<?php
/**
 * Pedido do estande e cobrança na Point: criar, acompanhar, cancelar, tentar de novo e liberar.
 *
 * O operador fecha o pedido no tablet em nome do lojista. O pedido WooCommerce é do vendor do
 * evento, com o comprador e a empresa cadastrados no estande, e a cobrança é uma tentativa
 * `mercadopago_point` na conta da Papelito.
 *
 * Regra de ouro: o valor cobrado é o da cotação recalculada na hora, e só se o `quoteFingerprint`
 * bater; o mesmo `checkoutAttemptId` nunca cria dois pedidos, e uma tentativa aberta impede outra.
 * Toda rota de pedido confere que o pedido é deste evento.
 *
 * O que este módulo não faz: não interpreta o estado da order (isso é `event_sales_reconcile.php`).
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_EVENT_META    = '_papelito_event_id';
const PAPELITO_EVENT_SALES_OPERATOR_META = '_papelito_event_operator_id';
const PAPELITO_EVENT_SALES_QUOTE_META    = '_papelito_event_quote';
const PAPELITO_EVENT_SALES_LEAD_META     = '_papelito_event_pdv_perfeito_lead';
const PAPELITO_EVENT_SALES_CHECKOUT_META = '_papelito_checkout_attempt_id';
const PAPELITO_EVENT_SALES_TERMINAL_META = '_papelito_event_terminal_id';
const PAPELITO_EVENT_SALES_TOTAL_META    = '_papelito_authoritative_total_cents';
const PAPELITO_EVENT_SALES_METHODS       = array( 'card', 'pix' );
const PAPELITO_EVENT_SALES_ATTEMPT_OPEN  = 'papelito_event_attempt_open';
const PAPELITO_EVENT_SALES_CONFLICT      = 409;
const PAPELITO_EVENT_SALES_ORDER_ROUTE   = '/orders/(?P<order_id>\d{1,18})';

/**
 * Erro de pedido com o status HTTP no formato do REST do WordPress.
 *
 * @param string              $code    Código do erro.
 * @param string              $message Mensagem para o operador.
 * @param int                 $status  Status HTTP.
 * @param array<string,mixed> $extra   Dados extras.
 * @return WP_Error
 */
function papelito_event_sales_order_error( string $code, string $message, int $status, array $extra = array() ): WP_Error {
	return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
}

/**
 * Nome do vendor gravado nas linhas do pedido.
 *
 * @param int $vendor_id Vendor do evento.
 * @return string
 */
function papelito_event_sales_vendor_name( int $vendor_id ): string {
	$name = trim( (string) get_user_meta( $vendor_id, 'store_name', true ) );

	return '' !== $name ? $name : 'Vendor #' . $vendor_id;
}

/**
 * Linha no formato de `papelito_order_routing_create_order()`.
 *
 * @param int    $product_id     Produto.
 * @param int    $qty            Quantidade.
 * @param int    $subtotal_cents Subtotal cheio.
 * @param int    $total_cents    Total cobrado.
 * @param string $source         Origem do desconto.
 * @param int    $vendor_id      Vendor do evento.
 * @return array<string,mixed>
 */
function papelito_event_sales_order_line( int $product_id, int $qty, int $subtotal_cents, int $total_cents, string $source, int $vendor_id ): array {
	return array(
		'product'         => wc_get_product( $product_id ),
		'product_id'      => $product_id,
		'qty'             => $qty,
		'vendor_id'       => $vendor_id,
		'vendor_name'     => papelito_event_sales_vendor_name( $vendor_id ),
		'subtotal'        => $subtotal_cents / 100,
		'total'           => $total_cents / 100,
		'discount_source' => $source,
		'subtotal_cents'  => $subtotal_cents,
		'discount_cents'  => $subtotal_cents - $total_cents,
		'total_cents'     => $total_cents,
	);
}

/**
 * Linhas do pedido: avulsas com o desconto do nível e os componentes do kit com −15%.
 *
 * @param array<string,mixed> $quote     Cotação autoritativa.
 * @param int                 $vendor_id Vendor do evento.
 * @return array<int,array<string,mixed>>
 */
function papelito_event_sales_order_lines( array $quote, int $vendor_id ): array {
	$lines = array();

	foreach ( $quote['lines'] as $line ) {
		$lines[] = papelito_event_sales_order_line( (int) $line['product_id'], (int) $line['qty'], (int) $line['subtotal_cents'], (int) $line['total_cents'], 'event_level', $vendor_id );
	}

	$kits = (int) $quote['kit']['qty'];
	foreach ( $kits > 0 ? $quote['kitComponents'] : array() as $component ) {
		$lines[] = papelito_event_sales_order_line( (int) $component['product_id'], $kits, $kits * (int) $component['price_cents'], $kits * (int) $component['unit_cents'], 'event_kit', $vendor_id );
	}

	return $lines;
}

