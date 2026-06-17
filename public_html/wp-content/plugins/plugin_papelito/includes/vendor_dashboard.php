<?php
/**
 * Operational vendor panel and customer order read models.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT' ) ) {
	define( 'PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT', 'aguardando_pagamento' );
	define( 'PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT', 'aguardando_envio' );
	define( 'PAPELITO_VENDOR_STATUS_PICKING', 'em_separacao' );
	define( 'PAPELITO_VENDOR_STATUS_SHIPPED', 'enviado' );
	define( 'PAPELITO_VENDOR_STATUS_DELIVERED', 'entregue' );
	define( 'PAPELITO_VENDOR_STATUS_CANCELLED', 'cancelado' );
}

/**
 * Verifica se um valor e uma instancia WooCommerce esperada.
 *
 * @param mixed  $value Valor inspecionado.
 * @param string $class Nome da classe WooCommerce.
 * @return bool
 */
function papelito_vendor_dashboard_is_wc_instance( $value, string $class ): bool {
	return class_exists( $class ) && is_object( $value ) && is_a( $value, $class );
}

/**
 * Allowed operational fulfillment statuses.
 *
 * @return array<int,string>
 */
function papelito_vendor_dashboard_statuses(): array {
	return array(
		PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT,
		PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT,
		PAPELITO_VENDOR_STATUS_PICKING,
		PAPELITO_VENDOR_STATUS_SHIPPED,
		PAPELITO_VENDOR_STATUS_DELIVERED,
		PAPELITO_VENDOR_STATUS_CANCELLED,
	);
}

/**
 * Fulfillment statuses that represent a confirmed sale (payment approved).
 * Orders awaiting payment or cancelled never count toward revenue/KPIs.
 *
 * @return array<int,string>
 */
function papelito_vendor_dashboard_sale_statuses(): array {
	return array(
		PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT,
		PAPELITO_VENDOR_STATUS_PICKING,
		PAPELITO_VENDOR_STATUS_SHIPPED,
		PAPELITO_VENDOR_STATUS_DELIVERED,
	);
}

/**
 * Defense-in-depth payment gate for KPIs. An order only counts as a sale when
 * the payment is actually confirmed. Trusts the WooCommerce order status
 * (paid-bearing statuses) and/or the persisted Pagar.me charge state, so that
 * legacy orders wrongly stamped with a fulfillment status before payment do
 * not inflate revenue.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_vendor_dashboard_order_is_paid( $order ): bool {
	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) ) {
		return false;
	}

	if ( method_exists( $order, 'get_status' ) ) {
		$wc_status = sanitize_key( (string) $order->get_status() );

		if ( in_array( $wc_status, array( 'processing', 'completed', 'refunded' ), true ) ) {
			return true;
		}

		if ( in_array( $wc_status, array( 'pending', 'failed', 'cancelled', 'on-hold', 'checkout-draft' ), true ) ) {
			return false;
		}
	}

	if ( function_exists( 'papelito_pagarme_payment_state_is_paid' ) ) {
		$pagarme_state = sanitize_key( (string) $order->get_meta( '_papelito_pagarme_payment_state', true ) );

		if ( '' !== $pagarme_state ) {
			return papelito_pagarme_payment_state_is_paid( $pagarme_state );
		}
	}

	return false;
}

/**
 * Normalize an operational fulfillment status.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_vendor_dashboard_order_status( $order ): string {
	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) ) {
		return PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT;
	}

	$status = sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) );
	$status = in_array( $status, papelito_vendor_dashboard_statuses(), true )
		? $status
		: PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT;

	if ( PAPELITO_VENDOR_STATUS_CANCELLED === $status ) {
		return $status;
	}

	if ( ! papelito_vendor_dashboard_order_is_paid( $order ) ) {
		return PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT;
	}

	return $status;
}

/**
 * Require an authenticated seller.
 *
 * @return WP_User|WP_Error
 */
function papelito_vendor_dashboard_require_seller() {
	$user = wp_get_current_user();

	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'papelito_vendor_auth_required', 'Nao autenticado.', array( 'status' => 401 ) );
	}

	if ( ! in_array( 'seller', (array) $user->roles, true ) ) {
		return new WP_Error( 'papelito_vendor_forbidden', 'Acesso restrito a vendors.', array( 'status' => 403 ) );
	}

	return $user;
}

