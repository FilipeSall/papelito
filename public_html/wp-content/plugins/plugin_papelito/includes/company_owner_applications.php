<?php
/**
 * Candidaturas do primeiro owner e documentos privados de revisão.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_COMPANY_DOCUMENT_MAX_BYTES' ) ) {
	define( 'PAPELITO_COMPANY_DOCUMENT_MAX_BYTES', 10 * MB_IN_BYTES );
}

if ( ! defined( 'PAPELITO_COMPANY_DOCUMENT_PURGE_HOOK' ) ) {
	define( 'PAPELITO_COMPANY_DOCUMENT_PURGE_HOOK', 'papelito_company_document_purge_retry' );
}

/**
 * Limita a evidência persistida a indicadores sem PII.
 *
 * @param array<string,mixed> $evidence Evidência calculada em memória.
 * @return array<string,mixed>
 */
function papelito_company_owner_application_safe_evidence( array $evidence ): array {
	$allowed = array(
		'qsa_available',
		'qsa_sufficient',
		'cpf_mask_match',
		'name_match',
		'age_band_match',
		'partner_match',
		'mei_confirmed',
		'mei_name_match',
		'provider',
		'checked_at',
	);
	$safe    = array();

	foreach ( $allowed as $key ) {
		if ( array_key_exists( $key, $evidence ) && ( is_scalar( $evidence[ $key ] ) || null === $evidence[ $key ] ) ) {
			$safe[ $key ] = $evidence[ $key ];
		}
	}

	return $safe;
}

/**
 * Cria uma candidatura dentro da transação do chamador.
 *
 * @param array<string,mixed> $evidence Evidência segura do provider.
 * @return int|WP_Error
 */
function papelito_company_owner_application_create( int $company_id, int $user_id, string $status, array $evidence ) {
	if ( ! in_array( $status, array( 'document_required', 'auto_approved' ), true ) ) {
		return new WP_Error( 'papelito_owner_application_invalid_status', 'Estado de candidatura inválido.', array( 'status' => 422 ) );
	}

	global $wpdb;
	$tables  = papelito_company_table_names();
	$attempt = 1 + (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COALESCE(MAX(attempt_number), 0) FROM {$tables['owner_applications']} WHERE company_id = %d",
			$company_id
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$now     = current_time( 'mysql', true );
	$safe    = papelito_company_owner_application_safe_evidence( $evidence );
	$inserted = $wpdb->insert(
		$tables['owner_applications'],
		array(
			'company_id'        => $company_id,
			'user_id'           => $user_id,
			'attempt_number'    => $attempt,
			'application_status'=> $status,
			'is_open'           => 'document_required' === $status ? 1 : null,
			'evidence_json'     => wp_json_encode( $safe ),
			'provider_source'   => sanitize_key( (string) ( $evidence['provider'] ?? '' ) ),
			'provider_checked_at'=> current_time( 'mysql', true ),
			'provider_data_hash'=> sanitize_text_field( (string) ( $evidence['hash'] ?? '' ) ),
			'created_at'        => $now,
			'updated_at'        => $now,
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $inserted ) {
		$code = false !== stripos( (string) $wpdb->last_error, 'duplicate' )
			? 'papelito_owner_application_conflict'
			: 'papelito_owner_application_persist_failed';
		return new WP_Error( $code, 'Não foi possível criar a candidatura.', array( 'status' => 409 ) );
	}

	return (int) $wpdb->insert_id;
}

/**
 * @return array<string,mixed>|null
 */
function papelito_company_owner_application_get( int $application_id ): ?array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$tables['owner_applications']} WHERE id = %d",
			$application_id
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
}

/**
 * @return array<string,mixed>|null
 */
function papelito_company_owner_application_latest_for_user( int $user_id ): ?array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$tables['owner_applications']} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
			$user_id
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function papelito_company_owner_applications_for_user( int $user_id ): array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$tables['owner_applications']} WHERE user_id = %d ORDER BY id DESC",
			$user_id
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $rows ) ? $rows : array();
}

/**
 * @return array<int,array<string,mixed>>
 */
function papelito_company_owner_applications_for_company( int $company_id ): array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$tables['owner_applications']} WHERE company_id = %d ORDER BY attempt_number DESC",
			$company_id
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $rows ) ? $rows : array();
}

