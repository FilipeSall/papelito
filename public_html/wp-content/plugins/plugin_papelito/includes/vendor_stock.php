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

/*
------------------------------------------------------------------
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

/*
------------------------------------------------------------------
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

	$prev_qty              = $row ? (int) $row['qty'] : 0;
	$qty                   = $prev_qty + $delta;
	$raw_notified          = $row['notified_zero_at'] ?? null;
	$is_zero_date          = ( '0000-00-00 00:00:00' === $raw_notified );
	$notified_zero_at      = ( null === $raw_notified || $is_zero_date ) ? null : $raw_notified;
	$zeroed_event_fired    = false;
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

	$prev_qty              = $row ? (int) $row['qty'] : 0;
	$raw_notified          = $row['notified_zero_at'] ?? null;
	$is_zero_date          = ( '0000-00-00 00:00:00' === $raw_notified );
	$notified_zero_at      = ( null === $raw_notified || $is_zero_date ) ? null : $raw_notified;
	$zeroed_event_fired    = false;
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

/*
------------------------------------------------------------------
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
	$images = papelito_vendor_stock_product_image_urls( array( $product_id ) );

	return $images[ $product_id ] ?? '';
}

/**
 * Retorna thumbnails de produtos em lote para evitar uma consulta de metadados
 * por item nas listagens de estoque.
 *
 * @param int[] $product_ids Produtos cujas imagens devem ser resolvidas.
 * @return array<int,string> Indexado pelo ID do produto.
 */
function papelito_vendor_stock_product_image_urls( array $product_ids ): array {
	global $wpdb;

	$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	if ( empty( $product_ids ) ) {
		return array();
	}

	$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND post_id IN ({$placeholders})",
			$product_ids
		),
		ARRAY_A
	);

	$thumbnail_by_product = array();
	$thumbnail_ids        = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$product_id   = absint( $row['post_id'] ?? 0 );
		$thumbnail_id = absint( $row['meta_value'] ?? 0 );

		if ( $product_id <= 0 || $thumbnail_id <= 0 ) {
			continue;
		}

		$thumbnail_by_product[ $product_id ] = $thumbnail_id;
		$thumbnail_ids[]                     = $thumbnail_id;
	}

	if ( empty( $thumbnail_by_product ) ) {
		return array();
	}

	if ( function_exists( 'update_meta_cache' ) ) {
		update_meta_cache( 'post', array_values( array_unique( $thumbnail_ids ) ) );
	}

	$images = array();
	foreach ( $thumbnail_by_product as $product_id => $thumbnail_id ) {
		$url = wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' );
		if ( is_string( $url ) ) {
			$images[ $product_id ] = $url;
		}
	}

	return $images;
}

/**
 * Limite de estoque baixo, em unidades.
 *
 * Constante e nao configuracao: antes desta decisao o sistema nao tinha nenhuma regra de estoque
 * baixo, e um limite por vendor exigiria campo, endpoint e migracao para um numero que o painel
 * usa apenas para ordenar atencao. O valor viaja na resposta REST para o front nunca guardar uma
 * segunda copia.
 */
if ( ! defined( 'PAPELITO_VENDOR_STOCK_LOW_THRESHOLD' ) ) {
	define( 'PAPELITO_VENDOR_STOCK_LOW_THRESHOLD', 5 );
}

/** @return int Limite abaixo do qual o saldo positivo conta como estoque baixo. */
function papelito_vendor_stock_low_threshold(): int {
	return max( 1, (int) PAPELITO_VENDOR_STOCK_LOW_THRESHOLD );
}

/**
 * Recortes de estoque aceitos pela listagem.
 *
 * `zeroed_only` e `unconfigured` sao disjuntos de proposito: quem nunca lancou saldo nao "ficou
 * sem estoque", e juntar os dois num filtro so esconde o produto que o vendor ainda nao trabalhou.
 *
 * @return string[]
 */
function papelito_vendor_stock_filters(): array {
	return array( 'all', 'with_stock', 'low_stock', 'zeroed_only', 'unconfigured', 'incomplete' );
}

/**
 * Situacao do catalogo inteiro para um vendor, em uma consulta.
 *
 * Nao aplica busca nem taxonomia: o resumo e a situacao fixa do catalogo, e cada numero dele e um
 * atalho para o filtro correspondente. Respeita apenas o segmento (`products` ou `kits`), senao a
 * contagem contradiria a lista que o vendor esta vendo.
 *
 * `coverage_percent` e presenca no catalogo — quantos SKUs elegiveis o vendor tem disponiveis —,
 * nunca participacao no volume fisico de estoque.
 *
 * @param int    $vendor_id Vendor alvo.
 * @param string $type      Segmento: `products` ou `kits`.
 * @return array<string,mixed>
 */
