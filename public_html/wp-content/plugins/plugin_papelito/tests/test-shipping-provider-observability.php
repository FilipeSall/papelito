<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Observabilidade de cotação por provider — contadores, latência e vazamento.
 *
 * Fixa o que só se vê olhando o contador junto com o erro que o alimentou: que
 * o desfecho é separado por provider e por categoria da taxonomia da
 * `08-error-handling-and-observability.md`, que rota não atendida não se
 * confunde com credencial recusada, e que nenhum dado do comprador ou da
 * credencial atravessa do `WP_Error` para a option do contador.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

const OBS_TEST_FIRST_DAY    = '2026-09-19';
const OBS_TEST_SECOND_DAY   = '2026-09-20';
const OBS_TEST_VENDOR_ID    = 2163;
const OBS_TEST_OTHER_VENDOR = 4477;
const OBS_TEST_CNPJ         = '20024291000165';
const OBS_TEST_CREDENTIAL   = 'braspress-usuario-do-teste';
const OBS_TEST_SECRET       = 'braspress-senha-do-teste';
const OBS_TEST_ADDRESS      = 'Rua das Laranjeiras, 128, Bebedouro';
const OBS_TEST_CORREIOS        = 'correios';
const OBS_TEST_BRASPRESS       = 'braspress';
const OBS_TEST_DESTINATION_CEP = '22041001';
const OBS_TEST_ORIGIN_CEP      = '14700000';

define( 'PAPELITO_BRASPRESS_ENABLED', 'true' );
define( 'PAPELITO_BRASPRESS_VENDOR_ALLOWLIST', (string) OBS_TEST_VENDOR_ID );

$GLOBALS['obs_test_day']         = OBS_TEST_FIRST_DAY;
$GLOBALS['obs_test_options']     = array();
$GLOBALS['obs_test_actions']     = array();
$GLOBALS['obs_test_correios']    = null;
$GLOBALS['obs_test_braspress']   = null;
$GLOBALS['obs_test_integration'] = null;
$GLOBALS['obs_test_calls']       = 0;

/** Resposta dos Correios controlada pela fixture, no lugar de `shipping.php`. */
function papelito_correios_quote( int $vendor_id, string $destination_cep, array $items ): mixed {
	return $GLOBALS['obs_test_correios'];
}

/** Integração resolvida do vendor, controlada pela fixture. */
function papelito_vendor_integration_resolve_braspress( int $vendor_id ): mixed {
	return $GLOBALS['obs_test_integration'];
}

/** Resposta do adapter Braspress, controlada pela fixture, contando as chamadas. */
function papelito_braspress_quote( array $integration, string $recipient_cnpj, string $destination_cep, array $package, int $merchandise_value_cents ): mixed {
	++$GLOBALS['obs_test_calls'];

	return $GLOBALS['obs_test_braspress'];
}

/** O contador de resíduo não participa deste teste; o pacote passa direto. */
function papelito_shipping_notify_package_built( mixed $package ): mixed { return $package; }
/** A fixture de pacote é aceita sem carregar o adaptador físico. */
function papelito_braspress_package_is_valid( array $package ): bool { return ! empty( $package['volumes'] ); }
/** Normalização documental suficiente para o gate de CNPJ do destinatário. */
function papelito_vendor_integration_normalize_document( mixed $value ): string { return preg_replace( '/\D+/', '', (string) $value ); }

/** Erro WordPress mínimo com a leitura de código e dados que o classificador usa. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

/** Registra o listener para que `do_action` execute o código real do contador. */
function add_action( mixed $hook, mixed $callback = null, mixed $priority = 10, mixed $accepted = 1 ): bool {
	$GLOBALS['obs_test_actions'][ (string) $hook ][] = $callback;

	return true;
}

/** Executa os listeners registrados, como a cotação real faria. */
function do_action( mixed $hook, mixed ...$args ): bool {
	foreach ( $GLOBALS['obs_test_actions'][ (string) $hook ] ?? array() as $callback ) {
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, ...$args );
		}
	}

	return true;
}

