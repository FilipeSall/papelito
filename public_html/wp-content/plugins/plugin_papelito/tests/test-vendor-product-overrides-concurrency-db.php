<?php
/**
 * Concorrência e custo do repository contra MariaDB local.
 *
 * Execute via wp eval-file. PAPELITO_CUSTOMIZATION_DB_MODE recebe setup,
 * worker ou report; workers separados disputam o mesmo par. Report limpa
 * as fixtures e mede lotes, EXPLAIN e degradação sem schema.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	exit( "Este teste exige WordPress local via WP-CLI.\n" );
}

// phpcs:disable WordPress.Security.EscapeOutput -- Script CLI não renderiza HTML.

global $wpdb;
$customization_fixture_key = 'papelito_test_customization_concurrency';
$customization_mode        = getenv( 'PAPELITO_CUSTOMIZATION_DB_MODE' );

if ( 'setup' === $customization_mode ) {
	if ( get_option( $customization_fixture_key ) ) {
		WP_CLI::error( 'Fixture existente: execute report antes de setup.' );
	}
	$customization_vendor = wp_insert_user(
		array(
			'user_login' => 'zzz-desc-race-' . wp_generate_uuid4(),
			'user_pass'  => wp_generate_password(),
			'role'       => 'seller',
		)
	);
	if ( is_wp_error( $customization_vendor ) ) {
		WP_CLI::error( $customization_vendor );
	}
	$customization_product = new WC_Product_Simple();
	$customization_product->set_name( 'zzz-description-concurrent-' . wp_generate_uuid4() );
	$customization_product->set_status( 'publish' );
	$customization_product->set_description( '<p>Canônico da concorrência.</p>' );
	$customization_product_id = $customization_product->save();
	add_option(
		$customization_fixture_key,
		array(
			'vendor'  => $customization_vendor,
			'product' => $customization_product_id,
			'name'    => $customization_product->get_name(),
		),
		'',
		false
	);
	WP_CLI::success( 'Fixture local criada.' );
	return;
}

$customization_fixture = get_option( $customization_fixture_key );
if ( ! is_array( $customization_fixture ) ) {
	WP_CLI::error( 'Execute setup primeiro.' );
}
$customization_vendor     = (int) $customization_fixture['vendor'];
$customization_product_id = (int) $customization_fixture['product'];

if ( 'worker' === $customization_mode ) {
	for ( $customization_iteration = 0; $customization_iteration < 40; ++$customization_iteration ) {
		$customization_result = papelito_vendor_product_override_upsert(
			$customization_vendor,
			$customization_product_id,
			'<p>Worker ' . getmypid() . ':' . $customization_iteration . '.</p>',
			0 !== $customization_iteration % 3
		);
		if ( is_wp_error( $customization_result ) ) {
			WP_CLI::error( $customization_result );
		}
	}
	WP_CLI::success( '40 mutações atômicas confirmadas.' );
	return;
}

if ( 'report' !== $customization_mode ) {
	WP_CLI::error( 'Modo inválido.' );
}

$customization_failure       = null;
$customization_timings       = array();
$customization_schema_filter = static function ( string $query ): string {
	return str_starts_with( $query, 'SHOW TABLES LIKE' ) && str_contains( $query, 'overrides' )
		? "SELECT ''"
		: $query;
};

try {
	$customization_table = papelito_vendor_product_overrides_table_name();
	$customization_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE vendor_id = %d AND product_id = %d', $customization_table, $customization_vendor, $customization_product_id ) );
	if ( $customization_count > 1 ) {
		throw new RuntimeException( 'Concorrência duplicou o par.' );
	}
	papelito_vendor_product_override_upsert( $customization_vendor, $customization_product_id, 'Última confirmação' );
	$customization_rows = papelito_vendor_product_overrides_get_many( $customization_vendor, array( $customization_product_id ) );
	if ( 'Última confirmação' !== $customization_rows[ $customization_product_id ]['description'] ) {
		throw new RuntimeException( 'Última gravação não prevaleceu.' );
	}
	if ( '<p>Canônico da concorrência.</p>' !== wc_get_product( $customization_product_id )->get_description( 'edit' )
		|| 0 !== papelito_get_vendor_stock( $customization_vendor, $customization_product_id ) ) {
		throw new RuntimeException( 'Concorrência alterou catálogo ou estoque.' );
	}
	foreach ( array( 1, 10, 40 ) as $customization_size ) {
		$customization_start   = microtime( true );
		$customization_queries = $wpdb->num_queries;
		papelito_vendor_product_overrides_get_many( $customization_vendor, range( $customization_product_id, $customization_product_id + $customization_size - 1 ) );
		$customization_timings[] = array(
			'size'    => $customization_size,
			'queries' => $wpdb->num_queries - $customization_queries,
			'ms'      => round( ( microtime( true ) - $customization_start ) * 1000, 3 ),
		);
		if ( 1 !== $wpdb->num_queries - $customization_queries ) {
			throw new RuntimeException( 'Repository criou N+1.' );
		}
	}
	$customization_explain = $wpdb->get_row( $wpdb->prepare( 'EXPLAIN SELECT description FROM %i WHERE vendor_id = %d AND product_id = %d', $customization_table, $customization_vendor, $customization_product_id ), ARRAY_A );
	if ( 'PRIMARY' !== $customization_explain['key'] ) {
		throw new RuntimeException( 'Detalhe não utiliza chave primária.' );
	}
	$customization_stock_queries = array();
	$customization_capture       = static function ( string $query ) use ( &$customization_stock_queries ): string {
		if ( str_contains( $query, 'papelito_vendor_product_overrides' ) && str_contains( $query, 'JOIN' ) ) {
			$customization_stock_queries[] = $query;
		}
		return $query;
	};
	add_filter( 'query', $customization_capture );
	foreach ( array( 1, 10, 40 ) as $customization_size ) {
		$customization_stock = papelito_vendor_stock_query( $customization_vendor, array( 'per_page' => $customization_size ) );
	}
	remove_filter( 'query', $customization_capture );
	if ( 3 !== count( $customization_stock_queries ) ) {
		throw new RuntimeException( 'Indicador não usa um JOIN por listagem.' );
	}
	add_filter( 'query', $customization_schema_filter );
	if ( papelito_vendor_product_overrides_schema_ready( true ) ) {
		throw new RuntimeException( 'Schema ausente foi considerado válido.' );
	}
	$customization_missing = papelito_vendor_product_overrides_get_many( $customization_vendor, array( $customization_product_id ) );
	if ( ! is_wp_error( $customization_missing ) || 500 !== $customization_missing->get_error_data()['status'] ) {
		throw new RuntimeException( 'Schema ausente virou fallback.' );
	}
	$customization_stock = papelito_vendor_stock_query( $customization_vendor, array( 'search' => $customization_fixture['name'] ) );
	if ( ! $customization_stock['items'] || null !== $customization_stock['items'][0]['has_description_override'] ) {
		throw new RuntimeException( 'Estoque não degradou com indicador indisponível.' );
	}
	WP_CLI::log(
		wp_json_encode(
			array(
				'batch'                => $customization_timings,
				'detail_index'         => $customization_explain['key'],
				'stock_override_joins' => count( $customization_stock_queries ),
			)
		)
	);
} catch ( Throwable $customization_error ) {
	$customization_failure = $customization_error->getMessage();
} finally {
	remove_filter( 'query', $customization_schema_filter );
	papelito_vendor_product_overrides_schema_ready( true );
	wp_delete_post( $customization_product_id, true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $customization_vendor );
	delete_option( $customization_fixture_key );
}
if ( null !== $customization_failure ) {
	WP_CLI::error( $customization_failure );
}
WP_CLI::success( 'Concorrência, índices, lotes e degradação verificados; fixtures removidas.' );
