<?php
/**
 * Rastreamento confiavel de envios pelos Correios.
 *
 * A API Rastro e a unica fonte capaz de concluir uma entrega. A integracao de
 * pre-postagem fica atras de um adapter server-side porque o schema contratado
 * e publicado apenas no CWS autenticado dos Correios.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_TRACKING_SHIPMENTS_TABLE' ) ) {
	define( 'PAPELITO_TRACKING_SHIPMENTS_TABLE', 'papelito_shipments' );
	define( 'PAPELITO_TRACKING_EVENTS_TABLE', 'papelito_tracking_events' );
	define( 'PAPELITO_TRACKING_POLL_HOOK', 'papelito_correios_tracking_poll_due' );
	define( 'PAPELITO_TRACKING_SOURCE_POLL', 'correios_poll' );
}

/** Resolve o nome da tabela de envios. */
function papelito_tracking_shipments_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . PAPELITO_TRACKING_SHIPMENTS_TABLE;
}

/** Resolve o nome da tabela de eventos. */
function papelito_tracking_events_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . PAPELITO_TRACKING_EVENTS_TABLE;
}

/** Cria as tabelas de envios e eventos brutos. */
function papelito_tracking_install_tables(): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$shipments       = papelito_tracking_shipments_table_name();
	$events          = papelito_tracking_events_table_name();

	$sql_shipments = "CREATE TABLE {$shipments} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  direction VARCHAR(12) NOT NULL DEFAULT 'outbound',
  tracking_code VARCHAR(13) NULL DEFAULT NULL,
  prepost_id VARCHAR(64) NULL DEFAULT NULL,
  service_code VARCHAR(20) NULL DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'tracking_pending',
  status_rank SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_event_code VARCHAR(12) NULL DEFAULT NULL,
  last_event_type VARCHAR(12) NULL DEFAULT NULL,
  last_event_at DATETIME NULL DEFAULT NULL,
  last_event_description TEXT NULL,
  last_event_location VARCHAR(255) NULL DEFAULT NULL,
  delivered_at DATETIME NULL DEFAULT NULL,
  next_poll_at DATETIME NULL DEFAULT NULL,
  poll_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code VARCHAR(64) NULL DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_tracking_code (tracking_code),
  UNIQUE KEY uq_prepost_id (prepost_id),
  KEY idx_order_active (order_id, active),
  KEY idx_vendor_order (vendor_id, order_id),
  KEY idx_poll_due (active, next_poll_at)
) {$charset_collate};";

	$sql_events = "CREATE TABLE {$events} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  event_key CHAR(64) NOT NULL,
  source VARCHAR(24) NOT NULL,
  event_code VARCHAR(12) NULL DEFAULT NULL,
  event_type VARCHAR(12) NULL DEFAULT NULL,
  event_at DATETIME NULL DEFAULT NULL,
  description TEXT NULL,
  location VARCHAR(255) NULL DEFAULT NULL,
  raw_payload LONGTEXT NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_event_key (event_key),
  KEY idx_shipment_event (shipment_id, event_at),
  KEY idx_received (received_at)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_shipments );
	dbDelta( $sql_events );
}

/** Normaliza e valida um codigo S10 dos Correios. */
function papelito_tracking_normalize_code( $value ): string {
	$code = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( (string) $value ) ) );
	return 1 === preg_match( '/^[A-Z]{2}[0-9]{9}[A-Z]{2}$/', $code ) ? $code : '';
}

/**
 * Mapeia somente combinacoes confirmadas no manual oficial publico.
 * Combinacoes adicionais devem vir do schema oficial habilitado no contrato.
 *
 * @return array{status:string,rank:int,terminal:bool}|null
 */
