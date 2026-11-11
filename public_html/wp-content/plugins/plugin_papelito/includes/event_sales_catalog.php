<?php
/**
 * Catálogo e contexto do tablet na venda do estande.
 *
 * Entrega numa chamada tudo o que as telas precisam para montar a loja: produtos com preço atual e
 * estoque do vendor do evento, categorias, prateleiras, níveis e o preço do Kit Premium.
 *
 * Regra de ouro: o produto sem estoque continua listado (o tablet mostra "Sem estoque"), e o kit
 * cadastrado no WooCommerce nunca entra: o Kit Premium do evento é calculado a partir dos 4
 * produtos de `PAPELITO_EVENT_SALES_KIT_PRODUCT_IDS`.
 *
 * O que este módulo não faz: não decide o valor cobrado. A cotação recalcula tudo.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_SHELF_TOP_SELLERS = 6;
const PAPELITO_EVENT_SALES_SHELF_NEW         = 4;

/**
 * Converte o preço decimal do WooCommerce em centavos inteiros.
 *
 * @param string $price Preço como o WooCommerce guarda.
 * @return int
 */
function papelito_event_sales_to_cents( string $price ): int {
	return is_numeric( $price ) ? (int) round( (float) $price * 100 ) : 0;
}

/**
 * Atributos do produto no formato de ficha técnica do tablet.
 *
 * @param WC_Product $product Produto.
 * @return array<int,array{label:string,value:string}>
 */
function papelito_event_sales_product_specs( WC_Product $product ): array {
	$specs = array();

	foreach ( array_keys( $product->get_attributes() ) as $name ) {
		$value = trim( (string) $product->get_attribute( (string) $name ) );
		if ( '' !== $value ) {
			$specs[] = array(
				'label' => wc_attribute_label( (string) $name ),
				'value' => $value,
			);
		}
	}

	return $specs;
}

/**
 * Diz se o produto pode ser vendido no estande: publicado, visível no catálogo, com preço, com
 * categoria Papelito e sem ser kit do WooCommerce.
 *
 * É o critério único do catálogo do tablet e da cotação: produto que sai do catálogo depois de o
 * tablet carregar o contexto deixa de ser cotável e cobrável.
 *
 * @param WC_Product $product Produto.
 * @return bool
 */
function papelito_event_sales_product_sellable( WC_Product $product ): bool {
	$product_id = $product->get_id();

	return 'publish' === $product->get_status()
		&& in_array( $product->get_catalog_visibility( 'edit' ), array( 'visible', 'catalog' ), true )
		&& papelito_event_sales_to_cents( (string) $product->get_price( 'edit' ) ) > 0
		&& is_array( papelito_product_get_category( $product_id ) )
		&& ! papelito_kit_is_product( $product_id );
}

/**
 * Linha de catálogo do evento, ou null quando o produto não pode ser vendido no estande.
 *
 * @param WC_Product $product   Produto publicado.
 * @param int        $vendor_id Vendor que vende no evento.
 * @return array<string,mixed>|null
 */
function papelito_event_sales_catalog_row( WC_Product $product, int $vendor_id ): ?array {
	if ( ! papelito_event_sales_product_sellable( $product ) ) {
		return null;
	}

	$product_id = $product->get_id();
	$price      = papelito_event_sales_to_cents( (string) $product->get_price( 'edit' ) );
	$category   = (array) papelito_product_get_category( $product_id );

	$image   = wp_get_attachment_image_url( (int) $product->get_image_id(), 'large' );
	$created = $product->get_date_created();

	return array(
		'id'          => $product_id,
		'name'        => $product->get_name(),
		'slug'        => $product->get_slug(),
		'imageUrl'    => is_string( $image ) ? $image : '',
		'priceCents'  => $price,
		'stock'       => max( 0, (int) papelito_get_vendor_stock( $vendor_id, $product_id ) ),
		'category'    => array(
			'slug' => (string) $category['slug'],
			'name' => (string) $category['name'],
		),
		'description' => wp_strip_all_tags( (string) $product->get_short_description( 'edit' ) ),
		'specs'       => papelito_event_sales_product_specs( $product ),
		'totalSales'  => (int) $product->get_total_sales( 'edit' ),
		'createdAt'   => null === $created ? '' : $created->format( DATE_ATOM ),
	);
}

