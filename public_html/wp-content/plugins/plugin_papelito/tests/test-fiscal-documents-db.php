<?php
/**
 * Integration checks for the fiscal document schema.
 *
 * O que só o MySQL garante: N versões históricas com uma única corrente por
 * (pedido, vendor), um arquivo ativo por papel, e chave de acesso duplicada
 * como sinalização — não como erro de banco. Precisa do WordPress carregado:
 *
 *   wp eval-file tests/test-fiscal-documents-db.php
 *
 * Cria linhas descartáveis com order_id reservado e apaga tudo no fim.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-fiscal-documents-db.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * Pedido reservado para teste: nunca colide com pedido real.
 */
const PAPELITO_FISCAL_TEST_ORDER_ID = 999000001;

/**
 * `wp eval-file` executa o arquivo dentro de uma função: variável de topo NÃO é
 * global. Sem declarar aqui, o `global $failures` dos helpers apontaria para
 * outra variável e o teste sairia com código 0 mesmo falhando.
 */
global $wpdb, $tables, $failures, $now;

$tables   = papelito_fiscal_table_names();
$failures = 0;
$now      = current_time( 'mysql', true );

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param mixed $expected Valor esperado.
 * @param mixed $actual   Valor obtido.
 */
function assert_fiscal_db( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo 'PASS: ' . esc_html( $label ) . "\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . esc_html( $label ) . ' (expected ' . esc_html( var_export( $expected, true ) ) . ', got ' . esc_html( var_export( $actual, true ) ) . ")\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
}

/**
 * Insere um documento de teste.
 *
 * @param array<string,mixed> $overrides Campos a sobrescrever.
 * @return int|false
 */
