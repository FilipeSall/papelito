<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private string $code;
	private string $message;
	private array $data;

	/**
 * Cria um fixture tipado de erro do WordPress.
	 *
 * @param string              $code Código do erro.
 * @param string              $message Mensagem do erro.
 * @param array<string,mixed> $data Dados do erro.
	 */
	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/**
 * Retorna o código do erro fixture.
	 *
 * @return string Código do erro.
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
 * Retorna a mensagem do erro fixture.
	 *
 * @return string Mensagem do erro.
	 */
	public function get_error_message(): string {
		return $this->message;
	}

	/**
 * Retorna os dados do erro fixture.
	 *
 * @return array<string,mixed> Dados do erro.
	 */
	public function get_error_data(): array {
		return $this->data;
	}
}

/** Identifica um fixture de erro do WordPress. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitiza uma string sintética do provider. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Normaliza uma chave sintética do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Codifica um payload sintético do WordPress. */
function wp_json_encode( mixed $value ): string { return (string) json_encode( $value ); }
/** Devolve o salt sintético usado pelas chaves HMAC. */
function wp_salt( string $scheme = 'auth' ): string { return 'salt-de-http-sintetico-' . $scheme; }
/** Stub do registro de uma action não relacionada do WordPress. */
function add_action( mixed ...$args ): bool { return true; }
/** Stub de observabilidade sem coletor para o teste HTTP. */
function do_action( mixed ...$args ): bool { return true; }
/** Stub do registro de uma rota não relacionada do WordPress. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Transient vazio para impedir persistência no teste HTTP. */
function get_transient( string $key ): false { return false; }
/** Escrita de transient inerte para o teste HTTP. */
function set_transient( string $key, mixed $value, int $expiration ): bool { return true; }
/** Lê o status de uma resposta HTTP sintética. */
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['status'] ?? 0 ); }
/** Lê o corpo de uma resposta HTTP sintética. */
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }

require_once dirname( __DIR__ ) . '/includes/shipping.php';
require_once dirname( __DIR__ ) . '/includes/braspress.php';
require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';
require_once dirname( __DIR__ ) . '/includes/braspress_tracking.php';

/** Indica se a integração sintética tem configuração. */
function papelito_vendor_integration_config_complete( array $config ): bool { return ! empty( $config ); }
/** Normaliza um documento sintético de fixture. */
function papelito_vendor_integration_normalize_document( mixed $value ): string { return preg_replace( '/\D+/', '', (string) $value ); }
/** Normaliza um CEP sintético de fixture. */
function papelito_vendor_integration_normalize_cep( mixed $value ): string { return preg_replace( '/\D+/', '', (string) $value ); }

const PAPELITO_VENDOR_INTEGRATION_ACTIVE  = 'active';
const PAPELITO_VENDOR_INTEGRATION_INVALID = 'invalid_credentials';
const PAPELITO_VENDOR_INTEGRATION_BLOCKED = 'provider_blocked';

/** Registra transições de saúde sintéticas para verificar a separação de domínio. */
function papelito_vendor_integration_set_braspress_operational_state( int $vendor_id, string $status, string $error_category = '' ): void {
	$GLOBALS['braspress_http_states'][] = array(
		'vendor_id'      => $vendor_id,
		'status'         => $status,
		'error_category' => $error_category,
	);
}

const BRASPRESS_TEST_USERNAME       = 'usuario-sintetico';
const BRASPRESS_TEST_PASSWORD       = 'senha-sintetica-nao-real';
const BRASPRESS_TEST_SENDER_CNPJ    = '12345678000195';
const BRASPRESS_TEST_RECIPIENT_CNPJ = '12345678000199';
const BRASPRESS_TEST_DESTINATION_CEP = '22041001';
const BRASPRESS_TEST_PRODUCTION_URL  = 'https://api.braspress.com/';
const BRASPRESS_TEST_QUOTE_PATH      = 'v1/cotacao/calcular/json';

$failures = 0;
/** Confere um comportamento do cliente Braspress standalone. */
function braspress_http_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

/** Monta uma integração com credenciais sintéticas e configuração do vendor. */
function braspress_http_fixture_integration(): array {
	return array(
		'vendor_id'  => 2163,
		'credentials' => array(
			'username' => BRASPRESS_TEST_USERNAME,
			'password' => BRASPRESS_TEST_PASSWORD,
		),
		'config' => array(
			'sender_cnpj'           => BRASPRESS_TEST_SENDER_CNPJ,
			'origin_cep'            => '01310930',
			'modal'                 => 'R',
			'freight_type'          => '1',
			'tracking_tomador_cnpj' => BRASPRESS_TEST_SENDER_CNPJ,
		),
	);
}

