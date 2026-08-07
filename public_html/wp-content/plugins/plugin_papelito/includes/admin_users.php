<?php
/**
 * Endpoints administrativos de usuarios.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filtros aceitos na listagem admin de usuarios.
 *
 * @return array<int, string>
 */
function papelito_admin_users_allowed_roles(): array {
	return array( 'all', 'administrator', 'customer', 'seller', 'other' );
}

/**
 * Normaliza um enum textual.
 *
 * @param string $value Valor bruto.
 * @param array  $allowed Valores permitidos.
 * @param string $fallback Fallback.
 * @return string
 */
function papelito_admin_users_normalize_enum( string $value, array $allowed, string $fallback ): string {
	return in_array( $value, $allowed, true ) ? $value : $fallback;
}

/**
 * Normaliza filtros de listagem.
 *
 * @param WP_REST_Request $request Request REST.
 * @return array<string, int|string>
 */
function papelito_admin_users_parse_filters( WP_REST_Request $request ): array {
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = max( 1, min( 50, (int) $request->get_param( 'perPage' ) ) );

	if ( $per_page <= 0 ) {
		$per_page = 20;
	}

	return array(
		'page'    => $page,
		'perPage' => $per_page,
		'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		'role'    => papelito_admin_users_normalize_enum(
			sanitize_key( (string) $request->get_param( 'role' ) ),
			papelito_admin_users_allowed_roles(),
			'all'
		),
	);
}

/**
 * SQL base da listagem admin de usuarios.
 *
 * @return string
 */
function papelito_admin_users_base_sql(): string {
	global $wpdb;

	$users_table    = $wpdb->users;
	$usermeta_table = $wpdb->usermeta;
	$capabilities   = $wpdb->prefix . 'capabilities';

	return "
		FROM {$users_table} u
		LEFT JOIN {$usermeta_table} cap ON cap.user_id = u.ID AND cap.meta_key = '{$capabilities}'
		LEFT JOIN {$usermeta_table} store_name ON store_name.user_id = u.ID AND store_name.meta_key = 'store_name'
		LEFT JOIN {$usermeta_table} phone_number ON phone_number.user_id = u.ID AND phone_number.meta_key = 'phone_number'
		LEFT JOIN {$usermeta_table} cnpj ON cnpj.user_id = u.ID AND cnpj.meta_key = 'cnpj'
		LEFT JOIN {$usermeta_table} state_meta ON state_meta.user_id = u.ID AND state_meta.meta_key = 'state'
		LEFT JOIN {$usermeta_table} city_meta ON city_meta.user_id = u.ID AND city_meta.meta_key = 'city'
		LEFT JOIN {$usermeta_table} first_name ON first_name.user_id = u.ID AND first_name.meta_key = 'first_name'
		LEFT JOIN {$usermeta_table} last_name ON last_name.user_id = u.ID AND last_name.meta_key = 'last_name'
		LEFT JOIN {$usermeta_table} email_verification_status ON email_verification_status.user_id = u.ID AND email_verification_status.meta_key = 'papelito_email_verification_status'
	";
}

/**
 * Expressao SQL de cobertura valida de vendor.
 *
 * @return string
 */
function papelito_admin_users_coverage_exists_sql(): string {
	global $wpdb;

	return "EXISTS (
		SELECT 1 FROM {$wpdb->usermeta} coverage_min
		WHERE coverage_min.user_id = u.ID
		AND coverage_min.meta_key = 'min_cep'
		AND coverage_min.meta_value <> ''
	) AND EXISTS (
		SELECT 1 FROM {$wpdb->usermeta} coverage_max
		WHERE coverage_max.user_id = u.ID
		AND coverage_max.meta_key = 'max_cep'
		AND coverage_max.meta_value <> ''
	)";
}

/**
 * Clausula WHERE compartilhada.
 *
 * @param array<string, int|string> $filters Filtros.
 * @param array<int, mixed>         $args Parametros preparados.
 * @return string
 */
function papelito_admin_users_where_sql( array $filters, array &$args ): string {
	global $wpdb;

	$conditions      = array( '1=1' );

	if ( ! empty( $filters['search'] ) && is_string( $filters['search'] ) ) {
		$term         = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		$conditions[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR store_name.meta_value LIKE %s OR first_name.meta_value LIKE %s OR last_name.meta_value LIKE %s OR cnpj.meta_value LIKE %s)';
		array_push( $args, $term, $term, $term, $term, $term, $term );
	}

	if ( 'administrator' === $filters['role'] ) {
		$conditions[] = 'cap.meta_value LIKE %s';
		$args[]       = '%"administrator"%';
	} elseif ( 'seller' === $filters['role'] ) {
		$conditions[] = 'cap.meta_value LIKE %s';
		$args[]       = '%"seller"%';
	} elseif ( 'customer' === $filters['role'] ) {
		$conditions[] = 'cap.meta_value NOT LIKE %s';
		$args[]       = '%"administrator"%';
		$conditions[] = 'cap.meta_value LIKE %s';
		$args[] = '%"customer"%';
	} elseif ( 'other' === $filters['role'] ) {
		$conditions[] = 'cap.meta_value NOT LIKE %s';
		$args[]       = '%"administrator"%';
		$conditions[] = 'cap.meta_value NOT LIKE %s';
		$args[]       = '%"customer"%';
		$conditions[] = 'cap.meta_value NOT LIKE %s';
		$args[]       = '%"seller"%';
	}

	return ' WHERE ' . implode( ' AND ', $conditions );
}