/**
 * Valida o endereço de entrega digitado no tablet.
 *
 * @param mixed $delivery Endereço cru.
 * @return array<string,string>|WP_Error Endereço no formato do roteamento de pedido.
 */
function papelito_event_sales_delivery_address( mixed $delivery ): array|WP_Error {
	$delivery = is_array( $delivery ) ? $delivery : array();
	$address  = array(
		'zip_code'     => preg_replace( '/\D+/', '', (string) ( $delivery['cep'] ?? '' ) ),
		'street'       => sanitize_text_field( (string) ( $delivery['street'] ?? '' ) ),
		'number'       => sanitize_text_field( (string) ( $delivery['number'] ?? '' ) ),
		'complement'   => sanitize_text_field( (string) ( $delivery['complement'] ?? '' ) ),
		'neighborhood' => sanitize_text_field( (string) ( $delivery['neighborhood'] ?? '' ) ),
		'city'         => sanitize_text_field( (string) ( $delivery['city'] ?? '' ) ),
		'state'        => strtoupper( sanitize_text_field( (string) ( $delivery['state'] ?? '' ) ) ),
	);

	$required = array( $address['street'], $address['number'], $address['neighborhood'], $address['city'] );
	if ( 8 !== strlen( $address['zip_code'] ) || 2 !== strlen( $address['state'] ) || in_array( '', $required, true ) ) {
		return papelito_event_sales_order_error( 'papelito_event_delivery_invalid', 'Confira o endereço de entrega.', 400 );
	}

	return $address;
}

/**
 * Snapshot B2B do pedido. Número e bairro fiscais vazios vêm da entrega quando ela é no mesmo CEP.
 *
 * @param array<string,mixed>  $company Empresa.
 * @param array<string,mixed>  $member  Vínculo do comprador.
 * @param int                  $buyer   Comprador.
 * @param array<string,string> $address Endereço de entrega.
 * @return array<string,mixed>
 */
function papelito_event_sales_b2b_snapshot( array $company, array $member, int $buyer, array $address ): array {
	if ( (string) $company['fiscal_cep'] === $address['zip_code'] ) {
		foreach ( array( 'number', 'neighborhood', 'complement' ) as $field ) {
			if ( '' === trim( (string) ( $company[ 'fiscal_' . $field ] ?? '' ) ) ) {
				$company[ 'fiscal_' . $field ] = $address[ $field ];
			}
		}
	}

	return array(
		'company'       => $company,
		'membership'    => $member,
		'buyer_user_id' => $buyer,
	);
}

/**
 * Pedido do evento já criado para o `checkoutAttemptId`, ou nulo.
 *
 * O WooCommerce ignora `meta_query` em `wc_get_orders()`; por isso a busca usa `meta_key` e o
 * pedido devolvido ainda é conferido pelas duas marcas antes de ser reaproveitado.
 *
 * @param string $checkout_attempt_id Chave do tablet.
 * @return object|null
 */
function papelito_event_sales_find_order_by_checkout( string $checkout_attempt_id ): ?object {
	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'return'     => 'objects',
			'meta_key'   => PAPELITO_EVENT_SALES_CHECKOUT_META,
			'meta_value' => $checkout_attempt_id,
		)
	);
	$order  = is_array( $orders ) && isset( $orders[0] ) && is_object( $orders[0] ) ? $orders[0] : null;

	if ( null === $order || $checkout_attempt_id !== (string) $order->get_meta( PAPELITO_EVENT_SALES_CHECKOUT_META, true ) || PAPELITO_EVENT_SALES_ID !== (string) $order->get_meta( PAPELITO_EVENT_SALES_EVENT_META, true ) ) {
		return null;
	}

	return $order;
}

/**
 * Tentativa mais recente do pedido.
 *
 * @param object $order Pedido.
 * @return array<string,mixed>|null
 */
function papelito_event_sales_latest_attempt( object $order ): ?array {
	return papelito_payment_attempt_latest_for_order( (int) $order->get_id() );
}

/**
 * Estado do pedido para o tablet; tentativa sem resposta há mais de 20 s é conferida na Point.
 *
 * @param object $order Pedido do evento.
 * @return array{state:string,detail:string,totalCents:int,method:string}
 */
