<?php
/**
 * Efeitos comuns de pagamento no pedido WooCommerce, para qualquer gateway.
 *
 * Liberação para envio, reabertura de pedido cancelado por falta de pagamento e encerramento sem
 * pagamento reaproveitam as regras de estoque por vendor já usadas pela Pagar.me
 * (`papelito_pagarme_promote_vendor_status_on_payment()` re-reserva quando dá, ou manda para
 * análise sem estoque). O estoque nativo do WooCommerce continua intocado.
 *
 * A regra de ouro: efeito é idempotente. Um marcador no pedido garante que a liberação para
 * envio acontece uma vez, mesmo com webhook repetido, cron simultâneo ou segunda aprovação da mesma
 * preferência; e pedido pago nunca é encerrado como não pago.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_PAYMENT_FULFILLMENT_RELEASED_META = '_papelito_payment_fulfillment_released';

/**
 * Diz se o pedido já foi liberado como pago por este módulo.
 *
 * @param object $order Pedido.
 * @return bool
 */
function papelito_payment_order_is_released( object $order ): bool {
	return '1' === (string) $order->get_meta( PAPELITO_PAYMENT_FULFILLMENT_RELEASED_META, true );
}

/**
 * Marca o pedido como pago e o libera para envio, uma única vez.
 *
 * @param object $order    Pedido.
 * @param string $provider Gateway que confirmou.
 * @return bool Se esta chamada fez a liberação.
 */
function papelito_payment_order_mark_paid( object $order, string $provider ): bool {
	if ( papelito_payment_order_is_released( $order ) ) {
		return false;
	}

	$order->update_meta_data( PAPELITO_PAYMENT_FULFILLMENT_RELEASED_META, '1' );
	$ready = function_exists( 'papelito_pagarme_promote_vendor_status_on_payment' ) ? papelito_pagarme_promote_vendor_status_on_payment( $order ) : true;
	$order->payment_complete();
	$order->update_status( $ready ? 'processing' : 'on-hold' );
	$order->add_order_note( sprintf( 'Pagamento confirmado pelo gateway %s.', sanitize_key( $provider ) ) );
	$order->save();

	do_action( 'papelito_order_payment_confirmed', $order, 'paid' );

	return true;
}

/**
 * Encerra o pedido sem pagamento: libera a reserva e cancela o status operacional.
 *
 * Pedido já pago nunca passa por aqui.
 *
 * @param object $order  Pedido.
 * @param string $reason Motivo curto da liberação.
 * @return bool Se esta chamada encerrou o pedido.
 */
function papelito_payment_order_mark_unpaid( object $order, string $reason ): bool {
	if ( papelito_payment_order_is_released( $order ) || in_array( $order->get_status(), array( 'processing', 'completed', 'failed', 'cancelled' ), true ) ) {
		return false;
	}

	if ( function_exists( 'papelito_pagarme_release_order_stock_for_terminal_state' ) ) {
		papelito_pagarme_release_order_stock_for_terminal_state( $order, sanitize_key( $reason ) );
	}

	$order->update_status( 'failed' );
	if ( function_exists( 'papelito_pagarme_mark_vendor_status_unpaid' ) ) {
		papelito_pagarme_mark_vendor_status_unpaid( $order );
	}
	$order->add_order_note( 'Pagamento não concluído: reserva de estoque liberada.' );
	$order->save();

	return true;
}
