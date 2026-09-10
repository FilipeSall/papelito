<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_RETURN_SQL_SELECT_ALL' ) ) {
	define( 'PAPELITO_RETURN_SQL_SELECT_ALL', 'SELECT * FROM ' );
}

const PAPELITO_RETURN_REQUESTS_TABLE = 'papelito_return_requests';
const PAPELITO_RETURN_ITEMS_TABLE = 'papelito_return_items';
const PAPELITO_RETURN_EVENTS_TABLE = 'papelito_return_events';
const PAPELITO_RETURN_REFUNDS_TABLE = 'papelito_return_refunds';
const PAPELITO_RETURN_PROOFS_TABLE = 'papelito_return_refund_proofs';
const PAPELITO_RETURN_MYSQL_FORMAT = 'Y-m-d H:i:s';
const PAPELITO_RETURN_SQL_BEGIN = 'START TRANSACTION';
const PAPELITO_RETURN_MSG_NOT_FOUND = 'Devolução não encontrada.';
const PAPELITO_RETURN_MSG_FORBIDDEN = 'Acesso negado.';
const PAPELITO_RETURN_DEFAULT_WINDOW_DAYS = 7;
const PAPELITO_RETURN_AUTHORIZATION_DAYS = 7;
const PAPELITO_RETURN_MAINTENANCE_HOOK = 'papelito_return_maintenance_event';
const PAPELITO_RETURN_MAX_PENDING_PROOFS = 3;
const PAPELITO_RETURN_PROOF_ORPHAN_TTL = DAY_IN_SECONDS;

function papelito_return_tables(): array {
	global $wpdb;
	return array(
		'requests' => $wpdb->prefix . PAPELITO_RETURN_REQUESTS_TABLE,
		'items'    => $wpdb->prefix . PAPELITO_RETURN_ITEMS_TABLE,
		'events'   => $wpdb->prefix . PAPELITO_RETURN_EVENTS_TABLE,
		'refunds'  => $wpdb->prefix . PAPELITO_RETURN_REFUNDS_TABLE,
		'proofs'   => $wpdb->prefix . PAPELITO_RETURN_PROOFS_TABLE,
	);
}

