<?php
/**
 * Disjuntor de provider de frete: para de chamar quem já provou estar fora.
 *
 * Diferente de `shipping_observability.php`, este módulo **conduz**: ele decide
 * que uma cotação não vai acontecer. Por isso o escopo é o menor possível — só a
 * Braspress é protegida, e só falha de indisponibilidade conta. Os Correios não
 * têm estado aqui e nunca consultam este módulo; era assim que o checkout
 * degradava antes e continua sendo o comportamento quando a Braspress cai.
 *
 * O que **não** abre o disjuntor é o que evita o desastre clássico de disjuntor
 * agressivo: destino fora da malha é resposta de negócio e acontece o dia
 * inteiro; credencial recusada já tem tratamento próprio, com estado persistido
 * e alerta, e desligar a transportadora por isso esconderia o problema real do
 * vendor; dado local inválido é defeito do Papelito, e o provider não tem culpa.
 *
 * Abrir o disjuntor não gera erro de checkout: a Braspress simplesmente não
 * participa daquela cotação, exatamente como um vendor sem integração. O
 * comprador vê as opções que sobraram.
 *
 * O relógio é parâmetro, e não `time()` embutido, porque uma máquina de estados
 * temporal só se prova com relógio controlado — testar descanso e meia-abertura
 * com o relógio do sistema exigiria dormir na suíte.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_SHIPPING_BREAKER_OPTION_PREFIX = 'papelito_shipping_breaker_';
const PAPELITO_SHIPPING_BREAKER_THRESHOLD     = 4;
const PAPELITO_SHIPPING_BREAKER_COOLDOWN      = 120;
const PAPELITO_SHIPPING_BREAKER_CLOSED        = 'closed';
const PAPELITO_SHIPPING_BREAKER_OPEN          = 'open';
const PAPELITO_SHIPPING_BREAKER_HALF_OPEN     = 'half_open';

/**
 * Desfechos que contam como indisponibilidade do provider.
 *
 * Deliberadamente curto. Cada categoria aqui é um caso em que insistir custa
 * latência no checkout e não muda o resultado; tudo o mais é informação, não
 * degradação.
 */
const PAPELITO_SHIPPING_BREAKER_FAILURES = array( 'timeout', 'network_error', 'rate_limited', 'provider_5xx' );

/**
 * Providers que o disjuntor protege.
 *
 * Os Correios ficam de fora por decisão, não por esquecimento: eles são a única
 * cotação disponível para a maior parte do catálogo, e um disjuntor sobre a
 * última alternativa transforma degradação em checkout sem frete.
 */
const PAPELITO_SHIPPING_BREAKER_PROVIDERS = array( 'braspress' );

/**
 * Informa se o disjuntor governa este provider.
 *
 * @param string $provider Identificador do provider.
 * @return bool Se o provider é protegido.
 */
function papelito_shipping_breaker_protects( string $provider ): bool {
	return in_array( sanitize_key( $provider ), PAPELITO_SHIPPING_BREAKER_PROVIDERS, true );
}

/**
 * Nome da option que guarda o estado de um provider para um vendor.
 *
 * A chave inclui o vendor porque a conta é dele: a Braspress fora do ar para a
 * Cifal não é motivo para tirar a Braspress de outra loja da cotação.
 *
 * @param string $provider Provider já saneado.
 * @param int    $vendor_id ID interno do vendor.
 * @return string Nome da option, sempre sem autoload na escrita.
 */
function papelito_shipping_breaker_option_key( string $provider, int $vendor_id ): string {
	return PAPELITO_SHIPPING_BREAKER_OPTION_PREFIX . sanitize_key( $provider ) . '_' . absint( $vendor_id );
}

/**
 * Lê o estado persistido, normalizado, sem nunca devolver lixo.
 *
 * @param string $provider Provider já saneado.
 * @param int    $vendor_id ID interno do vendor.
 * @return array{failures:int,opened_at:int} Estado do disjuntor.
 */
