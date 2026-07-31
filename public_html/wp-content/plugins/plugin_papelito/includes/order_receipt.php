<?php
/**
 * Recibos internos de pedidos.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

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

function papelito_receipt_money_cents( int $cents ): string {
	return 'R$ ' . number_format( $cents / 100, 2, ',', '.' );
}

/**
 * Data persistida em UTC, apresentada no fuso do site.
 *
 * @param mixed $value Data mysql em UTC.
 */
function papelito_receipt_datetime_label( $value ): string {
	$value = is_string( $value ) ? trim( $value ) : '';

	if ( '' === $value || 0 === strncmp( $value, '0000-00-00', 10 ) ) {
		return '';
	}

	$timestamp = strtotime( $value . ' UTC' );

	return false === $timestamp ? '' : wp_date( 'd/m/Y H:i', $timestamp );
}

function papelito_receipt_cnpj_label( string $cnpj ): string {
	$cnpj = preg_replace( '/[^A-Za-z0-9]/', '', $cnpj ) ?? '';

	if ( 14 !== strlen( $cnpj ) ) {
		return $cnpj;
	}

	return substr( $cnpj, 0, 2 ) . '.' . substr( $cnpj, 2, 3 ) . '.' . substr( $cnpj, 5, 3 ) . '/' . substr( $cnpj, 8, 4 ) . '-' . substr( $cnpj, 12, 2 );
}

/**
 * Situacao do pagamento congelada no recibo.
 */
function papelito_receipt_payment_state_label( string $state ): string {
	$state  = sanitize_key( $state );
	$labels = array(
		'paid'               => 'Pago',
		'captured'           => 'Pago',
		'authorized'         => 'Autorizado',
		'pending'            => 'Aguardando confirmação',
		'processing'         => 'Aguardando confirmação',
		'waiting_payment'    => 'Aguardando confirmação',
		'refunded'           => 'Reembolsado',
		'partially_refunded' => 'Reembolsado parcialmente',
	);

	if ( isset( $labels[ $state ] ) ) {
		return $labels[ $state ];
	}

	if ( function_exists( 'papelito_pagarme_payment_state_releases_stock' ) && papelito_pagarme_payment_state_releases_stock( $state ) ) {
		return 'Não confirmado';
	}

	return '' === $state ? 'Não informado' : 'Em processamento';
}

/**
 * Situacao do pedido: informativa e lida no momento da geracao.
 */
function papelito_receipt_order_status_label( object $order ): string {
	$vendor_status = method_exists( $order, 'get_meta' ) ? sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) ) : '';
	$vendor_labels = array(
		'aguardando_pagamento' => 'Aguardando pagamento',
		'aguardando_envio'     => 'Aguardando envio',
		'em_separacao'         => 'Em separação',
		'enviado'              => 'Enviado',
		'entregue'             => 'Entregue',
		'cancelado'            => 'Cancelado',
	);

	if ( isset( $vendor_labels[ $vendor_status ] ) ) {
		return $vendor_labels[ $vendor_status ];
	}

	$status    = method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '';
	$wc_labels = array(
		'pending'    => 'Aguardando pagamento',
		'processing' => 'Em processamento',
		'on-hold'    => 'Em espera',
		'completed'  => 'Concluído',
		'cancelled'  => 'Cancelado',
		'refunded'   => 'Reembolsado',
		'failed'     => 'Pagamento não concluído',
	);

	return $wc_labels[ $status ] ?? 'Em processamento';
}

/**
 * Recupera o recibo persistido do pedido, emitindo de forma idempotente quando
 * o pedido esta pago mas ainda nao tem linha (janela de deploy, atraso de
 * evento ou historico anterior ao backfill).
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_receipt_record_for_order( object $order ) {
	$order_id = (int) $order->get_id();
	$receipt  = function_exists( 'papelito_receipt_get_by_order' ) ? papelito_receipt_get_by_order( $order_id ) : null;

	if ( ! is_array( $receipt ) && function_exists( 'papelito_receipt_issue_for_order' ) ) {
		$issued  = papelito_receipt_issue_for_order( $order_id );
		$receipt = is_array( $issued ) ? $issued : null;
	}

	if ( ! is_array( $receipt ) || '' === (string) ( $receipt['receipt_number'] ?? '' ) ) {
		return new WP_Error( 'papelito_receipt_unavailable', 'O recibo deste pedido ainda nao esta disponivel.', array( 'status' => 409 ) );
	}

	return $receipt;
}

/**
 * Blocos de itens por vendor: identificacao e totais vem das parcelas, os itens
 * vem do snapshot imutavel.
 *
 * @param array<string,mixed>             $receipt Linha do recibo.
 * @param array<int,array<string,mixed>>  $parts   Parcelas por vendor.
 * @return array<int,array{vendor_name:string,total_cents:int,items:array<int,array<string,mixed>>}>
 */