function papelito_tracking_map_event( string $code, string $type ): ?array {
	$key = strtoupper( $code ) . '/' . strtoupper( $type );
	$map = array(
		'PO/01'  => array( 'status' => 'posted', 'rank' => 30, 'terminal' => false ),
		'RO/01'  => array( 'status' => 'in_transit', 'rank' => 40, 'terminal' => false ),
		'OEC/03' => array( 'status' => 'out_for_delivery', 'rank' => 50, 'terminal' => false ),
		'BDE/01' => array( 'status' => 'delivered', 'rank' => 100, 'terminal' => true ),
	);

	$map = apply_filters( 'papelito_correios_tracking_event_map', $map );
	if ( ! is_array( $map ) || ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
		return null;
	}

	$item = $map[ $key ];
	if ( empty( $item['status'] ) || ! isset( $item['rank'] ) ) {
		return null;
	}

	return array(
		'status'   => sanitize_key( (string) $item['status'] ),
		'rank'     => max( 0, absint( $item['rank'] ) ),
		'terminal' => ! empty( $item['terminal'] ),
	);
}

/** Converte data ISO da API para UTC MySQL sem depender do timezone do host. */
function papelito_tracking_event_datetime( $value ): ?string {
	$text = sanitize_text_field( (string) $value );
	if ( '' === $text ) {
		return null;
	}

	try {
		$date = new DateTimeImmutable( $text, wp_timezone() );
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	} catch ( Exception $exception ) {
		return null;
	}
}

/** Extrai localidade sem persistir informacoes pessoais do recebedor. */
function papelito_tracking_event_location( array $event ): string {
	$address = isset( $event['unidade']['endereco'] ) && is_array( $event['unidade']['endereco'] )
		? $event['unidade']['endereco']
		: array();
	$parts   = array_filter(
		array(
			sanitize_text_field( (string) ( $address['cidade'] ?? '' ) ),
			sanitize_text_field( (string) ( $address['uf'] ?? '' ) ),
		)
	);
	return implode( ' - ', $parts );
}

/** Produz uma chave idempotente estavel para um evento. */
function papelito_tracking_event_key( int $shipment_id, array $event ): string {
	$data = array(
		$shipment_id,
		strtoupper( sanitize_key( (string) ( $event['codigo'] ?? '' ) ) ),
		strtoupper( sanitize_key( (string) ( $event['tipo'] ?? '' ) ) ),
		sanitize_text_field( (string) ( $event['dtHrCriado'] ?? '' ) ),
		sanitize_text_field( (string) ( $event['descricao'] ?? '' ) ),
		papelito_tracking_event_location( $event ),
	);
	return hash( 'sha256', implode( '|', $data ) );
}

/** Busca os envios de um pedido. */
function papelito_tracking_order_shipments( int $order_id, bool $include_inactive = false ): array {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$sql   = "SELECT * FROM {$table} WHERE order_id = %d";
	if ( ! $include_inactive ) {
		$sql .= ' AND active = 1';
	}
	$sql .= ' ORDER BY id ASC';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return is_array( $rows ) ? $rows : array();
}

/** Monta o read model publico de um envio. */
function papelito_tracking_public_shipment( array $shipment ): array {
	return array(
		'id'              => absint( $shipment['id'] ?? 0 ),
		'tracking_code'   => sanitize_text_field( (string) ( $shipment['tracking_code'] ?? '' ) ),
		'service_code'    => sanitize_text_field( (string) ( $shipment['service_code'] ?? '' ) ),
		'status'          => sanitize_key( (string) ( $shipment['status'] ?? 'tracking_pending' ) ),
		'last_event_code' => sanitize_text_field( (string) ( $shipment['last_event_code'] ?? '' ) ),
		'last_event_type' => sanitize_text_field( (string) ( $shipment['last_event_type'] ?? '' ) ),
		'last_event_at'   => (string) ( $shipment['last_event_at'] ?? '' ),
		'last_event_description' => sanitize_text_field( (string) ( $shipment['last_event_description'] ?? '' ) ),
		'last_event_location' => sanitize_text_field( (string) ( $shipment['last_event_location'] ?? '' ) ),
		'delivered_at'    => (string) ( $shipment['delivered_at'] ?? '' ),
		'has_error'       => ! empty( $shipment['last_error_code'] ),
	);
}