function papelito_shipping_breaker_read( string $provider, int $vendor_id ): array {
	$stored = get_option( papelito_shipping_breaker_option_key( $provider, $vendor_id ), null );
	$stored = is_array( $stored ) ? $stored : array();

	return array(
		'failures'  => absint( $stored['failures'] ?? 0 ),
		'opened_at' => absint( $stored['opened_at'] ?? 0 ),
	);
}

/**
 * Persiste o estado, engolindo qualquer falha de escrita.
 *
 * Um disjuntor que não consegue gravar precisa falhar para o lado de deixar
 * passar: o custo de uma cotação a mais é latência, e o de recusar cotação por
 * defeito de armazenamento é venda perdida.
 *
 * @param string                            $provider Provider já saneado.
 * @param int                               $vendor_id ID interno do vendor.
 * @param array{failures:int,opened_at:int} $state Estado a gravar.
 * @return void
 */
function papelito_shipping_breaker_write( string $provider, int $vendor_id, array $state ): void {
	try {
		update_option( papelito_shipping_breaker_option_key( $provider, $vendor_id ), $state, false );
	} catch ( Throwable $error ) {
		return;
	}
}

/**
 * Estado corrente do disjuntor no instante informado.
 *
 * `open` e `half_open` não são estados persistidos separados: o que fica gravado
 * é o instante da abertura, e o descanso decide qual dos dois vale agora. Sem
 * isso, sair de `open` dependeria de alguém passar por ali para reescrever a
 * linha, e o disjuntor ficaria aberto justamente quando ninguém cota.
 *
 * @param string   $provider Identificador do provider.
 * @param int      $vendor_id ID interno do vendor.
 * @param int|null $now Instante Unix; nulo usa o relógio do sistema.
 * @return string `closed`, `open` ou `half_open`.
 */
function papelito_shipping_breaker_state( string $provider, int $vendor_id, ?int $now = null ): string {
	if ( ! papelito_shipping_breaker_protects( $provider ) ) {
		return PAPELITO_SHIPPING_BREAKER_CLOSED;
	}

	$state = papelito_shipping_breaker_read( $provider, $vendor_id );
	if ( $state['opened_at'] <= 0 ) {
		return PAPELITO_SHIPPING_BREAKER_CLOSED;
	}

	$now = null === $now ? time() : $now;

	return $now - $state['opened_at'] >= PAPELITO_SHIPPING_BREAKER_COOLDOWN
		? PAPELITO_SHIPPING_BREAKER_HALF_OPEN
		: PAPELITO_SHIPPING_BREAKER_OPEN;
}

/**
 * Decide se o provider pode ser chamado agora, consumindo a sonda quando cabe.
 *
 * Tem efeito colateral de propósito: meia-aberto libera **uma** tentativa, e
 * marcar o consumo aqui é o que impede que um pico de checkouts simultâneos
 * despeje todos eles sobre um provider que ainda pode estar fora.
 *
 * @param string   $provider Identificador do provider.
 * @param int      $vendor_id ID interno do vendor.
 * @param int|null $now Instante Unix; nulo usa o relógio do sistema.
 * @return bool Se a cotação pode ser tentada.
 */
function papelito_shipping_breaker_allows( string $provider, int $vendor_id, ?int $now = null ): bool {
	$state = papelito_shipping_breaker_state( $provider, $vendor_id, $now );
	if ( PAPELITO_SHIPPING_BREAKER_CLOSED === $state ) {
		return true;
	}
	if ( PAPELITO_SHIPPING_BREAKER_OPEN === $state ) {
		return false;
	}

	return papelito_shipping_breaker_claim_probe( $provider, $vendor_id, null === $now ? time() : $now );
}

/**
 * Nome da option que representa a posse da sonda.
 *
 * @param string $provider Provider já saneado.
 * @param int    $vendor_id ID interno do vendor.
 * @return string Nome da option.
 */
function papelito_shipping_breaker_probe_key( string $provider, int $vendor_id ): string {
	return papelito_shipping_breaker_option_key( $provider, $vendor_id ) . '_probe';
}

