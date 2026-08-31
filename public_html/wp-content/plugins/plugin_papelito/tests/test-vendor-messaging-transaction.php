<?php
/**
 * Regressão da criação atômica de conversa e primeira mensagem.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function absint( $value ) { return abs( (int) $value ); }
function current_time( $type, $gmt = false ) { return '2026-08-31 18:00:00'; }
function is_user_logged_in() { return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
}
class WP_REST_Request {}
class WP_REST_Response {}

class Papelito_Messaging_Transaction_Test_WPDB {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public bool $fail_message_insert = false;
	public array $queries = array();
	public array $inserts = array();

	public function query( $query ) {
		$this->queries[] = $query;
		return true;
	}

	public function insert( $table, $data, $format ) {
		$this->inserts[] = array( 'table' => $table, 'data' => $data );

		if ( $this->fail_message_insert && str_contains( $table, PAPELITO_MESSAGES_TABLE ) ) {
			return false;
		}

		$this->insert_id = count( $this->inserts );
		return 1;
	}

	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) { return 0; }
	public function get_row( $query, $output = null ) { return null; }
	public function get_results( $query, $output = null ) { return array(); }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
}

$wpdb = new Papelito_Messaging_Transaction_Test_WPDB();

require_once __DIR__ . '/../includes/vendor_messaging.php';

$failures = 0;
$checks   = 0;

function papelito_messaging_transaction_assert( bool $condition, string $message ): void {
	global $checks, $failures;
	++$checks;
	if ( ! $condition ) {
		++$failures;
		echo "FALHOU: {$message}\n";
	}
}

$thread_data = array(
	'order_id'    => 123,
	'customer_id' => 17,
	'vendor_id'   => 29,
	'created_at'  => '2026-08-31 18:00:00',
	'updated_at'  => '2026-08-31 18:00:00',
);
$thread_format = array( '%d', '%d', '%d', '%s', '%s' );

$wpdb->fail_message_insert = true;
$failed = papelito_messaging_create_thread_with_initial_message( $thread_data, $thread_format, 17, 'Primeira mensagem' );

papelito_messaging_transaction_assert( is_wp_error( $failed ), 'falha da primeira mensagem retorna erro' );
papelito_messaging_transaction_assert( in_array( 'START TRANSACTION', $wpdb->queries, true ), 'abre transação antes de criar a conversa' );
papelito_messaging_transaction_assert( in_array( 'ROLLBACK', $wpdb->queries, true ), 'falha da primeira mensagem desfaz a conversa' );
papelito_messaging_transaction_assert( ! in_array( 'COMMIT', $wpdb->queries, true ), 'falha não confirma thread sem mensagem' );

$wpdb = new Papelito_Messaging_Transaction_Test_WPDB();
$created = papelito_messaging_create_thread_with_initial_message( $thread_data, $thread_format, 17, 'Primeira mensagem' );

papelito_messaging_transaction_assert( ! is_wp_error( $created ), 'criação íntegra retorna os IDs gerados' );
papelito_messaging_transaction_assert( array( 'START TRANSACTION', 'COMMIT' ) === $wpdb->queries, 'confirma a thread e a primeira mensagem na mesma transação' );
papelito_messaging_transaction_assert( 2 === count( $wpdb->inserts ), 'insere uma thread e sua primeira mensagem' );
papelito_messaging_transaction_assert( str_contains( $wpdb->inserts[1]['table'], PAPELITO_MESSAGES_TABLE ), 'a segunda escrita é a mensagem inicial' );

echo "{$checks} verificacoes, {$failures} falhas\n";

exit( $failures > 0 ? 1 : 0 );
