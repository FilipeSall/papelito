<?php
/**
 * Standalone regression test for the public flash-sale endpoint response contract.
 *
 * O estado "sem campanha" precisa sair como 200 com payload vazio. Um 404 nunca
 * substitui a entrada do Data Cache do Next (só status 200 é gravado), então a
 * vitrine continuaria servindo o preço promocional da última campanha ativa por
 * tempo indeterminado.
 *
 * Usage: php tests/test-flash-sale-home-response.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( mixed ...$args ): void { unset( $args ); }
function add_filter( mixed ...$args ): void { unset( $args ); }
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_title( mixed $value ) { return strtolower( str_replace( ' ', '-', trim( (string) $value ) ) ); }
function wp_timezone() { return new DateTimeZone( 'America/Sao_Paulo' ); }
function wp_json_encode( mixed $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_salt( $scheme = 'auth' ) { return 'test-only-signing-secret'; }
function wp_unslash( mixed $value ) { return $value; }
function wc_format_decimal( mixed $value ) { return (string) $value; }
function wp_get_attachment_image_url( mixed $id, $size ) { return $id > 0 ? 'https://example.test/image.jpg' : false; }
function get_permalink( mixed $id ) { return 'https://example.test/produto/' . $id; }
function wp_get_current_user() { return null; }

$papelito_test_option = array();

function get_option( $name, $default = array() ) {
	global $papelito_test_option;
	unset( $name );
	return empty( $papelito_test_option ) ? $default : $papelito_test_option;
}

class WP_User {} // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.
class WP_REST_Request {} // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.

class WP_REST_Response { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.
	private mixed $data;
	private int $status;

	public function __construct( mixed $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}

class WP_REST_Server { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o código sob teste.
	const READABLE  = 'GET';
	const EDITABLE  = 'PUT';
	const DELETABLE = 'DELETE';
}

class WP_Error { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o instanceof do código sob teste.
	private string $code;
	public function __construct( string $code, string $message, mixed $data = null ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}

function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }

class WC_Product { // NOSONAR -- o nome é o da classe do WooCommerce; renomear quebraria o código sob teste.
	public function __construct(
		private int $id,
		private string $status = 'publish'
	) {}

	public function get_id() { return $this->id; }
	public function get_name() { return 'Seda Alfafa King Size'; }
	public function get_sku() { return 'PP01070003'; }
	public function get_status() { return $this->status; }
	public function get_weight( $context = 'view' ) { return '1'; }
	public function get_image_id() { return 0; }
	public function get_regular_price( $context = 'view' ) { return '240.00'; }
	public function get_price( $context = 'view' ) { return '240.00'; }
	public function get_average_rating() { return '0'; }
	public function get_review_count() { return 0; }
}

$papelito_test_products = array(
	11798 => new WC_Product( 11798 ),
	12001 => new WC_Product( 12001, 'draft' ),
);

function wc_get_product( mixed $product_id ) {
	global $papelito_test_products;
	return $papelito_test_products[ (int) $product_id ] ?? false;
}

function papelito_product_has_valid_weight( WC_Product $product ) {
	return (float) $product->get_weight( 'edit' ) > 0;
}

function papelito_product_get_category( mixed $product_id ) {
	return wc_get_product( $product_id ) ? array( 'id' => 7, 'name' => 'Sedas', 'slug' => 'sedas' ) : null;
}

function wc_get_product_terms( mixed $product_id, $taxonomy, $args ) {
	return array( (object) array( 'term_id' => 7, 'name' => 'Clássico', 'slug' => 'classico' ) );
}

require_once __DIR__ . '/../includes/flash_sale.php';

$failures = 0;

function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
	echo '        esperado: ' . var_export( $expected, true ) . "\n";
	echo '        recebido: ' . var_export( $actual, true ) . "\n";
}

function papelito_response_field( WP_REST_Response $response, string $field ): mixed {
	$data = $response->get_data();
	return is_array( $data ) && array_key_exists( $field, $data ) ? $data[ $field ] : 'ausente';
}

function papelito_set_campaign( array $campaign ): void {
	global $papelito_test_option;
	$papelito_test_option = $campaign;
}

function papelito_campaign( string $starts_at, string $ends_at, array $product_ids = array( 11798 ) ): array {
	return array(
		'title'           => 'Queimão',
		'slug'            => 'queimao',
		'starts_at'       => $starts_at,
		'ends_at'         => $ends_at,
		'productIds'      => $product_ids,
		'discountPercent' => 99,
		'label'           => 'Oferta Relâmpago',
		'supportingText'  => '',
	);
}

$now      = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Sao_Paulo' ) );
$past     = $now->modify( '-10 days' )->format( 'Y-m-d\TH:i:sP' );
$recent   = $now->modify( '-1 day' )->format( 'Y-m-d\TH:i:sP' );
$future   = $now->modify( '+10 days' )->format( 'Y-m-d\TH:i:sP' );
$far      = $now->modify( '+20 days' )->format( 'Y-m-d\TH:i:sP' );

echo "Scenario 1: sem campanha salva\n";
papelito_set_campaign( array() );
$response = papelito_flash_sale_home_response();
papelito_assert_same( 'status é 200 para o Next poder gravar no cache', 200, $response->get_status() );
papelito_assert_same( 'campaign vem nulo', null, papelito_response_field( $response, 'campaign' ) );
papelito_assert_same( 'products vem vazio', array(), papelito_response_field( $response, 'products' ) );

echo "Scenario 2: campanha expirada pelo relógio\n";
papelito_set_campaign( papelito_campaign( $past, $recent ) );
$response = papelito_flash_sale_home_response();
papelito_assert_same( 'status é 200', 200, $response->get_status() );
papelito_assert_same( 'campaign vem nulo', null, papelito_response_field( $response, 'campaign' ) );
papelito_assert_same( 'products vem vazio', array(), papelito_response_field( $response, 'products' ) );

echo "Scenario 3: campanha agendada para o futuro\n";
papelito_set_campaign( papelito_campaign( $future, $far ) );
$response = papelito_flash_sale_home_response();
papelito_assert_same( 'status é 200', 200, $response->get_status() );
papelito_assert_same( 'campaign vem nulo', null, papelito_response_field( $response, 'campaign' ) );

echo "Scenario 4: campanha ativa sem produto publicado\n";
papelito_set_campaign( papelito_campaign( $past, $future, array( 12001 ) ) );
$response = papelito_flash_sale_home_response();
papelito_assert_same( 'status é 200', 200, $response->get_status() );
papelito_assert_same( 'campaign vem nulo', null, papelito_response_field( $response, 'campaign' ) );
papelito_assert_same( 'products vem vazio', array(), papelito_response_field( $response, 'products' ) );

echo "Scenario 5: campanha ativa continua devolvendo os produtos\n";
papelito_set_campaign( papelito_campaign( $past, $future ) );
$response = papelito_flash_sale_home_response();
$data     = $response->get_data();
papelito_assert_same( 'status é 200', 200, $response->get_status() );
papelito_assert_same( 'status da campanha é active', 'active', $data['campaign']['status'] ?? 'ausente' );
papelito_assert_same( 'desconto de 99% preservado', 99, $data['campaign']['discountPercent'] ?? 'ausente' );
papelito_assert_same( 'um produto na campanha', 1, count( $data['products'] ) );
papelito_assert_same( 'preço promocional calculado', 2.4, $data['products'][0]['price'] ?? 'ausente' );
papelito_assert_same( 'preço original preservado', 240.0, $data['products'][0]['originalPrice'] ?? 'ausente' );
papelito_assert_same(
	'promotionContext assinado emitido',
	true,
	'' !== ( $data['products'][0]['promotionContext'] ?? '' )
);

echo "\n";
if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s)\n";
	exit( 1 );
}

echo "OK\n";
exit( 0 );
