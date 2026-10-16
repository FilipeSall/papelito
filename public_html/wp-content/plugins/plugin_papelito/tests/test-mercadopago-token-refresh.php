<?php
/**
 * Renovação antecipada do token OAuth do Mercado Pago.
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

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

$GLOBALS['refresh_env']      = array(
	'MERCADOPAGO_CLIENT_ID'     => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET' => 'secret',
	'MERCADOPAGO_REDIRECT_URI'  => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'   => 'test',
);
$GLOBALS['refresh_accounts'] = array();
$GLOBALS['refresh_requests'] = 0;
$GLOBALS['refresh_response'] = null;
$GLOBALS['refresh_locks']    = array();
$GLOBALS['refresh_events']   = array();
$GLOBALS['refresh_rotate_fails'] = false;

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): void { $GLOBALS['refresh_events'][] = $args; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_get_environment_type(): string { return 'local'; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function wp_salt( string $scheme = 'auth' ): string { return 'salt-' . $scheme; }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['refresh_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' === $hook ) {
		++$GLOBALS['refresh_requests'];
		$response = $GLOBALS['refresh_response'];
		return is_callable( $response ) ? $response() : $response;
	}
	return $value;
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }
function papelito_payment_attempt_acquire_lock( string $key, int $timeout = 10 ): bool {
	if ( isset( $GLOBALS['refresh_locks'][ $key ] ) ) {
		return false;
	}
	$GLOBALS['refresh_locks'][ $key ] = true;
	return true;
}
function papelito_payment_attempt_release_lock( string $key ): void { unset( $GLOBALS['refresh_locks'][ $key ] ); }
function papelito_vendor_payment_account_find( int $id ): ?array { return $GLOBALS['refresh_accounts'][ $id ] ?? null; }
function papelito_vendor_payment_account_tokens( array $account ) { return array( 'access_token' => 'APP_USR-old', 'refresh_token' => $account['refresh'] ); }
function papelito_vendor_payment_account_rotate_tokens( array $account, array $tokens ) {
	if ( $GLOBALS['refresh_rotate_fails'] ) {
		return new WP_Error( 'papelito_payment_account_encryption_failed', 'x' );
	}
	$stored = $GLOBALS['refresh_accounts'][ $account['id'] ];
	if ( $stored['token_version'] !== $account['token_version'] ) {
		return false;
	}
	$stored['token_version']++;
	$stored['refresh'] = $tokens['refresh_token'];
	$GLOBALS['refresh_accounts'][ $account['id'] ] = $stored;
	return true;
}
function papelito_vendor_payment_account_mark_revoked( int $id, string $reason ): void { $GLOBALS['refresh_accounts'][ $id ]['status'] = 'revoked'; $GLOBALS['refresh_accounts'][ $id ]['reason'] = $reason; }
function papelito_vendor_payment_account_update_health( int $id, array $health ): void { $GLOBALS['refresh_accounts'][ $id ]['health'] = $health; }
function papelito_vendor_payment_accounts_expiring( string $provider, int $until, int $limit ): array { return array_values( $GLOBALS['refresh_accounts'] ); }
function papelito_mercadopago_configuration_version(): string { return 'cfg-1'; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_token_refresh.php';

$failures = 0;

function refresh_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function refresh_account( int $id, array $overrides = array() ): array {
	return array_merge(
		array(
			'id'                    => $id,
			'vendor_id'             => 90 + $id,
			'provider'              => 'mercadopago',
			'environment'           => 'test',
			'status'                => 'active',
			'token_version'         => 1,
			'configuration_version' => 'cfg-1',
			'token_expires_at'      => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
			'refresh'               => 'TG-old',
		),
		$overrides
	);
}

function refresh_ok(): array {
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'access_token' => 'APP_USR-new', 'refresh_token' => 'TG-new', 'expires_in' => 15552000, 'user_id' => 900001 ) ) );
}

refresh_assert( 'token longe do vencimento não renova', ! papelito_mercadopago_token_needs_refresh( refresh_account( 1, array( 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 120 * DAY_IN_SECONDS ) ) ), time() ) );
refresh_assert( 'token a 5 dias do vencimento renova', papelito_mercadopago_token_needs_refresh( refresh_account( 1 ), time() ) );
refresh_assert( 'sem expiração gravada não inventa validade fixa', ! papelito_mercadopago_token_needs_refresh( refresh_account( 1, array( 'token_expires_at' => null ) ), time() ) );

$GLOBALS['refresh_accounts'][1] = refresh_account( 1 );
$GLOBALS['refresh_response']    = refresh_ok();
$result = papelito_mercadopago_refresh_account( 1 );
refresh_assert( 'renovação grava o novo par de tokens', 'refreshed' === $result && 'TG-new' === $GLOBALS['refresh_accounts'][1]['refresh'] );
refresh_assert( 'renovação avisa a saúde do gateway', in_array( 'papelito_payment_gateway_account_changed', array_column( $GLOBALS['refresh_events'], 0 ), true ) );

$GLOBALS['refresh_accounts'][2] = refresh_account( 2 );
$requests = $GLOBALS['refresh_requests'];
$GLOBALS['refresh_response'] = static function () {
	$second = papelito_mercadopago_refresh_account( 2 );
	$GLOBALS['refresh_nested'] = $second;
	return refresh_ok();
};
$first = papelito_mercadopago_refresh_account( 2 );
refresh_assert( 'dois workers renovam uma vez só', 'refreshed' === $first && 'busy' === $GLOBALS['refresh_nested'] && $GLOBALS['refresh_requests'] === $requests + 1 );

$GLOBALS['refresh_accounts'][3] = refresh_account( 3 );
$GLOBALS['refresh_response'] = static function () {
	$GLOBALS['refresh_accounts'][3]['token_version'] = 9;
	return refresh_ok();
};
refresh_assert( 'resposta obsoleta não sobrescreve renovação mais nova', 'stale' === papelito_mercadopago_refresh_account( 3 ) );

$GLOBALS['refresh_accounts'][4] = refresh_account( 4 );
$GLOBALS['refresh_response']    = array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'error' => 'invalid_grant', 'message' => 'invalid_grant' ) ) );
refresh_assert( 'refresh recusado marca a conta para reconexão', 'revoked' === papelito_mercadopago_refresh_account( 4 ) && 'revoked' === $GLOBALS['refresh_accounts'][4]['status'] );

foreach ( array( 'timeout' => new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ), '429' => array( 'response' => array( 'code' => 429 ), 'body' => '{}' ), '503' => array( 'response' => array( 'code' => 503 ), 'body' => '{}' ) ) as $label => $response ) {
	$GLOBALS['refresh_accounts'][5] = refresh_account( 5 );
	$GLOBALS['refresh_response']    = $response;
	$outcome = papelito_mercadopago_refresh_account( 5 );
	refresh_assert( "{$label} vira degraded sem revogar", 'degraded' === $outcome && 'active' === $GLOBALS['refresh_accounts'][5]['status'] && 'degraded' === $GLOBALS['refresh_accounts'][5]['health']['health_status'] );
}

$GLOBALS['refresh_accounts'][6] = refresh_account( 6, array( 'configuration_version' => 'cfg-velha' ) );
$requests = $GLOBALS['refresh_requests'];
refresh_assert( 'conta de configuração obsoleta não é renovada', 'skipped' === papelito_mercadopago_refresh_account( 6 ) && $GLOBALS['refresh_requests'] === $requests );

$GLOBALS['refresh_accounts'][7] = refresh_account( 7, array( 'status' => 'disconnected' ) );
refresh_assert( 'conta desconectada não volta a ativa por renovação', 'skipped' === papelito_mercadopago_refresh_account( 7 ) && 'disconnected' === $GLOBALS['refresh_accounts'][7]['status'] );

$GLOBALS['refresh_accounts'][8] = refresh_account( 8 );
$GLOBALS['refresh_response']    = refresh_ok();
$GLOBALS['refresh_rotate_fails'] = true;
refresh_assert( 'falha ao gravar tokens novos não é silenciosa', 'store_failed' === papelito_mercadopago_refresh_account( 8 ) && 'refresh_store_failed' === $GLOBALS['refresh_accounts'][8]['health']['health_reason'] );
$GLOBALS['refresh_rotate_fails'] = false;

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
