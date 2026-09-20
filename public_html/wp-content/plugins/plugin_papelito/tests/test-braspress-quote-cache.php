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

/** Codifica um payload sintético do WordPress. */
function wp_json_encode( mixed $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }

/** Devolve a chave HMAC estável somente para o teste local. */
function wp_salt( string $scheme = 'auth' ): string { return BRASPRESS_TEST_SALT . $scheme; }

/** Registra a action de observabilidade sem instalar um coletor de produção. */
function do_action( mixed ...$args ): bool {
	$GLOBALS['braspress_cache_actions'][] = array(
		'hook' => $args[0] ?? '',
		'args' => array_slice( $args, 1 ),
	);

	return true;
}

/** Stub de registro de action não relacionada ao seam testado. */
function add_action( mixed ...$args ): bool { return true; }

/** Stub de registro de rota não relacionada ao seam testado. */
function register_rest_route( mixed ...$args ): bool { return true; }

/** Lê o status de uma resposta HTTP sintética. */
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['status'] ?? 0 ); }

/** Lê o corpo de uma resposta HTTP sintética. */
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }

/** Lê uma cotação sintética do transient em memória. */
function get_transient( string $key ): mixed { return $GLOBALS['braspress_cache'][ $key ]['value'] ?? false; }

/** Persiste uma cotação sintética e seu TTL observável. */
function set_transient( string $key, mixed $value, int $expiration ): bool {
	$GLOBALS['braspress_cache'][ $key ] = array(
		'value' => $value,
		'ttl'   => $expiration,
	);

	return true;
}

/** Executa somente o transporte falso configurado pela fixture. */
function wp_remote_request( string $url, array $args ): mixed {
	$GLOBALS['braspress_http_calls'][] = array( 'url' => $url, 'args' => $args );

	return $GLOBALS['braspress_http_response'];
}

/** Confirma que a integração sintética pertence ao vendor que a recebeu. */
function papelito_vendor_integration_config_complete( array $config ): bool { return ! empty( $config ); }

/** Normaliza um documento sintético de fixture. */
function papelito_vendor_integration_normalize_document( mixed $value ): string { return (string) preg_replace( '/\D+/', '', (string) $value ); }

/** Normaliza um CEP sintético de fixture. */
function papelito_vendor_integration_normalize_cep( mixed $value ): string { return (string) preg_replace( '/\D+/', '', (string) $value ); }

/** Registra uma transição de estado sintética da integração. */
function papelito_vendor_integration_set_braspress_operational_state( int $vendor_id, string $status, string $error_category = '' ): void {
	$GLOBALS['braspress_states'][] = array(
		'vendor_id'      => $vendor_id,
		'status'         => $status,
		'error_category' => $error_category,
	);
}

require_once dirname( __DIR__ ) . '/includes/shipping.php';
require_once dirname( __DIR__ ) . '/includes/braspress.php';

const PAPELITO_VENDOR_INTEGRATION_ACTIVE = 'active';
const PAPELITO_VENDOR_INTEGRATION_INVALID = 'invalid_credentials';
const PAPELITO_VENDOR_INTEGRATION_BLOCKED = 'provider_blocked';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED = 'applied';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE   = 'stale';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED  = 'failed';
const BRASPRESS_TEST_SALT                = 'salt-de-cache-sintetico';
const BRASPRESS_TEST_VENDOR_ID           = 2163;
const BRASPRESS_TEST_OTHER_VENDOR_ID     = 2164;
const BRASPRESS_TEST_INTEGRATION_ID      = 91;
const BRASPRESS_TEST_CHANGED_INTEGRATION_ID = 92;
const BRASPRESS_TEST_CONFIGURATION_VERSION = 7;
const BRASPRESS_TEST_USERNAME             = 'usuario-de-cache-sintetico';
const BRASPRESS_TEST_PASSWORD             = 'senha-de-cache-sintetica';
const BRASPRESS_TEST_SENDER_CNPJ          = '12345678000195';
const BRASPRESS_TEST_RECIPIENT_CNPJ       = '12345678000199';
const BRASPRESS_TEST_DESTINATION_CEP      = '22041001';
const BRASPRESS_TEST_OTHER_DESTINATION_CEP = '30140071';
const BRASPRESS_TEST_ORIGIN_CEP           = '01310930';
const BRASPRESS_TEST_PHYSICAL_HASH        = 'physical-hash-v1';
const BRASPRESS_TEST_CHANGED_PHYSICAL_HASH = 'physical-hash-v2';
const BRASPRESS_TEST_BASE_WEIGHT_KG       = 2.5;
const BRASPRESS_TEST_CHANGED_WEIGHT_KG    = 2.6;
const BRASPRESS_TEST_MERCHANDISE_CENTS    = 12345;
const BRASPRESS_TEST_CHANGED_VALUE_CENTS  = 12346;
const BRASPRESS_TEST_TIMEZONE             = 'UTC';
const BRASPRESS_TEST_QUOTE_ID             = 'Q-CACHE-1';
const BRASPRESS_TEST_QUOTE_PRICE          = 12.5;
const BRASPRESS_TEST_QUOTE_DAYS           = 3;
const BRASPRESS_TEST_RAW_BODY_SENTINEL    = 'provider-body-sentinel';
const BRASPRESS_TEST_QUOTE_UTC            = '2026-09-15 02:00:00.000000';
const BRASPRESS_TEST_QUOTE_2359_UTC       = '2026-09-15 02:59:00.000000';
const BRASPRESS_TEST_QUOTE_MIDNIGHT_UTC   = '2026-09-15 03:00:30.000000';
const BRASPRESS_TEST_QUOTE_EXPIRY_UTC     = '2026-09-15 02:59:59.999000';

