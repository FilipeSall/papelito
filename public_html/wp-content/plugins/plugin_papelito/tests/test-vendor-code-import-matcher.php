<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

/**
 * Associação da planilha do ERP ao catálogo, sem banco.
 *
 * Fixtures com os títulos e descrições reais do catálogo Papelito. Cobre
 * correspondência exata, sinônimo, tamanhos e combos que não podem se anular,
 * quantidade do display, revisão sem escolha automática, cor de variação,
 * zeros à esquerda, linhas repetidas e conflitos.
 *
 * Execute no container: docker compose exec -T web php wp-content/plugins/plugin_papelito/tests/test-vendor-code-import-matcher.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( mixed $value ): string { return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function wp_strip_all_tags( mixed $value ): string { return strip_tags( (string) $value ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }
function add_action( mixed ...$args ): bool { return true; }
function remove_accents( string $text ): string {
	return strtr( $text, array( 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ç' => 'C', 'Ã' => 'A', 'Õ' => 'O' ) );
}

require_once dirname( __DIR__ ) . '/includes/vendor_item_settings.php';
require_once dirname( __DIR__ ) . '/includes/vendor_item_settings_rest.php';
require_once dirname( __DIR__ ) . '/includes/vendor_code_import_dictionary.php';
require_once dirname( __DIR__ ) . '/includes/vendor_code_import_matcher.php';
require_once dirname( __DIR__ ) . '/includes/vendor_code_import.php';

const IMPORT_TEST_SEDA_CONTENT  = 'Informações Técnicas: King Size - 14 g/m2 gramatura | Tamanho Papel - 108x44mm | 25 Livretos por Display';
const IMPORT_TEST_FILTRO_SHORT  = 'Tamanho: 6 x 15mm | 120 filtros por bag | 10 Bags por Display';
const IMPORT_TEST_FILTRO_LONG   = 'Tamanho: 6 x 22mm | 120 filtros por bag | 10 Bags por Display';
const IMPORT_TEST_SEDA_SUBGROUP = 'HEADSHOP - SEDA PAPELITO';
const IMPORT_TEST_BROWN_KS      = 'SEDA PAPELITO BROWN KING SIZE';
const IMPORT_TEST_PITEIRA_PACK  = '25 livretos por display';
const IMPORT_TEST_GRINDERS      = 'HEADSHOP - TRITURADORES';
const IMPORT_TEST_PITEIRAS      = 'HEADSHOP - PITEIRAS PAPEL';
const IMPORT_TEST_FILTROS       = 'HEADSHOP - FILTROS';
const IMPORT_TEST_LEADING_ZERO  = '00585';
const IMPORT_TEST_SAME_TEXT     = 'PAPELITO TRADICIONAL';
const IMPORT_TEST_SAME_CODE     = '777';

$failures = 0;

function import_matcher_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

/**
 * Post do catálogo no formato de `papelito_vendor_code_import_catalog_posts()`.
 */
function import_matcher_post( int $id, string $title, string $content = '', int $parent = 0, ?string $sku = null ): array {
	return array(
		'id'        => $id,
		'parent_id' => $parent,
		'post_type' => $parent > 0 ? 'product_variation' : 'product',
		'title'     => $title,
		'content'   => $content,
		'sku'       => $sku,
	);
}

/**
 * Catálogo de teste agrupado em famílias, como a carga real faz.
 *
 * @param array<int,?string> $codes Código atual do vendor por item.
 */
