<?php
/**
 * Coleções expostas no corredor da Home.
 *
 * A coleção é o registro canônico. Esta tabela guarda somente a configuração
 * editorial do card e nunca duplica slug, imagem ou texto calculado.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_COLLECTION_CARDS_PUBLIC_TTL' ) ) {
	define( 'PAPELITO_COLLECTION_CARDS_PUBLIC_TTL', 30 );
}

if ( ! defined( 'PAPELITO_COLLECTION_CARD_TITLE_MAX_LENGTH' ) ) {
	define( 'PAPELITO_COLLECTION_CARD_TITLE_MAX_LENGTH', 24 );
}

if ( ! defined( 'PAPELITO_COLLECTION_CARD_SUBTITLE_MAX_LENGTH' ) ) {
	define( 'PAPELITO_COLLECTION_CARD_SUBTITLE_MAX_LENGTH', 40 );
}

function papelito_collection_cards_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'papelito_collection_cards';
}

function papelito_collection_cards_install_tables(): void {
	global $wpdb;

	$table   = papelito_collection_cards_table();
	$charset = $wpdb->get_charset_collate();
	$existed = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( "CREATE TABLE {$table} (
  id VARCHAR(64) NOT NULL,
  collection_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(24) NOT NULL,
  subtitle VARCHAR(40) NOT NULL,
  indicator_key VARCHAR(32) NOT NULL DEFAULT 'NONE',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_collection (collection_id),
  KEY idx_active_sort (is_active, sort_order, id)
	) {$charset};" );

	if ( ! $existed ) {
		update_option( 'papelito_collection_cards_table_fresh', 1, false );
	}
}

function papelito_collection_cards_systems(): array {
	return array(
		'promocoes' => array( 'name' => 'Promoções', 'systemKey' => 'promotions', 'imageUrl' => '/images/categorias/icons/promocoes.webp' ),
		'novidades' => array( 'name' => 'Recém Chegados', 'systemKey' => 'new_arrivals', 'imageUrl' => '/images/categorias/icons/novidades.webp' ),
		'kits'      => array( 'name' => 'Kits', 'systemKey' => 'kits', 'imageUrl' => '/images/categorias/icons/kit.webp' ),
		'premium'   => array( 'name' => 'Premium', 'systemKey' => '', 'imageUrl' => '/images/categorias/icons/premium.webp' ),
	);
}

function papelito_collection_cards_ensure_system_collections(): void {
	global $wpdb;

	$collections = papelito_product_taxonomy_table_names()['collections'];
	$now        = papelito_taxonomy_now();
	$changed    = false;
	$backfill_images = ! get_option( 'papelito_collection_cards_system_images_backfilled', false );

	foreach ( papelito_collection_cards_systems() as $slug => $system ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, image_url, system_key FROM {$collections} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $row ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$collections,
				array(
					'slug'       => $slug,
					'name'       => $system['name'],
					'system_key' => $system['systemKey'] ?: null,
					'image_url'   => $system['imageUrl'],
					'sort_order'  => 0,
					'is_active'   => 1,
					'created_at'  => $now,
					'updated_at'  => $now,
			),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
			$changed = true;
			continue;
		}

		$updates = array();
		$formats = array();
		if ( $backfill_images && '' === (string) $row['image_url'] ) {
			$updates['image_url'] = $system['imageUrl'];
			$formats[]           = '%s';
		}
		if ( '' !== $system['systemKey'] && '' === (string) $row['system_key'] ) {
			$updates['system_key'] = $system['systemKey'];
			$formats[]             = '%s';
		}
		if ( ! empty( $updates ) ) {
			$updates['updated_at'] = $now;
			$formats[]             = '%s';
			$wpdb->update( $collections, $updates, array( 'id' => (int) $row['id'] ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$changed = true;
		}
	}
	if ( $backfill_images ) {
		update_option( 'papelito_collection_cards_system_images_backfilled', 1, false );
	}
	if ( $changed ) {
		papelito_product_taxonomy_touch( 'collection', 0 );
	}
}

function papelito_collection_cards_migrate_legacy_nav(): void {
	global $wpdb;

	papelito_collection_cards_ensure_system_collections();
	papelito_collection_cards_normalize_indicator_defaults();
	$option = 'papelito_home_collections_nav';
	$done   = 'papelito_collection_cards_migration_done';
	if ( get_option( $done, false ) ) {
		delete_option( $option );
		return;
	}
	$legacy = get_option( $option, null );
	$fresh_table = (bool) get_option( 'papelito_collection_cards_table_fresh', false );
	if ( null === $legacy && $fresh_table ) {
		$legacy = function_exists( 'papelito_home_assets_default_collections_nav_items' )
			? papelito_home_assets_default_collections_nav_items()
			: array();
	}
	if ( ! is_array( $legacy ) ) {
		update_option( $done, 1, false );
		delete_option( 'papelito_collection_cards_table_fresh' );
		return;
	}

	$table       = papelito_collection_cards_table();
	$card_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $card_count > 0 && false !== get_option( $option, false ) ) {
		delete_option( $option );
		update_option( $done, 1, false );
		delete_option( 'papelito_collection_cards_table_fresh' );
		return;
	}
	$collections = papelito_product_taxonomy_table_names()['collections'];
	foreach ( array_values( $legacy ) as $index => $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$slug = sanitize_key( (string) ( $item['collection'] ?? '' ) );
		if ( '' === $slug ) {
			$href = (string) ( $item['href'] ?? '' );
			if ( preg_match( '#^/(premium|promocoes|novidades|kits)(?:\?|$)#', $href, $match ) ) {
				$slug = $match[1];
			}
		}
		if ( '' === $slug ) {
			if ( preg_match( '/[?&]colecao=([^&]+)/', (string) ( $item['href'] ?? '' ), $match ) ) {
				$slug = sanitize_key( rawurldecode( $match[1] ) );
			}
		}
		$collection_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$collections} WHERE slug = %s AND is_active = 1 LIMIT 1", $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $collection_id <= 0 ) {
			continue;
		}
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE collection_id = %d LIMIT 1", $collection_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists > 0 ) {
			continue;
		}
		$indicator = 'NONE';
		if ( 'promocoes' === $slug ) {
			$indicator = 'MAX_DISCOUNT_PERCENT';
		} elseif ( 'novidades' === $slug ) {
			$indicator = 'NEW_ITEMS_COUNT';
		} elseif ( 'kits' === $slug ) {
			$indicator = 'ITEM_COUNT';
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'id'             => sanitize_key( (string) ( $item['id'] ?? $slug ) ),
				'collection_id'  => $collection_id,
				'title'         => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'subtitle'      => sanitize_text_field( (string) ( $item['subtitle'] ?? '' ) ),
				'indicator_key' => $indicator,
				'sort_order'    => $index + 1,
				'is_active'     => ! empty( $item['isActive'] ) ? 1 : 0,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}
	delete_option( $option );
	update_option( $done, 1, false );
	delete_option( 'papelito_collection_cards_table_fresh' );
}

/**
 * Alinha os cards sistêmicos ao contrato atual de destaques.
 *
 * Promoções, Recém Chegados e Kits sempre têm um indicador; Premium é comum e começa sem um.
 * Coleções manuais não são tocadas, porque o indicador escolhido pelo admin deve ser preservado.
 */
