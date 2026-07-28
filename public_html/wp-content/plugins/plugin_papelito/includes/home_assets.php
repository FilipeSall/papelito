<?php
/**
 * Assets administraveis da home.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nome da option dos banners hero.
 *
 * @return string
 */
function papelito_home_assets_hero_option_name(): string {
	return 'papelito_home_hero_banners';
}

/**
 * Nome da option do promo banner.
 *
 * @return string
 */
function papelito_home_assets_promo_option_name(): string {
	return 'papelito_home_promo_banner';
}

/**
 * Nome da option do partner banner.
 *
 * @return string
 */
function papelito_home_assets_partner_option_name(): string {
	return 'papelito_home_partner_banner';
}

/**
 * Nome da option das imagens administraveis do site.
 *
 * @return string
 */
function papelito_home_assets_site_images_option_name(): string {
	return 'papelito_site_image_assets';
}

/**
 * Gera um ID para item de hero.
 *
 * @return string
 */
function papelito_home_assets_generate_item_id(): string {
	if ( function_exists( 'wp_generate_uuid4' ) ) {
		return wp_generate_uuid4();
	}

	return uniqid( 'hero_', true );
}

/**
 * Sanitiza string simples.
 *
 * @param mixed $value Valor arbitrario.
 * @return string
 */
function papelito_home_assets_clean_text( $value ): string {
	return sanitize_text_field( (string) $value );
}

/**
 * Sanitiza texto de descricao.
 *
 * @param mixed $value Valor arbitrario.
 * @return string
 */
function papelito_home_assets_clean_textarea( $value ): string {
	return sanitize_textarea_field( (string) $value );
}

/**
 * Normaliza booleano.
 *
 * @param mixed $value Valor arbitrario.
 * @return bool
 */
function papelito_home_assets_to_bool( $value ): bool {
	return rest_sanitize_boolean( $value );
}

/**
 * Normaliza href interno.
 *
 * @param mixed $value Valor bruto.
 * @return string
 */
function papelito_home_assets_normalize_href( $value ): string {
	$href = trim( (string) $value );

	if ( '' === $href ) {
		return '';
	}

	if ( ! preg_match( '#^/(?!/)#', $href ) ) {
		return '';
	}

	return esc_url_raw( $href );
}

/**
 * Normaliza URL de imagem local ou absoluta.
 *
 * @param mixed $value URL bruta.
 * @return string
 */
function papelito_home_assets_normalize_image_url( $value ): string {
	$url = trim( (string) $value );

	if ( '' === $url ) {
		return '';
	}

	if ( preg_match( '#^/(?!/)#', $url ) || wp_http_validate_url( $url ) ) {
		return esc_url_raw( $url );
	}

	return '';
}

/**
 * Resolve URL publica de uma imagem.
 *
 * @param int $attachment_id ID do attachment.
 * @return string
 */
function papelito_home_assets_resolve_image_url( int $attachment_id, string $fallback_url = '' ): string {
	if ( $attachment_id <= 0 ) {
		return papelito_home_assets_normalize_image_url( $fallback_url );
	}

	$image_url = wp_get_attachment_image_url( $attachment_id, 'full' );
	return is_string( $image_url ) ? $image_url : papelito_home_assets_normalize_image_url( $fallback_url );
}

/**
 * Defaults dos banners hero atuais.
 *
 * @return array<int, array<string, mixed>>
 */
