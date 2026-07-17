<?php
/**
 * Vendor stock — STEP 2 do playbook do marketplace.
 *
 * Camada de estoque por vendor (independente do _stock global do WooCommerce).
 * Expõe helpers consumidos pelas STEPs 4 (coverage), 11 (decremento via pedido)
 * e 15 (UI admin), além de endpoints REST para o painel do vendor e do admin.
 *
 * Emite a action `papelito_stock_zeroed` na transição qty>0 → 0 (consumida pela
 * STEP 3, sistema de notificações).
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_VENDOR_STOCK_TABLE' ) ) {
	define( 'PAPELITO_VENDOR_STOCK_TABLE', 'papelito_vendor_stock' );
}

if ( ! defined( 'PAPELITO_VENDOR_STOCK_LOG_TABLE' ) ) {
	define( 'PAPELITO_VENDOR_STOCK_LOG_TABLE', 'papelito_vendor_stock_log' );
}

/* ------------------------------------------------------------------
 *  Schema
 * ------------------------------------------------------------------ */

/**
 * Resolve nomes completos (com prefixo) das tabelas de estoque.
 *
 * @return array{stock:string,log:string}
 */
function papelito_vendor_stock_table_names() {
	global $wpdb;

	return array(
		'stock' => $wpdb->prefix . PAPELITO_VENDOR_STOCK_TABLE,
		'log'   => $wpdb->prefix . PAPELITO_VENDOR_STOCK_LOG_TABLE,
	);
}

/**
 * Cria/atualiza as tabelas de estoque via dbDelta.
 *
 * Chamado pelo bootstrap de migration em `plugin_papelito.php` quando
 * `papelito_db_version` for inferior à versão atual.
 */
function papelito_vendor_stock_install_tables() {
	global $wpdb;

	$tables          = papelito_vendor_stock_table_names();
	$charset_collate = $wpdb->get_charset_collate();

	$stock_sql = "CREATE TABLE {$tables['stock']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  notified_zero_at DATETIME NULL DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_vendor_product (vendor_id, product_id),
  KEY idx_product (product_id)
) {$charset_collate};";

	$log_sql = "CREATE TABLE {$tables['log']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_vendor (vendor_id, created_at)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $stock_sql );
	dbDelta( $log_sql );
}

/* ------------------------------------------------------------------
 *  Helpers consumidos por outras STEPs
 * ------------------------------------------------------------------ */

/**
 * Retorna a quantidade atual em estoque do vendor para o produto.
 *
 * Linha pode não existir (Q1=A: registros sob demanda). Nesse caso retorna 0.
 */
function papelito_get_vendor_stock( $vendor_id, $product_id ) {
	global $wpdb;

	$vendor_id  = (int) $vendor_id;
	$product_id = (int) $product_id;

	if ( $vendor_id <= 0 || $product_id <= 0 ) {
		return 0;
	}

	$tables = papelito_vendor_stock_table_names();

	$qty = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT qty FROM {$tables['stock']} WHERE vendor_id = %d AND product_id = %d",
			$vendor_id,
			$product_id
		)
	);

	return null === $qty ? 0 : (int) $qty;
}

/**
 * Ajusta o estoque atual do vendor por delta relativo.
 *
 * Consumido pelo roteamento de pedidos para decrementar o estoque de forma
 * atômica, evitando race condition de "ler atual e depois gravar".
 *
 * @param int    $vendor_id  ID do vendor (papel `seller`).
 * @param int    $product_id ID do produto (post WC).
 * @param int    $delta      Delta relativo (pode ser negativo).
 * @param string $reason     Motivo livre (até 120 chars).
 * @return array{ok:bool,qty:int,prev_qty:int,zeroed_event_fired:bool,delta:int}|WP_Error
 */
