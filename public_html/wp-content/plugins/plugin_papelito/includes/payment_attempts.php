<?php
/**
 * Tentativas de pagamento: identidade, estado e trava contra cobrança duplicada.
 *
 * Cada tentativa registra, antes de qualquer chamada remota, o gateway, o ambiente, a conta que vai
 * receber e o valor em centavos. É isso que permite reconciliar uma cobrança cujo processo morreu
 * depois do POST e é o que o webhook, a reconciliação e o estorno consultam — nunca a preferência
 * atual do vendor.
 *
 * A regra de ouro: transição de estado só acontece por compare-and-set no banco
 * (`papelito_payment_attempt_transition()`), e a máquina de estados é monotônica — `unknown`
 * nunca volta a `not_started`, `paid` só sai para `refunded`. Duas requisições simultâneas
 * disputando a mesma emissão: uma ganha o `creating`, a outra recebe a tentativa como está.
 *
 * O que este módulo não faz: não escolhe gateway, não chama API remota e não mexe em estoque.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_PAYMENT_ATTEMPTS_TABLE = 'papelito_payment_attempts';

const PAPELITO_PAYMENT_FLOW_DIRECT                 = 'direct';
const PAPELITO_PAYMENT_FLOW_REDIRECT               = 'redirect';
const PAPELITO_PAYMENT_FLOW_REQUIRES_PAYMENT_INPUT = 'requires_payment_input';

/**
 * Transições permitidas, de => para.
 *
 * `failed` e `expired` aceitam `paid` porque aprovação tardia existe (boleto pago no vencimento,
 * segundo pagamento da mesma preferência depois de um recusado) e não pode ser descartada.
 *
 * @return array<string,array<int,string>>
 */
function papelito_payment_attempt_transitions(): array {
	return array(
		'not_started'            => array( 'creating', 'awaiting_payment_input', 'failed' ),
		'awaiting_payment_input' => array( 'creating', 'failed', 'expired' ),
		'creating'               => array( 'pending', 'paid', 'failed', 'unknown' ),
		'unknown'                => array( 'pending', 'paid', 'failed', 'expired' ),
		'pending'                => array( 'paid', 'failed', 'expired' ),
		'paid'                   => array( 'refunded' ),
		'failed'                 => array( 'paid' ),
		'expired'                => array( 'paid' ),
		'refunded'               => array(),
	);
}

/**
 * Estados conhecidos.
 *
 * @return array<int,string>
 */
function papelito_payment_attempt_states(): array {
	return array_keys( papelito_payment_attempt_transitions() );
}

/**
 * Diz se a transição é permitida.
 *
 * @param string $from Estado atual.
 * @param string $to   Estado desejado.
 * @return bool
 */
function papelito_payment_attempt_can_transition( string $from, string $to ): bool {
	$transitions = papelito_payment_attempt_transitions();

	return isset( $transitions[ $from ] ) && in_array( $to, $transitions[ $from ], true );
}

/**
 * Diz se o estado impede abrir outra tentativa para o mesmo pedido.
 *
 * Criação em andamento, resultado desconhecido, pagamento pendente ou pago significam que pode
 * existir cobrança remota viva: abrir outra arriscaria cobrar duas vezes.
 *
 * @param string $state Estado da tentativa existente.
 * @return bool
 */
function papelito_payment_attempt_blocks_new_attempt( string $state ): bool {
	return in_array( $state, array( 'creating', 'unknown', 'pending', 'paid' ), true );
}

/**
 * Gera referência opaca usada na notification URL do gateway.
 *
 * Não autentica nada: só ajuda o webhook a achar a conta candidata.
 *
 * @return string 32 caracteres hexadecimais.
 */
function papelito_payment_attempt_generate_ref(): string {
	return bin2hex( random_bytes( 16 ) );
}

/**
 * Valida o formato de uma referência opaca recebida de fora.
 *
 * @param string $ref Referência crua.
 * @return bool
 */
function papelito_payment_attempt_ref_is_valid( string $ref ): bool {
	return 1 === preg_match( '/^[a-f0-9]{32}$/', $ref );
}

/**
 * Valida os campos de uma tentativa nova antes de gravar.
 *
 * @param array<string,mixed> $fields Campos da tentativa.
 * @return true|WP_Error
 */
function papelito_payment_attempt_validate_new( array $fields ) {
	$provider    = (string) ( $fields['provider'] ?? '' );
	$environment = (string) ( $fields['environment'] ?? '' );
	$state       = (string) ( $fields['state'] ?? 'not_started' );

	if ( ! papelito_payment_provider_is_valid( $provider ) ) {
		return new WP_Error( 'papelito_payment_provider_unknown', 'Gateway inválido para a tentativa.', array( 'status' => 500 ) );
	}

	if ( ! in_array( $environment, array( 'test', 'live' ), true ) ) {
		return new WP_Error( 'papelito_payment_environment_invalid', 'Ambiente de pagamento inválido.', array( 'status' => 500 ) );
	}

	if ( (int) ( $fields['vendor_id'] ?? 0 ) <= 0 || (int) ( $fields['amount_cents'] ?? 0 ) <= 0 ) {
		return new WP_Error( 'papelito_payment_attempt_invalid', 'Tentativa de pagamento sem vendor ou valor.', array( 'status' => 500 ) );
	}

	if ( ! in_array( $state, array( 'not_started', 'awaiting_payment_input' ), true ) ) {
		return new WP_Error( 'papelito_payment_attempt_invalid_state', 'Tentativa nova nasce sem cobrança emitida.', array( 'status' => 500 ) );
	}

	return true;
}

/**
 * Projeção da tentativa que pode ir ao navegador.
 *
 * Sem attemptRef, conta ou ID remoto: o comprador recebe só o que precisa para seguir o fluxo.
 *
 * @param array<string,mixed> $row Linha da tabela.
 * @return array<string,mixed>
 */
function papelito_payment_attempt_public( array $row ): array {
	$public = array(
		'paymentAttemptId'     => (int) ( $row['id'] ?? 0 ),
		'provider'             => (string) ( $row['provider'] ?? '' ),
		'flow'                 => (string) ( $row['flow'] ?? '' ),
		'state'                => (string) ( $row['state'] ?? '' ),
		'amountCents'          => (int) ( $row['amount_cents'] ?? 0 ),
		'configurationVersion' => (string) ( $row['configuration_version'] ?? '' ),
		'fallbackReason'       => (string) ( $row['fallback_reason'] ?? '' ),
	);

	$redirect = (string) ( $row['redirect_url'] ?? '' );
	if ( PAPELITO_PAYMENT_FLOW_REDIRECT === $public['flow'] && 'pending' === $public['state'] && '' !== $redirect ) {
		$public['redirectUrl'] = esc_url_raw( $redirect );
	}

	return $public;
}

/**
 * Estado da tentativa a partir do estado da cobrança Pagar.me.
 *
 * @param string $state Estado da cobrança.
 * @return string
 */
function papelito_payment_attempt_state_from_pagarme( string $state ): string {
	if ( papelito_pagarme_payment_state_is_paid( $state ) ) {
		return 'paid';
	}

	return papelito_pagarme_payment_state_releases_stock( $state ) ? 'failed' : 'pending';
}
