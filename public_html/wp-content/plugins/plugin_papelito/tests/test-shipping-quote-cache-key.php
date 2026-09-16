<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Chave do cache de cotação dos Correios — BRASPRESS-003.
 *
 * O transient guarda uma cotação por 10 minutos, então tudo que muda o preço
 * precisa mudar a chave. O CEP de origem ficava de fora: ele é derivado do
 * vendor, e só o `vendor_id` entrava no hash, então mudar o endereço do vendor
 * continuava servindo a cotação antiga da origem anterior.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

const CACHE_KEY_TEST_VENDOR_ID   = 2303;
const CACHE_KEY_TEST_ORIGIN      = '01310930';
const CACHE_KEY_TEST_OTHER_ORIGIN = '70000000';
const CACHE_KEY_TEST_DESTINATION = '22041001';
const CACHE_KEY_TEST_SALT        = 'salt-de-teste';
const CACHE_KEY_TEST_PREFIX      = 'papelito_shipping_quote_v4_';

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function register_rest_route( mixed ...$args ): bool { return true; }
function apply_filters( mixed $hook, mixed $value ): mixed { return $value; }
function do_action( mixed ...$args ): bool { return true; }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function remove_accents( mixed $value ): string { return (string) $value; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( mixed $value ): string { return (string) json_encode( $value ); }
function wp_salt( mixed $scheme = '' ): string { return CACHE_KEY_TEST_SALT; }

require_once dirname( __DIR__ ) . '/includes/shipping.php';

$failures = 0;
function cache_key_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

$items   = array( array( 'product_id' => 11836, 'qty' => 2, 'declared_value_cents' => 7999 ) );
$package = array( 'weight' => 900.0, 'length' => 20.0, 'width' => 15.0, 'height' => 10.0, 'value' => 79.99, 'measurement_source' => 'legacy_synthetic' );

function cache_key_for( array $overrides = array() ): string {
	global $items, $package;

	return papelito_shipping_quote_cache_key(
		$overrides['vendor_id'] ?? CACHE_KEY_TEST_VENDOR_ID,
		$overrides['origin_cep'] ?? CACHE_KEY_TEST_ORIGIN,
		$overrides['destination_cep'] ?? CACHE_KEY_TEST_DESTINATION,
		$overrides['items'] ?? $items,
		$overrides['package'] ?? $package
	);
}

$base = cache_key_for();

echo "Scenario 1: a chave é deterministica e opaca\n";
cache_key_assert( 'mesma entrada, mesma chave', $base === cache_key_for() );
cache_key_assert( 'usa o prefixo versionado', str_starts_with( $base, CACHE_KEY_TEST_PREFIX ) );
cache_key_assert( 'nao carrega o CEP em claro', ! str_contains( $base, CACHE_KEY_TEST_DESTINATION ) && ! str_contains( $base, CACHE_KEY_TEST_ORIGIN ) );

echo "Scenario 2: trocar a origem do vendor invalida o cache\n";
cache_key_assert( 'origem diferente, chave diferente', $base !== cache_key_for( array( 'origin_cep' => CACHE_KEY_TEST_OTHER_ORIGIN ) ) );

echo "Scenario 3: destino, vendor, itens e pacote continuam na chave\n";
cache_key_assert( 'destino', $base !== cache_key_for( array( 'destination_cep' => '30110000' ) ) );
cache_key_assert( 'vendor', $base !== cache_key_for( array( 'vendor_id' => 9999 ) ) );
cache_key_assert( 'itens', $base !== cache_key_for( array( 'items' => array( array( 'product_id' => 11836, 'qty' => 3, 'declared_value_cents' => 11998 ) ) ) ) );
cache_key_assert( 'pacote', $base !== cache_key_for( array( 'package' => array_merge( $package, array( 'height' => 30.0 ) ) ) ) );

echo "Scenario 4: a embalagem do perfil entra pela assinatura fisica\n";
$with_hash  = array_merge( $package, array( 'physical_hash' => 'hash-caixa-p', 'measurement_source' => 'profile' ) );
$other_hash = array_merge( $package, array( 'physical_hash' => 'hash-caixa-m', 'measurement_source' => 'profile' ) );
cache_key_assert( 'pacote com perfil difere do legado', $base !== cache_key_for( array( 'package' => $with_hash ) ) );
cache_key_assert( 'physical_hash diferente, chave diferente', cache_key_for( array( 'package' => $with_hash ) ) !== cache_key_for( array( 'package' => $other_hash ) ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
