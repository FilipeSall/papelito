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
 * @param string $cep        CEP em qualquer formato valido.
 * @param int    $product_id Produto ou variacao consultada exatamente.
 * @param int    $qty        Quantidade minima em estoque.
 * @return array<int, array<string, int|float|string>>|WP_Error
 */
function papelito_coverage_vendors( string $cep, int $product_id, int $qty ) {
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

	return $items;
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
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_coverage_vendors(
						(string) $request->get_param( 'cep' ),
						(int) $request->get_param( 'product_id' ),
						max( 1, (int) $request->get_param( 'qty' ) )
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
