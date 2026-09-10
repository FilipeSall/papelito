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
	define( 'PAPELITO_MESSAGE_ORDER_NOT_FOUND', 'Pedido nao encontrado.' );
	define( 'PAPELITO_MESSAGE_THREAD_START_FAILED', 'Nao foi possivel iniciar a conversa.' );
	define( 'PAPELITO_MESSAGE_THREAD_NOT_FOUND', 'Conversa nao encontrada.' );
	define( 'PAPELITO_MESSAGE_RATE_LIMITED', 'Aguarde alguns instantes antes de iniciar outra conversa.' );
	define( 'PAPELITO_MESSAGE_NOT_INFORMED', 'não informada' );
	define( 'PAPELITO_CHAMADO_STATUS_OPEN', 'ABERTO' );
	define( 'PAPELITO_CHAMADO_STATUS_CLOSED', 'ENCERRADO' );
	define( 'PAPELITO_CHAMADO_REASON_RETURN', 'devolucao' );
	define( 'PAPELITO_MESSAGE_CONTENT_MAX_NODES', 100 );
	define( 'PAPELITO_MESSAGE_THREAD_CLOSED', 'Este chamado foi encerrado. Abra um novo chamado para continuar.' );
}

if ( ! defined( 'PAPELITO_REST_NAMESPACE' ) ) {
	define( 'PAPELITO_REST_NAMESPACE', 'papelito/v1' );
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
  order_id BIGINT UNSIGNED NULL DEFAULT NULL,
  return_request_id BIGINT UNSIGNED NULL DEFAULT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  context VARCHAR(64) NOT NULL DEFAULT 'order',
  support_key VARCHAR(100) NULL DEFAULT NULL,
  reason VARCHAR(48) NULL DEFAULT NULL,
  return_reason VARCHAR(32) NULL DEFAULT NULL,
  reason_other TEXT NULL DEFAULT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ABERTO',
  closed_at DATETIME NULL DEFAULT NULL,
  closed_by BIGINT UNSIGNED NULL DEFAULT NULL,
  escalated_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_return_request (return_request_id),
  UNIQUE KEY uniq_support_key (support_key),
  KEY idx_customer_updated (customer_id, updated_at),
  KEY idx_vendor_updated (vendor_id, updated_at),
  KEY idx_escalated_updated (escalated_at, updated_at),
  KEY idx_order_status (order_id, status, updated_at),
  KEY idx_customer_status_updated (customer_id, status, updated_at),
  KEY idx_vendor_status_updated (vendor_id, status, updated_at),
  KEY idx_status_updated (status, updated_at)
) {$charset_collate};";

	$messages_sql = "CREATE TABLE {$tables['messages']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  body_content LONGTEXT NULL DEFAULT NULL,
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

	$wpdb->query( "ALTER TABLE {$tables['threads']} MODIFY order_id BIGINT UNSIGNED NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$tables['threads']} LIKE %s", 'return_request_id' ) );
	if ( null === $column ) {
		$wpdb->query( "ALTER TABLE {$tables['threads']} ADD return_request_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD UNIQUE KEY uniq_return_request (return_request_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Colunas do chamado. A tabela carrega deriva de indentação desde a instalação original, e o
	// guard por SHOW COLUMNS torna a migração determinística em vez de depender do diff do dbDelta.
	$thread_columns = array(
		'reason'        => 'ADD reason VARCHAR(48) NULL DEFAULT NULL',
		'return_reason' => 'ADD return_reason VARCHAR(32) NULL DEFAULT NULL',
		'reason_other'  => 'ADD reason_other TEXT NULL DEFAULT NULL',
		'status'        => "ADD status VARCHAR(16) NOT NULL DEFAULT 'ABERTO'",
		'closed_at'     => 'ADD closed_at DATETIME NULL DEFAULT NULL',
		'closed_by'     => 'ADD closed_by BIGINT UNSIGNED NULL DEFAULT NULL',
	);

	foreach ( $thread_columns as $name => $clause ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$tables['threads']} LIKE %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $exists ) {
			$wpdb->query( "ALTER TABLE {$tables['threads']} {$clause}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	$content_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$tables['messages']} LIKE %s", 'body_content' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( null === $content_column ) {
		$wpdb->query( "ALTER TABLE {$tables['messages']} ADD body_content LONGTEXT NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Multiplos chamados por pedido. O dbDelta nunca remove indice, entao a queda de uniq_order e
	// explicita: sem ela um chamado encerrado bloquearia o pedido para sempre.
	$unique_order = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$tables['threads']} WHERE Key_name = %s", 'uniq_order' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( null !== $unique_order ) {
		$wpdb->query( "ALTER TABLE {$tables['threads']} DROP INDEX uniq_order" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

/**
 * Marca como devolucao os chamados abertos antes da coluna `reason`, usando o marcador legado.
 *
 * Sem este backfill todo pedido cuja devolucao ja foi pedida passaria a 409 na formalizacao pelo
 * vendor, porque papelito_return_vendor_open() exige papelito_messaging_return_support_exists().
 */
function papelito_messaging_backfill_chamado_reason(): void {
	global $wpdb;

	$tables = papelito_messaging_tables();
	$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$tables['threads']} LIKE %s", 'reason' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( null === $column ) {
		return;
	}

	$marker = '%' . $wpdb->esc_like( papelito_messaging_return_support_marker() ) . '%';

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->prepare(
			"UPDATE {$tables['threads']} t
			    SET t.reason = %s
			  WHERE t.reason IS NULL
			    AND t.order_id IS NOT NULL
			    AND EXISTS (
			         SELECT 1 FROM {$tables['messages']} m
			          WHERE m.thread_id = t.id
			            AND m.sender_id = t.customer_id
			            AND m.body LIKE %s
			    )",
			PAPELITO_CHAMADO_REASON_RETURN,
			$marker
		)
	);
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
 * @param int  $max Maximo de chamadas na janela.
 * @param int    $window  Janela em segundos.
 */
function papelito_messaging_rate_limit( int $user_id, string $bucket, int $max = 30, int $window = 60 ): bool {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	if ( '' !== $ip ) {
		$identity = 'ip_' . hash( 'sha256', $ip );
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
		return new WP_Error( 'papelito_message_order_not_found', PAPELITO_MESSAGE_ORDER_NOT_FOUND, array( 'status' => 404 ) );
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
 * Colunas lidas de toda thread. Uma lista só, para os cinco leitores não divergirem.
 */
function papelito_messaging_thread_columns(): string {
	return 'id, order_id, return_request_id, customer_id, vendor_id, context, support_key, reason, return_reason, reason_other, status, closed_at, closed_by, escalated_at, created_at, updated_at';
}

/**
 * Load a thread row.
 *
 * @param int $thread_id Thread identifier.
 * @return array<string,mixed>|null
 */
function papelito_messaging_get_thread( int $thread_id ): ?array {
	global $wpdb;

	$table   = papelito_messaging_tables()['threads'];
	$columns = papelito_messaging_thread_columns();
	$row     = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT {$columns} FROM {$table} WHERE id = %d",
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

	$table   = papelito_messaging_tables()['threads'];
	$columns = papelito_messaging_thread_columns();
	$row     = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT {$columns} FROM {$table} WHERE order_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1",
			$order_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Serializa a abertura de chamado por pedido.
 *
 * A checagem de "já existe chamado aberto com este motivo" e a inserção precisam acontecer juntas:
 * com o `uniq_order` removido, o banco não barra mais duas inserções simultâneas, e duplo clique
 * ou retry de timeout criariam dois chamados. Mesmo lock consultivo que a abertura de devolução usa.
 *
 * @param int      $order_id Pedido.
 * @param callable $work     Trabalho a executar com o pedido travado.
 * @return mixed
 */
function papelito_messaging_with_order_lock( int $order_id, callable $work ) {
	global $wpdb;

	$lock = 'papelito_chamado_order_' . $order_id;

	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
		return new WP_Error( 'papelito_message_busy', 'Outra abertura deste chamado está em andamento. Tente novamente.', array( 'status' => 409 ) );
	}

	try {
		return $work();
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

/**
 * Chamado aberto mais recente de um pedido, opcionalmente restrito a um motivo.
 *
 * Substitui a reutilizacao "uma thread por pedido": com uniq_order removido um pedido pode ter
 * varios chamados, e o unico que aceita mensagem nova e o aberto.
 *
 * @param int         $order_id Order identifier.
 * @param string|null $reason   Chamado reason.
 * @return array<string,mixed>|null
 */
function papelito_messaging_get_open_thread_by_order( int $order_id, ?string $reason = null ): ?array {
	global $wpdb;

	$table   = papelito_messaging_tables()['threads'];
	$columns = papelito_messaging_thread_columns();

	$sql    = "SELECT {$columns} FROM {$table} WHERE order_id = %d AND status = %s";
	$params = array( $order_id, PAPELITO_CHAMADO_STATUS_OPEN );

	if ( null !== $reason && '' !== $reason ) {
		$sql     .= ' AND reason = %s';
		$params[] = $reason;
	}

	$sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 1';

	$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	return is_array( $row ) ? $row : null;
}

/**
 * Todos os chamados de um pedido, do mais recente para o mais antigo.
 *
 * @param int $order_id Order identifier.
 * @return array<int,array<string,mixed>>
 */
function papelito_messaging_threads_for_order( int $order_id ): array {
	global $wpdb;

	$table   = papelito_messaging_tables()['threads'];
	$columns = papelito_messaging_thread_columns();
	$rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT {$columns} FROM {$table} WHERE order_id = %d ORDER BY updated_at DESC, id DESC",
			$order_id
		),
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : array();
}

/** Conversa específica de uma Return Request; não reutiliza a thread legada do pedido. */
function papelito_messaging_get_thread_by_return_request( int $return_request_id ): ?array {
	global $wpdb;
	$table = papelito_messaging_tables()['threads'];
	$columns = papelito_messaging_thread_columns();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE return_request_id = %d", $return_request_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return is_array( $row ) ? $row : null;
}

/**
 * Valida o chamado que autoriza a devolução formal pelo vendor.
 *
 * @param int $thread_id   Chamado informado pelo vendor.
 * @param int $order_id    Pedido da rota.
 * @param int $customer_id Comprador do pedido.
 * @param int $vendor_id   Vendor autenticado.
 * @return array<string,mixed>|WP_Error
 */
function papelito_messaging_return_chamado_for_vendor( int $thread_id, int $order_id, int $customer_id, int $vendor_id ) {
	$thread = papelito_messaging_get_thread( $thread_id );

	if ( null === $thread ) {
		return new WP_Error( 'papelito_return_thread_not_found', 'Chamado não encontrado.', array( 'status' => 404 ) );
	}

	if ( absint( $thread['order_id'] ?? 0 ) !== $order_id || absint( $thread['customer_id'] ?? 0 ) !== $customer_id || absint( $thread['vendor_id'] ?? 0 ) !== $vendor_id ) {
		return new WP_Error( 'papelito_return_thread_forbidden', 'Chamado não encontrado.', array( 'status' => 404 ) );
	}

	if ( ! papelito_messaging_is_chamado( $thread ) || PAPELITO_CHAMADO_REASON_RETURN !== (string) ( $thread['reason'] ?? '' ) ) {
		return new WP_Error( 'papelito_return_thread_not_return', 'Este chamado não é uma solicitação de devolução.', array( 'status' => 409 ) );
	}

	if ( PAPELITO_CHAMADO_STATUS_OPEN !== papelito_messaging_thread_status( $thread ) ) {
		return new WP_Error( 'papelito_return_thread_closed', 'Este chamado já está encerrado.', array( 'status' => 409 ) );
	}

	if ( ! papelito_messaging_has_valid_return_reason( $thread ) ) {
		return new WP_Error( 'papelito_return_reason_missing', 'Este chamado não possui um motivo de devolução válido.', array( 'status' => 409 ) );
	}

	return $thread;
}

/**
 * Confere se o motivo persistido no chamado pode iniciar uma devolução.
 *
 * @param array<string,mixed> $thread Chamado.
 */
function papelito_messaging_has_valid_return_reason( array $thread ): bool {
	$return_reason = sanitize_key( (string) ( $thread['return_reason'] ?? '' ) );

	return function_exists( 'papelito_return_reasons' )
		&& in_array( $return_reason, papelito_return_reasons(), true )
		&& ( 'other' !== $return_reason || '' !== trim( (string) ( $thread['reason_other'] ?? '' ) ) );
}

/**
 * Persiste a associação única entre chamado e devolução.
 *
 * @param int $thread_id         Chamado.
 * @param int $return_request_id Devolução criada na transação atual.
 * @param int $order_id          Pedido da devolução.
 * @param int $customer_id       Comprador do pedido.
 * @param int $vendor_id         Vendor do pedido.
 * @return true|WP_Error
 */
function papelito_messaging_attach_return_to_chamado( int $thread_id, int $return_request_id, int $order_id, int $customer_id, int $vendor_id ) {
	global $wpdb;

	$thread = papelito_messaging_return_chamado_for_vendor( $thread_id, $order_id, $customer_id, $vendor_id );
	if ( is_wp_error( $thread ) ) {
		return $thread;
	}

	if ( absint( $thread['return_request_id'] ?? 0 ) > 0 ) {
		return new WP_Error( 'papelito_return_thread_already_linked', 'Este chamado já possui uma devolução.', array( 'status' => 409 ) );
	}

	$table = papelito_messaging_tables()['threads'];
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET return_request_id = %d, updated_at = %s WHERE id = %d AND return_request_id IS NULL AND status = %s",
			$return_request_id,
			current_time( 'mysql', true ),
			$thread_id,
			PAPELITO_CHAMADO_STATUS_OPEN
		)
	);

	return 1 === $updated
		? true
		: new WP_Error( 'papelito_return_thread_link_failed', 'Não foi possível vincular a devolução ao chamado.', array( 'status' => 409 ) );
}

/**
 * Encerra o chamado de devolução no mesmo commit do estorno manual.
 *
 * @param int $return_request_id Devolução estornada.
 * @param int $closed_by         Usuário que registrou o estorno.
 * @return true|WP_Error
 */
function papelito_messaging_close_chamado_for_return( int $return_request_id, int $closed_by ) {
	global $wpdb;

	$table = papelito_messaging_tables()['threads'];
	$now = current_time( 'mysql', true );
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = %s, closed_at = %s, closed_by = %d, updated_at = %s WHERE return_request_id = %d AND context = %s AND status = %s",
			PAPELITO_CHAMADO_STATUS_CLOSED,
			$now,
			$closed_by,
			$now,
			$return_request_id,
			'order',
			PAPELITO_CHAMADO_STATUS_OPEN
		)
	);

	if ( false === $updated ) {
		return new WP_Error( 'papelito_return_thread_close_failed', 'Não foi possível encerrar o chamado da devolução.', array( 'status' => 500 ) );
	}

	if ( 1 === $updated ) {
		return true;
	}

	$thread = papelito_messaging_get_thread_by_return_request( $return_request_id );
	if ( null === $thread || PAPELITO_CHAMADO_STATUS_CLOSED === papelito_messaging_thread_status( $thread ) ) {
		return true;
	}

	return new WP_Error( 'papelito_return_thread_close_failed', 'Não foi possível encerrar o chamado da devolução.', array( 'status' => 409 ) );
}

/**
 * Return a vendor's idempotent Pagar.me bank-account support thread.
 *
 * @param int $vendor_id Vendor identifier.
 * @return array<string,mixed>|null
 */
function papelito_messaging_get_pagarme_bank_account_support_thread( int $vendor_id ): ?array {
	global $wpdb;

	$table       = papelito_messaging_tables()['threads'];
	$columns     = papelito_messaging_thread_columns();
	$support_key = 'vendor-pagarme-bank-account:' . $vendor_id;
	$row         = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT {$columns} FROM {$table} WHERE support_key = %s",
			$support_key
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

	if ( papelito_messaging_admin_can_view( $thread, $user_id ) ) {
		return 'administrator';
	}

	return new WP_Error( 'papelito_message_thread_forbidden', 'Acesso negado a esta conversa.', array( 'status' => 403 ) );
}

/**
 * A Papelito atende qualquer chamado.
 *
 * `escalated_at` deixou de ser porteiro de acesso e virou marca de prioridade: um chamado que o
 * comprador nunca escalou tambem precisa poder ser moderado e encerrado pela Papelito. Isso nao
 * altera quem recebe notificacao — `papelito_messaging_notification_recipients()` continua
 * avisando administradores so no chamado escalado.
 *
 * @param array<string,mixed> $thread  Thread row.
 * @param int                 $user_id User identifier.
 */
function papelito_messaging_admin_can_view( array $thread, int $user_id ): bool {
	unset( $thread );

	return $user_id > 0 && user_can( $user_id, 'manage_options' );
}

/** Contextos que sao chamado: exigem pedido e motivo. */
function papelito_messaging_chamado_contexts(): array {
	return array( 'order' );
}

/**
 * Um chamado tem pedido. Conversas sem pedido — o canal da Pagar.me e as threads de Return
 * Request — sao solicitacoes a Papelito, nao chamados, e ficam fora das listas de chamado.
 *
 * @param array<string,mixed> $thread Thread row.
 */
function papelito_messaging_is_chamado( array $thread ): bool {
	return in_array( (string) ( $thread['context'] ?? 'order' ), papelito_messaging_chamado_contexts(), true )
		&& absint( $thread['order_id'] ?? 0 ) > 0;
}

/** Ciclo de vida do chamado. Sem estados intermediarios e sem reabertura: abre-se outro chamado. */
function papelito_messaging_statuses(): array {
	return array( PAPELITO_CHAMADO_STATUS_OPEN, PAPELITO_CHAMADO_STATUS_CLOSED );
}

/** Status normalizado de uma thread, com o default das linhas antigas. */
function papelito_messaging_thread_status( array $thread ): string {
	$status = strtoupper( trim( (string) ( $thread['status'] ?? '' ) ) );

	return in_array( $status, papelito_messaging_statuses(), true ) ? $status : PAPELITO_CHAMADO_STATUS_OPEN;
}

/** O cliente nunca encerra: quem encerra e quem atende. */
function papelito_messaging_can_close( string $role ): bool {
	return in_array( $role, array( 'seller', 'administrator' ), true );
}

/**
 * Motivos do chamado.
 *
 * Slugs em portugues de proposito: a intersecao com papelito_return_reasons() e vazia, e por isso
 * um chamado geral com "produto avariado" nunca pode ser confundido com um pedido de devolucao —
 * o que autorizaria papelito_return_vendor_open() num pedido onde o comprador nada pediu.
 *
 * @return array<int,string>
 */
function papelito_messaging_reasons(): array {
	return array(
		'duvida_pedido',
		'atraso_entrega',
		'produto_errado',
		'produto_avariado',
		'problema_pagamento',
		PAPELITO_CHAMADO_REASON_RETURN,
		'outro',
	);
}

/**
 * Rotulos dos motivos. Array estatico, entao o rotulo nao custa consulta em nenhum payload.
 *
 * @return array<string,string>
 */
function papelito_messaging_reason_labels(): array {
	return array(
		'duvida_pedido'                => 'Dúvida sobre o pedido',
		'atraso_entrega'               => 'Atraso na entrega',
		'produto_errado'               => 'Recebi o produto errado',
		'produto_avariado'             => 'Produto avariado ou com defeito',
		'problema_pagamento'           => 'Problema com o pagamento',
		PAPELITO_CHAMADO_REASON_RETURN => 'Solicitação de devolução',
		'outro'                        => 'Outro assunto',
	);
}

/**
 * Rotulo legivel do motivo de um chamado, ou null quando a linha e anterior ao modelo.
 *
 * @param array<string,mixed> $thread Thread row.
 */
function papelito_messaging_reason_label( array $thread ): ?string {
	$reason = sanitize_key( (string) ( $thread['reason'] ?? '' ) );

	if ( '' === $reason ) {
		return null;
	}

	$labels = papelito_messaging_reason_labels();
	$label  = $labels[ $reason ] ?? 'Outro assunto';

	if ( PAPELITO_CHAMADO_REASON_RETURN === $reason && function_exists( 'papelito_return_reason_label' ) ) {
		$return_reason = sanitize_key( (string) ( $thread['return_reason'] ?? '' ) );

		if ( '' !== $return_reason ) {
			$label .= ' — ' . papelito_return_reason_label( $return_reason, (string) ( $thread['reason_other'] ?? '' ) );
		}
	}

	return $label;
}

/**
 * Valida o motivo do chamado antes de qualquer escrita.
 *
 * `devolucao` e o unico motivo que faz repasse: exige tambem um motivo do vocabulario de
 * devolucao, validado verbatim por papelito_return_validate_reason(), para o vendor nao precisar
 * re-digitar depois o que o comprador ja escolheu.
 *
 * @param WP_REST_Request $request Request.
 * @return array{reason:string,return_reason:?string,reason_other:?string}|WP_Error
 */
function papelito_messaging_validate_reason( WP_REST_Request $request ) {
	$reason = sanitize_key( (string) $request->get_param( 'reason' ) );

	if ( ! in_array( $reason, papelito_messaging_reasons(), true ) ) {
		return new WP_Error( 'papelito_message_reason_invalid', 'Escolha um motivo para o chamado.', array( 'status' => 422 ) );
	}

	$other = sanitize_textarea_field( (string) $request->get_param( 'reason_other' ) );

	if ( PAPELITO_CHAMADO_REASON_RETURN === $reason ) {
		if ( ! function_exists( 'papelito_return_reasons' ) ) {
			return new WP_Error( 'papelito_return_unavailable', 'Não foi possível abrir o chamado de devolução.', array( 'status' => 503 ) );
		}

		// Parametro proprio: `reason` ja carrega o motivo do chamado nesta rota, e os dois
		// vocabularios nao podem disputar a mesma chave. O valor e validado contra o mesmo enum
		// que papelito_return_validate_reason() usa, entao a formalizacao pelo vendor vai aceita-lo.
		$return_reason = sanitize_key( (string) $request->get_param( 'return_reason' ) );

		if ( ! in_array( $return_reason, papelito_return_reasons(), true ) ) {
			return new WP_Error( 'papelito_message_reason_invalid', 'Informe um motivo válido para a devolução.', array( 'status' => 422 ) );
		}

		if ( 'other' === $return_reason && '' === trim( $other ) ) {
			return new WP_Error( 'papelito_message_reason_invalid', 'Descreva o motivo da devolução.', array( 'status' => 422 ) );
		}

		return array(
			'reason'        => $reason,
			'return_reason' => $return_reason,
			'reason_other'  => '' === trim( $other ) ? null : $other,
		);
	}

	if ( 'outro' === $reason && '' === trim( $other ) ) {
		return new WP_Error( 'papelito_message_reason_invalid', 'Descreva o assunto do chamado.', array( 'status' => 422 ) );
	}

	return array(
		'reason'        => $reason,
		'return_reason' => null,
		'reason_other'  => '' === trim( $other ) ? null : $other,
	);
}

/**
 * Valida o corpo estruturado da mensagem.
 *
 * Nao chama papelito_home_assets_normalize_rich_text_content() de proposito: aquele normalizador
 * colapsa `\r\n\t` em espaco, o que destruiria a quebra de linha da conversa, e libera nos de
 * token da home. Aqui so existe `text` com negrito e italico.
 *
 * @param mixed $raw Submitted content.
 * @return array<int,array<string,mixed>>|null|WP_Error
 */
function papelito_messaging_normalize_body_content( $raw ) {
	if ( null === $raw || '' === $raw ) {
		return null;
	}

	if ( ! is_array( $raw ) || ! array_is_list( $raw ) ) {
		return new WP_Error( 'papelito_message_content_invalid', 'Formato de mensagem inválido.', array( 'status' => 422 ) );
	}

	if ( array() === $raw ) {
		return null;
	}

	if ( count( $raw ) > PAPELITO_MESSAGE_CONTENT_MAX_NODES ) {
		return new WP_Error( 'papelito_message_content_too_many_nodes', 'A mensagem tem formatação demais. Simplifique antes de enviar.', array( 'status' => 422 ) );
	}

	$nodes = array();

	foreach ( $raw as $node ) {
		$entry = papelito_messaging_normalize_body_node( $node );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		$nodes[] = $entry;
	}

	$plain = papelito_messaging_content_to_plain( $nodes );

	return '' === trim( $plain ) ? null : $nodes;
}

/**
 * Valida e normaliza um nó do corpo estruturado.
 *
 * @param mixed $node Nó cru.
 * @return array<string,mixed>|WP_Error
 */
function papelito_messaging_normalize_body_node( $node ) {
	if ( ! is_array( $node ) || 'text' !== ( $node['type'] ?? '' ) || ! is_string( $node['text'] ?? null ) ) {
		return new WP_Error( 'papelito_message_content_node_invalid', 'Formato de mensagem inválido.', array( 'status' => 422 ) );
	}

	$text = (string) $node['text'];

	// Rejeita em vez de manglar: a mensagem aceita texto, negrito e italico, e qualquer tag vinda
	// do cliente e sinal de conteudo que nao deveria estar ali.
	if ( wp_strip_all_tags( $text ) !== $text ) {
		return new WP_Error( 'papelito_message_content_html', 'A mensagem aceita apenas texto, negrito e itálico.', array( 'status' => 422 ) );
	}

	// sanitize_textarea_field preserva `\n`; sanitize_text_field o comeria. E nao ha trim por no:
	// o espaco entre um trecho em negrito e a palavra seguinte e parte da frase.
	$entry = array(
		'type' => 'text',
		'text' => sanitize_textarea_field( $text ),
	);

	if ( rest_sanitize_boolean( $node['bold'] ?? false ) ) {
		$entry['bold'] = true;
	}

	if ( rest_sanitize_boolean( $node['italic'] ?? false ) ) {
		$entry['italic'] = true;
	}

	return $entry;
}

/**
 * Projecao plana do corpo estruturado.
 *
 * @param array<int,array<string,mixed>> $content Nodes.
 */
function papelito_messaging_content_to_plain( array $content ): string {
	return implode(
		'',
		array_map(
			static fn( $node ): string => is_array( $node ) ? (string) ( $node['text'] ?? '' ) : '',
			$content
		)
	);
}

/**
 * Valida a mensagem, com ou sem formatacao.
 *
 * `body` e derivado de `content` e nunca aceito em paralelo: aceitar os dois deixaria a prévia, a
 * notificacao e o e-mail discordarem do que o usuario le na tela. O limite de tamanho continua
 * sendo o de papelito_messaging_validate_body(), aplicado sobre a projecao.
 *
 * @param WP_REST_Request $request Request.
 * @return array{body:string,content:?array<int,array<string,mixed>>}|WP_Error
 */
function papelito_messaging_validate_message( WP_REST_Request $request ) {
	$content = papelito_messaging_normalize_body_content( $request->get_param( 'content' ) );

	if ( is_wp_error( $content ) ) {
		return $content;
	}

	$raw  = null === $content ? $request->get_param( 'body' ) : papelito_messaging_content_to_plain( $content );
	$body = papelito_messaging_validate_body( $raw );

	if ( is_wp_error( $body ) ) {
		return $body;
	}

	return array(
		'body'    => $body,
		'content' => $content,
	);
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

	$content = null;

	if ( ! empty( $row['body_content'] ) ) {
		$decoded = json_decode( (string) $row['body_content'], true );
		$content = is_array( $decoded ) ? $decoded : null;
	}

	return array(
		'id'                => absint( $row['id'] ?? 0 ),
		'sender_id'         => $sender_id,
		'sender_name'       => papelito_messaging_user_name( $sender_id ),
		'sender_role'       => papelito_messaging_user_role( $sender_id ),
		'body'              => (string) ( $row['body'] ?? '' ),
		'content'           => $content,
		'created_at'        => (string) ( $row['created_at'] ?? '' ),
		'is_mine'           => $sender_id === $viewer_id,
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
			"SELECT id, thread_id, sender_id, body, body_content, created_at FROM {$table} WHERE thread_id = %d ORDER BY created_at ASC, id ASC",
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
 * Pré-carrega, em duas consultas, a última mensagem e a contagem de não lidas de uma página.
 *
 * Sem isso a listagem faz três consultas por linha — vinte chamados viram sessenta idas ao banco.
 * Era assim antes, e ficou mais caro quando a Papelito passou a ver todos os chamados em vez de
 * só os escalados; então o lote virou parte da mudança.
 *
 * @param array<int,array<string,mixed>> $threads   Linhas da página.
 * @param int                            $viewer_id Quem está lendo.
 */
function papelito_messaging_prime_summaries( array $threads, int $viewer_id ): void {
	global $wpdb;

	$ids = array_values( array_filter( array_map( static fn( $t ) => absint( $t['id'] ?? 0 ), $threads ) ) );

	if ( empty( $ids ) ) {
		return;
	}

	$tables       = papelito_messaging_tables();
	$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

	// Última mensagem de cada thread: a subconsulta pega o maior id por thread e o JOIN traz a linha.
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$wpdb->prepare(
			"SELECT m.thread_id, m.id, m.sender_id, m.body, m.body_content, m.created_at
			   FROM {$tables['messages']} m
			   INNER JOIN (
			        SELECT thread_id, MAX(id) AS last_id
			          FROM {$tables['messages']}
			         WHERE thread_id IN ({$placeholders})
			      GROUP BY thread_id
			   ) ultimo ON ultimo.thread_id = m.thread_id AND ultimo.last_id = m.id",
			$ids
		),
		ARRAY_A
	);

	$last = array();
	foreach ( (array) $rows as $row ) {
		$last[ absint( $row['thread_id'] ) ] = $row;
	}

	$counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$wpdb->prepare(
			"SELECT m.thread_id, COUNT(*) AS total
			   FROM {$tables['messages']} m
			   LEFT JOIN {$tables['reads']} r ON r.thread_id = m.thread_id AND r.user_id = %d
			  WHERE m.thread_id IN ({$placeholders})
			    AND m.id > COALESCE(r.last_read_message_id, 0)
			    AND m.sender_id <> %d
		       GROUP BY m.thread_id",
			array_merge( array( $viewer_id ), $ids, array( $viewer_id ) )
		),
		ARRAY_A
	);

	$unread = array();
	foreach ( (array) $counts as $row ) {
		$unread[ absint( $row['thread_id'] ) ] = absint( $row['total'] );
	}

	$GLOBALS['papelito_messaging_summary_cache'] = array(
		'last'      => $last,
		'unread'    => $unread,
		'viewer_id' => $viewer_id,
	);
}

/** Limpa a pré-carga: fora da listagem, cada leitura volta a consultar o banco. */
function papelito_messaging_clear_summary_cache(): void {
	unset( $GLOBALS['papelito_messaging_summary_cache'] );
}

/**
 * Fatia da pré-carga válida para este espectador, ou null quando não há.
 *
 * @return array<string,mixed>|null
 */
function papelito_messaging_summary_cache( int $viewer_id ): ?array {
	$cache = $GLOBALS['papelito_messaging_summary_cache'] ?? null;

	return is_array( $cache ) && absint( $cache['viewer_id'] ?? 0 ) === $viewer_id ? $cache : null;
}

/**
 * Count unread messages for a participant.
 *
 * @param int $thread_id Thread identifier.
 * @param int $user_id   User identifier.
 */
function papelito_messaging_unread_count( int $thread_id, int $user_id ): int {
	global $wpdb;

	$cache = papelito_messaging_summary_cache( $user_id );

	if ( null !== $cache ) {
		return absint( $cache['unread'][ $thread_id ] ?? 0 );
	}

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
	$context        = (string) ( $thread['context'] ?? 'order' );
	$order          = 'order' === $context ? papelito_messaging_order( $order_id ) : null;
	$messages_table = papelito_messaging_tables()['messages'];
	$cache          = papelito_messaging_summary_cache( $viewer_id );
	$message        = null !== $cache
		? ( $cache['last'][ $thread_id ] ?? null )
		: $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, sender_id, body, body_content, created_at FROM {$messages_table} WHERE thread_id = %d ORDER BY created_at DESC, id DESC LIMIT 1",
				$thread_id
			),
			ARRAY_A
		);
	$role           = papelito_messaging_access_role( $thread, $viewer_id );
	$customer       = papelito_messaging_user_name( absint( $thread['customer_id'] ?? 0 ) );
	$vendor         = papelito_messaging_user_name( absint( $thread['vendor_id'] ?? 0 ) );
	$order_number   = '';

	if ( 'order' === $context ) {
		$order_number = is_wp_error( $order ) ? (string) $order_id : (string) $order->get_order_number();
	}

	if ( 'pagarme_bank_account_update' === $context ) {
		$counterpart = 'Suporte Papelito';
	} elseif ( 'seller' === $role ) {
		$counterpart = $customer;
	} elseif ( 'administrator' === $role ) {
		$counterpart = sprintf( '%s / %s', $customer, $vendor );
	} else {
		$counterpart = $vendor;
	}

	$closed_by = absint( $thread['closed_by'] ?? 0 );

	return array(
		'thread_id'        => $thread_id,
		'context'          => $context,
		'order_id'         => $order_id,
		'order_number'     => $order_number,
		'counterpart_name' => $counterpart,
		'last_message'     => is_array( $message ) ? papelito_messaging_map_message( $message, $viewer_id ) : null,
		'opened_at'        => (string) ( $thread['created_at'] ?? '' ),
		'updated_at'       => (string) ( $thread['updated_at'] ?? '' ),
		'unread_count'     => papelito_messaging_unread_count( $thread_id, $viewer_id ),
		'escalated_at'     => empty( $thread['escalated_at'] ) ? null : (string) $thread['escalated_at'],
		'status'           => papelito_messaging_thread_status( $thread ),
		'reason'           => empty( $thread['reason'] ) ? null : (string) $thread['reason'],
		'reason_label'     => papelito_messaging_reason_label( $thread ),
		'closed_at'        => empty( $thread['closed_at'] ) ? null : (string) $thread['closed_at'],
		'closed_by'        => $closed_by > 0 ? $closed_by : null,
		'closed_by_name'   => $closed_by > 0 ? papelito_messaging_user_name( $closed_by ) : null,
		'is_chamado'       => papelito_messaging_is_chamado( $thread ),
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

	$customer_id = absint( $thread['customer_id'] ?? 0 );
	$vendor_id   = absint( $thread['vendor_id'] ?? 0 );
	$is_open     = PAPELITO_CHAMADO_STATUS_OPEN === papelito_messaging_thread_status( $thread );

	$seller = array(
		'id'   => $vendor_id,
		'name' => papelito_messaging_user_name( $vendor_id ),
	);

	// O vendor nao precisa de um link para si mesmo. Comprador e Papelito precisam.
	if ( 'seller' !== $role && papelito_messaging_is_chamado( $thread ) ) {
		$seller['whatsapp_url'] = papelito_messaging_seller_whatsapp_url( $thread );
	}

	return array_merge(
		papelito_messaging_map_thread_summary( $thread, $viewer_id ),
		array(
			'viewer_role'   => $role,
			'return_reason' => empty( $thread['return_reason'] ) ? null : (string) $thread['return_reason'],
			'reason_other'  => empty( $thread['reason_other'] ) ? null : (string) $thread['reason_other'],
			'capabilities'  => array(
				'can_close'    => papelito_messaging_can_close( $role ) && $is_open,
				'can_escalate' => 'customer' === $role && $is_open && empty( $thread['escalated_at'] ),
				'can_reply'    => $is_open,
				'can_start_return' => 'seller' === $role && $is_open && PAPELITO_CHAMADO_REASON_RETURN === (string) ( $thread['reason'] ?? '' ) && empty( $thread['return_request_id'] ) && papelito_messaging_has_valid_return_reason( $thread ),
			),
			'order'          => papelito_messaging_order_context( $thread ),
			'return_request' => papelito_messaging_linked_return( $thread ),
			'participants'  => array(
				'customer' => array(
					'id'   => $customer_id,
					'name' => papelito_messaging_user_name( $customer_id ),
				),
				'seller'   => $seller,
			),
			'messages'      => papelito_messaging_messages_for_thread( absint( $thread['id'] ?? 0 ), $viewer_id ),
		)
	);
}

/**
 * Devolução formal ligada a este chamado, quando o vendor já registrou.
 *
 * O chamado é a conversa; a devolução é o processo de logística reversa, com estados próprios.
 * Em vez de duas áreas separadas, o chamado aponta para o registro — uma porta só para o cliente.
 *
 * @param array<string,mixed> $thread Thread row.
 * @return array{id:int,status:string}|null
 */
function papelito_messaging_linked_return( array $thread ): ?array {
	global $wpdb;

	$return_request_id = absint( $thread['return_request_id'] ?? 0 );

	if ( $return_request_id <= 0 || ! function_exists( 'papelito_return_tables' ) ) {
		return null;
	}

	$table = papelito_return_tables()['requests'];
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, status FROM {$table} WHERE id = %d LIMIT 1",
			$return_request_id
		),
		ARRAY_A
	);

	return is_array( $row )
		? array(
			'id'     => absint( $row['id'] ),
			'status' => (string) $row['status'],
		)
		: null;
}

