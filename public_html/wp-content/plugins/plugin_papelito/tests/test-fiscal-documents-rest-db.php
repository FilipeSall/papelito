<?php
/**
 * Integration checks for the vendor fiscal document surface (etapa 6).
 *
 * Exercita o que só o banco e o WooCommerce garantem: quem pode anexar, o
 * recálculo a cada anexo, a substituição preservando a versão anterior e a
 * consulta em lote que a listagem usa. Precisa do WordPress carregado:
 *
 *   wp eval-file tests/test-fiscal-documents-rest-db.php
 *
 * Cria um pedido descartável e apaga tudo no fim.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-fiscal-documents-rest-db.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * `wp eval-file` executa o arquivo dentro de uma função: variável de topo NÃO é
 * global. Sem declarar aqui, o `global $failures` do helper apontaria para
 * outra variável e o teste sairia com código 0 mesmo falhando.
 */
global $wpdb, $failures, $fiscal_tables;

$failures      = 0;
$fiscal_tables = papelito_fiscal_table_names();

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param mixed $expected Valor esperado.
 * @param mixed $actual   Valor obtido.
 */
function assert_fiscal_rest( string $label, $expected, $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo 'PASS: ' . esc_html( $label ) . "\n";
		return;
	}

	++$failures;
	echo 'FAIL: ' . esc_html( $label ) . ' | esperado ' . esc_html( (string) wp_json_encode( $expected ) ) . ' | obtido ' . esc_html( (string) wp_json_encode( $actual ) ) . "\n";
}

/**
 * XML de NF-e coerente com a chave informada, para exercitar a extração.
 */
function papelito_fiscal_rest_test_xml( string $access_key, string $issuer_cnpj, string $total ): string {
	return '<?xml version="1.0" encoding="UTF-8"?>'
		. '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe' . $access_key . '" versao="4.00">'
		. '<ide><nNF>777</nNF><serie>1</serie><dhEmi>2025-09-01T09:15:00-03:00</dhEmi></ide>'
		. '<emit><CNPJ>' . $issuer_cnpj . '</CNPJ><xNome>EMITENTE DE TESTE LTDA</xNome></emit>'
		. '<total><ICMSTot><vNF>' . $total . '</vNF></ICMSTot></total>'
		. '</infNFe></NFe><protNFe><infProt><nProt>199999999999999</nProt></infProt></protNFe></nfeProc>';
}

/**
 * Grava o XML num arquivo temporário no formato de `$_FILES`.
 *
 * @return array<string,mixed>
 */
function papelito_fiscal_rest_test_upload( string $contents, string $name ): array {
	$path = wp_tempnam( $name );
	file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	return array(
		'name'     => $name,
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => strlen( $contents ),
		'type'     => 'application/xml',
	);
}

$vendor = get_users(
	array(
		'role'   => 'seller',
		'number' => 1,
	)
);

if ( empty( $vendor ) ) {
	echo "SKIP: nenhum usuario com papel seller neste ambiente\n";
	exit( 0 );
}

$vendor_id   = (int) $vendor[0]->ID;
$vendor_cnpj = papelito_fiscal_key_normalize( (string) get_user_meta( $vendor_id, 'cnpj', true ) );

if ( 14 !== strlen( $vendor_cnpj ) ) {
	$vendor_cnpj = '65326368000190';
}

$test_order = wc_create_order();
$test_order->update_meta_data( '_papelito_vendor_id', (string) $vendor_id );
$test_order->set_total( '110.27' );
$test_order->save();
$order_id = (int) $test_order->get_id();

// Pedido ainda não pago: a superfície tem de recusar o anexo.
assert_fiscal_rest( 'pedido nao pago nao aceita nota', 'aguardando_pagamento', papelito_fiscal_order_block_reason( $test_order ) );

$test_order->update_status( 'processing' );
$test_order->set_date_paid( time() );
$test_order->save();
$test_order = wc_get_order( $order_id );

assert_fiscal_rest( 'pedido pago aceita nota', '', papelito_fiscal_order_block_reason( $test_order ) );

$receipt_before = function_exists( 'papelito_receipt_get_by_order' ) ? papelito_receipt_get_by_order( $order_id ) : null;

