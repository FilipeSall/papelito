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

const PAPELITO_LOGIN_FAILURE_MAX    = 5;
const PAPELITO_LOGIN_FAILURE_WINDOW = 900;

function papelito_is_legacy_public_host(): bool {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : '';

	if ( '' === $host ) {
		return false;
	}

	$host = preg_replace( '/:\d+$/', '', $host );

	return in_array( $host, array( 'papelitobrasil.com.br', 'www.papelitobrasil.com.br' ), true );
}

function papelito_should_redirect_legacy_public_request(): bool {
	if ( ! papelito_is_legacy_public_host() ) {
		return false;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	if ( 'OPTIONS' === $method ) {
		return false;
	}

	if ( isset( $_GET['wc-ajax'] ) ) {
		return false;
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

	foreach ( array( '/wp-json', '/graphql', '/wp-admin', '/wp-content', '/wp-includes' ) as $prefix ) {
		if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
			return false;
		}
	}

	return ! in_array( $path, array( '/wp-login.php', '/wp-cron.php' ), true );
}

add_filter(
	'allowed_redirect_hosts',
	static function ( array $hosts ): array {
		$hosts[] = 'marketplace.papelito.com';
		return array_values( array_unique( $hosts ) );
	}
);

add_action(
	'init',
	static function (): void {
		if ( papelito_should_redirect_legacy_public_request() ) {
			wp_safe_redirect( 'https://marketplace.papelito.com/', 302 );
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

/**
 * Chave do contador de falhas de login.
 *
 * Chavear por `REMOTE_ADDR` NAO funciona aqui: o login do marketplace nao chega pelo navegador, e
 * sim pelo `authorize()` do NextAuth, que roda no servidor Next e bate na mutation GraphQL. O WP ve
 * sempre o mesmo IP — o do frontend —, entao um balde por IP vira um teto do marketplace inteiro e
 * a sexta senha errada do dia derruba a autenticacao de todo mundo. Mesmo raciocinio ja registrado
 * em `papelito_rate_limit_identity()` e coberto por `tests/test-rate-limit-identity.php`.
 *
 * A identidade tentada e o unico identificador que sobrevive ao proxy. Quando ela corresponde a
 * uma conta existente, login e e-mail sao reduzidos ao mesmo usuario para nao dobrar a cota.
 *
 * @param string $username Identificador tentado no login.
 * @return string
 */
function papelito_login_failure_key( string $username ): string {
	$identity = strtolower( trim( $username ) );

	if ( '' !== $identity && function_exists( 'get_user_by' ) ) {
		$user = get_user_by( 'login', $identity );
		$user = $user instanceof WP_User ? $user : get_user_by( 'email', $identity );

		if ( $user instanceof WP_User && $user->ID > 0 ) {
			return 'papelito_login_fail_user_' . $user->ID;
		}
	}

	if ( '' === $identity ) {
		$identity = 'ip:' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' );
	}

	return 'papelito_login_fail_identity_' . hash( 'sha256', $identity );
}

/**
 * Distingue senha/usuario incorretos de estados validos da conta, como e-mail pendente.
 *
 * @param WP_Error $error Resultado da autenticacao.
 * @return bool
 */
function papelito_login_is_credential_failure( WP_Error $error ): bool {
	return in_array( $error->get_error_code(), array( 'incorrect_password', 'invalid_username', 'invalid_email' ), true );
}

/**
 * Marca a tentativa que ESTA funcao ja recusou, para nao contar duas vezes.
 *
 * `wp_authenticate()` dispara `wp_login_failed` depois de toda a cadeia do filtro `authenticate`,
 * inclusive quando quem devolveu o WP_Error fomos nos.
 */
const PAPELITO_LOGIN_RATE_LIMIT_CODE = 'papelito_login_rate_limited';
const PAPELITO_LOGIN_RATE_LIMIT_MESSAGE = PAPELITO_LOGIN_RATE_LIMIT_CODE;

/**
 * Le o balde de falhas de uma identidade, ja descartando janela vencida.
 *
 * @param string $key Chave do transient.
 * @return array{count:int,expires_at:int}
 */
function papelito_login_failure_bucket( string $key ): array {
	$now    = time();
	$bucket = get_transient( $key );

	// Janela FIXA: guardar o vencimento junto do contador impede que cada nova falha renove o TTL.
	// Com janela deslizante, uma tentativa por minuto mantinha o bloqueio para sempre.
	if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['expires_at'] ) || $bucket['expires_at'] <= $now ) {
		return array(
			'count'      => 0,
			'expires_at' => $now + PAPELITO_LOGIN_FAILURE_WINDOW,
		);
	}

	return array(
		'count'      => (int) $bucket['count'],
		'expires_at' => (int) $bucket['expires_at'],
	);
}

/**
 * Aplica a cota e libera no acerto — no filtro `authenticate`, nao em `wp_login`.
 *
 * `wp_login` NAO serve para este fluxo: ele so dispara dentro de `wp_signon()`, e o plugin JWT usa
 * `wp_authenticate()` quando `GRAPHQL_JWT_AUTH_SET_COOKIES` nao esta definida — que e o caso aqui.
 * O filtro `authenticate` e o unico ponto que os dois caminhos atravessam.
 *
 * Prioridade 100 (depois de `wp_authenticate_username_password`, que e 20) porque so no fim da
 * cadeia se sabe se a credencial estava certa. Cedo demais nao funcionaria: aquela funcao so
 * devolve antes do tempo quando ja recebeu um `WP_User`, entao ela sobrescreveria nosso WP_Error.
 *
 * Devolver WP_Error em vez de `wp_die()` mantem a resposta GraphQL em 200 com `errors[]`, que o
 * proxy Next consegue ler e traduzir. Um `wp_die()` matava a requisicao com HTML 429, e o Next
 * colapsava isso em "servico indisponivel", escondendo tanto o rate limit quanto o aviso de e-mail
 * nao confirmado.
 *
 * @param null|WP_User|WP_Error $user     Resultado da cadeia de autenticacao.
 * @param string                $username Identificador tentado.
 * @return null|WP_User|WP_Error
 */
function papelito_login_rate_limit_gate( $user, $username = '' ) {
	$key = papelito_login_failure_key( (string) $username );

	if ( $user instanceof WP_User ) {
		// Credencial correta nunca e barrada, e zera a cota na hora.
		delete_transient( $key );
		delete_transient( papelito_login_failure_key( (string) $user->user_email ) );

		return $user;
	}

	if ( ! is_wp_error( $user ) ) {
		return $user;
	}

	if ( ! papelito_login_is_credential_failure( $user ) ) {
		return $user;
	}

	$bucket = papelito_login_failure_bucket( $key );

	if ( $bucket['count'] >= PAPELITO_LOGIN_FAILURE_MAX ) {
		return new WP_Error(
			PAPELITO_LOGIN_RATE_LIMIT_CODE,
			PAPELITO_LOGIN_RATE_LIMIT_MESSAGE,
			array( 'status' => 429 )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'papelito_login_rate_limit_gate', 100, 2 );

add_action(
	'wp_login_failed',
	static function ( $username = '', $error = null ): void {
		// A recusa por cota ja foi contada na tentativa que a esgotou; contar de novo so inflaria o
		// balde sem mudar decisao nenhuma.
		if ( $error instanceof WP_Error && PAPELITO_LOGIN_RATE_LIMIT_CODE === $error->get_error_code() ) {
			return;
		}

		$key    = papelito_login_failure_key( (string) $username );
		$bucket = papelito_login_failure_bucket( $key );
		$now    = time();

		++$bucket['count'];

		set_transient( $key, $bucket, max( 1, $bucket['expires_at'] - $now ) );
	},
	10,
	2
);

add_action(
	'wp_login',
	static function ( $user_login, $user = null ): void {
		// Redundante com o filtro `authenticate` no fluxo headless; existe para o login de navegador
		// no wp-admin, que passa por `wp_signon()` e compartilha as mesmas chaves.
		delete_transient( papelito_login_failure_key( (string) $user_login ) );

		if ( $user instanceof WP_User && '' !== (string) $user->user_email ) {
			delete_transient( papelito_login_failure_key( (string) $user->user_email ) );
		}
	},
	10,
	2
);
