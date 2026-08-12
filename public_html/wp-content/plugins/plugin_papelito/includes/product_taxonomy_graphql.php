<?php
/**
 * Exposição da taxonomia própria de produtos no WPGraphQL.
 *
 * O catálogo público lê o produto por GraphQL, numa query só. Resolver a
 * categoria produto a produto transformaria uma listagem de 100 produtos em
 * 100+ consultas e derrubaria a invariante de performance da home — a mesma
 * causa-raiz que já tornou `coverage/products` lento.
 *
 * A defesa é um cache de request em duas partes:
 *
 * 1. `posts_results` apenas ANOTA os ids de produto que a query trouxe, sem
 *    consultar nada — custo zero quando o cliente não pede os campos novos.
 * 2. o primeiro resolver que precisar do dado carrega a lista completa dos ids anotados de
 *    uma vez, em três consultas, e serve o resto do request pela memória.
 *
 * Escrita na taxonomia limpa o cache pelo hook `papelito_product_taxonomy_changed`,
 * para o mesmo processo (WP-CLI, teste) não servir dado velho.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

// ------------------------------------------------------------------
// Cache de request
// ------------------------------------------------------------------

/**
 * Armazenamento do request. O objeto vive no static, então mutação persiste.
 *
 * @return object
 */
function papelito_taxonomy_store() {
	static $store = null;

	if ( null === $store ) {
		$store                = new stdClass();
		$store->known         = array();
		$store->loaded        = array();
		$store->category      = array();
		$store->subcategories = array();
		$store->collections   = array();
		$store->by_category   = array();
	}

	return $store;
}

/**
 * Descarta o cache de request.
 *
 * @return void
 */
function papelito_taxonomy_flush_request_cache() {
	$store                = papelito_taxonomy_store();
	$store->known         = array();
	$store->loaded        = array();
	$store->category      = array();
	$store->subcategories = array();
	$store->collections   = array();
	$store->by_category   = array();
}
add_action( 'papelito_product_taxonomy_changed', 'papelito_taxonomy_flush_request_cache' );

/**
 * Anota ids de produto candidatos, sem consultar nada.
 *
 * @param int[] $product_ids Ids observados.
 * @return void
 */
function papelito_taxonomy_note_products( array $product_ids ) {
	$store = papelito_taxonomy_store();

	foreach ( $product_ids as $product_id ) {
		$product_id = (int) $product_id;

		if ( $product_id > 0 ) {
			$store->known[ $product_id ] = true;
		}
	}
}

/**
 * Coleta os ids de produto de qualquer WP_Query, para o lote seguinte.
 *
 * @param mixed $posts Resultado da query.
 * @param mixed $query Instância de WP_Query.
 * @return mixed
 */
function papelito_taxonomy_collect_product_ids( $posts, $query = null ) {
	unset( $query );

	if ( ! is_array( $posts ) || count( $posts ) < 2 ) {
		return $posts;
	}

	$ids = array();

	foreach ( $posts as $post ) {
		if ( is_object( $post ) && isset( $post->post_type, $post->ID ) && 'product' === $post->post_type ) {
			$ids[] = (int) $post->ID;
		}
	}

	if ( ! empty( $ids ) ) {
		papelito_taxonomy_note_products( $ids );
	}

	return $posts;
}
add_filter( 'posts_results', 'papelito_taxonomy_collect_product_ids', 10, 2 );

/**
 * Impede que o GraphQL transforme produto publicado, porém não classificado,
 * em item de catálogo. O resolver devolvia `papelitoCategory: null`, mas a
 * conexão `products` ainda entregava o nó ao cliente.
 *
 * @param array<string,string> $clauses Cláusulas da consulta de posts.
 * @param WP_Query             $query   Consulta em execução.
 * @return array<string,string>
 */
function papelito_taxonomy_filter_graphql_products( $clauses, $query ) {
	if ( ! function_exists( 'is_graphql_request' ) || ! is_graphql_request() || ! $query instanceof WP_Query ) {
		return $clauses;
	}

	if ( current_user_can( 'manage_options' ) ) {
		return $clauses;
	}

	$post_type = $query->get( 'post_type' );
	$post_types = is_array( $post_type ) ? $post_type : array( $post_type );

	if ( ! in_array( 'product', $post_types, true ) ) {
		return $clauses;
	}

	global $wpdb;
	$clauses['where'] .= ' AND ' . papelito_taxonomy_classified_clause( $wpdb->posts . '.ID' );

	return $clauses;
}
add_filter( 'posts_clauses', 'papelito_taxonomy_filter_graphql_products', 10, 2 );

/**
 * Garante que o produto pedido e o conjunto já anotado estejam em memória.
 *
 * Carrega em três consultas, independentemente do tamanho do lote.
 *
 * @param int $product_id Produto pedido pelo resolver.
 * @return void
 */
