<?php
/**
 * Standalone regression test for the vendor report scoping.
 *
 * Garante que o export do vendor sai somente com pedidos pagos daquele vendor
 * dentro do periodo, e que o export de clientes nao repete a mesma conta quando
 * ela compra varias vezes.
 *
 * Usage: php tests/test-vendor-reports-scope.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'PAPELITO_REST_NAMESPACE', 'papelito/v1' );

final class WP_REST_Server {
	public const READABLE = 'GET';
}

final class Vendor_Date {
	public function __construct( private string $value ) {}

	public function date_i18n( string $format ): string {
		return $this->value;
	}
}

final class WC_Order {
	public function __construct(
		private int $id,
		private bool $paid,
		private bool $in_period,
		private int $customer_id,
		private float $total,
		private string $name = 'Cliente'
	) {}

	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return (string) $this->id; }
	public function get_date_created(): Vendor_Date { return new Vendor_Date( '2026-08-04 10:00:00' ); }
	public function get_status(): string { return 'processing'; }
	public function get_customer_id(): int { return $this->customer_id; }
	public function get_billing_phone(): string { return '61999990000'; }
	public function get_billing_postcode(): string { return '71200030'; }
	public function get_billing_city(): string { return 'Brasília'; }
	public function get_billing_state(): string { return 'DF'; }
	public function get_payment_method_title(): string { return 'Pix'; }
	public function get_total(): float { return $this->total; }
	public function is_paid(): bool { return $this->paid; }
	public function is_in_period(): bool { return $this->in_period; }
	public function customer_label(): string { return $this->name; }
}

function add_action( ...$args ): void {}
function register_rest_route( ...$args ): void {}
function wp_date( string $format ): string { return '2026-09-02'; }
function wc_get_order_status_name( $status ): string { return 'Processando'; }
function papelito_admin_reports_order_customer_name( $order ): string { return $order->customer_label(); }
function papelito_vendor_dashboard_in_period( $order, $from, $to ): bool { return $order->is_in_period(); }
function papelito_vendor_dashboard_order_is_paid( $order ): bool { return $order->is_paid(); }

$GLOBALS['vendor_orders'] = array(
	10 => array(
		// Paga, no periodo, do cliente 2271.
		new WC_Order( 14094, true, true, 2271, 490.0, 'Marcos Stub de Oliveira' ),
		// Mesma conta comprando de novo: nao pode duplicar no export de clientes.
		new WC_Order( 14088, true, true, 2271, 110.27, 'Marcos Stub de Oliveira' ),
		// Fora do periodo.
		new WC_Order( 13000, true, false, 2271, 50.0 ),
		// Sem pagamento confirmado.
		new WC_Order( 14093, false, true, 2271, 99.0 ),
		// Convidado: sem conta, entra em vendas mas nao em clientes.
		new WC_Order( 14087, true, true, 0, 346.35, 'Convidado da Loja' ),
	),
	// Pedido de outro vendor jamais deve aparecer.
	11 => array( new WC_Order( 99999, true, true, 4242, 1000.0, 'Cliente de Outro Vendor' ) ),
);

function papelito_vendor_dashboard_orders_for_vendor( int $vendor_id ): array {
	return $GLOBALS['vendor_orders'][ $vendor_id ] ?? array();
}

function get_userdata( int $user_id ) {
	if ( 2271 !== $user_id ) {
		return false;
	}

	return (object) array(
		'user_login' => 'user1@test.com',
		'user_email' => 'user1@test.com',
	);
}

require_once dirname( __DIR__ ) . '/includes/vendor_reports.php';

$filters = array( 'from' => '2026-08-01', 'to' => '2026-08-31', 'format' => 'xlsx' );
$failures = 0;

function check( string $label, bool $passed ): void {
	global $failures;

	if ( ! $passed ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

$sales = papelito_vendor_reports_sales_rows( 10, $filters );

check(
	'export de vendas traz somente pedidos pagos do vendor no periodo',
	array_column( $sales, 'order_id' ) === array( 14094, 14088, 14087 )
);

check(
	'export de vendas resolve o nome do cliente',
	array_column( $sales, 'customer_name' ) === array(
		'Marcos Stub de Oliveira',
		'Marcos Stub de Oliveira',
		'Convidado da Loja',
	)
);

$customers = papelito_vendor_reports_customers_rows( 10, $filters );

check(
	'export de clientes nao repete a mesma conta',
	array_column( $customers, 'user_id' ) === array( 2271 )
);

check(
	'export de clientes ignora pedido de convidado',
	1 === count( $customers )
);

check(
	'export de clientes carrega somente coluna de usuario',
	array_keys( $customers[0] ) === array( 'user_id', 'username', 'email', 'phone', 'postcode', 'city', 'state' )
);

check(
	'pedido de outro vendor nao vaza',
	array() === papelito_vendor_reports_sales_rows( 12, $filters )
);

$other = papelito_vendor_reports_sales_rows( 11, $filters );

check(
	'cada vendor ve apenas a propria carteira',
	array_column( $other, 'order_id' ) === array( 99999 )
);

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
