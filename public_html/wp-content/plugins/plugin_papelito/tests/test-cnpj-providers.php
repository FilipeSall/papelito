<?php
/**
 * Standalone regression test for the CNPJ provider pipeline (fallback, conflict, alphanumeric,
 * cache, budget).
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
function is_wp_error( mixed $v ) { return $v instanceof WP_Error; }
function papelito_env( string $key, $default = null ) { return $default; }

/* Filter registry (only papelito_cnpj_http_response is used). */
$papelito_filters = array();
function add_filter( string $tag, callable $cb ) { global $papelito_filters; $papelito_filters[ $tag ][] = $cb; }
function apply_filters( string $tag, $value, ...$args ) {
	global $papelito_filters;
	foreach ( $papelito_filters[ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}

/* In-memory transients. */
$papelito_transients = array();
function get_transient( string $k ) { global $papelito_transients; return $papelito_transients[ $k ] ?? false; }
function set_transient( string $k, $v, $ttl ) { global $papelito_transients; $papelito_transients[ $k ] = $v; return true; }
function wp_remote_get( string $url, array $args = array() ) { return new WP_Error( 'no_network', 'network disabled in test' ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['status'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }

require __DIR__ . '/../includes/cnpj_validation.php';
require __DIR__ . '/../includes/cnpj_providers.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "  PASS: {$label}\n"; return; }
	++$failures;
	echo "  FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

/**
 * Installs a scripted HTTP responder keyed by provider host, then clears the transient cache.
 *
 * @param array<string,array<string,mixed>> $by_host host-substring => wp_remote-style response
 */
function papelito_test_script_http( array $by_host ): void {
	global $papelito_filters, $papelito_transients;
	$papelito_filters    = array();
	$papelito_transients = array();
	add_filter(
		'papelito_cnpj_http_response',
		static function ( $pre, $url, $args ) use ( $by_host ) {
			foreach ( $by_host as $needle => $resp ) {
				if ( false !== strpos( $url, $needle ) ) {
					return $resp;
				}
			}
			return new WP_Error( 'no_match', 'no scripted response' );
		}
	);
}

function papelito_resp( int $status, array $json ): array {
	return array( 'status' => $status, 'body' => json_encode( $json ) );
}

$numeric = '11222333000181';           // válido numérico
$alpha   = '12ABC34501DE35';           // válido alfanumérico (exemplo oficial)

/* 1. BrasilAPI active → early success, source brasilapi. */
papelito_test_script_http( array(
	'brasilapi' => papelito_resp( 200, array( 'cnpj' => $numeric, 'descricao_situacao_cadastral' => 'ATIVA', 'razao_social' => 'ACME LTDA' ) ),
) );
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'brasilapi active → active', 'active', $r['status'] );
papelito_assert_same( 'active source is brasilapi', 'brasilapi', $r['source'] );

/* 2. BrasilAPI unavailable (500) → fallback to CNPJ.ws active. */
papelito_test_script_http( array(
	'brasilapi'      => papelito_resp( 500, array() ),
	'publica.cnpj.ws' => papelito_resp( 200, array( 'razao_social' => 'ACME', 'estabelecimento' => array( 'situacao_cadastral' => 'Ativa' ) ) ),
) );
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'fallback to cnpjws → active', 'active', $r['status'] );
papelito_assert_same( 'fallback source cnpjws', 'cnpjws', $r['source'] );

/* 3. All not_found → not_found. */
papelito_test_script_http( array(
	'brasilapi'       => papelito_resp( 404, array() ),
	'publica.cnpj.ws' => papelito_resp( 404, array() ),
	'receitaws'       => papelito_resp( 404, array() ),
) );
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'all 404 → not_found', 'not_found', $r['status'] );

/* 4. All unavailable → unavailable. */
papelito_test_script_http( array(
	'brasilapi'       => papelito_resp( 503, array() ),
	'publica.cnpj.ws' => papelito_resp( 429, array() ),
	'receitaws'       => papelito_resp( 500, array() ),
) );
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'all 5xx/429 → unavailable', 'unavailable', $r['status'] );

/* 5. Conflict: brasilapi inactive, cnpjws active → conflict (blocks). */
papelito_test_script_http( array(
	'brasilapi'       => papelito_resp( 200, array( 'descricao_situacao_cadastral' => 'BAIXADA' ) ),
	'publica.cnpj.ws' => papelito_resp( 200, array( 'estabelecimento' => array( 'situacao_cadastral' => 'Ativa' ) ) ),
) );
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'active vs inactive → conflict', 'conflict', $r['status'] );

/* 6. Alphanumeric CNPJ + providers without support → provider_unsupported (never invalid). */
papelito_test_script_http( array() ); // no provider should be called
$r = papelito_cnpj_lookup( $alpha );
papelito_assert_same( 'alpha + unsupported providers → provider_unsupported', 'provider_unsupported', $r['status'] );

/* 7. Structurally invalid CNPJ → invalid, no provider call. */
papelito_test_script_http( array() );
$r = papelito_cnpj_lookup( '11222333000180' );
papelito_assert_same( 'invalid CNPJ → invalid', 'invalid', $r['status'] );

/* 8. Cache: second lookup returns from cache without new HTTP. */
papelito_test_script_http( array(
	'brasilapi' => papelito_resp( 200, array( 'descricao_situacao_cadastral' => 'ATIVA' ) ),
) );
$first = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'first not from cache', true, empty( $first['from_cache'] ) );
global $papelito_filters;
$papelito_filters = array(); // remove HTTP; only cache can answer now
$second = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'second served from cache', true, ! empty( $second['from_cache'] ) );
papelito_assert_same( 'cached status preserved', 'active', $second['status'] );

/* 9. Cache never stores raw QSA. */
papelito_test_script_http( array(
	'brasilapi' => papelito_resp( 200, array( 'descricao_situacao_cadastral' => 'ATIVA', 'qsa' => array( array( 'nome_socio' => 'FULANO' ) ) ) ),
) );
papelito_cnpj_lookup( $numeric );
global $papelito_transients;
$cached_entry = $papelito_transients[ PAPELITO_CNPJ_CACHE_PREFIX . $numeric ] ?? array();
papelito_assert_same( 'raw QSA not cached', true, ! isset( $cached_entry['qsa'] ) );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
