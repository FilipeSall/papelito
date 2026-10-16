<?php
/**
 * Orquestrador do checkout com gateways (Mercado Pago e Pagar.me) para vendors da coorte.
 *
 * Recebe do place-order o pedido já validado (itens, frete, preço, empresa) e o `PaymentContext`
 * resolvido no backend, cria um único pedido WooCommerce com uma reserva, registra a tentativa —
 * gateway, ambiente e conta — antes de qualquer chamada remota, e emite no gateway efetivo.
 *
 * A regra de ouro: um checkout, um pedido, uma reserva, no máximo uma emissão por tentativa. Trava
 * nomeada serializa o mesmo checkout; retry devolve a tentativa existente. Se o Mercado Pago falha
 * comprovadamente antes de criar, a alternativa Pagar.me reaproveita o mesmo pedido e reserva —
 * cobrando direto se já há método, ou pedindo os dados (`requires_payment_input`) para a
 * continuação. Resultado incerto vira reconciliação; nunca troca de gateway.
 *
 * Vendors fora da coorte continuam no caminho legado de `order_routing.php`, intocado.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_CHECKOUT_ADDRESS_META = '_papelito_checkout_address';
const PAPELITO_PAYMENT_INPUT_TTL     = 3600;

/**
 * Ambiente da chave Pagar.me configurada.
 *
 * @return string `test` ou `live`.
 */
function papelito_payment_checkout_pagarme_environment(): string {
	$key = function_exists( 'papelito_pagarme_secret_key' ) ? papelito_pagarme_secret_key() : '';

	return 0 === strpos( $key, 'sk_test_' ) ? 'test' : 'live';
}

/**
 * Coloca o pedido do vendor da coorte. Ponto de entrada chamado pelo place-order.
 *
 * @param array<string,mixed> $request Dados validados do checkout (ver `papelito_order_routing_handle_place_order`).
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_place( array $request ) {
	$lock = 'checkout:' . (int) $request['user_id'] . ':' . ( '' !== (string) $request['checkout_attempt_id'] ? (string) $request['checkout_attempt_id'] : (string) $request['commercial_hash'] );

	if ( ! papelito_payment_attempt_acquire_lock( $lock, 5 ) ) {
		return new WP_Error( 'papelito_checkout_attempt_in_progress', 'Este checkout já esta sendo processado. Aguarde alguns instantes.', array( 'status' => 409 ) );
	}

	try {
		return papelito_payment_checkout_place_locked( $request );
	} finally {
		papelito_payment_attempt_release_lock( $lock );
	}
}

/**
 * Corpo do place-order sob a trava do checkout.
 *
 * @param array<string,mixed> $request Dados validados do checkout.
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_place_locked( array $request ) {
	$replay = papelito_order_routing_replay_checkout_attempt(
		(int) $request['user_id'],
		(string) $request['checkout_attempt_id'],
		(int) ( $request['b2b_snapshot']['company_id'] ?? 0 ),
		(string) $request['request_hash'],
		(string) $request['commercial_hash']
	);

	if ( null !== $replay ) {
		return $replay;
	}

	$context = (array) $request['context'];
	$payment = papelito_payment_checkout_requested_payment( $request, $context );

	if ( is_wp_error( $payment ) ) {
		return $payment;
	}

	$order = papelito_payment_checkout_create_order( $request, $context, $payment );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	if ( PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO === $context['provider'] ) {
		return papelito_payment_checkout_mercadopago( $order, $request, $context, $payment );
	}

	return papelito_payment_checkout_start_pagarme( $order, $request, $context, $payment, '' );
}

/**
 * Pagamento informado no checkout, quando o gateway efetivo é a Pagar.me.
 *
 * Sem método escolhido devolve `null` (os dados serão pedidos na continuação). Método inválido ou
 * cartão incompleto recusa antes de existir pedido. No Mercado Pago o método é escolhido no Pro.
 *
 * @param array<string,mixed> $request Dados do checkout.
 * @param array<string,mixed> $context PaymentContext.
 * @return array<string,mixed>|WP_Error|null
 */
