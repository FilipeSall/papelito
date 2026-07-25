<?php
/**
 * Standalone regression test for the B2B rollout flags.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}

$papelito_test_env = array();
function papelito_env( string $key, $default = null ) { global $papelito_test_env; return $papelito_test_env[ $key ] ?? $default; }
function papelito_env_bool( string $key, bool $default = false ): bool {
	$value = papelito_env( $key, null );
	if ( null === $value ) { return $default; }
	$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	return null === $parsed ? $default : $parsed;
}

require __DIR__ . '/../includes/company_flags.php';

$failures = 0;
function papelito_company_flag_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "PASS: {$label}\n"; return; }
	++$failures;
	echo "FAIL: {$label}\n";
}

papelito_company_flag_assert_same( 'company rollout defaults disabled', false, papelito_b2b_company_model_enabled() );
papelito_company_flag_assert_same( 'company writes default disabled', false, papelito_b2b_company_writes_enabled() );
$papelito_test_env['PAPELITO_B2B_COMPANY_MODEL_ENABLED'] = 'true';
$papelito_test_env['PAPELITO_B2B_COMPANY_WRITES_ENABLED'] = 'true';
papelito_company_flag_assert_same( 'company rollout enabled', true, papelito_b2b_company_model_enabled() );
papelito_company_flag_assert_same( 'company writes enabled', true, papelito_b2b_company_writes_enabled() );
papelito_company_flag_assert_same( 'unknown flag stays disabled', false, papelito_b2b_flag( 'PAPELITO_NOT_A_FLAG' ) );

exit( $failures > 0 ? 1 : 0 );
