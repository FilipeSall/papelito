<?php
/**
 * Integration checks da nota fiscal como arquivo indexado.
 *
 * O que só o banco e o disco garantem: uma nota por (pedido, vendor),
 * substituição que apaga o arquivo anterior, remoção que não deixa órfão, e a
 * varredura que recolhe arquivo sem linha sem tocar em upload recém-gravado.
 * Precisa do WordPress carregado:
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
const PAPELITO_FISCAL_TEST_VENDOR_ID = 999000002;

/**
 * `wp eval-file` executa o arquivo dentro de uma função: variável de topo NÃO é
 * global. Sem declarar aqui, o `global $failures` dos helpers apontaria para
 * outra variável e o teste sairia com código 0 mesmo falhando.
 */
global $wpdb, $tables, $failures, $now, $directory;

$tables    = papelito_fiscal_table_names();
$failures  = 0;
$now       = current_time( 'mysql', true );
$directory = papelito_fiscal_documents_prepare_dir();

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
 * Grava um arquivo no diretório privado e devolve a storage key.
 */
function fiscal_db_write_file( string $contents = '%PDF-1.4 teste' ): string {
	global $directory;

	$key = bin2hex( random_bytes( 32 ) ) . '.pdf';
	file_put_contents( trailingslashit( $directory ) . $key, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	return $key;
}

function fiscal_db_file_exists( string $key ): bool {
	global $directory;

	return is_file( trailingslashit( $directory ) . $key );
}

/**
 * Insere uma nota de teste diretamente, sem passar pelo upload.
 *
 * @param array<string,mixed> $overrides Campos a sobrescrever.
 * @return int|false
 */
function fiscal_db_insert( array $overrides = array() ) {
	global $wpdb, $tables, $now;

	$inserted = $wpdb->insert(
		$tables['documents'],
		array_merge(
			array(
				'order_id'      => PAPELITO_FISCAL_TEST_ORDER_ID,
				'vendor_id'     => PAPELITO_FISCAL_TEST_VENDOR_ID,
				'storage_key'   => fiscal_db_write_file(),
				'original_name' => 'nota.pdf',
				'mime'          => 'application/pdf',
				'size_bytes'    => 14,
				'sha256'        => str_repeat( 'a', 64 ),
				'uploaded_by'   => PAPELITO_FISCAL_TEST_VENDOR_ID,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			$overrides
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return false === $inserted ? false : (int) $wpdb->insert_id;
}

function fiscal_db_cleanup(): void {
	global $wpdb, $tables;

	foreach ( array( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_ORDER_ID + 1 ) as $order_id ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT storage_key FROM {$tables['documents']} WHERE order_id = %d", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $rows as $row ) {
			papelito_fiscal_document_purge_file( (string) $row['storage_key'] );
		}

		$wpdb->delete( $tables['documents'], array( 'order_id' => $order_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $tables['events'], array( 'order_id' => $order_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

fiscal_db_cleanup();

assert_fiscal_db( 'diretorio privado esta preparado', false, is_wp_error( $directory ) );

// 1. Uma nota por (pedido, vendor): a segunda linha é recusada pelo índice único.
$first = fiscal_db_insert();
assert_fiscal_db( 'primeira nota entra', true, $first > 0 );

$suppressed = $wpdb->suppress_errors( true );
$second     = fiscal_db_insert();
$wpdb->suppress_errors( $suppressed );
assert_fiscal_db( 'segunda nota do mesmo vendor no mesmo pedido e recusada', false, $second );

// 2. Outro vendor no mesmo pedido é permitido: a unicidade é do par.
$other_vendor = fiscal_db_insert( array( 'vendor_id' => PAPELITO_FISCAL_TEST_VENDOR_ID + 1 ) );
assert_fiscal_db( 'outro vendor no mesmo pedido entra', true, $other_vendor > 0 );
$wpdb->delete( $tables['documents'], array( 'id' => $other_vendor ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 3. `papelito_fiscal_document_current()` acha a nota do par certo.
$current = papelito_fiscal_document_current( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID );
assert_fiscal_db( 'nota corrente e encontrada', $first, (int) ( $current['id'] ?? 0 ) );
assert_fiscal_db( 'par sem nota devolve null', null, papelito_fiscal_document_current( PAPELITO_FISCAL_TEST_ORDER_ID, 42424242 ) );

// 4. Substituição: a linha é a mesma, o arquivo antigo sai do disco.
$old_key    = (string) $current['storage_key'];
$new_key    = fiscal_db_write_file();
$commit     = papelito_fiscal_document_commit(
	PAPELITO_FISCAL_TEST_ORDER_ID,
	PAPELITO_FISCAL_TEST_VENDOR_ID,
	array(
		'original_name' => 'nota-v2.pdf',
		'mime'          => 'application/pdf',
		'size_bytes'    => 20,
		'size'          => 20,
		'sha256'        => str_repeat( 'b', 64 ),
	),
	array(
		'key'  => $new_key,
		'path' => trailingslashit( $directory ) . $new_key,
	),
	PAPELITO_FISCAL_TEST_VENDOR_ID
);

assert_fiscal_db( 'commit de substituicao devolve o evento', 'substituida', is_array( $commit ) ? $commit['event'] : '' );
assert_fiscal_db( 'commit devolve a key anterior para o chamador apagar', $old_key, is_array( $commit ) ? $commit['previous_key'] : '' );

$replaced = papelito_fiscal_document_current( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID );
assert_fiscal_db( 'substituir reescreve a MESMA linha', $first, (int) $replaced['id'] );
assert_fiscal_db( 'a linha aponta para o arquivo novo', $new_key, (string) $replaced['storage_key'] );
assert_fiscal_db( 'o nome original acompanha a troca', 'nota-v2.pdf', (string) $replaced['original_name'] );

// O commit não apaga: quem apaga é o chamador, depois do COMMIT.
assert_fiscal_db( 'arquivo antigo ainda existe antes do purge', true, fiscal_db_file_exists( $old_key ) );
papelito_fiscal_document_purge_file( $old_key );
assert_fiscal_db( 'arquivo antigo sai do disco no purge', false, fiscal_db_file_exists( $old_key ) );
assert_fiscal_db( 'arquivo novo continua no disco', true, fiscal_db_file_exists( $new_key ) );

// 5. A trilha acumula, e é chaveada pelo par — não pelo id do documento.
$history = papelito_fiscal_document_history( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID );
assert_fiscal_db( 'trilha registrou a substituicao', 1, count( $history ) );
assert_fiscal_db( 'trilha guarda o nome do arquivo', 'nota-v2.pdf', (string) ( $history[0]['original_name'] ?? '' ) );

// 6. Remoção: some a linha, some o arquivo, a trilha fica.
$removed = papelito_fiscal_document_remove( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID, PAPELITO_FISCAL_TEST_VENDOR_ID );
assert_fiscal_db( 'remocao devolve sucesso', true, $removed );
assert_fiscal_db( 'linha some', null, papelito_fiscal_document_current( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID ) );
assert_fiscal_db( 'arquivo some do disco', false, fiscal_db_file_exists( $new_key ) );

$history = papelito_fiscal_document_history( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID );
assert_fiscal_db( 'a trilha SOBREVIVE a remocao da nota', 2, count( $history ) );
assert_fiscal_db( 'o ultimo evento e a remocao', 'removida', (string) ( $history[1]['event'] ?? '' ) );

// 7. Remover o que não existe é 404, não erro de banco.
assert_fiscal_db(
	'remover nota inexistente devolve 404',
	'papelito_fiscal_document_not_found',
	papelito_fiscal_document_remove( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID, 1 )->get_error_code()
);

// 8. Exclusão do pedido não deixa linha nem arquivo, e registra ator "sistema".
$doc_id      = fiscal_db_insert();
$cascade_key = (string) papelito_fiscal_document_current( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID )['storage_key'];
papelito_fiscal_documents_delete_for_order( PAPELITO_FISCAL_TEST_ORDER_ID );

assert_fiscal_db( 'apagar o pedido remove a linha', null, papelito_fiscal_document_current( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID ) );
assert_fiscal_db( 'apagar o pedido remove o arquivo', false, fiscal_db_file_exists( $cascade_key ) );

$history = papelito_fiscal_document_history( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID );
$last    = end( $history );
assert_fiscal_db( 'remocao em cascata nao tem ator', 0, (int) ( $last['actor_user_id'] ?? -1 ) );
assert_fiscal_db( 'payload traduz ator zero para sistema', 'sistema', papelito_fiscal_event_payload( array( $last ) )[0]['actor_role'] );

// 9. Varredura: recolhe arquivo sem linha, preserva upload recém-gravado.
$orphan_old = fiscal_db_write_file();
$orphan_new = fiscal_db_write_file();
touch( trailingslashit( $directory ) . $orphan_old, time() - PAPELITO_FISCAL_SWEEP_MIN_AGE - 60 );

$referenced = fiscal_db_insert();
$referenced_key = (string) papelito_fiscal_document_current( PAPELITO_FISCAL_TEST_ORDER_ID, PAPELITO_FISCAL_TEST_VENDOR_ID )['storage_key'];
touch( trailingslashit( $directory ) . $referenced_key, time() - PAPELITO_FISCAL_SWEEP_MIN_AGE - 60 );

$dry = papelito_fiscal_documents_sweep( true );
assert_fiscal_db( 'dry-run enxerga o orfao antigo', true, in_array( $orphan_old, $dry['orphans'], true ) );
assert_fiscal_db( 'dry-run poupa o orfao recente (upload em voo)', false, in_array( $orphan_new, $dry['orphans'], true ) );
assert_fiscal_db( 'dry-run poupa arquivo referenciado', false, in_array( $referenced_key, $dry['orphans'], true ) );
assert_fiscal_db( 'dry-run nao apaga nada', 0, (int) $dry['removed'] );
assert_fiscal_db( 'dry-run nao tocou o arquivo', true, fiscal_db_file_exists( $orphan_old ) );

$swept = papelito_fiscal_documents_sweep( false );
assert_fiscal_db( 'varredura apaga o orfao antigo', false, fiscal_db_file_exists( $orphan_old ) );
assert_fiscal_db( 'varredura preserva o orfao recente', true, fiscal_db_file_exists( $orphan_new ) );
assert_fiscal_db( 'varredura preserva o arquivo referenciado', true, fiscal_db_file_exists( $referenced_key ) );
assert_fiscal_db( 'varredura contabiliza o que removeu', true, (int) $swept['removed'] >= 1 );

// Arquivo fora do padrão de storage key é reportado, nunca apagado.
$stranger = trailingslashit( $directory ) . 'arquivo-estranho.pdf';
file_put_contents( $stranger, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
touch( $stranger, time() - PAPELITO_FISCAL_SWEEP_MIN_AGE - 60 );
$swept = papelito_fiscal_documents_sweep( false );
assert_fiscal_db( 'arquivo fora do padrao e reportado', true, in_array( 'arquivo-estranho.pdf', $swept['skipped'], true ) );
assert_fiscal_db( 'arquivo fora do padrao NAO e apagado', true, is_file( $stranger ) );
unlink( $stranger );
papelito_fiscal_document_purge_file( $orphan_new );

fiscal_db_cleanup();

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) failed\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
