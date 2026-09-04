<?php

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['pap_options']           = array();
$GLOBALS['pap_routes']            = array();
$GLOBALS['pap_can_manage_options'] = true;
$GLOBALS['pap_auth_rate_limit_calls'] = 0;

function add_action( $hook, $callback ) {
	if ( 'rest_api_init' === $hook ) {
		$callback();
	}
}
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['pap_routes'][ $namespace . $route ][] = $args; }
function get_option( $key, $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['pap_can_manage_options']; }
function get_current_user_id() { return 0; }
function get_transient() { return 0; }
function set_transient() { return true; }
function wp_salt( $scheme = 'auth' ) { return 'shipping-test-salt-' . $scheme; }
function papelito_auth_rate_limit() { ++$GLOBALS['pap_auth_rate_limit_calls']; return true; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) { $this->params = $params; }
	public function get_json_params() { return $this->params; }
	public function get_params() { return $this->params; }
	public function get_header() { return ''; }
}
class WP_REST_Server {
	const READABLE = 'GET';
	const EDITABLE = 'PUT';
}

function __return_true() { return true; }

function papelito_assert( $label, $expected, $actual ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $label . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

require __DIR__ . '/../includes/shipping.php';

papelito_assert( 'uses an HMAC fingerprint for cache keys', hash_hmac( 'sha256', 'buyer-42', wp_salt( 'auth' ) ), papelito_shipping_cache_fingerprint( 'buyer-42' ) );
papelito_assert( 'uses the default minimum', 9900, papelito_shipping_get_free_shipping_minimum_cents() );
$updated = papelito_shipping_update_free_shipping_minimum_cents( 12550 );
papelito_assert( 'accepts integer cents', 12550, $updated['minimumOrderCents'] );
papelito_assert( 'persists without a decimal representation', 12550, $GLOBALS['pap_options'][ PAPELITO_SHIPPING_FREE_SHIPPING_MINIMUM_OPTION ] );

foreach ( array( 0, -1, 99.5, '9900', null ) as $invalid ) {
	papelito_assert( 'rejects invalid minimum', true, is_wp_error( papelito_shipping_update_free_shipping_minimum_cents( $invalid ) ) );
}

$public_route = $GLOBALS['pap_routes']['papelito/v1/shipping/free-shipping-threshold'][0];
$public_response = $public_route['callback']( new WP_REST_Request() );
papelito_assert( 'public response status', 200, $public_response->status );
papelito_assert(
	'public response carries the minimum and the regional scope',
	array(
		'minimumOrderCents' => 12550,
		'zipRanges'         => array(),
	),
	$public_response->data
);
papelito_assert( 'public configuration does not consume the shipping quote rate-limit bucket', 0, $GLOBALS['pap_auth_rate_limit_calls'] );

$admin_routes = $GLOBALS['pap_routes']['papelito/v1/admin/shipping/free-shipping-threshold'];
$GLOBALS['pap_can_manage_options'] = false;
papelito_assert( 'non-admin is rejected', 'papelito_shipping_forbidden', $admin_routes[0]['permission_callback']()->get_error_code() );

$GLOBALS['pap_can_manage_options'] = true;
$missing_response = $admin_routes[1]['callback']( new WP_REST_Request() );
papelito_assert( 'route rejects a missing minimum', 'papelito_shipping_invalid_free_shipping_minimum', $missing_response->get_error_code() );
$invalid_response = $admin_routes[1]['callback']( new WP_REST_Request( array( 'minimumOrderCents' => 99.5 ) ) );
papelito_assert( 'route rejects decimal cents', 'papelito_shipping_invalid_free_shipping_minimum', $invalid_response->get_error_code() );
$updated_response = $admin_routes[1]['callback']( new WP_REST_Request( array( 'minimumOrderCents' => 9900 ) ) );
papelito_assert( 'route persists valid cents', 200, $updated_response->status );
papelito_assert( 'route response has configured value', 9900, $updated_response->data['minimumOrderCents'] );

// Faixas de CEP: lista vazia é território inteiro, e é o padrão.
papelito_assert( 'starts without regional restriction', array(), papelito_shipping_get_free_shipping_zip_ranges() );
papelito_assert( 'an empty list allows any destination', true, papelito_shipping_cep_allows_free_shipping( '70000000' ) );
papelito_assert( 'an empty list allows even an unknown destination', true, papelito_shipping_cep_allows_free_shipping( '' ) );

$saved_ranges = papelito_shipping_update_free_shipping_zip_ranges(
	array(
		array( 'minCep' => '70000-000', 'maxCep' => '70999999' ),
		array( 'minCep' => '01000000', 'maxCep' => '05999-999' ),
	)
);
papelito_assert( 'normalizes the mask away', array(
	array( 'minCep' => '70000000', 'maxCep' => '70999999' ),
	array( 'minCep' => '01000000', 'maxCep' => '05999999' ),
), $saved_ranges['zipRanges'] );

papelito_assert( 'a destination inside the first range is eligible', true, papelito_shipping_cep_allows_free_shipping( '70123456' ) );
papelito_assert( 'a destination inside the second range is eligible', true, papelito_shipping_cep_allows_free_shipping( '03000-000' ) );
papelito_assert( 'a destination outside every range is not eligible', false, papelito_shipping_cep_allows_free_shipping( '88000000' ) );
papelito_assert( 'the lower bound is inclusive', true, papelito_shipping_cep_allows_free_shipping( '70000000' ) );
papelito_assert( 'the upper bound is inclusive', true, papelito_shipping_cep_allows_free_shipping( '70999999' ) );
papelito_assert( 'an unknown destination is refused once ranges exist', false, papelito_shipping_cep_allows_free_shipping( '' ) );
papelito_assert( 'a malformed destination is refused once ranges exist', false, papelito_shipping_cep_allows_free_shipping( '7000' ) );

papelito_assert(
	'rejects an incomplete range',
	'papelito_shipping_invalid_free_shipping_ranges',
	papelito_shipping_update_free_shipping_zip_ranges( array( array( 'minCep' => '70000000' ) ) )->get_error_code()
);
papelito_assert(
	'names the offending range',
	'Informe CEP inicial e final válidos na faixa 2.',
	papelito_shipping_update_free_shipping_zip_ranges(
		array(
			array( 'minCep' => '70000000', 'maxCep' => '70999999' ),
			array( 'minCep' => '7000', 'maxCep' => '70999999' ),
		)
	)->get_error_message()
);
papelito_assert(
	'rejects an inverted range',
	'papelito_shipping_invalid_free_shipping_range_order',
	papelito_shipping_update_free_shipping_zip_ranges( array( array( 'minCep' => '80000000', 'maxCep' => '70000000' ) ) )->get_error_code()
);
papelito_assert(
	'rejects more ranges than the ceiling',
	'papelito_shipping_too_many_free_shipping_ranges',
	papelito_shipping_update_free_shipping_zip_ranges(
		array_fill( 0, PAPELITO_SHIPPING_FREE_SHIPPING_ZIP_RANGES_MAX + 1, array( 'minCep' => '70000000', 'maxCep' => '70999999' ) )
	)->get_error_code()
);
papelito_assert( 'a refused write leaves the stored ranges untouched', 2, count( papelito_shipping_get_free_shipping_zip_ranges() ) );
papelito_assert( 'an empty payload clears the restriction', array(), papelito_shipping_update_free_shipping_zip_ranges( array() )['zipRanges'] );

// A rota administrativa aceita os dois campos, juntos ou separados, e valida antes de gravar.
$GLOBALS['pap_options'][ PAPELITO_SHIPPING_FREE_SHIPPING_MINIMUM_OPTION ] = 9900;
$rejected = $admin_routes[1]['callback'](
	new WP_REST_Request(
		array(
			'minimumOrderCents' => 15000,
			'zipRanges'         => array( array( 'minCep' => '80000000', 'maxCep' => '70000000' ) ),
		)
	)
);
papelito_assert( 'an invalid range rejects the whole payload', 'papelito_shipping_invalid_free_shipping_range_order', $rejected->get_error_code() );
papelito_assert( 'the minimum is not written when the range is refused', 9900, papelito_shipping_get_free_shipping_minimum_cents() );

$combined = $admin_routes[1]['callback'](
	new WP_REST_Request(
		array(
			'minimumOrderCents' => 15000,
			'zipRanges'         => array( array( 'minCep' => '70000000', 'maxCep' => '70999999' ) ),
		)
	)
);
papelito_assert( 'a valid payload writes both fields', 200, $combined->status );
papelito_assert( 'the minimum was written', 15000, $combined->data['minimumOrderCents'] );
papelito_assert( 'the range was written', 1, count( $combined->data['zipRanges'] ) );

$ranges_only = $admin_routes[1]['callback']( new WP_REST_Request( array( 'zipRanges' => array() ) ) );
papelito_assert( 'ranges alone are accepted', 200, $ranges_only->status );
papelito_assert( 'the minimum survives a ranges-only write', 15000, $ranges_only->data['minimumOrderCents'] );

echo "Shipping free-shipping threshold: ok\n";