function papelito_event_sales_order_status( object $order ): array {
	$attempt = papelito_event_sales_latest_attempt( $order );
	$waiting = null !== $attempt && in_array( $attempt['state'], array( 'creating', 'unknown', 'pending' ), true );

	if ( $waiting && papelito_event_sales_attempt_age( $attempt ) > PAPELITO_EVENT_SALES_RECONCILE_AFTER ) {
		papelito_event_sales_reconcile_attempt( $attempt );
		$attempt = papelito_payment_attempt_find( (int) $attempt['id'] );
	}

	$detail = null === $attempt ? '' : (string) ( '' !== (string) $attempt['incident'] ? $attempt['incident'] : $attempt['last_error_code'] );

	return array(
		'state'      => papelito_event_sales_public_state( $attempt ),
		'detail'     => $detail,
		'totalCents' => null === $attempt ? (int) $order->get_meta( PAPELITO_EVENT_SALES_TOTAL_META, true ) : (int) $attempt['amount_cents'],
		'method'     => null === $attempt ? '' : (string) $attempt['payment_method'],
	);
}

/**
 * Resumo do pedido devolvido ao criar, tentar de novo ou liberar.
 *
 * @param object $order Pedido do evento.
 * @return array<string,mixed>
 */
function papelito_event_sales_order_summary( object $order ): array {
	return array_merge(
		array(
			'orderId'     => (int) $order->get_id(),
			'orderNumber' => (string) $order->get_order_number(),
		),
		papelito_event_sales_order_status( $order )
	);
}

/**
 * Motivo gravado quando a criação da order falha de vez.
 *
 * @param WP_Error $error Erro do Mercado Pago.
 * @return string
 */
function papelito_event_sales_dispatch_error_code( WP_Error $error ): string {
	$data = (array) $error->get_error_data();

	if ( 'already_queued_order_for_terminal' === ( $data['remote_code'] ?? '' ) ) {
		return 'terminal_busy';
	}

	return sanitize_key( (string) ( $data['category'] ?? 'failed' ) );
}

/**
 * Manda a tentativa para a Point: grava `creating` antes do POST e o resultado depois.
 *
 * Timeout vira `unknown` e é reconciliado reenviando com a mesma chave.
 *
 * @param array<string,mixed> $attempt Tentativa `not_started`.
 * @param object              $order   Pedido do evento.
 * @return string Estado que o tablet mostra.
 */
function papelito_event_sales_dispatch_attempt( array $attempt, object $order ): string {
	$id = (int) $attempt['id'];
	if ( ! papelito_payment_attempt_transition( $id, 'not_started', 'creating', array( 'external_reference' => papelito_mercadopago_point_external_reference( $attempt ) ) ) ) {
		return papelito_event_sales_public_state( papelito_payment_attempt_find( $id ) );
	}

	$remote = papelito_mercadopago_point_create_order( $attempt, (int) $attempt['amount_cents'], papelito_event_sales_order_terminal( $order ), (string) $attempt['payment_method'], papelito_event_sales_order_description( (string) $order->get_order_number() ) );

	$category = is_wp_error( $remote ) ? (string) ( ( (array) $remote->get_error_data() )['category'] ?? '' ) : '';

	if ( ! is_wp_error( $remote ) ) {
		papelito_payment_attempt_transition( $id, 'creating', 'pending', array( 'remote_id' => sanitize_text_field( (string) ( $remote['id'] ?? '' ) ) ) );
	} elseif ( 'unknown_outcome' === $category ) {
		papelito_payment_attempt_transition( $id, 'creating', 'unknown' );
	} else {
		papelito_payment_attempt_transition( $id, 'creating', 'failed', array( 'last_error_code' => papelito_event_sales_dispatch_error_code( $remote ) ) );
	}

	return papelito_event_sales_public_state( papelito_payment_attempt_find( $id ) );
}

/**
 * Maquininha da cobrança atual do pedido. A recuperação reenvia para ela, nunca para outra.
 *
 * Pedido de antes da escolha de maquininha cai na primeira configurada.
 *
 * @param object $order Pedido do evento.
 * @return string
 */
function papelito_event_sales_order_terminal( object $order ): string {
	$stored = (string) $order->get_meta( PAPELITO_EVENT_SALES_TERMINAL_META, true );
	if ( '' !== $stored ) {
		return $stored;
	}

	$terminals = papelito_event_sales_config()['terminal_ids'];

	return $terminals[0] ?? '';
}

/**
 * Abre uma tentativa da Point na conta da Papelito e a despacha para a maquininha escolhida.
 *
 * @param object $order        Pedido do evento.
 * @param int    $amount_cents Valor cobrado.
 * @param string $method       `card` ou `pix`.
 * @param string $terminal_id  Maquininha já validada por `papelito_event_sales_resolve_terminal()`.
 * @return array<string,mixed>|WP_Error Resumo do pedido.
 */
