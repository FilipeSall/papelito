<?php
/**
 * Checkout headless + roteamento de pedidos por vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_ORDER_VENDOR_STATUS_AWAITING_SHIPMENT' ) ) {
	define( 'PAPELITO_ORDER_VENDOR_STATUS_AWAITING_SHIPMENT', 'aguardando_envio' );
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
 * Label legivel do metodo de pagamento.
 *
 * @param string $method Metodo normalizado.
 * @return string
 */
function papelito_order_routing_payment_method_label( string $method ): string {
	switch ( $method ) {
		case 'credit_card':
			return 'Cartao de credito';
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
			'Selecione uma forma de pagamento valida.',
			array( 'status' => 422 )
		);
	}

	$method = papelito_order_routing_normalize_payment_method( $payment['method'] ?? '' );

	if ( '' === $method ) {
		return new WP_Error(
			'papelito_checkout_invalid_payment',
			'Selecione uma forma de pagamento valida.',
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
			'Os dados do cartao nao estao completos.',
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
	if ( ! $user instanceof WP_User || ! in_array( 'customer', (array) $user->roles, true ) ) {
		return new WP_Error(
			'papelito_checkout_customer_only',
			'Somente consumidores finais podem concluir o checkout.',
			array( 'status' => 403 )
		);
	}

	if ( in_array( 'seller', (array) $user->roles, true ) ) {
		return new WP_Error(
			'papelito_checkout_seller_blocked',
			papelito_seller_purchase_block_message(),
			array( 'status' => 403 )
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
			'Endereco de entrega invalido.',
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
			'Preencha os campos obrigatorios do endereco.',
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
	if ( ! is_array( $items ) || empty( $items ) ) {
		return new WP_Error(
			'papelito_checkout_empty_items',
			'Carrinho vazio para concluir o pedido.',
			array( 'status' => 422 )
		);
	}

	$normalized = array();

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$product_id  = absint( $item['product_id'] ?? 0 );
		$qty         = max( 0, (int) ( $item['qty'] ?? 0 ) );
		$vendor_id   = absint( $item['vendor_id'] ?? 0 );
		$vendor_name = sanitize_text_field( (string) ( $item['vendor_name'] ?? '' ) );

		if ( $product_id <= 0 || $qty <= 0 || $vendor_id <= 0 ) {
			return new WP_Error(
				'papelito_checkout_invalid_items',
				'Itens invalidos para concluir o pedido.',
				array( 'status' => 422 )
			);
		}

		$normalized[] = array(
			'product_id'  => $product_id,
			'qty'         => $qty,
			'vendor_id'   => $vendor_id,
			'vendor_name' => $vendor_name,
		);
	}

	if ( empty( $normalized ) ) {
		return new WP_Error(
			'papelito_checkout_empty_items',
			'Carrinho vazio para concluir o pedido.',
			array( 'status' => 422 )
		);
	}

	return $normalized;
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
	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'papelito_checkout_woocommerce_unavailable',
			'WooCommerce indisponivel para concluir o pedido.',
			array( 'status' => 500 )
		);
	}

	$vendor_ids = array_values(
		array_unique(
			array_map(
				static function ( array $item ): int {
					return (int) $item['vendor_id'];
				},
				$items
			)
		)
	);

	if ( 1 !== count( $vendor_ids ) ) {
		return new WP_Error(
			'papelito_checkout_mixed_vendor_not_supported',
			'O checkout atual suporta apenas um vendor por pedido.',
			array( 'status' => 422 )
		);
	}

	$vendor_id = (int) $vendor_ids[0];
	$vendor    = papelito_shipping_get_vendor( $vendor_id );

	if ( is_wp_error( $vendor ) ) {
		return $vendor;
	}

	if (
		function_exists( 'papelito_get_seller_application_status' ) &&
		'approved' !== papelito_get_seller_application_status( $vendor_id )
	) {
		return new WP_Error(
			'papelito_checkout_vendor_not_approved',
			'O vendor selecionado nao esta apto para receber pedidos.',
			array( 'status' => 422 )
		);
	}

	$vendor_name = papelito_order_routing_vendor_name( $vendor_id );
	$lines       = array();

	foreach ( $items as $item ) {
		$product = wc_get_product( $item['product_id'] );

		if ( ! $product ) {
			return new WP_Error(
				'papelito_product_not_found',
				'Produto do carrinho nao encontrado.',
				array( 'status' => 404 )
			);
		}

		$current_stock = (int) papelito_get_vendor_stock( $vendor_id, $item['product_id'] );
		if ( $current_stock < $item['qty'] ) {
			return new WP_Error(
				'papelito_checkout_insufficient_stock',
				sprintf( 'Estoque insuficiente para o produto "%s".', $product->get_name() ),
				array(
					'status'     => 409,
					'product_id' => $item['product_id'],
					'available'  => $current_stock,
					'requested'  => (int) $item['qty'],
				)
			);
		}

		$unit_price = (float) $product->get_price();
		if ( $unit_price < 0 ) {
			$unit_price = 0.0;
		}

		$subtotal = round( $unit_price * (int) $item['qty'], 2 );

		$lines[] = array(
			'product'      => $product,
			'product_id'   => (int) $item['product_id'],
			'qty'          => (int) $item['qty'],
			'vendor_id'    => $vendor_id,
			'vendor_name'  => $vendor_name,
			'unit_price'   => $unit_price,
			'subtotal'     => $subtotal,
			'total'        => $subtotal,
			'discount'     => 0.0,
		);
	}

	return array(
		'vendor_id'   => $vendor_id,
		'vendor_name' => $vendor_name,
		'lines'       => $lines,
	);
}

