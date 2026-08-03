<?php
/**
 * Endpoints REST para oferta relâmpago da home e painel admin.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PAPELITO_FLASH_SALE_PROMO_HOOK' ) ) {
	define( 'PAPELITO_FLASH_SALE_PROMO_HOOK', 'papelito_flash_sale_promo_start' );
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
 * Clampa um percentual de desconto para o intervalo permitido.
 *
 * @param mixed $value Valor vindo do request.
 * @return int
 */
function papelito_flash_sale_clamp_discount( $value ): int {
	$percent = (int) round( (float) $value );

	if ( $percent < 0 ) {
		return 0;
	}

	if ( $percent > 99 ) {
		return 99;
	}

	return $percent;
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
	$title           = sanitize_text_field( (string) ( $campaign['title'] ?? '' ) );
	$label           = sanitize_text_field( (string) ( $campaign['label'] ?? '' ) );
	$supporting      = sanitize_textarea_field( (string) ( $campaign['supportingText'] ?? '' ) );
	$product_ids     = papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() );
	$discount        = papelito_flash_sale_clamp_discount( $campaign['discountPercent'] ?? 0 );
	$starts_at = papelito_flash_sale_parse_datetime( (string) ( $campaign['starts_at'] ?? '' ) );
	$ends_at   = papelito_flash_sale_parse_datetime( (string) ( $campaign['ends_at'] ?? '' ) );

	if ( '' === $title && empty( $product_ids ) && 0 === $discount && ! $starts_at && ! $ends_at ) {
		return null;
	}

	$slug = sanitize_title( $title );

	return array(
		'title'           => $title,
		'slug'            => '' !== $slug ? $slug : 'oferta-relampago',
		'status'          => papelito_flash_sale_derive_status( $starts_at, $ends_at ),
		'starts_at'       => papelito_flash_sale_format_datetime( $starts_at ),
		'ends_at'         => papelito_flash_sale_format_datetime( $ends_at ),
		'productIds'      => $product_ids,
		'discountPercent' => $discount,
		'label'           => '' !== $label ? $label : 'Oferta Relâmpago',
		'supportingText'  => $supporting,
	);
}

/**
 * Codifica bytes em base64 URL-safe sem padding.
 *
 * @param string $value Bytes crus.
 * @return string
 */
function papelito_flash_sale_base64url_encode( string $value ): string {
	return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}

/**
 * Decodifica base64 URL-safe.
 *
 * @param string $value Valor codificado.
 * @return string|false
 */
function papelito_flash_sale_base64url_decode( string $value ) {
	$padding = strlen( $value ) % 4;
	if ( $padding > 0 ) {
		$value .= str_repeat( '=', 4 - $padding );
	}

	return base64_decode( strtr( $value, '-_', '+/' ), true );
}

/**
 * Fingerprint estável dos dados que alteram uma promoção.
 *
 * @param array<string,mixed> $campaign Campanha normalizada.
 * @return string
 */
