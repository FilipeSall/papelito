<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private string $code;
	private array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): array { return $this->data; }
}

class WP_User {
	public string $display_name;

	public function __construct( string $name ) {
		$this->display_name = $name;
	}
}

/**
 * Banco falso com só a tabela de options e a soma de `_qty`. `before_write` roda antes da próxima
 * escrita e simula outra publicação gravando entre a leitura e a escrita desta.
 */
class Event_Prices_Test_Wpdb {
	public string $options = 'wp_options';
	public string $prefix  = 'wp_';
	public array $store    = array();
	public array $queries  = array();
	public array $unit_ids = array();
	public int $units      = 0;
	public ?Closure $before_write = null;

	public function prepare( string $query, mixed ...$args ): array {
		return array(
			'query' => $query,
			'args'  => is_array( $args[0] ?? null ) ? $args[0] : $args,
		);
	}

	public function get_var( array $prepared ): ?string {
		$this->queries[] = $prepared['query'];

		if ( str_contains( $prepared['query'], 'SUM(' ) ) {
			$this->unit_ids = $prepared['args'];
			return (string) $this->units;
		}

		return $this->store[ $prepared['args'][0] ] ?? null;
	}

	public function query( array $prepared ): int {
		$this->queries[] = $prepared['query'];
		$hook            = $this->before_write;
		$this->before_write = null;
		if ( null !== $hook ) {
			$hook( $this );
		}

		if ( str_starts_with( $prepared['query'], 'INSERT IGNORE' ) ) {
			[ $name, $value ] = $prepared['args'];
			if ( isset( $this->store[ $name ] ) ) {
				return 0;
			}
			$this->store[ $name ] = $value;
			return 1;
		}

		[ $value, $name, $old ] = $prepared['args'];
		if ( ( $this->store[ $name ] ?? null ) !== $old ) {
			return 0;
		}
		$this->store[ $name ] = $value;
		return 1;
	}
}

const EVENT_PRICES_TEST_OPTION  = 'papelito_event_sales_prices';
const EVENT_PRICES_TEST_ADMIN   = 'Admin Papelito';
const EVENT_PRICES_TEST_OTHER   = 'Outra Admin';
const EVENT_PRICES_TEST_INSANE  = 11792;
const EVENT_PRICES_TEST_PITEIRA = 11800;
const EVENT_PRICES_TEST_INVALID = 'papelito_event_prices_invalid';
const EVENT_PRICES_TEST_CONFLICT = 'papelito_event_prices_conflict';

$GLOBALS['wpdb']                = new Event_Prices_Test_Wpdb();
$GLOBALS['event_prices_ledger'] = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function maybe_serialize( mixed $value ): string { return serialize( $value ); }
function maybe_unserialize( mixed $value ): mixed { return is_string( $value ) ? unserialize( $value ) : $value; }
function wp_cache_delete( mixed ...$args ): bool { return true; }
function get_option( string $name, mixed $default_value = false ): mixed {
	$stored = $GLOBALS['wpdb']->store[ $name ] ?? null;
	return null === $stored ? $default_value : maybe_unserialize( $stored );
}
function papelito_event_sales_settlement_summary( string $event_id ): array {
	$rows = $GLOBALS['event_prices_ledger'];
	return array(
		'rows'   => $rows,
		'totals' => array(
			'grossCents'     => (int) array_sum( array_column( $rows, 'gross_cents' ) ),
			'feeCents'       => (int) array_sum( array_filter( array_column( $rows, 'fee_cents' ), 'is_int' ) ),
			'netCents'       => (int) array_sum( array_filter( array_column( $rows, 'net_cents' ), 'is_int' ) ),
			'pendingFeeRows' => count( array_filter( $rows, static fn( array $row ): bool => null === $row['fee_cents'] ) ),
		),
	);
}

require_once dirname( __DIR__ ) . '/includes/event_sales_pricing.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_prices.php';

$failures = 0;
function event_prices_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

function event_prices_error( mixed $result, string $code, int $status ): bool {
	return $result instanceof WP_Error && $code === $result->get_error_code() && $status === $result->get_error_data()['status'];
}

function event_prices_reset(): Event_Prices_Test_Wpdb {
	$GLOBALS['wpdb']                = new Event_Prices_Test_Wpdb();
	$GLOBALS['event_prices_ledger'] = array();
	return $GLOBALS['wpdb'];
}

function event_prices_body( int $version, array $prices, int $kit_off = 15 ): array {
	return array(
		'version'   => $version,
		'prices'    => $prices,
		'kitPctOff' => $kit_off,
	);
}

function event_prices_ledger_row( int $order_id, string $kind, int $gross, ?int $fee, ?int $net ): array {
	return array(
		'order_id'    => $order_id,
		'kind'        => $kind,
		'gross_cents' => $gross,
		'fee_cents'   => $fee,
		'net_cents'   => $net,
	);
}

