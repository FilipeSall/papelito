<?php
/**
 * Reconciliação programada das tentativas de pagamento, sem depender de webhook nem de retorno.
 *
 * Trabalha em lote pelas tentativas vencidas (`next_reconcile_at`), com prioridade para
 * `unknown`, e consulta só a conta/recebedor gravados em cada tentativa — nunca varre todos os
 * vendors no gateway. Falha de consulta adia com backoff exponencial e nunca libera estoque.
 *
 * A regra de ouro: estado remoto decide. Expiração da preferência não prova expiração de PIX ou
 * boleto já emitido, então a reserva só é liberada quando não há pagamento vivo; `unknown` só
 * expira depois da janela de criação sem nenhum pagamento achado (o link nunca chegou ao
 * comprador); pedido pago nunca é reabastecido. Expirar localmente não habilita cobrança em outro
 * gateway — isso só acontece num checkout novo.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_PAYMENT_RECONCILE_HOOK     = 'papelito_payment_reconcile_attempts';
const PAPELITO_PAYMENT_UNKNOWN_WINDOW     = 600;
const PAPELITO_PAYMENT_CREATING_STALE     = 300;
const PAPELITO_PAYMENT_RECONCILE_MAX_WAIT = 21600;
const PAPELITO_PAYMENT_LATE_WATCH_DAYS    = 7;
const PAPELITO_PAYMENT_TICKET_GRACE       = 259200;
const PAPELITO_PAYMENT_INSTANT_GRACE      = 3600;

/**
 * Espera antes da próxima consulta depois de N falhas seguidas.
 *
 * @param int $failures Falhas acumuladas.
 * @return int Segundos.
 */
function papelito_payment_reconcile_backoff( int $failures ): int {
	return (int) min( PAPELITO_PAYMENT_RECONCILE_MAX_WAIT, 120 * ( 2 ** min( max( 0, $failures ), 10 ) ) );
}

/**
 * Idade da tentativa em segundos.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return int
 */
function papelito_payment_reconcile_age( array $attempt ): int {
	$created = strtotime( (string) ( $attempt['created_at'] ?? '' ) . ' UTC' );

	return false === $created ? 0 : time() - $created;
}

/**
 * Agenda a próxima consulta da tentativa.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param int                 $delay   Segundos.
 * @param array<string,mixed> $fields  Campos extras.
 * @return void
 */