function import_matcher_families( array $codes = array() ): array {
	$posts = array(
		import_matcher_post( 11760, 'Seda Tradicional King Size', IMPORT_TEST_SEDA_CONTENT, 0, 'PP01010004' ),
		import_matcher_post( 11762, 'Seda Tradicional Mini Size', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11764, 'Seda Tradicional Com Piteira', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11768, 'Seda Brown King Size', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11770, 'Seda brown Mini Size', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11772, 'Seda brown Com Piteira', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11776, 'Seda Slim King Size', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11778, 'Seda Slim Mini Size', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11788, 'Seda Brown Slim King Size', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11794, 'Seda Insane Brown King Size', IMPORT_TEST_SEDA_CONTENT ),
		import_matcher_post( 11800, 'Piteira Tradicional', IMPORT_TEST_PITEIRA_PACK ),
		import_matcher_post( 11806, 'Piteira Longa', IMPORT_TEST_PITEIRA_PACK ),
		import_matcher_post( 11808, 'Piteira Ultra Longa', IMPORT_TEST_PITEIRA_PACK ),
		import_matcher_post( 11812, 'Filtro Tradicional', IMPORT_TEST_FILTRO_SHORT ),
		import_matcher_post( 11814, 'Filtro Longo', IMPORT_TEST_FILTRO_LONG ),
		import_matcher_post( 11824, 'Filtro Bio', IMPORT_TEST_FILTRO_SHORT ),
		import_matcher_post( 11845, 'Dichavador Cores', 'Mais conhecido como tritura tudo!' ),
		import_matcher_post( 11846, 'Dichavador Cores - Azul', '', 11845 ),
		import_matcher_post( 11847, 'Dichavador Cores - Rosa', '', 11845 ),
		import_matcher_post( 11848, 'Dichavador Cores - Verde', '', 11845 ),
		import_matcher_post( 11849, 'Dichavador Neon', '' ),
		import_matcher_post( 11850, 'Dichavador Neon - Verde', '', 11849 ),
		import_matcher_post( 11852, 'Dichavador Neon - Verde Claro', '', 11849 ),
		import_matcher_post( 13817, 'Green Herb Kit', 'Kit Papelito - Verde' ),
	);
	$labels   = array(
		11846 => 'azul',
		11847 => 'rosa',
		11848 => 'verde',
		11850 => 'verde',
		11852 => 'verde claro',
	);
	$families = papelito_vendor_code_import_group_families( $posts, $labels );
	$dict     = papelito_vendor_code_import_dictionary();
	foreach ( $families as $id => $family ) {
		$families[ $id ]['features'] = papelito_vendor_code_import_family_features( $family, $dict );
		foreach ( $family['targets'] as $index => $target ) {
			$families[ $id ]['targets'][ $index ]['current_code'] = $codes[ $target['product_id'] ] ?? null;
		}
		unset( $families[ $id ]['content'] );
	}
	return array_values( $families );
}

/**
 * Avalia uma linha isolada contra o catálogo de teste.
 */
function import_matcher_eval( string $description, string $code = '585', string $subgroup = IMPORT_TEST_SEDA_SUBGROUP, array $codes = array() ): array {
	return papelito_vendor_code_import_evaluate(
		array(
			'line'        => 2,
			'description' => $description,
			'subgroup'    => $subgroup,
			'code'        => $code,
		),
		import_matcher_families( $codes ),
		papelito_vendor_code_import_dictionary()
	);
}

function import_matcher_family_id( array $row ): ?int {
	return null === $row['family'] ? null : $row['family']['family_id'];
}

function import_matcher_candidate_ids( array $row ): array {
	return array_column( $row['candidates'], 'family_id' );
}

$row = import_matcher_eval( IMPORT_TEST_BROWN_KS );
import_matcher_assert( 'descrição em caixa alta e com a marca casa exato com "Seda Brown King Size"', 'exact' === $row['match'] && 11768 === import_matcher_family_id( $row ) );
import_matcher_assert( 'linha exata tem um único alvo, o próprio produto simples', array( 11768 ) === array_column( $row['family']['targets'], 'product_id' ) );

$row = import_matcher_eval( 'seda papelito brown king size' );
import_matcher_assert( 'caixa diferente não muda a correspondência', 11768 === import_matcher_family_id( $row ) );

$row = import_matcher_eval( 'TRITURADOR PLASTICO PAPELITO CORES', '6136', IMPORT_TEST_GRINDERS );
import_matcher_assert( 'triturador casa com dichavador por sinônimo', 'synonym' === $row['match'] && 11845 === import_matcher_family_id( $row ) );
import_matcher_assert( 'linha sem cor aplica a todas as variações do produto', array( 11846, 11847, 11848 ) === array_column( $row['family']['targets'], 'product_id' ) );

$row = import_matcher_eval( 'TRITURADOR PLASTICO PAPELITO CORES AZUL', '6136-AZ', IMPORT_TEST_GRINDERS );
import_matcher_assert( 'linha com cor aplica só à variação daquela cor', array( 11846 ) === array_column( (array) $row['family']['targets'], 'product_id' ) );

$row = import_matcher_eval( 'DICHAVADOR NEON VERDE CLARO', '77', IMPORT_TEST_GRINDERS );
import_matcher_assert( 'cor de duas palavras vence a cor de uma palavra', array( 11852 ) === array_column( (array) $row['family']['targets'], 'product_id' ) );

$row = import_matcher_eval( 'DICHAVADOR CORES LILAS', '78', IMPORT_TEST_GRINDERS );
import_matcher_assert( 'cor que o produto não tem vai para revisão', 'review' === $row['match'] && null === $row['family'] );

$row = import_matcher_eval( 'SEDA PAPELITO BROWN MINI SIZE' );
import_matcher_assert( 'mini size não casa com king size', 11770 === import_matcher_family_id( $row ) );
$row = import_matcher_eval( 'SEDA PAPELITO BROWN MS' );
import_matcher_assert( 'abreviação MS é sinônimo de mini size', 'synonym' === $row['match'] && 11770 === import_matcher_family_id( $row ) );

