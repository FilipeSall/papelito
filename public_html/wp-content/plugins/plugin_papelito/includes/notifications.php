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

if ( ! defined( 'PAPELITO_NOTIF_NEW_VENDOR_APPLICATION' ) ) {
	define( 'PAPELITO_NOTIF_NEW_VENDOR_APPLICATION', 'new_vendor_application' );
	define( 'PAPELITO_NOTIF_FAVORITE_ON_PROMO', 'favorite_on_promo' );
	define( 'PAPELITO_NOTIF_VENDOR_APPROVED', 'vendor_approved' );
	define( 'PAPELITO_NOTIF_VENDOR_REJECTED', 'vendor_rejected' );
	define( 'PAPELITO_NOTIF_STOCK_ZEROED', 'stock_zeroed' );
	define( 'PAPELITO_NOTIF_SUPPORT_MESSAGE', 'support_message' );
	define( 'PAPELITO_NOTIF_SUPPORT_ESCALATED', 'support_escalated' );
	define( 'PAPELITO_NOTIF_PRODUCT_MISSING_WEIGHT', 'product_missing_weight' );
}

/**
 * Resolve o nome completo da tabela de notificações.
 */
function papelito_notifications_table_name() {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_NOTIFICATIONS_TABLE;
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
  payload LONGTEXT NULL,
  read_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_unread (user_id, read_at),
  KEY idx_user_created (user_id, created_at)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
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
function papelito_dispatch_notification( $user_id, $type, $payload = array() ) {
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
			'payload'    => $payload_json,
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s' )
	);

	return false === $inserted ? false : (int) $wpdb->insert_id;
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
 * Notifica admins sobre candidatura de vendor.
 */
function papelito_handle_vendor_application_submitted( $vendor_user_id ) {
	$vendor_user_id = absint( $vendor_user_id );

	if ( $vendor_user_id <= 0 ) {
		return;
	}

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
		)
	);

	$payload = array(
		'vendor_user_id' => $vendor_user_id,
		'store_name'     => (string) get_user_meta( $vendor_user_id, 'store_name', true ),
		'city'           => (string) get_user_meta( $vendor_user_id, 'city', true ),
		'state'          => (string) get_user_meta( $vendor_user_id, 'state', true ),
	);

	foreach ( is_array( $admins ) ? $admins : array() as $admin_id ) {
		papelito_dispatch_notification( (int) $admin_id, PAPELITO_NOTIF_NEW_VENDOR_APPLICATION, $payload );
	}
}
add_action( 'papelito_vendor_application_submitted', 'papelito_handle_vendor_application_submitted', 10, 1 );

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
 * Notifica vendor quando estoque zera.
 */
function papelito_handle_stock_zeroed_notification( $vendor_id, $product_id ) {
	$payload = papelito_notification_product_payload( $product_id );
	papelito_dispatch_notification( absint( $vendor_id ), PAPELITO_NOTIF_STOCK_ZEROED, $payload );
}
add_action( 'papelito_stock_zeroed', 'papelito_handle_stock_zeroed_notification', 10, 2 );

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
	$payload = array_merge(
		papelito_notification_product_payload( $product_id ),
		array(
			'promo_type'  => sanitize_key( (string) ( $context['promo_type'] ?? $context['promoType'] ?? 'promo' ) ),
			'promo_label' => sanitize_text_field( (string) ( $context['promo_label'] ?? $context['promoLabel'] ?? 'Promoção' ) ),
		)
	);

	foreach ( papelito_notification_users_who_favorited_product( $product_id ) as $user_id ) {
		if ( papelito_user_has_unread_product_notification( $user_id, PAPELITO_NOTIF_FAVORITE_ON_PROMO, $product_id ) ) {
			continue;
		}

		papelito_dispatch_notification( $user_id, PAPELITO_NOTIF_FAVORITE_ON_PROMO, $payload );
	}
}
add_action( 'papelito_product_on_promo', 'papelito_handle_product_on_promo_notification', 10, 2 );

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