function papelito_payment_checkout_requested_payment( array $request, array $context ) {
	$raw = $request['payload']['payment'] ?? null;

	if ( ! is_array( $raw ) || '' === (string) ( $raw['method'] ?? '' ) ) {
		return null;
	}

	if ( PAPELITO_PAYMENT_PROVIDER_PAGARME === $context['provider'] ) {
		return papelito_payment_checkout_validate_pagarme_payment( $request['payload'], (array) $request['address'], (int) $request['pricing']['totals']['totalCents'], (int) $request['vendor_id'] );
	}

	$normalized = papelito_order_routing_normalize_payment( $raw, (array) $request['address'] );

	return is_wp_error( $normalized ) ? null : $normalized;
}

/**
 * Valida pagamento para a Pagar.me: método, valor mínimo e recebedor apto.
 *
 * @param array<string,mixed> $payload   Payload com `payment`.
 * @param array<string,mixed> $address   Endereço normalizado.
 * @param int                 $total     Total em centavos.
 * @param int                 $vendor_id Vendor.
 * @return array<string,mixed>|WP_Error
 */
function papelito_payment_checkout_validate_pagarme_payment( array $payload, array $address, int $total, int $vendor_id ) {
	if ( ! function_exists( 'papelito_pagarme_is_configured' ) || ! papelito_pagarme_is_configured() ) {
		return new WP_Error( 'papelito_checkout_payment_unavailable', 'Checkout indisponível até a configuração do Pagar.me.', array( 'status' => 501 ) );
	}

	return papelito_order_routing_resolve_checkout_payment( $payload, $address, $total, $vendor_id );
}

/**
 * Cria o pedido e a reserva do checkout. Uma vez só por checkout.
 *
 * @param array<string,mixed>      $request Dados do checkout.
 * @param array<string,mixed>      $context PaymentContext.
 * @param array<string,mixed>|null $payment Pagamento já validado, quando houver.
 * @return object|WP_Error Pedido WooCommerce.
 */
function papelito_payment_checkout_create_order( array $request, array $context, ?array $payment ) {
	$method  = is_array( $payment ) && PAPELITO_PAYMENT_PROVIDER_PAGARME === $context['provider'] ? (string) $payment['method'] : (string) $context['provider'];
	$created = papelito_order_routing_create_order(
		(int) $request['user_id'],
		(array) $request['address'],
		(array) $request['lines'],
		(array) $request['shipping'],
		$request['coupon'],
		$method,
		array(
			'checkout_attempt_id' => (string) $request['checkout_attempt_id'],
			'b2b_snapshot'        => (array) $request['b2b_snapshot'],
			'request_hash'        => (string) $request['request_hash'],
			'commercial_hash'     => (string) $request['commercial_hash'],
			'logistics_snapshot'  => $request['logistics_snapshot'],
		)
	);

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$order = wc_get_order( (int) $created['order_id'] );
	if ( ! is_object( $order ) ) {
		return new WP_Error( 'papelito_checkout_invalid_order_instance', 'Não foi possível recuperar o pedido recem-criado.', array( 'status' => 500 ) );
	}

	$order->update_meta_data( PAPELITO_CHECKOUT_ADDRESS_META, wp_json_encode( $request['address'] ) );
	if ( function_exists( 'papelito_ga4_store_order_identifiers' ) && is_array( $request['payload']['analytics'] ?? null ) ) {
		papelito_ga4_store_order_identifiers( $order, $request['payload']['analytics'] );
	}

	$reserved = papelito_pagarme_reserve_order_stock( $order, (array) $request['lines'] );
	if ( is_wp_error( $reserved ) ) {
		papelito_order_routing_fail_created_order( $order, (array) $request['lines'], 'Falha ao reservar estoque para o pagamento: ' . $reserved->get_error_message() );
		return $reserved;
	}

	return $order;
}

/**
 * Registra a tentativa do gateway e grava o snapshot de cobrança no pedido, antes da emissão.
 *
 * @param object              $order   Pedido.
 * @param array<string,mixed> $request Dados do checkout.
 * @param array<string,mixed> $fields  provider, state, flow, account_id, account_ref, environment, fallback_reason, configuration_version, payment_method.
 * @return array<string,mixed>|WP_Error
 */