/**
 * Link de WhatsApp do vendor do chamado, ou null quando nao ha telefone cadastrado.
 *
 * @param array<string,mixed> $thread Thread row.
 */
function papelito_messaging_seller_whatsapp_url( array $thread ): ?string {
	if ( ! function_exists( 'papelito_user_whatsapp_url' ) ) {
		return null;
	}

	$order_number = '';
	$order        = papelito_messaging_order( absint( $thread['order_id'] ?? 0 ) );

	if ( ! is_wp_error( $order ) && method_exists( $order, 'get_order_number' ) ) {
		$order_number = (string) $order->get_order_number();
	}

	$message = '' === $order_number
		? 'Olá! Falo sobre um chamado aberto na Papelito.'
		: 'Olá! Falo sobre o pedido #' . $order_number . ' na Papelito.';

	return papelito_user_whatsapp_url( absint( $thread['vendor_id'] ?? 0 ), $message );
}

/**
 * Contexto compacto da compra, servido dentro do proprio chamado.
 *
 * Fica no payload em vez de o front buscar o pedido porque nao existe leitura de detalhe de
 * pedido para admin, e os servicos de comprador e vendor sao escopados por audiencia. Um bloco
 * so atende as tres, com a capacidade de quem chama.
 *
 * @param array<string,mixed> $thread Thread row.
 * @return array<string,mixed>|null
 */
