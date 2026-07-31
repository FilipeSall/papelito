<?php
/**
 * Standalone tests for fiscal XML parsing and extraction.
 *
 * Exige a extensão **SimpleXML**, que o PHP CLI do host normalmente não tem.
 * Rode dentro do container:
 *
 *   docker compose exec web php wp-content/plugins/plugin_papelito/tests/test-fiscal-xml.php
 *
 * Falha explicitamente quando a extensão falta — não pula em silêncio.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

if ( ! class_exists( 'SimpleXMLElement' ) ) {
	fwrite( STDERR, "SimpleXML ausente. Rode este teste dentro do container: docker compose exec web php wp-content/plugins/plugin_papelito/tests/test-fiscal-xml.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

function sanitize_text_field( mixed $value ) {
	return trim( (string) $value ); }
function sanitize_key( mixed $value ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function wp_json_encode( mixed $value ) {
	return json_encode( $value ); }

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
	public function get_error_data() {
		return $this->data; }
}

require_once __DIR__ . '/../includes/cnpj_validation.php';
require_once __DIR__ . '/../includes/private_files.php';
require_once __DIR__ . '/../includes/fiscal_document_validation.php';

$failures = 0;

/**
 * Compara valores e contabiliza falhas.
 *
 * @param mixed $expected Valor esperado.
 * @param mixed $actual   Valor obtido.
 */
function assert_fiscal_xml( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

$issuer_cnpj = '11222333000181';
$base43      = implode( '', array( '35', '2607', $issuer_cnpj, '55', '001', '000000123', '1', '12345678' ) );
$key         = $base43 . papelito_fiscal_key_check_digit( $base43 );

$nfe_xml = '<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe' . $key . '" versao="4.00">
      <ide><nNF>123</nNF><serie>1</serie><dhEmi>2026-07-15T10:00:00-03:00</dhEmi></ide>
      <emit><CNPJ>' . $issuer_cnpj . '</CNPJ><xNome>Papelaria Vendor LTDA</xNome></emit>
      <total><ICMSTot><vNF>150.00</vNF></ICMSTot></total>
    </infNFe>
  </NFe>
  <protNFe><infProt><nProt>135260000123456</nProt></infProt></protNFe>
</nfeProc>';

// 1. XML válido parseia e extrai.
$parsed = papelito_fiscal_xml_parse( $nfe_xml );
assert_fiscal_xml( 'xml de nfe parseia', true, $parsed instanceof SimpleXMLElement );

if ( $parsed instanceof SimpleXMLElement ) {
	$extracted = papelito_fiscal_xml_extract( $parsed );

	assert_fiscal_xml( 'extrai a chave sem o prefixo NFe', $key, $extracted['access_key'] );
	assert_fiscal_xml( 'extrai o cnpj do emitente', $issuer_cnpj, $extracted['issuer_cnpj'] );
	assert_fiscal_xml( 'extrai a razao social', 'Papelaria Vendor LTDA', $extracted['issuer_name'] );
	assert_fiscal_xml( 'extrai o numero', '123', $extracted['number'] );
	assert_fiscal_xml( 'extrai a serie', '1', $extracted['series'] );
	assert_fiscal_xml( 'extrai o protocolo', '135260000123456', $extracted['protocol'] );
	assert_fiscal_xml( 'extrai o valor', '150.00', $extracted['total'] );
	assert_fiscal_xml( 'valor extraido em centavos', 15000, papelito_fiscal_amount_to_cents( $extracted['total'] ) );
	assert_fiscal_xml( 'emissao normalizada para utc', '2026-07-15 13:00:00', papelito_fiscal_normalize_datetime( $extracted['issued_at'] ) );
}

// 2. Raízes fora da lista são recusadas.
$foreign = papelito_fiscal_xml_parse( '<?xml version="1.0"?><pedido><item/></pedido>' );
assert_fiscal_xml( 'raiz desconhecida e recusada', 'papelito_fiscal_xml_root_invalid', $foreign instanceof WP_Error ? $foreign->get_error_code() : 'aceitou' );

foreach ( array( 'NFe', 'enviNFe', 'nfseProc', 'NFSe', 'DPS' ) as $root ) {
	$accepted = papelito_fiscal_xml_parse( '<?xml version="1.0"?><' . $root . '/>' );
	assert_fiscal_xml( "raiz {$root} e aceita", true, $accepted instanceof SimpleXMLElement );
}

// 3. XML malformado é recusado e não chega ao disco.
$malformed = papelito_fiscal_xml_parse( '<?xml version="1.0"?><nfeProc><NFe></nfeProc>' );
assert_fiscal_xml( 'xml malformado e recusado', 'papelito_fiscal_xml_malformed', $malformed instanceof WP_Error ? $malformed->get_error_code() : 'aceitou' );
assert_fiscal_xml( 'malformado devolve 422', 422, $malformed instanceof WP_Error ? ( $malformed->get_error_data()['status'] ?? 0 ) : 0 );

// 4. XXE não é processado nem com entidade declarada.
$xxe = papelito_fiscal_xml_parse( '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><nfeProc><NFe>&x;</NFe></nfeProc>' );
assert_fiscal_xml( 'XXE e recusado antes do parse', 'papelito_fiscal_xml_unsafe', $xxe instanceof WP_Error ? $xxe->get_error_code() : 'aceitou' );

// 5. NFS-e sem namespace também extrai.
$nfse = papelito_fiscal_xml_parse(
	'<?xml version="1.0"?><nfseProc><NFSe><prest><CNPJ>' . $issuer_cnpj . '</CNPJ><xNome>Servico LTDA</xNome></prest><vServ>99.90</vServ></NFSe></nfseProc>'
);
assert_fiscal_xml( 'nfse parseia', true, $nfse instanceof SimpleXMLElement );

if ( $nfse instanceof SimpleXMLElement ) {
	$nfse_data = papelito_fiscal_xml_extract( $nfse );
	assert_fiscal_xml( 'nfse extrai o prestador', $issuer_cnpj, $nfse_data['issuer_cnpj'] );
	assert_fiscal_xml( 'nfse extrai o valor do servico', 9990, papelito_fiscal_amount_to_cents( $nfse_data['total'] ) );
	assert_fiscal_xml( 'nfse sem chave fica ausente', 'ausente', papelito_fiscal_key_status( $nfse_data['access_key'] ) );
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
