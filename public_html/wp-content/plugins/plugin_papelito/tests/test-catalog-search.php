<?php
/**
 * Standalone regression test for public catalog search.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function __return_true() { return true; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_title( $value ) { return strtolower( trim( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function remove_accents( $value ) { return strtr( (string) $value, array( 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Ç' => 'C', 'ç' => 'c' ) ); }
class WP_REST_Request {}
class WP_REST_Response {}
class WP_Error {}

class Papelito_Catalog_Search_Test_WPDB {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $term_relationships = 'wp_term_relationships';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $terms = 'wp_terms';
	public array $products = array();
	public array $tags = array();
	public int $queries = 0;

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_results( $query, $output ) {
		++$this->queries;
		if ( str_contains( $query, "taxonomy.taxonomy = 'product_tag'" ) ) {
			$rows = array();
			foreach ( $this->tags as $product_id => $tags ) {
				foreach ( $tags as $tag ) {
					$rows[] = array( 'product_id' => $product_id, 'name' => $tag );
				}
			}
			return $rows;
		}

		return $this->products;
	}
}

$wpdb = new Papelito_Catalog_Search_Test_WPDB();
require __DIR__ . '/../includes/catalog_search.php';

$failures = 0;
function papelito_catalog_search_assert_same( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

function papelito_catalog_search_seed( int $count ): void {
	global $wpdb;
	$wpdb->products = array();
	$wpdb->tags     = array();
	for ( $index = 1; $index <= $count; ++$index ) {
		$wpdb->products[] = array( 'ID' => $index, 'post_title' => 'Produto ' . $index );
	}
	$wpdb->products[0] = array( 'ID' => 1, 'post_title' => 'SEDA TRADICIONAL MINI SIZE' );
	$wpdb->tags[1]     = array( 'vegano' );
	if ( $count > 1 ) {
		$wpdb->products[1] = array( 'ID' => 2, 'post_title' => 'PITEIRA ULTRA LONGA' );
	}
	if ( $count > 2 ) {
		$wpdb->products[2] = array( 'ID' => 3, 'post_title' => 'SEDA ECOLÓGICA BROWN' );
	}
}

echo "Scenario 1: normalização, prefixos e combinação de campos\n";
papelito_catalog_search_seed( 4 );
$result = papelito_catalog_search_products( array( 'search' => 'SEDA TRADICIONAL MINI SIZE', 'per_page' => 9 ) );
papelito_catalog_search_assert_same( 'nome completo sem diferenciar caixa', array( 1 ), $result['ids'] );
$result = papelito_catalog_search_products( array( 'search' => 'Seda trad', 'per_page' => 9 ) );
papelito_catalog_search_assert_same( 'nome parcial', array( 1 ), $result['ids'] );
$result = papelito_catalog_search_products( array( 'search' => 'mini size', 'per_page' => 9 ) );
papelito_catalog_search_assert_same( 'múltiplas palavras', array( 1 ), $result['ids'] );
$result = papelito_catalog_search_products( array( 'search' => 'seda vegan', 'per_page' => 9 ) );
papelito_catalog_search_assert_same( 'nome mais tag', array( 1 ), $result['ids'] );
$result = papelito_catalog_search_products( array( 'search' => 'pite ultra', 'per_page' => 9 ) );
papelito_catalog_search_assert_same( 'partes de duas palavras', array( 2 ), $result['ids'] );
$result = papelito_catalog_search_products( array( 'search' => 'seda ecologica', 'per_page' => 9 ) );
papelito_catalog_search_assert_same( 'acentos ignorados', array( 3 ), $result['ids'] );
$result = papelito_catalog_search_products( array( 'search' => 'vegan', 'per_page' => 9 ) );
papelito_catalog_search_assert_same( 'tag parcial', array( 1 ), $result['ids'] );
$result = papelito_catalog_search_products( array( 'search' => 'vegano', 'per_page' => 9 ) );
papelito_catalog_search_assert_same( 'tag completa', array( 1 ), $result['ids'] );

echo "Scenario 2: paginação e resultado vazio\n";
papelito_catalog_search_seed( 12 );
$result = papelito_catalog_search_products( array( 'search' => 'produto', 'page' => 2, 'per_page' => 5 ) );
papelito_catalog_search_assert_same( 'total paginado', 9, $result['total'] );
papelito_catalog_search_assert_same( 'segunda página', array( 9, 10, 11, 12 ), $result['ids'] );
$result = papelito_catalog_search_products( array( 'search' => 'inexistente', 'per_page' => 5 ) );
papelito_catalog_search_assert_same( 'resultado vazio', array(), $result['ids'] );

echo "Scenario 3: tags são carregadas em lote\n";
foreach ( array( 1, 10, 40 ) as $count ) {
	papelito_catalog_search_seed( $count );
	$wpdb->queries = 0;
	papelito_catalog_search_products( array( 'search' => 'produto', 'per_page' => 9 ) );
	papelito_catalog_search_assert_same( "consultas para {$count} produtos", 2, $wpdb->queries );
}

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
