<?php
/**
 * Cliente da Orders API da Point: payload da order, headers permitidos e provider interno.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
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

const POINT_TEST_TOKEN    = 'APP_USR-9999999999-111111-abcdefabcdefabcdefabcdefabcdefab-1813664612';
const POINT_TEST_TERMINAL = 'NEWLAND_N950__N950NCD600451364';
const POINT_TEST_REF      = 'ppl-501-77';

$GLOBALS['point_env']       = array();
$GLOBALS['point_requests']  = array();
$GLOBALS['point_response']  = null;
$GLOBALS['point_transient'] = array();
ini_set( 'error_log', tempnam( sys_get_temp_dir(), 'mpp' ) );

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_filter( mixed ...$args ): bool { return true; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_get_environment_type(): string { return 'local'; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['point_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function get_transient( string $key ): mixed { return $GLOBALS['point_transient'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['point_transient'][ $key ] = $value; return true; }
function papelito_pricing_quote( mixed ...$args ): array { return array(); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' === $hook ) {
		$GLOBALS['point_requests'][] = array( 'method' => $args[0], 'url' => $args[1], 'args' => $args[2] );
		return $GLOBALS['point_response'];
	}
	return $value;
}
function wp_remote_request( string $url, array $args ): mixed { throw new RuntimeException( 'rede real proibida no teste' ); }
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_point.php';
require_once dirname( __DIR__ ) . '/includes/order_routing.php';

$failures = 0;

function point_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

function point_response( int $code, array $body ): array {
	return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
}

function point_last_request(): array {
	return $GLOBALS['point_requests'][ count( $GLOBALS['point_requests'] ) - 1 ];
}

function point_reset(): void {
	$GLOBALS['point_env']      = array( 'PAPELITO_MERCADOPAGO_POINT_ACCESS_TOKEN' => POINT_TEST_TOKEN );
	$GLOBALS['point_requests'] = array();
	$GLOBALS['point_response'] = point_response( 201, array( 'id' => 'ORD01', 'status' => 'at_terminal' ) );
}

function point_attempt(): array {
	return array(
		'id'         => 77,
		'order_id'   => 501,
		'attempt_ref' => 'att_abc123',
	);
}

function test_create_order_payload_card(): void {
	point_reset();
	$result  = papelito_mercadopago_point_create_order( point_attempt(), 123456, POINT_TEST_TERMINAL, 'card', 'Pedido 501 ExpoCannabis' );
	$request = point_last_request();
	$body    = json_decode( (string) $request['args']['body'], true );
	point_assert( 'devolve a order criada', is_array( $result ) && 'ORD01' === $result['id'] );
	point_assert( 'POST em /v1/orders', 'POST' === $request['method'] && 'https://api.mercadopago.com/v1/orders' === $request['url'] );
	point_assert( 'usa o token da Point', 'Bearer ' . POINT_TEST_TOKEN === $request['args']['headers']['Authorization'] );
	point_assert( 'chave de idempotência é a referência da tentativa', 'att_abc123' === $request['args']['headers']['X-Idempotency-Key'] );
	point_assert( 'tipo point', 'point' === $body['type'] );
	point_assert( 'valor em string com duas casas', '1234.56' === $body['transactions']['payments'][0]['amount'] );
	point_assert( 'referência externa com pedido e tentativa', POINT_TEST_REF === $body['external_reference'] );
	point_assert( 'expira em 5 minutos', 'PT5M' === $body['expiration_time'] );
	point_assert( 'terminal configurado', POINT_TEST_TERMINAL === $body['config']['point']['terminal_id'] );
	point_assert( 'imprime o comprovante do vendedor', 'seller_ticket' === $body['config']['point']['print_on_terminal'] );
	point_assert( 'cartão não força forma de pagamento', ! isset( $body['config']['payment_method'] ) );
	point_assert( 'descrição vai junto', 'Pedido 501 ExpoCannabis' === $body['description'] );
}

function test_create_order_payload_pix(): void {
	point_reset();
	papelito_mercadopago_point_create_order( point_attempt(), 5000, POINT_TEST_TERMINAL, 'pix', 'Pedido 501' );
	$body = json_decode( (string) point_last_request()['args']['body'], true );
	point_assert( 'Pix abre o QR na máquina', 'qr' === $body['config']['payment_method']['default_type'] );
	point_assert( 'cinquenta reais', '50.00' === $body['transactions']['payments'][0]['amount'] );
}

function test_cancel_sends_allowlisted_header(): void {
	point_reset();
	papelito_mercadopago_point_cancel_order( 'ORD01', 'cancel-att_abc123' );
	$request = point_last_request();
	point_assert( 'cancela pela rota da order', 'https://api.mercadopago.com/v1/orders/ORD01/cancel' === $request['url'] && 'POST' === $request['method'] );
	point_assert( 'permite cancelar com a order na máquina', 'at_terminal' === ( $request['args']['headers']['x-allow-cancelable-status'] ?? '' ) );
	point_assert( 'cancelamento leva chave de idempotência própria', 'cancel-att_abc123' === ( $request['args']['headers']['X-Idempotency-Key'] ?? '' ) );
}

function test_unknown_header_dropped(): void {
	point_reset();
	papelito_mercadopago_request(
		'GET',
		'/v1/orders/ORD01',
		array(
			'token'   => POINT_TEST_TOKEN,
			'headers' => array(
				'Authorization'             => 'Bearer outro',
				'X-Forwarded-Host'          => 'evil.test',
				'X-Allow-Cancelable-Status' => 'at_terminal',
			),
		)
	);
	$headers = point_last_request()['args']['headers'];
	point_assert( 'header fora da lista é descartado', ! isset( $headers['X-Forwarded-Host'] ) );
	point_assert( 'Authorization não é sobrescrito', 'Bearer ' . POINT_TEST_TOKEN === $headers['Authorization'] );
	point_assert( 'header permitido entra em minúsculas', 'at_terminal' === ( $headers['x-allow-cancelable-status'] ?? '' ) );
}

function test_provider_valid_but_not_in_vendor_list(): void {
	point_assert( 'Point é provider válido', papelito_payment_provider_is_valid( 'mercadopago_point' ) );
	point_assert( 'Point não aparece para vendor', ! in_array( 'mercadopago_point', papelito_payment_providers(), true ) );
	point_assert( 'provider desconhecido continua inválido', ! papelito_payment_provider_is_valid( 'stripe' ) );
	point_assert( 'rótulo do método', 'Maquininha Mercado Pago' === papelito_order_routing_payment_method_label( 'mercadopago_point' ) );
}

function test_orders_api_error_code_is_read(): void {
	point_reset();
	$GLOBALS['point_response'] = point_response( 409, array( 'errors' => array( array( 'code' => 'already_queued_order_for_terminal', 'message' => 'There is already a queued order' ) ) ) );
	$result                    = papelito_mercadopago_point_create_order( point_attempt(), 1000, POINT_TEST_TERMINAL, 'card', 'Pedido' );
	point_assert( 'código do erro da Orders API é lido', is_wp_error( $result ) && 'already_queued_order_for_terminal' === $result->get_error_data()['remote_code'] );
}

function test_token_and_collector(): void {
	point_reset();
	$GLOBALS['point_env'] = array();
	$missing              = papelito_mercadopago_point_token();
	point_assert( 'sem token é erro de configuração', is_wp_error( $missing ) && 'configuration' === $missing->get_error_data()['category'] );

	point_reset();
	$GLOBALS['point_response'] = point_response( 200, array( 'id' => 1813664612 ) );
	$first                     = papelito_mercadopago_point_collector_id();
	$second                    = papelito_mercadopago_point_collector_id();
	point_assert( 'collector vem de /users/me', '1813664612' === $first && '1813664612' === $second );
	point_assert( 'collector fica em cache', 1 === count( $GLOBALS['point_requests'] ) );
}

function test_terminal_mode_and_list(): void {
	point_reset();
	papelito_mercadopago_point_set_mode( POINT_TEST_TERMINAL, 'PDV' );
	$request = point_last_request();
	$body    = json_decode( (string) $request['args']['body'], true );
	point_assert( 'modo pelo PATCH de setup', 'PATCH' === $request['method'] && 'https://api.mercadopago.com/terminals/v1/setup' === $request['url'] );
	point_assert( 'corpo do setup', POINT_TEST_TERMINAL === $body['terminals'][0]['id'] && 'PDV' === $body['terminals'][0]['operating_mode'] );
	point_assert( 'modo inválido é recusado', is_wp_error( papelito_mercadopago_point_set_mode( POINT_TEST_TERMINAL, 'OUTRO' ) ) );

	papelito_mercadopago_point_list_terminals();
	point_assert( 'lista terminais', 0 === strpos( point_last_request()['url'], 'https://api.mercadopago.com/terminals/v1/list' ) );

	papelito_mercadopago_point_refund_order( 'ORD01', 'refund-77' );
	$refund = point_last_request();
	point_assert( 'estorno pela rota da order com chave própria', 'https://api.mercadopago.com/v1/orders/ORD01/refund' === $refund['url'] && 'refund-77' === $refund['args']['headers']['X-Idempotency-Key'] );
}

function test_payment_fees(): void {
	point_reset();
	$GLOBALS['point_response'] = point_response( 200, array( 'id' => 123456789, 'transaction_amount' => 1234.56, 'fee_details' => array( array( 'amount' => 40.12 ), array( 'amount' => 3.09 ) ), 'transaction_details' => array( 'net_received_amount' => 1191.35 ) ) );
	$fees                      = papelito_mercadopago_point_payment_fees( '123456789' );
	point_assert( 'taxa soma os fee_details', 4321 === $fees['fee_cents'] );
	point_assert( 'líquido vem do net_received_amount', 119135 === $fees['net_cents'] );
	point_assert( 'consulta o pagamento pelo id', 'https://api.mercadopago.com/v1/payments/123456789' === point_last_request()['url'] );

	point_reset();
	$empty = papelito_mercadopago_point_payment_fees( '' );
	point_assert( 'sem id não consulta e fica a conferir', null === $empty['fee_cents'] && null === $empty['net_cents'] && array() === $GLOBALS['point_requests'] );

	$GLOBALS['point_response'] = point_response( 500, array() );
	$failed                    = papelito_mercadopago_point_payment_fees( '123' );
	point_assert( 'falha na consulta fica a conferir', null === $failed['fee_cents'] );
}

test_create_order_payload_card();
test_create_order_payload_pix();
test_cancel_sends_allowlisted_header();
test_unknown_header_dropped();
test_provider_valid_but_not_in_vendor_list();
test_orders_api_error_code_is_read();
test_token_and_collector();
test_terminal_mode_and_list();
test_payment_fees();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