function papelito_vendor_stock_summary( int $vendor_id, string $type = 'products' ): array {
	global $wpdb;

	$vendor_id = (int) $vendor_id;
	$type      = in_array( $type, array( 'products', 'kits' ), true ) ? $type : 'products';
	$threshold = papelito_vendor_stock_low_threshold();
	$empty     = array(
		'eligible'            => 0,
		'available'           => 0,
		'low_stock'           => 0,
		'out_of_stock'        => 0,
		'unconfigured'        => 0,
		'incomplete'          => 0,
		'coverage_percent'    => 0.0,
		'low_stock_threshold' => $threshold,
	);

	if ( $vendor_id <= 0 ) {
		return $empty;
	}

	$tables       = papelito_vendor_stock_table_names();
	$posts        = $wpdb->posts;
	$effective_id = 'COALESCE(NULLIF(p.post_parent, 0), p.ID)';

	$availability_sql    = 'COALESCE(vs.qty, 0)';
	$availability_params = array();
	$where               = array( 'p.post_type IN (%s, %s)', 'p.post_status = %s' );
	$where_params        = array( 'product', 'product_variation', 'publish' );

	if ( function_exists( 'papelito_kits_table_names' ) ) {
		$kits_tables = papelito_kits_table_names();
		$kit_exists  = "EXISTS ( SELECT 1 FROM {$kits_tables['kits']} papelito_summary_kit WHERE papelito_summary_kit.product_id = p.ID )";

		// Kit disponivel e o menor `saldo / quantidade` entre os componentes, a mesma regra da
		// listagem: a linha de estoque do produto comercial do kit nao decide disponibilidade.
		$availability_sql    = "CASE WHEN {$kit_exists} THEN COALESCE((SELECT MIN(FLOOR(COALESCE(papelito_summary_component.qty, 0) / papelito_summary_item.quantity)) FROM {$kits_tables['items']} papelito_summary_item LEFT JOIN {$tables['stock']} papelito_summary_component ON papelito_summary_component.product_id = papelito_summary_item.product_id AND papelito_summary_component.vendor_id = %d WHERE papelito_summary_item.kit_id = (SELECT id FROM {$kits_tables['kits']} papelito_summary_kit_id WHERE papelito_summary_kit_id.product_id = p.ID LIMIT 1) AND papelito_summary_item.quantity > 0), 0) ELSE COALESCE(vs.qty, 0) END";
		$availability_params = array( $vendor_id );
		$where[]             = 'kits' === $type ? $kit_exists : "NOT {$kit_exists}";
	}

	// Cadastro de kit vive na entidade `papelito_kits` e e checado pelo editor de kits; julgar o
	// produto comercial pelo postmeta acusaria falta onde o dado existe noutro lugar.
	$incomplete_sql = 'kits' === $type
		? '0'
		: papelito_vendor_stock_incomplete_clause( $effective_id )['sql'];
	$configured_sql = 'kits' === $type
		? '1'
		: 'CASE WHEN vs.qty IS NULL THEN 0 ELSE 1 END';

	$sql = "SELECT COUNT(*) AS eligible,
			SUM( situacao.avail > 0 ) AS available,
			SUM( situacao.avail > 0 AND situacao.avail <= %d ) AS low_stock,
			SUM( 1 = situacao.configured AND 0 = situacao.avail ) AS out_of_stock,
			SUM( 0 = situacao.configured ) AS unconfigured,
			SUM( 1 = situacao.incomplete ) AS incomplete
		FROM ( SELECT {$availability_sql} AS avail,
				{$configured_sql} AS configured,
				CASE WHEN {$incomplete_sql} THEN 1 ELSE 0 END AS incomplete
			FROM {$posts} p
			LEFT JOIN {$tables['stock']} vs ON vs.product_id = p.ID AND vs.vendor_id = %d
			WHERE " . implode( ' AND ', $where ) . ' ) situacao';

	$params = array_merge( array( $threshold ), $availability_params, array( $vendor_id ), $where_params );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

	if ( ! is_array( $row ) ) {
		return $empty;
	}

	$eligible  = (int) ( $row['eligible'] ?? 0 );
	$available = (int) ( $row['available'] ?? 0 );

	return array(
		'eligible'            => $eligible,
		'available'           => $available,
		'low_stock'           => (int) ( $row['low_stock'] ?? 0 ),
		'out_of_stock'        => (int) ( $row['out_of_stock'] ?? 0 ),
		'unconfigured'        => (int) ( $row['unconfigured'] ?? 0 ),
		'incomplete'          => (int) ( $row['incomplete'] ?? 0 ),
		'coverage_percent'    => $eligible > 0 ? round( $available * 100 / $eligible, 1 ) : 0.0,
		'low_stock_threshold' => $threshold,
	);
}

/**
 * Aplica a mesma quantidade a varios produtos do vendor em uma chamada.
 *
 * Cada produto continua passando por `papelito_set_vendor_stock()`: a transacao por linha, o log
 * de ajuste e o evento de zerado sao os mesmos do lancamento individual. O ganho e de rede — uma
 * requisicao em vez de uma por produto —, nao um caminho de escrita paralelo.
 *
 * @param int   $vendor_id   Vendor alvo.
 * @param int[] $product_ids Produtos selecionados.
 * @param int   $qty         Quantidade aplicada a todos.
 * @return array{updated:int,failed:array<int,array{product_id:int,message:string}>}
 */
function papelito_vendor_stock_set_many( int $vendor_id, array $product_ids, int $qty ): array {
	$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	$updated     = 0;
	$failed      = array();

	foreach ( $product_ids as $product_id ) {
		$result = papelito_set_vendor_stock( $vendor_id, $product_id, $qty, 'vendor_bulk_update' );

		if ( is_wp_error( $result ) ) {
			$failed[] = array(
				'product_id' => $product_id,
				'message'    => $result->get_error_message(),
			);
			continue;
		}

		++$updated;
	}

	return array(
		'updated' => $updated,
		'failed'  => $failed,
	);
}

/**
 * Registra para a Papelito o pedido de completar o cadastro de um produto.
 *
 * Os campos que faltam sao recalculados no servidor, nunca lidos do corpo da requisicao: o painel
 * mostra o que falta, mas quem afirma o que falta e a mesma auditoria que alimenta a listagem.
 *
 * Duplicidade e resolvida pelo indice `uq_user_type_dedupe`: uma solicitacao por vendor, produto e
 * administrador. Se ja existe, a resposta diz isso em vez de criar uma segunda.
 *
 * @param int    $vendor_id  Vendor solicitante.
 * @param int    $product_id Produto com cadastro incompleto.
 * @param string $message    Recado opcional do vendor.
 * @return array{ok:bool,created:int,already_pending:bool,missing_fields:string[]}|WP_Error
 */
function papelito_vendor_stock_request_product_data( int $vendor_id, int $product_id, string $message = '' ) {
	$vendor_id  = (int) $vendor_id;
	$product_id = (int) $product_id;

	if ( $vendor_id <= 0 || $product_id <= 0 ) {
		return new WP_Error( 'papelito_invalid_request', 'Produto invalido.', array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product_id ) ) {
		return new WP_Error( 'papelito_product_not_found', 'Produto nao encontrado.', array( 'status' => 404 ) );
	}

	$product   = wc_get_product( $product_id );
	$parent_id = (int) $product->get_parent_id();
	$effective = $parent_id > 0 ? $parent_id : $product_id;
	$audit     = papelito_vendor_stock_product_audit(
		$effective,
		'' !== papelito_vendor_stock_product_image_url( $effective )
	);

	if ( empty( $audit['missing'] ) ) {
		return new WP_Error(
			'papelito_product_data_complete',
			'O cadastro desse produto esta completo. Recarregue a pagina para ver os dados atuais.',
			array( 'status' => 409 )
		);
	}

	$vendor      = get_user_by( 'id', $vendor_id );
	$store_name  = sanitize_text_field( (string) get_user_meta( $vendor_id, 'store_name', true ) );
	$dedupe_key  = 'vendor-data-request:' . $vendor_id . ':' . $effective;
	$admins      = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
		)
	);
	$payload = array(
		'product_id'     => $effective,
		'product_name'   => (string) $product->get_name(),
		'product_sku'    => (string) $product->get_sku(),
		'missing_fields' => $audit['missing'],
		'message'        => $message,
		'vendor_id'      => $vendor_id,
		'vendor_name'    => $vendor instanceof WP_User ? (string) $vendor->display_name : '',
		'vendor_store'   => $store_name,
		'admin_url'      => admin_url( 'post.php?post=' . $effective . '&action=edit' ),
	);

	$created         = 0;
	$already_pending = false;

	foreach ( is_array( $admins ) ? $admins : array() as $admin_id ) {
		$admin_id = absint( $admin_id );

		if ( $admin_id <= 0 ) {
			continue;
		}

		$dispatched = papelito_dispatch_notification(
			$admin_id,
			PAPELITO_NOTIF_VENDOR_PRODUCT_DATA_REQUEST,
			$payload,
			$dedupe_key
		);

		if ( false === $dispatched ) {
			$already_pending = true;
			continue;
		}

		++$created;
	}

	return array(
		'ok'              => true,
		'created'         => $created,
		'already_pending' => 0 === $created && $already_pending,
		'missing_fields'  => $audit['missing'],
	);
}

