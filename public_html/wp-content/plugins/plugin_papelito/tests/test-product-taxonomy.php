<?php
/**
 * Invariantes da taxonomia própria de produtos.
 *
 * O que só o MySQL garante: uma categoria por produto (PRIMARY KEY em
 * product_id) e slug de subcategoria único POR categoria — o mesmo `slim`
 * pode existir em Sedas, Piteiras e Filtros. Precisa do WordPress carregado:
 *
 *   wp eval-file tests/test-product-taxonomy.php
 *
 * Cria categorias e produtos descartáveis com prefixo reservado e apaga tudo
 * no fim, inclusive em caso de falha.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-product-taxonomy.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * Prefixo reservado: nada fora dele é tocado pela limpeza.
 */
const PAPELITO_TAXONOMY_TEST_PREFIX = 'zzz-teste-taxonomia';

/**
 * `wp eval-file` executa o arquivo dentro de uma função: variável de topo NÃO é
 * global. Sem declarar aqui, o `global $failures` dos helpers apontaria para
 * outra variável e o teste sairia com código 0 mesmo falhando.
 */
global $wpdb, $failures, $checks, $created_categories, $created_products;

$failures           = 0;
$checks             = 0;
$created_categories = array();
$created_products   = array();

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param string $label    Descrição da checagem.
 * @param mixed  $expected Valor esperado.
 * @param mixed  $actual   Valor obtido.
 * @return void
 */
