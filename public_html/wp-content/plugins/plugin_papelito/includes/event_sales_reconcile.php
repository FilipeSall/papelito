<?php
/**
 * Aplica o estado da order da Point à tentativa, ao pedido e ao ledger do repasse.
 *
 * Três caminhos chegam aqui com a mesma order: o webhook `type=order`, a consulta de status do
 * tablet (depois de 20 s sem resposta) e o cron de reconciliação. Todos passam por
 * `papelito_event_sales_apply_remote_order()`, sob o lock `attempt:{id}`.
 *
 * Regra de ouro: só paga quando a order bate com a tentativa em referência externa, valor e conta
 * Papelito; divergência vira incidente `mismatch` e nunca pagamento. O pedido é marcado pago uma
 * vez (`papelito_payment_order_mark_paid()` é one-shot) e o ledger não duplica.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_RECONCILE_AFTER   = 20;
const PAPELITO_EVENT_SALES_RECOVER_WINDOW    = 600;
const PAPELITO_EVENT_SALES_REFERENCE_PATTERN = '/^ppl-(\\d+)-(\\d+)$/';

/**
 * Estado que o tablet mostra para uma tentativa da Point.
 *
 * @param array<string,mixed>|null $attempt Tentativa ou nulo quando o pedido ainda não tem.
 * @return string `sending`, `at_terminal`, `paid`, `failed`, `expired`, `canceled` ou `check_terminal`.
 */
function papelito_event_sales_public_state( ?array $attempt ): string {
	if ( null === $attempt ) {
		return 'sending';
	}

	$state    = (string) $attempt['state'];
	$incident = (string) ( $attempt['incident'] ?? '' );
	$map      = array(
		'not_started' => 'sending',
		'creating'    => 'sending',
		'unknown'     => 'check_terminal',
		'paid'        => 'paid',
		'refunded'    => 'paid',
		'expired'     => 'expired',
	);

	if ( 'pending' === $state ) {
		return '' === $incident ? 'at_terminal' : 'check_terminal';
	}

	if ( 'failed' === $state ) {
		return 'canceled' === (string) $attempt['last_error_code'] ? 'canceled' : 'failed';
	}

	return $map[ $state ] ?? 'check_terminal';
}

/**
 * Valor decimal da API em centavos.
 *
 * @param mixed $amount Valor como a API devolve (string `1234.56`).
 * @return int
 */
function papelito_event_sales_amount_cents( mixed $amount ): int {
	return is_numeric( $amount ) ? (int) round( (float) $amount * 100 ) : -1;
}

/**
 * Primeiro pagamento da order, ou vazio.
 *
 * @param array<string,mixed> $remote Order da API.
 * @return array<string,mixed>
 */
function papelito_event_sales_remote_payment( array $remote ): array {
	$payment = $remote['transactions']['payments'][0] ?? array();

	return is_array( $payment ) ? $payment : array();
}

/**
 * Diz se a order pertence à tentativa: mesma referência, mesmo valor e mesma conta Papelito.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param array<string,mixed> $remote  Order da API.
 * @return bool
 */
function papelito_event_sales_remote_matches( array $attempt, array $remote ): bool {
	$expected = array( papelito_mercadopago_point_external_reference( $attempt ), (int) $attempt['amount_cents'], (string) $attempt['account_ref'] );
	$actual   = array(
		(string) ( $remote['external_reference'] ?? '' ),
		papelito_event_sales_amount_cents( $remote['total_amount'] ?? papelito_event_sales_remote_payment( $remote )['amount'] ?? null ),
		isset( $remote['user_id'] ) ? (string) $remote['user_id'] : (string) $attempt['account_ref'],
	);

	return $expected === $actual;
}

/**
 * Linha de ledger de um pagamento ou estorno da Point, com taxa e líquido quando a API os informa.
 *
 * O id numérico do pagamento (`reference_id`) é o que `/v1/payments/{id}` aceita; sem ele, taxa e
 * líquido ficam nulos ("a conferir" no CSV).
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param array<string,mixed> $remote  Order da API.
 * @param string              $kind    `payment` ou `refund`.
 * @return array<string,mixed>
 */