function papelito_event_sales_open_attempt( object $order, int $amount_cents, string $method, string $terminal_id ): array|WP_Error {
	$collector = papelito_mercadopago_point_collector_id();
	if ( is_wp_error( $collector ) ) {
		return $collector;
	}

	$order->update_meta_data( PAPELITO_EVENT_SALES_TERMINAL_META, $terminal_id );
	$order->save();

	$attempt = papelito_payment_attempt_create(
		array(
			'order_id'            => (int) $order->get_id(),
			'checkout_attempt_id' => (string) $order->get_meta( PAPELITO_EVENT_SALES_CHECKOUT_META, true ),
			'buyer_user_id'       => (int) $order->get_meta( '_papelito_buyer_user_id', true ),
			'company_id'          => (int) $order->get_meta( '_papelito_company_id', true ),
			'vendor_id'           => papelito_event_sales_config()['vendor_id'],
			'provider'            => PAPELITO_MERCADOPAGO_POINT_PROVIDER,
			'environment'         => papelito_mercadopago_environment(),
			'account_ref'         => $collector,
			'amount_cents'        => $amount_cents,
			'flow'                => 'terminal',
			'payment_method'      => $method,
		)
	);

	if ( is_wp_error( $attempt ) ) {
		return $attempt;
	}

	papelito_event_sales_dispatch_attempt( $attempt, $order );

	return papelito_event_sales_order_summary( $order );
}

/**
 * Comprador e empresa do pedido, relidos do banco; o vínculo precisa existir e o CNPJ precisa estar
 * ativo na Receita agora. Empresa já cadastrada não prova nada: a situação é reconsultada (o cache
 * da consulta de CNPJ deixa isso barato).
 *
 * @param array<string,mixed> $input Corpo do pedido.
 * @return array{company:array<string,mixed>,member:array<string,mixed>,buyer:int}|WP_Error
 */
function papelito_event_sales_order_buyer( array $input ): array|WP_Error {
	$company_id = (int) ( $input['companyId'] ?? 0 );
	$buyer      = (int) ( $input['buyerUserId'] ?? 0 );
	$company    = papelito_company_get( $company_id );
	$member     = null === $company ? null : papelito_company_member_get( $company_id, $buyer );

	if ( null === $company || null === $member ) {
		return papelito_event_sales_order_error( 'papelito_event_buyer_not_found', 'Cadastre o lojista antes de fechar o pedido.', 404 );
	}

	$registry = papelito_event_sales_lookup_buyer( (string) $company['cnpj'] );
	if ( is_wp_error( $registry ) ) {
		return $registry;
	}

	return array(
		'company' => $company,
		'member'  => $member,
		'buyer'   => $buyer,
	);
}

/**
 * Cotação na hora de cobrar, recusada com a cotação nova quando o fingerprint mudou.
 *
 * @param array<string,mixed> $input   Corpo do pedido.
 * @param array<string,mixed> $company Empresa compradora.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_order_quote( array $input, array $company ): array|WP_Error {
	$quote = papelito_event_sales_quote(
		array(
			'items'         => $input['items'] ?? array(),
			'kits'          => (int) ( $input['kits'] ?? 0 ),
			'cep'           => (string) ( $input['delivery']['cep'] ?? '' ),
			'recipientCnpj' => (string) $company['cnpj'],
		)
	);

	if ( is_wp_error( $quote ) ) {
		return $quote;
	}

	if ( ! hash_equals( $quote['quoteFingerprint'], (string) ( $input['quoteFingerprint'] ?? '' ) ) ) {
		return papelito_event_sales_order_error( 'papelito_event_quote_changed', 'O valor do pedido mudou. Confira o total novo antes de cobrar.', PAPELITO_EVENT_SALES_CONFLICT, array( 'quote' => papelito_event_sales_public_quote( $quote ) ) );
	}

	return $quote;
}

/**
 * Cria o pedido WooCommerce do evento, grava as marcas do evento e reserva o estoque.
 *
 * @param array<string,mixed>  $input   Corpo do pedido.
 * @param array<string,mixed>  $buyer   Comprador e empresa.
 * @param array<string,string> $address Entrega.
 * @param array<string,mixed>  $quote   Cotação autoritativa.
 * @return object|WP_Error Pedido criado.
 */
