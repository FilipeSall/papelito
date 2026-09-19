<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Desfecho de cada poll de rastreio, publicado com o provider da remessa.
 *
 * O ponto de escuta é o agendamento do próximo poll, e não cada poller, porque
 * é por ali que Correios e Braspress passam nos dois desfechos. Fixar isso é o
 * que faz a próxima transportadora nascer com métrica sem editar o núcleo — o
 * mesmo motivo pelo qual a escolha do poller virou registro na BRASPRESS-008.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

const POLL_TEST_SHIPMENT_ID = 918;
const POLL_TEST_BRASPRESS   = 'braspress';
const POLL_TEST_CORREIOS    = 'correios';
const POLL_TEST_NOT_FOUND   = 'braspress_tracking_not_found';

$GLOBALS['poll_test_row']       = array();
$GLOBALS['poll_test_published'] = array();

/** `$wpdb` mínimo: devolve a remessa da fixture e aceita a reprogramação. */
class Papelito_Poll_Test_Wpdb {
	public string $prefix = 'wp_';

	public function get_row( mixed $query, mixed $output = null ): ?array {
		return $GLOBALS['poll_test_row'];
	}

	public function prepare( mixed $query, mixed ...$args ): string { return (string) $query; }
	public function update( mixed $table, array $data, mixed $where, mixed $format = null, mixed $where_format = null ): int { return 1; }
}

$GLOBALS['wpdb'] = new Papelito_Poll_Test_Wpdb();

/** Guarda o evento publicado pelo agendador. */
function do_action( mixed $hook, mixed ...$args ): bool {
	if ( 'papelito_tracking_poll_result' === (string) $hook ) {
		$GLOBALS['poll_test_published'][] = $args;
	}

	return true;
}

/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Relógio fixo do teste. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-19 12:00:00'; }
/** Sem jitter aleatório, o agendamento do teste é determinístico. */
function wp_rand( mixed $min = 0, mixed $max = 0 ): int { return 0; }
/** Nenhum listener é registrado; o módulo é exercitado por chamada direta. */
function add_action( mixed ...$args ): bool { return true; }
/** Nenhum filtro instalado. */
function add_filter( mixed ...$args ): bool { return true; }
/** Devolve o valor recebido, sem filtro instalado. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed { return $value; }
/** Nenhuma rota REST é exercitada. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Nenhum cron é agendado. */
function wp_next_scheduled( mixed ...$args ): bool { return true; }
/** Nenhum cron é agendado. */
function wp_schedule_event( mixed ...$args ): bool { return true; }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de texto longo suficiente para as fixturas. */
function sanitize_textarea_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }

require_once dirname( __DIR__ ) . '/includes/correios_tracking.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function poll_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Recria a remessa da fixture com o provider e o status informados. */
function poll_reset( string $provider, string $status = 'in_transit' ): void {
	$GLOBALS['poll_test_row']       = array( 'status' => $status, 'poll_attempts' => 0, 'provider' => $provider );
	$GLOBALS['poll_test_published'] = array();
}

echo "Cenário 1: o poll bem-sucedido publica o provider da remessa\n";
poll_reset( POLL_TEST_CORREIOS );

papelito_tracking_schedule_next_poll( POLL_TEST_SHIPMENT_ID, false, '' );

poll_assert( 'O desfecho foi publicado', 1 === count( $GLOBALS['poll_test_published'] ) );
poll_assert(
	'O evento carrega o provider da remessa, não um nome fixo',
	POLL_TEST_CORREIOS === ( $GLOBALS['poll_test_published'][0][0] ?? null )
);
poll_assert(
	'O sucesso é publicado sem código de erro',
	false === ( $GLOBALS['poll_test_published'][0][1] ?? null )
	&& '' === ( $GLOBALS['poll_test_published'][0][2] ?? null )
);

echo "\nCenário 2: a falha publica provider e código, sem dado da remessa\n";
poll_reset( POLL_TEST_BRASPRESS );

papelito_tracking_schedule_next_poll( POLL_TEST_SHIPMENT_ID, true, POLL_TEST_NOT_FOUND );

poll_assert(
	'A falha carrega provider e código do erro',
	POLL_TEST_BRASPRESS === ( $GLOBALS['poll_test_published'][0][0] ?? null )
	&& true === ( $GLOBALS['poll_test_published'][0][1] ?? null )
	&& POLL_TEST_NOT_FOUND === ( $GLOBALS['poll_test_published'][0][2] ?? null )
);
poll_assert(
	'Nem o ID da remessa nem o código de rastreio atravessam para a métrica',
	3 === count( $GLOBALS['poll_test_published'][0] ?? array() )
);

echo "\nCenário 3: remessa sem provider legível não inventa um\n";
poll_reset( '' );

papelito_tracking_schedule_next_poll( POLL_TEST_SHIPMENT_ID, true, POLL_TEST_NOT_FOUND );

poll_assert(
	'Sem provider na linha, nada é publicado',
	array() === $GLOBALS['poll_test_published']
);

echo "\nCenário 4: remessa já entregue também fecha o ciclo com um desfecho\n";
poll_reset( POLL_TEST_CORREIOS, 'delivered' );

papelito_tracking_schedule_next_poll( POLL_TEST_SHIPMENT_ID, false, '' );

poll_assert(
	'O poll de uma remessa entregue conta como sucesso',
	1 === count( $GLOBALS['poll_test_published'] )
	&& POLL_TEST_CORREIOS === ( $GLOBALS['poll_test_published'][0][0] ?? null )
	&& false === ( $GLOBALS['poll_test_published'][0][1] ?? null )
);

echo "\nCenário 5: remessa que sumiu do banco não publica desfecho\n";
$GLOBALS['poll_test_row']       = null;
$GLOBALS['poll_test_published'] = array();

papelito_tracking_schedule_next_poll( POLL_TEST_SHIPMENT_ID, true, POLL_TEST_NOT_FOUND );

poll_assert( 'Sem linha, nada é publicado', array() === $GLOBALS['poll_test_published'] );

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