/** Resume a logistica de um pedido para seller e comprador. */
function papelito_tracking_order_snapshot( int $order_id ): array {
	$rows       = papelito_tracking_order_shipments( $order_id );
	$shipments  = array_map( 'papelito_tracking_public_shipment', $rows );
	$all_done   = ! empty( $rows );
	$latest_at  = '';
	$status     = empty( $rows ) ? 'not_started' : 'tracking_pending';
	$max_rank   = -1;
	$rank_by_status = array(
		'tracking_pending' => 0,
		'preposted'        => 10,
		'posted'           => 30,
		'in_transit'       => 40,
		'out_for_delivery' => 50,
		'pickup_available' => 55,
		'delivery_failed'  => 60,
		'returning'        => 70,
		'returned'         => 80,
		'lost'             => 90,
		'delivered'        => 100,
	);

	foreach ( $rows as $row ) {
		$row_status = sanitize_key( (string) $row['status'] );
		$row_rank   = $rank_by_status[ $row_status ] ?? absint( $row['status_rank'] ?? 0 );
		if ( 'delivered' !== $row_status && $row_rank > $max_rank ) {
			$status   = $row_status;
			$max_rank = $row_rank;
		}
		if ( 'delivered' !== $row_status ) {
			$all_done = false;
		}
		if ( ! empty( $row['last_event_at'] ) && (string) $row['last_event_at'] > $latest_at ) {
			$latest_at = (string) $row['last_event_at'];
		}
	}

	if ( $all_done ) {
		$status = 'delivered';
	}

	return array(
		'status'             => $status,
		'all_packages_done'  => $all_done,
		'packages_total'     => count( $rows ),
		'packages_delivered' => count( array_filter( $rows, static fn( array $row ): bool => 'delivered' === $row['status'] ) ),
		'last_event_at'      => $latest_at,
		'shipments'          => $shipments,
	);
}

