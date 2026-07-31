<?php
/**
 * Standalone tests for the fiscal document foundation.
 *
 * Cobre o que não depende de extensão XML: módulo 11 da chave, CNPJ do
 * emitente, varredura de DOCTYPE/ENTITY, coerência de modelo/data, divergências
 * sem sobrescrita, storage key e sanitização de evento.
 *
 * O parse de XML fica em test-fiscal-xml.php, que exige SimpleXML.
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

/**
 * O emitente é conferido pelo validador de CNPJ do próprio plugin.
 */
require_once __DIR__ . '/../includes/cnpj_validation.php';
require_once __DIR__ . '/../includes/private_files.php';
require_once __DIR__ . '/../includes/fiscal_document_validation.php';
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
 * Monta uma chave de acesso válida a partir dos 43 primeiros dígitos.
 */
function fiscal_key( string $base43 ): string {
	return $base43 . papelito_fiscal_key_check_digit( $base43 );
}

/**
 * Base de 43 dígitos, campo a campo, na ordem da chave de acesso.
 */
function fiscal_key_base( string $issuer_cnpj, string $model = '55', string $number = '000000123' ): string {
	return implode(
		'',
		array(
			'35',        // cUF.
			'2607',      // AAMM.
			$issuer_cnpj,
			$model,
			'001',       // Série.
			$number,
			'1',         // tpEmis.
			'12345678',  // cNF.
		)
	);
}

// CNPJ válido usado como emitente em todo o arquivo.
$issuer_cnpj = '11222333000181';
assert_fiscal( 'fixture de CNPJ do emitente e valida', true, papelito_validate_cnpj( $issuer_cnpj ) );

// 1. Módulo 11 da chave de acesso.
$base43 = fiscal_key_base( $issuer_cnpj );
assert_fiscal( 'base da chave tem 43 digitos', 43, strlen( $base43 ) );

$key = fiscal_key( $base43 );
assert_fiscal( 'chave completa tem 44 digitos', 44, strlen( $key ) );
assert_fiscal( 'chave com DV correto e valida', true, papelito_fiscal_key_is_valid( $key ) );
assert_fiscal( 'chave formatada com separadores e aceita', true, papelito_fiscal_key_is_valid( chunk_split( $key, 4, ' ' ) ) );

$wrong_dv = $base43 . ( ( (int) $key[43] + 1 ) % 10 );
assert_fiscal( 'DV errado e recusado', false, papelito_fiscal_key_is_valid( $wrong_dv ) );
assert_fiscal( 'chave curta e recusada', false, papelito_fiscal_key_is_valid( substr( $key, 0, 43 ) ) );
assert_fiscal( 'chave com letras e recusada', false, papelito_fiscal_key_is_valid( str_repeat( 'A', 44 ) ) );
assert_fiscal( 'base com tamanho errado nao gera DV', -1, papelito_fiscal_key_check_digit( '123' ) );

// DV inválido é armazenável como `invalida`; ausente é `ausente`.
assert_fiscal( 'status da chave valida', 'valida', papelito_fiscal_key_status( $key ) );
assert_fiscal( 'status da chave com DV errado', 'invalida', papelito_fiscal_key_status( $wrong_dv ) );
assert_fiscal( 'status sem chave', 'ausente', papelito_fiscal_key_status( '' ) );

// 2. Campos embutidos na chave.
$parts = papelito_fiscal_key_parts( $key );
assert_fiscal( 'uf da chave', '35', $parts['uf'] );
assert_fiscal( 'ano/mes da chave', '2607', $parts['year_month'] );
assert_fiscal( 'cnpj do emitente na chave', $issuer_cnpj, $parts['issuer_cnpj'] );
assert_fiscal( 'modelo da chave', '55', $parts['model'] );
assert_fiscal( 'serie da chave', '001', $parts['series'] );
assert_fiscal( 'numero da chave', '000000123', $parts['number'] );
assert_fiscal( 'chave invalida nao tem partes', array(), papelito_fiscal_key_parts( '123' ) );

