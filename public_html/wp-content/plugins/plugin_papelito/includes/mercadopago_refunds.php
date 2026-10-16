<?php
/**
 * Estorno de cobrança Mercado Pago, sempre pelo gateway e pela conta da cobrança original.
 *
 * O fluxo de cancelamento/estorno continua sendo o de `order_refunds.php` (fila, auditoria,
 * recibo, caminho manual); este módulo só executa a parte remota quando o pedido foi cobrado no
 * Mercado Pago. O gateway sai do snapshot do pedido, nunca da preferência atual do vendor, e o
 * token é o da conta gravada na tentativa paga — mesmo que o vendor tenha trocado de conta.
 *
 * A regra de ouro: consultar antes de devolver. Cada tentativa lê o pagamento primeiro; se ele
 * já está estornado, confirma sem novo POST. O POST usa a chave de idempotência estável do estorno
 * (documentada para refunds), e timeout nunca confirma — a próxima tentativa consulta de novo.
 * Credencial ausente pede reautorização da loja, distinta de recusa comercial (ex.: saldo).
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_MERCADOPAGO_REFUND_WINDOW_DAYS = 180;

/**
 * Primeiro pagamento aprovado da cobrança do pedido.
 *
 * @param object $order Pedido.
 * @return string
 */
function papelito_mercadopago_refund_payment_id( object $order ): string {
	$approved = array_values( array_filter( explode( ',', (string) $order->get_meta( '_papelito_mercadopago_approved_payment_ids', true ) ) ) );

	return (string) ( $approved[0] ?? '' );
}

/**
 * Caminho do estorno Mercado Pago: API até 180 dias do pagamento aprovado, manual depois.
 *
 * @param object   $order   Pedido.
 * @param int|null $paid_at Momento do pagamento.
 * @param int      $now     Agora.
 * @return string `api` ou `manual`.
 */
function papelito_mercadopago_refund_mode( object $order, ?int $paid_at, int $now ): string {
	$within = null !== $paid_at && $paid_at > 0 && ( $now - $paid_at ) <= PAPELITO_MERCADOPAGO_REFUND_WINDOW_DAYS * DAY_IN_SECONDS;

	return '' !== papelito_mercadopago_refund_payment_id( $order ) && $within ? 'api' : 'manual';
}

/**
 * Gateway da cobrança de uma linha de estorno, pelo snapshot do pedido.
 *
 * Pedido sem snapshot é anterior aos gateways: Pagar.me, como sempre foi.
 *
 * @param array<string,mixed> $row Linha do estorno.
 * @return string
 */
function papelito_order_refund_row_provider( array $row ): string {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['order_id'] ) : null;

	if ( ! is_object( $order ) ) {
		return PAPELITO_PAYMENT_PROVIDER_PAGARME;
	}

	$provider = papelito_payment_provider_for_order( $order );

	return is_wp_error( $provider ) ? PAPELITO_PAYMENT_PROVIDER_PAGARME : $provider;
}

/**
 * Executa a parte remota do estorno no Mercado Pago.
 *
 * @param array<string,mixed> $row Linha do estorno (order_id, amount_cents, idempotency_key).
 * @return array{refunded:bool,source:string,payload:array<string,mixed>}|WP_Error
 */
function papelito_mercadopago_refund_remote( array $row ) {
	$order   = wc_get_order( (int) $row['order_id'] );
	$attempt = is_object( $order ) ? papelito_payment_attempt_find( absint( $order->get_meta( PAPELITO_PAYMENT_ATTEMPT_ID_META, true ) ) ) : null;
	$payment = is_object( $order ) ? papelito_mercadopago_refund_payment_id( $order ) : '';

	if ( ! is_array( $attempt ) || '' === $payment ) {
		return new WP_Error( 'papelito_mercadopago_refund_payment_missing', 'Pagamento aprovado do Mercado Pago não encontrado para este pedido.', array( 'status' => 409 ) );
	}

	$token = papelito_mercadopago_refund_token( $attempt );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$path     = '/v1/payments/' . rawurlencode( $payment );
	$precheck = papelito_mercadopago_request( 'GET', $path, array( 'token' => $token ) );

	if ( is_wp_error( $precheck ) ) {
		return $precheck;
	}

	if ( papelito_mercadopago_payment_is_refunded( $precheck, (int) $row['amount_cents'] ) ) {
		return papelito_mercadopago_refund_confirmed( $attempt, 'api_precheck', array( 'status' => (string) ( $precheck['status'] ?? '' ) ) );
	}

	$response = papelito_mercadopago_request(
		'POST',
		$path . '/refunds',
		array(
			'token'           => $token,
			'body'            => (object) array(),
			'idempotency_key' => (string) $row['idempotency_key'],
		)
	);

	return papelito_mercadopago_refund_outcome( $attempt, $response );
}

