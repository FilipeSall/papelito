<?php
/**
 * Métrica de resíduo de embalagem: quanto da cotação ainda sai de medida não cadastrada.
 *
 * A regra de ouro é que este módulo é passageiro no caminho de cotação, nunca
 * condutor: ele só escuta `papelito_shipping_package_built` e
 * `papelito_shipping_package_rejected`, e qualquer falha de escrita morre aqui.
 * Um contador que derruba a cotação custa venda; um contador que perde uma
 * contagem custa precisão de uma tendência, e a troca é deliberada.
 *
 * O armazenamento é uma option por dia civil, sem autoload. Option porque o
 * número é lido exatamente quando a operação está sob pressão — decidir o prazo
 * da política C, ver se o cadastro de caixas avançou — e transient pode ser
 * evictado justamente aí. Sem autoload porque o contador não tem por que
 * aparecer em toda requisição do site. Uma option por dia em vez de um mapa
 * único isola a escrita de hoje do número de ontem: a soma é read-modify-write,
 * igual à de `papelito_rate_limit()`, e pode perder incremento sob
 * concorrência. Perda é aceita e nunca retroage sobre um dia fechado.
 *
 * Este módulo deliberadamente **não** grava linha por cotação, não guarda
 * vendor, CEP, documento, preço nem `physical_hash`, e não expõe rota REST:
 * origem da medida e código de recusa são vocabulário fechado, e quem lê é
 * operação por `papelito_shipping_metrics_report()`.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_SHIPPING_METRICS_OPTION_PREFIX   = 'papelito_shipping_measure_metrics_';
const PAPELITO_SHIPPING_METRICS_SOURCE_UNKNOWN  = 'unknown';
const PAPELITO_SHIPPING_METRICS_REJECTION_OTHER = 'other';
const PAPELITO_SHIPPING_METRICS_GROUP_BUILT     = 'built';
const PAPELITO_SHIPPING_METRICS_GROUP_REJECTED  = 'rejected';
const PAPELITO_SHIPPING_METRICS_RETENTION_DAYS  = 400;
const PAPELITO_SHIPPING_METRICS_PRUNE_WINDOW    = 30;
const PAPELITO_SHIPPING_METRICS_MAX_RANGE_DAYS  = 400;

/*
 * Recusas que nascem fora de PAPELITO_SHIPPING_LOCAL_VALIDATION_CODES: a do
 * caminho de perfil, quando o vendor não tem caixa aplicável, e a do Kit que
 * perdeu o registro. Separá-las do carrinho grande demais é o ponto de contar
 * recusa — são pendências de cadastro, não de comportamento do comprador.
 */
const PAPELITO_SHIPPING_METRICS_EXTRA_REJECTIONS = array(
	'papelito_braspress_package_not_approved',
	'papelito_kit_not_found',
);

/**
 * Vocabulário fechado das origens de medida contadas.
 *
 * `unknown` existe para absorver pacote que chegue sem origem declarada — ele
 * nunca deveria subir, e se subir é defeito de produtor, não dado a investigar
 * no banco.
 *
 * @return array<int,string> Origens aceitas, na ordem de leitura do relatório.
 */
function papelito_shipping_metrics_sources(): array {
	return array(
		PAPELITO_SHIPPING_MEASUREMENT_PROFILE,
		PAPELITO_SHIPPING_MEASUREMENT_LEGACY,
		PAPELITO_SHIPPING_MEASUREMENT_KIT,
		PAPELITO_SHIPPING_METRICS_SOURCE_UNKNOWN,
	);
}

/**
 * Origens que contam como resíduo, isto é, medida não cadastrada.
 *
 * Pacote sintético e embalagem declarada pelo Kit são os dois; eles não são a
 * mesma coisa e o relatório não os funde, mas os dois somam no numerador.
 *
 * @return array<int,string> Origens de resíduo.
 */
function papelito_shipping_metrics_residue_sources(): array {
	return array( PAPELITO_SHIPPING_MEASUREMENT_LEGACY, PAPELITO_SHIPPING_MEASUREMENT_KIT );
}

/**
 * Vocabulário fechado dos códigos de recusa contados.
 *
 * Reaproveita `PAPELITO_SHIPPING_LOCAL_VALIDATION_CODES` para que validação
 * local nova entre na métrica sem edição aqui. O `defined()` existe porque este
 * módulo carrega antes de `shipping_providers.php` e um contador não pode
 * depender de ordem de include; código fora da lista cai em `other`.
 *
 * @return array<int,string> Códigos aceitos, na ordem de leitura do relatório.
 */