function papelito_flash_sale_campaign_fingerprint( array $campaign ): string {
	$canonical = array(
		'title'           => sanitize_text_field( (string) ( $campaign['title'] ?? '' ) ),
		'starts_at'       => sanitize_text_field( (string) ( $campaign['starts_at'] ?? '' ) ),
		'ends_at'         => sanitize_text_field( (string) ( $campaign['ends_at'] ?? '' ) ),
		'productIds'      => papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() ),
		'discountPercent' => papelito_flash_sale_clamp_discount( $campaign['discountPercent'] ?? 0 ),
	);

	return hash( 'sha256', (string) wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/**
 * Retorna uma chave exclusiva para assinar contextos promocionais.
 */
function papelito_flash_sale_context_signing_key(): string {
	return hash_hmac( 'sha256', 'papelito-flash-sale-context-v1', wp_salt( 'auth' ) );
}

/**
 * Cria prova assinada de que o item foi adicionado pela vitrine ativa.
 *
 * @param array<string,mixed>|null $campaign Campanha normalizada.
 * @param int                      $product_id Produto da vitrine.
 * @return string
 */
function papelito_flash_sale_create_promotion_context( ?array $campaign, int $product_id ): string {
	if ( null === $campaign || 'active' !== ( $campaign['status'] ?? '' ) ) {
		return '';
	}

	if ( ! in_array( $product_id, papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() ), true ) ) {
		return '';
	}

	$ends_at = papelito_flash_sale_parse_datetime( (string) ( $campaign['ends_at'] ?? '' ) );
	if ( ! $ends_at instanceof DateTimeImmutable ) {
		return '';
	}

	$payload = array(
		'v'   => 1,
		'pid' => $product_id,
		'fp'  => papelito_flash_sale_campaign_fingerprint( $campaign ),
		'exp' => $ends_at->getTimestamp(),
	);
	$encoded = papelito_flash_sale_base64url_encode(
		(string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
	$signature = hash_hmac( 'sha256', $encoded, papelito_flash_sale_context_signing_key(), true );

	return $encoded . '.' . papelito_flash_sale_base64url_encode( $signature );
}

/**
 * Resolve a campanha ativa para um produto sem confiar no navegador.
 *
 * @param int $product_id Produto consultado.
 * @return array<string,mixed>|null
 */
function papelito_flash_sale_get_active_campaign_for_product( int $product_id ): ?array {
	$campaign = papelito_flash_sale_normalize_campaign( papelito_flash_sale_get_raw_campaign() );

	if ( null === $campaign || 'active' !== ( $campaign['status'] ?? '' ) ) {
		return null;
	}

	return in_array( $product_id, papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() ), true )
		? $campaign
		: null;
}

/**
 * Valida a prova assinada contra a campanha ativa atual.
 *
 * @param string $context Contexto enviado pela linha do carrinho.
 * @param int    $product_id Produto esperado.
 * @return array<string,mixed>|WP_Error
 */
function papelito_flash_sale_resolve_promotion_context( string $context, int $product_id ) {
	if ( '' === $context || strlen( $context ) > 2048 || 2 !== count( explode( '.', $context ) ) ) {
		return new WP_Error( 'papelito_promotion_context_invalid', 'A oferta deste item não pôde ser validada.', array( 'status' => 409 ) );
	}

	list( $encoded, $encoded_signature ) = explode( '.', $context, 2 );
	$signature                           = papelito_flash_sale_base64url_decode( $encoded_signature );
	$expected                            = hash_hmac( 'sha256', $encoded, papelito_flash_sale_context_signing_key(), true );

	if ( ! is_string( $signature ) || ! hash_equals( $expected, $signature ) ) {
		return new WP_Error( 'papelito_promotion_context_invalid', 'A oferta deste item não pôde ser validada.', array( 'status' => 409 ) );
	}

	$decoded = papelito_flash_sale_base64url_decode( $encoded );
	$payload = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
	if ( ! is_array( $payload ) || 1 !== (int) ( $payload['v'] ?? 0 ) ) {
		return new WP_Error( 'papelito_promotion_context_invalid', 'A oferta deste item não pôde ser validada.', array( 'status' => 409 ) );
	}

	if ( $product_id <= 0 || $product_id !== (int) ( $payload['pid'] ?? 0 ) ) {
		return new WP_Error( 'papelito_promotion_context_product_mismatch', 'A oferta não pertence a este produto.', array( 'status' => 409 ) );
	}

	if ( (int) ( $payload['exp'] ?? 0 ) < time() ) {
		return new WP_Error( 'papelito_promotion_context_expired', 'A oferta deste item expirou.', array( 'status' => 409 ) );
	}

	$campaign = papelito_flash_sale_get_active_campaign_for_product( $product_id );
	if ( null === $campaign ) {
		return new WP_Error( 'papelito_promotion_context_expired', 'A oferta deste item expirou.', array( 'status' => 409 ) );
	}

	if ( ! hash_equals( papelito_flash_sale_campaign_fingerprint( $campaign ), (string) ( $payload['fp'] ?? '' ) ) ) {
		return new WP_Error( 'papelito_promotion_context_stale', 'A campanha deste item foi alterada.', array( 'status' => 409 ) );
	}

	return $campaign;
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
 * Resolve produto publicado pelo ID. Retorna null caso nao exista ou esteja despublicado.
 *
 * @param int $product_id ID do produto.
 * @return WC_Product|null
 */
function papelito_flash_sale_load_product( int $product_id ): ?WC_Product {
	if ( $product_id <= 0 ) {
		return null;
	}

	$product = wc_get_product( $product_id );

	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	if ( 'publish' !== $product->get_status() ) {
		return null;
	}

	if ( function_exists( 'papelito_product_has_valid_weight' ) && ! papelito_product_has_valid_weight( $product ) ) {
		return null;
	}

	return $product;
}

/**
 * Mapeia um produto elegível para o seletor administrativo.
 *
 * @param WC_Product $product Produto publicado e elegível.
 * @return array<string,mixed>
 */
function papelito_flash_sale_build_admin_candidate( WC_Product $product ): array {
	$image_id   = (int) $product->get_image_id();
	$image_url  = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
	$categories = wc_get_product_terms( $product->get_id(), 'product_cat', array( 'fields' => 'all' ) );
	$tags       = wc_get_product_terms( $product->get_id(), 'product_tag', array( 'fields' => 'all' ) );
	$map_term   = static function ( $term ): array {
		return array(
			'id'     => isset( $term->term_id ) ? (int) $term->term_id : (int) ( $term->id ?? 0 ),
			'name'   => sanitize_text_field( (string) ( $term->name ?? '' ) ),
			'parent' => isset( $term->parent ) ? (int) $term->parent : 0,
			'slug'   => sanitize_title( (string) ( $term->slug ?? '' ) ),
		);
	};

	return array(
		'id'            => (int) $product->get_id(),
		'name'          => sanitize_text_field( (string) $product->get_name() ),
		'sku'           => sanitize_text_field( (string) $product->get_sku() ),
		'status'        => sanitize_key( (string) $product->get_status() ),
		'type'          => method_exists( $product, 'get_type' ) ? sanitize_key( (string) $product->get_type() ) : 'simple',
		'price'         => (string) $product->get_price( 'edit' ),
		'regularPrice'  => (string) $product->get_regular_price( 'edit' ),
		'permalink'     => (string) get_permalink( $product->get_id() ),
		'weight'        => method_exists( $product, 'get_weight' ) ? (string) $product->get_weight( 'edit' ) : '',
		'stockStatus'   => method_exists( $product, 'get_stock_status' ) ? sanitize_key( (string) $product->get_stock_status() ) : 'instock',
		'stockQuantity' => method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null,
		'dateModified'  => method_exists( $product, 'get_date_modified' ) && $product->get_date_modified()
			? $product->get_date_modified()->date( DATE_ATOM )
			: '',
		'images'        => $image_url
			? array(
				array(
					'id'       => $image_id,
					'src'      => (string) $image_url,
					'alt'      => sanitize_text_field( (string) $product->get_name() ),
					'position' => 0,
				),
			)
			: array(),
		'categories'    => array_values( array_map( $map_term, is_array( $categories ) ? $categories : array() ) ),
		'tags'          => array_values( array_map( $map_term, is_array( $tags ) ? $tags : array() ) ),
	);
}

/**
 * Consulta produtos elegíveis usando a mesma regra aplicada ao salvar.
 *
 * @param array<string,mixed> $args Filtros de busca.
 * @return array{items:array<int,array<string,mixed>>,page:int,perPage:int,total:int,totalPages:int}
 */
function papelito_flash_sale_query_eligible_products( array $args = array() ): array {
	$page       = max( 1, absint( $args['page'] ?? 1 ) );
	$per_page   = min( 100, max( 1, absint( $args['per_page'] ?? 24 ) ) );
	$search     = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
	$category   = absint( $args['category'] ?? 0 );
	$query_args = array(
		'post_type'              => 'product',
		'post_status'            => 'publish',
		'fields'                 => 'ids',
		'posts_per_page'         => $per_page,
		'paged'                  => $page,
		'orderby'                => 'modified',
		'order'                  => 'DESC',
		'no_found_rows'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	if ( $category > 0 ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $category,
			),
		);
	}

	$search_filter      = null;
	$eligibility_filter = static function ( string $where ): string {
		global $wpdb;

		return $where . "
			AND (
				EXISTS (
					SELECT 1
					FROM {$wpdb->postmeta} flash_weight
					WHERE flash_weight.post_id = {$wpdb->posts}.ID
						AND flash_weight.meta_key = '_weight'
						AND CAST(flash_weight.meta_value AS DECIMAL(20,6)) > 0
				)
				OR EXISTS (
					SELECT 1
					FROM {$wpdb->posts} flash_variation
					INNER JOIN {$wpdb->postmeta} flash_variation_weight
						ON flash_variation_weight.post_id = flash_variation.ID
					WHERE flash_variation.post_parent = {$wpdb->posts}.ID
						AND flash_variation.post_type = 'product_variation'
						AND flash_variation.post_status = 'publish'
						AND flash_variation_weight.meta_key = '_weight'
						AND CAST(flash_variation_weight.meta_value AS DECIMAL(20,6)) > 0
				)
			)
		";
	};
	add_filter( 'posts_where', $eligibility_filter, 20, 1 );

	if ( '' !== $search ) {
		$query_args['s'] = $search;
		$search_filter = static function ( string $where ) use ( $search ): string {
			global $wpdb;

			$like = '%' . $wpdb->esc_like( $search ) . '%';
			if ( ctype_digit( $search ) ) {
				return $wpdb->prepare(
					" AND ( {$wpdb->posts}.ID = %d OR {$wpdb->posts}.post_title LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} flash_sku WHERE flash_sku.post_id = {$wpdb->posts}.ID AND flash_sku.meta_key = '_sku' AND flash_sku.meta_value LIKE %s ) ) ",
					absint( $search ),
					$like,
					$like
				);
			}

			return $wpdb->prepare(
				" AND ( {$wpdb->posts}.post_title LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} flash_sku WHERE flash_sku.post_id = {$wpdb->posts}.ID AND flash_sku.meta_key = '_sku' AND flash_sku.meta_value LIKE %s ) ) ",
				$like,
				$like
			);
		};
		add_filter( 'posts_search', $search_filter, 20, 1 );
	}

	$query = new WP_Query( $query_args );
	$total = (int) $query->found_posts;
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$safe_page   = min( $page, $total_pages );

	if ( $safe_page !== $page ) {
		$query_args['paged'] = $safe_page;
		$query               = new WP_Query( $query_args );
	}

	if ( null !== $search_filter ) {
		remove_filter( 'posts_search', $search_filter, 20 );
	}
	remove_filter( 'posts_where', $eligibility_filter, 20 );

	$eligible = array();
	foreach ( (array) $query->posts as $product_id ) {
		$product = papelito_flash_sale_load_product( (int) $product_id );
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		if ( '' !== $search ) {
			$id_matches   = ctype_digit( $search ) && (int) $search === (int) $product->get_id();
			$name_matches = false !== stripos( (string) $product->get_name(), $search );
			$sku_matches  = false !== stripos( (string) $product->get_sku(), $search );
			if ( ! $id_matches && ! $name_matches && ! $sku_matches ) {
				continue;
			}
		}

		$eligible[] = $product;
	}

	return array(
		'items'      => array_values(
			array_map(
				'papelito_flash_sale_build_admin_candidate',
				$eligible
			)
		),
		'page'       => $safe_page,
		'perPage'    => $per_page,
		'total'      => $total,
		'totalPages' => $total_pages,
	);
}

/**
 * Monta payload do produto aplicando o desconto da campanha.
 *
 * @param WC_Product $product          Produto.
 * @param int        $discount_percent Percentual aplicado.
 * @return array<string, mixed>
 */
function papelito_flash_sale_build_product_payload( WC_Product $product, int $discount_percent ): array {
	$discount       = papelito_flash_sale_clamp_discount( $discount_percent );
	$image_id       = (int) $product->get_image_id();
	$image_url      = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'large' ) : '';
	$categories     = wc_get_product_terms( $product->get_id(), 'product_cat', array( 'fields' => 'all' ) );
	$tags           = wc_get_product_terms( $product->get_id(), 'product_tag', array( 'fields' => 'all' ) );
	$regular_price  = papelito_flash_sale_to_float( $product->get_regular_price( 'edit' ) );
	$current_price  = papelito_flash_sale_to_float( $product->get_price( 'edit' ) );
	$base_price     = $regular_price > 0 ? $regular_price : $current_price;
	$campaign_price = $base_price > 0
		? round( $base_price * ( 100 - $discount ) / 100, 2 )
		: 0.0;
	$category_label = ! empty( $categories ) && isset( $categories[0]->name ) ? (string) $categories[0]->name : 'Produto';
	$badge_label    = ! empty( $tags ) && isset( $tags[0]->name ) ? (string) $tags[0]->name : 'Destaque';

	return array(
		'id'            => (string) $product->get_id(),
		'productId'     => $product->get_id(),
		'name'          => $product->get_name(),
		'sku'           => (string) $product->get_sku(),
		'category'      => $category_label,
		'badge'         => $badge_label,
		'discount'      => $discount,
		'originalPrice' => $base_price,
		'price'         => $campaign_price,
		'rating'        => (float) $product->get_average_rating(),
		'reviews'       => (int) $product->get_review_count(),
		'image'         => $image_url ? $image_url : '',
		'permalink'     => (string) get_permalink( $product->get_id() ),
		'status'        => (string) $product->get_status(),
		'hasImage'      => '' !== ( $image_url ? $image_url : '' ),
	);
}

