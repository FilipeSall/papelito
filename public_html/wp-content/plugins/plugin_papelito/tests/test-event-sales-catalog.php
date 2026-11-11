<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public string $code;
	public array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

class WP_User {
	public int $ID;
	public string $display_name;
	public array $allcaps;

	public function __construct( int $id = 0, string $name = '', array $caps = array() ) {
		$this->ID           = $id;
		$this->display_name = $name;
		$this->allcaps      = $caps;
	}
}

class WC_Product {
	public array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function get_id(): int { return $this->data['id']; }
	public function get_status(): string { return 'publish'; }
	public function get_catalog_visibility( string $context = 'view' ): string { return $this->data['visibility'] ?? 'visible'; }
	public function get_name(): string { return $this->data['name']; }
	public function get_slug(): string { return 'produto-' . $this->data['id']; }
	public function get_image_id(): int { return $this->data['id']; }
	public function get_price( string $context = 'view' ): string { return $this->data['price']; }
	public function get_short_description( string $context = 'view' ): string { return '<p>Descrição</p>'; }
	public function get_total_sales( string $context = 'view' ): int { return $this->data['sales']; }
	public function get_date_created(): DateTimeImmutable { return new DateTimeImmutable( $this->data['created'] ); }
	public function get_attributes(): array { return $this->data['attributes'] ?? array(); }
	public function get_attribute( string $name ): string { return $this->data['attributes'][ $name ] ?? ''; }
}

const EVENT_CATALOG_TEST_VENDOR = 2163;
const EVENT_CATALOG_TEST_KIT    = 900;

$GLOBALS['catalog_products'] = array();
$GLOBALS['catalog_stock']    = array();
$GLOBALS['catalog_env']      = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['catalog_env'][ $key ] ?? $default; }
function user_can( WP_User $user, string $cap ): bool { return ! empty( $user->allcaps[ $cap ] ); }
function wc_get_products( array $args ): array { return $GLOBALS['catalog_products']; }
function papelito_kit_is_product( int $product_id ): bool { return EVENT_CATALOG_TEST_KIT === $product_id; }
function papelito_get_vendor_stock( mixed $vendor_id, mixed $product_id ): int { return $GLOBALS['catalog_stock'][ (int) $product_id ] ?? 0; }
function papelito_product_get_category( mixed $product_id ): ?array {
	return 7 === (int) $product_id ? null : array( 'id' => 1, 'slug' => 'sedas', 'name' => 'Sedas' );
}
function papelito_categories_list( array $args = array() ): array {
	return array(
		array( 'id' => 2, 'slug' => 'piteiras', 'name' => 'Piteiras' ),
		array( 'id' => 1, 'slug' => 'sedas', 'name' => 'Sedas' ),
	);
}
function wp_get_attachment_image_url( int $id, string $size = 'thumbnail' ): string { return "https://cdn.test/{$id}.jpg"; }
function wp_strip_all_tags( string $text ): string { return trim( strip_tags( $text ) ); }
function wc_attribute_label( string $name ): string { return ucfirst( $name ); }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_config.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_pricing.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_catalog.php';

$failures = 0;

function event_catalog_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

function event_catalog_product( int $id, string $price, int $sales, string $created ): WC_Product {
	return new WC_Product(
		array(
			'id'         => $id,
			'name'       => "Produto {$id}",
			'price'      => $price,
			'sales'      => $sales,
			'created'    => $created,
			'attributes' => array( 'folhas' => '50' ),
		)
	);
}

function event_catalog_fixture(): void {
	$GLOBALS['catalog_products'] = array(
		event_catalog_product( 1, '180.20', 50, '2026-01-01' ),
		event_catalog_product( 2, '191.95', 40, '2026-02-01' ),
		event_catalog_product( 3, '191.95', 30, '2026-03-01' ),
		event_catalog_product( 4, '207.60', 20, '2026-04-01' ),
		event_catalog_product( 5, '99.90', 999, '2026-10-01' ),
		event_catalog_product( 6, '0', 10, '2026-05-01' ),
		event_catalog_product( 7, '10.00', 10, '2026-05-01' ),
		event_catalog_product( EVENT_CATALOG_TEST_KIT, '700.00', 500, '2026-06-01' ),
	);
	$GLOBALS['catalog_stock']    = array( 1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 0, 900 => 50 );
	$GLOBALS['catalog_env']      = array( 'PAPELITO_EVENT_SALES_KIT_PRODUCT_IDS' => '1,2,3,4' );
}

