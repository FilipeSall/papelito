<?php
/**
 * Endpoints REST de autenticação headless.
 *
 * Expõe POST /wp-json/papelito/v1/auth/google, /auth/register e /auth/register-seller.
 * Ambos retornam o mesmo par {authToken, refreshToken} usado pela mutation `login`
 * do plugin wp-graphql-jwt-authentication, mantendo compatibilidade total com o
 * Apollo Client do front Next.js.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'graphql_jwt_auth_expire',
	static function () {
		return 12 * HOUR_IN_SECONDS;
	}
);

/**
 * Valida formatos comuns de CEP usados no projeto.
 *
 * @param string $cep
 * @return bool
 */
function papelito_auth_is_valid_cep( string $cep ): bool {
	return 1 === preg_match( '/^\d{5}-?\d{3}$/', $cep ) || 1 === preg_match( '/^\d{2}\.\d{3}-\d{3}$/', $cep );
}

/**
 * Normaliza telefones brasileiros para dígitos locais.
 *
 * @param string $phone
 * @return string
 */
function papelito_auth_normalize_phone( string $phone ): string {
	$digits = preg_replace( '/\D+/', '', $phone );

	if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '55' ) ) {
		return substr( $digits, 2 );
	}

	if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '55' ) ) {
		return substr( $digits, 2 );
	}

	return $digits;
}

/**
 * Formata telefone brasileiro para o padrão visual usado pelo projeto.
 *
 * @param string $phone
 * @return string
 */
function papelito_auth_format_phone( string $phone ): string {
	$digits = papelito_auth_normalize_phone( $phone );

	if ( 11 === strlen( $digits ) ) {
		return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 5 ), substr( $digits, 7, 4 ) );
	}

	if ( 10 === strlen( $digits ) ) {
		return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 4 ), substr( $digits, 6, 4 ) );
	}

	return sanitize_text_field( $phone );
}

/**
 * Gera o par {authToken, refreshToken, user, profileComplete} para um WP_User.
 * Retorna WP_Error se o plugin de JWT não estiver disponível.
 *
 * @param WP_User $user
 * @return array|WP_Error
 */
function papelito_auth_build_token_response( WP_User $user ) {
	$jwt_class = '\\WPGraphQL\\JWT_Authentication\\Auth';

	if ( ! class_exists( $jwt_class ) ) {
		return new WP_Error(
			'papelito_jwt_unavailable',
			'Plugin wp-graphql-jwt-authentication não está ativo.',
			array( 'status' => 500 )
		);
	}

	$auth_token    = call_user_func( array( $jwt_class, 'get_token' ), $user, false );
	$refresh_token = call_user_func( array( $jwt_class, 'get_refresh_token' ), $user, false );

	if ( is_wp_error( $auth_token ) ) {
		return $auth_token;
	}

	if ( is_wp_error( $refresh_token ) ) {
		return $refresh_token;
	}

	$profile_complete = '1' === (string) get_user_meta( $user->ID, 'papelito_profile_complete', true );

	return array(
		'authToken'       => $auth_token,
		'refreshToken'    => $refresh_token,
		'user'            => array(
			'databaseId' => $user->ID,
			'email'      => $user->user_email,
			'firstName'  => (string) get_user_meta( $user->ID, 'first_name', true ),
			'lastName'   => (string) get_user_meta( $user->ID, 'last_name', true ),
		),
		'profileComplete' => $profile_complete,
	);
}

/**
 * Rate limit simples por IP. Bloqueia se exceder $max em $window segundos.
 *
 * @param string $bucket Identificador do endpoint (ex: 'google', 'register').
 * @param int    $max    Máximo de tentativas na janela.
 * @param int    $window Janela em segundos.
 * @return bool true se permitido, false se bloqueado.
 */
function papelito_auth_rate_limit( string $bucket, int $max = 20, int $window = 60 ): bool {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'papelito_auth_rl_' . $bucket . '_' . md5( $ip );

	$count = (int) get_transient( $key );

	if ( $count >= $max ) {
		return false;
	}

	set_transient( $key, $count + 1, $window );

	return true;
}

/**
 * Verifica um Google ID token via endpoint oficial tokeninfo. Sem deps externas.
 *
 * @param string $id_token
 * @return array|WP_Error Payload decodificado ou erro.
 */
