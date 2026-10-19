<?php
/**
 * Standalone regression test for the frontend base URL resolver.
 *
 * Cobre a causa raiz do link de faturamento com localhost: constante e variavel de ambiente sao
 * fontes distintas e o resolvedor antigo lia apenas uma delas.
 *
 * Usage: php tests/test-frontend-base-url.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

const FRONTEND_TEST_PROD_BASE       = 'https://marketplace.papelito.com';
const FRONTEND_TEST_VERCEL_BASE     = 'https://papelito-web.vercel.app';
const FRONTEND_TEST_LOCAL_BASE      = 'http://localhost:3000';
const FRONTEND_TEST_PROD_AND_VERCEL = FRONTEND_TEST_PROD_BASE . ',' . FRONTEND_TEST_VERCEL_BASE;

$GLOBALS['pap_env']         = array();
$GLOBALS['pap_environment'] = 'production';

function papelito_env( string $key, mixed $default = null ): mixed {
	$value = $GLOBALS['pap_env'][ $key ] ?? '';

	return '' === $value ? $default : $value;
}
function wp_get_environment_type(): string { return $GLOBALS['pap_environment']; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = array() ) {}
}
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }

class WP_REST_Request {
	public function __construct( private array $headers = array() ) {}

	public function get_header( string $name ): string { return (string) ( $this->headers[ $name ] ?? '' ); }
}

require __DIR__ . '/../includes/frontend_links.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n";
	}
}

function papelito_test_env( array $env, string $environment = 'production' ): void {
	$GLOBALS['pap_env']         = $env;
	$GLOBALS['pap_environment'] = $environment;
}

/* --- caso 1: variavel de ambiente resolve (era o unico caminho que funcionava antes) --- */
papelito_test_env( array( 'PAPELITO_FRONTEND_URL' => FRONTEND_TEST_PROD_BASE ) );
papelito_assert( 'env resolve a base', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url() );

/* --- caso 2: barra final e caixa sao normalizadas --- */
papelito_test_env( array( 'PAPELITO_FRONTEND_URL' => 'https://Marketplace.Papelito.COM/' ) );
papelito_assert( 'normaliza barra e caixa', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url() );

/* --- caso 3: sem PAPELITO_FRONTEND_URL, cai na primeira origem da allowlist --- */
papelito_test_env( array( 'PAPELITO_ALLOWED_ORIGINS' => FRONTEND_TEST_PROD_AND_VERCEL ) );
papelito_assert( 'fallback para primeira origem', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url() );

/* --- caso 4: ambiente de teste — base solicitada pelo Next entra porque esta na allowlist --- */
papelito_test_env(
	array(
		'PAPELITO_FRONTEND_URL'    => FRONTEND_TEST_PROD_BASE,
		'PAPELITO_ALLOWED_ORIGINS' => FRONTEND_TEST_PROD_AND_VERCEL,
	)
);
papelito_assert( 'preview usa a base solicitada', FRONTEND_TEST_VERCEL_BASE, papelito_frontend_base_url( FRONTEND_TEST_VERCEL_BASE ) );
papelito_assert( 'preview aceita barra final', FRONTEND_TEST_VERCEL_BASE, papelito_frontend_base_url( 'https://papelito-web.vercel.app/' ) );
papelito_assert( 'producao usa a base solicitada', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url( FRONTEND_TEST_PROD_BASE ) );

/* --- caso 5: base fora da allowlist e descartada (header forjado nao vira link de phishing) --- */
papelito_assert( 'base fora da allowlist e ignorada', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url( 'https://evil.example.com' ) );
papelito_assert( 'localhost solicitado em producao e ignorado', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url( FRONTEND_TEST_LOCAL_BASE ) );
papelito_assert( 'esquema nao http e ignorado', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url( 'javascript:alert(1)' ) );
papelito_assert( 'subdominio parecido e ignorado', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url( 'https://papelito-web.vercel.app.evil.com' ) );

/* --- caso 6: porta faz parte da identidade da origem --- */
papelito_test_env( array( 'PAPELITO_ALLOWED_ORIGINS' => FRONTEND_TEST_LOCAL_BASE ), 'local' );
papelito_assert( 'porta diferente nao casa', FRONTEND_TEST_LOCAL_BASE, papelito_frontend_base_url( 'http://localhost:4000' ) );
papelito_assert( 'porta igual casa', FRONTEND_TEST_LOCAL_BASE, papelito_frontend_base_url( FRONTEND_TEST_LOCAL_BASE ) );

/* --- caso 7: ambiente remoto sem nada configurado NAO devolve localhost --- */
papelito_test_env( array(), 'production' );
papelito_assert( 'producao sem config devolve vazio', '', papelito_frontend_base_url() );
papelito_test_env( array(), 'staging' );
papelito_assert( 'staging sem config devolve vazio', '', papelito_frontend_base_url() );

/* --- caso 8: local e development podem cair no localhost --- */
papelito_test_env( array(), 'local' );
papelito_assert( 'local cai no localhost', FRONTEND_TEST_LOCAL_BASE, papelito_frontend_base_url() );
papelito_test_env( array(), 'development' );
papelito_assert( 'development cai no localhost', FRONTEND_TEST_LOCAL_BASE, papelito_frontend_base_url() );

