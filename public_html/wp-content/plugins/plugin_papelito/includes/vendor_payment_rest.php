<?php
/**
 * REST do painel financeiro do vendor: saúde dos gateways, preferência e perfil financeiro comum.
 *
 * Handlers finos: carregam o contexto autoritativo (sempre o vendor autenticado; nunca um vendor
 * vindo do payload), delegam aos módulos de domínio e projetam resposta segura. Nenhuma rota daqui
 * cria cobrança; o refresh usa só operações neutras (GET do recipient, BrasilAPI, recomputar saúde)
 * e tem limite por vendor, não por IP do proxy.
 *
 * Mutação sensível do perfil (conta bancária, CPF, nascimento) exige a senha atual, como as demais
 * reautenticações do plugin. O espelho administrativo usa `manage_options` e é onde a Papelito
 * registra a confirmação de titularidade da conta Mercado Pago (com referência de evidência).
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

/**
 * Exige vendedor autenticado.
 *
 * @return true|WP_Error
 */
function papelito_vendor_payment_rest_permission() {
	$check = function_exists( 'papelito_vendor_dashboard_require_seller' ) ? papelito_vendor_dashboard_require_seller() : false;

	if ( is_wp_error( $check ) ) {
		return $check;
	}

	return false === $check ? new WP_Error( 'papelito_vendor_forbidden', 'Acesso restrito a vendors.', array( 'status' => 403 ) ) : true;
}

/**
 * Exige administrador.
 *
 * @return true|WP_Error
 */
function papelito_admin_vendor_payment_rest_permission() {
	return current_user_can( 'manage_options' ) ? true : new WP_Error( 'papelito_forbidden', 'Acesso restrito à administração.', array( 'status' => 403 ) );
}

/**
 * Resposta privada e sem cache.
 *
 * @param array<string,mixed> $data Corpo.
 * @return WP_REST_Response
 */
function papelito_vendor_payment_rest_response( array $data ): WP_REST_Response {
	$response = new WP_REST_Response( $data, 200 );
	$response->header( 'Cache-Control', 'no-store, private' );

	return $response;
}

/**
 * Resumo financeiro seguro de um vendor: modo, status dos gateways, preferência e conta MP.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>
 */
function papelito_vendor_payment_summary( int $vendor_id ): array {
	$account = function_exists( 'papelito_mercadopago_environment' )
		? papelito_vendor_payment_account_latest( $vendor_id, 'mercadopago', papelito_mercadopago_environment() )
		: null;

	return array(
		'mode'               => papelito_vendor_payment_mode( $vendor_id, false ),
		'gateways'           => papelito_payment_gateway_statuses( $vendor_id ),
		'preference'         => papelito_vendor_payment_preference( $vendor_id ),
		'mercadopagoAccount' => is_array( $account ) && function_exists( 'papelito_vendor_payment_account_public' ) ? papelito_vendor_payment_account_public( $account ) : null,
	);
}

/**
 * GET /vendor/payment-gateways.
 *
 * @return WP_REST_Response
 */
function papelito_vendor_payment_rest_gateways(): WP_REST_Response {
	return papelito_vendor_payment_rest_response( papelito_vendor_payment_summary( get_current_user_id() ) );
}

/**
 * PUT /vendor/payment-preferences: `{ preferredProvider, configurationVersion }`.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_vendor_payment_rest_preferences( WP_REST_Request $request ) {
	$body    = (array) $request->get_json_params();
	$version = $body['configurationVersion'] ?? null;

	if ( ! is_int( $version ) && ! ( is_string( $version ) && ctype_digit( $version ) ) ) {
		return new WP_Error( 'papelito_payment_preference_version_required', 'Atualize a página antes de alterar o gateway preferido.', array( 'status' => 422 ) );
	}

	$saved = papelito_vendor_payment_preference_save( get_current_user_id(), sanitize_key( (string) ( $body['preferredProvider'] ?? '' ) ), (int) $version );

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return papelito_vendor_payment_rest_response( array( 'preference' => $saved ) );
}

/**
 * Perfil público com pendências separadas por gateway.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>
 */
function papelito_vendor_payment_profile_view( int $vendor_id ): array {
	return array_merge(
		papelito_vendor_financial_profile_public( $vendor_id ),
		array(
			'cnpj'    => sanitize_text_field( (string) get_user_meta( $vendor_id, 'cnpj', true ) ),
			'pending' => array(
				'pagarme'     => papelito_pagarme_financial_profile_pending( $vendor_id ),
				'mercadopago' => array( 'pendingFields' => array() ),
			),
		)
	);
}

/**
 * GET /vendor/financial-profile.
 *
 * @return WP_REST_Response
 */
function papelito_vendor_payment_rest_profile_get(): WP_REST_Response {
	return papelito_vendor_payment_rest_response( papelito_vendor_payment_profile_view( get_current_user_id() ) );
}

