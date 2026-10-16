<?php
/**
 * Aplicação autenticada de pagamentos Mercado Pago às tentativas.
 *
 * Toda mudança de estado vem de uma consulta autenticada com o token da conta gravada na tentativa
 * — a conta histórica, mesmo que o vendor já tenha trocado de conta ou de preferência. Antes de
 * aplicar, o pagamento precisa bater em referência externa, collector, ambiente, moeda e valor
 * bruto; qualquer divergência é ignorada.
 *
 * A regra de ouro: estado monotônico e efeito único. Aprovação marca a tentativa paga (inclusive
 * depois de expiração local), eventos repetidos ou fora de ordem não regridem, e uma segunda
 * aprovação da mesma preferência vira incidente auditado — sem segunda separação de mercadoria.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_MERCADOPAGO_APPROVED_PAYMENTS_META = '_papelito_mercadopago_approved_payment_ids';
const PAPELITO_MERCADOPAGO_PAYMENT_METHOD_META    = '_papelito_mercadopago_payment_method';

/**
 * Token da conta histórica da tentativa.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return array{account:array<string,mixed>,token:string}|WP_Error
 */
function papelito_mercadopago_attempt_credentials( array $attempt ) {
	$account = papelito_vendor_payment_account_find( (int) $attempt['account_id'] );

	if ( ! is_array( $account ) || (int) $account['vendor_id'] !== (int) $attempt['vendor_id'] ) {
		return new WP_Error( 'papelito_payment_account_missing', 'Conta histórica da cobrança indisponível.', array( 'category' => 'configuration' ) );
	}

	$usable = papelito_mercadopago_usable_account_token( $account );

	if ( null === $usable ) {
		return new WP_Error( 'papelito_payment_account_reauthorization_required', 'A loja precisa reautorizar o Mercado Pago para consultar esta cobrança.', array( 'category' => 'configuration' ) );
	}

	return $usable;
}

/**
 * Credencial válida para operar uma cobrança da conta histórica.
 *
 * A autorização vigente do mesmo vendor, ambiente e collector vem primeiro — reconectar substitui
 * token revogado ou vencido, que continua decifrável. Sem ela, vale o token da conta histórica,
 * desde que não revogado. Conta de outro collector nunca serve.
 *
 * @param array<string,mixed> $historic Conta gravada na tentativa.
 * @return array{account:array<string,mixed>,token:string}|null
 */
function papelito_mercadopago_usable_account_token( array $historic ): ?array {
	$candidates = array( papelito_mercadopago_reconnected_account( $historic ) );
	if ( 'revoked' !== (string) ( $historic['status'] ?? '' ) ) {
		$candidates[] = $historic;
	}

	foreach ( array_filter( $candidates ) as $account ) {
		$tokens = papelito_vendor_payment_account_tokens( $account );
		if ( is_array( $tokens ) && '' !== (string) ( $tokens['access_token'] ?? '' ) ) {
			return array(
				'account' => $account,
				'token'   => (string) $tokens['access_token'],
			);
		}
	}

	return null;
}

/**
 * Conta vigente que reautoriza a mesma conta Mercado Pago de uma conta histórica sem credencial.
 *
 * Desconexão apaga os tokens da linha antiga e a reconexão cria outra linha; a cobrança antiga só
 * pode usar a nova se for o mesmo vendor, ambiente e collector — nunca outra conta do vendor.
 *
 * @param array<string,mixed> $historic Conta gravada na tentativa.
 * @return array<string,mixed>|null
 */
function papelito_mercadopago_reconnected_account( array $historic ): ?array {
	$current = papelito_vendor_payment_account_current( (int) $historic['vendor_id'], PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO, (string) ( $historic['environment'] ?? papelito_mercadopago_environment() ) );

	if ( ! is_array( $current ) || (string) $current['collector_id'] !== (string) $historic['collector_id'] ) {
		return null;
	}

	return $current;
}

/**
 * Diz se o pagamento pertence à tentativa: referência, collector, ambiente, moeda e valor.
 *
 * @param array<string,mixed> $payment Pagamento consultado.
 * @param array<string,mixed> $attempt Tentativa.
 * @param array<string,mixed> $account Conta histórica.
 * @return bool
 */
