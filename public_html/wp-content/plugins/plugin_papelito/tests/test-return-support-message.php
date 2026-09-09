<?php
/** Regressão da mensagem canônica enviada ao vendor ao solicitar devolução. */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function get_user_meta( ...$args ) { return 'Loja Exemplo'; }
function get_userdata( ...$args ) { return null; }
function papelito_return_order_delivered_at( $order ) { return '2026-09-08 13:00:00'; }
function papelito_return_decimal_to_cents( $value ) { return (int) round( (float) $value * 100 ); }

class WP_Error {}
class WP_REST_Request {}
class WP_REST_Response {}

class Papelito_Return_Support_Test_Item {
	public function __construct( private string $name, private int $quantity, private string $total ) {}
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->quantity; }
	public function get_total() { return $this->total; }
}

class Papelito_Return_Support_Test_Date {
	public function date_i18n( $format ) { return '06/09/2026 10:30'; }
}

class Papelito_Return_Support_Test_Order {
	public function get_items( $type ) { return array( 42 => new Papelito_Return_Support_Test_Item( 'Caderno Pautado', 2, '30.00' ) ); }
	public function get_date_created() { return new Papelito_Return_Support_Test_Date(); }
	public function get_order_number() { return '14094'; }
	public function get_meta( $key, $single ) { return 29; }
}

require_once __DIR__ . '/../includes/vendor_messaging.php';

$body = papelito_messaging_return_support_body(
	new Papelito_Return_Support_Test_Order(),
	array( 'items' => array( array( 'order_item_id' => 42, 'returnable_qty' => 1 ) ) )
);
$assertions = array(
	'usa um marcador canônico e idempotente' => str_contains( $body, papelito_messaging_return_support_marker() ),
	'inclui o pedido' => str_contains( $body, 'Pedido: #14094' ),
	'inclui a loja do vendor' => str_contains( $body, 'Loja: Loja Exemplo' ),
	'inclui datas da compra e entrega' => str_contains( $body, '06/09/2026 10:30' ) && str_contains( $body, '2026-09-08 13:00:00' ),
	'inclui item quantidade e valor líquido elegível' => str_contains( $body, 'Caderno Pautado' ) && str_contains( $body, '1 de 2 unidade(s)' ) && str_contains( $body, 'R$ 15,00' ),
);
$failures = 0;
foreach ( $assertions as $label => $condition ) {
	echo ( $condition ? 'PASS: ' : 'FALHOU: ' ) . $label . "\n";
	$failures += $condition ? 0 : 1;
}
exit( $failures > 0 ? 1 : 0 );
