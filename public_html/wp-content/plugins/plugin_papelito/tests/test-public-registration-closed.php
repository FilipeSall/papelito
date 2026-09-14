<?php
/**
 * Regressao: cadastro publico de conta fica fechado, pelo GraphQL e pelo wp-login.
 *
 * Com `users_can_register` ligado, `registerUser` criava conta com senha propria e sem meta de
 * verificacao, que o gate de e-mail le como legada: login com token sem candidatura nem confirmacao.
 *
 * Usage: php public_html/wp-content/plugins/plugin_papelito/tests/test-public-registration-closed.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['pap_hooks']        = array();
$GLOBALS['pap_deregistered'] = array();

function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['pap_hooks'][ $hook ][] = $callback;
}
function add_filter( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['pap_hooks'][ $hook ][] = $callback;
}
function remove_action( string $hook, mixed $callback, int $priority = 10 ): void {
	$GLOBALS['pap_removed_hooks'][] = $hook;
}
function get_transient( string $key ): mixed { return false; }
function set_transient( string $key, mixed $value, int $ttl ): bool { return true; }
function delete_transient( string $key ): bool { return true; }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function __return_empty_string(): string { return ''; }
function __return_false(): bool { return false; }
function __return_zero(): int { return 0; }
function is_user_logged_in(): bool { return false; }
function home_url(): string { return 'https://example.test'; }
function wp_safe_redirect( string $location, int $status = 302 ): bool { return true; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function get_option( string $name ) { return '/%postname%/'; }
function update_option( string $name, mixed $value ): bool { return true; }
function get_user_by( string $field, string $value ): false { return false; }
function deregister_graphql_mutation( string $mutation_name ): void {
	$GLOBALS['pap_deregistered'][] = $mutation_name;
}

require_once __DIR__ . '/../../../mu-plugins/papelito-hardening.php';

$failures = 0;
function registration_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";

		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

/**
 * Reproduz `get_option()` do core: `pre_option_{$name}` diferente de false encurta a leitura do banco.
 */
function get_option_simulado( string $name, mixed $stored ): mixed {
	$pre = false;

	foreach ( $GLOBALS['pap_hooks'][ "pre_option_{$name}" ] ?? array() as $callback ) {
		$pre = $callback( $pre, $name, false );
	}

	return false !== $pre ? $pre : $stored;
}

registration_assert( 'registro publico fica desligado mesmo com a opcao ligada no banco', false, (bool) get_option_simulado( 'users_can_register', '1' ) );
registration_assert( 'outras opcoes continuam vindo do banco', 'subscriber', get_option_simulado( 'default_role', 'subscriber' ) );

$checkout_registration = true;
foreach ( $GLOBALS['pap_hooks']['woocommerce_checkout_registration_enabled'] ?? array() as $callback ) {
	$checkout_registration = $callback( $checkout_registration );
}
registration_assert( 'checkout do Woo e Store API nao criam conta mesmo com a opcao ligada', false, (bool) $checkout_registration );

foreach ( $GLOBALS['pap_hooks']['plugins_loaded'] ?? array() as $callback ) {
	$callback();
}
$deregistered = $GLOBALS['pap_deregistered'];
sort( $deregistered );
registration_assert( 'mutations que criam conta saem do schema GraphQL', array( 'checkout', 'registerCustomer', 'registerUser' ), $deregistered );

echo 0 === $failures ? "\nOK\n" : "\n{$failures} FALHA(S)\n";
exit( 0 === $failures ? 0 : 1 );
