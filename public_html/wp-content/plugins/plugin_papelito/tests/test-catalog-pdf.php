<?php
/**
 * Standalone regression test for catalog PDF management.
 *
 * Usage: php tests/test-catalog-pdf.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
if ( ! defined( 'UPLOAD_ERR_OK' ) ) {
	define( 'UPLOAD_ERR_OK', 0 );
}
if ( ! defined( 'UPLOAD_ERR_NO_FILE' ) ) {
	define( 'UPLOAD_ERR_NO_FILE', 4 );
}

$GLOBALS['pap_options']            = array();
$GLOBALS['pap_attachments']        = array();
$GLOBALS['pap_can_manage_options'] = true;
$GLOBALS['pap_next_attachment_id'] = 100;
$GLOBALS['pap_deleted']            = array();
$GLOBALS['pap_media_error']        = null;

function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['pap_can_manage_options']; }
function get_option( $key, $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['pap_options'][ $key ] ); return true; }
function get_attached_file( $id ) { return $GLOBALS['pap_attachments'][ $id ]['path'] ?? false; }
function wp_get_attachment_url( $id ) { return $GLOBALS['pap_attachments'][ $id ]['url'] ?? false; }
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9.\-_]+/', '-', basename( (string) $name ) ); }
function wp_delete_attachment( $id, $force = false ) { $GLOBALS['pap_deleted'][] = $id; unset( $GLOBALS['pap_attachments'][ $id ] ); return true; }
function media_handle_sideload( $file, $post_id, $desc = null ) {
	if ( $GLOBALS['pap_media_error'] instanceof WP_Error ) {
		return $GLOBALS['pap_media_error'];
	}

	$id   = $GLOBALS['pap_next_attachment_id']++;
	$path = sys_get_temp_dir() . '/' . $file['name'];
	copy( $file['tmp_name'], $path );

	$GLOBALS['pap_attachments'][ $id ] = array(
		'path' => $path,
		'url'  => 'https://wp.example/uploads/' . rawurlencode( $file['name'] ),
	);

	return $id;
}

class WP_Error {
	public $code; public $message; public $data;
	function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_REST_Response {
	public $data; public $status;
	function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}

class WP_REST_Request {
	private $files;
	function __construct( $files = array() ) { $this->files = $files; }
	function get_file_params() { return $this->files; }
}

require __DIR__ . '/../includes/catalog-pdf.php';

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

function papelito_temp_upload( string $name, string $contents ): array {
	$path = tempnam( sys_get_temp_dir(), 'pap-catalog-' );
	file_put_contents( $path, $contents );
	return array(
		'name'     => $name,
		'tmp_name' => $path,
		'type'     => 'application/pdf',
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $path ),
	);
}

$snapshot = papelito_catalog_pdf_snapshot();
papelito_assert( 'default is active initially', 'default', $snapshot['activeCatalog']['source'] );
papelito_assert( 'default public path is portable', '/pdf/catalogo-papelito.pdf', $snapshot['activeCatalog']['url'] );
papelito_assert( 'default has no custom configured', null, $snapshot['configuredCatalog'] );

$file = papelito_temp_upload( 'Meet __ PDVPerfeito.pdf', "%PDF-1.4\nok" );
$res  = papelito_catalog_pdf_upload( new WP_REST_Request( array( 'file' => $file ) ) );
papelito_assert( 'valid upload returns response', true, $res instanceof WP_REST_Response );
papelito_assert( 'valid upload status', 201, $res->status );
papelito_assert( 'custom is active after upload', 'custom', $res->data['activeCatalog']['source'] );
papelito_assert( 'custom filename is sanitized', true, false !== strpos( $res->data['activeCatalog']['filename'], 'Meet-__-PDVPerfeito.pdf' ) );
$first_attachment_id = (int) get_option( PAPELITO_CATALOG_PDF_OPTION_ID, 0 );

$GLOBALS['pap_attachments'][ $first_attachment_id ]['path'] = sys_get_temp_dir() . '/missing-catalog.pdf';
$snapshot = papelito_catalog_pdf_snapshot();
papelito_assert( 'missing custom is still configured', 'custom', $snapshot['configuredCatalog']['source'] );
papelito_assert( 'missing custom is unavailable', false, $snapshot['configuredCatalog']['isAvailable'] );
papelito_assert( 'missing custom falls back to default', 'default', $snapshot['activeCatalog']['source'] );

$GLOBALS['pap_can_manage_options'] = false;
papelito_assert( 'non-admin cannot manage catalog', false, papelito_catalog_pdf_require_admin() );
$GLOBALS['pap_can_manage_options'] = true;
papelito_assert( 'admin can manage catalog', true, papelito_catalog_pdf_require_admin() );

$invalid = papelito_temp_upload( 'catalogo.txt', "not pdf" );
$err     = papelito_catalog_pdf_validate_upload( $invalid );
papelito_assert( 'non-pdf is rejected', true, is_wp_error( $err ) );
papelito_assert( 'non-pdf error code', 'papelito_catalog_invalid_extension', $err->code );

$corrupt = papelito_temp_upload( 'catalogo.pdf', "not pdf" );
$err     = papelito_catalog_pdf_validate_upload( $corrupt );
papelito_assert( 'corrupted pdf is rejected', true, is_wp_error( $err ) );
papelito_assert( 'corrupted pdf error code', 'papelito_catalog_invalid_type', $err->code );

$large          = papelito_temp_upload( 'catalogo.pdf', "%PDF-1.4\nok" );
$large['size']  = PAPELITO_CATALOG_MAX_FILE_SIZE + 1;
$err            = papelito_catalog_pdf_validate_upload( $large );
papelito_assert( 'large pdf is rejected', true, is_wp_error( $err ) );
papelito_assert( 'large pdf error code', 'papelito_catalog_file_too_large', $err->code );

$GLOBALS['pap_attachments'][ $first_attachment_id ] = array(
	'path' => $file['tmp_name'],
	'url'  => 'https://wp.example/uploads/catalogo-antigo.pdf',
);
update_option( PAPELITO_CATALOG_PDF_OPTION_ID, $first_attachment_id, false );
$GLOBALS['pap_media_error'] = new WP_Error( 'upload_failed', 'Falha ao salvar.', array( 'status' => 500 ) );
$new_file                   = papelito_temp_upload( 'novo.pdf', "%PDF-1.4\nnew" );
$err                        = papelito_catalog_pdf_upload( new WP_REST_Request( array( 'file' => $new_file ) ) );
papelito_assert( 'failed replacement returns error', true, is_wp_error( $err ) );
papelito_assert( 'failed replacement keeps previous attachment option', $first_attachment_id, (int) get_option( PAPELITO_CATALOG_PDF_OPTION_ID, 0 ) );
$GLOBALS['pap_media_error'] = null;

$res = papelito_catalog_pdf_restore_default();
papelito_assert( 'restore returns response', true, $res instanceof WP_REST_Response );
papelito_assert( 'restore default active', 'default', $res->data['activeCatalog']['source'] );
papelito_assert( 'restore clears custom option', 0, (int) get_option( PAPELITO_CATALOG_PDF_OPTION_ID, 0 ) );
papelito_assert( 'restore deletes custom attachment', true, in_array( $first_attachment_id, $GLOBALS['pap_deleted'], true ) );

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( 0 === $failures ? 0 : 1 );
