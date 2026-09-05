<?php
/**
 * Recibo interno persistido: numeração anual, snapshot imutável e parcelas por vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_RECEIPT_SNAPSHOT_VERSION' ) ) {
	define( 'PAPELITO_RECEIPT_SNAPSHOT_VERSION', 1 );
}

if ( ! defined( 'PAPELITO_RECEIPT_NUMBER_PREFIX' ) ) {
	define( 'PAPELITO_RECEIPT_NUMBER_PREFIX', 'PPL' );
}

if ( ! defined( 'PAPELITO_RECEIPT_ISSUE_MAX_ATTEMPTS' ) ) {
	define( 'PAPELITO_RECEIPT_ISSUE_MAX_ATTEMPTS', 3 );
}

/**
 * Falha de gravacao do recibo, capturada pelo rollback da transacao de emissao.
 */
class PapelitoReceiptPersistenceException extends RuntimeException {}

/**
 * Nomes das tabelas do recibo, já com o prefixo do $wpdb.
 *
 * @return array<string,string>
 */
function papelito_receipts_table_names(): array {
	global $wpdb;

	return array(
		'sequences'    => $wpdb->prefix . 'papelito_receipt_sequences',
		'receipts'     => $wpdb->prefix . 'papelito_receipts',
		'vendor_parts' => $wpdb->prefix . 'papelito_receipt_vendor_parts',
	);
}

function papelito_receipts_install_tables(): void {
	global $wpdb;

	$tables          = papelito_receipts_table_names();
	$charset_collate = $wpdb->get_charset_collate();

	$sequences_sql = "CREATE TABLE {$tables['sequences']} (
  sequence_year SMALLINT UNSIGNED NOT NULL,
  next_sequence BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (sequence_year)
) {$charset_collate};";

	$receipts_sql = "CREATE TABLE {$tables['receipts']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_number VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  sequence_year SMALLINT UNSIGNED NOT NULL,
  sequence_number BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  buyer_label VARCHAR(255) NOT NULL DEFAULT '',
  company_id BIGINT UNSIGNED NULL DEFAULT NULL,
  company_cnpj CHAR(14) NULL DEFAULT NULL,
  company_legal_name VARCHAR(255) NULL DEFAULT NULL,
  payment_method VARCHAR(64) NOT NULL DEFAULT '',
  payment_method_title VARCHAR(191) NOT NULL DEFAULT '',
  payment_state VARCHAR(32) NOT NULL DEFAULT '',
  order_status VARCHAR(32) NOT NULL DEFAULT '',
  currency CHAR(3) NOT NULL DEFAULT 'BRL',
  subtotal_cents BIGINT NOT NULL DEFAULT 0,
  discount_cents BIGINT NOT NULL DEFAULT 0,
  shipping_cents BIGINT NOT NULL DEFAULT 0,
  total_cents BIGINT NOT NULL DEFAULT 0,
  snapshot_json LONGTEXT NOT NULL,
  snapshot_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  origin VARCHAR(16) NOT NULL DEFAULT 'payment',
  ordered_at DATETIME NULL DEFAULT NULL,
  paid_at DATETIME NULL DEFAULT NULL,
  issued_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_order (order_id),
  UNIQUE KEY uniq_receipt_number (receipt_number),
  KEY idx_customer_issued (customer_user_id, issued_at),
  KEY idx_issued_at (issued_at),
  KEY idx_company (company_id),
  KEY idx_origin (origin)
) {$charset_collate};";

	$vendor_parts_sql = "CREATE TABLE {$tables['vendor_parts']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  vendor_name VARCHAR(255) NOT NULL DEFAULT '',
  part_ordinal SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  part_number VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  subtotal_cents BIGINT NOT NULL DEFAULT 0,
  discount_cents BIGINT NOT NULL DEFAULT 0,
  shipping_cents BIGINT NOT NULL DEFAULT 0,
  total_cents BIGINT NOT NULL DEFAULT 0,
  items_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_receipt_vendor (receipt_id, vendor_id),
  KEY idx_vendor (vendor_id),
  KEY idx_receipt_ordinal (receipt_id, part_ordinal)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sequences_sql );
	dbDelta( $receipts_sql );
	dbDelta( $vendor_parts_sql );

	papelito_db_align_binary_columns(
		$tables['receipts'],
		array(
			'receipt_number' => array(
				'type'       => 'VARCHAR(32)',
				'attributes' => 'NOT NULL',
			),
		)
	);
	papelito_db_align_binary_columns(
		$tables['vendor_parts'],
		array(
			'part_number' => array(
				'type'       => 'VARCHAR(40)',
				'attributes' => 'NOT NULL',
			),
		)
	);
}

