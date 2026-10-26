<?php
/**
 * Leitura da planilha de códigos do vendor (CSV ou XLSX) em linhas neutras.
 *
 * Aceita as colunas em qualquer ordem e tolera cabeçalho com acento, caixa e
 * espaço diferentes. Todo valor sai como texto: o código do ERP nunca vira
 * número, para não perder zero à esquerda. Não associa produto nem grava nada;
 * o arquivo é lido do diretório temporário do upload e descartado pelo PHP.
 *
 * A leitura neutra do arquivo — limites, encoding, delimitador, XLSX sem fórmula —
 * é compartilhada com a importação de produtos do admin por
 * `papelito_vendor_code_import_read_matrix()`; só o reconhecimento do cabeçalho é
 * de cada importação.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_CODE_IMPORT_MAX_BYTES          = 2 * 1024 * 1024;
const PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS           = 2000;
const PAPELITO_VENDOR_CODE_IMPORT_HEADER_SCAN        = 10;
const PAPELITO_VENDOR_CODE_IMPORT_MAX_COLUMNS        = 30;
const PAPELITO_VENDOR_CODE_IMPORT_MAX_EXPANDED_BYTES = 20 * 1024 * 1024;

/**
 * Erro de leitura do arquivo, marcado com a etapa para o painel distinguir de associação e gravação.
 *
 * @param string $code    Código do erro.
 * @param string $message Mensagem exibível ao vendor.
 * @param int    $status  Status HTTP.
 */
function papelito_vendor_code_import_read_error( string $code, string $message, int $status = 422 ): WP_Error {
	return new WP_Error(
		$code,
		$message,
		array(
			'status' => $status,
			'stage'  => 'read',
		)
	);
}

/**
 * Recusa de planilha com mais linhas do que a importação aceita, a mesma para CSV e XLSX.
 */
function papelito_vendor_code_import_too_many_rows_error(): WP_Error {
	return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_too_many_rows', 'A planilha tem mais de 2.000 linhas. Divida o arquivo e importe em partes.', 413 );
}

/**
 * Cabeçalhos aceitos por coluna, já normalizados.
 *
 * @return array<string,string[]>
 */
function papelito_vendor_code_import_header_aliases(): array {
	return array(
		'code'        => array( 'cod produto', 'codigo produto', 'cod do produto', 'codigo do produto', 'cod prod', 'cod', 'codigo', 'sku' ),
		'description' => array( 'descricao', 'descricao produto', 'descricao do produto', 'produto', 'nome', 'nome do produto' ),
		'subgroup'    => array( 'subgrupo', 'sub grupo', 'grupo', 'categoria' ),
	);
}

/**
 * Cabeçalho comparável: sem acento, minúsculo, pontuação vira espaço.
 *
 * @param mixed $value Célula do cabeçalho.
 */
function papelito_vendor_code_import_header_key( mixed $value ): string {
	$text = mb_strtolower( remove_accents( is_scalar( $value ) ? (string) $value : '' ), 'UTF-8' );
	return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
}

/**
 * Mapa coluna → índice quando a linha é o cabeçalho; vazio quando não é.
 *
 * @param array<int,mixed> $cells Células da linha.
 * @return array<string,int>
 */
function papelito_vendor_code_import_header_map( array $cells ): array {
	$map = array();
	foreach ( array_values( $cells ) as $index => $cell ) {
		$key = papelito_vendor_code_import_header_key( $cell );
		foreach ( papelito_vendor_code_import_header_aliases() as $column => $aliases ) {
			if ( ! isset( $map[ $column ] ) && in_array( $key, $aliases, true ) ) {
				$map[ $column ] = $index;
			}
		}
	}
	return $map;
}

/**
 * Procura o cabeçalho nas dez primeiras linhas e converte o resto em linhas da importação.
 *
 * @param array<int,array<int,mixed>> $matrix Linhas da planilha, na ordem do arquivo.
 * @return array<int,array{line:int,description:string,subgroup:string,code:string}>|WP_Error
 */