function papelito_event_sales_settlement_from_remote( array $attempt, array $remote, string $kind ): array {
	$payment    = papelito_event_sales_remote_payment( $remote );
	$payment_id = isset( $payment['reference_id'] ) && is_scalar( $payment['reference_id'] ) ? (string) $payment['reference_id'] : '';
	$fees       = papelito_mercadopago_point_payment_fees( $payment_id );

	return array(
		'event_id'          => PAPELITO_EVENT_SALES_ID,
		'order_id'          => (int) $attempt['order_id'],
		'attempt_id'        => (int) $attempt['id'],
		'kind'              => $kind,
		'payment_method'    => (string) $attempt['payment_method'],
		'remote_order_id'   => (string) ( $remote['id'] ?? $attempt['remote_id'] ),
		'remote_payment_id' => $payment_id,
		'nsu'               => '',
		'gross_cents'       => (int) $attempt['amount_cents'],
		'fee_cents'         => $fees['fee_cents'],
		'net_cents'         => $fees['net_cents'],
		'created_by'        => 0,
	);
}

/**
 * Marca a tentativa e o pedido como pagos e registra o repasse. Repetir não paga de novo.
 *
 * Pagamento já liberado à mão pelo supervisor não ganha segunda linha no ledger, por mais vezes
 * que a confirmação da máquina chegue: a liberação é lida do ledger, não do incidente.
 *
 * @param array<string,mixed> $attempt Tentativa relida sob lock.
 * @param array<string,mixed> $remote  Order processada.
 * @return void
 */
function papelito_event_sales_apply_paid( array $attempt, array $remote ): void {
	if ( 'paid' !== $attempt['state'] ) {
		papelito_payment_attempt_transition( (int) $attempt['id'], (string) $attempt['state'], 'paid', array( 'remote_id' => (string) ( $remote['id'] ?? $attempt['remote_id'] ) ) );
	}

	$order = wc_get_order( (int) $attempt['order_id'] );
	if ( is_object( $order ) ) {
		papelito_payment_order_mark_paid( $order, PAPELITO_MERCADOPAGO_POINT_PROVIDER );
	}

	if ( papelito_event_sales_settlement_has( (int) $attempt['id'], 'manual_release' ) ) {
		$fresh = papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;
		if ( 'manual_release_confirmed' !== (string) $fresh['incident'] ) {
			papelito_payment_attempt_update( $fresh, array( 'incident' => 'manual_release_confirmed' ) );
		}
		return;
	}

	papelito_event_sales_settlement_record( papelito_event_sales_settlement_from_remote( $attempt, $remote, 'payment' ) );
}

/**
 * Estado de destino da tentativa para um status remoto que encerra a cobrança sem pagamento.
 *
 * @param string $status Status remoto.
 * @return array{0:string,1:string}|null Estado e código do motivo, ou nulo quando não encerra.
 */
function papelito_event_sales_terminal_target( string $status ): ?array {
	$targets = array(
		'failed'   => array( 'failed', 'failed' ),
		'canceled' => array( 'failed', 'canceled' ),
		'expired'  => array( 'expired', 'expired' ),
	);

	return $targets[ $status ] ?? null;
}

/**
 * Encerra a tentativa sem pagamento; `creating` não pode expirar e vai para `failed`.
 *
 * @param array<string,mixed>      $attempt Tentativa.
 * @param array{0:string,1:string} $target Estado e motivo.
 * @return void
 */
function papelito_event_sales_apply_terminal( array $attempt, array $target ): void {
	$from = (string) $attempt['state'];
	$to   = papelito_payment_attempt_can_transition( $from, $target[0] ) ? $target[0] : 'failed';

	papelito_payment_attempt_transition( (int) $attempt['id'], $from, $to, array( 'last_error_code' => $target[1] ) );
}

