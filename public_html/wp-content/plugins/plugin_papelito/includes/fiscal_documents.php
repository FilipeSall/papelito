<?php
/**
 * Fundação da nota fiscal do pedido: schema, armazenamento privado e domínio.
 *
 * A Papelito não emite nota, não participa da operação fiscal e não valida
 * documento perante o fisco. O que ela faz é **guardar o arquivo que o vendor
 * anexa e saber que ele existe** — nada além disso é lido, interpretado ou
 * afirmado sobre o conteúdo.
 *
 * Por isso o modelo é: uma nota por `(order_id, vendor_id)`, um arquivo por
 * nota, e nenhum campo digitado. Anexar de novo substitui e apaga o arquivo
 * anterior do disco.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_FISCAL_XML_MAX_BYTES' ) ) {
	define( 'PAPELITO_FISCAL_XML_MAX_BYTES', 2 * 1048576 );
}

if ( ! defined( 'PAPELITO_FISCAL_PDF_MAX_BYTES' ) ) {
	define( 'PAPELITO_FISCAL_PDF_MAX_BYTES', 10 * 1048576 );
}

/**
 * Idade mínima para a varredura considerar um arquivo órfão.
 *
 * O arquivo é gravado em disco **antes** da transação que o referencia. Sem
 * esta janela, a varredura apagaria o arquivo de um upload cuja transação ainda
 * não commitou.
 */
if ( ! defined( 'PAPELITO_FISCAL_SWEEP_MIN_AGE' ) ) {
	define( 'PAPELITO_FISCAL_SWEEP_MIN_AGE', 3600 );
}

/**
 * Eventos da trilha. `removida` também é registrado quando o pedido é apagado.
 *
 * @return array<int,string>
 */
function papelito_fiscal_document_events(): array {
	return array( 'anexada', 'substituida', 'removida' );
}

/**
 * @return array<string,string>
 */
function papelito_fiscal_table_names(): array {
	global $wpdb;

	return array(
		'documents' => $wpdb->prefix . 'papelito_fiscal_documents',
		'events'    => $wpdb->prefix . 'papelito_fiscal_document_events',
	);
}

/**
 * Remove o schema do modelo anterior, que guardava dados digitados da nota.
 *
 * A nota deixou de ter chave de acesso, número, série, emissão, valor, status,
 * nível de conferência e flags de divergência: ela é só o arquivo. As tabelas
 * antigas não têm como ser migradas para o formato novo — `documents` perdeu
 * vinte colunas, `files` foi absorvida pela linha do documento e `events`
 * mudou de chave estrangeira para `(order_id, vendor_id)`.
 *
 * Idempotente e seguro: `DROP TABLE IF EXISTS` seguido do instalador novo, que
 * roda na mesma migração.
 */
function papelito_fiscal_documents_drop_legacy(): void {
	global $wpdb;

	$prefix = $wpdb->prefix;

	foreach ( array( 'papelito_fiscal_document_files', 'papelito_fiscal_document_events', 'papelito_fiscal_documents' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}

function papelito_fiscal_documents_install_tables(): void {
	global $wpdb;

	$tables          = papelito_fiscal_table_names();
	$charset_collate = $wpdb->get_charset_collate();

	// `UNIQUE (order_id, vendor_id)` é unicidade de verdade: uma nota por
	// pedido/vendor, para sempre. O modelo anterior precisava do truque
	// `is_current = 1 | NULL` só para caber N versões históricas sob índice
	// único — sem histórico de documento, o truque some junto.
	$documents_sql = "CREATE TABLE {$tables['documents']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  original_name VARCHAR(191) NOT NULL DEFAULT '',
  mime VARCHAR(64) NOT NULL DEFAULT '',
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_order_vendor (order_id, vendor_id),
  UNIQUE KEY uniq_storage_key (storage_key),
  KEY idx_order (order_id),
  KEY idx_vendor (vendor_id)
) {$charset_collate};";

	// A trilha é chaveada por `(order_id, vendor_id)`, e não pelo id do
	// documento, de propósito: remover a nota apaga a linha do documento, e é
	// justamente aí que alguém precisa saber o que aconteceu com ela.
	$events_sql = "CREATE TABLE {$tables['events']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(32) NOT NULL,
  original_name VARCHAR(191) NOT NULL DEFAULT '',
  actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_order_vendor_created (order_id, vendor_id, created_at)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $documents_sql );
	dbDelta( $events_sql );

	papelito_db_align_binary_columns(
		$tables['documents'],
		array(
			'storage_key' => array(
				'type'       => 'VARCHAR(96)',
				'attributes' => 'NOT NULL',
			),
			'sha256'      => array(
				'type'       => 'CHAR(64)',
				'attributes' => "NOT NULL DEFAULT ''",
			),
		)
	);
}

/**
 * Diretório privado dos arquivos fiscais, fora do webroot e sem fallback público.
 */
function papelito_fiscal_documents_dir(): string {
	return papelito_private_files_dir( 'PAPELITO_PRIVATE_FISCAL_DOCUMENTS_DIR', 'fiscal-documents' );
}

