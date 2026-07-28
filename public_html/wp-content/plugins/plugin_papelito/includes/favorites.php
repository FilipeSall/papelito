<?php
/**
 * Favoritos unificados para WordPress, WooCommerce e frontend headless.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PAPELITO_FAVORITES_META_KEY' ) ) {
	define( 'PAPELITO_FAVORITES_META_KEY', 'papelito_favorites_v1' );
}

/**
 * Check if the given product can be favorited.
 *
 * @param int $product_id Product database ID.
 * @return bool
 */
function papelito_is_favorite_product_valid( $product_id ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
		return false;
	}

	$product = wc_get_product( $product_id );

	if ( ! $product ) {
		return false;
	}

	return 'publish' === get_post_status( $product_id );
}

/**
 * Normalize a favorite timestamp to a unix timestamp.
 *
 * @param mixed $value Raw stored value.
 * @return int
 */
function papelito_normalize_favorite_timestamp( $value ) {
	if ( is_numeric( $value ) ) {
		$timestamp = (int) $value;
		return $timestamp > 0 ? $timestamp : time();
	}

	if ( is_string( $value ) ) {
		$timestamp = strtotime( $value );
		return false !== $timestamp ? (int) $timestamp : time();
	}

	return time();
}

/**
 * Normalize raw favorites data into a stable list.
 *
 * @param mixed $raw Raw user meta value.
 * @return array<int, array{product_id:int, added_at:string}>
 */
function papelito_normalize_favorites_entries( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$by_product = array();

	foreach ( $raw as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$product_id = 0;

		if ( isset( $item['product_id'] ) ) {
			$product_id = absint( $item['product_id'] );
		} elseif ( isset( $item['productId'] ) ) {
			$product_id = absint( $item['productId'] );
		}

		if ( $product_id <= 0 || ! papelito_is_favorite_product_valid( $product_id ) ) {
			continue;
		}

		$added_at = '';

		if ( isset( $item['added_at'] ) && is_string( $item['added_at'] ) ) {
			$added_at = $item['added_at'];
		} elseif ( isset( $item['addedAt'] ) && is_string( $item['addedAt'] ) ) {
			$added_at = $item['addedAt'];
		}

		$timestamp = papelito_normalize_favorite_timestamp( $added_at );

		if ( ! isset( $by_product[ $product_id ] ) || $timestamp > $by_product[ $product_id ] ) {
			$by_product[ $product_id ] = $timestamp;
		}
	}

	arsort( $by_product, SORT_NUMERIC );

	$entries = array();

	foreach ( $by_product as $product_id => $timestamp ) {
		$entries[] = array(
			'product_id' => (int) $product_id,
			'added_at'   => gmdate( 'c', (int) $timestamp ),
		);
	}

	return $entries;
}

/**
 * Persist the normalized favorites list for a user.
 *
 * @param int   $user_id User database ID.
 * @param array $entries Normalized entries.
 * @return array<int, array{product_id:int, added_at:string}>
 */
function papelito_store_user_favorites( $user_id, array $entries ) {
	$user_id = absint( $user_id );

	if ( $user_id <= 0 ) {
		return array();
	}

	$normalized = papelito_normalize_favorites_entries( $entries );

	if ( empty( $normalized ) ) {
		delete_user_meta( $user_id, PAPELITO_FAVORITES_META_KEY );
		return array();
	}

	update_user_meta( $user_id, PAPELITO_FAVORITES_META_KEY, array_values( $normalized ) );

	return array_values( $normalized );
}

/**
 * Retrieve favorites for a user and silently clean invalid data.
 *
 * @param int  $user_id          User database ID.
 * @param bool $persist_cleanup  Whether cleanup should be written back.
 * @return array<int, array{product_id:int, added_at:string}>
 */
function papelito_get_user_favorites( $user_id, $persist_cleanup = true ) {
	$user_id = absint( $user_id );

	if ( $user_id <= 0 ) {
		return array();
	}

	$raw        = get_user_meta( $user_id, PAPELITO_FAVORITES_META_KEY, true );
	$normalized = papelito_normalize_favorites_entries( $raw );

	if ( ! $persist_cleanup ) {
		return $normalized;
	}

	$raw_encoded        = wp_json_encode( is_array( $raw ) ? array_values( $raw ) : array() );
	$normalized_encoded = wp_json_encode( array_values( $normalized ) );

	if ( $raw_encoded !== $normalized_encoded ) {
		papelito_store_user_favorites( $user_id, $normalized );
	}

	return $normalized;
}

