<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.
/**
 * Dependência dos Correios em relação à Braspress configurada, por vendor.
 *
 * Usage: php tests/test-vendor-carriers.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_DIGITS_REGEX', '/\\D+/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_SHIPPING_PROVIDER_BRASPRESS', 'braspress' );

const CARRIERS_TEST_VENDOR       = 41;
const CARRIERS_TEST_OTHER_VENDOR = 42;
const CARRIERS_TEST_CNPJ         = '20.024.291/0001-65';
const CARRIERS_TEST_CEP          = '14711-142';
const CARRIERS_TEST_META         = 'papelito_correios_disabled';
const CARRIERS_TEST_LOCKED_CODE  = 'papelito_vendor_correios_locked';
const PAPELITO_AUTH_RATE_LIMIT_MESSAGE = 'Muitas tentativas. Tente novamente em alguns instantes.';

class WP_Error {
	private string $code;
	private mixed $data;

	public function __construct( string $code = '', string $message = '', mixed $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

class WP_REST_Request {}
class WP_REST_Response {}

/** Banco falso da tabela de integrações, indexado por vendor. */
class Carriers_Test_Wpdb {
	public string $prefix = 'wp_';

	public function prepare( string $query, mixed ...$args ): array {
		return $args;
	}

	public function get_row( mixed $prepared, mixed $output = null ): ?array {
		return $GLOBALS['carriers_rows'][ (int) $prepared[0] ] ?? null;
	}

	public function update( string $table, array $data, array $where ): int|false {
		if ( $GLOBALS['carriers_db_frozen'] ) {
			return false;
		}
		$vendor_id                             = (int) $where['id'];
		$GLOBALS['carriers_rows'][ $vendor_id ] = array_merge( $GLOBALS['carriers_rows'][ $vendor_id ], $data );
		return 1;
	}

	public function insert( string $table, array $data, mixed $format = null ): int {
		$GLOBALS['carriers_audit'][] = $data;
		return 1;
	}
}

$GLOBALS['wpdb']                 = new Carriers_Test_Wpdb();
$GLOBALS['carriers_rows']        = array();
$GLOBALS['carriers_meta']        = array();
$GLOBALS['carriers_meta_frozen'] = false;
$GLOBALS['carriers_db_frozen']   = false;
$GLOBALS['carriers_audit']       = array();
$GLOBALS['carriers_released']    = true;
$GLOBALS['carriers_rate_ok']     = true;
$GLOBALS['carriers_rate_calls']  = array();
$GLOBALS['carriers_probes']      = array();
$GLOBALS['carriers_probe_sets']  = '';

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function add_action( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): bool { return true; }
function current_time( mixed ...$args ): string { return '2026-10-13 12:00:00'; }
function papelito_shipping_feature_enabled( string $provider ): bool { return $GLOBALS['carriers_released']; }
function papelito_shipping_provider_vendor_allowed( string $provider, int $vendor_id ): bool { return true; }
function register_rest_route( mixed ...$args ): bool { return true; }
function papelito_auth_rate_limit( mixed ...$args ): bool {
	$GLOBALS['carriers_rate_calls'][] = $args;

	return (bool) $GLOBALS['carriers_rate_ok'];
}
function papelito_braspress_probe_credentials( int $vendor_id ): bool {
	$GLOBALS['carriers_probes'][] = $vendor_id;
	if ( '' !== $GLOBALS['carriers_probe_sets'] ) {
		$GLOBALS['carriers_rows'][ $vendor_id ]['status'] = $GLOBALS['carriers_probe_sets'];
	}

	return true;
}

function get_user_meta( int $user_id, string $key, bool $single = false ): mixed {
	return $GLOBALS['carriers_meta'][ $user_id ][ $key ] ?? '';
}

function update_user_meta( int $user_id, string $key, mixed $value ): bool {
	if ( $GLOBALS['carriers_meta_frozen'] ) {
		return false;
	}
	$GLOBALS['carriers_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( int $user_id, string $key ): bool {
	unset( $GLOBALS['carriers_meta'][ $user_id ][ $key ] );
	return true;
}

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';
require_once dirname( __DIR__ ) . '/includes/vendor_carriers.php';
require_once dirname( __DIR__ ) . '/includes/vendor_dashboard.php';

$failures = 0;

function carriers_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "  PASS: {$label}\n";
}

