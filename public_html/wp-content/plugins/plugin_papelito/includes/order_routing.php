<?php
/**
 * Checkout headless + roteamento de pedidos por vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'papelito_pricing_quote' ) ) {
	require_once __DIR__ . '/pricing.php';
}

if ( ! defined( 'PAPELITO_ORDER_VENDOR_STATUS_AWAITING_SHIPMENT' ) ) {
	define( 'PAPELITO_ORDER_VENDOR_STATUS_AWAITING_PAYMENT', 'aguardando_pagamento' );
	define( 'PAPELITO_ORDER_VENDOR_STATUS_AWAITING_SHIPMENT', 'aguardando_envio' );
}

if ( ! defined( 'PAPELITO_CHECKOUT_ATTEMPT_ID_META' ) ) {
	define( 'PAPELITO_CHECKOUT_ATTEMPT_ID_META', '_papelito_checkout_attempt_id' );
}
if ( ! defined( 'PAPELITO_CHECKOUT_ATTEMPT_COMPANY_META' ) ) {
	define( 'PAPELITO_CHECKOUT_ATTEMPT_COMPANY_META', '_papelito_checkout_attempt_company_id' );
}
if ( ! defined( 'PAPELITO_CHECKOUT_ATTEMPT_HASH_META' ) ) {
	define( 'PAPELITO_CHECKOUT_ATTEMPT_HASH_META', '_papelito_checkout_attempt_request_hash' );
}
if ( ! defined( 'PAPELITO_B2B_SNAPSHOT_VERSION' ) ) {
	define( 'PAPELITO_B2B_SNAPSHOT_VERSION', '1' );
}

function papelito_order_routing_sort_payload( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	foreach ( $value as $key => $item ) {
		$value[ $key ] = papelito_order_routing_sort_payload( $item );
	}
	ksort( $value );
	return $value;
}

function papelito_order_routing_checkout_request_hash( array $payload ): string {
	$normalized = $payload;
	// Os ids de analytics ficam de fora da impressao digital da tentativa: o `session_id` do GA4
	// expira sozinho, e uma nova tentativa do mesmo checkout com sessao renovada seria recusada
	// como `papelito_checkout_attempt_payload_conflict` sem que nada do pedido tenha mudado.
	unset( $normalized['analytics'] );
	if ( isset( $normalized['payment']['card_token_id'] ) ) {
		$normalized['payment']['card_token_id'] = hash_hmac( 'sha256', (string) $normalized['payment']['card_token_id'], wp_salt( 'papelito_checkout_attempt' ) );
	}
	return hash_hmac( 'sha256', (string) wp_json_encode( papelito_order_routing_sort_payload( $normalized ) ), wp_salt( 'papelito_checkout_attempt' ) );
}

/**
 * Traduz `purchaseBlockReason` na mensagem que o comprador realmente precisa ler.
 *
 * Antes toda recusa dizia "Sua empresa não está apta para pagamento", inclusive quando a empresa
 * estava `active`/`verified` e o problema era o papel do membro. O usuário ia investigar a empresa,
 * e o titular ia procurar um defeito que não existia.
 *
 * @param string $reason Motivo devolvido por `papelito_company_purchase_capability()`.
 * @return string
 */
function papelito_order_routing_purchase_block_message( string $reason ): string {
	$messages = array(
		'account_suspended'          => 'Sua conta está suspensa para operações comerciais. Fale com a Papelito.',
		'role_cannot_purchase'       => 'Seu papel nesta empresa não permite concluir compras. Peça a um administrador da empresa para alterar sua permissão.',
		'company_missing'            => 'Vincule uma empresa à sua conta para concluir a compra.',
		'company_selection_required' => 'Selecione a empresa que vai realizar esta compra.',
		'membership_not_active'      => 'Seu vínculo com a empresa não está ativo.',
		'membership_expired'         => 'Seu vínculo com a empresa expirou.',
		'identity_pending'           => 'Sua identificação ainda está em análise.',
		'not_a_customer_buyer'       => 'Esta conta não compra pela plataforma.',
	);

	return $messages[ $reason ] ?? 'Sua empresa não está apta para pagamento.';
}

/** @return array<string,mixed>|WP_Error */
function papelito_order_routing_resolve_b2b_snapshot( int $user_id, array $payload ) {
	$context = papelito_company_context( $user_id );
	$capability = papelito_company_purchase_capability( $user_id, $context );
	if ( empty( $capability['canPurchase'] ) || ! is_array( $capability['company'] ?? null ) || ! is_array( $capability['membership'] ?? null ) ) {
		$block_reason = (string) ( $capability['purchaseBlockReason'] ?? 'not_a_customer_buyer' );

		return new WP_Error(
			'papelito_b2b_purchase_not_allowed',
			papelito_order_routing_purchase_block_message( $block_reason ),
			array(
				'status'              => 403,
				'purchaseMode'        => $capability['purchaseMode'] ?? 'blocked',
				'purchaseBlockReason' => $block_reason,
			)
		);
	}
	$expected_company_id = absint( $payload['expected_company_id'] ?? 0 );
	$company = $capability['company'];
	if ( $expected_company_id <= 0 || $expected_company_id !== (int) $company['id'] ) {
		return new WP_Error( 'papelito_checkout_company_context_changed', 'A empresa ativa mudou. Revise o checkout antes de continuar.', array( 'status' => 409 ) );
	}
	$membership = $capability['membership'];
	return array(
		'company' => $company,
		'membership' => $membership,
		'company_id' => (int) $company['id'],
		'buyer_user_id' => $user_id,
		'expected_company_id' => $expected_company_id,
	);
}