$GLOBALS['braspress_cache']        = array();
$GLOBALS['braspress_cache_actions'] = array();
$GLOBALS['braspress_http_calls']   = array();
$GLOBALS['braspress_states']       = array();
$GLOBALS['braspress_http_response'] = array();
$failures                          = 0;

/** Confere um comportamento do cache Braspress standalone. */
function braspress_cache_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

/** Monta uma integração resolvida com credenciais exclusivamente sintéticas. */
function braspress_cache_fixture_integration( int $vendor_id = BRASPRESS_TEST_VENDOR_ID, int $configuration_version = BRASPRESS_TEST_CONFIGURATION_VERSION ): array {
	return array(
		'id'                   => BRASPRESS_TEST_INTEGRATION_ID,
		'vendor_id'            => $vendor_id,
		'configuration_version' => $configuration_version,
		'credentials'          => array(
			'username' => BRASPRESS_TEST_USERNAME,
			'password' => BRASPRESS_TEST_PASSWORD,
		),
		'config' => array(
			'sender_cnpj'           => BRASPRESS_TEST_SENDER_CNPJ,
			'origin_cep'            => BRASPRESS_TEST_ORIGIN_CEP,
			'modal'                 => 'R',
			'freight_type'          => '1',
			'tracking_tomador_cnpj' => BRASPRESS_TEST_SENDER_CNPJ,
		),
	);
}

/** Monta o menor pacote físico Braspress válido para os cenários. */
function braspress_cache_fixture_package( float $weight_kg = BRASPRESS_TEST_BASE_WEIGHT_KG, int $volumes = 1, string $physical_hash = BRASPRESS_TEST_PHYSICAL_HASH ): array {
	return array(
		'weight_kg'    => $weight_kg,
		'volumes'      => $volumes,
		'physical_hash' => $physical_hash,
		'cubagem'      => array(
			array(
				'length_m' => 0.3,
				'width_m'  => 0.2,
				'height_m' => 0.1,
				'volumes'  => $volumes,
			),
		),
	);
}

/** Retorna uma data UTC injetada na cotação. */
function braspress_cache_fixture_time( string $value ): DateTimeImmutable {
	return new DateTimeImmutable( $value, new DateTimeZone( BRASPRESS_TEST_TIMEZONE ) );
}

/** Retorna o response JSON de sucesso com um campo bruto que não pode ser cacheado. */
function braspress_cache_success_response(): array {
	return array(
		'status' => 200,
		'body'   => wp_json_encode(
			array(
				'id'             => BRASPRESS_TEST_QUOTE_ID,
				'totalFrete'     => BRASPRESS_TEST_QUOTE_PRICE,
				'prazo'          => BRASPRESS_TEST_QUOTE_DAYS,
				'raw_body_field' => BRASPRESS_TEST_RAW_BODY_SENTINEL,
			)
		),
	);
}

/** Reinicia o transporte e o armazenamento falso entre cenários. */
function braspress_cache_reset(): void {
	$GLOBALS['braspress_cache']         = array();
	$GLOBALS['braspress_cache_actions'] = array();
	$GLOBALS['braspress_http_calls']    = array();
	$GLOBALS['braspress_states']        = array();
}

/** Define a próxima resposta do executor HTTP falso. */
function braspress_cache_set_response( mixed $response ): void { $GLOBALS['braspress_http_response'] = $response; }

