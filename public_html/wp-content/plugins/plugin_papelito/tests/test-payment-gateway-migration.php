<?php
/**
 * Migração assistida de vendor para o fluxo de gateways: preview sem efeito e aplicação idempotente.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}
}

class WP_User {
	public function __construct( public int $ID ) {}
}

$GLOBALS['mig_meta']     = array( 120 => array( 'cnpj' => '11222333000181' ) );
$GLOBALS['mig_writes']   = 0;
$GLOBALS['mig_external'] = 0;
$GLOBALS['mig_open']     = array( 'unknown' => 0, 'creating' => 0, 'pending' => 0 );
$GLOBALS['mig_profile']  = null;
$GLOBALS['mig_health']   = array();
$GLOBALS['mig_pref']     = array( 'preferredProvider' => 'mercadopago', 'version' => 0, 'explicit' => false );

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-16 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function papelito_env( string $key, mixed $default = null ): mixed { return array( 'PAPELITO_PAYMENT_GATEWAYS_ENABLED' => 'false' )[ $key ] ?? $default; }
function get_userdata( int $id ): ?WP_User { return 120 === $id || 121 === $id ? new WP_User( $id ) : null; }
function papelito_user_is_effective_seller( mixed $user ): bool { return 120 === $user->ID; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['mig_meta'][ $user_id ][ $key ] ?? ''; }
function add_user_meta( int $user_id, string $key, mixed $value, bool $unique = false ): bool {
	++$GLOBALS['mig_writes'];
	if ( $unique && isset( $GLOBALS['mig_meta'][ $user_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['mig_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function papelito_vendor_cnae_current( int $vendor_id ): array { return array( 'status' => 'verified', 'primary' => '4729601', 'secondary' => array(), 'reason' => '' ); }
function papelito_vendor_cnae_refresh( int $vendor_id ): array { ++$GLOBALS['mig_external']; return array(); }
function papelito_vendor_payment_policy_classify( array $evidence ): array { return array( 'status' => 'blocked_cnae', 'codes' => array( array( 'code' => '4729601', 'position' => 'primary' ) ) ); }
function papelito_vendor_financial_profile_import_legacy( int $vendor_id, bool $dry_run ) {
	if ( ! $dry_run ) {
		++$GLOBALS['mig_writes'];
		$GLOBALS['mig_profile'] = array( 'version' => 1 );
	}
	return array( 'action' => null === $GLOBALS['mig_profile'] ? 'create' : 'noop', 'version' => (int) ( $GLOBALS['mig_profile']['version'] ?? 0 ) );
}
function papelito_vendor_payment_preference( int $vendor_id ): array { return $GLOBALS['mig_pref']; }
function papelito_pagarme_get_vendor_recipient_state( int $vendor_id ): array { return array( 'recipient_id' => 're_1', 'status' => 'active' ); }
function papelito_mercadopago_environment(): string { return 'test'; }
function papelito_vendor_payment_account_latest( int $vendor_id, string $provider, string $env ): ?array { return null; }
function papelito_payment_attempts_open_counts( int $vendor_id ): array { return $GLOBALS['mig_open']; }
function papelito_payment_gateway_health_record( int $vendor_id, string $provider ): array { $GLOBALS['mig_health'][] = $provider; return array(); }
function papelito_pagarme_request(): never { throw new RuntimeException( 'migração não pode chamar a Pagar.me' ); }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_gateway_migration_cli.php';

$failures = 0;

function mig_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

$plan = papelito_payment_migration_plan( 120 );
mig_assert( 'preview não grava nada nem consulta fora', 0 === $GLOBALS['mig_writes'] && 0 === $GLOBALS['mig_external'] );
mig_assert( 'preview lista as operações previstas', array( 'import_profile', 'mark_policy_enforced' ) === $plan['operations'] );
mig_assert( 'preview mostra CNAE e bloqueio Pagar.me', 'blocked_cnae' === $plan['pagarme']['policy'] && '4729601' === $plan['cnae']['primary'] );
mig_assert( 'preview preserva o recipient Pagar.me', 'active' === $plan['pagarme']['recipientStatus'] );
mig_assert( 'preview não traz CNPJ completo', false === strpos( json_encode( $plan ), '11222333000181' ) );
mig_assert( 'preview traz versão para a aplicação', 1 === preg_match( '/^[a-f0-9]{12}$/', $plan['version'] ) );

$stale = papelito_payment_migration_apply( 120, 'outra-versao' );
mig_assert( 'versão diferente do preview é recusada sem gravar', is_wp_error( $stale ) && 'papelito_payment_migration_stale' === $stale->get_error_code() && 0 === $GLOBALS['mig_writes'] );

$applied = papelito_payment_migration_apply( 120, $plan['version'] );
mig_assert( 'aplicação importa o perfil e grava o marcador', ! is_wp_error( $applied ) && papelito_vendor_payment_policy_enforced( 120 ) && null !== $GLOBALS['mig_profile'] );
mig_assert( 'aplicação reavalia a saúde dos dois gateways', array( 'pagarme', 'mercadopago' ) === $GLOBALS['mig_health'] );
mig_assert( 'preferência implícita (Mercado Pago) não é sobrescrita', false === $GLOBALS['mig_pref']['explicit'] );

$replan = papelito_payment_migration_plan( 120 );
$writes = $GLOBALS['mig_writes'];
mig_assert( 'segunda execução não tem operações', array() === $replan['operations'] );
$again = papelito_payment_migration_apply( 120, $replan['version'] );
mig_assert( 'reaplicar é seguro e não duplica', ! is_wp_error( $again ) && $writes === $GLOBALS['mig_writes'] );

$GLOBALS['mig_open']['unknown'] = 1;
$blocked_plan = papelito_payment_migration_plan( 120 );
$blocked      = papelito_payment_migration_apply( 120, $blocked_plan['version'] );
mig_assert( 'tentativa unknown ativa interrompe a migração', in_array( 'active_attempts_unknown', $blocked_plan['blockers'], true ) && is_wp_error( $blocked ) );

mig_assert( 'usuário que não é vendor é recusado', is_wp_error( papelito_payment_migration_plan( 121 ) ) );
mig_assert( 'vendor inexistente é recusado', is_wp_error( papelito_payment_migration_plan( 999 ) ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
