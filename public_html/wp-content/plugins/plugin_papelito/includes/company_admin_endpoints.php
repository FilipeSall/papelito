<?php

defined( 'ABSPATH' ) || exit;

function papelito_company_admin_install_capability(): void {
	$role = get_role( 'administrator' );
	if ( $role instanceof WP_Role ) { $role->add_cap( 'papelito_manage_companies' ); }
}
add_action( 'init', 'papelito_company_admin_install_capability' );

const PAPELITO_COMPANY_ADMIN_NAMESPACE = 'papelito/v1';

function papelito_company_admin_require_capability(): bool { return current_user_can( 'papelito_manage_companies' ); }

/**
 * Listagem administrativa de empresas.
 *
 * `status` (ownership_status) continua sendo o recorte da fila de analise documental. Sem ele a
 * listagem passa a ser a base inteira de empresas, que e o que a area de contas consome, e aceita
 * recorte por `companyStatus` e busca por razao social, nome fantasia ou CNPJ.
 *
 * @return array<string,mixed>
 */
function papelito_company_admin_list( WP_REST_Request $request ): array {
	global $wpdb;

	$tables   = papelito_company_table_names();
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = min( 50, max( 1, (int) $request->get_param( 'perPage' ) ?: 20 ) );
	$offset   = ( $page - 1 ) * $per_page;

	$args  = array();
	$where = papelito_company_admin_list_where( $request, $args );

	$total_sql = "SELECT COUNT(*) FROM {$tables['companies']} c" . $where;
	$total     = (int) $wpdb->get_var( empty( $args ) ? $total_sql : $wpdb->prepare( $total_sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	$page_args   = $args;
	$page_args[] = $per_page;
	$page_args[] = $offset;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT c.id, c.legal_name, c.trade_name, c.cnpj, c.registry_status, c.ownership_status, c.company_status,
				c.provider_source, c.provider_checked_at, c.provider_data_hash, c.owner_user_id, c.created_at,
				( SELECT COUNT(*) FROM {$tables['members']} m WHERE m.company_id = c.id AND m.member_status = 'active' ) AS active_members,
				( SELECT COUNT(*) FROM {$tables['members']} m WHERE m.company_id = c.id AND m.member_status IN ( 'pending_company_approval', 'pending_identity' ) ) AS pending_members
			FROM {$tables['companies']} c" . $where . ' ORDER BY c.created_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL
			$page_args
		),
		ARRAY_A
	);

	$items = array_map( 'papelito_company_admin_list_row_view', is_array( $rows ) ? $rows : array() );

	return array( 'items' => $items, 'page' => $page, 'perPage' => $per_page, 'total' => $total );
}

/**
 * Monta a clausula WHERE da listagem e acumula os parametros preparados.
 *
 * @param array<int,mixed> $args
 */
function papelito_company_admin_list_where( WP_REST_Request $request, array &$args ): string {
	global $wpdb;

	$conditions = array( '1=1' );

	$ownership_status = sanitize_key( (string) $request->get_param( 'status' ) );
	if ( '' !== $ownership_status && 'all' !== $ownership_status ) {
		$conditions[] = 'c.ownership_status = %s';
		$args[]       = $ownership_status;
	}

	$company_status = sanitize_key( (string) $request->get_param( 'companyStatus' ) );
	if ( '' !== $company_status && 'all' !== $company_status ) {
		$conditions[] = 'c.company_status = %s';
		$args[]       = $company_status;
	}

	$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
	if ( '' !== $search ) {
		$term         = '%' . $wpdb->esc_like( $search ) . '%';
		$digits       = (string) preg_replace( '/\D+/', '', $search );
		$cnpj_term    = '' !== $digits ? '%' . $wpdb->esc_like( $digits ) . '%' : $term;
		$conditions[] = '( c.legal_name LIKE %s OR c.trade_name LIKE %s OR c.cnpj LIKE %s )';
		array_push( $args, $term, $term, $cnpj_term );
	}

	return ' WHERE ' . implode( ' AND ', $conditions );
}

/**
 * Linha da listagem no formato consumido pelo painel.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function papelito_company_admin_list_row_view( array $row ): array {
	$owner_id   = (int) $row['owner_user_id'];
	$owner_user = $owner_id > 0 ? get_userdata( $owner_id ) : null;

	return array_merge(
		$row,
		array(
			'id'             => (int) $row['id'],
			'activeMembers'  => (int) $row['active_members'],
			'pendingMembers' => (int) $row['pending_members'],
			'ownerUserId'    => $owner_id,
			'ownerName'      => $owner_user instanceof WP_User ? (string) $owner_user->display_name : '',
			'ownerEmail'     => $owner_user instanceof WP_User ? (string) $owner_user->user_email : '',
		)
	);
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
	if ( ! papelito_company_document_key_is_valid( $key ) ) {
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

/**
 * Exige a chave de idempotência nas decisões administrativas.
 *
 * @return string|WP_Error
 */
