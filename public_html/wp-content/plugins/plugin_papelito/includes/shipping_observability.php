<?php
/**
 * Observabilidade de frete por provider: desfecho, latência e alerta.
 *
 * Responde a uma pergunta que o log sozinho não responde: a Braspress está
 * quieta porque ninguém cotou, porque a rota não é atendida ou porque a conta
 * parou de autenticar. Sem essa separação toda indisponibilidade externa chega
 * ao suporte como "não foi possível cotar o frete".
 *
 * O módulo é passageiro no caminho de cotação, nunca condutor: só escuta
 * `papelito_shipping_provider_quote_result` e `papelito_tracking_poll_result`, e
 * qualquer falha de escrita morre no contador, como em `shipping_metrics.php`.
 * Quem **conduz** é `shipping_breaker.php`, que consome o mesmo evento e decide
 * não chamar. O armazenamento reusa o mesmo padrão — uma option por dia civil,
 * sem autoload, lida por operação e não por requisição — e as funções de dia
 * civil são as de lá, não uma segunda cópia.
 *
 * Cotação e rastreio contam em baldes separados, com o mesmo vocabulário. Só a
 * cotação mede latência: o poll roda em cron e o tempo dele não é risco de
 * checkout, então o relatório omite a chave em vez de reportar zero.
 *
 * A cardinalidade é deliberadamente baixa: provider × desfecho, com vocabulário
 * fechado. Vendor **não** entra no contador; ele entra no log, na auditoria e no
 * alerta, que é onde a pergunta "qual loja" é feita. Contador por vendor cresce
 * com o marketplace e nunca é lido vendor a vendor.
 *
 * Nada aqui grava CNPJ, credencial, endereço, CEP ou corpo do provider: o
 * classificador reduz o `WP_Error` a uma palavra do vocabulário e descarta
 * mensagem e `data`.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_PROVIDER_METRICS_OPTION_PREFIX = 'papelito_shipping_provider_metrics_';
const PAPELITO_PROVIDER_METRICS_SUCCESS       = 'success';
const PAPELITO_PROVIDER_METRICS_SKIPPED       = 'skipped';
const PAPELITO_PROVIDER_METRICS_UNKNOWN       = 'unknown_error';
const PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME = 'outcomes';
const PAPELITO_PROVIDER_METRICS_GROUP_LATENCY = 'latency';
const PAPELITO_PROVIDER_METRICS_QUOTE         = 'quote';
const PAPELITO_PROVIDER_METRICS_TRACKING      = 'tracking';
const PAPELITO_PROVIDER_METRICS_LATENCY_OVER  = 'over';
const PAPELITO_PROVIDER_METRICS_LATENCY_MAX   = 900000;

/**
 * Fronteiras dos baldes de latência, em milissegundos.
 *
 * Histograma de largura fixa em vez de lista de amostras: percentil exato
 * exigiria guardar cada cotação, e uma linha por cotação é justamente o que o
 * padrão de `shipping_metrics.php` recusa. Os baldes respondem à pergunta
 * operacional real — quantas cotações passaram de 5 s — sem guardar nada por
 * requisição. O último balde é aberto e o timeout de 15 s do cliente cai nele.
 */
const PAPELITO_PROVIDER_METRICS_LATENCY_BUCKETS = array( 250, 500, 1000, 2500, 5000, 10000 );

/**
 * Categorias de falha do vocabulário de `08-error-handling-and-observability.md`.
 *
 * É mais largo que `PAPELITO_SHIPPING_FAILURE_CATEGORIES` de propósito: a
 * agregação pública funde credencial recusada e conta bloqueada em
 * `provider_4xx` para não contar nada ao comprador, e é justamente essa fusão
 * que impede o operador de distinguir "atualize a senha" de "a rota não é
 * atendida". A observabilidade precisa das três separadas.
 */
const PAPELITO_PROVIDER_METRICS_FAILURES = array(
	'configuration_error',
	'validation_error',
	'authentication_error',
	'not_available',
	'provider_account_blocked',
	'rate_limited',
	'timeout',
	'network_error',
	'provider_4xx',
	'provider_5xx',
	'invalid_response',
	'secret_store_error',
	'not_found',
	PAPELITO_PROVIDER_METRICS_UNKNOWN,
);

