<?php
/**
 * Máquina de estados e projeção pública das tentativas de pagamento.
 *
 * O SQL (compare-and-set, unicidade do attemptRef) é coberto por `test-payment-attempts-db.php`.
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

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function add_action( mixed ...$args ): bool { return true; }
function esc_url_raw( mixed $value ): string { return (string) $value; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';

$failures = 0;

function payment_attempts_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

$can = 'papelito_payment_attempt_can_transition';

payment_attempts_assert( 'tentativa nova pode começar a criar', $can( 'not_started', 'creating' ) );
payment_attempts_assert( 'tentativa nova pode aguardar dados de pagamento', $can( 'not_started', 'awaiting_payment_input' ) );
payment_attempts_assert( 'aguardando dados só cria uma vez', $can( 'awaiting_payment_input', 'creating' ) && ! $can( 'creating', 'creating' ) );
payment_attempts_assert( 'timeout da criação vira unknown', $can( 'creating', 'unknown' ) );
payment_attempts_assert( 'unknown nunca volta a not_started', ! $can( 'unknown', 'not_started' ) );
payment_attempts_assert( 'unknown nunca volta a creating por retry', ! $can( 'unknown', 'creating' ) );
payment_attempts_assert( 'unknown só sai por reconciliação', $can( 'unknown', 'pending' ) && $can( 'unknown', 'paid' ) && $can( 'unknown', 'failed' ) );
payment_attempts_assert( 'pago não regride para não pago', ! $can( 'paid', 'failed' ) && ! $can( 'paid', 'pending' ) && ! $can( 'paid', 'expired' ) );
payment_attempts_assert( 'pago pode ser estornado', $can( 'paid', 'refunded' ) );
payment_attempts_assert( 'aprovação tardia vence expiração e recusa', $can( 'expired', 'paid' ) && $can( 'failed', 'paid' ) );
payment_attempts_assert( 'estado desconhecido não transiciona', ! $can( 'bogus', 'paid' ) && ! $can( 'pending', 'bogus' ) );
payment_attempts_assert( 'nenhum estado volta a not_started', ! $can( 'failed', 'not_started' ) && ! $can( 'pending', 'not_started' ) );

$blocks = 'papelito_payment_attempt_blocks_new_attempt';
payment_attempts_assert( 'tentativa em criação, unknown, pendente ou paga impede outra', $blocks( 'creating' ) && $blocks( 'unknown' ) && $blocks( 'pending' ) && $blocks( 'paid' ) );
payment_attempts_assert( 'falha comprovada, expiração e estorno liberam nova tentativa', ! $blocks( 'failed' ) && ! $blocks( 'expired' ) && ! $blocks( 'refunded' ) );
payment_attempts_assert( 'tentativa ainda sem emissão não impede continuar', ! $blocks( 'not_started' ) && ! $blocks( 'awaiting_payment_input' ) );

$ref_a = papelito_payment_attempt_generate_ref();
$ref_b = papelito_payment_attempt_generate_ref();
payment_attempts_assert( 'attemptRef é opaco com 32 hex', 1 === preg_match( '/^[a-f0-9]{32}$/', $ref_a ) );
payment_attempts_assert( 'attemptRef não se repete', $ref_a !== $ref_b );
payment_attempts_assert( 'attemptRef malformado é recusado', ! papelito_payment_attempt_ref_is_valid( 'abc' ) && ! papelito_payment_attempt_ref_is_valid( str_repeat( 'Z', 32 ) ) );

$row = array(
	'id'                    => 15,
	'attempt_ref'           => $ref_a,
	'order_id'              => 900,
	'provider'              => 'mercadopago',
	'environment'           => 'test',
	'account_id'            => 3,
	'account_ref'           => '777',
	'state'                 => 'pending',
	'flow'                  => 'redirect',
	'redirect_url'          => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=1',
	'amount_cents'          => 12345,
	'fallback_reason'       => '',
	'configuration_version' => 'v1',
	'remote_id'             => 'pref-1',
);
$public = papelito_payment_attempt_public( $row );
payment_attempts_assert( 'projeção pública expõe id, provider, fluxo e estado', 15 === $public['paymentAttemptId'] && 'mercadopago' === $public['provider'] && 'redirect' === $public['flow'] && 'pending' === $public['state'] );
payment_attempts_assert( 'projeção pública não expõe attemptRef nem conta', ! in_array( $ref_a, $public, true ) && ! array_key_exists( 'accountRef', $public ) && ! array_key_exists( 'attemptRef', $public ) );
payment_attempts_assert( 'redirect só aparece no fluxo redirect pendente', isset( $public['redirectUrl'] ) );

$paid_public = papelito_payment_attempt_public( array_merge( $row, array( 'state' => 'paid' ) ) );
payment_attempts_assert( 'tentativa paga não reoferece o link', ! isset( $paid_public['redirectUrl'] ) );

$validation = papelito_payment_attempt_validate_new( array( 'provider' => 'stripe', 'environment' => 'test', 'vendor_id' => 1, 'amount_cents' => 10 ) );
payment_attempts_assert( 'criação recusa provider desconhecido', is_wp_error( $validation ) );
$validation = papelito_payment_attempt_validate_new( array( 'provider' => 'mercadopago', 'environment' => 'test', 'vendor_id' => 1, 'amount_cents' => 0 ) );
payment_attempts_assert( 'criação recusa valor zero', is_wp_error( $validation ) );
$validation = papelito_payment_attempt_validate_new( array( 'provider' => 'mercadopago', 'environment' => 'test', 'vendor_id' => 1, 'amount_cents' => 100, 'state' => 'paid' ) );
payment_attempts_assert( 'criação só nasce em not_started ou awaiting_payment_input', is_wp_error( $validation ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
