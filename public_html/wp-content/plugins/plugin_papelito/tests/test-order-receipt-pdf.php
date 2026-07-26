<?php
/**
 * Standalone regression test for the internal order receipt PDF.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
function add_filter() {}
function add_action() {}
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }

class ReceiptTestItem {
	public function get_name() { return 'Caderno 10 matérias'; }
	public function get_quantity() { return 2; }
	public function get_total() { return 25.5; }
}

class ReceiptTestOrder {
	public function get_meta( $key, $single = true ) { return '_papelito_vendor_name' === $key ? 'Açúcar & Cia' : ''; }
	public function get_formatted_billing_full_name() { return 'São José'; }
	public function get_billing_company() { return ''; }
	public function get_date_paid() { return new DateTimeImmutable( '@1720000000' ); }
	public function get_order_number() { return '123'; }
	public function get_payment_method_title() { return 'Cartão de crédito'; }
	public function get_items( $type ) { return array( new ReceiptTestItem() ); }
	public function get_subtotal() { return 25.5; }
	public function get_shipping_total() { return 9.9; }
	public function get_discount_total() { return 0; }
	public function get_total() { return 35.4; }
}

require __DIR__ . '/../includes/order_receipt.php';

$pdf = papelito_receipt_pdf( new ReceiptTestOrder() );

if ( 0 !== strpos( $pdf, '%PDF-1.4' ) || false === strpos( $pdf, '%%EOF' ) || false === strpos( $pdf, 'Recibo de pedido' ) ) {
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

foreach ( array( 'Açúcar & Cia', 'São José', 'Cartão de crédito', 'Caderno 10 matérias' ) as $expected_text ) {
	if ( ! is_string( $extracted_text ) || false === strpos( $extracted_text, $expected_text ) ) {
		echo "RESULT: receipt PDF corrupts Brazilian Portuguese characters\n";
		exit( 1 );
	}
}

echo "RESULT: receipt PDF generated\n";
