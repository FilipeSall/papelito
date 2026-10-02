<?php
/**
 * Atributos do vendor por item vendável, separados da quantidade.
 *
 * Uma linha por vendor e produto ou variação. Só entra aqui o que pertence ao
 * vendor, vale por item e não é quantidade: saldo mora em `papelito_vendor_stock`,
 * que o pedido trava, e conteúdo do produto pai em `papelito_vendor_product_overrides`.
 * Cada atributo é coluna explícita; linha sem nenhum atributo é removida.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_CODE_MAX_LENGTH = 60;

/** Nome completo da tabela de atributos por item. */
function papelito_vendor_item_settings_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'papelito_vendor_item_settings';
}

/**
 * Indexa `SHOW INDEX` por nome e posição da coluna.
 *
 * @param array<int,array<string,mixed>> $indexes Linhas do `SHOW INDEX`.
 * @return array<string,array<int,string>>
 */
function papelito_vendor_item_settings_index_map( array $indexes ): array {
	$keys = array();
	foreach ( $indexes as $index ) {
		$keys[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
	}
	return $keys;
}

/** Inspeciona tabela, colunas e índices no banco, sem cache. */
function papelito_vendor_item_settings_inspect_schema(): bool {
	global $wpdb;
	$table = papelito_vendor_item_settings_table_name();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
		return false;
	}

	$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
	if ( array_diff( array( 'vendor_id', 'product_id', 'vendor_code', 'created_at', 'updated_at' ), $columns ) ) {
		return false;
	}

	$keys = papelito_vendor_item_settings_index_map( $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A ) );
	return array(
		1 => 'vendor_id',
		2 => 'product_id',
	) === ( $keys['PRIMARY'] ?? array() )
		&& array(
			1 => 'vendor_id',
			2 => 'vendor_code',
		) === ( $keys['idx_vendor_code'] ?? array() )
		&& array( 1 => 'product_id' ) === ( $keys['idx_product'] ?? array() );
}

/**
 * Confere o schema uma vez por requisição; o instalador força nova inspeção.
 *
 * @param bool $refresh Ignora o cache da requisição.
 */
function papelito_vendor_item_settings_schema_ready( bool $refresh = false ): bool {
	static $ready = null;

	if ( null === $ready || $refresh ) {
		$ready = papelito_vendor_item_settings_inspect_schema();
	}
	return $ready;
}

/**
 * Instala a tabela no charset padrão do banco; retorna false para registrar schema incompleto.
 */
function papelito_vendor_item_settings_install_table(): bool {
	global $wpdb;
	$table   = papelito_vendor_item_settings_table_name();
	$collate = $wpdb->get_charset_collate();
	$sql     = "CREATE TABLE {$table} (
  vendor_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  vendor_code VARCHAR(60) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (vendor_id,product_id),
  KEY idx_vendor_code (vendor_id,vendor_code),
  KEY idx_product (product_id)
) {$collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	return papelito_vendor_item_settings_schema_ready( true );
}

/** Erro público de persistência, sem SQL nem dado do vendor. */
function papelito_vendor_item_settings_error(): WP_Error {
	return new WP_Error( 'papelito_item_settings_storage_error', 'Não foi possível acessar o código do produto.', array( 'status' => 500 ) );
}

/**
 * Lê um lote do vendor indexado por produto; vazio e erro são resultados distintos.
 *
 * @param int   $vendor_id   Vendor dono dos atributos.
 * @param int[] $product_ids Produtos ou variações da linha de estoque.
 * @return array<int,array{vendor_code:?string,updated_at:string}>|WP_Error
 */