function papelito_mercadopago_payment_matches( array $payment, array $attempt, array $account ): bool {
	$reference    = (string) $attempt['external_reference'];
	$collector    = (string) $account['collector_id'];
	$amount_cents = (int) $attempt['amount_cents'];
	$expects_live = 'live' === $attempt['environment'];
	$currency     = (string) ( $payment['currency_id'] ?? '' );
	$checks       = array(
		'' !== $reference && (string) ( $payment['external_reference'] ?? '' ) === $reference,
		(string) ( $payment['collector_id'] ?? '' ) === $collector,
		'BRL' === $currency,
		(int) round( 100 * (float) ( $payment['transaction_amount'] ?? 0 ) ) === $amount_cents,
		! empty( $payment['live_mode'] ) === $expects_live,
	);

	return ! in_array( false, $checks, true );
}

/**
 * Aplica um pagamento consultado à tentativa.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @param array<string,mixed> $payment Pagamento consultado.
 * @param array<string,mixed> $account Conta histórica.
 * @return string `mismatch`, `paid`, `duplicate`, `already_paid` ou `recorded`.
 */
function papelito_mercadopago_apply_payment( array $attempt, array $payment, array $account ): string {
	if ( ! papelito_mercadopago_payment_matches( $payment, $attempt, $account ) ) {
		return 'mismatch';
	}

	$payment_id = (string) ( $payment['id'] ?? '' );
	$attempt    = papelito_mercadopago_record_payment_id( $attempt, $payment_id );

	$status = (string) ( $payment['status'] ?? '' );

	if ( in_array( $status, array( 'refunded', 'charged_back' ), true ) ) {
		return papelito_mercadopago_apply_reversal( $attempt, $payment_id, $status );
	}

	if ( 'approved' !== $status ) {
		return 'recorded';
	}

	return papelito_mercadopago_apply_approval( $attempt, $payment_id, papelito_mercadopago_payment_method( $payment ) );
}

/**
 * Meio do pagamento no vocabulário do checkout (`credit_card`, `pix`, `boleto`).
 *
 * @param array<string,mixed> $payment Pagamento consultado.
 * @return string Vazio quando o meio não tem equivalente.
 */
function papelito_mercadopago_payment_method( array $payment ): string {
	$type = sanitize_key( (string) ( $payment['payment_type_id'] ?? '' ) );

	if ( 'pix' === sanitize_key( (string) ( $payment['payment_method_id'] ?? '' ) ) || 'bank_transfer' === $type ) {
		return 'pix';
	}

	if ( 'ticket' === $type ) {
		return 'boleto';
	}

	return in_array( $type, array( 'credit_card', 'debit_card', 'prepaid_card' ), true ) ? 'credit_card' : '';
}

/**
 * Snapshot de pagamento de um pedido cobrado no Mercado Pago, no formato que pedido, recibo e
 * painel já consomem (`method`, `state`), mais provider, fluxo e o link enquanto pendente.
 *
 * O estado sai do pedido e da tentativa persistidos; nada é consultado no gateway aqui.
 *
 * @param object $order Pedido.
 * @return array<string,string>
 */
function papelito_mercadopago_order_payment_snapshot( object $order ): array {
	$attempt  = papelito_payment_attempt_find( absint( $order->get_meta( PAPELITO_PAYMENT_ATTEMPT_ID_META, true ) ) );
	$released = '1' === (string) $order->get_meta( '_papelito_payment_fulfillment_released', true );
	$state    = $released ? 'paid' : (string) ( $attempt['state'] ?? '' );

	if ( is_array( $attempt ) && 'refunded' === $attempt['state'] ) {
		$state = 'refunded';
	}

	$snapshot = array(
		'method'   => sanitize_key( (string) $order->get_meta( PAPELITO_MERCADOPAGO_PAYMENT_METHOD_META, true ) ),
		'state'    => $state,
		'provider' => PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO,
		'flow'     => PAPELITO_PAYMENT_FLOW_REDIRECT,
	);

	$redirect = (string) ( $attempt['redirect_url'] ?? '' );
	if ( 'pending' === $state && '' !== $redirect && papelito_mercadopago_redirect_is_allowed( $redirect ) ) {
		$snapshot['redirect_url'] = $redirect;
	}

	return $snapshot;
}

/**
 * Pagamentos aprovados do pedido, na ordem em que foram aplicados.
 *
 * O primeiro é o principal — o que quitou o pedido; os demais são cobranças excedentes da mesma
 * preferência.
 *
 * @param object $order Pedido.
 * @return array<int,string>
 */
function papelito_mercadopago_order_approved_payments( object $order ): array {
	return array_values( array_filter( explode( ',', (string) $order->get_meta( PAPELITO_MERCADOPAGO_APPROVED_PAYMENTS_META, true ) ) ) );
}

/**
 * Devolução de uma cobrança excedente: anota no pedido uma vez, sem estornar o pedido.
 *
 * @param object $order      Pedido.
 * @param string $payment_id Pagamento excedente devolvido.
 * @return string
 */
