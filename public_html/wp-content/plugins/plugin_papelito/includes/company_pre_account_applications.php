<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS', 30 );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE', 'Não foi possível concluir esta candidatura.' );
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

function papelito_pre_account_application_authorize( string $token ): array|WP_Error {
	$application = papelito_pre_account_application_by_token( $token );
	if ( ! $application || empty( $application['resume_token_expires_at'] ) || strtotime( (string) $application['resume_token_expires_at'] ) < time() ) {
		return new WP_Error( 'papelito_pre_account_application_not_found', 'Candidatura não encontrada.', array( 'status' => 404 ) );
	}
	return $application;
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

function papelito_pre_account_application_identity( array $input ): array|WP_Error {
	$identity = array(
		'email'    => sanitize_email( (string) ( $input['email'] ?? '' ) ),
		'name'     => sanitize_text_field( (string) ( $input['full_name'] ?? '' ) ),
		'phone'    => sanitize_text_field( (string) ( $input['phone'] ?? '' ) ),
		'cpf'      => papelito_normalize_cpf( (string) ( $input['cpf'] ?? '' ) ),
		'birth'    => sanitize_text_field( (string) ( $input['birth_date'] ?? '' ) ),
		'cnpj'     => papelito_normalize_cnpj( (string) ( $input['cnpj'] ?? '' ) ),
		'password' => (string) ( $input['password'] ?? '' ),
	);
	$is_valid = is_email( $identity['email'] )
		&& '' !== $identity['name']
		&& '' !== $identity['phone']
		&& papelito_validate_cpf( $identity['cpf'] )
		&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $identity['birth'] )
		&& false !== strtotime( $identity['birth'] )
		&& papelito_validate_cnpj( $identity['cnpj'] )
		&& strlen( $identity['password'] ) >= 8;

	return $is_valid ? $identity : new WP_Error( 'papelito_pre_account_invalid_input', 'Dados cadastrais inválidos.', array( 'status' => 422 ) );
}

function papelito_pre_account_application_address( array $input ): array|WP_Error {
	$address = array(
		'cep'          => preg_replace( '/\\D+/', '', (string) ( $input['cep'] ?? '' ) ) ?? '',
		'street'       => sanitize_text_field( (string) ( $input['street'] ?? '' ) ),
		'number'       => sanitize_text_field( (string) ( $input['number'] ?? '' ) ),
		'complement'   => sanitize_text_field( (string) ( $input['complement'] ?? '' ) ),
		'neighborhood' => sanitize_text_field( (string) ( $input['neighborhood'] ?? '' ) ),
		'city'         => sanitize_text_field( (string) ( $input['city'] ?? '' ) ),
		'state'        => strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ),
	);
	$is_valid = papelito_validate_cep_format( $address['cep'] )
		&& '' !== $address['street']
		&& '' !== $address['number']
		&& '' !== $address['neighborhood']
		&& '' !== $address['city']
		&& array_key_exists( $address['state'], papelito_brazilian_states() );

	return $is_valid ? $address : new WP_Error( 'papelito_pre_account_invalid_address', 'Endereço inválido.', array( 'status' => 422 ) );
}

function papelito_pre_account_application_seal( array $identity, array $address, string $legal_name ): array|WP_Error {
	$sealed = array(
		'email_hmac' => papelito_pii_hmac( strtolower( $identity['email'] ) ),
		'cpf_hmac'   => papelito_cpf_hmac( $identity['cpf'] ),
	);
	$plaintext = array(
		'email'      => $identity['email'],
		'name'       => $identity['name'],
		'phone'      => $identity['phone'],
		'cpf'        => $identity['cpf'],
		'birth'      => $identity['birth'],
		'address'    => wp_json_encode( $address ),
		'legal_name' => $legal_name,
	);
	foreach ( $plaintext as $key => $value ) {
		$sealed[ $key ] = papelito_pii_encrypt( $value );
		if ( is_wp_error( $sealed[ $key ] ) ) {
			return $sealed[ $key ];
		}
	}
	if ( is_wp_error( $sealed['email_hmac'] ) ) {
		return $sealed['email_hmac'];
	}

	return is_wp_error( $sealed['cpf_hmac'] ) ? $sealed['cpf_hmac'] : $sealed;
}

