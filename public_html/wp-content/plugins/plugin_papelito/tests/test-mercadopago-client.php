<?php
/**
 * Cliente HTTP do Mercado Pago: configuração, host fixo, categorias de erro e redaction.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

$GLOBALS['mp_test_env']      = array();
$GLOBALS['mp_test_requests'] = array();
$GLOBALS['mp_test_response'] = null;
$GLOBALS['mp_test_wp_env']   = 'local';
$GLOBALS['mp_test_log_file'] = tempnam( sys_get_temp_dir(), 'mpc' );
ini_set( 'error_log', $GLOBALS['mp_test_log_file'] );
ini_set( 'display_errors', 'stderr' );

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_filter( mixed ...$args ): bool { return true; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_get_environment_type(): string { return $GLOBALS['mp_test_wp_env']; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function add_query_arg( array $args, string $url ): string { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['mp_test_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' === $hook ) {
		$GLOBALS['mp_test_requests'][] = array( 'method' => $args[0], 'url' => $args[1], 'args' => $args[2] );
		return $GLOBALS['mp_test_response'];
	}
	return $value;
}
function wp_remote_request( string $url, array $args ): mixed { throw new RuntimeException( 'rede real proibida no teste' ); }
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }

require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';

const MP_TEST_TOKEN  = 'APP_USR-1234567890-101612-abcdef0123456789abcdef0123456789-555';
const MP_TEST_SECRET = 'segredo-do-cliente-xyz';
const MP_TEST_CPF    = '529.982.247-25';

$failures = 0;

function mp_client_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function mp_response( int $code, mixed $body ): array {
	return array( 'response' => array( 'code' => $code ), 'body' => is_string( $body ) ? $body : json_encode( $body ) );
}

function mp_category( mixed $result ): string {
	return is_wp_error( $result ) ? (string) ( $result->get_error_data()['category'] ?? '' ) : 'ok';
}

$config_error = papelito_mercadopago_config_error();
mp_client_assert( 'sem configuração o Mercado Pago fica indisponível', is_wp_error( $config_error ) && 'configuration' === $config_error->get_error_data()['category'] );
mp_client_assert( 'sem configuração não está configurado', ! papelito_mercadopago_is_configured() );

$GLOBALS['mp_test_env'] = array(
	'MERCADOPAGO_CLIENT_ID'      => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET'  => MP_TEST_SECRET,
	'MERCADOPAGO_WEBHOOK_SECRET' => 'whsec',
	'MERCADOPAGO_REDIRECT_URI'   => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'    => 'test',
);
mp_client_assert( 'configuração completa em teste no ambiente local é válida', null === papelito_mercadopago_config_error() );
mp_client_assert( 'client_id e redirect saem da configuração', '4428161262826515' === papelito_mercadopago_config()['client_id'] );

$GLOBALS['mp_test_env']['MERCADOPAGO_ENVIRONMENT'] = 'sandbox';
mp_client_assert( 'ambiente fora de test/live é recusado', is_wp_error( papelito_mercadopago_config_error() ) );
$GLOBALS['mp_test_env']['MERCADOPAGO_ENVIRONMENT'] = 'live';
mp_client_assert( 'live em ambiente local é aceito, porque usuário de teste do MP usa credencial de produção', null === papelito_mercadopago_config_error() );
$GLOBALS['mp_test_wp_env'] = 'production';
mp_client_assert( 'live em produção é válido', null === papelito_mercadopago_config_error() );
$GLOBALS['mp_test_env']['MERCADOPAGO_ENVIRONMENT'] = 'test';
mp_client_assert( 'test em produção é incompatível', is_wp_error( papelito_mercadopago_config_error() ) );
$GLOBALS['mp_test_wp_env'] = 'local';

$GLOBALS['mp_test_env']['MERCADOPAGO_REDIRECT_URI'] = 'http://evil.example/callback';
mp_client_assert( 'redirect URI sem HTTPS é recusada', is_wp_error( papelito_mercadopago_config_error() ) );
$GLOBALS['mp_test_env']['MERCADOPAGO_REDIRECT_URI'] = 'https://papelito.example/api/vendor/mercadopago/callback';

$no_token = papelito_mercadopago_request( 'GET', '/v1/payments/1', array() );
mp_client_assert( 'chamada sem token do vendor não busca token global', 'configuration' === mp_category( $no_token ) && array() === $GLOBALS['mp_test_requests'] );

$GLOBALS['mp_test_response'] = mp_response( 200, array( 'id' => 1, 'status' => 'approved' ) );
$ok = papelito_mercadopago_request( 'GET', '/v1/payments/1', array( 'token' => MP_TEST_TOKEN ) );
$sent = end( $GLOBALS['mp_test_requests'] );
mp_client_assert( 'resposta válida é devolvida decodificada', is_array( $ok ) && 'approved' === $ok['status'] );
mp_client_assert( 'URL usa o host oficial fixo', 'https://api.mercadopago.com/v1/payments/1' === $sent['url'] );
mp_client_assert( 'token vai no Authorization Bearer', 'Bearer ' . MP_TEST_TOKEN === $sent['args']['headers']['Authorization'] );
mp_client_assert( 'redirecionamento não é seguido', 0 === $sent['args']['redirection'] );
mp_client_assert( 'resposta tem limite de tamanho', ( $sent['args']['limit_response_size'] ?? 0 ) > 0 );

$GLOBALS['mp_test_requests'] = array();
foreach ( array( '//evil.example/v1/payments', 'https://evil.example/v1', '/v1/../../x', '/v1/payments?x=@evil' ) as $bad_path ) {
	$bad = papelito_mercadopago_request( 'GET', $bad_path, array( 'token' => MP_TEST_TOKEN ) );
	mp_client_assert( "caminho {$bad_path} é recusado sem request", 'configuration' === mp_category( $bad ) );
}
mp_client_assert( 'nenhum caminho inválido chegou ao transporte', array() === $GLOBALS['mp_test_requests'] );

papelito_mercadopago_request( 'POST', '/v1/payments/1/refunds', array( 'token' => MP_TEST_TOKEN, 'body' => array(), 'idempotency_key' => 'refund-key-1' ) );
$sent = end( $GLOBALS['mp_test_requests'] );
mp_client_assert( 'chave de idempotência vai no header documentado', 'refund-key-1' === $sent['args']['headers']['X-Idempotency-Key'] );

$GLOBALS['mp_test_response'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' );
mp_client_assert( 'timeout em POST é resultado desconhecido', 'unknown_outcome' === mp_category( papelito_mercadopago_request( 'POST', '/checkout/preferences', array( 'token' => MP_TEST_TOKEN, 'body' => array() ) ) ) );
mp_client_assert( 'timeout em GET é indisponibilidade', 'unavailable' === mp_category( papelito_mercadopago_request( 'GET', '/v1/payments/1', array( 'token' => MP_TEST_TOKEN ) ) ) );
$GLOBALS['mp_test_response'] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to api.mercadopago.com port 443' );
mp_client_assert( 'falha de conexão comprovadamente antes do envio é indisponibilidade', 'unavailable' === mp_category( papelito_mercadopago_request( 'POST', '/checkout/preferences', array( 'token' => MP_TEST_TOKEN, 'body' => array() ) ) ) );

$GLOBALS['mp_test_response'] = mp_response( 502, 'Bad Gateway' );
mp_client_assert( '5xx em POST é resultado desconhecido', 'unknown_outcome' === mp_category( papelito_mercadopago_request( 'POST', '/checkout/preferences', array( 'token' => MP_TEST_TOKEN, 'body' => array() ) ) ) );
mp_client_assert( '5xx em GET é indisponibilidade', 'unavailable' === mp_category( papelito_mercadopago_request( 'GET', '/v1/payments/1', array( 'token' => MP_TEST_TOKEN ) ) ) );

$GLOBALS['mp_test_response'] = mp_response( 200, 'not-json' );
mp_client_assert( 'JSON inválido em POST é resultado desconhecido', 'unknown_outcome' === mp_category( papelito_mercadopago_request( 'POST', '/checkout/preferences', array( 'token' => MP_TEST_TOKEN, 'body' => array() ) ) ) );

$expectations = array(
	401 => 'authentication',
	403 => 'authorization',
	429 => 'rate_limit',
	400 => 'validation',
);
foreach ( $expectations as $status => $category ) {
	$GLOBALS['mp_test_response'] = mp_response( $status, array( 'message' => 'erro', 'error' => 'bad_request' ) );
	mp_client_assert( "HTTP {$status} vira {$category}", $category === mp_category( papelito_mercadopago_request( 'POST', '/checkout/preferences', array( 'token' => MP_TEST_TOKEN, 'body' => array() ) ) ) );
}

$GLOBALS['mp_test_response'] = mp_response( 400, array( 'message' => 'collector_does_not_comply_with_current_regulation', 'error' => 'collector_does_not_comply_with_current_regulation' ) );
$restricted = papelito_mercadopago_request( 'POST', '/checkout/preferences', array( 'token' => MP_TEST_TOKEN, 'body' => array() ) );
mp_client_assert( 'pendência regulatória do vendedor é restrição de negócio', 'business_restriction' === mp_category( $restricted ) );
mp_client_assert( 'código remoto é preservado sem o corpo', 'collector_does_not_comply_with_current_regulation' === $restricted->get_error_data()['remote_code'] && ! isset( $restricted->get_error_data()['body'] ) );

$GLOBALS['mp_test_response'] = mp_response( 400, array( 'message' => 'invalid token ' . MP_TEST_TOKEN . ' for cpf ' . MP_TEST_CPF . ' Bearer ' . MP_TEST_TOKEN, 'error' => 'bad_request' ) );
$leaky = papelito_mercadopago_request( 'POST', '/checkout/preferences', array( 'token' => MP_TEST_TOKEN, 'body' => array( 'payer' => array( 'identification' => array( 'number' => MP_TEST_CPF ) ) ) ) );
$exposed = $leaky->get_error_message() . json_encode( $leaky->get_error_data() );
mp_client_assert( 'erro não vaza token', false === strpos( $exposed, 'abcdef0123456789' ) );
mp_client_assert( 'erro não vaza CPF', false === strpos( $exposed, '529.982.247-25' ) && false === strpos( $exposed, '52998224725' ) );

$GLOBALS['mp_test_response'] = mp_response( 200, array( 'access_token' => MP_TEST_TOKEN ) );
papelito_mercadopago_oauth_token_request( array( 'grant_type' => 'authorization_code', 'code' => 'TG-code' ) );
$sent = end( $GLOBALS['mp_test_requests'] );
mp_client_assert( 'troca OAuth vai ao endpoint oficial sem Bearer', 'https://api.mercadopago.com/oauth/token' === $sent['url'] && ! isset( $sent['args']['headers']['Authorization'] ) );
mp_client_assert( 'troca OAuth leva client_id e secret da configuração', false !== strpos( (string) $sent['args']['body'], MP_TEST_SECRET ) && false !== strpos( (string) $sent['args']['body'], '4428161262826515' ) );

$log = (string) file_get_contents( $GLOBALS['mp_test_log_file'] );
mp_client_assert( 'log não contém token, secret nem CPF', false === strpos( $log, 'abcdef0123456789' ) && false === strpos( $log, MP_TEST_SECRET ) && false === strpos( $log, '247-25' ) );
unlink( $GLOBALS['mp_test_log_file'] );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
