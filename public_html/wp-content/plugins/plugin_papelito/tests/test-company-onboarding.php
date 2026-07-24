<?php
/**
 * Persistent onboarding and purchase-gate regression tests.
 *
 * Covers the public state contract used by create_company/join_company/Google onboarding without
 * requiring a WordPress installation: persistence is exercised through a small wpdb double.
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private string $code;
	public function __construct( string $code = '' ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function current_time( string $type, $gmt = 0 ): string { return '2026-07-24 00:00:00'; }
function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function papelito_normalize_cnpj( string $value ): string { return strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $value ) ?? '' ); }
function papelito_validate_cnpj( string $value ): bool { return 14 === strlen( papelito_normalize_cnpj( $value ) ); }
function wp_json_encode( $value ): string { return json_encode( $value ) ?: ''; }

$rows = array();
class FakeOnboardingWpdb {
	public string $prefix = 'wp_';
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$index = 0;
		return preg_replace_callback( '/%[ds]/', static function () use ( &$index, $args ) { return (string) ( $args[ $index++ ] ?? '' ); }, $query ) ?? $query;
	}
	public function get_row( string $query, $format = null ): ?array { global $rows; if ( preg_match( '/user_id = (\d+)/', $query, $m ) && isset( $rows[ (int) $m[1] ] ) ) { return $rows[ (int) $m[1] ]; } return null; }
	public function insert( string $table, array $data ): int { global $rows; $rows[ (int) $data['user_id'] ] = $data; return 1; }
	public function update( string $table, array $data, array $where ): int { global $rows; $id = (int) $where['user_id']; $rows[ $id ] = array_merge( $rows[ $id ] ?? array(), $data ); return 1; }
	public function query( string $query ): int { global $rows; if ( preg_match( '/user_id = (\d+)/', $query, $m ) && isset( $rows[ (int) $m[1] ] ) ) { $rows[ (int) $m[1] ]['status'] = 'pending_onboarding'; } return 1; }
}
$wpdb = new FakeOnboardingWpdb();

function papelito_company_table_names(): array { return array( 'onboarding' => 'wp_papelito_b2b_onboarding' ); }

require __DIR__ . '/../includes/company_onboarding.php';

$failures = 0;
function assert_same( string $label, mixed $expected, mixed $actual ): void { global $failures; if ( $expected === $actual ) { echo "PASS: {$label}\n"; } else { ++$failures; echo "FAIL: {$label}\n"; } }

assert_same( 'create onboarding persists type and target', true, papelito_company_onboarding_upsert( 7, 'create_company', '12.345.678/0001-95' ) === true );
assert_same( 'create onboarding state is pending email', 'pending_email', papelito_company_onboarding_state( 7 ) );
papelito_company_onboarding_mark_email_confirmed( 7 );
assert_same( 'email confirmation advances onboarding', 'pending_onboarding', papelito_company_onboarding_state( 7 ) );
papelito_company_onboarding_mark_completed( 7, 99, 101 );
assert_same( 'completed onboarding is idempotent state', 'completed', papelito_company_onboarding_state( 7 ) );
assert_same( 'missing onboarding is resumable', 'onboarding_required', papelito_company_onboarding_state( 8 ) );
assert_same( 'join onboarding persists target for automatic access request', true, papelito_company_onboarding_upsert( 9, 'join_company', '12.345.678/0001-95' ) === true );
assert_same( 'google onboarding has no duplicated PII target', true, papelito_company_onboarding_upsert( 10, 'google_onboarding' ) === true );

if ( $failures > 0 ) { exit( 1 ); }
echo "RESULT: all assertions passed\n";
