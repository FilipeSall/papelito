<?php
/**
 * Endpoints REST do painel da empresa B2B (Fase 1B).
 *
 * Todas as rotas /companies/current/* operam sobre a EMPRESA ATIVA recarregada pelo backend a
 * partir da sessão — nunca sobre companyId vindo do navegador. Mutações exigem Idempotency-Key
 * durável (company_idempotency.php), passam por rate limit e pela matriz RBAC (company_authz.php),
 * e retornam erros estáveis + auditoria sem PII.
 *
 * Rotas:
 *   POST   /companies/request-access
 *   GET    /companies/current
 *   POST   /companies/current/select
 *   GET    /companies/current/members
 *   PATCH  /companies/current/members/{id}
 *   DELETE /companies/current/members/{id}
 *   GET    /companies/current/invitations
 *   POST   /companies/current/invitations
 *   POST   /companies/current/invitations/{id}/resend
 *   DELETE /companies/current/invitations/{id}
 *   GET    /companies/current/access-requests
 *   POST   /companies/current/access-requests/{id}/approve
 *   POST   /companies/current/access-requests/{id}/reject
 *   POST   /companies/current/transfer-ownership
 *   GET    /company-invitations/{token}         (preview neutro pós-clique)
 *   POST   /company-invitations/{token}/accept
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Confirmações de e-mail de faturamento que uma empresa pode disparar por hora.
 *
 * Cobre a troca de endereço e o reenvio explícito. Folgado para o uso legítimo (errar o endereço,
 * não achar o e-mail, pedir de novo) e apertado o bastante para não servir de disparador.
 */
const PAPELITO_BILLING_EMAIL_SEND_MAX = 5;
const PAPELITO_COMPANY_MGMT_FORBIDDEN_MESSAGE = 'Ação não permitida.';
const PAPELITO_COMPANY_MGMT_RATE_LIMIT_MESSAGE = 'Muitas tentativas. Tente novamente em alguns instantes.';

/**
 * Permission callback compartilhado: exige usuário autenticado.
 */
function papelito_company_mgmt_permission(): bool {
	return get_current_user_id() > 0;
}

/**
 * Resolve o id da EMPRESA ATIVA do usuário (server-side). Erro estável se não houver ativa.
 *
 * @return int|WP_Error
 */
function papelito_company_mgmt_active_company_id( int $user_id ) {
	$context = papelito_company_context( $user_id );

	if ( ! empty( $context['companySelectionRequired'] ) ) {
		return new WP_Error( 'papelito_b2b_company_selection_required', 'Selecione a empresa ativa.', array( 'status' => 409 ) );
	}
	if ( empty( $context['companyId'] ) || 'complete' !== ( $context['onboardingStatus'] ?? '' ) ) {
		return new WP_Error( 'papelito_b2b_no_active_company', 'Nenhuma empresa ativa.', array( 'status' => 409 ) );
	}

	return (int) $context['companyId'];
}

/**
 * Envelope de idempotência para mutações: executa $run apenas uma vez por (actor, op, key).
 *
 * @param callable $run function(): int|WP_Error — retorna o resource_id afetado.
 * @return array{replayed:bool,resourceId:int}|WP_Error
 */
function papelito_company_mgmt_idempotent( WP_REST_Request $request, int $actor, string $operation, array $intent, callable $run ) {
	$writes = papelito_b2b_require_company_writes();
	if ( is_wp_error( $writes ) ) {
		return $writes;
	}
	$key  = (string) $request->get_header( 'Idempotency-Key' );
	$hash = papelito_company_idempotency_request_hash( $intent );

	$previous = papelito_company_idempotency_check( $actor, $operation, $key, $hash );
	if ( isset( $previous['error'] ) ) {
		return $previous['error'];
	}
	if ( null !== $previous ) {
		return array(
			'replayed'   => true,
			'resourceId' => (int) $previous['resource_id'],
		);
	}

	$result = $run();
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$resource_id = (int) $result;
	papelito_company_idempotency_store( $actor, $operation, $key, $hash, $resource_id, 200 );

	return array(
		'replayed'   => false,
		'resourceId' => $resource_id,
	);
}

