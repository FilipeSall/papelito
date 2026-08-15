<?php
/**
 * Contratos REST e GraphQL da taxonomia própria de produtos.
 *
 * O teste que justifica a fase é o de CONTAGEM DE CONSULTAS: resolver a
 * taxonomia de N produtos tem de custar 3 consultas, não 3N. Sem isso, expor os
 * campos no WPGraphQL derruba a performance do catálogo.
 *
 *   wp eval-file tests/test-product-taxonomy-api.php
 *
 * Cria categorias e produtos descartáveis com prefixo reservado e apaga tudo no
 * fim.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-product-taxonomy-api.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * Prefixo reservado: nada fora dele é tocado pela limpeza.
 */
const PAPELITO_TAXONOMY_API_TEST_PREFIX = 'zzz-teste-api-taxonomia';

/**
 * Quantos produtos entram na medição de N+1.
 */
const PAPELITO_TAXONOMY_API_TEST_PRODUCTS          = 40;
const PAPELITO_TAXONOMY_API_PUBLIC_ROUTE           = '/papelito/v1/categories';
const PAPELITO_TAXONOMY_API_ADMIN_CATEGORIES_ROUTE = '/papelito/v1/admin/categories';
const PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE   = '/papelito/v1/admin/categories/';
const PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_ROUTE   = '/papelito/v1/admin/products/';
const PAPELITO_TAXONOMY_API_TAXONOMY_ROUTE         = '/taxonomy';
const PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_TAXONOMY_ROUTE = PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_ROUTE . 'taxonomy?productIds=';
const PAPELITO_TAXONOMY_API_SEDAS_SUFFIX           = '-sedas';
const PAPELITO_TAXONOMY_API_NOVA_SUFFIX            = '-nova';
const PAPELITO_TAXONOMY_API_CHECKS_SUFFIX          = " checagens\n";
const PAPELITO_TAXONOMY_API_ADMIN_SUBCATEGORY_ROUTE = '/papelito/v1/admin/subcategories/';
const PAPELITO_TAXONOMY_API_SUBCATEGORIES_ROUTE     = '/subcategories';
const PAPELITO_TAXONOMY_API_PUT                     = 'PUT';

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
function assert_api( string $label, $expected, $actual ): void {
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
 * Cria uma categoria descartável.
 *
 * @param string $suffix Sufixo do slug.
 * @return int
 */
function api_test_category( string $suffix ): int {
	global $created_categories;

	$id = papelito_category_create(
		array(
			'name' => 'API ' . $suffix,
			'slug' => PAPELITO_TAXONOMY_API_TEST_PREFIX . '-' . $suffix,
		)
	);

	if ( is_wp_error( $id ) ) {
		echo '  FAIL nao criou a categoria ' . esc_html( $suffix ) . ': ' . esc_html( $id->get_error_message() ) . "\n";
		exit( 1 );
	}

	$created_categories[] = (int) $id;

	return (int) $id;
}

/**
 * Cria um produto descartável.
 *
 * @param string $suffix Sufixo do título.
 * @return int
 */
function api_test_product( string $suffix ): int {
	global $created_products;

	$id = wp_insert_post(
		array(
			'post_title'  => 'Produto ' . PAPELITO_TAXONOMY_API_TEST_PREFIX . ' ' . $suffix,
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
 * Executa uma rota REST interna e devolve status e corpo.
 *
 * @param string              $method Método HTTP.
 * @param string              $route  Rota completa.
 * @param array<string,mixed> $body   Corpo JSON.
 * @return array{status:int,data:mixed}
 */
function api_test_request( string $method, string $route, array $body = array() ): array {
	// `WP_REST_Request` não faz parse de query string no path: sem separar, a rota
	// não casa e a resposta vem 404 — que o teste leria como bug do endpoint.
	$query = array();

	if ( str_contains( $route, '?' ) ) {
		list( $route, $raw_query ) = explode( '?', $route, 2 );
		parse_str( $raw_query, $query );
	}

	$request = new WP_REST_Request( $method, $route );

	foreach ( $query as $key => $value ) {
		$request->set_param( $key, $value );
	}

	if ( ! empty( $body ) ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
	}

	$response = rest_do_request( $request );

	return array(
		'status' => (int) $response->get_status(),
		'data'   => $response->get_data(),
	);
}

/**
 * Apaga tudo que o teste criou.
 *
 * @return void
 */
function api_test_cleanup(): void {
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

$sedas       = api_test_category( 'sedas' );
$piteiras    = api_test_category( 'piteiras' );
$brown       = papelito_subcategory_create(
	array(
		'categoryId' => $sedas,
		'name'       => 'Brown',
		'facet'      => 'material',
	)
);
$king        = papelito_subcategory_create(
	array(
		'categoryId' => $sedas,
		'name'       => 'King Size',
		'facet'      => 'formato',
	)
);
$oculta      = papelito_subcategory_create(
	array(
		'categoryId' => $sedas,
		'name'       => 'Oculta',
		'facet'      => 'teste',
	)
);
$piteira_sub = papelito_subcategory_create(
	array(
		'categoryId' => $piteiras,
		'name'       => 'Slim',
		'facet'      => 'tamanho',
	)
);

echo "\nContagem de consultas (o critério de aceite da fase)\n";

$produtos = array();

for ( $i = 0; $i < PAPELITO_TAXONOMY_API_TEST_PRODUCTS; $i++ ) {
	$produto = api_test_product( (string) $i );
	papelito_product_set_category( $produto, $sedas );
	papelito_product_set_subcategories( $produto, array( $brown, $king ) );
	papelito_product_set_collections( $produto, array( 'premium' ) );
	$produtos[] = $produto;
}

papelito_taxonomy_flush_request_cache();
papelito_taxonomy_note_products( $produtos );

$antes = (int) $wpdb->num_queries;

foreach ( $produtos as $produto ) {
	papelito_taxonomy_cached_category( $produto );
	papelito_taxonomy_cached_subcategories( $produto );
	papelito_taxonomy_cached_collections( $produto );
}

$consultas = (int) $wpdb->num_queries - $antes;

assert_api( 'resolver ' . count( $produtos ) . ' produtos custa 3 consultas', 3, $consultas );

papelito_taxonomy_flush_request_cache();
$antes = (int) $wpdb->num_queries;
papelito_taxonomy_cached_category( $produtos[0] );
assert_api( 'produto avulso, sem lote anotado, também custa 3', 3, (int) $wpdb->num_queries - $antes );

$antes = (int) $wpdb->num_queries;
papelito_taxonomy_cached_category( $produtos[0] );
papelito_taxonomy_cached_subcategories( $produtos[0] );
assert_api( 'segunda leitura do mesmo produto não consulta', 0, (int) $wpdb->num_queries - $antes );

papelito_taxonomy_flush_request_cache();
papelito_taxonomy_note_products( $produtos );
papelito_taxonomy_cached_category( $produtos[0] );
$antes = (int) $wpdb->num_queries;

foreach ( $produtos as $produto ) {
	papelito_taxonomy_cached_subcategories( $produto );
}

assert_api( 'lote inteiro já veio no primeiro prime', 0, (int) $wpdb->num_queries - $antes );

assert_api( 'o dado servido pelo cache está correto', $sedas, papelito_taxonomy_cached_category( $produtos[5] )['id'] );
assert_api( 'subcategorias vêm ordenadas por faceta', array( 'formato', 'material' ), array_column( papelito_taxonomy_cached_subcategories( $produtos[5] ), 'facet' ) );
assert_api( 'coleções vêm pelo cache', array( 'premium' ), papelito_taxonomy_cached_collections( $produtos[5] ) );

papelito_product_set_category( $produtos[0], $piteiras );
assert_api( 'escrita invalida o cache de request', $piteiras, papelito_taxonomy_cached_category( $produtos[0] )['id'] );
assert_api( 'trocar categoria pela API limpou as subcategorias', 0, count( papelito_taxonomy_cached_subcategories( $produtos[0] ) ) );

papelito_product_set_category( $produtos[0], $sedas );
papelito_product_set_subcategories( $produtos[0], array( $brown, $king ) );

echo "\nREST público\n";

$publico = api_test_request( 'GET', PAPELITO_TAXONOMY_API_PUBLIC_ROUTE );

assert_api( 'GET /categories responde 200', 200, $publico['status'] );
assert_api( 'payload traz a versão da taxonomia', true, isset( $publico['data']['version'] ) );

$slugs = array_column( $publico['data']['categories'], 'slug' );

assert_api( 'categoria criada aparece no público', true, in_array( PAPELITO_TAXONOMY_API_TEST_PREFIX . PAPELITO_TAXONOMY_API_SEDAS_SUFFIX, $slugs, true ) );

$sedas_publica = null;

foreach ( $publico['data']['categories'] as $categoria ) {
	if ( PAPELITO_TAXONOMY_API_TEST_PREFIX . PAPELITO_TAXONOMY_API_SEDAS_SUFFIX === $categoria['slug'] ) {
		$sedas_publica = $categoria;
	}
}

assert_api( 'categoria pública traz as subcategorias', 3, count( $sedas_publica['subcategories'] ) );
assert_api( 'subcategoria pública traz a faceta', true, isset( $sedas_publica['subcategories'][0]['facet'] ) );
assert_api( 'payload público não vaza isActive', false, array_key_exists( 'isActive', $sedas_publica ) );

$bloqueio = papelito_subcategory_update( $king, array( 'isActive' => false ) );
assert_api( 'não desativa subcategoria vinculada a produto', true, is_wp_error( $bloqueio ) );
assert_api( 'bloqueio de subcategoria vinculada tem código estável', 'papelito_subcategory_in_use', $bloqueio instanceof WP_Error ? $bloqueio->get_error_code() : '' );
papelito_subcategory_update( $oculta, array( 'isActive' => false ) );
$publico_2 = api_test_request( 'GET', PAPELITO_TAXONOMY_API_PUBLIC_ROUTE );

foreach ( $publico_2['data']['categories'] as $categoria ) {
	if ( PAPELITO_TAXONOMY_API_TEST_PREFIX . PAPELITO_TAXONOMY_API_SEDAS_SUFFIX === $categoria['slug'] ) {
		assert_api( 'desativar subcategoria sem vínculos some do público na hora', 2, count( $categoria['subcategories'] ) );
	}
}

assert_api( 'cache versionado avançou', true, $publico_2['data']['version'] > $publico['data']['version'] );
papelito_subcategory_update( $oculta, array( 'isActive' => true ) );

echo "\nREST admin: permissão\n";

wp_set_current_user( 0 );
assert_api( 'admin/categories exige autenticação', 401, api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_CATEGORIES_ROUTE )['status'] );

$assinante = wp_insert_user(
	array(
		'user_login' => PAPELITO_TAXONOMY_API_TEST_PREFIX . '-sub',
		'user_pass'  => wp_generate_password(),
		'user_email' => PAPELITO_TAXONOMY_API_TEST_PREFIX . '@example.test',
		'role'       => 'subscriber',
	)
);

if ( ! is_wp_error( $assinante ) ) {
	wp_set_current_user( (int) $assinante );
	assert_api( 'assinante não acessa admin/categories', 403, api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_CATEGORIES_ROUTE )['status'] );
	wp_delete_user( (int) $assinante );
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( empty( $admins ) ) {
	echo "  aviso: sem administrador no ambiente, pulando o bloco admin\n";
	api_test_cleanup();
	echo "\n" . ( $failures > 0 ? 'FALHOU: ' . esc_html( (string) $failures ) . esc_html( PAPELITO_TAXONOMY_API_CHECKS_SUFFIX ) : 'OK: ' . esc_html( (string) $checks ) . esc_html( PAPELITO_TAXONOMY_API_CHECKS_SUFFIX ) );
	exit( $failures > 0 ? 1 : 0 );
}

wp_set_current_user( (int) $admins[0] );

echo "\nREST admin: leitura e escrita\n";

$admin = api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_CATEGORIES_ROUTE );

assert_api( 'admin/categories responde 200', 200, $admin['status'] );
assert_api( 'admin lista as coleções curadas', array( 'premium', 'kits' ), $admin['data']['collections'] );

$sedas_admin = null;

foreach ( $admin['data']['categories'] as $categoria ) {
	if ( $sedas === $categoria['id'] ) {
		$sedas_admin = $categoria;
	}
}

assert_api( 'admin enxerga contagem de publicados', PAPELITO_TAXONOMY_API_TEST_PRODUCTS, $sedas_admin['productCount']['published'] );
assert_api( 'admin enxerga contagem por subcategoria', PAPELITO_TAXONOMY_API_TEST_PRODUCTS, $sedas_admin['subcategories'][0]['productCount'] );

$criada = api_test_request(
	'POST',
	PAPELITO_TAXONOMY_API_ADMIN_CATEGORIES_ROUTE,
	array(
		'name' => 'API nova',
		'slug' => PAPELITO_TAXONOMY_API_TEST_PREFIX . PAPELITO_TAXONOMY_API_NOVA_SUFFIX,
	)
);

assert_api( 'POST /admin/categories cria e devolve 201', 201, $criada['status'] );

if ( 201 === $criada['status'] ) {
	$created_categories[] = (int) $criada['data']['id'];
	$nova                 = (int) $criada['data']['id'];

	assert_api(
		'slug duplicado devolve 409',
		409,
		api_test_request(
			'POST',
			PAPELITO_TAXONOMY_API_ADMIN_CATEGORIES_ROUTE,
			array(
				'name' => 'Outra',
				'slug' => PAPELITO_TAXONOMY_API_TEST_PREFIX . PAPELITO_TAXONOMY_API_NOVA_SUFFIX,
			)
		)['status']
	);
	assert_api( 'PUT atualiza o nome', 'API renomeada', api_test_request( 'PUT', PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . $nova, array( 'name' => 'API renomeada' ) )['data']['name'] );
	assert_api( 'DELETE arquiva categoria vazia', 200, api_test_request( 'DELETE', PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . $nova )['status'] );
	assert_api( 'categoria arquivada sai do público', false, in_array( PAPELITO_TAXONOMY_API_TEST_PREFIX . PAPELITO_TAXONOMY_API_NOVA_SUFFIX, array_column( api_test_request( 'GET', PAPELITO_TAXONOMY_API_PUBLIC_ROUTE )['data']['categories'], 'slug' ), true ) );
	assert_api( 'restore reativa', true, api_test_request( 'POST', PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . $nova . '/restore' )['data']['isActive'] );
}

assert_api( 'DELETE recusa categoria com produto publicado', 409, api_test_request( 'DELETE', PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . $sedas )['status'] );
assert_api( 'categoria inexistente devolve 404', 404, api_test_request( 'PUT', PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . '99999999', array( 'name' => 'x' ) )['status'] );

echo "\nREST admin: escrita devolve o mesmo formato da leitura\n";

// Mutação que devolve a linha crua entrega um objeto sem `productCount`, e o
// painel que confiar na resposta do PUT em vez de recarregar mostra contagem
// zerada. Leitura e escrita têm de ter exatamente as mesmas chaves.
$arvore          = api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_CATEGORIES_ROUTE )['data'];
$sedas_da_arvore = null;

foreach ( $arvore['categories'] as $categoria ) {
	if ( $sedas === $categoria['id'] ) {
		$sedas_da_arvore = $categoria;
	}
}

$brown_da_arvore = null;

foreach ( $sedas_da_arvore['subcategories'] as $subcategoria ) {
	if ( $brown === $subcategoria['id'] ) {
		$brown_da_arvore = $subcategoria;
	}
}

$chaves_categoria    = array_keys( $sedas_da_arvore );
$chaves_subcategoria = array_keys( $brown_da_arvore );

sort( $chaves_categoria );
sort( $chaves_subcategoria );

$sub_editada = api_test_request( PAPELITO_TAXONOMY_API_PUT, PAPELITO_TAXONOMY_API_ADMIN_SUBCATEGORY_ROUTE . $brown, array( 'name' => 'Brown editada' ) );
$chaves_put  = array_keys( $sub_editada['data'] );

sort( $chaves_put );

assert_api( 'PUT /admin/subcategories/{id} responde 200', 200, $sub_editada['status'] );
assert_api( 'PUT de subcategoria devolve as chaves da leitura', $chaves_subcategoria, $chaves_put );
assert_api( 'PUT de subcategoria devolve productCount', $brown_da_arvore['productCount'], $sub_editada['data']['productCount'] );
assert_api( 'PUT de subcategoria devolve o nome novo', 'Brown editada', $sub_editada['data']['name'] );

papelito_subcategory_update( $brown, array( 'name' => 'Brown' ) );

$sub_criada = api_test_request(
	'POST',
	PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . $sedas . PAPELITO_TAXONOMY_API_SUBCATEGORIES_ROUTE,
	array(
		'name'  => 'Recém-criada',
		'facet' => 'formato',
	)
);
$chaves_post = array_keys( $sub_criada['data'] );

sort( $chaves_post );

assert_api( 'POST de subcategoria devolve as chaves da leitura', $chaves_subcategoria, $chaves_post );
assert_api( 'subcategoria nova nasce com productCount zero', 0, $sub_criada['data']['productCount'] );

$sub_arquivada = api_test_request( 'DELETE', PAPELITO_TAXONOMY_API_ADMIN_SUBCATEGORY_ROUTE . (int) $sub_criada['data']['id'] );
$chaves_delete = array_keys( $sub_arquivada['data'] );

sort( $chaves_delete );

assert_api( 'DELETE de subcategoria devolve as chaves da leitura', $chaves_subcategoria, $chaves_delete );

$listagem_sub = api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . $sedas . PAPELITO_TAXONOMY_API_SUBCATEGORIES_ROUTE );
$chaves_lista = array_keys( $listagem_sub['data'][0] );

sort( $chaves_lista );

assert_api( 'GET de subcategorias devolve as chaves da árvore', $chaves_subcategoria, $chaves_lista );

$reordenada    = api_test_request(
	PAPELITO_TAXONOMY_API_PUT,
	PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . $sedas . PAPELITO_TAXONOMY_API_SUBCATEGORIES_ROUTE . '/reorder',
	array( 'ids' => array_column( $listagem_sub['data'], 'id' ) )
);
$chaves_reorder = array_keys( $reordenada['data'][0] );

sort( $chaves_reorder );

assert_api( 'reorder de subcategorias devolve as chaves da árvore', $chaves_subcategoria, $chaves_reorder );

$cat_editada = api_test_request( PAPELITO_TAXONOMY_API_PUT, PAPELITO_TAXONOMY_API_ADMIN_CATEGORY_ROUTE . $sedas, array( 'seoTitle' => 'Sedas para teste' ) );
$chaves_cat  = array_keys( $cat_editada['data'] );

sort( $chaves_cat );

assert_api( 'PUT de categoria devolve as chaves da leitura', $chaves_categoria, $chaves_cat );
assert_api( 'PUT de categoria devolve productCount', $sedas_da_arvore['productCount'], $cat_editada['data']['productCount'] );
$sedas_agora = null;

foreach ( api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_CATEGORIES_ROUTE )['data']['categories'] as $categoria ) {
	if ( $sedas === $categoria['id'] ) {
		$sedas_agora = $categoria;
	}
}