/**
 * Lista issues (avisos) da campanha salva.
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

	if ( empty( $product_ids ) ) {
		$issues[] = 'Nenhum produto selecionado para a campanha.';
	}

	foreach ( $product_ids as $product_id ) {
		$product = papelito_flash_sale_load_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			$issues[] = sprintf( 'Produto %d não encontrado ou despublicado.', $product_id );
			continue;
		}

		$image_id = (int) $product->get_image_id();

		if ( $image_id <= 0 ) {
			$issues[] = sprintf( '%s (#%d) está sem imagem principal.', $product->get_name(), $product_id );
		}

		$regular = papelito_flash_sale_to_float( $product->get_regular_price( 'edit' ) );
		$current = papelito_flash_sale_to_float( $product->get_price( 'edit' ) );

		if ( $regular <= 0 && $current <= 0 ) {
			$issues[] = sprintf( '%s (#%d) está sem preço base cadastrado.', $product->get_name(), $product_id );
		}
	}

	return $issues;
}

/**
 * Verifica se o usuário autenticado atual é seller.
 *
 * @return bool
 */
function papelito_flash_sale_current_user_is_seller(): bool {
	$current_user = wp_get_current_user();

	if ( ! ( $current_user instanceof WP_User ) ) {
		return false;
	}

	return in_array( 'seller', (array) $current_user->roles, true );
}

