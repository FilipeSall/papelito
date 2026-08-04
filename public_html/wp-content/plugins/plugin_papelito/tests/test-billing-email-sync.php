<?php
/**
 * Standalone regression test for billing-email sync and backfill.
 *
 * Google verificado, cadastro tradicional antes e depois de confirmar o e-mail principal, e o
 * backfill que nunca toca endereco diferente do e-mail da conta.
 *
 * Usage: php tests/test-billing-email-sync.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['pap_companies'] = array();
$GLOBALS['pap_members']   = array();
$GLOBALS['pap_users']     = array();
$GLOBALS['pap_meta']      = array();
$GLOBALS['pap_audit']     = array();
$GLOBALS['pap_actions']   = array();

function add_action( $hook, $callback = null, ...$rest ) { $GLOBALS['pap_actions'][ $hook ][] = $callback; }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['pap_actions'][ $hook ] ?? array() as $callback ) {
		call_user_func_array( $callback, $args );
	}
}
function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ? $email : '';
}
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function current_time( $type, $gmt = 0 ) { return '2026-08-04 12:00:00'; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function get_userdata( $id ) { return $GLOBALS['pap_users'][ $id ] ?? false; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['pap_meta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { $GLOBALS['pap_meta'][ $id ][ $key ] = $value; return true; }
function delete_user_meta( $id, $key ) { unset( $GLOBALS['pap_meta'][ $id ][ $key ] ); return true; }
function papelito_auth_requires_email_verification( $id ) {
	$status = (string) get_user_meta( $id, 'papelito_email_verification_status', true );
	if ( '' === $status ) { return false; }
	return 'verified' !== $status;
}
function papelito_auth_current_utc_mysql() { return '2026-08-04 12:00:00'; }
/**
 * Espelha papelito_auth_mark_email_verified(), inclusive o do_action que liga a sincronizacao.
 */
function papelito_auth_mark_email_verified( $id ) {
	update_user_meta( $id, 'papelito_email_verification_status', 'verified' );
	update_user_meta( $id, 'papelito_email_verified_at', papelito_auth_current_utc_mysql() );
	do_action( 'papelito_email_verified', $id );
}
function papelito_company_table_names() { return array( 'companies' => 'companies', 'members' => 'members' ); }
function papelito_company_audit( $company_id, $actor, $action, $payload = array() ) {
	$GLOBALS['pap_audit'][] = array( 'company_id' => $company_id, 'action' => $action, 'payload' => $payload );
}
function papelito_company_update( $company_id, $fields ) {
	if ( ! isset( $GLOBALS['pap_companies'][ $company_id ] ) ) {
		return new WP_Error( 'papelito_company_update_failed', 'Empresa inexistente.' );
	}
	foreach ( $fields as $key => $value ) {
		$GLOBALS['pap_companies'][ $company_id ][ $key ] = $value;
	}
	return true;
}
function papelito_company_member_is_operationally_active( $member ) {
	return null !== $member && 'active' === (string) ( $member['member_status'] ?? '' );
}

class WP_User {
	public $ID; public $user_email;
	public function __construct( $id, $email ) { $this->ID = $id; $this->user_email = $email; }
}
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/**
 * Reimplementa em memoria as duas consultas do modulo, preservando os filtros de cada uma.
 */
class Papelito_Test_Wpdb {
	public $last_error = '';
	private $mode = '';
	private $args = array();

	public function prepare( $sql, ...$args ) {
		$this->args = $args;
		$this->mode = str_contains( $sql, 'LEFT JOIN' ) ? 'candidates' : 'backfill';
		return $sql;
	}

	public function get_row( $sql, $output = null ) {
		foreach ( $GLOBALS['pap_companies'] as $id => $company ) {
			if ( (int) $id === (int) ( $this->args[0] ?? 0 ) ) {
				return array_merge( array( 'id' => $id ), $company );
			}
		}
		return null;
	}