/**
 * Payload seguro para sessão e frontend customer.
 *
 * @param array<string,mixed> $application Candidatura persistida.
 * @return array<string,mixed>
 */
function papelito_company_owner_application_view( array $application ): array {
	$status = (string) $application['application_status'];

	return array(
		'applicationId' => (int) $application['id'],
		'companyId'     => (int) $application['company_id'],
		'attemptNumber' => (int) $application['attempt_number'],
		'status'        => $status,
		'fileName'      => in_array( $status, array( 'document_required', 'pending_manual_review' ), true )
			? ( $application['document_original_name'] ?? null )
			: null,
		'submittedAt'   => $application['document_uploaded_at'] ?? null,
		'decidedAt'     => $application['decided_at'] ?? null,
		'canUpload'     => 'document_required' === $status && empty( $application['document_storage_key'] ),
		'canRestart'    => 'rejected' === $status,
	);
}

/**
 * Política de arquivo privado da candidatura de titularidade.
 *
 * @return array<string,mixed>
 */
function papelito_company_document_spec(): array {
	return array(
		'code_prefix'       => 'papelito_company_document',
		'max_bytes'         => PAPELITO_COMPANY_DOCUMENT_MAX_BYTES,
		'formats'           => array( 'jpg', 'png', 'pdf' ),
		'fallback_basename' => 'documento',
	);
}

/**
 * Diretório absoluto e fora do webroot.
 */
function papelito_company_documents_dir(): string {
	return papelito_private_files_dir( 'PAPELITO_PRIVATE_COMPANY_DOCUMENTS_DIR', 'company-documents' );
}

/**
 * Prepara armazenamento privado sem aceitar fallback dentro do webroot.
 *
 * @return string|WP_Error
 */
function papelito_company_documents_prepare_dir() {
	return papelito_private_files_prepare_dir( papelito_company_documents_dir(), 'papelito_company_document' );
}

/**
 * @return array{extension:string,mime:string,size:int,sha256:string,original_name:string}|WP_Error
 */
function papelito_company_document_validate_upload( array $file ) {
	return papelito_private_file_validate_upload( $file, papelito_company_document_spec() );
}

/**
 * @param array{extension:string,mime:string,size:int,sha256:string,original_name:string} $validated Metadados validados.
 * @return array{key:string,path:string}|WP_Error
 */
function papelito_company_document_store( array $file, array $validated ) {
	$directory = papelito_company_documents_prepare_dir();
	if ( is_wp_error( $directory ) ) {
		return $directory;
	}

	return papelito_private_file_store( $file, $validated, $directory, 'papelito_company_document' );
}

/**
 * Remove um arquivo recém-gravado em caso de rollback/conflito.
 */
function papelito_company_document_discard_path( string $path ): void {
	papelito_private_file_discard_path( $path );
}

/**
 * Valida a storage key antes de qualquer leitura ou exclusão em disco.
 */
function papelito_company_document_key_is_valid( string $key ): bool {
	return papelito_private_file_key_is_valid( $key, papelito_company_document_spec()['formats'] );
}