function papelito_event_sales_build_order( array $input, array $buyer, array $address, array $quote ): object {
	$vendor_id = papelito_event_sales_config()['vendor_id'];
	$lines     = papelito_event_sales_order_lines( $quote, $vendor_id );
	$created   = papelito_order_routing_create_order(
		$buyer['buyer'],
		$address,
		$lines,
		$quote['shipping']['raw'],
		null,
		PAPELITO_MERCADOPAGO_POINT_PROVIDER,
		array(
			'checkout_attempt_id' => (string) $input['checkoutAttemptId'],
			'b2b_snapshot'        => papelito_event_sales_b2b_snapshot( $buyer['company'], $buyer['member'], $buyer['buyer'], $address ),
		)
	);

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$order = wc_get_order( (int) $created['order_id'] );
	$order->update_meta_data( PAPELITO_EVENT_SALES_EVENT_META, PAPELITO_EVENT_SALES_ID );
	$order->update_meta_data( PAPELITO_EVENT_SALES_OPERATOR_META, get_current_user_id() );
	$order->update_meta_data( PAPELITO_EVENT_SALES_QUOTE_META, wp_json_encode( papelito_event_sales_public_quote( $quote ) ) );
	$order->save();

	$reserved = papelito_pagarme_reserve_order_stock( $order, $lines );
	if ( is_wp_error( $reserved ) ) {
		papelito_payment_order_mark_unpaid( $order, 'event_stock' );
		return $reserved;
	}

	return $order;
}

/**
 * Valida, cota e cria o pedido, e manda a cobrança para a Point.
 *
 * @param array<string,mixed> $input       Corpo do pedido.
 * @param string              $method      `card` ou `pix`.
 * @param string              $terminal_id Maquininha validada.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_place_order( array $input, string $method, string $terminal_id ): array|WP_Error {
	$buyer = papelito_event_sales_order_buyer( $input );
	if ( is_wp_error( $buyer ) ) {
		return $buyer;
	}

	$address = papelito_event_sales_delivery_address( $input['delivery'] ?? null );
	if ( is_wp_error( $address ) ) {
		return $address;
	}

	$quote = papelito_event_sales_order_quote( $input, $buyer['company'] );
	if ( is_wp_error( $quote ) ) {
		return $quote;
	}

	$order = papelito_event_sales_build_order( $input, $buyer, $address, $quote );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	return papelito_event_sales_open_attempt( $order, (int) $quote['totalCents'], $method, $terminal_id );
}

/**
 * Pedido já criado pelo mesmo atendimento: devolve o estado ou, se a cobrança nem chegou a abrir
 * (credencial ausente, Mercado Pago fora), abre agora no mesmo pedido.
 *
 * @param object $order       Pedido do evento.
 * @param string $method      `card` ou `pix`.
 * @param string $terminal_id Maquininha validada.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_resume_order( object $order, string $method, string $terminal_id ): array|WP_Error {
	if ( null !== papelito_event_sales_latest_attempt( $order ) ) {
		return papelito_event_sales_order_summary( $order );
	}

	return papelito_event_sales_retry_locked( $order, $method, $terminal_id );
}

/**
 * Forma de pagamento do tablet, ou erro fora de cartão e Pix.
 *
 * @param mixed $method Valor cru.
 * @return string|WP_Error
 */
function papelito_event_sales_method( mixed $method ): string|WP_Error {
	$method = (string) $method;

	if ( ! in_array( $method, PAPELITO_EVENT_SALES_METHODS, true ) ) {
		return papelito_event_sales_order_error( 'papelito_event_invalid_method', 'Escolha cartão ou Pix.', 400 );
	}

	return $method;
}