/**
 * Estorno confirmado: tentativa `refunded`, linha negativa no ledger e o estorno no pedido.
 *
 * O pedido entra no fluxo de estornos (`papelito_order_refund_handle_psp_reversal()`), que o marca
 * `estornado`, lança o reembolso no WooCommerce sem devolver estoque e avisa o comprador. Repetir
 * não duplica nada.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param array<string,mixed> $remote  Order estornada.
 * @return void
 */
function papelito_event_sales_apply_refund( array $attempt, array $remote ): void {
	if ( 'paid' === $attempt['state'] ) {
		papelito_payment_attempt_transition( (int) $attempt['id'], 'paid', 'refunded' );
	}

	if ( ! in_array( $attempt['state'], array( 'paid', 'refunded' ), true ) ) {
		return;
	}

	papelito_event_sales_settlement_record( papelito_event_sales_settlement_from_remote( $attempt, $remote, 'refund' ) );

	$order = wc_get_order( (int) $attempt['order_id'] );
	if ( is_object( $order ) && function_exists( 'papelito_order_refund_handle_psp_reversal' ) ) {
		papelito_order_refund_handle_psp_reversal( $order, 'refunded' );
	}
}

/**
 * Order criada ou na máquina: garante a tentativa `pending` com o id remoto.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param array<string,mixed> $remote  Order aberta.
 * @return void
 */
function papelito_event_sales_apply_open( array $attempt, array $remote ): void {
	if ( in_array( $attempt['state'], array( 'creating', 'unknown' ), true ) ) {
		papelito_payment_attempt_transition( (int) $attempt['id'], (string) $attempt['state'], 'pending', array( 'remote_id' => (string) ( $remote['id'] ?? '' ) ) );
	}
}

/**
 * Grava na tentativa o id da order já validada quando ele faltava (timeout na criação seguido de
 * liberação manual), para que confirmação e estorno passem a achar a cobrança.
 *
 * @param array<string,mixed> $attempt Tentativa que bateu com a order.
 * @param array<string,mixed> $remote  Order da API.
 * @return array<string,mixed> Tentativa relida.
 */
function papelito_event_sales_adopt_remote_id( array $attempt, array $remote ): array {
	$remote_id = (string) ( $remote['id'] ?? '' );
	if ( '' !== (string) $attempt['remote_id'] || '' === $remote_id ) {
		return $attempt;
	}

	papelito_payment_attempt_update( $attempt, array( 'remote_id' => $remote_id ) );

	return papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;
}

/**
 * Aplica a order sob o lock já adquirido.
 *
 * @param array<string,mixed> $attempt Tentativa relida.
 * @param array<string,mixed> $remote  Order da API.
 * @return void
 */
function papelito_event_sales_apply_locked( array $attempt, array $remote ): void {
	if ( ! papelito_event_sales_remote_matches( $attempt, $remote ) ) {
		papelito_payment_attempt_update( $attempt, array( 'incident' => 'mismatch' ) );
		return;
	}

	$attempt = papelito_event_sales_adopt_remote_id( $attempt, $remote );
	$status  = sanitize_key( (string) ( $remote['status'] ?? '' ) );
	$target  = papelito_event_sales_terminal_target( $status );

	if ( 'processed' === $status ) {
		papelito_event_sales_apply_paid( $attempt, $remote );
	} elseif ( null !== $target ) {
		papelito_event_sales_apply_terminal( $attempt, $target );
	} elseif ( 'refunded' === $status ) {
		papelito_event_sales_apply_refund( $attempt, $remote );
	} elseif ( 'action_required' === $status ) {
		papelito_payment_attempt_update( $attempt, array( 'incident' => 'action_required' ) );
	} else {
		papelito_event_sales_apply_open( $attempt, $remote );
	}
}

/**
 * Aplica o estado da order da Point à tentativa e devolve o estado que o tablet mostra.
 *
 * Sem o lock (outra requisição aplicando a mesma tentativa), não faz nada e devolve o estado atual.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param array<string,mixed> $remote  Order da API.
 * @return string
 */
