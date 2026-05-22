<?php
/**
 * Endpoints REST/GraphQL do plugin_papelito.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve sellers cuja faixa de CEP atende o CEP informado.
 *
 * @param string $cep CEP normalizado (apenas dígitos).
 * @return array<int, array{id:int, store_name:string}>
 */
function papelito_sellers_by_cep( string $cep ): array {
	$cep_n = preg_replace( '/\D/', '', $cep );

	if ( strlen( $cep_n ) !== 8 ) {
		return array();
	}

	if ( ! function_exists( 'papelito_matching_vendor_ids' ) ) {
		return array();
	}

	$vendor_ids = papelito_matching_vendor_ids( (int) $cep_n );

	return array_values(
		array_filter(
			array_map(
				static function ( int $vendor_id ): ?array {
					$user = get_userdata( $vendor_id );

					if ( ! $user instanceof WP_User ) {
						return null;
					}

					return array(
						'id'         => $user->ID,
						'store_name' => (string) get_user_meta( $user->ID, 'store_name', true ),
					);
				},
				$vendor_ids
			)
		)
	);
}

/**
 * Retorna vendors aprovados que cobrem o CEP e possuem estoque suficiente.
 *
 * @param string $cep             CEP em qualquer formato valido.
 * @param int    $product_id      Produto ou variacao consultada exatamente.
 * @param int    $qty             Quantidade minima em estoque.
 * @param int    $active_vendor   Vendor ativo do usuario (0 = nenhum). Quando > 0, marca `is_active` no payload.
 * @return array<int, array<string, int|float|string|bool|null>>|WP_Error
 */
function papelito_coverage_vendors( string $cep, int $product_id, int $qty, int $active_vendor = 0 ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'papelito_woocommerce_unavailable',
			'WooCommerce nao esta disponivel para consulta de cobertura.',
			array( 'status' => 500 )
		);
	}

	if ( ! wc_get_product( $product_id ) ) {
		return new WP_Error(
			'papelito_product_not_found',
			'Produto nao encontrado.',
			array( 'status' => 404 )
		);
	}

	if (
		! function_exists( 'papelito_normalize_cep' )
		|| ! function_exists( 'papelito_geocode_cep' )
		|| ! function_exists( 'papelito_haversine_km' )
		|| ! function_exists( 'papelito_matching_vendor_ids' )
		|| ! function_exists( 'papelito_vendors_with_stock' )
		|| ! function_exists( 'papelito_get_seller_application_status' )
	) {
		return new WP_Error(
			'papelito_coverage_dependencies_missing',
			'Dependencias de cobertura indisponiveis.',
			array( 'status' => 500 )
		);
	}

	$cep_n  = papelito_normalize_cep( $cep );
	$coords = papelito_geocode_cep( $cep_n );

	if ( null === $coords ) {
		return new WP_Error(
			'papelito_coverage_cep_geocode_failed',
			'Nao foi possivel geocodificar o CEP informado.',
			array( 'status' => 422 )
		);
	}

	$stock_rows = papelito_vendors_with_stock( $product_id );
	if ( empty( $stock_rows ) ) {
		return array();
	}

	$matching_vendor_ids = papelito_matching_vendor_ids( (int) $cep_n );
	if ( empty( $matching_vendor_ids ) ) {
		return array();
	}

	$matching_lookup = array_fill_keys( array_map( 'intval', $matching_vendor_ids ), true );
	$items           = array();

	foreach ( $stock_rows as $row ) {
		$vendor_id = isset( $row['vendor_id'] ) ? (int) $row['vendor_id'] : 0;
		$stock_qty = isset( $row['qty'] ) ? (int) $row['qty'] : 0;

		if ( $vendor_id <= 0 || $stock_qty < $qty || ! isset( $matching_lookup[ $vendor_id ] ) ) {
			continue;
		}

		$user = get_userdata( $vendor_id );
		if ( ! $user instanceof WP_User || ! in_array( 'seller', (array) $user->roles, true ) ) {
			continue;
		}

		if ( 'approved' !== papelito_get_seller_application_status( $vendor_id ) ) {
			continue;
		}

		$vendor_lat = get_user_meta( $vendor_id, 'cep_lat', true );
		$vendor_lng = get_user_meta( $vendor_id, 'cep_lng', true );
		if ( ! is_numeric( $vendor_lat ) || ! is_numeric( $vendor_lng ) ) {
			continue;
		}

		$distance_km = papelito_haversine_km(
			(float) $coords['lat'],
			(float) $coords['lng'],
			(float) $vendor_lat,
			(float) $vendor_lng
		);
		$lead_time_days = (int) get_user_meta( $vendor_id, 'shipping_lead_time_days', true );

		$items[] = array(
			'vendor_id'      => $vendor_id,
			'store_name'     => (string) get_user_meta( $vendor_id, 'store_name', true ),
			'city'           => (string) get_user_meta( $vendor_id, 'city', true ),
			'state'          => (string) get_user_meta( $vendor_id, 'state', true ),
			'distance_km'    => round( $distance_km, 2 ),
			'qty'            => $stock_qty,
			'lead_time_days' => $lead_time_days > 0 ? $lead_time_days : 2,
			'is_active'      => $active_vendor > 0 && $active_vendor === $vendor_id,
			'is_nearest'     => false,
		);
	}

	usort(
		$items,
		static function ( array $left, array $right ): int {
			$distance_compare = $left['distance_km'] <=> $right['distance_km'];

			if ( 0 !== $distance_compare ) {
				return $distance_compare;
			}

			return $left['vendor_id'] <=> $right['vendor_id'];
		}
	);

	if ( ! empty( $items ) ) {
		$items[0]['is_nearest'] = true;
	}

	return $items;
}

