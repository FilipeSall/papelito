<?php
/**
 * Configuração de atendimento exibida no rodapé do site público.
 *
 * Telefone do "Fale Conosco" e destino de cada ícone de rede social. Segue o padrão de
 * `collections_config.php` — option com normalizador tipado, leitura pública e escrita
 * administrativa — para não criar tabela nova.
 *
 * @package Papelito
 */

const PAPELITO_CONTACT_API_NAMESPACE  = 'papelito/v1';
const PAPELITO_SOCIAL_PROFILES_OPTION = 'papelito_social_profiles';
const PAPELITO_SOCIAL_PROFILE_MAX_URL = 300;

/**
 * Telefone padrão de atendimento, usado enquanto nenhum administrador o alterou.
 *
 * @return string
 */
function papelito_contact_phone_default(): string {
	return '+556198364920';
}

/**
 * Telefone vigente, com o padrão cobrindo option corrompida.
 *
 * @return string
 */
function papelito_contact_phone(): string {
	$value = get_option( 'papelito_contact_phone', papelito_contact_phone_default() );

	return is_string( $value ) && preg_match( '/^\+?[0-9 ()-]{10,20}$/', $value ) ? $value : papelito_contact_phone_default();
}

/**
 * Perfis sociais padrão, na ordem em que o rodapé os exibe.
 *
 * São as mesmas URLs que ficavam fixas no frontend antes de a configuração existir; o catálogo de
 * redes aceitas é exatamente este conjunto de chaves.
 *
 * @return array<string, string>
 */
function papelito_social_profile_defaults(): array {
	return array(
		'instagram' => 'https://www.instagram.com/papelitobrasil/',
		'youtube'   => 'https://www.youtube.com/c/PapelitoBrasil',
		'linkedin'  => 'https://www.linkedin.com/company/papelitobrasil/',
		'tiktok'    => 'https://www.tiktok.com/@papelitobrasil',
		'x'         => 'https://x.com/papelito_brasil',
	);
}

/**
 * Nome de exibição de cada rede, usado nas mensagens de erro do painel.
 *
 * @return array<string, string>
 */
function papelito_social_profile_labels(): array {
	return array(
		'instagram' => 'Instagram',
		'youtube'   => 'YouTube',
		'linkedin'  => 'LinkedIn',
		'tiktok'    => 'TikTok',
		'x'         => 'X',
	);
}

/**
 * Valida a URL de um perfil social.
 *
 * Vazio é valor legítimo: é como o administrador oculta o ícone da rede no rodapé.
 *
 * @param string $url URL já sem espaços nas pontas.
 * @return bool
 */
function papelito_social_profile_url_is_valid( string $url ): bool {
	if ( '' === $url ) {
		return true;
	}

	if ( strlen( $url ) > PAPELITO_SOCIAL_PROFILE_MAX_URL ) {
		return false;
	}

	return 1 === preg_match( '#^https?://#i', $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL );
}

/**
 * Perfis sociais vigentes, um por rede do catálogo.
 *
 * Rede que nunca foi gravada cai no padrão; string vazia gravada permanece vazia, porque significa
 * "não exibir" e não ausência de configuração.
 *
 * @return array<string, string>
 */