/**
 * Recebe o documento único de uma candidatura.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_company_owner_application_upload( int $user_id, array $file, string $idempotency_key ) {
	$application = papelito_company_owner_application_latest_for_user( $user_id );
	if ( ! $application || 'document_required' !== (string) $application['application_status'] || empty( $application['is_open'] ) ) {
		return new WP_Error( 'papelito_owner_application_upload_not_allowed', 'Esta candidatura não aceita um novo documento.', array( 'status' => 409 ) );
	}

	$validated = papelito_company_document_validate_upload( $file );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	$request_hash = papelito_company_idempotency_request_hash(
		array(
			'application_id' => (int) $application['id'],
			'sha256'         => $validated['sha256'],
			'size'           => $validated['size'],
			'mime'           => $validated['mime'],
		)
	);
	$previous = papelito_company_idempotency_check( $user_id, 'owner_document_upload', $idempotency_key, $request_hash );
	if ( isset( $previous['error'] ) ) {
		return $previous['error'];
	}
	if ( $previous ) {
		$replayed = papelito_company_owner_application_get( (int) $previous['resource_id'] );
		return $replayed ? papelito_company_owner_application_view( $replayed ) : new WP_Error( 'papelito_owner_application_not_found', 'Candidatura não encontrada.', array( 'status' => 404 ) );
	}

	$stored = papelito_company_document_store( $file, $validated );
	if ( is_wp_error( $stored ) ) {
		return $stored;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$locked = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['owner_applications']} WHERE id = %d AND user_id = %d FOR UPDATE",
				(int) $application['id'],
				$user_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $locked ) || 'document_required' !== (string) $locked['application_status'] || empty( $locked['is_open'] ) || ! empty( $locked['document_storage_key'] ) ) {
			throw new RuntimeException( 'application_not_uploadable' );
		}

		$now = current_time( 'mysql', true );
		$updated = $wpdb->update(
			$tables['owner_applications'],
			array(
				'application_status'       => 'pending_manual_review',
				'document_storage_key'     => $stored['key'],
				'document_original_name'   => $validated['original_name'],
				'document_mime'            => $validated['mime'],
				'document_size'            => $validated['size'],
				'document_sha256'          => $validated['sha256'],
				'document_uploaded_at'     => $now,
				'document_purge_status'    => 'retained',
				'document_purge_error_code'=> null,
				'updated_at'               => $now,
			),
			array( 'id' => (int) $locked['id'] )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $updated ) {
			throw new RuntimeException( 'application_update_failed' );
		}

		$company_updated = papelito_company_update(
			(int) $locked['company_id'],
			array( 'ownership_status' => 'pending_manual_review', 'company_status' => 'onboarding' )
		);
		if ( is_wp_error( $company_updated ) ) {
			throw new RuntimeException( 'company_update_failed' );
		}

		$wpdb->update(
			$tables['onboarding'],
			array( 'status' => 'pending_manual_review', 'updated_at' => $now ),
			array( 'user_id' => $user_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_company_audit(
			(int) $locked['company_id'],
			$user_id,
			'owner_document_submitted',
			array( 'application_id' => (int) $locked['id'], 'attempt' => (int) $locked['attempt_number'] )
		);
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_company_document_discard_path( $stored['path'] );
		return new WP_Error( 'papelito_owner_application_upload_conflict', 'O documento já foi enviado ou a candidatura foi atualizada.', array( 'status' => 409 ) );
	}

	papelito_company_idempotency_store( $user_id, 'owner_document_upload', $idempotency_key, $request_hash, (int) $application['id'], 201 );
	if ( function_exists( 'papelito_company_notify_owner_application_pending' ) ) {
		papelito_company_notify_owner_application_pending( (int) $application['id'] );
	}

	$updated_application = papelito_company_owner_application_get( (int) $application['id'] );
	return $updated_application ? papelito_company_owner_application_view( $updated_application ) : new WP_Error( 'papelito_owner_application_not_found', 'Candidatura não encontrada.', array( 'status' => 404 ) );
}

function papelito_company_notify_owner_application_pending( int $application_id ): void {
	$application = papelito_company_owner_application_get( $application_id );
	if ( ! $application ) {
		return;
	}

	$company = papelito_company_get( (int) $application['company_id'] );
	$admins  = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
		)
	);
	foreach ( $admins as $admin_id ) {
		papelito_dispatch_notification(
			(int) $admin_id,
			PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING,
			array(
				'applicationId' => (int) $application['id'],
				'userId'        => (int) $application['user_id'],
				'companyId'     => (int) $application['company_id'],
				'companyName'   => (string) ( $company['legal_name'] ?? '' ),
				'href'          => '/admin/users/' . (int) $application['user_id'] . '?tab=company-review',
			),
			'owner-application:' . (int) $application['id']
		);
	}
}

function papelito_company_owner_application_send_decision_email( array $application ): void {
	$user = get_userdata( (int) $application['user_id'] );
	if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
		return;
	}

	$status = (string) $application['application_status'];
	if ( ! in_array( $status, array( 'approved', 'rejected' ), true ) ) {
		return;
	}

	$type = 'approved' === $status ? PAPELITO_NOTIF_COMPANY_OWNER_APPROVED : PAPELITO_NOTIF_COMPANY_OWNER_REJECTED;
	if ( ! papelito_claim_notification_email_dispatch( $user->ID, $type, 'owner-application:' . (int) $application['id'] ) ) {
		return;
	}

	if ( 'approved' === $status ) {
		$subject = 'Cadastro empresarial aprovado - Papelito';
		$body    = "Seu cadastro empresarial foi aprovado.\n\nVocê já pode acessar sua conta e realizar compras na Papelito.";
	} else {
		$subject = 'Cadastro empresarial não aprovado - Papelito';
		$body    = "Não foi possível aprovar seu cadastro empresarial porque encontramos divergências nos dados analisados.\n\nEsta solicitação foi encerrada. Para realizar uma nova tentativa, será necessário iniciar novamente o processo de cadastro empresarial.";
	}

	wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
}

/**
 * @return array<string,mixed>|WP_Error
 */
