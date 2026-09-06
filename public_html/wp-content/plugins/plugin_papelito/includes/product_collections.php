<?php
/**
 * Catálogo de coleções manuais da Papelito.
 *
 * Coleção é recorte editorial/comercial que atravessa a categoria: um produto
 * pode estar em várias coleções ao mesmo tempo, e uma coleção reúne vários
 * produtos. O vínculo continua em `wp_papelito_product_collection`, cuja chave
 * primária `(product_id, collection_slug)` é a garantia de unicidade do par.
 *
 * Este módulo substitui a lista fixa que `papelito_curated_collections()`
 * devolvia. Coleção NÃO é `product_tag`: a taxonomia do WooCommerce continua
 * sendo palavra-chave de busca e não classifica nada.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_COLLECTION_NOT_FOUND_MESSAGE' ) ) {
	define( 'PAPELITO_COLLECTION_NOT_FOUND_MESSAGE', 'Coleção não encontrada.' );
}

/**
 * Slugs que o catálogo não pode ocupar.
 *
 * `todos`, `novidades` e `promocoes` são coleções derivadas, calculadas em
 * runtime; `kits` é entidade própria desde 20/08. Deixar o admin criar uma
 * coleção manual com um desses slugs faria dois conceitos disputarem o mesmo
 * identificador em `/catalog/search?collection=`.
 *
 * @return string[]
 */
function papelito_collection_reserved_slugs() {
	return array( 'todos', 'novidades', 'promocoes', 'kits' );
}

/**
 * Converte uma linha crua de coleção no formato público.
 *
 * @param array<string,mixed>|null $row Linha do banco.
 * @return array<string,mixed>|null
 */
function papelito_collection_shape( $row ) {
	if ( ! is_array( $row ) || empty( $row['id'] ) ) {
		return null;
	}

	return array(
		'id'          => (int) $row['id'],
		'slug'        => (string) $row['slug'],
		'name'        => (string) $row['name'],
		'description' => null === $row['description'] ? '' : (string) $row['description'],
		'sortOrder'   => (int) $row['sort_order'],
		'isActive'    => 1 === (int) $row['is_active'],
		'archivedAt'  => null === $row['archived_at'] ? null : (string) $row['archived_at'],
	);
}

/**
 * Confirma que a tabela de catálogo já existe.
 *
 * O deploy da Hostinger é sync de arquivos e a migração roda em
 * `plugins_loaded`: entre o arquivo novo e a migração existe uma janela em que
 * o código novo roda sem a tabela. Quem consulta precisa saber disso para cair
 * no comportamento antigo em vez de devolver conjunto vazio.
 *
 * @return bool
 */
function papelito_collections_table_ready() {
	global $wpdb;

	// Só o resultado positivo é memoizado. Cachear o negativo travaria a própria
	// requisição da migração: quem consultasse antes do `dbDelta` deixaria a
	// semente do Premium sem tabela pelo resto do request.
	static $ready = false;

	if ( $ready ) {
		return true;
	}

	$tables = papelito_product_taxonomy_table_names();
	$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tables['collections'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$ready = is_string( $found ) && '' !== $found;

	return $ready;
}

/**
 * Busca uma coleção pelo id.
 *
 * @param int $collection_id Id da coleção.
 * @return array<string,mixed>|null
 */
function papelito_collection_get( $collection_id ) {
	global $wpdb;

	$collection_id = (int) $collection_id;

	if ( $collection_id <= 0 || ! papelito_collections_table_ready() ) {
		return null;
	}

	$tables = papelito_product_taxonomy_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['collections']} WHERE id = %d", $collection_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return papelito_collection_shape( $row );
}

/**
 * Busca uma coleção pelo slug.
 *
 * @param string $slug Slug normalizado.
 * @return array<string,mixed>|null
 */
function papelito_collection_get_by_slug( $slug ) {
	global $wpdb;

	$slug = papelito_collection_slugify( $slug );

	if ( '' === $slug || ! papelito_collections_table_ready() ) {
		return null;
	}

	$tables = papelito_product_taxonomy_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['collections']} WHERE slug = %s", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return papelito_collection_shape( $row );
}

/**
 * Normaliza um slug de coleção.
 *
 * O limite de 48 caracteres não é estético: `collection_slug` é `VARCHAR(48)`
 * na tabela de vínculo, e um slug maior seria truncado na gravação e deixaria
 * de casar com o catálogo.
 *
 * @param string $value Valor cru.
 * @return string
 */
function papelito_collection_slugify( $value ) {
	return substr( sanitize_title( remove_accents( (string) $value ) ), 0, 48 );
}

/**
 * Lista coleções ordenadas por `sort_order`, com desempate estável por id.
 *
 * @param array<string,mixed> $args `active_only` (bool, default true), `include_archived` (bool, default false).
 * @return array<int,array<string,mixed>>
 */
