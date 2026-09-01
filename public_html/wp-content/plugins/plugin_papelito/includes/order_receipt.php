<?php
/**
 * Recibos internos de pedidos.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_RECEIPT_LABEL_AWAITING_CONFIRMATION = 'Aguardando confirmação';
const PAPELITO_RECEIPT_LABEL_NOT_INFORMED          = 'Não informado';
const PAPELITO_RECEIPT_LABEL_PROCESSING            = 'Em processamento';

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
		'pending'            => PAPELITO_RECEIPT_LABEL_AWAITING_CONFIRMATION,
		'processing'         => PAPELITO_RECEIPT_LABEL_AWAITING_CONFIRMATION,
		'waiting_payment'    => PAPELITO_RECEIPT_LABEL_AWAITING_CONFIRMATION,
		'refunded'           => 'Reembolsado',
		'partially_refunded' => 'Reembolsado parcialmente',
	);

	if ( isset( $labels[ $state ] ) ) {
		return $labels[ $state ];
	}

	if ( function_exists( 'papelito_pagarme_payment_state_releases_stock' ) && papelito_pagarme_payment_state_releases_stock( $state ) ) {
		return 'Não confirmado';
	}

	return '' === $state ? PAPELITO_RECEIPT_LABEL_NOT_INFORMED : PAPELITO_RECEIPT_LABEL_PROCESSING;
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
		'processing' => PAPELITO_RECEIPT_LABEL_PROCESSING,
		'on-hold'    => 'Em espera',
		'completed'  => 'Concluído',
		'cancelled'  => 'Cancelado',
		'refunded'   => 'Reembolsado',
		'failed'     => 'Pagamento não concluído',
	);

	return $wc_labels[ $status ] ?? PAPELITO_RECEIPT_LABEL_PROCESSING;
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
 * Resumo do recibo para o payload de detalhe do pedido do comprador.
 *
 * Nao emite nada: so informa o que ja existe e se o download esta liberado. O
 * numero fica nulo enquanto o recibo nao foi emitido — a emissao acontece na
 * confirmacao do pagamento, no backfill ou na propria geracao do PDF.
 *
 * @return array{number:string|null,available:bool,issued_at:string|null}
 */
function papelito_receipt_public_summary( object $order ): array {
	$payment_state = function_exists( 'papelito_pagarme_order_payment_snapshot' )
		? sanitize_key( (string) ( papelito_pagarme_order_payment_snapshot( $order )['state'] ?? '' ) )
		: '';

	$available = function_exists( 'papelito_pagarme_payment_state_is_paid' )
		&& papelito_pagarme_payment_state_is_paid( $payment_state );

	$receipt = function_exists( 'papelito_receipt_get_by_order' )
		? papelito_receipt_get_by_order( (int) $order->get_id() )
		: null;

	$number    = is_array( $receipt ) ? sanitize_text_field( (string) ( $receipt['receipt_number'] ?? '' ) ) : '';
	$issued_at = is_array( $receipt ) ? papelito_receipt_datetime_label( $receipt['issued_at'] ?? '' ) : '';

	return array(
		'number'    => '' !== $number ? $number : null,
		'available' => $available,
		'issued_at' => '' !== $issued_at ? $issued_at : null,
	);
}

/**
 * Itens do snapshot agrupados por vendor.
 *
 * @param array<string,mixed> $snapshot Snapshot do recibo.
 * @return array<int,array<int,array<string,mixed>>>
 */
function papelito_receipt_pdf_items_by_vendor( array $snapshot ): array {
	$items_by_vendor = array();

	foreach ( (array) ( $snapshot['items'] ?? array() ) as $item ) {
		if ( is_array( $item ) ) {
			$items_by_vendor[ (int) ( $item['vendor_id'] ?? 0 ) ][] = $item;
		}
	}

	return $items_by_vendor;
}

/**
 * Blocos vindos das parcelas por vendor; o items_json da parcela e a reserva
 * quando o snapshot nao tem itens daquele vendor.
 *
 * @param array<int,array<string,mixed>>            $parts           Parcelas por vendor.
 * @param array<int,array<int,array<string,mixed>>> $items_by_vendor Itens agrupados.
 * @return array<int,array<string,mixed>>
 */
