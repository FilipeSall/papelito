<?php
/**
 * Cliente da Orders API da Point Smart com a credencial da conta Mercado Pago da Papelito.
 *
 * No evento a maquininha pertence à conta da Papelito, não à do vendor: o dinheiro entra na
 * Papelito e é repassado à Cifal depois. Por isso este módulo usa um token próprio,
 * `PAPELITO_MERCADOPAGO_POINT_ACCESS_TOKEN`, e nunca o OAuth do vendor.
 *
 * Regra de ouro: a order é criada com `X-Idempotency-Key` igual à referência da tentativa, e o
 * reenvio depois de um timeout usa a mesma chave. Uma tentativa nunca gera duas orders.
 *
 * O que este módulo não faz: não decide o estado do pedido nem grava tentativa; só fala com a API.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_MERCADOPAGO_POINT_PROVIDER      = 'mercadopago_point';
const PAPELITO_MERCADOPAGO_POINT_TOKEN_SETTING = 'PAPELITO_MERCADOPAGO_POINT_ACCESS_TOKEN';
const PAPELITO_MERCADOPAGO_POINT_COLLECTOR_KEY = 'papelito_mercadopago_point_collector';
const PAPELITO_MERCADOPAGO_POINT_EXPIRATION    = 'PT5M';
const PAPELITO_MERCADOPAGO_POINT_ORDERS_PATH   = '/v1/orders';
const PAPELITO_MERCADOPAGO_POINT_MODES         = array( 'PDV', 'STANDALONE' );

/**
 * Access token da conta Papelito dona da Point, ou erro de configuração quando falta.
 *
 * @return string|WP_Error
 */
function papelito_mercadopago_point_token(): string|WP_Error {
	$token = papelito_mercadopago_setting( PAPELITO_MERCADOPAGO_POINT_TOKEN_SETTING );

	if ( '' === $token ) {
		return papelito_mercadopago_error( 'configuration', 'A credencial da maquininha da Papelito não está configurada.' );
	}

	return $token;
}

/**
 * Requisição à API com o token da Point.
 *
 * @param string              $method Método HTTP.
 * @param string              $path   Caminho relativo.
 * @param array<string,mixed> $args   Opções de `papelito_mercadopago_request()` sem o token.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_point_request( string $method, string $path, array $args = array() ): array|WP_Error {
	$token = papelito_mercadopago_point_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$args['token'] = $token;

	return papelito_mercadopago_request( $method, $path, $args );
}

/**
 * Id da conta Mercado Pago da Papelito (`user_id`), guardado por um dia.
 *
 * É a conta recebedora gravada na tentativa e o `user_id` que o webhook precisa trazer.
 *
 * @return string|WP_Error
 */
function papelito_mercadopago_point_collector_id(): string|WP_Error {
	$cached = get_transient( PAPELITO_MERCADOPAGO_POINT_COLLECTOR_KEY );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$me = papelito_mercadopago_point_request( 'GET', '/users/me' );
	if ( is_wp_error( $me ) ) {
		return $me;
	}

	$id = isset( $me['id'] ) && is_scalar( $me['id'] ) ? (string) $me['id'] : '';
	if ( '' === $id ) {
		return papelito_mercadopago_error( 'unavailable', 'O Mercado Pago não informou a conta da maquininha.' );
	}

	set_transient( PAPELITO_MERCADOPAGO_POINT_COLLECTOR_KEY, $id, DAY_IN_SECONDS );

	return $id;
}

/**
 * Valor em reais com duas casas, no formato string que a Orders API exige.
 *
 * @param int $amount_cents Valor em centavos.
 * @return string
 */
function papelito_mercadopago_point_amount( int $amount_cents ): string {
	return sprintf( '%d.%02d', intdiv( $amount_cents, 100 ), $amount_cents % 100 );
}

/**
 * Referência externa que liga a order da máquina ao pedido e à tentativa.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return string
 */
function papelito_mercadopago_point_external_reference( array $attempt ): string {
	return sprintf( 'ppl-%d-%d', (int) $attempt['order_id'], (int) $attempt['id'] );
}

/**
 * Corpo da order `type=point`. Pix abre o QR na máquina; cartão deixa crédito, débito e parcelas
 * para o cliente escolher na máquina.
 *
 * @param array<string,mixed> $attempt      Tentativa.
 * @param int                 $amount_cents Valor em centavos.
 * @param string              $terminal_id  Terminal configurado do evento.
 * @param string              $method       `card` ou `pix`.
 * @param string              $description  Descrição que aparece no extrato.
 * @return array<string,mixed>
 */
function papelito_mercadopago_point_order_payload( array $attempt, int $amount_cents, string $terminal_id, string $method, string $description ): array {
	$config = array(
		'point' => array(
			'terminal_id'       => $terminal_id,
			'print_on_terminal' => 'seller_ticket',
		),
	);

	if ( 'pix' === $method ) {
		$config['payment_method'] = array( 'default_type' => 'qr' );
	}

	return array(
		'type'               => 'point',
		'external_reference' => papelito_mercadopago_point_external_reference( $attempt ),
		'expiration_time'    => PAPELITO_MERCADOPAGO_POINT_EXPIRATION,
		'transactions'       => array(
			'payments' => array(
				array( 'amount' => papelito_mercadopago_point_amount( $amount_cents ) ),
			),
		),
		'config'             => $config,
		'description'        => $description,
	);
}

