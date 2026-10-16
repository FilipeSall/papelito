<?php
/**
 * Webhook Mercado Pago: assinatura, conta candidata pelo attemptRef e reconciliação autenticada.
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
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	public function get_json_params(): array {
		return $this->json;
	}
}

class WC_Order {
	public array $meta    = array();
	public string $status = 'pending';
	public array $notes   = array();
	public int $completed = 0;

	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function add_order_note( string $note ): void {
		$this->notes[] = $note;
	}

	public function payment_complete(): void {
		++$this->completed;
	}

	public function update_status( string $status ): void {
		$this->status = $status;
	}

	public function save(): int {
		return $this->id;
	}
}

const WH_SECRET      = 'segredo-webhook-app';
const WH_COLLECTOR_A = '900001';
const WH_COLLECTOR_B = '900002';

$GLOBALS['wh_env'] = array(
	'MERCADOPAGO_CLIENT_ID'      => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET'  => 'secret',
	'MERCADOPAGO_WEBHOOK_SECRET' => WH_SECRET,
	'MERCADOPAGO_REDIRECT_URI'   => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'    => 'test',
);
$GLOBALS['wh_orders']     = array();
$GLOBALS['wh_payments']   = array();
$GLOBALS['wh_gets']       = array();
$GLOBALS['wh_confirmed']  = 0;
$GLOBALS['wh_promotions'] = 0;
$GLOBALS['wh_accounts']   = array(
	1 => array( 'id' => 1, 'vendor_id' => 40, 'status' => 'active', 'collector_id' => WH_COLLECTOR_A ),
	2 => array( 'id' => 2, 'vendor_id' => 40, 'status' => 'superseded', 'collector_id' => WH_COLLECTOR_B ),
);

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): void {
	if ( 'papelito_order_payment_confirmed' === $args[0] ) {
		++$GLOBALS['wh_confirmed'];
	}
}
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function esc_url_raw( mixed $value ): string { return (string) $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_get_environment_type(): string { return 'local'; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function add_query_arg( array $args, string $url ): string { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['wh_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function papelito_rate_limit( mixed ...$args ): bool { return true; }
function papelito_rate_limit_identity(): string { return 'ip:test'; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' === $hook ) {
		$GLOBALS['wh_gets'][] = array( 'url' => $args[1], 'auth' => $args[2]['headers']['Authorization'] ?? '' );
		$path = (string) parse_url( $args[1], PHP_URL_PATH );
		if ( preg_match( '#/v1/payments/(\d+)$#', $path, $m ) ) {
			$payment = $GLOBALS['wh_payments'][ $m[1] ] ?? null;
			return null === $payment ? array( 'response' => array( 'code' => 404 ), 'body' => '{"message":"not found"}' ) : array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $payment ) );
		}
		if ( str_ends_with( $path, '/v1/payments/search' ) ) {
			parse_str( (string) parse_url( $args[1], PHP_URL_QUERY ), $query );
			$results = array_values( array_filter( $GLOBALS['wh_payments'], static fn( array $p ): bool => $p['external_reference'] === ( $query['external_reference'] ?? '' ) ) );
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'results' => $results ) ) );
		}
		return array( 'response' => array( 'code' => 404 ), 'body' => '{}' );
	}
	return $value;
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }
function wc_get_order( int $id ): ?WC_Order { return $GLOBALS['wh_orders'][ $id ] ?? null; }
function papelito_vendor_payment_account_find( int $id ): ?array { return $GLOBALS['wh_accounts'][ $id ] ?? null; }
function papelito_vendor_payment_account_tokens( array $account ) {
	return '' === (string) ( $account['access_token_envelope'] ?? 'k1:x' )
		? new WP_Error( 'papelito_payment_account_reauthorization_required', 'x' )
		: array( 'access_token' => 'APP_USR-token-of-account-' . $account['id'], 'refresh_token' => '' );
}
function papelito_vendor_payment_account_current( int $vendor_id, string $provider, string $environment ): ?array {
	foreach ( $GLOBALS['wh_accounts'] as $account ) {
		if ( (int) $account['vendor_id'] === $vendor_id && 'active' === $account['status'] ) {
			return $account;
		}
	}
	return null;
}
function papelito_pagarme_promote_vendor_status_on_payment( object $order ): bool { ++$GLOBALS['wh_promotions']; return true; }
function papelito_pagarme_release_order_stock_for_terminal_state( object $order, string $reason = '' ) { $order->meta['_papelito_stock_reserved'] = '0'; return true; }
function papelito_pagarme_mark_vendor_status_unpaid( object $order ): void { $order->meta['_papelito_vendor_status'] = 'cancelado'; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once __DIR__ . '/support/payment_attempts_memory.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_payments.php';
require_once dirname( __DIR__ ) . '/includes/payment_order_state.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_reconciliation.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_webhook.php';

$failures = 0;

function wh_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function wh_attempt( int $order_id, int $account_id, int $amount = 10000, string $state = 'pending' ): array {
	$GLOBALS['wh_orders'][ $order_id ] = new WC_Order( $order_id );
	$GLOBALS['wh_orders'][ $order_id ]->meta['_papelito_stock_reserved'] = '1';
	$GLOBALS['wh_orders'][ $order_id ]->meta[ PAPELITO_PAYMENT_PROVIDER_META ] = 'mercadopago';
	$attempt = papelito_payment_attempt_create( array( 'order_id' => $order_id, 'vendor_id' => 40, 'provider' => 'mercadopago', 'environment' => 'test', 'account_id' => $account_id, 'account_ref' => WH_COLLECTOR_A, 'amount_cents' => $amount, 'flow' => 'redirect' ) );
	papelito_payment_attempt_transition( $attempt['id'], 'not_started', 'creating', array( 'external_reference' => papelito_mercadopago_external_reference( $attempt ) ) );
	if ( 'pending' === $state ) {
		papelito_payment_attempt_transition( $attempt['id'], 'creating', 'pending', array( 'remote_id' => 'pref-' . $order_id ) );
	} else {
		papelito_payment_attempt_transition( $attempt['id'], 'creating', $state );
	}
	$GLOBALS['wh_orders'][ $order_id ]->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = $attempt['id'];
	return papelito_payment_attempt_find( $attempt['id'] );
}

function wh_payment( string $id, array $attempt, array $overrides = array() ): void {
	$GLOBALS['wh_payments'][ $id ] = array_merge(
		array(
			'id'                 => (int) $id,
			'status'             => 'approved',
			'status_detail'      => 'accredited',
			'transaction_amount' => $attempt['amount_cents'] / 100,
			'currency_id'        => 'BRL',
			'external_reference' => $attempt['external_reference'],
			'collector_id'       => (int) WH_COLLECTOR_A,
			'live_mode'          => false,
			'date_of_expiration' => null,
			'payment_type_id'    => 'bank_transfer',
			'payment_method_id'  => 'pix',
		),
		$overrides
	);
}

function wh_signed_request( string $data_id, string $attempt_ref, string $request_id = 'req-1', ?string $secret = WH_SECRET ): WP_REST_Request {
	$ts        = '1742505638683';
	$manifest  = 'id:' . strtolower( $data_id ) . ';request-id:' . $request_id . ';ts:' . $ts . ';';
	$signature = 'ts=' . $ts . ',v1=' . hash_hmac( 'sha256', $manifest, (string) $secret );
	return new WP_REST_Request(
		array( 'data_id' => $data_id, 'type' => 'payment', 'attempt' => $attempt_ref ),
		array( 'x-signature' => $signature, 'x-request-id' => $request_id ),
		array( 'type' => 'payment', 'action' => 'payment.updated', 'data' => array( 'id' => $data_id ) )
	);
}

echo "Assinatura\n";
wh_assert( 'manifesto oficial com data.id em minúsculas', 'id:abc123;request-id:r;ts:1;' === papelito_mercadopago_webhook_manifest( 'ABC123', 'r', '1' ) );
wh_assert( 'par ausente sai do manifesto', 'id:1;ts:2;' === papelito_mercadopago_webhook_manifest( '1', '', '2' ) );
wh_assert( 'cabeçalho x-signature é decomposto', array( 'ts' => '9', 'v1' => 'ff' ) === papelito_mercadopago_webhook_parse_signature( 'ts=9,v1=ff' ) );
wh_assert( 'assinatura válida confere', papelito_mercadopago_webhook_signature_valid( wh_signed_request( '123', str_repeat( 'a', 32 ) ) ) );
wh_assert( 'assinatura com outro segredo falha', ! papelito_mercadopago_webhook_signature_valid( wh_signed_request( '123', str_repeat( 'a', 32 ), 'req-1', 'outro' ) ) );
wh_assert( 'cabeçalho ausente falha', ! papelito_mercadopago_webhook_signature_valid( new WP_REST_Request( array( 'data_id' => '123' ), array(), array() ) ) );

echo "Pagamento aprovado\n";
$attempt = wh_attempt( 100, 1 );
wh_payment( '555', $attempt );
$response = papelito_mercadopago_webhook_handle( wh_signed_request( '555', $attempt['attempt_ref'] ) );
$after    = papelito_payment_attempt_find( $attempt['id'] );
wh_assert( 'webhook válido responde 200', 200 === $response->get_status() );
wh_assert( 'consulta usa o token da conta da tentativa', 'Bearer APP_USR-token-of-account-1' === end( $GLOBALS['wh_gets'] )['auth'] );
wh_assert( 'pagamento validado marca a tentativa paga', 'paid' === $after['state'] && in_array( '555', $after['remote_payment_ids'], true ) );
wh_assert( 'pedido liberado uma vez', 'processing' === $GLOBALS['wh_orders'][100]->status && 1 === $GLOBALS['wh_orders'][100]->completed && 1 === $GLOBALS['wh_confirmed'] );

papelito_mercadopago_webhook_handle( wh_signed_request( '555', $attempt['attempt_ref'], 'req-2' ) );
wh_assert( 'evento repetido não repete efeito financeiro nem de estoque', 1 === $GLOBALS['wh_orders'][100]->completed && 1 === $GLOBALS['wh_confirmed'] && 1 === $GLOBALS['wh_promotions'] );

$GLOBALS['wh_payments']['555']['status'] = 'pending';
papelito_mercadopago_webhook_handle( wh_signed_request( '555', $attempt['attempt_ref'], 'req-3' ) );
wh_assert( 'evento fora de ordem não regride pago para não pago', 'paid' === papelito_payment_attempt_find( $attempt['id'] )['state'] && 'processing' === $GLOBALS['wh_orders'][100]->status );

$snapshot = papelito_mercadopago_order_payment_snapshot( $GLOBALS['wh_orders'][100] );
wh_assert( 'snapshot do pedido MP pago informa provider, estado e meio', 'mercadopago' === $snapshot['provider'] && 'paid' === $snapshot['state'] && 'pix' === $snapshot['method'] );
$pending_order = wh_attempt( 150, 1 );
papelito_payment_attempt_update( papelito_payment_attempt_find( $pending_order['id'] ), array( 'redirect_url' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=p' ) );
$GLOBALS['wh_orders'][150]->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = $pending_order['id'];
$pending_snapshot = papelito_mercadopago_order_payment_snapshot( $GLOBALS['wh_orders'][150] );
wh_assert( 'snapshot do pedido MP pendente oferece o link de volta ao checkout', 'pending' === $pending_snapshot['state'] && 'redirect' === $pending_snapshot['flow'] && 0 === strpos( (string) $pending_snapshot['redirect_url'], 'https://www.mercadopago.com.br/' ) );

echo "Pagamento que não pertence à tentativa\n";
foreach (
	array(
		'collector de B'      => array( 'collector_id' => (int) WH_COLLECTOR_B ),
		'valor divergente'    => array( 'transaction_amount' => 99.99 ),
		'outra moeda'         => array( 'currency_id' => 'USD' ),
		'outro ambiente'      => array( 'live_mode' => true ),
		'referência estranha' => array( 'external_reference' => 'ppl-999-999' ),
	) as $label => $override
) {
	$target  = wh_attempt( 200 + count( $GLOBALS['wh_orders'] ), 1 );
	$pay_id  = (string) ( 7000 + count( $GLOBALS['wh_orders'] ) );
	wh_payment( $pay_id, $target, $override );
	papelito_mercadopago_webhook_handle( wh_signed_request( $pay_id, $target['attempt_ref'] ) );
	wh_assert( "{$label} não libera o pedido", 'paid' !== papelito_payment_attempt_find( $target['id'] )['state'] && 'processing' !== $GLOBALS['wh_orders'][ $target['order_id'] ]->status );
}

echo "Assinatura inválida e attemptRef\n";
$victim = wh_attempt( 300, 1 );
wh_payment( '8000', $victim );
$gets   = count( $GLOBALS['wh_gets'] );
$forged = papelito_mercadopago_webhook_handle( wh_signed_request( '8000', $victim['attempt_ref'], 'req-x', 'segredo-errado' ) );
wh_assert( 'assinatura inválida é 401 e não aplica nada', 401 === $forged->get_status() && 'pending' === papelito_payment_attempt_find( $victim['id'] )['state'] && $gets === count( $GLOBALS['wh_gets'] ) );
wh_assert( 'notificação não assinada agenda reconciliação puxada da tentativa', '' !== (string) papelito_payment_attempt_find( $victim['id'] )['next_reconcile_at'] );
$expired_victim = wh_attempt( 302, 1 );
papelito_payment_attempt_transition( $expired_victim['id'], 'pending', 'expired' );
papelito_mercadopago_webhook_handle( wh_signed_request( '8010', $expired_victim['attempt_ref'], 'req-y', 'segredo-errado' ) );
wh_assert( 'notificação não assinada também agenda tentativa expirada (aprovação tardia)', '' !== (string) papelito_payment_attempt_find( $expired_victim['id'] )['next_reconcile_at'] );

$other = wh_attempt( 301, 1 );
wh_payment( '8001', $other );
papelito_mercadopago_webhook_handle( wh_signed_request( '8001', $victim['attempt_ref'] ) );
wh_assert( 'attemptRef adulterado não confirma pagamento de outra tentativa', 'pending' === papelito_payment_attempt_find( $victim['id'] )['state'] && 'pending' === papelito_payment_attempt_find( $other['id'] )['state'] );

$gets = count( $GLOBALS['wh_gets'] );
$orphan = papelito_mercadopago_webhook_handle( wh_signed_request( '8002', '' ) );
wh_assert( 'sem attemptRef não consulta contas de todos os vendors', 200 === $orphan->get_status() && $gets === count( $GLOBALS['wh_gets'] ) );

echo "Conta histórica, aprovação tardia e cobrança dupla\n";
$historic = wh_attempt( 400, 2 );
$GLOBALS['wh_payments']['8100'] = array( 'id' => 8100, 'status' => 'approved', 'transaction_amount' => 100.0, 'currency_id' => 'BRL', 'external_reference' => $historic['external_reference'], 'collector_id' => (int) WH_COLLECTOR_B, 'live_mode' => false );
papelito_mercadopago_webhook_handle( wh_signed_request( '8100', $historic['attempt_ref'] ) );
wh_assert( 'pedido após troca de conta usa a identidade histórica', 'paid' === papelito_payment_attempt_find( $historic['id'] )['state'] && 'Bearer APP_USR-token-of-account-2' === end( $GLOBALS['wh_gets'] )['auth'] );

$late = wh_attempt( 500, 1 );
papelito_payment_attempt_transition( $late['id'], 'pending', 'expired' );
$GLOBALS['wh_orders'][500]->status = 'failed';
wh_payment( '8200', $late );
papelito_mercadopago_webhook_handle( wh_signed_request( '8200', $late['attempt_ref'] ) );
wh_assert( 'aprovação tardia vence a expiração local', 'paid' === papelito_payment_attempt_find( $late['id'] )['state'] );

$double = wh_attempt( 600, 1 );
wh_payment( '8300', $double );
wh_payment( '8301', $double );
papelito_mercadopago_webhook_handle( wh_signed_request( '8300', $double['attempt_ref'] ) );
papelito_mercadopago_webhook_handle( wh_signed_request( '8301', $double['attempt_ref'], 'req-dup' ) );
$double_after = papelito_payment_attempt_find( $double['id'] );
wh_assert( 'duas aprovações viram incidente auditado', 'multiple_approved' === $double_after['incident'] && 2 === count( $double_after['remote_payment_ids'] ) );
wh_assert( 'segunda aprovação não separa mercadoria de novo', 1 === $GLOBALS['wh_orders'][600]->completed );
wh_assert( 'cobrança excedente fica anotada no pedido', array() !== array_filter( $GLOBALS['wh_orders'][600]->notes, static fn( string $n ): bool => false !== strpos( $n, '8301' ) ) );

$interrupted = wh_attempt( 610, 1 );
wh_payment( '8310', $interrupted );
$GLOBALS['wh_orders'][610]->meta[ PAPELITO_MERCADOPAGO_APPROVED_PAYMENTS_META ] = '8310';
papelito_mercadopago_webhook_handle( wh_signed_request( '8310', $interrupted['attempt_ref'], 'req-retry' ) );
wh_assert( 'retry completa a confirmação interrompida depois de gravar o pagamento', 'paid' === papelito_payment_attempt_find( $interrupted['id'] )['state'] && 1 === $GLOBALS['wh_orders'][610]->completed );
papelito_mercadopago_webhook_handle( wh_signed_request( '8310', $interrupted['attempt_ref'], 'req-retry-2' ) );
wh_assert( 'retry da confirmação concluída não libera de novo', 1 === $GLOBALS['wh_orders'][610]->completed );

echo "Concorrência e conta reconectada\n";
$locked = wh_attempt( 800, 1 );
wh_payment( '8700', $locked );
papelito_payment_attempt_acquire_lock( 'attempt:' . $locked['id'] );
$busy = papelito_mercadopago_webhook_handle( wh_signed_request( '8700', $locked['attempt_ref'] ) );
wh_assert( 'tentativa em processamento por outro worker responde 500 sem aplicar', 500 === $busy->get_status() && 'pending' === papelito_payment_attempt_find( $locked['id'] )['state'] && 0 === $GLOBALS['wh_orders'][800]->completed );
papelito_payment_attempt_release_lock( 'attempt:' . $locked['id'] );

$closed = wh_attempt( 801, 1 );
papelito_payment_attempt_transition( $closed['id'], 'pending', 'paid' );
papelito_payment_attempt_transition( $closed['id'], 'paid', 'refunded' );
wh_payment( '8701', papelito_payment_attempt_find( $closed['id'] ) );
papelito_mercadopago_webhook_handle( wh_signed_request( '8701', $closed['attempt_ref'] ) );
wh_assert( 'aprovação que perde a transição não libera o pedido', 0 === $GLOBALS['wh_orders'][801]->completed && 'pending' === $GLOBALS['wh_orders'][801]->status );

$GLOBALS['wh_accounts'][3] = array( 'id' => 3, 'vendor_id' => 40, 'status' => 'disconnected', 'collector_id' => WH_COLLECTOR_A, 'access_token_envelope' => '' );
$GLOBALS['wh_accounts'][4] = array( 'id' => 4, 'vendor_id' => 40, 'status' => 'active', 'collector_id' => WH_COLLECTOR_A );
$GLOBALS['wh_accounts'][1]['status'] = 'superseded';
$reconnected = wh_attempt( 802, 3 );
wh_payment( '8702', $reconnected );
papelito_mercadopago_webhook_handle( wh_signed_request( '8702', $reconnected['attempt_ref'] ) );
wh_assert( 'conta desconectada usa a reconexão do mesmo collector', 'paid' === papelito_payment_attempt_find( $reconnected['id'] )['state'] && 'Bearer APP_USR-token-of-account-4' === end( $GLOBALS['wh_gets'] )['auth'] );
$GLOBALS['wh_accounts'][4]['collector_id'] = '999999';
$other_collector = wh_attempt( 803, 3 );
wh_payment( '8703', $other_collector );
papelito_mercadopago_webhook_handle( wh_signed_request( '8703', $other_collector['attempt_ref'] ) );
wh_assert( 'reconexão com outro collector não serve para cobrança antiga', 'pending' === papelito_payment_attempt_find( $other_collector['id'] )['state'] );
unset( $GLOBALS['wh_accounts'][3], $GLOBALS['wh_accounts'][4] );
$GLOBALS['wh_accounts'][1]['status'] = 'revoked';
$GLOBALS['wh_accounts'][6]           = array( 'id' => 6, 'vendor_id' => 40, 'status' => 'active', 'collector_id' => WH_COLLECTOR_A );
$revoked = wh_attempt( 804, 1 );
wh_payment( '8704', $revoked );
papelito_mercadopago_webhook_handle( wh_signed_request( '8704', $revoked['attempt_ref'] ) );
wh_assert( 'token revogado mas decifrável cede à reconexão válida do mesmo collector', 'Bearer APP_USR-token-of-account-6' === end( $GLOBALS['wh_gets'] )['auth'] );
unset( $GLOBALS['wh_accounts'][6] );
$GLOBALS['wh_accounts'][1]['status'] = 'active';

echo "Estorno e chargeback feitos fora da Papelito\n";
$GLOBALS['wh_reversals'] = array();
$GLOBALS['wh_anomalies'] = array();
function papelito_order_refund_handle_psp_reversal( object $order, string $state ): void { $GLOBALS['wh_reversals'][] = array( $order->get_id(), $state ); }
$refunded = wh_attempt( 700, 1 );
wh_payment( '8400', $refunded );
papelito_mercadopago_webhook_handle( wh_signed_request( '8400', $refunded['attempt_ref'] ) );
$GLOBALS['wh_payments']['8400']['status'] = 'refunded';
papelito_mercadopago_webhook_handle( wh_signed_request( '8400', $refunded['attempt_ref'], 'req-refund' ) );
wh_assert( 'estorno feito no painel do Mercado Pago vira estorno registrado', array( array( 700, 'refunded' ) ) === $GLOBALS['wh_reversals'] && 'refunded' === papelito_payment_attempt_find( $refunded['id'] )['state'] );

$charged = wh_attempt( 701, 1 );
wh_payment( '8500', $charged );
papelito_mercadopago_webhook_handle( wh_signed_request( '8500', $charged['attempt_ref'] ) );
$GLOBALS['wh_payments']['8500']['status'] = 'charged_back';
papelito_mercadopago_webhook_handle( wh_signed_request( '8500', $charged['attempt_ref'], 'req-cb' ) );
wh_assert( 'chargeback fica anotado sem mudar o estado do pedido', 'processing' === $GLOBALS['wh_orders'][701]->status && array() !== array_filter( $GLOBALS['wh_orders'][701]->notes, static fn( string $n ): bool => false !== stripos( $n, 'chargeback' ) ) );

$GLOBALS['wh_payments']['8301']['status'] = 'refunded';
$reversals = count( $GLOBALS['wh_reversals'] );
papelito_mercadopago_webhook_handle( wh_signed_request( '8301', $double['attempt_ref'], 'req-excess-refund' ) );
wh_assert( 'devolver a cobrança excedente não estorna o pedido pago pela principal', $reversals === count( $GLOBALS['wh_reversals'] ) && 'paid' === papelito_payment_attempt_find( $double['id'] )['state'] );
wh_assert( 'devolução da excedente fica anotada no pedido', array() !== array_filter( $GLOBALS['wh_orders'][600]->notes, static fn( string $n ): bool => false !== strpos( $n, '8301' ) && false !== stripos( $n, 'devolvid' ) ) );

$unpaid = wh_attempt( 702, 1 );
wh_payment( '8600', $unpaid, array( 'status' => 'refunded' ) );
$reversals = count( $GLOBALS['wh_reversals'] );
papelito_mercadopago_webhook_handle( wh_signed_request( '8600', $unpaid['attempt_ref'] ) );
wh_assert( 'estorno de pedido nunca pago não abre estorno', $reversals === count( $GLOBALS['wh_reversals'] ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