function papelito_shipping_metrics_rejection_codes(): array {
	$local = defined( 'PAPELITO_SHIPPING_LOCAL_VALIDATION_CODES' ) ? PAPELITO_SHIPPING_LOCAL_VALIDATION_CODES : array();

	return array_merge(
		$local,
		PAPELITO_SHIPPING_METRICS_EXTRA_REJECTIONS,
		array( PAPELITO_SHIPPING_METRICS_REJECTION_OTHER )
	);
}

/**
 * Nome da option do balde de um dia civil.
 *
 * @param string $day Dia no formato `Y-m-d`, já normalizado.
 * @return string Nome da option, sempre sem autoload na escrita.
 */
function papelito_shipping_metrics_option_key( string $day ): string {
	return PAPELITO_SHIPPING_METRICS_OPTION_PREFIX . $day;
}

/**
 * Dia civil corrente do site, que é o recorte do balde.
 *
 * Usa o fuso configurado no WordPress, e não UTC, porque quem lê o número
 * fecha o dia no horário da operação.
 *
 * @return string Dia no formato `Y-m-d`.
 */
function papelito_shipping_metrics_today(): string {
	return (string) current_time( 'Y-m-d' );
}

/**
 * Desloca um dia civil por uma quantidade de dias.
 *
 * @param string $day Dia base no formato `Y-m-d`.
 * @param int    $days Deslocamento, negativo para trás.
 * @return string Dia deslocado, ou o próprio dia quando ele não é interpretável.
 */
function papelito_shipping_metrics_shift_day( string $day, int $days ): string {
	$timestamp = strtotime( $day . ' UTC' );

	return false === $timestamp ? $day : gmdate( 'Y-m-d', $timestamp + $days * DAY_IN_SECONDS );
}

/**
 * Aceita apenas um dia civil real; qualquer outra entrada cai no padrão.
 *
 * O período do relatório vem de operação e de WP-CLI, então a validação é
 * fail-safe: entrada errada lê o padrão em vez de montar nome de option torto.
 *
 * @param string $day Dia cru.
 * @param string $fallback Dia usado quando o cru não serve.
 * @return string Dia no formato `Y-m-d`.
 */
function papelito_shipping_metrics_normalize_day( string $day, string $fallback ): string {
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
		return $fallback;
	}

	return papelito_shipping_metrics_shift_day( $day, 0 ) === $day ? $day : $fallback;
}

/**
 * Balde zerado com todo o vocabulário presente.
 *
 * Semear as chaves em zero é o que deixa o relatório afirmar "nenhuma cotação
 * de perfil" em vez de omitir a linha, e é o que faz o consumidor não precisar
 * conhecer o vocabulário.
 *
 * @param string $day Dia civil do balde.
 * @return array<string,mixed> Balde com `built` e `rejected` zerados.
 */
function papelito_shipping_metrics_empty_bucket( string $day ): array {
	$bucket = array( 'day' => $day );

	$bucket[ PAPELITO_SHIPPING_METRICS_GROUP_BUILT ]    = array_fill_keys( papelito_shipping_metrics_sources(), 0 );
	$bucket[ PAPELITO_SHIPPING_METRICS_GROUP_REJECTED ] = array_fill_keys( papelito_shipping_metrics_rejection_codes(), 0 );

	return $bucket;
}

/**
 * Lê o balde de um dia, devolvendo zeros quando ele não existe.
 *
 * @param string $day Dia civil já normalizado.
 * @return array<string,mixed> Balde do dia.
 */
function papelito_shipping_metrics_read_bucket( string $day ): array {
	$stored = get_option( papelito_shipping_metrics_option_key( $day ), null );

	return is_array( $stored ) ? $stored : papelito_shipping_metrics_empty_bucket( $day );
}

/**
 * Apaga os baldes que saíram da janela de retenção.
 *
 * Roda uma vez por dia, na criação do balde novo, e varre uma janela de dias em
 * vez de um só para cicatrizar período sem cotação. É `delete_option` em vez de
 * `LIKE` em `wp_options` de propósito: o custo é irrelevante e não há SQL direto.
 *
 * @param string $day Dia civil que acabou de abrir.
 * @return void
 */