// 3. Varredura de bytes: DOCTYPE e ENTITY nunca chegam ao parse.
$safe_xml = '<?xml version="1.0"?><nfeProc><NFe/></nfeProc>';
assert_fiscal( 'xml limpo nao e hostil', false, papelito_private_file_xml_is_hostile( $safe_xml ) );
assert_fiscal( 'DOCTYPE e hostil', true, papelito_private_file_xml_is_hostile( '<?xml version="1.0"?><!DOCTYPE foo><nfeProc/>' ) );
assert_fiscal( 'ENTITY e hostil', true, papelito_private_file_xml_is_hostile( '<!ENTITY xxe SYSTEM "file:///etc/passwd">' ) );
assert_fiscal( 'DOCTYPE maiusculo/minusculo e hostil', true, papelito_private_file_xml_is_hostile( '<!doctype html>' ) );

// 4. O verificador de arquivo recusa hostil e vazio, aceita XML textual.
$tmp_safe    = tempnam( sys_get_temp_dir(), 'papelito-xml-safe-' );
$tmp_hostile = tempnam( sys_get_temp_dir(), 'papelito-xml-xxe-' );
$tmp_empty   = tempnam( sys_get_temp_dir(), 'papelito-xml-empty-' );
file_put_contents( $tmp_safe, $safe_xml );
file_put_contents( $tmp_hostile, '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><nfeProc/>' );
file_put_contents( $tmp_empty, '   ' );

assert_fiscal( 'verificador aceita xml limpo', true, papelito_private_file_verify_xml( $tmp_safe, 'application/xml' ) );
assert_fiscal( 'verificador recusa XXE', false, papelito_private_file_verify_xml( $tmp_hostile, 'application/xml' ) );
assert_fiscal( 'verificador recusa arquivo vazio', false, papelito_private_file_verify_xml( $tmp_empty, 'application/xml' ) );
unlink( $tmp_safe );
unlink( $tmp_hostile );
unlink( $tmp_empty );

// 5. Registro de formatos e chave de armazenamento.
$formats = papelito_private_file_formats();
assert_fiscal( 'xml entrou no registro de formatos', true, isset( $formats['xml'] ) );
assert_fiscal( 'xml tem mime canonico', 'application/xml', $formats['xml']['canonical_mime'] );
assert_fiscal( 'jpg continua no registro', true, isset( $formats['jpg'] ) );

$random_key = bin2hex( random_bytes( 32 ) );
assert_fiscal( 'storage key de xml e aceita', true, papelito_private_file_key_is_valid( $random_key . '.xml', array( 'pdf', 'xml' ) ) );
assert_fiscal( 'storage key de pdf e aceita', true, papelito_private_file_key_is_valid( $random_key . '.pdf', array( 'pdf', 'xml' ) ) );
assert_fiscal( 'jpg nao e aceito no spec fiscal', false, papelito_private_file_key_is_valid( $random_key . '.jpg', array( 'pdf', 'xml' ) ) );
assert_fiscal( 'travessia de diretorio e recusada', false, papelito_private_file_key_is_valid( '../../etc/passwd', array( 'pdf', 'xml' ) ) );
assert_fiscal( 'key curta e recusada', false, papelito_private_file_key_is_valid( 'abc.xml', array( 'pdf', 'xml' ) ) );

// 6. Coerência interna da chave.
$coherent = array(
	'access_key'  => $key,
	'doc_type'    => 'nfe',
	'issuer_cnpj' => $issuer_cnpj,
	'issued_at'   => '2026-07-15 10:00:00',
);
assert_fiscal( 'documento coerente nao gera flag', array(), papelito_fiscal_key_coherence_flags( $coherent ) );

$wrong_model             = $coherent;
$wrong_model['doc_type'] = 'nfce';
assert_fiscal( 'modelo 55 declarado como nfce e sinalizado', array( 'modelo_incoerente' ), papelito_fiscal_key_coherence_flags( $wrong_model ) );

