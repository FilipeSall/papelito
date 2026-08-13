<?php
/**
 * Benefícios comerciais da página de produto, administráveis por escopo.
 *
 * O bloco de benefícios da PDP deixou de ser JSX fixo e passou a ser um grupo
 * resolvido por produto. O desenho carrega três invariantes:
 *
 * 1. Um alvo (produto, coleção ou categoria) pertence a NO MÁXIMO um grupo —
 *    garantido pela PRIMARY KEY (target_type, target_key) na tabela de alvos,
 *    não por convenção de código. Sem isso a precedência precisaria de um
 *    critério de desempate arbitrário dentro do mesmo nível.
 * 2. Existe exatamente UM grupo global, criado pelo seed e não excluível. Ele é
 *    o último nível da resolução, então o catálogo nunca fica sem resposta.
 * 3. Ícone é `emoji` ou `svg`. Nunca HTML: o painel não tem como injetar markup.
 *
 * A resolução é `produto > coleção > categoria > global`, parando no primeiro
 * acerto e sem cadeia de fallback — grupo vencedor sem item exibível resulta em
 * bloco vazio, de propósito. Cair para o nível seguinte seria imprevisível para
 * quem acabou de desligar os itens de um grupo específico.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_BENEFIT_GROUPS_TABLE' ) ) {
	define( 'PAPELITO_BENEFIT_GROUPS_TABLE', 'papelito_benefit_groups' );
}

if ( ! defined( 'PAPELITO_BENEFIT_ITEMS_TABLE' ) ) {
	define( 'PAPELITO_BENEFIT_ITEMS_TABLE', 'papelito_benefit_items' );
}

if ( ! defined( 'PAPELITO_BENEFIT_TARGETS_TABLE' ) ) {
	define( 'PAPELITO_BENEFIT_TARGETS_TABLE', 'papelito_benefit_group_targets' );
}

if ( ! defined( 'PAPELITO_BENEFITS_PUBLIC_TTL' ) ) {
	define( 'PAPELITO_BENEFITS_PUBLIC_TTL', 10 * MINUTE_IN_SECONDS );
}

// ------------------------------------------------------------------
// Schema
// ------------------------------------------------------------------

/**
 * Resolve nomes completos (com prefixo) das tabelas de benefícios.
 *
 * @return array{groups:string,items:string,targets:string}
 */
function papelito_product_benefits_table_names() {
	global $wpdb;

	return array(
		'groups'  => $wpdb->prefix . PAPELITO_BENEFIT_GROUPS_TABLE,
		'items'   => $wpdb->prefix . PAPELITO_BENEFIT_ITEMS_TABLE,
		'targets' => $wpdb->prefix . PAPELITO_BENEFIT_TARGETS_TABLE,
	);
}

/**
 * Cria/atualiza as tabelas de benefícios via dbDelta.
 *
 * Chamado pelo bootstrap de migration em `plugin_papelito.php` quando
 * `papelito_db_version` for inferior à versão atual.
 *
 * @return bool Se o schema e a normalização foram concluídos.
 */
