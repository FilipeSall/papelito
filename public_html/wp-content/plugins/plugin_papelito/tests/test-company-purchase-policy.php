<?php

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public function __construct( string $code = '', string $message = '', mixed $data = null ) {}
}

class WP_User {
	public int $ID;
	public array $roles;
	public array $caps;
	public function __construct( int $id, array $roles = array(), array $caps = array() ) {
		$this->ID = $id;
		$this->roles = $roles;
		$this->caps = $caps;
	}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( $value ); }
function get_userdata( int $user_id ): ?WP_User { global $papelito_users; return $papelito_users[ $user_id ] ?? null; }
function user_can( WP_User $user, string $capability ): bool { return ! empty( $user->caps[ $capability ] ); }
function papelito_user_has_role( WP_User $user, string $role ): bool { return in_array( $role, $user->roles, true ); }
function papelito_user_is_effective_seller( WP_User $user ): bool { return in_array( 'seller', $user->roles, true ); }
function papelito_b2b_is_cohort( int $user_id ): bool { global $papelito_cohorts; return ! empty( $papelito_cohorts[ $user_id ] ); }
function papelito_company_members_active_for_user( int $user_id ): array { return array(); }
function papelito_company_table_names(): array { return array( 'members' => 'members' ); }
function papelito_company_purchasing_roles(): array { return array( 'owner', 'admin', 'buyer' ); }
function papelito_company_get( int $company_id ): ?array { global $papelito_company; return $papelito_company; }
function papelito_company_member_get( int $company_id, int $user_id ): ?array { global $papelito_members; return $papelito_members[ $user_id ] ?? null; }
function is_email( string $email ): bool { return false !== strpos( $email, '@' ); }
function current_time( string $type, bool $gmt = false ): string { return '2026-07-24 00:00:00'; }
function get_user_meta() { return ''; }
function update_user_meta() { return true; }
function delete_user_meta() { return true; }
function get_user_by() { return null; }

class Papelito_Test_WPDB {
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_results( string $query, mixed $output = null ): array { return array(); }
}
$wpdb = new Papelito_Test_WPDB();

require __DIR__ . '/../includes/company_services.php';

$papelito_users = array(
	1 => new WP_User( 1, array( 'administrator' ), array( 'manage_options' => true ) ),
	2 => new WP_User( 2, array( 'seller' ) ),
	3 => new WP_User( 3, array( 'customer', 'seller' ) ),
	4 => new WP_User( 4, array( 'customer' ) ),
	5 => new WP_User( 5, array( 'customer' ) ),
);
$papelito_cohorts = array();
$papelito_company = array(
	'id' => 10,
	'company_status' => 'active',
	'registry_status' => 'active',
	'ownership_status' => 'verified',
	'billing_email_verified_at' => '2026-07-24 00:00:00',
	'billing_email' => 'fiscal@empresa.test',
	'legal_name' => 'Empresa Teste',
	'phone' => '11999999999',
	'cnpj' => '12345678000195',
	'fiscal_cep' => '01001000',
	'fiscal_state' => 'SP',
	'fiscal_city' => 'São Paulo',
	'fiscal_neighborhood' => 'Sé',
	'fiscal_street' => 'Praça da Sé',
	'fiscal_number' => '1',
);
$papelito_members = array(
	3 => array( 'id' => 30, 'member_role' => 'buyer', 'member_status' => 'active', 'expires_at' => null ),
	5 => array( 'id' => 50, 'member_role' => 'viewer', 'member_status' => 'active', 'expires_at' => null ),
	4 => array( 'id' => 40, 'member_role' => 'buyer', 'member_status' => 'active', 'identity_requirement' => 'not_required', 'expires_at' => null ),
);

function policy_context( ?int $company_id = 10 ): array {
	return array(
		'identityStatus' => 'verified',
		'companyId' => $company_id,
		'companySelectionRequired' => false,
	);
}

$failures = 0;
function policy_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "PASS: {$label}\n"; return; }
	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

$admin = papelito_company_purchase_capability( 1, policy_context() );
policy_assert_same( 'internal admin is not buyer', 'not_buyer', $admin['purchaseMode'] );
policy_assert_same( 'internal admin skips onboarding', false, $admin['requiresB2bOnboarding'] );
$vendor = papelito_company_purchase_capability( 2, policy_context() );
policy_assert_same( 'pure vendor is not buyer', 'not_buyer', $vendor['purchaseMode'] );
policy_assert_same( 'pure vendor skips onboarding', false, $vendor['requiresB2bOnboarding'] );
$hybrid = papelito_company_purchase_capability( 3, policy_context() );
policy_assert_same( 'hybrid is classified', 'hybrid', $hybrid['userContextType'] );
policy_assert_same( 'hybrid buyer can purchase', true, $hybrid['canPurchase'] );
$missing = papelito_company_purchase_capability( 4, policy_context( null ) );
policy_assert_same( 'customer without company is blocked', 'blocked', $missing['purchaseMode'] );
policy_assert_same( 'customer without company has stable reason', 'company_missing', $missing['purchaseBlockReason'] );
$invited_context = policy_context();
$invited_context['identityStatus'] = 'incomplete';
$invited = papelito_company_purchase_capability( 4, $invited_context );
policy_assert_same( 'invited buyer without CPF can purchase', true, $invited['canPurchase'] );
$viewer = papelito_company_purchase_capability( 5, policy_context() );
policy_assert_same( 'viewer is blocked', false, $viewer['canPurchase'] );
policy_assert_same( 'viewer has stable reason', 'role_cannot_purchase', $viewer['purchaseBlockReason'] );

exit( $failures > 0 ? 1 : 0 );
