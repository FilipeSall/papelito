<?php
/**
 * Superfície REST da taxonomia própria de produtos.
 *
 * Duas audiências, dois contratos:
 *
 * - `GET /papelito/v1/categories` é público e cacheável — devolve só o que está
 *   ativo, e é o que substitui `CATEGORIES_NAV_ITEMS`, `FILTER_TABS` e os demais
 *   hardcodes do frontend.
 * - `papelito/v1/admin/categories*` exige `manage_options` e enxerga inativo,
 *   arquivado e contagem de produtos, porque é a tela que reclassifica.
 *
 * A regra de negócio inteira mora em `product_taxonomy.php`; aqui só há
 * roteamento, sanitização e forma de resposta.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_TAXONOMY_PUBLIC_TTL' ) ) {
	define( 'PAPELITO_TAXONOMY_PUBLIC_TTL', 10 * MINUTE_IN_SECONDS );
}

// ------------------------------------------------------------------
// Forma das respostas
// ------------------------------------------------------------------

/**
 * Subcategoria no formato público.
 *
 * `facet` vem junto de cada item, em vez de uma estrutura aninhada por faceta:
 * agrupar é trabalho da UI, e a lista plana mantém o contrato estável quando
 * uma faceta nova aparece.
 *
 * @param array<string,mixed> $subcategory Subcategoria normalizada.
 * @return array<string,mixed>
 */
function papelito_taxonomy_public_subcategory( array $subcategory ) {
	return array(
		'id'        => $subcategory['id'],
		'slug'      => $subcategory['slug'],
		'name'      => $subcategory['name'],
		'facet'     => $subcategory['facet'],
		'sortOrder' => $subcategory['sortOrder'],
	);
}

/**
 * Categoria no formato público, com as subcategorias ativas.
 *
 * @param array<string,mixed> $category Categoria normalizada.
 * @return array<string,mixed>
 */
function papelito_taxonomy_public_category( array $category ) {
	return array(
		'id'             => $category['id'],
		'slug'           => $category['slug'],
		'name'           => $category['name'],
		'description'    => $category['description'],
		'iconUrl'        => papelito_taxonomy_icon_url( $category['iconAttachmentId'] ),
		'seoTitle'       => $category['seoTitle'],
		'seoDescription' => $category['seoDescription'],
		'sortOrder'      => $category['sortOrder'],
		// Contagem de publicados: é o que alimenta as abas do catálogo. Antes vinha
		// do `count` do termo de `product_cat`, que somava raiz e filho e fazia a
		// aba TODOS mostrar 62 para 40 produtos.
		'productCount'   => (int) ( papelito_category_product_counts()[ $category['id'] ]['published'] ?? 0 ),
		'subcategories'  => array_map(
			'papelito_taxonomy_public_subcategory',
			papelito_subcategories_list( $category['id'] )
		),
	);
}

/**
 * Árvore pública de categorias, com cache versionado.
 *
 * O transient carrega a versão no nome: qualquer escrita incrementa
 * `papelito_product_taxonomy_version()` e a chave antiga deixa de ser lida, sem
 * invalidação explícita. Foi o transient global e não invalidado do filtro de
 * estoque que fez categoria nova sumir por 10 minutos.
 *
 * @return array<string,mixed>
 */
function papelito_taxonomy_public_payload() {
	$version = papelito_product_taxonomy_version();
	$key     = 'papelito_taxonomy_public_v' . $version;
	$cached  = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$payload = array(
		'version' => $version,
		'categories' => array_map( 'papelito_taxonomy_public_category', papelito_categories_list() ),
	);

	set_transient( $key, $payload, PAPELITO_TAXONOMY_PUBLIC_TTL );

	return $payload;
}

