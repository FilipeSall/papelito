<?php
/**
 * Backfill idempotente de recibos para pedidos pagos anteriores a receipts.php.
 *
 * Nao escreve nada no pedido: status, total e postmeta ficam intocados. A unica
 * gravacao e a do proprio recibo, pela emissao idempotente de receipts.php.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_RECEIPTS_BACKFILL_STATE_OPTION' ) ) {
	define( 'PAPELITO_RECEIPTS_BACKFILL_STATE_OPTION', 'papelito_receipts_backfill_state' );
}

if ( ! defined( 'PAPELITO_RECEIPTS_BACKFILL_DEFAULT_BATCH' ) ) {
	define( 'PAPELITO_RECEIPTS_BACKFILL_DEFAULT_BATCH', 50 );
}

if ( ! defined( 'PAPELITO_RECEIPTS_BACKFILL_MAX_BATCH' ) ) {
	define( 'PAPELITO_RECEIPTS_BACKFILL_MAX_BATCH', 500 );
}

if ( ! defined( 'PAPELITO_RECEIPTS_BACKFILL_CRON_HOOK' ) ) {
	define( 'PAPELITO_RECEIPTS_BACKFILL_CRON_HOOK', 'papelito_receipts_backfill_continue' );
}

function papelito_receipts_backfill_clamp_batch( int $batch ): int {
	if ( $batch <= 0 ) {
		return PAPELITO_RECEIPTS_BACKFILL_DEFAULT_BATCH;
	}

	return min( PAPELITO_RECEIPTS_BACKFILL_MAX_BATCH, $batch );
}

/**
 * Estados Pagar.me elegiveis. Espelha papelito_pagarme_payment_state_is_paid().
 *
 * @return array<int,string>
 */
function papelito_receipts_backfill_paid_states(): array {
	return array( 'paid', 'captured' );
}

function papelito_receipts_backfill_uses_hpos(): bool {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		return false;
	}

	return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Checkpoint persistido do backfill. Serve para retomada e para o runbook.
 *
 * @return array<string,mixed>
 */
function papelito_receipts_backfill_state(): array {
	$state = get_option( PAPELITO_RECEIPTS_BACKFILL_STATE_OPTION, array() );
	$state = is_array( $state ) ? $state : array();

	return array(
		'last_run_at'         => (string) ( $state['last_run_at'] ?? '' ),
		'last_order_id'       => (int) ( $state['last_order_id'] ?? 0 ),
		'last_paid_at'        => (string) ( $state['last_paid_at'] ?? '' ),
		'last_receipt_number' => (string) ( $state['last_receipt_number'] ?? '' ),
		'total_issued'        => (int) ( $state['total_issued'] ?? 0 ),
		'total_skipped'       => (int) ( $state['total_skipped'] ?? 0 ),
		'total_failed'        => (int) ( $state['total_failed'] ?? 0 ),
		'blocked_order_ids'   => array_values( array_filter( array_map( 'absint', (array) ( $state['blocked_order_ids'] ?? array() ) ) ) ),
		'batch_size'          => papelito_receipts_backfill_clamp_batch( (int) ( $state['batch_size'] ?? PAPELITO_RECEIPTS_BACKFILL_DEFAULT_BATCH ) ),
	);
}

/**
 * @param array<string,mixed> $state Checkpoint completo.
 */
function papelito_receipts_backfill_save_state( array $state ): void {
	update_option( PAPELITO_RECEIPTS_BACKFILL_STATE_OPTION, $state, false );
}

function papelito_receipts_backfill_reset_state(): void {
	delete_option( PAPELITO_RECEIPTS_BACKFILL_STATE_OPTION );
}

/**
 * Pedidos pagos ainda sem recibo, do pagamento mais antigo para o mais novo.
 *
 * A exclusao de quem ja tem recibo e feita no proprio SQL: cada lote avanca
 * sozinho, sem depender do checkpoint para nao reprocessar. Pedido sem
 * `date_paid` ordena pela data de criacao, igual ao ano da numeracao em
 * papelito_receipt_sequence_year().
 *
 * @param array<int,int> $exclude_order_ids Pedidos que travaram em execucoes anteriores.
 * @return array<int,array{order_id:int,sort_ts:int}>
 */
