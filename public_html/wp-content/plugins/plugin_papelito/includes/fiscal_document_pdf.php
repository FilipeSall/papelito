<?php
/**
 * Espelho em PDF da nota fiscal anexada pelo vendor.
 *
 * **Não é DANFE e não é documento fiscal.** É o resumo que a Papelito guarda do
 * que o vendor anexou, com a mesma linguagem visual do recibo do comprador. O
 * documento com valor fiscal é a NF-e emitida pelo vendor; quando o DANFE em
 * PDF está anexado, ele continua disponível ao lado deste espelho.
 *
 * Reaproveita os primitivos de `order_receipt.php` — paleta, fontes, campos,
 * faixas e o montador do arquivo — para não existir um segundo desenho de
 * documento no produto.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rótulo da situação do documento, para o espelho não imprimir o slug cru.
 */
function papelito_fiscal_pdf_status_label( string $status ): string {
	$labels = array(
		'aceita'           => 'Aceita',
		'cancelada'        => 'Cancelada',
		'pendente_revisao' => 'Pendente de revisão',
		'recebida'         => 'Recebida',
		'rejeitada'        => 'Rejeitada',
		'substituida'      => 'Substituída',
	);

	return $labels[ $status ] ?? $status;
}

/**
 * Frase de cada nível de conferência local. O texto acompanha o número porque
 * "5" sozinho seria lido como homologação, que não é o que a escala mede.
 */
function papelito_fiscal_pdf_level_label( int $level ): string {
	$labels = array(
		PAPELITO_FISCAL_LEVEL_FILE   => 'Arquivo aceito',
		PAPELITO_FISCAL_LEVEL_KEY    => 'Chave estruturalmente válida',
		PAPELITO_FISCAL_LEVEL_XML    => 'XML coerente com a chave',
		PAPELITO_FISCAL_LEVEL_ISSUER => 'Emitente confere',
		PAPELITO_FISCAL_LEVEL_AMOUNT => 'Valor confere com o pedido',
	);

	return $labels[ $level ] ?? $labels[ PAPELITO_FISCAL_LEVEL_FILE ];
}

/**
 * Divergências por extenso, no mesmo texto que a tela do vendor mostra.
 */
function papelito_fiscal_pdf_flag_label( string $flag ): string {
	$labels = array(
		'chave_divergente'        => 'A chave digitada não é a mesma do XML.',
		'cnpj_emitente_invalido'  => 'O CNPJ do emitente não passa na validação.',
		'emissao_incoerente'      => 'A data de emissão não bate com a competência da chave.',
		'emitente_divergente'     => 'O emitente digitado não é o mesmo do XML.',
		'emitente_fora_da_chave'  => 'O CNPJ do emitente não é o que está embutido na chave.',
		'emitente_nao_e_o_vendor' => 'O emitente da nota não é o CNPJ da loja.',
		'modelo_incoerente'       => 'O modelo na chave não corresponde ao tipo de documento.',
		'numero_divergente'       => 'O número digitado não é o mesmo do XML.',
		'serie_divergente'        => 'A série digitada não é a mesma do XML.',
		'valor_divergente'        => 'O valor digitado não é o mesmo do XML.',
		'valor_fora_do_pedido'    => 'O valor da nota não bate com o total do pedido.',
	);

	return $labels[ $flag ] ?? str_replace( '_', ' ', $flag );
}

/**
 * Chave de acesso em grupos de quatro, como se lê no DANFE.
 */
function papelito_fiscal_pdf_key_label( string $key ): string {
	return '' === $key ? '' : trim( (string) chunk_split( $key, 4, ' ' ) );
}

function papelito_fiscal_pdf_cnpj_label( string $digits ): string {
	if ( 14 !== strlen( $digits ) ) {
		return $digits;
	}

	return substr( $digits, 0, 2 ) . '.' . substr( $digits, 2, 3 ) . '.' . substr( $digits, 5, 3 )
		. '/' . substr( $digits, 8, 4 ) . '-' . substr( $digits, 12, 2 );
}

/**
 * Rótulo do papel do arquivo anexado.
 */
function papelito_fiscal_pdf_role_label( string $role ): string {
	$labels = array(
		'danfe_pdf' => 'DANFE em PDF',
		'xml'       => 'XML da nota',
	);

	return $labels[ $role ] ?? 'Arquivo';
}

