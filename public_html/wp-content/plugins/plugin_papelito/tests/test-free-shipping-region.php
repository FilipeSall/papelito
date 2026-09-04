<?php
/**
 * Standalone regression test for the regional scope of automatic free shipping.
 *
 * Cobre o único ponto onde a elegibilidade por CEP entra no total autoritativo: o ramo automático
 * de `papelito_pricing_apply_discounts()`. O cupom com frete grátis é verificado aqui justamente
 * para provar que ele NÃO é restringido pela região.
 *
 * Usage: php tests/test-free-shipping-region.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

/** Stub vazio de propósito: o teste chama as funções do domínio direto, sem passar por hooks. */
function add_action( ...$args ): void {
	// Intencionalmente vazio.
}
/** Stub vazio de propósito: nenhum filtro é registrado neste recorte. */
function add_filter( ...$args ): void {
	// Intencionalmente vazio.
}
function apply_filters( string $hook, mixed $value ): mixed { return $value; }
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

function papelito_shipping_get_free_shipping_minimum_cents(): int { return 9900; }

const CEP_DENTRO_DA_FAIXA = '70123456';
const CEP_FORA_DAS_FAIXAS = '88000000';
const FAIXA_UM_INICIO     = '70000000';
const FAIXA_UM_FIM        = '70999999';
const FAIXA_DOIS_INICIO   = '01000000';
const FAIXA_DOIS_FIM      = '05999999';

/** Faixas controladas pelo teste, no formato já normalizado da option. */
$zip_ranges = array();

function papelito_shipping_normalize_cep( mixed $value ): string {
	$digits = preg_replace( '/\D+/', '', (string) $value );

	return is_string( $digits ) && 8 === strlen( $digits ) ? $digits : '';
}

function papelito_shipping_cep_allows_free_shipping( string $destination_cep ): bool {
	global $zip_ranges;

	if ( empty( $zip_ranges ) ) {
		return true;
	}

	$cep = papelito_shipping_normalize_cep( $destination_cep );

	if ( '' === $cep ) {
		return false;
	}

	foreach ( $zip_ranges as $range ) {
		if ( (int) $range['minCep'] <= (int) $cep && (int) $range['maxCep'] >= (int) $cep ) {
			return true;
		}
	}

	return false;
}

$resolved_coupon = null;
function papelito_coupon_apply_resolve( string $code, array $cart_items, int $user_id ) {
	global $resolved_coupon;

	return $resolved_coupon ?? new WP_Error( 'papelito_coupon_not_found', 'Cupom não encontrado.', array( 'status' => 404 ) );
}

function papelito_flash_sale_get_active_campaign_for_product( int $product_id ) { return null; }

require_once __DIR__ . '/../includes/pricing.php';

$failures = 0;
function assert_region( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";

		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

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

// Sem faixa cadastrada: comportamento anterior às regiões, para qualquer CEP.
$quote = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1627, CEP_FORA_DAS_FAIXAS );
assert_region( 'sem faixa, qualquer CEP tem frete gratis', 1627, $quote['totals']['shippingDiscountCents'] );
assert_region( 'sem faixa, nenhum ajuste de regiao', array(), $quote['adjustments'] );

// Sem faixa e sem CEP informado: o benefício continua valendo.
$quote_no_cep = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1627 );
assert_region( 'sem faixa e sem CEP, o beneficio vale', 1627, $quote_no_cep['totals']['shippingDiscountCents'] );

$zip_ranges = array(
	array( 'minCep' => FAIXA_UM_INICIO, 'maxCep' => FAIXA_UM_FIM ),
	array( 'minCep' => FAIXA_DOIS_INICIO, 'maxCep' => FAIXA_DOIS_FIM ),
);

// Dentro da faixa: abate normalmente.
$inside = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1627, CEP_DENTRO_DA_FAIXA );
assert_region( 'CEP dentro da faixa mantem o frete gratis', 1627, $inside['totals']['shippingDiscountCents'] );
assert_region( 'total sem o frete', 12100, $inside['totals']['totalCents'] );
assert_region( 'dentro da faixa nao gera ajuste', array(), $inside['adjustments'] );

// Fora da faixa: cobra o frete e explica por quê.
$outside = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1627, CEP_FORA_DAS_FAIXAS );
assert_region( 'CEP fora da faixa paga o frete', 0, $outside['totals']['shippingDiscountCents'] );
assert_region( 'total soma o frete cobrado', 13727, $outside['totals']['totalCents'] );
assert_region( 'ajuste nomeia a recusa regional', 'free_shipping_out_of_region', $outside['adjustments'][0]['type'] ?? '' );

// CEP ausente com faixa configurada: destino não verificável fica fora.
$unknown = papelito_pricing_apply_discounts( resolved_cart( 12100 ), '', 7, 1627 );
assert_region( 'CEP ausente com faixa nao recebe o beneficio', 0, $unknown['totals']['shippingDiscountCents'] );

// Subtotal abaixo do mínimo: a região não muda nada, e não há ajuste enganoso.
$below = papelito_pricing_apply_discounts( resolved_cart( 5000 ), '', 7, 1627, CEP_DENTRO_DA_FAIXA );
assert_region( 'abaixo do minimo nao abate', 0, $below['totals']['shippingDiscountCents'] );
assert_region( 'abaixo do minimo nao culpa a regiao', array(), $below['adjustments'] );

// Cupom de frete grátis: concedido caso a caso, continua valendo fora da faixa.
$resolved_coupon = array(
	'ok'                  => true,
	'code'                => 'FRETEGRATIS',
	'discount_type'       => 'fixed_cart',
	'free_shipping'       => true,
	'discount_value'      => 0.0,
	'applied_product_ids' => array( 501 ),
);
$coupon_outside = papelito_pricing_apply_discounts( resolved_cart( 12100 ), 'FRETEGRATIS', 7, 1627, CEP_FORA_DAS_FAIXAS );
assert_region( 'cupom de frete gratis ignora a restricao regional', 1627, $coupon_outside['totals']['shippingDiscountCents'] );
assert_region( 'cupom fora da faixa conta como aplicado', true, $coupon_outside['coupon']['applied'] );

if ( $failures > 0 ) {
	echo "\n{$failures} assertion(s) failed\n";
	exit( 1 );
}

echo "\nFree shipping region: ok\n";