function papelito_home_assets_default_hero_items(): array {
	$desktop = array(
		'/images/hero-section/Banners-Marketplace-B2B-01.png',
		'/images/hero-section/Banners-Marketplace-B2B-02.png',
		'/images/hero-section/Banners-Marketplace-B2B-03.png',
		'/images/hero-section/Banners-Marketplace-B2B-04.png',
	);
	$mobile  = array(
		'/images/hero-section/Banners-Marketplace-B2B-07_-Mobile.png',
		'/images/hero-section/Banners-Marketplace-B2B-08--Mobile.png',
		'/images/hero-section/Banners-Marketplace-B2B-09--Mobile.png',
		'/images/hero-section/Banners-Marketplace-B2B-10---Mobile.png',
	);

	return array_map(
		static function ( string $desktop_url, int $index ) use ( $mobile ): array {
			return array(
				'id'              => 'default-hero-' . ( $index + 1 ),
				'desktopImageId'  => 0,
				'desktopImageUrl' => $desktop_url,
				'mobileImageId'   => 0,
				'mobileImageUrl'  => $mobile[ $index ] ?? $desktop_url,
				'alt'             => 'Banner Papelito Marketplace B2B ' . ( $index + 1 ),
				'href'            => '',
				'order'           => $index + 1,
				'isActive'        => true,
			);
		},
		$desktop,
		array_keys( $desktop )
	);
}

/**
 * Default do banner PDV Perfeito da home.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_default_partner_banner(): array {
	return array(
		'tag'             => 'Seja um parceiro',
		'description'     => 'Junte-se ao nosso PDV Perfeito com lojistas em todo o Brasil. '
			. 'Receba brindes, prêmios e benefícios exclusivos',
		'ctaLabel'        => 'Quero ser um parceiro',
		'href'            => '/revendedor',
		'desktopImageId'  => 0,
		'desktopImageUrl' => '/images/CT1A3510%201.png',
		'mobileImageId'   => 0,
		'mobileImageUrl'  => '/images/pdv-mobile.jpg',
		'alt'             => 'Parceiros no espaço PDV Perfeito Papelito.',
		'isActive'        => true,
	);
}

/**
 * Defaults das imagens administraveis das paginas.
 *
 * @return array<string, array<string, mixed>>
 */
function papelito_home_assets_default_site_images(): array {
	return array(
		'productHero'                   => array(
			'imageId'  => 0,
			'imageUrl' => '/images/Rectangle21.png',
			'alt'      => 'Produtos Papelito - Made in Brazil.',
		),
		'aboutHero'                     => array(
			'imageId'  => 0,
			'imageUrl' => '/images/sobre-page/sobre-banner.png',
			'alt'      => 'Mulher sorrindo e segurando papéis Papelito diante de um fundo amarelo.',
		),
		'aboutStory'                    => array(
			'imageId'  => 0,
			'imageUrl' => '/images/sobre-page/fabrica-papelito.jpg',
			'alt'      => 'Sócios da Papelito em pé diante da linha de produção da fábrica.',
		),
		'revendedorBusinessMain'        => array(
			'imageId'  => 0,
			'imageUrl' => '/images/revendedor/business-main.jpg',
			'alt'      => 'Parceira Papelito sorrindo em um ponto de venda.',
		),
		'revendedorBusinessSecondary'   => array(
			'imageId'  => 0,
			'imageUrl' => '/images/revendedor/business-secondary.jpg',
			'alt'      => 'Equipe parceira Papelito em loja.',
		),
		'revendedorBusinessIllustration' => array(
			'imageId'  => 0,
			'imageUrl' => '/images/revendedor/business-card-vector.svg',
			'alt'      => 'Ilustração de atendimento a negócios revendedores.',
		),
	);
}

/**
 * Busca option hero.
 *
 * @return array<int, mixed>
 */
function papelito_home_assets_get_raw_hero_items(): array {
	$value = get_option( papelito_home_assets_hero_option_name(), array() );
	if ( ! is_array( $value ) || empty( $value ) ) {
		return papelito_home_assets_default_hero_items();
	}

	return array_values( $value );
}

/**
 * Busca option promo.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_raw_promo_banner(): array {
	$value = get_option( papelito_home_assets_promo_option_name(), array() );
	return is_array( $value ) ? $value : array();
}

/**
 * Busca option partner.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_raw_partner_banner(): array {
	$value = get_option( papelito_home_assets_partner_option_name(), array() );
	return is_array( $value ) && ! empty( $value ) ? $value : papelito_home_assets_default_partner_banner();
}

/**
 * Busca option das imagens administraveis.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_raw_site_images(): array {
	$value = get_option( papelito_home_assets_site_images_option_name(), array() );
	return is_array( $value ) ? $value : array();
}

/**
 * Normaliza item hero.
 *
 * @param array<string, mixed> $item Item armazenado.
 * @param int                  $index Posicao.
 * @return array<string, mixed>
 */
