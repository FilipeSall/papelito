<?php
/**
 * Compatibilidade com `product_cat`: flag, dual-write e reconciliação.
 *
 * Este é o único teste da taxonomia que ESCREVE em `product_cat` — e por isso
 * trabalha só com produto descartável, criado e apagado aqui. Nenhum produto real
 * é tocado.
 *
 *   wp eval-file tests/test-product-taxonomy-compat.php
 *
 * Depende do mapa `papelito_taxonomy_legacy_map`, gravado pela migração da fase 4.
 * Sem ele o teste avisa e sai limpo.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-product-taxonomy-compat.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * Prefixo reservado dos produtos descartáveis.
 */
const PAPELITO_COMPAT_TEST_PREFIX = 'zzz-teste-compat-taxonomia';

global $wpdb, $failures, $checks, $created_products;

$failures         = 0;
$checks           = 0;
$created_products = array();

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param string $label    Descrição da checagem.
 * @param mixed  $expected Valor esperado.
 * @param mixed  $actual   Valor obtido.
 * @return void
 */
function assert_compat( string $label, $expected, $actual ): void {
	global $failures, $checks;

	++$checks;

	if ( $expected === $actual ) {
		echo '  ok   ' . esc_html( $label ) . "\n";

		return;
	}

	++$failures;

	echo '  FAIL ' . esc_html( $label ) . "\n";
	echo '       esperado: ' . esc_html( (string) wp_json_encode( $expected ) ) . "\n";
	echo '       obtido:   ' . esc_html( (string) wp_json_encode( $actual ) ) . "\n";
}

/**
 * Cria um produto descartável.
 *
 * @param string $suffix Sufixo do título.
 * @return int
 */
function compat_test_product( string $suffix ): int {
	global $created_products;

	$id = wp_insert_post(
		array(
			'post_title'  => 'Produto ' . PAPELITO_COMPAT_TEST_PREFIX . ' ' . $suffix,
			'post_type'   => 'product',
			'post_status' => 'publish',
		)
	);

	if ( is_wp_error( $id ) || ! $id ) {
		echo '  FAIL nao criou o produto ' . esc_html( $suffix ) . "\n";
		exit( 1 );
	}

	$created_products[] = (int) $id;

	return (int) $id;
}

/**
 * Nomes dos termos de `product_cat` de um produto, ordenados.
 *
 * @param int $product_id Id do produto.
 * @return string[]
 */
function compat_test_term_names( int $product_id ): array {
	$terms = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
	$names = is_wp_error( $terms ) ? array() : array_map( 'strval', $terms );

	sort( $names );

	return $names;
}

/**
 * Ids de subcategoria pelos slugs, dentro de uma categoria.
 *
 * @param int      $category_id Id da categoria.
 * @param string[] $slugs       Slugs desejados.
 * @return int[]
 */
function compat_test_sub_ids( int $category_id, array $slugs ): array {
	$ids = array();

	foreach ( $slugs as $slug ) {
		$sub = papelito_subcategory_get_by_slug( $category_id, $slug );

		if ( null !== $sub ) {
			$ids[] = $sub['id'];
		}
	}

	return $ids;
}

/**
 * Apaga tudo que o teste criou.
 *
 * @return void
 */
function compat_test_cleanup(): void {
	global $created_products;

	foreach ( $created_products as $product_id ) {
		papelito_product_clear_taxonomy( $product_id );
		wp_delete_object_term_relationships( $product_id, 'product_cat' );
		wp_delete_post( $product_id, true );
	}
}

$mapa = papelito_taxonomy_legacy_term_map();

if ( empty( $mapa['roots'] ) ) {
	echo "  aviso: mapa papelito_taxonomy_legacy_map vazio. Rode a migração da fase 4 antes.\n";
	exit( 0 );
}

$sedas    = papelito_category_get_by_slug( 'sedas' );
$piteiras = papelito_category_get_by_slug( 'piteiras' );

if ( null === $sedas || null === $piteiras ) {
	echo "  aviso: seed da taxonomia ausente. Rode a migração da fase 4 antes.\n";
	exit( 0 );
}

echo "\nFeature flag\n";

assert_compat( 'a flag nasce desligada', false, papelito_product_taxonomy_enabled() );

add_filter( 'papelito_product_taxonomy_enabled', '__return_true' );
assert_compat( 'o filtro liga a flag', true, papelito_product_taxonomy_enabled() );
remove_filter( 'papelito_product_taxonomy_enabled', '__return_true' );
assert_compat( 'remover o filtro desliga de novo', false, papelito_product_taxonomy_enabled() );

echo "\nDual-write independe da flag de leitura\n";

assert_compat( 'dual-write ativo mesmo com a flag desligada', true, papelito_taxonomy_dual_write_enabled() );

$desligado = compat_test_product( 'flag-off' );

papelito_product_set_category( $desligado, $sedas['id'] );
papelito_product_set_subcategories( $desligado, compat_test_sub_ids( $sedas['id'], array( 'brown', 'king-size' ) ) );

