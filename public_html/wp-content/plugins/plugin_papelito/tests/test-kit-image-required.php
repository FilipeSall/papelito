<?php
/**
 * Regressão da imagem obrigatória no create de Kits.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

class Kit_Image_Test_DB {
	public string $prefix = 'wp_';

	public function prepare( string $query, ...$args ): string {
		return $query;
	}

	public function get_row( string $query, $output = null ) {
		return null;
	}
}

class WC_Product {
	public static int $saves = 0;

	public function get_id(): int { return 123; }
	public function set_name( string $value ): void {}
	public function set_slug( string $value ): void {}
	public function set_status( string $value ): void {}
	public function set_catalog_visibility( string $value ): void {}
	public function set_regular_price( string $value ): void {}
	public function set_sale_price( string $value ): void {}
	public function set_short_description( string $value ): void {}
	public function set_description( string $value ): void {}
	public function set_image_id( int $value ): void {}
	public function save(): int { ++self::$saves; return 123; }
}

class WC_Product_Simple extends WC_Product {}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_title( string $value ): string { return $value; }
function sanitize_key( string $value ): string { return $value; }
function wp_kses_post( string $value ): string { return $value; }
function wc_format_decimal( string $value ): string { return $value; }
function wp_attachment_is_image( int $attachment_id ): bool { return 999 === $attachment_id; }
function wc_get_product( int $product_id ) { return 10 === $product_id ? new WC_Product() : null; }

$wpdb = new Kit_Image_Test_DB();
require __DIR__ . '/../includes/kits.php';

$failures = 0;
function kit_image_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

$payload = array(
	'name'              => 'Kit sem imagem',
	'price'             => '10.00',
	'items'             => array( array( 'productId' => 10, 'quantity' => 1 ) ),
	'packageDimensions' => array(),
	'imageSource'       => 'custom',
);

echo "Scenario 1: criação sem imagem não persiste produto\n";
$result = papelito_kit_write( $payload );
kit_image_assert( 'retorna o erro de imagem obrigatória', is_wp_error( $result ) && 'papelito_kit_image_required' === $result->code );
kit_image_assert( 'não chama WC_Product::save', 0 === WC_Product::$saves );

echo "Scenario 2: Kit legado mantém preset ao editar\n";
$legacy_image = papelito_kit_validate_write_image( array( 'imageSource' => 'premium' ), 123 );
kit_image_assert( 'preset legado continua aceito em update', ! is_wp_error( $legacy_image ) && 'premium' === $legacy_image['source'] );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) failed\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
