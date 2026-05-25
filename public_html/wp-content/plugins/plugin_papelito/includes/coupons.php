<?php
/**
 * Engine de cupons do marketplace Papelito.
 *
 * Reaproveita o CPT shop_coupon nativo do WooCommerce e adiciona três
 * metas papelito: role, vendor_ids, product_ids. Expõe CRUD admin via
 * REST e endpoint público autenticado para aplicar cupom no carrinho.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_COUPON_META_ROLE         = '_papelito_coupon_role';
const PAPELITO_COUPON_META_VENDOR_IDS   = '_papelito_coupon_vendor_ids';
const PAPELITO_COUPON_META_PRODUCT_IDS  = '_papelito_coupon_product_ids';
const PAPELITO_COUPON_SUPPORTED_TYPES   = array( 'percent', 'fixed_cart' );
const PAPELITO_COUPON_ALLOWED_ROLES     = array( 'customer', 'any' );

/**
 * Normaliza lista de IDs (vendor_ids / product_ids).
 *
 * @param mixed $value Valor cru recebido.
 * @return array<int,int>
 */
function papelito_coupon_normalize_ids( $value ): array {
	$ids = is_array( $value ) ? $value : array();

	return array_values(
		array_unique(
			array_filter(
				array_map( 'absint', $ids ),
				static fn( int $id ): bool => $id > 0
			)
		)
	);
}

/**
 * Mapeia um cupom para a representação REST.
 *
 * @param int $coupon_id ID do post shop_coupon.
 * @return array<string,mixed>|null
 */
function papelito_coupon_map_to_response( int $coupon_id ): ?array {
	if ( $coupon_id <= 0 ) {
		return null;
	}

	$post = get_post( $coupon_id );
	if ( ! $post || 'shop_coupon' !== $post->post_type ) {
		return null;
	}

	$coupon       = new WC_Coupon( $coupon_id );
	$date_expires = $coupon->get_date_expires();
	$role         = (string) get_post_meta( $coupon_id, PAPELITO_COUPON_META_ROLE, true );
	$vendor_ids   = get_post_meta( $coupon_id, PAPELITO_COUPON_META_VENDOR_IDS, true );
	$product_ids  = get_post_meta( $coupon_id, PAPELITO_COUPON_META_PRODUCT_IDS, true );

	return array(
		'id'                   => $coupon_id,
		'code'                 => (string) $coupon->get_code(),
		'status'               => (string) $post->post_status,
		'discount_type'        => (string) $coupon->get_discount_type(),
		'amount'               => (float) $coupon->get_amount(),
		'date_expires'         => $date_expires instanceof WC_DateTime ? $date_expires->date( DATE_ATOM ) : null,
		'usage_limit'          => (int) $coupon->get_usage_limit(),
		'usage_limit_per_user' => (int) $coupon->get_usage_limit_per_user(),
		'minimum_amount'       => (float) $coupon->get_minimum_amount(),
		'usage_count'          => (int) $coupon->get_usage_count(),
		'role'                 => in_array( $role, PAPELITO_COUPON_ALLOWED_ROLES, true ) ? $role : 'customer',
		'vendor_ids'           => papelito_coupon_normalize_ids( $vendor_ids ),
		'product_ids'          => papelito_coupon_normalize_ids( $product_ids ),
	);
}

/**
 * Valida payload de criação/edição.
 *
 * @param array<string,mixed> $input    Dados do request.
 * @param int|null            $coupon_id ID do cupom existente em update.
 * @return array<string,mixed>|WP_Error Dados normalizados ou erro.
 */
