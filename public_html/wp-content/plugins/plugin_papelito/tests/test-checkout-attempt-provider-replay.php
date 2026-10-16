<?php
/**
 * Replay de checkout por provider e fingerprint comercial separado do pagamento.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_PAGARME_ORDER_ID_META', '_papelito_pagarme_order_id' );
define( 'PAPELITO_PAGARME_PAYMENT_METHOD_META', '_papelito_pagarme_payment_method' );
define( 'PAPELITO_PAGARME_PAYMENT_STATE_META', '_papelito_pagarme_payment_state' );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

class WC_Order {
	public array $meta = array();

	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_order_number(): string {
		return (string) $this->id;
	}

	public function get_status(): string {
		return 'pending';
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function get_subtotal(): float {
		return 100.0;
	}

	public function get_shipping_total(): float {
		return 0.0;
	}
}

class ReplayTestWpdb {
	public string $prefix = 'wp_';
	public array $rows    = array();
	private array $args   = array();

	public function prepare( string $sql, mixed ...$args ): string {
		$this->args = is_array( $args[0] ?? null ) ? $args[0] : $args;
		return $sql;
	}

	public function get_row( string $sql, string $output ): ?array {
		return $this->rows[ (int) ( $this->args[0] ?? 0 ) ] ?? null;
	}
}

$GLOBALS['wpdb'] = new ReplayTestWpdb();

function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function papelito_pricing_quote(): array { return array(); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function esc_url_raw( mixed $value ): string { return (string) $value; }
function wp_salt( string $scheme = 'auth' ): string { return 'salt-' . $scheme; }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function papelito_pagarme_order_payment_snapshot( object $order ): array {
	return array(
		'method' => (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ),
		'state'  => (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ),
	);
}

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts_repository.php';
require_once dirname( __DIR__ ) . '/includes/order_routing.php';

const REPLAY_TEST_REDIRECT = 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=abc';

$failures = 0;

function replay_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function replay_attempt_row( int $id, string $provider, string $state, string $flow ): array {
	return array(
		'id'                    => $id,
		'attempt_ref'           => str_repeat( 'a', 32 ),
		'provider'              => $provider,
		'state'                 => $state,
		'flow'                  => $flow,
		'redirect_url'          => REPLAY_TEST_REDIRECT,
		'amount_cents'          => 10000,
		'configuration_version' => 'cfg-1',
		'fallback_reason'       => '',
		'remote_payment_ids'    => '',
		'lock_version'          => 0,
	);
}

$payload = array(
	'items'    => array( array( 'product_id' => 1, 'qty' => 2 ) ),
	'address'  => array( 'zip_code' => '01001000' ),
	'payment'  => array( 'method' => 'pix' ),
	'analytics' => array( 'session_id' => 's1' ),
);
$with_card = array_merge( $payload, array( 'payment' => array( 'method' => 'credit_card', 'card_token_id' => 'tok_1' ) ) );
$other_qty = array_merge( $payload, array( 'items' => array( array( 'product_id' => 1, 'qty' => 3 ) ) ) );

replay_assert( 'fingerprint comercial ignora dados de pagamento', papelito_order_routing_commercial_request_hash( $payload ) === papelito_order_routing_commercial_request_hash( $with_card ) );
replay_assert( 'fingerprint comercial muda com os itens', papelito_order_routing_commercial_request_hash( $payload ) !== papelito_order_routing_commercial_request_hash( $other_qty ) );
replay_assert( 'hash legado continua incluindo o pagamento', papelito_order_routing_checkout_request_hash( $payload ) !== papelito_order_routing_checkout_request_hash( $with_card ) );

$mp_order = new WC_Order( 501 );
$mp_order->meta[ PAPELITO_PAYMENT_PROVIDER_META ]   = 'mercadopago';
$mp_order->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = 71;
$mp_order->meta['_papelito_authoritative_total_cents'] = 10000;
$GLOBALS['wpdb']->rows[71] = replay_attempt_row( 71, 'mercadopago', 'pending', 'redirect' );

$response = papelito_order_routing_existing_order_response( $mp_order );
replay_assert( 'preferência MP sem ID Pagar.me não é tratada como em andamento', is_array( $response ) );
replay_assert( 'replay MP devolve o mesmo pedido', 501 === ( $response['order_id'] ?? null ) );
replay_assert( 'replay MP devolve fluxo redirect com link', 'redirect' === ( $response['payment']['flow'] ?? null ) && REPLAY_TEST_REDIRECT === ( $response['payment']['redirectUrl'] ?? null ) );
replay_assert( 'replay identifica provider e tentativa', 'mercadopago' === ( $response['payment']['provider'] ?? null ) && 71 === ( $response['payment']['paymentAttemptId'] ?? null ) );

$awaiting = new WC_Order( 502 );
$awaiting->meta[ PAPELITO_PAYMENT_PROVIDER_META ]   = 'pagarme';
$awaiting->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = 72;
$GLOBALS['wpdb']->rows[72] = replay_attempt_row( 72, 'pagarme', 'awaiting_payment_input', 'requires_payment_input' );

$response = papelito_order_routing_existing_order_response( $awaiting );
replay_assert( 'aguardando dados devolve a mesma tentativa sem erro', is_array( $response ) && 'requires_payment_input' === ( $response['payment']['flow'] ?? null ) && 72 === ( $response['payment']['paymentAttemptId'] ?? null ) );

$creating = new WC_Order( 503 );
$creating->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = 73;
$creating->meta[ PAPELITO_PAYMENT_PROVIDER_META ]   = 'mercadopago';
$GLOBALS['wpdb']->rows[73] = replay_attempt_row( 73, 'mercadopago', 'creating', 'redirect' );
$response = papelito_order_routing_existing_order_response( $creating );
replay_assert( 'tentativa em criação continua 409 em andamento', is_wp_error( $response ) && 'papelito_checkout_attempt_in_progress' === $response->get_error_code() );

$unknown_attempt = new WC_Order( 504 );
$unknown_attempt->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = 74;
$unknown_attempt->meta[ PAPELITO_PAYMENT_PROVIDER_META ]   = 'mercadopago';
$GLOBALS['wpdb']->rows[74] = replay_attempt_row( 74, 'mercadopago', 'unknown', 'redirect' );
$response = papelito_order_routing_existing_order_response( $unknown_attempt );
replay_assert( 'tentativa unknown responde reconciliação, não nova cobrança', is_wp_error( $response ) && 'papelito_payment_attempt_reconciling' === $response->get_error_code() );

$awaiting->meta[ PAPELITO_CHECKOUT_ATTEMPT_COMPANY_META ]         = 9;
$awaiting->meta[ PAPELITO_CHECKOUT_ATTEMPT_HASH_META ]            = papelito_order_routing_checkout_request_hash( $payload );
$awaiting->meta[ PAPELITO_CHECKOUT_ATTEMPT_COMMERCIAL_HASH_META ] = papelito_order_routing_commercial_request_hash( $payload );
replay_assert(
	'pedido com tentativa aceita retry com pagamento diferente',
	true === papelito_order_routing_validate_existing_attempt( $awaiting, 9, papelito_order_routing_checkout_request_hash( $with_card ), papelito_order_routing_commercial_request_hash( $with_card ) )
);
$conflict = papelito_order_routing_validate_existing_attempt( $awaiting, 9, papelito_order_routing_checkout_request_hash( $other_qty ), papelito_order_routing_commercial_request_hash( $other_qty ) );
replay_assert( 'mudança comercial na mesma tentativa é conflito', is_wp_error( $conflict ) && 'papelito_checkout_attempt_payload_conflict' === $conflict->get_error_code() );

$legacy = new WC_Order( 505 );
$legacy->meta[ PAPELITO_CHECKOUT_ATTEMPT_COMPANY_META ] = 9;
$legacy->meta[ PAPELITO_CHECKOUT_ATTEMPT_HASH_META ]    = papelito_order_routing_checkout_request_hash( $payload );
$legacy_conflict = papelito_order_routing_validate_existing_attempt( $legacy, 9, papelito_order_routing_checkout_request_hash( $with_card ), papelito_order_routing_commercial_request_hash( $with_card ) );
replay_assert( 'pedido legado sem tentativa mantém hash completo', is_wp_error( $legacy_conflict ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