function papelito_pre_account_application_prepare( array $input ): array|WP_Error {
	$identity = papelito_pre_account_application_identity( $input );
	if ( is_wp_error( $identity ) ) {
		return $identity;
	}
	if ( papelito_company_find_by_cnpj( $identity['cnpj'] ) ) {
		return new WP_Error( 'papelito_pre_account_unavailable', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 409 ) );
	}
	$address = papelito_pre_account_application_address( $input );
	if ( is_wp_error( $address ) ) {
		return $address;
	}
	$validated = papelito_company_validate_owner_registry( $identity['cpf'], $identity['birth'], $identity['cnpj'], $identity['name'] );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}
	$sealed = papelito_pre_account_application_seal( $identity, $address, (string) ( $validated['lookup']['legal_name'] ?? '' ) );

	return is_wp_error( $sealed ) ? $sealed : array(
		'identity'  => $identity,
		'validated' => $validated,
		'sealed'    => $sealed,
	);
}

function papelito_pre_account_application_persist( array $prepared ): array|WP_Error {
	$identity   = $prepared['identity'];
	$validated  = $prepared['validated'];
	$sealed     = $prepared['sealed'];
	$token      = papelito_pre_account_application_new_token();
	$now        = current_time( 'mysql', true );
	$expires_at = papelito_pre_account_application_expires_at();
	$path       = (string) $validated['review_path'];

	global $wpdb;
	$tables   = papelito_company_table_names();
	$inserted = $wpdb->insert(
		$tables['pre_account_applications'],
		array(
			'contact_email_hmac'       => $sealed['email_hmac'],
			'contact_email_ciphertext' => $sealed['email'],
			'full_name_ciphertext'     => $sealed['name'],
			'phone_ciphertext'         => $sealed['phone'],
			'cpf_hmac'                 => $sealed['cpf_hmac'],
			'cpf_ciphertext'           => $sealed['cpf'],
			'birth_date_ciphertext'    => $sealed['birth'],
			'address_ciphertext'       => $sealed['address'],
			'password_hash'            => wp_hash_password( $identity['password'] ),
			'canonical_cnpj'           => $identity['cnpj'],
			'legal_name_ciphertext'    => $sealed['legal_name'],
			'review_path'              => $path,
			'application_status'       => 'document_required' === $path ? 'document_required' : 'pending_manual_review',
			'is_open'                  => 1,
			'resume_token_hash'        => papelito_pre_account_application_token_hash( $token ),
			'resume_token_expires_at'  => $expires_at,
			'evidence_json'            => wp_json_encode( papelito_company_owner_application_safe_evidence( $validated['evidence'] ) ),
			'provider_source'          => sanitize_key( (string) ( $validated['lookup']['source'] ?? '' ) ),
			'provider_checked_at'      => $now,
			'provider_data_hash'       => (string) ( $validated['evidence']['hash'] ?? '' ),
			'expires_at'               => $expires_at,
			'created_at'               => $now,
			'updated_at'               => $now,
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $inserted ) {
		return new WP_Error( 'papelito_pre_account_unavailable', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 409 ) );
	}
	$application = papelito_pre_account_application_get( (int) $wpdb->insert_id );
	if ( ! $application ) {
		return new WP_Error( 'papelito_pre_account_persist_failed', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 500 ) );
	}

	return array(
		'application'  => papelito_pre_account_application_view( $application ),
		'resume_token' => $token,
	);
}

function papelito_pre_account_application_create( array $input ): array|WP_Error {
	$prepared = papelito_pre_account_application_prepare( $input );

	return is_wp_error( $prepared ) ? $prepared : papelito_pre_account_application_persist( $prepared );
}