function papelito_collections_list( array $args = array() ) {
	global $wpdb;

	if ( ! papelito_collections_table_ready() ) {
		return array();
	}

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

	$sql  = "SELECT * FROM {$tables['collections']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY sort_order ASC, id ASC';
	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return array_values( array_filter( array_map( 'papelito_collection_shape', is_array( $rows ) ? $rows : array() ) ) );
}

/**
 * Contagem de produtos por coleção, em uma única consulta.
 *
 * Agregada de propósito: a listagem administrativa mostra a contagem de todas
 * as coleções de uma vez, e uma consulta por coleção viraria N+1.
 *
 * @return array<string,array{total:int,published:int}> Mapa `slug => contagens`.
 */
function papelito_collection_product_counts() {
	global $wpdb;

	$tables = papelito_product_taxonomy_table_names();
	$sql    = "SELECT pc.collection_slug, COUNT(DISTINCT pc.product_id) AS total, SUM(p.post_status = 'publish') AS published FROM {$tables['product_collection']} pc INNER JOIN {$wpdb->posts} p ON p.ID = pc.product_id AND p.post_type = 'product' GROUP BY pc.collection_slug"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows   = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$map = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$map[ (string) $row['collection_slug'] ] = array(
			'total'     => (int) $row['total'],
			'published' => (int) $row['published'],
		);
	}

	return $map;
}

/**
 * Quantos produtos estão vinculados a um slug de coleção.
 *
 * @param string $slug Slug da coleção.
 * @return int
 */
function papelito_collection_product_count( $slug ) {
	global $wpdb;

	$slug = papelito_collection_slugify( $slug );

	if ( '' === $slug ) {
		return 0;
	}

	$tables = papelito_product_taxonomy_table_names();

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT pc.product_id) FROM {$tables['product_collection']} pc INNER JOIN {$wpdb->posts} p ON p.ID = pc.product_id AND p.post_type = 'product' WHERE pc.collection_slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slug
		)
	);
}

/**
 * Lista administrativa: catálogo completo com contagem de produtos.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_collections_admin_list() {
	$counts = papelito_collection_product_counts();

	return array_map(
		static function ( array $collection ) use ( $counts ) {
			$slug = $collection['slug'];

			$collection['productCount'] = array(
				'published' => (int) ( $counts[ $slug ]['published'] ?? 0 ),
				'total'     => (int) ( $counts[ $slug ]['total'] ?? 0 ),
			);

			return $collection;
		},
		papelito_collections_list(
			array(
				'active_only'      => false,
				'include_archived' => true,
			)
		)
	);
}

/**
 * Valida nome e slug de entrada, devolvendo o par normalizado.
 *
 * @param array<string,mixed> $data       Payload.
 * @param string              $fallback   Nome de origem quando o slug não vem no payload.
 * @param int                 $exclude_id Id ignorado na checagem de duplicidade.
 * @return array{name:string,slug:string}|WP_Error
 */
function papelito_collection_resolve_identity( array $data, $fallback, $exclude_id = 0 ) {
	$name = sanitize_text_field( (string) ( $data['name'] ?? $fallback ) );

	if ( '' === $name ) {
		return new WP_Error( 'papelito_collection_name_required', 'Informe o nome da coleção.', array( 'status' => 422 ) );
	}

	$slug = papelito_collection_slugify( array_key_exists( 'slug', $data ) && '' !== trim( (string) $data['slug'] ) ? $data['slug'] : $name );

	if ( '' === $slug ) {
		return new WP_Error( 'papelito_collection_slug_invalid', 'Identificador de coleção inválido.', array( 'status' => 422 ) );
	}

	if ( in_array( $slug, papelito_collection_reserved_slugs(), true ) ) {
		return new WP_Error( 'papelito_collection_slug_reserved', sprintf( 'O identificador "%s" é reservado pelas coleções automáticas.', $slug ), array( 'status' => 422 ) );
	}

	$existing = papelito_collection_get_by_slug( $slug );

	if ( null !== $existing && (int) $existing['id'] !== (int) $exclude_id ) {
		return new WP_Error( 'papelito_collection_slug_taken', sprintf( 'Já existe uma coleção com o identificador "%s".', $slug ), array( 'status' => 409 ) );
	}

	return array(
		'name' => $name,
		'slug' => $slug,
	);
}

/**
 * Cria uma coleção manual.
 *
 * @param array<string,mixed> $data `name` (obrigatório), `slug`, `description`, `sortOrder`, `isActive`.
 * @return int|WP_Error Id criado.
 */
