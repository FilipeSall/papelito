<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS', 30 );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE', 'Não foi possível concluir esta candidatura.' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE', 'Candidatura não encontrada.' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE', 'Esta candidatura não está pendente.' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION', 'START TRANSACTION' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK', 'papelito_pre_account_application_purge_document' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_SWEEP_HOOK' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_SWEEP_HOOK', 'papelito_pre_account_applications_sweep' );
}

class PapelitoPreAccountTransactionException extends RuntimeException {}

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

/**
 * Autoriza uma candidatura pelo id interno, sem passar pelo `resume_token`.
 *
 * Existe para o tiquete de upload direto: guardar o token cru num transient deixaria o segredo em
 * claro em `wp_options`. O id sozinho nao autoriza nada — quem o obtem ja passou pelo token uma vez,
 * e a validade da candidatura e revalidada aqui do mesmo jeito.
 *
 * @param int $application_id Id interno da candidatura.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pre_account_application_authorize_by_id( int $application_id ): array|WP_Error {
	global $wpdb;
	$tables      = papelito_company_table_names();
	$application = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE id = %d", $application_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return papelito_pre_account_application_assert_open( is_array( $application ) ? $application : null );
}

function papelito_pre_account_application_authorize( string $token ): array|WP_Error {
	return papelito_pre_account_application_assert_open( papelito_pre_account_application_by_token( $token ) );
}

/**
 * Guarda comum de validade da candidatura.
 *
 * @param array<string,mixed>|null $application Linha carregada, ou null.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pre_account_application_assert_open( ?array $application ): array|WP_Error {
	if ( ! $application || empty( $application['resume_token_expires_at'] ) || strtotime( (string) $application['resume_token_expires_at'] ) < time() ) {
		return new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
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

function papelito_pre_account_application_admin_recipients(): array {
	$recipients = array();
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id > 0 && user_can( $user_id, 'papelito_manage_companies' ) ) {
			$recipients[] = $user_id;
		}
	}

	return $recipients;
}

function papelito_pre_account_application_notification_exists( int $recipient_id, int $application_id ): bool {
	if ( ! function_exists( 'papelito_notifications_table_name' ) ) {
		return false;
	}

	global $wpdb;
	$notification_id = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM ' . papelito_notifications_table_name() . ' WHERE user_id = %d AND type = %s AND dedupe_key = %s LIMIT 1',
			$recipient_id,
			PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING,
			'pre-account-application:' . $application_id
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	return (int) $notification_id > 0;
}

function papelito_pre_account_application_notify_pending( array $application ): bool {
	if ( ! function_exists( 'papelito_dispatch_notification' ) || ! defined( 'PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING' ) ) {
		return false;
	}

	$application_id = (int) $application['id'];
	$company_name   = papelito_pii_decrypt( (string) ( $application['legal_name_ciphertext'] ?? '' ) );
	$payload        = array(
		'applicationId' => papelito_pre_account_application_external_id( $application_id ),
		'source'        => 'pre_account',
		'companyName'   => is_string( $company_name ) && '' !== $company_name ? $company_name : 'Cadastro empresarial',
		'href'          => '/admin/users?preAccountApplication=' . rawurlencode( papelito_pre_account_application_external_id( $application_id ) ),
	);

	$recipients = papelito_pre_account_application_admin_recipients();
	if ( empty( $recipients ) ) {
		return false;
	}

	foreach ( $recipients as $recipient_id ) {
		if ( false === papelito_dispatch_notification( $recipient_id, PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING, $payload, 'pre-account-application:' . $application_id ) && ! papelito_pre_account_application_notification_exists( $recipient_id, $application_id ) ) {
			return false;
		}
	}

	return true;
}

function papelito_pre_account_application_backfill_pending_notifications(): int {
	global $wpdb;
	$tables = papelito_company_table_names();
	$applications = $wpdb->get_results(
		"SELECT id, legal_name_ciphertext FROM {$tables['pre_account_applications']} WHERE application_status = 'pending_manual_review' AND is_open = 1",
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$backfilled = 0;
	foreach ( is_array( $applications ) ? $applications : array() as $application ) {
		$backfilled += papelito_pre_account_application_notify_pending( $application ) ? 1 : 0;
	}

	return $backfilled;
}

function papelito_pre_account_application_identity( array $input ): array|WP_Error {
	$identity = array(
		'email'    => sanitize_email( (string) ( $input['email'] ?? '' ) ),
		// Normalizado antes de validar E de persistir: e este valor que segue para o cruzamento de
		// QSA e para o e-mail do candidato, entao nao pode guardar NBSP nem espaco duplicado.
		'name'     => papelito_normalize_unicode_spaces( sanitize_text_field( (string) ( $input['full_name'] ?? '' ) ) ),
		'phone'    => papelito_normalize_unicode_spaces( sanitize_text_field( (string) ( $input['phone'] ?? '' ) ) ),
		'cpf'      => papelito_normalize_cpf( (string) ( $input['cpf'] ?? '' ) ),
		'birth'    => sanitize_text_field( (string) ( $input['birth_date'] ?? '' ) ),
		'cnpj'     => papelito_normalize_cnpj( (string) ( $input['cnpj'] ?? '' ) ),
		'password' => (string) ( $input['password'] ?? '' ),
	);
	$errors = array();
	if ( ! is_email( $identity['email'] ) ) {
		$errors['email'] = array( 'Informe um e-mail válido.' );
	}
	$name_error = papelito_full_name_validation_error( $identity['name'] );
	if ( $name_error ) {
		$errors['full_name'] = array( $name_error );
	}
	$phone_error = papelito_phone_validation_error( $identity['phone'] );
	if ( $phone_error ) {
		$errors['phone'] = array( $phone_error );
	}
	if ( ! papelito_validate_cpf( $identity['cpf'] ) ) {
		$errors['cpf'] = array( 'Informe um CPF válido.' );
	}
	$birth_date_error = papelito_company_birth_date_validation_error( $identity['birth'] );
	if ( $birth_date_error ) {
		$errors['birth_date'] = array( $birth_date_error->get_error_message() );
	}
	if ( ! papelito_validate_cnpj( $identity['cnpj'] ) ) {
		$errors['cnpj'] = array( 'Informe um CNPJ válido.' );
	}
	if ( strlen( $identity['password'] ) < 8 ) {
		$errors['password'] = array( 'A senha precisa ter pelo menos 8 caracteres.' );
	}

	if ( empty( $errors ) ) {
		return $identity;
	}

	return new WP_Error(
		'papelito_pre_account_invalid_input',
		'Revise os dados informados.',
		array(
			'status' => 422,
			'errors' => $errors,
		)
	);
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
	// Mensagem deliberadamente neutra e idêntica nos três casos: distinguir "CNPJ já cadastrado"
	// de "e-mail já cadastrado" permitiria enumerar empresas e usuários.
	if ( papelito_company_find_by_cnpj( $identity['cnpj'] ) || email_exists( $identity['email'] ) || username_exists( $identity['email'] ) ) {
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
	$wpdb->query( PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_pre_account_unavailable', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 409 ) );
	}
	$application = papelito_pre_account_application_get( (int) $wpdb->insert_id );
	if ( ! $application ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_pre_account_persist_failed', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 500 ) );
	}
	if ( 'pending_manual_review' === (string) $application['application_status'] && ! papelito_pre_account_application_notify_pending( $application ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_pre_account_notification_failed', 'Não foi possível encaminhar a candidatura para análise.', array( 'status' => 500 ) );
	}
	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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
	return papelito_pre_account_application_upload_authorized( papelito_pre_account_application_authorize( $token ), $file );
}

/**
 * Recebe o documento de uma candidatura ja autorizada.
 *
 * Separado de `papelito_pre_account_application_upload()` para o upload direto poder autorizar pelo
 * id da candidatura, sem precisar do `resume_token` em claro.
 *
 * @param array<string,mixed>|WP_Error $application Candidatura autorizada.
 * @param array<string,mixed>          $file        Arquivo recebido.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pre_account_application_upload_authorized( array|WP_Error $application, array $file ): array|WP_Error {
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
	$wpdb->query( PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$locked = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE id = %d FOR UPDATE", (int) $application['id'] ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $locked ) || 'document_required' !== (string) $locked['application_status'] || empty( $locked['is_open'] ) || ! empty( $locked['document_storage_key'] ) ) {
			throw new DomainException( 'application_not_uploadable' );
		}

		$now     = current_time( 'mysql', true );
		$updated = $wpdb->update( $tables['pre_account_applications'], array( 'application_status' => 'pending_manual_review', 'document_storage_key' => $stored['key'], 'document_original_name' => $validated['original_name'], 'document_mime' => $validated['mime'], 'document_size' => $validated['size'], 'document_sha256' => $validated['sha256'], 'document_uploaded_at' => $now, 'updated_at' => $now ), array( 'id' => (int) $locked['id'], 'application_status' => 'document_required' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $updated ) {
			throw new PapelitoPreAccountTransactionException( 'application_update_failed' );
		}
		$locked['id']                 = (int) $locked['id'];
		$locked['application_status'] = 'pending_manual_review';
		if ( ! papelito_pre_account_application_notify_pending( $locked ) ) {
			throw new PapelitoPreAccountTransactionException( 'notification_failed' );
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( DomainException $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_company_document_discard_path( $stored['path'] );
		return new WP_Error( 'papelito_pre_account_upload_conflict', 'A candidatura foi atualizada. Atualize a página.', array( 'status' => 409 ) );
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_company_document_discard_path( $stored['path'] );
		return new WP_Error( 'papelito_pre_account_upload_failed', 'Não foi possível encaminhar o documento para análise. Tente novamente.', array( 'status' => 500 ) );
	}
	$updated_application = papelito_pre_account_application_get( (int) $application['id'] );
	return $updated_application ? papelito_pre_account_application_view( $updated_application ) : new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
}

/**
 * Apaga o arquivo privado de uma candidatura já decidida ou expirada.
 *
 * Espelha papelito_company_owner_application_purge_document(): em falha de unlink reagenda,
 * porque deixar o binário em disco é justamente o que a retenção precisa evitar.
 */
