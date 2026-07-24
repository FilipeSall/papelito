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
	$company = papelito_company_get( $company_id );
	if ( ! $company ) { return new WP_Error( 'papelito_b2b_company_not_found', 'Empresa não encontrada.', array( 'status' => 404 ) ); }
	if ( 'pending_manual_review' !== $company['ownership_status'] ) { return new WP_Error( 'papelito_b2b_invalid_transition', 'Empresa não está pendente de revisão.', array( 'status' => 409 ) ); }
	if ( ! $approve ) {
		global $wpdb; $tables = papelito_company_table_names(); $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			$updated = papelito_company_update( $company_id, array( 'ownership_status' => 'rejected', 'ownership_rejection_reason' => $reason, 'ownership_rejected_by_user_id' => $actor, 'ownership_rejected_at' => current_time( 'mysql', true ) ) );
			$member  = papelito_company_member_upsert( $company_id, (int) $company['created_by_user_id'], array( 'member_role' => 'owner', 'member_status' => 'rejected', 'rejected_at' => current_time( 'mysql', true ), 'rejected_reason' => $reason ) );
			if ( is_wp_error( $updated ) || is_wp_error( $member ) ) { throw new RuntimeException( 'rejection failed' ); }
			papelito_company_audit( $company_id, $actor, 'owner_rejected', array( 'reason' => $reason ) );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'papelito_b2b_rejection_failed', 'Não foi possível rejeitar a empresa.', array( 'status' => 409 ) ); }
		return true;
	}
	$lookup = papelito_cnpj_lookup( (string) $company['cnpj'], true );
	if ( 'active' !== (string) $lookup['status'] ) { return new WP_Error( 'papelito_b2b_registry_not_active', 'CNPJ não está ativo para aprovação.', array( 'status' => 422 ) ); }
	global $wpdb; $tables = papelito_company_table_names(); $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['companies']} WHERE id = %d FOR UPDATE", $company_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $locked ) || 'pending_manual_review' !== $locked['ownership_status'] ) { throw new RuntimeException( 'invalid transition' ); }
		$now = current_time( 'mysql', true );
		$wpdb->update( $tables['companies'], array( 'registry_status' => 'active', 'ownership_status' => 'verified', 'company_status' => 'active', 'owner_user_id' => (int) $locked['created_by_user_id'], 'verified_by_user_id' => $actor, 'verified_at' => $now, 'provider_source' => (string) $lookup['source'], 'provider_checked_at' => $now, 'updated_at' => $now ), array( 'id' => $company_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $tables['members'], array( 'member_status' => 'active', 'approved_by_user_id' => $actor, 'approved_at' => $now, 'updated_at' => $now ), array( 'company_id' => $company_id, 'user_id' => (int) $locked['created_by_user_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_company_audit( $company_id, $actor, 'owner_approved', array( 'provider' => $lookup['source'] ?? '' ) );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'papelito_b2b_approval_failed', 'Não foi possível aprovar a empresa.', array( 'status' => 409 ) ); }
	return true;
}

add_action( 'rest_api_init', static function (): void {
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