/** Conta chamadas feitas ao executor HTTP falso. */
function braspress_cache_call_count(): int { return count( $GLOBALS['braspress_http_calls'] ); }

/** Retorna o único registro de cache salvo no cenário atual. */
function braspress_cache_last_entry(): array {
	$entries = array_values( $GLOBALS['braspress_cache'] );

	return $entries[ count( $entries ) - 1 ] ?? array();
}

$integration = braspress_cache_fixture_integration();
$package     = braspress_cache_fixture_package();
$quoted_at   = braspress_cache_fixture_time( BRASPRESS_TEST_QUOTE_UTC );

braspress_cache_reset();
braspress_cache_set_response( braspress_cache_success_response() );
$first_quote  = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$second_quote = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
braspress_cache_assert( 'cotação idêntica devolve a mesma opção', is_array( $first_quote ) && $first_quote === $second_quote );
braspress_cache_assert( 'segunda cotação idêntica não chama o transporte', 1 === braspress_cache_call_count() );
braspress_cache_assert( 'cotação válida grava exatamente um valor', 1 === count( $GLOBALS['braspress_cache'] ) );
braspress_cache_assert( 'cache publica hit e miss com vendor e resultado', 2 === count( $GLOBALS['braspress_cache_actions'] ) && 'miss' === $GLOBALS['braspress_cache_actions'][0]['args'][1] && 'hit' === $GLOBALS['braspress_cache_actions'][1]['args'][1] && BRASPRESS_TEST_VENDOR_ID === $GLOBALS['braspress_cache_actions'][1]['args'][0] );

braspress_cache_reset();
$other_vendor_quote = papelito_braspress_quote_at( braspress_cache_fixture_integration( BRASPRESS_TEST_OTHER_VENDOR_ID ), BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$same_payload_quote = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
braspress_cache_assert( 'vendors diferentes com pacote físico idêntico não compartilham cache', is_array( $other_vendor_quote ) && is_array( $same_payload_quote ) && 2 === braspress_cache_call_count() && 2 === count( $GLOBALS['braspress_cache'] ) );

braspress_cache_reset();
papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$changed_version_quote = papelito_braspress_quote_at( braspress_cache_fixture_integration( BRASPRESS_TEST_VENDOR_ID, BRASPRESS_TEST_CONFIGURATION_VERSION + 1 ), BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
braspress_cache_assert( 'configuration_version diferente invalida o cache', is_array( $changed_version_quote ) && 2 === braspress_cache_call_count() );

braspress_cache_reset();
papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$changed_integration    = $integration;
$changed_integration['id'] = BRASPRESS_TEST_CHANGED_INTEGRATION_ID;
$changed_integration_quote = papelito_braspress_quote_at( $changed_integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
braspress_cache_assert( 'id da integração diferente invalida o cache', is_array( $changed_integration_quote ) && 2 === braspress_cache_call_count() );

braspress_cache_reset();
papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$changed_destination = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_OTHER_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$changed_value       = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_CHANGED_VALUE_CENTS, $quoted_at );
$changed_weight      = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, braspress_cache_fixture_package( BRASPRESS_TEST_CHANGED_WEIGHT_KG ), BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$changed_volumes     = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, braspress_cache_fixture_package( BRASPRESS_TEST_BASE_WEIGHT_KG, 2 ), BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$changed_physical    = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, braspress_cache_fixture_package( BRASPRESS_TEST_BASE_WEIGHT_KG, 1, BRASPRESS_TEST_CHANGED_PHYSICAL_HASH ), BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
braspress_cache_assert( 'destino, valor, peso, volumes e physical_hash invalidam o cache', is_array( $changed_destination ) && is_array( $changed_value ) && is_array( $changed_weight ) && is_array( $changed_volumes ) && is_array( $changed_physical ) && 6 === braspress_cache_call_count() );

$payload = papelito_braspress_build_quote_payload( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS );
$cache_key = '';
if ( function_exists( 'papelito_braspress_quote_cache_key' ) && is_array( $payload ) ) {
	$cache_key = papelito_braspress_quote_cache_key( BRASPRESS_TEST_VENDOR_ID, $integration, $payload, BRASPRESS_TEST_PHYSICAL_HASH, $quoted_at );
}
braspress_cache_assert( 'função de chave de cache existe', '' !== $cache_key );
$opaque_values = array( BRASPRESS_TEST_DESTINATION_CEP, BRASPRESS_TEST_OTHER_DESTINATION_CEP, BRASPRESS_TEST_ORIGIN_CEP, BRASPRESS_TEST_SENDER_CNPJ, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_USERNAME, BRASPRESS_TEST_PASSWORD );
$opaque_key    = true;
foreach ( $opaque_values as $opaque_value ) {
	if ( str_contains( $cache_key, $opaque_value ) ) {
		$opaque_key = false;
	}
}
braspress_cache_assert( 'chave não contém CEP, CNPJ ou credencial em claro', '' !== $cache_key && $opaque_key );

braspress_cache_reset();
braspress_cache_set_response( braspress_cache_success_response() );
papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, braspress_cache_fixture_time( BRASPRESS_TEST_QUOTE_2359_UTC ) );
$entry_2359 = braspress_cache_last_entry();
$ttl_2359   = (int) ( $entry_2359['ttl'] ?? 0 );
braspress_cache_assert( '23:59 de São Paulo grava TTL positivo e pequeno', $ttl_2359 > 0 && $ttl_2359 <= PAPELITO_BRASPRESS_QUOTE_CACHE_MAX_TTL );
$midnight_quote = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, braspress_cache_fixture_time( BRASPRESS_TEST_QUOTE_MIDNIGHT_UTC ) );
braspress_cache_assert( 'virada do dia civil de São Paulo invalida a entrada anterior', is_array( $midnight_quote ) && 2 === braspress_cache_call_count() && 2 === count( $GLOBALS['braspress_cache'] ) );
$entry_midnight = braspress_cache_last_entry();
$ttl_midnight   = (int) ( $entry_midnight['ttl'] ?? 0 );
braspress_cache_assert( 'TTL após meia-noite respeita o teto interno', $ttl_midnight > 0 && $ttl_midnight <= PAPELITO_BRASPRESS_QUOTE_CACHE_MAX_TTL );
braspress_cache_reset();
papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, braspress_cache_fixture_time( BRASPRESS_TEST_QUOTE_EXPIRY_UTC ) );
braspress_cache_assert( 'TTL zero no fim do dia não grava', 0 === count( $GLOBALS['braspress_cache'] ) );