/**
 * Diz se a edição toca dado financeiro sensível que exige a senha atual.
 *
 * @param array<string,mixed> $patch Edição.
 * @return bool
 */
function papelito_vendor_payment_patch_is_sensitive( array $patch ): bool {
	$partner = is_array( $patch['managingPartners'][0] ?? null ) ? $patch['managingPartners'][0] : array();

	return isset( $patch['bankAccount'] ) || array_key_exists( 'document', $partner ) || array_key_exists( 'birthdate', $partner );
}

/**
 * Confere a senha atual do usuário autenticado, com limite por usuário.
 *
 * @param string $password Senha informada.
 * @return true|WP_Error
 */
function papelito_vendor_payment_reauthenticate( string $password ) {
	$user_id = get_current_user_id();

	if ( function_exists( 'papelito_auth_rate_limit' ) && ! papelito_auth_rate_limit( 'payment_reauth', 5, 300, 'user:' . $user_id ) ) {
		return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Aguarde alguns minutos.', array( 'status' => 429 ) );
	}

	$user = get_user_by( 'id', $user_id );

	if ( ! $user instanceof WP_User || '' === $password || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
		return new WP_Error( 'papelito_reauthentication_failed', 'Não foi possível confirmar a senha atual.', array( 'status' => 403 ) );
	}

	return true;
}

/**
 * PATCH /vendor/financial-profile: `{ version, profile, currentPassword? }`.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_vendor_payment_rest_profile_patch( WP_REST_Request $request ) {
	$body  = (array) $request->get_json_params();
	$patch = is_array( $body['profile'] ?? null ) ? $body['profile'] : array();

	if ( ! isset( $body['version'] ) || ! is_numeric( $body['version'] ) ) {
		return new WP_Error( 'papelito_financial_profile_version_required', 'Atualize a página antes de salvar os dados financeiros.', array( 'status' => 422 ) );
	}

	if ( papelito_vendor_payment_patch_is_sensitive( $patch ) ) {
		$reauth = papelito_vendor_payment_reauthenticate( (string) ( $body['currentPassword'] ?? '' ) );
		if ( is_wp_error( $reauth ) ) {
			return $reauth;
		}
	}

	$vendor_id = get_current_user_id();
	$saved     = papelito_vendor_financial_profile_patch( $vendor_id, $patch, (int) $body['version'] );

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return papelito_vendor_payment_rest_response( papelito_vendor_payment_profile_view( $vendor_id ) );
}

/**
 * POST /vendor/payment-gateways/refresh: reconsulta neutra, limitada por vendor.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_vendor_payment_rest_refresh() {
	$vendor_id = get_current_user_id();

	if ( function_exists( 'papelito_auth_rate_limit' ) && ! papelito_auth_rate_limit( 'payment_gateways_refresh', 3, 600, 'user:' . $vendor_id ) ) {
		return new WP_Error( 'papelito_rate_limited', 'Você já atualizou o estado há pouco. Aguarde alguns minutos.', array( 'status' => 429 ) );
	}

	if ( function_exists( 'papelito_vendor_cnae_refresh' ) ) {
		papelito_vendor_cnae_refresh( $vendor_id );
	}

	if ( function_exists( 'papelito_pagarme_get_vendor_recipient_id' ) && '' !== papelito_pagarme_get_vendor_recipient_id( $vendor_id ) ) {
		papelito_pagarme_sync_vendor_recipient( $vendor_id );
	}

	papelito_vendor_payment_refresh_mercadopago_token( $vendor_id );

	foreach ( array( 'pagarme', 'mercadopago' ) as $provider ) {
		papelito_payment_gateway_health_record( $vendor_id, $provider );
	}

	return papelito_vendor_payment_rest_response( papelito_vendor_payment_summary( $vendor_id ) );
}

/**
 * Renova o token MP do vendor se estiver na janela de vencimento.
 *
 * @param int $vendor_id Vendor.
 * @return void
 */
function papelito_vendor_payment_refresh_mercadopago_token( int $vendor_id ): void {
	if ( ! function_exists( 'papelito_mercadopago_token_needs_refresh' ) ) {
		return;
	}

	$account = papelito_vendor_payment_account_current( $vendor_id, 'mercadopago', papelito_mercadopago_environment() );

	if ( is_array( $account ) && papelito_mercadopago_token_needs_refresh( $account, time() ) ) {
		papelito_mercadopago_refresh_account( (int) $account['id'] );
	}
}

/**
 * Vendor do parâmetro de rota administrativa, validado.
 *
 * @param WP_REST_Request $request Requisição.
 * @return int|WP_Error
 */
function papelito_admin_vendor_payment_target( WP_REST_Request $request ) {
	$vendor_id = absint( $request->get_param( 'id' ) );
	$user      = $vendor_id > 0 ? get_userdata( $vendor_id ) : null;

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor não encontrado.', array( 'status' => 404 ) );
	}

	return $vendor_id;
}

