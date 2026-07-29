<?php
/**
 * Regression test for deleting customer PII together with the WordPress user.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class Papelito_Customer_Profile_Delete_Test_WPDB {
	public string $prefix = 'wp_';
	public array $deleted = array();

	public function delete( string $table, array $where, array $formats ): int {
		$this->deleted = array( $table, $where, $formats );

		return 1;
	}
}

$wpdb = new Papelito_Customer_Profile_Delete_Test_WPDB();

require __DIR__ . '/../includes/customer_identity.php';

$result = papelito_customer_profile_delete( 2175 );

if ( true !== $result ) {
	echo "FAIL: customer profile deletion must succeed\n";
	exit( 1 );
}

if ( array( 'wp_papelito_customer_profiles', array( 'user_id' => 2175 ), array( '%d' ) ) !== $wpdb->deleted ) {
	echo "FAIL: customer profile deletion must target the user profile\n";
	exit( 1 );
}

echo "PASS: customer profile deletion targets the user profile\n";