/**
 * Conta tickets de suporte relacionados ao usuario.
 *
 * @param int $user_id Usuario alvo.
 * @return int
 */
function papelito_admin_users_support_tickets_count( int $user_id ): int {
	global $wpdb;

	if ( ! function_exists( 'papelito_messaging_tables' ) || $user_id <= 0 ) {
		return 0;
	}

	$tables = papelito_messaging_tables();

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['threads']} WHERE customer_id = %d OR vendor_id = %d",
			$user_id,
			$user_id
		)
	);
}

/**
 * Conta favoritos do usuario.
 *
 * @param int $user_id Usuario alvo.
 * @return int
 */
function papelito_admin_users_favorites_count( int $user_id ): int {
	if ( ! function_exists( 'papelito_get_user_favorites' ) || $user_id <= 0 ) {
		return 0;
	}

	$favorites = papelito_get_user_favorites( $user_id, false );

	return is_array( $favorites ) ? count( $favorites ) : 0;
}

/**
 * Busca pedidos recentes do usuario como customer.
 *
 * @param int $user_id Usuario alvo.
 * @param int $limit Quantidade maxima.
 * @return array<int, object>
 */
function papelito_admin_users_customer_orders( int $user_id, int $limit = 10 ): array {
	if ( ! function_exists( 'wc_get_orders' ) || $user_id <= 0 ) {
		return array();
	}

	$orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'limit'       => max( 1, $limit ),
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);

	return array_values(
		array_filter(
			is_array( $orders ) ? $orders : array(),
			static fn( $order ): bool => function_exists( 'papelito_vendor_dashboard_is_wc_instance' ) && papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' )
		)
	);
}

/**
 * Conta pedidos do usuario como customer.
 *
 * @param int $user_id Usuario alvo.
 * @return int
 */
function papelito_admin_users_customer_orders_count( int $user_id ): int {
	if ( ! function_exists( 'wc_get_orders' ) || $user_id <= 0 ) {
		return 0;
	}

	$query = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'limit'       => 1,
			'page'        => 1,
			'paginate'    => true,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);

	if ( is_object( $query ) ) {
		$data = get_object_vars( $query );
		if ( isset( $data['total'] ) ) {
			return (int) $data['total'];
		}
	}

	if ( is_array( $query ) ) {
		return count( $query );
	}

	return 0;
}

/**
 * Busca vendas recentes do vendor.
 *
 * @param int $user_id Usuario alvo.
 * @param int $limit Quantidade maxima.
 * @return array<int, object>
 */
function papelito_admin_users_sales_orders( int $user_id, int $limit = 10, ?array $all_orders = null ): array {
	if ( $user_id <= 0 ) {
		return array();
	}

	$orders = $all_orders;
	if ( null === $orders ) {
		if ( ! function_exists( 'papelito_vendor_dashboard_orders_for_vendor' ) ) {
			return array();
		}

		$orders = papelito_vendor_dashboard_orders_for_vendor( $user_id );
	}

	$orders = array_values(
		array_filter(
			is_array( $orders ) ? $orders : array(),
			static fn( $order ): bool => papelito_vendor_dashboard_order_is_paid( $order )
		)
	);

	return array_slice( $orders, 0, max( 1, $limit ) );
}

/**
 * Conta vendas do vendor.
 *
 * @param int $user_id Usuario alvo.
 * @return int
 */
function papelito_admin_users_sales_orders_count( int $user_id, ?array $all_orders = null ): int {
	return count( papelito_admin_users_sales_orders( $user_id, PHP_INT_MAX, $all_orders ) );
}

/**
 * Busca cancelamentos recentes de vendas sem restringir a pedidos pagos.
 *
 * @param array<int, object> $all_orders Pedidos brutos do vendor.
 * @param int                $limit Quantidade máxima.
 * @return array<int, object>
 */
function papelito_admin_users_cancelled_sales_orders( array $all_orders, int $limit = 10 ): array {
	return array_values(
		array_filter(
			array_slice( $all_orders, 0, max( 1, $limit ) ),
			static fn( $order ): bool => papelito_admin_users_order_is_cancelled( $order )
		)
	);
}

/**
 * Detecta se a conta tem historico relacionado a vendor.
 *
 * @param WP_User $user Usuario alvo.
 * @return bool
 */
function papelito_admin_users_is_vendor_related( WP_User $user ): bool {
	if ( function_exists( 'papelito_user_has_role' ) ) {
		return papelito_user_has_role( $user, 'seller' );
	}

	return in_array( 'seller', (array) ( $user->roles ?? array() ), true );
}

/**
 * Role principal Papelito para o usuario.
 *
 * @param WP_User $user Usuario alvo.
 * @return string
 */