function papelito_adjust_vendor_stock( $vendor_id, $product_id, $delta, $reason = 'vendor_adjustment' ) {
	global $wpdb;

	$vendor_id  = (int) $vendor_id;
	$product_id = (int) $product_id;
	$delta      = (int) $delta;
	$reason     = substr( (string) $reason, 0, 120 );

	if ( $vendor_id <= 0 ) {
		return new WP_Error( 'papelito_invalid_vendor', 'Vendor inválido.', array( 'status' => 400 ) );
	}

	if ( $product_id <= 0 ) {
		return new WP_Error( 'papelito_invalid_product', 'Produto inválido.', array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product_id ) ) {
		return new WP_Error( 'papelito_product_not_found', 'Produto não encontrado.', array( 'status' => 404 ) );
	}

	$tables = papelito_vendor_stock_table_names();

	$wpdb->query( 'START TRANSACTION' );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT qty, notified_zero_at FROM {$tables['stock']} WHERE vendor_id = %d AND product_id = %d FOR UPDATE",
			$vendor_id,
			$product_id
		),
		ARRAY_A
	);

	$prev_qty             = $row ? (int) $row['qty'] : 0;
	$qty                  = $prev_qty + $delta;
	$raw_notified         = $row['notified_zero_at'] ?? null;
	$is_zero_date         = ( '0000-00-00 00:00:00' === $raw_notified );
	$notified_zero_at     = ( null === $raw_notified || $is_zero_date ) ? null : $raw_notified;
	$zeroed_event_fired   = false;
	$next_notified_zero_at = $notified_zero_at;

	if ( $qty < 0 ) {
		$wpdb->query( 'ROLLBACK' );

		return new WP_Error(
			'papelito_insufficient_vendor_stock',
			'Estoque insuficiente para concluir o ajuste.',
			array(
				'status'   => 409,
				'qty'      => $prev_qty,
				'required' => abs( $delta ),
			)
		);
	}

	if ( $prev_qty > 0 && 0 === $qty && null === $notified_zero_at ) {
		$next_notified_zero_at = current_time( 'mysql', true );
		$zeroed_event_fired    = true;
	} elseif ( 0 === $prev_qty && $qty > 0 ) {
		$next_notified_zero_at = null;
	}

	if ( null === $next_notified_zero_at ) {
		$upsert = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['stock']} (vendor_id, product_id, qty, notified_zero_at)
				 VALUES (%d, %d, %d, NULL)
				 ON DUPLICATE KEY UPDATE qty = VALUES(qty), notified_zero_at = NULL",
				$vendor_id,
				$product_id,
				$qty
			)
		);
	} else {
		$upsert = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['stock']} (vendor_id, product_id, qty, notified_zero_at)
				 VALUES (%d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE qty = VALUES(qty), notified_zero_at = VALUES(notified_zero_at)",
				$vendor_id,
				$product_id,
				$qty,
				$next_notified_zero_at
			)
		);
	}

	if ( false === $upsert ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_stock_write_failed', 'Falha ao gravar estoque.', array( 'status' => 500 ) );
	}

	if ( 0 !== $delta ) {
		$log_written = $wpdb->insert(
			$tables['log'],
			array(
				'vendor_id'  => $vendor_id,
				'product_id' => $product_id,
				'delta'      => $delta,
				'reason'     => $reason,
			),
			array( '%d', '%d', '%d', '%s' )
		);

		if ( false === $log_written ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'papelito_stock_log_failed', 'Falha ao gravar log de estoque.', array( 'status' => 500 ) );
		}
	}

	$wpdb->query( 'COMMIT' );

	if ( $zeroed_event_fired ) {
		do_action( 'papelito_stock_zeroed', $vendor_id, $product_id );
	}

	do_action( 'papelito_vendor_stock_changed', $vendor_id, $product_id );

	return array(
		'ok'                 => true,
		'qty'                => $qty,
		'prev_qty'           => $prev_qty,
		'zeroed_event_fired' => $zeroed_event_fired,
		'delta'              => $delta,
	);
}

/**
 * Define a quantidade em estoque do vendor para o produto.
 *
 * Faz transação com SELECT ... FOR UPDATE para impedir race condition
 * entre admin manual + decremento de pedido. Grava entrada no log apenas
 * quando há delta. Emite `papelito_stock_zeroed` na transição qty>0 → 0.
 *
 * @param int    $vendor_id  ID do vendor (papel `seller`).
 * @param int    $product_id ID do produto (post WC).
 * @param int    $qty        Nova quantidade (>= 0).
 * @param string $reason     Motivo livre (até 120 chars). Obrigatório se a
 *                           chamada vier do admin; default `vendor_update`.
 * @return array{ok:bool,qty:int,prev_qty:int,zeroed_event_fired:bool}|WP_Error
 */
