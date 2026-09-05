<?php
/**
 * Standalone tests da nota fiscal como arquivo indexado.
 *
 * A nota deixou de ter dado digitado: não há chave de acesso, número, série,
 * emissão nem valor. O que sobra para conferir aqui é a política do arquivo —
 * formato real, limite por formato, storage key — e a garantia de que a
 * fundação não voltou a ler o conteúdo do documento.
 *
 * Os testes que dependem de banco ficam em test-fiscal-documents-db.php.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function sanitize_text_field( mixed $value ) {
	return trim( (string) $value ); }
function sanitize_key( mixed $value ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function wp_json_encode( mixed $value ) {
	return json_encode( $value ); }
function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path ); }
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ); }
function trailingslashit( string $value ): string {
	return untrailingslashit( $value ) . '/'; }
function wp_mkdir_p( string $dir ): bool {
	return is_dir( $dir ) || mkdir( $dir, 0700, true ); }
function sanitize_file_name( string $name ): string {
	return basename( $name ); }
function esc_url_raw( string $url ): string {
	return $url; }
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function wp_check_filetype_and_ext( string $path, string $name, array $allowed ): array {
	$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	foreach ( $allowed as $pattern => $mime ) {
		if ( in_array( $extension, explode( '|', $pattern ), true ) ) {
			return array(
				'ext'  => $extension,
				'type' => $mime,
			);
		}
	}

	return array(
		'ext'  => false,
		'type' => false,
	);
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
	public function get_error_data() {
		return $this->data; }
}

require_once __DIR__ . '/../includes/private_files.php';
require_once __DIR__ . '/../includes/fiscal_documents.php';

$failures = 0;

/**
 * Compara valores e contabiliza falhas.
 *
 * @param mixed $expected Valor esperado.
 * @param mixed $actual   Valor obtido.
 */
