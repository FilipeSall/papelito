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
	define( 'PAPELITO_PAGARME_PIX_QR_CODE_URL_META', '_papelito_pagarme_pix_qr_code_url' );
	define( 'PAPELITO_PAGARME_PIX_EXPIRES_AT_META', '_papelito_pagarme_pix_expires_at' );
	define( 'PAPELITO_PAGARME_BOLETO_URL_META', '_papelito_pagarme_boleto_url' );
	define( 'PAPELITO_PAGARME_BOLETO_LINE_META', '_papelito_pagarme_boleto_line' );
	define( 'PAPELITO_PAGARME_BOLETO_EXPIRES_AT_META', '_papelito_pagarme_boleto_expires_at' );
	define( 'PAPELITO_PAGARME_LAST_RECONCILE_META', '_papelito_pagarme_last_reconcile_at' );
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
function papelito_pagarme_order_payment_snapshot( object $order ): array {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return array(
			'method' => '',
			'state'  => '',
		);
	}

	$method = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ) );
	$state  = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ) );
	$pix_qr_code     = sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_PIX_QR_CODE_META, true ) );
	$pix_copy_paste  = sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_PIX_COPY_PASTE_META, true ) );
	$pix_qr_code_url = esc_url_raw( (string) $order->get_meta( PAPELITO_PAGARME_PIX_QR_CODE_URL_META, true ) );

	if ( '' === $pix_qr_code_url && papelito_pagarme_is_url( $pix_qr_code ) ) {
		$pix_qr_code_url = esc_url_raw( $pix_qr_code );
		$pix_qr_code     = '';
	}

	if ( '' === $pix_qr_code_url && papelito_pagarme_is_url( $pix_copy_paste ) ) {
		$pix_qr_code_url = esc_url_raw( $pix_copy_paste );
		$pix_copy_paste  = '';
	}

	if ( '' === $pix_copy_paste && '' !== $pix_qr_code ) {
		$pix_copy_paste = $pix_qr_code;
	}

	if ( '' === $pix_qr_code && '' !== $pix_copy_paste ) {
		$pix_qr_code = $pix_copy_paste;
	}

	$pix    = array_filter(
		array(
			'qr_code'     => $pix_qr_code,
			'qr_code_url' => $pix_qr_code_url,
			'copy_paste'  => $pix_copy_paste,
			'expires_at'  => (string) $order->get_meta( PAPELITO_PAGARME_PIX_EXPIRES_AT_META, true ),
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
 * Indica se um valor recebido do PSP e uma URL, nao um payload EMV Pix.
 */
function papelito_pagarme_is_url( string $value ): bool {
	return 1 === preg_match( '#^https?://#i', trim( $value ) );
}

/**
 * Escolhe o payload Pix copia-e-cola entre os formatos retornados pelo PSP.
 *
 * @param array<string,mixed> $last_transaction Ultima transacao da cobranca.
 */
function papelito_pagarme_pix_copy_paste_from_transaction( array $last_transaction ): string {
	foreach ( array( 'qr_code', 'qr_code_text', 'copy_paste', 'copy_and_paste', 'emv', 'emv_code' ) as $key ) {
		$value = sanitize_text_field( (string) ( $last_transaction[ $key ] ?? '' ) );

		if ( '' !== $value && ! papelito_pagarme_is_url( $value ) ) {
			return $value;
		}
	}

	return '';
}

/**
 * Escolhe a URL de QR Pix sem confundir com o payload copia-e-cola.
 *
 * @param array<string,mixed> $last_transaction Ultima transacao da cobranca.
 */
function papelito_pagarme_pix_qr_code_url_from_transaction( array $last_transaction ): string {
	foreach ( array( 'qr_code_url', 'qr_code_image_url', 'url', 'qr_code' ) as $key ) {
		$value = esc_url_raw( (string) ( $last_transaction[ $key ] ?? '' ) );

		if ( '' !== $value && papelito_pagarme_is_url( $value ) ) {
			return $value;
		}
	}

	return '';
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
		$total_cents  = isset( $line['total_cents'] )
			? max( 0, (int) $line['total_cents'] )
			: (int) round( 100 * (float) $line['total'] );
		$product_code = 'product-' . (int) $line['product_id'];
		$name         = is_object( $line['product'] ) && method_exists( $line['product'], 'get_name' )
			? sanitize_text_field( (string) $line['product']->get_name() )
			: 'Produto Papelito';

		if ( $total_cents <= 0 ) {
			continue;
		}

		$items[] = array(
			'amount'      => $total_cents,
			'description' => $qty > 1 ? sprintf( '%s x%d', $name, $qty ) : $name,
			'quantity'    => 1,
			'code'        => $product_code,
		);
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
 * Soma o valor financeiro representado pelos itens da API.
 */
function papelito_pagarme_items_total_cents( array $items ): int {
	$total = 0;
	foreach ( $items as $item ) {
		$total += max( 0, (int) ( $item['amount'] ?? 0 ) ) * max( 1, (int) ( $item['quantity'] ?? 1 ) );
	}
	return $total;
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

/** @return array<string,mixed>|WP_Error */
function papelito_pagarme_b2b_customer_payload_from_order( object $order ) {
	$company_id = (int) $order->get_meta( '_papelito_company_id', true );
	$name = sanitize_text_field( (string) $order->get_meta( '_papelito_company_legal_name', true ) );
	$email = sanitize_email( (string) $order->get_meta( '_papelito_company_billing_email', true ) );
	$cnpj = sanitize_text_field( (string) $order->get_meta( '_papelito_company_cnpj', true ) );
	$phone = sanitize_text_field( (string) $order->get_meta( '_papelito_company_phone', true ) );
	$code = sanitize_text_field( (string) $order->get_meta( '_papelito_company_pagarme_customer_code', true ) );
	if ( '' === $code && $company_id > 0 ) {
		$code = 'papelito-company-' . $company_id;
	}
	$length = static fn( string $value ): int => function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	if ( $company_id <= 0 || '' === $name || ! is_email( $email ) || $length( $name ) > 64 || $length( $email ) > 64 || $length( $code ) > 52 || strlen( $cnpj ) > 16 ) {
		return new WP_Error( 'papelito_b2b_pagarme_customer_invalid', 'Os dados fiscais da empresa não atendem aos limites do pagamento.', array( 'status' => 422 ) );
	}
	if ( function_exists( 'papelito_cnpj_is_alphanumeric' ) && papelito_cnpj_is_alphanumeric( $cnpj ) && ! papelito_b2b_flag( 'PAPELITO_ALPHANUMERIC_CNPJ_PAYMENT_ENABLED' ) ) {
		return new WP_Error( 'papelito_b2b_cnpj_alphanumeric_payment_unsupported', 'O pagamento para CNPJ alfanumérico ainda não está disponível.', array( 'status' => 422 ) );
	}
	$address = array();
	foreach ( array( 'cep', 'state', 'city', 'neighborhood', 'street', 'number', 'complement' ) as $field ) {
		$address[ $field ] = sanitize_text_field( (string) $order->get_meta( '_papelito_fiscal_' . $field, true ) );
		if ( '' === $address[ $field ] ) {
			$address[ $field ] = sanitize_text_field( (string) $order->get_meta( '_papelito_company_fiscal_' . $field, true ) );
		}
	}
	foreach ( array( 'cep', 'state', 'city', 'neighborhood', 'street', 'number' ) as $field ) {
		if ( '' === $address[ $field ] ) {
			return new WP_Error( 'papelito_b2b_fiscal_address_incomplete', 'O endereço fiscal da empresa está incompleto.', array( 'status' => 422 ) );
		}
	}
	$normalized_phone = function_exists( 'papelito_auth_normalize_phone' ) ? papelito_auth_normalize_phone( $phone ) : preg_replace( '/\\D+/', '', $phone );
	if ( ! is_string( $normalized_phone ) || ! in_array( strlen( $normalized_phone ), array( 10, 11 ), true ) ) {
		return new WP_Error( 'papelito_b2b_business_phone_invalid', 'O telefone empresarial é inválido para pagamento.', array( 'status' => 422 ) );
	}
	return array(
		'name' => $name,
		'email' => $email,
		'code' => $code,
		'document_type' => 'CNPJ',
		'document' => $cnpj,
		'type' => 'company',
		'phones' => array( 'mobile_phone' => papelito_pagarme_phone_payload( $phone ) ),
		'address' => papelito_pagarme_address_payload( $address ),
	);
}

/**
 * Monta o payload do cliente.
 *
 * @param array<string,string> $address Endereco.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_customer_payload( int $user_id, array $address ) {
	if ( '1' === (string) get_user_meta( $user_id, 'papelito_b2b_required', true ) ) {
		$context = function_exists( 'papelito_company_context' ) ? papelito_company_context( $user_id ) : array();
		if ( empty( $context['canPurchase'] ) || empty( $context['companyId'] ) ) {
			return new WP_Error( 'papelito_b2b_purchase_not_allowed', 'Empresa não autorizada para pagamento.', array( 'status' => 403 ) );
		}
		$company = papelito_company_get( (int) $context['companyId'] );
		if ( ! $company || 'active' !== $company['registry_status'] ) {
			return new WP_Error( 'papelito_b2b_company_unavailable', 'Empresa não disponível para pagamento.', array( 'status' => 422 ) );
		}
		return array(
			'name'          => (string) $company['legal_name'],
			'email'         => (string) $company['billing_email'],
			'code'          => 'papelito-company-' . (int) $company['id'],
			'document_type' => 'CNPJ',
			'document'      => (string) $company['cnpj'],
			'type'          => 'company',
			'phones'        => array( 'mobile_phone' => papelito_pagarme_phone_payload( (string) $company['phone'] ) ),
			'address'       => papelito_pagarme_billing_address_payload( $address ),
		);
	}
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
			'Informe um CPF ou CNPJ válido no seu perfil para concluir o pagamento.',
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
 * Valida se o vendor possui recebedor ativo.
 *
 * @return true|WP_Error
 */
function papelito_pagarme_validate_vendor_recipient( int $vendor_id ) {
	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $vendor_id );

	if ( '' === $recipient_id || ! papelito_pagarme_vendor_recipient_is_active( $vendor_id ) ) {
		return new WP_Error(
			'papelito_checkout_vendor_not_approved',
			'O vendor selecionado não esta apto para receber pagamentos.',
			array( 'status' => 422 )
		);
	}

	return true;
}

/**
 * Retorna o split unico para o vendor.
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_pagarme_vendor_split_payload( int $vendor_id, int $amount ) {
	$validation = papelito_pagarme_validate_vendor_recipient( $vendor_id );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $vendor_id );

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
function papelito_pagarme_reserve_order_stock( object $order, array $lines ) {
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
function papelito_pagarme_release_order_stock( object $order, array $lines, string $reason = 'payment_release' ) {
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
			'expired',
			'payment_expired',
			'checkout_expired',
			'abandoned',
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
function papelito_pagarme_order_reservation_expired( object $order ): bool {
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
function papelito_pagarme_release_order_stock_for_terminal_state( object $order, string $reason = 'payment_terminal' ) {
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
function papelito_pagarme_store_order_response( object $order, array $response, string $method ): array {
	$charge           = papelito_pagarme_primary_charge( $response );
	$last_transaction = isset( $charge['last_transaction'] ) && is_array( $charge['last_transaction'] ) ? $charge['last_transaction'] : array();
	$state            = sanitize_key( (string) ( $charge['status'] ?? $response['status'] ?? 'pending' ) );

	$order->update_meta_data( PAPELITO_PAGARME_ORDER_ID_META, sanitize_text_field( (string) ( $response['id'] ?? '' ) ) );
	$order->update_meta_data( PAPELITO_PAGARME_CHARGE_ID_META, sanitize_text_field( (string) ( $charge['id'] ?? '' ) ) );
	$order->update_meta_data( PAPELITO_PAGARME_PAYMENT_METHOD_META, $method );
	$order->update_meta_data( PAPELITO_PAGARME_PAYMENT_STATE_META, $state );

	if ( 'pix' === $method ) {
		$copy_paste  = papelito_pagarme_pix_copy_paste_from_transaction( $last_transaction );
		$qr_code_url = papelito_pagarme_pix_qr_code_url_from_transaction( $last_transaction );

		$order->update_meta_data( PAPELITO_PAGARME_PIX_QR_CODE_META, $copy_paste );
		$order->update_meta_data( PAPELITO_PAGARME_PIX_COPY_PASTE_META, $copy_paste );
		$order->update_meta_data( PAPELITO_PAGARME_PIX_QR_CODE_URL_META, $qr_code_url );
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
 * Indica se o pedido ja esta pago em WooCommerce ou no estado Pagar.me local.
 */
function papelito_pagarme_order_has_paid_status( object $order ): bool {
	if ( ! is_object( $order ) ) {
		return false;
	}

	if ( method_exists( $order, 'get_status' ) ) {
		$wc_status = sanitize_key( (string) $order->get_status() );

		if ( in_array( $wc_status, array( 'processing', 'completed' ), true ) ) {
			return true;
		}
	}

	if ( method_exists( $order, 'get_meta' ) ) {
		$payment_state = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ) );

		if ( papelito_pagarme_payment_state_is_paid( $payment_state ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Move o pedido conforme o estado conciliado da cobranca.
 */
function papelito_pagarme_apply_order_state( object $order, string $state, bool $paid ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'payment_complete' ) ) {
		return;
	}

	$order->update_meta_data( PAPELITO_PAGARME_PAYMENT_STATE_META, $state );

	if ( $paid ) {
		$order->payment_complete();
		$order->update_status( 'processing' );
		papelito_pagarme_promote_vendor_status_on_payment( $order );
	} elseif ( papelito_pagarme_payment_state_releases_stock( $state ) ) {
		$order->update_status( 'failed' );
		papelito_pagarme_mark_vendor_status_unpaid( $order );
	}

	$order->save();

	// Só depois de a persistência do estado pago concluir. Reentrante por desenho:
	// webhook repetido reemite o evento e os consumidores precisam ser idempotentes.
	if ( $paid && papelito_pagarme_payment_state_is_paid( $state ) ) {
		do_action( 'papelito_order_payment_confirmed', $order, $state );
	}
}

/**
 * Avanca o status operacional do vendor de "aguardando pagamento" para
 * "aguardando envio" quando o pagamento e confirmado. Idempotente: nao
 * regride pedidos que ja avancaram na esteira de fulfillment.
 */
function papelito_pagarme_promote_vendor_status_on_payment( object $order ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! defined( 'PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT' ) ) {
		return;
	}

	$current = sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) );

	if ( '' === $current || PAPELITO_VENDOR_STATUS_AWAITING_PAYMENT === $current ) {
		$order->update_meta_data( '_papelito_vendor_status', PAPELITO_VENDOR_STATUS_AWAITING_SHIPMENT );
		$order->add_order_note( 'Pagamento confirmado: pedido liberado para envio.' );

		if ( function_exists( 'papelito_orders_notify_vendor_new_purchase' ) ) {
			papelito_orders_notify_vendor_new_purchase( $order );
		}
	}
}

