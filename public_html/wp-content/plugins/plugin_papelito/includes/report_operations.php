<?php
/**
 * Registro das operações da página de Relatórios: cada exportação e cada lote de importação.
 *
 * Responde "quem tirou que dado, quando, com que recorte" e "o que entrou no catálogo por
 * planilha". É a fonte da Atividade recente do painel e da trava contra importar o mesmo
 * arquivo duas vezes, que procura aqui o hash do arquivo.
 *
 * O registro guarda parâmetros e contagens, nunca o conteúdo exportado: a linha de uma
 * exportação de usuários diz o recorte e quantas contas saíram, não quais. O arquivo
 * gerado continua sendo entregue na hora e descartado.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_REPORT_OPERATIONS_TABLE      = 'papelito_report_operations';
const PAPELITO_REPORT_OPERATION_RUNNING     = 'running';
const PAPELITO_REPORT_OPERATION_DONE        = 'done';
const PAPELITO_REPORT_OPERATION_PARTIAL     = 'partial';
const PAPELITO_REPORT_OPERATION_EMPTY       = 'empty';
const PAPELITO_REPORT_OPERATION_FAILED      = 'failed';
const PAPELITO_REPORT_OPERATION_STALE_AFTER = HOUR_IN_SECONDS;
const PAPELITO_REPORT_OPERATIONS_MAX_LIST   = 50;

/**
 * Nome completo (com prefixo) da tabela de operações de relatório.
 */
function papelito_report_operations_table(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_REPORT_OPERATIONS_TABLE;
}

/**
 * Cria a tabela de operações via dbDelta. Idempotente.
 */
function papelito_report_operations_install_tables(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = papelito_report_operations_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(12) NOT NULL,
  report VARCHAR(24) NOT NULL,
  title VARCHAR(190) NOT NULL,
  params LONGTEXT NULL,
  status VARCHAR(12) NOT NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_count INT UNSIGNED NOT NULL DEFAULT 0,
  ignored_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  lot_code VARCHAR(20) NULL DEFAULT NULL,
  file_hash CHAR(64) NULL DEFAULT NULL,
  detail LONGTEXT NULL,
  error_message VARCHAR(255) NULL DEFAULT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_created (created_at),
  KEY idx_file_hash (file_hash)
) {$charset_collate};"
	);
}

/**
 * Abre uma operação em andamento e devolve o id; 0 quando a gravação falha.
 *
 * Quem abre precisa fechar com `papelito_report_operation_finish()`. Operação que fica
 * aberta por mais de uma hora aparece como falha na leitura, para um fatal no meio de um
 * lote não deixar uma linha eternamente "processando".
 *
 * @param array{kind:string,report:string,title:string,params?:array<string,mixed>,file_hash?:string} $data Identidade da operação.
 */
function papelito_report_operation_start( array $data ): int {
	global $wpdb;

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_report_operations_table(),
		array(
			'kind'       => sanitize_key( (string) $data['kind'] ),
			'report'     => sanitize_key( (string) $data['report'] ),
			'title'      => mb_substr( sanitize_text_field( (string) $data['title'] ), 0, 190, 'UTF-8' ),
			'params'     => wp_json_encode( (array) ( $data['params'] ?? array() ) ),
			'status'     => PAPELITO_REPORT_OPERATION_RUNNING,
			'file_hash'  => isset( $data['file_hash'] ) ? (string) $data['file_hash'] : null,
			'user_id'    => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
		)
	);

	return false === $inserted ? 0 : (int) $wpdb->insert_id;
}

/**
 * Fecha a operação com o desfecho e as contagens. Falha de escrita é engolida: o registro
 * observa a operação e nunca pode ser o motivo de ela falhar.
 *
 * @param int                 $id   Operação aberta.
 * @param array<string,mixed> $data status, rows, created, ignored, errors, lot, detail, error.
 */
function papelito_report_operation_finish( int $id, array $data ): void {
	global $wpdb;

	if ( $id <= 0 ) {
		return;
	}

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_report_operations_table(),
		array(
			'status'        => sanitize_key( (string) ( $data['status'] ?? PAPELITO_REPORT_OPERATION_DONE ) ),
			'row_count'     => max( 0, (int) ( $data['rows'] ?? 0 ) ),
			'created_count' => max( 0, (int) ( $data['created'] ?? 0 ) ),
			'ignored_count' => max( 0, (int) ( $data['ignored'] ?? 0 ) ),
			'error_count'   => max( 0, (int) ( $data['errors'] ?? 0 ) ),
			'lot_code'      => isset( $data['lot'] ) ? (string) $data['lot'] : null,
			'detail'        => isset( $data['detail'] ) ? wp_json_encode( $data['detail'] ) : null,
			'error_message' => isset( $data['error'] ) ? mb_substr( sanitize_text_field( (string) $data['error'] ), 0, 255, 'UTF-8' ) : null,
			'finished_at'   => current_time( 'mysql', true ),
		),
		array( 'id' => $id )
	);
}