function papelito_admin_users_primary_role( WP_User $user ): string {
	if ( function_exists( 'papelito_auth_normalize_primary_role' ) ) {
		return papelito_auth_normalize_primary_role( $user );
	}

	return isset( $user->roles[0] ) ? sanitize_key( (string) $user->roles[0] ) : 'other';
}

/**
 * Label amigavel da role.
 *
 * @param string $role Role principal.
 * @return string
 */
function papelito_admin_users_role_label( string $role ): string {
	if ( function_exists( 'papelito_admin_reports_role_label' ) ) {
		return papelito_admin_reports_role_label( $role );
	}

	switch ( $role ) {
		case 'administrator':
			return 'Administrador';
		case 'seller':
			return 'Vendor';
		case 'customer':
			return 'Customer';
		default:
			return 'Outro';
	}
}

/**
 * Status de conta usado pelo painel admin.
 *
 * @param WP_User $user Usuario alvo.
 * @return string
 */
function papelito_admin_users_account_status( WP_User $user ): string {
	$email_status = sanitize_key( (string) get_user_meta( $user->ID, 'papelito_email_verification_status', true ) );
	if ( 'pending' === $email_status ) {
		return 'email_pending';
	}

	if ( user_can( $user, 'manage_options' ) ) {
		return 'admin_active';
	}

	if ( function_exists( 'papelito_user_is_effective_seller' ) && papelito_user_is_effective_seller( $user ) ) {
		return 'vendor_active';
	}

	return 'active';
}

/**
 * Label amigavel do status da conta.
 *
 * @param string $status Status normalizado.
 * @return string
 */
function papelito_admin_users_account_status_label( string $status ): string {
	switch ( $status ) {
		case 'email_pending':
			return 'Email pendente';
		case 'vendor_pending':
			return 'Vendor pendente';
		case 'vendor_rejected':
			return 'Vendor rejeitado';
		case 'vendor_active':
			return 'Vendor ativo';
		case 'admin_active':
			return 'Admin ativo';
		default:
			return 'Ativa';
	}
}

/**
 * Monta a origem do relacionamento do pedido.
 *
 * @param string $relationship purchase|sale.
 * @return string
 */
function papelito_admin_users_relationship_label( string $relationship ): string {
	return 'sale' === $relationship ? 'Venda' : 'Compra';
}

/**
 * Indica se um pedido deve ser tratado como cancelado no painel admin.
 *
 * @param object                     $order Pedido WooCommerce.
 * @param array<string, mixed>|null  $mapped Snapshot opcional do pedido.
 * @return bool
 */
function papelito_admin_users_order_is_cancelled( $order, ?array $mapped = null ): bool {
	$vendor_status = '';

	if ( is_array( $mapped ) && isset( $mapped['vendor_status'] ) ) {
		$vendor_status = sanitize_key( (string) $mapped['vendor_status'] );
	} elseif ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
		$vendor_status = sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) );
	}

	if ( 'cancelado' === $vendor_status ) {
		return true;
	}

	$wc_status = is_object( $order ) && method_exists( $order, 'get_status' )
		? sanitize_key( (string) $order->get_status() )
		: '';

	return in_array( $wc_status, array( 'cancelled', 'refunded', 'failed' ), true );
}

/**
 * Extrai o vendor operacional de um pedido.
 *
 * @param object $order Pedido WooCommerce.
 * @return int
 */
function papelito_admin_users_order_vendor_id( $order ): int {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return 0;
	}

	$vendor_id = absint( $order->get_meta( '_papelito_vendor_id', true ) );
	if ( $vendor_id > 0 ) {
		return $vendor_id;
	}

	if ( method_exists( $order, 'get_items' ) ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
				$item_vendor_id = absint( $item->get_meta( '_vendor_id', true ) );
				if ( $item_vendor_id > 0 ) {
					return $item_vendor_id;
				}
			}
		}
	}

	return 0;
}

/**
 * Mapeia um pedido relacionado ao usuario.
 *
 * @param object $order Pedido WooCommerce.
 * @param string $relationship purchase|sale.
 * @param int    $viewer_user_id Usuario alvo.
 * @return array<string, mixed>
 */
function papelito_admin_users_map_related_order( $order, string $relationship, int $viewer_user_id ): array {
	$mapped = function_exists( 'papelito_vendor_dashboard_map_order' )
		? papelito_vendor_dashboard_map_order( $order, 'sale' === $relationship ? $viewer_user_id : null, true )
		: array();

	$wc_status = is_object( $order ) && method_exists( $order, 'get_status' )
		? sanitize_key( (string) $order->get_status() )
		: '';
	$vendor_status = sanitize_key( (string) ( $mapped['vendor_status'] ?? '' ) );
	$cancel_reason = is_object( $order ) && method_exists( $order, 'get_meta' )
		? sanitize_textarea_field( (string) $order->get_meta( '_papelito_vendor_cancel_reason', true ) )
		: '';

	return array(
		'id'                => isset( $mapped['id'] ) ? (int) $mapped['id'] : ( is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0 ),
		'orderNumber'       => isset( $mapped['order_number'] ) ? (string) $mapped['order_number'] : '',
		'createdAt'         => isset( $mapped['created_at'] ) ? (string) $mapped['created_at'] : '',
		'customerName'      => isset( $mapped['customer_name'] ) ? (string) $mapped['customer_name'] : '',
		'itemsCount'        => isset( $mapped['items_count'] ) ? (int) $mapped['items_count'] : 0,
		'itemsLabel'        => isset( $mapped['items_label'] ) ? (string) $mapped['items_label'] : '',
		'total'             => isset( $mapped['total'] ) ? (float) $mapped['total'] : 0.0,
		'relationship'      => $relationship,
		'relationshipLabel' => papelito_admin_users_relationship_label( $relationship ),
		'status'            => $wc_status,
		'vendorStatus'      => $vendor_status,
		'cancelReason'      => $cancel_reason,
		'isCancelled'       => papelito_admin_users_order_is_cancelled( $order, $mapped ),
	);
}