function papelito_order_routing_store_b2b_snapshot( object $order, array $snapshot ): void {
	if ( empty( $snapshot['company'] ) || ! is_array( $snapshot['company'] ) || empty( $snapshot['membership'] ) || ! is_array( $snapshot['membership'] ) ) {
		return;
	}
	$company = $snapshot['company'];
	$member = $snapshot['membership'];
	$meta = array(
		'_papelito_company_snapshot_version' => PAPELITO_B2B_SNAPSHOT_VERSION,
		'_papelito_b2b_snapshot_version' => PAPELITO_B2B_SNAPSHOT_VERSION,
		'_papelito_company_id' => (int) $company['id'],
		'_papelito_buyer_user_id' => (int) $snapshot['buyer_user_id'],
		'_papelito_company_cnpj' => (string) $company['cnpj'],
		'_papelito_company_legal_name' => (string) $company['legal_name'],
		'_papelito_company_trade_name' => (string) ( $company['trade_name'] ?? '' ),
		'_papelito_company_pagarme_customer_code' => 'papelito-company-' . (int) $company['id'],
		'_papelito_company_status' => (string) $company['company_status'],
		'_papelito_company_registry_status' => (string) $company['registry_status'],
		'_papelito_company_ownership_status' => (string) $company['ownership_status'],
		'_papelito_company_verified_at' => (string) ( $company['verified_at'] ?? '' ),
		'_papelito_company_verification_source' => (string) ( $company['provider_source'] ?? '' ),
		'_papelito_company_provider_source' => (string) ( $company['provider_source'] ?? '' ),
		'_papelito_company_provider_checked_at' => (string) $company['provider_checked_at'],
		'_papelito_company_provider_data_hash' => (string) $company['provider_data_hash'],
		'_papelito_company_billing_email' => (string) $company['billing_email'],
		'_papelito_company_billing_email_verified_at' => (string) $company['billing_email_verified_at'],
		'_papelito_company_phone' => (string) $company['phone'],
		'_papelito_membership_id' => (int) $member['id'],
		'_papelito_membership_role' => (string) $member['member_role'],
		'_papelito_membership_status' => (string) $member['member_status'],
		'_papelito_membership_approved_at' => (string) $member['approved_at'],
		'_papelito_membership_expires_at' => (string) $member['expires_at'],
	);
	foreach ( array( 'cep', 'state', 'city', 'neighborhood', 'street', 'number', 'complement' ) as $field ) {
		$meta[ '_papelito_fiscal_' . $field ] = (string) ( $company[ 'fiscal_' . $field ] ?? '' );
		$meta[ '_papelito_company_fiscal_' . $field ] = (string) ( $company[ 'fiscal_' . $field ] ?? '' );
	}
	foreach ( $meta as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}
	$order->update_meta_data( '_billing_cnpj', (string) $company['cnpj'] );
	$order->update_meta_data( '_billing_company', (string) $company['legal_name'] );
	$order->update_meta_data( '_billing_email', (string) $company['billing_email'] );
	$order->update_meta_data( '_billing_phone', (string) $company['phone'] );
}

/**
 * Verifica se um valor e uma instancia da classe WooCommerce esperada.
 *
 * @param mixed  $value Valor inspecionado.
 * @param string $class Nome da classe WooCommerce.
 * @return bool
 */
function papelito_order_routing_is_wc_instance( $value, string $class ): bool {
	return class_exists( $class ) && is_object( $value ) && is_a( $value, $class );
}

/**
 * Normaliza o metodo de pagamento recebido do front.
 *
 * @param mixed $value Valor cru.
 * @return string
 */
function papelito_order_routing_normalize_payment_method( $value ): string {
	$method = sanitize_key( (string) $value );

	return in_array( $method, array( 'credit_card', 'pix', 'boleto' ), true ) ? $method : '';
}

/**
 * Normaliza a chave idempotente enviada pelo checkout.
 */
function papelito_order_routing_normalize_checkout_attempt_id( $value ): string {
	$attempt_id = sanitize_text_field( (string) $value );
	$attempt_id = substr( $attempt_id, 0, 120 );

	return preg_match( '/^[A-Za-z0-9._:-]{8,120}$/', $attempt_id ) ? $attempt_id : '';
}

/**
 * Busca um pedido ja criado pela mesma tentativa de checkout.
 */
function papelito_order_routing_find_order_by_attempt( int $user_id, string $attempt_id ) {
	if ( '' === $attempt_id || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}

	$orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'limit'       => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'meta_key'    => PAPELITO_CHECKOUT_ATTEMPT_ID_META,
			'meta_value'  => $attempt_id,
			'return'      => 'objects',
		)
	);

	return isset( $orders[0] ) ? $orders[0] : null;
}

/**
 * Monta a resposta de um pedido ja existente para retry idempotente.
 */
function papelito_order_routing_existing_order_response( object $order ) {
	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
		return null;
	}

	$pagarme_order_id = method_exists( $order, 'get_meta' ) && defined( 'PAPELITO_PAGARME_ORDER_ID_META' )
		? sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_ORDER_ID_META, true ) )
		: '';

	if ( '' === $pagarme_order_id ) {
		return new WP_Error(
			'papelito_checkout_attempt_in_progress',
			'Este checkout já esta sendo processado. Aguarde alguns instantes.',
			array( 'status' => 409 )
		);
	}

	$total_cents = method_exists( $order, 'get_meta' )
		? max( 0, (int) $order->get_meta( '_papelito_authoritative_total_cents', true ) )
		: 0;
	$shipping_cents = method_exists( $order, 'get_meta' )
		? max( 0, (int) $order->get_meta( '_papelito_shipping_price_cents', true ) )
		: 0;
	$shipping_discount_cents = method_exists( $order, 'get_meta' )
		? min( $shipping_cents, max( 0, (int) $order->get_meta( '_papelito_shipping_discount_cents', true ) ) )
		: 0;

	// Pedidos anteriores aos metas de auditoria guardam somente o frete efetivo do WooCommerce.
	if ( 0 === $shipping_cents && method_exists( $order, 'get_shipping_total' ) ) {
		$shipping_cents = max( 0, (int) round( (float) $order->get_shipping_total() * 100 ) );
	}

	$shipping_charged_cents = $shipping_cents - $shipping_discount_cents;
	$items_cents            = max( 0, $total_cents - $shipping_charged_cents );
	$subtotal_cents         = method_exists( $order, 'get_subtotal' )
		? max( $items_cents, (int) round( (float) $order->get_subtotal() * 100 ) )
		: $items_cents;
	$discount_cents         = max( 0, $subtotal_cents - $items_cents );

	return array_filter(
		array(
			'order_id'     => method_exists( $order, 'get_id' ) ? $order->get_id() : 0,
			'order_number' => method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : '',
			'status'       => method_exists( $order, 'get_status' ) ? $order->get_status() : '',
			'payment'      => function_exists( 'papelito_pagarme_order_payment_snapshot' )
				? papelito_pagarme_order_payment_snapshot( $order )
				: array(
					'method' => '',
					'state'  => '',
				),
			'totals'       => $total_cents > 0
				? array(
					'subtotalCents'         => $subtotal_cents,
					'discountCents'         => $discount_cents,
					'itemsCents'            => $items_cents,
					'shippingCents'         => $shipping_cents,
					'shippingDiscountCents' => $shipping_discount_cents,
					'totalCents'            => $total_cents,
				)
				: null,
		),
		static fn( $value ): bool => null !== $value
	);
}

