<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

/**
 * Regras da importação de produtos do admin: leitura do cabeçalho, validação por linha,
 * duplicidade no catálogo e no próprio arquivo, e o resumo da prévia.
 *
 * Execute no container: docker compose exec -T web php wp-content/plugins/plugin_papelito/tests/test-admin-product-import-rules.php
 */

define( 'ABSPATH', __DIR__ . '/' );

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
function sanitize_textarea_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function remove_accents( string $text ): string {
	return strtr( $text, array( 'á' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c', 'Á' => 'A', 'É' => 'E', 'Ç' => 'C', 'Ã' => 'A' ) );
}
function papelito_admin_reports_require_spreadsheet(): bool { return true; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }

require_once dirname( __DIR__ ) . '/includes/vendor_code_import_reader.php';
require_once dirname( __DIR__ ) . '/includes/vendor_code_import_dictionary.php';
require_once dirname( __DIR__ ) . '/includes/admin_product_import_rules.php';

const IMPORT_TEST_HEADER    = "\xEF\xBB\xBFsku;Nome;Categoria;Subcategorias;Preço regular;preco_promocional;peso_kg;comprimento_cm;largura_cm;altura_cm;colecoes\n";
const IMPORT_TEST_EXISTING  = 'Seda Tradicional King Size Display c/ 50 Livretos';
const IMPORT_TEST_BROWN     = 'Seda Brown Slim Display c/ 50 Livretos';
const IMPORT_TEST_SHIPPING  = '0,32;14;9;6';
const IMPORT_TEST_FILE_NAME = 'produtos.csv';
const IMPORT_TEST_NEON      = 'Dichavador Neon';

$failures = 0;

function import_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

/**
 * Lê o CSV como o upload faria: arquivo temporário, leitura neutra e reconhecimento do cabeçalho.
 */
function import_table( string $content ): array|WP_Error {
	$path = tempnam( sys_get_temp_dir(), 'pimp' );
	file_put_contents( $path, $content );
	$matrix = papelito_vendor_code_import_read_matrix(
		array(
			'name'     => IMPORT_TEST_FILE_NAME,
			'tmp_name' => $path,
			'size'     => strlen( $content ),
		),
		'papelito_product_import_csv_delimiter'
	);
	unlink( $path );
	return is_wp_error( $matrix ) ? $matrix : papelito_product_import_table_from_matrix( $matrix );
}

/**
 * Contexto com as quatro categorias do seed, a coleção premium e um produto já cadastrado.
 */
function import_context(): array {
	$terms    = papelito_product_import_terms( papelito_vendor_code_import_dictionary() );
	$category = static function ( int $id, string $slug, string $name, array $subs ) use ( $terms ): array {
		$index = array();
		foreach ( $subs as $sub_id => list( $sub_slug, $sub_name ) ) {
			foreach ( papelito_product_import_index_keys( array( $sub_slug, $sub_name ), $terms ) as $key ) {
				$index[ $key ] ??= array(
					'id'   => $sub_id,
					'slug' => $sub_slug,
					'name' => $sub_name,
				);
			}
		}
		return array(
			'id'   => $id,
			'slug' => $slug,
			'name' => $name,
			'subs' => $index,
		);
	};
	$entries = array(
		$category( 1, 'sedas', 'Sedas', array( 11 => array( 'brown', 'Brown' ), 12 => array( 'slim', 'Slim' ), 13 => array( 'king-size', 'King Size' ), 14 => array( 'tradicional', 'Tradicional' ) ) ),
		$category( 2, 'piteiras', 'Piteiras', array( 21 => array( 'large', 'Large' ), 22 => array( 'slim', 'Slim' ) ) ),
		$category( 3, 'filtros', 'Filtros', array( 31 => array( 'gomado', 'Gomado' ) ) ),
		$category( 4, 'acessorios', 'Acessórios', array( 41 => array( 'dichavador', 'Dichavador' ), 42 => array( 'cinzeiro', 'Cinzeiro' ) ) ),
	);
	$categories = array();
	foreach ( $entries as $entry ) {
		foreach ( papelito_product_import_index_keys( array( $entry['slug'], $entry['name'] ), $terms ) as $key ) {
			$categories[ $key ] ??= $entry;
		}
	}

	return array(
		'terms'       => $terms,
		'categories'  => $categories,
		'collections' => array( 'premium' ),
		'catalog'     => array(
			1 => array(
				papelito_product_import_canonical( IMPORT_TEST_EXISTING, $terms ) => array(
					'id'   => 811,
					'sku'  => 'PP01010004',
					'name' => IMPORT_TEST_EXISTING,
				),
			),
			4 => array(
				papelito_product_import_canonical( IMPORT_TEST_NEON, $terms ) => array(
					'id'   => 11849,
					'sku'  => 'PPL-011849',
					'name' => IMPORT_TEST_NEON,
				),
			),
		),
	);
}

$csv = IMPORT_TEST_HEADER
	. '999;' . IMPORT_TEST_BROWN . ';sedas;brown|slim;84,90;;' . IMPORT_TEST_SHIPPING . ";\n"
	. ';Piteira Ultra Longa Display;Piteiras;;61,00;;;;;;' . "\n"
	. ';SEDA TRADICIONAL KING-SIZE DISPLAY C/ 50 LIVRETOS;sedas;tradicional|King Size;90,09;;' . IMPORT_TEST_SHIPPING . ";\n"
	. ';' . IMPORT_TEST_BROWN . ';sedas;;80,00;;' . IMPORT_TEST_SHIPPING . ";\n"
	. ";Filtro Gomado Longo;filtroz;gomado;49,90;;0,29;15;10;7;\n"
	. ";;sedas;;29,90;;0,40;12;12;4;\n"
	. ";Seda Hemp Slim;sedas;slim|large;79,90;;0,31;14;9;6;\n"
	. ";Seda Insane Pink;sedas;;0;;0,33;16;10;6;\n"
	. ";Piteira Slim;piteiras;slim;46,90;49,90;0,20;14;9;5;\n"
	. ";Seda Alfafa;sedas;;R$ 1.234,56;;" . IMPORT_TEST_SHIPPING . ";vitrine\n"
	. ";;;;;;;;;;\n"
	. ";Seda Pink Mini;sedas;;R$ 69;R$ 59,90;0.25;12;8;6;premium\n"
	. ";Triturador Metal 4 Partes;triturador;;39,90;;0,12;6;6;5;\n"
	. ";Filtro Gomado Curto;Filtro;gomado;45,00;;0,29;15;10;7;\n"
	. ";Seda Brown KS;sedas;brown|ks;79,00;;0,31;14;9;6;\n"
	. ";Triturador Neon;acessorio;;39,90;;0,12;6;6;5;\n";

$table = import_table( $csv );
import_assert( 'lê o CSV com BOM, ponto e vírgula e cabeçalho com acento', is_array( $table ) && 15 === count( $table['rows'] ) );
import_assert( 'coluna sku é reconhecida como ignorada', is_array( $table ) && null === $table['columns'][0]['field'] && 'nome' === $table['columns'][1]['field'] );
import_assert( 'linha em branco não entra e a numeração segue a do arquivo', is_array( $table ) && 13 === $table['rows'][10]['line'] );
import_assert( 'forma canônica troca sinônimo e grafia', 'dichavador king size longa' === papelito_product_import_canonical( 'Triturador KS Longo', papelito_product_import_terms( papelito_vendor_code_import_dictionary() ) ) );

$analysis = papelito_product_import_analyze( $table, import_context() );
$by_line  = array_column( $analysis['rows'], null, 'line' );
$messages = static fn ( int $line ): string => implode( ' | ', array_column( $by_line[ $line ]['issues'], 'message' ) );

import_assert( 'linha completa é nova', 'novo' === $by_line[2]['status'] && array( 11, 12 ) === $by_line[2]['subcategoryIds'] );
import_assert( 'sem peso e medidas entra incompleto', 'incompleto' === $by_line[3]['status'] && $by_line[3]['incomplete'] );
import_assert( 'categoria aceita pelo nome com caixa diferente', 2 === $by_line[3]['category']['id'] );
import_assert( 'mesmo nome e categoria do catálogo é duplicado, ignorando caixa e hífen', 'duplicado' === $by_line[4]['status'] && 811 === $by_line[4]['duplicateOf']['id'] );
import_assert( 'subcategoria aceita pelo nome', in_array( 13, $by_line[4]['subcategoryIds'], true ) );
import_assert( 'segunda ocorrência no arquivo é repetida', 'repetida' === $by_line[5]['status'] && 2 === $by_line[5]['repeatOf'] );
import_assert( 'categoria inexistente sugere a parecida', 'erro' === $by_line[6]['status'] && str_contains( $messages( 6 ), '“filtros”' ) );
import_assert( 'tipo de produto vira categoria e subcategoria, com nota', 'novo' === $by_line[14]['status'] && 4 === $by_line[14]['category']['id'] && array( 41 ) === $by_line[14]['subcategoryIds'] && str_contains( $messages( 14 ), 'lida como Acessórios, subcategoria Dichavador' ) );
import_assert( 'categoria no singular é aceita sem nota', 'novo' === $by_line[15]['status'] && 3 === $by_line[15]['category']['id'] && '' === $messages( 15 ) );
import_assert( 'subcategoria por sinônimo é aceita com nota', in_array( 13, $by_line[16]['subcategoryIds'], true ) && str_contains( $messages( 16 ), '“ks” lida como King Size' ) );
import_assert( 'sinônimo no nome encontra o produto do catálogo', 'duplicado' === $by_line[17]['status'] && 11849 === $by_line[17]['duplicateOf']['id'] && str_contains( $messages( 17 ), 'grafias e sinônimos' ) );
import_assert( 'sem nome é erro', 'erro' === $by_line[7]['status'] && str_contains( $messages( 7 ), 'Sem nome' ) );
import_assert( 'subcategoria de outra categoria é erro', 'erro' === $by_line[8]['status'] && str_contains( $messages( 8 ), '“large”' ) );
import_assert( 'preço zero é erro', 'erro' === $by_line[9]['status'] && str_contains( $messages( 9 ), 'maior que zero' ) );
import_assert( 'promocional maior que o regular é erro', 'erro' === $by_line[10]['status'] && str_contains( $messages( 10 ), 'menor que o regular' ) );
import_assert( 'coleção desconhecida é erro', 'erro' === $by_line[11]['status'] && str_contains( $messages( 11 ), '“vitrine”' ) );
import_assert( 'preço com R$ e milhar é lido', abs( 1234.56 - (float) $by_line[11]['price'] ) < 0.001 );
import_assert( 'preço inteiro com R$, promoção e peso com ponto', 'novo' === $by_line[13]['status'] && 59.9 === $by_line[13]['salePrice'] && 0.25 === $by_line[13]['shipping']['peso_kg'] && array( 'premium' ) === $by_line[13]['collections'] );
import_assert(
	'resumo conta cada situação uma vez',
	array(
		'novo'       => 5,
		'incompleto' => 1,
		'duplicado'  => 2,
		'repetida'   => 1,
		'erro'       => 6,
		'total'      => 15,
	) === $analysis['summary']
);
import_assert( 'linha pública leva as células cruas do arquivo', '999' === papelito_product_import_public_row( $by_line[2], $table['rows'][0]['cells'] )['cells'][0] );

$missing = import_table( "Produto;Grupo;Valor unitário\nSeda;SEDAS;10\n" );
$data    = is_wp_error( $missing ) ? $missing->get_error_data() : array();
import_assert( 'sem colunas obrigatórias recusa com a lista do que falta', is_wp_error( $missing ) && 'papelito_product_import_missing_columns' === $missing->get_error_code() && array( 'categoria', 'preco_regular' ) === $data['missing'] );
import_assert( 'a recusa descreve o cabeçalho lido', 'nome' === ( $data['columns'][0]['field'] ?? null ) && 'Grupo' === $data['columns'][1]['header'] && null === $data['columns'][1]['field'] );

$only_header = import_table( IMPORT_TEST_HEADER );
import_assert( 'arquivo só com cabeçalho é recusado', is_wp_error( $only_header ) && 'papelito_product_import_empty' === $only_header->get_error_code() );

import_assert( 'decimal vazio é null', null === papelito_product_import_decimal( '' ) );
import_assert( 'decimal inválido é false', false === papelito_product_import_decimal( 'dez' ) );
import_assert( 'decimal brasileiro com milhar', 1234.5 === papelito_product_import_decimal( '1.234,50' ) );

if ( $failures > 0 ) {
	echo "{$failures} falha(s).\n";
	exit( 1 );
}
echo "OK: regras da importação de produtos.\n";
