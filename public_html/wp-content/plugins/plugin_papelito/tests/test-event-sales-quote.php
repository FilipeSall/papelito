<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public string $code;
	public array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

class WC_Product {
	public int $id;
	public string $price;

	public function __construct( int $id, string $price ) {
		$this->id    = $id;
		$this->price = $price;
	}

	public function get_id(): int { return $this->id; }
	public function get_name(): string { return "Produto {$this->id}"; }
	public function get_status(): string { return 'publish'; }
	public function get_price( string $context = 'view' ): string { return $this->price; }
}

const EVENT_QUOTE_TEST_CEP     = '01001-000';
const EVENT_QUOTE_TEST_CNPJ    = '11222333000181';
const EVENT_QUOTE_TEST_VENDOR  = '2163';
const EVENT_QUOTE_TEST_LOOSE   = 200;

$GLOBALS['quote_env']      = array();
$GLOBALS['quote_prices']   = array();
$GLOBALS['quote_stock']    = array();
$GLOBALS['quote_shipping'] = array();
$GLOBALS['quote_calls']    = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function wp_salt( string $scheme = 'auth' ): string { return 'sal-de-teste'; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['quote_env'][ $key ] ?? $default; }
function papelito_kit_is_product( int $product_id ): bool { return false; }
function papelito_normalize_cnpj( string $raw ): string { return preg_replace( '/\D+/', '', $raw ); }
function papelito_shipping_normalize_cep( mixed $value ): string { return preg_replace( '/\D+/', '', (string) $value ); }
function papelito_get_vendor_stock( mixed $vendor_id, mixed $product_id ): int { return $GLOBALS['quote_stock'][ (int) $product_id ] ?? 0; }
function wc_get_product( mixed $id ): ?WC_Product {
	return isset( $GLOBALS['quote_prices'][ (int) $id ] ) ? new WC_Product( (int) $id, $GLOBALS['quote_prices'][ (int) $id ] ) : null;
}
function papelito_shipping_quote_all_providers( int $vendor_id, string $destination_cep, array $items, array $context = array() ): array|WP_Error {
	$GLOBALS['quote_calls'][] = array( $vendor_id, $destination_cep, $items, $context );
	return $GLOBALS['quote_shipping'];
}

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_config.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_pricing.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_catalog.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_quote.php';

$failures = 0;

function event_quote_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

function event_quote_option( string $provider, string $key, int $cents ): array {
	return array(
		'provider'             => $provider,
		'option_key'           => $key,
		'name'                 => strtoupper( $key ),
		'customer_price_cents' => $cents,
		'delivery_time'        => 5,
		'fingerprint'          => "fp-{$key}-{$cents}",
	);
}

function event_quote_fixture(): void {
	$GLOBALS['quote_env']      = array(
		'PAPELITO_EVENT_SALES_VENDOR_ID'       => EVENT_QUOTE_TEST_VENDOR,
		'PAPELITO_EVENT_SALES_KIT_PRODUCT_IDS' => '101,102,103,104',
	);
	$GLOBALS['quote_prices']   = array(
		101                    => '180.20',
		102                    => '191.95',
		103                    => '191.95',
		104                    => '207.60',
		EVENT_QUOTE_TEST_LOOSE => '111.62',
	);
	$GLOBALS['quote_stock']    = array( 101 => 50, 102 => 50, 103 => 50, 104 => 50, EVENT_QUOTE_TEST_LOOSE => 50 );
	$GLOBALS['quote_calls']    = array();
	$GLOBALS['quote_shipping'] = array(
		'options' => array(
			event_quote_option( 'correios', 'correios:sedex', 4500 ),
			event_quote_option( 'braspress', 'braspress:rodo', 1000 ),
			event_quote_option( 'correios', 'correios:pac', 2990 ),
		),
	);
}

function event_quote_input( array $items, int $kits ): array {
	return array(
		'items'         => $items,
		'kits'          => $kits,
		'cep'           => EVENT_QUOTE_TEST_CEP,
		'recipientCnpj' => EVENT_QUOTE_TEST_CNPJ,
	);
}

