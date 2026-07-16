<?php
/**
 * Standalone regression test for vendor KPI payment gating.
 *
 * Runs without a full WordPress bootstrap: stubs the minimal WP/WC surface
 * that papelito_vendor_dashboard_kpis() touches, then asserts that orders
 * without a confirmed payment never count toward revenue / orders_count /
 * ticket while paid orders do — even when a legacy order carries a
 * fulfillment status that predates the payment check.
 *
 * Usage: php tests/test-vendor-dashboard-kpis.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['papelito_test_orders'] = array();

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wc_get_orders( $args ) {
	return $GLOBALS['papelito_test_orders'];
}

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}

// Mirrors pagarme_payments.php, which is loaded before vendor_dashboard.php in the real plugin.
function papelito_pagarme_payment_state_is_paid( string $state ): bool {
	return in_array( sanitize_key( $state ), array( 'paid', 'captured' ), true );
}

class WC_Order {
	private $meta;
	private $total;
	private $date;
	private $items;
	private $wc_status;

	public function __construct( array $meta, float $total, string $date, string $wc_status, array $items = array() ) {
		$this->meta      = $meta;
		$this->total     = $total;
		$this->date      = $date;
		$this->wc_status = $wc_status;
		$this->items     = $items;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function get_total() {
		return $this->total;
	}

	public function get_status() {
		return $this->wc_status;
	}

	public function get_date_created() {
		return new Papelito_Test_Date( $this->date );
	}

	public function get_items( $type = 'line_item' ) {
		return $this->items;
	}
}

class Papelito_Test_Date {
	private $value;

	public function __construct( string $value ) {
		$this->value = $value;
	}

	public function date_i18n( $format ) {
		return $this->value;
	}
}

require __DIR__ . '/../includes/vendor_dashboard.php';

/**
 * @param string $vendor_status Fulfillment status meta.
 * @param float  $total         Order total.
 * @param string $wc_status     WooCommerce status (processing/completed = paid).
 */
function papelito_make_order( string $vendor_status, float $total, string $wc_status = 'pending', string $date = '2026-06-11' ): WC_Order {
	return new WC_Order(
		array(
			'_papelito_vendor_status' => $vendor_status,
			'_papelito_vendor_id'     => '10',
		),
		$total,
		$date,
		$wc_status,
		array()
	);
}

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 0: protected logistics transitions cannot be performed by a seller\n";
papelito_assert( 'seller cannot manually mark picking order as shipped', array( 'cancelado' ), papelito_vendor_dashboard_next_statuses( 'em_separacao' ) );
papelito_assert( 'seller cannot manually mark shipped order as delivered', array(), papelito_vendor_dashboard_next_statuses( 'enviado' ) );

$period = array(
	'from'     => '2026-06-01',
	'to'       => '2026-06-30',
	'interval' => 'day',
);

echo "Scenario 1: unpaid order (aguardando_pagamento, WC pending) must NOT count as a sale\n";
$GLOBALS['papelito_test_orders'] = array( papelito_make_order( 'aguardando_pagamento', 71.36, 'pending' ) );
$kpis                            = papelito_vendor_dashboard_kpis( 10, $period );
papelito_assert( 'gross_revenue excludes unpaid', 0.0, $kpis['gross_revenue'] );
papelito_assert( 'orders_count excludes unpaid', 0, $kpis['orders_count'] );
papelito_assert( 'average_ticket excludes unpaid', 0.0, $kpis['average_ticket'] );
papelito_assert( 'awaiting_payment_orders counts unpaid', 1, $kpis['awaiting_payment_orders'] );
papelito_assert( 'revenue_series empty for unpaid', array(), $kpis['revenue_series'] );

echo "Scenario 2: a paid order (aguardando_envio, WC processing) counts as a sale\n";
$GLOBALS['papelito_test_orders'] = array( papelito_make_order( 'aguardando_envio', 100.0, 'processing' ) );
$kpis                            = papelito_vendor_dashboard_kpis( 10, $period );
papelito_assert( 'gross_revenue includes paid', 100.0, $kpis['gross_revenue'] );
papelito_assert( 'orders_count includes paid', 1, $kpis['orders_count'] );
papelito_assert( 'awaiting_payment_orders is zero', 0, $kpis['awaiting_payment_orders'] );