function papelito_receipt_pdf_vendor_blocks( array $receipt, array $parts ): array {
	$snapshot = json_decode( (string) ( $receipt['snapshot_json'] ?? '' ), true );
	$snapshot = is_array( $snapshot ) ? $snapshot : array();

	$items_by_vendor = array();
	foreach ( (array) ( $snapshot['items'] ?? array() ) as $item ) {
		if ( is_array( $item ) ) {
			$items_by_vendor[ (int) ( $item['vendor_id'] ?? 0 ) ][] = $item;
		}
	}

	$blocks = array();

	foreach ( $parts as $part ) {
		$vendor_id = (int) ( $part['vendor_id'] ?? 0 );
		$items     = $items_by_vendor[ $vendor_id ] ?? null;

		if ( null === $items ) {
			$decoded = json_decode( (string) ( $part['items_json'] ?? '' ), true );
			$items   = is_array( $decoded ) ? $decoded : array();
		}

		$blocks[] = array(
			'vendor_name' => sanitize_text_field( (string) ( $part['vendor_name'] ?? '' ) ),
			'total_cents' => (int) ( $part['total_cents'] ?? 0 ),
			'items'       => $items,
		);
	}

	if ( empty( $blocks ) ) {
		foreach ( (array) ( $snapshot['vendors'] ?? array() ) as $vendor ) {
			if ( is_array( $vendor ) ) {
				$blocks[] = array(
					'vendor_name' => sanitize_text_field( (string) ( $vendor['vendor_name'] ?? '' ) ),
					'total_cents' => (int) ( $vendor['total_cents'] ?? 0 ),
					'items'       => is_array( $vendor['items'] ?? null ) ? $vendor['items'] : array(),
				);
			}
		}
	}

	if ( empty( $blocks ) ) {
		$blocks[] = array(
			'vendor_name' => '',
			'total_cents' => (int) ( $receipt['total_cents'] ?? 0 ),
			'items'       => (array) ( $snapshot['items'] ?? array() ),
		);
	}

	return $blocks;
}

function papelito_receipt_pdf_escape( string $value ): string {
	$value   = wp_strip_all_tags( $value );
	$value   = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $value ) ?? '';
	$encoded = function_exists( 'iconv' ) ? iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $value ) : utf8_decode( $value );
	$encoded = false === $encoded ? $value : $encoded;

	return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $encoded );
}

/**
 * Uma linha do PDF, com fonte e peso.
 *
 * @return array{text:string,size:int,bold:bool}
 */
function papelito_receipt_pdf_line( string $text, int $size = 10, bool $bold = false ): array {
	return array(
		'text' => $text,
		'size' => $size,
		'bold' => $bold,
	);
}

/**
 * Linhas do PDF. Os valores financeiros e os identificadores da compra saem do
 * recibo persistido; do pedido ao vivo vem apenas a situacao operacional.
 *
 * @return array<int,array{text:string,size:int,bold:bool}>|WP_Error
 */
