<?php
/**
 * Standalone regression test for the free-shipping coupon.
 *
 * Cobre o abatimento do frete escolhido no total autoritativo: elegibilidade por
 * subtotal, exigência de modalidade escolhida e invariante do total.
 *
 * Usage: php tests/test-free-shipping-coupon.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function apply_filters( string $hook, mixed $value ) { return $value; }
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_key( mixed $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function wp_json_encode( mixed $value ) { return json_encode( $value ); }
function get_option( mixed $key, mixed $default = false ) { return $default; }

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }

$free_shipping_minimum_cents = 9900;
function papelito_shipping_get_free_shipping_minimum_cents(): int {
	global $free_shipping_minimum_cents;
	return $free_shipping_minimum_cents;
}

/** Cupom já resolvido, como papelito_coupon_apply_resolve devolveria. */
$resolved_coupon = null;
function papelito_coupon_apply_resolve( string $code, array $cart_items, int $user_id ) {
	global $resolved_coupon;
	return $resolved_coupon ?? new WP_Error( 'papelito_coupon_not_found', 'Cupom não encontrado.', array( 'status' => 404 ) );
}

function papelito_flash_sale_get_active_campaign_for_product( int $product_id ) { return null; }

require __DIR__ . '/../includes/pricing.php';

$failures = 0;
function assert_free_shipping( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

/**
 * Linhas resolvidas de um carrinho de um item, no formato que o pricing espera.
 *
 * @return array<string,mixed>
 */
function resolved_cart( int $unit_cents, int $qty = 1 ): array {
	return array(
		'vendor_id'   => 10,
		'vendor_name' => 'Papeloto',
		'adjustments' => array(),
		'lines'       => array(
			array(
				'product_id'            => 501,
				'vendor_id'             => 10,
				'qty'                   => $qty,
				'normal_unit_cents'     => $unit_cents,
				'normal_subtotal_cents' => $unit_cents * $qty,
				'promotion_context'     => '',
				'promotion'             => null,
			),
		),
	);
}

function free_shipping_coupon(): array {
	return array(
		'ok'                  => true,
		'code'                => 'FRETEGRATIS',
		'discount_type'       => 'fixed_cart',
		'free_shipping'       => true,
		'discount_value'      => 0.0,
		'applied_product_ids' => array( 501 ),
	);
}

// Subtotal acima do mínimo e frete escolhido: o frete sai do total.
$resolved_coupon = free_shipping_coupon();
$quote           = papelito_pricing_apply_discounts( resolved_cart( 12100 ), 'FRETEGRATIS', 7, 1627 );

assert_free_shipping( 'subtotal preservado', 12100, $quote['totals']['subtotalCents'] );
assert_free_shipping( 'frete cotado preservado', 1627, $quote['totals']['shippingCents'] );
assert_free_shipping( 'frete integralmente abatido', 1627, $quote['totals']['shippingDiscountCents'] );
assert_free_shipping( 'total sem o frete', 12100, $quote['totals']['totalCents'] );
assert_free_shipping( 'cupom marcado como aplicado', true, $quote['coupon']['applied'] );
assert_free_shipping( 'desconto de itens intocado', 0, $quote['totals']['discountCents'] );

// Modalidade mais cara: o abatimento acompanha a escolha, sem valor fixo.
$quote_sedex = papelito_pricing_apply_discounts( resolved_cart( 12100 ), 'FRETEGRATIS', 7, 1036 );
assert_free_shipping( 'abate exatamente a modalidade escolhida', 1036, $quote_sedex['totals']['shippingDiscountCents'] );
assert_free_shipping( 'total do sedex sem frete', 12100, $quote_sedex['totals']['totalCents'] );

// Sem frete escolhido ainda: nada a abater, e o cupom não conta como aplicado.
$quote_no_shipping = papelito_pricing_apply_discounts( resolved_cart( 12100 ), 'FRETEGRATIS', 7, 0 );
assert_free_shipping( 'sem modalidade nao ha abatimento', 0, $quote_no_shipping['totals']['shippingDiscountCents'] );
assert_free_shipping( 'sem modalidade o cupom nao esta aplicado', false, $quote_no_shipping['coupon']['applied'] );
assert_free_shipping( 'total sem modalidade e so o dos itens', 12100, $quote_no_shipping['totals']['totalCents'] );
assert_free_shipping(
	'ajuste explica por que nao aplicou',
	'free_shipping_not_applied',
	$quote_no_shipping['adjustments'][0]['type'] ?? ''
);

// Subtotal abaixo do minimo: beneficio nao vale e o frete continua no total.
$quote_below = papelito_pricing_apply_discounts( resolved_cart( 9899 ), 'FRETEGRATIS', 7, 1627 );
assert_free_shipping( 'abaixo do minimo nao abate', 0, $quote_below['totals']['shippingDiscountCents'] );
assert_free_shipping( 'abaixo do minimo cobra o frete', 9899 + 1627, $quote_below['totals']['totalCents'] );
assert_free_shipping( 'abaixo do minimo o cupom nao esta aplicado', false, $quote_below['coupon']['applied'] );

// Exatamente no minimo: vale.
$quote_at = papelito_pricing_apply_discounts( resolved_cart( 9900 ), 'FRETEGRATIS', 7, 1627 );
assert_free_shipping( 'no limite exato o beneficio vale', 1627, $quote_at['totals']['shippingDiscountCents'] );
assert_free_shipping( 'no limite exato o total e o subtotal', 9900, $quote_at['totals']['totalCents'] );

// Sem cupom nenhum: comportamento antigo intacto.
$resolved_coupon = null;
$quote_plain = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1627 );
assert_free_shipping( 'beneficio automatico nao exige codigo de cupom', 1627, $quote_plain['totals']['shippingDiscountCents'] );
assert_free_shipping( 'beneficio automatico remove o frete do total', 12100, $quote_plain['totals']['totalCents'] );

