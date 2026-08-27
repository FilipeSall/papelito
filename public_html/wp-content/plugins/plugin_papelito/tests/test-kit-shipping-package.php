<?php
/**
 * Regressão da fronteira entre Kit e cotação de frete.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}
class WC_Product {
	public function __construct( private float $price ) {}
	public function get_price(): float { return $this->price; }
}

function add_action( ...$args ): void {}
function add_filter( ...$args ): void {}
function register_rest_route( ...$args ): void {}
function apply_filters( $hook, $value ) { return $value; }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function remove_accents( $value ): string { return (string) $value; }
function absint( $value ): int { return abs( (int) $value ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function papelito_kit_is_product( int $product_id ): bool { return 900 === $product_id; }
function papelito_kit_logistics( int $product_id, int $quantity ) {
	return array( 'weight' => 1300.0, 'length' => 30.0, 'width' => 20.0, 'height' => 10.0 );
}
function wc_get_product( int $product_id ) { return 900 === $product_id ? new WC_Product( 59.9 ) : null; }

require __DIR__ . '/../includes/shipping.php';

$failures = 0;
function papelito_kit_shipping_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

echo "Scenario 1: Kit isolado usa peso derivado e embalagem manual\n";
$package = papelito_shipping_build_package( array( array( 'product_id' => 900, 'qty' => 1 ) ) );
papelito_kit_shipping_assert( 'usa o peso derivado', is_array( $package ) && 1300.0 === $package['weight'] );
papelito_kit_shipping_assert( 'usa dimensões da embalagem', is_array( $package ) && 30.0 === $package['length'] && 20.0 === $package['width'] && 10.0 === $package['height'] );

echo "Scenario 2: carrinho misto falha sem estimar embalagem\n";
$mixed = papelito_shipping_build_package( array( array( 'product_id' => 900, 'qty' => 1 ), array( 'product_id' => 101, 'qty' => 1 ) ) );
papelito_kit_shipping_assert( 'mistura retorna erro controlado', is_wp_error( $mixed ) && 'papelito_shipping_kit_package_not_supported' === $mixed->code );

echo "Scenario 3: duas unidades falham sem inventar empacotamento\n";
$multiple = papelito_shipping_build_package( array( array( 'product_id' => 900, 'qty' => 2 ) ) );
papelito_kit_shipping_assert( 'quantidade maior que um retorna erro controlado', is_wp_error( $multiple ) && 'papelito_shipping_kit_package_not_supported' === $multiple->code );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
