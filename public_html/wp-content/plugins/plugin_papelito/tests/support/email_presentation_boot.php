<?php
/**
 * Carrega a camada de apresentacao de e-mail nos testes standalone.
 *
 * Desde que os avisos transacionais passaram a montar o corpo com
 * `papelito_email_notice_html()` e a enviar por `papelito_email_send()`, um
 * harness que so faz stub de `wp_mail()` quebra ao carregar o modulo de dominio.
 * Carregar a apresentacao de verdade — em vez de fingi-la — mantem as assercoes
 * sobre corpo do e-mail valendo contra o HTML que o cliente recebe.
 *
 * O teste continua dono do stub de `wp_mail()`: este arquivo so preenche as
 * funcoes do WordPress que a apresentacao usa.
 *
 * @package Papelito
 */

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escapa texto para HTML.
	 *
	 * @param mixed $value Valor cru.
	 * @return string
	 */
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escapa texto para atributo HTML.
	 *
	 * @param mixed $value Valor cru.
	 * @return string
	 */
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escapa uma URL para uso em atributo.
	 *
	 * @param mixed $value URL crua.
	 * @return string
	 */
	function esc_url( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Converte para inteiro nao negativo.
	 *
	 * @param mixed $value Valor cru.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Remove tags HTML de um texto.
	 *
	 * @param mixed $value Valor cru.
	 * @return string
	 */
	function wp_strip_all_tags( $value ) {
		return preg_replace( '/<[^>]*>/', '', (string) $value );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub inerte do registrador de acoes.
	 *
	 * @param mixed ...$args Ignorados.
	 * @return void
	 */
	function add_action( ...$args ) {}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Stub inerte do removedor de acoes.
	 *
	 * @param mixed ...$args Ignorados.
	 * @return void
	 */
	function remove_action( ...$args ) {}
}

if ( ! function_exists( 'plugins_url' ) ) {
	/**
	 * URL publica de um arquivo do plugin.
	 *
	 * @param string $path   Caminho relativo dentro do plugin.
	 * @param string $plugin Arquivo principal do plugin, ignorado no stub.
	 * @return string
	 */
	function plugins_url( $path, $plugin = '' ) {
		unset( $plugin );

		return 'https://papelito.test/wp-content/plugins/plugin_papelito/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'mb_strtoupper' ) ) {
	/**
	 * Fallback de caixa alta quando a extensao mbstring nao esta carregada.
	 *
	 * @param mixed $value Valor cru.
	 * @return string
	 */
	function mb_strtoupper( $value ) {
		return strtoupper( (string) $value );
	}
}

require_once __DIR__ . '/../../includes/notification_emails.php';