/**
 * Campos que o cadastro de um produto precisa ter para ser vendido, na ordem em que o painel os
 * mostra. Sao as regras que o proprio sistema ja aplica em outro lugar, nao uma lista nova:
 * preco e peso vem de `papelito_product_has_valid_price()` / `papelito_product_has_valid_weight()`,
 * dimensao e a mesma exigencia do frete em `papelito_catalog_search_product_rows()`, e categoria e
 * a condicao de entrar na vitrine.
 */
function papelito_vendor_stock_required_fields(): array {
	return array( 'image', 'price', 'weight', 'dimensions', 'category' );
}

/**
 * Audita o cadastro de um produto uma unica vez, devolvendo o que falta e se a pagina publica
 * consegue renderiza-lo.
 *
 * Uma funcao so porque as duas respostas saem do mesmo `wc_get_product()`: separadas, a listagem
 * instanciaria o produto duas vezes por linha.
 *
 * @param int  $effective_id  Produto simples, ou o pai de uma variacao.
 * @param bool $has_thumbnail Se a listagem encontrou thumbnail para o produto.
 * @return array{publicly_viewable:bool,missing:string[]}
 */
function papelito_vendor_stock_product_audit( int $effective_id, bool $has_thumbnail = true ): array {
	$missing = array();

	if ( $effective_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
		return array(
			'publicly_viewable' => false,
			'missing'           => array(),
		);
	}

	$product = wc_get_product( $effective_id );

	if ( ! $product ) {
		return array(
			'publicly_viewable' => false,
			'missing'           => array(),
		);
	}

	$is_kit = function_exists( 'papelito_kit_get_by_product' )
		&& is_array( papelito_kit_get_by_product( $effective_id ) );

	if ( ! $has_thumbnail ) {
		$missing[] = 'image';
	}

	if ( function_exists( 'papelito_product_has_valid_price' ) && ! papelito_product_has_valid_price( $product ) ) {
		$missing[] = 'price';
	}

	$has_weight = function_exists( 'papelito_product_has_valid_weight' )
		? papelito_product_has_valid_weight( $product )
		: papelito_vendor_stock_has_positive_weight( $product->get_weight() );

	if ( ! $has_weight ) {
		$missing[] = 'weight';
	}

	// Kit tem dimensao propria na entidade, checada pelo editor de kits. Julgar o produto
	// comercial pelo postmeta acusaria falta onde o dado existe noutro lugar.
	if ( ! $is_kit && ! papelito_vendor_stock_product_has_dimensions( $product ) ) {
		$missing[] = 'dimensions';
	}

	if ( ! $is_kit && ( ! function_exists( 'papelito_product_get_category' ) || null === papelito_product_get_category( $effective_id ) ) ) {
		$missing[] = 'category';
	}

	return array(
		'publicly_viewable' => $has_weight,
		'missing'           => $missing,
	);
}

/**
 * Dimensoes completas no proprio produto ou em qualquer variacao publicada, a mesma leitura que o
 * frete faz. Sem as tres o calculo de frete falha, entao faltar uma equivale a faltar todas.
 *
 * @param WC_Product $product Produto avaliado.
 */