/**
 * Valida a cobertura do vendor para o CEP de destino.
 *
 * @return true|WP_Error
 */
function papelito_order_routing_validate_vendor_coverage( int $vendor_id, string $destination_cep ) {
	if ( '' === $destination_cep || ! function_exists( 'papelito_matching_vendor_ids' ) ) {
		return true;
	}

	$covering_ids = array_map( 'intval', papelito_matching_vendor_ids( (int) $destination_cep ) );

	if ( in_array( $vendor_id, $covering_ids, true ) ) {
		return true;
	}

	return new WP_Error(
		'papelito_checkout_vendor_not_approved',
		'O vendor selecionado nao atende o CEP do pedido.',
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
		'A cotacao de frete mudou. Selecione novamente a opcao de entrega.',
		array( 'status' => 409 )
	);
}

/**
 * Revalida o cupom atual contra os precos resolvidos no backend.
 *
 * @param string $coupon_code Codigo do cupom.
 * @param array<int,array<string,mixed>> $lines Linhas do pedido.
 * @param int $user_id Usuario logado.
 * @return array<string,mixed>|null|WP_Error
 */
function papelito_order_routing_resolve_coupon( string $coupon_code, array $lines, int $user_id ) {
	$normalized_code = strtoupper( trim( sanitize_text_field( $coupon_code ) ) );

	if ( '' === $normalized_code ) {
		return null;
	}

	$cart_items = array_map(
		static function ( array $line ): array {
			return array(
				'product_id' => (int) $line['product_id'],
				'vendor_id'  => (int) $line['vendor_id'],
				'qty'        => (int) $line['qty'],
				'price'      => (float) $line['unit_price'],
			);
		},
		$lines
	);

	$result = papelito_coupon_apply_resolve( $normalized_code, $cart_items, $user_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result;
}

/**
 * Distribui o desconto do cupom pelas linhas qualificadas.
 *
 * @param array<int,array<string,mixed>> $lines  Linhas do pedido.
 * @param array<string,mixed>|null       $coupon Cupom resolvido.
 * @return array<int,array<string,mixed>>
 */
function papelito_order_routing_apply_coupon_to_lines( array $lines, ?array $coupon ): array {
	if ( null === $coupon || empty( $coupon['discount_value'] ) || empty( $coupon['applied_product_ids'] ) ) {
		return $lines;
	}

	$applied_product_ids = array_map( 'intval', (array) $coupon['applied_product_ids'] );
	$discount_total      = round( (float) $coupon['discount_value'], 2 );
	$eligible_indexes    = array();
	$eligible_subtotal   = 0.0;

	foreach ( $lines as $index => $line ) {
		if ( in_array( (int) $line['product_id'], $applied_product_ids, true ) ) {
			$eligible_indexes[] = $index;
			$eligible_subtotal += (float) $line['subtotal'];
		}
	}

	if ( empty( $eligible_indexes ) || $eligible_subtotal <= 0 ) {
		return $lines;
	}

	$remaining_discount = min( $discount_total, round( $eligible_subtotal, 2 ) );
	$last_index         = count( $eligible_indexes ) - 1;

	foreach ( $eligible_indexes as $position => $index ) {
		$line_subtotal = round( (float) $lines[ $index ]['subtotal'], 2 );

		if ( $position === $last_index ) {
			$line_discount = round( max( 0, min( $remaining_discount, $line_subtotal ) ), 2 );
		} else {
			$line_discount = round( ( $line_subtotal / $eligible_subtotal ) * $discount_total, 2 );
			$line_discount = max( 0, min( $line_discount, $line_subtotal, $remaining_discount ) );
			$remaining_discount = round( $remaining_discount - $line_discount, 2 );
		}

		$lines[ $index ]['discount'] = $line_discount;
		$lines[ $index ]['total']    = round( max( 0, $line_subtotal - $line_discount ), 2 );
	}

	return $lines;
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
function papelito_order_routing_create_order( int $user_id, array $address, array $lines, array $shipping, ?array $coupon, string $payment_method ) {
	if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Order' ) || ! class_exists( 'WC_Order_Item_Shipping' ) || ! class_exists( 'WC_Order_Item_Product' ) ) {
		return new WP_Error(
			'papelito_checkout_woocommerce_unavailable',
			'WooCommerce indisponivel para concluir o pedido.',
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
			'WooCommerce retornou um pedido invalido.',
			array( 'status' => 500 )
		);
	}

	if ( empty( $lines ) ) {
		return new WP_Error(
			'papelito_checkout_empty_resolved_lines',
			'Nao foi possivel concluir o pedido sem itens validos.',
			array( 'status' => 422 )
		);
	}

	$vendor_id   = (int) $lines[0]['vendor_id'];
	$vendor_name = (string) $lines[0]['vendor_name'];
	$wc_address  = papelito_order_routing_build_wc_address( $user_id, $address );

	try {
		$order->set_currency( get_woocommerce_currency() );
		$order->set_payment_method( 'papelito_headless_' . $payment_method );
		$order->set_payment_method_title( papelito_order_routing_payment_method_label( $payment_method ) );
		$order->set_address( $wc_address, 'billing' );
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
					$item->save();
				}
			}

			$shipping_item_class = 'WC_Order_Item_Shipping';
			$shipping_item       = new $shipping_item_class();
			$shipping_item->set_method_id( 'papelito_correios_' . strtolower( sanitize_key( (string) ( $shipping['service'] ?? 'shipping' ) ) ) );
			$shipping_item->set_method_title( sanitize_text_field( (string) ( $shipping['name'] ?? $shipping['service'] ?? 'Correios' ) ) );
			$shipping_item->set_total( (float) ( $shipping['price'] ?? 0 ) );
		$shipping_item->add_meta_data( '_papelito_shipping_service_code', sanitize_text_field( (string) ( $shipping['code'] ?? '' ) ), true );
		$order->add_item( $shipping_item );

		if ( null !== $coupon ) {
			papelito_order_routing_add_coupon_item( $order, $coupon );
		}

		$order->update_meta_data( '_papelito_vendor_id', $vendor_id );
		$order->update_meta_data( '_papelito_vendor_name', $vendor_name );
		$order->update_meta_data( '_papelito_shipping_service_code', sanitize_text_field( (string) ( $shipping['code'] ?? '' ) ) );
		$order->update_meta_data( '_papelito_shipping_service_name', sanitize_text_field( (string) ( $shipping['name'] ?? $shipping['service'] ?? '' ) ) );
		$order->update_meta_data( '_papelito_shipping_delivery_time', absint( $shipping['delivery_time'] ?? 0 ) );
		$order->update_meta_data( '_papelito_stock_decremented', '0' );
		$order->update_meta_data( '_papelito_vendor_status', PAPELITO_ORDER_VENDOR_STATUS_AWAITING_SHIPMENT );

		$order->calculate_totals( false );
		$order->add_order_note( 'Pedido criado via checkout headless Papelito.' );
		$order->save();
	} catch ( Throwable $throwable ) {
		$order->add_order_note( 'Falha ao concluir o pedido headless: ' . sanitize_text_field( $throwable->getMessage() ) );
		$order->save();

		return new WP_Error(
			'papelito_checkout_order_creation_failed',
			'Nao foi possivel concluir o pedido.',
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
function papelito_order_routing_order_lines( $order ): array {
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

		$lines[] = array(
			'product'     => function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null,
			'product_id'  => $product_id,
			'qty'         => (int) $item->get_quantity(),
			'vendor_id'   => absint( $item->get_meta( '_vendor_id', true ) ) ?: $default_vendor_id,
			'vendor_name' => sanitize_text_field( (string) $item->get_meta( '_vendor_name', true ) ) ?: $default_vendor_name,
			'subtotal'    => (float) ( method_exists( $item, 'get_subtotal' ) ? $item->get_subtotal() : $item->get_total() ),
			'total'       => (float) $item->get_total(),
			'discount'    => 0.0,
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

	if ( ! $user instanceof WP_User || ! in_array( 'seller', (array) $user->roles, true ) ) {
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

		$adjustments[] = array(
			'vendor_id'  => $vendor_id,
			'product_id' => $product_id,
			'qty'        => $qty,
		);
	}

	if ( empty( $adjustments ) ) {
		return;
	}

	foreach ( $adjustments as $adjustment ) {
		$result = papelito_adjust_vendor_stock(
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
			'Checkout indisponivel ate a configuracao do Pagar.me.',
			array( 'status' => 501 )
		);
	}

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'papelito_checkout_invalid_payload', 'Payload invalido.', array( 'status' => 400 ) );
	}

	$user_id = get_current_user_id();
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
			'Selecione uma opcao de frete valida.',
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

	$coupon = papelito_order_routing_resolve_coupon(
		sanitize_text_field( (string) ( $payload['coupon_code'] ?? '' ) ),
		$resolved_items['lines'],
		$user_id
	);
	if ( is_wp_error( $coupon ) ) {
		return $coupon;
	}

	$lines   = papelito_order_routing_apply_coupon_to_lines( $resolved_items['lines'], $coupon );
	$payment = papelito_order_routing_normalize_payment( $payload['payment'] ?? null, $address );
	if ( is_wp_error( $payment ) ) {
		return $payment;
	}

	$created = papelito_order_routing_create_order( $user_id, $address, $lines, $shipping, $coupon, (string) $payment['method'] );
	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $created['order_id'] ) : null;
	if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
		return new WP_Error(
			'papelito_checkout_invalid_order_instance',
			'Nao foi possivel recuperar o pedido recem-criado.',
			array( 'status' => 500 )
		);
	}

	$reserved = papelito_pagarme_reserve_order_stock( $order, $lines );
	if ( is_wp_error( $reserved ) ) {
		$order->add_order_note( 'Falha ao reservar estoque para o pagamento: ' . $reserved->get_error_message() );
		$order->save();
		return $reserved;
	}

	$result = papelito_pagarme_create_order_payment( $order, $user_id, $payment, $address, $lines, $shipping );

	if ( is_wp_error( $result ) ) {
		papelito_pagarme_release_order_stock( $order, $lines, 'payment_error' );
		$order->add_order_note( 'Falha ao criar pedido no Pagar.me: ' . $result->get_error_message() );
		$order->update_status( 'failed' );
		$order->save();
		return new WP_Error(
			'papelito_checkout_payment_unavailable',
			$result->get_error_message(),
			array( 'status' => is_array( $result->get_error_data() ) ? (int) ( $result->get_error_data()['status'] ?? 502 ) : 502 )
		);
	}

	$payment_state = sanitize_key( (string) ( $result['payment']['state'] ?? '' ) );

	if ( function_exists( 'papelito_pagarme_payment_state_releases_stock' ) && papelito_pagarme_payment_state_releases_stock( $payment_state ) ) {
		papelito_pagarme_release_order_stock( $order, $lines, 'payment_failed' );
	}

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
							'Pedido nao encontrado.',
							array( 'status' => 404 )
						);
					}

					if ( ! is_user_logged_in() ) {
						return new WP_Error(
							'papelito_order_auth_required',
							'Nao autenticado.',
							array( 'status' => 401 )
						);
					}

					if ( papelito_order_routing_user_can_view_vendor_items( $order, get_current_user_id() ) ) {
						return true;
					}

					return new WP_Error(
						'papelito_order_forbidden',
						'Voce nao pode acessar o vendor deste pedido.',
						array( 'status' => 403 )
					);
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = wc_get_order( absint( $request->get_param( 'id' ) ) );

					if ( ! papelito_order_routing_is_wc_instance( $order, 'WC_Order' ) ) {
						return new WP_Error(
							'papelito_order_not_found',
							'Pedido nao encontrado.',
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
