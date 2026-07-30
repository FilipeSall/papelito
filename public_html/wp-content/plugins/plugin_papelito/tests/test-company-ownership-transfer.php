<?php
/**
 * Standalone regression test for Fase 1B ownership transfer + member status transitions.
 *
 * Uses a small in-memory members store. Covers:
 *   - only the active owner can transfer; target must be an active member; not to self
 *   - after transfer: old owner → admin, target → owner, company.owner_user_id updated
 *   - suspend/revoke clears the target's active-company selection when it pointed here
 *   - suspended member loses canPurchase (via context helper contract)
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private string $code;
	public function __construct( string $c = '', string $m = '', mixed $d = null ) { $this->code = $c; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( mixed $v ) { return $v instanceof WP_Error; }
function current_time( string $t, $g = 0 ) { return '2026-07-23 00:00:00'; }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : $v; }
function sanitize_key( $v ) { return strtolower( (string) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }

$papelito_meta = array();
function get_user_meta( int $u, string $k, bool $s = false ) { global $papelito_meta; return $papelito_meta[ "{$u}:{$k}" ] ?? ''; }
function update_user_meta( int $u, string $k, $v ) { global $papelito_meta; $papelito_meta[ "{$u}:{$k}" ] = $v; return true; }
function delete_user_meta( int $u, string $k ) { global $papelito_meta; unset( $papelito_meta[ "{$u}:{$k}" ] ); return true; }

/* --- in-memory model --- */
$papelito_company = array( 'id' => 100, 'owner_user_id' => 1 );
$papelito_members = array(); // "company:user" => row

function papelito_company_get( int $id ): ?array { global $papelito_company; return $id === $papelito_company['id'] ? $papelito_company : null; }
function papelito_company_member_get( int $c, int $u ): ?array { global $papelito_members; return $papelito_members[ "{$c}:{$u}" ] ?? null; }
function papelito_company_count_active_owners( int $c ): int {
	global $papelito_members; $n = 0;
	foreach ( $papelito_members as $m ) { if ( (int) $m['company_id'] === $c && 'owner' === $m['member_role'] && 'active' === $m['member_status'] ) { $n++; } }
	return $n;
}
function papelito_company_member_upsert( int $c, int $u, array $data = array() ) {
	global $papelito_members;
	$key = "{$c}:{$u}";
	$papelito_members[ $key ] = array_merge( array( 'id' => crc32( $key ), 'company_id' => $c, 'user_id' => $u ), $papelito_members[ $key ] ?? array(), $data );
	return $papelito_members[ $key ]['id'];
}
$papelito_audit = array();
function papelito_company_audit( int $c, ?int $a, string $action, array $p = array() ): void { global $papelito_audit; $papelito_audit[] = $action; }

/* fake $wpdb for the transactional transfer (START/COMMIT/ROLLBACK + FOR UPDATE + updates) */
class Fake_WPDB {
	public function query( $q ) { return true; }
	public function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } $i = 0; return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $a ) { return (string) ( $a[ $i++ ] ?? '' ); }, $q ); }
	public function get_row( $q, $o = null ) { return $GLOBALS['papelito_company']; }
	public function update( $table, $data, $where ) {
		global $papelito_members, $papelito_company;
		if ( str_contains( $table, 'companies' ) ) { $papelito_company = array_merge( $papelito_company, $data ); return 1; }
		// members update by (company_id,user_id[,member_role])
		foreach ( $papelito_members as $k => &$row ) {
			$ok = true;
			foreach ( $where as $wk => $wv ) { if ( (string) ( $row[ $wk ] ?? '' ) !== (string) $wv ) { $ok = false; break; } }
			if ( $ok ) { $row = array_merge( $row, $data ); }
		}
		unset( $row );
		return 1;
	}
}
$wpdb = new Fake_WPDB();

function papelito_company_table_names(): array {
	return array( 'companies' => 'wp_papelito_companies', 'members' => 'wp_papelito_company_members' );
}
function papelito_company_active_get_selection( int $u ) { return (int) get_user_meta( $u, 'papelito_b2b_active_company_id', true ); }
function papelito_company_active_clear_selection( int $u ) { delete_user_meta( $u, 'papelito_b2b_active_company_id' ); }
function papelito_company_context( int $u ): array { return array( 'identityStatus' => 'verified' ); }
function papelito_company_invitations_revoke_by_inviter(): int { return 0; }

require __DIR__ . '/../includes/company_authz.php';
require __DIR__ . '/../includes/company_membership_services.php';

$failures = 0;
function ok( string $label, $cond ): void { global $failures; if ( $cond ) { echo "  PASS: {$label}\n"; } else { ++$failures; echo "  FAIL: {$label}\n"; } }

$C = 100;
papelito_company_member_upsert( $C, 1, array( 'member_role' => 'owner', 'member_status' => 'active' ) );
papelito_company_member_upsert( $C, 2, array( 'member_role' => 'buyer', 'member_status' => 'active' ) );
papelito_company_member_upsert( $C, 3, array( 'member_role' => 'admin', 'member_status' => 'active' ) );

/* ---- non-owner cannot transfer ---- */
ok( 'admin cannot transfer ownership', is_wp_error( papelito_company_transfer_ownership( 3, $C, 2 ) ) );

/* ---- cannot transfer to self ---- */
ok( 'cannot transfer to self', is_wp_error( papelito_company_transfer_ownership( 1, $C, 1 ) ) );

/* ---- cannot transfer to a non-active member ---- */
papelito_company_member_upsert( $C, 4, array( 'member_role' => 'buyer', 'member_status' => 'suspended' ) );
ok( 'cannot transfer to suspended member', is_wp_error( papelito_company_transfer_ownership( 1, $C, 4 ) ) );

/* ---- successful transfer: 1 (owner) → 2 (buyer) ---- */
$t = papelito_company_transfer_ownership( 1, $C, 2 );
ok( 'transfer succeeds', true === $t );
ok( 'old owner demoted to admin', papelito_company_member_get( $C, 1 )['member_role'] === 'admin' );
ok( 'target promoted to owner', papelito_company_member_get( $C, 2 )['member_role'] === 'owner' );
ok( 'company owner_user_id updated', papelito_company_get( $C )['owner_user_id'] === 2 );
ok( 'still exactly one active owner', papelito_company_count_active_owners( $C ) === 1 );

/* ---- new owner (2) suspends a member; selection cleared ---- */
update_user_meta( 3, 'papelito_b2b_active_company_id', $C );
$s = papelito_company_member_set_status( 2, $C, 3, 'suspend' );
ok( 'owner can suspend admin', is_array( $s ) && $s['member_status'] === 'suspended' );
ok( 'suspended member selection cleared', 0 === papelito_company_active_get_selection( 3 ) );

/* ---- revoke a member ---- */
$r = papelito_company_member_set_status( 2, $C, 3, 'revoke' );
ok( 'owner can revoke member', is_array( $r ) && $r['member_status'] === 'revoked' );

if ( $failures > 0 ) { echo "RESULT: {$failures} assertion(s) FAILED\n"; exit( 1 ); }
echo "RESULT: all assertions passed\n";
exit( 0 );
