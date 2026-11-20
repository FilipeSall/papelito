<?php
/**
 * Regras de preço da venda no estande: nível pelo valor do pedido, desconto por linha e Kit Premium.
 *
 * Tudo aqui é puro (sem banco e sem WordPress além de `WP_Error`) e trabalha em centavos inteiros.
 * O nível sai do valor do pedido antes do desconto e antes do frete: os avulsos pelo preço do
 * estande mais os kits pelo preço cheio. A unidade é o display e um kit conta como 4, mas ela só
 * aparece em texto. O nível vale para as linhas avulsas, arredondado no
 * centavo por linha; o kit tem preço próprio (desconto sobre os 4 produtos, −15% por padrão e
 * configurável pelo admin em `event_sales_prices.php`) e não recebe o nível. Quem chama passa a
 * porcentagem mantida do kit; o padrão é `PAPELITO_EVENT_SALES_KIT_PCT_KEPT`.
 *
 * O tablet espelha esta conta só para exibir. O valor cobrado é sempre o que sai daqui, recalculado
 * no servidor com os preços atuais.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_KIT_UNITS        = 4;
const PAPELITO_EVENT_SALES_KIT_COMPONENTS   = 4;
const PAPELITO_EVENT_SALES_KIT_PCT_KEPT     = 85;
const PAPELITO_EVENT_SALES_KIT_MAX          = 3;
const PAPELITO_EVENT_SALES_FREE_SHIPPING    = 50000;
const PAPELITO_EVENT_SALES_MAX_QTY          = 999;
const PAPELITO_EVENT_SALES_BASIS_POINTS     = 10000;
const PAPELITO_EVENT_SALES_INVALID_QUANTITY = 'papelito_event_invalid_quantity';

/**
 * Níveis do evento, do menor para o maior, com a porcentagem em pontos-base.
 *
 * @return array<int,array{key:string,name:string,min_cents:int,pct_bp:int}>
 */
function papelito_event_sales_levels(): array {
	return array(
		array(
			'key'       => 'lojista',
			'name'      => 'Lojista',
			'min_cents' => 0,
			'pct_bp'    => 0,
		),
		array(
			'key'       => 'parceiro',
			'name'      => 'Parceiro',
			'min_cents' => 100000,
			'pct_bp'    => 500,
		),
		array(
			'key'       => 'lenda',
			'name'      => 'Lenda',
			'min_cents' => 150000,
			'pct_bp'    => 1000,
		),
	);
}

/**
 * Unidades do pedido: displays avulsos mais 4 por kit.
 *
 * @param array<int|string,int> $qty_by_product Quantidade por produto.
 * @param int                   $kits           Kits no pedido.
 * @return int
 */
function papelito_event_sales_units( array $qty_by_product, int $kits ): int {
	return (int) array_sum( array_map( 'intval', $qty_by_product ) ) + $kits * PAPELITO_EVENT_SALES_KIT_UNITS;
}

/**
 * Maior nível cujo mínimo em centavos a base do pedido atinge.
 *
 * O nome diz `_for_cents` de propósito: a escada já contou unidade, e um chamador antigo que
 * passasse unidade aqui escolheria o nível errado em silêncio, cobrando a menos.
 *
 * @param int $base_cents Base da faixa, em centavos.
 * @return array{key:string,name:string,min_cents:int,pct_bp:int}
 */
function papelito_event_sales_level_for_cents( int $base_cents ): array {
	$current = papelito_event_sales_levels()[0];

	foreach ( papelito_event_sales_levels() as $level ) {
		if ( $base_cents >= $level['min_cents'] ) {
			$current = $level;
		}
	}

	return $current;
}

/**
 * Valor em centavos a partir do qual o Kit Premium e o brinde entram: o primeiro degrau com
 * desconto. Fica derivado da escada para a liberação do kit não descolar da faixa quando os
 * valores mudarem.
 *
 * @return int
 */
function papelito_event_sales_kit_min_cents(): int {
	foreach ( papelito_event_sales_levels() as $level ) {
		if ( $level['pct_bp'] > 0 ) {
			return (int) $level['min_cents'];
		}
	}

	return PHP_INT_MAX;
}

/**
 * Desconto do frete do estande: a partir de R$ 500 no pedido a Papelito paga o frete inteiro.
 *
 * Devolve o abatimento, nunca um preço: o valor cobrado do lojista pela modalidade é assinado e
 * reconferido na criação do pedido, então zerá-lo faria a venda ser recusada na maquininha. Quem
 * abate é o `papelito_order_routing_add_shipping_item()`, que guarda o preço cheio no meta.
 *
 * O corte vale para qualquer CEP: a promessa do evento não tem exceção de região.
 *
 * @param int $base_cents     Base da faixa, em centavos.
 * @param int $shipping_cents Preço da modalidade escolhida, em centavos.
 * @return int
 */
function papelito_event_sales_shipping_discount_cents( int $base_cents, int $shipping_cents ): int {
	return $base_cents >= PAPELITO_EVENT_SALES_FREE_SHIPPING ? max( 0, $shipping_cents ) : 0;
}