/**
 * Serializa um membro para a listagem (sem PII).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function papelito_company_mgmt_member_view( array $row ): array {
	$user = get_userdata( (int) $row['user_id'] );

	return array(
		'memberId'    => (int) $row['id'],
		'userId'      => (int) $row['user_id'],
		'displayName' => $user instanceof WP_User ? $user->display_name : '',
		'email'       => $user instanceof WP_User ? $user->user_email : '',
		'role'        => (string) $row['member_role'],
		'status'      => papelito_company_member_is_operationally_active( $row ) ? (string) $row['member_status'] : 'expired',
		'origin'      => (string) ( $row['membership_origin'] ?? '' ),
		'expiresAt'   => $row['expires_at'] ?? null,
	);
}

/**
 * Serializa um convite para a listagem (sem token).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function papelito_company_mgmt_invitation_view( array $row ): array {
	return array(
		'invitationId' => (int) $row['id'],
		'email'        => (string) $row['invited_email'],
		'role'         => (string) $row['invited_role'],
		'status'       => (string) $row['invitation_status'],
		'expiresAt'    => $row['expires_at'] ?? null,
		'resendCount'  => (int) ( $row['resend_count'] ?? 0 ),
		'createdAt'    => $row['created_at'] ?? null,
	);
}

/**
 * Corpo do e-mail de confirmacao do endereco de faturamento.
 *
 * @param string $link Link de confirmacao.
 * @return string
 */
function papelito_company_mgmt_billing_email_body( string $link ): string {
	return implode(
		PHP_EOL,
		array(
			'Ola,',
			'',
			'Este endereço foi informado como e-mail de faturamento de uma empresa na Papelito.',
			'Confirme para que ele passe a receber os documentos fiscais dos pedidos.',
			'',
			'Abra o link abaixo para confirmar:',
			$link,
			'',
			'Este link expira em 24 horas e só pode ser usado uma vez.',
			'Se você não reconhece esta solicitação, ignore esta mensagem.',
			'',
			'Time Papelito',
		)
	);
}

/**
 * Gera token, grava a pendencia e envia o link de confirmacao.
 *
 * A base publica e resolvida ANTES de qualquer escrita: sem isso o banco ficava com uma pendencia
 * cujo unico link possivel era inabrivel.
 *
 * Ponto unico de envio (PATCH da empresa e reenvio explicito), e por isso e aqui que mora a cota:
 * o endereco de destino vem do chamador, entao sem teto um admin de empresa usa a Papelito como
 * disparador de e-mail contra terceiros. A chave e a empresa, nao o IP — a chamada vem do proxy.
 *
 * A cota e cobrada depois de resolver a base e antes de gravar: ambiente mal configurado nao pode
 * queimar as tentativas de quem so quer confirmar um endereco.
 *
 * @param int    $company_id     Empresa.
 * @param string $email          Endereco pendente (normalizado).
 * @param string $requested_base Base canonica informada pelo frontend.
 * @return true|WP_Error
 */