function papelito_receipt_pdf_blocks_from_parts( array $parts, array $items_by_vendor ): array {
	$blocks = array();

	foreach ( $parts as $part ) {
		$items = $items_by_vendor[ (int) ( $part['vendor_id'] ?? 0 ) ] ?? null;

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

	return $blocks;
}

/**
 * Blocos reconstruidos do proprio snapshot, quando nao ha parcelas por vendor.
 *
 * @param array<string,mixed> $snapshot Snapshot do recibo.
 * @return array<int,array<string,mixed>>
 */
function papelito_receipt_pdf_blocks_from_snapshot( array $snapshot ): array {
	$blocks = array();

	foreach ( (array) ( $snapshot['vendors'] ?? array() ) as $vendor ) {
		if ( is_array( $vendor ) ) {
			$blocks[] = array(
				'vendor_name' => sanitize_text_field( (string) ( $vendor['vendor_name'] ?? '' ) ),
				'total_cents' => (int) ( $vendor['total_cents'] ?? 0 ),
				'items'       => is_array( $vendor['items'] ?? null ) ? $vendor['items'] : array(),
			);
		}
	}

	return $blocks;
}

/**
 * Blocos de itens por vendor: identificacao e totais vem das parcelas, os itens
 * vem do snapshot imutavel.
 *
 * @param array<string,mixed>            $receipt Linha do recibo.
 * @param array<int,array<string,mixed>> $parts   Parcelas por vendor.
 * @return array<int,array{vendor_name:string,total_cents:int,items:array<int,array<string,mixed>>}>
 */
function papelito_receipt_pdf_vendor_blocks( array $receipt, array $parts ): array {
	$snapshot = json_decode( (string) ( $receipt['snapshot_json'] ?? '' ), true );
	$snapshot = is_array( $snapshot ) ? $snapshot : array();

	$blocks = papelito_receipt_pdf_blocks_from_parts( $parts, papelito_receipt_pdf_items_by_vendor( $snapshot ) );

	if ( empty( $blocks ) ) {
		$blocks = papelito_receipt_pdf_blocks_from_snapshot( $snapshot );
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
 * Normaliza um item do snapshot para a linha da tabela do recibo. O valor
 * unitario so aparece quando existe no snapshot ou pode ser derivado dos
 * valores reais da linha.
 *
 * @param array<string,mixed> $item Item do snapshot.
 * @return array<string,mixed>
 */
function papelito_receipt_document_item( array $item ): array {
	$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
	$subtotal = (int) ( $item['subtotal_cents'] ?? 0 );
	$total    = (int) ( $item['total_cents'] ?? 0 );
	$unit     = (int) ( $item['unit_price_cents'] ?? 0 );

	if ( $unit <= 0 ) {
		$base = $subtotal > 0 ? $subtotal : $total;
		$unit = $base > 0 ? (int) round( $base / $quantity ) : 0;
	}

	return array(
		'name'             => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
		'quantity'         => $quantity,
		'unit_price_cents' => $unit,
		'discount_cents'   => max( 0, (int) ( $item['discount_cents'] ?? 0 ) ),
		'total_cents'      => $total,
	);
}

/**
 * Blocos de vendor do documento, ja rotulados, com a marca de que alguma linha
 * teve desconto.
 *
 * @param array<int,array<string,mixed>> $blocks Blocos crus por vendor.
 * @return array{blocks:array<int,array<string,mixed>>,has_discount:bool}
 */
function papelito_receipt_document_vendor_blocks( array $blocks ): array {
	$vendor_blocks = array();
	$has_discount  = false;

	foreach ( $blocks as $block ) {
		$items = array();

		foreach ( $block['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$line    = papelito_receipt_document_item( $item );
			$items[] = $line;

			if ( $line['discount_cents'] > 0 ) {
				$has_discount = true;
			}
		}

		$vendor_blocks[] = array(
			'vendor_name' => '' !== $block['vendor_name'] ? sanitize_text_field( (string) $block['vendor_name'] ) : 'Papelito',
			'total_cents' => (int) $block['total_cents'],
			'items'       => $items,
		);
	}

	return array(
		'blocks'       => $vendor_blocks,
		'has_discount' => $has_discount,
	);
}

/**
 * Documento do recibo: tudo que o PDF imprime, ja rotulado e formatado.
 *
 * Os valores financeiros e os identificadores da compra saem do recibo
 * persistido; do pedido ao vivo vem apenas a situacao operacional.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_receipt_document( object $order ) {
	$receipt = papelito_receipt_record_for_order( $order );

	if ( is_wp_error( $receipt ) ) {
		return $receipt;
	}

	$parts    = function_exists( 'papelito_receipt_vendor_parts' ) ? papelito_receipt_vendor_parts( (int) $receipt['id'] ) : array();
	$blocks   = papelito_receipt_pdf_vendor_blocks( $receipt, $parts );
	$snapshot = json_decode( (string) ( $receipt['snapshot_json'] ?? '' ), true );
	$snapshot = is_array( $snapshot ) ? $snapshot : array();

	$method_title = sanitize_text_field( (string) $receipt['payment_method_title'] );

	if ( '' === $method_title && function_exists( 'papelito_order_routing_payment_method_label' ) ) {
		$method_title = papelito_order_routing_payment_method_label( (string) $receipt['payment_method'] );
	}

	$buyer_label        = sanitize_text_field( (string) $receipt['buyer_label'] );
	$company_legal_name = sanitize_text_field( (string) ( $receipt['company_legal_name'] ?? '' ) );

	$formatted     = papelito_receipt_document_vendor_blocks( $blocks );
	$vendor_blocks = $formatted['blocks'];
	$has_discount  = (int) $receipt['discount_cents'] > 0 || $formatted['has_discount'];

	return array(
		'receipt_number' => sanitize_text_field( (string) $receipt['receipt_number'] ),
		'order_number'   => sanitize_text_field( (string) ( $snapshot['order']['number'] ?? $receipt['order_id'] ) ),
		'issued_at'      => papelito_receipt_datetime_label( $receipt['issued_at'] ?? '' ),
		'generated_at'   => wp_date( 'd/m/Y H:i', time() ),
		'buyer'          => array(
			'label'      => '' !== $buyer_label ? $buyer_label : PAPELITO_RECEIPT_LABEL_NOT_INFORMED,
			'legal_name' => $company_legal_name,
			'cnpj'       => papelito_receipt_cnpj_label( (string) ( $receipt['company_cnpj'] ?? '' ) ),
		),
		'order'          => array(
			'ordered_at'     => papelito_receipt_datetime_label( $receipt['ordered_at'] ?? '' ),
			'paid_at'        => papelito_receipt_datetime_label( $receipt['paid_at'] ?? '' ),
			'payment_method' => '' !== $method_title ? $method_title : PAPELITO_RECEIPT_LABEL_NOT_INFORMED,
			'payment_state'  => papelito_receipt_payment_state_label( (string) $receipt['payment_state'] ),
			'order_status'   => papelito_receipt_order_status_label( $order ),
			'vendor'         => count( $vendor_blocks ) === 1 ? $vendor_blocks[0]['vendor_name'] : 'Vários vendors',
		),
		'blocks'         => $vendor_blocks,
		'multivendor'    => count( $vendor_blocks ) > 1,
		'has_discount'   => $has_discount,
		'totals'         => array(
			'subtotal_cents' => (int) $receipt['subtotal_cents'],
			'discount_cents' => (int) $receipt['discount_cents'],
			'shipping_cents' => (int) $receipt['shipping_cents'],
			'total_cents'    => (int) $receipt['total_cents'],
		),
	);
}
/**
 * Largura de um caractere Windows-1252 em milesimos de em, na Helvetica.
 *
 * Acentuadas nao aparecem na tabela: a Helvetica da a elas a largura da letra
 * base, entao o mapa de fallback resolve por transliteracao.
 *
 * @return array<int,array<string,int>>
 */
function papelito_receipt_pdf_font_widths(): array {
	static $widths = null;

	if ( null === $widths ) {
		$widths = array(
			'regular' => papelito_receipt_pdf_font_widths_regular(),
			'bold'    => papelito_receipt_pdf_font_widths_bold(),
		);
	}

	return $widths;
}

/**
 * Larguras da Helvetica regular, em milesimos de em.
 *
 * @return array<string,int>
 */
function papelito_receipt_pdf_font_widths_regular(): array {
	return array_combine(
		str_split( ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~' ),
		array(
			278,
			278,
			355,
			556,
			556,
			889,
			667,
			191,
			333,
			333,
			389,
			584,
			278,
			333,
			278,
			278,
			556,
			556,
			556,
			556,
			556,
			556,
			556,
			556,
			556,
			556,
			278,
			278,
			584,
			584,
			584,
			556,
			1015,
			667,
			667,
			722,
			722,
			667,
			611,
			778,
			722,
			278,
			500,
			667,
			556,
			833,
			722,
			778,
			667,
			778,
			722,
			667,
			611,
			722,
			667,
			944,
			667,
			667,
			611,
			278,
			278,
			278,
			469,
			556,
			333,
			556,
			556,
			500,
			556,
			556,
			278,
			556,
			556,
			222,
			222,
			500,
			222,
			833,
			556,
			556,
			556,
			556,
			333,
			500,
			278,
			556,
			500,
			722,
			500,
			500,
			500,
			334,
			260,
			334,
			584,
		)
	);
}

/**
 * Larguras da Helvetica bold, em milesimos de em.
 *
 * @return array<string,int>
 */
function papelito_receipt_pdf_font_widths_bold(): array {
	return array_combine(
		str_split( ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~' ),
		array(
			278,
			333,
			474,
			556,
			556,
			889,
			722,
			238,
			333,
			333,
			389,
			584,
			278,
			333,
			278,
			278,
			556,
			556,
			556,
			556,
			556,
			556,
			556,
			556,
			556,
			556,
			333,
			333,
			584,
			584,
			584,
			611,
			975,
			722,
			722,
			722,
			722,
			667,
			611,
			778,
			722,
			278,
			556,
			722,
			611,
			833,
			722,
			778,
			667,
			778,
			722,
			667,
			611,
			722,
			667,
			944,
			667,
			667,
			611,
			333,
			278,
			333,
			584,
			556,
			333,
			556,
			611,
			556,
			611,
			556,
			333,
			611,
			611,
			278,
			278,
			556,
			278,
			889,
			611,
			611,
			611,
			611,
			389,
			556,
			333,
			611,
			556,
			778,
			556,
			556,
			500,
			389,
			280,
			389,
			584,
		)
	);
}

/**
 * Largura do texto ja convertido para Windows-1252, em pontos.
 */
function papelito_receipt_pdf_text_width( string $encoded, float $size, bool $bold = false, float $char_space = 0.0 ): float {
	$table    = papelito_receipt_pdf_font_widths()[ $bold ? 'bold' : 'regular' ];
	$fallback = array(
		"\x80" => 556,
		"\x91" => 191,
		"\x92" => 191,
		"\x93" => 333,
		"\x94" => 333,
		"\x95" => 350,
		"\x96" => 556,
		"\x97" => 1000,
		"\xA0" => 278,
		"\xAA" => 370,
		"\xB0" => 400,
		"\xBA" => 365,
		"\xB7" => 278,
	);
	$latin    = array(
		'A' => "\xC0\xC1\xC2\xC3\xC4\xC5",
		'C' => "\xC7",
		'E' => "\xC8\xC9\xCA\xCB",
		'I' => "\xCC\xCD\xCE\xCF",
		'N' => "\xD1",
		'O' => "\xD2\xD3\xD4\xD5\xD6\xD8",
		'U' => "\xD9\xDA\xDB\xDC",
		'Y' => "\xDD",
		'a' => "\xE0\xE1\xE2\xE3\xE4\xE5",
		'c' => "\xE7",
		'e' => "\xE8\xE9\xEA\xEB",
		'i' => "\xEC\xED\xEE\xEF",
		'n' => "\xF1",
		'o' => "\xF2\xF3\xF4\xF5\xF6\xF8",
		'u' => "\xF9\xFA\xFB\xFC",
		'y' => "\xFD\xFF",
	);

	$total  = 0.0;
	$length = strlen( $encoded );

	for ( $index = 0; $index < $length; $index++ ) {
		$char  = $encoded[ $index ];
		$units = $table[ $char ] ?? $fallback[ $char ] ?? null;

		if ( null === $units ) {
			foreach ( $latin as $base => $accented ) {
				if ( false !== strpos( $accented, $char ) ) {
					$units = $table[ $base ];
					break;
				}
			}
		}

		$total += ( $units ?? 556 ) / 1000 * $size;
	}

	return $total + ( $length > 1 ? ( $length - 1 ) * $char_space : 0.0 );
}

/**
 * Fatia uma palavra larga demais para caber sozinha na linha.
 *
 * @return array{lines:array<int,string>,rest:string} Fatias cheias e o resto.
 */
function papelito_receipt_pdf_split_long_word( string $word, float $max_width, float $size, bool $bold ): array {
	$lines       = array();
	$word_length = strlen( $word );

	while ( $word_length > 1 && papelito_receipt_pdf_text_width( $word, $size, $bold ) > $max_width ) {
		$cut = $word_length;

		while ( $cut > 1 && papelito_receipt_pdf_text_width( substr( $word, 0, $cut ), $size, $bold ) > $max_width ) {
			--$cut;
		}

		$lines[] = substr( $word, 0, $cut );
		$word    = (string) substr( $word, $cut );
	}

	return array(
		'lines' => $lines,
		'rest'  => $word,
	);
}

/**
 * Quebra o texto na largura disponivel, preservando palavras e cortando
 * palavras longas demais para caber sozinhas.
 *
 * @return array<int,string> Trechos ja escapados para o PDF.
 */
function papelito_receipt_pdf_wrap( string $text, float $max_width, float $size, bool $bold = false ): array {
	$encoded = papelito_receipt_pdf_escape( $text );

	if ( '' === $encoded ) {
		return array( '' );
	}

	$lines   = array();
	$current = '';

	foreach ( explode( ' ', $encoded ) as $word ) {
		$candidate = '' === $current ? $word : $current . ' ' . $word;

		if ( papelito_receipt_pdf_text_width( $candidate, $size, $bold ) <= $max_width ) {
			$current = $candidate;
			continue;
		}

		if ( '' !== $current ) {
			$lines[] = $current;
			$current = '';
		}

		$split   = papelito_receipt_pdf_split_long_word( $word, $max_width, $size, $bold );
		$lines   = array_merge( $lines, $split['lines'] );
		$current = $split['rest'];
	}

	if ( '' !== $current ) {
		$lines[] = $current;
	}

	return empty( $lines ) ? array( '' ) : $lines;
}

/**
 * Paleta do documento, em componentes RGB de 0 a 1.
 *
 * @return array<string,array<int,float>>
 */
function papelito_receipt_pdf_palette(): array {
	return array(
		'ink'   => array( 0.137, 0.122, 0.125 ),
		'tape'  => array( 1.0, 0.898, 0.0 ),
		'paper' => array( 1.0, 1.0, 1.0 ),
		'kraft' => array( 0.980, 0.973, 0.949 ),
		'rule'  => array( 0.839, 0.847, 0.859 ),
		'label' => array( 0.365, 0.373, 0.400 ),
		'white' => array( 1.0, 1.0, 1.0 ),
	);
}

/**
 * Operador de cor do content stream, de preenchimento ou de traco.
 *
 * @return string Operadores do content stream.
 */
function papelito_receipt_pdf_color( string $name, bool $stroke = false ): string {
	$rgb = papelito_receipt_pdf_palette()[ $name ] ?? papelito_receipt_pdf_palette()['ink'];

	return sprintf( '%.3F %.3F %.3F %s', $rgb[0], $rgb[1], $rgb[2], $stroke ? 'RG' : 'rg' );
}

/**
 * Retangulo preenchido.
 *
 * @return string Operadores do content stream.
 */
function papelito_receipt_pdf_rect( float $x, float $y, float $width, float $height, string $color ): string {
	return sprintf(
		'q %s %.2F %.2F %.2F %.2F re f Q',
		papelito_receipt_pdf_color( $color ),
		$x,
		$y,
		$width,
		$height
	);
}

/**
 * Retangulo apenas contornado.
 */
function papelito_receipt_pdf_frame( float $x, float $y, float $width, float $height, string $color, float $line_width = 0.7 ): string {
	return sprintf(
		'q %s %.2F w %.2F %.2F %.2F %.2F re S Q',
		papelito_receipt_pdf_color( $color, true ),
		$line_width,
		$x,
		$y,
		$width,
		$height
	);
}

/**
 * Fio horizontal, usado como separador de linha e de secao.
 *
 * @return string Operadores do content stream.
 */
function papelito_receipt_pdf_hline( float $x, float $y, float $width, string $color, float $line_width = 0.7 ): string {
	return sprintf(
		'q %s %.2F w %.2F %.2F m %.2F %.2F l S Q',
		papelito_receipt_pdf_color( $color, true ),
		$line_width,
		$x,
		$y,
		$x + $width,
		$y
	);
}

/**
 * Losango da marca — o mesmo marcador de secao usado na interface.
 */
function papelito_receipt_pdf_diamond( float $cx, float $cy, float $radius, string $color ): string {
	return sprintf(
		'q %s %.2F %.2F m %.2F %.2F l %.2F %.2F l %.2F %.2F l h f Q',
		papelito_receipt_pdf_color( $color ),
		$cx,
		$cy + $radius,
		$cx + $radius,
		$cy,
		$cx,
		$cy - $radius,
		$cx - $radius,
		$cy
	);
}

/**
 * Texto posicionado. `align` aceita left, center e right; `x` e a borda do
 * alinhamento. As opcoes aceitam tamanho, peso, cor, alinhamento, espacamento
 * entre letras e `encoded`, que pula a conversao para Windows-1252.
 *
 * @param string              $text    Texto a desenhar.
 * @param float               $x       Coordenada horizontal, na borda do alinhamento.
 * @param float               $y       Linha de base do texto.
 * @param array<string,mixed> $options Opcoes de desenho.
 * @return string Operadores do content stream.
 */
function papelito_receipt_pdf_text( string $text, float $x, float $y, array $options = array() ): string {
	$size       = (float) ( $options['size'] ?? 9 );
	$bold       = (bool) ( $options['bold'] ?? false );
	$color      = (string) ( $options['color'] ?? 'ink' );
	$align      = (string) ( $options['align'] ?? 'left' );
	$char_space = (float) ( $options['tracking'] ?? 0 );
	$encoded    = isset( $options['encoded'] ) ? $text : papelito_receipt_pdf_escape( $text );

	if ( '' === $encoded ) {
		return '';
	}

	if ( 'right' === $align ) {
		$x -= papelito_receipt_pdf_text_width( $encoded, $size, $bold, $char_space );
	} elseif ( 'center' === $align ) {
		$x -= papelito_receipt_pdf_text_width( $encoded, $size, $bold, $char_space ) / 2;
	}

	return sprintf(
		'BT %s /%s %.2F Tf %.2F Tc 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
		papelito_receipt_pdf_color( $color ),
		$bold ? 'F2' : 'F1',
		$size,
		$char_space,
		$x,
		$y,
		$encoded
	);
}

const PAPELITO_RECEIPT_PDF_LEFT        = 48.0;
const PAPELITO_RECEIPT_PDF_WIDTH       = 499.0;
const PAPELITO_RECEIPT_PDF_TOP         = 794.0;
const PAPELITO_RECEIPT_PDF_CONTENT_END = 126.0;

/**
 * Rotulo curto acima do valor, no idioma dos blocos do documento. Valores
 * longos quebram ate `$max_lines` e sao cortados com reticencias.
 *
 * @return array{ops:array<int,string>,height:float}
 */
function papelito_receipt_pdf_field( string $label, string $value, float $x, float $y, float $max_width, int $max_lines = 1 ): array {
	$lines = papelito_receipt_pdf_wrap( '' !== trim( $value ) ? $value : '-', $max_width, 9.0 );

	if ( count( $lines ) > $max_lines ) {
		$last        = $lines[ $max_lines - 1 ];
		$limit       = $max_width - papelito_receipt_pdf_text_width( '...', 9.0 );
		$last_length = strlen( $last );

		while ( $last_length > 1 && papelito_receipt_pdf_text_width( $last, 9.0 ) > $limit ) {
			$last = (string) substr( $last, 0, -1 );
			--$last_length;
		}

		$lines                   = array_slice( $lines, 0, $max_lines );
		$lines[ $max_lines - 1 ] = rtrim( $last ) . '...';
	}

	$ops = array(
		papelito_receipt_pdf_text(
			$label,
			$x,
			$y,
			array(
				'size'     => 6.2,
				'bold'     => true,
				'color'    => 'label',
				'tracking' => 0.9,
			)
		),
	);

	foreach ( $lines as $index => $line ) {
		$ops[] = papelito_receipt_pdf_text(
			$line,
			$x,
			$y - 13 - ( 11 * $index ),
			array(
				'size'    => 9.0,
				'encoded' => true,
			)
		);
	}

	return array(
		'ops'    => $ops,
		'height' => 13.0 + ( 11.0 * ( count( $lines ) - 1 ) ),
	);
}

/**
 * Faixa de titulo de um bloco, com o losango da marca.
 *
 * @return array<int,string>
 */
function papelito_receipt_pdf_block_header( string $title, float $x, float $top, float $width ): array {
	return array(
		papelito_receipt_pdf_rect( $x, $top - 16, $width, 16, 'kraft' ),
		papelito_receipt_pdf_diamond( $x + 11, $top - 8, 3.2, 'tape' ),
		papelito_receipt_pdf_text(
			$title,
			$x + 20,
			$top - 11,
			array(
				'size'     => 6.6,
				'bold'     => true,
				'tracking' => 1.3,
			)
		),
	);
}

/**
 * Cabecalho da pagina. A primeira traz a marca em tamanho cheio; as seguintes
 * repetem uma faixa curta com o numero do recibo, para a folha solta continuar
 * identificavel.
 *
 * @param array<string,mixed> $doc Documento do recibo.
 * @return array{ops:array<int,string>,y:float}
 */
function papelito_receipt_pdf_page_header( array $doc, bool $continuation ): array {
	$left  = PAPELITO_RECEIPT_PDF_LEFT;
	$width = PAPELITO_RECEIPT_PDF_WIDTH;
	$right = $left + $width;

	if ( $continuation ) {
		$top = PAPELITO_RECEIPT_PDF_TOP - 32;

		return array(
			'ops' => array(
				papelito_receipt_pdf_rect( $left, $top, $width, 32, 'ink' ),
				papelito_receipt_pdf_text(
					'PAPELITO',
					$left + 18,
					$top + 11,
					array(
						'size'     => 12,
						'bold'     => true,
						'color'    => 'white',
						'tracking' => 1.8,
					)
				),
				papelito_receipt_pdf_text(
					'Recibo ' . $doc['receipt_number'] . ' · continuação',
					$right - 18,
					$top + 11,
					array(
						'size'  => 7.5,
						'color' => 'rule',
						'align' => 'right',
					)
				),
				papelito_receipt_pdf_rect( $left, $top - 3, $width, 3, 'tape' ),
			),
			'y'   => $top - 19,
		);
	}

	$top = PAPELITO_RECEIPT_PDF_TOP - 64;

	return array(
		'ops' => array(
			papelito_receipt_pdf_rect( $left, $top, $width, 64, 'ink' ),
			papelito_receipt_pdf_text(
				'PAPELITO',
				$left + 18,
				$top + 24,
				array(
					'size'     => 21,
					'bold'     => true,
					'color'    => 'white',
					'tracking' => 2.2,
				)
			),
			papelito_receipt_pdf_text(
				'RECIBO DE PEDIDO',
				$right - 18,
				$top + 25,
				array(
					'size'     => 12,
					'bold'     => true,
					'color'    => 'tape',
					'align'    => 'right',
					'tracking' => 1.4,
				)
			),
			papelito_receipt_pdf_rect( $left, $top - 4, $width, 4, 'tape' ),
		),
		'y'   => $top - 18,
	);
}

/**
 * Faixa de identificacao do documento: numero do recibo, pedido e emissao.
 *
 * @param array<string,mixed> $doc Documento do recibo.
 * @return array<int,string>
 */
function papelito_receipt_pdf_identification( array $doc, float $top ): array {
	$left  = PAPELITO_RECEIPT_PDF_LEFT;
	$width = PAPELITO_RECEIPT_PDF_WIDTH;
	$cell  = $width / 3;
	$cells = array(
		array( 'NÚMERO DO RECIBO', $doc['receipt_number'] ),
		array( 'PEDIDO', '#' . $doc['order_number'] ),
		'' !== $doc['issued_at']
			? array( 'EMISSÃO DO RECIBO', $doc['issued_at'] )
			: array( 'GERADO EM', $doc['generated_at'] ),
	);

	$ops = array(
		papelito_receipt_pdf_rect( $left, $top - 52, $width, 52, 'kraft' ),
		papelito_receipt_pdf_frame( $left, $top - 52, $width, 52, 'rule' ),
	);

	foreach ( $cells as $index => $content ) {
		$x = $left + ( $cell * $index );

		if ( $index > 0 ) {
			$ops[] = sprintf(
				'q %s 0.7 w %.2F %.2F m %.2F %.2F l S Q',
				papelito_receipt_pdf_color( 'rule', true ),
				$x,
				$top - 44,
				$x,
				$top - 8
			);
		}

		$ops[] = papelito_receipt_pdf_text(
			$content[0],
			$x + 16,
			$top - 19,
			array(
				'size'     => 6.2,
				'bold'     => true,
				'color'    => 'label',
				'tracking' => 0.9,
			)
		);
		$ops[] = papelito_receipt_pdf_text(
			$content[1],
			$x + 16,
			$top - 38,
			array(
				'size' => 13,
				'bold' => true,
			)
		);
	}

	return $ops;
}

/**
 * Bloco do comprador. A altura acompanha o nome, que pode ocupar duas linhas.
 *
 * @param array<string,mixed> $doc Documento do recibo.
 * @return array{ops:array<int,string>,y:float}
 */
function papelito_receipt_pdf_buyer_block( array $doc, float $top ): array {
	$left       = PAPELITO_RECEIPT_PDF_LEFT;
	$width      = PAPELITO_RECEIPT_PDF_WIDTH;
	$cnpj       = (string) $doc['buyer']['cnpj'];
	$legal_name = (string) $doc['buyer']['legal_name'];
	$name       = '' !== $legal_name ? $legal_name : (string) $doc['buyer']['label'];
	$has_person = '' !== $legal_name && $legal_name !== $doc['buyer']['label'];

	$name_width = '' !== $cnpj ? $width * 0.62 - 28 : $width - 28;
	$name_field = papelito_receipt_pdf_field( '' !== $cnpj ? 'RAZÃO SOCIAL' : 'NOME', $name, $left + 14, $top - 30, $name_width, 2 );

	$height = 30.0 + $name_field['height'] + 10.0 + ( $has_person ? 14.0 : 0.0 );
	$ops    = papelito_receipt_pdf_block_header( 'COMPRADOR', $left, $top, $width );
	$ops[]  = papelito_receipt_pdf_frame( $left, $top - $height, $width, $height, 'rule' );
	$ops    = array_merge( $ops, $name_field['ops'] );

	if ( '' !== $cnpj ) {
		$cnpj_field = papelito_receipt_pdf_field( 'CNPJ', $cnpj, $left + $width * 0.62, $top - 30, $width * 0.38 - 14 );
		$ops        = array_merge( $ops, $cnpj_field['ops'] );
	}

	if ( $has_person ) {
		$ops[] = papelito_receipt_pdf_text(
			'Comprador: ' . $doc['buyer']['label'],
			$left + 14,
			$top - $height + 9,
			array(
				'size'  => 7.5,
				'color' => 'label',
			)
		);
	}

	return array(
		'ops' => $ops,
		'y'   => $top - $height - 9,
	);
}

/**
 * Bloco com os dados do pedido, em tres colunas por duas linhas.
 *
 * @param array<string,mixed> $doc Documento do recibo.
 * @return array{ops:array<int,string>,y:float}
 */
function papelito_receipt_pdf_order_block( array $doc, float $top ): array {
	$left   = PAPELITO_RECEIPT_PDF_LEFT;
	$width  = PAPELITO_RECEIPT_PDF_WIDTH;
	$height = 78.0;
	$column = ( $width - 28 ) / 3;
	$fields = array(
		array( 'DATA DA COMPRA', $doc['order']['ordered_at'] ),
		array( 'DATA DO PAGAMENTO', $doc['order']['paid_at'] ),
		array( 'FORMA DE PAGAMENTO', $doc['order']['payment_method'] ),
		array( 'SITUAÇÃO DO PAGAMENTO', $doc['order']['payment_state'] ),
		array( 'SITUAÇÃO DO PEDIDO', $doc['order']['order_status'] ),
		array( 'VENDOR', $doc['order']['vendor'] ),
	);

	$ops   = papelito_receipt_pdf_block_header( 'PEDIDO E PAGAMENTO', $left, $top, $width );
	$ops[] = papelito_receipt_pdf_frame( $left, $top - $height, $width, $height, 'rule' );

	foreach ( $fields as $index => $field ) {
		$field_ops = papelito_receipt_pdf_field(
			$field[0],
			(string) $field[1],
			$left + 14 + $column * ( $index % 3 ),
			$top - 31 - ( 30 * intdiv( $index, 3 ) ),
			$column - 12,
			2
		);
		$ops       = array_merge( $ops, $field_ops['ops'] );
	}

	return array(
		'ops' => $ops,
		'y'   => $top - $height - 20,
	);
}

/**
 * Colunas da tabela de itens. A coluna de desconto so existe quando ha desconto.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_receipt_pdf_columns( bool $has_discount ): array {
	$widths  = $has_discount
		? array(
			'quantity' => 42.0,
			'name'     => 205.0,
			'unit'     => 74.0,
			'discount' => 74.0,
			'total'    => 104.0,
		)
		: array(
			'quantity' => 42.0,
			'name'     => 265.0,
			'unit'     => 88.0,
			'total'    => 104.0,
		);
	$labels  = array(
		'quantity' => 'QTD',
		'name'     => 'DESCRIÇÃO',
		'unit'     => 'VALOR UNIT.',
		'discount' => 'DESCONTO',
		'total'    => 'TOTAL',
	);
	$columns = array();
	$x       = PAPELITO_RECEIPT_PDF_LEFT;

	foreach ( $widths as $key => $column_width ) {
		$columns[] = array(
			'key'   => $key,
			'x'     => $x,
			'width' => $column_width,
			'align' => 'name' === $key ? 'left' : 'right',
			'label' => $labels[ $key ],
		);
		$x        += $column_width;
	}

	return $columns;
}

/**
 * Cabecalho da tabela de itens, repetido a cada pagina.
 *
 * @param array<int,array<string,mixed>> $columns Colunas da tabela.
 * @return array<int,string>
 */
function papelito_receipt_pdf_table_header( array $columns, float $top ): array {
	$ops = array( papelito_receipt_pdf_rect( PAPELITO_RECEIPT_PDF_LEFT, $top - 19, PAPELITO_RECEIPT_PDF_WIDTH, 19, 'ink' ) );

	foreach ( $columns as $column ) {
		$right = 'right' === $column['align'];
		$ops[] = papelito_receipt_pdf_text(
			$column['label'],
			$right ? $column['x'] + $column['width'] - 9 : $column['x'] + 9,
			$top - 12.5,
			array(
				'size'     => 6.4,
				'bold'     => true,
				'color'    => 'white',
				'tracking' => 1.1,
				'align'    => $right ? 'right' : 'left',
			)
		);
	}

	return $ops;
}

/**
 * Altura que a linha do item vai ocupar, para reservar a pagina antes de desenhar.
 *
 * @param array<string,mixed>            $item    Item ja normalizado.
 * @param array<int,array<string,mixed>> $columns Colunas da tabela.
 */
function papelito_receipt_pdf_item_height( array $item, array $columns ): float {
	$lines = papelito_receipt_pdf_wrap( $item['name'], $columns[1]['width'] - 18, 8.5 );

	return max( 22.0, 13.0 + ( count( $lines ) * 11.0 ) );
}

/**
 * Uma linha da tabela de itens.
 *
 * @param array<string,mixed>            $item    Item ja normalizado.
 * @param array<int,array<string,mixed>> $columns Colunas da tabela.
 * @return array{ops:array<int,string>,height:float}
 */
function papelito_receipt_pdf_item_row( array $item, array $columns, float $top, bool $shaded ): array {
	$name_column = $columns[1];
	$name_lines  = papelito_receipt_pdf_wrap( $item['name'], $name_column['width'] - 18, 8.5 );
	$height      = papelito_receipt_pdf_item_height( $item, $columns );
	$baseline    = $top - 15;

	$ops = array();

	if ( $shaded ) {
		$ops[] = papelito_receipt_pdf_rect( PAPELITO_RECEIPT_PDF_LEFT, $top - $height, PAPELITO_RECEIPT_PDF_WIDTH, $height, 'kraft' );
	}

	$ops[] = papelito_receipt_pdf_hline( PAPELITO_RECEIPT_PDF_LEFT, $top - $height, PAPELITO_RECEIPT_PDF_WIDTH, 'rule', 0.5 );

	$values = array(
		'quantity' => (string) $item['quantity'],
		'unit'     => $item['unit_price_cents'] > 0 ? papelito_receipt_money_cents( $item['unit_price_cents'] ) : '-',
		'discount' => $item['discount_cents'] > 0 ? '-' . papelito_receipt_money_cents( $item['discount_cents'] ) : '-',
		'total'    => papelito_receipt_money_cents( $item['total_cents'] ),
	);

	foreach ( $columns as $column ) {
		if ( 'name' === $column['key'] ) {
			foreach ( $name_lines as $index => $line ) {
				$ops[] = papelito_receipt_pdf_text(
					$line,
					$column['x'] + 9,
					$baseline - ( 11 * $index ),
					array(
						'size'    => 8.5,
						'encoded' => true,
					)
				);
			}
			continue;
		}

		$ops[] = papelito_receipt_pdf_text(
			$values[ $column['key'] ],
			$column['x'] + $column['width'] - 9,
			$baseline,
			array(
				'size'  => 8.5,
				'bold'  => 'total' === $column['key'],
				'align' => 'right',
				'color' => '-' === $values[ $column['key'] ] ? 'label' : 'ink',
			)
		);
	}

	return array(
		'ops'    => $ops,
		'height' => $height,
	);
}

/**
 * Area de totais, com o total pago em destaque.
 *
 * @param array<string,mixed> $doc Documento do recibo.
 * @return array<int,string>
 */
function papelito_receipt_pdf_totals( array $doc, float $top ): array {
	$left   = PAPELITO_RECEIPT_PDF_LEFT;
	$width  = PAPELITO_RECEIPT_PDF_WIDTH;
	$box    = 236.0;
	$box_x  = $left + $width - $box;
	$totals = $doc['totals'];
	$rows   = array( array( 'Subtotal', papelito_receipt_money_cents( $totals['subtotal_cents'] ) ) );

	if ( $totals['discount_cents'] > 0 ) {
		$rows[] = array( 'Descontos', '-' . papelito_receipt_money_cents( $totals['discount_cents'] ) );
	}

	$rows[] = array( 'Frete', papelito_receipt_money_cents( $totals['shipping_cents'] ) );

	$ops = array();
	$y   = $top - 14;

	foreach ( $rows as $row ) {
		$ops[] = papelito_receipt_pdf_text(
			$row[0],
			$box_x + 14,
			$y,
			array(
				'size'  => 9,
				'color' => 'label',
			)
		);
		$ops[] = papelito_receipt_pdf_text(
			$row[1],
			$box_x + $box - 14,
			$y,
			array(
				'size'  => 9,
				'align' => 'right',
			)
		);
		$y    -= 16;
	}

	$bar_top = $y + 4;
	$ops[]   = papelito_receipt_pdf_rect( $box_x, $bar_top - 32, $box, 32, 'ink' );
	$ops[]   = papelito_receipt_pdf_rect( $box_x, $bar_top - 32, 5, 32, 'tape' );
	$ops[]   = papelito_receipt_pdf_text(
		'TOTAL PAGO',
		$box_x + 18,
		$bar_top - 20,
		array(
			'size'     => 8,
			'bold'     => true,
			'color'    => 'white',
			'tracking' => 1.2,
		)
	);
	$ops[]   = papelito_receipt_pdf_text(
		papelito_receipt_money_cents( $totals['total_cents'] ),
		$box_x + $box - 14,
		$bar_top - 21,
		array(
			'size'  => 14,
			'bold'  => true,
			'color' => 'white',
			'align' => 'right',
		)
	);

	return $ops;
}

/**
 * Rodape documental, repetido em todas as paginas.
 *
 * @param array<string,mixed> $doc Documento do recibo.
 * @return array<int,string>
 */
function papelito_receipt_pdf_footer( array $doc, int $page, int $pages ): array {
	$left  = PAPELITO_RECEIPT_PDF_LEFT;
	$width = PAPELITO_RECEIPT_PDF_WIDTH;

	return array(
		papelito_receipt_pdf_hline( $left, 92, $width, 'rule', 0.7 ),
		papelito_receipt_pdf_diamond( $left + 3, 78, 3.2, 'tape' ),
		papelito_receipt_pdf_text(
			'Emitido por Papelito',
			$left + 12,
			75,
			array(
				'size' => 8,
				'bold' => true,
			)
		),
		papelito_receipt_pdf_text(
			'Recibo ' . $doc['receipt_number'] . ' · PDF gerado em ' . $doc['generated_at'],
			$left,
			62,
			array(
				'size'  => 7.5,
				'color' => 'label',
			)
		),
		papelito_receipt_pdf_text(
			sprintf( 'Página %d de %d', $page, $pages ),
			$left + $width,
			75,
			array(
				'size'  => 7.5,
				'color' => 'label',
				'align' => 'right',
			)
		),
	);
}

/**
 * Distribui o documento em paginas A4, repetindo cabecalho e cabecalho de
 * tabela nas continuacoes.
 *
 * @param array<string,mixed> $doc Documento do recibo.
 * @return array<int,array<int,string>> Operadores de content stream por pagina.
 */
function papelito_receipt_pdf_pages( array $doc ): array {
	$left     = PAPELITO_RECEIPT_PDF_LEFT;
	$width    = PAPELITO_RECEIPT_PDF_WIDTH;
	$columns  = papelito_receipt_pdf_columns( (bool) $doc['has_discount'] );
	$pages    = array();
	$in_table = true;

	$header = papelito_receipt_pdf_page_header( $doc, false );
	$ops    = $header['ops'];
	$y      = $header['y'];

	$ops = array_merge( $ops, papelito_receipt_pdf_identification( $doc, $y ) );
	$y  -= 64;

	$buyer = papelito_receipt_pdf_buyer_block( $doc, $y );
	$ops   = array_merge( $ops, $buyer['ops'] );
	$y     = $buyer['y'];

	$order = papelito_receipt_pdf_order_block( $doc, $y );
	$ops   = array_merge( $ops, $order['ops'] );
	$y     = $order['y'];

	$ops = array_merge( $ops, papelito_receipt_pdf_block_header( 'ITENS DO PEDIDO', $left, $y, $width ) );
	$y  -= 16;
	$ops = array_merge( $ops, papelito_receipt_pdf_table_header( $columns, $y ) );
	$y  -= 19;

	$break = static function ( float $needed ) use ( &$pages, &$ops, &$y, &$in_table, $doc, $columns ): void {
		if ( $y - $needed >= PAPELITO_RECEIPT_PDF_CONTENT_END ) {
			return;
		}

		$pages[] = $ops;
		$header  = papelito_receipt_pdf_page_header( $doc, true );
		$ops     = $header['ops'];
		$y       = $header['y'];

		if ( $in_table ) {
			$ops = array_merge( $ops, papelito_receipt_pdf_table_header( $columns, $y ) );
			$y  -= 19;
		}
	};

	$shaded = false;

	foreach ( $doc['blocks'] as $block ) {
		if ( $doc['multivendor'] ) {
			$break( 26 );
			$ops[]  = papelito_receipt_pdf_rect( $left, $y - 20, $width, 20, 'kraft' );
			$ops[]  = papelito_receipt_pdf_diamond( $left + 11, $y - 10, 3.0, 'tape' );
			$ops[]  = papelito_receipt_pdf_text(
				'Vendor: ' . $block['vendor_name'],
				$left + 20,
				$y - 13.5,
				array(
					'size' => 8,
					'bold' => true,
				)
			);
			$ops[]  = papelito_receipt_pdf_hline( $left, $y - 20, $width, 'rule', 0.5 );
			$y     -= 20;
			$shaded = false;
		}

		foreach ( $block['items'] as $item ) {
			$break( papelito_receipt_pdf_item_height( $item, $columns ) );
			$row    = papelito_receipt_pdf_item_row( $item, $columns, $y, $shaded );
			$ops    = array_merge( $ops, $row['ops'] );
			$y     -= $row['height'];
			$shaded = ! $shaded;
		}

		if ( $doc['multivendor'] ) {
			$break( 24 );
			$ops[] = papelito_receipt_pdf_text(
				'Total do vendor',
				$left + $width - 118,
				$y - 14,
				array(
					'size'  => 8,
					'color' => 'label',
					'align' => 'right',
				)
			);
			$ops[] = papelito_receipt_pdf_text(
				papelito_receipt_money_cents( (int) $block['total_cents'] ),
				$left + $width - 9,
				$y - 14,
				array(
					'size'  => 8.5,
					'bold'  => true,
					'align' => 'right',
				)
			);
			$ops[] = papelito_receipt_pdf_hline( $left, $y - 22, $width, 'rule', 0.5 );
			$y    -= 26;
		}
	}

	$in_table = false;
	$break( 118 );
	$ops[] = papelito_receipt_pdf_hline( $left, $y - 8, $width, 'ink', 1.2 );
	$ops   = array_merge( $ops, papelito_receipt_pdf_totals( $doc, $y - 14 ) );

	$pages[] = $ops;

	return $pages;
}

/**
 * Serializa as paginas do recibo em um PDF A4 autocontido.
 *
 * @return string|WP_Error
 */
function papelito_receipt_pdf( object $order ) {
	$doc = papelito_receipt_document( $order );

	if ( is_wp_error( $doc ) ) {
		return $doc;
	}

	$pages           = papelito_receipt_pdf_pages( $doc );
	$total_pages     = count( $pages );
	$objects         = array();
	$page_references = array();
	$objects[1]      = '<< /Type /Catalog /Pages 2 0 R >>';
	$objects[3]      = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
	$objects[4]      = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

	foreach ( $pages as $index => $page_ops ) {
		$content_id = 5 + ( $index * 2 );
		$page_id    = $content_id + 1;
		$content    = implode( "\n", array_filter( array_merge( $page_ops, papelito_receipt_pdf_footer( $doc, $index + 1, $total_pages ) ) ) );

		$objects[ $content_id ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		$objects[ $page_id ]    = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
		$page_references[]      = $page_id . ' 0 R';
	}

	$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $page_references ) . '] /Count ' . count( $page_references ) . ' >>';
	ksort( $objects );

	$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
	$offsets = array( 0 );
	foreach ( $objects as $id => $object ) {
		$offsets[ $id ] = strlen( $pdf );
		$pdf           .= $id . " 0 obj\n" . $object . "\nendobj\n";
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
	$key      = sprintf( 'papelito_receipt_email_%d_%d', $order_id, $user_id );
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
 * Nome do arquivo do recibo, igual no download e no anexo do e-mail.
 *
 * @param object $order Pedido do recibo.
 */
function papelito_receipt_filename( object $order ): string {
	return 'recibo-pedido-' . absint( $order->get_id() ) . '.pdf';
}

/**
 * Arquivo temporario com o recibo, para anexar no e-mail.
 *
 * O envio roda em requisicao REST, onde wp-admin/includes/file.php nao esta
 * carregado e wp_tempnam() nao existe; por isso o temporario e escrito aqui.
 *
 * @param int    $order_id Identificador do pedido.
 * @param string $pdf      Bytes do recibo ja renderizado.
 * @return string|WP_Error Caminho do temporario, ou erro ao prepara-lo.
 */
function papelito_receipt_email_attachment_file( int $order_id, string $pdf ) {
	$path = trailingslashit( get_temp_dir() ) . sprintf( 'recibo-pedido-%d-%s.pdf', $order_id, wp_generate_password( 12, false ) );

	if ( file_exists( $path ) || strlen( $pdf ) !== file_put_contents( $path, $pdf, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return new WP_Error( 'papelito_receipt_email_attachment_failed', 'Nao foi possivel preparar o recibo.', array( 'status' => 500 ) );
	}

	return $path;
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

	$filename = papelito_receipt_filename( $order );
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

	$temp_file = papelito_receipt_email_attachment_file( (int) $order->get_id(), $pdf );
	if ( is_wp_error( $temp_file ) ) {
		return $temp_file;
	}

	$sent = wp_mail(
		$recipient,
		sprintf( 'Recibo do pedido #%s', sanitize_text_field( (string) $order->get_order_number() ) ),
		"Ola,\n\nSegue em anexo o recibo do seu pedido.\n",
		array( 'Content-Type: text/plain; charset=UTF-8' ),
		array( papelito_receipt_filename( $order ) => $temp_file )
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