function carriers_seed_vendor( int $vendor_id ): void {
	$GLOBALS['carriers_meta'][ $vendor_id ] = array( 'cnpj' => CARRIERS_TEST_CNPJ, 'cep' => CARRIERS_TEST_CEP );
}

function carriers_configure_braspress( int $vendor_id, string $status = 'ready', int $enabled = 1, ?string $envelope = 'envelope-cifrado' ): void {
	$GLOBALS['carriers_rows'][ $vendor_id ] = array(
		'id'              => $vendor_id,
		'vendor_id'       => $vendor_id,
		'enabled'         => $enabled,
		'status'          => $status,
		'config_json'     => '',
		'secret_envelope' => $envelope,
	);
}

function carriers_disabled_meta( int $vendor_id ): string {
	return (string) get_user_meta( $vendor_id, CARRIERS_TEST_META, true );
}

carriers_seed_vendor( CARRIERS_TEST_VENDOR );
carriers_seed_vendor( CARRIERS_TEST_OTHER_VENDOR );

echo "Braspress não configurada\n";
$state = papelito_vendor_carriers_correios_state( CARRIERS_TEST_VENDOR );
carriers_assert( 'vendor novo começa com Correios ON e travado', array( 'enabled' => true, 'locked' => true, 'braspress_enabled' => false ) === $state );

$denied = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_assert(
	'desligar pela API sem Braspress configurada é recusado com 422',
	is_wp_error( $denied ) && CARRIERS_TEST_LOCKED_CODE === $denied->get_error_code() && 422 === $denied->get_error_data()['status']
);
carriers_assert( 'a recusa não grava preferência nenhuma', '' === carriers_disabled_meta( CARRIERS_TEST_VENDOR ) );
carriers_assert( 'religar sem Braspress continua permitido', is_array( papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, true ) ) );

carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'unconfigured', 0, null );
carriers_assert( 'linha unconfigured sem credencial não conta como configurada', ! papelito_vendor_carriers_braspress_configured( CARRIERS_TEST_VENDOR ) );

echo "\nBraspress configurada\n";
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'ready', 0 );
carriers_assert( 'configurada mesmo desabilitada: configurar e habilitar são eixos distintos', papelito_vendor_carriers_braspress_configured( CARRIERS_TEST_VENDOR ) );
carriers_assert( 'Braspress configurada destrava os Correios mesmo desabilitada', ! papelito_vendor_carriers_correios_state( CARRIERS_TEST_VENDOR )['locked'] );
foreach ( array( 'active', 'invalid_credentials', 'provider_blocked' ) as $status ) {
	carriers_configure_braspress( CARRIERS_TEST_VENDOR, $status );
	carriers_assert( "status {$status} conta como configurada, como a tela já mostra", papelito_vendor_carriers_braspress_configured( CARRIERS_TEST_VENDOR ) );
}
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'ready' );

$saved = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_assert( 'com Braspress ligada o vendor desliga os Correios', array( 'enabled' => false, 'locked' => false, 'braspress_enabled' => true ) === $saved );
carriers_assert( 'a preferência fica persistida para as próximas sessões', '1' === carriers_disabled_meta( CARRIERS_TEST_VENDOR ) );
carriers_assert( 'a cotação passa a ler Correios desligados', ! papelito_vendor_carriers_correios_enabled( CARRIERS_TEST_VENDOR ) );
carriers_assert( 'com a Braspress já ligada nada é gravado na integração', array() === $GLOBALS['carriers_audit'] );

$public = papelito_vendor_integration_public_record( $GLOBALS['carriers_rows'][ CARRIERS_TEST_VENDOR ] );
carriers_assert( 'o estado da Braspress leva o efeito sobre os Correios', false === $public['correios_enabled'] && false === $public['correios_locked'] );

