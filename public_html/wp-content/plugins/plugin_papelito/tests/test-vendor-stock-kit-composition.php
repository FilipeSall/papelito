<?php
/**
 * Standalone regression test for the kit composition of the vendor stock list.
 *
 * A invariante coberta aqui é de performance: a composição de todos os kits da
 * página sai em um número fixo de consultas. Um `SELECT` por kit voltaria como
 * N+1 na listagem do vendor.
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
function wp_get_attachment_image_url( $attachment_id, $size ) { return 'https://cdn.local/caderno.webp'; }
function wp_get_object_terms( $ids, $taxonomy, $args ) { return array(); }
function papelito_curated_collections() { return array( 'premium' ); }
function papelito_product_taxonomy_table_names() { return array( 'product_collection' => 'wp_papelito_product_collection' ); }
function papelito_taxonomy_exists_clause( $product_expr, $category_id, array $subcategory_ids, $unresolved = false ) { return null; }
function papelito_products_category_map( array $ids ) { return array(); }
function papelito_products_subcategory_map( array $ids ) { return array(); }
function papelito_kits_table_names() {
	return array(
		'kits'  => 'wp_papelito_kits',
		'items' => 'wp_papelito_kit_items',
	);
}

/**
 * Mantido como sentinela para garantir que a composição não volte a consultar a
 * disponibilidade de kits uma vez por linha.
 */
function papelito_kits_stock_rows_by_vendor_batch( array $product_ids, array $qty_by_product, array $vendor_ids ): array {
	global $kit_batch_calls;

	++$kit_batch_calls;

	return array( 30 => array( array( 'vendor_id' => 77, 'qty' => 2 ) ) );
}

class WP_Error {}
class WP_REST_Request {}
class WP_REST_Response {}

class Papelito_Kit_Composition_Test_WPDB {
	public string $prefix   = 'wp_';
	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';

	public int $result_queries = 0;

	public function esc_like( $text ) {
		return $text;
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_var( $query ) {
		return 0;
	}

	public function get_results( $query, $output = null ) {
		++$this->result_queries;

		if ( str_contains( $query, "meta_key = '_thumbnail_id'" ) ) {
			return array(
				array(
					'post_id'    => 21,
					'meta_value' => 900,
				),
			);
		}

		if ( str_contains( $query, 'wp_papelito_kits' ) ) {
			return array(
				array(
					'id'         => 5,
					'product_id' => 30,
					'slug'       => 'kit-escolar-completo',
				),
			);
		}

		if ( str_contains( $query, 'wp_papelito_kit_items' ) ) {
			return array(
				array(
					'kit_id'     => 5,
					'product_id' => 21,
					'quantity'   => 2,
				),
				array(
					'kit_id'     => 5,
					'product_id' => 22,
					'quantity'   => 3,
				),
			);
		}

		if ( str_contains( $query, 'sku.meta_value AS sku' ) ) {
			return array(
				array(
					'ID'         => 21,
					'post_title' => 'Caderno Universitário',
					'sku'        => 'PROD-001',
				),
				array(
					'ID'         => 22,
					'post_title' => 'Caneta Azul',
					'sku'        => 'PROD-002',
				),
			);
		}

		if ( str_contains( $query, 'wp_papelito_vendor_stock' ) ) {
			return array(
				array(
					'product_id' => 21,
					'qty'        => 8,
				),
			);
		}

		return array();
	}
}

$wpdb            = new Papelito_Kit_Composition_Test_WPDB();
$kit_batch_calls = 0;

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

$compositions = papelito_vendor_stock_kit_compositions( 77, array( 30, 31, 32 ) );

papelito_test_assert( array_keys( $compositions ) === array( 30 ), 'só o produto que é kit ganha composição' );

$kit = $compositions[30];

papelito_test_assert( 5 === $kit['kit_id'], 'a composição carrega o id do kit' );
papelito_test_assert( 'kit-escolar-completo' === $kit['slug'], 'a composição carrega o slug do kit' );
papelito_test_assert( 0 === $kit['assemblable_qty'], 'um componente zerado impede montar o kit' );
papelito_test_assert( 2 === count( $kit['items'] ), 'os dois itens do kit voltam aninhados no kit' );

papelito_test_assert(
	$kit['items'][0] === array(
		'product_id'   => 21,
		'product_name' => 'Caderno Universitário',
		'sku'          => 'PROD-001',
		'image_url'    => 'https://cdn.local/caderno.webp',
		'quantity'     => 2,
		'qty'          => 8,
		'is_zeroed'    => false,
	),
	'item com estoque traz nome, sku, imagem, quantidade no kit e estoque do vendor'
);

papelito_test_assert(
	3 === $kit['items'][1]['quantity'] && 0 === $kit['items'][1]['qty'] && true === $kit['items'][1]['is_zeroed'],
	'item sem linha de estoque conta como zerado'
);

papelito_test_assert(
	$wpdb->result_queries <= 5,
	"a composição da página inteira sai em consultas fixas (foram {$wpdb->result_queries})"
);
papelito_test_assert( 0 === $kit_batch_calls, 'a composição reaproveita os estoques já carregados, sem uma segunda consulta de disponibilidade' );

// Página sem kit nenhum não deve encostar nas tabelas de item.
$wpdb->result_queries = 0;
papelito_test_assert(
	array() === papelito_vendor_stock_kit_compositions( 0, array( 30 ) ),
	'sem vendor identificado não há composição'
);
papelito_test_assert( 0 === $wpdb->result_queries, 'sem vendor identificado nenhuma consulta é feita' );

echo "{$checks} verificacoes, {$failures} falhas\n";

exit( $failures > 0 ? 1 : 0 );
