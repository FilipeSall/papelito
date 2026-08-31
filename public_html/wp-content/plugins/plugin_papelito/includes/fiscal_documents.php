<?php
/**
 * Fundação de documentos fiscais: schema, armazenamento privado e domínio.
 *
 * A Papelito não emite nota. O vendor emite por fora e anexa aqui. Esta etapa
 * é só a fundação: **não registra rota REST, não tem UI e nada de fulfillment
 * ou pagamento lê `doc_status`**.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'papelito_fiscal_key_is_valid' ) ) {
	require_once __DIR__ . '/fiscal_document_validation.php';
}

if ( ! defined( 'PAPELITO_FISCAL_SNAPSHOT_VERSION' ) ) {
	define( 'PAPELITO_FISCAL_SNAPSHOT_VERSION', 1 );
}

/**
 * Tipos de documento aceitos.
 *
 * @return array<int,string>
 */
function papelito_fiscal_document_types(): array {
	return array( 'nfe', 'nfce', 'nfse', 'other' );
}

/**
 * Estados do documento. `substituida` e `cancelada` são terminais.
 *
 * @return array<int,string>
 */
function papelito_fiscal_document_statuses(): array {
	return array( 'recebida', 'pendente_revisao', 'aceita', 'rejeitada', 'cancelada', 'substituida' );
}

/**
 * Papéis de arquivo. Um ativo por papel em cada documento.
 *
 * @return array<int,string>
 */
function papelito_fiscal_file_roles(): array {
	return array( 'danfe_pdf', 'xml', 'other' );
}

/**
 * @return array<string,string>
 */
function papelito_fiscal_table_names(): array {
	global $wpdb;

	return array(
		'documents' => $wpdb->prefix . 'papelito_fiscal_documents',
		'files'     => $wpdb->prefix . 'papelito_fiscal_document_files',
		'events'    => $wpdb->prefix . 'papelito_fiscal_document_events',
	);
}

