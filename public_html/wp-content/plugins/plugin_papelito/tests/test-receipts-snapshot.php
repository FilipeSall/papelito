<?php
/**
 * Standalone tests for the receipt snapshot, cents math and numbering helpers.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action() {}
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function papelito_pricing_to_cents( $value ): int { return is_numeric( $value ) ? max( 0, (int) round( (float) $value * 100 ) ) : 0; }

require __DIR__ . '/../includes/receipts.php';

$failures = 0;
function assert_receipt( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

class ReceiptTestItem {
	/**
	 * @param array<string,mixed> $meta Metadados do item.
	 */
	public function __construct(
		private string $name,
		private int $quantity,
		private float $subtotal,
		private float $total,
		private array $meta = array()
	) {}
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->quantity; }
	public function get_subtotal() { return $this->subtotal; }
	public function get_total() { return $this->total; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
}

class ReceiptTestOrder {
	/**
	 * @param array<int,ReceiptTestItem> $items Itens do pedido.
	 * @param array<string,mixed>        $meta  Metadados do pedido.
	 */
	public function __construct(
		private array $items,
		private array $meta = array(),
		private float $shipping = 0.0,
		private string $company = '',
		private string $buyer = 'Comprador Teste'
	) {}
	public function get_id() { return 4242; }
	public function get_order_number() { return '4242'; }
	public function get_status() { return 'processing'; }
	public function get_currency() { return 'BRL'; }
	public function get_customer_id() { return 77; }
	public function get_payment_method() { return 'credit_card'; }
	public function get_payment_method_title() { return 'Cartão de crédito'; }
	public function get_billing_company() { return $this->company; }
	public function get_formatted_billing_full_name() { return $this->buyer; }
	public function get_shipping_total() { return $this->shipping; }
	public function get_items( $type ) { return $this->items; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function get_date_created() { return new DateTimeImmutable( '@1719878400' ); }
	public function get_date_paid() { return new DateTimeImmutable( '@1720000000' ); }
}

function cents_meta( int $subtotal, int $discount, int $total ): array {
	return array(
		'_papelito_subtotal_cents' => $subtotal,
		'_papelito_discount_cents' => $discount,
		'_papelito_total_cents'    => $total,
	);
}

// 1. Um vendor, com metas de centavos gravadas pelo checkout headless.
$single = papelito_receipt_build_snapshot(
	new ReceiptTestOrder(
		array( new ReceiptTestItem( 'Caderno', 2, 51.00, 45.00, cents_meta( 5100, 600, 4500 ) + array( '_vendor_id' => 9, '_vendor_name' => 'Vendor A' ) ) ),
		array( '_papelito_vendor_id' => 9, '_papelito_vendor_name' => 'Vendor A', '_papelito_authoritative_total_cents' => 5490 ),
		9.90
	)
);
assert_receipt( 'subtotal em centavos', 5100, $single['totals']['subtotal_cents'] );
assert_receipt( 'desconto em centavos', 600, $single['totals']['discount_cents'] );
assert_receipt( 'frete em centavos', 990, $single['totals']['shipping_cents'] );
assert_receipt( 'total em centavos', 5490, $single['totals']['total_cents'] );
assert_receipt( 'sem divergencia com o total autoritativo', false, $single['totals']['totals_mismatch'] );
assert_receipt( 'uma parcela de vendor', 1, count( $single['vendors'] ) );
assert_receipt( 'parcela recebe todo o frete', 990, $single['vendors'][0]['shipping_cents'] );
assert_receipt( 'parcela fecha com o total', 5490, $single['vendors'][0]['total_cents'] );
assert_receipt( 'preco unitario derivado da quantidade', 2550, $single['items'][0]['unit_price_cents'] );

// 2. Multivendor: o checkout impede hoje, mas o snapshot precisa suportar.
$multi = papelito_receipt_build_snapshot(
	new ReceiptTestOrder(
		array(
			new ReceiptTestItem( 'Papel A4', 1, 300.00, 300.00, cents_meta( 30000, 0, 30000 ) + array( '_vendor_id' => 1, '_vendor_name' => 'Vendor A' ) ),
			new ReceiptTestItem( 'Caneta', 1, 250.00, 250.00, cents_meta( 25000, 0, 25000 ) + array( '_vendor_id' => 2, '_vendor_name' => 'Vendor B' ) ),
		),
		array( '_papelito_vendor_id' => 1, '_papelito_vendor_name' => 'Vendor A' ),
		10.01
	)
);
$parts_sum = array_sum( array_column( $multi['vendors'], 'total_cents' ) );
assert_receipt( 'multivendor gera duas parcelas', 2, count( $multi['vendors'] ) );
assert_receipt( 'soma das parcelas bate exatamente com o total', $multi['totals']['total_cents'], $parts_sum );
assert_receipt( 'frete impar nao perde centavo', 1001, array_sum( array_column( $multi['vendors'], 'shipping_cents' ) ) );
assert_receipt( 'total do pedido multivendor', 56001, $multi['totals']['total_cents'] );
assert_receipt( 'parcela do vendor A', 30000, $multi['vendors'][0]['subtotal_cents'] );
assert_receipt( 'parcela do vendor B', 25000, $multi['vendors'][1]['subtotal_cents'] );
assert_receipt( 'itens ficam na parcela do proprio vendor', 1, count( $multi['vendors'][1]['items'] ) );

// 3. Pedido legado sem metas de centavos cai para os floats do WooCommerce.
$legacy = papelito_receipt_build_snapshot(
	new ReceiptTestOrder(
		array( new ReceiptTestItem( 'Item legado', 1, 20.00, 18.50, array( '_vendor_id' => 5, '_vendor_name' => 'Vendor L' ) ) ),
		array( '_papelito_vendor_id' => 5 ),
		5.00
	)
);
assert_receipt( 'subtotal legado vem do float', 2000, $legacy['totals']['subtotal_cents'] );
assert_receipt( 'desconto legado derivado de subtotal - total', 150, $legacy['totals']['discount_cents'] );
assert_receipt( 'total legado', 2350, $legacy['totals']['total_cents'] );

// 4. B2B: CNPJ vem da meta do pedido, só com dígitos.
$b2b = papelito_receipt_build_snapshot(
	new ReceiptTestOrder(
		array( new ReceiptTestItem( 'Resma', 1, 10.00, 10.00, cents_meta( 1000, 0, 1000 ) + array( '_vendor_id' => 3, '_vendor_name' => 'Vendor C' ) ) ),
		array(
			'_papelito_vendor_id'                => 3,
			'_papelito_company_snapshot_version' => '1',
			'_papelito_company_id'               => 12,
			'_papelito_company_cnpj'             => '12.345.678/0001-90',
			'_papelito_company_legal_name'       => 'Empresa Teste LTDA',
		),
		0.0,
		'Empresa Teste LTDA'
	)
);
assert_receipt( 'cnpj normalizado para digitos', '12345678000190', $b2b['company']['cnpj'] );
assert_receipt( 'razao social no snapshot', 'Empresa Teste LTDA', $b2b['company']['legal_name'] );
assert_receipt( 'comprador usa a razao social', 'Empresa Teste LTDA', $b2b['buyer']['label'] );
assert_receipt( 'marca compra B2B', true, $b2b['buyer']['is_b2b'] );

$individual = papelito_receipt_build_snapshot(
	new ReceiptTestOrder(
		array( new ReceiptTestItem( 'Resma', 1, 10.00, 10.00, cents_meta( 1000, 0, 1000 ) + array( '_vendor_id' => 3 ) ) ),
		array( '_papelito_vendor_id' => 3 )
	)
);
assert_receipt( 'compra individual nao tem bloco de empresa', null, $individual['company'] );
assert_receipt( 'comprador usa o nome de faturamento', 'Comprador Teste', $individual['buyer']['label'] );

// 5. Divergência entre total derivado e total autoritativo é sinalizada, não silenciada.
$mismatch = papelito_receipt_build_snapshot(
	new ReceiptTestOrder(
		array( new ReceiptTestItem( 'Item', 1, 10.00, 10.00, cents_meta( 1000, 0, 1000 ) + array( '_vendor_id' => 1 ) ) ),
		array( '_papelito_vendor_id' => 1, '_papelito_authoritative_total_cents' => 9999 )
	)
);
assert_receipt( 'divergencia de total e sinalizada', true, $mismatch['totals']['totals_mismatch'] );
assert_receipt( 'total do recibo mantem a invariante interna', 1000, $mismatch['totals']['total_cents'] );

// 6. Repartição de centavos nunca perde nem cria centavo.
foreach ( array(
	array( 1001, array( 300, 250 ) ),
	array( 1, array( 1, 1, 1 ) ),
	array( 100, array( 1, 1, 1 ) ),
	array( 9999, array( 7, 11, 13, 17 ) ),
	array( 0, array( 5, 5 ) ),
	array( 500, array( 0, 0 ) ),
) as $index => $scenario ) {
	list( $amount, $weights ) = $scenario;
	$allocation               = papelito_receipt_allocate_cents( $amount, $weights );
	assert_receipt( "reparticao {$index} soma exatamente", $amount, array_sum( $allocation ) );
	assert_receipt( "reparticao {$index} sem valor negativo", 0, count( array_filter( $allocation, static fn( $v ) => $v < 0 ) ) );
}
assert_receipt( 'reparticao sem pesos devolve vazio', array(), papelito_receipt_allocate_cents( 100, array() ) );

// 7. Numeração e ano da sequência.
assert_receipt( 'formato do numero', 'PPL-2026-000482', papelito_receipt_format_number( 2026, 482 ) );
assert_receipt( 'numero com sequencia alta', 'PPL-2026-1000000', papelito_receipt_format_number( 2026, 1000000 ) );
assert_receipt( 'ano vem do pagamento', 2025, papelito_receipt_sequence_year( array( 'order' => array( 'paid_at' => '2025-12-31 23:59:59', 'ordered_at' => '2024-01-01 00:00:00' ) ) ) );
assert_receipt( 'ano cai para a criacao quando nao ha pagamento', 2024, papelito_receipt_sequence_year( array( 'order' => array( 'paid_at' => null, 'ordered_at' => '2024-01-01 00:00:00' ) ) ) );
assert_receipt( 'ano cai para o ano corrente', (int) gmdate( 'Y' ), papelito_receipt_sequence_year( array( 'order' => array() ) ) );

// 8. O snapshot é derivado só do pedido: recalcular com o mesmo pedido dá o mesmo JSON.
$order_twice = new ReceiptTestOrder(
	array( new ReceiptTestItem( 'Caderno', 2, 51.00, 45.00, cents_meta( 5100, 600, 4500 ) + array( '_vendor_id' => 9 ) ) ),
	array( '_papelito_vendor_id' => 9 ),
	9.90
);
assert_receipt(
	'snapshot e deterministico para o mesmo pedido',
	json_encode( papelito_receipt_build_snapshot( $order_twice ) ),
	json_encode( papelito_receipt_build_snapshot( $order_twice ) )
);
assert_receipt( 'snapshot carrega a versao', PAPELITO_RECEIPT_SNAPSHOT_VERSION, $single['version'] );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