/**
 * Require an authenticated profile owner.
 *
 * @return WP_User|WP_Error
 */
function papelito_vendor_dashboard_require_profile_user() {
	$user = wp_get_current_user();

	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'papelito_profile_auth_required', 'Nao autenticado.', array( 'status' => 401 ) );
	}

	return $user;
}

/**
 * Determine whether a seller fulfills an order.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_vendor_dashboard_order_belongs_to_vendor( $order, int $vendor_id ): bool {
	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) ) {
		return false;
	}

	if ( absint( $order->get_meta( '_papelito_vendor_id', true ) ) === $vendor_id ) {
		return true;
	}

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			continue;
		}

		if ( absint( $item->get_meta( '_vendor_id', true ) ) === $vendor_id ) {
			return true;
		}
	}

	return false;
}

/**
 * Get order items visible to one vendor.
 *
 * @param object   $order Pedido WooCommerce.
 * @return array<int,array<string,mixed>>
 */
function papelito_vendor_dashboard_items( $order, ?int $vendor_id = null ): array {
	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) ) {
		return array();
	}

	$items = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) || ! method_exists( $item, 'get_id' ) || ! method_exists( $item, 'get_product_id' ) || ! method_exists( $item, 'get_name' ) || ! method_exists( $item, 'get_quantity' ) || ! method_exists( $item, 'get_total' ) ) {
			continue;
		}

		$item_vendor_id = absint( $item->get_meta( '_vendor_id', true ) ) ?: absint( $order->get_meta( '_papelito_vendor_id', true ) );

		if ( null !== $vendor_id && $item_vendor_id > 0 && $item_vendor_id !== $vendor_id ) {
			continue;
		}

		$items[] = array(
			'item_id'    => (int) $item->get_id(),
			'product_id' => (int) $item->get_product_id(),
			'name'       => sanitize_text_field( (string) $item->get_name() ),
			'qty'        => (int) $item->get_quantity(),
			'total'      => (float) $item->get_total(),
		);
	}

	return $items;
}

/**
 * Map order data shared by seller and customer UIs.
 *
 * @param object $order Pedido WooCommerce.
 * @return array<string,mixed>
 */
function papelito_vendor_dashboard_map_order( $order, ?int $vendor_id = null, bool $detail = false ): array {
	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) ) {
		return array();
	}

	$items       = papelito_vendor_dashboard_items( $order, $vendor_id );
	$created_at  = $order->get_date_created();
	$vendor_name = sanitize_text_field( (string) $order->get_meta( '_papelito_vendor_name', true ) );

	$result = array(
		'id'             => (int) $order->get_id(),
		'order_number'   => (string) $order->get_order_number(),
		'created_at'     => $created_at ? $created_at->date_i18n( 'Y-m-d H:i:s' ) : '',
		'customer_name'  => sanitize_text_field( (string) $order->get_formatted_billing_full_name() ),
		'total'          => (float) $order->get_total(),
		'items_count'    => array_sum( array_map( static fn( array $item ): int => (int) $item['qty'], $items ) ),
		'items_label'    => implode( ', ', array_slice( array_column( $items, 'name' ), 0, 2 ) ),
		'vendor_name'    => $vendor_name,
		'vendor_status'  => papelito_vendor_dashboard_order_status( $order ),
		'payment_method' => sanitize_text_field( (string) $order->get_payment_method_title() ),
	);

	if ( ! $detail ) {
		return $result;
	}

	$result['items'] = $items;
	$result['subtotal'] = (float) $order->get_subtotal();
	$result['shipping_total'] = (float) $order->get_shipping_total();
	$result['phone'] = sanitize_text_field( (string) $order->get_billing_phone() );
	$result['shipping_address'] = array(
		'address_1' => sanitize_text_field( (string) $order->get_shipping_address_1() ),
		'address_2' => sanitize_text_field( (string) $order->get_shipping_address_2() ),
		'city'      => sanitize_text_field( (string) $order->get_shipping_city() ),
		'state'     => sanitize_text_field( (string) $order->get_shipping_state() ),
		'postcode'  => sanitize_text_field( (string) $order->get_shipping_postcode() ),
	);
	$result['shipping_service'] = sanitize_text_field( (string) $order->get_meta( '_papelito_shipping_service_name', true ) );
	$result['delivery_time_days'] = absint( $order->get_meta( '_papelito_shipping_delivery_time', true ) );
	$paid_at             = $order->get_date_paid();
	$result['paid_at']   = $paid_at ? $paid_at->date_i18n( 'Y-m-d H:i:s' ) : '';
	$result['tracking_code'] = null;
	$result['payment'] = function_exists( 'papelito_pagarme_order_payment_snapshot' )
		? papelito_pagarme_order_payment_snapshot( $order )
		: array(
			'method' => '',
			'state'  => '',
		);

	return $result;
}

