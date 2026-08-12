<?php
/**
 * Fragmentos de consulta da taxonomia própria, para busca, estoque e flash sale.
 *
 * Existe para os três módulos filtrarem pelo MESMO critério. Com `product_cat`
 * cada um resolvia de um jeito — `flash_sale` usava `tax_query` (que inclui
 * descendentes por padrão), `catalog_search` casava slug exato e `vendor_stock`
 * casava `term_id` exato. Buscar `categories=papel` não devolvia um produto que
 * só tinha o filho `Slim`. Aqui a semântica é uma só:
 *
 * - filtrar por CATEGORIA devolve todo produto daquela categoria, tenha ele a
 *   subcategoria que tiver;
 * - filtrar por SUBCATEGORIA restringe dentro dela, com OR dentro de uma
 *   faceta e AND entre facetas distintas.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve o id de uma categoria a partir do slug.
 *
 * @param string $slug Slug da categoria.
 * @return int 0 quando não existe.
 */
function papelito_taxonomy_category_id_by_slug( $slug ) {
	$category = papelito_category_get_by_slug( $slug );

	return null === $category ? 0 : (int) $category['id'];
}

/**
 * Resolve ids de subcategoria a partir dos slugs, dentro de uma categoria.
 *
 * Slug de subcategoria é único POR categoria: sem o escopo, `slim` seria
 * ambíguo entre Sedas, Piteiras e Filtros.
 *
 * @param int      $category_id Categoria de escopo; 0 busca em todas.
 * @param string[] $slugs       Slugs desejados.
 * @return int[]
 */
function papelito_taxonomy_subcategory_ids_by_slugs( $category_id, array $slugs ) {
	global $wpdb;

	$slugs = array_values(
		array_filter( array_map( 'papelito_taxonomy_slugify', $slugs ) )
	);

	if ( empty( $slugs ) ) {
		return array();
	}

	$tables       = papelito_product_taxonomy_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
	$category_id  = (int) $category_id;
	$sql          = "SELECT subcategory.id FROM {$tables['subcategories']} subcategory INNER JOIN {$tables['categories']} category ON category.id = subcategory.category_id AND category.is_active = 1 AND category.archived_at IS NULL WHERE subcategory.slug IN ({$placeholders}) AND subcategory.is_active = 1 AND subcategory.archived_at IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$params       = $slugs;

	if ( $category_id > 0 ) {
		$sql     .= ' AND subcategory.category_id = %d';
		$params[] = $category_id;
	}

	$rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return array_map( 'intval', is_array( $rows ) ? $rows : array() );
}

/**
 * Confirma que cada slug de subcategoria pedido existe no escopo ativo.
 *
 * @param int      $category_id Categoria de escopo; 0 busca em todas.
 * @param string[] $slugs       Slugs desejados.
 * @return bool True quando algum slug não resolve.
 */
function papelito_taxonomy_has_unresolved_subcategory_slugs( $category_id, array $slugs ) {
	global $wpdb;

	$slugs = array_values( array_unique( array_filter( array_map( 'papelito_taxonomy_slugify', $slugs ) ) ) );

	if ( empty( $slugs ) ) {
		return false;
	}

	$tables       = papelito_product_taxonomy_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
	$sql          = "SELECT DISTINCT subcategory.slug FROM {$tables['subcategories']} subcategory INNER JOIN {$tables['categories']} category ON category.id = subcategory.category_id AND category.is_active = 1 AND category.archived_at IS NULL WHERE subcategory.slug IN ({$placeholders}) AND subcategory.is_active = 1 AND subcategory.archived_at IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$params       = $slugs;
	$category_id  = (int) $category_id;

	if ( $category_id > 0 ) {
		$sql     .= ' AND subcategory.category_id = %d';
		$params[] = $category_id;
	}

	$resolved = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return count( array_unique( (array) $resolved ) ) !== count( $slugs );
}

/**
 * Agrupa subcategorias ativas por faceta e confirma que todos os ids pedidos
 * pertencem ao escopo informado. Essa informação não pode ser descartada: o
 * filtro é OR dentro da faceta e AND entre facetas.
 *
 * @param int   $category_id Categoria de escopo; 0 aceita qualquer categoria.
 * @param int[] $subcategory_ids Ids pedidos.
 * @return array{groups:array<string,int[]>,unresolved:bool}
 */
function papelito_taxonomy_subcategory_facet_groups( $category_id, array $subcategory_ids ) {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $subcategory_ids ) ) ) );

	if ( empty( $ids ) ) {
		return array(
			'groups'     => array(),
			'unresolved' => false,
		);
	}

	$tables       = papelito_product_taxonomy_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql          = "SELECT subcategory.id, subcategory.facet FROM {$tables['subcategories']} subcategory INNER JOIN {$tables['categories']} category ON category.id = subcategory.category_id AND category.is_active = 1 AND category.archived_at IS NULL WHERE subcategory.id IN ({$placeholders}) AND subcategory.is_active = 1 AND subcategory.archived_at IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$params       = $ids;
	$category_id  = (int) $category_id;

	if ( $category_id > 0 ) {
		$sql     .= ' AND subcategory.category_id = %d';
		$params[] = $category_id;
	}

	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$groups = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$facet = (string) ( $row['facet'] ?? 'geral' );
		$groups[ $facet ][] = (int) $row['id'];
	}

	return array(
		'groups'     => $groups,
		'unresolved' => count( $rows ) !== count( $ids ),
	);
}

