<?php
/**
 * Standalone regression test for the configurable Home collection rail.
 *
 * Usage: php tests/test-home-assets-collections-nav.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['pap_options']            = array();
$GLOBALS['pap_routes']             = array();
$GLOBALS['pap_can_manage_options'] = true;

function add_action( mixed $hook, mixed $callback ) {
	if ( 'rest_api_init' === $hook ) {
		$callback();
	}
}
function register_rest_route( mixed $namespace, mixed $route, mixed $args ) {
	$GLOBALS['pap_routes'][ $namespace . $route ][] = $args;
}
function current_user_can( mixed $cap ) { return 'manage_options' === $cap && $GLOBALS['pap_can_manage_options']; }
function get_option( mixed $key, mixed $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( mixed $key, mixed $value, mixed $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_key( mixed $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) ); }
function wp_strip_all_tags( mixed $value ) { return strip_tags( (string) $value ); }
function esc_url_raw( mixed $value ) { return (string) $value; }
function rest_sanitize_boolean( mixed $value ) { return ! in_array( $value, array( false, 0, '0', 'false', '', null ), true ); }
function is_wp_error( mixed $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;
	public function __construct( string $code = '', string $message = '', mixed $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_REST_Response {
	public mixed $data;
	public int $status;
	public function __construct( mixed $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class WP_REST_Request {
	private mixed $params;
	public function __construct( mixed $params = array() ) { $this->params = $params; }
	public function get_json_params() { return $this->params; }
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
}

require_once __DIR__ . '/../includes/home_assets.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} - expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

echo "Collections nav: seed and defaults\n";
papelito_home_assets_seed_collections_nav();
$snapshot = papelito_home_assets_get_admin_collections_nav_snapshot();
papelito_assert( 'seed creates four cards', 4, count( $snapshot['items'] ) );
papelito_assert( 'seed has no issues', array(), $snapshot['issues'] );
papelito_assert( 'first card keeps the shipped title', 'Kits', $snapshot['items'][0]['title'] );
papelito_assert( 'first card keeps the shipped href', '/kits', $snapshot['items'][0]['href'] );
papelito_assert( 'kits card keeps the live counter', 'kits', $snapshot['items'][0]['collection'] );
papelito_assert( 'premium card has no live counter', '', $snapshot['items'][1]['collection'] );
papelito_assert( 'seed does not overwrite an existing option', 4, count( papelito_home_assets_get_raw_collections_nav_items() ) );

echo "Collections nav: normalization\n";
$reordered = papelito_home_assets_normalize_collections_nav_items(
	array(
		array(
			'id'       => 'b',
			'title'    => 'B',
			'subtitle' => 'Sub B',
			'href'     => '/b',
			'order'    => 9,
			'isActive' => true,
		),
		array(
			'id'       => 'a',
			'title'    => 'A',
			'subtitle' => 'Sub A',
			'href'     => '/a',
			'order'    => 2,
			'isActive' => true,
		),
	)
);
papelito_assert( 'stored order wins over array position', 'a', $reordered[0]['id'] );
papelito_assert( 'order is rewritten as the final position', 1, $reordered[0]['order'] );
papelito_assert( 'second card gets position two', 2, $reordered[1]['order'] );

$unknown = papelito_home_assets_normalize_collection_nav_item(
	array(
		'id'         => 'x',
		'title'      => 'X',
		'subtitle'   => 'Sub',
		'href'       => '/x',
		'collection' => 'premium',
	),
	0
);
papelito_assert( 'collection outside the whitelist is dropped', '', $unknown['collection'] );

$external = papelito_home_assets_normalize_collection_nav_item(
	array(
		'id'       => 'y',
		'title'    => 'Y',
		'subtitle' => 'Sub',
		'href'     => 'https://exemplo.com',
	),
	0
);
papelito_assert( 'external href is dropped', '', $external['href'] );

$query = papelito_home_assets_normalize_collection_nav_item(
	array(
		'id'       => 'z',
		'title'    => 'Z',
		'subtitle' => 'Sub',
		'href'     => '/colecoes?colecao=linha-eco',
	),
	0
);
papelito_assert( 'registered collection href survives', '/colecoes?colecao=linha-eco', $query['href'] );

echo "Collections nav: validation\n";
$payload = papelito_home_assets_default_collections_nav_items();
$payload[0]['title'] = '  Kits Papelito  ';
$validated = papelito_home_assets_validate_collections_nav_payload( $payload );
papelito_assert( 'validation trims the title', 'Kits Papelito', $validated[0]['title'] );
papelito_assert( 'validation renumbers the order', 4, $validated[3]['order'] );

$html = $payload;
$html[0]['title'] = '<strong>Kits</strong>';
papelito_assert( 'HTML title is rejected', 'papelito_home_assets_html_collection_nav_text', papelito_home_assets_validate_collections_nav_payload( $html )->get_error_code() );

$empty = $payload;
$empty[1]['subtitle'] = '   ';
papelito_assert( 'empty subtitle is rejected', 'papelito_home_assets_empty_collection_nav_text', papelito_home_assets_validate_collections_nav_payload( $empty )->get_error_code() );

$long = $payload;
$long[0]['title'] = str_repeat( 'a', 25 );
papelito_assert( 'long title is rejected', 'papelito_home_assets_long_collection_nav_text', papelito_home_assets_validate_collections_nav_payload( $long )->get_error_code() );

$offsite = $payload;
$offsite[2]['href'] = 'https://exemplo.com/promo';
papelito_assert( 'external href is rejected', 'papelito_home_assets_invalid_collection_nav_href', papelito_home_assets_validate_collections_nav_payload( $offsite )->get_error_code() );

$duplicated = $payload;
$duplicated[1]['id'] = $duplicated[0]['id'];
papelito_assert( 'duplicate id is rejected', 'papelito_home_assets_duplicate_collection_nav_id', papelito_home_assets_validate_collections_nav_payload( $duplicated )->get_error_code() );

$overflow = array();
for ( $i = 0; $i < 9; $i++ ) {
	$overflow[] = array(
		'id'       => 'card-' . $i,
		'title'    => 'Card',
		'subtitle' => 'Sub',
		'href'     => '/x',
		'isActive' => true,
	);
}
papelito_assert( 'more than eight cards is rejected', 'papelito_home_assets_too_many_collections_nav_items', papelito_home_assets_validate_collections_nav_payload( $overflow )->get_error_code() );

papelito_assert( 'empty list is accepted', array(), papelito_home_assets_validate_collections_nav_payload( array() ) );

echo "Collections nav: public route\n";
$public_route = $GLOBALS['pap_routes']['papelito/v1/home/collections-nav'][0]['callback'];
$response = $public_route();
papelito_assert( 'public response succeeds', 200, $response->status );
papelito_assert( 'public response keeps four cards', 4, count( $response->data['items'] ) );

$hidden = papelito_home_assets_default_collections_nav_items();
$hidden[1]['isActive'] = false;
$hidden[2]['title']    = '';
update_option( papelito_home_assets_collections_nav_option_name(), $hidden );
$response = $public_route();
papelito_assert( 'inactive card is hidden', 2, count( $response->data['items'] ) );
papelito_assert( 'order survives the filter as a stable sort key', array( 1, 4 ), array_column( $response->data['items'], 'order' ) );
papelito_assert( 'incomplete card never reaches the storefront', 'novidades', $response->data['items'][1]['id'] );

update_option( papelito_home_assets_collections_nav_option_name(), array() );
$response = $public_route();
papelito_assert( 'empty list survives instead of falling back to defaults', 0, count( $response->data['items'] ) );

echo "Collections nav: admin routes and permissions\n";
$admin_routes = $GLOBALS['pap_routes']['papelito/v1/admin/assets/collections-nav'];
$GLOBALS['pap_can_manage_options'] = false;
papelito_assert( 'non-admin cannot read', false, $admin_routes[0]['permission_callback']() );
papelito_assert( 'non-admin cannot write', false, $admin_routes[1]['permission_callback']() );

$GLOBALS['pap_can_manage_options'] = true;
papelito_assert( 'admin can read', true, $admin_routes[0]['permission_callback']() );

$saved = $admin_routes[1]['callback'](
	new WP_REST_Request(
		array(
			'items' => array(
				array(
					'id'         => 'kits',
					'title'      => 'Kits',
					'subtitle'   => 'Kits exclusivos',
					'href'       => '/kits',
					'collection' => 'kits',
					'isActive'   => true,
				),
			),
		)
	)
);
papelito_assert( 'write returns the admin snapshot', 200, $saved->status );
papelito_assert( 'write persists a single card', 1, count( $saved->data['items'] ) );
papelito_assert( 'write reports no issues', array(), $saved->data['issues'] );

$invalid_payload = array(
	'items' => array(
		array(
			'title'    => '',
			'subtitle' => '',
			'href'     => '',
		),
	),
);
$rejected        = $admin_routes[1]['callback']( new WP_REST_Request( $invalid_payload ) );
papelito_assert( 'invalid write answers 422', 422, $rejected->status );
papelito_assert( 'invalid write keeps the stored card', 1, count( papelito_home_assets_get_admin_collections_nav_snapshot()['items'] ) );

echo $failures > 0 ? "\n{$failures} failure(s)\n" : "\nAll assertions passed\n";
exit( $failures > 0 ? 1 : 0 );
