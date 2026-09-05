<?php
/**
 * Convites de empresa (Fase 1B).
 *
 * Owner/admin convida por e-mail, papel e expiração de 7 dias. Só o hash
 * do token é persistido; o token em claro só sai no e-mail e no retorno da criação/reenvio.
 *
 * Aceite (transacional) exige:
 *   - usuário autenticado;
 *   - convite pendente e não expirado;
 *   - e-mail do usuário compatível com invited_email;
 *   - e-mail da conta já confirmado;
 *   - vínculo ainda não existente para (empresa, usuário);
 *   - invalidação single-use do token no mesmo passo.
 *
 * Convite NUNCA concede owner (apenas admin/buyer/viewer). Aceitar marca o coorte B2B.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recusa convite para um e-mail que já tem vínculo vivo com a empresa.
 *
 * Convidar quem já está dentro não faz nada de útil: o aceite é idempotente e não muda papel, mas
 * dispara e-mail, ocupa a lista de convites e faz o admin acreditar que promoveu ou reconvidou
 * alguém. Vínculo encerrado (revogado/rejeitado) NÃO bloqueia — readmitir por convite é o caminho
 * previsto.
 *
 * @return null|WP_Error Erro quando o e-mail já pertence a um membro da empresa.
 */
function papelito_company_invitation_blocking_membership( int $company_id, string $email ): ?WP_Error {
	$user_id = (int) email_exists( $email );
	if ( $user_id <= 0 ) {
		return null;
	}

	$member = papelito_company_member_get( $company_id, $user_id );
	if ( null === $member ) {
		return null;
	}

	$messages = array(
		'active'                   => 'Este e-mail já faz parte da empresa. Ajuste o papel pela lista de membros.',
		'suspended'                => 'Este e-mail pertence a um membro suspenso. Reative o acesso pela lista de membros.',
		'pending_company_approval' => 'Este e-mail já solicitou acesso à empresa. Aprove a solicitação pendente.',
		'pending_identity'         => 'Este e-mail já solicitou acesso à empresa. Aprove a solicitação pendente.',
	);

	$status = (string) $member['member_status'];
	if ( ! isset( $messages[ $status ] ) ) {
		return null;
	}

	return new WP_Error( 'papelito_b2b_invitation_already_member', $messages[ $status ], array( 'status' => 409 ) );
}

/**
 * Cria um convite (owner/admin). Retorna dados públicos + token em claro (uma única vez).
 *
 * @param array<string,mixed> $input invited_email, invited_role, ttl_days?
 * @return array{id:int,token:string,invited_email:string,invited_role:string}|WP_Error
 */
function papelito_company_invitation_issue( int $actor_user_id, int $company_id, array $input ) {
	$loaded = papelito_company_authz_load( $actor_user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
	}

	$email = strtolower( sanitize_email( trim( (string) ( $input['invited_email'] ?? '' ) ) ) );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'papelito_b2b_invitation_invalid_email', 'E-mail do convite inválido.', array( 'status' => 422 ) );
	}

	$role = (string) ( $input['invited_role'] ?? 'buyer' );
	if ( ! in_array( $role, papelito_company_assignable_roles(), true ) ) {
		return new WP_Error( 'papelito_b2b_invitation_invalid_role', 'Convite só concede admin, buyer ou viewer.', array( 'status' => 422 ) );
	}

	$blocked = papelito_company_invitation_blocking_membership( $company_id, $email );
	if ( null !== $blocked ) {
		return $blocked;
	}

	$data = array( 'invited_role' => $role );

	if ( isset( $input['ttl_days'] ) && (int) $input['ttl_days'] > 0 ) {
		$data['ttl_days'] = (int) $input['ttl_days'];
	}

	$existing = papelito_company_invitation_find_pending_by_email( $company_id, $email );
	if ( null !== $existing ) {
		$reissued = papelito_company_invitation_resend( (int) $existing['id'] );
		if ( is_wp_error( $reissued ) ) {
			return $reissued;
		}
		papelito_company_audit( $company_id, $actor_user_id, 'member_invitation_resent', array( 'invitation_id' => (int) $existing['id'] ) );
		papelito_company_invitation_send_email( $company_id, $email, $reissued['token'] );
		return array( 'id' => (int) $existing['id'], 'token' => $reissued['token'], 'invited_email' => $email, 'invited_role' => (string) $existing['invited_role'] );
	}

	$created = papelito_company_invitation_create( $company_id, $email, $actor_user_id, $data );
	if ( is_wp_error( $created ) ) {
		return $created;
	}

	papelito_company_audit(
		$company_id,
		$actor_user_id,
		'member_invited',
		array(
			'invitation_id' => $created['id'],
			'invited_role'  => $role,
		)
	);
	papelito_company_invitation_send_email( $company_id, $email, $created['token'] );

	return array(
		'id'            => $created['id'],
		'token'         => $created['token'],
		'invited_email' => $email,
		'invited_role'  => $role,
	);
}

