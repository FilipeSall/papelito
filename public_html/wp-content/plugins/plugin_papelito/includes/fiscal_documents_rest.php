<?php
/**
 * Superfície REST da nota fiscal do pedido.
 *
 * A Papelito não emite nota: o vendor emite por fora e anexa o arquivo aqui.
 * Esta camada autoriza pelo pedido do vendor, guarda o arquivo e devolve o que
 * há. **Não existe dado digitado**: não há chave de acesso, número, série,
 * emissão nem valor — o sistema não lê o conteúdo do documento e não afirma
 * nada sobre ele.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_FISCAL_HEADER_CONTENT_LENGTH' ) ) {
	define( 'PAPELITO_FISCAL_HEADER_CONTENT_LENGTH', 'Content-Length: ' );
}

if ( ! defined( 'PAPELITO_FISCAL_HEADER_CONTENT_DISPOSITION' ) ) {
	define( 'PAPELITO_FISCAL_HEADER_CONTENT_DISPOSITION', 'Content-Disposition: ' );
}

if ( ! defined( 'PAPELITO_FISCAL_HEADER_FILENAME_PREFIX' ) ) {
	define( 'PAPELITO_FISCAL_HEADER_FILENAME_PREFIX', '; filename="' );
}

if ( ! defined( 'PAPELITO_FISCAL_HEADER_CONTENT_TYPE_OPTIONS' ) ) {
	define( 'PAPELITO_FISCAL_HEADER_CONTENT_TYPE_OPTIONS', 'X-Content-Type-Options: nosniff' );
}

/**
 * Interruptor da superfície. Desligar esconde a funcionalidade sem apagar dado:
 * as tabelas e os arquivos continuam onde estão.
 */
function papelito_fiscal_documents_enabled(): bool {
	if ( ! function_exists( 'papelito_env_bool' ) ) {
		return true;
	}

	return papelito_env_bool( 'PAPELITO_FISCAL_DOCUMENTS_ENABLED', true );
}

/**
 * Recusa a operação quando a superfície está desligada.
 *
 * @return true|WP_Error
 */
function papelito_fiscal_documents_require_enabled() {
	if ( papelito_fiscal_documents_enabled() ) {
		return true;
	}

	return new WP_Error(
		'papelito_fiscal_documents_disabled',
		'O anexo de nota fiscal está temporariamente indisponível.',
		array( 'status' => 503 )
	);
}

/**
 * Motivo pelo qual o pedido ainda não aceita nota, ou string vazia quando aceita.
 *
 * Antes do pagamento não existe nota a emitir, e pedido cancelado não recebe
 * documento novo. As duas regras saem do estado operacional já calculado por
 * `papelito_vendor_dashboard_order_status()`, não de um critério próprio.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_fiscal_order_block_reason( $order ): string {
	$status = papelito_vendor_dashboard_order_status( $order );

	if ( PAPELITO_VENDOR_STATUS_CANCELLED === $status ) {
		return 'cancelado';
	}

	if ( ! papelito_vendor_dashboard_order_is_paid( $order ) ) {
		return 'aguardando_pagamento';
	}

	return '';
}

/**
 * Apaga um arquivo do diretório privado pela storage key.
 *
 * A key é validada antes de virar caminho: sem isso, um valor corrompido no
 * banco viraria travessia de diretório na hora de apagar.
 */
function papelito_fiscal_document_purge_file( string $key ): void {
	if ( '' === $key || ! papelito_fiscal_document_key_is_valid( $key ) ) {
		return;
	}

	papelito_private_file_discard_path( trailingslashit( papelito_fiscal_documents_dir() ) . $key );
}

/**
 * Grava a linha da nota e o evento numa transação só.
 *
 * Devolve a storage key **anterior**, que o chamador apaga depois do commit.
 *
 * @param array<string,mixed> $validated Metadados validados do upload.
 * @param array{key:string,path:string} $stored Arquivo já gravado em disco.
 * @return array{previous_key:string,event:string}|WP_Error
 */
