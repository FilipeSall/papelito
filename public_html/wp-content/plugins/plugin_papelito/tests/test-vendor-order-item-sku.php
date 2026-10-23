<?php
/**
 * Teste standalone do SKU exibido em cada item do pedido no painel do vendor.
 *
 * Garante o mesmo critério da tela de estoque: o código do vendor quando existe, senão o SKU
 * da Papelito; a variação comprada vence o produto pai; sem schema de códigos, cai para o
 * catálogo em vez de quebrar o detalhe.
 *
 * Uso: php tests/test-vendor-order-item-sku.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

const ITEM_SKU_TEST_VENDOR_ID     = 10;
const ITEM_SKU_TEST_PARENT_ID     = 100;
const ITEM_SKU_TEST_VARIATION_ID  = 101;
const ITEM_SKU_TEST_SIMPLE_ID     = 200;
const ITEM_SKU_TEST_MISSING_ID    = 300;
const ITEM_SKU_TEST_VARIATION_SKU = 'PPL-PIT-LG';
const ITEM_SKU_TEST_SIMPLE_SKU    = 'PPL-PIT-TR';
const ITEM_SKU_TEST_VENDOR_CODE   = '000419';

$GLOBALS['item_sku_test_codes']       = array();
$GLOBALS['item_sku_test_codes_error'] = false;
$GLOBALS['item_sku_test_lookups']     = array();

/**
 * Erro mínimo do WordPress para o caminho de schema indisponível.
 */
class WP_Error {
}

/**
 * Produto mínimo com SKU.
 */
class Item_Sku_Test_Product {
	/**
	 * Produto com o SKU informado.
	 *
	 * @param string $sku SKU do catálogo.
	 */
	public function __construct( private string $sku ) {
	}

	/**
	 * SKU do catálogo.
	 */
	public function get_sku(): string {
		return $this->sku;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function sanitize_text_field( mixed $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function add_action( mixed ...$args ): bool {
	return true;
}

function add_filter( mixed ...$args ): bool {
	return true;
}

function register_rest_route( mixed ...$args ): bool {
	return true;
}

function wc_get_product( mixed $product_id ): Item_Sku_Test_Product|false {
	$catalog = array(
		ITEM_SKU_TEST_VARIATION_ID => ITEM_SKU_TEST_VARIATION_SKU,
		ITEM_SKU_TEST_SIMPLE_ID    => ITEM_SKU_TEST_SIMPLE_SKU,
	);

	return isset( $catalog[ (int) $product_id ] ) ? new Item_Sku_Test_Product( $catalog[ (int) $product_id ] ) : false;
}

function papelito_vendor_item_settings_get_many( int $vendor_id, array $product_ids ): array|WP_Error {
	$GLOBALS['item_sku_test_lookups'][] = array( $vendor_id, $product_ids );

	return $GLOBALS['item_sku_test_codes_error'] ? new WP_Error() : $GLOBALS['item_sku_test_codes'];
}

require_once dirname( __DIR__ ) . '/includes/vendor_dashboard.php';

$failures = 0;

function item_sku_test_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

function item_sku_test_items(): array {
	return array(
		array(
			'item_id'      => 1,
			'product_id'   => ITEM_SKU_TEST_PARENT_ID,
			'variation_id' => ITEM_SKU_TEST_VARIATION_ID,
		),
		array(
			'item_id'      => 2,
			'product_id'   => ITEM_SKU_TEST_SIMPLE_ID,
			'variation_id' => 0,
		),
		array(
			'item_id'      => 3,
			'product_id'   => ITEM_SKU_TEST_MISSING_ID,
			'variation_id' => 0,
		),
	);
}

echo "Cenário 1: sem código do vendor, cada item mostra o SKU da Papelito\n";
$items = papelito_vendor_dashboard_items_with_sku( item_sku_test_items(), ITEM_SKU_TEST_VENDOR_ID );
item_sku_test_assert( 'variação usa o SKU da variação', ITEM_SKU_TEST_VARIATION_SKU, $items[0]['sku'] );
item_sku_test_assert( 'produto simples usa o próprio SKU', ITEM_SKU_TEST_SIMPLE_SKU, $items[1]['sku'] );
item_sku_test_assert( 'produto removido vem com SKU vazio', '', $items[2]['sku'] );
item_sku_test_assert(
	'consulta os códigos do vendor uma vez, pelos ids que carregam o SKU',
	array( array( ITEM_SKU_TEST_VENDOR_ID, array( ITEM_SKU_TEST_VARIATION_ID, ITEM_SKU_TEST_SIMPLE_ID, ITEM_SKU_TEST_MISSING_ID ) ) ),
	$GLOBALS['item_sku_test_lookups']
);

echo "Cenário 2: código do vendor vence o SKU da Papelito só no item que o tem\n";
$GLOBALS['item_sku_test_codes'] = array(
	ITEM_SKU_TEST_VARIATION_ID => array(
		'vendor_code' => ITEM_SKU_TEST_VENDOR_CODE,
		'updated_at'  => '2026-10-01 10:00:00',
	),
);
$items                          = papelito_vendor_dashboard_items_with_sku( item_sku_test_items(), ITEM_SKU_TEST_VENDOR_ID );
item_sku_test_assert( 'variação com código mostra o código do vendor', ITEM_SKU_TEST_VENDOR_CODE, $items[0]['sku'] );
item_sku_test_assert( 'item sem código continua com o SKU da Papelito', ITEM_SKU_TEST_SIMPLE_SKU, $items[1]['sku'] );

echo "Cenário 3: schema de códigos indisponível cai para o catálogo\n";
$GLOBALS['item_sku_test_codes_error'] = true;
$items                                = papelito_vendor_dashboard_items_with_sku( item_sku_test_items(), ITEM_SKU_TEST_VENDOR_ID );
item_sku_test_assert( 'variação volta para o SKU da Papelito', ITEM_SKU_TEST_VARIATION_SKU, $items[0]['sku'] );

echo "Cenário 4: pedido sem itens do vendor não consulta códigos\n";
$GLOBALS['item_sku_test_lookups'] = array();
item_sku_test_assert( 'lista vazia devolvida intacta', array(), papelito_vendor_dashboard_items_with_sku( array(), ITEM_SKU_TEST_VENDOR_ID ) );
item_sku_test_assert( 'nenhuma consulta feita', array(), $GLOBALS['item_sku_test_lookups'] );

if ( $failures > 0 ) {
	echo "\n{$failures} falha(s).\n";
	exit( 1 );
}

echo "\nTodos os cenários passaram.\n";
