<?php
/**
 * Notificações in-app para o marketplace Papelito.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_NOTIFICATIONS_TABLE' ) ) {
	define( 'PAPELITO_NOTIFICATIONS_TABLE', 'papelito_notifications' );
}

if ( ! defined( 'PAPELITO_NOTIFICATION_EMAIL_LOG_TABLE' ) ) {
	define( 'PAPELITO_NOTIFICATION_EMAIL_LOG_TABLE', 'papelito_notification_email_log' );
}

if ( ! defined( 'PAPELITO_FAVORITE_PROMO_EMAIL_META' ) ) {
	define( 'PAPELITO_FAVORITE_PROMO_EMAIL_META', 'papelito_favorite_promo_email_enabled' );
}

if ( ! defined( 'PAPELITO_PRODUCT_PROMO_SCHEDULE_HOOK' ) ) {
	define( 'PAPELITO_PRODUCT_PROMO_SCHEDULE_HOOK', 'papelito_product_promo_start' );
}

if ( ! defined( 'PAPELITO_NOTIF_NEW_VENDOR_APPLICATION' ) ) {
	define( 'PAPELITO_NOTIF_NEW_VENDOR_APPLICATION', 'new_vendor_application' );
	define( 'PAPELITO_NOTIF_FAVORITE_ON_PROMO', 'favorite_on_promo' );
	define( 'PAPELITO_NOTIF_VENDOR_APPROVED', 'vendor_approved' );
	define( 'PAPELITO_NOTIF_VENDOR_REJECTED', 'vendor_rejected' );
	define( 'PAPELITO_NOTIF_STOCK_ZEROED', 'stock_zeroed' );
	define( 'PAPELITO_NOTIF_SUPPORT_MESSAGE', 'support_message' );
	define( 'PAPELITO_NOTIF_SUPPORT_ESCALATED', 'support_escalated' );
	define( 'PAPELITO_NOTIF_PRODUCT_MISSING_WEIGHT', 'product_missing_weight' );
	define( 'PAPELITO_NOTIF_NEW_PURCHASE', 'new_purchase' );
	define( 'PAPELITO_NOTIF_PROCESSING_OVERDUE', 'vendor_processing_overdue' );
	define( 'PAPELITO_NOTIF_VENDOR_REGISTRATION_PENDING', 'vendor_registration_pending' );
	define( 'PAPELITO_NOTIF_SHIPMENT_POSTED', 'shipment_posted' );
	define( 'PAPELITO_NOTIF_SHIPMENT_TRACKING_UPDATED', 'shipment_tracking_updated' );
	define( 'PAPELITO_NOTIF_SHIPMENT_OUT_FOR_DELIVERY', 'shipment_out_for_delivery' );
	define( 'PAPELITO_NOTIF_SHIPMENT_DELIVERED', 'shipment_delivered' );
	define( 'PAPELITO_NOTIF_SHIPMENT_DELIVERY_FAILED', 'shipment_delivery_failed' );
	define( 'PAPELITO_NOTIF_SHIPMENT_PICKUP_AVAILABLE', 'shipment_pickup_available' );
	define( 'PAPELITO_NOTIF_SHIPMENT_RETURNED', 'shipment_returned' );
	define( 'PAPELITO_NOTIF_SHIPMENT_EXCEPTION', 'shipment_exception' );
}

/**
 * Resolve o nome completo da tabela de notificações.
 */
function papelito_notifications_table_name() {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_NOTIFICATIONS_TABLE;
}

/**
 * Resolve o nome completo da tabela de log de e-mails de notificacao.
 */
function papelito_notification_email_log_table_name() {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_NOTIFICATION_EMAIL_LOG_TABLE;
}

/**
 * Cria/atualiza a tabela de notificações.
 */
function papelito_notifications_install_tables() {
	global $wpdb;

	$table           = papelito_notifications_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  dedupe_key VARCHAR(191) NULL DEFAULT NULL,
  payload LONGTEXT NULL,
  read_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_type_dedupe (user_id, type, dedupe_key),
  KEY idx_user_unread (user_id, read_at),
  KEY idx_user_created (user_id, created_at)
) {$charset_collate};";

	$email_log_table = papelito_notification_email_log_table_name();

	$email_log_sql = "CREATE TABLE {$email_log_table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  dedupe_key VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_type_dedupe (user_id, type, dedupe_key),
  KEY idx_user_created (user_id, created_at)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	dbDelta( $email_log_sql );
}

/**
 * Tipos conhecidos de notificação.
 *
 * @return array<int,string>
 */
function papelito_notification_allowed_types() {
	return array(
		PAPELITO_NOTIF_NEW_VENDOR_APPLICATION,
		PAPELITO_NOTIF_FAVORITE_ON_PROMO,
		PAPELITO_NOTIF_VENDOR_APPROVED,
		PAPELITO_NOTIF_VENDOR_REJECTED,
		PAPELITO_NOTIF_STOCK_ZEROED,
		PAPELITO_NOTIF_SUPPORT_MESSAGE,
		PAPELITO_NOTIF_SUPPORT_ESCALATED,
		PAPELITO_NOTIF_PRODUCT_MISSING_WEIGHT,
		PAPELITO_NOTIF_NEW_PURCHASE,
		PAPELITO_NOTIF_PROCESSING_OVERDUE,
		PAPELITO_NOTIF_VENDOR_REGISTRATION_PENDING,
		PAPELITO_NOTIF_SHIPMENT_POSTED,
		PAPELITO_NOTIF_SHIPMENT_TRACKING_UPDATED,
		PAPELITO_NOTIF_SHIPMENT_OUT_FOR_DELIVERY,
		PAPELITO_NOTIF_SHIPMENT_DELIVERED,
		PAPELITO_NOTIF_SHIPMENT_DELIVERY_FAILED,
		PAPELITO_NOTIF_SHIPMENT_PICKUP_AVAILABLE,
		PAPELITO_NOTIF_SHIPMENT_RETURNED,
		PAPELITO_NOTIF_SHIPMENT_EXCEPTION,
	);
}

/**
 * Verifica se o produto tem peso válido para venda/cotação.
 *
 * @param WC_Product $product Produto avaliado.
 * @return bool
 */
