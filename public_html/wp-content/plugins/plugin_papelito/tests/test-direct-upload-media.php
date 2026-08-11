<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and exercises temporary files.
/**
 * Regression test for direct media uploads used by Home assets.
 *
 * Usage: php tests/test-direct-upload-media.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );

function add_action( mixed ...$args ): void { unset( $args ); }
function add_filter( mixed ...$args ): void { unset( $args ); }
function register_rest_route( mixed ...$args ): void { unset( $args ); }
function rest_url( string $path ): string { return 'https://wp.example/wp-json/' . $path; }
function current_user_can( string $capability ): bool { return 'manage_options' === $capability; }
function user_can( int $user_id, string $capability ): bool { return 9 === $user_id && 'manage_options' === $capability; }
function wp_set_current_user( int $user_id ): void { $GLOBALS['pap_current_user'] = $user_id; }

class WP_Error { // NOSONAR - Test double must preserve the WordPress API class name.
	public string $code;
	public string $message;
	public array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

class WP_REST_Request { // NOSONAR - Test double must preserve the WordPress API class name.
	private array $files = array();

	public function __construct( string $method = 'POST', string $route = '' ) { unset( $method, $route ); }
	public function set_file_params( array $files ): void { $this->files = $files; }
	public function get_file_params(): array { return $this->files; }
}

class WP_REST_Response { // NOSONAR - Test double must preserve the WordPress API class name.
	private mixed $data;
	private int $status;

	public function __construct( mixed $data, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

function rest_do_request( WP_REST_Request $request ): WP_REST_Response {
	$file = $request->get_file_params()['file'] ?? array();
	$GLOBALS['pap_media_request'] = array(
		'name' => $file['name'] ?? '',
		'type' => $file['type'] ?? '',
	);

	return new WP_REST_Response(
		array(
			'id'         => 42,
			'alt_text'   => 'Ícone do benefício',
			'source_url' => 'https://wp.example/uploads/beneficio.svg',
		),
		201
	);
}

require_once __DIR__ . '/../includes/media_uploads.php';
require_once __DIR__ . '/../includes/image_validation.php';
require_once __DIR__ . '/../includes/direct_uploads.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label} — esperado " . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

$path = tempnam( sys_get_temp_dir(), 'pap-svg-' );
file_put_contents( $path, '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' );

$result = papelito_direct_upload_media(
	array( 'context' => array( 'user_id' => 9 ) ),
	array(
		'tmp_name' => $path,
		'name'     => 'beneficio.svg',
		'type'     => 'image/svg+xml',
		'size'     => filesize( $path ),
	)
);

papelito_assert( 'admin SVG upload succeeds', false, is_wp_error( $result ) );
papelito_assert( 'SVG is sent to the Media Library', 42, is_array( $result ) ? $result['media']['id'] : null );
papelito_assert( 'SVG content type is preserved', 'image/svg+xml', $GLOBALS['pap_media_request']['type'] ?? null );
papelito_assert( 'SVG filename is preserved', 'beneficio.svg', $GLOBALS['pap_media_request']['name'] ?? null );

unlink( $path );

$unsafe_path = tempnam( sys_get_temp_dir(), 'pap-svg-unsafe-' );
file_put_contents( $unsafe_path, '<svg><script>alert(1)</script></svg>' );

$unsafe_result = papelito_direct_upload_media(
	array( 'context' => array( 'user_id' => 9 ) ),
	array(
		'tmp_name' => $unsafe_path,
		'name'     => 'beneficio-inseguro.svg',
		'type'     => 'image/svg+xml',
		'size'     => filesize( $unsafe_path ),
	)
);

papelito_assert( 'unsafe SVG is rejected', 'papelito_upload_svg_invalid', is_wp_error( $unsafe_result ) ? $unsafe_result->code : null );

unlink( $unsafe_path );

$non_admin_result = papelito_direct_upload_media(
	array( 'context' => array( 'user_id' => 10 ) ),
	array()
);

papelito_assert( 'non-admin media upload is rejected', 'papelito_upload_not_allowed', is_wp_error( $non_admin_result ) ? $non_admin_result->code : null );

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( 0 === $failures ? 0 : 1 );
