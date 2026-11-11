<?php
/**
 * Rotas de admin do evento: repasse, terminais, modo da máquina e estorno.
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

	public function get_error_data(): mixed {
		return $this->data;
	}
}

class WP_REST_Request {
	public function __construct( private array $params = array() ) {}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
}

class Event_Admin_Order {
	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}
}

const EVENT_ADMIN_TEST_TERMINAL = 'NEWLAND_N950__N950NCD600451364';
const EVENT_ADMIN_TEST_EVENT    = 'expocannabis-2026';
const EVENT_ADMIN_TEST_SECOND   = 'NEWLAND_N950__N950NCD700342623';

$GLOBALS['eadm_caps']     = array();
$GLOBALS['eadm_env']      = array( 'PAPELITO_EVENT_SALES_TERMINAL_ID' => EVENT_ADMIN_TEST_TERMINAL . ',' . EVENT_ADMIN_TEST_SECOND );
$GLOBALS['eadm_calls']    = array();
$GLOBALS['eadm_attempts'] = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['eadm_env'][ $key ] ?? $default; }
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['eadm_caps'][ $cap ] ); }
function papelito_event_sales_settlement_rows( string $event_id ): array {
	return array(
		array( 'order_id' => 501, 'kind' => 'payment', 'gross_cents' => '123456', 'fee_cents' => '4321', 'net_cents' => '119135' ),
		array( 'order_id' => 502, 'kind' => 'manual_release', 'gross_cents' => '5000', 'fee_cents' => null, 'net_cents' => null ),
	);
}
function papelito_event_sales_settlement_csv( array $rows ): string { return "csv\n"; }
function papelito_mercadopago_point_list_terminals(): array {
	$GLOBALS['eadm_calls'][] = 'list';
	return array(
		'data' => array(
			'terminals' => array(
				array( 'id' => EVENT_ADMIN_TEST_TERMINAL, 'operating_mode' => 'PDV' ),
				array( 'id' => 'PAX_A910__OUTRA', 'operating_mode' => 'STANDALONE' ),
				array( 'id' => EVENT_ADMIN_TEST_SECOND, 'operating_mode' => $GLOBALS['eadm_second_mode'] ?? 'PDV' ),
			),
		),
	);
}
function papelito_mercadopago_point_set_mode( string $terminal_id, string $mode ): array {
	$GLOBALS['eadm_calls'][] = "mode {$terminal_id} {$mode}";
	return array();
}
function papelito_mercadopago_point_refund_order( string $order_id, string $key ): array {
	$GLOBALS['eadm_calls'][] = "refund {$order_id} {$key}";
	return array( 'id' => $order_id, 'status' => 'processed' );
}
function papelito_payment_attempt_latest_for_order( int $order_id ): ?array { return $GLOBALS['eadm_attempts'][ $order_id ] ?? null; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_config.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_admin.php';

$failures = 0;

function eadm_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

function test_settlement_csv_route_admin_only(): void {
	$request = new WP_REST_Request( array( 'event_id' => EVENT_ADMIN_TEST_EVENT ) );

	$GLOBALS['eadm_caps'] = array( 'papelito_event_sell' => true, 'papelito_event_supervise' => true );
	$denied               = papelito_event_sales_require_admin( $request );
	eadm_assert( 'supervisor não vê o repasse', $denied instanceof WP_Error && 403 === $denied->get_error_data()['status'] );

	$GLOBALS['eadm_caps'] = array( 'manage_options' => true );
	eadm_assert( 'admin vê o repasse', true === papelito_event_sales_require_admin( $request ) );
	$other = papelito_event_sales_require_admin( new WP_REST_Request( array( 'event_id' => 'outro' ) ) );
	eadm_assert( 'outro evento é 404', $other instanceof WP_Error && 404 === $other->get_error_data()['status'] );

	$summary = papelito_event_sales_settlement_summary( EVENT_ADMIN_TEST_EVENT );
	eadm_assert( 'resumo traz as linhas', 2 === count( $summary['rows'] ) );
	eadm_assert( 'total bruto soma tudo', 128456 === $summary['totals']['grossCents'] );
	eadm_assert( 'líquido soma o conhecido', 119135 === $summary['totals']['netCents'] && 4321 === $summary['totals']['feeCents'] );
	eadm_assert( 'conta as linhas a conferir', 1 === $summary['totals']['pendingFeeRows'] );
	eadm_assert( 'nome do arquivo do repasse', 'repasse-cifal-expocannabis-2026.csv' === papelito_event_sales_settlement_filename() );
}

function test_terminals_check_marks_configured(): void {
	$check = papelito_event_sales_terminals_check();
	eadm_assert( 'lista os terminais da conta', 3 === count( $check['terminals'] ) );
	eadm_assert( 'marca o terminal do evento', true === $check['terminals'][0]['configured'] && 'PDV' === $check['terminals'][0]['operatingMode'] );
	eadm_assert( 'os outros não são do evento', false === $check['terminals'][1]['configured'] );
	eadm_assert( 'diz se o terminal do evento está pronto', true === $check['ready'] );
	eadm_assert( 'marca as duas maquininhas do evento', true === $check['terminals'][2]['configured'] );
	$GLOBALS['eadm_second_mode'] = 'STANDALONE';
	eadm_assert( 'só fica pronto com todas as maquininhas do evento em PDV', false === papelito_event_sales_terminals_check()['ready'] );
	$GLOBALS['eadm_second_mode'] = 'PDV';
}

function test_mode_only_on_configured_terminal(): void {
	$GLOBALS['eadm_calls'] = array();
	papelito_event_sales_terminal_mode( 'STANDALONE' );
	eadm_assert( 'troca o modo só das maquininhas do evento', array( 'mode ' . EVENT_ADMIN_TEST_TERMINAL . ' STANDALONE', 'mode ' . EVENT_ADMIN_TEST_SECOND . ' STANDALONE' ) === $GLOBALS['eadm_calls'] );
	eadm_assert( 'modo inválido é recusado', papelito_event_sales_terminal_mode( 'OUTRO' ) instanceof WP_Error );
}

function test_refund_only_paid_point_attempt(): void {
	$GLOBALS['eadm_calls']    = array();
	$GLOBALS['eadm_attempts'] = array(
		501 => array( 'id' => 1, 'provider' => 'mercadopago_point', 'state' => 'paid', 'remote_id' => 'ORD01', 'attempt_ref' => 'abc' ),
		502 => array( 'id' => 2, 'provider' => 'mercadopago_point', 'state' => 'pending', 'remote_id' => 'ORD02', 'attempt_ref' => 'def' ),
	);
	$refund = papelito_event_sales_admin_refund( new Event_Admin_Order( 501 ) );
	eadm_assert( 'estorna a order paga com chave própria', is_array( $refund ) && array( 'refund ORD01 refund-abc' ) === $GLOBALS['eadm_calls'] );
	eadm_assert( 'não estorna cobrança não paga', papelito_event_sales_admin_refund( new Event_Admin_Order( 502 ) ) instanceof WP_Error );
}

test_settlement_csv_route_admin_only();
test_terminals_check_marks_configured();
test_mode_only_on_configured_terminal();
test_refund_only_paid_point_attempt();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