/**
 * Normaliza uma lista CSV de IDs de produtos.
 *
 * @param mixed $value Valor recebido da request.
 * @return array<int,int>
 */
function papelito_parse_product_ids_param( $value ): array {
	if ( is_array( $value ) ) {
		$raw_parts = $value;
	} else {
		$raw_parts = explode( ',', (string) $value );
	}

	$product_ids = array();

	foreach ( $raw_parts as $part ) {
		$product_id = absint( $part );

		if ( $product_id > 0 ) {
			$product_ids[] = $product_id;
		}
	}

	return array_values( array_unique( $product_ids ) );
}

/**
 * Converte um mapa de quantidades por produto.
 *
 * Aceita:
 * - array associativo `{ product_id: qty }`
 * - string `product_id:qty,product_id:qty`
 *
 * @param mixed $value Payload bruto.
 * @return array<int,int>
 */
function papelito_parse_product_qty_map( $value ): array {
	$qty_map = array();

	if ( is_array( $value ) ) {
		foreach ( $value as $product_id => $qty ) {
			if ( ! is_numeric( $product_id ) || ! is_numeric( $qty ) ) {
				continue;
			}

			$normalized_product_id = (int) $product_id;
			$normalized_qty        = max( 1, (int) $qty );

			if ( $normalized_product_id > 0 ) {
				$qty_map[ $normalized_product_id ] = $normalized_qty;
			}
		}

		return $qty_map;
	}

	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return array();
	}

	$pairs = array_map( 'trim', explode( ',', $value ) );

	foreach ( $pairs as $pair ) {
		if ( '' === $pair || false === strpos( $pair, ':' ) ) {
			continue;
		}

		list( $product_id, $qty ) = array_map( 'trim', explode( ':', $pair, 2 ) );

		if ( ! is_numeric( $product_id ) || ! is_numeric( $qty ) ) {
			continue;
		}

		$normalized_product_id = (int) $product_id;
		$normalized_qty        = max( 1, (int) $qty );

		if ( $normalized_product_id > 0 ) {
			$qty_map[ $normalized_product_id ] = $normalized_qty;
		}
	}

	return $qty_map;
}

/**
 * Monta a resposta de cobertura em lote para um CEP.
 *
 * Quando `$active_vendor` > 0, o filtro restringe a cobertura aos produtos
 * que aquele vendor especifico atende com estoque suficiente. `best_vendor`
 * passa a ser o vendor ativo (se presente) e `alternatives` contem os demais.
 *
 * @param string         $cep            CEP em qualquer formato valido.
 * @param array<int,int> $product_ids    Produtos consultados.
 * @param int            $qty            Quantidade minima default em estoque.
 * @param int            $active_vendor  Vendor ativo do usuario (0 = nenhum).
 * @param array<int,int> $qty_by_product Quantidades por produto.
 * @return array<string,array<string,mixed>>|WP_Error
 */