/**
 * `POST orders`: cria o pedido e a cobrança uma vez por `checkoutAttemptId`.
 *
 * Repetir com a mesma chave devolve o pedido que já existe, sem nova order na máquina.
 *
 * @param array<string,mixed> $input Corpo do pedido.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_create_order( array $input ): array|WP_Error {
	$method = papelito_event_sales_method( $input['method'] ?? '' );
	if ( is_wp_error( $method ) ) {
		return $method;
	}

	$terminal = papelito_event_sales_resolve_terminal( (string) ( $input['terminalId'] ?? '' ) );
	if ( is_wp_error( $terminal ) ) {
		return $terminal;
	}

	$checkout = (string) ( $input['checkoutAttemptId'] ?? '' );
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,120}$/', $checkout ) ) {
		return papelito_event_sales_order_error( 'papelito_event_checkout_invalid', 'Atendimento inválido. Comece um pedido novo.', 400 );
	}

	$lock = 'event_checkout:' . $checkout;
	if ( ! papelito_payment_attempt_acquire_lock( $lock ) ) {
		return papelito_event_sales_order_error( 'papelito_event_order_in_progress', 'O pedido já está sendo criado.', PAPELITO_EVENT_SALES_CONFLICT );
	}

	try {
		$existing = papelito_event_sales_find_order_by_checkout( $checkout );
		$result   = null === $existing ? papelito_event_sales_place_order( $input, $method, $terminal ) : papelito_event_sales_resume_order( $existing, $method, $terminal );
	} finally {
		papelito_payment_attempt_release_lock( $lock );
	}

	return $result;
}

/**
 * Executa uma ação sobre o pedido sob o lock `event_order:{id}`.
 *
 * @param object   $order  Pedido do evento.
 * @param callable $action Ação que recebe o pedido.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_with_order_lock( object $order, callable $action ): array|WP_Error {
	$lock = 'event_order:' . (int) $order->get_id();
	if ( ! papelito_payment_attempt_acquire_lock( $lock ) ) {
		return papelito_event_sales_order_error( 'papelito_event_order_busy', 'Outra ação está em andamento neste pedido.', PAPELITO_EVENT_SALES_CONFLICT );
	}

	try {
		return $action( $order );
	} finally {
		papelito_payment_attempt_release_lock( $lock );
	}
}

/**
 * Erro quando a última tentativa ainda pode cobrar (criando, desconhecida, pendente ou paga).
 *
 * @param array<string,mixed>|null $attempt Última tentativa.
 * @return WP_Error|null
 */
function papelito_event_sales_open_attempt_error( ?array $attempt ): ?WP_Error {
	if ( null !== $attempt && papelito_payment_attempt_blocks_new_attempt( (string) $attempt['state'] ) ) {
		return papelito_event_sales_order_error( PAPELITO_EVENT_SALES_ATTEMPT_OPEN, 'A cobrança anterior ainda não terminou.', PAPELITO_EVENT_SALES_CONFLICT );
	}

	return null;
}

/**
 * `POST orders/{id}/cancel`: pede o cancelamento da order na máquina. O fim chega pelo webhook.
 *
 * @param object $order Pedido do evento.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_cancel( object $order ): array|WP_Error {
	$attempt = papelito_event_sales_latest_attempt( $order );
	if ( null === $attempt || 'pending' !== $attempt['state'] || '' === (string) $attempt['remote_id'] ) {
		return papelito_event_sales_order_error( 'papelito_event_not_cancelable', 'Não há cobrança na maquininha para cancelar.', PAPELITO_EVENT_SALES_CONFLICT );
	}

	$result = papelito_mercadopago_point_cancel_order( (string) $attempt['remote_id'], 'cancel-' . (string) $attempt['attempt_ref'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return papelito_event_sales_order_summary( $order );
}

/**
 * Nova tentativa no mesmo pedido, só depois que a anterior terminou e com o pedido aberto.
 *
 * @param object $order       Pedido do evento.
 * @param string $method      `card` ou `pix`.
 * @param string $terminal_id Maquininha validada; pode ser outra que a da tentativa anterior.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_retry_locked( object $order, string $method, string $terminal_id ): array|WP_Error {
	if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'processing', 'completed', 'on-hold' ), true ) ) {
		return papelito_event_sales_order_error( 'papelito_event_order_closed', 'Este pedido não aceita nova cobrança.', PAPELITO_EVENT_SALES_CONFLICT );
	}

	$attempt = papelito_event_sales_latest_attempt( $order );
	$open    = papelito_event_sales_open_attempt_error( $attempt );
	if ( null !== $open ) {
		return $open;
	}

	$amount = null === $attempt ? (int) $order->get_meta( PAPELITO_EVENT_SALES_TOTAL_META, true ) : (int) $attempt['amount_cents'];

	return papelito_event_sales_open_attempt( $order, $amount, $method, $terminal_id );
}

/**
 * `POST orders/{id}/retry`, na maquininha pedida ou na padrão do evento.
 *
 * @param object $order    Pedido do evento.
 * @param mixed  $method   Forma escolhida.
 * @param mixed  $terminal Maquininha pedida, ou vazio.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_retry( object $order, mixed $method, mixed $terminal = '' ): array|WP_Error {
	$method = papelito_event_sales_method( $method );
	if ( is_wp_error( $method ) ) {
		return $method;
	}

	$terminal = papelito_event_sales_resolve_terminal( is_string( $terminal ) ? $terminal : '' );
	if ( is_wp_error( $terminal ) ) {
		return $terminal;
	}

	return papelito_event_sales_with_order_lock(
		$order,
		static fn( object $locked ): array|WP_Error => papelito_event_sales_retry_locked( $locked, $method, $terminal )
	);
}

/**
 * Encerra o pedido sem pagamento e libera o estoque, se nenhuma cobrança estiver aberta.
 *
 * @param object $order Pedido do evento.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_abandon_locked( object $order ): array|WP_Error {
	$open = papelito_event_sales_open_attempt_error( papelito_event_sales_latest_attempt( $order ) );
	if ( null !== $open ) {
		return $open;
	}

	papelito_payment_order_mark_unpaid( $order, 'event_abandoned' );

	return array(
		'orderId'   => (int) $order->get_id(),
		'abandoned' => true,
	);
}

/**
 * `POST orders/{id}/abandon`.
 *
 * @param object $order Pedido do evento.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_abandon( object $order ): array|WP_Error {
	return papelito_event_sales_with_order_lock( $order, 'papelito_event_sales_abandon_locked' );
}

/**
 * Dados da liberação manual validados: NSU, forma e motivo.
 *
 * @param array<string,mixed> $input Corpo.
 * @return array{nsu:string,method:string,reason:string}|WP_Error
 */
