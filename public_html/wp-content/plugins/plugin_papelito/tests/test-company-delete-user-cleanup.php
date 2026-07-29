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
	);
}

class Papelito_Company_Delete_User_Cleanup_Test_WPDB {
	public array $deleted = array();
	public array $updated = array();

	public function prepare( string $query, mixed ...$args ): string { return $query; }

	public function get_results( string $query, string $output ): array {
		return array( array( 'id' => 12 ) );
	}

	public function get_var( string $query ): int { return 0; }

	public function delete( string $table, array $where, array $formats ): int {
		$this->deleted[] = array( $table, $where, $formats );

		return 1;
	}

	public function update( string $table, array $data, array $where ): int {
		$this->updated[] = array( $table, $data, $where );

		return 1;
	}
}

$wpdb = new Papelito_Company_Delete_User_Cleanup_Test_WPDB();

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
