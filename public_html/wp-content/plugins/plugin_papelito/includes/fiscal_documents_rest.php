<?php
/**
 * Superfície REST dos documentos fiscais do vendor.
 *
 * A Papelito não emite nota: o vendor emite por fora e anexa aqui. Esta camada
 * autoriza pelo pedido do vendor, persiste em cima da fundação de
 * `fiscal_documents.php` e devolve o documento corrente. Nada aqui bloqueia
 * pagamento, fulfillment, postagem ou entrega — divergência é sinalização.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_FISCAL_NOTES_MAX_LENGTH' ) ) {
	define( 'PAPELITO_FISCAL_NOTES_MAX_LENGTH', 500 );
}

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
 * as tabelas, os arquivos e os eventos continuam onde estão.
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
 * Dados do pedido usados nos cruzamentos informativos da validação local.
 *
 * @param object $order Pedido WooCommerce.
 * @return array{vendor_cnpj:string,part_total_cents:int}
 */
function papelito_fiscal_order_context( $order, int $vendor_id ): array {
	$vendor_cnpj = papelito_fiscal_key_normalize( (string) get_user_meta( $vendor_id, 'cnpj', true ) );
	$part_cents  = 0;

	if ( function_exists( 'papelito_receipt_get_by_order' ) && function_exists( 'papelito_receipt_vendor_parts' ) ) {
		$receipt = papelito_receipt_get_by_order( (int) $order->get_id() );

		if ( is_array( $receipt ) && ! empty( $receipt['id'] ) ) {
			foreach ( papelito_receipt_vendor_parts( (int) $receipt['id'] ) as $part ) {
				if ( (int) ( $part['vendor_id'] ?? 0 ) === $vendor_id ) {
					$part_cents = (int) ( $part['total_cents'] ?? 0 );
					break;
				}
			}
		}
	}

	return array(
		'vendor_cnpj'      => $vendor_cnpj,
		'part_total_cents' => $part_cents,
	);
}

/**
 * Normaliza os campos digitados pelo vendor.
 *
 * O que não vier no payload fica ausente do array — quem mescla com o já
 * gravado é `papelito_fiscal_document_declared_from_row()`, e um campo ausente
 * não pode apagar o que o vendor já tinha informado.
 *
 * @param array<string,mixed> $input Payload cru.
 * @return array<string,mixed>
 */
function papelito_fiscal_declared_from_input( array $input ): array {
	$declared = array();

	// Tipo vazio não é "apagar o tipo": `papelito_fiscal_document_build()` cairia
	// em `other` porque '' não está na lista de tipos. Ausente preserva o que há.
	$doc_type = sanitize_key( (string) ( $input['docType'] ?? $input['doc_type'] ?? '' ) );

	if ( '' !== $doc_type ) {
		$declared['doc_type'] = $doc_type;
	}

	$text_fields = array(
		'accessKey'  => 'access_key',
		'docNumber'  => 'doc_number',
		'docSeries'  => 'doc_series',
		'protocol'   => 'protocol',
		'issuerCnpj' => 'issuer_cnpj',
		'issuerName' => 'issuer_name',
		'issuedAt'   => 'issued_at',
	);

	foreach ( $text_fields as $camel => $snake ) {
		if ( ! isset( $input[ $camel ] ) && ! isset( $input[ $snake ] ) ) {
			continue;
		}

		$declared[ $snake ] = sanitize_text_field( (string) ( $input[ $camel ] ?? $input[ $snake ] ) );
	}

	foreach ( array( 'access_key', 'issuer_cnpj' ) as $digits_only ) {
		if ( isset( $declared[ $digits_only ] ) ) {
			$declared[ $digits_only ] = substr( papelito_fiscal_key_normalize( $declared[ $digits_only ] ), 0, 44 );
		}
	}

	if ( isset( $input['totalCents'] ) || isset( $input['total_cents'] ) ) {
		$declared['total_cents'] = max( 0, (int) ( $input['totalCents'] ?? $input['total_cents'] ) );
	}

	if ( isset( $input['notes'] ) ) {
		$declared['notes'] = substr( sanitize_textarea_field( (string) $input['notes'] ), 0, PAPELITO_FISCAL_NOTES_MAX_LENGTH );
	}

	return $declared;
}

/**
 * Campos digitados que já estão gravados no documento corrente.
 *
 * Campo vazio é **omitido**, não devolvido como string vazia: a fundação
 * preenche o que está faltando com o valor do XML usando `??`, que não cai
 * para o fallback diante de `''`. Devolver vazio faria o emitente lido do XML
 * nunca chegar ao documento — e isso não é "não sobrescrever o digitado", é
 * perder um dado que ninguém digitou.
 *
 * @param array<string,mixed> $row Linha da tabela de documentos.
 * @return array<string,mixed>
 */