/**
 * Cláusula `EXISTS` que restringe um produto por categoria e/ou subcategorias.
 *
 * Devolve `null` quando não há nada a filtrar, para o chamador não montar SQL à
 * toa. Devolve uma cláusula impossível quando o filtro pedido não resolve —
 * fail-closed: categoria inexistente não pode virar "todos os produtos".
 *
 * @param string $product_expr     Expressão SQL do id do produto (ex.: `p.ID`).
 * @param int    $category_id      Id da categoria; 0 ignora.
 * @param int[]  $subcategory_ids  Ids de subcategoria; vazio ignora.
 * @param bool   $unresolved       True quando o chamador pediu filtro que não resolveu.
 * @return array{sql:string,params:array<int,mixed>}|null
 */
function papelito_taxonomy_exists_clause( $product_expr, $category_id, array $subcategory_ids, $unresolved = false ) {
	$tables          = papelito_product_taxonomy_table_names();
	$category_id     = (int) $category_id;
	$subcategory_ids = array_values( array_unique( array_filter( array_map( 'intval', $subcategory_ids ) ) ) );
	$facet_groups    = papelito_taxonomy_subcategory_facet_groups( $category_id, $subcategory_ids );
	$unresolved      = $unresolved || $facet_groups['unresolved'];

	if ( $unresolved ) {
		return array(
			'sql'    => '1 = 0',
			'params' => array(),
		);
	}

	if ( $category_id <= 0 && empty( $subcategory_ids ) ) {
		return null;
	}

	$clauses = array();
	$params  = array();

	if ( $category_id > 0 ) {
		$clauses[] = "EXISTS ( SELECT 1 FROM {$tables['product_category']} papelito_pc WHERE papelito_pc.product_id = {$product_expr} AND papelito_pc.category_id = %d )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[]  = $category_id;
	}

	foreach ( $facet_groups['groups'] as $facet_ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $facet_ids ), '%d' ) );
		$clauses[]    = "EXISTS ( SELECT 1 FROM {$tables['product_subcategory']} papelito_ps WHERE papelito_ps.product_id = {$product_expr} AND papelito_ps.subcategory_id IN ({$placeholders}) )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params       = array_merge( $params, $facet_ids );
	}

	return array(
		'sql'    => '( ' . implode( ' AND ', $clauses ) . ' )',
		'params' => $params,
	);
}

/**
 * Normaliza filtros públicos por slug em ramos de categoria independentes.
 *
 * Um slug de subcategoria só é significativo dentro da sua categoria. Montar
 * um único grupo global transforma, por exemplo, `slim` de categorias distintas
 * em facetas que nenhum produto pode satisfazer. Cada ramo preserva OR entre
 * categorias e aplica OR/AND de facetas somente entre subcategorias da mesma
 * categoria.
 *
 * @param string $product_expr Expressão SQL do id do produto.
 * @param string[] $category_slugs Categorias pedidas.
 * @param string[] $subcategory_slugs Subcategorias pedidas.
 * @return array{sql:string,params:array<int,mixed>}|null
 */