/** Cria um envio somente a partir de uma fonte confiavel do backend. */
function papelito_tracking_create_shipment( int $order_id, int $vendor_id, array $data ) {
	global $wpdb;

	$tracking_code = papelito_tracking_normalize_code( $data['tracking_code'] ?? '' );
	$prepost_id    = sanitize_text_field( (string) ( $data['prepost_id'] ?? '' ) );
	if ( '' === $tracking_code ) {
		return new WP_Error( 'papelito_tracking_invalid_code', 'Os Correios nao retornaram um codigo de rastreamento valido.', array( 'status' => 502 ) );
	}

	$inserted = $wpdb->insert(
		papelito_tracking_shipments_table_name(),
		array(
			'order_id'       => $order_id,
			'vendor_id'      => $vendor_id,
			'direction'      => 'outbound',
			'tracking_code'  => $tracking_code,
			'prepost_id'     => '' !== $prepost_id ? $prepost_id : null,
			'service_code'   => sanitize_text_field( (string) ( $data['service_code'] ?? '' ) ),
			'status'         => 'preposted',
			'status_rank'    => 10,
			'next_poll_at'   => current_time( 'mysql', true ),
			'created_at'     => current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		$status = false !== strpos( strtolower( (string) $wpdb->last_error ), 'duplicate' ) ? 409 : 500;
		return new WP_Error( 'papelito_tracking_shipment_not_created', 'Nao foi possivel associar o envio ao pedido.', array( 'status' => $status ) );
	}

	return absint( $wpdb->insert_id );
}

/**
 * Adapter de pre-postagem. O callback do filtro deve usar exclusivamente o
 * schema oficial do CWS contratado e devolver prepost_id/tracking_code.
 */
function papelito_tracking_generate_shipment( $order, int $vendor_id ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return new WP_Error( 'papelito_tracking_order_invalid', 'Pedido invalido.', array( 'status' => 422 ) );
	}
	$status = function_exists( 'papelito_vendor_dashboard_order_status' )
		? papelito_vendor_dashboard_order_status( $order )
		: '';
	if ( 'em_separacao' !== $status ) {
		return new WP_Error(
			'papelito_tracking_order_not_ready',
			'O pedido precisa estar pago e em separacao para gerar a etiqueta.',
			array( 'status' => 409 )
		);
	}
	$wc_status = method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '';
	if ( in_array( $wc_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
		return new WP_Error( 'papelito_tracking_order_closed', 'O pedido nao aceita um novo envio.', array( 'status' => 409 ) );
	}

	$existing = papelito_tracking_order_shipments( (int) $order->get_id() );
	if ( ! empty( $existing ) ) {
		return new WP_Error( 'papelito_tracking_shipment_exists', 'O pedido ja possui um envio ativo.', array( 'status' => 409 ) );
	}

	$result = apply_filters( 'papelito_correios_generate_prepostage', null, $order, $vendor_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ! is_array( $result ) ) {
		return new WP_Error(
			'papelito_correios_prepostage_not_enabled',
			'A API de pre-postagem ainda nao esta habilitada no contrato dos Correios.',
			array( 'status' => 503 )
		);
	}

	$shipment_id = papelito_tracking_create_shipment( (int) $order->get_id(), $vendor_id, $result );
	if ( is_wp_error( $shipment_id ) ) {
		return $shipment_id;
	}

	if ( method_exists( $order, 'add_order_note' ) ) {
		$order->add_order_note( sprintf( 'Pre-postagem Correios associada ao envio #%d.', $shipment_id ) );
		$order->save();
	}

	return papelito_tracking_order_snapshot( (int) $order->get_id() );
}

/** Consulta um objeto na API Rastro oficial. */
function papelito_tracking_fetch_correios_object( string $tracking_code ) {
	$credentials = papelito_correios_credentials();
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}
	$token = papelito_correios_get_token( $credentials );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$url = papelito_correios_base_url( $credentials['environment'] ) . 'srorastro/v1/objetos/' . rawurlencode( $tracking_code ) . '?resultado=T';
	return papelito_correios_request_json(
		'GET',
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . sanitize_text_field( (string) $token['token'] ),
			),
		)
	);
}

/** Notifica as partes apenas para marcos relevantes e de forma deduplicada. */
function papelito_tracking_notify_event( int $order_id, int $vendor_id, int $shipment_id, string $status, string $event_key ): void {
	if ( ! function_exists( 'papelito_dispatch_notification' ) || ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
		return;
	}
	$type_map = array(
		'posted'           => 'shipment_posted',
		'out_for_delivery' => 'shipment_out_for_delivery',
		'delivered'        => 'shipment_delivered',
		'delivery_failed'  => 'shipment_delivery_failed',
		'pickup_available' => 'shipment_pickup_available',
		'returned'         => 'shipment_returned',
		'lost'             => 'shipment_exception',
	);
	if ( ! isset( $type_map[ $status ] ) ) {
		return;
	}
	$dedupe  = 'tracking:' . $event_key;
	$payload = array( 'order_id' => $order_id, 'shipment_id' => $shipment_id, 'status' => $status, 'recipient_role' => 'customer' );
	papelito_dispatch_notification( absint( $order->get_customer_id() ), $type_map[ $status ], $payload, $dedupe );
	$payload['recipient_role'] = 'seller';
	papelito_dispatch_notification( $vendor_id, $type_map[ $status ], $payload, $dedupe );
}