function papelito_product_has_valid_weight( WC_Product $product ) {
	$weight = (float) wc_format_decimal( $product->get_weight( 'edit' ) );

	if ( $weight > 0 ) {
		return true;
	}

	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( $variation instanceof WC_Product && (float) wc_format_decimal( $variation->get_weight( 'edit' ) ) > 0 ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Cria uma notificação para um usuário.
 *
 * @param int                 $user_id ID do usuário destino.
 * @param string              $type Tipo da notificação.
 * @param array<string,mixed> $payload Payload serializável em JSON.
 * @return int|false
 */
function papelito_dispatch_notification( $user_id, $type, $payload = array(), $dedupe_key = null ) {
	global $wpdb;

	$user_id = absint( $user_id );
	$type    = sanitize_key( (string) $type );

	if ( $user_id <= 0 || ! in_array( $type, papelito_notification_allowed_types(), true ) ) {
		return false;
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user instanceof WP_User ) {
		return false;
	}

	$payload = is_array( $payload ) ? $payload : array();
	$dedupe_key = papelito_notification_normalize_dedupe_key( $dedupe_key );
	$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( false === $payload_json ) {
		$payload_json = '{}';
	}

	$should_dispatch = apply_filters(
		'papelito_should_dispatch_notification',
		true,
		$user_id,
		$type,
		$payload
	);

	if ( ! $should_dispatch ) {
		return false;
	}

	$inserted = $wpdb->insert(
		papelito_notifications_table_name(),
		array(
			'user_id'    => $user_id,
			'type'       => $type,
			'dedupe_key' => $dedupe_key,
			'payload'    => $payload_json,
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted && null !== $dedupe_key && false !== strpos( strtolower( (string) $wpdb->last_error ), 'duplicate' ) ) {
		return false;
	}

	return false === $inserted ? false : (int) $wpdb->insert_id;
}

/** Envia o e-mail de despacho manual uma unica vez por atualizacao de rastreio. */
function papelito_send_manual_shipment_email( $order, string $type, string $tracking_code, int $shipment_id ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_billing_email' ) || ! in_array( $type, array( PAPELITO_NOTIF_SHIPMENT_POSTED, PAPELITO_NOTIF_SHIPMENT_TRACKING_UPDATED ), true ) ) {
		return;
	}
	$recipient = sanitize_email( (string) $order->get_billing_email() );
	if ( '' === $recipient || ! is_email( $recipient ) || ! papelito_claim_notification_email_dispatch( absint( $order->get_customer_id() ), $type, 'shipment:' . $shipment_id . ':' . $type ) ) {
		return;
	}
	$url = 'https://rastreamento.correios.com.br/app/index.php?objetos=' . rawurlencode( $tracking_code );
	$subject = PAPELITO_NOTIF_SHIPMENT_TRACKING_UPDATED === $type ? 'Atualizacao do rastreamento do seu pedido - Papelito' : 'Seu pedido foi enviado - Papelito';
	$body = implode( PHP_EOL, array(
		'Atualizamos o envio do seu pedido ' . $order->get_order_number() . '.',
		'Código de rastreamento: ' . $tracking_code,
		'Acompanhe nos Correios: ' . $url,
	) );
	wp_mail( $recipient, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
}
add_action( 'papelito_manual_shipment_notified', 'papelito_send_manual_shipment_email', 10, 4 );

/**
 * Normaliza chave de deduplicacao para uso em indices.
 *
 * @param mixed $dedupe_key Valor bruto.
 * @return string|null
 */
function papelito_notification_normalize_dedupe_key( $dedupe_key ) {
	$dedupe_key = trim( sanitize_text_field( (string) $dedupe_key ) );

	if ( '' === $dedupe_key ) {
		return null;
	}

	return substr( $dedupe_key, 0, 191 );
}

/**
 * Marca um disparo de e-mail como enviado.
 *
 * @param int    $user_id Usuário destino.
 * @param string $type    Tipo da notificação.
 * @param mixed  $dedupe_key Chave idempotente.
 * @return bool
 */
function papelito_claim_notification_email_dispatch( $user_id, $type, $dedupe_key ) {
	global $wpdb;

	$user_id    = absint( $user_id );
	$type       = sanitize_key( (string) $type );
	$dedupe_key = papelito_notification_normalize_dedupe_key( $dedupe_key );

	if ( $user_id <= 0 || '' === $type || null === $dedupe_key ) {
		return false;
	}

	$inserted = $wpdb->insert(
		papelito_notification_email_log_table_name(),
		array(
			'user_id'    => $user_id,
			'type'       => $type,
			'dedupe_key' => $dedupe_key,
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s' )
	);

	if ( false === $inserted && false !== strpos( strtolower( (string) $wpdb->last_error ), 'duplicate' ) ) {
		return false;
	}

	return false !== $inserted;
}

/**
 * Converte uma linha do banco para payload REST.
 *
 * @param array<string,mixed> $row Linha da tabela.
 * @return array<string,mixed>
 */
function papelito_notification_map_row( array $row ) {
	$payload = array();
	$raw     = isset( $row['payload'] ) ? (string) $row['payload'] : '';

	if ( '' !== $raw ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$payload = $decoded;
		}
	}

	$read_at = isset( $row['read_at'] ) && null !== $row['read_at'] ? (string) $row['read_at'] : null;

	return array(
		'id'        => (int) $row['id'],
		'type'      => (string) $row['type'],
		'payload'   => $payload,
		'readAt'    => $read_at,
		'createdAt' => (string) $row['created_at'],
	);
}

/**
 * Busca uma notificação do usuário corrente.
 */
function papelito_get_user_notification( $user_id, $notification_id ) {
	global $wpdb;

	$table = papelito_notifications_table_name();
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, user_id, type, payload, read_at, created_at FROM {$table} WHERE id = %d AND user_id = %d",
			absint( $notification_id ),
			absint( $user_id )
		),
		ARRAY_A
	);

	return is_array( $row ) ? papelito_notification_map_row( $row ) : null;
}

/**
 * Conta notificações não lidas.
 */
function papelito_get_unread_notifications_count( $user_id ) {
	global $wpdb;

	$table = papelito_notifications_table_name();

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND read_at IS NULL",
			absint( $user_id )
		)
	);
}

