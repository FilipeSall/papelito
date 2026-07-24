<?php
/**
 * Camada de acesso às tabelas do modelo B2B (Fase 0).
 *
 * Cobre companies, company_members, company_invitations e company_audit_log. O perfil de
 * customer (com CPF protegido) tem sua camada em customer_identity.php.
 *
 * Fase 0: pronto e testado, NÃO chamado por nenhum fluxo existente. Sempre usa $wpdb->prepare.
 * O CNPJ é sempre persistido/consultado na forma canônica (papelito_normalize_cnpj).
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_INVITATION_TTL_DAYS' ) ) {
	define( 'PAPELITO_INVITATION_TTL_DAYS', 7 );
}

/* --- Companies --- */

/**
 * Cria uma empresa a partir do CNPJ canônico.
 *
 * @param array<string,mixed> $data legal_name, billing_email, created_by_user_id, ...
 * @return int|WP_Error ID da empresa criada.
 */
function papelito_company_create( string $raw_cnpj, array $data ) {
	global $wpdb;

	$cnpj = papelito_normalize_cnpj( $raw_cnpj );
	if ( '' === $cnpj || ! papelito_validate_cnpj( $cnpj ) ) {
		return new WP_Error( 'papelito_company_invalid_cnpj', 'CNPJ inválido.' );
	}

	if ( empty( $data['created_by_user_id'] ) ) {
		return new WP_Error( 'papelito_company_missing_creator', 'created_by_user_id é obrigatório.' );
	}

	$tables = papelito_company_table_names();
	$now    = current_time( 'mysql', true );

	$row = array(
		'cnpj'               => $cnpj,
		'legal_name'         => isset( $data['legal_name'] ) ? sanitize_text_field( (string) $data['legal_name'] ) : '',
		'trade_name'         => isset( $data['trade_name'] ) ? sanitize_text_field( (string) $data['trade_name'] ) : null,
		'billing_email'      => isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
		'phone'              => isset( $data['phone'] ) ? sanitize_text_field( (string) $data['phone'] ) : null,
		'registry_status'    => isset( $data['registry_status'] ) ? (string) $data['registry_status'] : 'pending',
		'ownership_status'   => isset( $data['ownership_status'] ) ? (string) $data['ownership_status'] : 'pending',
		'company_status'     => isset( $data['company_status'] ) ? (string) $data['company_status'] : 'onboarding',
		'owner_user_id'      => isset( $data['owner_user_id'] ) ? (int) $data['owner_user_id'] : null,
		'created_by_user_id' => (int) $data['created_by_user_id'],
		'created_at'         => $now,
		'updated_at'         => $now,
	);

	$result = $wpdb->insert( $tables['companies'], $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $result ) {
		if ( ! empty( $wpdb->last_error ) && false !== stripos( $wpdb->last_error, 'duplicate' ) ) {
			return new WP_Error( 'papelito_company_cnpj_exists', 'Já existe uma empresa com este CNPJ.', array( 'status' => 409 ) );
		}
		return new WP_Error( 'papelito_company_persist_failed', 'Falha ao criar empresa.' );
	}

	return (int) $wpdb->insert_id;
}

/**
 * Busca uma empresa pelo ID.
 *
 * @return array<string,mixed>|null
 */
function papelito_company_get( int $company_id ): ?array {
	global $wpdb;

	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['companies']} WHERE id = %d", $company_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return null === $row ? null : $row;
}

/**
 * Busca uma empresa pelo CNPJ (canonicalizado antes da consulta).
 *
 * @return array<string,mixed>|null
 */
function papelito_company_find_by_cnpj( string $raw_cnpj ): ?array {
	global $wpdb;

	$cnpj = papelito_normalize_cnpj( $raw_cnpj );
	if ( '' === $cnpj ) {
		return null;
	}

	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['companies']} WHERE cnpj = %s", $cnpj ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return null === $row ? null : $row;
}

/**
 * Retorna o owner ativo canônico da empresa (owner_user_id), ou null.
 */
function papelito_company_get_owner_user_id( int $company_id ): ?int {
	$company = papelito_company_get( $company_id );

	if ( null === $company || empty( $company['owner_user_id'] ) ) {
		return null;
	}

	return (int) $company['owner_user_id'];
}

/**
 * Atualiza campos de uma empresa.
 *
 * @param array<string,mixed> $fields
 * @return true|WP_Error
 */