function papelito_vendor_stock_product_has_dimensions( $product ): bool {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_length' ) ) {
		return false;
	}

	$complete = static function ( $candidate ): bool {
		foreach ( array( $candidate->get_length(), $candidate->get_width(), $candidate->get_height() ) as $side ) {
			if ( ! papelito_vendor_stock_has_positive_weight( $side ) ) {
				return false;
			}
		}

		return true;
	};

	if ( $complete( $product ) ) {
		return true;
	}

	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );

			if ( $variation && $complete( $variation ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Clausula SQL verdadeira quando falta algum dado obrigatorio no cadastro.
 *
 * Existe em SQL, e nao so no audite por linha, porque filtrar e contar "dados incompletos" olha o
 * catalogo inteiro, nao a pagina. Espelha `papelito_vendor_stock_product_audit()`; divergir entre
 * as duas faria a contagem do resumo discordar do selo da linha.
 *
 * ATENCAO: a clausula NAO conhece kit, e a fachada comercial de um kit cai nela como incompleta —
 * peso, dimensao e categoria de kit vivem na entidade `papelito_kits`, nao no postmeta do produto.
 * Quem chama precisa ter excluido kit do recorte antes (a listagem exclui pelo `type`, o resumo
 * pelo `NOT EXISTS`). Aplicar isto ao catalogo inteiro sem esse recorte acusa falta onde nao ha.
 *
 * @param string $product_expr Expressao SQL do produto efetivo.
 * @return array{sql:string,params:array<int,mixed>}
 */
function papelito_vendor_stock_incomplete_clause( string $product_expr ): array {
	global $wpdb;

	$postmeta = $wpdb->postmeta;
	$posts    = $wpdb->posts;
	$decimal  = static function ( string $alias ): string {
		return "CAST( REPLACE( {$alias}.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0";
	};

	$has_thumbnail = "EXISTS ( SELECT 1 FROM {$postmeta} thumb_meta WHERE thumb_meta.post_id = {$product_expr} AND thumb_meta.meta_key = '_thumbnail_id' AND thumb_meta.meta_value > 0 )";
	$has_price     = "EXISTS ( SELECT 1 FROM {$postmeta} price_meta WHERE price_meta.post_id = {$product_expr} AND price_meta.meta_key = '_price' AND {$decimal( 'price_meta' )} )";

	$own_shipping = "EXISTS ( SELECT 1 FROM {$postmeta} weight_meta
		INNER JOIN {$postmeta} length_meta ON length_meta.post_id = weight_meta.post_id AND length_meta.meta_key = '_length'
		INNER JOIN {$postmeta} width_meta ON width_meta.post_id = weight_meta.post_id AND width_meta.meta_key = '_width'
		INNER JOIN {$postmeta} height_meta ON height_meta.post_id = weight_meta.post_id AND height_meta.meta_key = '_height'
		WHERE weight_meta.post_id = {$product_expr} AND weight_meta.meta_key = '_weight'
		AND {$decimal( 'weight_meta' )} AND {$decimal( 'length_meta' )} AND {$decimal( 'width_meta' )} AND {$decimal( 'height_meta' )} )";

	$variation_shipping = "EXISTS ( SELECT 1 FROM {$posts} shipping_variation
		INNER JOIN {$postmeta} variation_weight ON variation_weight.post_id = shipping_variation.ID AND variation_weight.meta_key = '_weight'
		INNER JOIN {$postmeta} variation_length ON variation_length.post_id = shipping_variation.ID AND variation_length.meta_key = '_length'
		INNER JOIN {$postmeta} variation_width ON variation_width.post_id = shipping_variation.ID AND variation_width.meta_key = '_width'
		INNER JOIN {$postmeta} variation_height ON variation_height.post_id = shipping_variation.ID AND variation_height.meta_key = '_height'
		WHERE shipping_variation.post_parent = {$product_expr} AND shipping_variation.post_type = 'product_variation' AND shipping_variation.post_status = 'publish'
		AND {$decimal( 'variation_weight' )} AND {$decimal( 'variation_length' )} AND {$decimal( 'variation_width' )} AND {$decimal( 'variation_height' )} )";

	$taxonomy_tables = papelito_product_taxonomy_table_names();
	$has_category    = "EXISTS ( SELECT 1 FROM {$taxonomy_tables['product_category']} complete_pc WHERE complete_pc.product_id = {$product_expr} )";

	$complete = "( {$has_thumbnail} AND {$has_price} AND ( {$own_shipping} OR {$variation_shipping} ) AND {$has_category} )";

	return array(
		'sql'    => "NOT {$complete}",
		'params' => array(),
	);
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
	return papelito_vendor_stock_product_audit( $effective_id )['publicly_viewable'];
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
 * Coleções curadas normalizadas, no formato aceito pelo filtro do estoque.
 *
 * @return string[]
 */
function papelito_vendor_stock_curated_collections(): array {
	if ( ! function_exists( 'papelito_curated_collections' ) ) {
		return array();
	}

	return array_values(
		array_unique(
			array_filter( array_map( 'sanitize_title', papelito_curated_collections() ) )
		)
	);
}

/**
 * Coleções curadas com o total de produtos publicados em cada uma.
 *
 * @return array<int, array{slug:string,name:string,count:int}>
 */
function papelito_vendor_stock_collections(): array {
	global $wpdb;

	$slugs = papelito_vendor_stock_curated_collections();

	if ( empty( $slugs ) || ! function_exists( 'papelito_product_taxonomy_table_names' ) ) {
		return array();
	}

	$tables       = papelito_product_taxonomy_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pc.collection_slug, COUNT(DISTINCT pc.product_id) AS total
			FROM {$tables['product_collection']} pc
			INNER JOIN {$wpdb->posts} p ON p.ID = pc.product_id AND p.post_type = 'product' AND p.post_status = 'publish'
			WHERE pc.collection_slug IN ({$placeholders})
			GROUP BY pc.collection_slug",
			$slugs
		),
		ARRAY_A
	);

	$counts = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$counts[ (string) ( $row['collection_slug'] ?? '' ) ] = (int) ( $row['total'] ?? 0 );
	}

	$collections = array();

	foreach ( $slugs as $slug ) {
		$collections[] = array(
			'slug'  => $slug,
			'name'  => ucwords( str_replace( '-', ' ', $slug ) ),
			'count' => (int) ( $counts[ $slug ] ?? 0 ),
		);
	}

	return $collections;
}

/**
 * Composição dos kits presentes na página, em consultas de lote.
 *
 * O kit entra na listagem como produto comercial qualquer, mas a
 * disponibilidade dele não sai da própria linha de estoque: vem dos itens que o
 * compõem. Carregar os itens kit a kit criaria N+1 na listagem, então tudo aqui
 * é resolvido em consultas fixas para a página inteira.
 *
	 * A quantidade montável aplica a mesma fórmula da cobertura: o menor quociente
	 * inteiro entre estoque do componente e quantidade exigida pelo kit.
 *
 * @param int   $vendor_id   Vendor dono do estoque.
 * @param int[] $product_ids Produtos da página.
 * @return array<int, array<string, mixed>> Indexado pelo product_id do kit.
 */
