<?php
/**
 * Integration checks for the receipt backfill candidate query.
 *
 * A selecao de candidatos e SQL — ordenacao por pagamento, exclusao de pedido
 * nao pago e de pedido que ja tem recibo. Precisa do WordPress carregado:
 *
 *   wp eval-file tests/test-receipts-backfill-db.php
 *
 * Cria pedidos descartaveis e apaga tudo no fim, inclusive os recibos emitidos.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-receipts-backfill-db.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * `wp eval-file` executa o arquivo dentro de uma função: variável de topo NÃO é
 * global. Sem declarar $failures aqui, o `global $failures` do assert apontaria
 * para outra variável e o teste sairia com código 0 mesmo falhando.
 */
global $wpdb, $failures;

$tables   = papelito_receipts_table_names();
$failures = 0;

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param mixed $expected Valor esperado.
 * @param mixed $actual   Valor obtido.
 */
function assert_backfill_db( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo 'PASS: ' . esc_html( $label ) . "\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . esc_html( $label ) . ' (expected ' . esc_html( var_export( $expected, true ) ) . ', got ' . esc_html( var_export( $actual, true ) ) . ")\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
}

/**
 * Pedido descartavel com um item e um estado Pagar.me.
 */
function backfill_db_make_order( string $payment_state, ?int $paid_offset_days ): WC_Order {
	$order = wc_create_order( array( 'created_via' => 'papelito_backfill_test' ) );

	$item = new WC_Order_Item_Product();
	$item->set_name( 'Item de backfill' );
	$item->set_quantity( 1 );
	$item->set_subtotal( 10.00 );
	$item->set_total( 10.00 );
	$item->add_meta_data( '_vendor_id', 4321, true );
	$item->add_meta_data( '_vendor_name', 'Vendor de teste', true );
	$item->add_meta_data( '_papelito_subtotal_cents', 1000, true );
	$item->add_meta_data( '_papelito_discount_cents', 0, true );
	$item->add_meta_data( '_papelito_total_cents', 1000, true );
	$order->add_item( $item );

	$order->update_meta_data( '_papelito_vendor_id', 4321 );
	$order->update_meta_data( '_papelito_vendor_name', 'Vendor de teste' );
	$order->update_meta_data( PAPELITO_PAGARME_PAYMENT_STATE_META, $payment_state );

	if ( null !== $paid_offset_days ) {
		$order->set_date_paid( time() - ( $paid_offset_days * DAY_IN_SECONDS ) );
	}

	$order->calculate_totals( false );
	$order->save();

	return $order;
}

/**
 * Ids dos candidatos, na ordem devolvida pela consulta.
 *
 * @param array<int,int> $only Pedidos do teste, para ignorar o resto da base.
 * @return array<int,int>
 */
function backfill_db_candidate_ids( array $only ): array {
	return array_column( backfill_db_test_candidates( $only ), 'order_id' );
}

/**
 * Candidatos do teste, filtrados antes de qualquer emissao.
 *
 * @param array<int,int> $only Pedidos que pertencem ao teste.
 * @return array<int,array{order_id:int,sort_ts:int}>
 */
function backfill_db_test_candidates( array $only ): array {
	$candidates = array();
	foreach ( papelito_receipts_backfill_candidates( PAPELITO_RECEIPTS_BACKFILL_MAX_BATCH ) as $candidate ) {
		if ( in_array( (int) $candidate['order_id'], $only, true ) ) {
			$candidates[] = $candidate;
		}
	}

	return $candidates;
}

// Pago ha 30 dias, ha 10 dias e ha 20 dias — criados fora de ordem de propósito.
$oldest = backfill_db_make_order( 'paid', 30 );
$newest = backfill_db_make_order( 'captured', 10 );
$middle = backfill_db_make_order( 'paid', 20 );
$unpaid = backfill_db_make_order( 'pending', null );
$outside = backfill_db_make_order( 'paid', 40 );

$oldest_id = (int) $oldest->get_id();
$newest_id = (int) $newest->get_id();
$middle_id = (int) $middle->get_id();
$unpaid_id = (int) $unpaid->get_id();
$outside_id = (int) $outside->get_id();
$test_ids  = array( $oldest_id, $newest_id, $middle_id, $unpaid_id );