function papelito_set_vendor_stock( $vendor_id, $product_id, $qty, $reason = 'vendor_update' ) {
	global $wpdb;

	$vendor_id  = (int) $vendor_id;
	$product_id = (int) $product_id;
	$qty        = (int) $qty;
	$reason     = substr( (string) $reason, 0, 120 );

	if ( $vendor_id <= 0 ) {
		return new WP_Error( 'papelito_invalid_vendor', 'Vendor inválido.', array( 'status' => 400 ) );
	}

	if ( $product_id <= 0 ) {
		return new WP_Error( 'papelito_invalid_product', 'Produto inválido.', array( 'status' => 400 ) );
	}

	if ( $qty < 0 ) {
		return new WP_Error( 'papelito_invalid_qty', 'Quantidade não pode ser negativa.', array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product_id ) ) {
		return new WP_Error( 'papelito_product_not_found', 'Produto não encontrado.', array( 'status' => 404 ) );
	}

	$tables = papelito_vendor_stock_table_names();

	$wpdb->query( 'START TRANSACTION' );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT qty, notified_zero_at FROM {$tables['stock']} WHERE vendor_id = %d AND product_id = %d FOR UPDATE",
			$vendor_id,
			$product_id
		),
		ARRAY_A
	);

	$prev_qty           = $row ? (int) $row['qty'] : 0;
	$raw_notified       = $row['notified_zero_at'] ?? null;
	$is_zero_date       = ( '0000-00-00 00:00:00' === $raw_notified );
	$notified_zero_at   = ( null === $raw_notified || $is_zero_date ) ? null : $raw_notified;
	$zeroed_event_fired = false;
	$next_notified_zero_at = $notified_zero_at;

	if ( $prev_qty > 0 && 0 === $qty && null === $notified_zero_at ) {
		$next_notified_zero_at = current_time( 'mysql', true );
		$zeroed_event_fired    = true;
	} elseif ( 0 === $prev_qty && $qty > 0 ) {
		$next_notified_zero_at = null;
	}

	if ( null === $next_notified_zero_at ) {
		$upsert = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['stock']} (vendor_id, product_id, qty, notified_zero_at)
				 VALUES (%d, %d, %d, NULL)
				 ON DUPLICATE KEY UPDATE qty = VALUES(qty), notified_zero_at = NULL",
				$vendor_id,
				$product_id,
				$qty
			)
		);
	} else {
		$upsert = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['stock']} (vendor_id, product_id, qty, notified_zero_at)
				 VALUES (%d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE qty = VALUES(qty), notified_zero_at = VALUES(notified_zero_at)",
				$vendor_id,
				$product_id,
				$qty,
				$next_notified_zero_at
			)
		);
	}

	if ( false === $upsert ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_stock_write_failed', 'Falha ao gravar estoque.', array( 'status' => 500 ) );
	}

	$delta = $qty - $prev_qty;
	if ( 0 !== $delta ) {
		$log_written = $wpdb->insert(
			$tables['log'],
			array(
				'vendor_id'  => $vendor_id,
				'product_id' => $product_id,
				'delta'      => $delta,
				'reason'     => $reason,
			),
			array( '%d', '%d', '%d', '%s' )
		);

		if ( false === $log_written ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'papelito_stock_log_failed', 'Falha ao gravar log de estoque.', array( 'status' => 500 ) );
		}
	}

	$wpdb->query( 'COMMIT' );

	if ( $zeroed_event_fired ) {
		do_action( 'papelito_stock_zeroed', $vendor_id, $product_id );
	}

	do_action( 'papelito_vendor_stock_changed', $vendor_id, $product_id );

	return array(
		'ok'                 => true,
		'qty'                => $qty,
		'prev_qty'           => $prev_qty,
		'zeroed_event_fired' => $zeroed_event_fired,
	);
}