function papelito_home_assets_normalize_hero_item( array $item, int $index ): array {
	$desktop_image_id = absint( $item['desktopImageId'] ?? 0 );
	$mobile_image_id  = absint( $item['mobileImageId'] ?? 0 );
	$desktop_url      = papelito_home_assets_resolve_image_url(
		$desktop_image_id,
		(string) ( $item['desktopImageUrl'] ?? '' )
	);
	$mobile_url       = papelito_home_assets_resolve_image_url(
		$mobile_image_id,
		(string) ( $item['mobileImageUrl'] ?? '' )
	);
	$id               = sanitize_key( (string) ( $item['id'] ?? '' ) );

	if ( '' === $id ) {
		$id = papelito_home_assets_generate_item_id();
	}

	return array(
		'id'              => $id,
		'desktopImageId'  => $desktop_image_id,
		'desktopImageUrl' => $desktop_url,
		'mobileImageId'   => $mobile_image_id,
		'mobileImageUrl'  => $mobile_url,
		'alt'             => papelito_home_assets_clean_text( $item['alt'] ?? '' ),
		'href'            => papelito_home_assets_normalize_href( $item['href'] ?? '' ),
		'order'           => $index + 1,
		'isActive'        => papelito_home_assets_to_bool( $item['isActive'] ?? true ),
	);
}

/**
 * Determina se item hero esta completo para publicacao.
 *
 * @param array<string, mixed> $item Item normalizado.
 * @return bool
 */
function papelito_home_assets_is_complete_hero_item( array $item ): bool {
	return ! empty( $item['desktopImageUrl'] )
		&& ! empty( $item['mobileImageUrl'] )
		&& '' !== (string) $item['alt'];
}

/**
 * Normaliza promo banner.
 *
 * @param array<string, mixed> $banner Banner salvo.
 * @return array<string, mixed>
 */
function papelito_home_assets_normalize_promo_banner( array $banner ): array {
	return array(
		'ctaLabel' => papelito_home_assets_clean_text( $banner['ctaLabel'] ?? '' ),
		'href'     => papelito_home_assets_normalize_href( $banner['href'] ?? '' ),
		'isActive' => papelito_home_assets_to_bool( $banner['isActive'] ?? false ),
	);
}

/**
 * Determina se promo banner esta completo para publicacao.
 *
 * @param array<string, mixed> $banner Banner normalizado.
 * @return bool
 */
function papelito_home_assets_is_complete_promo_banner( array $banner ): bool {
	return '' !== (string) $banner['ctaLabel'] && '' !== (string) $banner['href'];
}

/**
 * Normaliza partner banner.
 *
 * @param array<string, mixed> $banner Banner salvo.
 * @return array<string, mixed>
 */
function papelito_home_assets_normalize_partner_banner( array $banner ): array {
	$defaults         = papelito_home_assets_default_partner_banner();
	$desktop_image_id = absint( $banner['desktopImageId'] ?? 0 );
	$mobile_image_id  = absint( $banner['mobileImageId'] ?? 0 );
	$raw_desktop_url  = papelito_home_assets_normalize_image_url( $banner['desktopImageUrl'] ?? '' );
	$raw_mobile_url   = papelito_home_assets_normalize_image_url( $banner['mobileImageUrl'] ?? '' );
	$desktop_url      = papelito_home_assets_resolve_image_url(
		$desktop_image_id,
		'' !== $raw_desktop_url ? $raw_desktop_url : (string) $defaults['desktopImageUrl']
	);
	$mobile_url       = papelito_home_assets_resolve_image_url(
		$mobile_image_id,
		'' !== $raw_mobile_url ? $raw_mobile_url : (string) $defaults['mobileImageUrl']
	);

	return array(
		'tag'             => papelito_home_assets_clean_text( $banner['tag'] ?? $defaults['tag'] ),
		'description'     => papelito_home_assets_clean_textarea( $banner['description'] ?? $defaults['description'] ),
		'ctaLabel'        => papelito_home_assets_clean_text( $banner['ctaLabel'] ?? $defaults['ctaLabel'] ),
		'href'            => papelito_home_assets_normalize_href( $banner['href'] ?? $defaults['href'] ),
		'desktopImageId'  => $desktop_image_id,
		'desktopImageUrl' => $desktop_url,
		'mobileImageId'   => $mobile_image_id,
		'mobileImageUrl'  => $mobile_url,
		'alt'             => papelito_home_assets_clean_text( $banner['alt'] ?? $defaults['alt'] ),
		'isActive'        => papelito_home_assets_to_bool( $banner['isActive'] ?? true ),
	);
}

