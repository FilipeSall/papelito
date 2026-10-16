<?php
/**
 * Política local que restringe a Pagar.me para os CNAEs de fumo.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

$GLOBALS['policy_test_env']      = array();
$GLOBALS['policy_test_meta']     = array();
$GLOBALS['policy_test_bumps']    = 0;

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function wp_salt( string $scheme = 'auth' ): string { return 'salt-' . $scheme; }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-16 12:00:00'; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['policy_test_env'][ $key ] ?? $default; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['policy_test_meta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( int $user_id, string $key, mixed $value ): bool { $GLOBALS['policy_test_meta'][ $user_id ][ $key ] = $value; return true; }
function add_user_meta( int $user_id, string $key, mixed $value, bool $unique = false ): bool {
	if ( $unique && isset( $GLOBALS['policy_test_meta'][ $user_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['policy_test_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( int $user_id, string $key ): bool { unset( $GLOBALS['policy_test_meta'][ $user_id ][ $key ] ); return true; }
function papelito_coverage_bump_cache_version( mixed ...$args ): void { ++$GLOBALS['policy_test_bumps']; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/vendor_cnae.php';
require_once dirname( __DIR__ ) . '/includes/vendor_payment_policy.php';

const POLICY_TEST_VENDOR = 51;
const POLICY_TEST_CNPJ   = '11222333000181';
const POLICY_TEST_RETAIL = '4729601';

$failures = 0;

function policy_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function policy_evidence( string $status, ?string $primary, array $secondary ): array {
	return array(
		'version'     => 1,
		'fingerprint' => papelito_vendor_cnae_fingerprint( POLICY_TEST_CNPJ ),
		'source'      => 'brasilapi',
		'status'      => $status,
		'reason'      => '',
		'primary'     => $primary,
		'secondary'   => $secondary,
		'checked_at'  => time(),
		'valid_until' => 'verified' === $status ? time() + DAY_IN_SECONDS : time() - 1,
	);
}

foreach ( array( '4729601', '4636201', '4636202' ) as $code ) {
	$as_primary   = papelito_vendor_payment_policy_classify( policy_evidence( 'verified', $code, array() ) );
	$as_secondary = papelito_vendor_payment_policy_classify( policy_evidence( 'verified', '4761003', array( '4751201', $code ) ) );
	policy_assert( "{$code} bloqueia como principal", 'blocked_cnae' === $as_primary['status'] && 'primary' === $as_primary['codes'][0]['position'] );
	policy_assert( "{$code} bloqueia como secundário", 'blocked_cnae' === $as_secondary['status'] && 'secondary' === $as_secondary['codes'][0]['position'] );
}

$allowed = papelito_vendor_payment_policy_classify( policy_evidence( 'verified', '4761003', array( '4751201' ) ) );
policy_assert( 'CNAE não restrito verificado é permitido', 'allowed' === $allowed['status'] && array() === $allowed['codes'] );
policy_assert( 'classificação carrega a versão da regra', PAPELITO_VENDOR_PAYMENT_POLICY_RULE_VERSION === $allowed['rule_version'] );

$pending = papelito_vendor_payment_policy_classify( policy_evidence( 'pending', null, array() ) );
policy_assert( 'evidência pendente não vira allowed', 'verification_pending' === $pending['status'] );

$expired_clean = papelito_vendor_payment_policy_classify( policy_evidence( 'expired', '4761003', array() ) );
policy_assert( 'evidência vencida sem código restrito não autoriza Pagar.me', 'verification_pending' === $expired_clean['status'] );

$expired_blocked = papelito_vendor_payment_policy_classify( policy_evidence( 'expired', '4761003', array( POLICY_TEST_RETAIL ) ) );
policy_assert( 'evidência vencida de bloqueio continua bloqueando', 'blocked_cnae' === $expired_blocked['status'] );

$GLOBALS['policy_test_meta'][ POLICY_TEST_VENDOR ]['cnpj']                                = POLICY_TEST_CNPJ;
$GLOBALS['policy_test_meta'][ POLICY_TEST_VENDOR ][ PAPELITO_VENDOR_CNAE_EVIDENCE_META ] = policy_evidence( 'verified', POLICY_TEST_RETAIL, array() );

policy_assert( 'vendor legado não sofre a restrição', true === papelito_vendor_pagarme_policy_guard( POLICY_TEST_VENDOR ) );
policy_assert( 'avaliar vendor legado não grava marcador', ! papelito_vendor_payment_policy_enforced( POLICY_TEST_VENDOR ) );

$GLOBALS['policy_test_env']['PAPELITO_PAYMENT_GATEWAYS_ENABLED']    = 'true';
$GLOBALS['policy_test_env']['PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS'] = (string) POLICY_TEST_VENDOR;

$guard = papelito_vendor_pagarme_policy_guard( POLICY_TEST_VENDOR );
policy_assert( 'vendor da coorte com CNAE de fumo é bloqueado', is_wp_error( $guard ) && 'papelito_pagarme_blocked_cnae' === $guard->get_error_code() );
policy_assert( 'bloqueio informa os códigos encontrados', POLICY_TEST_RETAIL === ( $guard->get_error_data()['cnaeCodes'][0]['code'] ?? null ) );
policy_assert( 'avaliação na coorte grava marcador', papelito_vendor_payment_policy_enforced( POLICY_TEST_VENDOR ) );

$GLOBALS['policy_test_env']['PAPELITO_PAYMENT_GATEWAYS_ENABLED'] = 'false';
policy_assert( 'flag desligada não libera recipient legado de tabacaria', is_wp_error( papelito_vendor_pagarme_policy_guard( POLICY_TEST_VENDOR ) ) );
$GLOBALS['policy_test_env']['PAPELITO_PAYMENT_GATEWAYS_ENABLED'] = 'true';

$GLOBALS['policy_test_meta'][ POLICY_TEST_VENDOR ][ PAPELITO_VENDOR_CNAE_EVIDENCE_META ] = policy_evidence( 'pending', null, array() );
$guard = papelito_vendor_pagarme_policy_guard( POLICY_TEST_VENDOR );
policy_assert( 'evidência pendente impede novo uso Pagar.me', is_wp_error( $guard ) && 'papelito_pagarme_cnae_verification_pending' === $guard->get_error_code() );

$GLOBALS['policy_test_meta'][ POLICY_TEST_VENDOR ][ PAPELITO_VENDOR_CNAE_EVIDENCE_META ] = policy_evidence( 'verified', '4761003', array() );
policy_assert( 'CNAE permitido libera a política (sem aprovar recipient)', true === papelito_vendor_pagarme_policy_guard( POLICY_TEST_VENDOR ) );

$bumps = $GLOBALS['policy_test_bumps'];
papelito_vendor_payment_policy_evidence_changed( POLICY_TEST_VENDOR );
policy_assert( 'primeira avaliação registrada invalida a cobertura', $GLOBALS['policy_test_bumps'] === $bumps + 1 );
papelito_vendor_payment_policy_evidence_changed( POLICY_TEST_VENDOR );
policy_assert( 'reavaliação sem mudança não invalida de novo', $GLOBALS['policy_test_bumps'] === $bumps + 1 );

$GLOBALS['policy_test_meta'][ POLICY_TEST_VENDOR ][ PAPELITO_VENDOR_CNAE_EVIDENCE_META ] = policy_evidence( 'verified', '4761003', array( '4636202' ) );
papelito_vendor_payment_policy_evidence_changed( POLICY_TEST_VENDOR );
policy_assert( 'transição para bloqueio invalida a cobertura', $GLOBALS['policy_test_bumps'] === $bumps + 2 );
$audit = get_user_meta( POLICY_TEST_VENDOR, PAPELITO_VENDOR_PAYMENT_POLICY_AUDIT_META, true );
policy_assert( 'transição fica auditada sem CNPJ', is_array( $audit ) && 'blocked_cnae' === end( $audit )['status'] && false === strpos( json_encode( $audit ), POLICY_TEST_CNPJ ) );

$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/pagarme_recipients.php' );
foreach ( array( 'papelito_pagarme_upsert_vendor_recipient', 'papelito_pagarme_create_vendor_kyc_link' ) as $function ) {
	$start = strpos( $source, "function {$function}(" );
	$body  = false === $start ? '' : substr( $source, $start, (int) strpos( $source, "\n}\n", $start ) - $start );
	$guard = strpos( $body, 'papelito_pagarme_policy_guard_for' );
	$call  = strpos( $body, 'papelito_pagarme_request' );
	$sync  = strpos( $body, 'papelito_pagarme_sync_vendor_recipient' );
	$first = min( false === $call ? PHP_INT_MAX : $call, false === $sync ? PHP_INT_MAX : $sync );
	policy_assert( "{$function} aplica a política antes de qualquer request", false !== $guard && $guard < $first );
}

$payments = (string) file_get_contents( dirname( __DIR__ ) . '/includes/pagarme_payments.php' );
$start    = strpos( $payments, 'function papelito_pagarme_validate_vendor_recipient(' );
$body     = substr( $payments, $start, (int) strpos( $payments, "\n}\n", $start ) - $start );
policy_assert( 'cobrança Pagar.me aplica a política', false !== strpos( $body, 'papelito_vendor_pagarme_policy_guard' ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