function papelito_vendor_stock_kit_compositions( int $vendor_id, array $product_ids ): array {
	global $wpdb;

	$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );

	if ( $vendor_id <= 0 || empty( $product_ids ) || ! function_exists( 'papelito_kits_table_names' ) ) {
		return array();
	}

	$tables       = papelito_kits_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$kits = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT k.id, k.product_id, p.post_name AS slug
			FROM {$tables['kits']} k
			INNER JOIN {$wpdb->posts} p ON p.ID = k.product_id
			WHERE k.product_id IN ({$placeholders})",
			$product_ids
		),
		ARRAY_A
	);

	if ( ! is_array( $kits ) || empty( $kits ) ) {
		return array();
	}

	$kit_ids          = array_map( static fn( array $kit ): int => (int) $kit['id'], $kits );
	$kit_placeholders = implode( ',', array_fill( 0, count( $kit_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT kit_id, product_id, quantity FROM {$tables['items']} WHERE kit_id IN ({$kit_placeholders}) ORDER BY kit_id ASC, product_id ASC",
			$kit_ids
		),
		ARRAY_A
	);

	$rows = is_array( $rows ) ? $rows : array();

	$component_ids = array_values(
		array_unique(
			array_filter( array_map( static fn( array $row ): int => (int) ( $row['product_id'] ?? 0 ), $rows ) )
		)
	);

	$components      = array();
	$component_stock = array();

	if ( ! empty( $component_ids ) ) {
		$component_placeholders = implode( ',', array_fill( 0, count( $component_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$component_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, sku.meta_value AS sku
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
				WHERE p.ID IN ({$component_placeholders})",
				$component_ids
			),
			ARRAY_A
		);

		foreach ( is_array( $component_rows ) ? $component_rows : array() as $component_row ) {
			$components[ (int) ( $component_row['ID'] ?? 0 ) ] = array(
				'name' => (string) ( $component_row['post_title'] ?? '' ),
				'sku'  => (string) ( $component_row['sku'] ?? '' ),
			);
		}

		$stock_tables = papelito_vendor_stock_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stock_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, qty FROM {$stock_tables['stock']} WHERE vendor_id = %d AND product_id IN ({$component_placeholders})",
				array_merge( array( $vendor_id ), $component_ids )
			),
			ARRAY_A
		);

		foreach ( is_array( $stock_rows ) ? $stock_rows : array() as $stock_row ) {
			$component_stock[ (int) ( $stock_row['product_id'] ?? 0 ) ] = (int) ( $stock_row['qty'] ?? 0 );
		}
	}

	$items_by_kit = array();
	$assemblable  = array();
	$image_urls   = papelito_vendor_stock_product_image_urls( $component_ids );

	foreach ( $rows as $row ) {
		$component_id = (int) ( $row['product_id'] ?? 0 );
		$stock        = (int) ( $component_stock[ $component_id ] ?? 0 );
		$kit_id       = (int) ( $row['kit_id'] ?? 0 );
		$quantity     = (int) ( $row['quantity'] ?? 0 );
		$possible      = $quantity > 0 ? (int) floor( $stock / $quantity ) : 0;

		if ( ! isset( $assemblable[ $kit_id ] ) ) {
			$assemblable[ $kit_id ] = $possible;
		} else {
			$assemblable[ $kit_id ] = min( $assemblable[ $kit_id ], $possible );
		}

		$items_by_kit[ $kit_id ][] = array(
			'product_id'   => $component_id,
			'product_name' => (string) ( $components[ $component_id ]['name'] ?? '' ),
			'sku'          => (string) ( $components[ $component_id ]['sku'] ?? '' ),
			'image_url'    => $image_urls[ $component_id ] ?? '',
			'quantity'     => $quantity,
			'qty'          => $stock,
			'is_zeroed'    => $stock <= 0,
		);
	}

	$compositions = array();

	foreach ( $kits as $kit ) {
		$kit_id         = (int) $kit['id'];
		$kit_product_id = (int) $kit['product_id'];

		$compositions[ $kit_product_id ] = array(
			'kit_id'          => $kit_id,
			'slug'            => (string) ( $kit['slug'] ?? '' ),
			'assemblable_qty' => (int) ( $assemblable[ $kit_id ] ?? 0 ),
			'items'           => $items_by_kit[ $kit_id ] ?? array(),
		);
	}

	return $compositions;
}