$error_responses = array(
	'timeout'         => new WP_Error( 'http_request_failed', 'Operation timed out' ),
	'network'         => new WP_Error( 'http_request_failed', 'Could not resolve host' ),
	'429'             => array( 'status' => 429, 'body' => '{"message":"Too many requests"}' ),
	'4xx'             => array( 'status' => 400, 'body' => '{"status":400,"errors":{"Cep":["invalido"]}}' ),
	'5xx'             => array( 'status' => 500, 'body' => '{"errorList":["FALHA SINTETICA"]}' ),
	'conta bloqueada' => array( 'status' => 500, 'body' => '{"errorList":["CNPJ PAGANTE BLOQUEADO"]}' ),
	'rota ausente'    => array( 'status' => 500, 'body' => '{"errorList":["CEP DESTINO NÃO ENCONTRADO"]}' ),
	'resposta inválida' => array( 'status' => 200, 'body' => '{"id":"","totalFrete":12.5,"prazo":3}' ),
);
foreach ( $error_responses as $label => $response ) {
	braspress_cache_reset();
	braspress_cache_set_response( $response );
	$first_error  = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
	$second_error = papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
	braspress_cache_assert( "{$label} nunca grava e tenta novamente", is_wp_error( $first_error ) && is_wp_error( $second_error ) && 2 === braspress_cache_call_count() && 0 === count( $GLOBALS['braspress_cache'] ) );
}

braspress_cache_reset();
braspress_cache_set_response( braspress_cache_success_response() );
papelito_braspress_quote_at( $integration, BRASPRESS_TEST_RECIPIENT_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, BRASPRESS_TEST_MERCHANDISE_CENTS, $quoted_at );
$stored_value = wp_json_encode( $GLOBALS['braspress_cache'] );
braspress_cache_assert( 'valor cacheado não contém corpo bruto do provider nem credencial', ! str_contains( $stored_value, BRASPRESS_TEST_RAW_BODY_SENTINEL ) && ! str_contains( $stored_value, BRASPRESS_TEST_USERNAME ) && ! str_contains( $stored_value, BRASPRESS_TEST_PASSWORD ) );
$action_value = wp_json_encode( $GLOBALS['braspress_cache_actions'] );
braspress_cache_assert( 'action de cache não transporta payload nem credencial', ! str_contains( $action_value, BRASPRESS_TEST_DESTINATION_CEP ) && ! str_contains( $action_value, BRASPRESS_TEST_USERNAME ) && ! str_contains( $action_value, BRASPRESS_TEST_PASSWORD ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