/**
 * Reenvia um convite (invalida o token anterior). Owner/admin.
 *
 * @return array{id:int,token:string}|WP_Error
 */
function papelito_company_invitation_reissue( int $actor_user_id, int $company_id, int $invitation_id ) {
	$check = papelito_company_invitation_authorize_target( $actor_user_id, $company_id, $invitation_id );
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$resent = papelito_company_invitation_resend( $invitation_id );
	if ( is_wp_error( $resent ) ) {
		return $resent;
	}

	papelito_company_audit( $company_id, $actor_user_id, 'member_invitation_resent', array( 'invitation_id' => $invitation_id ) );
	papelito_company_invitation_send_email( $company_id, (string) $check['invited_email'], $resent['token'] );

	return $resent;
}

/**
 * Revoga um convite pendente. Owner/admin.
 *
 * @return true|WP_Error
 */
function papelito_company_invitation_cancel( int $actor_user_id, int $company_id, int $invitation_id, string $reason = '' ) {
	$check = papelito_company_invitation_authorize_target( $actor_user_id, $company_id, $invitation_id );
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$revoked = papelito_company_invitation_revoke( $invitation_id, $actor_user_id, $reason );
	if ( is_wp_error( $revoked ) ) {
		return $revoked;
	}

	papelito_company_audit( $company_id, $actor_user_id, 'member_invitation_revoked', array( 'invitation_id' => $invitation_id ) );

	return true;
}

/**
 * Confere que o convite pertence à empresa e que o actor pode gerenciá-lo.
 *
 * @return array<string,mixed>|WP_Error Linha do convite.
 */
function papelito_company_invitation_authorize_target( int $actor_user_id, int $company_id, int $invitation_id ) {
	$loaded = papelito_company_authz_load( $actor_user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
	}

	$invitation = papelito_company_invitation_get( $invitation_id );
	if ( null === $invitation || (int) $invitation['company_id'] !== $company_id ) {
		return new WP_Error( 'papelito_b2b_invitation_not_found', 'Convite não encontrado.', array( 'status' => 404 ) );
	}

	return $invitation;
}

/**
 * Valida um token de convite SEM revelar dados sensíveis (para a landing pós-clique).
 *
 * Retorna apenas o estritamente necessário para orientar o usuário; nunca CNPJ completo,
 * membros ou dados fiscais.
 *
 * `accountExists`/`authMethods` existem para a landing rotear o convidado de forma
 * determinística (criar conta x entrar) em vez de oferecer uma bifurcação cega. Não é
 * enumeração de e-mail: só o portador do token chega aqui, e `invitedEmail` já é devolvido.
 *
 * `companyCnpj` sai da relação convite → empresa, canônico, e é o ÚNICO CNPJ que o convidado vê
 * ou envia no fluxo: nenhuma tela do aceite ou do cadastro por convite aceita CNPJ digitado.
 *
 * @return array{invitationId:int,companyName:string,companyCnpj:string,invitedRole:string,invitedEmail:string,accountExists:bool,authMethods:array<int,string>}|WP_Error
 */
