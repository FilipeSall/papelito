<?php
/**
 * Endpoints REST para oferta relâmpago da home e painel admin.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nome da option usada no singleton de campanha.
 *
 * @return string
 */
function papelito_flash_sale_option_name(): string {
	return 'papelito_flash_sale_campaign';
}

/**
 * Timezone configurado no WordPress.
 *
 * @return DateTimeZone
 */
function papelito_flash_sale_timezone(): DateTimeZone {
	return wp_timezone();
}

/**
 * Parse de data/hora local ou ISO8601.
 *
 * @param string $value Valor vindo da API.
 * @return DateTimeImmutable|null
 */
function papelito_flash_sale_parse_datetime( string $value ): ?DateTimeImmutable {
	$value = trim( $value );

	if ( '' === $value ) {
		return null;
	}

	$timezone = papelito_flash_sale_timezone();
	$formats  = array(
		'Y-m-d\TH:i',
		'Y-m-d\TH:i:s',
		DATE_ATOM,
	);

	foreach ( $formats as $format ) {
		$date = DateTimeImmutable::createFromFormat( $format, $value, $timezone );

		if ( $date instanceof DateTimeImmutable ) {
			return $date;
		}
	}

	try {
		return new DateTimeImmutable( $value, $timezone );
	} catch ( Exception $exception ) {
		return null;
	}
}

/**
 * Formata data em ISO8601 com timezone da loja.
 *
 * @param DateTimeImmutable|null $date Data para serializar.
 * @return string
 */
function papelito_flash_sale_format_datetime( ?DateTimeImmutable $date ): string {
	return $date instanceof DateTimeImmutable ? $date->format( DATE_ATOM ) : '';
}

/**
 * Determina o status derivado da campanha.
 *
 * @param DateTimeImmutable|null $starts_at Início.
 * @param DateTimeImmutable|null $ends_at   Fim.
 * @return string
 */
function papelito_flash_sale_derive_status( ?DateTimeImmutable $starts_at, ?DateTimeImmutable $ends_at ): string {
	if ( ! $starts_at || ! $ends_at ) {
		return 'draft';
	}

	$now = new DateTimeImmutable( 'now', papelito_flash_sale_timezone() );

	if ( $now < $starts_at ) {
		return 'scheduled';
	}

	if ( $now > $ends_at ) {
		return 'expired';
	}

	return 'active';
}

/**
 * Normaliza IDs de produtos.
 *
 * @param mixed $value Valor arbitrário.
 * @return array<int>
 */
function papelito_flash_sale_normalize_product_ids( $value ): array {
	$product_ids = is_array( $value ) ? $value : array();

	return array_values(
		array_filter(
			array_map( 'absint', $product_ids ),
			static fn( int $product_id ): bool => $product_id > 0
		)
	);
}

/**
 * Busca dados crus da campanha armazenada.
 *
 * @return array<string, mixed>
 */
function papelito_flash_sale_get_raw_campaign(): array {
	$campaign = get_option( papelito_flash_sale_option_name(), array() );
	return is_array( $campaign ) ? $campaign : array();
}

/**
 * Normaliza os dados da campanha para leitura.
 *
 * @param array<string, mixed> $campaign Dados persistidos.
 * @return array<string, mixed>|null
 */
function papelito_flash_sale_normalize_campaign( array $campaign ): ?array {
	$title       = sanitize_text_field( (string) ( $campaign['title'] ?? '' ) );
	$label       = sanitize_text_field( (string) ( $campaign['label'] ?? '' ) );
	$supporting  = sanitize_textarea_field( (string) ( $campaign['supportingText'] ?? '' ) );
	$product_ids = papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() );

	if ( '' === $title ) {
		return null;
	}

	$starts_at = papelito_flash_sale_parse_datetime( (string) ( $campaign['starts_at'] ?? '' ) );
	$ends_at   = papelito_flash_sale_parse_datetime( (string) ( $campaign['ends_at'] ?? '' ) );
	$slug      = sanitize_title( $title );

	return array(
		'title'          => $title,
		'slug'           => $slug,
		'status'         => papelito_flash_sale_derive_status( $starts_at, $ends_at ),
		'starts_at'      => papelito_flash_sale_format_datetime( $starts_at ),
		'ends_at'        => papelito_flash_sale_format_datetime( $ends_at ),
		'productIds'     => $product_ids,
		'label'          => '' !== $label ? $label : 'Oferta Relâmpago',
		'supportingText' => $supporting,
	);
}

/**
 * Converte preço para float.
 *
 * @param mixed $value Valor textual/numérico.
 * @return float
 */
function papelito_flash_sale_to_float( $value ): float {
	$normalized = wc_format_decimal( wp_unslash( (string) $value ) );
	return is_numeric( $normalized ) ? (float) $normalized : 0.0;
}

/**
 * Monta payload resumido de um produto WooCommerce para a home/admin.
 *
 * @param WC_Product $product Produto.
 * @return array<string, mixed>
 */
