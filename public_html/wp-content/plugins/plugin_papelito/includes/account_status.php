<?php
/**
 * Estado de conta (ativa/suspensa) e suas garantias de autorização.
 *
 * A suspensão é um estado de plataforma, acima da empresa e do vínculo: `company_status` diz
 * respeito à empresa, `member_status` ao vínculo daquela pessoa com aquela empresa, e este módulo
 * à conta em si. A palavra é a mesma nos três níveis — "suspensa" — e o nível fica explícito na
 * leitura de cada tela.
 *
 * Regra de ouro: quem decide é este módulo, sempre relendo o estado do banco. Nenhuma superfície
 * replica a condição; todas chamam `papelito_account_is_suspended()` ou um dos guards daqui. Isso
 * evita o `if ( $user->disabled )` espalhado por dezenas de rotas, que é como esse tipo de regra
 * apodrece.
 *
 * Suspensão NÃO bloqueia login: a pessoa entra e lê, na área autenticada, que a conta está
 * bloqueada para operações comerciais.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Falha na transação de sucessão de titularidade.
 *
 * Existe para o `catch` distinguir o que este módulo aborta de propósito de qualquer outro erro
 * que apareça dentro da transação — capturar `RuntimeException` genérica engoliria os dois.
 */
class Papelito_Account_Succession_Failure extends RuntimeException {}

const PAPELITO_ACCOUNT_STATUS_META         = 'papelito_account_status';
const PAPELITO_ACCOUNT_SUSPENSION_REASON   = 'papelito_account_suspension_reason';
const PAPELITO_ACCOUNT_SUSPENSION_AT       = 'papelito_account_suspension_at';
const PAPELITO_ACCOUNT_SUSPENSION_ACTOR    = 'papelito_account_suspension_actor';
const PAPELITO_ACCOUNT_STATUS_LOG_TABLE    = 'papelito_account_status_log';
const PAPELITO_ACCOUNT_REASON_MIN_LENGTH   = 5;
const PAPELITO_ACCOUNT_REASON_MAX_LENGTH   = 500;

/**
 * Nome completo (com prefixo) da tabela de histórico de estado de conta.
 */
function papelito_account_status_log_table(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_ACCOUNT_STATUS_LOG_TABLE;
}

/**
 * Cria a tabela de histórico via dbDelta. Idempotente.
 */
function papelito_account_status_install_tables(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = papelito_account_status_log_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(24) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  reason TEXT NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_created (user_id, created_at)
) {$charset_collate};"
	);
}

/**
 * Estado atual da conta: `active` ou `suspended`.
 */
function papelito_account_status( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return 'active';
	}

	return 'suspended' === sanitize_key( (string) get_user_meta( $user_id, PAPELITO_ACCOUNT_STATUS_META, true ) )
		? 'suspended'
		: 'active';
}

/**
 * A conta está suspensa?
 */
function papelito_account_is_suspended( int $user_id ): bool {
	return 'suspended' === papelito_account_status( $user_id );
}

/**
 * Rótulo em pt-BR do estado da conta.
 */
function papelito_account_status_label( string $status ): string {
	return 'suspended' === $status ? 'Suspensa' : 'Ativa';
}

/**
 * Dados da suspensão vigente, ou null quando a conta está ativa.
 *
 * @return array{reason:string,at:string,actorUserId:int,actorName:string}|null
 */
function papelito_account_suspension_details( int $user_id ): ?array {
	if ( ! papelito_account_is_suspended( $user_id ) ) {
		return null;
	}

	$actor_id   = (int) get_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_ACTOR, true );
	$actor_user = $actor_id > 0 ? get_userdata( $actor_id ) : null;

	return array(
		'reason'      => (string) get_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_REASON, true ),
		'at'          => (string) get_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_AT, true ),
		'actorUserId' => $actor_id,
		'actorName'   => $actor_user instanceof WP_User ? (string) $actor_user->display_name : '',
	);
}