function papelito_taxonomy_prime( $product_id ) {
	$store      = papelito_taxonomy_store();
	$product_id = (int) $product_id;

	if ( $product_id > 0 ) {
		$store->known[ $product_id ] = true;
	}

	$pending = array_values( array_diff( array_keys( $store->known ), array_keys( $store->loaded ) ) );

	if ( empty( $pending ) ) {
		return;
	}

	$categories    = papelito_products_category_map( $pending );
	$subcategories = papelito_products_subcategory_map( $pending );
	$collections   = papelito_products_collection_map( $pending );

	foreach ( $pending as $id ) {
		$store->loaded[ $id ]        = true;
		$store->category[ $id ]      = $categories[ $id ] ?? null;
		$store->subcategories[ $id ] = $subcategories[ $id ] ?? array();
		$store->collections[ $id ]   = $collections[ $id ] ?? array();
	}
}

/**
 * Categoria principal de um produto, servida pelo cache de request.
 *
 * @param int $product_id Id do produto.
 * @return array<string,mixed>|null
 */
function papelito_taxonomy_cached_category( $product_id ) {
	papelito_taxonomy_prime( $product_id );

	return papelito_taxonomy_store()->category[ (int) $product_id ] ?? null;
}

/**
 * Subcategorias de um produto, servidas pelo cache de request.
 *
 * @param int $product_id Id do produto.
 * @return array<int,array<string,mixed>>
 */
function papelito_taxonomy_cached_subcategories( $product_id ) {
	papelito_taxonomy_prime( $product_id );

	return papelito_taxonomy_store()->subcategories[ (int) $product_id ] ?? array();
}

/**
 * Coleções de um produto, servidas pelo cache de request.
 *
 * @param int $product_id Id do produto.
 * @return string[]
 */
function papelito_taxonomy_cached_collections( $product_id ) {
	papelito_taxonomy_prime( $product_id );

	return papelito_taxonomy_store()->collections[ (int) $product_id ] ?? array();
}

/**
 * Subcategorias ativas de uma categoria, memoizadas no request.
 *
 * @param int $category_id Id da categoria.
 * @return array<int,array<string,mixed>>
 */
function papelito_taxonomy_cached_category_subcategories( $category_id ) {
	$store       = papelito_taxonomy_store();
	$category_id = (int) $category_id;

	if ( ! array_key_exists( $category_id, $store->by_category ) ) {
		$store->by_category[ $category_id ] = papelito_subcategories_list( $category_id );
	}

	return $store->by_category[ $category_id ];
}

// ------------------------------------------------------------------
// Tipos e campos
// ------------------------------------------------------------------

/**
 * Lê uma chave de um item normalizado da taxonomia.
 *
 * @param mixed  $source   Item.
 * @param string $key      Chave.
 * @param mixed  $fallback Valor padrão.
 * @return mixed
 */
function papelito_taxonomy_graphql_value( $source, $key, $fallback = null ) {
	return is_array( $source ) && array_key_exists( $key, $source ) ? $source[ $key ] : $fallback;
}

/**
 * Registra o tipo GraphQL de subcategoria.
 *
 * @return void
 */
function papelito_taxonomy_graphql_register_subcategory_type(): void {
	register_graphql_object_type(
		'PapelitoProductSubcategory',
		array(
			'description' => 'Subcategoria da taxonomia própria da Papelito.',
			'fields'      => array(
				'databaseId' => array(
					'type'        => 'Int',
					'description' => 'Id da subcategoria.',
					'resolve'     => static function ( $source ) {
						return absint( papelito_taxonomy_graphql_value( $source, 'id', 0 ) );
					},
				),
				'categoryId' => array(
					'type'        => 'Int',
					'description' => 'Id da categoria à qual a subcategoria pertence.',
					'resolve'     => static function ( $source ) {
						return absint( papelito_taxonomy_graphql_value( $source, 'categoryId', 0 ) );
					},
				),
				'slug'       => array(
					'type'        => 'String',
					'description' => 'Slug, único dentro da categoria.',
					'resolve'     => static function ( $source ) {
						return (string) papelito_taxonomy_graphql_value( $source, 'slug', '' );
					},
				),
				'name'       => array(
					'type'        => 'String',
					'description' => 'Nome exibido.',
					'resolve'     => static function ( $source ) {
						return (string) papelito_taxonomy_graphql_value( $source, 'name', '' );
					},
				),
				'facet'      => array(
					'type'        => 'String',
					'description' => 'Eixo de classificação (material, formato, tamanho, tipo).',
					'resolve'     => static function ( $source ) {
						return (string) papelito_taxonomy_graphql_value( $source, 'facet', 'geral' );
					},
				),
				'sortOrder'  => array(
					'type'        => 'Int',
					'description' => 'Posição dentro da faceta.',
					'resolve'     => static function ( $source ) {
						return absint( papelito_taxonomy_graphql_value( $source, 'sortOrder', 0 ) );
					},
				),
			),
		)
	);
}

/**
 * Registra o tipo GraphQL de categoria.
 *
 * @return void
 */