function papelito_shipping_metrics_prune( string $day ): void {
	for ( $offset = 0; $offset < PAPELITO_SHIPPING_METRICS_PRUNE_WINDOW; $offset++ ) {
		$expired = papelito_shipping_metrics_shift_day( $day, -( PAPELITO_SHIPPING_METRICS_RETENTION_DAYS + $offset ) );
		delete_option( papelito_shipping_metrics_option_key( $expired ) );
	}
}

/**
 * Soma um no contador do dia corrente, engolindo qualquer falha de escrita.
 *
 * É o único escritor do módulo e o único ponto que pode falhar dentro do
 * caminho de cotação. O `catch` é amplo por decisão: cache de objetos de
 * terceiro pode lançar, e nenhuma exceção de contador tem o direito de virar
 * erro de frete para o comprador.
 *
 * @param string $group Grupo do balde (`built` ou `rejected`).
 * @param string $key Chave já normalizada no vocabulário fechado.
 * @return bool Se a contagem foi persistida.
 */
function papelito_shipping_metrics_increment( string $group, string $key ): bool {
	try {
		$day    = papelito_shipping_metrics_today();
		$option = papelito_shipping_metrics_option_key( $day );
		$stored = get_option( $option, null );
		if ( ! is_array( $stored ) ) {
			$stored = papelito_shipping_metrics_empty_bucket( $day );
			papelito_shipping_metrics_prune( $day );
		}

		$stored[ $group ][ $key ] = (int) ( $stored[ $group ][ $key ] ?? 0 ) + 1;

		return (bool) update_option( $option, $stored, false );
	} catch ( Throwable $error ) {
		return false;
	}
}

/**
 * Reduz a origem recebida ao vocabulário fechado.
 *
 * @param mixed $source Origem publicada pela action.
 * @return string Origem aceita, ou `unknown`.
 */
function papelito_shipping_metrics_normalize_source( mixed $source ): string {
	$normalized = is_string( $source ) ? $source : '';

	return in_array( $normalized, papelito_shipping_metrics_sources(), true )
		? $normalized
		: PAPELITO_SHIPPING_METRICS_SOURCE_UNKNOWN;
}

/**
 * Reduz a recusa recebida ao código do vocabulário fechado.
 *
 * Só o código do `WP_Error` entra; mensagem e `data` ficam de fora porque é lá
 * que mora o dado do comprador.
 *
 * @param mixed $error Erro publicado pela action.
 * @return string Código aceito, ou `other`.
 */
function papelito_shipping_metrics_normalize_rejection( mixed $error ): string {
	$code = is_wp_error( $error ) ? (string) $error->get_error_code() : '';

	return in_array( $code, papelito_shipping_metrics_rejection_codes(), true )
		? $code
		: PAPELITO_SHIPPING_METRICS_REJECTION_OTHER;
}

/**
 * Conta a origem da medida de um pacote que a cotação aprovou.
 *
 * @param mixed $source Origem publicada por `papelito_shipping_package_built`.
 * @return void
 */
function papelito_shipping_metrics_record_built( mixed $source ): void {
	papelito_shipping_metrics_increment(
		PAPELITO_SHIPPING_METRICS_GROUP_BUILT,
		papelito_shipping_metrics_normalize_source( $source )
	);
}

/**
 * Conta a recusa de um pacote pelo código do erro.
 *
 * @param mixed $error Erro publicado por `papelito_shipping_package_rejected`.
 * @return void
 */
function papelito_shipping_metrics_record_rejected( mixed $error ): void {
	papelito_shipping_metrics_increment(
		PAPELITO_SHIPPING_METRICS_GROUP_REJECTED,
		papelito_shipping_metrics_normalize_rejection( $error )
	);
}

/**
 * Soma um balde diário no acumulado do período.
 *
 * @param array<string,mixed> $totals Acumulado do período.
 * @param array<string,mixed> $bucket Balde de um dia.
 * @return array<string,mixed> Acumulado somado.
 */
function papelito_shipping_metrics_merge_bucket( array $totals, array $bucket ): array {
	foreach ( array( PAPELITO_SHIPPING_METRICS_GROUP_BUILT, PAPELITO_SHIPPING_METRICS_GROUP_REJECTED ) as $group ) {
		foreach ( $totals[ $group ] as $key => $count ) {
			$totals[ $group ][ $key ] = $count + (int) ( $bucket[ $group ][ $key ] ?? 0 );
		}
	}

	return $totals;
}

