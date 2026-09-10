<?php
/**
 * Ambiente compartilhado dos testes de chamado.
 *
 * Stuba o WordPress e o `$wpdb` o suficiente para exercitar as funções reais de
 * `vendor_messaging.php` sem banco, no mesmo desenho de `test-vendor-messaging-transaction.php`.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) );

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

const CHAMADO_TEST_CUSTOMER_ID = 17;
const CHAMADO_TEST_VENDOR_ID   = 29;
const CHAMADO_TEST_ADMIN_ID    = 1;
const CHAMADO_TEST_STRANGER_ID = 88;
const CHAMADO_TEST_ORDER_ID    = 14094;
const CHAMADO_TEST_THREAD_ID   = 7;

$GLOBALS['papelito_test_current_user'] = 0;
$GLOBALS['papelito_test_actions']      = array();
$GLOBALS['papelito_test_phones']       = array();

function add_action( ...$args ) {
	// Os testes exercitam funções puras: registrar hook não muda nada aqui.
}
function register_rest_route( ...$args ) {
	// Idem: o registro de rota não é exercitado, só as callbacks.
}
function absint( mixed $value ): int { return abs( (int) $value ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( mixed $value ): string { return strip_tags( (string) $value ); }
function wp_strip_all_tags( mixed $value ): string { return strip_tags( (string) $value ); }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function rest_sanitize_boolean( mixed $value ): bool {
	return ! in_array( $value, array( false, 0, '0', '', 'false', null ), true );
}
function current_time( string $format, bool $gmt = false ): string { return '2026-09-09 12:00:00'; }
function get_current_user_id() { return (int) $GLOBALS['papelito_test_current_user']; }
function get_avatar_url( int $user_id, array $args = array() ): string { return 'https://avatar.test/' . (int) $user_id; }
function get_userdata( int $user_id ) { return null; }
function get_users( array $args = array() ): array { return array( CHAMADO_TEST_ADMIN_ID ); }
function get_transient( string $key ): int { return 0; }
function set_transient( string $key, mixed $value, int $ttl ): bool { return true; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function user_can( int $user_id, string $capability ): bool {
	return CHAMADO_TEST_ADMIN_ID === (int) $user_id && 'manage_options' === $capability;
}
function current_user_can( string $capability ): bool { return user_can( get_current_user_id(), $capability ); }
function get_user_meta( int $user_id, string $key, bool $single = false ): string {
	if ( 'phone_number' === $key ) {
		return $GLOBALS['papelito_test_phones'][ (int) $user_id ] ?? '';
	}
	if ( 'store_name' === $key ) {
		return CHAMADO_TEST_VENDOR_ID === (int) $user_id ? 'Loja Exemplo' : '';
	}
	return '';
}
function do_action( string $hook, mixed ...$args ): void {
	$GLOBALS['papelito_test_actions'][] = array( 'hook' => $hook, 'args' => $args );
}
if ( ! function_exists( 'papelito_normalize_phone_digits' ) ) {
	function papelito_normalize_phone_digits( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( ( 12 === strlen( $digits ) || 13 === strlen( $digits ) ) && 0 === strpos( $digits, '55' ) ) {
			return substr( $digits, 2 );
		}
		return $digits;
	}
}
function papelito_return_reasons() {
	return array( 'regret', 'defective', 'damaged', 'incorrect_item', 'incomplete_item', 'other' );
}
function papelito_return_reason_labels() {
	return array(
		'regret'          => 'Desistência da compra',
		'defective'       => 'Produto com defeito',
		'damaged'         => 'Danificado no transporte',
		'incorrect_item'  => 'Item errado',
		'incomplete_item' => 'Item incompleto',
		'other'           => 'Outro motivo',
	);
}
function papelito_return_reason_label( string $reason, string $other = '' ) {
	$labels = papelito_return_reason_labels();
	$label  = $labels[ sanitize_key( $reason ) ] ?? 'Outro motivo';
	$other  = trim( $other );
	return 'other' === sanitize_key( $reason ) && '' !== $other ? $label . ' — ' . $other : $label;
}
function papelito_return_error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}
function papelito_return_validate_reason( WP_REST_Request $request, ?array $fallback = null ) {
	$reason = sanitize_key( (string) $request->get_param( 'reason' ) );
	$other  = sanitize_textarea_field( (string) $request->get_param( 'reasonOther' ) );
	if ( '' === $reason && null !== $fallback ) {
		$reason = sanitize_key( (string) ( $fallback['reason'] ?? '' ) );
		$other  = '' !== $other ? $other : sanitize_textarea_field( (string) ( $fallback['other'] ?? '' ) );
	}
	if ( ! in_array( $reason, papelito_return_reasons(), true ) ) {
		return papelito_return_error( 'papelito_return_reason_invalid', 'Informe um motivo válido para a devolução.', 422 );
	}
	if ( 'other' === $reason && '' === trim( $other ) ) {
		return papelito_return_error( 'papelito_return_reason_invalid', 'Informe um motivo válido para a devolução.', 422 );
	}
	return array( 'reason' => $reason, 'other' => $other );
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }

class WP_REST_Response {
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'PUT, PATCH';
}

class WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
	public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
}

class Papelito_Test_Order_Item {
	public function __construct(
		private string $name = 'Caderno Pautado',
		private int $quantity = 2,
		private string $total = '30.00'
	) {}
	public function get_name(): string { return $this->name; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_total(): string { return $this->total; }
	public function get_meta( string $key, bool $single = true ): int { return CHAMADO_TEST_VENDOR_ID; }
}

class Papelito_Test_Order {
	public function __construct(
		private int $customerId = CHAMADO_TEST_CUSTOMER_ID,
		private int $vendorId = CHAMADO_TEST_VENDOR_ID
	) {}
	public function get_id(): int { return CHAMADO_TEST_ORDER_ID; }
	public function get_customer_id(): int { return $this->customerId; }
	public function get_order_number(): string { return '14094'; }
	public function get_status(): string { return 'processing'; }
	public function get_payment_method_title(): string { return 'Cartão de crédito'; }
	public function get_subtotal(): float { return 60.0; }
	public function get_shipping_total(): float { return 12.0; }
	public function get_total(): float { return 72.0; }
	public function get_date_created() { return null; }
	public function get_items( string $type = 'line_item' ): array { return array( 42 => new Papelito_Test_Order_Item() ); }
	public function get_meta( string $key, bool $single = true ): mixed { return '_papelito_vendor_id' === $key ? $this->vendorId : ''; }
}

// WC_Order é final no WooCommerce: o duble não pode estendê-la, então vira ela por alias. É isso
// que faz `is_a( $order, 'WC_Order' )` passar dentro de papelito_messaging_order().
if ( ! class_exists( 'WC_Order' ) ) {
	class_alias( 'Papelito_Test_Order', 'WC_Order' );
}

$GLOBALS['papelito_test_order'] = new Papelito_Test_Order();

function wc_get_order( mixed $order_id ) {
	return CHAMADO_TEST_ORDER_ID === (int) $order_id ? $GLOBALS['papelito_test_order'] : false;
}
function wc_get_order_status_name( string $status ): string { return 'Processando'; }

/** `$wpdb` falso que registra escrita em vez de executar. */
class Papelito_Test_Wpdb {
	public string $prefix = 'wp_';
	// Espelha `$wpdb->insert_id`, que é o que o código real lê. O nome vem da API do
	// WordPress, então renomear para camelCase quebraria o dublê em silêncio.
	public int $insert_id = 900; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.NotSnakeCaseMemberVar
	public array $queries = array();
	public array $inserts = array();
	public array $updates = array();
	/** @var array<string,mixed>|null */
	public $thread = null;

