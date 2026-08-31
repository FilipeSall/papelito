<?php
/**
 * Standalone regression test for the vendor stock REST args.
 *
 * Estrutural: nenhum `sanitize_callback` das rotas de estoque pode devolver algo
 * que não seja string. O REST chama o callback com ( $value, $request, $param ),
 * e funções do core com assinatura ( $value, $fallback, $context ) — como
 * `sanitize_title()` — devolvem o **request** quando o valor é vazio. O callback
 * da rota então faz `(string) $request->get_param(...)` e a requisição inteira
 * morre com fatal: a listagem de estoque volta vazia para todo vendor, mesmo sem
 * nenhum filtro aplicado.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );

/**
 * Mesma armadilha do core: devolve o fallback (2º argumento) quando o valor sai vazio.
 */
function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
	$title = strtolower( trim( (string) $title ) );

	if ( '' === $title || false === $title ) {
		$title = $fallback_title;
	}

	return $title;
}

function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function get_post_thumbnail_id( $post_id ) { return 0; }
function wp_get_object_terms( $ids, $taxonomy, $args ) { return array(); }
function papelito_curated_collections() { return array( 'premium' ); }
function papelito_product_taxonomy_table_names() { return array( 'product_collection' => 'wp_papelito_product_collection' ); }
function papelito_taxonomy_exists_clause( $product_expr, $category_id, array $subcategory_ids, $unresolved = false ) { return null; }
function papelito_products_category_map( array $ids ) { return array(); }
function papelito_products_subcategory_map( array $ids ) { return array(); }
function papelito_kits_table_names() { return array( 'kits' => 'wp_papelito_kits', 'items' => 'wp_papelito_kit_items' ); }

function add_action( $hook, $callback = null, ...$rest ) {
	$GLOBALS['papelito_test_actions'][ $hook ][] = $callback;
}

function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['papelito_test_routes'][] = array(
		'route' => $route,
		'args'  => isset( $args['args'] ) ? $args['args'] : array(),
	);
}

class WP_Error {}
class WP_REST_Response {}

class WP_REST_Request {
	public function get_param( $key ) { return null; }
}

class Papelito_Rest_Args_Test_WPDB {
	public string $prefix   = 'wp_';
	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';

	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) { return 0; }
	public function get_results( $query, $output = null ) { return array(); }
	public function esc_like( $text ) { return $text; }
}

$wpdb                        = new Papelito_Rest_Args_Test_WPDB();
$GLOBALS['papelito_test_actions'] = array();
$GLOBALS['papelito_test_routes']  = array();

require_once __DIR__ . '/../includes/vendor_stock.php';

foreach ( $GLOBALS['papelito_test_actions']['rest_api_init'] ?? array() as $callback ) {
	$callback();
}

$failures = 0;
$checks   = 0;

function papelito_test_assert( bool $condition, string $message ): void {
	global $failures, $checks;

	++$checks;

	if ( ! $condition ) {
		++$failures;
		echo "FALHOU: {$message}\n";
	}
}

papelito_test_assert( count( $GLOBALS['papelito_test_routes'] ) >= 4, 'as rotas de estoque são registradas' );

$request        = new WP_REST_Request();
$sanitized_args = 0;

foreach ( $GLOBALS['papelito_test_routes'] as $route ) {
	foreach ( $route['args'] as $param => $definition ) {
		if ( ! isset( $definition['sanitize_callback'] ) ) {
			continue;
		}

		++$sanitized_args;

		// Exatamente como o WP_REST_Server chama: valor, request, nome do parâmetro.
		$empty = call_user_func( $definition['sanitize_callback'], '', $request, $param );

		papelito_test_assert(
			is_string( $empty ),
			"{$route['route']}::{$param} devolve string com valor vazio (devolveu " . get_debug_type( $empty ) . ')'
		);

		$filled = call_user_func( $definition['sanitize_callback'], 'Premium', $request, $param );

		papelito_test_assert(
			is_string( $filled ),
			"{$route['route']}::{$param} devolve string com valor preenchido"
		);
	}
}

papelito_test_assert( $sanitized_args >= 3, 'os parâmetros com sanitize_callback foram exercitados' );

echo "{$checks} verificacoes, {$failures} falhas\n";

exit( $failures > 0 ? 1 : 0 );