$row = import_matcher_eval( 'PITEIRA PAPEL PAPELITO ULTRA LONGA', '6928', IMPORT_TEST_PITEIRAS );
import_matcher_assert( 'ultra longa não é consumida como longa', 'exact' === $row['match'] && 11808 === import_matcher_family_id( $row ) );

$row = import_matcher_eval( 'SEDA PAPELITO BROWN KING SIZE + PITEIRA' );
import_matcher_assert( 'kit com piteira nunca casa com a seda avulsa', ! in_array( 11768, array_merge( import_matcher_candidate_ids( $row ), array( import_matcher_family_id( $row ) ) ), true ) );
import_matcher_assert( '"+ PITEIRA" sugere "Com Piteira" só em revisão, porque o título omite o King Size', 'review' === $row['match'] && array( 11772 ) === import_matcher_candidate_ids( $row ) );

$row = import_matcher_eval( 'SEDA PAPELITO BROWN KING SIZE C/ PITEIRA' );
import_matcher_assert( '"C/ PITEIRA" é o mesmo combo que "+ PITEIRA"', array( 11772 ) === import_matcher_candidate_ids( $row ) );

$row = import_matcher_eval( 'SEDA PAPELITO TRADICIONAL KING SIZE C/50', '586' );
import_matcher_assert( 'C/50 é incompatível com o display de 25 livretos', 'not_found' === $row['match'] );
$row = import_matcher_eval( 'SEDA PAPELITO TRADICIONAL KING SIZE C/25', '8057' );
import_matcher_assert( 'C/25 casa com o display de 25 livretos', 'exact' === $row['match'] && 11760 === import_matcher_family_id( $row ) );

$row = import_matcher_eval( 'FILTRO ENROLAR PAPELITO TRADICIONAL 6X15MM', '3841', IMPORT_TEST_FILTROS );
import_matcher_assert( 'dimensão igual à da descrição confirma o filtro', 'exact' === $row['match'] && 11812 === import_matcher_family_id( $row ) );
$row = import_matcher_eval( 'FILTRO ENROLAR PAPELITO LONGO 6X15MM', '1', IMPORT_TEST_FILTROS );
import_matcher_assert( 'dimensão divergente da descrição é contradição', 'not_found' === $row['match'] );

$row = import_matcher_eval( 'PITEIRA PAPEL PAPELITO TRADICIONAL', '3078', IMPORT_TEST_PITEIRAS );
import_matcher_assert( '"PAPEL" em piteira é enchimento e não puxa para seda', 'exact' === $row['match'] && 11800 === import_matcher_family_id( $row ) );

$row = import_matcher_eval( 'PAPELITO TRADICIONAL KING SIZE', '9', IMPORT_TEST_SEDA_SUBGROUP );
import_matcher_assert( 'sem tipo na descrição, o subgrupo decide', 'exact' === $row['match'] && 11760 === import_matcher_family_id( $row ) );

$row = import_matcher_eval( 'SEDA PAPELITO BROWN KING SIZE', '9', IMPORT_TEST_FILTROS );
import_matcher_assert( 'descrição e subgrupo com tipos diferentes é conflito', 'conflict' === $row['match'] );

$row = import_matcher_eval( 'SEDA PAPELITO KING SIZE' );
import_matcher_assert( 'descrição genérica nunca escolhe sozinha', 'review' === $row['match'] && null === $row['family'] && count( $row['candidates'] ) > 1 );

$row = import_matcher_eval( 'SEDA PAPELITO INSANE BROWN KING SIZE 12,5G' );
import_matcher_assert( 'gramatura que o catálogo não declara pede revisão', 'review' === $row['match'] && array( 11794 ) === import_matcher_candidate_ids( $row ) );

$row = import_matcher_eval( 'PITEIRA PAPEL PAPELITO HIPER LONGA DUPLA', '70011', IMPORT_TEST_PITEIRAS );
import_matcher_assert( 'produto sem nenhum atributo em comum não vira sugestão', 'not_found' === $row['match'] );

$row = import_matcher_eval( IMPORT_TEST_BROWN_KS, IMPORT_TEST_LEADING_ZERO );
import_matcher_assert( 'zeros à esquerda são preservados no código novo', IMPORT_TEST_LEADING_ZERO === $row['code'] && IMPORT_TEST_LEADING_ZERO === $row['family']['targets'][0]['new_code'] );

$row = import_matcher_eval( IMPORT_TEST_BROWN_KS, '   ' );
import_matcher_assert( 'código vazio é linha inválida', 'invalid' === $row['status'] );
$row = import_matcher_eval( IMPORT_TEST_BROWN_KS, str_repeat( '9', 61 ) );
import_matcher_assert( 'código acima de 60 caracteres é linha inválida', 'invalid' === $row['status'] );

