<?php
/**
 * Regression test for cleaning company records after deleting their only owner.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

function papelito_company_table_names(): array {
	return array(
		'profiles'    => 'wp_papelito_customer_profiles',
		'companies'   => 'wp_papelito_companies',
		'members'     => 'wp_papelito_company_members',
		'invitations' => 'wp_papelito_company_invitations',
		'audit'       => 'wp_papelito_company_audit_log',
		'idempotency' => 'wp_papelito_company_idempotency',
		'onboarding'  => 'wp_papelito_b2b_onboarding',
		'legacy_email_log' => 'wp_papelito_b2b_legacy_email_log',
		'pre_account_applications' => 'wp_papelito_company_pre_account_applications',
	);
}

class Papelito_Company_Delete_User_Cleanup_Test_WP_User {
	public function __construct( public int $ID, public string $user_email ) {}
}

/** O hook roda em `delete_user`, antes da remocao: o e-mail do alvo ainda resolve. */
function get_userdata( int $user_id ) {
	return $GLOBALS['pap_users'][ $user_id ] ?? false;
}
function current_time( string $type, bool $gmt = false ): string { return '2026-09-04 12:00:00'; }

class WP_User extends Papelito_Company_Delete_User_Cleanup_Test_WP_User {}

class Papelito_Company_Delete_User_Cleanup_Test_WPDB {
	public array $deleted = array();
	public array $updated = array();
	public array $queries = array();
	public array $owned_companies = array( array( 'id' => 12 ) );
	public int $other_members = 0;

	public function prepare( string $query, mixed ...$args ): string {
		$flat = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		foreach ( $flat as $arg ) {
			$query = preg_replace( '/%[dsf]/', is_string( $arg ) ? "'" . $arg . "'" : (string) $arg, $query, 1 );
		}
		return $query;
	}

	public function query( string $query ): int {
		$this->queries[] = $query;

		return 1;
	}

	public function get_results( string $query, string $output ): array {
		return $this->owned_companies;
	}

	public function get_var( string $query ): int { return $this->other_members; }

	public function delete( string $table, array $where, array $formats ): int {
		$this->deleted[] = array( $table, $where, $formats );

		return 1;
	}

	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int {
		$this->updated[] = array( $table, $data, $where );

		return 1;
	}
}

$wpdb                   = new Papelito_Company_Delete_User_Cleanup_Test_WPDB();
$GLOBALS['pap_users']   = array(
	2175 => new WP_User( 2175, 'Dono@Acme.com' ),
	2176 => new WP_User( 2176, 'membro@acme.com' ),
);

require __DIR__ . '/../includes/company_repository.php';

papelito_company_cleanup_deleted_user( 2175 );

$deleted_tables = array_column( $wpdb->deleted, 0 );

foreach ( array( 'wp_papelito_company_members', 'wp_papelito_company_invitations', 'wp_papelito_company_audit_log', 'wp_papelito_company_idempotency', 'wp_papelito_companies', 'wp_papelito_b2b_onboarding' ) as $table ) {
	if ( ! in_array( $table, $deleted_tables, true ) ) {
		echo "FAIL: {$table} must be cleaned up\n";
		exit( 1 );
	}
}

echo "PASS: orphan company data is cleaned up\n";

$wpdb = new Papelito_Company_Delete_User_Cleanup_Test_WPDB();
$wpdb->owned_companies = array();
papelito_company_cleanup_deleted_user( 2176 );

$member_cleanup_found = false;
foreach ( $wpdb->deleted as $deletion ) {
	if ( 'wp_papelito_company_members' === $deletion[0] && array( 'user_id' => 2176 ) === $deletion[1] ) {
		$member_cleanup_found = true;
		break;
	}
}

if ( ! $member_cleanup_found ) {
	echo "FAIL: memberships of non-owner users must be cleaned up\n";
	exit( 1 );
}

echo "PASS: non-owner membership is cleaned up\n";

/* --- convites ENDERECADOS ao usuario removido nao podem sobreviver --- */
$closing = '';
foreach ( $wpdb->queries as $query ) {
	if ( str_contains( $query, 'wp_papelito_company_invitations' ) && str_contains( $query, 'invited_email' ) ) {
		$closing = $query;
		break;
	}
}

if ( '' === $closing ) {
	echo "FAIL: invitations addressed to the deleted e-mail must be closed\n";
	exit( 1 );
}

// Um convite pendente sobrevivente readmitiria quem recriasse a conta com o mesmo e-mail; um
// convite 'accepted' sobrevivente afirma na lista da empresa que aquele e-mail ainda e membro.
foreach ( array( "'pending'", "'accepted'", "'revoked'", "'invited_user_deleted'" ) as $needle ) {
	if ( ! str_contains( $closing, $needle ) ) {
		echo "FAIL: closing query must handle {$needle}\n";
		exit( 1 );
	}
}

if ( ! str_contains( $closing, "'membro@acme.com'" ) ) {
	echo "FAIL: closing query must match the deleted user e-mail in lowercase\n";
	exit( 1 );
}

echo "PASS: invitations addressed to the deleted user are closed\n";
