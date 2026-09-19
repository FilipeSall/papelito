<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.
/**
 * Credencial recusada pela Braspress: estado, alerta e isolamento dos Correios.
 *
 * Fixa a parte que o banco sozinho não mostra: que `401/403` confirmado **não**
 * desliga a integração — `enabled` continua como o vendor deixou —, que o alerta
 * sai uma vez por transição e não a cada checkout, e que a Braspress inelegível
 * é um provider a menos, nunca uma cotação a menos.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_DIGITS_REGEX', '/\D+/' );
define( 'ARRAY_A', 'ARRAY_A' );

const ALERT_TEST_VENDOR_ID  = 2163;
const ALERT_TEST_CNPJ       = '20024291000165';
const ALERT_TEST_CEP        = '14711142';
const ALERT_TEST_CREDENTIAL = 'braspress-usuario-do-teste';
const ALERT_TEST_SECRET     = 'braspress-senha-do-teste';
const ALERT_TEST_ENVELOPE   = 'papelito:v1:envelope-cifrado-do-teste';
const ALERT_TEST_AUTH       = 'authentication_error';

$GLOBALS['alert_test_meta']    = array();
$GLOBALS['alert_test_row']     = null;
$GLOBALS['alert_test_updates'] = array();
$GLOBALS['alert_test_actions'] = array();
$GLOBALS['alert_test_alerts']  = array();
$GLOBALS['alert_test_logs']    = array();

/** Erro WordPress mínimo, suficiente para o decifrador e o gate. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

class WP_REST_Request {}
class WP_REST_Response {}

/** `$wpdb` mínimo: guarda o que foi atualizado e devolve a linha da fixture. */
class Papelito_Alert_Test_Wpdb {
	public string $prefix = 'wp_';

	public function get_charset_collate(): string { return ''; }

	public function get_row( mixed $query, mixed $output = null ): ?array {
		return $GLOBALS['alert_test_row'];
	}

	public function prepare( mixed $query, mixed ...$args ): string { return (string) $query; }

	public function update( mixed $table, array $data, mixed $where, mixed $format = null, mixed $where_format = null ): int {
		$GLOBALS['alert_test_updates'][] = $data;
		$GLOBALS['alert_test_row']       = array_merge( (array) $GLOBALS['alert_test_row'], $data );

		return 1;
	}

	public function insert( mixed $table, array $data, mixed $format = null ): int { return 1; }
	public function delete( mixed $table, mixed $where, mixed $format = null ): int { return 1; }
}

$GLOBALS['wpdb'] = new Papelito_Alert_Test_Wpdb();

/** Registra o listener para que `do_action` execute o código real. */
function add_action( mixed $hook, mixed $callback = null, mixed $priority = 10, mixed $accepted = 1 ): bool {
	$GLOBALS['alert_test_actions'][ (string) $hook ][] = $callback;

	return true;
}

/** Executa os listeners e guarda o alerta publicado para inspeção. */
function do_action( mixed $hook, mixed ...$args ): bool {
	if ( 'papelito_shipping_provider_alert' === (string) $hook ) {
		$GLOBALS['alert_test_alerts'][] = $args;
	}
	foreach ( $GLOBALS['alert_test_actions'][ (string) $hook ] ?? array() as $callback ) {
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, ...$args );
		}
	}

	return true;
}

/** Nenhum filtro instalado. */
function add_filter( mixed ...$args ): bool { return true; }
/** Devolve o valor recebido, sem filtro instalado. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed { return $value; }
/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Serialização usada pelo log estruturado. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Nenhuma rota REST é exercitada. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Relógio fixo do teste. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-19 12:00:00'; }
/** Cadastro do vendor, lido pela configuração derivada. */
function get_user_meta( int $user_id, string $key, bool $single = false ): string {
	return $GLOBALS['alert_test_meta'][ $user_id ][ $key ] ?? '';
}
/** O envelope da fixture decifra para a credencial da fixture. */
function papelito_pii_decrypt( string $envelope ): mixed {
	return ALERT_TEST_ENVELOPE === $envelope
		? wp_json_encode( array( 'username' => ALERT_TEST_CREDENTIAL, 'password' => ALERT_TEST_SECRET ) )
		: new WP_Error( 'papelito_pii_decrypt_failed', 'Envelope indisponível.' );
}
/** Cifragem não exercitada neste teste. */
function papelito_pii_encrypt( string $plain ): string { return ALERT_TEST_ENVELOPE; }

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';
require_once dirname( __DIR__ ) . '/includes/shipping_observability.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function alert_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Recria a integração habilitada e ativa do vendor da fixture. */
function alert_reset( string $status = PAPELITO_VENDOR_INTEGRATION_ACTIVE, string $origin_cep = ALERT_TEST_CEP ): void {
	$GLOBALS['alert_test_meta'][ ALERT_TEST_VENDOR_ID ] = array( 'cnpj' => ALERT_TEST_CNPJ, 'cep' => ALERT_TEST_CEP );
	$GLOBALS['alert_test_row']                          = array(
		'id'                    => 7,
		'vendor_id'             => ALERT_TEST_VENDOR_ID,
		'provider'              => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'config_json'           => wp_json_encode( array( 'origin_cep' => $origin_cep ) ),
		'secret_envelope'       => ALERT_TEST_ENVELOPE,
		'configuration_version' => 4,
		'enabled'               => 1,
		'status'                => $status,
	);
	$GLOBALS['alert_test_updates'] = array();
	$GLOBALS['alert_test_alerts']  = array();
}