function papelito_receipt_pdf_lines( object $order ) {
	$receipt = papelito_receipt_record_for_order( $order );

	if ( is_wp_error( $receipt ) ) {
		return $receipt;
	}

	$parts    = function_exists( 'papelito_receipt_vendor_parts' ) ? papelito_receipt_vendor_parts( (int) $receipt['id'] ) : array();
	$blocks   = papelito_receipt_pdf_vendor_blocks( $receipt, $parts );
	$snapshot = json_decode( (string) ( $receipt['snapshot_json'] ?? '' ), true );
	$snapshot = is_array( $snapshot ) ? $snapshot : array();

	$number       = sanitize_text_field( (string) $receipt['receipt_number'] );
	$order_number = sanitize_text_field( (string) ( $snapshot['order']['number'] ?? $receipt['order_id'] ) );
	$ordered_at   = papelito_receipt_datetime_label( $receipt['ordered_at'] ?? '' );
	$paid_at      = papelito_receipt_datetime_label( $receipt['paid_at'] ?? '' );
	$buyer_label  = sanitize_text_field( (string) $receipt['buyer_label'] );
	$cnpj         = papelito_receipt_cnpj_label( (string) ( $receipt['company_cnpj'] ?? '' ) );
	$method_title = sanitize_text_field( (string) $receipt['payment_method_title'] );

	if ( '' === $method_title && function_exists( 'papelito_order_routing_payment_method_label' ) ) {
		$method_title = papelito_order_routing_payment_method_label( (string) $receipt['payment_method'] );
	}

	$lines   = array();
	$lines[] = papelito_receipt_pdf_line( 'Recibo de pedido', 18, true );
	$lines[] = papelito_receipt_pdf_line( 'Recibo ' . $number, 12, true );
	$lines[] = papelito_receipt_pdf_line( 'Pedido #' . $order_number, 11, true );

	if ( '' !== $ordered_at ) {
		$lines[] = papelito_receipt_pdf_line( 'Data da compra: ' . $ordered_at );
	}
	if ( '' !== $paid_at ) {
		$lines[] = papelito_receipt_pdf_line( 'Data do pagamento: ' . $paid_at );
	}

	$lines[] = papelito_receipt_pdf_line( '', 8 );
	$lines[] = papelito_receipt_pdf_line( 'Comprador: ' . ( '' !== $buyer_label ? $buyer_label : 'Nao informado' ) );

	if ( '' !== $cnpj ) {
		$lines[] = papelito_receipt_pdf_line( 'CNPJ: ' . $cnpj );
	}

	$company_legal_name = sanitize_text_field( (string) ( $receipt['company_legal_name'] ?? '' ) );
	if ( '' !== $company_legal_name && $company_legal_name !== $buyer_label ) {
		$lines[] = papelito_receipt_pdf_line( 'Razão social: ' . $company_legal_name );
	}

	if ( 1 === count( $blocks ) ) {
		$lines[] = papelito_receipt_pdf_line( 'Vendor: ' . ( '' !== $blocks[0]['vendor_name'] ? $blocks[0]['vendor_name'] : 'Papelito' ) );
	}

	$lines[] = papelito_receipt_pdf_line( 'Pagamento: ' . ( '' !== $method_title ? $method_title : 'Nao informado' ) );
	$lines[] = papelito_receipt_pdf_line( 'Situação do pagamento: ' . papelito_receipt_payment_state_label( (string) $receipt['payment_state'] ) );
	$lines[] = papelito_receipt_pdf_line( 'Situação do pedido: ' . papelito_receipt_order_status_label( $order ) );
	$lines[] = papelito_receipt_pdf_line( '', 8 );
	$lines[] = papelito_receipt_pdf_line( 'Itens do pedido', 12, true );

	$multivendor = count( $blocks ) > 1;

	foreach ( $blocks as $block ) {
		if ( $multivendor ) {
			$lines[] = papelito_receipt_pdf_line( 'Vendor: ' . ( '' !== $block['vendor_name'] ? $block['vendor_name'] : 'Papelito' ), 10, true );
		}

		foreach ( $block['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_text = sprintf(
				'%dx %s — %s',
				max( 1, (int) ( $item['quantity'] ?? 1 ) ),
				sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				papelito_receipt_money_cents( (int) ( $item['total_cents'] ?? 0 ) )
			);

			foreach ( explode( "\n", wordwrap( $item_text, 82, "\n", true ) ) as $item_line ) {
				$lines[] = papelito_receipt_pdf_line( $item_line, 9 );
			}
		}

		if ( $multivendor ) {
			$lines[] = papelito_receipt_pdf_line( 'Total do vendor: ' . papelito_receipt_money_cents( $block['total_cents'] ), 9 );
			$lines[] = papelito_receipt_pdf_line( '', 8 );
		}
	}

	$lines[] = papelito_receipt_pdf_line( '', 8 );
	$lines[] = papelito_receipt_pdf_line( 'Subtotal: ' . papelito_receipt_money_cents( (int) $receipt['subtotal_cents'] ) );

	if ( (int) $receipt['discount_cents'] > 0 ) {
		$lines[] = papelito_receipt_pdf_line( 'Descontos: -' . papelito_receipt_money_cents( (int) $receipt['discount_cents'] ) );
	}

	$lines[] = papelito_receipt_pdf_line( 'Frete: ' . papelito_receipt_money_cents( (int) $receipt['shipping_cents'] ) );
	$lines[] = papelito_receipt_pdf_line( 'Total pago: ' . papelito_receipt_money_cents( (int) $receipt['total_cents'] ), 12, true );
	$lines[] = papelito_receipt_pdf_line( '', 8 );
	$lines[] = papelito_receipt_pdf_line( 'Emitido por Papelito', 10, true );
	$lines[] = papelito_receipt_pdf_line( 'Recibo ' . $number . ' · PDF gerado em ' . wp_date( 'd/m/Y H:i', time() ), 8 );

	return $lines;
}

/**
 * Serializa as linhas do recibo em um PDF autocontido.
 *
 * @return string|WP_Error
 */
function papelito_receipt_pdf( object $order ) {
	$lines = papelito_receipt_pdf_lines( $order );

	if ( is_wp_error( $lines ) ) {
		return $lines;
	}

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

/**
 * Resposta de download do recibo, com os headers de arquivo privado.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_receipt_download_response( object $order ) {
	$pdf = papelito_receipt_pdf( $order );

	if ( is_wp_error( $pdf ) ) {
		return $pdf;
	}

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

	$pdf = papelito_receipt_pdf( $order );
	if ( is_wp_error( $pdf ) ) {
		return $pdf;
	}

	$rate_limit = papelito_receipt_claim_email_attempt( (int) $order->get_id(), get_current_user_id() );
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$temp_file = wp_tempnam( 'papelito-receipt-' . absint( $order->get_id() ) );
	if ( ! is_string( $temp_file ) || '' === $temp_file || false === file_put_contents( $temp_file, $pdf, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
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