/**
 * Histórico de suspensões e reativações, mais recente primeiro.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_account_status_history( int $user_id, int $limit = 50 ): array {
	global $wpdb;

	if ( $user_id <= 0 ) {
		return array();
	}

	$table = papelito_account_status_log_table();
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT action, actor_user_id, reason, created_at FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", $user_id, max( 1, min( 200, $limit ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	return array_map(
		static function ( array $row ): array {
			$actor_id   = (int) $row['actor_user_id'];
			$actor_user = $actor_id > 0 ? get_userdata( $actor_id ) : null;

			return array(
				'action'     => (string) $row['action'],
				'reason'     => (string) $row['reason'],
				'createdAt'  => (string) $row['created_at'],
				'actorUserId' => $actor_id,
				'actorName'  => $actor_user instanceof WP_User ? (string) $actor_user->display_name : '',
			);
		},
		$rows
	);
}

/**
 * Registra um evento de estado de conta.
 */
function papelito_account_status_log_event( int $user_id, string $action, int $actor_user_id, string $reason ): bool {
	global $wpdb;

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_account_status_log_table(),
		array(
			'user_id'       => $user_id,
			'action'        => sanitize_key( $action ),
			'actor_user_id' => $actor_user_id > 0 ? $actor_user_id : null,
			'reason'        => '' === $reason ? null : $reason,
			'created_at'    => current_time( 'mysql', true ),
		)
	);

	return false !== $inserted;
}

/**
 * Normaliza e valida a justificativa exigida na suspensão.
 *
 * @return string|WP_Error
 */
function papelito_account_normalize_reason( string $raw, bool $required ) {
	$reason = trim( sanitize_textarea_field( $raw ) );

	if ( '' === $reason ) {
		if ( $required ) {
			return new WP_Error( 'papelito_account_reason_required', 'Informe a justificativa da suspensão.', array( 'status' => 422 ) );
		}

		return '';
	}

	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $reason, 'UTF-8' ) : strlen( $reason );

	if ( $required && $length < PAPELITO_ACCOUNT_REASON_MIN_LENGTH ) {
		return new WP_Error( 'papelito_account_reason_too_short', 'A justificativa precisa descrever o motivo da suspensão.', array( 'status' => 422 ) );
	}

	if ( $length > PAPELITO_ACCOUNT_REASON_MAX_LENGTH ) {
		return new WP_Error( 'papelito_account_reason_too_long', 'A justificativa excede o limite de 500 caracteres.', array( 'status' => 422 ) );
	}

	return $reason;
}

/**
 * Empresas em que o usuário é o único owner ativo.
 *
 * Suspender essa conta deixaria a empresa sem quem administre vínculos, e é por isso que a ação
 * é recusada: a saída é transferir o ownership ou suspender a empresa inteira.
 *
 * @return array<int,array{companyId:int,legalName:string}>
 */
function papelito_account_sole_owner_companies( int $user_id ): array {
	if ( ! function_exists( 'papelito_company_members_active_for_user' ) || ! function_exists( 'papelito_company_count_active_owners' ) ) {
		return array();
	}

	$blocking = array();

	foreach ( papelito_company_members_active_for_user( $user_id ) as $member ) {
		$company = papelito_account_membership_blocking_company( $member );

		if ( null !== $company ) {
			$blocking[] = $company;
		}
	}

	return $blocking;
}

/**
 * Devolve a empresa que este vínculo protege, ou null quando ele não impede a suspensão.
 *
 * @param array<string,mixed> $member
 * @return array{companyId:int,legalName:string}|null
 */
function papelito_account_membership_blocking_company( array $member ): ?array {
	if ( 'owner' !== (string) ( $member['member_role'] ?? '' ) ) {
		return null;
	}

	$company_id = (int) ( $member['company_id'] ?? 0 );

	if ( $company_id <= 0 || ! function_exists( 'papelito_company_get' ) ) {
		return null;
	}

	$company = papelito_company_get( $company_id );

	if ( ! is_array( $company ) ) {
		return null;
	}

	if ( in_array( (string) $company['company_status'], array( 'suspended', 'archived' ), true ) ) {
		return null;
	}

	if ( papelito_company_count_active_owners( $company_id ) > 1 ) {
		return null;
	}

	return array(
		'companyId' => $company_id,
		'legalName' => (string) ( $company['trade_name'] ?: $company['legal_name'] ),
	);
}

