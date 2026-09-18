<?php
/**
 * Standalone regression test for the administrative cancellation of a shipped order.
 *
 * A seller may not cancel an order that already left the warehouse, but the
 * administrator is the operational last resort and the account screen offers the
 * action. Before this suite the state machine rejected `enviado -> cancelado` for
 * everybody, so the admin button always answered 422 "Transicao de status invalida.".
 *
 * Usage: php tests/test-admin-cancel-shipped-order.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_email( $value ) {
	return trim( (string) $value );
}

function wc_format_datetime( $date, $format = '' ) {
	return '';
}

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function get_current_user_id() {
	return 1;
}

function papelito_pagarme_payment_state_is_paid( string $state ): bool {
	return in_array( sanitize_key( $state ), array( 'paid', 'captured' ), true );
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WC_Order {
	public $meta;
	public $notes    = array();
	public $saved    = false;
	private $wc_status;
	private $id;

	public function __construct( int $id, array $meta, string $wc_status ) {
		$this->id        = $id;
		$this->meta      = $meta;
		$this->wc_status = $wc_status;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function get_status() {
		return $this->wc_status;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function save() {
		$this->saved = true;
	}

	public function get_total() {
		return 12.76;
	}

	public function get_customer_id() {
		return 2158;
	}

	public function get_items( $type = 'line_item' ) {
		return array();
	}

	public function get_date_created() {
		return null;
	}

	public function get_date_paid() {
		return null;
	}

	public function get_order_number() {
		return (string) $this->id;
	}

	public function get_subtotal() {
		return 12.76;
	}

	public function get_shipping_total() {
		return 0.0;
	}

	/** Getters de leitura que o mapeamento toca e o teste não avalia. */
	public function __call( $name, $arguments ) {
		return '';
	}
}

$GLOBALS['papelito_test_order'] = null;

function wc_get_order( $order_id ) {
	return $GLOBALS['papelito_test_order'];
}

require __DIR__ . '/../includes/vendor_dashboard.php';

function papelito_make_shipped_order( string $wc_status = 'processing' ): WC_Order {
	return new WC_Order(
		11886,
		array(
			'_papelito_vendor_status' => 'enviado',
			'_papelito_vendor_id'     => '10',
		),
		$wc_status
	);
}

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: the state machine separates seller from administrator\n";
papelito_assert( 'seller has no way out of enviado', array(), papelito_vendor_dashboard_next_statuses( 'enviado' ) );
papelito_assert( 'administrator may cancel an enviado order', array( 'cancelado' ), papelito_vendor_dashboard_next_statuses( 'enviado', true ) );
papelito_assert( 'entregue stays closed even for the administrator', array(), papelito_vendor_dashboard_next_statuses( 'entregue', true ) );
papelito_assert( 'administrator does not gain new picking transitions', array( 'em_separacao', 'cancelado' ), papelito_vendor_dashboard_next_statuses( 'aguardando_envio', true ) );

echo "Scenario 1b: the screen can ask whether cancelling is possible at all\n";
papelito_assert( 'seller cannot cancel enviado', false, papelito_vendor_dashboard_can_cancel( 'enviado' ) );
papelito_assert( 'administrator can cancel enviado', true, papelito_vendor_dashboard_can_cancel( 'enviado', true ) );
papelito_assert( 'nobody cancels entregue', false, papelito_vendor_dashboard_can_cancel( 'entregue', true ) );
papelito_assert( 'nobody cancels an already cancelled order', false, papelito_vendor_dashboard_can_cancel( 'cancelado', true ) );
papelito_assert( 'aguardando_envio is cancellable by the seller', true, papelito_vendor_dashboard_can_cancel( 'aguardando_envio' ) );

echo "Scenario 2: the admin cancel endpoint path no longer answers 422\n";
$GLOBALS['papelito_test_order'] = papelito_make_shipped_order();
papelito_assert(
	'the fixture really is read as enviado, not as an unpaid order',
	'enviado',
	papelito_vendor_dashboard_order_status( $GLOBALS['papelito_test_order'] )
);
$result                         = papelito_vendor_dashboard_update_order_status( 11886, 10, 'cancelado', 'Venda de teste.', true );
papelito_assert( 'administrative cancel is not rejected as an invalid transition', false, is_wp_error( $result ) );
papelito_assert( 'order was moved to cancelado', 'cancelado', $GLOBALS['papelito_test_order']->get_meta( '_papelito_vendor_status', true ) );
papelito_assert( 'cancellation reason was persisted', 'Venda de teste.', $GLOBALS['papelito_test_order']->get_meta( '_papelito_vendor_cancel_reason', true ) );
papelito_assert( 'order was saved', true, $GLOBALS['papelito_test_order']->saved );

echo "Scenario 3: the seller still cannot cancel the same shipped order\n";
$GLOBALS['papelito_test_order'] = papelito_make_shipped_order();
$seller_result                  = papelito_vendor_dashboard_update_order_status( 11886, 10, 'cancelado', 'Venda de teste.' );
papelito_assert( 'seller cancel is refused', true, is_wp_error( $seller_result ) );
papelito_assert(
	'seller refusal keeps the invalid transition code',
	'papelito_vendor_invalid_status_transition',
	is_wp_error( $seller_result ) ? $seller_result->get_error_code() : ''
);
papelito_assert( 'seller refusal leaves the order untouched', 'enviado', $GLOBALS['papelito_test_order']->get_meta( '_papelito_vendor_status', true ) );

echo "Scenario 4: a reason remains mandatory for the administrator\n";
$GLOBALS['papelito_test_order'] = papelito_make_shipped_order();
$no_reason                      = papelito_vendor_dashboard_update_order_status( 11886, 10, 'cancelado', '', true );
papelito_assert(
	'administrative cancel without a reason is refused',
	'papelito_vendor_cancel_reason_required',
	is_wp_error( $no_reason ) ? $no_reason->get_error_code() : ''
);

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
