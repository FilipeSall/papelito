<?php
/**
 * Código do vendor por item contra WooCommerce e MariaDB reais.
 *
 * Execute com wp eval-file. Cobre isolamento entre vendors, variação separada
 * do pai, validação do corpo, listagem de estoque (campo, busca e filtro),
 * exportação e limpeza; fixtures descartáveis são removidas no finally.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Execute com wp eval-file.\n" );
}

if ( ! function_exists( 'papelito_vendor_item_settings_install_table' ) ) {
	WP_CLI::error( 'A persistência de atributos por item ainda não existe.' );
}

// phpcs:disable WordPress.Security.EscapeOutput -- Teste WP-CLI não renderiza HTML.

const ITEM_SETTINGS_TEST_CODE_A        = '000419';
const ITEM_SETTINGS_TEST_CODE_B        = 'MAT-8810';
const ITEM_SETTINGS_TEST_VARIATION     = 'VAR-77';
const ITEM_SETTINGS_TEST_FIXTURE_LOGIN = 'zzz-item-settings-';

global $wpdb, $item_settings_checks;
$item_settings_checks = 0;

/**
 * Falha imediatamente com código não zero, incluindo o nome da invariante.
 *
 * @param string $label    Invariante conferida.
 * @param mixed  $expected Valor esperado.
 * @param mixed  $actual   Valor obtido.
 * @throws UnexpectedValueException Quando os valores divergem.
 */
function papelito_item_settings_db_check( string $label, mixed $expected, mixed $actual ): void {
	global $item_settings_checks;
	++$item_settings_checks;
	if ( $expected !== $actual ) {
		throw new UnexpectedValueException( $label . ': esperado ' . wp_json_encode( $expected ) . ', recebido ' . wp_json_encode( $actual ) );
	}
}

/**
 * Exige erro com o status REST correspondente.
 *
 * @param string $label  Caso conferido.
 * @param int    $status Status HTTP esperado.
 * @param mixed  $result Retorno do serviço.
 */
function papelito_item_settings_db_error( string $label, int $status, mixed $result ): void {
	papelito_item_settings_db_check( $label . ' retorna erro', true, is_wp_error( $result ) );
	papelito_item_settings_db_check( $label . ' status', $status, $result->get_error_data()['status'] );
}

/**
 * Dispara uma requisição REST interna pelo dispatcher do WordPress.
 *
 * @param string     $method HTTP.
 * @param string     $route  Rota sem o namespace.
 * @param array|null $body   Corpo JSON opcional.
 */
function papelito_item_settings_db_request( string $method, string $route, ?array $body = null ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/papelito/v1' . $route );
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
}

/**
 * Itens da listagem do vendor para a busca dada, indexados por produto.
 *
 * @param int   $vendor_id Vendor da listagem.
 * @param array $args      Argumentos de `papelito_vendor_stock_query()`.
 * @return array<int,array<string,mixed>>
 */
function papelito_item_settings_db_listing( int $vendor_id, array $args ): array {
	$result = papelito_vendor_stock_query( $vendor_id, array_merge( array( 'per_page' => 100 ), $args ) );
	return array_column( $result['items'], null, 'product_id' );
}

$users       = array();
$products    = array();
$original_id = get_current_user_id();
$failure     = null;

