<?php
/**
 * Vendor ativo do usuario customer.
 *
 * Cada customer possui um vendor "ativo" gravado em `wp_usermeta` como
 * `papelito_active_vendor_id`. O catalogo e o carrinho derivam dele.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PAPELITO_ACTIVE_VENDOR_META = 'papelito_active_vendor_id';

/**
 * Le o vendor ativo persistido do usuario.
 *
 * @param int $user_id Usuario.
 * @return int|null
 */
function papelito_get_active_vendor_id( int $user_id ): ?int {
	if ( $user_id <= 0 ) {
		return null;
	}

	$raw = get_user_meta( $user_id, PAPELITO_ACTIVE_VENDOR_META, true );
	$id  = is_numeric( $raw ) ? (int) $raw : 0;

	return $id > 0 ? $id : null;
}

/**
 * CEP normalizado da conta do usuario.
 *
 * @param int $user_id Usuario.
 * @return string CEP com 8 digitos, ou string vazia se ausente/invalido.
 */
function papelito_get_user_account_cep( int $user_id ): string {
	if ( $user_id <= 0 || ! function_exists( 'papelito_normalize_cep' ) ) {
		return '';
	}

	$raw = (string) get_user_meta( $user_id, 'cep', true );

	return papelito_normalize_cep( $raw );
}

/**
 * Indica se vendor existe, e seller aprovado e cobre o CEP do usuario.
 *
 * @param int    $vendor_id Vendor candidato.
 * @param string $user_cep  CEP normalizado do usuario (8 digitos).
 * @return true|WP_Error
 */
function papelito_validate_active_vendor( int $vendor_id, string $user_cep ) {
	if ( $vendor_id <= 0 ) {
		return new WP_Error(
			'papelito_active_vendor_invalid',
			'Vendor inválido.',
			array( 'status' => 400 )
		);
	}

	$user = get_userdata( $vendor_id );

	if ( ! $user instanceof WP_User || ! in_array( 'seller', (array) $user->roles, true ) ) {
		return new WP_Error(
			'papelito_active_vendor_not_seller',
			'Vendor inexistente ou não e seller.',
			array( 'status' => 404 )
		);
	}

	if ( '' === $user_cep || strlen( $user_cep ) !== 8 ) {
		return new WP_Error(
			'papelito_account_cep_missing',
			'CEP da conta ausente ou inválido.',
			array( 'status' => 409 )
		);
	}

	if ( ! function_exists( 'papelito_matching_vendor_ids' ) ) {
		return new WP_Error(
			'papelito_active_vendor_dependencies_missing',
			'Dependencias indisponíveis para validar cobertura.',
			array( 'status' => 500 )
		);
	}

	$covering = array_map( 'intval', papelito_matching_vendor_ids( (int) $user_cep ) );

	if ( ! in_array( $vendor_id, $covering, true ) ) {
		return new WP_Error(
			'papelito_active_vendor_out_of_coverage',
			'Vendor não atende o CEP da sua conta.',
			array( 'status' => 422 )
		);
	}

	return true;
}

/**
 * Persiste vendor ativo para o usuario.
 *
 * @param int  $user_id   Usuario customer.
 * @param int  $vendor_id Vendor candidato.
 * @param bool $validate  Se deve validar cobertura/aprovacao.
 * @return true|WP_Error
 */
function papelito_set_active_vendor( int $user_id, int $vendor_id, bool $validate = true ) {
	if ( $user_id <= 0 ) {
		return new WP_Error( 'papelito_active_vendor_user_invalid', 'Usuario invalido.', array( 'status' => 400 ) );
	}

	if ( $validate ) {
		$user_cep   = papelito_get_user_account_cep( $user_id );
		$validation = papelito_validate_active_vendor( $vendor_id, $user_cep );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
	}

	$prev_id = papelito_get_active_vendor_id( $user_id );

	update_user_meta( $user_id, PAPELITO_ACTIVE_VENDOR_META, (string) $vendor_id );

	if ( $prev_id !== $vendor_id ) {
		do_action( 'papelito_active_vendor_changed', $user_id, $prev_id, $vendor_id );
	}

	return true;
}

/**
 * Calcula o vendor mais proximo do CEP da conta do usuario.
 *
 * Considera apenas vendors aprovados com `cep_lat`/`cep_lng` cadastrados.
 *
 * @param int $user_id Usuario.
 * @return int|null
 */