function assert_fiscal( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

/**
 * Código do WP_Error, ou o que veio quando não é erro.
 *
 * @param mixed $result Resultado da validação.
 */
function error_code_or( mixed $result ): string {
	return $result instanceof WP_Error ? $result->get_error_code() : 'aceitou';
}

/**
 * Upload falso a partir de conteúdo em disco.
 *
 * @return array<string,mixed>
 */
function fiscal_upload( string $contents, string $name ): array {
	$tmp = tempnam( sys_get_temp_dir(), 'papelito-fiscal-' );
	file_put_contents( $tmp, $contents );

	return array(
		'error'    => UPLOAD_ERR_OK,
		'name'     => $name,
		'tmp_name' => $tmp,
	);
}

$safe_xml = '<?xml version="1.0" encoding="UTF-8"?><nfeProc><NFe/></nfeProc>';
$safe_pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\ntrailer";

// 1. Um spec só, com os dois formatos.
$spec = papelito_fiscal_document_spec();
assert_fiscal( 'spec aceita pdf e xml', array( 'pdf', 'xml' ), $spec['formats'] );
assert_fiscal( 'spec fiscal tem prefixo proprio', 'papelito_fiscal_document', $spec['code_prefix'] );

// 2. Limites: o teto do spec é o maior, e o aperto por formato vem depois.
assert_fiscal( 'limite do xml e 2 MB', 2097152, PAPELITO_FISCAL_XML_MAX_BYTES );
assert_fiscal( 'limite do pdf e 10 MB', 10485760, PAPELITO_FISCAL_PDF_MAX_BYTES );
assert_fiscal( 'teto do spec e o do pdf', PAPELITO_FISCAL_PDF_MAX_BYTES, $spec['max_bytes'] );
assert_fiscal( 'max_bytes do xml', PAPELITO_FISCAL_XML_MAX_BYTES, papelito_fiscal_document_max_bytes( 'xml' ) );
assert_fiscal( 'max_bytes do pdf', PAPELITO_FISCAL_PDF_MAX_BYTES, papelito_fiscal_document_max_bytes( 'pdf' ) );
assert_fiscal(
	'limites expostos ao frontend',
	array(
		'pdf' => PAPELITO_FISCAL_PDF_MAX_BYTES,
		'xml' => PAPELITO_FISCAL_XML_MAX_BYTES,
	),
	papelito_fiscal_document_limits()
);

// 3. Arquivos válidos passam nos dois formatos.
$xml_upload = fiscal_upload( $safe_xml, 'nota.xml' );
$xml_valid  = papelito_fiscal_document_validate_upload( $xml_upload );
assert_fiscal( 'xml valido passa', true, is_array( $xml_valid ) );
assert_fiscal( 'xml valido tem mime canonico', 'application/xml', is_array( $xml_valid ) ? $xml_valid['mime'] : '' );
assert_fiscal( 'xml valido calcula sha256', 64, is_array( $xml_valid ) ? strlen( $xml_valid['sha256'] ) : 0 );
unlink( $xml_upload['tmp_name'] );

$pdf_upload = fiscal_upload( $safe_pdf, 'nota.pdf' );
$pdf_valid  = papelito_fiscal_document_validate_upload( $pdf_upload );
assert_fiscal( 'pdf valido passa', true, is_array( $pdf_valid ) );
assert_fiscal( 'pdf valido tem mime canonico', 'application/pdf', is_array( $pdf_valid ) ? $pdf_valid['mime'] : '' );
unlink( $pdf_upload['tmp_name'] );

// 4. O limite apertado do XML continua valendo mesmo com o spec aceitando 10 MB.
$oversize_xml = fiscal_upload( '<?xml version="1.0"?><nfeProc>' . str_repeat( 'a', PAPELITO_FISCAL_XML_MAX_BYTES ) . '</nfeProc>', 'nota.xml' );
assert_fiscal(
	'xml acima de 2 MB e recusado mesmo cabendo no teto do pdf',
	'papelito_fiscal_document_size_invalid',
	error_code_or( papelito_fiscal_document_validate_upload( $oversize_xml ) )
);
assert_fiscal(
	'a mensagem cita o limite do formato, e nao o do spec',
	'O documento deve ter no máximo 2 MB.',
	papelito_private_file_size_message( PAPELITO_FISCAL_XML_MAX_BYTES )
);
unlink( $oversize_xml['tmp_name'] );

// 5. Extensão e conteúdo precisam concordar.
$xml_as_pdf = fiscal_upload( $safe_xml, 'nota.pdf' );
assert_fiscal(
	'xml disfarcado de pdf e recusado',
	'papelito_fiscal_document_type_mismatch',
	error_code_or( papelito_fiscal_document_validate_upload( $xml_as_pdf ) )
);
unlink( $xml_as_pdf['tmp_name'] );

$pdf_as_xml = fiscal_upload( $safe_pdf, 'nota.xml' );
assert_fiscal(
	'pdf disfarcado de xml e recusado',
	'papelito_fiscal_document_type_mismatch',
	error_code_or( papelito_fiscal_document_validate_upload( $pdf_as_xml ) )
);
unlink( $pdf_as_xml['tmp_name'] );

$jpg_upload = fiscal_upload( $safe_pdf, 'nota.jpg' );
assert_fiscal(
	'jpg nao entra no fluxo fiscal',
	'papelito_fiscal_document_extension_invalid',
	error_code_or( papelito_fiscal_document_validate_upload( $jpg_upload ) )
);
unlink( $jpg_upload['tmp_name'] );

$fake_pdf = fiscal_upload( 'nao sou um pdf', 'nota.pdf' );
assert_fiscal(
	'arquivo sem assinatura %PDF- e recusado',
	'papelito_fiscal_document_mime_invalid',
	error_code_or( papelito_fiscal_document_validate_upload( $fake_pdf ) )
);
unlink( $fake_pdf['tmp_name'] );

$empty_upload = fiscal_upload( '', 'nota.pdf' );
assert_fiscal(
	'arquivo vazio e recusado',
	'papelito_fiscal_document_size_invalid',
	error_code_or( papelito_fiscal_document_validate_upload( $empty_upload ) )
);
unlink( $empty_upload['tmp_name'] );

// 6. XXE continua barrado por varredura de bytes, sem depender de extensão XML.
$hostile_upload = fiscal_upload( '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><nfeProc/>', 'nota.xml' );
assert_fiscal(
	'xml com XXE nao chega ao disco',
	'papelito_fiscal_document_xml_invalid',
	error_code_or( papelito_fiscal_document_validate_upload( $hostile_upload ) )
);
unlink( $hostile_upload['tmp_name'] );

// 7. Storage key: é ela que vira caminho na hora de ler e de apagar.
assert_fiscal( 'storage key de pdf e valida', true, papelito_fiscal_document_key_is_valid( str_repeat( 'a', 64 ) . '.pdf' ) );
assert_fiscal( 'storage key de xml e valida', true, papelito_fiscal_document_key_is_valid( str_repeat( 'f', 64 ) . '.xml' ) );
assert_fiscal( 'storage key curta e recusada', false, papelito_fiscal_document_key_is_valid( 'abc.pdf' ) );
assert_fiscal( 'storage key com travessia e recusada', false, papelito_fiscal_document_key_is_valid( '../' . str_repeat( 'a', 64 ) . '.pdf' ) );
assert_fiscal( 'storage key de outro formato e recusada', false, papelito_fiscal_document_key_is_valid( str_repeat( 'a', 64 ) . '.jpg' ) );
assert_fiscal( 'storage key vazia e recusada', false, papelito_fiscal_document_key_is_valid( '' ) );

// 8. Diretório dentro do webroot é recusado antes de gravar.
assert_fiscal(
	'diretorio no webroot e recusado',
	'papelito_fiscal_document_storage_public',
	error_code_or( papelito_private_files_prepare_dir( wp_normalize_path( ABSPATH ) . '/fiscal', 'papelito_fiscal_document' ) )
);

// 9. Trilha: só os três eventos do modelo novo.
assert_fiscal( 'eventos da trilha', array( 'anexada', 'substituida', 'removida' ), papelito_fiscal_document_events() );

// 10. A janela da varredura existe para não apagar upload em voo.
assert_fiscal( 'varredura so olha arquivo com mais de 1 hora', 3600, PAPELITO_FISCAL_SWEEP_MIN_AGE );

// 11. A fundação não voltou a ler o conteúdo do documento nem a guardar dado digitado.
$fiscal_sources = file_get_contents( __DIR__ . '/../includes/fiscal_documents.php' )
	. file_get_contents( __DIR__ . '/../includes/fiscal_documents_rest.php' )
	. file_get_contents( __DIR__ . '/../includes/fiscal_documents_cleanup.php' );
foreach ( array( 'SimpleXML', 'access_key', 'doc_number', 'doc_series', 'total_cents', 'validation_level', 'flags_json' ) as $forbidden ) {
	assert_fiscal( "nota indexada nao usa {$forbidden}", false, str_contains( $fiscal_sources, $forbidden ) );
}

// 12. E não decide pagamento, fulfillment nem estoque.
foreach ( array( 'update_status', 'payment_complete', 'papelito_set_vendor_stock', '_papelito_vendor_status' ) as $forbidden ) {
	assert_fiscal( "nota indexada nao toca {$forbidden}", false, str_contains( $fiscal_sources, $forbidden ) );
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
