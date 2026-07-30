<?php
/**
 * Standalone regression test for configurable Home commercial benefits.
 *
 * Usage: php tests/test-home-assets-features.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['pap_options']            = array();
$GLOBALS['pap_routes']             = array();
$GLOBALS['pap_attachments']        = array( 10 => 'https://wp.example/uploads/custom.svg' );
$GLOBALS['pap_can_manage_options'] = true;

function add_action( $hook, $callback ) {
	if ( 'rest_api_init' === $hook ) {
		$callback();
	}
}
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['pap_routes'][ $namespace . $route ][] = $args;
}
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['pap_can_manage_options']; }
function get_option( $key, $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) ); }
function sanitize_textarea_field( $value ) { return trim( wp_strip_all_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_http_validate_url( $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_URL ); }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function wp_get_attachment_url( $id ) { return $GLOBALS['pap_attachments'][ $id ] ?? false; }
function get_post_mime_type( $id ) { return 10 === (int) $id ? 'image/svg+xml' : false; }
function rest_sanitize_boolean( $value ) { return ! in_array( $value, array( false, 0, '0', 'false', '', null ), true ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	public $code;
	public $message;
	public $data;
	function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	function get_error_code() { return $this->code; }
	function get_error_message() { return $this->message; }
	function get_error_data() { return $this->data; }
}

class WP_REST_Response {
	public $data;
	public $status;
	function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class WP_REST_Request {
	private $params;
	function __construct( $params = array() ) { $this->params = $params; }
	function get_json_params() { return $this->params; }
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
}

require __DIR__ . '/../includes/home_assets.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} - expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

echo "Home features: seed and fallback\n";
papelito_home_assets_seed_features();
$snapshot = papelito_home_assets_get_admin_features_snapshot();
papelito_assert( 'seed creates four benefits', 4, count( $snapshot['items'] ) );
papelito_assert( 'first default title is preserved', 'Frete Grátis', $snapshot['items'][0]['title'] );
papelito_assert( 'default SVG is preserved', '/images/icons/truck.svg', $snapshot['items'][0]['iconUrl'] );

echo "Home features: validation\n";
$payload = $snapshot['items'];
$payload[0]['title']    = '  Frete Especial  ';
$payload[0]['subtitle'] = '  Acima de R$700  ';
$payload[0]['iconId']   = 10;
$payload[0]['iconUrl']  = '/images/icons/old.svg';
$validated = papelito_home_assets_validate_features_payload( $payload );
papelito_assert( 'validation trims title', 'Frete Especial', $validated[0]['title'] );
papelito_assert( 'validation trims subtitle', 'Acima de R$700', $validated[0]['subtitle'] );
papelito_assert( 'attachment SVG URL wins', 'https://wp.example/uploads/custom.svg', $validated[0]['iconUrl'] );

$html = $payload;
$html[0]['title'] = '<strong>Frete</strong>';
papelito_assert( 'HTML title is rejected', 'papelito_home_assets_html_feature_text', papelito_home_assets_validate_features_payload( $html )->get_error_code() );

$empty = $payload;
$empty[0]['subtitle'] = '';
papelito_assert( 'empty subtitle is rejected', 'papelito_home_assets_empty_feature_text', papelito_home_assets_validate_features_payload( $empty )->get_error_code() );

$long = $payload;
$long[0]['title'] = str_repeat( 'a', 33 );
papelito_assert( 'long title is rejected', 'papelito_home_assets_long_feature_text', papelito_home_assets_validate_features_payload( $long )->get_error_code() );

$invalid_icon = $payload;
$invalid_icon[0]['iconId']  = 0;
$invalid_icon[0]['iconUrl'] = '/images/icons/bad.png';
papelito_assert( 'non-SVG icon is rejected', 'papelito_home_assets_invalid_feature_icon', papelito_home_assets_validate_features_payload( $invalid_icon )->get_error_code() );

echo "Home features: public route and permissions\n";
$public_route = $GLOBALS['pap_routes']['papelito/v1/home/features'][0]['callback'];
$public_response = $public_route();
papelito_assert( 'public response succeeds', 200, $public_response->status );
papelito_assert( 'public response keeps four items', 4, count( $public_response->data['items'] ) );

$admin_routes = $GLOBALS['pap_routes']['papelito/v1/admin/assets/features'];
$GLOBALS['pap_can_manage_options'] = false;
papelito_assert( 'non-admin cannot read', false, $admin_routes[0]['permission_callback']() );
papelito_assert( 'non-admin cannot write', false, $admin_routes[1]['permission_callback']() );

$GLOBALS['pap_can_manage_options'] = true;
$admin_response = $admin_routes[1]['callback']( new WP_REST_Request( array( 'items' => $validated ) ) );
papelito_assert( 'admin write succeeds', 200, $admin_response->status );
papelito_assert( 'admin write stores one collection', 4, count( $GLOBALS['pap_options']['papelito_home_features'] ) );

echo "\n";
if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s)\n";
	exit( 1 );
}

echo "All Home feature assertions passed.\n";
exit( 0 );