/**
 * Query all orders owned by a vendor. Current checkout always writes order-level vendor metadata.
 *
 * @return array<int,object>
 */
function papelito_vendor_dashboard_orders_for_vendor( int $vendor_id ): array {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$orders = wc_get_orders(
		array(
			'limit'      => -1,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_key'   => '_papelito_vendor_id',
			'meta_value' => (string) $vendor_id,
		)
	);

	return array_values(
		array_filter(
			is_array( $orders ) ? $orders : array(),
			static fn( $order ): bool => papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' )
		)
	);
}

/**
 * Parse a YYYY-MM-DD date, applying a fallback.
 */
function papelito_vendor_dashboard_date_param( $value, string $fallback ): string {
	$value = sanitize_text_field( (string) $value );

	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : $fallback;
}

/**
 * Normalize KPI period filters.
 *
 * @param WP_REST_Request $request Request REST.
 * @return array{from:string,to:string,interval:string}
 */
function papelito_vendor_dashboard_period( WP_REST_Request $request ): array {
	$today    = wp_date( 'Y-m-d' );
	$default  = wp_date( 'Y-m-d', strtotime( '-29 days', current_time( 'timestamp' ) ) );
	$from     = papelito_vendor_dashboard_date_param( $request->get_param( 'from' ), $default );
	$to       = papelito_vendor_dashboard_date_param( $request->get_param( 'to' ), $today );
	$interval = sanitize_key( (string) $request->get_param( 'interval' ) );

	if ( ! in_array( $interval, array( 'day', 'week', 'month' ), true ) ) {
		$interval = 'day';
	}

	if ( $from > $to ) {
		$temp = $from;
		$from = $to;
		$to   = $temp;
	}

	return compact( 'from', 'to', 'interval' );
}

/**
 * Return whether an order belongs to a date window.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_vendor_dashboard_in_period( $order, string $from, string $to ): bool {
	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) ) {
		return false;
	}

	$date = $order->get_date_created();

	if ( ! $date ) {
		return false;
	}

	$key = $date->date_i18n( 'Y-m-d' );

	return $key >= $from && $key <= $to;
}

/**
 * Build a revenue series bucket key.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_vendor_dashboard_bucket( $order, string $interval ): string {
	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) ) {
		return '';
	}

	$date = $order->get_date_created();

	if ( ! $date ) {
		return '';
	}

	if ( 'month' === $interval ) {
		return $date->date_i18n( 'Y-m' );
	}

	if ( 'week' === $interval ) {
		return $date->date_i18n( 'o-\WW' );
	}

	return $date->date_i18n( 'Y-m-d' );
}

/**
 * Build seller KPI response.
 *
 * @return array<string,mixed>
 */