$wrong_month              = $coherent;
$wrong_month['issued_at'] = '2026-09-15 10:00:00';
assert_fiscal( 'mes de emissao fora da chave e sinalizado', array( 'emissao_incoerente' ), papelito_fiscal_key_coherence_flags( $wrong_month ) );

$wrong_issuer                = $coherent;
$wrong_issuer['issuer_cnpj'] = '11444777000161';
$issuer_flags                = papelito_fiscal_key_coherence_flags( $wrong_issuer );
assert_fiscal( 'emitente fora da chave e sinalizado', true, in_array( 'emitente_fora_da_chave', $issuer_flags, true ) );

$invalid_cnpj                = $coherent;
$invalid_cnpj['issuer_cnpj'] = '11111111111111';
$invalid_flags               = papelito_fiscal_key_coherence_flags( $invalid_cnpj );
assert_fiscal( 'cnpj de emitente invalido e sinalizado', true, in_array( 'cnpj_emitente_invalido', $invalid_flags, true ) );

// 7. Divergência entre digitado e XML: sinaliza, nunca sobrescreve.
$declared  = array(
	'access_key'  => $key,
	'issuer_cnpj' => $issuer_cnpj,
	'number'      => '123',
	'series'      => '1',
	'total_cents' => 15000,
);
$extracted = array(
	'access_key'  => $key,
	'issuer_cnpj' => $issuer_cnpj,
	'number'      => '000000123',
	'series'      => '001',
	'total_cents' => 15000,
);
assert_fiscal( 'numero e serie com zeros a esquerda nao divergem', array(), papelito_fiscal_compare_declared( $declared, $extracted ) );

$diverging                = $extracted;
$diverging['total_cents'] = 19900;
assert_fiscal( 'valor divergente e sinalizado', array( 'valor_divergente' ), papelito_fiscal_compare_declared( $declared, $diverging ) );

$other_key                   = fiscal_key( fiscal_key_base( $issuer_cnpj, '55', '000000999' ) );
$diverging_key               = $extracted;
$diverging_key['access_key'] = $other_key;
assert_fiscal( 'chave divergente e sinalizada', true, in_array( 'chave_divergente', papelito_fiscal_compare_declared( $declared, $diverging_key ), true ) );

// Campo ausente de um dos lados não é divergência.
assert_fiscal(
	'campo ausente no xml nao diverge',
	array(),
	papelito_fiscal_compare_declared(
		$declared,
		array(
			'access_key'  => '',
			'issuer_cnpj' => '',
		)
	)
);

// 8. Cruzamento com o pedido: informativo.
assert_fiscal(
	'emitente diferente do vendor e sinalizado',
	array( 'emitente_nao_e_o_vendor' ),
	papelito_fiscal_order_cross_flags(
		array(
			'issuer_cnpj' => $issuer_cnpj,
			'total_cents' => 15000,
		),
		array(
			'vendor_cnpj'      => '11444777000161',
			'part_total_cents' => 15000,
		)
	)
);
assert_fiscal(
	'valor fora do pedido e sinalizado, em centavos exatos',
	array( 'valor_fora_do_pedido' ),
	papelito_fiscal_order_cross_flags(
		array(
			'issuer_cnpj' => $issuer_cnpj,
			'total_cents' => 15001,
		),
		array(
			'vendor_cnpj'      => $issuer_cnpj,
			'part_total_cents' => 15000,
		)
	)
);
assert_fiscal(
	'valor igual nao gera flag',
	array(),
	papelito_fiscal_order_cross_flags(
		array(
			'issuer_cnpj' => $issuer_cnpj,
			'total_cents' => 15000,
		),
		array(
			'vendor_cnpj'      => $issuer_cnpj,
			'part_total_cents' => 15000,
		)
	)
);

// 9. Valor monetário em centavos.
assert_fiscal( 'valor com ponto', 15000, papelito_fiscal_amount_to_cents( '150.00' ) );
assert_fiscal( 'valor com virgula', 15000, papelito_fiscal_amount_to_cents( '150,00' ) );
assert_fiscal( 'valor quebrado arredonda', 1001, papelito_fiscal_amount_to_cents( '10.005' ) );
assert_fiscal( 'valor vazio', 0, papelito_fiscal_amount_to_cents( '' ) );
assert_fiscal( 'valor nao numerico', 0, papelito_fiscal_amount_to_cents( 'abc' ) );