	public function get_charset_collate(): string { return ''; }
	public function prepare( string $sql, mixed ...$args ): string {
		$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		return $sql . '|' . implode( ',', array_map( 'strval', $values ) );
	}
	public function esc_like( string $text ): string { return $text; }
	public function query( string $sql ): int {
		$this->queries[] = $sql;
		if ( str_starts_with( $sql, 'UPDATE' ) ) {
			$this->updates[] = $sql;
			if ( str_contains( $sql, 'SET return_request_id = %d' ) && is_array( $this->thread ) ) {
				$parts = explode( '|', $sql );
				$this->thread['return_request_id'] = absint( $parts[1] ?? 0 );
			}
		}
		return 1;
	}
	public function insert( string $table, array $data, ?array $format = null ): int {
		$this->inserts[] = array( 'table' => $table, 'data' => $data );
		++$this->insert_id;

		// A linha passa a existir para as leituras seguintes, como no banco de verdade.
		if ( str_contains( $table, 'message_threads' ) ) {
			$this->thread = array_merge( papelito_test_thread(), $data, array( 'id' => $this->insert_id ) );
		}

		return 1;
	}
	public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int {
		$this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
		return 1;
	}
	public function get_var( string $sql ): mixed {
		$this->queries[] = $sql;

		// O lock consultivo é concedido: os testes exercitam o caminho feliz da serialização.
		return str_contains( $sql, 'GET_LOCK' ) || str_contains( $sql, 'RELEASE_LOCK' ) ? '1' : null;
	}
	public function get_row( string $sql, mixed $output = null ): mixed { return $this->thread; }
	public function get_results( string $sql, mixed $output = null ): array { return array(); }
}

$GLOBALS['wpdb'] = new Papelito_Test_Wpdb();

/**
 * Linha de chamado usada como fixture.
 *
 * @param array<string,mixed> $overrides Campos a sobrescrever.
 * @return array<string,mixed>
 */
function papelito_test_thread( array $overrides = array() ): array {
	return array_merge(
		array(
			'id'                => CHAMADO_TEST_THREAD_ID,
			'order_id'          => CHAMADO_TEST_ORDER_ID,
			'return_request_id' => null,
			'customer_id'       => CHAMADO_TEST_CUSTOMER_ID,
			'vendor_id'         => CHAMADO_TEST_VENDOR_ID,
			'context'           => 'order',
			'support_key'       => null,
			'reason'            => 'duvida_pedido',
			'return_reason'     => null,
			'reason_other'      => null,
			'status'            => 'ABERTO',
			'closed_at'         => null,
			'closed_by'         => null,
			'escalated_at'      => null,
			'created_at'        => '2026-09-01 10:00:00',
			'updated_at'        => '2026-09-01 10:00:00',
		),
		$overrides
	);
}

function papelito_test_as( int $user_id ): void {
	$GLOBALS['papelito_test_current_user'] = $user_id;
}

function papelito_test_reset_wpdb(): void {
	$GLOBALS['wpdb']->queries = array();
	$GLOBALS['wpdb']->inserts = array();
	$GLOBALS['wpdb']->updates = array();
	$GLOBALS['wpdb']->thread  = null;
	$GLOBALS['papelito_test_actions'] = array();
}

/**
 * @param array<string,bool> $assertions
 */
function papelito_test_report( array $assertions ): void {
	$failures = 0;
	foreach ( $assertions as $label => $condition ) {
		echo ( $condition ? 'PASS: ' : 'FALHOU: ' ) . $label . "\n";
		$failures += $condition ? 0 : 1;
	}
	exit( $failures > 0 ? 1 : 0 );
}