function papelito_social_profiles(): array {
	$stored = get_option( PAPELITO_SOCIAL_PROFILES_OPTION, array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	$profiles = array();

	foreach ( papelito_social_profile_defaults() as $network => $default_url ) {
		$value = isset( $stored[ $network ] ) && is_string( $stored[ $network ] ) ? trim( $stored[ $network ] ) : null;

		$profiles[ $network ] = ( null !== $value && papelito_social_profile_url_is_valid( $value ) ) ? $value : $default_url;
	}

	return $profiles;
}

/**
 * Configuração completa devolvida pelas três rotas.
 *
 * @return array<string, mixed>
 */
function papelito_contact_config(): array {
	return array(
		'phone'  => papelito_contact_phone(),
		'social' => papelito_social_profiles(),
	);
}

/**
 * Aplica o bloco `social` do payload sobre os perfis vigentes.
 *
 * Recusa o payload inteiro no primeiro campo inválido: gravar metade dos links deixaria o rodapé
 * num estado que o administrador não pediu.
 *
 * @param mixed $payload Bloco `social` cru do REST.
 * @return array<string, string>|WP_Error
 */
function papelito_social_profiles_from_payload( $payload ) {
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'papelito_invalid_social_profiles', 'Informe os links das redes sociais.', array( 'status' => 422 ) );
	}

	$labels   = papelito_social_profile_labels();
	$profiles = papelito_social_profiles();

	foreach ( $payload as $network => $url ) {
		if ( ! is_string( $network ) || ! isset( $labels[ $network ] ) ) {
			return new WP_Error( 'papelito_unknown_social_network', 'Rede social desconhecida.', array( 'status' => 422 ) );
		}

		$normalized = is_string( $url ) ? trim( $url ) : null;
		$sanitized  = ( null !== $normalized && '' !== $normalized ) ? esc_url_raw( $normalized ) : $normalized;

		if ( null === $sanitized || '' === $sanitized && '' !== $normalized || ! papelito_social_profile_url_is_valid( $sanitized ) ) {
			return new WP_Error(
				'papelito_invalid_social_profile_url',
				sprintf( 'Informe uma URL http ou https para %s, ou deixe em branco para ocultar o ícone.', $labels[ $network ] ),
				array( 'status' => 422 )
			);
		}

		$profiles[ $network ] = $sanitized;
	}

	return $profiles;
}

/**
 * Valida e persiste telefone e perfis sociais.
 *
 * O payload é parcial de propósito: o painel salva telefone e redes sociais em botões separados, e
 * quem não vem no corpo permanece como está.
 *
 * @param WP_REST_Request $request Requisição do REST.
 * @return array<string, mixed>|WP_Error
 */
function papelito_contact_config_update( WP_REST_Request $request ) {
	$payload = $request->get_json_params();

	if ( ! is_array( $payload ) ) {
		$payload = array();
	}

	$has_phone  = array_key_exists( 'phone', $payload );
	$has_social = array_key_exists( 'social', $payload );

	if ( ! $has_phone && ! $has_social ) {
		return new WP_Error( 'papelito_invalid_contact_config', 'Informe o telefone ou os links das redes sociais.', array( 'status' => 422 ) );
	}

	$phone = null;

	if ( $has_phone ) {
		$phone = sanitize_text_field( (string) $payload['phone'] );

		if ( ! preg_match( '/^\+?[0-9 ()-]{10,20}$/', $phone ) ) {
			return new WP_Error( 'papelito_invalid_contact_phone', 'Informe um telefone válido.', array( 'status' => 422 ) );
		}
	}

	$social = null;

	if ( $has_social ) {
		$social = papelito_social_profiles_from_payload( $payload['social'] );

		if ( is_wp_error( $social ) ) {
			return $social;
		}
	}

	if ( null !== $phone ) {
		update_option( 'papelito_contact_phone', $phone, false );
	}

	if ( null !== $social ) {
		update_option( PAPELITO_SOCIAL_PROFILES_OPTION, $social, false );
	}

	return papelito_contact_config();
}

/**
 * Exige capacidade administrativa.
 *
 * @return bool
 */
function papelito_contact_require_admin(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Registra as rotas REST da configuração de atendimento.
 *
 * @return void
 */
function papelito_contact_config_register_routes(): void {
	register_rest_route(
		PAPELITO_CONTACT_API_NAMESPACE,
		'/home/contact-config',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => static fn() => papelito_contact_config(),
		)
	);

	register_rest_route(
		PAPELITO_CONTACT_API_NAMESPACE,
		'/admin/contact-config',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_contact_require_admin',
			'callback'            => static fn() => papelito_contact_config(),
		)
	);

	register_rest_route(
		PAPELITO_CONTACT_API_NAMESPACE,
		'/admin/contact-config',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'permission_callback' => 'papelito_contact_require_admin',
			'callback'            => 'papelito_contact_config_update',
		)
	);
}

add_action( 'rest_api_init', 'papelito_contact_config_register_routes' );