echo 'pedidos de teste: ' . esc_html( implode( ', ', $test_ids ) ) . "\n";
echo 'storage: ' . ( papelito_receipts_backfill_uses_hpos() ? "hpos\n" : "posts\n" );

assert_backfill_db(
	'ordena por data de pagamento, nao por id',
	array( $oldest_id, $middle_id, $newest_id ),
	backfill_db_candidate_ids( $test_ids )
);

// Dry-run nao pode gravar nada.
$test_candidates = backfill_db_test_candidates( $test_ids );
$dry             = papelito_receipts_backfill_process( $test_candidates, true );
assert_backfill_db( 'dry-run nao emite', 0, (int) $dry['issued'] );
assert_backfill_db( 'dry-run nao criou recibo para o mais antigo', null, papelito_receipt_get_by_order( $oldest_id ) );
assert_backfill_db(
	'candidatos continuam os mesmos apos o dry-run',
	array( $oldest_id, $middle_id, $newest_id ),
	backfill_db_candidate_ids( $test_ids )
);

// Primeiro run real.
$first = papelito_receipts_backfill_process( $test_candidates, false );
assert_backfill_db( 'primeiro run emitiu os tres pagos', 3, count( array_filter( array_map( 'papelito_receipt_get_by_order', array( $oldest_id, $middle_id, $newest_id ) ) ) ) );
assert_backfill_db( 'pedido nao pago nao recebeu recibo', null, papelito_receipt_get_by_order( $unpaid_id ) );
assert_backfill_db( 'pedido pago fora dos fixtures nao recebeu recibo', null, papelito_receipt_get_by_order( $outside_id ) );

$oldest_receipt = papelito_receipt_get_by_order( $oldest_id );
$middle_receipt = papelito_receipt_get_by_order( $middle_id );
$newest_receipt = papelito_receipt_get_by_order( $newest_id );

assert_backfill_db( 'origem marcada como backfill', 'backfill', (string) $oldest_receipt['origin'] );
assert_backfill_db(
	'numeracao cresce com a data de pagamento',
	true,
	(int) $oldest_receipt['sequence_number'] < (int) $middle_receipt['sequence_number']
		&& (int) $middle_receipt['sequence_number'] < (int) $newest_receipt['sequence_number']
);

// Quem ja tem recibo sai da fila.
assert_backfill_db( 'pedidos processados saem dos candidatos', array(), backfill_db_candidate_ids( $test_ids ) );

// Segundo run nao cria recibo extra nem consome numero.
$sequence_year = (int) $oldest_receipt['sequence_year'];
$next_before   = (int) $wpdb->get_var(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT next_sequence FROM {$tables['sequences']} WHERE sequence_year = %d", $sequence_year )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$second = papelito_receipts_backfill_process( backfill_db_test_candidates( $test_ids ), false );

$next_after = (int) $wpdb->get_var(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT next_sequence FROM {$tables['sequences']} WHERE sequence_year = %d", $sequence_year )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

assert_backfill_db( 'segundo run nao emite para os pedidos de teste', 0, count( array_intersect( $test_ids, (array) $second['failed_order_ids'] ) ) );
assert_backfill_db( 'segundo run nao consome numero do ano', $next_before, $next_after );

$count = (int) $wpdb->get_var(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['receipts']} WHERE order_id IN ( %d, %d, %d )", $oldest_id, $middle_id, $newest_id )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
assert_backfill_db( 'um recibo por pedido apos dois runs', 3, $count );

// O backfill nao encosta no pedido.
$reloaded = wc_get_order( $oldest_id );
assert_backfill_db( 'status do pedido intocado', $oldest->get_status(), $reloaded->get_status() );
assert_backfill_db( 'total do pedido intocado', (float) $oldest->get_total(), (float) $reloaded->get_total() );

// Limpeza.
foreach ( array( $oldest_id, $middle_id, $newest_id ) as $order_id ) {
	$receipt = papelito_receipt_get_by_order( $order_id );
	if ( is_array( $receipt ) ) {
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
			$wpdb->prepare( "DELETE FROM {$tables['vendor_parts']} WHERE receipt_id = %d", (int) $receipt['id'] )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
			$wpdb->prepare( "DELETE FROM {$tables['receipts']} WHERE id = %d", (int) $receipt['id'] )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

foreach ( array( $oldest, $middle, $newest, $unpaid, $outside ) as $test_order ) {
	$test_order->delete( true );
}

echo "limpeza ok\n";

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
