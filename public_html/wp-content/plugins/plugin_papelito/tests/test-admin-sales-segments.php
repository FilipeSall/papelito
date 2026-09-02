<?php
/**
 * Regression test for the sales snapshot segments and previous-window comparison.
 *
 * Usage: php tests/test-admin-sales-segments.php
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

final class Segment_Item {
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
	/** @param array<int, Segment_Item> $items */
	public function __construct(
		private string $status,
		private bool $paid,
		private float $total,
		private float $discount,
		private float $refunded,
		private array $items
	) {}

	public function get_status(): string { return $this->status; }
	public function is_paid(): bool { return $this->paid; }
	public function get_total(): float { return $this->total; }
	public function get_discount_total(): float { return $this->discount; }
	public function get_shipping_total(): float { return 0.0; }
	public function get_total_tax(): float { return 0.0; }
	public function get_total_refunded(): float { return $this->refunded; }
	public function get_date_created(): WC_DateTime { return new WC_DateTime( '2026-08-04 10:00:00' ); }
	public function get_payment_method_title(): string { return 'Pix'; }
	public function get_items( string $type ): array { return $this->items; }
}

function add_action( $hook, $callback ): void {}
function current_user_can( $capability ): bool { return true; }
function sanitize_key( string $value ): string { return strtolower( $value ); }
function wc_get_order_statuses(): array { return array( 'wc-cancelled' => 'Cancelado', 'wc-processing' => 'Processando', 'wc-refunded' => 'Reembolsado' ); }
function wc_get_orders( $args ): array {
	return array(
		// Paga, com desconto monetario real.
		new WC_Order( 'processing', true, 105.0, 10.0, 0.0, array( new Segment_Item( 'Seda com desconto', 1, 105.0 ) ) ),
		// Paga, sem desconto.
		new WC_Order( 'processing', true, 200.0, 0.0, 0.0, array( new Segment_Item( 'Seda cheia', 1, 200.0 ) ) ),
		// Reembolsada.
		new WC_Order( 'refunded', true, 30.0, 0.0, 30.0, array( new Segment_Item( 'Seda devolvida', 1, 30.0 ) ) ),
		// Cancelada: nao conta como venda paga, mas conta no segmento de cancelamentos.
		new WC_Order( 'cancelled', false, 70.0, 0.0, 0.0, array( new Segment_Item( 'Seda cancelada', 1, 70.0 ) ) ),
	);
}
function papelito_vendor_dashboard_order_is_paid( $order ): bool { return $order instanceof WC_Order && $order->is_paid(); }

require_once dirname( __DIR__ ) . '/includes/admin_reports.php';

function segment_snapshot( string $segment ): array {
	return papelito_admin_reports_get_sales_snapshot(
		array(
			'from'     => '2026-08-01',
			'to'       => '2026-08-31',
			'interval' => 'day',
			'segment'  => $segment,
		)
	);
}

$all = segment_snapshot( 'all' );

if ( 3 !== $all['orders'] || 335.0 !== $all['grossRevenue'] ) {
	echo "FAIL: segmento 'all' deve somar apenas vendas pagas (105 + 200 + 30)\n";
	exit( 1 );
}

$discounted = segment_snapshot( 'discounted' );

if ( 1 !== $discounted['orders'] || 105.0 !== $discounted['grossRevenue'] ) {
	echo "FAIL: segmento 'discounted' deve usar discount_total > 0, nao existencia de cupom\n";
	exit( 1 );
}

if ( array_column( $discounted['leaderboard'], 'label' ) !== array( 'seda com desconto' ) ) {
	echo "FAIL: o segmento precisa recortar tambem o leaderboard\n";
	exit( 1 );
}

$refunded = segment_snapshot( 'refunded' );

if ( 2 !== $refunded['orders'] || 100.0 !== $refunded['grossRevenue'] ) {
	echo "FAIL: segmento 'refunded' deve reunir status refunded e cancelled (30 + 70)\n";
	exit( 1 );
}

if ( 'all' !== $all['segment'] || 'refunded' !== $refunded['segment'] ) {
	echo "FAIL: o snapshot deve devolver o segmento aplicado\n";
	exit( 1 );
}

if ( ! array_key_exists( 'previousGrossRevenue', $all ) || null === $all['previousGrossRevenue'] ) {
	echo "FAIL: o snapshot deve calcular a receita da janela anterior\n";
	exit( 1 );
}

// O stub devolve os mesmos pedidos para qualquer janela: a comparacao precisa
// respeitar o segmento, entao a janela anterior de 'discounted' vale 105, nao 335.
if ( 105.0 !== segment_snapshot( 'discounted' )['previousGrossRevenue'] ) {
	echo "FAIL: a janela anterior precisa respeitar o mesmo segmento\n";
	exit( 1 );
}

$window = papelito_admin_reports_previous_window( '2026-08-01', '2026-08-31' );

if ( array( 'from' => '2026-07-01', 'to' => '2026-07-31' ) !== $window ) {
	echo "FAIL: a janela anterior deve ter a mesma duracao e terminar na vespera\n";
	exit( 1 );
}

echo "OK\n";
