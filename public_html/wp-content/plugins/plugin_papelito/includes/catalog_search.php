<?php
/**
 * Busca pública do catálogo por nome e tags.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_CATALOG_SEARCH_WHITESPACE_PATTERN' ) ) {
	define( 'PAPELITO_CATALOG_SEARCH_WHITESPACE_PATTERN', '/\s+/u' );
}

function papelito_catalog_search_normalize( string $value ): string {
	$value = remove_accents( $value );
	$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	$value = preg_replace( PAPELITO_CATALOG_SEARCH_WHITESPACE_PATTERN, ' ', trim( $value ) );

	return is_string( $value ) ? $value : '';
}

function papelito_catalog_search_terms( string $value ): array {
	$normalized = papelito_catalog_search_normalize( $value );
	if ( '' === $normalized ) {
		return array();
	}

	return array_values( array_filter( preg_split( PAPELITO_CATALOG_SEARCH_WHITESPACE_PATTERN, $normalized ) ) );
}

function papelito_catalog_search_matches_field( string $field, string $term ): bool {
	if ( '' === $field || '' === $term ) {
		return false;
	}

	foreach ( preg_split( PAPELITO_CATALOG_SEARCH_WHITESPACE_PATTERN, $field ) as $word ) {
		if ( str_starts_with( $word, $term ) || str_contains( $word, $term ) ) {
			return true;
		}
	}

	return false;
}

function papelito_catalog_search_normalize_tags( array $tags ): array {
	return array_values(
		array_filter(
			array_map(
				static fn( $tag ): string => papelito_catalog_search_normalize( (string) $tag ),
				$tags
			)
		)
	);
}

function papelito_catalog_search_matches_any_tag( array $tags, string $term ): bool {
	foreach ( $tags as $tag ) {
		if ( papelito_catalog_search_matches_field( $tag, $term ) ) {
			return true;
		}
	}

	return false;
}

function papelito_catalog_search_relevance_score( string $query, string $name, bool $has_name_match, bool $has_tag_match, bool $all_in_name ): int {
	if ( $name === $query ) {
		return 0;
	}

	if ( str_starts_with( $name, $query ) ) {
		return 1;
	}

	if ( $all_in_name ) {
		return 2;
	}

	if ( $has_name_match && $has_tag_match ) {
		return 3;
	}

	return $has_tag_match ? 4 : 5;
}

function papelito_catalog_search_relevance( string $query, array $terms, string $name, array $tags ): ?int {
	$normalized_name = papelito_catalog_search_normalize( $name );
	$normalized_tags = papelito_catalog_search_normalize_tags( $tags );
	$has_name_match = false;
	$has_tag_match  = false;
	$all_in_name    = true;

	foreach ( $terms as $term ) {
		$name_matches = papelito_catalog_search_matches_field( $normalized_name, $term );
		$tag_matches  = papelito_catalog_search_matches_any_tag( $normalized_tags, $term );

		if ( ! $name_matches && ! $tag_matches ) {
			return null;
		}

		$has_name_match = $has_name_match || $name_matches;
		$has_tag_match  = $has_tag_match || $tag_matches;
		$all_in_name    = $all_in_name && $name_matches;
	}

	return papelito_catalog_search_relevance_score(
		$query,
		$normalized_name,
		$has_name_match,
		$has_tag_match,
		$all_in_name
	);
}

function papelito_catalog_search_rate_limit(): bool {
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key   = 'papelito_catalog_search_rl_' . hash( 'sha256', $ip );
	$count = (int) get_transient( $key );

	if ( $count >= 90 ) {
		return false;
	}

	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

	return true;
}

/**
 * Preço efetivo dos produtos da campanha ativa, indexado por ID.
 *
 * A busca filtra faixa de preço pelo `_price` persistido, que não conhece o desconto
 * da campanha. Sem isto, um produto exibido a R$ 2,23 sumiria do filtro "até R$ 10".
 *
 * @return array<int,float>
 */
