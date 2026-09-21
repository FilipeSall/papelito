<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * A sonda da meia-abertura só é gasta por quem vai mesmo falar com a Braspress.
 *
 * O disjuntor libera **uma** tentativa por descanso. Se um gate de elegibilidade
 * consumir essa tentativa e desistir antes da chamada, nenhum desfecho é
 * reportado, a sonda não é devolvida e o provider — já recuperado — fica fora do
 * checkout por mais um descanso inteiro. Carrinho sem embalagem aprovada é
 * comum, então a recuperação ficava refém de qual carrinho chegasse primeiro.
 *
 * Usage: php tests/test-shipping-breaker-probe-order.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PAPELITO_BRASPRESS_ENABLED', true );
define( 'PAPELITO_BRASPRESS_VENDOR_ALLOWLIST', '2163' );

const PROBE_ORDER_TEST_VENDOR_ID   = 2163;
const PROBE_ORDER_TEST_CEP         = '22041001';
const PROBE_ORDER_TEST_CNPJ        = '20024291000165';
const PROBE_ORDER_TEST_START       = 1789000000;
const PROBE_ORDER_TEST_APPROVAL    = 'v3';
const PROBE_ORDER_TEST_HASH        = 'hash-do-pacote-do-teste';

$GLOBALS['probe_test_options']     = array();
$GLOBALS['probe_test_integration'] = null;
$GLOBALS['probe_test_package']     = null;
$GLOBALS['probe_test_quote_calls'] = 0;

/** Erro do core reduzido ao que o módulo lê. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Nenhum listener é registrado neste teste. */
function add_action( mixed ...$args ): bool { return true; }
/** Nenhum listener é executado neste teste. */
function do_action( mixed ...$args ): bool { return true; }
/** Nenhum filtro é registrado neste teste. */
function add_filter( mixed ...$args ): bool { return true; }
/** O único filtro relevante é o da embalagem física, controlado pela fixture. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed {
	return 'papelito_braspress_physical_package' === (string) $hook ? $GLOBALS['probe_test_package'] : $value;
}
/** Serialização usada pelo módulo. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Leitura de option do armazenamento em memória. */
function get_option( mixed $option, mixed $default_value = false ): mixed {
	return array_key_exists( (string) $option, $GLOBALS['probe_test_options'] ) ? $GLOBALS['probe_test_options'][ (string) $option ] : $default_value;
}
/** Gravação de option no armazenamento em memória. */
function update_option( mixed $option, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['probe_test_options'][ (string) $option ] = $value;

	return true;
}
/** Remoção de option do armazenamento em memória. */
function delete_option( mixed $option ): bool {
	unset( $GLOBALS['probe_test_options'][ (string) $option ] );

	return true;
}
/** Criação exclusiva, como o `add_option` do WordPress. */
function add_option( mixed $option, mixed $value = '', mixed $deprecated = '', mixed $autoload = null ): bool {
	if ( array_key_exists( (string) $option, $GLOBALS['probe_test_options'] ) ) {
		return false;
	}

	$GLOBALS['probe_test_options'][ (string) $option ] = $value;

	return true;
}
/** Integração resolvida do vendor, controlada pela fixture. */
function papelito_vendor_integration_resolve_braspress( int $vendor_id ) { return $GLOBALS['probe_test_integration']; }
/** Normalização de documento usada pelo gate de destinatário. */
function papelito_vendor_integration_normalize_document( mixed $value ): string {
	$digits = preg_replace( '/\D+/', '', (string) $value );

	return is_string( $digits ) ? $digits : '';
}
/** Publica a origem da medida sem alterar o pacote, como o módulo real. */
function papelito_shipping_notify_package_built( mixed $package ): mixed { return $package; }
/** O pacote da fixture é sempre válido quando existe. */
function papelito_braspress_package_is_valid( array $package ): bool { return true; }
/** Conta quantas vezes a Braspress foi realmente chamada. */
function papelito_braspress_quote( array $integration, string $recipient_cnpj, string $destination_cep, array $package, int $merchandise_value_cents ) {
	++$GLOBALS['probe_test_quote_calls'];

	return array(
		'service'           => 'Braspress',
		'code'              => 'rodoviario',
		'name'              => 'Braspress',
		'price'             => 99.0,
		'delivery_time'     => 3,
		'external_quote_id' => 'q-1',
		'expires_at'        => '2999-01-01T00:00:00+00:00',
		'physical_hash'     => PROBE_ORDER_TEST_HASH,
	);
}

require_once dirname( __DIR__ ) . '/includes/shipping_breaker.php';
require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function probe_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Deixa o disjuntor do vendor em meia-abertura, com a sonda ainda disponível. */
function probe_half_open(): void {
	$GLOBALS['probe_test_options']     = array();
	$GLOBALS['probe_test_quote_calls'] = 0;
	for ( $i = 0; $i < PAPELITO_SHIPPING_BREAKER_THRESHOLD; $i++ ) {
		papelito_shipping_breaker_record( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, PROBE_ORDER_TEST_VENDOR_ID, 'timeout', PROBE_ORDER_TEST_START );
	}
}