function test_quote_total_is_items_plus_kit_plus_shipping(): void {
	event_quote_fixture();
	$quote = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 14 ) ), 2 ) );
	event_quote_assert( 'cotação válida', is_array( $quote ) );
	event_quote_assert( 'itens com desconto e kit', 156268 - 12501 + 2 * 65595 === $quote['itemsCents'] );
	event_quote_assert( 'total soma o frete', $quote['itemsCents'] + 2990 === $quote['totalCents'] );
	event_quote_assert( 'nível vem das 22 unidades', 'pdv_perfeito' === $quote['level']['key'] && 22 === $quote['units'] );
	event_quote_assert( 'fingerprint presente', 64 === strlen( $quote['quoteFingerprint'] ) );
	event_quote_assert( 'componentes do kit com preço cheio e do kit', array( 'product_id' => 101, 'price_cents' => 18020, 'unit_cents' => 15317 ) === $quote['kitComponents'][0] && 4 === count( $quote['kitComponents'] ) );

	$call = $GLOBALS['quote_calls'][0];
	event_quote_assert( 'cota com o vendor do evento e o CEP em dígitos', 2163 === $call[0] && '01001000' === $call[1] );
	$declared = array_column( $call[2], 'declared_value_cents', 'product_id' );
	event_quote_assert( 'valor declarado do avulso é o total da linha', 143767 === $declared[ EVENT_QUOTE_TEST_LOOSE ] );
	event_quote_assert( 'componente do kit entra com o preço do kit', 2 * 15317 === $declared[101] );
	event_quote_assert( 'destinatário vai no contexto', EVENT_QUOTE_TEST_CNPJ === $call[3]['recipient_cnpj'] );
}

function test_quote_picks_cheapest_correios(): void {
	event_quote_fixture();
	$quote = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 3 ) ), 0 ) );
	event_quote_assert( 'escolhe o PAC, ignorando a Braspress mais barata', 'correios:pac' === $quote['shipping']['optionKey'] && 2990 === $quote['shipping']['priceCents'] );

	$GLOBALS['quote_shipping'] = array( 'options' => array( event_quote_option( 'braspress', 'braspress:rodo', 1000 ) ) );
	$none                      = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 3 ) ), 0 ) );
	event_quote_assert( 'sem Correios é frete indisponível', $none instanceof WP_Error && 'papelito_event_shipping_unavailable' === $none->get_error_code() && 422 === $none->get_error_data()['status'] );

	$GLOBALS['quote_shipping'] = new WP_Error( 'papelito_shipping_unavailable' );
	$failed                    = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 3 ) ), 0 ) );
	event_quote_assert( 'falha da cotação é frete indisponível', $failed instanceof WP_Error && 'papelito_event_shipping_unavailable' === $failed->get_error_code() );
}

function test_kit_component_stock_counts_kits(): void {
	event_quote_fixture();
	$GLOBALS['quote_stock'][101] = 2;
	$quote                       = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => 101, 'qty' => 1 ), array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 9 ) ), 2 ) );
	event_quote_assert( 'avulso mais kits acima do estoque é 409', $quote instanceof WP_Error && 'papelito_event_out_of_stock' === $quote->get_error_code() && 409 === $quote->get_error_data()['status'] );
	event_quote_assert( 'erro diz o produto e o disponível', array( array( 'productId' => 101, 'available' => 2 ) ) === $quote->get_error_data()['products'] );
	event_quote_assert( 'não cota frete sem estoque', array() === $GLOBALS['quote_calls'] );
}

function test_fingerprint_changes_with_price(): void {
	event_quote_fixture();
	$input  = event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 3 ) ), 0 );
	$before = papelito_event_sales_quote( $input );
	$again  = papelito_event_sales_quote( $input );
	event_quote_assert( 'mesma cotação, mesmo fingerprint', $before['quoteFingerprint'] === $again['quoteFingerprint'] );

	$GLOBALS['quote_prices'][ EVENT_QUOTE_TEST_LOOSE ] = '112.00';
	$after                                             = papelito_event_sales_quote( $input );
	event_quote_assert( 'preço novo muda o fingerprint', $before['quoteFingerprint'] !== $after['quoteFingerprint'] );

	$GLOBALS['quote_prices'][ EVENT_QUOTE_TEST_LOOSE ] = '111.62';
	$GLOBALS['quote_shipping']['options'][2]           = event_quote_option( 'correios', 'correios:pac', 3100 );
	$freight                                           = papelito_event_sales_quote( $input );
	event_quote_assert( 'frete novo muda o fingerprint', $before['quoteFingerprint'] !== $freight['quoteFingerprint'] );

	$unknown = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => 777, 'qty' => 1 ) ), 0 ) );
	event_quote_assert( 'produto que não existe é recusado', $unknown instanceof WP_Error && 'papelito_event_unknown_product' === $unknown->get_error_code() );
}

test_quote_total_is_items_plus_kit_plus_shipping();
test_quote_picks_cheapest_correios();
test_kit_component_stock_counts_kits();
test_fingerprint_changes_with_price();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
