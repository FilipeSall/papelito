<?php
/**
 * Webhook Pagar.me.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lê Basic Auth do request atual.
 *
 * @return array{user:string,pass:string}
 */
function papelito_pagarme_webhook_credentials(): array {
	$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) : '';

	if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	}

	if ( 0 !== stripos( $header, 'basic ' ) ) {
		return array( 'user' => '', 'pass' => '' );
	}

	$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
	if ( false === $decoded || ! str_contains( $decoded, ':' ) ) {
		return array( 'user' => '', 'pass' => '' );
	}

	list( $user, $pass ) = explode( ':', $decoded, 2 );

	return array(
		'user' => (string) $user,
		'pass' => (string) $pass,
	);
}

/**
 * Valida Basic Auth do webhook.
 *
 * @return true|WP_Error
 */
function papelito_pagarme_validate_webhook_auth() {
	$expected_user = papelito_pagarme_webhook_user();
	$expected_pass = papelito_pagarme_webhook_pass();
	$actual        = papelito_pagarme_webhook_credentials();

	if ( '' === $expected_user || '' === $expected_pass ) {
		return new WP_Error( 'papelito_pagarme_webhook_not_configured', 'Webhook Pagar.me nao configurado.', array( 'status' => 500 ) );
	}

	if ( ! hash_equals( $expected_user, $actual['user'] ) || ! hash_equals( $expected_pass, $actual['pass'] ) ) {
		return new WP_Error( 'papelito_pagarme_webhook_unauthorized', 'Credenciais invalidas.', array( 'status' => 401 ) );
	}

	return true;
}

/**
 * Busca pedido Woo por meta do pedido Pagar.me.
 */
function papelito_pagarme_find_wc_order_by_pagarme_order_id( string $pagarme_order_id ) {
	if ( '' === $pagarme_order_id || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}

	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => PAPELITO_PAGARME_ORDER_ID_META,
			'meta_value' => $pagarme_order_id,
			'return'     => 'objects',
		)
	);

	return isset( $orders[0] ) ? $orders[0] : null;
}

/**
 * Busca pedido Woo por meta da cobranca Pagar.me.
 */
function papelito_pagarme_find_wc_order_by_pagarme_charge_id( string $pagarme_charge_id ) {
	if ( '' === $pagarme_charge_id || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}

	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => PAPELITO_PAGARME_CHARGE_ID_META,
			'meta_value' => $pagarme_charge_id,
			'return'     => 'objects',
		)
	);

	return isset( $orders[0] ) ? $orders[0] : null;
}

/**
 * Busca vendor por recipient_id.
 */
function papelito_pagarme_find_vendor_by_recipient_id( string $recipient_id ): int {
	if ( '' === $recipient_id ) {
		return 0;
	}

	$users = get_users(
		array(
			'meta_key'   => PAPELITO_PAGARME_RECIPIENT_ID_META,
			'meta_value' => $recipient_id,
			'number'     => 1,
			'fields'     => 'ids',
		)
	);

	return isset( $users[0] ) ? (int) $users[0] : 0;
}

/**
 * Normaliza os possíveis ids presentes no payload.
 *
 * @param array<string,mixed> $payload Corpo do webhook.
 */
function papelito_pagarme_webhook_identifiers( array $payload ): array {
	$data       = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
	$event_type = sanitize_text_field( (string) ( $payload['type'] ?? $payload['event'] ?? '' ) );
	$order      = isset( $data['order'] ) && is_array( $data['order'] ) ? $data['order'] : array();
	$is_order   = 0 === strpos( $event_type, 'order.' );
	$is_charge  = 0 === strpos( $event_type, 'charge.' );

	return array(
		'event_type'    => $event_type,
		'order_id'      => sanitize_text_field( (string) ( $data['order_id'] ?? $order['id'] ?? ( $is_order ? ( $data['id'] ?? '' ) : '' ) ) ),
		'charge_id'     => sanitize_text_field( (string) ( $data['charge_id'] ?? ( $is_charge ? ( $data['id'] ?? '' ) : '' ) ) ),
		'recipient_id'  => sanitize_text_field( (string) ( $data['recipient_id'] ?? $data['id'] ?? '' ) ),
	);
}

/**
 * Processa um payload Pagar.me ja autenticado.
 *
 * @param array<string,mixed> $payload Corpo do evento.
 * @param callable|null       $reconcile_order Callback opcional para testes/simulador.
 */
function papelito_pagarme_process_webhook_payload( array $payload, ?callable $reconcile_order = null ) {
	$identifiers  = papelito_pagarme_webhook_identifiers( $payload );
	$event_type   = $identifiers['event_type'];

	if ( '' === $event_type ) {
		return new WP_Error( 'papelito_pagarme_webhook_invalid_payload', 'Evento invalido.', array( 'status' => 400 ) );
	}

	if ( 0 === strpos( $event_type, 'recipient.' ) ) {
		$recipient_id = $identifiers['recipient_id'];
		$vendor_id    = papelito_pagarme_find_vendor_by_recipient_id( $recipient_id );

		if ( $vendor_id > 0 ) {
			$synced = papelito_pagarme_sync_vendor_recipient( $vendor_id );
			if ( is_wp_error( $synced ) ) {
				return $synced;
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	$pagarme_order_id  = $identifiers['order_id'];
	$pagarme_charge_id = $identifiers['charge_id'];
	$order             = papelito_pagarme_find_wc_order_by_pagarme_order_id( $pagarme_order_id );

	if ( ! is_object( $order ) && '' !== $pagarme_charge_id ) {
		$order = papelito_pagarme_find_wc_order_by_pagarme_charge_id( $pagarme_charge_id );
	}

	if ( ! is_object( $order ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
	}

	$reconciled = is_callable( $reconcile_order )
		? $reconcile_order( $order, $payload, $identifiers )
		: papelito_pagarme_reconcile_wc_order( $order );

	if ( is_wp_error( $reconciled ) ) {
		return $reconciled;
	}

	$state = sanitize_key( (string) ( $reconciled['payment']['state'] ?? '' ) );

	if ( function_exists( 'papelito_pagarme_payment_state_releases_stock' ) && papelito_pagarme_payment_state_releases_stock( $state ) ) {
		papelito_pagarme_release_order_stock_for_terminal_state( $order, 'webhook_release' );
	}

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Processa o webhook Pagar.me.
 */
function papelito_pagarme_handle_webhook( WP_REST_Request $request ) {
	$auth = papelito_pagarme_validate_webhook_auth();

	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	return papelito_pagarme_process_webhook_payload( $payload );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/pagarme/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_pagarme_handle_webhook',
			)
		);
	}
);
