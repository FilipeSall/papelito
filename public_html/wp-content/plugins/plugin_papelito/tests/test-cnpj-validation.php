<?php
/**
 * Standalone regression test for CPF/CNPJ/CEP structural validation.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../includes/cnpj_validation.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

/* ---- CPF ---- */
papelito_assert_same( 'valid CPF (masked)', true, papelito_validate_cpf( '529.982.247-25' ) );
papelito_assert_same( 'valid CPF (digits)', true, papelito_validate_cpf( '52998224725' ) );
papelito_assert_same( 'invalid CPF wrong DV', false, papelito_validate_cpf( '529.982.247-24' ) );
papelito_assert_same( 'invalid CPF repeated', false, papelito_validate_cpf( '111.111.111-11' ) );
papelito_assert_same( 'invalid CPF short', false, papelito_validate_cpf( '1234567890' ) );
papelito_assert_same( 'normalize CPF strips mask', '52998224725', papelito_normalize_cpf( '529.982.247-25' ) );
papelito_assert_same( 'normalize CPF rejects wrong length', '', papelito_normalize_cpf( '5299822472' ) );

/* ---- CNPJ numérico ---- */
papelito_assert_same( 'valid numeric CNPJ (masked)', true, papelito_validate_cnpj( '11.222.333/0001-81' ) );
papelito_assert_same( 'valid numeric CNPJ (digits)', true, papelito_validate_cnpj( '11222333000181' ) );
papelito_assert_same( 'invalid numeric CNPJ wrong DV', false, papelito_validate_cnpj( '11222333000180' ) );
papelito_assert_same( 'invalid all-zero CNPJ', false, papelito_validate_cnpj( '00000000000000' ) );
papelito_assert_same( 'numeric CNPJ is not alphanumeric', false, papelito_cnpj_is_alphanumeric( '11222333000181' ) );

/* ---- CNPJ alfanumérico (exemplo oficial do plano: 12.ABC.345/01DE-35) ---- */
papelito_assert_same( 'valid alpha CNPJ (official example)', true, papelito_validate_cnpj( '12.ABC.345/01DE-35' ) );
papelito_assert_same( 'valid alpha CNPJ (canonical)', true, papelito_validate_cnpj( '12ABC34501DE35' ) );
papelito_assert_same( 'alpha CNPJ lowercase canonicalized', true, papelito_validate_cnpj( '12abc34501de35' ) );
papelito_assert_same( 'valid alpha CNPJ A1B2...', true, papelito_validate_cnpj( 'A1B2C3D4E5F668' ) );
papelito_assert_same( 'invalid alpha CNPJ wrong DV', false, papelito_validate_cnpj( '12ABC34501DE34' ) );
papelito_assert_same( 'alpha CNPJ flagged as alphanumeric', true, papelito_cnpj_is_alphanumeric( '12ABC34501DE35' ) );
papelito_assert_same( 'alpha CNPJ with letters in DV is invalid', false, papelito_validate_cnpj( '12ABC34501DE3X' ) );

/* ---- normalize CNPJ preserva letras, uppercase, não usa \D ---- */
papelito_assert_same( 'normalize keeps letters + uppercase', '12ABC34501DE35', papelito_normalize_cnpj( '12.abc.345/01de-35' ) );
papelito_assert_same( 'normalize rejects wrong length', '', papelito_normalize_cnpj( '12ABC34501DE3' ) );

/* ---- CEP formato ---- */
papelito_assert_same( 'valid CEP format (masked)', true, papelito_validate_cep_format( '70000-000' ) );
papelito_assert_same( 'valid CEP format (digits)', true, papelito_validate_cep_format( '70000000' ) );
papelito_assert_same( 'invalid CEP format short', false, papelito_validate_cep_format( '7000000' ) );
papelito_assert_same( 'normalize CEP', '70000000', papelito_normalize_cep( '70000-000' ) );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
