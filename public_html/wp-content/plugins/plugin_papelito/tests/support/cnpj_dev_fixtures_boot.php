<?php
/**
 * Bootstrap isolado do modulo de fixtures de CNPJ.
 *
 * O `add_filter` do rodape de includes/cnpj_dev_fixtures.php roda UMA vez, no require. Para testar
 * "o filtro nao e registrado fora de local/development" e preciso carregar o arquivo de novo com
 * outro ambiente — o que so e possivel em outro processo. Este script recebe o ambiente e a flag
 * por argv, carrega o modulo e imprime o resultado em JSON para o teste pai.
 *
 * Uso: php tests/support/cnpj_dev_fixtures_boot.php <environment> <true|false>
 *
 * Nao tem o prefixo `test-` de proposito: nao e um teste, e um alvo de subprocesso. O estilo dos
 * stubs segue o das demais harnesses standalone em tests/ (ver test-cnpj-providers.php).
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$papelito_boot_environment = isset( $argv[1] ) ? (string) $argv[1] : 'production';
$papelito_boot_flag_present = isset( $argv[2] ) && 'missing' !== $argv[2];
$papelito_boot_flag         = $papelito_boot_flag_present && 'true' === ( $argv[2] ?? '' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
function is_wp_error( mixed $v ) { return $v instanceof WP_Error; }

function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' ); }
function wp_get_environment_type(): string { global $papelito_boot_environment; return $papelito_boot_environment; }
function papelito_env( string $key, $default = null ) { return $default; }
function papelito_env_bool( string $key, bool $default = false ): bool {
	global $papelito_boot_flag, $papelito_boot_flag_present;
	if ( 'PAPELITO_CNPJ_DEV_FIXTURES_ENABLED' === $key && ! $papelito_boot_flag_present ) {
		return $default;
	}
	return 'PAPELITO_CNPJ_DEV_FIXTURES_ENABLED' === $key ? $papelito_boot_flag : $default;
}

$papelito_filters = array();
function add_filter( string $tag, callable $cb, int $priority = 10, int $accepted = 1 ) { global $papelito_filters; $papelito_filters[ $tag ][] = $cb; }
function apply_filters( string $tag, $value, ...$args ) {
	global $papelito_filters;
	foreach ( $papelito_filters[ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}

function get_transient( string $k ) { return false; }
function set_transient( string $k, $v, $ttl ) { return true; }
function wp_remote_get( string $url, array $args = array() ) { return new WP_Error( 'no_network', 'network disabled' ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- esta funcao E o stub de wp_parse_url.

require __DIR__ . '/../../includes/cnpj_validation.php';
require __DIR__ . '/../../includes/cnpj_providers.php';
require __DIR__ . '/../../includes/cnpj_dev_fixtures.php';

echo wp_json_encode(
	array(
		'environment'   => $papelito_boot_environment,
		'flag'          => $papelito_boot_flag_present ? $papelito_boot_flag : null,
		'enabled'       => papelito_cnpj_dev_fixtures_enabled(),
		'registered'    => ! empty( $papelito_filters['papelito_cnpj_http_response'] ),
		'cache_filter'  => ! empty( $papelito_filters['papelito_cnpj_cache_key'] ),
		'fixture_count' => count( papelito_cnpj_dev_fixtures() ),
	)
);
