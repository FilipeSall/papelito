<?php
/**
 * Taxonomia própria de produtos — categoria principal, subcategorias e coleções.
 *
 * Substitui `product_cat` do WooCommerce como fonte das regras de catálogo. O
 * desenho e o porquê estão em `docs/product-taxonomy-migration.md` (raiz do
 * workspace). Três invariantes carregam o módulo:
 *
 * 1. Um produto tem NO MÁXIMO uma categoria principal — garantido pela PRIMARY
 *    KEY em `product_id` na tabela de vínculo, não por convenção de código.
 * 2. Toda subcategoria de um produto pertence à categoria daquele produto.
 *    Trocar a categoria limpa as subcategorias, na mesma transação.
 * 3. Slug de subcategoria é único POR CATEGORIA, não globalmente. Foi o slug
 *    global do WordPress que produziu `slim-piteiras` e `slim-filtros`.
 *
 * "Pelo menos uma categoria" não é constraint de banco (o projeto não usa chave
 * estrangeira física): vira gate de publicação e relatório de integridade.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_CATEGORIES_TABLE' ) ) {
	define( 'PAPELITO_CATEGORIES_TABLE', 'papelito_categories' );
}

if ( ! defined( 'PAPELITO_SUBCATEGORIES_TABLE' ) ) {
	define( 'PAPELITO_SUBCATEGORIES_TABLE', 'papelito_subcategories' );
}

if ( ! defined( 'PAPELITO_PRODUCT_CATEGORY_TABLE' ) ) {
	define( 'PAPELITO_PRODUCT_CATEGORY_TABLE', 'papelito_product_category' );
}

if ( ! defined( 'PAPELITO_PRODUCT_SUBCATEGORY_TABLE' ) ) {
	define( 'PAPELITO_PRODUCT_SUBCATEGORY_TABLE', 'papelito_product_subcategory' );
}

if ( ! defined( 'PAPELITO_PRODUCT_COLLECTION_TABLE' ) ) {
	define( 'PAPELITO_PRODUCT_COLLECTION_TABLE', 'papelito_product_collection' );
}

if ( ! defined( 'PAPELITO_CATEGORY_NOT_FOUND_MESSAGE' ) ) {
	define( 'PAPELITO_CATEGORY_NOT_FOUND_MESSAGE', 'Categoria não encontrada.' );
}

// ------------------------------------------------------------------
// Schema
// ------------------------------------------------------------------

/**
 * Resolve nomes completos (com prefixo) das tabelas de taxonomia.
 *
 * @return array{categories:string,subcategories:string,product_category:string,product_subcategory:string,product_collection:string}
 */
function papelito_product_taxonomy_table_names() {
	global $wpdb;

	return array(
		'categories'          => $wpdb->prefix . PAPELITO_CATEGORIES_TABLE,
		'subcategories'       => $wpdb->prefix . PAPELITO_SUBCATEGORIES_TABLE,
		'product_category'    => $wpdb->prefix . PAPELITO_PRODUCT_CATEGORY_TABLE,
		'product_subcategory' => $wpdb->prefix . PAPELITO_PRODUCT_SUBCATEGORY_TABLE,
		'product_collection'  => $wpdb->prefix . PAPELITO_PRODUCT_COLLECTION_TABLE,
	);
}

/**
 * Cria/atualiza as tabelas de taxonomia via dbDelta.
 *
 * Chamado pelo bootstrap de migration em `plugin_papelito.php` quando
 * `papelito_db_version` for inferior à versão atual.
 *
 * @return void
 */
function papelito_product_taxonomy_install_tables() {
	global $wpdb;

	$tables          = papelito_product_taxonomy_table_names();
	$charset_collate = $wpdb->get_charset_collate();

	$categories_sql = "CREATE TABLE {$tables['categories']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(96) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL DEFAULT NULL,
  icon_attachment_id BIGINT UNSIGNED NULL DEFAULT NULL,
  seo_title VARCHAR(180) NULL DEFAULT NULL,
  seo_description VARCHAR(320) NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  archived_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_active_sort (is_active, sort_order, id)
) {$charset_collate};";

	$subcategories_sql = "CREATE TABLE {$tables['subcategories']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(96) NOT NULL,
  name VARCHAR(120) NOT NULL,
  facet VARCHAR(48) NOT NULL DEFAULT 'geral',
  description TEXT NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  archived_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_category_slug (category_id, slug),
  KEY idx_category_facet_sort (category_id, facet, sort_order, id)
) {$charset_collate};";

	$product_category_sql = "CREATE TABLE {$tables['product_category']} (
  product_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (product_id),
  KEY idx_category (category_id, product_id)
) {$charset_collate};";

	$product_subcategory_sql = "CREATE TABLE {$tables['product_subcategory']} (
  product_id BIGINT UNSIGNED NOT NULL,
  subcategory_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY  (product_id, subcategory_id),
  KEY idx_subcategory (subcategory_id, product_id)
) {$charset_collate};";

	$product_collection_sql = "CREATE TABLE {$tables['product_collection']} (
  product_id BIGINT UNSIGNED NOT NULL,
  collection_slug VARCHAR(48) NOT NULL,
  PRIMARY KEY  (product_id, collection_slug),
  KEY idx_collection (collection_slug, product_id)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( $categories_sql );
	dbDelta( $subcategories_sql );
	dbDelta( $product_category_sql );
	dbDelta( $product_subcategory_sql );
	dbDelta( $product_collection_sql );
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Coleções curadas aceitas em `papelito_product_set_collections`.
 *
 * Lista fixa e curta de propósito: são recortes comerciais, não entidade. Se um
 * dia a operação precisar criar coleção pela UI, isto vira tabela.
 *
 * @return string[]
 */
function papelito_curated_collections() {
	return (array) apply_filters( 'papelito_curated_collections', array( 'premium' ) );
}

/**
 * Normaliza um slug de categoria/subcategoria.
 *
 * @param string $value Valor cru.
 * @return string Slug normalizado, possivelmente vazio.
 */
function papelito_taxonomy_slugify( $value ) {
	$slug = sanitize_title( remove_accents( (string) $value ) );

	return substr( $slug, 0, 96 );
}

/**
 * Timestamp UTC no formato do MySQL.
 *
 * @return string
 */
function papelito_taxonomy_now() {
	return current_time( 'mysql', true );
}

/**
 * Sinaliza mudança na taxonomia para quem cacheia opções de filtro.
 *
 * @param string $scope   Escopo alterado (`category`, `subcategory`, `product`).
 * @param int    $subject Id da categoria, subcategoria ou produto.
 * @return void
 */
function papelito_product_taxonomy_touch( $scope, $subject = 0 ) {
	$version = (int) get_option( 'papelito_product_taxonomy_version', 0 );

	update_option( 'papelito_product_taxonomy_version', $version + 1, true );

	do_action( 'papelito_product_taxonomy_changed', (string) $scope, (int) $subject );
}

/**
 * Versão corrente do cache de taxonomia.
 *
 * @return int
 */
function papelito_product_taxonomy_version() {
	return (int) get_option( 'papelito_product_taxonomy_version', 0 );
}

/**
 * URL da imagem/ícone de uma categoria.
 *
 * @param int|null $attachment_id Id do anexo.
 * @return string|null
 */
