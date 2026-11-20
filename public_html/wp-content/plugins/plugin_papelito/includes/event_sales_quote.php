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
const PAPELITO_EVENT_SALES_WAIVED_SHIPPING   = 'correios:estande-frete-gratis';
const PAPELITO_EVENT_SALES_WAIVED_META       = '_papelito_event_shipping_waived';
const PAPELITO_EVENT_SALES_CEP_LENGTH        = 8;
const PAPELITO_EVENT_SALES_OUTAGE_FAILURES   = array( 'configuration_error', 'timeout', 'network_error', 'rate_limited', 'provider_4xx', 'provider_5xx', 'invalid_response', 'unknown_error' );

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
 * Preço do estande de cada produto pedido (o próprio do evento ou o do site), só para produto que o
 * catálogo do estande venderia.
 *
 * Produto reprovado por `papelito_event_sales_product_sellable()` fica fora do índice e as regras
 * de preço o recusam como desconhecido.
 *
 * @param array<int,int> $product_ids Produtos.
 * @return array<int,array{price_cents:int,name:string}>
 */
function papelito_event_sales_load_prices( array $product_ids ): array {
	$index = array();

	foreach ( array_unique( $product_ids ) as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product && papelito_event_sales_product_sellable( $product ) ) {
			$index[ (int) $product_id ] = array(
				'price_cents' => papelito_event_sales_price_override( (int) $product_id ) ?? papelito_event_sales_to_cents( (string) $product->get_price( 'edit' ) ),
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
		$unit  = papelito_event_sales_kit_unit_cents( array( $products[ $product_id ]['price_cents'] ), papelito_event_sales_kit_pct_kept() );
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
 * Componentes do kit com o preço cheio e o preço com o desconto do kit, para as linhas do pedido.
 *
 * @param array<string,mixed>                           $priced   Resultado de `papelito_event_sales_price()`.
 * @param array<int,array{price_cents:int,name:string}> $products Catálogo por id.
 * @param array<int,int>                                $kit_ids  Produtos do kit.
 * @return array<int,array{product_id:int,price_cents:int,unit_cents:int}>
 */
function papelito_event_sales_kit_components( array $priced, array $products, array $kit_ids ): array {
	$components = array();

	foreach ( (int) $priced['kit']['qty'] > 0 ? $kit_ids : array() as $product_id ) {
		$price        = (int) $products[ $product_id ]['price_cents'];
		$components[] = array(
			'product_id'  => (int) $product_id,
			'price_cents' => $price,
			'unit_cents'  => papelito_event_sales_kit_unit_cents( array( $price ), papelito_event_sales_kit_pct_kept() ),
		);
	}

	return $components;
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
 * Informa se a cotação falhou porque os Correios estão fora do ar, e não por cadastro ou carrinho.
 *
 * Cotação que voltou com opções mas sem nenhuma dos Correios também conta como queda: os Correios
 * falharam e outro provider respondeu. Erro de validação (produto sem medida, unidade que não cabe
 * numa caixa, rota inválida) nunca conta.
 *
 * @param array<string,mixed>|WP_Error $quoted Resultado de `papelito_shipping_quote_all_providers()`.
 * @return bool
 */
function papelito_event_sales_shipping_outage( array|WP_Error $quoted ): bool {
	if ( ! is_wp_error( $quoted ) ) {
		return true;
	}

	return in_array( $quoted->get_error_code(), PAPELITO_EVENT_SALES_OUTAGE_FAILURES, true );
}

/**
 * Modalidade de contingência: frete zero, a casa assume o envio e o vendor posta por PAC depois.
 *
 * Preço e prazo fixos mantêm o `quoteFingerprint` estável entre a cotação e o "Cobrar". O pedido
 * sai marcado com `PAPELITO_EVENT_SALES_WAIVED_META` para o custo real ser apurado na postagem.
 *
 * @return array<string,mixed>
 */
function papelito_event_sales_waived_shipping(): array {
	return array(
		'provider'             => PAPELITO_EVENT_SALES_SHIPPING_PROVIDER,
		'option_key'           => PAPELITO_EVENT_SALES_WAIVED_SHIPPING,
		'service'              => 'PAC',
		'code'                 => '',
		'name'                 => 'Frete grátis do estande (postar por PAC)',
		'customer_price_cents' => 0,
		'price'                => 0.0,
		'delivery_time'        => null,
		'fingerprint'          => '',
	);
}

/**
 * Frete do pedido do estande: a opção Correios mais barata, ou a contingência de frete grátis quando
 * os Correios estão fora do ar, o pedido já tem frete grátis (`PAPELITO_EVENT_SALES_FREE_SHIPPING`)
 * e o CEP tem 8 dígitos. Abaixo do frete grátis a falha continua travando a venda.
 *
 * @param int                            $vendor_id      Vendor do evento.
 * @param string                         $cep            CEP de entrega normalizado.
 * @param array<int,array<string,mixed>> $items          Itens da cotação de frete.
 * @param string                         $recipient_cnpj CNPJ do comprador.
 * @param int                            $base_cents     Base da faixa do pedido.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_resolve_shipping( int $vendor_id, string $cep, array $items, string $recipient_cnpj, int $base_cents ): array|WP_Error {
	$quoted   = papelito_shipping_quote_all_providers( $vendor_id, $cep, $items, array( 'recipient_cnpj' => $recipient_cnpj ) );
	$shipping = papelito_event_sales_cheapest_correios( $quoted );

	$waivable = is_wp_error( $shipping )
		&& $base_cents >= PAPELITO_EVENT_SALES_FREE_SHIPPING
		&& PAPELITO_EVENT_SALES_CEP_LENGTH === strlen( $cep )
		&& papelito_event_sales_shipping_outage( $quoted );

	return $waivable ? papelito_event_sales_waived_shipping() : $shipping;
}

/**
 * Caixas em que o pedido vai, as mesmas que os Correios cotaram; vazio quando não dá para montar.
 *
 * @param array<int,array<string,mixed>> $items Itens da cotação de frete.
 * @return array<int,array<string,mixed>>
 */
function papelito_event_sales_shipping_packages( array $items ): array {
	$packages = papelito_shipping_build_packages( $items );

	return is_array( $packages ) ? $packages : array();
}

/**
 * Assina o que define o valor cobrado e o que vai na caixa: linhas, kit e seus componentes, frete
 * escolhido e total. Trocar um produto do kit por outro de mesmo preço também muda a assinatura.
 *
 * O frete entra por opção, preço e prazo, não pelo fingerprint do provider, que também carrega a
 * validade da cotação e mudaria sem o valor mudar.
 *
 * @param array<string,mixed> $quote Cotação sem o fingerprint.
 * @return string
 */
function papelito_event_sales_quote_fingerprint( array $quote ): string {
	$shipping = array( $quote['shipping']['optionKey'], $quote['shipping']['priceCents'], $quote['shipping']['deliveryTime'] );

	return hash_hmac( 'sha256', (string) wp_json_encode( array( $quote['lines'], $quote['kit'], $quote['kitComponents'], $shipping, $quote['totalCents'] ) ), wp_salt( 'auth' ) );
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

	$pct_kept = papelito_event_sales_kit_pct_kept();
	$products = papelito_event_sales_load_prices( array_merge( array_keys( $qty ), $kits > 0 ? $kit_ids : array() ) );
	$priced   = papelito_event_sales_price( $products, $qty, $kits, $kit_ids, $pct_kept );
	if ( is_wp_error( $priced ) ) {
		return $priced;
	}

	$stock = papelito_event_sales_check_stock( $config['vendor_id'], papelito_event_sales_physical_qty( $qty, $kits, $kit_ids ) );
	if ( is_wp_error( $stock ) ) {
		return $stock;
	}

	$shipping_items = papelito_event_sales_shipping_items( $priced, $products, $kit_ids );
	$shipping       = papelito_event_sales_resolve_shipping(
		$config['vendor_id'],
		papelito_shipping_normalize_cep( (string) ( $input['cep'] ?? '' ) ),
		$shipping_items,
		papelito_normalize_cnpj( (string) ( $input['recipientCnpj'] ?? '' ) ),
		(int) $priced['base_cents']
	);
	if ( is_wp_error( $shipping ) ) {
		return $shipping;
	}

	$packages       = papelito_event_sales_shipping_packages( $shipping_items );
	$frete_cents    = (int) $shipping['customer_price_cents'];
	$frete_desconto = papelito_event_sales_shipping_discount_cents( (int) $priced['base_cents'], $frete_cents );

	/* O desconto viaja dentro da modalidade: é o `add_shipping_item()` que abate no pedido do
	   WooCommerce, mantendo preço cheio e assinatura intactos para a reconferência. */
	$shipping['discount'] = $frete_desconto / 100;

	$quote = array(
		'units'           => $priced['units'],
		'baseCents'       => $priced['base_cents'],
		'level'           => $priced['level'],
		'lines'           => $priced['lines'],
		'kit'             => $priced['kit'],
		'kitComponents'   => papelito_event_sales_kit_components( $priced, $products, $kit_ids ),
		'itemsCents'      => $priced['items_cents'],
		'discountCents'   => $priced['discount_cents'],
		'kitSavingsCents' => $priced['kit_savings_cents'],
		'kitPctOff'       => 100 - $pct_kept,
		'shipping'        => array(
			'optionKey'     => (string) $shipping['option_key'],
			'name'          => (string) ( $shipping['name'] ?? '' ),
			'priceCents'    => $frete_cents,
			'discountCents' => $frete_desconto,
			'deliveryTime'  => isset( $shipping['delivery_time'] ) ? (int) $shipping['delivery_time'] : null,
			'fingerprint'   => (string) ( $shipping['fingerprint'] ?? '' ),
			'waived'        => PAPELITO_EVENT_SALES_WAIVED_SHIPPING === $shipping['option_key'],
			'volumes'       => count( $packages ),
			'raw'           => $shipping,
			'packages'      => $packages,
		),
		'totalCents'      => $priced['items_cents'] + $frete_cents - $frete_desconto,
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
	unset( $quote['shipping']['raw'], $quote['shipping']['packages'] );
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
