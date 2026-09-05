<?php
/**
 * Limpeza de notas fiscais: exclusão em cascata e varredura de órfãos.
 *
 * Órfão nasce de dois lados. Linha sem arquivo acontece quando o pedido ou o
 * vendor somem e ninguém avisa a nota — é o que os ganchos abaixo resolvem.
 * Arquivo sem linha acontece quando o `unlink` posterior ao commit falha, e é
 * o que a varredura recolhe.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove as notas de um pedido apagado em definitivo.
 *
 * Ator zero: quem removeu foi o sistema, não o vendor. A trilha permanece.
 */
function papelito_fiscal_documents_delete_for_order( int $order_id ): void {
	global $wpdb;

	if ( $order_id <= 0 ) {
		return;
	}

	$tables = papelito_fiscal_table_names();
	$rows   = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['documents']} WHERE order_id = %d", $order_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	foreach ( (array) $rows as $row ) {
		papelito_fiscal_document_remove_row( (array) $row, 0 );
	}
}

/**
 * Mesma limpeza, pelo gancho genérico de post.
 *
 * Só reage a `shop_order`: `before_delete_post` dispara para todo tipo de post,
 * e um id de produto colidiria com um id de pedido.
 */
function papelito_fiscal_documents_delete_for_order_post( int $post_id ): void {
	if ( 'shop_order' !== get_post_type( $post_id ) ) {
		return;
	}

	papelito_fiscal_documents_delete_for_order( $post_id );
}

/**
 * Remove as notas de um vendor apagado.
 */
function papelito_fiscal_documents_delete_for_vendor( int $vendor_id ): void {
	global $wpdb;

	if ( $vendor_id <= 0 ) {
		return;
	}

	$tables = papelito_fiscal_table_names();
	$rows   = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['documents']} WHERE vendor_id = %d", $vendor_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	foreach ( (array) $rows as $row ) {
		papelito_fiscal_document_remove_row( (array) $row, 0 );
	}
}

add_action( 'woocommerce_before_delete_order', 'papelito_fiscal_documents_delete_for_order' );
add_action( 'before_delete_post', 'papelito_fiscal_documents_delete_for_order_post' );
add_action( 'deleted_user', 'papelito_fiscal_documents_delete_for_vendor' );

/**
 * Recolhe arquivos do diretório privado que nenhuma linha referencia.
 *
 * Duas guardas deliberadas:
 *
 * - **idade mínima** (`PAPELITO_FISCAL_SWEEP_MIN_AGE`): o arquivo é gravado em
 *   disco antes da transação que o referencia, então um arquivo recém-criado
 *   pode ser um upload legítimo ainda em voo, não um órfão;
 * - **formato da key**: só remove o que tem a cara de uma storage key nossa.
 *   Arquivo estranho no diretório é reportado, nunca apagado.
 *
 * @return array{scanned:int,orphans:array<int,string>,skipped:array<int,string>,removed:int}
 */
function papelito_fiscal_documents_sweep( bool $dry_run = true ): array {
	global $wpdb;

	$result = array(
		'scanned' => 0,
		'orphans' => array(),
		'skipped' => array(),
		'removed' => 0,
	);

	$directory = papelito_fiscal_documents_dir();

	if ( ! is_dir( $directory ) ) {
		return $result;
	}

	$tables = papelito_fiscal_table_names();
	$keys   = $wpdb->get_col( "SELECT storage_key FROM {$tables['documents']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$known  = array_flip( array_map( 'strval', (array) $keys ) );

	$threshold = time() - PAPELITO_FISCAL_SWEEP_MIN_AGE;
	$entries   = scandir( $directory );

	foreach ( (array) $entries as $entry ) {
		$entry = (string) $entry;

		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = trailingslashit( $directory ) . $entry;

		if ( ! is_file( $path ) ) {
			continue;
		}

		++$result['scanned'];

		if ( isset( $known[ $entry ] ) ) {
			continue;
		}

		if ( ! papelito_fiscal_document_key_is_valid( $entry ) ) {
			$result['skipped'][] = $entry;
			continue;
		}

		$modified = filemtime( $path );

		if ( false === $modified || $modified > $threshold ) {
			continue;
		}

		$result['orphans'][] = $entry;

		if ( ! $dry_run ) {
			papelito_private_file_discard_path( $path );
			++$result['removed'];
		}
	}

	return $result;
}

/**
 * `wp papelito fiscal sweep [--dry-run]`
 *
 * Sem cron: deleção irreversível num diretório de documentos fiscais precisa
 * de alguém apertando o botão.
 *
 * @param array<int,string>    $args       Argumentos posicionais.
 * @param array<string,string> $assoc_args Flags.
 */
function papelito_fiscal_documents_sweep_command( array $args, array $assoc_args ): void {
	unset( $args );

	$dry_run = ! empty( $assoc_args['dry-run'] );
	$result  = papelito_fiscal_documents_sweep( $dry_run );

	WP_CLI::log( sprintf( 'Arquivos no diretorio: %d', (int) $result['scanned'] ) );

	foreach ( $result['skipped'] as $entry ) {
		WP_CLI::warning( sprintf( 'Ignorado (fora do padrao de storage key): %s', $entry ) );
	}

	foreach ( $result['orphans'] as $entry ) {
		WP_CLI::log( sprintf( '%s %s', $dry_run ? 'Orfao:' : 'Removido:', $entry ) );
	}

	if ( $dry_run ) {
		WP_CLI::success( sprintf( '%d orfao(s) encontrado(s). Nada foi apagado (--dry-run).', count( $result['orphans'] ) ) );

		return;
	}

	WP_CLI::success( sprintf( '%d arquivo(s) removido(s).', (int) $result['removed'] ) );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'papelito fiscal sweep', 'papelito_fiscal_documents_sweep_command' );
}
