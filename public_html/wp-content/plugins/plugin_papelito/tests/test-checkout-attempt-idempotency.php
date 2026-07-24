<?php
/**
 * Standalone regression test for checkout attempt idempotency helpers.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'PAPELITO_PAGARME_ORDER_ID_META', '_papelito_pagarme_order_id' );
define( 'PAPELITO_PAGARME_PAYMENT_METHOD_META', '_papelito_pagarme_payment_method' );
define( 'PAPELITO_PAGARME_PAYMENT_STATE_META', '_papelito_pagarme_payment_state' );

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function papelito_pricing_quote() {}

function sanitize_key( mixed $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}

function sanitize_text_field( mixed $value ) {
	return trim( (string) $value );
}

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;

	public function __construct( string $code, string $message, mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

class WC_Order {
	public array $meta = array();

	public function __construct( private int $id, private int $customer_id ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_customer_id(): int {
		return $this->customer_id;
	}

	public function get_order_number(): string {
		return (string) $this->id;
	}

	public function get_status(): string {
		return 'pending';
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
}

$papelito_test_orders = array();

function wc_get_orders( array $args ): array {
	global $papelito_test_orders;
	$customer_id = (int) ( $args['customer_id'] ?? 0 );
	$meta_key    = (string) ( $args['meta_key'] ?? '' );
	$meta_value  = (string) ( $args['meta_value'] ?? '' );

	return array_values(
		array_filter(
			$papelito_test_orders,
			static fn( WC_Order $order ): bool => $order->get_customer_id() === $customer_id && (string) $order->get_meta( $meta_key, true ) === $meta_value
		)
	);
}

function papelito_pagarme_order_payment_snapshot( object $order ): array {
	return array(
		'method' => (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ),
		'state'  => (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ),
	);
}

require __DIR__ . '/../includes/order_routing.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: invalid attempt ids are ignored\n";
papelito_assert_same( 'too-short value is blank', '', papelito_order_routing_normalize_checkout_attempt_id( 'abc' ) );
papelito_assert_same( 'valid value is preserved', 'attempt-123', papelito_order_routing_normalize_checkout_attempt_id( 'attempt-123' ) );

echo "Scenario 2: existing completed checkout attempt returns the stored order\n";
$order = new WC_Order( 321, 77 );
$order->meta[ PAPELITO_CHECKOUT_ATTEMPT_ID_META ]     = 'attempt-123';
$order->meta[ PAPELITO_CHECKOUT_ATTEMPT_COMPANY_META ] = 44;
$order->meta[ PAPELITO_CHECKOUT_ATTEMPT_HASH_META ]    = 'stable-hash';
$order->meta[ PAPELITO_PAGARME_ORDER_ID_META ]       = 'ord_123';
$order->meta[ PAPELITO_PAGARME_PAYMENT_METHOD_META ] = 'boleto';
$order->meta[ PAPELITO_PAGARME_PAYMENT_STATE_META ]  = 'waiting_payment';
$order->meta['_papelito_authoritative_total_cents']  = 10890;
$papelito_test_orders                                = array( $order );

$found = papelito_order_routing_find_order_by_attempt( 77, 'attempt-123' );
papelito_assert_same( 'existing order is found', 321, $found->get_id() );
$response = papelito_order_routing_existing_order_response( $found );
papelito_assert_same( 'response reuses order id', 321, $response['order_id'] ?? null );
papelito_assert_same( 'response reuses payment state', 'waiting_payment', $response['payment']['state'] ?? null );

echo "Scenario 3: attempt identity protects company and payload\n";
papelito_assert_same( 'same company and payload are accepted', true, papelito_order_routing_validate_existing_attempt( $order, 44, 'stable-hash' ) );
$company_conflict = papelito_order_routing_validate_existing_attempt( $order, 45, 'stable-hash' );
papelito_assert_same( 'company change is rejected', true, is_wp_error( $company_conflict ) );
papelito_assert_same( 'company change is conflict', 409, $company_conflict->get_error_data()['status'] ?? null );
$payload_conflict = papelito_order_routing_validate_existing_attempt( $order, 44, 'changed-hash' );
papelito_assert_same( 'payload change is rejected', true, is_wp_error( $payload_conflict ) );

echo "Scenario 4: in-progress attempt blocks duplicate creation\n";
$order->meta[ PAPELITO_PAGARME_ORDER_ID_META ] = '';
$response                                      = papelito_order_routing_existing_order_response( $order );
papelito_assert_same( 'in-progress duplicate returns WP_Error', true, is_wp_error( $response ) );
papelito_assert_same( 'in-progress duplicate is conflict', 409, $response->get_error_data()['status'] ?? null );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