function papelito_receipt_format_number( int $year, int $sequence ): string {
	return sprintf( '%s-%04d-%06d', PAPELITO_RECEIPT_NUMBER_PREFIX, $year, $sequence );
}

/**
 * Reparte um valor em centavos proporcionalmente aos pesos, sem perder nem criar centavo.
 *
 * @param array<int,int> $weights Pesos por posição.
 * @return array<int,int>
 */
function papelito_receipt_allocate_cents( int $amount_cents, array $weights ): array {
	$positions = array_keys( $weights );
	$count     = count( $positions );

	if ( 0 === $count || $amount_cents <= 0 ) {
		return array_fill_keys( $positions, 0 );
	}

	$total_weight = 0;
	foreach ( $weights as $weight ) {
		$total_weight += max( 0, (int) $weight );
	}

	$allocation = array();
	$remaining  = $amount_cents;
	$last       = $count - 1;

	foreach ( $positions as $index => $position ) {
		if ( $index === $last ) {
			$share = $remaining;
		} elseif ( $total_weight > 0 ) {
			$share = (int) round( max( 0, (int) $weights[ $position ] ) * $amount_cents / $total_weight );
		} else {
			$share = (int) round( $amount_cents / $count );
		}

		$share                   = max( 0, min( $share, $remaining ) );
		$allocation[ $position ] = $share;
		$remaining              -= $share;
	}

	return $allocation;
}

function papelito_receipt_item_cents( object $item, string $meta_key, float $fallback ): int {
	$stored = method_exists( $item, 'get_meta' ) ? $item->get_meta( $meta_key, true ) : '';

	if ( is_numeric( $stored ) && (int) $stored > 0 ) {
		return (int) $stored;
	}

	return function_exists( 'papelito_pricing_to_cents' )
		? papelito_pricing_to_cents( $fallback )
		: max( 0, (int) round( $fallback * 100 ) );
}

/**
 * Converte uma data do WooCommerce em datetime MySQL UTC.
 *
 * @param mixed $date Objeto de data do pedido, ou null.
 * @return string|null
 */
function papelito_receipt_mysql_date( $date ): ?string {
	if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
		return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
	}

	return null;
}

/**
 * Balde zerado de um vendor no snapshot.
 *
 * @param int    $vendor_id   Id do vendor.
 * @param string $vendor_name Nome do vendor no momento da emissao.
 * @return array<string,mixed>
 */
function papelito_receipt_new_vendor_bucket( int $vendor_id, string $vendor_name ): array {
	return array(
		'vendor_id'      => $vendor_id,
		'vendor_name'    => $vendor_name,
		'subtotal_cents' => 0,
		'discount_cents' => 0,
		'shipping_cents' => 0,
		'total_cents'    => 0,
		'items'          => array(),
	);
}

/**
 * Converte uma linha do pedido no item do snapshot, ou null quando nao e item utilizavel.
 *
 * @param mixed  $item              Linha do pedido vinda do WooCommerce.
 * @param int    $order_vendor_id   Vendor do pedido, usado quando a linha nao tem o proprio.
 * @param string $order_vendor_name Nome do vendor do pedido, usado no mesmo fallback.
 * @return array<string,mixed>|null
 */