function papelito_catalog_search_campaign_prices(): array {
	if ( ! function_exists( 'papelito_flash_sale_normalize_campaign' ) ) {
		return array();
	}

	$campaign = papelito_flash_sale_normalize_campaign( papelito_flash_sale_get_raw_campaign() );

	if ( null === $campaign || 'active' !== ( $campaign['status'] ?? '' ) ) {
		return array();
	}

	$discount = papelito_flash_sale_clamp_discount( $campaign['discountPercent'] ?? 0 );
	$prices   = array();

	foreach ( papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() ) as $product_id ) {
		$product = papelito_flash_sale_load_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$regular = papelito_flash_sale_to_float( $product->get_regular_price( 'edit' ) );
		$base    = $regular > 0 ? $regular : papelito_flash_sale_to_float( $product->get_price( 'edit' ) );

		if ( $base <= 0 ) {
			continue;
		}

		$prices[ $product_id ] = round( $base * ( 100 - $discount ) / 100, 2 );
	}

	return $prices;
}

/**
 * Descarta produtos da campanha cujo preço promocional está fora da faixa pedida.
 *
 * @param array<int,array<string,mixed>> $rows            Linhas cruas do catálogo.
 * @param array<int,float>               $campaign_prices Preço promocional por produto.
 * @param array<string,mixed>            $args            Argumentos da busca.
 * @return array<int,array<string,mixed>>
 */
function papelito_catalog_search_filter_campaign_prices( array $rows, array $campaign_prices, array $args ): array {
	if ( empty( $campaign_prices ) ) {
		return $rows;
	}

	$min_price = isset( $args['min_price'] ) && null !== $args['min_price'] ? (float) $args['min_price'] : null;
	$max_price = isset( $args['max_price'] ) && null !== $args['max_price'] ? (float) $args['max_price'] : null;

	if ( null === $min_price && null === $max_price ) {
		return $rows;
	}

	return array_values(
		array_filter(
			$rows,
			static function ( array $row ) use ( $campaign_prices, $min_price, $max_price ): bool {
				$product_id = (int) ( $row['ID'] ?? 0 );

				if ( ! isset( $campaign_prices[ $product_id ] ) ) {
					return true;
				}

				$price = (float) $campaign_prices[ $product_id ];

				if ( null !== $min_price && $price < $min_price ) {
					return false;
				}

				return null === $max_price || $price <= $max_price;
			}
		)
	);
}

/**
 * Coleções curadas normalizadas para consumo no filtro de busca.
 *
 * @return string[]
 */
function papelito_catalog_search_curated_collections(): array {
	return array_values(
		array_unique(
			array_filter( array_map( 'sanitize_title', papelito_curated_collections() ) )
		)
	);
}

/**
 * Normaliza a coleção pública antes de montar a consulta.
 *
 * @param mixed $value Valor cru da coleção.
 * @return string|null `null` quando o valor é inválido, para falhar fechado.
 */
function papelito_catalog_search_collection( $value ): ?string {
	$collection = sanitize_title( (string) $value );
	$curated    = papelito_catalog_search_curated_collections();
	$allowed    = array_merge( array( 'todos', 'novidades', 'promocoes' ), $curated );

	return in_array( $collection, $allowed, true ) ? $collection : null;
}

/**
 * Restringe a consulta à coleção curada sem trazer produtos para PHP.
 *
 * `novidades` é filtrada depois: ela é o recorte global dos oito produtos mais
 * recentes da vitrine, e não pode ser recalculada a partir do termo pesquisado.
 *
 * @param string $product_expr Expressão SQL do produto da consulta externa.
 * @param string $collection Coleção já normalizada.
 * @param int[]  $campaign_ids Produtos da campanha relâmpago ativa.
 * @return array{sql:string,params:array<int,mixed>}|null
 */