/**
 * Marca o status operacional do vendor como cancelado quando o pagamento
 * entra em estado terminal sem confirmacao, evitando que o pedido conte
 * como venda. Nao mexe em pedidos ja enviados/entregues.
 */
function papelito_pagarme_mark_vendor_status_unpaid( object $order ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return;
	}

	if ( papelito_pagarme_order_has_paid_status( $order ) ) {
		return;
	}

	$current          = sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) );
	$cancelled_status = defined( 'PAPELITO_VENDOR_STATUS_CANCELLED' ) ? PAPELITO_VENDOR_STATUS_CANCELLED : 'cancelado';

	$final_vendor_statuses = array( 'enviado', 'entregue' );

	if ( defined( 'PAPELITO_VENDOR_STATUS_SHIPPED' ) ) {
		$final_vendor_statuses[] = PAPELITO_VENDOR_STATUS_SHIPPED;
	}

	if ( defined( 'PAPELITO_VENDOR_STATUS_DELIVERED' ) ) {
		$final_vendor_statuses[] = PAPELITO_VENDOR_STATUS_DELIVERED;
	}

	if ( in_array( $current, $final_vendor_statuses, true ) ) {
		return;
	}

	if ( $cancelled_status !== $current ) {
		$order->update_meta_data( '_papelito_vendor_status', $cancelled_status );

		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'Pagamento não concluido: pedido cancelado.' );
		}
	}
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
function papelito_pagarme_create_order_payment( object $order, int $customer_id, array $payment, array $address, array $lines, array $shipping ) {
	$customer = '1' === (string) $order->get_meta( '_papelito_company_snapshot_version', true ) || '1' === (string) $order->get_meta( '_papelito_b2b_snapshot_version', true )
		? papelito_pagarme_b2b_customer_payload_from_order( $order )
		: papelito_pagarme_customer_payload( $customer_id, $address );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}

	$wc_order_total = (int) round( 100 * (float) ( method_exists( $order, 'get_total' ) ? $order->get_total() : 0 ) );
	$order_total    = method_exists( $order, 'get_meta' )
		? max( 0, (int) $order->get_meta( '_papelito_authoritative_total_cents', true ) )
		: 0;
	if ( $order_total <= 0 ) {
		$order_total = $wc_order_total;
	}

	if ( abs( $wc_order_total - $order_total ) > 1 ) {
		return new WP_Error(
			'papelito_checkout_total_mismatch',
			'Os valores do pedido ficaram inconsistentes antes do pagamento. Atualize o carrinho e tente novamente.',
			array(
				'status'              => 409,
				'order_cents'         => $order_total,
				'woocommerce_cents'   => $wc_order_total,
			)
		);
	}

	$method      = sanitize_key( (string) $payment['method'] );
	$minimum     = function_exists( 'papelito_pricing_validate_payment_amount' )
		? papelito_pricing_validate_payment_amount( $method, $order_total, (int) ( $payment['installments'] ?? 1 ) )
		: true;
	if ( is_wp_error( $minimum ) ) {
		return $minimum;
	}

	$split       = papelito_pagarme_vendor_split_payload( (int) $lines[0]['vendor_id'], $order_total );
	if ( is_wp_error( $split ) ) {
		return $split;
	}

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
				'instructions'    => 'Pague até o vencimento.',
				'document_number' => (string) $order->get_id(),
				'due_at'          => gmdate( 'c', time() + DAY_IN_SECONDS ),
				'type'            => 'DM',
			),
		);
	} else {
		return new WP_Error(
			'papelito_checkout_invalid_payment',
			'Selecione uma forma de pagamento válida.',
			array( 'status' => 422 )
		);
	}

	foreach ( $payments as $index => $payment_payload ) {
		$payments[ $index ]['split'] = $split;
	}

	$items       = papelito_pagarme_order_items_payload( $lines, $shipping );
	$items_total = papelito_pagarme_items_total_cents( $items );
	$split_total = array_sum( array_map( static fn( array $entry ): int => (int) ( $entry['amount'] ?? 0 ), $split ) );
	if ( empty( $items ) || $items_total !== $order_total || $split_total !== $order_total ) {
		return new WP_Error(
			'papelito_checkout_total_mismatch',
			'Os valores do pedido ficaram inconsistentes antes do pagamento. Atualize o carrinho e tente novamente.',
			array(
				'status'          => 409,
				'items_cents'     => $items_total,
				'order_cents'     => $order_total,
				'split_cents'     => $split_total,
			)
		);
	}

	$request_body = array(
		'code'     => 'woo-order-' . $order->get_id(),
		'customer' => $customer,
		'items'    => $items,
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
function papelito_pagarme_reconcile_wc_order( object $order ) {
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
 * Atualiza dados pendentes do pagamento exibido no checkout, com throttle.
 */
function papelito_pagarme_maybe_reconcile_checkout_order( object $order ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) ) {
		return null;
	}

	$pagarme_order_id = sanitize_text_field( (string) $order->get_meta( PAPELITO_PAGARME_ORDER_ID_META, true ) );
	$method           = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_METHOD_META, true ) );
	$state            = sanitize_key( (string) $order->get_meta( PAPELITO_PAGARME_PAYMENT_STATE_META, true ) );

	if ( '' === $pagarme_order_id || ! in_array( $method, array( 'pix', 'boleto' ), true ) ) {
		return null;
	}

	if ( papelito_pagarme_payment_state_is_paid( $state ) || papelito_pagarme_payment_state_releases_stock( $state ) ) {
		return null;
	}

	$last_reconcile = (int) $order->get_meta( PAPELITO_PAGARME_LAST_RECONCILE_META, true );
	if ( $last_reconcile > 0 && ( time() - $last_reconcile ) < 10 ) {
		return null;
	}

	$order->update_meta_data( PAPELITO_PAGARME_LAST_RECONCILE_META, (string) time() );
	$order->save();

	return papelito_pagarme_reconcile_wc_order( $order );
}

