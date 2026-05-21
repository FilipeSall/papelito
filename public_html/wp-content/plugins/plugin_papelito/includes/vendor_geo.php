<?php
/**
 * Geocodificacao de CEP base de vendor (STEP 1 do playbook).
 *
 * Cadeia: BrasilAPI v2 -> ViaCEP + Nominatim. Cache via transient.
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_GEO_CACHE_PREFIX     = 'papelito_cep_geo_';
const PAPELITO_GEO_CACHE_HIT_TTL    = 30 * DAY_IN_SECONDS;
const PAPELITO_GEO_CACHE_MISS_TTL   = DAY_IN_SECONDS;
const PAPELITO_GEO_REMOTE_TIMEOUT   = 5;
const PAPELITO_GEO_NOMINATIM_AGENT  = 'PapelitoMarketplace/1.0 (+https://papelitobrasil.com)';

function papelito_normalize_cep( string $cep ): string {
	$digits = preg_replace( '/\D+/', '', $cep );
	return is_string( $digits ) && 8 === strlen( $digits ) ? $digits : '';
}

/**
 * Geocodifica um CEP retornando ['lat' => float, 'lng' => float] ou null.
 *
 * @param string $cep CEP em qualquer formato.
 */
function papelito_geocode_cep( string $cep ): ?array {
	$normalized = papelito_normalize_cep( $cep );
	if ( '' === $normalized ) {
		return null;
	}

	$transient_key = PAPELITO_GEO_CACHE_PREFIX . $normalized;
	$cached        = get_transient( $transient_key );

	if ( is_array( $cached ) ) {
		if ( isset( $cached['lat'], $cached['lng'] ) && is_numeric( $cached['lat'] ) && is_numeric( $cached['lng'] ) ) {
			return array(
				'lat' => (float) $cached['lat'],
				'lng' => (float) $cached['lng'],
			);
		}

		if ( array_key_exists( 'miss', $cached ) ) {
			return null;
		}
	}

	$coords = papelito_geocode_via_brasilapi( $normalized );

	if ( null === $coords ) {
		$address = papelito_geocode_fetch_viacep_address( $normalized );
		if ( null !== $address ) {
			$coords = papelito_geocode_via_nominatim( $address );
		}
	}

	if ( null === $coords ) {
		set_transient( $transient_key, array( 'miss' => true ), PAPELITO_GEO_CACHE_MISS_TTL );
		return null;
	}

	set_transient( $transient_key, $coords, PAPELITO_GEO_CACHE_HIT_TTL );
	return $coords;
}

/**
 * Aplica lat/lng ao user_meta do vendor a partir do CEP. Nao bloqueia em falha.
 */
function papelito_apply_vendor_geo( int $user_id, string $cep_raw ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}

	$coords = papelito_geocode_cep( $cep_raw );
	if ( null === $coords ) {
		delete_user_meta( $user_id, 'cep_lat' );
		delete_user_meta( $user_id, 'cep_lng' );
		return false;
	}

	update_user_meta( $user_id, 'cep_lat', (string) $coords['lat'] );
	update_user_meta( $user_id, 'cep_lng', (string) $coords['lng'] );
	return true;
}

function papelito_geocode_via_brasilapi( string $cep8 ): ?array {
	$response = wp_safe_remote_get(
		'https://brasilapi.com.br/api/cep/v2/' . rawurlencode( $cep8 ),
		array(
			'timeout' => PAPELITO_GEO_REMOTE_TIMEOUT,
			'headers' => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[papelito_geo] BrasilAPI erro: ' . $response->get_error_message() );
		return null;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) ) {
		return null;
	}

	$coords = $body['location']['coordinates'] ?? null;
	if ( ! is_array( $coords ) ) {
		return null;
	}

	$lat = isset( $coords['latitude'] ) ? trim( (string) $coords['latitude'] ) : '';
	$lng = isset( $coords['longitude'] ) ? trim( (string) $coords['longitude'] ) : '';

	if ( '' === $lat || '' === $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
		return null;
	}

	return array(
		'lat' => (float) $lat,
		'lng' => (float) $lng,
	);
}

