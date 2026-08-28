<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private string $code;
	private array $data;

	public function __construct( string $code, string $message = '', array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

class WP_REST_Request {}
class WP_REST_Response {}
class WP_User {}
class Papelito_Integration_Test_Wpdb {
	public string $prefix = 'wp_';
	public string $users = 'wp_users';
	public function get_row() { return null; }
}

$wpdb = new Papelito_Integration_Test_Wpdb();
$options = array();
$environment = array();
$catalog_add_payment = false;
$assertions = 0;
$failures = 0;

function integration_assert( bool $condition, string $message ): void {
	global $assertions, $failures;
	++$assertions;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$message}\n";
	}
}

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function apply_filters( string $hook, $value ) {
	global $catalog_add_payment;
	if ( 'papelito_integration_secret_catalog' === $hook && $catalog_add_payment ) {
		$value['test_payment'] = array( 'slug' => 'test_payment', 'label' => 'Pagamento de teste', 'class' => 'pagamento', 'env' => 'TEST_PAYMENT_SECRET' );
	}
	return $value;
}
function add_action() {}
function get_option( string $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function add_option( string $key, $value, string $deprecated = '', bool $autoload = true ): bool { global $options; $options[ $key ] = $value; return true; }
function update_option( string $key, $value ): bool { global $options; $options[ $key ] = $value; return true; }
function papelito_env( string $key, string $default = '' ) { global $environment; return $environment[ $key ] ?? $default; }
function papelito_pii_encrypt( string $value ): string { return 'v1:' . base64_encode( $value ); }
function papelito_pii_decrypt( string $value ): string { return base64_decode( substr( $value, 3 ), true ) ?: ''; }
function current_time(): string { return '2026-08-28 00:00:00'; }
function wp_generate_password(): string { return 'confirmation-token-for-test'; }
function wp_salt(): string { return 'test-salt'; }
function wp_json_encode( $value ): string { return json_encode( $value ); }

require_once dirname( __DIR__ ) . '/includes/integration_secrets.php';

$catalog = papelito_integration_secret_catalog();
integration_assert( isset( $catalog['ga4_measurement_id'], $catalog['ga4_api_secret'] ), 'catálogo contém as duas credenciais GA4' );
integration_assert( is_wp_error( papelito_integration_secret_catalog_item( 'papelito_pii_encryption_key' ) ), 'chave de criptografia é proibida' );
integration_assert( is_wp_error( papelito_integration_secret_catalog_item( 'graphql_jwt_auth_secret_key' ) ), 'chave JWT é proibida' );
integration_assert( 'papelito_integration_secret_unknown_slug' === papelito_integration_secret_catalog_item( 'desconhecida' )->get_error_code(), 'slug desconhecido tem erro próprio' );

$vault_value = 'vault-value-for-test';
$options[ PAPELITO_INTEGRATION_SECRETS_OPTION ] = array( 'ga4_api_secret' => papelito_pii_encrypt( $vault_value ) );
integration_assert( $vault_value === papelito_integration_secret( 'ga4_api_secret' ), 'resolvedor lê o cofre' );
integration_assert( 'vault' === papelito_integration_secret_source( 'ga4_api_secret' ), 'origem do cofre é declarada' );
integration_assert( false === str_contains( (string) $options[ PAPELITO_INTEGRATION_SECRETS_OPTION ]['ga4_api_secret'], $vault_value ), 'cofre não guarda valor cru' );

$environment['GA4_API_SECRET'] = 'environment-value-for-test';
integration_assert( 'environment-value-for-test' === papelito_integration_secret( 'ga4_api_secret' ), 'ambiente vence o cofre' );
integration_assert( 'env' === papelito_integration_secret_source( 'ga4_api_secret' ), 'origem do ambiente é declarada' );
integration_assert( 'test' === papelito_integration_secret_last4( 'environment-value-for-test' ), 'somente quatro caracteres finais são expostos' );

$catalog_add_payment = true;
$payment_value = 'payment-value-for-test';
$token = papelito_integration_secret_write_pending( 'test_payment', 'set', $payment_value, 42 );
$pending_envelope = $options[ PAPELITO_INTEGRATION_SECRETS_OPTION ][ papelito_integration_secret_pending_key( 'test_payment' ) ] ?? '';
$pending = json_decode( papelito_pii_decrypt( $pending_envelope ), true );
integration_assert( 'confirmation-token-for-test' === $token, 'mudança de pagamento gera token de confirmação' );
integration_assert( false === str_contains( $pending_envelope, $payment_value ), 'pagamento pendente não fica em claro no cofre' );
integration_assert( 'set' === ( $pending['action'] ?? '' ) && 42 === ( $pending['actor'] ?? 0 ), 'pagamento pendente preserva ação e ator' );
integration_assert( hash_equals( $pending['token_hash'] ?? '', hash_hmac( 'sha256', $token, 'test-salt' ) ), 'somente o hash assinado do token é persistido' );

echo "Assertions: {$assertions}\n";
exit( $failures > 0 ? 1 : 0 );
