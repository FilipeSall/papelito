<?php
/**
 * Estorno pelo gateway da cobrança original: adapter Mercado Pago e despacho por provider.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

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

class RefundTestOrder {
	public array $meta = array();

	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		return $this->meta[ $key ] ?? '';
	}
}

$GLOBALS['rf_env'] = array(
	'MERCADOPAGO_CLIENT_ID'     => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET' => 'secret',
	'MERCADOPAGO_REDIRECT_URI'  => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'   => 'test',
);
$GLOBALS['rf_requests']  = array();
$GLOBALS['rf_responses'] = array();
$GLOBALS['rf_orders']    = array();
$GLOBALS['rf_accounts']  = array(
	7 => array( 'id' => 7, 'vendor_id' => 40, 'status' => 'superseded', 'collector_id' => '900001', 'access_token_envelope' => 'k1:x' ),
	8 => array( 'id' => 8, 'vendor_id' => 40, 'status' => 'disconnected', 'collector_id' => '900003', 'access_token_envelope' => '' ),
);
$GLOBALS['rf_pagarme'] = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): void {}
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function esc_url_raw( mixed $value ): string { return (string) $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_get_environment_type(): string { return 'local'; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function add_query_arg( array $args, string $url ): string { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['rf_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' === $hook ) {
		$GLOBALS['rf_requests'][] = array( 'method' => $args[0], 'url' => $args[1], 'args' => $args[2] );
		return array_shift( $GLOBALS['rf_responses'] );
	}
	return $value;
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }
function wc_get_order( int $id ): ?RefundTestOrder { return $GLOBALS['rf_orders'][ $id ] ?? null; }
function papelito_vendor_payment_account_find( int $id ): ?array { return $GLOBALS['rf_accounts'][ $id ] ?? null; }
function papelito_vendor_payment_account_tokens( array $account ) {
	return '' === $account['access_token_envelope']
		? new WP_Error( 'papelito_payment_account_reauthorization_required', 'x' )
		: array( 'access_token' => 'APP_USR-account-' . $account['id'], 'refresh_token' => '' );
}
function papelito_pagarme_request( string $method, string $path, ?array $body = null, array $args = array() ) {
	$GLOBALS['rf_pagarme'][] = $method . ' ' . $path;
	return array( 'status' => 'canceled' );
}

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once __DIR__ . '/support/payment_attempts_memory.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_reconciliation.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_refunds.php';

const RF_KEY = 'papelito-refund-900-abc';

$failures = 0;

function rf_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function rf_json( int $code, array $body ): array {
	return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
}

function rf_order( int $id, int $account_id, string $provider = 'mercadopago' ): array {
	$order = new RefundTestOrder( $id );
	$order->meta[ PAPELITO_PAYMENT_PROVIDER_META ] = $provider;
	$order->meta['_papelito_mercadopago_approved_payment_ids'] = '7777';
	$GLOBALS['rf_orders'][ $id ] = $order;
	$attempt = papelito_payment_attempt_create( array( 'order_id' => $id, 'vendor_id' => 40, 'provider' => $provider, 'environment' => 'test', 'account_id' => $account_id, 'amount_cents' => 5000, 'flow' => 'redirect' ) );
	papelito_payment_attempt_transition( $attempt['id'], 'not_started', 'creating' );
	papelito_payment_attempt_transition( $attempt['id'], 'creating', 'paid' );
	$order->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = $attempt['id'];
	return array( 'order_id' => $id, 'amount_cents' => 5000, 'idempotency_key' => RF_KEY, 'attempt_id' => $attempt['id'] );
}

$row = rf_order( 900, 7 );
$GLOBALS['rf_responses'] = array(
	rf_json( 200, array( 'id' => 7777, 'status' => 'approved', 'transaction_amount' => 50.0, 'transaction_amount_refunded' => 0 ) ),
	rf_json( 201, array( 'id' => 1, 'payment_id' => 7777, 'status' => 'approved', 'amount' => 50.0 ) ),
);
$result = papelito_mercadopago_refund_remote( $row );
$post   = $GLOBALS['rf_requests'][1];
rf_assert( 'estorno usa a conta histórica da cobrança, não a vigente', 'Bearer APP_USR-account-7' === $post['args']['headers']['Authorization'] );
rf_assert( 'estorno vai ao endpoint de refunds do pagamento aprovado', 'POST' === $post['method'] && str_ends_with( $post['url'], '/v1/payments/7777/refunds' ) );
rf_assert( 'estorno leva a chave de idempotência estável', RF_KEY === $post['args']['headers']['X-Idempotency-Key'] );
rf_assert( 'estorno aprovado é confirmado', is_array( $result ) && true === $result['refunded'] && 'api' === $result['source'] );
rf_assert( 'tentativa passa a estornada', 'refunded' === papelito_payment_attempt_find( $row['attempt_id'] )['state'] );

$GLOBALS['rf_requests']  = array();
$row = rf_order( 901, 7 );
$GLOBALS['rf_responses'] = array( rf_json( 200, array( 'id' => 7777, 'status' => 'refunded', 'transaction_amount' => 50.0, 'transaction_amount_refunded' => 50.0 ) ) );
$result = papelito_mercadopago_refund_remote( $row );
rf_assert( 'pagamento já estornado não recebe novo POST', true === $result['refunded'] && 'api_precheck' === $result['source'] && 1 === count( $GLOBALS['rf_requests'] ) );

$GLOBALS['rf_requests']  = array();
$row = rf_order( 902, 7 );
$GLOBALS['rf_responses'] = array(
	rf_json( 200, array( 'id' => 7777, 'status' => 'approved', 'transaction_amount' => 50.0 ) ),
	new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ),
);
$timeout = papelito_mercadopago_refund_remote( $row );
rf_assert( 'timeout do estorno não confirma e não libera recibo', is_wp_error( $timeout ) );
$GLOBALS['rf_responses'] = array( rf_json( 200, array( 'id' => 7777, 'status' => 'refunded', 'transaction_amount' => 50.0, 'transaction_amount_refunded' => 50.0 ) ) );
$retry = papelito_mercadopago_refund_remote( $row );
rf_assert( 'retry consulta antes de repetir e não duplica a devolução', true === $retry['refunded'] && 'api_precheck' === $retry['source'] && 3 === count( $GLOBALS['rf_requests'] ) );

$row = rf_order( 903, 7 );
$GLOBALS['rf_responses'] = array(
	rf_json( 200, array( 'id' => 7777, 'status' => 'approved', 'transaction_amount' => 50.0 ) ),
	rf_json( 201, array( 'id' => 2, 'status' => 'in_process' ) ),
);
$processing = papelito_mercadopago_refund_remote( $row );
rf_assert( 'estorno em processamento não vira confirmado', is_array( $processing ) && false === $processing['refunded'] && 'in_process' === $processing['payload']['status'] );

$row = rf_order( 904, 7 );
$GLOBALS['rf_responses'] = array(
	rf_json( 200, array( 'id' => 7777, 'status' => 'approved', 'transaction_amount' => 50.0 ) ),
	rf_json( 400, array( 'error' => 'insufficient_amount', 'message' => 'Insufficient balance' ) ),
);
$no_balance = papelito_mercadopago_refund_remote( $row );
rf_assert( 'saldo insuficiente do vendor é recusa comercial, com mensagem legível', is_wp_error( $no_balance ) && 'papelito_mercadopago_refund_rejected' === $no_balance->get_error_code() );

$requests = count( $GLOBALS['rf_requests'] );
$row      = rf_order( 905, 8 );
$creds    = papelito_mercadopago_refund_remote( $row );
rf_assert( 'conta desconectada pede reautorização sem tentar o estorno', is_wp_error( $creds ) && 'papelito_mercadopago_refund_reauthorization_required' === $creds->get_error_code() && $requests === count( $GLOBALS['rf_requests'] ) );

function papelito_vendor_payment_account_current( int $vendor_id, string $provider, string $environment ): ?array { return $GLOBALS['rf_current'] ?? null; }
$GLOBALS['rf_current'] = array( 'id' => 9, 'vendor_id' => 40, 'status' => 'active', 'collector_id' => '900003', 'access_token_envelope' => 'k1:y', 'environment' => 'test' );
$GLOBALS['rf_accounts'][8]['environment'] = 'test';
$row = rf_order( 907, 8 );
$GLOBALS['rf_requests']  = array();
$GLOBALS['rf_responses'] = array( rf_json( 200, array( 'id' => 7777, 'status' => 'refunded', 'transaction_amount' => 50.0, 'transaction_amount_refunded' => 50.0 ) ) );
$reauthorized = papelito_mercadopago_refund_remote( $row );
rf_assert( 'estorno de conta desconectada usa a reconexão do mesmo collector', is_array( $reauthorized ) && 'Bearer APP_USR-account-9' === $GLOBALS['rf_requests'][0]['args']['headers']['Authorization'] );

$GLOBALS['rf_accounts'][10] = array( 'id' => 10, 'vendor_id' => 40, 'status' => 'revoked', 'collector_id' => '900003', 'access_token_envelope' => 'k1:z', 'environment' => 'test' );
$row = rf_order( 908, 10 );
$GLOBALS['rf_requests']  = array();
$GLOBALS['rf_responses'] = array( rf_json( 200, array( 'id' => 7777, 'status' => 'refunded', 'transaction_amount' => 50.0, 'transaction_amount_refunded' => 50.0 ) ) );
papelito_mercadopago_refund_remote( $row );
rf_assert( 'token revogado mas decifrável cede à reconexão válida do mesmo collector', 'Bearer APP_USR-account-9' === ( $GLOBALS['rf_requests'][0]['args']['headers']['Authorization'] ?? '' ) );
$GLOBALS['rf_current'] = null;

$pagarme_row = array( 'order_id' => 906, 'amount_cents' => 5000, 'idempotency_key' => 'k', 'pagarme_charge_id' => 'ch_1' );
$GLOBALS['rf_orders'][906] = new RefundTestOrder( 906 );
$GLOBALS['rf_orders'][906]->meta['_papelito_pagarme_order_id'] = 'or_1';
rf_assert( 'pedido Pagar.me continua estornado na Pagar.me', PAPELITO_PAYMENT_PROVIDER_PAGARME === papelito_order_refund_row_provider( $pagarme_row ) );
rf_assert( 'pedido Mercado Pago é estornado no Mercado Pago', PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO === papelito_order_refund_row_provider( array( 'order_id' => 900 ) ) );

rf_assert( 'pagamento aprovado recente vai para estorno pela API', 'api' === papelito_mercadopago_refund_mode( $GLOBALS['rf_orders'][900], time() - DAY_IN_SECONDS, time() ) );
rf_assert( 'pagamento além de 180 dias vai para estorno manual', 'manual' === papelito_mercadopago_refund_mode( $GLOBALS['rf_orders'][900], time() - 200 * DAY_IN_SECONDS, time() ) );
$GLOBALS['rf_orders'][900]->meta['_papelito_mercadopago_approved_payment_ids'] = '';
rf_assert( 'sem pagamento aprovado conhecido o estorno é manual', 'manual' === papelito_mercadopago_refund_mode( $GLOBALS['rf_orders'][900], time(), time() ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
