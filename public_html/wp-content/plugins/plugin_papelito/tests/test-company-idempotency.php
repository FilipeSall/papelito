<?php
/**
 * Standalone regression test for the shared durable idempotency layer (Fase 1B).
 *
 * Covers: first-call stores; identical replay returns the stored result without re-executing;
 * same key + different request → mismatch (409); missing key → 400; concurrent inserts collapse
 * to one via the UNIQUE key (INSERT IGNORE).
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private string $code;
	private mixed $data;
	public function __construct( string $c = '', string $m = '', mixed $d = null ) { $this->code = $c; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( mixed $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( string $t, $g = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }

/* Idempotency uses papelito_company_table_names(); provide a minimal stub. */
function papelito_company_table_names(): array {
	return array( 'idempotency' => 'wp_papelito_company_idempotency' );
}

/* Minimal $wpdb honoring the UNIQUE(actor,operation,key_hash) via INSERT IGNORE. */
class Fake_WPDB {
	public array $rows = array();
	public function prepare( $q, ...$a ) {
		if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $a ) {
			$v = $a[ $i++ ] ?? '';
			return ( '%d' === $m[0] ) ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $q );
	}
	public function get_row( $q, $o = null ) {
		// SELECT ... WHERE actor_user_id = N AND operation = 'op' AND key_hash = 'h'
		preg_match( "/actor_user_id = (\d+) AND operation = '([^']*)' AND key_hash = '([^']*)'/", $q, $m );
		$k = "{$m[1]}:{$m[2]}:{$m[3]}";
		return $this->rows[ $k ] ?? null;
	}
	public function query( $q ) {
		// INSERT IGNORE ... VALUES ( N, 'op', 'kh', 'rh', R, C, '..', '..' )
		if ( preg_match( "/VALUES\s*\(\s*(\d+),\s*'([^']*)',\s*'([^']*)',\s*'([^']*)',\s*(\d+),\s*(\d+),/", $q, $m ) ) {
			$k = "{$m[1]}:{$m[2]}:{$m[3]}";
			if ( isset( $this->rows[ $k ] ) ) { return 0; } // INSERT IGNORE: UNIQUE collision
			$this->rows[ $k ] = array( 'request_hash' => $m[4], 'resource_id' => (int) $m[5], 'response_code' => (int) $m[6] );
			return 1;
		}
		return 0;
	}
}
$wpdb = new Fake_WPDB();

require __DIR__ . '/../includes/company_idempotency.php';

$failures = 0;
function ok( string $label, $cond ): void { global $failures; if ( $cond ) { echo "  PASS: {$label}\n"; } else { ++$failures; echo "  FAIL: {$label}\n"; } }

$actor = 5;
$op    = 'member_patch';
$key   = 'idem-key-abc';
$hash  = papelito_company_idempotency_request_hash( array( 'member' => 12, 'role' => 'admin' ) );

/* ---- missing key → 400 ---- */
$miss = papelito_company_idempotency_check( $actor, $op, '', $hash );
ok( 'missing key → error', isset( $miss['error'] ) && $miss['error']->get_error_code() === 'papelito_b2b_idempotency_required' );

/* ---- first call: no prior record ---- */
ok( 'first check → null (proceed)', null === papelito_company_idempotency_check( $actor, $op, $key, $hash ) );

/* ---- store, then replay returns stored result ---- */
papelito_company_idempotency_store( $actor, $op, $key, $hash, 99, 200 );
$replay = papelito_company_idempotency_check( $actor, $op, $key, $hash );
ok( 'replay returns stored resource', is_array( $replay ) && ( $replay['resource_id'] ?? 0 ) === 99 && ( $replay['response_code'] ?? 0 ) === 200 );

/* ---- same key, different request → mismatch 409 ---- */
$other_hash = papelito_company_idempotency_request_hash( array( 'member' => 12, 'role' => 'viewer' ) );
$mismatch   = papelito_company_idempotency_check( $actor, $op, $key, $other_hash );
ok( 'same key + different request → mismatch', isset( $mismatch['error'] ) && $mismatch['error']->get_error_code() === 'papelito_b2b_idempotency_mismatch' );

/* ---- second store with same key is ignored (idempotent, no duplicate) ---- */
papelito_company_idempotency_store( $actor, $op, $key, $hash, 12345, 200 );
$still = papelito_company_idempotency_check( $actor, $op, $key, $hash );
ok( 'concurrent/second store ignored (resource unchanged)', is_array( $still ) && ( $still['resource_id'] ?? 0 ) === 99 );

/* ---- request hash is order-independent ---- */
$h1 = papelito_company_idempotency_request_hash( array( 'a' => 1, 'b' => 2 ) );
$h2 = papelito_company_idempotency_request_hash( array( 'b' => 2, 'a' => 1 ) );
ok( 'request hash order-independent', $h1 === $h2 );

if ( $failures > 0 ) { echo "RESULT: {$failures} assertion(s) FAILED\n"; exit( 1 ); }
echo "RESULT: all assertions passed\n";
exit( 0 );