function papelito_taxonomy_icon_url( $attachment_id ) {
	$attachment_id = (int) $attachment_id;

	if ( $attachment_id <= 0 ) {
		return null;
	}

	$url = wp_get_attachment_url( $attachment_id );

	return is_string( $url ) && '' !== $url ? $url : null;
}

/**
 * Confirma que o id aponta para um produto do WooCommerce.
 *
 * Variação não é aceita: variação herda a classificação do pai, exatamente como
 * já acontecia com `product_cat`.
 *
 * @param int $product_id Id candidato.
 * @return bool
 */
function papelito_taxonomy_is_product( $product_id ) {
	$product_id = (int) $product_id;

	return $product_id > 0 && 'product' === get_post_type( $product_id );
}

/**
 * Converte uma linha crua de categoria no formato público.
 *
 * @param array<string,mixed>|null $row Linha do banco.
 * @return array<string,mixed>|null
 */
function papelito_category_shape( $row ) {
	if ( ! is_array( $row ) || empty( $row['id'] ) ) {
		return null;
	}

	return array(
		'id'               => (int) $row['id'],
		'slug'             => (string) $row['slug'],
		'name'             => (string) $row['name'],
		'description'      => null === $row['description'] ? '' : (string) $row['description'],
		'iconAttachmentId' => null === $row['icon_attachment_id'] ? null : (int) $row['icon_attachment_id'],
		'seoTitle'         => null === $row['seo_title'] ? '' : (string) $row['seo_title'],
		'seoDescription'   => null === $row['seo_description'] ? '' : (string) $row['seo_description'],
		'sortOrder'        => (int) $row['sort_order'],
		'isActive'         => 1 === (int) $row['is_active'],
		'archivedAt'       => null === $row['archived_at'] ? null : (string) $row['archived_at'],
	);
}

/**
 * Converte uma linha crua de subcategoria no formato público.
 *
 * @param array<string,mixed>|null $row Linha do banco.
 * @return array<string,mixed>|null
 */
function papelito_subcategory_shape( $row ) {
	if ( ! is_array( $row ) || empty( $row['id'] ) ) {
		return null;
	}

	return array(
		'id'          => (int) $row['id'],
		'categoryId'  => (int) $row['category_id'],
		'slug'        => (string) $row['slug'],
		'name'        => (string) $row['name'],
		'facet'       => (string) $row['facet'],
		'description' => null === $row['description'] ? '' : (string) $row['description'],
		'sortOrder'   => (int) $row['sort_order'],
		'isActive'    => 1 === (int) $row['is_active'],
		'archivedAt'  => null === $row['archived_at'] ? null : (string) $row['archived_at'],
	);
}

// ------------------------------------------------------------------
// Categorias
// ------------------------------------------------------------------

/**
 * Busca uma categoria pelo id.
 *
 * @param int $category_id Id da categoria.
 * @return array<string,mixed>|null
 */
function papelito_category_get( $category_id ) {
	global $wpdb;

	$category_id = (int) $category_id;

	if ( $category_id <= 0 ) {
		return null;
	}

	$tables = papelito_product_taxonomy_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['categories']} WHERE id = %d", $category_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return papelito_category_shape( $row );
}

/**
 * Busca uma categoria pelo slug.
 *
 * @param string $slug Slug da categoria.
 * @return array<string,mixed>|null
 */
function papelito_category_get_by_slug( $slug ) {
	global $wpdb;

	$slug = papelito_taxonomy_slugify( $slug );

	if ( '' === $slug ) {
		return null;
	}

	$tables = papelito_product_taxonomy_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['categories']} WHERE slug = %s", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return papelito_category_shape( $row );
}

/**
 * Lista categorias ordenadas por `sort_order` e desempate estável por id.
 *
 * @param array<string,mixed> $args `active_only` (bool, default true), `include_archived` (bool, default false).
 * @return array<int,array<string,mixed>>
 */
function papelito_categories_list( array $args = array() ) {
	global $wpdb;

	$active_only      = ! array_key_exists( 'active_only', $args ) || (bool) $args['active_only'];
	$include_archived = ! empty( $args['include_archived'] );
	$tables           = papelito_product_taxonomy_table_names();
	$where            = array( '1=1' );

	if ( $active_only ) {
		$where[] = 'is_active = 1';
	}

	if ( ! $include_archived ) {
		$where[] = 'archived_at IS NULL';
	}

	$sql  = "SELECT * FROM {$tables['categories']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY sort_order ASC, id ASC';
	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return array_values( array_filter( array_map( 'papelito_category_shape', is_array( $rows ) ? $rows : array() ) ) );
}

/**
 * Cria uma categoria principal.
 *
 * @param array<string,mixed> $data `name` (obrigatório), `slug`, `description`, `iconAttachmentId`, `seoTitle`, `seoDescription`, `sortOrder`, `isActive`.
 * @return int|WP_Error Id criado.
 */
function papelito_category_create( array $data ) {
	global $wpdb;

	$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

	if ( '' === $name ) {
		return new WP_Error( 'papelito_category_name_required', 'Informe o nome da categoria.', array( 'status' => 422 ) );
	}

	$slug = papelito_taxonomy_slugify( $data['slug'] ?? $name );

	if ( '' === $slug ) {
		return new WP_Error( 'papelito_category_slug_invalid', 'Slug de categoria inválido.', array( 'status' => 422 ) );
	}

	if ( null !== papelito_category_get_by_slug( $slug ) ) {
		return new WP_Error( 'papelito_category_slug_taken', 'Já existe uma categoria com esse slug.', array( 'status' => 409 ) );
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['categories'],
		array(
			'slug'               => $slug,
			'name'               => $name,
			'description'        => isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : null,
			'icon_attachment_id' => isset( $data['iconAttachmentId'] ) && (int) $data['iconAttachmentId'] > 0 ? (int) $data['iconAttachmentId'] : null,
			'seo_title'          => isset( $data['seoTitle'] ) ? sanitize_text_field( (string) $data['seoTitle'] ) : null,
			'seo_description'    => isset( $data['seoDescription'] ) ? sanitize_text_field( (string) $data['seoDescription'] ) : null,
			'sort_order'         => isset( $data['sortOrder'] ) ? (int) $data['sortOrder'] : 0,
			'is_active'          => array_key_exists( 'isActive', $data ) && ! $data['isActive'] ? 0 : 1,
			'created_at'         => $now,
			'updated_at'         => $now,
		)
	);

	if ( ! $inserted ) {
		return new WP_Error( 'papelito_category_create_failed', 'Não foi possível criar a categoria.', array( 'status' => 500 ) );
	}

	$category_id = (int) $wpdb->insert_id;

	papelito_product_taxonomy_touch( 'category', $category_id );

	return $category_id;
}

/**
 * Extrai o nome da categoria do payload, exigindo que não seja vazio.
 *
 * @param array<string,mixed> $data Campos a atualizar.
 * @return array<string,mixed>|WP_Error
 */
function papelito_category_update_name_field( array $data ) {
	if ( ! array_key_exists( 'name', $data ) ) {
		return array();
	}

	$name = sanitize_text_field( (string) $data['name'] );

	if ( '' === $name ) {
		return new WP_Error( 'papelito_category_name_required', 'Informe o nome da categoria.', array( 'status' => 422 ) );
	}

	return array( 'name' => $name );
}

