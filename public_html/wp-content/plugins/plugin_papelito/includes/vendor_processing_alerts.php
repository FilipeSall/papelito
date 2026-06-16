<?php
/**
 * Alerta de prazo de processamento vencido para vendors.
 *
 * Verifica periodicamente (cron horario) pedidos pagos que continuam em
 * "aguardando_envio" (o vendor ainda nao comecou a separar) alem do prazo
 * de processamento configurado pelo vendor (shipping_lead_time_days) e
 * dispara notificacao in-app + e-mail. Idempotente por pedido via dedupe_key.
 *
 * Decisao tecnica: o prazo e medido em DIAS CORRIDOS a partir do pagamento
 * (date_paid), nao em dias uteis — o projeto nao possui calendario de feriados
 * e dias corridos sao mais simples e robustos.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_VENDOR_PROCESSING_OVERDUE_HOOK' ) ) {
	define( 'PAPELITO_VENDOR_PROCESSING_OVERDUE_HOOK', 'papelito_check_vendor_processing_overdue' );
}

if ( ! defined( 'PAPELITO_VENDOR_DEFAULT_LEAD_TIME_DAYS' ) ) {
	define( 'PAPELITO_VENDOR_DEFAULT_LEAD_TIME_DAYS', 2 );
}

/**
 * Resolve o prazo de processamento (em dias) de um vendor, com fallback.
 *
 * @param int $vendor_id ID do vendor.
 * @return int
 */
function papelito_vendor_processing_lead_time_days( int $vendor_id ): int {
	$lead_time = (int) get_user_meta( $vendor_id, 'shipping_lead_time_days', true );

	return $lead_time > 0 ? $lead_time : PAPELITO_VENDOR_DEFAULT_LEAD_TIME_DAYS;
}

/**
 * Resolve o timestamp real de confirmacao do pagamento para iniciar o prazo.
 *
 * Sem date_paid nao existe "tempo para entregar": pedidos pendentes ou legados
 * sem esse marco ficam fora da contagem para evitar cobrar o vendor antes da hora.
 *
 * @param object $order Pedido WooCommerce.
 * @return int
 */
function papelito_vendor_processing_paid_timestamp( $order ): int {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_date_paid' ) ) {
		return 0;
	}

	$paid_date = $order->get_date_paid();

	return is_object( $paid_date ) && method_exists( $paid_date, 'getTimestamp' )
		? (int) $paid_date->getTimestamp()
		: 0;
}

/**
 * Varre pedidos em "aguardando_envio" e alerta vendors que passaram do prazo.
 */
function papelito_check_vendor_processing_overdue(): void {
	if ( ! function_exists( 'wc_get_orders' ) || ! defined( 'PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT' ) ) {
		return;
	}

	$orders = wc_get_orders(
		array(
			'limit'      => -1,
			'orderby'    => 'date',
			'order'      => 'ASC',
			'meta_key'   => '_papelito_vendor_status',
			'meta_value' => PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT,
		)
	);

	if ( ! is_array( $orders ) ) {
		return;
	}

	$now = time();

	foreach ( $orders as $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			continue;
		}

		// Defesa extra: so alerta quem ainda nao comecou a separar.
		$status = sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) );
		if ( PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT !== $status ) {
			continue;
		}

		$vendor_id = function_exists( 'papelito_messaging_order_vendor_id' )
			? papelito_messaging_order_vendor_id( $order )
			: absint( $order->get_meta( '_papelito_vendor_id', true ) );

		if ( $vendor_id <= 0 ) {
			continue;
		}

		$lead_time = papelito_vendor_processing_lead_time_days( $vendor_id );
		$paid_ts   = papelito_vendor_processing_paid_timestamp( $order );

		if ( $paid_ts <= 0 ) {
			continue;
		}

		$deadline = $paid_ts + ( $lead_time * DAY_IN_SECONDS );

		if ( $now <= $deadline ) {
			continue;
		}

		$days_overdue = (int) floor( ( $now - $deadline ) / DAY_IN_SECONDS ) + 1;
		$dedupe_key   = sprintf( 'vendor_processing_overdue:%d:%d', $vendor_id, (int) $order->get_id() );

		$notification_id = papelito_dispatch_notification(
			$vendor_id,
			PAPELITO_NOTIF_PROCESSING_OVERDUE,
			array(
				'order_id'       => (int) $order->get_id(),
				'order_number'   => (string) $order->get_order_number(),
				'days_overdue'   => $days_overdue,
				'lead_time_days' => $lead_time,
			),
			$dedupe_key
		);

		if ( false === $notification_id ) {
			continue;
		}

		if ( ! papelito_claim_notification_email_dispatch( $vendor_id, PAPELITO_NOTIF_PROCESSING_OVERDUE, $dedupe_key ) ) {
			continue;
		}

		$vendor = get_user_by( 'id', $vendor_id );
		if ( $vendor instanceof WP_User ) {
			papelito_vendor_processing_overdue_send_email( $vendor, $order, $days_overdue, $lead_time );
		}
	}
}
add_action( PAPELITO_VENDOR_PROCESSING_OVERDUE_HOOK, 'papelito_check_vendor_processing_overdue' );

/**
 * Envia e-mail de prazo de separacao vencido ao vendor (texto plano).
 *
 * @param WP_User $vendor       Vendor destinatario.
 * @param object  $order        Pedido WooCommerce.
 * @param int     $days_overdue Dias de atraso.
 * @param int     $lead_time    Prazo de processamento configurado (dias).
 * @return bool
 */
function papelito_vendor_processing_overdue_send_email( WP_User $vendor, $order, int $days_overdue, int $lead_time ): bool {
	$recipient = sanitize_email( $vendor->user_email );

	if ( '' === $recipient || ! is_object( $order ) || ! method_exists( $order, 'get_order_number' ) ) {
		return false;
	}

	$store_name   = (string) get_user_meta( $vendor->ID, 'store_name', true );
	$greeting     = '' !== $store_name ? $store_name : $vendor->display_name;
	$order_number = (string) $order->get_order_number();
	$frontend_url = function_exists( 'papelito_auth_get_frontend_url' ) ? papelito_auth_get_frontend_url() : 'http://localhost:3000';
	$order_link   = sprintf( '%s/vendor/pedidos/%d', $frontend_url, (int) $order->get_id() );

	$subject    = sprintf( 'Prazo de separacao vencido - Papelito #%s', $order_number );
	$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
	$body_lines = array(
		sprintf( 'Ola %s,', '' !== $greeting ? $greeting : $recipient ),
		'',
		sprintf(
			'O pedido #%s passou do prazo de processamento de %d dia(s) e ainda nao entrou em separacao.',
			$order_number,
			$lead_time
		),
		sprintf( 'Atraso atual: %d dia(s).', $days_overdue ),
		'',
		'Separe e prepare o envio com urgencia para nao impactar o prazo do cliente.',
		'',
		'Acesse o detalhe abaixo:',
		$order_link,
		'',
		'Time Papelito',
	);

	return wp_mail( $recipient, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Agenda a verificacao periodica de prazos de processamento vencidos.
 */
function papelito_schedule_vendor_processing_overdue_check(): void {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	if ( ! wp_next_scheduled( PAPELITO_VENDOR_PROCESSING_OVERDUE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', PAPELITO_VENDOR_PROCESSING_OVERDUE_HOOK );
	}
}
add_action( 'init', 'papelito_schedule_vendor_processing_overdue_check' );