function papelito_receipts_backfill_candidates( int $limit, array $exclude_order_ids = array() ): array {
	global $wpdb;

	$limit    = papelito_receipts_backfill_clamp_batch( $limit );
	$tables   = papelito_receipts_table_names();
	$states   = papelito_receipts_backfill_paid_states();
	$excluded = array_values( array_unique( array_filter( array_map( 'absint', $exclude_order_ids ) ) ) );

	$state_placeholders = implode( ', ', array_fill( 0, count( $states ), '%s' ) );
	$exclude_sql        = '';
	$params             = $states;

	if ( ! empty( $excluded ) ) {
		$exclude_sql = ' AND o.id NOT IN ( ' . implode( ', ', array_fill( 0, count( $excluded ), '%d' ) ) . ' )';
		$params      = array_merge( $params, $excluded );
	}

	$params[] = $limit;

	if ( papelito_receipts_backfill_uses_hpos() ) {
		$sql = "SELECT o.id AS order_id,
				COALESCE( UNIX_TIMESTAMP( d.date_paid_gmt ), UNIX_TIMESTAMP( o.date_created_gmt ), 0 ) AS sort_ts
			FROM {$wpdb->prefix}wc_orders o
			INNER JOIN {$wpdb->prefix}wc_orders_meta m
				ON m.order_id = o.id AND m.meta_key = '_papelito_pagarme_payment_state'
			LEFT JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = o.id
			LEFT JOIN {$tables['receipts']} r ON r.order_id = o.id
			WHERE o.type = 'shop_order'
				AND o.status NOT IN ( 'trash', 'auto-draft' )
				AND m.meta_value IN ( {$state_placeholders} )
				AND r.id IS NULL{$exclude_sql}
			ORDER BY sort_ts ASC, o.id ASC
			LIMIT %d";
	} else {
		$sql = "SELECT o.ID AS order_id,
				COALESCE( NULLIF( CAST( paid.meta_value AS UNSIGNED ), 0 ), UNIX_TIMESTAMP( o.post_date_gmt ), 0 ) AS sort_ts
			FROM {$wpdb->posts} o
			INNER JOIN {$wpdb->postmeta} m
				ON m.post_id = o.ID AND m.meta_key = '_papelito_pagarme_payment_state'
			LEFT JOIN {$wpdb->postmeta} paid
				ON paid.post_id = o.ID AND paid.meta_key = '_date_paid'
			LEFT JOIN {$tables['receipts']} r ON r.order_id = o.ID
			WHERE o.post_type = 'shop_order'
				AND o.post_status NOT IN ( 'trash', 'auto-draft' )
				AND m.meta_value IN ( {$state_placeholders} )
				AND r.id IS NULL{$exclude_sql}
			ORDER BY sort_ts ASC, o.ID ASC
			LIMIT %d";
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- $sql so tem nome de tabela do $wpdb e placeholders montados acima; os valores vao em $params.
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$candidates = array();
	foreach ( $rows as $row ) {
		$candidates[] = array(
			'order_id' => (int) $row['order_id'],
			'sort_ts'  => (int) $row['sort_ts'],
		);
	}

	return $candidates;
}

function papelito_receipts_backfill_pending_count(): int {
	global $wpdb;

	$tables   = papelito_receipts_table_names();
	$states   = papelito_receipts_backfill_paid_states();
	$state     = papelito_receipts_backfill_state();
	$excluded  = array_values( array_unique( array_filter( array_map( 'absint', $state['blocked_order_ids'] ) ) ) );
	$placeholders = implode( ', ', array_fill( 0, count( $states ), '%s' ) );
	$exclude_sql  = '';
	$params       = $states;

	if ( ! empty( $excluded ) ) {
		$exclude_sql = ' AND o.id NOT IN ( ' . implode( ', ', array_fill( 0, count( $excluded ), '%d' ) ) . ' )';
		$params      = array_merge( $params, $excluded );
	}

	if ( papelito_receipts_backfill_uses_hpos() ) {
		$sql = "SELECT COUNT(DISTINCT o.id)
			FROM {$wpdb->prefix}wc_orders o
			INNER JOIN {$wpdb->prefix}wc_orders_meta m
				ON m.order_id = o.id AND m.meta_key = '_papelito_pagarme_payment_state'
			LEFT JOIN {$tables['receipts']} r ON r.order_id = o.id
			WHERE o.type = 'shop_order'
				AND o.status NOT IN ( 'trash', 'auto-draft' )
				AND m.meta_value IN ( {$placeholders} )
				AND r.id IS NULL{$exclude_sql}";
	} else {
		$sql = "SELECT COUNT(DISTINCT o.ID)
			FROM {$wpdb->posts} o
			INNER JOIN {$wpdb->postmeta} m
				ON m.post_id = o.ID AND m.meta_key = '_papelito_pagarme_payment_state'
			LEFT JOIN {$tables['receipts']} r ON r.order_id = o.ID
			WHERE o.post_type = 'shop_order'
				AND o.post_status NOT IN ( 'trash', 'auto-draft' )
				AND m.meta_value IN ( {$placeholders} )
				AND r.id IS NULL{$exclude_sql}";
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- $sql so tem nomes de tabela do $wpdb e placeholders montados acima; os valores vao em $params.
	return max( 0, (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
}

/**
 * Emite os recibos de uma lista de candidatos ja ordenada.
 *
 * Separada da consulta para poder ser exercitada sem banco. Em dry-run nao
 * grava recibo, nao consome numero e nao toca no checkpoint.
 *
 * @param array<int,array{order_id:int,sort_ts:int}> $candidates Candidatos em ordem de pagamento.
 * @return array<string,mixed>
 */
function papelito_receipts_backfill_process( array $candidates, bool $dry_run ): array {
	$summary = array(
		'scanned'             => 0,
		'issued'              => 0,
		'skipped'             => 0,
		'failed'              => 0,
		'would_issue'         => 0,
		'dry_run'             => $dry_run,
		'last_order_id'       => 0,
		'last_paid_at'        => '',
		'last_receipt_number' => '',
		'failed_order_ids'    => array(),
	);

	foreach ( $candidates as $candidate ) {
		$order_id = (int) ( $candidate['order_id'] ?? 0 );

		if ( $order_id <= 0 ) {
			continue;
		}

		++$summary['scanned'];

		$existing = papelito_receipt_get_by_order( $order_id );

		if ( is_array( $existing ) ) {
			++$summary['skipped'];
			continue;
		}

		if ( $dry_run ) {
			++$summary['would_issue'];
			continue;
		}

		$issued = papelito_receipt_issue_for_order( $order_id, 'backfill' );

		if ( ! is_array( $issued ) ) {
			++$summary['failed'];
			$summary['failed_order_ids'][] = $order_id;
			continue;
		}

		++$summary['issued'];
		$summary['last_order_id']       = $order_id;
		$summary['last_paid_at']        = (string) ( $issued['paid_at'] ?? '' );
		$summary['last_receipt_number'] = (string) ( $issued['receipt_number'] ?? '' );
	}

	return $summary;
}

/**
 * Atualiza o checkpoint a partir do resumo de um lote aplicado.
 *
 * @param array<string,mixed> $state   Checkpoint anterior.
 * @param array<string,mixed> $summary Resumo do lote.
 * @return array<string,mixed>
 */
function papelito_receipts_backfill_merge_state( array $state, array $summary, string $now ): array {
	$blocked = array_values(
		array_unique(
			array_merge(
				array_map( 'absint', (array) ( $state['blocked_order_ids'] ?? array() ) ),
				array_map( 'absint', (array) ( $summary['failed_order_ids'] ?? array() ) )
			)
		)
	);

	return array(
		'last_run_at'         => $now,
		'last_order_id'       => (int) $summary['last_order_id'] > 0 ? (int) $summary['last_order_id'] : (int) ( $state['last_order_id'] ?? 0 ),
		'last_paid_at'        => '' !== (string) $summary['last_paid_at'] ? (string) $summary['last_paid_at'] : (string) ( $state['last_paid_at'] ?? '' ),
		'last_receipt_number' => '' !== (string) $summary['last_receipt_number'] ? (string) $summary['last_receipt_number'] : (string) ( $state['last_receipt_number'] ?? '' ),
		'total_issued'        => (int) ( $state['total_issued'] ?? 0 ) + (int) $summary['issued'],
		'total_skipped'       => (int) ( $state['total_skipped'] ?? 0 ) + (int) $summary['skipped'],
		'total_failed'        => (int) ( $state['total_failed'] ?? 0 ) + (int) $summary['failed'],
		'blocked_order_ids'   => $blocked,
	);
}

/**
 * Agenda um unico lote de continuacao quando ainda ha pedidos elegiveis.
 */
function papelito_receipts_backfill_schedule_continuation( int $batch ): void {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) || papelito_receipts_backfill_pending_count() <= 0 ) {
		return;
	}

	if ( ! wp_next_scheduled( PAPELITO_RECEIPTS_BACKFILL_CRON_HOOK ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, PAPELITO_RECEIPTS_BACKFILL_CRON_HOOK );
	}
}

function papelito_receipts_backfill_clear_continuation(): void {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( PAPELITO_RECEIPTS_BACKFILL_CRON_HOOK );
	}
}

/**
 * Um lote de backfill: consulta, emite e grava o checkpoint.
 *
 * @return array<string,mixed>
 */
function papelito_receipts_backfill_run( int $batch, bool $dry_run ): array {
	$state      = papelito_receipts_backfill_state();
	$candidates = papelito_receipts_backfill_candidates( $batch, $state['blocked_order_ids'] );
	$summary    = papelito_receipts_backfill_process( $candidates, $dry_run );

	$summary['blocked_before'] = count( $state['blocked_order_ids'] );

	if ( ! $dry_run ) {
		$next_state               = papelito_receipts_backfill_merge_state( $state, $summary, current_time( 'mysql', true ) );
		$next_state['batch_size'] = papelito_receipts_backfill_clamp_batch( $batch );
		papelito_receipts_backfill_save_state(
			$next_state
		);
		papelito_receipts_backfill_schedule_continuation( $batch );
	}

	return $summary;
}

function papelito_receipts_backfill_continue(): void {
	$state = papelito_receipts_backfill_state();
	papelito_receipts_backfill_run( (int) $state['batch_size'], false );
}
add_action( PAPELITO_RECEIPTS_BACKFILL_CRON_HOOK, 'papelito_receipts_backfill_continue' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * WP-CLI do backfill de recibos.
	 */
	class Papelito_Receipts_CLI {
		/**
		 * Emite recibos de pedidos pagos que ainda nao tem um.
		 *
		 * @param array<int,string>    $args       Argumentos posicionais.
		 * @param array<string,string> $assoc_args --dry-run, --batch=N.
		 */
		public function backfill( array $args, array $assoc_args ): void {
			$dry_run = ! empty( $assoc_args['dry-run'] );
			$batch   = papelito_receipts_backfill_clamp_batch( (int) ( $assoc_args['batch'] ?? PAPELITO_RECEIPTS_BACKFILL_DEFAULT_BATCH ) );

			if ( ! function_exists( 'papelito_receipt_issue_for_order' ) ) {
				WP_CLI::error( 'receipts.php nao esta carregado.' );
			}

			if ( ! $dry_run ) {
				WP_CLI::log( sprintf( 'Aplicando backfill em ate %d pedidos. Use --dry-run para simular.', $batch ) );
			}

			$summary = papelito_receipts_backfill_run( $batch, $dry_run );

			WP_CLI::success(
				sprintf(
					'scanned=%d issued=%d would_issue=%d skipped=%d failed=%d dry_run=%s last_receipt=%s',
					(int) $summary['scanned'],
					(int) $summary['issued'],
					(int) $summary['would_issue'],
					(int) $summary['skipped'],
					(int) $summary['failed'],
					$dry_run ? 'true' : 'false',
					'' !== (string) $summary['last_receipt_number'] ? (string) $summary['last_receipt_number'] : '-'
				)
			);

			if ( ! empty( $summary['failed_order_ids'] ) ) {
				WP_CLI::warning(
					sprintf(
						'Pedidos que falharam e ficam fora dos proximos lotes: %s. Investigue e rode `wp papelito receipts backfill-reset` para reincluir.',
						implode( ', ', array_map( 'absint', $summary['failed_order_ids'] ) )
					)
				);
			}
		}

		/**
		 * Mostra o checkpoint e quantos pedidos ainda faltam.
		 */
		public function backfill_status(): void {
			$state            = papelito_receipts_backfill_state();
			$state['pending'] = papelito_receipts_backfill_pending_count();
			$state['storage'] = papelito_receipts_backfill_uses_hpos() ? 'hpos' : 'posts';

			WP_CLI::success( wp_json_encode( $state ) );
		}

		/**
		 * Limpa o checkpoint. Nao apaga recibo nenhum.
		 */
		public function backfill_reset(): void {
			papelito_receipts_backfill_clear_continuation();
			papelito_receipts_backfill_reset_state();
			WP_CLI::success( 'checkpoint e continuacao agendada limpos; nenhum recibo foi alterado' );
		}
	}

	WP_CLI::add_command( 'papelito receipts', 'Papelito_Receipts_CLI' );
}