function papelito_company_owner_application_admin_detail( int $application_id ) {
	$application = papelito_company_owner_application_get( $application_id );
	if ( ! $application ) {
		return new WP_Error( 'papelito_owner_application_not_found', 'Candidatura não encontrada.', array( 'status' => 404 ) );
	}

	$company = papelito_company_get( (int) $application['company_id'] );
	$user    = get_userdata( (int) $application['user_id'] );
	$profile = papelito_company_profile_get( (int) $application['user_id'] );
	$member  = papelito_company_member_get( (int) $application['company_id'], (int) $application['user_id'] );
	if ( ! $company || ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_owner_application_subject_not_found', 'Dados da candidatura não encontrados.', array( 'status' => 404 ) );
	}

	$cpf        = $profile && ! empty( $profile['cpf_ciphertext'] ) ? papelito_pii_decrypt( (string) $profile['cpf_ciphertext'] ) : null;
	$birth_date = $profile && ! empty( $profile['birth_date_ciphertext'] ) ? papelito_pii_decrypt( (string) $profile['birth_date_ciphertext'] ) : null;
	$evidence   = json_decode( (string) ( $application['evidence_json'] ?? '' ), true );

	return array(
		'application' => array_merge(
			papelito_company_owner_application_view( $application ),
			array(
				'documentMime'       => $application['document_mime'] ?? null,
				'documentSize'       => isset( $application['document_size'] ) ? (int) $application['document_size'] : null,
				'documentAvailable'  => ! empty( $application['document_storage_key'] ) && 'pending_manual_review' === (string) $application['application_status'],
				'documentPurgeStatus'=> (string) $application['document_purge_status'],
				'rejectionReason'    => $application['rejection_reason'] ?? null,
				'decidedByUserId'    => ! empty( $application['decided_by_user_id'] ) ? (int) $application['decided_by_user_id'] : null,
			)
		),
		'person'      => array(
			'userId'    => $user->ID,
			'fullName'  => trim( $user->first_name . ' ' . $user->last_name ),
			'email'     => $user->user_email,
			'cpf'       => is_string( $cpf ) ? $cpf : null,
			'birthDate' => is_string( $birth_date ) ? $birth_date : null,
			'phone'     => (string) get_user_meta( $user->ID, 'phone_number', true ),
		),
		'company'     => array(
			'id'               => (int) $company['id'],
			'cnpj'             => (string) $company['cnpj'],
			'legalName'        => (string) $company['legal_name'],
			'tradeName'        => $company['trade_name'] ?? null,
			'registryStatus'   => (string) $company['registry_status'],
			'ownershipStatus'  => (string) $company['ownership_status'],
			'companyStatus'    => (string) $company['company_status'],
			'providerSource'   => $company['provider_source'] ?? null,
			'providerCheckedAt'=> $company['provider_checked_at'] ?? null,
			'fiscalAddress'    => array(
				'cep'          => $company['fiscal_cep'],
				'state'        => $company['fiscal_state'],
				'city'         => $company['fiscal_city'],
				'neighborhood' => $company['fiscal_neighborhood'],
				'street'       => $company['fiscal_street'],
				'number'       => $company['fiscal_number'],
				'complement'   => $company['fiscal_complement'],
			),
		),
		'membership'  => $member ? array( 'role' => (string) $member['member_role'], 'status' => (string) $member['member_status'] ) : null,
		'evidence'    => is_array( $evidence ) ? $evidence : array(),
	);
}