/* --- caso 9: papelito_frontend_link monta o caminho e falha visivelmente sem base --- */
papelito_test_env( array( 'PAPELITO_FRONTEND_URL' => FRONTEND_TEST_PROD_BASE ) );
papelito_assert(
	'link de faturamento montado',
	'https://marketplace.papelito.com/confirmar-email-faturamento?token=abc',
	papelito_frontend_link( 'confirmar-email-faturamento?token=abc' )
);
papelito_test_env( array( 'PAPELITO_ALLOWED_ORIGINS' => FRONTEND_TEST_VERCEL_BASE ) );
papelito_assert(
	'link honra a base de preview',
	'https://papelito-web.vercel.app/confirmar-email-faturamento?token=abc',
	papelito_frontend_link( 'confirmar-email-faturamento?token=abc', FRONTEND_TEST_VERCEL_BASE )
);
papelito_test_env( array(), 'production' );
$link = papelito_frontend_link( 'confirmar-email-faturamento?token=abc' );
papelito_assert( 'sem base configurada retorna WP_Error', true, is_wp_error( $link ) );
papelito_assert( 'codigo do erro', 'papelito_frontend_url_unresolved', $link->code );

/* --- caso 10: apenas o header do proxy Next e lido; Origin nao e fallback --- */
papelito_assert(
	'le X-Papelito-Frontend-Base',
	FRONTEND_TEST_VERCEL_BASE,
	papelito_frontend_base_from_request( new WP_REST_Request( array( 'X-Papelito-Frontend-Base' => FRONTEND_TEST_VERCEL_BASE ) ) )
);
papelito_assert(
	'ignora Origin: quem chama nao escolhe o dominio do link',
	'',
	papelito_frontend_base_from_request( new WP_REST_Request( array( 'Origin' => FRONTEND_TEST_PROD_BASE ) ) )
);
papelito_assert( 'sem requisicao devolve vazio', '', papelito_frontend_base_from_request( null ) );

/* --- caso 11: nenhum ambiente remoto gera link com localhost, em qualquer combinacao --- */
foreach ( array( 'production', 'staging' ) as $environment ) {
	foreach (
		array(
			array(),
			array( 'PAPELITO_FRONTEND_URL' => FRONTEND_TEST_PROD_BASE ),
			array( 'PAPELITO_ALLOWED_ORIGINS' => 'https://marketplace.papelito.com,http://localhost:3000' ),
		) as $index => $env
	) {
		papelito_test_env( $env, $environment );
		$resolved = papelito_frontend_base_url( FRONTEND_TEST_LOCAL_BASE );
		papelito_assert( "invariante {$environment}/{$index}: sem localhost", false, str_contains( $resolved, 'localhost' ) );
	}
}

/* --- caso 13: dominio da requisicao vindo do Next, so da allowlist --- */
$frontend_test_env = array(
	'PAPELITO_ALLOWED_ORIGINS' => FRONTEND_TEST_PROD_AND_VERCEL,
	'PAPELITO_TEST_ORIGINS'    => FRONTEND_TEST_VERCEL_BASE,
);
papelito_test_env( $frontend_test_env );
$_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] = 'https://papelito-web.vercel.app/';
papelito_assert( 'dominio de teste da allowlist e lido da requisicao', FRONTEND_TEST_VERCEL_BASE, papelito_frontend_request_base() );
papelito_assert( 'dominio de teste e reconhecido como teste', true, papelito_frontend_request_is_test() );
$_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] = FRONTEND_TEST_PROD_BASE;
papelito_assert( 'producao nao e teste', false, papelito_frontend_request_is_test() );
$_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] = 'https://atacante.example';
papelito_assert( 'dominio fora da allowlist e ignorado', '', papelito_frontend_request_base() );
unset( $_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] );
papelito_assert( 'sem cabecalho nao ha dominio nem teste', array( '', false ), array( papelito_frontend_request_base(), papelito_frontend_request_is_test() ) );
papelito_test_env( array( 'PAPELITO_ALLOWED_ORIGINS' => FRONTEND_TEST_PROD_BASE, 'PAPELITO_TEST_ORIGINS' => FRONTEND_TEST_VERCEL_BASE ) );
$_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] = FRONTEND_TEST_VERCEL_BASE;
papelito_assert( 'origem de teste fora da allowlist nao vale', false, papelito_frontend_request_is_test() );
unset( $_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] );

/* --- caso 12: a constante tem precedencia (define fica por ultimo: nao da para redefinir) --- */
define( 'PAPELITO_FRONTEND_URL', FRONTEND_TEST_PROD_BASE );
papelito_test_env( array( 'PAPELITO_FRONTEND_URL' => FRONTEND_TEST_LOCAL_BASE ), 'production' );
papelito_assert( 'constante vence a env', FRONTEND_TEST_PROD_BASE, papelito_frontend_base_url() );

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( $failures === 0 ? 0 : 1 );
