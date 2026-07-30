<?php

defined( 'ABSPATH' ) || exit;

function papelito_company_admin_install_capability(): void {
	$role = get_role( 'administrator' );
	if ( $role instanceof WP_Role ) { $role->add_cap( 'papelito_manage_companies' ); }
}
add_action( 'init', 'papelito_company_admin_install_capability' );

function papelito_company_admin_require_capability(): bool { return current_user_can( 'papelito_manage_companies' ); }

function papelito_company_admin_list( WP_REST_Request $request ): array {
	global $wpdb;
	$tables = papelito_company_table_names(); $page = max( 1, (int) $request->get_param( 'page' ) ); $per_page = min( 50, max( 1, (int) $request->get_param( 'perPage' ) ?: 20 ) ); $offset = ( $page - 1 ) * $per_page;
	$status = sanitize_key( (string) $request->get_param( 'status' ) ?: 'pending_manual_review' );
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['companies']} WHERE ownership_status = %s", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, legal_name, trade_name, registry_status, ownership_status, company_status, provider_source, provider_checked_at, provider_data_hash, created_at FROM {$tables['companies']} WHERE ownership_status = %s ORDER BY created_at ASC LIMIT %d OFFSET %d", $status, $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	return array( 'items' => is_array( $rows ) ? $rows : array(), 'page' => $page, 'perPage' => $per_page, 'total' => $total );
}

function papelito_company_admin_idempotency( int $actor, string $operation, string $key, string $request_hash ): ?array {
	return papelito_company_idempotency_check( $actor, $operation, $key, $request_hash );
}

function papelito_company_admin_store_idempotency( int $actor, string $operation, string $key, string $request_hash, int $resource_id, int $code ): void {
	papelito_company_idempotency_store( $actor, $operation, $key, $request_hash, $resource_id, $code );
}

function papelito_company_admin_transition( int $company_id, int $actor, bool $approve, string $reason = '' ) {
	global $wpdb;
	$tables      = papelito_company_table_names();
	$application = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$tables['owner_applications']} WHERE company_id = %d AND application_status = 'pending_manual_review' AND is_open = 1 ORDER BY id DESC LIMIT 1",
			$company_id
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! is_array( $application ) ) {
		return new WP_Error( 'papelito_b2b_invalid_transition', 'Empresa não está pendente de revisão.', array( 'status' => 409 ) );
	}

	return papelito_company_owner_application_decide( (int) $application['id'], $actor, $approve, $reason );
}

function papelito_company_admin_owner_applications_list( WP_REST_Request $request ): array {
	global $wpdb;
	$tables   = papelito_company_table_names();
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = min( 50, max( 1, (int) $request->get_param( 'perPage' ) ?: 20 ) );
	$offset   = ( $page - 1 ) * $per_page;
	$status   = sanitize_key( (string) $request->get_param( 'status' ) ?: 'pending_manual_review' );
	$allowed  = array( 'document_required', 'pending_manual_review', 'approved', 'rejected', 'auto_approved' );
	if ( ! in_array( $status, $allowed, true ) ) {
		$status = 'pending_manual_review';
	}
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['owner_applications']} WHERE application_status = %s",
			$status
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT a.id, a.company_id, a.user_id, a.attempt_number, a.application_status, a.document_uploaded_at, a.created_at, c.legal_name, c.trade_name, u.display_name, u.user_email
			FROM {$tables['owner_applications']} a
			INNER JOIN {$tables['companies']} c ON c.id = a.company_id
			INNER JOIN {$wpdb->users} u ON u.ID = a.user_id
			WHERE a.application_status = %s
			ORDER BY COALESCE(a.document_uploaded_at, a.created_at) ASC
			LIMIT %d OFFSET %d",
			$status,
			$per_page,
			$offset
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array(
		'items'   => array_map(
			static fn( array $row ): array => array(
				'applicationId' => (int) $row['id'],
				'companyId'     => (int) $row['company_id'],
				'userId'        => (int) $row['user_id'],
				'attemptNumber' => (int) $row['attempt_number'],
				'status'        => (string) $row['application_status'],
				'submittedAt'   => $row['document_uploaded_at'] ?? null,
				'createdAt'     => (string) $row['created_at'],
				'companyName'   => (string) $row['legal_name'],
				'tradeName'     => $row['trade_name'] ?? null,
				'userName'      => (string) $row['display_name'],
				'userEmail'     => (string) $row['user_email'],
			),
			is_array( $rows ) ? $rows : array()
		),
		'page'    => $page,
		'perPage' => $per_page,
		'total'   => $total,
	);
}