try {
	papelito_item_settings_db_check( 'instalação', true, papelito_vendor_item_settings_install_table() );
	$alters  = array();
	$capture = static function ( string $query ) use ( &$alters ): string {
		if ( str_starts_with( $query, 'ALTER TABLE' ) ) {
			$alters[] = $query;
		}
		return $query;
	};
	add_filter( 'query', $capture );
	papelito_vendor_item_settings_install_table();
	remove_filter( 'query', $capture );
	papelito_item_settings_db_check( 'instalação idempotente', array(), $alters );

	foreach ( array( 'seller', 'seller', 'customer' ) as $fixture_role ) {
		$fixture_id = wp_insert_user(
			array(
				'user_login' => ITEM_SETTINGS_TEST_FIXTURE_LOGIN . wp_generate_uuid4(),
				'user_pass'  => wp_generate_password(),
				'role'       => $fixture_role,
			)
		);
		if ( is_wp_error( $fixture_id ) ) {
			throw new UnexpectedValueException( $fixture_id->get_error_message() );
		}
		$users[] = $fixture_id;
	}
	list( $vendor_a, $vendor_b, $customer ) = $users;

	$simple = new WC_Product_Simple();
	$simple->set_name( ITEM_SETTINGS_TEST_FIXTURE_LOGIN . wp_generate_uuid4() );
	$simple->set_status( 'publish' );
	$simple_id  = $simple->save();
	$products[] = $simple_id;
	$sku_before = get_post_meta( $simple_id, '_sku', true );

	$parent = new WC_Product_Variable();
	$parent->set_name( ITEM_SETTINGS_TEST_FIXTURE_LOGIN . wp_generate_uuid4() );
	$parent->set_status( 'publish' );
	$parent_id  = $parent->save();
	$products[] = $parent_id;
	$variation  = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_status( 'publish' );
	$variation_id = $variation->save();
	$products[]   = $variation_id;

	$plain = new WC_Product_Simple();
	$plain->set_name( ITEM_SETTINGS_TEST_FIXTURE_LOGIN . wp_generate_uuid4() );
	$plain->set_status( 'publish' );
	$plain_id   = $plain->save();
	$products[] = $plain_id;

	$draft = new WC_Product_Simple();
	$draft->set_name( ITEM_SETTINGS_TEST_FIXTURE_LOGIN . wp_generate_uuid4() );
	$draft->set_status( 'draft' );
	$draft_id   = $draft->save();
	$products[] = $draft_id;

	wp_set_current_user( 0 );
	papelito_item_settings_db_error( 'anônimo', 401, papelito_vendor_item_settings_get( $simple_id ) );
	wp_set_current_user( $customer );
	papelito_item_settings_db_error( 'cliente', 403, papelito_vendor_item_settings_save( $simple_id, array( 'vendor_code' => 'X' ) ) );

	wp_set_current_user( $vendor_a );
	papelito_item_settings_db_check( 'sem código', null, papelito_vendor_item_settings_get( $simple_id )['vendor_code'] );
	$saved = papelito_vendor_item_settings_save( $simple_id, array( 'vendor_code' => '  ' . ITEM_SETTINGS_TEST_CODE_A . '  ' ) );
	papelito_item_settings_db_check( 'apara e preserva zeros', ITEM_SETTINGS_TEST_CODE_A, $saved['vendor_code'] );
	papelito_item_settings_db_check( 'SKU da Papelito intocado', $sku_before, get_post_meta( $simple_id, '_sku', true ) );
	papelito_item_settings_db_check( 'código não cria linha de estoque', 0, papelito_get_vendor_stock( $vendor_a, $simple_id ) );

	wp_set_current_user( $vendor_b );
	papelito_item_settings_db_check( 'B não vê o código de A', null, papelito_vendor_item_settings_get( $simple_id )['vendor_code'] );
	papelito_vendor_item_settings_save( $simple_id, array( 'vendor_code' => ITEM_SETTINGS_TEST_CODE_B ) );
	wp_set_current_user( $vendor_a );
	papelito_item_settings_db_check( 'A preservado', ITEM_SETTINGS_TEST_CODE_A, papelito_vendor_item_settings_get( $simple_id )['vendor_code'] );

	$on_variation = papelito_vendor_item_settings_save( $variation_id, array( 'vendor_code' => ITEM_SETTINGS_TEST_VARIATION ) );
	papelito_item_settings_db_check( 'variação não sobe para o pai', $variation_id, $on_variation['product_id'] );
	papelito_item_settings_db_check( 'pai continua sem código', null, papelito_vendor_item_settings_get( $parent_id )['vendor_code'] );

	papelito_item_settings_db_error( 'rascunho', 404, papelito_vendor_item_settings_save( $draft_id, array( 'vendor_code' => 'X' ) ) );
	papelito_item_settings_db_error( 'inexistente', 404, papelito_vendor_item_settings_save( PHP_INT_MAX, array( 'vendor_code' => 'X' ) ) );
	papelito_item_settings_db_error( 'longo demais', 422, papelito_vendor_item_settings_save( $simple_id, array( 'vendor_code' => str_repeat( 'á', 61 ) ) ) );
	papelito_item_settings_db_check( '60 caracteres permitidos', false, is_wp_error( papelito_vendor_item_settings_validate( array( 'vendor_code' => str_repeat( 'á', 60 ) ) ) ) );
	foreach ( array( 419, true, array( 'x' ) ) as $invalid ) {
		papelito_item_settings_db_error( 'não texto', 422, papelito_vendor_item_settings_save( $simple_id, array( 'vendor_code' => $invalid ) ) );
	}
	papelito_item_settings_db_error( 'corpo sem código', 422, papelito_vendor_item_settings_save( $simple_id, array() ) );
	papelito_item_settings_db_error(
		'identidade no corpo',
		422,
		papelito_vendor_item_settings_save(
			$simple_id,
			array(
				'vendor_code' => 'X',
				'vendor_id'   => $vendor_b,
			)
		)
	);
	papelito_item_settings_db_check( 'recusa não altera', ITEM_SETTINGS_TEST_CODE_A, papelito_vendor_item_settings_get( $simple_id )['vendor_code'] );

	$listing = papelito_item_settings_db_listing( $vendor_a, array( 'search' => $simple->get_name() ) );
	papelito_item_settings_db_check( 'listagem traz o código', ITEM_SETTINGS_TEST_CODE_A, $listing[ $simple_id ]['vendor_code'] );
	$unconfigured = papelito_item_settings_db_listing(
		$vendor_a,
		array(
			'search' => $simple->get_name(),
			'filter' => 'unconfigured',
		)
	);
	papelito_item_settings_db_check( 'código não muda "Não configurado"', true, isset( $unconfigured[ $simple_id ] ) );
	$by_code = papelito_item_settings_db_listing( $vendor_a, array( 'search' => ITEM_SETTINGS_TEST_CODE_A ) );
	papelito_item_settings_db_check( 'busca pelo código', true, isset( $by_code[ $simple_id ] ) );
	$other_code = papelito_item_settings_db_listing( $vendor_a, array( 'search' => ITEM_SETTINGS_TEST_CODE_B ) );
	papelito_item_settings_db_check( 'busca não acha código de outro vendor', false, isset( $other_code[ $simple_id ] ) );
	$variation_listing = papelito_item_settings_db_listing( $vendor_a, array( 'search' => $parent->get_name() ) );
	papelito_item_settings_db_check( 'variação traz o próprio código', ITEM_SETTINGS_TEST_VARIATION, $variation_listing[ $variation_id ]['vendor_code'] ?? null );
	$missing = papelito_item_settings_db_listing(
		$vendor_a,
		array(
			'search'      => ITEM_SETTINGS_TEST_FIXTURE_LOGIN,
			'vendor_code' => 'missing',
		)
	);
	papelito_item_settings_db_check( 'filtro sem código exclui o mapeado', false, isset( $missing[ $simple_id ] ) );
	papelito_item_settings_db_check( 'filtro sem código mantém o pai', true, isset( $missing[ $parent_id ] ) );
	papelito_item_settings_db_check( 'envelope sinaliza disponibilidade', true, papelito_vendor_stock_query( $vendor_a, array( 'per_page' => 1 ) )['vendor_code_available'] );

	$export = array_column( papelito_vendor_stock_export_rows( $vendor_a ), null, 'product_name' );
	papelito_item_settings_db_check( 'export mostra o código do vendor como SKU', ITEM_SETTINGS_TEST_CODE_A, $export[ $simple->get_name() ]['sku'] ?? null );
	papelito_item_settings_db_check( 'export sem coluna do SKU da Papelito', false, isset( $export[ $simple->get_name() ]['vendor_code'] ) );
	papelito_item_settings_db_check( 'export cai no SKU da Papelito sem código', (string) get_post_meta( $plain_id, '_sku', true ), $export[ $plain->get_name() ]['sku'] ?? null );
	papelito_item_settings_db_check( 'CSV mantém zeros à esquerda', true, str_contains( papelito_vendor_stock_export_csv( array_values( $export ) ), "\n" . ITEM_SETTINGS_TEST_CODE_A . ';' ) );

	$route    = '/vendor/me/products/' . $simple_id . '/settings';
	$response = papelito_item_settings_db_request( 'PATCH', $route, array( 'vendor_code' => '' ) );
	papelito_item_settings_db_check( 'PATCH vazio responde 200', 200, $response->get_status() );
	papelito_item_settings_db_check( 'PATCH vazio apaga', null, $response->get_data()['vendor_code'] );
	papelito_item_settings_db_check( 'linha vazia removida', array(), papelito_vendor_item_settings_get_many( $vendor_a, array( $simple_id ) ) );
	papelito_item_settings_db_check( 'GET via REST', 200, papelito_item_settings_db_request( 'GET', $route )->get_status() );
	papelito_item_settings_db_check( 'PUT não é aceito', 404, papelito_item_settings_db_request( 'PUT', $route, array( 'vendor_code' => 'X' ) )->get_status() );
	papelito_item_settings_db_check( 'apagar sem linha', true, papelito_vendor_item_settings_save_code( $vendor_a, $simple_id, null ) );

	$queries = array();
	foreach ( array( 1, 10, 40 ) as $size ) {
		$start = $wpdb->num_queries;
		papelito_vendor_item_settings_get_many( $vendor_a, range( $simple_id, $simple_id + $size - 1 ) );
		$queries[] = $wpdb->num_queries - $start;
	}
	papelito_item_settings_db_check( 'lote 1/10/40 sem N+1', array( 1, 1, 1 ), $queries );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $vendor_b );
	papelito_item_settings_db_check( 'excluir vendor limpa seus códigos', array(), papelito_vendor_item_settings_get_many( $vendor_b, array( $simple_id ) ) );
	wp_delete_post( $variation_id, true );
	papelito_item_settings_db_check( 'excluir variação limpa o código', array(), papelito_vendor_item_settings_get_many( $vendor_a, array( $variation_id ) ) );
} catch ( Throwable $error ) {
	$failure = $error->getMessage();
} finally {
	wp_set_current_user( $original_id );
	foreach ( $products as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $fixture_id ) {
		delete_transient( 'papelito_rl_vendor_item_settings_' . md5( 'user:' . $fixture_id ) );
		wp_delete_user( $fixture_id );
	}
}

if ( null !== $failure ) {
	WP_CLI::error( $failure );
}
WP_CLI::success( "{$item_settings_checks} checagens de código do vendor passaram." );