function papelito_messaging_order_context( array $thread ): ?array {
	$order = papelito_messaging_order( absint( $thread['order_id'] ?? 0 ) );

	if ( is_wp_error( $order ) ) {
		return null;
	}

	$items = array();

	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$items[] = array(
			'id'         => (string) $item_id,
			'name'       => sanitize_text_field( (string) $item->get_name() ),
			'quantity'   => (int) $item->get_quantity(),
			'line_total' => (float) $item->get_total(),
		);
	}

	$vendor_id = absint( $thread['vendor_id'] ?? 0 );
	$created   = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;

	return array(
		'id'                   => absint( $order->get_id() ),
		'number'               => sanitize_text_field( (string) $order->get_order_number() ),
		'status'               => sanitize_key( (string) $order->get_status() ),
		'status_label'         => function_exists( 'wc_get_order_status_name' ) ? wp_strip_all_tags( (string) wc_get_order_status_name( $order->get_status() ) ) : '',
		'created_at'           => $created ? (string) $created->date( 'c' ) : '',
		'store_id'             => $vendor_id,
		'store_label'          => papelito_messaging_user_name( $vendor_id ),
		'payment_method_label' => wp_strip_all_tags( (string) $order->get_payment_method_title() ),
		'items'                => $items,
		'items_total_count'    => (int) array_sum( array_column( $items, 'quantity' ) ),
		'subtotal'             => (float) $order->get_subtotal(),
		'shipping'             => (float) $order->get_shipping_total(),
		'total'                => (float) $order->get_total(),
	);
}

