<?php
/**
 * Regression test for the admin sales export definition.
 *
 * Usage: php tests/test-admin-sales-export.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );

final class WP_REST_Server {
	public const READABLE = 'GET';
}

final class WC_DateTime {
	public function date_i18n( string $format ): string { return '2026-08-04 12:00:00'; }
}

final class WC_Order {
	public function __construct(
		private int $id,
		private string $status,
		private bool $paid,
		private string $shipping_name = '',
		private string $billing_company = '',
		private string $billing_name = ''
	) {}

	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return (string) $this->id; }
	public function get_date_created(): WC_DateTime { return new WC_DateTime(); }
	public function get_status(): string { return $this->status; }
	public function get_formatted_shipping_full_name(): string { return $this->shipping_name; }
	public function get_shipping_company(): string { return ''; }
	public function get_billing_company(): string { return $this->billing_company; }
	public function get_formatted_billing_full_name(): string { return $this->billing_name; }
	public function get_billing_phone(): string { return '11999999999'; }
	public function get_billing_postcode(): string { return '01310930'; }
	public function get_billing_city(): string { return 'São Paulo'; }
	public function get_billing_state(): string { return 'SP'; }
	public function get_payment_method_title(): string { return 'Cartão'; }
	public function get_total(): float { return 100.0; }
	public function is_paid(): bool { return $this->paid; }
}

function add_action( $hook, $callback ): void {}
function current_user_can( $capability ): bool { return true; }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function wc_get_order_statuses(): array { return array( 'wc-failed' => 'Falhou', 'wc-processing' => 'Processando', 'wc-refunded' => 'Reembolsado' ); }
function wc_get_order_status_name( $status ): string { return (string) $status; }
function wc_get_orders( $args ): array {
	return array(
		new WC_Order( 1, 'failed', false ),
		// Pedido B2B real: billing sem nome, pessoa so no shipping.
		new WC_Order( 2, 'processing', true, 'Marcos Stub de Oliveira', 'CERRADO PAPEIS E SUPRIMENTOS LTDA' ),
		// Sem pessoa no shipping: cai na empresa compradora.
		new WC_Order( 3, 'refunded', true, '', 'CERRADO PAPEIS E SUPRIMENTOS LTDA' ),
		// Legado nao-B2B: so billing name.
		new WC_Order( 4, 'processing', true, '', '', 'Cliente Legado' ),
		// Nada identificavel: o fallback continua valendo.
		new WC_Order( 5, 'processing', true ),
	);
}
function papelito_vendor_dashboard_order_is_paid( $order ): bool { return $order instanceof WC_Order && $order->is_paid(); }
function papelito_vendor_dashboard_customer_label( $order ): string {
	$candidates = array(
		$order->get_formatted_shipping_full_name(),
		$order->get_shipping_company(),
		$order->get_billing_company(),
		$order->get_formatted_billing_full_name(),
	);

	foreach ( $candidates as $candidate ) {
		$candidate = sanitize_text_field( trim( (string) $candidate ) );

		if ( '' !== $candidate ) {
			return $candidate;
		}
	}

	return '';
}

require_once dirname( __DIR__ ) . '/includes/admin_reports.php';

$rows = papelito_admin_reports_query_simple_sales_rows(
	array(
		'from' => '2026-08-01',
		'to'   => '2026-08-31',
	)
);

if ( array_column( $rows, 'order_id' ) !== array( 2, 3, 4, 5 ) ) {
	echo "FAIL: exportação deve conter somente pedidos com pagamento confirmado\n";
	exit( 1 );
}

$names = array_column( $rows, 'customer_name' );
$expected = array(
	'Marcos Stub de Oliveira',
	'CERRADO PAPEIS E SUPRIMENTOS LTDA',
	'Cliente Legado',
	'Cliente não identificado',
);

if ( $names !== $expected ) {
	echo "FAIL: nome do cliente resolvido errado\n";
	echo 'esperado: ' . wp_json_encode_fallback( $expected ) . "\n";
	echo 'obtido:   ' . wp_json_encode_fallback( $names ) . "\n";
	exit( 1 );
}

function wp_json_encode_fallback( array $value ): string {
	return implode( ' | ', $value );
}

echo "OK\n";
