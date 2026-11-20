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
	public function get_catalog_visibility( string $context = 'view' ): string { return in_array( $this->id, $GLOBALS['quote_hidden'], true ) ? 'hidden' : 'visible'; }
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
$GLOBALS['quote_hidden']   = array();
$GLOBALS['quote_orphans']  = array();
$GLOBALS['quote_packages'] = array();
$GLOBALS['quote_setup']    = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function wp_salt( string $scheme = 'auth' ): string { return 'sal-de-teste'; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['quote_env'][ $key ] ?? $default; }
function papelito_kit_is_product( int $product_id ): bool { return false; }
function get_option( string $name, mixed $default_value = false ): mixed { return $GLOBALS['quote_options'][ $name ] ?? $default_value; }
function papelito_product_get_category( mixed $product_id ): ?array {
	return in_array( (int) $product_id, $GLOBALS['quote_orphans'], true ) ? null : array( 'id' => 1, 'slug' => 'sedas', 'name' => 'Sedas' );
}
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

function papelito_shipping_build_packages( array $items ): array|WP_Error { return $GLOBALS['quote_packages']; }
function get_user_meta( int $user_id, string $key, bool $single = false ): string { return $GLOBALS['quote_setup']['origin_cep']; }
function papelito_shipping_get_vendor( int $vendor_id ): bool|WP_Error { return $GLOBALS['quote_setup']['vendor_ok'] ? true : new WP_Error( 'papelito_shipping_vendor_invalid' ); }
function papelito_correios_credentials(): array|WP_Error { return $GLOBALS['quote_setup']['credentials_ok'] ? array() : new WP_Error( 'papelito_correios_credentials_missing' ); }
function papelito_shipping_correios_is_regular( int $vendor_id ): bool { return $GLOBALS['quote_setup']['correios_on']; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_config.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_pricing.php';
require_once dirname( __DIR__ ) . '/includes/event_sales_prices.php';
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
	$GLOBALS['quote_hidden']   = array();
	$GLOBALS['quote_orphans']  = array();
	$GLOBALS['quote_options']  = array();
	$GLOBALS['quote_packages'] = array( array( 'height' => 50.0 ) );
	$GLOBALS['quote_setup']    = array(
		'origin_cep'     => '70040010',
		'vendor_ok'      => true,
		'credentials_ok' => true,
		'correios_on'    => true,
	);
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
	event_quote_assert( 'itens com desconto e kit', 156268 - 15627 + 2 * 65595 === $quote['itemsCents'] );
	event_quote_assert( 'acima de R$ 500 o frete é abatido do total', $quote['itemsCents'] === $quote['totalCents'] );
	event_quote_assert( 'modalidade guarda preço cheio e abatimento', 2990 === $quote['shipping']['priceCents'] && 2990 === $quote['shipping']['discountCents'] );
	event_quote_assert( 'o abatimento vai na modalidade crua, pro pedido abater', 2990 === (int) round( $quote['shipping']['raw']['discount'] * 100 ) );
	event_quote_assert( 'nível vem da base de R$ 3.106,08', 'lenda' === $quote['level']['key'] && 310608 === $quote['baseCents'] && 22 === $quote['units'] );
	event_quote_assert( 'fingerprint presente', 64 === strlen( $quote['quoteFingerprint'] ) );
	event_quote_assert( 'componentes do kit com preço cheio e do kit', array( 'product_id' => 101, 'price_cents' => 18020, 'unit_cents' => 15317 ) === $quote['kitComponents'][0] && 4 === count( $quote['kitComponents'] ) );

	$call = $GLOBALS['quote_calls'][0];
	event_quote_assert( 'cota com o vendor do evento e o CEP em dígitos', 2163 === $call[0] && '01001000' === $call[1] );
	$declared = array_column( $call[2], 'declared_value_cents', 'product_id' );
	event_quote_assert( 'valor declarado do avulso é o total da linha', 140641 === $declared[ EVENT_QUOTE_TEST_LOOSE ] );
	event_quote_assert( 'componente do kit entra com o preço do kit', 2 * 15317 === $declared[101] );
	event_quote_assert( 'destinatário vai no contexto', EVENT_QUOTE_TEST_CNPJ === $call[3]['recipient_cnpj'] );
}

function test_quote_picks_cheapest_correios(): void {
	event_quote_fixture();
	$quote = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 3 ) ), 0 ) );
	event_quote_assert( 'escolhe o PAC, ignorando a Braspress mais barata', 'correios:pac' === $quote['shipping']['optionKey'] && 2990 === $quote['shipping']['priceCents'] );
	event_quote_assert( 'abaixo de R$ 500 o frete entra no total', 0 === $quote['shipping']['discountCents'] && $quote['itemsCents'] + 2990 === $quote['totalCents'] );

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

