<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

/**
 * Leitura da planilha de códigos do vendor: CSV e XLSX reais em arquivo temporário.
 *
 * Cobre ponto e vírgula com BOM, Windows-1252, cabeçalho fora de ordem e com
 * acento, código sempre como texto (zero à esquerda), XLSX gerado pelo
 * PhpSpreadsheet e as recusas de arquivo vazio, corrompido, sem colunas e de
 * formato não suportado.
 *
 * Execute no container: docker compose exec -T web php wp-content/plugins/plugin_papelito/tests/test-vendor-code-import-reader.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private string $code;
	private array $data;

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code = $code;
		$this->data = (array) $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( mixed $value ): string { return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function remove_accents( string $text ): string {
	return strtr( $text, array( 'á' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ç' => 'c', 'Á' => 'A', 'É' => 'E', 'Ç' => 'C', 'Ã' => 'A' ) );
}
function papelito_admin_reports_require_spreadsheet(): bool {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
	return true;
}

require_once dirname( __DIR__ ) . '/includes/vendor_code_import_reader.php';

const READER_TEST_HEADER = "subgrupo;cod produto;descricao\n";
const READER_TEST_BROWN  = 'SEDA PAPELITO BROWN KING SIZE';
const READER_TEST_CODE   = '00585';
const READER_TEST_TITLE  = "Relatorio de produtos\n";

$failures = 0;
$temp     = array();

function reader_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

/**
 * Grava o conteúdo num arquivo temporário e devolve o array de upload correspondente.
 */
function reader_file( string $content, string $name ): array {
	global $temp;
	$path   = tempnam( sys_get_temp_dir(), 'sku' );
	$temp[] = $path;
	file_put_contents( $path, $content );
	return array(
		'name'     => $name,
		'tmp_name' => $path,
		'size'     => strlen( $content ),
	);
}

function reader_error_code( mixed $result ): string {
	return is_wp_error( $result ) ? $result->get_error_code() : '';
}

$rows = papelito_vendor_code_import_read_file( reader_file( "\xEF\xBB\xBF" . READER_TEST_HEADER . "HEADSHOP - SEDA PAPELITO;" . READER_TEST_CODE . ";" . READER_TEST_BROWN . "\n;;\nHEADSHOP - TRITURADORES;6136;TRITURADOR PLASTICO PAPELITO CORES\n", 'estoque.csv' ) );
reader_assert( 'CSV com BOM e ponto e vírgula é lido', is_array( $rows ) && 2 === count( $rows ) );
reader_assert( 'código mantém zero à esquerda', READER_TEST_CODE === $rows[0]['code'] );
reader_assert( 'BOM não contamina o cabeçalho do subgrupo', 'HEADSHOP - SEDA PAPELITO' === $rows[0]['subgroup'] );
reader_assert( 'linha vazia é ignorada e a numeração segue o arquivo', 2 === $rows[0]['line'] && 4 === $rows[1]['line'] );

$rows = papelito_vendor_code_import_read_file( reader_file( "  Descrição ,COD PRODUTO\n\"TRITURADOR, PLASTICO\",6136\n", 'erp.csv' ) );
reader_assert( 'cabeçalho fora de ordem, com acento e caixa diferente', is_array( $rows ) && '6136' === $rows[0]['code'] && 'TRITURADOR, PLASTICO' === $rows[0]['description'] );
reader_assert( 'subgrupo é opcional', is_array( $rows ) && '' === $rows[0]['subgroup'] );

$rows = papelito_vendor_code_import_read_file( reader_file( mb_convert_encoding( "descricao;cod produto\nPITEIRA PAPEL ÚNICA;3078\n", 'Windows-1252', 'UTF-8' ), 'win.csv' ) );
reader_assert( 'CSV em Windows-1252 vira UTF-8', is_array( $rows ) && 'PITEIRA PAPEL ÚNICA' === $rows[0]['description'] );

$rows = papelito_vendor_code_import_read_file( reader_file( "Relatório de produtos\n\n" . READER_TEST_HEADER . "X;1;SEDA\n", 'titulo.csv' ) );
reader_assert( 'cabeçalho depois de linhas de título é encontrado', is_array( $rows ) && '1' === $rows[0]['code'] );

reader_assert( 'arquivo vazio é recusado', 'papelito_vendor_code_import_empty' === reader_error_code( papelito_vendor_code_import_read_file( reader_file( '', 'vazio.csv' ) ) ) );
reader_assert( 'só cabeçalho é recusado', 'papelito_vendor_code_import_empty' === reader_error_code( papelito_vendor_code_import_read_file( reader_file( READER_TEST_HEADER, 'cab.csv' ) ) ) );
reader_assert( 'sem a coluna de código é recusado', 'papelito_vendor_code_import_missing_columns' === reader_error_code( papelito_vendor_code_import_read_file( reader_file( "subgrupo;descricao\nX;Y\n", 'sem.csv' ) ) ) );
reader_assert( 'extensão não suportada é recusada', 'papelito_vendor_code_import_unsupported' === reader_error_code( papelito_vendor_code_import_read_file( reader_file( 'a', 'planilha.xls' ) ) ) );
reader_assert( 'xlsx que não é zip é recusado como corrompido', 'papelito_vendor_code_import_corrupted' === reader_error_code( papelito_vendor_code_import_read_file( reader_file( 'não sou zip', 'falso.xlsx' ) ) ) );
reader_assert( 'zip truncado é recusado como corrompido', 'papelito_vendor_code_import_corrupted' === reader_error_code( papelito_vendor_code_import_read_file( reader_file( "PK\x03\x04quebrado", 'quebrado.xlsx' ) ) ) );

