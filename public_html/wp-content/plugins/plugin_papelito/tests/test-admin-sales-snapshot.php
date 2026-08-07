<?php
/**
 * Regression test for the canonical admin sales snapshot.
 *
 * Usage: php tests/test-admin-sales-snapshot.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );

final class WP_REST_Server {
	public const READABLE = 'GET';
}

final class WC_DateTime {
	public function __construct( private string $value ) {}

	public function date_i18n( string $format ): string {
		return gmdate( $format, strtotime( $this->value ) );
	}
}

final class Snapshot_Item {
	public function __construct(
		private string $name,
		private int $quantity,
		private float $total
	) {}

	public function get_name(): string { return $this->name; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_total(): float { return $this->total; }
}

final class WC_Order {
	/** @param array<int, Snapshot_Item> $items */
	public function __construct(
		private string $status,
		private bool $paid,
		private float $total,
		private float $discount,
		private float $shipping,
		private float $taxes,
		private float $refunded,
		private string $created_at,
		private string $payment_method,
		private array $items
	) {}

	public function get_status(): string { return $this->status; }
	public function is_paid(): bool { return $this->paid; }
	public function get_total(): float { return $this->total; }
	public function get_discount_total(): float { return $this->discount; }
	public function get_shipping_total(): float { return $this->shipping; }
	public function get_total_tax(): float { return $this->taxes; }
	public function get_total_refunded(): float { return $this->refunded; }
	public function get_date_created(): WC_DateTime { return new WC_DateTime( $this->created_at ); }
	public function get_payment_method_title(): string { return $this->payment_method; }
	public function get_items( string $type ): array { return $this->items; }
}

function add_action( $hook, $callback ): void {}
function current_user_can( $capability ): bool { return true; }
function sanitize_key( string $value ): string { return strtolower( $value ); }
function wc_get_order_statuses(): array { return array( 'wc-failed' => 'Falhou', 'wc-processing' => 'Processando', 'wc-refunded' => 'Reembolsado' ); }
function wc_get_orders( $args ): array {
	return array(
		new WC_Order( 'failed', false, 99.0, 0.0, 0.0, 0.0, 0.0, '2026-08-04 10:00:00', 'Pix', array( new Snapshot_Item( 'Produto falho', 1, 99.0 ) ) ),
		new WC_Order( 'processing', true, 105.0, 10.0, 5.0, 0.0, 0.0, '2026-08-04 11:00:00', 'Cartão', array( new Snapshot_Item( 'Produto pago', 2, 100.0 ) ) ),
		new WC_Order( 'refunded', true, 30.0, 0.0, 0.0, 0.0, 30.0, '2026-08-05 10:00:00', 'Cartão', array( new Snapshot_Item( 'Produto reembolsado', 1, 30.0 ) ) ),
	);
}
function papelito_vendor_dashboard_order_is_paid( $order ): bool { return $order instanceof WC_Order && $order->is_paid(); }

require_once dirname( __DIR__ ) . '/includes/admin_reports.php';

$snapshot = papelito_admin_reports_get_sales_snapshot(
	array(
		'from'     => '2026-08-01',
		'to'       => '2026-08-31',
		'interval' => 'day',
	)
);

if (
	2 !== $snapshot['orders'] ||
	135.0 !== $snapshot['grossRevenue'] ||
	100.0 !== $snapshot['netRevenue'] ||
	3 !== $snapshot['itemsSold'] ||
	2 !== $snapshot['orderVolumeByInterval']['2026-08-04'] ||
	105.0 !== $snapshot['revenueByInterval']['2026-08-04']
) {
	echo "FAIL: snapshot deve separar pedidos criados de vendas confirmadas\n";
	exit( 1 );
}

echo "OK\n";