function papelito_geocode_fetch_viacep_address( string $cep8 ): ?string {
	$response = wp_safe_remote_get(
		'https://viacep.com.br/ws/' . rawurlencode( $cep8 ) . '/json/',
		array(
			'timeout' => PAPELITO_GEO_REMOTE_TIMEOUT,
			'headers' => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[papelito_geo] ViaCEP erro: ' . $response->get_error_message() );
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || ! empty( $body['erro'] ) ) {
		return null;
	}

	$parts = array_filter(
		array(
			isset( $body['logradouro'] ) ? (string) $body['logradouro'] : '',
			isset( $body['bairro'] ) ? (string) $body['bairro'] : '',
			isset( $body['localidade'] ) ? (string) $body['localidade'] : '',
			isset( $body['uf'] ) ? (string) $body['uf'] : '',
		),
		static function ( $value ) {
			return '' !== trim( $value );
		}
	);

	if ( count( $parts ) < 2 ) {
		return null;
	}

	return implode( ', ', $parts );
}

function papelito_geocode_via_nominatim( string $address ): ?array {
	$url = add_query_arg(
		array(
			'format'         => 'json',
			'country'        => 'br',
			'limit'          => '1',
			'addressdetails' => '0',
			'q'              => $address,
		),
		'https://nominatim.openstreetmap.org/search'
	);

	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout' => PAPELITO_GEO_REMOTE_TIMEOUT,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => PAPELITO_GEO_NOMINATIM_AGENT,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[papelito_geo] Nominatim erro: ' . $response->get_error_message() );
		return null;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || empty( $body[0] ) || ! is_array( $body[0] ) ) {
		return null;
	}

	$first = $body[0];
	$lat   = isset( $first['lat'] ) ? trim( (string) $first['lat'] ) : '';
	$lng   = isset( $first['lon'] ) ? trim( (string) $first['lon'] ) : '';

	if ( '' === $lat || '' === $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
		return null;
	}

	return array(
		'lat' => (float) $lat,
		'lng' => (float) $lng,
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/admin/vendors/(?P<id>\d+)/geo',
			array(
				'methods'             => 'PUT',
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => 'papelito_admin_recompute_vendor_geo',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}
);

function papelito_admin_recompute_vendor_geo( WP_REST_Request $request ) {
	$user_id = (int) $request['id'];
	$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

	if ( ! $user instanceof WP_User ) {
		return new WP_REST_Response(
			array( 'ok' => false, 'message' => 'Vendor nao encontrado.' ),
			404
		);
	}

	if ( ! in_array( 'seller', (array) $user->roles, true ) ) {
		return new WP_REST_Response(
			array( 'ok' => false, 'message' => 'Usuario nao possui role de vendor.' ),
			409
		);
	}

	$cep_raw = (string) get_user_meta( $user_id, 'cep', true );
	if ( '' === papelito_normalize_cep( $cep_raw ) ) {
		return new WP_REST_Response(
			array( 'ok' => false, 'message' => 'Vendor sem CEP base cadastrado.' ),
			422
		);
	}

	$coords = papelito_geocode_cep( $cep_raw );
	if ( null === $coords ) {
		delete_user_meta( $user_id, 'cep_lat' );
		delete_user_meta( $user_id, 'cep_lng' );
		return new WP_REST_Response(
			array( 'ok' => false, 'message' => 'Nao foi possivel geocodificar o CEP. Tente novamente.' ),
			422
		);
	}

	update_user_meta( $user_id, 'cep_lat', (string) $coords['lat'] );
	update_user_meta( $user_id, 'cep_lng', (string) $coords['lng'] );

	return new WP_REST_Response(
		array(
			'ok'  => true,
			'lat' => $coords['lat'],
			'lng' => $coords['lng'],
		),
		200
	);
}
