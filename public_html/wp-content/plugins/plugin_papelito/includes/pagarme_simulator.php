<?php
/**
 * Simulador local/teste de eventos Pagar.me.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Indica se o simulador pode ser usado neste ambiente.
 */
function papelito_pagarme_simulator_enabled(): bool {
	$env_type = function_exists( 'papelito_pagarme_environment_type' )
		? papelito_pagarme_environment_type()
		: sanitize_key( (string) papelito_env( 'WP_ENVIRONMENT_TYPE', 'production' ) );

	if ( 'production' === $env_type ) {
		return false;
	}

	return function_exists( 'papelito_env_bool' )
		? papelito_env_bool( 'PAPELITO_PAGARME_SIMULATION_ENABLED', false )
		: filter_var( papelito_env( 'PAPELITO_PAGARME_SIMULATION_ENABLED', false ), FILTER_VALIDATE_BOOLEAN );
}

/**
 * Autoriza uso do simulador por admin ou token de desenvolvimento.
 */
function papelito_pagarme_simulator_permission( WP_REST_Request $request ) {
	if ( ! papelito_pagarme_simulator_enabled() ) {
		return new WP_Error(
			'papelito_pagarme_simulator_disabled',
			'Simulador Pagar.me indisponível neste ambiente.',
			array( 'status' => 404 )
		);
	}

	if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
		return true;
	}

	$expected = sanitize_text_field( (string) papelito_env( 'PAPELITO_PAGARME_SIMULATION_TOKEN', '' ) );
	$actual   = sanitize_text_field( (string) $request->get_header( 'x-papelito-dev-token' ) );

	if ( '' !== $expected && hash_equals( $expected, $actual ) ) {
		return true;
	}

	return new WP_Error(
		'papelito_pagarme_simulator_forbidden',
		'Acesso negado ao simulador Pagar.me.',
		array( 'status' => 403 )
	);
}

/**
 * Normaliza o cenario de simulacao.
 */
function papelito_pagarme_simulator_scenario( $value ): string {
	$scenario = sanitize_key( (string) $value );

	return in_array( $scenario, array( 'paid', 'pending', 'failed', 'expired', 'duplicate' ), true )
		? $scenario
		: '';
}

/**
 * Garante ids Pagar.me fake suficientes para localizar o pedido no processador.
 */
function papelito_pagarme_simulator_ensure_order_ids( object $order ): array {
	$order_id         = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	$pagarme_order_id = sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_ORDER_ID_META, true ) );
	$charge_id        = sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_CHARGE_ID_META, true ) );

	if ( '' === $pagarme_order_id ) {
		$pagarme_order_id = 'ord_sim_' . $order_id;
		$order->update_meta_data( PAPELITO_PAGARME_ORDER_ID_META, $pagarme_order_id );
	}

	if ( '' === $charge_id ) {
		$charge_id = 'ch_sim_' . $order_id;
		$order->update_meta_data( PAPELITO_PAGARME_CHARGE_ID_META, $charge_id );
	}

	if ( method_exists( $order, 'save' ) ) {
		$order->save();
	}

	return array(
		'order_id'  => $pagarme_order_id,
		'charge_id' => $charge_id,
	);
}

/**
 * Monta uma resposta Pagar.me fake com a mesma forma consumida pela reconciliacao.
 */
