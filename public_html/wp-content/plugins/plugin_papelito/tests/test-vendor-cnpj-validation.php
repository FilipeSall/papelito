<?php
/**
 * Standalone regression test for the vendor CNPJ validation.
 *
 * The vendor flow used to accept any string matching the CNPJ mask, so a payload
 * sent straight to POST /papelito/v1/admin/vendors could create a vendor with
 * fabricated check digits. Every CNPJ gate in revendedor_application.php now runs
 * the official mod 11 check through papelito_revendedor_validate_cnpj().
 *
 * Usage: php tests/test-vendor-cnpj-validation.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function sanitize_email( $value ) { return (string) $value; }
function is_email( $value ) { return false !== strpos( (string) $value, '@' ); }
function papelito_auth_normalize_phone( $value ) { return preg_replace( '/\D+/', '', (string) $value ); }
function papelito_brazilian_states() { return array( 'SP' => 'Sao Paulo' ); }

class WP_Error {
	public $codes = array();

	public function add( $code, $message = '' ) {
		$this->codes[] = $code;
	}

	public function has( $code ) {
		return in_array( $code, $this->codes, true );
	}
}

require __DIR__ . '/../includes/revendedor_application.php';

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

echo "Scenario 1: CNPJ with valid check digits is accepted\n";
papelito_assert( '65.326.368/0001-90', true, papelito_revendedor_validate_cnpj( '65.326.368/0001-90' ) );
papelito_assert( '11.222.333/0001-81', true, papelito_revendedor_validate_cnpj( '11.222.333/0001-81' ) );

echo "Scenario 2: mask is right but check digits are fabricated -> rejected\n";
papelito_assert( '65.326.368/0001-91', false, papelito_revendedor_validate_cnpj( '65.326.368/0001-91' ) );
papelito_assert( '12.345.678/0001-00', false, papelito_revendedor_validate_cnpj( '12.345.678/0001-00' ) );

echo "Scenario 3: repeated digits -> rejected\n";
papelito_assert( '11.111.111/1111-11', false, papelito_revendedor_validate_cnpj( '11.111.111/1111-11' ) );
papelito_assert( '00.000.000/0000-00', false, papelito_revendedor_validate_cnpj( '00.000.000/0000-00' ) );

echo "Scenario 4: mask stays mandatory (contract of the vendor endpoints)\n";
papelito_assert( 'unmasked digits', false, papelito_revendedor_validate_cnpj( '65326368000190' ) );
papelito_assert( 'empty string', false, papelito_revendedor_validate_cnpj( '' ) );
papelito_assert( 'partial', false, papelito_revendedor_validate_cnpj( '65.326.368/0001' ) );

echo "Scenario 5: alphanumeric CNPJ stays out of the vendor flow\n";
papelito_assert( '12.ABC.345/01DE-35', false, papelito_revendedor_validate_cnpj( '12.ABC.345/01DE-35' ) );

echo "Scenario 6: no CNPJ gate may fall back to the mask-only regex\n";
$source          = (string) file_get_contents( __DIR__ . '/../includes/revendedor_application.php' );
$pattern_matches = preg_match_all( '/PAPELITO_VENDOR_CNPJ_PATTERN/', $source );
papelito_assert( 'regex referenced only by the const and the helper', 2, $pattern_matches );

echo "Scenario 7: the submit gate of the revendedor application rejects a fabricated CNPJ\n";
$base = array( 'email' => 'vendor@teste.com', 'phoneNumber' => '(11) 99999-9999', 'state' => 'SP' );

$errors = new WP_Error();
papelito_validate_seller_identity_fields( array_merge( $base, array( 'cnpj' => '65.326.368/0001-91' ) ), $errors );
papelito_assert( 'fabricated check digits are rejected', true, $errors->has( 'cnpj' ) );

$errors = new WP_Error();
papelito_validate_seller_identity_fields( array_merge( $base, array( 'cnpj' => '65.326.368/0001-90' ) ), $errors );
papelito_assert( 'valid CNPJ passes the gate', false, $errors->has( 'cnpj' ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