function papelito_company_admin_idempotency_key( WP_REST_Request $request ) {
	$key = sanitize_text_field( (string) $request->get_header( 'Idempotency-Key' ) );

	if ( '' === $key || strlen( $key ) > 191 ) {
		return new WP_Error( 'papelito_b2b_idempotency_key_required', 'Informe uma chave de idempotência válida.', array( 'status' => 422 ) );
	}

	return $key;
}

/**
 * Registra uma rota administrativa de leitura.
 */
function papelito_company_admin_register_read_route( string $route, callable $callback ): void {
	register_rest_route(
		PAPELITO_COMPANY_ADMIN_NAMESPACE,
		$route,
		array(
			'methods'             => 'GET',
			'permission_callback' => 'papelito_company_admin_require_capability',
			'callback'            => $callback,
		)
	);
}

/**
 * Registra o par approve/reject de uma decisão administrativa.
 */
function papelito_company_admin_register_decision_routes( string $route_prefix, callable $handler ): void {
	foreach ( array( 'approve' => true, 'reject' => false ) as $action => $approve ) {
		register_rest_route(
			PAPELITO_COMPANY_ADMIN_NAMESPACE,
			$route_prefix . $action,
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_company_admin_require_capability',
				'callback'            => static fn( WP_REST_Request $request ) => $handler( $request, $approve ),
			)
		);
	}
}

/**
 * GET /admin/pre-account-applications
 */
function papelito_company_admin_handle_pre_account_list( WP_REST_Request $request ): WP_REST_Response {
	$status = sanitize_key( (string) $request->get_param( 'status' ) ?: 'pending_manual_review' );

	return new WP_REST_Response( array( 'items' => papelito_pre_account_application_admin_list( $status ) ), 200 );
}

/**
 * GET /admin/pre-account-applications/{id}
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_company_admin_handle_pre_account_detail( WP_REST_Request $request ) {
	$detail = papelito_pre_account_application_admin_detail( (int) $request['id'] );

	return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, 200 );
}

/**
 * POST /admin/pre-account-applications/{id}/{approve|reject}
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_company_admin_handle_pre_account_decision( WP_REST_Request $request, bool $approve ) {
	$key = papelito_company_admin_idempotency_key( $request );

	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$actor     = get_current_user_id();
	$body      = (array) $request->get_json_params();
	$reason    = sanitize_textarea_field( (string) ( $body['reason'] ?? '' ) );
	$operation = $approve ? 'pre_account_application_approve' : 'pre_account_application_reject';
	$hash      = papelito_company_idempotency_request_hash( array( 'application_id' => (int) $request['id'], 'reason' => $reason ) );
	$previous  = papelito_company_idempotency_check( $actor, $operation, $key, $hash );

	if ( isset( $previous['error'] ) ) {
		return $previous['error'];
	}

	if ( $previous ) {
		$detail = papelito_pre_account_application_admin_detail( (int) $previous['resource_id'] );

		return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, (int) $previous['response_code'] );
	}

	$result = papelito_pre_account_application_decide( (int) $request['id'], $actor, $approve, $reason );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	papelito_company_idempotency_store( $actor, $operation, $key, $hash, (int) $request['id'], 200 );
	$detail = papelito_pre_account_application_admin_detail( (int) $request['id'] );

	return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, 200 );
}

/**
 * GET /admin/owner-applications/{id}
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_company_admin_handle_owner_application_detail( WP_REST_Request $request ) {
	$detail = papelito_company_owner_application_admin_detail( (int) $request['id'] );

	return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, 200 );
}

/**
 * GET /admin/users/{user_id}/owner-applications
 */
function papelito_company_admin_handle_user_owner_applications( WP_REST_Request $request ): WP_REST_Response {
	$user_id = (int) $request['user_id'];

	if ( ! papelito_company_owner_application_latest_for_user( $user_id ) ) {
		return new WP_REST_Response( array( 'current' => null, 'history' => array() ), 200 );
	}

	$history = array();

	foreach ( papelito_company_owner_applications_for_user( $user_id ) as $application ) {
		$history[] = papelito_company_owner_application_admin_detail( (int) $application['id'] );
	}

	return new WP_REST_Response( array( 'current' => $history[0] ?? null, 'history' => $history ), 200 );
}