function papelito_returns_install_tables(): void {
	global $wpdb;
	$tables = papelito_return_tables();
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$tables['requests']} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 order_id BIGINT UNSIGNED NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL,
 vendor_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(48) NOT NULL,
 reason VARCHAR(48) NOT NULL,
 reason_other TEXT NULL,
 vendor_decision_reason TEXT NULL,
 authorization_code VARCHAR(96) NULL,
 authorization_instructions TEXT NULL,
 authorization_expires_at DATETIME NULL,
 reverse_shipment_id BIGINT UNSIGNED NULL,
 delivered_at DATETIME NOT NULL,
 requested_at DATETIME NOT NULL,
 received_at DATETIME NULL,
 inspected_at DATETIME NULL,
 refund_due_at DATETIME NULL,
 refunded_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 KEY idx_customer_created (customer_id, created_at),
 KEY idx_vendor_status (vendor_id, status, updated_at),
	KEY idx_vendor_requested (vendor_id, requested_at),
	KEY idx_vendor_refunded (vendor_id, refunded_at),
 KEY idx_order (order_id),
 KEY idx_status_due (status, authorization_expires_at),
 KEY idx_refunded_at (refunded_at)
) {$charset};" );
	dbDelta( "CREATE TABLE {$tables['items']} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 return_request_id BIGINT UNSIGNED NOT NULL,
 order_item_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 product_name VARCHAR(191) NOT NULL,
 requested_qty INT UNSIGNED NOT NULL,
 received_qty INT UNSIGNED NULL,
 eligible_amount_cents BIGINT UNSIGNED NOT NULL,
 inspection_condition VARCHAR(48) NULL,
 inspection_other TEXT NULL,
 stock_disposition VARCHAR(48) NULL,
 stock_applied_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 KEY idx_request (return_request_id),
 KEY idx_order_item (order_item_id),
 KEY idx_product_vendor (product_id)
) {$charset};" );
	dbDelta( "CREATE TABLE {$tables['events']} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 return_request_id BIGINT UNSIGNED NOT NULL,
 event VARCHAR(64) NOT NULL,
 from_status VARCHAR(48) NULL,
 to_status VARCHAR(48) NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 payload LONGTEXT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 KEY idx_request_created (return_request_id, created_at)
) {$charset};" );
	dbDelta( "CREATE TABLE {$tables['refunds']} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 return_request_id BIGINT UNSIGNED NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 refunded_at DATETIME NOT NULL,
 method VARCHAR(32) NOT NULL,
 method_other VARCHAR(191) NULL,
 reference VARCHAR(191) NULL,
 notes TEXT NULL,
 performed_by_vendor_id BIGINT UNSIGNED NOT NULL,
 recorded_by_user_id BIGINT UNSIGNED NOT NULL,
 proof_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_return (return_request_id),
 UNIQUE KEY uq_proof (proof_id),
 KEY idx_refunded_at (refunded_at)
) {$charset};" );
	dbDelta( "CREATE TABLE {$tables['proofs']} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 return_request_id BIGINT UNSIGNED NOT NULL,
 vendor_id BIGINT UNSIGNED NOT NULL,
 storage_key VARCHAR(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 original_name VARCHAR(191) NOT NULL,
 mime VARCHAR(64) NOT NULL,
 size_bytes BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 uploaded_by BIGINT UNSIGNED NOT NULL,
 attached_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_storage (storage_key),
 KEY idx_return_vendor (return_request_id, vendor_id)
) {$charset};" );
	$shipments = papelito_tracking_shipments_table_name();
	$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$shipments} LIKE %s", 'return_request_id' ) );
	if ( null === $column ) {
		$wpdb->query( "ALTER TABLE {$shipments} ADD return_request_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_return_request (return_request_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}

function papelito_return_statuses(): array {
	return array( 'requested', 'rejected', 'awaiting_reverse_authorization', 'awaiting_posting', 'posting_expired', 'in_transit', 'awaiting_vendor_receipt', 'under_inspection', 'refund_pending', 'refunded', 'cancelled' );
}

function papelito_return_reasons(): array {
	return array( 'regret', 'defective', 'damaged', 'incorrect_item', 'incomplete_item', 'other' );
}

/**
 * Rotulos dos motivos de devolucao, espelhando os do frontend.
 *
 * @return array<string,string>
 */
function papelito_return_reason_labels(): array {
	return array(
		'regret'          => 'Desistência da compra',
		'defective'       => 'Produto com defeito',
		'damaged'         => 'Danificado no transporte',
		'incorrect_item'  => 'Item errado',
		'incomplete_item' => 'Item incompleto',
		'other'           => 'Outro motivo',
	);
}

/**
 * Rotulo legivel de um motivo de devolucao. Em `other`, acrescenta o texto do comprador.
 *
 * @param string $reason Motivo do vocabulario de devolucao.
 * @param string $other  Texto livre, quando o motivo e `other`.
 */
function papelito_return_reason_label( string $reason, string $other = '' ): string {
	$labels = papelito_return_reason_labels();
	$label  = $labels[ sanitize_key( $reason ) ] ?? 'Outro motivo';
	$other  = trim( $other );

	return 'other' === sanitize_key( $reason ) && '' !== $other ? $label . ' — ' . $other : $label;
}

function papelito_return_error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

function papelito_return_window_days(): int {
	return max( PAPELITO_RETURN_DEFAULT_WINDOW_DAYS, min( 90, absint( get_option( 'papelito_return_window_days', PAPELITO_RETURN_DEFAULT_WINDOW_DAYS ) ) ) );
}

function papelito_return_now(): string {
	return current_time( 'mysql', true );
}

/** Converte recorte de calendário WordPress para bordas UTC persistidas. */
function papelito_return_period_utc_bounds( string $from, string $to ): array {
	try {
		$timezone = wp_timezone();
		return array(
			( new DateTimeImmutable( $from . ' 00:00:00', $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( PAPELITO_RETURN_MYSQL_FORMAT ),
			( new DateTimeImmutable( $to . ' 23:59:59', $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( PAPELITO_RETURN_MYSQL_FORMAT ),
		);
	} catch ( Exception $error ) {
		return array( $from . ' 00:00:00', $to . ' 23:59:59' );
	}
}

function papelito_return_get( int $return_id, bool $for_update = false ): ?array {
	global $wpdb;
	$table = papelito_return_tables()['requests'];
	$row = $wpdb->get_row( $wpdb->prepare( PAPELITO_RETURN_SQL_SELECT_ALL . "{$table} WHERE id = %d" . ( $for_update ? ' FOR UPDATE' : '' ), $return_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return is_array( $row ) ? $row : null;
}

function papelito_return_items( int $return_id ): array {
	global $wpdb;
	$table = papelito_return_tables()['items'];
	$rows = $wpdb->get_results( $wpdb->prepare( PAPELITO_RETURN_SQL_SELECT_ALL . "{$table} WHERE return_request_id = %d ORDER BY id ASC", $return_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return is_array( $rows ) ? $rows : array();
}

function papelito_return_event( int $return_id, string $event, ?string $from, ?string $to, int $actor, array $payload = array() ): void {
	global $wpdb;
	$wpdb->insert( papelito_return_tables()['events'], array(
		'return_request_id' => $return_id,
		'event' => sanitize_key( $event ),
		'from_status' => $from,
		'to_status' => $to,
		'actor_user_id' => max( 0, $actor ),
		'payload' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		'created_at' => papelito_return_now(),
	), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
}

/**
 * @param mixed $value Valor monetário vindo do WooCommerce.
 */
function papelito_return_decimal_to_cents( $value ): int {
	$decimal = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value, 2 ) : number_format( (float) $value, 2, '.', '' );
	$negative = str_starts_with( ltrim( (string) $decimal ), '-' );
	$parts = explode( '.', (string) $decimal, 2 );
	$whole = preg_replace( '/\D+/', '', $parts[0] ?? '0' );
	$fraction = str_pad( preg_replace( '/\D+/', '', $parts[1] ?? '' ), 2, '0' );
	$cents = (int) $whole * 100 + (int) substr( $fraction, 0, 2 );
	return $negative ? -$cents : $cents;
}

/**
 * @param WC_Order|object|null $order Pedido do WooCommerce.
 */
function papelito_return_order_delivered_at( $order ): ?string {
	if ( ! function_exists( 'papelito_tracking_order_shipments' ) ) {
		return null;
	}
	$dates = array();
	foreach ( papelito_tracking_order_shipments( (int) $order->get_id() ) as $shipment ) {
		if ( 'outbound' === (string) ( $shipment['direction'] ?? 'outbound' ) && 'delivered' === (string) ( $shipment['status'] ?? '' ) && ! empty( $shipment['delivered_at'] ) ) {
			$dates[] = (string) $shipment['delivered_at'];
		}
	}
	return empty( $dates ) ? null : max( $dates );
}

/** Fim da janela de solicitação: a contagem começa no dia seguinte à entrega. */
function papelito_return_window_deadline( string $delivered_at ): ?DateTimeImmutable {
	try {
		$delivered = new DateTimeImmutable( $delivered_at, new DateTimeZone( 'UTC' ) );
		return $delivered->setTimezone( wp_timezone() )->modify( '+1 day' )->setTime( 0, 0 )->modify( '+' . papelito_return_window_days() . ' days -1 second' );
	} catch ( Exception $exception ) {
		return null;
	}
}

/**
 * @param WC_Order|object|null $order Pedido do WooCommerce.
 */
function papelito_return_is_eligible( $order, int $customer_id ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) || (int) $order->get_customer_id() !== $customer_id ) {
		return papelito_return_error( 'papelito_return_not_found', 'Pedido não encontrado.', 404 );
	}
	if ( ! function_exists( 'papelito_vendor_dashboard_order_is_paid' ) || ! papelito_vendor_dashboard_order_is_paid( $order ) ) {
		return papelito_return_error( 'papelito_return_payment_required', 'O pedido ainda não está elegível para devolução.', 409 );
	}
	$delivered_at = papelito_return_order_delivered_at( $order );
	if ( null === $delivered_at ) {
		return papelito_return_error( 'papelito_return_delivery_required', 'A devolução só pode ser solicitada depois da entrega confirmada.', 409 );
	}
	$deadline = papelito_return_window_deadline( $delivered_at );
	if ( null === $deadline ) {
		return papelito_return_error( 'papelito_return_delivery_invalid', 'Não foi possível determinar o prazo de devolução.', 409 );
	}
	if ( new DateTimeImmutable( 'now', wp_timezone() ) > $deadline ) {
		return papelito_return_error( 'papelito_return_window_expired', 'O prazo para solicitar esta devolução expirou.', 409 );
	}
	return $delivered_at;
}

/**
 * @param WC_Order|object|null $order Pedido do WooCommerce.
 */
function papelito_return_order_vendor_id( $order ): int {
	return absint( $order->get_meta( '_papelito_vendor_id', true ) );
}

function papelito_return_active_statuses(): array {
	return array( 'requested', 'awaiting_reverse_authorization', 'awaiting_posting', 'in_transit', 'awaiting_vendor_receipt', 'under_inspection', 'refund_pending' );
}

function papelito_return_consuming_statuses(): array {
	return array_merge( papelito_return_active_statuses(), array( 'refunded' ) );
}

/**
 * Quantidade já consumida por linha de pedido, em uma consulta só.
 *
 * @param int[] $order_item_ids Linhas do pedido a medir.
 * @return array<int,int> Mapa de order_item_id para quantidade consumida.
 */
function papelito_return_consumed_qty_by_item( array $order_item_ids ): array {
	global $wpdb;
	$order_item_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_item_ids ) ) ) );
	if ( empty( $order_item_ids ) ) {
		return array();
	}
	$tables = papelito_return_tables();
	$statuses = papelito_return_consuming_statuses();
	$item_placeholders = implode( ',', array_fill( 0, count( $order_item_ids ), '%d' ) );
	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.order_item_id, COALESCE(SUM(i.requested_qty), 0) AS consumed FROM {$tables['items']} i INNER JOIN {$tables['requests']} r ON r.id = i.return_request_id WHERE i.order_item_id IN ({$item_placeholders}) AND r.status IN ({$status_placeholders}) GROUP BY i.order_item_id", array_merge( $order_item_ids, $statuses ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$consumed = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$consumed[ (int) $row['order_item_id'] ] = (int) $row['consumed'];
	}
	return $consumed;
}

/**
 * Elegibilidade de devolução para a tela do comprador.
 *
 * A decisão continua sendo recalculada no POST; isto existe só para a interface
 * não reimplementar a janela nem descobrir a recusa depois de preencher o formulário.
 *
 * @param object $order       Pedido do WooCommerce.
 * @param int    $customer_id Comprador autenticado.
 * @return array<string,mixed>
 */
function papelito_return_order_eligibility( $order, int $customer_id ): array {
	$payload = array( 'can_request' => false, 'reason' => '', 'message' => '', 'window_days' => papelito_return_window_days(), 'window_ends_at' => '', 'items' => array() );
	$delivered_at = papelito_return_is_eligible( $order, $customer_id );
	if ( is_wp_error( $delivered_at ) ) {
		$payload['reason'] = $delivered_at->get_error_code();
		$payload['message'] = $delivered_at->get_error_message();
		return $payload;
	}
	$deadline = papelito_return_window_deadline( $delivered_at );
	$payload['window_ends_at'] = $deadline ? $deadline->setTimezone( new DateTimeZone( 'UTC' ) )->format( PAPELITO_RETURN_MYSQL_FORMAT ) : '';
	$vendor_id = papelito_return_order_vendor_id( $order );
	if ( $vendor_id <= 0 ) {
		$payload['reason'] = 'papelito_return_vendor_missing';
		$payload['message'] = 'O pedido não possui vendor elegível para devolução.';
		return $payload;
	}
	$lines = array();
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$lines[ (int) $item_id ] = $item;
	}
	$consumed = papelito_return_consumed_qty_by_item( array_keys( $lines ) );
	$returnable_total = 0;
	foreach ( $lines as $item_id => $item ) {
		$item_vendor = absint( $item->get_meta( '_vendor_id', true ) ) ?: $vendor_id;
		$available = $item_vendor === $vendor_id ? max( 0, (int) $item->get_quantity() - ( $consumed[ $item_id ] ?? 0 ) ) : 0;
		$returnable_total += $available;
		$payload['items'][] = array(
			'order_item_id' => $item_id,
			'name' => sanitize_text_field( (string) $item->get_name() ),
			'purchased_qty' => (int) $item->get_quantity(),
			'returnable_qty' => $available,
		);
	}
	if ( $returnable_total <= 0 ) {
		$payload['reason'] = 'papelito_return_item_already_requested';
		$payload['message'] = 'Todos os itens deste pedido já possuem devolução registrada.';
		return $payload;
	}
	$payload['can_request'] = true;
	return $payload;
}

/**
 * Motivo e detalhe livre, já sanitizados.
 *
 * @return array{reason:string,other:string}|WP_Error
 */
function papelito_return_validate_reason( WP_REST_Request $request, ?array $fallback = null, bool $fallback_only = false ) {
	$reason = $fallback_only ? '' : sanitize_key( (string) $request->get_param( 'reason' ) );
	$other = $fallback_only ? '' : sanitize_textarea_field( (string) $request->get_param( 'reasonOther' ) );
	if ( ( $fallback_only || '' === $reason ) && null !== $fallback ) {
		$reason = sanitize_key( (string) ( $fallback['reason'] ?? '' ) );
		$other = sanitize_textarea_field( (string) ( $fallback['other'] ?? '' ) );
	}
	if ( ! in_array( $reason, papelito_return_reasons(), true ) ) {
		return papelito_return_error( 'papelito_return_reason_invalid', 'Informe um motivo válido para a devolução.', 422 );
	}
	if ( 'other' === $reason && '' === trim( $other ) ) {
		return papelito_return_error( 'papelito_return_reason_invalid', 'Informe um motivo válido para a devolução.', 422 );
	}
	return array( 'reason' => $reason, 'other' => $other );
}

/**
 * Casa a seleção recebida com as linhas reais do pedido.
 *
 * Só o pedido decide o que existe e em que quantidade: nada aqui confia no
 * `orderItemId` nem na quantidade que chegaram do navegador.
 *
 * @param WC_Order|object $order     Pedido do WooCommerce.
 * @param int             $vendor_id Vendor dono do pedido.
 * @param mixed           $selected  Itens escolhidos.
 * @return array<int,array{item:object,qty:int}>|WP_Error
 */
function papelito_return_normalize_selection( $order, int $vendor_id, $selected ) {
	if ( ! is_array( $selected ) || empty( $selected ) ) {
		return papelito_return_error( 'papelito_return_items_required', 'Selecione ao menos um item para devolver.', 422 );
	}
	$items_by_id = array();
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$items_by_id[ (int) $item_id ] = $item;
	}
	$normalized = array();
	foreach ( $selected as $selected_item ) {
		if ( ! is_array( $selected_item ) ) {
			continue;
		}
		$item_id = absint( $selected_item['orderItemId'] ?? 0 );
		$qty = absint( $selected_item['qty'] ?? 0 );
		$item = $items_by_id[ $item_id ] ?? null;
		if ( ! $item || $qty <= 0 || $qty > (int) $item->get_quantity() || isset( $normalized[ $item_id ] ) ) {
			return papelito_return_error( 'papelito_return_item_invalid', 'Um item selecionado não pertence ao pedido ou possui quantidade inválida.', 422 );
		}
		if ( ( absint( $item->get_meta( '_vendor_id', true ) ) ?: $vendor_id ) !== $vendor_id ) {
			return papelito_return_error( 'papelito_return_vendor_invalid', 'Os itens selecionados não pertencem ao mesmo vendor.', 422 );
		}
		$normalized[ $item_id ] = array( 'item' => $item, 'qty' => $qty );
	}
	return $normalized;
}

/**
 * Núcleo da abertura de devolução, compartilhado pelos dois atores.
 *
 * O comprador abre pedindo análise; o vendor abre depois de já ter combinado no
 * chat, então entra direto em `awaiting_reverse_authorization`. Fora o estado
 * inicial e quem é notificado, as guardas são as mesmas — elegibilidade, vendor
 * único e quantidade ainda não consumida — e é por isso que vivem aqui e não
 * duplicadas em cada entrada.
 */
/**
 * Guardas de entrada da abertura de devolução: pedido elegível, vendor, motivo e itens.
 *
 * Extraído de papelito_return_open() para separar validação de escrita — a transação abaixo já é
 * densa o bastante sem quatro checagens antes dela.
 *
 * @param WP_REST_Request    $request         Request.
 * @param int                $customer_id     Comprador.
 * @param array<string,mixed>|null $reason_fallback Motivo escolhido no chamado.
 * @return array{order:object,vendor_id:int,delivered_at:string,reason:string,reason_other:string,normalized:array}|WP_Error
 */
function papelito_return_open_context( WP_REST_Request $request, int $customer_id, ?array $reason_fallback, bool $fallback_only = false ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $request->get_param( 'orderId' ) ) ) : null;

	$delivered_at = papelito_return_is_eligible( $order, $customer_id );
	if ( is_wp_error( $delivered_at ) ) {
		return $delivered_at;
	}

	$vendor_id = papelito_return_order_vendor_id( $order );
	if ( $vendor_id <= 0 ) {
		return papelito_return_error( 'papelito_return_vendor_missing', 'O pedido não possui vendor elegível para devolução.', 422 );
	}

	$reason = papelito_return_validate_reason( $request, $reason_fallback, $fallback_only );
	if ( is_wp_error( $reason ) ) {
		return $reason;
	}

	$normalized = papelito_return_normalize_selection( $order, $vendor_id, $request->get_param( 'items' ) );
	if ( is_wp_error( $normalized ) ) {
		return $normalized;
	}

	return array(
		'delivered_at' => $delivered_at,
		'normalized'   => $normalized,
		'order'        => $order,
		'reason'       => $reason['reason'],
		'reason_other' => $reason['other'],
		'vendor_id'    => $vendor_id,
	);
}