echo "Scenario 3: LEGACY bug - order stamped aguardando_envio but WC still pending must NOT count\n";
$GLOBALS['papelito_test_orders'] = array( papelito_make_order( 'aguardando_envio', 500.0, 'pending' ) );
$kpis                            = papelito_vendor_dashboard_kpis( 10, $period );
papelito_assert( 'gross_revenue ignores mislabeled-unpaid', 0.0, $kpis['gross_revenue'] );
papelito_assert( 'orders_count ignores mislabeled-unpaid', 0, $kpis['orders_count'] );
papelito_assert( 'awaiting_payment_orders catches it', 1, $kpis['awaiting_payment_orders'] );

echo "Scenario 4: mixed batch - only confirmed-paid orders feed revenue/ticket\n";
$GLOBALS['papelito_test_orders'] = array(
	papelito_make_order( 'aguardando_pagamento', 71.36, 'pending' ),
	papelito_make_order( 'aguardando_envio', 100.0, 'processing' ),
	papelito_make_order( 'entregue', 50.0, 'completed' ),
	papelito_make_order( 'cancelado', 999.0, 'failed' ),
	papelito_make_order( 'aguardando_envio', 250.0, 'pending' ),
);
$kpis = papelito_vendor_dashboard_kpis( 10, $period );
papelito_assert( 'gross_revenue = 150 (100 + 50 paid only)', 150.0, $kpis['gross_revenue'] );
papelito_assert( 'orders_count = 2 paid', 2, $kpis['orders_count'] );
papelito_assert( 'average_ticket = 75', 75.0, $kpis['average_ticket'] );
papelito_assert( 'awaiting_payment_orders = 2 (1 pending + 1 mislabeled)', 2, $kpis['awaiting_payment_orders'] );

echo "Scenario 5: paid via Pagar.me meta when WC status is non-decisive\n";
$order_paid_via_meta = new WC_Order(
	array(
		'_papelito_vendor_status'        => 'aguardando_envio',
		'_papelito_vendor_id'            => '10',
		'_papelito_pagarme_payment_state' => 'paid',
	),
	80.0,
	'2026-06-11',
	'wc-processing-like-unknown',
	array()
);
$GLOBALS['papelito_test_orders'] = array( $order_paid_via_meta );
$kpis                            = papelito_vendor_dashboard_kpis( 10, $period );
papelito_assert( 'gross_revenue includes pagarme-paid', 80.0, $kpis['gross_revenue'] );

echo "Scenario 6: order status defaults unknown/empty to aguardando_pagamento (safe)\n";
papelito_assert( 'empty status -> aguardando_pagamento', 'aguardando_pagamento', papelito_vendor_dashboard_order_status( papelito_make_order( '', 10.0 ) ) );
papelito_assert( 'garbage status -> aguardando_pagamento', 'aguardando_pagamento', papelito_vendor_dashboard_order_status( papelito_make_order( 'lixo', 10.0 ) ) );

echo "Scenario 7: displayed status is payment-aware (fixes legacy orders in profile/vendor lists)\n";
papelito_assert(
	'legacy aguardando_envio + WC pending -> shown as aguardando_pagamento',
	'aguardando_pagamento',
	papelito_vendor_dashboard_order_status( papelito_make_order( 'aguardando_envio', 71.36, 'pending' ) )
);
papelito_assert(
	'paid aguardando_envio stays aguardando_envio',
	'aguardando_envio',
	papelito_vendor_dashboard_order_status( papelito_make_order( 'aguardando_envio', 100.0, 'processing' ) )
);
papelito_assert(
	'paid em_separacao is preserved',
	'em_separacao',
	papelito_vendor_dashboard_order_status( papelito_make_order( 'em_separacao', 100.0, 'completed' ) )
);
papelito_assert(
	'cancelled stays cancelled even if unpaid',
	'cancelado',
	papelito_vendor_dashboard_order_status( papelito_make_order( 'cancelado', 100.0, 'failed' ) )
);

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
