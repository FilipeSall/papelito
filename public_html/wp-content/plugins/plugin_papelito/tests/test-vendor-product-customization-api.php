<?php
/**
 * Contratos REST reais de personalização, com fixtures isoladas.
 *
 * Execute via wp eval-file. Exercita permission callbacks e serviços com banco
 * real; limpa usuários, produtos, caixas e transients no finally.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Execute com wp eval-file.\n" );
}

// phpcs:disable WordPress.Security.EscapeOutput -- Teste WP-CLI não renderiza HTML.

global $wpdb, $customization_api_checks;
$customization_api_checks = 0;

/** Asserção contabilizada que falha antes de continuar usando dados inválidos. */
function papelito_customization_api_check( string $label, mixed $expected, mixed $actual ): void {
	global $customization_api_checks;
	++$customization_api_checks;
	if ( $expected !== $actual ) {
		throw new RuntimeException( $label . ': esperado ' . wp_json_encode( $expected ) . ', recebido ' . wp_json_encode( $actual ) );
	}
}

/** Dispara uma requisição REST interna pelo mesmo dispatcher do WordPress. */
function papelito_customization_api_request( string $method, string $fixture_path, ?array $body = null, array $query = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/papelito/v1' . $fixture_path );
	$request->set_query_params( $query );
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
}

$users       = array();
$products    = array();
$original_id = get_current_user_id();
$failure     = null;
$box_table   = papelito_packaging_table_names()['profiles'];
$kits_tables = papelito_kits_table_names();
$kit_id      = 0;

