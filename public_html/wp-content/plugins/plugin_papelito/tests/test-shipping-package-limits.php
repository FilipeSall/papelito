<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Limites físicos do pacote sintético dos Correios.
 *
 * O builder legado só aplicava mínimos, então um carrinho grande gerava um
 * objeto impossível que era cotado em silêncio e reaparecia como cobrança
 * complementar na fatura. Este teste fixa os máximos e a origem da medida.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

const LIMITS_TEST_SMALL_PRODUCT  = 201;
const LIMITS_TEST_WIDE_PRODUCT   = 202;
const LIMITS_TEST_HEAVY_PRODUCT  = 203;
const LIMITS_TEST_TINY_PRODUCT   = 204;
const LIMITS_TEST_EXCEEDS_CODE   = 'papelito_shipping_package_exceeds_limits';

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

class WC_Product {
	public function __construct(
		private float $weight,
		private float $length,
		private float $width,
		private float $height
	) {}
	public function get_weight(): float { return $this->weight; }
	public function get_length(): float { return $this->length; }
	public function get_width(): float { return $this->width; }
	public function get_height(): float { return $this->height; }
	public function get_name(): string { return 'Produto de teste'; }
	public function get_price(): float { return 10.0; }
}

function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function register_rest_route( mixed ...$args ): bool { return true; }
function apply_filters( mixed $hook, mixed $value ): mixed { return $value; }
function do_action( mixed ...$args ): bool { return true; }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function remove_accents( mixed $value ): string { return (string) $value; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wc_get_weight( mixed $value, mixed $unit ): float { return (float) $value; }
function wc_get_dimension( mixed $value, mixed $unit ): float { return (float) $value; }
function papelito_kit_is_product( int $product_id ): bool { return false; }
function papelito_kit_shipping_items( array $items ): array { return $items; }

function wc_get_product( int $product_id ): ?WC_Product {
	return match ( $product_id ) {
		LIMITS_TEST_SMALL_PRODUCT => new WC_Product( 100.0, 20.0, 15.0, 5.0 ),
		LIMITS_TEST_WIDE_PRODUCT  => new WC_Product( 100.0, 90.0, 80.0, 35.0 ),
		LIMITS_TEST_HEAVY_PRODUCT => new WC_Product( 31000.0, 20.0, 15.0, 5.0 ),
		LIMITS_TEST_TINY_PRODUCT  => new WC_Product( 0.5, 2.0, 2.0, 0.5 ),
		default                   => null,
	};
}

require_once __DIR__ . '/../includes/shipping.php';

$failures = 0;
function papelito_limits_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

function papelito_limits_package( int $product_id, int $qty ) {
	return papelito_shipping_build_package( array( array( 'product_id' => $product_id, 'qty' => $qty ) ) );
}

echo "Scenario 1: pacote dentro dos limites continua cotando\n";
$ok = papelito_limits_package( LIMITS_TEST_SMALL_PRODUCT, 1 );
papelito_limits_assert( 'devolve pacote', is_array( $ok ) );
papelito_limits_assert( 'peso somado', is_array( $ok ) && 100.0 === $ok['weight'] );
papelito_limits_assert( 'marca a origem da medida', is_array( $ok ) && 'legacy_synthetic' === ( $ok['measurement_source'] ?? null ) );

echo "Scenario 2: lado acima de 100 cm é recusado\n";
$tall = papelito_limits_package( LIMITS_TEST_SMALL_PRODUCT, 21 );
papelito_limits_assert( 'recusa lado fora do limite', is_wp_error( $tall ) && LIMITS_TEST_EXCEEDS_CODE === $tall->code );
papelito_limits_assert( 'aponta qual limite estourou', is_wp_error( $tall ) && 'dimension' === ( $tall->data['limit'] ?? null ) );
papelito_limits_assert( 'usa 422 de cadastro/carrinho', is_wp_error( $tall ) && 422 === ( $tall->data['status'] ?? null ) );

echo "Scenario 3: soma acima de 200 cm é recusada mesmo sem lado estourado\n";
$wide = papelito_limits_package( LIMITS_TEST_WIDE_PRODUCT, 1 );
papelito_limits_assert( 'recusa soma fora do limite', is_wp_error( $wide ) && LIMITS_TEST_EXCEEDS_CODE === $wide->code );
papelito_limits_assert( 'aponta a soma', is_wp_error( $wide ) && 'dimension_sum' === ( $wide->data['limit'] ?? null ) );

echo "Scenario 4: peso acima de 30 kg é recusado\n";
$heavy = papelito_limits_package( LIMITS_TEST_HEAVY_PRODUCT, 1 );
papelito_limits_assert( 'recusa peso fora do limite', is_wp_error( $heavy ) && LIMITS_TEST_EXCEEDS_CODE === $heavy->code );
papelito_limits_assert( 'aponta o peso', is_wp_error( $heavy ) && 'weight' === ( $heavy->data['limit'] ?? null ) );

echo "Scenario 5: mínimos dos Correios continuam aplicados\n";
$tiny = papelito_limits_package( LIMITS_TEST_TINY_PRODUCT, 1 );
papelito_limits_assert( 'peso mínimo 1 g', is_array( $tiny ) && 1.0 === $tiny['weight'] );
papelito_limits_assert( 'comprimento mínimo 16 cm', is_array( $tiny ) && 16.0 === $tiny['length'] );
papelito_limits_assert( 'largura mínima 11 cm', is_array( $tiny ) && 11.0 === $tiny['width'] );
papelito_limits_assert( 'altura mínima 2 cm', is_array( $tiny ) && 2.0 === $tiny['height'] );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