function papelito_company_invitation_preview( string $token ) {
	$invitation = papelito_company_invitation_find_pending_by_token( $token );
	if ( null === $invitation ) {
		return new WP_Error( 'papelito_b2b_invitation_invalid', 'Convite inválido ou expirado.', array( 'status' => 404 ) );
	}

	$company = papelito_company_get( (int) $invitation['company_id'] );
	$name    = $company ? (string) ( ! empty( $company['trade_name'] ) ? $company['trade_name'] : $company['legal_name'] ) : '';

	$email   = (string) $invitation['invited_email'];
	$user_id = (int) email_exists( $email );

	return array(
		'invitationId'  => (int) $invitation['id'],
		'companyName'   => $name,
		'companyCnpj'   => $company ? (string) $company['cnpj'] : '',
		'invitedRole'   => (string) $invitation['invited_role'],
		'invitedEmail'  => $email,
		'accountExists' => $user_id > 0,
		'authMethods'   => $user_id > 0 && function_exists( 'papelito_auth_credential_methods' )
			? papelito_auth_credential_methods( $user_id )
			: array(),
	);
}

/**
 * Valida a identidade e o e-mail do usuário que está aceitando o convite.
 *
 * @param array<string,mixed> $invitation Linha do convite.
 * @return null|WP_Error
 */
function papelito_company_invitation_validate_acceptance( int $user_id, WP_User $user, array $invitation ) {
	if ( strtolower( (string) $invitation['invited_email'] ) !== strtolower( (string) $user->user_email ) ) {
		return new WP_Error( 'papelito_b2b_invitation_email_mismatch', 'Este convite foi enviado para outro e-mail.', array( 'status' => 403 ) );
	}

	if ( function_exists( 'papelito_auth_requires_email_verification' ) && papelito_auth_requires_email_verification( $user_id ) ) {
		return new WP_Error( 'papelito_b2b_invitation_email_unverified', 'Confirme o e-mail destinatário antes de aceitar o convite.', array( 'status' => 422 ) );
	}

	$profile = papelito_company_profile_get( $user_id );
	if ( null === $profile || 'verified' !== (string) ( $profile['identity_status'] ?? '' ) ) {
		return new WP_Error( 'papelito_b2b_invitation_identity_required', 'Informe seu CPF antes de aceitar o convite.', array( 'status' => 422 ) );
	}

	return null;
}

/**
 * Resolve an existing membership before creating a new one.
 *
 * @param array<string,mixed>    $invitation Linha do convite.
 * @param array<string,mixed>|null $existing Membership existente.
 * @return array<string,mixed>|WP_Error|null
 */
function papelito_company_invitation_handle_existing_membership( int $user_id, int $company_id, array $invitation, ?array $existing ) {
	if ( null === $existing ) {
		return null;
	}

	if ( 'active' === (string) $existing['member_status'] ) {
		$accepted = papelito_company_invitation_accept( (int) $invitation['id'], $user_id );
		if ( is_wp_error( $accepted ) ) {
			return $accepted;
		}
		papelito_company_audit( $company_id, $user_id, 'invitation_accepted_existing_member', array( 'invitation_id' => (int) $invitation['id'] ) );
		return papelito_company_context( $user_id );
	}

	if ( 'suspended' === (string) $existing['member_status'] ) {
		return new WP_Error( 'papelito_b2b_membership_suspended', 'Seu acesso a esta empresa está suspenso.', array( 'status' => 409 ) );
	}

	return null;
}

/**
 * Atomically consume the invitation and create the active membership.
 *
 * @param array<string,mixed> $invitation Linha do convite.
 * @return true|WP_Error
 */
function papelito_company_invitation_persist_acceptance( int $user_id, int $company_id, array $invitation ) {
	global $wpdb;

	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$accepted = papelito_company_invitation_accept( (int) $invitation['id'], $user_id );
		if ( is_wp_error( $accepted ) ) {
			throw new RuntimeException( 'not acceptable' );
		}

		$member = papelito_company_member_upsert(
			$company_id,
			$user_id,
			array(
				'member_role'          => (string) $invitation['invited_role'],
				'member_status'        => 'active',
				'membership_origin'    => 'invitation',
				'invited_by_user_id'   => (int) $invitation['invited_by_user_id'],
				'approved_by_user_id'  => (int) $invitation['invited_by_user_id'],
				'approved_at'          => current_time( 'mysql', true ),
				'identity_requirement' => 'required',
			)
		);
		if ( is_wp_error( $member ) ) {
			throw new RuntimeException( 'member upsert failed' );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_b2b_invitation_accept_failed', 'Não foi possível aceitar o convite.', array( 'status' => 409 ) );
	}

	return true;
}