function papelito_vendor_item_settings_get_many( int $vendor_id, array $product_ids ): array|WP_Error {
	global $wpdb;
	if ( ! papelito_vendor_item_settings_schema_ready() ) {
		return papelito_vendor_item_settings_error();
	}
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	if ( ! $ids ) {
		return array();
	}
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$params       = array_merge( array( papelito_vendor_item_settings_table_name(), $vendor_id ), $ids );
	$rows         = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN contém apenas placeholders gerados; parâmetros são enviados como array.
		$wpdb->prepare( "SELECT product_id, vendor_code, updated_at FROM %i WHERE vendor_id = %d AND product_id IN ({$placeholders})", $params ),
		ARRAY_A
	);
	if ( '' !== $wpdb->last_error ) {
		return papelito_vendor_item_settings_error();
	}
	$result = array();
	foreach ( $rows as $row ) {
		$result[ (int) $row['product_id'] ] = array(
			'vendor_code' => null === $row['vendor_code'] ? null : (string) $row['vendor_code'],
			'updated_at'  => (string) $row['updated_at'],
		);
	}
	return $result;
}

/**
 * Grava o código do vendor para o item, preservando a data de criação; `null` apaga.
 *
 * @param int         $vendor_id   Vendor dono do código.
 * @param int         $product_id  Produto ou variação publicado.
 * @param string|null $vendor_code Código já normalizado, ou null para remover.
 * @return true|WP_Error
 */
function papelito_vendor_item_settings_save_code( int $vendor_id, int $product_id, ?string $vendor_code ): bool|WP_Error {
	global $wpdb;
	if ( ! papelito_vendor_item_settings_schema_ready() ) {
		return papelito_vendor_item_settings_error();
	}
	if ( null === $vendor_code ) {
		return papelito_vendor_item_settings_clear_code( $vendor_id, $product_id );
	}
	$now = current_time( 'mysql', true );
	$sql = $wpdb->prepare(
		'INSERT INTO %i (vendor_id, product_id, vendor_code, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)
		ON DUPLICATE KEY UPDATE vendor_code = VALUES(vendor_code), updated_at = VALUES(updated_at)',
		papelito_vendor_item_settings_table_name(),
		$vendor_id,
		$product_id,
		$vendor_code,
		$now,
		$now
	);
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado imediatamente acima, incluindo identificador da tabela.
	return false === $wpdb->query( $sql ) ? papelito_vendor_item_settings_error() : true;
}

/**
 * Remove o código do item e descarta a linha quando nenhum atributo sobra.
 *
 * Ao ganhar coluna nova, ela entra na condição do DELETE; senão apagar o código
 * levaria junto um atributo ainda preenchido.
 *
 * @param int $vendor_id  Vendor dono do código.
 * @param int $product_id Produto ou variação.
 * @return true|WP_Error
 */
function papelito_vendor_item_settings_clear_code( int $vendor_id, int $product_id ): bool|WP_Error {
	global $wpdb;
	$table   = papelito_vendor_item_settings_table_name();
	$cleared = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET vendor_code = NULL, updated_at = %s WHERE vendor_id = %d AND product_id = %d',
			$table,
			current_time( 'mysql', true ),
			$vendor_id,
			$product_id
		)
	);
	if ( false === $cleared ) {
		return papelito_vendor_item_settings_error();
	}
	$pruned = $wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE vendor_id = %d AND product_id = %d AND vendor_code IS NULL',
			$table,
			$vendor_id,
			$product_id
		)
	);
	return false === $pruned ? papelito_vendor_item_settings_error() : true;
}

/**
 * Apaga os atributos na exclusão definitiva do produto ou da variação.
 *
 * @param int $product_id Post em exclusão.
 */
function papelito_vendor_item_settings_delete_product( int $product_id ): void {
	global $wpdb;
	if ( in_array( get_post_type( $product_id ), array( 'product', 'product_variation' ), true ) && papelito_vendor_item_settings_schema_ready() ) {
		$wpdb->delete( papelito_vendor_item_settings_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
	}
}

/**
 * Apaga os atributos do usuário excluído, sem afetar outros vendors.
 *
 * @param int $vendor_id Usuário excluído.
 */
function papelito_vendor_item_settings_delete_user( int $vendor_id ): void {
	global $wpdb;
	if ( papelito_vendor_item_settings_schema_ready() ) {
		$wpdb->delete( papelito_vendor_item_settings_table_name(), array( 'vendor_id' => $vendor_id ), array( '%d' ) );
	}
}

add_action( 'before_delete_post', 'papelito_vendor_item_settings_delete_product' );
add_action( 'deleted_user', 'papelito_vendor_item_settings_delete_user' );