/**
 * Grava os itens da devolução recém-criada.
 *
 * @param int   $return_id  Devolução.
 * @param array $normalized Itens já validados.
 * @param string $now       Instante da abertura.
 */
function papelito_return_insert_items( int $return_id, array $normalized, string $now ): void {
	global $wpdb;

	$table = papelito_return_tables()['items'];

	foreach ( $normalized as $item_id => $candidate ) {
		$item = $candidate['item'];
		$line_cents = max( 0, papelito_return_decimal_to_cents( $item->get_total() ) );
		$eligible = intdiv( $line_cents * $candidate['qty'], max( 1, (int) $item->get_quantity() ) );
		$wpdb->insert( $table, array(
			'return_request_id' => $return_id, 'order_item_id' => $item_id, 'product_id' => (int) $item->get_product_id(),
			'product_name' => substr( sanitize_text_field( $item->get_name() ), 0, 191 ), 'requested_qty' => $candidate['qty'],
			'eligible_amount_cents' => $eligible, 'created_at' => $now, 'updated_at' => $now,
		), array( '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ) );
	}
}

/**
 * Avisa a contraparte de que a devolução foi aberta.
 *
 * @param int    $return_id  Devolução.
 * @param int    $order_id   Pedido.
 * @param int    $customer_id Comprador.
 * @param int    $vendor_id  Vendor.
 * @param int    $actor_id   Quem abriu.
 * @param string $open_event Evento de abertura.
 */
function papelito_return_notify_opened( int $return_id, int $order_id, int $customer_id, int $vendor_id, int $actor_id, string $open_event ): void {
	if ( ! function_exists( 'papelito_dispatch_notification' ) ) {
		return;
	}

	$opened_by_vendor = $actor_id === $vendor_id;

	papelito_dispatch_notification(
		$opened_by_vendor ? $customer_id : $vendor_id,
		$opened_by_vendor ? 'return_opened' : 'return_requested',
		array( 'return_id' => $return_id, 'order_id' => $order_id ),
		'return:' . $return_id . ':' . $open_event
	);
}

function papelito_return_open( WP_REST_Request $request, int $customer_id, int $actor_id, string $initial_status, string $open_event, ?array $reason_fallback = null, bool $fallback_only = false, ?callable $after_create = null ) {
	global $wpdb;
	$user_id = $customer_id;
	$context = papelito_return_open_context( $request, $customer_id, $reason_fallback, $fallback_only );
	if ( is_wp_error( $context ) ) { return $context; }
	$order = $context['order'];
	$vendor_id = $context['vendor_id'];
	$delivered_at = $context['delivered_at'];
	$reason = $context['reason'];
	$reason_other = $context['reason_other'];
	$normalized = $context['normalized'];
	$lock = 'papelito_return_order_' . absint( $order->get_id() );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
		return papelito_return_error( 'papelito_return_busy', 'Outra alteração está em andamento. Tente novamente.', 409 );
	}
	$transaction_started = false;
	try {
		$tables = papelito_return_tables();
		$wpdb->query( PAPELITO_RETURN_SQL_BEGIN );
		$transaction_started = true;
		$consumed = papelito_return_consumed_qty_by_item( array_keys( $normalized ) );
		foreach ( $normalized as $item_id => $candidate ) {
			if ( ( $consumed[ $item_id ] ?? 0 ) + $candidate['qty'] > (int) $candidate['item']->get_quantity() ) {
				$wpdb->query( 'ROLLBACK' );
				return papelito_return_error( 'papelito_return_item_already_requested', 'A quantidade selecionada já possui uma devolução em andamento.', 409 );
			}
		}
		$now = papelito_return_now();
		$written = $wpdb->insert( $tables['requests'], array(
			'order_id' => (int) $order->get_id(), 'customer_id' => $user_id, 'vendor_id' => $vendor_id,
			'status' => $initial_status, 'reason' => $reason, 'reason_other' => $reason_other ?: null,
			'delivered_at' => $delivered_at, 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
		), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $written ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_create_failed', 'Não foi possível registrar a devolução.', 500 ); }
		$return_id = (int) $wpdb->insert_id;
		papelito_return_insert_items( $return_id, $normalized, $now );
		if ( null !== $after_create ) {
			$link = $after_create( $return_id );
			if ( is_wp_error( $link ) ) { $wpdb->query( 'ROLLBACK' ); return $link; }
		}
		papelito_return_event( $return_id, $open_event, null, $initial_status, $actor_id, array(
			'reason'         => $reason,
			'chamado_reason' => $reason_fallback['reason'] ?? null,
		) );
		$wpdb->query( 'COMMIT' );
		$transaction_started = false;
		papelito_return_notify_opened( $return_id, (int) $order->get_id(), $user_id, $vendor_id, $actor_id, $open_event );
		return new WP_REST_Response( papelito_return_payload( papelito_return_get( $return_id ) ), 201 );
	} catch ( Throwable $error ) {
		if ( $transaction_started ) { $wpdb->query( 'ROLLBACK' ); }
		return papelito_return_error( 'papelito_return_create_failed', 'Não foi possível registrar a devolução.', 500 );
	} finally {
		if ( $transaction_started ) { $wpdb->query( 'ROLLBACK' ); }
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

function papelito_return_create( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$order_id = absint( $request->get_param( 'orderId' ) );
	if ( ! function_exists( 'papelito_messaging_open_return_support' ) ) {
		return papelito_return_error( 'papelito_return_support_unavailable', 'Não foi possível abrir o suporte da devolução.', 503 );
	}
	return papelito_messaging_open_return_support( $order_id, $user_id, $request );
}

/**
 * Vendor abre a devolução que combinou com o cliente pelo suporte do pedido.
 *
 * Não existe caminho em que o comprador registre sozinho: a solicitação nasce
 * como conversa, e este endpoint é o que a transforma em processo auditável.
 */
function papelito_return_vendor_open( WP_REST_Request $request ) {
	$vendor_id = get_current_user_id();
	$user = get_userdata( $vendor_id );
	if ( ! current_user_can( 'seller' ) && ! in_array( 'seller', is_object( $user ) ? (array) $user->roles : array(), true ) ) {
		return papelito_return_error( 'papelito_return_forbidden', PAPELITO_RETURN_MSG_FORBIDDEN, 403 );
	}
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $request->get_param( 'orderId' ) ) ) : null;
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) || papelito_return_order_vendor_id( $order ) !== $vendor_id ) {
		return papelito_return_error( 'papelito_return_not_found', 'Pedido não encontrado.', 404 );
	}
	$thread_id = absint( $request->get_param( 'threadId' ) );
	if ( $thread_id <= 0 ) {
		return papelito_return_error( 'papelito_return_thread_required', 'Informe o chamado que originou a devolução.', 422 );
	}
	if ( ! function_exists( 'papelito_messaging_with_order_lock' ) || ! function_exists( 'papelito_messaging_return_chamado_for_vendor' ) || ! function_exists( 'papelito_messaging_attach_return_to_chamado' ) ) {
		return papelito_return_error( 'papelito_return_support_unavailable', 'Não foi possível abrir a devolução pelo chamado.', 503 );
	}

	return papelito_messaging_with_order_lock(
		(int) $order->get_id(),
		static function () use ( $request, $thread_id, $order, $vendor_id ) {
			$customer_id = (int) $order->get_customer_id();
			$thread = papelito_messaging_return_chamado_for_vendor( $thread_id, (int) $order->get_id(), $customer_id, $vendor_id );
			if ( is_wp_error( $thread ) ) { return $thread; }

			$linked_return_id = absint( $thread['return_request_id'] ?? 0 );
			if ( $linked_return_id > 0 ) {
				$linked_return = papelito_return_get( $linked_return_id );
				return $linked_return
					? new WP_REST_Response( papelito_return_payload( $linked_return ), 200 )
					: papelito_return_error( 'papelito_return_thread_link_invalid', 'O vínculo de devolução deste chamado não é válido.', 409 );
			}

			$fallback = array(
				'reason' => (string) $thread['return_reason'],
				'other'  => (string) ( $thread['reason_other'] ?? '' ),
			);

			return papelito_return_open(
				$request,
				$customer_id,
				$vendor_id,
				'awaiting_reverse_authorization',
				'opened_by_vendor',
				$fallback,
				true,
				static fn( int $return_id ) => papelito_messaging_attach_return_to_chamado( $thread_id, $return_id, (int) $order->get_id(), $customer_id, $vendor_id )
			);
		}
	);
}

