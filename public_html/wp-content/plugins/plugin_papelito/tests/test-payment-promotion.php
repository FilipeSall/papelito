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

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}

function add_action( ...$args ) {}
function add_filter( ...$args ) {}

class WC_Order_Stub {
	public $meta = array();
	public $status;
	public $payment_completed = false;
	public $notes = array();

	public function __construct( string $vendor_status, string $status = 'pending' ) {
		$this->meta['_papelito_vendor_status'] = $vendor_status;
		$this->status                          = $status;
	}

	public function payment_complete() {
		$this->payment_completed = true;
	}

	public function update_status( $status ) {
		$this->status = $status;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function save() {}
}

require __DIR__ . '/../includes/pagarme_payments.php';

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

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