/**
 * Dados que o espelho imprime, já formatados.
 *
 * @param object $order Pedido WooCommerce.
 * @return array<string,mixed>|WP_Error
 */
function papelito_fiscal_pdf_document( $order, int $vendor_id ) {
	$document = papelito_fiscal_document_current( (int) $order->get_id(), $vendor_id );

	if ( ! $document ) {
		return new WP_Error( 'papelito_fiscal_document_not_found', 'Nota fiscal não encontrada.', array( 'status' => 404 ) );
	}

	$flags   = json_decode( (string) ( $document['flags_json'] ?? '' ), true );
	$billing = papelito_vendor_dashboard_billing( $order );
	$files   = array();

	foreach ( papelito_fiscal_document_files( (int) $document['id'] ) as $file ) {
		$files[] = array(
			'role' => papelito_fiscal_pdf_role_label( (string) $file['role'] ),
			'name' => (string) $file['original_name'],
			'size' => size_format( (int) $file['size_bytes'] ),
		);
	}

	return array(
		'order_number' => (string) $order->get_order_number(),
		'order_total'  => papelito_receipt_money_cents( (int) round( ( (float) $order->get_total() ) * 100 ) ),
		'doc_status'   => papelito_fiscal_pdf_status_label( (string) $document['doc_status'] ),
		'doc_type'     => strtoupper( (string) $document['doc_type'] ),
		'access_key'   => papelito_fiscal_pdf_key_label( (string) ( $document['access_key'] ?? '' ) ),
		'doc_number'   => (string) ( $document['doc_number'] ?? '' ),
		'doc_series'   => (string) ( $document['doc_series'] ?? '' ),
		'protocol'     => (string) ( $document['protocol'] ?? '' ),
		'issuer_name'  => (string) ( $document['issuer_name'] ?? '' ),
		'issuer_cnpj'  => papelito_fiscal_pdf_cnpj_label( (string) ( $document['issuer_cnpj'] ?? '' ) ),
		'issued_at'    => papelito_receipt_datetime_label( (string) ( $document['issued_at'] ?? '' ) ),
		'total'        => papelito_receipt_money_cents( (int) $document['total_cents'] ),
		'level'        => (int) $document['validation_level'],
		'level_label'  => papelito_fiscal_pdf_level_label( (int) $document['validation_level'] ),
		'flags'        => array_map( 'papelito_fiscal_pdf_flag_label', is_array( $flags ) ? $flags : array() ),
		'notes'        => (string) ( $document['internal_notes'] ?? '' ),
		'attached_at'  => papelito_receipt_datetime_label( (string) $document['created_at'] ),
		'buyer_name'   => (string) ( '' !== $billing['legal_name'] ? $billing['legal_name'] : papelito_vendor_dashboard_customer_label( $order ) ),
		'buyer_cnpj'   => papelito_fiscal_pdf_cnpj_label( (string) $billing['cnpj'] ),
		'files'        => $files,
		'generated_at' => papelito_receipt_datetime_label( current_time( 'mysql', true ) ),
	);
}

/**
 * Faixa de identificação do espelho, no lugar da tira do recibo.
 *
 * @param array<string,mixed> $doc Dados do espelho.
 * @return array{ops:array<int,string>,y:float}
 */
function papelito_fiscal_pdf_identification( array $doc, float $top ): array {
	$left  = PAPELITO_RECEIPT_PDF_LEFT;
	$width = PAPELITO_RECEIPT_PDF_WIDTH;
	$third = $width / 3;

	$ops = array(
		papelito_receipt_pdf_rect( $left, $top - 46, $width, 46, 'kraft' ),
		papelito_receipt_pdf_frame( $left, $top - 46, $width, 46, 'rule' ),
	);

	$fields = array(
		array( 'DOCUMENTO', $doc['doc_type'] . ( '' !== $doc['doc_number'] ? ' Nº ' . $doc['doc_number'] : '' ) . ( '' !== $doc['doc_series'] ? ' / SÉRIE ' . $doc['doc_series'] : '' ) ),
		array( 'PEDIDO', '#' . $doc['order_number'] ),
		array( 'SITUAÇÃO', $doc['doc_status'] ),
	);

	foreach ( $fields as $index => $field ) {
		$field_ops = papelito_receipt_pdf_field( $field[0], $field[1], $left + 16 + ( $third * $index ), $top - 16, $third - 24 );
		$ops       = array_merge( $ops, $field_ops['ops'] );
	}

	return array(
		'ops' => $ops,
		'y'   => $top - 62,
	);
}