function papelito_fiscal_document_commit( int $order_id, int $vendor_id, array $validated, array $stored, int $actor_id ) {
	global $wpdb;

	$tables       = papelito_fiscal_table_names();
	$now          = current_time( 'mysql', true );
	$current      = papelito_fiscal_document_current( $order_id, $vendor_id );
	$previous_key = $current ? (string) $current['storage_key'] : '';
	$event        = $current ? 'substituida' : 'anexada';

	$columns = array(
		'storage_key'   => (string) $stored['key'],
		'original_name' => (string) $validated['original_name'],
		'mime'          => (string) $validated['mime'],
		'size_bytes'    => (int) $validated['size'],
		'sha256'        => (string) $validated['sha256'],
		'uploaded_by'   => max( 0, $actor_id ),
		'updated_at'    => $now,
	);

	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( $current ) {
		$written = $wpdb->update(
			$tables['documents'],
			$columns,
			array( 'id' => (int) $current['id'] ),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} else {
		$columns['order_id']   = $order_id;
		$columns['vendor_id']  = $vendor_id;
		$columns['created_at'] = $now;

		$written = $wpdb->insert( $tables['documents'], $columns ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	if ( false === $written ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return new WP_Error( 'papelito_fiscal_document_save_failed', 'Não foi possível registrar a nota fiscal.', array( 'status' => 500 ) );
	}

	if ( ! papelito_fiscal_document_log_event( $order_id, $vendor_id, $event, (string) $validated['original_name'], $actor_id ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return new WP_Error( 'papelito_fiscal_document_save_failed', 'Não foi possível registrar a nota fiscal.', array( 'status' => 500 ) );
	}

	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return array(
		'previous_key' => $previous_key,
		'event'        => $event,
	);
}

/**
 * Recebe o arquivo da nota, substituindo o anterior quando já existe um.
 *
 * A ordem das etapas é o que impede órfão dos dois lados:
 *
 * 1. grava o arquivo **novo** em disco;
 * 2. transaciona a linha e o evento;
 * 3. em erro, apaga o arquivo novo — a nota anterior fica intacta;
 * 4. em sucesso, e **só depois do commit**, apaga o arquivo antigo.
 *
 * Apagar o antigo antes de commitar perderia a nota válida num rollback. Já
 * falhar no passo 4 deixa apenas um arquivo solto, recuperável pela varredura
 * `papelito_fiscal_documents_sweep()`.
 *
 * @param object              $order Pedido WooCommerce.
 * @param array<string,mixed> $file  Entrada de `$_FILES`.
 * @return true|WP_Error
 */
function papelito_fiscal_document_attach_file( $order, int $vendor_id, array $file, int $actor_id ) {
	$directory = papelito_fiscal_documents_prepare_dir();

	if ( is_wp_error( $directory ) ) {
		return $directory;
	}

	$validated = papelito_fiscal_document_validate_upload( $file );

	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	$stored = papelito_fiscal_document_store( $file, $validated, $directory );

	if ( is_wp_error( $stored ) ) {
		return $stored;
	}

	$result = papelito_fiscal_document_commit( (int) $order->get_id(), $vendor_id, $validated, $stored, $actor_id );

	if ( is_wp_error( $result ) ) {
		papelito_fiscal_document_discard_path( (string) $stored['path'] );

		return $result;
	}

	papelito_fiscal_document_purge_file( (string) $result['previous_key'] );

	return true;
}

/**
 * Apaga a nota: linha, arquivo e um evento na trilha.
 *
 * A trilha **sobrevive** à remoção — ela é chaveada por `(order_id, vendor_id)`
 * justamente para responder "por que a nota que eu vi ontem sumiu?".
 *
 * @param array<string,mixed> $document Linha do documento.
 * @return true|WP_Error
 */
function papelito_fiscal_document_remove_row( array $document, int $actor_id ) {
	global $wpdb;

	$tables    = papelito_fiscal_table_names();
	$order_id  = (int) $document['order_id'];
	$vendor_id = (int) $document['vendor_id'];

	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$deleted = $wpdb->delete( $tables['documents'], array( 'id' => (int) $document['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $deleted ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return new WP_Error( 'papelito_fiscal_document_delete_failed', 'Não foi possível remover a nota fiscal.', array( 'status' => 500 ) );
	}

	if ( ! papelito_fiscal_document_log_event( $order_id, $vendor_id, 'removida', (string) $document['original_name'], $actor_id ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return new WP_Error( 'papelito_fiscal_document_delete_failed', 'Não foi possível remover a nota fiscal.', array( 'status' => 500 ) );
	}

	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	papelito_fiscal_document_purge_file( (string) $document['storage_key'] );

	return true;
}

/**
 * @return true|WP_Error
 */
function papelito_fiscal_document_remove( int $order_id, int $vendor_id, int $actor_id ) {
	$document = papelito_fiscal_document_current( $order_id, $vendor_id );

	if ( ! $document ) {
		return new WP_Error( 'papelito_fiscal_document_not_found', 'Nota fiscal não encontrada.', array( 'status' => 404 ) );
	}

	return papelito_fiscal_document_remove_row( $document, $actor_id );
}

/**
 * Arquivo da nota validado para leitura em disco.
 *
 * @param array<string,mixed> $document Linha do documento.
 * @return array<string,mixed>|WP_Error
 */
function papelito_fiscal_document_readable_file( array $document ) {
	$key = (string) ( $document['storage_key'] ?? '' );

	if ( ! papelito_fiscal_document_key_is_valid( $key ) ) {
		return new WP_Error( 'papelito_fiscal_document_file_invalid', 'Arquivo inválido.', array( 'status' => 500 ) );
	}

	$directory = papelito_fiscal_documents_prepare_dir();

	if ( is_wp_error( $directory ) ) {
		return $directory;
	}

	$path = trailingslashit( $directory ) . $key;

	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return new WP_Error( 'papelito_fiscal_document_file_missing', 'Arquivo não encontrado.', array( 'status' => 410 ) );
	}

	return array_merge( $document, array( 'path' => $path ) );
}

/**
 * @param array<string,mixed>|null $document Linha do documento.
 * @return array<string,mixed>|null
 */
function papelito_fiscal_document_payload( ?array $document ): ?array {
	if ( ! $document || empty( $document['id'] ) ) {
		return null;
	}

	return array(
		'id'            => (int) $document['id'],
		'original_name' => (string) $document['original_name'],
		'mime'          => (string) $document['mime'],
		'size_bytes'    => (int) $document['size_bytes'],
		'created_at'    => (string) $document['created_at'],
		'updated_at'    => (string) $document['updated_at'],
	);
}

/**
 * Trilha em formato de payload.
 *
 * `actor_role` é derivado, e não guardado: quem age é sempre o vendor dono do
 * pedido, e ator zero é o sistema — remoção disparada por exclusão do pedido.
 *
 * @param array<int,array<string,mixed>> $rows Linhas de evento.
 * @return array<int,array<string,mixed>>
 */
function papelito_fiscal_event_payload( array $rows ): array {
	$events = array();

	foreach ( $rows as $row ) {
		$actor = (int) ( $row['actor_user_id'] ?? 0 );

		if ( $actor <= 0 ) {
			$actor_role = 'sistema';
		} elseif ( $actor === (int) ( $row['vendor_id'] ?? 0 ) ) {
			$actor_role = 'vendor';
		} else {
			$actor_role = 'admin';
		}

		$events[] = array(
			'id'            => (int) $row['id'],
			'event'         => (string) $row['event'],
			'original_name' => (string) $row['original_name'],
			'actor_role'    => $actor_role,
			'created_at'    => (string) $row['created_at'],
		);
	}

	return $events;
}

/**
 * Bloco de nota fiscal do pedido, na visão do vendor.
 *
 * @param object $order Pedido WooCommerce.
 * @return array<string,mixed>
 */
function papelito_fiscal_order_block( $order, int $vendor_id ): array {
	$enabled      = papelito_fiscal_documents_enabled();
	$block_reason = papelito_fiscal_order_block_reason( $order );
	$order_id     = (int) $order->get_id();

	return array(
		'enabled'      => $enabled,
		'can_attach'   => $enabled && '' === $block_reason,
		'block_reason' => $block_reason,
		'limits'       => papelito_fiscal_document_limits(),
		'document'     => $enabled ? papelito_fiscal_document_payload( papelito_fiscal_document_current( $order_id, $vendor_id ) ) : null,
		'events'       => $enabled ? papelito_fiscal_event_payload( papelito_fiscal_document_history( $order_id, $vendor_id ) ) : array(),
	);
}

/**
 * Nota fiscal na visão do comprador: existe e dá para baixar, mais nada.
 *
 * `null` quando não há nota — e a tela do comprador **não** anuncia essa
 * ausência: quem emite é o vendor, e a pendência não é do comprador.
 *
 * @param object $order Pedido WooCommerce.
 * @return array<string,mixed>|null
 */
function papelito_fiscal_customer_summary( $order ): ?array {
	if ( ! papelito_fiscal_documents_enabled() ) {
		return null;
	}

	$vendor_id = absint( $order->get_meta( '_papelito_vendor_id', true ) );

	if ( $vendor_id <= 0 ) {
		return null;
	}

	$document = papelito_fiscal_document_current( (int) $order->get_id(), $vendor_id );

	if ( ! $document ) {
		return null;
	}

	return array(
		'original_name' => (string) $document['original_name'],
		'mime'          => (string) $document['mime'],
		'size_bytes'    => (int) $document['size_bytes'],
		'created_at'    => (string) $document['created_at'],
	);
}

/**
 * @param object $order Pedido WooCommerce.
 * @return array<string,mixed>|WP_Error
 */
function papelito_fiscal_customer_file( $order ) {
	$enabled = papelito_fiscal_documents_require_enabled();

	if ( is_wp_error( $enabled ) ) {
		return $enabled;
	}

	$vendor_id = absint( $order->get_meta( '_papelito_vendor_id', true ) );
	$document  = $vendor_id > 0 ? papelito_fiscal_document_current( (int) $order->get_id(), $vendor_id ) : null;

	if ( ! $document ) {
		return new WP_Error( 'papelito_fiscal_document_not_found', 'Nota fiscal não encontrada.', array( 'status' => 404 ) );
	}

	return papelito_fiscal_document_readable_file( $document );
}

/**
 * Pedidos do vendor que já têm nota, em uma consulta só.
 *
 * Sem lista de ids: a listagem precisa do conjunto inteiro para contar a fila
 * de nota pendente, e passar um placeholder por pedido fazia `prepare()` montar
 * uma query proporcional ao histórico do vendor a cada carregamento.
 *
 * @return array<int,int> Ids indexados por si mesmos.
 */
function papelito_fiscal_documented_order_ids_for_vendor( int $vendor_id ): array {
	global $wpdb;

	if ( $vendor_id <= 0 || ! papelito_fiscal_documents_enabled() ) {
		return array();
	}

	$tables = papelito_fiscal_table_names();
	$rows   = $wpdb->get_col(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT order_id FROM {$tables['documents']} WHERE vendor_id = %d", $vendor_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$ids = array();
	foreach ( (array) $rows as $id ) {
		$ids[ (int) $id ] = (int) $id;
	}

	return $ids;
}

/**
 * Pedido do vendor autenticado, com a superfície de nota habilitada.
 *
 * @return object|WP_Error
 */
function papelito_fiscal_rest_vendor_order( WP_REST_Request $request ) {
	$enabled = papelito_fiscal_documents_require_enabled();

	if ( is_wp_error( $enabled ) ) {
		return $enabled;
	}

	return papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'id' ) ), get_current_user_id() );
}

function papelito_fiscal_rest_handle_get( WP_REST_Request $request ) {
	$order = papelito_fiscal_rest_vendor_order( $request );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	return new WP_REST_Response( papelito_fiscal_order_block( $order, get_current_user_id() ), 200 );
}

/**
 * REST callback: remove a nota do pedido.
 *
 * Some a linha e some o arquivo. A trilha guarda o registro da remoção — é o
 * único rastro que sobra de que existiu uma nota ali.
 */
function papelito_fiscal_rest_handle_delete( WP_REST_Request $request ) {
	$order = papelito_fiscal_rest_vendor_order( $request );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$vendor_id = get_current_user_id();
	$removed   = papelito_fiscal_document_remove( (int) $order->get_id(), $vendor_id, $vendor_id );

	if ( is_wp_error( $removed ) ) {
		return $removed;
	}

	return new WP_REST_Response( papelito_fiscal_order_block( $order, $vendor_id ), 200 );
}

/**
 * Faz streaming do arquivo da nota.
 *
 * XML sai como anexo e PDF inline: o navegador exibe o PDF, e nunca renderiza
 * o XML como documento.
 *
 * @param array<string,mixed> $file Arquivo já validado para leitura.
 */
function papelito_fiscal_stream_file( array $file ): void {
	$disposition = false !== strpos( (string) $file['mime'], 'xml' ) ? 'attachment' : 'inline';

	nocache_headers();
	header( 'Content-Type: ' . (string) $file['mime'] );
	header( PAPELITO_FISCAL_HEADER_CONTENT_LENGTH . (string) filesize( (string) $file['path'] ) );
	header( PAPELITO_FISCAL_HEADER_CONTENT_DISPOSITION . $disposition . PAPELITO_FISCAL_HEADER_FILENAME_PREFIX . str_replace( array( '"', "\r", "\n" ), '', (string) $file['original_name'] ) . '"' );
	header( PAPELITO_FISCAL_HEADER_CONTENT_TYPE_OPTIONS );
	readfile( (string) $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

function papelito_fiscal_rest_handle_file( WP_REST_Request $request ) {
	$order = papelito_fiscal_rest_vendor_order( $request );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$document = papelito_fiscal_document_current( (int) $order->get_id(), get_current_user_id() );

	if ( ! $document ) {
		return new WP_Error( 'papelito_fiscal_document_not_found', 'Nota fiscal não encontrada.', array( 'status' => 404 ) );
	}

	$file = papelito_fiscal_document_readable_file( $document );

	if ( is_wp_error( $file ) ) {
		return $file;
	}

	papelito_fiscal_stream_file( $file );
}

/**
 * REST callback: arquivo da nota para o comprador do pedido.
 *
 * Autorização é a do comprador — `papelito_receipt_order_for_current_user()`
 * já resolve "este pedido é seu". O comprador só lê; anexar, substituir e
 * remover são do vendor.
 */
function papelito_fiscal_customer_handle_file( WP_REST_Request $request ) {
	$order = papelito_receipt_order_for_current_user( absint( $request->get_param( 'id' ) ) );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$file = papelito_fiscal_customer_file( $order );

	if ( is_wp_error( $file ) ) {
		return $file;
	}

	papelito_fiscal_stream_file( $file );
}

/**
 * REST callback: recibo do pagamento, anexado ao pedido do vendor.
 *
 * É o mesmo recibo do comprador, gerado pelo mesmo snapshot — não uma segunda
 * versão do documento. O vendor vê o dele porque é o pedido da loja dele; a
 * autorização é a mesma do detalhe, e não a do comprador.
 */
function papelito_vendor_rest_handle_receipt( WP_REST_Request $request ) {
	$order = papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'id' ) ), get_current_user_id() );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$pdf = papelito_receipt_pdf( $order );

	if ( is_wp_error( $pdf ) ) {
		return $pdf;
	}

	$disposition = '1' === (string) $request->get_param( 'download' ) ? 'attachment' : 'inline';

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( PAPELITO_FISCAL_HEADER_CONTENT_LENGTH . strlen( $pdf ) );
	header( PAPELITO_FISCAL_HEADER_CONTENT_DISPOSITION . $disposition . PAPELITO_FISCAL_HEADER_FILENAME_PREFIX . papelito_receipt_filename( $order ) . '"' );
	header( PAPELITO_FISCAL_HEADER_CONTENT_TYPE_OPTIONS );
	echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- corpo binario de PDF.
	exit;
}

/**
 * Registra as rotas de nota fiscal do vendor.
 */
function papelito_fiscal_documents_register_routes(): void {
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/fiscal-document',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
				'callback'            => 'papelito_fiscal_rest_handle_get',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
				'callback'            => 'papelito_fiscal_rest_handle_delete',
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/fiscal-document/file',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
			'callback'            => 'papelito_fiscal_rest_handle_file',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/profile/me/orders/(?P<id>\d+)/fiscal-document/file',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_dashboard_permission_profile_user',
			'callback'            => 'papelito_fiscal_customer_handle_file',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/receipt',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
			'callback'            => 'papelito_vendor_rest_handle_receipt',
		)
	);
}
add_action( 'rest_api_init', 'papelito_fiscal_documents_register_routes' );
