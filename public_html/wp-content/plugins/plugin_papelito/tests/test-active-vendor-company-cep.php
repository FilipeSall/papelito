<?php
/**
 * Regressão: cobertura B2B usa o CEP fiscal da empresa antes do CEP pessoal.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$failures = 0;
function assert_same( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

$GLOBALS['pap_user_meta'] = array( 42 => array( 'cep' => '' ) );
$GLOBALS['pap_company_context'] = array(
	42 => array( 'company' => array( 'fiscalAddress' => array( 'cep' => '30130-010' ) ) ),
	43 => array( 'company' => array( 'fiscalAddress' => null ) ),
);

function papelito_normalize_cep( string $value ): string { return preg_match( '/^\d{5}-?\d{3}$/', trim( $value ) ) ? preg_replace( '/\D+/', '', $value ) : ''; }
function get_user_meta( int $user_id, string $key, bool $single = false ): string { return (string) ( $GLOBALS['pap_user_meta'][ $user_id ][ $key ] ?? '' ); }
function papelito_company_context( int $user_id ): array { return $GLOBALS['pap_company_context'][ $user_id ] ?? array(); }

$source = file_get_contents( dirname( __DIR__ ) . '/includes/active_vendor.php' );
if ( false === $source || ! preg_match( '/function papelito_get_user_account_cep.*?\n}/s', $source, $match ) ) {
	echo "FAIL: nao isolou papelito_get_user_account_cep\n";
	exit( 1 );
}
eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

echo "Cenario: CEP fiscal B2B tem precedencia\n";
assert_same( 'empresa usa CEP fiscal', '30130010', papelito_get_user_account_cep( 42 ) );
$GLOBALS['pap_user_meta'][43]['cep'] = '71200-030';
assert_same( 'conta sem empresa usa CEP pessoal como fallback', '71200030', papelito_get_user_account_cep( 43 ) );

echo ( 0 === $failures ? "ALL PASS\n" : "{$failures} FAILURE(S)\n" );
exit( 0 === $failures ? 0 : 1 );