/**
 * Produtos publicados e visíveis no catálogo que o estande pode vender, com estoque do vendor.
 *
 * @param int $vendor_id Vendor que vende no evento.
 * @return array<int,array<string,mixed>>
 */
function papelito_event_sales_catalog_products( int $vendor_id ): array {
	$products = wc_get_products(
		array(
			'status'     => 'publish',
			'visibility' => 'catalog',
			'limit'      => -1,
			'orderby'    => 'title',
			'order'      => 'ASC',
		)
	);

	$rows = array();
	foreach ( is_array( $products ) ? $products : array() as $product ) {
		$row = $product instanceof WC_Product ? papelito_event_sales_catalog_row( $product, $vendor_id ) : null;
		if ( null !== $row ) {
			$rows[] = $row;
		}
	}

	return $rows;
}

/**
 * Diz se a linha de catálogo tem estoque para entrar numa prateleira.
 *
 * @param array<string,mixed> $product Linha de catálogo.
 * @return bool
 */
function papelito_event_sales_in_stock( array $product ): bool {
	return (int) $product['stock'] > 0;
}

/**
 * Compara por vendas, da maior para a menor.
 *
 * @param array<string,mixed> $a Linha de catálogo.
 * @param array<string,mixed> $b Linha de catálogo.
 * @return int
 */
function papelito_event_sales_by_sales_desc( array $a, array $b ): int {
	return (int) $b['totalSales'] <=> (int) $a['totalSales'];
}

/**
 * Compara por data de criação, da mais recente para a mais antiga.
 *
 * @param array<string,mixed> $a Linha de catálogo.
 * @param array<string,mixed> $b Linha de catálogo.
 * @return int
 */
function papelito_event_sales_by_created_desc( array $a, array $b ): int {
	return strcmp( (string) $b['createdAt'], (string) $a['createdAt'] );
}

/**
 * Prateleiras da tela inicial da loja: mais vendidos, linha premium e lançamentos.
 *
 * @param array<int,array<string,mixed>> $products Catálogo do evento.
 * @param array<int,int>                 $kit_ids  Produtos do Kit Premium.
 * @return array{mais_vendidos:array<int,int>,premium:array<int,int>,lancamentos:array<int,int>}
 */
function papelito_event_sales_shelves( array $products, array $kit_ids ): array {
	$available = array_values( array_filter( $products, 'papelito_event_sales_in_stock' ) );

	$by_sales = $available;
	usort( $by_sales, 'papelito_event_sales_by_sales_desc' );

	$by_created = $available;
	usort( $by_created, 'papelito_event_sales_by_created_desc' );

	return array(
		'mais_vendidos' => array_column( array_slice( $by_sales, 0, PAPELITO_EVENT_SALES_SHELF_TOP_SELLERS ), 'id' ),
		'premium'       => array_values( $kit_ids ),
		'lancamentos'   => array_column( array_slice( $by_created, 0, PAPELITO_EVENT_SALES_SHELF_NEW ), 'id' ),
	);
}

/**
 * Catálogo indexado por id no formato que as regras de preço consomem.
 *
 * @param array<int,array<string,mixed>> $products Catálogo do evento.
 * @return array<int,array{price_cents:int,name:string}>
 */
function papelito_event_sales_price_index( array $products ): array {
	$index = array();

	foreach ( $products as $product ) {
		$index[ (int) $product['id'] ] = array(
			'price_cents' => (int) $product['priceCents'],
			'name'        => (string) $product['name'],
		);
	}

	return $index;
}

/**
 * Bloco do Kit Premium no contexto: preço atual ou indisponível quando falta produto.
 *
 * @param array<int,array{price_cents:int,name:string}> $index   Catálogo por id.
 * @param array<int,int>                                $kit_ids Produtos do Kit Premium.
 * @return array{productIds:array<int,int>,unitCents:int,fullUnitCents:int,available:bool}
 */