function papelito_order_routing_validate_existing_attempt( object $order, int $company_id, string $request_hash ) {
	$stored_company = (int) $order->get_meta( PAPELITO_CHECKOUT_ATTEMPT_COMPANY_META, true );
	$stored_hash = sanitize_text_field( (string) $order->get_meta( PAPELITO_CHECKOUT_ATTEMPT_HASH_META, true ) );
	if ( $stored_company !== $company_id ) {
		return new WP_Error( 'papelito_checkout_company_context_changed', 'A empresa desta tentativa não corresponde à empresa ativa.', array( 'status' => 409 ) );
	}
	if ( '' === $stored_hash || ! hash_equals( $stored_hash, $request_hash ) ) {
		return new WP_Error( 'papelito_checkout_attempt_payload_conflict', 'Esta tentativa de checkout foi reutilizada com dados diferentes.', array( 'status' => 409 ) );
	}
	return true;
}

/**
 * Label legivel do metodo de pagamento.
 *
 * @param string $method Metodo normalizado.
 * @return string
 */
function papelito_order_routing_payment_method_label( string $method ): string {
	switch ( $method ) {
		case 'credit_card':
			return 'Cartao de crédito';
		case 'pix':
			return 'Pix';
		case 'boleto':
			return 'Boleto';
		default:
			return 'Pagamento headless';
	}
}

/**
 * Normaliza o payload de pagamento.
 *
 * @param mixed $payment Payload cru.
 * @param array<string,string> $fallback_address Endereco padrao.
 * @return array<string,mixed>|WP_Error
 */
function papelito_order_routing_normalize_payment( $payment, array $fallback_address ) {
	if ( ! is_array( $payment ) ) {
		return new WP_Error(
			'papelito_checkout_invalid_payment',
			'Selecione uma forma de pagamento válida.',
			array( 'status' => 422 )
		);
	}

	$method = papelito_order_routing_normalize_payment_method( $payment['method'] ?? '' );

	if ( '' === $method ) {
		return new WP_Error(
			'papelito_checkout_invalid_payment',
			'Selecione uma forma de pagamento válida.',
			array( 'status' => 422 )
		);
	}

	$normalized = array(
		'method'          => $method,
		'installments'    => max( 1, (int) ( $payment['installments'] ?? 1 ) ),
		'card_token_id'   => sanitize_text_field( (string) ( $payment['card_token_id'] ?? '' ) ),
		'holder_name'     => sanitize_text_field( (string) ( $payment['holder_name'] ?? '' ) ),
		'billing_address' => $fallback_address,
	);

	if ( isset( $payment['billing_address'] ) && is_array( $payment['billing_address'] ) ) {
		$billing = papelito_order_routing_normalize_address( $payment['billing_address'] );

		if ( is_wp_error( $billing ) ) {
			return $billing;
		}

		$normalized['billing_address'] = $billing;
	}

	if ( 'credit_card' === $method && ( '' === $normalized['card_token_id'] || '' === $normalized['holder_name'] ) ) {
		return new WP_Error(
			'papelito_checkout_invalid_payment',
			'Os dados do cartao não estão completos.',
			array( 'status' => 422 )
		);
	}

	return $normalized;
}

/**
 * Requer usuario autenticado com role customer para fechar checkout.
 *
 * @return true|WP_Error
 */
function papelito_order_routing_require_customer() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'papelito_checkout_auth_required',
			'Faca login para concluir o pedido.',
			array( 'status' => 401 )
		);
	}

	$user = wp_get_current_user();
	if ( ! $user instanceof WP_User ) {
		return new WP_Error(
			'papelito_checkout_customer_only',
			'Somente consumidores finais podem concluir o checkout.',
			array( 'status' => 403 )
		);
	}

	$capability = papelito_company_purchase_capability( $user->ID );
	if ( empty( $capability['canPurchase'] ) ) {
		return new WP_Error(
			'papelito_b2b_purchase_not_allowed',
			papelito_order_routing_purchase_block_message( (string) ( $capability['purchaseBlockReason'] ?? '' ) ),
			array( 'status' => 403, 'purchaseMode' => $capability['purchaseMode'], 'purchaseBlockReason' => $capability['purchaseBlockReason'] )
		);
	}

	return true;
}

/**
 * Normaliza e valida o endereco do checkout.
 *
 * @param mixed $address Payload cru.
 * @return array<string,string>|WP_Error
 */
function papelito_order_routing_normalize_address( $address ) {
	if ( ! is_array( $address ) ) {
		return new WP_Error(
			'papelito_checkout_invalid_address',
			'Endereço de entrega inválido.',
			array( 'status' => 422 )
		);
	}

	$normalized = array(
		'zip_code'     => papelito_shipping_normalize_cep( $address['zip_code'] ?? '' ),
		'street'       => sanitize_text_field( (string) ( $address['street'] ?? '' ) ),
		'number'       => sanitize_text_field( (string) ( $address['number'] ?? '' ) ),
		'complement'   => sanitize_text_field( (string) ( $address['complement'] ?? '' ) ),
		'neighborhood' => sanitize_text_field( (string) ( $address['neighborhood'] ?? '' ) ),
		'city'         => sanitize_text_field( (string) ( $address['city'] ?? '' ) ),
		'state'        => strtoupper( sanitize_text_field( (string) ( $address['state'] ?? '' ) ) ),
	);

	if (
		'' === $normalized['zip_code'] ||
		'' === $normalized['street'] ||
		'' === $normalized['number'] ||
		'' === $normalized['neighborhood'] ||
		'' === $normalized['city'] ||
		2 !== strlen( $normalized['state'] )
	) {
		return new WP_Error(
			'papelito_checkout_invalid_address',
			'Preencha os campos obrigatórios do endereço.',
			array( 'status' => 422 )
		);
	}

	return $normalized;
}

