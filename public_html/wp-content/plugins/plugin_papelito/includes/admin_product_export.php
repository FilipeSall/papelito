<?php
/**
 * Exportação do catálogo de produtos no mesmo formato da importação do admin.
 *
 * As colunas são as de `papelito_product_import_columns()`, na mesma ordem, mais `sku`, `status`
 * e `tipo` como referência: o arquivo exportado é o modelo preenchido, e reenviá-lo pela
 * importação não altera nada — as três colunas extras são ignoradas e as linhas viram "já existe".
 *
 * Fica de fora a fachada dos Kits, que é domínio próprio e não se cadastra por planilha. Produto
 * variável sai numa linha só, sem preço: o preço mora nas variações, que a importação não cria.
 * Como as demais exportações, registra a operação sem guardar o conteúdo.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_PRODUCT_EXPORT_STATUSES = array(
	'all'     => array( 'publish', 'draft', 'pending', 'private' ),
	'publish' => array( 'publish' ),
	'draft'   => array( 'draft', 'pending' ),
);

/**
 * Filtros da exportação: formato, situação e categoria (slug ativo ou `all`).
 *
 * @param WP_REST_Request $request Parâmetros `format`, `status` e `category`.
 * @return array{format:string,status:string,category:?array}
 */
function papelito_product_export_filters( WP_REST_Request $request ): array {
	$status   = sanitize_key( (string) $request->get_param( 'status' ) );
	$slug     = sanitize_title( (string) $request->get_param( 'category' ) );
	$category = '' === $slug || 'all' === $slug ? null : papelito_category_get_by_slug( $slug );

	return array(
		'format'   => 'csv' === sanitize_key( (string) $request->get_param( 'format' ) ) ? 'csv' : 'xlsx',
		'status'   => isset( PAPELITO_PRODUCT_EXPORT_STATUSES[ $status ] ) ? $status : 'all',
		'category' => $category,
	);
}

/**
 * Ids dos produtos do recorte, por nome, sem a fachada dos Kits.
 *
 * @param array<string,mixed> $filters Saída de `papelito_product_export_filters()`.
 * @return int[]
 */
function papelito_product_export_ids( array $filters ): array {
	global $wpdb;

	$statuses = PAPELITO_PRODUCT_EXPORT_STATUSES[ $filters['status'] ];
	$kits     = papelito_kits_table_names()['kits'];
	$taxonomy = papelito_product_taxonomy_table_names()['product_category'];
	$join     = null === $filters['category'] ? '' : "INNER JOIN {$taxonomy} c ON c.product_id = p.ID AND c.category_id = " . (int) $filters['category']['id'];
	$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- Nomes de tabela não viram placeholder; o id da categoria é inteiro; os placeholders dos status são montados acima.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p {$join}
			WHERE p.post_type = 'product' AND p.post_status IN ({$in})
			AND p.ID NOT IN (SELECT product_id FROM {$kits})
			ORDER BY p.post_title ASC, p.ID ASC",
			$statuses
		)
	);
	// phpcs:enable

	return array_map( 'intval', is_array( $ids ) ? $ids : array() );
}

/**
 * Cabeçalho do arquivo: `sku`, as colunas da importação e as de referência.
 *
 * @return string[]
 */
function papelito_product_export_header(): array {
	return array_merge( array( 'sku' ), array_keys( papelito_product_import_columns() ), array( 'status', 'tipo' ) );
}

/**
 * Número no formato do arquivo: vírgula decimal no CSV, número de verdade no XLSX; vazio quando não há.
 *
 * @param mixed  $value  Valor do WooCommerce.
 * @param string $format `csv` ou `xlsx`.
 * @return string|float
 */
function papelito_product_export_number( mixed $value, string $format ): string|float {
	if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
		return '';
	}
	return 'csv' === $format ? str_replace( '.', ',', (string) (float) $value ) : (float) $value;
}

/**
 * Texto do produto sem HTML, com a mesma limpeza das outras exportações.
 *
 * @param mixed $value Texto do WooCommerce.
 */
function papelito_product_export_text( mixed $value ): string {
	return papelito_admin_reports_normalize_export_text( trim( wp_strip_all_tags( (string) $value ) ) );
}

/**
 * Uma linha do arquivo, na ordem de `papelito_product_export_header()`.
 *
 * @param WC_Product          $product Produto.
 * @param array<string,array> $maps    Categoria, subcategorias e coleções de todos os produtos do recorte.
 * @param string              $format  `csv` ou `xlsx`.
 * @return array<int,string|float>
 */
function papelito_product_export_row( WC_Product $product, array $maps, string $format ): array {
	$id   = $product->get_id();
	$tags = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'names' ) );

	return array(
		$product->get_sku(),
		papelito_product_export_text( $product->get_name() ),
		(string) ( $maps['categories'][ $id ]['slug'] ?? '' ),
		implode( PAPELITO_PRODUCT_IMPORT_LIST_SEP, array_column( $maps['subcategories'][ $id ] ?? array(), 'slug' ) ),
		papelito_product_export_number( $product->get_regular_price(), $format ),
		papelito_product_export_number( $product->get_sale_price(), $format ),
		papelito_product_export_number( $product->get_weight(), $format ),
		papelito_product_export_number( $product->get_length(), $format ),
		papelito_product_export_number( $product->get_width(), $format ),
		papelito_product_export_number( $product->get_height(), $format ),
		papelito_product_export_text( $product->get_short_description() ),
		papelito_product_export_text( $product->get_description() ),
		implode( PAPELITO_PRODUCT_IMPORT_LIST_SEP, $maps['collections'][ $id ] ?? array() ),
		implode( PAPELITO_PRODUCT_IMPORT_LIST_SEP, is_array( $tags ) ? $tags : array() ),
		$product->get_status(),
		$product->get_type(),
	);
}