function papelito_payment_checkout_open_attempt( object $order, array $request, array $fields ) {
	$attempt = papelito_payment_attempt_create(
		array_merge(
			array(
				'order_id'            => (int) $order->get_id(),
				'checkout_attempt_id' => (string) $request['checkout_attempt_id'],
				'buyer_user_id'       => (int) $request['user_id'],
				'company_id'          => (int) ( $request['b2b_snapshot']['company_id'] ?? 0 ),
				'vendor_id'           => (int) $request['vendor_id'],
				'amount_cents'        => (int) $request['pricing']['totals']['totalCents'],
				'commercial_hash'     => (string) $request['commercial_hash'],
			),
			$fields
		)
	);

	if ( is_wp_error( $attempt ) ) {
		return $attempt;
	}

	papelito_payment_store_order_snapshot(
		$order,
		array(
			'provider'    => $attempt['provider'],
			'environment' => $attempt['environment'],
			'account_id'  => $attempt['account_id'],
			'account_ref' => $attempt['account_ref'],
			'attempt_id'  => $attempt['id'],
		)
	);
	$order->save();

	return $attempt;
}

/**
 * Fluxo Mercado Pago: tentativa, preferência e, se ela falhar antes de criar, a alternativa.
 *
 * @param object                   $order   Pedido com reserva.
 * @param array<string,mixed>      $request Dados do checkout.
 * @param array<string,mixed>      $context PaymentContext.
 * @param array<string,mixed>|null $payment Pagamento informado (para uma eventual alternativa).
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_mercadopago( object $order, array $request, array $context, ?array $payment ) {
	$account = papelito_vendor_payment_account_current( (int) $request['vendor_id'], PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO, papelito_mercadopago_environment() );
	$attempt = papelito_payment_checkout_open_attempt(
		$order,
		$request,
		array(
			'provider'              => PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO,
			'environment'           => papelito_mercadopago_environment(),
			'account_id'            => is_array( $account ) ? (int) $account['id'] : 0,
			'account_ref'           => is_array( $account ) ? (string) $account['collector_id'] : '',
			'flow'                  => PAPELITO_PAYMENT_FLOW_REDIRECT,
			'fallback_reason'       => (string) $context['fallbackReason'],
			'configuration_version' => (string) $context['configurationVersion'],
		)
	);

	if ( is_wp_error( $attempt ) ) {
		papelito_order_routing_fail_created_order( $order, (array) $request['lines'], 'Falha ao registrar a tentativa de pagamento.', 'payment_error' );
		return $attempt;
	}

	$result = papelito_mercadopago_create_preference( $attempt, papelito_payment_checkout_order_data( $order, $request ) );

	if ( 'pending' === ( $result['state'] ?? '' ) ) {
		return papelito_payment_checkout_response( $order, $result, $request );
	}

	if ( 'unknown' === ( $result['state'] ?? '' ) ) {
		return papelito_payment_checkout_reconciling( $order );
	}

	if ( papelito_payment_gateway_attempt_allows_switch( $result ) ) {
		return papelito_payment_checkout_mercadopago_fallback( $order, $request, $payment );
	}

	$restricted = 'business_restriction' === ( $result['last_error_code'] ?? '' );
	papelito_order_routing_fail_created_order( $order, (array) $request['lines'], 'Mercado Pago recusou a criação do pagamento: ' . sanitize_key( (string) ( $result['last_error_code'] ?? '' ) ), 'payment_error' );

	return new WP_Error(
		$restricted ? 'papelito_checkout_vendor_not_approved' : 'papelito_checkout_payment_unavailable',
		$restricted ? 'O vendor selecionado não esta apto para receber pagamentos.' : 'Não foi possível iniciar o pagamento. Tente novamente.',
		array( 'status' => $restricted ? 422 : 502 )
	);
}

/**
 * Alternativa depois de o Mercado Pago falhar comprovadamente antes de criar.
 *
 * @param object                   $order   Pedido com reserva.
 * @param array<string,mixed>      $request Dados do checkout.
 * @param array<string,mixed>|null $payment Pagamento informado.
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_mercadopago_fallback( object $order, array $request, ?array $payment ) {
	$alternative = papelito_payment_gateway_resolve( (int) $request['vendor_id'], array( 'exclude' => array( PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO ) ) );

	if ( is_wp_error( $alternative ) || PAPELITO_PAYMENT_PROVIDER_PAGARME !== $alternative['provider'] ) {
		papelito_order_routing_fail_created_order( $order, (array) $request['lines'], 'Mercado Pago indisponível e sem gateway alternativo apto.', 'payment_error' );
		return new WP_Error( 'papelito_checkout_payment_unavailable', 'Nenhuma forma de pagamento está disponível agora. Tente novamente em instantes.', array( 'status' => 503 ) );
	}

	$validated = is_array( $payment )
		? papelito_payment_checkout_validate_pagarme_payment( (array) $request['payload'], (array) $request['address'], (int) $request['pricing']['totals']['totalCents'], (int) $request['vendor_id'] )
		: null;

	return papelito_payment_checkout_start_pagarme( $order, $request, $alternative, is_wp_error( $validated ) ? null : $validated, (string) $alternative['fallbackReason'] );
}

/**
 * Abre a tentativa Pagar.me no pedido e cobra, ou pede os dados de pagamento.
 *
 * @param object                   $order   Pedido com reserva.
 * @param array<string,mixed>      $request Dados do checkout.
 * @param array<string,mixed>      $context PaymentContext da Pagar.me.
 * @param array<string,mixed>|null $payment Pagamento validado, ou nulo para coletar depois.
 * @param string                   $reason  Motivo do fallback, quando houver.
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_start_pagarme( object $order, array $request, array $context, ?array $payment, string $reason ) {
	$attempt = papelito_payment_checkout_open_attempt(
		$order,
		$request,
		array(
			'provider'              => PAPELITO_PAYMENT_PROVIDER_PAGARME,
			'environment'           => papelito_payment_checkout_pagarme_environment(),
			'account_ref'           => papelito_pagarme_get_vendor_recipient_id( (int) $request['vendor_id'] ),
			'state'                 => null === $payment ? 'awaiting_payment_input' : 'not_started',
			'flow'                  => null === $payment ? PAPELITO_PAYMENT_FLOW_REQUIRES_PAYMENT_INPUT : PAPELITO_PAYMENT_FLOW_DIRECT,
			'payment_method'        => is_array( $payment ) ? (string) $payment['method'] : '',
			'fallback_reason'       => '' !== $reason ? $reason : (string) $context['fallbackReason'],
			'configuration_version' => (string) $context['configurationVersion'],
			'expires_at'            => null === $payment ? gmdate( 'Y-m-d H:i:s', time() + PAPELITO_PAYMENT_INPUT_TTL ) : null,
			'next_reconcile_at'     => null === $payment ? gmdate( 'Y-m-d H:i:s', time() + PAPELITO_PAYMENT_INPUT_TTL ) : null,
		)
	);

	if ( is_wp_error( $attempt ) ) {
		papelito_order_routing_fail_created_order( $order, (array) $request['lines'], 'Falha ao registrar a tentativa de pagamento.', 'payment_error' );
		return $attempt;
	}

	if ( null === $payment ) {
		return papelito_payment_checkout_response( $order, $attempt, $request );
	}

	return papelito_payment_checkout_charge_pagarme( $order, $attempt, $payment, (array) $request['address'], (array) $request['lines'], (array) $request['shipping'], $request );
}

/**
 * Cobra na Pagar.me a tentativa e projeta o resultado.
 *
 * @param object                   $order    Pedido.
 * @param array<string,mixed>      $attempt  Tentativa em `not_started` ou `awaiting_payment_input`.
 * @param array<string,mixed>      $payment  Pagamento validado.
 * @param array<string,mixed>      $address  Endereço normalizado.
 * @param array<int,array>         $lines    Linhas do pedido.
 * @param array<string,mixed>      $shipping Frete.
 * @param array<string,mixed>|null $request  Dados do checkout (nulo na continuação).
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_charge_pagarme( object $order, array $attempt, array $payment, array $address, array $lines, array $shipping, ?array $request ) {
	if ( ! papelito_payment_attempt_transition( (int) $attempt['id'], (string) $attempt['state'], 'creating', array( 'payment_method' => (string) $payment['method'] ) ) ) {
		return papelito_payment_checkout_existing( $order );
	}

	$result = papelito_pagarme_create_order_payment( $order, (int) $attempt['buyer_user_id'], $payment, $address, $lines, $shipping );

	if ( is_wp_error( $result ) ) {
		return papelito_payment_checkout_pagarme_failure( $order, $attempt, $lines, $result );
	}

	$remote_id = sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_ORDER_ID_META, true ) );
	if ( '' === $remote_id ) {
		return papelito_payment_checkout_pagarme_unknown( $order, $attempt );
	}

	$state  = sanitize_key( (string) ( $result['payment']['state'] ?? '' ) );
	$target = papelito_payment_attempt_state_from_pagarme( $state );

	papelito_payment_attempt_transition( (int) $attempt['id'], 'creating', $target, array( 'remote_id' => $remote_id ) );

	if ( 'failed' === $target && function_exists( 'papelito_pagarme_release_order_stock' ) ) {
		papelito_pagarme_release_order_stock( $order, $lines, 'payment_failed' );
	}

	return papelito_payment_checkout_response( $order, papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt, $request, $result['payment'] ?? array() );
}

/**
 * Diz se o erro da Pagar.me deixa incerto se a cobrança foi criada.
 *
 * Rede, timeout e 5xx podem ter chegado ao gateway; recusa 4xx é definitiva.
 *
 * @param WP_Error $error Erro da Pagar.me.
 * @return bool
 */