/** Se a sonda da meia-abertura está tomada neste momento. */
function probe_is_taken(): bool {
	return array_key_exists(
		papelito_shipping_breaker_probe_key( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, PROBE_ORDER_TEST_VENDOR_ID ),
		$GLOBALS['probe_test_options']
	);
}

/** Integração resolvida e completa, boa para cotar. */
function probe_integration(): array {
	return array(
		'id'                    => 7,
		'vendor_id'             => PROBE_ORDER_TEST_VENDOR_ID,
		'provider'              => PAPELITO_SHIPPING_PROVIDER_BRASPRESS,
		'config'                => array( 'origin_cep' => '14711142' ),
		'configuration_version' => 3,
		'credentials'           => array( 'username' => 'u', 'password' => 'p' ),
	);
}

/** Pacote físico aprovado, bom para cotar. */
function probe_package(): array {
	return array( 'approval_version' => PROBE_ORDER_TEST_APPROVAL, 'physical_hash' => PROBE_ORDER_TEST_HASH );
}

/** Contexto autoritativo completo do destinatário. */
function probe_context(): array {
	return array( 'recipient_cnpj' => PROBE_ORDER_TEST_CNPJ, 'merchandise_value_cents' => 12000 );
}

/** Executa a cotação Braspress com a meia-abertura vigente. */
function probe_quote( array $context ): mixed {
	return papelito_shipping_quote_braspress( PROBE_ORDER_TEST_VENDOR_ID, PROBE_ORDER_TEST_CEP, array(), $context );
}

echo "Cenário 1: integração indisponível não gasta a sonda\n";
probe_half_open();
$GLOBALS['probe_test_integration'] = null;
$GLOBALS['probe_test_package']     = probe_package();

$result = probe_quote( probe_context() );

probe_assert( 'A cotação é pulada', null === $result );
probe_assert( 'A Braspress não foi chamada', 0 === $GLOBALS['probe_test_quote_calls'] );
probe_assert( 'A sonda continua disponível', ! probe_is_taken() );

echo "\nCenário 2: embalagem não aprovada não gasta a sonda\n";
probe_half_open();
$GLOBALS['probe_test_integration'] = probe_integration();
$GLOBALS['probe_test_package']     = null;

$result = probe_quote( probe_context() );

probe_assert( 'A cotação é pulada', null === $result );
probe_assert( 'A Braspress não foi chamada', 0 === $GLOBALS['probe_test_quote_calls'] );
probe_assert( 'A sonda continua disponível', ! probe_is_taken() );

echo "\nCenário 3: destinatário incompleto não gasta a sonda\n";
probe_half_open();
$GLOBALS['probe_test_integration'] = probe_integration();
$GLOBALS['probe_test_package']     = probe_package();

$result = probe_quote( array() );

probe_assert( 'A cotação é pulada', null === $result );
probe_assert( 'A Braspress não foi chamada', 0 === $GLOBALS['probe_test_quote_calls'] );
probe_assert( 'A sonda continua disponível', ! probe_is_taken() );

echo "\nCenário 4: a tentativa que chega na Braspress gasta a sonda, e só ela\n";
probe_half_open();
$GLOBALS['probe_test_integration'] = probe_integration();
$GLOBALS['probe_test_package']     = probe_package();

$result = probe_quote( probe_context() );

probe_assert( 'A cotação acontece', is_array( $result ) );
probe_assert( 'A Braspress foi chamada uma vez', 1 === $GLOBALS['probe_test_quote_calls'] );
probe_assert( 'A sonda foi tomada por quem cotou', probe_is_taken() );

$segunda = probe_quote( probe_context() );

probe_assert( 'A segunda tentativa no mesmo descanso é barrada', null === $segunda );
probe_assert( 'E não alcança a Braspress', 1 === $GLOBALS['probe_test_quote_calls'] );

echo "\nCenário 5: disjuntor aberto continua barrando antes de qualquer trabalho\n";
$GLOBALS['probe_test_options']     = array();
$GLOBALS['probe_test_quote_calls'] = 0;
$GLOBALS['probe_test_integration'] = probe_integration();
$GLOBALS['probe_test_package']     = probe_package();
for ( $i = 0; $i < PAPELITO_SHIPPING_BREAKER_THRESHOLD; $i++ ) {
	papelito_shipping_breaker_record( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, PROBE_ORDER_TEST_VENDOR_ID, 'timeout', time() );
}

$result = probe_quote( probe_context() );

probe_assert( 'A cotação é pulada com o disjuntor aberto', null === $result );
probe_assert( 'A Braspress não foi chamada', 0 === $GLOBALS['probe_test_quote_calls'] );
probe_assert( 'Nenhuma sonda é criada enquanto está aberto', ! probe_is_taken() );

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