/**
 * Linhas de todos os produtos do recorte, com a taxonomia lida em lote.
 *
 * @param int[]  $ids    Produtos.
 * @param string $format `csv` ou `xlsx`.
 * @return array<int,array<int,string|float>>
 */
function papelito_product_export_rows( array $ids, string $format ): array {
	$maps = array(
		'categories'    => papelito_products_category_map( $ids ),
		'subcategories' => papelito_products_subcategory_map( $ids ),
		'collections'   => papelito_products_collection_map( $ids ),
	);
	$rows = array();
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( $product instanceof WC_Product ) {
			$rows[] = papelito_product_export_row( $product, $maps, $format );
		}
	}
	return $rows;
}

/**
 * CSV com BOM e ponto e vírgula, o separador que o Excel em português abre em colunas.
 *
 * @param string[]                           $header Cabeçalho.
 * @param array<int,array<int,string|float>> $rows   Linhas.
 */
function papelito_product_export_csv( array $header, array $rows ): string {
	$file = new SplTempFileObject();
	$file->fwrite( "\xEF\xBB\xBF" );
	$file->fputcsv( $header, ';', '"', '' );
	foreach ( $rows as $row ) {
		$file->fputcsv( $row, ';', '"', '' );
	}
	$file->rewind();
	$csv = '';
	while ( ! $file->eof() ) {
		$csv .= (string) $file->fgets();
	}
	return $csv;
}

/**
 * XLSX numa aba "Produtos", com preço, peso e medidas como número.
 *
 * @param string[]                           $header Cabeçalho.
 * @param array<int,array<int,string|float>> $rows   Linhas.
 * @return string|WP_Error
 */
function papelito_product_export_xlsx( array $header, array $rows ): string|WP_Error {
	$ready = papelito_admin_reports_require_spreadsheet();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}
	try {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Produtos' );
		$sheet->fromArray( array_merge( array( $header ), $rows ), null, 'A1', true );
		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
		ob_start();
		$writer->save( 'php://output' );
		$binary = ob_get_clean();
		$spreadsheet->disconnectWorksheets();
		return is_string( $binary ) ? $binary : '';
	} catch ( Throwable $unused ) {
		return new WP_Error( 'papelito_product_export_xlsx_failed', 'Não foi possível gerar o XLSX de produtos.', array( 'status' => 500 ) );
	}
}

/**
 * Rótulo da exportação para a Atividade recente: "Produtos · Rascunhos · Sedas".
 *
 * @param array<string,mixed> $filters Filtros da exportação.
 */
function papelito_product_export_title( array $filters ): string {
	$parts  = array( 'Produtos' );
	$labels = array(
		'publish' => 'Publicados',
		'draft'   => 'Rascunhos',
	);
	if ( isset( $labels[ $filters['status'] ] ) ) {
		$parts[] = $labels[ $filters['status'] ];
	}
	if ( null !== $filters['category'] ) {
		$parts[] = (string) $filters['category']['name'];
	}
	return implode( ' · ', $parts );
}

/**
 * GET do download: gera o arquivo, registra a operação e entrega.
 *
 * @param WP_REST_Request $request Filtros.
 * @return WP_Error|void Erro ao gerar; no sucesso a resposta é o arquivo.
 */
function papelito_product_export_endpoint( WP_REST_Request $request ) {
	$filters = papelito_product_export_filters( $request );
	$rows    = papelito_product_export_rows( papelito_product_export_ids( $filters ), $filters['format'] );
	$header  = papelito_product_export_header();
	$binary  = 'csv' === $filters['format'] ? papelito_product_export_csv( $header, $rows ) : papelito_product_export_xlsx( $header, $rows );
	$status  = 0 === count( $rows ) ? PAPELITO_REPORT_OPERATION_EMPTY : PAPELITO_REPORT_OPERATION_DONE;

	papelito_report_operation_record(
		array(
			'kind'   => 'export',
			'report' => 'products',
			'title'  => papelito_product_export_title( $filters ),
			'params' => array(
				'format'   => $filters['format'],
				'status'   => $filters['status'],
				'category' => null === $filters['category'] ? 'all' : (string) $filters['category']['slug'],
			),
		),
		array(
			'status' => is_wp_error( $binary ) ? PAPELITO_REPORT_OPERATION_FAILED : $status,
			'rows'   => count( $rows ),
			'error'  => is_wp_error( $binary ) ? $binary->get_error_message() : null,
		)
	);
	if ( is_wp_error( $binary ) ) {
		return $binary;
	}
	$content_type = 'csv' === $filters['format'] ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
	papelito_admin_reports_output_download( $binary, 'produtos-' . wp_date( 'Y-m-d' ) . '.' . $filters['format'], $content_type );
}

/**
 * GET da contagem de produtos do recorte, antes do download.
 *
 * @param WP_REST_Request $request Filtros.
 */
function papelito_product_export_count_endpoint( WP_REST_Request $request ): WP_REST_Response {
	return new WP_REST_Response( array( 'count' => count( papelito_product_export_ids( papelito_product_export_filters( $request ) ) ) ), 200 );
}

/** Registra download e contagem, restritos a administradores. */
function papelito_product_export_register_routes(): void {
	$routes = array(
		'/reports/products/export' => 'papelito_product_export_endpoint',
		'/reports/products/count'  => 'papelito_product_export_count_endpoint',
	);
	foreach ( $routes as $route => $callback ) {
		register_rest_route(
			'papelito/v1/admin',
			$route,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => $callback,
				'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
			)
		);
	}
}

add_action( 'rest_api_init', 'papelito_product_export_register_routes' );