/**
 * Sucessor natural da titularidade: o membro ativo mais antigo da empresa.
 *
 * "Mais antigo" é a data de entrada na empresa, não a data de cadastro na plataforma — quem está
 * há mais tempo na empresa é quem tem mais contexto para assumir. Contas suspensas e o próprio
 * titular que está saindo ficam de fora.
 *
 * `$for_update` trava as linhas candidatas: dentro da transação de suspensão a escolha precisa
 * valer até o COMMIT, senão o vínculo poderia ser revogado entre a leitura e a promoção.
 *
 * @return array<string,mixed>|null
 */
function papelito_account_ownership_successor( int $company_id, int $leaving_user_id, bool $for_update = false ): ?array {
	global $wpdb;

	$tables     = papelito_company_table_names();
	$lock       = $for_update ? ' FOR UPDATE' : '';
	$candidates = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT user_id, member_role, created_at
			FROM {$tables['members']}
			WHERE company_id = %d AND user_id <> %d AND member_status = 'active'
			ORDER BY created_at ASC, user_id ASC" . $lock, // phpcs:ignore WordPress.DB.PreparedSQL
			$company_id,
			$leaving_user_id
		),
		ARRAY_A
	);

	foreach ( is_array( $candidates ) ? $candidates : array() as $candidate ) {
		if ( ! papelito_account_is_suspended( (int) $candidate['user_id'] ) ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Passa a titularidade de uma empresa para o sucessor, **dentro** de uma transação já aberta.
 *
 * Não abre nem fecha transação de propósito: quem chama é `papelito_account_commit_suspension()`,
 * que precisa de atomicidade entre todas as empresas e a mudança de estado da conta. Uma
 * transferência que desse certo enquanto outra falha deixaria empresa sem titular e conta ativa.
 *
 * @return array<string,mixed> Dados da transferência.
 * @throws Papelito_Account_Succession_Failure Quando falta sucessor ou alguma escrita falha.
 */
function papelito_account_transfer_ownership_locked( int $company_id, int $leaving_user_id, int $actor_user_id ): array {
	global $wpdb;

	$tables = papelito_company_table_names();
	$now    = current_time( 'mysql', true );

	$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['companies']} WHERE id = %d FOR UPDATE", $company_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( ! is_array( $locked ) ) {
		throw new Papelito_Account_Succession_Failure( 'company missing' );
	}

	// O sucessor é resolvido depois do lock: entre o pré-voo e aqui o quadro de membros pode ter
	// mudado, e promover alguém que acabou de sair seria pior do que recusar a suspensão.
	$successor = papelito_account_ownership_successor( $company_id, $leaving_user_id, true );

	if ( null === $successor ) {
		throw new Papelito_Account_Succession_Failure( 'no successor' );
	}

	$successor_id = (int) $successor['user_id'];

	$demoted = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['members'],
		array(
			'member_role'             => 'admin',
			'role_changed_at'         => $now,
			'role_changed_by_user_id' => $actor_user_id,
			'updated_at'              => $now,
		),
		array(
			'company_id'  => $company_id,
			'user_id'     => $leaving_user_id,
			'member_role' => 'owner',
		)
	);

	$promoted = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['members'],
		array(
			'member_role'             => 'owner',
			'member_status'           => 'active',
			'identity_requirement'    => 'required',
			'role_changed_at'         => $now,
			'role_changed_by_user_id' => $actor_user_id,
			'updated_at'              => $now,
		),
		array(
			'company_id' => $company_id,
			'user_id'    => $successor_id,
		)
	);

	$company_updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['companies'],
		array(
			'owner_user_id' => $successor_id,
			'updated_at'    => $now,
		),
		array( 'id' => $company_id )
	);

	if ( false === $demoted || false === $promoted || false === $company_updated ) {
		throw new Papelito_Account_Succession_Failure( 'succession write failed' );
	}

	$audited = papelito_company_audit_log(
		$company_id,
		'ownership_transferred_on_suspension',
		$actor_user_id,
		array(
			'from_user_id'  => $leaving_user_id,
			'to_user_id'    => $successor_id,
			'previous_role' => (string) $successor['member_role'],
		)
	);

	if ( is_wp_error( $audited ) ) {
		throw new Papelito_Account_Succession_Failure( 'audit write failed' );
	}

	$successor_user = get_userdata( $successor_id );
	$company_name   = (string) ( $locked['trade_name'] ?: $locked['legal_name'] );

	return array(
		'companyId'     => $company_id,
		'companyName'   => $company_name,
		'successorId'   => $successor_id,
		'successorName' => $successor_user instanceof WP_User ? (string) $successor_user->display_name : '',
	);
}

