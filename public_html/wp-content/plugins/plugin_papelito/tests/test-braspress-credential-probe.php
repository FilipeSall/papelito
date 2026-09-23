<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Sondagem da credencial Braspress no momento em que o vendor salva a integração.
 *
 * Fixa a assimetria que o estado sozinho não mostra: recusa de credencial e
 * conta bloqueada degradam a integração no próprio salvamento, enquanto
 * indisponibilidade da transportadora e cotação aceita deixam o estado como
 * estava — a loja não pode sair da vitrine por um `5xx` da Braspress.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );

/** Erro do WordPress reduzido ao que o cliente Braspress lê. */
class WP_Error {
	/**
	 * Cria o erro sintético com código, mensagem e dados.
	 *
	 * @param string              $code Código do erro.
	 * @param string              $message Mensagem do erro.
	 * @param array<string,mixed> $data Dados do erro.
	 */
	public function __construct(
		private string $code = '',
		private string $message = '',
		private array $data = array()
	) {}

	/**
	 * Devolve o código do erro sintético.
	 *
	 * @return string Código do erro.
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * Devolve a mensagem do erro sintético.
	 *
	 * @return string Mensagem do erro.
	 */
	public function get_error_message(): string {
		return $this->message;
	}

	/**
	 * Devolve os dados anexados ao erro sintético.
	 *
	 * @return array<string,mixed> Dados do erro.
	 */
	public function get_error_data(): array {
		return $this->data;
	}
}

/** Identifica um erro do WordPress. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitiza uma string vinda do provider sintético. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Normaliza uma chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Serializa um payload como o WordPress serializa. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }
/** Registro de action sem coletor nesta suíte. */
function add_action( mixed ...$args ): bool { return true; }
/** Disparo de action sem coletor nesta suíte. */
function do_action( mixed ...$args ): bool { return true; }
/** Registro de rota REST sem servidor nesta suíte. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Cache de cotação sempre vazio, para toda sondagem chegar ao transporte. */
function get_transient( string $key ): false { return false; }
/** Escrita de cache inerte nesta suíte. */
function set_transient( string $key, mixed $value, int $expiration ): bool { return true; }
/** Lê o status de uma resposta HTTP sintética. */
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['status'] ?? 0 ); }
/** Lê o corpo de uma resposta HTTP sintética. */
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }

/** Captura a chamada e devolve a resposta sintética programada. */
function wp_remote_request( string $url, array $args ): mixed {
	$GLOBALS['probe_test_calls'][] = array( 'url' => $url, 'args' => $args );

	return $GLOBALS['probe_test_response'];
}

require_once dirname( __DIR__ ) . '/includes/braspress.php';

const PAPELITO_VENDOR_INTEGRATION_ACTIVE         = 'active';
const PAPELITO_VENDOR_INTEGRATION_INVALID        = 'invalid_credentials';
const PAPELITO_VENDOR_INTEGRATION_BLOCKED        = 'provider_blocked';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED = 'applied';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE   = 'stale';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED  = 'failed';

const PROBE_TEST_VENDOR_ID   = 2334;
const PROBE_TEST_USERNAME    = 'usuario-sintetico';
const PROBE_TEST_PASSWORD    = 'senha-sintetica-nao-real';
const PROBE_TEST_CNPJ        = '20024291000165';
const PROBE_TEST_ORIGIN_CEP  = '14711142';
const PROBE_TEST_VERSION     = 7;

$GLOBALS['probe_test_calls']       = array();
$GLOBALS['probe_test_response']    = null;
$GLOBALS['probe_test_states']      = array();
$GLOBALS['probe_test_integration'] = null;

/** Confirma que a configuração sintética está completa. */
function papelito_vendor_integration_config_complete( array $config ): bool { return ! empty( $config['origin_cep'] ); }
/** Normaliza um documento sintético. */
function papelito_vendor_integration_normalize_document( mixed $value ): string { return preg_replace( '/\D+/', '', (string) $value ); }
/** Normaliza um CEP sintético. */
function papelito_vendor_integration_normalize_cep( mixed $value ): string { return preg_replace( '/\D+/', '', (string) $value ); }

/**
 * Devolve a integração resolvida que a sondagem vai usar.
 *
 * @param int $vendor_id Vendor consultado.
 * @return array<string,mixed>|null Integração sintética, ou nulo quando o teste a retirou.
 */
function papelito_vendor_integration_resolve_braspress( int $vendor_id ) {
	return $GLOBALS['probe_test_integration'];
}

/**
 * Registra a transição de saúde que a sondagem provocou.
 *
 * @param int    $vendor_id Vendor alvo.
 * @param string $status Estado aplicado.
 * @param string $error_category Categoria pública do erro.
 * @param int    $expected_version Versão que originou a tentativa.
 * @return string Desfecho da transição.
 */
function papelito_vendor_integration_apply_braspress_health( int $vendor_id, string $status, string $error_category = '', int $expected_version = 0 ): string {
	$GLOBALS['probe_test_states'][] = array(
		'vendor_id'      => $vendor_id,
		'status'         => $status,
		'error_category' => $error_category,
		'version'        => $expected_version,
	);

	return PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED;
}