function papelito_catalog_search_collection_clause( string $product_expr, string $collection, array $campaign_ids ): ?array {
	global $wpdb;

	if ( 'todos' === $collection || 'novidades' === $collection ) {
		return null;
	}

	if ( in_array( $collection, papelito_catalog_search_curated_collections(), true ) ) {
		$tables = papelito_product_taxonomy_table_names();

		return array(
			'sql'    => "EXISTS ( SELECT 1 FROM {$tables['product_collection']} papelito_collection WHERE papelito_collection.product_id = {$product_expr} AND papelito_collection.collection_slug = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'params' => array( $collection ),
		);
	}

	if ( 'promocoes' !== $collection ) {
		return array(
			'sql'    => '1 = 0',
			'params' => array(),
		);
	}

	$native_sale  = "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sale_meta INNER JOIN {$wpdb->postmeta} regular_meta ON regular_meta.post_id = sale_meta.post_id AND regular_meta.meta_key = '_regular_price' WHERE sale_meta.post_id = {$product_expr} AND sale_meta.meta_key = '_sale_price' AND CAST( REPLACE( sale_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 AND CAST( REPLACE( sale_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) < CAST( REPLACE( regular_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$campaign_ids = array_values( array_unique( array_filter( array_map( 'intval', $campaign_ids ) ) ) );

	if ( empty( $campaign_ids ) ) {
		return array(
			'sql'    => $native_sale,
			'params' => array(),
		);
	}

	return array(
		'sql'    => "( {$native_sale} OR {$product_expr} IN ( " . implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) ) . ' ) )', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'params' => $campaign_ids,
	);
}

