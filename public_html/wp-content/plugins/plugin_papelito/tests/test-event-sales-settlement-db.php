<?php
/**
 * Ledger do repasse à Cifal contra o banco real.
 *
 * Prova que gravar o mesmo pagamento duas vezes deixa uma linha só, que o estorno entra negativo
 * e que o CSV mostra "a conferir" quando a taxa não veio do Mercado Pago.
 *
 *   docker compose exec -T web wp --allow-root eval-file \
 *     wp-content/plugins/plugin_papelito/tests/test-event-sales-settlement-db.php
 *
 * Usa um event_id reservado e apaga as próprias linhas no fim.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- WP-CLI test script prints results by design.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-event-sales-settlement-db.php\n" );
	exit( 1 );
}

const EVENT_SETTLEMENT_TEST_EVENT = 'teste-ledger-evento';

global $wpdb, $failures;

$failures = 0;

/**
 * Registra uma asserção.
 *
 * @param string $label     Descrição.
 * @param bool   $condition Resultado.
 * @return void
 */
function event_settlement_db_check( string $label, bool $condition ): void {
	global $failures;

	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/**
 * Apaga as linhas do evento de teste.
 *
 * @return void
 */
function event_settlement_db_cleanup(): void {
	global $wpdb;

	$wpdb->delete( papelito_event_sales_settlement_table(), array( 'event_id' => EVENT_SETTLEMENT_TEST_EVENT ) );
}

echo "test_record_is_idempotent_and_refund_negative\n";

event_settlement_db_check( 'tabela instalada', true === papelito_event_sales_install_tables() );
event_settlement_db_cleanup();

$payment = array(
	'event_id'          => EVENT_SETTLEMENT_TEST_EVENT,
	'order_id'          => 501,
	'attempt_id'        => 9001,
	'kind'              => 'payment',
	'payment_method'    => 'card',
	'remote_order_id'   => 'ORD01TESTE',
	'remote_payment_id' => '123456789',
	'nsu'               => '000123',
	'gross_cents'       => 123456,
	'fee_cents'         => 4321,
	'net_cents'         => 119135,
	'created_by'        => 1,
);

event_settlement_db_check( 'primeiro registro grava', true === papelito_event_sales_settlement_record( $payment ) );
event_settlement_db_check( 'repetir o mesmo pagamento é aceito', true === papelito_event_sales_settlement_record( array_merge( $payment, array( 'gross_cents' => 999 ) ) ) );

$refund = array_merge(
	$payment,
	array(
		'kind'        => 'refund',
		'gross_cents' => 123456,
		'fee_cents'   => null,
		'net_cents'   => null,
	)
);
event_settlement_db_check( 'estorno grava', true === papelito_event_sales_settlement_record( $refund ) );

$manual = array_merge(
	$payment,
	array(
		'order_id'          => 502,
		'attempt_id'        => 9002,
		'kind'              => 'manual_release',
		'remote_order_id'   => '',
		'remote_payment_id' => '',
		'nsu'               => '000999',
		'gross_cents'       => 5000,
		'fee_cents'         => null,
		'net_cents'         => null,
	)
);
event_settlement_db_check( 'liberação manual grava', true === papelito_event_sales_settlement_record( $manual ) );
event_settlement_db_check( 'acha a liberação manual da tentativa', papelito_event_sales_settlement_has( 9002, 'manual_release' ) );
event_settlement_db_check( 'não acha tipo que a tentativa não tem', ! papelito_event_sales_settlement_has( 9002, 'payment' ) );

$rows = papelito_event_sales_settlement_rows( EVENT_SETTLEMENT_TEST_EVENT );
event_settlement_db_check( 'três linhas, sem duplicata', 3 === count( $rows ) );

$by_kind = array_column( $rows, null, 'kind' );
event_settlement_db_check( 'duplicata não sobrescreve o bruto', 123456 === (int) $by_kind['payment']['gross_cents'] );
event_settlement_db_check( 'estorno entra negativo', -123456 === (int) $by_kind['refund']['gross_cents'] );
event_settlement_db_check( 'taxa desconhecida fica nula', null === $by_kind['manual_release']['fee_cents'] );

$csv   = papelito_event_sales_settlement_csv( $rows );
$lines = explode( "\n", trim( $csv ) );
event_settlement_db_check( 'cabeçalho do CSV', 'pedido;data;tipo;forma;order_mp;pagamento_mp;nsu;bruto;taxa;liquido' === $lines[0] );
event_settlement_db_check( 'valor com vírgula decimal', false !== strpos( $csv, ';1234,56;43,21;1191,35' ) );
event_settlement_db_check( 'taxa nula sai como a conferir', false !== strpos( $csv, ';50,00;a conferir;a conferir' ) );
event_settlement_db_check( 'estorno negativo no CSV', false !== strpos( $csv, ';-1234,56;' ) );
event_settlement_db_check( 'total do bruto e do líquido conhecido', 'TOTAL;;;;;;;50,00;43,21;1191,35' === $lines[ count( $lines ) - 1 ] );

event_settlement_db_cleanup();

echo 0 === $failures ? "OK\n" : "{$failures} falha(s)\n";
if ( $failures > 0 ) {
	exit( 1 );
}