echo "Cenário 1: 401 confirmado marca invalid_credentials sem desabilitar a integração\n";
alert_reset();

papelito_vendor_integration_set_braspress_operational_state( ALERT_TEST_VENDOR_ID, PAPELITO_VENDOR_INTEGRATION_INVALID, ALERT_TEST_AUTH );

$written = $GLOBALS['alert_test_updates'][0] ?? array();

alert_assert(
	'O estado passa a invalid_credentials',
	PAPELITO_VENDOR_INTEGRATION_INVALID === ( $written['status'] ?? null )
);
alert_assert(
	'A escrita não encosta em enabled, que continua como o vendor deixou',
	! array_key_exists( 'enabled', $written ) && 1 === (int) $GLOBALS['alert_test_row']['enabled']
);
alert_assert(
	'A categoria do erro fica registrada na linha',
	ALERT_TEST_AUTH === ( $written['last_error_category'] ?? null )
);

echo "\nCenário 2: a transição alerta uma vez, e o alerta não carrega segredo\n";
alert_reset();

papelito_vendor_integration_set_braspress_operational_state( ALERT_TEST_VENDOR_ID, PAPELITO_VENDOR_INTEGRATION_INVALID, ALERT_TEST_AUTH );
$first = count( $GLOBALS['alert_test_alerts'] );
papelito_vendor_integration_set_braspress_operational_state( ALERT_TEST_VENDOR_ID, PAPELITO_VENDOR_INTEGRATION_INVALID, ALERT_TEST_AUTH );

alert_assert( 'A entrada em invalid_credentials gera alerta', 1 === $first );
alert_assert(
	'Uma credencial quebrada não alerta de novo a cada checkout',
	1 === count( $GLOBALS['alert_test_alerts'] )
);

$alert = $GLOBALS['alert_test_alerts'][0] ?? array();

alert_assert(
	'O alerta identifica provider, vendor por ID interno e motivo',
	PAPELITO_VENDOR_INTEGRATION_PROVIDER === ( $alert[0] ?? null )
	&& PAPELITO_VENDOR_INTEGRATION_INVALID === ( $alert[1] ?? null )
	&& ALERT_TEST_VENDOR_ID === ( $alert[2]['vendor_id'] ?? null )
);

$serialized = wp_json_encode( $alert );
foreach (
	array(
		'usuário da credencial' => ALERT_TEST_CREDENTIAL,
		'senha da credencial'   => ALERT_TEST_SECRET,
		'envelope cifrado'      => ALERT_TEST_ENVELOPE,
		'CNPJ do vendor'        => ALERT_TEST_CNPJ,
		'CEP de origem'         => ALERT_TEST_CEP,
	) as $label => $needle
) {
	alert_assert( "O alerta não carrega {$label}", false === strpos( $serialized, $needle ) );
}

echo "\nCenário 3: integração inválida é inelegível, mas continua habilitada\n";
alert_reset( PAPELITO_VENDOR_INTEGRATION_INVALID );

alert_assert(
	'Credencial recusada tira a Braspress da cotação',
	null === papelito_vendor_integration_resolve_braspress( ALERT_TEST_VENDOR_ID )
);
alert_assert(
	'A integração continua habilitada, para o vendor poder corrigir a senha',
	1 === (int) $GLOBALS['alert_test_row']['enabled']
);

echo "\nCenário 4: configuração local inválida também é inelegível, sem alerta de credencial\n";
alert_reset( PAPELITO_VENDOR_INTEGRATION_READY, '' );
$GLOBALS['alert_test_meta'][ ALERT_TEST_VENDOR_ID ]['cep'] = '';

alert_assert(
	'Origem ausente tira a Braspress da cotação',
	null === papelito_vendor_integration_resolve_braspress( ALERT_TEST_VENDOR_ID )
);
alert_assert(
	'Configuração incompleta não vira alerta de credencial recusada',
	array() === $GLOBALS['alert_test_alerts']
);

echo "\nCenário 5: voltar a cotar fecha o alerta e restaura o estado ativo\n";
alert_reset( PAPELITO_VENDOR_INTEGRATION_INVALID );

papelito_vendor_integration_set_braspress_operational_state( ALERT_TEST_VENDOR_ID, PAPELITO_VENDOR_INTEGRATION_ACTIVE );

alert_assert(
	'Uma cotação válida devolve a integração para active',
	PAPELITO_VENDOR_INTEGRATION_ACTIVE === ( $GLOBALS['alert_test_updates'][0]['status'] ?? null )
);
alert_assert(
	'A recuperação também é publicada, para fechar o alerta aberto',
	1 === count( $GLOBALS['alert_test_alerts'] )
	&& PAPELITO_VENDOR_INTEGRATION_ACTIVE === ( $GLOBALS['alert_test_alerts'][0][1] ?? null )
);

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