/**
 * Verifica dedup de produto para notificações não lidas.
 */
function papelito_user_has_unread_product_notification( $user_id, $type, $product_id ) {
	global $wpdb;

	$user_id    = absint( $user_id );
	$type       = sanitize_key( (string) $type );
	$product_id = absint( $product_id );

	if ( $user_id <= 0 || $product_id <= 0 ) {
		return false;
	}

	$table = papelito_notifications_table_name();
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT payload FROM {$table} WHERE user_id = %d AND type = %s AND read_at IS NULL",
			$user_id,
			$type
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return false;
	}

	foreach ( $rows as $row ) {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );

		if ( ! is_array( $payload ) ) {
			continue;
		}

		$payload_product_id = absint( $payload['product_id'] ?? $payload['productId'] ?? 0 );
		if ( $payload_product_id === $product_id ) {
			return true;
		}
	}

	return false;
}

/**
 * Lê preferência do cliente para receber e-mail de favorito em promoção.
 *
 * @param int $user_id Usuário consultado.
 * @return bool
 */
function papelito_user_prefers_favorite_promo_email( $user_id ) {
	$user_id = absint( $user_id );

	if ( $user_id <= 0 ) {
		return false;
	}

	return '1' === (string) get_user_meta( $user_id, PAPELITO_FAVORITE_PROMO_EMAIL_META, true );
}

/**
 * Normaliza um valor numérico de contexto promocional.
 *
 * @param mixed $value Valor arbitrário.
 * @return float|null
 */
function papelito_notification_promo_number( $value ) {
	if ( null === $value || '' === $value ) {
		return null;
	}

	$normalized = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( (string) $value ) : (string) $value;

	if ( ! is_numeric( $normalized ) ) {
		return null;
	}

	return round( (float) $normalized, 2 );
}

/**
 * Calcula percentual de desconto quando há preço regular e promocional válidos.
 *
 * @param float|null $regular_price Preço base.
 * @param float|null $sale_price    Preço promocional.
 * @return int|null
 */
function papelito_notification_discount_percent( $regular_price, $sale_price ) {
	if ( null === $regular_price || null === $sale_price || $regular_price <= 0 || $sale_price <= 0 || $sale_price >= $regular_price ) {
		return null;
	}

	return (int) round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );
}

/**
 * Gera link público do produto para e-mails.
 *
 * @param array<string,mixed> $payload Payload da notificação.
 * @return string
 */
function papelito_notification_product_url( array $payload ) {
	$product_id   = absint( $payload['product_id'] ?? 0 );
	$frontend_url = function_exists( 'papelito_auth_get_frontend_url' ) ? papelito_auth_get_frontend_url() : 'http://localhost:3000';

	if ( $product_id <= 0 ) {
		return rtrim( $frontend_url, '/' ) . '/produtos';
	}

	return sprintf( '%s/produtos/%d', rtrim( $frontend_url, '/' ), $product_id );
}

/**
 * Formata preço para texto simples em e-mails.
 *
 * @param float|null $value Valor monetário.
 * @return string
 */
function papelito_notification_format_price( $value ) {
	if ( null === $value || $value <= 0 ) {
		return '';
	}

	if ( function_exists( 'wc_price' ) ) {
		return wp_strip_all_tags( wc_price( $value ) );
	}

	return 'R$ ' . number_format( $value, 2, ',', '.' );
}

/**
 * Monta payload canônico do evento de favorito em promoção.
 *
 * @param int                 $product_id Produto em promoção.
 * @param array<string,mixed> $context    Contexto opcional do evento.
 * @return array{dedupe_key:string,payload:array<string,mixed>}|null
 */
function papelito_normalize_favorite_promo_event( $product_id, array $context ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 ) {
		return null;
	}

	$promo_type = sanitize_key( (string) ( $context['promo_type'] ?? $context['promoType'] ?? 'promo' ) );
	$promo_label = sanitize_text_field( (string) ( $context['promo_label'] ?? $context['promoLabel'] ?? 'Promoção' ) );
	$regular_price = papelito_notification_promo_number( $context['regular_price'] ?? $context['regularPrice'] ?? null );
	$sale_price = papelito_notification_promo_number( $context['sale_price'] ?? $context['salePrice'] ?? null );
	$discount_percent = isset( $context['discount_percent'] ) || isset( $context['discountPercent'] )
		? papelito_notification_promo_number( $context['discount_percent'] ?? $context['discountPercent'] ?? null )
		: papelito_notification_discount_percent( $regular_price, $sale_price );
	$dedupe_key = papelito_notification_normalize_dedupe_key( $context['promo_event_key'] ?? $context['promoEventKey'] ?? '' );

	if ( null === $dedupe_key ) {
		$dedupe_key = papelito_notification_normalize_dedupe_key(
			sprintf(
				'%s:%d:%s:%s:%s',
				$promo_type ? $promo_type : 'promo',
				$product_id,
				null !== $sale_price ? (string) $sale_price : '',
				null !== $regular_price ? (string) $regular_price : '',
				$promo_label
			)
		);
	}

	$payload = array_merge(
		papelito_notification_product_payload( $product_id ),
		array(
			'promo_type'  => '' !== $promo_type ? $promo_type : 'promo',
			'promo_label' => '' !== $promo_label ? $promo_label : 'Promoção',
		)
	);

	if ( null !== $regular_price && $regular_price > 0 ) {
		$payload['regular_price'] = $regular_price;
	}

	if ( null !== $sale_price && $sale_price > 0 ) {
		$payload['sale_price'] = $sale_price;
	}

	if ( null !== $discount_percent && $discount_percent > 0 ) {
		$payload['discount_percent'] = (int) round( $discount_percent );
	}

	return null === $dedupe_key
		? null
		: array(
			'dedupe_key' => $dedupe_key,
			'payload'    => $payload,
		);
}