$failures = 0;

/** Confere um comportamento da sondagem de credencial. */
function probe_assert( string $label, bool $condition ): void {
	global $failures;

	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

/**
 * Monta a integração habilitada e completa que a sondagem recebe.
 *
 * @return array<string,mixed> Integração sintética resolvida.
 */
function probe_integration(): array {
	return array(
		'vendor_id'             => PROBE_TEST_VENDOR_ID,
		'configuration_version' => PROBE_TEST_VERSION,
		'credentials'           => array(
			'username' => PROBE_TEST_USERNAME,
			'password' => PROBE_TEST_PASSWORD,
		),
		'config'                => array(
			'sender_cnpj'           => PROBE_TEST_CNPJ,
			'origin_cep'            => PROBE_TEST_ORIGIN_CEP,
			'modal'                 => 'R',
			'freight_type'          => '1',
			'tracking_tomador_cnpj' => PROBE_TEST_CNPJ,
		),
	);
}

/**
 * Prepara a próxima sondagem com a resposta sintética informada.
 *
 * @param mixed                    $response Resposta que o transporte devolve.
 * @param array<string,mixed>|null $integration Integração resolvida, ou nulo para simular ausência.
 * @return void
 */
function probe_reset( mixed $response, ?array $integration = null ): void {
	$GLOBALS['probe_test_response']    = $response;
	$GLOBALS['probe_test_calls']       = array();
	$GLOBALS['probe_test_states']      = array();
	$GLOBALS['probe_test_integration'] = $integration ?? probe_integration();
}

/**
 * Diz se alguma transição de saúde registrou o estado informado.
 *
 * @param string $status Estado procurado.
 * @return bool Se a sondagem aplicou aquele estado.
 */
function probe_applied( string $status ): bool {
	foreach ( $GLOBALS['probe_test_states'] as $state ) {
		if ( $status === $state['status'] ) {
			return true;
		}
	}

	return false;
}

probe_reset(
	array(
		'status' => 401,
		'body'   => wp_json_encode( array( 'message' => 'Unauthorized' ) ),
	)
);
$category = papelito_braspress_probe_credentials( PROBE_TEST_VENDOR_ID );
probe_assert( 'credencial recusada devolve a categoria de autenticacao', PAPELITO_BRASPRESS_ERROR_AUTHENTICATION === $category );
probe_assert( 'credencial recusada degrada a integracao', probe_applied( PAPELITO_VENDOR_INTEGRATION_INVALID ) );
probe_assert( 'a degradacao viaja com a versao da configuracao gravada', PROBE_TEST_VERSION === ( $GLOBALS['probe_test_states'][0]['version'] ?? 0 ) );
probe_assert( 'a sondagem cota pelo endpoint oficial', str_ends_with( (string) ( $GLOBALS['probe_test_calls'][0]['url'] ?? '' ), PAPELITO_BRASPRESS_QUOTE_PATH ) );

probe_reset(
	array(
		'status' => 403,
		'body'   => wp_json_encode( array( 'message' => 'Forbidden' ) ),
	)
);
$category = papelito_braspress_probe_credentials( PROBE_TEST_VENDOR_ID );
probe_assert( 'conta recusada pela transportadora e categorizada', in_array( $category, array( PAPELITO_BRASPRESS_ERROR_AUTHENTICATION, PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED ), true ) );

probe_reset(
	array(
		'status' => 503,
		'body'   => wp_json_encode( array( 'message' => 'Service unavailable' ) ),
	)
);
$category = papelito_braspress_probe_credentials( PROBE_TEST_VENDOR_ID );
probe_assert( 'indisponibilidade nao devolve categoria de conta', '' === $category );
probe_assert( 'indisponibilidade nao marca credencial invalida', ! probe_applied( PAPELITO_VENDOR_INTEGRATION_INVALID ) );
probe_assert( 'indisponibilidade nao bloqueia a conta', ! probe_applied( PAPELITO_VENDOR_INTEGRATION_BLOCKED ) );

probe_reset(
	array(
		'status' => 200,
		'body'   => wp_json_encode(
			array(
				'id'         => 'cotacao-sintetica',
				'totalFrete' => 42.5,
				'prazo'      => 3,
			)
		),
	)
);
$category = papelito_braspress_probe_credentials( PROBE_TEST_VENDOR_ID );
probe_assert( 'credencial aceita nao devolve categoria', '' === $category );
probe_assert( 'credencial aceita nao degrada a integracao', empty( $GLOBALS['probe_test_states'] ) );

probe_reset( array( 'status' => 401, 'body' => '' ), array() );
$GLOBALS['probe_test_integration'] = null;
$category                          = papelito_braspress_probe_credentials( PROBE_TEST_VENDOR_ID );
probe_assert( 'integracao ausente nao chega ao transporte', '' === $category && empty( $GLOBALS['probe_test_calls'] ) );

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