function papelito_fiscal_document_declared_from_row( array $row ): array {
	$declared = array( 'doc_type' => (string) ( $row['doc_type'] ?? 'nfe' ) );

	$fields = array(
		'access_key'  => 'access_key',
		'doc_number'  => 'doc_number',
		'doc_series'  => 'doc_series',
		'protocol'    => 'protocol',
		'issuer_cnpj' => 'issuer_cnpj',
		'issuer_name' => 'issuer_name',
		'issued_at'   => 'issued_at',
		'notes'       => 'internal_notes',
	);

	foreach ( $fields as $key => $column ) {
		$value = trim( (string) ( $row[ $column ] ?? '' ) );

		if ( '' !== $value ) {
			$declared[ $key ] = $value;
		}
	}

	$total_cents = (int) ( $row['total_cents'] ?? 0 );

	if ( $total_cents > 0 ) {
		$declared['total_cents'] = $total_cents;
	}

	return $declared;
}

/**
 * Conteúdo do XML ativo de um documento, ou string vazia.
 */
function papelito_fiscal_document_xml_contents( int $document_id ): string {
	foreach ( papelito_fiscal_document_files( $document_id ) as $file ) {
		if ( 'xml' !== (string) ( $file['role'] ?? '' ) ) {
			continue;
		}

		$key = (string) ( $file['storage_key'] ?? '' );
		if ( ! papelito_fiscal_document_key_is_valid( $key ) ) {
			return '';
		}

		$directory = papelito_fiscal_documents_prepare_dir();
		if ( is_wp_error( $directory ) ) {
			return '';
		}

		$path = trailingslashit( $directory ) . $key;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- leitura de arquivo privado local.

		return is_string( $contents ) ? $contents : '';
	}

	return '';
}

/**
 * Dados extraídos do XML já anexado, ou array vazio quando não há XML legível.
 *
 * @return array<string,string>
 */
function papelito_fiscal_extract_from_xml( string $contents ): array {
	if ( '' === $contents ) {
		return array();
	}

	$xml = papelito_fiscal_xml_parse( $contents );

	return $xml instanceof SimpleXMLElement ? papelito_fiscal_xml_extract( $xml ) : array();
}

/**
 * Colunas gravadas a partir do resultado de `papelito_fiscal_document_build()`.
 *
 * @param array<string,mixed> $built Documento montado pela fundação.
 * @return array<string,mixed>
 */
function papelito_fiscal_document_columns( array $built, string $notes ): array {
	return array(
		'doc_type'          => (string) $built['doc_type'],
		'doc_status'        => (string) $built['doc_status'],
		'validation_level'  => (int) $built['validation_level'],
		'access_key'        => '' === $built['access_key'] ? null : (string) $built['access_key'],
		'access_key_status' => (string) $built['access_key_status'],
		'doc_number'        => substr( (string) $built['doc_number'], 0, 20 ),
		'doc_series'        => substr( (string) $built['doc_series'], 0, 10 ),
		'protocol'          => substr( (string) $built['protocol'], 0, 40 ),
		'issuer_cnpj'       => '' === $built['issuer_cnpj'] ? null : substr( (string) $built['issuer_cnpj'], 0, 14 ),
		'issuer_name'       => substr( (string) $built['issuer_name'], 0, 255 ),
		'issued_at'         => '' === $built['issued_at'] ? null : (string) $built['issued_at'],
		'total_cents'       => (int) $built['total_cents'],
		'internal_notes'    => $notes,
		'flags_json'        => wp_json_encode( array_values( (array) $built['flags'] ) ),
		'snapshot_version'  => PAPELITO_FISCAL_SNAPSHOT_VERSION,
	);
}

/**
 * Insere o documento corrente do vendor no pedido.
 *
 * @param array<string,mixed> $built Documento montado pela fundação.
 * @return int|WP_Error Id do documento criado.
 */
