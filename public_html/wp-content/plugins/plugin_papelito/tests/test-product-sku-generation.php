<?php
/**
 * Standalone regression test for automatic product SKU generation.
 *
 * Usage: php tests/test-product-sku-generation.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );

function add_action( mixed ...$args ): void {
	// O teste standalone não inicializa o registro real de hooks do WordPress.
	unset( $args );
}

function register_rest_route( mixed ...$args ): void {
	// O teste standalone não inicializa o registro real de rotas do WordPress.
	unset( $args );
}
function absint( mixed $value ): int { return abs( (int) $value ); }

final class Papelito_Test_Product {
	private array $state = array( 'save_count' => 0 );

	public function __construct(
		private int $id,
		private string $name,
		private string $sku = ''
	) {}

	public function get_id(): int { return $this->id; }
	public function get_name(): string { return $this->name; }
	public function get_sku(): string { return $this->sku; }
	public function set_sku( string $sku ): void { $this->sku = $sku; }
	public function save(): void {
		++$this->state['save_count'];
		$GLOBALS['pap_product_meta'][ $this->id ] = $this->sku;
	}
	public function get_save_count(): int { return $this->state['save_count']; }
}

$GLOBALS['pap_products']     = array();
$GLOBALS['pap_product_meta'] = array();

function get_posts( array $args ): array {
	return array_keys( $GLOBALS['pap_products'] );
}
function get_post_meta( int $product_id, string $key, bool $single = false ): string {
	return '_sku' === $key ? (string) ( $GLOBALS['pap_product_meta'][ $product_id ] ?? '' ) : '';
}
function wc_get_product( int $product_id ): ?Papelito_Test_Product {
	return $GLOBALS['pap_products'][ $product_id ] ?? null;
}
function wc_get_product_id_by_sku( string $sku ): int {
	foreach ( $GLOBALS['pap_products'] as $product ) {
		if ( $sku === $product->get_sku() ) {
			return $product->get_id();
		}
	}

	return 0;
}

require_once __DIR__ . '/../includes/product_sku.php';

$failures = 0;

function papelito_sku_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label} - expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

papelito_sku_assert_same( 'SKU keeps the Papelito prefix and six-digit product id', 'PPL-011836', papelito_product_sku_for_id( 11836 ) );
papelito_sku_assert_same( 'SKU does not truncate ids above six digits', 'PPL-1234567', papelito_product_sku_for_id( 1234567 ) );

$legacy_product = new Papelito_Test_Product( 10, 'Legacy', 'PP01010001' );
$GLOBALS['pap_products'][10]     = $legacy_product;
$GLOBALS['pap_product_meta'][10] = 'PP01010001';
papelito_product_sku_assign_on_create( 10, $legacy_product );
papelito_sku_assert_same( 'existing SKU is preserved on creation hook', 'PP01010001', $legacy_product->get_sku() );
papelito_sku_assert_same( 'existing SKU does not trigger a second save', 0, $legacy_product->get_save_count() );

$new_product = new Papelito_Test_Product( 11836, 'Bandeja M' );
$GLOBALS['pap_products'][11836]     = $new_product;
$GLOBALS['pap_product_meta'][11836] = '';
papelito_product_sku_assign_on_create( 11836, $new_product );
papelito_sku_assert_same( 'missing SKU is generated after creation', 'PPL-011836', $new_product->get_sku() );
papelito_sku_assert_same( 'generated SKU is persisted', 1, $new_product->get_save_count() );

$legacy_product->set_sku( 'ALTERED' );
papelito_product_sku_guard_save( $legacy_product );
papelito_sku_assert_same( 'existing SKU cannot be changed', 'PP01010001', $legacy_product->get_sku() );

$missing_product = new Papelito_Test_Product( 11837, 'Bandeja P' );
$GLOBALS['pap_products'][11837]     = $missing_product;
$GLOBALS['pap_product_meta'][11837] = '';
$preview = papelito_product_sku_backfill( array( 'dry_run' => true, 'batch' => 100 ) );
papelito_sku_assert_same( 'dry-run finds only missing SKUs', 1, $preview['missing'] );
papelito_sku_assert_same( 'dry-run does not write', '', $missing_product->get_sku() );

$applied = papelito_product_sku_backfill( array( 'dry_run' => false, 'batch' => 100 ) );
papelito_sku_assert_same( 'backfill generates one missing SKU', 1, $applied['generated'] );
papelito_sku_assert_same( 'backfill uses the product id', 'PPL-011837', $missing_product->get_sku() );

$second_run = papelito_product_sku_backfill( array( 'dry_run' => false, 'batch' => 100 ) );
papelito_sku_assert_same( 'second backfill is idempotent', 0, $second_run['generated'] );
papelito_sku_assert_same( 'second backfill skips populated products', 3, $second_run['skipped'] );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All product SKU generation tests passed.\n";