/**
 * Normaliza e valida as linhas do checkout.
 *
 * @param mixed $items Payload cru.
 * @return array<int,array{product_id:int,qty:int,vendor_id:int,vendor_name:string}>|WP_Error
 */
function papelito_order_routing_normalize_items( $items ) {
	return papelito_pricing_normalize_items( $items );
}

/**
 * Resolve o nome legivel do vendor.
 *
 * @param int $vendor_id ID do vendor.
 * @return string
 */
function papelito_order_routing_vendor_name( int $vendor_id ): string {
	$meta_name = sanitize_text_field( (string) get_user_meta( $vendor_id, 'store_name', true ) );

	if ( '' !== $meta_name ) {
		return $meta_name;
	}

	$user = get_userdata( $vendor_id );

	return $user instanceof WP_User ? sanitize_text_field( $user->display_name ) : '';
}

/**
 * Valida o vendor unico do pedido e resolve as linhas com preco atual.
 *
 * @param array<int,array{product_id:int,qty:int,vendor_id:int,vendor_name:string}> $items Itens normalizados.
 * @return array{vendor_id:int,vendor_name:string,lines:array<int,array<string,mixed>>}|WP_Error
 */
function papelito_order_routing_resolve_items( array $items ) {
	return papelito_pricing_resolve_items( $items );
}

/**
 * Valida a cobertura do vendor para o CEP de destino.
 *
 * @return true|WP_Error
 */
function papelito_order_routing_validate_vendor_coverage( int $vendor_id, string $destination_cep ) {
	if ( '' === $destination_cep ) {
		return new WP_Error(
			'papelito_checkout_invalid_shipping',
			'Informe um CEP de destino válido.',
			array( 'status' => 422 )
		);
	}

	if ( ! function_exists( 'papelito_matching_vendor_ids' ) ) {
		return new WP_Error(
			'papelito_checkout_coverage_unavailable',
			'Não foi possível validar a cobertura do vendor agora.',
			array( 'status' => 503 )
		);
	}

	$covering_ids = array_map( 'intval', papelito_matching_vendor_ids( (int) $destination_cep ) );

	if ( in_array( $vendor_id, $covering_ids, true ) ) {
		return true;
	}

	return new WP_Error(
		'papelito_checkout_vendor_not_approved',
		'O vendor selecionado não atende o CEP do pedido.',
		array( 'status' => 422 )
	);
}

/**
 * Revalida o frete selecionado a partir do CEP do vendor.
 *
 * @param int    $vendor_id        Vendor do pedido.
 * @param string $destination_cep  CEP destino normalizado.
 * @param string $selected_code    Codigo do servico escolhido.
 * @param array<int,array<string,mixed>> $lines Linhas do pedido.
 * @return array<string,mixed>|WP_Error
 */
function papelito_order_routing_resolve_shipping( int $vendor_id, string $destination_cep, string $selected_code, array $lines ) {
	$quote_items = array_map(
		static function ( array $line ): array {
			return array(
				'product_id' => (int) $line['product_id'],
				'qty'        => (int) $line['qty'],
			);
		},
		$lines
	);

	$quote = papelito_correios_quote( $vendor_id, $destination_cep, $quote_items );
	if ( is_wp_error( $quote ) ) {
		return $quote;
	}

	$options = isset( $quote['options'] ) && is_array( $quote['options'] ) ? $quote['options'] : array();

	foreach ( $options as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}

		if ( $selected_code === sanitize_text_field( (string) ( $option['code'] ?? '' ) ) ) {
			return $option;
		}
	}

	return new WP_Error(
		'papelito_checkout_shipping_stale',
		'A cotação de frete mudou. Selecione novamente a opção de entrega.',
		array( 'status' => 409 )
	);
}

/**
 * Monta o endereco WooCommerce a partir do endereco do checkout e do usuario atual.
 *
 * @param int $user_id ID do cliente.
 * @param array<string,string> $address Endereco validado.
 * @return array<string,string>
 */
function papelito_order_routing_build_wc_address( int $user_id, array $address ): array {
	$first_name = sanitize_text_field( (string) get_user_meta( $user_id, 'first_name', true ) );
	$last_name  = sanitize_text_field( (string) get_user_meta( $user_id, 'last_name', true ) );
	$phone      = sanitize_text_field( (string) get_user_meta( $user_id, 'phone_number', true ) );
	$user       = get_userdata( $user_id );

	return array(
		'first_name' => $first_name,
		'last_name'  => $last_name,
		'company'    => '',
		'email'      => $user instanceof WP_User ? sanitize_email( $user->user_email ) : '',
		'phone'      => $phone,
		'address_1'  => $address['street'] . ', ' . $address['number'],
		'address_2'  => $address['complement'],
		'city'       => $address['city'],
		'state'      => $address['state'],
		'postcode'   => $address['zip_code'],
		'country'    => 'BR',
	);
}

/**
 * Adiciona o item de cupom ao pedido, preservando o codigo usado.
 *
 * @param object $order Pedido alvo.
 * @param array<string,mixed> $coupon Cupom resolvido.
 * @return void
 */
function papelito_order_routing_add_coupon_item( $order, array $coupon ): void {
	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) || ! class_exists( 'WC_Order_Item_Coupon' ) ) {
		return;
	}

	$coupon_item_class = 'WC_Order_Item_Coupon';
	$item              = new $coupon_item_class();
	$item->set_code( (string) $coupon['code'] );
	$item->set_discount( (float) $coupon['discount_value'] );
	$item->set_discount_tax( 0 );
	$order->add_item( $item );
}

/**
 * Cria um pedido real do WooCommerce a partir do payload validado.
 *
 * @param int $user_id Cliente autenticado.
 * @param array<string,string> $address Endereco validado.
 * @param array<int,array<string,mixed>> $lines Linhas resolvidas.
 * @param array<string,mixed> $shipping Opcao de frete revalidada.
 * @param array<string,mixed>|null $coupon Cupom resolvido.
 * @param string $payment_method Metodo de pagamento.
 * @return array<string,mixed>|WP_Error
 */