/**
 * Decisão terminal, protegida por lock da candidatura.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_company_owner_application_decide( int $application_id, int $actor_user_id, bool $approve, string $reason = '' ) {
	$reason = trim( sanitize_textarea_field( $reason ) );
	if ( ! $approve && '' === $reason ) {
		return new WP_Error( 'papelito_owner_application_rejection_reason_required', 'Informe o motivo interno da reprovação.', array( 'status' => 422 ) );
	}
	if ( function_exists( 'mb_strlen' ) ? mb_strlen( $reason, 'UTF-8' ) > 500 : strlen( $reason ) > 500 ) {
		return new WP_Error( 'papelito_owner_application_rejection_reason_too_long', 'O motivo deve ter no máximo 500 caracteres.', array( 'status' => 422 ) );
	}
	$document_directory = papelito_company_documents_prepare_dir();
	if ( is_wp_error( $document_directory ) ) {
		return $document_directory;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['owner_applications']} WHERE id = %d FOR UPDATE",
				$application_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $application ) ) {
			throw new OutOfBoundsException( 'not_found' );
		}
		if ( 'pending_manual_review' !== (string) $application['application_status'] || empty( $application['is_open'] ) ) {
			throw new DomainException( 'already_decided' );
		}
		$key = (string) ( $application['document_storage_key'] ?? '' );
		if ( ! papelito_company_document_key_is_valid( $key ) || ! is_file( trailingslashit( $document_directory ) . $key ) ) {
			throw new RuntimeException( 'document_unavailable' );
		}

		$company = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['companies']} WHERE id = %d FOR UPDATE",
				(int) $application['company_id']
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $company ) || 'pending_manual_review' !== (string) $company['ownership_status'] ) {
			throw new DomainException( 'company_not_pending' );
		}

		$now    = current_time( 'mysql', true );
		$status = $approve ? 'approved' : 'rejected';
		$updated = $wpdb->update(
			$tables['owner_applications'],
			array(
				'application_status' => $status,
				'is_open'            => null,
				'decided_by_user_id'  => $actor_user_id,
				'decided_at'          => $now,
				'rejection_reason'    => $approve ? null : $reason,
				'updated_at'          => $now,
			),
			array( 'id' => $application_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $updated ) {
			throw new RuntimeException( 'application_update_failed' );
		}

		$company_fields = $approve
			? array(
				'ownership_status'              => 'verified',
				'company_status'                => 'active',
				'owner_user_id'                 => (int) $application['user_id'],
				'verified_by_user_id'           => $actor_user_id,
				'verified_at'                   => $now,
				'ownership_rejection_reason'    => null,
				'ownership_rejected_by_user_id' => null,
				'ownership_rejected_at'         => null,
			)
			: array(
				'ownership_status'              => 'rejected',
				'company_status'                => 'onboarding',
				'owner_user_id'                 => null,
				'ownership_rejection_reason'    => substr( $reason, 0, 255 ),
				'ownership_rejected_by_user_id' => $actor_user_id,
				'ownership_rejected_at'         => $now,
			);
		$company_updated = papelito_company_update( (int) $application['company_id'], $company_fields );
		if ( is_wp_error( $company_updated ) ) {
			throw new RuntimeException( 'company_update_failed' );
		}

		$member = papelito_company_member_upsert(
			(int) $application['company_id'],
			(int) $application['user_id'],
			$approve
				? array(
					'member_role'         => 'owner',
					'member_status'       => 'active',
					'approved_by_user_id' => $actor_user_id,
					'approved_at'         => $now,
					'rejected_at'         => null,
					'rejected_reason'     => null,
					'rejected_by_user_id' => null,
				)
				: array(
					'member_role'         => 'owner',
					'member_status'       => 'rejected',
					'approved_by_user_id' => null,
					'approved_at'         => null,
					'rejected_at'         => $now,
					'rejected_reason'     => substr( $reason, 0, 255 ),
					'rejected_by_user_id' => $actor_user_id,
				)
		);
		if ( is_wp_error( $member ) ) {
			throw new RuntimeException( 'member_update_failed' );
		}

		if ( $approve ) {
			papelito_company_onboarding_mark_completed( (int) $application['user_id'], (int) $application['company_id'], (int) $member );
		} else {
			papelito_company_onboarding_mark_owner_application( (int) $application['user_id'], (int) $application['company_id'], (int) $member, 'rejected' );
		}
		papelito_company_audit(
			(int) $application['company_id'],
			$actor_user_id,
			$approve ? 'owner_application_approved' : 'owner_application_rejected',
			array( 'application_id' => $application_id, 'attempt' => (int) $application['attempt_number'] )
		);
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( OutOfBoundsException $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_owner_application_not_found', 'Candidatura não encontrada.', array( 'status' => 404 ) );
	} catch ( DomainException $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_owner_application_decision_conflict', 'Esta candidatura já foi decidida ou não está mais pendente.', array( 'status' => 409 ) );
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_owner_application_decision_failed', 'Não foi possível registrar a decisão.', array( 'status' => 500 ) );
	}

	update_user_meta( (int) $application['user_id'], 'papelito_account_state', $approve ? 'active' : 'pending_review' );
	papelito_company_owner_application_purge_document( $application_id );
	$decided = papelito_company_owner_application_get( $application_id );
	if ( $decided ) {
		papelito_company_owner_application_send_decision_email( $decided );
	}

	return $decided ? papelito_company_owner_application_admin_detail( $application_id ) : new WP_Error( 'papelito_owner_application_not_found', 'Candidatura não encontrada.', array( 'status' => 404 ) );
}

/**
 * Apaga conteúdo e metadados sensíveis de um documento decidido.
 */