/**
 * Start or return the dedicated Pagar.me bank-account authorization conversation.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_messaging_handle_create_pagarme_bank_account_support( WP_REST_Request $request ) {
	$vendor_id = get_current_user_id();

	if ( 'seller' !== papelito_messaging_user_role( $vendor_id ) ) {
		return new WP_Error( 'papelito_message_support_forbidden', 'Acesso negado ao atendimento.', array( 'status' => 403 ) );
	}

	if ( ! papelito_messaging_rate_limit( $vendor_id, 'pagarme_bank_account_support', 5, 60 ) ) {
		return new WP_Error( 'papelito_message_rate_limited', PAPELITO_MESSAGE_RATE_LIMITED, array( 'status' => 429 ) );
	}

	$existing = papelito_messaging_get_pagarme_bank_account_support_thread( $vendor_id );
	if ( null !== $existing ) {
		return new WP_REST_Response( papelito_messaging_thread_detail( $existing, $vendor_id ), 200 );
	}

	$created_at  = current_time( 'mysql', true );
	$support_key = 'vendor-pagarme-bank-account:' . $vendor_id;
	$message     = 'Olá, preciso de ajuda para atualizar a conta bancária do meu recebedor Pagar.me. A Pagar.me informou que é necessária uma autorização adicional para concluir a atualização. Podem verificar e orientar a liberação?';
	$created     = papelito_messaging_create_thread_with_initial_message(
		array(
			'order_id'     => null,
			'customer_id'  => 0,
			'vendor_id'    => $vendor_id,
			'context'      => 'pagarme_bank_account_update',
			'support_key'  => $support_key,
			'escalated_at' => $created_at,
			'created_at'   => $created_at,
			'updated_at'   => $created_at,
		),
		array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ),
		$vendor_id,
		$message
	);

	if ( is_wp_error( $created ) ) {
		$existing = papelito_messaging_get_pagarme_bank_account_support_thread( $vendor_id );
		if ( null !== $existing ) {
			return new WP_REST_Response( papelito_messaging_thread_detail( $existing, $vendor_id ), 200 );
		}
		return $created;
	}

	$thread = papelito_messaging_get_thread( $created['thread_id'] );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_insert_failed', PAPELITO_MESSAGE_THREAD_START_FAILED, array( 'status' => 500 ) );
	}

	papelito_messaging_after_message_insert( $created['thread_id'], $vendor_id, $created['message_id'] );

	return new WP_REST_Response( papelito_messaging_thread_detail( $thread, $vendor_id ), 201 );
}

/**
 * Cria uma conversa e a primeira mensagem na mesma transação.
 *
 * @param array<string,mixed> $thread_data Dados da nova conversa.
 * @param array<int,string>   $thread_format Formato de cada campo da conversa.
 * @return array{thread_id:int,message_id:int}|WP_Error
 */
