<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_DIGITS_REGEX', '/\\D+/' );

class WP_Error {
	private string $code;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code = $code;
	}

	public function get_error_code(): string { return $this->code; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function add_action( mixed ...$args ): bool { return true; }
function register_rest_route( mixed ...$args ): bool { return true; }

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';
require_once dirname( __DIR__ ) . '/includes/braspress.php';

$failures = 0;
function braspress_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

const BRASPRESS_TEST_SENDER_CNPJ      = '12345678000195';
const BRASPRESS_TEST_DESTINATION_CNPJ = '12345678000199';
const BRASPRESS_TEST_DESTINATION_CEP  = '22041001';

$integration = array(
	'vendor_id' => 2163,
	'config'    => array(
		'sender_cnpj'           => BRASPRESS_TEST_SENDER_CNPJ,
		'origin_cep'            => '01310930',
		'modal'                 => 'R',
		'freight_type'          => '1',
		'tracking_tomador_cnpj' => BRASPRESS_TEST_SENDER_CNPJ,
	),
);
$package = array(
	'weight_kg' => 2.5,
	'volumes'   => 2,
	'cubagem'   => array(
		array( 'length_m' => 0.3, 'width_m' => 0.2, 'height_m' => 0.1, 'volumes' => 2 ),
	),
);

$payload = papelito_braspress_build_quote_payload( $integration, BRASPRESS_TEST_DESTINATION_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $package, 12345 );
braspress_assert( 'payload válido é montado somente a partir de fontes autorizadas', is_array( $payload ) );
braspress_assert( 'valor mercantil usa centavos sem ponto flutuante', is_array( $payload ) && '123.45' === $payload['vlrMercadoria'] );
braspress_assert( 'cubagem preserva metros e soma de volumes', is_array( $payload ) && 2 === $payload['volumes'] && 0.3 === $payload['cubagem'][0]['comprimento'] );
braspress_assert( 'peso é enviado em quilogramas', is_array( $payload ) && 2.5 === $payload['peso'] );
braspress_assert( 'payload não contém credenciais', is_array( $payload ) && ! array_key_exists( 'username', $payload ) && ! array_key_exists( 'password', $payload ) );

$rounded_package               = $package;
$rounded_package['weight_kg'] = 2.555;
$rounded_payload               = papelito_braspress_build_quote_payload( $integration, BRASPRESS_TEST_DESTINATION_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $rounded_package, 29 );
braspress_assert( 'peso em kg é arredondado half-up para duas casas', is_array( $rounded_payload ) && 2.56 === $rounded_payload['peso'] );
braspress_assert( 'centavos de valor mercantil preservam zero à esquerda', is_array( $rounded_payload ) && '0.29' === $rounded_payload['vlrMercadoria'] );

try {
	$expiry = papelito_braspress_quote_expiry( new DateTimeImmutable( '2026-09-14 23:50:00', new DateTimeZone( 'America/Sao_Paulo' ) ) );
} catch ( TypeError $exception ) {
	$expiry = '';
}
braspress_assert( 'validade fecha no fim do dia de São Paulo em UTC', '2026-09-15T02:59:59.999+00:00' === $expiry );

$invalid_package = $package;
$invalid_package['volumes'] = 1;
$invalid = papelito_braspress_build_quote_payload( $integration, BRASPRESS_TEST_DESTINATION_CNPJ, BRASPRESS_TEST_DESTINATION_CEP, $invalid_package, 12345 );
braspress_assert( 'volumes incoerentes bloqueiam a cotação', is_wp_error( $invalid ) && 'papelito_braspress_payload_invalid' === $invalid->get_error_code() );

braspress_assert( 'consignatário deixou de existir no payload', is_array( $payload ) && ! array_key_exists( 'cnpjConsignado', $payload ) );

$zeroed = papelito_braspress_build_quote_payload( $integration, BRASPRESS_TEST_DESTINATION_CNPJ, '00000000', $package, 12345 );
braspress_assert( 'CEP de destino só com zeros é recusado antes de chamar a Braspress', is_wp_error( $zeroed ) );
braspress_assert( 'CEP de destino com zero à esquerda continua válido', papelito_braspress_cep_is_quotable( '02323000' ) );

/**
 * Corpos literais devolvidos pela API de produção em 2026-09-14.
 * Erro de negócio chega com HTTP 500; erro de validação, com ProblemDetails.
 */
$rota_nao_atendida = array(
	'statusCode' => 500,
	'message'    => 'Erro Interno',
	'dateTime'   => '15/09/2026 00:09:05',
	'errorList'  => array( 'CEP DESTINO NÃO ENCONTRADO' ),
);
$conta_bloqueada = array(
	'statusCode' => 500,
	'message'    => 'Erro Interno',
	'dateTime'   => '15/09/2026 00:09:37',
	'errorList'  => array( "CNPJ PAGANTE BLOQUEADO\rinadimplencia" ),
);
$validacao = array(
	'type'    => 'https://tools.ietf.org/html/rfc7231#section-6.5.1',
	'title'   => 'One or more validation errors occurred.',
	'status'  => 400,
	'traceId' => '00-9f5c4b01e8bdef25c4b23afe0367aeab-11a7f8c7d8fa9a92-01',
	'errors'  => array( 'Cubagem' => array( 'Cubagem dos volumes é obrigatório!' ) ),
);

braspress_assert(
	'destino fora da malha é inelegibilidade, não defeito da integração',
	PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE === papelito_braspress_classify_error( 500, $rota_nao_atendida )['category']
);
braspress_assert(
	'CNPJ bloqueado por inadimplência é reconhecido apesar do HTTP 500',
	PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED === papelito_braspress_classify_error( 500, $conta_bloqueada )['category']
);
braspress_assert(
	'erro de validação é classificado como recusa de payload',
	PAPELITO_BRASPRESS_ERROR_PROVIDER_4XX === papelito_braspress_classify_error( 400, $validacao )['category']
);
braspress_assert(
	'credencial recusada continua sendo erro de autenticação',
	PAPELITO_BRASPRESS_ERROR_AUTHENTICATION === papelito_braspress_classify_error( 401, null )['category']
);
braspress_assert(
	'HTTP 500 sem causa conhecida não vira inelegibilidade silenciosa',
	PAPELITO_BRASPRESS_ERROR_PROVIDER_5XX === papelito_braspress_classify_error( 500, array( 'errorList' => array( 'FALHA GENERICA' ) ) )['category']
);

$campos = papelito_braspress_error_messages( $validacao );
braspress_assert( 'o campo recusado é preservado para diagnóstico', 1 === count( $campos ) && str_contains( $campos[0], 'Cubagem' ) );
braspress_assert( 'o traceId é preservado para correlacionar com a Braspress', str_starts_with( papelito_braspress_classify_error( 400, $validacao )['trace_id'], '00-9f5c4b01' ) );
braspress_assert( 'a causa real do 500 é preservada, não o "Erro Interno"', array( 'CEP DESTINO NÃO ENCONTRADO' ) === papelito_braspress_error_messages( $rota_nao_atendida ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
