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
 * Achata o documento do recibo em texto, para assercao de conteudo.
 *
 * @param array<string,mixed> $doc Documento do recibo.
 */
function receipt_document_text( array $doc ): string {
	$parts = array(
		$doc['receipt_number'],
		'#' . $doc['order_number'],
		$doc['issued_at'],
		$doc['buyer']['label'],
		$doc['buyer']['legal_name'],
		$doc['buyer']['cnpj'],
	);

	foreach ( $doc['order'] as $value ) {
		$parts[] = (string) $value;
	}

	foreach ( $doc['blocks'] as $block ) {
		$parts[] = 'Vendor: ' . $block['vendor_name'];
		$parts[] = 'Total do vendor: ' . papelito_receipt_money_cents( $block['total_cents'] );

		foreach ( $block['items'] as $item ) {
			$parts[] = sprintf(
				'%dx %s | unit %s | desc %s | total %s',
				$item['quantity'],
				$item['name'],
				papelito_receipt_money_cents( $item['unit_price_cents'] ),
				papelito_receipt_money_cents( $item['discount_cents'] ),
				papelito_receipt_money_cents( $item['total_cents'] )
			);
		}
	}

	foreach ( $doc['totals'] as $key => $cents ) {
		$parts[] = $key . ': ' . papelito_receipt_money_cents( (int) $cents );
	}

	return implode( "\n", $parts );
}

/**
 * Texto extraido do PDF renderizado, ou null quando o pdftotext nao esta disponivel.
 */
