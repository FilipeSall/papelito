<?php
/**
 * Standalone tests for the receipt backfill orchestration.
 *
 * A selecao de candidatos e SQL e vive em test-receipts-backfill-db.php. Aqui
 * ficam idempotencia entre runs, dry-run, contabilidade e checkpoint.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );

function add_action() {}
function absint( mixed $value ): int { return abs( (int) $value ); }
function wp_json_encode( mixed $value ) { return json_encode( $value ); }
function get_option( string $key, mixed $default = false ): mixed { return $default; }
function wp_next_scheduled( string $hook, array $args = array() ) { return false; }

$scheduled_events = array();
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
	global $scheduled_events;
	$scheduled_events[] = array( 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args );
	return true;
}

/**
 * Erro minimo, so para o backfill distinguir sucesso de falha.
 */
class WP_Error {
	public function __construct( private string $code = '' ) {}
	public function get_error_code() { return $this->code; }
}

$receipt_store  = array();
$issue_calls    = array();
$next_sequence  = 1;
$unissuable_ids = array( 9001 );

function papelito_receipt_get_by_order( int $order_id ): ?array {
	global $receipt_store;
	return $receipt_store[ $order_id ] ?? null;
}

function papelito_receipts_table_names(): array {
	global $wpdb;
	return array( 'receipts' => $wpdb->prefix . 'papelito_receipts' );
}

function papelito_receipt_issue_for_order( int $order_id, string $origin = 'payment' ) {
	global $receipt_store, $issue_calls, $next_sequence, $unissuable_ids;
	$issue_calls[] = $order_id;

	if ( in_array( $order_id, $unissuable_ids, true ) ) {
		return new WP_Error( 'papelito_receipt_payment_not_confirmed' );
	}

	$receipt_store[ $order_id ] = array(
		'id'             => $order_id,
		'order_id'       => $order_id,
		'receipt_number' => sprintf( 'PPL-2025-%06d', $next_sequence ),
		'origin'         => $origin,
		'paid_at'        => sprintf( '2025-01-%02d 10:00:00', $next_sequence ),
	);
	++$next_sequence;

	return $receipt_store[ $order_id ];
}

require __DIR__ . '/../includes/receipts_backfill.php';

$failures = 0;

/**
 * Compara valores e contabiliza falhas.
 *
 * @param mixed $expected Valor esperado.
 * @param mixed $actual   Valor obtido.
 */
