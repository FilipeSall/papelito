<?php

defined( 'ABSPATH' ) || exit;

const PAPELITO_KIT_PRODUCT_META  = '_papelito_kit_id';
const PAPELITO_KIT_DEFAULT_IMAGE = '/images/categorias/icons/kit.webp';
const PAPELITO_KIT_IMAGE_PRESETS = array(
	'fallback' => '/images/categorias/icons/kit.webp',
	'kit'      => '/images/categorias/kit.webp',
	'premium'  => '/images/categorias/premium.webp',
);

function papelito_kits_table_names(): array {
	global $wpdb;

	return array(
		'kits'        => $wpdb->prefix . 'papelito_kits',
		'items'       => $wpdb->prefix . 'papelito_kit_items',
		'merchandise' => $wpdb->prefix . 'papelito_kit_merchandise',
		'merch_stock' => $wpdb->prefix . 'papelito_kit_merchandise_stock',
	);
}

function papelito_kits_install_tables(): void {
	global $wpdb;

	$tables  = papelito_kits_table_names();
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$tables['kits']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  image_source VARCHAR(24) NOT NULL DEFAULT 'fallback',
  image_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_product (product_id)
) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$tables['items']} (
  kit_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  PRIMARY KEY  (kit_id, product_id),
  KEY idx_product (product_id)
) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$tables['merchandise']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kit_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  image_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  quantity INT UNSIGNED NOT NULL,
  weight DECIMAL(12,4) NOT NULL,
  length DECIMAL(12,2) NOT NULL,
  width DECIMAL(12,2) NOT NULL,
  height DECIMAL(12,2) NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_kit (kit_id)
) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$tables['merch_stock']} (
  merchandise_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (merchandise_id, vendor_id),
  KEY idx_vendor (vendor_id)
) {$charset};"
	);
}

function papelito_kits_remove_legacy_collection(): void {
	// Os vínculos legados são histórico de negócio. A nova entidade Kit não os
	// consulta, mas removê-los torna rollback e auditoria impossíveis.
}

function papelito_kit_image_url( array $kit ): string {
	$source = sanitize_key( (string) ( $kit['image_source'] ?? 'fallback' ) );
	if ( 'custom' === $source ) {
		$url = wp_get_attachment_image_url( absint( $kit['image_attachment_id'] ?? 0 ), 'medium' );
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
	}

	return PAPELITO_KIT_IMAGE_PRESETS[ $source ] ?? PAPELITO_KIT_DEFAULT_IMAGE;
}

function papelito_kit_get( int $kit_id ): ?array {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['kits']} WHERE id = %d", $kit_id ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

function papelito_kit_get_by_product( int $product_id ): ?array {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['kits']} WHERE product_id = %d", $product_id ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

function papelito_kit_is_product( int $product_id ): bool {
	return null !== papelito_kit_get_by_product( $product_id );
}

function papelito_kit_items( int $kit_id ): array {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, quantity FROM {$tables['items']} WHERE kit_id = %d ORDER BY product_id ASC", $kit_id ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

function papelito_kit_merchandise( int $kit_id ): array {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, image_attachment_id, quantity, weight, length, width, height FROM {$tables['merchandise']} WHERE kit_id = %d ORDER BY id ASC", $kit_id ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

function papelito_kit_merchandise_stock( int $merchandise_id, int $vendor_id ): int {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$qty    = $wpdb->get_var( $wpdb->prepare( "SELECT qty FROM {$tables['merch_stock']} WHERE merchandise_id = %d AND vendor_id = %d", $merchandise_id, $vendor_id ) );
	return null === $qty ? 0 : (int) $qty;
}

function papelito_kit_merchandise_stocks( int $merchandise_id ): array {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT vendor_id, qty FROM {$tables['merch_stock']} WHERE merchandise_id = %d ORDER BY vendor_id ASC", $merchandise_id ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

function papelito_kit_adjust_merchandise_stock( int $merchandise_id, int $vendor_id, int $delta ) {
	global $wpdb;
	if ( $merchandise_id <= 0 || $vendor_id <= 0 ) {
		return new WP_Error( 'papelito_kit_merchandise_stock_invalid', 'Estoque de brinde inválido.', array( 'status' => 422 ) );
	}
	$tables = papelito_kits_table_names();
	$wpdb->query( 'START TRANSACTION' );
	$row     = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT qty FROM {$tables['merch_stock']} WHERE merchandise_id = %d AND vendor_id = %d FOR UPDATE",
			$merchandise_id,
			$vendor_id
		),
		ARRAY_A
	);
	$current = $row ? (int) $row['qty'] : 0;
	$next    = $current + $delta;
	if ( $next < 0 ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_checkout_insufficient_stock', 'Estoque de brinde insuficiente para o Kit.', array( 'status' => 409 ) );
	}
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$tables['merch_stock']} (merchandise_id, vendor_id, qty) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE qty = VALUES(qty)",
			$merchandise_id,
			$vendor_id,
			$next
		)
	);
	if ( false === $result ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_kit_merchandise_stock_write_failed', 'Não foi possível atualizar o estoque de brinde.', array( 'status' => 500 ) );
	}
	$wpdb->query( 'COMMIT' );
	return true;
}

