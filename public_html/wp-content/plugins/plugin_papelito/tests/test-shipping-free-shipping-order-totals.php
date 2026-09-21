<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress and WooCommerce classes by design.
/**
 * Frete grátis no pedido: custo integral e abatimento continuam separados.
 *
 * O item de frete guarda o que foi **cobrado**, e os metas guardam o preço
 * cheio e o desconto. Colapsar os dois num valor só destruiria a auditoria — o
 * recibo deixaria de conseguir mostrar de quanto foi o benefício — e faria a
 * resposta ao comprador contar o frete duas vezes ou perdê-lo. A regra vale
 * igual para os dois providers, porque quem separa custo de desconto é o
 * caminho comum do pedido, não o adapter.
 *
 * Usage: php tests/test-shipping-free-shipping-order-totals.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_PAGARME_ORDER_ID_META', '_papelito_pagarme_order_id' );
define( 'PAPELITO_PAGARME_PAYMENT_METHOD_META', '_papelito_pagarme_payment_method' );
define( 'PAPELITO_PAGARME_PAYMENT_STATE_META', '_papelito_pagarme_payment_state' );

const FREE_SHIPPING_TEST_PRICE_CENTS = 2490;
const FREE_SHIPPING_TEST_ITEMS_CENTS = 10000;
const FREE_SHIPPING_TEST_CORREIOS    = 'correios';
const FREE_SHIPPING_TEST_BRASPRESS   = 'braspress';

/** Erro do core reduzido ao que o módulo lê. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): array { return $this->data; }
}

/** Item de frete do WooCommerce reduzido ao que o pedido grava. */
class WC_Order_Item_Shipping {
	public array $meta     = array();
	public string $method_id = '';
	public string $title     = '';
	public float $total      = 0.0;

	public function set_method_id( mixed $value ): void { $this->method_id = (string) $value; }
	public function set_method_title( mixed $value ): void { $this->title = (string) $value; }
	public function set_total( mixed $value ): void { $this->total = (float) $value; }
	public function add_meta_data( mixed $key, mixed $value, mixed $unique = false ): void {
		$this->meta[ (string) $key ] = $value;
	}
}

/** Pedido do WooCommerce reduzido ao que as duas funções sob teste tocam. */
class WC_Order {
	public array $meta  = array();
	public array $items = array();

	public function __construct( private int $id = 991, private float $subtotal = 0.0, private float $shipping_total = 0.0 ) {}

	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return (string) $this->id; }
	public function get_status(): string { return 'pending'; }
	public function get_subtotal(): float { return $this->subtotal; }
	public function get_shipping_total(): float { return $this->shipping_total; }
	public function get_meta( mixed $key, mixed $single = true ): mixed { return $this->meta[ (string) $key ] ?? ''; }
	public function add_item( object $item ): void { $this->items[] = $item; }
}

/** Nenhum listener é registrado. */
function add_action( mixed ...$args ): bool { return true; }
/** Nenhum filtro instalado. */
function add_filter( mixed ...$args ): bool { return true; }
/** Nenhum evento é consumido. */
function do_action( mixed ...$args ): bool { return true; }
/** Devolve o valor recebido. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed { return $value; }
/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Nenhuma rota REST é exercitada. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Nenhum pedido é buscado. */
function wc_get_orders( array $args ): array { return array(); }
/** O pagamento não é o assunto deste teste. */
function papelito_pagarme_order_payment_snapshot( object $order ): array {
	return array( 'method' => '', 'state' => '' );
}

require_once dirname( __DIR__ ) . '/includes/pricing.php';
require_once dirname( __DIR__ ) . '/includes/order_routing.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function free_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Modalidade resolvida, com preço e desconto em reais como o resolver devolve. */
function free_shipping_option( string $provider, float $price, float $discount ): array {
	return array(
		'provider'    => $provider,
		'service'     => 'PAC',
		'name'        => 'PAC',
		'code'        => 'rodoviario',
		'option_key'  => $provider . ':rodoviario',
		'fingerprint' => 'fp-1',
		'price'       => $price,
		'discount'    => $discount,
	);
}

/** Pedido já gravado, com os metas que a resposta ao comprador relê. */
function free_order_with( int $price_cents, int $discount_cents, int $items_cents ): WC_Order {
	$charged = $price_cents - min( $price_cents, $discount_cents );
	$order   = new WC_Order( 991, ( $items_cents ) / 100, $charged / 100 );

	$order->meta[ PAPELITO_PAGARME_ORDER_ID_META ]     = 'or_teste';
	$order->meta['_papelito_authoritative_total_cents'] = $items_cents + $charged;
	$order->meta['_papelito_shipping_price_cents']      = $price_cents;
	$order->meta['_papelito_shipping_discount_cents']   = $discount_cents;

	return $order;
}

