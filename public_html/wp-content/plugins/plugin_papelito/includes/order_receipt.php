<?php
/**
 * Recibos internos de pedidos.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * TODO: substituir ou complementar este recibo interno por documentos fiscais
 * autorizados quando a integracao fiscal da Papelito for implantada.
 */

function papelito_receipt_order_for_current_user( int $order_id ) {
	$order = papelito_vendor_dashboard_customer_order( $order_id, get_current_user_id() );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$payment_state = function_exists( 'papelito_pagarme_order_payment_snapshot' )
		? sanitize_key( (string) ( papelito_pagarme_order_payment_snapshot( $order )['state'] ?? '' ) )
		: '';

	if ( ! function_exists( 'papelito_pagarme_payment_state_is_paid' ) || ! papelito_pagarme_payment_state_is_paid( $payment_state ) ) {
		return new WP_Error( 'papelito_receipt_payment_not_confirmed', 'O recibo fica disponivel apos a confirmacao do pagamento.', array( 'status' => 409 ) );
	}

	return $order;
}

function papelito_receipt_money( float $value ): string {
	return 'R$ ' . number_format( $value, 2, ',', '.' );
}

function papelito_receipt_pdf_escape( string $value ): string {
	$value   = wp_strip_all_tags( $value );
	$value   = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $value ) ?? '';
	$encoded = function_exists( 'iconv' ) ? iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $value ) : utf8_decode( $value );
	$encoded = false === $encoded ? $value : $encoded;

	return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $encoded );
}

/**
 * @return array<int,array{text:string,size:int,bold:bool}>
 */
function papelito_receipt_pdf_lines( object $order ): array {
	$vendor_name  = sanitize_text_field( (string) $order->get_meta( '_papelito_vendor_name', true ) );
	$buyer_name   = sanitize_text_field( (string) $order->get_formatted_billing_full_name() );
	$company_name = sanitize_text_field( (string) $order->get_billing_company() );
	$paid_at      = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
	$lines        = array(
		array( 'text' => 'Recibo de pedido', 'size' => 18, 'bold' => true ),
		array( 'text' => 'Pedido #' . sanitize_text_field( (string) $order->get_order_number() ), 'size' => 11, 'bold' => true ),
		array( 'text' => 'Data do pagamento: ' . ( $paid_at ? wp_date( 'd/m/Y H:i', $paid_at->getTimestamp() ) : '' ), 'size' => 10, 'bold' => false ),
		array( 'text' => '', 'size' => 8, 'bold' => false ),
		array( 'text' => 'Comprador: ' . ( '' !== $company_name ? $company_name : $buyer_name ), 'size' => 10, 'bold' => false ),
		array( 'text' => 'Vendor: ' . ( '' !== $vendor_name ? $vendor_name : 'Papelito' ), 'size' => 10, 'bold' => false ),
		array( 'text' => 'Pagamento: ' . sanitize_text_field( (string) $order->get_payment_method_title() ), 'size' => 10, 'bold' => false ),
		array( 'text' => '', 'size' => 8, 'bold' => false ),
		array( 'text' => 'Itens do pedido', 'size' => 12, 'bold' => true ),
	);

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
			continue;
		}

		$item_text = sprintf(
			'%dx %s — %s',
			max( 1, (int) $item->get_quantity() ),
			sanitize_text_field( (string) $item->get_name() ),
			papelito_receipt_money( (float) $item->get_total() )
		);
		foreach ( explode( "\n", wordwrap( $item_text, 82, "\n", true ) ) as $item_line ) {
			$lines[] = array( 'text' => $item_line, 'size' => 9, 'bold' => false );
		}
	}

	$lines[] = array( 'text' => '', 'size' => 8, 'bold' => false );
	$lines[] = array( 'text' => 'Subtotal: ' . papelito_receipt_money( (float) $order->get_subtotal() ), 'size' => 10, 'bold' => false );
	$lines[] = array( 'text' => 'Frete: ' . papelito_receipt_money( (float) $order->get_shipping_total() ), 'size' => 10, 'bold' => false );
	if ( (float) $order->get_discount_total() > 0 ) {
		$lines[] = array( 'text' => 'Descontos: -' . papelito_receipt_money( (float) $order->get_discount_total() ), 'size' => 10, 'bold' => false );
	}
	$lines[] = array( 'text' => 'Total pago: ' . papelito_receipt_money( (float) $order->get_total() ), 'size' => 12, 'bold' => true );

	return $lines;
}