/**
 * POST /admin/owner-applications/{id}/{approve|reject}
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_company_admin_handle_owner_application_decision( WP_REST_Request $request, bool $approve ) {
	$key = papelito_company_admin_idempotency_key( $request );

	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$actor    = get_current_user_id();
	$body     = (array) $request->get_json_params();
	$reason   = sanitize_textarea_field( (string) ( $body['reason'] ?? '' ) );
	$op       = $approve ? 'owner_application_approve' : 'owner_application_reject';
	$hash     = papelito_company_idempotency_request_hash( array( 'application_id' => (int) $request['id'], 'reason' => $reason ) );
	$previous = papelito_company_idempotency_check( $actor, $op, $key, $hash );

	if ( isset( $previous['error'] ) ) {
		return $previous['error'];
	}

	if ( $previous ) {
		$detail = papelito_company_owner_application_admin_detail( (int) $previous['resource_id'] );

		return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, (int) $previous['response_code'] );
	}

	$result = papelito_company_owner_application_decide( (int) $request['id'], $actor, $approve, $reason );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	papelito_company_idempotency_store( $actor, $op, $key, $hash, (int) $request['id'], 200 );

	return new WP_REST_Response( $result, 200 );
}

/**
 * Eventos de auditoria da empresa, do mais recente para o mais antigo.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_company_admin_audit_events( int $company_id ): array {
	global $wpdb;

	$tables = papelito_company_table_names();
	$audit  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT action, actor_user_id, payload_json, created_at FROM {$tables['audit']} WHERE company_id = %d ORDER BY id DESC LIMIT 20", $company_id ), // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);

	return array_map( 'papelito_company_admin_audit_event_view', is_array( $audit ) ? $audit : array() );
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function papelito_company_admin_audit_event_view( array $row ): array {
	$payload    = json_decode( (string) $row['payload_json'], true );
	$actor_id   = (int) $row['actor_user_id'];
	$actor_user = $actor_id > 0 ? get_userdata( $actor_id ) : null;

	return array(
		'action'      => $row['action'],
		'createdAt'   => $row['created_at'],
		'reason'      => is_array( $payload ) ? (string) ( $payload['reason'] ?? '' ) : '',
		'actorUserId' => $actor_id,
		'actorName'   => $actor_user instanceof WP_User ? (string) $actor_user->display_name : '',
		'evidence'    => is_array( $payload['evidence'] ?? null ) ? $payload['evidence'] : null,
	);
}

/**
 * @param array<string,mixed> $member
 * @return array<string,mixed>
 */
function papelito_company_admin_member_view( array $member ): array {
	$user_id     = (int) $member['user_id'];
	$member_user = get_userdata( $user_id );

	return array(
		'userId'        => $user_id,
		'name'          => $member_user instanceof WP_User ? (string) $member_user->display_name : '',
		'email'         => $member_user instanceof WP_User ? (string) $member_user->user_email : '',
		'isVendor'      => $member_user instanceof WP_User && function_exists( 'papelito_user_is_effective_seller' ) && papelito_user_is_effective_seller( $member_user ),
		'accountStatus' => function_exists( 'papelito_account_status' ) ? papelito_account_status( $user_id ) : 'active',
		'role'          => (string) $member['member_role'],
		'status'        => (string) $member['member_status'],
	);
}

/**
 * @param array<string,mixed> $company
 * @return array<string,mixed>
 */
function papelito_company_admin_company_view( array $company ): array {
	return array(
		'id'                     => (int) $company['id'],
		'legalName'              => $company['legal_name'],
		'tradeName'              => $company['trade_name'],
		'cnpj'                   => $company['cnpj'],
		'registryStatus'         => $company['registry_status'],
		'ownershipStatus'        => $company['ownership_status'],
		'companyStatus'          => $company['company_status'],
		'providerSource'         => $company['provider_source'],
		'providerCheckedAt'      => $company['provider_checked_at'],
		'rejectionReason'        => $company['ownership_rejection_reason'],
		'createdByUserId'        => (int) $company['created_by_user_id'],
		'ownerUserId'            => ! empty( $company['owner_user_id'] ) ? (int) $company['owner_user_id'] : null,
		'billingEmail'           => $company['billing_email'] ?? '',
		'billingEmailVerifiedAt' => $company['billing_email_verified_at'] ?? null,
		'phone'                  => $company['phone'] ?? '',
		'createdAt'              => $company['created_at'] ?? '',
		'fiscalAddress'          => array(
			'cep'          => $company['fiscal_cep'] ?? '',
			'state'        => $company['fiscal_state'] ?? '',
			'city'         => $company['fiscal_city'] ?? '',
			'neighborhood' => $company['fiscal_neighborhood'] ?? '',
			'street'       => $company['fiscal_street'] ?? '',
			'number'       => $company['fiscal_number'] ?? '',
			'complement'   => $company['fiscal_complement'] ?? '',
		),
	);
}

