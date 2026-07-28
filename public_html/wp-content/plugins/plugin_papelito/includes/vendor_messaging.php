<?php
/**
 * Conversas de suporte entre cliente, vendor e administracao.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_MESSAGE_THREADS_TABLE' ) ) {
	define( 'PAPELITO_MESSAGE_THREADS_TABLE', 'papelito_message_threads' );
	define( 'PAPELITO_MESSAGES_TABLE', 'papelito_messages' );
	define( 'PAPELITO_MESSAGE_READS_TABLE', 'papelito_message_reads' );
	define( 'PAPELITO_MESSAGES_DEFAULT_PER_PAGE', 20 );
}

// Custom table identifiers below are derived exclusively from the trusted WordPress table prefix.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Return fully qualified messaging table names.
 *
 * @return array{threads:string,messages:string,reads:string}
 */
function papelito_messaging_tables(): array {
	global $wpdb;

	return array(
		'threads'  => $wpdb->prefix . PAPELITO_MESSAGE_THREADS_TABLE,
		'messages' => $wpdb->prefix . PAPELITO_MESSAGES_TABLE,
		'reads'    => $wpdb->prefix . PAPELITO_MESSAGE_READS_TABLE,
	);
}

/**
 * Create or update messaging tables.
 */
function papelito_messaging_install_tables(): void {
	global $wpdb;

	$tables          = papelito_messaging_tables();
	$charset_collate = $wpdb->get_charset_collate();

	$threads_sql = "CREATE TABLE {$tables['threads']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  escalated_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_order (order_id),
  KEY idx_customer_updated (customer_id, updated_at),
  KEY idx_vendor_updated (vendor_id, updated_at),
  KEY idx_escalated_updated (escalated_at, updated_at)
) {$charset_collate};";

	$messages_sql = "CREATE TABLE {$tables['messages']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_thread_created (thread_id, created_at, id)
) {$charset_collate};";

	$reads_sql = "CREATE TABLE {$tables['reads']} (
  thread_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (thread_id, user_id),
  KEY idx_user_read (user_id, read_at)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $threads_sql );
	dbDelta( $messages_sql );
	dbDelta( $reads_sql );
}

/**
 * Require an authenticated user for messaging endpoints.
 *
 * @return true|WP_Error
 */
function papelito_messaging_require_auth() {
	if ( is_user_logged_in() ) {
		return true;
	}

	return new WP_Error( 'papelito_messages_auth_required', 'Nao autenticado.', array( 'status' => 401 ) );
}

/**
 * Rate limit por IP para escrita em mensagens, com fallback para user_id.
 *
 * @param int    $user_id Usuario autenticado.
 * @param string $bucket  Identificador do endpoint.
 * @param int    $max     Maximo de chamadas na janela.
 * @param int    $window  Janela em segundos.
 */
function papelito_messaging_rate_limit( int $user_id, string $bucket, int $max = 30, int $window = 60 ): bool {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	if ( '' !== $ip ) {
		$identity = 'ip_' . md5( $ip );
	} elseif ( $user_id > 0 ) {
		$identity = 'user_' . $user_id;
	} else {
		return false;
	}

	$key   = 'papelito_msg_rl_' . $bucket . '_' . $identity;
	$count = (int) get_transient( $key );

	if ( $count >= $max ) {
		return false;
	}

	set_transient( $key, $count + 1, $window );

	return true;
}

/**
 * Load one WooCommerce order.
 *
 * @param int $order_id Order identifier.
 * @return object|WP_Error
 */
function papelito_messaging_order( int $order_id ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

	if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
		return new WP_Error( 'papelito_message_order_not_found', 'Pedido nao encontrado.', array( 'status' => 404 ) );
	}

	return $order;
}

/**
 * Resolve the vendor associated with an order.
 *
 * @param object $order WooCommerce order.
 */
