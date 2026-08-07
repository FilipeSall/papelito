<?php
/**
 * Regression test for sales shown on the administrative user screen.
 *
 * Usage: php tests/test-admin-user-sales-count.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );

final class Admin_User_Order {
	public function __construct( public int $id, public bool $paid, private string $status ) {}

	public function get_status(): string { return $this->status; }
}

function add_action( $hook, $callback ): void {}
function sanitize_key( string $value ): string { return strtolower( $value ); }
function papelito_vendor_dashboard_orders_for_vendor( int $vendor_id ): array {
	return array(
		new Admin_User_Order( 1, false, 'failed' ),
		new Admin_User_Order( 2, true, 'processing' ),
		new Admin_User_Order( 3, false, 'cancelled' ),
	);
}
function papelito_vendor_dashboard_order_is_paid( $order ): bool {
	return $order instanceof Admin_User_Order && $order->paid;
}

require_once dirname( __DIR__ ) . '/includes/admin_users.php';

$sales = papelito_admin_users_sales_orders( 7, 10 );
$cancelled = papelito_admin_users_cancelled_sales_orders(
	papelito_vendor_dashboard_orders_for_vendor( 7 ),
	10
);

if ( count( $sales ) !== 1 || papelito_admin_users_sales_orders_count( 7 ) !== 1 ) {
	echo "FAIL: vendas administrativas devem excluir pedidos sem pagamento confirmado\n";
	exit( 1 );
}

if ( 2 !== count( $cancelled ) ) {
	echo "FAIL: cancelamentos recentes devem preservar pedidos falhos e cancelados\n";
	exit( 1 );
}

echo "OK\n";