/**
 * Dispara os eventos de promoção da campanha ativa.
 *
 * @param array<string, mixed> $campaign Campanha validada.
 * @return void
 */
function papelito_flash_sale_dispatch_promo_events( array $campaign ): void {
	$campaign = papelito_flash_sale_normalize_campaign( $campaign );

	if ( null === $campaign || 'active' !== $campaign['status'] ) {
		return;
	}

	$promo_label = trim( (string) ( $campaign['title'] ?? '' ) );

	if ( '' === $promo_label ) {
		$promo_label = trim( (string) ( $campaign['label'] ?? 'Oferta Relâmpago' ) );
	}

	foreach ( papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() ) as $product_id ) {
		$product = papelito_flash_sale_load_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$product_payload = papelito_flash_sale_build_product_payload( $product, (int) $campaign['discountPercent'] );

		do_action(
			'papelito_product_on_promo',
			(int) $product_id,
			array(
				'promo_type'      => 'flash_sale',
				'promo_label'     => '' !== $promo_label ? $promo_label : 'Oferta Relâmpago',
				'promo_event_key' => sprintf(
					'flash_sale:%d:%s',
					(int) $product_id,
					sanitize_text_field( (string) ( $campaign['starts_at'] ?? '' ) )
				),
				'discount_percent' => (int) ( $product_payload['discount'] ?? 0 ),
				'regular_price'    => $product_payload['originalPrice'] ?? null,
				'sale_price'       => $product_payload['price'] ?? null,
			)
		);
	}
}

