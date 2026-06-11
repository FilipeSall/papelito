<?php
/**
 * Fluxo de pagamentos Pagar.me por pedido.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PAGARME_ORDER_ID_META' ) ) {
	define( 'PAPELITO_PAGARME_ORDER_ID_META', '_papelito_pagarme_order_id' );
	define( 'PAPELITO_PAGARME_CHARGE_ID_META', '_papelito_pagarme_charge_id' );
	define( 'PAPELITO_PAGARME_PAYMENT_METHOD_META', '_papelito_pagarme_payment_method' );
	define( 'PAPELITO_PAGARME_PAYMENT_STATE_META', '_papelito_pagarme_payment_state' );
	define( 'PAPELITO_PAGARME_PIX_COPY_PASTE_META', '_papelito_pagarme_pix_copy_paste' );
	define( 'PAPELITO_PAGARME_PIX_QR_CODE_META', '_papelito_pagarme_pix_qr_code' );
	define( 'PAPELITO_PAGARME_PIX_EXPIRES_AT_META', '_papelito_pagarme_pix_expires_at' );
	define( 'PAPELITO_PAGARME_BOLETO_URL_META', '_papelito_pagarme_boleto_url' );
	define( 'PAPELITO_PAGARME_BOLETO_LINE_META', '_papelito_pagarme_boleto_line' );
	define( 'PAPELITO_PAGARME_BOLETO_EXPIRES_AT_META', '_papelito_pagarme_boleto_expires_at' );
	define( 'PAPELITO_STOCK_RESERVED_META', '_papelito_stock_reserved' );
	define( 'PAPELITO_PAGARME_RECONCILE_HOOK', 'papelito_pagarme_reconcile_pending_stock' );
	define( 'PAPELITO_PAGARME_PIX_RESERVATION_GRACE', HOUR_IN_SECONDS );
	define( 'PAPELITO_PAGARME_BOLETO_RESERVATION_GRACE', 3 * DAY_IN_SECONDS );
}

/**
 * Retorna o estado serializado do pagamento do pedido.
 *
 * @return array<string,mixed>
 */
function papelito_pagarme_order_payment_snapshot( $order ): array {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return array(
			'method' => '',
			'state'  => '',
		);
	}

	$method = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ) );
	$state  = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ) );
	$pix    = array_filter(
		array(
			'qr_code'    => (string) $order->get_meta( PAPELITO_PAGARME_PIX_QR_CODE_META, true ),
			'copy_paste' => (string) $order->get_meta( PAPELITO_PAGARME_PIX_COPY_PASTE_META, true ),
			'expires_at' => (string) $order->get_meta( PAPELITO_PAGARME_PIX_EXPIRES_AT_META, true ),
		),
		static fn( $value ): bool => is_string( $value ) && '' !== $value
	);
	$boleto = array_filter(
		array(
			'url'        => (string) $order->get_meta( PAPELITO_PAGARME_BOLETO_URL_META, true ),
			'line'       => (string) $order->get_meta( PAPELITO_PAGARME_BOLETO_LINE_META, true ),
			'expires_at' => (string) $order->get_meta( PAPELITO_PAGARME_BOLETO_EXPIRES_AT_META, true ),
		),
		static fn( $value ): bool => is_string( $value ) && '' !== $value
	);

	$snapshot = array(
		'method' => $method,
		'state'  => $state,
	);

	if ( ! empty( $pix ) ) {
		$snapshot['pix'] = $pix;
	}

	if ( ! empty( $boleto ) ) {
		$snapshot['boleto'] = $boleto;
	}

	return $snapshot;
}

/**
 * Monta o endereco de cobranca.
 *
 * @param array<string,string> $address Endereco.
 * @return array<string,string>
 */
function papelito_pagarme_billing_address_payload( array $address ): array {
	return papelito_pagarme_address_payload(
		array(
			'street'     => $address['street'] ?? '',
			'number'     => $address['number'] ?? '',
			'complement' => $address['complement'] ?? '',
			'city'       => $address['city'] ?? '',
			'state'      => $address['state'] ?? '',
			'zip_code'   => $address['zip_code'] ?? '',
		)
	);
}

