<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public string $code;
	public array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

class WP_User {
	public int $ID;
	public array $allcaps;

	public function __construct( int $id = 0, array $caps = array() ) {
		$this->ID      = $id;
		$this->allcaps = $caps;
	}
}

class WP_REST_Request {
	private array $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
}

$GLOBALS['event_env']          = array();
$GLOBALS['event_current_user'] = new WP_User();
$GLOBALS['event_routes']       = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['event_env'][ $key ] ?? $default; }
function wp_get_current_user(): WP_User { return $GLOBALS['event_current_user']; }
function get_current_user_id(): int { return $GLOBALS['event_current_user']->ID; }
function user_can( WP_User $user, string $cap ): bool { return ! empty( $user->allcaps[ $cap ] ); }
function current_user_can( string $cap ): bool { return user_can( $GLOBALS['event_current_user'], $cap ); }
function register_rest_route( string $route_namespace, string $route, array $args = array() ): bool {
	$GLOBALS['event_routes'][] = $route_namespace . $route;
	return true;
}

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_config.php';

const EVENT_SALES_TEST_STARTS = '2026-11-20T00:00:00-03:00';
const EVENT_SALES_TEST_ENDS   = '2026-11-22T23:59:59-03:00';
const EVENT_SALES_TEST_ID     = 'expocannabis-2026';

$failures = 0;

function event_config_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

function event_config_error_is( mixed $result, string $code, int $status ): bool {
	return $result instanceof WP_Error && $code === $result->get_error_code() && $status === ( $result->get_error_data()['status'] ?? 0 );
}

function test_routes_register_without_any_flag(): void {
	$GLOBALS['event_env']    = array( 'PAPELITO_EVENT_SALES_ENABLED' => 'false' );
	$GLOBALS['event_routes'] = array();
	papelito_event_sales_register_route( '/context', array() );
	event_config_assert( 'a rota do evento existe sem flag nenhuma', array( 'papelito/v1/event/(?P<event_id>[a-z0-9-]+)/context' ) === $GLOBALS['event_routes'] );
}

function test_config_parses_csv_and_dates(): void {
	$GLOBALS['event_env'] = array(
		'PAPELITO_EVENT_SALES_KIT_PRODUCT_IDS' => '11, 12,x,13',
		'PAPELITO_EVENT_SALES_VENDOR_ID'       => '2163',
		'PAPELITO_EVENT_SALES_TERMINAL_ID'     => ' NEWLAND_N950__N950NCD600451364 ',
	);
	$config               = papelito_event_sales_config();
	event_config_assert( 'kit lê só os ids numéricos', array( 11, 12, 13 ) === $config['kit_product_ids'] );
	event_config_assert( 'início padrão é 20/11 às 0h de Brasília', strtotime( EVENT_SALES_TEST_STARTS ) === $config['starts_at'] );
	event_config_assert( 'fim padrão é 22/11 às 23h59 de Brasília', strtotime( EVENT_SALES_TEST_ENDS ) === $config['ends_at'] );
	event_config_assert( 'vendor vem do ambiente', 2163 === $config['vendor_id'] );
	event_config_assert( 'terminal sem espaços', array( 'NEWLAND_N950__N950NCD600451364' ) === $config['terminal_ids'] );
	event_config_assert( 'id do evento é fixo', EVENT_SALES_TEST_ID === $config['event_id'] );

	$GLOBALS['event_env'] = array( 'PAPELITO_EVENT_SALES_STARTS_AT' => 'amanhã cedo' );
	event_config_assert( 'data inválida cai no padrão', strtotime( EVENT_SALES_TEST_STARTS ) === papelito_event_sales_config()['starts_at'] );
}

function test_window_blocks_operator_outside_and_allows_admin(): void {
	$GLOBALS['event_env'] = array();
	$operator             = new WP_User( 7, array( 'papelito_event_sell' => true ) );
	$admin                = new WP_User( 1, array( 'manage_options' => true, 'papelito_event_sell' => true ) );
	$before               = strtotime( EVENT_SALES_TEST_STARTS ) - 1;
	$during               = strtotime( '2026-11-21T15:00:00-03:00' );
	$after                = strtotime( EVENT_SALES_TEST_ENDS ) + 1;

	event_config_assert( 'operador não vende antes do evento', false === papelito_event_sales_is_open( $before, $operator ) );
	event_config_assert( 'operador vende durante o evento', true === papelito_event_sales_is_open( $during, $operator ) );
	event_config_assert( 'operador não vende depois do evento', false === papelito_event_sales_is_open( $after, $operator ) );
	event_config_assert( 'administrador homologa fora da janela', true === papelito_event_sales_is_open( $before, $admin ) );
}