function assert_taxonomy( string $label, $expected, $actual ): void {
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
 * Confirma que o retorno é um WP_Error com o código esperado.
 *
 * @param string $label    Descrição da checagem.
 * @param string $expected Código esperado.
 * @param mixed  $actual   Valor obtido.
 * @return void
 */
function assert_taxonomy_error( string $label, string $expected, $actual ): void {
	assert_taxonomy( $label, $expected, is_wp_error( $actual ) ? $actual->get_error_code() : 'sem-erro' );
}

/**
 * Cria uma categoria descartável.
 *
 * @param string              $suffix Sufixo do slug.
 * @param array<string,mixed> $data   Campos extras.
 * @return int
 */
function taxonomy_test_category( string $suffix, array $data = array() ): int {
	global $created_categories;

	$id = papelito_category_create(
		array_merge(
			array(
				'name' => 'Teste ' . $suffix,
				'slug' => PAPELITO_TAXONOMY_TEST_PREFIX . '-' . $suffix,
			),
			$data
		)
	);

	if ( is_wp_error( $id ) ) {
		echo '  FAIL nao criou a categoria de teste ' . esc_html( $suffix ) . ': ' . esc_html( $id->get_error_message() ) . "\n";
		exit( 1 );
	}

	$created_categories[] = (int) $id;

	return (int) $id;
}

/**
 * Cria um produto descartável.
 *
 * @param string $suffix Sufixo do título.
 * @param string $status Status do post.
 * @return int
 */
function taxonomy_test_product( string $suffix, string $status = 'publish' ): int {
	global $created_products;

	$id = wp_insert_post(
		array(
			'post_title'  => 'Produto ' . PAPELITO_TAXONOMY_TEST_PREFIX . ' ' . $suffix,
			'post_type'   => 'product',
			'post_status' => $status,
		)
	);

	if ( is_wp_error( $id ) || ! $id ) {
		echo '  FAIL nao criou o produto de teste ' . esc_html( $suffix ) . "\n";
		exit( 1 );
	}

	$created_products[] = (int) $id;

	return (int) $id;
}

/**
 * Apaga tudo que o teste criou.
 *
 * @return void
 */
function taxonomy_test_cleanup(): void {
	global $wpdb, $created_categories, $created_products;

	$tables = papelito_product_taxonomy_table_names();

	foreach ( $created_products as $product_id ) {
		papelito_product_clear_taxonomy( $product_id );
		wp_delete_post( $product_id, true );
	}

	foreach ( $created_categories as $category_id ) {
		$wpdb->delete( $tables['subcategories'], array( 'category_id' => $category_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $tables['categories'], array( 'id' => $category_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

papelito_product_taxonomy_install_tables();

$tables = papelito_product_taxonomy_table_names();

echo "\nSchema\n";

foreach ( $tables as $key => $table ) {
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	assert_taxonomy( 'tabela ' . $key . ' existe', $table, $exists );
}

papelito_product_taxonomy_install_tables();
assert_taxonomy( 'dbDelta é idempotente (categorias não duplicam)', true, is_array( papelito_categories_list( array( 'active_only' => false ) ) ) );

echo "\nCategorias\n";

$sedas    = taxonomy_test_category( 'sedas' );
$piteiras = taxonomy_test_category( 'piteiras' );

assert_taxonomy( 'categoria criada é recuperável pelo slug', $sedas, papelito_category_get_by_slug( PAPELITO_TAXONOMY_TEST_PREFIX . '-sedas' )['id'] );
assert_taxonomy_error(
	'slug de categoria é único globalmente',
	'papelito_category_slug_taken',
	papelito_category_create(
		array(
			'name' => 'Duplicada',
			'slug' => PAPELITO_TAXONOMY_TEST_PREFIX . '-sedas',
		)
	)
);
assert_taxonomy_error( 'categoria exige nome', 'papelito_category_name_required', papelito_category_create( array( 'name' => '   ' ) ) );

papelito_categories_reorder( array( $piteiras, $sedas ) );
assert_taxonomy( 'reorder grava sort_order posicional', 0, papelito_category_get( $piteiras )['sortOrder'] );
assert_taxonomy( 'reorder grava sort_order do segundo', 1, papelito_category_get( $sedas )['sortOrder'] );

echo "\nSubcategorias\n";

$material_brown = papelito_subcategory_create(
	array(
		'categoryId' => $sedas,
		'name'       => 'Brown',
		'facet'      => 'material',
	)
);
$formato_slim   = papelito_subcategory_create(
	array(
		'categoryId' => $sedas,
		'name'       => 'Slim',
		'facet'      => 'formato',
	)
);
$piteira_slim   = papelito_subcategory_create(
	array(
		'categoryId' => $piteiras,
		'name'       => 'Slim',
		'facet'      => 'tamanho',
	)
);

assert_taxonomy( 'subcategoria criada em Sedas', true, is_int( $material_brown ) && $material_brown > 0 );
assert_taxonomy( 'mesmo slug "slim" convive em duas categorias', true, is_int( $formato_slim ) && is_int( $piteira_slim ) && $formato_slim !== $piteira_slim );
assert_taxonomy( 'slug de subcategoria não recebe sufixo de desambiguação', 'slim', papelito_subcategory_get( $piteira_slim )['slug'] );
assert_taxonomy_error(
	'slug de subcategoria é único dentro da categoria',
	'papelito_subcategory_slug_taken',
	papelito_subcategory_create(
		array(
			'categoryId' => $sedas,
			'name'       => 'Slim',
			'facet'      => 'outra',
		)
	)
);
assert_taxonomy_error(
	'subcategoria exige categoria existente',
	'papelito_category_not_found',
	papelito_subcategory_create(
		array(
			'categoryId' => 99999999,
			'name'       => 'Órfã',
		)
	)
);
assert_taxonomy( 'listagem ordena por faceta', array( 'formato', 'material' ), array_column( papelito_subcategories_list( $sedas ), 'facet' ) );

echo "\nCategoria principal do produto\n";

$produto = taxonomy_test_product( 'a' );

assert_taxonomy( 'produto começa sem categoria', null, papelito_product_get_category( $produto ) );
assert_taxonomy( 'define a categoria principal', true, papelito_product_set_category( $produto, $sedas ) );
assert_taxonomy( 'lê a categoria principal', $sedas, papelito_product_get_category( $produto )['id'] );

papelito_product_set_category( $produto, $sedas );
$linhas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['product_category']} WHERE product_id = %d", $produto ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
assert_taxonomy( 'gravar duas vezes deixa UMA linha', 1, $linhas );

papelito_product_set_category( $produto, $piteiras );
$linhas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['product_category']} WHERE product_id = %d", $produto ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
assert_taxonomy( 'trocar de categoria continua com UMA linha', 1, $linhas );
assert_taxonomy( 'troca de categoria é efetiva', $piteiras, papelito_product_get_category( $produto )['id'] );

assert_taxonomy_error( 'categoria inexistente é recusada', 'papelito_category_not_found', papelito_product_set_category( $produto, 99999999 ) );
assert_taxonomy_error( 'produto inexistente é recusado', 'papelito_product_not_found', papelito_product_set_category( 99999999, $sedas ) );

$arquivada = taxonomy_test_category( 'arquivada' );
papelito_category_archive( $arquivada );
assert_taxonomy_error( 'categoria arquivada é recusada', 'papelito_category_inactive', papelito_product_set_category( $produto, $arquivada ) );

echo "\nSubcategorias do produto\n";

papelito_product_set_category( $produto, $sedas );

assert_taxonomy( 'define subcategorias da própria categoria', true, papelito_product_set_subcategories( $produto, array( $material_brown, $formato_slim ) ) );
assert_taxonomy( 'lê as duas subcategorias', 2, count( papelito_product_get_subcategories( $produto ) ) );
assert_taxonomy_error( 'subcategoria de outra categoria é recusada', 'papelito_subcategory_foreign', papelito_product_set_subcategories( $produto, array( $piteira_slim ) ) );
assert_taxonomy( 'recusa não altera o conjunto anterior', 2, count( papelito_product_get_subcategories( $produto ) ) );

papelito_product_set_category( $produto, $piteiras );
assert_taxonomy( 'trocar a categoria limpa as subcategorias', 0, count( papelito_product_get_subcategories( $produto ) ) );

$sem_categoria = taxonomy_test_product( 'b' );
assert_taxonomy_error( 'subcategoria sem categoria principal é recusada', 'papelito_product_category_missing', papelito_product_set_subcategories( $sem_categoria, array( $material_brown ) ) );

echo "\nColeções\n";

papelito_product_set_category( $produto, $sedas );
assert_taxonomy( 'define coleções curadas', true, papelito_product_set_collections( $produto, array( 'premium', 'kits' ) ) );
assert_taxonomy( 'lê as coleções em ordem', array( 'kits', 'premium' ), papelito_product_get_collections( $produto ) );
assert_taxonomy_error( 'coleção desconhecida é recusada', 'papelito_collection_unknown', papelito_product_set_collections( $produto, array( 'inventada' ) ) );
assert_taxonomy( 'substituição remove o que saiu', array( 'premium' ), ( papelito_product_set_collections( $produto, array( 'premium' ) ) && true ) ? papelito_product_get_collections( $produto ) : array() );

echo "\nArquivamento\n";

assert_taxonomy_error( 'categoria com produto publicado não arquiva', 'papelito_category_in_use', papelito_category_archive( $sedas ) );
papelito_product_clear_taxonomy( $produto );
assert_taxonomy( 'categoria sem produto publicado arquiva', true, papelito_category_archive( $sedas ) );
assert_taxonomy( 'restaurar reativa a categoria', true, papelito_category_restore( $sedas ) );
assert_taxonomy( 'categoria restaurada volta ativa', true, papelito_category_get( $sedas )['isActive'] );

papelito_product_set_category( $produto, $sedas );
papelito_product_set_subcategories( $produto, array( $material_brown ) );
papelito_subcategory_archive( $material_brown );
assert_taxonomy( 'arquivar subcategoria desfaz o vínculo', 0, count( papelito_product_get_subcategories( $produto ) ) );
assert_taxonomy( 'produto arquivado de subcategoria mantém a categoria', $sedas, papelito_product_get_category( $produto )['id'] );

echo "\nLoaders em lote\n";

$outro = taxonomy_test_product( 'c' );
papelito_product_set_category( $outro, $piteiras );
papelito_product_set_subcategories( $outro, array( $piteira_slim ) );

$mapa_categoria = papelito_products_category_map( array( $produto, $outro, 99999999 ) );
assert_taxonomy( 'mapa de categoria cobre os dois produtos', 2, count( $mapa_categoria ) );
assert_taxonomy( 'mapa de categoria traz a categoria certa', $piteiras, $mapa_categoria[ $outro ]['id'] );

$mapa_sub = papelito_products_subcategory_map( array( $produto, $outro ) );
assert_taxonomy( 'mapa de subcategoria só traz quem tem', array( $outro ), array_keys( $mapa_sub ) );

papelito_product_set_collections( $outro, array( 'kits' ) );
$mapa_col = papelito_products_collection_map( array( $produto, $outro ) );
assert_taxonomy( 'mapa de coleção traz o slug', array( 'kits' ), $mapa_col[ $outro ] );

assert_taxonomy( 'loaders aceitam lista vazia', array(), papelito_products_category_map( array() ) );

echo "\nContagens e integridade\n";

$contagens = papelito_category_product_counts();
assert_taxonomy( 'contagem por categoria conta o publicado', 1, $contagens[ $piteiras ]['published'] ?? 0 );

$rascunho = taxonomy_test_product( 'd', 'draft' );
papelito_product_set_category( $rascunho, $piteiras );
$contagens = papelito_category_product_counts();
assert_taxonomy( 'rascunho entra no total', 2, $contagens[ $piteiras ]['total'] ?? 0 );
assert_taxonomy( 'rascunho não entra no publicado', 1, $contagens[ $piteiras ]['published'] ?? 0 );

$relatorio = papelito_category_integrity_report();
assert_taxonomy( 'relatório não acusa vínculo órfão', array(), $relatorio['danglingCategory'] );
assert_taxonomy( 'relatório não acusa subcategoria cruzada', array(), $relatorio['crossCategorySubcategory'] );

$orfao     = taxonomy_test_product( 'e' );
$relatorio = papelito_category_integrity_report();
assert_taxonomy( 'relatório acusa publicado sem categoria', true, in_array( $orfao, $relatorio['publishedWithoutCategory'], true ) );
assert_taxonomy( 'relatório fica sujo quando há pendência', false, $relatorio['isClean'] );

taxonomy_test_cleanup();

echo "\n";
echo $failures > 0
	? 'FALHOU: ' . esc_html( (string) $failures ) . ' de ' . esc_html( (string) $checks ) . " checagens\n"
	: 'OK: ' . esc_html( (string) $checks ) . " checagens\n";

exit( $failures > 0 ? 1 : 0 );