/**
 * Notifica o vendor responsavel sobre uma nova compra confirmada (in-app + e-mail).
 *
 * Idempotente: usa a meta `_papelito_vendor_purchase_notified` para garantir
 * que o vendor nao recebe notificacao/e-mail duplicado em caso de webhook,
 * retry ou reprocessamento do mesmo pedido.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_orders_notify_vendor_new_purchase( $order ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return;
	}

	if ( '' !== (string) $order->get_meta( '_papelito_vendor_purchase_notified', true ) ) {
		return;
	}

	$vendor_id = function_exists( 'papelito_messaging_order_vendor_id' )
		? papelito_messaging_order_vendor_id( $order )
		: absint( $order->get_meta( '_papelito_vendor_id', true ) );

	if ( $vendor_id <= 0 ) {
		return;
	}

	$items_count = 0;
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$items_count += is_object( $item ) && method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0;
	}

	$payload = array(
		'order_id'      => (int) $order->get_id(),
		'order_number'  => (string) $order->get_order_number(),
		'total'         => (float) $order->get_total(),
		'created_at'    => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
		'customer_name' => (string) $order->get_formatted_billing_full_name(),
		'items_count'   => $items_count,
	);

	papelito_dispatch_notification( $vendor_id, PAPELITO_NOTIF_NEW_PURCHASE, $payload );

	$vendor = get_user_by( 'id', $vendor_id );
	if ( $vendor instanceof WP_User ) {
		papelito_orders_send_new_purchase_email( $vendor, $order );
	}

	$order->update_meta_data( '_papelito_vendor_purchase_notified', '1' );
}

/**
 * Envia e-mail de nova compra ao vendor, seguindo o padrao texto plano do projeto.
 *
 * @param WP_User $vendor Vendor destinatario.
 * @param object  $order  Pedido WooCommerce.
 * @return bool
 */
function papelito_orders_send_new_purchase_email( WP_User $vendor, $order ): bool {
	$recipient = sanitize_email( $vendor->user_email );

	if ( '' === $recipient || ! is_object( $order ) || ! method_exists( $order, 'get_order_number' ) ) {
		return false;
	}

	$store_name   = (string) get_user_meta( $vendor->ID, 'store_name', true );
	$greeting     = '' !== $store_name ? $store_name : $vendor->display_name;
	$order_number = (string) $order->get_order_number();
	$frontend_url = function_exists( 'papelito_auth_get_frontend_url' ) ? papelito_auth_get_frontend_url() : 'http://localhost:3000';
	$order_link   = sprintf( '%s/vendor/pedidos/%d', $frontend_url, (int) $order->get_id() );
	$total        = function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $order->get_total() ) ) : (string) $order->get_total();
	$created_at   = $order->get_date_created() ? wp_date( 'd/m/Y H:i', $order->get_date_created()->getTimestamp() ) : '';

	$subject    = sprintf( 'Nova compra na sua loja - Papelito #%s', $order_number );
	$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
	$body_lines = array(
		sprintf( 'Ola %s,', '' !== $greeting ? $greeting : $recipient ),
		'',
		'Você recebeu uma nova compra na Papelito.',
		'',
		sprintf( 'Pedido: #%s', $order_number ),
	);

	if ( '' !== $created_at ) {
		$body_lines[] = sprintf( 'Data: %s', $created_at );
	}

	$body_lines = array_merge(
		$body_lines,
		array(
			sprintf( 'Total: %s', $total ),
			'',
			'Separe o pedido e prepare o envio. Acesse o detalhe abaixo:',
			$order_link,
			'',
			'Time Papelito',
		)
	);

	return wp_mail( $recipient, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Requer usuário autenticado para endpoints REST de notificações.
 *
 * @return true|WP_Error
 */
function papelito_require_notifications_auth() {
	if ( is_user_logged_in() ) {
		return true;
	}

	return new WP_Error( 'papelito_notifications_auth_required', 'Não autenticado.', array( 'status' => 401 ) );
}

/**
 * Resolve dados básicos de produto para payloads de notificação.
 *
 * @return array{product_id:int,product_name:string,product_slug:string}
 */
function papelito_notification_product_payload( $product_id ) {
	$product_id = absint( $product_id );
	$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

	return array(
		'product_id'   => $product_id,
		'product_name' => $product instanceof WC_Product ? $product->get_name() : get_the_title( $product_id ),
		'product_slug' => (string) get_post_field( 'post_name', $product_id ),
	);
}

/**
 * Lista usuários que favoritaram um produto usando o user_meta atual.
 *
 * @return array<int,int>
 */
function papelito_notification_users_who_favorited_product( $product_id ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 || ! defined( 'PAPELITO_FAVORITES_META_KEY' ) ) {
		return array();
	}

	$users = get_users(
		array(
			'fields'   => 'ID',
			'meta_key' => PAPELITO_FAVORITES_META_KEY,
		)
	);

	if ( ! is_array( $users ) ) {
		return array();
	}

	$matched = array();
	foreach ( $users as $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			continue;
		}

		if ( function_exists( 'papelito_user_has_favorite_product' ) && papelito_user_has_favorite_product( $user_id, $product_id ) ) {
			$matched[] = $user_id;
		}
	}

	return array_values( array_unique( $matched ) );
}

/**
 * Notifica admins sobre uma nova manifestacao de interesse de vendor.
 *
 * @param int               $interest_id     Manifestacao.
 * @param int               $customer_user_id Customer relacionado.
 * @param array<string,mixed>|null $interest Dados persistidos.
 */
function papelito_handle_vendor_interest_submitted( $interest_id, $customer_user_id, $interest = null ) {
	$interest_id = absint( $interest_id );

	if ( $interest_id <= 0 ) {
		return;
	}

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
		)
	);

	$interest = is_array( $interest ) ? $interest : array();
	$payload  = array(
		'interest_id'      => $interest_id,
		'customer_user_id' => absint( $customer_user_id ),
		'visibility'       => (string) ( $interest['visibility'] ?? 'customer' ),
		'store_name'       => (string) ( $interest['storeName'] ?? '' ),
	);

	foreach ( is_array( $admins ) ? $admins : array() as $admin_id ) {
		papelito_dispatch_notification( (int) $admin_id, PAPELITO_NOTIF_NEW_VENDOR_APPLICATION, $payload );
	}
}
add_action( 'papelito_vendor_interest_submitted', 'papelito_handle_vendor_interest_submitted', 10, 3 );

/**
 * Notifica vendor aprovado.
 */