function papelito_coupon_validate_input( array $input, ?int $coupon_id = null ) {
	$code = isset( $input['code'] ) ? strtoupper( trim( sanitize_text_field( (string) $input['code'] ) ) ) : '';

	if ( '' === $code || strlen( $code ) > 50 ) {
		return new WP_Error(
			'papelito_coupon_invalid_code',
			'Código do cupom inválido. Use até 50 caracteres alfanuméricos.',
			array( 'status' => 422 )
		);
	}

	if ( ! preg_match( '/^[A-Z0-9_-]+$/', $code ) ) {
		return new WP_Error(
			'papelito_coupon_invalid_code',
			'Use apenas letras, números, hífen ou underscore no código.',
			array( 'status' => 422 )
		);
	}

	$existing_id = (int) wc_get_coupon_id_by_code( $code );
	if ( $existing_id > 0 && $existing_id !== ( $coupon_id ?? 0 ) ) {
		return new WP_Error(
			'papelito_coupon_code_taken',
			'Já existe um cupom com esse código.',
			array( 'status' => 409 )
		);
	}

	$discount_type = isset( $input['discount_type'] ) ? sanitize_key( (string) $input['discount_type'] ) : '';
	if ( ! in_array( $discount_type, PAPELITO_COUPON_SUPPORTED_TYPES, true ) ) {
		return new WP_Error(
			'papelito_coupon_invalid_type',
			'Tipo de desconto inválido. Use "percent" ou "fixed_cart".',
			array( 'status' => 422 )
		);
	}

	$amount = isset( $input['amount'] ) ? (float) $input['amount'] : 0.0;
	if ( $amount <= 0 ) {
		return new WP_Error(
			'papelito_coupon_invalid_amount',
			'O valor do desconto precisa ser maior que zero.',
			array( 'status' => 422 )
		);
	}

	if ( 'percent' === $discount_type && $amount > 100 ) {
		return new WP_Error(
			'papelito_coupon_invalid_amount',
			'Desconto percentual não pode ultrapassar 100%.',
			array( 'status' => 422 )
		);
	}

	$date_expires_ts = null;
	if ( isset( $input['date_expires'] ) && '' !== trim( (string) $input['date_expires'] ) ) {
		$date_input = trim( (string) $input['date_expires'] );
		$timezone   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date       = DateTimeImmutable::createFromFormat( '!Y-m-d', $date_input, $timezone );
		$errors     = DateTimeImmutable::getLastErrors();

		if (
			false === $date ||
			( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) )
		) {
			return new WP_Error(
				'papelito_coupon_invalid_date',
				'Data de validade inválida.',
				array( 'status' => 422 )
			);
		}

		$date_expires_ts = $date->setTime( 23, 59, 59 )->getTimestamp();
	}

	$usage_limit          = isset( $input['usage_limit'] ) ? max( 0, (int) $input['usage_limit'] ) : 0;
	$usage_limit_per_user = isset( $input['usage_limit_per_user'] ) ? max( 0, (int) $input['usage_limit_per_user'] ) : 0;
	$minimum_amount       = isset( $input['minimum_amount'] ) ? max( 0.0, (float) $input['minimum_amount'] ) : 0.0;

	$role = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : 'customer';
	if ( ! in_array( $role, PAPELITO_COUPON_ALLOWED_ROLES, true ) ) {
		$role = 'customer';
	}

	$vendor_ids  = papelito_coupon_normalize_ids( $input['vendor_ids'] ?? array() );
	$product_ids = papelito_coupon_normalize_ids( $input['product_ids'] ?? array() );

	if ( ! empty( $product_ids ) ) {
		foreach ( $product_ids as $pid ) {
			$product_post = get_post( $pid );
			if ( ! $product_post || 'product' !== $product_post->post_type ) {
				return new WP_Error(
					'papelito_coupon_invalid_product',
					sprintf( 'Produto %d não encontrado.', $pid ),
					array( 'status' => 422 )
				);
			}
		}
	}

	if ( ! empty( $vendor_ids ) ) {
		foreach ( $vendor_ids as $vid ) {
			$vendor_user = get_user_by( 'id', $vid );
			if ( ! $vendor_user instanceof WP_User || ! in_array( 'seller', (array) $vendor_user->roles, true ) ) {
				return new WP_Error(
					'papelito_coupon_invalid_vendor',
					sprintf( 'Vendor %d inválido.', $vid ),
					array( 'status' => 422 )
				);
			}
		}
	}

	$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'publish';
	if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
		$status = 'publish';
	}

	return array(
		'code'                 => $code,
		'discount_type'        => $discount_type,
		'amount'               => $amount,
		'date_expires_ts'      => $date_expires_ts,
		'usage_limit'          => $usage_limit,
		'usage_limit_per_user' => $usage_limit_per_user,
		'minimum_amount'       => $minimum_amount,
		'role'                 => $role,
		'vendor_ids'           => $vendor_ids,
		'product_ids'          => $product_ids,
		'status'               => $status,
	);
}

/**
 * Persiste um cupom novo ou existente e emite o evento de promoção quando aplicável.
 *
 * @param array<string,mixed> $data      Dados normalizados (output de papelito_coupon_validate_input).
 * @param int|null            $coupon_id ID existente em update.
 * @return int|WP_Error
 */