function papelito_messaging_create_thread_with_initial_message( array $thread_data, array $thread_format, int $sender_id, string $body, ?array $content = null ) {
	global $wpdb;

	$tables = papelito_messaging_tables();

	$wpdb->query( 'START TRANSACTION' );

	$inserted = $wpdb->insert( $tables['threads'], $thread_data, $thread_format );
	if ( false === $inserted ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_message_thread_insert_failed', PAPELITO_MESSAGE_THREAD_START_FAILED, array( 'status' => 500 ) );
	}

	$thread_id  = (int) $wpdb->insert_id;
	$message_id = papelito_messaging_insert_message_row( $thread_id, $sender_id, $body, (string) $thread_data['created_at'], $content );

	if ( is_wp_error( $message_id ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $message_id;
	}

	$wpdb->query( 'COMMIT' );

	return array(
		'thread_id'  => $thread_id,
		'message_id' => $message_id,
	);
}

/**
 * Insere uma mensagem sem abrir ou finalizar transação.
 *
 * @return int|WP_Error
 */
function papelito_messaging_insert_message_row( int $thread_id, int $sender_id, string $body, string $created_at, ?array $content = null ) {
	global $wpdb;

	$inserted = $wpdb->insert(
		papelito_messaging_tables()['messages'],
		array(
			'thread_id'    => $thread_id,
			'sender_id'    => $sender_id,
			'body'         => $body,
			'body_content' => null === $content ? null : wp_json_encode( $content ),
			'created_at'   => $created_at,
		),
		array( '%d', '%d', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'papelito_message_insert_failed', 'Nao foi possivel enviar a mensagem.', array( 'status' => 500 ) );
	}

	return (int) $wpdb->insert_id;
}

/**
 * Atualiza a leitura e dispara notificações após a confirmação da transação.
 */
function papelito_messaging_after_message_insert( int $thread_id, int $sender_id, int $message_id ): void {
	papelito_messaging_mark_read( $thread_id, $sender_id, $message_id );
	do_action( 'papelito_support_message_sent', $thread_id, $message_id, $sender_id );
}

/**
 * Insert one message and emit the notification event.
 *
 * @param array<string,mixed> $thread Thread row.
 * @param int                 $sender_id Sender identifier.
 * @param string              $body Sanitized message body.
 * @return int|WP_Error
 */
function papelito_messaging_insert_message( array $thread, int $sender_id, string $body, ?array $content = null ) {
	global $wpdb;

	$thread_id  = absint( $thread['id'] ?? 0 );
	$created_at = current_time( 'mysql', true );

	$wpdb->query( 'START TRANSACTION' );

	$message_id = papelito_messaging_insert_message_row( $thread_id, $sender_id, $body, $created_at, $content );
	if ( is_wp_error( $message_id ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $message_id;
	}

	$updated = $wpdb->update(
		papelito_messaging_tables()['threads'],
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

	papelito_messaging_after_message_insert( $thread_id, $sender_id, $message_id );

	return $message_id;
}

/** Marcador estável para tornar a abertura do suporte de devolução idempotente. */
function papelito_messaging_return_support_marker(): string {
	return '[Papelito: solicitacao-devolucao]';
}

/**
 * A devolução só pode ser formalizada depois que o comprador abriu o suporte.
 *
 * Não basta haver uma conversa qualquer do pedido: exigimos a mensagem
 * canônica, criada pelo servidor ao acionar "Solicitar devolução".
 */
function papelito_messaging_return_support_exists( int $order_id ): bool {
	global $wpdb;

	$tables = papelito_messaging_tables();

	// Em qualquer status, nao so ABERTO: se o vendor encerra o chamado depois de combinar a
	// devolucao com o comprador, ele nao pode ficar travado para registrar o que acabou de
	// combinar. A semantica e "o comprador pediu devolucao em algum momento".
	$found = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$tables['threads']} WHERE order_id = %d AND reason = %s LIMIT 1",
			$order_id,
			PAPELITO_CHAMADO_REASON_RETURN
		)
	);

	if ( ! empty( $found ) ) {
		return true;
	}

	// Compat com chamados abertos antes da coluna `reason`. O backfill da migracao cobre a base
	// existente; este caminho protege contra migracao parcial. O JOIN e necessario porque um
	// pedido pode ter varios chamados e o marcador pode estar em qualquer um deles.
	$marker = '%' . $wpdb->esc_like( papelito_messaging_return_support_marker() ) . '%';
	$legacy = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT m.id FROM {$tables['messages']} m
			   INNER JOIN {$tables['threads']} t ON t.id = m.thread_id
			  WHERE t.order_id = %d AND m.sender_id = t.customer_id AND m.body LIKE %s LIMIT 1",
			$order_id,
			$marker
		)
	);

	return ! empty( $legacy );
}