function papelito_fiscal_document_insert( array $built, string $notes, int $actor_id ) {
	global $wpdb;

	$tables = papelito_fiscal_table_names();
	$now    = current_time( 'mysql', true );

	$inserted = $wpdb->insert(
		$tables['documents'],
		array_merge(
			papelito_fiscal_document_columns( $built, $notes ),
			array(
				'order_id'   => (int) $built['order_id'],
				'vendor_id'  => (int) $built['vendor_id'],
				'is_current' => 1,
				'created_by' => $actor_id,
				'updated_by' => $actor_id,
				'created_at' => $now,
				'updated_at' => $now,
			)
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $inserted ) {
		return new WP_Error( 'papelito_fiscal_document_store_failed', 'Não foi possível registrar a nota fiscal.', array( 'status' => 500 ) );
	}

	return (int) $wpdb->insert_id;
}

/**
 * Atualiza o documento corrente com os dados recalculados.
 *
 * @param array<string,mixed> $built Documento montado pela fundação.
 * @return true|WP_Error
 */
function papelito_fiscal_document_update( int $document_id, array $built, string $notes, int $actor_id ) {
	global $wpdb;

	$tables  = papelito_fiscal_table_names();
	$updated = $wpdb->update(
		$tables['documents'],
		array_merge(
			papelito_fiscal_document_columns( $built, $notes ),
			array(
				'updated_by' => $actor_id,
				'updated_at' => current_time( 'mysql', true ),
			)
		),
		array( 'id' => $document_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $updated ) {
		return new WP_Error( 'papelito_fiscal_document_store_failed', 'Não foi possível atualizar a nota fiscal.', array( 'status' => 500 ) );
	}

	return true;
}

/**
 * Remove logicamente e do disco os arquivos de um documento.
 *
 * A nota substituída deixa de existir: o pedido tem no máximo uma nota, e
 * guardar o arquivo anterior seria manter uma versão que ninguém pode alcançar.
 * A exclusão em disco acontece **depois** do commit — falha ali não desfaz a
 * troca, só deixa um arquivo sem referência, que é o erro menos grave.
 *
 * @param array<int,string> $keys Storage keys já lidas antes do delete.
 */
function papelito_fiscal_document_purge_files( array $keys ): void {
	$directory = papelito_fiscal_documents_prepare_dir();

	if ( is_wp_error( $directory ) ) {
		return;
	}

	foreach ( $keys as $key ) {
		if ( papelito_fiscal_document_key_is_valid( (string) $key ) ) {
			papelito_fiscal_document_discard_path( trailingslashit( $directory ) . (string) $key );
		}
	}
}

/**
 * Storage keys dos arquivos de um documento, opcionalmente de um papel só.
 *
 * @return array<int,string>
 */
function papelito_fiscal_document_storage_keys( int $document_id, string $role = '' ): array {
	$keys = array();

	foreach ( papelito_fiscal_document_files( $document_id, false ) as $file ) {
		if ( '' !== $role && (string) $file['role'] !== $role ) {
			continue;
		}

		$keys[] = (string) $file['storage_key'];
	}

	return $keys;
}

/**
 * Apaga as linhas de arquivo de um documento, de um papel ou de todos.
 */
function papelito_fiscal_document_delete_file_rows( int $document_id, string $role = '' ): void {
	global $wpdb;

	$tables = papelito_fiscal_table_names();
	$where  = array( 'fiscal_document_id' => $document_id );
	$format = array( '%d' );

	if ( '' !== $role ) {
		$where['role'] = $role;
		$format[]      = '%s';
	}

	$wpdb->delete( $tables['files'], $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Registra o arquivo já gravado em disco.
 *
 * @param array{extension:string,mime:string,size:int,sha256:string,original_name:string} $validated Metadados validados.
 * @return int|WP_Error
 */
function papelito_fiscal_document_register_file( int $document_id, string $role, string $storage_key, array $validated, int $actor_id ) {
	global $wpdb;

	$tables   = papelito_fiscal_table_names();
	$inserted = $wpdb->insert(
		$tables['files'],
		array(
			'fiscal_document_id' => $document_id,
			'role'               => $role,
			'storage_key'        => $storage_key,
			'original_name'      => substr( (string) $validated['original_name'], 0, 191 ),
			'mime'               => substr( (string) $validated['mime'], 0, 64 ),
			'size_bytes'         => (int) $validated['size'],
			'sha256'             => (string) $validated['sha256'],
			'is_active'          => 1,
			'uploaded_by'        => $actor_id,
			'created_at'         => current_time( 'mysql', true ),
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $inserted ) {
		return new WP_Error( 'papelito_fiscal_document_store_failed', 'Não foi possível registrar o arquivo da nota.', array( 'status' => 500 ) );
	}

	return (int) $wpdb->insert_id;
}

/**
 * Recalcula o documento a partir do digitado acumulado e do XML anexado.
 *
 * Roda a cada anexo e a cada edição: acrescentar o XML depois do PDF precisa
 * reabrir os cruzamentos, ou o nível de validação congelaria no que se sabia
 * antes.
 *
 * @param array<string,mixed> $declared Campos digitados já mesclados.
 * @param array<string,mixed> $context  Cruzamentos do pedido.
 * @return array<string,mixed>
 */
function papelito_fiscal_document_rebuild( array $declared, int $order_id, int $vendor_id, string $xml_contents, array $context ): array {
	$declared['order_id']  = $order_id;
	$declared['vendor_id'] = $vendor_id;

	return papelito_fiscal_document_build( $declared, papelito_fiscal_extract_from_xml( $xml_contents ), $context );
}

/**
 * Grava o documento a partir de dados digitados, sem arquivo.
 *
 * @param object              $order    Pedido WooCommerce.
 * @param array<string,mixed> $declared Campos digitados.
 * @return array<string,mixed>|WP_Error Documento corrente serializado.
 */
function papelito_fiscal_document_save_declared( $order, int $vendor_id, array $declared, int $actor_id ) {
	$order_id = (int) $order->get_id();
	$current  = papelito_fiscal_document_current( $order_id, $vendor_id );
	$merged   = $current ? array_merge( papelito_fiscal_document_declared_from_row( $current ), $declared ) : $declared;
	$notes    = substr( (string) ( $merged['notes'] ?? '' ), 0, PAPELITO_FISCAL_NOTES_MAX_LENGTH );
	$xml      = $current ? papelito_fiscal_document_xml_contents( (int) $current['id'] ) : '';
	$built    = papelito_fiscal_document_rebuild( $merged, $order_id, $vendor_id, $xml, papelito_fiscal_order_context( $order, $vendor_id ) );

	if ( ! $current ) {
		$document_id = papelito_fiscal_document_insert( $built, $notes, $actor_id );

		if ( is_wp_error( $document_id ) ) {
			return papelito_fiscal_document_current( $order_id, $vendor_id )
				? new WP_Error(
					'papelito_fiscal_document_conflict',
					'A nota deste pedido acabou de ser registrada. Recarregue a página.',
					array( 'status' => 409 )
				)
				: $document_id;
		}

		papelito_fiscal_document_log_event(
			$document_id,
			'criado',
			array(
				'doc_type'   => $built['doc_type'],
				'doc_status' => $built['doc_status'],
				'access_key' => $built['access_key'],
				'flags'      => $built['flags'],
			),
			$actor_id,
			'vendor'
		);

		return papelito_fiscal_document_payload( papelito_fiscal_document_get( $document_id ) );
	}

	$document_id = (int) $current['id'];
	$updated     = papelito_fiscal_document_update( $document_id, $built, $notes, $actor_id );

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	papelito_fiscal_document_log_event(
		$document_id,
		'atualizado',
		array(
			'previous_status' => (string) $current['doc_status'],
			'doc_status'      => $built['doc_status'],
			'access_key'      => $built['access_key'],
			'flags'           => $built['flags'],
		),
		$actor_id,
		'vendor'
	);

	return papelito_fiscal_document_payload( papelito_fiscal_document_get( $document_id ) );
}

/**
 * Anexa um arquivo validado ao documento do vendor no pedido.
 *
 * `replace` cria uma versão nova e aposenta a anterior; `attach` acrescenta ou
 * troca o arquivo do papel dentro do documento corrente.
 *
 * @param object              $order      Pedido WooCommerce.
 * @param array<string,mixed> $attachment Papel, arquivo, metadados validados, digitados e modo.
 * @return array<string,mixed>|WP_Error
 */
function papelito_fiscal_document_attach_file( $order, int $vendor_id, array $attachment, int $actor_id ) {
	$directory = papelito_fiscal_documents_prepare_dir();

	if ( is_wp_error( $directory ) ) {
		return $directory;
	}

	$stored = papelito_fiscal_document_store( $attachment['file'], $attachment['validated'], $directory );

	if ( is_wp_error( $stored ) ) {
		return $stored;
	}

	$attachment['stored'] = $stored;
	$result               = papelito_fiscal_document_commit_file( $order, $vendor_id, $attachment, $actor_id );

	if ( is_wp_error( $result ) ) {
		papelito_fiscal_document_discard_path( $stored['path'] );
	}

	return $result;
}

/**
 * Conteúdo do arquivo recém-gravado, para o XML entrar no recálculo já no anexo.
 *
 * @param array{key:string,path:string} $stored Arquivo gravado.
 */
function papelito_fiscal_stored_xml_contents( array $stored ): string {
	$contents = file_get_contents( (string) $stored['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- arquivo privado recém-gravado.

	return is_string( $contents ) ? $contents : '';
}

/**
 * Persiste o documento e seu arquivo dentro de uma transação.
 *
 * @param array<string,mixed>|null $current    Documento corrente.
 * @param bool                     $replace     Se a operação substitui o documento.
 * @param string                   $role        Papel do arquivo.
 * @param array<string,mixed>      $built       Documento reconstruído.
 * @param string                   $notes       Observações normalizadas.
 * @param array<string,mixed>      $attachment  Anexo já gravado em disco.
 * @param int                      $actor_id    Usuário que executou a operação.
 * @return array<string,mixed>|WP_Error
 */
function papelito_fiscal_document_persist_commit( ?array $current, bool $replace, string $role, array $built, string $notes, array $attachment, int $actor_id ) {
	global $wpdb;

	$obsolete = array();
	$event    = 'criado';

	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( $current ) {
		$document_id = (int) $current['id'];
		$obsolete    = papelito_fiscal_document_storage_keys( $document_id, $replace ? '' : $role );

		if ( $replace ) {
			$event = 'substituida';
		} elseif ( empty( $obsolete ) ) {
			$event = 'arquivo_anexado';
		} else {
			$event = 'arquivo_substituido';
		}

		$updated = papelito_fiscal_document_update( $document_id, $built, $notes, $actor_id );

		if ( is_wp_error( $updated ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $updated;
		}

		papelito_fiscal_document_delete_file_rows( $document_id, $replace ? '' : $role );
	} else {
		$document_id = papelito_fiscal_document_insert( $built, $notes, $actor_id );

		if ( is_wp_error( $document_id ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $document_id;
		}
	}

	$file_id = papelito_fiscal_document_register_file( $document_id, $role, $attachment['stored']['key'], $attachment['validated'], $actor_id );

	if ( is_wp_error( $file_id ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $file_id;
	}

	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return array(
		'document_id' => (int) $document_id,
		'obsolete'    => $obsolete,
		'event'       => $event,
	);
}

/**
 * Grava o anexo, com no máximo uma nota por pedido e sem versões.
 *
 * A ordem importa: o arquivo novo já está em disco quando a transação começa,
 * a troca de referência é atômica, e só depois do commit os arquivos antigos
 * saem do disco. Falha antes do commit deixa a nota anterior intacta; falha
 * depois deixa no máximo um arquivo sem referência, nunca um pedido sem nota.
 *
 * @param object              $order      Pedido WooCommerce.
 * @param array<string,mixed> $attachment Anexo já gravado em disco.
 * @return array<string,mixed>|WP_Error
 */
function papelito_fiscal_document_commit_file( $order, int $vendor_id, array $attachment, int $actor_id ) {
	$order_id = (int) $order->get_id();
	$role     = (string) $attachment['role'];
	$replace  = 'replace' === (string) $attachment['mode'];
	$current  = papelito_fiscal_document_current( $order_id, $vendor_id );
	$context  = papelito_fiscal_order_context( $order, $vendor_id );

	// Substituir descarta o que havia; completar mantém o outro papel e o
	// digitado que já estava gravado.
	$declared = ( $current && ! $replace )
		? array_merge( papelito_fiscal_document_declared_from_row( $current ), $attachment['declared'] )
		: $attachment['declared'];

	$xml = '';
	if ( 'xml' === $role ) {
		$xml = papelito_fiscal_stored_xml_contents( $attachment['stored'] );
	} elseif ( $current && ! $replace ) {
		$xml = papelito_fiscal_document_xml_contents( (int) $current['id'] );
	}

	$built = papelito_fiscal_document_rebuild( $declared, $order_id, $vendor_id, $xml, $context );
	$notes = substr( (string) ( $declared['notes'] ?? '' ), 0, PAPELITO_FISCAL_NOTES_MAX_LENGTH );

	$persisted = papelito_fiscal_document_persist_commit( $current, $replace, $role, $built, $notes, $attachment, $actor_id );

	if ( is_wp_error( $persisted ) ) {
		return $persisted;
	}

	$document_id = (int) $persisted['document_id'];
	papelito_fiscal_document_purge_files( $persisted['obsolete'] );

	papelito_fiscal_document_log_event(
		$document_id,
		$persisted['event'],
		array(
			'role'       => $role,
			'doc_type'   => $built['doc_type'],
			'doc_status' => $built['doc_status'],
			'access_key' => $built['access_key'],
			'flags'      => $built['flags'],
		),
		$actor_id,
		'vendor'
	);

	return papelito_fiscal_document_payload( papelito_fiscal_document_get( $document_id ) );
}

/**
 * Quantos eventos do documento voltam no payload.
 *
 * O detalhe do pedido é lido sem cache a cada visita, então a lista precisa de
 * teto: um vendor que reanexa o arquivo dezenas de vezes não pode arrastar todo
 * o log junto do pedido.
 */
if ( ! defined( 'PAPELITO_FISCAL_HISTORY_LIMIT' ) ) {
	define( 'PAPELITO_FISCAL_HISTORY_LIMIT', 20 );
}

/**
 * Histórico do documento, do mais recente para o mais antigo.
 *
 * Os eventos já eram gravados desde a fundação — o que faltava era devolvê-los.
 * `detail_json` passou por `papelito_fiscal_event_safe_detail()` na escrita, e
 * é de lá que sai o papel do arquivo e os quatro últimos dígitos da chave: a
 * chave inteira nunca entrou no log.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_fiscal_document_history( int $document_id ): array {
	global $wpdb;

	if ( $document_id <= 0 ) {
		return array();
	}

	$tables = papelito_fiscal_table_names();
	$rows   = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare(
			"SELECT id, event, actor_role, detail_json, created_at FROM {$tables['events']} WHERE fiscal_document_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
			$document_id,
			PAPELITO_FISCAL_HISTORY_LIMIT
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$events = array();

	foreach ( (array) $rows as $row ) {
		$detail = json_decode( (string) ( $row['detail_json'] ?? '' ), true );
		$detail = is_array( $detail ) ? $detail : array();

		$events[] = array(
			'id'         => (int) $row['id'],
			'event'      => (string) $row['event'],
			'actor_role' => (string) $row['actor_role'],
			'created_at' => (string) $row['created_at'],
			'role'       => (string) ( $detail['role'] ?? '' ),
			'doc_status' => (string) ( $detail['doc_status'] ?? '' ),
		);
	}

	return $events;
}

/**
 * Documento corrente serializado para o painel do vendor.
 *
 * A chave de acesso volta inteira: quem a digitou foi o próprio vendor, e sem
 * ela a tela não consegue mostrar o que foi anexado. O log de eventos continua
 * guardando apenas os quatro últimos dígitos.
 *
 * @param array<string,mixed>|null $document Linha da tabela de documentos.
 * @return array<string,mixed>|null
 */
function papelito_fiscal_document_payload( ?array $document ): ?array {
	if ( ! $document ) {
		return null;
	}

	$document_id = (int) $document['id'];
	$flags       = json_decode( (string) ( $document['flags_json'] ?? '' ), true );

	return array(
		'id'                   => $document_id,
		'doc_type'             => (string) $document['doc_type'],
		'doc_status'           => (string) $document['doc_status'],
		'validation_level'     => (int) $document['validation_level'],
		'access_key'           => (string) ( $document['access_key'] ?? '' ),
		'access_key_status'    => (string) $document['access_key_status'],
		'doc_number'           => (string) ( $document['doc_number'] ?? '' ),
		'doc_series'           => (string) ( $document['doc_series'] ?? '' ),
		'protocol'             => (string) ( $document['protocol'] ?? '' ),
		'issuer_cnpj'          => (string) ( $document['issuer_cnpj'] ?? '' ),
		'issuer_name'          => (string) ( $document['issuer_name'] ?? '' ),
		'issued_at'            => (string) ( $document['issued_at'] ?? '' ),
		'total_cents'          => (int) $document['total_cents'],
		'notes'                => (string) ( $document['internal_notes'] ?? '' ),
		'flags'                => is_array( $flags ) ? array_values( $flags ) : array(),
		'created_at'           => (string) $document['created_at'],
		'updated_at'           => (string) $document['updated_at'],
		'events'               => papelito_fiscal_document_history( $document_id ),
		'files'                => array_map(
			static function ( array $file ): array {
				return array(
					'id'            => (int) $file['id'],
					'role'          => (string) $file['role'],
					'original_name' => (string) $file['original_name'],
					'mime'          => (string) $file['mime'],
					'size_bytes'    => (int) $file['size_bytes'],
					'created_at'    => (string) $file['created_at'],
				);
			},
			papelito_fiscal_document_files( $document_id )
		),
	);
}

/**
 * Bloco de nota fiscal do detalhe de pedido do vendor.
 *
 * @param object $order Pedido WooCommerce.
 * @return array<string,mixed>
 */
function papelito_fiscal_order_block( $order, int $vendor_id ): array {
	$enabled      = papelito_fiscal_documents_enabled();
	$block_reason = papelito_fiscal_order_block_reason( $order );

	return array(
		'enabled'      => $enabled,
		'can_attach'   => $enabled && '' === $block_reason,
		'block_reason' => $block_reason,
		'limits'       => array(
			'xml'       => PAPELITO_FISCAL_XML_MAX_BYTES,
			'danfe_pdf' => PAPELITO_FISCAL_PDF_MAX_BYTES,
		),
		'document'     => $enabled ? papelito_fiscal_document_payload( papelito_fiscal_document_current( (int) $order->get_id(), $vendor_id ) ) : null,
	);
}

/**
 * Nota fiscal do pedido na visão do comprador.
 *
 * Devolve `null` quando não há nota: a interface do comprador **não avisa**
 * ausência de nota fiscal — quem emite é o vendor, e cobrar isso do comprador
 * seria expor uma pendência que não é dele. Sem flags, sem nível e sem notas
 * internas: nada disso é assunto de quem comprou.
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

	$files = array();

	foreach ( papelito_fiscal_document_files( (int) $document['id'] ) as $file ) {
		$files[] = array(
			'id'         => (int) $file['id'],
			'role'       => (string) $file['role'],
			'mime'       => (string) $file['mime'],
			'size_bytes' => (int) $file['size_bytes'],
		);
	}

	if ( empty( $files ) ) {
		return null;
	}

	return array(
		'doc_number' => (string) ( $document['doc_number'] ?? '' ),
		'doc_series' => (string) ( $document['doc_series'] ?? '' ),
		'doc_type'   => (string) $document['doc_type'],
		'access_key' => (string) ( $document['access_key'] ?? '' ),
		'issued_at'  => (string) ( $document['issued_at'] ?? '' ),
		'files'      => $files,
	);
}

/**
 * Arquivo da nota liberado para o comprador daquele pedido.
 *
 * @param object $order Pedido WooCommerce.
 * @return array<string,mixed>|WP_Error
 */
function papelito_fiscal_customer_file( $order, int $file_id ) {
	$enabled = papelito_fiscal_documents_require_enabled();

	if ( is_wp_error( $enabled ) ) {
		return $enabled;
	}

	$vendor_id = absint( $order->get_meta( '_papelito_vendor_id', true ) );
	$document  = $vendor_id > 0 ? papelito_fiscal_document_current( (int) $order->get_id(), $vendor_id ) : null;

	if ( ! $document ) {
		return new WP_Error( 'papelito_fiscal_document_not_found', 'Nota fiscal não encontrada.', array( 'status' => 404 ) );
	}

	return papelito_fiscal_document_readable_file( (int) $document['id'], $file_id );
}

/**
 * Pedidos do vendor que têm documento corrente, em uma consulta só.
 *
 * Sem lista de ids: a listagem precisa do conjunto inteiro para contar a fila
 * de nota pendente, e passar um placeholder por pedido fazia `prepare()` montar
 * uma query proporcional ao histórico do vendor a cada carregamento e a cada
 * revalidação de 30 s.
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
		$wpdb->prepare( "SELECT order_id FROM {$tables['documents']} WHERE vendor_id = %d AND is_current = 1", $vendor_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$ids = array();
	foreach ( (array) $rows as $id ) {
		$ids[ (int) $id ] = (int) $id;
	}

	return $ids;
}

/**
 * Arquivo ativo de um documento, validado para leitura em disco.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_fiscal_document_readable_file( int $document_id, int $file_id ) {
	foreach ( papelito_fiscal_document_files( $document_id ) as $file ) {
		if ( (int) $file['id'] !== $file_id ) {
			continue;
		}

		$key = (string) $file['storage_key'];

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

		return array_merge( $file, array( 'path' => $path ) );
	}

	return new WP_Error( 'papelito_fiscal_document_file_not_found', 'Arquivo não encontrado.', array( 'status' => 404 ) );
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

/**
 * REST callback: documento fiscal corrente do pedido.
 */
function papelito_fiscal_rest_handle_get( WP_REST_Request $request ) {
	$order = papelito_fiscal_rest_vendor_order( $request );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	return new WP_REST_Response( papelito_fiscal_order_block( $order, get_current_user_id() ), 200 );
}

/**
 * REST callback: grava os dados digitados da nota, sem arquivo.
 */
function papelito_fiscal_rest_handle_save( WP_REST_Request $request ) {
	$order = papelito_fiscal_rest_vendor_order( $request );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$vendor_id    = get_current_user_id();
	$block_reason = papelito_fiscal_order_block_reason( $order );

	if ( '' !== $block_reason ) {
		return new WP_Error(
			'papelito_fiscal_order_not_ready',
			'cancelado' === $block_reason
				? 'Pedido cancelado não recebe nota fiscal.'
				: 'A nota fiscal pode ser anexada depois da confirmação do pagamento.',
			array( 'status' => 409 )
		);
	}

	$declared = papelito_fiscal_declared_from_input( (array) $request->get_json_params() );

	if ( empty( $declared ) ) {
		return new WP_Error( 'papelito_fiscal_document_empty', 'Informe ao menos um dado da nota fiscal.', array( 'status' => 422 ) );
	}

	if ( ! papelito_fiscal_document_current( (int) $order->get_id(), $vendor_id ) && '' === (string) ( $declared['access_key'] ?? '' ) ) {
		return new WP_Error( 'papelito_fiscal_access_key_required', 'Informe a chave de acesso ou anexe o arquivo da nota.', array( 'status' => 422 ) );
	}

	$saved = papelito_fiscal_document_save_declared( $order, $vendor_id, $declared, $vendor_id );

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return new WP_REST_Response( papelito_fiscal_order_block( $order, $vendor_id ), 200 );
}

/**
 * REST callback: download do arquivo da nota.
 */
function papelito_fiscal_rest_handle_file( WP_REST_Request $request ) {
	$order = papelito_fiscal_rest_vendor_order( $request );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$document = papelito_fiscal_document_current( (int) $order->get_id(), get_current_user_id() );

	if ( ! $document ) {
		return new WP_Error( 'papelito_fiscal_document_not_found', 'Nota fiscal não encontrada.', array( 'status' => 404 ) );
	}

	$file = papelito_fiscal_document_readable_file( (int) $document['id'], absint( $request->get_param( 'fileId' ) ) );

	if ( is_wp_error( $file ) ) {
		return $file;
	}

	$disposition = 'xml' === (string) $file['role'] ? 'attachment' : 'inline';

	nocache_headers();
	header( 'Content-Type: ' . (string) $file['mime'] );
	header( PAPELITO_FISCAL_HEADER_CONTENT_LENGTH . (string) filesize( (string) $file['path'] ) );
	header( PAPELITO_FISCAL_HEADER_CONTENT_DISPOSITION . $disposition . PAPELITO_FISCAL_HEADER_FILENAME_PREFIX . str_replace( array( '"', "\r", "\n" ), '', (string) $file['original_name'] ) . '"' );
	header( PAPELITO_FISCAL_HEADER_CONTENT_TYPE_OPTIONS );
	readfile( (string) $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

/**
 * REST callback: espelho da nota em PDF.
 *
 * `inline` por padrão para o vendor conferir sem baixar; `?download=1` força o
 * anexo.
 */
function papelito_fiscal_rest_handle_pdf( WP_REST_Request $request ) {
	$order = papelito_fiscal_rest_vendor_order( $request );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$pdf = papelito_fiscal_document_pdf( $order, get_current_user_id() );

	if ( is_wp_error( $pdf ) ) {
		return $pdf;
	}

	$disposition = '1' === (string) $request->get_param( 'download' ) ? 'attachment' : 'inline';
	$filename    = 'espelho-nota-pedido-' . (string) $order->get_order_number() . '.pdf';

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( PAPELITO_FISCAL_HEADER_CONTENT_LENGTH . strlen( $pdf ) );
	header( PAPELITO_FISCAL_HEADER_CONTENT_DISPOSITION . $disposition . PAPELITO_FISCAL_HEADER_FILENAME_PREFIX . $filename . '"' );
	header( PAPELITO_FISCAL_HEADER_CONTENT_TYPE_OPTIONS );
	echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- corpo binario de PDF.
	exit;
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
 * REST callback: arquivo da nota para o comprador do pedido.
 *
 * Autorização é a do comprador — `papelito_receipt_order_for_current_user()`
 * já resolve "este pedido é seu". O comprador só lê; anexar e substituir são
 * do vendor.
 */
function papelito_fiscal_customer_handle_file( WP_REST_Request $request ) {
	$order = papelito_receipt_order_for_current_user( absint( $request->get_param( 'id' ) ) );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$file = papelito_fiscal_customer_file( $order, absint( $request->get_param( 'fileId' ) ) );

	if ( is_wp_error( $file ) ) {
		return $file;
	}

	$disposition = 'xml' === (string) $file['role'] ? 'attachment' : 'inline';

	nocache_headers();
	header( 'Content-Type: ' . (string) $file['mime'] );
	header( PAPELITO_FISCAL_HEADER_CONTENT_LENGTH . (string) filesize( (string) $file['path'] ) );
	header( PAPELITO_FISCAL_HEADER_CONTENT_DISPOSITION . $disposition . PAPELITO_FISCAL_HEADER_FILENAME_PREFIX . str_replace( array( '"', "\r", "\n" ), '', (string) $file['original_name'] ) . '"' );
	header( PAPELITO_FISCAL_HEADER_CONTENT_TYPE_OPTIONS );
	readfile( (string) $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
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
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
				'callback'            => 'papelito_fiscal_rest_handle_save',
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/profile/me/orders/(?P<id>\d+)/fiscal-document/files/(?P<fileId>\d+)',
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

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/fiscal-document/pdf',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
			'callback'            => 'papelito_fiscal_rest_handle_pdf',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/fiscal-document/files/(?P<fileId>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
			'callback'            => 'papelito_fiscal_rest_handle_file',
		)
	);
}
add_action( 'rest_api_init', 'papelito_fiscal_documents_register_routes' );