function papelito_flash_sale_build_product_payload( WC_Product $product ): array {
	$image_id        = (int) $product->get_image_id();
	$image_url       = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'large' ) : '';
	$categories      = wc_get_product_terms( $product->get_id(), 'product_cat', array( 'fields' => 'all' ) );
	$tags            = wc_get_product_terms( $product->get_id(), 'product_tag', array( 'fields' => 'all' ) );
	$regular_price   = papelito_flash_sale_to_float( $product->get_regular_price( 'edit' ) );
	$sale_price      = papelito_flash_sale_to_float( $product->get_sale_price( 'edit' ) );
	$display_price   = $sale_price > 0 ? $sale_price : papelito_flash_sale_to_float( $product->get_price( 'edit' ) );
	$category_label  = ! empty( $categories ) && isset( $categories[0]->name ) ? (string) $categories[0]->name : 'Produto';
	$badge_label     = ! empty( $tags ) && isset( $tags[0]->name ) ? (string) $tags[0]->name : 'Destaque';
	$discount        = ( $regular_price > 0 && $sale_price > 0 && $sale_price < $regular_price )
		? (int) round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 )
		: 0;

	return array(
		'id'            => (string) $product->get_id(),
		'productId'     => $product->get_id(),
		'name'          => $product->get_name(),
		'sku'           => (string) $product->get_sku(),
		'category'      => $category_label,
		'badge'         => $badge_label,
		'discount'      => $discount,
		'originalPrice' => $regular_price,
		'price'         => $display_price,
		'rating'        => (float) $product->get_average_rating(),
		'reviews'       => (int) $product->get_review_count(),
		'image'         => $image_url ? $image_url : '',
		'permalink'     => (string) get_permalink( $product->get_id() ),
		'status'        => (string) $product->get_status(),
	);
}

/**
 * Resolve e valida um produto elegível para campanha.
 *
 * @param int $product_id ID do produto.
 * @return array<string, mixed>|WP_Error
 */
function papelito_flash_sale_validate_product( int $product_id ) {
	if ( $product_id <= 0 ) {
		return new WP_Error(
			'papelito_flash_sale_invalid_product',
			'ID de produto inválido.',
			array( 'status' => 422 )
		);
	}

	$product = wc_get_product( $product_id );

	if ( ! $product instanceof WC_Product ) {
		return new WP_Error(
			'papelito_flash_sale_product_not_found',
			sprintf( 'Produto %d não encontrado.', $product_id ),
			array( 'status' => 422 )
		);
	}

	if ( 'publish' !== $product->get_status() ) {
		return new WP_Error(
			'papelito_flash_sale_product_unpublished',
			sprintf( 'Produto %d precisa estar publicado para entrar na campanha.', $product_id ),
			array( 'status' => 422 )
		);
	}

	$payload = papelito_flash_sale_build_product_payload( $product );

	if ( empty( $payload['image'] ) ) {
		return new WP_Error(
			'papelito_flash_sale_product_missing_image',
			sprintf( 'Produto %d precisa ter imagem principal.', $product_id ),
			array( 'status' => 422 )
		);
	}

	if (
		! isset( $payload['originalPrice'], $payload['price'] ) ||
		! is_numeric( $payload['originalPrice'] ) ||
		! is_numeric( $payload['price'] ) ||
		(float) $payload['originalPrice'] <= 0 ||
		(float) $payload['price'] <= 0 ||
		(float) $payload['price'] >= (float) $payload['originalPrice']
	) {
		return new WP_Error(
			'papelito_flash_sale_product_missing_sale_price',
			sprintf( 'Produto %d precisa ter preço promocional válido no WooCommerce.', $product_id ),
			array( 'status' => 422 )
		);
	}

	return $payload;
}

/**
 * Lista issues atuais de uma campanha salva, sem impedir leitura admin.
 *
 * @param array<string, mixed> $campaign Campanha normalizada.
 * @return array<int, string>
 */
function papelito_flash_sale_collect_campaign_issues( array $campaign ): array {
	$issues      = array();
	$product_ids = papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() );

	if ( empty( $campaign['starts_at'] ) || empty( $campaign['ends_at'] ) ) {
		$issues[] = 'Campanha sem janela completa de início e fim.';
	}

	foreach ( $product_ids as $product_id ) {
		$product = papelito_flash_sale_validate_product( $product_id );

		if ( is_wp_error( $product ) ) {
			$issues[] = $product->get_error_message();
		}
	}

	return $issues;
}

/**
 * Monta snapshot admin da campanha.
 *
 * @return array<string, mixed>
 */