function papelito_coupon_persist( array $data, ?int $coupon_id = null ) {
	$was_publish_before = false;
	if ( $coupon_id ) {
		$existing_post = get_post( $coupon_id );
		if ( ! $existing_post || 'shop_coupon' !== $existing_post->post_type ) {
			return new WP_Error(
				'papelito_coupon_not_found',
				'Cupom não encontrado.',
				array( 'status' => 404 )
			);
		}
		$was_publish_before = 'publish' === $existing_post->post_status;
	}

	$coupon = $coupon_id ? new WC_Coupon( $coupon_id ) : new WC_Coupon();
	$coupon->set_code( $data['code'] );
	$coupon->set_discount_type( $data['discount_type'] );
	$coupon->set_amount( $data['amount'] );
	$coupon->set_date_expires( $data['date_expires_ts'] );
	$coupon->set_usage_limit( $data['usage_limit'] );
	$coupon->set_usage_limit_per_user( $data['usage_limit_per_user'] );
	$coupon->set_minimum_amount( $data['minimum_amount'] );
	$coupon->set_status( $data['status'] );

	$saved_id = $coupon->save();
	if ( ! $saved_id ) {
		return new WP_Error(
			'papelito_coupon_save_failed',
			'Falha ao salvar cupom.',
			array( 'status' => 500 )
		);
	}

	update_post_meta( $saved_id, PAPELITO_COUPON_META_ROLE, $data['role'] );
	update_post_meta( $saved_id, PAPELITO_COUPON_META_VENDOR_IDS, $data['vendor_ids'] );
	update_post_meta( $saved_id, PAPELITO_COUPON_META_PRODUCT_IDS, $data['product_ids'] );

	if ( 'publish' === $data['status'] && ! $was_publish_before && ! empty( $data['product_ids'] ) ) {
		foreach ( $data['product_ids'] as $product_id ) {
			do_action(
				'papelito_product_on_promo',
				(int) $product_id,
				array(
					'promo_type'  => 'coupon',
					'promo_label' => $data['code'],
				)
			);
		}
	}

	return (int) $saved_id;
}

/**
 * Valida e calcula desconto para um pedido de apply.
 *
 * @param string                       $code       Código informado pelo cliente.
 * @param array<int,array<string,mixed>> $cart_items Linhas do carrinho atual.
 * @param int                          $user_id    Usuário logado.
 * @return array<string,mixed>|WP_Error
 */