function papelito_event_sales_apply_remote_order( array $attempt, array $remote ): string {
	$lock = 'attempt:' . (int) $attempt['id'];
	if ( ! papelito_payment_attempt_acquire_lock( $lock ) ) {
		return papelito_event_sales_public_state( $attempt );
	}

	try {
		$fresh = papelito_payment_attempt_find( (int) $attempt['id'] );
		if ( null !== $fresh ) {
			papelito_event_sales_apply_locked( $fresh, $remote );
		}
	} finally {
		papelito_payment_attempt_release_lock( $lock );
	}

	return papelito_event_sales_public_state( papelito_payment_attempt_find( (int) $attempt['id'] ) );
}

/**
 * Diz se ainda dá para reenviar a criação: depois de 10 minutos a cobrança perdida não volta à
 * máquina sozinha, porque o lojista pode já ter ido embora.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return bool
 */
function papelito_event_sales_can_recover( array $attempt ): bool {
	return papelito_event_sales_attempt_age( $attempt ) <= PAPELITO_EVENT_SALES_RECOVER_WINDOW;
}

/**
 * Segundos desde a criação da tentativa.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return int
 */
function papelito_event_sales_attempt_age( array $attempt ): int {
	$created = strtotime( (string) $attempt['created_at'] . ' UTC' );

	return false === $created ? 0 : time() - $created;
}

/**
 * Reenvia a criação com a mesma chave de idempotência quando o resultado do POST se perdeu.
 *
 * @param array<string,mixed> $attempt Tentativa `creating` ou `unknown` sem id remoto.
 * @return array<string,mixed>|WP_Error Order existente ou recém-criada.
 */
function papelito_event_sales_recover_remote( array $attempt ): array|WP_Error {
	$config = papelito_event_sales_config();
	$order  = wc_get_order( (int) $attempt['order_id'] );
	$number = is_object( $order ) ? (string) $order->get_order_number() : (string) $attempt['order_id'];

	return papelito_mercadopago_point_create_order( $attempt, (int) $attempt['amount_cents'], $config['terminal_id'], (string) $attempt['payment_method'], papelito_event_sales_order_description( $number ) );
}

/**
 * Descrição da order na máquina e no extrato.
 *
 * @param string $order_number Número do pedido.
 * @return string
 */
function papelito_event_sales_order_description( string $order_number ): string {
	return 'Pedido Papelito #' . $order_number;
}

/**
 * Diz se a criação da cobrança se perdeu: criando ou com resultado desconhecido, sem id remoto.
 *
 * @param array<string,mixed> $attempt Tentativa da Point.
 * @return bool
 */
function papelito_event_sales_attempt_lost( array $attempt ): bool {
	return in_array( $attempt['state'], array( 'creating', 'unknown' ), true ) && '' === (string) $attempt['remote_id'];
}

/**
 * Order remota da tentativa: reenvia a criação perdida (dentro de 10 minutos) ou lê pelo id.
 *
 * @param array<string,mixed> $attempt Tentativa da Point.
 * @return array<string,mixed>|WP_Error|null Nulo quando não há o que consultar.
 */
function papelito_event_sales_fetch_remote( array $attempt ): array|WP_Error|null {
	if ( papelito_event_sales_attempt_lost( $attempt ) ) {
		return papelito_event_sales_can_recover( $attempt ) ? papelito_event_sales_recover_remote( $attempt ) : null;
	}

	$remote_id = (string) $attempt['remote_id'];

	return '' === $remote_id ? null : papelito_mercadopago_point_get_order( $remote_id );
}

/**
 * Consulta a Point e aplica o que ela disser. É o caminho do status do tablet.
 *
 * @param array<string,mixed> $attempt Tentativa da Point.
 * @return string Estado que o tablet mostra.
 */
function papelito_event_sales_reconcile_attempt( array $attempt ): string {
	$remote = papelito_event_sales_fetch_remote( $attempt );

	if ( ! is_array( $remote ) ) {
		return papelito_event_sales_public_state( $attempt );
	}

	return papelito_event_sales_apply_remote_order( $attempt, $remote );
}