function papelito_mercadopago_record_excess_refund( object $order, string $payment_id ): string {
	$marker = '_papelito_mercadopago_excess_refunded_' . sanitize_key( $payment_id );

	if ( '1' !== (string) $order->get_meta( $marker, true ) ) {
		$order->update_meta_data( $marker, '1' );
		$order->add_order_note( sprintf( 'A cobrança excedente %s foi devolvida no Mercado Pago. O pedido continua pago pelo pagamento principal.', sanitize_text_field( $payment_id ) ) );
		$order->save();
	}

	return 'excess_refunded';
}

/**
 * Estorno ou chargeback de pagamento já aprovado, feito fora da Papelito.
 *
 * Estorno do pagamento principal registra-se pelo fluxo de estornos (recibo, auditoria, status);
 * devolução de cobrança excedente só é anotada, porque o pedido segue pago pela principal.
 * Chargeback só é anotado e segue o rito da operadora. Pagamento nunca aprovado aqui não abre
 * estorno.
 *
 * @param array<string,mixed> $attempt    Tentativa.
 * @param string              $payment_id Pagamento.
 * @param string              $status     `refunded` ou `charged_back`.
 * @return string
 */
function papelito_mercadopago_apply_reversal( array $attempt, string $payment_id, string $status ): string {
	$order    = wc_get_order( (int) $attempt['order_id'] );
	$approved = is_object( $order ) ? papelito_mercadopago_order_approved_payments( $order ) : array();

	if ( ! is_object( $order ) || ! in_array( $payment_id, $approved, true ) ) {
		return 'recorded';
	}

	if ( 'charged_back' === $status ) {
		$marker = '_papelito_mercadopago_chargeback_' . sanitize_key( $payment_id );
		if ( '1' !== (string) $order->get_meta( $marker, true ) ) {
			$order->update_meta_data( $marker, '1' );
			$order->add_order_note( sprintf( 'O Mercado Pago reportou chargeback no pagamento %s.', sanitize_text_field( $payment_id ) ) );
			$order->save();
			do_action( 'papelito_order_payment_anomaly', $order, 'chargeback', array( 'payment_id' => $payment_id ) );
		}
		return 'chargeback';
	}

	if ( $approved[0] !== $payment_id ) {
		return papelito_mercadopago_record_excess_refund( $order, $payment_id );
	}

	if ( 'paid' === $attempt['state'] ) {
		papelito_payment_attempt_transition( (int) $attempt['id'], 'paid', 'refunded' );
		if ( function_exists( 'papelito_order_refund_handle_psp_reversal' ) ) {
			papelito_order_refund_handle_psp_reversal( $order, 'refunded' );
		}
	}

	return 'refunded';
}

/**
 * Acrescenta o ID do pagamento à tentativa, sem perder gravações concorrentes.
 *
 * @param array<string,mixed> $attempt    Tentativa.
 * @param string              $payment_id ID do pagamento.
 * @return array<string,mixed> Tentativa relida.
 */
function papelito_mercadopago_record_payment_id( array $attempt, string $payment_id ): array {
	for ( $try = 0; $try < 3; $try++ ) {
		$fresh = papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;
		if ( in_array( $payment_id, (array) $fresh['remote_payment_ids'], true ) ) {
			return $fresh;
		}

		$ids = array_merge( (array) $fresh['remote_payment_ids'], array( $payment_id ) );
		if ( papelito_payment_attempt_update( $fresh, array( 'remote_payment_ids' => array_values( $ids ) ) ) ) {
			return papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $fresh;
		}
	}

	return papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;
}

/**
 * Aplica a aprovação: paga a tentativa e libera o pedido, ou registra cobrança excedente.
 *
 * Idempotente e retomável: gravar o ID aprovado não encerra o trabalho. Um retry do pagamento
 * principal completa o que ficou pendente (tentativa paga, pedido liberado); só a confirmação
 * inteira concluída responde `already_paid`.
 *
 * @param array<string,mixed> $attempt    Tentativa.
 * @param string              $payment_id Pagamento aprovado.
 * @param string              $method     Meio do pagamento aprovado.
 * @return string
 */