// Só o digitado: sem XML, o nível para na chave.
$base       = substr( str_pad( '53250' . $vendor_cnpj . '550010000007771000007', 43, '0' ), 0, 43 );
$access_key = $base . papelito_fiscal_key_check_digit( $base );

$saved = papelito_fiscal_document_save_declared(
	$test_order,
	$vendor_id,
	array(
		'access_key'  => $access_key,
		'doc_number'  => '777',
		'issuer_cnpj' => $vendor_cnpj,
	),
	$vendor_id
);

assert_fiscal_rest( 'digitado cria documento corrente', true, is_array( $saved ) && $saved['id'] > 0 );
assert_fiscal_rest( 'sem xml o nivel para na chave', PAPELITO_FISCAL_LEVEL_KEY, (int) $saved['validation_level'] );
assert_fiscal_rest( 'documento novo nasce sem arquivo', 0, count( $saved['files'] ) );

$first_document_id = (int) $saved['id'];

// Anexar o XML precisa reabrir os cruzamentos, não congelar o nível anterior.
$xml       = papelito_fiscal_rest_test_xml( $access_key, $vendor_cnpj, '110.27' );
$upload    = papelito_fiscal_rest_test_upload( $xml, 'nota-teste.xml' );
$validated = papelito_fiscal_document_validate_upload( $upload, 'xml' );

assert_fiscal_rest( 'xml valido passa na validacao', false, is_wp_error( $validated ) );

$attached = papelito_fiscal_document_attach_file(
	$test_order,
	$vendor_id,
	array(
		'role'      => 'xml',
		'file'      => $upload,
		'validated' => $validated,
		'declared'  => array(),
		'mode'      => 'attach',
	),
	$vendor_id
);

assert_fiscal_rest( 'anexo entra no documento corrente', $first_document_id, is_array( $attached ) ? (int) $attached['id'] : 0 );
assert_fiscal_rest( 'anexar xml recalcula o nivel', true, (int) $attached['validation_level'] > PAPELITO_FISCAL_LEVEL_KEY );
assert_fiscal_rest( 'xml preenche o emitente que faltava', 'EMITENTE DE TESTE LTDA', (string) $attached['issuer_name'] );
assert_fiscal_rest( 'arquivo fica ativo no papel xml', 1, count( $attached['files'] ) );

// Substituir troca a nota: mesma linha, arquivo novo, sem versão anterior.
$old_keys = array();
global $fiscal_tables;
foreach ( papelito_fiscal_document_files( $first_document_id, false ) as $old_file ) {
	$old_keys[] = (string) $old_file['storage_key'];
}

$replacement_upload = papelito_fiscal_rest_test_upload( $xml, 'nota-teste-2.xml' );
$replacement        = papelito_fiscal_document_attach_file(
	$test_order,
	$vendor_id,
	array(
		'role'      => 'xml',
		'file'      => $replacement_upload,
		'validated' => papelito_fiscal_document_validate_upload( $replacement_upload, 'xml' ),
		'declared'  => array(),
		'mode'      => 'replace',
	),
	$vendor_id
);