/**
 * Lista paginada de estoque de um vendor com busca opcional por nome/SKU.
 *
 * @param int   $vendor_id Vendor alvo.
 * @param array $args      Argumentos: page (>=1), per_page (1-100),
 *                         search (string), filter (all|with_stock|zeroed_only),
 *                         sort (name_asc|name_desc|qty_desc|qty_asc|updated_desc),
 *                         category (int: term_id com a flag off, id da categoria
 *                         Papelito com ela ligada), subcategories (csv|array de
 *                         id de subcategoria Papelito), tags (csv|array de term_id),
 *                         collection (slug de coleção curada), type (products|kits),
 *                         paginate (bool), include_history (bool).
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
		'qty_desc'      => '',
		'qty_asc'       => '',
		'updated_desc' => 'vs.updated_at IS NULL, vs.updated_at DESC, p.ID ASC',
	);
	if ( ! isset( $sort_map[ $sort ] ) ) {
		$sort = 'name_asc';
	}

	$category = isset( $args['category'] ) ? (int) $args['category'] : 0;

	$subcategories = array();
	if ( ! empty( $args['subcategories'] ) ) {
		$raw_subcategories = is_array( $args['subcategories'] )
			? $args['subcategories']
			: explode( ',', (string) $args['subcategories'] );

		foreach ( $raw_subcategories as $raw_subcategory ) {
			$subcategory_id = (int) $raw_subcategory;
			if ( $subcategory_id > 0 ) {
				$subcategories[] = $subcategory_id;
			}
		}

		$subcategories = array_values( array_unique( $subcategories ) );
	}

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

	if ( ! in_array( $filter, papelito_vendor_stock_filters(), true ) ) {
		$filter = 'all';
	}

	$type = (string) ( $args['type'] ?? 'products' );
	if ( ! in_array( $type, array( 'products', 'kits' ), true ) ) {
		$type = 'products';
	}

	$tables                 = papelito_vendor_stock_table_names();
	$posts                  = $wpdb->posts;
	$postmeta               = $wpdb->postmeta;
	$kit_availability_sql    = 'COALESCE(vs.qty, 0)';
	$kit_availability_params = array();

	if ( function_exists( 'papelito_kits_table_names' ) ) {
		$kits_tables          = papelito_kits_table_names();
		$kit_exists           = "EXISTS ( SELECT 1 FROM {$kits_tables['kits']} papelito_stock_kit WHERE papelito_stock_kit.product_id = p.ID )";
		$kit_availability_sql = "CASE WHEN {$kit_exists} THEN COALESCE((SELECT MIN(FLOOR(COALESCE(papelito_stock_component.qty, 0) / papelito_stock_item.quantity)) FROM {$kits_tables['items']} papelito_stock_item LEFT JOIN {$tables['stock']} papelito_stock_component ON papelito_stock_component.product_id = papelito_stock_item.product_id AND papelito_stock_component.vendor_id = %d WHERE papelito_stock_item.kit_id = (SELECT id FROM {$kits_tables['kits']} papelito_stock_kit_id WHERE papelito_stock_kit_id.product_id = p.ID LIMIT 1) AND papelito_stock_item.quantity > 0), 0) ELSE COALESCE(vs.qty, 0) END";
		$kit_availability_params = array( $vendor_id );
	}

	$where  = array( 'p.post_type IN (%s, %s)', 'p.post_status = %s' );
	$params = array( 'product', 'product_variation', 'publish' );

	if ( 'with_stock' === $filter ) {
		$where[] = "{$kit_availability_sql} > 0";
		$params  = array_merge( $params, $kit_availability_params );
	} elseif ( 'zeroed_only' === $filter ) {
		// Zerado e nunca configurado sao buckets disjuntos: quem nunca lancou saldo nao "ficou
		// sem", e somar os dois numa lista so esconde justamente o produto que o vendor ainda
		// nao trabalhou. `unconfigured` cobre esse outro caso.
		$where[] = 'kits' === $type
			? "{$kit_availability_sql} = 0"
			: "vs.qty IS NOT NULL AND {$kit_availability_sql} = 0";
		$params  = array_merge( $params, $kit_availability_params );
	} elseif ( 'low_stock' === $filter ) {
		$where[] = "{$kit_availability_sql} > 0 AND {$kit_availability_sql} <= %d";
		$params  = array_merge( $params, $kit_availability_params, $kit_availability_params, array( papelito_vendor_stock_low_threshold() ) );
	} elseif ( 'unconfigured' === $filter ) {
		$where[] = 'vs.qty IS NULL';
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

	// `$effective_id` e não `p.ID`: variação não tem classificação própria, herda a
	// do pai. Um JOIN ingênuo por `p.ID` derrubaria todas as variações.
	$clause = papelito_taxonomy_exists_clause( $effective_id, $category, $subcategories );

	if ( null !== $clause ) {
		$where[]   = $clause['sql'];
		$params    = array_merge( $params, $clause['params'] );
		$where_sql = implode( ' AND ', $where );
	}

	// Coleção é recorte comercial que atravessa categoria: entra como mais um AND,
	// nunca substituindo os outros filtros. Slug fora da curadoria falha fechado.
	$collection = sanitize_title( (string) ( $args['collection'] ?? '' ) );

	if ( '' !== $collection ) {
		if ( in_array( $collection, papelito_vendor_stock_curated_collections(), true ) ) {
			$taxonomy_tables = papelito_product_taxonomy_table_names();
			$where[]         = "EXISTS ( SELECT 1 FROM {$taxonomy_tables['product_collection']} papelito_collection WHERE papelito_collection.product_id = {$effective_id} AND papelito_collection.collection_slug = %s )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[]        = $collection;
		} else {
			$where[] = '1 = 0';
		}

		$where_sql = implode( ' AND ', $where );
	}

	// Cadastro incompleto e propriedade do produto, nao do estoque: entra como mais um AND sobre
	// o mesmo recorte, para o vendor cruzar "tenho saldo" com "falta dado".
	if ( 'incomplete' === $filter ) {
		$incomplete = papelito_vendor_stock_incomplete_clause( $effective_id );
		$where[]    = $incomplete['sql'];
		$params     = array_merge( $params, $incomplete['params'] );
		$where_sql  = implode( ' AND ', $where );
	}

	if ( function_exists( 'papelito_kits_table_names' ) ) {
		$kits_tables = papelito_kits_table_names();
		$exists      = "EXISTS ( SELECT 1 FROM {$kits_tables['kits']} papelito_kit WHERE papelito_kit.product_id = p.ID )";

		// `p.ID` e não `$effective_id`: variação não é kit, e o produto comercial do
		// kit é sempre simples. Herdar do pai marcaria variações como kit.
		$where[]   = 'kits' === $type ? $exists : "NOT {$exists}";
		$where_sql = implode( ' AND ', $where );
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

	$group_sql   = $need_group ? ' GROUP BY p.ID' : '';
	$sort_sql     = $sort_map[ $sort ];
	$sort_params = array();

	if ( 'qty_desc' === $sort || 'qty_asc' === $sort ) {
		$sort_sql    = sprintf( '%s %s, p.ID ASC', $kit_availability_sql, 'qty_desc' === $sort ? 'DESC' : 'ASC' );
		$sort_params = $kit_availability_params;
	}

	$select_sql = "SELECT p.ID AS product_id, COALESCE(vs.qty, 0) AS qty, vs.updated_at, vs.notified_zero_at,
				p.post_title AS product_name, sku.meta_value AS sku, {$effective_id} AS effective_id
			FROM {$posts} p
			LEFT JOIN {$tables['stock']} vs ON vs.product_id = p.ID AND vs.vendor_id = %d
			{$join_sku}
			{$tax_joins}
			WHERE {$where_sql}
			{$group_sql}
			ORDER BY {$sort_sql}";

	$select_params = array_merge( array( $vendor_id ), $tax_params, $params, $sort_params );

	if ( $paginate ) {
		$offset          = ( $page - 1 ) * $per_page;
		$select_sql     .= ' LIMIT %d OFFSET %d';
		$select_params[] = $per_page;
		$select_params[] = $offset;
	}

	$rows          = $wpdb->get_results( $wpdb->prepare( $select_sql, $select_params ), ARRAY_A );
	$product_ids   = array();
	$effective_ids = array();
	$items         = array();
	$image_rows    = is_array( $rows ) ? $rows : array();
	$image_urls    = papelito_vendor_stock_product_image_urls(
		array_merge(
			array_map( static fn( array $row ): int => (int) ( $row['product_id'] ?? 0 ), $image_rows ),
			// O efetivo tambem entra no lote: variacao raramente tem thumbnail propria, herda a do
			// pai. Sem isso a listagem mostrava fallback e acusava "sem imagem" onde ha imagem.
			array_map( static fn( array $row ): int => (int) ( $row['effective_id'] ?? 0 ), $image_rows )
		)
	);

	foreach ( $image_rows as $row ) {
		$product_id      = (int) ( $row['product_id'] ?? 0 );
		$effective       = (int) ( $row['effective_id'] ?? $product_id );
		$product_ids[]   = $product_id;
		$effective_ids[] = $effective;
		$image_url       = $image_urls[ $product_id ] ?? ( $image_urls[ $effective ] ?? '' );
		$audit           = papelito_vendor_stock_product_audit( $effective, '' !== $image_url );
		$qty             = (int) ( $row['qty'] ?? 0 );
		$items[]         = array(
			'product_id'           => $product_id,
			'public_product_id'    => $effective,
			'is_publicly_viewable' => $audit['publicly_viewable'],
			'missing_fields'       => $audit['missing'],
			'product_name'         => (string) ( $row['product_name'] ?? '' ),
			'sku'                  => (string) ( $row['sku'] ?? '' ),
			'qty'                  => $qty,
			'updated_at'           => (string) ( $row['updated_at'] ?? '' ),
			'is_zeroed'            => 0 === $qty,
			// `null` no join e ausencia de linha: o vendor nunca lancou saldo desse produto.
			'is_unconfigured'      => null === ( $row['qty'] ?? null ),
			'image_url'            => $image_url,
			'history'              => array(),
			'effective_id'         => $effective,
			'categories'           => array(),
			'subcategories'        => array(),
			'tags'                 => array(),
			'kit'                  => null,
		);
	}

	if ( ! empty( $effective_ids ) ) {
		$unique_ids = array_values( array_unique( array_map( 'intval', $effective_ids ) ) );
		$term_map   = array();

		$papelito_categories = papelito_products_category_map( $unique_ids );
		$papelito_subs       = papelito_products_subcategory_map( $unique_ids );

		// Só tag vem do WooCommerce: é palavra-chave de busca, não classificação.
		foreach ( array( 'product_tag' => 'tags' ) as $taxonomy => $key ) {

			$terms = wp_get_object_terms( $unique_ids, $taxonomy, array( 'fields' => 'all_with_object_id' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$object_id                        = (int) $term->object_id;
				$term_map[ $object_id ][ $key ][] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
				);
			}
		}

		foreach ( $items as &$item ) {
			$eff                = (int) $item['effective_id'];
			$item['tags'] = $term_map[ $eff ]['tags'] ?? array();
			$category     = $papelito_categories[ $eff ] ?? null;

			$item['categories']    = null === $category
				? array()
				: array(
					array(
						'id'   => $category['id'],
						'name' => $category['name'],
						'slug' => $category['slug'],
					),
				);
			$item['subcategories'] = array_map(
				static function ( array $subcategory ): array {
					return array(
						'id'    => $subcategory['id'],
						'name'  => $subcategory['name'],
						'slug'  => $subcategory['slug'],
						'facet' => $subcategory['facet'],
					);
				},
				$papelito_subs[ $eff ] ?? array()
			);

			unset( $item['effective_id'] );
		}
		unset( $item );
	}

	if ( ! empty( $product_ids ) ) {
		$kit_compositions = papelito_vendor_stock_kit_compositions( $vendor_id, $product_ids );

		foreach ( $items as &$item ) {
			$item['kit'] = $kit_compositions[ (int) $item['product_id'] ] ?? null;
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
		'items'               => $items,
		'total'               => $total,
		'page'                => $page,
		'per_page'            => $paginate ? $per_page : max( 1, $total ),
		'low_stock_threshold' => papelito_vendor_stock_low_threshold(),
	);
}

/**
 * Lista categorias, subcategorias e coleções curadas da taxonomia Papelito, mais
 * as tags do WooCommerce, para popular o drawer de filtros do estoque.
 * para popular o drawer de filtros do estoque. Cache curto por transient.
 */
