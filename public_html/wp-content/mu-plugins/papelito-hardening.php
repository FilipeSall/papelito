<?php
/**
 * Plugin Name: Papelito Hardening
 * Description: Endurecimento de segurança aplicado em todas as instâncias.
 * Version: 1.0.0
 * Author: Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function papelito_is_legacy_public_host(): bool {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : '';

	if ( '' === $host ) {
		return false;
	}

	$host = preg_replace( '/:\d+$/', '', $host );

	return in_array( $host, array( 'papelitobrasil.com.br', 'www.papelitobrasil.com.br' ), true );
}

add_filter(
	'allowed_redirect_hosts',
	static function ( array $hosts ): array {
		$hosts[] = 'papelito.com';
		return array_values( array_unique( $hosts ) );
	}
);

add_action(
	'init',
	static function (): void {
		if ( papelito_is_legacy_public_host() ) {
			wp_safe_redirect( 'https://papelito.com/', 302 );
			exit;
		}
	},
	0
);

add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}

		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}
);

add_action(
	'init',
	static function (): void {
		if ( ! is_admin() && isset( $_GET['author'] ) && is_numeric( wp_unslash( $_GET['author'] ) ) ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}
);

remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter(
	'wp_headers',
	static function ( array $headers ): array {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

add_action(
	'admin_init',
	static function (): void {
		if ( get_option( 'permalink_structure' ) !== '/%postname%/' ) {
			update_option( 'permalink_structure', '/%postname%/' );
		}
	}
);

add_action(
	'wp_login_failed',
	static function (): void {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'papelito_login_fail_' . md5( $ip );
		$n   = (int) get_transient( $key );
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		if ( $n >= 60 ) {
			wp_die( 'Too many failed attempts. Try again later.', 'Rate limit', array( 'response' => 429 ) );
		}
	}
);