/**
 * Aceita um convite pelo token (usuário autenticado). Transacional e single-use.
 *
 * @return array<string,mixed>|WP_Error Contexto B2B atualizado.
 */
function papelito_company_invitation_accept_token( int $user_id, string $token ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_b2b_user_not_found', 'Usuário inválido.', array( 'status' => 401 ) );
	}

	$invitation = papelito_company_invitation_find_pending_by_token( $token );
	if ( null === $invitation ) {
		return new WP_Error( 'papelito_b2b_invitation_invalid', 'Convite inválido ou expirado.', array( 'status' => 404 ) );
	}

	$validation = papelito_company_invitation_validate_acceptance( $user_id, $user, $invitation );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	$company_id = (int) $invitation['company_id'];

	$existing = papelito_company_member_get( $company_id, $user_id );
	$existing_result = papelito_company_invitation_handle_existing_membership( $user_id, $company_id, $invitation, $existing );
	if ( null !== $existing_result ) {
		return $existing_result;
	}

	$persisted = papelito_company_invitation_persist_acceptance( $user_id, $company_id, $invitation );
	if ( is_wp_error( $persisted ) ) {
		return $persisted;
	}

	papelito_b2b_mark_cohort( $user_id );
	if ( function_exists( 'papelito_legacy_complete_if_ready' ) ) {
		papelito_legacy_complete_if_ready( $user_id, 'invitation_accepted' );
	}
	papelito_company_audit(
		$company_id,
		$user_id,
		'invitation_accepted',
		array(
			'invitation_id' => (int) $invitation['id'],
			'role'          => (string) $invitation['invited_role'],
		)
	);

	return papelito_company_context( $user_id );
}

/** Recusa um convite autenticado para o e-mail destinatário, sem revelar nem alterar memberships. */
function papelito_company_invitation_decline_token( int $user_id, string $token ) {
	$user = get_userdata( $user_id );
	$invitation = papelito_company_invitation_find_pending_by_token( $token );
	if ( ! $user instanceof WP_User || null === $invitation || ! hash_equals( strtolower( (string) $invitation['invited_email'] ), strtolower( (string) $user->user_email ) ) ) {
		return new WP_Error( 'papelito_b2b_invitation_invalid', 'Convite inválido ou expirado.', array( 'status' => 404 ) );
	}
	$declined = papelito_company_invitation_decline( (int) $invitation['id'], $user_id );
	if ( is_wp_error( $declined ) ) {
		return $declined;
	}
	papelito_company_audit( (int) $invitation['company_id'], $user_id, 'invitation_declined', array( 'invitation_id' => (int) $invitation['id'] ) );
	return true;
}

/**
 * Envia o e-mail do convite com o link para a landing do frontend.
 *
 * Best-effort: falha de e-mail não derruba a criação do convite (o token já foi persistido).
 */
function papelito_company_invitation_send_email( int $company_id, string $email, string $token ): void {
	$link = papelito_frontend_link( 'convite/' . rawurlencode( $token ) );

	if ( is_wp_error( $link ) ) {
		error_log( 'papelito: convite nao enviado, URL do frontend nao configurada.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return;
	}

	$company = papelito_company_get( $company_id );
	$name    = $company ? (string) ( ! empty( $company['trade_name'] ) ? $company['trade_name'] : $company['legal_name'] ) : 'sua empresa';

	$subject = 'Convite para acessar uma empresa no Papelito';
	$body    = sprintf(
		"Você foi convidado(a) para acessar %s no Papelito.\n\nAcesse o link abaixo para aceitar o convite:\n%s\n\nO convite expira em %d dias.",
		$name,
		$link,
		PAPELITO_INVITATION_TTL_DAYS
	);

	wp_mail( $email, $subject, $body );
}
