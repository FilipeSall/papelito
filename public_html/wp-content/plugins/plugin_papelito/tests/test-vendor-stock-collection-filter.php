<?php
/**
 * Standalone regression test for the vendor stock collection filter.
 *
 * Cobre o que quebra em silêncio: a ordem dos parâmetros do `wpdb::prepare` da
 * listagem. Um `%s` a mais ou fora de posição derruba o estoque inteiro do
 * vendor, não só o filtro novo.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );

function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function absint( $value ) { return abs( (int) $value ); }
function sanitize_title( $value ) { return strtolower( trim( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function get_post_thumbnail_id( $post_id ) { return 0; }
function wp_get_object_terms( $ids, $taxonomy, $args ) { return array(); }
function papelito_curated_collections() { return array( 'premium', 'volta-as-aulas' ); }
function papelito_product_taxonomy_table_names() {
	return array(
		'product_category'    => 'wp_papelito_product_category',
		'product_subcategory' => 'wp_papelito_product_subcategory',
		'product_collection'  => 'wp_papelito_product_collection',
	);
}
function papelito_taxonomy_exists_clause( $product_expr, $category_id, array $subcategory_ids, $unresolved = false ) {
	if ( (int) $category_id <= 0 ) {
		return null;
	}

	return array(
		'sql'    => "EXISTS ( SELECT 1 FROM wp_papelito_product_category papelito_pc WHERE papelito_pc.product_id = {$product_expr} AND papelito_pc.category_id = %d )",
		'params' => array( (int) $category_id ),
	);
}
function papelito_products_category_map( array $ids ) { return array(); }
function papelito_products_subcategory_map( array $ids ) { return array(); }
function papelito_kits_table_names() {
	return array(
		'kits'        => 'wp_papelito_kits',
		'items'       => 'wp_papelito_kit_items',
		'merchandise' => 'wp_papelito_kit_merchandise',
	);
}

class WP_Error {}
class WP_REST_Request {}
class WP_REST_Response {}

class Papelito_Vendor_Stock_Test_WPDB {
	public string $prefix             = 'wp_';
	public string $posts              = 'wp_posts';
	public string $postmeta           = 'wp_postmeta';
	public string $term_relationships = 'wp_term_relationships';
	public string $term_taxonomy      = 'wp_term_taxonomy';

	/** @var array<int, array{sql:string,params:array}> */
	public array $prepared = array();

	public function esc_like( $text ) {
		return $text;
	}

	public function prepare( $query, ...$args ) {
		$params = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;

		$placeholders = preg_match_all( '/%[dsf]/', $query );

		if ( $placeholders !== count( $params ) ) {
			throw new RuntimeException(
				sprintf( 'prepare() com %d placeholders e %d parametros: %s', $placeholders, count( $params ), $query )
			);
		}

		$this->prepared[] = array(
			'sql'    => $query,
			'params' => $params,
		);

		return $query;
	}

	public function get_var( $query ) {
		return 0;
	}

	public function get_results( $query, $output = null ) {
		return array();
	}
}

$wpdb = new Papelito_Vendor_Stock_Test_WPDB();

require_once __DIR__ . '/../includes/vendor_stock.php';

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

function papelito_test_run_query( array $args ): array {
	global $wpdb;

	$wpdb->prepared = array();

	papelito_vendor_stock_query( 77, $args );

	return $wpdb->prepared;
}

// Sem coleção, a consulta não menciona a tabela de coleção.
$prepared = papelito_test_run_query( array( 'filter' => 'all' ) );
papelito_test_assert( count( $prepared ) >= 2, 'a listagem prepara ao menos count e select' );
papelito_test_assert(
	! str_contains( $prepared[0]['sql'], 'papelito_product_collection' ),
	'sem coleção selecionada a consulta não filtra por coleção'
);

// Coleção curada entra como EXISTS, com o slug logo depois dos parâmetros anteriores.
$prepared = papelito_test_run_query(
	array(
		'filter'     => 'with_stock',
		'search'     => 'seda',
		'category'   => 9,
		'tags'       => '12,45',
		'collection' => 'Premium',
	)
);
$count_sql = $prepared[0]['sql'];