/**
 * Determina se partner banner esta completo para publicacao.
 *
 * @param array<string, mixed> $banner Banner normalizado.
 * @return bool
 */
function papelito_home_assets_is_complete_partner_banner( array $banner ): bool {
	return '' !== (string) $banner['tag']
		&& '' !== (string) $banner['description']
		&& '' !== (string) $banner['ctaLabel']
		&& '' !== (string) $banner['href']
		&& ! empty( $banner['desktopImageUrl'] )
		&& ! empty( $banner['mobileImageUrl'] )
		&& '' !== (string) $banner['alt'];
}

/**
 * Lista issues administrativas do hero.
 *
 * @param array<int, array<string, mixed>> $items Hero normalizado.
 * @return array<int, string>
 */
function papelito_home_assets_collect_hero_issues( array $items ): array {
	$issues = array();

	foreach ( $items as $index => $item ) {
		if ( ! papelito_home_assets_to_bool( $item['isActive'] ?? false ) ) {
			continue;
		}

		if ( ! papelito_home_assets_is_complete_hero_item( $item ) ) {
			$issues[] = sprintf( 'Hero #%d esta ativo, mas ainda não tem desktop, mobile e alt completos.', $index + 1 );
		}
	}

	return $issues;
}

/**
 * Lista issues administrativas do promo.
 *
 * @param array<string, mixed> $banner Banner normalizado.
 * @return array<int, string>
 */
function papelito_home_assets_collect_promo_issues( array $banner ): array {
	if ( ! papelito_home_assets_to_bool( $banner['isActive'] ?? false ) ) {
		return array();
	}

	if ( papelito_home_assets_is_complete_promo_banner( $banner ) ) {
		return array();
	}

	return array( 'Promo banner esta ativo, mas ainda não tem CTA e href completos.' );
}

/**
 * Lista issues administrativas do partner.
 *
 * @param array<string, mixed> $banner Banner normalizado.
 * @return array<int, string>
 */
function papelito_home_assets_collect_partner_issues( array $banner ): array {
	if ( ! papelito_home_assets_to_bool( $banner['isActive'] ?? false ) ) {
		return array();
	}

	if ( papelito_home_assets_is_complete_partner_banner( $banner ) ) {
		return array();
	}

	return array( 'Partner banner esta ativo, mas ainda não tem textos, href e imagens completos.' );
}

/**
 * Snapshot admin do hero.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_admin_hero_snapshot(): array {
	$items = array_map(
		'papelito_home_assets_normalize_hero_item',
		papelito_home_assets_get_raw_hero_items(),
		array_keys( papelito_home_assets_get_raw_hero_items() )
	);

	return array(
		'banners' => $items,
		'issues'  => papelito_home_assets_collect_hero_issues( $items ),
	);
}

/**
 * Snapshot admin do promo.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_admin_promo_snapshot(): array {
	$banner = papelito_home_assets_normalize_promo_banner( papelito_home_assets_get_raw_promo_banner() );

	return array(
		'banner' => $banner,
		'issues' => papelito_home_assets_collect_promo_issues( $banner ),
	);
}

/**
 * Snapshot admin do partner.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_admin_partner_snapshot(): array {
	$banner = papelito_home_assets_normalize_partner_banner( papelito_home_assets_get_raw_partner_banner() );

	return array(
		'banner' => $banner,
		'issues' => papelito_home_assets_collect_partner_issues( $banner ),
	);
}

/**
 * Normaliza uma imagem administravel.
 *
 * @param string               $key Chave da imagem.
 * @param array<string, mixed> $image Imagem salva.
 * @return array<string, mixed>
 */
