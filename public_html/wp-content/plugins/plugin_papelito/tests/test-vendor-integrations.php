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

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value ): string { return json_encode( $value ); }
function add_action() {}
function register_rest_route() {}

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';

$failures = 0;

function vendor_integration_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

$config = papelito_vendor_integration_normalize_config(
	array(
		'senderCnpj'          => '12.345.678/0001-95',
		'originCep'           => '01310-930',
		'modal'               => 'r',
		'freightType'         => '1',
		'weightUnit'          => 'kg',
		'quoteTimezone'       => 'America/Sao_Paulo',
		'trackingTomadorCnpj' => '12.345.678/0001-95',
	)
);

vendor_integration_assert( 'configuração Braspress válida é normalizada', is_array( $config ) && 'R' === $config['modal'] && '01310930' === $config['origin_cep'] );
vendor_integration_assert( 'configuração completa exige os campos contratuais', is_array( $config ) && papelito_vendor_integration_config_complete( $config ) );
vendor_integration_assert(
	'alterar contrato ativo exige nova validação',
	is_array( $config ) && papelito_vendor_integration_config_changed(
		array( 'config_json' => wp_json_encode( $config ) ),
		array_merge( $config, array( 'origin_cep' => '01001000' ) )
	)
);
vendor_integration_assert(
	'contrato idêntico não força revalidação',
	is_array( $config ) && ! papelito_vendor_integration_config_changed( array( 'config_json' => wp_json_encode( $config ) ), $config )
);

$consigned = papelito_vendor_integration_normalize_config(
	array(
		'freightType' => '3',
	)
);
vendor_integration_assert( 'consignado sem CNPJ é recusado', is_wp_error( $consigned ) && 'papelito_vendor_integration_consignee_required' === $consigned->get_error_code() );

$public = papelito_vendor_integration_public_record(
	array(
		'enabled'               => 1,
		'status'                => PAPELITO_VENDOR_INTEGRATION_READY,
		'configuration_version' => 4,
		'secret_envelope'       => 'v1:opaque',
		'config_json'           => wp_json_encode( $config ),
	)
);
$serialized = wp_json_encode( $public );
vendor_integration_assert( 'leitura pública informa que há credencial', true === $public['credentials_configured'] );
vendor_integration_assert( 'leitura pública nunca inclui envelope', ! str_contains( $serialized, 'v1:opaque' ) );
vendor_integration_assert( 'leitura pública nunca inclui senha', ! array_key_exists( 'password', $public ) && ! array_key_exists( 'username', $public ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
