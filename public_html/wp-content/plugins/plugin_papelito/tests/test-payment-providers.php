<?php
/**
 * Contrato comum de gateways: coorte, modo financeiro do vendor e provider histórico do pedido.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_PAGARME_ORDER_ID_META', '_papelito_pagarme_order_id' );
define( 'PAPELITO_PAGARME_CHARGE_ID_META', '_papelito_pagarme_charge_id' );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

class PaymentProvidersTestOrder {
	public array $meta = array();

	public function get_meta( string $key, bool $single = true ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}
}

$GLOBALS['payment_test_env']       = array();
$GLOBALS['payment_test_user_meta'] = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-16 12:00:00'; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['payment_test_env'][ $key ] ?? $default; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed {
	return $GLOBALS['payment_test_user_meta'][ $user_id ][ $key ] ?? '';
}
function add_user_meta( int $user_id, string $key, mixed $value, bool $unique = false ): bool {
	if ( $unique && isset( $GLOBALS['payment_test_user_meta'][ $user_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['payment_test_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';

const PAYMENT_TEST_VENDOR    = 41;
const PAYMENT_TEST_OUTSIDER  = 42;
const PAYMENT_TEST_FLAG      = 'PAPELITO_PAYMENT_GATEWAYS_ENABLED';
const PAYMENT_TEST_ALLOWLIST = 'PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS';

$failures = 0;

function payment_providers_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

payment_providers_assert( 'providers conhecidos são os dois gateways', array( 'pagarme', 'mercadopago' ) === papelito_payment_providers() );
payment_providers_assert( 'provider desconhecido não é válido', ! papelito_payment_provider_is_valid( 'stripe' ) );

payment_providers_assert( 'flag nasce desligada', ! papelito_payment_gateways_enabled() );
payment_providers_assert( 'sem flag e sem marcador o vendor fica no legado', 'legacy' === papelito_vendor_payment_mode( PAYMENT_TEST_VENDOR ) );
payment_providers_assert( 'avaliação legada não grava marcador', ! papelito_vendor_payment_policy_enforced( PAYMENT_TEST_VENDOR ) );

$GLOBALS['payment_test_env'][ PAYMENT_TEST_FLAG ]      = 'true';
$GLOBALS['payment_test_env'][ PAYMENT_TEST_ALLOWLIST ] = '41, 7 ,abc';
payment_providers_assert( 'allowlist ignora lixo e espaços', array( 41, 7 ) === papelito_payment_gateways_cohort_ids() );
payment_providers_assert( 'flag ligada sem coorte mantém outsider no legado', 'legacy' === papelito_vendor_payment_mode( PAYMENT_TEST_OUTSIDER ) );
payment_providers_assert( 'vendor da coorte entra no fluxo novo', 'gateways' === papelito_vendor_payment_mode( PAYMENT_TEST_VENDOR ) );
payment_providers_assert( 'primeiro ingresso grava marcador persistente', papelito_vendor_payment_policy_enforced( PAYMENT_TEST_VENDOR ) );

$GLOBALS['payment_test_env'][ PAYMENT_TEST_ALLOWLIST ] = '7';
payment_providers_assert( 'sair da coorte suspende em vez de voltar ao legado', 'suspended' === papelito_vendor_payment_mode( PAYMENT_TEST_VENDOR ) );

$GLOBALS['payment_test_env'][ PAYMENT_TEST_FLAG ] = 'false';
payment_providers_assert( 'flag desligada suspende vendor marcado', 'suspended' === papelito_vendor_payment_mode( PAYMENT_TEST_VENDOR ) );
payment_providers_assert( 'toggle não limpa o marcador', papelito_vendor_payment_policy_enforced( PAYMENT_TEST_VENDOR ) );

$GLOBALS['payment_test_env'][ PAYMENT_TEST_FLAG ]      = 'true';
$GLOBALS['payment_test_env'][ PAYMENT_TEST_ALLOWLIST ] = '';
payment_providers_assert( 'flag ligada com allowlist vazia não habilita ninguém', 'legacy' === papelito_vendor_payment_mode( PAYMENT_TEST_OUTSIDER ) );
payment_providers_assert( 'consulta sem gravação não marca outsider', 'legacy' === papelito_vendor_payment_mode( PAYMENT_TEST_OUTSIDER, false ) && ! papelito_vendor_payment_policy_enforced( PAYMENT_TEST_OUTSIDER ) );

$legacy = new PaymentProvidersTestOrder();
$legacy->meta[ PAPELITO_PAGARME_ORDER_ID_META ] = 'or_legacy';
payment_providers_assert( 'pedido antigo com ID Pagar.me é lido como Pagar.me', 'pagarme' === papelito_payment_provider_for_order( $legacy ) );

$charge_only = new PaymentProvidersTestOrder();
$charge_only->meta[ PAPELITO_PAGARME_CHARGE_ID_META ] = 'ch_legacy';
payment_providers_assert( 'cobrança Pagar.me sem ID de pedido ainda é evidência', 'pagarme' === papelito_payment_provider_for_order( $charge_only ) );

$unknown = papelito_payment_provider_for_order( new PaymentProvidersTestOrder() );
payment_providers_assert( 'sem evidência o provider falha explicitamente', is_wp_error( $unknown ) && 'papelito_payment_provider_unknown' === $unknown->get_error_code() );

$forged = new PaymentProvidersTestOrder();
$forged->meta[ PAPELITO_PAYMENT_PROVIDER_META ] = 'stripe';
payment_providers_assert( 'provider gravado inválido não vira Pagar.me', is_wp_error( papelito_payment_provider_for_order( $forged ) ) );

$order = new PaymentProvidersTestOrder();
papelito_payment_store_order_snapshot(
	$order,
	array(
		'provider'    => 'mercadopago',
		'environment' => 'test',
		'account_id'  => 9,
		'account_ref' => '123456',
		'attempt_id'  => 15,
	)
);
$GLOBALS['payment_test_user_meta'][ PAYMENT_TEST_VENDOR ]['papelito_vendor_preferred_payment_provider'] = 'pagarme';
$snapshot = papelito_payment_order_snapshot( $order );
payment_providers_assert( 'provider do pedido é o da tentativa, não a preferência atual', 'mercadopago' === papelito_payment_provider_for_order( $order ) );
payment_providers_assert( 'snapshot preserva ambiente e conta histórica', 'test' === $snapshot['environment'] && 9 === $snapshot['account_id'] && '123456' === $snapshot['account_ref'] && 15 === $snapshot['attempt_id'] );

$rejected = papelito_payment_store_order_snapshot( new PaymentProvidersTestOrder(), array( 'provider' => 'stripe', 'environment' => 'test' ) );
payment_providers_assert( 'snapshot recusa provider desconhecido', is_wp_error( $rejected ) );
$bad_env = papelito_payment_store_order_snapshot( new PaymentProvidersTestOrder(), array( 'provider' => 'pagarme', 'environment' => 'sandbox' ) );
payment_providers_assert( 'snapshot recusa ambiente fora de test/live', is_wp_error( $bad_env ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