/**
 * Resumo de metricas brutas do usuario.
 *
 * @param WP_User $user Usuario alvo.
 * @return array<string, int>
 */
function papelito_admin_users_metrics( WP_User $user, ?array $vendor_orders = null ): array {
	$user_id          = (int) $user->ID;
	$purchases_count  = papelito_admin_users_customer_orders_count( $user_id );
	$favorites_count  = papelito_admin_users_favorites_count( $user_id );
	$tickets_count    = papelito_admin_users_support_tickets_count( $user_id );
	$cancelled_orders = 0;
	$vendor_orders    = null === $vendor_orders && function_exists( 'papelito_vendor_dashboard_orders_for_vendor' )
		? papelito_vendor_dashboard_orders_for_vendor( $user_id )
		: ( is_array( $vendor_orders ) ? $vendor_orders : array() );
	$sales_count      = papelito_admin_users_sales_orders_count( $user_id, $vendor_orders );

	foreach ( papelito_admin_users_customer_orders( $user_id, 20 ) as $order ) {
		if ( papelito_admin_users_order_is_cancelled( $order ) ) {
			++$cancelled_orders;
		}
	}

	foreach ( array_slice( $vendor_orders, 0, 20 ) as $order ) {
		if ( papelito_admin_users_order_is_cancelled( $order ) ) {
			++$cancelled_orders;
		}
	}

	return array(
		'ordersCount'         => $purchases_count,
		'purchasesCount'      => $purchases_count,
		'salesCount'          => $sales_count,
		'favoritesCount'      => $favorites_count,
		'supportTicketsCount' => $tickets_count,
		'cancelledOrdersCount' => $cancelled_orders,
	);
}

/**
 * Monta linha da listagem admin.
 *
 * @param array<string, mixed> $row Linha SQL.
 * @return array<string, mixed>
 */
function papelito_admin_users_map_row( array $row ): array {
	$user_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
	$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

	if ( ! $user instanceof WP_User ) {
		return array();
	}

	$display_name = isset( $row['display_name'] ) ? trim( (string) $row['display_name'] ) : '';
	$first_name   = isset( $row['first_name'] ) ? trim( (string) $row['first_name'] ) : '';
	$last_name    = isset( $row['last_name'] ) ? trim( (string) $row['last_name'] ) : '';
	$name         = $display_name;

	if ( '' === $name ) {
		$name = trim( $first_name . ' ' . $last_name );
	}

	if ( '' === $name ) {
		$name = (string) $user->user_email;
	}

	$role          = papelito_admin_users_primary_role( $user );
	$account_state = papelito_admin_users_account_status( $user );
	$metrics       = papelito_admin_users_metrics( $user );

	return array(
		'id'                 => $user_id,
		'name'               => $name,
		'email'              => (string) $user->user_email,
		'role'               => $role,
		'roleLabel'          => papelito_admin_users_role_label( $role ),
		'accountStatus'      => $account_state,
		'accountStatusLabel' => papelito_admin_users_account_status_label( $account_state ),
		'registeredAt'       => (string) $user->user_registered,
		'isVendor'           => papelito_admin_users_is_vendor_related( $user ),
		'ordersCount'        => $metrics['ordersCount'],
		'purchasesCount'     => $metrics['purchasesCount'],
		'salesCount'         => $metrics['salesCount'],
		'favoritesCount'     => $metrics['favoritesCount'],
		'supportTicketsCount' => $metrics['supportTicketsCount'],
	);
}

/**
 * Consulta linhas da listagem.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return array<int, array<string, mixed>>
 */
