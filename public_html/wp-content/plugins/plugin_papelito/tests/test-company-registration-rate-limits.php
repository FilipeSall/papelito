<?php
/**
 * Regressões comportamentais das cotas do cadastro empresarial atrás do proxy Next.
 *
 * Executa callbacks REST, limitadores e serviços reais; somente WordPress, HTTP e a leitura
 * de candidaturas usam fronteiras em memória. Não cobre concorrência de transients nem SQL.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
const REGISTRATION_LIMIT_TEST_EMAIL          = 'cadastro@example.test';
const REGISTRATION_LIMIT_TEST_CNPJ           = '11222333000181';
const REGISTRATION_LIMIT_TEST_MASKED_CNPJ    = '11.222.333/0001-81';
const REGISTRATION_LIMIT_TEST_OTHER_CNPJ     = '12345678000195';
const REGISTRATION_LIMIT_TEST_TOKEN          = 'application-one';
const REGISTRATION_LIMIT_TEST_OTHER_TOKEN    = 'application-two';
const REGISTRATION_LIMIT_TEST_DOCUMENT_ROUTE = '/company-applications/current/document';
const REGISTRATION_LIMIT_TEST_CURRENT_ROUTE  = '/company-applications/current';
const REGISTRATION_LIMIT_TEST_VALIDATE_ROUTE = '/companies/validate-cnpj';

$GLOBALS['registration_limit_test'] = array(
	'actions'     => array(),
	'routes'      => array(),
	'transients'  => array(),
	'expirations' => array(),
	'writes'      => true,
	'http_calls'  => 0,
	'failures'    => 0,
	'assertions'  => 0,
);

require_once __DIR__ . '/support/company_registration_rate_limits/class-wp-error.php';
require_once __DIR__ . '/support/company_registration_rate_limits/class-wp-rest-response.php';
require_once __DIR__ . '/support/company_registration_rate_limits/class-wp-rest-request.php';
require_once __DIR__ . '/support/company_registration_rate_limits/class-registrationlimittestwpdb.php';

/** Reconhece o erro que o dispatcher WordPress serializaria. */
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}
/** Higieniza as fixtures textuais recebidas pelos callbacks. */
function sanitize_text_field( mixed $value ): string {
	return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) ?? '' );
}
/** Preserva a caixa do core; normalização pertence ao helper real. */
function sanitize_email( mixed $value ): string {
	return (string) filter_var( trim( (string) $value ), FILTER_SANITIZE_EMAIL );
}
/** Replica as chaves simples aceitas pelas fronteiras WordPress. */
function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ?? '' );
}
/** Valida o formato das fixtures de e-mail. */
function is_email( mixed $value ): string|false {
	return filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}