function papelito_home_assets_normalize_site_image( string $key, array $image ): array {
	$defaults = papelito_home_assets_default_site_images();
	$default  = $defaults[ $key ] ?? array(
		'imageId'  => 0,
		'imageUrl' => '',
		'alt'      => '',
	);
	$image_id = absint( $image['imageId'] ?? 0 );
	$image_url = papelito_home_assets_normalize_image_url( $image['imageUrl'] ?? '' );

	return array(
		'imageId'  => $image_id,
		'imageUrl' => papelito_home_assets_resolve_image_url(
			$image_id,
			'' !== $image_url ? $image_url : (string) $default['imageUrl']
		),
		'alt'      => papelito_home_assets_clean_text( $image['alt'] ?? $default['alt'] ),
	);
}

/**
 * Normaliza todas as imagens administraveis.
 *
 * @param array<string, mixed> $images Imagens salvas.
 * @return array<string, array<string, mixed>>
 */
function papelito_home_assets_normalize_site_images( array $images ): array {
	$defaults   = papelito_home_assets_default_site_images();
	$normalized = array();

	foreach ( $defaults as $key => $default_image ) {
		$raw               = isset( $images[ $key ] ) && is_array( $images[ $key ] ) ? $images[ $key ] : $default_image;
		$normalized[ $key ] = papelito_home_assets_normalize_site_image( $key, $raw );
	}

	return $normalized;
}

/**
 * Lista issues administrativas das imagens.
 *
 * @param array<string, array<string, mixed>> $images Imagens normalizadas.
 * @return array<int, string>
 */
function papelito_home_assets_collect_site_images_issues( array $images ): array {
	$issues = array();

	foreach ( $images as $key => $image ) {
		if ( empty( $image['imageUrl'] ) || '' === (string) $image['alt'] ) {
			$issues[] = sprintf( 'Imagem %s precisa ter arquivo e alt preenchidos.', $key );
		}
	}

	return $issues;
}

/**
 * Snapshot admin das imagens.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_admin_site_images_snapshot(): array {
	$images = papelito_home_assets_normalize_site_images( papelito_home_assets_get_raw_site_images() );

	return array(
		'images' => $images,
		'issues' => papelito_home_assets_collect_site_images_issues( $images ),
	);
}

/**
 * Prepara resposta a partir de um WP_Error.
 *
 * @param WP_Error $error Erro.
 * @return WP_REST_Response
 */
function papelito_home_assets_rest_error_response( WP_Error $error ): WP_REST_Response {
	$error_data = $error->get_error_data();
	$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 422;

	return new WP_REST_Response(
		array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		),
		$status
	);
}

/**
 * Valida payload hero para escrita.
 *
 * @param mixed $input Valor recebido.
 * @return array<int, array<string, mixed>>|WP_Error
 */
function papelito_home_assets_validate_hero_payload( $input ) {
	if ( ! is_array( $input ) || empty( $input ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_hero_payload',
			'Hero Section precisa ter pelo menos uma opção.',
			array( 'status' => 422 )
		);
	}

	$validated = array();

	foreach ( array_values( $input ) as $index => $item ) {
		if ( ! is_array( $item ) ) {
			return new WP_Error(
				'papelito_home_assets_invalid_hero_item',
				sprintf( 'Hero #%d esta em formato inválido.', $index + 1 ),
				array( 'status' => 422 )
			);
		}

		$normalized = papelito_home_assets_normalize_hero_item( $item, $index );

		if ( $normalized['isActive'] && ! papelito_home_assets_is_complete_hero_item( $normalized ) ) {
			return new WP_Error(
				'papelito_home_assets_incomplete_hero_item',
				sprintf( 'Hero #%d ativo precisa ter imagens desktop/mobile e alt.', $index + 1 ),
				array( 'status' => 422 )
			);
		}

		$validated[] = array(
			'id'              => $normalized['id'],
			'desktopImageId'  => $normalized['desktopImageId'],
			'desktopImageUrl' => $normalized['desktopImageUrl'],
			'mobileImageId'   => $normalized['mobileImageId'],
			'mobileImageUrl'  => $normalized['mobileImageUrl'],
			'alt'             => $normalized['alt'],
			'href'            => '',
			'order'           => $normalized['order'],
			'isActive'        => true,
		);
	}

	return $validated;
}

