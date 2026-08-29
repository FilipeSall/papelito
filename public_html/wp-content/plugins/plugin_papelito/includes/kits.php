<?php

defined( 'ABSPATH' ) || exit;

const PAPELITO_KIT_PRODUCT_META  = '_papelito_kit_id';
const PAPELITO_KIT_REST_NAMESPACE = 'papelito/v1';
const PAPELITO_KIT_NOT_FOUND_MESSAGE = 'Kit não encontrado.';
const PAPELITO_KIT_DEFAULT_IMAGE = '/images/categorias/icons/kit.webp';
const PAPELITO_KIT_PACKAGE_MIN_LENGTH = 11.0;
const PAPELITO_KIT_PACKAGE_MIN_WIDTH  = 6.0;
const PAPELITO_KIT_PACKAGE_MIN_HEIGHT = 0.4;
const PAPELITO_KIT_PACKAGE_MAX_DIMENSION = 100.0;
const PAPELITO_KIT_PACKAGE_MAX_SUM       = 200.0;
const PAPELITO_KIT_PACKAGE_MAX_WEIGHT_G  = 30000.0;
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
  package_length DECIMAL(12,2) NOT NULL DEFAULT 0,
  package_width DECIMAL(12,2) NOT NULL DEFAULT 0,
  package_height DECIMAL(12,2) NOT NULL DEFAULT 0,
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

function papelito_kits_using_component( int $component_id ): array {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT k.product_id FROM {$tables['kits']} k INNER JOIN {$tables['items']} i ON i.kit_id = k.id WHERE i.product_id = %d",
			$component_id
		)
	);

	return array_values( array_unique( array_filter( array_map( 'absint', is_array( $rows ) ? $rows : array() ) ) ) );
}

function papelito_kit_merchandise( int $kit_id ): array {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, image_attachment_id, quantity, weight, length, width, height FROM {$tables['merchandise']} WHERE kit_id = %d ORDER BY id ASC", $kit_id ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

function papelito_kit_package_dimensions( array $kit ): ?array {
	$dimensions = array(
		'length' => (float) ( $kit['package_length'] ?? 0 ),
		'width'  => (float) ( $kit['package_width'] ?? 0 ),
		'height' => (float) ( $kit['package_height'] ?? 0 ),
	);

	return min( $dimensions ) > 0 ? $dimensions : null;
}

function papelito_kit_validate_package_dimensions( ?array $dimensions ) {
	if ( null === $dimensions ) {
		return new WP_Error( 'papelito_kit_package_dimensions_missing', 'Informe as dimensões da embalagem final do Kit para cotar frete.', array( 'status' => 422 ) );
	}

	if (
		$dimensions['length'] < PAPELITO_KIT_PACKAGE_MIN_LENGTH
		|| $dimensions['width'] < PAPELITO_KIT_PACKAGE_MIN_WIDTH
		|| $dimensions['height'] < PAPELITO_KIT_PACKAGE_MIN_HEIGHT
		|| max( $dimensions ) > PAPELITO_KIT_PACKAGE_MAX_DIMENSION
		|| array_sum( $dimensions ) > PAPELITO_KIT_PACKAGE_MAX_SUM
	) {
		return new WP_Error( 'papelito_kit_package_dimensions_invalid', 'As dimensões da embalagem do Kit estão fora dos limites aceitos pelos Correios.', array( 'status' => 422 ) );
	}

	return true;
}

function papelito_kit_calculate_weight_grams( int $kit_id, int $kit_quantity = 1 ) {
	$kit_quantity = max( 1, $kit_quantity );
	$total_weight = 0.0;

	foreach ( papelito_kit_items( $kit_id ) as $item ) {
		$product = wc_get_product( absint( $item['product_id'] ?? 0 ) );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'papelito_kit_component_invalid', 'Todo componente do Kit precisa ser um produto disponível.', array( 'status' => 422 ) );
		}
		$weight = (float) $product->get_weight( 'edit' );
		if ( $weight <= 0 ) {
			return new WP_Error( 'papelito_kit_component_weight_missing', sprintf( 'O produto "%s" precisa de peso para compor o Kit.', $product->get_name() ), array( 'status' => 422 ) );
		}
		$total_weight += (float) wc_get_weight( $weight, 'g' ) * absint( $item['quantity'] ?? 0 ) * $kit_quantity;
	}

	foreach ( papelito_kit_merchandise( $kit_id ) as $item ) {
		$weight = (float) ( $item['weight'] ?? 0 );
		if ( $weight <= 0 ) {
			return new WP_Error( 'papelito_kit_merchandise_weight_missing', 'Todo brinde físico do Kit precisa de peso.', array( 'status' => 422 ) );
		}
		$total_weight += (float) wc_get_weight( $weight, 'g' ) * absint( $item['quantity'] ?? 0 ) * $kit_quantity;
	}

	if ( $total_weight <= 0 || $total_weight > PAPELITO_KIT_PACKAGE_MAX_WEIGHT_G ) {
		return new WP_Error( 'papelito_kit_weight_invalid', 'O peso total do Kit está fora dos limites aceitos pelos Correios.', array( 'status' => 422 ) );
	}

	return round( $total_weight, 2 );
}