function papelito_return_transition( int $return_id, array $from, string $to, int $actor, array $updates = array(), array $payload = array() ) {
	global $wpdb;
	$tables = papelito_return_tables();
	$wpdb->query( PAPELITO_RETURN_SQL_BEGIN );
	$row = papelito_return_get( $return_id, true );
	if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_not_found', PAPELITO_RETURN_MSG_NOT_FOUND, 404 ); }
	if ( ! in_array( (string) $row['status'], $from, true ) ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_transition_invalid', 'Esta ação não está disponível para o estado atual.', 409 ); }
	$data = array_merge( $updates, array( 'status' => $to, 'version' => (int) $row['version'] + 1, 'updated_at' => papelito_return_now() ) );
	$formats = array_fill( 0, count( $data ), '%s' );
	foreach ( array( 'version', 'reverse_shipment_id' ) as $numeric ) { if ( array_key_exists( $numeric, $data ) ) { $formats[ array_search( $numeric, array_keys( $data ), true ) ] = '%d'; } }
	$written = $wpdb->update( $tables['requests'], $data, array( 'id' => $return_id, 'version' => (int) $row['version'] ), $formats, array( '%d', '%d' ) );
	if ( false === $written || 0 === $written ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_concurrent_update', 'A devolução mudou durante esta operação. Atualize a página.', 409 ); }
	papelito_return_event( $return_id, 'status_changed', (string) $row['status'], $to, $actor, $payload );
	$wpdb->query( 'COMMIT' );
	return papelito_return_get( $return_id );
}

function papelito_return_require_vendor( int $return_id ) {
	$row = papelito_return_get( $return_id );
	if ( ! $row || (int) $row['vendor_id'] !== get_current_user_id() ) { return papelito_return_error( 'papelito_return_not_found', PAPELITO_RETURN_MSG_NOT_FOUND, 404 ); }
	$user = get_userdata( get_current_user_id() );
	if ( ! current_user_can( 'seller' ) && ! in_array( 'seller', is_object( $user ) ? (array) $user->roles : array(), true ) ) { return papelito_return_error( 'papelito_return_forbidden', PAPELITO_RETURN_MSG_FORBIDDEN, 403 ); }
	return $row;
}

function papelito_return_customer_row( int $return_id ) {
	$row = papelito_return_get( $return_id );
	return $row && (int) $row['customer_id'] === get_current_user_id() ? $row : papelito_return_error( 'papelito_return_not_found', PAPELITO_RETURN_MSG_NOT_FOUND, 404 );
}

function papelito_return_approve( WP_REST_Request $request ) {
	$return_id = absint( $request->get_param( 'id' ) );
	$row = papelito_return_require_vendor( $return_id );
	if ( is_wp_error( $row ) ) { return $row; }
	$result = papelito_return_transition( $return_id, array( 'requested' ), 'awaiting_reverse_authorization', get_current_user_id() );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( papelito_return_payload( $result ), 200 );
}

function papelito_return_reject( WP_REST_Request $request ) {
	$return_id = absint( $request->get_param( 'id' ) );
	$row = papelito_return_require_vendor( $return_id );
	if ( is_wp_error( $row ) ) { return $row; }
	$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
	if ( '' === $reason ) { return papelito_return_error( 'papelito_return_rejection_reason_required', 'Informe o motivo da recusa.', 422 ); }
	$result = papelito_return_transition( $return_id, array( 'requested' ), 'rejected', get_current_user_id(), array( 'vendor_decision_reason' => $reason ), array( 'reason' => $reason ) );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( papelito_return_payload( $result ), 200 );
}

/**
 * Converte a validade informada para UTC, ou `null` quando ela não é uma data
 * do calendário ou estoura o teto da política. Data inválida é entrada comum,
 * não excepcional — por isso o retorno é nulo em vez de exceção.
 */
function papelito_return_authorization_expiry_utc( string $expires ): ?string {
	$expiry = DateTimeImmutable::createFromFormat( '!Y-m-d', $expires, wp_timezone() );
	$errors = DateTimeImmutable::getLastErrors();
	if ( ! $expiry || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
		return null;
	}
	if ( $expiry->format( 'Y-m-d' ) !== $expires ) {
		return null;
	}
	$expiry = $expiry->setTime( 23, 59, 59 );
	$now = new DateTimeImmutable( 'now', wp_timezone() );
	if ( $expiry <= $now || $expiry > $now->modify( '+' . PAPELITO_RETURN_AUTHORIZATION_DAYS . ' days' )->setTime( 23, 59, 59 ) ) {
		return null;
	}
	return $expiry->setTimezone( new DateTimeZone( 'UTC' ) )->format( PAPELITO_RETURN_MYSQL_FORMAT );
}

function papelito_return_authorize( WP_REST_Request $request ) {
	$return_id = absint( $request->get_param( 'id' ) );
	$row = papelito_return_require_vendor( $return_id );
	if ( is_wp_error( $row ) ) { return $row; }
	$code = substr( sanitize_text_field( (string) $request->get_param( 'authorizationCode' ) ), 0, 96 );
	$instructions = sanitize_textarea_field( (string) $request->get_param( 'instructions' ) );
	$expires = sanitize_text_field( (string) $request->get_param( 'expiresAt' ) );
	if ( strlen( $code ) < 3 || '' === $expires ) { return papelito_return_error( 'papelito_return_authorization_invalid', 'Informe o código e a validade da autorização.', 422 ); }
	$expires = papelito_return_authorization_expiry_utc( $expires );
	if ( null === $expires ) { return papelito_return_error( 'papelito_return_authorization_expiry_invalid', 'A autorização deve expirar em até 7 dias.', 422 ); }
	$result = papelito_return_transition( $return_id, array( 'awaiting_reverse_authorization' ), 'awaiting_posting', get_current_user_id(), array( 'authorization_code' => $code, 'authorization_instructions' => $instructions ?: null, 'authorization_expires_at' => $expires ), array( 'authorization_code' => $code ) );
	if ( is_wp_error( $result ) ) { return $result; }
	if ( function_exists( 'papelito_dispatch_notification' ) ) {
		papelito_dispatch_notification( (int) $row['customer_id'], 'return_authorization_issued', array( 'return_id' => $return_id, 'order_id' => (int) $row['order_id'] ), 'return:' . $return_id . ':authorization' );
	}
	return new WP_REST_Response( papelito_return_payload( $result ), 200 );
}

/** Expira a autorização sem alegar uma postagem ou evento inexistente dos Correios. */
function papelito_return_expire_authorization( array $row ): array {
	if ( 'awaiting_posting' !== (string) ( $row['status'] ?? '' ) || empty( $row['authorization_expires_at'] ) || (string) $row['authorization_expires_at'] >= papelito_return_now() ) {
		return $row;
	}
	$result = papelito_return_transition( (int) $row['id'], array( 'awaiting_posting' ), 'posting_expired', 0, array(), array( 'authorization_expires_at' => (string) $row['authorization_expires_at'] ) );
	return is_wp_error( $result ) ? $row : $result;
}

/** Manutenção fora das leituras: expira autorizações e remove uploads não usados. */
function papelito_return_run_maintenance(): void {
	global $wpdb;
	$tables = papelito_return_tables();
	$expired = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['requests']} WHERE status = 'awaiting_posting' AND authorization_expires_at < %s ORDER BY authorization_expires_at ASC LIMIT 100", papelito_return_now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( is_array( $expired ) ? $expired : array() as $return_id ) {
		$row = papelito_return_get( absint( $return_id ) );
		if ( $row ) { papelito_return_expire_authorization( $row ); }
	}
	$restock_items = $wpdb->get_results( "SELECT i.*, r.vendor_id FROM {$tables['items']} i INNER JOIN {$tables['requests']} r ON r.id = i.return_request_id WHERE r.status IN ('refund_pending', 'refunded') AND i.stock_disposition = 'return_to_sellable_stock' AND i.stock_applied_at IS NULL ORDER BY i.id ASC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( is_array( $restock_items ) ? $restock_items : array() as $item ) {
		papelito_return_apply_stock( $item, absint( $item['vendor_id'] ) );
	}
	$cutoff = gmdate( PAPELITO_RETURN_MYSQL_FORMAT, time() - PAPELITO_RETURN_PROOF_ORPHAN_TTL );
	$proofs = $wpdb->get_results( $wpdb->prepare( "SELECT id, storage_key FROM {$tables['proofs']} WHERE attached_at IS NULL AND created_at < %s ORDER BY id ASC LIMIT 100", $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( is_array( $proofs ) ? $proofs : array() as $proof ) {
		$key = (string) $proof['storage_key'];
		if ( papelito_private_file_key_is_valid( $key, array( 'pdf', 'jpg', 'png' ) ) ) {
			papelito_private_file_discard_path( trailingslashit( papelito_return_proofs_dir() ) . $key );
		}
		$wpdb->delete( $tables['proofs'], array( 'id' => absint( $proof['id'] ), 'attached_at' => null ), array( '%d', '%s' ) );
	}
}

function papelito_return_schedule_maintenance(): void {
	if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( PAPELITO_RETURN_MAINTENANCE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', PAPELITO_RETURN_MAINTENANCE_HOOK );
	}
}
add_action( PAPELITO_RETURN_MAINTENANCE_HOOK, 'papelito_return_run_maintenance' );
add_action( 'init', 'papelito_return_schedule_maintenance' );

/** Associa opcionalmente um S10 à autorização, sem tratar a autorização como rastreio. */
function papelito_return_attach_tracking( WP_REST_Request $request ) {
	global $wpdb;
	$return_id = absint( $request->get_param( 'id' ) );
	$row = papelito_return_require_vendor( $return_id );
	if ( is_wp_error( $row ) ) { return $row; }
	$row = papelito_return_expire_authorization( $row );
	if ( ! in_array( (string) $row['status'], array( 'awaiting_posting', 'in_transit', 'awaiting_vendor_receipt' ), true ) ) {
		return papelito_return_error( 'papelito_return_tracking_unavailable', 'O rastreio não pode ser alterado neste estado.', 409 );
	}
	$code = papelito_tracking_normalize_code( (string) $request->get_param( 'trackingCode' ) );
	if ( '' === $code ) { return papelito_return_error( 'papelito_return_tracking_invalid', 'Informe um código S10 válido.', 422 ); }
	$existing = absint( $row['reverse_shipment_id'] ?? 0 );
	if ( $existing > 0 ) {
		$shipment = $wpdb->get_row( $wpdb->prepare( PAPELITO_RETURN_SQL_SELECT_ALL . papelito_tracking_shipments_table_name() . ' WHERE id = %d', $existing ), ARRAY_A );
		if ( is_array( $shipment ) && $code === (string) $shipment['tracking_code'] ) { return new WP_REST_Response( papelito_return_payload( $row ), 200 ); }
		return papelito_return_error( 'papelito_return_tracking_already_set', 'Já existe um rastreio reverso associado a esta devolução.', 409 );
	}
	$shipment_id = papelito_tracking_create_shipment( (int) $row['order_id'], (int) $row['vendor_id'], array(
		'direction' => 'reverse', 'return_request_id' => $return_id, 'provider' => 'correios', 'tracking_code' => $code,
		'status' => 'preposted', 'status_rank' => 10, 'idempotency_key' => hash( 'sha256', 'return-reverse-s10|' . $return_id . '|' . $code ),
	) );
	if ( is_wp_error( $shipment_id ) ) { return $shipment_id; }
	$linked = $wpdb->update(
		papelito_return_tables()['requests'],
		array( 'reverse_shipment_id' => $shipment_id, 'updated_at' => papelito_return_now() ),
		array( 'id' => $return_id, 'reverse_shipment_id' => null ),
		array( '%d', '%s' ), array( '%d', '%d' )
	);
	if ( 1 !== $linked ) { return papelito_return_error( 'papelito_return_tracking_conflict', 'O rastreio foi alterado durante esta operação.', 409 ); }
	papelito_return_event( $return_id, 'reverse_tracking_attached', null, null, get_current_user_id(), array( 'tracking_code' => $code, 'shipment_id' => $shipment_id ) );
	return new WP_REST_Response( papelito_return_payload( papelito_return_get( $return_id ) ), 200 );
}

/** Recebe somente fatos reais de um S10 reverso já associado. */
function papelito_return_tracking_event( array $shipment, string $tracking_status, string $event_key ): void {
	$return_id = absint( $shipment['return_request_id'] ?? 0 );
	if ( $return_id <= 0 ) { return; }
	$row = papelito_return_get( $return_id );
	if ( ! $row ) { return; }
	$to = '';
	$from = array();
	if ( in_array( $tracking_status, array( 'posted', 'in_transit', 'out_for_delivery', 'pickup_available' ), true ) ) {
		$to = 'in_transit'; $from = array( 'awaiting_posting', 'in_transit' );
	} elseif ( 'delivered' === $tracking_status ) {
		$to = 'awaiting_vendor_receipt'; $from = array( 'awaiting_posting', 'in_transit', 'awaiting_vendor_receipt' );
	}
	if ( '' === $to ) { return; }
	$result = papelito_return_transition( $return_id, $from, $to, 0, array(), array( 'source' => 'correios_s10', 'event_key' => $event_key, 'tracking_status' => $tracking_status ) );
	if ( ! is_wp_error( $result ) && function_exists( 'papelito_dispatch_notification' ) ) {
		papelito_dispatch_notification( (int) $row['customer_id'], 'return_tracking_updated', array( 'return_id' => $return_id, 'order_id' => (int) $row['order_id'] ), 'return:' . $return_id . ':tracking:' . $event_key );
	}
}

function papelito_return_confirm_received( WP_REST_Request $request ) {
	$return_id = absint( $request->get_param( 'id' ) );
	$row = papelito_return_require_vendor( $return_id );
	if ( is_wp_error( $row ) ) { return $row; }
	$result = papelito_return_transition( $return_id, array( 'awaiting_posting', 'in_transit', 'awaiting_vendor_receipt' ), 'under_inspection', get_current_user_id(), array( 'received_at' => papelito_return_now() ), array( 'source' => 'vendor_confirmation' ) );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( papelito_return_payload( $result ), 200 );
}

function papelito_return_apply_stock( array $return_item, int $vendor_id ) {
	if ( 'return_to_sellable_stock' !== (string) $return_item['stock_disposition'] || ! empty( $return_item['stock_applied_at'] ) ) { return true; }
	global $wpdb;
	$tables = papelito_return_tables();
	$stock_tables = function_exists( 'papelito_vendor_stock_table_names' ) ? papelito_vendor_stock_table_names() : array();
	$reason = 'return:' . (int) $return_item['return_request_id'] . ':item:' . (int) $return_item['id'];
	if ( ! empty( $stock_tables['log'] ) ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$stock_tables['log']} WHERE vendor_id = %d AND product_id = %d AND reason = %s LIMIT 1", $vendor_id, (int) $return_item['product_id'], $reason ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists ) { $wpdb->update( $tables['items'], array( 'stock_applied_at' => papelito_return_now() ), array( 'id' => (int) $return_item['id'] ), array( '%s' ), array( '%d' ) ); return true; }
	}
	$result = function_exists( 'papelito_adjust_vendor_stock_idempotent' )
		? papelito_adjust_vendor_stock_idempotent( $vendor_id, (int) $return_item['product_id'], (int) $return_item['received_qty'], $reason )
		: papelito_adjust_vendor_stock( $vendor_id, (int) $return_item['product_id'], (int) $return_item['received_qty'], $reason );
	if ( is_wp_error( $result ) ) { return $result; }
	$wpdb->update( $tables['items'], array( 'stock_applied_at' => papelito_return_now() ), array( 'id' => (int) $return_item['id'] ), array( '%s' ), array( '%d' ) );
	return true;
}

/**
 * Uma linha de inspeção validada contra os itens da própria devolução.
 *
 * @param mixed                   $input Linha recebida.
 * @param array<int,array>        $by_id Itens da devolução, por id.
 * @param array<int,bool>         $seen  Itens já inspecionados nesta chamada.
 * @return array{id:int,condition:string,disposition:string,other:string,received:int}|WP_Error
 */
function papelito_return_validate_inspection_row( $input, array $by_id, array $seen ) {
	$invalid = papelito_return_error( 'papelito_return_inspection_invalid', 'O resultado da inspeção é inválido.', 422 );
	$id = absint( is_array( $input ) ? ( $input['id'] ?? 0 ) : 0 );
	$item = $by_id[ $id ] ?? null;
	if ( ! $item || isset( $seen[ $id ] ) ) {
		return $invalid;
	}
	$condition = sanitize_key( (string) ( $input['condition'] ?? '' ) );
	if ( ! in_array( $condition, array( 'sellable', 'defective', 'damaged', 'unsellable', 'other' ), true ) ) {
		return $invalid;
	}
	$disposition = sanitize_key( (string) ( $input['stockDisposition'] ?? '' ) );
	if ( ! in_array( $disposition, array( 'return_to_sellable_stock', 'do_not_restock' ), true ) ) {
		return $invalid;
	}
	$received = absint( $input['receivedQty'] ?? 0 );
	if ( $received <= 0 || $received > (int) $item['requested_qty'] ) {
		return $invalid;
	}
	$other = sanitize_textarea_field( (string) ( $input['other'] ?? '' ) );
	if ( 'other' === $condition && '' === trim( $other ) ) {
		return $invalid;
	}
	return array( 'id' => $id, 'condition' => $condition, 'disposition' => $disposition, 'other' => $other, 'received' => $received );
}

function papelito_return_inspect( WP_REST_Request $request ) {
	$return_id = absint( $request->get_param( 'id' ) );
	$row = papelito_return_require_vendor( $return_id );
	if ( is_wp_error( $row ) ) { return $row; }
	$inspection = $request->get_param( 'items' );
	if ( ! is_array( $inspection ) || empty( $inspection ) ) { return papelito_return_error( 'papelito_return_inspection_items_required', 'Informe o resultado da inspeção.', 422 ); }
	$items = papelito_return_items( $return_id );
	$by_id = array_column( $items, null, 'id' );
	$seen = array();
	global $wpdb;
	$wpdb->query( PAPELITO_RETURN_SQL_BEGIN );
	$locked = papelito_return_get( $return_id, true );
	if ( ! $locked || 'under_inspection' !== (string) $locked['status'] || (int) $locked['vendor_id'] !== (int) $row['vendor_id'] ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_transition_invalid', 'Esta ação não está disponível para o estado atual.', 409 ); }
	foreach ( $inspection as $input ) {
		$verdict = papelito_return_validate_inspection_row( $input, $by_id, $seen );
		if ( is_wp_error( $verdict ) ) { $wpdb->query( 'ROLLBACK' ); return $verdict; }
		$seen[ $verdict['id'] ] = true;
		$wpdb->update( papelito_return_tables()['items'], array( 'received_qty' => $verdict['received'], 'inspection_condition' => $verdict['condition'], 'inspection_other' => $verdict['other'] ?: null, 'stock_disposition' => $verdict['disposition'], 'updated_at' => papelito_return_now() ), array( 'id' => $verdict['id'] ), array( '%d', '%s', '%s', '%s', '%s' ), array( '%d' ) );
	}
	if ( count( $seen ) !== count( $items ) ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_inspection_incomplete', 'Inspecione todos os itens da devolução.', 422 ); }
	$now = papelito_return_now();
	$updated = $wpdb->update( papelito_return_tables()['requests'], array( 'status' => 'refund_pending', 'inspected_at' => $now, 'refund_due_at' => gmdate( PAPELITO_RETURN_MYSQL_FORMAT, time() + 7 * DAY_IN_SECONDS ), 'version' => (int) $locked['version'] + 1, 'updated_at' => $now ), array( 'id' => $return_id, 'version' => (int) $locked['version'] ), array( '%s', '%s', '%s', '%d', '%s' ), array( '%d', '%d' ) );
	if ( 1 !== $updated ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_concurrent_update', 'A devolução mudou durante esta operação. Atualize a página.', 409 ); }
	papelito_return_event( $return_id, 'status_changed', 'under_inspection', 'refund_pending', get_current_user_id(), array( 'inspection_completed' => true ) );
	$wpdb->query( 'COMMIT' );
	foreach ( papelito_return_items( $return_id ) as $item ) {
		$stock = papelito_return_apply_stock( $item, (int) $row['vendor_id'] );
		if ( is_wp_error( $stock ) ) { papelito_return_event( $return_id, 'stock_restock_pending', null, null, 0, array( 'item_id' => (int) $item['id'], 'error' => $stock->get_error_code() ) ); return $stock; }
	}
	return new WP_REST_Response( papelito_return_payload( papelito_return_get( $return_id ) ), 200 );
}

function papelito_return_proof_spec(): array {
	return array( 'code_prefix' => 'papelito_return_proof', 'formats' => array( 'pdf', 'jpg', 'png' ), 'max_bytes' => 10 * MB_IN_BYTES, 'fallback_basename' => 'comprovante-estorno' );
}

function papelito_return_refund_amount_allowed( int $amount_cents, int $eligible_cents ): bool {
	return $amount_cents > 0 && $amount_cents <= max( 0, $eligible_cents );
}

function papelito_return_proofs_dir(): string {
	return papelito_private_files_dir( 'PAPELITO_PRIVATE_RETURN_REFUND_PROOFS_DIR', 'return-refund-proofs' );
}

/** Armazena uma prova privada ainda não vinculada a um estorno. */
function papelito_return_refund_proof_attach_file( int $return_id, int $vendor_id, array $file, int $actor ) {
	$row = papelito_return_get( $return_id );
	if ( ! $row || (int) $row['vendor_id'] !== $vendor_id || $vendor_id <= 0 ) { return papelito_return_error( 'papelito_return_not_found', PAPELITO_RETURN_MSG_NOT_FOUND, 404 ); }
	if ( ! in_array( (string) $row['status'], array( 'refund_pending' ), true ) ) { return papelito_return_error( 'papelito_return_proof_unavailable', 'O comprovante só pode ser anexado quando o estorno estiver pendente.', 409 ); }
	global $wpdb;
	$pending_proofs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . papelito_return_tables()['proofs'] . ' WHERE return_request_id = %d AND attached_at IS NULL', $return_id ) );
	if ( $pending_proofs >= PAPELITO_RETURN_MAX_PENDING_PROOFS ) { return papelito_return_error( 'papelito_return_proof_limit', 'Remova ou use um comprovante já enviado antes de anexar outro.', 409 ); }
	$validated = papelito_private_file_validate_upload( $file, papelito_return_proof_spec() );
	if ( is_wp_error( $validated ) ) { return $validated; }
	$directory = papelito_private_files_prepare_dir( papelito_return_proofs_dir(), 'papelito_return_proof' );
	if ( is_wp_error( $directory ) ) { return $directory; }
	$stored = papelito_private_file_store( $file, $validated, $directory, 'papelito_return_proof' );
	if ( is_wp_error( $stored ) ) { return $stored; }
	$now = papelito_return_now();
	$inserted = $wpdb->insert( papelito_return_tables()['proofs'], array(
		'return_request_id' => $return_id, 'vendor_id' => $vendor_id, 'storage_key' => $stored['key'], 'original_name' => $validated['original_name'],
		'mime' => $validated['mime'], 'size_bytes' => $validated['size'], 'sha256' => $validated['sha256'], 'uploaded_by' => $actor, 'created_at' => $now,
	), array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ) );
	if ( false === $inserted ) { papelito_private_file_discard_path( $stored['path'] ); return papelito_return_error( 'papelito_return_proof_not_saved', 'Não foi possível registrar o comprovante.', 500 ); }
	$proof_id = absint( $wpdb->insert_id );
	papelito_return_event( $return_id, 'refund_proof_uploaded', null, null, $actor, array( 'proof_id' => $proof_id ) );
	return array( 'id' => $proof_id, 'originalName' => $validated['original_name'], 'mime' => $validated['mime'], 'sizeBytes' => $validated['size'] );
}

/**
 * Campos do estorno manual, sanitizados e com a data já em UTC.
 *
 * O teto contra o valor elegível não entra aqui: ele depende da linha travada
 * em transação e continua junto da escrita.
 *
 * @return array{amount:int,proof_id:int,method:string,method_other:string,reference:string,notes:string,refunded_at:string}|WP_Error
 */
function papelito_return_validate_refund_input( WP_REST_Request $request ) {
	$amount = absint( $request->get_param( 'amountCents' ) );
	$proof_id = absint( $request->get_param( 'proofId' ) );
	$method = sanitize_key( (string) $request->get_param( 'method' ) );
	$method_other = sanitize_text_field( (string) $request->get_param( 'methodOther' ) );
	if ( $amount <= 0 || $proof_id <= 0 || ! in_array( $method, array( 'pix', 'bank_transfer', 'original_method', 'other' ), true ) ) {
		return papelito_return_error( 'papelito_return_refund_invalid', 'Informe valor, método e comprovante válidos.', 422 );
	}
	if ( 'other' === $method && '' === $method_other ) {
		return papelito_return_error( 'papelito_return_refund_invalid', 'Informe valor, método e comprovante válidos.', 422 );
	}
	try {
		$refunded_at = ( new DateTimeImmutable( sanitize_text_field( (string) $request->get_param( 'refundedAt' ) ) ?: 'now', wp_timezone() ) )
			->setTimezone( new DateTimeZone( 'UTC' ) )
			->format( PAPELITO_RETURN_MYSQL_FORMAT );
	} catch ( Exception $exception ) {
		return papelito_return_error( 'papelito_return_refund_date_invalid', 'Informe uma data de estorno válida.', 422 );
	}
	return array(
		'amount' => $amount,
		'proof_id' => $proof_id,
		'method' => $method,
		'method_other' => $method_other,
		'reference' => substr( sanitize_text_field( (string) $request->get_param( 'reference' ) ), 0, 191 ),
		'notes' => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
		'refunded_at' => $refunded_at,
	);
}

/**
 * Travas do estorno manual, todas dentro da transação já aberta.
 *
 * Estado, teto do valor, comprovante e duplicidade precisam ser lidos com a linha travada — por
 * isso vivem aqui, e não junto da validação de entrada.
 *
 * @param int $return_id Devolução.
 * @param int $amount    Valor em centavos.
 * @param int $proof_id  Comprovante.
 * @return array{row:array<string,mixed>}|WP_Error
 */
function papelito_return_refund_lock_guard( int $return_id, int $amount, int $proof_id ) {
	global $wpdb;

	$locked = papelito_return_get( $return_id, true );
	if ( ! $locked || 'refund_pending' !== (string) $locked['status'] ) {
		return papelito_return_error( 'papelito_return_refund_unavailable', 'O estorno não está disponível neste estado.', 409 );
	}

	$eligible = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(eligible_amount_cents), 0) FROM ' . papelito_return_tables()['items'] . ' WHERE return_request_id = %d', $return_id ) );
	if ( ! papelito_return_refund_amount_allowed( $amount, $eligible ) ) {
		return papelito_return_error( 'papelito_return_refund_above_limit', 'O estorno não pode superar o valor líquido elegível dos itens.', 422 );
	}

	$proof = $wpdb->get_row( $wpdb->prepare( PAPELITO_RETURN_SQL_SELECT_ALL . papelito_return_tables()['proofs'] . ' WHERE id = %d FOR UPDATE', $proof_id ), ARRAY_A );
	if ( ! is_array( $proof ) || (int) $proof['return_request_id'] !== $return_id || (int) $proof['vendor_id'] !== (int) $locked['vendor_id'] || ! empty( $proof['attached_at'] ) ) {
		return papelito_return_error( 'papelito_return_refund_proof_invalid', 'O comprovante não pertence a esta devolução ou já foi utilizado.', 422 );
	}

	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . papelito_return_tables()['refunds'] . ' WHERE return_request_id = %d FOR UPDATE', $return_id ) );
	if ( $exists ) {
		return papelito_return_error( 'papelito_return_refund_duplicate', 'Esta devolução já possui estorno registrado.', 409 );
	}

	return array( 'row' => $locked );
}

