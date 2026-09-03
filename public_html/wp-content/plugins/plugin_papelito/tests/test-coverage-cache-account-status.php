<?php
/**
 * Standalone regression test: suspender ou reativar conta invalida o cache de cobertura.
 *
 * Sem isso a vitrine seguia oferecendo o vendor suspenso ate o transient de 5 minutos expirar, e o
 * comprador so descobria o bloqueio no checkout.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$papelito_options = array();
$papelito_actions = array();

function get_option( string $name, mixed $default = false ): mixed {
	global $papelito_options;
	return $papelito_options[ $name ] ?? $default;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	global $papelito_options;
	$papelito_options[ $name ] = $value;
	return true;
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted = 1 ): void {
	global $papelito_actions;
	$papelito_actions[ $hook ][] = $callback;
}

function wp_json_encode( mixed $value ): string {
	return json_encode( $value );
}

/**
 * Dispara os callbacks registrados para um hook de usermeta.
 */
function papelito_fire_meta_hook( string $hook, int $user_id, string $meta_key ): void {
	global $papelito_actions;

	foreach ( $papelito_actions[ $hook ] ?? array() as $callback ) {
		$callback( 1, $user_id, $meta_key );
	}
}

require_once __DIR__ . '/support/coverage_cache_boot.php';

$failures = 0;

function coverage_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

$before = papelito_coverage_cache_version();

papelito_fire_meta_hook( 'updated_user_meta', 42, 'papelito_account_status' );
coverage_assert( 'suspender invalida a cobertura', $before + 1, papelito_coverage_cache_version() );

$after_suspend = papelito_coverage_cache_version();
papelito_fire_meta_hook( 'deleted_user_meta', 42, 'papelito_account_status' );
coverage_assert( 'reativar invalida a cobertura', $after_suspend + 1, papelito_coverage_cache_version() );

$after_reactivate = papelito_coverage_cache_version();
papelito_fire_meta_hook( 'updated_user_meta', 42, 'description' );
coverage_assert( 'meta irrelevante nao invalida', $after_reactivate, papelito_coverage_cache_version() );

$key_active = papelito_coverage_products_cache_key( '70000000', array( 1 ), 1, 0, array( 1 => 1 ) );
papelito_fire_meta_hook( 'updated_user_meta', 42, 'papelito_account_status' );
$key_suspended = papelito_coverage_products_cache_key( '70000000', array( 1 ), 1, 0, array( 1 => 1 ) );
coverage_assert( 'a chave de cache muda com o status da conta', true, $key_active !== $key_suspended );

exit( $failures > 0 ? 1 : 0 );