/**
 * Lista vendors com estoque > 0 do produto, ordenados por qty desc.
 *
 * Consumido pela STEP 4 (cobertura) cruzado com faixa de CEP.
 *
 * @return array<int,array{vendor_id:int,qty:int}>
 */
function papelito_vendors_with_stock( $product_id ) {
	global $wpdb;

	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return array();
	}

	$tables = papelito_vendor_stock_table_names();

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT vendor_id, qty FROM {$tables['stock']} WHERE product_id = %d AND qty > 0 ORDER BY qty DESC",
			$product_id
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	return array_map(
		static function ( $row ) {
			return array(
				'vendor_id' => (int) $row['vendor_id'],
				'qty'       => (int) $row['qty'],
			);
		},
		$rows
	);
}

/* ------------------------------------------------------------------
 *  Helpers internos de query (REST)
 * ------------------------------------------------------------------ */

/**
 * Resolve se o usuario pode operar como vendor no marketplace.
 */
function papelito_vendor_stock_is_operational_vendor( $user ): bool {
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return false;
	}

	if ( ! in_array( 'seller', (array) $user->roles, true ) ) {
		return false;
	}

	return true;
}

/**
 * Retorna thumbnail pequena do produto para o painel admin.
 */
function papelito_vendor_stock_product_image_url( int $product_id ): string {
	$thumbnail_id = (int) get_post_thumbnail_id( $product_id );
	if ( $thumbnail_id <= 0 ) {
		return '';
	}

	$url = wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' );
	return is_string( $url ) ? $url : '';
}

/**
 * Indica se a pagina publica de produto (`/produtos/{id}` no front) consegue
 * renderizar o produto. O catalogo headless esconde produtos sem peso (frete
 * impossivel) via `hasValidWeight`; espelhamos a mesma regra aqui para nao
 * gerar links mortos no estoque do vendor.
 *
 * Considera publicavel quando o produto efetivo (simples, ou o pai variavel)
 * tem peso positivo nele mesmo ou em qualquer variacao.
 */
