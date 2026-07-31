<?php
/**
 * Standalone regression test for the internal order receipt PDF.
 *
 * Cobre a leitura do recibo persistido: o PDF sai do snapshot imutavel e nao do
 * WC_Order ao vivo, com fallback idempotente e erro controlado.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_filter() {}
function add_action() {}
function wp_strip_all_tags( mixed $value ) { return strip_tags( (string) $value ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_key( mixed $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function wp_date( string $format, int $timestamp ) { return gmdate( $format, $timestamp ); }
function is_wp_error( mixed $thing ) { return $thing instanceof WP_Error; }
function wp_json_encode( mixed $value ) { return json_encode( $value ); }

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

$receipt_store  = array();
$vendor_parts   = array();
$issued_orders  = array();
$payment_states = array();

function papelito_pagarme_order_payment_snapshot( object $order ): array {
	global $payment_states;
	return array( 'state' => $payment_states[ (int) $order->get_id() ] ?? 'paid' );
}

function papelito_pagarme_payment_state_is_paid( string $state ): bool {
	return in_array( $state, array( 'paid', 'captured' ), true );
}

function papelito_receipt_get_by_order( int $order_id ): ?array {
	global $receipt_store;
	return $receipt_store[ $order_id ] ?? null;
}

function papelito_receipt_vendor_parts( int $receipt_id ): array {
	global $vendor_parts;
	return $vendor_parts[ $receipt_id ] ?? array();
}

function papelito_receipt_issue_for_order( int $order_id, string $origin = 'payment' ) {
	global $receipt_store, $issued_orders;
	$issued_orders[] = $order_id;

	if ( isset( $receipt_store[ $order_id ] ) ) {
		return $receipt_store[ $order_id ];
	}

	if ( 5555 === $order_id ) {
		$receipt_store[ $order_id ] = receipt_fixture( $order_id, 'PPL-2026-000900' );
		return $receipt_store[ $order_id ];
	}

	return new WP_Error( 'papelito_receipt_payment_not_confirmed', 'Pagamento nao confirmado.', array( 'status' => 409 ) );
}

/**
 * Pedido minimo: o PDF so le dele o identificador e a situacao operacional.
 */