/**
 * Converte linhas do pedido em itens exatos para a API.
 *
 * @param array<int,array<string,mixed>> $lines Linhas do pedido.
 * @param array<string,mixed>            $shipping Frete.
 * @return array<int,array<string,mixed>>
 */
function papelito_pagarme_order_items_payload( array $lines, array $shipping ): array {
	$items = array();

	foreach ( $lines as $line ) {
		$qty          = max( 1, (int) $line['qty'] );
		$total_cents  = (int) round( 100 * (float) $line['total'] );
		$base_amount  = intdiv( $total_cents, $qty );
		$remainder    = $total_cents - ( $base_amount * $qty );
		$product_code = 'product-' . (int) $line['product_id'];
		$name         = is_object( $line['product'] ) && method_exists( $line['product'], 'get_name' )
			? sanitize_text_field( (string) $line['product']->get_name() )
			: 'Produto Papelito';

		for ( $i = 0; $i < $qty; $i++ ) {
			$unit_amount = $base_amount + ( $i === ( $qty - 1 ) ? $remainder : 0 );
			$items[]     = array(
				'amount'      => max( 0, $unit_amount ),
				'description' => $name,
				'quantity'    => 1,
				'code'        => $product_code . '-' . ( $i + 1 ),
			);
		}
	}

	$shipping_cents = (int) round( 100 * (float) ( $shipping['price'] ?? 0 ) );

	if ( $shipping_cents > 0 ) {
		$items[] = array(
			'amount'      => $shipping_cents,
			'description' => 'Frete',
			'quantity'    => 1,
			'code'        => 'shipping',
		);
	}

	return $items;
}

/**
 * Resolve o documento do comprador (CPF ou CNPJ) a partir do perfil.
 *
 * Retorna apenas os digitos do primeiro meta preenchido entre `cnpj` e `cpf`.
 */
function papelito_pagarme_resolve_customer_document( int $user_id ): string {
	foreach ( array( 'cnpj', 'cpf' ) as $meta_key ) {
		$digits = preg_replace( '/\D+/', '', (string) get_user_meta( $user_id, $meta_key, true ) );

		if ( '' !== $digits ) {
			return $digits;
		}
	}

	return '';
}

/**
 * Monta o payload do cliente.
 *
 * @param array<string,string> $address Endereco.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_customer_payload( int $user_id, array $address ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_checkout_customer_not_found', 'Cliente nao encontrado.', array( 'status' => 404 ) );
	}

	$first_name = sanitize_text_field( (string) get_user_meta( $user_id, 'first_name', true ) );
	$last_name  = sanitize_text_field( (string) get_user_meta( $user_id, 'last_name', true ) );
	$name       = trim( $first_name . ' ' . $last_name );
	$phone      = sanitize_text_field( (string) get_user_meta( $user_id, 'phone_number', true ) );
	$document   = papelito_pagarme_resolve_customer_document( $user_id );

	if ( '' === $name ) {
		$name = sanitize_text_field( (string) $user->display_name );
	}

	if ( ! in_array( strlen( $document ), array( 11, 14 ), true ) ) {
		return new WP_Error(
			'papelito_checkout_invalid_payment',
			'Informe um CPF ou CNPJ valido no seu perfil para concluir o pagamento.',
			array( 'status' => 422 )
		);
	}

	return array(
		'name'          => $name,
		'email'         => sanitize_email( (string) $user->user_email ),
		'document_type' => 14 === strlen( $document ) ? 'CNPJ' : 'CPF',
		'document'      => $document,
		'type'          => 14 === strlen( $document ) ? 'company' : 'individual',
		'phones'        => array(
			'mobile_phone' => papelito_pagarme_phone_payload( $phone ),
		),
		'address'       => papelito_pagarme_billing_address_payload( $address ),
	);
}

/**
 * Retorna o split unico para o vendor.
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_pagarme_vendor_split_payload( int $vendor_id, int $amount ) {
	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $vendor_id );

	if ( '' === $recipient_id || ! papelito_pagarme_vendor_recipient_is_active( $vendor_id ) ) {
		return new WP_Error(
			'papelito_checkout_vendor_not_approved',
			'O vendor selecionado nao esta apto para receber pagamentos.',
			array( 'status' => 422 )
		);
	}

	return array(
		array(
			'recipient_id' => $recipient_id,
			'amount'       => max( 0, $amount ),
			'type'         => 'flat',
			'options'      => array(
				'liable'                => true,
				'charge_processing_fee' => true,
				'charge_remainder_fee'  => true,
			),
		),
	);
}

/**
 * Reserva o estoque das linhas do pedido.
 *
 * @param array<int,array<string,mixed>> $lines Linhas do pedido.
 * @return true|WP_Error
 */