/**
 * Persiste a suspensão inteira em uma única transação.
 *
 * Sucessão de titularidade de **todas** as empresas afetadas, gravação do estado da conta e
 * registro no histórico acontecem juntos ou não acontecem. Em falha, o rollback é acompanhado da
 * limpeza do cache de usuário: o WordPress atualiza o cache de meta na escrita, e sem isso o
 * processo seguiria lendo "suspensa" de um banco que voltou atrás.
 *
 * @return array<int,array<string,mixed>>|WP_Error Transferências realizadas.
 */
function papelito_account_commit_suspension( int $user_id, int $actor_user_id, string $reason ) {
	global $wpdb;

	$companies = papelito_account_sole_owner_companies( $user_id );
	$now       = current_time( 'mysql', true );

	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	try {
		$transfers = array();

		foreach ( $companies as $company ) {
			$transfers[] = papelito_account_transfer_ownership_locked( (int) $company['companyId'], $user_id, $actor_user_id );
		}

		if ( false === update_user_meta( $user_id, PAPELITO_ACCOUNT_STATUS_META, 'suspended' ) ) {
			throw new Papelito_Account_Succession_Failure( 'status write failed' );
		}

		update_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_REASON, $reason );
		update_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_AT, $now );
		update_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_ACTOR, $actor_user_id );

		if ( ! papelito_account_status_log_event( $user_id, 'suspend', $actor_user_id, $reason ) ) {
			throw new Papelito_Account_Succession_Failure( 'history write failed' );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $transfers;
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_account_forget_user_cache( $user_id );

		if ( 'no successor' === $error->getMessage() ) {
			return new WP_Error(
				'papelito_account_no_ownership_successor',
				'Esta pessoa deixou de ter um sucessor ativo para a titularidade. Suspenda a empresa antes de suspender a conta.',
				array( 'status' => 422 )
			);
		}

		return new WP_Error( 'papelito_account_suspend_failed', 'Não foi possível suspender a conta.', array( 'status' => 409 ) );
	}
}

/**
 * Descarta o cache de usuário depois de um rollback.
 */
function papelito_account_forget_user_cache( int $user_id ): void {
	if ( function_exists( 'clean_user_cache' ) ) {
		clean_user_cache( $user_id );
	}

	if ( function_exists( 'wp_cache_delete' ) ) {
		wp_cache_delete( $user_id, 'user_meta' );
	}
}

/**
 * Verifica se a suspensão desta conta é permitida.
 *
 * O único titular ativo **não** trava mais a ação: a titularidade passa automaticamente para o
 * membro ativo mais antigo quando a suspensão acontece. Só resta bloqueio quando não há ninguém
 * para assumir.
 *
 * @return true|WP_Error
 */
function papelito_account_can_suspend( int $user_id, int $actor_user_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_account_user_not_found', 'Usuário não encontrado.', array( 'status' => 404 ) );
	}

	if ( $user_id === $actor_user_id ) {
		return new WP_Error( 'papelito_account_cannot_suspend_self', 'Você não pode suspender a própria conta.', array( 'status' => 409 ) );
	}

	if ( user_can( $user, 'manage_options' ) ) {
		return new WP_Error( 'papelito_account_cannot_suspend_admin', 'Rebaixe o administrador antes de suspender a conta.', array( 'status' => 409 ) );
	}

	foreach ( papelito_account_sole_owner_companies( $user_id ) as $company ) {
		if ( null === papelito_account_ownership_successor( (int) $company['companyId'], $user_id ) ) {
			return new WP_Error(
				'papelito_account_no_ownership_successor',
				sprintf( 'Esta pessoa é o único membro ativo de %s. Suspenda a empresa antes de suspender a conta.', (string) $company['legalName'] ),
				array( 'status' => 422 )
			);
		}
	}

	return true;
}

