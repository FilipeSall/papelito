<?php
/**
 * Standalone regression test for the Fase 1B authorization matrix and last-owner protection.
 *
 * Exercises papelito_company_authz_guard_member_action() with the repository lookups stubbed,
 * so the full RBAC matrix and invariant guards are validated without a database.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;
	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }

/*
 * Test-controlled world: memberships keyed by "company:user" and active-owner counts by company.
 */
$papelito_world = array(
	'members' => array(),
	'owners'  => array(),
);

function papelito_company_get( int $company_id ): ?array {
	return array( 'id' => $company_id, 'owner_user_id' => 1 );
}
function papelito_company_member_get( int $company_id, int $user_id ): ?array {
	global $papelito_world;
	return $papelito_world['members'][ "{$company_id}:{$user_id}" ] ?? null;
}
function papelito_company_count_active_owners( int $company_id ): int {
	global $papelito_world;
	return (int) ( $papelito_world['owners'][ $company_id ] ?? 0 );
}

require __DIR__ . '/../includes/company_authz.php';

$failures = 0;
function papelito_assert_true( string $label, mixed $actual ): void {
	global $failures;
	if ( (bool) $actual ) { echo "  PASS: {$label}\n"; return; }
	++$failures;
	echo "  FAIL: {$label}\n";
}
function papelito_assert_err( string $label, mixed $actual, string $expected_code ): void {
	global $failures;
	if ( is_wp_error( $actual ) && $actual->get_error_code() === $expected_code ) { echo "  PASS: {$label}\n"; return; }
	++$failures;
	$got = is_wp_error( $actual ) ? $actual->get_error_code() : var_export( $actual, true );
	echo "  FAIL: {$label} (expected WP_Error {$expected_code}, got {$got})\n";
}

/* Helpers to seed the world. */
function papelito_seed_member( int $company_id, int $user_id, string $role, string $status = 'active' ): void {
	global $papelito_world;
	$papelito_world['members'][ "{$company_id}:{$user_id}" ] = array(
		'company_id'    => $company_id,
		'user_id'       => $user_id,
		'member_role'   => $role,
		'member_status' => $status,
	);
}
function papelito_set_owners( int $company_id, int $count ): void {
	global $papelito_world;
	$papelito_world['owners'][ $company_id ] = $count;
}

$C = 100;

/* ---- owner/admin can manage; buyer/viewer cannot ---- */
papelito_seed_member( $C, 1, 'owner' );
papelito_seed_member( $C, 2, 'admin' );
papelito_seed_member( $C, 3, 'buyer' );
papelito_seed_member( $C, 4, 'viewer' );
papelito_set_owners( $C, 1 );

papelito_assert_true( 'owner can manage', papelito_company_authz_can_manage( papelito_company_member_get( $C, 1 ) ) );
papelito_assert_true( 'admin can manage', papelito_company_authz_can_manage( papelito_company_member_get( $C, 2 ) ) );
papelito_assert_true( 'buyer cannot manage', ! papelito_company_authz_can_manage( papelito_company_member_get( $C, 3 ) ) );
papelito_assert_true( 'viewer cannot manage', ! papelito_company_authz_can_manage( papelito_company_member_get( $C, 4 ) ) );

/* buyer acting → forbidden */
papelito_assert_err( 'buyer cannot change roles', papelito_company_authz_guard_member_action( 3, $C, 4, 'change_role', 'admin' ), 'papelito_b2b_forbidden' );

/* ---- admin cannot touch owner ---- */
papelito_set_owners( $C, 1 );
papelito_assert_err( 'admin cannot suspend owner', papelito_company_authz_guard_member_action( 2, $C, 1, 'suspend' ), 'papelito_b2b_admin_cannot_touch_owner' );
papelito_assert_err( 'admin cannot revoke owner', papelito_company_authz_guard_member_action( 2, $C, 1, 'revoke' ), 'papelito_b2b_admin_cannot_touch_owner' );

/* ---- nobody promotes to owner via change_role (only transfer) ---- */
papelito_assert_err( 'owner cannot promote buyer to owner via role', papelito_company_authz_guard_member_action( 1, $C, 3, 'change_role', 'owner' ), 'papelito_b2b_owner_via_transfer_only' );

/* ---- invalid role rejected ---- */
papelito_assert_err( 'invalid role rejected', papelito_company_authz_guard_member_action( 1, $C, 3, 'change_role', 'superuser' ), 'papelito_b2b_invalid_role' );

/* ---- last owner protection ---- */
papelito_set_owners( $C, 1 );
papelito_assert_err( 'cannot demote last owner', papelito_company_authz_guard_member_action( 1, $C, 1, 'change_role', 'admin' ), 'papelito_b2b_last_owner_protected' );
papelito_assert_err( 'cannot revoke last owner', papelito_company_authz_guard_member_action( 1, $C, 1, 'revoke' ), 'papelito_b2b_last_owner_protected' );
papelito_assert_err( 'cannot suspend last owner', papelito_company_authz_guard_member_action( 1, $C, 1, 'suspend' ), 'papelito_b2b_last_owner_protected' );

/* with a second owner present, demoting one is allowed */
papelito_seed_member( $C, 5, 'owner' );
papelito_set_owners( $C, 2 );
papelito_assert_true( 'demote owner allowed when another owner exists', true === papelito_company_authz_guard_member_action( 1, $C, 5, 'change_role', 'admin' ) );

/* owner can manage a normal member */
papelito_set_owners( $C, 1 );
papelito_assert_true( 'owner can change buyer role', true === papelito_company_authz_guard_member_action( 1, $C, 3, 'change_role', 'viewer' ) );
papelito_assert_true( 'owner can suspend buyer', true === papelito_company_authz_guard_member_action( 1, $C, 3, 'suspend' ) );

/* ---- inactive/absent actor membership → forbidden (anti-enumeration) ---- */
papelito_assert_err( 'non-member actor forbidden', papelito_company_authz_guard_member_action( 999, $C, 3, 'suspend' ), 'papelito_b2b_forbidden' );

/* suspended actor cannot act */
papelito_seed_member( $C, 6, 'admin', 'suspended' );
papelito_assert_err( 'suspended admin forbidden', papelito_company_authz_guard_member_action( 6, $C, 3, 'suspend' ), 'papelito_b2b_forbidden' );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
