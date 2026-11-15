<?php
/**
 * Preços do estande definidos pelo admin e o resumo do que o evento já vendeu.
 *
 * O admin pode dar a um produto um preço próprio do evento, que vale só no tablet e na cotação do
 * estande; sem ele, vale o preço do site. Também define o desconto do Kit Premium (−15% por padrão).
 * Tudo mora numa option versionada: publicar exige a versão que o admin abriu, e quem publicou por
 * cima recebe 409 em vez de apagar o trabalho do outro em silêncio. A gravação é um compare-and-swap
 * no banco (a linha só muda se ainda tiver o valor lido), então duas publicações simultâneas da mesma
 * versão nunca vencem as duas.
 *
 * Regra de ouro: o preço próprio nunca toca o produto do WooCommerce. O site continua com o preço
 * dele, e apagar o preço próprio volta o estande para o preço do site.
 *
 * O que este módulo não faz: não mexe nos níveis por unidade (continuam em
 * `papelito_event_sales_levels()`) nem recalcula pedido já criado; a cotação seguinte já usa o preço
 * novo, e o tablet recebe o catálogo novo ao recarregar a página.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_PRICES_OPTION     = 'papelito_event_sales_prices';
const PAPELITO_EVENT_SALES_KIT_PCT_OFF       = 15;
const PAPELITO_EVENT_SALES_KIT_PCT_OFF_MAX   = 50;
const PAPELITO_EVENT_SALES_PRICE_MAX_CENTS   = 10000000;
const PAPELITO_EVENT_SALES_PRICES_INVALID    = 'papelito_event_prices_invalid';
const PAPELITO_EVENT_SALES_PRICES_PAID_KINDS = array( 'payment', 'manual_release' );
const PAPELITO_EVENT_SALES_PRICES_ROUTE      = '/admin/prices';

/**
 * Estado dos preços do evento a partir do valor cru da option, descartando o que não é válido.
 *
 * Função pura: não lê o banco. Preço próprio só entra com id e valor inteiros positivos.
 *
 * @param mixed $raw Valor guardado na option.
 * @return array{prices:array<int,int>,kit_pct_off:int,version:int,updated_at:string,updated_by:string}
 */
function papelito_event_sales_prices_normalize( mixed $raw ): array {
	$raw     = is_array( $raw ) ? $raw : array();
	$prices  = array();
	$kit_off = (int) ( $raw['kit_pct_off'] ?? PAPELITO_EVENT_SALES_KIT_PCT_OFF );

	foreach ( is_array( $raw['prices'] ?? null ) ? $raw['prices'] : array() as $product_id => $cents ) {
		if ( (int) $product_id > 0 && (int) $cents > 0 ) {
			$prices[ (int) $product_id ] = (int) $cents;
		}
	}

	return array(
		'prices'      => $prices,
		'kit_pct_off' => max( 0, min( PAPELITO_EVENT_SALES_KIT_PCT_OFF_MAX, $kit_off ) ),
		'version'     => max( 0, (int) ( $raw['version'] ?? 0 ) ),
		'updated_at'  => (string) ( $raw['updated_at'] ?? '' ),
		'updated_by'  => (string) ( $raw['updated_by'] ?? '' ),
	);
}

/**
 * Estado atual dos preços do evento, relido da option a cada chamada.
 *
 * @return array{prices:array<int,int>,kit_pct_off:int,version:int,updated_at:string,updated_by:string}
 */
function papelito_event_sales_prices_state(): array {
	return papelito_event_sales_prices_normalize( get_option( PAPELITO_EVENT_SALES_PRICES_OPTION, array() ) );
}

/**
 * Preço próprio do evento para o produto, ou null quando o estande usa o preço do site.
 *
 * @param int $product_id Produto.
 * @return int|null
 */
function papelito_event_sales_price_override( int $product_id ): ?int {
	return papelito_event_sales_prices_state()['prices'][ $product_id ] ?? null;
}

/**
 * Porcentagem do preço que o Kit Premium mantém (85 quando o desconto é 15%).
 *
 * @return int
 */
