<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Correios como transportadora regular ou contingência técnica da Braspress.
 *
 * Usage: php tests/test-shipping-correios-fallback.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_BRASPRESS_ENABLED', true );
define( 'PAPELITO_BRASPRESS_VENDOR_ALLOWLIST', '7,8' );
define( 'PAPELITO_SHIPPING_BREAKER_CLOSED', 'closed' );
define( 'PAPELITO_SHIPPING_BREAKER_OPEN', 'open' );

const FALLBACK_TEST_NOW             = '2026-10-13T12:00:00+00:00';
const FALLBACK_TEST_DESTINATION_CEP = '22041001';
const FALLBACK_TEST_RECIPIENT_CNPJ  = '12345678000195';
const FALLBACK_TEST_VENDOR          = 7;
const FALLBACK_TEST_OTHER_VENDOR    = 8;
const FALLBACK_TEST_ALERT_HOOK      = 'papelito_shipping_provider_alert';

ini_set( 'error_log', '/dev/null' );

class WP_Error {
	private string $code;
	private mixed $data;

	public function __construct( string $code, string $message = '', mixed $data = null ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function current_time( mixed ...$args ): string { return FALLBACK_TEST_NOW; }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }
function wp_salt( mixed ...$args ): string { return 'test-salt'; }
function add_action( mixed ...$args ): bool { return true; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

function do_action( string $hook, mixed ...$args ): bool {
	$GLOBALS['fallback_actions'][] = array( $hook, $args );
	return true;
}

function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed {
	return 'papelito_braspress_physical_package' === $hook ? array( 'approval_version' => 'test-approved' ) : $value;
}

$correios_options = array(
	'origin_cep'      => '01001000',
	'destination_cep' => FALLBACK_TEST_DESTINATION_CEP,
	'vendor_id'       => FALLBACK_TEST_VENDOR,
	'options'         => array( array( 'code' => '03298', 'name' => 'PAC', 'service' => 'PAC', 'price' => 15.88, 'delivery_time' => 5 ) ),
);
$braspress_option = array( 'code' => 'rodoviario', 'name' => 'Braspress', 'service' => 'RODOVIARIO', 'price' => 22.5, 'delivery_time' => 3, 'external_quote_id' => 'quote-1' );

$GLOBALS['fallback_correios_enabled'] = array();
$GLOBALS['fallback_breaker']          = array();
$GLOBALS['fallback_braspress_result'] = $braspress_option;
$GLOBALS['fallback_correios_result']  = $correios_options;
$GLOBALS['fallback_calls']            = array();
$GLOBALS['fallback_actions']          = array();

function papelito_vendor_carriers_correios_enabled( int $vendor_id ): bool {
	return $GLOBALS['fallback_correios_enabled'][ $vendor_id ] ?? true;
}
function papelito_shipping_breaker_state( string $provider, int $vendor_id ): string {
	return $GLOBALS['fallback_breaker'][ $vendor_id ] ?? PAPELITO_SHIPPING_BREAKER_CLOSED;
}
function papelito_shipping_breaker_allows( string $provider, int $vendor_id ): bool {
	return PAPELITO_SHIPPING_BREAKER_CLOSED === papelito_shipping_breaker_state( $provider, $vendor_id );
}
function papelito_correios_quote( int $vendor_id, string $destination_cep, array $items ): array|WP_Error {
	$GLOBALS['fallback_calls'][] = 'correios';
	return $GLOBALS['fallback_correios_result'];
}
function papelito_vendor_integration_resolve_braspress( int $vendor_id ): ?array {
	return empty( $GLOBALS['fallback_braspress_disabled'] ) ? array( 'config' => array( 'origin_cep' => '01001000' ) ) : null;
}
function papelito_braspress_package_is_valid( array $package ): bool { return true; }
function papelito_braspress_quote( array $integration, string $recipient_cnpj, string $destination_cep, array $package, int $merchandise_value_cents ): array|WP_Error {
	$GLOBALS['fallback_calls'][] = 'braspress';
	return $GLOBALS['fallback_braspress_result'];
}
function papelito_vendor_integration_normalize_document( mixed $document ): string { return preg_replace( '/\D+/', '', (string) $document ); }
function papelito_shipping_notify_package_built( mixed $package ): mixed { return $package; }

require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';
require_once dirname( __DIR__ ) . '/includes/shipping_observability.php';

$failures = 0;

function fallback_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "  PASS: {$label}\n";
}

/** Cota do jeito do checkout e devolve resultado, ordem das chamadas e alertas de contingência. */
function fallback_quote( int $vendor_id = FALLBACK_TEST_VENDOR ): array {
	$GLOBALS['fallback_calls']   = array();
	$GLOBALS['fallback_actions'] = array();
	$result = papelito_shipping_quote_all_providers(
		$vendor_id,
		FALLBACK_TEST_DESTINATION_CEP,
		array(),
		array( 'recipient_cnpj' => FALLBACK_TEST_RECIPIENT_CNPJ, 'merchandise_value_cents' => 1000 )
	);
	$alerts = array_values(
		array_filter(
			$GLOBALS['fallback_actions'],
			static fn ( array $action ): bool => FALLBACK_TEST_ALERT_HOOK === $action[0]
		)
	);

	return array( $result, $GLOBALS['fallback_calls'], $alerts );
}

function fallback_providers( mixed $result ): array {
	if ( ! is_array( $result ) ) {
		return array();
	}

	return array_map( static fn ( array $option ): string => (string) $option['provider'], $result['options'] );
}

function fallback_braspress_failure( string $code, string $category, int $status = 502 ): WP_Error {
	return new WP_Error( $code, 'falha', array( 'status' => $status, 'error_category' => $category ) );
}

echo "Correios ligados\n";
[ $result, $calls ] = fallback_quote();
fallback_assert( 'Correios ON + Braspress no ar oferecem as duas', array( 'correios', 'braspress' ) === fallback_providers( $result ) );
fallback_assert( 'Correios ON continuam cotados antes da Braspress', array( 'correios', 'braspress' ) === $calls );

echo "\nCorreios desligados\n";
$GLOBALS['fallback_correios_enabled'][ FALLBACK_TEST_VENDOR ] = false;
[ $result, $calls, $alerts ] = fallback_quote();
fallback_assert( 'Correios OFF + Braspress no ar oferecem só a Braspress', array( 'braspress' ) === fallback_providers( $result ) );
fallback_assert( 'Correios OFF não chamam os Correios com a Braspress no ar', array( 'braspress' ) === $calls );
fallback_assert( 'sem falha não há registro de contingência', array() === $alerts );

foreach ( array( 'timeout', 'network_error', 'rate_limited', 'provider_5xx', 'invalid_response', 'configuration_error' ) as $category ) {
	$GLOBALS['fallback_braspress_result'] = fallback_braspress_failure( 'papelito_braspress_' . $category, $category );
	[ $result, $calls, $alerts ] = fallback_quote();
	fallback_assert( "falha técnica {$category} aciona os Correios como contingência", array( 'correios' ) === fallback_providers( $result ) );
	fallback_assert( "contingência por {$category} chama Braspress e depois Correios, uma vez cada", array( 'braspress', 'correios' ) === $calls );
	fallback_assert(
		"contingência por {$category} é registrada com vendor e motivo",
		1 === count( $alerts )
			&& 'correios' === $alerts[0][1][0]
			&& 'fallback_engaged' === $alerts[0][1][1]
			&& array( 'vendor_id' => FALLBACK_TEST_VENDOR, 'error_category' => $category ) === $alerts[0][1][2]
	);
}

$non_technical = array(
	'rota não atendida'      => fallback_braspress_failure( 'papelito_braspress_route_not_available', 'not_available', 422 ),
	'credencial recusada'    => fallback_braspress_failure( 'papelito_braspress_credentials_invalid', 'authentication_error', 401 ),
	'conta bloqueada'        => fallback_braspress_failure( 'papelito_braspress_account_blocked', 'provider_account_blocked', 403 ),
	'segredo indisponível'   => new WP_Error( 'papelito_vendor_integration_secret_unavailable', 'falha', array( 'status' => 503 ) ),
);
foreach ( $non_technical as $label => $error ) {
	$GLOBALS['fallback_braspress_result'] = $error;
	[ $result, $calls, $alerts ] = fallback_quote();
	fallback_assert( "{$label} não aciona os Correios desligados", is_wp_error( $result ) && array( 'braspress' ) === $calls && array() === $alerts );
}

$GLOBALS['fallback_braspress_result'] = new WP_Error( 'papelito_braspress_payload_invalid', 'falha', array( 'status' => 422 ) );
[ $result ] = fallback_quote();
fallback_assert( 'payload que a Papelito não conseguiu montar é falha técnica, não resposta da Braspress', array( 'correios' ) === fallback_providers( $result ) );

$GLOBALS['fallback_braspress_result'] = $braspress_option;
$GLOBALS['fallback_breaker'][ FALLBACK_TEST_VENDOR ] = PAPELITO_SHIPPING_BREAKER_OPEN;
[ $result, $calls, $alerts ] = fallback_quote();
fallback_assert( 'disjuntor aberto conta como Braspress fora do ar', array( 'correios' ) === fallback_providers( $result ) && array( 'correios' ) === $calls );
fallback_assert( 'contingência por disjuntor registra o motivo', 1 === count( $alerts ) && 'circuit_open' === $alerts[0][1][2]['error_category'] );

unset( $GLOBALS['fallback_breaker'][ FALLBACK_TEST_VENDOR ] );
[ $result, $calls ] = fallback_quote();
fallback_assert( 'Braspress recuperada volta a ser a única opção', array( 'braspress' ) === fallback_providers( $result ) && array( 'braspress' ) === $calls );

$GLOBALS['fallback_braspress_disabled'] = true;
[ $result, $calls, $alerts ] = fallback_quote();
fallback_assert( 'Braspress desabilitada + Correios OFF ficam sem frete, sem contingência', is_wp_error( $result ) && array() === $calls && array() === $alerts );
$GLOBALS['fallback_breaker'][ FALLBACK_TEST_VENDOR ] = PAPELITO_SHIPPING_BREAKER_OPEN;
[ $result, $calls ] = fallback_quote();
fallback_assert( 'disjuntor aberto de uma Braspress desabilitada não aciona contingência', is_wp_error( $result ) && array() === $calls );
unset( $GLOBALS['fallback_breaker'][ FALLBACK_TEST_VENDOR ] );
$GLOBALS['fallback_braspress_disabled'] = false;

echo "\nAs duas indisponíveis\n";
$GLOBALS['fallback_braspress_result'] = fallback_braspress_failure( 'papelito_braspress_timeout', 'timeout', 504 );
$GLOBALS['fallback_correios_result']  = new WP_Error( 'papelito_correios_timeout', 'falha', array( 'status' => 504 ) );
[ $result ] = fallback_quote();
fallback_assert( 'Braspress e Correios fora devolvem erro de frete, sem opção inventada', is_wp_error( $result ) && 'timeout' === $result->get_error_code() );
$GLOBALS['fallback_correios_result'] = $correios_options;

echo "\nIsolamento por vendor\n";
[ $result, $calls ] = fallback_quote( FALLBACK_TEST_OTHER_VENDOR );
fallback_assert( 'Correios desligados de um vendor não afetam outro', array( 'correios' ) === array_slice( $calls, 0, 1 ) && in_array( 'correios', fallback_providers( $result ), true ) );

echo "\n";
echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
