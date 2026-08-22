<?php
/**
 * Standalone regression test for the payment-driven fulfillment transition.
 *
 * Asserts that papelito_pagarme_apply_order_state() only promotes an order to
 * "aguardando_envio" once the charge is paid, cancels it on a terminal
 * unpaid state, and never regresses an order already further along the
 * fulfillment line.
 *
 * Usage: php tests/test-payment-promotion.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

define( 'PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT', 'aguardando_pagamento' );
define( 'PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT', 'aguardando_envio' );
define( 'PAPELITO_VENDOR_STATUS_PICKING', 'em_separacao' );
define( 'PAPELITO_VENDOR_STATUS_SHIPPED', 'enviado' );
define( 'PAPELITO_VENDOR_STATUS_DELIVERED', 'entregue' );
define( 'PAPELITO_VENDOR_STATUS_CANCELLED', 'cancelado' );

function sanitize_key( mixed $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}

function sanitize_text_field( mixed $value ) {
	return trim( (string) $value );
}

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}

	public function get_error_message(): string {
		return $this->message;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function do_action( ...$args ) {}

$papelito_test_stock_available = true;
$papelito_test_stock_adjustments = array();

function papelito_adjust_vendor_stock( int $vendor_id, int $product_id, int $delta, string $reason ) {
	global $papelito_test_stock_available, $papelito_test_stock_adjustments;

	$papelito_test_stock_adjustments[] = array( $vendor_id, $product_id, $delta, $reason );

	if ( $delta < 0 && ! $papelito_test_stock_available ) {
		return new WP_Error( 'papelito_insufficient_vendor_stock', 'Estoque insuficiente.', array( 'status' => 409 ) );
	}

	return true;
}

function papelito_order_routing_order_lines( object $order ): array {
	return array(
		array(
			'vendor_id'  => 10,
			'product_id' => 20,
			'qty'        => 1,
		),
	);
}

class WC_Order_Stub {
	public $meta = array();
	public string $status;
	public $payment_completed = false;
	public $notes = array();

	public function __construct( string $vendor_status, string $status = 'pending' ) {
		$this->meta['_papelito_vendor_status'] = $vendor_status;
		$this->status                          = $status;
	}

	public function payment_complete() {
		$this->payment_completed = true;
	}

	public function update_status( string $status ): void {
		$this->status = $status;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_id(): int {
		return 123;
	}

	public function get_meta( string $key, bool $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function add_order_note( string $note ): void {
		$this->notes[] = $note;
	}

	public function save() {}
}

require __DIR__ . '/../includes/pagarme_payments.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: paid charge promotes aguardando_pagamento -> aguardando_envio\n";
$order = new WC_Order_Stub( 'aguardando_pagamento' );
papelito_pagarme_apply_order_state( $order, 'paid', true );
papelito_assert( 'wc status processing', 'processing', $order->status );
papelito_assert( 'payment_complete called', true, $order->payment_completed );
papelito_assert( 'vendor status -> aguardando_envio', 'aguardando_envio', $order->meta['_papelito_vendor_status'] );

echo "Scenario 2: still-pending charge leaves order awaiting payment (no promotion)\n";
$order = new WC_Order_Stub( 'aguardando_pagamento' );
papelito_pagarme_apply_order_state( $order, 'waiting_payment', false );
papelito_assert( 'wc status untouched (pending)', 'pending', $order->status );
papelito_assert( 'payment_complete NOT called', false, $order->payment_completed );
papelito_assert( 'vendor status stays aguardando_pagamento', 'aguardando_pagamento', $order->meta['_papelito_vendor_status'] );

echo "Scenario 3: terminal failure cancels an unpaid order\n";
$order = new WC_Order_Stub( 'aguardando_pagamento' );
papelito_pagarme_apply_order_state( $order, 'refused', false );
papelito_assert( 'wc status failed', 'failed', $order->status );
papelito_assert( 'vendor status -> cancelado', 'cancelado', $order->meta['_papelito_vendor_status'] );

echo "Scenario 4: paying an order already in separacao does NOT regress it\n";
$order = new WC_Order_Stub( 'em_separacao', 'processing' );
papelito_pagarme_apply_order_state( $order, 'paid', true );
papelito_assert( 'vendor status stays em_separacao', 'em_separacao', $order->meta['_papelito_vendor_status'] );

echo "Scenario 5: terminal failure does NOT cancel a shipped order\n";
$order = new WC_Order_Stub( 'enviado', 'processing' );
papelito_pagarme_apply_order_state( $order, 'refused', false );
papelito_assert( 'vendor status stays enviado', 'enviado', $order->meta['_papelito_vendor_status'] );

echo "Scenario 6: legacy failed order awaiting shipment is cancelled\n";
$order = new WC_Order_Stub( PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT, 'failed' );
papelito_pagarme_mark_vendor_status_unpaid( $order );
papelito_assert( 'legacy failed order becomes cancelado', PAPELITO_VENDOR_STATUS_CANCELLED, $order->meta['_papelito_vendor_status'] );
papelito_pagarme_mark_vendor_status_unpaid( $order );
papelito_assert( 'legacy cancellation is idempotent', 1, count( $order->notes ) );

echo "Scenario 8: a late paid webhook recovers an order cancelled by the payment itself\n";
$order = new WC_Order_Stub( 'aguardando_pagamento' );
papelito_pagarme_apply_order_state( $order, 'refused', false );
papelito_assert( 'cancelled by payment', PAPELITO_VENDOR_STATUS_CANCELLED, $order->meta['_papelito_vendor_status'] );
papelito_assert( 'cancellation is attributed to the payment', 'payment_unpaid', $order->meta['_papelito_vendor_status_source'] ?? '' );
papelito_pagarme_apply_order_state( $order, 'paid', true );
papelito_assert( 'late payment restores aguardando_envio', PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT, $order->meta['_papelito_vendor_status'] );
papelito_assert( 'wc status back to processing', 'processing', $order->status );

echo "Scenario 8b: a recovered paid order reserves stock again before fulfillment\n";
$papelito_test_stock_available   = true;
$papelito_test_stock_adjustments = array();
$order                           = new WC_Order_Stub( PAPELITO_VENDOR_STATUS_CANCELLED, 'failed' );
$order->update_meta_data( '_papelito_vendor_status_source', PAPELITO_VENDOR_STATUS_SOURCE_PAYMENT_UNPAID );
$order->update_meta_data( PAPELITO_STOCK_RESERVED_META, '0' );
papelito_pagarme_apply_order_state( $order, 'paid', true );
papelito_assert( 'stock is reserved again', '1', $order->meta[ PAPELITO_STOCK_RESERVED_META ] );
papelito_assert( 'stock decrement is executed once', -1, $papelito_test_stock_adjustments[0][2] ?? null );
papelito_assert( 'recovered order is released for fulfillment', PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT, $order->meta['_papelito_vendor_status'] );

echo "Scenario 8c: a recovered paid order without stock waits for manual resolution\n";
$papelito_test_stock_available   = false;
$papelito_test_stock_adjustments = array();
$order                           = new WC_Order_Stub( PAPELITO_VENDOR_STATUS_CANCELLED, 'failed' );
$order->update_meta_data( '_papelito_vendor_status_source', PAPELITO_VENDOR_STATUS_SOURCE_PAYMENT_UNPAID );
$order->update_meta_data( PAPELITO_STOCK_RESERVED_META, '0' );
papelito_pagarme_apply_order_state( $order, 'paid', true );
papelito_assert( 'stock remains unreserved', '0', $order->meta[ PAPELITO_STOCK_RESERVED_META ] );
papelito_assert( 'order is held instead of processing', 'on-hold', $order->status );
papelito_assert( 'vendor order waits for stock review', 'aguardando_estoque', $order->meta['_papelito_vendor_status'] );
papelito_pagarme_apply_order_state( $order, 'paid', true );
papelito_assert( 'repeated paid event keeps the order on hold', 'on-hold', $order->status );
papelito_assert( 'repeated paid event does not rerun stock reservation', 1, count( $papelito_test_stock_adjustments ) );

echo "Scenario 9: a late paid webhook never resurrects an order the vendor cancelled\n";
$order = new WC_Order_Stub( PAPELITO_VENDOR_STATUS_CANCELLED, 'processing' );
$order->update_meta_data( '_papelito_vendor_status_source', 'vendor_action' );
papelito_pagarme_apply_order_state( $order, 'paid', true );
papelito_assert( 'vendor cancellation is respected', PAPELITO_VENDOR_STATUS_CANCELLED, $order->meta['_papelito_vendor_status'] );

echo "Scenario 7: discounted multi-quantity lines never create zero-cent items\n";
$product = new class() {
	public function get_name() { return 'Produto promocional'; }
};
$payload_items = papelito_pagarme_order_items_payload(
	array(
		array(
			'product'     => $product,
			'product_id'  => 11776,
			'qty'         => 3,
			'total'       => 0.02,
			'total_cents' => 2,
		),
		array(
			'product'     => $product,
			'product_id'  => 11777,
			'qty'         => 1,
			'total'       => 0.0,
			'total_cents' => 0,
		),
	),
	array( 'price' => 0.01 )
);
papelito_assert( 'product and shipping are represented', 2, count( $payload_items ) );
papelito_assert( 'discounted line remains positive', 2, $payload_items[0]['amount'] ?? null );
papelito_assert( 'zero-value lines are omitted', array(), array_values( array_filter( $payload_items, static fn( array $item ): bool => (int) $item['amount'] <= 0 ) ) );
papelito_assert( 'payload sum remains exact', 3, papelito_pagarme_items_total_cents( $payload_items ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