assert_api( 'PUT de categoria devolve as subcategorias', count( $sedas_agora['subcategories'] ), count( $cat_editada['data']['subcategories'] ) );

papelito_category_update( $sedas, array( 'seoTitle' => '' ) );

echo "\nREST admin: taxonomia do produto\n";

$alvo = $produtos[1];

assert_api(
	'PUT /products/{id}/taxonomy grava categoria e subcategorias',
	$sedas,
	api_test_request(
		'PUT',
		PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_ROUTE . $alvo . PAPELITO_TAXONOMY_API_TAXONOMY_ROUTE,
		array(
			'categoryId'     => $sedas,
			'subcategoryIds' => array( $brown ),
			'collections'    => array( 'kits' ),
		)
	)['data']['category']['id']
);

$lido = api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_ROUTE . $alvo . PAPELITO_TAXONOMY_API_TAXONOMY_ROUTE );

assert_api( 'GET devolve a subcategoria gravada', 1, count( $lido['data']['subcategories'] ) );
assert_api( 'GET devolve a coleção gravada', array( 'kits' ), $lido['data']['collections'] );

assert_api(
	'subcategoria de outra categoria devolve 422',
	422,
	api_test_request(
		'PUT',
		PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_ROUTE . $alvo . PAPELITO_TAXONOMY_API_TAXONOMY_ROUTE,
		array( 'subcategoryIds' => array( $piteira_sub ) )
	)['status']
);

