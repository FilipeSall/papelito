<?php
/**
 * Standalone regression test for the administrable promotion marquee.
 *
 * Usage: php tests/test-home-assets-promo-marquee.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['pap_options']            = array();
$GLOBALS['pap_can_manage_options'] = true;
$GLOBALS['pap_routes']             = array();

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
function rest_sanitize_boolean( $value ) { return ! in_array( $value, array( false, 0, '0', 'false', '', null ), true ); }
function wp_generate_uuid4() { return 'uuid-' . count( $GLOBALS['pap_options'] ); }
function wp_get_attachment_image_url( $id, $size = 'full' ) { return false; }
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
	const DELETABLE  = 'DELETE';
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

function papelito_promo_marquee_payload( int $active_count ): array {
	$total = max( 3, $active_count );
	$items = array();

	for ( $index = 0; $index < $total; $index++ ) {
		$items[] = array(
			'id'       => 'generated-' . $index,
			'text'     => 'Mensagem gerada ' . ( $index + 1 ),
			'order'    => $index + 1,
			'isActive' => $index < $active_count,
		);
	}

	return $items;
}

echo "Promo marquee: seed and defaults\n";
papelito_home_assets_seed_promo_marquee();
$snapshot = papelito_home_assets_get_admin_promo_marquee_snapshot();
papelito_assert( 'seed creates six messages', 6, count( $snapshot['messages'] ) );
papelito_assert( 'seed keeps emoji in text', '⚡ COMPRE 3 LEVE 4 em Sedas', $snapshot['messages'][0]['text'] );

echo "Promo marquee: validation\n";
$validated = papelito_home_assets_validate_promo_marquee_payload(
	array(
		array(
			'id'       => 'second',
			'text'     => '  <strong>Oferta segura</strong>  ',
			'order'    => 2,
			'isActive' => false,
		),
		array(
			'id'       => 'first',
			'text'     => 'Primeira oferta',
			'order'    => 1,
			'isActive' => true,
		),
		array(
			'id'       => 'third',
			'text'     => 'Terceira oferta',
			'order'    => 3,
			'isActive' => true,
		),
		array(
			'id'       => 'fourth',
			'text'     => 'Quarta oferta',
			'order'    => 4,
			'isActive' => true,
		),
	)
);
papelito_assert( 'validation trims and removes html', 'Oferta segura', $validated[0]['text'] );
papelito_assert( 'validation normalizes submitted order', 1, $validated[0]['order'] );
papelito_assert( 'validation preserves inactive status', false, $validated[0]['isActive'] );
papelito_assert(
	'empty collection is rejected by minimum active rule',
	'papelito_home_assets_min_active_promo_marquee',
	papelito_home_assets_validate_promo_marquee_payload( array() )->get_error_code()
);

foreach ( array( 0, 1, 2 ) as $active_count ) {
	$invalid = papelito_home_assets_validate_promo_marquee_payload(
		papelito_promo_marquee_payload( $active_count )
	);
	papelito_assert(
		sprintf( '%d active messages are rejected', $active_count ),
		'papelito_home_assets_min_active_promo_marquee',
		$invalid->get_error_code()
	);
}

foreach ( array( 3, 5 ) as $active_count ) {
	$valid = papelito_home_assets_validate_promo_marquee_payload(
		papelito_promo_marquee_payload( $active_count )
	);
	papelito_assert( sprintf( '%d active messages are accepted', $active_count ), false, is_wp_error( $valid ) );
}

$empty = papelito_home_assets_validate_promo_marquee_payload(
	array( array( 'id' => 'empty', 'text' => '   ', 'isActive' => true ) )
);
papelito_assert( 'empty text is rejected', 'papelito_home_assets_empty_promo_marquee_text', $empty->get_error_code() );

$long = papelito_home_assets_validate_promo_marquee_payload(
	array( array( 'id' => 'long', 'text' => str_repeat( 'a', 121 ), 'isActive' => true ) )
);
papelito_assert( 'long text is rejected', 'papelito_home_assets_long_promo_marquee_text', $long->get_error_code() );

echo "Promo marquee: public filtering and persistence\n";
$GLOBALS['pap_options']['papelito_home_promo_marquee'] = array(
	array( 'id' => 'inactive', 'text' => 'Não aparece', 'order' => 1, 'isActive' => false ),
	array( 'id' => 'active', 'text' => 'Aparece uma vez na coleção', 'order' => 2, 'isActive' => true ),
);
$public_routes = $GLOBALS['pap_routes']['papelito/v1/home/promo-marquee'];
$public_response = $public_routes[0]['callback']();
papelito_assert( 'public response succeeds', 200, $public_response->status );
papelito_assert( 'public response hides fewer than three active items', 0, count( $public_response->data['messages'] ) );
papelito_assert( 'persisted collection is not duplicated', 2, count( $GLOBALS['pap_options']['papelito_home_promo_marquee'] ) );

$GLOBALS['pap_options']['papelito_home_promo_marquee'] = array(
	array( 'id' => 'inactive', 'text' => 'Desativada', 'order' => 1, 'isActive' => false ),
);
$public_response = $public_routes[0]['callback']();
papelito_assert( 'empty active response is still successful', 200, $public_response->status );
papelito_assert( 'empty active response has no messages', array(), $public_response->data['messages'] );

echo "Promo marquee: permissions and admin write\n";
$admin_routes = $GLOBALS['pap_routes']['papelito/v1/admin/assets/promo-marquee'];
$GLOBALS['pap_can_manage_options'] = false;
papelito_assert( 'non-admin cannot read', false, $admin_routes[0]['permission_callback']() );
papelito_assert( 'non-admin cannot write', false, $admin_routes[1]['permission_callback']() );

$GLOBALS['pap_can_manage_options'] = true;
$admin_response = $admin_routes[1]['callback'](
	new WP_REST_Request(
		array(
			'messages' => papelito_promo_marquee_payload( 1 ),
		)
	)
);
papelito_assert( 'admin write rejects fewer than three active items', 422, $admin_response->status );

$admin_response = $admin_routes[1]['callback'](
	new WP_REST_Request( array( 'messages' => papelito_promo_marquee_payload( 3 ) ) )
);
papelito_assert( 'admin write succeeds with three active items', 200, $admin_response->status );
papelito_assert( 'admin write stores one original collection', 3, count( $GLOBALS['pap_options']['papelito_home_promo_marquee'] ) );
papelito_assert( 'admin write normalizes order', 1, $GLOBALS['pap_options']['papelito_home_promo_marquee'][0]['order'] );

echo "\n";
if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s)\n";
	exit( 1 );
}

echo "All promo marquee assertions passed.\n";
exit( 0 );