function papelito_company_admin_owner_application_document( int $application_id ) {
	$application = papelito_company_owner_application_get( $application_id );
	if ( ! $application ) {
		return new WP_Error( 'papelito_owner_application_not_found', 'Candidatura não encontrada.', array( 'status' => 404 ) );
	}
	if ( 'pending_manual_review' !== (string) $application['application_status'] || empty( $application['document_storage_key'] ) ) {
		return new WP_Error( 'papelito_owner_application_document_unavailable', 'O documento não está mais disponível.', array( 'status' => 410 ) );
	}

	$key = (string) $application['document_storage_key'];
	if ( 1 !== preg_match( '/^[a-f0-9]{64}\.(?:jpg|png|pdf)$/', $key ) ) {
		return new WP_Error( 'papelito_owner_application_document_invalid', 'Documento inválido.', array( 'status' => 500 ) );
	}
	$directory = papelito_company_documents_prepare_dir();
	if ( is_wp_error( $directory ) ) {
		return $directory;
	}
	$path = trailingslashit( $directory ) . $key;
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return new WP_Error( 'papelito_owner_application_document_missing', 'Documento não encontrado.', array( 'status' => 410 ) );
	}

	nocache_headers();
	header( 'Content-Type: ' . (string) $application['document_mime'] );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	header( 'Content-Disposition: inline; filename="' . str_replace( array( '"', "\r", "\n" ), '', (string) $application['document_original_name'] ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'papelito/v1', '/admin/owner-applications', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_admin_require_capability', 'callback' => static fn( WP_REST_Request $r ) => new WP_REST_Response( papelito_company_admin_owner_applications_list( $r ), 200 ) ) );
	register_rest_route( 'papelito/v1', '/admin/owner-applications/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_admin_require_capability', 'callback' => static function ( WP_REST_Request $r ) {
		$detail = papelito_company_owner_application_admin_detail( (int) $r['id'] );
		return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, 200 );
	} ) );
	register_rest_route( 'papelito/v1', '/admin/owner-applications/(?P<id>\d+)/document', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_admin_require_capability', 'callback' => static fn( WP_REST_Request $r ) => papelito_company_admin_owner_application_document( (int) $r['id'] ) ) );
	register_rest_route( 'papelito/v1', '/admin/users/(?P<user_id>\d+)/owner-applications', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_admin_require_capability', 'callback' => static function ( WP_REST_Request $r ) {
		$applications = papelito_company_owner_application_latest_for_user( (int) $r['user_id'] );
		if ( ! $applications ) {
			return new WP_REST_Response( array( 'current' => null, 'history' => array() ), 200 );
		}
		$history = array();
		foreach ( papelito_company_owner_applications_for_user( (int) $r['user_id'] ) as $application ) {
			$history[] = papelito_company_owner_application_admin_detail( (int) $application['id'] );
		}
		return new WP_REST_Response( array( 'current' => $history[0] ?? null, 'history' => $history ), 200 );
	} ) );
	foreach ( array( 'approve' => true, 'reject' => false ) as $application_action => $application_approve ) {
		register_rest_route( 'papelito/v1', '/admin/owner-applications/(?P<id>\d+)/' . $application_action, array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_admin_require_capability', 'callback' => static function ( WP_REST_Request $r ) use ( $application_approve ) {
			$actor = get_current_user_id();
			$key   = sanitize_text_field( (string) $r->get_header( 'Idempotency-Key' ) );
			if ( '' === $key || strlen( $key ) > 191 ) {
				return new WP_Error( 'papelito_b2b_idempotency_key_required', 'Informe uma chave de idempotência válida.', array( 'status' => 422 ) );
			}
			$body   = (array) $r->get_json_params();
			$reason = sanitize_textarea_field( (string) ( $body['reason'] ?? '' ) );
			$op     = $application_approve ? 'owner_application_approve' : 'owner_application_reject';
			$hash   = papelito_company_idempotency_request_hash( array( 'application_id' => (int) $r['id'], 'reason' => $reason ) );
			$previous = papelito_company_idempotency_check( $actor, $op, $key, $hash );
			if ( isset( $previous['error'] ) ) { return $previous['error']; }
			if ( $previous ) {
				$detail = papelito_company_owner_application_admin_detail( (int) $previous['resource_id'] );
				return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, (int) $previous['response_code'] );
			}
			$result = papelito_company_owner_application_decide( (int) $r['id'], $actor, $application_approve, $reason );
			if ( is_wp_error( $result ) ) { return $result; }
			papelito_company_idempotency_store( $actor, $op, $key, $hash, (int) $r['id'], 200 );
			return new WP_REST_Response( $result, 200 );
		} ) );
	}
	register_rest_route( 'papelito/v1', '/admin/companies', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_admin_require_capability', 'callback' => static fn( WP_REST_Request $r ) => new WP_REST_Response( papelito_company_admin_list( $r ), 200 ) ) );
	register_rest_route( 'papelito/v1', '/admin/companies/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => 'papelito_company_admin_require_capability', 'callback' => static function ( WP_REST_Request $r ) {
		$company = papelito_company_get( (int) $r['id'] ); if ( ! $company ) { return new WP_Error( 'papelito_b2b_company_not_found', 'Empresa não encontrada.', array( 'status' => 404 ) ); }
		global $wpdb; $tables = papelito_company_table_names();
		$audit = $wpdb->get_results( $wpdb->prepare( "SELECT action, payload_json, created_at FROM {$tables['audit']} WHERE company_id = %d ORDER BY id DESC LIMIT 10", (int) $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$events = array_map( static function ( array $row ): array { $payload = json_decode( (string) $row['payload_json'], true ); return array( 'action' => $row['action'], 'createdAt' => $row['created_at'], 'evidence' => is_array( $payload['evidence'] ?? null ) ? $payload['evidence'] : null ); }, is_array( $audit ) ? $audit : array() );
		$members = papelito_company_members_list( (int) $r['id'] );
		$evidence = null;
		foreach ( $events as $event ) { if ( is_array( $event['evidence'] ?? null ) ) { $evidence = $event['evidence']; break; } }
		return new WP_REST_Response( array( 'company' => array( 'id' => (int) $company['id'], 'legalName' => $company['legal_name'], 'tradeName' => $company['trade_name'], 'registryStatus' => $company['registry_status'], 'ownershipStatus' => $company['ownership_status'], 'companyStatus' => $company['company_status'], 'providerSource' => $company['provider_source'], 'providerCheckedAt' => $company['provider_checked_at'], 'rejectionReason' => $company['ownership_rejection_reason'], 'createdByUserId' => (int) $company['created_by_user_id'], 'ownerUserId' => ! empty( $company['owner_user_id'] ) ? (int) $company['owner_user_id'] : null ), 'evidence' => $evidence, 'members' => array_map( static function ( array $member ): array { return array( 'userId' => (int) $member['user_id'], 'role' => (string) $member['member_role'], 'status' => (string) $member['member_status'] ); }, $members ), 'events' => $events ), 200 );
	} ) );
	foreach ( array( 'approve' => true, 'reject' => false ) as $action => $approve ) {
		register_rest_route( 'papelito/v1', '/admin/companies/(?P<id>\d+)/' . $action, array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_admin_require_capability', 'callback' => static function ( WP_REST_Request $r ) use ( $approve ) {
			$body = (array) $r->get_json_params(); $reason = sanitize_text_field( (string) ( $body['reason'] ?? '' ) );
			if ( ! $approve && '' === $reason ) { return new WP_Error( 'papelito_b2b_rejection_reason_required', 'Motivo da rejeição obrigatório.', array( 'status' => 422 ) ); }
			$actor = get_current_user_id(); $key = (string) $r->get_header( 'Idempotency-Key' ); $op = $approve ? 'owner_approve' : 'owner_reject'; $hash = hash( 'sha256', wp_json_encode( array( 'id' => (int) $r['id'], 'reason' => $reason ) ) ?: '' );
			$previous = papelito_company_admin_idempotency( $actor, $op, $key, $hash ); if ( isset( $previous['error'] ) ) { return $previous['error']; } if ( $previous ) { return new WP_REST_Response( array( 'companyId' => $previous['resource_id'], 'replayed' => true ), $previous['response_code'] ); }
			$result = papelito_company_admin_transition( (int) $r['id'], $actor, $approve, $reason ); if ( is_wp_error( $result ) ) { return $result; }
			papelito_company_admin_store_idempotency( $actor, $op, $key, $hash, (int) $r['id'], 200 ); return new WP_REST_Response( array( 'companyId' => (int) $r['id'] ), 200 );
		} ) );
	}
} );