$error = papelito_vendor_code_import_read_file( reader_file( '', 'vazio.csv' ) );
reader_assert( 'erro de leitura é marcado com a etapa read', is_wp_error( $error ) && 'read' === $error->get_error_data()['stage'] );

$many = READER_TEST_HEADER;
for ( $i = 1; $i <= PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS + 1; $i++ ) {
	$many .= "X;{$i};SEDA {$i}\n";
}
reader_assert( 'mais de 2.000 linhas é recusado', 'papelito_vendor_code_import_too_many_rows' === reader_error_code( papelito_vendor_code_import_read_file( reader_file( $many, 'grande.csv' ) ) ) );

papelito_admin_reports_require_spreadsheet();
$book  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->fromArray( array( 'descricao', 'subgrupo', 'cod produto' ), null, 'A1' );
$sheet->setCellValue( 'A2', READER_TEST_BROWN );
$sheet->setCellValue( 'B2', 'HEADSHOP - SEDA PAPELITO' );
$sheet->setCellValueExplicit( 'C2', READER_TEST_CODE, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING );
$sheet->setCellValue( 'A3', 'SEDA PAPELITO SLIM KING SIZE' );
$sheet->setCellValue( 'C3', 587 );
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $book );
ob_start();
$writer->save( 'php://output' );
$binary = (string) ob_get_clean();

$rows = papelito_vendor_code_import_read_file( reader_file( $binary, 'Estoque.XLSX' ) );
reader_assert( 'XLSX é lido com as colunas em outra ordem', is_array( $rows ) && 2 === count( $rows ) && READER_TEST_BROWN === $rows[0]['description'] );
reader_assert( 'XLSX preserva código de texto com zero à esquerda', is_array( $rows ) && READER_TEST_CODE === $rows[0]['code'] );
reader_assert( 'XLSX com código numérico sai como texto sem casa decimal', is_array( $rows ) && '587' === $rows[1]['code'] );


foreach ( array( ',' => 'vírgula', "\t" => 'tabulação' ) as $delimiter => $label ) {
	$rows = papelito_vendor_code_import_read_file( reader_file( READER_TEST_TITLE . "cod produto{$delimiter}descricao\n001{$delimiter}Seda Brown King Size\n", 'titulo.csv' ) );
	reader_assert( "título antes do cabeçalho com {$label} não esconde as colunas", is_array( $rows ) && '001' === $rows[0]['code'] && 'Seda Brown King Size' === $rows[0]['description'] );
}

/**
 * XLSX com cabeçalho e linhas numeradas, gerado em memória.
 */
function reader_xlsx( int $data_rows, ?string $far_cell = null ): string {
	$book  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
	$sheet = $book->getActiveSheet();
	$sheet->fromArray( array( 'cod produto', 'descricao' ), null, 'A1' );
	for ( $i = 2; $i <= $data_rows + 1; $i++ ) {
		$sheet->setCellValue( "A{$i}", (string) $i );
		$sheet->setCellValue( "B{$i}", "SEDA {$i}" );
	}
	if ( null !== $far_cell ) {
		$sheet->setCellValue( $far_cell, 'x' );
	}
	ob_start();
	( new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $book ) )->save( 'php://output' );
	return (string) ob_get_clean();
}

$memory_before = memory_get_usage();
$rows          = papelito_vendor_code_import_read_file( reader_file( reader_xlsx( 1500, 'XFD2' ), 'distante.xlsx' ) );
reader_assert( 'célula na última coluna não materializa 16 mil colunas', is_array( $rows ) && 1500 === count( $rows ) );
reader_assert( 'leitura do XLSX com célula distante fica abaixo de 64 MB', memory_get_peak_usage() - $memory_before < 64 * 1024 * 1024 );

reader_assert( 'XLSX com linhas demais é recusado antes de carregar', 'papelito_vendor_code_import_too_many_rows' === reader_error_code( papelito_vendor_code_import_read_file( reader_file( reader_xlsx( PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS + 20 ), 'grande.xlsx' ) ) ) );

$bomb_path = tempnam( sys_get_temp_dir(), 'bomb' );
$temp[]    = $bomb_path;
$bomb      = new ZipArchive();
$bomb->open( $bomb_path, ZipArchive::OVERWRITE );
$bomb->addFromString( 'xl/worksheets/sheet1.xml', str_repeat( '<c/>', 6 * 1024 * 1024 ) );
$bomb->close();
$bomb_size = (int) filesize( $bomb_path );
reader_assert( 'XLSX que descompacta além do limite é recusado', $bomb_size < PAPELITO_VENDOR_CODE_IMPORT_MAX_BYTES && 'papelito_vendor_code_import_too_large' === reader_error_code(
	papelito_vendor_code_import_read_file(
		array(
			'name'     => 'bomba.xlsx',
			'tmp_name' => $bomb_path,
			'size'     => $bomb_size,
		)
	)
) );

foreach ( $temp as $path ) {
	@unlink( $path );
}

if ( $failures > 0 ) {
	echo "\n{$failures} falha(s).\n";
	exit( 1 );
}
echo "OK: leitura da planilha de códigos.\n";
