<?php
/**
 * Vinculação OAuth do Mercado Pago: state de uso único, PKCE, sessão iniciadora e troca do código.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
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

$GLOBALS['oauth_env']        = array(
	'MERCADOPAGO_CLIENT_ID'                => '4428161262826515',
	'MERCADOPAGO_CLIENT_SECRET'            => 'client-secret',
	'MERCADOPAGO_WEBHOOK_SECRET'           => 'wh',
	'MERCADOPAGO_REDIRECT_URI'             => 'https://papelito.example/api/vendor/mercadopago/callback',
	'MERCADOPAGO_ENVIRONMENT'              => 'test',
	'PAPELITO_PAYMENT_GATEWAYS_ENABLED'    => 'true',
	'PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS' => '71,72',
	'PAPELITO_ALLOWED_ORIGINS'             => 'https://marketplace.papelito.com,https://papelito-web.vercel.app',
);
$GLOBALS['oauth_meta']       = array(
	71 => array( 'cnpj' => '11222333000181' ),
	72 => array( 'cnpj' => '44555666000199' ),
);
$GLOBALS['oauth_transients'] = array();
$GLOBALS['oauth_requests']   = array();
$GLOBALS['oauth_response']   = null;
$GLOBALS['oauth_connected']  = array();
$GLOBALS['oauth_accounts']   = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): void { $GLOBALS['oauth_actions'][] = $args[0]; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }
function wp_get_environment_type(): string { return 'local'; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_salt( string $scheme = 'auth' ): string { return 'salt-' . $scheme; }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function add_query_arg( array $args, string $url ): string { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['oauth_env'][ $key ] ?? $default; }
function papelito_integration_secret( string $slug ): string { return ''; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['oauth_meta'][ $user_id ][ $key ] ?? ''; }
function add_user_meta( int $user_id, string $key, mixed $value, bool $unique = false ): bool { $GLOBALS['oauth_meta'][ $user_id ][ $key ] = $value; return true; }
function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['oauth_transients'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['oauth_transients'][ $key ] ?? false; }
function delete_transient( string $key ): bool {
	if ( ! array_key_exists( $key, $GLOBALS['oauth_transients'] ) ) {
		return false;
	}
	unset( $GLOBALS['oauth_transients'][ $key ] );
	return true;
}
function papelito_vendor_secret_encrypt( string $plain ): string { return 'k1:' . base64_encode( $plain ); }
function papelito_vendor_secret_decrypt( string $envelope ) { return base64_decode( substr( $envelope, 3 ) ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_mercadopago_pre_request' === $hook ) {
		$GLOBALS['oauth_requests'][] = array( 'url' => $args[1], 'body' => json_decode( (string) $args[2]['body'], true ) );
		return $GLOBALS['oauth_response'];
	}
	return $value;
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }
function papelito_vendor_payment_account_connect( int $vendor_id, string $provider, string $environment, array $data ) {
	$GLOBALS['oauth_connected'][] = compact( 'vendor_id', 'provider', 'environment', 'data' );
	$account = array( 'id' => count( $GLOBALS['oauth_connected'] ), 'vendor_id' => $vendor_id, 'provider' => $provider, 'environment' => $environment, 'collector_id' => $data['collector_id'], 'status' => 'active', 'identity_status' => 'verification_pending', 'account_version' => 1 );
	$GLOBALS['oauth_accounts'][ $vendor_id ] = $account;
	return $account;
}
function papelito_vendor_payment_account_current( int $vendor_id, string $provider, string $environment ): ?array { return $GLOBALS['oauth_accounts'][ $vendor_id ] ?? null; }
function papelito_vendor_payment_account_disconnect( int $account_id ): bool {
	foreach ( $GLOBALS['oauth_accounts'] as $vendor => $account ) {
		if ( $account['id'] === $account_id ) {
			unset( $GLOBALS['oauth_accounts'][ $vendor ] );
		}
	}
	return true;
}
function papelito_vendor_payment_account_public( array $account ): array { return array( 'collectorId' => $account['collector_id'], 'status' => $account['status'], 'identityStatus' => $account['identity_status'] ); }

require_once dirname( __DIR__ ) . '/includes/frontend_links.php';
require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_client.php';
require_once dirname( __DIR__ ) . '/includes/mercadopago_oauth.php';

const OAUTH_TEST_BINDING = 'binding-cookie-value-abcdefghijklmnop';
const OAUTH_TEST_TOKEN   = 'APP_USR-4428161262826515-101612-abc-900001';

$failures = 0;

function oauth_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function oauth_state_from( string $url ): string {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
	return (string) ( $query['state'] ?? '' );
}

function oauth_token_response( string $user_id, bool $live_mode = false ): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode(
			array(
				'access_token'  => OAUTH_TEST_TOKEN,
				'refresh_token' => 'TG-refresh',
				'expires_in'    => 15552000,
				'user_id'       => (int) $user_id,
				'public_key'    => 'APP_USR-pk',
				'scope'         => 'offline_access payments write',
				'live_mode'     => $live_mode,
			)
		),
	);
}

$pair = papelito_mercadopago_oauth_pkce_pair();
oauth_assert( 'code_verifier tem entre 43 e 128 caracteres', strlen( $pair['verifier'] ) >= 43 && strlen( $pair['verifier'] ) <= 128 );
oauth_assert( 'code_challenge é S256 base64url do verifier', rtrim( strtr( base64_encode( hash( 'sha256', $pair['verifier'], true ) ), '+/', '-_' ), '=' ) === $pair['challenge'] );

$outsider = papelito_mercadopago_oauth_begin( 99, OAUTH_TEST_BINDING );
oauth_assert( 'vendor fora da coorte não inicia conexão', is_wp_error( $outsider ) );

$short = papelito_mercadopago_oauth_begin( 71, 'curto' );
oauth_assert( 'binding de sessão fraco é recusado', is_wp_error( $short ) );

$begin = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
$url   = (string) ( $begin['authorizationUrl'] ?? '' );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
oauth_assert( 'URL de autorização usa o host oficial brasileiro', 0 === strpos( $url, 'https://auth.mercadopago.com.br/authorization?' ) );
oauth_assert( 'URL leva client_id, redirect exato e response_type code', '4428161262826515' === $query['client_id'] && $GLOBALS['oauth_env']['MERCADOPAGO_REDIRECT_URI'] === $query['redirect_uri'] && 'code' === $query['response_type'] );
oauth_assert( 'URL leva PKCE S256 e state opaco', 'S256' === $query['code_challenge_method'] && strlen( $query['state'] ) >= 32 && '' !== $query['code_challenge'] );
oauth_assert( 'state não carrega vendor nem CNPJ', false === strpos( $query['state'], '71' ) || 1 === preg_match( '/^[A-Za-z0-9_-]{43,}$/', $query['state'] ) );
$stored = array_values( $GLOBALS['oauth_transients'] )[0] ?? array();
oauth_assert( 'verifier fica cifrado no state guardado', isset( $stored['verifier'] ) && $stored['verifier'] !== $pair['verifier'] && 0 === strpos( $stored['verifier'], 'k1:' ) );
oauth_assert( 'binding guardado só como hash', ! in_array( OAUTH_TEST_BINDING, $stored, true ) );

$state = oauth_state_from( $url );

$GLOBALS['oauth_response'] = oauth_token_response( '900001' );
$wrong_vendor = papelito_mercadopago_oauth_complete( 72, 'TG-code', $state, OAUTH_TEST_BINDING );
oauth_assert( 'código não vincula B a state de A', is_wp_error( $wrong_vendor ) && array() === $GLOBALS['oauth_requests'] );

$wrong_session = papelito_mercadopago_oauth_complete( 71, 'TG-code', $state, 'outra-sessao-binding-zzzzzzzzzzzzzzz' );
oauth_assert( 'sessão diferente da iniciadora é recusada sem gastar o código', is_wp_error( $wrong_session ) && array() === $GLOBALS['oauth_requests'] );

$done = papelito_mercadopago_oauth_complete( 71, 'TG-code', $state, OAUTH_TEST_BINDING );
$sent = end( $GLOBALS['oauth_requests'] );
oauth_assert( 'troca do código com a sessão correta conecta', ! is_wp_error( $done ) && '900001' === $GLOBALS['oauth_connected'][0]['data']['collector_id'] );
oauth_assert( 'troca envia code_verifier e redirect exato', $pair['verifier'] !== ( $sent['body']['code_verifier'] ?? '' ) && 43 <= strlen( (string) ( $sent['body']['code_verifier'] ?? '' ) ) && $GLOBALS['oauth_env']['MERCADOPAGO_REDIRECT_URI'] === $sent['body']['redirect_uri'] );
oauth_assert( 'conexão bem-sucedida não declara KYC aprovado', 'verification_pending' === ( $done['account']['identityStatus'] ?? '' ) );
oauth_assert( 'resposta não carrega token', false === strpos( json_encode( $done ), OAUTH_TEST_TOKEN ) && false === strpos( json_encode( $done ), 'TG-refresh' ) );

$requests = count( $GLOBALS['oauth_requests'] );
$reused   = papelito_mercadopago_oauth_complete( 71, 'TG-code', $state, OAUTH_TEST_BINDING );
oauth_assert( 'state reusado é recusado sem nova troca', is_wp_error( $reused ) && count( $GLOBALS['oauth_requests'] ) === $requests );

$begin = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
$state = oauth_state_from( $begin['authorizationUrl'] );
$GLOBALS['oauth_meta'][71]['cnpj'] = '99888777000166';
$cnpj_changed = papelito_mercadopago_oauth_complete( 71, 'TG-code-2', $state, OAUTH_TEST_BINDING );
oauth_assert( 'CNPJ alterado entre início e retorno invalida o fluxo', is_wp_error( $cnpj_changed ) );
$GLOBALS['oauth_meta'][71]['cnpj'] = '11222333000181';

$begin = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
$state = oauth_state_from( $begin['authorizationUrl'] );
$GLOBALS['oauth_env']['MERCADOPAGO_CLIENT_ID'] = '999';
$config_changed = papelito_mercadopago_oauth_complete( 71, 'TG-code-3', $state, OAUTH_TEST_BINDING );
oauth_assert( 'configuração alterada entre início e retorno invalida o fluxo', is_wp_error( $config_changed ) );
$GLOBALS['oauth_env']['MERCADOPAGO_CLIENT_ID'] = '4428161262826515';

$begin = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
$state = oauth_state_from( $begin['authorizationUrl'] );
foreach ( $GLOBALS['oauth_transients'] as $key => $record ) {
	$GLOBALS['oauth_transients'][ $key ]['expires_at'] = time() - 1;
}
oauth_assert( 'state expirado é recusado', is_wp_error( papelito_mercadopago_oauth_complete( 71, 'TG-code-4', $state, OAUTH_TEST_BINDING ) ) );

$connected_before = count( $GLOBALS['oauth_connected'] );
$begin = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
$state = oauth_state_from( $begin['authorizationUrl'] );
$GLOBALS['oauth_response'] = array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'error' => 'invalid_grant', 'message' => 'invalid_grant' ) ) );
$denied = papelito_mercadopago_oauth_complete( 71, 'TG-code-5', $state, OAUTH_TEST_BINDING );
oauth_assert( 'erro na troca não apaga a vinculação anterior', is_wp_error( $denied ) && isset( $GLOBALS['oauth_accounts'][71] ) && count( $GLOBALS['oauth_connected'] ) === $connected_before );

$denied_by_user = papelito_mercadopago_oauth_callback_error( 'access_denied' );
oauth_assert( 'OAuth negado pela loja vira erro legível', is_wp_error( $denied_by_user ) && 'papelito_mercadopago_oauth_denied' === $denied_by_user->get_error_code() );

$begin = papelito_mercadopago_oauth_begin( 72, OAUTH_TEST_BINDING );
$state = oauth_state_from( $begin['authorizationUrl'] );
$GLOBALS['oauth_response'] = oauth_token_response( '900002', true );
$connected_before = count( $GLOBALS['oauth_connected'] );
$mode_mismatch    = papelito_mercadopago_oauth_complete( 72, 'TG-code-6', $state, OAUTH_TEST_BINDING );
oauth_assert( 'token de produção não vincula em ambiente de teste', is_wp_error( $mode_mismatch ) && count( $GLOBALS['oauth_connected'] ) === $connected_before );

$disconnected = papelito_mercadopago_oauth_disconnect( 71 );
oauth_assert( 'desconexão desativa a conta vigente', true === $disconnected && ! isset( $GLOBALS['oauth_accounts'][71] ) );
oauth_assert( 'desconectar sem conta é erro recuperável', is_wp_error( papelito_mercadopago_oauth_disconnect( 71 ) ) );

const OAUTH_TEST_VERCEL_CALLBACK = 'https://papelito-web.vercel.app/api/vendor/mercadopago/callback';

$_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] = 'https://papelito-web.vercel.app';
$GLOBALS['oauth_accounts'] = array();
$dynamic_begin = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
parse_str( (string) parse_url( (string) ( $dynamic_begin['authorizationUrl'] ?? '' ), PHP_URL_QUERY ), $dynamic_query );
oauth_assert( 'redirect sai do domínio que iniciou a conexão', OAUTH_TEST_VERCEL_CALLBACK === ( $dynamic_query['redirect_uri'] ?? '' ) );
unset( $_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] );
$GLOBALS['oauth_response'] = oauth_token_response( '900001' );
papelito_mercadopago_oauth_complete( 71, 'TG-code-dynamic', oauth_state_from( (string) $dynamic_begin['authorizationUrl'] ), OAUTH_TEST_BINDING );
oauth_assert( 'troca do código usa o mesmo redirect da autorização', OAUTH_TEST_VERCEL_CALLBACK === ( end( $GLOBALS['oauth_requests'] )['body']['redirect_uri'] ?? '' ) );

$_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] = 'https://atacante.example';
$foreign_begin = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
parse_str( (string) parse_url( (string) ( $foreign_begin['authorizationUrl'] ?? '' ), PHP_URL_QUERY ), $foreign_query );
oauth_assert( 'domínio fora da allowlist cai no redirect configurado', $GLOBALS['oauth_env']['MERCADOPAGO_REDIRECT_URI'] === ( $foreign_query['redirect_uri'] ?? '' ) );

$configured_redirect = $GLOBALS['oauth_env']['MERCADOPAGO_REDIRECT_URI'];
unset( $GLOBALS['oauth_env']['MERCADOPAGO_REDIRECT_URI'] );
$_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] = 'https://papelito-web.vercel.app';
$no_config = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
oauth_assert( 'sem redirect configurado, o domínio da requisição basta', ! is_wp_error( $no_config ) );
unset( $_SERVER['HTTP_X_PAPELITO_FRONTEND_BASE'] );
$no_redirect = papelito_mercadopago_oauth_begin( 71, OAUTH_TEST_BINDING );
oauth_assert( 'sem domínio e sem redirect configurado a conexão não começa', is_wp_error( $no_redirect ) );
$GLOBALS['oauth_env']['MERCADOPAGO_REDIRECT_URI'] = $configured_redirect;

class WP_User {
	public int $ID = 71;
}
function papelito_vendor_dashboard_require_seller(): mixed { return $GLOBALS['oauth_seller'] ?? new WP_User(); }
oauth_assert( 'vendedor autenticado passa na permissão', true === papelito_mercadopago_oauth_permission() );
$GLOBALS['oauth_seller'] = new WP_Error( 'papelito_vendor_forbidden', 'x', array( 'status' => 403 ) );
oauth_assert( 'não vendedor é recusado', is_wp_error( papelito_mercadopago_oauth_permission() ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