function papelito_return_register_refund( WP_REST_Request $request ) {
	global $wpdb;
	$return_id = absint( $request->get_param( 'id' ) );
	$is_admin_exception = current_user_can( 'manage_options' );
	$row = $is_admin_exception ? papelito_return_get( $return_id ) : papelito_return_require_vendor( $return_id );
	if ( is_wp_error( $row ) ) { return $row; }
	if ( ! $row ) { return papelito_return_error( 'papelito_return_not_found', PAPELITO_RETURN_MSG_NOT_FOUND, 404 ); }
	$exception_reason = sanitize_textarea_field( (string) $request->get_param( 'exceptionReason' ) );
	if ( $is_admin_exception && '' === $exception_reason ) { return papelito_return_error( 'papelito_return_exception_reason_required', 'A exceção administrativa exige justificativa.', 422 ); }
	$input = papelito_return_validate_refund_input( $request );
	if ( is_wp_error( $input ) ) { return $input; }
	$amount = $input['amount'];
	$proof_id = $input['proof_id'];
	$method = $input['method'];
	$method_other = $input['method_other'];
	$reference = $input['reference'];
	$notes = $input['notes'];
	$refunded_at = $input['refunded_at'];
	$wpdb->query( PAPELITO_RETURN_SQL_BEGIN );
	$guard = papelito_return_refund_lock_guard( $return_id, $amount, $proof_id );
	if ( is_wp_error( $guard ) ) { $wpdb->query( 'ROLLBACK' ); return $guard; }
	$locked = $guard['row'];
	$now = papelito_return_now();
	$written = $wpdb->insert( papelito_return_tables()['refunds'], array( 'return_request_id' => $return_id, 'amount_cents' => $amount, 'refunded_at' => $refunded_at, 'method' => $method, 'method_other' => $method_other ?: null, 'reference' => $reference ?: null, 'notes' => $notes ?: null, 'performed_by_vendor_id' => (int) $locked['vendor_id'], 'recorded_by_user_id' => get_current_user_id(), 'proof_id' => $proof_id, 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ) );
	if ( false === $written ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_refund_not_saved', 'Não foi possível registrar o estorno.', 500 ); }
	$proof_updated = $wpdb->update( papelito_return_tables()['proofs'], array( 'attached_at' => $now ), array( 'id' => $proof_id, 'attached_at' => null ), array( '%s' ), array( '%d', '%s' ) );
	if ( 1 !== $proof_updated ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_refund_proof_conflict', 'O comprovante foi alterado durante o estorno.', 409 ); }
	$updated = $wpdb->update( papelito_return_tables()['requests'], array( 'status' => 'refunded', 'refunded_at' => $refunded_at, 'version' => (int) $locked['version'] + 1, 'updated_at' => $now ), array( 'id' => $return_id, 'version' => (int) $locked['version'] ), array( '%s', '%s', '%d', '%s' ), array( '%d', '%d' ) );
	if ( 1 !== $updated ) { $wpdb->query( 'ROLLBACK' ); return papelito_return_error( 'papelito_return_refund_conflict', 'A devolução foi alterada durante o estorno.', 409 ); }
	if ( function_exists( 'papelito_messaging_close_chamado_for_return' ) ) {
		$closed = papelito_messaging_close_chamado_for_return( $return_id, get_current_user_id() );
		if ( is_wp_error( $closed ) ) { $wpdb->query( 'ROLLBACK' ); return $closed; }
	}
	papelito_return_event( $return_id, 'manual_refund_recorded', 'refund_pending', 'refunded', get_current_user_id(), array( 'amount_cents' => $amount, 'method' => $method, 'proof_id' => $proof_id, 'performed_by_vendor_id' => (int) $locked['vendor_id'], 'admin_exception_reason' => $is_admin_exception ? $exception_reason : null ) );
	$wpdb->query( 'COMMIT' );
	if ( function_exists( 'papelito_dispatch_notification' ) ) { papelito_dispatch_notification( (int) $locked['customer_id'], 'return_refunded', array( 'return_id' => $return_id, 'order_id' => (int) $locked['order_id'] ), 'return:' . $return_id . ':refunded' ); }
	return new WP_REST_Response( papelito_return_payload( papelito_return_get( $return_id ) ), 200 );
}

function papelito_return_can_access( array $row ): bool {
	return current_user_can( 'manage_options' ) || (int) $row['customer_id'] === get_current_user_id() || (int) $row['vendor_id'] === get_current_user_id();
}

/** Download autenticado; a storage key nunca integra a resposta REST. */
function papelito_return_download_proof( WP_REST_Request $request ) {
	global $wpdb;
	$proof = $wpdb->get_row( $wpdb->prepare( 'SELECT p.*, r.customer_id, r.vendor_id FROM ' . papelito_return_tables()['proofs'] . ' p INNER JOIN ' . papelito_return_tables()['requests'] . ' r ON r.id = p.return_request_id WHERE p.id = %d', absint( $request->get_param( 'proofId' ) ) ), ARRAY_A );
	if ( ! is_array( $proof ) || ! papelito_return_can_access( $proof ) ) { return papelito_return_error( 'papelito_return_proof_not_found', 'Comprovante não encontrado.', 404 ); }
	$key = (string) $proof['storage_key'];
	if ( ! papelito_private_file_key_is_valid( $key, array( 'pdf', 'jpg', 'png' ) ) ) { return papelito_return_error( 'papelito_return_proof_invalid', 'Comprovante indisponível.', 404 ); }
	$path = trailingslashit( papelito_return_proofs_dir() ) . $key;
	if ( ! is_file( $path ) ) { return papelito_return_error( 'papelito_return_proof_missing', 'Comprovante indisponível.', 404 ); }
	nocache_headers();
	header( 'Content-Type: ' . (string) $proof['mime'] );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $proof['original_name'] ) . '"' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

function papelito_return_list_for( string $scope ): WP_REST_Response|WP_Error {
	global $wpdb;
	$table = papelito_return_tables()['requests'];
	$where = '1=1'; $args = array();
	if ( 'customer' === $scope ) { $where = 'customer_id = %d'; $args[] = get_current_user_id(); }
	if ( 'vendor' === $scope ) { $where = 'vendor_id = %d'; $args[] = get_current_user_id(); }
	if ( 'admin' === $scope && ! current_user_can( 'manage_options' ) ) { return papelito_return_error( 'papelito_return_forbidden', PAPELITO_RETURN_MSG_FORBIDDEN, 403 ); }
	$sql = PAPELITO_RETURN_SQL_SELECT_ALL . "{$table} WHERE {$where} ORDER BY requested_at DESC LIMIT 100";
	$rows = $wpdb->get_results( empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = is_array( $rows ) ? $rows : array();
	$ids = array_map( 'absint', array_column( $rows, 'id' ) );
	if ( empty( $ids ) ) { return new WP_REST_Response( array( 'items' => array() ), 200 ); }
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$items = $wpdb->get_results( $wpdb->prepare( PAPELITO_RETURN_SQL_SELECT_ALL . papelito_return_tables()['items'] . " WHERE return_request_id IN ({$placeholders}) ORDER BY id ASC", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$refunds = $wpdb->get_results( $wpdb->prepare( PAPELITO_RETURN_SQL_SELECT_ALL . papelito_return_tables()['refunds'] . " WHERE return_request_id IN ({$placeholders})", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$items_by_return = array();
	foreach ( is_array( $items ) ? $items : array() as $item ) { $items_by_return[ (int) $item['return_request_id'] ][] = $item; }
	$refunds_by_return = array();
	foreach ( is_array( $refunds ) ? $refunds : array() as $refund ) { $refunds_by_return[ (int) $refund['return_request_id'] ] = $refund; }
	return new WP_REST_Response( array( 'items' => array_map( static fn( array $row ): array => papelito_return_payload( $row, $items_by_return[ (int) $row['id'] ] ?? array(), $refunds_by_return[ (int) $row['id'] ] ?? null ), $rows ) ), 200 );
}

function papelito_return_admin_settings( WP_REST_Request $request ) {
	if ( ! current_user_can( 'manage_options' ) ) { return papelito_return_error( 'papelito_return_forbidden', PAPELITO_RETURN_MSG_FORBIDDEN, 403 ); }
	if ( in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		$days = absint( $request->get_param( 'windowDays' ) );
		if ( $days < PAPELITO_RETURN_DEFAULT_WINDOW_DAYS || $days > 90 ) { return papelito_return_error( 'papelito_return_window_invalid', 'O prazo deve ficar entre 7 e 90 dias.', 422 ); }
		update_option( 'papelito_return_window_days', $days, false );
	}
	return new WP_REST_Response( array( 'windowDays' => papelito_return_window_days(), 'authorizationMaxDays' => PAPELITO_RETURN_AUTHORIZATION_DAYS ), 200 );
}

function papelito_return_audit( WP_REST_Request $request ) {
	global $wpdb;
	$row = papelito_return_get( absint( $request->get_param( 'id' ) ) );
	if ( ! $row || ! papelito_return_can_access( $row ) ) { return papelito_return_error( 'papelito_return_not_found', PAPELITO_RETURN_MSG_NOT_FOUND, 404 ); }
	$events = $wpdb->get_results( $wpdb->prepare( 'SELECT event, from_status, to_status, actor_user_id, payload, created_at FROM ' . papelito_return_tables()['events'] . ' WHERE return_request_id = %d ORDER BY id ASC', (int) $row['id'] ), ARRAY_A );
	$is_admin = current_user_can( 'manage_options' );
	return new WP_REST_Response( array( 'items' => array_map( static function( array $event ) use ( $is_admin ): array { $event['payload'] = json_decode( (string) $event['payload'], true ) ?: array(); if ( ! $is_admin ) { unset( $event['payload']['admin_exception_reason'] ); } return $event; }, is_array( $events ) ? $events : array() ) ), 200 );
}

function papelito_return_mutation_permission() {
	if ( ! is_user_logged_in() ) { return papelito_return_error( 'papelito_return_auth_required', 'Autenticação necessária.', 401 ); }
	if ( ! papelito_rate_limit( 'returns_mutation', 'user:' . get_current_user_id(), 20, MINUTE_IN_SECONDS ) ) {
		return papelito_return_error( 'papelito_return_rate_limited', 'Muitas alterações em pouco tempo. Tente novamente em instantes.', 429 );
	}
	return true;
}

function papelito_return_payload( ?array $row, ?array $items_override = null, ?array $refund_override = null ): array {
	if ( ! $row ) { return array(); }
	global $wpdb;
	$items = null === $items_override ? papelito_return_items( (int) $row['id'] ) : $items_override;
	$refund = null === $refund_override ? $wpdb->get_row( $wpdb->prepare( PAPELITO_RETURN_SQL_SELECT_ALL . papelito_return_tables()['refunds'] . " WHERE return_request_id = %d", (int) $row['id'] ), ARRAY_A ) : $refund_override;
	return array( 'id' => (int) $row['id'], 'orderId' => (int) $row['order_id'], 'status' => (string) $row['status'], 'reason' => (string) $row['reason'], 'reasonOther' => (string) ( $row['reason_other'] ?? '' ), 'authorizationCode' => (string) ( $row['authorization_code'] ?? '' ), 'authorizationInstructions' => (string) ( $row['authorization_instructions'] ?? '' ), 'authorizationExpiresAt' => (string) ( $row['authorization_expires_at'] ?? '' ), 'reverseShipmentId' => absint( $row['reverse_shipment_id'] ?? 0 ), 'requestedAt' => (string) $row['requested_at'], 'receivedAt' => (string) ( $row['received_at'] ?? '' ), 'refundDueAt' => (string) ( $row['refund_due_at'] ?? '' ), 'refundedAt' => (string) ( $row['refunded_at'] ?? '' ), 'eligibleAmountCents' => array_sum( array_map( static fn( array $item ): int => (int) $item['eligible_amount_cents'], $items ) ), 'items' => array_map( static fn( array $item ): array => array( 'id' => (int) $item['id'], 'orderItemId' => (int) $item['order_item_id'], 'productId' => (int) $item['product_id'], 'productName' => (string) $item['product_name'], 'requestedQty' => (int) $item['requested_qty'], 'receivedQty' => null === $item['received_qty'] ? null : (int) $item['received_qty'], 'eligibleAmountCents' => (int) $item['eligible_amount_cents'], 'condition' => $item['inspection_condition'], 'stockDisposition' => $item['stock_disposition'] ), $items ), 'refund' => is_array( $refund ) ? array( 'amountCents' => (int) $refund['amount_cents'], 'refundedAt' => (string) $refund['refunded_at'], 'method' => (string) $refund['method'], 'proofId' => (int) $refund['proof_id'] ) : null );
}

function papelito_returns_register_routes(): void {
	register_rest_route( PAPELITO_REST_NAMESPACE, '/profile/me/returns', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => static fn() => papelito_return_list_for( 'customer' ) ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/profile/me/orders/(?P<orderId>\d+)/returns', array( array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_create' ) ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/profile/me/returns/(?P<id>\d+)', array(
		array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => static function( WP_REST_Request $request ) { $row = papelito_return_customer_row( absint( $request->get_param( 'id' ) ) ); return is_wp_error( $row ) ? $row : new WP_REST_Response( papelito_return_payload( $row ), 200 ); } ),
		array( 'methods' => WP_REST_Server::DELETABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => static function( WP_REST_Request $request ) { $row = papelito_return_customer_row( absint( $request->get_param( 'id' ) ) ); if ( is_wp_error( $row ) ) { return $row; } $result = papelito_return_transition( (int) $row['id'], array( 'requested', 'awaiting_reverse_authorization', 'awaiting_posting' ), 'cancelled', get_current_user_id(), array( 'cancelled_at' => papelito_return_now() ) ); return is_wp_error( $result ) ? $result : new WP_REST_Response( papelito_return_payload( $result ), 200 ); } ),
	) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns/(?P<id>\d+)/approve', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_approve' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => static function( WP_REST_Request $request ) { $row = papelito_return_require_vendor( absint( $request->get_param( 'id' ) ) ); return is_wp_error( $row ) ? $row : new WP_REST_Response( papelito_return_payload( $row ), 200 ); } ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns/(?P<id>\d+)/reject', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_reject' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns/(?P<id>\d+)/authorization', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_authorize' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns/(?P<id>\d+)/tracking', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_attach_tracking' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns/(?P<id>\d+)/received', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_confirm_received' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns/(?P<id>\d+)/inspection', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_inspect' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns/(?P<id>\d+)/refund', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_register_refund' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/orders/(?P<orderId>\d+)/returns', array( array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_vendor_open' ) ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/vendor/me/returns', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => static fn() => papelito_return_list_for( 'vendor' ) ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/returns/proofs/(?P<proofId>\d+)/download', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => 'papelito_return_download_proof' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/returns/(?P<id>\d+)/events', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => 'papelito_return_audit' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/admin/returns', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => static fn() => papelito_return_list_for( 'admin' ) ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/admin/returns/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => static function( WP_REST_Request $request ) { if ( ! current_user_can( 'manage_options' ) ) { return papelito_return_error( 'papelito_return_forbidden', PAPELITO_RETURN_MSG_FORBIDDEN, 403 ); } $row = papelito_return_get( absint( $request->get_param( 'id' ) ) ); return $row ? new WP_REST_Response( papelito_return_payload( $row ), 200 ) : papelito_return_error( 'papelito_return_not_found', PAPELITO_RETURN_MSG_NOT_FOUND, 404 ); } ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/admin/returns/(?P<id>\d+)/refund', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_register_refund' ) );
	register_rest_route( PAPELITO_REST_NAMESPACE, '/admin/returns/settings', array( array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn() => is_user_logged_in(), 'callback' => 'papelito_return_admin_settings' ), array( 'methods' => WP_REST_Server::EDITABLE, 'permission_callback' => 'papelito_return_mutation_permission', 'callback' => 'papelito_return_admin_settings' ) ) );
}
add_action( 'rest_api_init', 'papelito_returns_register_routes' );