function papelito_handle_vendor_approved_notification( $vendor_user_id ) {
	papelito_dispatch_notification( absint( $vendor_user_id ), PAPELITO_NOTIF_VENDOR_APPROVED, array() );
}
add_action( 'papelito_vendor_approved', 'papelito_handle_vendor_approved_notification', 10, 1 );

/**
 * Notifica vendor rejeitado.
 */
function papelito_handle_vendor_rejected_notification( $vendor_user_id, $reason = '' ) {
	papelito_dispatch_notification(
		absint( $vendor_user_id ),
		PAPELITO_NOTIF_VENDOR_REJECTED,
		array(
			'reason' => sanitize_textarea_field( (string) $reason ),
		)
	);
}
add_action( 'papelito_vendor_rejected', 'papelito_handle_vendor_rejected_notification', 10, 2 );

/**
 * Labels legiveis dos campos pendentes do onboarding financeiro.
 *
 * @return array<string, string>
 */
function papelito_vendor_pending_registration_field_labels(): array {
	return array(
		'companyName'                   => 'Razao social',
		'tradingName'                   => 'Nome fantasia',
		'corporationType'               => 'Natureza jurídica',
		'foundingDate'                  => 'Data de fundacao',
		'annualRevenue'                 => 'Faturamento anual',
		'partner.name'                  => 'Nome do socio administrador',
		'partner.email'                 => 'E-mail do socio administrador',
		'partner.document'              => 'CPF do socio administrador',
		'partner.motherName'            => 'Nome da mae do socio administrador',
		'partner.birthdate'             => 'Data de nascimento do socio administrador',
		'partner.monthlyIncome'         => 'Renda mensal do socio administrador',
		'partner.professionalOccupation' => 'Ocupacao profissional do socio administrador',
		'partner.address.zipCode'       => 'CEP do socio administrador',
		'partner.address.street'        => 'Logradouro do socio administrador',
		'partner.address.streetNumber'  => 'Número do endereço do socio administrador',
		'partner.address.neighborhood'  => 'Bairro do socio administrador',
		'partner.address.city'          => 'Cidade do socio administrador',
		'partner.address.state'         => 'Estado do socio administrador',
		'bankAccount.holderName'        => 'Titular da conta',
		'bankAccount.holderDocument'    => 'Documento do titular',
		'bankAccount.bankCode'          => 'Código do banco',
		'bankAccount.branchNumber'      => 'Agência',
		'bankAccount.accountNumber'     => 'Conta',
		'bankAccount.accountCheckDigit' => 'Digito da conta',
	);
}

/**
 * Envia e-mail quando o vendor ainda precisa concluir dados obrigatorios.
 *
 * @param WP_User            $user Usuario destino.
 * @param array<int, string> $pending_fields Campos pendentes.
 * @return bool
 */
function papelito_send_vendor_pending_registration_email( WP_User $user, array $pending_fields ): bool {
	$recipient = sanitize_email( $user->user_email );

	if ( '' === $recipient || ! is_email( $recipient ) ) {
		return false;
	}

	$labels      = papelito_vendor_pending_registration_field_labels();
	$field_lines = array();

	foreach ( $pending_fields as $field ) {
		$field = sanitize_text_field( (string) $field );
		if ( isset( $labels[ $field ] ) ) {
			$field_lines[] = '- ' . $labels[ $field ];
		}
	}

	$store_name = (string) get_user_meta( $user->ID, 'store_name', true );
	$greeting   = '' !== $store_name ? $store_name : $user->display_name;
	$frontend   = function_exists( 'papelito_auth_get_frontend_url' ) ? papelito_auth_get_frontend_url() : 'http://localhost:3000';
	$vendor_url = $frontend . '/vendor/dashboard';

	$body_lines = array(
		sprintf( 'Ola %s,', $greeting ),
		'',
		'Seu cadastro foi criado pelo time Papelito, mas ainda faltam alguns dados obrigatórios para concluir a operação financeira e a integração.',
		'',
		'Campos pendentes:',
	);

	$body_lines = array_merge( $body_lines, ! empty( $field_lines ) ? $field_lines : array( '- Revise os dados financeiros do cadastro.' ) );

	$body_lines = array_merge(
		$body_lines,
		array(
			'',
			'Acesse sua área de vendor para revisar e completar essas informações:',
			$vendor_url,
			'',
			'Time Papelito',
		)
	);

	return wp_mail(
		$recipient,
		'Complete seu cadastro de vendor - Papelito',
		implode( PHP_EOL, $body_lines ),
		array( 'Content-Type: text/plain; charset=UTF-8' )
	);
}

/**
 * Notifica o vendor sobre dados pendentes no onboarding.
 *
 * @param int                $vendor_user_id Vendor destino.
 * @param array<int, string> $pending_fields Campos pendentes.
 * @return void
 */
function papelito_handle_vendor_pending_registration_notification( $vendor_user_id, $pending_fields = array() ) {
	$vendor_user_id = absint( $vendor_user_id );
	$pending_fields = is_array( $pending_fields ) ? array_values( array_filter( array_map( 'strval', $pending_fields ), 'strlen' ) ) : array();

	if ( $vendor_user_id <= 0 || empty( $pending_fields ) ) {
		return;
	}

	$signature    = md5( implode( '|', $pending_fields ) );
	$dedupe_key   = sprintf( 'vendor_registration_pending:%d:%s', $vendor_user_id, $signature );
	$notification = papelito_dispatch_notification(
		$vendor_user_id,
		PAPELITO_NOTIF_VENDOR_REGISTRATION_PENDING,
		array(
			'pending_fields' => $pending_fields,
			'pending_count'  => count( $pending_fields ),
		),
		$dedupe_key
	);

	if ( false === $notification ) {
		return;
	}

	if ( ! papelito_claim_notification_email_dispatch( $vendor_user_id, PAPELITO_NOTIF_VENDOR_REGISTRATION_PENDING, $dedupe_key ) ) {
		return;
	}

	$user = get_user_by( 'id', $vendor_user_id );
	if ( $user instanceof WP_User ) {
		papelito_send_vendor_pending_registration_email( $user, $pending_fields );
	}
}
add_action( 'papelito_vendor_pending_registration_created', 'papelito_handle_vendor_pending_registration_notification', 10, 2 );

/**
 * Notifica vendor quando estoque zera.
 */