class ReceiptTestOrder {
	/**
	 * Constroi o pedido de teste.
	 *
	 * @param array<string,mixed> $meta Metadados do pedido.
	 */
	public function __construct(
		private int $id = 4242,
		private array $meta = array( '_papelito_vendor_status' => 'em_separacao' ),
		private string $status = 'processing'
	) {}
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function set_meta( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function set_status( string $status ): void { $this->status = $status; }
}

/**
 * Linha de recibo persistido, como o banco a devolveria.
 *
 * @param array<int,array<string,mixed>> $items Itens do snapshot.
 * @return array<string,mixed>
 */
function receipt_fixture( int $order_id, string $number, array $items = array(), array $totals = array() ): array {
	$items  = $items ? $items : array(
		array(
			'name'           => 'Caderno 10 matérias',
			'quantity'       => 2,
			'subtotal_cents' => 5100,
			'discount_cents' => 600,
			'total_cents'    => 4500,
			'vendor_id'      => 9,
			'vendor_name'    => 'Açúcar & Cia',
		),
	);
	$totals = $totals ? $totals : array(
		'subtotal_cents' => 5100,
		'discount_cents' => 600,
		'shipping_cents' => 990,
		'total_cents'    => 5490,
	);

	return array(
		'id'                   => $order_id,
		'receipt_number'       => $number,
		'order_id'             => $order_id,
		'buyer_label'          => 'Papelaria São José LTDA',
		'company_cnpj'         => '12345678000190',
		'company_legal_name'   => 'Papelaria São José LTDA',
		'payment_method'       => 'credit_card',
		'payment_method_title' => 'Cartão de crédito',
		'payment_state'        => 'paid',
		'order_status'         => 'processing',
		'subtotal_cents'       => $totals['subtotal_cents'],
		'discount_cents'       => $totals['discount_cents'],
		'shipping_cents'       => $totals['shipping_cents'],
		'total_cents'          => $totals['total_cents'],
		'ordered_at'           => '2026-07-01 12:00:00',
		'paid_at'              => '2026-07-03 09:30:00',
		'issued_at'            => '2026-07-03 09:31:00',
		'snapshot_json'        => wp_json_encode(
			array(
				'version' => 1,
				'order'   => array( 'id' => $order_id, 'number' => (string) $order_id, 'status' => 'processing' ),
				'items'   => $items,
				'totals'  => $totals,
				'vendors' => array(
					array( 'vendor_id' => 9, 'vendor_name' => 'Açúcar & Cia', 'total_cents' => $totals['total_cents'], 'items' => $items ),
				),
			)
		),
	);
}

require __DIR__ . '/../includes/order_receipt.php';

$failures = 0;
function assert_receipt_pdf( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

function assert_receipt_pdf_true( string $label, bool $condition ): void {
	assert_receipt_pdf( $label, true, $condition );
}

/**
 * Achata as linhas do PDF em texto, para assercao de conteudo.
 *
 * @param array<int,array<string,mixed>> $lines Linhas do PDF.
 */
function receipt_lines_text( array $lines ): string {
	return implode( "\n", array_column( $lines, 'text' ) );
}

$receipt_store[4242] = receipt_fixture( 4242, 'PPL-2026-000482' );
$vendor_parts[4242]  = array(
	array(
		'vendor_id'   => 9,
		'vendor_name' => 'Açúcar & Cia',
		'total_cents' => 5490,
		'items_json'  => wp_json_encode( array() ),
	),
);

$paid_order = new ReceiptTestOrder();
$lines      = papelito_receipt_pdf_lines( $paid_order );

assert_receipt_pdf_true( 'linhas do recibo persistido sao geradas', is_array( $lines ) );

$text = receipt_lines_text( $lines );
foreach ( array(
	'Recibo PPL-2026-000482',
	'Pedido #4242',
	'Data da compra: 01/07/2026 12:00',
	'Data do pagamento: 03/07/2026 09:30',
	'Comprador: Papelaria São José LTDA',
	'CNPJ: 12.345.678/0001-90',
	'Vendor: Açúcar & Cia',
	'Pagamento: Cartão de crédito',
	'Situação do pagamento: Pago',
	'Situação do pedido: Em separação',
	'2x Caderno 10 matérias — R$ 45,00',
	'Subtotal: R$ 51,00',
	'Descontos: -R$ 6,00',
	'Frete: R$ 9,90',
	'Total pago: R$ 54,90',
	'Emitido por Papelito',
) as $expected_line ) {
	assert_receipt_pdf_true( "linha presente: {$expected_line}", false !== strpos( $text, $expected_line ) );
}

$footer = (string) end( $lines )['text'];
assert_receipt_pdf_true(
	'rodape com numero do recibo e data de geracao',
	1 === preg_match( '#^Recibo PPL-2026-000482 · PDF gerado em \d{2}/\d{2}/\d{4} \d{2}:\d{2}$#u', $footer )
);

// O PDF le o snapshot: mutar o pedido ao vivo nao muda valor, item nem comprador.
$before = array_slice( papelito_receipt_pdf_lines( $paid_order ), 0, -1 );
$paid_order->set_meta( '_papelito_vendor_name', 'Vendor Trocado' );
$paid_order->set_meta( '_papelito_company_cnpj', '99999999999999' );
$paid_order->set_meta( '_papelito_company_legal_name', 'Empresa Trocada' );
$after = array_slice( papelito_receipt_pdf_lines( $paid_order ), 0, -1 );
assert_receipt_pdf( 'mutacao do pedido nao altera o conteudo financeiro do PDF', $before, $after );

// A situacao do pedido e informativa e acompanha o pedido ao vivo.
$paid_order->set_meta( '_papelito_vendor_status', 'entregue' );
$live = receipt_lines_text( papelito_receipt_pdf_lines( $paid_order ) );
assert_receipt_pdf_true( 'situacao do pedido acompanha o pedido ao vivo', false !== strpos( $live, 'Situação do pedido: Entregue' ) );
assert_receipt_pdf_true( 'situacao do pagamento continua vindo do recibo', false !== strpos( $live, 'Situação do pagamento: Pago' ) );
$paid_order->set_meta( '_papelito_vendor_status', 'em_separacao' );

// Multivendor: uma parcela por vendor, com total proprio.
$multi_items         = array(
	array( 'name' => 'Papel A4', 'quantity' => 1, 'total_cents' => 30000, 'vendor_id' => 1, 'vendor_name' => 'Vendor A' ),
	array( 'name' => 'Caneta', 'quantity' => 1, 'total_cents' => 25000, 'vendor_id' => 2, 'vendor_name' => 'Vendor B' ),
);
$receipt_store[7777] = receipt_fixture(
	7777,
	'PPL-2026-000777',
	$multi_items,
	array( 'subtotal_cents' => 55000, 'discount_cents' => 0, 'shipping_cents' => 1001, 'total_cents' => 56001 )
);
$vendor_parts[7777]  = array(
	array( 'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'total_cents' => 30546, 'items_json' => wp_json_encode( array() ) ),
	array( 'vendor_id' => 2, 'vendor_name' => 'Vendor B', 'total_cents' => 25455, 'items_json' => wp_json_encode( array() ) ),
);

$multi_text = receipt_lines_text( papelito_receipt_pdf_lines( new ReceiptTestOrder( 7777 ) ) );
foreach ( array( 'Vendor: Vendor A', 'Vendor: Vendor B', '1x Papel A4 — R$ 300,00', '1x Caneta — R$ 250,00', 'Total do vendor: R$ 305,46', 'Total do vendor: R$ 254,55', 'Total pago: R$ 560,01' ) as $expected_line ) {
	assert_receipt_pdf_true( "multivendor: {$expected_line}", false !== strpos( $multi_text, $expected_line ) );
}

// Pedido pago sem recibo: emite de forma idempotente e segue.
$fallback = papelito_receipt_pdf_lines( new ReceiptTestOrder( 5555 ) );
assert_receipt_pdf_true( 'pedido sem recibo dispara emissao idempotente', in_array( 5555, $issued_orders, true ) );
assert_receipt_pdf_true( 'pedido sem recibo gera o PDF apos a emissao', is_array( $fallback ) && false !== strpos( receipt_lines_text( $fallback ), 'Recibo PPL-2026-000900' ) );

// Recibo impossivel: erro controlado, nunca fatal.
$unavailable = papelito_receipt_pdf( new ReceiptTestOrder( 6666 ) );
assert_receipt_pdf_true( 'recibo indisponivel devolve WP_Error', $unavailable instanceof WP_Error );
assert_receipt_pdf( 'codigo do erro controlado', 'papelito_receipt_unavailable', $unavailable->get_error_code() );
assert_receipt_pdf( 'status do erro controlado', 409, $unavailable->get_error_data()['status'] ?? 0 );

// Compra individual: sem bloco de empresa.
$receipt_store[8888]                       = receipt_fixture( 8888, 'PPL-2026-000888' );
$receipt_store[8888]['company_cnpj']       = null;
$receipt_store[8888]['company_legal_name'] = null;
$receipt_store[8888]['buyer_label']        = 'Maria de Souza';
$vendor_parts[8888]                        = $vendor_parts[4242];
$individual                                = receipt_lines_text( papelito_receipt_pdf_lines( new ReceiptTestOrder( 8888 ) ) );
assert_receipt_pdf_true( 'compra individual nao exibe CNPJ', false === strpos( $individual, 'CNPJ:' ) );
assert_receipt_pdf_true( 'compra individual exibe o comprador', false !== strpos( $individual, 'Comprador: Maria de Souza' ) );

// Resumo para o payload de detalhe do pedido: informa, nao emite.
$summary = papelito_receipt_public_summary( $paid_order );
assert_receipt_pdf( 'resumo traz o numero do recibo', 'PPL-2026-000482', $summary['number'] );
assert_receipt_pdf( 'resumo libera o download do pedido pago', true, $summary['available'] );
assert_receipt_pdf( 'resumo traz a data de emissao', '03/07/2026 09:31', $summary['issued_at'] );

$issue_calls_before_summary = count( $issued_orders );
$pending_summary            = papelito_receipt_public_summary( new ReceiptTestOrder( 5556 ) );
assert_receipt_pdf( 'resumo nao emite recibo', $issue_calls_before_summary, count( $issued_orders ) );
assert_receipt_pdf( 'pedido sem recibo tem numero nulo', null, $pending_summary['number'] );
assert_receipt_pdf( 'pedido sem recibo nao tem data de emissao', null, $pending_summary['issued_at'] );
assert_receipt_pdf( 'pedido pago sem recibo continua com download liberado', true, $pending_summary['available'] );

$payment_states[5557] = 'pending';
$unpaid_summary       = papelito_receipt_public_summary( new ReceiptTestOrder( 5557 ) );
assert_receipt_pdf( 'pedido nao pago nao libera download', false, $unpaid_summary['available'] );
assert_receipt_pdf( 'pedido nao pago nao tem numero', null, $unpaid_summary['number'] );

// PDF binario valido, com acentuacao preservada.
$pdf = papelito_receipt_pdf( $paid_order );

if ( ! is_string( $pdf ) || 0 !== strpos( $pdf, '%PDF-1.4' ) || false === strpos( $pdf, '%%EOF' ) ) {
	echo "RESULT: receipt PDF is invalid\n";
	exit( 1 );
}

$pdf_file  = tempnam( sys_get_temp_dir(), 'papelito-receipt-' );
$text_file = tempnam( sys_get_temp_dir(), 'papelito-receipt-text-' );

if ( false === $pdf_file || false === $text_file || false === file_put_contents( $pdf_file, $pdf ) ) {
	echo "RESULT: receipt PDF test fixture could not be created\n";
	exit( 1 );
}

exec( 'pdftotext -enc UTF-8 ' . escapeshellarg( $pdf_file ) . ' ' . escapeshellarg( $text_file ), $output, $exit_code );
$extracted_text = ( 0 === $exit_code && is_file( $text_file ) ) ? file_get_contents( $text_file ) : false;
unlink( $pdf_file );
unlink( $text_file );

foreach ( array( 'Açúcar & Cia', 'São José', 'Cartão de crédito', 'Caderno 10 matérias', 'Situação do pedido', 'PPL-2026-000482' ) as $expected_text ) {
	if ( ! is_string( $extracted_text ) || false === strpos( $extracted_text, $expected_text ) ) {
		echo "RESULT: receipt PDF corrupts Brazilian Portuguese characters or drops the receipt number\n";
		exit( 1 );
	}
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: receipt PDF generated\n";