/**
 * Check whether a user has favorited a given product.
 *
 * @param int $user_id User database ID.
 * @param int $product_id Product database ID.
 * @return bool
 */
function papelito_user_has_favorite_product( $user_id, $product_id ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 ) {
		return false;
	}

	foreach ( papelito_get_user_favorites( $user_id ) as $entry ) {
		if ( $product_id === (int) $entry['product_id'] ) {
			return true;
		}
	}

	return false;
}

/**
 * Add or refresh a favorite for the given user.
 *
 * @param int $user_id User database ID.
 * @param int $product_id Product database ID.
 * @return array<string,mixed>|WP_Error
 */
function papelito_add_favorite_product( $user_id, $product_id ) {
	$user_id    = absint( $user_id );
	$product_id = absint( $product_id );

	if ( $user_id <= 0 ) {
		return new WP_Error( 'papelito_favorites_auth_required', 'Usuario nao autenticado.', array( 'status' => 401 ) );
	}

	if ( $product_id <= 0 ) {
		return new WP_Error( 'papelito_favorites_invalid_product', 'Produto invalido.', array( 'status' => 400 ) );
	}

	if ( ! papelito_is_favorite_product_valid( $product_id ) ) {
		return new WP_Error( 'papelito_favorites_product_unavailable', 'Produto indisponivel para favoritos.', array( 'status' => 404 ) );
	}

	$favorites = array_values(
		array_filter(
			papelito_get_user_favorites( $user_id ),
			static function ( $entry ) use ( $product_id ) {
				return (int) $entry['product_id'] !== $product_id;
			}
		)
	);

	array_unshift(
		$favorites,
		array(
			'product_id' => $product_id,
			'added_at'   => gmdate( 'c' ),
		)
	);

	$stored = papelito_store_user_favorites( $user_id, $favorites );

	return array(
		'success'        => true,
		'isFavorite'     => true,
		'favoritesCount' => count( $stored ),
		'productId'      => $product_id,
	);
}

/**
 * Remove a favorite for the given user.
 *
 * @param int $user_id User database ID.
 * @param int $product_id Product database ID.
 * @return array<string,mixed>|WP_Error
 */
function papelito_remove_favorite_product( $user_id, $product_id ) {
	$user_id    = absint( $user_id );
	$product_id = absint( $product_id );

	if ( $user_id <= 0 ) {
		return new WP_Error( 'papelito_favorites_auth_required', 'Usuario nao autenticado.', array( 'status' => 401 ) );
	}

	if ( $product_id <= 0 ) {
		return new WP_Error( 'papelito_favorites_invalid_product', 'Produto invalido.', array( 'status' => 400 ) );
	}

	$favorites = array_values(
		array_filter(
			papelito_get_user_favorites( $user_id ),
			static function ( $entry ) use ( $product_id ) {
				return (int) $entry['product_id'] !== $product_id;
			}
		)
	);

	$stored = papelito_store_user_favorites( $user_id, $favorites );

	return array(
		'success'        => true,
		'isFavorite'     => false,
		'favoritesCount' => count( $stored ),
		'productId'      => $product_id,
	);
}

/**
 * Resolve a WC_Product from a favorite entry.
 *
 * @param array $entry Favorite entry.
 * @return WC_Product|null
 */
function papelito_get_product_from_favorite_entry( array $entry ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return null;
	}

	$product_id = isset( $entry['product_id'] ) ? absint( $entry['product_id'] ) : 0;

	if ( $product_id <= 0 ) {
		return null;
	}

	$product = wc_get_product( $product_id );

	return $product instanceof WC_Product ? $product : null;
}

/**
 * Convert a favorite entry into REST-friendly data.
 *
 * @param array $entry Favorite entry.
 * @return array<string,mixed>|null
 */
