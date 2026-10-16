<?php
/**
 * Contas financeiras por vendor, provider e ambiente, contra o banco real.
 *
 *   docker compose exec -T -u www-data web wp eval-file \
 *     wp-content/plugins/plugin_papelito/tests/test-vendor-payment-accounts-db.php
 *
 * Usa vendor_ids reservados e apaga as próprias linhas no fim.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- WP-CLI test script prints results by design.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-vendor-payment-accounts-db.php\n" );
	exit( 1 );
}

const ACCOUNTS_TEST_VENDOR_A = 999999201;
const ACCOUNTS_TEST_VENDOR_B = 999999202;
const ACCOUNTS_TEST_TOKEN_A  = 'APP_USR-111-token-do-vendor-a';
const ACCOUNTS_TEST_TOKEN_B  = 'APP_USR-222-token-do-vendor-b';

global $wpdb, $failures;

$failures = 0;

function accounts_db_check( string $label, bool $condition ): void {
	global $failures;

	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

function accounts_db_cleanup(): void {
	global $wpdb;
	$table = papelito_vendor_payment_accounts_table_name();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE vendor_id IN (%d, %d)", ACCOUNTS_TEST_VENDOR_A, ACCOUNTS_TEST_VENDOR_B ) );
}

function accounts_db_connect( int $vendor_id, string $environment, string $collector, string $access ) {
	return papelito_vendor_payment_account_connect(
		$vendor_id,
		'mercadopago',
		$environment,
		array(
			'collector_id'          => $collector,
			'access_token'          => $access,
			'refresh_token'         => 'TG-refresh-' . $collector,
			'expires_in'            => 15552000,
			'public_key'            => 'APP_USR-public-' . $collector,
			'scope'                 => 'offline_access payments write',
			'live_mode'             => 'live' === $environment,
			'configuration_version' => 'cfg-1',
		)
	);
}

papelito_vendor_payment_accounts_install_table();
accounts_db_cleanup();
$table = papelito_vendor_payment_accounts_table_name();

$first = accounts_db_connect( ACCOUNTS_TEST_VENDOR_A, 'test', '9000001', ACCOUNTS_TEST_TOKEN_A );
accounts_db_check( 'conexão cria conta vigente', is_array( $first ) && 'active' === $first['status'] );
accounts_db_check( 'conta nasce com verificação de titularidade pendente', 'verification_pending' === $first['identity_status'] );

$raw_row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $first['id'] ), ARRAY_A );
accounts_db_check( 'tokens ficam cifrados no banco', false === strpos( wp_json_encode( $raw_row ), ACCOUNTS_TEST_TOKEN_A ) && false === strpos( wp_json_encode( $raw_row ), 'TG-refresh' ) );

$tokens = papelito_vendor_payment_account_tokens( $first );
accounts_db_check( 'adapter recebe o token do próprio vendor', ! is_wp_error( $tokens ) && ACCOUNTS_TEST_TOKEN_A === $tokens['access_token'] );

$public = papelito_vendor_payment_account_public( $first );
accounts_db_check( 'resumo público não expõe token', false === strpos( wp_json_encode( $public ), 'APP_USR-111' ) && false === strpos( wp_json_encode( $public ), 'TG-' ) && '9000001' === $public['collectorId'] );

$b_account = accounts_db_connect( ACCOUNTS_TEST_VENDOR_B, 'test', '9000002', ACCOUNTS_TEST_TOKEN_B );
$a_current = papelito_vendor_payment_account_current( ACCOUNTS_TEST_VENDOR_A, 'mercadopago', 'test' );
accounts_db_check( 'vendor A não recebe a conta de B', is_array( $a_current ) && '9000001' === $a_current['collector_id'] && ACCOUNTS_TEST_TOKEN_A === papelito_vendor_payment_account_tokens( $a_current )['access_token'] );
accounts_db_check( 'test e live não se misturam', null === papelito_vendor_payment_account_current( ACCOUNTS_TEST_VENDOR_A, 'mercadopago', 'live' ) );

$stolen = accounts_db_connect( ACCOUNTS_TEST_VENDOR_B, 'test', '9000001', 'APP_USR-x' );
accounts_db_check( 'collector vigente de A não pode ser vinculado a B', is_wp_error( $stolen ) && 'papelito_payment_account_collector_in_use' === $stolen->get_error_code() );
accounts_db_check( 'tentativa recusada não derruba a conta vigente de B', '9000002' === papelito_vendor_payment_account_current( ACCOUNTS_TEST_VENDOR_B, 'mercadopago', 'test' )['collector_id'] );

$accounts_break_insert = static function ( string $query ) use ( $table ): string {
	return 0 === strpos( $query, "INSERT INTO `{$table}`" ) ? 'INSERT INTO papelito_tabela_inexistente_teste VALUES (1)' : $query;
};
add_filter( 'query', $accounts_break_insert );
$broken = accounts_db_connect( ACCOUNTS_TEST_VENDOR_B, 'test', '9000009', 'APP_USR-quebrado' );
remove_filter( 'query', $accounts_break_insert );
$b_after_break = papelito_vendor_payment_account_current( ACCOUNTS_TEST_VENDOR_B, 'mercadopago', 'test' );
accounts_db_check( 'falha ao gravar a reconexão mantém a conta vigente anterior', is_wp_error( $broken ) && is_array( $b_after_break ) && '9000002' === $b_after_break['collector_id'] && 'active' === $b_after_break['status'] );

$live = accounts_db_connect( ACCOUNTS_TEST_VENDOR_A, 'live', '9000001', 'APP_USR-live' );
accounts_db_check( 'mesmo collector em outro ambiente é outra conta', is_array( $live ) );

$second = accounts_db_connect( ACCOUNTS_TEST_VENDOR_A, 'test', '9000003', 'APP_USR-a-nova' );
$current_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE vendor_id = %d AND provider = 'mercadopago' AND environment = 'test' AND status = 'active'", ACCOUNTS_TEST_VENDOR_A ) );
accounts_db_check( 'troca de conta deixa uma só vigente', 1 === $current_count && '9000003' === papelito_vendor_payment_account_current( ACCOUNTS_TEST_VENDOR_A, 'mercadopago', 'test' )['collector_id'] );
$historic = papelito_vendor_payment_account_find( (int) $first['id'] );
accounts_db_check( 'conta antiga continua endereçável para cobrança histórica', is_array( $historic ) && 'superseded' === $historic['status'] && '9000001' === $historic['collector_id'] );
accounts_db_check( 'conta histórica mantém credencial para consulta/estorno', ! is_wp_error( papelito_vendor_payment_account_tokens( $historic ) ) );
accounts_db_check( 'versão da conta avança na troca', (int) $second['account_version'] === (int) $first['account_version'] + 1 );

$reuse_old = accounts_db_connect( ACCOUNTS_TEST_VENDOR_B, 'test', '9000001', 'APP_USR-y' );
accounts_db_check( 'collector superado volta a ser vinculável', is_array( $reuse_old ) );
accounts_db_cleanup();
$second = accounts_db_connect( ACCOUNTS_TEST_VENDOR_A, 'test', '9000003', 'APP_USR-a-nova' );

$rotated = papelito_vendor_payment_account_rotate_tokens(
	$second,
	array(
		'access_token'  => 'APP_USR-renovado',
		'refresh_token' => 'TG-renovado',
		'expires_in'    => 15552000,
	)
);
accounts_db_check( 'rotação com versão atual grava', true === $rotated );
$stale_rotation = papelito_vendor_payment_account_rotate_tokens(
	$second,
	array(
		'access_token'  => 'APP_USR-obsoleto',
		'refresh_token' => 'TG-obsoleto',
		'expires_in'    => 15552000,
	)
);
accounts_db_check( 'rotação com versão obsoleta perde', false === $stale_rotation );
$after_rotation = papelito_vendor_payment_account_current( ACCOUNTS_TEST_VENDOR_A, 'mercadopago', 'test' );
accounts_db_check( 'token vigente é o da rotação vencedora', 'APP_USR-renovado' === papelito_vendor_payment_account_tokens( $after_rotation )['access_token'] );

$wpdb->update( $table, array( 'access_token_envelope' => 'k1:adulterado:x:y' ), array( 'id' => $after_rotation['id'] ) );
$tampered = papelito_vendor_payment_account_tokens( papelito_vendor_payment_account_find( (int) $after_rotation['id'] ) );
accounts_db_check( 'envelope adulterado falha fechado', is_wp_error( $tampered ) );

papelito_vendor_payment_account_disconnect( (int) $after_rotation['id'] );
$disconnected = papelito_vendor_payment_account_find( (int) $after_rotation['id'] );
accounts_db_check( 'desconexão tira a conta da vigência', null === papelito_vendor_payment_account_current( ACCOUNTS_TEST_VENDOR_A, 'mercadopago', 'test' ) );
accounts_db_check( 'desconexão preserva a referência histórica', is_array( $disconnected ) && 'disconnected' === $disconnected['status'] && '9000003' === $disconnected['collector_id'] );
accounts_db_check( 'desconexão apaga credenciais e exige reautorização', is_wp_error( papelito_vendor_payment_account_tokens( $disconnected ) ) );
$latest = papelito_vendor_payment_account_latest( ACCOUNTS_TEST_VENDOR_A, 'mercadopago', 'test' );
accounts_db_check( 'conta mais recente aparece mesmo desconectada, para o painel explicar', is_array( $latest ) && 'disconnected' === $latest['status'] );

delete_user_meta( ACCOUNTS_TEST_VENDOR_A, PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_META );
for ( $accounts_audit_index = 1; $accounts_audit_index <= PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_LIMIT + 2; $accounts_audit_index++ ) {
	papelito_vendor_payment_identity_audit_append(
		ACCOUNTS_TEST_VENDOR_A,
		array(
			'accountId'   => $accounts_audit_index,
			'collectorId' => '9000003',
			'status'      => 'verified',
			'actorId'     => 1,
			'evidenceRef' => 'ticket-' . $accounts_audit_index,
		)
	);
}
$accounts_audit = papelito_vendor_payment_identity_audit( ACCOUNTS_TEST_VENDOR_A );
accounts_db_check( 'trilha de identidade acumula sem sobrescrever', PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_LIMIT === count( $accounts_audit ) && 'ticket-3' === $accounts_audit[0]['evidenceRef'] && 'ticket-' . ( PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_LIMIT + 2 ) === end( $accounts_audit )['evidenceRef'] && '' !== (string) end( $accounts_audit )['at'] );
delete_user_meta( ACCOUNTS_TEST_VENDOR_A, PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_META );

papelito_vendor_payment_accounts_install_table();
accounts_db_check( 'migração repetida conserva dados', is_array( papelito_vendor_payment_account_find( (int) $after_rotation['id'] ) ) );

accounts_db_cleanup();

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
if ( $failures > 0 ) {
	exit( 1 );
}