function papelito_receipt_pdf( object $order ): string {
	$lines         = papelito_receipt_pdf_lines( $order );
	$pages         = array_chunk( $lines, 48 );
	$objects       = array();
	$page_references = array();
	$objects[1]    = '<< /Type /Catalog /Pages 2 0 R >>';
	$objects[3]    = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
	$objects[4]    = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

	foreach ( $pages as $index => $page_lines ) {
		$content_id = 5 + ( $index * 2 );
		$page_id    = $content_id + 1;
		$y          = 800;
		$commands   = array( 'BT' );

		foreach ( $page_lines as $line ) {
			$font     = ! empty( $line['bold'] ) ? 'F2' : 'F1';
			$size     = max( 8, (int) $line['size'] );
			$commands[] = sprintf( '/%s %d Tf 1 0 0 1 42 %d Tm (%s) Tj', $font, $size, $y, papelito_receipt_pdf_escape( (string) $line['text'] ) );
			$y       -= max( 14, $size + 4 );
		}

		$commands[]          = 'ET';
		$content             = implode( "\n", $commands );
		$objects[ $content_id ] = "<< /Length " . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		$objects[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
		$page_references[]   = $page_id . ' 0 R';
	}

	$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $page_references ) . '] /Count ' . count( $page_references ) . ' >>';
	ksort( $objects );

	$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
	$offsets = array( 0 );
	foreach ( $objects as $id => $object ) {
		$offsets[ $id ] = strlen( $pdf );
		$pdf            .= $id . " 0 obj\n" . $object . "\nendobj\n";
	}

	$xref_offset = strlen( $pdf );
	$pdf        .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( array_keys( $objects ) as $id ) {
		$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
	}
	$pdf .= 'trailer' . "\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

	return $pdf;
}

function papelito_receipt_email_recipient( object $order ) {
	$is_b2b = '' !== (string) $order->get_meta( '_papelito_company_snapshot_version', true );

	if ( $is_b2b ) {
		$company_id     = (int) $order->get_meta( '_papelito_company_id', true );
		$snapshot_email = sanitize_email( (string) $order->get_meta( '_papelito_company_billing_email', true ) );
		$company        = function_exists( 'papelito_company_get' ) ? papelito_company_get( $company_id ) : null;
		$current_email  = is_array( $company ) ? sanitize_email( (string) ( $company['billing_email'] ?? '' ) ) : '';
		$verified_at    = is_array( $company ) ? (string) ( $company['billing_email_verified_at'] ?? '' ) : '';

		if ( '' === $snapshot_email || '' === $current_email || '' === $verified_at || ! hash_equals( strtolower( $snapshot_email ), strtolower( $current_email ) ) ) {
			return new WP_Error( 'papelito_receipt_email_unavailable', 'Nao ha e-mail verificado para o envio.', array( 'status' => 422 ) );
		}

		return $snapshot_email;
	}

	$user = get_userdata( (int) $order->get_customer_id() );
	if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) || ( function_exists( 'papelito_auth_requires_email_verification' ) && papelito_auth_requires_email_verification( $user->ID ) ) ) {
		return new WP_Error( 'papelito_receipt_email_unavailable', 'Nao ha e-mail verificado para o envio.', array( 'status' => 422 ) );
	}

	return sanitize_email( $user->user_email );
}