/**
 * Motivo de devolucao escolhido pelo comprador no chamado, para o vendor nao re-digitar.
 *
 * @param int $order_id Pedido.
 * @return array{reason:string,other:string}|null
 */
function papelito_messaging_chamado_return_reason( int $order_id ): ?array {
	global $wpdb;

	$table = papelito_messaging_tables()['threads'];
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT return_reason, reason_other FROM {$table}
			  WHERE order_id = %d AND reason = %s AND return_reason IS NOT NULL
			  ORDER BY updated_at DESC, id DESC LIMIT 1",
			$order_id,
			PAPELITO_CHAMADO_REASON_RETURN
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) || '' === (string) ( $row['return_reason'] ?? '' ) ) {
		return null;
	}

	return array(
		'reason' => (string) $row['return_reason'],
		'other'  => (string) ( $row['reason_other'] ?? '' ),
	);
}

/**
 * Mensagem canônica enviada pelo comprador ao abrir o chamado de devolução.
 *
 * Curta de propósito: pedido, loja, datas e valores elegíveis vivem no painel de contexto do
 * chamado, e repeti-los aqui transformava o primeiro turno da conversa num despejo que o
 * comprador não escreveu.
 *
 * @param string $return_reason Motivo do vocabulário de devolução.
 * @param string $reason_other  Texto livre, quando o motivo é `other`.
 * @return array{body:string,content:array<int,array<string,mixed>>}
 */
function papelito_messaging_return_support_message( string $return_reason, string $reason_other = '' ): array {
	$label = function_exists( 'papelito_return_reason_label' )
		? papelito_return_reason_label( $return_reason, $reason_other )
		: sanitize_text_field( $return_reason );

	$content = array(
		array(
			'type' => 'text',
			'text' => 'Olá! Gostaria de solicitar a devolução deste pedido.',
		),
		array(
			'type' => 'text',
			'text' => "\n",
		),
		array(
			'type' => 'text',
			'text' => 'Motivo: ',
			'bold' => true,
		),
		array(
			'type' => 'text',
			'text' => $label,
		),
	);

	return array(
		'body'    => papelito_messaging_content_to_plain( $content ),
		'content' => $content,
	);
}

/**
 * Abre (ou reutiliza) o chamado de suporte de devolução e envia a mensagem
 * pronta. Repetir o clique não cria nem envia uma segunda solicitação.
 */
/**
 * Guardas de entrada da abertura do suporte de devolução.
 *
 * A elegibilidade é a mesma que protege a abertura formal — quem decide
 * continua sendo `papelito_return_order_eligibility()`, não esta camada.
 *
 * @return array{order:object,eligibility:array,vendor_id:int}|WP_Error
 */
function papelito_messaging_return_support_context( int $order_id, int $customer_id ) {
	if ( ! papelito_messaging_rate_limit( $customer_id, 'open_return_support', 10, 60 ) ) {
		return new WP_Error( 'papelito_message_rate_limited', PAPELITO_MESSAGE_RATE_LIMITED, array( 'status' => 429 ) );
	}
	$order = papelito_messaging_order( $order_id );
	if ( is_wp_error( $order ) || (int) $order->get_customer_id() !== $customer_id ) {
		return new WP_Error( 'papelito_message_order_forbidden', PAPELITO_MESSAGE_ORDER_NOT_FOUND, array( 'status' => 404 ) );
	}
	if ( ! function_exists( 'papelito_return_order_eligibility' ) ) {
		return new WP_Error( 'papelito_return_unavailable', 'O fluxo de devolução não está disponível.', array( 'status' => 503 ) );
	}
	$eligibility = papelito_return_order_eligibility( $order, $customer_id );
	if ( empty( $eligibility['can_request'] ) ) {
		return new WP_Error( sanitize_key( (string) ( $eligibility['reason'] ?? 'papelito_return_unavailable' ) ), (string) ( $eligibility['message'] ?? 'A devolução não está disponível para este pedido.' ), array( 'status' => 409 ) );
	}
	$vendor_id = papelito_messaging_order_vendor_id( $order );
	if ( $vendor_id <= 0 ) {
		return new WP_Error( 'papelito_message_vendor_missing', 'Este pedido não possui vendor para atendimento.', array( 'status' => 422 ) );
	}
	return array( 'order' => $order, 'eligibility' => $eligibility, 'vendor_id' => $vendor_id );
}

/**
 * Abre o chamado de devolução, ou devolve o que já está aberto.
 *
 * O motivo é validado antes de qualquer escrita e gravado na própria linha do chamado, então o
 * vendor não precisa mais re-digitar no enum o texto livre que o comprador escreveu na conversa.
 *
 * @param int             $order_id    Pedido.
 * @param int             $customer_id Comprador autenticado.
 * @param WP_REST_Request $request     Request, para a validação do motivo.
 * @return WP_REST_Response|WP_Error
 */