function papelito_coverage_products( string $cep, array $product_ids, int $qty, int $active_vendor = 0, array $qty_by_product = array() ) {
	$result = array();

	foreach ( $product_ids as $product_id ) {
		$product_qty = isset( $qty_by_product[ $product_id ] ) ? max( 1, (int) $qty_by_product[ $product_id ] ) : $qty;
		$vendors     = papelito_coverage_vendors( $cep, $product_id, $product_qty, $active_vendor );

		if ( is_wp_error( $vendors ) ) {
			if ( 'papelito_product_not_found' === $vendors->get_error_code() ) {
				$result[ (string) $product_id ] = array(
					'has_coverage' => false,
					'best_vendor'  => null,
					'alternatives' => array(),
				);
				continue;
			}

			return $vendors;
		}

		$best_vendor = $vendors[0] ?? null;
		$alternates  = array_slice( $vendors, 1 );

		if ( $active_vendor > 0 ) {
			$active_match = null;
			$others       = array();

			foreach ( $vendors as $vendor ) {
				if ( ( $vendor['vendor_id'] ?? 0 ) === $active_vendor ) {
					$active_match = $vendor;
				} else {
					$others[] = $vendor;
				}
			}

			if ( null !== $active_match ) {
				$best_vendor = $active_match;
				$alternates  = $others;
			} else {
				$best_vendor = null;
				$alternates  = array();
			}
		}

		$result[ (string) $product_id ] = array(
			'has_coverage' => null !== $best_vendor,
			'best_vendor'  => $best_vendor,
			'alternatives' => $alternates,
		);
	}

	return $result;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/cep',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'cep' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) && 1 === preg_match( '/^\d{5}-?\d{3}$/', $value );
						},
					),
				),
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$cep     = (string) $request->get_param( 'cep' );
					$cep_n   = preg_replace( '/\D/', '', $cep );
					$sellers = papelito_sellers_by_cep( $cep );
					$cookie  = array(
						'expires'  => time() + ( 7 * DAY_IN_SECONDS ),
						'path'     => COOKIEPATH ? COOKIEPATH : '/',
						'secure'   => is_ssl(),
						'httponly' => false,
						'samesite' => 'Lax',
					);

					if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
						$cookie['domain'] = COOKIE_DOMAIN;
					}

					setcookie( 'user_cep', $cep_n, $cookie );

					return new WP_REST_Response(
						array(
							'cep'     => $cep_n,
							'sellers' => $sellers,
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/sellers-by-cep',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'cep' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					return new WP_REST_Response(
						papelito_sellers_by_cep( (string) $request->get_param( 'cep' ) ),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/coverage',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'cep'        => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) && 1 === preg_match( '/^\d{5}-?\d{3}$/', $value );
						},
					),
					'product_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
					'qty'        => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return null === $value || ( is_numeric( $value ) && (int) $value > 0 );
						},
					),
					'vendor_id'  => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return null === $value || ( is_numeric( $value ) && (int) $value >= 0 );
						},
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_coverage_vendors(
						(string) $request->get_param( 'cep' ),
						(int) $request->get_param( 'product_id' ),
						max( 1, (int) $request->get_param( 'qty' ) ),
						max( 0, (int) $request->get_param( 'vendor_id' ) )
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
			'/coverage/products',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'cep'         => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) && 1 === preg_match( '/^\d{5}-?\d{3}$/', $value );
						},
					),
					'product_ids' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							if ( ! is_string( $value ) ) {
								return false;
							}

							$product_ids = papelito_parse_product_ids_param( $value );

							return count( $product_ids ) > 0 && count( $product_ids ) <= 120;
						},
					),
					'qty'         => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return null === $value || ( is_numeric( $value ) && (int) $value > 0 );
						},
					),
					'vendor_id'   => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return null === $value || ( is_numeric( $value ) && (int) $value >= 0 );
						},
					),
					'quantities'  => array(
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							if ( is_array( $value ) ) {
								return $value;
							}

							return sanitize_text_field( (string) $value );
						},
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$product_ids = papelito_parse_product_ids_param( $request->get_param( 'product_ids' ) );

					if ( empty( $product_ids ) ) {
						return new WP_Error(
							'papelito_invalid_product_ids',
							'Informe ao menos um produto valido.',
							array( 'status' => 400 )
						);
					}

					$result = papelito_coverage_products(
						(string) $request->get_param( 'cep' ),
						$product_ids,
						max( 1, (int) $request->get_param( 'qty' ) ),
						max( 0, (int) $request->get_param( 'vendor_id' ) ),
						papelito_parse_product_qty_map( $request->get_param( 'quantities' ) )
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

add_action(
	'graphql_register_types',
	static function (): void {
		if ( ! function_exists( 'register_graphql_object_type' ) || ! function_exists( 'register_graphql_field' ) ) {
			return;
		}

		register_graphql_object_type(
			'PapelitoSeller',
			array(
				'description' => 'Seller que atende um CEP',
				'fields'      => array(
					'id'        => array( 'type' => 'Int' ),
					'storeName' => array( 'type' => 'String' ),
				),
			)
		);

		register_graphql_field(
			'RootQuery',
			'sellersByCep',
			array(
				'type'        => array( 'list_of' => 'PapelitoSeller' ),
				'description' => 'Lista de sellers que atendem o CEP informado.',
				'args'        => array(
					'cep' => array( 'type' => array( 'non_null' => 'String' ) ),
				),
				'resolve'     => static function ( $root, array $args ): array {
					$rows = papelito_sellers_by_cep( (string) $args['cep'] );
					return array_map(
						static fn( array $row ): array => array(
							'id'        => $row['id'],
							'storeName' => $row['store_name'],
						),
						$rows
					);
				},
			)
		);
	}
);