function papelito_event_sales_context_kit( array $index, array $kit_ids ): array {
	$prices    = papelito_event_sales_kit_prices( $index, $kit_ids );
	$available = ! is_wp_error( $prices );

	return array(
		'productIds'    => array_values( $kit_ids ),
		'unitCents'     => $available ? $prices['unit_cents'] : 0,
		'fullUnitCents' => $available ? $prices['full_unit_cents'] : 0,
		'available'     => $available,
	);
}

/**
 * Nível no formato público do contexto.
 *
 * @param array{key:string,name:string,min_units:int,pct_bp:int} $level Nível.
 * @return array{key:string,name:string,minUnits:int,pctBp:int}
 */
function papelito_event_sales_public_level( array $level ): array {
	return array(
		'key'      => $level['key'],
		'name'     => $level['name'],
		'minUnits' => $level['min_units'],
		'pctBp'    => $level['pct_bp'],
	);
}

/**
 * Categorias que têm produto no catálogo do evento, na ordem da taxonomia.
 *
 * @param array<int,array<string,mixed>> $products Catálogo do evento.
 * @return array<int,array{slug:string,name:string}>
 */
function papelito_event_sales_context_categories( array $products ): array {
	$present    = array_flip( array_map( 'strval', array_column( array_column( $products, 'category' ), 'slug' ) ) );
	$categories = array();

	foreach ( papelito_categories_list() as $category ) {
		if ( isset( $present[ (string) $category['slug'] ] ) ) {
			$categories[] = array(
				'slug' => (string) $category['slug'],
				'name' => (string) $category['name'],
			);
		}
	}

	return $categories;
}

/**
 * Contexto completo do tablet para o operador.
 *
 * @param WP_User $user      Operador autenticado.
 * @param int     $vendor_id Vendor que vende no evento.
 * @return array<string,mixed>
 */
function papelito_event_sales_context( WP_User $user, int $vendor_id ): array {
	$config   = papelito_event_sales_config();
	$products = papelito_event_sales_catalog_products( $vendor_id );

	return array(
		'event'      => array(
			'id'       => PAPELITO_EVENT_SALES_ID,
			'name'     => PAPELITO_EVENT_SALES_NAME,
			'startsAt' => gmdate( DATE_ATOM, $config['starts_at'] ),
			'endsAt'   => gmdate( DATE_ATOM, $config['ends_at'] ),
		),
		'operator'   => array(
			'name'         => (string) $user->display_name,
			'canSupervise' => user_can( $user, PAPELITO_EVENT_SALES_CAP_SUPERVISE ),
		),
		'levels'     => array_map( 'papelito_event_sales_public_level', papelito_event_sales_levels() ),
		'kit'        => papelito_event_sales_context_kit( papelito_event_sales_price_index( $products ), $config['kit_product_ids'] ),
		'categories' => papelito_event_sales_context_categories( $products ),
		'products'   => $products,
		'shelves'    => papelito_event_sales_shelves( $products, $config['kit_product_ids'] ),
	);
}

/**
 * Resposta JSON do evento sem cache em nenhuma camada.
 *
 * @param array<string,mixed> $data   Corpo.
 * @param int                 $status Status HTTP.
 * @return WP_REST_Response
 */
function papelito_event_sales_response( array $data, int $status = 200 ): WP_REST_Response {
	$response = new WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'no-store' );

	return $response;
}

/**
 * `GET event/{id}/context`: o gate já validou operador, janela e configuração.
 *
 * @return WP_REST_Response
 */
function papelito_event_sales_context_endpoint(): WP_REST_Response {
	return papelito_event_sales_response( papelito_event_sales_context( wp_get_current_user(), papelito_event_sales_config()['vendor_id'] ) );
}

/**
 * Registra a rota de contexto do evento.
 *
 * @return void
 */
function papelito_event_sales_register_catalog_routes(): void {
	papelito_event_sales_register_route(
		'/context',
		array(
			'methods'             => 'GET',
			'callback'            => 'papelito_event_sales_context_endpoint',
			'permission_callback' => 'papelito_event_sales_require_seller',
		)
	);
}
add_action( 'rest_api_init', 'papelito_event_sales_register_catalog_routes' );
