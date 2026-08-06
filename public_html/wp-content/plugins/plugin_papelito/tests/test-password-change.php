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

function add_action( mixed ...$args ): void { $GLOBALS['pap_hooks'][] = $args; }
function add_filter( mixed ...$args ): void { $GLOBALS['pap_hooks'][] = $args; }
function register_rest_route( mixed ...$args ): void { $GLOBALS['pap_routes'][] = $args; }
function sanitize_key( mixed $value ): string { return strtolower( (string) $value ); }
function sanitize_text_field( mixed $value ): string { return (string) $value; }
function sanitize_email( mixed $value ): string { return (string) $value; }
function is_email( mixed $value ): bool { return false; }
function wp_unslash( mixed $value ): mixed { return $value; }
function get_current_user_id(): int { return $GLOBALS['pap_current_user_id']; }
function wp_get_current_user(): object|false { return get_userdata( get_current_user_id() ); }
function get_userdata( int $user_id ): object|false { return $GLOBALS['pap_users'][ $user_id ] ?? false; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['pap_meta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( int $user_id, string $key, mixed $value ): bool { $GLOBALS['pap_meta'][ $user_id ][ $key ] = $value; return true; }
function delete_user_meta( int $user_id, string $key ): bool { unset( $GLOBALS['pap_meta'][ $user_id ][ $key ] ); return true; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_check_password( string $password, string $hash, int|string $user_id = '' ): bool { return 'senha-atual' === $password && 'hash-antigo' === $hash; }
function wp_set_password( string $password, int $user_id ): void { $GLOBALS['pap_password_updates'][] = array( $password, $user_id ); $GLOBALS['pap_users'][ $user_id ]->user_pass = 'hash-novo'; }
function wp_generate_uuid4(): string { return 'session-version-2'; }
function papelito_rate_limit( string $bucket, string $identity, int $max, int $window ): bool { return true; }
function papelito_rate_limit_identity( string $fallback_scope = '' ): string { return 'user:' . get_current_user_id(); }
function current_user_can( string $capability ): bool { return true; }
function is_user_logged_in(): bool { return true; }
function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string { return 'generated-password'; }
function wp_remote_get( mixed ...$args ): WP_Error { return new WP_Error( 'unused' ); }
function wp_remote_retrieve_response_code( mixed ...$args ): int { return 500; }
function wp_remote_retrieve_body( mixed ...$args ): string { return ''; }
function email_exists( mixed ...$args ): false { return false; }
function username_exists( mixed ...$args ): false { return false; }
function wp_insert_user( mixed ...$args ): WP_Error { return new WP_Error( 'unused' ); }
function wp_update_user( mixed ...$args ): int { return 0; }
function wp_hash_password( string $password ): string { return 'hash'; }
function check_password_reset_key( mixed ...$args ): WP_Error { return new WP_Error( 'unused' ); }
function reset_password( mixed ...$args ): void { $GLOBALS['pap_password_resets'][] = $args; }
function get_transient( mixed ...$args ): false { return false; }
function set_transient( mixed ...$args ): true { return true; }
class PapelitoTestUser {
	private array $attributes;
	public function __construct( int $id ) {
		$this->attributes = array(
			'ID'           => $id,
			'user_email'   => 'admin@papelito.test',
			'display_name' => 'Admin',
			'first_name'   => 'Admin',
			'last_name'    => 'Papelito',
			'user_pass'    => 'hash-antigo',
			'roles'        => array( 'administrator' ),
		);
	}
	public function __get( string $name ): mixed { return $this->attributes[ $name ] ?? null; }
	public function __set( string $name, mixed $value ): void { $this->attributes[ $name ] = $value; }
}
class PapelitoTestError {
	public string $code;
	public string $message;
	public mixed $data;
	public function __construct( string $code = '', string $message = '', mixed $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
class PapelitoTestRestRequest {
	public function get_json_params(): array { return array(); }
	public function get_params(): array { return array(); }
}
class PapelitoTestRestResponse {
	public mixed $data;
	public int $status;
	public function __construct( mixed $data = null, int $status = 200 ) { $this->data = $data; $this->status = $status; }
}
class PapelitoTestSessionTokens {
	public static function get_instance( int $user_id ): self { return new self(); }
	public function destroy_all(): void { $GLOBALS['pap_destroy_all_called']++; }
}

class_alias( PapelitoTestUser::class, 'WP_User' );
class_alias( PapelitoTestError::class, 'WP_Error' );
class_alias( PapelitoTestRestRequest::class, 'WP_REST_Request' );
class_alias( PapelitoTestRestResponse::class, 'WP_REST_Response' );
class_alias( PapelitoTestSessionTokens::class, 'WP_Session_Tokens' );

require_once __DIR__ . '/../includes/auth_endpoints.php';

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

foreach ( $GLOBALS['pap_hooks'] as $hook ) {
	if ( 'rest_api_init' === $hook[0] ) {
		$hook[1]();
	}
}

$route_callbacks = array();
foreach ( $GLOBALS['pap_routes'] as $route ) {
	$route_callbacks[ $route[1] ] = $route[2]['callback'];
}

papelito_assert( 'auth routes use named callbacks', 'papelito_auth_handle_change_password', $route_callbacks['/auth/change-password'] ?? null );
papelito_assert( 'auth route namespace is centralized', 10, count( $route_callbacks ) );

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