function test_defaults_without_option(): void {
	event_prices_reset();
	$state = papelito_event_sales_prices_state();
	event_prices_assert( 'sem option não há preço próprio', array() === $state['prices'] && 0 === $state['version'] );
	event_prices_assert( 'kit mantém 85% por padrão', 85 === papelito_event_sales_kit_pct_kept() );
	event_prices_assert( 'produto sem preço próprio devolve null', null === papelito_event_sales_price_override( EVENT_PRICES_TEST_INSANE ) );
}

function test_normalize_drops_invalid_values(): void {
	$state = papelito_event_sales_prices_normalize(
		array(
			'prices'      => array( EVENT_PRICES_TEST_INSANE => 15000, EVENT_PRICES_TEST_PITEIRA => 0, 'x' => 100 ),
			'kit_pct_off' => 90,
			'version'     => -2,
		)
	);
	event_prices_assert( 'só preço positivo de id válido fica', array( EVENT_PRICES_TEST_INSANE => 15000 ) === $state['prices'] );
	event_prices_assert( 'desconto do kit acima do teto é cortado', 50 === $state['kit_pct_off'] );
	event_prices_assert( 'versão negativa vira zero', 0 === $state['version'] );
	event_prices_assert( 'valor que não é array vira o padrão', 15 === papelito_event_sales_prices_normalize( 'lixo' )['kit_pct_off'] );
}

function test_publish_saves_and_bumps_version(): void {
	event_prices_reset();
	$known  = array( EVENT_PRICES_TEST_INSANE, EVENT_PRICES_TEST_PITEIRA );
	$result = papelito_event_sales_prices_publish(
		event_prices_body( 0, array( (string) EVENT_PRICES_TEST_INSANE => 15000, (string) EVENT_PRICES_TEST_PITEIRA => null ), 20 ),
		$known,
		new WP_User( EVENT_PRICES_TEST_ADMIN )
	);
	event_prices_assert( 'publicação válida devolve o estado novo', is_array( $result ) && 1 === $result['version'] );
	event_prices_assert( 'nulo tira o preço próprio', array( EVENT_PRICES_TEST_INSANE => 15000 ) === papelito_event_sales_prices_state()['prices'] );
	event_prices_assert( 'grava quem publicou', EVENT_PRICES_TEST_ADMIN === papelito_event_sales_prices_state()['updated_by'] );
	event_prices_assert( 'preço próprio passa a valer', 15000 === papelito_event_sales_price_override( EVENT_PRICES_TEST_INSANE ) );
	event_prices_assert( 'kit passa a manter 80%', 80 === papelito_event_sales_kit_pct_kept() );

	$next = papelito_event_sales_prices_publish( event_prices_body( 1, array( (string) EVENT_PRICES_TEST_INSANE => 14000 ) ), $known, new WP_User( EVENT_PRICES_TEST_ADMIN ) );
	event_prices_assert( 'segunda publicação atualiza a linha existente', is_array( $next ) && 2 === $next['version'] && 14000 === papelito_event_sales_price_override( EVENT_PRICES_TEST_INSANE ) );

	$stale = papelito_event_sales_prices_publish( event_prices_body( 0, array() ), $known, new WP_User( EVENT_PRICES_TEST_ADMIN ) );
	event_prices_assert( 'versão antiga é conflito 409', event_prices_error( $stale, EVENT_PRICES_TEST_CONFLICT, 409 ) );
	event_prices_assert( 'conflito não apaga o que estava publicado', 14000 === papelito_event_sales_price_override( EVENT_PRICES_TEST_INSANE ) );
}

function test_concurrent_publish_of_same_version(): void {
	$known = array( EVENT_PRICES_TEST_INSANE, EVENT_PRICES_TEST_PITEIRA );

	$db               = event_prices_reset();
	$db->before_write = static function (): void {
		papelito_event_sales_prices_publish( event_prices_body( 0, array( (string) EVENT_PRICES_TEST_PITEIRA => 5990 ) ), array( EVENT_PRICES_TEST_INSANE, EVENT_PRICES_TEST_PITEIRA ), new WP_User( EVENT_PRICES_TEST_OTHER ) );
	};
	$late = papelito_event_sales_prices_publish( event_prices_body( 0, array( (string) EVENT_PRICES_TEST_INSANE => 15000 ) ), $known, new WP_User( EVENT_PRICES_TEST_ADMIN ) );
	event_prices_assert( 'primeira publicação concorrente recebe 409', event_prices_error( $late, EVENT_PRICES_TEST_CONFLICT, 409 ) );
	event_prices_assert( 'fica só o preço de quem gravou antes', array( EVENT_PRICES_TEST_PITEIRA => 5990 ) === papelito_event_sales_prices_state()['prices'] );
	event_prices_assert( 'versão sobe uma vez só', 1 === papelito_event_sales_prices_state()['version'] && EVENT_PRICES_TEST_OTHER === papelito_event_sales_prices_state()['updated_by'] );

	$db               = event_prices_reset();
	$db->store[ EVENT_PRICES_TEST_OPTION ] = serialize( array( 'prices' => array(), 'kit_pct_off' => 15, 'version' => 4 ) );
	$db->before_write = static function (): void {
		papelito_event_sales_prices_publish( event_prices_body( 4, array( (string) EVENT_PRICES_TEST_PITEIRA => 5990 ) ), array( EVENT_PRICES_TEST_INSANE, EVENT_PRICES_TEST_PITEIRA ), new WP_User( EVENT_PRICES_TEST_OTHER ) );
	};
	$update = papelito_event_sales_prices_publish( event_prices_body( 4, array( (string) EVENT_PRICES_TEST_INSANE => 15000 ) ), $known, new WP_User( EVENT_PRICES_TEST_ADMIN ) );
	event_prices_assert( 'corrida sobre option existente também é 409', event_prices_error( $update, EVENT_PRICES_TEST_CONFLICT, 409 ) );
	event_prices_assert( 'corrida sobre option existente mantém um vencedor', 5 === papelito_event_sales_prices_state()['version'] && array( EVENT_PRICES_TEST_PITEIRA => 5990 ) === papelito_event_sales_prices_state()['prices'] );
}

