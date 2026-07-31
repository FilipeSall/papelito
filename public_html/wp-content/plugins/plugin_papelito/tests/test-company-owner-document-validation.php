<?php
/**
 * Standalone validation tests for private owner documents.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'PAPELITO_COMPANY_DOCUMENT_MAX_BYTES', 10 * 1024 * 1024 );

class WP_Error {
	public function __construct( private string $code ) {}
	public function get_error_code(): string { return $this->code; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_file_name( string $value ): string { return basename( $value ); }
function wp_check_filetype_and_ext( string $path, string $name, array $allowed ): array {
	$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	return 'png' === $extension
		? array( 'ext' => 'png', 'type' => 'image/png' )
		: array( 'ext' => false, 'type' => false );
}

require __DIR__ . '/../includes/private_files.php';

$source = file_get_contents( __DIR__ . '/../includes/company_owner_applications.php' );
foreach ( array( 'papelito_company_document_spec', 'papelito_company_document_validate_upload' ) as $function ) {
	if ( ! preg_match( '/function ' . $function . '\(.*?\n}/s', $source, $match ) ) {
		echo "FAIL: could not isolate {$function}\n";
		exit( 1 );
	}
	eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}

$failures = 0;
function assert_document( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

$png_path = tempnam( sys_get_temp_dir(), 'papelito-png-' );
file_put_contents(
	$png_path,
	base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' )
);
$actual_size = filesize( $png_path );
$valid = papelito_company_document_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => $png_path,
		'size'     => 1,
		'name'     => 'comprovante.png',
	)
);
assert_document( 'PNG real e permitido passa', true, is_array( $valid ) );
assert_document( 'tamanho vem do arquivo, nao do campo informado pelo cliente', $actual_size, is_array( $valid ) ? $valid['size'] : null );

$fake_path = tempnam( sys_get_temp_dir(), 'papelito-fake-' );
file_put_contents( $fake_path, 'conteudo que nao e imagem' );
$fake = papelito_company_document_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => $fake_path,
		'size'     => filesize( $fake_path ),
		'name'     => 'comprovante.png',
	)
);
assert_document( 'arquivo disfarçado de PNG é recusado pelo conteúdo', 'papelito_company_document_mime_invalid', $fake instanceof WP_Error ? $fake->get_error_code() : null );

unlink( $png_path );
unlink( $fake_path );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