function papelito_vendor_code_import_rows_from_matrix( array $matrix ): array|WP_Error {
	$matrix = array_values( $matrix );
	foreach ( array_slice( $matrix, 0, PAPELITO_VENDOR_CODE_IMPORT_HEADER_SCAN ) as $offset => $cells ) {
		$map = papelito_vendor_code_import_header_map( (array) $cells );
		if ( isset( $map['code'], $map['description'] ) ) {
			return papelito_vendor_code_import_collect_rows( array_slice( $matrix, $offset + 1 ), $map, $offset + 2 );
		}
	}
	return papelito_vendor_code_import_read_error(
		'papelito_vendor_code_import_missing_columns',
		'A planilha precisa das colunas "cod produto" e "descricao" (o "subgrupo" é opcional).'
	);
}

/**
 * Converte as linhas de dados, ignorando as totalmente vazias.
 *
 * @param array<int,array<int,mixed>> $data       Linhas depois do cabeçalho.
 * @param array<string,int>           $map        Índices das colunas.
 * @param int                         $first_line Número da primeira linha de dados no arquivo.
 * @return array<int,array{line:int,description:string,subgroup:string,code:string}>|WP_Error
 */
function papelito_vendor_code_import_collect_rows( array $data, array $map, int $first_line ): array|WP_Error {
	$rows = array();
	foreach ( $data as $offset => $cells ) {
		$cells = array_values( (array) $cells );
		$row   = array(
			'line'        => $first_line + $offset,
			'description' => papelito_vendor_code_import_cell_text( $cells[ $map['description'] ] ?? '' ),
			'subgroup'    => isset( $map['subgroup'] ) ? papelito_vendor_code_import_cell_text( $cells[ $map['subgroup'] ] ?? '' ) : '',
			'code'        => papelito_vendor_code_import_cell_text( $cells[ $map['code'] ] ?? '' ),
		);
		if ( '' === $row['description'] && '' === $row['code'] ) {
			continue;
		}
		if ( count( $rows ) >= PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS ) {
			return papelito_vendor_code_import_too_many_rows_error();
		}
		$rows[] = $row;
	}
	if ( ! $rows ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_empty', 'A planilha não tem nenhuma linha de produto.' );
	}
	return $rows;
}

/**
 * Célula como texto limpo; número inteiro sai sem casa decimal.
 *
 * @param mixed $value Valor lido do arquivo.
 */
function papelito_vendor_code_import_cell_text( mixed $value ): string {
	if ( is_float( $value ) && floor( $value ) === $value ) {
		$value = (string) (int) $value;
	}
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$text = (string) $value;
	if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
		return '';
	}
	return mb_substr( sanitize_text_field( $text ), 0, 200, 'UTF-8' );
}

/**
 * Delimitador que revela o cabeçalho: testa `;`, `,` e tabulação nas linhas onde o cabeçalho
 * pode estar e fica com o primeiro que encontra código e descrição.
 *
 * Linha de título antes do cabeçalho não tem separador nenhum, então contar separadores só
 * na primeira linha escolheria às cegas. Sem cabeçalho reconhecível, cai no mais frequente
 * da primeira linha, e a leitura acusa a coluna ausente.
 *
 * @param string $content CSV já em UTF-8.
 */
function papelito_vendor_code_import_csv_delimiter( string $content ): string {
	$candidates = papelito_vendor_code_import_csv_head_lines( $content );
	foreach ( array( ';', ',', "\t" ) as $delimiter ) {
		foreach ( $candidates as $line ) {
			$map = papelito_vendor_code_import_header_map( str_getcsv( $line, $delimiter, '"', '' ) );
			if ( isset( $map['code'], $map['description'] ) ) {
				return $delimiter;
			}
		}
	}
	$first  = (string) ( $candidates[0] ?? '' );
	$counts = array(
		';'  => substr_count( $first, ';' ),
		','  => substr_count( $first, ',' ),
		"\t" => substr_count( $first, "\t" ),
	);
	arsort( $counts );
	return (string) array_key_first( $counts );
}

/**
 * Primeiras linhas não vazias do CSV, onde o cabeçalho pode estar.
 *
 * Para na décima: quebrar o arquivo inteiro em linhas custaria uma string por registro, e um
 * CSV de 2 MB com registros de quatro bytes tem meio milhão deles.
 *
 * @param string $content CSV já em UTF-8.
 * @return string[]
 */