function papelito_pre_account_application_purge_document( int $application_id ): bool {
	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application || ! in_array( (string) $application['application_status'], array( 'approved', 'rejected', 'expired' ), true ) ) {
		return false;
	}

	$key = (string) ( $application['document_storage_key'] ?? '' );
	if ( '' === $key ) {
		return true;
	}

	$deleted = false;
	if ( papelito_company_document_key_is_valid( $key ) ) {
		$directory = papelito_company_documents_prepare_dir();
		if ( ! is_wp_error( $directory ) ) {
			$path    = trailingslashit( $directory ) . $key;
			$deleted = ! is_file( $path ) || unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	if ( ! $deleted ) {
		if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK, array( $application_id ) ) ) {
			wp_schedule_single_event( time() + ( 5 * MINUTE_IN_SECONDS ), PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK, array( $application_id ) );
		}

		return false;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->update(
		$tables['pre_account_applications'],
		array(
			'document_storage_key'   => null,
			'document_original_name' => null,
			'document_sha256'        => null,
			'document_deleted_at'    => current_time( 'mysql', true ),
			'updated_at'             => current_time( 'mysql', true ),
		),
		array( 'id' => $application_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return true;
}
add_action( PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK, 'papelito_pre_account_application_purge_document', 10, 1 );

/**
 * Zera os dados pessoais de uma candidatura vencida, preservando o rastro auditável.
 *
 * Sobra o que não é reversível nem identificável isoladamente (hashes, CNPJ, decisão,
 * IDs criados). As colunas cifradas são NOT NULL, então recebem string vazia, não NULL;
 * `password_hash = NULL` é o sentinela de "já purgada".
 */
function papelito_pre_account_application_purge_pii( int $application_id ): bool {
	papelito_pre_account_application_purge_document( $application_id );

	global $wpdb;
	$tables  = papelito_company_table_names();
	$updated = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'contact_email_ciphertext' => '',
			'full_name_ciphertext'     => '',
			'phone_ciphertext'         => '',
			'cpf_ciphertext'           => '',
			'birth_date_ciphertext'    => '',
			'address_ciphertext'       => '',
			'legal_name_ciphertext'    => null,
			'password_hash'            => null,
			'resume_token_hash'        => '',
			'evidence_json'            => null,
			'updated_at'               => current_time( 'mysql', true ),
		),
		array( 'id' => $application_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return 1 === $updated;
}

/**
 * Fecha candidaturas abertas vencidas e purga os dados pessoais das que passaram do TTL.
 *
 * @return array{expired:int,purged:int}
 */
function papelito_pre_account_applications_sweep(): array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$now    = current_time( 'mysql', true );

	$expired = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$tables['pre_account_applications']} SET application_status = %s, is_open = NULL, updated_at = %s WHERE is_open = 1 AND expires_at < %s",
			'expired',
			$now,
			$now
		)
	);

	$due = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id FROM {$tables['pre_account_applications']} WHERE password_hash IS NOT NULL AND expires_at < %s LIMIT 200",
			$now
		)
	);

	$purged = 0;
	foreach ( is_array( $due ) ? $due : array() as $id ) {
		$purged += papelito_pre_account_application_purge_pii( (int) $id ) ? 1 : 0;
	}

	return array(
		'expired' => $expired,
		'purged'  => $purged,
	);
}
add_action( PAPELITO_PRE_ACCOUNT_SWEEP_HOOK, 'papelito_pre_account_applications_sweep' );