function papelito_company_update( int $company_id, array $fields ) {
	global $wpdb;

	if ( empty( $fields ) ) {
		return true;
	}

	$fields['updated_at'] = current_time( 'mysql', true );

	$tables = papelito_company_table_names();
	$result = $wpdb->update( $tables['companies'], $fields, array( 'id' => $company_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return false === $result
		? new WP_Error( 'papelito_company_update_failed', 'Falha ao atualizar empresa.' )
		: true;
}

/* --- Members (N:N usuário ↔ empresa) --- */

/**
 * Cria/atualiza um vínculo (member) entre usuário e empresa.
 *
 * Unicidade é no par (company_id, user_id).
 *
 * @param array<string,mixed> $data member_role, member_status, invited_by_user_id, ...
 * @return int|WP_Error ID do member.
 */
function papelito_company_member_upsert( int $company_id, int $user_id, array $data = array() ) {
	global $wpdb;

	$tables   = papelito_company_table_names();
	$now      = current_time( 'mysql', true );
	$existing = papelito_company_member_get( $company_id, $user_id );

	$row = array(
		'company_id'    => $company_id,
		'user_id'       => $user_id,
		'member_role'   => isset( $data['member_role'] ) ? (string) $data['member_role'] : 'buyer',
		'member_status' => isset( $data['member_status'] ) ? (string) $data['member_status'] : 'pending_company_approval',
		'updated_at'    => $now,
	);

	$optional_fields = array(
		'membership_origin',
		'invited_by_user_id',
		'approved_by_user_id',
		'requested_at',
		'request_count',
		'last_request_at',
		'approved_at',
		'rejected_at',
		'rejected_reason',
		'rejected_by_user_id',
		'suspended_at',
		'suspended_by_user_id',
		'revoked_at',
		'revoked_by_user_id',
		'role_changed_at',
		'role_changed_by_user_id',
		'expires_at',
	);
	foreach ( $optional_fields as $optional ) {
		if ( array_key_exists( $optional, $data ) ) {
			$row[ $optional ] = $data[ $optional ];
		}
	}

	if ( null === $existing ) {
		$row['created_at'] = $now;
		$result            = $wpdb->insert( $tables['members'], $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'papelito_member_persist_failed', 'Falha ao criar vínculo.' );
		}
		return (int) $wpdb->insert_id;
	}

	$result = $wpdb->update( $tables['members'], $row, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $result ) {
		return new WP_Error( 'papelito_member_persist_failed', 'Falha ao atualizar vínculo.' );
	}

	return (int) $existing['id'];
}

/**
 * Busca o vínculo de um usuário em uma empresa.
 *
 * @return array<string,mixed>|null
 */
function papelito_company_member_get( int $company_id, int $user_id ): ?array {
	global $wpdb;

	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$tables['members']} WHERE company_id = %d AND user_id = %d", $company_id, $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);

	return null === $row ? null : $row;
}

function papelito_company_member_is_operationally_active( ?array $member ): bool {
	if ( null === $member || 'active' !== (string) $member['member_status'] ) {
		return false;
	}

	if ( empty( $member['expires_at'] ) ) {
		return true;
	}

	$expires_at = strtotime( (string) $member['expires_at'] );
	return false !== $expires_at && $expires_at > time();
}

/**
 * Lista os vínculos ativos de um usuário (para seleção da empresa ativa da sessão).
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_company_members_active_for_user( int $user_id ): array {
	global $wpdb;

	$tables = papelito_company_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$tables['members']} WHERE user_id = %d AND member_status = %s AND ( expires_at IS NULL OR expires_at > UTC_TIMESTAMP() )", $user_id, 'active' ), // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Busca um vínculo pelo id do member (para rotas /members/{id}).
 *
 * @return array<string,mixed>|null
 */
function papelito_company_member_get_by_id( int $member_id ): ?array {
	global $wpdb;

	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['members']} WHERE id = %d", $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return null === $row ? null : $row;
}

/**
 * Lista membros de uma empresa filtrando por conjunto de status.
 *
 * @param array<int,string> $statuses Lista de member_status a incluir (vazio = todos).
 * @return array<int,array<string,mixed>>
 */
function papelito_company_members_list( int $company_id, array $statuses = array() ): array {
	global $wpdb;

	$tables = papelito_company_table_names();

	if ( empty( $statuses ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['members']} WHERE company_id = %d ORDER BY created_at ASC", $company_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $rows ) ? $rows : array();
	}

	$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	$params       = array_merge( array( $company_id ), array_values( $statuses ) );
	$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['members']} WHERE company_id = %d AND member_status IN ( {$placeholders} ) ORDER BY created_at ASC", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return is_array( $rows ) ? $rows : array();
}

