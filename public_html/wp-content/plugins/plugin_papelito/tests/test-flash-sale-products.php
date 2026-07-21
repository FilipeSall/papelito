<?php
/**
 * Standalone regression test for flash-sale eligible product discovery.
 *
 * Usage: php tests/test-flash-sale-products.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function remove_filter( ...$args ) {}
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_key( mixed $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_title( mixed $value ) { return strtolower( str_replace( ' ', '-', trim( (string) $value ) ) ); }
function wp_timezone() { return new DateTimeZone( 'America/Sao_Paulo' ); }
function wp_unslash( mixed $value ) { return $value; }
function wc_format_decimal( mixed $value ) { return (string) $value; }
function wp_get_attachment_image_url( mixed $id, $size ) { return $id > 0 ? 'https://example.test/image.jpg' : false; }
function get_permalink( mixed $id ) { return 'https://example.test/produto/' . $id; }
function __return_true() { return true; }

class WP_User {}
class WP_REST_Request {}
class WP_REST_Response {}
class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'PUT';
	const DELETABLE = 'DELETE';
}

class Papelito_Test_WPDB {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public function esc_like( mixed $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( string $query, mixed ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
}

$wpdb = new Papelito_Test_WPDB();

class WC_Product {
	public int $id;
	public string $name;
	public string $sku;
	public string $status;
	public string $type;
	public string $weight;
	public int $category_id;

	public function __construct( int $id, string $name, string $sku, string $status = 'publish', string $type = 'simple', string $weight = '1', int $category_id = 7 ) {
		$this->id = $id;
		$this->name = $name;
		$this->sku = $sku;
		$this->status = $status;
		$this->type = $type;
		$this->weight = $weight;
		$this->category_id = $category_id;
	}

	public function get_id() { return $this->id; }
	public function get_name() { return $this->name; }
	public function get_sku() { return $this->sku; }
	public function get_status() { return $this->status; }
	public function get_weight( $context = 'view' ) { return $this->weight; }
	public function is_type( mixed $type ) { return $this->type === $type; }
	public function get_children() { return array(); }
	public function get_image_id() { return 0; }
	public function get_regular_price( $context = 'view' ) { return '121.00'; }
	public function get_price( $context = 'view' ) { return '99.90'; }
	public function get_average_rating() { return '0'; }
	public function get_review_count() { return 0; }
}

$papelito_test_products = array();
for ( $index = 1; $index <= 40; ++$index ) {
	$id = 11000 + $index;
	$papelito_test_products[ $id ] = new WC_Product(
		$id,
		$index === 40 ? 'Seda Slim King Size' : 'Produto ' . $index,
		$index === 40 ? 'PP01070003' : 'SKU-' . $index,
		'publish',
		$index === 39 ? 'variable' : 'simple',
		'1',
		$index > 35 ? 9 : 7
	);
}
$papelito_test_products[12001] = new WC_Product( 12001, 'Rascunho', 'DRAFT-1', 'draft' );
$papelito_test_products[12002] = new WC_Product( 12002, 'Sem Peso', 'NOWEIGHT-1', 'publish', 'simple', '0' );

function wc_get_product( mixed $product_id ) {
	global $papelito_test_products;
	return $papelito_test_products[ (int) $product_id ] ?? false;
}

function papelito_product_has_valid_weight( WC_Product $product ) {
	return (float) $product->get_weight( 'edit' ) > 0;
}

function wc_get_product_terms( mixed $product_id, $taxonomy, $args ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) return array();
	return array( (object) array( 'term_id' => $product->category_id, 'name' => 'Categoria ' . $product->category_id, 'slug' => 'categoria-' . $product->category_id ) );
}

class WP_Query {
	public array $posts = array();

	public function __construct( array $args ) {
		global $papelito_test_products;
		$products = array_values( $papelito_test_products );
		$products = array_filter(
			$products,
			static function ( WC_Product $product ) use ( $args ): bool {
				if ( 'publish' !== $product->get_status() ) return false;
				if ( isset( $args['p'] ) && (int) $args['p'] !== $product->get_id() ) return false;
				if ( isset( $args['tax_query'][0]['terms'] ) && (int) $args['tax_query'][0]['terms'] !== $product->category_id ) return false;
				return true;
			}
		);
		usort( $products, static fn( WC_Product $left, WC_Product $right ): int => $right->get_id() <=> $left->get_id() );
		$this->posts = array_map( static fn( WC_Product $product ): int => $product->get_id(), $products );
	}
}

require __DIR__ . '/../includes/flash_sale.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: all 40 eligible products are paginated\n";
$page_one = papelito_flash_sale_query_eligible_products( array( 'page' => 1, 'per_page' => 24 ) );
$page_two = papelito_flash_sale_query_eligible_products( array( 'page' => 2, 'per_page' => 24 ) );
papelito_assert_same( 'total eligible products', 40, $page_one['total'] ?? null );
papelito_assert_same( 'first page size', 24, count( $page_one['items'] ?? array() ) );
papelito_assert_same( 'second page size', 16, count( $page_two['items'] ?? array() ) );
papelito_assert_same( 'total pages', 2, $page_two['totalPages'] ?? null );

echo "Scenario 2: exact ID, partial name and partial SKU find page-two product\n";
$by_id = papelito_flash_sale_query_eligible_products( array( 'search' => '11040' ) );
$by_name = papelito_flash_sale_query_eligible_products( array( 'search' => 'Slim King' ) );
$by_sku = papelito_flash_sale_query_eligible_products( array( 'search' => '010700' ) );
papelito_assert_same( 'ID search', 11040, $by_id['items'][0]['id'] ?? null );
papelito_assert_same( 'name search', 11040, $by_name['items'][0]['id'] ?? null );
papelito_assert_same( 'SKU search', 11040, $by_sku['items'][0]['id'] ?? null );

echo "Scenario 3: category, variable parent and eligibility rules are preserved\n";
$category = papelito_flash_sale_query_eligible_products( array( 'category' => 9, 'per_page' => 24 ) );
$category_ids = array_column( $category['items'] ?? array(), 'id' );
papelito_assert_same( 'category filter count', 5, $category['total'] ?? null );
papelito_assert_same( 'variable parent is listed', true, in_array( 11039, $category_ids, true ) );
papelito_assert_same( 'draft excluded', false, in_array( 12001, $category_ids, true ) );
papelito_assert_same( 'invalid weight excluded', false, in_array( 12002, $category_ids, true ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