function receipt_pdf_text( string $pdf ): ?string {
	$pdf_file  = tempnam( sys_get_temp_dir(), 'papelito-receipt-' );
	$text_file = tempnam( sys_get_temp_dir(), 'papelito-receipt-text-' );

	if ( false === $pdf_file || false === $text_file || false === file_put_contents( $pdf_file, $pdf ) ) {
		return null;
	}

	$output    = array();
	$exit_code = 0;
	exec( 'pdftotext -enc UTF-8 -layout ' . escapeshellarg( $pdf_file ) . ' ' . escapeshellarg( $text_file ) . ' 2>/dev/null', $output, $exit_code );
	$text = ( 0 === $exit_code && is_file( $text_file ) ) ? file_get_contents( $text_file ) : false;

	unlink( $pdf_file );
	unlink( $text_file );

	return is_string( $text ) ? $text : null;
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
$document   = papelito_receipt_document( $paid_order );

assert_receipt_pdf_true( 'documento do recibo persistido e gerado', is_array( $document ) );

$text = receipt_document_text( $document );
foreach ( array(
	'PPL-2026-000482',
	'#4242',
	'01/07/2026 12:00',
	'03/07/2026 09:30',
	'Papelaria São José LTDA',
	'12.345.678/0001-90',
	'Vendor: Açúcar & Cia',
	'Cartão de crédito',
	'Pago',
	'Em separação',
	'2x Caderno 10 matérias | unit R$ 25,50 | desc R$ 6,00 | total R$ 45,00',
	'subtotal_cents: R$ 51,00',
	'discount_cents: R$ 6,00',
	'shipping_cents: R$ 9,90',
	'total_cents: R$ 54,90',
) as $expected_line ) {
	assert_receipt_pdf_true( "conteudo presente: {$expected_line}", false !== strpos( $text, $expected_line ) );
}

assert_receipt_pdf( 'emissao do recibo vem da linha persistida', '03/07/2026 09:31', $document['issued_at'] );
assert_receipt_pdf_true(
	'data de geracao do PDF acompanha o relogio',
	1 === preg_match( '#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $document['generated_at'] )
);
assert_receipt_pdf( 'desconto de item liga a coluna de desconto', true, $document['has_discount'] );

// O documento le o snapshot: mutar o pedido ao vivo nao muda valor, item nem comprador.
$before = papelito_receipt_document( $paid_order );
unset( $before['generated_at'], $before['order']['order_status'] );
$paid_order->set_meta( '_papelito_vendor_name', 'Vendor Trocado' );
$paid_order->set_meta( '_papelito_company_cnpj', '99999999999999' );
$paid_order->set_meta( '_papelito_company_legal_name', 'Empresa Trocada' );
$after = papelito_receipt_document( $paid_order );
unset( $after['generated_at'], $after['order']['order_status'] );
assert_receipt_pdf( 'mutacao do pedido nao altera o conteudo financeiro do documento', $before, $after );

// A situacao do pedido e informativa e acompanha o pedido ao vivo.
$paid_order->set_meta( '_papelito_vendor_status', 'entregue' );
$live = papelito_receipt_document( $paid_order );
assert_receipt_pdf( 'situacao do pedido acompanha o pedido ao vivo', 'Entregue', $live['order']['order_status'] );
assert_receipt_pdf( 'situacao do pagamento continua vindo do recibo', 'Pago', $live['order']['payment_state'] );
$paid_order->set_meta( '_papelito_vendor_status', 'em_separacao' );

// Multivendor: uma parcela por vendor, com total proprio. O valor unitario e
// derivado quando o snapshot antigo nao o traz.
$multi_items         = array(
	array( 'name' => 'Papel A4', 'quantity' => 1, 'total_cents' => 30000, 'vendor_id' => 1, 'vendor_name' => 'Vendor A' ),
	array( 'name' => 'Caneta', 'quantity' => 2, 'total_cents' => 25000, 'vendor_id' => 2, 'vendor_name' => 'Vendor B' ),
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

$multi_document = papelito_receipt_document( new ReceiptTestOrder( 7777 ) );
$multi_text     = receipt_document_text( $multi_document );
assert_receipt_pdf( 'multivendor e sinalizado no documento', true, $multi_document['multivendor'] );
assert_receipt_pdf( 'multivendor nao aponta um unico vendor', 'Vários vendors', $multi_document['order']['vendor'] );
foreach ( array(
	'Vendor: Vendor A',
	'Vendor: Vendor B',
	'1x Papel A4 | unit R$ 300,00 | desc R$ 0,00 | total R$ 300,00',
	'2x Caneta | unit R$ 125,00 | desc R$ 0,00 | total R$ 250,00',
	'Total do vendor: R$ 305,46',
	'Total do vendor: R$ 254,55',
	'total_cents: R$ 560,01',
) as $expected_line ) {
	assert_receipt_pdf_true( "multivendor: {$expected_line}", false !== strpos( $multi_text, $expected_line ) );
}

// Pedido pago sem recibo: emite de forma idempotente e segue.
$fallback = papelito_receipt_document( new ReceiptTestOrder( 5555 ) );
assert_receipt_pdf_true( 'pedido sem recibo dispara emissao idempotente', in_array( 5555, $issued_orders, true ) );
assert_receipt_pdf( 'pedido sem recibo gera o documento apos a emissao', 'PPL-2026-000900', is_array( $fallback ) ? $fallback['receipt_number'] : null );

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
$individual                                = papelito_receipt_document( new ReceiptTestOrder( 8888 ) );
assert_receipt_pdf( 'compra individual nao exibe CNPJ', '', $individual['buyer']['cnpj'] );
assert_receipt_pdf( 'compra individual nao inventa razao social', '', $individual['buyer']['legal_name'] );
assert_receipt_pdf( 'compra individual exibe o comprador', 'Maria de Souza', $individual['buyer']['label'] );

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

// PDF binario valido, em A4, com acentuacao preservada.
$pdf = papelito_receipt_pdf( $paid_order );

if ( ! is_string( $pdf ) || 0 !== strpos( $pdf, '%PDF-1.4' ) || false === strpos( $pdf, '%%EOF' ) ) {
	echo "RESULT: receipt PDF is invalid\n";
	exit( 1 );
}

assert_receipt_pdf_true( 'paginas em A4', false !== strpos( $pdf, '/MediaBox [0 0 595 842]' ) );

$extracted_text = receipt_pdf_text( $pdf );

foreach ( array(
	'RECIBO DE PEDIDO',
	'PPL-2026-000482',
	'#4242',
	'Açúcar & Cia',
	'São José',
	'12.345.678/0001-90',
	'Cartão de crédito',
	'Caderno 10 matérias',
	'SITUAÇÃO DO PEDIDO',
	'TOTAL PAGO',
	'R$ 54,90',
	'Emitido por Papelito',
	'Página 1 de 1',
) as $expected_text ) {
	if ( ! is_string( $extracted_text ) || false === strpos( $extracted_text, $expected_text ) ) {
		echo "RESULT: receipt PDF is missing expected content: {$expected_text}\n";
		exit( 1 );
	}
}

// Pedido longo: quebra em paginas, repete o cabecalho da tabela e numera as folhas.
$long_items = array();
for ( $index = 1; $index <= 40; $index++ ) {
	$long_items[] = array(
		'name'             => 'Papel Sulfite A4 75g/m² Branco Alcalino — caixa com 10 resmas de 500 folhas, item ' . $index,
		'quantity'         => $index,
		'unit_price_cents' => 1990 + $index,
		'subtotal_cents'   => ( 1990 + $index ) * $index,
		'discount_cents'   => 0,
		'total_cents'      => ( 1990 + $index ) * $index,
		'vendor_id'        => 9,
		'vendor_name'      => 'Açúcar & Cia',
	);
}
$receipt_store[9999] = receipt_fixture(
	9999,
	'PPL-2026-000999',
	$long_items,
	array( 'subtotal_cents' => 1234567, 'discount_cents' => 0, 'shipping_cents' => 98765, 'total_cents' => 1333332 )
);
$vendor_parts[9999]  = array( array( 'vendor_id' => 9, 'vendor_name' => 'Açúcar & Cia', 'total_cents' => 1333332, 'items_json' => wp_json_encode( array() ) ) );

$long_pdf  = papelito_receipt_pdf( new ReceiptTestOrder( 9999 ) );
$long_text = is_string( $long_pdf ) ? receipt_pdf_text( $long_pdf ) : null;

assert_receipt_pdf_true( 'pedido longo gera mais de uma pagina', is_string( $long_pdf ) && substr_count( $long_pdf, '/Type /Page ' ) > 1 );
assert_receipt_pdf_true(
	'continuacao repete o cabecalho da tabela',
	is_string( $long_text ) && substr_count( $long_text, 'DESCRIÇÃO' ) > 1
);
assert_receipt_pdf_true(
	'continuacao repete o numero do recibo',
	is_string( $long_text ) && substr_count( $long_text, 'PPL-2026-000999' ) > 2
);
assert_receipt_pdf_true( 'totais fecham na ultima pagina', is_string( $long_text ) && false !== strpos( $long_text, 'R$ 13.333,32' ) );
assert_receipt_pdf_true( 'paginas numeradas', is_string( $long_text ) && false !== strpos( $long_text, 'Página 2 de' ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: receipt PDF generated\n";
