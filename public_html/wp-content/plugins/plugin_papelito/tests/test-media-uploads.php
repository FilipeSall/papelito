<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and intentionally performs temporary filesystem operations.
/**
 * Testes das protecoes de armazenamento de midia publica.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
	public $code;
	public $data;
	public $message;

	public function __construct( $code, $message, $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['pap_media_filters'][ $hook ][] = $callback;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_upload_dir() {
	return $GLOBALS['pap_media_upload_dir'];
}

function wp_mkdir_p( $path ) {
	if ( ! empty( $GLOBALS['pap_media_mkdir_fails'] ) ) {
		return false;
	}

	return mkdir( $path, 0755, true ) || is_dir( $path );
}

function current_user_can() {
	return true;
}

require __DIR__ . '/../includes/media_uploads.php';

$failures = 0;

function papelito_media_assert( string $label, $expected, $actual ): void {
	global $failures;

	if ( $expected !== $actual ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}

	echo "PASS: {$label}\n";
}

$temporary_root = sys_get_temp_dir() . '/papelito-media-' . uniqid( '', true );
$monthly_path   = $temporary_root . '/2026/07';

$GLOBALS['pap_media_mkdir_fails'] = false;
$GLOBALS['pap_media_upload_dir']  = array(
	'error' => false,
	'path'  => $monthly_path,
);

$prepared = papelito_media_prepare_public_upload();
papelito_media_assert( 'creates the missing year and month directory', true, true === $prepared && is_dir( $monthly_path ) );

$GLOBALS['pap_media_upload_dir'] = array(
	'error' => 'Could not create the upload directory because the parent is not writable.',
	'path'  => $temporary_root . '/2026/08',
);
$not_writable = papelito_media_prepare_public_upload();
papelito_media_assert( 'reports a directory without write permission', 'papelito_media_upload_directory_unavailable', $not_writable->code );
papelito_media_assert( 'returns a retryable status for storage failures', 503, $not_writable->data['status'] );

$GLOBALS['pap_media_upload_dir']  = array(
	'error' => false,
	'path'  => $temporary_root . '/2026/09',
);
$GLOBALS['pap_media_mkdir_fails'] = true;
$mkdir_failure                     = papelito_media_prepare_public_upload();
papelito_media_assert( 'reports a failed directory creation', 'papelito_media_upload_directory_unavailable', $mkdir_failure->code );

$GLOBALS['pap_media_mkdir_fails'] = false;
$move_failure                      = array( 'error' => 'The uploaded file could not be moved to wp-content/uploads/2026/07.' );
papelito_media_assert( 'keeps the WordPress move failure intact for the REST controller', $move_failure, papelito_media_capture_upload_error( $move_failure, 'move_sideload_file' ) );

$request = new class() {
	public function get_route() {
		return '/wp/v2/media';
	}
};
$GLOBALS['pap_media_upload_dir'] = array(
	'error' => 'Could not create the upload directory because the parent is not writable.',
	'path'  => $temporary_root . '/2026/10',
);
$rest_result = papelito_media_preflight_rest_upload( null, null, $request );
papelito_media_assert( 'returns the WordPress storage error before creating an attachment', true, is_wp_error( $rest_result ) );

rmdir( $monthly_path );
rmdir( dirname( $monthly_path ) );
rmdir( $temporary_root );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All media upload tests passed.\n";
