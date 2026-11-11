<?php
/**
 * Cotação autoritativa do pedido do estande: preço atual, estoque do vendor e frete Correios.
 *
 * O tablet mostra uma prévia; esta cotação é a que vale. Ela relê o preço de cada produto, aplica
 * as regras de `event_sales_pricing.php`, confere o estoque do vendor do evento contando os
 * componentes do kit e escolhe a opção Correios mais barata.
 *
 * Regra de ouro: a cobrança só sai com o `quoteFingerprint` que esta função devolver na hora de
 * cobrar. Se preço, kit ou frete mudarem entre a cotação e o "Cobrar", o pedido é recusado com a
 * cotação nova, nunca no valor velho.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_SHIPPING_PROVIDER = 'correios';

/**
 * Quantidade por produto a partir dos itens do tablet, somando produto repetido.
 *
 * @param array<int,mixed> $items Itens `{productId, qty}`.
 * @return array<int,int>
 */
function papelito_event_sales_qty_by_product( array $items ): array {
	$qty = array();

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$product_id         = (int) ( $item['productId'] ?? 0 );
		$qty[ $product_id ] = ( $qty[ $product_id ] ?? 0 ) + (int) ( $item['qty'] ?? 0 );
	}

	return $qty;
}

/**
 * Preço atual de cada produto pedido, só para produto publicado, com preço e que não seja kit.
 *
 * Produto que não passa fica fora do índice e as regras de preço o recusam como desconhecido.
 *
 * @param array<int,int> $product_ids Produtos.
 * @return array<int,array{price_cents:int,name:string}>
 */
function papelito_event_sales_load_prices( array $product_ids ): array {
	$index = array();

	foreach ( array_unique( $product_ids ) as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() || papelito_kit_is_product( (int) $product_id ) ) {
			continue;
		}

		$price = papelito_event_sales_to_cents( (string) $product->get_price( 'edit' ) );
		if ( $price > 0 ) {
			$index[ (int) $product_id ] = array(
				'price_cents' => $price,
				'name'        => $product->get_name(),
			);
		}
	}

	return $index;
}

/**
 * Quantidade física de cada produto: avulso mais um por kit quando o produto é do kit.
 *
 * @param array<int,int> $qty_by_product Avulsos por produto.
 * @param int            $kits           Kits no pedido.
 * @param array<int,int> $kit_ids        Produtos do kit.
 * @return array<int,int>
 */
function papelito_event_sales_physical_qty( array $qty_by_product, int $kits, array $kit_ids ): array {
	$physical = array_filter( $qty_by_product );

	if ( $kits > 0 ) {
		foreach ( $kit_ids as $product_id ) {
			$physical[ $product_id ] = ( $physical[ $product_id ] ?? 0 ) + $kits;
		}
	}

	return $physical;
}

/**
 * Confere o estoque do vendor para a quantidade física do pedido.
 *
 * @param int            $vendor_id Vendor do evento.
 * @param array<int,int> $physical  Quantidade física por produto.
 * @return true|WP_Error 409 com `products[{productId, available}]` do que faltou.
 */
function papelito_event_sales_check_stock( int $vendor_id, array $physical ): bool|WP_Error {
	$missing = array();

	foreach ( $physical as $product_id => $qty ) {
		$available = max( 0, (int) papelito_get_vendor_stock( $vendor_id, $product_id ) );
		if ( $qty > $available ) {
			$missing[] = array(
				'productId' => (int) $product_id,
				'available' => $available,
			);
		}
	}

	if ( array() === $missing ) {
		return true;
	}

	return new WP_Error(
		'papelito_event_out_of_stock',
		'Um produto do pedido não tem estoque suficiente.',
		array(
			'status'   => 409,
			'products' => $missing,
		)
	);
}

/**
 * Itens da cotação de frete: cada produto com a quantidade física e o valor que ele paga no pedido.
 *
 * @param array<string,mixed>                           $priced   Resultado de `papelito_event_sales_price()`.
 * @param array<int,array{price_cents:int,name:string}> $products Catálogo por id.
 * @param array<int,int>                                $kit_ids  Produtos do kit.
 * @return array<int,array{product_id:int,qty:int,declared_value_cents:int}>
 */
function papelito_event_sales_shipping_items( array $priced, array $products, array $kit_ids ): array {
	$items = array();

	foreach ( $priced['lines'] as $line ) {
		$items[ $line['product_id'] ] = array(
			'product_id'           => $line['product_id'],
			'qty'                  => $line['qty'],
			'declared_value_cents' => $line['total_cents'],
		);
	}

	$kits = (int) $priced['kit']['qty'];
	foreach ( $kits > 0 ? $kit_ids : array() as $product_id ) {
		$unit  = papelito_event_sales_kit_unit_cents( array( $products[ $product_id ]['price_cents'] ) );
		$entry = $items[ $product_id ] ?? array(
			'product_id'           => $product_id,
			'qty'                  => 0,
			'declared_value_cents' => 0,
		);

		$entry['qty']                  += $kits;
		$entry['declared_value_cents'] += $kits * $unit;
		$items[ $product_id ]           = $entry;
	}

	return array_values( $items );
}

