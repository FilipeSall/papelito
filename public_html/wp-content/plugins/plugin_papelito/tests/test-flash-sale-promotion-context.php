<?php
/**
 * Standalone regression test for signed flash-sale promotion contexts.
 *
 * Usage: php tests/test-flash-sale-promotion-context.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_title( mixed $value ) { return strtolower( str_replace( ' ', '-', trim( (string) $value ) ) ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_json_encode( mixed $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_salt( $scheme = 'auth' ) { return 'test-only-signing-secret'; }
function wp_unslash( mixed $value ) { return $value; }
function wc_format_decimal( mixed $value ) { return (string) $value; }

class WP_User {}
class WP_REST_Request {}
class WP_REST_Response {}
class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'PUT';
	const DELETABLE = 'DELETE';
}

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;
	public function __construct( string $code, string $message, mixed $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }

$papelito_test_campaign = array();
function get_option( $name, $default = array() ) {
	global $papelito_test_campaign;
	return $papelito_test_campaign ?: $default;
}

class WC_Product {}

require __DIR__ . '/../includes/flash_sale.php';

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

$now = time();
$papelito_test_campaign = array(
	'title'           => 'Teste de Venda',
	'starts_at'       => gmdate( DATE_ATOM, $now - 3600 ),
	'ends_at'         => gmdate( DATE_ATOM, $now + 3600 ),
	'productIds'      => array( 11776 ),
	'discountPercent' => 99,
);

echo "Scenario 1: a valid signed context resolves its current campaign\n";
$campaign = papelito_flash_sale_normalize_campaign( $papelito_test_campaign );
$token = papelito_flash_sale_create_promotion_context( $campaign, 11776 );
$resolved = papelito_flash_sale_resolve_promotion_context( $token, 11776 );
papelito_assert_same( 'token was created', true, is_string( $token ) && strlen( $token ) > 40 );
papelito_assert_same( 'valid token resolves', false, is_wp_error( $resolved ) );
papelito_assert_same( 'resolved discount', 99, $resolved['discountPercent'] ?? null );

echo "Scenario 2: tampering and wrong products are rejected\n";
$tampered = substr( $token, 0, -1 ) . ( substr( $token, -1 ) === 'a' ? 'b' : 'a' );
$tampered_result = papelito_flash_sale_resolve_promotion_context( $tampered, 11776 );
$wrong_product = papelito_flash_sale_resolve_promotion_context( $token, 11777 );
papelito_assert_same( 'tampered token rejected', 'papelito_promotion_context_invalid', $tampered_result->get_error_code() );
papelito_assert_same( 'wrong product rejected', 'papelito_promotion_context_product_mismatch', $wrong_product->get_error_code() );

echo "Scenario 3: campaign changes and expiration invalidate old contexts\n";
$papelito_test_campaign['discountPercent'] = 98;
$changed = papelito_flash_sale_resolve_promotion_context( $token, 11776 );
papelito_assert_same( 'campaign fingerprint mismatch rejected', 'papelito_promotion_context_stale', $changed->get_error_code() );

$papelito_test_campaign['discountPercent'] = 99;
$papelito_test_campaign['ends_at'] = gmdate( DATE_ATOM, $now - 1 );
$expired = papelito_flash_sale_resolve_promotion_context( $token, 11776 );
papelito_assert_same( 'expired campaign rejected', 'papelito_promotion_context_expired', $expired->get_error_code() );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
