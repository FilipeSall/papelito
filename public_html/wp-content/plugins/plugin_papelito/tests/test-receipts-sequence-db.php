<?php
/**
 * Integration checks for receipt numbering and issuance idempotency.
 *
 * Precisa do WordPress carregado — rode com WP-CLI, não com `php` direto:
 *
 *   wp eval-file tests/test-receipts-sequence-db.php idempotency
 *   wp eval-file tests/test-receipts-sequence-db.php claim
 *   wp eval-file tests/test-receipts-sequence-db.php report 50
 *   wp eval-file tests/test-receipts-sequence-db.php reset
 *
 * A concorrência real é exercitada disparando N `claim` em paralelo pelo shell;
 * `report` confere que saíram N números distintos e sem buraco.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-receipts-sequence-db.php <modo>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * Ano reservado para teste: nunca colide com numeração real.
 */
const PAPELITO_RECEIPT_TEST_YEAR = 2999;

$test_mode = isset( $args[0] ) ? (string) $args[0] : 'idempotency';

/**
 * `wp eval-file` executa o arquivo dentro de uma função: variável de topo NÃO é
 * global. Sem declarar $failures aqui, o `global $failures` do assert apontaria
 * para outra variável e o teste sairia com código 0 mesmo falhando.
 */
global $wpdb, $failures;
$tables = papelito_receipts_table_names();

if ( 'claim' === $test_mode ) {
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$sequence = papelito_receipt_claim_sequence( PAPELITO_RECEIPT_TEST_YEAR );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		echo esc_html( papelito_receipt_format_number( PAPELITO_RECEIPT_TEST_YEAR, $sequence ) ) . "\n";
		exit( 0 );
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		echo 'CLAIM_FAILED: ' . esc_html( $error->getMessage() ) . "\n";
		exit( 1 );
	}
}

if ( 'report' === $test_mode ) {
	$expected = isset( $args[1] ) ? (int) $args[1] : 0;
	$next     = (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT next_sequence FROM {$tables['sequences']} WHERE sequence_year = %d", PAPELITO_RECEIPT_TEST_YEAR )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$consumed = $next - 1;
	echo 'consumidos=' . (int) $consumed . ' esperado=' . (int) $expected . "\n";
	exit( $expected > 0 && $consumed !== $expected ? 1 : 0 );
}

if ( 'reset' === $test_mode ) {
	$wpdb->query(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "DELETE FROM {$tables['sequences']} WHERE sequence_year = %d", PAPELITO_RECEIPT_TEST_YEAR )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	echo "reset ok\n";
	exit( 0 );
}

$failures = 0;

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param mixed $expected Valor esperado.
 * @param mixed $actual   Valor obtido.
 */
function assert_db( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo 'PASS: ' . esc_html( $label ) . "\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . esc_html( $label ) . ' (expected ' . esc_html( var_export( $expected, true ) ) . ', got ' . esc_html( var_export( $actual, true ) ) . ")\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
}

// Pedido descartável, marcado como pago apenas pela meta do Pagar.me.
$paid_order = wc_create_order( array( 'created_via' => 'papelito_receipt_test' ) );
if ( is_wp_error( $paid_order ) ) {
	echo "FAIL: nao foi possivel criar o pedido de teste\n";
	exit( 1 );
}

$line_item = new WC_Order_Item_Product();
$line_item->set_name( 'Item de teste' );
$line_item->set_quantity( 2 );
$line_item->set_subtotal( 51.00 );
$line_item->set_total( 45.00 );
$line_item->add_meta_data( '_vendor_id', 4321, true );
$line_item->add_meta_data( '_vendor_name', 'Vendor de teste', true );
$line_item->add_meta_data( '_papelito_subtotal_cents', 5100, true );
$line_item->add_meta_data( '_papelito_discount_cents', 600, true );
$line_item->add_meta_data( '_papelito_total_cents', 4500, true );
$paid_order->add_item( $line_item );

$shipping_item = new WC_Order_Item_Shipping();
$shipping_item->set_method_title( 'Correios' );
$shipping_item->set_total( 9.90 );
$paid_order->add_item( $shipping_item );

$paid_order->update_meta_data( '_papelito_vendor_id', 4321 );
$paid_order->update_meta_data( '_papelito_vendor_name', 'Vendor de teste' );
$paid_order->update_meta_data( '_papelito_authoritative_total_cents', 5490 );
$paid_order->update_meta_data( PAPELITO_PAGARME_PAYMENT_STATE_META, 'paid' );
$paid_order->calculate_totals( false );
$paid_order->save();

$order_id = (int) $paid_order->get_id();
echo 'pedido de teste: #' . (int) $order_id . "\n";

$first = papelito_receipt_issue_for_order( $order_id );
assert_db( 'primeira emissao devolve recibo', false, is_wp_error( $first ) );

$second = papelito_receipt_issue_for_order( $order_id );
assert_db( 'segunda emissao nao cria outro recibo', false, is_wp_error( $second ) );
assert_db(
	'as duas emissoes devolvem o mesmo numero',
	is_array( $first ) ? $first['receipt_number'] : 'a',
	is_array( $second ) ? $second['receipt_number'] : 'b'
);

$count = (int) $wpdb->get_var(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['receipts']} WHERE order_id = %d", $order_id )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
assert_db( 'exatamente uma linha por pedido', 1, $count );

if ( is_array( $first ) ) {
	assert_db( 'valores em centavos preservados', 5490, (int) $first['total_cents'] );
	assert_db( 'origem e o pagamento', 'payment', (string) $first['origin'] );
	assert_db( 'numero segue o formato', 1, preg_match( '/^PPL-\d{4}-\d{6,}$/', (string) $first['receipt_number'] ) );

	$parts = papelito_receipt_vendor_parts( (int) $first['id'] );
	assert_db( 'uma parcela de vendor', 1, count( $parts ) );
	assert_db( 'parcela soma ao total do recibo', (int) $first['total_cents'], array_sum( array_map( 'intval', array_column( $parts, 'total_cents' ) ) ) );
	assert_db( 'parcela guarda o vendor', 4321, (int) $parts[0]['vendor_id'] );

	// Mutar o pedido depois da emissão não pode alterar o recibo.
	$paid_order->update_meta_data( '_papelito_authoritative_total_cents', 999999 );
	$paid_order->set_shipping_total( 100.00 );
	$paid_order->calculate_totals( false );
	$paid_order->save();

	$after = papelito_receipt_get_by_order( $order_id );
	assert_db( 'mutar o pedido nao muda os centavos do recibo', (int) $first['total_cents'], (int) $after['total_cents'] );
	assert_db( 'mutar o pedido nao muda o snapshot', (string) $first['snapshot_json'], (string) $after['snapshot_json'] );

	$wpdb->query(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "DELETE FROM {$tables['vendor_parts']} WHERE receipt_id = %d", (int) $first['id'] )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "DELETE FROM {$tables['receipts']} WHERE id = %d", (int) $first['id'] )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// Pedido não pago não gera recibo.
$unpaid_order = wc_create_order( array( 'created_via' => 'papelito_receipt_test' ) );
$unpaid_order->update_meta_data( PAPELITO_PAGARME_PAYMENT_STATE_META, 'pending' );
$unpaid_order->save();
$unpaid_result = papelito_receipt_issue_for_order( (int) $unpaid_order->get_id() );
assert_db( 'pedido nao pago e recusado', 'papelito_receipt_payment_not_confirmed', is_wp_error( $unpaid_result ) ? $unpaid_result->get_error_code() : 'emitiu' );
$unpaid_order->delete( true );

$paid_order->delete( true );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