function papelito_collection_create( array $data ) {
	global $wpdb;

	if ( ! papelito_collections_table_ready() ) {
		return new WP_Error( 'papelito_collections_unavailable', 'O catálogo de coleções ainda não foi instalado.', array( 'status' => 503 ) );
	}

	$identity = papelito_collection_resolve_identity( $data, '' );

	if ( is_wp_error( $identity ) ) {
		return $identity;
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['collections'],
		array(
			'slug'        => $identity['slug'],
			'name'        => $identity['name'],
			'description' => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null,
			'sort_order'  => isset( $data['sortOrder'] ) ? (int) $data['sortOrder'] : papelito_collection_next_sort_order(),
			'is_active'   => array_key_exists( 'isActive', $data ) && ! $data['isActive'] ? 0 : 1,
			'created_at'  => $now,
			'updated_at'  => $now,
		)
	);

	if ( ! $inserted ) {
		return new WP_Error( 'papelito_collection_create_failed', 'Não foi possível criar a coleção.', array( 'status' => 500 ) );
	}

	$collection_id = (int) $wpdb->insert_id;

	papelito_product_taxonomy_touch( 'collection', $collection_id );

	return $collection_id;
}

/**
 * Próxima posição livre na ordenação.
 *
 * @return int
 */
function papelito_collection_next_sort_order() {
	global $wpdb;

	if ( ! papelito_collections_table_ready() ) {
		return 0;
	}

	$tables = papelito_product_taxonomy_table_names();
	$sql    = "SELECT MAX(sort_order) FROM {$tables['collections']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$max    = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return null === $max ? 0 : (int) $max + 1;
}

/**
 * Atualiza uma coleção. Só o que vier em `$data` é tocado.
 *
 * O identificador é imutável depois do primeiro vínculo: ele é a chave
 * estrangeira natural em `wp_papelito_product_collection`, e trocá-lo exigiria
 * reescrever as associações. Recusar é mais seguro que um update frágil.
 *
 * @param int                 $collection_id Id da coleção.
 * @param array<string,mixed> $data          Campos a atualizar.
 * @return true|WP_Error
 */