function papelito_vendor_dashboard_kpis( int $vendor_id, array $period ): array {
	$orders                 = papelito_vendor_dashboard_orders_for_vendor( $vendor_id );
	$gross_revenue          = 0.0;
	$orders_count           = 0;
	$pending_orders         = 0;
	$awaiting_payment_count = 0;
	$series                 = array();
	$products               = array();
	$sale_statuses          = papelito_vendor_dashboard_sale_statuses();

	foreach ( $orders as $order ) {
		if ( ! papelito_vendor_dashboard_in_period( $order, $period['from'], $period['to'] ) ) {
			continue;
		}

		$status = papelito_vendor_dashboard_order_status( $order );

		if ( PAPELITO_VENDOR_STATUS_CANCELLED === $status ) {
			continue;
		}

		if ( ! papelito_vendor_dashboard_order_is_paid( $order ) ) {
			++$awaiting_payment_count;
			continue;
		}

		if ( ! in_array( $status, $sale_statuses, true ) ) {
			continue;
		}

		$total          = (float) $order->get_total();
		$gross_revenue += $total;
		++$orders_count;

		if ( in_array( $status, array( PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT, PAPELITO_VENDOR_STATUS_PICKING ), true ) ) {
			++$pending_orders;
		}

		$bucket = papelito_vendor_dashboard_bucket( $order, $period['interval'] );
		if ( '' !== $bucket ) {
			$series[ $bucket ] = ( $series[ $bucket ] ?? 0.0 ) + $total;
		}

		foreach ( papelito_vendor_dashboard_items( $order, $vendor_id ) as $item ) {
			$product_id = (int) $item['product_id'];
			if ( ! isset( $products[ $product_id ] ) ) {
				$products[ $product_id ] = array(
					'product_id' => $product_id,
					'name'       => (string) $item['name'],
					'qty'        => 0,
					'revenue'    => 0.0,
				);
			}

			$products[ $product_id ]['qty'] += (int) $item['qty'];
			$products[ $product_id ]['revenue'] += (float) $item['total'];
		}
	}

	ksort( $series );
	usort(
		$products,
		static fn( array $left, array $right ): int => $right['revenue'] <=> $left['revenue']
	);

	return array(
		'period'                  => $period,
		'gross_revenue'           => round( $gross_revenue, 2 ),
		'average_ticket'          => $orders_count > 0 ? round( $gross_revenue / $orders_count, 2 ) : 0.0,
		'pending_orders'          => $pending_orders,
		'awaiting_payment_orders' => $awaiting_payment_count,
		'orders_count'            => $orders_count,
		'revenue_series'          => array_map(
			static fn( string $label, float $value ): array => array(
				'label' => $label,
				'value' => round( $value, 2 ),
			),
			array_keys( $series ),
			array_values( $series )
		),
		'top_products'            => array_slice( array_values( $products ), 0, 5 ),
	);
}

/**
 * Return the next states available from a current state.
 *
 * @return array<int,string>
 */
function papelito_vendor_dashboard_next_statuses( string $current ): array {
	switch ( $current ) {
		case PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT:
			return array( PAPELITO_VENDOR_STATUS_PICKING, PAPELITO_VENDOR_STATUS_CANCELLED );
		case PAPELITO_VENDOR_STATUS_PICKING:
			return array( PAPELITO_VENDOR_STATUS_SHIPPED, PAPELITO_VENDOR_STATUS_CANCELLED );
		case PAPELITO_VENDOR_STATUS_SHIPPED:
			return array( PAPELITO_VENDOR_STATUS_DELIVERED );
		default:
			return array();
	}
}

/**
 * Find an order visible to a seller.
 *
 * @return object|WP_Error
 */
function papelito_vendor_dashboard_vendor_order( int $order_id, int $vendor_id ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) || ! papelito_vendor_dashboard_order_belongs_to_vendor( $order, $vendor_id ) ) {
		return new WP_Error( 'papelito_vendor_order_not_found', 'Pedido nao encontrado.', array( 'status' => 404 ) );
	}

	return $order;
}

/**
 * Persist a valid operational transition for a seller-owned order.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_dashboard_update_order_status( int $order_id, int $vendor_id, $next_status, $reason = '' ) {
	$order = papelito_vendor_dashboard_vendor_order( $order_id, $vendor_id );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$current = papelito_vendor_dashboard_order_status( $order );
	$next    = sanitize_key( (string) $next_status );

	if ( ! in_array( $next, papelito_vendor_dashboard_next_statuses( $current ), true ) ) {
		return new WP_Error( 'papelito_vendor_invalid_status_transition', 'Transicao de status invalida.', array( 'status' => 422 ) );
	}

	$reason = sanitize_textarea_field( (string) $reason );

	if ( PAPELITO_VENDOR_STATUS_CANCELLED === $next && '' === $reason ) {
		return new WP_Error( 'papelito_vendor_cancel_reason_required', 'Informe o motivo do cancelamento.', array( 'status' => 422 ) );
	}

	$order->update_meta_data( '_papelito_vendor_status', $next );

	if ( PAPELITO_VENDOR_STATUS_CANCELLED === $next ) {
		$order->update_meta_data( '_papelito_vendor_cancel_reason', $reason );
		$order->add_order_note( sprintf( 'Envio cancelado pelo vendor. Justificativa: %s', $reason ) );
	} else {
		$order->add_order_note( sprintf( 'Status operacional do vendor atualizado: %s.', $next ) );
	}

	$order->save();

	return papelito_vendor_dashboard_map_order( $order, $vendor_id, true );
}

/**
 * Paginated seller order list with lightweight in-memory filtering.
 *
 * @return array<string,mixed>
 */