/**
 * Operações observadas, e quais delas medem latência.
 *
 * O rastreio não mede: ele roda em cron, e o tempo de um poll não é risco de
 * checkout. Relatar `0 ms` ali seria pior do que não relatar, porque parece
 * medição e não é. A cotação mede porque o comprador espera por ela.
 */
const PAPELITO_PROVIDER_METRICS_OPERATIONS = array( PAPELITO_PROVIDER_METRICS_QUOTE, PAPELITO_PROVIDER_METRICS_TRACKING );

/**
 * Códigos de rastreio cuja categoria não se deduz do nome.
 *
 * Conhecimento ainda não emitido é o caso que mais importa separar: ele é
 * esperado na janela inicial da postagem e contá-lo como falha da transportadora
 * transformaria operação normal em alarme.
 */
const PAPELITO_PROVIDER_METRICS_TRACKING_CODES = array(
	'braspress_tracking_not_found'      => 'not_found',
	'tracking_not_found'                => 'not_found',
	'tracking_code_mismatch'            => 'invalid_response',
	'tracking_provider_unsupported'     => 'configuration_error',
	'braspress_integration_unavailable' => 'configuration_error',
);

/**
 * Vocabulário fechado dos desfechos contados, na ordem de leitura do relatório.
 *
 * @return array<int,string> Desfechos aceitos.
 */
function papelito_shipping_provider_metrics_outcomes(): array {
	return array_merge(
		array( PAPELITO_PROVIDER_METRICS_SUCCESS, PAPELITO_PROVIDER_METRICS_SKIPPED ),
		PAPELITO_PROVIDER_METRICS_FAILURES
	);
}

/**
 * Providers contados, com o vocabulário fechado herdado da orquestração.
 *
 * @return array<int,string> Identificadores de provider.
 */
function papelito_shipping_provider_metrics_providers(): array {
	return defined( 'PAPELITO_SHIPPING_PROVIDERS' ) ? PAPELITO_SHIPPING_PROVIDERS : array( 'correios', 'braspress' );
}

/**
 * Nome da option do balde de um dia civil.
 *
 * @param string $day Dia no formato `Y-m-d`, já normalizado.
 * @return string Nome da option, sempre sem autoload na escrita.
 */
function papelito_shipping_provider_metrics_option_key( string $day ): string {
	return PAPELITO_PROVIDER_METRICS_OPTION_PREFIX . $day;
}

/**
 * Lê a categoria que o próprio adapter declarou no erro.
 *
 * O adapter Braspress já resolveu credencial recusada, conta bloqueada e rota
 * não atendida em `error_category`; reclassificar pelo código público perderia
 * essa decisão, porque a agregação funde as três.
 *
 * @param WP_Error $error Erro devolvido por um provider.
 * @return string Categoria declarada, ou vazio quando o erro não declara uma.
 */
function papelito_shipping_provider_metrics_declared_category( WP_Error $error ): string {
	$data     = $error->get_error_data();
	$declared = is_array( $data ) && is_scalar( $data['error_category'] ?? null )
		? sanitize_key( (string) $data['error_category'] )
		: '';

	return in_array( $declared, PAPELITO_PROVIDER_METRICS_FAILURES, true ) ? $declared : '';
}

/**
 * Reduz o resultado bruto de um provider a uma palavra do vocabulário fechado.
 *
 * É aqui que mensagem, `data` e corpo do provider param: só a categoria
 * atravessa para o contador.
 *
 * @param string $provider Identificador do provider.
 * @param mixed  $result Resultado bruto devolvido pelo provider.
 * @return string Desfecho aceito.
 */
function papelito_shipping_provider_metrics_classify( string $provider, mixed $result ): string {
	if ( null === $result ) {
		return PAPELITO_PROVIDER_METRICS_SKIPPED;
	}
	if ( is_array( $result ) ) {
		return PAPELITO_PROVIDER_METRICS_SUCCESS;
	}
	if ( ! is_wp_error( $result ) ) {
		return 'invalid_response';
	}

	$declared = papelito_shipping_provider_metrics_declared_category( $result );
	if ( '' !== $declared ) {
		return $declared;
	}

	$category = function_exists( 'papelito_shipping_failure_category' )
		? papelito_shipping_failure_category( sanitize_key( $provider ), (string) $result->get_error_code() )
		: PAPELITO_PROVIDER_METRICS_UNKNOWN;

	return in_array( $category, PAPELITO_PROVIDER_METRICS_FAILURES, true ) ? $category : PAPELITO_PROVIDER_METRICS_UNKNOWN;
}