function papelito_collection_update( $collection_id, array $data ) {
	global $wpdb;

	$collection = papelito_collection_get( $collection_id );

	if ( null === $collection ) {
		return new WP_Error( 'papelito_collection_not_found', PAPELITO_COLLECTION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$fields = array();

	if ( array_key_exists( 'name', $data ) || array_key_exists( 'slug', $data ) ) {
		// Renomear não redesenha o identificador. Sem fixar o slug atual, trocar o
		// nome de uma coleção com produtos derivaria um slug novo e cairia na trava
		// abaixo — quem só queria corrigir o nome perderia a edição inteira.
		$identity = papelito_collection_resolve_identity(
			array_merge( $data, array( 'slug' => $data['slug'] ?? $collection['slug'] ) ),
			$collection['name'],
			$collection['id']
		);

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		if ( $identity['slug'] !== $collection['slug'] && papelito_collection_product_count( $collection['slug'] ) > 0 ) {
			return new WP_Error(
				'papelito_collection_slug_locked',
				'O identificador não pode mudar depois que a coleção tem produtos vinculados.',
				array( 'status' => 409 )
			);
		}

		$fields['name'] = $identity['name'];
		$fields['slug'] = $identity['slug'];
	}

	if ( array_key_exists( 'description', $data ) ) {
		$fields['description'] = sanitize_textarea_field( (string) $data['description'] );
	}

	if ( array_key_exists( 'sortOrder', $data ) ) {
		$fields['sort_order'] = (int) $data['sortOrder'];
	}

	if ( array_key_exists( 'isActive', $data ) ) {
		$fields['is_active'] = $data['isActive'] ? 1 : 0;

		// Marcar "Ativa" precisa desarquivar junto. A listagem ativa exige
		// `is_active = 1` E `archived_at IS NULL`: mexer só no primeiro deixava a
		// coleção arquivada e o salvamento parecia ter funcionado sem nada mudar.
		if ( $data['isActive'] ) {
			$fields['archived_at'] = null;
		}
	}

	if ( empty( $fields ) ) {
		return true;
	}

	$fields['updated_at'] = papelito_taxonomy_now();
	$tables               = papelito_product_taxonomy_table_names();

	$wpdb->update( $tables['collections'], $fields, array( 'id' => $collection['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	papelito_product_taxonomy_touch( 'collection', $collection['id'] );

	return true;
}

/**
 * Arquiva (soft delete) uma coleção.
 *
 * Os vínculos são preservados: apagá-los tornaria o arquivamento irreversível
 * e destruiria curadoria que ninguém pediu para destruir. A coleção arquivada
 * some da vitrine e do seletor, e restaurar devolve tudo.
 *
 * @param int $collection_id Id da coleção.
 * @return true|WP_Error
 */
function papelito_collection_archive( $collection_id ) {
	global $wpdb;

	$collection = papelito_collection_get( $collection_id );

	if ( null === $collection ) {
		return new WP_Error( 'papelito_collection_not_found', PAPELITO_COLLECTION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['collections'],
		array(
			'is_active'   => 0,
			'archived_at' => $now,
			'updated_at'  => $now,
		),
		array( 'id' => $collection['id'] )
	);

	papelito_product_taxonomy_touch( 'collection', $collection['id'] );

	return true;
}

/**
 * Reverte o arquivamento de uma coleção.
 *
 * @param int $collection_id Id da coleção.
 * @return true|WP_Error
 */
function papelito_collection_restore( $collection_id ) {
	global $wpdb;

	$collection = papelito_collection_get( $collection_id );

	if ( null === $collection ) {
		return new WP_Error( 'papelito_collection_not_found', PAPELITO_COLLECTION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$tables = papelito_product_taxonomy_table_names();

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['collections'],
		array(
			'is_active'   => 1,
			'archived_at' => null,
			'updated_at'  => papelito_taxonomy_now(),
		),
		array( 'id' => $collection['id'] )
	);

	papelito_product_taxonomy_touch( 'collection', $collection['id'] );

	return true;
}

/**
 * Exclui uma coleção em definitivo, com todos os vínculos.
 *
 * Só aceita coleção já arquivada, pelo mesmo motivo das categorias: exclusão
 * permanente não tem volta. Diferente da categoria, os vínculos são apagados em
 * vez de barrarem a operação — coleção é opcional, então remover a associação
 * não deixa nenhum produto fora da vitrine. Quantos vínculos serão perdidos é o
 * que a confirmação da interface precisa dizer antes.
 *
 * @param int $collection_id Id da coleção.
 * @return true|WP_Error
 */
function papelito_collection_delete_permanently( $collection_id ) {
	global $wpdb;

	$collection = papelito_collection_get( $collection_id );

	if ( null === $collection ) {
		return new WP_Error( 'papelito_collection_not_found', PAPELITO_COLLECTION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	if ( null === $collection['archivedAt'] ) {
		return new WP_Error(
			'papelito_collection_not_archived',
			'Arquive a coleção antes de excluí-la em definitivo.',
			array( 'status' => 409 )
		);
	}

	$tables = papelito_product_taxonomy_table_names();

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_collection_delete_failed', 'Não foi possível iniciar a exclusão.', array( 'status' => 500 ) );
	}

	$ok = false !== $wpdb->delete( $tables['product_collection'], array( 'collection_slug' => $collection['slug'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( $ok ) {
		$ok = false !== $wpdb->delete( $tables['collections'], array( 'id' => $collection['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return new WP_Error( 'papelito_collection_delete_failed', 'Não foi possível excluir a coleção.', array( 'status' => 500 ) );
	}

	papelito_product_taxonomy_touch( 'collection', $collection['id'] );

	return true;
}

/**
 * Reordena coleções na ordem exata dos ids informados.
 *
 * A ordem não é cosmética: ela desempata a precedência de benefícios quando um
 * produto está em mais de uma coleção.
 *
 * @param int[] $ordered_ids Ids na ordem desejada.
 * @return true|WP_Error
 */
function papelito_collections_reorder( array $ordered_ids ) {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ordered_ids ) ) ) );

	if ( empty( $ids ) ) {
		return new WP_Error( 'papelito_reorder_empty', 'Informe ao menos uma coleção.', array( 'status' => 422 ) );
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	foreach ( $ids as $position => $collection_id ) {
		if ( null === papelito_collection_get( $collection_id ) ) {
			return new WP_Error( 'papelito_collection_not_found', 'Coleção não encontrada: ' . $collection_id, array( 'status' => 404 ) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tables['collections'],
			array(
				'sort_order' => $position,
				'updated_at' => $now,
			),
			array( 'id' => $collection_id )
		);
	}

	papelito_product_taxonomy_touch( 'collection', 0 );

	return true;
}

/**
 * Semeia a coleção Premium no catálogo.
 *
 * Idempotente: roda a cada bump de versão do schema e só insere se o slug não
 * existir. `sort_order` zero preserva a precedência que Premium tinha quando
 * era o único item do array literal.
 *
 * @return void
 */
function papelito_collections_seed_premium() {
	global $wpdb;

	if ( ! papelito_collections_table_ready() || null !== papelito_collection_get_by_slug( 'premium' ) ) {
		return;
	}

	$tables = papelito_product_taxonomy_table_names();
	$now    = papelito_taxonomy_now();

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['collections'],
		array(
			'slug'        => 'premium',
			'name'        => 'Premium',
			'description' => 'Linha premium do catálogo.',
			'sort_order'  => 0,
			'is_active'   => 1,
			'created_at'  => $now,
			'updated_at'  => $now,
		)
	);

	papelito_product_taxonomy_touch( 'collection', (int) $wpdb->insert_id );
}
