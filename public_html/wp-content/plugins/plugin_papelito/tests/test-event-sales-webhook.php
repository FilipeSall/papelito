<?php
/**
 * Webhook `type=order` da Point e ramo da Point no cron de reconciliação.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

class WP_REST_Response {
	public function __construct( public mixed $data = null, public int $status = 200 ) {}

	public function get_status(): int {
		return $this->status;
	}
}

class WP_REST_Request {
	public function __construct( private array $query = array(), private array $headers = array(), private array $json = array() ) {}

	public function get_query_params(): array {
		return $this->query;
	}

	public function get_header( string $name ): ?string {
		return $this->headers[ $name ] ?? null;
	}

	public function get_json_params(): array {
		return $this->json;
	}
}

class Event_Webhook_Order {
	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_order_number(): string {
		return (string) $this->id;
	}
}

const EVENT_WEBHOOK_TEST_SECRET    = 'segredo-webhook-app';
const EVENT_WEBHOOK_TEST_REMOTE    = 'ORD01EVENTOWEBHOOK';
const EVENT_WEBHOOK_TEST_COLLECTOR = '1813664612';
const EVENT_WEBHOOK_TEST_TOKEN     = 'APP_USR-1-1-abc-1813664612';

$GLOBALS['ewh_env']    = array(
	'MERCADOPAGO_CLIENT_ID'                   => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET'               => 'secret',
	'MERCADOPAGO_WEBHOOK_SECRET'              => EVENT_WEBHOOK_TEST_SECRET,
	'MERCADOPAGO_ENVIRONMENT'                 => 'test',
	'PAPELITO_MERCADOPAGO_POINT_ACCESS_TOKEN' => EVENT_WEBHOOK_TEST_TOKEN,
	'PAPELITO_EVENT_SALES_TERMINAL_ID'        => 'NEWLAND_N950__SBX0000001',
	'PAPELITO_EVENT_SALES_VENDOR_ID'          => '2163',
);
$GLOBALS['ewh_remote'] = array();
$GLOBALS['ewh_calls']  = array();
$GLOBALS['ewh_paid']   = array();
$GLOBALS['ewh_ledger'] = array();
$GLOBALS['ewh_trans']  = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( mixed $value ): string { return (string) $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_get_environment_type(): string { return 'local'; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['ewh_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function papelito_rate_limit( mixed ...$args ): bool { return true; }
function papelito_rate_limit_identity(): string { return 'ip:test'; }
function get_transient( string $key ): mixed { return EVENT_WEBHOOK_TEST_COLLECTOR; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { return true; }
function wc_get_order( mixed $id ): Event_Webhook_Order { return new Event_Webhook_Order( (int) $id ); }
function papelito_payment_order_mark_paid( object $order, string $provider ): bool { $GLOBALS['ewh_paid'][] = $order->get_id(); return true; }
function papelito_payment_order_mark_unpaid( object $order, string $reason ): bool { return true; }
function papelito_event_sales_settlement_record( array $row ): bool { $GLOBALS['ewh_ledger'][] = $row; return true; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' !== $hook ) {
		return $value;
	}
	$GLOBALS['ewh_calls'][] = $args[0] . ' ' . parse_url( $args[1], PHP_URL_PATH );
	$path                   = (string) parse_url( $args[1], PHP_URL_PATH );
	if ( '/v1/orders/' . EVENT_WEBHOOK_TEST_REMOTE === $path && array() !== $GLOBALS['ewh_remote'] ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $GLOBALS['ewh_remote'] ) );
	}
	if ( '/v1/orders' === $path ) {
		return array( 'response' => array( 'code' => 201 ), 'body' => json_encode( $GLOBALS['ewh_remote'] ) );
	}
	return array( 'response' => array( 'code' => 503 ), 'body' => '{}' );
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once __DIR__ . '/support/payment_attempts_memory.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_point.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_config.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_reconcile.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_webhook.php';
require_once dirname( __DIR__ ) . '/includes/payment_reconciliation.php';

$failures = 0;

function ewh_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

function ewh_reset(): void {
	$GLOBALS['memory_attempts'] = array();
	$GLOBALS['memory_locks']    = array();
	$GLOBALS['ewh_remote']      = array();
	$GLOBALS['ewh_calls']       = array();
	$GLOBALS['ewh_paid']        = array();
	$GLOBALS['ewh_ledger']      = array();
	$GLOBALS['ewh_trans']       = array();
}

function ewh_attempt( string $state, string $remote_id ): array {
	$attempt = papelito_payment_attempt_create(
		array(
			'order_id'       => 501,
			'vendor_id'      => 2163,
			'provider'       => 'mercadopago_point',
			'environment'    => 'test',
			'account_ref'    => EVENT_WEBHOOK_TEST_COLLECTOR,
			'amount_cents'   => 123456,
			'flow'           => 'terminal',
			'payment_method' => 'card',
		)
	);
	$GLOBALS['memory_attempts'][ $attempt['id'] ] = array_merge( $attempt, array( 'state' => $state, 'remote_id' => $remote_id, 'external_reference' => 'ppl-501-' . $attempt['id'] ) );

	return $GLOBALS['memory_attempts'][ $attempt['id'] ];
}

function ewh_remote( string $status, string $reference = 'ppl-501-1' ): array {
	return array(
		'id'                 => EVENT_WEBHOOK_TEST_REMOTE,
		'status'             => $status,
		'external_reference' => $reference,
		'total_amount'       => '1234.56',
		'user_id'            => (int) EVENT_WEBHOOK_TEST_COLLECTOR,
		'transactions'       => array( 'payments' => array( array( 'id' => 'PAY01', 'amount' => '1234.56' ) ) ),
	);
}

function ewh_request( bool $signed, string $data_id = EVENT_WEBHOOK_TEST_REMOTE ): WP_REST_Request {
	$ts       = '1731300000';
	$rid      = 'req-1';
	$manifest = 'id:' . strtolower( $data_id ) . ';request-id:' . $rid . ';ts:' . $ts . ';';
	$v1       = $signed ? hash_hmac( 'sha256', $manifest, EVENT_WEBHOOK_TEST_SECRET ) : str_repeat( '0', 64 );

	return new WP_REST_Request(
		array(
			'type'    => 'order',
			'data_id' => $data_id,
		),
		array(
			'x-signature'  => "ts={$ts},v1={$v1}",
			'x-request-id' => $rid,
		),
		array( 'type' => 'order' )
	);
}

function test_order_webhook_invalid_signature_401(): void {
	ewh_reset();
	ewh_attempt( 'pending', EVENT_WEBHOOK_TEST_REMOTE );
	$GLOBALS['ewh_remote'] = ewh_remote( 'processed' );
	$response              = papelito_mercadopago_webhook_handle( ewh_request( false ) );
	ewh_assert( 'assinatura inválida é 401', 401 === $response->get_status() );
	ewh_assert( 'assinatura inválida não consulta nem paga', array() === $GLOBALS['ewh_calls'] && array() === $GLOBALS['ewh_paid'] );
}

function test_order_webhook_before_remote_id_is_saved(): void {
	ewh_reset();
	$attempt               = ewh_attempt( 'creating', '' );
	$GLOBALS['ewh_remote'] = ewh_remote( 'processed' );
	$early                 = papelito_mercadopago_webhook_handle( ewh_request( true ) );
	ewh_assert( 'notificação antes do id remoto é 500 para reenvio', 500 === $early->get_status() && array() === $GLOBALS['ewh_paid'] );

	$GLOBALS['memory_attempts'][ $attempt['id'] ]['state']     = 'pending';
	$GLOBALS['memory_attempts'][ $attempt['id'] ]['remote_id'] = EVENT_WEBHOOK_TEST_REMOTE;
	$later                                                     = papelito_mercadopago_webhook_handle( ewh_request( true ) );
	ewh_assert( 'reenvio depois do id remoto paga', 200 === $later->get_status() && array( 501 ) === $GLOBALS['ewh_paid'] );
	ewh_assert( 'tentativa paga', 'paid' === $GLOBALS['memory_attempts'][ $attempt['id'] ]['state'] );
	ewh_assert( 'consulta com o token da Point', in_array( 'GET /v1/orders/' . EVENT_WEBHOOK_TEST_REMOTE, $GLOBALS['ewh_calls'], true ) );
	ewh_assert( 'grava o repasse', 1 === count( $GLOBALS['ewh_ledger'] ) );
}

function test_order_webhook_foreign_reference_200(): void {
	ewh_reset();
	ewh_attempt( 'pending', EVENT_WEBHOOK_TEST_REMOTE );
	$GLOBALS['ewh_remote'] = ewh_remote( 'processed', 'pedido-de-outro-sistema' );
	$response              = papelito_mercadopago_webhook_handle( ewh_request( true ) );
	ewh_assert( 'referência estranha é 200 sem efeito', 200 === $response->get_status() && array() === $GLOBALS['ewh_paid'] );

	$GLOBALS['ewh_remote'] = ewh_remote( 'processed', 'ppl-501-99' );
	$missing               = papelito_mercadopago_webhook_handle( ewh_request( true ) );
	ewh_assert( 'tentativa inexistente é 200 sem efeito', 200 === $missing->get_status() && array() === $GLOBALS['ewh_paid'] );

	ewh_reset();
	$other                                       = ewh_attempt( 'pending', EVENT_WEBHOOK_TEST_REMOTE );
	$GLOBALS['memory_attempts'][ $other['id'] ]['provider'] = 'mercadopago';
	$GLOBALS['ewh_remote']                       = ewh_remote( 'processed' );
	$foreign                                     = papelito_mercadopago_webhook_handle( ewh_request( true ) );
	ewh_assert( 'tentativa de outro provider é 200 sem efeito', 200 === $foreign->get_status() && array() === $GLOBALS['ewh_paid'] );
}

function test_order_webhook_unavailable_asks_resend(): void {
	ewh_reset();
	ewh_attempt( 'pending', EVENT_WEBHOOK_TEST_REMOTE );
	$response = papelito_mercadopago_webhook_handle( ewh_request( true ) );
	ewh_assert( 'Mercado Pago fora do ar é 500', 500 === $response->get_status() );
}

function test_cron_dispatches_point_provider(): void {
	ewh_reset();
	$attempt               = ewh_attempt( 'pending', EVENT_WEBHOOK_TEST_REMOTE );
	$GLOBALS['ewh_remote'] = ewh_remote( 'processed' );
	$outcome               = papelito_payment_reconcile_attempt_locked( $GLOBALS['memory_attempts'][ $attempt['id'] ] );
	ewh_assert( 'cron manda a Point para a reconciliação do evento', array() === $GLOBALS['ewh_trans'] && 'paid' === $outcome );
	ewh_assert( 'cron paga pela Point', array( 501 ) === $GLOBALS['ewh_paid'] );

	ewh_reset();
	$stale                                              = ewh_attempt( 'unknown', '' );
	$GLOBALS['memory_attempts'][ $stale['id'] ]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 3600 );
	$GLOBALS['ewh_remote']                              = ewh_remote( 'at_terminal' );
	papelito_payment_reconcile_attempt_locked( $GLOBALS['memory_attempts'][ $stale['id'] ] );
	ewh_assert( 'cobrança perdida há uma hora não é reenviada à máquina', ! in_array( 'POST /v1/orders', $GLOBALS['ewh_calls'], true ) );
	ewh_assert( 'cobrança perdida vira incidente', 'unresolved' === $GLOBALS['memory_attempts'][ $stale['id'] ]['incident'] );
}

test_order_webhook_invalid_signature_401();
test_order_webhook_before_remote_id_is_saved();
test_order_webhook_foreign_reference_200();
test_order_webhook_unavailable_asks_resend();
test_cron_dispatches_point_provider();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