$second_document_id = is_array( $replacement ) ? (int) $replacement['id'] : 0;
$documents_total    = (int) $wpdb->get_var(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT COUNT(*) FROM {$fiscal_tables['documents']} WHERE order_id = %d", $order_id )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$files_total = count( papelito_fiscal_document_files( $second_document_id, false ) );
$directory   = papelito_fiscal_documents_prepare_dir();
$orphans     = 0;

foreach ( $old_keys as $old_key ) {
	if ( ! is_wp_error( $directory ) && is_file( trailingslashit( $directory ) . $old_key ) ) {
		++$orphans;
	}
}

assert_fiscal_rest( 'substituir nao abre documento novo', $first_document_id, $second_document_id );
assert_fiscal_rest( 'pedido continua com uma nota so', 1, $documents_total );
assert_fiscal_rest( 'nota substituida nao deixa versao', 1, $files_total );
assert_fiscal_rest( 'arquivo anterior sai do disco', 0, $orphans );
// Uma nota por pedido nao significa nenhum rastro: a linha e reescrita, mas o
// log de eventos e cumulativo e sobrevive a substituicao.
$history = is_array( $replacement ) ? (array) ( $replacement['events'] ?? array() ) : array();

assert_fiscal_rest( 'payload carrega o historico do documento', true, ! empty( $history ) );
assert_fiscal_rest( 'evento mais recente e a substituicao', 'substituida', (string) ( $history[0]['event'] ?? '' ) );
assert_fiscal_rest( 'substituir preserva os eventos anteriores', 3, count( $history ) );
assert_fiscal_rest(
	'historico registra criacao e anexo antes da troca',
	array( 'substituida', 'arquivo_anexado', 'criado' ),
	array_column( $history, 'event' )
);
assert_fiscal_rest( 'historico diz quem agiu', 'vendor', (string) ( $history[0]['actor_role'] ?? '' ) );
assert_fiscal_rest( 'historico nao carrega a chave inteira', false, array_key_exists( 'access_key', (array) ( $history[0] ?? array() ) ) );

// Falha na validação não pode tocar a nota que já está no pedido.
$rejected = papelito_fiscal_document_validate_upload(
	papelito_fiscal_rest_test_upload( $xml, 'nota-invalida.pdf' ),
	'danfe_pdf'
);
$after_failure = papelito_fiscal_document_current( $order_id, $vendor_id );

assert_fiscal_rest( 'arquivo no papel errado e recusado', true, is_wp_error( $rejected ) );
assert_fiscal_rest( 'nota anterior sobrevive a falha', $first_document_id, (int) $after_failure['id'] );
assert_fiscal_rest( 'nota anterior mantem o arquivo', 1, count( papelito_fiscal_document_files( $first_document_id ) ) );

// A listagem descobre quem tem nota em uma consulta so, sem lista de ids.
$documented = papelito_fiscal_documented_order_ids_for_vendor( $vendor_id );

assert_fiscal_rest( 'consulta unica encontra o pedido com nota', true, isset( $documented[ $order_id ] ) );
assert_fiscal_rest( 'consulta unica nao inventa pedido', false, isset( $documented[ $order_id + 987654 ] ) );
assert_fiscal_rest( 'pedido com nota deixa de estar pendente', false, papelito_vendor_dashboard_fiscal_is_pending( $test_order, true ) );

// Pedido cancelado para de aceitar nota.
$test_order->update_meta_data( '_papelito_vendor_status', 'cancelado' );
$test_order->save();
$test_order = wc_get_order( $order_id );

assert_fiscal_rest( 'pedido cancelado nao aceita nota', 'cancelado', papelito_fiscal_order_block_reason( $test_order ) );

// O recibo é permanente: nenhuma operação de nota fiscal o altera.
$receipt_after = function_exists( 'papelito_receipt_get_by_order' ) ? papelito_receipt_get_by_order( $order_id ) : null;
assert_fiscal_rest(
	'recibo intacto depois das operacoes de nota',
	$receipt_before ? (string) $receipt_before['receipt_number'] : '',
	$receipt_after ? (string) $receipt_after['receipt_number'] : ''
);

// Limpeza: linhas, arquivos em disco e o pedido descartável.
$directory    = papelito_fiscal_documents_prepare_dir();
$document_ids = $wpdb->get_col(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
	$wpdb->prepare( "SELECT id FROM {$fiscal_tables['documents']} WHERE order_id = %d", $order_id )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array_map( 'intval', (array) $document_ids ) as $document_id ) {
	foreach ( papelito_fiscal_document_files( $document_id, false ) as $file ) {
		if ( ! is_wp_error( $directory ) && papelito_fiscal_document_key_is_valid( (string) $file['storage_key'] ) ) {
			papelito_fiscal_document_discard_path( trailingslashit( $directory ) . (string) $file['storage_key'] );
		}
	}

	$wpdb->delete( $fiscal_tables['files'], array( 'fiscal_document_id' => $document_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $fiscal_tables['events'], array( 'fiscal_document_id' => $document_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

$wpdb->delete( $fiscal_tables['documents'], array( 'order_id' => $order_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_delete_post( $order_id, true );

echo "limpeza ok\n";

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