/**
 * @return string|WP_Error
 */
function papelito_fiscal_documents_prepare_dir() {
	return papelito_private_files_prepare_dir( papelito_fiscal_documents_dir(), 'papelito_fiscal_document' );
}

/**
 * Política do arquivo da nota: PDF ou XML, num spec só.
 *
 * O teto declarado aqui é o maior dos dois; o limite por formato é aplicado
 * depois, em `papelito_fiscal_document_validate_upload()`, porque só dá para
 * saber qual vale depois que o `finfo` disse o que o arquivo é de verdade.
 *
 * @return array<string,mixed>
 */
function papelito_fiscal_document_spec(): array {
	return array(
		'code_prefix'       => 'papelito_fiscal_document',
		'max_bytes'         => PAPELITO_FISCAL_PDF_MAX_BYTES,
		'formats'           => array( 'pdf', 'xml' ),
		'fallback_basename' => 'nota',
	);
}

function papelito_fiscal_document_max_bytes( string $format ): int {
	return 'xml' === $format ? PAPELITO_FISCAL_XML_MAX_BYTES : PAPELITO_FISCAL_PDF_MAX_BYTES;
}

/**
 * @return array<string,int>
 */
function papelito_fiscal_document_limits(): array {
	return array(
		'pdf' => PAPELITO_FISCAL_PDF_MAX_BYTES,
		'xml' => PAPELITO_FISCAL_XML_MAX_BYTES,
	);
}

/**
 * @return array{extension:string,mime:string,size:int,sha256:string,original_name:string}|WP_Error
 */
function papelito_fiscal_document_validate_upload( array $file ) {
	$validated = papelito_private_file_validate_upload( $file, papelito_fiscal_document_spec() );

	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	$max_bytes = papelito_fiscal_document_max_bytes( (string) $validated['extension'] );

	if ( (int) $validated['size'] > $max_bytes ) {
		return papelito_private_file_error(
			'papelito_fiscal_document',
			'size_invalid',
			papelito_private_file_size_message( $max_bytes ),
			413
		);
	}

	return $validated;
}

/**
 * @param array{extension:string,mime:string,size:int,sha256:string,original_name:string} $validated Metadados validados.
 * @return array{key:string,path:string}|WP_Error
 */
function papelito_fiscal_document_store( array $file, array $validated, string $directory ) {
	return papelito_private_file_store( $file, $validated, $directory, 'papelito_fiscal_document' );
}

function papelito_fiscal_document_discard_path( string $path ): void {
	papelito_private_file_discard_path( $path );
}

function papelito_fiscal_document_key_is_valid( string $key ): bool {
	return papelito_private_file_key_is_valid( $key, array( 'pdf', 'xml' ) );
}

/**
 * Nota do vendor no pedido, ou null.
 *
 * @return array<string,mixed>|null
 */
function papelito_fiscal_document_current( int $order_id, int $vendor_id ): ?array {
	global $wpdb;

	if ( $order_id <= 0 || $vendor_id <= 0 ) {
		return null;
	}

	$tables = papelito_fiscal_table_names();
	$row    = $wpdb->get_row(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['documents']} WHERE order_id = %d AND vendor_id = %d", $order_id, $vendor_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return is_array( $row ) ? $row : null;
}

/**
 * @return array<string,mixed>|null
 */
function papelito_fiscal_document_get( int $document_id ): ?array {
	global $wpdb;

	$tables = papelito_fiscal_table_names();
	$row    = $wpdb->get_row(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['documents']} WHERE id = %d", $document_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return is_array( $row ) ? $row : null;
}

/**
 * Registra um evento na trilha.
 *
 * O nome original do arquivo é guardado porque é o único jeito de a trilha
 * dizer *qual* nota foi trocada depois que o arquivo já sumiu do disco.
 */
function papelito_fiscal_document_log_event( int $order_id, int $vendor_id, string $event, string $original_name = '', int $actor_user_id = 0 ): bool {
	global $wpdb;

	$event = sanitize_key( $event );

	if ( $order_id <= 0 || $vendor_id <= 0 || ! in_array( $event, papelito_fiscal_document_events(), true ) ) {
		return false;
	}

	$tables   = papelito_fiscal_table_names();
	$inserted = $wpdb->insert(
		$tables['events'],
		array(
			'order_id'      => $order_id,
			'vendor_id'     => $vendor_id,
			'event'         => $event,
			'original_name' => substr( sanitize_file_name( $original_name ), 0, 191 ),
			'actor_user_id' => max( 0, $actor_user_id ),
			'created_at'    => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s', '%s', '%d', '%s' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return false !== $inserted;
}

/**
 * Trilha da nota, da mais antiga para a mais recente.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_fiscal_document_history( int $order_id, int $vendor_id ): array {
	global $wpdb;

	if ( $order_id <= 0 || $vendor_id <= 0 ) {
		return array();
	}

	$tables = papelito_fiscal_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
			"SELECT * FROM {$tables['events']} WHERE order_id = %d AND vendor_id = %d ORDER BY id ASC",
			$order_id,
			$vendor_id
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return is_array( $rows ) ? $rows : array();
}
