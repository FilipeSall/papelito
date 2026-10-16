<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

/**
 * Limites de recurso na leitura da planilha de códigos do vendor.
 *
 * Roda com o mesmo teto de memória do PHP web (128 MB): CSV compacto com
 * centenas de milhares de linhas é recusado cedo, linha larga demais no CSV
 * é cortada na janela de colunas e XLSX com fórmula é recusado sem que a
 * fórmula seja calculada.
 *
 * Execute no container: docker compose exec -T web php wp-content/plugins/plugin_papelito/tests/test-vendor-code-import-limits.php
 */

define( 'ABSPATH', __DIR__ . '/' );
ini_set( 'memory_limit', '128M' );

class WP_Error {
	private string $code;
	private string $message;
	private array $data;

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = (array) $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( mixed $value ): string { return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function remove_accents( string $text ): string { return strtr( $text, array( 'ç' => 'c', 'ã' => 'a' ) ); }
function papelito_admin_reports_require_spreadsheet(): bool {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
	return true;
}

require_once dirname( __DIR__ ) . '/includes/vendor_code_import_reader.php';

const LIMITS_TEST_HEADER    = "cod produto;descricao\n";
const LIMITS_TEST_TOO_MANY  = 'papelito_vendor_code_import_too_many_rows';
const LIMITS_TEST_FORMULA   = 'papelito_vendor_code_import_formula';
const LIMITS_TEST_SEDA      = 'SEDA PAPELITO BROWN KING SIZE';
const LIMITS_TEST_EARLY_CAP = 32 * 1024 * 1024;

$failures = 0;
$temp     = array();

function limits_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

/**
 * Grava o conteúdo num arquivo temporário e devolve o array de upload correspondente.
 */
function limits_file( string $content, string $name ): array {
	global $temp;
	$path   = (string) tempnam( sys_get_temp_dir(), 'lim' );
	$temp[] = $path;
	file_put_contents( $path, $content );
	return array(
		'name'     => $name,
		'tmp_name' => $path,
		'size'     => strlen( $content ),
	);
}

function limits_error_code( mixed $result ): string {
	return is_wp_error( $result ) ? $result->get_error_code() : '';
}

/**
 * Lê o arquivo e devolve o resultado e quanto a leitura subiu o pico de memória.
 *
 * @return array{result:mixed,peak:int}
 */
function limits_read( array $file ): array {
	memory_reset_peak_usage();
	$before = memory_get_usage();
	$result = papelito_vendor_code_import_read_file( $file );
	return array(
		'result' => $result,
		'peak'   => memory_get_peak_usage() - $before,
	);
}

$compact = LIMITS_TEST_HEADER . str_repeat( "1;a\n", 400000 );
$read    = limits_read( limits_file( $compact, 'compacto.csv' ) );
unset( $compact );
limits_assert( 'CSV compacto com 400 mil linhas cabe no limite de 2 MB', 400000 * 4 < PAPELITO_VENDOR_CODE_IMPORT_MAX_BYTES );
limits_assert( 'CSV com 400 mil linhas é recusado com o erro de limite de linhas', LIMITS_TEST_TOO_MANY === limits_error_code( $read['result'] ) );
limits_assert( 'CSV com linhas demais é recusado antes de acumular a matriz', $read['peak'] < LIMITS_TEST_EARLY_CAP );

$blank_tail = LIMITS_TEST_HEADER . '585;' . LIMITS_TEST_SEDA . "\n" . str_repeat( ";\n", 5000 );
$rows       = papelito_vendor_code_import_read_file( limits_file( $blank_tail, 'rodape.csv' ) );
limits_assert( 'linhas só com separador não contam no limite de linhas', is_array( $rows ) && 1 === count( $rows ) );

$wide   = 'cod produto;descricao' . str_repeat( ';extra', 200 ) . "\n585;" . LIMITS_TEST_SEDA . str_repeat( ';x', 200 ) . "\n";
$matrix = papelito_vendor_code_import_csv_matrix( $wide );
limits_assert( 'CSV largo é cortado na mesma janela de colunas do XLSX', is_array( $matrix ) && PAPELITO_VENDOR_CODE_IMPORT_MAX_COLUMNS === max( array_map( 'count', $matrix ) ) );
$rows = papelito_vendor_code_import_read_file( limits_file( $wide, 'largo.csv' ) );
limits_assert( 'CSV largo continua lendo as colunas da janela', is_array( $rows ) && '585' === $rows[0]['code'] && LIMITS_TEST_SEDA === $rows[0]['description'] );

$many = LIMITS_TEST_HEADER;
for ( $i = 1; $i <= PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS; $i++ ) {
	$many .= "{$i};SEDA {$i}\n";
}
$rows = papelito_vendor_code_import_read_file( limits_file( $many, 'limite.csv' ) );
limits_assert( 'CSV com exatamente 2.000 linhas continua aceito', is_array( $rows ) && PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS === count( $rows ) );
limits_assert( 'CSV com 2.001 linhas é recusado', LIMITS_TEST_TOO_MANY === limits_error_code( papelito_vendor_code_import_read_file( limits_file( $many . "9999;SEDA\n", 'acima.csv' ) ) ) );

/**
 * XLSX com cabeçalho e uma linha cujo código é a fórmula dada, salvo sem pré-calcular.
 */
function limits_formula_xlsx( string $formula ): string {
	papelito_admin_reports_require_spreadsheet();
	$book  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
	$sheet = $book->getActiveSheet();
	$sheet->fromArray( array( 'cod produto', 'descricao' ), null, 'A1' );
	$sheet->setCellValue( 'A2', $formula );
	$sheet->setCellValue( 'B2', LIMITS_TEST_SEDA );
	$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $book );
	$writer->setPreCalculateFormulas( false );
	ob_start();
	$writer->save( 'php://output' );
	return (string) ob_get_clean();
}

$bomb = limits_formula_xlsx( '=REPT("a",200000000)' );
limits_assert( 'XLSX com fórmula expansiva cabe no limite de 2 MB', strlen( $bomb ) < PAPELITO_VENDOR_CODE_IMPORT_MAX_BYTES );
$read = limits_read( limits_file( $bomb, 'formula.xlsx' ) );
limits_assert( 'XLSX com fórmula expansiva é recusado sem calcular', LIMITS_TEST_FORMULA === limits_error_code( $read['result'] ) );
limits_assert( 'recusa da fórmula não aloca o texto expandido', $read['peak'] < LIMITS_TEST_EARLY_CAP );
limits_assert( 'recusa da fórmula é erro de leitura com status 422', is_wp_error( $read['result'] ) && 422 === $read['result']->get_error_data()['status'] && 'read' === $read['result']->get_error_data()['stage'] );
limits_assert( 'recusa da fórmula aponta a célula para o vendor corrigir', is_wp_error( $read['result'] ) && str_contains( $read['result']->get_error_message(), 'A2' ) );

$read = limits_read( limits_file( limits_formula_xlsx( '="00"&"585"' ), 'formula-curta.xlsx' ) );
limits_assert( 'fórmula inofensiva também é recusada: o valor gravado nunca sai de cálculo', LIMITS_TEST_FORMULA === limits_error_code( $read['result'] ) );

foreach ( $temp as $path ) {
	@unlink( $path );
}

if ( $failures > 0 ) {
	echo "\n{$failures} falha(s).\n";
	exit( 1 );
}
echo "OK: limites de recurso na leitura da planilha.\n";