function papelito_messaging_order_vendor_id( $order ): int {
	$vendor_id = absint( $order->get_meta( '_papelito_vendor_id', true ) );

	if ( $vendor_id > 0 ) {
		return $vendor_id;
	}

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
			$vendor_id = absint( $item->get_meta( '_vendor_id', true ) );
			if ( $vendor_id > 0 ) {
				return $vendor_id;
			}
		}
	}

	return 0;
}

/**
 * Load a thread row.
 *
 * @param int $thread_id Thread identifier.
 * @return array<string,mixed>|null
 */
function papelito_messaging_get_thread( int $thread_id ): ?array {
	global $wpdb;

	$table = papelito_messaging_tables()['threads'];
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, order_id, customer_id, vendor_id, escalated_at, created_at, updated_at FROM {$table} WHERE id = %d",
			$thread_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Load a thread row for an order.
 *
 * @param int $order_id Order identifier.
 * @return array<string,mixed>|null
 */
function papelito_messaging_get_thread_by_order( int $order_id ): ?array {
	global $wpdb;

	$table = papelito_messaging_tables()['threads'];
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, order_id, customer_id, vendor_id, escalated_at, created_at, updated_at FROM {$table} WHERE order_id = %d",
			$order_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Resolve a participant role for presentation and authorization.
 *
 * @param int $user_id User identifier.
 */
function papelito_messaging_user_role( int $user_id ): string {
	$user = get_userdata( $user_id );

	if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
		return 'administrator';
	}

	if ( $user instanceof WP_User && in_array( 'seller', (array) $user->roles, true ) ) {
		return 'seller';
	}

	return 'customer';
}

/**
 * Validate access to a thread and return the participant role.
 *
 * @param array<string,mixed> $thread Thread row.
 * @param int                 $user_id User identifier.
 * @return string|WP_Error
 */
function papelito_messaging_access_role( array $thread, int $user_id ) {
	if ( absint( $thread['customer_id'] ?? 0 ) === $user_id ) {
		return 'customer';
	}

	if ( absint( $thread['vendor_id'] ?? 0 ) === $user_id ) {
		return 'seller';
	}

	if ( ! empty( $thread['escalated_at'] ) && user_can( $user_id, 'manage_options' ) ) {
		return 'administrator';
	}

	return new WP_Error( 'papelito_message_thread_forbidden', 'Acesso negado a esta conversa.', array( 'status' => 403 ) );
}

/**
 * Sanitize and validate a message body.
 *
 * @param mixed $raw_body Submitted body.
 * @return string|WP_Error
 */
function papelito_messaging_validate_body( $raw_body ) {
	$body   = sanitize_textarea_field( (string) $raw_body );
	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $body ) : strlen( $body );

	if ( 0 === $length ) {
		return new WP_Error( 'papelito_message_empty_body', 'Escreva uma mensagem antes de enviar.', array( 'status' => 422 ) );
	}

	if ( $length > 2000 ) {
		return new WP_Error( 'papelito_message_body_too_long', 'A mensagem deve ter no maximo 2000 caracteres.', array( 'status' => 422 ) );
	}

	return $body;
}

/**
 * Resolve a readable participant name.
 *
 * @param int $user_id User identifier.
 */
function papelito_messaging_user_name( int $user_id ): string {
	$store_name = sanitize_text_field( (string) get_user_meta( $user_id, 'store_name', true ) );

	if ( '' !== $store_name ) {
		return $store_name;
	}

	$user = get_userdata( $user_id );

	return $user instanceof WP_User ? sanitize_text_field( (string) $user->display_name ) : 'Usuário';
}

/**
 * Map a stored message to a REST payload.
 *
 * @param array<string,mixed> $row Message row.
 * @param int                 $viewer_id Current participant identifier.
 * @return array<string,mixed>
 */
function papelito_messaging_map_message( array $row, int $viewer_id ): array {
	$sender_id = absint( $row['sender_id'] ?? 0 );

	return array(
		'id'          => absint( $row['id'] ?? 0 ),
		'sender_id'   => $sender_id,
		'sender_name' => papelito_messaging_user_name( $sender_id ),
		'sender_role' => papelito_messaging_user_role( $sender_id ),
		'body'        => (string) ( $row['body'] ?? '' ),
		'created_at'  => (string) ( $row['created_at'] ?? '' ),
		'is_mine'     => $sender_id === $viewer_id,
	);
}