/** Nenhum filtro é registrado; o teste não altera vocabulário. */
function add_filter( mixed ...$args ): bool { return true; }

/** Só o pacote físico da Braspress vem da fixture; os demais filtros passam direto. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'papelito_braspress_physical_package' !== (string) $hook ) {
		return $value;
	}

	return array(
		'weight_kg'        => 0.97,
		'volumes'          => 1,
		'cubagem'          => array( array( 'length_m' => 0.2, 'width_m' => 0.15, 'height_m' => 0.1, 'volumes' => 1 ) ),
		'approval_version' => 3,
		'physical_hash'    => str_repeat( 'a', 64 ),
	);
}
/** Sanitização textual suficiente para as fixturas do teste. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Serialização usada pelo log estruturado. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }
/** Segredo HMAC restrito ao teste, usado pelo fingerprint da opção. */
function wp_salt( mixed $scheme = '' ): string { return 'observability-test-salt'; }

/** Relógio do teste: o dia civil é a fixture. */
function current_time( mixed $format = 'Y-m-d', mixed $gmt = false ): string {
	$day = (string) $GLOBALS['obs_test_day'];

	return 'Y-m-d' === (string) $format ? $day : $day . ' 12:00:00';
}

/** Lê a option do armazenamento em memória do teste. */
function get_option( mixed $option, mixed $default_value = false ): mixed {
	return array_key_exists( (string) $option, $GLOBALS['obs_test_options'] )
		? $GLOBALS['obs_test_options'][ (string) $option ]
		: $default_value;
}

/** Grava a option no armazenamento em memória do teste. */
function update_option( mixed $option, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['obs_test_options'][ (string) $option ] = $value;

	return true;
}

/** Remove a option do armazenamento em memória do teste. */
function delete_option( mixed $option ): bool {
	unset( $GLOBALS['obs_test_options'][ (string) $option ] );

	return true;
}

require_once dirname( __DIR__ ) . '/includes/shipping_metrics.php';
require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';
require_once dirname( __DIR__ ) . '/includes/shipping_observability.php';
require_once dirname( __DIR__ ) . '/includes/shipping_breaker.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function obs_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Zera o armazenamento e o relógio entre cenários. */
function obs_reset( string $day = OBS_TEST_FIRST_DAY ): void {
	$GLOBALS['obs_test_options'] = array();
	$GLOBALS['obs_test_day']     = $day;
}

/** Publica um desfecho de cotação como a orquestração publica. */
function obs_publish( string $provider, mixed $result, int $duration_ms = 0, int $vendor_id = OBS_TEST_VENDOR_ID ): void {
	do_action( 'papelito_shipping_provider_quote_result', $provider, $result, $duration_ms, $vendor_id );
}

/** Integração Braspress elegível, como `vendor_integrations.php` resolve. */
function obs_integration(): array {
	return array(
		'vendor_id'   => OBS_TEST_VENDOR_ID,
		'config'      => array( 'origin_cep' => OBS_TEST_ORIGIN_CEP ),
		'credentials' => array( 'username' => OBS_TEST_CREDENTIAL, 'password' => OBS_TEST_SECRET ),
	);
}

/** Contexto autoritativo de precificação exigido pelo gate da Braspress. */
function obs_quote_context(): array {
	return array(
		'recipient_cnpj'          => OBS_TEST_CNPJ,
		'merchandise_value_cents' => 25000,
	);
}

/** Envelope de cotação válido, como o adapter devolve à orquestração. */
function obs_success_result(): array {
	return array(
		'origin_cep'      => OBS_TEST_ORIGIN_CEP,
		'destination_cep' => OBS_TEST_DESTINATION_CEP,
		'vendor_id'       => OBS_TEST_VENDOR_ID,
		'options'         => array(
			array(
				'code'          => 'rodoviario',
				'service'       => 'Braspress',
				'name'          => 'Braspress',
				'price'         => 42.5,
				'delivery_time' => 3,
			),
		),
	);
}

echo "Cenário 1: o contador separa provider e categoria de desfecho\n";
obs_reset();

obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 320 );
obs_publish( OBS_TEST_BRASPRESS, new WP_Error( 'papelito_braspress_credentials_invalid', 'As credenciais Braspress precisam ser atualizadas.', array( 'status' => 502, 'error_category' => 'authentication_error' ) ), 210 );
obs_publish( OBS_TEST_BRASPRESS, new WP_Error( 'papelito_braspress_route_not_available', 'A Braspress não atende este destino.', array( 'status' => 422, 'error_category' => 'not_available' ) ), 180 );
obs_publish( OBS_TEST_CORREIOS, obs_success_result(), 640 );
obs_publish( OBS_TEST_CORREIOS, null, 0 );

$report = papelito_shipping_provider_metrics_report();

obs_assert(
	'Braspress conta uma cotação bem-sucedida',
	1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['outcomes']['success'] ?? null )
);
obs_assert(
	'Credencial recusada entra como authentication_error, não como provider_4xx',
	1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['outcomes']['authentication_error'] ?? null )
	&& 0 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['outcomes']['provider_4xx'] ?? null )
);
obs_assert(
	'Rota não atendida é contada como not_available, separada de falha',
	1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['outcomes']['not_available'] ?? null )
);
obs_assert(
	'Correios conta no próprio balde e não herda o desfecho da Braspress',
	1 === ( $report['quote'][ OBS_TEST_CORREIOS ]['outcomes']['success'] ?? null )
	&& 0 === ( $report['quote'][ OBS_TEST_CORREIOS ]['outcomes']['authentication_error'] ?? null )
);
obs_assert(
	'Provider que não participou é contado como skipped',
	1 === ( $report['quote'][ OBS_TEST_CORREIOS ]['outcomes']['skipped'] ?? null )
);

echo "\nCenário 2: a latência agrega por provider sem guardar amostra individual\n";
obs_reset();

obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 200 );
obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 800 );
obs_publish( OBS_TEST_BRASPRESS, new WP_Error( 'papelito_braspress_timeout', 'A Braspress está temporariamente indisponível.', array( 'status' => 504, 'error_category' => 'timeout' ) ), 15000 );
obs_publish( OBS_TEST_BRASPRESS, null, 0 );
obs_publish( OBS_TEST_CORREIOS, obs_success_result(), 640 );

$report  = papelito_shipping_provider_metrics_report();
$latency = $report['quote'][ OBS_TEST_BRASPRESS ]['latency'] ?? array();

obs_assert(
	'A latência conta só as tentativas que chamaram o provider, não o skipped',
	3 === ( $latency['count'] ?? null )
);
obs_assert(
	'A média sai da soma agregada, sem lista de amostras',
	5333 === ( $latency['average_ms'] ?? null ) && 16000 === ( $latency['total_ms'] ?? null )
);
obs_assert(
	'O pior caso do período é preservado',
	15000 === ( $latency['max_ms'] ?? null )
);
obs_assert(
	'Os baldes de latência localizam a cauda sem percentil exato',
	1 === ( $latency['buckets']['250'] ?? null )
	&& 1 === ( $latency['buckets']['1000'] ?? null )
	&& 1 === ( $latency['buckets']['over'] ?? null )
);
obs_assert(
	'A latência dos Correios não se mistura com a da Braspress',
	1 === ( $report['quote'][ OBS_TEST_CORREIOS ]['latency']['count'] ?? null )
	&& 640 === ( $report['quote'][ OBS_TEST_CORREIOS ]['latency']['max_ms'] ?? null )
);

echo "\nCenário 3: nada do erro do provider atravessa para a option do contador\n";
obs_reset();

