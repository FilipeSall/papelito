<?php
/**
 * Regressão da logística derivada de Kits.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );

class Papelito_Kit_Logistics_DB {
	public string $prefix = 'wp_';
	public array $kits = array();
	public array $items = array();
	public array $merchandise = array();

	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( array( '%d', '%s', '%f' ), '%s', $query ), $args );
	}

	public function get_row( string $query, $output = null ) {
		preg_match( '/product_id = (\d+)/', $query, $matches );
		return $this->kits[ (int) ( $matches[1] ?? 0 ) ] ?? null;
	}

	public function get_results( string $query, $output = null ) {
		preg_match( '/kit_id = (\d+)/', $query, $matches );
		$kit_id = (int) ( $matches[1] ?? 0 );
		if ( str_contains( $query, 'papelito_kit_items' ) ) {
			return $this->items[ $kit_id ] ?? array();
		}
		if ( str_contains( $query, 'papelito_kit_merchandise' ) ) {
			return $this->merchandise[ $kit_id ] ?? array();
		}
		return array();
	}
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

class WC_Product {
	public function __construct( private int $id, private string $name, private string $weight, private string $type = 'simple' ) {}
	public function get_id(): int { return $this->id; }
	public function get_name(): string { return $this->name; }
	public function get_weight( string $context = 'view' ): string { return $this->weight; }
	public function is_type( string $type ): bool { return $this->type === $type; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
function wc_get_weight( float $weight, string $unit ): float { return 'g' === $unit ? $weight * 1000 : $weight; }
function wc_format_decimal( string $value ): string { return $value; }
function wc_get_product( int $product_id ) { return $GLOBALS['papelito_kit_test_products'][ $product_id ] ?? null; }

$wpdb = new Papelito_Kit_Logistics_DB();
require __DIR__ . '/../includes/kits.php';

$failures = 0;
function papelito_kit_logistics_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

$wpdb->kits = array( 900 => array( 'id' => 7, 'product_id' => 900, 'package_length' => '30', 'package_width' => '20', 'package_height' => '10' ) );
$wpdb->items = array( 7 => array( array( 'product_id' => 101, 'quantity' => 2 ), array( 'product_id' => 102, 'quantity' => 1 ) ) );
$wpdb->merchandise = array( 7 => array() );
$GLOBALS['papelito_kit_test_products'] = array(
	101 => new WC_Product( 101, 'Produto A', '0.5' ),
	102 => new WC_Product( 102, 'Produto B', '0.3' ),
);

echo "Scenario 1: peso soma os componentes e suas quantidades\n";
$weight = papelito_kit_calculate_weight_grams( 7 );
papelito_kit_logistics_assert( '500g x 2 + 300g resulta em 1300g', 1300.0 === $weight );

echo "Scenario 2: brinde físico participa do peso\n";
$wpdb->merchandise[7] = array( array( 'id' => 55, 'quantity' => 2, 'weight' => '0.05' ) );
$weight = papelito_kit_calculate_weight_grams( 7 );
papelito_kit_logistics_assert( 'dois brindes de 50g resultam em 1400g', 1400.0 === $weight );

echo "Scenario 3: falta de peso impede derivação\n";
$GLOBALS['papelito_kit_test_products'][102] = new WC_Product( 102, 'Produto B', '' );
$weight = papelito_kit_calculate_weight_grams( 7 );
papelito_kit_logistics_assert( 'componente sem peso retorna erro', is_wp_error( $weight ) && 'papelito_kit_component_weight_missing' === $weight->code );

echo "Scenario 4: componente variável participa da logística\n";
$GLOBALS['papelito_kit_test_products'][102] = new WC_Product( 102, 'Produto B', '0.3', 'variable' );
$weight = papelito_kit_calculate_weight_grams( 7 );
papelito_kit_logistics_assert( 'produto variável com peso é aceito', 1400.0 === $weight );

echo "Scenario 5: dimensões são a embalagem final validada\n";
$GLOBALS['papelito_kit_test_products'][102] = new WC_Product( 102, 'Produto B', '0.3' );
$logistics = papelito_kit_logistics( 900 );
papelito_kit_logistics_assert( 'usa dimensões manuais sem somar produtos', is_array( $logistics ) && 30.0 === $logistics['length'] && 20.0 === $logistics['width'] && 10.0 === $logistics['height'] );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) failed\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
