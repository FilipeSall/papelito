<?php
/**
 * Plugin Name: Papelito CORS
 * Description: CORS controlado para REST API e WPGraphQL com allowlist explícita.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'papelito_cors_allowed_origins' ) ) {
	function papelito_cors_allowed_origins(): array {
		$raw = defined( 'PAPELITO_ALLOWED_ORIGINS' ) ? PAPELITO_ALLOWED_ORIGINS : '';
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}
}

if ( ! function_exists( 'papelito_cors_send_headers' ) ) {
	function papelito_cors_send_headers(): void {
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$allowed = papelito_cors_allowed_origins();

		if ( $origin && in_array( $origin, $allowed, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Vary: Origin', false );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
			header( 'Access-Control-Max-Age: 600' );
		}
	}
}

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
