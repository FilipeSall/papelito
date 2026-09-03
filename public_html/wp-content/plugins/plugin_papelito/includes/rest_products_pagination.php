<?php
/**
 * Preserva o `per_page` da REST de produtos quando a consulta tem busca.
 *
 * O tema JupiterX registra `jupiterx_modify_search_page_query` em `pre_get_posts` para afinar a
 * PÁGINA de busca do front. O guard dele é `is_admin() || ! is_search() || post_type !== 'product'`,
 * que não exclui requisição REST: numa chamada `/wc/v3/products?search=...` nada disso barra, e a
 * última linha da função sobrescreve `posts_per_page` com `jupiterx_search_posts_per_page` (5 por
 * padrão). Resultado: a listagem administrativa devolvia 5 itens por página durante a busca,
 * ignorando o `per_page` pedido, e o header `X-WP-TotalPages` vinha calculado em cima desses 5.
 *
 * O tema é código de terceiro e não pode ser editado. A correção então guarda o `posts_per_page`
 * que o WooCommerce montou a partir do `per_page` da requisição e o reaplica no fim da cadeia de
 * `pre_get_posts`, só para consulta REST de produto. A página de busca do front do WordPress segue
 * com o comportamento do tema.
 *
 * @package Papelito
 */

/**
 * Prioridade alta o bastante para rodar depois do tema, que registra na prioridade padrão.
 */
const PAPELITO_REST_PER_PAGE_PRIORITY = 999;

/**
 * Guarda e devolve o `posts_per_page` da requisição REST de produtos em curso.
 *
 * @param int|null $per_page Valor a guardar; omitido apenas lê.
 * @return int|null Valor guardado nesta requisição, ou null quando não há.
 */
function papelito_rest_products_per_page( $per_page = null ) {
	static $stored = null;

	if ( null !== $per_page ) {
		$stored = $per_page > 0 ? (int) $per_page : null;
	}

	return $stored;
}

/**
 * Captura o `posts_per_page` que o WooCommerce derivou do `per_page` da requisição.
 *
 * @param array<string, mixed> $args Argumentos da WP_Query montados pelo controller REST.
 * @return array<string, mixed> Argumentos inalterados.
 */
function papelito_rest_capture_products_per_page( $args ) {
	if ( isset( $args['posts_per_page'] ) ) {
		papelito_rest_products_per_page( (int) $args['posts_per_page'] );
	}

	return $args;
}
add_filter( 'woocommerce_rest_product_object_query', 'papelito_rest_capture_products_per_page', 10, 1 );

/**
 * Reaplica o `per_page` pedido caso algum `pre_get_posts` anterior o tenha trocado.
 *
 * @param WP_Query $query Consulta em preparação.
 * @return void
 */
function papelito_rest_restore_products_per_page( $query ) {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return;
	}

	if ( 'product' !== ( $query->query_vars['post_type'] ?? '' ) ) {
		return;
	}

	$intended = papelito_rest_products_per_page();

	if ( null === $intended || (int) $query->get( 'posts_per_page' ) === $intended ) {
		return;
	}

	$query->set( 'posts_per_page', $intended );
}
add_action( 'pre_get_posts', 'papelito_rest_restore_products_per_page', PAPELITO_REST_PER_PAGE_PRIORITY );
