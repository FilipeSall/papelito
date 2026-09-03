<?php
/**
 * Standalone regression test for account suspension.
 *
 * Cobre as tres garantias que a suspensao precisa manter: os guards da acao administrativa
 * (auto-suspensao, administrador, ultimo titular), a validacao da justificativa obrigatoria e a
 * precedencia do motivo `account_suspended` sobre os demais bloqueios de compra.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;
	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

class WP_User {
	// Os nomes $ID e $display_name sao da API publica do WP_User do WordPress: renomear quebraria
	// a fidelidade do stub ao objeto real que o codigo sob teste consome.
	public int $ID; // NOSONAR
	public array $roles;
	public array $caps;
	public string $display_name = 'Fulano'; // NOSONAR
	public function __construct( int $id, array $roles = array(), array $caps = array() ) {
		$this->ID    = $id;
		$this->roles = $roles;
		$this->caps  = $caps;
	}
}

$papelito_meta      = array();
$papelito_users     = array();
$papelito_cohorts   = array();
$papelito_company   = null;
$papelito_members   = array();
$papelito_owner_map = array();
$papelito_log       = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( trim( $value ) ); }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
function get_userdata( int $user_id ): ?WP_User { global $papelito_users; return $papelito_users[ $user_id ] ?? null; }
function user_can( WP_User $user, string $capability ): bool { return ! empty( $user->caps[ $capability ] ); }
function current_time( string $type, bool $gmt = false ): string { return '2026-09-02 12:00:00'; }
function do_action( string $hook, mixed ...$args ): void {
	// Stub deliberadamente vazio: o teste verifica o estado persistido, nao os listeners do hook.
}
function is_email( string $email ): bool { return false !== strpos( $email, '@' ); }
function papelito_user_has_role( WP_User $user, string $role ): bool { return in_array( $role, $user->roles, true ); }
function papelito_user_is_effective_seller( WP_User $user ): bool { return in_array( 'seller', $user->roles, true ); }
function papelito_b2b_is_cohort( int $user_id ): bool { global $papelito_cohorts; return ! empty( $papelito_cohorts[ $user_id ] ); }
function papelito_company_table_names(): array { return array( 'members' => 'members', 'companies' => 'companies' ); }
function papelito_company_purchasing_roles(): array { return array( 'owner', 'admin', 'buyer' ); }
function papelito_company_get( int $company_id ): ?array { global $papelito_company; return $papelito_company; }
function papelito_company_member_get( int $company_id, int $user_id ): ?array { global $papelito_members; return $papelito_members[ $user_id ] ?? null; }
function papelito_company_count_active_owners( int $company_id ): int { global $papelito_owner_map; return $papelito_owner_map[ $company_id ] ?? 0; }
function get_user_by() { return null; }

function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
	global $papelito_meta;
	return $papelito_meta[ $user_id ][ $key ] ?? '';
}
function update_user_meta( int $user_id, string $key, mixed $value ): bool {
	global $papelito_meta;
	$papelito_meta[ $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( int $user_id, string $key ): bool {
	global $papelito_meta;
	unset( $papelito_meta[ $user_id ][ $key ] );
	return true;
}

function papelito_company_members_active_for_user( int $user_id ): array {
	global $papelito_active_memberships;
	return $papelito_active_memberships[ $user_id ] ?? array();
}

function papelito_company_audit_log( int $company_id, string $action, ?int $actor_user_id = null, array $payload = array() ) {
	global $papelito_audit;
	$papelito_audit[] = array( 'companyId' => $company_id, 'action' => $action, 'payload' => $payload );
	return 1;
}

class Papelito_Test_WPDB {
	public string $prefix = 'wp_';
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_results( string $query, mixed $output = null ): array {
		global $papelito_company_members_rows;
		return str_contains( $query, 'FROM members' ) ? ( $papelito_company_members_rows ?? array() ) : array();
	}
	public function get_var( string $query ) { return 0; }
	public function get_row( string $query, mixed $output = null ) { global $papelito_company; return $papelito_company; }
	public function query( string $query ) {
		global $papelito_queries;
		$papelito_queries[] = $query;
		return true;
	}
	public function update( string $table, array $data, array $where ) {
		global $papelito_writes, $papelito_fail_update_at;
		$papelito_writes[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
		if ( null !== $papelito_fail_update_at && count( $papelito_writes ) === $papelito_fail_update_at ) {
			return false;
		}
		return 1;
	}
	public function insert( string $table, array $data ) {
		global $papelito_log;
		$papelito_log[] = $data;
		return 1;
	}
	public function get_charset_collate(): string { return ''; }
}
$wpdb = new Papelito_Test_WPDB();

require_once __DIR__ . '/../includes/account_status.php';
require_once __DIR__ . '/../includes/company_services.php';

$papelito_users = array(
	1 => new WP_User( 1, array( 'administrator' ), array( 'manage_options' => true ) ),
	2 => new WP_User( 2, array( 'seller' ) ),
	4 => new WP_User( 4, array( 'customer' ) ),
	6 => new WP_User( 6, array( 'customer' ) ),
);
$papelito_active_memberships = array(
	6 => array( array( 'company_id' => 10, 'member_role' => 'owner', 'member_status' => 'active' ) ),
);
$papelito_owner_map = array( 10 => 1 );
$papelito_audit     = array();
$papelito_writes    = array();
$papelito_queries   = array();
$papelito_fail_update_at = null;
$papelito_company_members_rows = array();
$papelito_company   = array(
	'id' => 10,
	'company_status' => 'active',
	'registry_status' => 'active',
	'ownership_status' => 'verified',
	'billing_email_verified_at' => '2026-09-02 12:00:00',
	'billing_email' => 'fiscal@empresa.test',
	'legal_name' => 'Empresa Teste',
	'trade_name' => 'Empresa Teste',
	'phone' => '11999999999',
	'cnpj' => '12345678000195',
	'fiscal_cep' => '01001000',
	'fiscal_state' => 'SP',
	'fiscal_city' => 'Sao Paulo',
	'fiscal_neighborhood' => 'Se',
	'fiscal_street' => 'Praca da Se',
	'fiscal_number' => '1',
);
$papelito_members = array(
	4 => array( 'id' => 40, 'member_role' => 'buyer', 'member_status' => 'active', 'identity_requirement' => 'not_required', 'expires_at' => null ),
	6 => array( 'id' => 60, 'member_role' => 'owner', 'member_status' => 'active', 'identity_requirement' => 'not_required', 'expires_at' => null ),
);

$failures = 0;

function account_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

function account_assert_err( string $label, mixed $actual, string $expected_code ): void {
	account_assert_error( $label, $actual, $expected_code );
}

function account_assert_error( string $label, mixed $actual, string $expected_code ): void {
	global $failures;
	if ( $actual instanceof WP_Error && $actual->get_error_code() === $expected_code ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	$got = $actual instanceof WP_Error ? $actual->get_error_code() : var_export( $actual, true );
	echo "FAIL: {$label} expected={$expected_code} actual={$got}\n";
}

const SUSPENSION_REASON = 'Fraude confirmada no pedido 1234.';

function account_context( ?int $company_id = 10 ): array {
	return array(
		'identityStatus' => 'verified',
		'companyId' => $company_id,
		'companySelectionRequired' => false,
	);
}

account_assert_error( 'nao suspende a propria conta', papelito_account_can_suspend( 1, 1 ), 'papelito_account_cannot_suspend_self' );
account_assert_error( 'nao suspende administrador', papelito_account_can_suspend( 1, 9 ), 'papelito_account_cannot_suspend_admin' );
account_assert_error( 'nao suspende usuario inexistente', papelito_account_can_suspend( 999, 1 ), 'papelito_account_user_not_found' );
account_assert_error( 'unico membro ativo bloqueia a suspensao', papelito_account_can_suspend( 6, 1 ), 'papelito_account_no_ownership_successor' );
account_assert_same( 'customer comum pode ser suspenso', true, papelito_account_can_suspend( 4, 1 ) );

$papelito_owner_map = array( 10 => 2 );
account_assert_same( 'titular com par ativo pode ser suspenso', true, papelito_account_can_suspend( 6, 1 ) );
$papelito_owner_map = array( 10 => 1 );

// Com sucessor disponivel, o unico titular ativo deixa de travar: a titularidade passa adiante.
$papelito_users[8] = new WP_User( 8, array( 'customer' ) );
$papelito_users[9] = new WP_User( 9, array( 'customer' ) );
$papelito_company_members_rows = array(
	array( 'user_id' => 9, 'member_role' => 'viewer', 'created_at' => '2026-03-01 00:00:00' ),
	array( 'user_id' => 8, 'member_role' => 'buyer', 'created_at' => '2026-01-01 00:00:00' ),
);
account_assert_same( 'sucessor e o membro ativo mais antigo', 9, (int) papelito_account_ownership_successor( 10, 6 )['user_id'] );
account_assert_same( 'titular unico deixa de bloquear quando ha sucessor', true, papelito_account_can_suspend( 6, 1 ) );

$suspended_owner = papelito_account_suspend( 6, 1, 'Titular suspenso por auditoria interna.' );
account_assert_same( 'suspensao do titular transfere a empresa', 9, $suspended_owner['ownershipTransfers'][0]['successorId'] );
account_assert_same( 'transferencia entra na auditoria da empresa', 'ownership_transferred_on_suspension', $papelito_audit[0]['action'] );
account_assert_same( 'novo titular vira owner', 'owner', $papelito_writes[1]['data']['member_role'] );
account_assert_same( 'titular anterior vira admin', 'admin', $papelito_writes[0]['data']['member_role'] );
account_assert_same( 'empresa aponta para o novo titular', 9, $papelito_writes[2]['data']['owner_user_id'] );
papelito_account_reactivate( 6, 1, '' );

// Falha no meio da sucessao: nada pode ficar pela metade — a conta continua ativa e a transacao
// volta atras, em vez de deixar empresa transferida com titular ainda ativo.
$papelito_audit          = array();
$papelito_writes         = array();
$papelito_queries        = array();
$papelito_fail_update_at = 2;
$failed = papelito_account_suspend( 6, 1, 'Tentativa que falha no meio da sucessao.' );
account_assert_err( 'falha na sucessao recusa a suspensao', $failed, 'papelito_account_suspend_failed' );
account_assert_same( 'conta segue ativa apos falha', 'active', papelito_account_status( 6 ) );
account_assert_same( 'transacao sofre rollback', true, in_array( 'ROLLBACK', $papelito_queries, true ) );
account_assert_same( 'nada entra na auditoria da empresa', 0, count( $papelito_audit ) );

$papelito_fail_update_at = null;
$papelito_company_members_rows = array();
$papelito_audit = array();
$papelito_writes = array();
$papelito_queries = array();

account_assert_error( 'justificativa e obrigatoria', papelito_account_suspend( 4, 1, '   ' ), 'papelito_account_reason_required' );
account_assert_error( 'justificativa curta e recusada', papelito_account_suspend( 4, 1, 'abc' ), 'papelito_account_reason_too_short' );
account_assert_error( 'justificativa longa e recusada', papelito_account_suspend( 4, 1, str_repeat( 'a', 501 ) ), 'papelito_account_reason_too_long' );
account_assert_same( 'conta comeca ativa', 'active', papelito_account_status( 4 ) );
account_assert_same( 'guard comercial passa quando ativa', true, papelito_account_guard_commercial( 4 ) );

$suspended = papelito_account_suspend( 4, 1, SUSPENSION_REASON );
account_assert_same( 'suspensao persiste o estado', 'suspended', papelito_account_status( 4 ) );
account_assert_same( 'suspensao devolve o novo estado', 'suspended', $suspended['status'] );
account_assert_same( 'suspensao registra a justificativa', SUSPENSION_REASON, papelito_account_suspension_details( 4 )['reason'] );
account_assert_same( 'suspensao registra o autor', 1, papelito_account_suspension_details( 4 )['actorUserId'] );
account_assert_same( 'suspensao entra no historico', 'suspend', $papelito_log[0]['action'] );

account_assert_error( 'guard comercial barra conta suspensa', papelito_account_guard_commercial( 4 ), 'papelito_account_suspended' );

$blocked = papelito_company_purchase_capability( 4, account_context() );
account_assert_same( 'conta suspensa nao compra', false, $blocked['canPurchase'] );
account_assert_same( 'conta suspensa fica em modo blocked', 'blocked', $blocked['purchaseMode'] );
account_assert_same( 'conta suspensa vence os demais motivos', 'account_suspended', $blocked['purchaseBlockReason'] );

$replayed = papelito_account_suspend( 4, 1, SUSPENSION_REASON );
account_assert_same( 'suspender duas vezes e idempotente', true, $replayed['replayed'] );

$reactivated = papelito_account_reactivate( 4, 1, 'Analise concluida.' );
account_assert_same( 'reativacao volta para ativa', 'active', papelito_account_status( 4 ) );
account_assert_same( 'reativacao devolve o novo estado', 'active', $reactivated['status'] );
account_assert_same( 'reativacao limpa a suspensao', null, papelito_account_suspension_details( 4 ) );
account_assert_same( 'reativacao dispensa justificativa', false, $reactivated['replayed'] );

$allowed = papelito_company_purchase_capability( 4, account_context() );
account_assert_same( 'conta reativada volta a comprar', true, $allowed['canPurchase'] );

$vendor = papelito_company_purchase_capability( 2, account_context() );
account_assert_same( 'vendor puro segue not_buyer', 'not_buyer', $vendor['purchaseMode'] );

exit( $failures > 0 ? 1 : 0 );