/**
 * Read the messages stored for one thread.
 *
 * @param int $thread_id Thread identifier.
 * @param int $viewer_id Current participant identifier.
 * @return array<int,array<string,mixed>>
 */
function papelito_messaging_messages_for_thread( int $thread_id, int $viewer_id ): array {
	global $wpdb;

	$table = papelito_messaging_tables()['messages'];
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, thread_id, sender_id, body, created_at FROM {$table} WHERE thread_id = %d ORDER BY created_at ASC, id ASC",
			$thread_id
		),
		ARRAY_A
	);

	return array_map(
		static fn( array $row ): array => papelito_messaging_map_message( $row, $viewer_id ),
		is_array( $rows ) ? $rows : array()
	);
}

/**
 * Count unread messages for a participant.
 *
 * @param int $thread_id Thread identifier.
 * @param int $user_id   User identifier.
 */
function papelito_messaging_unread_count( int $thread_id, int $user_id ): int {
	global $wpdb;

	$tables  = papelito_messaging_tables();
	$last_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT last_read_message_id FROM {$tables['reads']} WHERE thread_id = %d AND user_id = %d",
			$thread_id,
			$user_id
		)
	);

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['messages']} WHERE thread_id = %d AND id > %d AND sender_id <> %d",
			$thread_id,
			$last_id,
			$user_id
		)
	);
}

/**
 * Map a thread for list displays.
 *
 * @param array<string,mixed> $thread Thread row.
 * @param int                 $viewer_id Current participant identifier.
 * @return array<string,mixed>
 */
function papelito_messaging_map_thread_summary( array $thread, int $viewer_id ): array {
	global $wpdb;

	$thread_id      = absint( $thread['id'] ?? 0 );
	$order_id       = absint( $thread['order_id'] ?? 0 );
	$order          = papelito_messaging_order( $order_id );
	$messages_table = papelito_messaging_tables()['messages'];
	$message        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, sender_id, body, created_at FROM {$messages_table} WHERE thread_id = %d ORDER BY created_at DESC, id DESC LIMIT 1",
			$thread_id
		),
		ARRAY_A
	);
	$role           = papelito_messaging_access_role( $thread, $viewer_id );
	$customer       = papelito_messaging_user_name( absint( $thread['customer_id'] ?? 0 ) );
	$vendor         = papelito_messaging_user_name( absint( $thread['vendor_id'] ?? 0 ) );

	if ( 'seller' === $role ) {
		$counterpart = $customer;
	} elseif ( 'administrator' === $role ) {
		$counterpart = sprintf( '%s / %s', $customer, $vendor );
	} else {
		$counterpart = $vendor;
	}

	return array(
		'thread_id'        => $thread_id,
		'order_id'         => $order_id,
		'order_number'     => is_wp_error( $order ) ? (string) $order_id : (string) $order->get_order_number(),
		'counterpart_name' => $counterpart,
		'last_message'     => is_array( $message ) ? papelito_messaging_map_message( $message, $viewer_id ) : null,
		'updated_at'       => (string) ( $thread['updated_at'] ?? '' ),
		'unread_count'     => papelito_messaging_unread_count( $thread_id, $viewer_id ),
		'escalated_at'     => empty( $thread['escalated_at'] ) ? null : (string) $thread['escalated_at'],
	);
}

/**
 * Return full thread data for a participant.
 *
 * @param array<string,mixed> $thread Thread row.
 * @param int                 $viewer_id Current participant identifier.
 * @return array<string,mixed>|WP_Error
 */
