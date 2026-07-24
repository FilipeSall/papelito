<?php
/**
 * Standalone regression test for the Fase 1B active-company state machine.
 *
 * Validates papelito_company_active_resolve() / _select() with usermeta stubbed:
 *   - zero active memberships → none (+ selection cleared)
 *   - exactly one            → auto-selected + persisted
 *   - more than one, none set → company_selection_required
 *   - more than one, valid    → selected
 *   - selected but no active membership → selection cleared + requires selection again
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	private string $code;
	public function __construct( string $code = '', string $m = '', mixed $d = null ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( mixed $v ) { return $v instanceof WP_Error; }

$papelito_meta = array();
function get_user_meta( int $user_id, string $key, bool $single = false ) {
	global $papelito_meta;
	return $papelito_meta[ "{$user_id}:{$key}" ] ?? '';
}
function update_user_meta( int $user_id, string $key, $value ) {
	global $papelito_meta;
	$papelito_meta[ "{$user_id}:{$key}" ] = $value;
	return true;
}
function delete_user_meta( int $user_id, string $key ) {
	global $papelito_meta;
	unset( $papelito_meta[ "{$user_id}:{$key}" ] );
	return true;
}
function current_time( string $type, $gmt = 0 ) { return '2026-07-23 00:00:00'; }

$papelito_audit_calls = array();
function papelito_company_audit( int $company_id, ?int $actor, string $action, array $payload = array() ): void {
	global $papelito_audit_calls;
	$papelito_audit_calls[] = array( $company_id, $action );
}

/* member lookups for _select() */
$papelito_members = array();
function papelito_company_member_get( int $company_id, int $user_id ): ?array {
	global $papelito_members;
	return $papelito_members[ "{$company_id}:{$user_id}" ] ?? null;
}

require __DIR__ . '/../includes/company_active_context.php';

$failures = 0;
function papelito_assert_same( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "  PASS: {$label}\n"; return; }
	++$failures;
	echo "  FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}
function papelito_assert_true( string $label, $actual ): void { papelito_assert_same( $label, true, (bool) $actual ); }

$U = 7;
function m( int $company_id ): array { return array( 'company_id' => $company_id, 'user_id' => 7, 'member_status' => 'active' ); }

/* ---- zero active ---- */
$r = papelito_company_active_resolve( $U, array() );
papelito_assert_same( 'zero → none', 'none', $r['status'] );
papelito_assert_same( 'zero → no selection persisted', 0, papelito_company_active_get_selection( $U ) );

/* ---- exactly one → auto-select + persist ---- */
$r = papelito_company_active_resolve( $U, array( m( 11 ) ) );
papelito_assert_same( 'one → selected', 'selected', $r['status'] );
papelito_assert_same( 'one → member returned', 11, (int) $r['member']['company_id'] );
papelito_assert_same( 'one → selection persisted', 11, papelito_company_active_get_selection( $U ) );

/* ---- more than one, none chosen (persisted 11 is stale, not in the set) → required ---- */
$r = papelito_company_active_resolve( $U, array( m( 21 ), m( 22 ) ) );
papelito_assert_same( 'multi, stale selection → required', 'company_selection_required', $r['status'] );
papelito_assert_same( 'multi → stale selection cleared', 0, papelito_company_active_get_selection( $U ) );

/* ---- more than one, valid selection → selected ---- */
papelito_company_active_set_selection( $U, 22 );
$r = papelito_company_active_resolve( $U, array( m( 21 ), m( 22 ) ) );
papelito_assert_same( 'multi, valid selection → selected', 'selected', $r['status'] );
papelito_assert_same( 'multi → correct member', 22, (int) $r['member']['company_id'] );

/* ---- selected company no longer has active membership → cleared + required ---- */
papelito_company_active_set_selection( $U, 99 );
$r = papelito_company_active_resolve( $U, array( m( 21 ), m( 22 ) ) );
papelito_assert_same( 'selected-without-active-membership → required', 'company_selection_required', $r['status'] );
papelito_assert_same( 'selected-without-active-membership → cleared', 0, papelito_company_active_get_selection( $U ) );

/* ---- explicit select validates active membership ---- */
$papelito_members[ '30:7' ] = array( 'company_id' => 30, 'user_id' => 7, 'member_status' => 'active' );
papelito_assert_true( 'select active membership ok', true === papelito_company_active_select( $U, 30 ) );
papelito_assert_same( 'select persists', 30, papelito_company_active_get_selection( $U ) );

$papelito_members[ '31:7' ] = array( 'company_id' => 31, 'user_id' => 7, 'member_status' => 'suspended' );
papelito_assert_true( 'select suspended membership rejected', is_wp_error( papelito_company_active_select( $U, 31 ) ) );
papelito_assert_true( 'select unknown membership rejected', is_wp_error( papelito_company_active_select( $U, 32 ) ) );

/* ---- cohort sticky ---- */
$U2 = 8;
papelito_assert_true( 'not cohort initially', ! papelito_b2b_is_cohort( $U2 ) );
papelito_b2b_mark_cohort( $U2 );
papelito_assert_true( 'cohort after mark', papelito_b2b_is_cohort( $U2 ) );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