function test_kits_excluded_and_zero_stock_kept(): void {
	event_catalog_fixture();
	$products = papelito_event_sales_catalog_products( EVENT_CATALOG_TEST_VENDOR );
	$ids      = array_column( $products, 'id' );
	event_catalog_assert( 'kit cadastrado no WooCommerce fica fora', ! in_array( EVENT_CATALOG_TEST_KIT, $ids, true ) );
	event_catalog_assert( 'produto sem preço fica fora', ! in_array( 6, $ids, true ) );
	event_catalog_assert( 'produto sem categoria Papelito fica fora', ! in_array( 7, $ids, true ) );
	event_catalog_assert( 'estoque zero continua listado', in_array( 5, $ids, true ) );
	$first = $products[0];
	event_catalog_assert( 'preço em centavos', 18020 === $first['priceCents'] );
	event_catalog_assert( 'estoque é o do vendor', 10 === $first['stock'] );
	event_catalog_assert( 'categoria vem da taxonomia', 'sedas' === $first['category']['slug'] );
	event_catalog_assert( 'specs vêm dos atributos', array( array( 'label' => 'Folhas', 'value' => '50' ) ) === $first['specs'] );
	event_catalog_assert( 'descrição sem HTML', 'Descrição' === $first['description'] );
}

function test_shelves_rules(): void {
	event_catalog_fixture();
	$products = papelito_event_sales_catalog_products( EVENT_CATALOG_TEST_VENDOR );
	$shelves  = papelito_event_sales_shelves( $products, array( 1, 2, 3, 4 ) );
	event_catalog_assert( 'mais vendidos ignora estoque zero', array( 1, 2, 3, 4 ) === $shelves['mais_vendidos'] );
	event_catalog_assert( 'premium são os ids do kit', array( 1, 2, 3, 4 ) === $shelves['premium'] );
	event_catalog_assert( 'lançamentos são os mais recentes com estoque', array( 4, 3, 2, 1 ) === $shelves['lancamentos'] );
}

function test_context_shape_keys(): void {
	event_catalog_fixture();
	$context = papelito_event_sales_context( new WP_User( 7, 'Operadora', array( 'papelito_event_sell' => true ) ), EVENT_CATALOG_TEST_VENDOR );
	event_catalog_assert( 'contexto tem as chaves do contrato', array( 'event', 'operator', 'terminals', 'levels', 'kit', 'categories', 'products', 'shelves' ) === array_keys( $context ) );
	event_catalog_assert( 'evento tem id e nome', 'expocannabis-2026' === $context['event']['id'] && 'ExpoCannabis Brasil 2026' === $context['event']['name'] );
	event_catalog_assert( 'operador sem supervise', 'Operadora' === $context['operator']['name'] && false === $context['operator']['canSupervise'] );
	event_catalog_assert( 'kit calculado pelos preços atuais', 65595 === $context['kit']['unitCents'] && true === $context['kit']['available'] );
	event_catalog_assert( 'níveis em camelCase', 10 === $context['levels'][1]['minUnits'] && 500 === $context['levels'][1]['pctBp'] );
	event_catalog_assert( 'categorias só as que têm produto', array( 'sedas' ) === array_column( $context['categories'], 'slug' ) );

	$GLOBALS['catalog_env'] = array( 'PAPELITO_EVENT_SALES_KIT_PRODUCT_IDS' => '1,2' );
	$partial                = papelito_event_sales_context( new WP_User( 7, 'Operadora', array() ), EVENT_CATALOG_TEST_VENDOR );
	event_catalog_assert( 'kit incompleto fica indisponível', false === $partial['kit']['available'] && 0 === $partial['kit']['unitCents'] );
}

test_kits_excluded_and_zero_stock_kept();
test_shelves_rules();
test_context_shape_keys();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
