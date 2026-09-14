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

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value ): string { return json_encode( $value ); }
function add_action() {}
function register_rest_route() {}

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

$integration = array(
	'config' => array(
		'sender_cnpj'          => '12345678000195',
		'origin_cep'           => '01310930',
		'modal'                => 'R',
		'freight_type'         => '1',
		'consignee_cnpj'       => '',
		'weight_unit'          => 'kg',
		'quote_timezone'       => 'America/Sao_Paulo',
		'tracking_tomador_cnpj' => '12345678000195',
	),
);
$package = array(
	'weight_kg' => 2.5,
	'volumes'   => 2,
	'cubagem'   => array(
		array( 'length_m' => 0.3, 'width_m' => 0.2, 'height_m' => 0.1, 'volumes' => 2 ),
	),
);

$payload = papelito_braspress_build_quote_payload( $integration, '12345678000199', '22041001', $package, 12345 );
braspress_assert( 'payload válido é montado somente a partir de fontes autorizadas', is_array( $payload ) );
braspress_assert( 'valor mercantil usa centavos sem ponto flutuante', is_array( $payload ) && '123.45' === $payload['vlrMercadoria'] );
braspress_assert( 'cubagem preserva metros e soma de volumes', is_array( $payload ) && 2 === $payload['volumes'] && 0.3 === $payload['cubagem'][0]['comprimento'] );
braspress_assert( 'payload não contém credenciais', is_array( $payload ) && ! array_key_exists( 'username', $payload ) && ! array_key_exists( 'password', $payload ) );

$grams_integration                         = $integration;
$grams_integration['config']['weight_unit'] = 'g';
$grams_payload                             = papelito_braspress_build_quote_payload( $grams_integration, '12345678000199', '22041001', $package, 29 );
braspress_assert( 'peso físico em kg converte para gramas quando o contrato exige g', is_array( $grams_payload ) && 2500 === $grams_payload['peso'] );
braspress_assert( 'centavos de valor mercantil preservam zero à esquerda', is_array( $grams_payload ) && '0.29' === $grams_payload['vlrMercadoria'] );

$invalid_package = $package;
$invalid_package['volumes'] = 1;
$invalid = papelito_braspress_build_quote_payload( $integration, '12345678000199', '22041001', $invalid_package, 12345 );
braspress_assert( 'volumes incoerentes bloqueiam a cotação', is_wp_error( $invalid ) && 'papelito_braspress_payload_invalid' === $invalid->get_error_code() );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
