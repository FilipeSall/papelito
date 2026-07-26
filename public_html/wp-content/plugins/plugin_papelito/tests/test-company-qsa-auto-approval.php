<?php
/**
 * QSA ownership-evidence and auto-approval tests.
 *
 * The Receita masks the partner CPF as `***112108**`: it hides the first 3 and last 2 digits and
 * exposes digits 4 through 9. Comparing "last 4 vs last 4" never matched, so every company fell
 * into manual review even when the partner was clearly the person registering.
 */

define( 'ABSPATH', __DIR__ );

// Espelha includes/company_services.php: pontuação vira ESPAÇO (não some) e acento é removido.
// Sem depender de ext-intl, que não está instalada no PHP que roda estes scripts.
function papelito_company_normalize_name( string $value ): string {
	$value = strtr(
		trim( $value ),
		array( 'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C' )
	);
	$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) ?? $value;
	$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
}

$flag_enabled = true;
function papelito_b2b_flag( string $name ): bool {
	global $flag_enabled;
	return 'PAPELITO_QSA_AUTO_APPROVE_ENABLED' === $name ? $flag_enabled : false;
}

$source = file_get_contents( __DIR__ . '/../includes/company_services.php' );
foreach ( array( 'papelito_company_cpf_mask_matches', 'papelito_company_qsa_qualification', 'papelito_company_should_auto_approve_owner' ) as $fn ) {
	if ( ! preg_match( '/function ' . $fn . '\(.*?\n}/s', $source, $m ) ) {
		echo "FAIL: could not isolate {$fn}\n";
		exit( 1 );
	}
	eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}

$failures = 0;
function assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "PASS: {$label}\n"; return; }
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

/* --- Máscara de CPF: os dígitos visíveis ficam no MEIO, não no fim --- */

assert_same( 'cpf do socio bate com a mascara da Receita', true, papelito_company_cpf_mask_matches( '10011210826', '***112108**' ) );
assert_same( 'cpf formatado tambem bate', true, papelito_company_cpf_mask_matches( '100.112.108-26', '***112108**' ) );
assert_same( 'cpf de terceiro nao bate', false, papelito_company_cpf_mask_matches( '52998224725', '***112108**' ) );
// Regressão do bug: os últimos 4 dígitos coincidiam mas o miolo não — antes isso "casava".
assert_same( 'cpf com final igual mas miolo diferente nao bate', false, papelito_company_cpf_mask_matches( '99999910826', '***112108**' ) );
assert_same( 'cpf incompleto nao bate', false, papelito_company_cpf_mask_matches( '123', '***112108**' ) );
assert_same( 'mascara vazia nao bate', false, papelito_company_cpf_mask_matches( '10011210826', '' ) );

/* --- Qualificação: BrasilAPI/ReceitaWS mandam string, CNPJ.ws manda objeto --- */

assert_same( 'qualificacao string (brasilapi)', 'PRESIDENTE', papelito_company_qsa_qualification( array( 'qualificacao_socio' => 'Presidente' ) ) );
assert_same( 'qualificacao objeto (cnpjws)', 'SOCIO ADMINISTRADOR', papelito_company_qsa_qualification( array( 'qualificacao_socio' => array( 'descricao' => 'Sócio-Administrador' ) ) ) );
assert_same( 'qualificacao ausente', '', papelito_company_qsa_qualification( array() ) );

/* --- Decisão de aprovação automática --- */

$match  = array( 'qsa_available' => true, 'cpf_mask_match' => true, 'name_match' => true, 'age_band_match' => true, 'partner_match' => true );
$active = array( 'status' => 'active' );

assert_same( 'socio confirmado em cnpj ativo aprova na hora', true, papelito_company_should_auto_approve_owner( $match, $active ) );
assert_same( 'cnpj inativo nao aprova', false, papelito_company_should_auto_approve_owner( $match, array( 'status' => 'inactive' ) ) );
assert_same( 'sem qsa nao aprova', false, papelito_company_should_auto_approve_owner( array( 'qsa_available' => false, 'cpf_mask_match' => 'unknown', 'name_match' => 'unknown' ), $active ) );
assert_same( 'so o nome batendo nao aprova (homonimo)', false, papelito_company_should_auto_approve_owner( array( 'qsa_available' => true, 'cpf_mask_match' => 'unknown', 'name_match' => true ), $active ) );
assert_same( 'so o cpf batendo nao aprova', false, papelito_company_should_auto_approve_owner( array( 'qsa_available' => true, 'cpf_mask_match' => true, 'name_match' => 'unknown' ), $active ) );

assert_same( 'idade divergente nao aprova', false, papelito_company_should_auto_approve_owner( array( 'qsa_available' => true, 'cpf_mask_match' => true, 'name_match' => true, 'age_band_match' => false ), $active ) );
assert_same( 'sinais de socios diferentes nao aprovam', false, papelito_company_should_auto_approve_owner( array( 'qsa_available' => true, 'cpf_mask_match' => true, 'name_match' => true, 'age_band_match' => true ), $active ) );
assert_same( 'mei ativo com titular compativel aprova temporariamente', true, papelito_company_should_auto_approve_owner( array( 'mei_name_match' => true ), array( 'status' => 'active', 'is_mei' => true, 'legal_nature_code' => '2135' ) ) );
assert_same( 'empresario individual nao-mei nao usa excecao', false, papelito_company_should_auto_approve_owner( array( 'mei_name_match' => true ), array( 'status' => 'active', 'is_mei' => false, 'legal_nature_code' => '2135' ) ) );

$flag_enabled = false;
assert_same( 'flag legada nao interfere na autoaprovacao', true, papelito_company_should_auto_approve_owner( $match, $active ) );
$flag_enabled = true;

if ( $failures > 0 ) { exit( 1 ); }
echo "RESULT: all assertions passed\n";
