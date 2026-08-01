<?php
/**
 * Retenção da candidatura pré-conta: purge de documento, purge de PII e varredura de expiração.
 *
 * Executar: php public_html/wp-content/plugins/plugin_papelito/tests/test-pre-account-retention.php
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
	public string $code;
	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code = $code;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}
function current_time( string $type, bool $gmt = false ): string {
	return '2026-08-01 12:00:00';
}
function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}
function sanitize_key( string $value ): string {
	return strtolower( $value );
}
function sanitize_textarea_field( string $value ): string {
	return trim( $value );
}
function wp_json_encode( mixed $value ): string {
	return json_encode( $value );
}

$papelito_scheduled = array();
function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {}
function wp_next_scheduled( string $hook, array $args = array() ) {
	return false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): void {}
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): void {
	global $papelito_scheduled;
	$papelito_scheduled[] = array( $hook, $args );
}

/* --- Dependências externas ao módulo sob teste --- */
$papelito_rows = array();
function papelito_company_table_names(): array {
	return array( 'pre_account_applications' => 'wp_papelito_company_pre_account_applications' );
}
function papelito_company_document_key_is_valid( string $key ): bool {
	return 1 === preg_match( '/^[A-Za-z0-9_-]+\.(jpg|png|pdf)$/', $key );
}
function papelito_company_documents_prepare_dir() {
	return sys_get_temp_dir() . '/papelito-test-docs';
}
function papelito_pii_decrypt( string $value ) {
	return $value;
}
function papelito_pii_encrypt( string $value ) {
	return $value;
}

class Papelito_Test_WPDB {
	public array $updates = array();
	public array $queries = array();
	public array $rows_to_return = array();

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $query, 1 );
		}
		return $query;
	}
	public function update( string $table, array $data, array $where ) {
		$this->updates[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);
		return 1;
	}
	public function query( string $query ) {
		$this->queries[] = $query;
		return 3;
	}
	public function get_col( string $query ) {
		return $this->rows_to_return;
	}
	public function get_row( string $query, mixed $output = null ) {
		global $papelito_rows;
		if ( 1 === preg_match( '/WHERE id = (\d+)/', $query, $matches ) ) {
			return $papelito_rows[ (int) $matches[1] ] ?? null;
		}
		return null;
	}
}
$wpdb = new Papelito_Test_WPDB();

require __DIR__ . '/../includes/company_pre_account_applications.php';

$failures = 0;
function retention_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

function base_row( int $id, string $status, ?string $key = null ): array {
	return array(
		'id'                       => $id,
		'application_status'       => $status,
		'document_storage_key'     => $key,
		'contact_email_ciphertext' => 'cifra-email',
		'full_name_ciphertext'     => 'cifra-nome',
		'phone_ciphertext'         => 'cifra-telefone',
		'cpf_ciphertext'           => 'cifra-cpf',
		'birth_date_ciphertext'    => 'cifra-nascimento',
		'address_ciphertext'       => 'cifra-endereco',
		'password_hash'            => '$P$hashfake',
		'canonical_cnpj'           => '12345678000195',
		'is_open'                  => 'pending_manual_review' === $status ? 1 : null,
	);
}

/* --- 1. Candidatura ainda pendente nunca perde o documento --- */
$papelito_rows = array( 1 => base_row( 1, 'pending_manual_review', 'doc-1.pdf' ) );
$wpdb->updates = array();
retention_assert( 'pendente nao purga documento', false, papelito_pre_account_application_purge_document( 1 ) );
retention_assert( 'pendente nao grava update', 0, count( $wpdb->updates ) );

/* --- 2. Candidatura reprovada sem documento encerra sem tocar em disco --- */
$papelito_rows = array( 2 => base_row( 2, 'rejected', null ) );
$wpdb->updates = array();
retention_assert( 'reprovada sem documento e idempotente', true, papelito_pre_account_application_purge_document( 2 ) );
retention_assert( 'reprovada sem documento nao grava update', 0, count( $wpdb->updates ) );

/* --- 3. Documento com chave inválida reagenda em vez de silenciar --- */
$papelito_rows      = array( 3 => base_row( 3, 'rejected', '../../etc/passwd' ) );
$wpdb->updates      = array();
$papelito_scheduled = array();
retention_assert( 'chave invalida nao apaga', false, papelito_pre_account_application_purge_document( 3 ) );
retention_assert( 'chave invalida reagenda', 1, count( $papelito_scheduled ) );
retention_assert( 'reagenda no hook certo', PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK, $papelito_scheduled[0][0] ?? null );

/* --- 4. Purge de PII zera todo dado reversível e mantém o rastro auditável --- */
$papelito_rows = array( 4 => base_row( 4, 'rejected', null ) );
$wpdb->updates = array();
retention_assert( 'purge de pii aplica update', true, papelito_pre_account_application_purge_pii( 4 ) );
$purged = $wpdb->updates[ count( $wpdb->updates ) - 1 ]['data'] ?? array();
foreach ( array( 'contact_email_ciphertext', 'full_name_ciphertext', 'phone_ciphertext', 'cpf_ciphertext', 'birth_date_ciphertext', 'address_ciphertext' ) as $column ) {
	retention_assert( "purge zera {$column}", '', $purged[ $column ] ?? null );
}
// `??` também dispara quando o valor é null, que é exatamente o esperado aqui: testar a
// presença da chave e o valor separadamente.
retention_assert( 'purge escreve password_hash', true, array_key_exists( 'password_hash', $purged ) );
retention_assert( 'purge remove hash de senha', null, $purged['password_hash'] );
retention_assert( 'purge invalida token de retomada', '', $purged['resume_token_hash'] ?? null );
retention_assert( 'purge escreve evidence_json', true, array_key_exists( 'evidence_json', $purged ) );
retention_assert( 'purge descarta evidencia do provedor', null, $purged['evidence_json'] );
retention_assert( 'purge preserva cnpj para auditoria', false, array_key_exists( 'canonical_cnpj', $purged ) );
retention_assert( 'purge preserva decisao para auditoria', false, array_key_exists( 'decided_by_user_id', $purged ) );

/* --- 5. Varredura expira as abertas vencidas e purga as que passaram do TTL --- */
$papelito_rows           = array(
	5 => base_row( 5, 'rejected', null ),
	6 => base_row( 6, 'approved', null ),
);
$wpdb->updates           = array();
$wpdb->queries           = array();
$wpdb->rows_to_return    = array( 5, 6 );
$sweep                   = papelito_pre_account_applications_sweep();
retention_assert( 'varredura expira abertas vencidas', 3, $sweep['expired'] );
retention_assert( 'varredura purga as vencidas', 2, $sweep['purged'] );
retention_assert( 'varredura fecha o is_open', true, false !== strpos( $wpdb->queries[0] ?? '', 'is_open = NULL' ) );
retention_assert( 'varredura so mexe em vencidas', true, false !== strpos( $wpdb->queries[0] ?? '', 'expires_at <' ) );

echo $failures > 0 ? "\n{$failures} FALHA(S)\n" : "\nTodos os testes de retenção passaram.\n";
exit( $failures > 0 ? 1 : 0 );