function papelito_admin_users_query_rows( array $filters, ?int $limit = null, ?int $offset = null ): array {
	global $wpdb;

	$args            = array();
	$base_sql        = papelito_admin_users_base_sql();
	$where_sql       = papelito_admin_users_where_sql( $filters, $args );
	$coverage_exists = papelito_admin_users_coverage_exists_sql();
	$limit           = null === $limit ? (int) $filters['perPage'] : max( 0, $limit );
	$offset          = null === $offset ? ( (int) $filters['page'] - 1 ) * (int) $filters['perPage'] : max( 0, $offset );

	if ( 0 === $limit ) {
		return array();
	}

	$args[] = $limit;
	$args[] = $offset;

	$select = "
		SELECT
			u.ID AS id,
			u.display_name AS display_name,
			u.user_email AS user_email,
			u.user_registered AS user_registered,
			COALESCE(cap.meta_value, '') AS capabilities,
			COALESCE(store_name.meta_value, '') AS store_name,
			COALESCE(phone_number.meta_value, '') AS phone_number,
			COALESCE(cnpj.meta_value, '') AS cnpj,
			COALESCE(state_meta.meta_value, '') AS state,
			COALESCE(city_meta.meta_value, '') AS city,
			COALESCE(first_name.meta_value, '') AS first_name,
			COALESCE(last_name.meta_value, '') AS last_name,
			COALESCE(email_verification_status.meta_value, '') AS email_verification_status,
			CASE WHEN {$coverage_exists} THEN 1 ELSE 0 END AS has_coverage
	";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		$select . $base_sql . $where_sql . ' ORDER BY u.user_registered DESC, u.ID DESC LIMIT %d OFFSET %d',
		$args
	);

	$raw_rows = $wpdb->get_results( $sql, ARRAY_A );
	$rows     = array();

	foreach ( $raw_rows as $raw_row ) {
		$mapped = papelito_admin_users_map_row( $raw_row );
		if ( ! empty( $mapped ) ) {
			$rows[] = $mapped;
		}
	}

	return $rows;
}

/**
 * Monta linhas de candidaturas que ainda não possuem conta WordPress.
 *
 * @param array<string, int|string> $filters Filtros da listagem.
 * @return array<int, array<string, mixed>>
 */
function papelito_admin_users_pending_pre_account_rows( array $filters ): array {
	if ( 'all' !== $filters['role'] || ! function_exists( 'papelito_pre_account_application_admin_list' ) ) {
		return array();
	}

	$search       = trim( (string) $filters['search'] );
	$applications = papelito_pre_account_application_admin_list( 'pending_manual_review' );
	$rows         = array();

	foreach ( $applications as $application ) {
		if ( ! is_array( $application ) ) {
			continue;
		}

		$searchable = implode(
			' ',
			array(
				(string) ( $application['fullName'] ?? '' ),
				(string) ( $application['email'] ?? '' ),
				(string) ( $application['companyName'] ?? '' ),
				(string) ( $application['cnpj'] ?? '' ),
			)
		);
		$normalized_search     = preg_replace( '/\D+/', '', $search ) ?: $search;
		$normalized_searchable = preg_replace( '/\D+/', '', $searchable ) ?: $searchable;

		if (
			'' !== $search &&
			false === stripos( $searchable, $search ) &&
			false === stripos( $normalized_searchable, $normalized_search )
		) {
			continue;
		}

		$application_id = (string) ( $application['applicationId'] ?? '' );
		if ( ! preg_match( '/^pre:\d+$/', $application_id ) ) {
			continue;
		}

		$rows[] = array(
			'id'                  => $application_id,
			'recordType'          => 'pre_account_application',
			'name'                => (string) ( $application['fullName'] ?? '' ),
			'email'               => (string) ( $application['email'] ?? '' ),
			'role'                => 'pre_account_application',
			'roleLabel'           => 'Candidatura pré-conta',
			'accountStatus'       => 'pending_manual_review',
			'accountStatusLabel'  => 'Sob análise',
			'registeredAt'        => (string) ( $application['submittedAt'] ?? $application['createdAt'] ?? '' ),
			'isVendor'            => false,
			'ordersCount'         => 0,
			'purchasesCount'      => 0,
			'salesCount'          => 0,
			'favoritesCount'      => 0,
			'supportTicketsCount' => 0,
		);
	}

	return $rows;
}

/**
 * Resume totais da listagem.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return array<string, int>
 */
function papelito_admin_users_query_summary( array $filters ): array {
	global $wpdb;

	$args            = array();
	$base_sql        = papelito_admin_users_base_sql();
	$where_sql       = papelito_admin_users_where_sql( $filters, $args );
	$coverage_exists = papelito_admin_users_coverage_exists_sql();

	$args[] = '%"administrator"%';
	$args[] = '%"seller"%';
	$args[] = '%"customer"%';
	$args[] = '%"administrator"%';
	$args[] = '%"customer"%';
	$args[] = '%"seller"%';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		"
		SELECT
			COUNT(*) AS total_users,
			SUM(CASE WHEN cap.meta_value LIKE %s THEN 1 ELSE 0 END) AS admins_count,
			SUM(CASE WHEN cap.meta_value LIKE %s THEN 1 ELSE 0 END) AS sellers_count,
			SUM(CASE WHEN cap.meta_value LIKE %s THEN 1 ELSE 0 END) AS customers_count,
			SUM(CASE WHEN cap.meta_value NOT LIKE %s AND cap.meta_value NOT LIKE %s AND cap.meta_value NOT LIKE %s THEN 1 ELSE 0 END) AS others_count
		" . $base_sql . $where_sql,
		$args
	);

	$summary = $wpdb->get_row( $sql, ARRAY_A );

	return array(
		'totalUsers'  => isset( $summary['total_users'] ) ? (int) $summary['total_users'] : 0,
		'adminsCount' => isset( $summary['admins_count'] ) ? (int) $summary['admins_count'] : 0,
		'sellersCount' => isset( $summary['sellers_count'] ) ? (int) $summary['sellers_count'] : 0,
		'customersCount' => isset( $summary['customers_count'] ) ? (int) $summary['customers_count'] : 0,
		'othersCount' => isset( $summary['others_count'] ) ? (int) $summary['others_count'] : 0,
	);
}