/**
 * Opção Correios de menor preço da cotação, ou erro quando não há nenhuma.
 *
 * @param array<string,mixed>|WP_Error $quote Resultado de `papelito_shipping_quote_all_providers()`.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_cheapest_correios( array|WP_Error $quote ): array|WP_Error {
	$best = null;

	$options = is_array( $quote ) && is_array( $quote['options'] ?? null ) ? $quote['options'] : array();
	foreach ( $options as $option ) {
		$is_correios = is_array( $option ) && PAPELITO_EVENT_SALES_SHIPPING_PROVIDER === ( $option['provider'] ?? '' );
		if ( $is_correios && ( null === $best || (int) $option['customer_price_cents'] < (int) $best['customer_price_cents'] ) ) {
			$best = $option;
		}
	}

	if ( null === $best ) {
		return new WP_Error( 'papelito_event_shipping_unavailable', 'Não conseguimos calcular o frete pra esse CEP.', array( 'status' => 422 ) );
	}

	return $best;
}

/**
 * Assina o que define o valor cobrado: linhas, kit, frete escolhido e total.
 *
 * O frete entra por opção, preço e prazo, não pelo fingerprint do provider, que também carrega a
 * validade da cotação e mudaria sem o valor mudar.
 *
 * @param array<string,mixed> $quote Cotação sem o fingerprint.
 * @return string
 */
function papelito_event_sales_quote_fingerprint( array $quote ): string {
	$shipping = array( $quote['shipping']['optionKey'], $quote['shipping']['priceCents'], $quote['shipping']['deliveryTime'] );

	return hash_hmac( 'sha256', (string) wp_json_encode( array( $quote['lines'], $quote['kit'], $shipping, $quote['totalCents'] ) ), wp_salt( 'auth' ) );
}

/**
 * Cotação completa do pedido do estande com o frete Correios mais barato.
 *
 * @param array<string,mixed> $input `items[{productId, qty}]`, `kits`, `cep` e `recipientCnpj`.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_quote( array $input ): array|WP_Error {
	$config  = papelito_event_sales_config();
	$kit_ids = $config['kit_product_ids'];
	$qty     = papelito_event_sales_qty_by_product( is_array( $input['items'] ?? null ) ? $input['items'] : array() );
	$kits    = (int) ( $input['kits'] ?? 0 );

	$products = papelito_event_sales_load_prices( array_merge( array_keys( $qty ), $kits > 0 ? $kit_ids : array() ) );
	$priced   = papelito_event_sales_price( $products, $qty, $kits, $kit_ids );
	if ( is_wp_error( $priced ) ) {
		return $priced;
	}

	$stock = papelito_event_sales_check_stock( $config['vendor_id'], papelito_event_sales_physical_qty( $qty, $kits, $kit_ids ) );
	if ( is_wp_error( $stock ) ) {
		return $stock;
	}

	$shipping = papelito_event_sales_cheapest_correios(
		papelito_shipping_quote_all_providers(
			$config['vendor_id'],
			papelito_shipping_normalize_cep( (string) ( $input['cep'] ?? '' ) ),
			papelito_event_sales_shipping_items( $priced, $products, $kit_ids ),
			array( 'recipient_cnpj' => papelito_normalize_cnpj( (string) ( $input['recipientCnpj'] ?? '' ) ) )
		)
	);
	if ( is_wp_error( $shipping ) ) {
		return $shipping;
	}

	$quote = array(
		'units'           => $priced['units'],
		'level'           => $priced['level'],
		'lines'           => $priced['lines'],
		'kit'             => $priced['kit'],
		'itemsCents'      => $priced['items_cents'],
		'discountCents'   => $priced['discount_cents'],
		'kitSavingsCents' => $priced['kit_savings_cents'],
		'shipping'        => array(
			'optionKey'    => (string) $shipping['option_key'],
			'name'         => (string) ( $shipping['name'] ?? '' ),
			'priceCents'   => (int) $shipping['customer_price_cents'],
			'deliveryTime' => isset( $shipping['delivery_time'] ) ? (int) $shipping['delivery_time'] : null,
			'fingerprint'  => (string) ( $shipping['fingerprint'] ?? '' ),
			'raw'          => $shipping,
		),
		'totalCents'      => $priced['items_cents'] + (int) $shipping['customer_price_cents'],
	);

	$quote['quoteFingerprint'] = papelito_event_sales_quote_fingerprint( $quote );

	return $quote;
}

/**
 * Cotação no formato público do tablet, sem a opção crua do provider.
 *
 * @param array<string,mixed> $quote Cotação completa.
 * @return array<string,mixed>
 */
function papelito_event_sales_public_quote( array $quote ): array {
	unset( $quote['shipping']['raw'] );
	$quote['level'] = papelito_event_sales_public_level( $quote['level'] );

	return $quote;
}

/**
 * `POST event/{id}/quote`.
 *
 * @param WP_REST_Request $request Requisição com `items`, `kits`, `cep` e `recipientCnpj`.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_quote_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$quote = papelito_event_sales_quote( papelito_event_sales_json_body( $request ) );

	return papelito_event_sales_respond( is_wp_error( $quote ) ? $quote : papelito_event_sales_public_quote( $quote ) );
}

/**
 * Registra a rota de cotação do evento.
 *
 * @return void
 */
function papelito_event_sales_register_quote_routes(): void {
	papelito_event_sales_register_route(
		'/quote',
		array(
			'methods'             => 'POST',
			'callback'            => 'papelito_event_sales_quote_endpoint',
			'permission_callback' => 'papelito_event_sales_require_seller',
		)
	);
}
add_action( 'rest_api_init', 'papelito_event_sales_register_quote_routes' );