function papelito_vendor_code_import_csv_head_lines( string $content ): array {
	$lines = array();
	$found = 0;
	$line  = strtok( $content, "\r\n" );
	while ( false !== $line && $found < PAPELITO_VENDOR_CODE_IMPORT_HEADER_SCAN ) {
		if ( '' !== trim( $line ) ) {
			$lines[] = $line;
			++$found;
		}
		$line = strtok( "\r\n" );
	}
	return $lines;
}

/**
 * Diz se a linha do CSV só tem células em branco, como o `;;` que o Excel exporta no rodapé.
 *
 * @param array<int,?string> $cells Células da linha.
 */
function papelito_vendor_code_import_csv_row_is_blank( array $cells ): bool {
	foreach ( $cells as $cell ) {
		if ( '' !== trim( (string) $cell ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Lê CSV com ou sem BOM, em UTF-8 ou Windows-1252 (padrão do Excel em português).
 *
 * Lê registro a registro e recusa assim que as linhas preenchidas passam da janela do XLSX
 * (limite de linhas mais a busca do cabeçalho): acumular o arquivo inteiro antes de contar
 * esgota a memória com CSV compacto de centenas de milhares de registros. Linha em branco
 * entra como vazia, sem contar no limite, para a numeração continuar a do arquivo; cada
 * linha é cortada na mesma janela de colunas do XLSX.
 *
 * @param string        $content       Bytes do arquivo.
 * @param callable|null $delimiter_for Recebe o CSV em UTF-8 e devolve o delimitador; sem ele,
 *                                     vale o que reconhece o cabeçalho da planilha de códigos.
 * @return array<int,array<int,string>>|WP_Error
 */
function papelito_vendor_code_import_csv_matrix( string $content, ?callable $delimiter_for = null ): array|WP_Error {
	$delimiter_for ??= 'papelito_vendor_code_import_csv_delimiter';

	$content = str_starts_with( $content, "\xEF\xBB\xBF" ) ? substr( $content, 3 ) : $content;
	if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
		$content = (string) mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
	}
	if ( '' === trim( $content ) ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_empty', 'O arquivo enviado está vazio.' );
	}
	$stream = new SplTempFileObject();
	$stream->fwrite( $content );
	$stream->rewind();
	$stream->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD );
	$stream->setCsvControl( (string) $delimiter_for( $content ), '"', '' );
	$matrix = array();
	$filled = 0;
	foreach ( $stream as $cells ) {
		if ( ! is_array( $cells ) || array( null ) === $cells ) {
			continue;
		}
		if ( papelito_vendor_code_import_csv_row_is_blank( $cells ) ) {
			$matrix[] = array();
			continue;
		}
		if ( ++$filled > PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS + PAPELITO_VENDOR_CODE_IMPORT_HEADER_SCAN ) {
			return papelito_vendor_code_import_too_many_rows_error();
		}
		$matrix[] = array_slice( $cells, 0, PAPELITO_VENDOR_CODE_IMPORT_MAX_COLUMNS );
	}
	return $matrix;
}

/**
 * Lê os primeiros bytes do arquivo temporário do upload; vazio quando não consegue.
 *
 * @param string $path   Caminho temporário.
 * @param int    $length Quantidade de bytes; 0 lê o arquivo inteiro.
 */
function papelito_vendor_code_import_read_bytes( string $path, int $length ): string {
	try {
		$file = new SplFileObject( $path, 'rb' );
		$size = $length > 0 ? $length : max( 1, (int) $file->getSize() );
		return (string) $file->fread( $size );
	} catch ( Throwable ) {
		return '';
	}
}

/**
 * Lê a primeira aba do XLSX com os valores formatados, preservando código em texto.
 *
 * @param string $path Caminho temporário do upload.
 * @return array<int,array<int,mixed>>|WP_Error
 */
function papelito_vendor_code_import_xlsx_matrix( string $path ): array|WP_Error {
	if ( "PK\x03\x04" !== papelito_vendor_code_import_read_bytes( $path, 4 ) ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_corrupted', 'O arquivo .xlsx está corrompido ou não é uma planilha do Excel.' );
	}
	$expanded = papelito_vendor_code_import_xlsx_expanded_bytes( $path );
	if ( null === $expanded ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_corrupted', 'O arquivo .xlsx está corrompido ou não pôde ser lido.' );
	}
	if ( $expanded > PAPELITO_VENDOR_CODE_IMPORT_MAX_EXPANDED_BYTES ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_too_large', 'A planilha descompactada passa de 20 MB. Remova abas e formatações extras e envie de novo.', 413 );
	}
	$ready = papelito_admin_reports_require_spreadsheet();
	if ( is_wp_error( $ready ) ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_unavailable', 'A leitura de planilhas está indisponível no momento.', 500 );
	}
	try {
		return papelito_vendor_code_import_xlsx_bounded_matrix( $path );
	} catch ( Throwable ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_corrupted', 'O arquivo .xlsx está corrompido ou não pôde ser lido.' );
	}
}

/**
 * Soma dos tamanhos descompactados declarados no XLSX; null quando o ZIP não abre.
 *
 * O limite de 2 MB vale para o arquivo comprimido; XML repetitivo descompacta centenas de
 * vezes maior e seria carregado inteiro antes de qualquer outra checagem.
 *
 * @param string $path Caminho temporário do upload.
 */
function papelito_vendor_code_import_xlsx_expanded_bytes( string $path ): ?int {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
		return null;
	}
	$total   = 0;
	$entries = $zip->count();
	for ( $index = 0; $index < $entries; $index++ ) {
		$total += (int) ( $zip->statIndex( $index )['size'] ?? 0 );
	}
	$zip->close();
	return $total;
}

/**
 * Filtro de leitura do PhpSpreadsheet: só a aba escolhida, as primeiras colunas e as linhas que cabem no limite.
 *
 * O tamanho do arquivo não limita a memória: uma única célula em `XFD2` faria a planilha
 * ter 16.384 colunas em cada linha. Com o filtro, célula fora da janela nem é carregada.
 *
 * @param string $sheet    Nome da aba lida.
 * @param int    $max_rows Última linha lida.
 */
function papelito_vendor_code_import_read_filter( string $sheet, int $max_rows ): \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
	return new class( $sheet, $max_rows ) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
		/**
		 * Janela de leitura: aba e última linha.
		 *
		 * @param string $sheet    Nome da aba lida.
		 * @param int    $max_rows Última linha lida.
		 */
		public function __construct( private string $sheet, private int $max_rows ) {}

		/**
		 * Lê a célula só dentro da aba e da janela de colunas e linhas.
		 *
		 * @param string $column_address Coluna (`A`, `B`…).
		 * @param int    $row            Linha.
		 * @param string $worksheet_name Aba.
		 */
		public function readCell( string $column_address, int $row, string $worksheet_name = '' ): bool {
			return ( '' === $worksheet_name || $this->sheet === $worksheet_name )
				&& $row <= $this->max_rows
				&& \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $column_address ) <= PAPELITO_VENDOR_CODE_IMPORT_MAX_COLUMNS;
		}
	};
}

