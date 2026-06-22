<?php
/**
 * Standalone regression test for the recipient bank-account payload.
 *
 * Reproduces the Nubank failure: agency 0001 has no branch check digit, so an
 * empty branchCheckDigit must be OMITTED from the Pagar.me payload, not sent as
 * "" (which the API rejects with "invalid_parameter | agencia_dv | Invalid
 * format"). The same applies to an empty accountCheckDigit.
 *
 * Usage: php tests/test-pagarme-bank-account-payload.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $value ) );
}

function add_action( ...$args ) {}
function add_filter( ...$args ) {}

require __DIR__ . '/../includes/pagarme_recipients.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

$nubank = array(
	'holderName'       => 'Filipe Reges De Salles',
	'holderType'       => 'company',
	'holderDocument'   => '65.326.368/0001-90',
	'bankCode'         => '260',
	'branchNumber'     => '0001',
	'branchCheckDigit' => '',
	'accountNumber'    => '66566179',
	'accountCheckDigit' => '5',
	'type'             => 'checking',
);

echo "Scenario 1: Nubank (no branch check digit) omits branch_check_digit\n";
$payload = papelito_pagarme_bank_account_payload( $nubank, 'Loja Fallback', '11222333000181' );
papelito_assert( 'branch_check_digit absent when empty', false, array_key_exists( 'branch_check_digit', $payload ) );
papelito_assert( 'branch_number preserved', '0001', $payload['branch_number'] );
papelito_assert( 'account_check_digit kept when present', '5', $payload['account_check_digit'] );
papelito_assert( 'bank preserved', '260', $payload['bank'] );
papelito_assert( 'holder_document digits only', '65326368000190', $payload['holder_document'] );

echo "Scenario 2: account with both digits keeps both\n";
$itau = array(
	'bankCode'          => '341',
	'branchNumber'      => '1234',
	'branchCheckDigit'  => '5',
	'accountNumber'     => '67890',
	'accountCheckDigit' => '1',
);
$payload = papelito_pagarme_bank_account_payload( $itau, 'Loja', '11222333000181' );
papelito_assert( 'branch_check_digit kept', '5', $payload['branch_check_digit'] );
papelito_assert( 'account_check_digit kept', '1', $payload['account_check_digit'] );

echo "Scenario 3: empty account_check_digit is also omitted\n";
$no_account_dv = array(
	'bankCode'          => '077',
	'branchNumber'      => '0001',
	'branchCheckDigit'  => '',
	'accountNumber'     => '12345',
	'accountCheckDigit' => '',
);
$payload = papelito_pagarme_bank_account_payload( $no_account_dv, 'Loja', '11222333000181' );
papelito_assert( 'branch_check_digit absent', false, array_key_exists( 'branch_check_digit', $payload ) );
papelito_assert( 'account_check_digit absent', false, array_key_exists( 'account_check_digit', $payload ) );

echo "Scenario 4: defaults fall back to store name / cnpj when not provided\n";
$payload = papelito_pagarme_bank_account_payload( array( 'bankCode' => '260', 'branchNumber' => '0001', 'accountNumber' => '1', 'accountCheckDigit' => '0' ), 'Loja Fallback', '11222333000181' );
papelito_assert( 'holder_name falls back to store name', 'Loja Fallback', $payload['holder_name'] );
papelito_assert( 'holder_type default company', 'company', $payload['holder_type'] );
papelito_assert( 'type default checking', 'checking', $payload['type'] );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