function papelito_event_sales_kit_pct_kept(): int {
	return 100 - papelito_event_sales_prices_state()['kit_pct_off'];
}

/**
 * Erro de publicação de preço com status HTTP.
 *
 * @param string $message Mensagem para o admin.
 * @param int    $status  Status HTTP.
 * @param string $code    Código do erro.
 * @return WP_Error
 */
function papelito_event_sales_prices_error( string $message, int $status = 400, string $code = PAPELITO_EVENT_SALES_PRICES_INVALID ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

/**
 * Preços próprios enviados pelo admin, conferidos contra o catálogo do evento.
 *
 * Valor nulo ou vazio tira o preço próprio. Produto fora do catálogo do estande é recusado, para
 * nenhum preço ficar guardado sem ninguém ver.
 *
 * @param mixed          $prices    Mapa `productId => centavos|null`.
 * @param array<int,int> $known_ids Produtos do catálogo do evento.
 * @return array<int,int>|WP_Error
 */
function papelito_event_sales_prices_parse( mixed $prices, array $known_ids ): array|WP_Error {
	if ( ! is_array( $prices ) ) {
		return papelito_event_sales_prices_error( 'Os preços do evento vieram num formato inválido.' );
	}

	$known  = array_flip( $known_ids );
	$parsed = array();

	foreach ( $prices as $product_id => $cents ) {
		if ( null === $cents || '' === $cents ) {
			continue;
		}

		if ( ! isset( $known[ (int) $product_id ] ) ) {
			return papelito_event_sales_prices_error( 'Um dos produtos não está à venda no estande. Recarregue a página.' );
		}

		if ( ! is_int( $cents ) || $cents <= 0 || $cents > PAPELITO_EVENT_SALES_PRICE_MAX_CENTS ) {
			return papelito_event_sales_prices_error( 'Cada preço do evento precisa ser maior que zero e até R$ 100.000,00.' );
		}

		$parsed[ (int) $product_id ] = $cents;
	}

	return $parsed;
}

/**
 * Desconto do Kit Premium enviado pelo admin, em porcentagem inteira de 0 a 50.
 *
 * @param mixed $value Valor enviado.
 * @return int|WP_Error
 */
function papelito_event_sales_prices_parse_kit( mixed $value ): int|WP_Error {
	if ( ! is_int( $value ) || $value < 0 || $value > PAPELITO_EVENT_SALES_KIT_PCT_OFF_MAX ) {
		return papelito_event_sales_prices_error( 'O desconto do kit vai de 0% a 50%, em número inteiro.' );
	}

	return $value;
}

/**
 * Valor da option como está no banco agora, sem o cache de objetos; `null` quando ela não existe.
 *
 * @return string|null
 */
function papelito_event_sales_prices_stored(): ?string {
	global $wpdb;

	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", PAPELITO_EVENT_SALES_PRICES_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return null === $value ? null : (string) $value;
}

/**
 * Grava o estado novo só se a option ainda tiver o valor lido em `$stored` (compare-and-swap).
 * Devolve false quando outra publicação gravou antes; nesse caso nada muda. Limpa o cache da option
 * nos dois desfechos, para a próxima leitura ver o banco.
 *
 * @param string|null         $stored Valor lido por `papelito_event_sales_prices_stored()`.
 * @param array<string,mixed> $next   Estado novo.
 * @return bool
 */
function papelito_event_sales_prices_swap( ?string $stored, array $next ): bool {
	global $wpdb;

	$value = maybe_serialize( $next );

	if ( null === $stored ) {
		$written = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'on')", PAPELITO_EVENT_SALES_PRICES_OPTION, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} else {
		$written = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, PAPELITO_EVENT_SALES_PRICES_OPTION, $stored ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( PAPELITO_EVENT_SALES_PRICES_OPTION, 'options' );

	return 1 === $written;
}

/**
 * Erro de conflito de versão: outra pessoa publicou depois de o admin abrir a tela.
 *
 * @return WP_Error
 */
function papelito_event_sales_prices_conflict(): WP_Error {
	return papelito_event_sales_prices_error( 'Outra pessoa publicou preços enquanto você editava. Recarregue a página para ver a versão atual.', 409, 'papelito_event_prices_conflict' );
}

/**
 * Publica os preços do evento. Recusa com 409 quando a versão aberta pelo admin não é mais a atual,
 * inclusive quando outra publicação grava entre a leitura e a escrita desta.
 *
 * @param array<string,mixed> $body      Corpo com `version`, `prices` e `kitPctOff`.
 * @param array<int,int>      $known_ids Produtos do catálogo do evento.
 * @param WP_User             $user      Admin que publica.
 * @return array{prices:array<int,int>,kit_pct_off:int,version:int,updated_at:string,updated_by:string}|WP_Error
 */
function papelito_event_sales_prices_publish( array $body, array $known_ids, WP_User $user ): array|WP_Error {
	$stored  = papelito_event_sales_prices_stored();
	$current = papelito_event_sales_prices_normalize( null === $stored ? array() : maybe_unserialize( $stored ) );

	if ( ! is_int( $body['version'] ?? null ) || $body['version'] !== $current['version'] ) {
		return papelito_event_sales_prices_conflict();
	}

	$prices = papelito_event_sales_prices_parse( $body['prices'] ?? null, $known_ids );
	if ( is_wp_error( $prices ) ) {
		return $prices;
	}

	$kit_off = papelito_event_sales_prices_parse_kit( $body['kitPctOff'] ?? null );
	if ( is_wp_error( $kit_off ) ) {
		return $kit_off;
	}

	$next = array(
		'prices'      => $prices,
		'kit_pct_off' => $kit_off,
		'version'     => $current['version'] + 1,
		'updated_at'  => gmdate( DATE_ATOM ),
		'updated_by'  => (string) $user->display_name,
	);

	return papelito_event_sales_prices_swap( $stored, $next ) ? $next : papelito_event_sales_prices_conflict();
}

/**
 * Displays dos pedidos vendidos numa consulta só: soma do `_qty` das linhas de produto (o kit já
 * entra como 4 linhas). Não hidrata pedido nenhum, para o custo não crescer com as vendas.
 *
 * @param array<int,int> $order_ids Pedidos vendidos.
 * @return int
 */
function papelito_event_sales_prices_units( array $order_ids ): int {
	global $wpdb;

	if ( array() === $order_ids ) {
		return 0;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );
	$sql          = "SELECT COALESCE(SUM(CAST(im.meta_value AS SIGNED)), 0) FROM {$wpdb->prefix}woocommerce_order_items oi INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_qty' WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$placeholders})";

	return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_map( 'intval', $order_ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Pedidos que contam como vendidos: pagos ou liberados à mão e sem estorno no ledger.
 *
 * @param array<int,array<string,mixed>> $rows Linhas do ledger.
 * @return array<int,int>
 */
function papelito_event_sales_prices_sold_order_ids( array $rows ): array {
	$paid     = array();
	$refunded = array();

	foreach ( $rows as $row ) {
		$order_id = (int) $row['order_id'];

		if ( in_array( (string) $row['kind'], PAPELITO_EVENT_SALES_PRICES_PAID_KINDS, true ) ) {
			$paid[ $order_id ] = $order_id;
		} elseif ( 'refund' === (string) $row['kind'] ) {
			$refunded[ $order_id ] = $order_id;
		}
	}

	return array_values( array_diff_key( $paid, $refunded ) );
}

/**
 * Quanto o evento já vendeu, pelo ledger de repasse: valores já descontam estornos, e o líquido é o
 * que vai inteiro para a Cifal. Linha com taxa ainda a conferir conta no bruto e fica fora do líquido.
 *
 * @param string $event_id Evento.
 * @return array{orders:int,units:int,grossCents:int,feeCents:int,netCents:int,pendingFeeRows:int}
 */
function papelito_event_sales_prices_sales( string $event_id ): array {
	$summary = papelito_event_sales_settlement_summary( $event_id );
	$orders  = papelito_event_sales_prices_sold_order_ids( $summary['rows'] );

	return array(
		'orders'         => count( $orders ),
		'units'          => papelito_event_sales_prices_units( $orders ),
		'grossCents'     => $summary['totals']['grossCents'],
		'feeCents'       => $summary['totals']['feeCents'],
		'netCents'       => $summary['totals']['netCents'],
		'pendingFeeRows' => $summary['totals']['pendingFeeRows'],
	);
}

/**
 * Linha de produto da tela de preços: preço do site, preço próprio e estoque do vendor.
 *
 * @param array<string,mixed> $product Linha do catálogo do evento.
 * @param array<int,int>      $prices  Preços próprios.
 * @return array<string,mixed>
 */
function papelito_event_sales_prices_product_row( array $product, array $prices ): array {
	return array(
		'id'             => (int) $product['id'],
		'name'           => (string) $product['name'],
		'imageUrl'       => (string) $product['imageUrl'],
		'category'       => $product['category'],
		'sitePriceCents' => (int) $product['sitePriceCents'],
		'eventCents'     => $prices[ (int) $product['id'] ] ?? null,
		'stock'          => (int) $product['stock'],
	);
}

/**
 * Tudo que a aba ExpoDay do admin mostra: produtos, Kit Premium, versão publicada e vendas.
 *
 * @param int $vendor_id Vendor que vende no evento.
 * @return array<string,mixed>
 */
function papelito_event_sales_prices_snapshot( int $vendor_id ): array {
	$state    = papelito_event_sales_prices_state();
	$products = papelito_event_sales_catalog_products( $vendor_id );
	$kit_ids  = papelito_event_sales_config()['kit_product_ids'];
	$rows     = array();

	foreach ( $products as $product ) {
		$rows[] = papelito_event_sales_prices_product_row( $product, $state['prices'] );
	}

	return array(
		'products'  => $rows,
		'kit'       => papelito_event_sales_context_kit( papelito_event_sales_price_index( $products ), $kit_ids ),
		'version'   => $state['version'],
		'updatedAt' => $state['updated_at'],
		'updatedBy' => $state['updated_by'],
		'sales'     => papelito_event_sales_prices_sales( PAPELITO_EVENT_SALES_ID ),
	);
}

/**
 * `GET event/{id}/admin/prices`.
 *
 * @return WP_REST_Response
 */
function papelito_event_sales_prices_endpoint(): WP_REST_Response {
	return papelito_event_sales_response( papelito_event_sales_prices_snapshot( papelito_event_sales_config()['vendor_id'] ) );
}

/**
 * `PUT event/{id}/admin/prices`: publica e devolve a tela já com a versão nova.
 *
 * @param WP_REST_Request $request Requisição com `version`, `prices` e `kitPctOff`.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_prices_publish_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$vendor_id = papelito_event_sales_config()['vendor_id'];
	$known_ids = array_map( 'intval', array_column( papelito_event_sales_catalog_products( $vendor_id ), 'id' ) );
	$published = papelito_event_sales_prices_publish( papelito_event_sales_json_body( $request ), $known_ids, wp_get_current_user() );

	if ( is_wp_error( $published ) ) {
		return $published;
	}

	return papelito_event_sales_response( papelito_event_sales_prices_snapshot( $vendor_id ) );
}

/**
 * Registra as rotas de preço do evento, só para `manage_options`.
 *
 * @return void
 */
function papelito_event_sales_register_prices_routes(): void {
	papelito_event_sales_register_route(
		PAPELITO_EVENT_SALES_PRICES_ROUTE,
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'papelito_event_sales_prices_endpoint',
				'permission_callback' => 'papelito_event_sales_require_admin',
			),
			array(
				'methods'             => 'PUT',
				'callback'            => 'papelito_event_sales_prices_publish_endpoint',
				'permission_callback' => 'papelito_event_sales_require_admin',
			),
		)
	);
}
add_action( 'rest_api_init', 'papelito_event_sales_register_prices_routes' );
