<?php
/**
 * Standalone regression test for the Braspress v3 date parser and reconciliation.
 *
 * A Braspress devolve data como `dd/MM/yyyy` e `dd/MM/yyyy HH:mm`, em
 * America/Sao_Paulo. O parser genérico do PHP lê barra como formato americano,
 * então `01/02/2026` viraria 2 de janeiro em silêncio — e `25/12/2026` viraria
 * erro de mês 25. O parser precisa ser estrito e não pode depender do fuso do
 * servidor, que em produção não é o de São Paulo.
 *
 * Usage: php tests/test-braspress-tracking-timeline.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );

date_default_timezone_set( 'UTC' );

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function add_action( ...$args ) {
	return true;
}

require_once dirname( __DIR__ ) . '/includes/braspress_tracking.php';

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

echo "Scenario 1: the two documented formats are read as São Paulo local time\n";
papelito_assert(
	'date only becomes midnight in São Paulo, stored in UTC',
	'2026-12-25 03:00:00',
	papelito_braspress_tracking_parse_datetime( '25/12/2026' )
);
papelito_assert(
	'date and time are read as São Paulo local time',
	'2026-12-25 17:30:00',
	papelito_braspress_tracking_parse_datetime( '25/12/2026 14:30' )
);
papelito_assert(
	'surrounding whitespace does not break the parse',
	'2026-12-25 03:00:00',
	papelito_braspress_tracking_parse_datetime( '  25/12/2026  ' )
);

echo "Scenario 2: day comes first, which the PHP default parser gets backwards\n";
papelito_assert(
	'01/02/2026 is the first of February, not the second of January',
	'2026-02-01 03:00:00',
	papelito_braspress_tracking_parse_datetime( '01/02/2026' )
);
papelito_assert(
	'a day above twelve is not rejected as an impossible month',
	'2026-02-25 03:00:00',
	papelito_braspress_tracking_parse_datetime( '25/02/2026' )
);

echo "Scenario 3: the answer does not depend on the server clock\n";
date_default_timezone_set( 'Asia/Tokyo' );
papelito_assert(
	'a server in Tokyo reads the same instant',
	'2026-12-25 17:30:00',
	papelito_braspress_tracking_parse_datetime( '25/12/2026 14:30' )
);
date_default_timezone_set( 'UTC' );

echo "Scenario 4: anything outside the contract has no date instead of a wrong one\n";
papelito_assert( 'empty string has no date', null, papelito_braspress_tracking_parse_datetime( '' ) );
papelito_assert( 'impossible date has no date', null, papelito_braspress_tracking_parse_datetime( '32/13/2026' ) );
papelito_assert( 'overflowing day has no date', null, papelito_braspress_tracking_parse_datetime( '31/02/2026' ) );
papelito_assert( 'ISO is not in the contract', null, papelito_braspress_tracking_parse_datetime( '2026-12-25' ) );
papelito_assert( 'seconds are not in the contract', null, papelito_braspress_tracking_parse_datetime( '25/12/2026 14:30:15' ) );
papelito_assert( 'free text has no date', null, papelito_braspress_tracking_parse_datetime( 'ontem' ) );

function papelito_braspress_test_response(): array {
	return array(
		'conhecimentos' => array(
			array(
				'numero'      => '1001',
				'ocorrencias' => array(
					array(
						'descricao' => 'Mercadoria coletada',
						'data'      => '20/09/2026 08:15',
					),
					array(
						'descricao' => 'Em transito para filial',
						'data'      => '21/09/2026 19:40',
					),
				),
			),
			array(
				'numero'   => '1002',
				'timeline' => array(
					array(
						'descricao' => 'Mercadoria coletada',
						'data'      => '20/09/2026 09:00',
					),
				),
			),
		),
	);
}

echo "Scenario 5: every conhecimento is read, not just the first\n";
$events = papelito_braspress_tracking_events( papelito_braspress_test_response() );
papelito_assert( 'three occurrences across two conhecimentos', 3, count( $events ) );
papelito_assert(
	'the second conhecimento is not dropped',
	true,
	in_array( '1002', array_column( $events, 'conhecimento' ), true )
);

echo "Scenario 6: occurrences arrive in chronological order with real dates\n";
papelito_assert( 'first event is the earliest collection', '2026-09-20 11:15:00', $events[0]['event_at'] );
papelito_assert( 'second event is the other collection', '2026-09-20 12:00:00', $events[1]['event_at'] );
papelito_assert( 'last event is the transit one', '2026-09-21 22:40:00', $events[2]['event_at'] );
papelito_assert( 'description survives untouched', 'Em transito para filial', $events[2]['descricao'] );

echo "Scenario 7: the same occurrence twice in one response is reconciled once\n";
$duplicated                      = papelito_braspress_test_response();
$duplicated['conhecimentos'][0]['timeline'] = $duplicated['conhecimentos'][0]['ocorrencias'];
papelito_assert(
	'timeline repeating an occurrence does not double it',
	3,
	count( papelito_braspress_tracking_events( $duplicated ) )
);

echo "Scenario 8: broken entries do not take healthy ones down\n";
$messy = array(
	'conhecimentos' => array(
		array(
			'numero'      => '2001',
			'ocorrencias' => array(
				array( 'data' => '20/09/2026' ),
				array( 'descricao' => '   ' ),
				array(
					'descricao' => 'Entregue ao destinatario',
					'data'      => 'ontem',
				),
				array(
					'descricao' => 'Saiu para entrega',
					'data'      => '22/09/2026 07:00',
				),
				'nao e um array',
			),
		),
	),
);
$messy_events = papelito_braspress_tracking_events( $messy );
papelito_assert( 'only entries with a description survive', 2, count( $messy_events ) );
papelito_assert( 'a dated event comes before an undated one', '2026-09-22 10:00:00', $messy_events[0]['event_at'] );
papelito_assert( 'an unreadable date keeps the event without a date', null, $messy_events[1]['event_at'] );
papelito_assert( 'the undated event is still the delivery text', 'Entregue ao destinatario', $messy_events[1]['descricao'] );

echo "Scenario 9: nothing to reconcile yields nothing\n";
papelito_assert( 'an empty answer has no events', array(), papelito_braspress_tracking_events( array( 'conhecimentos' => array() ) ) );
papelito_assert( 'a malformed answer has no events', array(), papelito_braspress_tracking_events( array() ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