/**
 * Lista convites de uma empresa (opcionalmente por status).
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_company_invitations_list( int $company_id, ?string $status = null ): array {
	global $wpdb;

	$tables = papelito_company_table_names();

	if ( null === $status || '' === $status ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['invitations']} WHERE company_id = %d ORDER BY created_at DESC", $company_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	} else {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['invitations']} WHERE company_id = %d AND invitation_status = %s ORDER BY created_at DESC", $company_id, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	return is_array( $rows ) ? $rows : array();
}

/**
 * Busca um convite pelo id (para rotas /invitations/{id}/*).
 *
 * @return array<string,mixed>|null
 */
function papelito_company_invitation_get( int $invitation_id ): ?array {
	global $wpdb;

	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['invitations']} WHERE id = %d", $invitation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return null === $row ? null : $row;
}

/**
 * Conta os owners ativos de uma empresa (protege a invariante "ao menos um owner ativo").
 */
function papelito_company_count_active_owners( int $company_id ): int {
	global $wpdb;

	$tables = papelito_company_table_names();
	$count  = $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['members']} WHERE company_id = %d AND member_role = %s AND member_status = %s AND ( expires_at IS NULL OR expires_at > UTC_TIMESTAMP() )", $company_id, 'owner', 'active' ) // phpcs:ignore WordPress.DB.PreparedSQL
	);

	return (int) $count;
}

/* --- Invitations (token single-use, só hash persistido) --- */

/**
 * Calcula o hash de armazenamento de um token de convite (nunca persistir o token em claro).
 */
function papelito_company_invitation_hash_token( string $token ): string {
	return hash( 'sha256', $token );
}

/**
 * Cria um convite. Retorna o token em claro (só neste retorno; persiste apenas o hash).
 *
 * @param array<string,mixed> $data invited_role, invited_cpf_hmac, expires_at
 * @return array{id:int,token:string}|WP_Error
 */
function papelito_company_invitation_create( int $company_id, string $invited_email, int $invited_by_user_id, array $data = array() ) {
	global $wpdb;

	$email = sanitize_email( $invited_email );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'papelito_invitation_invalid_email', 'E-mail do convite inválido.' );
	}

	$token      = bin2hex( random_bytes( 32 ) );
	$token_hash = papelito_company_invitation_hash_token( $token );

	$ttl_days   = isset( $data['ttl_days'] ) ? max( 1, (int) $data['ttl_days'] ) : PAPELITO_INVITATION_TTL_DAYS;
	$expires_at = isset( $data['expires_at'] )
		? (string) $data['expires_at']
		: gmdate( 'Y-m-d H:i:s', time() + ( $ttl_days * DAY_IN_SECONDS ) );

	$tables = papelito_company_table_names();
	$row    = array(
		'company_id'         => $company_id,
		'invited_email'      => $email,
		'invited_cpf_hmac'   => isset( $data['invited_cpf_hmac'] ) ? (string) $data['invited_cpf_hmac'] : null,
		'invited_role'       => isset( $data['invited_role'] ) ? (string) $data['invited_role'] : 'buyer',
		'token_hash'         => $token_hash,
		'invitation_status'  => 'pending',
		'invited_by_user_id' => $invited_by_user_id,
		'expires_at'         => $expires_at,
		'created_at'         => current_time( 'mysql', true ),
	);

	$result = $wpdb->insert( $tables['invitations'], $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $result ) {
		return new WP_Error( 'papelito_invitation_persist_failed', 'Falha ao criar convite.' );
	}

	return array(
		'id'    => (int) $wpdb->insert_id,
		'token' => $token,
	);
}

/**
 * Busca um convite PENDENTE e não expirado pelo token em claro.
 *
 * Convite aceito, revogado ou expirado não é retornado (sem reuso).
 *
 * @return array<string,mixed>|null
 */
function papelito_company_invitation_find_pending_by_token( string $token ): ?array {
	global $wpdb;

	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$tables['invitations']} WHERE token_hash = %s", papelito_company_invitation_hash_token( $token ) ), // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);

	if ( null === $row ) {
		return null;
	}

	if ( 'pending' !== $row['invitation_status'] ) {
		return null;
	}

	if ( strtotime( (string) $row['expires_at'] ) < time() ) {
		return null;
	}

	return $row;
}