function papelito_handle_stock_zeroed_notification( $vendor_id, $product_id ) {
	$payload = papelito_notification_product_payload( $product_id );
	papelito_dispatch_notification( absint( $vendor_id ), PAPELITO_NOTIF_STOCK_ZEROED, $payload );
}
add_action( 'papelito_stock_zeroed', 'papelito_handle_stock_zeroed_notification', 10, 2 );

/**
 * Envia e-mail de favorito em promoção, seguindo o padrão texto simples do projeto.
 *
 * @param WP_User             $user    Destinatário.
 * @param array<string,mixed> $payload Payload do evento.
 * @return bool
 */
function papelito_send_favorite_promo_email( WP_User $user, array $payload ) {
	$recipient = sanitize_email( $user->user_email );

	if ( '' === $recipient || ! is_email( $recipient ) ) {
		return false;
	}

	$name = (string) get_user_meta( $user->ID, 'first_name', true );
	if ( '' === $name ) {
		$name = $user->display_name ? (string) $user->display_name : $recipient;
	}

	$product_name = sanitize_text_field( (string) ( $payload['product_name'] ?? 'Produto favorito' ) );
	$promo_label  = sanitize_text_field( (string) ( $payload['promo_label'] ?? 'Promoção' ) );
	$link         = papelito_notification_product_url( $payload );
	$regular      = papelito_notification_format_price( papelito_notification_promo_number( $payload['regular_price'] ?? null ) );
	$sale         = papelito_notification_format_price( papelito_notification_promo_number( $payload['sale_price'] ?? null ) );
	$discount     = absint( $payload['discount_percent'] ?? 0 );

	$subject    = sprintf( '%s entrou em promoção - Papelito', $product_name );
	$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
	$body_lines = array(
		sprintf( 'Ola %s,', $name ),
		'',
		sprintf( 'Um produto dos seus favoritos entrou em promoção: %s.', $product_name ),
		sprintf( 'Oferta: %s.', $promo_label ),
	);

	if ( $discount > 0 ) {
		$body_lines[] = sprintf( 'Desconto: %d%%.', $discount );
	}

	if ( '' !== $regular ) {
		$body_lines[] = sprintf( 'Preço regular: %s.', $regular );
	}

	if ( '' !== $sale ) {
		$body_lines[] = sprintf( 'Preço promocional: %s.', $sale );
	}

	$body_lines = array_merge(
		$body_lines,
		array(
			'',
			'Veja o produto no link abaixo:',
			$link,
			'',
			'Time Papelito',
		)
	);

	return wp_mail( $recipient, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Notifica clientes quando produto favorito entra em promoção.
 *
 * @param int                 $product_id Produto em promoção.
 * @param array<string,mixed> $context Contexto opcional do evento.
 */
function papelito_handle_product_on_promo_notification( $product_id, $context = array() ) {
	$product_id = absint( $product_id );
	if ( $product_id <= 0 ) {
		return;
	}

	$context = is_array( $context ) ? $context : array();
	$event   = papelito_normalize_favorite_promo_event( $product_id, $context );

	if ( null === $event ) {
		return;
	}

	foreach ( papelito_notification_users_who_favorited_product( $product_id ) as $user_id ) {
		$notification_id = papelito_dispatch_notification(
			$user_id,
			PAPELITO_NOTIF_FAVORITE_ON_PROMO,
			$event['payload'],
			$event['dedupe_key']
		);

		if ( false === $notification_id ) {
			continue;
		}

		if ( ! papelito_user_prefers_favorite_promo_email( $user_id ) ) {
			continue;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof WP_User ) {
			continue;
		}

		if ( ! papelito_claim_notification_email_dispatch( $user_id, PAPELITO_NOTIF_FAVORITE_ON_PROMO, $event['dedupe_key'] ) ) {
			continue;
		}

		papelito_send_favorite_promo_email( $user, $event['payload'] );
	}
}
add_action( 'papelito_product_on_promo', 'papelito_handle_product_on_promo_notification', 10, 2 );

/**
 * Gera assinatura do evento de promoção por preço promocional manual.
 *
 * @param int    $product_id     Produto alvo.
 * @param string $sale_price     Preço promocional.
 * @param string $regular_price  Preço regular.
 * @param int    $starts_at_ts   Início em timestamp UTC.
 * @param int    $ends_at_ts     Fim em timestamp UTC.
 * @return string
 */
function papelito_build_product_sale_promo_event_key( $product_id, $sale_price, $regular_price, $starts_at_ts, $ends_at_ts ) {
	return sprintf(
		'sale_price:%d:%s:%s:%d:%d',
		absint( $product_id ),
		trim( (string) $sale_price ),
		trim( (string) $regular_price ),
		(int) $starts_at_ts,
		(int) $ends_at_ts
	);
}

/**
 * Captura estado promocional corrente de um produto.
 *
 * @param WC_Product $product Produto avaliado.
 * @return array<string,mixed>
 */
function papelito_product_promo_state_snapshot( WC_Product $product ) {
	$starts_at = method_exists( $product, 'get_date_on_sale_from' ) ? $product->get_date_on_sale_from( 'edit' ) : null;
	$ends_at   = method_exists( $product, 'get_date_on_sale_to' ) ? $product->get_date_on_sale_to( 'edit' ) : null;
	$starts_at_ts = $starts_at instanceof WC_DateTime ? $starts_at->getTimestamp() : 0;
	$ends_at_ts   = $ends_at instanceof WC_DateTime ? $ends_at->getTimestamp() : 0;
	$sale_price   = trim( (string) $product->get_sale_price( 'edit' ) );
	$regular_price = trim( (string) $product->get_regular_price( 'edit' ) );
	$now_timestamp = (int) current_time( 'timestamp', true );
	$discount_percent = papelito_notification_discount_percent(
		papelito_notification_promo_number( $regular_price ),
		papelito_notification_promo_number( $sale_price )
	);

	return array(
		'product_id'        => (int) $product->get_id(),
		'is_published'      => 'publish' === $product->get_status(),
		'is_on_sale'        => 'publish' === $product->get_status() && $product->is_on_sale(),
		'has_future_start'  => '' !== $sale_price && $starts_at_ts > $now_timestamp,
		'sale_price'        => $sale_price,
		'regular_price'     => $regular_price,
		'starts_at_ts'      => $starts_at_ts,
		'ends_at_ts'        => $ends_at_ts,
		'discount_percent'  => $discount_percent,
		'promo_event_key'   => papelito_build_product_sale_promo_event_key(
			(int) $product->get_id(),
			$sale_price,
			$regular_price,
			$starts_at_ts,
			$ends_at_ts
		),
	);
}

/**
 * Agenda entrada futura de produto em promoção.
 *
 * @param array<string,mixed> $state Snapshot promocional.
 * @return void
 */
function papelito_sync_scheduled_product_promo_event( array $state ) {
	if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
		return;
	}

	$product_id = absint( $state['product_id'] ?? 0 );

	if ( $product_id <= 0 ) {
		return;
	}

	wp_clear_scheduled_hook( PAPELITO_PRODUCT_PROMO_SCHEDULE_HOOK, array( $product_id ) );

	if (
		! function_exists( 'wp_schedule_single_event' ) ||
		empty( $state['is_published'] ) ||
		! empty( $state['is_on_sale'] ) ||
		empty( $state['has_future_start'] ) ||
		empty( $state['starts_at_ts'] )
	) {
		return;
	}

	wp_schedule_single_event( (int) $state['starts_at_ts'], PAPELITO_PRODUCT_PROMO_SCHEDULE_HOOK, array( $product_id ) );
}

/**
 * Monta contexto de promoção manual para disparo do evento unificado.
 *
 * @param array<string,mixed> $state Snapshot promocional.
 * @return array<string,mixed>
 */
function papelito_build_product_sale_promo_context( array $state ) {
	$context = array(
		'promo_type'     => 'sale_price',
		'promo_label'    => 'preço promocional',
		'promo_event_key' => (string) ( $state['promo_event_key'] ?? '' ),
	);

	$regular_price = papelito_notification_promo_number( $state['regular_price'] ?? null );
	$sale_price    = papelito_notification_promo_number( $state['sale_price'] ?? null );
	$discount      = isset( $state['discount_percent'] ) ? absint( $state['discount_percent'] ) : 0;

	if ( null !== $regular_price && $regular_price > 0 ) {
		$context['regular_price'] = $regular_price;
	}

	if ( null !== $sale_price && $sale_price > 0 ) {
		$context['sale_price'] = $sale_price;
	}

	if ( $discount > 0 ) {
		$context['discount_percent'] = $discount;
	}

	return $context;
}

/**
 * Captura o estado persistido antes de salvar um produto.
 *
 * @param WC_Product $product Produto em edição.
 * @return void
 */
function papelito_capture_product_promo_state_before_save( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$product_id = (int) $product->get_id();

	if ( $product_id <= 0 ) {
		return;
	}

	$stored = wc_get_product( $product_id );

	if ( $stored instanceof WC_Product ) {
		$GLOBALS['papelito_product_promo_previous_states'][ $product_id ] = papelito_product_promo_state_snapshot( $stored );
	}
}
add_action( 'woocommerce_before_product_object_save', 'papelito_capture_product_promo_state_before_save', 10, 1 );

/**
 * Dispara promoções manuais quando o produto passa a estar efetivamente em oferta.
 *
 * @param int        $product_id ID do produto salvo.
 * @param WC_Product $product    Instância atual.
 * @return void
 */
function papelito_handle_product_promo_state_after_save( $product_id, $product = null ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 ) {
		return;
	}

	if ( ! $product instanceof WC_Product ) {
		$product = wc_get_product( $product_id );
	}

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$previous = isset( $GLOBALS['papelito_product_promo_previous_states'][ $product_id ] ) && is_array( $GLOBALS['papelito_product_promo_previous_states'][ $product_id ] )
		? $GLOBALS['papelito_product_promo_previous_states'][ $product_id ]
		: null;

	unset( $GLOBALS['papelito_product_promo_previous_states'][ $product_id ] );

	$current = papelito_product_promo_state_snapshot( $product );

	papelito_sync_scheduled_product_promo_event( $current );

	if ( empty( $current['is_on_sale'] ) || ( is_array( $previous ) && ! empty( $previous['is_on_sale'] ) ) ) {
		return;
	}

	do_action(
		'papelito_product_on_promo',
		$product_id,
		papelito_build_product_sale_promo_context( $current )
	);
}
add_action( 'woocommerce_update_product', 'papelito_handle_product_promo_state_after_save', 10, 2 );