/**
 * Cria a order na Point com a chave de idempotência da tentativa.
 *
 * Erro `unknown_outcome` significa que a order pode existir: reconcilie reenviando com a mesma chave.
 *
 * @param array<string,mixed> $attempt      Tentativa com `id`, `order_id` e `attempt_ref`.
 * @param int                 $amount_cents Valor em centavos.
 * @param string              $terminal_id  Terminal configurado do evento.
 * @param string              $method       `card` ou `pix`.
 * @param string              $description  Descrição da order.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_point_create_order( array $attempt, int $amount_cents, string $terminal_id, string $method, string $description ): array|WP_Error {
	return papelito_mercadopago_point_request(
		'POST',
		PAPELITO_MERCADOPAGO_POINT_ORDERS_PATH,
		array(
			'body'            => papelito_mercadopago_point_order_payload( $attempt, $amount_cents, $terminal_id, $method, $description ),
			'idempotency_key' => (string) $attempt['attempt_ref'],
		)
	);
}

/**
 * Caminho de uma order, ou erro quando o id não tem o formato da API.
 *
 * @param string $order_id Id da order.
 * @param string $suffix   Sufixo da ação.
 * @return string|WP_Error
 */
function papelito_mercadopago_point_order_path( string $order_id, string $suffix = '' ): string|WP_Error {
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $order_id ) ) {
		return papelito_mercadopago_error( 'validation', 'Id de order inválido.' );
	}

	return PAPELITO_MERCADOPAGO_POINT_ORDERS_PATH . '/' . $order_id . $suffix;
}

/**
 * Executa uma chamada sobre uma order existente.
 *
 * @param string              $method   Método HTTP.
 * @param string              $order_id Id da order.
 * @param string              $suffix   Sufixo da ação.
 * @param array<string,mixed> $args     Opções.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_point_order_call( string $method, string $order_id, string $suffix = '', array $args = array() ): array|WP_Error {
	$path = papelito_mercadopago_point_order_path( $order_id, $suffix );
	if ( is_wp_error( $path ) ) {
		return $path;
	}

	return papelito_mercadopago_point_request( $method, $path, $args );
}

/**
 * Lê a order na API: fonte de verdade do estado da cobrança.
 *
 * @param string $order_id Id da order.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_point_get_order( string $order_id ): array|WP_Error {
	return papelito_mercadopago_point_order_call( 'GET', $order_id );
}

/**
 * Pede o cancelamento, inclusive com a order já na máquina. O resultado final chega pelo webhook.
 *
 * A Orders API recusa o cancelamento sem `X-Idempotency-Key`; a chave é própria do cancelamento,
 * diferente da usada na criação.
 *
 * @param string $order_id        Id da order.
 * @param string $idempotency_key Chave do cancelamento.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_point_cancel_order( string $order_id, string $idempotency_key ): array|WP_Error {
	return papelito_mercadopago_point_order_call(
		'POST',
		$order_id,
		'/cancel',
		array(
			'headers'         => array( 'x-allow-cancelable-status' => 'at_terminal' ),
			'idempotency_key' => $idempotency_key,
		)
	);
}

/**
 * Estorna a order inteira com uma chave de idempotência própria do estorno.
 *
 * @param string $order_id        Id da order.
 * @param string $idempotency_key Chave do estorno.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_point_refund_order( string $order_id, string $idempotency_key ): array|WP_Error {
	return papelito_mercadopago_point_order_call( 'POST', $order_id, '/refund', array( 'idempotency_key' => $idempotency_key ) );
}

/**
 * Taxa e líquido de um pagamento da Point, lidos de `/v1/payments/{id}` com o token da Papelito.
 *
 * Sem id ou com a consulta falhando, os dois voltam nulos e o ledger mostra "a conferir".
 *
 * @param string $payment_id Id numérico do pagamento.
 * @return array{fee_cents:int|null,net_cents:int|null}
 */
function papelito_mercadopago_point_payment_fees( string $payment_id ): array {
	$unknown = array(
		'fee_cents' => null,
		'net_cents' => null,
	);

	if ( 1 !== preg_match( '/^\d{1,20}$/', $payment_id ) ) {
		return $unknown;
	}

	$payment = papelito_mercadopago_point_request( 'GET', '/v1/payments/' . $payment_id );
	if ( is_wp_error( $payment ) ) {
		return $unknown;
	}

	$fee = 0.0;
	foreach ( is_array( $payment['fee_details'] ?? null ) ? $payment['fee_details'] : array() as $detail ) {
		$fee += is_array( $detail ) && is_numeric( $detail['amount'] ?? null ) ? (float) $detail['amount'] : 0.0;
	}

	$net = $payment['transaction_details']['net_received_amount'] ?? null;

	return array(
		'fee_cents' => (int) round( $fee * 100 ),
		'net_cents' => is_numeric( $net ) ? (int) round( (float) $net * 100 ) : null,
	);
}

/**
 * Terminais Point ativos na conta da Papelito, com loja, caixa e modo de operação.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_point_list_terminals(): array|WP_Error {
	return papelito_mercadopago_point_request( 'GET', '/terminals/v1/list', array( 'query' => array( 'limit' => 50 ) ) );
}

/**
 * Troca o modo de operação do terminal (`PDV` para integrar, `STANDALONE` para a máquina avulsa).
 *
 * O terminal precisa ser reiniciado para o modo novo valer.
 *
 * @param string $terminal_id Terminal.
 * @param string $mode        `PDV` ou `STANDALONE`.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_point_set_mode( string $terminal_id, string $mode ): array|WP_Error {
	if ( ! in_array( $mode, PAPELITO_MERCADOPAGO_POINT_MODES, true ) ) {
		return papelito_mercadopago_error( 'validation', 'Modo de terminal inválido.' );
	}

	return papelito_mercadopago_point_request(
		'PATCH',
		'/terminals/v1/setup',
		array(
			'body' => array(
				'terminals' => array(
					array(
						'id'             => $terminal_id,
						'operating_mode' => $mode,
					),
				),
			),
		)
	);
}