function assert_backfill( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

/**
 * Candidatos na ordem que o SQL devolveria.
 *
 * @param array<int,int> $order_ids Pedidos em ordem de pagamento.
 * @return array<int,array{order_id:int,sort_ts:int}>
 */
function backfill_candidates( array $order_ids ): array {
	$candidates = array();
	foreach ( $order_ids as $index => $order_id ) {
		$candidates[] = array(
			'order_id' => $order_id,
			'sort_ts'  => 1700000000 + $index,
		);
	}
	return $candidates;
}

// 1. Dry-run nao emite, nao consome numero e conta o que faria.
$candidates = backfill_candidates( array( 101, 102, 103 ) );
$dry        = papelito_receipts_backfill_process( $candidates, true );

assert_backfill( 'dry-run varre todos', 3, $dry['scanned'] );
assert_backfill( 'dry-run contaria tres emissoes', 3, $dry['would_issue'] );
assert_backfill( 'dry-run nao emite', 0, $dry['issued'] );
assert_backfill( 'dry-run nao chama a emissao', array(), $issue_calls );
assert_backfill( 'dry-run nao consome numero', 1, $next_sequence );
assert_backfill( 'dry-run se identifica', true, $dry['dry_run'] );

// 2. Primeiro run real emite na ordem recebida.
$first = papelito_receipts_backfill_process( $candidates, false );

assert_backfill( 'primeiro run emite tres', 3, $first['issued'] );
assert_backfill( 'primeiro run nao pula ninguem', 0, $first['skipped'] );
assert_backfill( 'primeiro run nao falha', 0, $first['failed'] );
assert_backfill( 'emissao segue a ordem de pagamento', array( 101, 102, 103 ), $issue_calls );
assert_backfill( 'numeracao segue a ordem', 'PPL-2025-000001', $receipt_store[101]['receipt_number'] );
assert_backfill( 'ultimo numero do lote', 'PPL-2025-000003', $first['last_receipt_number'] );
assert_backfill( 'ultimo pedido do lote', 103, $first['last_order_id'] );
assert_backfill( 'origem e o backfill', 'backfill', $receipt_store[101]['origin'] );

// 3. Segundo run sobre os mesmos pedidos nao cria recibo nem consome numero.
$issue_calls_before = count( $issue_calls );
$sequence_before    = $next_sequence;
$second             = papelito_receipts_backfill_process( $candidates, false );

assert_backfill( 'segundo run nao emite nada', 0, $second['issued'] );
assert_backfill( 'segundo run pula os tres', 3, $second['skipped'] );
assert_backfill( 'segundo run nao chama a emissao', $issue_calls_before, count( $issue_calls ) );
assert_backfill( 'segundo run nao consome numero', $sequence_before, $next_sequence );

// 4. Pedido que a emissao recusa e contabilizado e sinalizado, sem parar o lote.
$mixed = papelito_receipts_backfill_process( backfill_candidates( array( 9001, 104 ) ), false );

assert_backfill( 'lote com falha continua', 1, $mixed['issued'] );
assert_backfill( 'falha e contabilizada', 1, $mixed['failed'] );
assert_backfill( 'falha e identificada', array( 9001 ), $mixed['failed_order_ids'] );
assert_backfill( 'pedido seguinte foi emitido', true, isset( $receipt_store[104] ) );

// 5. Candidato invalido nao entra na contagem.
$empty = papelito_receipts_backfill_process( array( array( 'order_id' => 0, 'sort_ts' => 1 ) ), false );
assert_backfill( 'candidato sem id e ignorado', 0, $empty['scanned'] );

// 6. Checkpoint acumula e guarda quem travou.
$state = array(
	'last_run_at'         => '',
	'last_order_id'       => 0,
	'last_paid_at'        => '',
	'last_receipt_number' => '',
	'total_issued'        => 10,
	'total_skipped'       => 2,
	'total_failed'        => 1,
	'blocked_order_ids'   => array( 777 ),
);

$merged = papelito_receipts_backfill_merge_state( $state, $mixed, '2026-07-30 12:00:00' );

assert_backfill( 'checkpoint soma emissoes', 11, $merged['total_issued'] );
assert_backfill( 'checkpoint soma falhas', 2, $merged['total_failed'] );
assert_backfill( 'checkpoint acumula bloqueados', array( 777, 9001 ), $merged['blocked_order_ids'] );
assert_backfill( 'checkpoint guarda o run', '2026-07-30 12:00:00', $merged['last_run_at'] );
assert_backfill( 'checkpoint guarda o ultimo pedido', 104, $merged['last_order_id'] );

// Lote sem emissao preserva o ultimo pedido conhecido.
$kept = papelito_receipts_backfill_merge_state( $merged, $second, '2026-07-30 13:00:00' );
assert_backfill( 'lote vazio preserva o ultimo pedido', 104, $kept['last_order_id'] );
assert_backfill( 'lote vazio preserva o ultimo numero', $merged['last_receipt_number'], $kept['last_receipt_number'] );
assert_backfill( 'lote vazio nao duplica bloqueado', array( 777, 9001 ), $kept['blocked_order_ids'] );

// Lista de bloqueados nao cresce sem limite.

$overflow = papelito_receipts_backfill_merge_state(
	array( 'blocked_order_ids' => range( 1, 250 ) ),
	$mixed,
	'2026-07-30 14:00:00'
);
assert_backfill( 'bloqueados persistem sem truncar', 251, count( $overflow['blocked_order_ids'] ) );

// 7. Tamanho de lote.
assert_backfill( 'lote padrao quando ausente', PAPELITO_RECEIPTS_BACKFILL_DEFAULT_BATCH, papelito_receipts_backfill_clamp_batch( 0 ) );
assert_backfill( 'lote negativo cai no padrao', PAPELITO_RECEIPTS_BACKFILL_DEFAULT_BATCH, papelito_receipts_backfill_clamp_batch( -5 ) );
assert_backfill( 'lote respeita o teto', PAPELITO_RECEIPTS_BACKFILL_MAX_BATCH, papelito_receipts_backfill_clamp_batch( 100000 ) );
assert_backfill( 'lote pequeno e respeitado', 7, papelito_receipts_backfill_clamp_batch( 7 ) );

// 8. Estados elegiveis espelham o gate de pagamento.
assert_backfill( 'estados elegiveis', array( 'paid', 'captured' ), papelito_receipts_backfill_paid_states() );

class BackfillCountWpdb {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $prepared_sql = '';

	public function prepare( string $sql, ...$params ): string {
		$this->prepared_sql = $sql;
		return $sql;
	}

	public function get_results( string $sql, string $output ): array {
		return array_fill( 0, PAPELITO_RECEIPTS_BACKFILL_MAX_BATCH, array( 'order_id' => 1, 'sort_ts' => 1 ) );
	}

	public function get_var( string $sql ): int {
		$this->prepared_sql = $sql;
		return PAPELITO_RECEIPTS_BACKFILL_MAX_BATCH + 1;
	}
}

global $wpdb;
$wpdb = new BackfillCountWpdb();

assert_backfill( 'pendencias usam contagem sem limite de lote', PAPELITO_RECEIPTS_BACKFILL_MAX_BATCH + 1, papelito_receipts_backfill_pending_count() );
assert_backfill( 'scheduler de continuacao existe', true, function_exists( 'papelito_receipts_backfill_schedule_continuation' ) );
if ( function_exists( 'papelito_receipts_backfill_schedule_continuation' ) ) {
	papelito_receipts_backfill_schedule_continuation( 25 );
}
assert_backfill( 'scheduler agenda o proximo lote', 1, count( $scheduled_events ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