/**
 * Processa uma promoção manual agendada quando chega a data de início.
 *
 * @param int $product_id Produto agendado.
 * @return void
 */
function papelito_process_scheduled_product_promo( $product_id ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 ) {
		return;
	}

	$product = wc_get_product( $product_id );

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$current = papelito_product_promo_state_snapshot( $product );

	if ( empty( $current['is_on_sale'] ) ) {
		return;
	}

	do_action(
		'papelito_product_on_promo',
		$product_id,
		papelito_build_product_sale_promo_context( $current )
	);
}
add_action( PAPELITO_PRODUCT_PROMO_SCHEDULE_HOOK, 'papelito_process_scheduled_product_promo', 10, 1 );

/**
 * Notify thread participants when a support message is sent.
 *
 * @param int $thread_id Thread identifier.
 * @param int $message_id Message identifier.
 * @param int $sender_id Sender identifier.
 */
function papelito_handle_support_message_notification( $thread_id, $message_id, $sender_id ) {
	if ( ! function_exists( 'papelito_messaging_get_thread' ) || ! function_exists( 'papelito_messaging_notification_recipients' ) ) {
		return;
	}

	$thread_id = absint( $thread_id );
	$sender_id = absint( $sender_id );
	$thread    = papelito_messaging_get_thread( $thread_id );

	if ( null === $thread ) {
		return;
	}

	foreach ( papelito_messaging_notification_recipients( $thread ) as $recipient_id ) {
		if ( $recipient_id !== $sender_id ) {
			$payload                   = papelito_messaging_notification_payload( $thread_id, $sender_id );
			$payload['recipient_role'] = papelito_messaging_user_role( $recipient_id );
			papelito_dispatch_notification( $recipient_id, PAPELITO_NOTIF_SUPPORT_MESSAGE, $payload );
		}
	}
}
add_action( 'papelito_support_message_sent', 'papelito_handle_support_message_notification', 10, 3 );

