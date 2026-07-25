<?php
/**
 * Convites de empresa (Fase 1B).
 *
 * Owner/admin convida por e-mail (com CPF HMAC opcional), papel e expiração de 7 dias. Só o hash
 * do token é persistido; o token em claro só sai no e-mail e no retorno da criação/reenvio.
 *
 * Aceite (transacional) exige:
 *   - usuário autenticado;
 *   - convite pendente e não expirado;
 *   - e-mail do usuário compatível com invited_email;
 *   - CPF compatível quando o convite estiver vinculado a CPF (invited_cpf_hmac);
 *   - vínculo ainda não existente para (empresa, usuário);
 *   - invalidação single-use do token no mesmo passo.
 *
 * Convite NUNCA concede owner (apenas admin/buyer/viewer). Aceitar marca o coorte B2B.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cria um convite (owner/admin). Retorna dados públicos + token em claro (uma única vez).
 *
 * @param array<string,mixed> $input invited_email, invited_role, invited_cpf?, ttl_days?
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

	$email = sanitize_email( (string) ( $input['invited_email'] ?? '' ) );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'papelito_b2b_invitation_invalid_email', 'E-mail do convite inválido.', array( 'status' => 422 ) );
	}

	$role = (string) ( $input['invited_role'] ?? 'buyer' );
	if ( ! in_array( $role, papelito_company_assignable_roles(), true ) ) {
		return new WP_Error( 'papelito_b2b_invitation_invalid_role', 'Convite só concede admin, buyer ou viewer.', array( 'status' => 422 ) );
	}

	$data = array( 'invited_role' => $role );

	if ( isset( $input['ttl_days'] ) && (int) $input['ttl_days'] > 0 ) {
		$data['ttl_days'] = (int) $input['ttl_days'];
	}

	if ( ! empty( $input['invited_cpf'] ) ) {
		if ( ! papelito_validate_cpf( (string) $input['invited_cpf'] ) ) {
			return new WP_Error( 'papelito_b2b_invitation_invalid_cpf', 'CPF do convite inválido.', array( 'status' => 422 ) );
		}
		$hmac = papelito_cpf_hmac( (string) $input['invited_cpf'] );
		if ( is_wp_error( $hmac ) ) {
			return $hmac;
		}
		$data['invited_cpf_hmac'] = $hmac;
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
			'cpf_locked'    => isset( $data['invited_cpf_hmac'] ),
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
 * @return array{invitationId:int,companyName:string,invitedRole:string,invitedEmail:string,cpfLocked:bool}|WP_Error
 */
function papelito_company_invitation_preview( string $token ) {
	$invitation = papelito_company_invitation_find_pending_by_token( $token );
	if ( null === $invitation ) {
		return new WP_Error( 'papelito_b2b_invitation_invalid', 'Convite inválido ou expirado.', array( 'status' => 404 ) );
	}

	$company = papelito_company_get( (int) $invitation['company_id'] );
	$name    = $company ? (string) ( ! empty( $company['trade_name'] ) ? $company['trade_name'] : $company['legal_name'] ) : '';

	return array(
		'invitationId' => (int) $invitation['id'],
		'companyName'  => $name,
		'invitedRole'  => (string) $invitation['invited_role'],
		'invitedEmail' => (string) $invitation['invited_email'],
		'cpfLocked'    => ! empty( $invitation['invited_cpf_hmac'] ),
	);
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

	// E-mail autenticado precisa bater com o destinatário do convite.
	if ( strtolower( (string) $invitation['invited_email'] ) !== strtolower( (string) $user->user_email ) ) {
		return new WP_Error( 'papelito_b2b_invitation_email_mismatch', 'Este convite foi enviado para outro e-mail.', array( 'status' => 403 ) );
	}

	// Quando o convite trava CPF, o CPF do perfil precisa corresponder ao HMAC.
	if ( ! empty( $invitation['invited_cpf_hmac'] ) ) {
		$cpf = papelito_customer_profile_get_cpf( $user_id );
		if ( is_wp_error( $cpf ) || null === $cpf ) {
			return new WP_Error( 'papelito_b2b_invitation_cpf_required', 'Complete seu perfil (CPF) para aceitar este convite.', array( 'status' => 422 ) );
		}
		$hmac = papelito_cpf_hmac( (string) $cpf );
		if ( is_wp_error( $hmac ) || ! hash_equals( (string) $invitation['invited_cpf_hmac'], (string) $hmac ) ) {
			return new WP_Error( 'papelito_b2b_invitation_cpf_mismatch', 'Este convite está vinculado a outro CPF.', array( 'status' => 403 ) );
		}
	}

	$company_id = (int) $invitation['company_id'];

	// Vínculo não pode já existir (evita "aceitar" o que já é membro ativo).
	$existing = papelito_company_member_get( $company_id, $user_id );
	if ( null !== $existing && in_array( (string) $existing['member_status'], array( 'active', 'suspended' ), true ) ) {
		return new WP_Error( 'papelito_b2b_membership_exists', 'Você já faz parte desta empresa.', array( 'status' => 409 ) );
	}

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		// Invalidação single-use: o UPDATE só afeta convite ainda 'pending' (corrida perde).
		$accepted = papelito_company_invitation_accept( (int) $invitation['id'], $user_id );
		if ( is_wp_error( $accepted ) ) {
			throw new RuntimeException( 'not acceptable' );
		}

		$member = papelito_company_member_upsert(
			$company_id,
			$user_id,
			array(
				'member_role'         => (string) $invitation['invited_role'],
				'member_status'       => 'active',
				'membership_origin'   => 'invitation',
				'invited_by_user_id'  => (int) $invitation['invited_by_user_id'],
				'approved_by_user_id' => (int) $invitation['invited_by_user_id'],
				'approved_at'         => current_time( 'mysql', true ),
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

/**
 * Envia o e-mail do convite com o link para a landing do frontend.
 *
 * Best-effort: falha de e-mail não derruba a criação do convite (o token já foi persistido).
 */
function papelito_company_invitation_send_email( int $company_id, string $email, string $token ): void {
	$base = rtrim( (string) papelito_env( 'PAPELITO_FRONTEND_URL', 'http://localhost:3000' ), '/' );
	$link = $base . '/convite/' . rawurlencode( $token );

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
