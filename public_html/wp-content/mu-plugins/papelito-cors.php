<?php
/**
 * Plugin Name: Papelito CORS
 * Description: CORS controlado para REST API e WPGraphQL com allowlist explícita.
 * Version: 1.1.0
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'papelito_cors_allowed_origins' ) ) {
	/**
	 * Retorna a allowlist configurada no ambiente.
	 *
	 * @return string[]
	 */
	function papelito_cors_allowed_origins(): array {
		$raw = defined( 'PAPELITO_ALLOWED_ORIGINS' ) ? PAPELITO_ALLOWED_ORIGINS : '';
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}
}

if ( ! function_exists( 'papelito_cors_request_origin' ) ) {
	/**
	 * Le a origem que o navegador informou na requisicao.
	 *
	 * @return string
	 */
	function papelito_cors_request_origin(): string {
		return isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
	}
}

if ( ! function_exists( 'papelito_cors_headers_for_origin' ) ) {
	/**
	 * Constroi os cabecalhos CORS somente para uma origem previamente permitida.
	 *
	 * @param string $origin Origem informada pelo navegador.
	 * @return array<string, string>
	 */
	function papelito_cors_headers_for_origin( string $origin ): array {
		if ( '' === $origin || ! in_array( $origin, papelito_cors_allowed_origins(), true ) ) {
			return array();
		}

		return array(
			'Access-Control-Allow-Origin'      => $origin,
			'Access-Control-Allow-Credentials' => 'true',
			'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, X-Papelito-Upload-Ticket, X-WP-Nonce',
			'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, OPTIONS',
			'Access-Control-Max-Age'           => '600',
			'Vary'                             => 'Origin',
		);
	}
}

if ( ! function_exists( 'papelito_cors_send_headers' ) ) {
	/**
	 * Envia os cabecalhos CORS da origem atual, caso ela esteja na allowlist.
	 *
	 * @return void
	 */
	function papelito_cors_send_headers(): void {
		foreach ( papelito_cors_headers_for_origin( papelito_cors_request_origin() ) as $name => $value ) {
			// Nao sobrescreve outros valores de Vary eventualmente adicionados por cache/proxy.
			header( $name . ': ' . $value, 'Vary' !== $name );
		}
	}
}

/**
 * O core do WordPress registra `rest_send_cors_headers()` em `rest_api_init` (prioridade 10) e
 * reflete qualquer Origin recebido. Remover o callback depois desse registro evita que ele
 * reintroduza uma origem nao permitida depois da nossa allowlist.
 */
add_action(
	'rest_api_init',
	static function (): void {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	},
	11
);

/**
 * WPGraphQL adiciona `Access-Control-Allow-Origin: *` por padrao. Esta e a ultima etapa antes de
 * enviar a resposta GraphQL, entao removemos qualquer cabecalho CORS anterior e aplicamos a mesma
 * allowlist usada pela REST API.
 *
 * @param array<string, string> $headers Cabecalhos que o WPGraphQL pretende enviar.
 * @return array<string, string>
 */
function papelito_cors_graphql_response_headers( array $headers ): array {
	foreach ( array_keys( $headers ) as $name ) {
		if ( 0 === stripos( $name, 'Access-Control-' ) || 'vary' === strtolower( $name ) ) {
			unset( $headers[ $name ] );
		}
	}

	return array_merge( $headers, papelito_cors_headers_for_origin( papelito_cors_request_origin() ) );
}

add_filter( 'graphql_response_headers_to_send', 'papelito_cors_graphql_response_headers', 999 );

add_filter(
	'rest_pre_serve_request',
	static function ( bool $served ): bool {
		papelito_cors_send_headers();

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) {
			status_header( 200 );
			exit;
		}

		return $served;
	},
	15
);

add_action(
	'graphql_init',
	static function (): void {
		papelito_cors_send_headers();

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) {
			status_header( 200 );
			exit;
		}
	}
);

add_action(
	'init',
	static function (): void {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if (
			( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' &&
			( strpos( $uri, '/graphql' ) === 0 || strpos( $uri, '/wp-json' ) === 0 )
		) {
			papelito_cors_send_headers();
			status_header( 200 );
			exit;
		}
	},
	0
);