/**
 * Subcategoria no formato administrativo.
 *
 * Este é o único lugar que define o formato: leitura e escrita devolvem a mesma
 * coisa. Antes, mutação devolvia a linha crua, sem `productCount`, e quem
 * confiasse na resposta do `PUT` recebia um objeto incompleto.
 *
 * @param array<string,mixed> $subcategory Subcategoria normalizada.
 * @param array<int,int>      $sub_counts  Contagens por subcategoria.
 * @return array<string,mixed>
 */
function papelito_taxonomy_admin_subcategory( array $subcategory, array $sub_counts ) {
	return array(
		'id'           => $subcategory['id'],
		'categoryId'   => $subcategory['categoryId'],
		'slug'         => $subcategory['slug'],
		'name'         => $subcategory['name'],
		'facet'        => $subcategory['facet'],
		'description'  => $subcategory['description'],
		'sortOrder'    => $subcategory['sortOrder'],
		'isActive'     => $subcategory['isActive'],
		'archivedAt'   => $subcategory['archivedAt'],
		'productCount' => (int) ( $sub_counts[ $subcategory['id'] ] ?? 0 ),
	);
}

/**
 * Categoria no formato administrativo: enxerga inativo, arquivado e contagem.
 *
 * @param array<string,mixed>          $category         Categoria normalizada.
 * @param array<int,array<string,int>> $category_counts  Contagens por categoria.
 * @param array<int,int>               $sub_counts       Contagens por subcategoria.
 * @return array<string,mixed>
 */
function papelito_taxonomy_admin_category( array $category, array $category_counts, array $sub_counts ) {
	$subcategories = papelito_subcategories_list(
		$category['id'],
		array(
			'active_only'      => false,
			'include_archived' => true,
		)
	);

	return array(
		'id'               => $category['id'],
		'slug'             => $category['slug'],
		'name'             => $category['name'],
		'description'      => $category['description'],
		'iconAttachmentId' => $category['iconAttachmentId'],
		'iconUrl'          => papelito_taxonomy_icon_url( $category['iconAttachmentId'] ),
		'seoTitle'         => $category['seoTitle'],
		'seoDescription'   => $category['seoDescription'],
		'sortOrder'        => $category['sortOrder'],
		'isActive'         => $category['isActive'],
		'archivedAt'       => $category['archivedAt'],
		'productCount'     => array(
			'total'     => (int) ( $category_counts[ $category['id'] ]['total'] ?? 0 ),
			'published' => (int) ( $category_counts[ $category['id'] ]['published'] ?? 0 ),
		),
		'subcategories'    => array_map(
			static function ( array $subcategory ) use ( $sub_counts ) {
				return papelito_taxonomy_admin_subcategory( $subcategory, $sub_counts );
			},
			$subcategories
		),
	);
}

/**
 * Resposta de uma categoria só, no mesmo formato da árvore administrativa.
 *
 * @param int $category_id Id da categoria.
 * @return array<string,mixed>|null
 */
function papelito_taxonomy_admin_category_response( $category_id ) {
	$category = papelito_category_get( $category_id );

	if ( null === $category ) {
		return null;
	}

	return papelito_taxonomy_admin_category(
		$category,
		papelito_category_product_counts(),
		papelito_subcategory_product_counts( $category['id'] )
	);
}

/**
 * Resposta de uma subcategoria só, no mesmo formato da árvore administrativa.
 *
 * @param int $subcategory_id Id da subcategoria.
 * @return array<string,mixed>|null
 */
function papelito_taxonomy_admin_subcategory_response( $subcategory_id ) {
	$subcategory = papelito_subcategory_get( $subcategory_id );

	if ( null === $subcategory ) {
		return null;
	}

	return papelito_taxonomy_admin_subcategory(
		$subcategory,
		papelito_subcategory_product_counts( $subcategory['categoryId'] )
	);
}

/**
 * Lista de subcategorias de uma categoria no formato administrativo.
 *
 * @param int $category_id Id da categoria.
 * @return array<int,array<string,mixed>>
 */