function papelito_collection_cards_normalize_indicator_defaults(): void {
	global $wpdb;
	$marker = 'papelito_collection_cards_indicator_defaults_migrated';
	if ( get_option( $marker, false ) ) {
		return;
	}

	$table       = papelito_collection_cards_table();
	$collections = papelito_product_taxonomy_table_names()['collections'];
	$wpdb->query( "UPDATE {$table} cards INNER JOIN {$collections} collections ON collections.id = cards.collection_id
		SET cards.indicator_key = CASE collections.slug
			WHEN 'promocoes' THEN 'MAX_DISCOUNT_PERCENT'
			WHEN 'novidades' THEN 'NEW_ITEMS_COUNT'
			WHEN 'kits' THEN 'ITEM_COUNT'
			WHEN 'premium' THEN 'NONE'
			ELSE cards.indicator_key
		END
		WHERE collections.slug IN ('promocoes', 'novidades', 'kits', 'premium')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	update_option( $marker, 1, false );
}

function papelito_collection_cards_public_path( array $collection ): string {
	$slug = (string) ( $collection['slug'] ?? '' );
	if ( 'kits' === $slug || 'promocoes' === $slug || 'novidades' === $slug || 'premium' === $slug ) {
		return '/' . $slug;
	}
	return '/colecoes?colecao=' . rawurlencode( $slug );
}

function papelito_collection_cards_policy( array $collection, array $metrics = array() ): array {
	$system = (string) ( $collection['systemKey'] ?? $collection['system_key'] ?? '' );
	if ( 'promotions' === $system ) {
		return array( 'required' => 'MAX_DISCOUNT_PERCENT', 'locked' => true, 'allowed' => array( 'MAX_DISCOUNT_PERCENT' ) );
	}
	if ( 'new_arrivals' === $system ) {
		return array( 'required' => 'NEW_ITEMS_COUNT', 'locked' => true, 'allowed' => array( 'NEW_ITEMS_COUNT' ) );
	}
	if ( 'kits' === $system ) {
		return array( 'required' => 'ITEM_COUNT', 'locked' => true, 'allowed' => array( 'ITEM_COUNT' ) );
	}
	$allowed = array( 'NONE', 'ITEM_COUNT' );
	if ( (int) ( $metrics['maxDiscount'] ?? 0 ) > 0 ) {
		$allowed[] = 'MAX_DISCOUNT_PERCENT';
	}
	if ( (int) ( $metrics['newItems'] ?? 0 ) > 0 ) {
		$allowed[] = 'NEW_ITEMS_COUNT';
	}
	if ( (int) ( $metrics['activeDeals'] ?? 0 ) > 0 ) {
		$allowed[] = 'ACTIVE_DEALS_COUNT';
	}
	return array( 'required' => null, 'locked' => false, 'allowed' => $allowed );
}

function papelito_collection_cards_metric_copy( string $key, array $metrics ): array {
	if ( 'NONE' === $key ) {
		return array( 'label' => 'Sem destaque dinâmico', 'text' => '', 'value' => 0 );
	}
	$metric_fields = array(
		'ITEM_COUNT'           => 'itemCount',
		'MAX_DISCOUNT_PERCENT' => 'maxDiscount',
		'NEW_ITEMS_COUNT'      => 'newItems',
		'ACTIVE_DEALS_COUNT'   => 'activeDeals',
	);
	$value = (int) ( $metrics[ $metric_fields[ $key ] ?? 'itemCount' ] ?? 0 );
	if ( 'MAX_DISCOUNT_PERCENT' === $key ) {
		return array( 'label' => 'Maior desconto', 'text' => $value > 0 ? sprintf( 'Até %d%% off', $value ) : 'Confira as ofertas', 'value' => $value );
	}
	if ( 'NEW_ITEMS_COUNT' === $key ) {
		return array( 'label' => 'Novidades', 'text' => 1 === $value ? '1 novo item' : ( $value > 0 ? sprintf( '%d novos itens', $value ) : 'Novidades chegando' ), 'value' => $value );
	}
	if ( 'ACTIVE_DEALS_COUNT' === $key ) {
		return array( 'label' => 'Ofertas ativas', 'text' => $value > 0 ? sprintf( '%d ofertas agora', $value ) : 'Confira as ofertas', 'value' => $value );
	}
	return array( 'label' => 'Quantidade de produtos', 'text' => 1 === $value ? '1 item para explorar' : ( $value > 0 ? sprintf( '%d itens para explorar', $value ) : 'Explore esta coleção' ), 'value' => $value );
}

function papelito_collection_cards_metrics( array $collections ): array {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $collections, 'id' ) ) ) );
	$metrics = array();
	foreach ( $ids as $id ) {
		$metrics[ $id ] = array( 'itemCount' => 0, 'maxDiscount' => 0, 'newItems' => 0, 'activeDeals' => 0 );
	}
	if ( empty( $ids ) ) {
		return $metrics;
	}
	$posts = $wpdb->posts;
	$meta  = $wpdb->postmeta;
	$in    = implode( ',', array_map( 'absint', $ids ) );
	$collections_table = papelito_product_taxonomy_table_names()['collections'];
	$prices = "(SELECT post_id,
		MAX(CASE WHEN meta_key = '_price' THEN CAST(REPLACE(meta_value, ',', '.') AS DECIMAL(20,6)) END) AS price,
		MAX(CASE WHEN meta_key = '_regular_price' THEN CAST(REPLACE(meta_value, ',', '.') AS DECIMAL(20,6)) END) AS regular_price,
		MAX(CASE WHEN meta_key = '_sale_price' THEN CAST(REPLACE(meta_value, ',', '.') AS DECIMAL(20,6)) END) AS sale_price
		FROM {$meta}
		WHERE meta_key IN ('_price', '_regular_price', '_sale_price')
		GROUP BY post_id) product_prices";
	$links = $wpdb->get_results( "SELECT c.id AS collection_id, p.ID, p.post_date, product_prices.price, product_prices.regular_price, product_prices.sale_price FROM {$collections_table} c INNER JOIN " . papelito_product_taxonomy_table_names()['product_collection'] . " pc ON pc.collection_slug = c.slug INNER JOIN {$posts} p ON p.ID = pc.product_id AND p.post_type = 'product' AND p.post_status = 'publish' LEFT JOIN {$prices} ON product_prices.post_id = p.ID WHERE c.id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$now = current_time( 'timestamp' );
	foreach ( $links as $row ) {
		$id = (int) $row['collection_id'];
		if ( ! isset( $metrics[ $id ] ) || (float) $row['price'] <= 0 ) {
			continue;
		}
		$metrics[ $id ]['itemCount']++;
		if ( (float) $row['sale_price'] > 0 && (float) $row['sale_price'] < (float) $row['regular_price'] ) {
			$metrics[ $id ]['activeDeals']++;
			$metrics[ $id ]['maxDiscount'] = max( $metrics[ $id ]['maxDiscount'], (int) round( ( 1 - ( (float) $row['sale_price'] / (float) $row['regular_price'] ) ) * 100 ) );
		}
	}
	foreach ( $collections as $collection ) {
		$id     = (int) $collection['id'];
		$system = (string) ( $collection['system_key'] ?? $collection['systemKey'] ?? '' );
		if ( 'promotions' !== $system && 'new_arrivals' !== $system && 'kits' !== $system ) {
			continue;
		}
		$rows = $wpdb->get_results( "SELECT p.ID, p.post_date, product_prices.price, product_prices.regular_price, product_prices.sale_price FROM {$posts} p LEFT JOIN {$prices} ON product_prices.post_id = p.ID " . ( 'kits' === $system ? "INNER JOIN " . $wpdb->prefix . "papelito_kits kit ON kit.product_id = p.ID " : '' ) . "WHERE p.post_type = 'product' AND p.post_status = 'publish'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$metrics[ $id ] = array( 'itemCount' => 0, 'maxDiscount' => 0, 'newItems' => 0, 'activeDeals' => 0 );
		$campaign = 'promotions' === $system && function_exists( 'papelito_flash_sale_normalize_campaign' ) ? papelito_flash_sale_normalize_campaign( papelito_flash_sale_get_raw_campaign() ) : null;
		$campaign_ids = $campaign && 'active' === ( $campaign['status'] ?? '' ) ? papelito_flash_sale_normalize_product_ids( $campaign['productIds'] ?? array() ) : array();
		$campaign_discount = $campaign ? (int) ( $campaign['discountPercent'] ?? 0 ) : 0;
		$derived_config = function_exists( 'papelito_collections_get_config' ) ? papelito_collections_get_config() : array();
		$new_limit = 'new_arrivals' === $system ? (int) ( $derived_config['newArrivals']['limit'] ?? 10 ) : 0;
		$new_expiration = 'new_arrivals' === $system ? (int) ( $derived_config['newArrivals']['expirationDays'] ?? 0 ) : 0;
		if ( 'new_arrivals' === $system ) {
			usort( $rows, static fn( array $left, array $right ): int => strcmp( (string) $right['post_date'], (string) $left['post_date'] ) );
		}
		foreach ( $rows as $row ) {
			if ( (float) $row['price'] <= 0 ) {
				continue;
			}
			if ( 'new_arrivals' === $system && $new_limit > 0 && $metrics[ $id ]['itemCount'] >= $new_limit ) {
				break;
			}
			if ( 'new_arrivals' === $system && $new_expiration > 0 && strtotime( (string) $row['post_date'] ) < $now - ( $new_expiration * DAY_IN_SECONDS ) ) {
				continue;
			}
			$native_promotion = (float) $row['sale_price'] > 0 && (float) $row['sale_price'] < (float) $row['regular_price'];
			$campaign_promotion = 'promotions' === $system && in_array( (int) $row['ID'], $campaign_ids, true );
			if ( 'promotions' === $system && ! $native_promotion && ! $campaign_promotion ) {
				continue;
			}
			$metrics[ $id ]['itemCount']++;
			if ( $native_promotion || $campaign_promotion ) {
				$metrics[ $id ]['activeDeals']++;
				$native_discount = $native_promotion && (float) $row['regular_price'] > 0 ? (int) round( ( 1 - ( (float) $row['sale_price'] / (float) $row['regular_price'] ) ) * 100 ) : 0;
				$metrics[ $id ]['maxDiscount'] = max( $metrics[ $id ]['maxDiscount'], $native_discount, $campaign_promotion ? $campaign_discount : 0 );
			}
			if ( 'new_arrivals' === $system ) {
				$metrics[ $id ]['newItems']++;
			}
		}
	}
	return $metrics;
}

function papelito_collection_cards_collection_shape( array $row, array $metrics = array() ): array {
	$collection = array(
		'id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'name' => (string) $row['name'],
		'description' => (string) ( $row['description'] ?? '' ), 'systemKey' => (string) ( $row['system_key'] ?? $row['systemKey'] ?? '' ),
		'imageAttachmentId' => (int) ( $row['image_attachment_id'] ?? $row['imageAttachmentId'] ?? 0 ), 'imageUrl' => (string) ( $row['image_url'] ?? $row['imageUrl'] ?? '' ),
		'isActive' => isset( $row['is_active'] ) ? (bool) $row['is_active'] : (bool) ( $row['isActive'] ?? false ), 'sortOrder' => (int) ( $row['sort_order'] ?? $row['sortOrder'] ?? 0 ),
	);
	$collection['path'] = papelito_collection_cards_public_path( $collection );
	$collection['indicatorPolicy'] = papelito_collection_cards_policy( $collection, $metrics );
	$collection['indicatorOptions'] = papelito_collection_cards_indicator_options( $collection, $metrics );
	return $collection;
}

function papelito_collection_cards_snapshot( bool $admin = false ): array {
	global $wpdb;
	$cache_key = '';
	if ( ! $admin ) {
		$version   = function_exists( 'papelito_product_taxonomy_version' ) ? papelito_product_taxonomy_version() : 0;
		$cache_key = 'papelito_collection_cards_public_v' . $version;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}
	$collections_table = papelito_product_taxonomy_table_names()['collections'];
	$rows = $wpdb->get_results( "SELECT * FROM {$collections_table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$metrics = papelito_collection_cards_metrics( $rows );
	$catalog = array();
	foreach ( $rows as $row ) {
		$catalog[] = papelito_collection_cards_collection_shape( $row, $metrics[ (int) $row['id'] ] ?? array() );
	}
	$table = papelito_collection_cards_table();
	$cards = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$by_id = array();
	foreach ( $catalog as $collection ) { $by_id[ $collection['id'] ] = $collection; }
	$items = array();
	foreach ( $cards as $card ) {
		$collection = $by_id[ (int) $card['collection_id'] ] ?? null;
		if ( ! $collection ) { continue; }
		$metric = papelito_collection_cards_metric_copy( (string) $card['indicator_key'], $metrics[ (int) $card['collection_id'] ] ?? array() );
		$public_collection = $collection;
		if ( ! $admin ) {
			unset( $public_collection['indicatorPolicy'], $public_collection['indicatorOptions'] );
		}
		$items[] = array(
			'id' => (string) $card['id'], 'title' => (string) $card['title'], 'subtitle' => (string) $card['subtitle'],
			'href' => $collection['path'], 'collection' => $collection['slug'], 'collectionId' => (int) $card['collection_id'],
			'indicatorKey' => (string) $card['indicator_key'], 'order' => (int) $card['sort_order'], 'isActive' => (bool) $card['is_active'],
			'collectionData' => $public_collection, 'highlight' => $metric,
			'indicatorOptions' => $admin ? papelito_collection_cards_indicator_options( $collection, $metrics[ (int) $card['collection_id'] ] ?? array() ) : array(),
		);
	}
	$available = array_values( array_filter( $catalog, static function ( array $collection ) use ( $items ): bool {
		foreach ( $items as $item ) { if ( (int) $item['collectionId'] === (int) $collection['id'] && ! empty( $item['isActive'] ) ) { return false; } }
		return true;
	} ) );
	$snapshot = array( 'items' => $admin ? $items : array_values( array_filter( $items, static fn( array $item ): bool => ! empty( $item['isActive'] ) && ! empty( $item['collectionData']['isActive'] ) ) ), 'collections' => $admin ? $available : array(), 'issues' => array() );
	if ( ! $admin && '' !== $cache_key ) {
		set_transient( $cache_key, $snapshot, PAPELITO_COLLECTION_CARDS_PUBLIC_TTL );
	}
	return $snapshot;
}

function papelito_collection_cards_indicator_options( array $collection, array $metrics ): array {
	$labels = array(
		'NONE' => array( 'label' => 'Sem destaque dinâmico', 'description' => 'Exibe o texto auxiliar do card, sem calcular um número.', 'preview' => 'Texto auxiliar do card' ),
		'ITEM_COUNT' => array( 'label' => 'Quantidade de produtos', 'description' => 'Mostra quantos itens estão disponíveis nesta coleção.' ),
		'MAX_DISCOUNT_PERCENT' => array( 'label' => 'Maior desconto', 'description' => 'Exibe o maior desconto ativo encontrado nesta coleção.' ),
		'NEW_ITEMS_COUNT' => array( 'label' => 'Novidades', 'description' => 'Mostra quantos produtos recentes existem nesta coleção.' ),
		'ACTIVE_DEALS_COUNT' => array( 'label' => 'Ofertas ativas', 'description' => 'Conta as ofertas válidas disponíveis nesta coleção.' ),
	);
	$policy = papelito_collection_cards_policy( $collection, $metrics );
	$options = array();
	foreach ( $policy['allowed'] as $key ) {
		$copy = papelito_collection_cards_metric_copy( $key, $metrics );
		$options[] = array( 'key' => $key, 'label' => $labels[ $key ]['label'], 'description' => $labels[ $key ]['description'], 'preview' => $labels[ $key ]['preview'] ?? $copy['text'] );
	}
	return $options;
}

function papelito_collection_cards_save( array $items ) {
	global $wpdb;
	if ( count( $items ) > 6 ) {
		return new WP_Error( 'papelito_collection_card_limit', 'O corredor aceita no máximo 6 cards.', array( 'status' => 422 ) );
	}
	$table = papelito_collection_cards_table();
	$collections_table = papelito_product_taxonomy_table_names()['collections'];
	$rows = $wpdb->get_results( "SELECT * FROM {$collections_table} WHERE is_active = 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$by_id = array(); foreach ( $rows as $row ) { $by_id[ (int) $row['id'] ] = $row; }
	$all_metrics = papelito_collection_cards_metrics( $rows );
	$seen = array(); $seen_ids = array(); $validated = array();
	foreach ( array_values( $items ) as $index => $item ) {
		if ( ! is_array( $item ) ) { return new WP_Error( 'papelito_collection_card_invalid', 'Card de coleção inválido.', array( 'status' => 422 ) ); }
		$collection_id = absint( $item['collectionId'] ?? 0 );
		if ( ! isset( $by_id[ $collection_id ] ) ) { return new WP_Error( 'papelito_collection_card_collection_required', 'Selecione uma coleção válida.', array( 'status' => 422 ) ); }
		if ( isset( $seen[ $collection_id ] ) ) { return new WP_Error( 'papelito_collection_card_duplicate', 'Uma coleção só pode ter um card ativo.', array( 'status' => 409 ) ); }
		$seen[ $collection_id ] = true;
		$collection = papelito_collection_cards_collection_shape( $by_id[ $collection_id ] );
		$metrics = $all_metrics[ $collection_id ] ?? array();
		$policy = papelito_collection_cards_policy( $collection, $metrics );
		$key = strtoupper( sanitize_key( (string) ( $item['indicatorKey'] ?? ( $policy['required'] ?? $policy['allowed'][0] ?? 'NONE' ) ) ) );
		if ( $policy['locked'] ) { $key = $policy['required']; }
		if ( ! in_array( $key, $policy['allowed'], true ) ) { return new WP_Error( 'papelito_collection_card_indicator_invalid', 'Escolha um destaque disponível para esta coleção.', array( 'status' => 422 ) ); }
		$title    = sanitize_text_field( (string) ( $item['title'] ?? $collection['name'] ) );
		$subtitle = sanitize_text_field( (string) ( $item['subtitle'] ?? '' ) );
		$length   = static fn( string $value ): int => function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( '' === $title || $length( $title ) > PAPELITO_COLLECTION_CARD_TITLE_MAX_LENGTH || $length( $subtitle ) > PAPELITO_COLLECTION_CARD_SUBTITLE_MAX_LENGTH ) {
			return new WP_Error( 'papelito_collection_card_text_invalid', 'Título e texto auxiliar excedem os limites permitidos.', array( 'status' => 422 ) );
		}
		$card_id = sanitize_key( (string) ( $item['id'] ?? '' ) );
		if ( '' === $card_id ) {
			$card_id = sanitize_key( wp_generate_uuid4() );
		}
		if ( isset( $seen_ids[ $card_id ] ) ) {
			return new WP_Error( 'papelito_collection_card_duplicate_id', 'Cada card precisa ter um identificador único.', array( 'status' => 422 ) );
		}
		$seen_ids[ $card_id ] = true;
		$validated[] = array( 'id' => $card_id, 'collection_id' => $collection_id, 'title' => $title, 'subtitle' => $subtitle, 'indicator_key' => $key, 'sort_order' => $index + 1, 'is_active' => ! empty( $item['isActive'] ) ? 1 : 0 );
	}
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$keep = array();
	foreach ( $validated as $card ) {
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE collection_id = %d LIMIT 1", $card['collection_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $existing && $existing['id'] !== $card['id'] ) { $card['id'] = $existing['id']; }
		$keep[] = $card['id'];
		$ok = $wpdb->replace( $table, $card, array( '%s', '%d', '%s', '%s', '%s', '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'papelito_collection_card_save_failed', 'Não foi possível salvar os cards.', array( 'status' => 500 ) ); }
	}
	$delete_ok = empty( $keep ) ? $wpdb->query( "DELETE FROM {$table}" ) : $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id NOT IN (" . implode( ',', array_fill( 0, count( $keep ), '%s' ) ) . ")", $keep ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $delete_ok ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_collection_card_save_failed', 'Não foi possível remover os cards antigos.', array( 'status' => 500 ) );
	}
	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_transient( 'papelito_collection_cards_public_v' . ( function_exists( 'papelito_product_taxonomy_version' ) ? papelito_product_taxonomy_version() : 0 ) );
	if ( function_exists( 'papelito_product_taxonomy_touch' ) ) {
		papelito_product_taxonomy_touch( 'collection_cards', 0 );
	}
	return true;
}