/**
 * Tenta tomar a sonda para si, e devolve se conseguiu.
 *
 * `add_option()` é a única primitiva atômica que existe para options: por baixo é
 * um `INSERT` numa coluna com índice único, então dois processos que cheguem
 * juntos não podem vencer os dois. Ler, conferir e gravar — que era o que estava
 * aqui — deixa passar todos os checkouts que leram antes da primeira escrita, e
 * é justamente um pico desses que o disjuntor existe para conter.
 *
 * A posse expira depois de um descanso. Sem isso, um processo que morresse entre
 * tomar a sonda e reportar o desfecho deixaria a Braspress fora do checkout para
 * sempre, sem nada no estado que explicasse por quê.
 *
 * Sonda sem dono e sonda abandonada são tomadas por caminhos diferentes, cada um
 * com a sua primitiva atômica, porque `add_option()` só protege a primeira.
 *
 * @param string $provider Provider já saneado.
 * @param int    $vendor_id ID interno do vendor.
 * @param int    $now Instante Unix da tentativa.
 * @return bool Se esta chamada é a dona da sonda.
 */
function papelito_shipping_breaker_claim_probe( string $provider, int $vendor_id, int $now ): bool {
	$option = papelito_shipping_breaker_probe_key( $provider, $vendor_id );
	$held   = get_option( $option, null );

	if ( null === $held ) {
		return papelito_shipping_breaker_insert_probe( $option, $now );
	}

	if ( is_numeric( $held ) && $now - (int) $held < PAPELITO_SHIPPING_BREAKER_COOLDOWN ) {
		return false;
	}

	return papelito_shipping_breaker_take_expired_probe( $option, (string) $held, $now );
}

/**
 * Cria a sonda quando não há dono, e devolve se esta chamada foi quem criou.
 *
 * @param string $option Nome da option da sonda.
 * @param int    $now Instante Unix da tentativa.
 * @return bool Se esta chamada é a dona.
 */
function papelito_shipping_breaker_insert_probe( string $option, int $now ): bool {
	try {
		return (bool) add_option( $option, $now, '', false );
	} catch ( Throwable $error ) {
		return false;
	}
}

/**
 * Retoma a sonda abandonada num passo só, e devolve se esta chamada a retomou.
 *
 * Apagar e reinserir não serve: dois processos que leram a mesma sonda velha
 * apagam e inserem em sequência, cada um por cima do outro, e os dois saem
 * donos. Conferir o retorno do `delete_option()` também não resolve, porque o
 * `DELETE` não olha o valor e o segundo processo apaga a linha que o primeiro
 * acabou de criar.
 *
 * O `UPDATE` condicionado ao carimbo velho é a troca atômica que falta. Aqui,
 * diferente do que acontece na saúde da integração, zero linha afetada **não é
 * ambíguo**: o valor novo é o instante atual e o velho está a pelo menos um
 * descanso de distância, então nunca são iguais — zero significa que outro
 * processo chegou antes, e nunca "já estava assim".
 *
 * @param string $option Nome da option da sonda.
 * @param string $held Carimbo da sonda abandonada, como está gravado.
 * @param int    $now Instante Unix da tentativa.
 * @return bool Se esta chamada é a dona.
 */
function papelito_shipping_breaker_take_expired_probe( string $option, string $held, int $now ): bool {
	global $wpdb;

	if ( ! is_object( $wpdb ) ) {
		return false;
	}

	try {
		$taken = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives exclusively from $wpdb.
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $now,
				$option,
				$held
			)
		);
	} catch ( Throwable $error ) {
		return false;
	}

	if ( 1 !== (int) $taken ) {
		return false;
	}

	wp_cache_delete( $option, 'options' );

	return true;
}

/**
 * Devolve a sonda, para que o próximo desfecho possa tomá-la de novo.
 *
 * @param string $provider Provider já saneado.
 * @param int    $vendor_id ID interno do vendor.
 * @return void
 */