function papelito_taxonomy_slug_filter_clause( $product_expr, array $category_slugs, array $subcategory_slugs ) {
	global $wpdb;

	$category_slugs    = array_values( array_unique( array_filter( array_map( 'papelito_taxonomy_slugify', $category_slugs ) ) ) );
	$subcategory_slugs = array_values( array_unique( array_filter( array_map( 'papelito_taxonomy_slugify', $subcategory_slugs ) ) ) );

	if ( empty( $category_slugs ) && empty( $subcategory_slugs ) ) {
		return null;
	}

	$category_ids = array_values( array_unique( array_filter( array_map( 'papelito_taxonomy_category_id_by_slug', $category_slugs ) ) ) );
	if ( ! empty( $category_slugs ) && count( $category_ids ) !== count( $category_slugs ) ) {
		return array( 'sql' => '1 = 0', 'params' => array() );
	}

	if ( ! empty( $subcategory_slugs ) && papelito_taxonomy_has_unresolved_subcategory_slugs( 0, $subcategory_slugs ) ) {
		return array( 'sql' => '1 = 0', 'params' => array() );
	}

	if ( empty( $category_ids ) ) {
		$tables       = papelito_product_taxonomy_table_names();
		$placeholders = implode( ',', array_fill( 0, count( $subcategory_slugs ), '%s' ) );
		$sql          = "SELECT DISTINCT category_id FROM {$tables['subcategories']} WHERE slug IN ({$placeholders}) AND is_active = 1 AND archived_at IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$category_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $subcategory_slugs ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	$branches = array();
	$params   = array();
	foreach ( $category_ids as $category_id ) {
		$subcategory_ids = papelito_taxonomy_subcategory_ids_by_slugs( $category_id, $subcategory_slugs );
		if ( ! empty( $subcategory_slugs ) && count( $subcategory_ids ) !== count( $subcategory_slugs ) ) {
			continue;
		}

		$branch = papelito_taxonomy_exists_clause( $product_expr, $category_id, $subcategory_ids );
		if ( null !== $branch ) {
			$branches[] = $branch['sql'];
			$params     = array_merge( $params, $branch['params'] );
		}
	}

	if ( empty( $branches ) ) {
		return array( 'sql' => '1 = 0', 'params' => array() );
	}

	return array(
		'sql'    => '( ' . implode( ' OR ', $branches ) . ' )',
		'params' => $params,
	);
}

/**
 * Cláusula que barra produto sem categoria principal.
 *
 * É aqui que a regra "todo produto publicado tem categoria" deixa de ser só
 * relatório e passa a valer: sem chave estrangeira, o banco garante no máximo
 * uma; o "pelo menos uma" é este gate, no ponto em que importa — a vitrine.
 *
 * @param string $product_expr Expressão SQL do id do produto.
 * @return string
 */
function papelito_taxonomy_classified_clause( $product_expr ) {
	$tables = papelito_product_taxonomy_table_names();

	return "EXISTS ( SELECT 1 FROM {$tables['product_category']} papelito_gate INNER JOIN {$tables['categories']} papelito_category ON papelito_category.id = papelito_gate.category_id AND papelito_category.is_active = 1 AND papelito_category.archived_at IS NULL WHERE papelito_gate.product_id = {$product_expr} )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Ids de produto de uma categoria, para quem só sabe filtrar por `post__in`.
 *
 * @param int   $category_id     Id da categoria.
 * @param int[] $subcategory_ids Ids de subcategoria.
 * @return int[]
 */
function papelito_taxonomy_product_ids( $category_id, array $subcategory_ids = array() ) {
	global $wpdb;

	$clause = papelito_taxonomy_exists_clause( 'p.ID', $category_id, $subcategory_ids );

	if ( null === $clause ) {
		return array();
	}

	$sql  = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND {$clause['sql']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = empty( $clause['params'] )
		? $wpdb->get_col( $sql ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		: $wpdb->get_col( $wpdb->prepare( $sql, $clause['params'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return array_map( 'intval', is_array( $rows ) ? $rows : array() );
}

// ------------------------------------------------------------------
// Filtro na REST de produtos do WooCommerce
// ------------------------------------------------------------------

/**
 * Faz `/wc/v3/products` entender `papelito_category` e `papelito_subcategories`.
 *
 * O painel administrativo pagina a lista por essa rota, que só conhece
 * `product_cat`. Sem isto, filtrar por categoria no admin exigiria traduzir para
 * `term_id` no cliente — e o painel voltaria a saber que `product_cat` existe.
 *
 * Usa `post__in` porque a filtragem acontece em tabelas próprias: o WP_Query
 * pagina o conjunto incluído normalmente, e o custo fica no banco, não na URL.
 *
 * @param array<string,mixed> $args    Argumentos do WP_Query.
 * @param WP_REST_Request     $request Requisição.
 * @return array<string,mixed>
 */
function papelito_taxonomy_filter_wc_product_query( $args, $request ) {
	$category = absint( $request['papelito_category'] ?? 0 );
	$raw_subs = $request['papelito_subcategories'] ?? '';
	$subs     = array_values(
		array_filter(
			array_map( 'intval', is_array( $raw_subs ) ? $raw_subs : explode( ',', (string) $raw_subs ) )
		)
	);

	if ( $category <= 0 && empty( $subs ) ) {
		return $args;
	}

	$ids = papelito_taxonomy_product_ids( $category, $subs );

	// Fail-closed: filtro que não casa devolve nada, nunca a lista inteira.
	$args['post__in'] = empty( $ids ) ? array( 0 ) : $ids;

	return $args;
}
add_filter( 'woocommerce_rest_product_object_query', 'papelito_taxonomy_filter_wc_product_query', 10, 2 );

/**
 * Declara os parâmetros novos na coleção de produtos da REST do WooCommerce.
 *
 * Sem isto o WordPress descarta os parâmetros antes de chegarem ao filtro.
 *
 * @param array<string,mixed> $params Parâmetros aceitos.
 * @return array<string,mixed>
 */
function papelito_taxonomy_register_wc_product_params( $params ) {
	$params['papelito_category'] = array(
		'description'       => 'Id da categoria da taxonomia Papelito.',
		'type'              => 'integer',
		'default'           => 0,
		'sanitize_callback' => 'absint',
	);

	$params['papelito_subcategories'] = array(
		'description'       => 'Ids de subcategoria da taxonomia Papelito, separados por vírgula.',
		'type'              => 'string',
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
	);

	return $params;
}
add_filter( 'woocommerce_rest_product_collection_params', 'papelito_taxonomy_register_wc_product_params' );
