<?php
/**
 * Pedido do estande, cobrança na Point e reconciliação, com tentativas em memória e Mercado Pago simulado.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

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

	public function header( string $name, string $value ): void {
		$this->headers[ $name ] = $value;
	}

	public function get_data(): mixed {
		return $this->data;
	}
}

class WP_REST_Request {
	public function __construct( private array $params = array(), private array $body = array() ) {}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}

	public function get_json_params(): array {
		return $this->body;
	}
}

class WP_User {
	public function __construct( public int $ID = 0 ) {}
}

class Event_Test_Order {
	public array $meta   = array();
	public array $notes  = array();
	public string $status = 'pending';

	public function __construct( public int $id ) {}

	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return (string) $this->id; }
	public function get_status(): string { return $this->status; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function add_order_note( string $note ): int { $this->notes[] = $note; return count( $this->notes ); }
	public function save(): int { return $this->id; }
}

class Event_Test_Product {
	public function __construct( public int $id ) {}
	public function get_id(): int { return $this->id; }
}

const PAPELITO_MERCADOPAGO_POINT_PROVIDER = 'mercadopago_point';
const EVENT_ORDERS_TEST_FP        = 'fingerprint-da-cotacao';
const EVENT_ORDERS_TEST_REMOTE    = 'ORD01EVENTO';
const EVENT_ORDERS_TEST_COLLECTOR = '1813664612';
const EVENT_ORDERS_TEST_TOTAL     = 123456;
const EVENT_ORDERS_TEST_EVENT     = 'expocannabis-2026';
const EVENT_ORDERS_TEST_CHECKOUT  = 'chk-0001';
const EVENT_ORDERS_TEST_ATTEMPT_OPEN = 'papelito_event_attempt_open';

$GLOBALS['event_env']      = array();
$GLOBALS['event_orders']   = array();
$GLOBALS['event_user']     = 7;
$GLOBALS['event_caps']     = array( 'papelito_event_sell' => true );
$GLOBALS['event_mp']       = array();
$GLOBALS['event_mp_calls'] = array();
$GLOBALS['event_paid']     = array();
$GLOBALS['event_unpaid']   = array();
$GLOBALS['event_reserved'] = array();
$GLOBALS['event_ledger']   = array();
$GLOBALS['event_quote_fp'] = EVENT_ORDERS_TEST_FP;
$GLOBALS['event_registry'] = 'active';
$GLOBALS['event_reversals'] = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): void { $GLOBALS['event_actions'][] = $args; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function esc_url_raw( string $url ): string { return $url; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['event_env'][ $key ] ?? $default; }
function get_current_user_id(): int { return $GLOBALS['event_user']; }
function wp_get_current_user(): WP_User { return new WP_User( $GLOBALS['event_user'] ); }
function user_can( WP_User $user, string $cap ): bool { return ! empty( $GLOBALS['event_caps'][ $cap ] ); }
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['event_caps'][ $cap ] ); }
function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed { return 'store_name' === $key ? 'Cifal' : ''; }
function wc_get_product( mixed $id ): Event_Test_Product { return new Event_Test_Product( (int) $id ); }
function wc_get_order( mixed $id ): ?Event_Test_Order { return $GLOBALS['event_orders'][ (int) $id ] ?? null; }
function wc_get_orders( array $args ): array {
	$found = array();
	foreach ( array_reverse( $GLOBALS['event_orders'], true ) as $order ) {
		if ( isset( $args['meta_key'] ) && ( $order->meta[ $args['meta_key'] ] ?? null ) !== $args['meta_value'] ) {
			continue;
		}
		$found[] = $order;
	}
	return array_slice( $found, 0, (int) ( $args['limit'] ?? 10 ) );
}
function papelito_company_get( int $id ): ?array {
	return 30 === $id ? array( 'id' => 30, 'cnpj' => '11222333000181', 'legal_name' => 'Loja', 'trade_name' => 'Loja', 'billing_email' => 'loja@exemplo.com.br', 'phone' => '11987654321', 'fiscal_cep' => '01001000', 'fiscal_state' => 'SP', 'fiscal_city' => 'São Paulo', 'fiscal_street' => 'Praça da Sé', 'fiscal_number' => '', 'fiscal_neighborhood' => '', 'fiscal_complement' => '', 'company_status' => 'onboarding', 'registry_status' => 'active', 'ownership_status' => 'pending', 'provider_checked_at' => '', 'provider_data_hash' => '', 'billing_email_verified_at' => '' ) : null;
}
function papelito_company_member_get( int $company_id, int $user_id ): ?array {
	return 30 === $company_id && 40 === $user_id ? array( 'id' => 50, 'member_role' => 'buyer', 'member_status' => 'pending_company_approval', 'approved_at' => '', 'expires_at' => '' ) : null;
}
function papelito_event_sales_quote( array $input ): array|WP_Error {
	$GLOBALS['event_quote_inputs'][] = $input;
	return array(
		'units'            => 22,
		'level'            => array( 'key' => 'pdv_perfeito', 'name' => 'PDV Perfeito', 'min_units' => 20, 'pct_bp' => 800 ),
		'lines'            => array( array( 'product_id' => 200, 'qty' => 14, 'unit_cents' => 11162, 'subtotal_cents' => 156268, 'discount_cents' => 12501, 'total_cents' => 143767, 'source' => 'event_level' ) ),
		'kit'              => array( 'qty' => 1, 'unit_cents' => 65595, 'full_unit_cents' => 77170, 'total_cents' => 65595 ),
		'kitComponents'    => array( array( 'product_id' => 101, 'unit_cents' => 15317, 'price_cents' => 18020 ) ),
		'itemsCents'       => 209362,
		'discountCents'    => 12501,
		'kitSavingsCents'  => 11575,
		'shipping'         => array( 'optionKey' => 'correios:pac', 'name' => 'PAC', 'priceCents' => 2990, 'deliveryTime' => 5, 'fingerprint' => 'fp', 'raw' => array( 'provider' => 'correios', 'option_key' => 'correios:pac', 'price' => 29.9, 'customer_price_cents' => 2990 ) ),
		'totalCents'       => EVENT_ORDERS_TEST_TOTAL,
		'quoteFingerprint' => $GLOBALS['event_quote_fp'],
	);
}
function papelito_event_sales_public_quote( array $quote ): array { unset( $quote['shipping']['raw'] ); return $quote; }
function papelito_event_sales_respond( array|WP_Error $result ): WP_REST_Response|WP_Error { return is_wp_error( $result ) ? $result : new WP_REST_Response( $result ); }
function papelito_event_sales_json_body( WP_REST_Request $request ): array { return $request->get_json_params(); }
function papelito_order_routing_create_order( int $user_id, array $address, array $lines, array $shipping, ?array $coupon, string $payment_method, array $context = array() ): array {
	$id                                                = 500 + count( $GLOBALS['event_orders'] ) + 1;
	$order                                             = new Event_Test_Order( $id );
	$order->meta['_papelito_checkout_attempt_id']      = $context['checkout_attempt_id'] ?? '';
	$order->meta['_papelito_authoritative_total_cents'] = array_sum( array_column( $lines, 'total_cents' ) ) + (int) $shipping['customer_price_cents'];
	$GLOBALS['event_orders'][ $id ]                    = $order;
	$GLOBALS['event_created'][]                        = compact( 'user_id', 'address', 'lines', 'shipping', 'payment_method', 'context' );
	return array( 'order_id' => $id, 'order_number' => (string) $id, 'status' => 'pending' );
}
function papelito_pagarme_reserve_order_stock( object $order, array $lines ): bool { $GLOBALS['event_reserved'][] = $order->get_id(); return true; }
function papelito_payment_order_mark_paid( object $order, string $provider ): bool {
	if ( '1' === $order->get_meta( 'paid' ) ) {
		return false;
	}
	$order->meta['paid'] = '1';
	$order->status       = 'processing';
	$GLOBALS['event_paid'][] = $order->get_id();
	return true;
}
function papelito_payment_order_mark_unpaid( object $order, string $reason ): bool { $order->status = 'cancelled'; $GLOBALS['event_unpaid'][] = array( $order->get_id(), $reason ); return true; }
function papelito_event_sales_settlement_has( int $attempt_id, string $kind ): bool { return isset( $GLOBALS['event_ledger'][ $attempt_id . ':' . $kind ] ); }
function papelito_order_refund_handle_psp_reversal( object $order, string $state ): void { $GLOBALS['event_reversals'][] = array( $order->get_id(), $state ); }
function papelito_event_sales_lookup_buyer( string $raw_cnpj ): array|WP_Error {
	return 'active' === $GLOBALS['event_registry'] ? array( 'cnpj' => $raw_cnpj ) : new WP_Error( 'papelito_event_cnpj_inactive', 'Esse CNPJ está inativo na Receita.', array( 'status' => 422 ) );
}
function papelito_event_sales_settlement_record( array $row ): bool {
	$key                            = $row['attempt_id'] . ':' . $row['kind'];
	$GLOBALS['event_ledger'][ $key ] = $GLOBALS['event_ledger'][ $key ] ?? $row;
	return true;
}
function papelito_mercadopago_environment(): string { return 'test'; }
function papelito_mercadopago_point_collector_id(): string|WP_Error {
	return empty( $GLOBALS['event_collector_down'] ) ? EVENT_ORDERS_TEST_COLLECTOR : new WP_Error( 'papelito_mercadopago_configuration', 'sem credencial', array( 'status' => 503, 'category' => 'configuration' ) );
}
function event_mp_next( string $name ): array|WP_Error {
	$queue = $GLOBALS['event_mp'][ $name ] ?? array();
	$next  = array_shift( $queue );
	$GLOBALS['event_mp'][ $name ] = $queue;
	return $next ?? new WP_Error( 'papelito_mercadopago_unavailable', 'sem resposta', array( 'category' => 'unavailable' ) );
}
function papelito_mercadopago_point_create_order( array $attempt, int $amount_cents, string $terminal_id, string $method, string $description ): array|WP_Error {
	$GLOBALS['event_mp_calls'][] = array( 'create', $attempt['attempt_ref'], $amount_cents, $terminal_id, $method );
	return event_mp_next( 'create' );
}
function papelito_mercadopago_point_get_order( string $order_id ): array|WP_Error {
	$GLOBALS['event_mp_calls'][] = array( 'get', $order_id );
	return event_mp_next( 'get' );
}
function papelito_mercadopago_point_cancel_order( string $order_id, string $idempotency_key = '' ): array|WP_Error {
	$GLOBALS['event_mp_calls'][] = array( 'cancel', $order_id, $idempotency_key );
	return array( 'id' => $order_id, 'status' => 'at_terminal' );
}
function papelito_mercadopago_point_payment_fees( string $payment_id ): array {
	return '' === $payment_id ? array( 'fee_cents' => null, 'net_cents' => null ) : array( 'fee_cents' => 4321, 'net_cents' => EVENT_ORDERS_TEST_TOTAL - 4321 );
}
function papelito_mercadopago_point_external_reference( array $attempt ): string { return sprintf( 'ppl-%d-%d', (int) $attempt['order_id'], (int) $attempt['id'] ); }
function papelito_mercadopago_point_amount( int $amount_cents ): string { return sprintf( '%d.%02d', intdiv( $amount_cents, 100 ), $amount_cents % 100 ); }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once __DIR__ . '/support/payment_attempts_memory.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_config.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_reconcile.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_orders.php';

$failures = 0;

function event_orders_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

function event_orders_reset(): void {
	$GLOBALS['event_env']          = array(
		'PAPELITO_EVENT_SALES_VENDOR_ID'       => '2163',
		'PAPELITO_EVENT_SALES_TERMINAL_ID'     => 'NEWLAND_N950__SBX0000001,NEWLAND_N950__SBX0000002',
		'PAPELITO_EVENT_SALES_KIT_PRODUCT_IDS' => '101,102,103,104',
	);
	$GLOBALS['event_orders']       = array();
	$GLOBALS['event_created']      = array();
	$GLOBALS['event_mp']           = array();
	$GLOBALS['event_mp_calls']     = array();
	$GLOBALS['event_paid']         = array();
	$GLOBALS['event_unpaid']       = array();
	$GLOBALS['event_reserved']     = array();
	$GLOBALS['event_ledger']       = array();
	$GLOBALS['event_quote_fp']     = EVENT_ORDERS_TEST_FP;
	$GLOBALS['memory_attempts']    = array();
	$GLOBALS['memory_locks']       = array();
	$GLOBALS['event_caps']         = array( 'papelito_event_sell' => true );
	$GLOBALS['event_collector_down'] = false;
	$GLOBALS['event_registry']       = 'active';
	$GLOBALS['event_reversals']      = array();
}

function event_orders_input( string $method = 'card' ): array {
	return array(
		'checkoutAttemptId' => EVENT_ORDERS_TEST_CHECKOUT,
		'companyId'         => 30,
		'buyerUserId'       => 40,
		'items'             => array( array( 'productId' => 200, 'qty' => 14 ) ),
		'kits'              => 1,
		'delivery'          => array( 'cep' => '01001-000', 'street' => 'Praça da Sé', 'number' => '100', 'complement' => '', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP' ),
		'quoteFingerprint'  => EVENT_ORDERS_TEST_FP,
		'method'            => $method,
	);
}

function event_orders_remote( string $status, array $overrides = array() ): array {
	return array_merge(
		array(
			'id'                 => EVENT_ORDERS_TEST_REMOTE,
			'status'             => $status,
			'status_detail'      => $status,
			'external_reference' => 'ppl-501-1',
			'total_amount'       => '1234.56',
			'user_id'            => (int) EVENT_ORDERS_TEST_COLLECTOR,
			'transactions'       => array( 'payments' => array( array( 'id' => 'PAY01', 'reference_id' => '123456789', 'amount' => '1234.56', 'status' => $status ) ) ),
		),
		$overrides
	);
}

function event_orders_create(): array|WP_Error {
	return papelito_event_sales_create_order( event_orders_input() );
}

function test_create_twice_reuses_open_attempt(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
	$first                         = event_orders_create();
	$second                        = event_orders_create();
	event_orders_assert( 'cria o pedido e manda à máquina', is_array( $first ) && 'at_terminal' === $first['state'] && 501 === $first['orderId'] );
	event_orders_assert( 'segundo toque devolve o mesmo pedido', is_array( $second ) && 501 === $second['orderId'] );
	event_orders_assert( 'um pedido só', 1 === count( $GLOBALS['event_orders'] ) );
	event_orders_assert( 'uma order só na máquina', 1 === count( array_filter( $GLOBALS['event_mp_calls'], static fn( array $c ): bool => 'create' === $c[0] ) ) );
	event_orders_assert( 'uma tentativa só', 1 === count( $GLOBALS['memory_attempts'] ) );

	$attempt = $GLOBALS['memory_attempts'][1];
	event_orders_assert( 'tentativa da Point na conta da Papelito', 'mercadopago_point' === $attempt['provider'] && EVENT_ORDERS_TEST_COLLECTOR === $attempt['account_ref'] && 'terminal' === $attempt['flow'] );
	event_orders_assert( 'tentativa com o total da cotação', EVENT_ORDERS_TEST_TOTAL === $attempt['amount_cents'] && 'card' === $attempt['payment_method'] );
	event_orders_assert( 'tentativa guarda a order remota e a referência', EVENT_ORDERS_TEST_REMOTE === $attempt['remote_id'] && 'ppl-501-1' === $attempt['external_reference'] );

	$order = $GLOBALS['event_orders'][501];
	event_orders_assert( 'pedido marcado como do evento', EVENT_ORDERS_TEST_EVENT === $order->meta['_papelito_event_id'] && 7 === $order->meta['_papelito_event_operator_id'] );
	event_orders_assert( 'estoque reservado', array( 501 ) === $GLOBALS['event_reserved'] );

	$created = $GLOBALS['event_created'][0];
	$sources = array_column( $created['lines'], 'discount_source' );
	event_orders_assert( 'linhas do nível e do kit', in_array( 'event_level', $sources, true ) && in_array( 'event_kit', $sources, true ) );
	event_orders_assert( 'pedido do comprador com o vendor do evento', 40 === $created['user_id'] && 2163 === $created['lines'][0]['vendor_id'] );
	event_orders_assert( 'método da maquininha', 'mercadopago_point' === $created['payment_method'] );
	event_orders_assert( 'endereço com número e bairro', '100' === $created['address']['number'] && 'Sé' === $created['address']['neighborhood'] && '01001000' === $created['address']['zip_code'] );
	event_orders_assert( 'snapshot da empresa completa número e bairro', '100' === $created['context']['b2b_snapshot']['company']['fiscal_number'] );
}

function test_other_orders_are_never_reused(): void {
	event_orders_reset();
	$GLOBALS['event_orders'][900]                                       = new Event_Test_Order( 900 );
	$GLOBALS['event_orders'][901]                                       = new Event_Test_Order( 901 );
	$GLOBALS['event_orders'][901]->meta['_papelito_checkout_attempt_id'] = EVENT_ORDERS_TEST_CHECKOUT;
	$GLOBALS['event_mp']['create']                                      = array( event_orders_remote( 'created', array( 'external_reference' => 'ppl-503-1' ) ) );
	$result                                                             = event_orders_create();
	event_orders_assert( 'pedido de fora do evento não é reaproveitado', is_array( $result ) && ! in_array( $result['orderId'], array( 900, 901 ), true ) );
	event_orders_assert( 'cria o pedido do evento', 3 === count( $GLOBALS['event_orders'] ) );

	$GLOBALS['event_quote_fp'] = 'fingerprint-novo';
	$stale                     = papelito_event_sales_create_order( array_merge( event_orders_input(), array( 'checkoutAttemptId' => 'chk-0002' ) ) );
	event_orders_assert( 'atendimento novo com fingerprint velho é recusado', $stale instanceof WP_Error && 'papelito_event_quote_changed' === $stale->get_error_code() );
}

function test_second_tap_opens_missing_attempt(): void {
	event_orders_reset();
	$GLOBALS['event_collector_down'] = true;
	$first                           = event_orders_create();
	event_orders_assert( 'sem credencial a cobrança falha', $first instanceof WP_Error && 'papelito_mercadopago_configuration' === $first->get_error_code() );
	event_orders_assert( 'o pedido fica criado sem tentativa', 1 === count( $GLOBALS['event_orders'] ) && array() === $GLOBALS['memory_attempts'] );

	$GLOBALS['event_collector_down'] = false;
	$GLOBALS['event_mp']['create']   = array( event_orders_remote( 'created' ) );
	$second                          = event_orders_create();
	event_orders_assert( 'o segundo toque abre a tentativa no mesmo pedido', is_array( $second ) && 501 === $second['orderId'] && 'at_terminal' === $second['state'] );
	event_orders_assert( 'continua um pedido só', 1 === count( $GLOBALS['event_orders'] ) );
}

function test_order_rejects_stale_fingerprint(): void {
	event_orders_reset();
	$GLOBALS['event_quote_fp'] = 'fingerprint-novo';
	$result                    = event_orders_create();
	event_orders_assert( 'cotação mudou é 409', $result instanceof WP_Error && 'papelito_event_quote_changed' === $result->get_error_code() && 409 === $result->get_error_data()['status'] );
	event_orders_assert( 'erro traz a cotação nova', 'fingerprint-novo' === $result->get_error_data()['quote']['quoteFingerprint'] );
	event_orders_assert( 'nenhum pedido criado', array() === $GLOBALS['event_orders'] && array() === $GLOBALS['event_mp_calls'] );
}

function test_buyer_must_belong_to_company(): void {
	event_orders_reset();
	$input                = event_orders_input();
	$input['buyerUserId'] = 41;
	$result               = papelito_event_sales_create_order( $input );
	event_orders_assert( 'comprador sem vínculo é recusado', $result instanceof WP_Error && 'papelito_event_buyer_not_found' === $result->get_error_code() );
	$input['buyerUserId']      = 40;
	$GLOBALS['event_registry'] = 'inactive';
	$inactive                  = papelito_event_sales_create_order( $input );
	event_orders_assert( 'empresa com CNPJ inativo não fecha pedido', $inactive instanceof WP_Error && 'papelito_event_cnpj_inactive' === $inactive->get_error_code() );
	event_orders_assert( 'CNPJ inativo não cria pedido', array() === $GLOBALS['event_orders'] );
	$GLOBALS['event_registry'] = 'active';
	$input['method']      = 'boleto';
	$bad_method           = papelito_event_sales_create_order( $input );
	event_orders_assert( 'método fora de cartão e Pix é recusado', $bad_method instanceof WP_Error && 'papelito_event_invalid_method' === $bad_method->get_error_code() );
}

function test_terminal_busy_maps_to_failed(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( new WP_Error( 'papelito_mercadopago_validation', 'ocupada', array( 'category' => 'validation', 'http_status' => 409, 'remote_code' => 'already_queued_order_for_terminal' ) ) );
	$result                        = event_orders_create();
	$status                        = papelito_event_sales_order_status( $GLOBALS['event_orders'][501] );
	event_orders_assert( 'máquina ocupada vira falha', is_array( $result ) && 'failed' === $result['state'] );
	event_orders_assert( 'detalhe terminal_busy', 'terminal_busy' === $status['detail'] && 'failed' === $status['state'] );
}

function test_unknown_outcome_then_reconcile_reposts_same_key(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array(
		new WP_Error( 'papelito_mercadopago_unknown_outcome', 'timeout', array( 'category' => 'unknown_outcome' ) ),
		event_orders_remote( 'at_terminal' ),
	);
	$result                        = event_orders_create();
	event_orders_assert( 'timeout deixa a cobrança em conferência', is_array( $result ) && 'check_terminal' === $result['state'] );
	event_orders_assert( 'tentativa unknown', 'unknown' === $GLOBALS['memory_attempts'][1]['state'] );

	$state = papelito_event_sales_reconcile_attempt( $GLOBALS['memory_attempts'][1] );
	$keys  = array_column( array_filter( $GLOBALS['event_mp_calls'], static fn( array $c ): bool => 'create' === $c[0] ), 1 );
	event_orders_assert( 'reenvia com a mesma chave', 2 === count( $keys ) && $keys[0] === $keys[1] );
	event_orders_assert( 'order achada fica pendente na máquina', 'at_terminal' === $state && 'pending' === $GLOBALS['memory_attempts'][1]['state'] );
}

function test_processed_marks_paid_once_and_records_ledger(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
	event_orders_create();
	$attempt = $GLOBALS['memory_attempts'][1];

	$first  = papelito_event_sales_apply_remote_order( $attempt, event_orders_remote( 'processed' ) );
	$second = papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'processed' ) );
	event_orders_assert( 'processada vira paga', 'paid' === $first && 'paid' === $second );
	event_orders_assert( 'pedido marcado pago uma vez', array( 501 ) === $GLOBALS['event_paid'] );
	event_orders_assert( 'uma linha de repasse', 1 === count( $GLOBALS['event_ledger'] ) );
	$row = array_values( $GLOBALS['event_ledger'] )[0];
	event_orders_assert( 'repasse com bruto, taxa e líquido', 'payment' === $row['kind'] && EVENT_ORDERS_TEST_TOTAL === $row['gross_cents'] && 4321 === $row['fee_cents'] && '123456789' === $row['remote_payment_id'] );
	event_orders_assert( 'repasse do evento com a forma', EVENT_ORDERS_TEST_EVENT === $row['event_id'] && 'card' === $row['payment_method'] && EVENT_ORDERS_TEST_REMOTE === $row['remote_order_id'] );
	event_orders_assert( 'lock liberado', array() === $GLOBALS['memory_locks'] );

	$refunded = papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'refunded' ) );
	event_orders_assert( 'estorno muda a tentativa', 'refunded' === $GLOBALS['memory_attempts'][1]['state'] );
	event_orders_assert( 'estorno grava linha', isset( $GLOBALS['event_ledger']['1:refund'] ) && 'canceled' !== $refunded );
	event_orders_assert( 'estorno confirmado entra no fluxo de estornos do pedido', array( array( 501, 'refunded' ) ) === $GLOBALS['event_reversals'] );

	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'refunded' ) );
	event_orders_assert( 'estorno repetido não duplica a linha', 2 === count( $GLOBALS['event_ledger'] ) );
}

function test_mismatch_amount_sets_incident_not_paid(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
	event_orders_create();

	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'processed', array( 'total_amount' => '1.00' ) ) );
	event_orders_assert( 'valor divergente não paga', array() === $GLOBALS['event_paid'] && 'pending' === $GLOBALS['memory_attempts'][1]['state'] );
	event_orders_assert( 'valor divergente vira incidente', 'mismatch' === $GLOBALS['memory_attempts'][1]['incident'] );

	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'processed', array( 'user_id' => 999 ) ) );
	event_orders_assert( 'outra conta não paga', array() === $GLOBALS['event_paid'] );

	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'processed', array( 'external_reference' => 'ppl-501-9' ) ) );
	event_orders_assert( 'outra referência não paga', array() === $GLOBALS['event_paid'] );
	$status = papelito_event_sales_order_status( $GLOBALS['event_orders'][501] );
	event_orders_assert( 'incidente pede conferência', 'check_terminal' === $status['state'] );
}

function test_remote_failures_map_states(): void {
	$cases = array(
		'failed'   => array( 'failed', 'failed' ),
		'canceled' => array( 'failed', 'canceled' ),
		'expired'  => array( 'expired', 'expired' ),
	);
	foreach ( $cases as $remote => $expected ) {
		event_orders_reset();
		$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
		event_orders_create();
		papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( $remote ) );
		$status = papelito_event_sales_order_status( $GLOBALS['event_orders'][501] );
		event_orders_assert( "{$remote} vira tentativa {$expected[0]}", $expected[0] === $GLOBALS['memory_attempts'][1]['state'] );
		event_orders_assert( "{$remote} aparece como {$expected[1]}", $expected[1] === $status['state'] );
	}

	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
	event_orders_create();
	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'action_required' ) );
	event_orders_assert( 'ação requerida vira incidente sem mudar o estado', 'pending' === $GLOBALS['memory_attempts'][1]['state'] && 'action_required' === $GLOBALS['memory_attempts'][1]['incident'] );
	event_orders_assert( 'ação requerida pede conferência', 'check_terminal' === papelito_event_sales_order_status( $GLOBALS['event_orders'][501] )['state'] );
}

function test_status_poll_reconciles_after_20s(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
	event_orders_create();
	$GLOBALS['event_mp']['get'] = array( event_orders_remote( 'processed' ) );

	$fresh = papelito_event_sales_order_status( $GLOBALS['event_orders'][501] );
	event_orders_assert( 'antes de 20 s não consulta', 'at_terminal' === $fresh['state'] && array() === array_filter( $GLOBALS['event_mp_calls'], static fn( array $c ): bool => 'get' === $c[0] ) );

	$GLOBALS['memory_attempts'][1]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 30 );
	$late                                        = papelito_event_sales_order_status( $GLOBALS['event_orders'][501] );
	event_orders_assert( 'depois de 20 s consulta e paga', 'paid' === $late['state'] && array( 501 ) === $GLOBALS['event_paid'] );
	event_orders_assert( 'status traz total e forma', EVENT_ORDERS_TEST_TOTAL === $late['totalCents'] && 'card' === $late['method'] );
}

function test_abandon_releases_stock(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
	event_orders_create();
	$order = $GLOBALS['event_orders'][501];

	$blocked = papelito_event_sales_abandon( $order );
	event_orders_assert( 'não abandona com cobrança aberta', $blocked instanceof WP_Error && EVENT_ORDERS_TEST_ATTEMPT_OPEN === $blocked->get_error_code() );

	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'expired' ) );
	$result = papelito_event_sales_abandon( $order );
	event_orders_assert( 'abandona depois de expirar', is_array( $result ) && array( array( 501, 'event_abandoned' ) ) === $GLOBALS['event_unpaid'] );

	$retry = papelito_event_sales_retry( $order, 'pix' );
	event_orders_assert( 'pedido abandonado não cobra de novo', $retry instanceof WP_Error );
}

function test_retry_opens_new_attempt_only_after_end(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ), event_orders_remote( 'created', array( 'id' => 'ORD02', 'external_reference' => 'ppl-501-2' ) ) );
	event_orders_create();
	$order = $GLOBALS['event_orders'][501];

	$blocked = papelito_event_sales_retry( $order, 'pix' );
	event_orders_assert( 'não tenta de novo com cobrança aberta', $blocked instanceof WP_Error && EVENT_ORDERS_TEST_ATTEMPT_OPEN === $blocked->get_error_code() );

	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'failed' ) );
	$retry = papelito_event_sales_retry( $order, 'pix' );
	event_orders_assert( 'nova tentativa no Pix', is_array( $retry ) && 'at_terminal' === $retry['state'] && 'pix' === $GLOBALS['memory_attempts'][2]['payment_method'] );
	event_orders_assert( 'nova tentativa com outra chave', $GLOBALS['memory_attempts'][1]['attempt_ref'] !== $GLOBALS['memory_attempts'][2]['attempt_ref'] );
}

function test_cancel_calls_remote(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
	event_orders_create();
	$result = papelito_event_sales_cancel( $GLOBALS['event_orders'][501] );
	event_orders_assert( 'cancela a order remota com chave própria da tentativa', is_array( $result ) && in_array( array( 'cancel', EVENT_ORDERS_TEST_REMOTE, 'cancel-' . $GLOBALS['memory_attempts'][1]['attempt_ref'] ), $GLOBALS['event_mp_calls'], true ) );
}

function test_manual_confirm_requires_supervisor(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( new WP_Error( 'papelito_mercadopago_unknown_outcome', 'timeout', array( 'category' => 'unknown_outcome' ) ) );
	event_orders_create();
	$request = new WP_REST_Request( array( 'event_id' => EVENT_ORDERS_TEST_EVENT, 'order_id' => '501' ) );

	event_orders_assert( 'operador não libera', papelito_event_sales_require_supervisor( $request ) instanceof WP_Error );

	$GLOBALS['event_caps']['papelito_event_supervise'] = true;
	$GLOBALS['event_env']['PAPELITO_EVENT_SALES_STARTS_AT'] = '2000-01-01T00:00:00-03:00';
	$GLOBALS['event_env']['PAPELITO_EVENT_SALES_ENDS_AT']   = '2100-01-01T00:00:00-03:00';
	event_orders_assert( 'supervisor passa no gate', true === papelito_event_sales_require_supervisor( $request ) );

	$invalid = papelito_event_sales_manual_confirm( $GLOBALS['event_orders'][501], array( 'nsu' => '', 'method' => 'card', 'reason' => 'Máquina aprovou' ) );
	event_orders_assert( 'sem NSU não libera', $invalid instanceof WP_Error && 'papelito_event_manual_invalid' === $invalid->get_error_code() );

	$result = papelito_event_sales_manual_confirm( $GLOBALS['event_orders'][501], array( 'nsu' => '000123', 'method' => 'card', 'reason' => 'Máquina aprovou e o sistema não viu' ) );
	event_orders_assert( 'liberação manual paga', is_array( $result ) && 'paid' === $result['state'] && array( 501 ) === $GLOBALS['event_paid'] );
	event_orders_assert( 'tentativa com incidente manual', 'manual_release' === $GLOBALS['memory_attempts'][1]['incident'] );
	event_orders_assert( 'ledger de liberação manual', isset( $GLOBALS['event_ledger']['1:manual_release'] ) && '000123' === $GLOBALS['event_ledger']['1:manual_release']['nsu'] );

	$GLOBALS['memory_attempts'][1]['remote_id'] = EVENT_ORDERS_TEST_REMOTE;
	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'processed' ) );
	event_orders_assert( 'confirmação da máquina depois da liberação manual não duplica o repasse', 1 === count( $GLOBALS['event_ledger'] ) );
	event_orders_assert( 'a liberação manual fica marcada como confirmada', 'manual_release_confirmed' === $GLOBALS['memory_attempts'][1]['incident'] );

	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'processed' ) );
	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'processed' ) );
	event_orders_assert( 'confirmações repetidas não somam outro repasse', 1 === count( $GLOBALS['event_ledger'] ) );

	$again = papelito_event_sales_manual_confirm( $GLOBALS['event_orders'][501], array( 'nsu' => '000123', 'method' => 'card', 'reason' => 'De novo por engano' ) );
	event_orders_assert( 'não libera pedido já pago', $again instanceof WP_Error );
}

function test_order_routes_check_event(): void {
	event_orders_reset();
	$GLOBALS['event_orders'][900] = new Event_Test_Order( 900 );
	$found                        = papelito_event_sales_order_from_request( new WP_REST_Request( array( 'order_id' => '900' ) ) );
	event_orders_assert( 'pedido de fora do evento é 404', $found instanceof WP_Error && 404 === $found->get_error_data()['status'] );

	papelito_event_sales_mark_lead( $GLOBALS['event_orders'][900] );
	event_orders_assert( 'lead marca o pedido', '1' === $GLOBALS['event_orders'][900]->meta['_papelito_event_pdv_perfeito_lead'] );
}

function test_charge_goes_to_chosen_terminal(): void {
	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array(
		new WP_Error( 'papelito_mercadopago_unknown_outcome', 'timeout', array( 'category' => 'unknown_outcome' ) ),
		event_orders_remote( 'at_terminal' ),
	);
	$input               = event_orders_input();
	$input['terminalId'] = 'NEWLAND_N950__SBX0000002';
	papelito_event_sales_create_order( $input );
	papelito_event_sales_reconcile_attempt( $GLOBALS['memory_attempts'][1] );
	$terminals = array_column( array_filter( $GLOBALS['event_mp_calls'], static fn( array $c ): bool => 'create' === $c[0] ), 3 );
	event_orders_assert( 'cobra na maquininha escolhida e reenvia para a mesma', array( 'NEWLAND_N950__SBX0000002', 'NEWLAND_N950__SBX0000002' ) === $terminals );

	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ) );
	papelito_event_sales_create_order( event_orders_input() );
	event_orders_assert( 'sem escolha cobra na primeira maquininha', 'NEWLAND_N950__SBX0000001' === $GLOBALS['event_mp_calls'][0][3] );

	event_orders_reset();
	$input['terminalId'] = 'PAX_A910__SMARTPOS1491733241';
	$foreign             = papelito_event_sales_create_order( $input );
	event_orders_assert( 'maquininha de fora do evento é recusada', $foreign instanceof WP_Error && 'papelito_event_terminal_invalid' === $foreign->get_error_code() );
	event_orders_assert( 'maquininha recusada não cria pedido', array() === $GLOBALS['event_orders'] && array() === $GLOBALS['event_mp_calls'] );

	event_orders_reset();
	$GLOBALS['event_mp']['create'] = array( event_orders_remote( 'created' ), event_orders_remote( 'created', array( 'id' => 'ORD02', 'external_reference' => 'ppl-501-2' ) ) );
	papelito_event_sales_create_order( event_orders_input() );
	papelito_event_sales_apply_remote_order( $GLOBALS['memory_attempts'][1], event_orders_remote( 'failed' ) );
	papelito_event_sales_retry( $GLOBALS['event_orders'][501], 'card', 'NEWLAND_N950__SBX0000002' );
	event_orders_assert( 'nova tentativa pode ir para a outra maquininha', 'NEWLAND_N950__SBX0000002' === $GLOBALS['event_mp_calls'][1][3] );
}

test_charge_goes_to_chosen_terminal();
test_create_twice_reuses_open_attempt();
test_other_orders_are_never_reused();
test_second_tap_opens_missing_attempt();
test_order_rejects_stale_fingerprint();
test_buyer_must_belong_to_company();
test_terminal_busy_maps_to_failed();
test_unknown_outcome_then_reconcile_reposts_same_key();
test_processed_marks_paid_once_and_records_ledger();
test_mismatch_amount_sets_incident_not_paid();
test_remote_failures_map_states();
test_status_poll_reconciles_after_20s();
test_abandon_releases_stock();
test_retry_opens_new_attempt_only_after_end();
test_cancel_calls_remote();
test_manual_confirm_requires_supervisor();
test_order_routes_check_event();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
