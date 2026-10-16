<?php
/**
 * Gate único de venda com gateways: pelo menos um gateway utilizável, sem perder os demais requisitos.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES', 24 );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}
}

$GLOBALS['elig_env']       = array(
	'PAPELITO_PAYMENT_GATEWAYS_ENABLED'    => 'true',
	'PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS' => '101',
);
$GLOBALS['elig_meta']      = array();
$GLOBALS['elig_can']       = array( 'pagarme' => false, 'mercadopago' => false );
$GLOBALS['elig_recipient'] = array( 'recipient_id' => 're_1', 'status' => 'active', 'kyc_status' => '', 'kyc_status_reason' => '', 'last_error' => '' );

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-16 12:00:00'; }
function get_option( string $key, mixed $default = false ): mixed { return $default; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['elig_env'][ $key ] ?? $default; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['elig_meta'][ $user_id ][ $key ] ?? ''; }
function add_user_meta( int $user_id, string $key, mixed $value, bool $unique = false ): bool { $GLOBALS['elig_meta'][ $user_id ][ $key ] = $value; return true; }
function papelito_account_is_suspended( int $vendor_id ): bool { return false; }
function papelito_packaging_active_profile_count( int $vendor_id ): int { return 3; }
function papelito_packaging_profile_gate_enabled(): bool { return true; }
function papelito_pagarme_get_vendor_recipient_state( int $vendor_id ): array { return $GLOBALS['elig_recipient']; }
function papelito_payment_gateway_status( int $vendor_id, string $provider ): array {
	return array( 'provider' => $provider, 'state' => $GLOBALS['elig_can'][ $provider ] ? 'ready' : 'connection_pending', 'canReceive' => $GLOBALS['elig_can'][ $provider ], 'reasonCodes' => $GLOBALS['elig_can'][ $provider ] ? array() : array( 'not_connected' ) );
}
function papelito_payment_gateway_statuses( int $vendor_id ): array {
	return array( papelito_payment_gateway_status( $vendor_id, 'pagarme' ), papelito_payment_gateway_status( $vendor_id, 'mercadopago' ) );
}

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_gateway_resolver.php';
require_once dirname( __DIR__ ) . '/includes/vendor_eligibility.php';

$failures = 0;

function elig_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function elig_requirement( int $vendor_id, string $id ): ?array {
	foreach ( papelito_vendor_requirements( $vendor_id ) as $requirement ) {
		if ( $id === $requirement['id'] ) {
			return $requirement;
		}
	}
	return null;
}

elig_assert( 'vendor legado mantém o requisito Pagar.me de sempre', null !== elig_requirement( 7, 'pagarme' ) && null === elig_requirement( 7, 'payment_gateway' ) );
elig_assert( 'vendor legado com recipient ativo vende', papelito_vendor_is_eligible_to_sell( 7 ) );

elig_assert( 'vendor da coorte troca Pagar.me pelo requisito de gateway', null === elig_requirement( 101, 'pagarme' ) && null !== elig_requirement( 101, 'payment_gateway' ) );
elig_assert( 'sem gateway utilizável o vendor da coorte não vende, mesmo com recipient legado ativo', ! papelito_vendor_is_eligible_to_sell( 101 ) );

$GLOBALS['elig_can']['mercadopago'] = true;
elig_assert( 'Mercado Pago apto basta para vender com Pagar.me pendente', papelito_vendor_is_eligible_to_sell( 101 ) );

$GLOBALS['elig_can'] = array( 'pagarme' => true, 'mercadopago' => false );
elig_assert( 'Pagar.me apta basta para vender com Mercado Pago pendente', papelito_vendor_is_eligible_to_sell( 101 ) );
elig_assert( 'requisito informa o gateway que habilita a venda', array( 'pagarme' ) === elig_requirement( 101, 'payment_gateway' )['providers'] );

$GLOBALS['elig_meta'][101][ PAPELITO_VENDOR_PAYMENT_PREFERENCE_META ] = array( 'provider' => 'mercadopago', 'version' => 1 );
elig_assert( 'gateway escolhido inapto tira o vendor da venda, mesmo com o outro apto', ! papelito_vendor_is_eligible_to_sell( 101 ) );
elig_assert( 'motivo aponta o gateway escolhido', 'selected_gateway_unavailable' === elig_requirement( 101, 'payment_gateway' )['reason'] && array( 'mercadopago' ) === elig_requirement( 101, 'payment_gateway' )['providers'] );
unset( $GLOBALS['elig_meta'][101][ PAPELITO_VENDOR_PAYMENT_PREFERENCE_META ] );

$GLOBALS['elig_env']['PAPELITO_PAYMENT_GATEWAYS_ENABLED'] = 'false';
elig_assert( 'vendor marcado com flag desligada fica sem novas compras', ! papelito_vendor_is_eligible_to_sell( 101 ) );
elig_assert( 'motivo da suspensão é próprio', 'payment_policy_suspended' === elig_requirement( 101, 'payment_gateway' )['reason'] );

elig_assert( 'conta, caixas e demais requisitos continuam avaliados', null !== elig_requirement( 101, 'account' ) && null !== elig_requirement( 101, 'boxes' ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