/**
 * Marca um convite como aceito (single-use).
 *
 * @return true|WP_Error
 */
function papelito_company_invitation_accept( int $invitation_id, int $accepted_by_user_id ) {
	global $wpdb;

	$tables = papelito_company_table_names();
	$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['invitations'],
		array(
			'invitation_status'   => 'accepted',
			'accepted_at'         => current_time( 'mysql', true ),
			'accepted_by_user_id' => $accepted_by_user_id,
		),
		array(
			'id'                => $invitation_id,
			'invitation_status' => 'pending',
		)
	);

	if ( false === $result || 0 === $result ) {
		return new WP_Error( 'papelito_invitation_not_acceptable', 'Convite não pode ser aceito (inexistente, já usado ou expirado).', array( 'status' => 409 ) );
	}

	return true;
}

/**
 * Revoga um convite pendente.
 *
 * @return true|WP_Error
 */
function papelito_company_invitation_revoke( int $invitation_id, int $revoked_by_user_id, string $reason = '' ) {
	global $wpdb;

	$tables = papelito_company_table_names();
	$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['invitations'],
		array(
			'invitation_status'  => 'revoked',
			'revoked_at'         => current_time( 'mysql', true ),
			'revoked_by_user_id' => $revoked_by_user_id,
			'revoked_reason'     => '' === $reason ? null : sanitize_text_field( $reason ),
		),
		array(
			'id'                => $invitation_id,
			'invitation_status' => 'pending',
		)
	);

	if ( false === $result || 0 === $result ) {
		return new WP_Error( 'papelito_invitation_not_revocable', 'Convite não pode ser revogado.', array( 'status' => 409 ) );
	}

	return true;
}

/**
 * Reenvia um convite: gera um novo token, invalidando o anterior. Retorna o novo token.
 *
 * @return array{id:int,token:string}|WP_Error
 */
function papelito_company_invitation_resend( int $invitation_id ) {
	global $wpdb;

	$tables     = papelito_company_table_names();
	$token      = bin2hex( random_bytes( 32 ) );
	$token_hash = papelito_company_invitation_hash_token( $token );
	$ttl        = time() + ( PAPELITO_INVITATION_TTL_DAYS * DAY_IN_SECONDS );

	// O nome da tabela vem de papelito_company_table_names() (interno, não input do usuário); valores via prepare().
	$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$tables['invitations']} SET token_hash = %s, expires_at = %s, resent_at = %s, resend_count = resend_count + 1 WHERE id = %d AND invitation_status = %s",
			$token_hash,
			gmdate( 'Y-m-d H:i:s', $ttl ),
			current_time( 'mysql', true ),
			$invitation_id,
			'pending'
		)
	);

	if ( false === $result || 0 === $result ) {
		return new WP_Error( 'papelito_invitation_not_resendable', 'Convite não pode ser reenviado.', array( 'status' => 409 ) );
	}

	return array(
		'id'    => $invitation_id,
		'token' => $token,
	);
}

/**
 * Expira em lote os convites pendentes vencidos. Retorna o número de convites expirados.
 */
function papelito_company_invitations_expire_due(): int {
	global $wpdb;

	$tables = papelito_company_table_names();
	// O nome da tabela vem de papelito_company_table_names() (interno, não input do usuário); valores via prepare().
	$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$tables['invitations']} SET invitation_status = %s WHERE invitation_status = %s AND expires_at < %s",
			'expired',
			'pending',
			current_time( 'mysql', true )
		)
	);

	return (int) $result;
}

/* --- Audit log (sem PII) --- */

/**
 * Registra uma ação sensível na trilha de auditoria da empresa.
 *
 * @param array<string,mixed> $payload Dados NÃO sensíveis (sem CPF, DOB, QSA, tokens, PII).
 * @return int|WP_Error
 */
function papelito_company_audit_log( int $company_id, string $action, ?int $actor_user_id = null, array $payload = array() ) {
	global $wpdb;

	$tables = papelito_company_table_names();
	$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['audit'],
		array(
			'company_id'    => $company_id,
			'actor_user_id' => $actor_user_id,
			'action'        => sanitize_key( $action ),
			'payload_json'  => empty( $payload ) ? null : wp_json_encode( $payload ),
			'created_at'    => current_time( 'mysql', true ),
		)
	);

	return false === $result
		? new WP_Error( 'papelito_audit_persist_failed', 'Falha ao registrar auditoria.' )
		: (int) $wpdb->insert_id;
}
