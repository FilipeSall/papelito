<?php
/**
 * Importação de códigos do vendor contra WooCommerce e MariaDB reais.
 *
 * Execute com wp eval-file. Cobre prévia sem escrita, gravação só no vendor
 * autenticado, variações do produto variável, reimportação sem escrita nem
 * trilha, valor alterado entre prévia e confirmação, família fora dos
 * candidatos, conflito, rollback total em falha de SQL, trilha da edição
 * manual e exportação preservada. Fixtures são removidas no finally.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Execute com wp eval-file.\n" );
}

if ( ! function_exists( 'papelito_vendor_code_import_confirm' ) ) {
	WP_CLI::error( 'A importação de códigos do vendor ainda não existe.' );
}

// phpcs:disable WordPress.Security.EscapeOutput -- Teste WP-CLI não renderiza HTML.

const SKU_IMPORT_TEST_LOGIN        = 'zzz-sku-import-';
const SKU_IMPORT_TEST_SEDA_KS      = 'SEDA PAPELITO ZQXIMPORT KING SIZE';
const SKU_IMPORT_TEST_GRINDER      = 'TRITURADOR PLASTICO PAPELITO ZQXIMPORT';
const SKU_IMPORT_TEST_GRINDERS     = 'HEADSHOP - TRITURADORES';
const SKU_IMPORT_TEST_GENERIC      = 'SEDA PAPELITO ZQXIMPORT';
const SKU_IMPORT_TEST_CODE         = '00777';
const SKU_IMPORT_TEST_GRINDER_CODE = '8888';

global $wpdb, $sku_import_checks;
$sku_import_checks = 0;

/**
 * Falha imediatamente com o nome da invariante.
 *
 * @param string $label    Invariante.
 * @param mixed  $expected Esperado.
 * @param mixed  $actual   Obtido.
 * @throws UnexpectedValueException Quando divergem.
 */
function papelito_sku_import_db_check( string $label, mixed $expected, mixed $actual ): void {
	global $sku_import_checks;
	++$sku_import_checks;
	if ( $expected !== $actual ) {
		throw new UnexpectedValueException( $label . ': esperado ' . wp_json_encode( $expected ) . ', recebido ' . wp_json_encode( $actual ) );
	}
}

/**
 * Linha da planilha.
 *
 * @param int    $line        Linha.
 * @param string $description Descrição.
 * @param string $code        Código.
 * @param string $subgroup    Subgrupo.
 */
function papelito_sku_import_db_row( int $line, string $description, string $code, string $subgroup = '' ): array {
	return array(
		'line'        => $line,
		'description' => $description,
		'subgroup'    => $subgroup,
		'code'        => $code,
	);
}

/**
 * Linha da confirmação montada a partir da prévia, como o painel envia.
 *
 * @param array    $preview_row Linha da prévia.
 * @param int|null $family_id   Família escolhida; padrão é a automática.
 */
function papelito_sku_import_db_decision( array $preview_row, ?int $family_id = null ): array {
	$family = $preview_row['family'];
	foreach ( $preview_row['candidates'] as $candidate ) {
		if ( $candidate['family_id'] === $family_id ) {
			$family = $candidate;
		}
	}
	return array(
		'line'        => $preview_row['line'],
		'description' => $preview_row['description'],
		'subgroup'    => $preview_row['subgroup'],
		'code'        => $preview_row['code'],
		'family_id'   => $family_id ?? $family['family_id'],
		'expected'    => array_map(
			static fn( array $target ): array => array(
				'product_id'   => $target['product_id'],
				'current_code' => $target['current_code'],
			),
			(array) ( $family['targets'] ?? array() )
		),
	);
}

/**
 * Confirmação pelo mesmo caminho da rota: corpo validado e depois gravado.
 *
 * @param int   $vendor_id Vendor.
 * @param array $decisions Linhas montadas por `papelito_sku_import_db_decision()`.
 * @return array|WP_Error
 * @throws UnexpectedValueException Quando a etapa falha.
 */
function papelito_sku_import_db_confirm( int $vendor_id, array $decisions ): array|WP_Error {
	$rows = papelito_vendor_code_import_validate_payload( array( 'rows' => $decisions ) );
	if ( is_wp_error( $rows ) ) {
		throw new UnexpectedValueException( 'corpo recusado: ' . $rows->get_error_message() );
	}
	return papelito_vendor_code_import_confirm( $vendor_id, $rows );
}