/**
 * Valida payload das imagens administraveis.
 *
 * @param mixed $input Valor recebido.
 * @return array<string, array<string, mixed>>|WP_Error
 */
function papelito_home_assets_validate_site_images_payload( $input ) {
	if ( ! is_array( $input ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_site_images_payload',
			'Payload de imagens inválido.',
			array( 'status' => 422 )
		);
	}

	$normalized = papelito_home_assets_normalize_site_images( $input );
	$issues     = papelito_home_assets_collect_site_images_issues( $normalized );

	if ( ! empty( $issues ) ) {
		return new WP_Error(
			'papelito_home_assets_incomplete_site_images',
			implode( ' ', $issues ),
			array( 'status' => 422 )
		);
	}

	return $normalized;
}

/**
 * Valida payload promo para escrita.
 *
 * @param mixed $input Valor recebido.
 * @return array<string, mixed>|WP_Error
 */
function papelito_home_assets_validate_promo_payload( $input ) {
	if ( ! is_array( $input ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_promo_payload',
			'Payload do promo banner inválido.',
			array( 'status' => 422 )
		);
	}

	$normalized = papelito_home_assets_normalize_promo_banner( $input );

	if ( $normalized['isActive'] && ! papelito_home_assets_is_complete_promo_banner( $normalized ) ) {
		return new WP_Error(
			'papelito_home_assets_incomplete_promo_banner',
			'Promo banner ativo precisa ter CTA e href.',
			array( 'status' => 422 )
		);
	}

	if ( '' === $normalized['href'] && ! empty( $input['href'] ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_href',
			'Promo banner precisa usar apenas href interno.',
			array( 'status' => 422 )
		);
	}

	return array(
		'ctaLabel' => $normalized['ctaLabel'],
		'href'     => $normalized['href'],
		'isActive' => $normalized['isActive'],
	);
}

/**
 * Valida payload partner para escrita.
 *
 * @param mixed $input Valor recebido.
 * @return array<string, mixed>|WP_Error
 */
