<?php

defined( 'ABSPATH' ) || exit;

function papelito_company_require_authenticated_user() {
	$user_id = get_current_user_id();
	return $user_id > 0 ? $user_id : new WP_Error( 'papelito_b2b_not_authenticated', 'Autenticação necessária.', array( 'status' => 401 ) );
}

/**
 * Persiste o endereço informado no onboarding.
 *
 * O painel (`AccountCepNotice`, `/perfil/enderecos`) lê o CEP do customer WooCommerce — billing,
 * shipping e metaData — via WPGraphQL. Gravar só `usermeta.cep` fazia o aviso "conta sem CEP"
 * continuar aparecendo e a lista de endereços ficar vazia mesmo com o CEP preenchido.
 *
 * @param array<string,mixed> $data Payload do onboarding.
 * @return true|WP_Error
 */
function papelito_company_onboarding_save_address( int $user_id, string $cep, array $data ) {
	if ( '' === $cep ) {
		return true;
	}

	$street       = sanitize_text_field( (string) ( $data['street'] ?? '' ) );
	$number       = sanitize_text_field( (string) ( $data['number'] ?? '' ) );
	$complement   = sanitize_text_field( (string) ( $data['complement'] ?? '' ) );
	$neighborhood = sanitize_text_field( (string) ( $data['neighborhood'] ?? '' ) );
	$city         = sanitize_text_field( (string) ( $data['city'] ?? '' ) );
	$state        = strtoupper( sanitize_text_field( (string) ( $data['state'] ?? '' ) ) );

	if ( '' !== $state && ! array_key_exists( $state, papelito_brazilian_states() ) ) {
		return new WP_Error( 'papelito_b2b_invalid_state', 'Estado inválido.', array( 'status' => 422 ) );
	}

	update_user_meta( $user_id, 'cep', $cep );
	if ( '' !== $city ) { update_user_meta( $user_id, 'city', $city ); }
	if ( '' !== $state ) { update_user_meta( $user_id, 'state', $state ); }
	foreach ( array( 'cep' => $cep, 'street' => $street, 'number' => $number, 'complement' => $complement, 'neighborhood' => $neighborhood, 'city' => $city, 'state' => $state ) as $key => $value ) {
		update_user_meta( $user_id, 'papelito_b2b_onboarding_address_' . $key, $value );
	}

	if ( ! class_exists( 'WC_Customer' ) ) {
		return true;
	}

	try {
		$customer = new WC_Customer( $user_id );
	} catch ( Exception $error ) {
		return true;
	}

	$address_1 = '' !== $number ? trim( $street . ', ' . $number ) : $street;
	$address_2 = implode( ' • ', array_filter( array( $neighborhood, $complement ) ) );

	foreach ( array( 'billing', 'shipping' ) as $scope ) {
		$customer->{"set_{$scope}_postcode"}( $cep );
		$customer->{"set_{$scope}_country"}( 'BR' );
		if ( '' !== $address_1 ) { $customer->{"set_{$scope}_address_1"}( $address_1 ); }
		if ( '' !== $address_2 ) { $customer->{"set_{$scope}_address_2"}( $address_2 ); }
		if ( '' !== $city ) { $customer->{"set_{$scope}_city"}( $city ); }
		if ( '' !== $state ) { $customer->{"set_{$scope}_state"}( $state ); }
	}

	$customer->set_billing_email( (string) ( get_userdata( $user_id )->user_email ?? '' ) );
	$phone = (string) get_user_meta( $user_id, 'phone_number', true );
	if ( '' !== $phone ) { $customer->set_billing_phone( $phone ); }

	$customer->save();

	return true;
}

