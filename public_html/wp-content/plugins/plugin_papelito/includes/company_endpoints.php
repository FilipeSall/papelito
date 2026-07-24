<?php

defined( 'ABSPATH' ) || exit;

function papelito_company_require_authenticated_user() {
	$user_id = get_current_user_id();
	return $user_id > 0 ? $user_id : new WP_Error( 'papelito_b2b_not_authenticated', 'Autenticação necessária.', array( 'status' => 401 ) );
}

function papelito_company_validate_owner_input( array $data ): array|WP_Error {
	$required = array( 'cpf', 'birth_date', 'cnpj' );
	foreach ( $required as $field ) { if ( empty( $data[ $field ] ) ) { return new WP_Error( 'papelito_b2b_missing_' . $field, 'Dados cadastrais incompletos.', array( 'status' => 422 ) ); } }
	if ( ! papelito_validate_cpf( (string) $data['cpf'] ) || ! papelito_validate_cnpj( (string) $data['cnpj'] ) ) { return new WP_Error( 'papelito_b2b_invalid_document', 'Documento inválido.', array( 'status' => 422 ) ); }
	return array( 'cpf' => papelito_normalize_cpf( (string) $data['cpf'] ), 'birth_date' => sanitize_text_field( (string) $data['birth_date'] ), 'cnpj' => papelito_normalize_cnpj( (string) $data['cnpj'] ) );
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
		if ( '' !== $cep ) { update_user_meta( $user_id, 'cep', $cep ); }
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
		papelito_company_onboarding_mark_completed( $user_id, (int) $result['company_id'], (int) ( $result['membership_id'] ?? 0 ) );
		return new WP_REST_Response( array_merge( $result, papelito_company_context( $user_id ) ), 201 );
	} ) );

	register_rest_route( 'papelito/v1', '/companies/current/resubmit-owner', array( 'methods' => 'POST', 'permission_callback' => static fn() => get_current_user_id() > 0, 'callback' => static function () {
		$user_id = papelito_company_require_authenticated_user(); if ( is_wp_error( $user_id ) ) { return $user_id; }
		$result = papelito_company_resubmit_owner_candidate( $user_id ); return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	} ) );
} );
