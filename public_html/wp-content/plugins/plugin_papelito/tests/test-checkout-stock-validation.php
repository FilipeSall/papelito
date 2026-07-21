<?php
/**
 * Standalone regression test for checkout stock validation.
 *
 * Usage: php tests/test-checkout-stock-validation.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}

function sanitize_key( mixed $value ) {
	return strtolower( trim( (string) $value ) );
}

function sanitize_text_field( mixed $value ) {
	return trim( (string) $value );
}

function get_user_meta( $user_id, $key, $single ) {
	return 'Vendor Centro';
}

function get_userdata( $user_id ) {
	return null;
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

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

class Papelito_Test_Product {
	public function get_name(): string {
		return 'Produto Papelito';
	}

	public function get_price(): string {
		return '49.90';
	}
}

function wc_get_product( mixed $product_id ) {
	return (int) $product_id > 0 ? new Papelito_Test_Product() : false;
}

function papelito_shipping_get_vendor( int $vendor_id ) {
	return array( 'id' => (int) $vendor_id );
}

$papelito_test_stock = 3;

function papelito_get_vendor_stock( $vendor_id, $product_id ): int {
	global $papelito_test_stock;
	return $papelito_test_stock;
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

function papelito_test_item( int $qty ): array {
	return array(
		'product_id'  => 11883,
		'qty'         => $qty,
		'vendor_id'   => 101,
		'vendor_name' => 'Vendor Centro',
	);
}

echo "Scenario 1: quantity equal to stock is accepted\n";
$papelito_test_stock = 3;
$result               = papelito_order_routing_resolve_items( array( papelito_test_item( 3 ) ) );
papelito_assert_same( 'equal quantity is accepted', false, is_wp_error( $result ) );
papelito_assert_same( 'accepted line keeps quantity', 3, $result['lines'][0]['qty'] ?? null );

echo "Scenario 2: direct quantity above stock is rejected\n";
$result = papelito_order_routing_resolve_items( array( papelito_test_item( 4 ) ) );
papelito_assert_same( 'above-stock quantity returns an error', true, is_wp_error( $result ) );
papelito_assert_same( 'error code identifies insufficient stock', 'papelito_checkout_insufficient_stock', $result->get_error_code() );
papelito_assert_same( 'error exposes available stock', 3, $result->get_error_data()['available'] ?? null );
papelito_assert_same( 'error exposes requested quantity', 4, $result->get_error_data()['requested'] ?? null );
papelito_assert_same( 'error is a conflict', 409, $result->get_error_data()['status'] ?? null );

echo "Scenario 3: zero stock rejects a positive quantity\n";
$papelito_test_stock = 0;
$result               = papelito_order_routing_resolve_items( array( papelito_test_item( 1 ) ) );
papelito_assert_same( 'zero stock returns an error', true, is_wp_error( $result ) );
papelito_assert_same( 'zero stock is reported', 0, $result->get_error_data()['available'] ?? null );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