function papelito_vendor_dashboard_list_orders( int $vendor_id, WP_REST_Request $request ): array {
	if ( function_exists( 'papelito_pagarme_maybe_reconcile_unpaid_orders_for_request' ) ) {
		papelito_pagarme_maybe_reconcile_unpaid_orders_for_request();
	}

	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
	$status   = sanitize_key( (string) $request->get_param( 'status' ) );
	$search   = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
	$orders   = papelito_vendor_dashboard_orders_for_vendor( $vendor_id );

	if ( '' !== $status && 'all' !== $status && in_array( $status, papelito_vendor_dashboard_statuses(), true ) ) {
		$orders = array_values(
			array_filter(
				$orders,
				static fn( $order ): bool => papelito_vendor_dashboard_order_status( $order ) === $status
			)
		);
	}

	if ( '' !== $search ) {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
		$orders = array_values(
			array_filter(
				$orders,
				static function ( $order ) use ( $needle ): bool {
					if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) ) {
						return false;
					}

					$haystack = $order->get_order_number() . ' ' . $order->get_formatted_billing_full_name();
					$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
					return false !== strpos( $haystack, $needle );
				}
			)
		);
	}

	$total = count( $orders );
	$items = array_slice( $orders, ( $page - 1 ) * $per_page, $per_page );

	return array(
		'items'       => array_map(
			static fn( $order ): array => papelito_vendor_dashboard_map_order( $order, $vendor_id ),
			$items
		),
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
	);
}

/**
 * Valida acesso admin aos pedidos operacionais de um vendor aprovado.
 *
 * @return WP_User|WP_Error
 */
function papelito_vendor_dashboard_admin_vendor_user( int $vendor_id ) {
	$user = get_user_by( 'id', $vendor_id );

	if ( function_exists( 'papelito_vendor_stock_is_operational_vendor' ) ) {
		if ( ! papelito_vendor_stock_is_operational_vendor( $user ) ) {
			return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
		}

		return $user;
	}

	if ( ! $user instanceof WP_User || ! in_array( 'seller', (array) $user->roles, true ) ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	return $user;
}

/**
 * Read seller operational settings.
 *
 * @return array{shipping_lead_time_days:int}
 */
function papelito_vendor_dashboard_settings( int $vendor_id ): array {
	$lead_time = (int) get_user_meta( $vendor_id, 'shipping_lead_time_days', true );

	return array(
		'shipping_lead_time_days' => $lead_time >= 1 && $lead_time <= 30 ? $lead_time : 2,
	);
}

/**
 * Format normalized CEP digits for display.
 */
function papelito_vendor_dashboard_format_cep( string $cep ): string {
	$digits = preg_replace( '/\D+/', '', $cep );

	if ( ! is_string( $digits ) || 8 !== strlen( $digits ) ) {
		return $cep;
	}

	return substr( $digits, 0, 5 ) . '-' . substr( $digits, 5 );
}

/**
 * Read seller coverage ranges from paired min_cep/max_cep metadata.
 *
 * @return array<int,array{id:int,min_cep:string,max_cep:string,min_cep_formatted:string,max_cep_formatted:string}>
 */
function papelito_vendor_dashboard_coverage_ranges( int $vendor_id ): array {
	$min_ceps = (array) get_user_meta( $vendor_id, 'min_cep', false );
	$max_ceps = (array) get_user_meta( $vendor_id, 'max_cep', false );
	$count    = min( count( $min_ceps ), count( $max_ceps ) );
	$items    = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$min_cep = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) $min_ceps[ $i ] ) : '';
		$max_cep = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) $max_ceps[ $i ] ) : '';

		if ( '' === $min_cep || '' === $max_cep ) {
			continue;
		}

		$items[] = array(
			'id'                => count( $items ) + 1,
			'min_cep'           => $min_cep,
			'max_cep'           => $max_cep,
			'min_cep_formatted' => papelito_vendor_dashboard_format_cep( $min_cep ),
			'max_cep_formatted' => papelito_vendor_dashboard_format_cep( $max_cep ),
		);
	}

	return $items;
}

/**
 * Validate a coverage range payload.
 *
 * @return array{min_cep:string,max_cep:string}|WP_Error
 */