function papelito_home_assets_validate_partner_payload( $input ) {
	if ( ! is_array( $input ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_partner_payload',
			'Payload do partner banner inválido.',
			array( 'status' => 422 )
		);
	}

	$normalized = papelito_home_assets_normalize_partner_banner( $input );

	if ( $normalized['isActive'] && ! papelito_home_assets_is_complete_partner_banner( $normalized ) ) {
		return new WP_Error(
			'papelito_home_assets_incomplete_partner_banner',
			'Partner banner ativo precisa ter textos, href e imagens desktop/mobile.',
			array( 'status' => 422 )
		);
	}

	if ( '' === $normalized['href'] && ! empty( $input['href'] ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_href',
			'Partner banner precisa usar apenas href interno.',
			array( 'status' => 422 )
		);
	}

	return array(
		'tag'             => $normalized['tag'],
		'description'     => $normalized['description'],
		'ctaLabel'        => $normalized['ctaLabel'],
		'href'            => $normalized['href'],
		'desktopImageId'  => $normalized['desktopImageId'],
		'desktopImageUrl' => $normalized['desktopImageUrl'],
		'mobileImageId'   => $normalized['mobileImageId'],
		'mobileImageUrl'  => $normalized['mobileImageUrl'],
		'alt'             => $normalized['alt'],
		'isActive'        => true,
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/home/hero-banners',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$items = array_map(
						'papelito_home_assets_normalize_hero_item',
						papelito_home_assets_get_raw_hero_items(),
						array_keys( papelito_home_assets_get_raw_hero_items() )
					);

					$public_items = array_values(
						array_filter(
							$items,
							static function ( array $item ): bool {
								return ! empty( $item['isActive'] ) && papelito_home_assets_is_complete_hero_item( $item );
							}
						)
					);

					usort(
						$public_items,
						static function ( array $left, array $right ): int {
							return (int) $left['order'] <=> (int) $right['order'];
						}
					);

					if ( empty( $public_items ) ) {
						return new WP_REST_Response(
							array(
								'code'    => 'papelito_home_assets_no_active_hero',
								'message' => 'Nenhum hero banner ativo encontrado.',
							),
							404
						);
					}

					return new WP_REST_Response( array( 'banners' => $public_items ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/home/promo-banner',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$banner = papelito_home_assets_normalize_promo_banner( papelito_home_assets_get_raw_promo_banner() );

					if ( ! $banner['isActive'] || ! papelito_home_assets_is_complete_promo_banner( $banner ) ) {
						return new WP_REST_Response(
							array(
								'code'    => 'papelito_home_assets_no_active_promo',
								'message' => 'Promo banner inativo ou incompleto.',
							),
							404
						);
					}

					return new WP_REST_Response( array( 'banner' => $banner ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/home/partner-banner',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$banner = papelito_home_assets_normalize_partner_banner( papelito_home_assets_get_raw_partner_banner() );

					if ( ! $banner['isActive'] || ! papelito_home_assets_is_complete_partner_banner( $banner ) ) {
						return new WP_REST_Response(
							array(
								'code'    => 'papelito_home_assets_no_active_partner',
								'message' => 'Partner banner inativo ou incompleto.',
							),
							404
						);
					}

					return new WP_REST_Response( array( 'banner' => $banner ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/site/image-assets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response(
						array(
							'images' => papelito_home_assets_normalize_site_images(
								papelito_home_assets_get_raw_site_images()
							),
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/hero-banners',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_home_assets_get_admin_hero_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/hero-banners',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$params    = $request->get_json_params();
					$payload   = is_array( $params ) ? $params : array();
					$validated = papelito_home_assets_validate_hero_payload( $payload['banners'] ?? $payload );

					if ( is_wp_error( $validated ) ) {
						return papelito_home_assets_rest_error_response( $validated );
					}

					update_option( papelito_home_assets_hero_option_name(), $validated, false );

					return new WP_REST_Response( papelito_home_assets_get_admin_hero_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/site-images',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_home_assets_get_admin_site_images_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/site-images',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$params    = $request->get_json_params();
					$payload   = is_array( $params ) ? $params : array();
					$validated = papelito_home_assets_validate_site_images_payload( $payload['images'] ?? $payload );

					if ( is_wp_error( $validated ) ) {
						return papelito_home_assets_rest_error_response( $validated );
					}

					update_option( papelito_home_assets_site_images_option_name(), $validated, false );

					return new WP_REST_Response( papelito_home_assets_get_admin_site_images_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/promo-banner',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_home_assets_get_admin_promo_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/promo-banner',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$params    = $request->get_json_params();
					$payload   = is_array( $params ) ? $params : array();
					$validated = papelito_home_assets_validate_promo_payload( $payload['banner'] ?? $payload );

					if ( is_wp_error( $validated ) ) {
						return papelito_home_assets_rest_error_response( $validated );
					}

					update_option( papelito_home_assets_promo_option_name(), $validated, false );

					return new WP_REST_Response( papelito_home_assets_get_admin_promo_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/partner-banner',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_home_assets_get_admin_partner_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/partner-banner',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$params    = $request->get_json_params();
					$payload   = is_array( $params ) ? $params : array();
					$validated = papelito_home_assets_validate_partner_payload( $payload['banner'] ?? $payload );

					if ( is_wp_error( $validated ) ) {
						return papelito_home_assets_rest_error_response( $validated );
					}

					update_option( papelito_home_assets_partner_option_name(), $validated, false );

					return new WP_REST_Response( papelito_home_assets_get_admin_partner_snapshot(), 200 );
				},
			)
		);
	}
);