function papelito_receipt_build_item_line( $item, int $order_vendor_id, string $order_vendor_name ): ?array {
	if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
		return null;
	}

	$item_vendor_id   = (int) $item->get_meta( '_vendor_id', true );
	$item_vendor_name = sanitize_text_field( (string) $item->get_meta( '_vendor_name', true ) );
	$quantity         = max( 1, (int) $item->get_quantity() );

	$item_subtotal   = papelito_receipt_item_cents( $item, '_papelito_subtotal_cents', (float) $item->get_subtotal() );
	$item_total      = papelito_receipt_item_cents( $item, '_papelito_total_cents', (float) $item->get_total() );
	$stored_discount = $item->get_meta( '_papelito_discount_cents', true );
	$item_discount   = is_numeric( $stored_discount )
		? max( 0, (int) $stored_discount )
		: max( 0, $item_subtotal - $item_total );

	return array(
		'name'             => sanitize_text_field( (string) $item->get_name() ),
		'quantity'         => $quantity,
		'unit_price_cents' => (int) round( $item_subtotal / $quantity ),
		'subtotal_cents'   => $item_subtotal,
		'discount_cents'   => $item_discount,
		'total_cents'      => $item_total,
		'discount_source'  => sanitize_key( (string) $item->get_meta( '_papelito_discount_source', true ) ),
		'vendor_id'        => $item_vendor_id > 0 ? $item_vendor_id : $order_vendor_id,
		'vendor_name'      => '' !== $item_vendor_name ? $item_vendor_name : $order_vendor_name,
	);
}

/**
 * Reparte o frete entre os vendors e fecha o total de cada um.
 *
 * @param array<int,array<string,mixed>> $vendors        Baldes por vendor.
 * @param int                            $shipping_cents Frete total em centavos.
 * @return array<int,array<string,mixed>>
 */
function papelito_receipt_apply_shipping_shares( array $vendors, int $shipping_cents ): array {
	$weights = array();
	foreach ( $vendors as $vendor_id => $vendor ) {
		$weights[ $vendor_id ] = max( 0, $vendor['subtotal_cents'] - $vendor['discount_cents'] );
	}

	foreach ( papelito_receipt_allocate_cents( $shipping_cents, $weights ) as $vendor_id => $share ) {
		$vendors[ $vendor_id ]['shipping_cents'] = $share;
		$vendors[ $vendor_id ]['total_cents']    = $vendors[ $vendor_id ]['subtotal_cents']
			- $vendors[ $vendor_id ]['discount_cents']
			+ $share;
	}

	return $vendors;
}

/**
 * Snapshot imutável a partir do pedido e das metas já persistidas.
 *
 * @return array<string,mixed>
 */
function papelito_receipt_build_snapshot( object $order ): array {
	$company_id   = (int) $order->get_meta( '_papelito_company_id', true );
	$company_cnpj = preg_replace( '/\D/', '', (string) $order->get_meta( '_papelito_company_cnpj', true ) );
	$company_name = sanitize_text_field( (string) $order->get_meta( '_papelito_company_legal_name', true ) );
	$is_b2b       = '' !== (string) $order->get_meta( '_papelito_company_snapshot_version', true );
	$buyer_label  = sanitize_text_field( (string) $order->get_billing_company() );

	if ( '' === $buyer_label ) {
		$buyer_label = sanitize_text_field( (string) $order->get_formatted_billing_full_name() );
	}

	$order_vendor_id   = (int) $order->get_meta( '_papelito_vendor_id', true );
	$order_vendor_name = sanitize_text_field( (string) $order->get_meta( '_papelito_vendor_name', true ) );

	$items          = array();
	$vendors        = array();
	$subtotal_cents = 0;
	$discount_cents = 0;

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$line = papelito_receipt_build_item_line( $item, $order_vendor_id, $order_vendor_name );
		if ( null === $line ) {
			continue;
		}

		$vendor_id = $line['vendor_id'];

		$items[]         = $line;
		$subtotal_cents += $line['subtotal_cents'];
		$discount_cents += $line['discount_cents'];

		if ( ! isset( $vendors[ $vendor_id ] ) ) {
			$vendors[ $vendor_id ] = papelito_receipt_new_vendor_bucket( $vendor_id, $line['vendor_name'] );
		}

		$vendors[ $vendor_id ]['subtotal_cents'] += $line['subtotal_cents'];
		$vendors[ $vendor_id ]['discount_cents'] += $line['discount_cents'];
		$vendors[ $vendor_id ]['items'][]         = $line;
	}

	if ( empty( $vendors ) ) {
		$vendors[ $order_vendor_id ] = papelito_receipt_new_vendor_bucket( $order_vendor_id, $order_vendor_name );
	}

	$shipping_cents = function_exists( 'papelito_pricing_to_cents' )
		? papelito_pricing_to_cents( (float) $order->get_shipping_total() )
		: max( 0, (int) round( (float) $order->get_shipping_total() * 100 ) );

	$vendors = papelito_receipt_apply_shipping_shares( $vendors, $shipping_cents );

	$total_cents         = $subtotal_cents - $discount_cents + $shipping_cents;
	$authoritative_cents = (int) $order->get_meta( '_papelito_authoritative_total_cents', true );

	return array(
		'version' => PAPELITO_RECEIPT_SNAPSHOT_VERSION,
		'order'   => array(
			'id'         => (int) $order->get_id(),
			'number'     => sanitize_text_field( (string) $order->get_order_number() ),
			'status'     => sanitize_key( (string) $order->get_status() ),
			'currency'   => sanitize_text_field( (string) $order->get_currency() ),
			'ordered_at' => papelito_receipt_mysql_date( $order->get_date_created() ),
			'paid_at'    => papelito_receipt_mysql_date( $order->get_date_paid() ),
		),
		'buyer'   => array(
			'user_id' => (int) $order->get_customer_id(),
			'label'   => $buyer_label,
			'is_b2b'  => $is_b2b,
		),
		'company' => $is_b2b && $company_id > 0
			? array(
				'id'         => $company_id,
				'cnpj'       => $company_cnpj,
				'legal_name' => $company_name,
			)
			: null,
		'payment' => array(
			'method'       => sanitize_text_field( (string) $order->get_payment_method() ),
			'method_title' => sanitize_text_field( (string) $order->get_payment_method_title() ),
			'state'        => sanitize_key( (string) $order->get_meta( '_papelito_pagarme_payment_state', true ) ),
		),
		'totals'  => array(
			'subtotal_cents'            => $subtotal_cents,
			'discount_cents'            => $discount_cents,
			'shipping_cents'            => $shipping_cents,
			'total_cents'               => $total_cents,
			'authoritative_total_cents' => $authoritative_cents,
			'totals_mismatch'           => $authoritative_cents > 0 && $authoritative_cents !== $total_cents,
		),
		'items'   => $items,
		'vendors' => array_values( $vendors ),
	);
}