function papelito_taxonomy_admin_subcategories_response( $category_id ) {
	$sub_counts = papelito_subcategory_product_counts( $category_id );

	return array_map(
		static function ( array $subcategory ) use ( $sub_counts ) {
			return papelito_taxonomy_admin_subcategory( $subcategory, $sub_counts );
		},
		papelito_subcategories_list(
			$category_id,
			array(
				'active_only'      => false,
				'include_archived' => true,
			)
		)
	);
}

/**
 * Árvore administrativa completa.
 *
 * @return array<string,mixed>
 */
function papelito_taxonomy_admin_payload() {
	$categories      = papelito_categories_list(
		array(
			'active_only'      => false,
			'include_archived' => true,
		)
	);
	$category_counts = papelito_category_product_counts();
	$sub_counts      = papelito_subcategory_product_counts();

	return array(
		'version'            => papelito_product_taxonomy_version(),
		// `collections` continua sendo a lista de slugs ativos que o editor de
		// produto sempre consumiu; `collectionsCatalog` é o objeto completo,
		// com nome, status e contagem, para a tela de administração.
		'collections'        => papelito_curated_collections(),
		'collectionsCatalog' => function_exists( 'papelito_collections_admin_list' ) ? papelito_collections_admin_list() : array(),
		'categories'         => array_map(
			static function ( array $category ) use ( $category_counts, $sub_counts ) {
				return papelito_taxonomy_admin_category( $category, $category_counts, $sub_counts );
			},
			$categories
		),
	);
}

// ------------------------------------------------------------------
// Helpers de request
// ------------------------------------------------------------------

/**
 * Diz se o request pediu exclusão permanente.
 *
 * `?force=true` é a convenção da própria REST do WordPress para separar lixeira
 * de exclusão definitiva (`/wp/v2/posts/{id}?force=true`). Sem o parâmetro, o
 * DELETE continua arquivando.
 *
 * @param WP_REST_Request $request Request.
 * @return bool
 */
function papelito_taxonomy_wants_force( WP_REST_Request $request ) {
	return rest_sanitize_boolean( $request->get_param( 'force' ) );
}

/**
 * Resposta das rotas administrativas de coleção.
 *
 * Toda escrita devolve o catálogo inteiro, como as rotas de categoria: a tela
 * recarrega de uma vez e a contagem nunca fica defasada em relação à lista.
 *
 * @return array<string,mixed>
 */
function papelito_collections_admin_payload() {
	return array(
		'version'     => papelito_product_taxonomy_version(),
		'collections' => papelito_collections_admin_list(),
	);
}

/**
 * Só administrador escreve taxonomia.
 *
 * @return bool
 */
function papelito_taxonomy_admin_permission() {
	return current_user_can( 'manage_options' );
}

/**
 * Extrai do request apenas as chaves conhecidas que vieram no corpo.
 *
 * Diferencia "não enviado" de "enviado vazio": só o que veio é atualizado.
 *
 * @param WP_REST_Request $request Request.
 * @param string[]        $keys    Chaves aceitas.
 * @return array<string,mixed>
 */
function papelito_taxonomy_pick( WP_REST_Request $request, array $keys ) {
	$body = $request->get_json_params();
	$body = is_array( $body ) ? $body : array();
	$data = array();

	foreach ( $keys as $key ) {
		if ( array_key_exists( $key, $body ) ) {
			$data[ $key ] = $body[ $key ];
		}
	}

	return $data;
}

/**
 * Lista de inteiros vinda do corpo do request.
 *
 * @param WP_REST_Request $request Request.
 * @param string          $key     Chave do corpo.
 * @return int[]
 */
function papelito_taxonomy_id_list( WP_REST_Request $request, $key ) {
	$body  = $request->get_json_params();
	$value = is_array( $body ) && isset( $body[ $key ] ) ? $body[ $key ] : array();

	if ( ! is_array( $value ) ) {
		return array();
	}

	return array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );
}