function papelito_messaging_thread_detail( array $thread, int $viewer_id ) {
	$role = papelito_messaging_access_role( $thread, $viewer_id );

	if ( is_wp_error( $role ) ) {
		return $role;
	}

	return array_merge(
		papelito_messaging_map_thread_summary( $thread, $viewer_id ),
		array(
			'viewer_role'  => $role,
			'participants' => array(
				'customer' => array(
					'id'   => absint( $thread['customer_id'] ?? 0 ),
					'name' => papelito_messaging_user_name( absint( $thread['customer_id'] ?? 0 ) ),
				),
				'seller'   => array(
					'id'   => absint( $thread['vendor_id'] ?? 0 ),
					'name' => papelito_messaging_user_name( absint( $thread['vendor_id'] ?? 0 ) ),
				),
			),
			'messages'     => papelito_messaging_messages_for_thread( absint( $thread['id'] ?? 0 ), $viewer_id ),
		)
	);
}

/**
 * Insert one message and emit the notification event.
 *
 * @param array<string,mixed> $thread Thread row.
 * @param int                 $sender_id Sender identifier.
 * @param string              $body Sanitized message body.
 * @return int|WP_Error
 */
function papelito_messaging_insert_message( array $thread, int $sender_id, string $body ) {
	global $wpdb;

	$tables     = papelito_messaging_tables();
	$thread_id  = absint( $thread['id'] ?? 0 );
	$created_at = current_time( 'mysql', true );

	$wpdb->query( 'START TRANSACTION' );

	$inserted = $wpdb->insert(
		$tables['messages'],
		array(
			'thread_id'  => $thread_id,
			'sender_id'  => $sender_id,
			'body'       => $body,
			'created_at' => $created_at,
		),
		array( '%d', '%d', '%s', '%s' )
	);

	if ( false === $inserted ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_message_insert_failed', 'Nao foi possivel enviar a mensagem.', array( 'status' => 500 ) );
	}

	$message_id = (int) $wpdb->insert_id;

	$updated = $wpdb->update(
		$tables['threads'],
		array( 'updated_at' => $created_at ),
		array( 'id' => $thread_id ),
		array( '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_message_insert_failed', 'Nao foi possivel enviar a mensagem.', array( 'status' => 500 ) );
	}

	$wpdb->query( 'COMMIT' );

	papelito_messaging_mark_read( $thread_id, $sender_id, $message_id );
	do_action( 'papelito_support_message_sent', $thread_id, $message_id, $sender_id );

	return $message_id;
}

/**
 * Mark a thread read through one message for one participant.
 *
 * @param int      $thread_id  Thread identifier.
 * @param int      $user_id    User identifier.
 * @param int|null $message_id Last seen message identifier.
 */
function papelito_messaging_mark_read( int $thread_id, int $user_id, ?int $message_id = null ): void {
	global $wpdb;

	$tables = papelito_messaging_tables();

	if ( null === $message_id ) {
		$message_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$tables['messages']} WHERE thread_id = %d",
				$thread_id
			)
		);
	}

	$read_at = current_time( 'mysql', true );
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$tables['reads']} (thread_id, user_id, last_read_message_id, read_at)
			 VALUES (%d, %d, %d, %s)
			 ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), read_at = VALUES(read_at)",
			$thread_id,
			$user_id,
			max( 0, $message_id ),
			$read_at
		)
	);
}

/**
 * Build a payload shared by support notifications.
 *
 * @param int $thread_id Thread identifier.
 * @param int $sender_id Sender identifier.
 * @return array<string,mixed>
 */
function papelito_messaging_notification_payload( int $thread_id, int $sender_id ): array {
	$thread = papelito_messaging_get_thread( $thread_id );

	return array(
		'thread_id'   => $thread_id,
		'order_id'    => null === $thread ? 0 : absint( $thread['order_id'] ?? 0 ),
		'sender_name' => papelito_messaging_user_name( $sender_id ),
		'sender_role' => papelito_messaging_user_role( $sender_id ),
	);
}

/**
 * List participant IDs eligible for notifications on a thread.
 *
 * @param array<string,mixed> $thread Thread row.
 * @return array<int,int>
 */