function test_require_seller_error_order(): void {
	$GLOBALS['event_env'] = array(
		'PAPELITO_EVENT_SALES_STARTS_AT' => '2000-01-01T00:00:00-03:00',
		'PAPELITO_EVENT_SALES_ENDS_AT'   => '2100-01-01T00:00:00-03:00',
	);
	$request              = new WP_REST_Request( array( 'event_id' => EVENT_SALES_TEST_ID ) );

	$GLOBALS['event_current_user'] = new WP_User( 7, array( 'papelito_event_sell' => true ) );
	$wrong_event                   = papelito_event_sales_require_seller( new WP_REST_Request( array( 'event_id' => 'outro-evento' ) ) );
	event_config_assert( 'evento desconhecido é 404', event_config_error_is( $wrong_event, 'papelito_event_not_found', 404 ) );

	$GLOBALS['event_current_user'] = new WP_User();
	event_config_assert( 'sem login é 401', event_config_error_is( papelito_event_sales_require_seller( $request ), 'papelito_event_auth_required', 401 ) );

	$GLOBALS['event_current_user'] = new WP_User( 9, array( 'read' => true ) );
	event_config_assert( 'sem capability é 403', event_config_error_is( papelito_event_sales_require_seller( $request ), 'papelito_event_forbidden', 403 ) );

	$GLOBALS['event_env']['PAPELITO_EVENT_SALES_ENDS_AT'] = '2001-01-01T00:00:00-03:00';
	$GLOBALS['event_current_user']                        = new WP_User( 7, array( 'papelito_event_sell' => true ) );
	event_config_assert( 'evento encerrado é 403', event_config_error_is( papelito_event_sales_require_seller( $request ), 'papelito_event_closed', 403 ) );

	$GLOBALS['event_env']['PAPELITO_EVENT_SALES_ENDS_AT'] = '2100-01-01T00:00:00-03:00';
	event_config_assert( 'sem vendor e terminal é 503', event_config_error_is( papelito_event_sales_require_seller( $request ), 'papelito_event_not_configured', 503 ) );

	$GLOBALS['event_env']['PAPELITO_EVENT_SALES_VENDOR_ID']   = '2163';
	$GLOBALS['event_env']['PAPELITO_EVENT_SALES_TERMINAL_ID'] = 'NEWLAND_N950__SBX0000001';
	event_config_assert( 'operador configurado passa', true === papelito_event_sales_require_seller( $request ) );
	event_config_assert( 'operador sem supervise não supervisiona', event_config_error_is( papelito_event_sales_require_supervisor( $request ), 'papelito_event_forbidden', 403 ) );

	$GLOBALS['event_current_user'] = new WP_User( 8, array( 'papelito_event_sell' => true, 'papelito_event_supervise' => true ) );
	event_config_assert( 'supervisor passa', true === papelito_event_sales_require_supervisor( $request ) );
}

function test_terminals_list_and_choice(): void {
	$GLOBALS['event_env'] = array( 'PAPELITO_EVENT_SALES_TERMINAL_ID' => 'NEWLAND_N950__N950NCD600451364, NEWLAND_N950__N950NCD700342623,x y,NEWLAND_N950__N950NCD600451364' );
	$ids                  = papelito_event_sales_config()['terminal_ids'];
	event_config_assert( 'lista de terminais sem repetição nem lixo', array( 'NEWLAND_N950__N950NCD600451364', 'NEWLAND_N950__N950NCD700342623' ) === $ids );
	event_config_assert( 'sem escolha usa a primeira maquininha', 'NEWLAND_N950__N950NCD600451364' === papelito_event_sales_resolve_terminal( '' ) );
	event_config_assert( 'escolhe a segunda maquininha', 'NEWLAND_N950__N950NCD700342623' === papelito_event_sales_resolve_terminal( 'NEWLAND_N950__N950NCD700342623' ) );
	event_config_assert( 'maquininha de fora do evento é recusada', event_config_error_is( papelito_event_sales_resolve_terminal( 'PAX_A910__SMARTPOS1491733241' ), 'papelito_event_terminal_invalid', 400 ) );
	event_config_assert( 'rótulo pelo final do número de série', 'Maquininha final 1364' === papelito_event_sales_terminal_label( 'NEWLAND_N950__N950NCD600451364' ) );
	event_config_assert( 'contexto lista as maquininhas', array( array( 'id' => 'NEWLAND_N950__N950NCD600451364', 'label' => 'Maquininha final 1364' ), array( 'id' => 'NEWLAND_N950__N950NCD700342623', 'label' => 'Maquininha final 2623' ) ) === papelito_event_sales_public_terminals() );
}

test_routes_register_without_any_flag();
test_terminals_list_and_choice();
test_config_parses_csv_and_dates();
test_window_blocks_operator_outside_and_allows_admin();
test_require_seller_error_order();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