function papelito_resolve_default_vendor_id( int $user_id ): ?int {
	$user_cep = papelito_get_user_account_cep( $user_id );

	if ( '' === $user_cep ) {
		return null;
	}

	if (
		! function_exists( 'papelito_matching_vendor_ids' )
		|| ! function_exists( 'papelito_geocode_cep' )
		|| ! function_exists( 'papelito_haversine_km' )
	) {
		return null;
	}

	$vendor_ids = array_map( 'intval', papelito_matching_vendor_ids( (int) $user_cep ) );

	if ( empty( $vendor_ids ) ) {
		return null;
	}

	$coords = papelito_geocode_cep( $user_cep );

	$best_id       = null;
	$best_distance = null;
	$best_stock    = -1;

	foreach ( $vendor_ids as $vendor_id ) {
		$user = get_userdata( $vendor_id );

		if ( ! $user instanceof WP_User || ! in_array( 'seller', (array) $user->roles, true ) ) {
			continue;
		}

		$distance = null;

		if ( null !== $coords ) {
			$vendor_lat = get_user_meta( $vendor_id, 'cep_lat', true );
			$vendor_lng = get_user_meta( $vendor_id, 'cep_lng', true );

			if ( is_numeric( $vendor_lat ) && is_numeric( $vendor_lng ) ) {
				$distance = papelito_haversine_km(
					(float) $coords['lat'],
					(float) $coords['lng'],
					(float) $vendor_lat,
					(float) $vendor_lng
				);
			}
		}

		$products_in_stock = function_exists( 'papelito_vendor_products_in_stock_count' )
			? papelito_vendor_products_in_stock_count( $vendor_id )
			: 0;

		if ( null === $best_id ) {
			$best_id       = $vendor_id;
			$best_distance = $distance;
			$best_stock    = $products_in_stock;
			continue;
		}

		if ( $products_in_stock > 0 && $best_stock <= 0 ) {
			$best_id       = $vendor_id;
			$best_distance = $distance;
			$best_stock    = $products_in_stock;
			continue;
		}

		if ( $products_in_stock <= 0 && $best_stock > 0 ) {
			continue;
		}

		if ( null === $distance ) {
			continue;
		}

		if ( null === $best_distance || $distance < $best_distance ) {
			$best_id       = $vendor_id;
			$best_distance = $distance;
			$best_stock    = $products_in_stock;
		}
	}

	return $best_id;
}

/**
 * Monta o payload publico de um vendor para o front.
 *
 * @param int    $vendor_id Vendor.
 * @param string $user_cep  CEP normalizado do usuario (8 digitos), opcional.
 * @return array<string,mixed>|null
 */
function papelito_vendor_payload( int $vendor_id, string $user_cep = '' ): ?array {
	$user = get_userdata( $vendor_id );

	if ( ! $user instanceof WP_User ) {
		return null;
	}

	$distance_km = null;

	if (
		'' !== $user_cep
		&& function_exists( 'papelito_geocode_cep' )
		&& function_exists( 'papelito_haversine_km' )
	) {
		$coords     = papelito_geocode_cep( $user_cep );
		$vendor_lat = get_user_meta( $vendor_id, 'cep_lat', true );
		$vendor_lng = get_user_meta( $vendor_id, 'cep_lng', true );

		if ( null !== $coords && is_numeric( $vendor_lat ) && is_numeric( $vendor_lng ) ) {
			$distance_km = round(
				papelito_haversine_km(
					(float) $coords['lat'],
					(float) $coords['lng'],
					(float) $vendor_lat,
					(float) $vendor_lng
				),
				2
			);
		}
	}

	$lead_time = (int) get_user_meta( $vendor_id, 'shipping_lead_time_days', true );

	return array(
		'vendor_id'      => $vendor_id,
		'store_name'     => (string) get_user_meta( $vendor_id, 'store_name', true ),
		'city'           => (string) get_user_meta( $vendor_id, 'city', true ),
		'state'          => (string) get_user_meta( $vendor_id, 'state', true ),
		'distance_km'    => $distance_km,
		'lead_time_days' => $lead_time > 0 ? $lead_time : 2,
	);
}

/**
 * Conta produtos com qty > 0 para o vendor.
 *
 * @param int $vendor_id Vendor.
 * @return int
 */
function papelito_vendor_products_in_stock_count( int $vendor_id ): int {
	global $wpdb;

	if ( ! function_exists( 'papelito_vendor_stock_table_names' ) ) {
		return 0;
	}

	$tables = papelito_vendor_stock_table_names();
	$table  = isset( $tables['stock'] ) ? (string) $tables['stock'] : '';

	if ( '' === $table ) {
		return 0;
	}

	$count = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . esc_sql( $table ) . ' WHERE vendor_id = %d AND qty > 0',
			$vendor_id
		)
	);

	return is_numeric( $count ) ? (int) $count : 0;
}