function papelito_messaging_notification_recipients( array $thread ): array {
	$recipients = array(
		absint( $thread['customer_id'] ?? 0 ),
		absint( $thread['vendor_id'] ?? 0 ),
	);

	if ( ! empty( $thread['escalated_at'] ) ) {
		$admins     = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		$recipients = array_merge( $recipients, is_array( $admins ) ? array_map( 'absint', $admins ) : array() );
	}

	return array_values( array_filter( array_unique( $recipients ) ) );
}

/**
 * Constroi o WHERE de listagem de threads para o usuario logado.
 *
 * @param int    $user_id Usuario logado.
 * @param string $search  Filtro livre por pedido ou contraparte.
 * @return array{where:string,params:array<int,mixed>}
 */
function papelito_messaging_threads_scope( int $user_id, string $search ): array {
	global $wpdb;

	$tables = papelito_messaging_tables();

	if ( current_user_can( 'manage_options' ) ) {
		$where  = 'escalated_at IS NOT NULL';
		$params = array();
	} elseif ( 'seller' === papelito_messaging_user_role( $user_id ) ) {
		$where  = 'vendor_id = %d';
		$params = array( $user_id );
	} else {
		$where  = 'customer_id = %d';
		$params = array( $user_id );
	}

	$search = trim( $search );

	if ( '' !== $search ) {
		$like        = '%' . $wpdb->esc_like( $search ) . '%';
		$is_numeric  = ctype_digit( $search );
		$counterpart = "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('store_name','first_name','last_name','billing_company') AND meta_value LIKE %s";
		$by_user     = "{$tables['threads']}.customer_id IN ({$counterpart}) OR {$tables['threads']}.vendor_id IN ({$counterpart})";

		if ( $is_numeric ) {
			$where    = "({$where}) AND ({$tables['threads']}.order_id = %d OR {$by_user})";
			$params[] = (int) $search;
			$params[] = $like;
			$params[] = $like;
		} else {
			$where    = "({$where}) AND ({$by_user})";
			$params[] = $like;
			$params[] = $like;
		}
	}

	return array(
		'where'  => $where,
		'params' => $params,
	);
}

/**
 * GET /messages/threads — listagem por role.
 */
function papelito_messaging_handle_list_threads( WP_REST_Request $request ) {
	global $wpdb;

	$user_id            = get_current_user_id();
	$order_id           = absint( $request->get_param( 'order_id' ) );
	$page               = max( 1, (int) $request->get_param( 'page' ) );
	$requested_per_page = (int) $request->get_param( 'per_page' );
	$per_page           = min( 50, max( 1, $requested_per_page > 0 ? $requested_per_page : PAPELITO_MESSAGES_DEFAULT_PER_PAGE ) );
	$search             = sanitize_text_field( (string) $request->get_param( 'search' ) );
	$table              = papelito_messaging_tables()['threads'];

	if ( $order_id > 0 ) {
		$order = papelito_messaging_order( $order_id );
		if ( is_wp_error( $order ) || (int) $order->get_customer_id() !== $user_id ) {
			return new WP_Error( 'papelito_message_order_forbidden', 'Pedido nao encontrado.', array( 'status' => 404 ) );
		}

		$thread = papelito_messaging_get_thread_by_order( $order_id );
		$items  = null === $thread ? array() : array( papelito_messaging_map_thread_summary( $thread, $user_id ) );

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => count( $items ),
				'page'        => 1,
				'per_page'    => 1,
				'total_pages' => 1,
			),
			200
		);
	}

	$scope     = papelito_messaging_threads_scope( $user_id, $search );
	$where_sql = $scope['where'];
	$params    = $scope['params'];

	// WHERE is composed exclusively from controlled role/search clauses above.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
	$total = (int) $wpdb->get_var(
		empty( $params )
			? "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
			: $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params )
	);

	$query_params   = $params;
	$query_params[] = $per_page;
	$query_params[] = ( $page - 1 ) * $per_page;
	$rows           = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, order_id, customer_id, vendor_id, escalated_at, created_at, updated_at
			 FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
			$query_params
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

	return new WP_REST_Response(
		array(
			'items'       => array_map(
				static fn( array $row ): array => papelito_messaging_map_thread_summary( $row, $user_id ),
				is_array( $rows ) ? $rows : array()
			),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		),
		200
	);
}