function papelito_company_owner_application_purge_document( int $application_id ): bool {
	$application = papelito_company_owner_application_get( $application_id );
	if ( ! $application || ! in_array( (string) $application['application_status'], array( 'approved', 'rejected' ), true ) ) {
		return false;
	}

	$key     = (string) ( $application['document_storage_key'] ?? '' );
	$deleted = true;
	if ( '' !== $key ) {
		if ( ! papelito_company_document_key_is_valid( $key ) ) {
			$deleted = false;
		} else {
			$directory = papelito_company_documents_prepare_dir();
			if ( is_wp_error( $directory ) ) {
				$deleted = false;
			} else {
				$path = trailingslashit( $directory ) . $key;
				$deleted = ! is_file( $path ) || unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	if ( $deleted ) {
		$wpdb->update(
			$tables['owner_applications'],
			array(
				'document_storage_key'     => null,
				'document_original_name'   => null,
				'document_sha256'          => null,
				'document_purge_status'    => 'deleted',
				'document_purge_error_code'=> null,
				'document_deleted_at'      => current_time( 'mysql', true ),
				'updated_at'               => current_time( 'mysql', true ),
			),
			array( 'id' => $application_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	$wpdb->update(
		$tables['owner_applications'],
		array(
			'document_purge_status'    => 'failed',
			'document_purge_error_code'=> 'unlink_failed',
			'updated_at'               => current_time( 'mysql', true ),
		),
		array( 'id' => $application_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( PAPELITO_COMPANY_DOCUMENT_PURGE_HOOK, array( $application_id ) ) ) {
		wp_schedule_single_event( time() + ( 5 * MINUTE_IN_SECONDS ), PAPELITO_COMPANY_DOCUMENT_PURGE_HOOK, array( $application_id ) );
	}

	return false;
}
add_action( PAPELITO_COMPANY_DOCUMENT_PURGE_HOOK, 'papelito_company_owner_application_purge_document', 10, 1 );

function papelito_company_owner_applications_cleanup_deleted_user( int $user_id ): void {
	global $wpdb;
	$tables = papelito_company_table_names();
	foreach ( papelito_company_owner_applications_for_user( $user_id ) as $application ) {
		$key = (string) ( $application['document_storage_key'] ?? '' );
		if ( papelito_company_document_key_is_valid( $key ) ) {
			papelito_company_document_discard_path( trailingslashit( papelito_company_documents_dir() ) . $key );
		}
	}
	$wpdb->delete( $tables['owner_applications'], array( 'user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
add_action( 'delete_user', 'papelito_company_owner_applications_cleanup_deleted_user', 10, 1 );