function papelito_payment_checkout_pagarme_outcome_uncertain( WP_Error $error ): bool {
	if ( 'papelito_pagarme_network_error' === $error->get_error_code() ) {
		return true;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

	return 'papelito_pagarme_request_failed' === $error->get_error_code() && ( $status >= 500 || 408 === $status );
}

/**
 * Leva a tentativa Pagar.me para `unknown`, mantendo pedido e reserva para a reconciliação.
 *
 * @param object $order   Pedido.
 * @param array  $attempt Tentativa em criação.
 * @return WP_Error
 */
function papelito_payment_checkout_pagarme_unknown( object $order, array $attempt ): WP_Error {
	papelito_payment_attempt_transition(
		(int) $attempt['id'],
		'creating',
		'unknown',
		array(
			'last_error_code'   => 'unknown_outcome',
			'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() + 120 ),
		)
	);

	return papelito_payment_checkout_reconciling( $order );
}

/**
 * Falha na criação Pagar.me: resultado incerto reconcilia, recusa definitiva falha o pedido.
 *
 * @param object           $order   Pedido.
 * @param array            $attempt Tentativa em criação.
 * @param array<int,array> $lines   Linhas.
 * @param WP_Error         $error   Erro da Pagar.me.
 * @return WP_Error
 */
function papelito_payment_checkout_pagarme_failure( object $order, array $attempt, array $lines, WP_Error $error ): WP_Error {
	if ( papelito_payment_checkout_pagarme_outcome_uncertain( $error ) ) {
		return papelito_payment_checkout_pagarme_unknown( $order, $attempt );
	}

	papelito_payment_attempt_transition( (int) $attempt['id'], 'creating', 'failed', array( 'last_error_code' => sanitize_key( $error->get_error_code() ) ) );
	papelito_order_routing_fail_created_order( $order, $lines, 'Falha ao criar pedido no Pagar.me: ' . $error->get_error_message(), 'payment_error' );

	return function_exists( 'papelito_order_routing_gateway_error' ) ? papelito_order_routing_gateway_error( $error ) : $error;
}

/**
 * Erro de pagamento em reconciliação, com o pedido para o comprador acompanhar.
 *
 * @param object $order Pedido.
 * @return WP_Error
 */
function papelito_payment_checkout_reconciling( object $order ): WP_Error {
	return new WP_Error(
		'papelito_payment_attempt_reconciling',
		'Estamos confirmando o pagamento deste pedido com o gateway. Acompanhe pelo pedido antes de tentar de novo.',
		array(
			'status'  => 409,
			'orderId' => (int) $order->get_id(),
		)
	);
}

/**
 * Resposta do pedido já existente (retry), pelo estado da tentativa.
 *
 * @param object $order Pedido.
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_existing( object $order ) {
	$response = papelito_order_routing_existing_order_response( $order );

	return is_wp_error( $response ) ? $response : new WP_REST_Response( $response, 200 );
}

/**
 * Resposta do place-order/continuação com pedido, tentativa e totais.
 *
 * @param object                   $order   Pedido.
 * @param array<string,mixed>      $attempt Tentativa.
 * @param array<string,mixed>|null $request Dados do checkout (com pricing), quando houver.
 * @param array<string,mixed>      $direct  Pagamento direto (PIX/boleto) devolvido pela Pagar.me.
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_response( object $order, array $attempt, ?array $request, array $direct = array() ) {
	if ( null === $request || ! isset( $request['pricing'] ) ) {
		return papelito_payment_checkout_existing( $order );
	}

	$result = array(
		'order_id'     => (int) $order->get_id(),
		'order_number' => (string) $order->get_order_number(),
		'status'       => (string) $order->get_status(),
		'payment'      => array_merge(
			array(
				'method' => (string) ( $attempt['payment_method'] ?? '' ),
				'state'  => (string) $attempt['state'],
			),
			$direct,
			papelito_payment_attempt_public( $attempt )
		),
	);

	if ( function_exists( 'papelito_order_routing_decorate_place_order_response' ) ) {
		$result = papelito_order_routing_decorate_place_order_response( $result, (array) $request['pricing'], (array) $request['lines'] );
	}

	return new WP_REST_Response( $result, 200 );
}

/**
 * Dados autoritativos do pedido para a preferência Mercado Pago.
 *
 * @param object              $order   Pedido.
 * @param array<string,mixed> $request Dados do checkout.
 * @return array<string,mixed>
 */
function papelito_payment_checkout_order_data( object $order, array $request ): array {
	$company  = (array) ( $request['b2b_snapshot']['company'] ?? array() );
	$shipping = (array) $request['shipping'];
	$lines    = array();

	foreach ( (array) $request['lines'] as $line ) {
		$lines[] = array(
			'product_id'  => (int) $line['product_id'],
			'name'        => is_object( $line['product'] ?? null ) && method_exists( $line['product'], 'get_name' ) ? (string) $line['product']->get_name() : (string) ( $line['name'] ?? 'Produto Papelito' ),
			'qty'         => (int) $line['qty'],
			'total_cents' => (int) ( $line['total_cents'] ?? round( 100 * (float) ( $line['total'] ?? 0 ) ) ),
		);
	}

	$installments = function_exists( 'papelito_pricing_get_installment_config' ) ? (int) papelito_pricing_get_installment_config()['maxInstallments'] : 1;

	return array(
		'order_id'         => (int) $order->get_id(),
		'total_cents'      => (int) $request['pricing']['totals']['totalCents'],
		'shipping_cents'   => max( 0, (int) round( 100 * (float) ( $shipping['price'] ?? 0 ) ) - (int) round( 100 * (float) ( $shipping['discount'] ?? 0 ) ) ),
		'lines'            => $lines,
		'payer'            => array(
			'legal_name' => (string) ( $company['legal_name'] ?? '' ),
			'email'      => (string) ( $company['billing_email'] ?? '' ),
			'cnpj'       => (string) ( $company['cnpj'] ?? '' ),
		),
		'frontend_base'    => (string) $request['frontend_base'],
		'max_installments' => $installments,
	);
}

/**
 * `POST /checkout/payment-attempts/{paymentAttemptId}/continue`: completa o pagamento Pagar.me.
 *
 * Só o comprador da tentativa, na mesma empresa, com a tentativa em `awaiting_payment_input` e a
 * configuração ainda igual à que gerou a tentativa. Retries devolvem o resultado sem reemitir.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_continue( WP_REST_Request $request ) {
	$attempt = papelito_payment_attempt_find( absint( $request->get_param( 'paymentAttemptId' ) ) );
	$user_id = get_current_user_id();

	if ( ! is_array( $attempt ) || (int) $attempt['buyer_user_id'] !== $user_id ) {
		return new WP_Error( 'papelito_payment_attempt_not_found', 'Pagamento não encontrado.', array( 'status' => 404 ) );
	}

	$body     = (array) $request->get_json_params();
	$snapshot = papelito_order_routing_resolve_b2b_snapshot( $user_id, array( 'expected_company_id' => $body['expectedCompanyId'] ?? 0 ) );

	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}

	if ( (int) $snapshot['company_id'] !== (int) $attempt['company_id'] ) {
		return new WP_Error( 'papelito_payment_attempt_not_found', 'Pagamento não encontrado.', array( 'status' => 404 ) );
	}

	$lock = 'attempt:' . (int) $attempt['id'];
	if ( ! papelito_payment_attempt_acquire_lock( $lock, 5 ) ) {
		return new WP_Error( 'papelito_checkout_attempt_in_progress', 'Este pagamento já está sendo processado.', array( 'status' => 409 ) );
	}

	try {
		return papelito_payment_checkout_continue_locked( (int) $attempt['id'], $body );
	} finally {
		papelito_payment_attempt_release_lock( $lock );
	}
}

/**
 * Corpo da continuação sob a trava da tentativa.
 *
 * @param int                 $attempt_id Tentativa.
 * @param array<string,mixed> $body       Corpo da requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_checkout_continue_locked( int $attempt_id, array $body ) {
	$attempt = papelito_payment_attempt_find( $attempt_id );
	$order   = is_array( $attempt ) ? wc_get_order( (int) $attempt['order_id'] ) : null;

	if ( ! is_array( $attempt ) || ! is_object( $order ) ) {
		return new WP_Error( 'papelito_payment_attempt_not_found', 'Pagamento não encontrado.', array( 'status' => 404 ) );
	}

	if ( 'awaiting_payment_input' !== $attempt['state'] ) {
		return in_array( $attempt['state'], array( 'failed', 'expired', 'refunded' ), true )
			? new WP_Error( 'papelito_payment_attempt_closed', 'Este pagamento não pode mais ser concluído. Refaça o pedido.', array( 'status' => 409 ) )
			: papelito_payment_checkout_existing( $order );
	}

	$closed = papelito_payment_checkout_input_closed( $order, $attempt );
	if ( null !== $closed ) {
		return $closed;
	}

	$context = papelito_payment_checkout_resolve_for_attempt( $attempt );
	$version = (string) $attempt['configuration_version'];

	if ( ! papelito_payment_checkout_context_matches( $context, $version, (string) ( $body['configurationVersion'] ?? '' ) ) ) {
		return papelito_payment_checkout_stale_context( $order, $attempt, $context );
	}

	$address = json_decode( (string) $order->get_meta( PAPELITO_CHECKOUT_ADDRESS_META, true ), true );
	$address = is_array( $address ) ? $address : array();
	$payment = papelito_payment_checkout_validate_pagarme_payment( array( 'payment' => $body['payment'] ?? null ), $address, (int) $attempt['amount_cents'], (int) $attempt['vendor_id'] );

	if ( is_wp_error( $payment ) ) {
		return $payment;
	}

	return papelito_payment_checkout_charge_pagarme( $order, $attempt, $payment, $address, papelito_order_routing_order_lines( $order ), papelito_payment_checkout_order_shipping( $order ), null );
}

/**
 * Contexto de gateway atual para a tentativa, excluindo o gateway que falhou antes de criar.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return array<string,mixed>|WP_Error
 */
function papelito_payment_checkout_resolve_for_attempt( array $attempt ) {
	return papelito_payment_gateway_resolve( (int) $attempt['vendor_id'], array( 'exclude' => papelito_payment_checkout_attempt_exclusions( $attempt ) ) );
}

/**
 * Recusa de continuação para pedido que não está mais aguardando pagamento ou prazo vencido.
 *
 * Pedido cancelado, falho ou sem reserva nunca é cobrado; coleta fora do prazo é encerrada e a
 * reserva liberada.
 *
 * @param object              $order   Pedido.
 * @param array<string,mixed> $attempt Tentativa `awaiting_payment_input`.
 * @return WP_Error|null
 */
function papelito_payment_checkout_input_closed( object $order, array $attempt ): ?WP_Error {
	$expires = strtotime( (string) ( $attempt['expires_at'] ?? '' ) . ' UTC' );
	$expired = false !== $expires && $expires > 0 && $expires < time();
	$open    = 'pending' === $order->get_status() && '1' === (string) $order->get_meta( '_papelito_stock_reserved', true );

	if ( $open && ! $expired ) {
		return null;
	}

	papelito_payment_attempt_transition( (int) $attempt['id'], 'awaiting_payment_input', 'expired', array( 'last_error_code' => $expired ? 'payment_input_timeout' : 'order_closed' ) );

	if ( $open ) {
		papelito_order_routing_fail_created_order( $order, papelito_order_routing_order_lines( $order ), 'Pagamento não concluído no prazo: reserva de estoque liberada.', 'payment_expired' );
	}

	return new WP_Error( 'papelito_payment_attempt_closed', 'Este pagamento não pode mais ser concluído. Refaça o pedido.', array( 'status' => 409 ) );
}

/**
 * Resposta para continuação cujo contexto mudou, sempre sem cobrar.
 *
 * Se a Pagar.me continua apta, a tentativa passa a esperar a versão nova e o cliente recebe essa
 * versão para confirmar e reenviar. Se não há mais gateway para esta tentativa, ela é encerrada,
 * a reserva é liberada e o comprador é orientado a refazer o pedido.
 *
 * @param object                       $order   Pedido.
 * @param array<string,mixed>          $attempt Tentativa `awaiting_payment_input`.
 * @param array<string,mixed>|WP_Error $context Contexto atual.
 * @return WP_Error
 */
function papelito_payment_checkout_stale_context( object $order, array $attempt, $context ): WP_Error {
	if ( ! is_wp_error( $context ) && PAPELITO_PAYMENT_PROVIDER_PAGARME === $context['provider'] ) {
		$current = (string) $context['configurationVersion'];
		papelito_payment_attempt_update( $attempt, array( 'configuration_version' => $current ) );

		return new WP_Error(
			'papelito_payment_configuration_stale',
			'As condições de pagamento desta loja mudaram. Revise e confirme o pagamento de novo.',
			array(
				'status'               => 409,
				'configurationVersion' => $current,
			)
		);
	}

	papelito_payment_attempt_transition( (int) $attempt['id'], 'awaiting_payment_input', 'expired', array( 'last_error_code' => 'no_gateway_available' ) );
	papelito_order_routing_fail_created_order( $order, papelito_order_routing_order_lines( $order ), 'Pagamento não concluído: nenhum gateway apto para concluir a cobrança.', 'payment_expired' );

	return new WP_Error( 'papelito_payment_attempt_closed', 'Esta loja não tem mais forma de pagamento disponível para este pedido. Refaça o pedido mais tarde.', array( 'status' => 409 ) );
}

/**
 * Snapshot de pagamento do pedido cuja tentativa Pagar.me aguarda os dados do comprador.
 *
 * Publica o que a tela precisa para retomar a coleta em qualquer aparelho: tentativa e versão.
 * Nulo quando o pedido não está nessa situação.
 *
 * @param object $order Pedido.
 * @return array<string,mixed>|null
 */
function papelito_payment_checkout_awaiting_snapshot( object $order ): ?array {
	$attempt_id = defined( 'PAPELITO_PAYMENT_ATTEMPT_ID_META' ) ? absint( $order->get_meta( PAPELITO_PAYMENT_ATTEMPT_ID_META, true ) ) : 0;
	$attempt    = $attempt_id > 0 ? papelito_payment_attempt_find( $attempt_id ) : null;

	if ( ! is_array( $attempt ) || 'awaiting_payment_input' !== $attempt['state'] ) {
		return null;
	}

	return array(
		'method'                => '',
		'state'                 => 'awaiting_payment_input',
		'provider'              => (string) $attempt['provider'],
		'flow'                  => PAPELITO_PAYMENT_FLOW_REQUIRES_PAYMENT_INPUT,
		'payment_attempt_id'    => (int) $attempt['id'],
		'configuration_version' => (string) $attempt['configuration_version'],
	);
}

/**
 * Diz se a decisão atual de gateway ainda é a que gerou a tentativa e a que o cliente viu.
 *
 * @param array<string,mixed>|WP_Error $context         PaymentContext atual.
 * @param string                       $attempt_version Versão gravada na tentativa.
 * @param string                       $client_version  Versão enviada pelo cliente.
 * @return bool
 */
function papelito_payment_checkout_context_matches( $context, string $attempt_version, string $client_version ): bool {
	if ( is_wp_error( $context ) || PAPELITO_PAYMENT_PROVIDER_PAGARME !== $context['provider'] ) {
		return false;
	}

	return (string) $context['configurationVersion'] === $attempt_version && $client_version === $attempt_version;
}

/**
 * Gateways excluídos na resolução da tentativa (o que falhou antes de criar).
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return array<int,string>
 */
function papelito_payment_checkout_attempt_exclusions( array $attempt ): array {
	if ( 'preferred_failed_before_creation' !== (string) $attempt['fallback_reason'] ) {
		return array();
	}

	return array( PAPELITO_PAYMENT_PROVIDER_PAGARME === $attempt['provider'] ? PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO : PAPELITO_PAYMENT_PROVIDER_PAGARME );
}

/**
 * Frete do pedido no formato que o payload Pagar.me espera.
 *
 * @param object $order Pedido.
 * @return array<string,float>
 */
function papelito_payment_checkout_order_shipping( object $order ): array {
	return array(
		'price'    => max( 0, (int) $order->get_meta( '_papelito_shipping_price_cents', true ) ) / 100,
		'discount' => max( 0, (int) $order->get_meta( '_papelito_shipping_discount_cents', true ) ) / 100,
	);
}

/**
 * Registra a rota de continuação.
 *
 * @return void
 */
function papelito_payment_checkout_register_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/checkout/payment-attempts/(?P<paymentAttemptId>\d+)/continue',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_order_routing_require_customer',
			'callback'            => 'papelito_payment_checkout_continue',
		)
	);
}

add_action( 'rest_api_init', 'papelito_payment_checkout_register_routes' );