function papelito_vendor_stock_taxonomies() {
	// A versão entra na CHAVE: qualquer escrita na taxonomia torna a chave anterior
	// inalcançável. O transient global e não versionado da versão antiga fazia
	// categoria nova sumir do drawer por até 10 minutos.
	$cache_key = 'papelito_vendor_stock_taxonomies_v3_' . papelito_product_taxonomy_version();
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$out = array(
		'categories'    => array(),
		'collections'   => papelito_vendor_stock_collections(),
		'subcategories' => array(),
		'tags'          => array(),
	);

	$counts = papelito_category_product_counts();
	$subs   = papelito_subcategory_product_counts();

	foreach ( papelito_categories_list() as $category ) {
		$out['categories'][] = array(
			'id'    => $category['id'],
			'name'  => $category['name'],
			'slug'  => $category['slug'],
			'count' => (int) ( $counts[ $category['id'] ]['total'] ?? 0 ),
		);

		foreach ( papelito_subcategories_list( $category['id'] ) as $subcategory ) {
			$out['subcategories'][] = array(
				'id'         => $subcategory['id'],
				'categoryId' => $subcategory['categoryId'],
				'name'       => $subcategory['name'],
				'slug'       => $subcategory['slug'],
				'facet'      => $subcategory['facet'],
				'count'      => (int) ( $subs[ $subcategory['id'] ] ?? 0 ),
			);
		}
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'product_tag',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
		$out['tags'][] = array(
			'id'    => (int) $term->term_id,
			'name'  => (string) $term->name,
			'slug'  => (string) $term->slug,
			'count' => (int) $term->count,
		);
	}

	set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );

	return $out;
}

/*
------------------------------------------------------------------
 *  Permissões
 * ------------------------------------------------------------------ */

/**
 * Verifica que o usuário corrente é vendor aprovado.
 */
/**
 * Require an authenticated seller whose account is not suspended.
 *
 * Lancar estoque e preparar venda futura, e conta suspensa nao vende. A leitura do estoque
 * continua liberada para o vendor conseguir despachar o que ja vendeu.
 *
 * @return WP_User|WP_Error
 */
function papelito_vendor_stock_require_seller_commercial() {
	$check = papelito_vendor_stock_require_seller();

	if ( is_wp_error( $check ) ) {
		return $check;
	}

	if ( ! function_exists( 'papelito_account_guard_commercial' ) ) {
		return $check;
	}

	$guard = papelito_account_guard_commercial( (int) $check->ID );

	return is_wp_error( $guard ) ? $guard : $check;
}