function papelito_kit_expand_requirements( int $kit_product_id, int $kit_qty ): array|WP_Error {
	$kit = papelito_kit_get_by_product( $kit_product_id );
	if ( ! $kit ) {
		return new WP_Error( 'papelito_kit_not_found', 'Kit não encontrado.', array( 'status' => 404 ) );
	}
	$items = papelito_kit_items( (int) $kit['id'] );
	if ( empty( $items ) ) {
		return new WP_Error( 'papelito_kit_empty', 'O Kit precisa ter ao menos um produto.', array( 'status' => 422 ) );
	}
	$products = array();
	foreach ( $items as $item ) {
		$product_id = absint( $item['product_id'] ?? 0 );
		$quantity   = absint( $item['quantity'] ?? 0 ) * $kit_qty;
		if ( $product_id <= 0 || $quantity <= 0 || ! wc_get_product( $product_id ) ) {
			return new WP_Error( 'papelito_kit_component_invalid', 'Um produto do Kit não está mais disponível.', array( 'status' => 422 ) );
		}
		$products[ $product_id ] = ( $products[ $product_id ] ?? 0 ) + $quantity;
	}
	$merchandise = array();
	foreach ( papelito_kit_merchandise( (int) $kit['id'] ) as $item ) {
		$merchandise[] = array_merge( $item, array( 'required_quantity' => absint( $item['quantity'] ?? 0 ) * $kit_qty ) );
	}
	return array(
		'kit'         => $kit,
		'products'    => $products,
		'merchandise' => $merchandise,
	);
}

function papelito_kit_vendor_has_stock( int $kit_product_id, int $kit_qty, int $vendor_id ) {
	$requirements = papelito_kit_expand_requirements( $kit_product_id, $kit_qty );
	if ( is_wp_error( $requirements ) ) {
		return $requirements;
	}
	foreach ( $requirements['products'] as $product_id => $quantity ) {
		if ( papelito_get_vendor_stock( $vendor_id, $product_id ) < $quantity ) {
			return new WP_Error(
				'papelito_checkout_insufficient_stock',
				'O vendor não possui todos os produtos necessários para este Kit.',
				array(
					'status'     => 409,
					'product_id' => $product_id,
				)
			);
		}
	}
	foreach ( $requirements['merchandise'] as $merchandise ) {
		if ( papelito_kit_merchandise_stock( (int) $merchandise['id'], $vendor_id ) < (int) $merchandise['required_quantity'] ) {
			return new WP_Error(
				'papelito_checkout_insufficient_stock',
				'O vendor não possui todos os brindes necessários para este Kit.',
				array(
					'status'         => 409,
					'merchandise_id' => (int) $merchandise['id'],
				)
			);
		}
	}
	return true;
}

function papelito_kit_stock_rows_by_vendor( int $kit_product_id, int $kit_qty, array $vendor_ids ): array {
	$rows = array();
	foreach ( array_unique( array_map( 'absint', $vendor_ids ) ) as $vendor_id ) {
		if ( $vendor_id <= 0 ) {
			continue;
		}
		$available = papelito_kit_vendor_has_stock( $kit_product_id, $kit_qty, $vendor_id );
		if ( true === $available ) {
			$rows[] = array(
				'vendor_id' => $vendor_id,
				'qty'       => $kit_qty,
			);
		}
	}
	return $rows;
}

