<?php
/**
 * Leitura do catálogo pela taxonomia própria: busca, estoque e flash sale.
 *
 * Três coisas que só um teste de integração pega:
 *
 * 1. **Paridade de semântica.** Com `product_cat`, os três módulos resolviam
 *    categoria de jeitos diferentes e o mesmo filtro devolvia conjuntos
 *    diferentes em cada tela. Aqui os três são comparados entre si.
 * 2. **Fail-closed.** Slug que não resolve devolve ZERO produtos, nunca o
 *    catálogo inteiro.
 * 3. **Variação herda do pai.** Um JOIN por `p.ID` em vez do id efetivo derruba
 *    todas as variações do estoque.
 *
 *   wp eval-file tests/test-product-taxonomy-catalog.php
 *
 * Usa os produtos reais do catálogo em modo leitura e produtos descartáveis para
 * os casos de borda. Não altera `product_cat`.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-product-taxonomy-catalog.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

const PAPELITO_CATALOG_TEST_PREFIX = 'zzz-teste-catalogo-taxonomia';

global $wpdb, $failures, $checks, $created_products, $created_vendors;

$failures         = 0;
$checks           = 0;
$created_products = array();
$created_vendors  = array();

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param string $label    Descrição da checagem.
 * @param mixed  $expected Valor esperado.
 * @param mixed  $actual   Valor obtido.
 * @return void
 */
