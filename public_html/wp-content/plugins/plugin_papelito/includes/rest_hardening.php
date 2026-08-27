<?php
/**
 * Endurecimento da superficie REST do WordPress em operacao headless.
 *
 * O front do WordPress nao e usado: quem atende o publico e o app Next. As rotas
 * de nucleo que so servem ao front continuam registradas e publicas, entao sao
 * removidas aqui.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rotas de nucleo removidas da superficie REST.
 *
 * `/wp/v2/users` responde a visitante anonimo e permite enumerar contas: nomes
 * de exibicao e slugs de login de todos os usuarios com posts publicados. Nada
 * no Papelito consome essa rota — o app le identidade por
 * `/papelito/v1/auth/me`, que exige Bearer.
 *
 * @return array<string, string[]>
 */
function papelito_rest_hardening_blocked_routes(): array {
	return array(
		'/wp/v2/users',
		'/wp/v2/users/(?P<id>[\d]+)',
	);
}

/**
 * Remove as rotas bloqueadas antes do REST resolver o pedido.
 *
 * @param array<string, mixed> $endpoints Rotas registradas.
 * @return array<string, mixed>
 */
function papelito_rest_hardening_filter_endpoints( $endpoints ) {
	if ( ! is_array( $endpoints ) ) {
		return $endpoints;
	}

	foreach ( papelito_rest_hardening_blocked_routes() as $route ) {
		unset( $endpoints[ $route ] );
	}

	return $endpoints;
}
add_filter( 'rest_endpoints', 'papelito_rest_hardening_filter_endpoints' );
