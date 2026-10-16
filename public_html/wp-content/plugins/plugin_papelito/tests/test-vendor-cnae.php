<?php
/**
 * Evidência CNAE do vendor obtida pela BrasilAPI.
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
}

$GLOBALS['cnae_test_meta']       = array();
$GLOBALS['cnae_test_transients'] = array();
$GLOBALS['cnae_test_http']       = array();
$GLOBALS['cnae_test_http_calls'] = 0;
$GLOBALS['cnae_test_log_file']   = tempnam( sys_get_temp_dir(), 'cnae' );
ini_set( 'error_log', $GLOBALS['cnae_test_log_file'] );
ini_set( 'display_errors', 'stderr' );

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function wp_salt( string $scheme = 'auth' ): string { return 'salt-' . $scheme; }
function papelito_env( string $key, mixed $default = null ): mixed { return $default; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['cnae_test_meta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( int $user_id, string $key, mixed $value ): bool { $GLOBALS['cnae_test_meta'][ $user_id ][ $key ] = $value; return true; }
function delete_user_meta( int $user_id, string $key ): bool { unset( $GLOBALS['cnae_test_meta'][ $user_id ][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['cnae_test_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['cnae_test_transients'][ $key ] = $value; return true; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_cnpj_http_response' === $hook ) {
		++$GLOBALS['cnae_test_http_calls'];
		return $GLOBALS['cnae_test_http'];
	}
	return $value;
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }
function papelito_normalize_cnpj( string $raw ): string { return preg_replace( '/\D+/', '', $raw ); }
function papelito_validate_cnpj( string $cnpj ): bool { return 14 === strlen( $cnpj ); }
function papelito_cnpj_is_alphanumeric( string $cnpj ): bool { return false; }

require_once dirname( __DIR__ ) . '/includes/cnpj_providers.php';
require_once dirname( __DIR__ ) . '/includes/vendor_cnae.php';

const CNAE_TEST_VENDOR     = 77;
const CNAE_TEST_CNPJ       = '11222333000181';
const CNAE_TEST_OTHER_CNPJ = '44555666000199';
const CNAE_TEST_NOW        = 1792000000;
const CNAE_TEST_TOBACCO    = '4729601';

$failures = 0;

function cnae_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function cnae_body( mixed $primary, mixed $secondary ): array {
	$body = array(
		'cnpj'                         => CNAE_TEST_CNPJ,
		'razao_social'                 => 'TABACARIA TESTE LTDA',
		'descricao_situacao_cadastral' => 'ATIVA',
		'qsa'                          => array( array( 'nome_socio' => 'SOCIO', 'cnpj_cpf_do_socio' => '***123***' ) ),
	);
	if ( null !== $primary ) {
		$body['cnae_fiscal'] = $primary;
	}
	if ( 'absent' !== $secondary ) {
		$body['cnaes_secundarios'] = $secondary;
	}
	return $body;
}

function cnae_http( int $code, ?array $body ): void {
	$GLOBALS['cnae_test_transients'] = array();
	$GLOBALS['cnae_test_http'] = array( 'code' => $code, 'body' => null === $body ? '' : json_encode( $body ) );
}

cnae_assert( 'código numérico vira sete dígitos', '4729601' === papelito_vendor_cnae_normalize_code( 4729601 ) );
cnae_assert( 'código formatado vira sete dígitos', '4636202' === papelito_vendor_cnae_normalize_code( '46.36-2-02' ) );
cnae_assert( 'código com seis dígitos recupera zero à esquerda', '0111301' === papelito_vendor_cnae_normalize_code( 111301 ) );
cnae_assert( 'zero é placeholder, não código', '' === papelito_vendor_cnae_normalize_code( 0 ) && '' === papelito_vendor_cnae_normalize_code( '0000000' ) );
cnae_assert( 'código malformado é recusado', null === papelito_vendor_cnae_normalize_code( 'abc' ) && null === papelito_vendor_cnae_normalize_code( '12345678' ) );

$mapped = papelito_cnpj_map_brasilapi( cnae_body( 4729601, array( array( 'codigo' => 4636202, 'descricao' => 'x' ) ) ) );
cnae_assert( 'mapper preserva principal', CNAE_TEST_TOBACCO === ( $mapped['cnaes']['primary'] ?? null ) );
cnae_assert( 'mapper preserva secundários', array( '4636202' ) === ( $mapped['cnaes']['secondary'] ?? null ) );
cnae_assert( 'mapper marca lista completa', true === ( $mapped['cnaes']['complete'] ?? null ) );
cnae_assert( 'mapper continua devolvendo dados cadastrais', 'TABACARIA TESTE LTDA' === $mapped['legal_name'] && 'active' === $mapped['status'] );

$placeholder = papelito_cnpj_map_brasilapi( cnae_body( 4761003, array( array( 'codigo' => 0, 'descricao' => '' ) ) ) );
cnae_assert( 'secundário placeholder da BrasilAPI é lista vazia válida', array() === $placeholder['cnaes']['secondary'] && $placeholder['cnaes']['complete'] );

$explicit_empty = papelito_cnpj_map_brasilapi( cnae_body( 4761003, array() ) );
cnae_assert( 'lista secundária vazia explícita é válida', $explicit_empty['cnaes']['complete'] );

$absent = papelito_cnpj_map_brasilapi( cnae_body( 4761003, 'absent' ) );
cnae_assert( 'lista secundária ausente fica incompleta', false === $absent['cnaes']['complete'] );

$malformed = papelito_cnpj_map_brasilapi( cnae_body( 4761003, array( array( 'codigo' => 'xx' ), array( 'codigo' => 4729601 ) ) ) );
cnae_assert( 'código secundário malformado deixa incompleto', false === $malformed['cnaes']['complete'] );

$no_primary = papelito_cnpj_map_brasilapi( cnae_body( 0, array() ) );
cnae_assert( 'principal zero deixa incompleto', false === $no_primary['cnaes']['complete'] );

$GLOBALS['cnae_test_meta'][ CNAE_TEST_VENDOR ]['cnpj'] = '11.222.333/0001-81';

cnae_http( 200, cnae_body( 4761003, array( array( 'codigo' => 4729601 ) ) ) );
$evidence = papelito_vendor_cnae_refresh( CNAE_TEST_VENDOR, CNAE_TEST_NOW );
cnae_assert( 'consulta válida produz evidência verificada', 'verified' === $evidence['status'] );
cnae_assert( 'evidência guarda secundário bloqueável', in_array( CNAE_TEST_TOBACCO, $evidence['secondary'], true ) );
cnae_assert( 'evidência é da BrasilAPI', 'brasilapi' === $evidence['source'] );
cnae_assert( 'validade positiva é de sete dias', CNAE_TEST_NOW + 7 * DAY_IN_SECONDS === $evidence['valid_until'] );
cnae_assert( 'evidência não carrega QSA nem CNPJ em claro', ! isset( $evidence['qsa'] ) && false === strpos( json_encode( $evidence ), CNAE_TEST_CNPJ ) );
cnae_assert( 'evidência persiste em usermeta', is_array( get_user_meta( CNAE_TEST_VENDOR, PAPELITO_VENDOR_CNAE_EVIDENCE_META, true ) ) );

$current = papelito_vendor_cnae_current( CNAE_TEST_VENDOR, CNAE_TEST_NOW + DAY_IN_SECONDS );
cnae_assert( 'evidência dentro da validade continua verificada', 'verified' === $current['status'] );
$expired = papelito_vendor_cnae_current( CNAE_TEST_VENDOR, CNAE_TEST_NOW + 8 * DAY_IN_SECONDS );
cnae_assert( 'evidência vencida não é verificada', 'expired' === $expired['status'] );
cnae_assert( 'evidência vencida preserva códigos para manter bloqueio', in_array( CNAE_TEST_TOBACCO, $expired['secondary'], true ) );

$calls_before = $GLOBALS['cnae_test_http_calls'];
cnae_http( 503, null );
$after_failure = papelito_vendor_cnae_refresh( CNAE_TEST_VENDOR, CNAE_TEST_NOW + 8 * DAY_IN_SECONDS );
cnae_assert( 'falha de renovação consulta a BrasilAPI uma vez', $GLOBALS['cnae_test_http_calls'] === $calls_before + 1 );
cnae_assert( 'falha de renovação não apaga evidência de bloqueio', in_array( CNAE_TEST_TOBACCO, $after_failure['secondary'], true ) );
cnae_assert( 'falha de renovação não promove a verificada', 'expired' === papelito_vendor_cnae_current( CNAE_TEST_VENDOR, CNAE_TEST_NOW + 8 * DAY_IN_SECONDS )['status'] );

$calls_before = $GLOBALS['cnae_test_http_calls'];
papelito_vendor_cnae_refresh( CNAE_TEST_VENDOR, CNAE_TEST_NOW + 8 * DAY_IN_SECONDS + 60 );
cnae_assert( 'indisponibilidade recente segura nova consulta por dez minutos', $GLOBALS['cnae_test_http_calls'] === $calls_before );
papelito_vendor_cnae_refresh( CNAE_TEST_VENDOR, CNAE_TEST_NOW + 8 * DAY_IN_SECONDS + 11 * MINUTE_IN_SECONDS );
cnae_assert( 'depois de dez minutos a consulta volta', $GLOBALS['cnae_test_http_calls'] === $calls_before + 1 );

$GLOBALS['cnae_test_meta'][ CNAE_TEST_VENDOR ]['cnpj'] = CNAE_TEST_OTHER_CNPJ;
$changed = papelito_vendor_cnae_current( CNAE_TEST_VENDOR, CNAE_TEST_NOW + DAY_IN_SECONDS );
cnae_assert( 'trocar CNPJ não reutiliza evidência do documento anterior', 'pending' === $changed['status'] && 'cnpj_changed' === $changed['reason'] );

$GLOBALS['cnae_test_meta'][ 78 ]['cnpj'] = CNAE_TEST_CNPJ;
cnae_http( 200, cnae_body( 4761003, 'absent' ) );
$incomplete = papelito_vendor_cnae_refresh( 78, CNAE_TEST_NOW );
cnae_assert( 'resposta incompleta fica pendente', 'pending' === $incomplete['status'] );

$GLOBALS['cnae_test_meta'][ 79 ]['cnpj'] = CNAE_TEST_CNPJ;
cnae_http( 404, null );
cnae_assert( '404 não equivale a empresa permitida', 'verified' !== papelito_vendor_cnae_refresh( 79, CNAE_TEST_NOW )['status'] );
cnae_http( 429, null );
cnae_assert( '429 não equivale a empresa permitida', 'verified' !== papelito_vendor_cnae_refresh( 80, CNAE_TEST_NOW )['status'] );

$GLOBALS['cnae_test_meta'][ 81 ]['cnpj'] = CNAE_TEST_CNPJ;
$GLOBALS['cnae_test_meta'][ 83 ]['cnpj'] = CNAE_TEST_CNPJ;
cnae_http( 200, cnae_body( 4761003, array() ) );
$GLOBALS['cnae_test_transients'][ PAPELITO_CNPJ_CACHE_PREFIX . CNAE_TEST_CNPJ ] = array( 'status' => 'active', 'source' => 'brasilapi' );
$calls_before = $GLOBALS['cnae_test_http_calls'];
$renewed      = papelito_vendor_cnae_refresh( 81, CNAE_TEST_NOW );
$calls_before = $GLOBALS['cnae_test_http_calls'];
papelito_vendor_cnae_refresh( 83, CNAE_TEST_NOW );
cnae_assert( 'cache legado sem CNAEs é renovado, não promovido', 'verified' === $renewed['status'] );
cnae_assert( 'resposta BrasilAPI completa em cache é reaproveitada', $GLOBALS['cnae_test_http_calls'] === $calls_before );

$GLOBALS['cnae_test_meta'][ 82 ]['cnpj'] = CNAE_TEST_CNPJ;
cnae_http( 503, null );
$GLOBALS['cnae_test_transients'][ PAPELITO_CNPJ_CACHE_PREFIX . CNAE_TEST_CNPJ ] = array(
	'status' => 'active',
	'source' => 'cnpjws',
);
cnae_assert( 'resultado cadastral de outro provider não prova CNAE', 'verified' !== papelito_vendor_cnae_refresh( 82, CNAE_TEST_NOW )['status'] );

cnae_assert( 'nenhum log contém CNPJ completo', false === strpos( (string) file_get_contents( $GLOBALS['cnae_test_log_file'] ), CNAE_TEST_CNPJ ) );
unlink( $GLOBALS['cnae_test_log_file'] );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