function test_publish_refuses_invalid_input(): void {
	$db    = event_prices_reset();
	$known = array( EVENT_PRICES_TEST_INSANE );
	$admin = new WP_User( EVENT_PRICES_TEST_ADMIN );

	event_prices_assert( 'produto fora do catálogo é recusado', event_prices_error( papelito_event_sales_prices_publish( event_prices_body( 0, array( '999' => 100 ) ), $known, $admin ), EVENT_PRICES_TEST_INVALID, 400 ) );
	event_prices_assert( 'preço zero é recusado', event_prices_error( papelito_event_sales_prices_publish( event_prices_body( 0, array( (string) EVENT_PRICES_TEST_INSANE => 0 ) ), $known, $admin ), EVENT_PRICES_TEST_INVALID, 400 ) );
	event_prices_assert( 'preço decimal é recusado', event_prices_error( papelito_event_sales_prices_publish( array( 'version' => 0, 'prices' => array( (string) EVENT_PRICES_TEST_INSANE => 150.5 ), 'kitPctOff' => 15 ), $known, $admin ), EVENT_PRICES_TEST_INVALID, 400 ) );
	event_prices_assert( 'desconto do kit acima de 50% é recusado', event_prices_error( papelito_event_sales_prices_publish( event_prices_body( 0, array(), 60 ), $known, $admin ), EVENT_PRICES_TEST_INVALID, 400 ) );
	event_prices_assert( 'sem versão é conflito', event_prices_error( papelito_event_sales_prices_publish( array( 'prices' => array(), 'kitPctOff' => 15 ), $known, $admin ), EVENT_PRICES_TEST_CONFLICT, 409 ) );
	event_prices_assert( 'nada recusado foi gravado', array() === $db->store );
}

function test_sales_summary_from_ledger(): void {
	$db        = event_prices_reset();
	$db->units = 38;
	$GLOBALS['event_prices_ledger'] = array(
		event_prices_ledger_row( 501, 'payment', 230000, 6900, 223100 ),
		event_prices_ledger_row( 502, 'manual_release', 120000, null, null ),
		event_prices_ledger_row( 503, 'payment', 30000, 900, 29100 ),
		event_prices_ledger_row( 503, 'refund', -30000, -900, -29100 ),
	);
	$sales     = papelito_event_sales_prices_sales( 'expocannabis-2026' );
	$sum_calls = count( array_filter( $db->queries, static fn( string $query ): bool => str_contains( $query, 'SUM(' ) ) );
	event_prices_assert( 'pedido estornado não conta como vendido', 2 === $sales['orders'] );
	event_prices_assert( 'displays vêm de uma consulta só com os pedidos vendidos', 38 === $sales['units'] && array( 501, 502 ) === $db->unit_ids && 1 === $sum_calls );
	event_prices_assert( 'bruto já desconta o estorno', 350000 === $sales['grossCents'] );
	event_prices_assert( 'líquido para a Cifal ignora taxa a conferir', 223100 === $sales['netCents'] );
	event_prices_assert( 'conta as linhas com taxa a conferir', 1 === $sales['pendingFeeRows'] );

	$db                             = event_prices_reset();
	$GLOBALS['event_prices_ledger'] = array();
	event_prices_assert( 'sem venda não consulta itens', 0 === papelito_event_sales_prices_sales( 'expocannabis-2026' )['units'] && array() === $db->queries );
}

test_defaults_without_option();
test_normalize_drops_invalid_values();
test_publish_saves_and_bumps_version();
test_concurrent_publish_of_same_version();
test_publish_refuses_invalid_input();
test_sales_summary_from_ledger();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