function papelito_kits_stock_rows_by_vendor_batch( array $product_ids, array $qty_by_product, array $vendor_ids ): array {
	global $wpdb;
	$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	$vendor_ids  = array_values( array_unique( array_filter( array_map( 'absint', $vendor_ids ) ) ) );
	if ( empty( $product_ids ) || empty( $vendor_ids ) ) {
		return array();
	}
	$tables               = papelito_kits_table_names();
	$product_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
	$kits                 = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, product_id FROM {$tables['kits']} WHERE product_id IN ({$product_placeholders})", $product_ids ),
		ARRAY_A
	);
	if ( ! is_array( $kits ) || empty( $kits ) ) {
		return array();
	}
	$kit_ids          = array_map( static fn( array $kit ): int => (int) $kit['id'], $kits );
	$kit_placeholders = implode( ',', array_fill( 0, count( $kit_ids ), '%d' ) );
	$items            = $wpdb->get_results(
		$wpdb->prepare( "SELECT kit_id, product_id, quantity FROM {$tables['items']} WHERE kit_id IN ({$kit_placeholders})", $kit_ids ),
		ARRAY_A
	);
	$merchandise      = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, kit_id, quantity FROM {$tables['merchandise']} WHERE kit_id IN ({$kit_placeholders})", $kit_ids ),
		ARRAY_A
	);
	$requirements     = array();
	$component_ids    = array();
	$merchandise_ids  = array();
	foreach ( $kits as $kit ) {
		$requirements[ (int) $kit['id'] ] = array(
			'components'  => array(),
			'merchandise' => array(),
		);
	}
	foreach ( is_array( $items ) ? $items : array() as $item ) {
		$kit_id = (int) $item['kit_id'];
		if ( ! isset( $requirements[ $kit_id ] ) ) {
			continue;
		}
		$product_id = (int) $item['product_id'];
		$requirements[ $kit_id ]['components'][ $product_id ] = ( $requirements[ $kit_id ]['components'][ $product_id ] ?? 0 ) + (int) $item['quantity'];
		$component_ids[]                                      = $product_id;
	}
	foreach ( is_array( $merchandise ) ? $merchandise : array() as $item ) {
		$kit_id = (int) $item['kit_id'];
		if ( ! isset( $requirements[ $kit_id ] ) ) {
			continue;
		}
		$requirements[ $kit_id ]['merchandise'][ (int) $item['id'] ] = (int) $item['quantity'];
		$merchandise_ids[] = (int) $item['id'];
	}
	$vendor_placeholders = implode( ',', array_fill( 0, count( $vendor_ids ), '%d' ) );
	$product_stock       = array();
	if ( ! empty( $component_ids ) && function_exists( 'papelito_vendor_stock_table_names' ) ) {
		$stock_tables           = papelito_vendor_stock_table_names();
		$component_ids          = array_values( array_unique( $component_ids ) );
		$component_placeholders = implode( ',', array_fill( 0, count( $component_ids ), '%d' ) );
		$rows                   = $wpdb->get_results(
			$wpdb->prepare( "SELECT product_id, vendor_id, qty FROM {$stock_tables['stock']} WHERE product_id IN ({$component_placeholders}) AND vendor_id IN ({$vendor_placeholders})", array_merge( $component_ids, $vendor_ids ) ),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$product_stock[ (int) $row['product_id'] ][ (int) $row['vendor_id'] ] = (int) $row['qty'];
		}
	}
	$merchandise_stock = array();
	if ( ! empty( $merchandise_ids ) ) {
		$merchandise_ids          = array_values( array_unique( $merchandise_ids ) );
		$merchandise_placeholders = implode( ',', array_fill( 0, count( $merchandise_ids ), '%d' ) );
		$rows                     = $wpdb->get_results(
			$wpdb->prepare( "SELECT merchandise_id, vendor_id, qty FROM {$tables['merch_stock']} WHERE merchandise_id IN ({$merchandise_placeholders}) AND vendor_id IN ({$vendor_placeholders})", array_merge( $merchandise_ids, $vendor_ids ) ),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$merchandise_stock[ (int) $row['merchandise_id'] ][ (int) $row['vendor_id'] ] = (int) $row['qty'];
		}
	}
	$result = array();
	foreach ( $kits as $kit ) {
		$kit_id     = (int) $kit['id'];
		$product_id = (int) $kit['product_id'];
		$requested  = max( 1, (int) ( $qty_by_product[ $product_id ] ?? 1 ) );
		if ( empty( $requirements[ $kit_id ]['components'] ) ) {
			continue;
		}
		foreach ( $vendor_ids as $vendor_id ) {
			$available = PHP_INT_MAX;
			foreach ( $requirements[ $kit_id ]['components'] as $component_id => $component_qty ) {
				$available = min( $available, intdiv( max( 0, (int) ( $product_stock[ $component_id ][ $vendor_id ] ?? 0 ) ), $component_qty ) );
			}
			foreach ( $requirements[ $kit_id ]['merchandise'] as $merchandise_id => $merchandise_qty ) {
				$available = min( $available, intdiv( max( 0, (int) ( $merchandise_stock[ $merchandise_id ][ $vendor_id ] ?? 0 ) ), $merchandise_qty ) );
			}
			if ( $available >= $requested ) {
				$result[ $product_id ][] = array(
					'vendor_id' => $vendor_id,
					'qty'       => $available,
				);
			}
		}
	}
	return $result;
}

