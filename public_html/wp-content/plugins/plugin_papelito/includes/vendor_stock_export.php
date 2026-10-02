<?php
/**
 * Exportação do estoque do vendor para conciliar com o ERP ou a planilha dele.
 *
 * Uma linha por item vendável do catálogo, inclusive os nunca configurados.
 * A coluna SKU é a que o vendor vê na tela: o código dele quando existe, senão
 * o SKU da Papelito — sem coluna separada para os dois. Kits ficam de fora: não
 * têm saldo próprio. O SKU sai sempre como texto, preservando zeros à esquerda.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cabeçalho legível pelo vendor, na ordem das colunas.
 *
 * @return string[]
 */
function papelito_vendor_stock_export_header(): array {
	return array( 'SKU', 'Produto', 'Quantidade', 'Atualizado em' );
}

/**
 * Linhas do catálogo inteiro do vendor, ordenadas por nome.
 *
 * @param int $vendor_id Vendor autenticado.
 * @return array<int,array{sku:string,product_name:string,qty:int,updated_at:string}>
 */
function papelito_vendor_stock_export_rows( int $vendor_id ): array {
	$snapshot = papelito_vendor_stock_query(
		$vendor_id,
		array(
			'paginate' => false,
			'type'     => 'products',
			'sort'     => 'name_asc',
		)
	);
	return array_map(
		static fn( array $item ): array => array(
			'sku'          => (string) ( $item['vendor_code'] ?? $item['sku'] ),
			'product_name' => papelito_admin_reports_normalize_export_text( $item['product_name'] ),
			'qty'          => (int) $item['qty'],
			'updated_at'   => (string) $item['updated_at'],
		),
		$snapshot['items']
	);
}

/**
 * CSV com BOM e ponto e vírgula, que o Excel em português abre em colunas.
 *
 * @param array<int,array<string,string|int>> $rows Linhas de `papelito_vendor_stock_export_rows()`.
 */
function papelito_vendor_stock_export_csv( array $rows ): string {
	$stream = fopen( 'php://temp', 'r+' );
	if ( false === $stream ) {
		return '';
	}
	fwrite( $stream, "\xEF\xBB\xBF" );
	fputcsv( $stream, papelito_vendor_stock_export_header(), ';', '"', '' );
	foreach ( $rows as $row ) {
		fputcsv( $stream, array_values( $row ), ';', '"', '' );
	}
	rewind( $stream );
	$csv = stream_get_contents( $stream );
	fclose( $stream );
	return is_string( $csv ) ? $csv : '';
}

/**
 * Escreve uma linha na planilha com o SKU forçado como texto.
 *
 * @param object                   $sheet     Planilha ativa do PhpSpreadsheet.
 * @param array<string,string|int> $row       Linha exportada.
 * @param int                      $row_index Linha da planilha, a partir de 2.
 */
function papelito_vendor_stock_export_xlsx_row( object $sheet, array $row, int $row_index ): void {
	$text = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
	$sheet->setCellValueExplicit( 'A' . $row_index, $row['sku'], $text );
	$sheet->setCellValueExplicit( 'B' . $row_index, $row['product_name'], $text );
	$sheet->setCellValue( 'C' . $row_index, $row['qty'] );
	$sheet->setCellValueExplicit( 'D' . $row_index, $row['updated_at'], $text );
}

/**
 * XLSX do estoque; erro de geração volta como WP_Error 500.
 *
 * @param array<int,array<string,string|int>> $rows Linhas de `papelito_vendor_stock_export_rows()`.
 * @return string|WP_Error
 */
function papelito_vendor_stock_export_xlsx( array $rows ): string|WP_Error {
	$ready = papelito_admin_reports_require_spreadsheet();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}
	try {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Estoque' );
		$sheet->fromArray( papelito_vendor_stock_export_header(), null, 'A1' );
		foreach ( array_values( $rows ) as $offset => $row ) {
			papelito_vendor_stock_export_xlsx_row( $sheet, $row, $offset + 2 );
		}
		foreach ( range( 'A', 'D' ) as $column ) {
			$sheet->getColumnDimension( $column )->setAutoSize( true );
		}
		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
		ob_start();
		$writer->save( 'php://output' );
		$binary = ob_get_clean();
		$spreadsheet->disconnectWorksheets();
		return is_string( $binary ) ? $binary : '';
	} catch ( Throwable ) {
		return new WP_Error( 'papelito_vendor_stock_export_failed', 'Não foi possível gerar a planilha de estoque.', array( 'status' => 500 ) );
	}
}

/**
 * Entrega o arquivo do vendor autenticado; `format=csv` troca a planilha por CSV.
 *
 * @param WP_REST_Request $request Requisição com `format` opcional.
 * @return WP_Error|void
 */
function papelito_vendor_stock_export_endpoint( WP_REST_Request $request ) {
	$format = 'csv' === $request->get_param( 'format' ) ? 'csv' : 'xlsx';
	$rows   = papelito_vendor_stock_export_rows( get_current_user_id() );
	$binary = 'csv' === $format ? papelito_vendor_stock_export_csv( $rows ) : papelito_vendor_stock_export_xlsx( $rows );
	return papelito_vendor_reports_download( $binary, 'meu-estoque', $format );
}

/** Permission callback: leitura do próprio estoque, liberada também para conta suspensa. */
function papelito_vendor_stock_export_permission(): bool|WP_Error {
	$user = papelito_vendor_stock_require_seller();
	return is_wp_error( $user ) ? $user : true;
}

/** Registra a rota de exportação do estoque do vendor. */
function papelito_vendor_stock_export_register_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/vendor/me/stock/export',
		array(
			'methods'             => 'GET',
			'callback'            => 'papelito_vendor_stock_export_endpoint',
			'permission_callback' => 'papelito_vendor_stock_export_permission',
			'args'                => array(
				'format' => array(
					'type'    => 'string',
					'enum'    => array( 'xlsx', 'csv' ),
					'default' => 'xlsx',
				),
			),
		)
	);
}

add_action( 'rest_api_init', 'papelito_vendor_stock_export_register_routes' );