try {
	foreach ( array( 'seller', 'seller', 'customer', 'administrator' ) as $fixture_role ) {
		$fixture_id = wp_insert_user(
			array(
				'user_login' => 'zzz-description-api-' . wp_generate_uuid4(),
				'user_pass'  => wp_generate_password(),
				'role'       => $fixture_role,
			)
		);
		if ( is_wp_error( $fixture_id ) ) {
			throw new RuntimeException( $fixture_id->get_error_message() );
		}
		$users[] = $fixture_id;
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'zzz-description-api-' . wp_generate_uuid4() );
	$product->set_status( 'publish' );
	$product->set_description( '<p>Canônico.</p>' );
	$product_id   = $product->save();
	$products[]   = $product_id;
	$fixture_path = '/vendor/me/products/' . $product_id . '/customization';
	$public       = '/products/' . $product_id . '/presentation';

	wp_set_current_user( 0 );
	foreach ( array( 'GET', 'PUT' ) as $method ) {
		papelito_customization_api_check( 'anônimo ' . $method, 401, papelito_customization_api_request( $method, $fixture_path, array( 'description' => 'X' ) )->get_status() );
	}
	foreach ( array_slice( $users, 2 ) as $fixture_id ) {
		wp_set_current_user( $fixture_id );
		papelito_customization_api_check( 'sem seller', 403, papelito_customization_api_request( 'GET', $fixture_path )->get_status() );
	}
	wp_set_current_user( $users[0] );
	$response = papelito_customization_api_request( 'PUT', $fixture_path, array( 'description' => '<p>Vendor A.</p>' ) );
	papelito_customization_api_check( 'PUT sem estoque', 200, $response->get_status() );
	papelito_customization_api_check( 'no-store', 'private, no-store', $response->get_headers()['Cache-Control'] ?? null );
	papelito_customization_api_check( 'ID vem do usuário', $users[0], $response->get_data()['vendor_id'] );
	$response = papelito_customization_api_request( 'GET', $fixture_path, null, array( 'product_id' => $product_id + 1000000 ) );
	papelito_customization_api_check( 'ID do recurso vem da URL', 200, $response->get_status() );
	papelito_customization_api_check(
		'body owner recusado',
		422,
		papelito_customization_api_request(
			'PUT',
			$fixture_path,
			array(
				'description' => 'X',
				'vendor_id'   => $users[1],
			)
		)->get_status()
	);
	papelito_customization_api_check( 'DELETE descomissionado', 404, papelito_customization_api_request( 'DELETE', $fixture_path )->get_status() );
	wp_set_current_user( $users[1] );
	papelito_customization_api_check( 'B não lê gestão de A', null, papelito_customization_api_request( 'GET', $fixture_path )->get_data()['vendor_description'] );
	papelito_customization_api_request( 'PUT', $fixture_path, array( 'description' => '<p>Vendor B.</p>' ) );

	$minimum_boxes = papelito_vendor_eligibility_config()['minimum_boxes'];
	foreach ( array_slice( $users, 0, 2 ) as $vendor_id ) {
		update_user_meta( $vendor_id, PAPELITO_PAGARME_RECIPIENT_STATUS_META, 'active' );
		for ( $index = 0; $index < $minimum_boxes; $index++ ) {
			$wpdb->insert(
				$box_table,
				array(
					'vendor_id' => $vendor_id,
					'code'      => 'test-' . $index,
					'label'     => 'Teste',
					'length_mm' => 100,
					'width_mm'  => 100,
					'height_mm' => 100,
				)
			);
		}
		papelito_customization_api_check( 'fixture elegível', true, papelito_vendor_is_eligible_to_sell( $vendor_id ) );
	}

	wp_set_current_user( 0 );
	papelito_customization_api_check( 'público sem contexto', 'papelito', papelito_customization_api_request( 'GET', $public )->get_data()['description_source'] );
	$response = papelito_customization_api_request( 'GET', $public, null, array( 'vendor_id' => $users[0] ) );
	papelito_customization_api_check( 'público A sem estoque', '<p>Vendor A.</p>', $response->get_data()['description'] );
	papelito_customization_api_check( 'sem dados privados', false, isset( $response->get_data()['canonical_description'] ) );
	papelito_customization_api_check( 'sem timestamps privados', false, isset( $response->get_data()['updated_at'] ) );
	papelito_customization_api_check( 'público B isolado', '<p>Vendor B.</p>', papelito_customization_api_request( 'GET', $public, null, array( 'vendor_id' => $users[1] ) )->get_data()['description'] );
	papelito_customization_api_check( 'vendor não seller', 403, papelito_customization_api_request( 'GET', $public, null, array( 'vendor_id' => $users[2] ) )->get_status() );

	wp_set_current_user( $users[0] );
	update_user_meta( $users[0], PAPELITO_ACCOUNT_STATUS_META, 'suspended' );
	papelito_customization_api_check( 'suspenso lê', 200, papelito_customization_api_request( 'GET', $fixture_path )->get_status() );
	papelito_customization_api_check( 'suspenso can_edit false', false, papelito_customization_api_request( 'GET', $fixture_path )->get_data()['can_edit'] );
	papelito_customization_api_check( 'suspenso PUT', 403, papelito_customization_api_request( 'PUT', $fixture_path, array( 'description' => 'X' ) )->get_status() );
	papelito_customization_api_check( 'suspenso não expõe', 403, papelito_customization_api_request( 'GET', $public, null, array( 'vendor_id' => $users[0] ) )->get_status() );
	update_user_meta( $users[0], PAPELITO_ACCOUNT_STATUS_META, 'active' );

	$bucket = 'papelito_rl_vendor_product_customization_' . md5( 'user:' . $users[0] );
	set_transient( $bucket, 30, 60 );
	papelito_customization_api_check( 'rate limit PUT', 429, papelito_customization_api_request( 'PUT', $fixture_path, array( 'description' => 'X' ) )->get_status() );
	wp_set_current_user( $users[1] );
	papelito_customization_api_check( 'rate limit individual', 200, papelito_customization_api_request( 'PUT', $fixture_path, array( 'description' => '<p>B atualizado.</p>' ) )->get_status() );
	delete_transient( $bucket );
	wp_set_current_user( $users[0] );

	$storage_error = static function ( string $query ): string {
		if ( str_contains( $query, 'papelito_vendor_product_overrides' ) && ( str_starts_with( $query, 'SELECT product_id, description' ) || str_starts_with( $query, 'INSERT INTO' ) ) ) {
			return 'SELECT * FROM papelito_test_nonexistent_customization_table';
		}
		return $query;
	};
	$suppressed    = $wpdb->suppress_errors( true );
	add_filter( 'query', $storage_error );
	papelito_customization_api_check( 'erro GET não vira fallback', 500, papelito_customization_api_request( 'GET', $fixture_path )->get_status() );
	papelito_customization_api_check( 'erro público não vira fallback', 500, papelito_customization_api_request( 'GET', $public, null, array( 'vendor_id' => $users[0] ) )->get_status() );
	papelito_customization_api_check( 'erro PUT', 500, papelito_customization_api_request( 'PUT', $fixture_path, array( 'description' => 'Não pode persistir' ) )->get_status() );
	remove_filter( 'query', $storage_error );
	$wpdb->suppress_errors( $suppressed );
	papelito_customization_api_check( 'falha mantém valor anterior', '<p>Vendor A.</p>', papelito_customization_api_request( 'GET', $fixture_path )->get_data()['vendor_description'] );

	$kit_product = new WC_Product_Simple();
	$kit_product->set_name( 'zzz-description-kit-' . wp_generate_uuid4() );
	$kit_product->set_status( 'publish' );
	$kit_product->set_description( 'Kit canônico' );
	$kit_product_id = $kit_product->save();
	$products[]     = $kit_product_id;
	$wpdb->insert( $kits_tables['kits'], array( 'product_id' => $kit_product_id ) );
	$kit_id = (int) $wpdb->insert_id;
	$wpdb->insert(
		$kits_tables['items'],
		array(
			'kit_id'     => $kit_id,
			'product_id' => $product_id,
			'quantity'   => 2,
		)
	);
	$kit_path = '/vendor/me/products/' . $kit_product_id . '/customization';
	papelito_customization_api_check( 'kit não exige saldo fictício', 200, papelito_customization_api_request( 'PUT', $kit_path, array( 'description' => 'Kit vendor' ) )->get_status() );
	papelito_customization_api_check( 'kit usa produto comercial', $kit_product_id, papelito_customization_api_request( 'GET', $kit_path )->get_data()['product_id'] );

	foreach ( array( 'draft', 'pending', 'private', 'trash' ) as $fixture_status ) {
		$product = wc_get_product( $product_id );
		$product->set_status( $fixture_status );
		$product->save();
		foreach ( array( 'GET', 'PUT' ) as $method ) {
			papelito_customization_api_check( $fixture_status . ' ' . $method, 404, papelito_customization_api_request( $method, $fixture_path, 'PUT' === $method ? array( 'description' => 'X' ) : null )->get_status() );
		}
	}
	papelito_customization_api_check( 'produto inexistente', 404, papelito_customization_api_request( 'GET', '/products/999999999/presentation' )->get_status() );
} catch ( Throwable $error ) {
	$failure = $error->getMessage();
} finally {
	if ( isset( $storage_error ) ) {
		remove_filter( 'query', $storage_error );
		$wpdb->suppress_errors( false );
	}
	wp_set_current_user( $original_id );
	$wpdb->delete( $kits_tables['items'], array( 'kit_id' => $kit_id ), array( '%d' ) );
	$wpdb->delete( $kits_tables['kits'], array( 'id' => $kit_id ), array( '%d' ) );
	foreach ( $products as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $fixture_id ) {
		$wpdb->delete( $box_table, array( 'vendor_id' => $fixture_id ), array( '%d' ) );
		delete_transient( 'papelito_rl_vendor_product_customization_' . md5( 'user:' . $fixture_id ) );
		wp_delete_user( $fixture_id );
	}
}
if ( null !== $failure ) {
	WP_CLI::error( $failure );
}
WP_CLI::success( "{$customization_api_checks} checagens REST passaram." );