function papelito_company_validate_owner_input( array $data ): array|WP_Error {
	$required = array( 'cpf', 'birth_date', 'cnpj', 'full_name', 'phone', 'cep', 'street', 'number', 'neighborhood', 'city', 'state' );
	foreach ( $required as $field ) { if ( empty( $data[ $field ] ) ) { return new WP_Error( 'papelito_b2b_missing_' . $field, 'Dados cadastrais incompletos.', array( 'status' => 422 ) ); } }
	if ( ! papelito_validate_cpf( (string) $data['cpf'] ) || ! papelito_validate_cnpj( (string) $data['cnpj'] ) ) { return new WP_Error( 'papelito_b2b_invalid_document', 'Documento inválido.', array( 'status' => 422 ) ); }
	$cep = preg_replace( '/\D+/', '', (string) $data['cep'] ) ?? '';
	if ( ! papelito_validate_cep_format( $cep ) || ! array_key_exists( strtoupper( (string) $data['state'] ), papelito_brazilian_states() ) ) { return new WP_Error( 'papelito_b2b_invalid_address', 'Endereço inválido.', array( 'status' => 422 ) ); }
	$phone = function_exists( 'papelito_auth_normalize_phone' ) ? papelito_auth_normalize_phone( (string) $data['phone'] ) : preg_replace( '/\D+/', '', (string) $data['phone'] );
	if ( ! is_string( $phone ) || ! in_array( strlen( $phone ), array( 10, 11 ), true ) ) { return new WP_Error( 'papelito_b2b_invalid_phone', 'Telefone inválido.', array( 'status' => 422 ) ); }
	return array( 'cpf' => papelito_normalize_cpf( (string) $data['cpf'] ), 'birth_date' => sanitize_text_field( (string) $data['birth_date'] ), 'cnpj' => papelito_normalize_cnpj( (string) $data['cnpj'] ), 'full_name' => sanitize_text_field( (string) $data['full_name'] ), 'phone' => $phone, 'cep' => $cep, 'street' => sanitize_text_field( (string) $data['street'] ), 'number' => sanitize_text_field( (string) $data['number'] ), 'complement' => sanitize_text_field( (string) ( $data['complement'] ?? '' ) ), 'neighborhood' => sanitize_text_field( (string) $data['neighborhood'] ), 'city' => sanitize_text_field( (string) $data['city'] ), 'state' => strtoupper( sanitize_text_field( (string) $data['state'] ) ) );
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'papelito/v1', '/companies/validate-cnpj', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static function ( WP_REST_Request $request ) {
		$data = (array) $request->get_json_params();
		$cnpj = isset( $data['cnpj'] ) ? (string) $data['cnpj'] : '';
		if ( ! papelito_validate_cnpj( $cnpj ) ) { return new WP_Error( 'papelito_b2b_invalid_cnpj', 'CNPJ inválido.', array( 'status' => 422 ) ); }
		$lookup = papelito_cnpj_lookup( $cnpj );
		return new WP_REST_Response( array( 'status' => $lookup['status'], 'legalName' => $lookup['legal_name'] ?? null, 'tradeName' => $lookup['trade_name'] ?? null ), 200 );
	} ) );

	register_rest_route( 'papelito/v1', '/onboarding/customer-profile', array( 'methods' => 'POST', 'permission_callback' => static fn() => get_current_user_id() > 0, 'callback' => static function ( WP_REST_Request $request ) {
		$writes = papelito_b2b_require_company_writes(); if ( is_wp_error( $writes ) ) { return $writes; }
		$user_id = papelito_company_require_authenticated_user(); if ( is_wp_error( $user_id ) ) { return $user_id; }
		$data = (array) $request->get_json_params();
		$cep  = preg_replace( '/\D+/', '', (string) ( $data['cep'] ?? '' ) ) ?? '';
		if ( '' !== $cep && ! papelito_validate_cep_format( $cep ) ) { return new WP_Error( 'papelito_b2b_invalid_cep', 'CEP inválido.', array( 'status' => 422 ) ); }
		$result = papelito_company_profile_upsert( $user_id, (string) ( $data['cpf'] ?? '' ), (string) ( $data['birth_date'] ?? '' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		// O endereço precisa ser persistido ANTES de o contexto ser recalculado: o painel lê o CEP
		// do customer WooCommerce (billing/shipping), não só do usermeta.
		$address = papelito_company_onboarding_save_address( $user_id, $cep, $data );
		if ( is_wp_error( $address ) ) { return $address; }
		return new WP_REST_Response( papelito_company_context( $user_id ), 200 );
	} ) );

	register_rest_route( 'papelito/v1', '/companies', array( 'methods' => 'POST', 'permission_callback' => static fn() => get_current_user_id() > 0, 'callback' => static function ( WP_REST_Request $request ) {
		if ( ! papelito_b2b_company_model_enabled() ) { return new WP_Error( 'papelito_b2b_company_rollout_disabled', 'Cadastro empresarial temporariamente indisponível.', array( 'status' => 503 ) ); }
		$writes = papelito_b2b_require_company_writes(); if ( is_wp_error( $writes ) ) { return $writes; }
		$user_id = papelito_company_require_authenticated_user(); if ( is_wp_error( $user_id ) ) { return $user_id; }
		$input = papelito_company_validate_owner_input( (array) $request->get_json_params() ); if ( is_wp_error( $input ) ) { return $input; }
		$result = papelito_company_create_owner_candidate( $user_id, $input );
		if ( is_wp_error( $result ) && 'papelito_company_cnpj_exists' === $result->get_error_code() ) { return new WP_Error( 'papelito_b2b_company_unavailable', 'Não foi possível concluir esta candidatura.', array( 'status' => 409 ) ); }
		if ( is_wp_error( $result ) ) { return $result; }
		update_user_meta( $user_id, 'phone_number', (string) $input['phone'] );
		$address = papelito_company_onboarding_save_address( $user_id, (string) $input['cep'], $input );
		if ( is_wp_error( $address ) ) { return $address; }
		papelito_company_onboarding_mark_completed( $user_id, (int) $result['company_id'], (int) ( $result['membership_id'] ?? 0 ) );
		return new WP_REST_Response( array_merge( $result, papelito_company_context( $user_id ) ), 201 );
	} ) );

	register_rest_route( 'papelito/v1', '/companies/current/resubmit-owner', array( 'methods' => 'POST', 'permission_callback' => static fn() => get_current_user_id() > 0, 'callback' => static function () {
		$user_id = papelito_company_require_authenticated_user(); if ( is_wp_error( $user_id ) ) { return $user_id; }
		$result = papelito_company_resubmit_owner_candidate( $user_id ); return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	} ) );
} );