function papelito_vendor_stock_product_publicly_viewable( int $effective_id ): bool {
	if ( $effective_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
		return false;
	}

	$product = wc_get_product( $effective_id );
	if ( ! $product ) {
		return false;
	}

	if ( papelito_vendor_stock_has_positive_weight( $product->get_weight() ) ) {
		return true;
	}

	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );
			if ( $variation && papelito_vendor_stock_has_positive_weight( $variation->get_weight() ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Peso positivo segundo a mesma normalizacao do front (`hasPositiveWeight`).
 *
 * @param mixed $weight Valor de peso retornado pelo WooCommerce.
 */
function papelito_vendor_stock_has_positive_weight( $weight ): bool {
	if ( ! is_string( $weight ) && ! is_numeric( $weight ) ) {
		return false;
	}

	$normalized = str_replace( ',', '.', trim( (string) $weight ) );
	if ( '' === $normalized || ! is_numeric( $normalized ) ) {
		return false;
	}

	return (float) $normalized > 0;
}

/**
 * Busca o historico recente de ajustes por produto.
 *
 * @param int   $vendor_id Vendor alvo.
 * @param int[] $product_ids Produtos exibidos na resposta.
 * @param int   $limit_per_product Quantidade maxima por produto.
 * @return array<int, array<int, array<string, mixed>>>
 */
function papelito_vendor_stock_recent_logs( int $vendor_id, array $product_ids, int $limit_per_product = 5 ): array {
	global $wpdb;

	$vendor_id = (int) $vendor_id;
	if ( $vendor_id <= 0 || empty( $product_ids ) ) {
		return array();
	}

	$product_ids = array_values(
		array_filter(
			array_map( 'intval', $product_ids ),
			static fn( int $product_id ): bool => $product_id > 0
		)
	);

	if ( empty( $product_ids ) ) {
		return array();
	}

	$limit_per_product = max( 1, $limit_per_product );
	$tables            = papelito_vendor_stock_table_names();
	$placeholders      = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );
	$params            = array_merge( array( $vendor_id ), $product_ids );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		"SELECT id, product_id, delta, reason, created_at
		FROM {$tables['log']}
		WHERE vendor_id = %d
			AND product_id IN ({$placeholders})
		ORDER BY created_at DESC, id DESC",
		$params
	);

	$rows    = $wpdb->get_results( $sql, ARRAY_A );
	$grouped = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$product_id = (int) ( $row['product_id'] ?? 0 );
		if ( $product_id <= 0 ) {
			continue;
		}

		if ( ! isset( $grouped[ $product_id ] ) ) {
			$grouped[ $product_id ] = array();
		}

		if ( count( $grouped[ $product_id ] ) >= $limit_per_product ) {
			continue;
		}

		$grouped[ $product_id ][] = array(
			'id'         => (int) ( $row['id'] ?? 0 ),
			'delta'      => (int) ( $row['delta'] ?? 0 ),
			'reason'     => sanitize_text_field( (string) ( $row['reason'] ?? '' ) ),
			'created_at' => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	return $grouped;
}

/**
 * Lista paginada de estoque de um vendor com busca opcional por nome/SKU.
 *
 * @param int    $vendor_id Vendor alvo.
 * @param array  $args      Argumentos: page (>=1), per_page (1-100),
 *                          search (string), filter (all|with_stock|zeroed_only),
 *                          sort (name_asc|name_desc|qty_desc|qty_asc|updated_desc),
 *                          category (int term_id), tags (csv|array de term_id),
 *                          paginate (bool), include_history (bool).
 * @return array{items:array,total:int,page:int,per_page:int}
 */
function papelito_vendor_stock_query( $vendor_id, $args ) {
	global $wpdb;

	$vendor_id = (int) $vendor_id;
	$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
	$per_page  = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$search    = trim( (string) ( $args['search'] ?? '' ) );
	$filter    = (string) ( $args['filter'] ?? 'all' );
	$paginate  = ! isset( $args['paginate'] ) || (bool) $args['paginate'];
	$history   = ! empty( $args['include_history'] );

	$sort     = (string) ( $args['sort'] ?? 'name_asc' );
	$sort_map = array(
		'name_asc'     => 'p.post_title ASC, p.ID ASC',
		'name_desc'    => 'p.post_title DESC, p.ID ASC',
		'qty_desc'     => 'COALESCE(vs.qty, 0) DESC, p.ID ASC',
		'qty_asc'      => 'COALESCE(vs.qty, 0) ASC, p.ID ASC',
		'updated_desc' => 'vs.updated_at IS NULL, vs.updated_at DESC, p.ID ASC',
	);
	if ( ! isset( $sort_map[ $sort ] ) ) {
		$sort = 'name_asc';
	}

	$category = isset( $args['category'] ) ? (int) $args['category'] : 0;

	$tag_ids = array();
	if ( ! empty( $args['tags'] ) ) {
		$raw_tags = is_array( $args['tags'] ) ? $args['tags'] : explode( ',', (string) $args['tags'] );
		foreach ( $raw_tags as $raw_tag ) {
			$tag_id = (int) $raw_tag;
			if ( $tag_id > 0 ) {
				$tag_ids[] = $tag_id;
			}
		}
		$tag_ids = array_values( array_unique( $tag_ids ) );
	}

	if ( ! in_array( $filter, array( 'all', 'with_stock', 'zeroed_only' ), true ) ) {
		$filter = 'all';
	}

	$tables   = papelito_vendor_stock_table_names();
	$posts    = $wpdb->posts;
	$postmeta = $wpdb->postmeta;

	$where  = array( 'p.post_type IN (%s, %s)', 'p.post_status = %s' );
	$params = array( 'product', 'product_variation', 'publish' );

	if ( 'with_stock' === $filter ) {
		$where[] = 'COALESCE(vs.qty, 0) > 0';
	} elseif ( 'zeroed_only' === $filter ) {
		$where[] = 'COALESCE(vs.qty, 0) = 0';
	}

	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[]  = '(p.post_title LIKE %s OR sku.meta_value LIKE %s)';
		$params[] = $like;
		$params[] = $like;
	}

	$where_sql = implode( ' AND ', $where );

	$join_sku = "LEFT JOIN {$postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'";

	$effective_id = 'COALESCE(NULLIF(p.post_parent, 0), p.ID)';

	$tax_joins  = '';
	$tax_params = array();
	$need_group = false;

	if ( $category > 0 ) {
		$tax_joins   .= " INNER JOIN {$wpdb->term_relationships} cat_tr ON cat_tr.object_id = {$effective_id}";
		$tax_joins   .= " INNER JOIN {$wpdb->term_taxonomy} cat_tt ON cat_tt.term_taxonomy_id = cat_tr.term_taxonomy_id AND cat_tt.taxonomy = 'product_cat' AND cat_tt.term_id = %d";
		$tax_params[] = $category;
	}

	if ( ! empty( $tag_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
		$tax_joins   .= " INNER JOIN {$wpdb->term_relationships} tag_tr ON tag_tr.object_id = {$effective_id}";
		$tax_joins   .= " INNER JOIN {$wpdb->term_taxonomy} tag_tt ON tag_tt.term_taxonomy_id = tag_tr.term_taxonomy_id AND tag_tt.taxonomy = 'product_tag' AND tag_tt.term_id IN ({$placeholders})";
		$need_group   = true;
		foreach ( $tag_ids as $tag_id ) {
			$tax_params[] = $tag_id;
		}
	}

	$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$posts} p
		LEFT JOIN {$tables['stock']} vs ON vs.product_id = p.ID AND vs.vendor_id = %d
		{$join_sku}
		{$tax_joins}
		WHERE {$where_sql}";

	$count_params = array_merge( array( $vendor_id ), $tax_params, $params );

	$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) );

	$group_sql = $need_group ? ' GROUP BY p.ID' : '';

	$select_sql = "SELECT p.ID AS product_id, COALESCE(vs.qty, 0) AS qty, vs.updated_at, vs.notified_zero_at,
				p.post_title AS product_name, sku.meta_value AS sku, {$effective_id} AS effective_id
			FROM {$posts} p
			LEFT JOIN {$tables['stock']} vs ON vs.product_id = p.ID AND vs.vendor_id = %d
			{$join_sku}
			{$tax_joins}
			WHERE {$where_sql}
			{$group_sql}
			ORDER BY {$sort_map[ $sort ]}";

	$select_params = array_merge( array( $vendor_id ), $tax_params, $params );

	if ( $paginate ) {
		$offset          = ( $page - 1 ) * $per_page;
		$select_sql     .= ' LIMIT %d OFFSET %d';
		$select_params[] = $per_page;
		$select_params[] = $offset;
	}

	$rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $select_params ), ARRAY_A );
	$product_ids   = array();
	$effective_ids = array();
	$items         = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$product_id      = (int) ( $row['product_id'] ?? 0 );
		$effective       = (int) ( $row['effective_id'] ?? $product_id );
		$product_ids[]   = $product_id;
		$effective_ids[] = $effective;
		$items[]         = array(
			'product_id'           => $product_id,
			'public_product_id'    => $effective,
			'is_publicly_viewable' => papelito_vendor_stock_product_publicly_viewable( $effective ),
			'product_name'         => (string) ( $row['product_name'] ?? '' ),
			'sku'                  => (string) ( $row['sku'] ?? '' ),
			'qty'                  => (int) ( $row['qty'] ?? 0 ),
			'updated_at'           => (string) ( $row['updated_at'] ?? '' ),
			'is_zeroed'            => 0 === (int) ( $row['qty'] ?? 0 ),
			'image_url'            => papelito_vendor_stock_product_image_url( $product_id ),
			'history'              => array(),
			'effective_id'         => $effective,
			'categories'           => array(),
			'tags'                 => array(),
		);
	}

	if ( ! empty( $effective_ids ) ) {
		$unique_ids = array_values( array_unique( array_map( 'intval', $effective_ids ) ) );
		$term_map   = array();

		foreach ( array(
			'product_cat' => 'categories',
			'product_tag' => 'tags',
		) as $taxonomy => $key ) {
			$terms = wp_get_object_terms( $unique_ids, $taxonomy, array( 'fields' => 'all_with_object_id' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$object_id                          = (int) $term->object_id;
				$term_map[ $object_id ][ $key ][] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
				);
			}
		}

		foreach ( $items as &$item ) {
			$eff                = (int) $item['effective_id'];
			$item['categories'] = $term_map[ $eff ]['categories'] ?? array();
			$item['tags']       = $term_map[ $eff ]['tags'] ?? array();
			unset( $item['effective_id'] );
		}
		unset( $item );
	}

	if ( $history && ! empty( $product_ids ) ) {
		$history_lookup = papelito_vendor_stock_recent_logs( $vendor_id, $product_ids, 5 );

		foreach ( $items as &$item ) {
			$product_id      = (int) $item['product_id'];
			$item['history'] = $history_lookup[ $product_id ] ?? array();
		}
		unset( $item );
	}

	return array(
		'items'    => $items,
		'total'    => $total,
		'page'     => $page,
		'per_page' => $paginate ? $per_page : max( 1, $total ),
	);
}

