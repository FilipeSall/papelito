<?php
/**
 * Standalone regression test for explicit confirmation of 99% flash-sale discounts.
 *
 * Usage: php tests/test-flash-sale-extreme-discount.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( mixed ...$args ): void { unset( $args ); }
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_title( mixed $value ) { return strtolower( str_replace( ' ', '-', trim( (string) $value ) ) ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_json_encode( mixed $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_salt( $scheme = 'auth' ) { return 'test-only-signing-secret'; }
function wp_unslash( mixed $value ) { return $value; }
function wc_format_decimal( mixed $value ) { return (string) $value; }
function get_option( $name, $default = array() ) { return $default; }
function wc_get_product( $product_id ) { return new WC_Product(); }

class WC_Product { // NOSONAR -- o nome é o da classe do WooCommerce; renomear quebraria o código sob teste.
	public function get_status() { return 'publish'; }
}
class WP_User {} // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.
class WP_REST_Request {} // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.
class WP_REST_Response {} // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.
class WP_REST_Server { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.
	const READABLE  = 'GET';
	const EDITABLE  = 'PUT';
	const DELETABLE = 'DELETE';
}
class WP_Error { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o instanceof do código sob teste.
	private string $code;
	public function __construct( string $code, string $message, mixed $data = null ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }

require_once __DIR__ . '/../includes/flash_sale.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

$base = array(
	'title'           => 'Queimão',
	'startsAt'        => '2026-08-04T12:00:00+00:00',
	'endsAt'          => '2026-08-05T12:00:00+00:00',
	'productIds'      => array( 11794 ),
	'discountPercent' => 99,
);

echo "Scenario 1: 99% requires explicit confirmation\n";
$without_confirmation = papelito_flash_sale_validate_input( $base, array() );
papelito_assert_same(
	'99 percent is rejected without confirmation',
	'papelito_flash_sale_extreme_discount_confirmation_required',
	$without_confirmation->get_error_code()
);

echo "Scenario 2: explicit confirmation permits 99%\n";
$with_confirmation = papelito_flash_sale_validate_input(
	array_merge( $base, array( 'extremeDiscountConfirmed' => true ) ),
	array()
);
papelito_assert_same( 'confirmed campaign is accepted', false, is_wp_error( $with_confirmation ) );
papelito_assert_same( 'confirmed discount remains 99', 99, $with_confirmation['discountPercent'] ?? null );

echo "Scenario 3: ordinary discounts do not need confirmation\n";
$ordinary = papelito_flash_sale_validate_input(
	array_merge( $base, array( 'discountPercent' => 50 ) ),
	array()
);
papelito_assert_same( '50 percent campaign is accepted', false, is_wp_error( $ordinary ) );
papelito_assert_same( 'ordinary discount remains editable', 50, $ordinary['discountPercent'] ?? null );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