function papelito_kit_logistics( int $kit_product_id, int $kit_quantity = 1 ) {
	$kit = papelito_kit_get_by_product( $kit_product_id );
	if ( ! $kit ) {
		return new WP_Error( 'papelito_kit_not_found', PAPELITO_KIT_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}
	$weight = papelito_kit_calculate_weight_grams( (int) $kit['id'], $kit_quantity );
	if ( is_wp_error( $weight ) ) {
		return $weight;
	}
	$dimensions = papelito_kit_package_dimensions( $kit );
	$valid      = papelito_kit_validate_package_dimensions( $dimensions );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	return array_merge( array( 'weight' => $weight ), $dimensions );
}

function papelito_kits_should_skip_product_notification(): bool {
	return ! empty( $GLOBALS['papelito_kits_skip_product_notification'] );
}

function papelito_kit_expand_requirements( int $kit_product_id, int $kit_qty ): array|WP_Error {
	$kit = papelito_kit_get_by_product( $kit_product_id );
	if ( ! $kit ) {
		return new WP_Error( 'papelito_kit_not_found', PAPELITO_KIT_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
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
	$requirements     = array();
	$component_ids    = array();
	foreach ( $kits as $kit ) {
		$requirements[ (int) $kit['id'] ] = array( 'components' => array() );
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
	return array( 'components' => $components );
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
		$adjusted[ $product_id ] = $quantity;
	}
	if ( ! isset( $result ) || ! is_wp_error( $result ) ) {
		return true;
	}
	foreach ( array_reverse( $adjusted, true ) as $product_id => $quantity ) {
		papelito_adjust_vendor_stock( $vendor_id, $product_id, $direction * -1 * $quantity, $reason . '_rollback' );
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
		'shortDescription'    => $product ? wp_kses_post( $product->get_short_description() ) : '',
		'description'         => $product ? wp_kses_post( $product->get_description() ) : '',
		'packageDimensions'   => papelito_kit_package_dimensions( $kit ),
		'items'               => $items,
		'merchandise'         => $merchandise,
		'referencePriceCents' => $reference_cents,
	);
}

function papelito_kit_public_detail_response( array $kit ): array {
	$product = wc_get_product( (int) $kit['product_id'] );
	if ( ! $product || 'publish' !== $product->get_status() ) {
		return array();
	}

	$gallery = array(
		array(
			'id'    => 'kit:' . (int) $kit['id'],
			'name'  => sanitize_text_field( $product->get_name() ),
			'image' => papelito_kit_image_url( $kit ),
		),
	);
	foreach ( papelito_kit_items( (int) $kit['id'] ) as $item ) {
		$component = wc_get_product( absint( $item['product_id'] ?? 0 ) );
		if ( ! $component ) {
			continue;
		}
		$gallery[] = array(
			'id'    => 'product:' . (int) $component->get_id(),
			'name'  => sanitize_text_field( $component->get_name() ),
			'image' => (string) wp_get_attachment_image_url( $component->get_image_id(), 'medium' ),
		);
	}

	return array(
		'productId'         => (int) $kit['product_id'],
		'name'              => sanitize_text_field( $product->get_name() ),
		'slug'              => sanitize_title( $product->get_slug() ),
		'price'             => (string) $product->get_regular_price(),
		'salePrice'         => $product->is_on_sale() ? (string) $product->get_price() : '',
		'imageUrl'          => papelito_kit_image_url( $kit ),
		'shortDescription'  => wp_kses_post( $product->get_short_description() ),
		'description'       => wp_kses_post( $product->get_description() ),
		'galleryImages'     => $gallery,
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

function papelito_kit_validate_write_payload( array $payload ): array|WP_Error {
	$name          = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
	$regular_price = wc_format_decimal( (string) ( $payload['price'] ?? '' ) );
	$items         = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
	$merchandise   = isset( $payload['merchandise'] ) && is_array( $payload['merchandise'] ) ? $payload['merchandise'] : array();

	if ( '' === $name || ! is_numeric( $regular_price ) || (float) $regular_price <= 0 || empty( $items ) ) {
		return new WP_Error( 'papelito_kit_invalid', 'Informe nome, preço e ao menos um produto para o Kit.', array( 'status' => 422 ) );
	}
	$components_valid = papelito_kit_validate_component_items( $items );
	if ( is_wp_error( $components_valid ) ) {
		return $components_valid;
	}
	$merchandise_valid = papelito_kit_validate_merchandise_items( $merchandise );
	if ( is_wp_error( $merchandise_valid ) ) {
		return $merchandise_valid;
	}

	return compact( 'name', 'regular_price', 'items', 'merchandise' );
}

function papelito_kit_validate_component_items( array $items ) {
	$seen_component_ids = array();
	foreach ( $items as $item ) {
		$component_id = absint( $item['productId'] ?? 0 );
		$quantity     = absint( $item['quantity'] ?? 0 );
		$component    = $component_id > 0 ? wc_get_product( $component_id ) : null;
		if ( $component_id <= 0 || $quantity <= 0 || isset( $seen_component_ids[ $component_id ] ) || ! $component instanceof WC_Product || papelito_kit_is_product( $component_id ) ) {
			return new WP_Error( 'papelito_kit_component_invalid', 'Os produtos do Kit precisam existir, ser únicos e ter quantidade positiva.', array( 'status' => 422 ) );
		}
		$seen_component_ids[ $component_id ] = true;
	}

	return true;
}

function papelito_kit_validate_merchandise_items( array $items ) {
	foreach ( $items as $item ) {
		$dimensions = papelito_kit_merchandise_dimensions( $item );
		if ( '' === sanitize_text_field( (string) ( $item['name'] ?? '' ) ) || absint( $item['quantity'] ?? 0 ) <= 0 || min( $dimensions ) <= 0 || ! wp_attachment_is_image( absint( $item['imageAttachmentId'] ?? 0 ) ) ) {
			return new WP_Error( 'papelito_kit_merchandise_invalid', 'Todo brinde precisa de imagem, nome, quantidade, peso e dimensões positivos.', array( 'status' => 422 ) );
		}
	}

	return true;
}

function papelito_kit_merchandise_dimensions( array $item ): array {
	return array_map(
		static fn( $value ): float => (float) wc_format_decimal( (string) $value ),
		array( $item['weight'] ?? 0, $item['length'] ?? 0, $item['width'] ?? 0, $item['height'] ?? 0 )
	);
}

function papelito_kit_write_context( ?int $kit_id ): array|WP_Error {
	$kit = null !== $kit_id ? papelito_kit_get( $kit_id ) : null;
	if ( null !== $kit_id && ! $kit ) {
		return new WP_Error( 'papelito_kit_not_found', PAPELITO_KIT_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}
	$product = $kit ? wc_get_product( (int) $kit['product_id'] ) : new WC_Product_Simple();
	if ( ! $product ) {
		return new WP_Error( 'papelito_kit_product_missing', 'Produto comercial do Kit não encontrado.', array( 'status' => 409 ) );
	}

	return array(
		'kit'     => $kit,
		'product' => $product,
	);
}

function papelito_kit_save_product( array $payload, array $validated, WC_Product $product ) {
	$GLOBALS['papelito_kits_skip_product_notification'] = true;
	try {
		$product->set_name( $validated['name'] );
		$product->set_slug( sanitize_title( (string) ( $payload['slug'] ?? $validated['name'] ) ) );
		$product->set_status( in_array( $payload['status'] ?? 'draft', array( 'draft', 'publish' ), true ) ? $payload['status'] : 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( $validated['regular_price'] );
		$product->set_sale_price( wc_format_decimal( (string) ( $payload['salePrice'] ?? '' ) ) );
		$product->set_short_description( wp_kses_post( (string) ( $payload['shortDescription'] ?? '' ) ) );
		$product->set_description( wp_kses_post( (string) ( $payload['description'] ?? '' ) ) );
		$product->save();
	} catch ( Throwable $exception ) {
		$GLOBALS['papelito_kits_skip_product_notification'] = false;
		return new WP_Error( 'papelito_kit_product_invalid', 'Não foi possível salvar o produto comercial do Kit.', array( 'status' => 422 ) );
	}
	$GLOBALS['papelito_kits_skip_product_notification'] = false;

	return $product->get_id() > 0 ? $product : new WP_Error( 'papelito_kit_product_write_failed', 'Não foi possível salvar o produto comercial do Kit.', array( 'status' => 500 ) );
}

function papelito_kit_write_package( array $payload ): array|WP_Error {
	$input   = is_array( $payload['packageDimensions'] ?? null ) ? $payload['packageDimensions'] : array();
	$package = array(
		'length' => (float) wc_format_decimal( (string) ( $input['length'] ?? 0 ) ),
		'width'  => (float) wc_format_decimal( (string) ( $input['width'] ?? 0 ) ),
		'height' => (float) wc_format_decimal( (string) ( $input['height'] ?? 0 ) ),
	);
	if ( 0.0 !== max( $package ) && min( $package ) <= 0 ) {
		return new WP_Error( 'papelito_kit_package_dimensions_invalid', 'Informe todas as dimensões da embalagem do Kit ou deixe os campos vazios para completar depois.', array( 'status' => 422 ) );
	}
	if ( 0.0 !== max( $package ) ) {
		$valid = papelito_kit_validate_package_dimensions( $package );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}

	return $package;
}

function papelito_kit_validate_write_image( array $payload, ?int $kit_id ): array|WP_Error {
	$source        = sanitize_key( (string) ( $payload['imageSource'] ?? 'fallback' ) );
	$source        = array_key_exists( $source, PAPELITO_KIT_IMAGE_PRESETS ) || 'custom' === $source ? $source : 'fallback';
	$attachment_id = absint( $payload['imageAttachmentId'] ?? 0 );
	if ( null === $kit_id && ( 'custom' !== $source || $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) ) {
		return new WP_Error( 'papelito_kit_image_required', 'Envie uma imagem do Kit antes de salvar.', array( 'status' => 422 ) );
	}

	return array(
		'source'        => $source,
		'attachment_id' => $attachment_id,
	);
}

function papelito_kit_write_image( array $image, WC_Product $product ): array|WP_Error {
	$source        = $image['source'];
	$attachment_id = $image['attachment_id'];
	if ( 'custom' === $source && $attachment_id > 0 ) {
		$result = papelito_kit_save_product_image( $product, $attachment_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return array(
		'source'        => $source,
		'attachment_id' => $attachment_id,
	);
}

function papelito_kit_save_product_image( WC_Product $product, int $attachment_id ) {
	$GLOBALS['papelito_kits_skip_product_notification'] = true;
	try {
		$product->set_image_id( $attachment_id );
		$product->save();
	} catch ( Throwable $exception ) {
		$GLOBALS['papelito_kits_skip_product_notification'] = false;
		return new WP_Error( 'papelito_kit_image_invalid', 'Não foi possível salvar a imagem do Kit.', array( 'status' => 422 ) );
	}
	$GLOBALS['papelito_kits_skip_product_notification'] = false;

	return true;
}

function papelito_kit_persist_items( int $kit_id, array $items, WC_Product $product ): bool|WP_Error {
	global $wpdb;

	$normalized_items = array();
	foreach ( $items as $item ) {
		$product_id = absint( $item['productId'] ?? 0 );
		$quantity   = absint( $item['quantity'] ?? 0 );
		$component  = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( $product_id <= 0 || $product_id === (int) $product->get_id() || $quantity <= 0 || ! $component instanceof WC_Product || papelito_kit_is_product( $product_id ) ) {
			return new WP_Error( 'papelito_kit_component_invalid', 'Os produtos do Kit precisam existir, ser únicos e ter quantidade positiva.', array( 'status' => 422 ) );
		}
		$normalized_items[ $product_id ] = $quantity;
	}
	if ( count( $normalized_items ) !== count( $items ) ) {
		return new WP_Error( 'papelito_kit_component_duplicate', 'Um produto só pode ser adicionado uma vez ao Kit.', array( 'status' => 422 ) );
	}

	$tables = papelito_kits_table_names();
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

	return true;
}

function papelito_kit_persist_merchandise( int $kit_id, array $items ): bool|WP_Error {
	global $wpdb;

	$tables                = papelito_kits_table_names();
	$existing_merchandise  = array_map( 'intval', array_column( papelito_kit_merchandise( $kit_id ), 'id' ) );
	$submitted_merchandise = array();
	foreach ( $items as $item ) {
		$name                = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
		$quantity            = absint( $item['quantity'] ?? 0 );
		$dimensions          = papelito_kit_merchandise_dimensions( $item );
		$image_attachment_id = absint( $item['imageAttachmentId'] ?? 0 );
		if ( '' === $name || $quantity <= 0 || min( $dimensions ) <= 0 || ! wp_attachment_is_image( $image_attachment_id ) ) {
			return new WP_Error( 'papelito_kit_merchandise_invalid', 'Todo brinde precisa de imagem, nome, quantidade, peso e dimensões positivos.', array( 'status' => 422 ) );
		}
		$merchandise_id = absint( $item['id'] ?? 0 );
		if ( $merchandise_id > 0 && in_array( $merchandise_id, $existing_merchandise, true ) ) {
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
	}

	foreach ( array_diff( $existing_merchandise, $submitted_merchandise ) as $merchandise_id ) {
		$wpdb->delete( $tables['merchandise'], array( 'id' => $merchandise_id ), array( '%d' ) );
	}

	return true;
}

function papelito_kit_persist( ?int $kit_id, WC_Product $product, array $image, array $package, array $items, array $merchandise ) {
	global $wpdb;

	$tables = papelito_kits_table_names();
	$wpdb->query( 'START TRANSACTION' );
	if ( null !== $kit_id ) {
		$wpdb->update(
			$tables['kits'],
			array(
				'image_source'        => $image['source'],
				'image_attachment_id' => $image['attachment_id'],
				'package_length'      => $package['length'],
				'package_width'       => $package['width'],
				'package_height'      => $package['height'],
			),
			array( 'id' => $kit_id ),
			array( '%s', '%d', '%f', '%f', '%f' ),
			array( '%d' )
		);
	} else {
		$wpdb->insert(
			$tables['kits'],
			array(
				'product_id'          => $product->get_id(),
				'image_source'        => $image['source'],
				'image_attachment_id' => $image['attachment_id'],
				'package_length'      => $package['length'],
				'package_width'       => $package['width'],
				'package_height'      => $package['height'],
			),
			array( '%d', '%s', '%d', '%f', '%f', '%f' )
		);
		$kit_id = (int) $wpdb->insert_id;
		$product->update_meta_data( PAPELITO_KIT_PRODUCT_META, $kit_id );
		$product->save();
	}

	$items_result       = papelito_kit_persist_items( $kit_id, $items, $product );
	$merchandise_result = is_wp_error( $items_result ) ? $items_result : papelito_kit_persist_merchandise( $kit_id, $merchandise );
	if ( is_wp_error( $merchandise_result ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $merchandise_result;
	}
	$wpdb->query( 'COMMIT' );

	return papelito_kit_get( $kit_id );
}

function papelito_kit_write( array $payload, ?int $kit_id = null ) {
	if ( ! class_exists( 'WC_Product_Simple' ) ) {
		return new WP_Error( 'papelito_woocommerce_unavailable', 'WooCommerce indisponível.', array( 'status' => 500 ) );
	}
	$validated = papelito_kit_validate_write_payload( $payload );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}
	$context = papelito_kit_write_context( $kit_id );
	if ( is_wp_error( $context ) ) {
		return $context;
	}
	$package = papelito_kit_write_package( $payload );
	if ( is_wp_error( $package ) ) {
		return $package;
	}
	$image = papelito_kit_validate_write_image( $payload, $kit_id );
	if ( is_wp_error( $image ) ) {
		return $image;
	}
	$product = papelito_kit_save_product( $payload, $validated, $context['product'] );
	if ( is_wp_error( $product ) ) {
		return $product;
	}
	$image = papelito_kit_write_image( $image, $product );
	if ( is_wp_error( $image ) ) {
		return $image;
	}
	$persisted_kit = papelito_kit_persist( $kit_id, $product, $image, $package, $validated['items'], $validated['merchandise'] );
	if ( is_wp_error( $persisted_kit ) ) {
		return $persisted_kit;
	}
	papelito_kits_invalidate_public_cache();
	if ( function_exists( 'papelito_sync_product_data_notification' ) ) {
		papelito_sync_product_data_notification( (int) $product->get_id(), $product );
	}

	return $persisted_kit;
}

function papelito_kit_admin_list() {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$rows   = $wpdb->get_results( "SELECT * FROM {$tables['kits']} ORDER BY updated_at DESC", ARRAY_A );

	return new WP_REST_Response( array( 'items' => array_map( 'papelito_kit_response', is_array( $rows ) ? $rows : array() ) ), 200 );
}

function papelito_kit_admin_create( WP_REST_Request $request ) {
	$kit = papelito_kit_write( (array) $request->get_json_params() );

	return is_wp_error( $kit ) ? $kit : new WP_REST_Response( papelito_kit_response( $kit ), 201 );
}

function papelito_kit_admin_get( WP_REST_Request $request ) {
	$kit = papelito_kit_get( absint( $request['id'] ) );

	return $kit ? new WP_REST_Response( papelito_kit_response( $kit ), 200 ) : new WP_Error( 'papelito_kit_not_found', PAPELITO_KIT_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
}

function papelito_kit_admin_update( WP_REST_Request $request ) {
	$kit = papelito_kit_write( (array) $request->get_json_params(), absint( $request['id'] ) );

	return is_wp_error( $kit ) ? $kit : new WP_REST_Response( papelito_kit_response( $kit ), 200 );
}

function papelito_kit_attachment_ids( array $kit, WC_Product $product ): array {
	$attachment_ids = array();
	if ( 'custom' === sanitize_key( (string) ( $kit['image_source'] ?? '' ) ) ) {
		$attachment_ids[] = absint( $kit['image_attachment_id'] ?? 0 );
	}
	$attachment_ids[] = absint( $product->get_image_id() );
	$attachment_ids   = array_merge( $attachment_ids, array_map( 'absint', $product->get_gallery_image_ids() ) );
	foreach ( papelito_kit_merchandise( (int) $kit['id'] ) as $merchandise ) {
		$attachment_ids[] = absint( $merchandise['image_attachment_id'] ?? 0 );
	}

	return array_values( array_unique( array_filter( $attachment_ids ) ) );
}

function papelito_kit_delete_persisted( array $kit ): bool|WP_Error {
	global $wpdb;

	$tables = papelito_kits_table_names();
	$wpdb->query( 'START TRANSACTION' );
	foreach ( array( 'items', 'merchandise' ) as $table_key ) {
		if ( false === $wpdb->delete( $tables[ $table_key ], array( 'kit_id' => (int) $kit['id'] ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'papelito_kit_delete_failed', 'Não foi possível remover a composição do Kit.', array( 'status' => 500 ) );
		}
	}
	if ( 1 !== $wpdb->delete( $tables['kits'], array( 'id' => (int) $kit['id'] ), array( '%d' ) ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_kit_delete_failed', 'Não foi possível remover o Kit.', array( 'status' => 500 ) );
	}
	if ( false === wp_delete_post( (int) $kit['product_id'], true ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_kit_product_delete_failed', 'Não foi possível remover o produto comercial do Kit.', array( 'status' => 500 ) );
	}
	if ( '' !== $wpdb->last_error ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_kit_delete_failed', 'Não foi possível remover o Kit.', array( 'status' => 500 ) );
	}
	$wpdb->query( 'COMMIT' );

	return true;
}

function papelito_kit_delete_attachments( array $attachment_ids ): array {
	$deleted_ids   = array();
	$preserved_ids = array();
	$failed_ids    = array();
	foreach ( $attachment_ids as $attachment_id ) {
		if ( papelito_admin_media_cleanup_referenced( $attachment_id ) ) {
			$preserved_ids[] = $attachment_id;
			continue;
		}
		if ( wp_delete_attachment( $attachment_id, true ) ) {
			$deleted_ids[] = $attachment_id;
		} else {
			$failed_ids[] = $attachment_id;
		}
	}

	return array(
		'deletedIds'   => $deleted_ids,
		'preservedIds' => $preserved_ids,
		'failedIds'    => $failed_ids,
	);
}

function papelito_kit_admin_delete( WP_REST_Request $request ) {
	$kit = papelito_kit_get( absint( $request['id'] ) );
	if ( ! $kit ) {
		return new WP_Error( 'papelito_kit_not_found', PAPELITO_KIT_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}
	$product = wc_get_product( (int) $kit['product_id'] );
	if ( ! $product instanceof WC_Product ) {
		return new WP_Error( 'papelito_kit_product_missing', 'Produto comercial do Kit não encontrado.', array( 'status' => 409 ) );
	}
	$attachment_ids = papelito_kit_attachment_ids( $kit, $product );
	$deleted        = papelito_kit_delete_persisted( $kit );
	if ( is_wp_error( $deleted ) ) {
		return $deleted;
	}
	papelito_kits_invalidate_public_cache();
	$media_cleanup = papelito_kit_delete_attachments( $attachment_ids );

	return new WP_REST_Response(
		array(
			'deleted'      => true,
			'kitId'        => (int) $kit['id'],
			'productId'    => (int) $kit['product_id'],
			'mediaCleanup' => $media_cleanup,
			'partial'      => ! empty( $media_cleanup['failedIds'] ),
		),
		200
	);
}

function papelito_kit_public_list() {
	global $wpdb;
	$cached = get_transient( 'papelito_kits_public' );
	if ( is_array( $cached ) ) {
		return new WP_REST_Response( $cached, 200 );
	}
	$tables = papelito_kits_table_names();
	$rows   = $wpdb->get_results( "SELECT k.* FROM {$tables['kits']} k INNER JOIN {$wpdb->posts} p ON p.ID = k.product_id WHERE p.post_status = 'publish' ORDER BY k.updated_at DESC", ARRAY_A );
	$payload = array( 'items' => array_values( array_filter( array_map( 'papelito_kit_public_response', is_array( $rows ) ? $rows : array() ) ) ) );
	set_transient( 'papelito_kits_public', $payload, MINUTE_IN_SECONDS );

	return new WP_REST_Response( $payload, 200 );
}

function papelito_kit_public_detail( WP_REST_Request $request ) {
	global $wpdb;
	$tables = papelito_kits_table_names();
	$kit    = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT k.* FROM {$tables['kits']} k INNER JOIN {$wpdb->posts} p ON p.ID = k.product_id WHERE p.post_status = 'publish' AND p.post_name = %s LIMIT 1",
			sanitize_title( (string) $request['slug'] )
		),
		ARRAY_A
	);
	$payload = is_array( $kit ) ? papelito_kit_public_detail_response( $kit ) : array();

	return empty( $payload ) ? new WP_Error( 'papelito_kit_not_found', PAPELITO_KIT_NOT_FOUND_MESSAGE, array( 'status' => 404 ) ) : new WP_REST_Response( $payload, 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			PAPELITO_KIT_REST_NAMESPACE,
			'/admin/kits',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => 'papelito_kit_admin_list',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => 'papelito_kit_admin_create',
				),
			)
		);
		register_rest_route(
			PAPELITO_KIT_REST_NAMESPACE,
			'/admin/kits/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => 'papelito_kit_admin_get',
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => 'papelito_kit_admin_update',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => 'papelito_kits_require_admin',
					'callback'            => 'papelito_kit_admin_delete',
				),
			)
		);
		register_rest_route(
			PAPELITO_KIT_REST_NAMESPACE,
			'/kits',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_kit_public_list',
			)
		);
		register_rest_route(
			PAPELITO_KIT_REST_NAMESPACE,
			'/kits/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_kit_public_detail',
			)
		);
	}
);
