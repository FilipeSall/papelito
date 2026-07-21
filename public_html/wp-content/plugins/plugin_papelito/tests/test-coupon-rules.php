<?php
/**
 * Standalone regression tests for the real coupon resolver.
 *
 * Usage: php tests/test-coupon-rules.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_key( mixed $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function wc_price( mixed $value ) { return 'R$ ' . number_format( (float) $value, 2, ',', '.' ); }

class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_User {
	public function __construct( public array $roles ) {}
}

class WC_DateTime extends DateTimeImmutable {}

$coupon_fixtures = array();
$coupon_codes    = array();
$coupon_meta     = array();

class WC_Coupon {
	private array $fixture;
	public function __construct( int $id = 0 ) {
		global $coupon_fixtures;
		$this->fixture = $coupon_fixtures[ $id ] ?? array();
	}
	public function get_date_expires() { return $this->fixture['expires'] ?? null; }
	public function get_usage_limit() { return $this->fixture['usage_limit'] ?? 0; }
	public function get_usage_count() { return $this->fixture['usage_count'] ?? 0; }
	public function get_usage_limit_per_user() { return $this->fixture['usage_limit_per_user'] ?? 0; }
	public function get_minimum_amount() { return $this->fixture['minimum_amount'] ?? 0; }
	public function get_discount_type() { return $this->fixture['discount_type'] ?? 'percent'; }
	public function get_amount() { return $this->fixture['amount'] ?? 10; }
}

function wc_get_coupon_id_by_code( string $code ) {
	global $coupon_codes;
	return $coupon_codes[ strtoupper( $code ) ] ?? 0;
}

function get_post( int $id ) {
	global $coupon_fixtures;
	if ( ! isset( $coupon_fixtures[ $id ] ) ) return null;
	return (object) array(
		'post_type'   => $coupon_fixtures[ $id ]['post_type'] ?? 'shop_coupon',
		'post_status' => $coupon_fixtures[ $id ]['status'] ?? 'publish',
	);
}

function get_post_meta( int $id, string $key, bool $single = false ) {
	global $coupon_meta;
	$value = $coupon_meta[ $id ][ $key ] ?? ( $single ? '' : array() );
	return $single ? $value : (array) $value;
}

function get_user_by( string $field, int $id ) {
	return 10 === $id ? new WP_User( array( 'customer' ) ) : null;
}

require __DIR__ . '/../includes/coupons.php';

$failures = 0;
function coupon_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

function coupon_fixture( string $code, array $fixture = array(), array $meta = array() ): int {
	global $coupon_fixtures, $coupon_codes, $coupon_meta;
	$id = count( $coupon_fixtures ) + 1;
	$coupon_codes[ $code ]    = $id;
	$coupon_fixtures[ $id ]   = $fixture;
	$coupon_meta[ $id ]       = array_merge(
		array(
			PAPELITO_COUPON_META_ROLE        => 'customer',
			PAPELITO_COUPON_META_VENDOR_IDS  => array(),
			PAPELITO_COUPON_META_PRODUCT_IDS => array(),
		),
		$meta
	);
	return $id;
}

function coupon_item( int $product_id = 11776, int $vendor_id = 101, int $qty = 3, float $price = 33.33 ): array {
	return array( 'product_id' => $product_id, 'vendor_id' => $vendor_id, 'qty' => $qty, 'price' => $price );
}

echo "Scenario 1: percent and fixed coupons preserve currency rounding\n";
coupon_fixture( 'TEN', array( 'amount' => 10 ) );
coupon_fixture( 'NINETY9', array( 'amount' => 99 ) );
coupon_fixture( 'FIXED', array( 'discount_type' => 'fixed_cart', 'amount' => 150 ) );
$ten        = papelito_coupon_apply_resolve( 'TEN', array( coupon_item() ), 10 );
$ninety_nine = papelito_coupon_apply_resolve( 'NINETY9', array( coupon_item() ), 10 );
$fixed      = papelito_coupon_apply_resolve( 'FIXED', array( coupon_item() ), 10 );
coupon_assert_same( '10 percent rounds to R$10.00', 10.0, $ten['discount_value'] ?? null );
coupon_assert_same( '99 percent rounds to R$98.99', 98.99, $ninety_nine['discount_value'] ?? null );
coupon_assert_same( 'fixed coupon is capped at subtotal', 99.99, $fixed['discount_value'] ?? null );

echo "Scenario 2: invalid, draft and expired coupons are rejected\n";
coupon_fixture( 'DRAFT', array( 'status' => 'draft' ) );
coupon_fixture( 'EXPIRED', array( 'expires' => new WC_DateTime( '-1 day' ) ) );
$missing = papelito_coupon_apply_resolve( 'MISSING', array( coupon_item() ), 10 );
$draft   = papelito_coupon_apply_resolve( 'DRAFT', array( coupon_item() ), 10 );
$expired = papelito_coupon_apply_resolve( 'EXPIRED', array( coupon_item() ), 10 );
coupon_assert_same( 'unknown coupon', 'papelito_coupon_not_found', $missing->get_error_code() );
coupon_assert_same( 'draft coupon', 'papelito_coupon_not_found', $draft->get_error_code() );
coupon_assert_same( 'expired coupon', 'papelito_coupon_expired', $expired->get_error_code() );

echo "Scenario 3: total and per-user limits are enforced\n";
$total_id = coupon_fixture( 'TOTAL_LIMIT', array( 'usage_limit' => 2, 'usage_count' => 2 ) );
$user_id  = coupon_fixture( 'USER_LIMIT', array( 'usage_limit_per_user' => 1 ) );
$coupon_meta[ $user_id ]['_used_by'] = array( 10 );
$total_limit = papelito_coupon_apply_resolve( 'TOTAL_LIMIT', array( coupon_item() ), 10 );
$user_limit  = papelito_coupon_apply_resolve( 'USER_LIMIT', array( coupon_item() ), 10 );
coupon_assert_same( 'total usage limit', 'papelito_coupon_usage_limit_total', $total_limit->get_error_code() );
coupon_assert_same( 'per-user usage limit', 'papelito_coupon_usage_limit_user', $user_limit->get_error_code() );

echo "Scenario 4: vendor, product and minimum-subtotal restrictions are enforced\n";
coupon_fixture( 'VENDOR', array(), array( PAPELITO_COUPON_META_VENDOR_IDS => array( 202 ) ) );
coupon_fixture( 'PRODUCT', array(), array( PAPELITO_COUPON_META_PRODUCT_IDS => array( 999 ) ) );
coupon_fixture( 'MINIMUM', array( 'minimum_amount' => 100 ) );
$vendor  = papelito_coupon_apply_resolve( 'VENDOR', array( coupon_item() ), 10 );
$product = papelito_coupon_apply_resolve( 'PRODUCT', array( coupon_item() ), 10 );
$minimum = papelito_coupon_apply_resolve( 'MINIMUM', array( coupon_item() ), 10 );
coupon_assert_same( 'vendor restriction', 'papelito_coupon_vendor_restricted', $vendor->get_error_code() );
coupon_assert_same( 'product restriction', 'papelito_coupon_product_restricted', $product->get_error_code() );
coupon_assert_same( 'minimum subtotal', 'papelito_coupon_minimum_not_met', $minimum->get_error_code() );

echo "Scenario 5: creation validation accepts a 99 percent coupon\n";
$validated = papelito_coupon_validate_input(
	array( 'code' => 'SAVE99', 'discount_type' => 'percent', 'amount' => 99, 'role' => 'any' )
);
coupon_assert_same( '99 percent remains numeric 99', 99.0, $validated['amount'] ?? null );
coupon_assert_same( 'percent type remains percent', 'percent', $validated['discount_type'] ?? null );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