function papelito_map_favorite_entry_to_rest_item( array $entry ) {
	$product = papelito_get_product_from_favorite_entry( $entry );

	if ( ! $product ) {
		return null;
	}

	$image_id  = $product->get_image_id();
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

	return array(
		'productId' => (int) $entry['product_id'],
		'addedAt'   => (string) $entry['added_at'],
		'product'   => array(
			'databaseId' => $product->get_id(),
			'name'       => $product->get_name(),
			'permalink'  => get_permalink( $product->get_id() ),
			'image'      => $image_url,
			'priceHtml'  => $product->get_price_html(),
			'stockStatus'=> $product->get_stock_status(),
		),
	);
}

/**
 * Require an authenticated WordPress user for REST favorites routes.
 *
 * @return true|WP_Error
 */
function papelito_require_rest_favorites_auth() {
	if ( is_user_logged_in() ) {
		return true;
	}

	return new WP_Error( 'papelito_favorites_auth_required', 'Usuario nao autenticado.', array( 'status' => 401 ) );
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'papelito/v1',
			'/favorites',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_require_rest_favorites_auth',
					'callback'            => static function () {
						$user_id  = get_current_user_id();
						$entries  = papelito_get_user_favorites( $user_id );
						$items    = array_values(
							array_filter(
								array_map( 'papelito_map_favorite_entry_to_rest_item', $entries )
							)
						);

						return new WP_REST_Response(
							array(
								'items'  => $items,
								'count'  => count( $entries ),
							),
							200
						);
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_require_rest_favorites_auth',
					'args'                => array(
						'productId' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
					'callback'            => static function ( WP_REST_Request $request ) {
						$result = papelito_add_favorite_product( get_current_user_id(), (int) $request->get_param( 'productId' ) );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( $result, 200 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/favorites/(?P<productId>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => 'papelito_require_rest_favorites_auth',
				'args'                => array(
					'productId' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_remove_favorite_product( get_current_user_id(), (int) $request->get_param( 'productId' ) );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( $result, 200 );
				},
			)
		);
	}
);

/**
 * Resolve a product ID from a WPGraphQL product model.
 *
 * @param mixed $source GraphQL source.
 * @return int
 */
function papelito_resolve_graphql_product_id( $source ) {
	if ( is_object( $source ) ) {
		if ( method_exists( $source, 'get_id' ) ) {
			return absint( $source->get_id() );
		}

		if ( isset( $source->ID ) ) {
			return absint( $source->ID );
		}

		if ( isset( $source->databaseId ) ) {
			return absint( $source->databaseId );
		}
	}

	if ( is_array( $source ) && isset( $source['databaseId'] ) ) {
		return absint( $source['databaseId'] );
	}

	return 0;
}

/**
 * Resolve a customer/user ID from a WPGraphQL customer model.
 *
 * @param mixed $source GraphQL source.
 * @return int
 */
function papelito_resolve_graphql_customer_id( $source ) {
	if ( is_object( $source ) ) {
		if ( method_exists( $source, 'get_id' ) ) {
			return absint( $source->get_id() );
		}

		if ( isset( $source->ID ) ) {
			return absint( $source->ID );
		}

		if ( isset( $source->customer_id ) ) {
			return absint( $source->customer_id );
		}
	}

	return get_current_user_id();
}

add_action(
	'graphql_register_types',
	static function () {
		if (
			! function_exists( 'register_graphql_object_type' ) ||
			! function_exists( 'register_graphql_field' ) ||
			! function_exists( 'register_graphql_mutation' )
		) {
			return;
		}

		register_graphql_object_type(
			'PapelitoFavorite',
			array(
				'description' => 'Produto favoritado por um cliente.',
				'fields'      => array(
					'productId' => array(
						'type'        => 'Int',
						'description' => 'Database ID do produto favoritado.',
						'resolve'     => static function ( $favorite ) {
							return isset( $favorite['product_id'] ) ? absint( $favorite['product_id'] ) : absint( $favorite['productId'] ?? 0 );
						},
					),
					'addedAt'   => array(
						'type'        => 'String',
						'description' => 'Data de inclusão do favorito em formato ISO 8601.',
						'resolve'     => static function ( $favorite ) {
							return isset( $favorite['added_at'] ) ? (string) $favorite['added_at'] : (string) ( $favorite['addedAt'] ?? '' );
						},
					),
					'product'   => array(
						'type'        => 'Product',
						'description' => 'Produto associado ao favorito.',
						'resolve'     => static function ( $favorite, $args, $context ) {
							$product_id = isset( $favorite['product_id'] ) ? absint( $favorite['product_id'] ) : absint( $favorite['productId'] ?? 0 );

							if ( $product_id <= 0 || ! class_exists( '\WPGraphQL\WooCommerce\Data\Factory' ) ) {
								return null;
							}

							return \WPGraphQL\WooCommerce\Data\Factory::resolve_crud_object( $product_id, $context );
						},
					),
				),
			)
		);

		register_graphql_object_type(
			'PapelitoFavoriteMutationPayload',
			array(
				'description' => 'Resultado de mutacao de favoritos.',
				'fields'      => array(
					'success'        => array(
						'type'    => 'Boolean',
						'resolve' => static function ( $payload ) {
							return ! empty( $payload['success'] );
						},
					),
					'isFavorite'     => array(
						'type'    => 'Boolean',
						'resolve' => static function ( $payload ) {
							return ! empty( $payload['isFavorite'] );
						},
					),
					'favoritesCount' => array(
						'type'    => 'Int',
						'resolve' => static function ( $payload ) {
							return isset( $payload['favoritesCount'] ) ? absint( $payload['favoritesCount'] ) : 0;
						},
					),
					'productId'      => array(
						'type'    => 'Int',
						'resolve' => static function ( $payload ) {
							return isset( $payload['productId'] ) ? absint( $payload['productId'] ) : 0;
						},
					),
				),
			)
		);

		register_graphql_field(
			'Customer',
			'favorites',
			array(
				'type'        => array( 'list_of' => 'PapelitoFavorite' ),
				'description' => 'Lista de favoritos do cliente autenticado.',
				'resolve'     => static function ( $customer ) {
					$user_id = papelito_resolve_graphql_customer_id( $customer );
					return $user_id > 0 ? papelito_get_user_favorites( $user_id ) : array();
				},
			)
		);

		register_graphql_field(
			'Customer',
			'favoritesCount',
			array(
				'type'        => 'Int',
				'description' => 'Quantidade de favoritos do cliente autenticado.',
				'resolve'     => static function ( $customer ) {
					$user_id = papelito_resolve_graphql_customer_id( $customer );
					return $user_id > 0 ? count( papelito_get_user_favorites( $user_id ) ) : 0;
				},
			)
		);

		register_graphql_field(
			'Product',
			'isFavorite',
			array(
				'type'        => 'Boolean',
				'description' => 'Indica se o produto esta nos favoritos do usuário autenticado.',
				'resolve'     => static function ( $product ) {
					$user_id    = get_current_user_id();
					$product_id = papelito_resolve_graphql_product_id( $product );

					if ( $user_id <= 0 || $product_id <= 0 ) {
						return false;
					}

					return papelito_user_has_favorite_product( $user_id, $product_id );
				},
			)
		);

		register_graphql_mutation(
			'addFavoriteProduct',
			array(
				'inputFields'         => array(
					'productId' => array(
						'type'        => array( 'non_null' => 'Int' ),
						'description' => 'Database ID do produto a favoritar.',
					),
				),
				'outputFields'        => array(
					'success'        => array( 'type' => 'Boolean' ),
					'isFavorite'     => array( 'type' => 'Boolean' ),
					'favoritesCount' => array( 'type' => 'Int' ),
					'productId'      => array( 'type' => 'Int' ),
				),
				'mutateAndGetPayload' => static function ( $input ) {
					$user_id = get_current_user_id();

					if ( $user_id <= 0 ) {
						throw papelito_graphql_user_error( 'Usuario nao autenticado.' );
					}

					$result = papelito_add_favorite_product( $user_id, isset( $input['productId'] ) ? $input['productId'] : 0 );

					if ( is_wp_error( $result ) ) {
						throw papelito_graphql_user_error( $result->get_error_message() );
					}

					return $result;
				},
			)
		);

		register_graphql_mutation(
			'removeFavoriteProduct',
			array(
				'inputFields'         => array(
					'productId' => array(
						'type'        => array( 'non_null' => 'Int' ),
						'description' => 'Database ID do produto a remover dos favoritos.',
					),
				),
				'outputFields'        => array(
					'success'        => array( 'type' => 'Boolean' ),
					'isFavorite'     => array( 'type' => 'Boolean' ),
					'favoritesCount' => array( 'type' => 'Int' ),
					'productId'      => array( 'type' => 'Int' ),
				),
				'mutateAndGetPayload' => static function ( $input ) {
					$user_id = get_current_user_id();

					if ( $user_id <= 0 ) {
						throw papelito_graphql_user_error( 'Usuario nao autenticado.' );
					}

					$result = papelito_remove_favorite_product( $user_id, isset( $input['productId'] ) ? $input['productId'] : 0 );

					if ( is_wp_error( $result ) ) {
						throw papelito_graphql_user_error( $result->get_error_message() );
					}

					return $result;
				},
			)
		);
	}
);

/**
 * Register the WooCommerce account endpoint.
 *
 * @return void
 */
function papelito_register_favorites_endpoint() {
	add_rewrite_endpoint( 'favoritos', EP_ROOT | EP_PAGES );
}
add_action( 'init', 'papelito_register_favorites_endpoint' );

/**
 * Add favorites to the WooCommerce account menu.
 *
 * @param array $items Existing menu items.
 * @return array
 */
function papelito_add_favorites_account_menu_item( $items ) {
	$updated = array();

	foreach ( $items as $key => $label ) {
		if ( 'customer-logout' === $key ) {
			$updated['favoritos'] = 'Favoritos';
		}

		$updated[ $key ] = $label;
	}

	if ( ! isset( $updated['favoritos'] ) ) {
		$updated['favoritos'] = 'Favoritos';
	}

	return $updated;
}
add_filter( 'woocommerce_account_menu_items', 'papelito_add_favorites_account_menu_item' );

/**
 * Render a single favorites account card.
 *
 * @param array $entry Favorite entry.
 * @return string
 */
function papelito_render_favorite_account_card( array $entry ) {
	$product = papelito_get_product_from_favorite_entry( $entry );

	if ( ! $product ) {
		return '';
	}

	$product_id = $product->get_id();
	$image      = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'papelito-favorites-card__image', 'loading' => 'lazy' ) );
	$price_html = $product->get_price_html();
	$permalink  = get_permalink( $product_id );
	$title      = esc_html( $product->get_name() );

	ob_start();
	?>
	<article class="papelito-favorites-card" data-product-id="<?php echo esc_attr( $product_id ); ?>">
		<a class="papelito-favorites-card__media" href="<?php echo esc_url( $permalink ); ?>">
			<?php echo $image ? wp_kses_post( $image ) : ''; ?>
		</a>
		<div class="papelito-favorites-card__content">
			<a class="papelito-favorites-card__title" href="<?php echo esc_url( $permalink ); ?>">
				<?php echo $title; ?>
			</a>
			<div class="papelito-favorites-card__price">
				<?php echo wp_kses_post( $price_html ); ?>
			</div>
			<div class="papelito-favorites-card__actions">
				<a class="papelito-favorites-card__link" href="<?php echo esc_url( $permalink ); ?>">
					Ver produto
				</a>
				<button
					type="button"
					class="papelito-favorite-toggle papelito-favorites-card__remove"
					data-product-id="<?php echo esc_attr( $product_id ); ?>"
					data-is-favorite="true"
					aria-pressed="true"
				>
					Remover
				</button>
			</div>
		</div>
	</article>
	<?php

	return (string) ob_get_clean();
}