/**
 * Lista vendors aprovados que cobrem o CEP da conta do usuario.
 *
 * Inclui o vendor ativo e marca os flags `is_active` e `is_nearest`.
 *
 * @param int $user_id Usuario.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_available_vendors_for_user( int $user_id ) {
	$user_cep = papelito_get_user_account_cep( $user_id );

	if ( '' === $user_cep ) {
		return new WP_Error(
			'papelito_account_cep_missing',
			'CEP da conta ausente ou inválido.',
			array( 'status' => 409 )
		);
	}

	if ( ! function_exists( 'papelito_matching_vendor_ids' ) ) {
		return new WP_Error(
			'papelito_active_vendor_dependencies_missing',
			'Dependencias indisponíveis.',
			array( 'status' => 500 )
		);
	}

	$vendor_ids = array_map( 'intval', papelito_matching_vendor_ids( (int) $user_cep ) );

	if ( empty( $vendor_ids ) ) {
		return array();
	}

	$active_id  = papelito_get_active_vendor_id( $user_id );
	$nearest_id = papelito_resolve_default_vendor_id( $user_id );

	if ( null !== $active_id ) {
		$validation = papelito_validate_active_vendor( $active_id, $user_cep );

		if ( is_wp_error( $validation ) ) {
			$active_id = null;
		}
	}

	if ( null === $active_id ) {
		$active_id = $nearest_id;
	}

	$items = array();

	foreach ( $vendor_ids as $vendor_id ) {
		$user = get_userdata( $vendor_id );

		if ( ! $user instanceof WP_User || ! in_array( 'seller', (array) $user->roles, true ) ) {
			continue;
		}

		$payload = papelito_vendor_payload( $vendor_id, $user_cep );

		if ( null === $payload ) {
			continue;
		}

		$payload['products_in_stock'] = papelito_vendor_products_in_stock_count( $vendor_id );
		$payload['is_active']         = $active_id === $vendor_id;
		$payload['is_nearest']        = $nearest_id === $vendor_id;

		$items[] = $payload;
	}

	usort(
		$items,
		static function ( array $left, array $right ): int {
			$left_distance  = is_numeric( $left['distance_km'] ?? null ) ? (float) $left['distance_km'] : PHP_FLOAT_MAX;
			$right_distance = is_numeric( $right['distance_km'] ?? null ) ? (float) $right['distance_km'] : PHP_FLOAT_MAX;

			$compare = $left_distance <=> $right_distance;

			if ( 0 !== $compare ) {
				return $compare;
			}

			return ( (int) $left['vendor_id'] ) <=> ( (int) $right['vendor_id'] );
		}
	);

	return $items;
}

/**
 * Retorna o vendor ativo do usuario, calculando default lazy se nao houver persistido.
 *
 * @param int $user_id Usuario.
 * @return array<string,mixed>|WP_Error
 */
function papelito_get_active_vendor_response( int $user_id ) {
	$user_cep = papelito_get_user_account_cep( $user_id );

	if ( '' === $user_cep ) {
		return new WP_Error(
			'papelito_account_cep_missing',
			'CEP da conta ausente ou inválido.',
			array( 'status' => 409 )
		);
	}

	$active_id = papelito_get_active_vendor_id( $user_id );
	$is_default = false;

	if ( null !== $active_id ) {
		$validation = papelito_validate_active_vendor( $active_id, $user_cep );

		if ( is_wp_error( $validation ) ) {
			$active_id = null;
		}
	}

	if ( null === $active_id ) {
		$active_id  = papelito_resolve_default_vendor_id( $user_id );
		$is_default = true;
	}

	if ( null === $active_id ) {
		return new WP_Error(
			'papelito_active_vendor_none_available',
			'Nenhum vendor disponível para o CEP da sua conta.',
			array( 'status' => 404 )
		);
	}

	$payload = papelito_vendor_payload( $active_id, $user_cep );

	if ( null === $payload ) {
		return new WP_Error(
			'papelito_active_vendor_payload_failed',
			'Falha ao montar dados do vendor.',
			array( 'status' => 500 )
		);
	}

	$payload['is_default'] = $is_default;

	return $payload;
}

/**
 * Verifica autenticacao para os endpoints de vendor ativo.
 *
 * @return true|WP_Error
 */
function papelito_require_active_vendor_auth() {
	if ( is_user_logged_in() ) {
		return true;
	}

	return new WP_Error(
		'papelito_active_vendor_auth_required',
		'Usuário não autenticado.',
		array( 'status' => 401 )
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/profile/me/active-vendor',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => 'papelito_require_active_vendor_auth',
					'callback'            => static function (): WP_REST_Response|WP_Error {
						$result = papelito_get_active_vendor_response( get_current_user_id() );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( $result, 200 );
					},
				),
				array(
					'methods'             => 'PUT',
					'permission_callback' => 'papelito_require_active_vendor_auth',
					'args'                => array(
						'vendor_id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => static function ( $value ): bool {
								return is_numeric( $value ) && (int) $value > 0;
							},
						),
					),
					'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response|WP_Error {
						$user_id   = get_current_user_id();
						$vendor_id = (int) $request->get_param( 'vendor_id' );

						$result = papelito_set_active_vendor( $user_id, $vendor_id );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						$payload = papelito_get_active_vendor_response( $user_id );

						if ( is_wp_error( $payload ) ) {
							return $payload;
						}

						return new WP_REST_Response( $payload, 200 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/profile/me/available-vendors',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_require_active_vendor_auth',
				'callback'            => static function (): WP_REST_Response|WP_Error {
					$result = papelito_available_vendors_for_user( get_current_user_id() );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( $result, 200 );
				},
			)
		);
	}
);