/**
 * Suspende a conta.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_account_suspend( int $user_id, int $actor_user_id, string $raw_reason ) {
	$allowed = papelito_account_can_suspend( $user_id, $actor_user_id );

	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$reason = papelito_account_normalize_reason( $raw_reason, true );

	if ( is_wp_error( $reason ) ) {
		return $reason;
	}

	if ( papelito_account_is_suspended( $user_id ) ) {
		return array(
			'userId'             => $user_id,
			'status'             => 'suspended',
			'replayed'           => true,
			'suspension'         => papelito_account_suspension_details( $user_id ),
			'ownershipTransfers' => array(),
		);
	}

	$transfers = papelito_account_commit_suspension( $user_id, $actor_user_id, $reason );

	if ( is_wp_error( $transfers ) ) {
		return $transfers;
	}

	// Os hooks correm depois do COMMIT: e-mail e integração não têm rollback.
	foreach ( $transfers as $transfer ) {
		do_action( 'papelito_company_ownership_succeeded', (int) $transfer['companyId'], $user_id, (int) $transfer['successorId'], $actor_user_id );
	}

	do_action( 'papelito_account_suspended', $user_id, $actor_user_id, $reason );

	return array(
		'userId'             => $user_id,
		'status'             => 'suspended',
		'replayed'           => false,
		'suspension'         => papelito_account_suspension_details( $user_id ),
		'ownershipTransfers' => $transfers,
	);
}

/**
 * Reativa a conta.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_account_reactivate( int $user_id, int $actor_user_id, string $raw_reason = '' ) {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_account_user_not_found', 'Usuário não encontrado.', array( 'status' => 404 ) );
	}

	$reason = papelito_account_normalize_reason( $raw_reason, false );

	if ( is_wp_error( $reason ) ) {
		return $reason;
	}

	if ( ! papelito_account_is_suspended( $user_id ) ) {
		return array(
			'userId'   => $user_id,
			'status'   => 'active',
			'replayed' => true,
		);
	}

	update_user_meta( $user_id, PAPELITO_ACCOUNT_STATUS_META, 'active' );
	delete_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_REASON );
	delete_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_AT );
	delete_user_meta( $user_id, PAPELITO_ACCOUNT_SUSPENSION_ACTOR );

	papelito_account_status_log_event( $user_id, 'reactivate', $actor_user_id, $reason );

	do_action( 'papelito_account_reactivated', $user_id, $actor_user_id, $reason );

	return array(
		'userId'   => $user_id,
		'status'   => 'active',
		'replayed' => false,
	);
}

/**
 * Guard para operações comerciais do próprio usuário autenticado.
 *
 * Usado pelas rotas de escrita do vendor (estoque, cobertura). Despacho, rastreio e mensagens de
 * pedidos já vendidos continuam liberados: suspender um vendor não pode abandonar pedido pago.
 *
 * @return true|WP_Error
 */
function papelito_account_guard_commercial( int $user_id ) {
	if ( ! papelito_account_is_suspended( $user_id ) ) {
		return true;
	}

	return new WP_Error(
		'papelito_account_suspended',
		'Sua conta está suspensa para operações comerciais. Fale com a Papelito.',
		array( 'status' => 403 )
	);
}

/**
 * Contexto de conta devolvido ao frontend em `/auth/me`.
 *
 * @return array<string,mixed>
 */
function papelito_account_status_context( int $user_id ): array {
	$status = papelito_account_status( $user_id );

	return array(
		'accountStatus'      => $status,
		'accountStatusLabel' => papelito_account_status_label( $status ),
		'accountSuspension'  => papelito_account_suspension_details( $user_id ),
	);
}
