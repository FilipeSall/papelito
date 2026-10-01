<?php
/**
 * Personalização de produtos contra WooCommerce e MariaDB reais.
 *
 * Execute com wp eval-file; cria fixtures descartáveis e limpa todas no finally.
 * Testa autorização e persistência sem substituir o SQL por stubs.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Execute com wp eval-file.\n" );
}

if ( ! function_exists( 'papelito_vendor_product_overrides_install_table' ) ) {
	WP_CLI::error( 'A persistência de personalização ainda não existe.' );
}

// phpcs:disable WordPress.Security.EscapeOutput -- Teste WP-CLI não renderiza HTML.

global $wpdb, $customization_checks;
$customization_checks = 0;

/**
 * Falha imediatamente com código não zero, incluindo o nome da invariante.
 */
function papelito_customization_db_check( string $label, mixed $expected, mixed $actual ): void {
	global $customization_checks;
	++$customization_checks;
	if ( $expected !== $actual ) {
		throw new RuntimeException( $label . ': esperado ' . wp_json_encode( $expected ) . ', recebido ' . wp_json_encode( $actual ) );
	}
}

/**
 * Executa um caso e exige o status REST correspondente.
 */
function papelito_customization_db_error( string $label, int $fixture_status, mixed $result ): void {
	papelito_customization_db_check( $label . ' retorna erro', true, is_wp_error( $result ) );
	papelito_customization_db_check( $label . ' status', $fixture_status, $result->get_error_data()['status'] );
}

$users       = array();
$products    = array();
$original_id = get_current_user_id();
$failure     = null;

