<?php
/**
 * Tentativas de pagamento contra o banco real.
 *
 * O standalone cobre a máquina de estados; aqui o alvo é o que só o MySQL prova: o compare-and-set
 * deixa uma única requisição vencer a emissão, `lock_version` impede gravação obsoleta e a
 * referência opaca é única.
 *
 *   docker compose exec -T -u www-data web wp eval-file \
 *     wp-content/plugins/plugin_papelito/tests/test-payment-attempts-db.php
 *
 * Usa um vendor_id reservado e apaga as próprias linhas no fim.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- WP-CLI test script prints results and toggles env flags by design.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-payment-attempts-db.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

const PAPELITO_ATTEMPTS_TEST_VENDOR = 999999101;

global $wpdb, $failures;

$failures = 0;

/**
 * Registra uma asserção.
 *
 * @param string $label     Descrição.
 * @param bool   $condition Resultado.
 * @return void
 */
function papelito_attempts_db_check( string $label, bool $condition ): void {
	global $failures;

	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

papelito_payment_attempts_install_table();
$table = papelito_payment_attempts_table_name();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE vendor_id = %d", PAPELITO_ATTEMPTS_TEST_VENDOR ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$base = array(
	'order_id'     => 0,
	'vendor_id'    => PAPELITO_ATTEMPTS_TEST_VENDOR,
	'provider'     => 'mercadopago',
	'environment'  => 'test',
	'account_id'   => 5,
	'account_ref'  => '12345',
	'amount_cents' => 4990,
	'flow'         => 'redirect',
);

$attempt = papelito_payment_attempt_create( $base );
papelito_attempts_db_check( 'tentativa nasce gravada em not_started', is_array( $attempt ) && 'not_started' === $attempt['state'] );
papelito_attempts_db_check( 'tentativa nasce com attemptRef válido', is_array( $attempt ) && papelito_payment_attempt_ref_is_valid( $attempt['attempt_ref'] ) );

$second = papelito_payment_attempt_create( $base );
papelito_attempts_db_check( 'cada tentativa ganha attemptRef próprio', is_array( $second ) && $second['attempt_ref'] !== $attempt['attempt_ref'] );

$first_win  = papelito_payment_attempt_transition( $attempt['id'], 'not_started', 'creating' );
$second_win = papelito_payment_attempt_transition( $attempt['id'], 'not_started', 'creating' );
papelito_attempts_db_check( 'somente uma requisição vence a emissão', $first_win && ! $second_win );

$illegal = papelito_payment_attempt_transition( $attempt['id'], 'creating', 'not_started' );
papelito_attempts_db_check( 'transição fora da máquina não chega ao banco', ! $illegal );

papelito_attempts_db_check(
	'timeout grava unknown com o motivo',
	papelito_payment_attempt_transition( $attempt['id'], 'creating', 'unknown', array( 'last_error_code' => 'unknown_outcome' ) )
);
$reloaded = papelito_payment_attempt_find( $attempt['id'] );
papelito_attempts_db_check( 'estado e motivo persistidos', 'unknown' === $reloaded['state'] && 'unknown_outcome' === $reloaded['last_error_code'] );
papelito_attempts_db_check( 'unknown não volta a creating por retry', ! papelito_payment_attempt_transition( $attempt['id'], 'unknown', 'creating' ) );

$stale = $reloaded;
papelito_attempts_db_check( 'gravação com versão atual vence', papelito_payment_attempt_update( $reloaded, array( 'remote_id' => 'pref-db-1', 'remote_payment_ids' => array( '111' ) ) ) );
papelito_attempts_db_check( 'gravação com versão obsoleta perde', ! papelito_payment_attempt_update( $stale, array( 'remote_id' => 'pref-velha' ) ) );

$by_remote = papelito_payment_attempt_find_by_remote( 'mercadopago', 'pref-db-1' );
papelito_attempts_db_check( 'busca por ID remoto acha a tentativa', is_array( $by_remote ) && $by_remote['id'] === $attempt['id'] && array( '111' ) === $by_remote['remote_payment_ids'] );
papelito_attempts_db_check( 'busca por ID remoto respeita o provider', null === papelito_payment_attempt_find_by_remote( 'pagarme', 'pref-db-1' ) );

$by_ref = papelito_payment_attempt_find_by_ref( $attempt['attempt_ref'] );
papelito_attempts_db_check( 'busca por attemptRef acha a tentativa', is_array( $by_ref ) && $by_ref['id'] === $attempt['id'] );
papelito_attempts_db_check( 'attemptRef malformado não consulta', null === papelito_payment_attempt_find_by_ref( "' OR 1=1 -- " ) );

$duplicate_ref = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$table,
	array(
		'attempt_ref'  => $attempt['attempt_ref'],
		'vendor_id'    => PAPELITO_ATTEMPTS_TEST_VENDOR,
		'provider'     => 'mercadopago',
		'environment'  => 'test',
		'amount_cents' => 1,
		'state'        => 'not_started',
		'created_at'   => current_time( 'mysql', true ),
		'updated_at'   => current_time( 'mysql', true ),
	)
);
papelito_attempts_db_check( 'banco recusa attemptRef duplicado', false === $duplicate_ref );

$due     = papelito_payment_attempts_due( 500 );
$due_ids = array_column( $due, 'id' );
papelito_attempts_db_check( 'tentativa unknown vencida entra no lote de reconciliação', in_array( $attempt['id'], $due_ids, true ) );
papelito_attempts_db_check( 'tentativa not_started não entra no lote', ! in_array( $second['id'], $due_ids, true ) );
papelito_payment_attempt_update( papelito_payment_attempt_find( $attempt['id'] ), array( 'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
papelito_attempts_db_check( 'tentativa agendada para depois fica fora do lote', ! in_array( $attempt['id'], array_column( papelito_payment_attempts_due( 500 ), 'id' ), true ) );

$awaiting_due = papelito_payment_attempt_create( array_merge( $base, array( 'provider' => 'pagarme', 'state' => 'awaiting_payment_input', 'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ) );
$watch_due    = papelito_payment_attempt_create( $base );
papelito_payment_attempt_transition( $watch_due['id'], 'not_started', 'creating' );
papelito_payment_attempt_transition( $watch_due['id'], 'creating', 'pending' );
papelito_payment_attempt_transition( $watch_due['id'], 'pending', 'expired', array( 'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
$done_due = papelito_payment_attempt_create( $base );
papelito_payment_attempt_transition( $done_due['id'], 'not_started', 'creating' );
papelito_payment_attempt_transition( $done_due['id'], 'creating', 'pending' );
papelito_payment_attempt_transition( $done_due['id'], 'pending', 'expired', array( 'next_reconcile_at' => null ) );
$due_ids = array_column( papelito_payment_attempts_due( 500 ), 'id' );
papelito_attempts_db_check( 'coleta aguardando dados vencida entra no lote', in_array( $awaiting_due['id'], $due_ids, true ) );
papelito_attempts_db_check( 'expirada sob vigilância entra no lote', in_array( $watch_due['id'], $due_ids, true ) );
papelito_attempts_db_check( 'expirada sem vigilância fica fora do lote', ! in_array( $done_due['id'], $due_ids, true ) );

papelito_attempts_db_check( 'trava nomeada é exclusiva da conexão que a obteve', papelito_payment_attempt_acquire_lock( 'order:test', 1 ) );
papelito_payment_attempt_release_lock( 'order:test' );

$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE vendor_id = %d", PAPELITO_ATTEMPTS_TEST_VENDOR ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
if ( $failures > 0 ) {
	exit( 1 );
}