$leaky = new WP_Error(
	'papelito_braspress_provider_5xx',
	'Erro Interno: CNPJ PAGANTE ' . OBS_TEST_CNPJ . ' recusado para ' . OBS_TEST_ADDRESS,
	array(
		'status'          => 502,
		'error_category'  => 'provider_5xx',
		'provider_status' => 500,
		'classification'  => array(
			'messages' => array( 'CNPJ PAGANTE BLOQUEADO ' . OBS_TEST_CNPJ ),
			'trace_id' => '00-9f5c4b01e8bdef25c4b23afe0367aeab-11a7f8c7d8fa9a92-01',
		),
		'credentials'     => array( 'username' => OBS_TEST_CREDENTIAL, 'password' => OBS_TEST_SECRET ),
		'recipient_cnpj'  => OBS_TEST_CNPJ,
		'destination_cep' => '22041001',
	)
);

obs_publish( OBS_TEST_BRASPRESS, $leaky, 900 );
$serialized = wp_json_encode( $GLOBALS['obs_test_options'] );

obs_assert(
	'A falha foi contada na categoria declarada',
	1 === ( papelito_shipping_provider_metrics_report()['quote'][ OBS_TEST_BRASPRESS ]['outcomes']['provider_5xx'] ?? null )
);
foreach (
	array(
		'CNPJ do comprador'      => OBS_TEST_CNPJ,
		'usuário da credencial'  => OBS_TEST_CREDENTIAL,
		'senha da credencial'    => OBS_TEST_SECRET,
		'endereço do comprador'  => OBS_TEST_ADDRESS,
		'CEP de destino'         => '22041001',
		'mensagem do provider'   => 'PAGANTE',
		'traceId do provider'    => '9f5c4b01e8bdef25c4b23afe0367aeab',
	) as $label => $needle
) {
	obs_assert( "A option do contador não guarda {$label}", false === strpos( $serialized, $needle ) );
}

echo "\nCenário 4: o período soma dias civis sem perder o pior caso\n";
obs_reset( OBS_TEST_FIRST_DAY );

obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 4000 );
$GLOBALS['obs_test_day'] = OBS_TEST_SECOND_DAY;
obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 300 );
obs_publish( OBS_TEST_BRASPRESS, new WP_Error( 'papelito_braspress_network_error', 'A Braspress está temporariamente indisponível.', array( 'status' => 503, 'error_category' => 'network_error' ) ), 1200 );

$today  = papelito_shipping_provider_metrics_report();
$period = papelito_shipping_provider_metrics_report( OBS_TEST_FIRST_DAY, OBS_TEST_SECOND_DAY );

obs_assert(
	'Sem argumento o relatório fica no dia corrente',
	1 === ( $today['quote'][ OBS_TEST_BRASPRESS ]['success'] ?? null )
);
obs_assert(
	'O período soma os dois dias',
	2 === ( $period['quote'][ OBS_TEST_BRASPRESS ]['success'] ?? null )
	&& 1 === ( $period['quote'][ OBS_TEST_BRASPRESS ]['failures'] ?? null )
);
obs_assert(
	'A taxa de falha do período usa só tentativas reais',
	33.33 === ( $period['quote'][ OBS_TEST_BRASPRESS ]['failure_percent'] ?? null )
);
obs_assert(
	'O pior caso do período vem do dia mais lento',
	4000 === ( $period['quote'][ OBS_TEST_BRASPRESS ]['latency']['max_ms'] ?? null )
	&& 3 === ( $period['quote'][ OBS_TEST_BRASPRESS ]['latency']['count'] ?? null )
);

echo "\nCenário 5: a orquestração publica o desfecho de cada provider por conta própria\n";
obs_reset();

$GLOBALS['obs_test_correios']    = obs_success_result();
$GLOBALS['obs_test_integration']  = obs_integration();
$GLOBALS['obs_test_braspress']   = new WP_Error( 'papelito_braspress_timeout', 'A Braspress está temporariamente indisponível.', array( 'status' => 504, 'error_category' => 'timeout' ) );

$quote  = papelito_shipping_quote_all_providers( OBS_TEST_VENDOR_ID, OBS_TEST_DESTINATION_CEP, array(), obs_quote_context() );
$report = papelito_shipping_provider_metrics_report();

