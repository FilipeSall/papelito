<?php
/**
 * Resolucao da URL publica do frontend usada em links de e-mail.
 *
 * Modulo isolado de proposito: e a unica fonte da base publica e nao depende de mais nada do
 * plugin, o que permite exercita-lo em teste standalone sem carregar os endpoints REST.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Le uma configuracao de URL aceitando constante ou variavel de ambiente.
 *
 * As duas formas existem em producao: o `wp-config.php` e mantido a mao no servidor e nao vem no
 * deploy, entao o mesmo valor aparece como `define()` em um ambiente e como `putenv()` em outro.
 * Ler so uma das formas foi a causa do link de faturamento sair com `http://localhost:3000`:
 * `papelito_env()` consulta apenas `getenv()` e nunca ve a constante.
 *
 * @param string $key Nome da constante/variavel.
 * @return string
 */
function papelito_frontend_config_value( string $key ): string {
	if ( defined( $key ) ) {
		$value = (string) constant( $key );

		if ( '' !== $value ) {
			return $value;
		}
	}

	if ( function_exists( 'papelito_env' ) ) {
		$value = (string) papelito_env( $key, '' );

		if ( '' !== $value ) {
			return $value;
		}
	}

	$value = getenv( $key );

	return false === $value ? '' : (string) $value;
}

/**
 * Reduz uma URL a origem canonica comparavel: `esquema://host[:porta]`.
 *
 * Descarta caminho, barra final e diferenca de caixa para que a comparacao com a allowlist seja
 * exata. Retorna string vazia para qualquer coisa que nao seja http(s) com host.
 *
 * @param string $url URL crua.
 * @return string
 */
function papelito_frontend_normalize_base( string $url ): string {
	$url = trim( $url );

	if ( '' === $url ) {
		return '';
	}

	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	$scheme = strtolower( (string) $parts['scheme'] );

	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}

	$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

	return $scheme . '://' . strtolower( (string) $parts['host'] ) . $port;
}

/**
 * Origens de frontend confiaveis, a partir de `PAPELITO_ALLOWED_ORIGINS`.
 *
 * @return array<int,string>
 */
function papelito_frontend_allowed_bases(): array {
	$bases = array();

	foreach ( explode( ',', papelito_frontend_config_value( 'PAPELITO_ALLOWED_ORIGINS' ) ) as $origin ) {
		$normalized = papelito_frontend_normalize_base( (string) $origin );

		if ( '' !== $normalized ) {
			$bases[ $normalized ] = true;
		}
	}

	return array_keys( $bases );
}

/**
 * Informa se o ambiente atual pode cair no `localhost` como base de link.
 *
 * @return bool
 */
function papelito_frontend_allows_localhost(): bool {
	$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

	return in_array( $environment, array( 'local', 'development' ), true );
}

/**
 * Informa se a base aponta para a maquina de quem desenvolve.
 *
 * `PAPELITO_ALLOWED_ORIGINS` mantem `http://localhost:3000` em producao de proposito, porque a
 * mesma lista serve o CORS. Sem esta guarda, um header `X-Papelito-Frontend-Base: localhost`
 * passaria a validacao de allowlist e o e-mail sairia com o link inabrivel de novo.
 *
 * @param string $base Origem normalizada.
 * @return bool
 */
function papelito_frontend_is_local_base( string $base ): bool {
	$host = (string) wp_parse_url( $base, PHP_URL_HOST );

	if ( '' === $host ) {
		return true;
	}

	if ( in_array( $host, array( 'localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]' ), true ) ) {
		return true;
	}

	return 1 === preg_match( '/(^|\.)(localhost|local|test|localdomain)$/', $host );
}

/**
 * Informa se uma base pode ser usada como link no ambiente atual.
 *
 * @param string $base Origem normalizada.
 * @return bool
 */
function papelito_frontend_base_is_usable( string $base ): bool {
	if ( '' === $base ) {
		return false;
	}

	return papelito_frontend_allows_localhost() || ! papelito_frontend_is_local_base( $base );
}

/**
 * Resolve a base publica do frontend usada em links de e-mail.
 *
 * Um unico WordPress atende o Vercel Preview e a producao, entao uma variavel so no servidor nao
 * consegue produzir dominios diferentes por ambiente. O proxy Next informa a propria base canonica
 * e ela e aceita **somente** se estiver na allowlist — sem isso, um header forjado faria o WP
 * enviar link de phishing em dominio alheio.
 *
 * Fora de local/development nunca retorna `localhost`: prefere devolver string vazia, para o
 * chamador falhar de forma visivel em vez de enviar link morto.
 *
 * @param string $requested Base solicitada pelo frontend (nao confiavel).
 * @return string Origem canonica, ou string vazia quando nada esta configurado.
 */
function papelito_frontend_base_url( string $requested = '' ): string {
	$allowed   = papelito_frontend_allowed_bases();
	$requested = papelito_frontend_normalize_base( $requested );

	if ( in_array( $requested, $allowed, true ) && papelito_frontend_base_is_usable( $requested ) ) {
		return $requested;
	}

	$configured = papelito_frontend_normalize_base( papelito_frontend_config_value( 'PAPELITO_FRONTEND_URL' ) );

	if ( papelito_frontend_base_is_usable( $configured ) ) {
		return $configured;
	}

	foreach ( $allowed as $base ) {
		if ( papelito_frontend_base_is_usable( $base ) ) {
			return $base;
		}
	}

	return papelito_frontend_allows_localhost() ? 'http://localhost:3000' : '';
}

/**
 * Le a base canonica informada pelo proxy Next.
 *
 * Somente `X-Papelito-Frontend-Base`, que so o proxy emite. `Origin` nao serve de fallback: ele e
 * escolhido por quem chama, entao um chamador fora do navegador escolheria qual dominio da
 * allowlist aparece no e-mail. Ausente o header, `papelito_frontend_base_url()` cai na
 * configuracao do ambiente — que e o comportamento correto para qualquer chamada direta.
 *
 * O valor so tem efeito depois de passar pela allowlist em `papelito_frontend_base_url()`.
 *
 * @param WP_REST_Request|null $request Requisicao REST.
 * @return string
 */
function papelito_frontend_base_from_request( $request = null ): string {
	if ( ! $request instanceof WP_REST_Request ) {
		return '';
	}

	return sanitize_text_field( (string) $request->get_header( 'X-Papelito-Frontend-Base' ) );
}

/**
 * Monta um link publico do frontend, ou WP_Error quando a base nao esta configurada.
 *
 * Para e-mail transacional: melhor falhar com 500 visivel do que gravar estado pendente e enviar
 * um link que ninguem consegue abrir.
 *
 * @param string $path      Caminho comecando com barra.
 * @param string $requested Base solicitada pelo frontend.
 * @return string|WP_Error
 */
function papelito_frontend_link( string $path, string $requested = '' ) {
	$base = papelito_frontend_base_url( $requested );

	if ( '' === $base ) {
		return new WP_Error(
			'papelito_frontend_url_unresolved',
			'A URL pública do frontend não está configurada neste ambiente.',
			array( 'status' => 500 )
		);
	}

	return $base . '/' . ltrim( $path, '/' );
}