/**
 * Notify vendor and administrators when a customer escalates support.
 *
 * @param int $thread_id Thread identifier.
 * @param int $customer_id Customer identifier.
 */
function papelito_handle_support_escalated_notification( $thread_id, $customer_id ) {
	if ( ! function_exists( 'papelito_messaging_get_thread' ) || ! function_exists( 'papelito_messaging_notification_recipients' ) ) {
		return;
	}

	$thread_id   = absint( $thread_id );
	$customer_id = absint( $customer_id );
	$thread      = papelito_messaging_get_thread( $thread_id );

	if ( null === $thread ) {
		return;
	}

	foreach ( papelito_messaging_notification_recipients( $thread ) as $recipient_id ) {
		if ( $recipient_id !== $customer_id ) {
			$payload                   = papelito_messaging_notification_payload( $thread_id, $customer_id );
			$payload['recipient_role'] = papelito_messaging_user_role( $recipient_id );
			papelito_dispatch_notification( $recipient_id, PAPELITO_NOTIF_SUPPORT_ESCALATED, $payload );
		}
	}
}
add_action( 'papelito_support_escalated', 'papelito_handle_support_escalated_notification', 10, 2 );

/**
 * Notifica admins sobre produto publicado sem peso.
 *
 * @param int     $post_id ID do post.
 * @param WP_Post $post    Post salvo.
 * @return void
 */
function papelito_handle_product_missing_weight_notification( $post_id, $post ) {
	if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}

	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;

	if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() || papelito_product_has_valid_weight( $product ) ) {
		return;
	}

	$payload = papelito_notification_product_payload( $post_id );
	$admins  = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
		)
	);

	foreach ( is_array( $admins ) ? $admins : array() as $admin_id ) {
		$admin_id = absint( $admin_id );

		if ( $admin_id <= 0 || papelito_user_has_unread_product_notification( $admin_id, PAPELITO_NOTIF_PRODUCT_MISSING_WEIGHT, $post_id ) ) {
			continue;
		}

		papelito_dispatch_notification( $admin_id, PAPELITO_NOTIF_PRODUCT_MISSING_WEIGHT, $payload );
	}
}
add_action( 'save_post_product', 'papelito_handle_product_missing_weight_notification', 20, 2 );

/**
 * Faz um scan leve dos produtos publicados sem peso quando um admin consulta notificações.
 *
 * @param int $user_id Usuário autenticado.
 * @return void
 */
function papelito_maybe_scan_missing_weight_products_for_admin( $user_id ) {
	$user_id = absint( $user_id );

	if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) {
		return;
	}

	$transient_key = 'papelito_missing_weight_scan_' . $user_id;

	if ( get_transient( $transient_key ) ) {
		return;
	}

	set_transient( $transient_key, '1', 15 * MINUTE_IN_SECONDS );

	$product_ids = get_posts(
		array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	foreach ( is_array( $product_ids ) ? $product_ids : array() as $product_id ) {
		$post = get_post( $product_id );

		if ( $post instanceof WP_Post ) {
			papelito_handle_product_missing_weight_notification( (int) $product_id, $post );
		}
	}
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/notifications/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_require_notifications_auth',
				'args'                => array(
					'unread_only' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'page'        => array( 'type' => 'integer', 'default' => 1 ),
					'per_page'    => array( 'type' => 'integer', 'default' => 20 ),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;

					$user_id     = get_current_user_id();
					papelito_maybe_scan_missing_weight_products_for_admin( $user_id );
					$page        = max( 1, (int) $request->get_param( 'page' ) );
					$per_page    = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) );
					$unread_only = (bool) $request->get_param( 'unread_only' );
					$offset      = ( $page - 1 ) * $per_page;
					$table       = papelito_notifications_table_name();

					$where_sql = 'user_id = %d';
					$params    = array( $user_id );

					if ( $unread_only ) {
						$where_sql .= ' AND read_at IS NULL';
					}

					$total = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
							$params
						)
					);

					$query_params   = $params;
					$query_params[] = $per_page;
					$query_params[] = $offset;

					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT id, user_id, type, payload, read_at, created_at
							 FROM {$table}
							 WHERE {$where_sql}
							 ORDER BY created_at DESC, id DESC
							 LIMIT %d OFFSET %d",
							$query_params
						),
						ARRAY_A
					);

					$items = array_map(
						'papelito_notification_map_row',
						is_array( $rows ) ? $rows : array()
					);

					return new WP_REST_Response(
						array(
							'items'    => $items,
							'total'    => $total,
							'page'     => $page,
							'perPage'  => $per_page,
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/notifications/me/unread-count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_require_notifications_auth',
				'callback'            => static function () {
					$user_id = get_current_user_id();
					papelito_maybe_scan_missing_weight_products_for_admin( $user_id );

					return new WP_REST_Response(
						array( 'count' => papelito_get_unread_notifications_count( $user_id ) ),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/notifications/me/(?P<id>\d+)/read',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_require_notifications_auth',
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;

					$user_id         = get_current_user_id();
					$notification_id = absint( $request->get_param( 'id' ) );
					$table           = papelito_notifications_table_name();

					$exists = papelito_get_user_notification( $user_id, $notification_id );
					if ( null === $exists ) {
						return new WP_Error( 'papelito_notification_not_found', 'Notificação não encontrada.', array( 'status' => 404 ) );
					}

					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL",
							current_time( 'mysql', true ),
							$notification_id,
							$user_id
						)
					);

					return new WP_REST_Response(
						array(
							'item'        => papelito_get_user_notification( $user_id, $notification_id ),
							'unreadCount' => papelito_get_unread_notifications_count( $user_id ),
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/notifications/me/read-all',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_require_notifications_auth',
				'callback'            => static function () {
					global $wpdb;

					$user_id = get_current_user_id();
					$table   = papelito_notifications_table_name();

					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} SET read_at = %s WHERE user_id = %d AND read_at IS NULL",
							current_time( 'mysql', true ),
							$user_id
						)
					);

					return new WP_REST_Response(
						array(
							'success'     => true,
							'unreadCount' => 0,
						),
						200
					);
				},
			)
		);
	}
);