/** Remove o escape aplicado pela fronteira de entrada WordPress. */
function wp_unslash( mixed $value ): mixed {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
/** Todos os cenários representam candidaturas sem usuário autenticado. */
function get_current_user_id(): int {
	return 0;
}
/** Permissão pública declarada pelas rotas reais. */
function __return_true(): bool { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.FunctionDoubleUnderscore, WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore -- Nome da função pública do WordPress usado pelo permission_callback.
	return true;
}
/** Escapa a saída textual do relatório standalone. */
function esc_html( mixed $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
/** Registra filtros do bootstrap sem executar integrações fora deste escopo. */
function add_filter( mixed ...$args ): bool {
	$GLOBALS['registration_limit_test']['filters'][] = $args;
	return true;
}
/** Registra a fronteira de filtros, sem alterar o valor padrão do pipeline real. */
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$GLOBALS['registration_limit_test']['filter_calls'][] = array( $hook, $args );
	return $value;
}
/** Lê transients respeitando o prazo solicitado pelo limitador real. */
function get_transient( string $key ): mixed {
	if ( ( $GLOBALS['registration_limit_test']['expirations'][ $key ] ?? PHP_INT_MAX ) <= time() ) {
		return false;
	}
	return $GLOBALS['registration_limit_test']['transients'][ $key ] ?? false;
}
/** Armazena o valor e sua expiração sem implementar a regra de cota. */
function set_transient( string $key, mixed $value, int $expiration ): bool {
	$GLOBALS['registration_limit_test']['transients'][ $key ]  = $value;
	$GLOBALS['registration_limit_test']['expirations'][ $key ] = $expiration > 0 ? time() + $expiration : PHP_INT_MAX;
	return true;
}
/** Ambiente descartável: altera somente o gate necessário ao cenário. */
function papelito_env_bool( string $key, bool $fallback = false ): bool {
	return 'PAPELITO_B2B_COMPANY_WRITES_ENABLED' === $key ? $GLOBALS['registration_limit_test']['writes'] : $fallback;
}
/** Mantém provedores sem credenciais e registra a configuração consultada. */
function papelito_env( string $key, mixed $fallback = null ): mixed {
	$GLOBALS['registration_limit_test']['env_reads'][] = $key;
	return $fallback;
}
/** Captura o bootstrap REST e os metadados de seus hooks. */
function add_action( string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['registration_limit_test']['actions'][ $hook ][]         = $callback;
	$GLOBALS['registration_limit_test']['action_metadata'][ $hook ][] = array( $priority, $accepted_args );
	return true;
}
/** Guarda os callbacks declarados em produção para despacho pelo teste. */
function register_rest_route( string $rest_namespace, string $route, array $args ): bool {
	$GLOBALS['registration_limit_test']['routes'][ $route ]     = $args;
	$GLOBALS['registration_limit_test']['namespaces'][ $route ] = $rest_namespace;
	return true;
}
/** Fronteira HTTP indisponível, observável e sem acesso à rede. */
function wp_remote_get( string $url, array $args = array() ): WP_Error {
	++$GLOBALS['registration_limit_test']['http_calls'];
	$GLOBALS['registration_limit_test']['http_requests'][] = array( $url, $args );
	return new WP_Error( 'http_request_failed', 'Fronteira HTTP indisponível para esta fixture.' );
}

$GLOBALS['wpdb'] = new RegistrationLimitTestWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fronteira de banco descartável do teste standalone.

require_once dirname( __DIR__ ) . '/includes/support.php';
require_once dirname( __DIR__ ) . '/includes/auth_endpoints.php';
require_once dirname( __DIR__ ) . '/includes/company_flags.php';
require_once dirname( __DIR__ ) . '/includes/cnpj_validation.php';
require_once dirname( __DIR__ ) . '/includes/cnpj_providers.php';
require_once dirname( __DIR__ ) . '/includes/customer_identity.php';
require_once dirname( __DIR__ ) . '/includes/company_schema.php';
require_once dirname( __DIR__ ) . '/includes/company_services.php';
require_once dirname( __DIR__ ) . '/includes/company_pre_account_applications.php';
require_once dirname( __DIR__ ) . '/includes/company_endpoints.php';

foreach ( $GLOBALS['registration_limit_test']['actions']['rest_api_init'] as $register_routes ) {
	$register_routes();
}

/**
 * Executa a rota pública registrada, incluindo seu permission_callback.
 *
 * @throws RuntimeException Se a rota recusar a permissão pública esperada pela fixture.
 */
function registration_limit_test_request( string $route, array $body = array(), string $token = '' ): WP_Error|WP_REST_Response {
	$definition = $GLOBALS['registration_limit_test']['routes'][ $route ];
	$request    = new WP_REST_Request( $body, array( 'X-Papelito-Application-Token' => $token ) );
	$permission = $definition['permission_callback'];
	if ( true !== $permission( $request ) ) {
		throw new RuntimeException( 'Fixture não autorizada pelo permission_callback real.' );
	}
	return $definition['callback']( $request );
}

/** Compara valores sem interromper os demais cenários de regressão. */
function registration_limit_test_same( string $label, mixed $expected, mixed $actual ): void {
	++$GLOBALS['registration_limit_test']['assertions'];
	if ( $expected !== $actual ) {
		++$GLOBALS['registration_limit_test']['failures'];
		echo esc_html( 'FAIL: ' . $label . ' esperado=' . (string) $expected . ' recebido=' . (string) $actual ) . "\n";
	}
}

/** Lê o status preservando o contrato WP_Error usado pelo dispatcher REST. */
function registration_limit_test_status( WP_Error|WP_REST_Response $response ): int {
	return $response instanceof WP_Error ? (int) ( $response->get_error_data()['status'] ?? 500 ) : $response->get_status();
}

/** Limpa as fronteiras para que a cota de um cenário nunca contamine outro. */
function registration_limit_test_reset(): void {
	$GLOBALS['registration_limit_test']['transients']  = array();
	$GLOBALS['registration_limit_test']['expirations'] = array();
	$GLOBALS['registration_limit_test']['writes']      = true;
	$GLOBALS['registration_limit_test']['http_calls']  = 0;
	$GLOBALS['wpdb']->reads                            = 0;
	$_SERVER['REMOTE_ADDR']                            = '192.0.2.10';
	$_FILES = array( 'document' => array( 'name' => 'document.pdf' ) );
}

registration_limit_test_reset();
$body = array(
	'email' => REGISTRATION_LIMIT_TEST_EMAIL,
	'cnpj'  => REGISTRATION_LIMIT_TEST_CNPJ,
);
for ( $attempt = 0; $attempt < 5; ++$attempt ) {
	registration_limit_test_same( 'candidatura chega à validação real antes da cota', 422, registration_limit_test_status( registration_limit_test_request( '/company-applications', $body ) ) );
}
registration_limit_test_same( 'sexta candidatura recebe 429', 429, registration_limit_test_status( registration_limit_test_request( '/company-applications', $body ) ) );
$other_body = array(
	'email' => 'outro@example.test',
	'cnpj'  => REGISTRATION_LIMIT_TEST_OTHER_CNPJ,
);
registration_limit_test_same( 'outra pessoa atrás do mesmo proxy mantém sua cota', 422, registration_limit_test_status( registration_limit_test_request( '/company-applications', $other_body ) ) );

registration_limit_test_reset();
for ( $attempt = 0; $attempt < 5; ++$attempt ) {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( $attempt + 1 );
	$body['email']          = 'rotacao' . $attempt . '@example.test';
	registration_limit_test_request( '/company-applications', $body );
}
$body['email'] = REGISTRATION_LIMIT_TEST_EMAIL;
$body['cnpj']  = REGISTRATION_LIMIT_TEST_MASKED_CNPJ;
registration_limit_test_same( 'trocar e-mail e máscara não reinicia a cota do CNPJ', 429, registration_limit_test_status( registration_limit_test_request( '/company-applications', $body ) ) );

registration_limit_test_reset();
for ( $attempt = 0; $attempt < 5; ++$attempt ) {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( $attempt + 1 );
	$body['cnpj']           = 'documento-' . $attempt;
	registration_limit_test_request( '/company-applications', $body );
}
$body['email'] = ' CADASTRO@EXAMPLE.TEST ';
$body['cnpj']  = REGISTRATION_LIMIT_TEST_OTHER_CNPJ;
registration_limit_test_same( 'normalização do e-mail impede contornar a cota trocando CNPJ', 429, registration_limit_test_status( registration_limit_test_request( '/company-applications', $body ) ) );

foreach ( array(
	REGISTRATION_LIMIT_TEST_CURRENT_ROUTE  => 60,
	REGISTRATION_LIMIT_TEST_DOCUMENT_ROUTE => 10,
) as $route => $quota ) {
	registration_limit_test_reset();
	for ( $attempt = 0; $attempt < $quota; ++$attempt ) {
		registration_limit_test_request( $route, array(), REGISTRATION_LIMIT_TEST_TOKEN );
	}
	$reads = $GLOBALS['wpdb']->reads;
	registration_limit_test_same( $route . ' bloqueia token esgotado', 429, registration_limit_test_status( registration_limit_test_request( $route, array(), REGISTRATION_LIMIT_TEST_TOKEN ) ) );
	registration_limit_test_same( $route . ' bloqueia antes de ler candidatura', $reads, $GLOBALS['wpdb']->reads );
	$expected_status = REGISTRATION_LIMIT_TEST_CURRENT_ROUTE === $route ? 200 : 409;
	registration_limit_test_same( $route . ' isola outro token no mesmo proxy', $expected_status, registration_limit_test_status( registration_limit_test_request( $route, array(), REGISTRATION_LIMIT_TEST_OTHER_TOKEN ) ) );
	registration_limit_test_same( $route . ' não autentica token desconhecido', 404, registration_limit_test_status( registration_limit_test_request( $route, array(), 'unknown-token' ) ) );
	registration_limit_test_same( $route . ' não autentica token ausente', 404, registration_limit_test_status( registration_limit_test_request( $route ) ) );
}

registration_limit_test_reset();
$body = array( 'cnpj' => REGISTRATION_LIMIT_TEST_CNPJ );
$GLOBALS['registration_limit_test']['writes'] = false;
registration_limit_test_same( 'consulta respeita gate de escritas empresariais', 503, registration_limit_test_status( registration_limit_test_request( REGISTRATION_LIMIT_TEST_VALIDATE_ROUTE, $body ) ) );
registration_limit_test_same( 'gate bloqueia antes de chamar provedor', 0, $GLOBALS['registration_limit_test']['http_calls'] );

registration_limit_test_reset();
for ( $attempt = 0; $attempt < 10; ++$attempt ) {
	unset( $GLOBALS['registration_limit_test']['transients'][ PAPELITO_CNPJ_CACHE_PREFIX . REGISTRATION_LIMIT_TEST_CNPJ ] );
	registration_limit_test_same( 'consulta permitida responde pelo serviço real', 200, registration_limit_test_status( registration_limit_test_request( REGISTRATION_LIMIT_TEST_VALIDATE_ROUTE, $body ) ) );
}
$http_calls = $GLOBALS['registration_limit_test']['http_calls'];
unset( $GLOBALS['registration_limit_test']['transients'][ PAPELITO_CNPJ_CACHE_PREFIX . REGISTRATION_LIMIT_TEST_CNPJ ] );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
$body['cnpj']                    = REGISTRATION_LIMIT_TEST_MASKED_CNPJ;
$limited                         = registration_limit_test_request( REGISTRATION_LIMIT_TEST_VALIDATE_ROUTE, $body );
registration_limit_test_same( 'consulta limita CNPJ canônico mesmo com cabeçalho de IP alterado', 429, registration_limit_test_status( $limited ) );
registration_limit_test_same( '429 preserva código REST padrão', 'papelito_rate_limited', $limited instanceof WP_Error ? $limited->get_error_code() : '' );
registration_limit_test_same( 'consulta bloqueada não chama provedor', $http_calls, $GLOBALS['registration_limit_test']['http_calls'] );
registration_limit_test_same( 'outro CNPJ mantém sua quota atrás do proxy', 200, registration_limit_test_status( registration_limit_test_request( REGISTRATION_LIMIT_TEST_VALIDATE_ROUTE, array( 'cnpj' => REGISTRATION_LIMIT_TEST_OTHER_CNPJ ) ) ) );
registration_limit_test_same( 'CNPJ inválido continua recusado', 422, registration_limit_test_status( registration_limit_test_request( REGISTRATION_LIMIT_TEST_VALIDATE_ROUTE, array( 'cnpj' => 'invalid' ) ) ) );

registration_limit_test_reset();
echo esc_html( 'RESULT: ' . $GLOBALS['registration_limit_test']['assertions'] . ' assertions, ' . $GLOBALS['registration_limit_test']['failures'] . ' failures' ) . "\n";
exit( $GLOBALS['registration_limit_test']['failures'] > 0 ? 1 : 0 );