function papelito_order_routing_create_order( int $user_id, array $address, array $lines, array $shipping, ?array $coupon, string $payment_method, string $checkout_attempt_id = '', array $b2b_snapshot = array(), string $request_hash = '' ) {
	if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Order' ) || ! class_exists( 'WC_Order_Item_Shipping' ) || ! class_exists( 'WC_Order_Item_Product' ) ) {
		return new WP_Error(
			'papelito_checkout_woocommerce_unavailable',
			'WooCommerce indisponível para concluir o pedido.',
			array( 'status' => 500 )
		);
	}

	$order = wc_create_order(
		array(
			'customer_id' => $user_id,
			'created_via' => 'papelito_headless_checkout',
		)
	);

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
		return new WP_Error(
			'papelito_checkout_invalid_order_instance',
			'WooCommerce retornou um pedido inválido.',
			array( 'status' => 500 )
		);
	}

	if ( empty( $lines ) ) {
		return new WP_Error(
			'papelito_checkout_empty_resolved_lines',
			'Não foi possível concluir o pedido sem itens validos.',
			array( 'status' => 422 )
		);
	}

	$vendor_id   = (int) $lines[0]['vendor_id'];
	$vendor_name = (string) $lines[0]['vendor_name'];
	$wc_address  = papelito_order_routing_build_wc_address( $user_id, $address );
	$billing_address = $wc_address;
	if ( ! empty( $b2b_snapshot['company'] ) && is_array( $b2b_snapshot['company'] ) ) {
		$company = $b2b_snapshot['company'];
		$billing_address = array_merge(
			$billing_address,
			array(
				'first_name' => '',
				'last_name' => '',
				'company' => (string) $company['legal_name'],
				'email' => (string) $company['billing_email'],
				'phone' => (string) $company['phone'],
				'address_1' => trim( (string) $company['fiscal_street'] . ', ' . (string) $company['fiscal_number'] ),
				'address_2' => (string) $company['fiscal_complement'],
				'city' => (string) $company['fiscal_city'],
				'state' => (string) $company['fiscal_state'],
				'postcode' => (string) $company['fiscal_cep'],
			)
		);
	}

	try {
		$order->set_currency( get_woocommerce_currency() );
		$order->set_payment_method( 'papelito_headless_' . $payment_method );
		$order->set_payment_method_title( papelito_order_routing_payment_method_label( $payment_method ) );
		$order->set_address( $billing_address, 'billing' );
		$order->set_address( $wc_address, 'shipping' );

		foreach ( $lines as $line ) {
			$item_id = $order->add_product(
				$line['product'],
				(int) $line['qty'],
				array(
					'subtotal' => (float) $line['subtotal'],
					'total'    => (float) $line['total'],
				)
			);

			$item = $order->get_item( $item_id );

			if ( papelito_order_routing_is_wc_instance( $item, 'WC_Order_Item_Product' ) ) {
				$item->add_meta_data( '_vendor_id', $vendor_id, true );
				$item->add_meta_data( '_vendor_name', $vendor_name, true );
				$item->add_meta_data( '_papelito_discount_source', sanitize_key( (string) ( $line['discount_source'] ?? 'none' ) ), true );
				$item->add_meta_data( '_papelito_subtotal_cents', (int) ( $line['subtotal_cents'] ?? 0 ), true );
				$item->add_meta_data( '_papelito_discount_cents', (int) ( $line['discount_cents'] ?? 0 ), true );
				$item->add_meta_data( '_papelito_total_cents', (int) ( $line['total_cents'] ?? 0 ), true );
				if ( ! empty( $line['kit_snapshot'] ) ) {
					$item->add_meta_data( '_papelito_kit_snapshot', wp_json_encode( $line['kit_snapshot'] ), true );
				}
				$item->save();
			}
		}

		// O frete grátis abate a modalidade escolhida: o item de frete guarda o valor
		// efetivamente cobrado, e o preço cheio fica no meta para auditoria e recibo.
		$shipping_price_cents    = papelito_pricing_to_cents( (float) ( $shipping['price'] ?? 0 ) );
		$shipping_discount_cents = min(
			$shipping_price_cents,
			max( 0, papelito_pricing_to_cents( (float) ( $shipping['discount'] ?? 0 ) ) )
		);
		$shipping_charged_cents  = $shipping_price_cents - $shipping_discount_cents;

		$shipping_item_class = 'WC_Order_Item_Shipping';
		$shipping_item       = new $shipping_item_class();
		$shipping_item->set_method_id( 'papelito_correios_' . strtolower( sanitize_key( (string) ( $shipping['service'] ?? 'shipping' ) ) ) );
		$shipping_item->set_method_title( sanitize_text_field( (string) ( $shipping['name'] ?? $shipping['service'] ?? 'Correios' ) ) );
		$shipping_item->set_total( papelito_pricing_from_cents( $shipping_charged_cents ) );
		$shipping_item->add_meta_data( '_papelito_shipping_service_code', sanitize_text_field( (string) ( $shipping['code'] ?? '' ) ), true );
		$shipping_item->add_meta_data( '_papelito_shipping_price_cents', $shipping_price_cents, true );
		$shipping_item->add_meta_data( '_papelito_shipping_discount_cents', $shipping_discount_cents, true );
		$order->add_item( $shipping_item );

		if ( null !== $coupon ) {
			papelito_order_routing_add_coupon_item( $order, $coupon );
		}

		$order->update_meta_data( '_papelito_vendor_id', $vendor_id );
		$order->update_meta_data( '_papelito_vendor_name', $vendor_name );
		$order->update_meta_data( '_papelito_shipping_service_code', sanitize_text_field( (string) ( $shipping['code'] ?? '' ) ) );
		$order->update_meta_data( '_papelito_shipping_service_name', sanitize_text_field( (string) ( $shipping['name'] ?? $shipping['service'] ?? '' ) ) );
		$order->update_meta_data( '_papelito_shipping_delivery_time', absint( $shipping['delivery_time'] ?? 0 ) );
		$order->update_meta_data( '_papelito_shipping_price_cents', $shipping_price_cents );
		$order->update_meta_data( '_papelito_shipping_discount_cents', $shipping_discount_cents );
		// WooCommerce nao possui campo nativo de bairro; preserve o snapshot para expedicao.
		$order->update_meta_data( '_papelito_shipping_neighborhood', sanitize_text_field( (string) ( $address['neighborhood'] ?? '' ) ) );
		$order->update_meta_data( '_papelito_stock_decremented', '0' );
		$order->update_meta_data( '_papelito_vendor_status', PAPELITO_ORDER_VENDOR_STATUS_AWAITING_PAYMENT );
		papelito_order_routing_store_b2b_snapshot( $order, $b2b_snapshot );

		if ( '' !== $checkout_attempt_id ) {
			$order->update_meta_data( PAPELITO_CHECKOUT_ATTEMPT_ID_META, $checkout_attempt_id );
			$order->update_meta_data( PAPELITO_CHECKOUT_ATTEMPT_COMPANY_META, (int) ( $b2b_snapshot['company_id'] ?? 0 ) );
			$order->update_meta_data( PAPELITO_CHECKOUT_ATTEMPT_HASH_META, $request_hash );
		}

		$authoritative_total_cents = $shipping_charged_cents;
		foreach ( $lines as $line ) {
			$authoritative_total_cents += max( 0, (int) ( $line['total_cents'] ?? 0 ) );
		}

		$order->calculate_totals( false );
		$order->update_meta_data( '_papelito_authoritative_total_cents', $authoritative_total_cents );
		$order->add_order_note( 'Pedido criado via checkout headless Papelito.' );
		$order->save();
	} catch ( Throwable $throwable ) {
		$order->add_order_note( 'Falha ao concluir o pedido headless: ' . sanitize_text_field( $throwable->getMessage() ) );
		$order->save();

		return new WP_Error(
			'papelito_checkout_order_creation_failed',
			'Não foi possível concluir o pedido.',
			array( 'status' => 500 )
		);
	}

	return array(
		'order_id'     => $order->get_id(),
		'order_number' => $order->get_order_number(),
		'status'       => $order->get_status(),
	);
}