/**
 * Remove qualquer disparo agendado da campanha atual.
 *
 * @return void
 */
function papelito_flash_sale_clear_scheduled_promo_event(): void {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( PAPELITO_FLASH_SALE_PROMO_HOOK );
	}
}

/**
 * Agenda o disparo da campanha para o início configurado, quando aplicável.
 *
 * @param array<string,mixed> $campaign Campanha validada.
 * @return void
 */
function papelito_flash_sale_sync_promo_schedule( array $campaign ): void {
	papelito_flash_sale_clear_scheduled_promo_event();

	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		return;
	}

	$campaign = papelito_flash_sale_normalize_campaign( $campaign );

	if ( null === $campaign || 'scheduled' !== $campaign['status'] ) {
		return;
	}

	$starts_at = papelito_flash_sale_parse_datetime( (string) ( $campaign['starts_at'] ?? '' ) );

	if ( ! $starts_at instanceof DateTimeImmutable ) {
		return;
	}

	wp_schedule_single_event( $starts_at->getTimestamp(), PAPELITO_FLASH_SALE_PROMO_HOOK );
}

/**
 * Processa a campanha ativa quando chega o horário agendado.
 *
 * @return void
 */
function papelito_flash_sale_dispatch_scheduled_promo_events(): void {
	papelito_flash_sale_dispatch_promo_events( papelito_flash_sale_get_raw_campaign() );
}
add_action( PAPELITO_FLASH_SALE_PROMO_HOOK, 'papelito_flash_sale_dispatch_scheduled_promo_events' );

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
			$selected_products[] = papelito_flash_sale_build_product_payload( $product, $campaign['discountPercent'] );
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
 * @param array<string, mixed> $input  JSON recebido.
 * @param array<string, mixed> $stored Campanha salva (para preservar defaults).
 * @return array<string, mixed>|WP_Error
 */