/**
 * Bloco de um par de colunas de campos.
 *
 * @param array<int,array{0:string,1:string}> $fields Rótulo e valor.
 * @return array{ops:array<int,string>,y:float}
 */
function papelito_fiscal_pdf_fields_block( string $title, array $fields, float $top ): array {
	$left   = PAPELITO_RECEIPT_PDF_LEFT;
	$width  = PAPELITO_RECEIPT_PDF_WIDTH;
	$half   = $width / 2;
	$ops    = papelito_receipt_pdf_block_header( $title, $left, $top, $width );
	$cursor = $top - 16;
	$rows   = (int) ceil( count( $fields ) / 2 );
	$height = 0.0;

	foreach ( array_values( $fields ) as $index => $field ) {
		$column    = $index % 2;
		$row       = intdiv( $index, 2 );
		$field_ops = papelito_receipt_pdf_field(
			$field[0],
			$field[1],
			$left + 16 + ( $half * $column ),
			$cursor - 14 - ( 34 * $row ),
			$half - 30,
			2
		);
		$ops       = array_merge( $ops, $field_ops['ops'] );
		$height    = max( $height, ( 34 * $row ) + 14 + $field_ops['height'] );
	}

	$block_height = max( $height + 14, ( 34 * $rows ) + 14 );
	$ops[]        = papelito_receipt_pdf_frame( $left, $cursor - $block_height, $width, $block_height, 'rule' );

	return array(
		'ops' => $ops,
		'y'   => $cursor - $block_height - 18,
	);
}

/**
 * Bloco da conferência local, com a ressalva de escopo junto do número.
 *
 * @param array<string,mixed> $doc Dados do espelho.
 * @return array{ops:array<int,string>,y:float}
 */
function papelito_fiscal_pdf_validation_block( array $doc, float $top ): array {
	$left  = PAPELITO_RECEIPT_PDF_LEFT;
	$width = PAPELITO_RECEIPT_PDF_WIDTH;
	$flags = (array) $doc['flags'];
	$lines = array();

	$lines[] = array( sprintf( 'Nível %d de 5 · %s', (int) $doc['level'], (string) $doc['level_label'] ), true );
	$lines[] = array( 'Conferência feita pela Papelito sobre o arquivo e o pedido. Não é validação perante o fisco.', false );

	foreach ( $flags as $flag ) {
		$lines[] = array( '•  ' . $flag, false );
	}

	if ( ! empty( $flags ) ) {
		$lines[] = array( 'Divergência é sinalização: não bloqueia pagamento, separação, postagem nem entrega.', false );
	}

	$ops    = papelito_receipt_pdf_block_header( 'CONFERÊNCIA LOCAL', $left, $top, $width );
	$cursor = $top - 16;
	$y      = $cursor - 16;

	foreach ( $lines as $line ) {
		$ops[] = papelito_receipt_pdf_text(
			(string) $line[0],
			$left + 16,
			$y,
			array(
				'size'  => $line[1] ? 9.5 : 8.0,
				'bold'  => (bool) $line[1],
				'color' => $line[1] ? 'ink' : 'label',
			)
		);
		$y    -= $line[1] ? 16 : 13;
	}

	$block_height = ( $cursor - $y ) + 4;
	$ops[]        = papelito_receipt_pdf_frame( $left, $cursor - $block_height, $width, $block_height, 'rule' );

	return array(
		'ops' => $ops,
		'y'   => $cursor - $block_height - 18,
	);
}

/**
 * Página única do espelho.
 *
 * @param array<string,mixed> $doc Dados do espelho.
 * @return array<int,array<int,string>>
 */
