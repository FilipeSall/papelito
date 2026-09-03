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
 * - filtrar por CATEGORIA devolve todos os produtos dela, tenham eles a
 *   subcategoria que tiverem;
 * - filtrar por SUBCATEGORIA restringe dentro dela, com OR dentro de uma
 *   faceta e AND entre facetas distintas.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/** Recorte da subcategoria pela categoria dona, repetido em cada consulta por escopo. */
const PAPELITO_TAXONOMY_SUBCATEGORY_SCOPE_SQL = ' AND subcategory.category_id = %d';

/**
 * Teto de slugs por requisição.
 *
 * A rota de busca é pública e aceita CSV livre, e cada categoria pedida custa
 * consultas próprias. O teto é ordens de grandeza acima da taxonomia real; passar
 * dele é abuso, não filtro, e cai fechado como qualquer pedido que não resolve.
 */
const PAPELITO_TAXONOMY_MAX_CATEGORY_SLUGS    = 50;
const PAPELITO_TAXONOMY_MAX_SUBCATEGORY_SLUGS = 200;

/**
 * Cláusula que não casa com produto nenhum.
 *
 * Filtro que não resolve é fail-closed: devolve vazio em vez de ser ignorado, senão
 * categoria renomeada viraria "mostre o catálogo inteiro".
 *
 * @return array{sql:string,params:array<int,mixed>}
 */
function papelito_taxonomy_impossible_clause() {
	return array(
		'sql'    => '1 = 0',
		'params' => array(),
	);
}

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
		$sql     .= PAPELITO_TAXONOMY_SUBCATEGORY_SCOPE_SQL;
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
		$sql     .= PAPELITO_TAXONOMY_SUBCATEGORY_SCOPE_SQL;
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
		$sql     .= PAPELITO_TAXONOMY_SUBCATEGORY_SCOPE_SQL;
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
		return papelito_taxonomy_impossible_clause();
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
 * Separa `subcategories` em escopos de categoria.
 *
 * O item vem como `categoria.subcategoria`. O slug solto, de link antigo, não tem
 * escopo e continua valendo para toda categoria pedida.
 *
 * Token escopado com metade vazia (`sedas.`, `.brown`) é pedido inválido, não item
 * a descartar: descartar transformaria filtro quebrado em filtro ausente, e a
 * listagem devolveria a categoria inteira.
 *
 * @param string[] $tokens Itens crus.
 * @return array{scoped:array<string,string[]>,bare:string[],invalid:bool}
 */
function papelito_taxonomy_parse_scoped_subcategories( array $tokens ) {
	$scoped  = array();
	$bare    = array();
	$invalid = false;

	foreach ( $tokens as $token ) {
		$token = (string) $token;
		$parts = explode( '.', $token, 2 );

		if ( count( $parts ) < 2 ) {
			$slug = papelito_taxonomy_slugify( $token );
			if ( '' !== $slug && ! in_array( $slug, $bare, true ) ) {
				$bare[] = $slug;
			}
			continue;
		}

		$category = papelito_taxonomy_slugify( $parts[0] );
		$slug     = papelito_taxonomy_slugify( $parts[1] );

		if ( '' === $category || '' === $slug ) {
			$invalid = true;
			continue;
		}

		if ( ! isset( $scoped[ $category ] ) ) {
			$scoped[ $category ] = array();
		}

		if ( ! in_array( $slug, $scoped[ $category ], true ) ) {
			$scoped[ $category ][] = $slug;
		}
	}

	return array( 'scoped' => $scoped, 'bare' => $bare, 'invalid' => $invalid );
}

/**
 * Resolve várias categorias numa consulta só, indexadas por slug.
 *
 * Uma consulta por slug fazia o custo crescer com o tamanho da lista que a rota
 * pública aceita, e ainda relia a mesma linha na hora de montar o ramo.
 *
 * @param string[] $slugs Slugs já normalizados.
 * @return array<string,array<string,mixed>>
 */