function papelito_coupon_apply_resolve( string $code, array $cart_items, int $user_id ) {
	$code = strtoupper( trim( $code ) );
	if ( '' === $code ) {
		return new WP_Error(
			'papelito_coupon_missing_code',
			'Informe um cupom.',
			array( 'status' => 422 )
		);
	}

	$coupon_id = (int) wc_get_coupon_id_by_code( $code );
	if ( $coupon_id <= 0 ) {
		return new WP_Error(
			'papelito_coupon_not_found',
			'Cupom não encontrado.',
			array( 'status' => 404 )
		);
	}

	$post = get_post( $coupon_id );
	if ( ! $post || 'shop_coupon' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error(
			'papelito_coupon_not_found',
			'Cupom não encontrado.',
			array( 'status' => 404 )
		);
	}

	$coupon       = new WC_Coupon( $coupon_id );
	$date_expires = $coupon->get_date_expires();
	if ( $date_expires instanceof WC_DateTime && $date_expires->getTimestamp() < time() ) {
		return new WP_Error(
			'papelito_coupon_expired',
			'Este cupom expirou.',
			array( 'status' => 410 )
		);
	}

	$allowed_role = (string) get_post_meta( $coupon_id, PAPELITO_COUPON_META_ROLE, true );
	if ( ! in_array( $allowed_role, PAPELITO_COUPON_ALLOWED_ROLES, true ) ) {
		$allowed_role = 'customer';
	}

	if ( 'customer' === $allowed_role ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User || ! in_array( 'customer', (array) $user->roles, true ) ) {
			return new WP_Error(
				'papelito_coupon_role_restricted',
				'Este cupom é exclusivo para consumidores finais.',
				array( 'status' => 403 )
			);
		}
	}

	$usage_limit = (int) $coupon->get_usage_limit();
	$usage_count = (int) $coupon->get_usage_count();
	if ( $usage_limit > 0 && $usage_count >= $usage_limit ) {
		return new WP_Error(
			'papelito_coupon_usage_limit_total',
			'Este cupom atingiu o limite total de uso.',
			array( 'status' => 409 )
		);
	}

	$limit_per_user = (int) $coupon->get_usage_limit_per_user();
	if ( $limit_per_user > 0 ) {
		$used_by   = (array) get_post_meta( $coupon_id, '_used_by', false );
		$user_uses = 0;
		foreach ( $used_by as $uid ) {
			if ( (int) $uid === $user_id ) {
				++$user_uses;
			}
		}
		if ( $user_uses >= $limit_per_user ) {
			return new WP_Error(
				'papelito_coupon_usage_limit_user',
				'Você já utilizou este cupom o número máximo de vezes.',
				array( 'status' => 409 )
			);
		}
	}

	$vendor_filter  = papelito_coupon_normalize_ids( get_post_meta( $coupon_id, PAPELITO_COUPON_META_VENDOR_IDS, true ) );
	$product_filter = papelito_coupon_normalize_ids( get_post_meta( $coupon_id, PAPELITO_COUPON_META_PRODUCT_IDS, true ) );

	$qualifying_product_ids = array();
	$qualifying_subtotal    = 0.0;
	$valid_cart_items       = 0;

	foreach ( $cart_items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
		$vendor_id  = isset( $item['vendor_id'] ) ? absint( $item['vendor_id'] ) : 0;
		$qty        = isset( $item['qty'] ) ? max( 0, (int) $item['qty'] ) : 0;
		$price      = isset( $item['price'] ) ? max( 0.0, (float) $item['price'] ) : 0.0;

		if ( $product_id <= 0 || $qty <= 0 ) {
			continue;
		}

		++$valid_cart_items;

		if ( ! empty( $vendor_filter ) && ! in_array( $vendor_id, $vendor_filter, true ) ) {
			return new WP_Error(
				'papelito_coupon_vendor_restricted',
				'Este cupom não pode ser aplicado a itens de outro vendor.',
				array( 'status' => 422 )
			);
		}

		if ( ! empty( $product_filter ) && ! in_array( $product_id, $product_filter, true ) ) {
			return new WP_Error(
				'papelito_coupon_product_restricted',
				'Este cupom não pode ser aplicado aos produtos atuais do carrinho.',
				array( 'status' => 422 )
			);
		}

		$qualifying_product_ids[] = $product_id;
		$qualifying_subtotal     += $price * $qty;
	}

	if ( 0 === $valid_cart_items || empty( $qualifying_product_ids ) ) {
		return new WP_Error(
			'papelito_coupon_no_eligible_items',
			'Nenhum item do seu carrinho é elegível para este cupom.',
			array( 'status' => 422 )
		);
	}

	$minimum_amount = (float) $coupon->get_minimum_amount();
	if ( $minimum_amount > 0 && $qualifying_subtotal < $minimum_amount ) {
		return new WP_Error(
			'papelito_coupon_minimum_not_met',
			sprintf( 'Subtotal mínimo de %s não atingido.', wc_price( $minimum_amount ) ),
			array( 'status' => 422 )
		);
	}

	$discount_type = (string) $coupon->get_discount_type();
	$amount        = (float) $coupon->get_amount();

	if ( 'percent' === $discount_type ) {
		$discount_value = round( $qualifying_subtotal * $amount / 100, 2 );
	} elseif ( 'fixed_cart' === $discount_type ) {
		$discount_value = round( min( $amount, $qualifying_subtotal ), 2 );
	} else {
		return new WP_Error(
			'papelito_coupon_invalid_type',
			'Tipo de desconto não suportado.',
			array( 'status' => 422 )
		);
	}

	return array(
		'ok'                  => true,
		'code'                => $code,
		'discount_type'       => $discount_type,
		'discount_value'      => (float) $discount_value,
		'applied_product_ids' => array_values( array_unique( $qualifying_product_ids ) ),
	);
}

/**
 * Permission callback: requer manage_options.
 */
function papelito_coupons_require_admin() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	return new WP_Error(
		'papelito_coupon_forbidden',
		'Acesso administrativo necessário.',
		array( 'status' => 403 )
	);
}

/**
 * Permission callback: requer usuário logado.
 */