function papelito_kit_snapshot( int $kit_product_id, int $kit_qty ): array {
	$requirements = papelito_kit_expand_requirements( $kit_product_id, $kit_qty );
	if ( is_wp_error( $requirements ) ) {
		return array();
	}
	$components = array();
	foreach ( $requirements['products'] as $product_id => $quantity ) {
		$product      = wc_get_product( $product_id );
		$components[] = array(
			'productId' => $product_id,
			'name'      => $product ? sanitize_text_field( $product->get_name() ) : '',
			'quantity'  => $quantity,
		);
	}
	return array(
		'kitId'       => (int) $requirements['kit']['id'],
		'components'  => $components,
		'merchandise' => array_map(
			static fn( array $item ): array => array(
				'id'       => (int) $item['id'],
				'name'     => sanitize_text_field( (string) $item['name'] ),
				'quantity' => (int) $item['required_quantity'],
			),
			$requirements['merchandise']
		),
	);
}

function papelito_kit_snapshot_requirements( array $snapshot ): array|WP_Error {
	$components = array();
	foreach ( (array) ( $snapshot['components'] ?? array() ) as $component ) {
		$product_id = absint( $component['productId'] ?? 0 );
		$quantity   = absint( $component['quantity'] ?? 0 );
		if ( $product_id <= 0 || $quantity <= 0 ) {
			return new WP_Error( 'papelito_kit_snapshot_invalid', 'A composição salva do Kit é inválida.', array( 'status' => 409 ) );
		}
		$components[ $product_id ] = ( $components[ $product_id ] ?? 0 ) + $quantity;
	}
	if ( empty( $components ) ) {
		return new WP_Error( 'papelito_kit_snapshot_invalid', 'A composição salva do Kit está vazia.', array( 'status' => 409 ) );
	}
	$merchandise = array();
	foreach ( (array) ( $snapshot['merchandise'] ?? array() ) as $item ) {
		$merchandise_id = absint( $item['id'] ?? 0 );
		$quantity       = absint( $item['quantity'] ?? 0 );
		if ( $merchandise_id <= 0 || $quantity <= 0 ) {
			return new WP_Error( 'papelito_kit_snapshot_invalid', 'O brinde salvo do Kit é inválido.', array( 'status' => 409 ) );
		}
		$merchandise[ $merchandise_id ] = ( $merchandise[ $merchandise_id ] ?? 0 ) + $quantity;
	}
	return array(
		'components'  => $components,
		'merchandise' => $merchandise,
	);
}

function papelito_kit_adjust_snapshot_stock( array $snapshot, int $vendor_id, int $direction, string $reason ) {
	$requirements = papelito_kit_snapshot_requirements( $snapshot );
	if ( is_wp_error( $requirements ) ) {
		return $requirements;
	}
	$adjusted = array();
	foreach ( $requirements['components'] as $product_id => $quantity ) {
		$result = papelito_adjust_vendor_stock( $vendor_id, $product_id, $direction * $quantity, $reason );
		if ( is_wp_error( $result ) ) {
			break;
		}
		$adjusted[] = array(
			'type'     => 'product',
			'id'       => $product_id,
			'quantity' => $quantity,
		);
	}
	if ( ! isset( $result ) || ! is_wp_error( $result ) ) {
		foreach ( $requirements['merchandise'] as $merchandise_id => $quantity ) {
			$result = papelito_kit_adjust_merchandise_stock( $merchandise_id, $vendor_id, $direction * $quantity );
			if ( is_wp_error( $result ) ) {
				break;
			}
			$adjusted[] = array(
				'type'     => 'merchandise',
				'id'       => $merchandise_id,
				'quantity' => $quantity,
			);
		}
	}
	if ( ! isset( $result ) || ! is_wp_error( $result ) ) {
		return true;
	}
	foreach ( array_reverse( $adjusted ) as $entry ) {
		if ( 'product' === $entry['type'] ) {
			papelito_adjust_vendor_stock( $vendor_id, $entry['id'], $direction * -1 * $entry['quantity'], $reason . '_rollback' );
			continue;
		}
		papelito_kit_adjust_merchandise_stock( $entry['id'], $vendor_id, $direction * -1 * $entry['quantity'] );
	}
	return $result;
}