function papelito_payment_reconcile_schedule( array $attempt, int $delay, array $fields = array() ): void {
	$fresh = papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;

	papelito_payment_attempt_update( $fresh, array_merge( array( 'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ) ), $fields ) );
}

/**
 * Adia a tentativa com backoff depois de uma consulta que falhou.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return string
 */
function papelito_payment_reconcile_defer( array $attempt ): string {
	$fresh    = papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;
	$failures = (int) $fresh['reconcile_failures'] + 1;

	papelito_payment_reconcile_schedule( $fresh, papelito_payment_reconcile_backoff( $failures ), array( 'reconcile_failures' => $failures ) );
	papelito_payment_observe_metric( 'reconcile_deferred' );

	return 'deferred';
}

/**
 * Expira a tentativa sem pagamento e encerra o pedido sem pagamento.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param string              $reason  Motivo.
 * @return string
 */
function papelito_payment_reconcile_expire( array $attempt, string $reason ): string {
	$watch = ! empty( $attempt['remote_payment_ids'] ) ? gmdate( 'Y-m-d H:i:s', time() + PAPELITO_PAYMENT_RECONCILE_MAX_WAIT ) : null;

	if ( ! papelito_payment_attempt_transition(
		(int) $attempt['id'],
		(string) $attempt['state'],
		'expired',
		array(
			'last_error_code'   => $reason,
			'next_reconcile_at' => $watch,
		)
	) ) {
		return 'skipped';
	}

	$order = wc_get_order( (int) $attempt['order_id'] );
	if ( is_object( $order ) ) {
		papelito_payment_order_mark_unpaid( $order, 'payment_expired' );
	}

	return 'expired';
}

/**
 * Reconcilia uma tentativa pelo gateway que a criou.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return string Desfecho.
 */
function papelito_payment_reconcile_attempt( array $attempt ): string {
	$lock = 'attempt:' . (int) $attempt['id'];

	if ( ! papelito_payment_attempt_acquire_lock( $lock, 0 ) ) {
		return 'busy';
	}

	try {
		return papelito_payment_reconcile_attempt_locked( papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt );
	} finally {
		papelito_payment_attempt_release_lock( $lock );
	}
}

/**
 * Corpo da reconciliação, já sob a trava da tentativa e com a tentativa relida.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return string Desfecho.
 */
function papelito_payment_reconcile_attempt_locked( array $attempt ): string {
	if ( 'awaiting_payment_input' === $attempt['state'] ) {
		return papelito_payment_reconcile_awaiting_input( $attempt );
	}

	if ( 'creating' === $attempt['state'] ) {
		if ( papelito_payment_reconcile_age( $attempt ) < PAPELITO_PAYMENT_CREATING_STALE ) {
			return 'skipped';
		}
		papelito_payment_attempt_transition( (int) $attempt['id'], 'creating', 'unknown', array( 'last_error_code' => 'creation_interrupted' ) );
		$attempt = papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;
	}

	return PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO === $attempt['provider']
		? papelito_payment_reconcile_mercadopago( $attempt )
		: papelito_payment_reconcile_pagarme( $attempt );
}

/**
 * Coleta de pagamento abandonada: depois do prazo, a tentativa expira e a reserva é liberada.
 *
 * @param array<string,mixed> $attempt Tentativa `awaiting_payment_input`.
 * @return string
 */
function papelito_payment_reconcile_awaiting_input( array $attempt ): string {
	$expires = strtotime( (string) ( $attempt['expires_at'] ?? '' ) . ' UTC' );

	if ( false === $expires || $expires <= 0 || $expires >= time() ) {
		papelito_payment_reconcile_schedule( $attempt, false === $expires || $expires <= 0 ? HOUR_IN_SECONDS : max( MINUTE_IN_SECONDS, $expires - time() ) );
		return 'waiting';
	}

	return papelito_payment_reconcile_expire( $attempt, 'payment_input_timeout' );
}

/**
 * Reconciliação Mercado Pago por referência externa.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return string
 */
function papelito_payment_reconcile_mercadopago( array $attempt ): string {
	$result = papelito_mercadopago_reconcile_by_reference( $attempt );

	if ( is_wp_error( $result ) ) {
		return papelito_payment_reconcile_defer( $attempt );
	}

	$attempt = papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;

	if ( 'paid' === $attempt['state'] ) {
		return 'paid';
	}

	$open = papelito_payment_reconcile_open_payments( $result['payments'] );

	if ( 'unknown' === $attempt['state'] ) {
		return papelito_payment_reconcile_mercadopago_unknown( $attempt, $result['payments'] );
	}

	if ( 'expired' === $attempt['state'] ) {
		return papelito_payment_reconcile_late_watch( $attempt );
	}

	if ( 'pending' !== $attempt['state'] ) {
		return (string) $attempt['state'];
	}

	if ( ! empty( $open ) ) {
		papelito_payment_reconcile_schedule( $attempt, papelito_payment_reconcile_open_delay( $open ), array( 'reconcile_failures' => 0 ) );
		return 'pending';
	}

	$expires = strtotime( (string) ( $attempt['expires_at'] ?? '' ) . ' UTC' );
	if ( false !== $expires && $expires > 0 && $expires < time() ) {
		return papelito_payment_reconcile_expire( $attempt, 'preference_expired' );
	}

	papelito_payment_reconcile_schedule( $attempt, 15 * MINUTE_IN_SECONDS, array( 'reconcile_failures' => 0 ) );

	return 'pending';
}

/**
 * Vigilância de baixa frequência de tentativa expirada que tinha pagamento registrado.
 *
 * Boleto e PIX podem compensar depois da expiração local; por alguns dias a reconciliação volta
 * a consultar, e a aprovação (se vier) é aplicada pela consulta acima. Depois disso, desiste.
 *
 * @param array<string,mixed> $attempt Tentativa `expired`.
 * @return string
 */
function papelito_payment_reconcile_late_watch( array $attempt ): string {
	$watching = ! empty( $attempt['remote_payment_ids'] ) && papelito_payment_reconcile_age( $attempt ) < PAPELITO_PAYMENT_LATE_WATCH_DAYS * DAY_IN_SECONDS;

	papelito_payment_attempt_update( papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt, array( 'next_reconcile_at' => $watching ? gmdate( 'Y-m-d H:i:s', time() + PAPELITO_PAYMENT_RECONCILE_MAX_WAIT ) : null ) );

	return 'expired';
}

/**
 * Resolve a criação incerta do Mercado Pago.
 *
 * @param array<string,mixed>            $attempt  Tentativa `unknown`.
 * @param array<int,array<string,mixed>> $payments Pagamentos da referência.
 * @return string
 */
function papelito_payment_reconcile_mercadopago_unknown( array $attempt, array $payments ): string {
	if ( ! empty( $payments ) ) {
		papelito_payment_attempt_transition( (int) $attempt['id'], 'unknown', 'pending', array( 'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ) ) );
		return 'pending';
	}

	if ( papelito_payment_reconcile_age( $attempt ) > PAPELITO_PAYMENT_UNKNOWN_WINDOW ) {
		return papelito_payment_reconcile_expire( $attempt, 'creation_not_confirmed' );
	}

	papelito_payment_reconcile_schedule( $attempt, 120 );

	return 'waiting';
}

/**
 * Pagamentos ainda vivos (pendentes e dentro do vencimento).
 *
 * @param array<int,array<string,mixed>> $payments Pagamentos.
 * @return array<int,array<string,mixed>>
 */
function papelito_payment_reconcile_open_payments( array $payments ): array {
	return array_values(
		array_filter(
			$payments,
			static function ( array $payment ): bool {
				$expiration = strtotime( (string) ( $payment['date_of_expiration'] ?? '' ) );
				$grace      = 'ticket' === (string) ( $payment['payment_type_id'] ?? '' ) ? PAPELITO_PAYMENT_TICKET_GRACE : PAPELITO_PAYMENT_INSTANT_GRACE;
				$alive      = false === $expiration || $expiration + $grace > time();

				return $alive && in_array( (string) ( $payment['status'] ?? '' ), array( 'pending', 'in_process', 'authorized', 'in_mediation' ), true );
			}
		)
	);
}

/**
 * Próxima consulta de pagamento vivo: no vencimento mais próximo, no máximo em 30 minutos.
 *
 * @param array<int,array<string,mixed>> $open Pagamentos vivos.
 * @return int Segundos.
 */
function papelito_payment_reconcile_open_delay( array $open ): int {
	$delay = 30 * MINUTE_IN_SECONDS;

	foreach ( $open as $payment ) {
		$expiration = strtotime( (string) ( $payment['date_of_expiration'] ?? '' ) );
		if ( false !== $expiration && $expiration > time() ) {
			$delay = min( $delay, $expiration - time() + MINUTE_IN_SECONDS );
		}
	}

	return max( MINUTE_IN_SECONDS, $delay );
}

/**
 * Reconciliação Pagar.me: criação incerta pelo código do pedido, pendente pelo pedido remoto.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return string
 */
function papelito_payment_reconcile_pagarme( array $attempt ): string {
	$order = wc_get_order( (int) $attempt['order_id'] );

	if ( ! is_object( $order ) ) {
		return 'skipped';
	}

	if ( 'unknown' === $attempt['state'] ) {
		return papelito_payment_reconcile_pagarme_unknown( $attempt, $order );
	}

	if ( 'pending' !== $attempt['state'] ) {
		return (string) $attempt['state'];
	}

	$reconciled = papelito_pagarme_reconcile_wc_order( $order );
	if ( is_wp_error( $reconciled ) ) {
		return papelito_payment_reconcile_defer( $attempt );
	}

	return papelito_payment_reconcile_pagarme_state( $attempt, sanitize_key( (string) ( $reconciled['payment']['state'] ?? '' ) ) );
}

/**
 * Leva a tentativa Pagar.me ao estado da cobrança remota.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param string              $state   Estado da cobrança.
 * @return string
 */
function papelito_payment_reconcile_pagarme_state( array $attempt, string $state ): string {
	$target = papelito_payment_attempt_state_from_pagarme( $state );

	if ( $target !== $attempt['state'] ) {
		papelito_payment_attempt_transition( (int) $attempt['id'], (string) $attempt['state'], $target );
	}

	if ( 'pending' === $target ) {
		papelito_payment_reconcile_schedule( $attempt, 30 * MINUTE_IN_SECONDS, array( 'reconcile_failures' => 0 ) );
	}

	return $target;
}

/**
 * Procura na Pagar.me o pedido criado pela tentativa incerta (código `woo-order-{id}`).
 *
 * @param array<string,mixed> $attempt Tentativa `unknown`.
 * @param object              $order   Pedido.
 * @return string
 */
function papelito_payment_reconcile_pagarme_unknown( array $attempt, object $order ): string {
	$code     = 'woo-order-' . (int) $order->get_id();
	$response = papelito_pagarme_request( 'GET', 'orders?code=' . rawurlencode( $code ) );

	if ( is_wp_error( $response ) ) {
		return papelito_payment_reconcile_defer( $attempt );
	}

	$found = null;
	foreach ( (array) ( $response['data'] ?? array() ) as $remote ) {
		if ( is_array( $remote ) && (string) ( $remote['code'] ?? '' ) === $code ) {
			$found = $remote;
			break;
		}
	}

	if ( null === $found ) {
		return papelito_payment_reconcile_age( $attempt ) > PAPELITO_PAYMENT_UNKNOWN_WINDOW
			? papelito_payment_reconcile_expire( $attempt, 'creation_not_confirmed' )
			: 'waiting';
	}

	$snapshot = papelito_pagarme_store_order_response( $order, $found, (string) $attempt['payment_method'] );
	$state    = sanitize_key( (string) ( $snapshot['state'] ?? '' ) );
	papelito_payment_attempt_update( papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt, array( 'remote_id' => sanitize_text_field( (string) ( $found['id'] ?? '' ) ) ) );
	papelito_pagarme_apply_order_state( $order, $state, papelito_pagarme_payment_state_is_paid( $state ) );

	return papelito_payment_reconcile_pagarme_state( papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt, $state );
}

/**
 * Diz se o pedido pertence ao fluxo Pagar.me (para o cron legado não tocar pedidos do Mercado Pago).
 *
 * @param object $order Pedido.
 * @return bool
 */
function papelito_payment_reconcile_is_pagarme_order( object $order ): bool {
	return PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO !== sanitize_key( (string) $order->get_meta( PAPELITO_PAYMENT_PROVIDER_META, true ) );
}

/**
 * Consulta sob demanda quando o comprador abre um pedido Mercado Pago ainda em aberto.
 *
 * Pagamentos de teste não notificam e o webhook pode atrasar; sem isto o retorno do Checkout Pro
 * ficaria preso em "aguardando" até o próximo lote. Limitada a uma consulta a cada 10 s por pedido.
 *
 * @param object $order Pedido.
 * @return void
 */
function papelito_payment_maybe_reconcile_on_view( object $order ): void {
	if ( PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO !== sanitize_key( (string) $order->get_meta( PAPELITO_PAYMENT_PROVIDER_META, true ) ) ) {
		return;
	}

	$attempt = papelito_payment_attempt_find( absint( $order->get_meta( PAPELITO_PAYMENT_ATTEMPT_ID_META, true ) ) );
	$last    = (int) $order->get_meta( '_papelito_payment_last_view_reconcile', true );

	if ( ! is_array( $attempt ) || ! in_array( $attempt['state'], array( 'pending', 'unknown' ), true ) || ( time() - $last ) < 10 ) {
		return;
	}

	$order->update_meta_data( '_papelito_payment_last_view_reconcile', (string) time() );
	$order->save();
	papelito_payment_reconcile_attempt( $attempt );
}

/**
 * Cron: reconcilia as tentativas vencidas em lote.
 *
 * @return void
 */
function papelito_payment_reconcile_due(): void {
	if ( ! function_exists( 'papelito_payment_attempts_due' ) ) {
		return;
	}

	foreach ( papelito_payment_attempts_due( 25 ) as $attempt ) {
		papelito_payment_reconcile_attempt( $attempt );
	}
}

add_action( PAPELITO_PAYMENT_RECONCILE_HOOK, 'papelito_payment_reconcile_due' );

/**
 * Agenda a reconciliação a cada cinco minutos.
 *
 * @return void
 */
function papelito_payment_reconcile_schedule_cron(): void {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( PAPELITO_PAYMENT_RECONCILE_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'papelito_five_minutes', PAPELITO_PAYMENT_RECONCILE_HOOK );
	}
}

add_action( 'init', 'papelito_payment_reconcile_schedule_cron', 20 );