/**
 * GET /admin/vendors/{id}/payment-gateways: mesma projeção do painel do vendor.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_admin_vendor_payment_rest_get( WP_REST_Request $request ) {
	$vendor_id = papelito_admin_vendor_payment_target( $request );

	if ( is_wp_error( $vendor_id ) ) {
		return $vendor_id;
	}

	return papelito_vendor_payment_rest_response( papelito_vendor_payment_summary( $vendor_id ) );
}

/**
 * POST /admin/vendors/{id}/mercadopago/identity: confirmação administrativa de titularidade.
 *
 * A API do Mercado Pago não oferece prova documentada de CNPJ/habilitação; a Papelito registra a
 * conferência com referência curta da evidência (chamado, protocolo), nunca o documento. O admin
 * confirma a conta que viu (`accountId`): se o vendor reconectou outra no meio, a resposta é 409.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_admin_vendor_mercadopago_identity( WP_REST_Request $request ) {
	$vendor_id = papelito_admin_vendor_payment_target( $request );
	if ( is_wp_error( $vendor_id ) ) {
		return $vendor_id;
	}

	$body       = (array) $request->get_json_params();
	$status     = sanitize_key( (string) ( $body['status'] ?? '' ) );
	$evidence   = sanitize_text_field( (string) ( $body['evidenceRef'] ?? '' ) );
	$account_id = absint( $body['accountId'] ?? 0 );

	if ( ! in_array( $status, array( 'verified', 'mismatch' ), true ) || '' === $evidence || $account_id <= 0 ) {
		return new WP_Error( 'papelito_payment_identity_invalid', 'Informe a conta conferida, o resultado e a referência da evidência.', array( 'status' => 422 ) );
	}

	$account = papelito_vendor_payment_account_latest( $vendor_id, 'mercadopago', papelito_mercadopago_environment() );
	if ( ! is_array( $account ) ) {
		return new WP_Error( 'papelito_mercadopago_not_connected', 'A loja ainda não conectou o Mercado Pago.', array( 'status' => 404 ) );
	}

	if ( (int) $account['id'] !== $account_id ) {
		return new WP_Error( 'papelito_payment_identity_account_changed', 'A loja reconectou outra conta do Mercado Pago. Recarregue e confira a conta atual.', array( 'status' => 409 ) );
	}

	papelito_vendor_payment_account_set_identity( $account_id, $status, get_current_user_id(), $evidence );
	papelito_vendor_payment_identity_audit_append(
		$vendor_id,
		array(
			'accountId'   => $account_id,
			'collectorId' => (string) ( $account['collector_id'] ?? '' ),
			'status'      => $status,
			'actorId'     => get_current_user_id(),
			'evidenceRef' => $evidence,
		)
	);
	do_action( 'papelito_payment_gateway_account_changed', $vendor_id, 'mercadopago' );

	return papelito_vendor_payment_rest_response( papelito_vendor_payment_summary( $vendor_id ) );
}

/**
 * Registra as rotas do painel financeiro.
 *
 * @return void
 */
function papelito_vendor_payment_rest_register_routes(): void {
	$vendor = 'papelito_vendor_payment_rest_permission';
	$admin  = 'papelito_admin_vendor_payment_rest_permission';
	$routes = array(
		array( '/vendor/payment-gateways', WP_REST_Server::READABLE, $vendor, 'papelito_vendor_payment_rest_gateways' ),
		array( '/vendor/payment-preferences', 'PUT', $vendor, 'papelito_vendor_payment_rest_preferences' ),
		array( '/vendor/financial-profile', WP_REST_Server::READABLE, $vendor, 'papelito_vendor_payment_rest_profile_get' ),
		array( '/vendor/financial-profile', 'PATCH', $vendor, 'papelito_vendor_payment_rest_profile_patch' ),
		array( '/vendor/payment-gateways/refresh', WP_REST_Server::CREATABLE, $vendor, 'papelito_vendor_payment_rest_refresh' ),
		array( '/admin/vendors/(?P<id>\d+)/payment-gateways', WP_REST_Server::READABLE, $admin, 'papelito_admin_vendor_payment_rest_get' ),
		array( '/admin/vendors/(?P<id>\d+)/mercadopago/identity', WP_REST_Server::CREATABLE, $admin, 'papelito_admin_vendor_mercadopago_identity' ),
	);

	foreach ( $routes as $route ) {
		register_rest_route(
			'papelito/v1',
			$route[0],
			array(
				'methods'             => $route[1],
				'permission_callback' => $route[2],
				'callback'            => $route[3],
			)
		);
	}
}

add_action( 'rest_api_init', 'papelito_vendor_payment_rest_register_routes' );