function papelito_pagarme_simulator_order_response( object $order, string $scenario ): array {
	$ids    = papelito_pagarme_simulator_ensure_order_ids( $order );
	$method = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ) );
	$state  = 'duplicate' === $scenario ? 'paid' : $scenario;

	if ( 'pending' === $state ) {
		$state = 'waiting_payment';
	}

	$last_transaction = array();

	if ( 'pix' === $method ) {
		$last_transaction = array(
			'qr_code'    => sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_PIX_COPY_PASTE_META, true ) ) ?: '00020126360014BR.GOV.BCB.PIX0114simulado-papelito',
			'expires_at' => sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_PIX_EXPIRES_AT_META, true ) ) ?: gmdate( 'c', time() + HOUR_IN_SECONDS ),
		);
	} elseif ( 'boleto' === $method ) {
		$last_transaction = array(
			'url'    => esc_url_raw( (string) $order->get_meta( PAPELITO_PAGARME_BOLETO_URL_META, true ) ) ?: 'https://example.test/boleto-simulado.pdf',
			'line'   => sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_BOLETO_LINE_META, true ) ) ?: '34191.79001 01043.510047 91020.150008 1 99990000010000',
			'due_at' => sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_BOLETO_EXPIRES_AT_META, true ) ) ?: gmdate( 'c', time() + DAY_IN_SECONDS ),
		);
	}

	return array(
		'id'      => $ids['order_id'],
		'status'  => $state,
		'charges' => array(
			array(
				'id'               => $ids['charge_id'],
				'status'           => $state,
				'last_transaction' => $last_transaction,
			),
		),
	);
}

/**
 * Aplica uma resposta simulada usando o mesmo caminho de estado do pagamento real.
 */
function papelito_pagarme_simulator_reconcile_order( object $order, array $payload, array $identifiers ) {
	$scenario = sanitize_key( (string) ( $payload['papelito_scenario'] ?? 'pending' ) );
	$method   = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ) );

	if ( '' === $method ) {
		$method = 'boleto';
	}

	$response = papelito_pagarme_simulator_order_response( $order, $scenario );
	$payment  = papelito_pagarme_store_order_response( $order, $response, $method );
	$charge   = papelito_pagarme_primary_charge( $response );
	$state    = sanitize_key( (string) ( $charge['status'] ?? $response['status'] ?? '' ) );
	$paid     = papelito_pagarme_payment_state_is_paid( $state );

	papelito_pagarme_apply_order_state( $order, $state, $paid );

	return array(
		'payment'  => $payment,
		'response' => $response,
	);
}

/**
 * Cria um payload de webhook fake para o processador compartilhado.
 */
function papelito_pagarme_simulator_payload( object $order, string $scenario ): array {
	$ids        = papelito_pagarme_simulator_ensure_order_ids( $order );
	$event_type = 'charge.' . ( 'duplicate' === $scenario ? 'paid' : $scenario );

	if ( 'failed' === $scenario ) {
		$event_type = 'charge.payment_failed';
	}

	return array(
		'type'              => $event_type,
		'papelito_scenario' => $scenario,
		'data'              => array(
			'id'       => $ids['charge_id'],
			'order_id' => $ids['order_id'],
		),
	);
}

/**
 * Executa a simulacao de webhook.
 */
function papelito_pagarme_handle_simulate_webhook( WP_REST_Request $request ) {
	$order_id     = absint( $request->get_param( 'order_id' ) );
	$scenario     = papelito_pagarme_simulator_scenario( $request->get_param( 'scenario' ) );
	$repeat_count = min( 5, max( 1, (int) $request->get_param( 'repeat_count' ) ) );

	if ( $order_id <= 0 || '' === $scenario ) {
		return new WP_Error(
			'papelito_pagarme_simulator_invalid_payload',
			'Informe order_id e scenario validos.',
			array( 'status' => 422 )
		);
	}

	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return new WP_Error(
			'papelito_pagarme_simulator_order_not_found',
			'Pedido não encontrado.',
			array( 'status' => 404 )
		);
	}

	$response = null;

	for ( $index = 0; $index < $repeat_count; $index++ ) {
		$response = papelito_pagarme_process_webhook_payload(
			papelito_pagarme_simulator_payload( $order, $scenario ),
			'papelito_pagarme_simulator_reconcile_order'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
	}

	return new WP_REST_Response(
		array(
			'ok'              => true,
			'order_id'        => $order_id,
			'scenario'        => $scenario,
			'processed_count' => $repeat_count,
			'payment_state'   => sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ) ),
		),
		200
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/dev/pagarme/simulate-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_pagarme_simulator_permission',
				'callback'            => 'papelito_pagarme_handle_simulate_webhook',
			)
		);
	}
);
