<?php
/**
 * Standalone regression test for the Pagar.me local webhook simulator.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT', 'aguardando_pagamento' );
define( 'PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT', 'aguardando_envio' );
define( 'PAPELITO_VENDOR_STATUS_CANCELLED', 'cancelado' );

$papelito_test_env              = array();
$papelito_test_orders           = array();
$papelito_test_current_user_can = false;
$papelito_test_stock_releases   = 0;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function set_transient( ...$args ) {}
function get_transient( ...$args ) { return false; }
function wp_next_scheduled( ...$args ) { return false; }
function wp_schedule_event( ...$args ) {}
function wp_json_encode( mixed $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function esc_url_raw( mixed $value ) { return trim( (string) $value ); }
function absint( mixed $value ) { return abs( (int) $value ); }

function sanitize_key( mixed $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}

function sanitize_text_field( mixed $value ) {
	return trim( (string) $value );
}

function papelito_env( string $key, $default = null ) {
	global $papelito_test_env;
	return array_key_exists( $key, $papelito_test_env ) ? $papelito_test_env[ $key ] : $default;
}

function papelito_env_bool( string $key, bool $default = false ): bool {
	$value = papelito_env( $key, null );
	if ( null === $value ) {
		return $default;
	}
	$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	return null === $parsed ? $default : $parsed;
}

function current_user_can( string $capability ): bool {
	global $papelito_test_current_user_can;
	return $papelito_test_current_user_can;
}

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;

	public function __construct( string $code, string $message, mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

class WP_REST_Request {
	public function __construct( private array $params = array(), private array $headers = array() ) {}

	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function get_header( string $key ) {
		return $this->headers[ strtolower( $key ) ] ?? '';
	}
}

class WP_REST_Response {
	public function __construct( private mixed $data, private int $status = 200 ) {}

	public function get_data() {
		return $this->data;
	}

	public function get_status(): int {
		return $this->status;
	}
}

class WC_Order {
	public array $meta = array();
	public string $status = 'pending';
	public bool $payment_completed = false;
	public array $notes = array();

	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function save() {}

	public function get_status(): string {
		return $this->status;
	}

	public function update_status( $status ) {
		$this->status = $status;
	}

	public function payment_complete() {
		$this->payment_completed = true;
	}

	public function add_order_note( string $note ) {
		$this->notes[] = $note;
	}
}

function wc_get_order( int $order_id ) {
	global $papelito_test_orders;
	return $papelito_test_orders[ $order_id ] ?? null;
}

function wc_get_orders( array $args ): array {
	global $papelito_test_orders;
	$meta_key   = (string) ( $args['meta_key'] ?? '' );
	$meta_value = (string) ( $args['meta_value'] ?? '' );

	return array_values(
		array_filter(
			$papelito_test_orders,
			static fn( WC_Order $order ): bool => (string) $order->get_meta( $meta_key, true ) === $meta_value
		)
	);
}

function papelito_order_routing_order_lines( object $order ): array {
	return array(
		array(
			'vendor_id'  => 10,
			'product_id' => 20,
			'qty'        => 1,
		),
	);
}

function papelito_adjust_vendor_stock( int $vendor_id, int $product_id, int $qty, string $reason ) {
	global $papelito_test_stock_releases;
	if ( $qty > 0 ) {
		++$papelito_test_stock_releases;
	}
	return true;
}

require __DIR__ . '/../includes/pagarme_client.php';
require __DIR__ . '/../includes/pagarme_payments.php';
require __DIR__ . '/../includes/pagarme_webhook.php';
require __DIR__ . '/../includes/pagarme_simulator.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: simulator is blocked by default\n";
$papelito_test_env = array( 'WP_ENVIRONMENT_TYPE' => 'local' );
$permission        = papelito_pagarme_simulator_permission( new WP_REST_Request() );
papelito_assert_same( 'disabled simulator returns not found', 404, $permission->get_error_data()['status'] ?? null );

echo "Scenario 2: simulator is blocked in production even with the flag\n";
$papelito_test_env = array(
	'WP_ENVIRONMENT_TYPE'                     => 'production',
	'PAPELITO_PAGARME_SIMULATION_ENABLED'    => 'true',
	'PAPELITO_PAGARME_SIMULATION_TOKEN'      => 'dev-token',
);
$permission = papelito_pagarme_simulator_permission( new WP_REST_Request( array(), array( 'x-papelito-dev-token' => 'dev-token' ) ) );
papelito_assert_same( 'production simulator returns not found', 404, $permission->get_error_data()['status'] ?? null );

echo "Scenario 3: token-authenticated simulator can mark an order paid\n";
$papelito_test_env = array(
	'WP_ENVIRONMENT_TYPE'                  => 'local',
	'PAPELITO_PAGARME_SIMULATION_ENABLED' => 'true',
	'PAPELITO_PAGARME_SIMULATION_TOKEN'   => 'dev-token',
);
$permission = papelito_pagarme_simulator_permission( new WP_REST_Request( array(), array( 'x-papelito-dev-token' => 'dev-token' ) ) );
papelito_assert_same( 'token authorizes simulator', true, $permission );

$order = new WC_Order( 501 );
$order->meta[ PAPELITO_PAGARME_PAYMENT_METHOD_META ] = 'boleto';
$order->meta[ PAPELITO_STOCK_RESERVED_META ]         = '1';
$order->meta['_papelito_vendor_status']              = PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT;
$papelito_test_orders                                = array( 501 => $order );
$response = papelito_pagarme_handle_simulate_webhook(
	new WP_REST_Request(
		array(
			'order_id' => 501,
			'scenario' => 'paid',
		),
		array( 'x-papelito-dev-token' => 'dev-token' )
	)
);
papelito_assert_same( 'paid simulation returns 200', 200, $response->get_status() );
papelito_assert_same( 'paid simulation stores paid state', 'paid', $order->meta[ PAPELITO_PAGARME_PAYMENT_STATE_META ] ?? null );
papelito_assert_same( 'paid simulation completes payment', true, $order->payment_completed );
papelito_assert_same( 'paid simulation does not release stock', 0, $papelito_test_stock_releases );

echo "Scenario 4: repeated expired simulation releases stock only once\n";
$papelito_test_stock_releases = 0;
$order                        = new WC_Order( 502 );
$order->meta[ PAPELITO_PAGARME_PAYMENT_METHOD_META ] = 'boleto';
$order->meta[ PAPELITO_STOCK_RESERVED_META ]         = '1';
$order->meta['_papelito_vendor_status']              = PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT;
$papelito_test_orders                                = array( 502 => $order );
$response = papelito_pagarme_handle_simulate_webhook(
	new WP_REST_Request(
		array(
			'order_id'     => 502,
			'scenario'     => 'expired',
			'repeat_count' => 2,
		),
		array( 'x-papelito-dev-token' => 'dev-token' )
	)
);
papelito_assert_same( 'expired simulation returns 200', 200, $response->get_status() );
papelito_assert_same( 'expired simulation stores expired state', 'expired', $order->meta[ PAPELITO_PAGARME_PAYMENT_STATE_META ] ?? null );
papelito_assert_same( 'expired simulation fails order', 'failed', $order->status );
papelito_assert_same( 'duplicate expired event releases stock once', 1, $papelito_test_stock_releases );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