$row = import_matcher_eval( 'SEDA PAPELITO TRADICIONAL KING SIZE', 'PP01010004' );
import_matcher_assert( 'código igual ao SKU da Papelito grava null, como na edição manual', null === $row['family']['targets'][0]['new_code'] );

$row = import_matcher_eval( IMPORT_TEST_BROWN_KS, '585', IMPORT_TEST_SEDA_SUBGROUP, array( 11768 => '585' ) );
import_matcher_assert( 'código atual igual ao novo não conta alteração', 0 === $row['changes'] );

$families = import_matcher_families();
$dict     = papelito_vendor_code_import_dictionary();
$rows     = array(
	array( 'line' => 2, 'description' => IMPORT_TEST_BROWN_KS, 'subgroup' => '', 'code' => '585' ),
	array( 'line' => 3, 'description' => 'SEDA PAPELITO BROWN KING SIZE C/25', 'subgroup' => '', 'code' => '7265' ),
	array( 'line' => 4, 'description' => 'SEDA PAPELITO SLIM KING SIZE', 'subgroup' => '', 'code' => '587' ),
	array( 'line' => 5, 'description' => 'SEDA PAPELITO SLIM KING SIZE', 'subgroup' => '', 'code' => '587' ),
	array( 'line' => 6, 'description' => 'SEDA PAPELITO SLIM MINI SIZE', 'subgroup' => '', 'code' => '900' ),
	array( 'line' => 7, 'description' => 'SEDA PAPELITO TRADICIONAL MINI SIZE', 'subgroup' => '', 'code' => '900' ),
);
$evaluated = array_map( static fn( array $row ): array => papelito_vendor_code_import_evaluate( $row, $families, $dict ), $rows );
$flagged   = papelito_vendor_code_import_flag_conflicts( $evaluated, 'papelito_vendor_code_import_is_automatic' );
$status    = array_column( $flagged, 'status', 'line' );
import_matcher_assert( 'dois códigos para o mesmo produto: as duas linhas viram conflito', 'conflict' === $status[2] && 'conflict' === $status[3] );
import_matcher_assert( 'linha idêntica repetida é marcada como repetida', 'exact' === $status[4] && 'duplicate' === $status[5] );
import_matcher_assert( 'mesmo código em produtos diferentes é conflito', 'conflict' === $status[6] && 'conflict' === $status[7] );

$summary = papelito_vendor_code_import_summary( $flagged );
import_matcher_assert( 'resumo conta só as alterações que gravariam automaticamente', 1 === $summary['changes'] && 6 === $summary['total'] );

$same_text = array(
	array( 'line' => 2, 'description' => IMPORT_TEST_SAME_TEXT, 'subgroup' => IMPORT_TEST_PITEIRAS, 'code' => IMPORT_TEST_SAME_CODE ),
	array( 'line' => 3, 'description' => IMPORT_TEST_SAME_TEXT, 'subgroup' => IMPORT_TEST_FILTROS, 'code' => IMPORT_TEST_SAME_CODE ),
	array( 'line' => 4, 'description' => IMPORT_TEST_SAME_TEXT, 'subgroup' => IMPORT_TEST_PITEIRAS, 'code' => IMPORT_TEST_SAME_CODE ),
);
$evaluated = array_map( static fn( array $row ): array => papelito_vendor_code_import_evaluate( $row, $families, $dict ), $same_text );
import_matcher_assert( 'mesma descrição resolve para famílias diferentes conforme o subgrupo', 11800 === import_matcher_family_id( $evaluated[0] ) && 11812 === import_matcher_family_id( $evaluated[1] ) );
foreach ( array( 'na ordem do arquivo' => $evaluated, 'na ordem invertida' => array_reverse( $evaluated ) ) as $order => $batch ) {
	$status = array_column( papelito_vendor_code_import_flag_conflicts( $batch, 'papelito_vendor_code_import_is_automatic' ), 'status', 'line' );
	import_matcher_assert( "mesmo texto e código em subgrupos de famílias diferentes é conflito {$order}", 'conflict' === $status[2] && 'conflict' === $status[3] );
	import_matcher_assert( "repetição de linha em conflito também fica de fora como conflito {$order}", 'conflict' === $status[4] );
}

$status = array_column( papelito_vendor_code_import_flag_conflicts( array( $evaluated[0], $evaluated[2] ), 'papelito_vendor_code_import_is_automatic' ), 'status', 'line' );
import_matcher_assert( 'mesmo texto, código e subgrupo continua repetida', 'exact' === $status[2] && 'duplicate' === $status[4] );

if ( $failures > 0 ) {
	echo "\n{$failures} falha(s).\n";
	exit( 1 );
}
echo "OK: associação da planilha ao catálogo.\n";