/**
 * Recupera o recibo de um pedido.
 *
 * @return array<string,mixed>|null
 */
function papelito_receipt_get_by_order( int $order_id ): ?array {
	global $wpdb;

	$tables = papelito_receipts_table_names();
	$row    = $wpdb->get_row(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['receipts']} WHERE order_id = %d", $order_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return is_array( $row ) ? $row : null;
}

/**
 * Recupera o recibo por número, para busca de suporte e auditoria.
 *
 * @return array<string,mixed>|null
 */
function papelito_receipt_get_by_number( string $number ): ?array {
	global $wpdb;

	$number = strtoupper( trim( $number ) );
	if ( 1 !== preg_match( '/^[A-Z]{2,8}-\d{4}-\d{6,}$/', $number ) ) {
		return null;
	}

	$tables = papelito_receipts_table_names();
	$row    = $wpdb->get_row(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['receipts']} WHERE receipt_number = %s", $number ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return is_array( $row ) ? $row : null;
}

/**
 * Parcelas por vendor de um recibo, na ordem em que foram gravadas.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_receipt_vendor_parts( int $receipt_id ): array {
	global $wpdb;

	$tables = papelito_receipts_table_names();
	$rows   = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['vendor_parts']} WHERE receipt_id = %d ORDER BY part_ordinal ASC", $receipt_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return is_array( $rows ) ? $rows : array();
}

/**
 * Ano da numeração: segue o pagamento, para o sequencial anual ficar cronológico.
 */
function papelito_receipt_sequence_year( array $snapshot ): int {
	foreach ( array( 'paid_at', 'ordered_at' ) as $key ) {
		$value = (string) ( $snapshot['order'][ $key ] ?? '' );
		if ( '' !== $value && 1 === preg_match( '/^(\d{4})-/', $value, $match ) ) {
			return (int) $match[1];
		}
	}

	return (int) gmdate( 'Y' );
}

/**
 * Aloca o próximo sequencial do ano. Deve rodar dentro da transação do chamador.
 *
 * @throws PapelitoReceiptPersistenceException Quando a linha anual não pode ser lida ou incrementada.
 */
