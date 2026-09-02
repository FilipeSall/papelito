<?php
/**
 * Standalone regression test for administrable site logos and SVG upload guards.
 *
 * Usage: php tests/test-home-assets-logos.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_TEST_CUSTOM_FOOTER_ALT', 'Rodape custom' );
define( 'PAPELITO_TEST_PUBLIC_LOGO_URL', '/images/nova-publica.svg' );
define( 'PAPELITO_TEST_PNG_MIME_TYPE', 'image/png' );
define( 'PAPELITO_TEST_SVG_MIME_TYPE', 'image/svg+xml' );

$GLOBALS['pap_options']            = array();
$GLOBALS['pap_attachments']        = array();
$GLOBALS['pap_can_manage_options'] = true;
$GLOBALS['pap_filters']            = array();

function add_action( ...$args ) {
	// The standalone test does not execute WordPress hooks; registration is intentionally a no-op.
}
function register_rest_route( ...$args ) {
	// The standalone test calls the handlers directly; route registration is intentionally a no-op.
}
function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ) {
	$GLOBALS['pap_filters'][ $hook ][] = $callback;
	return true;
}
function papelito_test_apply_filter( string $hook, ...$args ) {
	$value = $args[0];
	foreach ( $GLOBALS['pap_filters'][ $hook ] ?? array() as $callback ) {
		$args[0] = $value;
		$value   = $callback( ...$args );
	}
	return $value;
}
function current_user_can( mixed $cap ) { return 'manage_options' === $cap && $GLOBALS['pap_can_manage_options']; }
function get_option( mixed $key, mixed $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( mixed $key, mixed $value, mixed $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function delete_option( mixed $key ) { unset( $GLOBALS['pap_options'][ $key ] ); return true; }
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_key( mixed $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) ); }
function sanitize_textarea_field( mixed $value ) { return trim( wp_strip_all_tags( (string) $value ) ); }
function wp_strip_all_tags( mixed $value ) { return strip_tags( (string) $value ); }
function esc_url_raw( mixed $value ) { return (string) $value; }
function wp_http_validate_url( mixed $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_URL ); }
function rest_sanitize_boolean( mixed $value ) { return ! in_array( $value, array( false, 0, '0', 'false', '', null ), true ); }
function wp_generate_uuid4() { return 'uuid-' . count( $GLOBALS['pap_options'] ); }
function wp_get_attachment_image_url( mixed $id, mixed $size = 'full' ) { return $GLOBALS['pap_attachments'][ $id ] ?? false; }

class WP_Error {
	public mixed $code;
	public mixed $message;
	public mixed $data;
	public function __construct( mixed $code = '', mixed $message = '', mixed $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( mixed $thing ) { return $thing instanceof WP_Error; }

class WP_REST_Response {
	public mixed $data;
	public mixed $status;
	public function __construct( mixed $data = null, mixed $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class WP_REST_Request {
	private mixed $params;
	public function __construct( mixed $params = array() ) { $this->params = $params; }
	public function get_json_params() { return $this->params; }
	public function get_param( mixed $key ) { return $this->params[ $key ] ?? null; }
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
}

require_once __DIR__ . '/../includes/home_assets.php';
require_once __DIR__ . '/../includes/media_uploads.php';

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

echo "Logos: defaults\n";
$snapshot = papelito_home_assets_get_admin_logos_snapshot();
papelito_assert( 'exposes exactly three logos', array( 'publicHeader', 'privateHeader', 'footer' ), array_keys( $snapshot['logos'] ) );
papelito_assert( 'defaults have no issues', array(), $snapshot['issues'] );

echo "Logos: per-key fallback\n";
$GLOBALS['pap_options']['papelito_site_logos'] = array(
	'footer' => array(
		'imageId'  => 0,
		'imageUrl' => '/images/custom-footer.svg',
		'alt'      => PAPELITO_TEST_CUSTOM_FOOTER_ALT,
	),
);
$snapshot = papelito_home_assets_get_admin_logos_snapshot();
papelito_assert( 'stored key wins', '/images/custom-footer.svg', $snapshot['logos']['footer']['imageUrl'] );
papelito_assert( 'stored alt wins', PAPELITO_TEST_CUSTOM_FOOTER_ALT, $snapshot['logos']['footer']['alt'] );
papelito_assert( 'missing key falls back to default', papelito_home_assets_default_logos()['privateHeader']['imageUrl'], $snapshot['logos']['privateHeader']['imageUrl'] );

echo "Logos: attachment id resolution\n";
$GLOBALS['pap_attachments'][77]                = 'https://wp.example/wp-content/uploads/logo-nova.svg';
$GLOBALS['pap_options']['papelito_site_logos'] = array(
	'privateHeader' => array(
		'imageId'  => 77,
		'imageUrl' => '/images/marketplacelogo.svg',
		'alt'      => 'Marketplace',
	),
);
$snapshot = papelito_home_assets_get_admin_logos_snapshot();
papelito_assert( 'attachment url wins over stored url', 'https://wp.example/wp-content/uploads/logo-nova.svg', $snapshot['logos']['privateHeader']['imageUrl'] );

echo "Logos: validation\n";
$invalid = papelito_home_assets_validate_logos_payload( 'nope' );
papelito_assert( 'non-array payload is rejected', 'papelito_home_assets_invalid_logos_payload', $invalid->get_error_code() );
papelito_assert( 'rejection carries 422', 422, $invalid->get_error_data()['status'] );

$validated = papelito_home_assets_validate_logos_payload(
	array(
		'publicHeader' => array(
			'imageId'  => 0,
			'imageUrl' => PAPELITO_TEST_PUBLIC_LOGO_URL,
			'alt'      => 'Publica',
		),
	)
);
papelito_assert( 'valid payload normalizes all keys', array( 'publicHeader', 'privateHeader', 'footer' ), array_keys( $validated ) );
papelito_assert( 'valid payload keeps sent url', PAPELITO_TEST_PUBLIC_LOGO_URL, $validated['publicHeader']['imageUrl'] );
papelito_assert( 'valid payload fills missing key from default', papelito_home_assets_default_logos()['footer']['imageUrl'], $validated['footer']['imageUrl'] );

$blank_alt = papelito_home_assets_validate_logos_payload(
	array(
		'publicHeader' => array(
			'imageId'  => 0,
			'imageUrl' => PAPELITO_TEST_PUBLIC_LOGO_URL,
			'alt'      => '   ',
		),
	)
);
papelito_assert( 'blank alt falls back to default instead of failing', papelito_home_assets_default_logos()['publicHeader']['alt'], $blank_alt['publicHeader']['alt'] );

$external_url = papelito_home_assets_validate_logos_payload(
	array(
		'footer' => array(
			'imageId'  => 0,
			'imageUrl' => 'javascript:alert(1)',
			'alt'      => 'Rodape',
		),
	)
);
papelito_assert( 'unsafe url falls back to default', papelito_home_assets_default_logos()['footer']['imageUrl'], $external_url['footer']['imageUrl'] );

echo "Logos: restore default\n";
$GLOBALS['pap_options']['papelito_site_logos'] = array(
	'privateHeader' => array(
		'imageId'  => 0,
		'imageUrl' => '/images/privada-custom.svg',
		'alt'      => 'Privada custom',
	),
	'footer'        => array(
		'imageId'  => 0,
		'imageUrl' => '/images/rodape-custom.svg',
		'alt'      => PAPELITO_TEST_CUSTOM_FOOTER_ALT,
	),
);
$restored = papelito_home_assets_restore_default_logo( 'privateHeader' );
papelito_assert( 'restored key returns to default', papelito_home_assets_default_logos()['privateHeader']['imageUrl'], $restored['logos']['privateHeader']['imageUrl'] );
papelito_assert( 'other keys are untouched', '/images/rodape-custom.svg', $restored['logos']['footer']['imageUrl'] );
papelito_assert( 'restored key is removed from the option', false, array_key_exists( 'privateHeader', $GLOBALS['pap_options']['papelito_site_logos'] ) );

$bad_key = papelito_home_assets_restore_default_logo( 'naoExiste' );
papelito_assert( 'unknown key is rejected', 'papelito_home_assets_invalid_logo_key', $bad_key->get_error_code() );
papelito_assert( 'unknown key keeps the option intact', true, array_key_exists( 'footer', $GLOBALS['pap_options']['papelito_site_logos'] ) );
papelito_assert( 'valid key check accepts known keys', true, papelito_home_assets_is_valid_logo_key( 'footer' ) );
papelito_assert( 'valid key check rejects non-strings', false, papelito_home_assets_is_valid_logo_key( 42 ) );

echo "SVG uploads: mime allowance\n";
$GLOBALS['pap_can_manage_options'] = true;
$mimes                            = papelito_test_apply_filter( 'upload_mimes', array( 'png' => PAPELITO_TEST_PNG_MIME_TYPE ) );
papelito_assert( 'admin gets svg mime', PAPELITO_TEST_SVG_MIME_TYPE, $mimes['svg'] ?? null );

$GLOBALS['pap_can_manage_options'] = false;
$mimes                            = papelito_test_apply_filter( 'upload_mimes', array( 'png' => PAPELITO_TEST_PNG_MIME_TYPE ) );
papelito_assert( 'non-admin does not get svg mime', null, $mimes['svg'] ?? null );

$GLOBALS['pap_can_manage_options'] = true;
$checked                          = papelito_test_apply_filter(
	'wp_check_filetype_and_ext',
	array(
		'ext'  => false,
		'type' => false,
	),
	'/tmp/logo.svg',
	'logo.svg'
);
papelito_assert( 'filetype check fixes svg ext', 'svg', $checked['ext'] );
papelito_assert( 'filetype check fixes svg type', PAPELITO_TEST_SVG_MIME_TYPE, $checked['type'] );

$checked = papelito_test_apply_filter(
	'wp_check_filetype_and_ext',
	array(
		'ext'  => 'png',
		'type' => PAPELITO_TEST_PNG_MIME_TYPE,
	),
	'/tmp/logo.png',
	'logo.png'
);
papelito_assert( 'filetype check leaves non-svg alone', PAPELITO_TEST_PNG_MIME_TYPE, $checked['type'] );

echo "SVG uploads: content guard\n";
papelito_assert( 'clean svg is accepted', true, papelito_media_svg_contents_are_safe( '<svg viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>' ) );
papelito_assert( 'script is rejected', false, papelito_media_svg_contents_are_safe( '<svg><script>alert(1)</script></svg>' ) );
papelito_assert( 'event handler is rejected', false, papelito_media_svg_contents_are_safe( '<svg onload="alert(1)"></svg>' ) );
papelito_assert( 'javascript href is rejected', false, papelito_media_svg_contents_are_safe( '<svg><a href="javascript:alert(1)">x</a></svg>' ) );
papelito_assert( 'external ref is rejected', false, papelito_media_svg_contents_are_safe( '<svg><image xlink:href="https://evil.test/x.png"/></svg>' ) );
papelito_assert( 'entity is rejected', false, papelito_media_svg_contents_are_safe( '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg/>' ) );
papelito_assert( 'foreignObject is rejected', false, papelito_media_svg_contents_are_safe( '<svg><foreignObject><body/></foreignObject></svg>' ) );
papelito_assert( 'empty file is rejected', false, papelito_media_svg_contents_are_safe( '   ' ) );

echo "SVG uploads: prefilter\n";
function papelito_test_svg_upload( string $contents ): array {
	$path = tempnam( sys_get_temp_dir(), 'pap-svg-' );
	file_put_contents( $path, $contents );
	return array(
		'name'     => 'logo.svg',
		'tmp_name' => $path,
		'type'     => PAPELITO_TEST_SVG_MIME_TYPE,
	);
}

$GLOBALS['pap_can_manage_options'] = true;
$clean                            = papelito_test_apply_filter( 'wp_handle_upload_prefilter', papelito_test_svg_upload( '<svg viewBox="0 0 4 4"/>' ) );
papelito_assert( 'clean svg passes the prefilter', false, isset( $clean['error'] ) );

$dirty = papelito_test_apply_filter( 'wp_handle_upload_prefilter', papelito_test_svg_upload( '<svg><script>alert(1)</script></svg>' ) );
papelito_assert( 'dirty svg is blocked by the prefilter', true, isset( $dirty['error'] ) );

$GLOBALS['pap_can_manage_options'] = false;
$forbidden                        = papelito_test_apply_filter( 'wp_handle_upload_prefilter', papelito_test_svg_upload( '<svg viewBox="0 0 4 4"/>' ) );
papelito_assert( 'non-admin svg upload is blocked', true, isset( $forbidden['error'] ) );

$GLOBALS['pap_can_manage_options'] = true;
$png                              = papelito_test_apply_filter(
	'wp_handle_upload_prefilter',
	array(
		'name'     => 'logo.png',
		'tmp_name' => '/tmp/does-not-matter.png',
		'type'     => PAPELITO_TEST_PNG_MIME_TYPE,
	)
);
papelito_assert( 'png upload is not inspected', false, isset( $png['error'] ) );

echo "\n";
if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s)\n";
	exit( 1 );
}

echo "All logo assertions passed.\n";
exit( 0 );
