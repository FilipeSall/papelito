<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS', 30 );
}

function papelito_pre_account_application_external_id( int $application_id ): string {
	return 'pre:' . $application_id;
}

function papelito_pre_account_application_new_token(): string {
	return bin2hex( random_bytes( 32 ) );
}

function papelito_pre_account_application_token_hash( string $token ): string {
	return hash( 'sha256', $token );
}

function papelito_pre_account_application_expires_at(): string {
	return gmdate( 'Y-m-d H:i:s', time() + ( PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS * DAY_IN_SECONDS ) );
}

function papelito_pre_account_application_get( int $application_id ): ?array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE id = %d", $application_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
}

function papelito_pre_account_application_by_token( string $token ): ?array {
	if ( '' === $token ) {
		return null;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE resume_token_hash = %s", papelito_pre_account_application_token_hash( $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
}

function papelito_pre_account_application_view( array $application ): array {
	return array(
		'applicationId' => papelito_pre_account_application_external_id( (int) $application['id'] ),
		'status'        => (string) $application['application_status'],
		'reviewPath'    => $application['review_path'] ?? null,
		'canUpload'     => 'document_required' === (string) $application['application_status'],
		'expiresAt'     => $application['expires_at'] ?? null,
	);
}

function papelito_pre_account_application_create( array $input ): array|WP_Error {
	$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$name  = sanitize_text_field( (string) ( $input['full_name'] ?? '' ) );
	$phone = sanitize_text_field( (string) ( $input['phone'] ?? '' ) );
	$cpf   = papelito_normalize_cpf( (string) ( $input['cpf'] ?? '' ) );
	$birth = sanitize_text_field( (string) ( $input['birth_date'] ?? '' ) );
	$cnpj  = papelito_normalize_cnpj( (string) ( $input['cnpj'] ?? '' ) );
	$password = (string) ( $input['password'] ?? '' );
	if ( ! is_email( $email ) || '' === $name || '' === $phone || ! papelito_validate_cpf( $cpf ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birth ) || false === strtotime( $birth ) || ! papelito_validate_cnpj( $cnpj ) || strlen( $password ) < 8 ) {
		return new WP_Error( 'papelito_pre_account_invalid_input', 'Dados cadastrais inválidos.', array( 'status' => 422 ) );
	}
	if ( papelito_company_find_by_cnpj( $cnpj ) ) {
		return new WP_Error( 'papelito_pre_account_unavailable', 'Não foi possível concluir esta candidatura.', array( 'status' => 409 ) );
	}
	$validated = papelito_company_validate_owner_registry( $cpf, $birth, $cnpj, $name );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}
	$email_hmac = papelito_pii_hmac( strtolower( $email ) );
	$cpf_hmac   = papelito_cpf_hmac( $cpf );
	$encrypted  = array();
	foreach ( array( 'email' => $email, 'name' => $name, 'phone' => $phone, 'cpf' => $cpf, 'birth' => $birth, 'legal_name' => (string) ( $validated['lookup']['legal_name'] ?? '' ) ) as $key => $value ) {
		$encrypted[ $key ] = papelito_pii_encrypt( $value );
		if ( is_wp_error( $encrypted[ $key ] ) ) {
			return $encrypted[ $key ];
		}
	}
	if ( is_wp_error( $email_hmac ) || is_wp_error( $cpf_hmac ) ) {
		return is_wp_error( $email_hmac ) ? $email_hmac : $cpf_hmac;
	}
	$token = papelito_pre_account_application_new_token();
	$now   = current_time( 'mysql', true );
	$path  = (string) $validated['review_path'];
	$status = 'document_required' === $path ? 'document_required' : 'pending_manual_review';
	global $wpdb;
	$tables = papelito_company_table_names();
	$inserted = $wpdb->insert(
		$tables['pre_account_applications'],
		array(
			'contact_email_hmac'       => $email_hmac,
			'contact_email_ciphertext' => $encrypted['email'],
			'full_name_ciphertext'      => $encrypted['name'],
			'phone_ciphertext'          => $encrypted['phone'],
			'cpf_hmac'                  => $cpf_hmac,
			'cpf_ciphertext'            => $encrypted['cpf'],
			'birth_date_ciphertext'     => $encrypted['birth'],
			'password_hash'             => wp_hash_password( $password ),
			'canonical_cnpj'            => $cnpj,
			'legal_name_ciphertext'     => $encrypted['legal_name'],
			'review_path'               => $path,
			'application_status'         => $status,
			'is_open'                   => 1,
			'resume_token_hash'         => papelito_pre_account_application_token_hash( $token ),
			'resume_token_expires_at'   => papelito_pre_account_application_expires_at(),
			'evidence_json'             => wp_json_encode( papelito_company_owner_application_safe_evidence( $validated['evidence'] ) ),
			'provider_source'           => sanitize_key( (string) ( $validated['lookup']['source'] ?? '' ) ),
			'provider_checked_at'       => $now,
			'provider_data_hash'        => (string) ( $validated['evidence']['hash'] ?? '' ),
			'expires_at'                => papelito_pre_account_application_expires_at(),
			'created_at'                => $now,
			'updated_at'                => $now,
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $inserted ) {
		return new WP_Error( 'papelito_pre_account_unavailable', 'Não foi possível concluir esta candidatura.', array( 'status' => 409 ) );
	}
	$application = papelito_pre_account_application_get( (int) $wpdb->insert_id );
	return $application ? array( 'application' => papelito_pre_account_application_view( $application ), 'resume_token' => $token ) : new WP_Error( 'papelito_pre_account_persist_failed', 'Não foi possível concluir esta candidatura.', array( 'status' => 500 ) );
}
