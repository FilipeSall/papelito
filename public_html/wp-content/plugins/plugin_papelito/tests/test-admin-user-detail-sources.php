<?php
/**
 * Regression test for where the administrative user screen reads each cadastral field.
 *
 * Usage: php tests/test-admin-user-detail-sources.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_VENDOR_APPLICATION_STREET_META', 'seller_application_street' );
define( 'PAPELITO_VENDOR_APPLICATION_NUMBER_META', 'seller_application_number' );
define( 'PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META', 'seller_application_complement' );
define( 'PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META', 'seller_application_neighborhood' );

$GLOBALS['usermeta']  = array();
$GLOBALS['companies'] = array();
$GLOBALS['selection'] = array();

final class WP_User {
	public array $roles = array();

	public function __construct( public int $ID, public string $display_name, public string $user_email, array $roles = array(), public string $user_registered = '2026-09-01 00:00:00' ) {
		$this->roles = $roles;
	}
}

function add_action( $hook, $callback ): void {}
function sanitize_key( string $value ): string { return strtolower( $value ); }
function get_user_meta( int $user_id, string $key, bool $single = false ) {
	return $GLOBALS['usermeta'][ $user_id ][ $key ] ?? '';
}
function papelito_company_get( int $company_id ): ?array {
	return $GLOBALS['companies'][ $company_id ] ?? null;
}
function papelito_company_active_get_selection( int $user_id ): int {
	return (int) ( $GLOBALS['selection'][ $user_id ] ?? 0 );
}
function papelito_user_has_role( WP_User $user, string $role ): bool {
	return in_array( $role, $user->roles, true );
}
function papelito_user_is_effective_seller( $user ): bool {
	return $user instanceof WP_User && papelito_user_has_role( $user, 'seller' );
}
function user_can( $user, string $capability ): bool {
	return $user instanceof WP_User && in_array( 'administrator', $user->roles, true );
}
function papelito_vendor_dashboard_orders_for_vendor( int $vendor_id ): array {
	$GLOBALS['vendor_orders_calls'][] = $vendor_id;
	return array();
}
function papelito_vendor_dashboard_order_is_paid( $order ): bool { return false; }

require_once dirname( __DIR__ ) . '/includes/admin_users.php';

$failures = array();

function assert_same( string $label, $expected, $actual, array &$failures ): void {
	if ( $expected !== $actual ) {
		$failures[] = sprintf( '%s: esperado %s, obtido %s', $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

$GLOBALS['companies'][21] = array(
	'id'                  => 21,
	'cnpj'                => '99999003000148',
	'phone'               => '(61) 4002-8922',
	'fiscal_cep'          => '71200030',
	'fiscal_state'        => 'DF',
	'fiscal_city'         => 'Brasilia',
	'fiscal_street'       => 'Trecho SIA Trecho 3',
	'fiscal_number'       => '108',
	'fiscal_complement'   => 'apt',
	'fiscal_neighborhood' => 'Zona Industrial',
);

$memberships = array( array( 'companyId' => 21 ) );

// Conta B2B: telefone, CNPJ e endereco so existem na empresa vinculada.
$customer = new WP_User( 2271, 'Marcos', 'user1@test.com', array( 'customer' ) );
$GLOBALS['usermeta'][2271] = array( 'city' => 'Brasilia', 'state' => 'DF', 'cep' => '71200030' );

$detail = papelito_admin_users_base_detail(
	$customer,
	papelito_admin_users_reference_company( 2271, $memberships )
);

assert_same( 'telefone do customer B2B', '(61) 4002-8922', $detail['phoneNumber'], $failures );
assert_same( 'cnpj do customer B2B', '99999003000148', $detail['cnpj'], $failures );
assert_same( 'logradouro do customer B2B', 'Trecho SIA Trecho 3', $detail['street'], $failures );
assert_same( 'bairro do customer B2B', 'Zona Industrial', $detail['neighborhood'], $failures );
assert_same( 'loja do customer B2B', '', $detail['storeName'], $failures );

// Onboarding B2B grava o endereco em usermeta propria e ela vence o endereco fiscal.
$GLOBALS['usermeta'][2271]['papelito_b2b_onboarding_address_street'] = 'Rua do onboarding';
$detail = papelito_admin_users_base_detail(
	$customer,
	papelito_admin_users_reference_company( 2271, $memberships )
);
assert_same( 'logradouro do onboarding vence o fiscal', 'Rua do onboarding', $detail['street'], $failures );

// Vendor legado: o usermeta continua sendo a fonte, empresa nenhuma sobrescreve.
$vendor = new WP_User( 2270, 'Papeloto', 'vendor@test.com', array( 'seller' ) );
$GLOBALS['usermeta'][2270] = array(
	'phone_number'                    => '(61) 99827-2992',
	'cnpj'                            => '65.326.368/0001-90',
	'store_name'                      => 'Papeloto',
	'seller_application_street'       => 'Quadra SQN 416 Bloco F',
	'seller_application_neighborhood' => 'Asa Norte',
);

$detail = papelito_admin_users_base_detail( $vendor, $GLOBALS['companies'][21] );

assert_same( 'telefone legado do vendor', '(61) 99827-2992', $detail['phoneNumber'], $failures );
assert_same( 'cnpj legado do vendor', '65.326.368/0001-90', $detail['cnpj'], $failures );
assert_same( 'logradouro legado do vendor', 'Quadra SQN 416 Bloco F', $detail['street'], $failures );
assert_same( 'vendor continua marcado como vendor', true, $detail['isVendor'], $failures );

// Campo realmente inexistente continua vazio, sem fallback inventado.
$orphan = new WP_User( 99, 'Sem vinculo', 'orphan@test.com', array( 'customer' ) );
$detail = papelito_admin_users_base_detail( $orphan, papelito_admin_users_reference_company( 99, array() ) );

assert_same( 'telefone sem origem', '', $detail['phoneNumber'], $failures );
assert_same( 'cnpj sem origem', '', $detail['cnpj'], $failures );

// Customer nao dispara a consulta de pedidos de vendor.
$GLOBALS['vendor_orders_calls'] = array();
papelito_admin_users_metrics( $customer );
assert_same( 'customer nao consulta pedidos de vendor', array(), $GLOBALS['vendor_orders_calls'], $failures );

$GLOBALS['vendor_orders_calls'] = array();
papelito_admin_users_metrics( $vendor );
assert_same( 'vendor continua consultando pedidos', array( 2270 ), $GLOBALS['vendor_orders_calls'], $failures );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ', $failure, "\n";
	}
	exit( 1 );
}

echo "OK\n";