assert_api(
	'coleção desconhecida devolve 422',
	422,
	api_test_request(
		'PUT',
		PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_ROUTE . $alvo . PAPELITO_TAXONOMY_API_TAXONOMY_ROUTE,
		array( 'collections' => array( 'inventada' ) )
	)['status']
);

echo "\nFase 7: o painel deixa de falar product_cat\n";

$admin_payload = api_test_request( 'GET', '/papelito/v1/admin/categories' )['data'];
$sedas_admin   = null;

foreach ( $admin_payload['categories'] as $categoria ) {
	if ( $sedas === $categoria['id'] ) {
		$sedas_admin = $categoria;
	}
}


$lote = api_test_request(
	'GET',
	PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_TAXONOMY_ROUTE . implode( ',', array_slice( $produtos, 0, 3 ) )
);

assert_api( 'lote de taxonomia responde 200', 200, $lote['status'] );
assert_api( 'lote traz um item por produto pedido', 3, count( $lote['data']['items'] ) );

$primeiro = $lote['data']['items'][ (string) $produtos[0] ];

assert_api( 'item do lote traz a categoria', $sedas, $primeiro['category']['id'] );
assert_api( 'item do lote traz as subcategorias', 2, count( $primeiro['subcategories'] ) );
assert_api( 'item do lote traz as coleções', array( 'premium' ), $primeiro['collections'] );

