<?php
/**
 * Regressão da criação atômica de conversa e primeira mensagem.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

const TEST_NOW = '2026-08-31 18:00:00';
const TEST_BEGIN = 'START TRANSACTION';

function add_action( ...$args ) {
	// O teste exercita a função de escrita, não o registro de hooks.
}
function register_rest_route( ...$args ) {
	// Idem: nenhuma rota é exercitada aqui.
}
function absint( mixed $value ): int { return abs( (int) $value ); }
function current_time( string $type, bool $gmt = false ): string { return TEST_NOW; }
function is_user_logged_in(): bool { return true; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
}
class WP_REST_Request {}
class WP_REST_Response {}

class Papelito_Messaging_Transaction_Test_WPDB {
	public string $prefix = 'wp_';
	// Espelha `$wpdb->insert_id`: é o nome que o código sob teste consulta.
	public int $insert_id = 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.NotSnakeCaseMemberVar
	public bool $failMessageInsert = false;
	public array $queries = array();
	public array $inserts = array();

	public function query( string $query ): bool {
		$this->queries[] = $query;
		return true;
	}

	public function insert( string $table, array $data, ?array $format = null ): bool|int {
		$this->inserts[] = array( 'table' => $table, 'data' => $data );

		if ( $this->failMessageInsert && str_contains( $table, PAPELITO_MESSAGES_TABLE ) ) {
			return false;
		}

		$this->insert_id = count( $this->inserts );
		return 1;
	}

	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_var( string $query ): int { return 0; }
	public function get_row( string $query, mixed $output = null ): mixed { return null; }
	public function get_results( string $query, mixed $output = null ): array { return array(); }
	public function update( string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null ): int { return 1; }
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
	'created_at'  => TEST_NOW,
	'updated_at'  => TEST_NOW,
);
$thread_format = array( '%d', '%d', '%d', '%s', '%s' );

$wpdb->failMessageInsert = true;
$failed = papelito_messaging_create_thread_with_initial_message( $thread_data, $thread_format, 17, 'Primeira mensagem' );

papelito_messaging_transaction_assert( is_wp_error( $failed ), 'falha da primeira mensagem retorna erro' );
papelito_messaging_transaction_assert( in_array( TEST_BEGIN, $wpdb->queries, true ), 'abre transação antes de criar a conversa' );
papelito_messaging_transaction_assert( in_array( 'ROLLBACK', $wpdb->queries, true ), 'falha da primeira mensagem desfaz a conversa' );
papelito_messaging_transaction_assert( ! in_array( 'COMMIT', $wpdb->queries, true ), 'falha não confirma thread sem mensagem' );

$wpdb = new Papelito_Messaging_Transaction_Test_WPDB();
$created = papelito_messaging_create_thread_with_initial_message( $thread_data, $thread_format, 17, 'Primeira mensagem' );

papelito_messaging_transaction_assert( ! is_wp_error( $created ), 'criação íntegra retorna os IDs gerados' );
papelito_messaging_transaction_assert( 1 === ( $created['thread_id'] ?? 0 ), 'devolve o id da thread inserida' );
papelito_messaging_transaction_assert( 2 === ( $created['message_id'] ?? 0 ), 'devolve o id da mensagem inserida' );
papelito_messaging_transaction_assert( array( TEST_BEGIN, 'COMMIT' ) === $wpdb->queries, 'confirma a thread e a primeira mensagem na mesma transação' );
papelito_messaging_transaction_assert( 2 === count( $wpdb->inserts ), 'insere uma thread e sua primeira mensagem' );
papelito_messaging_transaction_assert( str_contains( $wpdb->inserts[1]['table'], PAPELITO_MESSAGES_TABLE ), 'a segunda escrita é a mensagem inicial' );
papelito_messaging_transaction_assert( array_key_exists( 'body_content', $wpdb->inserts[1]['data'] ) && null === $wpdb->inserts[1]['data']['body_content'], 'mensagem sem formatação grava conteúdo nulo' );

// O chamado carrega motivo e status desde a primeira escrita, e a forma transacional não muda
// por causa disso: um `$wpdb->query()` a mais aqui quebraria a garantia acima.
$wpdb = new Papelito_Messaging_Transaction_Test_WPDB();
$chamado_data = array_merge(
	$thread_data,
	array(
		'reason'        => 'devolucao',
		'return_reason' => 'defective',
		'reason_other'  => null,
		'status'        => 'ABERTO',
	)
);
$content = array( array( 'type' => 'text', 'text' => 'Motivo: ', 'bold' => true ) );
$chamado = papelito_messaging_create_thread_with_initial_message(
	$chamado_data,
	array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
	17,
	'Motivo: Produto com defeito',
	$content
);

papelito_messaging_transaction_assert( ! is_wp_error( $chamado ), 'chamado com motivo é criado' );
papelito_messaging_transaction_assert( array( TEST_BEGIN, 'COMMIT' ) === $wpdb->queries, 'chamado com conteúdo continua em uma transação só' );
papelito_messaging_transaction_assert( 'ABERTO' === $wpdb->inserts[0]['data']['status'], 'o chamado nasce aberto' );
papelito_messaging_transaction_assert( 'devolucao' === $wpdb->inserts[0]['data']['reason'], 'o motivo é gravado na própria linha do chamado' );
papelito_messaging_transaction_assert( json_encode( $content ) === $wpdb->inserts[1]['data']['body_content'], 'o conteúdo formatado é serializado como JSON' );

$wpdb = new Papelito_Messaging_Transaction_Test_WPDB();
$wpdb->failMessageInsert = true;
$rollback = papelito_messaging_create_thread_with_initial_message( $chamado_data, array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ), 17, 'x', $content );

papelito_messaging_transaction_assert( is_wp_error( $rollback ), 'falha da mensagem formatada também retorna erro' );
papelito_messaging_transaction_assert( in_array( 'ROLLBACK', $wpdb->queries, true ) && ! in_array( 'COMMIT', $wpdb->queries, true ), 'falha da mensagem formatada desfaz a conversa' );

echo "{$checks} verificacoes, {$failures} falhas\n";

exit( $failures > 0 ? 1 : 0 );