obs_assert(
	'A falha da Braspress não apaga a opção dos Correios',
	is_array( $quote ) && 1 === count( $quote['options'] ?? array() )
);
obs_assert(
	'A cotação dos Correios foi contada como sucesso',
	1 === ( $report['quote'][ OBS_TEST_CORREIOS ]['success'] ?? null )
);
obs_assert(
	'O timeout da Braspress foi contado na própria categoria',
	1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['outcomes']['timeout'] ?? null )
);
obs_assert(
	'Cada provider mediu a própria latência',
	1 === ( $report['quote'][ OBS_TEST_CORREIOS ]['latency']['count'] ?? null )
	&& 1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['latency']['count'] ?? null )
);

echo "\nCenário 6: Braspress inelegível não conta como falha nem toca nos Correios\n";
obs_reset();

$GLOBALS['obs_test_correios']    = obs_success_result();
$GLOBALS['obs_test_integration'] = null;
$GLOBALS['obs_test_braspress']   = null;

$quote  = papelito_shipping_quote_all_providers( OBS_TEST_VENDOR_ID, OBS_TEST_DESTINATION_CEP, array(), obs_quote_context() );
$report = papelito_shipping_provider_metrics_report();

obs_assert(
	'Os Correios continuam cotando com a Braspress fora',
	is_array( $quote ) && 1 === count( $quote['options'] ?? array() )
);
obs_assert(
	'Inelegibilidade entra como skipped, não infla a taxa de falha',
	1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['skipped'] ?? null )
	&& 0 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['failures'] ?? null )
	&& 0.0 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['failure_percent'] ?? null )
);

echo "\nCenário 7: indisponibilidade repetida tira a Braspress do caminho do checkout\n";
obs_reset();

$GLOBALS['obs_test_correios']    = obs_success_result();
$GLOBALS['obs_test_integration'] = obs_integration();
$GLOBALS['obs_test_braspress']   = new WP_Error( 'papelito_braspress_timeout', 'A Braspress está temporariamente indisponível.', array( 'status' => 504, 'error_category' => 'timeout' ) );
$GLOBALS['obs_test_calls']       = 0;

for ( $attempt = 0; $attempt < PAPELITO_SHIPPING_BREAKER_THRESHOLD; $attempt++ ) {
	papelito_shipping_quote_all_providers( OBS_TEST_VENDOR_ID, OBS_TEST_DESTINATION_CEP, array(), obs_quote_context() );
}

$calls_before = $GLOBALS['obs_test_calls'];
$quote        = papelito_shipping_quote_all_providers( OBS_TEST_VENDOR_ID, OBS_TEST_DESTINATION_CEP, array(), obs_quote_context() );
$report       = papelito_shipping_provider_metrics_report();

obs_assert(
	'Depois do limite a Braspress deixa de ser chamada',
	PAPELITO_SHIPPING_BREAKER_THRESHOLD === $calls_before && $calls_before === $GLOBALS['obs_test_calls']
);
obs_assert(
	'O checkout continua recebendo a opção dos Correios',
	is_array( $quote ) && 1 === count( $quote['options'] ?? array() )
);
obs_assert(
	'A tentativa barrada é contada como skipped, não como falha nova',
	PAPELITO_SHIPPING_BREAKER_THRESHOLD === ( $report['quote'][ OBS_TEST_BRASPRESS ]['failures'] ?? null )
	&& 1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['skipped'] ?? null )
);
obs_assert(
	'Os Correios cotaram todas as vezes, inclusive com a Braspress barrada',
	PAPELITO_SHIPPING_BREAKER_THRESHOLD + 1 === ( $report['quote'][ OBS_TEST_CORREIOS ]['success'] ?? null )
);

echo "\nCenário 8: o rastreio conta por provider no mesmo vocabulário, sem se misturar à cotação\n";
obs_reset();

obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 300 );
do_action( 'papelito_tracking_poll_result', OBS_TEST_BRASPRESS, false, '' );
do_action( 'papelito_tracking_poll_result', OBS_TEST_BRASPRESS, true, 'braspress_tracking_not_found' );
do_action( 'papelito_tracking_poll_result', OBS_TEST_BRASPRESS, true, 'papelito_braspress_timeout' );
do_action( 'papelito_tracking_poll_result', OBS_TEST_CORREIOS, false, '' );