function papelito_flash_sale_validate_input( array $input, array $stored ) {
	$title         = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	$product_ids   = papelito_flash_sale_normalize_product_ids( $input['productIds'] ?? array() );
	$starts_at_raw = sanitize_text_field( (string) ( $input['startsAt'] ?? '' ) );
	$ends_at_raw   = sanitize_text_field( (string) ( $input['endsAt'] ?? '' ) );
	$starts_at     = papelito_flash_sale_parse_datetime( $starts_at_raw );
	$ends_at       = papelito_flash_sale_parse_datetime( $ends_at_raw );
	$discount      = papelito_flash_sale_clamp_discount( $input['discountPercent'] ?? ( $stored['discountPercent'] ?? 0 ) );
	$label         = sanitize_text_field( (string) ( $input['label'] ?? ( $stored['label'] ?? '' ) ) );
	$supporting    = sanitize_textarea_field( (string) ( $input['supportingText'] ?? ( $stored['supportingText'] ?? '' ) ) );

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
		$product = papelito_flash_sale_load_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'papelito_flash_sale_product_unpublished',
				sprintf( 'Produto %d precisa existir e estar publicado.', $product_id ),
				array( 'status' => 422 )
			);
		}
	}

	return array(
		'title'           => $title,
		'slug'            => '' !== sanitize_title( $title ) ? sanitize_title( $title ) : 'oferta-relampago',
		'starts_at'       => papelito_flash_sale_format_datetime( $starts_at ),
		'ends_at'         => papelito_flash_sale_format_datetime( $ends_at ),
		'productIds'      => $product_ids,
		'discountPercent' => $discount,
		'label'           => '' !== $label ? $label : 'Oferta Relâmpago',
		'supportingText'  => $supporting,
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
					if ( papelito_flash_sale_current_user_is_seller() ) {
						return new WP_REST_Response( (object) array(), 200 );
					}

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
						$product = papelito_flash_sale_load_product( $product_id );

						if ( $product instanceof WC_Product ) {
							$product_payload                     = papelito_flash_sale_build_product_payload( $product, $campaign['discountPercent'] );
							$product_payload['promotionContext'] = papelito_flash_sale_create_promotion_context( $campaign, $product_id );
							$products[]                          = $product_payload;
						}
					}

					if ( empty( $products ) ) {
						return new WP_REST_Response(
							array(
								'code'    => 'papelito_flash_sale_no_valid_products',
								'message' => 'Nenhum produto válido na campanha.',
							),
							404
						);
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
			'/flash-sale/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'page'     => array( 'sanitize_callback' => 'absint', 'default' => 1 ),
					'per_page' => array( 'sanitize_callback' => 'absint', 'default' => 24 ),
					'search'   => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					'category' => array( 'sanitize_callback' => 'absint', 'default' => 0 ),
				),
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					return new WP_REST_Response(
						papelito_flash_sale_query_eligible_products(
							array(
								'page'     => $request->get_param( 'page' ),
								'per_page' => $request->get_param( 'per_page' ),
								'search'   => $request->get_param( 'search' ),
								'category' => $request->get_param( 'category' ),
							)
						),
						200
					);
				},
			),
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
					$stored    = papelito_flash_sale_get_raw_campaign();
					$validated = papelito_flash_sale_validate_input( $payload, $stored );

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
					papelito_flash_sale_sync_promo_schedule( $validated );
					papelito_flash_sale_dispatch_promo_events( $validated );

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
					papelito_flash_sale_clear_scheduled_promo_event();
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