function papelito_pagarme_reserve_order_stock( $order, array $lines ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return new WP_Error( 'papelito_checkout_invalid_order_instance', 'Pedido invalido para reservar estoque.', array( 'status' => 500 ) );
	}

	if ( ! function_exists( 'papelito_adjust_vendor_stock' ) ) {
		return new WP_Error( 'papelito_checkout_stock_unavailable', 'Estoque indisponivel para reservar o pedido.', array( 'status' => 500 ) );
	}

	if ( '1' === (string) $order->get_meta( PAPELITO_STOCK_RESERVED_META, true ) ) {
		return true;
	}

	$reserved_lines = array();

	foreach ( $lines as $line ) {
		$result = papelito_adjust_vendor_stock(
			(int) $line['vendor_id'],
			(int) $line['product_id'],
			(int) $line['qty'] * -1,
			'payment_reserve:#' . $order->get_id()
		);

		if ( is_wp_error( $result ) ) {
			foreach ( array_reverse( $reserved_lines ) as $reserved_line ) {
				$rollback = papelito_adjust_vendor_stock(
					(int) $reserved_line['vendor_id'],
					(int) $reserved_line['product_id'],
					(int) $reserved_line['qty'],
					'payment_reserve_rollback:#' . $order->get_id()
				);

				if ( is_wp_error( $rollback ) && method_exists( $order, 'add_order_note' ) ) {
					$order->add_order_note( 'Falha ao reverter reserva parcial de estoque: ' . $rollback->get_error_message() );
				}
			}

			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}

			return $result;
		}

		$reserved_lines[] = $line;
	}

	$order->update_meta_data( PAPELITO_STOCK_RESERVED_META, '1' );
	$order->update_meta_data( '_papelito_stock_decremented', '1' );
	$order->save();

	return true;
}

/**
 * Libera a reserva de estoque do pedido.
 *
 * @param array<int,array<string,mixed>> $lines Linhas do pedido.
 * @return true|WP_Error
 */
function papelito_pagarme_release_order_stock( $order, array $lines, string $reason = 'payment_release' ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return new WP_Error( 'papelito_checkout_invalid_order_instance', 'Pedido invalido para liberar estoque.', array( 'status' => 500 ) );
	}

	if ( '1' !== (string) $order->get_meta( PAPELITO_STOCK_RESERVED_META, true ) ) {
		return true;
	}

	foreach ( $lines as $line ) {
		$result = papelito_adjust_vendor_stock(
			(int) $line['vendor_id'],
			(int) $line['product_id'],
			(int) $line['qty'],
			substr( $reason, 0, 80 ) . ':#' . $order->get_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	$order->update_meta_data( PAPELITO_STOCK_RESERVED_META, '0' );
	$order->update_meta_data( '_papelito_stock_decremented', '0' );
	$order->save();

	return true;
}

/**
 * Extrai cobranca principal da resposta do pedido.
 *
 * @param array<string,mixed> $response Resposta Pagar.me.
 * @return array<string,mixed>
 */
function papelito_pagarme_primary_charge( array $response ): array {
	$charges = isset( $response['charges'] ) && is_array( $response['charges'] ) ? $response['charges'] : array();

	return isset( $charges[0] ) && is_array( $charges[0] ) ? $charges[0] : array();
}

/**
 * Estados em que a reserva de estoque deve ser liberada.
 */
function papelito_pagarme_payment_state_releases_stock( string $state ): bool {
	return in_array(
		sanitize_key( $state ),
		array(
			'failed',
			'canceled',
			'cancelled',
			'refused',
			'payment_failed',
			'not_authorized',
			'with_error',
			'voided',
		),
		true
	);
}

/**
 * Estados considerados pagos/capturados.
 */
function papelito_pagarme_payment_state_is_paid( string $state ): bool {
	return in_array( sanitize_key( $state ), array( 'paid', 'captured' ), true );
}

/**
 * Retorna timestamp UTC de uma data ISO/mysql persistida.
 */
function papelito_pagarme_timestamp( string $value ): int {
	if ( '' === $value ) {
		return 0;
	}

	$timestamp = strtotime( $value );

	return false === $timestamp ? 0 : (int) $timestamp;
}

/**
 * Indica se a reserva local ja passou do prazo do meio de pagamento.
 */
function papelito_pagarme_order_reservation_expired( $order ): bool {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return false;
	}

	$method = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ) );
	$now    = time();

	if ( 'pix' === $method ) {
		$expires_at = papelito_pagarme_timestamp( sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_PIX_EXPIRES_AT_META, true ) ) );

		return $expires_at > 0 && ( $expires_at + PAPELITO_PAGARME_PIX_RESERVATION_GRACE ) < $now;
	}

	if ( 'boleto' === $method ) {
		$expires_at = papelito_pagarme_timestamp( sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_BOLETO_EXPIRES_AT_META, true ) ) );

		return $expires_at > 0 && ( $expires_at + PAPELITO_PAGARME_BOLETO_RESERVATION_GRACE ) < $now;
	}

	return false;
}