/**
 * Extrai o slug da categoria, garantindo unicidade contra as demais.
 *
 * @param array<string,mixed> $category Categoria sendo editada.
 * @param array<string,mixed> $data     Campos a atualizar.
 * @return array<string,mixed>|WP_Error
 */
function papelito_category_update_slug_field( array $category, array $data ) {
	if ( ! array_key_exists( 'slug', $data ) ) {
		return array();
	}

	$slug = papelito_taxonomy_slugify( $data['slug'] );

	if ( '' === $slug ) {
		return new WP_Error( 'papelito_category_slug_invalid', 'Slug de categoria inválido.', array( 'status' => 422 ) );
	}

	$existing = papelito_category_get_by_slug( $slug );

	if ( null !== $existing && $existing['id'] !== $category['id'] ) {
		return new WP_Error( 'papelito_category_slug_taken', 'Já existe uma categoria com esse slug.', array( 'status' => 409 ) );
	}

	if ( $slug !== $category['slug'] && ( papelito_category_product_counts()[ $category['id'] ]['total'] ?? 0 ) > 0 ) {
		return new WP_Error( 'papelito_category_slug_locked', 'O slug não pode mudar enquanto houver produtos classificados nesta categoria.', array( 'status' => 409 ) );
	}

	return array( 'slug' => $slug );
}

/**
 * Extrai os campos opcionais da categoria (descrição, SEO, ícone, ordem).
 *
 * @param array<string,mixed> $data Campos a atualizar.
 * @return array<string,mixed>
 */
function papelito_category_update_optional_fields( array $data ) {
	$fields = array();

	if ( array_key_exists( 'description', $data ) ) {
		$fields['description'] = wp_kses_post( (string) $data['description'] );
	}

	if ( array_key_exists( 'iconAttachmentId', $data ) ) {
		$fields['icon_attachment_id'] = (int) $data['iconAttachmentId'] > 0 ? (int) $data['iconAttachmentId'] : null;
	}

	if ( array_key_exists( 'seoTitle', $data ) ) {
		$fields['seo_title'] = sanitize_text_field( (string) $data['seoTitle'] );
	}

	if ( array_key_exists( 'seoDescription', $data ) ) {
		$fields['seo_description'] = sanitize_text_field( (string) $data['seoDescription'] );
	}

	if ( array_key_exists( 'sortOrder', $data ) ) {
		$fields['sort_order'] = (int) $data['sortOrder'];
	}

	return $fields;
}

/**
 * Extrai o estado ativo, barrando a desativação de categoria ainda em uso.
 *
 * @param array<string,mixed> $category Categoria sendo editada.
 * @param array<string,mixed> $data     Campos a atualizar.
 * @return array<string,mixed>|WP_Error
 */
function papelito_category_update_active_field( array $category, array $data ) {
	if ( ! array_key_exists( 'isActive', $data ) ) {
		return array();
	}

	if ( ! $data['isActive'] && ( papelito_category_product_counts()[ $category['id'] ]['total'] ?? 0 ) > 0 ) {
		return new WP_Error( 'papelito_category_in_use', 'Reclassifique os produtos antes de desativar a categoria.', array( 'status' => 409 ) );
	}

	return array( 'is_active' => $data['isActive'] ? 1 : 0 );
}

/**
 * Atualiza campos de uma categoria. Só o que vier em `$data` é tocado.
 *
 * @param int                 $category_id Id da categoria.
 * @param array<string,mixed> $data        Campos a atualizar.
 * @return true|WP_Error
 */