function papelito_company_mgmt_send_billing_email_confirmation( int $company_id, string $email, string $requested_base = '' ) {
	$token = wp_generate_password( 48, false, false );
	$link  = papelito_frontend_link( 'confirmar-email-faturamento?token=' . rawurlencode( $token ), $requested_base );
	if ( is_wp_error( $link ) ) {
		return $link;
	}

	if ( ! papelito_rate_limit( 'billing_email_send', 'company:' . $company_id, PAPELITO_BILLING_EMAIL_SEND_MAX, HOUR_IN_SECONDS ) ) {
		return new WP_Error(
			'papelito_b2b_billing_email_rate_limited',
			'Muitos envios de confirmação para esta empresa. Aguarde uma hora antes de tentar de novo.',
			array( 'status' => 429 )
		);
	}

	$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
	$updated = papelito_company_update( $company_id, array( 'pending_billing_email' => $email, 'pending_billing_email_token_hash' => hash( 'sha256', $token ), 'pending_billing_email_expires_at' => $expires, 'billing_email_verification_sent_at' => current_time( 'mysql', true ) ) );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	$sent = wp_mail( $email, 'Confirme o e-mail de faturamento da Papelito', papelito_company_mgmt_billing_email_body( (string) $link ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
	if ( ! $sent ) {
		return new WP_Error( 'papelito_b2b_billing_email_send_failed', 'Não foi possível enviar o e-mail de confirmação. Tente novamente em alguns instantes.', array( 'status' => 500 ) );
	}
	return true;
}

/**
 * Consome o token de confirmacao do e-mail de faturamento.
 *
 * Distingue os tres finais possiveis, porque o usuario precisa saber se tem remedio: expirado pede
 * reenvio, inexistente/consumido nao tem o que fazer, e endereco substituido caiu junto com o token
 * anterior (a pendencia nova sobrescreve o hash).
 *
 * @param string $token Token cru recebido do link.
 * @return WP_REST_Response|WP_Error
 */
function papelito_company_mgmt_confirm_billing_email( string $token ) {
	if ( '' === $token ) {
		return new WP_Error( 'papelito_b2b_invalid_billing_token', 'Token inválido.', array( 'status' => 422 ) );
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	// A busca ignora a expiracao de proposito: sem a linha nao ha como responder 410 no lugar de um
	// 404 generico.
	$company = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['companies']} WHERE pending_billing_email_token_hash = %s", hash( 'sha256', $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( ! is_array( $company ) ) {
		return new WP_Error( 'papelito_b2b_invalid_billing_token', 'Link de confirmação inválido ou já utilizado.', array( 'status' => 404 ) );
	}

	$expires_at = strtotime( (string) ( $company['pending_billing_email_expires_at'] ?? '' ) . ' UTC' );

	if ( false === $expires_at || $expires_at <= time() ) {
		return new WP_Error( 'papelito_b2b_billing_token_expired', 'Link de confirmação expirado. Solicite um novo e-mail para continuar.', array( 'status' => 410 ) );
	}

	$email = papelito_normalize_email( (string) ( $company['pending_billing_email'] ?? '' ) );

	if ( '' === $email ) {
		return new WP_Error( 'papelito_b2b_invalid_billing_token', 'Link de confirmação inválido ou já utilizado.', array( 'status' => 404 ) );
	}

	$updated = papelito_company_update(
		(int) $company['id'],
		array(
			'billing_email'                    => $email,
			'billing_email_verified_at'        => current_time( 'mysql', true ),
			'pending_billing_email'            => null,
			'pending_billing_email_token_hash' => null,
			'pending_billing_email_expires_at' => null,
		)
	);

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	papelito_company_audit( (int) $company['id'], null, 'billing_email_verified', array( 'source' => 'token' ) );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

function papelito_company_mgmt_forbidden(): WP_Error {
	return new WP_Error( 'papelito_b2b_forbidden', PAPELITO_COMPANY_MGMT_FORBIDDEN_MESSAGE, array( 'status' => 403 ) );
}

function papelito_company_mgmt_rate_limited(): WP_Error {
	return new WP_Error( 'papelito_rate_limited', PAPELITO_COMPANY_MGMT_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
}

function papelito_company_mgmt_require_manager( int $actor, int $company_id ) {
	$loaded = papelito_company_authz_load( $actor, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ?? array() ) ) {
		return papelito_company_mgmt_forbidden();
	}
	return $loaded;
}

function papelito_company_mgmt_update_phone( int $actor, int $company_id, string $raw_phone ) {
	$phone  = sanitize_text_field( $raw_phone );
	$result = papelito_company_update( $company_id, array( 'phone' => $phone ?: null ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	papelito_company_audit( $company_id, $actor, 'company_phone_updated', array( 'target_user_id' => $actor ) );
	return true;
}

function papelito_company_mgmt_update_billing_email( int $actor, int $company_id, array $company, string $raw_email, string $requested_base = '' ) {
	$email = papelito_normalize_email( $raw_email );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'papelito_b2b_invalid_billing_email', 'E-mail de faturamento inválido.', array( 'status' => 422 ) );
	}

	$user     = get_userdata( $actor );
	$decision = papelito_billing_email_decide_update(
		$company,
		$email,
		$user instanceof WP_User ? papelito_normalize_email( (string) $user->user_email ) : '',
		papelito_billing_email_account_is_verified( $actor )
	);
	if ( 'confirm_matches_account' === $decision ) {
		if ( ! papelito_billing_email_confirm_company( $company_id, $email, papelito_billing_email_account_verified_at( $actor ), 'account_email' ) ) {
			return new WP_Error( 'papelito_company_update_failed', 'Falha ao atualizar empresa.', array( 'status' => 500 ) );
		}
	}
	if ( 'send_confirmation' !== $decision ) {
		return true;
	}

	$result = papelito_company_mgmt_send_billing_email_confirmation( $company_id, $email, $requested_base );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	papelito_company_audit( $company_id, $actor, 'billing_email_confirmation_requested', array( 'target_user_id' => $actor ) );
	return true;
}

function papelito_company_mgmt_update_details( int $actor, int $company_id, array $body, string $requested_base = '' ) {
	$loaded = papelito_company_mgmt_require_manager( $actor, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}

	if ( array_key_exists( 'phone', $body ) ) {
		$result = papelito_company_mgmt_update_phone( $actor, $company_id, (string) $body['phone'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	if ( array_key_exists( 'billingEmail', $body ) ) {
		$result = papelito_company_mgmt_update_billing_email( $actor, $company_id, $loaded['company'], (string) $body['billingEmail'], $requested_base );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	return true;
}

function papelito_company_mgmt_resend_billing_email_run( int $actor, int $company_id, string $requested_base = '' ) {
	$loaded = papelito_company_authz_load( $actor, $company_id );
	if ( is_wp_error( $loaded ) || ! papelito_company_authz_can_manage( $loaded['membership'] ?? array() ) ) {
		return papelito_company_mgmt_forbidden();
	}
	$email = (string) ( $loaded['company']['pending_billing_email'] ?? '' );
	if ( '' === $email ) {
		return new WP_Error( 'papelito_b2b_no_pending_billing_email', 'Não há e-mail pendente.', array( 'status' => 409 ) );
	}
	$result = papelito_company_mgmt_send_billing_email_confirmation( $company_id, $email, $requested_base );
	return is_wp_error( $result ) ? $result : $company_id;
}

function papelito_company_mgmt_audit_view( int $company_id, int $actor, array $row ): array {
	$payload = json_decode( (string) $row['payload_json'], true );
	$payload = is_array( $payload ) ? $payload : array();
	$target  = (int) ( $payload['target_user_id'] ?? $payload['requester_user_id'] ?? 0 );
	$person = static function ( int $user_id ) use ( $company_id ): ?array {
		if ( $user_id <= 0 ) { return null; }
		$user = get_userdata( $user_id ); $member = papelito_company_member_get( $company_id, $user_id );
		return array( 'displayName' => $user instanceof WP_User ? $user->display_name : 'Usuário removido', 'role' => $member['member_role'] ?? null );
	};
	return array( 'action' => (string) $row['action'], 'createdAt' => (string) $row['created_at'], 'actor' => $person( (int) $row['actor_user_id'] ), 'target' => $person( $target ) );
}

function papelito_company_mgmt_request_access() {
	return new WP_Error( 'papelito_b2b_invite_required', 'O acesso a uma empresa existente é concedido por convite por e-mail do administrador.', array( 'status' => 410 ) );
}

function papelito_company_mgmt_get_current() {
	return new WP_REST_Response( papelito_company_context( get_current_user_id() ), 200 );
}

function papelito_company_mgmt_update_current( WP_REST_Request $request ) {
	$actor      = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $actor );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}

	$body           = (array) $request->get_json_params();
	$requested_base = papelito_frontend_base_from_request( $request );
	$outcome        = papelito_company_mgmt_idempotent(
		$request,
		$actor,
		'company_details_update',
		$body,
		static function () use ( $actor, $company_id, $body, $requested_base ) {
			$result = papelito_company_mgmt_update_details( $actor, $company_id, $body, $requested_base );
			return is_wp_error( $result ) ? $result : $company_id;
		}
	);
	return is_wp_error( $outcome ) ? $outcome : new WP_REST_Response( papelito_company_context( $actor ), 200 );
}

function papelito_company_mgmt_get_audit( WP_REST_Request $request ) {
	$actor      = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $actor );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$loaded = papelito_company_authz_load( $actor, $company_id );
	if ( is_wp_error( $loaded ) || ! in_array( (string) ( $loaded['membership']['member_role'] ?? '' ), array( 'owner', 'admin' ), true ) ) {
		return papelito_company_mgmt_forbidden();
	}

	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = min( 50, max( 1, (int) ( $request->get_param( 'perPage' ) ?: 20 ) ) );
	global $wpdb;
	$tables = papelito_company_table_names();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT actor_user_id, action, payload_json, created_at FROM {$tables['audit']} WHERE company_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $company_id, $per_page, ( $page - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return new WP_REST_Response(
		array(
			'items'  => array_map( static fn( array $row ): array => papelito_company_mgmt_audit_view( $company_id, $actor, $row ), is_array( $rows ) ? $rows : array() ),
			'page'   => $page,
			'perPage' => $per_page,
		),
		200
	);
}

function papelito_company_mgmt_resend_billing_email( WP_REST_Request $request ) {
	$actor      = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $actor );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}

	$requested_base = papelito_frontend_base_from_request( $request );
	$outcome        = papelito_company_mgmt_idempotent(
		$request,
		$actor,
		'billing_email_resend',
		array(),
		static function () use ( $actor, $company_id, $requested_base ) {
			return papelito_company_mgmt_resend_billing_email_run( $actor, $company_id, $requested_base );
		}
	);
	return is_wp_error( $outcome ) ? $outcome : new WP_REST_Response( papelito_company_context( $actor ), 200 );
}

function papelito_company_mgmt_confirm_billing_email_endpoint( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	$token  = sanitize_text_field( (string) ( $params['token'] ?? '' ) );
	if ( ! papelito_rate_limit( 'billing_email_confirm', 'token:' . hash( 'sha256', $token ), 10, 300 ) ) {
		return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas.', array( 'status' => 429 ) );
	}
	return papelito_company_mgmt_confirm_billing_email( $token );
}

function papelito_company_mgmt_select_current( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$writes  = papelito_b2b_require_company_writes();
	if ( is_wp_error( $writes ) ) {
		return $writes;
	}
	$company_id = (int) ( ( (array) $request->get_json_params() )['companyId'] ?? 0 );
	if ( $company_id <= 0 ) {
		return new WP_Error( 'papelito_b2b_invalid_company', 'Empresa inválida.', array( 'status' => 422 ) );
	}
	$selected = papelito_company_active_select( $user_id, $company_id );
	return is_wp_error( $selected ) ? $selected : new WP_REST_Response( papelito_company_context( $user_id ), 200 );
}

function papelito_company_mgmt_register_context_routes( string $ns ): void {
	register_rest_route( $ns, '/companies/request-access', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_request_access' ) );
	register_rest_route( $ns, '/companies/current', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_get_current' ) );
	register_rest_route( $ns, '/companies/current', array( 'methods' => 'PATCH', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_update_current' ) );
	register_rest_route( $ns, '/companies/current/audit', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_get_audit' ) );
	register_rest_route( $ns, '/companies/current/billing-email/resend', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_resend_billing_email' ) );
	register_rest_route( $ns, '/companies/billing-email/confirm', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'papelito_company_mgmt_confirm_billing_email_endpoint' ) );
	register_rest_route( $ns, '/companies/current/select', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_select_current' ) );
}

function papelito_company_mgmt_get_members() {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$loaded = papelito_company_authz_load( $user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
	}
	$rows = papelito_company_members_list( $company_id, array( 'active', 'suspended', 'pending_company_approval' ) );
	return new WP_REST_Response( array( 'items' => array_map( 'papelito_company_mgmt_member_view', $rows ) ), 200 );
}

function papelito_company_mgmt_member_for_request( WP_REST_Request $request, int $company_id ) {
	$member = papelito_company_member_get_by_id( (int) $request['id'] );
	if ( null === $member || (int) $member['company_id'] !== $company_id ) {
		return new WP_Error( 'papelito_b2b_member_not_found', 'Membro não encontrado.', array( 'status' => 404 ) );
	}
	return $member;
}

function papelito_company_mgmt_parse_expiration( $raw_expires ) {
	if ( null === $raw_expires ) {
		return null;
	}
	if ( ! is_string( $raw_expires ) || '' === trim( $raw_expires ) ) {
		return new WP_Error( 'papelito_b2b_invalid_expiration', 'Data de expiração inválida.', array( 'status' => 422 ) );
	}
	$date = DateTimeImmutable::createFromFormat( DateTimeInterface::RFC3339, $raw_expires );
	if ( ! $date || $date->getTimestamp() <= time() ) {
		return new WP_Error( 'papelito_b2b_invalid_expiration', 'Data de expiração deve estar no futuro.', array( 'status' => 422 ) );
	}
	return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

function papelito_company_mgmt_patch_member_run( int $user_id, int $company_id, int $target_user_id, array $body ) {
	if ( isset( $body['role'] ) ) {
		$result = papelito_company_member_change_role( $user_id, $company_id, $target_user_id, (string) $body['role'] );
	} elseif ( isset( $body['status'] ) ) {
		$result = papelito_company_member_set_status( $user_id, $company_id, $target_user_id, (string) $body['status'] );
	} elseif ( array_key_exists( 'expiresAt', $body ) ) {
		$expires = papelito_company_mgmt_parse_expiration( $body['expiresAt'] );
		if ( is_wp_error( $expires ) ) {
			return $expires;
		}
		$result = papelito_company_member_set_expiration( $user_id, $company_id, $target_user_id, $expires );
	} else {
		return new WP_Error( 'papelito_b2b_no_op', 'Nada a atualizar.', array( 'status' => 422 ) );
	}
	return is_wp_error( $result ) ? $result : (int) ( $result['id'] ?? 0 );
}

function papelito_company_mgmt_mutation_response( array $outcome ): WP_REST_Response {
	return new WP_REST_Response( array( 'ok' => true, 'replayed' => $outcome['replayed'] ), 200 );
}

function papelito_company_mgmt_patch_member( WP_REST_Request $request ) {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$member = papelito_company_mgmt_member_for_request( $request, $company_id );
	if ( is_wp_error( $member ) ) {
		return $member;
	}
	$body           = (array) $request->get_json_params();
	$target_user_id = (int) $member['user_id'];
	$run            = static fn() => papelito_company_mgmt_patch_member_run( $user_id, $company_id, $target_user_id, $body );
	$outcome        = papelito_company_mgmt_idempotent( $request, $user_id, 'member_patch', array( 'member' => (int) $request['id'], 'body' => $body ), $run );
	return is_wp_error( $outcome ) ? $outcome : papelito_company_mgmt_mutation_response( $outcome );
}

function papelito_company_mgmt_revoke_member( WP_REST_Request $request ) {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$member = papelito_company_mgmt_member_for_request( $request, $company_id );
	if ( is_wp_error( $member ) ) {
		return $member;
	}
	$target_user_id = (int) $member['user_id'];
	$run            = static function () use ( $user_id, $company_id, $target_user_id ) {
		$result = papelito_company_member_set_status( $user_id, $company_id, $target_user_id, 'revoke' );
		return is_wp_error( $result ) ? $result : (int) ( $result['id'] ?? 0 );
	};
	$outcome = papelito_company_mgmt_idempotent( $request, $user_id, 'member_revoke', array( 'member' => (int) $request['id'] ), $run );
	return is_wp_error( $outcome ) ? $outcome : papelito_company_mgmt_mutation_response( $outcome );
}

function papelito_company_mgmt_register_member_routes( string $ns ): void {
	register_rest_route( $ns, '/companies/current/members', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_get_members' ) );
	register_rest_route(
		$ns,
		'/companies/current/members/(?P<id>\d+)',
		array(
			array( 'methods' => 'PATCH', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_patch_member' ),
			array( 'methods' => 'DELETE', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_revoke_member' ),
		)
	);
}

function papelito_company_mgmt_get_invitations() {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$loaded = papelito_company_authz_load( $user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
	}
	$rows = papelito_company_invitations_list( $company_id );
	return new WP_REST_Response( array( 'items' => array_map( 'papelito_company_mgmt_invitation_view', $rows ) ), 200 );
}

function papelito_company_mgmt_create_invitation( WP_REST_Request $request ) {
	if ( ! papelito_auth_rate_limit( 'company_invite', 20, 60 ) ) {
		return papelito_company_mgmt_rate_limited();
	}
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$body = (array) $request->get_json_params();
	$run  = static function () use ( $user_id, $company_id, $body ) {
		$result = papelito_company_invitation_issue( $user_id, $company_id, $body );
		return is_wp_error( $result ) ? $result : (int) $result['id'];
	};
	$intent  = array( 'email' => strtolower( (string) ( $body['invited_email'] ?? '' ) ), 'role' => (string) ( $body['invited_role'] ?? '' ) );
	$outcome = papelito_company_mgmt_idempotent( $request, $user_id, 'invitation_create', $intent, $run );
	if ( is_wp_error( $outcome ) ) {
		return $outcome;
	}
	return new WP_REST_Response( array( 'invitationId' => $outcome['resourceId'], 'replayed' => $outcome['replayed'] ), 201 );
}

function papelito_company_mgmt_resend_invitation( WP_REST_Request $request ) {
	if ( ! papelito_auth_rate_limit( 'company_invite_resend', 20, 60 ) ) {
		return papelito_company_mgmt_rate_limited();
	}
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$invitation_id = (int) $request['id'];
	$run           = static function () use ( $user_id, $company_id, $invitation_id ) {
		$result = papelito_company_invitation_reissue( $user_id, $company_id, $invitation_id );
		return is_wp_error( $result ) ? $result : (int) $result['id'];
	};
	$outcome = papelito_company_mgmt_idempotent( $request, $user_id, 'invitation_resend', array( 'invitation' => $invitation_id ), $run );
	return is_wp_error( $outcome ) ? $outcome : papelito_company_mgmt_mutation_response( $outcome );
}

function papelito_company_mgmt_cancel_invitation( WP_REST_Request $request ) {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$invitation_id = (int) $request['id'];
	$reason        = sanitize_text_field( (string) ( ( (array) $request->get_json_params() )['reason'] ?? '' ) );
	$run           = static function () use ( $user_id, $company_id, $invitation_id, $reason ) {
		$result = papelito_company_invitation_cancel( $user_id, $company_id, $invitation_id, $reason );
		return is_wp_error( $result ) ? $result : $invitation_id;
	};
	$outcome = papelito_company_mgmt_idempotent( $request, $user_id, 'invitation_revoke', array( 'invitation' => $invitation_id ), $run );
	return is_wp_error( $outcome ) ? $outcome : papelito_company_mgmt_mutation_response( $outcome );
}

function papelito_company_mgmt_register_invitation_routes( string $ns ): void {
	register_rest_route(
		$ns,
		'/companies/current/invitations',
		array(
			array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_get_invitations' ),
			array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_create_invitation' ),
		)
	);
	register_rest_route( $ns, '/companies/current/invitations/(?P<id>\d+)/resend', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_resend_invitation' ) );
	register_rest_route( $ns, '/companies/current/invitations/(?P<id>\d+)', array( 'methods' => 'DELETE', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_cancel_invitation' ) );
}

function papelito_company_mgmt_get_access_requests() {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$list = papelito_company_access_requests_list( $user_id, $company_id );
	return is_wp_error( $list ) ? $list : new WP_REST_Response( array( 'items' => $list ), 200 );
}

function papelito_company_mgmt_access_request_member( WP_REST_Request $request, int $company_id ) {
	$member = papelito_company_member_get_by_id( (int) $request['id'] );
	if ( null === $member || (int) $member['company_id'] !== $company_id ) {
		return new WP_Error( 'papelito_b2b_member_not_found', 'Solicitação não encontrada.', array( 'status' => 404 ) );
	}
	return $member;
}

function papelito_company_mgmt_approve_access_request( WP_REST_Request $request ) {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$member = papelito_company_mgmt_access_request_member( $request, $company_id );
	if ( is_wp_error( $member ) ) {
		return $member;
	}
	$target_user_id = (int) $member['user_id'];
	$role           = (string) ( ( (array) $request->get_json_params() )['role'] ?? 'buyer' );
	$run            = static function () use ( $user_id, $company_id, $target_user_id, $role ) {
		$result = papelito_company_access_request_approve( $user_id, $company_id, $target_user_id, $role );
		return is_wp_error( $result ) ? $result : (int) ( $result['id'] ?? 0 );
	};
	$outcome = papelito_company_mgmt_idempotent( $request, $user_id, 'access_request_approve', array( 'member' => (int) $request['id'], 'role' => $role ), $run );
	return is_wp_error( $outcome ) ? $outcome : papelito_company_mgmt_mutation_response( $outcome );
}

function papelito_company_mgmt_reject_access_request( WP_REST_Request $request ) {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$member = papelito_company_mgmt_access_request_member( $request, $company_id );
	if ( is_wp_error( $member ) ) {
		return $member;
	}
	$target_user_id = (int) $member['user_id'];
	$member_id      = (int) $member['id'];
	$reason         = sanitize_text_field( (string) ( ( (array) $request->get_json_params() )['reason'] ?? '' ) );
	$run            = static function () use ( $user_id, $company_id, $target_user_id, $reason, $member_id ) {
		$result = papelito_company_access_request_reject( $user_id, $company_id, $target_user_id, $reason );
		return is_wp_error( $result ) ? $result : $member_id;
	};
	$outcome = papelito_company_mgmt_idempotent( $request, $user_id, 'access_request_reject', array( 'member' => (int) $request['id'], 'reason' => $reason ), $run );
	return is_wp_error( $outcome ) ? $outcome : papelito_company_mgmt_mutation_response( $outcome );
}

function papelito_company_mgmt_register_access_request_routes( string $ns ): void {
	register_rest_route( $ns, '/companies/current/access-requests', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_get_access_requests' ) );
	register_rest_route( $ns, '/companies/current/access-requests/(?P<id>\d+)/approve', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_approve_access_request' ) );
	register_rest_route( $ns, '/companies/current/access-requests/(?P<id>\d+)/reject', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_reject_access_request' ) );
}

function papelito_company_mgmt_transfer_ownership( WP_REST_Request $request ) {
	$user_id    = get_current_user_id();
	$company_id = papelito_company_mgmt_active_company_id( $user_id );
	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}
	$target_user_id = (int) ( ( (array) $request->get_json_params() )['targetUserId'] ?? 0 );
	if ( $target_user_id <= 0 ) {
		return new WP_Error( 'papelito_b2b_invalid_target', 'Destinatário inválido.', array( 'status' => 422 ) );
	}
	$run = static function () use ( $user_id, $company_id, $target_user_id ) {
		$result = papelito_company_transfer_ownership( $user_id, $company_id, $target_user_id );
		return is_wp_error( $result ) ? $result : $company_id;
	};
	$outcome = papelito_company_mgmt_idempotent( $request, $user_id, 'transfer_ownership', array( 'target' => $target_user_id ), $run );
	return is_wp_error( $outcome ) ? $outcome : papelito_company_mgmt_mutation_response( $outcome );
}

function papelito_company_mgmt_register_transfer_route( string $ns ): void {
	register_rest_route( $ns, '/companies/current/transfer-ownership', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_transfer_ownership' ) );
}

function papelito_company_mgmt_preview_invitation( WP_REST_Request $request ) {
	if ( ! papelito_auth_rate_limit( 'invitation_preview', 30, 60 ) ) {
		return papelito_company_mgmt_rate_limited();
	}
	$result = papelito_company_invitation_preview( (string) $request['token'] );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

function papelito_company_mgmt_decline_invitation( WP_REST_Request $request ) {
	$result = papelito_company_invitation_decline_token( get_current_user_id(), (string) $request['token'] );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'ok' => true ), 200 );
}

function papelito_company_mgmt_accept_invitation( WP_REST_Request $request ) {
	if ( ! papelito_auth_rate_limit( 'invitation_accept', 20, 60 ) ) {
		return papelito_company_mgmt_rate_limited();
	}
	$writes = papelito_b2b_require_company_writes();
	if ( is_wp_error( $writes ) ) {
		return $writes;
	}
	$result = papelito_company_invitation_accept_token( get_current_user_id(), (string) $request['token'] );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

function papelito_company_mgmt_register_token_routes( string $ns ): void {
	register_rest_route( $ns, '/company-invitations/(?P<token>[A-Za-z0-9]+)', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'papelito_company_mgmt_preview_invitation' ) );
	register_rest_route( $ns, '/company-invitations/(?P<token>[A-Za-z0-9]+)/decline', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_decline_invitation' ) );
	register_rest_route( $ns, '/company-invitations/(?P<token>[A-Za-z0-9]+)/accept', array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => 'papelito_company_mgmt_accept_invitation' ) );
}

function papelito_company_mgmt_register_routes(): void {
	$ns = 'papelito/v1';
	papelito_company_mgmt_register_context_routes( $ns );
	papelito_company_mgmt_register_member_routes( $ns );
	papelito_company_mgmt_register_invitation_routes( $ns );
	papelito_company_mgmt_register_access_request_routes( $ns );
	papelito_company_mgmt_register_transfer_route( $ns );
	papelito_company_mgmt_register_token_routes( $ns );
}

add_action( 'rest_api_init', 'papelito_company_mgmt_register_routes' );