// A flag governa a LEITURA. Se o dual-write dependesse dela, salvar um produto
// pelo admin novo o tiraria da vitrine, que ainda lê `product_cat`.
assert_compat( 'produto salvo com a flag off já sincroniza product_cat', array( 'Brown', 'Papel' ), compat_test_term_names( $desligado ) );

echo "\nDual-write: composição dos termos\n";

$ligado = compat_test_product( 'composicao' );

papelito_product_set_category( $ligado, $sedas['id'] );
papelito_product_set_subcategories( $ligado, compat_test_sub_ids( $sedas['id'], array( 'brown', 'king-size' ) ) );

assert_compat( 'brown + king-size volta como Papel + Brown', array( 'Brown', 'Papel' ), compat_test_term_names( $ligado ) );

papelito_product_set_subcategories( $ligado, compat_test_sub_ids( $sedas['id'], array( 'brown', 'slim', 'king-size' ) ) );

assert_compat( 'brown + slim recompõe o termo combinado Brown Slim', array( 'Brown Slim', 'Papel' ), compat_test_term_names( $ligado ) );

$so_formato = compat_test_product( 'so-formato' );

papelito_product_set_category( $so_formato, $sedas['id'] );
papelito_product_set_subcategories( $so_formato, compat_test_sub_ids( $sedas['id'], array( 'king-size' ) ) );

assert_compat( 'subcategoria que nunca foi termo cai só na raiz', array( 'Papel' ), compat_test_term_names( $so_formato ) );

echo "\nInversa explícita do termo-bucket\n";

$bucket = compat_test_product( 'bucket' );

papelito_product_set_category( $bucket, $piteiras['id'] );
papelito_product_set_subcategories( $bucket, compat_test_sub_ids( $piteiras['id'], array( 'mega-longa' ) ) );

assert_compat( 'mega-longa volta ao bucket Longas, sem perder o termo', array( 'Longas', 'Piteiras' ), compat_test_term_names( $bucket ) );

echo "\nInversa da coleção\n";

$premium = compat_test_product( 'premium' );

papelito_product_set_category( $premium, $sedas['id'] );
papelito_product_set_subcategories( $premium, compat_test_sub_ids( $sedas['id'], array( 'brown', 'king-size' ) ) );
papelito_product_set_collections( $premium, array( 'premium' ) );

assert_compat( 'coleção premium recupera o termo Premium', array( 'Brown', 'Papel', 'Premium' ), compat_test_term_names( $premium ) );

echo "\nSupressão do dual-write\n";

$suprimido = compat_test_product( 'suprimido' );

papelito_taxonomy_suppress_dual_write( true );
papelito_product_set_category( $suprimido, $sedas['id'] );
papelito_product_set_subcategories( $suprimido, compat_test_sub_ids( $sedas['id'], array( 'brown' ) ) );
papelito_taxonomy_suppress_dual_write( false );

assert_compat( 'supressão impede a escrita em product_cat', array(), compat_test_term_names( $suprimido ) );
assert_compat( 'a supressão não é permanente', false, papelito_taxonomy_suppress_dual_write() );

echo "\nReconciliação\n";

$relatorio = papelito_taxonomy_reconcile_report();

assert_compat( 'reconciliação enxerga o produto suprimido como divergente', true, in_array( $suprimido, array_column( $relatorio['divergentes'], 'productId' ), true ) );

$divergencia = null;

foreach ( $relatorio['divergentes'] as $d ) {
	if ( $suprimido === $d['productId'] ) {
		$divergencia = $d;
	}
}

assert_compat( 'produto suprimido não tem termo PERDIDO, só faltando', array(), $divergencia['perdidos'] );
assert_compat( 'produto suprimido tem termo a adicionar', 2, count( $divergencia['adicionados'] ) );

papelito_taxonomy_dual_write_product( $suprimido );
$relatorio_2 = papelito_taxonomy_reconcile_report();

assert_compat( 'dual-write manual resolve a divergência', false, in_array( $suprimido, array_column( $relatorio_2['divergentes'], 'productId' ), true ) );

wp_set_object_terms( $suprimido, array( $mapa['roots']['piteiras'] ), 'product_cat', false );
$relatorio_3 = papelito_taxonomy_reconcile_report();
$perda       = null;

foreach ( $relatorio_3['comPerda'] as $d ) {
	if ( $suprimido === $d['productId'] ) {
		$perda = $d;
	}
}

assert_compat( 'termo alheio em product_cat é contado como PERDA', true, null !== $perda );
assert_compat( 'relatório fica sujo quando há perda', false, $relatorio_3['isClean'] );

compat_test_cleanup();

echo "\nEstado após a limpeza\n";

$final = papelito_taxonomy_reconcile_report();

assert_compat( 'nenhuma perda sobrou nos produtos reais', array(), $final['comPerda'] );
assert_compat( 'reconciliação dos produtos reais está limpa', true, $final['isClean'] );

echo "\n";
echo $failures > 0
	? 'FALHOU: ' . esc_html( (string) $failures ) . ' de ' . esc_html( (string) $checks ) . " checagens\n"
	: 'OK: ' . esc_html( (string) $checks ) . " checagens\n";

exit( $failures > 0 ? 1 : 0 );