function papelito_auth_verify_google_id_token( string $id_token ) {
	$response = wp_remote_get(
		'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ),
		array( 'timeout' => 5 )
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'papelito_google_unreachable',
			'Não foi possível validar o token com o Google.',
			array( 'status' => 502 )
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code || ! is_array( $body ) ) {
		return new WP_Error(
			'papelito_invalid_token',
			'Token Google inválido ou expirado.',
			array( 'status' => 401 )
		);
	}

	$expected_aud = defined( 'PAPELITO_GOOGLE_CLIENT_ID' ) ? PAPELITO_GOOGLE_CLIENT_ID : '';
	$received_aud = isset( $body['aud'] ) ? (string) $body['aud'] : '';

	if ( '' === $expected_aud || $expected_aud !== $received_aud ) {
		return new WP_Error(
			'papelito_invalid_token',
			'Audience do token Google não confere.',
			array( 'status' => 401 )
		);
	}

	if ( empty( $body['email'] ) || empty( $body['email_verified'] ) || 'true' !== (string) $body['email_verified'] ) {
		return new WP_Error(
			'papelito_unverified_email',
			'E-mail do Google não verificado.',
			array( 'status' => 401 )
		);
	}

	return $body;
}

/**
 * Encontra ou cria um WP_User a partir de um payload Google verificado.
 *
 * @param array $payload Payload do tokeninfo.
 * @return WP_User|WP_Error
 */
function papelito_auth_find_or_create_google_user( array $payload ) {
	$email = sanitize_email( (string) $payload['email'] );

	if ( '' === $email ) {
		return new WP_Error( 'papelito_invalid_email', 'E-mail inválido.', array( 'status' => 400 ) );
	}

	$existing_id = email_exists( $email );

	if ( $existing_id ) {
		$user = get_userdata( $existing_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'papelito_user_lookup_failed', 'Falha ao carregar usuário.', array( 'status' => 500 ) );
		}

		// Vincula google_sub se ainda não tiver (account linking implícito).
		if ( ! get_user_meta( $user->ID, 'google_sub', true ) && ! empty( $payload['sub'] ) ) {
			update_user_meta( $user->ID, 'google_sub', sanitize_text_field( (string) $payload['sub'] ) );
		}

		return $user;
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'first_name'   => isset( $payload['given_name'] ) ? sanitize_text_field( (string) $payload['given_name'] ) : '',
			'last_name'    => isset( $payload['family_name'] ) ? sanitize_text_field( (string) $payload['family_name'] ) : '',
			'display_name' => isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : $email,
			'role'         => 'customer',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( ! empty( $payload['sub'] ) ) {
		update_user_meta( $user_id, 'google_sub', sanitize_text_field( (string) $payload['sub'] ) );
	}

	update_user_meta( $user_id, 'papelito_profile_complete', '0' );

	// Garante compat com hooks WC. Lista vazia porque o usuário Google não passou pelo form.
	do_action( 'woocommerce_created_customer', $user_id, array(), false );

	$user = get_userdata( $user_id );

	return $user instanceof WP_User
		? $user
		: new WP_Error( 'papelito_user_lookup_failed', 'Falha ao carregar usuário recém-criado.', array( 'status' => 500 ) );
}

/**
 * Valida campos do registro (mesmas regras dos hooks de WooCommerce).
 *
 * @param array $data
 * @return WP_Error|null
 */
function papelito_auth_validate_register_payload( array $data ) {
	$errors = new WP_Error();

	$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
	if ( '' === $email || ! is_email( $email ) ) {
		$errors->add( 'email', 'E-mail inválido.' );
	}

	$password = isset( $data['password'] ) ? (string) $data['password'] : '';
	if ( strlen( $password ) < 8 ) {
		$errors->add( 'password', 'Senha precisa ter pelo menos 8 caracteres.' );
	}

	if ( empty( $data['first_name'] ) ) {
		$errors->add( 'first_name', 'Informe o seu nome.' );
	}

	if ( empty( $data['last_name'] ) ) {
		$errors->add( 'last_name', 'Informe o seu sobrenome.' );
	}

	$phone_digits = papelito_auth_normalize_phone( isset( $data['phone_number'] ) ? (string) $data['phone_number'] : '' );
	if ( ! in_array( strlen( $phone_digits ), array( 10, 11 ), true ) ) {
		$errors->add( 'phone_number', 'Telefone inválido. Formato esperado: (11) 99999-9999.' );
	}

	$cep = isset( $data['cep'] ) ? (string) $data['cep'] : '';
	if ( ! papelito_auth_is_valid_cep( $cep ) ) {
		$errors->add( 'cep', 'CEP inválido. Formato esperado: 01310-000.' );
	}

	// Campos de vendor (store_name, cnpj, state, city) são opcionais — só valida se fornecidos.
	if ( ! empty( $data['cnpj'] ) && ! preg_match( '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', (string) $data['cnpj'] ) ) {
		$errors->add( 'cnpj', 'CNPJ inválido. Formato esperado: 12.345.678/0001-90.' );
	}

	if ( ! empty( $data['state'] ) && defined( 'brazilian_states' ) && ! array_key_exists( (string) $data['state'], brazilian_states ) ) {
		$errors->add( 'state', 'Estado inválido.' );
	}

	return $errors->has_errors() ? $errors : null;
}