function papelito_vendor_dashboard_normalize_coverage_range( array $payload ) {
	$min_cep = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $payload['minCep'] ?? $payload['min_cep'] ?? '' ) ) : '';
	$max_cep = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $payload['maxCep'] ?? $payload['max_cep'] ?? '' ) ) : '';

	if ( '' === $min_cep || '' === $max_cep ) {
		return new WP_Error( 'papelito_vendor_invalid_coverage_range', 'Informe CEP inicial e final validos.', array( 'status' => 422 ) );
	}

	if ( (int) $min_cep > (int) $max_cep ) {
		return new WP_Error( 'papelito_vendor_invalid_coverage_order', 'O CEP final precisa ser maior ou igual ao inicial.', array( 'status' => 422 ) );
	}

	return array(
		'min_cep' => $min_cep,
		'max_cep' => $max_cep,
	);
}

/**
 * Persist normalized coverage ranges for a vendor.
 *
 * @param array<int,array{min_cep:string,max_cep:string}> $ranges Ranges.
 */
function papelito_vendor_dashboard_save_coverage_ranges( int $vendor_id, array $ranges ): void {
	delete_user_meta( $vendor_id, 'min_cep' );
	delete_user_meta( $vendor_id, 'max_cep' );

	foreach ( $ranges as $range ) {
		add_user_meta( $vendor_id, 'min_cep', $range['min_cep'], false );
		add_user_meta( $vendor_id, 'max_cep', $range['max_cep'], false );
	}

	if ( function_exists( 'papelito_coverage_bump_cache_version' ) ) {
		papelito_coverage_bump_cache_version();
	}
}

/**
 * Ensure the new or edited range does not duplicate/overlap existing ranges.
 *
 * @param array<int,array{id:int,min_cep:string,max_cep:string}> $ranges Existing ranges.
 * @param array{min_cep:string,max_cep:string}                  $candidate Candidate.
 */
function papelito_vendor_dashboard_validate_coverage_overlap( array $ranges, array $candidate, int $ignore_id = 0 ) {
	$candidate_min = (int) $candidate['min_cep'];
	$candidate_max = (int) $candidate['max_cep'];

	foreach ( $ranges as $range ) {
		$range_id = isset( $range['id'] ) ? (int) $range['id'] : 0;

		if ( $ignore_id > 0 && $range_id === $ignore_id ) {
			continue;
		}

		$range_min = (int) $range['min_cep'];
		$range_max = (int) $range['max_cep'];

		if ( $candidate_min <= $range_max && $candidate_max >= $range_min ) {
			return new WP_Error(
				'papelito_vendor_coverage_overlap',
				'Esta faixa se sobrepoe a uma faixa ja cadastrada.',
				array( 'status' => 409 )
			);
		}
	}

	return true;
}

/**
 * Add a vendor coverage range.
 */
function papelito_vendor_dashboard_add_coverage_range( int $vendor_id, array $payload ) {
	$ranges    = papelito_vendor_dashboard_coverage_ranges( $vendor_id );
	$candidate = papelito_vendor_dashboard_normalize_coverage_range( $payload );

	if ( is_wp_error( $candidate ) ) {
		return $candidate;
	}

	$overlap = papelito_vendor_dashboard_validate_coverage_overlap( $ranges, $candidate );

	if ( is_wp_error( $overlap ) ) {
		return $overlap;
	}

	$next_ranges   = array_map(
		static fn( array $range ): array => array(
			'min_cep' => $range['min_cep'],
			'max_cep' => $range['max_cep'],
		),
		$ranges
	);
	$next_ranges[] = $candidate;

	papelito_vendor_dashboard_save_coverage_ranges( $vendor_id, $next_ranges );

	return papelito_vendor_dashboard_coverage_ranges( $vendor_id );
}

/**
 * Update one vendor coverage range.
 */
function papelito_vendor_dashboard_update_coverage_range( int $vendor_id, int $range_id, array $payload ) {
	$ranges = papelito_vendor_dashboard_coverage_ranges( $vendor_id );

	if ( $range_id <= 0 || $range_id > count( $ranges ) ) {
		return new WP_Error( 'papelito_vendor_coverage_range_not_found', 'Faixa de CEP nao encontrada.', array( 'status' => 404 ) );
	}

	$candidate = papelito_vendor_dashboard_normalize_coverage_range( $payload );

	if ( is_wp_error( $candidate ) ) {
		return $candidate;
	}

	$overlap = papelito_vendor_dashboard_validate_coverage_overlap( $ranges, $candidate, $range_id );

	if ( is_wp_error( $overlap ) ) {
		return $overlap;
	}

	$ranges[ $range_id - 1 ] = array_merge( $ranges[ $range_id - 1 ], $candidate );
	$next_ranges            = array_map(
		static fn( array $range ): array => array(
			'min_cep' => $range['min_cep'],
			'max_cep' => $range['max_cep'],
		),
		$ranges
	);

	papelito_vendor_dashboard_save_coverage_ranges( $vendor_id, $next_ranges );

	return papelito_vendor_dashboard_coverage_ranges( $vendor_id );
}