/**
 * Registra de uma vez uma operação que começa e termina na mesma requisição, como uma exportação.
 *
 * @param array<string,mixed> $identity Mesmo formato de `papelito_report_operation_start()`.
 * @param array<string,mixed> $outcome  Mesmo formato de `papelito_report_operation_finish()`.
 */
function papelito_report_operation_record( array $identity, array $outcome ): void {
	papelito_report_operation_finish( papelito_report_operation_start( $identity ), $outcome );
}

/**
 * Código legível do lote de importação, derivado do id da operação (`L-0007`).
 *
 * @param int $id Operação de importação.
 */
function papelito_report_operation_lot_code( int $id ): string {
	return 'L-' . str_pad( (string) $id, 4, '0', STR_PAD_LEFT );
}

/**
 * Status exibível: operação aberta há mais tempo que o limite foi interrompida.
 *
 * @param array<string,mixed> $row Linha crua.
 */
function papelito_report_operation_effective_status( array $row ): string {
	$status = (string) $row['status'];
	if ( PAPELITO_REPORT_OPERATION_RUNNING !== $status ) {
		return $status;
	}
	$started = strtotime( (string) $row['created_at'] . ' UTC' );

	return false !== $started && time() - $started > PAPELITO_REPORT_OPERATION_STALE_AFTER
		? PAPELITO_REPORT_OPERATION_FAILED
		: $status;
}

/**
 * Converte a linha crua no formato público do painel. Não inclui `detail`, que é do lote.
 *
 * @param array<string,mixed> $row Linha do banco.
 * @return array<string,mixed>
 */
function papelito_report_operation_shape( array $row ): array {
	$params = json_decode( (string) ( $row['params'] ?? '' ), true );
	$user   = get_userdata( (int) $row['user_id'] );
	$status = papelito_report_operation_effective_status( $row );
	$error  = null === $row['error_message'] ? null : (string) $row['error_message'];

	if ( PAPELITO_REPORT_OPERATION_FAILED === $status && null === $error ) {
		$error = 'A operação foi interrompida antes de terminar.';
	}

	return array(
		'id'      => (int) $row['id'],
		'kind'    => (string) $row['kind'],
		'report'  => (string) $row['report'],
		'title'   => (string) $row['title'],
		'params'  => is_array( $params ) ? $params : array(),
		'status'  => $status,
		'rows'    => (int) $row['row_count'],
		'created' => (int) $row['created_count'],
		'ignored' => (int) $row['ignored_count'],
		'errors'  => (int) $row['error_count'],
		'lot'     => null === $row['lot_code'] ? null : (string) $row['lot_code'],
		'error'   => $error,
		'at'      => gmdate( 'c', (int) strtotime( (string) $row['created_at'] . ' UTC' ) ),
		'byName'  => $user ? (string) $user->display_name : '',
	);
}

/**
 * Operações mais recentes, da mais nova para a mais antiga.
 *
 * @param int $limit Quantidade, até o teto da listagem.
 * @return array<int,array<string,mixed>>
 */
function papelito_report_operations_list( int $limit ): array {
	global $wpdb;

	$limit = min( PAPELITO_REPORT_OPERATIONS_MAX_LIST, max( 1, $limit ) );
	$table = papelito_report_operations_table();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array_map( 'papelito_report_operation_shape', is_array( $rows ) ? $rows : array() );
}

/**
 * Lote que já importou este arquivo, com código, data e quantos produtos criou; null quando nenhum.
 *
 * Só conta lote que criou alguma coisa: arquivo recusado ou lote que falhou inteiro pode ser
 * reenviado sem aviso.
 *
 * @param string $file_hash SHA-256 do arquivo.
 * @return array{lot:string,at:string,created:int}|null
 */
function papelito_report_operation_find_import( string $file_hash ): ?array {
	global $wpdb;

	$table = papelito_report_operations_table();
	$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT lot_code, created_at, created_count FROM {$table} WHERE kind = 'import' AND file_hash = %s AND created_count > 0 AND status IN ('done','partial') ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$file_hash
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return null;
	}

	return array(
		'lot'     => (string) $row['lot_code'],
		'at'      => gmdate( 'c', (int) strtotime( (string) $row['created_at'] . ' UTC' ) ),
		'created' => (int) $row['created_count'],
	);
}

/**
 * GET da listagem para a Atividade recente.
 *
 * @param WP_REST_Request $request Parâmetro `limit`.
 */
function papelito_report_operations_endpoint( WP_REST_Request $request ): WP_REST_Response {
	$limit = absint( $request->get_param( 'limit' ) );

	return new WP_REST_Response( array( 'items' => papelito_report_operations_list( $limit > 0 ? $limit : 10 ) ), 200 );
}

/** Registra a rota de leitura, restrita a administradores. */
function papelito_report_operations_register_routes(): void {
	register_rest_route(
		'papelito/v1/admin',
		'/reports/operations',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'papelito_report_operations_endpoint',
			'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
		)
	);
}

add_action( 'rest_api_init', 'papelito_report_operations_register_routes' );