if ( function_exists( 'add_action' ) ) {
	add_action( 'init', static function (): void {
		if ( ! wp_next_scheduled( PAPELITO_PRE_ACCOUNT_SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', PAPELITO_PRE_ACCOUNT_SWEEP_HOOK );
		}
	} );
}

function papelito_pre_account_application_admin_list( string $status = 'pending_manual_review' ): array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$allowed_statuses = array( 'document_required', 'pending_manual_review', 'approved', 'rejected' );
	if ( ! in_array( $status, $allowed_statuses, true ) ) {
		$status = 'pending_manual_review';
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, contact_email_ciphertext, full_name_ciphertext, legal_name_ciphertext, canonical_cnpj, application_status, review_path, document_uploaded_at, created_at FROM {$tables['pre_account_applications']} WHERE application_status = %s ORDER BY COALESCE(document_uploaded_at, created_at) ASC", $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array_map( static function ( array $row ): array {
		return array( 'applicationId' => papelito_pre_account_application_external_id( (int) $row['id'] ), 'email' => papelito_pii_decrypt( (string) $row['contact_email_ciphertext'] ) ?: null, 'fullName' => papelito_pii_decrypt( (string) $row['full_name_ciphertext'] ) ?: null, 'companyName' => papelito_pii_decrypt( (string) $row['legal_name_ciphertext'] ) ?: null, 'cnpj' => (string) $row['canonical_cnpj'], 'status' => (string) $row['application_status'], 'reviewPath' => $row['review_path'] ?? null, 'submittedAt' => $row['document_uploaded_at'] ?? null, 'createdAt' => (string) $row['created_at'] );
	}, is_array( $rows ) ? $rows : array() );
}