/**
 * Reduz o provider recebido ao vocabulário fechado da orquestração.
 *
 * @param mixed $provider Provider publicado pela action.
 * @return string Provider aceito, ou vazio quando ele não é conhecido.
 */
function papelito_shipping_provider_metrics_normalize_provider( mixed $provider ): string {
	$normalized = sanitize_key( is_string( $provider ) ? $provider : '' );

	return in_array( $normalized, papelito_shipping_provider_metrics_providers(), true ) ? $normalized : '';
}

/**
 * Balde zerado com todo o vocabulário presente.
 *
 * Semear as chaves em zero é o que deixa o relatório afirmar "nenhum timeout"
 * em vez de omitir a linha, e o que dispensa o consumidor de conhecer o
 * vocabulário.
 *
 * @param string $day Dia civil do balde.
 * @return array<string,mixed> Balde com todos os providers zerados.
 */
function papelito_shipping_provider_metrics_empty_bucket( string $day ): array {
	$bucket = array( 'day' => $day );
	foreach ( papelito_shipping_provider_metrics_providers() as $provider ) {
		foreach ( PAPELITO_PROVIDER_METRICS_OPERATIONS as $operation ) {
			$bucket[ $provider ][ $operation ] = papelito_shipping_provider_metrics_empty_operation( $operation );
		}
	}

	return $bucket;
}

/**
 * Balde zerado de uma operação, com latência só onde ela é medida.
 *
 * @param string $operation Operação observada.
 * @return array<string,mixed> Balde da operação.
 */
function papelito_shipping_provider_metrics_empty_operation( string $operation ): array {
	$empty = array( PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME => array_fill_keys( papelito_shipping_provider_metrics_outcomes(), 0 ) );
	if ( PAPELITO_PROVIDER_METRICS_QUOTE === $operation ) {
		$empty[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ] = papelito_shipping_provider_metrics_empty_latency();
	}

	return $empty;
}

/**
 * Agregado de latência zerado, com todos os baldes presentes.
 *
 * @return array<string,mixed> Agregado sem nenhuma amostra.
 */
function papelito_shipping_provider_metrics_empty_latency(): array {
	$buckets = array_fill_keys( array_map( 'strval', PAPELITO_PROVIDER_METRICS_LATENCY_BUCKETS ), 0 );
	$buckets[ PAPELITO_PROVIDER_METRICS_LATENCY_OVER ] = 0;

	return array(
		'count'    => 0,
		'total_ms' => 0,
		'max_ms'   => 0,
		'buckets'  => $buckets,
	);
}

/**
 * Nome do balde de latência que comporta uma duração.
 *
 * @param int $duration_ms Duração já normalizada.
 * @return string Chave do balde.
 */
function papelito_shipping_provider_metrics_latency_bucket( int $duration_ms ): string {
	foreach ( PAPELITO_PROVIDER_METRICS_LATENCY_BUCKETS as $boundary ) {
		if ( $duration_ms <= $boundary ) {
			return (string) $boundary;
		}
	}

	return PAPELITO_PROVIDER_METRICS_LATENCY_OVER;
}

/**
 * Soma uma duração no agregado de latência de um provider.
 *
 * @param array<string,mixed> $latency Agregado corrente.
 * @param int                 $duration_ms Duração já normalizada.
 * @return array<string,mixed> Agregado somado.
 */
function papelito_shipping_provider_metrics_merge_latency( array $latency, int $duration_ms ): array {
	$bucket = papelito_shipping_provider_metrics_latency_bucket( $duration_ms );

	$latency['count']              = (int) ( $latency['count'] ?? 0 ) + 1;
	$latency['total_ms']           = (int) ( $latency['total_ms'] ?? 0 ) + $duration_ms;
	$latency['max_ms']             = max( (int) ( $latency['max_ms'] ?? 0 ), $duration_ms );
	$latency['buckets'][ $bucket ] = (int) ( $latency['buckets'][ $bucket ] ?? 0 ) + 1;

	return $latency;
}