function papelito_shipping_breaker_release_probe( string $provider, int $vendor_id ): void {
	try {
		delete_option( papelito_shipping_breaker_probe_key( $provider, $vendor_id ) );
	} catch ( Throwable $error ) {
		return;
	}
}

/**
 * Atualiza o disjuntor com o desfecho de uma tentativa.
 *
 * @param string   $provider Identificador do provider.
 * @param int      $vendor_id ID interno do vendor.
 * @param string   $outcome Desfecho já classificado pela observabilidade.
 * @param int|null $now Instante Unix; nulo usa o relógio do sistema.
 * @return void
 */
function papelito_shipping_breaker_record( string $provider, int $vendor_id, string $outcome, ?int $now = null ): void {
	if ( ! papelito_shipping_breaker_protects( $provider ) ) {
		return;
	}

	$outcome = sanitize_key( $outcome );
	if ( 'success' === $outcome ) {
		papelito_shipping_breaker_close( $provider, $vendor_id );
		return;
	}
	if ( ! in_array( $outcome, PAPELITO_SHIPPING_BREAKER_FAILURES, true ) ) {
		return;
	}

	papelito_shipping_breaker_count_failure( $provider, $vendor_id, null === $now ? time() : $now );
}

/**
 * Fecha o disjuntor e esquece o histórico de falhas.
 *
 * @param string $provider Provider já saneado.
 * @param int    $vendor_id ID interno do vendor.
 * @return void
 */
function papelito_shipping_breaker_close( string $provider, int $vendor_id ): void {
	papelito_shipping_breaker_release_probe( $provider, $vendor_id );

	try {
		delete_option( papelito_shipping_breaker_option_key( $provider, $vendor_id ) );
	} catch ( Throwable $error ) {
		return;
	}
}

/**
 * Conta uma falha de indisponibilidade e abre o disjuntor quando cabe.
 *
 * Falha durante a sonda reabre imediatamente, sem esperar o limite de novo: o
 * provider acabou de ser testado e reprovou.
 *
 * @param string $provider Provider já saneado.
 * @param int    $vendor_id ID interno do vendor.
 * @param int    $now Instante Unix da falha.
 * @return void
 */
function papelito_shipping_breaker_count_failure( string $provider, int $vendor_id, int $now ): void {
	$state = papelito_shipping_breaker_read( $provider, $vendor_id );
	if ( papelito_shipping_breaker_state( $provider, $vendor_id, $now ) === PAPELITO_SHIPPING_BREAKER_HALF_OPEN ) {
		papelito_shipping_breaker_release_probe( $provider, $vendor_id );
		papelito_shipping_breaker_write(
			$provider,
			$vendor_id,
			array(
				'failures'  => PAPELITO_SHIPPING_BREAKER_THRESHOLD,
				'opened_at' => $now,
			)
		);
		return;
	}
	$failures = $state['failures'] + 1;
	$opened   = $failures >= PAPELITO_SHIPPING_BREAKER_THRESHOLD ? $now : 0;

	papelito_shipping_breaker_write(
		$provider,
		$vendor_id,
		array(
			'failures'  => $failures,
			'opened_at' => $opened,
		)
	);
}

/**
 * Atualiza o disjuntor com o desfecho publicado pela orquestração.
 *
 * @param mixed $provider Provider publicado pela action.
 * @param mixed $result Resultado bruto do provider.
 * @param mixed $duration_ms Duração da tentativa, não usada pelo disjuntor.
 * @param mixed $vendor_id ID interno do vendor.
 * @return void
 */
function papelito_shipping_breaker_observe( mixed $provider, mixed $result, mixed $duration_ms = 0, mixed $vendor_id = 0 ): void {
	if ( ! is_string( $provider ) || ! function_exists( 'papelito_shipping_provider_metrics_classify' ) ) {
		return;
	}

	papelito_shipping_breaker_record(
		$provider,
		absint( $vendor_id ),
		papelito_shipping_provider_metrics_classify( sanitize_key( $provider ), $result )
	);
}

add_action( 'papelito_shipping_provider_quote_result', 'papelito_shipping_breaker_observe', 10, 4 );