/**
 * POST /messages/threads — cliente ou vendor do pedido abre uma nova conversa.
 */
function papelito_messaging_handle_create_thread( WP_REST_Request $request ) {
	global $wpdb;

	$user_id = get_current_user_id();

	if ( ! papelito_messaging_rate_limit( $user_id, 'create_thread', 10, 60 ) ) {
		return new WP_Error( 'papelito_message_rate_limited', 'Aguarde alguns instantes antes de iniciar outra conversa.', array( 'status' => 429 ) );
	}

	$order_id = absint( $request->get_param( 'order_id' ) );
	$order    = papelito_messaging_order( $order_id );

	if ( is_wp_error( $order ) ) {
		return new WP_Error( 'papelito_message_order_forbidden', 'Pedido nao encontrado.', array( 'status' => 404 ) );
	}

	$customer_id = (int) $order->get_customer_id();
	$vendor_id   = papelito_messaging_order_vendor_id( $order );

	if ( $user_id !== $customer_id && $user_id !== $vendor_id ) {
		return new WP_Error( 'papelito_message_order_forbidden', 'Pedido nao encontrado.', array( 'status' => 404 ) );
	}

	$existing = papelito_messaging_get_thread_by_order( $order_id );
	if ( null !== $existing ) {
		return new WP_Error( 'papelito_message_thread_exists', 'A conversa deste pedido ja foi iniciada.', array( 'status' => 409 ) );
	}

	if ( $vendor_id <= 0 ) {
		return new WP_Error( 'papelito_message_vendor_missing', 'Este pedido nao possui vendor para atendimento.', array( 'status' => 422 ) );
	}

	$body = papelito_messaging_validate_body( $request->get_param( 'body' ) );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$created_at = current_time( 'mysql', true );
	$inserted   = $wpdb->insert(
		papelito_messaging_tables()['threads'],
		array(
			'order_id'    => $order_id,
			'customer_id' => $customer_id,
			'vendor_id'   => $vendor_id,
			'created_at'  => $created_at,
			'updated_at'  => $created_at,
		),
		array( '%d', '%d', '%d', '%s', '%s' )
	);

	if ( false === $inserted ) {
		$thread = papelito_messaging_get_thread_by_order( $order_id );
		if ( null !== $thread ) {
			return new WP_Error( 'papelito_message_thread_exists', 'A conversa deste pedido ja foi iniciada.', array( 'status' => 409 ) );
		}

		return new WP_Error( 'papelito_message_thread_insert_failed', 'Nao foi possivel iniciar a conversa.', array( 'status' => 500 ) );
	}

	$thread = papelito_messaging_get_thread( (int) $wpdb->insert_id );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_insert_failed', 'Nao foi possivel iniciar a conversa.', array( 'status' => 500 ) );
	}

	$message_id = papelito_messaging_insert_message( $thread, $user_id, $body );
	if ( is_wp_error( $message_id ) ) {
		return $message_id;
	}

	return new WP_REST_Response( papelito_messaging_thread_detail( $thread, $user_id ), 201 );
}

/**
 * GET /messages/threads/{id} — detalhe de uma thread.
 */
function papelito_messaging_handle_get_thread( WP_REST_Request $request ) {
	$thread = papelito_messaging_get_thread( absint( $request->get_param( 'id' ) ) );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_not_found', 'Conversa nao encontrada.', array( 'status' => 404 ) );
	}

	$detail = papelito_messaging_thread_detail( $thread, get_current_user_id() );

	return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, 200 );
}

/**
 * POST /messages/threads/{id} — envia mensagem.
 */
