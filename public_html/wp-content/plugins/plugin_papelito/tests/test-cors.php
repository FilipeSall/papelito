<?php
/**
 * Regressao do CORS estrito da REST API e do WPGraphQL.
 *
 * Usage: php public_html/wp-content/plugins/plugin_papelito/tests/test-cors.php
 *
 * @package Papelito
 */

// phpcs:disable -- Este e um teste CLI autonomo: os stubs e a saida de diagnostico nao passam pelo ciclo HTTP do WordPress.

define( 'ABSPATH', __DIR__ );
define( 'PAPELITO_ALLOWED_ORIGINS', 'https://marketplace.papelito.com,https://papelito-web.vercel.app,http://localhost:3000' );

$GLOBALS['pap_cors_hooks'] = array();

function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['pap_cors_hooks'][ $hook ][ $priority ][] = $callback;
}
function add_filter( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {
	add_action( $hook, $callback, $priority, $args );
}
function remove_filter( string $hook, mixed $callback, int $priority = 10 ): void {
	foreach ( $GLOBALS['pap_cors_hooks'][ $hook ] ?? array() as $registered_priority => $callbacks ) {
		foreach ( $callbacks as $index => $registered_callback ) {
			if ( $registered_callback === $callback ) {
				unset( $GLOBALS['pap_cors_hooks'][ $hook ][ $registered_priority ][ $index ] );
			}
		}
	}
}
function sanitize_text_field( string $value ): string { return trim( $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }

require_once __DIR__ . '/../../../mu-plugins/papelito-cors.php';

$failures = 0;
function cors_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}
function cors_do_action( string $hook ): void {
	foreach ( $GLOBALS['pap_cors_hooks'][ $hook ] ?? array() as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callback();
		}
	}
}
function cors_has_callback( string $hook, mixed $callback ): bool {
	foreach ( $GLOBALS['pap_cors_hooks'][ $hook ] ?? array() as $callbacks ) {
		if ( in_array( $callback, $callbacks, true ) ) {
			return true;
		}
	}

	return false;
}

// Simula o registro que o WordPress Core faz em rest_api_init, prioridade 10.
add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers', 10 );
cors_do_action( 'rest_api_init' );
cors_assert( 'remove o CORS reflexivo do WordPress Core', false, cors_has_callback( 'rest_pre_serve_request', 'rest_send_cors_headers' ) );

$_SERVER['HTTP_ORIGIN'] = 'https://marketplace.papelito.com';
$allowed_headers         = papelito_cors_headers_for_origin( papelito_cors_request_origin() );
cors_assert( 'autoriza o dominio canonico', 'https://marketplace.papelito.com', $allowed_headers['Access-Control-Allow-Origin'] ?? null );
cors_assert( 'permite credenciais apenas na origem autorizada', 'true', $allowed_headers['Access-Control-Allow-Credentials'] ?? null );

$_SERVER['HTTP_ORIGIN'] = 'https://papelito-web.vercel.app';
cors_assert( 'autoriza o dominio tecnico da Vercel', 'https://papelito-web.vercel.app', papelito_cors_headers_for_origin( papelito_cors_request_origin() )['Access-Control-Allow-Origin'] ?? null );

$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
cors_assert( 'rejeita origem nao listada', array(), papelito_cors_headers_for_origin( papelito_cors_request_origin() ) );

$graphql_headers = papelito_cors_graphql_response_headers(
	array(
		'Access-Control-Allow-Origin'      => '*',
		'Access-Control-Allow-Credentials' => 'true',
		'Vary'                             => 'Origin',
		'Content-Type'                     => 'application/json',
	)
);
cors_assert( 'GraphQL remove o wildcard para origem nao listada', false, isset( $graphql_headers['Access-Control-Allow-Origin'] ) );
cors_assert( 'GraphQL preserva cabecalhos nao-CORS', 'application/json', $graphql_headers['Content-Type'] ?? null );

$_SERVER['HTTP_ORIGIN'] = 'https://marketplace.papelito.com';
$graphql_headers         = papelito_cors_graphql_response_headers( array( 'Access-Control-Allow-Origin' => '*' ) );
cors_assert( 'GraphQL usa a origem exata permitida', 'https://marketplace.papelito.com', $graphql_headers['Access-Control-Allow-Origin'] ?? null );
cors_assert( 'GraphQL nunca mantem wildcard', false, in_array( '*', $graphql_headers, true ) );

echo 0 === $failures ? "\nOK\n" : "\n{$failures} FALHA(S)\n";
exit( 0 === $failures ? 0 : 1 );
