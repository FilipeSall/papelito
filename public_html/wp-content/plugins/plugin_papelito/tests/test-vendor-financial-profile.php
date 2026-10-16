<?php
/**
 * Perfil financeiro comum do vendor: importação do draft legado, edição parcial, máscara e versão.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META', 'papelito_pagarme_recipient_draft' );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

$GLOBALS['profile_test_meta']         = array();
$GLOBALS['profile_test_cipher_broken'] = false;

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): void { $GLOBALS['profile_test_actions'][] = $args[0]; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( mixed $value ): string { return trim( (string) $value ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_salt( string $scheme = 'auth' ): string { return 'salt-' . $scheme; }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-16 12:00:00'; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['profile_test_meta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( int $user_id, string $key, mixed $value, mixed $prev = '' ): bool {
	$current = $GLOBALS['profile_test_meta'][ $user_id ][ $key ] ?? '';
	if ( '' !== $prev && $current !== $prev ) {
		return false;
	}
	$GLOBALS['profile_test_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function add_user_meta( int $user_id, string $key, mixed $value, bool $unique = false ): bool {
	if ( $unique && isset( $GLOBALS['profile_test_meta'][ $user_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['profile_test_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function papelito_pii_encrypt( string $plaintext ) {
	if ( $GLOBALS['profile_test_cipher_broken'] ) {
		return new WP_Error( 'papelito_pii_encrypt_failed', 'falhou' );
	}
	return 'v1:' . base64_encode( strrev( $plaintext ) );
}
function papelito_pii_decrypt( string $envelope ) {
	return 0 === strpos( $envelope, 'v1:' ) ? strrev( base64_decode( substr( $envelope, 3 ) ) ) : new WP_Error( 'papelito_pii_envelope_malformed', 'x' );
}
function papelito_revendedor_validate_cpf( string $value ): bool { return 11 === strlen( preg_replace( '/\D+/', '', $value ) ); }
function papelito_get_vendor_pagarme_recipient_draft( int $user_id ): ?array {
	$raw = (string) get_user_meta( $user_id, PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META, true );
	return '' === $raw ? null : json_decode( $raw, true );
}

require_once dirname( __DIR__ ) . '/includes/vendor_financial_profile.php';

const PROFILE_TEST_VENDOR    = 61;
const PROFILE_TEST_CPF       = '12345678909';
const PROFILE_TEST_NEW_CPF   = '98765432100';
const PROFILE_TEST_BIRTHDATE = '1980-05-04';
const PROFILE_TEST_CNPJ      = '11222333000181';

$failures = 0;

function profile_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

$legacy_draft = array(
	'companyName'      => 'Tabacaria Exemplo LTDA',
	'tradingName'      => 'Tabacaria Exemplo',
	'annualRevenue'    => '120000',
	'managingPartners' => array(
		array(
			'name'      => 'Maria Socia',
			'email'     => 'maria@example.com',
			'document'  => PROFILE_TEST_CPF,
			'birthdate' => PROFILE_TEST_BIRTHDATE,
			'address'   => array( 'zipCode' => '01001000', 'street' => 'Rua A', 'streetNumber' => '10', 'city' => 'São Paulo', 'state' => 'SP' ),
		),
	),
	'bankAccount'      => array(
		'holderName'     => 'Tabacaria Exemplo LTDA',
		'holderType'     => 'company',
		'holderDocument' => PROFILE_TEST_CNPJ,
		'bankCode'       => '341',
		'branchNumber'   => '1234',
		'accountNumber'  => '56789',
	),
);
$GLOBALS['profile_test_meta'][ PROFILE_TEST_VENDOR ]['cnpj']                                      = PROFILE_TEST_CNPJ;
$GLOBALS['profile_test_meta'][ PROFILE_TEST_VENDOR ][ PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META ] = json_encode( $legacy_draft );

$preview = papelito_vendor_financial_profile_import_legacy( PROFILE_TEST_VENDOR, true );
profile_assert( 'preview anuncia criação sem gravar', 'create' === $preview['action'] && null === papelito_vendor_financial_profile_stored( PROFILE_TEST_VENDOR ) );

$first  = papelito_vendor_financial_profile_import_legacy( PROFILE_TEST_VENDOR, false );
$stored = papelito_vendor_financial_profile_stored( PROFILE_TEST_VENDOR );
profile_assert( 'importação cria perfil versão 1', 'create' === $first['action'] && 1 === $stored['version'] );
profile_assert( 'perfil gravado não contém CPF nem nascimento em claro', false === strpos( json_encode( $stored ), PROFILE_TEST_CPF ) && false === strpos( json_encode( $stored ), PROFILE_TEST_BIRTHDATE ) );

$second = papelito_vendor_financial_profile_import_legacy( PROFILE_TEST_VENDOR, false );
profile_assert( 'rodar duas vezes não muda o perfil', 'noop' === $second['action'] && 1 === papelito_vendor_financial_profile_stored( PROFILE_TEST_VENDOR )['version'] );

$internal = papelito_vendor_financial_profile_internal( PROFILE_TEST_VENDOR );
profile_assert( 'perfil interno devolve CPF ao adapter', PROFILE_TEST_CPF === $internal['managingPartners'][0]['document'] );
profile_assert( 'perfil interno devolve nascimento ao adapter', PROFILE_TEST_BIRTHDATE === $internal['managingPartners'][0]['birthdate'] );
profile_assert( 'campos ausentes no draft continuam ausentes, sem valor inventado', '' === $internal['managingPartners'][0]['monthlyIncome'] && '' === $internal['corporationType'] );

$public = papelito_vendor_financial_profile_public( PROFILE_TEST_VENDOR );
profile_assert( 'projeção pública não expõe CPF nem nascimento', false === strpos( json_encode( $public ), PROFILE_TEST_CPF ) && false === strpos( json_encode( $public ), PROFILE_TEST_BIRTHDATE ) );
profile_assert( 'projeção pública indica CPF salvo pelos 4 finais', '8909' === $public['profile']['managingPartners'][0]['documentLast4'] && true === $public['profile']['managingPartners'][0]['hasDocument'] );
profile_assert( 'projeção pública indica nascimento salvo', true === $public['profile']['managingPartners'][0]['hasBirthdate'] );
profile_assert( 'projeção pública traz versão', 1 === $public['version'] );

$patched = papelito_vendor_financial_profile_patch( PROFILE_TEST_VENDOR, array( 'tradingName' => 'Novo Nome' ), 1 );
$internal = papelito_vendor_financial_profile_internal( PROFILE_TEST_VENDOR );
profile_assert( 'edição parcial aumenta a versão', ! is_wp_error( $patched ) && 2 === $patched['version'] );
profile_assert( 'campo omitido mantém CPF', PROFILE_TEST_CPF === $internal['managingPartners'][0]['document'] );
profile_assert( 'campo omitido mantém conta', '56789' === $internal['bankAccount']['accountNumber'] );
profile_assert( 'campo enviado foi alterado', 'Novo Nome' === $internal['tradingName'] );

$stale = papelito_vendor_financial_profile_patch( PROFILE_TEST_VENDOR, array( 'tradingName' => 'Obsoleto' ), 1 );
profile_assert( 'versão obsoleta é conflito 409 sem sobrescrita', is_wp_error( $stale ) && 409 === $stale->get_error_data()['status'] && 'Novo Nome' === papelito_vendor_financial_profile_internal( PROFILE_TEST_VENDOR )['tradingName'] );

$masked = papelito_vendor_financial_profile_patch( PROFILE_TEST_VENDOR, array( 'managingPartners' => array( array( 'document' => '***.***.*89-09' ) ) ), 2 );
profile_assert( 'máscara enviada como CPF é recusada', is_wp_error( $masked ) && 'papelito_financial_profile_masked_value' === $masked->get_error_code() );

$removed = papelito_vendor_financial_profile_patch( PROFILE_TEST_VENDOR, array( 'managingPartners' => array( array( 'document' => '' ) ) ), 2 );
profile_assert( 'remover CPF exige validação específica e é recusado', is_wp_error( $removed ) && 'papelito_financial_profile_sensitive_removal' === $removed->get_error_code() );

$invalid = papelito_vendor_financial_profile_patch( PROFILE_TEST_VENDOR, array( 'managingPartners' => array( array( 'document' => '123' ) ) ), 2 );
profile_assert( 'CPF inválido é recusado', is_wp_error( $invalid ) );

$replaced = papelito_vendor_financial_profile_patch( PROFILE_TEST_VENDOR, array( 'managingPartners' => array( array( 'document' => PROFILE_TEST_NEW_CPF ) ) ), 2 );
$internal = papelito_vendor_financial_profile_internal( PROFILE_TEST_VENDOR );
profile_assert( 'CPF novo válido substitui o anterior', ! is_wp_error( $replaced ) && PROFILE_TEST_NEW_CPF === $internal['managingPartners'][0]['document'] );
profile_assert( 'substituir CPF preserva o resto do sócio', 'Maria Socia' === $internal['managingPartners'][0]['name'] && PROFILE_TEST_BIRTHDATE === $internal['managingPartners'][0]['birthdate'] );

$GLOBALS['profile_test_cipher_broken'] = true;
$before = papelito_vendor_financial_profile_stored( PROFILE_TEST_VENDOR );
$broken = papelito_vendor_financial_profile_patch( PROFILE_TEST_VENDOR, array( 'managingPartners' => array( array( 'document' => PROFILE_TEST_CPF ) ) ), 3 );
profile_assert( 'falha de criptografia não salva nada', is_wp_error( $broken ) && $before === papelito_vendor_financial_profile_stored( PROFILE_TEST_VENDOR ) );
$GLOBALS['profile_test_cipher_broken'] = false;

$legacy_draft['tradingName'] = 'Draft Antigo Alterado';
$GLOBALS['profile_test_meta'][ PROFILE_TEST_VENDOR ][ PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META ] = json_encode( $legacy_draft );
$diverged = papelito_vendor_financial_profile_import_legacy( PROFILE_TEST_VENDOR, false );
profile_assert( 'draft antigo não substitui perfil editado', 'diverged' === $diverged['action'] && 'Novo Nome' === papelito_vendor_financial_profile_internal( PROFILE_TEST_VENDOR )['tradingName'] );

$partial_vendor = 62;
$GLOBALS['profile_test_meta'][ $partial_vendor ]['cnpj']                                      = PROFILE_TEST_CNPJ;
$GLOBALS['profile_test_meta'][ $partial_vendor ][ PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META ] = json_encode( array( 'companyName' => 'Parcial LTDA' ) );
papelito_vendor_financial_profile_import_legacy( $partial_vendor, false );
$partial = papelito_vendor_financial_profile_public( $partial_vendor );
profile_assert( 'draft parcial continua parcial', 'Parcial LTDA' === $partial['profile']['companyName'] && false === $partial['profile']['managingPartners'][0]['hasDocument'] );

$GLOBALS['profile_test_meta'][ PROFILE_TEST_VENDOR ]['cnpj'] = '44555666000199';
profile_assert( 'CNPJ alterado sinaliza reavaliação financeira', true === papelito_vendor_financial_profile_public( PROFILE_TEST_VENDOR )['cnpjChanged'] );

profile_assert( 'vendor sem draft nem perfil tem perfil vazio, sem erro', 0 === papelito_vendor_financial_profile_public( 999 )['version'] );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