/**
 * Bloco de pessoa da tela administrativa, tolerante a falha de decifragem.
 *
 * @param array<string,string>|WP_Error $values Valores decifrados da candidatura.
 * @return array<string,mixed>
 */
function papelito_pre_account_application_admin_person( array|WP_Error $values ): array {
	if ( is_wp_error( $values ) ) {
		return array( 'userId' => null, 'fullName' => null, 'email' => null, 'cpf' => null, 'birthDate' => null, 'phone' => null );
	}

	return array(
		'userId'    => null,
		'fullName'  => $values['name'],
		'email'     => $values['email'],
		'cpf'       => $values['cpf'],
		'birthDate' => $values['birth'],
		'phone'     => $values['phone'],
	);
}

function papelito_pre_account_application_admin_detail( int $application_id ): array|WP_Error {
	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application ) {
		return new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$values  = papelito_pre_account_application_decrypt_values( $application );
	$address = is_wp_error( $values ) ? array() : json_decode( (string) $values['address'], true );
	$evidence = json_decode( (string) ( $application['evidence_json'] ?? '' ), true );
	$legal_name = papelito_pii_decrypt( (string) ( $application['legal_name_ciphertext'] ?? '' ) );

	return array(
		'application' => array(
			'applicationId'      => papelito_pre_account_application_external_id( $application_id ),
			'companyId'          => null,
			'attemptNumber'      => 1,
			'status'             => (string) $application['application_status'],
			'fileName'           => in_array( (string) $application['application_status'], array( 'document_required', 'pending_manual_review' ), true ) ? ( $application['document_original_name'] ?? null ) : null,
			'submittedAt'        => $application['document_uploaded_at'] ?? null,
			'decidedAt'          => $application['decided_at'] ?? null,
			'canUpload'          => 'document_required' === (string) $application['application_status'] && empty( $application['document_storage_key'] ),
			'canRestart'         => 'rejected' === (string) $application['application_status'],
			'documentMime'       => $application['document_mime'] ?? null,
			'documentSize'       => isset( $application['document_size'] ) ? (int) $application['document_size'] : null,
			'documentAvailable'  => 'pending_manual_review' === (string) $application['application_status'] && ! empty( $application['document_storage_key'] ),
			'documentPurgeStatus'=> empty( $application['document_storage_key'] ) && ! empty( $application['document_deleted_at'] ) ? 'deleted' : 'retained',
			'rejectionReason'    => $application['rejection_reason'] ?? null,
			'decidedByUserId'    => ! empty( $application['decided_by_user_id'] ) ? (int) $application['decided_by_user_id'] : null,
		),
		'person'      => papelito_pre_account_application_admin_person( $values ),
		'company'     => array(
			'id'               => null,
			'cnpj'             => (string) $application['canonical_cnpj'],
			'legalName'        => is_string( $legal_name ) ? $legal_name : null,
			'tradeName'        => null,
			'registryStatus'   => null,
			'ownershipStatus'  => null,
			'companyStatus'    => null,
			'providerSource'   => $application['provider_source'] ?? null,
			'providerCheckedAt'=> $application['provider_checked_at'] ?? null,
			'fiscalAddress'    => is_array( $address ) ? $address : array(),
		),
		'membership'  => null,
		'evidence'    => is_array( $evidence ) ? $evidence : array(),
	);
}