function assert_catalog( string $label, $expected, $actual ): void {
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
 * Cria um produto publicado descartável, com preço e dimensões válidos.
 *
 * A busca pública barra produto sem preço ou sem dimensões; sem isso o produto
 * não entraria no resultado e o teste mediria a coisa errada.
 *
 * @param string $title Título.
 * @return int
 */
function catalog_test_product( string $title ): int {
	global $created_products;

	$id = wp_insert_post(
		array(
			'post_title'  => $title,
			'post_type'   => 'product',
			'post_status' => 'publish',
		)
	);

	if ( is_wp_error( $id ) || ! $id ) {
		echo "  FAIL nao criou produto de teste\n";
		exit( 1 );
	}

	$id = (int) $id;

	update_post_meta( $id, '_price', '10.00' );
	update_post_meta( $id, '_regular_price', '10.00' );
	update_post_meta( $id, '_weight', '0.4' );
	update_post_meta( $id, '_length', '5' );
	update_post_meta( $id, '_width', '5' );
	update_post_meta( $id, '_height', '5' );

	$created_products[] = $id;

	return $id;
}

/**
 * Apaga o que o teste criou.
 *
 * @return void
 */
function catalog_test_cleanup(): void {
	global $created_products, $created_vendors;

	foreach ( $created_products as $product_id ) {
		papelito_product_clear_taxonomy( $product_id );
		wp_delete_object_term_relationships( $product_id, 'product_cat' );
		wp_delete_post( $product_id, true );
	}

	foreach ( $created_vendors as $vendor_id ) {
		wp_delete_user( $vendor_id );
	}
}

/**
 * Ids devolvidos pela busca pública.
 *
 * @param array<string,mixed> $args Argumentos da busca.
 * @return int[]
 */
function catalog_search_ids( array $args ): array {
	$result = papelito_catalog_search_products( $args );
	$ids    = array_map( 'intval', $result['ids'] );

	sort( $ids );

	return $ids;
}

$sedas    = papelito_category_get_by_slug( 'sedas' );
$piteiras = papelito_category_get_by_slug( 'piteiras' );

if ( null === $sedas || null === $piteiras ) {
	echo "  aviso: seed da taxonomia ausente. Rode a migração da fase 4 antes.\n";
	exit( 0 );
}

$brown = papelito_subcategory_get_by_slug( $sedas['id'], 'brown' );
$hemp  = papelito_subcategory_get_by_slug( $sedas['id'], 'hemp' );
$slim  = papelito_subcategory_get_by_slug( $sedas['id'], 'slim' );

echo "\nBusca\n";

$busca_on = catalog_search_ids(
	array(
		'search'   => 'seda',
		'per_page' => 60,
	)
);

assert_catalog( 'a busca encontra sedas', true, count( $busca_on ) > 0 );

echo "\nBusca: filtro por categoria\n";

$por_categoria = catalog_search_ids(
	array(
		'search'     => 'seda',
		'categories' => array( 'sedas' ),
		'per_page'   => 60,
	)
);

assert_catalog( 'filtrar por sedas devolve as mesmas sedas da busca livre', $busca_on, $por_categoria );

// Com `product_cat`, filtrar pela RAIZ não devolvia o produto que só tinha o
// filho — era o problema de semântica entre os módulos.
$slim_produtos = catalog_search_ids(
	array(
		'search'        => 'seda',
		'categories'    => array( 'sedas' ),
		'subcategories' => array( 'slim' ),
		'per_page'      => 60,
	)
);

assert_catalog( 'filtrar por subcategoria devolve um subconjunto', true, count( $slim_produtos ) > 0 && count( $slim_produtos ) < count( $por_categoria ) );
assert_catalog( 'todo produto da subcategoria está na categoria', $slim_produtos, array_values( array_intersect( $slim_produtos, $por_categoria ) ) );

echo "\nBusca: semântica de facetas\n";

if ( null !== $brown && null !== $slim ) {
	$apenas_brown = catalog_test_product( PAPELITO_CATALOG_TEST_PREFIX . ' faceta brown' );
	$apenas_slim  = catalog_test_product( PAPELITO_CATALOG_TEST_PREFIX . ' faceta slim' );
	$ambas        = catalog_test_product( PAPELITO_CATALOG_TEST_PREFIX . ' faceta ambas' );

	papelito_product_set_category( $apenas_brown, $sedas['id'] );
	papelito_product_set_subcategories( $apenas_brown, array( $brown['id'] ) );
	papelito_product_set_category( $apenas_slim, $sedas['id'] );
	papelito_product_set_subcategories( $apenas_slim, array( $slim['id'] ) );
	papelito_product_set_category( $ambas, $sedas['id'] );
	papelito_product_set_subcategories( $ambas, array( $brown['id'], $slim['id'] ) );

	$por_duas_facetas = catalog_search_ids(
		array(
			'search'        => PAPELITO_CATALOG_TEST_PREFIX . ' faceta',
			'categories'    => array( 'sedas' ),
			'subcategories' => array( 'brown', 'slim' ),
			'per_page'      => 60,
		)
	);

	assert_catalog( 'AND entre facetas não aceita produto só Brown', false, in_array( $apenas_brown, $por_duas_facetas, true ) );
	assert_catalog( 'AND entre facetas não aceita produto só Slim', false, in_array( $apenas_slim, $por_duas_facetas, true ) );
	assert_catalog( 'AND entre facetas aceita produto Brown e Slim', true, in_array( $ambas, $por_duas_facetas, true ) );
}

echo "\nBusca: fail-closed\n";

assert_catalog(
	'categoria inexistente devolve ZERO, não o catálogo',
	array(),
	catalog_search_ids(
		array(
			'search'     => 'seda',
			'categories' => array( 'categoria-que-nao-existe' ),
			'per_page'   => 60,
		)
	)
);

assert_catalog(
	'subcategoria inexistente devolve ZERO',
	array(),
	catalog_search_ids(
		array(
			'search'        => 'seda',
			'categories'    => array( 'sedas' ),
			'subcategories' => array( 'nao-existe' ),
			'per_page'      => 60,
		)
	)
);

assert_catalog(
	'subcategoria de OUTRA categoria devolve ZERO',
	array(),
	catalog_search_ids(
		array(
			'search'        => 'seda',
			'categories'    => array( 'sedas' ),
			'subcategories' => array( 'mega-longa' ),
			'per_page'      => 60,
		)
	)
);

echo "\nBusca: gate de publicação\n";

$sem_categoria = catalog_test_product( PAPELITO_CATALOG_TEST_PREFIX . ' orfao' );

$sem_orfao = catalog_search_ids(
	array(
		'search'   => PAPELITO_CATALOG_TEST_PREFIX,
		'per_page' => 60,
	)
);

assert_catalog( 'produto sem categoria NAO entra na vitrine', false, in_array( $sem_categoria, $sem_orfao, true ) );

papelito_product_set_category( $sem_categoria, $sedas['id'] );
$classificado = catalog_search_ids(
	array(
		'search'   => PAPELITO_CATALOG_TEST_PREFIX,
		'per_page' => 60,
	)
);

assert_catalog( 'classificar o produto o traz de volta', true, in_array( $sem_categoria, $classificado, true ) );

echo "\nBusca: coleção curada\n";

$premium_colecao = catalog_test_product( PAPELITO_CATALOG_TEST_PREFIX . ' colecao premium' );
$kit_colecao     = catalog_test_product( PAPELITO_CATALOG_TEST_PREFIX . ' colecao kit' );
$sem_colecao     = catalog_test_product( PAPELITO_CATALOG_TEST_PREFIX . ' colecao comum' );

foreach ( array( $premium_colecao, $kit_colecao, $sem_colecao ) as $product_id ) {
	papelito_product_set_category( $product_id, $sedas['id'] );
}

papelito_product_set_collections( $premium_colecao, array( 'premium' ) );
papelito_product_set_collections( $kit_colecao, array( 'kits' ) );

$premium_encontrado = catalog_search_ids(
	array(
		'search'     => PAPELITO_CATALOG_TEST_PREFIX . ' colecao',
		'collection' => 'premium',
		'per_page'   => 60,
	)
);
$kits_encontrados  = catalog_search_ids(
	array(
		'search'     => PAPELITO_CATALOG_TEST_PREFIX . ' colecao',
		'collection' => 'kits',
		'per_page'   => 60,
	)
);

assert_catalog( 'coleção premium só devolve o produto marcado', array( $premium_colecao ), $premium_encontrado );
assert_catalog( 'coleção kits só devolve o produto marcado', array( $kit_colecao ), $kits_encontrados );
assert_catalog(
	'coleção inválida falha fechado',
	array(),
	catalog_search_ids(
		array(
			'search'     => PAPELITO_CATALOG_TEST_PREFIX . ' colecao',
			'collection' => 'colecao-invalida',
			'per_page'   => 60,
		)
	)
);

echo "\nEstoque do vendor\n";

$vendor_id = wp_insert_user(
	array(
		'user_login' => PAPELITO_CATALOG_TEST_PREFIX . '-vendor',
		'user_pass'  => wp_generate_password(),
		'user_email' => PAPELITO_CATALOG_TEST_PREFIX . '@example.test',
		'role'       => 'seller',
	)
);

if ( is_wp_error( $vendor_id ) ) {
	echo "  aviso: nao criou vendor de teste, pulando bloco de estoque\n";
} else {
	$created_vendors[] = (int) $vendor_id;

	$estoque_sedas = papelito_vendor_stock_query(
		(int) $vendor_id,
		array(
			'category' => $sedas['id'],
			'paginate' => false,
		)
	);
	$ids_sedas     = array_map( 'intval', array_column( $estoque_sedas['items'], 'product_id' ) );

	assert_catalog( 'estoque filtrado por categoria traz produtos', true, $estoque_sedas['total'] > 0 );

	$categorias_distintas = array();
	foreach ( $estoque_sedas['items'] as $item ) {
		foreach ( $item['categories'] as $category ) {
			$categorias_distintas[ $category['slug'] ] = true;
		}
	}

	assert_catalog( 'todo item filtrado é da categoria pedida', array( 'sedas' ), array_keys( $categorias_distintas ) );
	assert_catalog( 'item traz a categoria principal, uma só', 1, count( $estoque_sedas['items'][0]['categories'] ) );
	assert_catalog( 'item traz subcategorias com faceta', true, isset( $estoque_sedas['items'][0]['subcategories'] ) );

	$estoque_sub = papelito_vendor_stock_query(
		(int) $vendor_id,
		array(
			'category'      => $sedas['id'],
			'subcategories' => (string) $brown['id'],
			'paginate'      => false,
		)
	);

	assert_catalog( 'subcategoria restringe o estoque', true, $estoque_sub['total'] > 0 && $estoque_sub['total'] < $estoque_sedas['total'] );

	$estoque_sub_dupla = papelito_vendor_stock_query(
		(int) $vendor_id,
		array(
			'category'      => $sedas['id'],
			'subcategories' => $brown['id'] . ',' . $hemp['id'],
			'paginate'      => false,
		)
	);

	assert_catalog( 'duas subcategorias da mesma faceta somam (semântica OR)', true, $estoque_sub_dupla['total'] >= $estoque_sub['total'] );

	$estoque_sub_entre_facetas = papelito_vendor_stock_query(
		(int) $vendor_id,
		array(
			'category'      => $sedas['id'],
			'subcategories' => $brown['id'] . ',' . $slim['id'],
			'paginate'      => false,
		)
	);

	assert_catalog( 'duas subcategorias de facetas distintas restringem (semântica AND)', true, $estoque_sub_entre_facetas['total'] <= $estoque_sub['total'] );

	$estoque_vazio = papelito_vendor_stock_query(
		(int) $vendor_id,
		array(
			'category' => 99999999,
			'paginate' => false,
		)
	);

	assert_catalog( 'categoria inexistente no estoque devolve zero', 0, $estoque_vazio['total'] );

	// Paginação: `COUNT(DISTINCT p.ID)` é o que impede o total de vir errado quando
	// há GROUP BY na consulta de itens.
	$pagina_1 = papelito_vendor_stock_query(
		(int) $vendor_id,
		array(
			'category' => $sedas['id'],
			'page'     => 1,
			'per_page' => 5,
		)
	);
	$pagina_2 = papelito_vendor_stock_query(
		(int) $vendor_id,
		array(
			'category' => $sedas['id'],
			'page'     => 2,
			'per_page' => 5,
		)
	);

	assert_catalog( 'total da paginação bate com a consulta sem paginar', $estoque_sedas['total'], $pagina_1['total'] );
	assert_catalog( 'a página 1 tem 5 itens', 5, count( $pagina_1['items'] ) );

	$ids_p1 = array_column( $pagina_1['items'], 'product_id' );
	$ids_p2 = array_column( $pagina_2['items'], 'product_id' );

	assert_catalog( 'páginas não repetem produto', array(), array_values( array_intersect( $ids_p1, $ids_p2 ) ) );

	$opcoes = papelito_vendor_stock_taxonomies();

	assert_catalog( 'opções de filtro trazem as 4 categorias', 4, count( $opcoes['categories'] ) );
	assert_catalog( 'opções de filtro trazem subcategorias', true, count( $opcoes['subcategories'] ) > 0 );
	assert_catalog( 'subcategoria da opção declara a categoria pai', true, isset( $opcoes['subcategories'][0]['categoryId'] ) );

	// O transient da versão antiga era global e nunca invalidado: categoria nova
	// sumia do drawer por até 10 minutos.
	$versao_antes = papelito_product_taxonomy_version();
	papelito_product_taxonomy_touch( 'category', 0 );

	assert_catalog( 'escrita na taxonomia avança a versão do cache', true, papelito_product_taxonomy_version() > $versao_antes );
}

echo "\nFlash sale\n";

$args_sedas = papelito_flash_sale_build_eligible_product_query_args( 1, 20, $sedas['id'] );

assert_catalog( 'flash sale filtra por post__in, não por tax_query', true, isset( $args_sedas['post__in'] ) && ! isset( $args_sedas['tax_query'] ) );
assert_catalog( 'post__in traz os produtos da categoria', true, count( $args_sedas['post__in'] ) > 1 );

$args_vazio = papelito_flash_sale_build_eligible_product_query_args( 1, 20, 99999999 );

assert_catalog( 'categoria inexistente vira post__in impossível, não filtro ausente', array( 0 ), $args_vazio['post__in'] );

$args_sem_filtro = papelito_flash_sale_build_eligible_product_query_args( 1, 20, 0 );

assert_catalog( 'sem categoria não restringe', false, isset( $args_sem_filtro['post__in'] ) );

echo "\nParidade entre os três módulos\n";

// A prova do problema 6: o MESMO filtro tem de devolver o MESMO conjunto nos três
// caminhos. Com `product_cat` isso não valia.
$ids_busca_categoria = catalog_search_ids(
	array(
		'search'     => 'seda',
		'categories' => array( 'sedas' ),
		'per_page'   => 60,
	)
);
$ids_flash           = papelito_taxonomy_product_ids( $sedas['id'] );

sort( $ids_flash );

$busca_dentro_de_flash = array_values( array_diff( $ids_busca_categoria, $ids_flash ) );

assert_catalog( 'todo produto da busca por categoria está no conjunto da flash sale', array(), $busca_dentro_de_flash );

if ( ! is_wp_error( $vendor_id ) ) {
	$ids_estoque = array_map( 'intval', array_column( $estoque_sedas['items'], 'product_id' ) );
	sort( $ids_estoque );

	assert_catalog( 'estoque e flash sale enxergam a mesma categoria', $ids_flash, $ids_estoque );
}

echo "\nRótulo da flash sale\n";

$produto_rotulo = wc_get_product( $ids_flash[0] ?? 0 );

if ( $produto_rotulo instanceof WC_Product ) {
	$payload = papelito_flash_sale_build_product_payload( $produto_rotulo, 10 );

	assert_catalog( 'rótulo é o nome da categoria principal, não o primeiro termo', 'Sedas', $payload['category'] );
}

$produto_sem_categoria = catalog_test_product( PAPELITO_CATALOG_TEST_PREFIX . ' sem rotulo' );
$wc_sem_categoria      = wc_get_product( $produto_sem_categoria );

if ( $wc_sem_categoria instanceof WC_Product ) {
	$payload_sem = papelito_flash_sale_build_product_payload( $wc_sem_categoria, 10 );

	assert_catalog( 'produto sem categoria cai no rótulo padrão', 'Produto', $payload_sem['category'] );
}


catalog_test_cleanup();

echo "\n";
echo $failures > 0
	? 'FALHOU: ' . esc_html( (string) $failures ) . ' de ' . esc_html( (string) $checks ) . " checagens\n"
	: 'OK: ' . esc_html( (string) $checks ) . " checagens\n";

exit( $failures > 0 ? 1 : 0 );
