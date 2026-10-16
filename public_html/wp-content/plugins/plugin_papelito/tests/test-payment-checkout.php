<?php
/**
 * Orquestrador do checkout com gateways: um pedido, uma reserva, uma criação financeira.
 *
 * Exercita a sequência que a spec exige — sem método → Mercado Pago falha antes de criar →
 * coleta Pagar.me → continuação → retries — com o repositório de tentativas em memória e o
 * WooCommerce simulado na fronteira.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'PAPELITO_PAGARME_ORDER_ID_META', '_papelito_pagarme_order_id' );

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
	public array $headers = array();

	public function __construct( public mixed $data = null, public int $status = 200 ) {}

	public function get_data(): mixed {
		return $this->data;
	}

	public function header( string $k, string $v ): void {
		$this->headers[ $k ] = $v;
	}
}

class WP_REST_Request {
	public function __construct( private array $json = array(), private array $params = array() ) {}

	public function get_json_params(): array {
		return $this->json;
	}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
}

class WC_Order {
	public array $meta   = array();
	public string $status = 'pending';

	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_order_number(): string {
		return (string) $this->id;
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

	public function save(): int {
		return $this->id;
	}
}

$GLOBALS['co_env'] = array(
	'MERCADOPAGO_CLIENT_ID'             => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET'         => 'secret',
	'MERCADOPAGO_REDIRECT_URI'          => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'           => 'test',
	'MERCADOPAGO_NOTIFICATION_BASE_URL' => 'https://hooks.papelito.example/wp-json/papelito/v1/mercadopago/webhook',
);
$GLOBALS['co_orders']        = array();
$GLOBALS['co_reservations']  = 0;
$GLOBALS['co_releases']      = 0;
$GLOBALS['co_pagarme_calls'] = 0;
$GLOBALS['co_pagarme_state'] = 'paid';
$GLOBALS['co_mp_response']   = null;
$GLOBALS['co_mp_calls']      = 0;
$GLOBALS['co_user']          = 501;
$GLOBALS['co_failed_orders'] = array();
$GLOBALS['co_resolve']       = array( 'provider' => 'mercadopago', 'flow' => 'redirect', 'configurationVersion' => 'cfg-mp', 'fallbackReason' => '', 'legacy' => false );

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
function rest_url( string $path = '' ): string { return 'http://localhost/wp-json/' . $path; }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id(): int { return $GLOBALS['co_user']; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['co_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' === $hook ) {
		++$GLOBALS['co_mp_calls'];
		return $GLOBALS['co_mp_response'];
	}
	return $value;
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }
function wc_get_order( int $id ): ?WC_Order { return $GLOBALS['co_orders'][ $id ] ?? null; }
function papelito_frontend_base_url( string $requested = '' ): string { return 'https://papelito.example'; }
function papelito_pricing_get_installment_config(): array { return array( 'maxInstallments' => 6 ); }
function papelito_pagarme_is_configured(): bool { return true; }
function papelito_pagarme_secret_key(): string { return 'sk_test_x'; }
function papelito_pagarme_get_vendor_recipient_id( int $vendor_id ): string { return 're_vendor'; }
function papelito_vendor_payment_account_current( int $vendor_id, string $provider, string $env ): ?array { return array( 'id' => 3, 'vendor_id' => $vendor_id, 'status' => 'active', 'collector_id' => '900001', 'provider' => 'mercadopago' ); }
function papelito_vendor_payment_account_find( int $id ): ?array { return papelito_vendor_payment_account_current( 40, 'mercadopago', 'test' ); }
function papelito_vendor_payment_account_tokens( array $account ) { return array( 'access_token' => 'APP_USR-vendor', 'refresh_token' => 'TG' ); }
function papelito_vendor_payment_account_update_health( int $id, array $health ): void {}
function papelito_payment_gateway_health_record( int $vendor_id, string $provider ): array { return array(); }
function papelito_payment_gateway_resolve( int $vendor_id, array $options = array() ) {
	if ( in_array( 'mercadopago', (array) ( $options['exclude'] ?? array() ), true ) ) {
		return $GLOBALS['co_fallback_context'] ?? array( 'provider' => 'pagarme', 'flow' => 'direct', 'configurationVersion' => 'cfg-pg', 'fallbackReason' => 'preferred_failed_before_creation', 'legacy' => false );
	}
	return $GLOBALS['co_resolve'];
}
function papelito_order_routing_create_order( int $user_id, array $address, array $lines, array $shipping, ?array $coupon, string $method, array $context ) {
	$id                         = 9000 + count( $GLOBALS['co_orders'] ) + 1;
	$order                      = new WC_Order( $id );
	$order->meta['_papelito_authoritative_total_cents'] = 10000;
	$order->meta['_papelito_checkout_attempt_id']       = $context['checkout_attempt_id'];
	$GLOBALS['co_orders'][ $id ] = $order;
	return array( 'order_id' => $id, 'order_number' => (string) $id, 'status' => 'pending' );
}
function papelito_order_routing_replay_checkout_attempt( int $user_id, string $attempt_id, int $company_id, string $hash, string $commercial ) {
	foreach ( $GLOBALS['co_orders'] as $order ) {
		if ( $attempt_id === $order->meta['_papelito_checkout_attempt_id'] ) {
			$response = papelito_order_routing_existing_order_response( $order );
			return is_wp_error( $response ) ? $response : new WP_REST_Response( $response, 200 );
		}
	}
	return null;
}
function papelito_order_routing_existing_order_response( object $order ) {
	$attempt = papelito_payment_attempt_find( (int) $order->get_meta( PAPELITO_PAYMENT_ATTEMPT_ID_META, true ) );
	if ( null === $attempt || 'creating' === $attempt['state'] ) {
		return new WP_Error( 'papelito_checkout_attempt_in_progress', 'x', array( 'status' => 409 ) );
	}
	return array( 'order_id' => $order->get_id(), 'status' => $order->get_status(), 'payment' => papelito_payment_attempt_public( $attempt ) );
}
function papelito_pagarme_reserve_order_stock( object $order, array $lines ) {
	if ( '1' === $order->get_meta( '_papelito_stock_reserved', true ) ) {
		return true;
	}
	++$GLOBALS['co_reservations'];
	$order->update_meta_data( '_papelito_stock_reserved', '1' );
	return true;
}
function papelito_pagarme_release_order_stock( object $order, array $lines, string $reason = '' ) { ++$GLOBALS['co_releases']; $order->update_meta_data( '_papelito_stock_reserved', '0' ); return true; }
function papelito_order_routing_fail_created_order( object $order, array $lines, string $note, string $release = '' ): void {
	if ( '' !== $release ) {
		papelito_pagarme_release_order_stock( $order, $lines, $release );
	}
	$order->status                 = 'failed';
	$GLOBALS['co_failed_orders'][] = $order->get_id();
}
function papelito_order_routing_order_lines( object $order ): array { return array( array( 'product_id' => 1, 'qty' => 2, 'vendor_id' => 40, 'total' => 80.0, 'product' => null ) ); }
function papelito_order_routing_normalize_payment( mixed $payment, array $address ) {
	if ( ! is_array( $payment ) || ! in_array( $payment['method'] ?? '', array( 'credit_card', 'pix', 'boleto' ), true ) ) {
		return new WP_Error( 'papelito_checkout_invalid_payment', 'x', array( 'status' => 422 ) );
	}
	if ( 'credit_card' === $payment['method'] && '' === (string) ( $payment['card_token_id'] ?? '' ) ) {
		return new WP_Error( 'papelito_checkout_invalid_payment', 'cartão incompleto', array( 'status' => 422 ) );
	}
	return array( 'method' => $payment['method'], 'installments' => 1, 'card_token_id' => (string) ( $payment['card_token_id'] ?? '' ), 'billing_address' => $address );
}
function papelito_order_routing_resolve_checkout_payment( array $payload, array $address, int $total, int $vendor_id ) { return papelito_order_routing_normalize_payment( $payload['payment'] ?? null, $address ); }
function papelito_order_routing_resolve_b2b_snapshot( int $user_id, array $payload ) {
	return 77 === (int) ( $payload['expected_company_id'] ?? 0 ) ? array( 'company_id' => 77 ) : new WP_Error( 'papelito_checkout_company_context_changed', 'x', array( 'status' => 409 ) );
}
function papelito_pagarme_create_order_payment( object $order, int $customer, array $payment, array $address, array $lines, array $shipping ) {
	++$GLOBALS['co_pagarme_calls'];
	if ( 'network' === $GLOBALS['co_pagarme_state'] ) {
		return new WP_Error( 'papelito_pagarme_network_error', 'timeout', array( 'status' => 502 ) );
	}
	if ( 'server_error' === $GLOBALS['co_pagarme_state'] ) {
		return new WP_Error( 'papelito_pagarme_request_failed', 'bad gateway', array( 'status' => 503 ) );
	}
	if ( 'empty_id' === $GLOBALS['co_pagarme_state'] ) {
		return array( 'order_id' => $order->get_id(), 'status' => 'pending', 'payment' => array( 'method' => $payment['method'], 'state' => 'pending' ) );
	}
	$order->update_meta_data( PAPELITO_PAGARME_ORDER_ID_META, 'or_' . $order->get_id() );
	return array( 'order_id' => $order->get_id(), 'status' => 'pending', 'payment' => array( 'method' => $payment['method'], 'state' => $GLOBALS['co_pagarme_state'] ) );
}
function papelito_pagarme_payment_state_releases_stock( string $state ): bool { return in_array( $state, array( 'failed', 'refused' ), true ); }
function papelito_pagarme_payment_state_is_paid( string $state ): bool { return 'paid' === $state; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once __DIR__ . '/support/payment_attempts_memory.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_payments.php';
require_once dirname( __DIR__ ) . '/includes/payment_fallback_policy.php';
require_once dirname( __DIR__ ) . '/includes/payment_checkout.php';

$failures = 0;

function co_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function co_request( string $attempt_id, ?array $payment ): array {
	return array(
		'user_id'             => $GLOBALS['co_user'],
		'payload'             => array( 'payment' => $payment, 'expected_company_id' => 77 ),
		'b2b_snapshot'        => array( 'company_id' => 77, 'company' => array( 'cnpj' => '44555666000199', 'legal_name' => 'Comprador LTDA', 'billing_email' => 'f@c.example' ) ),
		'checkout_attempt_id' => $attempt_id,
		'request_hash'        => 'h-' . $attempt_id,
		'commercial_hash'     => 'c-' . $attempt_id,
		'address'             => array( 'zip_code' => '01001000', 'street' => 'Rua', 'number' => '1', 'complement' => '', 'neighborhood' => 'Sé', 'city' => 'SP', 'state' => 'SP' ),
		'lines'               => array( array( 'product_id' => 1, 'qty' => 2, 'vendor_id' => 40, 'total_cents' => 8000, 'product' => null, 'name' => 'Seda' ) ),
		'shipping'            => array( 'price' => 20.0, 'discount' => 0.0 ),
		'coupon'              => null,
		'pricing'             => array( 'totals' => array( 'totalCents' => 10000 ), 'coupon' => null, 'adjustments' => array() ),
		'vendor_id'           => 40,
		'logistics_snapshot'  => null,
		'context'             => $GLOBALS['co_resolve'],
		'frontend_base'       => 'https://papelito.example',
	);
}

function co_data( mixed $response ): array {
	return $response instanceof WP_REST_Response ? (array) $response->get_data() : array();
}

function co_mp_ok(): array {
	return array( 'response' => array( 'code' => 201 ), 'body' => json_encode( array( 'id' => 'pref-1', 'collector_id' => 900001, 'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1' ) ) );
}

echo "Cenário 1: Mercado Pago apto emite redirect\n";
$GLOBALS['co_mp_response'] = co_mp_ok();
$response = papelito_payment_checkout_place( co_request( 'attempt-mp-1', null ) );
$data     = co_data( $response );
co_assert( 'pedido MP devolve fluxo redirect com link', 'redirect' === ( $data['payment']['flow'] ?? '' ) && 0 === strpos( (string) ( $data['payment']['redirectUrl'] ?? '' ), 'https://www.mercadopago.com.br/' ) );
co_assert( 'pedido MP não exige método de pagamento', 'mercadopago' === ( $data['payment']['provider'] ?? '' ) );
co_assert( 'provider e conta gravados no pedido', 'mercadopago' === $GLOBALS['co_orders'][9001]->meta[ PAPELITO_PAYMENT_PROVIDER_META ] && '900001' === $GLOBALS['co_orders'][9001]->meta[ PAPELITO_PAYMENT_ACCOUNT_REF_META ] );
$replay = co_data( papelito_payment_checkout_place( co_request( 'attempt-mp-1', null ) ) );
co_assert( 'retry do mesmo checkout devolve a mesma tentativa sem nova preferência', 1 === $GLOBALS['co_mp_calls'] && ( $replay['payment']['paymentAttemptId'] ?? 0 ) === ( $data['payment']['paymentAttemptId'] ?? -1 ) );
co_assert( 'um pedido e uma reserva', 1 === count( $GLOBALS['co_orders'] ) && 1 === $GLOBALS['co_reservations'] );

echo "Cenário 2: sem método → MP falha antes de criar → coleta Pagar.me → continuação\n";
$GLOBALS['co_mp_response'] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
$response = papelito_payment_checkout_place( co_request( 'attempt-fb-1', null ) );
$data     = co_data( $response );
$order_id = (int) ( $data['order_id'] ?? 0 );
co_assert( 'falha comprovadamente anterior pede dados de pagamento da alternativa', 'requires_payment_input' === ( $data['payment']['flow'] ?? '' ) && 'pagarme' === ( $data['payment']['provider'] ?? '' ) );
co_assert( 'Pagar.me não foi chamada com dados insuficientes', 0 === $GLOBALS['co_pagarme_calls'] );
co_assert( 'pedido continua com uma reserva só', 2 === count( $GLOBALS['co_orders'] ) && 2 === $GLOBALS['co_reservations'] && 0 === $GLOBALS['co_releases'] );
$attempt_id = (int) $data['payment']['paymentAttemptId'];
$replayed   = co_data( papelito_payment_checkout_place( co_request( 'attempt-fb-1', null ) ) );
co_assert( 'retry do place-order devolve a mesma tentativa aguardando dados', $attempt_id === ( $replayed['payment']['paymentAttemptId'] ?? 0 ) && 2 === count( $GLOBALS['co_orders'] ) );

$GLOBALS['co_user'] = 999;
$intruder = papelito_payment_checkout_continue( new WP_REST_Request( array( 'expectedCompanyId' => 77, 'configurationVersion' => 'cfg-pg', 'payment' => array( 'method' => 'pix' ) ), array( 'paymentAttemptId' => $attempt_id ) ) );
co_assert( 'outro comprador não continua a tentativa', is_wp_error( $intruder ) && 404 === $intruder->get_error_data()['status'] && 0 === $GLOBALS['co_pagarme_calls'] );
$GLOBALS['co_user'] = 501;

$stale = papelito_payment_checkout_continue( new WP_REST_Request( array( 'expectedCompanyId' => 77, 'configurationVersion' => 'velha', 'payment' => array( 'method' => 'pix' ) ), array( 'paymentAttemptId' => $attempt_id ) ) );
co_assert( 'versão de configuração obsoleta pede atualização sem cobrar', is_wp_error( $stale ) && 'papelito_payment_configuration_stale' === $stale->get_error_code() && 0 === $GLOBALS['co_pagarme_calls'] );

$awaiting_snapshot = papelito_payment_checkout_awaiting_snapshot( $GLOBALS['co_orders'][ $order_id ] );
co_assert( 'pedido aguardando dados publica a tentativa para retomar em outro aparelho', is_array( $awaiting_snapshot ) && 'requires_payment_input' === $awaiting_snapshot['flow'] && $attempt_id === $awaiting_snapshot['payment_attempt_id'] && 'cfg-pg' === $awaiting_snapshot['configuration_version'] );

$GLOBALS['co_fallback_context'] = array( 'provider' => 'pagarme', 'flow' => 'direct', 'configurationVersion' => 'cfg-pg-novo', 'fallbackReason' => 'preferred_failed_before_creation', 'legacy' => false );
$changed = papelito_payment_checkout_continue( new WP_REST_Request( array( 'expectedCompanyId' => 77, 'configurationVersion' => 'cfg-pg', 'payment' => array( 'method' => 'pix' ) ), array( 'paymentAttemptId' => $attempt_id ) ) );
co_assert( 'condição mudou mas Pagar.me segue apta: devolve a versão nova sem cobrar', is_wp_error( $changed ) && 'papelito_payment_configuration_stale' === $changed->get_error_code() && 'cfg-pg-novo' === ( $changed->get_error_data()['configurationVersion'] ?? '' ) && 0 === $GLOBALS['co_pagarme_calls'] );
co_assert( 'a tentativa passa a esperar a versão nova', 'cfg-pg-novo' === papelito_payment_attempt_find( $attempt_id )['configuration_version'] );
$GLOBALS['co_fallback_context'] = null;
$GLOBALS['co_fallback_context'] = array( 'provider' => 'pagarme', 'flow' => 'direct', 'configurationVersion' => 'cfg-pg', 'fallbackReason' => 'preferred_failed_before_creation', 'legacy' => false );
papelito_payment_attempt_update( papelito_payment_attempt_find( $attempt_id ), array( 'configuration_version' => 'cfg-pg' ) );

$GLOBALS['co_pagarme_state'] = 'pending';
$continued = co_data( papelito_payment_checkout_continue( new WP_REST_Request( array( 'expectedCompanyId' => 77, 'configurationVersion' => 'cfg-pg', 'payment' => array( 'method' => 'pix' ) ), array( 'paymentAttemptId' => $attempt_id ) ) ) );
co_assert( 'continuação emite a cobrança Pagar.me uma vez', 1 === $GLOBALS['co_pagarme_calls'] && 'pending' === ( $continued['payment']['state'] ?? '' ) );
co_assert( 'continuação não cria outro pedido nem outra reserva', 2 === count( $GLOBALS['co_orders'] ) && 2 === $GLOBALS['co_reservations'] );

$retry = co_data( papelito_payment_checkout_continue( new WP_REST_Request( array( 'expectedCompanyId' => 77, 'configurationVersion' => 'cfg-pg', 'payment' => array( 'method' => 'boleto' ) ), array( 'paymentAttemptId' => $attempt_id ) ) ) );
co_assert( 'retry da continuação devolve o resultado sem nova emissão', 1 === $GLOBALS['co_pagarme_calls'] && 'pending' === ( $retry['payment']['state'] ?? '' ) );
$fresh_place = co_data( papelito_payment_checkout_place( co_request( 'attempt-fb-1', array( 'method' => 'pix' ) ) ) );
co_assert( 'reenviar o place-order com método não reemite', 1 === $GLOBALS['co_pagarme_calls'] && $order_id === (int) ( $fresh_place['order_id'] ?? 0 ) );

$awaiting_attempt = papelito_payment_attempt_find( $attempt_id );
co_assert( 'tentativa aguardando dados nasce com prazo', '' !== (string) ( $awaiting_attempt['expires_at'] ?? '' ) );

echo "Cenário 2a: pedido cancelado pelo admin não é cobrado na continuação\n";
$GLOBALS['co_mp_response'] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
$cancel_case = co_data( papelito_payment_checkout_place( co_request( 'attempt-fb-cancel', null ) ) );
$GLOBALS['co_orders'][ (int) $cancel_case['order_id'] ]->status = 'cancelled';
$calls_before = $GLOBALS['co_pagarme_calls'];
$cancelled    = papelito_payment_checkout_continue( new WP_REST_Request( array( 'expectedCompanyId' => 77, 'configurationVersion' => 'cfg-pg', 'payment' => array( 'method' => 'pix' ) ), array( 'paymentAttemptId' => (int) $cancel_case['payment']['paymentAttemptId'] ) ) );
co_assert( 'pedido fora de pending recusa a continuação sem cobrar', is_wp_error( $cancelled ) && 'papelito_payment_attempt_closed' === $cancelled->get_error_code() && $calls_before === $GLOBALS['co_pagarme_calls'] );
$late_case = co_data( papelito_payment_checkout_place( co_request( 'attempt-fb-late', null ) ) );
papelito_payment_attempt_update( papelito_payment_attempt_find( (int) $late_case['payment']['paymentAttemptId'] ), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
$late = papelito_payment_checkout_continue( new WP_REST_Request( array( 'expectedCompanyId' => 77, 'configurationVersion' => 'cfg-pg', 'payment' => array( 'method' => 'pix' ) ), array( 'paymentAttemptId' => (int) $late_case['payment']['paymentAttemptId'] ) ) );
co_assert( 'coleta fora do prazo é encerrada sem cobrar', is_wp_error( $late ) && 'papelito_payment_attempt_closed' === $late->get_error_code() && $calls_before === $GLOBALS['co_pagarme_calls'] );

echo "Cenário 2b: sem gateway apto na continuação encerra a tentativa e libera a reserva\n";
$GLOBALS['co_fallback_context'] = null;
$GLOBALS['co_mp_response'] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
$closing = co_data( papelito_payment_checkout_place( co_request( 'attempt-fb-close', null ) ) );
$GLOBALS['co_fallback_context'] = new WP_Error( 'papelito_payment_no_gateway_available', 'x', array( 'status' => 409 ) );
$releases = $GLOBALS['co_releases'];
$closed   = papelito_payment_checkout_continue( new WP_REST_Request( array( 'expectedCompanyId' => 77, 'configurationVersion' => 'cfg-pg', 'payment' => array( 'method' => 'pix' ) ), array( 'paymentAttemptId' => (int) $closing['payment']['paymentAttemptId'] ) ) );
co_assert( 'sem gateway apto a tentativa é encerrada com orientação de refazer o pedido', is_wp_error( $closed ) && 'papelito_payment_attempt_closed' === $closed->get_error_code() && 'expired' === papelito_payment_attempt_find( (int) $closing['payment']['paymentAttemptId'] )['state'] );
co_assert( 'encerrar a tentativa libera a reserva', $releases + 1 === $GLOBALS['co_releases'] );
$GLOBALS['co_fallback_context'] = null;

echo "Cenário 3: timeout do MP vira reconciliação, sem fallback\n";
$GLOBALS['co_mp_response'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
$calls    = $GLOBALS['co_pagarme_calls'];
$releases = $GLOBALS['co_releases'];
$unknown  = papelito_payment_checkout_place( co_request( 'attempt-unknown', array( 'method' => 'pix' ) ) );
co_assert( 'timeout responde reconciliação e não cobra no outro gateway', is_wp_error( $unknown ) && 'papelito_payment_attempt_reconciling' === $unknown->get_error_code() && $calls === $GLOBALS['co_pagarme_calls'] );
co_assert( 'pedido em reconciliação mantém a reserva', $releases === $GLOBALS['co_releases'] );

echo "Cenário 4: recusa de payload do MP não troca de gateway\n";
$GLOBALS['co_mp_response'] = array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'error' => 'invalid_items', 'message' => 'x' ) ) );
$refused = papelito_payment_checkout_place( co_request( 'attempt-invalid', array( 'method' => 'pix' ) ) );
co_assert( 'erro de payload falha o pedido e libera a reserva', is_wp_error( $refused ) && in_array( array_key_last( $GLOBALS['co_orders'] ), $GLOBALS['co_failed_orders'], true ) && $releases + 1 === $GLOBALS['co_releases'] && $calls === $GLOBALS['co_pagarme_calls'] );

echo "Cenário 5: Pagar.me preferida com cartão recusado não vira MP\n";
$GLOBALS['co_resolve']       = array( 'provider' => 'pagarme', 'flow' => 'direct', 'configurationVersion' => 'cfg-pg2', 'fallbackReason' => '', 'legacy' => false );
$GLOBALS['co_pagarme_state'] = 'failed';
$mp_calls                    = $GLOBALS['co_mp_calls'];
$card = co_data( papelito_payment_checkout_place( co_request( 'attempt-card', array( 'method' => 'credit_card', 'card_token_id' => 'tok_1' ) ) ) );
co_assert( 'cartão recusado fica na Pagar.me', 'failed' === ( $card['payment']['state'] ?? '' ) && $mp_calls === $GLOBALS['co_mp_calls'] );

echo "Cenário 6: rede Pagar.me incerta vira unknown\n";
$GLOBALS['co_pagarme_state'] = 'network';
$network = papelito_payment_checkout_place( co_request( 'attempt-net', array( 'method' => 'pix' ) ) );
co_assert( 'erro de rede da Pagar.me não falha nem libera o pedido', is_wp_error( $network ) && 'papelito_payment_attempt_reconciling' === $network->get_error_code() && 'pending' === end( $GLOBALS['co_orders'] )->status );

echo "Cenário 6b: 5xx e 2xx sem id da Pagar.me viram unknown\n";
foreach ( array( 'server_error', 'empty_id' ) as $co_uncertain ) {
	$GLOBALS['co_pagarme_state'] = $co_uncertain;
	$co_failed_before            = count( $GLOBALS['co_failed_orders'] );
	$co_uncertain_result         = papelito_payment_checkout_place( co_request( 'attempt-' . $co_uncertain, array( 'method' => 'pix' ) ) );
	$co_uncertain_attempt        = end( $GLOBALS['memory_attempts'] );
	co_assert( "{$co_uncertain} reconcilia sem falhar o pedido", is_wp_error( $co_uncertain_result ) && 'papelito_payment_attempt_reconciling' === $co_uncertain_result->get_error_code() && $co_failed_before === count( $GLOBALS['co_failed_orders'] ) && 'pending' === end( $GLOBALS['co_orders'] )->status && 'unknown' === $co_uncertain_attempt['state'] );
}

echo "Cenário 7: cartão incompleto não cria pedido\n";
$orders = count( $GLOBALS['co_orders'] );
$GLOBALS['co_pagarme_state'] = 'paid';
$invalid_card = papelito_payment_checkout_place( co_request( 'attempt-badcard', array( 'method' => 'credit_card' ) ) );
co_assert( 'dados de cartão incompletos são recusados antes do pedido', is_wp_error( $invalid_card ) && $orders === count( $GLOBALS['co_orders'] ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