/**
 * Render the WooCommerce account endpoint content.
 *
 * @return void
 */
function papelito_render_favorites_account_content() {
	$user_id   = get_current_user_id();
	$favorites = $user_id > 0 ? papelito_get_user_favorites( $user_id ) : array();
	$shop_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	?>
	<section class="papelito-favorites-account" data-empty-label="Nenhum favorito por enquanto.">
		<header class="papelito-favorites-account__header">
			<h2>Favoritos</h2>
			<p><?php echo esc_html( sprintf( _n( '%d produto salvo', '%d produtos salvos', count( $favorites ), 'papelito' ), count( $favorites ) ) ); ?></p>
		</header>

		<?php if ( empty( $favorites ) ) : ?>
			<div class="papelito-favorites-empty">
				<p>Quando você salvar produtos, eles vao aparecer aqui para facilitar a recompra.</p>
				<a class="button" href="<?php echo esc_url( $shop_url ); ?>">Ir para a loja</a>
			</div>
		<?php else : ?>
			<div class="papelito-favorites-grid">
				<?php
				foreach ( $favorites as $favorite ) {
					echo wp_kses_post( papelito_render_favorite_account_card( $favorite ) );
				}
				?>
			</div>
		<?php endif; ?>
	</section>
	<?php
}
add_action( 'woocommerce_account_favoritos_endpoint', 'papelito_render_favorites_account_content' );