/**
 * Token da conta histórica, com erro próprio de reautorização.
 *
 * @param array<string,mixed> $attempt Tentativa paga.
 * @return string|WP_Error
 */
function papelito_mercadopago_refund_token( array $attempt ) {
	$account = papelito_vendor_payment_account_find( (int) $attempt['account_id'] );
	$usable  = is_array( $account ) ? papelito_mercadopago_usable_account_token( $account ) : null;

	if ( null === $usable ) {
		return new WP_Error( 'papelito_mercadopago_refund_reauthorization_required', 'A loja precisa reconectar o Mercado Pago para concluir o estorno desta cobrança.', array( 'status' => 409 ) );
	}

	return $usable['token'];
}

/**
 * Diz se o pagamento já foi devolvido integralmente.
 *
 * @param array<string,mixed> $payment      Pagamento consultado.
 * @param int                 $amount_cents Valor do estorno.
 * @return bool
 */
function papelito_mercadopago_payment_is_refunded( array $payment, int $amount_cents ): bool {
	if ( 'refunded' === (string) ( $payment['status'] ?? '' ) ) {
		return true;
	}

	return $amount_cents > 0 && (int) round( 100 * (float) ( $payment['transaction_amount_refunded'] ?? 0 ) ) >= $amount_cents;
}

/**
 * Interpreta a resposta do POST de estorno.
 *
 * @param array<string,mixed>          $attempt  Tentativa paga.
 * @param array<string,mixed>|WP_Error $response Resposta.
 * @return array{refunded:bool,source:string,payload:array<string,mixed>}|WP_Error
 */
function papelito_mercadopago_refund_outcome( array $attempt, $response ) {
	if ( is_wp_error( $response ) ) {
		$category = (string) ( ( (array) $response->get_error_data() )['category'] ?? '' );

		return in_array( $category, array( 'validation', 'business_restriction' ), true )
			? new WP_Error( 'papelito_mercadopago_refund_rejected', 'O Mercado Pago recusou o estorno: ' . $response->get_error_message(), array( 'status' => 409 ) )
			: $response;
	}

	$status = sanitize_key( (string) ( $response['status'] ?? '' ) );

	if ( 'approved' === $status ) {
		return papelito_mercadopago_refund_confirmed( $attempt, 'api', array( 'status' => $status ) );
	}

	if ( in_array( $status, array( 'rejected', 'cancelled' ), true ) ) {
		return new WP_Error( 'papelito_mercadopago_refund_rejected', 'O Mercado Pago não concluiu o estorno.', array( 'status' => 409 ) );
	}

	return array(
		'refunded' => false,
		'source'   => 'api',
		'payload'  => array( 'status' => $status ),
	);
}

/**
 * Estorno confirmado: a tentativa passa a estornada.
 *
 * @param array<string,mixed> $attempt Tentativa paga.
 * @param string              $source  Origem da confirmação.
 * @param array<string,mixed> $payload Dados para a auditoria.
 * @return array{refunded:bool,source:string,payload:array<string,mixed>}
 */
function papelito_mercadopago_refund_confirmed( array $attempt, string $source, array $payload ): array {
	papelito_payment_attempt_transition( (int) $attempt['id'], 'paid', 'refunded' );

	return array(
		'refunded' => true,
		'source'   => $source,
		'payload'  => $payload,
	);
}