/**
 * Snapshot da listagem admin.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return array<string, mixed>
 */
function papelito_admin_users_get_snapshot( array $filters ): array {
	$summary          = papelito_admin_users_query_summary( $filters );
	$pre_account_rows = papelito_admin_users_pending_pre_account_rows( $filters );
	$pre_account_total = count( $pre_account_rows );
	$total_rows       = $summary['totalUsers'] + $pre_account_total;
	$total_pages = max( 1, (int) ceil( $total_rows / max( 1, (int) $filters['perPage'] ) ) );
	$safe_page   = min( max( 1, (int) $filters['page'] ), $total_pages );

	if ( $safe_page !== (int) $filters['page'] ) {
		$filters['page'] = $safe_page;
	}

	$offset          = ( $safe_page - 1 ) * (int) $filters['perPage'];
	$pre_account_page = array_slice( $pre_account_rows, $offset, (int) $filters['perPage'] );
	$remaining        = (int) $filters['perPage'] - count( $pre_account_page );
	$user_offset      = max( 0, $offset - $pre_account_total );
	$user_rows        = papelito_admin_users_query_rows( $filters, $remaining, $user_offset );

	return array(
		'rows'        => array_merge( $pre_account_page, $user_rows ),
		'summary'     => $summary,
		'currentPage' => $safe_page,
		'perPage'     => (int) $filters['perPage'],
		'totalRows'   => $total_rows,
		'totalPages'  => $total_pages,
		'issues'      => array(),
	);
}

/**
 * Dados basicos do usuario.
 *
 * @param WP_User $user Usuario alvo.
 * @return array<string, mixed>
 */
function papelito_admin_users_base_detail( WP_User $user ): array {
	$role          = papelito_admin_users_primary_role( $user );
	$account_state = papelito_admin_users_account_status( $user );

	return array(
		'id'                 => (int) $user->ID,
		'name'               => trim( (string) $user->display_name ),
		'displayName'        => (string) $user->display_name,
		'email'              => (string) $user->user_email,
		'firstName'          => (string) get_user_meta( $user->ID, 'first_name', true ),
		'lastName'           => (string) get_user_meta( $user->ID, 'last_name', true ),
		'storeName'          => (string) get_user_meta( $user->ID, 'store_name', true ),
		'phoneNumber'        => (string) get_user_meta( $user->ID, 'phone_number', true ),
		'cnpj'               => (string) get_user_meta( $user->ID, 'cnpj', true ),
		'instagram'          => (string) get_user_meta( $user->ID, 'instagram', true ),
		'state'              => (string) get_user_meta( $user->ID, 'state', true ),
		'city'               => (string) get_user_meta( $user->ID, 'city', true ),
		'cep'                => (string) get_user_meta( $user->ID, 'cep', true ),
		'street'             => defined( 'PAPELITO_VENDOR_APPLICATION_STREET_META' ) ? (string) get_user_meta( $user->ID, PAPELITO_VENDOR_APPLICATION_STREET_META, true ) : '',
		'number'             => defined( 'PAPELITO_VENDOR_APPLICATION_NUMBER_META' ) ? (string) get_user_meta( $user->ID, PAPELITO_VENDOR_APPLICATION_NUMBER_META, true ) : '',
		'complement'         => defined( 'PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META' ) ? (string) get_user_meta( $user->ID, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, true ) : '',
		'neighborhood'       => defined( 'PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META' ) ? (string) get_user_meta( $user->ID, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, true ) : '',
		'role'               => $role,
		'roleLabel'          => papelito_admin_users_role_label( $role ),
		'roles'              => array_values( array_map( 'sanitize_key', (array) $user->roles ) ),
		'accountStatus'      => $account_state,
		'accountStatusLabel' => papelito_admin_users_account_status_label( $account_state ),
		'registeredAt'       => (string) $user->user_registered,
		'isVendor'           => papelito_admin_users_is_vendor_related( $user ),
		'emailVerificationStatus' => (string) get_user_meta( $user->ID, 'papelito_email_verification_status', true ),
	);
}

/**
 * Acoes disponiveis para o admin logado.
 *
 * @param WP_User $target Usuario alvo.
 * @param int     $viewer_id Admin logado.
 * @return array<string, mixed>
 */
function papelito_admin_users_available_actions( WP_User $target, int $viewer_id ): array {
	$current_role = papelito_admin_users_primary_role( $target );
	$is_self      = $viewer_id === (int) $target->ID;

	return array(
		'isSelf'                    => $is_self,
		'currentRole'               => $current_role,
		'canPromoteToAdministrator' => in_array( $current_role, array( 'customer', 'seller' ), true ),
		'canConvertSellerToCustomer' => 'seller' === $current_role,
		'canDemoteAdministrator'    => 'administrator' === $current_role && ! $is_self,
		'canUseVendorRedirect'      => in_array( $current_role, array( 'customer', 'administrator' ), true ),
		'canCancelOrders'           => true,
	);
}