/**
 * Reconcilia pedidos com estoque reservado para evitar reservas indefinidas.
 */
function papelito_pagarme_reconcile_terminal_unpaid_orders(): void {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}

	foreach ( array( 'failed', 'cancelled' ) as $status ) {
		$orders = wc_get_orders(
			array(
				'limit'   => 25,
				'orderby' => 'date',
				'order'   => 'ASC',
				'status'  => $status,
				'return'  => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
				continue;
			}

			if ( papelito_pagarme_order_has_paid_status( $order ) ) {
				continue;
			}

			$released = papelito_pagarme_release_order_stock_for_terminal_state( $order, 'payment_terminal' );

			if ( is_wp_error( $released ) && method_exists( $order, 'add_order_note' ) ) {
				$order->add_order_note( 'Falha ao liberar estoque em reconciliacao terminal Pagar.me: ' . $released->get_error_message() );
			}

			papelito_pagarme_mark_vendor_status_unpaid( $order );

			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		}
	}
}

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
			papelito_pagarme_mark_vendor_status_unpaid( $order );
			$order->add_order_note( 'Reserva de estoque liberada após expiracao do pagamento Pagar.me.' );
			$order->save();
		}
	}

	papelito_pagarme_reconcile_terminal_unpaid_orders();
}
add_action( PAPELITO_PAGARME_RECONCILE_HOOK, 'papelito_pagarme_reconcile_pending_stock_reservations' );

function papelito_pagarme_maybe_reconcile_unpaid_orders_for_request(): void {
	static $did_reconcile = false;

	if ( $did_reconcile ) {
		return;
	}

	$did_reconcile = true;

	if ( function_exists( 'get_transient' ) && get_transient( 'papelito_pagarme_lazy_reconcile_lock' ) ) {
		return;
	}

	if ( function_exists( 'set_transient' ) ) {
		set_transient( 'papelito_pagarme_lazy_reconcile_lock', '1', MINUTE_IN_SECONDS );
	}

	papelito_pagarme_reconcile_pending_stock_reservations();
}

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