$lote_misto = api_test_request(
	'GET',
	PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_TAXONOMY_ROUTE . $produtos[0] . ',99999999,,abc'
);

// Id não numérico e vazio são descartados; id numérico inexistente volta com
// categoria nula — o chamador precisa distinguir "sem taxonomia" de "não pedi".
assert_api( 'lote descarta id não numérico', 2, count( $lote_misto['data']['items'] ) );
assert_api( 'produto inexistente volta com categoria nula', null, $lote_misto['data']['items']['99999999']['category'] );
assert_api( 'lote vazio devolve mapa vazio', array(), api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_TAXONOMY_ROUTE )['data']['items'] );

// Uma consulta por conjunto, não por produto: a lista do admin não pode ser N+1.
$antes_lote = (int) $wpdb->num_queries;
api_test_request( 'GET', PAPELITO_TAXONOMY_API_ADMIN_PRODUCTS_TAXONOMY_ROUTE . implode( ',', $produtos ) );
$custo_lote = (int) $wpdb->num_queries - $antes_lote;

assert_api( 'lote de ' . count( $produtos ) . ' produtos custa poucas consultas', true, $custo_lote <= 6 );

echo "\nGraphQL\n";

if ( ! function_exists( 'graphql' ) ) {
	echo "  aviso: WPGraphQL ausente, pulando o bloco GraphQL\n";
} else {
	$resultado = graphql( array( 'query' => '{ papelitoCategories { databaseId slug name subcategories { slug facet } } }' ) );

	assert_api( 'papelitoCategories não retorna erro', false, isset( $resultado['errors'] ) );

	$retornadas = array_column( $resultado['data']['papelitoCategories'] ?? array(), 'slug' );

	assert_api( 'papelitoCategories traz a categoria criada', true, in_array( PAPELITO_TAXONOMY_API_TEST_PREFIX . PAPELITO_TAXONOMY_API_SEDAS_SUFFIX, $retornadas, true ) );

	$listagem_query = 'query($first: Int){ products(first: $first) { nodes { databaseId papelitoCategory { slug } papelitoSubcategories { slug facet } papelitoCollections } } }';
	$simples_query  = 'query($first: Int){ products(first: $first) { nodes { databaseId } } }';
	$variaveis      = array( 'first' => 10 );

	// Aquece: sem isto a primeira execução paga o carregamento dos posts e a
	// comparação entre as duas queries mede cache frio, não o custo da taxonomia.
	graphql(
		array(
			'query'     => $simples_query,
			'variables' => $variaveis,
		)
	);
	graphql(
		array(
			'query'     => $listagem_query,
			'variables' => $variaveis,
		)
	);

	papelito_taxonomy_flush_request_cache();
	$antes_gql = (int) $wpdb->num_queries;
	graphql(
		array(
			'query'     => $simples_query,
			'variables' => $variaveis,
		)
	);
	$custo_sem = (int) $wpdb->num_queries - $antes_gql;

	papelito_taxonomy_flush_request_cache();
	$antes_gql = (int) $wpdb->num_queries;
	$listagem  = graphql(
		array(
			'query'     => $listagem_query,
			'variables' => $variaveis,
		)
	);
	$custo_com = (int) $wpdb->num_queries - $antes_gql;

	assert_api( 'listagem GraphQL não retorna erro', false, isset( $listagem['errors'] ) );
	assert_api( 'campos de taxonomia custam no máximo 3 consultas na listagem', true, ( $custo_com - $custo_sem ) <= 3 );

	echo '  info: listagem de 10 produtos custou ' . esc_html( (string) $custo_sem ) . ' consultas sem taxonomia e ' . esc_html( (string) $custo_com ) . " com\n";
}

api_test_cleanup();

echo "\n";
echo $failures > 0
	? 'FALHOU: ' . esc_html( (string) $failures ) . ' de ' . esc_html( (string) $checks ) . esc_html( PAPELITO_TAXONOMY_API_CHECKS_SUFFIX )
	: 'OK: ' . esc_html( (string) $checks ) . esc_html( PAPELITO_TAXONOMY_API_CHECKS_SUFFIX );

exit( $failures > 0 ? 1 : 0 );
