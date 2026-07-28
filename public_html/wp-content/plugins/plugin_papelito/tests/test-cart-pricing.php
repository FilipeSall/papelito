<?php
/**
 * Standalone regression test for authoritative cart pricing.
 *
 * Usage: php tests/test-cart-pricing.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}
function apply_filters( string $hook, mixed $value ) { return $value; }
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_key( mixed $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function wc_format_decimal( mixed $value ) { return (string) $value; }
function wp_unslash( mixed $value ) { return $value; }

$papelito_test_rate_limit_allowed = true;
function papelito_auth_rate_limit( string $bucket, int $max = 20, int $window = 60 ): bool {
	global $papelito_test_rate_limit_allowed;
	return $papelito_test_rate_limit_allowed;
}

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;
	public function __construct( string $code, string $message, mixed $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }

class WC_Product {
	public function get_id() { return 11776; }
	public function get_name() { return 'Seda Slim King Size'; }
	public function get_status() { return 'publish'; }
	public function get_price( $context = 'view' ) { return '99.90'; }
	public function get_regular_price( $context = 'view' ) { return '121.00'; }
}

function wc_get_product( mixed $product_id ) {
	return 11776 === (int) $product_id ? new WC_Product() : false;
}

function papelito_shipping_get_vendor( int $vendor_id ) {
	return 101 === (int) $vendor_id ? array( 'id' => 101 ) : new WP_Error( 'vendor_invalid', 'Vendor inválido.' );
}

function papelito_get_vendor_stock( $vendor_id, $product_id ) { return 20; }
function get_user_meta( $user_id, $key, $single = true ) { return 'Vendor Centro'; }
function get_userdata( $user_id ) { return null; }
function papelito_shipping_normalize_cep( mixed $value ) { return preg_replace( '/\D+/', '', (string) $value ); }
function papelito_order_routing_resolve_shipping( int $vendor_id, string $destination_cep, string $selected_code, array $lines ) {
	if ( 101 !== $vendor_id || '01310930' !== $destination_cep || '03298' !== $selected_code ) {
		return new WP_Error( 'papelito_checkout_invalid_shipping', 'Frete invalido.', array( 'status' => 422 ) );
	}
	return array( 'price' => 10.36 );
}

function papelito_flash_sale_resolve_promotion_context( string $context, int $product_id ) {
	if ( 'valid-promo' === $context && 11776 === $product_id ) {
		return array( 'discountPercent' => 99 );
	}
	return new WP_Error( 'papelito_promotion_context_invalid', 'Oferta invalida.', array( 'status' => 409 ) );
}

function papelito_coupon_apply_resolve( string $code, array $items, int $user_id ) {
	$subtotal = 0.0;
	foreach ( $items as $item ) {
		$subtotal += (float) $item['price'] * (int) $item['qty'];
	}
	if ( 'TEN' === $code ) $amount = 10;
	elseif ( 'NINETY9' === $code ) $amount = 99;
	elseif ( 'EXPIRED' === $code ) return new WP_Error( 'papelito_coupon_expired', 'Cupom expirado.', array( 'status' => 410 ) );
	else return new WP_Error( 'papelito_coupon_not_found', 'Cupom não encontrado.', array( 'status' => 404 ) );

	return array(
		'ok' => true,
		'code' => $code,
		'discount_type' => 'percent',
		'discount_value' => round( $subtotal * $amount / 100, 2 ),
		'applied_product_ids' => array( 11776 ),
	);
}

require __DIR__ . '/../includes/pricing.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

function papelito_test_item( ?string $context = null, int $qty = 1 ): array {
	return array(
		'product_id' => 11776,
		'qty' => $qty,
		'vendor_id' => 101,
		'vendor_name' => 'Vendor Centro',
		'promotion_context' => $context,
		'price' => 0.01,
	);
}

echo "Scenario 1: campaign context is authoritative and browser price is ignored\n";
$quote = papelito_pricing_quote( array( papelito_test_item( 'valid-promo' ) ), '', 10, 0 );
papelito_assert_same( 'regular campaign reference', 12100, $quote['totals']['subtotalCents'] ?? null );
papelito_assert_same( '99 percent discount', 11979, $quote['totals']['discountCents'] ?? null );
papelito_assert_same( 'campaign total R$ 1.21', 121, $quote['totals']['totalCents'] ?? null );
papelito_assert_same( 'campaign source', 'flash_sale', $quote['lines'][0]['discountSource'] ?? null );

echo "Scenario 2: catalog item has normal Woo price and invalid tokens are removed\n";
$catalog = papelito_pricing_quote( array( papelito_test_item() ), '', 10, 0 );
$invalid = papelito_pricing_quote( array( papelito_test_item( 'tampered' ) ), '', 10, 0 );
papelito_assert_same( 'catalog total', 9990, $catalog['totals']['totalCents'] ?? null );
papelito_assert_same( 'invalid promotion reverts to normal', 9990, $invalid['totals']['totalCents'] ?? null );
papelito_assert_same( 'invalid context adjustment', 'promotion_removed', $invalid['adjustments'][0]['type'] ?? null );

echo "Scenario 3: campaign and coupon choose the lower line total without stacking\n";
$ten = papelito_pricing_quote( array( papelito_test_item( 'valid-promo' ) ), 'TEN', 10, 0 );
$ninety_nine = papelito_pricing_quote( array( papelito_test_item( 'valid-promo' ) ), 'NINETY9', 10, 0 );
papelito_assert_same( '10 percent coupon does not stack', 121, $ten['totals']['totalCents'] ?? null );
papelito_assert_same( 'coupon reports no additional discount', false, $ten['coupon']['applied'] ?? null );
papelito_assert_same( '99 percent coupon wins with R$ 1.00', 100, $ninety_nine['totals']['totalCents'] ?? null );
papelito_assert_same( 'winning coupon source', 'coupon', $ninety_nine['lines'][0]['discountSource'] ?? null );
papelito_assert_same( 'effective coupon discount', 9890, $ninety_nine['coupon']['discountValueCents'] ?? null );

echo "Scenario 4: invalid coupons remain validation errors\n";
$expired = papelito_pricing_quote( array( papelito_test_item() ), 'EXPIRED', 10, 0 );
papelito_assert_same( 'expired coupon error', 'papelito_coupon_expired', $expired->get_error_code() );

echo "Scenario 5: payment minimums and installment floor are enforced\n";
$card_too_small = papelito_pricing_validate_payment_amount( 'credit_card', 99, 1 );
$pix_minimum = papelito_pricing_validate_payment_amount( 'pix', 1, 1 );
$installment_too_small = papelito_pricing_validate_payment_amount( 'credit_card', 121, 2 );
$installments_exceeded = papelito_pricing_validate_payment_amount( 'credit_card', 1000, 7 );
papelito_assert_same( 'card below R$1 rejected', 'papelito_checkout_amount_below_minimum', $card_too_small->get_error_code() );
papelito_assert_same( 'Pix R$0.01 accepted', true, $pix_minimum );
papelito_assert_same( 'installment below R$1 rejected', 'papelito_checkout_installment_below_minimum', $installment_too_small->get_error_code() );
papelito_assert_same( 'more than six installments rejected', 'papelito_checkout_installments_exceeded', $installments_exceeded->get_error_code() );

echo "Scenario 6: selected shipping is recalculated instead of accepting browser cents\n";
$with_shipping = papelito_pricing_quote(
	array( papelito_test_item( 'valid-promo' ) ),
	'',
	10,
	999999,
	array( 'destination_cep' => '01310-930', 'selected_code' => '03298' )
);
papelito_assert_same( 'authoritative shipping', 1036, $with_shipping['totals']['shippingCents'] ?? null );
papelito_assert_same( 'authoritative total with shipping', 1157, $with_shipping['totals']['totalCents'] ?? null );

echo "Scenario 7: abusive pricing payloads are rejected\n";
$too_many_items = papelito_pricing_normalize_items( array_fill( 0, 121, papelito_test_item() ) );
$duplicate_items = papelito_pricing_normalize_items( array( papelito_test_item(), papelito_test_item() ) );
papelito_assert_same( 'more than 120 items rejected', 'papelito_checkout_too_many_items', $too_many_items->get_error_code() );
papelito_assert_same( 'duplicate product rejected', 'papelito_checkout_duplicate_item', $duplicate_items->get_error_code() );

echo "Scenario 8: public pricing rate limit fails closed\n";
$papelito_test_rate_limit_allowed = false;
$rate_limited = papelito_pricing_check_rate_limit();
papelito_assert_same( 'rate limit error', 'papelito_rate_limited', $rate_limited->get_error_code() );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
