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
 * Nome da option da faixa de avisos e promocoes.
 *
 * @return string
 */
function papelito_home_assets_promo_marquee_option_name(): string {
	return 'papelito_home_promo_marquee';
}

/**
 * Nome da option dos beneficios comerciais da Home.
 *
 * @return string
 */
function papelito_home_assets_features_option_name(): string {
	return 'papelito_home_features';
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
 * Nome da option das logos administraveis do site.
 *
 * @return string
 */
function papelito_home_assets_logos_option_name(): string {
	return 'papelito_site_logos';
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
 * Defaults da faixa de avisos e promocoes.
 *
 * @return array<int, array<string, mixed>>
 */
function papelito_home_assets_default_promo_marquee_items(): array {
	return array(
		array(
			'id'       => 'default-marquee-1',
			'text'     => '⚡ COMPRE 3 LEVE 4 em Sedas',
			'order'    => 1,
			'isActive' => true,
		),
		array(
			'id'       => 'default-marquee-2',
			'text'     => '🌿 Hemp King Size com 20% OFF',
			'order'    => 2,
			'isActive' => true,
		),
		array(
			'id'       => 'default-marquee-3',
			'text'     => '🎁 BRINDE em pedidos acima de R$500',
			'order'    => 3,
			'isActive' => true,
		),
		array(
			'id'       => 'default-marquee-4',
			'text'     => '💳 PARCELAMOS em 3x sem juros',
			'order'    => 4,
			'isActive' => true,
		),
		array(
			'id'       => 'default-marquee-5',
			'text'     => '🏆 A #1 DO BRASIL em papéis para enrolar',
			'order'    => 5,
			'isActive' => true,
		),
		array(
			'id'       => 'default-marquee-6',
			'text'     => '🔥 FRETE GRÁTIS acima de R$79',
			'order'    => 6,
			'isActive' => true,
		),
	);
}

/**
 * Limite de caracteres por mensagem da faixa.
 *
 * @return int
 */
function papelito_home_assets_promo_marquee_max_length(): int {
	return 120;
}

/**
 * Quantidade minima de mensagens ativas na faixa.
 *
 * @return int
 */
function papelito_home_assets_promo_marquee_min_active_messages(): int {
	return 3;
}

/**
 * Defaults dos beneficios comerciais da Home.
 *
 * @return array<int, array<string, mixed>>
 */
function papelito_home_assets_default_features(): array {
	return array(
		array(
			'id'       => 'frete-gratis',
			'title'    => 'Frete Grátis',
			'subtitle' => 'Acima de R$500',
			'iconId'   => 0,
			'iconUrl'  => '/images/icons/truck.svg',
		),
		array(
			'id'       => 'troca-facil',
			'title'    => 'Troca Fácil',
			'subtitle' => '15 dias para troca',
			'iconId'   => 0,
			'iconUrl'  => '/images/icons/refresh.svg',
		),
		array(
			'id'       => 'parcelamos',
			'title'    => 'Parcelamos',
			'subtitle' => 'Em 3x sem juros',
			'iconId'   => 0,
			'iconUrl'  => '/images/icons/price.svg',
		),
		array(
			'id'       => 'envio-rapido',
			'title'    => 'Envio Rápido',
			'subtitle' => 'Sai no mesmo dia',
			'iconId'   => 0,
			'iconUrl'  => '/images/icons/thunder.svg',
		),
	);
}

/**
 * Limite do titulo de beneficio.
 *
 * @return int
 */
function papelito_home_assets_feature_title_max_length(): int {
	return 32;
}

/**
 * Limite do texto auxiliar de beneficio.
 *
 * @return int
 */
function papelito_home_assets_feature_subtitle_max_length(): int {
	return 44;
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
 * Defaults das logos administraveis do site.
 *
 * @return array<string, array<string, mixed>>
 */
function papelito_home_assets_default_logos(): array {
	return array(
		'publicHeader'  => array(
			'imageId'  => 0,
			'imageUrl' => '/images/logo.svg',
			'alt'      => 'Papelito',
		),
		'privateHeader' => array(
			'imageId'  => 0,
			'imageUrl' => '/images/marketplacelogo.svg',
			'alt'      => 'Marketplace Papelito',
		),
		'footer'        => array(
			'imageId'  => 0,
			'imageUrl' => '/images/logo3.svg',
			'alt'      => 'Papelito',
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
 * Busca option da faixa de avisos e promocoes.
 *
 * @return array<int, mixed>
 */
function papelito_home_assets_get_raw_promo_marquee_items(): array {
	$value = get_option( papelito_home_assets_promo_marquee_option_name(), null );

	if ( ! is_array( $value ) ) {
		return papelito_home_assets_default_promo_marquee_items();
	}

	return array_values( $value );
}

/**
 * Cria a configuracao inicial da faixa sem sobrescrever edicoes existentes.
 *
 * @return void
 */
function papelito_home_assets_seed_promo_marquee(): void {
	$value = get_option( papelito_home_assets_promo_marquee_option_name(), null );

	if ( null !== $value ) {
		return;
	}

	update_option(
		papelito_home_assets_promo_marquee_option_name(),
		papelito_home_assets_default_promo_marquee_items(),
		false
	);
}

/**
 * Busca option dos beneficios comerciais.
 *
 * @return array<int, mixed>
 */
function papelito_home_assets_get_raw_features(): array {
	$value = get_option( papelito_home_assets_features_option_name(), null );

	if ( ! is_array( $value ) || 4 !== count( $value ) ) {
		return papelito_home_assets_default_features();
	}

	return array_values( $value );
}

/**
 * Cria a configuracao inicial dos beneficios sem sobrescrever edicoes existentes.
 *
 * @return void
 */
function papelito_home_assets_seed_features(): void {
	$value = get_option( papelito_home_assets_features_option_name(), null );

	if ( null !== $value ) {
		return;
	}

	update_option(
		papelito_home_assets_features_option_name(),
		papelito_home_assets_default_features(),
		false
	);
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
 * Busca option das logos administraveis.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_raw_logos(): array {
	$value = get_option( papelito_home_assets_logos_option_name(), array() );
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
 * Gera um ID para item da faixa.
 *
 * @return string
 */
function papelito_home_assets_generate_promo_marquee_id(): string {
	if ( function_exists( 'wp_generate_uuid4' ) ) {
		return wp_generate_uuid4();
	}

	return uniqid( 'marquee_', true );
}

/**
 * Normaliza item da faixa.
 *
 * @param array<string, mixed> $item Item armazenado.
 * @param int                  $index Posicao de fallback.
 * @return array<string, mixed>
 */
function papelito_home_assets_normalize_promo_marquee_item( array $item, int $index ): array {
	$id = sanitize_key( (string) ( $item['id'] ?? '' ) );

	if ( '' === $id ) {
		$id = papelito_home_assets_generate_promo_marquee_id();
	}

	return array(
		'id'       => $id,
		'text'     => papelito_home_assets_clean_text( $item['text'] ?? '' ),
		'order'    => max( 1, absint( $item['order'] ?? ( $index + 1 ) ) ),
		'isActive' => papelito_home_assets_to_bool( $item['isActive'] ?? true ),
	);
}

/**
 * Normaliza e ordena todos os itens da faixa.
 *
 * @param array<int, mixed> $items Itens armazenados.
 * @return array<int, array<string, mixed>>
 */
function papelito_home_assets_normalize_promo_marquee_items( array $items ): array {
	$normalized = array();

	foreach ( array_values( $items ) as $index => $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$normalized[] = papelito_home_assets_normalize_promo_marquee_item( $item, $index );
	}

	usort(
		$normalized,
		static function ( array $left, array $right ): int {
			return (int) $left['order'] <=> (int) $right['order'];
		}
	);

	foreach ( $normalized as $index => &$item ) {
		$item['order'] = $index + 1;
	}
	unset( $item );

	return $normalized;
}

/**
 * Normaliza URL de SVG.
 *
 * @param mixed $value URL bruta.
 * @return string
 */
function papelito_home_assets_normalize_svg_url( $value ): string {
	$url  = papelito_home_assets_normalize_image_url( $value );
	$path = wp_parse_url( $url, PHP_URL_PATH );

	if ( '' === $url || ! is_string( $path ) || 'svg' !== strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
		return '';
	}

	return $url;
}

/**
 * Resolve URL de um attachment SVG.
 *
 * @param int    $attachment_id ID do attachment.
 * @param string $fallback_url URL fallback.
 * @return string
 */
function papelito_home_assets_resolve_svg_url( int $attachment_id, string $fallback_url = '' ): string {
	if ( $attachment_id > 0 && function_exists( 'get_post_mime_type' ) && 'image/svg+xml' === get_post_mime_type( $attachment_id ) ) {
		$attachment_url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment_id ) : '';
		$normalized_url  = papelito_home_assets_normalize_svg_url( $attachment_url );

		if ( '' !== $normalized_url ) {
			return $normalized_url;
		}
	}

	return papelito_home_assets_normalize_svg_url( $fallback_url );
}

/**
 * Normaliza um beneficio comercial.
 *
 * @param array<string, mixed> $item Item salvo.
 * @param int                  $index Posicao.
 * @return array<string, mixed>
 */
function papelito_home_assets_normalize_feature( array $item, int $index ): array {
	$defaults = papelito_home_assets_default_features();
	$default  = $defaults[ $index ] ?? $defaults[0];
	$icon_id  = absint( $item['iconId'] ?? 0 );
	$icon_url = papelito_home_assets_resolve_svg_url( $icon_id, $item['iconUrl'] ?? '' );

	return array(
		'id'       => sanitize_key( (string) ( $item['id'] ?? $default['id'] ) ) ?: $default['id'],
		'title'    => papelito_home_assets_clean_text( $item['title'] ?? $default['title'] ),
		'subtitle' => papelito_home_assets_clean_text( $item['subtitle'] ?? $default['subtitle'] ),
		'iconId'   => $icon_id,
		'iconUrl'  => '' !== $icon_url ? $icon_url : $default['iconUrl'],
	);
}

/**
 * Normaliza todos os beneficios comerciais.
 *
 * @param array<int, mixed> $items Itens salvos.
 * @return array<int, array<string, mixed>>
 */
function papelito_home_assets_normalize_features( array $items ): array {
	$defaults   = papelito_home_assets_default_features();
	$normalized = array();

	foreach ( $defaults as $index => $default ) {
		$raw          = isset( $items[ $index ] ) && is_array( $items[ $index ] ) ? $items[ $index ] : $default;
		$normalized[] = papelito_home_assets_normalize_feature( $raw, $index );
	}

	return $normalized;
}

/**
 * Lista issues administrativas dos beneficios.
 *
 * @param array<int, array<string, mixed>> $items Itens normalizados.
 * @return array<int, string>
 */
function papelito_home_assets_collect_features_issues( array $items ): array {
	$issues = array();

	foreach ( $items as $index => $item ) {
		$number = $index + 1;
		$title  = (string) $item['title'];
		$subtitle = (string) $item['subtitle'];

		if ( '' === $title || '' === $subtitle || '' === (string) $item['iconUrl'] ) {
			$issues[] = sprintf( 'Benefício #%d precisa ter título, texto auxiliar e SVG.', $number );
		}

		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		$subtitle_length = function_exists( 'mb_strlen' ) ? mb_strlen( $subtitle ) : strlen( $subtitle );

		if ( $title_length > papelito_home_assets_feature_title_max_length() ) {
			$issues[] = sprintf( 'Título do benefício #%d excede o limite de %d caracteres.', $number, papelito_home_assets_feature_title_max_length() );
		}

		if ( $subtitle_length > papelito_home_assets_feature_subtitle_max_length() ) {
			$issues[] = sprintf( 'Texto auxiliar do benefício #%d excede o limite de %d caracteres.', $number, papelito_home_assets_feature_subtitle_max_length() );
		}
	}

	return $issues;
}

/**
 * Snapshot admin dos beneficios.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_admin_features_snapshot(): array {
	$items = papelito_home_assets_normalize_features( papelito_home_assets_get_raw_features() );

	return array(
		'items'  => $items,
		'issues' => papelito_home_assets_collect_features_issues( $items ),
	);
}

/**
 * Valida payload dos beneficios para escrita.
 *
 * @param mixed $input Valor recebido.
 * @return array<int, array<string, mixed>>|WP_Error
 */
function papelito_home_assets_validate_features_payload( $input ) {
	if ( ! is_array( $input ) || 4 !== count( $input ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_features_payload',
			'Os benefícios comerciais precisam conter exatamente quatro itens.',
			array( 'status' => 422 )
		);
	}

	$validated = array();
	$ids       = array();

	foreach ( array_values( $input ) as $index => $item ) {
		$number = $index + 1;

		if ( ! is_array( $item ) ) {
			return new WP_Error(
				'papelito_home_assets_invalid_feature_item',
				sprintf( 'Benefício #%d está em formato inválido.', $number ),
				array( 'status' => 422 )
			);
		}

		$raw_title    = trim( (string) ( $item['title'] ?? '' ) );
		$raw_subtitle = trim( (string) ( $item['subtitle'] ?? '' ) );
		$title        = papelito_home_assets_clean_text( $raw_title );
		$subtitle     = papelito_home_assets_clean_text( $raw_subtitle );
		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		$subtitle_length = function_exists( 'mb_strlen' ) ? mb_strlen( $subtitle ) : strlen( $subtitle );

		if ( wp_strip_all_tags( $raw_title ) !== $raw_title || wp_strip_all_tags( $raw_subtitle ) !== $raw_subtitle ) {
			return new WP_Error(
				'papelito_home_assets_html_feature_text',
				sprintf( 'Benefício #%d aceita apenas texto simples.', $number ),
				array( 'status' => 422 )
			);
		}

		if ( '' === $title || '' === $subtitle ) {
			return new WP_Error(
				'papelito_home_assets_empty_feature_text',
				sprintf( 'Benefício #%d precisa ter título e texto auxiliar.', $number ),
				array( 'status' => 422 )
			);
		}

		if ( $title_length > papelito_home_assets_feature_title_max_length() || $subtitle_length > papelito_home_assets_feature_subtitle_max_length() ) {
			return new WP_Error(
				'papelito_home_assets_long_feature_text',
				sprintf( 'Benefício #%d excede o limite de texto permitido.', $number ),
				array( 'status' => 422 )
			);
		}

		$normalized = papelito_home_assets_normalize_feature( $item, $index );
		$id         = $normalized['id'];
		$icon_id    = absint( $item['iconId'] ?? 0 );
		$icon_url   = papelito_home_assets_resolve_svg_url( $icon_id, $item['iconUrl'] ?? '' );

		if ( isset( $ids[ $id ] ) ) {
			return new WP_Error(
				'papelito_home_assets_duplicate_feature_id',
				sprintf( 'Benefício #%d possui ID duplicado.', $number ),
				array( 'status' => 422 )
			);
		}

		if ( '' === $icon_url ) {
			return new WP_Error(
				'papelito_home_assets_invalid_feature_icon',
				sprintf( 'Benefício #%d precisa ter um ícone SVG válido.', $number ),
				array( 'status' => 422 )
			);
		}

		$ids[ $id ]  = true;
		$validated[] = array(
			'id'       => $id,
			'title'    => $title,
			'subtitle' => $subtitle,
			'iconId'   => $icon_id,
			'iconUrl'  => $icon_url,
		);
	}

	return $validated;
}

/**
 * Lista issues administrativas da faixa.
 *
 * @param array<int, array<string, mixed>> $items Itens normalizados.
 * @return array<int, string>
 */
function papelito_home_assets_collect_promo_marquee_issues( array $items ): array {
	$issues = array();
	$ids    = array();
	$active = 0;

	foreach ( $items as $index => $item ) {
		if ( '' === (string) $item['text'] ) {
			$issues[] = sprintf( 'Mensagem #%d não pode ficar vazia.', $index + 1 );
		}

		if ( function_exists( 'mb_strlen' ) ) {
			$length = mb_strlen( (string) $item['text'] );
		} else {
			$length = strlen( (string) $item['text'] );
		}

		if ( $length > papelito_home_assets_promo_marquee_max_length() ) {
			$issues[] = sprintf(
				'Mensagem #%d excede o limite de %d caracteres.',
				$index + 1,
				papelito_home_assets_promo_marquee_max_length()
			);
		}

		if ( isset( $ids[ $item['id'] ] ) ) {
			$issues[] = sprintf( 'Mensagem #%d possui ID duplicado.', $index + 1 );
		}

		if ( ! empty( $item['isActive'] ) ) {
			$active++;
		}

		$ids[ $item['id'] ] = true;
	}

	if ( $active < papelito_home_assets_promo_marquee_min_active_messages() ) {
		$missing = papelito_home_assets_promo_marquee_min_active_messages() - $active;
		$issues[] = sprintf(
			'Selecione pelo menos %d frases para manter a faixa de avisos ativa. Ative mais %d frase%s.',
			papelito_home_assets_promo_marquee_min_active_messages(),
			$missing,
			1 === $missing ? '' : 's'
		);
	}

	return $issues;
}

/**
 * Snapshot admin da faixa.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_admin_promo_marquee_snapshot(): array {
	$items = papelito_home_assets_normalize_promo_marquee_items(
		papelito_home_assets_get_raw_promo_marquee_items()
	);

	return array(
		'messages' => $items,
		'issues'   => papelito_home_assets_collect_promo_marquee_issues( $items ),
	);
}

/**
 * Valida payload da faixa para escrita.
 *
 * @param mixed $input Valor recebido.
 * @return array<int, array<string, mixed>>|WP_Error
 */
function papelito_home_assets_validate_promo_marquee_payload( $input ) {
	if ( ! is_array( $input ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_promo_marquee_payload',
			'Payload da faixa de avisos inválido.',
			array( 'status' => 422 )
		);
	}

	$validated = array();
	$ids       = array();

	foreach ( array_values( $input ) as $index => $item ) {
		if ( ! is_array( $item ) ) {
			return new WP_Error(
				'papelito_home_assets_invalid_promo_marquee_item',
				sprintf( 'Mensagem #%d está em formato inválido.', $index + 1 ),
				array( 'status' => 422 )
			);
		}

		$normalized = papelito_home_assets_normalize_promo_marquee_item( $item, $index );
		$text       = (string) $normalized['text'];
		$length     = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		if ( '' === $text ) {
			return new WP_Error(
				'papelito_home_assets_empty_promo_marquee_text',
				sprintf( 'Mensagem #%d não pode ficar vazia.', $index + 1 ),
				array( 'status' => 422 )
			);
		}

		if ( $length > papelito_home_assets_promo_marquee_max_length() ) {
			return new WP_Error(
				'papelito_home_assets_long_promo_marquee_text',
				sprintf(
					'Mensagem #%d excede o limite de %d caracteres.',
					$index + 1,
					papelito_home_assets_promo_marquee_max_length()
				),
				array( 'status' => 422 )
			);
		}

		if ( isset( $ids[ $normalized['id'] ] ) ) {
			return new WP_Error(
				'papelito_home_assets_duplicate_promo_marquee_id',
				sprintf( 'Mensagem #%d possui ID duplicado.', $index + 1 ),
				array( 'status' => 422 )
			);
		}

		$ids[ $normalized['id'] ] = true;
		$validated[]             = array(
			'id'       => $normalized['id'],
			'text'     => $text,
			'order'    => $index + 1,
			'isActive' => $normalized['isActive'],
		);
	}

	$active = count(
		array_filter(
			$validated,
			static function ( array $item ): bool {
				return ! empty( $item['isActive'] );
			}
		)
	);

	if ( $active < papelito_home_assets_promo_marquee_min_active_messages() ) {
		$missing = papelito_home_assets_promo_marquee_min_active_messages() - $active;

		return new WP_Error(
			'papelito_home_assets_min_active_promo_marquee',
			sprintf(
				'Selecione pelo menos %d frases para manter a faixa de avisos ativa. Ative mais %d frase%s.',
				papelito_home_assets_promo_marquee_min_active_messages(),
				$missing,
				1 === $missing ? '' : 's'
			),
			array( 'status' => 422 )
		);
	}

	return $validated;
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
 * Normaliza uma logo administravel.
 *
 * @param string               $key Chave da logo.
 * @param array<string, mixed> $logo Logo salva.
 * @return array<string, mixed>
 */
function papelito_home_assets_normalize_logo( string $key, array $logo ): array {
	$defaults  = papelito_home_assets_default_logos();
	$default   = $defaults[ $key ] ?? array(
		'imageId'  => 0,
		'imageUrl' => '',
		'alt'      => '',
	);
	$image_id  = absint( $logo['imageId'] ?? 0 );
	$image_url = papelito_home_assets_normalize_image_url( $logo['imageUrl'] ?? '' );
	$alt       = papelito_home_assets_clean_text( $logo['alt'] ?? '' );

	return array(
		'imageId'  => $image_id,
		'imageUrl' => papelito_home_assets_resolve_image_url(
			$image_id,
			'' !== $image_url ? $image_url : (string) $default['imageUrl']
		),
		'alt'      => '' !== $alt ? $alt : papelito_home_assets_clean_text( $default['alt'] ),
	);
}

/**
 * Normaliza todas as logos administraveis.
 *
 * @param array<string, mixed> $logos Logos salvas.
 * @return array<string, array<string, mixed>>
 */
function papelito_home_assets_normalize_logos( array $logos ): array {
	$defaults   = papelito_home_assets_default_logos();
	$normalized = array();

	foreach ( $defaults as $key => $default_logo ) {
		$raw                = isset( $logos[ $key ] ) && is_array( $logos[ $key ] ) ? $logos[ $key ] : $default_logo;
		$normalized[ $key ] = papelito_home_assets_normalize_logo( $key, $raw );
	}

	return $normalized;
}

/**
 * Lista issues administrativas das logos.
 *
 * @param array<string, array<string, mixed>> $logos Logos normalizadas.
 * @return array<int, string>
 */
function papelito_home_assets_collect_logos_issues( array $logos ): array {
	$issues = array();

	foreach ( $logos as $key => $logo ) {
		if ( empty( $logo['imageUrl'] ) || '' === (string) $logo['alt'] ) {
			$issues[] = sprintf( 'Logo %s precisa ter arquivo e alt preenchidos.', $key );
		}
	}

	return $issues;
}

/**
 * Snapshot admin das logos.
 *
 * @return array<string, mixed>
 */
function papelito_home_assets_get_admin_logos_snapshot(): array {
	$logos = papelito_home_assets_normalize_logos( papelito_home_assets_get_raw_logos() );

	return array(
		'logos'  => $logos,
		'issues' => papelito_home_assets_collect_logos_issues( $logos ),
	);
}

/**
 * Determina se a chave de logo e valida.
 *
 * @param mixed $key Chave recebida.
 * @return bool
 */
function papelito_home_assets_is_valid_logo_key( $key ): bool {
	return is_string( $key ) && array_key_exists( $key, papelito_home_assets_default_logos() );
}

/**
 * Restaura a logo padrao de uma chave, removendo a personalizacao salva.
 *
 * @param string $key Chave da logo.
 * @return array<string, mixed>|WP_Error
 */
function papelito_home_assets_restore_default_logo( string $key ) {
	if ( ! papelito_home_assets_is_valid_logo_key( $key ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_logo_key',
			'Logo informada não existe.',
			array( 'status' => 422 )
		);
	}

	$stored = papelito_home_assets_get_raw_logos();
	unset( $stored[ $key ] );
	update_option( papelito_home_assets_logos_option_name(), $stored, false );

	return papelito_home_assets_get_admin_logos_snapshot();
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
 * Valida payload das logos administraveis.
 *
 * @param mixed $input Valor recebido.
 * @return array<string, array<string, mixed>>|WP_Error
 */
function papelito_home_assets_validate_logos_payload( $input ) {
	if ( ! is_array( $input ) ) {
		return new WP_Error(
			'papelito_home_assets_invalid_logos_payload',
			'Payload de logos inválido.',
			array( 'status' => 422 )
		);
	}

	$normalized = papelito_home_assets_normalize_logos( $input );
	$issues     = papelito_home_assets_collect_logos_issues( $normalized );

	if ( ! empty( $issues ) ) {
		return new WP_Error(
			'papelito_home_assets_incomplete_logos',
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
			'/home/promo-marquee',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$items = papelito_home_assets_normalize_promo_marquee_items(
						papelito_home_assets_get_raw_promo_marquee_items()
					);

					$public_items = array_values(
						array_filter(
							$items,
							static function ( array $item ): bool {
								$length = function_exists( 'mb_strlen' )
									? mb_strlen( (string) $item['text'] )
									: strlen( (string) $item['text'] );

								return ! empty( $item['isActive'] )
									&& '' !== (string) $item['text']
									&& $length <= papelito_home_assets_promo_marquee_max_length();
							}
						)
					);

					if ( count( $public_items ) < papelito_home_assets_promo_marquee_min_active_messages() ) {
						$public_items = array();
					}

					return new WP_REST_Response( array( 'messages' => $public_items ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/home/features',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$items = papelito_home_assets_normalize_features(
						papelito_home_assets_get_raw_features()
					);

					if ( ! empty( papelito_home_assets_collect_features_issues( $items ) ) ) {
						$items = papelito_home_assets_normalize_features( papelito_home_assets_default_features() );
					}

					return new WP_REST_Response( array( 'items' => $items ), 200 );
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
			'papelito/v1',
			'/site/logos',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response(
						array(
							'logos' => papelito_home_assets_normalize_logos(
								papelito_home_assets_get_raw_logos()
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
			'/assets/logos',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_home_assets_get_admin_logos_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/logos',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$params    = $request->get_json_params();
					$payload   = is_array( $params ) ? $params : array();
					$validated = papelito_home_assets_validate_logos_payload( $payload['logos'] ?? $payload );

					if ( is_wp_error( $validated ) ) {
						return papelito_home_assets_rest_error_response( $validated );
					}

					update_option( papelito_home_assets_logos_option_name(), $validated, false );

					return new WP_REST_Response( papelito_home_assets_get_admin_logos_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/logos',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'key' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ): bool {
							return papelito_home_assets_is_valid_logo_key( $value );
						},
					),
				),
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$restored = papelito_home_assets_restore_default_logo( (string) $request->get_param( 'key' ) );

					if ( is_wp_error( $restored ) ) {
						return papelito_home_assets_rest_error_response( $restored );
					}

					return new WP_REST_Response( $restored, 200 );
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
			'/assets/promo-marquee',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_home_assets_get_admin_promo_marquee_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/features',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( papelito_home_assets_get_admin_features_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/features',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$params    = $request->get_json_params();
					$payload   = is_array( $params ) ? $params : array();
					$validated = papelito_home_assets_validate_features_payload( $payload['items'] ?? $payload );

					if ( is_wp_error( $validated ) ) {
						return papelito_home_assets_rest_error_response( $validated );
					}

					update_option( papelito_home_assets_features_option_name(), $validated, false );

					return new WP_REST_Response( papelito_home_assets_get_admin_features_snapshot(), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/assets/promo-marquee',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$params    = $request->get_json_params();
					$payload   = is_array( $params ) ? $params : array();
					$validated = papelito_home_assets_validate_promo_marquee_payload( $payload['messages'] ?? $payload );

					if ( is_wp_error( $validated ) ) {
						return papelito_home_assets_rest_error_response( $validated );
					}

					update_option( papelito_home_assets_promo_marquee_option_name(), $validated, false );

					return new WP_REST_Response( papelito_home_assets_get_admin_promo_marquee_snapshot(), 200 );
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