function papelito_receipt_claim_sequence( int $year ): int {
	global $wpdb;

	$tables = papelito_receipts_table_names();
	$row    = $wpdb->get_row(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT next_sequence FROM {$tables['sequences']} WHERE sequence_year = %d FOR UPDATE", $year ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( ! is_array( $row ) ) {
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
			$wpdb->prepare( "INSERT IGNORE INTO {$tables['sequences']} (sequence_year, next_sequence) VALUES (%d, 1)", $year )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
			$wpdb->prepare( "SELECT next_sequence FROM {$tables['sequences']} WHERE sequence_year = %d FOR UPDATE", $year ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	if ( ! is_array( $row ) ) {
		throw new PapelitoReceiptPersistenceException( 'receipt_sequence_unavailable' );
	}

	$sequence = max( 1, (int) $row['next_sequence'] );
	$updated  = $wpdb->query(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "UPDATE {$tables['sequences']} SET next_sequence = next_sequence + 1, updated_at = CURRENT_TIMESTAMP WHERE sequence_year = %d", $year )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $updated ) {
		throw new PapelitoReceiptPersistenceException( 'receipt_sequence_update_failed' );
	}

	return $sequence;
}

/**
 * Carrega o pedido e exige pagamento confirmado antes de qualquer emissao.
 *
 * @param int $order_id Id do pedido.
 * @return object|WP_Error
 */
function papelito_receipt_resolve_paid_order( int $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'papelito_receipt_woocommerce_missing', 'WooCommerce indisponivel.', array( 'status' => 500 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
		return new WP_Error( 'papelito_receipt_order_not_found', 'Pedido nao encontrado.', array( 'status' => 404 ) );
	}

	$payment_state = function_exists( 'papelito_pagarme_order_payment_snapshot' )
		? sanitize_key( (string) ( papelito_pagarme_order_payment_snapshot( $order )['state'] ?? '' ) )
		: '';

	if ( ! function_exists( 'papelito_pagarme_payment_state_is_paid' ) || ! papelito_pagarme_payment_state_is_paid( $payment_state ) ) {
		return new WP_Error( 'papelito_receipt_payment_not_confirmed', 'O recibo so e emitido apos a confirmacao do pagamento.', array( 'status' => 409 ) );
	}

	return $order;
}

/**
 * Persiste o recibo tolerando conflito de concorrencia, ate o limite de tentativas.
 *
 * @param int                 $order_id Id do pedido.
 * @param array<string,mixed> $snapshot Snapshot imutavel.
 * @param string              $origin   Origem da emissao.
 * @return array<string,mixed>|WP_Error
 */
function papelito_receipt_persist_with_retry( int $order_id, array $snapshot, string $origin ) {
	for ( $attempt = 1; $attempt <= PAPELITO_RECEIPT_ISSUE_MAX_ATTEMPTS; $attempt++ ) {
		$issued = papelito_receipt_persist( $order_id, $snapshot, $origin );

		if ( ! is_wp_error( $issued ) ) {
			return $issued;
		}

		$existing = papelito_receipt_get_by_order( $order_id );
		if ( $existing ) {
			return $existing;
		}

		if ( 'papelito_receipt_retryable' !== $issued->get_error_code() ) {
			return $issued;
		}

		usleep( 50000 * $attempt );
	}

	$existing = papelito_receipt_get_by_order( $order_id );

	return $existing ? $existing : new WP_Error( 'papelito_receipt_issue_failed', 'Nao foi possivel emitir o recibo.', array( 'status' => 500 ) );
}

/**
 * Emite o recibo de um pedido pago. Idempotente por `order_id`.
 *
 * @return array<string,mixed>|WP_Error
 */
/**
 * Origens possíveis de emissão de um recibo.
 *
 * - `payment`: o evento de pagamento confirmado, que é o caminho normal.
 * - `backfill`: a rotina que emite recibo de pedido antigo.
 * - `on_demand`: alguém pediu o PDF de um pedido pago que ainda não tinha
 *   recibo, e a geração emitiu na hora. Registrar isso como `payment` dizia que
 *   o recibo nasceu do pagamento, o que não aconteceu — e o número da sequência
 *   anual sai fora da ordem dos pagamentos justamente nesses casos.
 *
 * @return array<int,string>
 */
function papelito_receipt_origins(): array {
	return array( 'payment', 'backfill', 'on_demand' );
}

function papelito_receipt_issue_for_order( int $order_id, string $origin = 'payment' ) {
	$existing = papelito_receipt_get_by_order( $order_id );
	if ( $existing ) {
		return $existing;
	}

	$order = papelito_receipt_resolve_paid_order( $order_id );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$snapshot = papelito_receipt_build_snapshot( $order );
	$origin   = in_array( $origin, papelito_receipt_origins(), true ) ? $origin : 'payment';

	return papelito_receipt_persist_with_retry( $order_id, $snapshot, $origin );
}

/**
 * Linha do recibo pronta para o insert.
 *
 * @param int                 $order_id Id do pedido.
 * @param array<string,mixed> $snapshot Snapshot imutavel.
 * @param string              $origin   Origem da emissao.
 * @param string              $number   Numero do recibo ja formatado.
 * @param int                 $year     Ano da numeracao.
 * @param int                 $sequence Sequencial anual alocado.
 * @param string              $now      Datetime UTC da emissao.
 * @return array<string,mixed>
 */
function papelito_receipt_row_for_insert( int $order_id, array $snapshot, string $origin, string $number, int $year, int $sequence, string $now ): array {
	$company = is_array( $snapshot['company'] ?? null ) ? $snapshot['company'] : null;

	return array(
		'receipt_number'       => $number,
		'sequence_year'        => $year,
		'sequence_number'      => $sequence,
		'order_id'             => $order_id,
		'customer_user_id'     => (int) ( $snapshot['buyer']['user_id'] ?? 0 ),
		'buyer_label'          => (string) ( $snapshot['buyer']['label'] ?? '' ),
		'company_id'           => $company ? (int) $company['id'] : null,
		'company_cnpj'         => $company && '' !== (string) $company['cnpj'] ? (string) $company['cnpj'] : null,
		'company_legal_name'   => $company ? (string) $company['legal_name'] : null,
		'payment_method'       => (string) ( $snapshot['payment']['method'] ?? '' ),
		'payment_method_title' => (string) ( $snapshot['payment']['method_title'] ?? '' ),
		'payment_state'        => (string) ( $snapshot['payment']['state'] ?? '' ),
		'order_status'         => (string) ( $snapshot['order']['status'] ?? '' ),
		'currency'             => substr( (string) ( $snapshot['order']['currency'] ?? 'BRL' ), 0, 3 ),
		'subtotal_cents'       => (int) $snapshot['totals']['subtotal_cents'],
		'discount_cents'       => (int) $snapshot['totals']['discount_cents'],
		'shipping_cents'       => (int) $snapshot['totals']['shipping_cents'],
		'total_cents'          => (int) $snapshot['totals']['total_cents'],
		'snapshot_json'        => wp_json_encode( $snapshot ),
		'snapshot_version'     => (int) ( $snapshot['version'] ?? PAPELITO_RECEIPT_SNAPSHOT_VERSION ),
		'origin'               => $origin,
		'ordered_at'           => $snapshot['order']['ordered_at'] ?? null,
		'paid_at'              => $snapshot['order']['paid_at'] ?? null,
		'issued_at'            => $now,
		'created_at'           => $now,
		'updated_at'           => $now,
	);
}

/**
 * Grava uma parcela por vendor do recibo.
 *
 * @param array<string,string> $tables     Tabelas do recibo.
 * @param int                  $receipt_id Id do recibo recem-gravado.
 * @param array<string,mixed>  $snapshot   Snapshot imutavel.
 * @param string               $number     Numero do recibo ja formatado.
 * @param string               $now        Datetime UTC da emissao.
 * @return void
 * @throws PapelitoReceiptPersistenceException Quando a gravacao de uma parcela falha.
 */
function papelito_receipt_insert_vendor_parts( array $tables, int $receipt_id, array $snapshot, string $number, string $now ): void {
	global $wpdb;

	$ordinal = 0;

	foreach ( (array) ( $snapshot['vendors'] ?? array() ) as $vendor ) {
		++$ordinal;
		$part_inserted = $wpdb->insert(
			$tables['vendor_parts'],
			array(
				'receipt_id'     => $receipt_id,
				'vendor_id'      => (int) $vendor['vendor_id'],
				'vendor_name'    => (string) $vendor['vendor_name'],
				'part_ordinal'   => $ordinal,
				'part_number'    => $number . '-' . $ordinal,
				'subtotal_cents' => (int) $vendor['subtotal_cents'],
				'discount_cents' => (int) $vendor['discount_cents'],
				'shipping_cents' => (int) $vendor['shipping_cents'],
				'total_cents'    => (int) $vendor['total_cents'],
				'items_json'     => wp_json_encode( $vendor['items'] ?? array() ),
				'created_at'     => $now,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $part_inserted ) {
			throw new PapelitoReceiptPersistenceException( 'receipt_part_insert_failed' );
		}
	}
}

/**
 * Traduz a falha da transacao em erro retentavel ou definitivo.
 *
 * @param string $last_error Ultimo erro reportado pelo $wpdb.
 * @return WP_Error
 */
function papelito_receipt_persist_failure( string $last_error ): WP_Error {
	$last = strtolower( $last_error );

	if ( false !== strpos( $last, 'duplicate' ) || false !== strpos( $last, 'deadlock' ) || false !== strpos( $last, 'lock wait' ) ) {
		return new WP_Error( 'papelito_receipt_retryable', 'Conflito de concorrencia ao emitir o recibo.', array( 'status' => 409 ) );
	}

	return new WP_Error( 'papelito_receipt_persist_failed', 'Nao foi possivel gravar o recibo.', array( 'status' => 500 ) );
}

/**
 * Transação única: confere, aloca sequencial, grava recibo e parcelas.
 *
 * @param array<string,mixed> $snapshot Snapshot imutável.
 * @return array<string,mixed>|WP_Error
 * @throws DomainException Quando outro processo já emitiu o recibo do pedido.
 * @throws PapelitoReceiptPersistenceException Quando a gravação do recibo ou de uma parcela falha.
 */
function papelito_receipt_persist( int $order_id, array $snapshot, string $origin ) {
	global $wpdb;

	$tables = papelito_receipts_table_names();
	$now    = current_time( 'mysql', true );
	$year   = papelito_receipt_sequence_year( $snapshot );

	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	try {
		$already = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
			$wpdb->prepare( "SELECT id FROM {$tables['receipts']} WHERE order_id = %d", $order_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $already ) {
			throw new DomainException( 'already_issued' );
		}

		$sequence = papelito_receipt_claim_sequence( $year );
		$number   = papelito_receipt_format_number( $year, $sequence );

		$inserted = $wpdb->insert(
			$tables['receipts'],
			papelito_receipt_row_for_insert( $order_id, $snapshot, $origin, $number, $year, $sequence, $now )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $inserted ) {
			throw new PapelitoReceiptPersistenceException( 'receipt_insert_failed' );
		}

		papelito_receipt_insert_vendor_parts( $tables, (int) $wpdb->insert_id, $snapshot, $number, $now );

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( DomainException $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return new WP_Error( 'papelito_receipt_retryable', 'Recibo ja emitido por outro processo.', array( 'status' => 409 ) );
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return papelito_receipt_persist_failure( (string) $wpdb->last_error );
	}

	$receipt = papelito_receipt_get_by_order( $order_id );

	return $receipt ? $receipt : new WP_Error( 'papelito_receipt_persist_failed', 'Nao foi possivel gravar o recibo.', array( 'status' => 500 ) );
}

/**
 * Emissão a partir do evento de domínio de pagamento confirmado.
 *
 * Não é listener de notificação: é regra do próprio domínio de recibo, por isso
 * vive aqui e não em notifications.php.
 *
 * @param mixed $order Pedido entregue pelo evento de dominio.
 * @return void
 */
function papelito_receipt_issue_on_payment_confirmed( $order ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return;
	}

	papelito_receipt_issue_for_order( (int) $order->get_id() );
}
add_action( 'papelito_order_payment_confirmed', 'papelito_receipt_issue_on_payment_confirmed', 10, 1 );

/**
 * Rede de segurança para pedidos que chegam a processing por outro caminho.
 * O gate de pagamento continua sendo papelito_receipt_issue_for_order().
 *
 * @param mixed $order_id Id do pedido entregue pelo hook do WooCommerce.
 * @return void
 */
function papelito_receipt_issue_on_processing( $order_id ): void {
	papelito_receipt_issue_for_order( absint( $order_id ) );
}
add_action( 'woocommerce_order_status_processing', 'papelito_receipt_issue_on_processing', 20, 1 );
