<?php
/**
 * Persistência das descrições do vendor, independente de inventário e catálogo.
 *
 * O par vendor/produto é único. Ausência de linha significa herdar o canônico;
 * falha SQL nunca significa ausência de personalização.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/** Nome completo da tabela de personalizações. */
function papelito_vendor_product_overrides_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'papelito_vendor_product_overrides';
}

/**
 * Confere o schema uma vez por requisição; o instalador força nova inspeção.
 */
function papelito_vendor_product_overrides_schema_ready( bool $refresh = false ): bool {
	global $wpdb;
	static $ready = null;

	if ( null !== $ready && ! $refresh ) {
		return $ready;
	}

	$table = papelito_vendor_product_overrides_table_name();
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	$ready = false;
	if ( $found !== $table ) {
		return false;
	}

	$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
	$needed  = array( 'vendor_id', 'product_id', 'description', 'description_enabled', 'created_at', 'updated_at' );
	if ( array_diff( $needed, $columns ) ) {
		return false;
	}

	$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
	$keys    = array();
	foreach ( $indexes as $index ) {
		$keys[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
	}
	$ready = array(
		1 => 'vendor_id',
		2 => 'product_id',
	) === ( $keys['PRIMARY'] ?? array() )
		&& array( 1 => 'product_id' ) === ( $keys['idx_product'] ?? array() );
	return $ready;
}

/**
 * Instala sem seed nem snapshots; retorna false para registrar schema incompleto.
 */
function papelito_vendor_product_overrides_install_table(): bool {
	global $wpdb;
	$table   = papelito_vendor_product_overrides_table_name();
	$collate = $wpdb->get_charset_collate();
	$sql     = "CREATE TABLE {$table} (
  vendor_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  description LONGTEXT NULL,
  description_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (vendor_id,product_id),
  KEY idx_product (product_id)
) {$collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	return papelito_vendor_product_overrides_schema_ready( true );
}

/** Erro público de persistência, sem conteúdo de SQL ou dados comerciais. */
function papelito_vendor_product_overrides_error(): WP_Error {
	return new WP_Error( 'papelito_customization_storage_error', 'Não foi possível acessar a descrição personalizada.', array( 'status' => 500 ) );
}

/**
 * Lê um lote por vendor, indexado por produto; vazio e erro são resultados distintos.
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_vendor_product_overrides_get_many( int $vendor_id, array $product_ids ): array|WP_Error {
	global $wpdb;
	if ( ! papelito_vendor_product_overrides_schema_ready() ) {
		return papelito_vendor_product_overrides_error();
	}
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	if ( ! $ids ) {
		return array();
	}
	$table        = papelito_vendor_product_overrides_table_name();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$params       = array_merge( array( $table, $vendor_id ), $ids );
	$rows         = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN contém apenas placeholders gerados; parâmetros são enviados como array.
		$wpdb->prepare( "SELECT product_id, description, description_enabled, created_at, updated_at FROM %i WHERE vendor_id = %d AND product_id IN ({$placeholders})", $params ),
		ARRAY_A
	);
	if ( '' !== $wpdb->last_error ) {
		return papelito_vendor_product_overrides_error();
	}
	$result = array();
	foreach ( $rows as $row ) {
		$row['description_enabled']         = '1' === (string) $row['description_enabled'];
		$result[ (int) $row['product_id'] ] = $row;
	}
	return $result;
}

/**
 * Substitui atomicamente o texto do par e a escolha de exibi-lo, preservando a data de criação.
 *
 * Com `$enabled` falso o texto fica guardado e a loja mostra a descrição da Papelito.
 *
 * @param int    $vendor_id   Seller dono do texto.
 * @param int    $product_id  Produto pai publicado ou produto comercial do kit.
 * @param string $description Texto já sanitizado.
 * @param bool   $enabled     Se a loja exibe o texto do vendor.
 *
 * @return true|WP_Error
 */
function papelito_vendor_product_override_upsert( int $vendor_id, int $product_id, string $description, bool $enabled = true ): bool|WP_Error {
	global $wpdb;
	if ( ! papelito_vendor_product_overrides_schema_ready() ) {
		return papelito_vendor_product_overrides_error();
	}
	$now = current_time( 'mysql', true );
	$sql = $wpdb->prepare(
		'INSERT INTO %i (vendor_id, product_id, description, description_enabled, created_at, updated_at) VALUES (%d, %d, %s, %d, %s, %s)
		ON DUPLICATE KEY UPDATE description = VALUES(description), description_enabled = VALUES(description_enabled), updated_at = VALUES(updated_at)',
		papelito_vendor_product_overrides_table_name(),
		$vendor_id,
		$product_id,
		$description,
		$enabled ? 1 : 0,
		$now,
		$now
	);
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado imediatamente acima, incluindo identificador da tabela.
	return false === $wpdb->query( $sql ) ? papelito_vendor_product_overrides_error() : true;
}

/**
 * Restaura o campo pela remoção do override; DELETE sem linha também tem sucesso.
 *
 * @return true|WP_Error
 */
function papelito_vendor_product_override_delete( int $vendor_id, int $product_id ): bool|WP_Error {
	global $wpdb;
	if ( ! papelito_vendor_product_overrides_schema_ready() ) {
		return papelito_vendor_product_overrides_error();
	}
	$result = $wpdb->delete(
		papelito_vendor_product_overrides_table_name(),
		array(
			'vendor_id'  => $vendor_id,
			'product_id' => $product_id,
		),
		array( '%d', '%d' )
	);
	return false === $result ? papelito_vendor_product_overrides_error() : true;
}

/** Apaga dependentes somente na exclusão definitiva do produto canônico. */
function papelito_vendor_product_overrides_delete_product( int $product_id ): void {
	global $wpdb;
	if ( 'product' === get_post_type( $product_id ) && papelito_vendor_product_overrides_schema_ready() ) {
		$wpdb->delete( papelito_vendor_product_overrides_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
	}
}

/** Apaga os overrides do usuário excluído, sem afetar outros vendors. */
function papelito_vendor_product_overrides_delete_user( int $vendor_id ): void {
	global $wpdb;
	if ( papelito_vendor_product_overrides_schema_ready() ) {
		$wpdb->delete( papelito_vendor_product_overrides_table_name(), array( 'vendor_id' => $vendor_id ), array( '%d' ) );
	}
}

add_action( 'before_delete_post', 'papelito_vendor_product_overrides_delete_product' );
add_action( 'deleted_user', 'papelito_vendor_product_overrides_delete_user' );