function papelito_taxonomy_graphql_register_category_type(): void {
	register_graphql_object_type(
		'PapelitoProductCategory',
		array(
			'description' => 'Categoria principal da taxonomia própria da Papelito.',
			'fields'      => array(
				'databaseId'     => array(
					'type'        => 'Int',
					'description' => 'Id da categoria.',
					'resolve'     => static function ( $source ) {
						return absint( papelito_taxonomy_graphql_value( $source, 'id', 0 ) );
					},
				),
				'slug'           => array(
					'type'        => 'String',
					'description' => 'Slug único da categoria.',
					'resolve'     => static function ( $source ) {
						return (string) papelito_taxonomy_graphql_value( $source, 'slug', '' );
					},
				),
				'name'           => array(
					'type'        => 'String',
					'description' => 'Nome exibido.',
					'resolve'     => static function ( $source ) {
						return (string) papelito_taxonomy_graphql_value( $source, 'name', '' );
					},
				),
				'description'    => array(
					'type'        => 'String',
					'description' => 'Descrição da categoria.',
					'resolve'     => static function ( $source ) {
						return (string) papelito_taxonomy_graphql_value( $source, 'description', '' );
					},
				),
				'iconUrl'        => array(
					'type'        => 'String',
					'description' => 'URL do ícone da categoria.',
					'resolve'     => static function ( $source ) {
						return papelito_taxonomy_icon_url( papelito_taxonomy_graphql_value( $source, 'iconAttachmentId', 0 ) );
					},
				),
				'seoTitle'       => array(
					'type'        => 'String',
					'description' => 'Título de SEO.',
					'resolve'     => static function ( $source ) {
						return (string) papelito_taxonomy_graphql_value( $source, 'seoTitle', '' );
					},
				),
				'seoDescription' => array(
					'type'        => 'String',
					'description' => 'Descrição de SEO.',
					'resolve'     => static function ( $source ) {
						return (string) papelito_taxonomy_graphql_value( $source, 'seoDescription', '' );
					},
				),
				'sortOrder'      => array(
					'type'        => 'Int',
					'description' => 'Posição na navegação.',
					'resolve'     => static function ( $source ) {
						return absint( papelito_taxonomy_graphql_value( $source, 'sortOrder', 0 ) );
					},
				),
				'subcategories'  => array(
					'type'        => array( 'list_of' => 'PapelitoProductSubcategory' ),
					'description' => 'Subcategorias ativas da categoria.',
					'resolve'     => static function ( $source ) {
						return papelito_taxonomy_cached_category_subcategories(
							absint( papelito_taxonomy_graphql_value( $source, 'id', 0 ) )
						);
					},
				),
			),
		)
	);
}

/**
 * Registra os campos de taxonomia no produto GraphQL.
 *
 * @return void
 */
function papelito_taxonomy_graphql_register_product_fields(): void {
	register_graphql_field(
		'Product',
		'papelitoCategory',
		array(
			'type'        => 'PapelitoProductCategory',
			'description' => 'Categoria principal da Papelito. Nulo enquanto o produto não estiver classificado.',
			'resolve'     => static function ( $product ) {
				$product_id = papelito_resolve_graphql_product_id( $product );

				return $product_id > 0 ? papelito_taxonomy_cached_category( $product_id ) : null;
			},
		)
	);

	register_graphql_field(
		'Product',
		'papelitoSubcategories',
		array(
			'type'        => array( 'list_of' => 'PapelitoProductSubcategory' ),
			'description' => 'Subcategorias da Papelito, todas pertencentes à categoria principal.',
			'resolve'     => static function ( $product ) {
				$product_id = papelito_resolve_graphql_product_id( $product );

				return $product_id > 0 ? papelito_taxonomy_cached_subcategories( $product_id ) : array();
			},
		)
	);

	register_graphql_field(
		'Product',
		'papelitoCollections',
		array(
			'type'        => array( 'list_of' => 'String' ),
			'description' => 'Coleções curadas do produto (premium, kits).',
			'resolve'     => static function ( $product ) {
				$product_id = papelito_resolve_graphql_product_id( $product );

				return $product_id > 0 ? papelito_taxonomy_cached_collections( $product_id ) : array();
			},
		)
	);
}

/**
 * Registra a árvore de categorias na raiz do GraphQL.
 *
 * @return void
 */
function papelito_taxonomy_graphql_register_root_query_field(): void {
	register_graphql_field(
		'RootQuery',
		'papelitoCategories',
		array(
			'type'        => array( 'list_of' => 'PapelitoProductCategory' ),
			'description' => 'Árvore de categorias ativas da Papelito, na ordem definida no admin.',
			'resolve'     => static function () {
				return papelito_categories_list();
			},
		)
	);
}

add_action(
	'graphql_register_types',
	static function (): void {
		if ( ! function_exists( 'register_graphql_object_type' ) || ! function_exists( 'register_graphql_field' ) ) {
			return;
		}

		papelito_taxonomy_graphql_register_subcategory_type();
		papelito_taxonomy_graphql_register_category_type();
		papelito_taxonomy_graphql_register_product_fields();
		papelito_taxonomy_graphql_register_root_query_field();
	}
);