/**
 * Base da faixa: avulsos pelo preço do estande mais os kits pelo preço cheio, antes de qualquer
 * desconto e antes do frete. É este número que escolhe o nível e que libera o kit.
 *
 * @param array<int,array{price_cents:int,name:string}> $products        Catálogo por id.
 * @param array<int|string,int>                         $qty_by_product  Quantidade por produto.
 * @param int                                           $kits            Kits no pedido.
 * @param array<int,int>                                $kit_product_ids Os 4 produtos do kit.
 * @return int|WP_Error
 */
function papelito_event_sales_base_cents( array $products, array $qty_by_product, int $kits, array $kit_product_ids ): int|WP_Error {
	$base = 0;

	foreach ( $qty_by_product as $product_id => $qty ) {
		if ( (int) $qty > 0 ) {
			$base += (int) $qty * (int) $products[ (int) $product_id ]['price_cents'];
		}
	}

	if ( 0 === $kits ) {
		return $base;
	}

	$prices = papelito_event_sales_kit_prices( $products, $kit_product_ids );
	if ( is_wp_error( $prices ) ) {
		return $prices;
	}

	return $base + $kits * (int) $prices['full_unit_cents'];
}

/**
 * Preço de um kit: soma dos 4 produtos com o desconto do kit, cada um arredondado no centavo.
 *
 * @param array<int,int> $component_price_cents Preço atual de cada produto do kit.
 * @param int            $pct_kept              Porcentagem do preço que o kit mantém (85 = −15%).
 * @return int
 */
function papelito_event_sales_kit_unit_cents( array $component_price_cents, int $pct_kept = PAPELITO_EVENT_SALES_KIT_PCT_KEPT ): int {
	$total = 0;

	foreach ( $component_price_cents as $price ) {
		$total += intdiv( (int) $price * $pct_kept + 50, 100 );
	}

	return $total;
}

/**
 * Erro de preço com o status HTTP no formato que o REST do WordPress devolve.
 *
 * @param string $code    Código do erro.
 * @param string $message Mensagem para o operador.
 * @param int    $status  Status HTTP.
 * @return WP_Error
 */
function papelito_event_sales_pricing_error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

/**
 * Confere limites de quantidade e se cada produto existe no catálogo do evento.
 *
 * @param array<int,array{price_cents:int,name:string}> $products       Catálogo por id.
 * @param array<int|string,int>                         $qty_by_product Quantidade por produto.
 * @param int                                           $kits           Kits no pedido.
 * @return true|WP_Error
 */
function papelito_event_sales_validate_quantities( array $products, array $qty_by_product, int $kits ): bool|WP_Error {
	if ( $kits < 0 || $kits > PAPELITO_EVENT_SALES_KIT_MAX ) {
		return papelito_event_sales_pricing_error( PAPELITO_EVENT_SALES_INVALID_QUANTITY, 'O pedido aceita de 0 a 3 kits.', 400 );
	}

	foreach ( $qty_by_product as $product_id => $qty ) {
		if ( (int) $qty < 0 || (int) $qty > PAPELITO_EVENT_SALES_MAX_QTY ) {
			return papelito_event_sales_pricing_error( PAPELITO_EVENT_SALES_INVALID_QUANTITY, 'A quantidade de cada produto vai de 0 a 999.', 400 );
		}

		if ( ! isset( $products[ (int) $product_id ] ) ) {
			return papelito_event_sales_pricing_error( 'papelito_event_unknown_product', 'Um produto do pedido não está à venda no evento.', 400 );
		}
	}

	return true;
}

/**
 * Preço do kit pelos produtos atuais, ou erro quando o kit não está completo no catálogo.
 *
 * @param array<int,array{price_cents:int,name:string}> $products        Catálogo por id.
 * @param array<int,int>                                $kit_product_ids Os 4 produtos do kit.
 * @param int                                           $pct_kept        Porcentagem que o kit mantém.
 * @return array{unit_cents:int,full_unit_cents:int}|WP_Error
 */
function papelito_event_sales_kit_prices( array $products, array $kit_product_ids, int $pct_kept = PAPELITO_EVENT_SALES_KIT_PCT_KEPT ): array|WP_Error {
	$prices = array();

	foreach ( $kit_product_ids as $product_id ) {
		$price = (int) ( $products[ $product_id ]['price_cents'] ?? 0 );
		if ( $price > 0 ) {
			$prices[] = $price;
		}
	}

	if ( PAPELITO_EVENT_SALES_KIT_COMPONENTS !== count( $kit_product_ids ) || PAPELITO_EVENT_SALES_KIT_COMPONENTS !== count( $prices ) ) {
		return papelito_event_sales_pricing_error( 'papelito_event_kit_unavailable', 'O Kit Premium não está disponível agora.', 409 );
	}

	return array(
		'unit_cents'      => papelito_event_sales_kit_unit_cents( $prices, $pct_kept ),
		'full_unit_cents' => (int) array_sum( $prices ),
	);
}