$quote_plain_repeated = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1627 );
assert_free_shipping( 'recalculo automatico nao acumula desconto', 12100, $quote_plain_repeated['totals']['totalCents'] );

$quote_plain_pac = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1937 );
assert_free_shipping( 'PAC automatico abate o preco atual', 1937, $quote_plain_pac['totals']['shippingDiscountCents'] );
assert_free_shipping( 'PAC automatico mantem o total', 12100, $quote_plain_pac['totals']['totalCents'] );

$quote_plain_sedex = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1245 );
assert_free_shipping( 'troca para SEDEX substitui o abatimento anterior', 1245, $quote_plain_sedex['totals']['shippingDiscountCents'] );
assert_free_shipping( 'SEDEX automatico mantem o total', 12100, $quote_plain_sedex['totals']['totalCents'] );

$quote_plain_pac_again = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1937 );
assert_free_shipping( 'volta ao PAC recalcula sem estado antigo', 1937, $quote_plain_pac_again['totals']['shippingDiscountCents'] );

$quote_plain_below = papelito_pricing_apply_discounts( resolved_cart( 9899 ), '', 7, 1627 );
assert_free_shipping( 'sem atingir o minimo o frete continua cobrado', 9899 + 1627, $quote_plain_below['totals']['totalCents'] );

// Cupom normal de itens continua descontando item, nunca frete.
$resolved_coupon = array(
	'ok'                  => true,
	'code'                => 'DEZOFF',
	'discount_type'       => 'fixed_cart',
	'discount_value'      => 10.0,
	'applied_product_ids' => array( 501 ),
);
$quote_fixed = papelito_pricing_apply_discounts( resolved_cart( 12100 ), 'DEZOFF', 7, 1627 );
assert_free_shipping( 'beneficio automatico tambem vale com cupom de item', 1627, $quote_fixed['totals']['shippingDiscountCents'] );
assert_free_shipping( 'cupom de item desconta item', 1000, $quote_fixed['totals']['discountCents'] );
assert_free_shipping( 'frete gratis automatico combina com cupom de item', 12100 - 1000, $quote_fixed['totals']['totalCents'] );

// O frete grátis automático não pode fazer um cupom de item ineficaz parecer aplicado.
$shadowed_cart = resolved_cart( 12100 );
$shadowed_cart['lines'][0]['promotion'] = array(
	'reference_unit_cents' => 12100,
	'total_cents'          => 9000,
);
$quote_coupon_shadowed = papelito_pricing_apply_discounts( $shadowed_cart, 'DEZOFF', 7, 1937 );
assert_free_shipping( 'cupom sem desconto efetivo continua nao aplicado', false, $quote_coupon_shadowed['coupon']['applied'] );
assert_free_shipping( 'cupom ineficaz nao vira item do pedido por causa do frete automatico', null, $quote_coupon_shadowed['coupon_data'] );

// Invariante do contrato consumido pelo frontend.
foreach ( array( $quote, $quote_sedex, $quote_below, $quote_at, $quote_plain, $quote_plain_pac, $quote_plain_sedex, $quote_plain_pac_again, $quote_fixed, $quote_coupon_shadowed ) as $index => $checked ) {
	$totals = $checked['totals'];
	assert_free_shipping(
		"invariante itens + frete - abatimento = total ({$index})",
		$totals['totalCents'],
		$totals['itemsCents'] + $totals['shippingCents'] - $totals['shippingDiscountCents']
	);
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: free shipping coupon rules hold\n";