function papelito_event_sales_manual_input( array $input ): array|WP_Error {
	$nsu    = sanitize_text_field( (string) ( $input['nsu'] ?? '' ) );
	$method = (string) ( $input['method'] ?? '' );
	$reason = sanitize_text_field( (string) ( $input['reason'] ?? '' ) );

	if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,40}$/', $nsu ) || ! in_array( $method, PAPELITO_EVENT_SALES_METHODS, true ) || 1 !== preg_match( '/^.{5,}$/u', $reason ) ) {
		return papelito_event_sales_order_error( 'papelito_event_manual_invalid', 'Informe o NSU, a forma e o motivo.', 400 );
	}

	return array(
		'nsu'    => $nsu,
		'method' => $method,
		'reason' => $reason,
	);
}

/**
 * Marca pago o que a máquina aprovou e o sistema não viu, com incidente e linha de repasse.
 *
 * @param object                                        $order Pedido do evento.
 * @param array{nsu:string,method:string,reason:string} $input Dados validados.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_manual_confirm_locked( object $order, array $input ): array|WP_Error {
	$attempt = papelito_event_sales_latest_attempt( $order );
	if ( null === $attempt || ! papelito_payment_attempt_can_transition( (string) $attempt['state'], 'paid' ) ) {
		return papelito_event_sales_order_error( 'papelito_event_manual_not_allowed', 'Este pedido não pode ser liberado manualmente.', PAPELITO_EVENT_SALES_CONFLICT );
	}

	if ( ! papelito_payment_attempt_transition(
		(int) $attempt['id'],
		(string) $attempt['state'],
		'paid',
		array(
			'incident'       => 'manual_release',
			'payment_method' => $input['method'],
		)
	) ) {
		return papelito_event_sales_order_error( 'papelito_event_attempt_changed', 'A cobrança mudou. Confira de novo.', PAPELITO_EVENT_SALES_CONFLICT );
	}

	papelito_payment_order_mark_paid( $order, PAPELITO_MERCADOPAGO_POINT_PROVIDER );
	$order->add_order_note( sprintf( 'Liberação manual pelo supervisor #%d. NSU %s. Motivo: %s', get_current_user_id(), $input['nsu'], $input['reason'] ) );
	$order->save();

	papelito_event_sales_settlement_record(
		array(
			'event_id'        => PAPELITO_EVENT_SALES_ID,
			'order_id'        => (int) $order->get_id(),
			'attempt_id'      => (int) $attempt['id'],
			'kind'            => 'manual_release',
			'payment_method'  => $input['method'],
			'remote_order_id' => (string) $attempt['remote_id'],
			'nsu'             => $input['nsu'],
			'gross_cents'     => (int) $attempt['amount_cents'],
			'fee_cents'       => null,
			'net_cents'       => null,
			'created_by'      => get_current_user_id(),
		)
	);

	return papelito_event_sales_order_summary( $order );
}

/**
 * `POST orders/{id}/manual-confirm` (supervisor).
 *
 * @param object              $order Pedido do evento.
 * @param array<string,mixed> $input Corpo com `nsu`, `method` e `reason`.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_manual_confirm( object $order, array $input ): array|WP_Error {
	$valid = papelito_event_sales_manual_input( $input );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	return papelito_event_sales_with_order_lock(
		$order,
		static fn( object $locked ): array|WP_Error => papelito_event_sales_manual_confirm_locked( $locked, $valid )
	);
}

/**
 * `POST orders/{id}/lead`: o lojista quer participar do PDV Perfeito.
 *
 * @param object $order Pedido do evento.
 * @return array<string,mixed>
 */