/**
 * Render the favorite toggle button on single product pages.
 *
 * @return void
 */
function papelito_render_single_product_favorite_button() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$product_id  = $product->get_id();
	$is_favorite = is_user_logged_in() && papelito_user_has_favorite_product( get_current_user_id(), $product_id );
	$label       = $is_favorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos';
	?>
	<div class="papelito-single-favorite">
		<button
			type="button"
			class="papelito-favorite-toggle papelito-single-favorite__button<?php echo $is_favorite ? ' is-active' : ''; ?>"
			data-product-id="<?php echo esc_attr( $product_id ); ?>"
			data-is-favorite="<?php echo $is_favorite ? 'true' : 'false'; ?>"
			aria-pressed="<?php echo $is_favorite ? 'true' : 'false'; ?>"
		>
			<span class="papelito-single-favorite__icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M9 15.2C9 15.2 3.6 11.8 2.15 8.65C0.85 5.85 1.95 3.45 4.3 3.15C5.95 2.95 7.4 3.75 8.2 5.05L9 6.35L9.8 5.05C10.6 3.75 12.05 2.95 13.7 3.15C16.05 3.45 17.15 5.85 15.85 8.65C14.4 11.8 9 15.2 9 15.2Z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</span>
			<span class="papelito-single-favorite__label"><?php echo esc_html( $label ); ?></span>
		</button>
	</div>
	<?php
}
add_action( 'woocommerce_single_product_summary', 'papelito_render_single_product_favorite_button', 31 );