/**
 * Mantém somente a coleção global de novidades: os oito mais recentes da
 * vitrine elegível, definidos antes de busca, categoria, preço e paginação.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function papelito_catalog_search_filter_new_arrivals( array $rows, array $args ): array {
	$global_args                  = $args;
	$global_args['categories']    = array();
	$global_args['subcategories'] = array();
	$global_args['min_price']     = null;
	$global_args['max_price']     = null;
	$global_args['collection']    = 'todos';
	$global_rows                  = papelito_catalog_search_product_rows( $global_args );
	$newest_ids                   = array_map( 'intval', array_slice( array_column( $global_rows, 'ID' ), 0, 8 ) );
	$newest_lookup                = array_fill_keys( $newest_ids, true );

	return array_values(
		array_filter(
			$rows,
			static fn( array $row ): bool => isset( $newest_lookup[ (int) ( $row['ID'] ?? 0 ) ] )
		)
	);
}

function papelito_catalog_search_product_rows( array $args ): array {
	global $wpdb;

	$campaign_ids    = array_map( 'intval', array_keys( (array) ( $args['campaign_prices'] ?? array() ) ) );
	$campaign_escape = '';

	if ( ! empty( $campaign_ids ) ) {
		$campaign_escape = ' OR p.ID IN ( ' . implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) ) . ' )';
	}

	$categories = array_values(
		array_filter(
			array_map( 'sanitize_title', (array) ( $args['categories'] ?? array() ) )
		)
	);
	$where      = array(
		'p.post_type = %s',
		'p.post_status = %s',
		"EXISTS ( SELECT 1 FROM {$wpdb->postmeta} price_meta WHERE price_meta.post_id = p.ID AND price_meta.meta_key = '_price' AND CAST( REPLACE( price_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 )",
		"( EXISTS ( SELECT 1 FROM {$wpdb->postmeta} weight_meta INNER JOIN {$wpdb->postmeta} length_meta ON length_meta.post_id = weight_meta.post_id AND length_meta.meta_key = '_length' INNER JOIN {$wpdb->postmeta} width_meta ON width_meta.post_id = weight_meta.post_id AND width_meta.meta_key = '_width' INNER JOIN {$wpdb->postmeta} height_meta ON height_meta.post_id = weight_meta.post_id AND height_meta.meta_key = '_height' WHERE weight_meta.post_id = p.ID AND weight_meta.meta_key = '_weight' AND CAST( REPLACE( weight_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 AND CAST( REPLACE( length_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 AND CAST( REPLACE( width_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 AND CAST( REPLACE( height_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 ) OR EXISTS ( SELECT 1 FROM {$wpdb->posts} variation INNER JOIN {$wpdb->postmeta} variation_weight ON variation_weight.post_id = variation.ID AND variation_weight.meta_key = '_weight' INNER JOIN {$wpdb->postmeta} variation_length ON variation_length.post_id = variation.ID AND variation_length.meta_key = '_length' INNER JOIN {$wpdb->postmeta} variation_width ON variation_width.post_id = variation.ID AND variation_width.meta_key = '_width' INNER JOIN {$wpdb->postmeta} variation_height ON variation_height.post_id = variation.ID AND variation_height.meta_key = '_height' WHERE variation.post_parent = p.ID AND variation.post_type = 'product_variation' AND variation.post_status = 'publish' AND CAST( REPLACE( variation_weight.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 AND CAST( REPLACE( variation_length.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 AND CAST( REPLACE( variation_width.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 AND CAST( REPLACE( variation_height.meta_value, ',', '.' ) AS DECIMAL(20,6) ) > 0 ) )",
	);
	$params     = array( 'product', 'publish' );

	if ( isset( $args['min_price'] ) && null !== $args['min_price'] ) {
		$where[]  = "( EXISTS ( SELECT 1 FROM {$wpdb->postmeta} min_price_meta WHERE min_price_meta.post_id = p.ID AND min_price_meta.meta_key = '_price' AND CAST( REPLACE( min_price_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) >= %f ){$campaign_escape} )";
		$params[] = (float) $args['min_price'];
		$params   = array_merge( $params, $campaign_ids );
	}

	if ( isset( $args['max_price'] ) && null !== $args['max_price'] ) {
		$where[]  = "( EXISTS ( SELECT 1 FROM {$wpdb->postmeta} max_price_meta WHERE max_price_meta.post_id = p.ID AND max_price_meta.meta_key = '_price' AND CAST( REPLACE( max_price_meta.meta_value, ',', '.' ) AS DECIMAL(20,6) ) <= %f ){$campaign_escape} )";
		$params[] = (float) $args['max_price'];
		$params   = array_merge( $params, $campaign_ids );
	}

	// `sanitize_title` comeria o ponto do escopo `categoria.subcategoria`; quem
	// normaliza cada metade é `papelito_taxonomy_slug_filter_clause`.
	$subcategories = array_values(
		array_filter(
			array_map( 'trim', array_map( 'strval', (array) ( $args['subcategories'] ?? array() ) ) )
		)
	);

	// Vitrine não mostra produto sem categoria principal. Sem chave estrangeira,
	// é este gate que faz o "pelo menos uma categoria" valer onde importa.
	$where[] = papelito_taxonomy_classified_clause( 'p.ID' );

	$clause = papelito_taxonomy_slug_filter_clause( 'p.ID', $categories, $subcategories );

	if ( null !== $clause ) {
		$where[] = $clause['sql'];
		$params  = array_merge( $params, $clause['params'] );
	}

	$collection = papelito_catalog_search_collection( $args['collection'] ?? 'todos' );
	if ( null === $collection ) {
		$where[] = '1 = 0';
	} else {
		$collection_clause = papelito_catalog_search_collection_clause(
			'p.ID',
			$collection,
			array_map( 'intval', array_keys( (array) ( $args['campaign_prices'] ?? array() ) ) )
		);

		if ( null !== $collection_clause ) {
			$where[] = $collection_clause['sql'];
			$params  = array_merge( $params, $collection_clause['params'] );
		}
	}

	$sql = "SELECT p.ID, p.post_title FROM {$wpdb->posts} p WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.post_date DESC, p.ID DESC';

	return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
}

function papelito_catalog_search_tags_by_product( array $product_ids ): array {
	global $wpdb;

	if ( empty( $product_ids ) ) {
		return array();
	}

	$ids          = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql          = "SELECT relationship.object_id AS product_id, term.name FROM {$wpdb->term_relationships} relationship INNER JOIN {$wpdb->term_taxonomy} taxonomy ON taxonomy.term_taxonomy_id = relationship.term_taxonomy_id AND taxonomy.taxonomy = 'product_tag' INNER JOIN {$wpdb->terms} term ON term.term_id = taxonomy.term_id WHERE relationship.object_id IN ({$placeholders})";
	$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
	$tags         = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$product_id = (int) ( $row['product_id'] ?? 0 );
		if ( $product_id > 0 ) {
			$tags[ $product_id ][] = (string) ( $row['name'] ?? '' );
		}
	}

	return $tags;
}

function papelito_catalog_search_products( array $args ): array {
	$query = papelito_catalog_search_normalize( (string) ( $args['search'] ?? '' ) );
	$terms = papelito_catalog_search_terms( $query );
	$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
	$limit = min( 60, max( 1, (int) ( $args['per_page'] ?? 9 ) ) );

	if ( empty( $terms ) ) {
		return array(
			'ids'      => array(),
			'total'    => 0,
			'page'     => 1,
			'per_page' => $limit,
		);
	}

	$campaign_prices         = papelito_catalog_search_campaign_prices();
	$args['campaign_prices'] = $campaign_prices;
	$collection              = papelito_catalog_search_collection( $args['collection'] ?? 'todos' );
	if ( null === $collection ) {
		return array(
			'ids'      => array(),
			'total'    => 0,
			'page'     => 1,
			'per_page' => $limit,
		);
	}
	$args['collection']       = $collection;
	$rows                    = papelito_catalog_search_filter_campaign_prices(
		papelito_catalog_search_product_rows( $args ),
		$campaign_prices,
		$args
	);

	if ( 'novidades' === $collection ) {
		$rows = papelito_catalog_search_filter_new_arrivals( $rows, $args );
	}
	$tags_by_id              = papelito_catalog_search_tags_by_product( array_column( $rows, 'ID' ) );
	$matches                 = array();

	foreach ( $rows as $position => $row ) {
		$product_id = (int) ( $row['ID'] ?? 0 );
		$relevance  = papelito_catalog_search_relevance(
			$query,
			$terms,
			(string) ( $row['post_title'] ?? '' ),
			$tags_by_id[ $product_id ] ?? array()
		);

		if ( null !== $relevance ) {
			$matches[] = array(
				'id'        => $product_id,
				'relevance' => $relevance,
				'position'  => $position,
			);
		}
	}

	usort(
		$matches,
		static function ( array $left, array $right ): int {
			$by_relevance = $left['relevance'] <=> $right['relevance'];
			return 0 !== $by_relevance ? $by_relevance : $left['position'] <=> $right['position'];
		}
	);

	$total       = count( $matches );
	$total_pages = max( 1, (int) ceil( $total / $limit ) );
	$page        = min( $page, $total_pages );
	$ids         = array_column( array_slice( $matches, ( $page - 1 ) * $limit, $limit ), 'id' );

	return array(
		'ids'      => array_map( 'intval', $ids ),
		'total'    => $total,
		'page'     => $page,
		'per_page' => $limit,
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/catalog/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'busca'         => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'categories'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'subcategories'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'collection'     => array( 'type' => 'string', 'default' => 'todos', 'sanitize_callback' => 'sanitize_text_field' ),
					'preco_min' => array( 'type' => 'number', 'required' => false ),
					'preco_max' => array( 'type' => 'number', 'required' => false ),
					'page'      => array( 'type' => 'integer', 'default' => 1 ),
					'per_page'  => array( 'type' => 'integer', 'default' => 9 ),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! papelito_catalog_search_rate_limit() ) {
						return new WP_Error( 'papelito_rate_limited', 'Muitas buscas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
					}

					return new WP_REST_Response(
						papelito_catalog_search_products(
							array(
								'search'        => (string) $request->get_param( 'busca' ),
							'categories'    => explode( ',', (string) $request->get_param( 'categories' ) ),
							'subcategories' => explode( ',', (string) $request->get_param( 'subcategories' ) ),
							'collection'    => (string) $request->get_param( 'collection' ),
								'min_price'  => $request->get_param( 'preco_min' ),
								'max_price'  => $request->get_param( 'preco_max' ),
								'page'       => (int) $request->get_param( 'page' ),
								'per_page'   => (int) $request->get_param( 'per_page' ),
							)
						),
						200
					);
				},
			)
		);
	}
);