/**
 * Valida o payload de onboarding de seller.
 *
 * @param array $data
 * @return WP_Error|null
 */
function papelito_auth_validate_seller_register_payload( array $data ) {
	$errors = papelito_auth_validate_register_payload( $data );
	$errors = $errors instanceof WP_Error ? $errors : new WP_Error();

	$required_fields = array(
		'store_name' => 'Informe o nome da loja.',
		'cnpj'       => 'Informe um CNPJ válido.',
		'state'      => 'Informe o estado.',
		'city'       => 'Informe a cidade.',
		'instagram'  => 'Informe o Instagram da loja.',
	);

	foreach ( $required_fields as $field => $message ) {
		if ( empty( $data[ $field ] ) ) {
			$errors->add( $field, $message );
		}
	}

	$min_cep = isset( $data['min_cep'] ) ? (string) $data['min_cep'] : '';
	$max_cep = isset( $data['max_cep'] ) ? (string) $data['max_cep'] : '';

	if ( ! papelito_auth_is_valid_cep( $min_cep ) ) {
		$errors->add( 'min_cep', 'CEP mínimo inválido. Formato esperado: 01310-000.' );
	}

	if ( ! papelito_auth_is_valid_cep( $max_cep ) ) {
		$errors->add( 'max_cep', 'CEP máximo inválido. Formato esperado: 01310-000.' );
	}

	$min_cep_digits = preg_replace( '/\D+/', '', $min_cep );
	$max_cep_digits = preg_replace( '/\D+/', '', $max_cep );

	if ( '' !== $min_cep_digits && '' !== $max_cep_digits && (int) $min_cep_digits > (int) $max_cep_digits ) {
		$errors->add( 'max_cep', 'O CEP máximo deve ser maior ou igual ao CEP mínimo.' );
	}

	$has_sold = isset( $data['has_sold_papelito'] ) ? (string) $data['has_sold_papelito'] : '';
	if ( '' !== $has_sold && ! in_array( $has_sold, array( 'sim', 'nao' ), true ) ) {
		$errors->add( 'has_sold_papelito', 'Valor inválido para histórico de venda Papelito.' );
	}

	return $errors->has_errors() ? $errors : null;
}

/**
 * Cria um usuário a partir de um payload validado de cadastro.
 *
 * @param array $data
 * @return WP_User|WP_Error
 */