function papelito_coupons_require_logged_in() {
	if ( is_user_logged_in() ) {
		return true;
	}

	return new WP_Error(
		'papelito_coupon_auth_required',
		'Não autenticado.',
		array( 'status' => 401 )
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/admin/coupons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_coupons_require_admin',
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'default'           => 'any',
						'sanitize_callback' => 'sanitize_key',
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 20 ),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$status   = (string) $request->get_param( 'status' );
					$search   = trim( (string) $request->get_param( 'search' ) );
					$page     = max( 1, (int) $request->get_param( 'page' ) );
					$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

					$query_args = array(
						'post_type'      => 'shop_coupon',
						'post_status'    => 'any' === $status ? array( 'publish', 'draft' ) : array( $status ),
						'posts_per_page' => $per_page,
						'paged'          => $page,
						'orderby'        => 'date',
						'order'          => 'DESC',
					);

					if ( '' !== $search ) {
						$query_args['s'] = $search;
					}

					$query = new WP_Query( $query_args );
					$items = array();

					foreach ( $query->posts as $post ) {
						$mapped = papelito_coupon_map_to_response( (int) $post->ID );
						if ( null !== $mapped ) {
							$items[] = $mapped;
						}
					}

					return new WP_REST_Response(
						array(
							'items'   => $items,
							'total'   => (int) $query->found_posts,
							'page'    => $page,
							'perPage' => $per_page,
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/coupons',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_coupons_require_admin',
				'callback'            => static function ( WP_REST_Request $request ) {
					$payload   = $request->get_json_params();
					$validated = papelito_coupon_validate_input( is_array( $payload ) ? $payload : array(), null );

					if ( is_wp_error( $validated ) ) {
						return $validated;
					}

					$result = papelito_coupon_persist( $validated, null );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( papelito_coupon_map_to_response( (int) $result ), 201 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/coupons/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_coupons_require_admin',
				'callback'            => static function ( WP_REST_Request $request ) {
					$id     = absint( $request->get_param( 'id' ) );
					$mapped = papelito_coupon_map_to_response( $id );

					if ( null === $mapped ) {
						return new WP_Error(
							'papelito_coupon_not_found',
							'Cupom não encontrado.',
							array( 'status' => 404 )
						);
					}

					return new WP_REST_Response( $mapped, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/coupons/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_coupons_require_admin',
				'callback'            => static function ( WP_REST_Request $request ) {
					$id      = absint( $request->get_param( 'id' ) );
					$payload = $request->get_json_params();

					$existing = papelito_coupon_map_to_response( $id );
					if ( null === $existing ) {
						return new WP_Error(
							'papelito_coupon_not_found',
							'Cupom não encontrado.',
							array( 'status' => 404 )
						);
					}

					$validated = papelito_coupon_validate_input( is_array( $payload ) ? $payload : array(), $id );
					if ( is_wp_error( $validated ) ) {
						return $validated;
					}

					$result = papelito_coupon_persist( $validated, $id );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( papelito_coupon_map_to_response( $id ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/coupons/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => 'papelito_coupons_require_admin',
				'callback'            => static function ( WP_REST_Request $request ) {
					$id   = absint( $request->get_param( 'id' ) );
					$post = get_post( $id );

					if ( ! $post || 'shop_coupon' !== $post->post_type ) {
						return new WP_Error(
							'papelito_coupon_not_found',
							'Cupom não encontrado.',
							array( 'status' => 404 )
						);
					}

					$deleted = wp_delete_post( $id, true );
					if ( ! $deleted ) {
						return new WP_Error(
							'papelito_coupon_delete_failed',
							sprintf( 'Falha ao remover cupom %d (status atual: %s).', $id, $post->post_status ),
							array( 'status' => 500 )
						);
					}

					return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/coupons/vendor-options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_coupons_require_admin',
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$search = trim( (string) $request->get_param( 'search' ) );

					$query_args = array(
						'role'    => 'seller',
						'number'  => 50,
						'orderby' => 'display_name',
						'order'   => 'ASC',
						'fields'  => array( 'ID', 'display_name', 'user_email' ),
					);

					if ( '' !== $search ) {
						$query_args['search']         = '*' . esc_attr( $search ) . '*';
						$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
					}

					$users   = get_users( $query_args );
					$options = array();

					foreach ( $users as $user ) {
						if ( function_exists( 'papelito_get_seller_application_status' ) ) {
							$status = papelito_get_seller_application_status( (int) $user->ID );
							if ( 'approved' !== $status ) {
								continue;
							}
						}

						$store_name = (string) get_user_meta( $user->ID, 'store_name', true );
						$options[]  = array(
							'id'         => (int) $user->ID,
							'name'       => '' !== $store_name ? $store_name : (string) $user->display_name,
							'storeName'  => $store_name,
							'displayName' => (string) $user->display_name,
							'email'      => (string) $user->user_email,
						);
					}

					return new WP_REST_Response( array( 'items' => $options ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/coupons/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_coupons_require_logged_in',
				'callback'            => static function ( WP_REST_Request $request ) {
					$payload    = $request->get_json_params();
					$payload    = is_array( $payload ) ? $payload : array();
					$code       = (string) ( $payload['code'] ?? '' );
					$cart_items = is_array( $payload['cart_items'] ?? null ) ? $payload['cart_items'] : array();

					$result = papelito_coupon_apply_resolve( $code, $cart_items, get_current_user_id() );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( $result, 200 );
				},
			)
		);
	}
);