function papelito_product_benefits_install_tables() {
	global $wpdb;

	$tables          = papelito_product_benefits_table_names();
	$charset_collate = $wpdb->get_charset_collate();

	$groups_sql = "CREATE TABLE {$tables['groups']} (
	  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	  name VARCHAR(120) NOT NULL,
	  is_global TINYINT(1) NOT NULL DEFAULT 0,
	  global_key TINYINT(1) UNSIGNED NULL DEFAULT NULL,
	  is_active TINYINT(1) NOT NULL DEFAULT 1,
	  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	  PRIMARY KEY  (id),
	  UNIQUE KEY uniq_global_key (global_key),
	  KEY idx_global (is_global, is_active),
	  KEY idx_active (is_active, id)
) {$charset_collate};";

	$items_sql = "CREATE TABLE {$tables['items']} (
	  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	  group_id BIGINT(20) UNSIGNED NOT NULL,
  icon_type VARCHAR(12) NOT NULL DEFAULT 'emoji',
  icon_emoji VARCHAR(16) NOT NULL DEFAULT '',
	  icon_attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  icon_url VARCHAR(255) NOT NULL DEFAULT '',
  title VARCHAR(48) NOT NULL,
  description VARCHAR(96) NOT NULL DEFAULT '',
  description_content LONGTEXT NULL DEFAULT NULL,
	  sort_order INT(11) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY idx_group_sort (group_id, sort_order, id)
) {$charset_collate};";

	$targets_sql = "CREATE TABLE {$tables['targets']} (
  target_type VARCHAR(12) NOT NULL,
  target_key VARCHAR(96) NOT NULL,
	  group_id BIGINT(20) UNSIGNED NOT NULL,
  PRIMARY KEY  (target_type, target_key),
  KEY idx_group (group_id)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( $groups_sql );
	dbDelta( $items_sql );
	dbDelta( $targets_sql );

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false;
	}

	// `global_key` is nullable so non-global groups can coexist, while the
	// unique key makes the global group a database invariant. Lock the selected
	// row and normalize inside one transaction so readers never observe a state
	// without its global fallback.
	$global_id = $wpdb->get_var( "SELECT id FROM {$tables['groups']} WHERE is_global = 1 ORDER BY id ASC LIMIT 1 FOR UPDATE" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$reset     = $wpdb->query( "UPDATE {$tables['groups']} SET is_global = 0, global_key = NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( false === $reset ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false;
	}

	if ( null !== $global_id ) {
		$restored = $wpdb->update(
			$tables['groups'],
			array(
				'is_global'  => 1,
				'global_key' => 1,
			),
			array( 'id' => (int) $global_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);

		if ( false === $restored ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			return false;
		}
	}

	if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false;
	}

	// A migration can alter pre-existing rows without going through a writer.
	// Always advance the cache generation after the successful normalization.
	papelito_product_benefits_touch( 'install' );

	return true;
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Tipos de alvo aceitos, do mais específico para o mais genérico.
 *
 * A ordem desta lista É a precedência entre níveis. Global não aparece porque
 * não é alvo: é uma flag na própria linha do grupo.
 *
 * @return string[]
 */
function papelito_benefit_target_types() {
	return array( 'product', 'collection', 'category' );
}

/**
 * Tipos de ícone aceitos.
 *
 * @return string[]
 */
function papelito_benefit_icon_types() {
	return array( 'emoji', 'svg' );
}

/**
 * Limite do título de um benefício.
 *
 * @return int
 */
function papelito_benefit_title_max_length() {
	return 48;
}

/**
 * Limite do texto auxiliar de um benefício.
 *
 * @return int
 */
function papelito_benefit_description_max_length() {
	return 96;
}

/**
 * Limite de itens por grupo.
 *
 * Não é regra de layout — a faixa se adapta a qualquer quantidade. É um teto de
 * sanidade para o payload de escrita.
 *
 * @return int
 */
function papelito_benefit_items_max() {
	return 12;
}

/**
 * Timestamp UTC no formato do MySQL.
 *
 * @return string
 */
function papelito_benefits_now() {
	return current_time( 'mysql', true );
}

/**
 * Sinaliza mudança nos benefícios, invalidando o índice cacheado.
 *
 * @param string $scope   Escopo alterado (`group`, `items`, `targets`).
 * @param int    $subject Id do grupo afetado.
 * @return void
 */
function papelito_product_benefits_touch( $scope, $subject = 0 ) {
	$version = (int) get_option( 'papelito_product_benefits_version', 0 );

	update_option( 'papelito_product_benefits_version', $version + 1, true );

	do_action( 'papelito_product_benefits_changed', (string) $scope, (int) $subject );
}

/**
 * Versão corrente do cache de benefícios.
 *
 * @return int
 */
function papelito_product_benefits_version() {
	return (int) get_option( 'papelito_product_benefits_version', 0 );
}

/**
 * Erro REST padronizado do módulo.
 *
 * @param string $code    Código do erro.
 * @param string $message Mensagem.
 * @param int    $status  Status HTTP.
 * @param array  $data    Dados extras.
 * @return WP_Error
 */
function papelito_benefits_error( $code, $message, $status = 422, $data = array() ) {
	return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
}

// ------------------------------------------------------------------
// Normalização e validação
// ------------------------------------------------------------------

/**
 * Normaliza o emoji de um ícone.
 *
 * O painel oferece uma paleta, mas o campo é livre; a barreira real é aqui.
 *
 * Duas regras, e a segunda é a que importa: além de recusar os caracteres que
 * abrem tag, entidade ou atributo, o valor não pode conter alfanumérico ASCII.
 * Emoji e símbolo são sempre fora do ASCII, então a regra aceita tudo que é
 * legítimo e recusa qualquer palavra — inclusive o resíduo de um `<script>`
 * depois de o `wp_strip_all_tags` remover as tags, que passaria pelo filtro de
 * caracteres perigosos como texto inofensivo mas jamais é um ícone.
 *
 * @param mixed $value Valor bruto.
 * @return string Emoji sanitizado, possivelmente vazio.
 */
function papelito_benefit_normalize_emoji( $value ) {
	$emoji = trim( wp_strip_all_tags( (string) $value ) );

	if ( '' === $emoji || strlen( $emoji ) > 16 ) {
		return '';
	}

	if ( 1 === preg_match( '/[A-Za-z0-9<>&"\'\\\\\/]/', $emoji ) ) {
		return '';
	}

	return $emoji;
}

/**
 * Normaliza o caminho de um SVG curado servido pelo frontend.
 *
 * Só caminho interno começando por uma barra. Reaproveita a mesma política de
 * `papelito_home_assets_normalize_href()`: URL absoluta não entra.
 *
 * @param mixed $value Valor bruto.
 * @return string
 */
function papelito_benefit_normalize_icon_path( $value ) {
	$path = trim( (string) $value );

	$extension = strtolower( (string) pathinfo( (string) wp_parse_url( $path, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

	if (
		'' === $path ||
		strlen( $path ) > 255 ||
		1 !== preg_match( '#^/(?!/)#', $path ) ||
		'svg' !== $extension
	) {
		return '';
	}

	return $path;
}

/**
 * Normaliza um item de benefício vindo do painel.
 *
 * @param mixed $item  Item bruto.
 * @param int   $index Posição na lista, usada como `sort_order`.
 * @return array<string,mixed>|WP_Error
 */
function papelito_benefit_normalize_item( $item, $index ) {
	if ( ! is_array( $item ) ) {
		return papelito_benefits_error(
			'papelito_benefit_invalid_item',
			sprintf( 'Benefício #%d é inválido.', $index + 1 )
		);
	}

	$number = $index + 1;
	$title  = sanitize_text_field( (string) ( $item['title'] ?? '' ) );

	if ( '' === $title ) {
		return papelito_benefits_error(
			'papelito_benefit_missing_title',
			sprintf( 'Benefício #%d precisa de um título.', $number )
		);
	}

	if ( papelito_benefits_length( $title ) > papelito_benefit_title_max_length() ) {
		return papelito_benefits_error(
			'papelito_benefit_title_too_long',
			sprintf(
				'Título do benefício #%d excede o limite de %d caracteres.',
				$number,
				papelito_benefit_title_max_length()
			)
		);
	}

	$icon_type = (string) ( $item['iconType'] ?? 'emoji' );

	if ( ! in_array( $icon_type, papelito_benefit_icon_types(), true ) ) {
		return papelito_benefits_error(
			'papelito_benefit_invalid_icon_type',
			sprintf( 'Benefício #%d tem um tipo de ícone inválido.', $number )
		);
	}

	$icon_emoji         = '';
	$icon_attachment_id = 0;
	$icon_url           = '';

	if ( 'emoji' === $icon_type ) {
		$icon_emoji = papelito_benefit_normalize_emoji( $item['iconEmoji'] ?? '' );

		if ( '' === $icon_emoji ) {
			return papelito_benefits_error(
				'papelito_benefit_invalid_emoji',
				sprintf( 'Benefício #%d precisa de um emoji válido.', $number )
			);
		}
	} else {
		$icon_attachment_id = absint( $item['iconAttachmentId'] ?? 0 );
		$icon_url           = papelito_benefit_resolve_svg_url(
			$icon_attachment_id,
			$item['iconUrl'] ?? ''
		);

		if ( '' === $icon_url ) {
			return papelito_benefits_error(
				'papelito_benefit_invalid_svg',
				sprintf( 'Benefício #%d precisa de um SVG válido.', $number )
			);
		}
	}

	$content = papelito_home_assets_normalize_rich_text_content( $item['descriptionContent'] ?? null );

	// O texto plano é o degrau de degradação quando um token do conteúdo rico não
	// resolve — é o que reproduz o "Com cupom" que a PDP mostra hoje quando o
	// mínimo de frete grátis não está configurado.
	$description = sanitize_text_field( (string) ( $item['description'] ?? '' ) );

	if ( papelito_benefits_length( $description ) > papelito_benefit_description_max_length() ) {
		return papelito_benefits_error(
			'papelito_benefit_description_too_long',
			sprintf(
				'Texto auxiliar do benefício #%d excede o limite de %d caracteres.',
				$number,
				papelito_benefit_description_max_length()
			)
		);
	}

	return array(
		'iconType'           => $icon_type,
		'iconEmoji'          => $icon_emoji,
		'iconAttachmentId'   => $icon_attachment_id,
		'iconUrl'            => $icon_url,
		'title'              => $title,
		'description'        => $description,
		'descriptionContent' => $content,
		'sortOrder'          => $index,
		'isActive'           => ! isset( $item['isActive'] ) || rest_sanitize_boolean( $item['isActive'] ),
	);
}

/**
 * Conta caracteres respeitando multibyte quando disponível.
 *
 * @param string $value Texto.
 * @return int
 */
function papelito_benefits_length( $value ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
}

/**
 * Resolve a URL de um ícone SVG.
 *
 * O attachment só é aceito se for de fato `image/svg+xml`; senão cai para o
 * caminho curado. Mesma política de `papelito_home_assets_resolve_svg_url()`,
 * mas sem o fallback silencioso para o default da Home.
 *
 * @param int   $attachment_id Id do anexo.
 * @param mixed $fallback_path Caminho interno alternativo.
 * @return string
 */
function papelito_benefit_resolve_svg_url( $attachment_id, $fallback_path = '' ) {
	$attachment_id = (int) $attachment_id;

	if ( $attachment_id > 0 && 'image/svg+xml' === get_post_mime_type( $attachment_id ) ) {
		$url = wp_get_attachment_url( $attachment_id );

		if ( is_string( $url ) && '' !== $url && strlen( $url ) <= 255 ) {
			return $url;
		}
	}

	return papelito_benefit_normalize_icon_path( $fallback_path );
}

/**
 * Normaliza a lista de alvos de um grupo.
 *
 * Aceita o formato do painel: `{ products: int[], collections: string[], categories: int[] }`.
 * Chave desconhecida e valor inválido são descartados; duplicata some pelo `array_unique`
 * antes mesmo de chegar ao banco.
 *
 * @param mixed $value Alvos brutos.
 * @return array<int, array{type:string,key:string}>|WP_Error
 */
function papelito_benefit_normalize_targets( $value ) {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$targets     = array();
	$collections = papelito_curated_collections();

	foreach ( array_map( 'absint', (array) ( $value['products'] ?? array() ) ) as $product_id ) {
		if ( $product_id <= 0 ) {
			continue;
		}

		if ( ! papelito_taxonomy_is_product( $product_id ) ) {
			return papelito_benefits_error(
				'papelito_benefit_unknown_product',
				sprintf( 'O produto #%d não existe.', $product_id )
			);
		}

		$targets[] = array(
			'type' => 'product',
			'key'  => (string) $product_id,
		);
	}

	foreach ( (array) ( $value['collections'] ?? array() ) as $slug ) {
		$slug = sanitize_key( (string) $slug );

		if ( '' === $slug ) {
			continue;
		}

		if ( ! in_array( $slug, $collections, true ) ) {
			return papelito_benefits_error(
				'papelito_benefit_unknown_collection',
				sprintf( 'A coleção "%s" não existe.', $slug )
			);
		}

		$targets[] = array(
			'type' => 'collection',
			'key'  => $slug,
		);
	}

	foreach ( array_map( 'absint', (array) ( $value['categories'] ?? array() ) ) as $category_id ) {
		if ( $category_id <= 0 ) {
			continue;
		}

		if ( null === papelito_category_get( $category_id ) ) {
			return papelito_benefits_error(
				'papelito_benefit_unknown_category',
				sprintf( 'A categoria #%d não existe.', $category_id )
			);
		}

		$targets[] = array(
			'type' => 'category',
			'key'  => (string) $category_id,
		);
	}

	return papelito_benefit_unique_targets( $targets );
}

/**
 * Remove alvos repetidos preservando a ordem de chegada.
 *
 * @param array<int, array{type:string,key:string}> $targets Alvos normalizados.
 * @return array<int, array{type:string,key:string}>
 */
function papelito_benefit_unique_targets( array $targets ) {
	$seen   = array();
	$unique = array();

	foreach ( $targets as $target ) {
		$signature = $target['type'] . ':' . $target['key'];

		if ( isset( $seen[ $signature ] ) ) {
			continue;
		}

		$seen[ $signature ] = true;
		$unique[]           = $target;
	}

	return $unique;
}

/**
 * Valida o payload completo de um grupo.
 *
 * @param mixed $input     Corpo recebido.
 * @param bool  $is_global Se o grupo alvo é o global.
 * @return array<string,mixed>|WP_Error
 */
function papelito_benefit_validate_group_payload( $input, $is_global = false ) {
	if ( ! is_array( $input ) ) {
		return papelito_benefits_error( 'papelito_benefit_invalid_payload', 'Payload inválido.' );
	}

	$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );

	if ( '' === $name ) {
		return papelito_benefits_error( 'papelito_benefit_missing_name', 'Informe um nome para a configuração.' );
	}

	if ( papelito_benefits_length( $name ) > 120 ) {
		return papelito_benefits_error(
			'papelito_benefit_name_too_long',
			'O nome da configuração excede o limite de 120 caracteres.'
		);
	}

	$raw_items = $input['items'] ?? array();

	if ( ! is_array( $raw_items ) ) {
		return papelito_benefits_error( 'papelito_benefit_invalid_items', 'A lista de benefícios é inválida.' );
	}

	if ( count( $raw_items ) > papelito_benefit_items_max() ) {
		return papelito_benefits_error(
			'papelito_benefit_too_many_items',
			sprintf( 'Uma configuração aceita no máximo %d benefícios.', papelito_benefit_items_max() )
		);
	}

	$items = array();

	foreach ( array_values( $raw_items ) as $index => $item ) {
		$normalized = papelito_benefit_normalize_item( $item, $index );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$items[] = $normalized;
	}

	// O grupo global vale para todo o catálogo: alvo explícito nele seria
	// contraditório e é descartado em silêncio, não recusado.
	$targets = $is_global ? array() : papelito_benefit_normalize_targets( $input['targets'] ?? array() );

	if ( is_wp_error( $targets ) ) {
		return $targets;
	}

	return array(
		'name'     => $name,
		'isActive' => ! isset( $input['isActive'] ) || rest_sanitize_boolean( $input['isActive'] ),
		'items'    => $items,
		'targets'  => $targets,
	);
}

// ------------------------------------------------------------------
// Leitura
// ------------------------------------------------------------------

/**
 * Converte uma linha crua de item no formato de saída.
 *
 * @param array<string,mixed> $row Linha do banco.
 * @return array<string,mixed>
 */
function papelito_benefit_item_shape( array $row ) {
	$content = null;

	if ( ! empty( $row['description_content'] ) ) {
		$decoded = json_decode( (string) $row['description_content'], true );
		$content = is_array( $decoded ) ? $decoded : null;
	}

	return array(
		'id'                 => (int) $row['id'],
		'iconType'           => (string) $row['icon_type'],
		'iconEmoji'          => (string) $row['icon_emoji'],
		'iconAttachmentId'   => (int) $row['icon_attachment_id'],
		'iconUrl'            => (string) $row['icon_url'],
		'title'              => (string) $row['title'],
		'description'        => (string) $row['description'],
		'descriptionContent' => $content,
		'sortOrder'          => (int) $row['sort_order'],
		'isActive'           => (bool) (int) $row['is_active'],
	);
}

/**
 * Índice completo de benefícios: grupos, itens e alvos em uma estrutura só.
 *
 * Cacheado por versão em um transient único. A resolução por produto roda em PHP
 * sobre o índice, então trocar um alvo não exige limpar chave por produto — o
 * bump da versão descarta tudo de uma vez. É o mesmo idioma de
 * `papelito_taxonomy_public_payload()`.
 *
 * @return array{groups:array<int,array<string,mixed>>,targets:array<string,int>,global:int}
 */
function papelito_product_benefits_index() {
	global $wpdb;

	$version = papelito_product_benefits_version();
	$key     = 'papelito_benefits_index_v' . $version;
	$cached  = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$tables = papelito_product_benefits_table_names();

	$group_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT id, name, is_global, is_active FROM {$tables['groups']} ORDER BY is_global DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	$item_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT * FROM {$tables['items']} ORDER BY group_id ASC, sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	$target_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT target_type, target_key, group_id FROM {$tables['targets']}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	$groups    = array();
	$global_id = 0;

	foreach ( (array) $group_rows as $row ) {
		$id            = (int) $row['id'];
		$groups[ $id ] = array(
			'id'       => $id,
			'name'     => (string) $row['name'],
			'isGlobal' => (bool) (int) $row['is_global'],
			'isActive' => (bool) (int) $row['is_active'],
			'items'    => array(),
			'targets'  => array(),
		);

		if ( $groups[ $id ]['isGlobal'] ) {
			$global_id = $id;
		}
	}

	foreach ( (array) $item_rows as $row ) {
		$group_id = (int) $row['group_id'];

		if ( isset( $groups[ $group_id ] ) ) {
			$groups[ $group_id ]['items'][] = papelito_benefit_item_shape( $row );
		}
	}

	$targets = array();

	foreach ( (array) $target_rows as $row ) {
		$group_id = (int) $row['group_id'];
		$type     = (string) $row['target_type'];
		$target   = (string) $row['target_key'];

		$targets[ $type . ':' . $target ] = $group_id;

		if ( isset( $groups[ $group_id ] ) ) {
			$groups[ $group_id ]['targets'][] = array(
				'type' => $type,
				'key'  => $target,
			);
		}
	}

	$index = array(
		'groups'  => $groups,
		'targets' => $targets,
		'global'  => $global_id,
	);

	set_transient( $key, $index, PAPELITO_BENEFITS_PUBLIC_TTL );

	return $index;
}

/**
 * Lista os grupos no formato do painel administrativo.
 *
 * @return array<int, array<string,mixed>>
 */
function papelito_benefit_groups_list() {
	$index  = papelito_product_benefits_index();
	$groups = array();

	foreach ( $index['groups'] as $group ) {
		$groups[] = papelito_benefit_group_admin_shape( $group );
	}

	return $groups;
}

/**
 * Busca um grupo pelo id.
 *
 * @param int $group_id Id do grupo.
 * @return array<string,mixed>|null
 */
function papelito_benefit_group_get( $group_id ) {
	$index    = papelito_product_benefits_index();
	$group_id = (int) $group_id;

	return isset( $index['groups'][ $group_id ] ) ? $index['groups'][ $group_id ] : null;
}

/**
 * Formata um grupo para o painel, agrupando os alvos por tipo.
 *
 * @param array<string,mixed> $group Grupo do índice.
 * @return array<string,mixed>
 */
function papelito_benefit_group_admin_shape( array $group ) {
	$targets = array(
		'products'    => array(),
		'collections' => array(),
		'categories'  => array(),
	);

	foreach ( $group['targets'] as $target ) {
		if ( 'product' === $target['type'] ) {
			$targets['products'][] = (int) $target['key'];
		} elseif ( 'collection' === $target['type'] ) {
			$targets['collections'][] = $target['key'];
		} elseif ( 'category' === $target['type'] ) {
			$targets['categories'][] = (int) $target['key'];
		}
	}

	return array(
		'id'       => $group['id'],
		'name'     => $group['name'],
		'isGlobal' => $group['isGlobal'],
		'isActive' => $group['isActive'],
		'items'    => $group['items'],
		'targets'  => $targets,
	);
}

/**
 * Avisos administrativos sobre um grupo.
 *
 * Não bloqueiam a gravação: o painel mostra e o admin decide. O caso que mais
 * importa é o grupo que reivindica alvos mas não tem nenhum item exibível —
 * como não há cadeia de fallback, ele apaga o bloco dos produtos que reivindicou.
 *
 * @param array<string,mixed> $group Grupo no formato do painel.
 * @return string[]
 */
function papelito_benefit_collect_group_issues( array $group ) {
	$issues = array();

	$active_items = array_filter(
		$group['items'],
		static function ( $item ) {
			return ! empty( $item['isActive'] );
		}
	);

	$target_count = count( $group['targets']['products'] )
		+ count( $group['targets']['collections'] )
		+ count( $group['targets']['categories'] );

	if ( empty( $active_items ) ) {
		$issues[] = $group['isGlobal']
			? 'A configuração global não tem nenhum benefício ativo: a faixa não aparece em nenhum produto.'
			: sprintf(
				'"%s" não tem nenhum benefício ativo: a faixa some nos produtos que ela atende.',
				$group['name']
			);
	}

	if ( ! $group['isGlobal'] && 0 === $target_count && $group['isActive'] ) {
		$issues[] = sprintf( '"%s" está ativa mas não foi aplicada a nenhum produto, coleção ou categoria.', $group['name'] );
	}

	return $issues;
}

/**
 * Snapshot administrativo com todos os grupos e seus avisos.
 *
 * @return array{groups:array<int,array<string,mixed>>,collections:string[],issues:string[]}
 */
function papelito_product_benefits_admin_snapshot() {
	$groups = papelito_benefit_groups_list();
	$issues = array();

	foreach ( $groups as $group ) {
		$issues = array_merge( $issues, papelito_benefit_collect_group_issues( $group ) );
	}

	return array(
		'groups'      => $groups,
		'collections' => array_values( papelito_curated_collections() ),
		'issues'      => $issues,
	);
}

// ------------------------------------------------------------------
// Resolução
// ------------------------------------------------------------------

/**
 * Resolve qual grupo de benefícios vale para um produto.
 *
 * Precedência `produto > coleção > categoria > global`, parando no primeiro
 * acerto entre os grupos ativos. O desempate dentro do nível de coleção é a
 * ordem declarada em `papelito_curated_collections()` — um produto pode estar em
 * `premium` e `kits` ao mesmo tempo, e sem essa ordem a resposta dependeria da
 * ordem de leitura do banco.
 *
 * @param int $product_id Id do produto.
 * @return array{groupId:int,groupName:string,source:string,items:array<int,array<string,mixed>>}
 */
function papelito_product_benefits_resolve( $product_id ) {
	$product_id = (int) $product_id;
	$index      = papelito_product_benefits_index();
	$empty      = array(
		'groupId'   => 0,
		'groupName' => '',
		'source'    => 'none',
		'items'     => array(),
	);

	if ( $product_id <= 0 ) {
		return $empty;
	}

	$candidates = array( array( 'product', (string) $product_id ) );

	foreach ( papelito_product_benefits_product_collections( $product_id ) as $slug ) {
		$candidates[] = array( 'collection', $slug );
	}

	$category = papelito_product_benefits_product_category_id( $product_id );

	if ( $category > 0 ) {
		$candidates[] = array( 'category', (string) $category );
	}

	foreach ( $candidates as $candidate ) {
		list( $type, $key ) = $candidate;
		$signature          = $type . ':' . $key;

		if ( ! isset( $index['targets'][ $signature ] ) ) {
			continue;
		}

		$group = $index['groups'][ $index['targets'][ $signature ] ] ?? null;

		if ( null === $group || ! $group['isActive'] ) {
			continue;
		}

		return papelito_product_benefits_shape_resolution( $group, $type );
	}

	$global = $index['groups'][ $index['global'] ] ?? null;

	if ( null === $global || ! $global['isActive'] ) {
		return $empty;
	}

	return papelito_product_benefits_shape_resolution( $global, 'global' );
}

/**
 * Monta a resposta pública a partir do grupo vencedor.
 *
 * @param array<string,mixed> $group  Grupo resolvido.
 * @param string              $source Nível que venceu.
 * @return array<string,mixed>
 */
function papelito_product_benefits_shape_resolution( array $group, $source ) {
	$items = array();

	foreach ( $group['items'] as $item ) {
		if ( empty( $item['isActive'] ) ) {
			continue;
		}

		$items[] = $item;
	}

	return array(
		'groupId'   => $group['id'],
		'groupName' => $group['name'],
		'source'    => $source,
		'items'     => $items,
	);
}

/**
 * Coleções do produto na ordem curada, ignorando slug desconhecido.
 *
 * @param int $product_id Id do produto.
 * @return string[]
 */
function papelito_product_benefits_product_collections( $product_id ) {
	if ( ! function_exists( 'papelito_product_get_collections' ) ) {
		return array();
	}

	$assigned = (array) papelito_product_get_collections( $product_id );
	$ordered  = array();

	foreach ( papelito_curated_collections() as $slug ) {
		if ( in_array( $slug, $assigned, true ) ) {
			$ordered[] = $slug;
		}
	}

	return $ordered;
}

/**
 * Id da categoria principal do produto.
 *
 * @param int $product_id Id do produto.
 * @return int
 */
function papelito_product_benefits_product_category_id( $product_id ) {
	if ( ! function_exists( 'papelito_product_get_category' ) ) {
		return 0;
	}

	$category = papelito_product_get_category( $product_id );

	if ( is_array( $category ) && ! empty( $category['id'] ) ) {
		return (int) $category['id'];
	}

	return 0;
}

// ------------------------------------------------------------------
// Escrita
// ------------------------------------------------------------------

/**
 * Cria um grupo de benefícios.
 *
 * @param array<string,mixed> $payload Payload já validado.
 * @return array<string,mixed>|WP_Error
 */
function papelito_benefit_group_create( array $payload ) {
	global $wpdb;

	$tables = papelito_product_benefits_table_names();
	$now    = papelito_benefits_now();

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return papelito_benefits_error(
			'papelito_benefit_create_failed',
			'Não foi possível iniciar a gravação da configuração.',
			500
		);
	}

	$inserted = $wpdb->insert(
		$tables['groups'],
		array(
			'name'       => $payload['name'],
			'is_global'  => 0,
			'global_key' => null,
			'is_active'  => $payload['isActive'] ? 1 : 0,
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%s', '%d', '%d', '%d', '%s', '%s' )
	);

	if ( false === $inserted ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return papelito_benefits_error(
			'papelito_benefit_create_failed',
			'Não foi possível criar a configuração.',
			500
		);
	}

	$group_id = (int) $wpdb->insert_id;

	$written = papelito_benefit_group_write_children( $group_id, $payload );

	if ( is_wp_error( $written ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $written;
	}

	if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return papelito_benefits_error(
			'papelito_benefit_create_failed',
			'Não foi possível concluir a gravação da configuração.',
			500
		);
	}

	papelito_product_benefits_touch( 'group', $group_id );

	return papelito_benefit_group_admin_shape( papelito_benefit_group_get( $group_id ) );
}

/**
 * Atualiza um grupo existente.
 *
 * @param int                 $group_id Id do grupo.
 * @param array<string,mixed> $payload  Payload já validado.
 * @return array<string,mixed>|WP_Error
 */
function papelito_benefit_group_update( $group_id, array $payload ) {
	global $wpdb;

	$group_id = (int) $group_id;
	$existing = papelito_benefit_group_get( $group_id );

	if ( null === $existing ) {
		return papelito_benefits_error(
			'papelito_benefit_group_not_found',
			'Configuração não encontrada.',
			404
		);
	}

	$tables = papelito_product_benefits_table_names();

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return papelito_benefits_error(
			'papelito_benefit_update_failed',
			'Não foi possível iniciar a gravação da configuração.',
			500
		);
	}

	// Valide antes de alterar os metadados do grupo: um conflito de alvo deve
	// deixar a configuração inteira exatamente como estava.
	$conflict = papelito_benefit_find_target_conflict( $group_id, $payload['targets'] );

	if ( is_wp_error( $conflict ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $conflict;
	}

	// O global não pode ser desativado: ele é o último nível da resolução e sem
	// ele um produto sem configuração específica ficaria sem faixa nenhuma.
	$is_active = $existing['isGlobal'] ? 1 : ( $payload['isActive'] ? 1 : 0 );

	$updated = $wpdb->update(
		$tables['groups'],
		array(
			'name'       => $payload['name'],
			'is_active'  => $is_active,
			'updated_at' => papelito_benefits_now(),
		),
		array( 'id' => $group_id ),
		array( '%s', '%d', '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return papelito_benefits_error(
			'papelito_benefit_update_failed',
			'Não foi possível gravar a configuração.',
			500
		);
	}

	$written = papelito_benefit_group_write_children( $group_id, $payload );

	if ( is_wp_error( $written ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $written;
	}

	if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return papelito_benefits_error(
			'papelito_benefit_update_failed',
			'Não foi possível concluir a gravação da configuração.',
			500
		);
	}

	papelito_product_benefits_touch( 'group', $group_id );

	return papelito_benefit_group_admin_shape( papelito_benefit_group_get( $group_id ) );
}

/**
 * Substitui itens e alvos de um grupo.
 *
 * Substituição total, não merge: o painel sempre manda a lista inteira, e é isso
 * que torna a reordenação um `sort_order` reindexado em vez de um patch.
 *
 * @param int                 $group_id Id do grupo.
 * @param array<string,mixed> $payload  Payload já validado.
 * @return true|WP_Error
 */
function papelito_benefit_group_write_children( $group_id, array $payload ) {
	global $wpdb;

	$tables   = papelito_product_benefits_table_names();
	$group_id = (int) $group_id;

	$conflict = papelito_benefit_find_target_conflict( $group_id, $payload['targets'] );

	if ( is_wp_error( $conflict ) ) {
		return $conflict;
	}

	$deleted_items = $wpdb->delete( $tables['items'], array( 'group_id' => $group_id ), array( '%d' ) );

	if ( false === $deleted_items ) {
		return papelito_benefits_error(
			'papelito_benefit_write_failed',
			'Não foi possível substituir os benefícios da configuração.',
			500
		);
	}

	foreach ( $payload['items'] as $item ) {
		$inserted_item = $wpdb->insert(
			$tables['items'],
			array(
				'group_id'            => $group_id,
				'icon_type'           => $item['iconType'],
				'icon_emoji'          => $item['iconEmoji'],
				'icon_attachment_id'  => $item['iconAttachmentId'],
				'icon_url'            => $item['iconUrl'],
				'title'               => $item['title'],
				'description'         => $item['description'],
				'description_content' => null === $item['descriptionContent']
					? null
					: wp_json_encode( $item['descriptionContent'] ),
				'sort_order'          => $item['sortOrder'],
				'is_active'           => $item['isActive'] ? 1 : 0,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( false === $inserted_item ) {
			return papelito_benefits_error(
				'papelito_benefit_write_failed',
				'Não foi possível gravar os benefícios da configuração.',
				500
			);
		}
	}

	$deleted_targets = $wpdb->delete( $tables['targets'], array( 'group_id' => $group_id ), array( '%d' ) );

	if ( false === $deleted_targets ) {
		return papelito_benefits_error(
			'papelito_benefit_write_failed',
			'Não foi possível substituir os alvos da configuração.',
			500
		);
	}

	foreach ( $payload['targets'] as $target ) {
		$inserted_target = $wpdb->insert(
			$tables['targets'],
			array(
				'target_type' => $target['type'],
				'target_key'  => $target['key'],
				'group_id'    => $group_id,
			),
			array( '%s', '%s', '%d' )
		);

		if ( false === $inserted_target ) {
			return papelito_benefits_error(
				'papelito_benefit_write_failed',
				'Não foi possível gravar os alvos da configuração.',
				500
			);
		}
	}

	return true;
}

/**
 * Detecta alvo já reivindicado por outro grupo.
 *
 * A PRIMARY KEY da tabela impede a gravação de qualquer jeito; esta checagem
 * existe para trocar o erro mudo do banco por uma mensagem que diz QUAL grupo
 * detém o alvo, que é o que o painel precisa para oferecer mover.
 *
 * @param int                                       $group_id Id do grupo que está gravando.
 * @param array<int, array{type:string,key:string}> $targets  Alvos pretendidos.
 * @return true|WP_Error
 */
function papelito_benefit_find_target_conflict( $group_id, array $targets ) {
	if ( empty( $targets ) ) {
		return true;
	}

	$index    = papelito_product_benefits_index();
	$group_id = (int) $group_id;

	foreach ( $targets as $target ) {
		$signature = $target['type'] . ':' . $target['key'];
		$owner     = $index['targets'][ $signature ] ?? 0;

		if ( 0 === $owner || $owner === $group_id ) {
			continue;
		}

		$owner_name = $index['groups'][ $owner ]['name'] ?? '';

		return papelito_benefits_error(
			'papelito_benefit_target_taken',
			sprintf(
				'%s já pertence à configuração "%s". Remova de lá antes de aplicar aqui.',
				papelito_benefit_target_label( $target ),
				$owner_name
			),
			422,
			array(
				'target'       => $target,
				'ownerGroupId' => $owner,
			)
		);
	}

	return true;
}

/**
 * Rótulo legível de um alvo, para a mensagem de conflito.
 *
 * @param array{type:string,key:string} $target Alvo.
 * @return string
 */
function papelito_benefit_target_label( array $target ) {
	if ( 'product' === $target['type'] ) {
		$title = get_the_title( (int) $target['key'] );

		return '' !== (string) $title
			? sprintf( 'O produto "%s"', $title )
			: sprintf( 'O produto #%d', (int) $target['key'] );
	}

	if ( 'collection' === $target['type'] ) {
		return sprintf( 'A coleção "%s"', $target['key'] );
	}

	$category = papelito_category_get( (int) $target['key'] );

	return sprintf( 'A categoria "%s"', is_array( $category ) ? $category['name'] : $target['key'] );
}

/**
 * Exclui um grupo.
 *
 * @param int $group_id Id do grupo.
 * @return true|WP_Error
 */
function papelito_benefit_group_delete( $group_id ) {
	global $wpdb;

	$group_id = (int) $group_id;
	$group    = papelito_benefit_group_get( $group_id );

	if ( null === $group ) {
		return papelito_benefits_error(
			'papelito_benefit_group_not_found',
			'Configuração não encontrada.',
			404
		);
	}

	if ( $group['isGlobal'] ) {
		return papelito_benefits_error(
			'papelito_benefit_global_not_deletable',
			'A configuração global não pode ser excluída: ela é o padrão de todos os produtos.'
		);
	}

	$tables = papelito_product_benefits_table_names();

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return papelito_benefits_error(
			'papelito_benefit_delete_failed',
			'Não foi possível iniciar a exclusão da configuração.',
			500
		);
	}

	$deleted_items   = $wpdb->delete( $tables['items'], array( 'group_id' => $group_id ), array( '%d' ) );
	$deleted_targets = false === $deleted_items
		? false
		: $wpdb->delete( $tables['targets'], array( 'group_id' => $group_id ), array( '%d' ) );
	$deleted_group   = false === $deleted_targets
		? false
		: $wpdb->delete( $tables['groups'], array( 'id' => $group_id ), array( '%d' ) );

	if ( false === $deleted_items || false === $deleted_targets || false === $deleted_group ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return papelito_benefits_error(
			'papelito_benefit_delete_failed',
			'Não foi possível excluir a configuração.',
			500
		);
	}

	if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return papelito_benefits_error(
			'papelito_benefit_delete_failed',
			'Não foi possível concluir a exclusão da configuração.',
			500
		);
	}

	papelito_product_benefits_touch( 'group', $group_id );

	return true;
}

// ------------------------------------------------------------------
// Seed
// ------------------------------------------------------------------

/**
 * Itens padrão do grupo global: os três benefícios que a PDP mostrava fixos.
 *
 * O primeiro guarda o texto plano "Com cupom" E o conteúdo rico com o token do
 * mínimo de frete grátis. Isso reproduz `formatFreeShippingCouponCopy` exatamente:
 * com o mínimo configurado sai "A partir de R$ 99,00 com cupom"; sem ele o token
 * não resolve e a frase degrada para "Com cupom", que é o que a PDP mostra hoje.
 *
 * @return array<int, array<string,mixed>>
 */
function papelito_product_benefits_default_items() {
	return array(
		array(
			'iconType'           => 'emoji',
			'iconEmoji'          => '🚚',
			'iconAttachmentId'   => 0,
			'iconUrl'            => '',
			'title'              => 'Frete Grátis',
			'description'        => 'Com cupom',
			'descriptionContent' => array(
				array(
					'type' => 'text',
					'text' => 'A partir de ',
				),
				array(
					'type'  => 'token',
					'token' => 'frete_gratis.minimo',
				),
				array(
					'type' => 'text',
					'text' => ' com cupom',
				),
			),
			'sortOrder'          => 0,
			'isActive'           => true,
		),
		array(
			'iconType'           => 'emoji',
			'iconEmoji'          => '↩️',
			'iconAttachmentId'   => 0,
			'iconUrl'            => '',
			'title'              => '30 Dias',
			'description'        => 'Troca grátis',
			'descriptionContent' => null,
			'sortOrder'          => 1,
			'isActive'           => true,
		),
		array(
			'iconType'           => 'emoji',
			'iconEmoji'          => '🔒',
			'iconAttachmentId'   => 0,
			'iconUrl'            => '',
			'title'              => 'Pagamento',
			'description'        => '100% seguro',
			'descriptionContent' => null,
			'sortOrder'          => 2,
			'isActive'           => true,
		),
	);
}

/**
 * Cria o grupo global com os benefícios atuais, sem sobrescrever edições.
 *
 * Roda no bootstrap de migration. Se já existe grupo global, não faz nada: o
 * conteúdo passou a ser do administrador.
 *
 * @return void
 */
function papelito_product_benefits_seed_global() {
	global $wpdb;

	$tables = papelito_product_benefits_table_names();

	$existing = $wpdb->get_var( "SELECT id FROM {$tables['groups']} WHERE is_global = 1 LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( null !== $existing ) {
		return;
	}

	$now = papelito_benefits_now();

	$inserted = $wpdb->insert(
		$tables['groups'],
		array(
			'name'       => 'Padrão',
			'is_global'  => 1,
			'global_key' => 1,
			'is_active'  => 1,
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%s', '%d', '%d', '%d', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return;
	}

	$group_id = (int) $wpdb->insert_id;

	if ( $group_id <= 0 ) {
		return;
	}

	foreach ( papelito_product_benefits_default_items() as $item ) {
		$wpdb->insert(
			$tables['items'],
			array(
				'group_id'            => $group_id,
				'icon_type'           => $item['iconType'],
				'icon_emoji'          => $item['iconEmoji'],
				'icon_attachment_id'  => $item['iconAttachmentId'],
				'icon_url'            => $item['iconUrl'],
				'title'               => $item['title'],
				'description'         => $item['description'],
				'description_content' => null === $item['descriptionContent']
					? null
					: wp_json_encode( $item['descriptionContent'] ),
				'sort_order'          => $item['sortOrder'],
				'is_active'           => 1,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	papelito_product_benefits_touch( 'seed', $group_id );
}