/**
 * Libera estoque e marca o pedido como falho quando a cobranca nao pode mais ser paga.
 */
function papelito_pagarme_release_order_stock_for_terminal_state( $order, string $reason = 'payment_terminal' ) {
	$lines = function_exists( 'papelito_order_routing_order_lines' )
		? papelito_order_routing_order_lines( $order )
		: array();

	if ( empty( $lines ) ) {
		return true;
	}

	return papelito_pagarme_release_order_stock( $order, $lines, $reason );
}

/**
 * Persiste os metas de pagamento do pedido.
 *
 * @param array<string,mixed> $response Resposta do pedido Pagar.me.
 * @return array<string,mixed>
 */
function papelito_pagarme_store_order_response( $order, array $response, string $method ): array {
	$charge           = papelito_pagarme_primary_charge( $response );
	$last_transaction = isset( $charge['last_transaction'] ) && is_array( $charge['last_transaction'] ) ? $charge['last_transaction'] : array();
	$state            = sanitize_key( (string) ( $charge['status'] ?? $response['status'] ?? 'pending' ) );

	$order->update_meta_data( PAPELITO_PAGARME_ORDER_ID_META, sanitize_text_field( (string) ( $response['id'] ?? '' ) ) );
	$order->update_meta_data( PAPELITO_PAGARME_CHARGE_ID_META, sanitize_text_field( (string) ( $charge['id'] ?? '' ) ) );
	$order->update_meta_data( PAPELITO_PAGARME_PAYMENT_METHOD_META, $method );
	$order->update_meta_data( PAPELITO_PAGARME_PAYMENT_STATE_META, $state );

	if ( 'pix' === $method ) {
		$order->update_meta_data( PAPELITO_PAGARME_PIX_QR_CODE_META, sanitize_text_field( (string) ( $last_transaction['qr_code'] ?? $last_transaction['qr_code_url'] ?? $last_transaction['qr_code_text'] ?? '' ) ) );
		$order->update_meta_data( PAPELITO_PAGARME_PIX_COPY_PASTE_META, sanitize_text_field( (string) ( $last_transaction['qr_code'] ?? $last_transaction['qr_code_text'] ?? $last_transaction['copy_and_paste'] ?? '' ) ) );
		$order->update_meta_data( PAPELITO_PAGARME_PIX_EXPIRES_AT_META, sanitize_text_field( (string) ( $last_transaction['expires_at'] ?? '' ) ) );
	}

	if ( 'boleto' === $method ) {
		$order->update_meta_data( PAPELITO_PAGARME_BOLETO_URL_META, esc_url_raw( (string) ( $last_transaction['url'] ?? '' ) ) );
		$order->update_meta_data( PAPELITO_PAGARME_BOLETO_LINE_META, sanitize_text_field( (string) ( $last_transaction['line'] ?? '' ) ) );
		$order->update_meta_data( PAPELITO_PAGARME_BOLETO_EXPIRES_AT_META, sanitize_text_field( (string) ( $last_transaction['due_at'] ?? $last_transaction['expires_at'] ?? '' ) ) );
	}

	$order->save();

	return papelito_pagarme_order_payment_snapshot( $order );
}