function fiscal_db_insert( array $overrides = array() ) {
	global $wpdb, $tables, $now;

	$row = array_merge(
		array(
			'order_id'          => PAPELITO_FISCAL_TEST_ORDER_ID,
			'vendor_id'         => 4321,
			'doc_type'          => 'nfe',
			'doc_status'        => 'recebida',
			'validation_level'  => PAPELITO_FISCAL_LEVEL_KEY,
			'access_key_status' => 'ausente',
			'total_cents'       => 15000,
			'is_current'        => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		),
		$overrides
	);

	$inserted = $wpdb->insert( $tables['documents'], $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return false === $inserted ? false : (int) $wpdb->insert_id;
}

papelito_fiscal_documents_install_tables();

/**
 * Cria um upload XML temporário para validar o caminho real do WordPress.
 *
 * @return array<string,mixed>
 */
function fiscal_db_xml_upload( string $contents ): array {
	$tmp_file = wp_tempnam( 'papelito-fiscal-upload' );
	file_put_contents( $tmp_file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	return array(
		'error'    => UPLOAD_ERR_OK,
		'name'     => 'nota.xml',
		'tmp_name' => $tmp_file,
	);
}

$valid_xml_upload = fiscal_db_xml_upload( '<?xml version="1.0"?><nfeProc><NFe/></nfeProc>' );
$valid_xml_result = papelito_fiscal_document_validate_upload( $valid_xml_upload, 'xml' );
assert_fiscal_db( 'xml valido passa pela validacao real do WordPress', true, is_array( $valid_xml_result ) );
unlink( $valid_xml_upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

$invalid_xml_upload = fiscal_db_xml_upload( '<?xml version="1.0"?><nfeProc><NFe></nfeProc>' );
$invalid_xml_result = papelito_fiscal_document_validate_upload( $invalid_xml_upload, 'xml' );
assert_fiscal_db(
	'xml malformado e recusado antes do armazenamento',
	'papelito_fiscal_document_xml_invalid',
	is_wp_error( $invalid_xml_result ) ? $invalid_xml_result->get_error_code() : 'aceitou'
);
unlink( $invalid_xml_upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

// 1. Uma corrente por (pedido, vendor).
$first = fiscal_db_insert();
assert_fiscal_db( 'primeiro documento e inserido', true, $first > 0 );

$suppress = $wpdb->suppress_errors( true );
$conflict = fiscal_db_insert();
$wpdb->suppress_errors( $suppress );
assert_fiscal_db( 'segunda corrente do mesmo vendor e recusada pelo indice', false, $conflict );

// 2. Histórico: is_current = NULL permite N versões.
$wpdb->update(
	$tables['documents'],
	array(
		'is_current' => null,
		'doc_status' => 'substituida',
	),
	array( 'id' => $first )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$second = fiscal_db_insert( array( 'replaces_document_id' => $first ) );
assert_fiscal_db( 'nova corrente entra depois de arquivar a anterior', true, $second > 0 );

$wpdb->update(
	$tables['documents'],
	array(
		'is_current' => null,
		'doc_status' => 'substituida',
	),
	array( 'id' => $second )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$third = fiscal_db_insert( array( 'replaces_document_id' => $second ) );
assert_fiscal_db( 'terceira versao tambem entra', true, $third > 0 );

$historic = (int) $wpdb->get_var(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['documents']} WHERE order_id = %d AND is_current IS NULL", PAPELITO_FISCAL_TEST_ORDER_ID )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
assert_fiscal_db( 'duas versoes historicas convivem', 2, $historic );

$current = papelito_fiscal_document_current( PAPELITO_FISCAL_TEST_ORDER_ID, 4321 );
assert_fiscal_db( 'helper devolve a corrente', $third, is_array( $current ) ? (int) $current['id'] : 0 );

// 3. Outro vendor no mesmo pedido tem a sua própria corrente.
$other_vendor = fiscal_db_insert( array( 'vendor_id' => 8765 ) );
assert_fiscal_db( 'outro vendor tem corrente propria', true, $other_vendor > 0 );

// 4. Chave duplicada é sinalização, não erro de banco.
$key_base = implode( '', array( '35', '2607', '11222333000181', '55', '001', '000000123', '1', '12345678' ) );
$key      = $key_base . papelito_fiscal_key_check_digit( $key_base );
assert_fiscal_db( 'chave da fixture tem 44 digitos', 44, strlen( $key ) );
$wpdb->update(
	$tables['documents'],
	array(
		'access_key'        => $key,
		'access_key_status' => 'valida',
	),
	array( 'id' => $third )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$duplicate = $wpdb->update(
	$tables['documents'],
	array(
		'access_key'        => $key,
		'access_key_status' => 'valida',
	),
	array( 'id' => $other_vendor )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

assert_fiscal_db( 'chave duplicada e aceita pelo banco', true, false !== $duplicate );
assert_fiscal_db( 'busca por chave encontra as duas', 2, count( papelito_fiscal_documents_by_access_key( $key ) ) );

// 5. Um arquivo ativo por papel; removidos convivem com is_active NULL.
$file_row = array(
	'fiscal_document_id' => $third,
	'role'               => 'xml',
	'storage_key'        => bin2hex( random_bytes( 32 ) ) . '.xml',
	'mime'               => 'application/xml',
	'size_bytes'         => 2048,
	'sha256'             => str_repeat( 'a', 64 ),
	'is_active'          => 1,
	'created_at'         => $now,
);
$file_one = $wpdb->insert( $tables['files'], $file_row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
assert_fiscal_db( 'primeiro xml do documento entra', true, false !== $file_one );
$file_one_id = (int) $wpdb->insert_id;

$file_row['storage_key'] = bin2hex( random_bytes( 32 ) ) . '.xml';
$suppress                = $wpdb->suppress_errors( true );
$file_conflict           = $wpdb->insert( $tables['files'], $file_row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->suppress_errors( $suppress );
assert_fiscal_db( 'segundo xml ativo e recusado', false, $file_conflict );

$wpdb->update(
	$tables['files'],
	array(
		'is_active'  => null,
		'deleted_at' => $now,
	),
	array( 'id' => $file_one_id )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$file_two = $wpdb->insert( $tables['files'], $file_row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
assert_fiscal_db( 'novo xml entra depois do soft-delete', true, false !== $file_two );

$file_row['role']        = 'danfe_pdf';
$file_row['storage_key'] = bin2hex( random_bytes( 32 ) ) . '.pdf';
$file_row['mime']        = 'application/pdf';
$pdf_file                = $wpdb->insert( $tables['files'], $file_row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
assert_fiscal_db( 'pdf e xml convivem no mesmo documento', true, false !== $pdf_file );

assert_fiscal_db( 'helper lista so os arquivos ativos', 2, count( papelito_fiscal_document_files( $third ) ) );
assert_fiscal_db( 'helper opcionalmente lista os removidos', 3, count( papelito_fiscal_document_files( $third, false ) ) );

// 6. Evento de auditoria não guarda chave completa.
papelito_fiscal_document_log_event(
	$third,
	'documento_anexado',
	array(
		'access_key' => $key,
		'role'       => 'xml',
		'doc_status' => 'recebida',
	),
	0,
	'vendor'
);
$event = $wpdb->get_row(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT * FROM {$tables['events']} WHERE fiscal_document_id = %d ORDER BY id DESC LIMIT 1", $third ),
	ARRAY_A
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

assert_fiscal_db( 'evento foi gravado', 'documento_anexado', is_array( $event ) ? (string) $event['event'] : '' );
assert_fiscal_db( 'evento nao contem a chave completa', false, is_array( $event ) && str_contains( (string) $event['detail_json'], $key ) );
assert_fiscal_db( 'evento guarda os 4 ultimos digitos', true, is_array( $event ) && str_contains( (string) $event['detail_json'], substr( $key, -4 ) ) );

// Limpeza.
$document_ids = $wpdb->get_col(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT id FROM {$tables['documents']} WHERE order_id = %d", PAPELITO_FISCAL_TEST_ORDER_ID )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array_map( 'intval', (array) $document_ids ) as $document_id ) {
	$wpdb->delete( $tables['files'], array( 'fiscal_document_id' => $document_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $tables['events'], array( 'fiscal_document_id' => $document_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$wpdb->delete( $tables['documents'], array( 'order_id' => PAPELITO_FISCAL_TEST_ORDER_ID ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

echo "limpeza ok\n";

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
