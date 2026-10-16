<?php
/**
 * Saúde independente de cada gateway (GatewayStatus) e efeito em canReceive.
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

$GLOBALS['health_env']        = array(
	'PAPELITO_PAYMENT_GATEWAYS_ENABLED'    => 'true',
	'PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS' => '81',
	'MERCADOPAGO_CLIENT_ID'                => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET'            => 'secret',
	'MERCADOPAGO_REDIRECT_URI'             => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'              => 'test',
);
$GLOBALS['health_meta']       = array( 81 => array( 'cnpj' => '11222333000181' ) );
$GLOBALS['health_account']    = null;
$GLOBALS['health_recipient']  = array( 'recipient_id' => '', 'status' => '', 'kyc_status' => '', 'kyc_status_reason' => '', 'last_error' => '', 'last_sync_at' => '' );
$GLOBALS['health_policy']     = array( 'status' => 'allowed', 'codes' => array(), 'reason' => '' );
$GLOBALS['health_bumps']      = 0;

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_get_environment_type(): string { return 'local'; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function wp_salt( string $scheme = 'auth' ): string { return 'salt-' . $scheme; }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['health_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['health_meta'][ $user_id ][ $key ] ?? ''; }
function add_user_meta( int $user_id, string $key, mixed $value, bool $unique = false ): bool { $GLOBALS['health_meta'][ $user_id ][ $key ] = $value; return true; }
function papelito_vendor_payment_account_current( int $vendor_id, string $provider, string $environment ): ?array { return $GLOBALS['health_account']; }
function papelito_vendor_payment_account_latest( int $vendor_id, string $provider, string $environment ): ?array { return $GLOBALS['health_account']; }
function papelito_pagarme_get_vendor_recipient_state( int $vendor_id ): array { return $GLOBALS['health_recipient']; }
function papelito_pagarme_kyc_action_required( string $status, string $kyc, string $reason ): bool { return 'pending' === $kyc; }
function papelito_vendor_pagarme_policy( int $vendor_id ): array { return array_merge( array( 'rule_version' => 'r1', 'evidence_status' => 'verified' ), $GLOBALS['health_policy'] ); }
function papelito_pagarme_financial_profile_pending( int $vendor_id ): array { return array( 'policy' => $GLOBALS['health_policy']['status'], 'pendingFields' => array( 'bankAccount.accountNumber' ) ); }
function papelito_coverage_bump_cache_version( mixed ...$args ): void { ++$GLOBALS['health_bumps']; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_oauth.php';
require_once dirname( __DIR__ ) . '/includes/payment_gateway_health.php';

const HEALTH_TEST_VENDOR = 81;

$failures = 0;

function health_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function health_account( array $overrides = array() ): array {
	return array_merge(
		array(
			'id'                    => 5,
			'vendor_id'             => HEALTH_TEST_VENDOR,
			'provider'              => 'mercadopago',
			'environment'           => 'test',
			'status'                => 'active',
			'collector_id'          => '900001',
			'identity_status'       => 'verified',
			'cnpj_fingerprint'      => papelito_mercadopago_oauth_cnpj_fingerprint( HEALTH_TEST_VENDOR ),
			'configuration_version' => papelito_mercadopago_configuration_version(),
			'token_expires_at'      => gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS ),
			'health_status'         => '',
			'health_reason'         => '',
			'health_checked_at'     => '2026-10-16 10:00:00',
			'health_success_at'     => '2026-10-16 10:00:00',
			'access_token_envelope' => 'k1:x',
			'last_error_code'       => '',
		),
		$overrides
	);
}

function mp_status(): array {
	return papelito_payment_gateway_status( HEALTH_TEST_VENDOR, 'mercadopago' );
}

function pagarme_status(): array {
	return papelito_payment_gateway_status( HEALTH_TEST_VENDOR, 'pagarme' );
}

$status = mp_status();
health_assert( 'sem conta Mercado Pago é unconfigured', 'unconfigured' === $status['state'] && false === $status['canReceive'] );
health_assert( 'GatewayStatus traz os campos do contrato', array() === array_diff( array( 'provider', 'state', 'connectionStatus', 'kycStatus', 'restrictionCode', 'healthStatus', 'canReceive', 'reasonCodes', 'pendingFields', 'lastCheckedAt', 'lastSuccessAt', 'configurationVersion' ), array_keys( $status ) ) );

$GLOBALS['health_account'] = health_account( array( 'identity_status' => 'verification_pending' ) );
$status = mp_status();
health_assert( 'OAuth válido sem prova de titularidade é verification_pending', 'verification_pending' === $status['state'] && false === $status['canReceive'] && 'connected' === $status['connectionStatus'] );
health_assert( 'conexão OAuth não aparece como KYC aprovado', 'approved' !== $status['kycStatus'] );

$GLOBALS['health_account'] = health_account();
$status = mp_status();
health_assert( 'conta verificada e token vigente é ready', 'ready' === $status['state'] && true === $status['canReceive'] );

$GLOBALS['health_account'] = health_account( array( 'health_status' => 'degraded', 'health_reason' => 'refresh_unavailable' ) );
$status = mp_status();
health_assert( 'timeout de reconsulta é degraded sem apagar a prova', 'degraded' === $status['state'] && true === $status['canReceive'] );
health_assert( 'motivo do degraded é código, não erro bruto', in_array( 'refresh_unavailable', $status['reasonCodes'], true ) );

$GLOBALS['health_account'] = health_account( array( 'status' => 'revoked', 'access_token_envelope' => '' ) );
$status = mp_status();
health_assert( 'token revogado exige reconexão', 'connection_pending' === $status['state'] && false === $status['canReceive'] && in_array( 'reauthorization_required', $status['reasonCodes'], true ) );

$GLOBALS['health_account'] = health_account( array( 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
health_assert( 'token vencido retira canReceive', false === mp_status()['canReceive'] );

$GLOBALS['health_account'] = health_account( array( 'cnpj_fingerprint' => 'outro' ) );
$status = mp_status();
health_assert( 'conta de CNPJ anterior não recebe pelo CNPJ novo', false === $status['canReceive'] && in_array( 'cnpj_changed', $status['reasonCodes'], true ) );

$GLOBALS['health_account'] = health_account( array( 'configuration_version' => 'velha' ) );
health_assert( 'conta de configuração obsoleta não recebe', false === mp_status()['canReceive'] );

$GLOBALS['health_account'] = health_account( array( 'identity_status' => 'mismatch' ) );
health_assert( 'CNPJ divergente da conta é unavailable', 'unavailable' === mp_status()['state'] );

$GLOBALS['health_account'] = health_account( array( 'health_status' => 'kyc_pending', 'health_reason' => 'regulatory_pending' ) );
$status = mp_status();
health_assert( 'pendência regulatória do Mercado Pago é kyc_pending', 'kyc_pending' === $status['state'] && false === $status['canReceive'] );

$GLOBALS['health_account'] = health_account();
$GLOBALS['health_policy']  = array( 'status' => 'blocked_cnae', 'codes' => array( array( 'code' => '4729601', 'position' => 'primary', 'label' => 'x' ) ), 'reason' => '' );
$GLOBALS['health_recipient'] = array( 'recipient_id' => 're_1', 'status' => 'active', 'kyc_status' => 'approved', 'kyc_status_reason' => '', 'last_error' => '', 'last_sync_at' => '2026-10-16 09:00:00' );
$status = pagarme_status();
health_assert( 'CNAE bloqueado é blocked_cnae mesmo com recipient ativo', 'blocked_cnae' === $status['state'] && false === $status['canReceive'] && 'blocked_cnae' === $status['restrictionCode'] );
health_assert( 'bloqueio informa o código encontrado', '4729601' === $status['cnaeCodes'][0]['code'] );
health_assert( 'bloqueio Pagar.me não muda a saúde Mercado Pago', 'ready' === mp_status()['state'] );

$GLOBALS['health_policy'] = array( 'status' => 'verification_pending', 'codes' => array(), 'reason' => 'never_checked' );
health_assert( 'CNAE não verificado deixa Pagar.me em verification_pending', 'verification_pending' === pagarme_status()['state'] );

$GLOBALS['health_policy'] = array( 'status' => 'allowed', 'codes' => array(), 'reason' => '' );
health_assert( 'recipient ativo com CNAE permitido é ready', 'ready' === pagarme_status()['state'] && pagarme_status()['canReceive'] );

$GLOBALS['health_recipient']['last_error'] = 'timeout';
health_assert( 'erro de sincronização sobre recipient ativo é degraded que ainda recebe', 'degraded' === pagarme_status()['state'] && pagarme_status()['canReceive'] );

$GLOBALS['health_recipient'] = array( 'recipient_id' => 're_1', 'status' => 'affiliation', 'kyc_status' => 'pending', 'kyc_status_reason' => '', 'last_error' => '', 'last_sync_at' => '' );
health_assert( 'recipient em credenciamento é kyc_pending', 'kyc_pending' === pagarme_status()['state'] );

$GLOBALS['health_recipient'] = array( 'recipient_id' => 're_1', 'status' => 'refused', 'kyc_status' => '', 'kyc_status_reason' => '', 'last_error' => '', 'last_sync_at' => '' );
health_assert( 'recipient recusado é unavailable', 'unavailable' === pagarme_status()['state'] );

$GLOBALS['health_recipient'] = array( 'recipient_id' => '', 'status' => '', 'kyc_status' => '', 'kyc_status_reason' => '', 'last_error' => '', 'last_sync_at' => '' );
$status = pagarme_status();
health_assert( 'sem recipient a Pagar.me é unconfigured com pendências próprias', 'unconfigured' === $status['state'] && in_array( 'bankAccount.accountNumber', $status['pendingFields'], true ) );

$public = wp_json_encode_safe( mp_status() );
health_assert( 'cards não recebem token nem ID de conta interna', false === strpos( $public, 'k1:x' ) && false === strpos( $public, 'access_token' ) );

$GLOBALS['health_account'] = health_account();
$bumps = $GLOBALS['health_bumps'];
papelito_payment_gateway_health_record( HEALTH_TEST_VENDOR, 'mercadopago' );
papelito_payment_gateway_health_record( HEALTH_TEST_VENDOR, 'mercadopago' );
health_assert( 'mesma aptidão não invalida a cobertura de novo', $GLOBALS['health_bumps'] <= $bumps + 1 );
$GLOBALS['health_account'] = null;
$before = $GLOBALS['health_bumps'];
papelito_payment_gateway_health_record( HEALTH_TEST_VENDOR, 'mercadopago' );
health_assert( 'mudança de aptidão invalida a cobertura', $GLOBALS['health_bumps'] === $before + 1 );

$statuses = papelito_payment_gateway_statuses( HEALTH_TEST_VENDOR );
health_assert( 'leitura em lote devolve os dois gateways', array( 'pagarme', 'mercadopago' ) === array_column( $statuses, 'provider' ) );

function wp_json_encode_safe( array $value ): string {
	return (string) json_encode( $value );
}

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
