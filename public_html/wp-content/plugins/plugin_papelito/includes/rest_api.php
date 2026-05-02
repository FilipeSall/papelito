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