/**
 * Move o pedido conforme o estado conciliado da cobranca.
 */
function papelito_pagarme_apply_order_state( $order, string $state, bool $paid ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'payment_complete' ) ) {
		return;
	}

	$order->update_meta_data( PAPELITO_PAGARME_PAYMENT_STATE_META, $state );

	if ( $paid ) {
		$order->payment_complete();
		$order->update_status( 'processing' );
	} elseif ( papelito_pagarme_payment_state_releases_stock( $state ) ) {
		$order->update_status( 'failed' );
	}

	$order->save();
}

/**
 * Envia o pedido para o Pagar.me.
 *
 * @param array<string,mixed> $payment Payload normalizado do checkout.
 * @param array<string,string> $address Endereco de entrega.
 * @param array<int,array<string,mixed>> $lines Linhas do pedido.
 * @param array<string,mixed> $shipping Frete.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_create_order_payment( $order, int $customer_id, array $payment, array $address, array $lines, array $shipping ) {
	$customer = papelito_pagarme_customer_payload( $customer_id, $address );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}

	$order_total = (int) round( 100 * (float) ( method_exists( $order, 'get_total' ) ? $order->get_total() : 0 ) );
	$split       = papelito_pagarme_vendor_split_payload( (int) $lines[0]['vendor_id'], $order_total );
	if ( is_wp_error( $split ) ) {
		return $split;
	}

	$method        = sanitize_key( (string) $payment['method'] );
	$billing_input = isset( $payment['billing_address'] ) && is_array( $payment['billing_address'] )
		? $payment['billing_address']
		: $address;
	$billing       = papelito_pagarme_billing_address_payload( $billing_input );
	$payments      = array();

	if ( 'credit_card' === $method ) {
		$payments[] = array(
			'payment_method' => 'credit_card',
			'credit_card'    => array(
				'installments'    => max( 1, (int) ( $payment['installments'] ?? 1 ) ),
				'statement_descriptor' => 'PAPELITO',
				'card_token'      => sanitize_text_field( (string) ( $payment['card_token_id'] ?? '' ) ),
				'billing_address' => $billing,
				'operation_type'  => 'auth_and_capture',
			),
		);
	} elseif ( 'pix' === $method ) {
		$payments[] = array(
			'payment_method' => 'pix',
			'pix'            => array(
				'expires_in' => 1800,
			),
		);
	} elseif ( 'boleto' === $method ) {
		$payments[] = array(
			'payment_method' => 'boleto',
			'boleto'         => array(
				'instructions'    => 'Pague ate o vencimento.',
				'document_number' => (string) $order->get_id(),
				'due_at'          => gmdate( 'c', time() + DAY_IN_SECONDS ),
				'type'            => 'DM',
			),
		);
	} else {
		return new WP_Error(
			'papelito_checkout_invalid_payment',
			'Selecione uma forma de pagamento valida.',
			array( 'status' => 422 )
		);
	}

	foreach ( $payments as $index => $payment_payload ) {
		$payments[ $index ]['split'] = $split;
	}

	$request_body = array(
		'code'     => 'woo-order-' . $order->get_id(),
		'customer' => $customer,
		'items'    => papelito_pagarme_order_items_payload( $lines, $shipping ),
		'payments' => $payments,
	);

	$response = papelito_pagarme_request(
		'POST',
		'orders',
		$request_body,
		array(
			'idempotency_key' => papelito_pagarme_order_idempotency_key( $order ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$payment_state = papelito_pagarme_store_order_response( $order, $response, $method );
	$charge        = papelito_pagarme_primary_charge( $response );
	$charge_state  = sanitize_key( (string) ( $charge['status'] ?? $payment_state['state'] ?? '' ) );
	$paid          = papelito_pagarme_payment_state_is_paid( $charge_state );

	if ( $paid ) {
		papelito_pagarme_apply_order_state( $order, $charge_state, true );
	} elseif ( papelito_pagarme_payment_state_releases_stock( $charge_state ) ) {
		papelito_pagarme_apply_order_state( $order, $charge_state, false );
	}

	return array(
		'order_id'     => $order->get_id(),
		'order_number' => $order->get_order_number(),
		'status'       => $order->get_status(),
		'payment'      => $payment_state,
	);
}

/**
 * Obtem o pedido no Pagar.me e atualiza os metas locais.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_reconcile_wc_order( $order ) {
	$pagarme_order_id = sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_ORDER_ID_META, true ) );

	if ( '' === $pagarme_order_id ) {
		return new WP_Error( 'papelito_pagarme_missing_order', 'Pedido Pagar.me nao encontrado.', array( 'status' => 404 ) );
	}

	$response = papelito_pagarme_request( 'GET', 'orders/' . rawurlencode( $pagarme_order_id ) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$payment = papelito_pagarme_store_order_response(
		$order,
		$response,
		sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ) )
	);

	$charge = papelito_pagarme_primary_charge( $response );
	$state  = sanitize_key( (string) ( $charge['status'] ?? $response['status'] ?? '' ) );
	$paid   = papelito_pagarme_payment_state_is_paid( $state );

	papelito_pagarme_apply_order_state( $order, $state, $paid );

	return array(
		'payment'  => $payment,
		'response' => $response,
	);
}

/**
 * Reconcilia pedidos com estoque reservado para evitar reservas indefinidas.
 */