/**
 * Prévia indexada por linha.
 *
 * @param int   $vendor_id Vendor.
 * @param array $rows      Linhas.
 * @throws UnexpectedValueException Quando a etapa falha.
 */
function papelito_sku_import_db_preview( int $vendor_id, array $rows ): array {
	$result = papelito_vendor_code_import_preview( $vendor_id, $rows );
	if ( is_wp_error( $result ) ) {
		throw new UnexpectedValueException( 'prévia falhou: ' . $result->get_error_message() );
	}
	return array_column( $result['rows'], null, 'line' );
}

/**
 * Status do resultado da confirmação por linha.
 *
 * @param array|WP_Error $result Resultado.
 * @throws UnexpectedValueException Quando a etapa falha.
 */
function papelito_sku_import_db_statuses( array|WP_Error $result ): array {
	if ( is_wp_error( $result ) ) {
		throw new UnexpectedValueException( 'confirmação falhou: ' . $result->get_error_message() );
	}
	return array_column( $result['rows'], 'status', 'line' );
}

/**
 * Códigos atuais do vendor para os itens.
 *
 * @param int   $vendor_id Vendor.
 * @param int[] $ids       Itens.
 */
function papelito_sku_import_db_codes( int $vendor_id, array $ids ): array {
	$rows  = papelito_vendor_item_settings_get_many( $vendor_id, $ids );
	$codes = array();
	foreach ( $ids as $id ) {
		$codes[ $id ] = $rows[ $id ]['vendor_code'] ?? null;
	}
	return $codes;
}

/**
 * Quantas linhas da trilha o vendor tem, opcionalmente filtradas pela origem.
 *
 * @param int         $vendor_id Vendor.
 * @param string|null $source    Origem.
 */
