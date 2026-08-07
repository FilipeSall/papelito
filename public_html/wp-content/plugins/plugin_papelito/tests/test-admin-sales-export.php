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
		private bool $paid
	) {}

	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return (string) $this->id; }
	public function get_date_created(): WC_DateTime { return new WC_DateTime(); }
	public function get_status(): string { return $this->status; }
	public function get_formatted_billing_full_name(): string { return 'Cliente de teste'; }
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
function wc_get_order_statuses(): array { return array( 'wc-failed' => 'Falhou', 'wc-processing' => 'Processando', 'wc-refunded' => 'Reembolsado' ); }
function wc_get_order_status_name( $status ): string { return (string) $status; }
function wc_get_orders( $args ): array {
	return array(
		new WC_Order( 1, 'failed', false ),
		new WC_Order( 2, 'processing', true ),
		new WC_Order( 3, 'refunded', true ),
	);
}
function papelito_vendor_dashboard_order_is_paid( $order ): bool { return $order instanceof WC_Order && $order->is_paid(); }

require_once dirname( __DIR__ ) . '/includes/admin_reports.php';

$rows = papelito_admin_reports_query_simple_sales_rows(
	array(
		'from' => '2026-08-01',
		'to'   => '2026-08-31',
	)
);

if ( array_column( $rows, 'order_id' ) !== array( 2, 3 ) ) {
	echo "FAIL: exportação deve conter somente pedidos com pagamento confirmado\n";
	exit( 1 );
}

echo "OK\n";