function papelito_category_update( $category_id, array $data ) {
	global $wpdb;

	$category = papelito_category_get( $category_id );

	if ( null === $category ) {
		return new WP_Error( 'papelito_category_not_found', PAPELITO_CATEGORY_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$fields = array();

	foreach ( array(
		papelito_category_update_name_field( $data ),
		papelito_category_update_slug_field( $category, $data ),
		papelito_category_update_optional_fields( $data ),
		papelito_category_update_active_field( $category, $data ),
	) as $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fields = array_merge( $fields, $result );
	}

	if ( empty( $fields ) ) {
		return true;
	}

	$fields['updated_at'] = papelito_taxonomy_now();
	$tables               = papelito_product_taxonomy_table_names();

	$wpdb->update( $tables['categories'], $fields, array( 'id' => $category['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	papelito_product_taxonomy_touch( 'category', $category['id'] );

	return true;
}

/**
 * Conta todos os produtos vinculados a uma categoria.
 *
 * @param int $category_id Id da categoria.
 * @return int
 */
function papelito_category_product_count( $category_id ) {
	global $wpdb;

	$category_id = (int) $category_id;

	if ( $category_id <= 0 ) {
		return 0;
	}

	$tables = papelito_product_taxonomy_table_names();

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT pc.product_id) FROM {$tables['product_category']} pc INNER JOIN {$wpdb->posts} p ON p.ID = pc.product_id AND p.post_type = 'product' WHERE pc.category_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$category_id
		)
	);
}

/**
 * Arquiva (soft delete) uma categoria.
 *
 * Recusa quando há produto publicado vinculado: relatório e pedido histórico
 * dependem do rótulo, e nenhum produto pode ficar sem categoria em silêncio.
 *
 * @param int $category_id Id da categoria.
 * @return true|WP_Error
 */
function papelito_category_archive( $category_id ) {
	global $wpdb;

	$category = papelito_category_get( $category_id );

	if ( null === $category ) {
		return new WP_Error( 'papelito_category_not_found', PAPELITO_CATEGORY_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$in_use = papelito_category_product_count( $category['id'] );

	if ( $in_use > 0 ) {
		return new WP_Error(
			'papelito_category_in_use',
			sprintf( 'A categoria tem %d produto(s) publicado(s). Reclassifique antes de arquivar.', $in_use ),
			array(
				'status'       => 409,
				'productCount' => $in_use,
			)
		);
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['categories'],
		array(
			'is_active'   => 0,
			'archived_at' => $now,
			'updated_at'  => $now,
		),
		array( 'id' => $category['id'] )
	);

	papelito_product_taxonomy_touch( 'category', $category['id'] );

	return true;
}

/**
 * Reverte o arquivamento de uma categoria.
 *
 * @param int $category_id Id da categoria.
 * @return true|WP_Error
 */
function papelito_category_restore( $category_id ) {
	global $wpdb;

	$category = papelito_category_get( $category_id );

	if ( null === $category ) {
		return new WP_Error( 'papelito_category_not_found', PAPELITO_CATEGORY_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$tables = papelito_product_taxonomy_table_names();

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['categories'],
		array(
			'is_active'   => 1,
			'archived_at' => null,
			'updated_at'  => papelito_taxonomy_now(),
		),
		array( 'id' => $category['id'] )
	);

	papelito_product_taxonomy_touch( 'category', $category['id'] );

	return true;
}

/**
 * Reordena categorias na ordem exata dos ids informados.
 *
 * Ids omitidos mantêm a ordem relativa depois dos informados.
 *
 * @param int[] $ordered_ids Ids na ordem desejada.
 * @return true|WP_Error
 */
function papelito_categories_reorder( array $ordered_ids ) {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ordered_ids ) ) ) );

	if ( empty( $ids ) ) {
		return new WP_Error( 'papelito_reorder_empty', 'Informe ao menos uma categoria.', array( 'status' => 422 ) );
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	foreach ( $ids as $position => $category_id ) {
		if ( null === papelito_category_get( $category_id ) ) {
			return new WP_Error( 'papelito_category_not_found', 'Categoria não encontrada: ' . $category_id, array( 'status' => 404 ) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tables['categories'],
			array(
				'sort_order' => $position,
				'updated_at' => $now,
			),
			array( 'id' => $category_id )
		);
	}

	papelito_product_taxonomy_touch( 'category', 0 );

	return true;
}

// ------------------------------------------------------------------
// Subcategorias
// ------------------------------------------------------------------

/**
 * Busca uma subcategoria pelo id.
 *
 * @param int $subcategory_id Id da subcategoria.
 * @return array<string,mixed>|null
 */
function papelito_subcategory_get( $subcategory_id ) {
	global $wpdb;

	$subcategory_id = (int) $subcategory_id;

	if ( $subcategory_id <= 0 ) {
		return null;
	}

	$tables = papelito_product_taxonomy_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['subcategories']} WHERE id = %d", $subcategory_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return papelito_subcategory_shape( $row );
}

/**
 * Busca uma subcategoria pelo par (categoria, slug).
 *
 * @param int    $category_id Id da categoria.
 * @param string $slug        Slug da subcategoria.
 * @return array<string,mixed>|null
 */
function papelito_subcategory_get_by_slug( $category_id, $slug ) {
	global $wpdb;

	$category_id = (int) $category_id;
	$slug        = papelito_taxonomy_slugify( $slug );

	if ( $category_id <= 0 || '' === $slug ) {
		return null;
	}

	$tables = papelito_product_taxonomy_table_names();
	$row    = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT * FROM {$tables['subcategories']} WHERE category_id = %d AND slug = %s", $category_id, $slug ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	return papelito_subcategory_shape( $row );
}

/**
 * Lista subcategorias de uma categoria, agrupáveis por faceta.
 *
 * @param int                 $category_id Id da categoria.
 * @param array<string,mixed> $args        `active_only` (bool, default true), `include_archived` (bool, default false).
 * @return array<int,array<string,mixed>>
 */
function papelito_subcategories_list( $category_id, array $args = array() ) {
	global $wpdb;

	$category_id = (int) $category_id;

	if ( $category_id <= 0 ) {
		return array();
	}

	$active_only      = ! array_key_exists( 'active_only', $args ) || (bool) $args['active_only'];
	$include_archived = ! empty( $args['include_archived'] );
	$tables           = papelito_product_taxonomy_table_names();
	$where            = array( 'category_id = %d' );

	if ( $active_only ) {
		$where[] = 'is_active = 1';
	}

	if ( ! $include_archived ) {
		$where[] = 'archived_at IS NULL';
	}

	$sql  = "SELECT * FROM {$tables['subcategories']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY facet ASC, sort_order ASC, id ASC';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $category_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return array_values( array_filter( array_map( 'papelito_subcategory_shape', is_array( $rows ) ? $rows : array() ) ) );
}

/**
 * Cria uma subcategoria dentro de uma categoria.
 *
 * @param array<string,mixed> $data `categoryId` e `name` obrigatórios; `slug`, `facet`, `description`, `sortOrder`, `isActive`.
 * @return int|WP_Error Id criado.
 */
function papelito_subcategory_create( array $data ) {
	global $wpdb;

	$category_id = (int) ( $data['categoryId'] ?? 0 );
	$category    = papelito_category_get( $category_id );

	if ( null === $category ) {
		return new WP_Error( 'papelito_category_not_found', PAPELITO_CATEGORY_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

	if ( '' === $name ) {
		return new WP_Error( 'papelito_subcategory_name_required', 'Informe o nome da subcategoria.', array( 'status' => 422 ) );
	}

	$slug = papelito_taxonomy_slugify( $data['slug'] ?? $name );

	if ( '' === $slug ) {
		return new WP_Error( 'papelito_subcategory_slug_invalid', 'Slug de subcategoria inválido.', array( 'status' => 422 ) );
	}

	if ( null !== papelito_subcategory_get_by_slug( $category_id, $slug ) ) {
		return new WP_Error( 'papelito_subcategory_slug_taken', 'Já existe uma subcategoria com esse slug nesta categoria.', array( 'status' => 409 ) );
	}

	$facet  = sanitize_key( (string) ( $data['facet'] ?? 'geral' ) );
	$facet  = '' === $facet ? 'geral' : substr( $facet, 0, 48 );
	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['subcategories'],
		array(
			'category_id' => $category_id,
			'slug'        => $slug,
			'name'        => $name,
			'facet'       => $facet,
			'description' => isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : null,
			'sort_order'  => isset( $data['sortOrder'] ) ? (int) $data['sortOrder'] : 0,
			'is_active'   => array_key_exists( 'isActive', $data ) && ! $data['isActive'] ? 0 : 1,
			'created_at'  => $now,
			'updated_at'  => $now,
		)
	);

	if ( ! $inserted ) {
		return new WP_Error( 'papelito_subcategory_create_failed', 'Não foi possível criar a subcategoria.', array( 'status' => 500 ) );
	}

	$subcategory_id = (int) $wpdb->insert_id;

	papelito_product_taxonomy_touch( 'subcategory', $subcategory_id );

	return $subcategory_id;
}

/**
 * Extrai o nome da subcategoria do payload, exigindo que não seja vazio.
 *
 * @param array<string,mixed> $data Campos a atualizar.
 * @return array<string,mixed>|WP_Error
 */
function papelito_subcategory_update_name_field( array $data ) {
	if ( ! array_key_exists( 'name', $data ) ) {
		return array();
	}

	$name = sanitize_text_field( (string) $data['name'] );

	if ( '' === $name ) {
		return new WP_Error( 'papelito_subcategory_name_required', 'Informe o nome da subcategoria.', array( 'status' => 422 ) );
	}

	return array( 'name' => $name );
}

/**
 * Extrai o slug da subcategoria, garantindo unicidade dentro da categoria.
 *
 * @param array<string,mixed> $subcategory Subcategoria sendo editada.
 * @param array<string,mixed> $data        Campos a atualizar.
 * @return array<string,mixed>|WP_Error
 */
function papelito_subcategory_update_slug_field( array $subcategory, array $data ) {
	if ( ! array_key_exists( 'slug', $data ) ) {
		return array();
	}

	$slug = papelito_taxonomy_slugify( $data['slug'] );

	if ( '' === $slug ) {
		return new WP_Error( 'papelito_subcategory_slug_invalid', 'Slug de subcategoria inválido.', array( 'status' => 422 ) );
	}

	$existing = papelito_subcategory_get_by_slug( $subcategory['categoryId'], $slug );

	if ( null !== $existing && $existing['id'] !== $subcategory['id'] ) {
		return new WP_Error( 'papelito_subcategory_slug_taken', 'Já existe uma subcategoria com esse slug nesta categoria.', array( 'status' => 409 ) );
	}

	if ( $slug !== $subcategory['slug'] && ( papelito_subcategory_product_counts( $subcategory['categoryId'] )[ $subcategory['id'] ] ?? 0 ) > 0 ) {
		return new WP_Error( 'papelito_subcategory_slug_locked', 'O slug não pode mudar enquanto houver produtos vinculados a esta subcategoria.', array( 'status' => 409 ) );
	}

	return array( 'slug' => $slug );
}

/**
 * Extrai os campos opcionais da subcategoria (faceta e ordem).
 *
 * @param array<string,mixed> $data Campos a atualizar.
 * @return array<string,mixed>
 */
function papelito_subcategory_update_optional_fields( array $data ) {
	$fields = array();

	if ( array_key_exists( 'facet', $data ) ) {
		$facet           = sanitize_key( (string) $data['facet'] );
		$fields['facet'] = '' === $facet ? 'geral' : substr( $facet, 0, 48 );
	}

	if ( array_key_exists( 'description', $data ) ) {
		$fields['description'] = wp_kses_post( (string) $data['description'] );
	}

	if ( array_key_exists( 'sortOrder', $data ) ) {
		$fields['sort_order'] = (int) $data['sortOrder'];
	}

	return $fields;
}

/**
 * Extrai o estado ativo, barrando a desativação de subcategoria ainda em uso.
 *
 * @param array<string,mixed> $subcategory Subcategoria sendo editada.
 * @param array<string,mixed> $data        Campos a atualizar.
 * @return array<string,mixed>|WP_Error
 */
function papelito_subcategory_update_active_field( array $subcategory, array $data ) {
	if ( ! array_key_exists( 'isActive', $data ) ) {
		return array();
	}

	if ( ! $data['isActive'] && ( papelito_subcategory_product_counts( $subcategory['categoryId'] )[ $subcategory['id'] ] ?? 0 ) > 0 ) {
		return new WP_Error( 'papelito_subcategory_in_use', 'Remova os vínculos de produto antes de desativar a subcategoria.', array( 'status' => 409 ) );
	}

	return array( 'is_active' => $data['isActive'] ? 1 : 0 );
}

/**
 * Atualiza campos de uma subcategoria. `categoryId` é imutável.
 *
 * Mover subcategoria entre categorias invalidaria os vínculos de produto já
 * existentes: quem precisa disso cria a subcategoria nova e reclassifica.
 *
 * @param int                 $subcategory_id Id da subcategoria.
 * @param array<string,mixed> $data           Campos a atualizar.
 * @return true|WP_Error
 */
function papelito_subcategory_update( $subcategory_id, array $data ) {
	global $wpdb;

	$subcategory = papelito_subcategory_get( $subcategory_id );

	if ( null === $subcategory ) {
		return new WP_Error( 'papelito_subcategory_not_found', 'Subcategoria não encontrada.', array( 'status' => 404 ) );
	}

	$fields = array();

	foreach ( array(
		papelito_subcategory_update_name_field( $data ),
		papelito_subcategory_update_slug_field( $subcategory, $data ),
		papelito_subcategory_update_optional_fields( $data ),
		papelito_subcategory_update_active_field( $subcategory, $data ),
	) as $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fields = array_merge( $fields, $result );
	}

	if ( empty( $fields ) ) {
		return true;
	}

	$fields['updated_at'] = papelito_taxonomy_now();
	$tables               = papelito_product_taxonomy_table_names();

	$wpdb->update( $tables['subcategories'], $fields, array( 'id' => $subcategory['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	papelito_product_taxonomy_touch( 'subcategory', $subcategory['id'] );

	return true;
}

/**
 * Arquiva (soft delete) uma subcategoria e desfaz os vínculos com produtos.
 *
 * Diferente de categoria, arquivar subcategoria é seguro: produto sem
 * subcategoria continua classificado.
 *
 * @param int $subcategory_id Id da subcategoria.
 * @return true|WP_Error
 */
function papelito_subcategory_archive( $subcategory_id ) {
	global $wpdb;

	$subcategory = papelito_subcategory_get( $subcategory_id );

	if ( null === $subcategory ) {
		return new WP_Error( 'papelito_subcategory_not_found', 'Subcategoria não encontrada.', array( 'status' => 404 ) );
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['subcategories'],
		array(
			'is_active'   => 0,
			'archived_at' => $now,
			'updated_at'  => $now,
		),
		array( 'id' => $subcategory['id'] )
	);

	$wpdb->delete( $tables['product_subcategory'], array( 'subcategory_id' => $subcategory['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	papelito_product_taxonomy_touch( 'subcategory', $subcategory['id'] );

	return true;
}

/**
 * Reordena subcategorias dentro de uma categoria.
 *
 * @param int   $category_id Id da categoria.
 * @param int[] $ordered_ids Ids na ordem desejada.
 * @return true|WP_Error
 */
function papelito_subcategories_reorder( $category_id, array $ordered_ids ) {
	global $wpdb;

	$category_id = (int) $category_id;
	$ids         = array_values( array_unique( array_filter( array_map( 'intval', $ordered_ids ) ) ) );

	if ( empty( $ids ) ) {
		return new WP_Error( 'papelito_reorder_empty', 'Informe ao menos uma subcategoria.', array( 'status' => 422 ) );
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	foreach ( $ids as $position => $subcategory_id ) {
		$subcategory = papelito_subcategory_get( $subcategory_id );

		if ( null === $subcategory || $subcategory['categoryId'] !== $category_id ) {
			return new WP_Error( 'papelito_subcategory_foreign', 'Subcategoria não pertence à categoria: ' . $subcategory_id, array( 'status' => 422 ) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tables['subcategories'],
			array(
				'sort_order' => $position,
				'updated_at' => $now,
			),
			array( 'id' => $subcategory_id )
		);
	}

	papelito_product_taxonomy_touch( 'subcategory', 0 );

	return true;
}

// ------------------------------------------------------------------
// Vínculo com produto
// ------------------------------------------------------------------

/**
 * Resolve a categoria principal do payload, preservando a atual se ausente.
 *
 * @param array<string,mixed>|null $current_category Categoria já vinculada.
 * @param array<string,mixed>      $data             Payload da edição.
 * @return array<string,mixed>|WP_Error|null
 */
function papelito_product_taxonomy_resolve_category( $current_category, array $data ) {
	if ( ! array_key_exists( 'categoryId', $data ) ) {
		return $current_category;
	}

	$category = papelito_category_get( (int) $data['categoryId'] );

	if ( null === $category ) {
		return new WP_Error( 'papelito_category_not_found', PAPELITO_CATEGORY_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	if ( ! $category['isActive'] || null !== $category['archivedAt'] ) {
		return new WP_Error( 'papelito_category_inactive', 'Categoria inativa ou arquivada.', array( 'status' => 422 ) );
	}

	return $category;
}

/**
 * Resolve as subcategorias do payload, exigindo que pertençam à categoria.
 *
 * @param array<string,mixed>|null $category                Categoria resolvida.
 * @param int[]                    $current_subcategory_ids Vínculos atuais.
 * @param array<string,mixed>      $data                    Payload da edição.
 * @return int[]|WP_Error
 */
function papelito_product_taxonomy_resolve_subcategory_ids( $category, array $current_subcategory_ids, array $data ) {
	if ( ! array_key_exists( 'subcategoryIds', $data ) ) {
		return array();
	}

	if ( null === $category ) {
		return new WP_Error( 'papelito_product_category_missing', 'Defina a categoria principal antes das subcategorias.', array( 'status' => 422 ) );
	}

	$subcategory_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $data['subcategoryIds'] ) ) ) );

	foreach ( $subcategory_ids as $subcategory_id ) {
		$subcategory = papelito_subcategory_get( $subcategory_id );

		if ( null === $subcategory ) {
			return new WP_Error( 'papelito_subcategory_not_found', 'Subcategoria não encontrada: ' . $subcategory_id, array( 'status' => 404 ) );
		}

		if ( $subcategory['categoryId'] !== $category['id'] ) {
			return new WP_Error( 'papelito_subcategory_foreign', sprintf( 'A subcategoria "%s" não pertence à categoria "%s".', $subcategory['name'], $category['name'] ), array( 'status' => 422 ) );
		}

		if ( ( ! $subcategory['isActive'] || null !== $subcategory['archivedAt'] ) && ! in_array( $subcategory['id'], $current_subcategory_ids, true ) ) {
			return new WP_Error( 'papelito_subcategory_inactive', 'Subcategoria inativa ou arquivada não pode ser vinculada a um novo produto: ' . $subcategory['name'], array( 'status' => 422 ) );
		}
	}

	return $subcategory_ids;
}

/**
 * Resolve as coleções curadas do payload.
 *
 * @param array<string,mixed> $data Payload da edição.
 * @return string[]|WP_Error
 */
function papelito_product_taxonomy_resolve_collections( array $data ) {
	if ( ! array_key_exists( 'collections', $data ) ) {
		return array();
	}

	$allowed     = papelito_curated_collections();
	$collections = array();

	foreach ( (array) $data['collections'] as $slug ) {
		$slug = sanitize_key( (string) $slug );

		if ( '' === $slug ) {
			continue;
		}
		if ( 'kits' === $slug ) {
			// Aceita o valor legado para que uma edição comum consiga removê-lo
			// sem reintroduzir a coleção no novo modelo de Kits.
			continue;
		}

		if ( ! in_array( $slug, $allowed, true ) ) {
			return new WP_Error( 'papelito_collection_unknown', 'Coleção desconhecida: ' . $slug, array( 'status' => 422 ) );
		}

		$collections[ $slug ] = $slug;
	}

	return $collections;
}

/**
 * Grava o vínculo de categoria principal. Só escreve se a categoria mudou.
 *
 * @param int                      $product_id       Id do produto.
 * @param array<string,string>     $tables           Nomes das tabelas.
 * @param array<string,mixed>|null $category         Categoria resolvida.
 * @param bool                     $category_changed Se a categoria mudou.
 * @return bool
 */
function papelito_product_taxonomy_replace_category_link( $product_id, array $tables, $category, $category_changed ) {
	global $wpdb;

	if ( ! $category_changed ) {
		return true;
	}

	return false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"INSERT INTO {$tables['product_category']} (product_id, category_id, updated_at) VALUES (%d, %d, %s) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$product_id,
			$category['id'],
			papelito_taxonomy_now()
		)
	);
}

/**
 * Regrava os vínculos de subcategoria. Trocar de categoria limpa os antigos.
 *
 * @param int                  $product_id        Id do produto.
 * @param array<string,string> $tables            Nomes das tabelas.
 * @param bool                 $category_changed  Se a categoria mudou.
 * @param bool                 $has_subcategories Se o payload trouxe a chave.
 * @param int[]                $subcategory_ids   Subcategorias resolvidas.
 * @return bool
 */
function papelito_product_taxonomy_replace_subcategory_links( $product_id, array $tables, $category_changed, $has_subcategories, array $subcategory_ids ) {
	global $wpdb;

	if ( ! $has_subcategories && ! $category_changed ) {
		return true;
	}

	if ( false === $wpdb->delete( $tables['product_subcategory'], array( 'product_id' => $product_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false;
	}

	if ( ! $has_subcategories ) {
		return true;
	}

	foreach ( $subcategory_ids as $subcategory_id ) {
		if ( false === $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tables['product_subcategory'],
			array(
				'product_id'     => $product_id,
				'subcategory_id' => $subcategory_id,
			)
		) ) {
			return false;
		}
	}

	return true;
}

/**
 * Regrava os vínculos de coleção curada.
 *
 * @param int                  $product_id      Id do produto.
 * @param array<string,string> $tables          Nomes das tabelas.
 * @param bool                 $has_collections Se o payload trouxe a chave.
 * @param string[]             $collections     Coleções resolvidas.
 * @return bool
 */
function papelito_product_taxonomy_replace_collection_links( $product_id, array $tables, $has_collections, array $collections ) {
	global $wpdb;

	if ( ! $has_collections ) {
		return true;
	}

	if ( false === $wpdb->delete( $tables['product_collection'], array( 'product_id' => $product_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false;
	}

	foreach ( $collections as $slug ) {
		if ( false === $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tables['product_collection'],
			array(
				'product_id'      => $product_id,
				'collection_slug' => $slug,
			)
		) ) {
			return false;
		}
	}

	return true;
}

/**
 * Executa as três gravações do plano, abortando na primeira que falhar.
 *
 * @param int                  $product_id Id do produto.
 * @param array<string,string> $tables     Nomes das tabelas.
 * @param array<string,mixed>  $plan       Categoria, subcategorias e coleções já resolvidas.
 * @return bool
 */
function papelito_product_taxonomy_replace_write( $product_id, array $tables, array $plan ) {
	if ( ! papelito_product_taxonomy_replace_category_link( $product_id, $tables, $plan['category'], $plan['categoryChanged'] ) ) {
		return false;
	}

	if ( ! papelito_product_taxonomy_replace_subcategory_links( $product_id, $tables, $plan['categoryChanged'], $plan['hasSubcategories'], $plan['subcategoryIds'] ) ) {
		return false;
	}

	return papelito_product_taxonomy_replace_collection_links( $product_id, $tables, $plan['hasCollections'], $plan['collections'] );
}

/**
 * Substitui, em uma única transação, os vínculos de taxonomia enviados para
 * um produto. Valida tudo antes de apagar qualquer vínculo existente.
 *
 * As chaves ausentes são preservadas; uma troca de categoria sem
 * `subcategoryIds` limpa as subcategorias, pois elas pertencem à categoria
 * anterior. Essa é a operação usada pela API de edição completa.
 *
 * @param int                 $product_id Id do produto.
 * @param array<string,mixed> $data       categoryId, subcategoryIds e collections opcionais.
 * @return true|WP_Error
 */
function papelito_product_replace_taxonomy( $product_id, array $data ) {
	global $wpdb;

	$product_id = (int) $product_id;

	if ( ! papelito_taxonomy_is_product( $product_id ) ) {
		return new WP_Error( 'papelito_product_not_found', 'Produto não encontrado.', array( 'status' => 404 ) );
	}

	$current_category        = papelito_product_get_category( $product_id );
	$current_subcategory_ids = array_map( 'intval', array_column( papelito_product_get_subcategories( $product_id ), 'id' ) );
	$category                = papelito_product_taxonomy_resolve_category( $current_category, $data );

	if ( is_wp_error( $category ) ) {
		return $category;
	}

	$subcategory_ids = papelito_product_taxonomy_resolve_subcategory_ids( $category, $current_subcategory_ids, $data );

	if ( is_wp_error( $subcategory_ids ) ) {
		return $subcategory_ids;
	}

	$collections = papelito_product_taxonomy_resolve_collections( $data );

	if ( is_wp_error( $collections ) ) {
		return $collections;
	}

	$has_category      = array_key_exists( 'categoryId', $data );
	$has_subcategories = array_key_exists( 'subcategoryIds', $data );
	$has_collections   = array_key_exists( 'collections', $data );
	$category_changed  = $has_category && is_array( $category ) && ( null === $current_category || $current_category['id'] !== $category['id'] );
	$tables            = papelito_product_taxonomy_table_names();

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_product_taxonomy_failed', 'Não foi possível iniciar a gravação da taxonomia.', array( 'status' => 500 ) );
	}

	$written = papelito_product_taxonomy_replace_write(
		$product_id,
		$tables,
		array(
			'category'         => $category,
			'categoryChanged'  => $category_changed,
			'collections'      => $collections,
			'hasCollections'   => $has_collections,
			'hasSubcategories' => $has_subcategories,
			'subcategoryIds'   => $subcategory_ids,
		)
	);

	if ( ! $written || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return new WP_Error( 'papelito_product_taxonomy_failed', 'Não foi possível gravar a taxonomia do produto.', array( 'status' => 500 ) );
	}

	papelito_product_taxonomy_touch( 'product', $product_id );

	return true;
}

/**
 * Categoria principal de um produto.
 *
 * @param int $product_id Id do produto.
 * @return array<string,mixed>|null
 */
function papelito_product_get_category( $product_id ) {
	global $wpdb;

	$product_id = (int) $product_id;

	if ( $product_id <= 0 ) {
		return null;
	}

	$tables      = papelito_product_taxonomy_table_names();
	$category_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT category_id FROM {$tables['product_category']} WHERE product_id = %d", $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return $category_id > 0 ? papelito_category_get( $category_id ) : null;
}

/**
 * Define a categoria principal de um produto. Único writer do vínculo.
 *
 * A PRIMARY KEY em `product_id` faz o banco garantir "no máximo uma"; esta
 * função garante o resto: categoria existe, está ativa, e trocar de categoria
 * limpa as subcategorias antigas — que pertenciam à categoria anterior e
 * ficariam órfãs de sentido.
 *
 * @param int $product_id  Id do produto.
 * @param int $category_id Id da categoria.
 * @return true|WP_Error
 */
function papelito_product_set_category( $product_id, $category_id ) {
	return papelito_product_replace_taxonomy( $product_id, array( 'categoryId' => (int) $category_id ) );
}

/**
 * Subcategorias de um produto, em ordem de faceta.
 *
 * @param int $product_id Id do produto.
 * @return array<int,array<string,mixed>>
 */
function papelito_product_get_subcategories( $product_id ) {
	global $wpdb;

	$product_id = (int) $product_id;

	if ( $product_id <= 0 ) {
		return array();
	}

	$tables = papelito_product_taxonomy_table_names();
	$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT s.* FROM {$tables['product_subcategory']} ps INNER JOIN {$tables['subcategories']} s ON s.id = ps.subcategory_id WHERE ps.product_id = %d ORDER BY s.facet ASC, s.sort_order ASC, s.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$product_id
		),
		ARRAY_A
	);

	return array_values( array_filter( array_map( 'papelito_subcategory_shape', is_array( $rows ) ? $rows : array() ) ) );
}

/**
 * Substitui o conjunto de subcategorias de um produto.
 *
 * Recusa qualquer subcategoria que não pertença à categoria principal do
 * produto. Sem categoria definida, recusa por inteiro.
 *
 * @param int   $product_id      Id do produto.
 * @param int[] $subcategory_ids Ids das subcategorias.
 * @return true|WP_Error
 */
function papelito_product_set_subcategories( $product_id, array $subcategory_ids ) {
	return papelito_product_replace_taxonomy( $product_id, array( 'subcategoryIds' => $subcategory_ids ) );
}

/**
 * Coleções curadas de um produto.
 *
 * @param int $product_id Id do produto.
 * @return string[]
 */
function papelito_product_get_collections( $product_id ) {
	global $wpdb;

	$product_id = (int) $product_id;

	if ( $product_id <= 0 ) {
		return array();
	}

	$tables = papelito_product_taxonomy_table_names();
	$rows   = $wpdb->get_col( $wpdb->prepare( "SELECT collection_slug FROM {$tables['product_collection']} WHERE product_id = %d ORDER BY collection_slug ASC", $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array_map( 'strval', is_array( $rows ) ? $rows : array() );
}

/**
 * Substitui o conjunto de coleções curadas de um produto.
 *
 * @param int      $product_id Id do produto.
 * @param string[] $slugs      Slugs de coleção.
 * @return true|WP_Error
 */
function papelito_product_set_collections( $product_id, array $slugs ) {
	return papelito_product_replace_taxonomy( $product_id, array( 'collections' => $slugs ) );
}

/**
 * Remove todo o vínculo de taxonomia de um produto.
 *
 * @param int $product_id Id do produto.
 * @return true
 */
function papelito_product_clear_taxonomy( $product_id ) {
	global $wpdb;

	$product_id = (int) $product_id;

	if ( $product_id <= 0 ) {
		return true;
	}

	$tables = papelito_product_taxonomy_table_names();

	$wpdb->delete( $tables['product_subcategory'], array( 'product_id' => $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $tables['product_collection'], array( 'product_id' => $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $tables['product_category'], array( 'product_id' => $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	papelito_product_taxonomy_touch( 'product', $product_id );

	return true;
}

// ------------------------------------------------------------------
// Loaders em lote
// ------------------------------------------------------------------

/**
 * Categoria principal de vários produtos, em uma consulta.
 *
 * Existe para o resolver GraphQL: uma listagem de 100 produtos com leitura
 * produto a produto viraria N+1 e derrubaria a invariante de performance do
 * catálogo.
 *
 * @param int[] $product_ids Ids dos produtos.
 * @return array<int,array<string,mixed>> Mapa `product_id => categoria`.
 */
function papelito_products_category_map( array $product_ids ) {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );

	if ( empty( $ids ) ) {
		return array();
	}

	$tables       = papelito_product_taxonomy_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql          = "SELECT pc.product_id, c.* FROM {$tables['product_category']} pc INNER JOIN {$tables['categories']} c ON c.id = pc.category_id WHERE pc.product_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$map = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$product_id = (int) ( $row['product_id'] ?? 0 );
		$category   = papelito_category_shape( $row );

		if ( $product_id > 0 && null !== $category ) {
			$map[ $product_id ] = $category;
		}
	}

	return $map;
}

/**
 * Subcategorias de vários produtos, em uma consulta.
 *
 * @param int[] $product_ids Ids dos produtos.
 * @return array<int,array<int,array<string,mixed>>> Mapa `product_id => subcategorias[]`.
 */
function papelito_products_subcategory_map( array $product_ids ) {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );

	if ( empty( $ids ) ) {
		return array();
	}

	$tables       = papelito_product_taxonomy_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql          = "SELECT ps.product_id, s.* FROM {$tables['product_subcategory']} ps INNER JOIN {$tables['subcategories']} s ON s.id = ps.subcategory_id WHERE ps.product_id IN ({$placeholders}) ORDER BY s.facet ASC, s.sort_order ASC, s.id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$map = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$product_id  = (int) ( $row['product_id'] ?? 0 );
		$subcategory = papelito_subcategory_shape( $row );

		if ( $product_id > 0 && null !== $subcategory ) {
			$map[ $product_id ][] = $subcategory;
		}
	}

	return $map;
}

/**
 * Coleções de vários produtos, em uma consulta.
 *
 * @param int[] $product_ids Ids dos produtos.
 * @return array<int,string[]> Mapa `product_id => slugs[]`.
 */
function papelito_products_collection_map( array $product_ids ) {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );

	if ( empty( $ids ) ) {
		return array();
	}

	$tables       = papelito_product_taxonomy_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql          = "SELECT product_id, collection_slug FROM {$tables['product_collection']} WHERE product_id IN ({$placeholders}) ORDER BY collection_slug ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$map = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$product_id = (int) ( $row['product_id'] ?? 0 );

		if ( $product_id > 0 ) {
			$map[ $product_id ][] = (string) $row['collection_slug'];
		}
	}

	return $map;
}

// ------------------------------------------------------------------
// Contagens e integridade
// ------------------------------------------------------------------

/**
 * Contagem de produtos por categoria, separando publicados de rascunhos.
 *
 * @return array<int,array{total:int,published:int}> Mapa `category_id => contagens`.
 */
function papelito_category_product_counts() {
	global $wpdb;

	$tables = papelito_product_taxonomy_table_names();
	$sql    = "SELECT pc.category_id, COUNT(DISTINCT pc.product_id) AS total, SUM(p.post_status = 'publish') AS published FROM {$tables['product_category']} pc INNER JOIN {$wpdb->posts} p ON p.ID = pc.product_id AND p.post_type = 'product' GROUP BY pc.category_id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows   = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$map = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$map[ (int) $row['category_id'] ] = array(
			'total'     => (int) $row['total'],
			'published' => (int) $row['published'],
		);
	}

	return $map;
}

/**
 * Contagem de produtos por subcategoria.
 *
 * @param int $category_id Restringe a uma categoria; 0 para todas.
 * @return array<int,int> Mapa `subcategory_id => total`.
 */
function papelito_subcategory_product_counts( $category_id = 0 ) {
	global $wpdb;

	$category_id = (int) $category_id;
	$tables      = papelito_product_taxonomy_table_names();
	$sql         = "SELECT ps.subcategory_id, COUNT(DISTINCT ps.product_id) AS total FROM {$tables['product_subcategory']} ps INNER JOIN {$tables['subcategories']} s ON s.id = ps.subcategory_id";

	if ( $category_id > 0 ) {
		$sql .= $wpdb->prepare( ' WHERE s.category_id = %d', $category_id );
	}

	$sql .= ' GROUP BY ps.subcategory_id';

	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$map  = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$map[ (int) $row['subcategory_id'] ] = (int) $row['total'];
	}

	return $map;
}

/**
 * Relatório de integridade da taxonomia.
 *
 * O banco garante "no máximo uma categoria". Isto acha o que ele não garante:
 * produto publicado sem categoria, vínculo apontando para categoria/subcategoria
 * que não existe mais, subcategoria de outra categoria, e categoria inativa
 * ainda segurando produto publicado.
 *
 * Kits ficam de fora de `publishedWithoutCategory`: o produto comercial deles é
 * oculto do catálogo por decisão de produto e não se classifica.
 *
 * @return array<string,mixed>
 */
function papelito_category_integrity_report() {
	global $wpdb;

	$tables      = papelito_product_taxonomy_table_names();
	$kits_tables = papelito_kits_table_names();

	// O produto comercial de um Kit nasce `catalog_visibility=hidden` e nunca
	// recebe categoria: ele não entra na vitrine por classificação. Contá-lo aqui
	// vira alerta permanente que ninguém consegue resolver, porque a aba Produtos
	// exclui kits — eles se editam na aba Kits.
	$sql              = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$tables['product_category']} pc ON pc.product_id = p.ID LEFT JOIN {$kits_tables['kits']} k ON k.product_id = p.ID WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pc.product_id IS NULL AND k.product_id IS NULL ORDER BY p.ID ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$missing_category = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$sql               = "SELECT pc.product_id FROM {$tables['product_category']} pc LEFT JOIN {$tables['categories']} c ON c.id = pc.category_id WHERE c.id IS NULL ORDER BY pc.product_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$dangling_category = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$sql                  = "SELECT ps.product_id FROM {$tables['product_subcategory']} ps LEFT JOIN {$tables['subcategories']} s ON s.id = ps.subcategory_id WHERE s.id IS NULL ORDER BY ps.product_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$dangling_subcategory = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$sql            = "SELECT ps.product_id, ps.subcategory_id, s.category_id AS subcategory_category_id, pc.category_id AS product_category_id FROM {$tables['product_subcategory']} ps INNER JOIN {$tables['subcategories']} s ON s.id = ps.subcategory_id INNER JOIN {$tables['product_category']} pc ON pc.product_id = ps.product_id WHERE s.category_id <> pc.category_id ORDER BY ps.product_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$cross_category = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$sql                    = "SELECT c.id AS category_id, c.name, COUNT(DISTINCT pc.product_id) AS total FROM {$tables['categories']} c INNER JOIN {$tables['product_category']} pc ON pc.category_id = c.id INNER JOIN {$wpdb->posts} p ON p.ID = pc.product_id AND p.post_type = 'product' WHERE c.is_active = 0 OR c.archived_at IS NOT NULL GROUP BY c.id ORDER BY c.id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$inactive_with_products = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$sql                = "SELECT DISTINCT collection_slug FROM {$tables['product_collection']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$unknown_collection = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$allowed            = papelito_curated_collections();
	$unknown_collection = array_values( array_diff( array_map( 'strval', is_array( $unknown_collection ) ? $unknown_collection : array() ), $allowed ) );

	$report = array(
		'publishedWithoutCategory' => array_map( 'intval', is_array( $missing_category ) ? $missing_category : array() ),
		'danglingCategory'         => array_map( 'intval', is_array( $dangling_category ) ? $dangling_category : array() ),
		'danglingSubcategory'      => array_map( 'intval', is_array( $dangling_subcategory ) ? $dangling_subcategory : array() ),
		'crossCategorySubcategory' => is_array( $cross_category ) ? $cross_category : array(),
		'inactiveWithProducts'     => is_array( $inactive_with_products ) ? $inactive_with_products : array(),
		'unknownCollections'       => $unknown_collection,
	);

	$report['isClean'] = empty( $report['publishedWithoutCategory'] )
		&& empty( $report['danglingCategory'] )
		&& empty( $report['danglingSubcategory'] )
		&& empty( $report['crossCategorySubcategory'] )
		&& empty( $report['inactiveWithProducts'] )
		&& empty( $report['unknownCollections'] );

	return $report;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class PapelitoProductTaxonomyCli {
		public function integrity( array $args, array $assoc_args ): void {
			unset( $args, $assoc_args );

			$report = papelito_category_integrity_report();
			WP_CLI::log( wp_json_encode( $report ) );

			if ( empty( $report['isClean'] ) ) {
				WP_CLI::error( 'Relatorio de integridade da taxonomia contem inconsistencias.' );
			}
		}
	}

	WP_CLI::add_command( 'papelito taxonomy', 'PapelitoProductTaxonomyCli' );
}