/**
 * Require an authenticated seller.
 *
 * @return WP_User|WP_Error
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

/*
------------------------------------------------------------------
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
					'page'          => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page'      => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'search'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'filter'        => array(
						'type'    => 'string',
						'default' => 'all',
					),
					'paginate'      => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'category'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'subcategories' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							return implode(
								',',
								array_values(
									array_filter(
										array_map( 'intval', explode( ',', (string) $value ) )
									)
								)
							);
						},
					),
					'tags'          => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							$ids = array_filter( array_map( 'intval', explode( ',', (string) $value ) ) );
							return implode( ',', array_unique( $ids ) );
						},
					),
					'collection'    => array(
						'type'              => 'string',
						'default'           => '',
						// Closure e não `sanitize_title` direto: o REST chama o callback com
						// ( $value, $request, $param ), e o 2º argumento de `sanitize_title()` é
						// o fallback devolvido quando o valor é vazio — o request viraria o valor
						// do parâmetro em toda requisição sem coleção.
						'sanitize_callback' => static function ( $value ) {
							return sanitize_title( (string) $value );
						},
					),
					'type'          => array(
						'type'              => 'string',
						'default'           => 'products',
						'sanitize_callback' => static function ( $value ) {
							$value = strtolower( trim( (string) $value ) );

							return in_array( $value, array( 'products', 'kits' ), true ) ? $value : 'products';
						},
					),
					'sort'          => array(
						'type'    => 'string',
						'default' => 'name_asc',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$user = wp_get_current_user();

					$result = papelito_vendor_stock_query(
						(int) $user->ID,
						array(
							'page'          => (int) $request->get_param( 'page' ),
							'per_page'      => (int) $request->get_param( 'per_page' ),
							'search'        => (string) $request->get_param( 'search' ),
							'filter'        => (string) $request->get_param( 'filter' ),
							'paginate'      => rest_sanitize_boolean( $request->get_param( 'paginate' ) ),
							'category'      => (int) $request->get_param( 'category' ),
							'subcategories' => (string) $request->get_param( 'subcategories' ),
							'tags'          => (string) $request->get_param( 'tags' ),
							'collection'    => (string) $request->get_param( 'collection' ),
							'type'          => (string) $request->get_param( 'type' ),
							'sort'          => (string) $request->get_param( 'sort' ),
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
					$check = papelito_vendor_stock_require_seller_commercial();
					return is_wp_error( $check ) ? $check : true;
				},
				'args'                => array(
					'product_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'qty'        => array(
						'type'     => 'integer',
						'required' => true,
					),
					'reason'     => array(
						'type'     => 'string',
						'required' => false,
					),
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
			'/vendor/me/stock/summary',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					$check = papelito_vendor_stock_require_seller();
					return is_wp_error( $check ) ? $check : true;
				},
				'args'                => array(
					'type' => array(
						'type'              => 'string',
						'default'           => 'products',
						'sanitize_callback' => static function ( $value ) {
							$value = strtolower( trim( (string) $value ) );

							return in_array( $value, array( 'products', 'kits' ), true ) ? $value : 'products';
						},
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$user = wp_get_current_user();

					return new WP_REST_Response(
						papelito_vendor_stock_summary( (int) $user->ID, (string) $request->get_param( 'type' ) ),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/stock/bulk',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					$check = papelito_vendor_stock_require_seller_commercial();
					return is_wp_error( $check ) ? $check : true;
				},
				'args'                => array(
					'product_ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
					'qty'         => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$user        = wp_get_current_user();
					$qty         = (int) $request->get_param( 'qty' );
					$product_ids = (array) $request->get_param( 'product_ids' );

					if ( $qty < 0 ) {
						return new WP_Error( 'papelito_invalid_qty', 'Quantidade nao pode ser negativa.', array( 'status' => 400 ) );
					}

					if ( empty( $product_ids ) ) {
						return new WP_Error( 'papelito_empty_selection', 'Selecione ao menos um produto.', array( 'status' => 400 ) );
					}

					// Teto de lote: acima disso a requisicao passaria do tempo limite do PHP no meio
					// da escrita, deixando parte da selecao aplicada sem o vendor saber quais.
					if ( count( $product_ids ) > 200 ) {
						return new WP_Error(
							'papelito_selection_too_large',
							'Selecione no maximo 200 produtos por vez.',
							array( 'status' => 422 )
						);
					}

					$result = papelito_vendor_stock_set_many( (int) $user->ID, $product_ids, $qty );

					return new WP_REST_Response(
						array(
							'ok'      => empty( $result['failed'] ),
							'qty'     => $qty,
							'updated' => $result['updated'],
							'failed'  => $result['failed'],
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/me/stock/data-request',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					$check = papelito_vendor_stock_require_seller_commercial();
					return is_wp_error( $check ) ? $check : true;
				},
				'args'                => array(
					'product_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'message'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							return substr( sanitize_textarea_field( (string) $value ), 0, 500 );
						},
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$user   = wp_get_current_user();
					$result = papelito_vendor_stock_request_product_data(
						(int) $user->ID,
						(int) $request->get_param( 'product_id' ),
						(string) $request->get_param( 'message' )
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
					'page'          => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page'      => array(
						'type'    => 'integer',
						'default' => 50,
					),
					'search'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'filter'        => array(
						'type'    => 'string',
						'default' => 'all',
					),
					'paginate'      => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'category'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'subcategories' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							return implode(
								',',
								array_values(
									array_filter(
										array_map( 'intval', explode( ',', (string) $value ) )
									)
								)
							);
						},
					),
					'tags'          => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							$ids = array_filter( array_map( 'intval', explode( ',', (string) $value ) ) );
							return implode( ',', array_unique( $ids ) );
						},
					),
					'collection'    => array(
						'type'              => 'string',
						'default'           => '',
						// Closure e não `sanitize_title` direto: o REST chama o callback com
						// ( $value, $request, $param ), e o 2º argumento de `sanitize_title()` é
						// o fallback devolvido quando o valor é vazio — o request viraria o valor
						// do parâmetro em toda requisição sem coleção.
						'sanitize_callback' => static function ( $value ) {
							return sanitize_title( (string) $value );
						},
					),
					'type'          => array(
						'type'              => 'string',
						'default'           => 'products',
						'sanitize_callback' => static function ( $value ) {
							$value = strtolower( trim( (string) $value ) );

							return in_array( $value, array( 'products', 'kits' ), true ) ? $value : 'products';
						},
					),
					'sort'          => array(
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
							'page'            => (int) $request->get_param( 'page' ),
							'per_page'        => (int) $request->get_param( 'per_page' ),
							'search'          => (string) $request->get_param( 'search' ),
							'filter'          => (string) $request->get_param( 'filter' ),
							'paginate'        => rest_sanitize_boolean( $request->get_param( 'paginate' ) ),
							'category'        => (int) $request->get_param( 'category' ),
							'subcategories'   => (string) $request->get_param( 'subcategories' ),
							'tags'            => (string) $request->get_param( 'tags' ),
							'collection'      => (string) $request->get_param( 'collection' ),
							'type'            => (string) $request->get_param( 'type' ),
							'sort'            => (string) $request->get_param( 'sort' ),
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
					'product_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'qty'        => array(
						'type'     => 'integer',
						'required' => true,
					),
					'reason'     => array(
						'type'     => 'string',
						'required' => true,
					),
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