function papelito_adjust_stock_line( array $line, int $direction, string $reason ) {
	$vendor_id  = absint( $line['vendor_id'] ?? 0 );
	$product_id = absint( $line['product_id'] ?? 0 );
	$quantity   = absint( $line['qty'] ?? 0 );
	if ( $vendor_id <= 0 || $product_id <= 0 || $quantity <= 0 ) {
		return new WP_Error( 'papelito_stock_line_invalid', 'Linha de estoque inválida.', array( 'status' => 422 ) );
	}
	if ( ! papelito_kit_is_product( $product_id ) ) {
		return papelito_adjust_vendor_stock( $vendor_id, $product_id, $direction * $quantity, $reason );
	}
	$snapshot = isset( $line['kit_snapshot']['components'] ) && is_array( $line['kit_snapshot']['components'] ) ? $line['kit_snapshot'] : papelito_kit_snapshot( $product_id, $quantity );
	return papelito_kit_adjust_snapshot_stock( $snapshot, $vendor_id, $direction, $reason );
}

function papelito_kit_shipping_items( array $items ): array|WP_Error {
	$expanded = array();
	foreach ( $items as $item ) {
		$product_id = absint( $item['product_id'] ?? 0 );
		$qty        = max( 1, absint( $item['qty'] ?? 1 ) );
		if ( ! papelito_kit_is_product( $product_id ) ) {
			$expanded[] = $item;
			continue;
		}
		$requirements = papelito_kit_expand_requirements( $product_id, $qty );
		if ( is_wp_error( $requirements ) ) {
			return $requirements;
		}
		$value   = 0.0;
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$value = (float) $product->get_price() * $qty;
		}
		$is_first = true;
		foreach ( $requirements['products'] as $component_id => $component_qty ) {
			$expanded[] = array(
				'product_id'     => $component_id,
				'qty'            => $component_qty,
				'declared_value' => $is_first ? $value : 0.0,
			);
			$is_first   = false;
		}
		foreach ( $requirements['merchandise'] as $merchandise ) {
			$expanded[] = array(
				'merchandise'    => $merchandise,
				'qty'            => 1,
				'declared_value' => 0.0,
			);
		}
	}
	return $expanded;
}

function papelito_kit_adjust_requirements_stock( int $kit_product_id, int $kit_qty, int $vendor_id, int $direction, string $reason = 'kit_order' ) {
	$snapshot = papelito_kit_snapshot( $kit_product_id, $kit_qty );
	return papelito_kit_adjust_snapshot_stock( $snapshot, $vendor_id, $direction, $reason );
}

function papelito_kit_response( array $kit ): array {
	$product         = wc_get_product( (int) $kit['product_id'] );
	$items           = array();
	$reference_cents = 0;
	foreach ( papelito_kit_items( (int) $kit['id'] ) as $item ) {
		$product_id       = (int) $item['product_id'];
		$component        = wc_get_product( $product_id );
		$quantity         = (int) $item['quantity'];
		$current_price    = $component ? (float) $component->get_price() : 0.0;
		$reference_cents += (int) round( $current_price * 100 ) * $quantity;
		$items[]          = array(
			'productId'         => $product_id,
			'name'              => $component ? sanitize_text_field( $component->get_name() ) : 'Produto removido',
			'sku'               => $component ? sanitize_text_field( $component->get_sku() ) : '',
			'imageUrl'          => $component ? (string) wp_get_attachment_image_url( $component->get_image_id(), 'thumbnail' ) : '',
			'quantity'          => $quantity,
			'currentPriceCents' => (int) round( $current_price * 100 ),
		);
	}
	$merchandise = array_map(
		static fn( array $item ): array => array(
			'id'                => (int) $item['id'],
			'name'              => $item['name'],
			'imageAttachmentId' => (int) $item['image_attachment_id'],
			'imageUrl'          => (string) ( wp_get_attachment_image_url( (int) $item['image_attachment_id'], 'thumbnail' ) ?: PAPELITO_KIT_DEFAULT_IMAGE ),
			'quantity'          => (int) $item['quantity'],
			'weight'            => (string) $item['weight'],
			'length'            => (string) $item['length'],
			'width'             => (string) $item['width'],
			'height'            => (string) $item['height'],
			'stocks'            => array_map(
				static fn( array $stock ): array => array(
					'vendorId' => (int) $stock['vendor_id'],
					'qty'      => (int) $stock['qty'],
				),
				papelito_kit_merchandise_stocks( (int) $item['id'] )
			),
		),
		papelito_kit_merchandise( (int) $kit['id'] )
	);
	return array(
		'id'                  => (int) $kit['id'],
		'productId'           => (int) $kit['product_id'],
		'name'                => $product ? sanitize_text_field( $product->get_name() ) : 'Kit',
		'slug'                => $product ? sanitize_title( $product->get_slug() ) : '',
		'status'              => $product ? $product->get_status() : 'draft',
		'price'               => $product ? (string) $product->get_regular_price() : '',
		'salePrice'           => $product ? (string) $product->get_sale_price() : '',
		'imageUrl'            => papelito_kit_image_url( $kit ),
		'imageSource'         => sanitize_key( (string) $kit['image_source'] ),
		'items'               => $items,
		'merchandise'         => $merchandise,
		'referencePriceCents' => $reference_cents,
	);
}