function papelito_event_sales_mark_lead( object $order ): array {
	$order->update_meta_data( PAPELITO_EVENT_SALES_LEAD_META, '1' );
	$order->save();

	return array(
		'orderId' => (int) $order->get_id(),
		'lead'    => true,
	);
}

/**
 * Pedido da rota, só se for deste evento; qualquer outro é 404.
 *
 * @param WP_REST_Request $request Requisição com `order_id`.
 * @return object|WP_Error
 */
function papelito_event_sales_order_from_request( WP_REST_Request $request ): object {
	$order = wc_get_order( (int) $request->get_param( 'order_id' ) );

	if ( ! is_object( $order ) || PAPELITO_EVENT_SALES_ID !== (string) $order->get_meta( PAPELITO_EVENT_SALES_EVENT_META, true ) ) {
		return papelito_event_sales_order_error( 'papelito_event_order_not_found', 'Pedido não encontrado.', 404 );
	}

	return $order;
}

/**
 * Carrega o pedido da rota e aplica a ação, devolvendo a resposta REST.
 *
 * @param WP_REST_Request $request Requisição.
 * @param callable        $action  Ação que recebe o pedido e o corpo.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_order_action( WP_REST_Request $request, callable $action ): WP_REST_Response|WP_Error {
	$order = papelito_event_sales_order_from_request( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	return papelito_event_sales_respond( $action( $order, papelito_event_sales_json_body( $request ) ) );
}

/**
 * `POST orders`.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_create_order_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_respond( papelito_event_sales_create_order( papelito_event_sales_json_body( $request ) ) );
}

/**
 * `GET orders/{id}/status`.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_status_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_order_action( $request, static fn( object $order ): array => papelito_event_sales_order_summary( $order ) );
}

/**
 * `POST orders/{id}/cancel`.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_cancel_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_order_action( $request, static fn( object $order ): array|WP_Error => papelito_event_sales_cancel( $order ) );
}

/**
 * `POST orders/{id}/retry`.
 *
 * @param WP_REST_Request $request Requisição com `method`.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_retry_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_order_action( $request, static fn( object $order, array $body ): array|WP_Error => papelito_event_sales_retry( $order, $body['method'] ?? '', $body['terminalId'] ?? '' ) );
}

/**
 * `POST orders/{id}/abandon`.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_abandon_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_order_action( $request, static fn( object $order ): array|WP_Error => papelito_event_sales_abandon( $order ) );
}

/**
 * `POST orders/{id}/manual-confirm`.
 *
 * @param WP_REST_Request $request Requisição com `nsu`, `method` e `reason`.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_manual_confirm_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_order_action( $request, 'papelito_event_sales_manual_confirm' );
}

/**
 * `POST orders/{id}/lead`.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_lead_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_order_action( $request, static fn( object $order ): array => papelito_event_sales_mark_lead( $order ) );
}

/**
 * Registra as rotas de pedido do evento.
 *
 * @return void
 */
function papelito_event_sales_register_order_routes(): void {
	$routes = array(
		'/orders'                                     => array( 'POST', 'papelito_event_sales_create_order_endpoint', 'papelito_event_sales_require_seller' ),
		PAPELITO_EVENT_SALES_ORDER_ROUTE . '/status'  => array( 'GET', 'papelito_event_sales_status_endpoint', 'papelito_event_sales_require_seller' ),
		PAPELITO_EVENT_SALES_ORDER_ROUTE . '/cancel'  => array( 'POST', 'papelito_event_sales_cancel_endpoint', 'papelito_event_sales_require_seller' ),
		PAPELITO_EVENT_SALES_ORDER_ROUTE . '/retry'   => array( 'POST', 'papelito_event_sales_retry_endpoint', 'papelito_event_sales_require_seller' ),
		PAPELITO_EVENT_SALES_ORDER_ROUTE . '/abandon' => array( 'POST', 'papelito_event_sales_abandon_endpoint', 'papelito_event_sales_require_seller' ),
		PAPELITO_EVENT_SALES_ORDER_ROUTE . '/manual-confirm' => array( 'POST', 'papelito_event_sales_manual_confirm_endpoint', 'papelito_event_sales_require_supervisor' ),
		PAPELITO_EVENT_SALES_ORDER_ROUTE . '/lead'    => array( 'POST', 'papelito_event_sales_lead_endpoint', 'papelito_event_sales_require_seller' ),
	);

	foreach ( $routes as $path => $route ) {
		papelito_event_sales_register_route(
			$path,
			array(
				'methods'             => $route[0],
				'callback'            => $route[1],
				'permission_callback' => $route[2],
			)
		);
	}
}
add_action( 'rest_api_init', 'papelito_event_sales_register_order_routes' );
