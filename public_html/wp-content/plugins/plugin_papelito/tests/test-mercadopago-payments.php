<?php
/**
 * Checkout Pro: payload da preferência, recebedor, totais e estados da emissão.
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

$GLOBALS['mpp_env']       = array(
	'MERCADOPAGO_CLIENT_ID'              => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET'          => 'secret',
	'MERCADOPAGO_REDIRECT_URI'           => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'            => 'test',
	'MERCADOPAGO_NOTIFICATION_BASE_URL'  => 'https://hooks.papelito.example/wp-json/papelito/v1/mercadopago/webhook',
);
$GLOBALS['mpp_requests']  = array();
$GLOBALS['mpp_response']  = null;
$GLOBALS['mpp_health']    = array();
$GLOBALS['mpp_accounts']  = array(
	3 => array( 'id' => 3, 'vendor_id' => 40, 'provider' => 'mercadopago', 'environment' => 'test', 'status' => 'active', 'collector_id' => '900001' ),
);

const MPP_TEST_PLAIN_SCHEME = 'http';

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): void { $GLOBALS['mpp_actions'][] = $args[0]; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function esc_url_raw( mixed $value ): string { return (string) $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_get_environment_type(): string { return 'local'; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function add_query_arg( array $args, string $url ): string { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function rest_url( string $path = '' ): string { return MPP_TEST_PLAIN_SCHEME . '://localhost:8080/wp-json/' . $path; }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['mpp_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' === $hook ) {
		$GLOBALS['mpp_requests'][] = array( 'method' => $args[0], 'url' => $args[1], 'args' => $args[2] );
		return $GLOBALS['mpp_response'];
	}
	return $value;
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }
function papelito_vendor_payment_account_find( int $id ): ?array { return $GLOBALS['mpp_accounts'][ $id ] ?? null; }
function papelito_vendor_payment_account_tokens( array $account ) { return array( 'access_token' => 'APP_USR-vendor-' . $account['vendor_id'], 'refresh_token' => 'TG' ); }
function papelito_vendor_payment_account_update_health( int $id, array $health ): void { $GLOBALS['mpp_health'][ $id ] = $health; }
function papelito_payment_gateway_health_record( int $vendor_id, string $provider ): array { return array(); }

$GLOBALS['mpp_test_vendors'] = array();
function papelito_vendor_is_test_vendor( int $vendor_id ): bool { return in_array( $vendor_id, $GLOBALS['mpp_test_vendors'], true ); }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once __DIR__ . '/support/payment_attempts_memory.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_payments.php';

const MPP_REDIRECT = 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1';

$failures = 0;

function mpp_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function mpp_order_data( array $overrides = array() ): array {
	return array_merge(
		array(
			'order_id'       => 700,
			'total_cents'    => 15790,
			'shipping_cents' => 1990,
			'lines'          => array(
				array( 'product_id' => 11, 'name' => 'Seda Slim', 'qty' => 3, 'total_cents' => 10001 ),
				array( 'product_id' => 12, 'name' => 'Piteira', 'qty' => 1, 'total_cents' => 3799 ),
				array( 'product_id' => 13, 'name' => 'Brinde', 'qty' => 1, 'total_cents' => 0 ),
			),
			'payer'          => array( 'legal_name' => 'Comprador LTDA', 'email' => 'fiscal@comprador.example', 'cnpj' => '44.555.666/0001-99' ),
			'frontend_base'  => 'https://papelito.example',
			'max_installments' => 6,
		),
		$overrides
	);
}

function mpp_attempt( array $overrides = array() ): array {
	return papelito_payment_attempt_create(
		array_merge(
			array(
				'order_id'     => 700,
				'vendor_id'    => 40,
				'provider'     => 'mercadopago',
				'environment'  => 'test',
				'account_id'   => 3,
				'account_ref'  => '900001',
				'amount_cents' => 15790,
				'flow'         => 'redirect',
			),
			$overrides
		)
	);
}

function mpp_preference_response( array $overrides = array() ): array {
	return array(
		'response' => array( 'code' => 201 ),
		'body'     => json_encode( array_merge( array( 'id' => 'pref-1', 'collector_id' => 900001, 'init_point' => MPP_REDIRECT, 'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/x' ), $overrides ) ),
	);
}

$attempt = mpp_attempt();
$payload = papelito_mercadopago_preference_payload( mpp_order_data(), $attempt );
$sum     = 0;
foreach ( $payload['items'] as $item ) {
	$sum += (int) round( $item['unit_price'] * 100 ) * $item['quantity'];
}
mpp_assert( 'soma dos itens bate exatamente com o total cobrado', 15790 === $sum );
mpp_assert( 'frete entra como item e não em shipments', in_array( 'Frete', array_column( $payload['items'], 'title' ), true ) && ! isset( $payload['shipments'] ) );
mpp_assert( 'linha zerada não vira item', 2 + 1 === count( $payload['items'] ) );
mpp_assert( 'quantidade agregada fica no título, com quantity 1', '3 x Seda Slim' === $payload['items'][0]['title'] && 1 === $payload['items'][0]['quantity'] );
mpp_assert( 'comissão da Papelito é zero', 0 === $payload['marketplace_fee'] );
mpp_assert( 'payer fiscal é o CNPJ do snapshot', 'CNPJ' === $payload['payer']['identification']['type'] && '44555666000199' === $payload['payer']['identification']['number'] );
mpp_assert( 'referência externa é estável e curta', 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $payload['external_reference'] ) && $payload['external_reference'] === papelito_mercadopago_external_reference( $attempt ) );
mpp_assert( 'notification URL vem do servidor e carrega attemptRef', 0 === strpos( $payload['notification_url'], 'https://hooks.papelito.example/' ) && false !== strpos( $payload['notification_url'], $attempt['attempt_ref'] ) );
mpp_assert( 'back URLs apontam para o pedido no frontend configurado', 'https://papelito.example/checkout/pagamento/700?retorno=mercadopago' === $payload['back_urls']['success'] );
mpp_assert( 'preferência expira e PIX/boleto têm vencimento próprio', true === $payload['expires'] && isset( $payload['expiration_date_to'], $payload['date_of_expiration'] ) );
mpp_assert( 'parcelamento respeita o limite configurado', 6 === $payload['payment_methods']['installments'] );

$mpp_without_base = papelito_mercadopago_preference_payload( mpp_order_data( array( 'frontend_base' => '' ) ), $attempt );
mpp_assert( 'sem base do frontend não manda retorno relativo nem auto_return', ! isset( $mpp_without_base['back_urls'] ) && ! isset( $mpp_without_base['auto_return'] ) );

mpp_assert( 'em teste o pagador não leva e-mail real, que o sandbox recusa junto de vendedor de teste', ! isset( $payload['payer']['email'] ) && 'CNPJ' === $payload['payer']['identification']['type'] );
$GLOBALS['mpp_env']['MERCADOPAGO_ENVIRONMENT'] = 'live';
$mpp_live = papelito_mercadopago_preference_payload( mpp_order_data(), $attempt );
mpp_assert( 'em produção o pagador leva o e-mail fiscal da empresa', 'fiscal@comprador.example' === ( $mpp_live['payer']['email'] ?? '' ) );
$GLOBALS['mpp_test_vendors'] = array( 40 );
$mpp_test_vendor = papelito_mercadopago_preference_payload( mpp_order_data(), $attempt );
mpp_assert( 'vendor de teste em produção não manda e-mail real, que o MP recusa junto de conta de teste', ! isset( $mpp_test_vendor['payer']['email'] ) );
$GLOBALS['mpp_test_vendors'] = array();
$GLOBALS['mpp_env']['MERCADOPAGO_ENVIRONMENT'] = 'test';

$mpp_local = papelito_mercadopago_preference_payload( mpp_order_data( array( 'frontend_base' => MPP_TEST_PLAIN_SCHEME . '://localhost:3000' ) ), $attempt );
mpp_assert( 'retorno sem HTTPS mantém back_urls mas não pede auto_return, que o MP recusa', isset( $mpp_local['back_urls']['success'] ) && ! isset( $mpp_local['auto_return'] ) );
mpp_assert( 'retorno HTTPS continua com auto_return', 'approved' === ( $payload['auto_return'] ?? '' ) );

$mismatch = papelito_mercadopago_preference_payload( mpp_order_data( array( 'total_cents' => 15791 ) ), $attempt );
mpp_assert( 'divergência de centavos recusa a emissão', is_wp_error( $mismatch ) && 'papelito_checkout_total_mismatch' === $mismatch->get_error_code() );

$GLOBALS['mpp_env']['MERCADOPAGO_NOTIFICATION_BASE_URL'] = '';
$local = papelito_mercadopago_preference_payload( mpp_order_data(), $attempt );
mpp_assert( 'sem URL HTTPS de notificação a preferência sai sem notification_url (reconciliação cobre)', ! isset( $local['notification_url'] ) );
$GLOBALS['mpp_env']['MERCADOPAGO_NOTIFICATION_BASE_URL'] = 'https://hooks.papelito.example/wp-json/papelito/v1/mercadopago/webhook';

$GLOBALS['mpp_response'] = mpp_preference_response();
$created = papelito_mercadopago_create_preference( $attempt, mpp_order_data() );
$sent    = end( $GLOBALS['mpp_requests'] );
mpp_assert( 'emissão usa o token OAuth do vendor da tentativa', 'Bearer APP_USR-vendor-40' === $sent['args']['headers']['Authorization'] );
mpp_assert( 'emissão vai ao endpoint de preferences', 'https://api.mercadopago.com/checkout/preferences' === $sent['url'] );
mpp_assert( 'sucesso deixa a tentativa pendente com link validado', 'pending' === $created['state'] && MPP_REDIRECT === $created['redirect_url'] && 'pref-1' === $created['remote_id'] );
mpp_assert( 'link de produção (init_point) é o usado, nunca sandbox_init_point', false === strpos( $created['redirect_url'], 'sandbox' ) );

$again = papelito_mercadopago_create_preference( papelito_payment_attempt_find( $created['id'] ), mpp_order_data() );
mpp_assert( 'tentativa já emitida não emite de novo', count( $GLOBALS['mpp_requests'] ) === 1 && 'pending' === $again['state'] );

$timeout = mpp_attempt( array( 'order_id' => 701 ) );
$GLOBALS['mpp_response'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
$result  = papelito_mercadopago_create_preference( $timeout, mpp_order_data( array( 'order_id' => 701 ) ) );
mpp_assert( 'timeout após envio vira unknown', 'unknown' === $result['state'] && '' !== (string) $result['next_reconcile_at'] );

$unavailable = mpp_attempt( array( 'order_id' => 702 ) );
$GLOBALS['mpp_response'] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
$result = papelito_mercadopago_create_preference( $unavailable, mpp_order_data( array( 'order_id' => 702 ) ) );
mpp_assert( 'falha de conexão antes do envio é failed sem criação, apta a fallback', 'failed' === $result['state'] && 'unavailable' === $result['last_error_code'] && '' === $result['remote_id'] );

$invalid = mpp_attempt( array( 'order_id' => 703 ) );
$GLOBALS['mpp_response'] = array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'error' => 'invalid_items', 'message' => 'unit_price invalid' ) ) );
$result = papelito_mercadopago_create_preference( $invalid, mpp_order_data( array( 'order_id' => 703 ) ) );
mpp_assert( 'erro de payload é failed sem fallback', 'failed' === $result['state'] && 'validation' === $result['last_error_code'] );

$regulation = mpp_attempt( array( 'order_id' => 704 ) );
$GLOBALS['mpp_response'] = array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'error' => 'collector_does_not_comply_with_current_regulation', 'message' => 'collector_does_not_comply_with_current_regulation' ) ) );
$result = papelito_mercadopago_create_preference( $regulation, mpp_order_data( array( 'order_id' => 704 ) ) );
mpp_assert( 'pendência regulatória do vendedor marca a conta como kyc_pending', 'failed' === $result['state'] && 'kyc_pending' === ( $GLOBALS['mpp_health'][3]['health_status'] ?? '' ) );

$foreign = mpp_attempt( array( 'order_id' => 705 ) );
$GLOBALS['mpp_response'] = mpp_preference_response( array( 'collector_id' => 123 ) );
$result = papelito_mercadopago_create_preference( $foreign, mpp_order_data( array( 'order_id' => 705 ) ) );
mpp_assert( 'preferência de outro collector não é entregue ao comprador', 'unknown' === $result['state'] && '' === (string) $result['redirect_url'] );

$phishing = mpp_attempt( array( 'order_id' => 706 ) );
$GLOBALS['mpp_response'] = mpp_preference_response( array( 'init_point' => 'https://evil.example/pay' ) );
$result = papelito_mercadopago_create_preference( $phishing, mpp_order_data( array( 'order_id' => 706 ) ) );
mpp_assert( 'link fora do domínio Mercado Pago não é entregue', 'unknown' === $result['state'] && '' === (string) $result['redirect_url'] );

mpp_assert( 'host oficial é aceito como link', papelito_mercadopago_redirect_is_allowed( MPP_REDIRECT ) );
mpp_assert( 'http não é aceito', ! papelito_mercadopago_redirect_is_allowed( MPP_TEST_PLAIN_SCHEME . '://www.mercadopago.com.br/x' ) );
mpp_assert( 'domínio parecido não é aceito', ! papelito_mercadopago_redirect_is_allowed( 'https://mercadopago.com.br.evil.example/x' ) );

$GLOBALS['mpp_accounts'][3]['status'] = 'revoked';
$no_account = mpp_attempt( array( 'order_id' => 707 ) );
$requests   = count( $GLOBALS['mpp_requests'] );
$result     = papelito_mercadopago_create_preference( $no_account, mpp_order_data( array( 'order_id' => 707 ) ) );
mpp_assert( 'conta revogada não emite e não usa conta global', 'failed' === $result['state'] && count( $GLOBALS['mpp_requests'] ) === $requests );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