function papelito_fiscal_documents_install_tables(): void {
	global $wpdb;

	$tables          = papelito_fiscal_table_names();
	$charset_collate = $wpdb->get_charset_collate();

	$documents_sql = "CREATE TABLE {$tables['documents']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  receipt_id BIGINT UNSIGNED NULL DEFAULT NULL,
  receipt_vendor_part_id BIGINT UNSIGNED NULL DEFAULT NULL,
  doc_type VARCHAR(16) NOT NULL DEFAULT 'nfe',
  doc_status VARCHAR(24) NOT NULL DEFAULT 'recebida',
  validation_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  access_key CHAR(44) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
  access_key_status VARCHAR(16) NOT NULL DEFAULT 'ausente',
  doc_number VARCHAR(20) NULL DEFAULT NULL,
  doc_series VARCHAR(10) NULL DEFAULT NULL,
  protocol VARCHAR(40) NULL DEFAULT NULL,
  issuer_cnpj CHAR(14) NULL DEFAULT NULL,
  issuer_name VARCHAR(255) NULL DEFAULT NULL,
  issued_at DATETIME NULL DEFAULT NULL,
  total_cents BIGINT NOT NULL DEFAULT 0,
  internal_notes TEXT NULL,
  flags_json LONGTEXT NULL,
  snapshot_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  replaces_document_id BIGINT UNSIGNED NULL DEFAULT NULL,
  replaced_by_document_id BIGINT UNSIGNED NULL DEFAULT NULL,
  cancelled_at DATETIME NULL DEFAULT NULL,
  cancel_reason VARCHAR(255) NULL DEFAULT NULL,
  is_current TINYINT UNSIGNED NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_current (order_id, vendor_id, is_current),
  KEY idx_access_key (access_key),
  KEY idx_order (order_id),
  KEY idx_vendor_status (vendor_id, doc_status),
  KEY idx_receipt (receipt_id),
  KEY idx_issued_at (issued_at)
) {$charset_collate};";

	$files_sql = "CREATE TABLE {$tables['files']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'other',
  storage_key VARCHAR(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  original_name VARCHAR(191) NOT NULL DEFAULT '',
  mime VARCHAR(64) NOT NULL DEFAULT '',
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  is_active TINYINT UNSIGNED NULL DEFAULT 1,
  uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  deleted_by BIGINT UNSIGNED NULL DEFAULT NULL,
  deleted_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_active_role (fiscal_document_id, role, is_active),
  UNIQUE KEY uniq_storage_key (storage_key),
  KEY idx_document (fiscal_document_id),
  KEY idx_sha256 (sha256)
) {$charset_collate};";

	$events_sql = "CREATE TABLE {$tables['events']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(32) NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  actor_role VARCHAR(20) NOT NULL DEFAULT '',
  detail_json LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_document_created (fiscal_document_id, created_at),
  KEY idx_event (event)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $documents_sql );
	dbDelta( $files_sql );
	dbDelta( $events_sql );

	papelito_db_align_binary_columns(
		$tables['documents'],
		array(
			'access_key' => array(
				'type'       => 'CHAR(44)',
				'attributes' => 'NULL DEFAULT NULL',
			),
		)
	);
	papelito_db_align_binary_columns(
		$tables['files'],
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
 * Política de arquivo por papel. São dois specs porque os limites diferem:
 * 10 MB para o DANFE em PDF, 2 MB para o XML.
 *
 * @return array<string,mixed>
 */
function papelito_fiscal_document_spec( string $role ): array {
	if ( 'xml' === $role ) {
		return array(
			'code_prefix'       => 'papelito_fiscal_document',
			'max_bytes'         => PAPELITO_FISCAL_XML_MAX_BYTES,
			'formats'           => array( 'xml' ),
			'fallback_basename' => 'nota',
		);
	}

	return array(
		'code_prefix'       => 'papelito_fiscal_document',
		'max_bytes'         => PAPELITO_FISCAL_PDF_MAX_BYTES,
		'formats'           => array( 'pdf' ),
		'fallback_basename' => 'danfe',
	);
}

/**
 * @return array{extension:string,mime:string,size:int,sha256:string,original_name:string}|WP_Error
 */
function papelito_fiscal_document_validate_upload( array $file, string $role ) {
	$validated = papelito_private_file_validate_upload( $file, papelito_fiscal_document_spec( $role ) );

	if ( is_wp_error( $validated ) || 'xml' !== $role ) {
		return $validated;
	}

	$contents = file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- arquivo temporario ja validado.
	$parsed   = is_string( $contents ) ? papelito_fiscal_xml_parse( $contents ) : null;

	if ( ! $parsed instanceof SimpleXMLElement ) {
		return papelito_private_file_error( 'papelito_fiscal_document', 'xml_invalid', 'O XML enviado é inválido.', 422 );
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
 * Documento corrente de um vendor no pedido, ou null.
 *
 * @return array<string,mixed>|null
 */
function papelito_fiscal_document_current( int $order_id, int $vendor_id ): ?array {
	global $wpdb;

	$tables = papelito_fiscal_table_names();
	$row    = $wpdb->get_row(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['documents']} WHERE order_id = %d AND vendor_id = %d AND is_current = 1", $order_id, $vendor_id ),
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
 * Documentos que declaram a mesma chave. Duplicidade é sinalização
 * administrativa, não erro de banco — por isso o índice não é único.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_fiscal_documents_by_access_key( string $access_key ): array {
	global $wpdb;

	$access_key = papelito_fiscal_key_normalize( $access_key );

	if ( 44 !== strlen( $access_key ) ) {
		return array();
	}

	$tables = papelito_fiscal_table_names();
	$rows   = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['documents']} WHERE access_key = %s ORDER BY id ASC", $access_key ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return is_array( $rows ) ? $rows : array();
}

/**
 * Arquivos ativos de um documento.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_fiscal_document_files( int $document_id, bool $only_active = true ): array {
	global $wpdb;

	$tables = papelito_fiscal_table_names();

	if ( $only_active ) {
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
			$wpdb->prepare( "SELECT * FROM {$tables['files']} WHERE fiscal_document_id = %d AND is_active = 1 ORDER BY id ASC", $document_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	$rows = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome de tabela com prefixo do $wpdb.
		$wpdb->prepare( "SELECT * FROM {$tables['files']} WHERE fiscal_document_id = %d ORDER BY id ASC", $document_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return is_array( $rows ) ? $rows : array();
}

/**
 * Evento imutável de auditoria.
 *
 * Nunca recebe PII, conteúdo de arquivo, nome original ou chave completa: o
 * detalhe é sanitizado por papelito_fiscal_event_safe_detail().
 *
 * @param array<string,mixed> $detail Detalhe estruturado.
 */
function papelito_fiscal_document_log_event( int $document_id, string $event, array $detail = array(), int $actor_user_id = 0, string $actor_role = '' ): bool {
	global $wpdb;

	if ( $document_id <= 0 || '' === $event ) {
		return false;
	}

	$tables   = papelito_fiscal_table_names();
	$inserted = $wpdb->insert(
		$tables['events'],
		array(
			'fiscal_document_id' => $document_id,
			'event'              => substr( sanitize_key( $event ), 0, 32 ),
			'actor_user_id'      => max( 0, $actor_user_id ),
			'actor_role'         => substr( sanitize_key( $actor_role ), 0, 20 ),
			'detail_json'        => wp_json_encode( papelito_fiscal_event_safe_detail( $detail ) ),
			'created_at'         => current_time( 'mysql', true ),
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return false !== $inserted;
}

/**
 * Reduz o detalhe do evento ao que é seguro persistir.
 *
 * Escalares numéricos e booleanos passam; strings só quando estão na lista de
 * campos não sensíveis, e a chave de acesso vira só os quatro últimos dígitos.
 *
 * @param array<string,mixed> $detail Detalhe cru.
 * @return array<string,mixed>
 */
function papelito_fiscal_event_safe_detail( array $detail ): array {
	$allowed_strings = array( 'doc_type', 'doc_status', 'previous_status', 'role', 'access_key_status', 'reason_code' );
	$safe            = array();

	foreach ( $detail as $key => $value ) {
		$key = sanitize_key( (string) $key );

		if ( 'access_key' === $key ) {
			$normalized = papelito_fiscal_key_normalize( (string) $value );
			if ( '' !== $normalized ) {
				$safe['access_key_last4'] = substr( $normalized, -4 );
			}
			continue;
		}

		if ( is_bool( $value ) || is_int( $value ) ) {
			$safe[ $key ] = $value;
			continue;
		}

		if ( is_array( $value ) && 'flags' === $key ) {
			$safe['flags'] = array_values( array_map( 'sanitize_key', array_map( 'strval', $value ) ) );
			continue;
		}

		if ( is_string( $value ) && in_array( $key, $allowed_strings, true ) ) {
			$safe[ $key ] = substr( sanitize_key( $value ), 0, 32 );
		}
	}

	return $safe;
}

/**
 * Monta a linha de um documento a partir de dados declarados e extraídos.
 *
 * Não grava nada: devolve o array pronto para insert, com flags e nível já
 * calculados. O digitado nunca é sobrescrito pelo XML.
 *
 * @param array<string,mixed> $declared  Dados informados pelo vendor.
 * @param array<string,mixed> $extracted Dados lidos do XML, se houver.
 * @param array<string,mixed> $context   Dados do pedido para cruzamento.
 * @return array<string,mixed>
 */
function papelito_fiscal_document_build( array $declared, array $extracted = array(), array $context = array() ): array {
	$doc_type = sanitize_key( (string) ( $declared['doc_type'] ?? 'nfe' ) );
	$doc_type = in_array( $doc_type, papelito_fiscal_document_types(), true ) ? $doc_type : 'other';

	$access_key = papelito_fiscal_key_normalize( (string) ( $declared['access_key'] ?? '' ) );
	if ( '' === $access_key && ! empty( $extracted['access_key'] ) ) {
		$access_key = papelito_fiscal_key_normalize( (string) $extracted['access_key'] );
	}

	$issued_at = papelito_fiscal_normalize_datetime( (string) ( $declared['issued_at'] ?? '' ) );
	if ( '' === $issued_at && ! empty( $extracted['issued_at'] ) ) {
		$issued_at = papelito_fiscal_normalize_datetime( (string) $extracted['issued_at'] );
	}

	$document = array(
		'order_id'          => (int) ( $declared['order_id'] ?? 0 ),
		'vendor_id'         => (int) ( $declared['vendor_id'] ?? 0 ),
		'doc_type'          => $doc_type,
		'access_key'        => $access_key,
		'access_key_status' => papelito_fiscal_key_status( $access_key ),
		'doc_number'        => trim( (string) ( $declared['doc_number'] ?? ( $extracted['number'] ?? '' ) ) ),
		'doc_series'        => trim( (string) ( $declared['doc_series'] ?? ( $extracted['series'] ?? '' ) ) ),
		'protocol'          => trim( (string) ( $declared['protocol'] ?? ( $extracted['protocol'] ?? '' ) ) ),
		'issuer_cnpj'       => papelito_fiscal_key_normalize( (string) ( $declared['issuer_cnpj'] ?? ( $extracted['issuer_cnpj'] ?? '' ) ) ),
		'issuer_name'       => sanitize_text_field( (string) ( $declared['issuer_name'] ?? ( $extracted['issuer_name'] ?? '' ) ) ),
		'issued_at'         => $issued_at,
		'total_cents'       => (int) ( $declared['total_cents'] ?? 0 ),
		'has_xml'           => ! empty( $extracted ),
	);

	if ( $document['total_cents'] <= 0 && ! empty( $extracted['total'] ) ) {
		$document['total_cents'] = papelito_fiscal_amount_to_cents( (string) $extracted['total'] );
	}

	$comparable = $extracted;
	if ( ! empty( $extracted ) ) {
		$comparable['total_cents'] = papelito_fiscal_amount_to_cents( (string) ( $extracted['total'] ?? '' ) );
	}

	$flags = array_values(
		array_unique(
			array_merge(
				empty( $extracted ) ? array() : papelito_fiscal_compare_declared( $declared, $comparable ),
				papelito_fiscal_key_coherence_flags( $document ),
				papelito_fiscal_order_cross_flags( $document, $context )
			)
		)
	);

	$document['flags']            = $flags;
	$document['validation_level'] = papelito_fiscal_validation_level( $document, $flags, $context );
	$document['doc_status']       = empty( $flags ) ? 'recebida' : 'pendente_revisao';

	return $document;
}