papelito_test_assert(
	str_contains( $count_sql, 'wp_papelito_product_collection papelito_collection' ),
	'coleção curada vira EXISTS na tabela de coleção'
);
papelito_test_assert(
	str_contains( $count_sql, 'papelito_stock_item.quantity' ) && str_contains( $count_sql, 'papelito_pc.category_id' ),
	'coleção combina com a disponibilidade montável de kits e categoria em vez de substituí-los'
);
papelito_test_assert(
	str_contains( $count_sql, "tag_tt.taxonomy = 'product_tag'" ),
	'coleção combina com o filtro de tags'
);
papelito_test_assert(
	$prepared[0]['params'] === array( 77, 12, 45, 'product', 'product_variation', 'publish', 77, '%seda%', '%seda%', 9, 'premium' ),
	'os parâmetros incluem o vendor da disponibilidade de kit na ordem do WHERE'
);
papelito_test_assert(
	$prepared[1]['params'] === array( 77, 12, 45, 'product', 'product_variation', 'publish', 77, '%seda%', '%seda%', 9, 'premium', 20, 0 ),
	'o select repete os mesmos parâmetros antes do LIMIT/OFFSET'
);

// Tipo restringe pela entidade Kit, sem parâmetro novo e sem perder os demais filtros.
$prepared = papelito_test_run_query(
	array(
		'filter'     => 'with_stock',
		'collection' => 'premium',
		'type'       => 'kits',
	)
);
papelito_test_assert(
	str_contains( $prepared[0]['sql'], 'EXISTS ( SELECT 1 FROM wp_papelito_kits papelito_kit WHERE papelito_kit.product_id = p.ID )' ),
	'type=kits restringe pela tabela de kits, não por coleção legada'
);
papelito_test_assert(
	str_contains( $prepared[0]['sql'], 'papelito_product_collection' ) && str_contains( $prepared[0]['sql'], 'papelito_stock_item.quantity' ),
	'type combina com coleção e disponibilidade'
);
papelito_test_assert(
	$prepared[0]['params'] === array( 77, 'product', 'product_variation', 'publish', 77, 'premium' ),
	'o filtro de tipo preserva o parâmetro do vendor na disponibilidade de kit'
);

$prepared = papelito_test_run_query( array( 'sort' => 'qty_desc' ) );
papelito_test_assert(
	str_contains( $prepared[1]['sql'], 'papelito_stock_item.quantity' ) && str_contains( $prepared[1]['sql'], 'ORDER BY CASE WHEN' ),
	'ordenação por quantidade usa a disponibilidade montável do kit'
);
papelito_test_assert(
	$prepared[1]['params'] === array( 77, 'product', 'product_variation', 'publish', 77, 20, 0 ),
	'ordenação por quantidade inclui o vendor na expressão do ORDER BY'
);

$prepared = papelito_test_run_query( array( 'type' => 'products' ) );
papelito_test_assert(
	str_contains( $prepared[0]['sql'], 'NOT EXISTS ( SELECT 1 FROM wp_papelito_kits papelito_kit' ),
	'type=products exclui os kits'
);

$prepared = papelito_test_run_query( array( 'type' => 'qualquer-coisa' ) );
papelito_test_assert(
	str_contains( $prepared[0]['sql'], 'NOT EXISTS ( SELECT 1 FROM wp_papelito_kits papelito_kit' ),
	'tipo desconhecido volta ao recorte padrão de produtos'
);

// Slug fora da curadoria falha fechado: nenhum produto, nunca a lista inteira.
$prepared = papelito_test_run_query( array( 'collection' => 'inexistente' ) );
papelito_test_assert(
	str_contains( $prepared[0]['sql'], '1 = 0' ),
	'coleção desconhecida falha fechado'
);
papelito_test_assert(
	! str_contains( $prepared[0]['sql'], 'papelito_product_collection' ),
	'coleção desconhecida não vira consulta na tabela de coleção'
);

echo "{$checks} verificacoes, {$failures} falhas\n";

exit( $failures > 0 ? 1 : 0 );