// 10. Nível de validação.
assert_fiscal(
	'sem chave valida fica no nivel de arquivo',
	PAPELITO_FISCAL_LEVEL_FILE,
	papelito_fiscal_validation_level( array( 'access_key' => '' ), array() )
);
assert_fiscal(
	'chave valida sem xml fica no nivel de chave',
	PAPELITO_FISCAL_LEVEL_KEY,
	papelito_fiscal_validation_level(
		array(
			'access_key' => $key,
			'has_xml'    => false,
		),
		array()
	)
);
assert_fiscal(
	'xml sem cruzamento fica no nivel de xml',
	PAPELITO_FISCAL_LEVEL_XML,
	papelito_fiscal_validation_level(
		array(
			'access_key' => $key,
			'has_xml'    => true,
		),
		array()
	)
);
assert_fiscal(
	'emitente confirmado sem parcela fica no nivel de emitente',
	PAPELITO_FISCAL_LEVEL_ISSUER,
	papelito_fiscal_validation_level(
		array(
			'access_key'  => $key,
			'has_xml'     => true,
			'issuer_cnpj' => $issuer_cnpj,
		),
		array(),
		array( 'vendor_cnpj' => $issuer_cnpj )
	)
);
assert_fiscal(
	'divergencia de valor para no nivel de emitente',
	PAPELITO_FISCAL_LEVEL_ISSUER,
	papelito_fiscal_validation_level(
		array(
			'access_key'  => $key,
			'has_xml'     => true,
			'issuer_cnpj' => $issuer_cnpj,
			'total_cents' => 15000,
		),
		array( 'valor_divergente' ),
		array(
			'vendor_cnpj'      => $issuer_cnpj,
			'part_total_cents' => 15000,
		)
	)
);
assert_fiscal(
	'divergencia de emitente para no nivel de xml',
	PAPELITO_FISCAL_LEVEL_XML,
	papelito_fiscal_validation_level(
		array(
			'access_key' => $key,
			'has_xml'    => true,
		),
		array( 'emitente_nao_e_o_vendor' )
	)
);

// 11. Parse recusa antes de tocar SimpleXML.
$hostile = papelito_fiscal_xml_parse( '<?xml version="1.0"?><!DOCTYPE r><nfeProc/>' );
assert_fiscal( 'parse recusa DOCTYPE', 'papelito_fiscal_xml_unsafe', $hostile instanceof WP_Error ? $hostile->get_error_code() : 'aceitou' );
$empty = papelito_fiscal_xml_parse( '   ' );
assert_fiscal( 'parse recusa vazio', 'papelito_fiscal_xml_empty', $empty instanceof WP_Error ? $empty->get_error_code() : 'aceitou' );
$too_large = papelito_fiscal_xml_parse( '<nfeProc>' . str_repeat( 'a', PAPELITO_FISCAL_XML_MAX_BYTES ) . '</nfeProc>' );
assert_fiscal( 'parse recusa acima de 2 MB', 'papelito_fiscal_xml_too_large', $too_large instanceof WP_Error ? $too_large->get_error_code() : 'aceitou' );

// 12. Limites do spec fiscal.
assert_fiscal( 'limite do xml e 2 MB', 2097152, PAPELITO_FISCAL_XML_MAX_BYTES );
assert_fiscal( 'limite do pdf e 10 MB', 10485760, PAPELITO_FISCAL_PDF_MAX_BYTES );
assert_fiscal( 'mensagem de limite do xml e derivada', 'O documento deve ter no máximo 2 MB.', papelito_private_file_size_message( PAPELITO_FISCAL_XML_MAX_BYTES ) );

// 13. Upload: extensão e conteúdo precisam concordar.
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