/**
 * Lê o balde de um dia, devolvendo zeros quando ele não existe.
 *
 * @param string $day Dia civil já normalizado.
 * @return array<string,mixed> Balde do dia.
 */
function papelito_shipping_provider_metrics_read_bucket( string $day ): array {
	$stored = get_option( papelito_shipping_provider_metrics_option_key( $day ), null );

	return is_array( $stored ) ? $stored : papelito_shipping_provider_metrics_empty_bucket( $day );
}

/**
 * Apaga os baldes que saíram da janela de retenção.
 *
 * @param string $day Dia civil que acabou de abrir.
 * @return void
 */
function papelito_shipping_provider_metrics_prune( string $day ): void {
	for ( $offset = 0; $offset < PAPELITO_SHIPPING_METRICS_PRUNE_WINDOW; $offset++ ) {
		$expired = papelito_shipping_metrics_shift_day( $day, -( PAPELITO_SHIPPING_METRICS_RETENTION_DAYS + $offset ) );
		delete_option( papelito_shipping_provider_metrics_option_key( $expired ) );
	}
}

/**
 * Soma um desfecho no balde do dia corrente, engolindo qualquer falha de escrita.
 *
 * É o único escritor do módulo e o único ponto que pode falhar dentro do
 * caminho de cotação. O `catch` é amplo por decisão: nenhuma exceção de
 * contador tem o direito de virar erro de frete para o comprador.
 *
 * @param string $provider Provider já normalizado.
 * @param string $operation Operação observada.
 * @param string $outcome Desfecho já normalizado.
 * @param int    $duration_ms Duração já normalizada, ou negativa para não medir.
 * @return bool Se a contagem foi persistida.
 */
function papelito_shipping_provider_metrics_increment( string $provider, string $operation, string $outcome, int $duration_ms = -1 ): bool {
	try {
		$day    = papelito_shipping_metrics_today();
		$option = papelito_shipping_provider_metrics_option_key( $day );
		$stored = get_option( $option, null );
		if ( ! is_array( $stored ) ) {
			$stored = papelito_shipping_provider_metrics_empty_bucket( $day );
			papelito_shipping_provider_metrics_prune( $day );
		}

		$bucket = is_array( $stored[ $provider ][ $operation ] ?? null )
			? $stored[ $provider ][ $operation ]
			: papelito_shipping_provider_metrics_empty_operation( $operation );

		$bucket[ PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME ][ $outcome ] = (int) ( $bucket[ PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME ][ $outcome ] ?? 0 ) + 1;
		if ( $duration_ms >= 0 && isset( $bucket[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ] ) ) {
			$bucket[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ] = papelito_shipping_provider_metrics_merge_latency(
				$bucket[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ],
				$duration_ms
			);
		}

		$stored[ $provider ][ $operation ] = $bucket;

		return (bool) update_option( $option, $stored, false );
	} catch ( Throwable $error ) {
		return false;
	}
}

/**
 * Conta o desfecho de uma cotação publicada pela orquestração.
 *
 * O vendor publicado pela action não entra aqui de propósito: o contador é
 * agregado, e quem precisa saber de qual loja se trata lê o log, a auditoria ou
 * o alerta.
 *
 * @param mixed $provider Provider publicado pela action.
 * @param mixed $result Resultado bruto do provider.
 * @param mixed $duration_ms Duração da tentativa em milissegundos.
 * @return void
 */
function papelito_shipping_provider_metrics_record( mixed $provider, mixed $result, mixed $duration_ms = 0 ): void {
	$from_cache = papelito_shipping_provider_metrics_cache_mark();
	$normalized = papelito_shipping_provider_metrics_normalize_provider( $provider );
	if ( '' === $normalized ) {
		return;
	}

	$outcome      = papelito_shipping_provider_metrics_classify( $normalized, $result );
	$unmeasurable = $from_cache || PAPELITO_PROVIDER_METRICS_SKIPPED === $outcome;
	$measured     = $unmeasurable
		? -1
		: min( PAPELITO_PROVIDER_METRICS_LATENCY_MAX, max( 0, (int) $duration_ms ) );

	papelito_shipping_provider_metrics_increment( $normalized, PAPELITO_PROVIDER_METRICS_QUOTE, $outcome, $measured );
}