function papelito_auth_create_registered_user( array $data ) {
	$email = sanitize_email( (string) $data['email'] );

	if ( email_exists( $email ) ) {
		return new WP_Error(
			'papelito_email_exists',
			'Já existe uma conta com este e-mail.',
			array( 'status' => 409 )
		);
	}

	$user_id = wp_insert_user(
		array(
			'user_login' => $email,
			'user_email' => $email,
			'user_pass'  => (string) $data['password'],
			'first_name' => sanitize_text_field( (string) $data['first_name'] ),
			'last_name'  => sanitize_text_field( (string) $data['last_name'] ),
			'role'       => 'customer',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$meta_keys = array( 'store_name', 'phone_number', 'cnpj', 'instagram', 'state', 'city', 'cep' );

	foreach ( $meta_keys as $key ) {
		if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
			$value = 'phone_number' === $key
				? papelito_auth_format_phone( (string) $data[ $key ] )
				: sanitize_text_field( (string) $data[ $key ] );
			update_user_meta( $user_id, $key, $value );
		}
	}

	update_user_meta( $user_id, 'papelito_profile_complete', '1' );

	do_action( 'woocommerce_created_customer', $user_id, array(), false );

	$user = get_userdata( $user_id );

	return $user instanceof WP_User
		? $user
		: new WP_Error( 'papelito_user_lookup_failed', 'Falha ao carregar usuário recém-criado.', array( 'status' => 500 ) );
}

/**
 * Cria um seller a partir do payload validado do onboarding headless.
 *
 * @param array $data
 * @return WP_User|WP_Error
 */
function papelito_auth_create_registered_seller( array $data ) {
	$email = sanitize_email( (string) $data['email'] );

	if ( email_exists( $email ) ) {
		return new WP_Error(
			'papelito_email_exists',
			'Já existe uma conta com este e-mail.',
			array( 'status' => 409 )
		);
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => (string) $data['password'],
			'first_name'   => sanitize_text_field( (string) $data['first_name'] ),
			'last_name'    => sanitize_text_field( (string) $data['last_name'] ),
			'display_name' => sanitize_text_field( trim( (string) $data['first_name'] . ' ' . (string) $data['last_name'] ) ),
			'role'         => 'seller',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$meta_keys = array(
		'store_name',
		'phone_number',
		'cnpj',
		'instagram',
		'state',
		'city',
		'cep',
		'discovery_channel',
		'has_sold_papelito',
	);

	foreach ( $meta_keys as $key ) {
		if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
			$value = 'phone_number' === $key
				? papelito_auth_format_phone( (string) $data[ $key ] )
				: sanitize_text_field( (string) $data[ $key ] );
			update_user_meta( $user_id, $key, $value );
		}
	}

	if ( ! empty( $data['min_cep'] ) ) {
		update_user_meta( $user_id, 'min_cep', sanitize_text_field( (string) $data['min_cep'] ) );
	}

	if ( ! empty( $data['max_cep'] ) ) {
		update_user_meta( $user_id, 'max_cep', sanitize_text_field( (string) $data['max_cep'] ) );
	}

	update_user_meta( $user_id, 'papelito_profile_complete', '1' );

	$user = get_userdata( $user_id );

	return $user instanceof WP_User
		? $user
		: new WP_Error( 'papelito_user_lookup_failed', 'Falha ao carregar usuário seller recém-criado.', array( 'status' => 500 ) );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/auth/google',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'id_token' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! papelito_auth_rate_limit( 'google' ) ) {
						return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
					}

					$id_token = (string) $request->get_param( 'id_token' );

					if ( '' === trim( $id_token ) ) {
						return new WP_Error( 'papelito_missing_token', 'id_token ausente.', array( 'status' => 400 ) );
					}

					$payload = papelito_auth_verify_google_id_token( $id_token );

					if ( is_wp_error( $payload ) ) {
						return $payload;
					}

					$user = papelito_auth_find_or_create_google_user( $payload );

					if ( is_wp_error( $user ) ) {
						return $user;
					}

					$response = papelito_auth_build_token_response( $user );

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					return new WP_REST_Response( $response, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/auth/register',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! papelito_auth_rate_limit( 'register', 10, 60 ) ) {
						return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
					}

					$data = $request->get_json_params();

					if ( ! is_array( $data ) ) {
						$data = $request->get_params();
					}

					$validation = papelito_auth_validate_register_payload( (array) $data );

					if ( $validation instanceof WP_Error ) {
						$validation->add_data( array( 'status' => 422 ) );
						return $validation;
					}

					$user = papelito_auth_create_registered_user( (array) $data );

					if ( is_wp_error( $user ) ) {
						return $user;
					}

					$response = papelito_auth_build_token_response( $user );

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					return new WP_REST_Response( $response, 201 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/auth/register-seller',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! papelito_auth_rate_limit( 'register_seller', 10, 60 ) ) {
						return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
					}

					$data = $request->get_json_params();

					if ( ! is_array( $data ) ) {
						$data = $request->get_params();
					}

					$validation = papelito_auth_validate_seller_register_payload( (array) $data );

					if ( $validation instanceof WP_Error ) {
						$validation->add_data( array( 'status' => 422 ) );
						return $validation;
					}

					$user = papelito_auth_create_registered_seller( (array) $data );

					if ( is_wp_error( $user ) ) {
						return $user;
					}

					$response = papelito_auth_build_token_response( $user );

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					return new WP_REST_Response( $response, 201 );
				},
			)
		);
	}
);