/**
 * Reconstroi as linhas de um pedido Woo para uso em rollback/reconciliacao.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_order_routing_order_lines( object $order ): array {
	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
		return array();
	}

	$default_vendor_id   = absint( $order->get_meta( '_papelito_vendor_id', true ) );
	$default_vendor_name = sanitize_text_field( (string) $order->get_meta( '_papelito_vendor_name', true ) );
	$lines               = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) || ! method_exists( $item, 'get_meta' ) || ! method_exists( $item, 'get_quantity' ) || ! method_exists( $item, 'get_total' ) ) {
			continue;
		}

		$product_id = (int) $item->get_product_id();

		$snapshot = json_decode( (string) $item->get_meta( '_papelito_kit_snapshot', true ), true );
		$lines[] = array(
			'product'     => function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null,
			'product_id'  => $product_id,
			'qty'         => (int) $item->get_quantity(),
			'vendor_id'   => absint( $item->get_meta( '_vendor_id', true ) ) ?: $default_vendor_id,
			'vendor_name' => sanitize_text_field( (string) $item->get_meta( '_vendor_name', true ) ) ?: $default_vendor_name,
			'subtotal'    => (float) ( method_exists( $item, 'get_subtotal' ) ? $item->get_subtotal() : $item->get_total() ),
			'total'       => (float) $item->get_total(),
			'discount'    => 0.0,
			'kit_snapshot' => is_array( $snapshot ) ? $snapshot : array(),
		);
	}

	return $lines;
}

/**
 * Verifica se o usuario atual pode visualizar o mapeamento de vendor do pedido.
 *
 * @param object $order Pedido alvo.
 * @param int      $user_id Usuario atual.
 * @return bool
 */
function papelito_order_routing_user_can_view_vendor_items( $order, int $user_id ): bool {
	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
		return false;
	}

	if ( $user_id <= 0 ) {
		return false;
	}

	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	if ( (int) $order->get_user_id() === $user_id ) {
		return true;
	}

	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User || ! papelito_user_is_effective_seller( $user ) ) {
		return false;
	}

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			continue;
		}

		$vendor_id = absint( $item->get_meta( '_vendor_id', true ) );

		if ( $vendor_id === $user_id ) {
			return true;
		}
	}

	return false;
}

/**
 * Lista os itens do pedido com o vendor associado.
 *
 * @param object $order Pedido alvo.
 * @return array<int,array<string,mixed>>
 */
function papelito_order_routing_map_order_items_vendor( $order ): array {
	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
		return array();
	}

	$default_vendor_id   = absint( $order->get_meta( '_papelito_vendor_id', true ) );
	$default_vendor_name = sanitize_text_field( (string) $order->get_meta( '_papelito_vendor_name', true ) );
	$vendor_status       = sanitize_text_field( (string) $order->get_meta( '_papelito_vendor_status', true ) );
	$response            = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_id' ) || ! method_exists( $item, 'get_product_id' ) || ! method_exists( $item, 'get_meta' ) || ! method_exists( $item, 'get_quantity' ) ) {
			continue;
		}

		$response[] = array(
			'item_id'      => $item->get_id(),
			'product_id'   => $item->get_product_id(),
			'vendor_id'    => absint( $item->get_meta( '_vendor_id', true ) ) ?: $default_vendor_id,
			'vendor_name'  => sanitize_text_field( (string) $item->get_meta( '_vendor_name', true ) ) ?: $default_vendor_name,
			'qty'          => (int) $item->get_quantity(),
			'vendor_status' => '' !== $vendor_status ? $vendor_status : PAPELITO_ORDER_VENDOR_STATUS_AWAITING_SHIPMENT,
		);
	}

	return $response;
}

/**
 * Baixa o estoque do vendor quando o pedido entra em processing/completed.
 *
 * @param int $order_id ID do pedido.
 * @return void
 */
