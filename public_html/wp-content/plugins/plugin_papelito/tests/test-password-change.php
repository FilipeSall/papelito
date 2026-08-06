<?php
/**
 * Standalone regression test for authenticated password changes.
 *
 * Usage: php tests/test-password-change.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pap_meta']              = array();
$GLOBALS['pap_users']             = array();
$GLOBALS['pap_current_user_id']   = 10;
$GLOBALS['pap_password_updates']  = array();
$GLOBALS['pap_destroy_all_called'] = 0;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function sanitize_key( $value ) { return strtolower( (string) $value ); }
function sanitize_text_field( $value ) { return (string) $value; }
function sanitize_email( $value ) { return (string) $value; }
function is_email( $value ) { return false; }
function wp_unslash( $value ) { return $value; }
function get_current_user_id() { return $GLOBALS['pap_current_user_id']; }
function wp_get_current_user() { return get_userdata( get_current_user_id() ); }
function get_userdata( $user_id ) { return $GLOBALS['pap_users'][ $user_id ] ?? false; }
function get_user_meta( $user_id, $key, $single = false ) { return $GLOBALS['pap_meta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['pap_meta'][ $user_id ][ $key ] = $value; return true; }
function delete_user_meta( $user_id, $key ) { unset( $GLOBALS['pap_meta'][ $user_id ][ $key ] ); return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_check_password( $password, $hash, $user_id = '' ) { return 'senha-atual' === $password && 'hash-antigo' === $hash; }
function wp_set_password( $password, $user_id ) { $GLOBALS['pap_password_updates'][] = array( $password, $user_id ); $GLOBALS['pap_users'][ $user_id ]->user_pass = 'hash-novo'; }
function wp_generate_uuid4() { return 'session-version-2'; }
function papelito_rate_limit( $bucket, $identity, $max, $window ) { return true; }
function papelito_rate_limit_identity( $fallback_scope = '' ) { return 'user:' . get_current_user_id(); }
function current_user_can( $capability ) { return true; }
function is_user_logged_in() { return true; }
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) { return 'generated-password'; }
function wp_remote_get( ...$args ) { return new WP_Error( 'unused' ); }
function wp_remote_retrieve_response_code( ...$args ) { return 500; }
function wp_remote_retrieve_body( ...$args ) { return ''; }
function email_exists( ...$args ) { return false; }
function username_exists( ...$args ) { return false; }
function wp_insert_user( ...$args ) { return new WP_Error( 'unused' ); }
function wp_update_user( ...$args ) { return 0; }
function wp_hash_password( $password ) { return 'hash'; }
function check_password_reset_key( ...$args ) { return new WP_Error( 'unused' ); }
function reset_password( ...$args ) {}
function get_transient( ...$args ) { return false; }
function set_transient( ...$args ) { return true; }
class WP_User {
	public $ID;
	public $user_email = 'admin@papelito.test';
	public $display_name = 'Admin';
	public $first_name = 'Admin';
	public $last_name = 'Papelito';
	public $user_pass = 'hash-antigo';
	public $roles = array( 'administrator' );
	public function __construct( $id ) { $this->ID = $id; }
}
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
class WP_REST_Request { public function get_json_params() { return array(); } public function get_params() { return array(); } }
class WP_REST_Response { public function __construct( $data = null, $status = 200 ) {} }
class WP_Session_Tokens {
	public static function get_instance( $user_id ) { return new self(); }
	public function destroy_all() { $GLOBALS['pap_destroy_all_called']++; }
}

require __DIR__ . '/../includes/auth_endpoints.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	$failures++;
	echo "  FAIL: {$label} — expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

$GLOBALS['pap_users'][10] = new WP_User( 10 );
$wrong = papelito_auth_change_password( $GLOBALS['pap_users'][10], array(
	'currentPassword' => 'errada',
	'password'        => 'nova-senha',
	'confirmPassword' => 'nova-senha',
) );
papelito_assert( 'wrong current password is rejected', true, is_wp_error( $wrong ) );
papelito_assert( 'wrong current password changes nothing', 0, count( $GLOBALS['pap_password_updates'] ) );

$changed = papelito_auth_change_password( $GLOBALS['pap_users'][10], array(
	'currentPassword' => 'senha-atual',
	'password'        => 'nova-senha',
	'confirmPassword' => 'nova-senha',
) );
papelito_assert( 'correct current password changes the credential', true, true === $changed );
papelito_assert( 'password change writes the new password once', 1, count( $GLOBALS['pap_password_updates'] ) );
papelito_assert( 'password change rotates the session version', 'session-version-2', $GLOBALS['pap_meta'][10]['papelito_auth_session_version'] );
papelito_assert( 'password change destroys WordPress sessions', 1, $GLOBALS['pap_destroy_all_called'] );

$old_token = (object) array( 'data' => (object) array( 'user' => (object) array( 'id' => 10 ) ) );
$new_token = (object) array( 'data' => (object) array( 'user' => (object) array( 'id' => 10, 'papelito_session_version' => 'session-version-2' ) ) );
papelito_assert( 'old JWT is rejected after password change', true, is_wp_error( papelito_auth_validate_session_version( $old_token ) ) );
papelito_assert( 'new JWT session version is accepted', $new_token, papelito_auth_validate_session_version( $new_token ) );

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( $failures === 0 ? 0 : 1 );