$xml_spec = papelito_fiscal_document_spec( 'xml' );
$pdf_spec = papelito_fiscal_document_spec( 'danfe_pdf' );
assert_fiscal( 'spec do xml aceita so xml', array( 'xml' ), $xml_spec['formats'] );
assert_fiscal( 'spec do pdf aceita so pdf', array( 'pdf' ), $pdf_spec['formats'] );
assert_fiscal( 'spec fiscal tem prefixo proprio', 'papelito_fiscal_document', $xml_spec['code_prefix'] );

$valid_xml_upload = fiscal_upload( $safe_xml, 'nota.xml' );
$valid_xml        = papelito_private_file_validate_upload( $valid_xml_upload, $xml_spec );
assert_fiscal( 'xml valido passa', true, is_array( $valid_xml ) );
assert_fiscal( 'xml valido tem mime canonico', 'application/xml', is_array( $valid_xml ) ? $valid_xml['mime'] : '' );
assert_fiscal( 'xml valido calcula sha256', 64, is_array( $valid_xml ) ? strlen( $valid_xml['sha256'] ) : 0 );
unlink( $valid_xml_upload['tmp_name'] );

$xml_as_pdf = fiscal_upload( $safe_xml, 'nota.pdf' );
assert_fiscal(
	'xml disfarcado de pdf e recusado',
	'papelito_fiscal_document_mime_invalid',
	error_code_or( papelito_private_file_validate_upload( $xml_as_pdf, $pdf_spec ) )
);
unlink( $xml_as_pdf['tmp_name'] );

$pdf_as_xml = fiscal_upload( "%PDF-1.4\n%\xE2\xE3\xCF\xD3\ntrailer", 'nota.xml' );
assert_fiscal(
	'pdf disfarcado de xml e recusado',
	'papelito_fiscal_document_mime_invalid',
	error_code_or( papelito_private_file_validate_upload( $pdf_as_xml, $xml_spec ) )
);
unlink( $pdf_as_xml['tmp_name'] );

$jpg_upload = fiscal_upload( $safe_xml, 'danfe.jpg' );
assert_fiscal(
	'jpg nao entra no fluxo fiscal',
	'papelito_fiscal_document_extension_invalid',
	error_code_or( papelito_private_file_validate_upload( $jpg_upload, $pdf_spec ) )
);
unlink( $jpg_upload['tmp_name'] );

$oversize = fiscal_upload( '<nfeProc>' . str_repeat( 'a', PAPELITO_FISCAL_XML_MAX_BYTES ) . '</nfeProc>', 'nota.xml' );
assert_fiscal(
	'xml acima de 2 MB e recusado',
	'papelito_fiscal_document_size_invalid',
	error_code_or( papelito_private_file_validate_upload( $oversize, $xml_spec ) )
);
unlink( $oversize['tmp_name'] );

$hostile_upload = fiscal_upload( '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><nfeProc/>', 'nota.xml' );
assert_fiscal(
	'xml com XXE nao chega ao disco',
	'papelito_fiscal_document_xml_invalid',
	error_code_or( papelito_private_file_validate_upload( $hostile_upload, $xml_spec ) )
);
unlink( $hostile_upload['tmp_name'] );

// 14. Diretório dentro do webroot é recusado antes de gravar.
assert_fiscal(
	'diretorio no webroot e recusado',
	'papelito_fiscal_document_storage_public',
	error_code_or( papelito_private_files_prepare_dir( wp_normalize_path( ABSPATH ) . '/fiscal', 'papelito_fiscal_document' ) )
);
assert_fiscal(
	'caminho relativo e recusado',
	'papelito_fiscal_document_storage_public',
	error_code_or( papelito_private_files_prepare_dir( 'fiscal-documents', 'papelito_fiscal_document' ) )
);