/**
 * Endereço da primeira célula com fórmula na janela lida; null quando não há nenhuma.
 *
 * Só inspeciona o tipo da célula, sem calcular: `=REPT("a",200000000)` num XLSX de poucos KB
 * esgota a memória do PHP se for avaliado.
 *
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Aba carregada pelo filtro de leitura.
 */
function papelito_vendor_code_import_xlsx_formula_cell( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet ): ?string {
	foreach ( $sheet->getCoordinates() as $coordinate ) {
		if ( $sheet->getCell( $coordinate )->isFormula() ) {
			return $coordinate;
		}
	}
	return null;
}

/**
 * Carrega só a primeira aba, dentro da janela de colunas e linhas, e devolve os valores formatados.
 *
 * A contagem de linhas vem de `listWorksheetInfo()`, que percorre o XML sem montar a planilha:
 * aba com linhas demais é recusada antes de qualquer célula ir para a memória.
 *
 * Fórmula nunca é calculada: o arquivo é do vendor e o cálculo roda no PHP do servidor. Célula
 * com fórmula recusa a planilha inteira em vez de usar o valor em cache, que o próprio arquivo
 * declara e pode não corresponder ao que o Excel mostra.
 *
 * @param string $path Caminho temporário do upload.
 * @return array<int,array<int,mixed>>|WP_Error
 */