function papelito_order_routing_decrement_stock_for_order( int $order_id ): void {
	if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'papelito_adjust_vendor_stock' ) ) {
		return;
	}

	$order = wc_get_order( $order_id );

	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
		return;
	}

	if ( '1' === (string) $order->get_meta( '_papelito_stock_decremented', true ) ) {
		return;
	}

	$default_vendor_id = absint( $order->get_meta( '_papelito_vendor_id', true ) );
	$adjustments       = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) || ! method_exists( $item, 'get_product_id' ) || ! method_exists( $item, 'get_quantity' ) ) {
			continue;
		}

		$vendor_id = absint( $item->get_meta( '_vendor_id', true ) ) ?: $default_vendor_id;
		$product_id = (int) $item->get_product_id();
		$qty        = (int) $item->get_quantity();

		if ( $vendor_id <= 0 || $product_id <= 0 || $qty <= 0 ) {
			continue;
		}

		$snapshot = json_decode( (string) $item->get_meta( '_papelito_kit_snapshot', true ), true );
		$adjustments[] = array(
			'vendor_id'  => $vendor_id,
			'product_id' => $product_id,
			'qty'        => $qty,
			'kit_snapshot' => is_array( $snapshot ) ? $snapshot : array(),
		);
	}

	if ( empty( $adjustments ) ) {
		return;
	}

	foreach ( $adjustments as $adjustment ) {
		$result = function_exists( 'papelito_adjust_stock_line' )
			? papelito_adjust_stock_line( $adjustment, -1, 'order_decrement:#' . $order_id )
			: papelito_adjust_vendor_stock(
				$adjustment['vendor_id'],
				$adjustment['product_id'],
				$adjustment['qty'] * -1,
				'order_decrement:#' . $order_id
			);

		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					'Falha ao baixar estoque do vendor %d no produto %d: %s',
					$adjustment['vendor_id'],
					$adjustment['product_id'],
					$result->get_error_message()
				)
			);
			$order->save();

			return;
		}
	}

	if ( '' === (string) $order->get_meta( '_papelito_vendor_status', true ) ) {
		$order->update_meta_data( '_papelito_vendor_status', PAPELITO_ORDER_VENDOR_STATUS_AWAITING_SHIPMENT );
	}

	$order->update_meta_data( '_papelito_stock_decremented', '1' );
	$order->save();
}

add_action(
	'woocommerce_checkout_create_order_line_item',
	static function ( $item, $cart_item_key, $values, $order ): void {
		if ( ! papelito_order_routing_is_wc_instance( $item, 'WC_Order_Item_Product' ) || ! is_array( $values ) ) {
			return;
		}

		$vendor_id = absint( $values['vendor_id'] ?? 0 );

		if ( $vendor_id > 0 ) {
			$item->add_meta_data( '_vendor_id', $vendor_id, true );
		}

		$vendor_name = sanitize_text_field( (string) ( $values['vendor_name'] ?? '' ) );

		if ( '' !== $vendor_name ) {
			$item->add_meta_data( '_vendor_name', $vendor_name, true );
		}
	},
	10,
	4
);

add_action(
	'woocommerce_order_status_processing',
	'papelito_order_routing_decrement_stock_for_order',
	10,
	1
);

add_action(
	'woocommerce_order_status_completed',
	'papelito_order_routing_decrement_stock_for_order',
	10,
	1
);

/**
 * Processa o checkout headless completo.
 */