function papelito_mercadopago_apply_approval( array $attempt, string $payment_id, string $method = '' ): string {
	$order = wc_get_order( (int) $attempt['order_id'] );
	if ( ! is_object( $order ) ) {
		return 'recorded';
	}

	$approved = papelito_mercadopago_order_approved_payments( $order );
	$known    = in_array( $payment_id, $approved, true );

	if ( ! $known ) {
		$approved = papelito_mercadopago_append_approved_payment( $order, $approved, $payment_id, $method );
	}

	if ( $approved[0] !== $payment_id ) {
		return $known ? 'duplicate' : papelito_mercadopago_record_excess_approval( $order, $attempt, $payment_id );
	}

	$fresh = papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt;
	if ( $known && 'paid' === $fresh['state'] && papelito_payment_order_is_released( $order ) ) {
		return 'already_paid';
	}

	$paid = 'paid' === $fresh['state'] || papelito_payment_attempt_transition( (int) $fresh['id'], (string) $fresh['state'], 'paid' );

	if ( ! $paid && ! $known ) {
		$order->add_order_note( sprintf( 'Mercado Pago aprovou o pagamento %s, mas a tentativa já estava encerrada (%s). Nada foi liberado; confira o pagamento.', sanitize_text_field( $payment_id ), sanitize_key( (string) $fresh['state'] ) ) );
		$order->save();
	}

	if ( ! $paid ) {
		return 'not_applied';
	}

	papelito_payment_order_mark_paid( $order, PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO );

	return 'paid';
}

/**
 * Grava o pagamento aprovado no pedido; o primeiro também define o meio de pagamento.
 *
 * @param object            $order      Pedido.
 * @param array<int,string> $approved   Aprovados já gravados.
 * @param string            $payment_id Pagamento novo.
 * @param string            $method     Meio do pagamento.
 * @return array<int,string> Aprovados com o novo.
 */
function papelito_mercadopago_append_approved_payment( object $order, array $approved, string $payment_id, string $method ): array {
	$approved[] = $payment_id;
	$order->update_meta_data( PAPELITO_MERCADOPAGO_APPROVED_PAYMENTS_META, implode( ',', $approved ) );
	if ( 1 === count( $approved ) ) {
		$order->update_meta_data( PAPELITO_MERCADOPAGO_PAYMENT_METHOD_META, $method );
	}
	$order->save();

	return $approved;
}

/**
 * Cobrança excedente da mesma preferência: incidente auditado, sem nova separação de mercadoria.
 *
 * @param object              $order      Pedido.
 * @param array<string,mixed> $attempt    Tentativa.
 * @param string              $payment_id Pagamento excedente.
 * @return string
 */
function papelito_mercadopago_record_excess_approval( object $order, array $attempt, string $payment_id ): string {
	papelito_payment_attempt_update( papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt, array( 'incident' => 'multiple_approved' ) );
	$order->add_order_note( sprintf( 'Mercado Pago aprovou outro pagamento (%s) para a mesma preferência. Nenhuma nova separação foi feita; analise o estorno da cobrança excedente.', sanitize_text_field( $payment_id ) ) );
	$order->save();

	return 'duplicate';
}

/**
 * Consulta um pagamento pelo ID com a conta da tentativa e o aplica.
 *
 * @param array<string,mixed> $attempt    Tentativa.
 * @param string              $payment_id ID informado pela notificação.
 * @return string|WP_Error Desfecho, ou erro transitório para nova tentativa.
 */
function papelito_mercadopago_reconcile_payment_id( array $attempt, string $payment_id ) {
	$credentials = papelito_mercadopago_attempt_credentials( $attempt );
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	$payment = papelito_mercadopago_request( 'GET', '/v1/payments/' . rawurlencode( $payment_id ), array( 'token' => $credentials['token'] ) );
	if ( is_wp_error( $payment ) ) {
		return $payment;
	}

	return papelito_mercadopago_apply_payment( $attempt, $payment, $credentials['account'] );
}

/**
 * Busca e aplica todos os pagamentos da referência externa da tentativa.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return array{payments:array<int,array<string,mixed>>}|WP_Error
 */
function papelito_mercadopago_reconcile_by_reference( array $attempt ) {
	$credentials = papelito_mercadopago_attempt_credentials( $attempt );
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	$search = papelito_mercadopago_request(
		'GET',
		'/v1/payments/search',
		array(
			'token' => $credentials['token'],
			'query' => array(
				'external_reference' => (string) $attempt['external_reference'],
				'sort'               => 'date_created',
				'criteria'           => 'asc',
			),
		)
	);

	if ( is_wp_error( $search ) ) {
		return $search;
	}

	$payments = array();
	foreach ( (array) ( $search['results'] ?? array() ) as $payment ) {
		if ( is_array( $payment ) && 'mismatch' !== papelito_mercadopago_apply_payment( papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt, $payment, $credentials['account'] ) ) {
			$payments[] = $payment;
		}
	}

	return array( 'payments' => $payments );
}