/**
 * Marca — ou consome — a informação de que a cotação medida saiu do cache.
 *
 * A pergunta que a latência existe para responder é quantas cotações passaram
 * de cinco segundos **na transportadora**. Um acerto de cache volta em zero e
 * nunca encostou na Braspress; sob repetição de `/cart/pricing` os acertos
 * dominam a amostra e afundam a média de uma chamada que não aconteceu. O
 * desfecho continua `success`, porque o comprador de fato recebeu a opção — o
 * que sai é só a medida de tempo, pelo mesmo caminho que `skipped` já usava.
 *
 * A leitura é destrutiva de propósito: zerar ao consumir impede que a cotação
 * seguinte herde a marca da anterior. A ordem é determinística — o adapter
 * publica o desfecho do cache antes de a orquestração publicar o da tentativa,
 * e a orquestração cota um vendor por vez.
 *
 * @param bool|null $mark Verdadeiro marca cache; nulo consome a marca.
 * @return bool Se a cotação medida agora veio do cache.
 */
function papelito_shipping_provider_metrics_cache_mark( ?bool $mark = null ): bool {
	static $from_cache = false;

	if ( null !== $mark ) {
		$from_cache = $mark;

		return $from_cache;
	}

	$consumed   = $from_cache;
	$from_cache = false;

	return $consumed;
}

/**
 * Anota o desfecho do cache publicado pelo adapter da Braspress.
 *
 * @param mixed $vendor_id ID do vendor, não usado pelo contador agregado.
 * @param mixed $outcome `hit` ou `miss`.
 * @return void
 */
function papelito_shipping_provider_metrics_note_cache( mixed $vendor_id, mixed $outcome = '' ): void {
	papelito_shipping_provider_metrics_cache_mark( 'hit' === sanitize_key( (string) $outcome ) );
}

/**
 * Reduz um código de erro de rastreio a uma palavra do vocabulário fechado.
 *
 * @param string $provider Provider já normalizado.
 * @param string $code Código do erro devolvido pelo poller.
 * @return string Desfecho aceito.
 */
function papelito_shipping_provider_metrics_classify_code( string $provider, string $code ): string {
	$code = sanitize_key( $code );
	if ( isset( PAPELITO_PROVIDER_METRICS_TRACKING_CODES[ $code ] ) ) {
		return PAPELITO_PROVIDER_METRICS_TRACKING_CODES[ $code ];
	}

	$category = function_exists( 'papelito_shipping_failure_category' )
		? papelito_shipping_failure_category( $provider, $code )
		: PAPELITO_PROVIDER_METRICS_UNKNOWN;

	return in_array( $category, PAPELITO_PROVIDER_METRICS_FAILURES, true ) ? $category : PAPELITO_PROVIDER_METRICS_UNKNOWN;
}

/**
 * Conta o desfecho de um poll de rastreio publicado pelo agendador.
 *
 * O ponto de escuta é o agendamento do próximo poll porque é por onde os dois
 * pollers passam, com sucesso e com falha. Instrumentar cada poller deixaria a
 * próxima transportadora nascer sem métrica.
 *
 * @param mixed $provider Provider da remessa.
 * @param mixed $failed Se o poll falhou.
 * @param mixed $error_code Código do erro, vazio no sucesso.
 * @return void
 */
function papelito_shipping_provider_metrics_record_tracking( mixed $provider, mixed $failed, mixed $error_code = '' ): void {
	$normalized = papelito_shipping_provider_metrics_normalize_provider( $provider );
	if ( '' === $normalized ) {
		return;
	}

	$outcome = empty( $failed )
		? PAPELITO_PROVIDER_METRICS_SUCCESS
		: papelito_shipping_provider_metrics_classify_code( $normalized, (string) $error_code );

	papelito_shipping_provider_metrics_increment( $normalized, PAPELITO_PROVIDER_METRICS_TRACKING, $outcome );
}

/**
 * Soma um balde diário no acumulado do período.
 *
 * @param array<string,mixed> $totals Acumulado do período.
 * @param array<string,mixed> $bucket Balde de um dia.
 * @return array<string,mixed> Acumulado somado.
 */