/** Recalcula o status operacional sem permitir regressao ou entrega manual. */
function papelito_tracking_reconcile_order_status( int $order_id ): void {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return;
	}
	$snapshot = papelito_tracking_order_snapshot( $order_id );
	$current  = sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) );
	$current_logistics = sanitize_key( (string) $order->get_meta( '_papelito_logistics_status', true ) );
	if ( $current_logistics !== $snapshot['status'] ) {
		$order->update_meta_data( '_papelito_logistics_status', $snapshot['status'] );
		$order->update_meta_data( '_papelito_logistics_updated_at', current_time( 'mysql', true ) );
	}

	$wc_status = method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '';
	if ( in_array( $current, array( 'cancelado', 'entregue' ), true ) || 'refunded' === $wc_status ) {
		$order->save();
		return;
	}

	$next     = null;
	if ( ! empty( $snapshot['all_packages_done'] ) ) {
		$next = 'entregue';
	} elseif ( in_array( $snapshot['status'], array( 'posted', 'in_transit', 'out_for_delivery', 'pickup_available', 'delivery_failed', 'returning', 'returned', 'lost' ), true ) ) {
		$next = 'enviado';
	}
	if ( null === $next || $next === $current ) {
		$order->save();
		return;
	}

	$order->update_meta_data( '_papelito_vendor_status', $next );
	$order->update_meta_data( '_papelito_vendor_status_source', 'correios_rastro' );
	$order->add_order_note( 'Status logistico atualizado automaticamente pela API Rastro dos Correios: ' . $next . '.' );
	$order->save();
}

/** Persiste um evento e aplica a transicao somente se nao for regressiva. */
function papelito_tracking_ingest_event( array $shipment, array $event, string $source = PAPELITO_TRACKING_SOURCE_POLL ): bool {
	global $wpdb;
	$shipment_id = absint( $shipment['id'] ?? 0 );
	if ( $shipment_id <= 0 ) {
		return false;
	}

	$code      = strtoupper( sanitize_key( (string) ( $event['codigo'] ?? '' ) ) );
	$type      = strtoupper( sanitize_key( (string) ( $event['tipo'] ?? '' ) ) );
	$event_at  = papelito_tracking_event_datetime( $event['dtHrCriado'] ?? '' );
	$event_key = papelito_tracking_event_key( $shipment_id, $event );
	$mapping   = papelito_tracking_map_event( $code, $type );
	$raw       = wp_json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $raw ) {
		$raw = '{}';
	}

	$wpdb->query( 'START TRANSACTION' );
	$locked = $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . papelito_tracking_shipments_table_name() . ' WHERE id = %d FOR UPDATE', $shipment_id ),
		ARRAY_A
	);
	if ( ! is_array( $locked ) ) {
		$wpdb->query( 'ROLLBACK' );
		return false;
	}

	$inserted = $wpdb->insert(
		papelito_tracking_events_table_name(),
		array(
			'shipment_id' => $shipment_id,
			'event_key'   => $event_key,
			'source'      => sanitize_key( $source ),
			'event_code'  => $code,
			'event_type'  => $type,
			'event_at'    => $event_at,
			'description' => sanitize_textarea_field( (string) ( $event['descricao'] ?? '' ) ),
			'location'    => papelito_tracking_event_location( $event ),
			'raw_payload' => $raw,
			'received_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		$wpdb->query( 'ROLLBACK' );
		return false;
	}

	$changed = false;
	if ( is_array( $mapping ) ) {
		$current_rank = absint( $locked['status_rank'] ?? 0 );
		$current_at   = (string) ( $locked['last_event_at'] ?? '' );
		$is_newer     = null !== $event_at && ( '' === $current_at || $event_at >= $current_at );
		if ( $mapping['rank'] > $current_rank || ( $mapping['rank'] === $current_rank && $is_newer ) ) {
			$update = array(
				'status'          => $mapping['status'],
				'status_rank'     => $mapping['rank'],
				'last_event_code' => $code,
				'last_event_type' => $type,
				'last_event_at'   => $event_at,
				'last_event_description' => sanitize_text_field( (string) ( $event['descricao'] ?? '' ) ),
				'last_event_location' => papelito_tracking_event_location( $event ),
				'last_error_code' => null,
				'poll_attempts'   => 0,
				'updated_at'      => current_time( 'mysql', true ),
			);
			if ( 'delivered' === $mapping['status'] ) {
				$update['delivered_at'] = $event_at ?: current_time( 'mysql', true );
				$update['next_poll_at'] = null;
			}
			$changed = false !== $wpdb->update( papelito_tracking_shipments_table_name(), $update, array( 'id' => $shipment_id ) );
		}
	}
	$wpdb->query( 'COMMIT' );

	if ( $changed && is_array( $mapping ) ) {
		papelito_tracking_reconcile_order_status( absint( $locked['order_id'] ) );
		papelito_tracking_notify_event( absint( $locked['order_id'] ), absint( $locked['vendor_id'] ), $shipment_id, $mapping['status'], $event_key );
	}
	return true;
}