function test_event_prices_drive_quote(): void {
	event_quote_fixture();
	$input = event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 3 ) ), 0 );
	$site  = papelito_event_sales_quote( $input );

	$GLOBALS['quote_options'] = array(
		'papelito_event_sales_prices' => array(
			'prices'      => array( EVENT_QUOTE_TEST_LOOSE => 10000 ),
			'kit_pct_off' => 20,
		),
	);
	$event = papelito_event_sales_quote( $input );
	event_quote_assert( 'cotação cobra o preço próprio do evento', 30000 === $event['itemsCents'] );
	event_quote_assert( 'preço próprio muda o fingerprint', $site['quoteFingerprint'] !== $event['quoteFingerprint'] );

	$kit = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 6 ) ), 1 ) );
	event_quote_assert( 'kit usa o desconto configurado', 14416 === $kit['kitComponents'][0]['unit_cents'] && 20 === $kit['kitPctOff'] );
}

function test_products_out_of_catalog_are_refused(): void {
	event_quote_fixture();
	$input                    = event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 3 ) ), 0 );
	$GLOBALS['quote_orphans'] = array( EVENT_QUOTE_TEST_LOOSE );
	$orphan                   = papelito_event_sales_quote( $input );
	event_quote_assert( 'produto sem categoria Papelito não é cotado', $orphan instanceof WP_Error && 'papelito_event_unknown_product' === $orphan->get_error_code() );

	$GLOBALS['quote_orphans'] = array();
	$GLOBALS['quote_hidden']  = array( EVENT_QUOTE_TEST_LOOSE );
	$hidden                   = papelito_event_sales_quote( $input );
	event_quote_assert( 'produto oculto do catálogo não é cotado', $hidden instanceof WP_Error && 'papelito_event_unknown_product' === $hidden->get_error_code() );
}

function test_fingerprint_changes_with_kit_composition(): void {
	event_quote_fixture();
	$input   = event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 14 ) ), 1 );
	$before  = papelito_event_sales_quote( $input );
	$GLOBALS['quote_prices'][105]                         = $GLOBALS['quote_prices'][104];
	$GLOBALS['quote_stock'][105]                          = 50;
	$GLOBALS['quote_env']['PAPELITO_EVENT_SALES_KIT_PRODUCT_IDS'] = '101,102,103,105';
	$swapped = papelito_event_sales_quote( $input );
	event_quote_assert( 'troca de componente com o mesmo preço mantém o total', $before['totalCents'] === $swapped['totalCents'] );
	event_quote_assert( 'troca de componente do kit muda o fingerprint', $before['quoteFingerprint'] !== $swapped['quoteFingerprint'] );
}

function event_quote_big_order(): array|WP_Error {
	return papelito_event_sales_quote( event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 14 ) ), 0 ) );
}

function test_correios_outage_waives_shipping_on_free_shipping_orders(): void {
	event_quote_fixture();
	$GLOBALS['quote_shipping'] = new WP_Error( 'provider_5xx', 'Não foi possível cotar o frete.', array( 'status' => 503 ) );
	$quote = event_quote_big_order();
	event_quote_assert( 'Correios fora do ar com frete grátis não trava a venda', is_array( $quote ) );
	event_quote_assert( 'contingência sai com frete zero', 0 === $quote['shipping']['priceCents'] && 0 === $quote['shipping']['discountCents'] );
	event_quote_assert( 'contingência marca a modalidade', true === $quote['shipping']['waived'] && PAPELITO_EVENT_SALES_WAIVED_SHIPPING === $quote['shipping']['optionKey'] );
	event_quote_assert( 'total da contingência é só o dos itens', $quote['itemsCents'] === $quote['totalCents'] );
	event_quote_assert( 'contingência dá o mesmo fingerprint entre cotação e cobrança', event_quote_big_order()['quoteFingerprint'] === $quote['quoteFingerprint'] );
}

function test_correios_outage_below_free_shipping_still_blocks(): void {
	event_quote_fixture();
	$GLOBALS['quote_shipping'] = new WP_Error( 'timeout', 'Não foi possível cotar o frete.', array( 'status' => 503 ) );
	$quote = papelito_event_sales_quote( event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 1 ) ), 0 ) );
	event_quote_assert( 'abaixo do frete grátis a queda continua travando', $quote instanceof WP_Error && 'papelito_event_shipping_unavailable' === $quote->code );
}

function test_validation_error_is_never_waived(): void {
	event_quote_fixture();
	$GLOBALS['quote_shipping'] = new WP_Error( 'papelito_shipping_product_dimensions_missing', 'Não foi possível cotar o frete.', array( 'status' => 422 ) );
	event_quote_assert( 'erro de cadastro não vira frete grátis', event_quote_big_order() instanceof WP_Error );
}

function test_correios_rejection_is_never_waived(): void {
	foreach ( array( 'validation_error', 'configuration_error' ) as $category ) {
		event_quote_fixture();
		$GLOBALS['quote_shipping'] = new WP_Error( $category, 'Não foi possível cotar o frete.', array( 'status' => 502 ) );
		event_quote_assert( "recusa dos Correios ({$category}) não vira frete grátis", event_quote_big_order() instanceof WP_Error );
	}
}