function papelito_shipping_provider_metrics_merge_bucket( array $totals, array $bucket ): array {
	foreach ( papelito_shipping_provider_metrics_providers() as $provider ) {
		foreach ( PAPELITO_PROVIDER_METRICS_OPERATIONS as $operation ) {
			$totals[ $provider ][ $operation ] = papelito_shipping_provider_metrics_merge_operation(
				$totals[ $provider ][ $operation ],
				$bucket[ $provider ][ $operation ] ?? array()
			);
		}
	}

	return $totals;
}

/**
 * Soma o balde de uma operação num dia sobre o acumulado do período.
 *
 * @param array<string,mixed> $totals Acumulado da operação.
 * @param mixed               $day Balde da operação num dia.
 * @return array<string,mixed> Acumulado somado.
 */
function papelito_shipping_provider_metrics_merge_operation( array $totals, mixed $day ): array {
	$day      = is_array( $day ) ? $day : array();
	$outcomes = is_array( $day[ PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME ] ?? null ) ? $day[ PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME ] : array();

	foreach ( $totals[ PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME ] as $outcome => $count ) {
		$totals[ PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME ][ $outcome ] = $count + (int) ( $outcomes[ $outcome ] ?? 0 );
	}

	if ( isset( $totals[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ] ) ) {
		$totals[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ] = papelito_shipping_provider_metrics_sum_latency(
			$totals[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ],
			$day[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ] ?? array()
		);
	}

	return $totals;
}

/**
 * Soma dois agregados de latência sem reabrir amostras.
 *
 * O máximo do período é o maior dos máximos diários; a média sai da soma, não
 * da média das médias, que pesaria dias fracos igual a dias movimentados.
 *
 * @param array<string,mixed> $totals Acumulado do período.
 * @param mixed               $day Agregado de um dia.
 * @return array<string,mixed> Acumulado somado.
 */
function papelito_shipping_provider_metrics_sum_latency( array $totals, mixed $day ): array {
	if ( ! is_array( $day ) ) {
		return $totals;
	}

	$totals['count']    = (int) $totals['count'] + (int) ( $day['count'] ?? 0 );
	$totals['total_ms'] = (int) $totals['total_ms'] + (int) ( $day['total_ms'] ?? 0 );
	$totals['max_ms']   = max( (int) $totals['max_ms'], (int) ( $day['max_ms'] ?? 0 ) );
	foreach ( $totals['buckets'] as $bucket => $count ) {
		$totals['buckets'][ $bucket ] = $count + (int) ( $day['buckets'][ $bucket ] ?? 0 );
	}

	return $totals;
}

/**
 * Fecha o relatório de um provider com os totais derivados.
 *
 * @param array<string,mixed> $totals Acumulado do provider no período.
 * @return array<string,mixed> Bloco do provider.
 */
function papelito_shipping_provider_metrics_summarize_provider( array $totals ): array {
	$outcomes = $totals[ PAPELITO_PROVIDER_METRICS_GROUP_OUTCOME ];
	$failures = 0;
	foreach ( PAPELITO_PROVIDER_METRICS_FAILURES as $category ) {
		$failures += (int) ( $outcomes[ $category ] ?? 0 );
	}

	$success   = (int) ( $outcomes[ PAPELITO_PROVIDER_METRICS_SUCCESS ] ?? 0 );
	$attempted = $success + $failures;

	$summary = array(
		'outcomes'        => $outcomes,
		'success'         => $success,
		'skipped'         => (int) ( $outcomes[ PAPELITO_PROVIDER_METRICS_SKIPPED ] ?? 0 ),
		'failures'        => $failures,
		'attempted'       => $attempted,
		'failure_ratio'   => $attempted > 0 ? round( $failures / $attempted, 4 ) : 0.0,
		'failure_percent' => $attempted > 0 ? round( $failures / $attempted * 100, 2 ) : 0.0,
	);

	if ( ! isset( $totals[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ] ) ) {
		return $summary;
	}

	$latency               = $totals[ PAPELITO_PROVIDER_METRICS_GROUP_LATENCY ];
	$latency['average_ms'] = (int) $latency['count'] > 0
		? (int) round( (int) $latency['total_ms'] / (int) $latency['count'] )
		: 0;
	$summary['latency']    = $latency;

	return $summary;
}