function papelito_pre_account_application_admin_document( int $application_id ) {
	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application ) {
		return new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}
	if ( 'pending_manual_review' !== (string) $application['application_status'] || empty( $application['document_storage_key'] ) ) {
		return new WP_Error( 'papelito_pre_account_document_unavailable', 'O documento não está mais disponível.', array( 'status' => 410 ) );
	}

	$key = (string) $application['document_storage_key'];
	if ( ! papelito_company_document_key_is_valid( $key ) ) {
		return new WP_Error( 'papelito_pre_account_document_invalid', 'Documento inválido.', array( 'status' => 500 ) );
	}
	$directory = papelito_company_documents_prepare_dir();
	if ( is_wp_error( $directory ) ) {
		return $directory;
	}
	$path = trailingslashit( $directory ) . $key;
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return new WP_Error( 'papelito_pre_account_document_missing', 'Documento não encontrado.', array( 'status' => 410 ) );
	}

	nocache_headers();
	header( 'Content-Type: ' . (string) $application['document_mime'] );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	header( 'Content-Disposition: inline; filename="' . str_replace( array( '"', "\r", "\n" ), '', (string) $application['document_original_name'] ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

/**
 * Decifra os campos necessários para provisionar a conta.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pre_account_application_decrypt_values( array $application ): array|WP_Error {
	$columns = array(
		'email'   => 'contact_email_ciphertext',
		'name'    => 'full_name_ciphertext',
		'phone'   => 'phone_ciphertext',
		'cpf'     => 'cpf_ciphertext',
		'birth'   => 'birth_date_ciphertext',
		'address' => 'address_ciphertext',
	);

	$values = array();
	foreach ( $columns as $key => $column ) {
		$value = papelito_pii_decrypt( (string) $application[ $column ] );
		if ( ! is_string( $value ) || '' === $value ) {
			return new WP_Error( 'papelito_pre_account_decrypt_failed', 'Não foi possível concluir a candidatura.', array( 'status' => 500 ) );
		}
		$values[ $key ] = $value;
	}

	return $values;
}

function papelito_pre_account_application_reject( array $application, int $actor_user_id, string $reason ): array|WP_Error {
	global $wpdb;
	$tables = papelito_company_table_names();
	$now    = current_time( 'mysql', true );
	$updated = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'application_status' => 'rejected',
			'is_open'            => null,
			'decided_by_user_id' => $actor_user_id,
			'decided_at'         => $now,
			'rejection_reason'   => $reason,
			'updated_at'         => $now,
		),
		array(
			'id'                 => (int) $application['id'],
			'application_status' => 'pending_manual_review',
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( 1 !== $updated ) {
		return new WP_Error( 'papelito_pre_account_decision_conflict', PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE, array( 'status' => 409 ) );
	}

	papelito_pre_account_application_purge_document( (int) $application['id'] );

	return papelito_pre_account_application_view( papelito_pre_account_application_get( (int) $application['id'] ) ?: $application );
}

function papelito_pre_account_application_send_decision_email( array $application ): void {
	$status = (string) $application['application_status'];
	if ( ! in_array( $status, array( 'approved', 'rejected' ), true ) ) {
		return;
	}

	$recipient = papelito_pii_decrypt( (string) ( $application['contact_email_ciphertext'] ?? '' ) );
	if ( ! is_string( $recipient ) || ! is_email( $recipient ) ) {
		return;
	}

	if ( 'approved' === $status ) {
		$subject = 'Cadastro empresarial aprovado - Papelito';
		$body    = "Seu cadastro empresarial foi aprovado.\n\nSua conta já foi criada. Enviamos, em outra mensagem, um link para confirmar este endereço de e-mail — ele libera as compras e passa a receber os documentos fiscais dos pedidos.";
	} else {
		$subject = 'Cadastro empresarial não aprovado - Papelito';
		$body    = "Não foi possível aprovar seu cadastro empresarial porque encontramos divergências nos dados analisados.\n\nEsta solicitação foi encerrada. Para realizar uma nova tentativa, será necessário iniciar novamente o processo de cadastro empresarial.";
	}

	wp_mail( $recipient, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
}

/**
 * Envia o link de confirmacao do e-mail principal da conta recem-provisionada.
 *
 * Best-effort e sempre depois do COMMIT: uma aprovacao revertida nao pode ter mandado e-mail, e
 * uma falha de SMTP nao pode desfazer a aprovacao. Sem o link o comprador nao fica preso — o
 * fluxo de reenvio em /auth/resend-verification continua disponivel.
 *
 * @param int $user_id Conta criada pela aprovacao.
 * @return void
 */
function papelito_pre_account_application_dispatch_verification( int $user_id ): void {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return;
	}

	$dispatched = papelito_auth_dispatch_verification_email( $user );

	if ( is_wp_error( $dispatched ) ) {
		error_log( 'papelito: pre-conta aprovada sem e-mail de confirmacao (' . $dispatched->get_error_code() . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Cria conta, perfil, empresa e membership a partir de uma candidatura aprovada.
 *
 * O `wp_user` nasce FORA da transação de propósito: `wp_insert_user` dispara `user_register`,
 * que terceiros (WooCommerce) escutam e podem gravar por fora do nosso controle transacional.
 * As tabelas próprias ficam dentro da transação; se ela falhar, o usuário é removido em
 * seguida. Sem isso, uma falha depois de `papelito_company_create` deixava empresa órfã e o
 * CNPJ travado para sempre em papelito_pre_account_application_prepare().
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_pre_account_application_approve( array $application, int $actor_user_id ): array|WP_Error {
	$application_id = (int) $application['id'];
	$values         = papelito_pre_account_application_decrypt_values( $application );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	$registry = papelito_company_validate_owner_registry( $values['cpf'], $values['birth'], (string) $application['canonical_cnpj'], $values['name'] );
	if ( is_wp_error( $registry ) ) {
		return $registry;
	}
	if ( 'document_required' === (string) $registry['review_path'] && empty( $application['document_storage_key'] ) ) {
		return new WP_Error( 'papelito_pre_account_document_required', 'A nova consulta exige documento antes da aprovação.', array( 'status' => 409 ) );
	}
	if ( email_exists( $values['email'] ) || username_exists( $values['email'] ) || papelito_company_find_by_cnpj( (string) $application['canonical_cnpj'] ) ) {
		return new WP_Error( 'papelito_pre_account_unavailable', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 409 ) );
	}

	$parts   = preg_split( '/\s+/', trim( $values['name'] ), 2 ) ?: array();
	$user_id = wp_insert_user(
		array(
			'user_login'   => $values['email'],
			'user_email'   => $values['email'],
			'user_pass'    => wp_generate_password( 32, true, true ),
			'first_name'   => $parts[0] ?? '',
			'last_name'    => $parts[1] ?? '',
			'display_name' => $values['name'],
			'role'         => 'customer',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	papelito_auth_mark_email_pending( $user_id );

	global $wpdb;
	$now     = current_time( 'mysql', true );
	$address = json_decode( $values['address'], true );
	$address = is_array( $address ) ? $address : array();

	$wpdb->query( PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// A senha foi escolhida na candidatura e guardada já com hash; wp_insert_user acabou de
	// gravar uma aleatória e é ela que precisa ser substituída.
	$wpdb->update( $wpdb->users, array( 'user_pass' => (string) $application['password_hash'] ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	clean_user_cache( $user_id );

	$profile = papelito_company_profile_upsert( $user_id, $values['cpf'], $values['birth'] );
	if ( is_wp_error( $profile ) ) {
		return papelito_pre_account_application_abort_approval( $user_id, $profile );
	}

	// O e-mail de faturamento nasce igual ao da conta, mas nao herda verificacao nenhuma: a conta
	// acabou de nascer `pending`. Quem confirmar o e-mail principal dispara `papelito_email_verified`
	// e `papelito_billing_email_sync_for_user()` confirma esta empresa em cascata.
	$company_id = papelito_company_create(
		(string) $application['canonical_cnpj'],
		array(
			'legal_name'         => (string) ( $registry['lookup']['legal_name'] ?? '' ),
			'trade_name'         => (string) ( $registry['lookup']['trade_name'] ?? '' ),
			'billing_email'      => papelito_normalize_email( (string) $values['email'] ),
			'billing_email_verified_at' => null,
			'phone'              => $values['phone'],
			'registry_status'    => 'active',
			'ownership_status'   => 'verified',
			'company_status'     => 'active',
			'owner_user_id'      => $user_id,
			'created_by_user_id' => $user_id,
			'fiscal_cep'         => (string) ( $address['cep'] ?? '' ),
			'fiscal_state'       => (string) ( $address['state'] ?? '' ),
			'fiscal_city'        => (string) ( $address['city'] ?? '' ),
			'fiscal_neighborhood'=> (string) ( $address['neighborhood'] ?? '' ),
			'fiscal_street'      => (string) ( $address['street'] ?? '' ),
			'fiscal_number'      => (string) ( $address['number'] ?? '' ),
			'fiscal_complement'  => (string) ( $address['complement'] ?? '' ),
		)
	);
	if ( is_wp_error( $company_id ) ) {
		return papelito_pre_account_application_abort_approval( $user_id, $company_id );
	}

	$member_id = papelito_company_member_upsert(
		$company_id,
		$user_id,
		array(
			'member_role'         => 'owner',
			'member_status'       => 'active',
			'membership_origin'   => 'owner_candidate',
			'approved_by_user_id' => $actor_user_id,
			'approved_at'         => $now,
		)
	);
	if ( is_wp_error( $member_id ) ) {
		return papelito_pre_account_application_abort_approval( $user_id, $member_id );
	}

	papelito_company_onboarding_upsert( $user_id, 'create_company', (string) $application['canonical_cnpj'], 'pending_onboarding' );
	papelito_company_onboarding_save_address( $user_id, (string) ( $address['cep'] ?? '' ), $address );
	papelito_company_onboarding_mark_completed( $user_id, $company_id, $member_id );

	$tables  = papelito_company_table_names();
	$decided = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'application_status'    => 'approved',
			'is_open'               => null,
			'decided_by_user_id'    => $actor_user_id,
			'decided_at'            => $now,
			'created_user_id'       => $user_id,
			'created_company_id'    => $company_id,
			'created_membership_id' => $member_id,
			'updated_at'            => $now,
		),
		array(
			'id'                 => $application_id,
			'application_status' => 'pending_manual_review',
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Guarda de concorrência: outro administrador decidiu esta candidatura enquanto
	// provisionávamos. Desfaz tudo em vez de deixar duas contas para o mesmo CNPJ.
	if ( 1 !== $decided ) {
		return papelito_pre_account_application_abort_approval(
			$user_id,
			new WP_Error( 'papelito_pre_account_decision_conflict', PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE, array( 'status' => 409 ) )
		);
	}

	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	clean_user_cache( $user_id );
	update_user_meta( $user_id, 'papelito_account_state', 'active' );
	papelito_pre_account_application_purge_document( $application_id );

	// Depois do COMMIT de proposito: aprovacao revertida nao pode ter mandado e-mail. Best-effort —
	// falha de SMTP nao desfaz a aprovacao, o usuario reobtem o link por /auth/resend-verification.
	papelito_pre_account_application_dispatch_verification( $user_id );

	return papelito_pre_account_application_view( papelito_pre_account_application_get( $application_id ) ?: $application );
}

/**
 * Desfaz um provisionamento parcial: rollback das tabelas próprias e remoção do wp_user.
 */
function papelito_pre_account_application_abort_approval( int $user_id, WP_Error $error ): WP_Error {
	global $wpdb;
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	wp_delete_user( $user_id );
	clean_user_cache( $user_id );

	return $error;
}

function papelito_pre_account_application_decide( int $application_id, int $actor_user_id, bool $approve, string $reason = '' ): array|WP_Error {
	$reason = trim( sanitize_textarea_field( $reason ) );
	if ( ! $approve && '' === $reason ) {
		return new WP_Error( 'papelito_pre_account_rejection_reason_required', 'Informe o motivo interno da reprovação.', array( 'status' => 422 ) );
	}

	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application || 'pending_manual_review' !== (string) $application['application_status'] || empty( $application['is_open'] ) ) {
		return new WP_Error( 'papelito_pre_account_decision_conflict', PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE, array( 'status' => 409 ) );
	}

	$result = $approve
		? papelito_pre_account_application_approve( $application, $actor_user_id )
		: papelito_pre_account_application_reject( $application, $actor_user_id, $reason );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$decided = papelito_pre_account_application_get( $application_id );
	if ( $decided ) {
		papelito_pre_account_application_send_decision_email( $decided );
	}

	return $result;
}