function papelito_receipt_claim_email_attempt( int $order_id, int $user_id ) {
	$key      = 'papelito_receipt_email_' . md5( $order_id . ':' . $user_id );
	$now      = time();
	$attempts = get_transient( $key );
	$attempts = is_array( $attempts ) ? array_values( array_filter( $attempts, static fn( $timestamp ): bool => is_numeric( $timestamp ) && (int) $timestamp > $now - HOUR_IN_SECONDS ) ) : array();

	if ( count( $attempts ) >= 3 ) {
		return new WP_Error( 'papelito_receipt_email_rate_limited', 'Aguarde antes de solicitar outro envio.', array( 'status' => 429 ) );
	}

	$attempts[] = $now;
	set_transient( $key, $attempts, HOUR_IN_SECONDS );

	return true;
}

function papelito_receipt_download_response( object $order ): WP_REST_Response {
	$pdf      = papelito_receipt_pdf( $order );
	$filename = 'recibo-pedido-' . absint( $order->get_id() ) . '.pdf';
	$response = new WP_REST_Response( $pdf, 200 );
	$response->header( 'Content-Type', 'application/pdf' );
	$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	$response->header( 'X-Papelito-Receipt', '1' );

	return $response;
}

function papelito_receipt_send_email( object $order ) {
	$recipient = papelito_receipt_email_recipient( $order );
	if ( is_wp_error( $recipient ) ) {
		return $recipient;
	}

	$rate_limit = papelito_receipt_claim_email_attempt( (int) $order->get_id(), get_current_user_id() );
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$temp_file = wp_tempnam( 'papelito-receipt-' . absint( $order->get_id() ) );
	if ( ! is_string( $temp_file ) || '' === $temp_file || false === file_put_contents( $temp_file, papelito_receipt_pdf( $order ), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return new WP_Error( 'papelito_receipt_email_attachment_failed', 'Nao foi possivel preparar o recibo.', array( 'status' => 500 ) );
	}

	$sent = wp_mail(
		$recipient,
		sprintf( 'Recibo do pedido #%s', sanitize_text_field( (string) $order->get_order_number() ) ),
		"Ola,\n\nSegue em anexo o recibo do seu pedido.\n",
		array( 'Content-Type: text/plain; charset=UTF-8' ),
		array( $temp_file )
	);
	wp_delete_file( $temp_file );

	if ( ! $sent ) {
		return new WP_Error( 'papelito_receipt_email_failed', 'Nao foi possivel enviar o recibo agora.', array( 'status' => 502 ) );
	}

	return true;
}

add_filter(
	'rest_pre_serve_request',
	static function ( bool $served, $result ): bool {
		$headers = $result instanceof WP_REST_Response ? $result->get_headers() : array();
		if ( $served || ! $result instanceof WP_REST_Response || '1' !== ( $headers['X-Papelito-Receipt'] ?? '' ) ) {
			return $served;
		}

		foreach ( $headers as $name => $value ) {
			if ( 'X-Papelito-Receipt' !== $name ) {
				header( $name . ': ' . $value );
			}
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF interno autenticado.
		return true;
	},
	20,
	2
);

add_action(
	'rest_api_init',
	static function (): void {
		$permission = static function () {
			$check = papelito_vendor_dashboard_require_profile_user();
			return is_wp_error( $check ) ? $check : true;
		};

		register_rest_route(
			'papelito/v1',
			'/profile/me/orders/(?P<id>\d+)/receipt',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = papelito_receipt_order_for_current_user( absint( $request->get_param( 'id' ) ) );
					return is_wp_error( $order ) ? $order : papelito_receipt_download_response( $order );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/profile/me/orders/(?P<id>\d+)/receipt/email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $permission,
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = papelito_receipt_order_for_current_user( absint( $request->get_param( 'id' ) ) );
					if ( is_wp_error( $order ) ) {
						return $order;
					}

					$sent = papelito_receipt_send_email( $order );
					return is_wp_error( $sent ) ? $sent : new WP_REST_Response( array( 'ok' => true ), 200 );
				},
			)
		);
	}
);
