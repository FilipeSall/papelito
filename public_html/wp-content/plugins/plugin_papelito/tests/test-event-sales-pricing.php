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

	public function get_error_data(): array {
		return $this->data;
	}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require_once dirname( __DIR__ ) . '/includes/event_sales_pricing.php';

const EVENT_SALES_TEST_INSANE       = 101;
const EVENT_SALES_TEST_PINK         = 102;
const EVENT_SALES_TEST_BROWN        = 103;
const EVENT_SALES_TEST_ALFAFA       = 104;
const EVENT_SALES_TEST_LOOSE        = 200;
const EVENT_SALES_TEST_KIT_UNIT     = 65595;
const EVENT_SALES_TEST_INVALID_QTY  = 'papelito_event_invalid_quantity';

$failures = 0;

function event_pricing_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

function event_pricing_products(): array {
	return array(
		EVENT_SALES_TEST_INSANE => array( 'price_cents' => 18020, 'name' => 'Insane' ),
		EVENT_SALES_TEST_PINK   => array( 'price_cents' => 19195, 'name' => 'Pink' ),
		EVENT_SALES_TEST_BROWN  => array( 'price_cents' => 19195, 'name' => 'Insane Brown' ),
		EVENT_SALES_TEST_ALFAFA => array( 'price_cents' => 20760, 'name' => 'Alfafa' ),
		EVENT_SALES_TEST_LOOSE  => array( 'price_cents' => 11162, 'name' => 'Avulso' ),
	);
}

function event_pricing_kit_ids(): array {
	return array( EVENT_SALES_TEST_INSANE, EVENT_SALES_TEST_PINK, EVENT_SALES_TEST_BROWN, EVENT_SALES_TEST_ALFAFA );
}

function event_pricing_error( mixed $result, string $code, int $status ): bool {
	return $result instanceof WP_Error && $code === $result->get_error_code() && $status === ( $result->get_error_data()['status'] ?? 0 );
}

function test_kit_unit_is_65595(): void {
	event_pricing_assert( 'kit dos preços de 29/10 custa 655,95', EVENT_SALES_TEST_KIT_UNIT === papelito_event_sales_kit_unit_cents( array( 18020, 19195, 19195, 20760 ) ) );
}

function test_units_count_kit_as_four(): void {
	event_pricing_assert( '6 displays e 1 kit são 10 unidades', 10 === papelito_event_sales_units( array( 'a' => 6 ), 1 ) );
}

function test_levels_boundaries(): void {
	$cases = array( 0 => 'lojista', 9 => 'lojista', 10 => 'parceiro', 19 => 'parceiro', 20 => 'pdv_perfeito', 39 => 'pdv_perfeito', 40 => 'lenda', 400 => 'lenda' );
	foreach ( $cases as $units => $key ) {
		event_pricing_assert( "{$units} unidades é {$key}", $key === papelito_event_sales_level_for( $units )['key'] );
	}
	event_pricing_assert( 'Lenda tem 12%', 1200 === papelito_event_sales_level_for( 40 )['pct_bp'] );
}

function test_line_discount_rounds_per_line(): void {
	$priced = papelito_event_sales_price( event_pricing_products(), array( EVENT_SALES_TEST_LOOSE => 3, EVENT_SALES_TEST_INSANE => 17 ), 0, event_pricing_kit_ids() );
	$line   = $priced['lines'][0];
	event_pricing_assert( '20 unidades é PDV Perfeito', 'pdv_perfeito' === $priced['level']['key'] );
	event_pricing_assert( 'linha guarda o subtotal cheio', 33486 === $line['subtotal_cents'] );
	event_pricing_assert( 'desconto da linha arredonda no centavo', 2679 === $line['discount_cents'] );
	event_pricing_assert( 'total da linha é subtotal menos desconto', 30807 === $line['total_cents'] );
	event_pricing_assert( 'origem do desconto é o nível do evento', 'event_level' === $line['source'] );
	event_pricing_assert( 'desconto do pedido soma as linhas', 2679 + 24507 === $priced['discount_cents'] );
}

function test_kit_without_level_is_rejected(): void {
	$result = papelito_event_sales_price( event_pricing_products(), array( EVENT_SALES_TEST_LOOSE => 5 ), 1, event_pricing_kit_ids() );
	event_pricing_assert( 'kit com 9 unidades é recusado', event_pricing_error( $result, 'papelito_event_kit_requires_level', 409 ) );

	$allowed = papelito_event_sales_price( event_pricing_products(), array( EVENT_SALES_TEST_LOOSE => 6 ), 1, event_pricing_kit_ids() );
	event_pricing_assert( 'kit conta para liberar o kit', ! is_wp_error( $allowed ) && 10 === $allowed['units'] );
}

function test_kit_not_discounted_by_level(): void {
	$priced = papelito_event_sales_price( event_pricing_products(), array( EVENT_SALES_TEST_LOOSE => 14 ), 2, event_pricing_kit_ids() );
	event_pricing_assert( '14 avulsos e 2 kits são 22 unidades', 22 === $priced['units'] );
	event_pricing_assert( 'kit mantém o preço próprio', 2 * EVENT_SALES_TEST_KIT_UNIT === $priced['kit']['total_cents'] );
	event_pricing_assert( 'kit informa o preço cheio dos 4', 77170 === $priced['kit']['full_unit_cents'] );
	event_pricing_assert( 'economia do kit é a diferença', 2 * ( 77170 - EVENT_SALES_TEST_KIT_UNIT ) === $priced['kit_savings_cents'] );
	event_pricing_assert( 'itens somam avulsos com desconto e kits', 156268 - 12501 + 2 * EVENT_SALES_TEST_KIT_UNIT === $priced['items_cents'] );
}

function test_quantity_limits(): void {
	$products = event_pricing_products();
	$kit_ids  = event_pricing_kit_ids();
	event_pricing_assert( '1000 unidades é recusado', event_pricing_error( papelito_event_sales_price( $products, array( EVENT_SALES_TEST_LOOSE => 1000 ), 0, $kit_ids ), EVENT_SALES_TEST_INVALID_QTY, 400 ) );
	event_pricing_assert( 'quantidade negativa é recusada', event_pricing_error( papelito_event_sales_price( $products, array( EVENT_SALES_TEST_LOOSE => -1 ), 0, $kit_ids ), EVENT_SALES_TEST_INVALID_QTY, 400 ) );
	event_pricing_assert( '4 kits é recusado', event_pricing_error( papelito_event_sales_price( $products, array( EVENT_SALES_TEST_LOOSE => 30 ), 4, $kit_ids ), EVENT_SALES_TEST_INVALID_QTY, 400 ) );
	event_pricing_assert( 'produto fora do catálogo é recusado', event_pricing_error( papelito_event_sales_price( $products, array( 999 => 1 ), 0, $kit_ids ), 'papelito_event_unknown_product', 400 ) );
	event_pricing_assert( 'kit sem os 4 ids é indisponível', event_pricing_error( papelito_event_sales_price( $products, array( EVENT_SALES_TEST_LOOSE => 10 ), 1, array( EVENT_SALES_TEST_INSANE ) ), 'papelito_event_kit_unavailable', 409 ) );
	event_pricing_assert( 'quantidade zero some das linhas', array() === papelito_event_sales_price( $products, array( EVENT_SALES_TEST_LOOSE => 0 ), 0, $kit_ids )['lines'] );
}

test_kit_unit_is_65595();
test_units_count_kit_as_four();
test_levels_boundaries();
test_line_discount_rounds_per_line();
test_kit_without_level_is_rejected();
test_kit_not_discounted_by_level();
test_quantity_limits();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