function papelito_messaging_handle_post_message( WP_REST_Request $request ) {
	$user_id = get_current_user_id();

	if ( ! papelito_messaging_rate_limit( $user_id, 'send_message', 30, 60 ) ) {
		return new WP_Error( 'papelito_message_rate_limited', 'Voce enviou muitas mensagens em pouco tempo. Aguarde alguns segundos.', array( 'status' => 429 ) );
	}

	$thread = papelito_messaging_get_thread( absint( $request->get_param( 'id' ) ) );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_not_found', 'Conversa nao encontrada.', array( 'status' => 404 ) );
	}

	$role = papelito_messaging_access_role( $thread, $user_id );
	if ( is_wp_error( $role ) ) {
		return $role;
	}

	$body = papelito_messaging_validate_body( $request->get_param( 'body' ) );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$message_id = papelito_messaging_insert_message( $thread, $user_id, $body );
	if ( is_wp_error( $message_id ) ) {
		return $message_id;
	}

	$updated = papelito_messaging_get_thread( absint( $thread['id'] ?? 0 ) );

	return new WP_REST_Response( papelito_messaging_thread_detail( $updated ?? $thread, $user_id ), 201 );
}

/**
 * PUT /messages/threads/{id}/read — marca como lida.
 */
function papelito_messaging_handle_mark_read( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$thread  = papelito_messaging_get_thread( absint( $request->get_param( 'id' ) ) );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_not_found', 'Conversa nao encontrada.', array( 'status' => 404 ) );
	}

	$role = papelito_messaging_access_role( $thread, $user_id );
	if ( is_wp_error( $role ) ) {
		return $role;
	}

	papelito_messaging_mark_read( absint( $thread['id'] ?? 0 ), $user_id );

	return new WP_REST_Response( papelito_messaging_thread_detail( $thread, $user_id ), 200 );
}

/**
 * POST /messages/threads/{id}/escalate — cliente eleva para Papelito.
 */
function papelito_messaging_handle_escalate( WP_REST_Request $request ) {
	global $wpdb;

	$user_id = get_current_user_id();

	if ( ! papelito_messaging_rate_limit( $user_id, 'escalate', 5, 60 ) ) {
		return new WP_Error( 'papelito_message_rate_limited', 'Aguarde alguns instantes antes de tentar novamente.', array( 'status' => 429 ) );
	}

	$thread = papelito_messaging_get_thread( absint( $request->get_param( 'id' ) ) );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_not_found', 'Conversa nao encontrada.', array( 'status' => 404 ) );
	}

	if ( absint( $thread['customer_id'] ?? 0 ) !== $user_id ) {
		return new WP_Error( 'papelito_message_escalate_forbidden', 'Somente o cliente pode escalar o atendimento.', array( 'status' => 403 ) );
	}

	if ( empty( $thread['escalated_at'] ) ) {
		$escalated_at = current_time( 'mysql', true );
		$wpdb->update(
			papelito_messaging_tables()['threads'],
			array(
				'escalated_at' => $escalated_at,
				'updated_at'   => $escalated_at,
			),
			array( 'id' => absint( $thread['id'] ?? 0 ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		do_action( 'papelito_support_escalated', absint( $thread['id'] ?? 0 ), $user_id );
		$thread = papelito_messaging_get_thread( absint( $thread['id'] ?? 0 ) ) ?? $thread;
	}

	return new WP_REST_Response( papelito_messaging_thread_detail( $thread, $user_id ), 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/messages/threads',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_messaging_require_auth',
					'callback'            => 'papelito_messaging_handle_list_threads',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_messaging_require_auth',
					'callback'            => 'papelito_messaging_handle_create_thread',
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/messages/threads/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_messaging_require_auth',
					'callback'            => 'papelito_messaging_handle_get_thread',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_messaging_require_auth',
					'callback'            => 'papelito_messaging_handle_post_message',
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/messages/threads/(?P<id>\d+)/read',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_mark_read',
			)
		);

		register_rest_route(
			'papelito/v1',
			'/messages/threads/(?P<id>\d+)/escalate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_escalate',
			)
		);
	}
);