function test_only_other_providers_counts_as_correios_outage(): void {
	event_quote_fixture();
	$GLOBALS['quote_shipping'] = array( 'options' => array( event_quote_option( 'braspress', 'braspress:rodo', 1000 ) ) );
	$quote = event_quote_big_order();
	event_quote_assert( 'sem opção Correios e com frete grátis a venda segue', is_array( $quote ) && true === $quote['shipping']['waived'] );
}

function test_invalid_cep_is_never_waived(): void {
	event_quote_fixture();
	$GLOBALS['quote_shipping'] = new WP_Error( 'provider_4xx', 'Não foi possível cotar o frete.', array( 'status' => 422 ) );
	$input        = event_quote_input( array( array( 'productId' => EVENT_QUOTE_TEST_LOOSE, 'qty' => 14 ) ), 0 );
	$input['cep'] = '7004';
	event_quote_assert( 'CEP incompleto não vira frete grátis', papelito_event_sales_quote( $input ) instanceof WP_Error );
}

function test_quote_reports_box_count(): void {
	event_quote_fixture();
	$GLOBALS['quote_packages'] = array( array( 'height' => 100.0 ), array( 'height' => 100.0 ), array( 'height' => 20.0 ) );
	$quote = event_quote_big_order();
	event_quote_assert( 'cotação informa quantas caixas', 3 === $quote['shipping']['volumes'] );
	event_quote_assert( 'cotação normal não é contingência', false === $quote['shipping']['waived'] );
	$public = papelito_event_sales_public_quote( $quote );
	event_quote_assert( 'tablet recebe a quantidade de caixas', 3 === $public['shipping']['volumes'] );
	event_quote_assert( 'tablet não recebe as medidas internas', ! isset( $public['shipping']['packages'] ) );
}

test_quote_total_is_items_plus_kit_plus_shipping();
test_correios_outage_waives_shipping_on_free_shipping_orders();
test_correios_outage_below_free_shipping_still_blocks();
test_validation_error_is_never_waived();
test_correios_rejection_is_never_waived();
test_only_other_providers_counts_as_correios_outage();
test_invalid_cep_is_never_waived();
function event_quote_outage_with( string $setup_key, mixed $value ): array|WP_Error {
	event_quote_fixture();
	$GLOBALS['quote_shipping']            = new WP_Error( 'invalid_response', 'Não foi possível cotar o frete.', array( 'status' => 503 ) );
	$GLOBALS['quote_setup'][ $setup_key ] = $value;

	return event_quote_big_order();
}

function test_registration_errors_are_never_waived(): void {
	event_quote_fixture();
	$GLOBALS['quote_shipping'] = new WP_Error( 'invalid_response', 'Não foi possível cotar o frete.', array( 'status' => 503 ) );
	event_quote_assert( 'com cadastro válido a queda vira contingência', is_array( event_quote_big_order() ) );
	event_quote_assert( 'CEP de origem do vendor inválido não vira frete grátis', event_quote_outage_with( 'origin_cep', '7004' ) instanceof WP_Error );
	event_quote_assert( 'vendor inelegível não vira frete grátis', event_quote_outage_with( 'vendor_ok', false ) instanceof WP_Error );
	event_quote_assert( 'credencial dos Correios ausente não vira frete grátis', event_quote_outage_with( 'credentials_ok', false ) instanceof WP_Error );
	event_quote_assert( 'Correios desligado no vendor não vira frete grátis', event_quote_outage_with( 'correios_on', false ) instanceof WP_Error );
}

function test_package_errors_block_before_waiving(): void {
	event_quote_fixture();
	$GLOBALS['quote_shipping'] = new WP_Error( 'provider_5xx', 'Não foi possível cotar o frete.', array( 'status' => 503 ) );
	$GLOBALS['quote_packages'] = new WP_Error( 'papelito_shipping_package_exceeds_limits', 'Não cabe.', array( 'status' => 422, 'limit' => 'dimension' ) );
	$quote = event_quote_big_order();
	event_quote_assert( 'unidade que não cabe numa caixa recusa mesmo com Correios fora do ar', $quote instanceof WP_Error && 'papelito_shipping_package_exceeds_limits' === $quote->code );
	event_quote_assert( 'erro de embalagem recusa antes de chamar os Correios', array() === $GLOBALS['quote_calls'] );
}

test_quote_reports_box_count();
test_registration_errors_are_never_waived();
test_package_errors_block_before_waiving();
test_products_out_of_catalog_are_refused();
test_fingerprint_changes_with_kit_composition();
test_quote_picks_cheapest_correios();
test_kit_component_stock_counts_kits();
test_fingerprint_changes_with_price();
test_event_prices_drive_quote();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