function papelito_sku_import_db_log_count( int $vendor_id, ?string $source = null ): int {
	global $wpdb;
	$table = papelito_vendor_item_settings_log_table_name();
	return null === $source
		? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE vendor_id = %d', $table, $vendor_id ) )
		: (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE vendor_id = %d AND source = %s', $table, $vendor_id, $source ) );
}

/**
 * Cria produto simples publicado.
 *
 * @param string $name    Nome.
 * @param string $content Descrição.
 */
function papelito_sku_import_db_simple( string $name, string $content = '' ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_description( $content );
	$product->set_status( 'publish' );
	return $product->save();
}

$original_id = get_current_user_id();
$users       = array();
$products    = array();
$failure     = null;

try {
	papelito_vendor_item_settings_install_table();
	foreach ( array( 'seller', 'seller', 'customer' ) as $fixture_role ) {
		$fixture_id = wp_insert_user(
			array(
				'user_login' => SKU_IMPORT_TEST_LOGIN . wp_generate_uuid4(),
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

	$seda_ks    = papelito_sku_import_db_simple( 'Seda Zqximport King Size', '25 Livretos por Display' );
	$seda_mini  = papelito_sku_import_db_simple( 'Seda Zqximport Mini Size', '25 Livretos por Display' );
	$products[] = $seda_ks;
	$products[] = $seda_mini;
	$sku_before = get_post_meta( $seda_ks, '_sku', true );

	$parent = new WC_Product_Variable();
	$parent->set_name( 'Dichavador Zqximport' );
	$parent->set_status( 'publish' );
	$parent_id  = $parent->save();
	$products[] = $parent_id;
	$variations = array();
	foreach ( array( 'azul', 'rosa' ) as $color ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( array( 'cor' => $color ) );
		$variation->set_status( 'publish' );
		$variations[] = $variation->save();
	}
	$products = array_merge( $products, $variations );
	$all_ids  = array_merge( array( $seda_ks, $seda_mini ), $variations );

	$sheet = array(
		papelito_sku_import_db_row( 2, SKU_IMPORT_TEST_SEDA_KS, SKU_IMPORT_TEST_CODE, 'HEADSHOP - SEDA PAPELITO' ),
		papelito_sku_import_db_row( 3, SKU_IMPORT_TEST_GRINDER, SKU_IMPORT_TEST_GRINDER_CODE, SKU_IMPORT_TEST_GRINDERS ),
		papelito_sku_import_db_row( 4, SKU_IMPORT_TEST_GENERIC, '9999' ),
	);

	wp_set_current_user( $vendor_a );
	$first = papelito_sku_import_db_preview( $vendor_a, $sheet );
	papelito_sku_import_db_check( 'linha exata', 'exact', $first[2]['status'] );
	papelito_sku_import_db_check( 'linha exata aponta a seda king size', $seda_ks, $first[2]['family']['family_id'] );
	papelito_sku_import_db_check( 'triturador por sinônimo', 'synonym', $first[3]['status'] );
	papelito_sku_import_db_check( 'sem cor, todas as variações', $variations, array_column( $first[3]['family']['targets'], 'product_id' ) );
	papelito_sku_import_db_check( 'descrição genérica fica em revisão', 'review', $first[4]['status'] );
	papelito_sku_import_db_check( 'revisão sugere as duas sedas', array( $seda_ks, $seda_mini ), array_values( array_intersect( array( $seda_ks, $seda_mini ), array_column( $first[4]['candidates'], 'family_id' ) ) ) );
	papelito_sku_import_db_check( 'prévia não grava código', array_fill_keys( $all_ids, null ), papelito_sku_import_db_codes( $vendor_a, $all_ids ) );
	papelito_sku_import_db_check( 'prévia não grava trilha', 0, papelito_sku_import_db_log_count( $vendor_a ) );

	$result = papelito_sku_import_db_confirm( $vendor_a, array( papelito_sku_import_db_decision( $first[2] ), papelito_sku_import_db_decision( $first[3] ) ) );
	papelito_sku_import_db_check(
		'confirmação aplica as duas linhas',
		array(
			2 => 'applied',
			3 => 'applied',
		),
		papelito_sku_import_db_statuses( $result )
	);
	papelito_sku_import_db_check( 'itens gravados contam as variações', 3, $result['summary']['updated_items'] );
	papelito_sku_import_db_check(
		'códigos gravados com zero à esquerda e nas duas cores',
		array(
			$seda_ks       => SKU_IMPORT_TEST_CODE,
			$seda_mini     => null,
			$variations[0] => SKU_IMPORT_TEST_GRINDER_CODE,
			$variations[1] => SKU_IMPORT_TEST_GRINDER_CODE,
		),
		papelito_sku_import_db_codes( $vendor_a, $all_ids )
	);
	papelito_sku_import_db_check( 'SKU da Papelito intocado', $sku_before, get_post_meta( $seda_ks, '_sku', true ) );
	papelito_sku_import_db_check( 'outro vendor intocado', array_fill_keys( $all_ids, null ), papelito_sku_import_db_codes( $vendor_b, $all_ids ) );
	papelito_sku_import_db_check( 'trilha registra cada item como import', 3, papelito_sku_import_db_log_count( $vendor_a, 'import' ) );
	papelito_sku_import_db_check( 'código não cria linha de estoque', 0, papelito_get_vendor_stock( $vendor_a, $seda_ks ) );

	$again = papelito_sku_import_db_preview( $vendor_a, $sheet );
	papelito_sku_import_db_check( 'prévia repetida mostra já atualizado', array( 'up_to_date', 'up_to_date' ), array( $again[2]['status'], $again[3]['status'] ) );
	$result = papelito_sku_import_db_confirm( $vendor_a, array( papelito_sku_import_db_decision( $again[2] ), papelito_sku_import_db_decision( $again[3] ) ) );
	papelito_sku_import_db_check(
		'reimportação não grava',
		array(
			2 => 'up_to_date',
			3 => 'up_to_date',
		),
		papelito_sku_import_db_statuses( $result )
	);
	papelito_sku_import_db_check( 'reimportação não cria trilha', 3, papelito_sku_import_db_log_count( $vendor_a ) );

	$changed         = papelito_sku_import_db_decision( $first[2] );
	$changed['code'] = 'NOVO-1';
	$result          = papelito_sku_import_db_confirm( $vendor_a, array( $changed ) );
	papelito_sku_import_db_check( 'valor mudou desde a prévia', array( 2 => 'stale' ), papelito_sku_import_db_statuses( $result ) );
	papelito_sku_import_db_check( 'linha desatualizada não grava', SKU_IMPORT_TEST_CODE, papelito_sku_import_db_codes( $vendor_a, array( $seda_ks ) )[ $seda_ks ] );

	$result = papelito_sku_import_db_confirm( $vendor_a, array( papelito_sku_import_db_decision( $first[4], $parent_id ) ) );
	papelito_sku_import_db_check( 'família fora dos candidatos é recusada', array( 4 => 'rejected' ), papelito_sku_import_db_statuses( $result ) );
	$result = papelito_sku_import_db_confirm( $vendor_a, array( papelito_sku_import_db_decision( $first[4], $seda_mini ) ) );
	papelito_sku_import_db_check( 'candidato escolhido na revisão é aplicado', array( 4 => 'applied' ), papelito_sku_import_db_statuses( $result ) );
	papelito_sku_import_db_check( 'código da revisão gravado', '9999', papelito_sku_import_db_codes( $vendor_a, array( $seda_mini ) )[ $seda_mini ] );

	$fresh  = papelito_sku_import_db_preview( $vendor_a, array( papelito_sku_import_db_row( 2, SKU_IMPORT_TEST_SEDA_KS, 'A-1' ), papelito_sku_import_db_row( 3, 'SEDA ZQXIMPORT KING SIZE C/25', 'A-2' ) ) );
	$result = papelito_sku_import_db_confirm( $vendor_a, array( papelito_sku_import_db_decision( $fresh[2] ), papelito_sku_import_db_decision( $fresh[3] ) ) );
	papelito_sku_import_db_check(
		'dois códigos para o mesmo item: conflito nas duas',
		array(
			2 => 'conflict',
			3 => 'conflict',
		),
		papelito_sku_import_db_statuses( $result )
	);
	papelito_sku_import_db_check( 'conflito não grava', SKU_IMPORT_TEST_CODE, papelito_sku_import_db_codes( $vendor_a, array( $seda_ks ) )[ $seda_ks ] );

	$fresh     = papelito_sku_import_db_preview( $vendor_a, array( papelito_sku_import_db_row( 2, SKU_IMPORT_TEST_SEDA_KS, 'R-1' ), papelito_sku_import_db_row( 3, SKU_IMPORT_TEST_GRINDER, 'R-2', SKU_IMPORT_TEST_GRINDERS ) ) );
	$log_table = papelito_vendor_item_settings_log_table_name();
	$inserts   = 0;
	$sabotage  = static function ( string $query ) use ( $log_table, &$inserts ): string {
		if ( str_starts_with( $query, "INSERT INTO `{$log_table}`" ) && 2 === ++$inserts ) {
			return 'INSERT INTO zzz_sku_import_missing_table (x) VALUES (1)';
		}
		return $query;
	};
	add_filter( 'query', $sabotage );
	$wpdb->suppress_errors( true );
	$result = papelito_sku_import_db_confirm( $vendor_a, array( papelito_sku_import_db_decision( $fresh[2] ), papelito_sku_import_db_decision( $fresh[3] ) ) );
	$wpdb->suppress_errors( false );
	remove_filter( 'query', $sabotage );
	papelito_sku_import_db_check( 'falha de SQL devolve erro de gravação', 'persist', is_wp_error( $result ) ? $result->get_error_data()['stage'] : null );
	papelito_sku_import_db_check( 'falha desfaz a primeira linha já gravada', SKU_IMPORT_TEST_CODE, papelito_sku_import_db_codes( $vendor_a, array( $seda_ks ) )[ $seda_ks ] );
	papelito_sku_import_db_check( 'falha não deixa trilha', 4, papelito_sku_import_db_log_count( $vendor_a ) );

	$overlap_preview = papelito_vendor_code_import_preview(
		$vendor_a,
		array(
			papelito_sku_import_db_row( 2, SKU_IMPORT_TEST_GRINDER . ' AZUL', 'OV-1', SKU_IMPORT_TEST_GRINDERS ),
			papelito_sku_import_db_row( 3, SKU_IMPORT_TEST_GRINDER, 'OV-1', SKU_IMPORT_TEST_GRINDERS ),
		)
	);
	$overlap         = array_column( $overlap_preview['rows'], null, 'line' );
	papelito_sku_import_db_check( 'linha da cor alcança só a variação azul', array( $variations[0] ), array_column( $overlap[2]['family']['targets'], 'product_id' ) );
	papelito_sku_import_db_check( 'mesma cor e mesmo código não é conflito', array( 'synonym', 'synonym' ), array( $overlap[2]['status'], $overlap[3]['status'] ) );
	papelito_sku_import_db_check( 'prévia conta cada item uma vez só', 2, $overlap_preview['summary']['changes'] );
	$result = papelito_sku_import_db_confirm( $vendor_a, array( papelito_sku_import_db_decision( $overlap[2] ), papelito_sku_import_db_decision( $overlap[3] ) ) );
	papelito_sku_import_db_check(
		'linha da família depois da linha da cor não vira stale',
		array(
			2 => 'applied',
			3 => 'applied',
		),
		papelito_sku_import_db_statuses( $result )
	);
	papelito_sku_import_db_check( 'todas as variações recebem o código', array_fill_keys( $variations, 'OV-1' ), papelito_sku_import_db_codes( $vendor_a, $variations ) );
	papelito_sku_import_db_check( 'cada item gravado uma vez', 2, $result['summary']['updated_items'] );

	$export = array_column( papelito_vendor_stock_export_rows( $vendor_a ), 'sku', 'product_name' );
	papelito_sku_import_db_check( 'exportação mostra o código importado', SKU_IMPORT_TEST_CODE, $export['Seda Zqximport King Size'] ?? null );

	wp_set_current_user( $vendor_b );
	$preview_b = papelito_sku_import_db_preview( $vendor_b, array( $sheet[0] ) );
	papelito_sku_import_db_check( 'outro vendor vê o próprio código atual', null, $preview_b[2]['family']['targets'][0]['current_code'] );
	$result = papelito_sku_import_db_confirm( $vendor_b, array( papelito_sku_import_db_decision( $preview_b[2] ) ) );
	papelito_sku_import_db_check( 'vendor B grava o seu', array( 2 => 'applied' ), papelito_sku_import_db_statuses( $result ) );
	papelito_sku_import_db_check( 'vendor B não altera o código do vendor A', SKU_IMPORT_TEST_CODE, papelito_sku_import_db_codes( $vendor_a, array( $seda_ks ) )[ $seda_ks ] );

	wp_set_current_user( $customer );
	$request = new WP_REST_Request( 'POST', '/papelito/v1/vendor/me/products/vendor-codes/import' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'rows' => array( papelito_sku_import_db_decision( $first[2] ) ) ) ) );
	papelito_sku_import_db_check( 'cliente não confirma importação', 403, rest_do_request( $request )->get_status() );
	papelito_sku_import_db_check( 'cliente não recebe prévia pelo upload', 'papelito_upload_not_allowed', papelito_vendor_code_import_receive( array( 'context' => array( 'vendor_id' => $customer ) ), array() )->get_error_code() );

	wp_set_current_user( $vendor_a );
	$patch = new WP_REST_Request( 'PATCH', '/papelito/v1/vendor/me/products/' . $seda_ks . '/settings' );
	$patch->set_header( 'Content-Type', 'application/json' );
	$patch->set_body( wp_json_encode( array( 'vendor_code' => 'MANUAL-1' ) ) );
	papelito_sku_import_db_check( 'edição manual continua funcionando', 'MANUAL-1', rest_do_request( $patch )->get_data()['vendor_code'] ?? null );
	papelito_sku_import_db_check( 'edição manual entra na trilha como manual', 1, papelito_sku_import_db_log_count( $vendor_a, 'manual' ) );

	$broken_log = static function ( string $query ) use ( $log_table ): string {
		return str_starts_with( $query, "INSERT INTO `{$log_table}`" ) ? 'INSERT INTO zzz_sku_import_missing_table (x) VALUES (1)' : $query;
	};
	add_filter( 'query', $broken_log );
	$wpdb->suppress_errors( true );
	foreach ( array( 'MANUAL-2', null ) as $attempt ) {
		$patch->set_body( wp_json_encode( array( 'vendor_code' => $attempt ) ) );
		papelito_sku_import_db_check( 'falha na trilha devolve erro na edição manual (' . wp_json_encode( $attempt ) . ')', 500, rest_do_request( $patch )->get_status() );
		papelito_sku_import_db_check( 'falha na trilha preserva o código anterior (' . wp_json_encode( $attempt ) . ')', 'MANUAL-1', papelito_sku_import_db_codes( $vendor_a, array( $seda_ks ) )[ $seda_ks ] );
	}
	$wpdb->suppress_errors( false );
	remove_filter( 'query', $broken_log );
} catch ( Throwable $error ) {
	$failure = $error->getMessage();
} finally {
	wp_set_current_user( $original_id );
	foreach ( $products as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $fixture_id ) {
		$wpdb->delete( papelito_vendor_item_settings_log_table_name(), array( 'vendor_id' => $fixture_id ), array( '%d' ) );
		delete_transient( 'papelito_rl_vendor_item_settings_' . md5( 'user:' . $fixture_id ) );
		delete_transient( 'papelito_rl_vendor_code_import_' . md5( 'user:' . $fixture_id ) );
		wp_delete_user( $fixture_id );
	}
}

if ( null !== $failure ) {
	WP_CLI::error( $failure );
}
WP_CLI::success( $sku_import_checks . ' checagens da importação de códigos passaram.' );