/** Agenda a proxima consulta com backoff em falhas. */
function papelito_tracking_schedule_next_poll( int $shipment_id, bool $failed, string $error_code = '' ): void {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT status, poll_attempts FROM {$table} WHERE id = %d", $shipment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! is_array( $row ) ) {
		return;
	}
	if ( 'delivered' === $row['status'] ) {
		$wpdb->update(
			$table,
			array( 'next_poll_at' => null, 'poll_attempts' => 0, 'last_error_code' => null, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $shipment_id )
		);
		return;
	}

	$attempts = $failed ? min( 10, absint( $row['poll_attempts'] ) + 1 ) : 0;
	if ( $failed ) {
		$delay = min( 6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempts - 1 ) ) );
	} else {
		$delay = 'out_for_delivery' === $row['status'] ? 10 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
	}
	$wpdb->update(
		$table,
		array(
			'next_poll_at'   => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			'poll_attempts'   => $attempts,
			'last_error_code' => $failed ? sanitize_key( $error_code ) : null,
			'updated_at'      => current_time( 'mysql', true ),
		),
		array( 'id' => $shipment_id )
	);
}

/** Consulta um envio e ingere todos os eventos retornados. */
function papelito_tracking_poll_shipment( array $shipment ): void {
	$shipment_id  = absint( $shipment['id'] ?? 0 );
	$tracking_code = papelito_tracking_normalize_code( $shipment['tracking_code'] ?? '' );
	if ( $shipment_id <= 0 || '' === $tracking_code ) {
		return;
	}

	$response = papelito_tracking_fetch_correios_object( $tracking_code );
	if ( is_wp_error( $response ) ) {
		papelito_tracking_schedule_next_poll( $shipment_id, true, $response->get_error_code() );
		return;
	}

	$object = isset( $response['objetos'][0] ) && is_array( $response['objetos'][0] ) ? $response['objetos'][0] : array();
	if ( ! empty( $object['codObjeto'] ) && papelito_tracking_normalize_code( $object['codObjeto'] ) !== $tracking_code ) {
		papelito_tracking_schedule_next_poll( $shipment_id, true, 'tracking_code_mismatch' );
		return;
	}
	$events = isset( $object['eventos'] ) && is_array( $object['eventos'] ) ? $object['eventos'] : array();
	foreach ( array_reverse( $events ) as $event ) {
		if ( is_array( $event ) ) {
			papelito_tracking_ingest_event( $shipment, $event );
		}
	}
	papelito_tracking_schedule_next_poll( $shipment_id, false );
}

/** Processa um lote pequeno para respeitar tempo e rate limit do host. */
function papelito_tracking_poll_due_shipments(): void {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE active = 1 AND tracking_code IS NOT NULL AND next_poll_at <= %s AND created_at >= %s ORDER BY next_poll_at ASC LIMIT 40",
			current_time( 'mysql', true ),
			gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) )
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( is_array( $rows ) ? $rows : array() as $shipment ) {
		papelito_tracking_poll_shipment( $shipment );
	}
}
add_action( PAPELITO_TRACKING_POLL_HOOK, 'papelito_tracking_poll_due_shipments' );

