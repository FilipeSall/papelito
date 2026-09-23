<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_DIGITS_REGEX', '/\\D+/' );

class WP_Error {
	private string $code;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code = $code;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

class WP_REST_Request {}
class WP_REST_Response {}

$GLOBALS['vendor_meta'] = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function add_action( mixed ...$args ): bool { return true; }
function register_rest_route( mixed ...$args ): bool { return true; }
function get_user_meta( int $user_id, string $key, bool $single = false ) {
	return $GLOBALS['vendor_meta'][ $user_id ][ $key ] ?? '';
}

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';

$failures = 0;

function vendor_integration_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

const VENDOR_TEST_ID          = 2163;
const VENDOR_TEST_CNPJ_DIGITS = '20024291000165';
const VENDOR_TEST_CEP_DIGITS  = '14711142';

$GLOBALS['vendor_meta'][2163] = array(
	'cnpj' => '20.024.291/0001-65',
	'cep'  => '14711-142',
);

$config = papelito_vendor_integration_braspress_config( 2163 );

vendor_integration_assert( 'CNPJ remetente vem do cadastro, não do formulário', VENDOR_TEST_CNPJ_DIGITS === $config['sender_cnpj'] );
vendor_integration_assert( 'em CIF o tomador do frete é o próprio remetente', $config['sender_cnpj'] === $config['tracking_tomador_cnpj'] );
vendor_integration_assert( 'origem cai para o CEP do cadastro quando nada é declarado', VENDOR_TEST_CEP_DIGITS === $config['origin_cep'] );
vendor_integration_assert( 'modal e tipo de frete são fixos do marketplace', 'R' === $config['modal'] && '1' === $config['freight_type'] );
vendor_integration_assert( 'configuração derivada do cadastro é suficiente para cotar', papelito_vendor_integration_config_complete( $config ) );
vendor_integration_assert(
	'peso, fuso e consignatário não são dados persistidos do vendor',
	! array_key_exists( 'weight_unit', $config ) && ! array_key_exists( 'quote_timezone', $config ) && ! array_key_exists( 'consignee_cnpj', $config )
);

$declared = papelito_vendor_integration_braspress_config( 2163, wp_json_encode( array( 'origin_cep' => '01001-000' ) ) );
vendor_integration_assert( 'vendor pode declarar a origem do próprio contrato', '01001000' === $declared['origin_cep'] );
vendor_integration_assert( 'origem declarada não altera o CNPJ do cadastro', VENDOR_TEST_CNPJ_DIGITS === $declared['sender_cnpj'] );

$GLOBALS['vendor_meta'][999] = array( 'cnpj' => '', 'cep' => '' );
$incomplete                  = papelito_vendor_integration_braspress_config( 999 );
vendor_integration_assert( 'cadastro sem CNPJ e sem CEP não permite cotar', ! papelito_vendor_integration_config_complete( $incomplete ) );

vendor_integration_assert( 'origem em branco é aceita e recai no cadastro', '' === papelito_vendor_integration_normalize_origin_cep( '' ) );
vendor_integration_assert( 'origem com menos de oito dígitos é recusada', null === papelito_vendor_integration_normalize_origin_cep( '1471114' ) );
vendor_integration_assert( 'origem só com zeros é recusada', null === papelito_vendor_integration_normalize_origin_cep( '00000000' ) );

$public = papelito_vendor_integration_public_record(
	array(
		'vendor_id'             => 2163,
		'enabled'               => 1,
		'status'                => PAPELITO_VENDOR_INTEGRATION_READY,
		'configuration_version' => 4,
		'secret_envelope'       => 'v1:opaque',
		'config_json'           => wp_json_encode( array( 'origin_cep' => VENDOR_TEST_CEP_DIGITS ) ),
	)
);
$serialized = wp_json_encode( $public );
vendor_integration_assert( 'leitura pública informa que há credencial', true === $public['credentials_configured'] );
vendor_integration_assert( 'leitura pública nunca inclui envelope', ! str_contains( $serialized, 'v1:opaque' ) );
vendor_integration_assert( 'leitura pública nunca inclui senha', ! array_key_exists( 'password', $public ) && ! array_key_exists( 'username', $public ) );
vendor_integration_assert( 'leitura pública devolve a origem para pré-preencher o formulário', VENDOR_TEST_CEP_DIGITS === $public['config']['origin_cep'] );

vendor_integration_assert( 'existe estado próprio para conta bloqueada na Braspress', 'provider_blocked' === PAPELITO_VENDOR_INTEGRATION_BLOCKED );

$GLOBALS['probe_calls'] = array();

/**
 * Registra a sondagem que o gate da gravação deixou passar.
 *
 * @param int $vendor_id Vendor sondado.
 * @return string Categoria degradante, sempre vazia neste fixture.
 */
function papelito_braspress_probe_credentials( int $vendor_id ): string {
	$GLOBALS['probe_calls'][] = $vendor_id;

	return '';
}

papelito_vendor_integration_probe_braspress_credentials( VENDOR_TEST_ID, true, PAPELITO_VENDOR_INTEGRATION_READY );
vendor_integration_assert( 'integração habilitada e pronta sonda a credencial ao salvar', array( VENDOR_TEST_ID ) === $GLOBALS['probe_calls'] );

papelito_vendor_integration_probe_braspress_credentials( VENDOR_TEST_ID, false, PAPELITO_VENDOR_INTEGRATION_READY );
papelito_vendor_integration_probe_braspress_credentials( VENDOR_TEST_ID, true, PAPELITO_VENDOR_INTEGRATION_ACTIVE );
papelito_vendor_integration_probe_braspress_credentials( VENDOR_TEST_ID, true, PAPELITO_VENDOR_INTEGRATION_INVALID );
vendor_integration_assert( 'desabilitada, já ativa ou já recusada não gastam sondagem', array( VENDOR_TEST_ID ) === $GLOBALS['probe_calls'] );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