function papelito_pagarme_reconcile_pending_stock_reservations(): void {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}

	$orders = wc_get_orders(
		array(
			'limit'      => 25,
			'orderby'    => 'date',
			'order'      => 'ASC',
			'meta_key'   => PAPELITO_STOCK_RESERVED_META,
			'meta_value' => '1',
			'return'     => 'objects',
		)
	);

	foreach ( $orders as $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'add_order_note' ) ) {
			continue;
		}

		if ( method_exists( $order, 'get_status' ) && in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			continue;
		}

		$state = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ) );

		if ( papelito_pagarme_payment_state_is_paid( $state ) ) {
			continue;
		}

		$reconciled = papelito_pagarme_reconcile_wc_order( $order );

		if ( ! is_wp_error( $reconciled ) ) {
			$state = sanitize_key( (string) ( $reconciled['payment']['state'] ?? $state ) );
		}

		if ( papelito_pagarme_payment_state_is_paid( $state ) ) {
			continue;
		}

		if ( papelito_pagarme_payment_state_releases_stock( $state ) ) {
			$released = papelito_pagarme_release_order_stock_for_terminal_state( $order, 'payment_terminal' );

			if ( is_wp_error( $released ) ) {
				$order->add_order_note( 'Falha ao liberar estoque em reconciliacao Pagar.me: ' . $released->get_error_message() );
				$order->save();
			}

			continue;
		}

		if ( papelito_pagarme_order_reservation_expired( $order ) ) {
			$released = papelito_pagarme_release_order_stock_for_terminal_state( $order, 'payment_expired' );

			if ( is_wp_error( $released ) ) {
				$order->add_order_note( 'Falha ao liberar estoque expirado em reconciliacao Pagar.me: ' . $released->get_error_message() );
				$order->save();
				continue;
			}

			$order->update_meta_data( PAPELITO_PAGARME_PAYMENT_STATE_META, 'expired' );
			$order->update_status( 'failed' );
			$order->add_order_note( 'Reserva de estoque liberada apos expiracao do pagamento Pagar.me.' );
			$order->save();
		}
	}
}
add_action( PAPELITO_PAGARME_RECONCILE_HOOK, 'papelito_pagarme_reconcile_pending_stock_reservations' );

/**
 * Agenda a reconciliacao periodica de pagamentos pendentes.
 */
function papelito_pagarme_schedule_stock_reconciliation(): void {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	if ( ! wp_next_scheduled( PAPELITO_PAGARME_RECONCILE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', PAPELITO_PAGARME_RECONCILE_HOOK );
	}
}
add_action( 'init', 'papelito_pagarme_schedule_stock_reconciliation' );