/**
 * Lista os dias civis do período, com teto para não varrer o banco à toa.
 *
 * @param string $from Primeiro dia, já normalizado.
 * @param string $to Último dia, já normalizado.
 * @return array<int,string> Dias no formato `Y-m-d`.
 */
function papelito_shipping_metrics_day_range( string $from, string $to ): array {
	$days      = array();
	$day       = $from;
	$remaining = PAPELITO_SHIPPING_METRICS_MAX_RANGE_DAYS;

	while ( $day <= $to && $remaining > 0 ) {
		$days[] = $day;
		$day    = papelito_shipping_metrics_shift_day( $day, 1 );
		--$remaining;
	}

	return $days;
}

/**
 * Soma as origens que contam como resíduo.
 *
 * @param array<string,int> $built Contagem por origem.
 * @return int Total de medida não cadastrada.
 */
function papelito_shipping_metrics_residue_total( array $built ): int {
	$total = 0;
	foreach ( papelito_shipping_metrics_residue_sources() as $source ) {
		$total += (int) ( $built[ $source ] ?? 0 );
	}

	return $total;
}

/**
 * Relatório de resíduo de embalagem por período.
 *
 * Devolve a contagem por origem, a contagem de recusa por código e o
 * percentual de resíduo — `(legacy_synthetic + kit_declared)` sobre o total de
 * pacotes montados. Período vazio devolve zero, nunca divisão por zero. Sem
 * argumento, o período é o dia civil corrente.
 *
 * A contagem é por pacote montado, não por carrinho: um carrinho cotado nos
 * dois providers conta duas vezes, com a origem de cada um. É o recorte que
 * responde à pergunta da política C — quanto da cotação ainda sai de medida
 * legada — e não serve para contar pedidos.
 *
 * @param string $from Primeiro dia do período (`Y-m-d`); vazio usa `$to`.
 * @param string $to Último dia do período (`Y-m-d`); vazio usa hoje.
 * @return array<string,mixed> Relatório agregado.
 */
function papelito_shipping_metrics_report( string $from = '', string $to = '' ): array {
	$end   = papelito_shipping_metrics_normalize_day( $to, papelito_shipping_metrics_today() );
	$start = papelito_shipping_metrics_normalize_day( $from, $end );
	if ( $start > $end ) {
		$start = $end;
	}

	$totals = papelito_shipping_metrics_empty_bucket( '' );
	foreach ( papelito_shipping_metrics_day_range( $start, $end ) as $day ) {
		$totals = papelito_shipping_metrics_merge_bucket( $totals, papelito_shipping_metrics_read_bucket( $day ) );
	}

	return papelito_shipping_metrics_summarize( $start, $end, $totals );
}

/**
 * Fecha o relatório com os totais derivados do acumulado.
 *
 * @param string              $from Primeiro dia do período.
 * @param string              $to Último dia do período.
 * @param array<string,mixed> $totals Acumulado do período.
 * @return array<string,mixed> Relatório agregado.
 */
function papelito_shipping_metrics_summarize( string $from, string $to, array $totals ): array {
	$built       = $totals[ PAPELITO_SHIPPING_METRICS_GROUP_BUILT ];
	$total_built = array_sum( $built );
	$residue     = papelito_shipping_metrics_residue_total( $built );
	$ratio       = $total_built > 0 ? $residue / $total_built : 0.0;

	return array(
		'from'            => $from,
		'to'              => $to,
		'built'           => $built,
		'rejected'        => $totals[ PAPELITO_SHIPPING_METRICS_GROUP_REJECTED ],
		'total_built'     => (int) $total_built,
		'total_rejected'  => (int) array_sum( $totals[ PAPELITO_SHIPPING_METRICS_GROUP_REJECTED ] ),
		'residue_built'   => $residue,
		'residue_ratio'   => round( $ratio, 4 ),
		'residue_percent' => round( $ratio * 100, 2 ),
	);
}

add_action( 'papelito_shipping_package_built', 'papelito_shipping_metrics_record_built', 10, 1 );
add_action( 'papelito_shipping_package_rejected', 'papelito_shipping_metrics_record_rejected', 10, 1 );