/**
 * Relatório de desfecho de cotação por provider, agregado por período.
 *
 * Sem argumento, o período é o dia civil corrente. O recorte é por tentativa de
 * provider, não por carrinho: um carrinho cotado nos dois providers aparece uma
 * vez em cada bloco.
 *
 * @param string $from Primeiro dia do período (`Y-m-d`); vazio usa `$to`.
 * @param string $to Último dia do período (`Y-m-d`); vazio usa hoje.
 * @return array<string,mixed> Relatório agregado.
 */
function papelito_shipping_provider_metrics_report( string $from = '', string $to = '' ): array {
	$end   = papelito_shipping_metrics_normalize_day( $to, papelito_shipping_metrics_today() );
	$start = papelito_shipping_metrics_normalize_day( $from, $end );
	if ( $start > $end ) {
		$start = $end;
	}

	$totals = papelito_shipping_provider_metrics_empty_bucket( '' );
	foreach ( papelito_shipping_metrics_day_range( $start, $end ) as $day ) {
		$totals = papelito_shipping_provider_metrics_merge_bucket( $totals, papelito_shipping_provider_metrics_read_bucket( $day ) );
	}

	$report = array(
		'from' => $start,
		'to'   => $end,
	);
	foreach ( PAPELITO_PROVIDER_METRICS_OPERATIONS as $operation ) {
		foreach ( papelito_shipping_provider_metrics_providers() as $provider ) {
			$report[ $operation ][ $provider ] = papelito_shipping_provider_metrics_summarize_provider( $totals[ $provider ][ $operation ] );
		}
	}

	return $report;
}

/**
 * Campos que um alerta operacional pode carregar.
 *
 * Allowlist em vez de blocklist: campo novo só chega ao alerta se alguém o
 * acrescentar aqui de propósito. É o que impede que uma chave a mais no contexto
 * — a credencial decifrada, o CNPJ, o corpo do provider — vaze porque ninguém
 * lembrou de removê-la.
 */
const PAPELITO_PROVIDER_ALERT_FIELDS = array( 'vendor_id', 'previous_state', 'error_category' );

/**
 * Reduz o contexto de um alerta aos campos permitidos, já saneados.
 *
 * @param array<string,mixed> $context Contexto cru do emissor.
 * @return array<string,mixed> Contexto seguro.
 */
function papelito_shipping_provider_alert_context( array $context ): array {
	$safe = array();
	foreach ( PAPELITO_PROVIDER_ALERT_FIELDS as $field ) {
		if ( ! isset( $context[ $field ] ) ) {
			continue;
		}

		$safe[ $field ] = 'vendor_id' === $field
			? absint( $context[ $field ] )
			: sanitize_key( (string) $context[ $field ] );
	}

	return $safe;
}

/**
 * Publica e registra um alerta de saúde de provider, sem segredo nem PII.
 *
 * O vendor é identificado pelo ID interno e nunca pelo CNPJ: quem recebe o
 * alerta tem acesso ao painel, onde o ID resolve a loja, e um documento num
 * canal de alerta sobrevive a qualquer retenção que o Papelito controle.
 *
 * @param string              $provider Identificador do provider.
 * @param string              $state Estado operacional alcançado.
 * @param array<string,mixed> $context Contexto do alerta, filtrado pela allowlist.
 * @return void
 */
function papelito_shipping_provider_alert( string $provider, string $state, array $context = array() ): void {
	$provider = sanitize_key( $provider );
	$state    = sanitize_key( $state );
	$safe     = papelito_shipping_provider_alert_context( $context );

	do_action( 'papelito_shipping_provider_alert', $provider, $state, $safe );

	error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		'papelito_shipping_alert ' . wp_json_encode(
			array_merge(
				array(
					'provider' => $provider,
					'state'    => $state,
				),
				$safe
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		)
	);
}

add_action( 'papelito_shipping_provider_quote_result', 'papelito_shipping_provider_metrics_record', 10, 3 );
add_action( 'papelito_braspress_quote_cache_result', 'papelito_shipping_provider_metrics_note_cache', 10, 2 );
add_action( 'papelito_tracking_poll_result', 'papelito_shipping_provider_metrics_record_tracking', 10, 3 );