function papelito_pre_account_application_upload( string $token, array $file ): array|WP_Error {
	$application = papelito_pre_account_application_authorize( $token );
	if ( is_wp_error( $application ) ) {
		return $application;
	}
	if ( 'document_required' !== (string) $application['application_status'] || empty( $application['is_open'] ) || ! empty( $application['document_storage_key'] ) ) {
		return new WP_Error( 'papelito_pre_account_upload_not_allowed', 'Esta candidatura não aceita um novo documento.', array( 'status' => 409 ) );
	}
	$validated = papelito_company_document_validate_upload( $file );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}
	$stored = papelito_company_document_store( $file, $validated );
	if ( is_wp_error( $stored ) ) {
		return $stored;
	}
	global $wpdb;
	$tables = papelito_company_table_names();
	$now = current_time( 'mysql', true );
	$updated = $wpdb->update( $tables['pre_account_applications'], array( 'application_status' => 'pending_manual_review', 'document_storage_key' => $stored['key'], 'document_original_name' => $validated['original_name'], 'document_mime' => $validated['mime'], 'document_size' => $validated['size'], 'document_sha256' => $validated['sha256'], 'document_uploaded_at' => $now, 'updated_at' => $now ), array( 'id' => (int) $application['id'], 'application_status' => 'document_required' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( 1 !== $updated ) {
		papelito_company_document_discard_path( $stored['path'] );
		return new WP_Error( 'papelito_pre_account_upload_conflict', 'A candidatura foi atualizada. Atualize a página.', array( 'status' => 409 ) );
	}
	$updated_application = papelito_pre_account_application_get( (int) $application['id'] );
	return $updated_application ? papelito_pre_account_application_view( $updated_application ) : new WP_Error( 'papelito_pre_account_application_not_found', 'Candidatura não encontrada.', array( 'status' => 404 ) );
}

function papelito_pre_account_application_admin_list( string $status = 'pending_manual_review' ): array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, contact_email_ciphertext, full_name_ciphertext, canonical_cnpj, application_status, review_path, document_uploaded_at, created_at FROM {$tables['pre_account_applications']} WHERE application_status = %s ORDER BY COALESCE(document_uploaded_at, created_at) ASC", $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array_map( static function ( array $row ): array {
		return array( 'applicationId' => papelito_pre_account_application_external_id( (int) $row['id'] ), 'email' => papelito_pii_decrypt( (string) $row['contact_email_ciphertext'] ) ?: null, 'fullName' => papelito_pii_decrypt( (string) $row['full_name_ciphertext'] ) ?: null, 'cnpj' => (string) $row['canonical_cnpj'], 'status' => (string) $row['application_status'], 'reviewPath' => $row['review_path'] ?? null, 'submittedAt' => $row['document_uploaded_at'] ?? null, 'createdAt' => (string) $row['created_at'] );
	}, is_array( $rows ) ? $rows : array() );
}

