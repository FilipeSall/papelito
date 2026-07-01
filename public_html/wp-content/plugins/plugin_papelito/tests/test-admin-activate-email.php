<?php
/**
 * Standalone regression test for admin manual email activation.
 *
 * Usage: php tests/test-admin-activate-email.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pap_meta']        = array();
$GLOBALS['pap_users']       = array();
$GLOBALS['pap_current_uid'] = 999;
$GLOBALS['pap_can']         = true;
$GLOBALS['pap_log_calls']   = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function absint( $v ) { return (int) abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function get_current_user_id() { return $GLOBALS['pap_current_uid']; }
function current_user_can( $cap ) { return (bool) $GLOBALS['pap_can']; }
function get_userdata( $id ) { return $GLOBALS['pap_users'][ $id ] ?? false; }
function user_can( $user, $cap ) { return false; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['pap_meta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $val ) { $GLOBALS['pap_meta'][ $id ][ $key ] = $val; return true; }
function delete_user_meta( $id, $key ) { unset( $GLOBALS['pap_meta'][ $id ][ $key ] ); return true; }
function my_plugin_log_json( $data ) { $GLOBALS['pap_log_calls'][] = $data; }

class WP_User {
	public $ID; public $user_email; public $display_name = 'User'; public $user_registered = '2026-01-01 00:00:00'; public $roles = array( 'customer' );
	function __construct( $id, $email = 'u@x.com' ) { $this->ID = $id; $this->user_email = $email; }
}
class WP_Error {
	public $code; public $message; public $data;
	function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_REST_Request {}
class WP_REST_Response { public $data; public $status; function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } }

function papelito_auth_requires_email_verification( $id ) {
	$status = (string) get_user_meta( $id, 'papelito_email_verification_status', true );
	if ( '' === $status ) { return false; }
	return 'verified' !== $status;
}
function papelito_auth_current_utc_mysql() { return '2026-07-01 12:00:00'; }
function papelito_auth_mark_email_verified( $id ) {
	update_user_meta( $id, 'papelito_email_verification_status', 'verified' );
	update_user_meta( $id, 'papelito_email_verified_at', papelito_auth_current_utc_mysql() );
	delete_user_meta( $id, 'papelito_email_verification_token_hash' );
	delete_user_meta( $id, 'papelito_email_verification_token_expires_at' );
}
require __DIR__ . '/../includes/admin_users.php';

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

$GLOBALS['pap_users'][10] = new WP_User( 10 );
$GLOBALS['pap_meta'][10]  = array(
	'papelito_email_verification_status'           => 'pending',
	'papelito_email_verification_token_hash'       => 'abc',
	'papelito_email_verification_token_expires_at' => '2026-07-02T00:00:00+00:00',
);
$GLOBALS['pap_log_calls'] = array();
$res = papelito_admin_users_activate_email( 10 );
papelito_assert( 'case1: not WP_Error', false, is_wp_error( $res ) );
papelito_assert( 'case1: status verified', 'verified', $GLOBALS['pap_meta'][10]['papelito_email_verification_status'] );
papelito_assert( 'case1: method admin', 'admin', $GLOBALS['pap_meta'][10]['papelito_email_verification_method'] );
papelito_assert( 'case1: verified_by admin uid', 999, $GLOBALS['pap_meta'][10]['papelito_email_verified_by'] );
papelito_assert( 'case1: token hash cleared', false, isset( $GLOBALS['pap_meta'][10]['papelito_email_verification_token_hash'] ) );
papelito_assert( 'case1: one audit log line', 1, count( $GLOBALS['pap_log_calls'] ) );
papelito_assert( 'case1: log action', 'admin_email_verified', $GLOBALS['pap_log_calls'][0]['action'] );
papelito_assert( 'case1: detail returns verified', 'verified', $res['emailVerificationStatus'] );

$GLOBALS['pap_users'][11] = new WP_User( 11 );
$GLOBALS['pap_meta'][11]  = array( 'papelito_email_verification_status' => 'verified' );
$GLOBALS['pap_log_calls'] = array();
$res = papelito_admin_users_activate_email( 11 );
papelito_assert( 'case2a: WP_Error', true, is_wp_error( $res ) );
papelito_assert( 'case2a: code not_pending', 'papelito_email_not_pending', $res->code );
papelito_assert( 'case2a: 409', 409, $res->data['status'] );
papelito_assert( 'case2a: no method meta written', false, isset( $GLOBALS['pap_meta'][11]['papelito_email_verification_method'] ) );
papelito_assert( 'case2a: no log', 0, count( $GLOBALS['pap_log_calls'] ) );

$GLOBALS['pap_users'][12] = new WP_User( 12 );
$GLOBALS['pap_meta'][12]  = array();
$res = papelito_admin_users_activate_email( 12 );
papelito_assert( 'case2b: WP_Error', true, is_wp_error( $res ) );
papelito_assert( 'case2b: code not_pending', 'papelito_email_not_pending', $res->code );

$res = papelito_admin_users_activate_email( 777 );
papelito_assert( 'case3: WP_Error', true, is_wp_error( $res ) );
papelito_assert( 'case3: 404', 404, $res->data['status'] );

$GLOBALS['pap_can'] = false;
papelito_assert( 'case4: non-admin rejected', false, papelito_admin_users_require_admin() );
$GLOBALS['pap_can'] = true;
papelito_assert( 'case4: admin allowed', true, papelito_admin_users_require_admin() );

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( $failures === 0 ? 0 : 1 );