$report = papelito_shipping_provider_metrics_report();

obs_assert(
	'O rastreio bem-sucedido é contado no balde do provider',
	1 === ( $report['tracking'][ OBS_TEST_BRASPRESS ]['success'] ?? null )
	&& 1 === ( $report['tracking'][ OBS_TEST_CORREIOS ]['success'] ?? null )
);
obs_assert(
	'Conhecimento ainda não emitido não vira falha de transportadora',
	1 === ( $report['tracking'][ OBS_TEST_BRASPRESS ]['outcomes']['not_found'] ?? null )
);
obs_assert(
	'Falha de rede no rastreio entra na categoria da taxonomia',
	1 === ( $report['tracking'][ OBS_TEST_BRASPRESS ]['outcomes']['timeout'] ?? null )
);
obs_assert(
	'O rastreio não contamina o contador de cotação',
	1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['success'] ?? null )
	&& 0 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['outcomes']['timeout'] ?? null )
);
obs_assert(
	'O rastreio não mede latência, e o relatório diz isso em vez de mentir zero',
	! array_key_exists( 'latency', $report['tracking'][ OBS_TEST_BRASPRESS ] )
);

echo "\nCenário 9: cotação descartada por configuração obsoleta não vira opção nula\n";
obs_reset();

$GLOBALS['obs_test_correios']    = obs_success_result();
$GLOBALS['obs_test_integration'] = obs_integration();
$GLOBALS['obs_test_braspress']   = null;

$quote  = papelito_shipping_quote_all_providers( OBS_TEST_VENDOR_ID, OBS_TEST_DESTINATION_CEP, array(), obs_quote_context() );
$report = papelito_shipping_provider_metrics_report();

obs_assert(
	'O checkout recebe só a opção dos Correios, sem entrada vazia',
	is_array( $quote ) && 1 === count( $quote['options'] ?? array() )
	&& is_array( $quote['options'][0] ?? null )
);
obs_assert(
	'O descarte é contado como skipped, não como sucesso nem como falha',
	1 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['skipped'] ?? null )
	&& 0 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['success'] ?? null )
	&& 0 === ( $report['quote'][ OBS_TEST_BRASPRESS ]['failures'] ?? null )
);

echo "\nCenário 10: cache hit conta como sucesso mas não entra no histograma de latência\n";
obs_reset();

do_action( 'papelito_braspress_quote_cache_result', OBS_TEST_VENDOR_ID, 'miss' );
obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 4200 );

do_action( 'papelito_braspress_quote_cache_result', OBS_TEST_VENDOR_ID, 'hit' );
obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 1 );

$report  = papelito_shipping_provider_metrics_report();
$quote   = $report['quote'][ OBS_TEST_BRASPRESS ] ?? array();
$latency = $quote['latency'] ?? array();

obs_assert(
	'As duas cotações contam como sucesso para o comprador',
	2 === ( $quote['outcomes']['success'] ?? null )
);
obs_assert(
	'Só a que falou com a Braspress entra na latência',
	1 === ( $latency['count'] ?? null ) && 4200 === ( $latency['total_ms'] ?? null )
);
obs_assert(
	'E o cache não puxa a média para baixo',
	4200 === ( $latency['average_ms'] ?? null )
);

echo "\nCenário 11: a marca de cache não vaza para a cotação seguinte\n";
obs_reset();

do_action( 'papelito_braspress_quote_cache_result', OBS_TEST_VENDOR_ID, 'hit' );
obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 5 );
obs_publish( OBS_TEST_BRASPRESS, obs_success_result(), 900 );

$latency = papelito_shipping_provider_metrics_report()['quote'][ OBS_TEST_BRASPRESS ]['latency'] ?? array();

obs_assert(
	'A cotação seguinte volta a ser medida',
	1 === ( $latency['count'] ?? null ) && 900 === ( $latency['total_ms'] ?? null )
);

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