/**
 * Carrega detalhe administrativo do usuario.
 *
 * @param int $user_id Usuario alvo.
 * @return array<string, mixed>|WP_Error
 */
function papelito_admin_users_get_detail( int $user_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_admin_user_not_found', 'Usuario nao encontrado.', array( 'status' => 404 ) );
	}

	$base            = papelito_admin_users_base_detail( $user );
	$vendor_orders   = function_exists( 'papelito_vendor_dashboard_orders_for_vendor' )
		? papelito_vendor_dashboard_orders_for_vendor( $user_id )
		: array();
	$metrics         = papelito_admin_users_metrics( $user, $vendor_orders );
	$recent_purchase_orders = papelito_admin_users_customer_orders( $user_id, 10 );
	$recent_sales_orders    = papelito_admin_users_sales_orders( $user_id, 10, $vendor_orders );
	$recent_cancelled_sales_orders = papelito_admin_users_cancelled_sales_orders( $vendor_orders, 10 );

	$recent_purchases = array_map(
		static fn( $order ): array => papelito_admin_users_map_related_order( $order, 'purchase', $user_id ),
		$recent_purchase_orders
	);
	$recent_sales = array_map(
		static fn( $order ): array => papelito_admin_users_map_related_order( $order, 'sale', $user_id ),
		$recent_sales_orders
	);
	$recent_cancelled_sales = array_map(
		static fn( $order ): array => papelito_admin_users_map_related_order( $order, 'sale', $user_id ),
		$recent_cancelled_sales_orders
	);

	$cancelled_orders = array();
	foreach ( array_merge( $recent_purchases, $recent_cancelled_sales ) as $order ) {
		if ( ! empty( $order['isCancelled'] ) ) {
			$cancelled_orders[ (int) $order['id'] ] = $order;
		}
	}

	$vendor_data = null;
	if ( papelito_admin_users_is_vendor_related( $user ) && function_exists( 'papelito_get_vendor_application_detail' ) ) {
		$raw_vendor_data = papelito_get_vendor_application_detail( $user_id );
		$vendor_data     = is_wp_error( $raw_vendor_data ) ? null : $raw_vendor_data;
	}

	return array_merge(
		$base,
		array(
			'metrics'         => $metrics,
			'recentPurchases' => array_values( $recent_purchases ),
			'recentSales'     => array_values( $recent_sales ),
			'cancelledOrders' => array_values( $cancelled_orders ),
			'vendorData'      => $vendor_data,
			'availableActions' => papelito_admin_users_available_actions( $user, get_current_user_id() ),
		)
	);
}

/**
 * Aplica transicao direta de role permitida.
 *
 * @param int    $target_user_id Usuario alvo.
 * @param int    $viewer_user_id Admin logado.
 * @param string $target_role Role final.
 * @return array<string, mixed>|WP_Error
 */
function papelito_admin_users_change_role( int $target_user_id, int $viewer_user_id, string $target_role ) {
	$user = get_userdata( $target_user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_admin_user_not_found', 'Usuario nao encontrado.', array( 'status' => 404 ) );
	}

	$target_role  = sanitize_key( $target_role );
	$current_role = papelito_admin_users_primary_role( $user );

	if ( ! in_array( $target_role, array( 'administrator', 'customer' ), true ) ) {
		return new WP_Error( 'papelito_admin_invalid_role_target', 'Role alvo invalida para esta tela.', array( 'status' => 422 ) );
	}

	if ( 'administrator' === $current_role && $viewer_user_id === $target_user_id && 'customer' === $target_role ) {
		return new WP_Error( 'papelito_admin_self_demote_forbidden', 'Voce nao pode remover a propria permissao de admin.', array( 'status' => 409 ) );
	}

	$allowed = false;

	if ( 'customer' === $current_role && 'administrator' === $target_role ) {
		$allowed = true;
	}

	if ( 'seller' === $current_role && in_array( $target_role, array( 'customer', 'administrator' ), true ) ) {
		$allowed = true;
	}

	if ( 'administrator' === $current_role && 'customer' === $target_role && $viewer_user_id !== $target_user_id ) {
		$allowed = true;
	}

	if ( ! $allowed ) {
		return new WP_Error( 'papelito_admin_role_transition_forbidden', 'Transicao de role nao permitida nesta tela.', array( 'status' => 422 ) );
	}

	$user->set_role( $target_role );

	if ( 'seller' === $current_role && defined( 'PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META' ) && defined( 'PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META' ) ) {
		update_user_meta( $target_user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META, current_time( 'mysql', true ) );
		update_user_meta( $target_user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META, $viewer_user_id );
	}

	return papelito_admin_users_get_detail( $target_user_id );
}

/**
 * Cancela operacionalmente um pedido relacionado ao usuario.
 *
 * @param int    $user_id Usuario alvo da tela.
 * @param int    $order_id Pedido alvo.
 * @param string $reason Motivo.
 * @return array<string, mixed>|WP_Error
 */