/**
 * Primeira evidência disponível no histórico de auditoria.
 *
 * @param array<int,array<string,mixed>> $events
 * @return array<string,mixed>|null
 */
function papelito_company_admin_first_evidence( array $events ): ?array {
	foreach ( $events as $event ) {
		if ( is_array( $event['evidence'] ?? null ) ) {
			return $event['evidence'];
		}
	}

	return null;
}

/**
 * GET /admin/companies/{id}
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_company_admin_handle_company_detail( WP_REST_Request $request ) {
	$company_id = (int) $request['id'];
	$company    = papelito_company_get( $company_id );

	if ( ! $company ) {
		return new WP_Error( 'papelito_b2b_company_not_found', 'Empresa não encontrada.', array( 'status' => 404 ) );
	}

	$events = papelito_company_admin_audit_events( $company_id );

	return new WP_REST_Response(
		array(
			'company'  => papelito_company_admin_company_view( $company ),
			'evidence' => papelito_company_admin_first_evidence( $events ),
			'members'  => array_map( 'papelito_company_admin_member_view', papelito_company_members_list( $company_id ) ),
			'events'   => $events,
		),
		200
	);
}

/**
 * POST /admin/companies/{id}/{approve|reject}
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_company_admin_handle_company_decision( WP_REST_Request $request, bool $approve ) {
	$body   = (array) $request->get_json_params();
	$reason = sanitize_text_field( (string) ( $body['reason'] ?? '' ) );

	if ( ! $approve && '' === $reason ) {
		return new WP_Error( 'papelito_b2b_rejection_reason_required', 'Motivo da rejeição obrigatório.', array( 'status' => 422 ) );
	}

	$company_id = (int) $request['id'];
	$actor      = get_current_user_id();
	$key        = (string) $request->get_header( 'Idempotency-Key' );
	$op         = $approve ? 'owner_approve' : 'owner_reject';
	$hash       = hash( 'sha256', wp_json_encode( array( 'id' => $company_id, 'reason' => $reason ) ) ?: '' );
	$previous   = papelito_company_admin_idempotency( $actor, $op, $key, $hash );

	if ( isset( $previous['error'] ) ) {
		return $previous['error'];
	}

	if ( $previous ) {
		return new WP_REST_Response( array( 'companyId' => $previous['resource_id'], 'replayed' => true ), $previous['response_code'] );
	}

	$result = papelito_company_admin_transition( $company_id, $actor, $approve, $reason );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	papelito_company_admin_store_idempotency( $actor, $op, $key, $hash, $company_id, 200 );

	return new WP_REST_Response( array( 'companyId' => $company_id ), 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		papelito_company_admin_register_read_route( '/admin/pre-account-applications', 'papelito_company_admin_handle_pre_account_list' );
		papelito_company_admin_register_read_route( '/admin/pre-account-applications/(?P<id>\d+)', 'papelito_company_admin_handle_pre_account_detail' );
		papelito_company_admin_register_read_route(
			'/admin/pre-account-applications/(?P<id>\d+)/document',
			static fn( WP_REST_Request $request ) => papelito_pre_account_application_admin_document( (int) $request['id'] )
		);
		papelito_company_admin_register_decision_routes(
			'/admin/pre-account-applications/(?P<id>\d+)/',
			'papelito_company_admin_handle_pre_account_decision'
		);

		papelito_company_admin_register_read_route(
			'/admin/owner-applications',
			static fn( WP_REST_Request $request ) => new WP_REST_Response( papelito_company_admin_owner_applications_list( $request ), 200 )
		);
		papelito_company_admin_register_read_route( '/admin/owner-applications/(?P<id>\d+)', 'papelito_company_admin_handle_owner_application_detail' );
		papelito_company_admin_register_read_route(
			'/admin/owner-applications/(?P<id>\d+)/document',
			static fn( WP_REST_Request $request ) => papelito_company_admin_owner_application_document( (int) $request['id'] )
		);
		papelito_company_admin_register_read_route( '/admin/users/(?P<user_id>\d+)/owner-applications', 'papelito_company_admin_handle_user_owner_applications' );
		papelito_company_admin_register_decision_routes(
			'/admin/owner-applications/(?P<id>\d+)/',
			'papelito_company_admin_handle_owner_application_decision'
		);

		papelito_company_admin_register_read_route(
			'/admin/companies',
			static fn( WP_REST_Request $request ) => new WP_REST_Response( papelito_company_admin_list( $request ), 200 )
		);
		papelito_company_admin_register_read_route( '/admin/companies/(?P<id>\d+)', 'papelito_company_admin_handle_company_detail' );
		papelito_company_admin_register_decision_routes(
			'/admin/companies/(?P<id>\d+)/',
			'papelito_company_admin_handle_company_decision'
		);
	}
);
