<?php
/**
 * Standalone tests for the generic private file validator and storage keys.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_file_name( string $value ): string { return basename( $value ); }
function wp_normalize_path( string $path ): string { return preg_replace( '|/+|', '/', str_replace( '\\', '/', $path ) ); }
function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function trailingslashit( string $value ): string { return untrailingslashit( $value ) . '/'; }
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

require __DIR__ . '/../includes/private_files.php';

$failures = 0;
function assert_private( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

function error_code_of( mixed $result ): ?string {
	return $result instanceof WP_Error ? $result->get_error_code() : null;
}

$company_spec = array(
	'code_prefix'       => 'papelito_company_document',
	'max_bytes'         => 10 * 1024 * 1024,
	'formats'           => array( 'jpg', 'png', 'pdf' ),
	'fallback_basename' => 'documento',
);

$temp_files = array();
function fixture( string $prefix, string $contents ): string {
	global $temp_files;
	$path = tempnam( sys_get_temp_dir(), $prefix );
	file_put_contents( $path, $contents );
	$temp_files[] = $path;

	return $path;
}

$jpeg_bytes = base64_decode( '/9j/2wBDABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AP//Z' );
$png_bytes  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
$pdf_bytes  = "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF";

// 1. Extensão .jpeg legítima é aceita e normalizada para a extensão canônica jpg.
$jpeg = papelito_private_file_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => fixture( 'papelito-jpeg-', $jpeg_bytes ),
		'size'     => 1,
		'name'     => 'contrato.jpeg',
	),
	$company_spec
);
assert_private( 'JPEG com extensao .jpeg e aceito', true, is_array( $jpeg ) );
assert_private( 'extensao canonica de .jpeg e jpg', 'jpg', is_array( $jpeg ) ? $jpeg['extension'] : null );
assert_private( 'mime canonico de jpeg', 'image/jpeg', is_array( $jpeg ) ? $jpeg['mime'] : null );

// 2. PDF real com extensão pdf é aceito.
$pdf_path = fixture( 'papelito-pdf-', $pdf_bytes );
$pdf      = papelito_private_file_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => $pdf_path,
		'size'     => 1,
		'name'     => 'contrato.pdf',
	),
	$company_spec
);
assert_private( 'PDF real e aceito', true, is_array( $pdf ) );
assert_private( 'sha256 confere com o arquivo', hash_file( 'sha256', $pdf_path ), is_array( $pdf ) ? $pdf['sha256'] : null );

// 3. Conteúdo PDF disfarçado de PNG é recusado pelo cruzamento extensão/conteúdo.
$mismatch = papelito_private_file_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => fixture( 'papelito-mismatch-', $pdf_bytes ),
		'size'     => 1,
		'name'     => 'contrato.png',
	),
	$company_spec
);
assert_private( 'PDF com extensao .png e recusado', 'papelito_company_document_type_mismatch', error_code_of( $mismatch ) );

// 4. Conteúdo que não corresponde a nenhum formato permitido.
$foreign = papelito_private_file_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => fixture( 'papelito-foreign-', 'conteudo que nao e imagem' ),
		'size'     => 1,
		'name'     => 'contrato.png',
	),
	$company_spec
);
assert_private( 'conteudo estranho e recusado pelo mime', 'papelito_company_document_mime_invalid', error_code_of( $foreign ) );

// 5. Extensão fora do spec para antes de qualquer leitura de conteúdo.
$extension = papelito_private_file_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => fixture( 'papelito-ext-', $pdf_bytes ),
		'size'     => 1,
		'name'     => 'nota.xml',
	),
	$company_spec
);
assert_private( 'extensao fora do spec e recusada', 'papelito_company_document_extension_invalid', error_code_of( $extension ) );
assert_private(
	'mensagem de extensao lista os formatos do spec',
	'Envie um arquivo JPG, JPEG, PNG ou PDF.',
	$extension instanceof WP_Error ? $extension->get_error_message() : null
);

// 6. Arquivo vazio.
$empty = papelito_private_file_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => fixture( 'papelito-empty-', '' ),
		'size'     => 1,
		'name'     => 'contrato.pdf',
	),
	$company_spec
);
assert_private( 'arquivo vazio e recusado', 'papelito_company_document_size_invalid', error_code_of( $empty ) );

// 7. Arquivo acima do limite do spec.
$tiny_spec              = $company_spec;
$tiny_spec['max_bytes'] = 8;
$too_big                = papelito_private_file_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => fixture( 'papelito-big-', $pdf_bytes ),
		'size'     => 1,
		'name'     => 'contrato.pdf',
	),
	$tiny_spec
);
assert_private( 'arquivo acima do limite e recusado', 'papelito_company_document_size_invalid', error_code_of( $too_big ) );

// 8. Erro de upload e tmp_name ausente.
assert_private(
	'erro de upload e recusado',
	'papelito_company_document_upload_invalid',
	error_code_of(
		papelito_private_file_validate_upload(
			array(
				'error'    => UPLOAD_ERR_INI_SIZE,
				'tmp_name' => '',
				'name'     => 'contrato.pdf',
			),
			$company_spec
		)
	)
);

// 9. Spec inválido falha fechado, sem tocar no arquivo.
assert_private(
	'spec sem formatos falha fechado',
	'papelito_company_document_spec_invalid',
	error_code_of(
		papelito_private_file_validate_upload(
			array(
				'error'    => UPLOAD_ERR_OK,
				'tmp_name' => $pdf_path,
				'name'     => 'contrato.pdf',
			),
			array(
				'code_prefix' => 'papelito_company_document',
				'max_bytes'   => 10,
				'formats'     => array(),
			)
		)
	)
);

// 10. PDF reconhecido pelo finfo, mas sem a assinatura na posição inicial, preserva o erro específico do fluxo completo.
$pdf_without_signature = papelito_private_file_validate_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => fixture( 'papelito-nopdf-', "x%PDF-1.4\n1 0 obj" ),
		'size'     => 1,
		'name'     => 'contrato.pdf',
	),
	$company_spec
);
assert_private( 'PDF sem assinatura inicial e recusado', 'papelito_company_document_pdf_invalid', error_code_of( $pdf_without_signature ) );

// 11. Verificador de PDF isolado.
assert_private( 'verificador aceita PDF com assinatura', true, papelito_private_file_verify_pdf( $pdf_path, 'application/pdf' ) );
assert_private(
	'verificador recusa PDF sem assinatura %PDF-',
	false,
	papelito_private_file_verify_pdf( fixture( 'papelito-nopdf-', "GARBAGE\n1 0 obj" ), 'application/pdf' )
);

// 12. Mensagens derivadas do spec.
assert_private( 'mensagem de limite de 10 MB', 'O documento deve ter no máximo 10 MB.', papelito_private_file_size_message( 10 * 1024 * 1024 ) );
assert_private( 'mensagem de limite de 2 MB', 'O documento deve ter no máximo 2 MB.', papelito_private_file_size_message( 2 * 1024 * 1024 ) );
assert_private( 'mensagem de extensao com um formato', 'Envie um arquivo PDF.', papelito_private_file_extension_message( array( 'pdf' ) ) );
assert_private( 'mensagem de extensao com dois formatos', 'Envie um arquivo PDF ou XML.', papelito_private_file_extension_message( array( 'pdf', 'xml' ) ) );

// 13. Registro de formatos: x-pdf pertence ao formato pdf e canonicaliza para application/pdf.
$formats = papelito_private_file_spec_formats( $company_spec );
assert_private( 'spec resolve tres formatos', array( 'jpg', 'png', 'pdf' ), array_keys( $formats ) );
assert_private( 'x-pdf pertence ao formato pdf', true, in_array( 'application/x-pdf', $formats['pdf']['mimes'], true ) );
assert_private( 'mime canonico do formato pdf', 'application/pdf', $formats['pdf']['canonical_mime'] );
assert_private( 'formato desconhecido e ignorado pelo spec', array( 'pdf' ), array_keys( papelito_private_file_spec_formats( array( 'formats' => array( 'pdf', 'docx' ) ) ) ) );

// 14. Storage keys.
$hex64 = str_repeat( 'a1b2c3d4', 8 );
assert_private( 'key valida com extensao canonica', true, papelito_private_file_key_is_valid( $hex64 . '.pdf', array( 'jpg', 'png', 'pdf' ) ) );
assert_private( 'key com 63 hex e invalida', false, papelito_private_file_key_is_valid( substr( $hex64, 0, 63 ) . '.pdf', array( 'pdf' ) ) );
assert_private( 'key com 65 hex e invalida', false, papelito_private_file_key_is_valid( $hex64 . 'a.pdf', array( 'pdf' ) ) );
assert_private( 'key em maiuscula e invalida', false, papelito_private_file_key_is_valid( strtoupper( $hex64 ) . '.pdf', array( 'pdf' ) ) );
assert_private( 'extensao nao canonica jpeg e invalida', false, papelito_private_file_key_is_valid( $hex64 . '.jpeg', array( 'jpg', 'png', 'pdf' ) ) );
assert_private( 'extensao fora do spec e invalida', false, papelito_private_file_key_is_valid( $hex64 . '.xml', array( 'jpg', 'png', 'pdf' ) ) );
assert_private( 'path traversal e invalido', false, papelito_private_file_key_is_valid( '../' . $hex64 . '.pdf', array( 'pdf' ) ) );
assert_private( 'diretorio embutido e invalido', false, papelito_private_file_key_is_valid( 'sub/' . $hex64 . '.pdf', array( 'pdf' ) ) );
assert_private( 'key sem extensao e invalida', false, papelito_private_file_key_is_valid( $hex64, array( 'pdf' ) ) );
assert_private( 'lista de formatos vazia recusa tudo', false, papelito_private_file_key_is_valid( $hex64 . '.pdf', array() ) );
assert_private( 'formato desconhecido nao habilita key', false, papelito_private_file_key_is_valid( $hex64 . '.docx', array( 'docx' ) ) );

// 15. Preparação de diretório recusa qualquer caminho dentro do webroot.
$inside_webroot = papelito_private_files_prepare_dir( ABSPATH . '/uploads-privados', 'papelito_company_document' );
assert_private( 'diretorio dentro do webroot e recusado', 'papelito_company_document_storage_public', error_code_of( $inside_webroot ) );
assert_private( 'caminho relativo e recusado', 'papelito_company_document_storage_public', error_code_of( papelito_private_files_prepare_dir( 'relativo/privado', 'papelito_company_document' ) ) );
assert_private( 'diretorio vazio e recusado', 'papelito_company_document_storage_public', error_code_of( papelito_private_files_prepare_dir( '', 'papelito_company_document' ) ) );

foreach ( $temp_files as $temp_file ) {
	if ( is_file( $temp_file ) ) {
		unlink( $temp_file );
	}
}

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