/**
 * Lista categorias e tags (product_cat/product_tag) com count > 0,
 * para popular o drawer de filtros do estoque. Cache curto por transient.
 */
function papelito_vendor_stock_taxonomies() {
	$cache_key = 'papelito_vendor_stock_taxonomies_v1';
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$out = array(
		'categories' => array(),
		'tags'       => array(),
	);

	$taxonomies = array(
		'product_cat' => 'categories',
		'product_tag' => 'tags',
	);

	foreach ( $taxonomies as $taxonomy => $key ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			$out[ $key ][] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'slug'  => (string) $term->slug,
				'count' => (int) $term->count,
			);
		}
	}

	set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );

	return $out;
}

/* ------------------------------------------------------------------
 *  Permissões
 * ------------------------------------------------------------------ */

/**
 * Verifica que o usuário corrente é vendor aprovado.
 */
function papelito_vendor_stock_require_seller() {
	$user = wp_get_current_user();

	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'papelito_not_authenticated', 'Não autenticado.', array( 'status' => 401 ) );
	}

	if ( ! papelito_vendor_stock_is_operational_vendor( $user ) ) {
		return new WP_Error( 'papelito_forbidden', 'Acesso restrito a vendors.', array( 'status' => 403 ) );
	}

	return $user;
}

/**
 * Sanitiza o motivo enviado pelo vendor (opcional). Sempre prefixado.
 */