foreach ( array( FREE_SHIPPING_TEST_CORREIOS, FREE_SHIPPING_TEST_BRASPRESS ) as $provider ) {
	echo "Cenário: frete grátis integral no pedido — {$provider}\n";

	$order   = new WC_Order();
	$totals  = papelito_order_routing_add_shipping_item(
		$order,
		free_shipping_option( $provider, FREE_SHIPPING_TEST_PRICE_CENTS / 100, FREE_SHIPPING_TEST_PRICE_CENTS / 100 )
	);
	$item    = $order->items[0];

	free_assert( 'O custo integral é preservado', FREE_SHIPPING_TEST_PRICE_CENTS === $totals['price_cents'] );
	free_assert( 'O abatimento é registrado à parte', FREE_SHIPPING_TEST_PRICE_CENTS === $totals['discount_cents'] );
	free_assert( 'O comprador não paga frete', 0 === $totals['charged_cents'] );
	free_assert( 'O item do pedido cobra o valor efetivo', 0.0 === $item->total );
	free_assert(
		'E o item guarda os dois valores separados, para recibo e auditoria',
		FREE_SHIPPING_TEST_PRICE_CENTS === $item->meta['_papelito_shipping_price_cents']
		&& FREE_SHIPPING_TEST_PRICE_CENTS === $item->meta['_papelito_shipping_discount_cents']
	);
	free_assert( 'O provider escolhido é o que fica gravado', $provider === $item->meta['_papelito_shipping_provider'] );
	echo "\n";
}

echo "Cenário: desconto parcial cobra a diferença\n";
$order  = new WC_Order();
$totals = papelito_order_routing_add_shipping_item( $order, free_shipping_option( FREE_SHIPPING_TEST_BRASPRESS, 30.00, 10.00 ) );

free_assert( 'O custo integral não encolhe com o desconto', 3000 === $totals['price_cents'] );
free_assert( 'O abatimento é o declarado', 1000 === $totals['discount_cents'] );
free_assert( 'A diferença é o que se cobra', 2000 === $totals['charged_cents'] );

echo "\nCenário: abatimento maior que o frete não vira crédito\n";
$order  = new WC_Order();
$totals = papelito_order_routing_add_shipping_item( $order, free_shipping_option( FREE_SHIPPING_TEST_CORREIOS, 30.00, 50.00 ) );

free_assert( 'O desconto é limitado ao próprio frete', 3000 === $totals['discount_cents'] );
free_assert( 'E o cobrado nunca fica negativo', 0 === $totals['charged_cents'] );

echo "\nCenário: a resposta ao comprador não conta o frete duas vezes\n";
$gratis = papelito_order_routing_existing_order_response(
	free_order_with( FREE_SHIPPING_TEST_PRICE_CENTS, FREE_SHIPPING_TEST_PRICE_CENTS, FREE_SHIPPING_TEST_ITEMS_CENTS )
);
$pago   = papelito_order_routing_existing_order_response(
	free_order_with( FREE_SHIPPING_TEST_PRICE_CENTS, 0, FREE_SHIPPING_TEST_ITEMS_CENTS )
);

free_assert(
	'Com frete grátis o total do comprador é só o dos itens',
	FREE_SHIPPING_TEST_ITEMS_CENTS === ( $gratis['totals']['totalCents'] ?? null )
);
free_assert(
	'E o frete continua aparecendo pelo custo integral, não por zero',
	FREE_SHIPPING_TEST_PRICE_CENTS === ( $gratis['totals']['shippingCents'] ?? null )
	&& FREE_SHIPPING_TEST_PRICE_CENTS === ( $gratis['totals']['shippingDiscountCents'] ?? null )
);
free_assert(
	'Os itens não absorvem o frete abatido',
	FREE_SHIPPING_TEST_ITEMS_CENTS === ( $gratis['totals']['itemsCents'] ?? null )
);
free_assert(
	'Sem benefício, o mesmo frete entra no total do comprador',
	FREE_SHIPPING_TEST_ITEMS_CENTS + FREE_SHIPPING_TEST_PRICE_CENTS === ( $pago['totals']['totalCents'] ?? null )
	&& 0 === ( $pago['totals']['shippingDiscountCents'] ?? null )
);
free_assert(
	'E os itens valem o mesmo nos dois casos',
	( $gratis['totals']['itemsCents'] ?? null ) === ( $pago['totals']['itemsCents'] ?? null )
);

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