function papelito_taxonomy_categories_by_slugs( array $slugs ) {
	global $wpdb;

	$slugs = array_values( array_unique( array_filter( $slugs ) ) );

	if ( empty( $slugs ) ) {
		return array();
	}

	$tables       = papelito_product_taxonomy_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
	$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['categories']} WHERE slug IN ({$placeholders})", $slugs ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$categories   = array();

	foreach ( (array) $rows as $row ) {
		$category = papelito_category_shape( $row );

		if ( null !== $category ) {
			$categories[ $category['slug'] ] = $category;
		}
	}

	return $categories;
}

/**
 * Confirma que cada escopo pedido aponta para uma categoria válida do pedido.
 *
 * Escopo de categoria que não está na seleção é requisição inválida, não filtro a
 * ignorar: devolver o catálogo inteiro seria abrir o filtro.
 *
 * @param array<string,string[]>            $scoped         Slugs por categoria.
 * @param string[]                          $category_slugs Categorias pedidas; vazio aceita qualquer uma.
 * @param array<string,array<string,mixed>> $categories     Categorias já resolvidas, por slug.
 * @return bool
 */
function papelito_taxonomy_scoped_subcategories_resolve( array $scoped, array $category_slugs, array $categories ) {
	foreach ( $scoped as $category_slug => $slugs ) {
		if ( ! empty( $category_slugs ) && ! in_array( $category_slug, $category_slugs, true ) ) {
			return false;
		}

		if ( ! isset( $categories[ $category_slug ] ) ) {
			return false;
		}

		if ( papelito_taxonomy_has_unresolved_subcategory_slugs( $categories[ $category_slug ]['id'], $slugs ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Categorias implícitas quando o pedido veio só de subcategoria.
 *
 * @param array<string,string[]> $scoped Slugs por categoria.
 * @param string[]               $bare   Slugs sem escopo.
 * @return array<string,array<string,mixed>>
 */
function papelito_taxonomy_categories_of_subcategories( array $scoped, array $bare ) {
	global $wpdb;

	$slugs = array_keys( $scoped );

	if ( ! empty( $bare ) ) {
		$tables       = papelito_product_taxonomy_table_names();
		$placeholders = implode( ',', array_fill( 0, count( $bare ), '%s' ) );
		$sql          = "SELECT DISTINCT category.slug FROM {$tables['subcategories']} subcategory INNER JOIN {$tables['categories']} category ON category.id = subcategory.category_id WHERE subcategory.slug IN ({$placeholders}) AND subcategory.is_active = 1 AND subcategory.archived_at IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slugs        = array_merge( $slugs, array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $bare ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	return papelito_taxonomy_categories_by_slugs( $slugs );
}

/**
 * Ramo de uma categoria, já com o refinamento que pertence a ela.
 *
 * Categoria sem escopo próprio entra inteira; com escopo, restringe. O slug solto
 * mantém a semântica antiga — precisa resolver por completo dentro da categoria,
 * senão o ramo cai fora.
 *
 * @param string                 $product_expr Expressão SQL do id do produto.
 * @param array<string,mixed>    $category     Categoria do ramo.
 * @param array<string,string[]> $scoped       Slugs por categoria.
 * @param string[]               $bare         Slugs sem escopo.
 * @return array{sql:string,params:array<int,mixed>}|null
 */
function papelito_taxonomy_category_branch( $product_expr, array $category, array $scoped, array $bare ) {
	$category_slug = (string) $category['slug'];
	$has_scope     = isset( $scoped[ $category_slug ] );
	$wanted        = $has_scope ? $scoped[ $category_slug ] : $bare;

	$subcategory_ids = papelito_taxonomy_subcategory_ids_by_slugs( (int) $category['id'], $wanted );

	if ( ! $has_scope && ! empty( $wanted ) && count( $subcategory_ids ) !== count( $wanted ) ) {
		return null;
	}

	return papelito_taxonomy_exists_clause( $product_expr, (int) $category['id'], $subcategory_ids );
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
 * Com o escopo `categoria.subcategoria`, cada categoria é refinada de forma
 * independente e a que não recebeu escopo nenhum entra inteira — sem isso,
 * refinar Sedas apagaria Piteiras do resultado.
 *
 * @param string $product_expr Expressão SQL do id do produto.
 * @param string[] $category_slugs Categorias pedidas.
 * @param string[] $subcategory_slugs Subcategorias pedidas, com ou sem escopo.
 * @return array{sql:string,params:array<int,mixed>}|null
 */
function papelito_taxonomy_slug_filter_clause( $product_expr, array $category_slugs, array $subcategory_slugs ) {
	$category_slugs = array_values( array_unique( array_filter( array_map( 'papelito_taxonomy_slugify', $category_slugs ) ) ) );
	$parsed         = papelito_taxonomy_parse_scoped_subcategories( $subcategory_slugs );
	$scoped         = $parsed['scoped'];
	$bare           = $parsed['bare'];

	if ( empty( $category_slugs ) && empty( $scoped ) && empty( $bare ) ) {
		return $parsed['invalid'] ? papelito_taxonomy_impossible_clause() : null;
	}

	if ( $parsed['invalid'] ) {
		return papelito_taxonomy_impossible_clause();
	}

	if ( count( $category_slugs ) > PAPELITO_TAXONOMY_MAX_CATEGORY_SLUGS || count( $subcategory_slugs ) > PAPELITO_TAXONOMY_MAX_SUBCATEGORY_SLUGS ) {
		return papelito_taxonomy_impossible_clause();
	}

	$categories = papelito_taxonomy_categories_by_slugs( $category_slugs );

	if ( count( $categories ) !== count( $category_slugs ) ) {
		return papelito_taxonomy_impossible_clause();
	}

	if ( ! empty( $bare ) && papelito_taxonomy_has_unresolved_subcategory_slugs( 0, $bare ) ) {
		return papelito_taxonomy_impossible_clause();
	}

	// A derivação vem antes da validação: sem `categories`, quem diz de que categoria
	// o pedido é são os próprios escopos.
	if ( empty( $categories ) ) {
		$categories = papelito_taxonomy_categories_of_subcategories( $scoped, $bare );
	}

	if ( ! papelito_taxonomy_scoped_subcategories_resolve( $scoped, $category_slugs, $categories ) ) {
		return papelito_taxonomy_impossible_clause();
	}

	$clause = papelito_taxonomy_categories_clause( $product_expr, $categories, $scoped, $bare );

	return null === $clause ? papelito_taxonomy_impossible_clause() : $clause;
}

/**
 * Une em um OR só o ramo SQL de cada categoria pedida.
 *
 * @param string $product_expr Expressão SQL do id do produto.
 * @param array<int,array<string,mixed>> $categories Categorias já resolvidas.
 * @param array<string,string[]> $scoped Subcategorias com escopo de categoria.
 * @param string[] $bare Subcategorias sem escopo.
 * @return array{sql:string,params:array<int,mixed>}|null Null quando nenhuma categoria rendeu ramo.
 */
function papelito_taxonomy_categories_clause( $product_expr, array $categories, array $scoped, array $bare ) {
	$branches = array();
	$params   = array();

	foreach ( $categories as $category ) {
		$branch = papelito_taxonomy_category_branch( $product_expr, $category, $scoped, $bare );

		if ( null !== $branch ) {
			$branches[] = $branch['sql'];
			$params     = array_merge( $params, $branch['params'] );
		}
	}

	if ( empty( $branches ) ) {
		return null;
	}

	return array(
		'sql'    => '( ' . implode( ' OR ', $branches ) . ' )',
		'params' => $params,
	);
}

/**
 * Cláusula que barra produto sem categoria principal.
 *
 * É aqui que a regra "todos os produtos publicados têm categoria" deixa de ser só
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

/**
 * Campos sem os quais um produto não chega à vitrine.
 *
 * Peso e as três dimensões porque a cotação de frete falha sem eles; imagem porque o card não
 * renderiza; preço porque não há o que cobrar; categoria porque a taxonomia Papelito é a única
 * fonte de classificação headless — produto publicado sem categoria não entra na vitrine.
 *
 * @return array<int,string>
 */
function papelito_incomplete_shipping_meta_keys(): array {
	return array( '_weight', '_length', '_width', '_height' );
}

/**
 * Produtos aos quais falta algum dado essencial para irem à vitrine.
 *
 * Em produto variável peso, dimensões e preço moram na variação, não no pai: cobrar isso do pai
 * marcaria como incompleto todo produto variável, inclusive os corretos. Por isso o recorte
 * pergunta às variações quando elas existem, e ao próprio produto quando não existem.
 *
 * @return array<int,int>
 */
function papelito_incomplete_product_ids(): array {
	global $wpdb;

	$tables       = papelito_product_taxonomy_table_names();
	$meta_keys    = papelito_incomplete_shipping_meta_keys();

	// `meta_value` vazio conta como ausente: o WooCommerce grava string vazia em campo limpo, e
	// olhar só a existência da linha deixaria passar exatamente o produto que alguém esvaziou.
	$missing_own_meta = "NOT EXISTS (
		SELECT 1 FROM {$wpdb->postmeta} pm
		WHERE pm.post_id = p.ID AND pm.meta_key = mk.meta_key
		AND pm.meta_value <> '' AND pm.meta_value IS NOT NULL
	)";

	$missing_variation_meta = "EXISTS (
		SELECT 1 FROM {$wpdb->posts} v
		WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status <> 'trash'
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} vm
			WHERE vm.post_id = v.ID AND vm.meta_key = mk.meta_key
			AND vm.meta_value <> '' AND vm.meta_value IS NOT NULL
		)
	)";

	$has_variation = "EXISTS (
		SELECT 1 FROM {$wpdb->posts} v2
		WHERE v2.post_parent = p.ID AND v2.post_type = 'product_variation' AND v2.post_status <> 'trash'
	)";

	$sql = "
		SELECT DISTINCT p.ID
		FROM {$wpdb->posts} p
		CROSS JOIN (
			SELECT %s AS meta_key UNION ALL SELECT %s UNION ALL SELECT %s UNION ALL SELECT %s
			UNION ALL SELECT '_price' UNION ALL SELECT '_thumbnail_id'
		) mk
		WHERE p.post_type = 'product'
		AND p.post_status NOT IN ( 'trash', 'auto-draft' )
		AND (
			( mk.meta_key = '_thumbnail_id' AND {$missing_own_meta} )
			OR ( mk.meta_key <> '_thumbnail_id' AND {$has_variation} AND {$missing_variation_meta} )
			OR ( mk.meta_key <> '_thumbnail_id' AND NOT {$has_variation} AND {$missing_own_meta} )
		)

		UNION

		SELECT p.ID
		FROM {$wpdb->posts} p
		WHERE p.post_type = 'product'
		AND p.post_status NOT IN ( 'trash', 'auto-draft' )
		AND NOT EXISTS (
			SELECT 1 FROM {$tables['product_category']} pc WHERE pc.product_id = p.ID
		)
	"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$rows = $wpdb->get_col( $wpdb->prepare( $sql, $meta_keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return array_values( array_unique( array_map( 'intval', is_array( $rows ) ? $rows : array() ) ) );
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

	$incomplete = ! empty( $request['papelito_incomplete'] );

	if ( $category <= 0 && empty( $subs ) && ! $incomplete ) {
		return $args;
	}

	$ids = ( $category > 0 || ! empty( $subs ) )
		? papelito_taxonomy_product_ids( $category, $subs )
		: null;

	if ( $incomplete ) {
		$incomplete_ids = papelito_incomplete_product_ids();
		$ids            = null === $ids ? $incomplete_ids : array_values( array_intersect( $ids, $incomplete_ids ) );
	}

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

	$params['papelito_incomplete'] = array(
		'description'       => 'Só produtos sem algum dado essencial para a vitrine.',
		'type'              => 'boolean',
		'default'           => false,
		'sanitize_callback' => 'rest_sanitize_boolean',
	);

	return $params;
}
add_filter( 'woocommerce_rest_product_collection_params', 'papelito_taxonomy_register_wc_product_params' );