/** Retorna indicadores operacionais sem expor credenciais ou dados pessoais. */
function papelito_tracking_health_snapshot(): array {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$now   = current_time( 'mysql', true );
	$day_ago = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	$ninety_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );

	return array(
		'active'       => absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE active = 1" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'due'          => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND next_poll_at <= %s", $now ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'with_errors'  => absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND last_error_code IS NOT NULL" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'stalled_24h'  => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND status <> 'delivered' AND updated_at <= %s", $day_ago ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'expired_90d'  => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND status <> 'delivered' AND created_at < %s", $ninety_days_ago ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'generated_at' => $now,
	);
}

/** Instala Action Scheduler quando disponivel, com fallback para WP-Cron. */
function papelito_tracking_ensure_schedule(): void {
	if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
		if ( ! as_has_scheduled_action( PAPELITO_TRACKING_POLL_HOOK ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, PAPELITO_TRACKING_POLL_HOOK, array(), 'papelito' );
		}
		return;
	}
	if ( ! wp_next_scheduled( PAPELITO_TRACKING_POLL_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'papelito_five_minutes', PAPELITO_TRACKING_POLL_HOOK );
	}
}
add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		$schedules['papelito_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'A cada cinco minutos' );
		return $schedules;
	}
);
add_action( 'init', 'papelito_tracking_ensure_schedule', 20 );

/** Registra endpoints de geracao e associacao administrativa para migracao. */
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/vendor/me/orders/(?P<id>\d+)/shipments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static function () {
					$check = function_exists( 'papelito_vendor_dashboard_require_seller' ) ? papelito_vendor_dashboard_require_seller() : new WP_Error( 'forbidden', 'Acesso negado.' );
					return is_wp_error( $check ) ? $check : true;
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'id' ) ), get_current_user_id() );
					if ( is_wp_error( $order ) ) {
						return $order;
					}
					$result = papelito_tracking_generate_shipment( $order, get_current_user_id() );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/orders/(?P<id>\d+)/shipments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $request->get_param( 'id' ) ) ) : null;
					if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
						return new WP_Error( 'papelito_order_not_found', 'Pedido nao encontrado.', array( 'status' => 404 ) );
					}
					$id = papelito_tracking_create_shipment(
						(int) $order->get_id(),
						absint( $order->get_meta( '_papelito_vendor_id', true ) ),
						array(
							'tracking_code' => $request->get_param( 'tracking_code' ),
							'prepost_id'    => $request->get_param( 'prepost_id' ),
							'service_code'  => $request->get_param( 'service_code' ),
						)
					);
					if ( is_wp_error( $id ) ) {
						return $id;
					}
					$order->add_order_note( sprintf( 'Envio #%d associado manualmente por administrador para migracao/auditoria.', $id ) );
					$order->save();
					return new WP_REST_Response( papelito_tracking_order_snapshot( (int) $order->get_id() ), 201 );
				},
				'args'                => array(
					'tracking_code' => array( 'type' => 'string', 'required' => true ),
					'prepost_id'    => array( 'type' => 'string', 'required' => false ),
					'service_code'  => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/tracking/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
				'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( papelito_tracking_health_snapshot(), 200 ),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/orders/(?P<id>\d+)/tracking-events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;
					$order_id = absint( $request->get_param( 'id' ) );
					$events   = papelito_tracking_events_table_name();
					$shipments = papelito_tracking_shipments_table_name();
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT e.* FROM {$events} e INNER JOIN {$shipments} s ON s.id = e.shipment_id WHERE s.order_id = %d ORDER BY e.event_at ASC, e.id ASC",
							$order_id
						),
						ARRAY_A
					); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					return new WP_REST_Response( array( 'items' => is_array( $rows ) ? $rows : array() ), 200 );
				},
			)
		);
	}
);