/**
 * Tentativa da Point apontada pela referência externa `ppl-{pedido}-{tentativa}`, ou nulo.
 *
 * @param array<string,mixed> $remote Order da API.
 * @return array<string,mixed>|null
 */
function papelito_event_sales_attempt_from_remote( array $remote ): ?array {
	if ( 1 !== preg_match( PAPELITO_EVENT_SALES_REFERENCE_PATTERN, (string) ( $remote['external_reference'] ?? '' ), $match ) ) {
		return null;
	}

	$attempt = papelito_payment_attempt_find( (int) $match[2] );
	$ours    = is_array( $attempt ) && PAPELITO_MERCADOPAGO_POINT_PROVIDER === $attempt['provider'] && (int) $match[1] === (int) $attempt['order_id'];

	return $ours ? $attempt : null;
}

/**
 * Webhook `type=order`: lê a order com o token da Point e aplica à tentativa da referência externa.
 *
 * Responde 500 quando o Mercado Pago deve reenviar (API fora do ar ou a notificação chegou com o
 * POST de criação ainda em andamento) e 200 sem efeito quando a order não é de uma tentativa da
 * Point. Tentativa sem id remoto fora da criação (timeout, liberação manual) adota o id da order.
 *
 * @param string $data_id Id da order na notificação.
 * @return WP_REST_Response
 */
function papelito_event_sales_handle_order_notification( string $data_id ): WP_REST_Response {
	$retry = new WP_REST_Response( array( 'received' => false ), 500 );
	$done  = new WP_REST_Response( array( 'received' => true ), 200 );

	if ( '' === $data_id ) {
		return $done;
	}

	$remote = papelito_mercadopago_point_get_order( $data_id );
	if ( is_wp_error( $remote ) ) {
		$category = (string) ( ( (array) $remote->get_error_data() )['category'] ?? '' );
		return in_array( $category, array( 'unavailable', 'rate_limit', 'unknown_outcome' ), true ) ? $retry : $done;
	}

	$attempt = papelito_event_sales_attempt_from_remote( $remote );
	if ( null === $attempt ) {
		return $done;
	}

	if ( 'creating' === $attempt['state'] && '' === (string) $attempt['remote_id'] ) {
		papelito_payment_attempt_update( $attempt, array( 'next_reconcile_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		return $retry;
	}

	papelito_event_sales_apply_remote_order( $attempt, $remote );

	return $done;
}

/**
 * Ramo da Point no cron de reconciliação. Cobrança perdida há mais de 10 minutos vira incidente
 * `unresolved` e só volta a ser conferida em 6 horas, sem reenviar nada à máquina. Falha da API
 * adia a tentativa com o backoff do cron (`papelito_payment_reconcile_defer()`), para ela não
 * ocupar todo lote; a primeira consulta que responde zera as falhas.
 *
 * @param array<string,mixed> $attempt Tentativa da Point, já sob o lock do cron.
 * @return string Desfecho.
 */
function papelito_event_sales_cron_reconcile( array $attempt ): string {
	if ( papelito_event_sales_attempt_lost( $attempt ) && ! papelito_event_sales_can_recover( $attempt ) ) {
		papelito_payment_attempt_update(
			$attempt,
			array(
				'incident'          => 'unresolved',
				'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS ),
			)
		);
		return 'incident';
	}

	$remote = papelito_event_sales_fetch_remote( $attempt );
	if ( is_wp_error( $remote ) ) {
		return papelito_payment_reconcile_defer( $attempt );
	}

	if ( null === $remote ) {
		return papelito_event_sales_public_state( $attempt );
	}

	if ( (int) ( $attempt['reconcile_failures'] ?? 0 ) > 0 ) {
		papelito_payment_attempt_update( papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt, array( 'reconcile_failures' => 0 ) );
	}

	return papelito_event_sales_apply_remote_order( $attempt, $remote );
}