function papelito_pre_account_application_decide( int $application_id, int $actor_user_id, bool $approve, string $reason = '' ): array|WP_Error {
	$reason = trim( sanitize_textarea_field( $reason ) );
	if ( ! $approve && '' === $reason ) return new WP_Error( 'papelito_pre_account_rejection_reason_required', 'Informe o motivo interno da reprovação.', array( 'status' => 422 ) );
	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application || 'pending_manual_review' !== (string) $application['application_status'] || empty( $application['is_open'] ) ) return new WP_Error( 'papelito_pre_account_decision_conflict', 'Esta candidatura não está pendente.', array( 'status' => 409 ) );
	if ( ! $approve ) {
		global $wpdb;
		$tables = papelito_company_table_names();
		$wpdb->update( $tables['pre_account_applications'], array( 'application_status' => 'rejected', 'is_open' => null, 'decided_by_user_id' => $actor_user_id, 'decided_at' => current_time( 'mysql', true ), 'rejection_reason' => $reason, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $application_id, 'application_status' => 'pending_manual_review' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return papelito_pre_account_application_view( papelito_pre_account_application_get( $application_id ) ?: $application );
	}
	$values = array();
	foreach ( array( 'email' => 'contact_email_ciphertext', 'name' => 'full_name_ciphertext', 'phone' => 'phone_ciphertext', 'cpf' => 'cpf_ciphertext', 'birth' => 'birth_date_ciphertext', 'address' => 'address_ciphertext' ) as $key => $column ) {
		$value = papelito_pii_decrypt( (string) $application[ $column ] );
		if ( ! is_string( $value ) || '' === $value ) return new WP_Error( 'papelito_pre_account_decrypt_failed', 'Não foi possível concluir a candidatura.', array( 'status' => 500 ) );
		$values[ $key ] = $value;
	}
	$registry = papelito_company_validate_owner_registry( $values['cpf'], $values['birth'], (string) $application['canonical_cnpj'], $values['name'] );
	if ( is_wp_error( $registry ) ) return $registry;
	if ( 'document_required' === (string) $registry['review_path'] && empty( $application['document_storage_key'] ) ) return new WP_Error( 'papelito_pre_account_document_required', 'A nova consulta exige documento antes da aprovação.', array( 'status' => 409 ) );
	if ( email_exists( $values['email'] ) || username_exists( $values['email'] ) || papelito_company_find_by_cnpj( (string) $application['canonical_cnpj'] ) ) return new WP_Error( 'papelito_pre_account_unavailable', 'Não foi possível concluir esta candidatura.', array( 'status' => 409 ) );
	$parts = preg_split( '/\\s+/', trim( $values['name'] ), 2 ) ?: array();
	$user_id = wp_insert_user( array( 'user_login' => $values['email'], 'user_email' => $values['email'], 'user_pass' => wp_generate_password( 32, true, true ), 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'display_name' => $values['name'], 'role' => 'customer' ) );
	if ( is_wp_error( $user_id ) ) return $user_id;
	global $wpdb;
	$wpdb->update( $wpdb->users, array( 'user_pass' => (string) $application['password_hash'] ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	clean_user_cache( $user_id );
	$profile = papelito_company_profile_upsert( $user_id, $values['cpf'], $values['birth'] );
	if ( is_wp_error( $profile ) ) { wp_delete_user( $user_id ); return $profile; }
	$address = json_decode( $values['address'], true );
	$company_id = papelito_company_create( (string) $application['canonical_cnpj'], array( 'legal_name' => (string) ( $registry['lookup']['legal_name'] ?? '' ), 'trade_name' => (string) ( $registry['lookup']['trade_name'] ?? '' ), 'billing_email' => $values['email'], 'phone' => $values['phone'], 'registry_status' => 'active', 'ownership_status' => 'verified', 'company_status' => 'active', 'owner_user_id' => $user_id, 'created_by_user_id' => $user_id ) );
	if ( is_wp_error( $company_id ) ) { wp_delete_user( $user_id ); return $company_id; }
	$member_id = papelito_company_member_upsert( $company_id, $user_id, array( 'member_role' => 'owner', 'member_status' => 'active', 'membership_origin' => 'owner_candidate', 'approved_by_user_id' => $actor_user_id, 'approved_at' => current_time( 'mysql', true ) ) );
	if ( is_wp_error( $member_id ) ) { wp_delete_user( $user_id ); return $member_id; }
	papelito_company_onboarding_upsert( $user_id, 'create_company', (string) $application['canonical_cnpj'], 'pending_onboarding' );
	papelito_company_onboarding_save_address( $user_id, (string) ( $address['cep'] ?? '' ), is_array( $address ) ? $address : array() );
	papelito_company_onboarding_mark_completed( $user_id, $company_id, $member_id );
	update_user_meta( $user_id, 'papelito_account_state', 'active' );
	$tables = papelito_company_table_names();
	$wpdb->update( $tables['pre_account_applications'], array( 'application_status' => 'approved', 'is_open' => null, 'decided_by_user_id' => $actor_user_id, 'decided_at' => current_time( 'mysql', true ), 'created_user_id' => $user_id, 'created_company_id' => $company_id, 'created_membership_id' => $member_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $application_id, 'application_status' => 'pending_manual_review' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return papelito_pre_account_application_view( papelito_pre_account_application_get( $application_id ) ?: $application );
}