try {
	papelito_customization_db_check( 'instalação', true, papelito_vendor_product_overrides_install_table() );
	papelito_customization_db_check( 'schema', true, papelito_vendor_product_overrides_schema_ready( true ) );
	$alters  = array();
	$capture = static function ( string $query ) use ( &$alters ): string {
		if ( str_starts_with( $query, 'ALTER TABLE' ) ) {
			$alters[] = $query;
		}
		return $query;
	};
	add_filter( 'query', $capture );
	papelito_vendor_product_overrides_install_table();
	remove_filter( 'query', $capture );
	papelito_customization_db_check( 'instalação idempotente', array(), $alters );

	foreach ( array( 'seller', 'seller', 'customer', 'administrator' ) as $fixture_role ) {
		$fixture_id = wp_insert_user(
			array(
				'user_login' => 'zzz-customization-' . wp_generate_uuid4(),
				'user_pass'  => wp_generate_password(),
				'role'       => $fixture_role,
			)
		);
		if ( is_wp_error( $fixture_id ) ) {
			throw new RuntimeException( $fixture_id->get_error_message() );
		}
		$users[] = $fixture_id;
	}

	$product = new WC_Product_Variable();
	$product->set_name( 'zzz-customization-' . wp_generate_uuid4() );
	$product->set_status( 'publish' );
	$product->set_description( '<p>Canônico V1.</p>' );
	$product->set_short_description( '<p>Resumo canônico.</p>' );
	$product_id = $product->save();
	$products[] = $product_id;
	$variation  = new WC_Product_Variation();
	$variation->set_parent_id( $product_id );
	$variation->set_status( 'publish' );
	$variation_id = $variation->save();
	$products[]   = $variation_id;
	$before       = get_post( $product_id )->post_content;

	wp_set_current_user( 0 );
	papelito_customization_db_error( 'anônimo', 401, papelito_vendor_product_customization_get( $product_id ) );
	foreach ( array_slice( $users, 2 ) as $fixture_id ) {
		wp_set_current_user( $fixture_id );
		papelito_customization_db_error( 'papel sem seller', 403, papelito_vendor_product_customization_save( $product_id, array( 'description' => 'Proibido' ) ) );
	}

	wp_set_current_user( $users[0] );
	$view = papelito_vendor_product_customization_get( $variation_id );
	papelito_customization_db_check( 'normaliza pai', $product_id, $view['product_id'] );
	papelito_customization_db_check( 'sem override', null, $view['vendor_description'] );
	papelito_customization_db_check( 'pode preparar sem vender', true, $view['can_edit'] );
	papelito_customization_db_check( 'sem linha de estoque', 0, papelito_get_vendor_stock( $users[0], $product_id ) );

	$saved = papelito_vendor_product_customization_save( $variation_id, array( 'description' => '<p>Vendor A.</p>' ) );
	papelito_customization_db_check( 'salva sem estoque', 'vendor', $saved['description_source'] );
	papelito_customization_db_check( 'salva no pai', $product_id, $saved['product_id'] );
	papelito_customization_db_check( 'canônico intocado', $before, get_post( $product_id )->post_content );
	papelito_customization_db_error( 'inelegível não expõe', 403, papelito_product_presentation_resolve( $product_id, $users[0] ) );
	papelito_customization_db_check( 'resumo separado', '<p>Resumo canônico.</p>', papelito_product_presentation_resolve( $product_id )['summary'] );

	wp_set_current_user( $users[1] );
	papelito_customization_db_check( 'B não lê gestão de A', null, papelito_vendor_product_customization_get( $product_id )['vendor_description'] );
	papelito_vendor_product_customization_save( $product_id, array( 'description' => '<p>Vendor B.</p>' ) );
	wp_set_current_user( $users[0] );
	papelito_customization_db_check( 'A preservado', '<p>Vendor A.</p>', papelito_vendor_product_customization_get( $product_id )['vendor_description'] );
	$stock = papelito_vendor_stock_query(
		$users[0],
		array(
			'search'   => $product->get_name(),
			'per_page' => 100,
		)
	);
	foreach ( $stock['items'] as $item ) {
		papelito_customization_db_check( 'indicador herdado pelo pai', true, $item['has_description_override'] ?? null );
		papelito_customization_db_check( 'listagem sem descrição', false, isset( $item['description'] ) );
	}

	$kept = papelito_vendor_product_customization_save(
		$product_id,
		array(
			'description'            => '<p>Vendor A guardado.</p>',
			'use_vendor_description' => false,
		)
	);
	papelito_customization_db_check( 'desligado guarda o texto', '<p>Vendor A guardado.</p>', $kept['vendor_description'] );
	papelito_customization_db_check( 'desligado expõe a escolha', false, $kept['vendor_description_enabled'] );
	papelito_customization_db_check( 'desligado volta à Papelito', 'papelito', $kept['description_source'] );
	papelito_customization_db_check( 'desligado mostra o canônico', $before, $kept['effective_description'] );
	$disabled_stock = papelito_vendor_stock_query(
		$users[0],
		array(
			'search'   => $product->get_name(),
			'per_page' => 100,
		)
	);
	foreach ( $disabled_stock['items'] as $item ) {
		papelito_customization_db_check( 'indicador some com texto desligado', false, $item['has_description_override'] ?? null );
	}
	$enabled = papelito_vendor_product_customization_save(
		$product_id,
		array(
			'description'            => '<p>Vendor A.</p>',
			'use_vendor_description' => true,
		)
	);
	papelito_customization_db_check( 'religado volta ao vendor', 'vendor', $enabled['description_source'] );
	papelito_customization_db_check( 'religado expõe a escolha', true, $enabled['vendor_description_enabled'] );
	foreach ( array( 'sim', 1, null ) as $invalid_choice ) {
		papelito_customization_db_error(
			'escolha não booleana',
			422,
			papelito_vendor_product_customization_save(
				$product_id,
				array(
					'description'            => '<p>X</p>',
					'use_vendor_description' => $invalid_choice,
				)
			)
		);
	}

	foreach ( array( '', '   ', null, '<p></p>', '<p>&nbsp;</p>', '<p>&#160;</p>', "<p>\u{200B}</p>", str_repeat( 'á', 20001 ) ) as $invalid ) {
		papelito_customization_db_error( 'texto inválido', 422, papelito_vendor_product_customization_save( $product_id, array( 'description' => $invalid ) ) );
	}
	papelito_customization_db_error(
		'identidade no body',
		422,
		papelito_vendor_product_customization_save(
			$product_id,
			array(
				'description' => 'X',
				'vendor_id'   => $users[1],
			)
		)
	);
	$sanitized = papelito_vendor_product_customization_save( $product_id, array( 'description' => '<p onclick="alert(1)" style="color:red">Texto<br><img src=x onerror=alert(1)><iframe>frame</iframe></p>' ) );
	papelito_customization_db_check( 'remove atributos perigosos', false, str_contains( $sanitized['vendor_description'], 'onclick' ) );
	papelito_customization_db_check( 'remove imagem', false, str_contains( $sanitized['vendor_description'], '<img' ) );
	papelito_customization_db_check( 'remove iframe', false, str_contains( $sanitized['vendor_description'], '<iframe' ) );
	papelito_customization_db_check( 'preserva parágrafo', true, str_contains( $sanitized['vendor_description'], '<p>' ) );
	papelito_customization_db_check( '20 mil caracteres permitidos', false, is_wp_error( papelito_vendor_product_customization_save( $product_id, array( 'description' => str_repeat( 'á', 20000 ) ) ) ) );

	$expanded_html = '<p>' . str_repeat( '&quot;', 19995 ) . '</p>\n<p>fim</p>';
	$expanded_html = str_replace( '\n', "\n", $expanded_html );
	papelito_customization_db_check( 'limite mede texto, não entidades e tags', false, is_wp_error( papelito_vendor_product_customization_validate( array( 'description' => $expanded_html ) ) ) );
	papelito_customization_db_check( 'Unicode fora do BMP no limite', false, is_wp_error( papelito_vendor_product_customization_validate( array( 'description' => '<p>' . str_repeat( '😀', 20000 ) . '</p>' ) ) ) );
	papelito_customization_db_error( 'Unicode fora do BMP acima do limite', 422, papelito_vendor_product_customization_validate( array( 'description' => '<p>' . str_repeat( '😀', 20001 ) . '</p>' ) ) );
	papelito_vendor_product_customization_save( $product_id, array( 'description' => $before ) );
	$product = wc_get_product( $product_id );
	$product->set_description( '<p>Canônico V2.</p>' );
	$product->save();
	papelito_customization_db_check( 'igual ao canônico continua override', $before, papelito_vendor_product_customization_get( $product_id )['effective_description'] );
	$restored = papelito_vendor_product_customization_restore( $product_id );
	papelito_customization_db_check( 'restaura canônico atual', '<p>Canônico V2.</p>', $restored['effective_description'] );
	papelito_customization_db_check( 'restaurado sem linha', null, $restored['vendor_description'] );
	papelito_customization_db_check( 'DELETE idempotente', 'papelito', papelito_vendor_product_customization_restore( $product_id )['description_source'] );

	papelito_vendor_product_customization_save( $product_id, array( 'description' => 'Dormente' ) );
	$summary_before = papelito_vendor_stock_summary( $users[0] );
	$product->set_status( 'draft' );
	$product->save();
	papelito_customization_db_error( 'pai em rascunho', 404, papelito_vendor_product_customization_save( $variation_id, array( 'description' => 'Tentativa' ) ) );
	papelito_customization_db_error( 'GET rascunho', 404, papelito_vendor_product_customization_get( $product_id ) );
	papelito_customization_db_error( 'público rascunho', 404, papelito_product_presentation_resolve( $product_id ) );
	papelito_customization_db_check( 'rascunho preserva override', 'Dormente', papelito_vendor_product_overrides_get_many( $users[0], array( $product_id ) )[ $product_id ]['description'] );
	$draft_stock = papelito_vendor_stock_query( $users[0], array( 'search' => $product->get_name() ) );
	papelito_customization_db_check( 'estoque conserva variação de pai rascunho', array( $variation_id ), array_column( $draft_stock['items'], 'product_id' ) );
	papelito_customization_db_check( 'total da lista conserva variação', 1, $draft_stock['total'] );
	$summary_after = papelito_vendor_stock_summary( $users[0] );
	papelito_customization_db_check( 'resumo e lista retiram somente o pai', $summary_before['eligible'] - 1, $summary_after['eligible'] );
	$product->set_status( 'publish' );
	$product->save();
	papelito_customization_db_check( 'republicação reativa', 'Dormente', papelito_vendor_product_customization_get( $product_id )['vendor_description'] );

	$table      = papelito_vendor_product_overrides_table_name();
	$suppressed = $wpdb->suppress_errors( true );
	$duplicate  = $wpdb->insert(
		$table,
		array(
			'vendor_id'   => $users[0],
			'product_id'  => $product_id,
			'description' => 'Duplicado',
			'created_at'  => current_time( 'mysql', true ),
			'updated_at'  => current_time( 'mysql', true ),
		)
	);
	$wpdb->suppress_errors( $suppressed );
	papelito_customization_db_check( 'PK impede duplicata física', false, $duplicate );
	papelito_customization_db_check( 'UPSERT repetido', true, papelito_vendor_product_override_upsert( $users[0], $product_id, 'Atualizado' ) );
	papelito_customization_db_check( 'um par único', '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE vendor_id = %d AND product_id = %d', $table, $users[0], $product_id ) ) );

	$queries = array();
	foreach ( array( 1, 10, 40 ) as $size ) {
		$start = $wpdb->num_queries;
		papelito_vendor_product_overrides_get_many( $users[0], range( $product_id, $product_id + $size - 1 ) );
		$queries[] = $wpdb->num_queries - $start;
	}
	papelito_customization_db_check( 'lote 1/10/40 sem N+1', array( 1, 1, 1 ), $queries );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $users[1] );
	papelito_customization_db_check( 'excluir vendor limpa seus overrides', array(), papelito_vendor_product_overrides_get_many( $users[1], array( $product_id ) ) );
	wp_delete_post( $product_id, true );
	papelito_customization_db_check( 'excluir produto limpa dependentes', array(), papelito_vendor_product_overrides_get_many( $users[0], array( $product_id ) ) );
} catch ( Throwable $error ) {
	$failure = $error->getMessage();
} finally {
	wp_set_current_user( $original_id );
	foreach ( $products as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $fixture_id ) {
		delete_transient( 'papelito_rl_vendor_product_customization_' . md5( 'user:' . $fixture_id ) );
		wp_delete_user( $fixture_id );
	}
}

if ( null !== $failure ) {
	WP_CLI::error( $failure );
}
WP_CLI::success( "{$customization_checks} checagens de personalização passaram." );