/** Captura uma chamada HTTP e devolve a resposta sintética corrente. */
function braspress_http_executor( string $url, array $args ): mixed {
	$GLOBALS['braspress_http_calls'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['braspress_http_response'];
}

/** Direciona o executor padrão do WordPress ao transporte sintético. */
function wp_remote_request( string $url, array $args ): mixed {
	return braspress_http_executor( $url, $args );
}

/** Retorna a última chamada HTTP capturada. */
function braspress_http_last_call(): array {
	$calls = $GLOBALS['braspress_http_calls'];
	return $calls[ count( $calls ) - 1 ] ?? array();
}

/** Reinicia o executor falso e escolhe a próxima resposta. */
function braspress_http_set_response( mixed $response ): void {
	$GLOBALS['braspress_http_response'] = $response;
	$GLOBALS['braspress_http_calls']    = array();
}

/** Confere se o recorder de estado recebeu uma transição específica. */
function braspress_http_has_state( string $status ): bool {
	foreach ( $GLOBALS['braspress_http_states'] ?? array() as $state ) {
		if ( $status === ( $state['status'] ?? '' ) ) {
			return true;
		}
	}

	return false;
}

/** Monta o menor pacote sintético de cotação válido. */
function braspress_http_quote_package(): array {
	return array(
		'weight_kg' => 2.5,
		'volumes'   => 1,
		'cubagem'   => array(
			array( 'length_m' => 0.3, 'width_m' => 0.2, 'height_m' => 0.1, 'volumes' => 1 ),
		),
	);
}

$integration = braspress_http_fixture_integration();

putenv( 'BRASPRESS_BASE_URL' );
braspress_http_set_response( array( 'status' => 200, 'body' => '{"id":"Q-1","totalFrete":12.5,"prazo":3}' ) );
$success = papelito_braspress_http_request( $integration, 'POST', BRASPRESS_TEST_QUOTE_PATH, '{}', 'braspress_http_executor' );
$call    = braspress_http_last_call();
braspress_http_assert( 'base ausente usa produção', is_array( $success ) && BRASPRESS_TEST_PRODUCTION_URL . BRASPRESS_TEST_QUOTE_PATH === $call['url'] );

foreach ( array( BRASPRESS_TEST_PRODUCTION_URL, rtrim( BRASPRESS_TEST_PRODUCTION_URL, '/' ) ) as $base_url ) {
	putenv( 'BRASPRESS_BASE_URL=' . $base_url );
	braspress_http_set_response( array( 'status' => 200, 'body' => '{"id":"Q-1","totalFrete":12.5,"prazo":3}' ) );
	$result = papelito_braspress_http_request( $integration, 'POST', BRASPRESS_TEST_QUOTE_PATH, '{}', 'braspress_http_executor' );
	$call   = braspress_http_last_call();
	$expected_base = rtrim( $base_url, '/' ) . '/';
	braspress_http_assert( 'host oficial com ou sem barra é aceito', is_array( $result ) && $expected_base . BRASPRESS_TEST_QUOTE_PATH === $call['url'] );
}

foreach ( array( '', 'http://api.braspress.com/', 'https://hml-api.braspress.com/', 'https://hml-api.braspress.com', 'https://api.braspress.com.evil.test/', 'https://api.braspress.com:443/', 'https://usuario:senha@api.braspress.com/', 'https://127.0.0.1/', 'https://localhost/', 'https://api.braspress.com/v1/' ) as $invalid_base_url ) {
	putenv( 'BRASPRESS_BASE_URL=' . $invalid_base_url );
	braspress_http_set_response( array( 'status' => 200, 'body' => '{}' ) );
	$result = papelito_braspress_http_request( $integration, 'POST', BRASPRESS_TEST_QUOTE_PATH, '{}', 'braspress_http_executor' );
	braspress_http_assert( 'base insegura é configuração inválida', is_wp_error( $result ) && 'papelito_braspress_configuration_error' === $result->get_error_code() && 0 === count( $GLOBALS['braspress_http_calls'] ) );
}

putenv( 'BRASPRESS_BASE_URL=' . BRASPRESS_TEST_PRODUCTION_URL );
braspress_http_set_response( array( 'status' => 200, 'body' => '{"id":"Q-1","totalFrete":12.5,"prazo":3}' ) );
$result = papelito_braspress_http_request( $integration, 'POST', BRASPRESS_TEST_QUOTE_PATH, '{}', 'braspress_http_executor' );
$args   = braspress_http_last_call()['args'];
braspress_http_assert( 'header Basic usa exatamente as credenciais sintéticas', 'Basic ' . base64_encode( BRASPRESS_TEST_USERNAME . ':' . BRASPRESS_TEST_PASSWORD ) === $args['headers']['Authorization'] );
braspress_http_assert( 'transporte recebe timeout, redirection, sslverify e limite', 15 === $args['timeout'] && 0 === $args['redirection'] && true === $args['sslverify'] && defined( 'PAPELITO_BRASPRESS_LIMIT_RESPONSE_SIZE' ) && PAPELITO_BRASPRESS_LIMIT_RESPONSE_SIZE === $args['limit_response_size'] );
$missing_credentials = $integration;
$missing_credentials['credentials'] = array( 'username' => BRASPRESS_TEST_USERNAME );
braspress_http_set_response( array( 'status' => 200, 'body' => '{}' ) );
$result = papelito_braspress_http_request( $missing_credentials, 'POST', BRASPRESS_TEST_QUOTE_PATH, '{}', 'braspress_http_executor' );
braspress_http_assert( 'credencial ausente vira configuration_error sem chamada', is_wp_error( $result ) && 'papelito_braspress_configuration_error' === $result->get_error_code() && 0 === count( $GLOBALS['braspress_http_calls'] ) );

putenv( 'BRASPRESS_BASE_URL=' . BRASPRESS_TEST_PRODUCTION_URL );
$oversized_json = '{"padding":"' . str_repeat( 'x', PAPELITO_BRASPRESS_LIMIT_RESPONSE_SIZE ) . '"}';
$transport_errors = array(
	'papelito_braspress_timeout'      => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
	'papelito_braspress_network_error' => new WP_Error( 'http_request_failed', 'Could not resolve host' ),
);
foreach ( $transport_errors as $expected_code => $response ) {
	braspress_http_set_response( $response );
	$result = papelito_braspress_http_request( $integration, 'POST', BRASPRESS_TEST_QUOTE_PATH, '{}', 'braspress_http_executor' );
	braspress_http_assert( 'falha de transporte é classificada sem retry', is_wp_error( $result ) && $expected_code === $result->get_error_code() && 1 === count( $GLOBALS['braspress_http_calls'] ) );
}

$provider_responses = array(
	'302 vira invalid_response' => array( 302, '' , 'papelito_braspress_invalid_response' ),
	'401 vira provider_4xx com marca de credencial' => array( 401, '{"message":"Unauthorized"}', 'papelito_braspress_provider_4xx' ),
	'403 vira provider_4xx com marca de credencial' => array( 403, '{"message":"Forbidden"}', 'papelito_braspress_provider_4xx' ),
	'429 vira rate_limited' => array( 429, '{"message":"Too many requests"}', 'papelito_braspress_rate_limited' ),
	'400 ProblemDetails vira provider_4xx' => array( 400, '{"status":400,"traceId":"trace-sintetico","errors":{"Cep":["invalido"]}}', 'papelito_braspress_provider_4xx' ),
	'500 com errorList vira provider_5xx' => array( 500, '{"statusCode":500,"errorList":["FALHA SINTETICA"]}', 'papelito_braspress_provider_5xx' ),
	'503 sem corpo vira provider_5xx' => array( 503, '', 'papelito_braspress_provider_5xx' ),
	'200 com JSON inválido vira invalid_response' => array( 200, 'not-json', 'papelito_braspress_invalid_response' ),
	'200 JSON acima do limite vira invalid_response' => array( 200, $oversized_json, 'papelito_braspress_invalid_response' ),
);
foreach ( $provider_responses as $label => $fixture ) {
	braspress_http_set_response( array( 'status' => $fixture[0], 'body' => $fixture[1] ) );
	$result = papelito_braspress_http_request( $integration, 'POST', BRASPRESS_TEST_QUOTE_PATH, '{}', 'braspress_http_executor' );
	braspress_http_assert( $label, is_wp_error( $result ) && $fixture[2] === $result->get_error_code() );
}

$log_path = tempnam( sys_get_temp_dir(), 'braspress-log-' );
ini_set( 'error_log', $log_path );
$provider_body = wp_json_encode(
	array(
		'statusCode' => 500,
		'errorList'  => array(
			'CNPJ ECOADO ' . BRASPRESS_TEST_RECIPIENT_CNPJ,
			'usuario ' . BRASPRESS_TEST_USERNAME . ' senha ' . BRASPRESS_TEST_PASSWORD,
			base64_encode( BRASPRESS_TEST_USERNAME . ':' . BRASPRESS_TEST_PASSWORD ),
		),
	)
);
braspress_http_set_response( array( 'status' => 500, 'body' => $provider_body ) );
$quote = papelito_braspress_quote( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, braspress_http_quote_package(), 12345 );
$log   = (string) file_get_contents( $log_path );
unlink( $log_path );
braspress_http_assert( 'falha do provider é registrada pela camada de domínio', is_wp_error( $quote ) && str_contains( $log, 'provider_status' ) && str_contains( $log, 'operation' ) && str_contains( $log, 'duration_ms' ) );
braspress_http_assert( 'log nunca contém segredo, header ou base64', ! str_contains( $log, BRASPRESS_TEST_PASSWORD ) && ! str_contains( $log, BRASPRESS_TEST_USERNAME ) && ! str_contains( $log, 'Authorization' ) && ! str_contains( $log, base64_encode( BRASPRESS_TEST_USERNAME . ':' . BRASPRESS_TEST_PASSWORD ) ) );
braspress_http_assert( 'CNPJ ecoado pelo provider é mascarado no log', ! str_contains( $log, BRASPRESS_TEST_RECIPIENT_CNPJ ) && str_contains( $log, '[redacted]' ) );

braspress_http_set_response( array( 'status' => 200, 'body' => '{"conhecimentos":[{"statusTransporte":"EM TRANSITO"}]}' ) );
$GLOBALS['braspress_http_states'] = array();
$tracking = papelito_braspress_tracking_by_order( $integration, 'PED-2026/001' );
$tracking_call = braspress_http_last_call();
braspress_http_assert( 'tracking usa o mesmo transporte seguro e deriva o tomador do vendor', is_array( $tracking ) && 'GET' === $tracking_call['args']['method'] && str_contains( $tracking_call['url'], '/v3/tracking/byNumPedido/' . BRASPRESS_TEST_SENDER_CNPJ . '/PED-2026%2F001/json' ) );
braspress_http_assert( 'tracking bem-sucedido não ativa a integração', ! braspress_http_has_state( PAPELITO_VENDOR_INTEGRATION_ACTIVE ) );

braspress_http_set_response( array( 'status' => 200, 'body' => '{"conhecimentos":[]}' ) );
$GLOBALS['braspress_http_states'] = array();
$empty_tracking = papelito_braspress_tracking_by_order( $integration, 'PED-2026/002' );
braspress_http_assert( 'tracking vazio não ativa a integração', is_array( $empty_tracking ) && ! braspress_http_has_state( PAPELITO_VENDOR_INTEGRATION_ACTIVE ) );

braspress_http_set_response( array( 'status' => 200, 'body' => '{"id":"Q-2","totalFrete":12.5,"prazo":3}' ) );
$clocked_quote = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, braspress_http_quote_package(), 12345, new DateTimeImmutable( '2026-09-14 23:50:00', new DateTimeZone( 'America/Sao_Paulo' ) ) );
braspress_http_assert( 'relógio injetado controla validade sem alterar quote pública', is_array( $clocked_quote ) && '2026-09-15T02:59:59.999+00:00' === $clocked_quote['expires_at'] );
braspress_http_assert( 'somente a cotação válida ativa a integração', braspress_http_has_state( PAPELITO_VENDOR_INTEGRATION_ACTIVE ) );

foreach ( array( 'configuration_error', 'timeout', 'network_error', 'rate_limited', 'provider_4xx', 'provider_5xx', 'invalid_response' ) as $category ) {
	braspress_http_assert( 'categoria fechada reconhece ' . $category, $category === papelito_shipping_failure_category( 'braspress', 'papelito_braspress_' . $category ) );
}

foreach (
	array(
		'papelito_braspress_credentials_invalid' => 'provider_4xx',
		'papelito_braspress_account_blocked'     => 'provider_4xx',
		'papelito_braspress_route_not_available' => 'validation_error',
	) as $code => $category
) {
	braspress_http_assert( 'categoria de domínio reconhece ' . $code, $category === papelito_shipping_failure_category( 'braspress', $code ) );
}

putenv( 'BRASPRESS_BASE_URL' );
echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
