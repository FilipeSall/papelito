<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Cotação Braspress que chega depois de a configuração mudar.
 *
 * A cotação real leva até 15 segundos e o vendor pode desabilitar a integração
 * ou trocar a credencial nesse intervalo. Fixa que a resposta em trânsito é
 * descartada em vez de virar opção no checkout, e que ela também não entra no
 * cache — reaparecer meia hora depois seria pior do que não ter cotado.
 *
 * Fixa a assimetria oposta com o mesmo peso: quando **só** o registro de saúde
 * falha, a cotação continua válida e o comprador não perde o frete.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );

const PAPELITO_VENDOR_INTEGRATION_ACTIVE         = 'active';
const PAPELITO_VENDOR_INTEGRATION_INVALID        = 'invalid_credentials';
const PAPELITO_VENDOR_INTEGRATION_BLOCKED        = 'provider_blocked';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED = 'applied';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE   = 'stale';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED  = 'failed';

const STALE_TEST_SALT              = 'salt-de-cotacao-obsoleta';
const STALE_TEST_VENDOR_ID         = 2163;
const STALE_TEST_INTEGRATION_ID    = 91;
const STALE_TEST_VERSION           = 7;
const STALE_TEST_USERNAME          = 'usuario-sintetico-do-teste';
const STALE_TEST_PASSWORD          = 'senha-sintetica-do-teste';
const STALE_TEST_SENDER_CNPJ       = '12345678000195';
const STALE_TEST_RECIPIENT_CNPJ    = '12345678000199';
const STALE_TEST_DESTINATION_CEP   = '22041001';
const STALE_TEST_ORIGIN_CEP        = '01310930';
const STALE_TEST_PHYSICAL_HASH     = 'physical-hash-obsoleto';
const STALE_TEST_MERCHANDISE_CENTS = 12345;
const STALE_TEST_QUOTE_ID          = 'Q-STALE-1';
const STALE_TEST_QUOTE_PRICE       = 12.5;
const STALE_TEST_QUOTE_DAYS        = 3;

$GLOBALS['stale_cache']        = array();
$GLOBALS['stale_health_calls'] = array();
$GLOBALS['stale_health_result'] = PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED;
$GLOBALS['stale_logs']         = array();

/** Erro WordPress mínimo. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

/** Identifica um fixture de erro do WordPress. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitiza uma string sintética do provider. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Codifica um payload sintético. */
function wp_json_encode( mixed $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }
/** Chave HMAC estável restrita ao teste. */
function wp_salt( string $scheme = 'auth' ): string { return STALE_TEST_SALT . $scheme; }
/** Nenhum listener é executado. */
function do_action( mixed ...$args ): bool { return true; }
/** Stub de registro de action. */
function add_action( mixed ...$args ): bool { return true; }
/** Stub de registro de rota. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Lê o status de uma resposta HTTP sintética. */
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['status'] ?? 0 ); }
/** Lê o corpo de uma resposta HTTP sintética. */
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }
/** Lê uma cotação sintética do transient em memória. */
function get_transient( string $key ): mixed { return $GLOBALS['stale_cache'][ $key ] ?? false; }
/** Persiste uma cotação sintética. */
function set_transient( string $key, mixed $value, int $expiration ): bool {
	$GLOBALS['stale_cache'][ $key ] = $value;

	return true;
}
/** Transporte falso que sempre devolve a cotação válida da fixture. */
function wp_remote_request( string $url, array $args ): mixed {
	return array(
		'status' => 200,
		'body'   => wp_json_encode(
			array( 'id' => STALE_TEST_QUOTE_ID, 'totalFrete' => STALE_TEST_QUOTE_PRICE, 'prazo' => STALE_TEST_QUOTE_DAYS )
		),
	);
}
/** Aceita a configuração sintética como completa. */
function papelito_vendor_integration_config_complete( array $config ): bool { return ! empty( $config ); }
/** Normaliza um documento sintético. */
function papelito_vendor_integration_normalize_document( mixed $value ): string { return (string) preg_replace( '/\D+/', '', (string) $value ); }
/** Normaliza um CEP sintético. */
function papelito_vendor_integration_normalize_cep( mixed $value ): string { return (string) preg_replace( '/\D+/', '', (string) $value ); }

/** Registra a tentativa de transição e devolve o desfecho da fixture. */
function papelito_vendor_integration_apply_braspress_health( int $vendor_id, string $status, string $error_category = '', int $expected_version = 0 ): string {
	$GLOBALS['stale_health_calls'][] = array(
		'vendor_id'        => $vendor_id,
		'status'           => $status,
		'error_category'   => $error_category,
		'expected_version' => $expected_version,
	);

	return $GLOBALS['stale_health_result'];
}

