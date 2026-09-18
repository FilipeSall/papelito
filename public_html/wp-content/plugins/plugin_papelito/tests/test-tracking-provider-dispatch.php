<?php
/**
 * Standalone regression test for the tracking provider dispatcher.
 *
 * O polling nasceu só com Correios e ganhou Braspress como um `if` dentro de
 * papelito_tracking_poll_shipment(). Enquanto a escolha do provider viver num
 * condicional, cada transportadora nova exige editar o núcleo — que é
 * exatamente o que a BRASPRESS-008 tira do caminho.
 *
 * Usage: php tests/test-tracking-provider-dispatch.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['papelito_test_filters'] = array();

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function wp_next_scheduled( ...$args ) {
	return time();
}
function wp_schedule_event( ...$args ) {}
function current_time( ...$args ) {
	return gmdate( 'Y-m-d H:i:s' );
}
function wp_upload_dir( ...$args ) {
	return array(
		'basedir' => sys_get_temp_dir(),
		'baseurl' => '',
	);
}
function trailingslashit( $value ) {
	return rtrim( (string) $value, '/' ) . '/';
}
function get_option( $name, $default = false ) {
	return $default;
}

function wp_timezone() {
	return new DateTimeZone( 'America/Sao_Paulo' );
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['papelito_test_filters'][ $tag ][] = $callback;
}

function apply_filters( $tag, $value, ...$rest ) {
	foreach ( $GLOBALS['papelito_test_filters'][ $tag ] ?? array() as $callback ) {
		$value = $callback( $value, ...$rest );
	}
	return $value;
}

class WP_Error {
	public function __construct( ...$args ) {}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/** Presente no plugin real; aqui só precisa existir para o registro resolver. */
function papelito_braspress_tracking_poll_shipment( array $shipment ): void {}

require __DIR__ . '/../includes/correios_tracking.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: the legacy providers keep resolving to the Correios poller\n";
papelito_assert( 'correios resolves', 'papelito_tracking_poll_correios_shipment', papelito_tracking_resolve_poller( 'correios' ) );
papelito_assert( 'mock keeps the legacy poller', 'papelito_tracking_poll_correios_shipment', papelito_tracking_resolve_poller( 'mock' ) );
papelito_assert( 'manual keeps the legacy poller', 'papelito_tracking_poll_correios_shipment', papelito_tracking_resolve_poller( 'manual' ) );

echo "Scenario 2: Braspress is a registration, not a branch in the core\n";
papelito_assert( 'braspress resolves to its own poller', 'papelito_braspress_tracking_poll_shipment', papelito_tracking_resolve_poller( 'braspress' ) );

echo "Scenario 3: an unregistered carrier is refused instead of silently polled as Correios\n";
papelito_assert( 'unknown provider has no poller', null, papelito_tracking_resolve_poller( 'transportadora_nova' ) );
papelito_assert( 'empty provider has no poller', null, papelito_tracking_resolve_poller( '' ) );

echo "Scenario 4: the core accepts a carrier it never heard of\n";
add_filter(
	'papelito_tracking_providers',
	function ( array $providers ): array {
		$providers['jadlog'] = 'papelito_test_jadlog_poller';
		return $providers;
	}
);
papelito_assert( 'a registered carrier resolves', 'papelito_test_jadlog_poller', papelito_tracking_resolve_poller( 'jadlog' ) );

echo "Scenario 5: a registration that cannot be called is ignored\n";
add_filter(
	'papelito_tracking_providers',
	function ( array $providers ): array {
		$providers['quebrado'] = 'papelito_funcao_que_nao_existe';
		$providers['tambem']   = array( 'not', 'callable' );
		return $providers;
	}
);
papelito_assert( 'missing function is not returned as a poller', null, papelito_tracking_resolve_poller( 'quebrado' ) );
papelito_assert( 'non-callable registration is not returned', null, papelito_tracking_resolve_poller( 'tambem' ) );
papelito_assert( 'a broken registration does not poison the healthy ones', 'papelito_test_jadlog_poller', papelito_tracking_resolve_poller( 'jadlog' ) );

function papelito_test_jadlog_poller( array $shipment ): void {}

echo "Scenario 6: an adapter may hand over a date it already normalised\n";
$fields = papelito_tracking_event_fields(
	array(
		'codigo'     => 'BRASPRESS',
		'tipo'       => 'OCOR',
		'dtHrCriado' => '20/09/2026 08:15',
		'event_at'   => '2026-09-20 11:15:00',
	)
);
papelito_assert( 'the normalised date wins over the raw provider text', '2026-09-20 11:15:00', $fields['event_at'] );

$undated = papelito_tracking_event_fields(
	array(
		'codigo'     => 'BRASPRESS',
		'tipo'       => 'OCOR',
		'dtHrCriado' => 'ontem',
		'event_at'   => null,
	)
);
papelito_assert( 'an adapter saying there is no date is believed', null, $undated['event_at'] );

$legacy = papelito_tracking_event_fields(
	array(
		'codigo'     => 'PO',
		'tipo'       => '01',
		'dtHrCriado' => '2026-09-20T08:15:00',
	)
);
papelito_assert( 'Correios keeps parsing its own ISO date', true, is_string( $legacy['event_at'] ) );

echo "Scenario 7: the customer view names the carrier without leaking the rest\n";
$customer = papelito_tracking_customer_shipment(
	array(
		'id'                  => 7,
		'provider'            => 'braspress',
		'external_reference'  => 'PED-2026-0001',
		'tracking_code'       => null,
		'status'              => 'in_transit',
		'posted_at'           => '2026-09-20',
		'last_event_at'       => '2026-09-20 11:15:00',
		'last_event_description' => 'Mercadoria coletada',
		'last_event_location' => 'SAO PAULO - SP',
		'delivered_at'        => '',
		'idempotency_key'     => 'nao-pode-vazar',
		'label_storage_key'   => 'privado/etiqueta.pdf',
		'last_error_code'     => 'braspress_tracking_not_found',
		'prepost_id'          => '99',
	)
);
papelito_assert( 'the customer learns which carrier is moving the order', 'braspress', $customer['provider'] ?? null );
papelito_assert( 'the Braspress reference reaches the customer', 'PED-2026-0001', $customer['external_reference'] ?? null );
papelito_assert( 'the internal idempotency key stays internal', false, array_key_exists( 'idempotency_key', $customer ) );
papelito_assert( 'the private label key stays internal', false, array_key_exists( 'label_storage_key', $customer ) );
papelito_assert( 'the internal error code stays internal', false, array_key_exists( 'last_error_code', $customer ) );
papelito_assert( 'the prepostage id stays internal', false, array_key_exists( 'prepost_id', $customer ) );
papelito_assert( 'the last occurrence still reaches the customer', 'Mercadoria coletada', $customer['last_event_description'] ?? null );

$legacy_customer = papelito_tracking_customer_shipment(
	array(
		'id'            => 9,
		'tracking_code' => 'AA123456789BR',
		'status'        => 'posted',
	)
);
papelito_assert( 'a shipment without provider is read as Correios', 'correios', $legacy_customer['provider'] ?? null );
papelito_assert( 'the S10 code still reaches the customer', 'AA123456789BR', $legacy_customer['tracking_code'] ?? null );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