/**
 * Enqueue frontend favorites assets when needed.
 *
 * @return void
 */
function papelito_enqueue_favorites_assets() {
	if (
		! function_exists( 'is_product' ) ||
		( ! is_product() && ! is_account_page() )
	) {
		return;
	}

	$script_path = plugin_dir_path( __FILE__ ) . '../js/favorites.js';
	$style_path  = plugin_dir_path( __FILE__ ) . '../css/favorites.css';

	wp_enqueue_style(
		'papelito-favorites',
		plugin_dir_url( __FILE__ ) . '../css/favorites.css',
		array(),
		file_exists( $style_path ) ? (string) filemtime( $style_path ) : '1.0.0'
	);

	wp_enqueue_script(
		'papelito-favorites',
		plugin_dir_url( __FILE__ ) . '../js/favorites.js',
		array(),
		file_exists( $script_path ) ? (string) filemtime( $script_path ) : '1.0.0',
		true
	);

	wp_localize_script(
		'papelito-favorites',
		'papelitoFavorites',
		array(
			'restUrl'              => esc_url_raw( rest_url( 'papelito/v1/favorites' ) ),
			'nonce'                => wp_create_nonce( 'wp_rest' ),
			'loginUrl'             => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url(),
			'removeLabel'          => 'Remover dos favoritos',
			'addLabel'             => 'Adicionar aos favoritos',
			'removingLabel'        => 'Removendo...',
			'addingLabel'          => 'Salvando...',
			'emptyFavoritesLabel'  => 'Nenhum favorito por enquanto.',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'papelito_enqueue_favorites_assets', 110 );
