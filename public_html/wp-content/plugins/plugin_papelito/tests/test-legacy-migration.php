<?php
/**
 * Standalone regression tests for Step 4 legacy migration context.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_B2B_REQUIRED_META', 'papelito_b2b_required' );

class WP_Error {
	private string $code;
	public function __construct( string $code = '', string $message = '', mixed $data = null ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
}
class WP_User {
	public int $ID;
	public string $user_email;
	public string $user_registered;
	public array $roles;
	public function __construct( int $id, array $roles = array( 'customer' ) ) {
		$this->ID = $id;
		$this->roles = $roles;
		$this->user_email = "user{$id}@papelito.test";
		$this->user_registered = '2026-01-01 00:00:00';
	}
}
class WP_REST_Response {}
class WP_REST_Request {}

$papelito_meta = array();
$papelito_flags = array(
	'PAPELITO_B2B_LEGACY_WARNING_ENABLED' => true,
	'PAPELITO_B2B_PURCHASE_ENFORCED'      => false,
);

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( string $hook, callable|string $callback, int $priority = 10, int $args = 1 ): void {}
function register_rest_route(): void {}
function wp_next_scheduled(): bool { return true; }
function wp_schedule_event(): void {}
function current_time( string $type, bool $gmt = false ): string { return '2026-07-24 00:00:00'; }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function wp_salt( string $scheme = '' ): string { return 'test-salt-' . $scheme; }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function papelito_env( string $name, mixed $default = '' ): mixed { return 'PAPELITO_B2B_LEGACY_GRACE_END_AT' === $name ? '2026-08-31 23:59:59' : $default; }
function papelito_b2b_flag( string $name ): bool { global $papelito_flags; return (bool) ( $papelito_flags[ $name ] ?? false ); }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { global $papelito_meta; return $papelito_meta[ "{$user_id}:{$key}" ] ?? ''; }
function update_user_meta( int $user_id, string $key, mixed $value ): bool { global $papelito_meta; $papelito_meta[ "{$user_id}:{$key}" ] = $value; return true; }
function delete_user_meta( int $user_id, string $key ): bool { global $papelito_meta; unset( $papelito_meta[ "{$user_id}:{$key}" ] ); return true; }
function papelito_b2b_is_cohort( int $user_id ): bool { return '1' === (string) get_user_meta( $user_id, PAPELITO_B2B_REQUIRED_META, true ); }
function papelito_user_has_role( WP_User $user, string $role ): bool { return in_array( $role, $user->roles, true ); }
function papelito_user_is_effective_seller( WP_User|int $user ): bool { return $user instanceof WP_User && in_array( 'seller', $user->roles, true ); }
function papelito_company_members_active_for_user( int $user_id ): array { return array(); }
function papelito_company_members_pending_for_user( int $user_id ): array { return array(); }
function papelito_validate_cpf( string $cpf ): bool { return 11 === strlen( preg_replace( '/\D+/', '', $cpf ) ?? '' ); }
function papelito_validate_cnpj( string $cnpj ): bool { return 14 === strlen( preg_replace( '/[^A-Z0-9]/i', '', $cnpj ) ?? '' ); }
function papelito_normalize_cnpj( string $cnpj ): string { return strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $cnpj ) ?? '' ); }
function get_userdata( int $user_id ): WP_User { return new WP_User( $user_id ); }
function wc_get_orders(): array { return array(); }
function get_users(): array { return array(); }

require __DIR__ . '/../includes/legacy_migration.php';

$failures = 0;
function legacy_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} expected " . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) . "\n";
}

legacy_assert_same( 'non cohort defaults to legacy mode', 'legacy', papelito_legacy_context( 10 )['purchaseMode'] );
papelito_legacy_mark_user( 10 );
$context = papelito_legacy_context( 10 );
legacy_assert_same( 'legacy cohort is stable', true, $context['isLegacyCohort'] );
legacy_assert_same( 'legacy user can purchase during grace', true, $context['legacyCanPurchaseDuringGrace'] );
legacy_assert_same( 'marking legacy does not mark b2b required', false, papelito_b2b_is_cohort( 10 ) );
update_user_meta( 10, PAPELITO_B2B_REQUIRED_META, '1' );
legacy_assert_same( 'b2b required switches purchase mode', 'b2b', papelito_legacy_context( 10 )['purchaseMode'] );

$customer = new WP_User( 20, array( 'customer' ) );
$seller = new WP_User( 21, array( 'customer', 'seller' ) );
$admin = new WP_User( 22, array( 'administrator', 'customer' ) );
legacy_assert_same( 'customer before cutoff is candidate', true, papelito_legacy_user_is_candidate( $customer, '2026-02-01 00:00:00' ) );
legacy_assert_same( 'seller is excluded', false, papelito_legacy_user_is_candidate( $seller, '2026-02-01 00:00:00' ) );
legacy_assert_same( 'admin is excluded', false, papelito_legacy_user_is_candidate( $admin, '2026-02-01 00:00:00' ) );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