/**
 * Linha avulsa com o desconto do nível arredondado no centavo.
 *
 * @param int $product_id  Produto.
 * @param int $qty         Quantidade.
 * @param int $unit_cents  Preço unitário atual.
 * @param int $pct_bp      Porcentagem do nível em pontos-base.
 * @return array<string,int|string>
 */
function papelito_event_sales_line( int $product_id, int $qty, int $unit_cents, int $pct_bp ): array {
	$subtotal = $qty * $unit_cents;
	$discount = intdiv( $subtotal * $pct_bp + PAPELITO_EVENT_SALES_BASIS_POINTS / 2, PAPELITO_EVENT_SALES_BASIS_POINTS );

	return array(
		'product_id'     => $product_id,
		'qty'            => $qty,
		'unit_cents'     => $unit_cents,
		'subtotal_cents' => $subtotal,
		'discount_cents' => $discount,
		'total_cents'    => $subtotal - $discount,
		'source'         => 'event_level',
	);
}

/**
 * Bloco do kit no pedido; com zero kits o preço fica zerado e nada é exigido do catálogo.
 *
 * @param array<int,array{price_cents:int,name:string}> $products        Catálogo por id.
 * @param int                                           $kits            Kits no pedido.
 * @param int                                           $base_cents      Base da faixa, em centavos.
 * @param array<int,int>                                $kit_product_ids Os 4 produtos do kit.
 * @param int                                           $pct_kept        Porcentagem que o kit mantém.
 * @return array{qty:int,unit_cents:int,full_unit_cents:int,total_cents:int}|WP_Error
 */
function papelito_event_sales_kit_block( array $products, int $kits, int $base_cents, array $kit_product_ids, int $pct_kept = PAPELITO_EVENT_SALES_KIT_PCT_KEPT ): array|WP_Error {
	if ( 0 === $kits ) {
		return array(
			'qty'             => 0,
			'unit_cents'      => 0,
			'full_unit_cents' => 0,
			'total_cents'     => 0,
		);
	}

	$kit_min = papelito_event_sales_kit_min_cents();
	if ( $base_cents < $kit_min ) {
		$minimo = number_format( $kit_min / 100, 2, ',', '.' );
		return papelito_event_sales_pricing_error( 'papelito_event_kit_requires_level', "O Kit Premium só entra a partir de R$ {$minimo} no pedido.", 409 );
	}

	$prices = papelito_event_sales_kit_prices( $products, $kit_product_ids, $pct_kept );
	if ( is_wp_error( $prices ) ) {
		return $prices;
	}

	return array(
		'qty'             => $kits,
		'unit_cents'      => $prices['unit_cents'],
		'full_unit_cents' => $prices['full_unit_cents'],
		'total_cents'     => $kits * $prices['unit_cents'],
	);
}

/**
 * Preço completo do pedido do evento: unidades, nível, linhas avulsas e kit.
 *
 * É a autoridade do valor; quem cobra recalcula por aqui com os preços atuais, nunca confia no
 * valor que o tablet mostrou.
 *
 * @param array<int,array{price_cents:int,name:string}> $products        Catálogo por id.
 * @param array<int|string,int>                         $qty_by_product  Quantidade por produto.
 * @param int                                           $kits            Kits no pedido.
 * @param array<int,int>                                $kit_product_ids Os 4 produtos do kit.
 * @param int                                           $kit_pct_kept    Porcentagem que o kit mantém.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_price( array $products, array $qty_by_product, int $kits, array $kit_product_ids, int $kit_pct_kept = PAPELITO_EVENT_SALES_KIT_PCT_KEPT ): array|WP_Error {
	$valid = papelito_event_sales_validate_quantities( $products, $qty_by_product, $kits );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$base = papelito_event_sales_base_cents( $products, $qty_by_product, $kits, $kit_product_ids );
	if ( is_wp_error( $base ) ) {
		return $base;
	}

	$units = papelito_event_sales_units( $qty_by_product, $kits );
	$level = papelito_event_sales_level_for_cents( $base );
	$kit   = papelito_event_sales_kit_block( $products, $kits, $base, $kit_product_ids, $kit_pct_kept );
	if ( is_wp_error( $kit ) ) {
		return $kit;
	}

	$lines = array();
	foreach ( $qty_by_product as $product_id => $qty ) {
		if ( (int) $qty > 0 ) {
			$lines[] = papelito_event_sales_line( (int) $product_id, (int) $qty, (int) $products[ (int) $product_id ]['price_cents'], $level['pct_bp'] );
		}
	}

	$discount = (int) array_sum( array_column( $lines, 'discount_cents' ) );

	return array(
		'units'             => $units,
		'base_cents'        => $base,
		'level'             => $level,
		'lines'             => $lines,
		'kit'               => $kit,
		'items_cents'       => (int) array_sum( array_column( $lines, 'total_cents' ) ) + $kit['total_cents'],
		'discount_cents'    => $discount,
		'kit_savings_cents' => $kit['qty'] * ( $kit['full_unit_cents'] - $kit['unit_cents'] ),
	);
}
