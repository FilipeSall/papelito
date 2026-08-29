<?php
/**
 * Relatório de integridade da taxonomia contra o banco real.
 *
 * O alvo é o que só o SQL prova: "produto publicado sem categoria" não pode
 * incluir o produto comercial de um Kit. Kit nasce `catalog_visibility=hidden`
 * e nunca recebe categoria (`papelito_kit_save_product`), então contá-lo
 * transformava um estado correto em alerta vermelho permanente — e o admin não
 * tinha para onde ir, porque a aba Produtos exclui kits.
 *
 *   wp eval-file tests/test-product-taxonomy-integrity-db.php
 *
 * Cria produtos e kits descartáveis com prefixo reservado e apaga tudo no fim.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-product-taxonomy-integrity-db.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * Prefixo reservado: nada fora dele é tocado pela limpeza.
 */
const PAPELITO_INTEGRITY_TEST_PREFIX = 'zzz-teste-integridade';

global $wpdb, $failures, $checks, $created_products, $created_kits, $created_categories;

$failures           = 0;
$checks             = 0;
$created_products   = array();
$created_kits       = array();
$created_categories = array();

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param string $label    Descrição.
 * @param mixed  $expected Esperado.
 * @param mixed  $actual   Obtido.
 * @return void
 */
function papelito_integrity_check( $label, $expected, $actual ) {
	global $failures, $checks;

	$checks++;

	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}

	$failures++;
	echo "  FAIL: {$label} - esperado " . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

/**
 * Cria um produto descartável.
 *
 * @param string $suffix Sufixo do título.
 * @param string $status Status do post.
 * @return int
 */
function papelito_integrity_make_product( $suffix, $status = 'publish' ) {
	global $created_products;

	$product_id = (int) wp_insert_post(
		array(
			'post_title'  => PAPELITO_INTEGRITY_TEST_PREFIX . $suffix,
			'post_type'   => 'product',
			'post_status' => $status,
		)
	);

	$created_products[] = $product_id;

	return $product_id;
}

/**
 * Promove um produto a Kit registrando-o na tabela de kits.
 *
 * @param int $product_id Produto comercial do kit.
 * @return void
 */
function papelito_integrity_make_kit( $product_id ) {
	global $wpdb, $created_kits;

	$tables = papelito_kits_table_names();
	$wpdb->insert(
		$tables['kits'],
		array(
			'product_id'   => $product_id,
			'image_source' => 'custom',
		),
		array( '%d', '%s' )
	);

	$created_kits[] = $product_id;
}

/**
 * Cria uma categoria descartável e vincula um produto a ela.
 *
 * @param int    $product_id Produto.
 * @param string $suffix     Sufixo do nome.
 * @return void
 */
function papelito_integrity_classify( $product_id, $suffix ) {
	global $wpdb, $created_categories;

	$tables = papelito_product_taxonomy_table_names();
	$wpdb->insert(
		$tables['categories'],
		array(
			'name'      => PAPELITO_INTEGRITY_TEST_PREFIX . $suffix,
			'slug'      => PAPELITO_INTEGRITY_TEST_PREFIX . $suffix,
			'is_active' => 1,
		),
		array( '%s', '%s', '%d' )
	);
	$category_id          = (int) $wpdb->insert_id;
	$created_categories[] = $category_id;

	$wpdb->insert(
		$tables['product_category'],
		array(
			'product_id'  => $product_id,
			'category_id' => $category_id,
		),
		array( '%d', '%d' )
	);
}

/**
 * IDs sinalizados como publicados sem categoria.
 *
 * @return int[]
 */
function papelito_integrity_flagged() {
	$report = papelito_category_integrity_report();

	return array_map( 'intval', $report['publishedWithoutCategory'] );
}

echo "\nIntegridade (banco): produto comum sem categoria continua sendo sinalizado\n";

$orphan_id = papelito_integrity_make_product( '-orfao' );
papelito_integrity_check(
	'produto publicado sem categoria aparece no relatório',
	true,
	in_array( $orphan_id, papelito_integrity_flagged(), true )
);

$draft_id = papelito_integrity_make_product( '-rascunho', 'draft' );
papelito_integrity_check(
	'rascunho sem categoria não aparece',
	false,
	in_array( $draft_id, papelito_integrity_flagged(), true )
);

$classified_id = papelito_integrity_make_product( '-classificado' );
papelito_integrity_classify( $classified_id, '-categoria' );
papelito_integrity_check(
	'produto publicado com categoria não aparece',
	false,
	in_array( $classified_id, papelito_integrity_flagged(), true )
);

echo "\nIntegridade (banco): Kit não é produto sem categoria\n";

$kit_product_id = papelito_integrity_make_product( '-kit' );
papelito_integrity_check(
	'antes de virar kit, o produto é sinalizado',
	true,
	in_array( $kit_product_id, papelito_integrity_flagged(), true )
);

papelito_integrity_make_kit( $kit_product_id );
papelito_integrity_check(
	'kit publicado sem categoria NÃO é sinalizado',
	false,
	in_array( $kit_product_id, papelito_integrity_flagged(), true )
);

papelito_integrity_check(
	'excluir o kit não esconde os produtos comuns',
	true,
	in_array( $orphan_id, papelito_integrity_flagged(), true )
);

echo "\nLimpando\n";

$kit_tables      = papelito_kits_table_names();
$taxonomy_tables = papelito_product_taxonomy_table_names();

foreach ( $created_kits as $kit_product_id ) {
	$wpdb->delete( $kit_tables['kits'], array( 'product_id' => $kit_product_id ), array( '%d' ) );
}

foreach ( $created_products as $product_id ) {
	$wpdb->delete( $taxonomy_tables['product_category'], array( 'product_id' => $product_id ), array( '%d' ) );
	wp_delete_post( $product_id, true );
}

foreach ( $created_categories as $category_id ) {
	$wpdb->delete( $taxonomy_tables['categories'], array( 'id' => $category_id ), array( '%d' ) );
}

echo "\n{$checks} checagens\n";

if ( $failures > 0 ) {
	echo "FALHAS: {$failures}\n";
	exit( 1 );
}

echo "Todas as checagens de integridade no banco passaram.\n";