	public function get_results( $sql, $output = null ) {
		if ( 'candidates' === $this->mode ) {
			$user_id = (int) ( $this->args[0] ?? 0 );
			$rows    = array();
			foreach ( $GLOBALS['pap_companies'] as $id => $company ) {
				if ( null !== $company['billing_email_verified_at'] ) { continue; }
				$member = $GLOBALS['pap_members'][ $id ][ $user_id ] ?? null;
				if ( (int) ( $company['owner_user_id'] ?? 0 ) !== $user_id && null === $member ) { continue; }
				$rows[] = array_merge(
					array( 'id' => $id ),
					$company,
					array(
						'member_role'   => $member['member_role'] ?? null,
						'member_status' => $member['member_status'] ?? null,
						'expires_at'    => $member['expires_at'] ?? null,
					)
				);
			}
			return $rows;
		}

		$rows = array();
		foreach ( $GLOBALS['pap_companies'] as $id => $company ) {
			if ( null === $company['billing_email_verified_at'] ) {
				$rows[] = array_merge( array( 'id' => $id ), $company );
			}
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new Papelito_Test_Wpdb();

require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/billing_email_sync.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n";
	}
}

function papelito_test_company( int $id, string $billing_email, int $owner_id, ?string $verified_at = null, ?string $pending = null ): void {
	$GLOBALS['pap_companies'][ $id ] = array(
		'billing_email'                    => $billing_email,
		'billing_email_verified_at'        => $verified_at,
		'pending_billing_email'            => $pending,
		'pending_billing_email_token_hash' => null === $pending ? null : 'hash',
		'pending_billing_email_expires_at' => null === $pending ? null : '2026-08-05 12:00:00',
		'owner_user_id'                    => $owner_id,
		'created_by_user_id'               => $owner_id,
	);
}

/* --- conta Google: e-mail principal ja verificado pelo provedor --- */
$GLOBALS['pap_users'][10] = new WP_User( 10, 'Dono@Empresa.com' );
$GLOBALS['pap_meta'][10]  = array(
	'papelito_email_verification_status'  => 'verified',
	'papelito_email_verified_at'          => '2026-07-20 09:00:00',
	'papelito_email_verification_method'  => 'google',
);
papelito_test_company( 1, 'dono@empresa.com', 10 );
papelito_assert( 'google: confirma a empresa cujo faturamento e o proprio e-mail', 1, papelito_billing_email_sync_for_user( 10 ) );
papelito_assert( 'google: verified_at herda o da conta', '2026-07-20 09:00:00', $GLOBALS['pap_companies'][1]['billing_email_verified_at'] );
papelito_assert( 'google: auditoria marca a origem', 'account_email', $GLOBALS['pap_audit'][0]['payload']['source'] );
papelito_assert( 'google: caixa diferente nao impede', 'dono@empresa.com', $GLOBALS['pap_companies'][1]['billing_email'] );
papelito_assert( 'google: rodar de novo e no-op', 0, papelito_billing_email_sync_for_user( 10 ) );

/* --- conta Google que trocou o faturamento para outro endereco: nao herda nada --- */
papelito_test_company( 2, 'contabilidade@parceiro.com', 10 );
papelito_assert( 'google: endereco de terceiro nao e confirmado', 0, papelito_billing_email_sync_for_user( 10 ) );
papelito_assert( 'google: endereco de terceiro segue pendente', null, $GLOBALS['pap_companies'][2]['billing_email_verified_at'] );
unset( $GLOBALS['pap_companies'][2] );

/* --- cadastro tradicional antes de confirmar o e-mail principal --- */
$GLOBALS['pap_users'][11] = new WP_User( 11, 'novo@empresa.com' );
$GLOBALS['pap_meta'][11]  = array( 'papelito_email_verification_status' => 'pending' );
papelito_test_company( 3, 'novo@empresa.com', 11 );
papelito_assert( 'tradicional pendente: nao confirma faturamento', 0, papelito_billing_email_sync_for_user( 11 ) );
papelito_assert( 'tradicional pendente: segue nao confirmado', null, $GLOBALS['pap_companies'][3]['billing_email_verified_at'] );

/* --- e depois de confirmar: o hook resolve sozinho, sem segundo e-mail --- */
$GLOBALS['pap_audit'] = array();
papelito_auth_mark_email_verified( 11 );
papelito_assert( 'tradicional confirmado: faturamento confirmado pelo hook', '2026-08-04 12:00:00', $GLOBALS['pap_companies'][3]['billing_email_verified_at'] );
papelito_assert( 'tradicional confirmado: uma auditoria', 1, count( $GLOBALS['pap_audit'] ) );

/* --- pendencia para o MESMO endereco e absorvida; para outro endereco sobrevive --- */
papelito_test_company( 4, 'dono@empresa.com', 10, null, 'dono@empresa.com' );
papelito_billing_email_sync_for_user( 10 );
papelito_assert( 'pendencia do mesmo endereco e limpa', null, $GLOBALS['pap_companies'][4]['pending_billing_email'] );
papelito_assert( 'hash do mesmo endereco e limpo', null, $GLOBALS['pap_companies'][4]['pending_billing_email_token_hash'] );

papelito_test_company( 5, 'dono@empresa.com', 10, null, 'outro@empresa.com' );
papelito_billing_email_sync_for_user( 10 );
papelito_assert( 'troca em andamento sobrevive a sincronizacao', 'outro@empresa.com', $GLOBALS['pap_companies'][5]['pending_billing_email'] );
papelito_assert( 'e o endereco antigo fica confirmado', '2026-07-20 09:00:00', $GLOBALS['pap_companies'][5]['billing_email_verified_at'] );

/* --- membro comum nao confirma o endereco fiscal da empresa --- */
$GLOBALS['pap_users'][12] = new WP_User( 12, 'comprador@empresa.com' );
$GLOBALS['pap_meta'][12]  = array( 'papelito_email_verification_status' => 'verified', 'papelito_email_verified_at' => '2026-07-01 00:00:00' );
papelito_test_company( 6, 'comprador@empresa.com', 10 );
$GLOBALS['pap_members'][6][12] = array( 'member_role' => 'buyer', 'member_status' => 'active' );
papelito_assert( 'membro buyer nao confirma faturamento', 0, papelito_billing_email_sync_for_user( 12 ) );

$GLOBALS['pap_members'][6][12] = array( 'member_role' => 'admin', 'member_status' => 'active' );
papelito_assert( 'membro admin confirma faturamento', 1, papelito_billing_email_sync_for_user( 12 ) );

/* --- membro admin expirado/inativo nao confirma --- */
papelito_test_company( 7, 'comprador@empresa.com', 10 );
$GLOBALS['pap_members'][7][12] = array( 'member_role' => 'admin', 'member_status' => 'suspended' );
papelito_assert( 'membro admin inativo nao confirma', 0, papelito_billing_email_sync_for_user( 12 ) );

/* --- backfill: diagnostico nao grava nada --- */
$GLOBALS['pap_companies'] = array();
$GLOBALS['pap_members']   = array();
papelito_test_company( 20, 'dono@empresa.com', 10 );                 // casa com a conta verificada
papelito_test_company( 21, 'DONO@Empresa.com', 10 );                 // casa, so muda a caixa
papelito_test_company( 22, 'contabilidade@parceiro.com', 10 );        // endereco diferente: intocado
papelito_test_company( 23, 'novo@empresa.com', 13 );                  // conta com e-mail pendente
papelito_test_company( 24, 'orfa@empresa.com', 999 );                 // sem usuario
$GLOBALS['pap_users'][13] = new WP_User( 13, 'novo@empresa.com' );
$GLOBALS['pap_meta'][13]  = array( 'papelito_email_verification_status' => 'pending' );

$dry = papelito_billing_email_backfill_run( false, 100 );
papelito_assert( 'dry-run varre todas', 5, $dry['scanned'] );
papelito_assert( 'dry-run conta as elegiveis', 2, $dry['matched'] );
papelito_assert( 'dry-run nao grava nada', 0, $dry['confirmed'] );
papelito_assert( 'dry-run separa endereco divergente', 1, $dry['email_differs'] );
papelito_assert( 'dry-run separa conta pendente', 1, $dry['account_pending'] );
papelito_assert( 'dry-run separa empresa sem usuario', 1, $dry['no_owner'] );
papelito_assert( 'dry-run lista amostra', array( 20, 21 ), $dry['sample'] );
papelito_assert( 'dry-run nao alterou o banco', null, $GLOBALS['pap_companies'][20]['billing_email_verified_at'] );

/* --- backfill: execucao confirma so o que casa --- */
$run = papelito_billing_email_backfill_run( true, 100 );
papelito_assert( 'execucao confirma as elegiveis', 2, $run['confirmed'] );
papelito_assert( 'empresa 20 confirmada', '2026-07-20 09:00:00', $GLOBALS['pap_companies'][20]['billing_email_verified_at'] );
papelito_assert( 'empresa 21 confirmada e normalizada', 'dono@empresa.com', $GLOBALS['pap_companies'][21]['billing_email'] );
papelito_assert( 'endereco divergente intocado', null, $GLOBALS['pap_companies'][22]['billing_email_verified_at'] );
papelito_assert( 'conta pendente intocada', null, $GLOBALS['pap_companies'][23]['billing_email_verified_at'] );
papelito_assert( 'empresa sem usuario intocada', null, $GLOBALS['pap_companies'][24]['billing_email_verified_at'] );

/* --- backfill e idempotente --- */
$again = papelito_billing_email_backfill_run( true, 100 );
papelito_assert( 'segunda execucao nao confirma nada', 0, $again['confirmed'] );
papelito_assert( 'segunda execucao ve menos empresas', 3, $again['scanned'] );

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( $failures === 0 ? 0 : 1 );