function papelito_fiscal_pdf_pages( array $doc ): array {
	$left   = PAPELITO_RECEIPT_PDF_LEFT;
	$width  = PAPELITO_RECEIPT_PDF_WIDTH;
	// A faixa do recibo diz "RECIBO DE PEDIDO"; aqui ela precisa dizer o que
	// este documento é, ou o vendor guardaria um espelho achando que é a nota.
	$header = papelito_receipt_pdf_page_header( $doc, false, 'ESPELHO DA NOTA FISCAL' );
	$ops    = $header['ops'];

	$identification = papelito_fiscal_pdf_identification( $doc, $header['y'] );
	$ops            = array_merge( $ops, $identification['ops'] );

	$emitter = papelito_fiscal_pdf_fields_block(
		'NOTA ANEXADA',
		array(
			array( 'CHAVE DE ACESSO', (string) $doc['access_key'] ),
			array( 'PROTOCOLO', (string) $doc['protocol'] ),
			array( 'EMITENTE', (string) $doc['issuer_name'] ),
			array( 'CNPJ DO EMITENTE', (string) $doc['issuer_cnpj'] ),
			array( 'EMISSÃO', (string) $doc['issued_at'] ),
			array( 'VALOR DA NOTA', (string) $doc['total'] ),
		),
		$identification['y']
	);
	$ops = array_merge( $ops, $emitter['ops'] );

	$order_block = papelito_fiscal_pdf_fields_block(
		'PEDIDO E COMPRADOR',
		array(
			array( 'PEDIDO', '#' . $doc['order_number'] ),
			array( 'TOTAL DO PEDIDO', (string) $doc['order_total'] ),
			array( 'COMPRADOR', (string) $doc['buyer_name'] ),
			array( 'CNPJ DO COMPRADOR', (string) $doc['buyer_cnpj'] ),
		),
		$emitter['y']
	);
	$ops = array_merge( $ops, $order_block['ops'] );

	$validation = papelito_fiscal_pdf_validation_block( $doc, $order_block['y'] );
	$ops        = array_merge( $ops, $validation['ops'] );

	$files = (array) $doc['files'];

	if ( ! empty( $files ) ) {
		$file_fields = array();

		foreach ( $files as $file ) {
			$file_fields[] = array( strtoupper( (string) $file['role'] ), (string) $file['name'] . ' · ' . (string) $file['size'] );
		}

		$files_block = papelito_fiscal_pdf_fields_block( 'ARQUIVOS ANEXADOS', $file_fields, $validation['y'] );
		$ops         = array_merge( $ops, $files_block['ops'] );
		$cursor      = $files_block['y'];
	} else {
		$cursor = $validation['y'];
	}

	$ops[] = papelito_receipt_pdf_text(
		'A nota fiscal com valor legal é a emitida pelo vendor. Este espelho é o registro que a Papelito guarda do que foi anexado ao pedido.',
		$left,
		$cursor - 4,
		array(
			'size'  => 7.5,
			'color' => 'label',
		)
	);

	return array( $ops );
}

/**
 * Rodapé próprio: o do recibo imprime o número do recibo, que aqui não existe.
 *
 * @param array<string,mixed> $doc Dados do espelho.
 * @return array<int,string>
 */
function papelito_fiscal_pdf_footer( array $doc ): array {
	$left  = PAPELITO_RECEIPT_PDF_LEFT;
	$width = PAPELITO_RECEIPT_PDF_WIDTH;

	return array(
		papelito_receipt_pdf_hline( $left, 92, $width, 'rule', 0.7 ),
		papelito_receipt_pdf_diamond( $left + 3, 78, 3.2, 'tape' ),
		papelito_receipt_pdf_text( 'Emitido por Papelito', $left + 12, 75, array( 'size' => 8, 'bold' => true ) ),
		papelito_receipt_pdf_text(
			'Espelho da nota do pedido #' . $doc['order_number'] . ' · anexada em ' . $doc['attached_at'] . ' · PDF gerado em ' . $doc['generated_at'],
			$left,
			62,
			array(
				'size'  => 7.5,
				'color' => 'label',
			)
		),
	);
}

/**
 * PDF do espelho, ou WP_Error quando não há nota anexada.
 *
 * @param object $order Pedido WooCommerce.
 * @return string|WP_Error
 */
function papelito_fiscal_document_pdf( $order, int $vendor_id ) {
	$doc = papelito_fiscal_pdf_document( $order, $vendor_id );

	if ( is_wp_error( $doc ) ) {
		return $doc;
	}

	$pages    = papelito_fiscal_pdf_pages( $doc );
	$rendered = array();

	foreach ( $pages as $page_ops ) {
		$rendered[] = array_merge( $page_ops, papelito_fiscal_pdf_footer( $doc ) );
	}

	return papelito_pdf_assemble( $rendered );
}