function papelito_messaging_open_return_support( int $order_id, int $customer_id, WP_REST_Request $request ) {
	$context = papelito_messaging_return_support_context( $order_id, $customer_id );

	if ( is_wp_error( $context ) ) {
		return $context;
	}

	if ( ! function_exists( 'papelito_return_validate_reason' ) ) {
		return new WP_Error( 'papelito_return_unavailable', 'O fluxo de devolução não está disponível.', array( 'status' => 503 ) );
	}

	$reason = papelito_return_validate_reason( $request );

	if ( is_wp_error( $reason ) ) {
		return $reason;
	}

	$vendor_id = $context['vendor_id'];

	$message = papelito_messaging_return_support_message( (string) $reason['reason'], (string) $reason['other'] );

	$created = papelito_messaging_with_order_lock(
		$order_id,
		static function () use ( $order_id, $customer_id, $vendor_id, $reason, $message ) {
			// Substitui a idempotência por marcador de texto: uma leitura indexada em
			// idx_order_status, correta agora que um pedido pode ter vários chamados. Sob o lock,
			// um segundo clique encontra o chamado do primeiro em vez de abrir outro.
			$existing = papelito_messaging_get_open_thread_by_order( $order_id, PAPELITO_CHAMADO_REASON_RETURN );

			if ( null !== $existing ) {
				return $existing;
			}

			$now = current_time( 'mysql', true );

			return papelito_messaging_create_thread_with_initial_message(
				array(
					'order_id'      => $order_id,
					'customer_id'   => $customer_id,
					'vendor_id'     => $vendor_id,
					'reason'        => PAPELITO_CHAMADO_REASON_RETURN,
					'return_reason' => (string) $reason['reason'],
					'reason_other'  => '' === (string) $reason['other'] ? null : (string) $reason['other'],
					'status'        => PAPELITO_CHAMADO_STATUS_OPEN,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
				$customer_id,
				$message['body'],
				$message['content']
			);
		}
	);

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	// Chamado que já existia: devolve 200 em vez de abrir outro.
	if ( isset( $created['id'] ) ) {
		return new WP_REST_Response( papelito_messaging_thread_detail( $created, $customer_id ), 200 );
	}

	$thread = papelito_messaging_get_thread( $created['thread_id'] );

	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_insert_failed', PAPELITO_MESSAGE_THREAD_START_FAILED, array( 'status' => 500 ) );
	}

	papelito_messaging_after_message_insert( $created['thread_id'], $customer_id, $created['message_id'] );

	return new WP_REST_Response( papelito_messaging_thread_detail( $thread, $customer_id ), 201 );
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

	$order_id = null === $thread ? 0 : absint( $thread['order_id'] ?? 0 );
	$order    = $order_id > 0 ? papelito_messaging_order( $order_id ) : null;

	return array(
		'thread_id'    => $thread_id,
		'order_id'     => $order_id,
		// Uma consulta por notificação, não por linha de lista: é o que faz o aviso dizer de qual
		// pedido se trata em vez de mandar o cliente adivinhar.
		'order_number' => is_object( $order ) && ! is_wp_error( $order ) ? (string) $order->get_order_number() : '',
		'context'      => null === $thread ? 'order' : (string) ( $thread['context'] ?? 'order' ),
		'sender_name'  => papelito_messaging_user_name( $sender_id ),
		'sender_role'  => papelito_messaging_user_role( $sender_id ),
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
function papelito_messaging_threads_scope( int $user_id, string $search, array $filters = array() ): array {
	global $wpdb;

	$tables = papelito_messaging_tables();

	if ( current_user_can( 'manage_options' ) ) {
		$where  = '1=1';
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

	return papelito_messaging_apply_thread_filters( $where, $params, $filters );
}

/**
 * Aplica os filtros de listagem sobre o escopo de papel.
 *
 * Os filtros so estreitam: o predicado de papel fica sempre na clausula mais interna, e por isso
 * passar o pedido de outra pessoa devolve pagina vazia em vez dos chamados dela.
 *
 * @param string             $where   Clausula do escopo de papel.
 * @param array<int,mixed>   $params  Parametros do escopo.
 * @param array<string,mixed> $filters Filtros da requisicao.
 * @return array{where:string,params:array<int,mixed>}
 */
function papelito_messaging_apply_thread_filters( string $where, array $params, array $filters ): array {
	$table = papelito_messaging_tables()['threads'];

	$status = strtoupper( trim( (string) ( $filters['status'] ?? '' ) ) );
	if ( in_array( $status, papelito_messaging_statuses(), true ) ) {
		$where    = "({$where}) AND {$table}.status = %s";
		$params[] = $status;
	}

	$reason = sanitize_key( (string) ( $filters['reason'] ?? '' ) );
	if ( in_array( $reason, papelito_messaging_reasons(), true ) ) {
		$where    = "({$where}) AND {$table}.reason = %s";
		$params[] = $reason;
	}

	$order_id = absint( $filters['order_id'] ?? 0 );
	if ( $order_id > 0 ) {
		$where    = "({$where}) AND {$table}.order_id = %d";
		$params[] = $order_id;
	}

	$kind = (string) ( $filters['kind'] ?? '' );
	if ( 'chamado' === $kind || 'solicitacao' === $kind ) {
		$contexts     = papelito_messaging_chamado_contexts();
		$placeholders = implode( ', ', array_fill( 0, count( $contexts ), '%s' ) );
		$is_chamado   = 'chamado' === $kind;

		$where  = $is_chamado
			? "({$where}) AND ({$table}.order_id IS NOT NULL AND {$table}.context IN ({$placeholders}))"
			: "({$where}) AND ({$table}.order_id IS NULL OR {$table}.context NOT IN ({$placeholders}))";
		$params = array_merge( $params, $contexts );
	}

	if ( ! empty( $filters['escalated'] ) ) {
		$where = "({$where}) AND {$table}.escalated_at IS NOT NULL";
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
		// O filtro por pedido aceita as tres audiencias: antes o vendor recebia 404 no proprio
		// pedido. 404 e nao 403 para nao confirmar a existencia de pedido alheio.
		$order = papelito_messaging_order( $order_id );

		if ( is_wp_error( $order ) ) {
			return new WP_Error( 'papelito_message_order_forbidden', PAPELITO_MESSAGE_ORDER_NOT_FOUND, array( 'status' => 404 ) );
		}

		$is_customer = (int) $order->get_customer_id() === $user_id;
		$is_vendor   = papelito_messaging_order_vendor_id( $order ) === $user_id;

		if ( ! $is_customer && ! $is_vendor && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'papelito_message_order_forbidden', PAPELITO_MESSAGE_ORDER_NOT_FOUND, array( 'status' => 404 ) );
		}
	}

	$scope     = papelito_messaging_threads_scope(
		$user_id,
		$search,
		array(
			'escalated' => rest_sanitize_boolean( $request->get_param( 'escalated' ) ),
			'kind'      => sanitize_key( (string) $request->get_param( 'kind' ) ),
			'order_id'  => $order_id,
			'reason'    => (string) $request->get_param( 'reason' ),
			'status'    => (string) $request->get_param( 'status' ),
		)
	);
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
	$columns        = papelito_messaging_thread_columns();
	$rows           = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT {$columns}
			 FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
			$query_params
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

	$page_rows = is_array( $rows ) ? $rows : array();
	papelito_messaging_prime_summaries( $page_rows, $user_id );

	$items = array_map(
		static fn( array $row ): array => papelito_messaging_map_thread_summary( $row, $user_id ),
		$page_rows
	);

	papelito_messaging_clear_summary_cache();

	return new WP_REST_Response(
		array(
			'items'       => $items,
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
	$user_id = get_current_user_id();

	if ( ! papelito_messaging_rate_limit( $user_id, 'create_thread', 10, 60 ) ) {
		return new WP_Error( 'papelito_message_rate_limited', PAPELITO_MESSAGE_RATE_LIMITED, array( 'status' => 429 ) );
	}

	$order_id = absint( $request->get_param( 'order_id' ) );
	$order    = papelito_messaging_order( $order_id );

	if ( is_wp_error( $order ) ) {
		return new WP_Error( 'papelito_message_order_forbidden', PAPELITO_MESSAGE_ORDER_NOT_FOUND, array( 'status' => 404 ) );
	}

	$customer_id = (int) $order->get_customer_id();
	$vendor_id   = papelito_messaging_order_vendor_id( $order );

	if ( $user_id !== $customer_id && $user_id !== $vendor_id ) {
		return new WP_Error( 'papelito_message_order_forbidden', PAPELITO_MESSAGE_ORDER_NOT_FOUND, array( 'status' => 404 ) );
	}

	if ( $vendor_id <= 0 ) {
		return new WP_Error( 'papelito_message_vendor_missing', 'Este pedido nao possui vendor para atendimento.', array( 'status' => 422 ) );
	}

	$reason = papelito_messaging_validate_reason( $request );
	if ( is_wp_error( $reason ) ) {
		return $reason;
	}

	$message = papelito_messaging_validate_message( $request );
	if ( is_wp_error( $message ) ) {
		return $message;
	}

	$created = papelito_messaging_with_order_lock(
		$order_id,
		static function () use ( $order_id, $customer_id, $vendor_id, $reason, $user_id, $message ) {
			// Um pedido pode ter varios chamados; o que nao pode e o mesmo assunto duas vezes em
			// aberto. O thread_id vai no erro para o cliente navegar ao chamado que ja existe.
			$existing = papelito_messaging_get_open_thread_by_order( $order_id, $reason['reason'] );

			if ( null !== $existing ) {
				return new WP_Error(
					'papelito_message_chamado_open_exists',
					'Já existe um chamado aberto deste pedido com esse motivo.',
					array(
						'status'    => 409,
						'thread_id' => absint( $existing['id'] ?? 0 ),
					)
				);
			}

			$created_at = current_time( 'mysql', true );

			return papelito_messaging_create_thread_with_initial_message(
				array(
					'order_id'      => $order_id,
					'customer_id'   => $customer_id,
					'vendor_id'     => $vendor_id,
					'reason'        => $reason['reason'],
					'return_reason' => $reason['return_reason'],
					'reason_other'  => $reason['reason_other'],
					'status'        => PAPELITO_CHAMADO_STATUS_OPEN,
					'created_at'    => $created_at,
					'updated_at'    => $created_at,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
				$user_id,
				$message['body'],
				$message['content']
			);
		}
	);

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$thread = papelito_messaging_get_thread( $created['thread_id'] );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_insert_failed', PAPELITO_MESSAGE_THREAD_START_FAILED, array( 'status' => 500 ) );
	}

	papelito_messaging_after_message_insert( $created['thread_id'], $user_id, $created['message_id'] );

	return new WP_REST_Response( papelito_messaging_thread_detail( $thread, $user_id ), 201 );
}

/** POST /messages/orders/{id}/return-support — chamado canônico de devolução do comprador. */
function papelito_messaging_handle_open_return_support( WP_REST_Request $request ) {
	return papelito_messaging_open_return_support( absint( $request->get_param( 'orderId' ) ), get_current_user_id(), $request );
}

/** POST /messages/return-threads — uma conversa independente por Return Request. */
function papelito_messaging_handle_create_return_thread( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$return_id = absint( $request->get_param( 'return_request_id' ) );
	$row = function_exists( 'papelito_return_get' ) ? papelito_return_get( $return_id ) : null;
	if ( ! $row || ( $user_id !== (int) $row['customer_id'] && $user_id !== (int) $row['vendor_id'] ) ) {
		return new WP_Error( 'papelito_message_return_forbidden', 'Devolução não encontrada.', array( 'status' => 404 ) );
	}
	$existing = papelito_messaging_get_thread_by_return_request( $return_id );
	if ( null !== $existing ) { return new WP_REST_Response( papelito_messaging_thread_detail( $existing, $user_id ), 200 ); }
	$body = papelito_messaging_validate_body( $request->get_param( 'body' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$now = current_time( 'mysql', true );
	$created = papelito_messaging_create_thread_with_initial_message(
		array( 'order_id' => null, 'return_request_id' => $return_id, 'customer_id' => (int) $row['customer_id'], 'vendor_id' => (int) $row['vendor_id'], 'context' => 'return', 'created_at' => $now, 'updated_at' => $now ),
		array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' ), $user_id, $body
	);
	if ( is_wp_error( $created ) ) { $existing = papelito_messaging_get_thread_by_return_request( $return_id ); return null !== $existing ? new WP_REST_Response( papelito_messaging_thread_detail( $existing, $user_id ), 200 ) : $created; }
	$thread = papelito_messaging_get_thread( $created['thread_id'] );
	if ( null === $thread ) { return new WP_Error( 'papelito_message_thread_insert_failed', PAPELITO_MESSAGE_THREAD_START_FAILED, array( 'status' => 500 ) ); }
	papelito_messaging_after_message_insert( $created['thread_id'], $user_id, $created['message_id'] );
	return new WP_REST_Response( papelito_messaging_thread_detail( $thread, $user_id ), 201 );
}

/**
 * GET /messages/threads/{id} — detalhe de uma thread.
 */
function papelito_messaging_handle_get_thread( WP_REST_Request $request ) {
	$thread = papelito_messaging_get_thread( absint( $request->get_param( 'id' ) ) );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_not_found', PAPELITO_MESSAGE_THREAD_NOT_FOUND, array( 'status' => 404 ) );
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
		return new WP_Error( 'papelito_message_thread_not_found', PAPELITO_MESSAGE_THREAD_NOT_FOUND, array( 'status' => 404 ) );
	}

	$role = papelito_messaging_access_role( $thread, $user_id );
	if ( is_wp_error( $role ) ) {
		return $role;
	}

	// Sem este guard "encerrado" seria decoracao. Codigo estavel para o cliente transformar o 409
	// em "abrir novo chamado" em vez de reabrir este silenciosamente.
	if ( PAPELITO_CHAMADO_STATUS_CLOSED === papelito_messaging_thread_status( $thread ) ) {
		return new WP_Error( 'papelito_message_thread_closed', PAPELITO_MESSAGE_THREAD_CLOSED, array( 'status' => 409 ) );
	}

	$message = papelito_messaging_validate_message( $request );
	if ( is_wp_error( $message ) ) {
		return $message;
	}

	$message_id = papelito_messaging_insert_message( $thread, $user_id, $message['body'], $message['content'] );
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
		return new WP_Error( 'papelito_message_thread_not_found', PAPELITO_MESSAGE_THREAD_NOT_FOUND, array( 'status' => 404 ) );
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
/**
 * Encerra o chamado.
 *
 * Somente a loja do pedido e a Papelito encerram; o cliente recebe 403. O bloqueio do vendor nao
 * relacionado acontece antes, em papelito_messaging_access_role() — um gate so para leitura,
 * resposta, escalonamento e encerramento.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function papelito_messaging_handle_close( WP_REST_Request $request ) {
	global $wpdb;

	$user_id = get_current_user_id();

	if ( ! papelito_messaging_rate_limit( $user_id, 'close_thread', 20, 60 ) ) {
		return new WP_Error( 'papelito_message_rate_limited', 'Aguarde alguns instantes antes de tentar novamente.', array( 'status' => 429 ) );
	}

	$thread = papelito_messaging_get_thread( absint( $request->get_param( 'id' ) ) );

	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_not_found', PAPELITO_MESSAGE_THREAD_NOT_FOUND, array( 'status' => 404 ) );
	}

	$role = papelito_messaging_access_role( $thread, $user_id );

	if ( is_wp_error( $role ) ) {
		return $role;
	}

	if ( ! papelito_messaging_can_close( $role ) ) {
		return new WP_Error(
			'papelito_message_close_forbidden',
			'Somente a loja ou a Papelito podem encerrar o chamado.',
			array( 'status' => 403 )
		);
	}

	$thread_id = absint( $thread['id'] ?? 0 );

	if ( PAPELITO_CHAMADO_STATUS_CLOSED !== papelito_messaging_thread_status( $thread ) ) {
		$now   = current_time( 'mysql', true );
		$table = papelito_messaging_tables()['threads'];

		// Compare-and-set: dois encerramentos concorrentes nao podem sobrescrever quem encerrou
		// primeiro, entao closed_by e closed_at sao imutaveis depois do primeiro.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, closed_at = %s, closed_by = %d, updated_at = %s
				  WHERE id = %d AND status = %s",
				PAPELITO_CHAMADO_STATUS_CLOSED,
				$now,
				$user_id,
				$now,
				$thread_id,
				PAPELITO_CHAMADO_STATUS_OPEN
			)
		);

		$thread = papelito_messaging_get_thread( $thread_id ) ?? $thread;

		do_action( 'papelito_support_chamado_closed', $thread_id, $user_id );
	}

	// Encerrar duas vezes devolve 200: um POST repetido atras do proxy Next nao pode virar erro
	// para um estado que quem chamou ja queria.
	return new WP_REST_Response( papelito_messaging_thread_detail( $thread, $user_id ), 200 );
}

/**
 * Vocabulario de motivos, para o frontend nao duplicar o que vive no PHP.
 *
 * @return WP_REST_Response
 */
function papelito_messaging_handle_reasons() {
	$labels  = papelito_messaging_reason_labels();
	$reasons = array();

	foreach ( papelito_messaging_reasons() as $reason ) {
		$reasons[] = array(
			'value'                  => $reason,
			'label'                  => $labels[ $reason ] ?? $reason,
			'requires_detail'        => 'outro' === $reason,
			'requires_return_reason' => PAPELITO_CHAMADO_REASON_RETURN === $reason,
		);
	}

	$return_reasons = array();

	if ( function_exists( 'papelito_return_reasons' ) && function_exists( 'papelito_return_reason_labels' ) ) {
		$return_labels = papelito_return_reason_labels();

		foreach ( papelito_return_reasons() as $reason ) {
			$return_reasons[] = array(
				'value'           => $reason,
				'label'           => $return_labels[ $reason ] ?? $reason,
				'requires_detail' => 'other' === $reason,
			);
		}
	}

	return new WP_REST_Response(
		array(
			'reasons'        => $reasons,
			'return_reasons' => $return_reasons,
		),
		200
	);
}

function papelito_messaging_handle_escalate( WP_REST_Request $request ) {
	global $wpdb;

	$user_id = get_current_user_id();

	if ( ! papelito_messaging_rate_limit( $user_id, 'escalate', 5, 60 ) ) {
		return new WP_Error( 'papelito_message_rate_limited', 'Aguarde alguns instantes antes de tentar novamente.', array( 'status' => 429 ) );
	}

	$thread = papelito_messaging_get_thread( absint( $request->get_param( 'id' ) ) );
	if ( null === $thread ) {
		return new WP_Error( 'papelito_message_thread_not_found', PAPELITO_MESSAGE_THREAD_NOT_FOUND, array( 'status' => 404 ) );
	}

	if ( absint( $thread['customer_id'] ?? 0 ) !== $user_id ) {
		return new WP_Error( 'papelito_message_escalate_forbidden', 'Somente o cliente pode escalar o atendimento.', array( 'status' => 403 ) );
	}

	// Escalar um chamado encerrado colocaria a Papelito a responder numa conversa que ninguem
	// pode continuar.
	if ( PAPELITO_CHAMADO_STATUS_CLOSED === papelito_messaging_thread_status( $thread ) ) {
		return new WP_Error( 'papelito_message_thread_closed', PAPELITO_MESSAGE_THREAD_CLOSED, array( 'status' => 409 ) );
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
			PAPELITO_REST_NAMESPACE,
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
			PAPELITO_REST_NAMESPACE,
			'/messages/return-threads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_create_return_thread',
			)
		);

		register_rest_route(
			PAPELITO_REST_NAMESPACE,
			'/messages/orders/(?P<orderId>\d+)/return-support',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_open_return_support',
			)
		);

		register_rest_route(
			PAPELITO_REST_NAMESPACE,
			'/messages/support/pagarme-bank-account',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_create_pagarme_bank_account_support',
			)
		);

		register_rest_route(
			PAPELITO_REST_NAMESPACE,
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
			PAPELITO_REST_NAMESPACE,
			'/messages/threads/(?P<id>\d+)/read',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_mark_read',
			)
		);

		register_rest_route(
			PAPELITO_REST_NAMESPACE,
			'/messages/threads/(?P<id>\d+)/close',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_close',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			PAPELITO_REST_NAMESPACE,
			'/messages/chamado-reasons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_reasons',
			)
		);

		register_rest_route(
			PAPELITO_REST_NAMESPACE,
			'/messages/threads/(?P<id>\d+)/escalate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_messaging_require_auth',
				'callback'            => 'papelito_messaging_handle_escalate',
			)
		);
	}
);