function papelito_kit_public_response( array $kit ): array {
	$product = wc_get_product( (int) $kit['product_id'] );
	if ( ! $product ) {
		return array();
	}
	return array(
		'productId' => (int) $kit['product_id'],
		'name'      => sanitize_text_field( $product->get_name() ),
		'slug'      => sanitize_title( $product->get_slug() ),
		'price'     => (string) $product->get_regular_price(),
		'salePrice' => $product->is_on_sale() ? (string) $product->get_price() : '',
		'imageUrl'  => papelito_kit_image_url( $kit ),
	);
}

function papelito_kits_invalidate_public_cache(): void {
	delete_transient( 'papelito_kits_public' );
}

function papelito_kits_require_admin() {
	return current_user_can( 'manage_options' ) ? true : new WP_Error( 'papelito_kits_forbidden', 'Acesso administrativo necessário.', array( 'status' => 403 ) );
}

function papelito_kit_write( array $payload, ?int $kit_id = null ) {
	global $wpdb;

	if ( ! class_exists( 'WC_Product_Simple' ) ) {
		return new WP_Error( 'papelito_woocommerce_unavailable', 'WooCommerce indisponível.', array( 'status' => 500 ) );
	}
	$name          = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
	$regular_price = wc_format_decimal( (string) ( $payload['price'] ?? '' ) );
	$items         = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
	if ( '' === $name || ! is_numeric( $regular_price ) || (float) $regular_price <= 0 || empty( $items ) ) {
		return new WP_Error( 'papelito_kit_invalid', 'Informe nome, preço e ao menos um produto para o Kit.', array( 'status' => 422 ) );
	}
	$seen_component_ids = array();
	foreach ( $items as $item ) {
		$component_id = absint( $item['productId'] ?? 0 );
		$quantity     = absint( $item['quantity'] ?? 0 );
		if ( $component_id <= 0 || $quantity <= 0 || isset( $seen_component_ids[ $component_id ] ) || ! wc_get_product( $component_id ) || papelito_kit_is_product( $component_id ) ) {
			return new WP_Error( 'papelito_kit_component_invalid', 'Os produtos do Kit precisam ser produtos comuns, únicos e com quantidade positiva.', array( 'status' => 422 ) );
		}
		$seen_component_ids[ $component_id ] = true;
	}
	foreach ( (array) ( $payload['merchandise'] ?? array() ) as $item ) {
		$dimensions          = array_map( static fn( $value ): float => (float) wc_format_decimal( (string) $value ), array( $item['weight'] ?? 0, $item['length'] ?? 0, $item['width'] ?? 0, $item['height'] ?? 0 ) );
		$image_attachment_id = absint( $item['imageAttachmentId'] ?? 0 );
		if ( '' === sanitize_text_field( (string) ( $item['name'] ?? '' ) ) || absint( $item['quantity'] ?? 0 ) <= 0 || min( $dimensions ) <= 0 || ! wp_attachment_is_image( $image_attachment_id ) ) {
			return new WP_Error( 'papelito_kit_merchandise_invalid', 'Todo brinde precisa de imagem, nome, quantidade, peso e dimensões positivos.', array( 'status' => 422 ) );
		}
		foreach ( (array) ( $item['stocks'] ?? array() ) as $stock ) {
			$vendor_id = absint( $stock['vendorId'] ?? 0 );
			$user      = get_userdata( $vendor_id );
			if ( $vendor_id <= 0 || ! $user instanceof WP_User || ! papelito_user_is_effective_seller( $user ) ) {
				return new WP_Error( 'papelito_kit_merchandise_vendor_invalid', 'Selecione um vendor válido para o estoque do brinde.', array( 'status' => 422 ) );
			}
		}
	}
	$kit = null !== $kit_id ? papelito_kit_get( $kit_id ) : null;
	if ( null !== $kit_id && ! $kit ) {
		return new WP_Error( 'papelito_kit_not_found', 'Kit não encontrado.', array( 'status' => 404 ) );
	}
	$product = $kit ? wc_get_product( (int) $kit['product_id'] ) : new WC_Product_Simple();
	if ( ! $product ) {
		return new WP_Error( 'papelito_kit_product_missing', 'Produto comercial do Kit não encontrado.', array( 'status' => 409 ) );
	}
	try {
		$product->set_name( $name );
		$product->set_slug( sanitize_title( (string) ( $payload['slug'] ?? $name ) ) );
		$product->set_status( in_array( $payload['status'] ?? 'draft', array( 'draft', 'publish' ), true ) ? $payload['status'] : 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( $regular_price );
		$product->set_sale_price( wc_format_decimal( (string) ( $payload['salePrice'] ?? '' ) ) );
		$product_id = $product->save();
	} catch ( Throwable $exception ) {
		return new WP_Error( 'papelito_kit_product_invalid', 'Não foi possível salvar o produto comercial do Kit.', array( 'status' => 422 ) );
	}
	if ( $product_id <= 0 ) {
		return new WP_Error( 'papelito_kit_product_write_failed', 'Não foi possível salvar o produto comercial do Kit.', array( 'status' => 500 ) );
	}
	$tables        = papelito_kits_table_names();
	$source        = sanitize_key( (string) ( $payload['imageSource'] ?? 'fallback' ) );
	$source        = array_key_exists( $source, PAPELITO_KIT_IMAGE_PRESETS ) || 'custom' === $source ? $source : 'fallback';
	$attachment_id = absint( $payload['imageAttachmentId'] ?? 0 );
	if ( 'custom' === $source && $attachment_id > 0 ) {
		try {
			$product->set_image_id( $attachment_id );
			$product->save();
		} catch ( Throwable $exception ) {
			return new WP_Error( 'papelito_kit_image_invalid', 'Não foi possível salvar a imagem do Kit.', array( 'status' => 422 ) );
		}
	}
	$wpdb->query( 'START TRANSACTION' );
	if ( $kit ) {
		$wpdb->update(
			$tables['kits'],
			array(
				'image_source'        => $source,
				'image_attachment_id' => $attachment_id,
			),
			array( 'id' => $kit_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	} else {
		$wpdb->insert(
			$tables['kits'],
			array(
				'product_id'          => $product_id,
				'image_source'        => $source,
				'image_attachment_id' => $attachment_id,
			),
			array( '%d', '%s', '%d' )
		);
		$kit_id = (int) $wpdb->insert_id;
		$product->update_meta_data( PAPELITO_KIT_PRODUCT_META, $kit_id );
		$product->save();
	}
	$normalized_items = array();
	foreach ( $items as $item ) {
		$product_id = absint( $item['productId'] ?? 0 );
		$quantity   = absint( $item['quantity'] ?? 0 );
		if ( $product_id <= 0 || $product_id === (int) $product->get_id() || $quantity <= 0 || ! wc_get_product( $product_id ) || papelito_kit_is_product( $product_id ) ) {
			return new WP_Error( 'papelito_kit_component_invalid', 'Os produtos do Kit precisam ser produtos comuns, únicos e com quantidade positiva.', array( 'status' => 422 ) );
		}
		$normalized_items[ $product_id ] = $quantity;
	}
	if ( count( $normalized_items ) !== count( $items ) ) {
		return new WP_Error( 'papelito_kit_component_duplicate', 'Um produto só pode ser adicionado uma vez ao Kit.', array( 'status' => 422 ) );
	}
	$wpdb->delete( $tables['items'], array( 'kit_id' => $kit_id ), array( '%d' ) );
	foreach ( $normalized_items as $component_id => $quantity ) {
		$wpdb->insert(
			$tables['items'],
			array(
				'kit_id'     => $kit_id,
				'product_id' => $component_id,
				'quantity'   => $quantity,
			),
			array( '%d', '%d', '%d' )
		);
	}
	$existing_merchandise  = array_column( papelito_kit_merchandise( $kit_id ), 'id' );
	$submitted_merchandise = array();
	foreach ( (array) ( $payload['merchandise'] ?? array() ) as $item ) {
		$name                = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
		$quantity            = absint( $item['quantity'] ?? 0 );
		$dimensions          = array_map( static fn( $value ): float => (float) wc_format_decimal( (string) $value ), array( $item['weight'] ?? 0, $item['length'] ?? 0, $item['width'] ?? 0, $item['height'] ?? 0 ) );
		$image_attachment_id = absint( $item['imageAttachmentId'] ?? 0 );
		if ( '' === $name || $quantity <= 0 || min( $dimensions ) <= 0 || ! wp_attachment_is_image( $image_attachment_id ) ) {
			return new WP_Error( 'papelito_kit_merchandise_invalid', 'Todo brinde precisa de imagem, nome, quantidade, peso e dimensões positivos.', array( 'status' => 422 ) );
		}
		$merchandise_id = absint( $item['id'] ?? 0 );
		if ( $merchandise_id > 0 && in_array( $merchandise_id, array_map( 'intval', $existing_merchandise ), true ) ) {
			$wpdb->update(
				$tables['merchandise'],
				array(
					'name'                => $name,
					'image_attachment_id' => $image_attachment_id,
					'quantity'            => $quantity,
					'weight'              => $dimensions[0],
					'length'              => $dimensions[1],
					'width'               => $dimensions[2],
					'height'              => $dimensions[3],
				),
				array( 'id' => $merchandise_id ),
				array( '%s', '%d', '%d', '%f', '%f', '%f', '%f' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$tables['merchandise'],
				array(
					'kit_id'              => $kit_id,
					'name'                => $name,
					'image_attachment_id' => $image_attachment_id,
					'quantity'            => $quantity,
					'weight'              => $dimensions[0],
					'length'              => $dimensions[1],
					'width'               => $dimensions[2],
					'height'              => $dimensions[3],
				),
				array( '%d', '%s', '%d', '%d', '%f', '%f', '%f', '%f' )
			);
			$merchandise_id = (int) $wpdb->insert_id;
		}
		$submitted_merchandise[] = $merchandise_id;
		$wpdb->delete( $tables['merch_stock'], array( 'merchandise_id' => $merchandise_id ), array( '%d' ) );
		foreach ( (array) ( $item['stocks'] ?? array() ) as $stock ) {
			$vendor_id = absint( $stock['vendorId'] ?? 0 );
			$stock_qty = max( 0, (int) ( $stock['qty'] ?? 0 ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$tables['merch_stock']} (merchandise_id, vendor_id, qty) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE qty = VALUES(qty)", $merchandise_id, $vendor_id, $stock_qty ) );
		}
	}
	foreach ( array_diff( array_map( 'intval', $existing_merchandise ), $submitted_merchandise ) as $merchandise_id ) {
		$wpdb->delete( $tables['merch_stock'], array( 'merchandise_id' => $merchandise_id ), array( '%d' ) );
		$wpdb->delete( $tables['merchandise'], array( 'id' => $merchandise_id ), array( '%d' ) );
	}
	$wpdb->query( 'COMMIT' );
	papelito_kits_invalidate_public_cache();
	return papelito_kit_get( $kit_id );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/admin/kits',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => static function ( WP_REST_Request $request ) {
													global $wpdb;
													$tables = papelito_kits_table_names();
													$rows = $wpdb->get_results( "SELECT * FROM {$tables['kits']} ORDER BY updated_at DESC", ARRAY_A );
													return new WP_REST_Response( array( 'items' => array_map( 'papelito_kit_response', is_array( $rows ) ? $rows : array() ) ), 200 ); },
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => static function ( WP_REST_Request $request ) {
						$kit = papelito_kit_write( (array) $request->get_json_params() );
						return is_wp_error( $kit ) ? $kit : new WP_REST_Response( papelito_kit_response( $kit ), 201 ); },
				),
			)
		);
		register_rest_route(
			'papelito/v1',
			'/admin/kits/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => static function ( WP_REST_Request $request ) {
													$kit = papelito_kit_get( absint( $request['id'] ) );
													return $kit ? new WP_REST_Response( papelito_kit_response( $kit ), 200 ) : new WP_Error( 'papelito_kit_not_found', 'Kit não encontrado.', array( 'status' => 404 ) ); },
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => static function ( WP_REST_Request $request ) {
						$kit = papelito_kit_write( (array) $request->get_json_params(), absint( $request['id'] ) );
						return is_wp_error( $kit ) ? $kit : new WP_REST_Response( papelito_kit_response( $kit ), 200 ); },
				),
			)
		);
		register_rest_route(
			'papelito/v1',
			'/kits',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					global $wpdb;
					$cached = get_transient( 'papelito_kits_public' );
					if ( is_array( $cached ) ) {
						return new WP_REST_Response( $cached, 200 );
					} $tables = papelito_kits_table_names();
					$rows = $wpdb->get_results( "SELECT k.* FROM {$tables['kits']} k INNER JOIN {$wpdb->posts} p ON p.ID = k.product_id WHERE p.post_status = 'publish' ORDER BY k.updated_at DESC", ARRAY_A );
					$payload = array( 'items' => array_values( array_filter( array_map( 'papelito_kit_public_response', is_array( $rows ) ? $rows : array() ) ) ) );
					set_transient( 'papelito_kits_public', $payload, MINUTE_IN_SECONDS );
					return new WP_REST_Response( $payload, 200 );
				},
			)
		);
	}
);