function papelito_admin_users_cancel_order( int $user_id, int $order_id, string $reason ) {
	if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'papelito_vendor_dashboard_update_order_status' ) ) {
		return new WP_Error( 'papelito_admin_cancel_unavailable', 'Cancelamento operacional indisponivel.', array( 'status' => 500 ) );
	}

	$reason = sanitize_textarea_field( $reason );
	if ( '' === $reason ) {
		return new WP_Error( 'papelito_admin_cancel_reason_required', 'Informe o motivo do cancelamento.', array( 'status' => 422 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
		return new WP_Error( 'papelito_admin_order_not_found', 'Pedido nao encontrado.', array( 'status' => 404 ) );
	}

	$customer_id = (int) $order->get_customer_id();
	$vendor_id   = papelito_admin_users_order_vendor_id( $order );

	if ( $customer_id !== $user_id && $vendor_id !== $user_id ) {
		return new WP_Error( 'papelito_admin_order_user_mismatch', 'Pedido nao relacionado ao usuario selecionado.', array( 'status' => 404 ) );
	}

	if ( $vendor_id <= 0 ) {
		return new WP_Error( 'papelito_admin_order_vendor_missing', 'Pedido sem vendor operacional para cancelamento.', array( 'status' => 409 ) );
	}

	$result = papelito_vendor_dashboard_update_order_status(
		$order_id,
		$vendor_id,
		'cancelado',
		$reason
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'order' => $result,
		'user'  => papelito_admin_users_get_detail( $user_id ),
	);
}

/**
 * Manually mark a pending user's e-mail as verified (admin action).
 *
 * Sets status to 'verified' (same target as real confirmation, which unblocks
 * the login gate) and records provenance so this is distinguishable from a
 * user-driven confirmation. Idempotent guard: only acts on pending users.
 *
 * @param int $user_id Target user ID.
 * @return array|WP_Error Updated admin-user detail, or error.
 */
function papelito_admin_users_activate_email( int $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_admin_user_not_found', 'Usuario nao encontrado.', array( 'status' => 404 ) );
	}

	if ( ! papelito_auth_requires_email_verification( $user->ID ) ) {
		return new WP_Error( 'papelito_email_not_pending', 'Conta nao esta com e-mail pendente.', array( 'status' => 409 ) );
	}

	papelito_auth_mark_email_verified( $user->ID );

	update_user_meta( $user->ID, 'papelito_email_verification_method', 'admin' );
	update_user_meta( $user->ID, 'papelito_email_verified_by', get_current_user_id() );

	my_plugin_log_json( array(
		'timestamp' => gmdate( 'c' ),
		'action'    => 'admin_email_verified',
		'user_id'   => $user->ID,
		'admin_id'  => get_current_user_id(),
	) );

	return papelito_admin_users_get_detail( $user->ID );
}

/**
 * Permission callback compartilhado.
 *
 * @return bool
 */
function papelito_admin_users_require_admin(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * GET /admin/users
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function papelito_admin_users_handle_list( WP_REST_Request $request ): WP_REST_Response {
	$filters = papelito_admin_users_parse_filters( $request );

	return new WP_REST_Response( papelito_admin_users_get_snapshot( $filters ), 200 );
}

/**
 * GET /admin/users/{id}
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function papelito_admin_users_handle_get( WP_REST_Request $request ) {
	$detail = papelito_admin_users_get_detail( absint( $request->get_param( 'id' ) ) );

	return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, 200 );
}

/**
 * POST /admin/users/{id}/role
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function papelito_admin_users_handle_change_role( WP_REST_Request $request ) {
	$body   = $request->get_json_params();
	$target = is_array( $body ) ? (string) ( $body['role'] ?? '' ) : '';
	$result = papelito_admin_users_change_role(
		absint( $request->get_param( 'id' ) ),
		get_current_user_id(),
		$target
	);

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * POST /admin/users/{id}/orders/{orderId}/cancel
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function papelito_admin_users_handle_cancel_order( WP_REST_Request $request ) {
	$body   = $request->get_json_params();
	$reason = is_array( $body ) ? (string) ( $body['reason'] ?? '' ) : '';
	$result = papelito_admin_users_cancel_order(
		absint( $request->get_param( 'id' ) ),
		absint( $request->get_param( 'orderId' ) ),
		$reason
	);

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1/admin',
			'/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_admin_users_require_admin',
				'callback'            => 'papelito_admin_users_handle_list',
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/users/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_admin_users_require_admin',
				'callback'            => 'papelito_admin_users_handle_get',
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/users/(?P<id>\d+)/role',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_admin_users_require_admin',
				'callback'            => 'papelito_admin_users_handle_change_role',
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/users/(?P<id>\d+)/activate-email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_admin_users_require_admin',
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_admin_users_activate_email( absint( $request->get_param( 'id' ) ) );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/users/(?P<id>\d+)/orders/(?P<orderId>\d+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_admin_users_require_admin',
				'callback'            => 'papelito_admin_users_handle_cancel_order',
			)
		);
	}
);
