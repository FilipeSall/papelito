<?php
/**
 * Standalone regression test for price filtering over flash-sale prices in catalog search.
 *
 * Usage: php tests/test-catalog-search-campaign-price.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( mixed ...$args ): void { unset( $args ); }
function remove_accents( mixed $value ) { return (string) $value; }
function sanitize_title( mixed $value ) { return strtolower( str_replace( ' ', '-', trim( (string) $value ) ) ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function wp_unslash( mixed $value ) { return $value; }
function get_transient( string $key ) { unset( $key ); return 0; }
function set_transient( string $key, mixed $value, int $ttl ) { unset( $key, $value, $ttl ); return true; }

class WP_REST_Request {} // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.
class WP_REST_Response { // NOSONAR -- idem.
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
}
class WP_Error { // NOSONAR -- idem.
	public function __construct( string $code, string $message, mixed $data = null ) { unset( $code, $message, $data ); }
}

class PapelitoTestWpdb {
	public string $posts            = 'wp_posts';
	public string $postmeta         = 'wp_postmeta';
	public string $terms            = 'wp_terms';
	public string $termTaxonomy     = 'wp_term_taxonomy';
	public string $termRelationships = 'wp_term_relationships';
	public string $lastQuery        = '';

	public function prepare( string $sql, array $params ): string {
		foreach ( $params as $param ) {
			$replacement = is_string( $param ) ? "'" . $param . "'" : (string) $param;
			$sql         = preg_replace( '/%[sdf]/', $replacement, $sql, 1 );
		}

		$this->lastQuery = $sql;

		return $sql;
	}

	public function get_results( string $sql, string $output = ARRAY_A ): array {
		unset( $sql, $output );
		return array();
	}
}

define( 'ARRAY_A', 'ARRAY_A' );

$wpdb = new PapelitoTestWpdb();

/**
 * Stubs da taxonomia própria.
 *
 * Este teste cobre relevância, ranking e faixa de preço da busca — não o filtro
 * de categoria, que tem suite própria (`test-product-taxonomy-catalog.php`, que
 * roda com o WordPress carregado). Os stubs deixam o filtro neutro para o resto
 * do comportamento ficar mensurável.
 */
function papelito_taxonomy_classified_clause( $product_expr ) { return '1 = 1'; }
function papelito_taxonomy_category_id_by_slug( $slug ) { return 0; }
function papelito_taxonomy_subcategory_ids_by_slugs( $category_id, array $slugs ) { return array(); }
function papelito_taxonomy_exists_clause( $product_expr, $category_id, array $subcategory_ids, $unresolved = false ) { return null; }
function papelito_taxonomy_has_unresolved_subcategory_slugs( $category_id, array $slugs ) { return false; }
function papelito_taxonomy_slug_filter_clause( $product_expr, array $categories, array $subcategories ) { return null; }

require_once __DIR__ . '/../includes/catalog_search.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} (esperado " . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . ")\n";
}

$campaign_prices = array( 11794 => 2.23 );

echo "Scenario 1: campaign product escapes the regular-price SQL filter\n";
papelito_catalog_search_product_rows(
	array(
		'categories'      => array(),
		'max_price'       => 10.0,
		'campaign_prices' => $campaign_prices,
	)
);
papelito_assert_same(
	'campaign id is OR-ed into the max price clause',
	true,
	str_contains( $wpdb->lastQuery, 'OR p.ID IN ( 11794 )' )
);

echo "Scenario 2: no campaign means the SQL stays untouched\n";
papelito_catalog_search_product_rows(
	array(
		'categories'      => array(),
		'max_price'       => 10.0,
		'campaign_prices' => array(),
	)
);
papelito_assert_same(
	'without campaign there is no escape clause',
	false,
	str_contains( $wpdb->lastQuery, 'OR p.ID IN' )
);

echo "Scenario 3: promotional price decides the range, not the regular price\n";
$rows = array(
	array( 'ID' => 11794, 'post_title' => 'Seda Insane Brown King Size' ),
	array( 'ID' => 11795, 'post_title' => 'Seda fora da campanha' ),
);
$within = papelito_catalog_search_filter_campaign_prices(
	$rows,
	$campaign_prices,
	array( 'max_price' => 10.0 )
);
papelito_assert_same( 'promotional product survives "até R$ 10"', 2, count( $within ) );

$outside = papelito_catalog_search_filter_campaign_prices(
	$rows,
	$campaign_prices,
	array( 'min_price' => 50.0 )
);
papelito_assert_same( 'promotional product is dropped from "a partir de R$ 50"', 1, count( $outside ) );
papelito_assert_same( 'the product kept is the one outside the campaign', 11795, (int) $outside[0]['ID'] );

$untouched = papelito_catalog_search_filter_campaign_prices( $rows, $campaign_prices, array() );
papelito_assert_same( 'without price range nothing is filtered', 2, count( $untouched ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