function papelito_order_routing_handle_place_order( WP_REST_Request $request ) {
	if ( function_exists( 'papelito_auth_rate_limit' ) && ! papelito_auth_rate_limit( 'checkout_place_order', 30, 60 ) ) {
		return new WP_Error(
			'papelito_rate_limited',
			'Muitas tentativas. Tente novamente em alguns instantes.',
			array( 'status' => 429 )
		);
	}

	if ( ! function_exists( 'papelito_pagarme_is_configured' ) || ! papelito_pagarme_is_configured() ) {
		return new WP_Error(
			'papelito_checkout_payment_unavailable',
			'Checkout indisponível até a configuração do Pagar.me.',
			array( 'status' => 501 )
		);
	}

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'papelito_checkout_invalid_payload', 'Payload invalido.', array( 'status' => 400 ) );
	}

	$user_id = get_current_user_id();
	$b2b_snapshot = papelito_order_routing_resolve_b2b_snapshot( $user_id, $payload );
	if ( is_wp_error( $b2b_snapshot ) ) {
		return $b2b_snapshot;
	}
	$checkout_attempt_id = papelito_order_routing_normalize_checkout_attempt_id( $payload['checkout_attempt_id'] ?? '' );
	$request_hash = papelito_order_routing_checkout_request_hash( $payload );

	if ( '' !== $checkout_attempt_id ) {
		$existing_order = papelito_order_routing_find_order_by_attempt( $user_id, $checkout_attempt_id );
		if ( is_object( $existing_order ) ) {
			$attempt_valid = papelito_order_routing_validate_existing_attempt( $existing_order, (int) ( $b2b_snapshot['company_id'] ?? 0 ), $request_hash );
			if ( is_wp_error( $attempt_valid ) ) {
				return $attempt_valid;
			}
			$existing_response = papelito_order_routing_existing_order_response( $existing_order );

			if ( is_wp_error( $existing_response ) ) {
				return $existing_response;
			}

			if ( is_array( $existing_response ) ) {
				return new WP_REST_Response( $existing_response, 200 );
			}
		}
	}

	$address = papelito_order_routing_normalize_address( $payload['address'] ?? null );
	if ( is_wp_error( $address ) ) {
		return $address;
	}

	$items = papelito_order_routing_normalize_items( $payload['items'] ?? null );
	if ( is_wp_error( $items ) ) {
		return $items;
	}

	$resolved_items = papelito_order_routing_resolve_items( $items );
	if ( is_wp_error( $resolved_items ) ) {
		return $resolved_items;
	}

	$shipping_payload = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
	$destination_cep  = papelito_shipping_normalize_cep( $shipping_payload['destination_cep'] ?? $address['zip_code'] );
	$selected_code    = sanitize_text_field( (string) ( $shipping_payload['selected_code'] ?? '' ) );

	if ( '' === $destination_cep || '' === $selected_code ) {
		return new WP_Error(
			'papelito_checkout_invalid_shipping',
			'Selecione uma opção de frete válida.',
			array( 'status' => 422 )
		);
	}

	$coverage = papelito_order_routing_validate_vendor_coverage( (int) $resolved_items['vendor_id'], $destination_cep );
	if ( is_wp_error( $coverage ) ) {
		return $coverage;
	}

	$shipping = papelito_order_routing_resolve_shipping(
		(int) $resolved_items['vendor_id'],
		$destination_cep,
		$selected_code,
		$resolved_items['lines']
	);
	if ( is_wp_error( $shipping ) ) {
		return $shipping;
	}

	$pricing = papelito_pricing_apply_discounts(
		$resolved_items,
		sanitize_text_field( (string) ( $payload['coupon_code'] ?? '' ) ),
		$user_id,
		papelito_pricing_to_cents( $shipping['price'] ?? 0 )
	);
	if ( is_wp_error( $pricing ) ) {
		return $pricing;
	}

	$lines  = (array) $pricing['lines'];
	$coupon = isset( $pricing['coupon_data'] ) && is_array( $pricing['coupon_data'] ) ? $pricing['coupon_data'] : null;

	$shipping['discount'] = papelito_pricing_from_cents(
		max( 0, (int) ( $pricing['totals']['shippingDiscountCents'] ?? 0 ) )
	);

	$payment = papelito_order_routing_normalize_payment( $payload['payment'] ?? null, $address );
	if ( is_wp_error( $payment ) ) {
		return $payment;
	}

	$amount_validation = papelito_pricing_validate_payment_amount(
		(string) $payment['method'],
		(int) ( $pricing['totals']['totalCents'] ?? 0 ),
		(int) ( $payment['installments'] ?? 1 )
	);
	if ( is_wp_error( $amount_validation ) ) {
		return $amount_validation;
	}

	$recipient_validation = function_exists( 'papelito_pagarme_validate_vendor_recipient' )
		? papelito_pagarme_validate_vendor_recipient( (int) $resolved_items['vendor_id'] )
		: new WP_Error(
			'papelito_checkout_payment_unavailable',
			'Não foi possível validar o recebedor do vendor.',
			array( 'status' => 503 )
		);
	if ( is_wp_error( $recipient_validation ) ) {
		return $recipient_validation;
	}

	$created = papelito_order_routing_create_order( $user_id, $address, $lines, $shipping, $coupon, (string) $payment['method'], $checkout_attempt_id, $b2b_snapshot, $request_hash );
	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $created['order_id'] ) : null;
	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
		return new WP_Error(
			'papelito_checkout_invalid_order_instance',
			'Não foi possível recuperar o pedido recem-criado.',
			array( 'status' => 500 )
		);
	}

	if ( function_exists( 'papelito_ga4_store_order_identifiers' ) && isset( $payload['analytics'] ) && is_array( $payload['analytics'] ) ) {
		papelito_ga4_store_order_identifiers( $order, $payload['analytics'] );
	}

	$reserved = papelito_pagarme_reserve_order_stock( $order, $lines );
	if ( is_wp_error( $reserved ) ) {
		$order->add_order_note( 'Falha ao reservar estoque para o pagamento: ' . $reserved->get_error_message() );
		$order->update_status( 'failed' );
		if ( function_exists( 'papelito_pagarme_mark_vendor_status_unpaid' ) ) {
			papelito_pagarme_mark_vendor_status_unpaid( $order );
		}
		$order->save();
		return $reserved;
	}

	$result = papelito_pagarme_create_order_payment( $order, $user_id, $payment, $address, $lines, $shipping );

	if ( is_wp_error( $result ) ) {
		papelito_pagarme_release_order_stock( $order, $lines, 'payment_error' );
		$order->add_order_note( 'Falha ao criar pedido no Pagar.me: ' . $result->get_error_message() );
		$order->update_status( 'failed' );
		if ( function_exists( 'papelito_pagarme_mark_vendor_status_unpaid' ) ) {
			papelito_pagarme_mark_vendor_status_unpaid( $order );
		}
		$order->save();
		$error_code = $result->get_error_code();
		if ( 'papelito_pagarme_amount_rejected' === $error_code ) {
			$error_code = 'papelito_checkout_gateway_amount_rejected';
		} elseif ( 'papelito_checkout_total_mismatch' !== $error_code ) {
			$error_code = 'papelito_checkout_payment_unavailable';
		}
		return new WP_Error(
			$error_code,
			$result->get_error_message(),
			array( 'status' => is_array( $result->get_error_data() ) ? (int) ( $result->get_error_data()['status'] ?? 502 ) : 502 )
		);
	}

	$payment_state = sanitize_key( (string) ( $result['payment']['state'] ?? '' ) );

	if ( function_exists( 'papelito_pagarme_payment_state_releases_stock' ) && papelito_pagarme_payment_state_releases_stock( $payment_state ) ) {
		papelito_pagarme_release_order_stock( $order, $lines, 'payment_failed' );
	}

	$result['totals']      = $pricing['totals'];
	$result['lines']       = array_map(
		static fn( array $line ): array => array(
			'productId'      => (int) $line['product_id'],
			'quantity'       => (int) $line['qty'],
			'subtotalCents'  => (int) $line['subtotal_cents'],
			'discountCents'  => (int) $line['discount_cents'],
			'totalCents'     => (int) $line['total_cents'],
			'discountSource' => (string) $line['discount_source'],
		),
		$lines
	);
	$result['coupon']      = $pricing['coupon'];
	$result['adjustments'] = $pricing['adjustments'];

	return new WP_REST_Response( $result, 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/checkout/place-order',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_order_routing_require_customer',
				'callback'            => 'papelito_order_routing_handle_place_order',
			)
		);

		register_rest_route(
			'papelito/v1',
			'/orders/(?P<id>\d+)/items-vendor',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function ( WP_REST_Request $request ) {
					$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $request->get_param( 'id' ) ) ) : null;

					if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
						return new WP_Error(
							'papelito_order_not_found',
							'Pedido não encontrado.',
							array( 'status' => 404 )
						);
					}

					if ( ! is_user_logged_in() ) {
						return new WP_Error(
							'papelito_order_auth_required',
							'Não autenticado.',
							array( 'status' => 401 )
						);
					}

					if ( papelito_order_routing_user_can_view_vendor_items( $order, get_current_user_id() ) ) {
						return true;
					}

					return new WP_Error(
						'papelito_order_forbidden',
						'Você não pode acessar o vendor deste pedido.',
						array( 'status' => 403 )
					);
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = wc_get_order( absint( $request->get_param( 'id' ) ) );

					if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
						return new WP_Error(
							'papelito_order_not_found',
							'Pedido não encontrado.',
							array( 'status' => 404 )
						);
					}

					return new WP_REST_Response(
						papelito_order_routing_map_order_items_vendor( $order ),
						200
					);
				},
			)
		);
	}
);