// ------------------------------------------------------------------
// Rotas
// ------------------------------------------------------------------

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_taxonomy_public_payload(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function (): WP_REST_Response {
						return new WP_REST_Response( papelito_taxonomy_admin_payload(), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$created = papelito_category_create(
							papelito_taxonomy_pick(
								$request,
								array( 'name', 'slug', 'description', 'iconAttachmentId', 'seoTitle', 'seoDescription', 'sortOrder', 'isActive' )
							)
						);

						if ( is_wp_error( $created ) ) {
							return $created;
						}

						return new WP_REST_Response( papelito_taxonomy_admin_category_response( $created ), 201 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/collections',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function (): WP_REST_Response {
						return new WP_REST_Response( papelito_collections_admin_payload(), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$created = papelito_collection_create(
							papelito_taxonomy_pick( $request, array( 'name', 'slug', 'description', 'sortOrder', 'isActive' ) )
						);

						if ( is_wp_error( $created ) ) {
							return $created;
						}

						return new WP_REST_Response( papelito_collections_admin_payload(), 201 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/collections/reorder',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_taxonomy_admin_permission',
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_collections_reorder( papelito_taxonomy_id_list( $request, 'ids' ) );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( papelito_collections_admin_payload(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/collections/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$result = papelito_collection_update(
							(int) $request['id'],
							papelito_taxonomy_pick( $request, array( 'name', 'slug', 'description', 'sortOrder', 'isActive' ) )
						);

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( papelito_collections_admin_payload(), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$collection_id = (int) $request['id'];
						$result        = papelito_taxonomy_wants_force( $request )
							? papelito_collection_delete_permanently( $collection_id )
							: papelito_collection_archive( $collection_id );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( papelito_collections_admin_payload(), 200 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/collections/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_taxonomy_admin_permission',
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_collection_restore( (int) $request['id'] );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( papelito_collections_admin_payload(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/categories/reorder',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_taxonomy_admin_permission',
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_categories_reorder( papelito_taxonomy_id_list( $request, 'ids' ) );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( papelito_taxonomy_admin_payload(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/categories/integrity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_taxonomy_admin_permission',
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_category_integrity_report(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$category_id = (int) $request['id'];
						$result      = papelito_category_update(
							$category_id,
							papelito_taxonomy_pick(
								$request,
								array( 'name', 'slug', 'description', 'iconAttachmentId', 'seoTitle', 'seoDescription', 'sortOrder', 'isActive' )
							)
						);

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( papelito_taxonomy_admin_category_response( $category_id ), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$category_id = (int) $request['id'];

						if ( papelito_taxonomy_wants_force( $request ) ) {
							$deleted = papelito_category_delete_permanently( $category_id );

							if ( is_wp_error( $deleted ) ) {
								return $deleted;
							}

							// A categoria deixou de existir: devolver o objeto dela
							// daria 404 no shape. O payload é a árvore restante.
							return new WP_REST_Response( papelito_taxonomy_admin_payload(), 200 );
						}

						$result = papelito_category_archive( $category_id );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( papelito_taxonomy_admin_category_response( $category_id ), 200 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/categories/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_taxonomy_admin_permission',
				'callback'            => static function ( WP_REST_Request $request ) {
					$category_id = (int) $request['id'];
					$result      = papelito_category_restore( $category_id );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( papelito_taxonomy_admin_category_response( $category_id ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/categories/(?P<id>\d+)/subcategories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$category_id = (int) $request['id'];

						if ( null === papelito_category_get( $category_id ) ) {
							return new WP_Error( 'papelito_category_not_found', 'Categoria não encontrada.', array( 'status' => 404 ) );
						}

						return new WP_REST_Response( papelito_taxonomy_admin_subcategories_response( $category_id ), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$data               = papelito_taxonomy_pick( $request, array( 'name', 'slug', 'facet', 'description', 'sortOrder', 'isActive' ) );
						$data['categoryId'] = (int) $request['id'];
						$created            = papelito_subcategory_create( $data );

						if ( is_wp_error( $created ) ) {
							return $created;
						}

						return new WP_REST_Response( papelito_taxonomy_admin_subcategory_response( $created ), 201 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/categories/(?P<id>\d+)/subcategories/reorder',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_taxonomy_admin_permission',
				'callback'            => static function ( WP_REST_Request $request ) {
					$category_id = (int) $request['id'];
					$result      = papelito_subcategories_reorder( $category_id, papelito_taxonomy_id_list( $request, 'ids' ) );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( papelito_taxonomy_admin_subcategories_response( $category_id ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/subcategories/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$subcategory_id = (int) $request['id'];
						$result         = papelito_subcategory_update(
							$subcategory_id,
							papelito_taxonomy_pick( $request, array( 'name', 'slug', 'facet', 'description', 'sortOrder', 'isActive' ) )
						);

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( papelito_taxonomy_admin_subcategory_response( $subcategory_id ), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$subcategory_id = (int) $request['id'];
						$result         = papelito_subcategory_archive( $subcategory_id );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( papelito_taxonomy_admin_subcategory_response( $subcategory_id ), 200 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/products/taxonomy',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_taxonomy_admin_permission',
				'args'                => array(
					'productIds' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$ids = array_values(
						array_unique(
							array_filter(
								array_map( 'intval', explode( ',', (string) $request->get_param( 'productIds' ) ) )
							)
						)
					);

					// Uma consulta por conjunto, nao por produto: a lista do admin traz 20
					// produtos por pagina e o painel nao pode virar N+1.
					$categories    = papelito_products_category_map( $ids );
					$subcategories = papelito_products_subcategory_map( $ids );
					$collections   = papelito_products_collection_map( $ids );
					$items         = array();

					foreach ( $ids as $product_id ) {
						$items[ (string) $product_id ] = array(
							'productId'     => $product_id,
							'category'      => $categories[ $product_id ] ?? null,
							'subcategories' => $subcategories[ $product_id ] ?? array(),
							'collections'   => $collections[ $product_id ] ?? array(),
						);
					}

					return new WP_REST_Response( array( 'items' => $items ), 200 );
				},
			)
		);
		register_rest_route(
			'papelito/v1/admin',
			'/products/(?P<id>\d+)/taxonomy',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$product_id = (int) $request['id'];
						if ( ! papelito_taxonomy_is_product( $product_id ) ) {
							return new WP_Error( 'papelito_product_not_found', 'Produto não encontrado.', array( 'status' => 404 ) );
						}

						return new WP_REST_Response(
							array(
								'productId'     => $product_id,
								'category'      => papelito_product_get_category( $product_id ),
								'subcategories' => papelito_product_get_subcategories( $product_id ),
								'collections'   => papelito_product_get_collections( $product_id ),
							),
							200
						);
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_taxonomy_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$product_id = (int) $request['id'];
						$body       = $request->get_json_params();
						$body       = is_array( $body ) ? $body : array();

						$data = array();

						if ( array_key_exists( 'categoryId', $body ) ) {
							$data['categoryId'] = (int) $body['categoryId'];
						}

						if ( array_key_exists( 'subcategoryIds', $body ) ) {
							$data['subcategoryIds'] = papelito_taxonomy_id_list( $request, 'subcategoryIds' );
						}

						if ( array_key_exists( 'collections', $body ) ) {
							$data['collections'] = is_array( $body['collections'] ) ? $body['collections'] : array();
						}

						$result = papelito_product_replace_taxonomy( $product_id, $data );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response(
							array(
								'productId'     => $product_id,
								'category'      => papelito_product_get_category( $product_id ),
								'subcategories' => papelito_product_get_subcategories( $product_id ),
								'collections'   => papelito_product_get_collections( $product_id ),
							),
							200
						);
					},
				),
			)
		);
	}
);
