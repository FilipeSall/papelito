<?php
/**
 * Regressao: e-mail e token de confirmacao nao viajam na query string do link.
 *
 * GTM e GA4 registram a query e ignoram o fragmento; com a confirmacao por clique o token fica vivo
 * por ate 24 h, entao ele e o e-mail precisam ir depois do `#`.
 *
 * Usage: php public_html/wp-content/plugins/plugin_papelito/tests/test-email-verification-link.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['pap_hooks'] = array();

function add_filter( mixed ...$args ): void { $GLOBALS['pap_hooks'][] = $args; }
function add_action( mixed ...$args ): void { $GLOBALS['pap_hooks'][] = $args; }
function is_wp_error( mixed $value ): bool { return false; }
function papelito_frontend_link( string $path ): string { return 'https://marketplace.example.test/' . ltrim( $path, '/' ); }

require_once __DIR__ . '/../includes/auth_endpoints.php';

$failures = 0;
function link_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";

		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

/**
 * Separa caminho, query e fragmento do link como o navegador os enxerga.
 *
 * @return array{path:string,query:array,fragment:array}
 */
function link_parts( string $link ): array {
	$query    = array();
	$fragment = array();
	parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $query );
	parse_str( (string) parse_url( $link, PHP_URL_FRAGMENT ), $fragment );

	return array(
		'path'     => (string) parse_url( $link, PHP_URL_PATH ),
		'query'    => $query,
		'fragment' => $fragment,
	);
}

$plain = link_parts( papelito_auth_build_email_verification_link( 'ana+teste@empresa.test', 'token-abc' ) );
link_assert( 'link aponta para a pagina de confirmacao', '/confirmar-email', $plain['path'] );
link_assert( 'token nao vai na query string', false, isset( $plain['query']['token'] ) );
link_assert( 'e-mail nao vai na query string', false, isset( $plain['query']['email'] ) );
link_assert( 'token viaja no fragmento', 'token-abc', $plain['fragment']['token'] ?? null );
link_assert( 'e-mail viaja no fragmento sem perder o +', 'ana+teste@empresa.test', $plain['fragment']['email'] ?? null );

$invite = link_parts( papelito_auth_build_email_verification_link( 'convidado@empresa.test', 'token-xyz', '/convite' ) );
link_assert( 'convite leva o retorno na query', '/convite', $invite['query']['callbackUrl'] ?? null );
link_assert( 'convite tambem mantem o token fora da query', false, isset( $invite['query']['token'] ) );
link_assert( 'convite leva o token no fragmento', 'token-xyz', $invite['fragment']['token'] ?? null );

$other = link_parts( papelito_auth_build_email_verification_link( 'outro@empresa.test', 'token-qualquer', '/painel' ) );
link_assert( 'retorno fora da allowlist e ignorado', false, isset( $other['query']['callbackUrl'] ) );

echo 0 === $failures ? "\nOK\n" : "\n{$failures} FALHA(S)\n";
exit( 0 === $failures ? 0 : 1 );
