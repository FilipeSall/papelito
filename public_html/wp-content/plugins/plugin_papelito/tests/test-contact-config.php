<?php

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['pap_options']            = array();
$GLOBALS['pap_routes']             = array();
$GLOBALS['pap_can_manage_options'] = true;

function add_action( string $hook, callable $callback ): void {
	if ( 'rest_api_init' === $hook ) {
		$callback();
	}
}

function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['pap_routes'][ $namespace . $route ][] = $args;
}

function get_option( string $key, mixed $fallback = false ): mixed {
	return $GLOBALS['pap_options'][ $key ] ?? $fallback;
}

function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['pap_options'][ $key ] = $value;

	return true;
}

function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability && $GLOBALS['pap_can_manage_options'];
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function esc_url_raw( string $value ): string {
	return filter_var( $value, FILTER_SANITIZE_URL );
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = '',
		private array $data = array()
	) {}

	public function get_error_code(): string {
		return $this->code;
	}
}

class WP_REST_Request {
	public function __construct( private mixed $params = array() ) {}

	public function get_json_params(): mixed {
		return $this->params;
	}
}

class WP_REST_Server {
	const READABLE = 'GET';
	const EDITABLE = 'PUT';
}

function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $label . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

require_once __DIR__ . '/../includes/contact_config.php';

$public_route = $GLOBALS['pap_routes']['papelito/v1/home/contact-config'][0];
$admin_routes = $GLOBALS['pap_routes']['papelito/v1/admin/contact-config'];

papelito_assert( 'new installs expose the default social profiles', 'https://www.instagram.com/papelitobrasil/', $public_route['callback']()['social']['instagram'] );

$GLOBALS['pap_can_manage_options'] = false;
papelito_assert( 'non-admin cannot read contact config', false, $admin_routes[0]['permission_callback']() );

$GLOBALS['pap_can_manage_options'] = true;
$saved = $admin_routes[1]['callback'](
	new WP_REST_Request(
		array(
			'social' => array(
				'instagram' => 'https://www.instagram.com/outra/',
				'x'         => '',
			),
		)
	)
);
papelito_assert( 'valid social profile is persisted', 'https://www.instagram.com/outra/', $saved['social']['instagram'] );
papelito_assert( 'empty social profile remains hidden', '', $saved['social']['x'] );

$phone_only = $admin_routes[1]['callback']( new WP_REST_Request( array( 'phone' => '+556133334444' ) ) );
papelito_assert( 'partial phone update preserves social profiles', 'https://www.instagram.com/outra/', $phone_only['social']['instagram'] );

$before_invalid = $GLOBALS['pap_options'];
$invalid_url = $admin_routes[1]['callback'](
	new WP_REST_Request( array( 'social' => array( 'youtube' => 'javascript:alert(1)' ) ) )
);
papelito_assert( 'invalid scheme is rejected', 'papelito_invalid_social_profile_url', $invalid_url->get_error_code() );
papelito_assert( 'invalid social update does not write options', $before_invalid, $GLOBALS['pap_options'] );

$unknown = $admin_routes[1]['callback'](
	new WP_REST_Request( array( 'social' => array( 'facebook' => 'https://facebook.com/papelito' ) ) )
);
papelito_assert( 'unknown network is rejected', 'papelito_unknown_social_network', $unknown->get_error_code() );

$too_long = $admin_routes[1]['callback'](
	new WP_REST_Request( array( 'social' => array( 'youtube' => 'https://youtube.com/' . str_repeat( 'a', 300 ) ) ) )
);
papelito_assert( 'overlong profile is rejected', 'papelito_invalid_social_profile_url', $too_long->get_error_code() );

$empty = $admin_routes[1]['callback']( new WP_REST_Request( array() ) );
papelito_assert( 'empty payload is rejected', 'papelito_invalid_contact_config', $empty->get_error_code() );

echo "Contact config: ok\n";