function papelito_vendor_stock_sanitize_reason_vendor( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return 'vendor_update';
	}

	$clean = sanitize_text_field( $raw );
	return substr( 'vendor_update:' . $clean, 0, 120 );
}

/**
 * Sanitiza o motivo enviado pelo admin. Obrigatório (10-100 chars úteis).
 *
 * @return string|WP_Error
 */
function papelito_vendor_stock_sanitize_reason_admin( $raw ) {
	$clean = sanitize_text_field( (string) $raw );
	$len   = strlen( $clean );

	if ( $len < 10 ) {
		return new WP_Error( 'papelito_reason_required', 'Motivo obrigatório (mínimo 10 caracteres).', array( 'status' => 400 ) );
	}

	return substr( 'admin_adjustment:' . $clean, 0, 120 );
}

/* ------------------------------------------------------------------
 *  Endpoints REST
 * ------------------------------------------------------------------ */

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/vendor/me/stock',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					$check = papelito_vendor_stock_require_seller();
					return is_wp_error( $check ) ? $check : true;
				},
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 20 ),
					'search'   => array( 'type' => 'string', 'default' => '' ),
					'filter'   => array( 'type' => 'string', 'default' => 'all' ),
					'paginate' => array( 'type' => 'boolean', 'default' => true ),
					'category' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'tags'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							$ids = array_filter( array_map( 'intval', explode( ',', (string) $value ) ) );
							return implode( ',', array_unique( $ids ) );
						},
					),
					'sort'     => array(
						'type'    => 'string',
						'default' => 'name_asc',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$user = wp_get_current_user();

					$result = papelito_vendor_stock_query(
						(int) $user->ID,
						array(
							'page'     => (int) $request->get_param( 'page' ),
							'per_page' => (int) $request->get_param( 'per_page' ),
							'search'   => (string) $request->get_param( 'search' ),
							'filter'   => (string) $request->get_param( 'filter' ),
							'paginate' => rest_sanitize_boolean( $request->get_param( 'paginate' ) ),
							'category' => (int) $request->get_param( 'category' ),
							'tags'     => (string) $request->get_param( 'tags' ),
							'sort'     => (string) $request->get_param( 'sort' ),
						)
					);

					return new WP_REST_Response( $result, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/stock/taxonomies',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					$check = papelito_vendor_stock_require_seller();
					return is_wp_error( $check ) ? $check : true;
				},
				'callback'            => static function () {
					return new WP_REST_Response( papelito_vendor_stock_taxonomies(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/stock',
			array(
				'methods'             => 'PUT',
				'permission_callback' => static function () {
					$check = papelito_vendor_stock_require_seller();
					return is_wp_error( $check ) ? $check : true;
				},
				'args'                => array(
					'product_id' => array( 'type' => 'integer', 'required' => true ),
					'qty'        => array( 'type' => 'integer', 'required' => true ),
					'reason'     => array( 'type' => 'string', 'required' => false ),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$user   = wp_get_current_user();
					$reason = papelito_vendor_stock_sanitize_reason_vendor( $request->get_param( 'reason' ) );

					$result = papelito_set_vendor_stock(
						(int) $user->ID,
						(int) $request->get_param( 'product_id' ),
						(int) $request->get_param( 'qty' ),
						$reason
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( $result, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/vendors/(?P<id>\d+)/stock',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 50 ),
					'search'   => array( 'type' => 'string', 'default' => '' ),
					'filter'   => array( 'type' => 'string', 'default' => 'all' ),
					'paginate' => array( 'type' => 'boolean', 'default' => true ),
					'category' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'tags'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							$ids = array_filter( array_map( 'intval', explode( ',', (string) $value ) ) );
							return implode( ',', array_unique( $ids ) );
						},
					),
					'sort'     => array(
						'type'    => 'string',
						'default' => 'name_asc',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$vendor_id = (int) $request->get_param( 'id' );
					$user      = get_user_by( 'id', $vendor_id );

					if ( ! papelito_vendor_stock_is_operational_vendor( $user ) ) {
						return new WP_Error( 'papelito_vendor_not_found', 'Vendor não encontrado.', array( 'status' => 404 ) );
					}

					$result = papelito_vendor_stock_query(
						$vendor_id,
						array(
							'page'     => (int) $request->get_param( 'page' ),
							'per_page' => (int) $request->get_param( 'per_page' ),
							'search'   => (string) $request->get_param( 'search' ),
							'filter'   => (string) $request->get_param( 'filter' ),
							'paginate' => rest_sanitize_boolean( $request->get_param( 'paginate' ) ),
							'category' => (int) $request->get_param( 'category' ),
							'tags'     => (string) $request->get_param( 'tags' ),
							'sort'     => (string) $request->get_param( 'sort' ),
							'include_history' => true,
						)
					);

					return new WP_REST_Response( $result, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/vendors/(?P<id>\d+)/stock',
			array(
				'methods'             => 'PUT',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'product_id' => array( 'type' => 'integer', 'required' => true ),
					'qty'        => array( 'type' => 'integer', 'required' => true ),
					'reason'     => array( 'type' => 'string', 'required' => true ),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$vendor_id = (int) $request->get_param( 'id' );
					$user      = get_user_by( 'id', $vendor_id );

					if ( ! papelito_vendor_stock_is_operational_vendor( $user ) ) {
						return new WP_Error( 'papelito_vendor_not_found', 'Vendor não encontrado.', array( 'status' => 404 ) );
					}

					$reason = papelito_vendor_stock_sanitize_reason_admin( $request->get_param( 'reason' ) );
					if ( is_wp_error( $reason ) ) {
						return $reason;
					}

					$result = papelito_set_vendor_stock(
						$vendor_id,
						(int) $request->get_param( 'product_id' ),
						(int) $request->get_param( 'qty' ),
						$reason
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( $result, 200 );
				},
			)
		);
	}
);
