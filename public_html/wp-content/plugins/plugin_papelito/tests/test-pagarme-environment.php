<?php
/**
 * Standalone regression test for Pagar.me environment validation.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$papelito_test_env = array();

function sanitize_key( mixed $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}

function papelito_env( string $key, $default = null ) {
	global $papelito_test_env;
	return array_key_exists( $key, $papelito_test_env ) ? $papelito_test_env[ $key ] : $default;
}

class WP_Error {
	public function __construct( public string $code, public string $message, public mixed $data = null ) {}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

require __DIR__ . '/../includes/pagarme_client.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: production rejects test secret keys\n";
$papelito_test_env = array(
	'WP_ENVIRONMENT_TYPE' => 'production',
	'PAGARME_SECRET_KEY'  => 'sk_test_example',
);
papelito_assert_same( 'test key invalid in production', false, papelito_pagarme_is_configured() );

echo "Scenario 2: local rejects live secret keys\n";
$papelito_test_env = array(
	'WP_ENVIRONMENT_TYPE' => 'local',
	'PAGARME_SECRET_KEY'  => 'sk_live_example',
);
papelito_assert_same( 'live key invalid locally', false, papelito_pagarme_is_configured() );

echo "Scenario 3: local accepts test secret keys\n";
$papelito_test_env = array(
	'WP_ENVIRONMENT_TYPE' => 'local',
	'PAGARME_SECRET_KEY'  => 'sk_test_example',
);
papelito_assert_same( 'test key valid locally', true, papelito_pagarme_is_configured() );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