/**
 * Remove one vendor coverage range.
 */
function papelito_vendor_dashboard_delete_coverage_range( int $vendor_id, int $range_id ) {
	$ranges = papelito_vendor_dashboard_coverage_ranges( $vendor_id );

	if ( $range_id <= 0 || $range_id > count( $ranges ) ) {
		return new WP_Error( 'papelito_vendor_coverage_range_not_found', 'Faixa de CEP nao encontrada.', array( 'status' => 404 ) );
	}

	array_splice( $ranges, $range_id - 1, 1 );

	$next_ranges = array_map(
		static fn( array $range ): array => array(
			'min_cep' => $range['min_cep'],
			'max_cep' => $range['max_cep'],
		),
		$ranges
	);

	papelito_vendor_dashboard_save_coverage_ranges( $vendor_id, $next_ranges );

	return papelito_vendor_dashboard_coverage_ranges( $vendor_id );
}

/**
 * Map a customer-owned order detail.
 *
 * @return object|WP_Error
 */
function papelito_vendor_dashboard_customer_order( int $order_id, int $customer_id ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

	if ( ! papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' ) || (int) $order->get_customer_id() !== $customer_id ) {
		return new WP_Error( 'papelito_profile_order_not_found', 'Pedido nao encontrado.', array( 'status' => 404 ) );
	}

	return $order;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/vendor/me/kpis',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function () {
					$check = papelito_vendor_dashboard_require_seller();
					return is_wp_error( $check ) ? $check : true;
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					return new WP_REST_Response(
						papelito_vendor_dashboard_kpis( get_current_user_id(), papelito_vendor_dashboard_period( $request ) ),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function () {
					$check = papelito_vendor_dashboard_require_seller();
					return is_wp_error( $check ) ? $check : true;
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					return new WP_REST_Response(
						papelito_vendor_dashboard_list_orders( get_current_user_id(), $request ),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/vendors/(?P<id>\d+)/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$vendor_id = absint( $request->get_param( 'id' ) );
					$user      = papelito_vendor_dashboard_admin_vendor_user( $vendor_id );

					if ( is_wp_error( $user ) ) {
						return $user;
					}

					return new WP_REST_Response(
						papelito_vendor_dashboard_list_orders( $vendor_id, $request ),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/orders/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function () {
						$check = papelito_vendor_dashboard_require_seller();
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$order = papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'id' ) ), get_current_user_id() );
						if ( is_wp_error( $order ) ) {
							return $order;
						}

						return new WP_REST_Response( papelito_vendor_dashboard_map_order( $order, get_current_user_id(), true ), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => static function () {
						$check = papelito_vendor_dashboard_require_seller();
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$result = papelito_vendor_dashboard_update_order_status(
							absint( $request->get_param( 'id' ) ),
							get_current_user_id(),
							$request->get_param( 'status' )
						);

						return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
					},
					'args'                => array(
						'status' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/orders/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function () {
					$check = papelito_vendor_dashboard_require_seller();
					return is_wp_error( $check ) ? $check : true;
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_vendor_dashboard_update_order_status(
						absint( $request->get_param( 'id' ) ),
						get_current_user_id(),
						$request->get_param( 'status' ),
						(string) $request->get_param( 'reason' )
					);

					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
				'args'                => array(
					'status' => array( 'type' => 'string', 'required' => true ),
					'reason' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function () {
						$check = papelito_vendor_dashboard_require_seller();
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function () {
						return new WP_REST_Response( papelito_vendor_dashboard_settings( get_current_user_id() ), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => static function () {
						$check = papelito_vendor_dashboard_require_seller();
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$raw       = $request->get_param( 'shipping_lead_time_days' );
						$lead_time = is_numeric( $raw ) ? (float) $raw : 0.0;

						if ( floor( $lead_time ) !== $lead_time || $lead_time < 1 || $lead_time > 30 ) {
							return new WP_Error( 'papelito_vendor_invalid_lead_time', 'Informe um prazo inteiro entre 1 e 30 dias uteis.', array( 'status' => 422 ) );
						}

						update_user_meta( get_current_user_id(), 'shipping_lead_time_days', (int) $lead_time );

						return new WP_REST_Response( papelito_vendor_dashboard_settings( get_current_user_id() ), 200 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/coverage-ranges',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function () {
						$check = papelito_vendor_dashboard_require_seller();
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function () {
						return new WP_REST_Response(
							array( 'items' => papelito_vendor_dashboard_coverage_ranges( get_current_user_id() ) ),
							200
						);
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => static function () {
						$check = papelito_vendor_dashboard_require_seller();
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$payload = $request->get_json_params();
						$result  = papelito_vendor_dashboard_add_coverage_range(
							get_current_user_id(),
							is_array( $payload ) ? $payload : array()
						);

						return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'items' => $result ), 201 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/coverage-ranges/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => static function () {
						$check = papelito_vendor_dashboard_require_seller();
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$payload = $request->get_json_params();
						$result  = papelito_vendor_dashboard_update_coverage_range(
							get_current_user_id(),
							absint( $request->get_param( 'id' ) ),
							is_array( $payload ) ? $payload : array()
						);

						return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'items' => $result ), 200 );
					},
					'args'                => array(
						'id' => array(
							'validate_callback' => static function ( $value ): bool {
								return is_numeric( $value ) && (int) $value > 0;
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => static function () {
						$check = papelito_vendor_dashboard_require_seller();
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$result = papelito_vendor_dashboard_delete_coverage_range(
							get_current_user_id(),
							absint( $request->get_param( 'id' ) )
						);

						return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'items' => $result ), 200 );
					},
					'args'                => array(
						'id' => array(
							'validate_callback' => static function ( $value ): bool {
								return is_numeric( $value ) && (int) $value > 0;
							},
						),
					),
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/profile/me/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function () {
					$check = papelito_vendor_dashboard_require_profile_user();
					return is_wp_error( $check ) ? $check : true;
				},
					'callback'            => static function ( WP_REST_Request $request ) {
						if ( ! function_exists( 'wc_get_orders' ) ) {
							return new WP_Error( 'papelito_woocommerce_unavailable', 'WooCommerce nao esta disponivel.', array( 'status' => 500 ) );
						}

						$page     = max( 1, (int) $request->get_param( 'page' ) );
						$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
						$query    = wc_get_orders(
							array(
								'customer_id' => get_current_user_id(),
								'limit'       => $per_page,
							'page'        => $page,
							'paginate'    => true,
							'orderby'     => 'date',
								'order'       => 'DESC',
							)
						);
						$query_data = is_object( $query ) ? get_object_vars( $query ) : array();
						$orders     = isset( $query_data['orders'] ) && is_array( $query_data['orders'] ) ? array_values(
							array_filter(
								$query_data['orders'],
								static fn( $order ): bool => papelito_vendor_dashboard_is_wc_instance( $order, 'WC_Order' )
							)
						) : array();
						$total      = isset( $query_data['total'] ) ? (int) $query_data['total'] : 0;
						$total_pages = isset( $query_data['max_num_pages'] ) ? (int) $query_data['max_num_pages'] : 1;

						return new WP_REST_Response(
							array(
								'items'       => array_map(
									static fn( $order ): array => papelito_vendor_dashboard_map_order( $order ),
									$orders
								),
								'total'       => $total,
								'page'        => $page,
								'per_page'    => $per_page,
								'total_pages' => max( 1, $total_pages ),
							),
							200
						);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/profile/me/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function () {
					$check = papelito_vendor_dashboard_require_profile_user();
					return is_wp_error( $check ) ? $check : true;
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = papelito_vendor_dashboard_customer_order( absint( $request->get_param( 'id' ) ), get_current_user_id() );
					if ( is_wp_error( $order ) ) {
						return $order;
					}

					if ( function_exists( 'papelito_pagarme_maybe_reconcile_checkout_order' ) ) {
						papelito_pagarme_maybe_reconcile_checkout_order( $order );
					}

					return new WP_REST_Response( papelito_vendor_dashboard_map_order( $order, null, true ), 200 );
				},
			)
		);
	}
);