echo "\nNunca as duas desligadas\n";
papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, true );
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'ready', 0 );
$saved = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_assert( 'desligar os Correios com a Braspress desligada liga a Braspress', array( 'enabled' => false, 'locked' => false, 'braspress_enabled' => true ) === $saved );
carriers_assert( 'a Braspress é ligada pela escrita única da integração', 1 === $GLOBALS['carriers_rows'][ CARRIERS_TEST_VENDOR ]['enabled'] && 1 === $GLOBALS['carriers_rows'][ CARRIERS_TEST_VENDOR ]['configuration_version'] );
carriers_assert( 'a troca automática fica na trilha de auditoria', 'configuration_saved' === ( $GLOBALS['carriers_audit'][0]['action'] ?? '' ) );

$GLOBALS['carriers_rows'][ CARRIERS_TEST_VENDOR ]['enabled'] = 0;
carriers_assert( 'desligar a Braspress com os Correios desligados religa os Correios', papelito_vendor_carriers_correios_enabled( CARRIERS_TEST_VENDOR ) );
carriers_assert( 'a preferência de desligado é esquecida', '' === carriers_disabled_meta( CARRIERS_TEST_VENDOR ) );

papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
$switched_off = papelito_vendor_integration_write_braspress( CARRIERS_TEST_VENDOR, array( 'enabled' => false, 'origin_cep' => '' ), CARRIERS_TEST_VENDOR );
carriers_assert( 'a resposta de desligar a Braspress já devolve os Correios religados', is_array( $switched_off ) && true === $switched_off['correios_enabled'] && false === $switched_off['enabled'] );

$GLOBALS['carriers_audit']     = array();
$GLOBALS['carriers_db_frozen'] = true;
$blocked = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_assert( 'se a Braspress não liga, os Correios não desligam', is_wp_error( $blocked ) && '' === carriers_disabled_meta( CARRIERS_TEST_VENDOR ) );
$GLOBALS['carriers_db_frozen'] = false;

$GLOBALS['carriers_released'] = false;
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'active' );
$unreleased = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_assert( 'Braspress não liberada pela plataforma trava os Correios', is_wp_error( $unreleased ) && CARRIERS_TEST_LOCKED_CODE === $unreleased->get_error_code() );
carriers_assert( 'Braspress não liberada conta como desligada', array( 'enabled' => true, 'locked' => true, 'braspress_enabled' => false ) === papelito_vendor_carriers_correios_state( CARRIERS_TEST_VENDOR ) );
$GLOBALS['carriers_released'] = true;
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'ready' );
papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );

echo "\nIsolamento entre vendors\n";
carriers_assert( 'outro vendor continua com Correios ON', papelito_vendor_carriers_correios_enabled( CARRIERS_TEST_OTHER_VENDOR ) );
carriers_assert( 'outro vendor não herda a Braspress configurada', papelito_vendor_carriers_correios_state( CARRIERS_TEST_OTHER_VENDOR )['locked'] );

echo "\nReligar\n";
$saved = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, true );
carriers_assert( 'religar devolve Correios ON e editável', array( 'enabled' => true, 'locked' => false, 'braspress_enabled' => true ) === $saved );
carriers_assert( 'religar apaga a preferência em vez de gravar outro valor', '' === carriers_disabled_meta( CARRIERS_TEST_VENDOR ) );

echo "\nBraspress perde a configuração\n";
papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'unconfigured', 0, null );
carriers_assert( 'credencial removida devolve Correios ON', papelito_vendor_carriers_correios_enabled( CARRIERS_TEST_VENDOR ) );
carriers_assert( 'a preferência antiga é esquecida', '' === carriers_disabled_meta( CARRIERS_TEST_VENDOR ) );
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'ready' );
carriers_assert( 'recadastrar a Braspress não ressuscita Correios OFF', papelito_vendor_carriers_correios_enabled( CARRIERS_TEST_VENDOR ) );

papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
unset( $GLOBALS['carriers_rows'][ CARRIERS_TEST_VENDOR ] );
$erased = papelito_vendor_integration_public_record( null, CARRIERS_TEST_VENDOR );
carriers_assert( 'integração removida responde Correios ON e travado', true === $erased['correios_enabled'] && true === $erased['correios_locked'] );
carriers_assert( 'integração removida apaga a preferência', '' === carriers_disabled_meta( CARRIERS_TEST_VENDOR ) );

carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'ready' );
papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
$GLOBALS['carriers_meta'][ CARRIERS_TEST_VENDOR ]['cnpj'] = '';
carriers_assert( 'CNPJ apagado do cadastro por fora também devolve Correios ON', papelito_vendor_carriers_correios_enabled( CARRIERS_TEST_VENDOR ) );
carriers_seed_vendor( CARRIERS_TEST_VENDOR );

echo "\nFalha de persistência\n";
$GLOBALS['carriers_meta_frozen'] = true;
$failed = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_assert( 'gravação que não pegou devolve erro, não sucesso falso', is_wp_error( $failed ) && 'papelito_vendor_correios_save_failed' === $failed->get_error_code() );
$GLOBALS['carriers_meta_frozen'] = false;

echo "\nCota da autoativação\n";
carriers_seed_vendor( CARRIERS_TEST_VENDOR );
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'ready', 0 );
papelito_vendor_carriers_forget_correios_preference( CARRIERS_TEST_VENDOR );
$GLOBALS['carriers_rate_ok']    = false;
$GLOBALS['carriers_rate_calls'] = array();
$GLOBALS['carriers_probes']     = array();
$limited = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_assert( 'cota esgotada recusa a autoativação com 429', is_wp_error( $limited ) && 'papelito_vendor_integration_rate_limited' === $limited->get_error_code() );
carriers_assert( 'sem sondar a Braspress', array() === $GLOBALS['carriers_probes'] );
carriers_assert( 'e os Correios continuam ligados', papelito_vendor_carriers_correios_enabled( CARRIERS_TEST_VENDOR ) );
carriers_assert( 'a autoativação consome a cota de configuração', 'vendor_braspress_config' === ( $GLOBALS['carriers_rate_calls'][0][0] ?? null ) );
$GLOBALS['carriers_rate_ok'] = true;
$allowed = papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
carriers_assert( 'com cota, desligar os Correios liga a Braspress', is_array( $allowed ) && true === $allowed['braspress_enabled'] );
carriers_assert( 'e sonda a credencial uma vez', array( CARRIERS_TEST_VENDOR ) === $GLOBALS['carriers_probes'] );

echo "\nResposta da gravação dos Correios\n";
carriers_configure_braspress( CARRIERS_TEST_VENDOR, 'ready', 0 );
papelito_vendor_carriers_forget_correios_preference( CARRIERS_TEST_VENDOR );
$GLOBALS['carriers_probe_sets'] = 'invalid_credentials';
papelito_vendor_carriers_set_correios_enabled( CARRIERS_TEST_VENDOR, false );
$respondido = papelito_vendor_dashboard_settings_write_response( CARRIERS_TEST_VENDOR, array( 'correios_enabled' => false ) );
carriers_assert( 'a resposta traz a Braspress ligada pela autoativação', true === ( $respondido['braspress']['enabled'] ?? null ) );
carriers_assert( 'com a recusa que a sondagem gravou', 'invalid_credentials' === ( $respondido['braspress']['status'] ?? null ) );
carriers_assert( 'sem credencial em claro', ! array_key_exists( 'secret_envelope', $respondido['braspress'] ?? array() ) );
$so_prazo = papelito_vendor_dashboard_settings_write_response( CARRIERS_TEST_VENDOR, array( 'shipping_lead_time_days' => 5 ) );
carriers_assert( 'gravar só o prazo não carrega a Braspress', ! array_key_exists( 'braspress', $so_prazo ) );
$GLOBALS['carriers_probe_sets'] = '';

echo "\nLeitura do corpo\n";
carriers_assert( 'aceita booleano e as formas textuais exatas', true === papelito_vendor_carriers_parse_enabled( 'true' ) && false === papelito_vendor_carriers_parse_enabled( 0 ) && false === papelito_vendor_carriers_parse_enabled( false ) );
carriers_assert( 'valor ambíguo não vira desligado', null === papelito_vendor_carriers_parse_enabled( '' ) && null === papelito_vendor_carriers_parse_enabled( 'off' ) && null === papelito_vendor_carriers_parse_enabled( array() ) );

echo "\n";
echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