function papelito_vendor_code_import_xlsx_bounded_matrix( string $path ): array|WP_Error {
	$max_rows = PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS + PAPELITO_VENDOR_CODE_IMPORT_HEADER_SCAN;
	$reader   = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
	$info     = $reader->listWorksheetInfo( $path )[0] ?? null;
	if ( ! is_array( $info ) ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_empty', 'A planilha não tem nenhuma aba com dados.' );
	}
	if ( (int) $info['totalRows'] > $max_rows ) {
		return papelito_vendor_code_import_too_many_rows_error();
	}
	$reader->setLoadSheetsOnly( array( (string) $info['worksheetName'] ) );
	$reader->setReadEmptyCells( false );
	$reader->setReadFilter( papelito_vendor_code_import_read_filter( (string) $info['worksheetName'], $max_rows ) );
	$book    = $reader->load( $path );
	$sheet   = $book->getSheet( 0 );
	$formula = papelito_vendor_code_import_xlsx_formula_cell( $sheet );
	if ( null !== $formula ) {
		$book->disconnectWorksheets();
		return papelito_vendor_code_import_read_error(
			'papelito_vendor_code_import_formula',
			sprintf( 'A planilha tem fórmula na célula %s. Copie as colunas e cole como valores antes de enviar.', $formula )
		);
	}
	$columns = min( PAPELITO_VENDOR_CODE_IMPORT_MAX_COLUMNS, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $sheet->getHighestDataColumn() ) );
	$rows    = min( $max_rows, $sheet->getHighestDataRow() );
	$range   = 'A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $columns ) . $rows;
	$matrix  = $sheet->rangeToArray( $range, null, false, true, false );
	$book->disconnectWorksheets();
	return $matrix;
}

/**
 * Valida o upload e devolve a planilha como matriz de células, sem interpretar cabeçalho.
 *
 * É a parte da leitura que não depende de qual importação está lendo: extensão, tamanho,
 * encoding e as defesas do XLSX. Quem chama reconhece o próprio cabeçalho na matriz.
 *
 * @param array{name?:string,tmp_name?:string,size?:int} $file          Arquivo recebido pelo upload direto.
 * @param callable|null                                  $delimiter_for Detector de delimitador do CSV.
 * @return array<int,array<int,mixed>>|WP_Error
 */
function papelito_vendor_code_import_read_matrix( array $file, ?callable $delimiter_for = null ): array|WP_Error {
	$extension = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
	$path      = (string) ( $file['tmp_name'] ?? '' );
	if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_unsupported', 'Envie uma planilha .csv ou .xlsx.', 415 );
	}
	$size = (int) ( $file['size'] ?? 0 );
	if ( $size <= 0 || '' === $path || ! is_readable( $path ) ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_empty', 'O arquivo enviado está vazio.' );
	}
	if ( $size > PAPELITO_VENDOR_CODE_IMPORT_MAX_BYTES ) {
		return papelito_vendor_code_import_read_error( 'papelito_vendor_code_import_too_large', 'A planilha excede o limite de 2 MB.', 413 );
	}
	return 'xlsx' === $extension
		? papelito_vendor_code_import_xlsx_matrix( $path )
		: papelito_vendor_code_import_csv_matrix( papelito_vendor_code_import_read_bytes( $path, 0 ), $delimiter_for );
}

/**
 * Valida o upload e devolve as linhas da planilha de códigos.
 *
 * @param array{name?:string,tmp_name?:string,size?:int} $file Arquivo recebido pelo upload direto.
 * @return array<int,array{line:int,description:string,subgroup:string,code:string}>|WP_Error
 */
function papelito_vendor_code_import_read_file( array $file ): array|WP_Error {
	$matrix = papelito_vendor_code_import_read_matrix( $file );
	return is_wp_error( $matrix ) ? $matrix : papelito_vendor_code_import_rows_from_matrix( $matrix );
}