// 15. Documento montado: digitado prevalece, XML só sinaliza.
$built = papelito_fiscal_document_build(
	array(
		'order_id'    => 4242,
		'vendor_id'   => 9,
		'doc_type'    => 'nfe',
		'access_key'  => $key,
		'issuer_cnpj' => $issuer_cnpj,
		'doc_number'  => '123',
		'total_cents' => 15000,
		'issued_at'   => '2026-07-15 10:00:00',
	),
	array(
		'access_key'  => $key,
		'issuer_cnpj' => $issuer_cnpj,
		'number'      => '000000123',
		'series'      => '001',
		'total'       => '199.00',
		'issued_at'   => '2026-07-15T10:00:00-03:00',
	),
	array(
		'vendor_cnpj'      => $issuer_cnpj,
		'part_total_cents' => 15000,
	)
);

assert_fiscal( 'valor digitado nao e sobrescrito pelo xml', 15000, $built['total_cents'] );
assert_fiscal( 'divergencia de valor vira flag', true, in_array( 'valor_divergente', $built['flags'], true ) );
assert_fiscal( 'documento com flag vai para revisao', 'pendente_revisao', $built['doc_status'] );
assert_fiscal( 'chave valida e registrada como valida', 'valida', $built['access_key_status'] );

$clean = papelito_fiscal_document_build(
	array(
		'order_id'    => 4242,
		'vendor_id'   => 9,
		'doc_type'    => 'nfe',
		'access_key'  => $key,
		'issuer_cnpj' => $issuer_cnpj,
		'total_cents' => 15000,
		'issued_at'   => '2026-07-15 10:00:00',
	),
	array(
		'access_key'  => $key,
		'issuer_cnpj' => $issuer_cnpj,
		'total'       => '150.00',
	),
	array(
		'vendor_cnpj'      => $issuer_cnpj,
		'part_total_cents' => 15000,
	)
);
assert_fiscal( 'documento coerente nao tem flag', array(), $clean['flags'] );
assert_fiscal( 'documento coerente fica recebida', 'recebida', $clean['doc_status'] );
assert_fiscal( 'documento coerente chega ao nivel 5', PAPELITO_FISCAL_LEVEL_AMOUNT, $clean['validation_level'] );
assert_fiscal( 'tipo desconhecido vira other', 'other', papelito_fiscal_document_build( array( 'doc_type' => 'inventado' ) )['doc_type'] );

// 16. Evento de auditoria não guarda PII, conteúdo nem chave completa.
$event = papelito_fiscal_event_safe_detail(
	array(
		'access_key'    => $key,
		'doc_status'    => 'pendente_revisao',
		'issuer_name'   => 'Papelaria Vendor LTDA',
		'original_name' => 'nota-do-cliente.xml',
		'xml_contents'  => $safe_xml,
		'size_bytes'    => 2048,
		'flags'         => array( 'valor_divergente' ),
	)
);
assert_fiscal( 'evento guarda so os 4 ultimos digitos da chave', substr( $key, -4 ), $event['access_key_last4'] ?? '' );
assert_fiscal( 'evento nao guarda a chave completa', false, isset( $event['access_key'] ) );
assert_fiscal( 'evento nao guarda razao social', false, isset( $event['issuer_name'] ) );
assert_fiscal( 'evento nao guarda nome original', false, isset( $event['original_name'] ) );
assert_fiscal( 'evento nao guarda conteudo', false, isset( $event['xml_contents'] ) );
assert_fiscal( 'evento guarda o status', 'pendente_revisao', $event['doc_status'] ?? '' );
assert_fiscal( 'evento guarda tamanho numerico', 2048, $event['size_bytes'] ?? 0 );
assert_fiscal( 'evento guarda flags', array( 'valor_divergente' ), $event['flags'] ?? array() );

// 17. A fundação não registra rota nem toca pagamento/fulfillment.
$fiscal_sources = file_get_contents( __DIR__ . '/../includes/fiscal_documents.php' )
	. file_get_contents( __DIR__ . '/../includes/fiscal_document_validation.php' );
foreach ( array( 'register_rest_route', 'update_status', 'payment_complete', 'papelito_set_vendor_stock', '_papelito_vendor_status' ) as $forbidden ) {
	assert_fiscal( "fundacao nao usa {$forbidden}", false, str_contains( $fiscal_sources, $forbidden ) );
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