require_once dirname( __DIR__ ) . '/includes/shipping.php';
require_once dirname( __DIR__ ) . '/includes/braspress.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function stale_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Integração resolvida com credenciais exclusivamente sintéticas. */
function stale_integration(): array {
	return array(
		'id'                    => STALE_TEST_INTEGRATION_ID,
		'vendor_id'             => STALE_TEST_VENDOR_ID,
		'provider'              => 'braspress',
		'configuration_version' => STALE_TEST_VERSION,
		'config'                => array(
			'sender_cnpj'           => STALE_TEST_SENDER_CNPJ,
			'origin_cep'            => STALE_TEST_ORIGIN_CEP,
			'modal'                 => 'R',
			'freight_type'          => '1',
			'tracking_tomador_cnpj' => STALE_TEST_SENDER_CNPJ,
		),
		'credentials'           => array(
			'username' => STALE_TEST_USERNAME,
			'password' => STALE_TEST_PASSWORD,
		),
	);
}

/** Pacote físico aprovado da fixture. */
function stale_package(): array {
	return array(
		'weight_kg'     => 2.5,
		'volumes'       => 1,
		'cubagem'       => array( array( 'length_m' => 0.2, 'width_m' => 0.15, 'height_m' => 0.1, 'volumes' => 1 ) ),
		'physical_hash' => STALE_TEST_PHYSICAL_HASH,
	);
}

/** Executa uma cotação com o desfecho de saúde controlado pela fixture. */
function stale_quote( string $health ): mixed {
	$GLOBALS['stale_cache']         = array();
	$GLOBALS['stale_health_calls']  = array();
	$GLOBALS['stale_health_result'] = $health;

	return papelito_braspress_quote(
		stale_integration(),
		STALE_TEST_RECIPIENT_CNPJ,
		STALE_TEST_DESTINATION_CEP,
		stale_package(),
		STALE_TEST_MERCHANDISE_CENTS
	);
}

echo "Cenário 1: a cotação confirma active usando a versão que a originou\n";
$result = stale_quote( PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED );

stale_assert( 'A opção é devolvida', is_array( $result ) && STALE_TEST_QUOTE_ID === ( $result['external_quote_id'] ?? null ) );
stale_assert( 'A transição foi tentada uma vez', 1 === count( $GLOBALS['stale_health_calls'] ) );
stale_assert(
	'A versão da configuração viaja com a transição',
	STALE_TEST_VERSION === ( $GLOBALS['stale_health_calls'][0]['expected_version'] ?? null )
	&& PAPELITO_VENDOR_INTEGRATION_ACTIVE === ( $GLOBALS['stale_health_calls'][0]['status'] ?? null )
);
stale_assert( 'A cotação válida entra no cache', 1 === count( $GLOBALS['stale_cache'] ) );

echo "\nCenário 2: configuração trocada em trânsito descarta a resposta\n";
$result = stale_quote( PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE );

stale_assert( 'A opção não chega ao checkout', null === $result );
stale_assert( 'A cotação descartada não entra no cache', array() === $GLOBALS['stale_cache'] );

echo "\nCenário 3: falha só ao gravar a saúde preserva a cotação\n";
$result = stale_quote( PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED );

stale_assert(
	'O comprador não perde um frete válido por causa do contador de saúde',
	is_array( $result ) && STALE_TEST_QUOTE_ID === ( $result['external_quote_id'] ?? null )
);

echo "\nCenário 4: o erro de autenticação também carrega a versão\n";
$GLOBALS['stale_health_calls']  = array();
$GLOBALS['stale_health_result'] = PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED;

papelito_braspress_handle_transport_error(
	stale_integration(),
	new WP_Error(
		'papelito_braspress_http_error',
		'',
		array(
			'status'          => 502,
			'provider_status' => 401,
			'error_category'  => 'authentication_error',
			'classification'  => array( 'category' => 'authentication_error', 'messages' => array(), 'trace_id' => '' ),
		)
	),
	'quote',
	120
);

stale_assert(
	'Credencial recusada marca a versão que foi usada, não a corrente',
	STALE_TEST_VERSION === ( $GLOBALS['stale_health_calls'][0]['expected_version'] ?? null )
	&& PAPELITO_VENDOR_INTEGRATION_INVALID === ( $GLOBALS['stale_health_calls'][0]['status'] ?? null )
);

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
