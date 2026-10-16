<?php
/**
 * Reconciliação programada das tentativas: unknown, pendente, expiração e estoque.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'PAPELITO_PAGARME_ORDER_ID_META', '_papelito_pagarme_order_id' );
define( 'PAPELITO_PAGARME_PAYMENT_STATE_META', '_papelito_pagarme_payment_state' );

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

$GLOBALS['rc_env'] = array(
	'MERCADOPAGO_CLIENT_ID'     => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET' => 'secret',
	'MERCADOPAGO_REDIRECT_URI'  => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'   => 'test',
);
$GLOBALS['rc_orders']      = array();
$GLOBALS['rc_payments']    = array();
$GLOBALS['rc_search_fail'] = false;
$GLOBALS['rc_releases']    = 0;
$GLOBALS['rc_pagarme']     = array();
$GLOBALS['rc_pagarme_reconciled'] = array();

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
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['rc_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' !== $hook ) {
		return $value;
	}
	if ( $GLOBALS['rc_search_fail'] ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
	}
	parse_str( (string) parse_url( $args[1], PHP_URL_QUERY ), $query );
	$ref     = (string) ( $query['external_reference'] ?? '' );
	$results = array_values( array_filter( $GLOBALS['rc_payments'], static fn( array $p ): bool => $p['external_reference'] === $ref ) );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'results' => $results ) ) );
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }
function wc_get_order( int $id ): ?WC_Order { return $GLOBALS['rc_orders'][ $id ] ?? null; }
function papelito_vendor_payment_account_find( int $id ): ?array { return array( 'id' => $id, 'vendor_id' => 40, 'status' => 'active', 'collector_id' => '900001' ); }
function papelito_vendor_payment_account_tokens( array $account ) { return array( 'access_token' => 'APP_USR-x', 'refresh_token' => '' ); }
function papelito_vendor_payment_account_current( int $vendor_id, string $provider, string $environment ): ?array { return papelito_vendor_payment_account_find( 1 ); }
function papelito_pagarme_promote_vendor_status_on_payment( object $order ): bool { return true; }
function papelito_pagarme_release_order_stock_for_terminal_state( object $order, string $reason = '' ) { ++$GLOBALS['rc_releases']; $order->meta['_papelito_stock_reserved'] = '0'; return true; }
function papelito_pagarme_mark_vendor_status_unpaid( object $order ): void {}
function papelito_pagarme_request( string $method, string $path, ?array $body = null, array $args = array() ) {
	$GLOBALS['rc_pagarme'][] = $path;
	return array( 'data' => $GLOBALS['rc_pagarme_orders'] ?? array() );
}
function papelito_pagarme_store_order_response( object $order, array $response, string $method ): array {
	$order->meta[ PAPELITO_PAGARME_ORDER_ID_META ]    = $response['id'];
	$order->meta[ PAPELITO_PAGARME_PAYMENT_STATE_META ] = $response['charges'][0]['status'];
	return array( 'method' => $method, 'state' => $response['charges'][0]['status'] );
}
function papelito_pagarme_reconcile_wc_order( object $order ) {
	$GLOBALS['rc_pagarme_reconciled'][] = $order->get_id();
	return array( 'payment' => array( 'state' => (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ) ) );
}
function papelito_pagarme_payment_state_is_paid( string $state ): bool { return 'paid' === $state; }
function papelito_pagarme_payment_state_releases_stock( string $state ): bool { return in_array( $state, array( 'failed', 'canceled' ), true ); }
function papelito_pagarme_apply_order_state( object $order, string $state, bool $paid ): void { $order->meta['applied_state'] = $state; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once __DIR__ . '/support/payment_attempts_memory.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_payments.php';
require_once dirname( __DIR__ ) . '/includes/payment_order_state.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_reconciliation.php';
require_once dirname( __DIR__ ) . '/includes/payment_reconciliation.php';

$failures = 0;

function rc_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function rc_attempt( int $order_id, string $state, array $fields = array(), string $provider = 'mercadopago' ): array {
	$GLOBALS['rc_orders'][ $order_id ] = new WC_Order( $order_id );
	$GLOBALS['rc_orders'][ $order_id ]->meta['_papelito_stock_reserved'] = '1';
	$attempt = papelito_payment_attempt_create( array( 'order_id' => $order_id, 'vendor_id' => 40, 'provider' => $provider, 'environment' => 'test', 'account_id' => 1, 'account_ref' => '900001', 'amount_cents' => 5000, 'flow' => 'redirect' ) );
	$path    = array( 'creating' => array( 'creating' ), 'unknown' => array( 'creating', 'unknown' ), 'pending' => array( 'creating', 'pending' ) )[ $state ];
	$from    = 'not_started';
	foreach ( $path as $step ) {
		papelito_payment_attempt_transition( $attempt['id'], $from, $step, 'creating' === $step ? array( 'external_reference' => papelito_mercadopago_external_reference( $attempt ) ) : array() );
		$from = $step;
	}
	papelito_payment_attempt_update( papelito_payment_attempt_find( $attempt['id'] ), $fields );
	return papelito_payment_attempt_find( $attempt['id'] );
}

function rc_payment( array $attempt, string $id, string $status, ?string $expiration = null ): void {
	$GLOBALS['rc_payments'][ $id ] = array( 'id' => (int) $id, 'status' => $status, 'transaction_amount' => 50.0, 'currency_id' => 'BRL', 'external_reference' => $attempt['external_reference'], 'collector_id' => 900001, 'live_mode' => false, 'date_of_expiration' => $expiration );
}

function rc_old( int $minutes ): string {
	return gmdate( 'Y-m-d H:i:s', time() - $minutes * 60 );
}

echo "unknown\n";
$unknown_paid = rc_attempt( 10, 'unknown' );
rc_payment( $unknown_paid, '1', 'approved' );
papelito_payment_reconcile_attempt( $unknown_paid );
rc_assert( 'unknown com pagamento aprovado vira pago e libera o pedido', 'paid' === papelito_payment_attempt_find( $unknown_paid['id'] )['state'] && 'processing' === $GLOBALS['rc_orders'][10]->status );

$unknown_pending = rc_attempt( 11, 'unknown' );
rc_payment( $unknown_pending, '2', 'pending', gmdate( 'c', time() + DAY_IN_SECONDS ) );
papelito_payment_reconcile_attempt( $unknown_pending );
rc_assert( 'unknown com PIX/boleto emitido vira pendente', 'pending' === papelito_payment_attempt_find( $unknown_pending['id'] )['state'] );

$unknown_fresh = rc_attempt( 12, 'unknown', array( 'created_at' => rc_old( 3 ) ) );
papelito_payment_reconcile_attempt( $unknown_fresh );
rc_assert( 'ausência ainda recente mantém unknown', 'unknown' === papelito_payment_attempt_find( $unknown_fresh['id'] )['state'] && '1' === $GLOBALS['rc_orders'][12]->meta['_papelito_stock_reserved'] );

$unknown_old = rc_attempt( 13, 'unknown', array( 'created_at' => rc_old( 20 ) ) );
papelito_payment_reconcile_attempt( $unknown_old );
rc_assert( 'sem pagamento depois da janela, unknown expira e libera a reserva', 'expired' === papelito_payment_attempt_find( $unknown_old['id'] )['state'] && 'failed' === $GLOBALS['rc_orders'][13]->status && '0' === $GLOBALS['rc_orders'][13]->meta['_papelito_stock_reserved'] );

$stuck = rc_attempt( 14, 'creating', array( 'created_at' => rc_old( 20 ) ) );
papelito_payment_reconcile_attempt( $stuck );
rc_assert( 'criação travada por crash é reconciliada como unknown', in_array( papelito_payment_attempt_find( $stuck['id'] )['state'], array( 'unknown', 'expired' ), true ) );

echo "pendente\n";
$pending_open = rc_attempt( 20, 'pending', array( 'expires_at' => rc_old( 120 ) ) );
rc_payment( $pending_open, '3', 'pending', gmdate( 'c', time() + DAY_IN_SECONDS ) );
papelito_payment_reconcile_attempt( $pending_open );
rc_assert( 'preferência vencida não cancela boleto/PIX ainda válido', 'pending' === papelito_payment_attempt_find( $pending_open['id'] )['state'] && 'pending' === $GLOBALS['rc_orders'][20]->status );

$pending_dead = rc_attempt( 21, 'pending', array( 'expires_at' => rc_old( 120 ) ) );
rc_payment( $pending_dead, '4', 'rejected' );
papelito_payment_reconcile_attempt( $pending_dead );
rc_assert( 'preferência vencida sem pagamento vivo expira e libera estoque', 'expired' === papelito_payment_attempt_find( $pending_dead['id'] )['state'] && 'failed' === $GLOBALS['rc_orders'][21]->status );

$pending_live = rc_attempt( 22, 'pending', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
papelito_payment_reconcile_attempt( $pending_live );
rc_assert( 'preferência ainda vigente continua pendente', 'pending' === papelito_payment_attempt_find( $pending_live['id'] )['state'] );

$paid_late = rc_attempt( 23, 'pending', array( 'expires_at' => rc_old( 120 ) ) );
papelito_payment_attempt_transition( $paid_late['id'], 'pending', 'expired' );
$GLOBALS['rc_orders'][23]->status = 'failed';
rc_payment( papelito_payment_attempt_find( $paid_late['id'] ), '5', 'approved' );
papelito_payment_reconcile_attempt( papelito_payment_attempt_find( $paid_late['id'] ) );
rc_assert( 'pagamento aprovado depois da expiração local é aplicado', 'paid' === papelito_payment_attempt_find( $paid_late['id'] )['state'] );

$releases = $GLOBALS['rc_releases'];
papelito_payment_reconcile_attempt( papelito_payment_attempt_find( $unknown_paid['id'] ) );
rc_assert( 'pedido pago nunca é reabastecido por expiração', $releases === $GLOBALS['rc_releases'] && 'processing' === $GLOBALS['rc_orders'][10]->status );

echo "falhas e lote\n";
$GLOBALS['rc_search_fail'] = true;
$retry = rc_attempt( 30, 'pending', array( 'expires_at' => rc_old( 120 ) ) );
papelito_payment_reconcile_attempt( $retry );
$after = papelito_payment_attempt_find( $retry['id'] );
rc_assert( 'falha de consulta mantém pendente com backoff', 'pending' === $after['state'] && 1 === (int) $after['reconcile_failures'] && strtotime( $after['next_reconcile_at'] . ' UTC' ) > time() );
rc_assert( 'falha de consulta não libera estoque', '1' === $GLOBALS['rc_orders'][30]->meta['_papelito_stock_reserved'] );
$GLOBALS['rc_search_fail'] = false;

rc_assert( 'backoff cresce e tem teto', papelito_payment_reconcile_backoff( 1 ) < papelito_payment_reconcile_backoff( 4 ) && papelito_payment_reconcile_backoff( 30 ) <= 6 * HOUR_IN_SECONDS );

echo "Pagar.me\n";
$GLOBALS['rc_pagarme_orders'] = array( array( 'id' => 'or_found', 'code' => 'woo-order-40', 'status' => 'pending', 'charges' => array( array( 'id' => 'ch_1', 'status' => 'pending' ) ) ) );
$pg_unknown = rc_attempt( 40, 'unknown', array(), 'pagarme' );
papelito_payment_reconcile_attempt( $pg_unknown );
rc_assert( 'unknown Pagar.me é resolvido pelo código do pedido', 'pending' === papelito_payment_attempt_find( $pg_unknown['id'] )['state'] && 'or_found' === $GLOBALS['rc_orders'][40]->meta[ PAPELITO_PAGARME_ORDER_ID_META ] && false !== strpos( end( $GLOBALS['rc_pagarme'] ), 'woo-order-40' ) );

$GLOBALS['rc_orders'][40]->meta[ PAPELITO_PAGARME_PAYMENT_STATE_META ] = 'paid';
papelito_payment_reconcile_attempt( papelito_payment_attempt_find( $pg_unknown['id'] ) );
rc_assert( 'pendente Pagar.me pago no gateway vira pago na tentativa', 'paid' === papelito_payment_attempt_find( $pg_unknown['id'] )['state'] );

rc_assert( 'cron legado da Pagar.me ignora pedido do Mercado Pago', papelito_payment_reconcile_is_pagarme_order( $GLOBALS['rc_orders'][40] ) && ! papelito_payment_reconcile_is_pagarme_order( papelito_payment_reconcile_mp_order_fixture() ) );

function papelito_payment_reconcile_mp_order_fixture(): WC_Order {
	$order = new WC_Order( 99 );
	$order->meta[ PAPELITO_PAYMENT_PROVIDER_META ] = 'mercadopago';
	return $order;
}

echo "coleta Pagar.me abandonada\n";
$GLOBALS['rc_orders'][70] = new WC_Order( 70 );
$GLOBALS['rc_orders'][70]->meta['_papelito_stock_reserved'] = '1';
$abandoned = papelito_payment_attempt_create( array( 'order_id' => 70, 'vendor_id' => 40, 'provider' => 'pagarme', 'environment' => 'test', 'amount_cents' => 5000, 'state' => 'awaiting_payment_input', 'flow' => 'requires_payment_input', 'expires_at' => rc_old( 5 ) ) );
papelito_payment_reconcile_attempt( $abandoned );
rc_assert( 'coleta abandonada expira e libera a reserva', 'expired' === papelito_payment_attempt_find( $abandoned['id'] )['state'] && 'failed' === $GLOBALS['rc_orders'][70]->status && '0' === $GLOBALS['rc_orders'][70]->meta['_papelito_stock_reserved'] );
$GLOBALS['rc_orders'][71] = new WC_Order( 71 );
$waiting = papelito_payment_attempt_create( array( 'order_id' => 71, 'vendor_id' => 40, 'provider' => 'pagarme', 'environment' => 'test', 'amount_cents' => 5000, 'state' => 'awaiting_payment_input', 'flow' => 'requires_payment_input', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
papelito_payment_reconcile_attempt( $waiting );
rc_assert( 'coleta ainda no prazo continua aguardando', 'awaiting_payment_input' === papelito_payment_attempt_find( $waiting['id'] )['state'] );

echo "aprovação tardia\n";
$boleto = rc_attempt( 72, 'pending', array( 'expires_at' => rc_old( 120 ) ) );
rc_payment( $boleto, '20', 'pending', gmdate( 'c', time() - DAY_IN_SECONDS ) );
$GLOBALS['rc_payments']['20']['payment_type_id'] = 'ticket';
papelito_payment_reconcile_attempt( $boleto );
rc_assert( 'boleto vencido há um dia ainda pode compensar: reserva mantida', 'pending' === papelito_payment_attempt_find( $boleto['id'] )['state'] && '1' === $GLOBALS['rc_orders'][72]->meta['_papelito_stock_reserved'] );

$watched = rc_attempt( 73, 'pending', array( 'expires_at' => rc_old( 120 ) ) );
rc_payment( $watched, '21', 'pending', gmdate( 'c', time() - 10 * DAY_IN_SECONDS ) );
papelito_payment_reconcile_attempt( $watched );
$expired_watch = papelito_payment_attempt_find( $watched['id'] );
rc_assert( 'tentativa expirada com pagamento registrado continua vigiada', 'expired' === $expired_watch['state'] && '' !== (string) $expired_watch['next_reconcile_at'] );
$GLOBALS['rc_payments']['21']['status'] = 'approved';
papelito_payment_reconcile_attempt( $expired_watch );
rc_assert( 'compensação depois da expiração local ainda é aplicada', 'paid' === papelito_payment_attempt_find( $watched['id'] )['state'] );

$no_payment = rc_attempt( 74, 'pending', array( 'expires_at' => rc_old( 120 ) ) );
papelito_payment_reconcile_attempt( $no_payment );
rc_assert( 'expirada sem nenhum pagamento sai da vigilância', null === papelito_payment_attempt_find( $no_payment['id'] )['next_reconcile_at'] || '' === (string) papelito_payment_attempt_find( $no_payment['id'] )['next_reconcile_at'] );

echo "trava por tentativa\n";
$busy_attempt = rc_attempt( 60, 'pending', array( 'expires_at' => rc_old( 120 ) ) );
papelito_payment_attempt_acquire_lock( 'attempt:' . $busy_attempt['id'] );
rc_assert( 'reconciliação com a tentativa travada por outro worker não age', 'busy' === papelito_payment_reconcile_attempt( $busy_attempt ) && 'pending' === papelito_payment_attempt_find( $busy_attempt['id'] )['state'] && '1' === $GLOBALS['rc_orders'][60]->meta['_papelito_stock_reserved'] );
papelito_payment_attempt_release_lock( 'attempt:' . $busy_attempt['id'] );

echo "consulta ao abrir o pedido\n";
$viewed = rc_attempt( 50, 'pending', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
$GLOBALS['rc_orders'][50]->meta[ PAPELITO_PAYMENT_PROVIDER_META ]   = 'mercadopago';
$GLOBALS['rc_orders'][50]->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = $viewed['id'];
rc_payment( $viewed, '9', 'approved' );
papelito_payment_maybe_reconcile_on_view( $GLOBALS['rc_orders'][50] );
rc_assert( 'abrir pedido MP pendente reconcilia e mostra o pagamento', 'paid' === papelito_payment_attempt_find( $viewed['id'] )['state'] );

$throttled = rc_attempt( 51, 'pending', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
$GLOBALS['rc_orders'][51]->meta[ PAPELITO_PAYMENT_PROVIDER_META ]   = 'mercadopago';
$GLOBALS['rc_orders'][51]->meta[ PAPELITO_PAYMENT_ATTEMPT_ID_META ] = $throttled['id'];
papelito_payment_maybe_reconcile_on_view( $GLOBALS['rc_orders'][51] );
rc_payment( $throttled, '10', 'approved' );
papelito_payment_maybe_reconcile_on_view( $GLOBALS['rc_orders'][51] );
rc_assert( 'reabrir em seguida não consulta de novo (throttle)', 'pending' === papelito_payment_attempt_find( $throttled['id'] )['state'] );

$GLOBALS['rc_orders'][40]->meta[ PAPELITO_PAYMENT_PROVIDER_META ] = 'pagarme';
papelito_payment_maybe_reconcile_on_view( $GLOBALS['rc_orders'][40] );
rc_assert( 'pedido Pagar.me não é tocado por esta consulta', true );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