function papelito_flash_sale_get_admin_snapshot(): array {
	$raw_campaign = papelito_flash_sale_get_raw_campaign();
	$campaign     = papelito_flash_sale_normalize_campaign( $raw_campaign );

	if ( null === $campaign ) {
		return array(
			'campaign'         => null,
			'selectedProducts' => array(),
			'issues'           => array(),
		);
	}

	$selected_products = array();

	foreach ( $campaign['productIds'] as $product_id ) {
		$product = wc_get_product( $product_id );

		if ( $product instanceof WC_Product ) {
			$selected_products[] = papelito_flash_sale_build_product_payload( $product );
		}
	}

	return array(
		'campaign'         => $campaign,
		'selectedProducts' => $selected_products,
		'issues'           => papelito_flash_sale_collect_campaign_issues( $campaign ),
	);
}

/**
 * Valida e normaliza payload de escrita.
 *
 * @param array<string, mixed> $input JSON recebido.
 * @return array<string, mixed>|WP_Error
 */
function papelito_flash_sale_validate_input( array $input ) {
	$title          = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	$label          = sanitize_text_field( (string) ( $input['label'] ?? '' ) );
	$supporting     = sanitize_textarea_field( (string) ( $input['supportingText'] ?? '' ) );
	$product_ids    = papelito_flash_sale_normalize_product_ids( $input['productIds'] ?? array() );
	$starts_at_raw  = sanitize_text_field( (string) ( $input['startsAt'] ?? '' ) );
	$ends_at_raw    = sanitize_text_field( (string) ( $input['endsAt'] ?? '' ) );
	$starts_at      = papelito_flash_sale_parse_datetime( $starts_at_raw );
	$ends_at        = papelito_flash_sale_parse_datetime( $ends_at_raw );

	if ( '' === $title ) {
		return new WP_Error(
			'papelito_flash_sale_missing_title',
			'Título da campanha é obrigatório.',
			array( 'status' => 422 )
		);
	}

	if ( ! $starts_at || ! $ends_at ) {
		return new WP_Error(
			'papelito_flash_sale_missing_window',
			'Início e fim da campanha são obrigatórios.',
			array( 'status' => 422 )
		);
	}

	if ( $starts_at >= $ends_at ) {
		return new WP_Error(
			'papelito_flash_sale_invalid_window',
			'O início da campanha precisa ser anterior ao fim.',
			array( 'status' => 422 )
		);
	}

	if ( empty( $product_ids ) ) {
		return new WP_Error(
			'papelito_flash_sale_missing_products',
			'Selecione ao menos um produto para a campanha.',
			array( 'status' => 422 )
		);
	}

	if ( count( $product_ids ) !== count( array_unique( $product_ids ) ) ) {
		return new WP_Error(
			'papelito_flash_sale_duplicate_products',
			'A campanha não pode conter produtos duplicados.',
			array( 'status' => 422 )
		);
	}

	foreach ( $product_ids as $product_id ) {
		$validated = papelito_flash_sale_validate_product( $product_id );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
	}

	return array(
		'title'          => $title,
		'slug'           => sanitize_title( $title ),
		'starts_at'      => papelito_flash_sale_format_datetime( $starts_at ),
		'ends_at'        => papelito_flash_sale_format_datetime( $ends_at ),
		'productIds'     => $product_ids,
		'label'          => '' !== $label ? $label : 'Oferta Relâmpago',
		'supportingText' => $supporting,
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/home/flash-sale',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$campaign = papelito_flash_sale_normalize_campaign( papelito_flash_sale_get_raw_campaign() );

					if ( null === $campaign || 'active' !== $campaign['status'] ) {
						return new WP_REST_Response(
							array(
								'code'    => 'papelito_flash_sale_not_active',
								'message' => 'Nenhuma campanha ativa no momento.',
							),
							404
						);
					}

					$products = array();

					foreach ( $campaign['productIds'] as $product_id ) {
						$validated = papelito_flash_sale_validate_product( $product_id );

						if ( is_wp_error( $validated ) ) {
							return new WP_REST_Response(
								array(
									'code'    => $validated->get_error_code(),
									'message' => $validated->get_error_message(),
								),
								404
							);
						}

						$products[] = $validated;
					}

					return new WP_REST_Response(
						array(
							'campaign' => $campaign,
							'products' => $products,
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/flash-sale',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_flash_sale_get_admin_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/flash-sale',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$params    = $request->get_json_params();
					$payload   = is_array( $params ) ? $params : array();
					$validated = papelito_flash_sale_validate_input( $payload );

					if ( is_wp_error( $validated ) ) {
						$error_data = $validated->get_error_data();
						$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 422;

						return new WP_REST_Response(
							array(
								'code'    => $validated->get_error_code(),
								'message' => $validated->get_error_message(),
							),
							$status
						);
					}

					update_option( papelito_flash_sale_option_name(), $validated, false );

					return new WP_REST_Response( papelito_flash_sale_get_admin_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/flash-sale',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					delete_option( papelito_flash_sale_option_name() );

					return new WP_REST_Response(
						array(
							'campaign'         => null,
							'selectedProducts' => array(),
							'issues'           => array(),
						),
						200
					);
				},
			)
		);
	}
);
