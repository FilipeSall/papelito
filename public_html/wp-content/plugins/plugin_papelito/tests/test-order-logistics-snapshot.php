<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Persistência do LogisticsSnapshot no pedido.
 *
 * O pedido guardava zero dado físico: a pré-postagem re-derivaria a caixa de
 * dado mutável. Aqui o pedido passa a registrar a embalagem que foi realmente
 * cotada, marcada com a verificação do fingerprint quando ela é possível.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

const LOGISTICS_TEST_VENDOR_ID         = 4210;
const LOGISTICS_TEST_OTHER_VENDOR_ID   = 4211;
const LOGISTICS_TEST_PRODUCT_ID        = 55120;
const LOGISTICS_TEST_PROFILE_ID        = 71;
const LOGISTICS_TEST_PROFILE_CODE      = 'caixa-media';
const LOGISTICS_TEST_PROFILE_VERSION   = 4;
const LOGISTICS_TEST_DESTINATION_CEP   = '22041001';
const LOGISTICS_TEST_ORIGIN_CEP        = '01310930';
const LOGISTICS_TEST_MERCHANDISE_CENTS = 12500;
const LOGISTICS_TEST_LINE_CENTS        = 9900;
const LOGISTICS_TEST_VERIFIED          = 'verified';
const LOGISTICS_TEST_MISMATCH          = 'mismatch';
const LOGISTICS_TEST_NOT_APPLICABLE    = 'not_applicable';
const LOGISTICS_TEST_CORREIOS_CODE     = '03298';
const LOGISTICS_TEST_SALT              = 'salt-de-logistica-sintetico';
const LOGISTICS_TEST_QUOTED_AT         = '2026-09-17 12:00:00';
const LOGISTICS_TEST_SNAPSHOT_META     = '_papelito_logistics_snapshot';
const LOGISTICS_TEST_HASH_META         = '_papelito_logistics_physical_hash';
const LOGISTICS_TEST_MISMATCH_ACTION   = 'papelito_logistics_snapshot_mismatch';
const LOGISTICS_TEST_USERNAME          = 'usuario-braspress-sintetico';
const LOGISTICS_TEST_PASSWORD          = 'senha-braspress-sintetica';
const LOGISTICS_TEST_CPF               = '39053344705';
const LOGISTICS_TEST_ACCENTED          = 'Endereço de expedição — Anexo B';

class WP_Error {
	/**
	 * Cria um fixture tipado de erro do WordPress.
	 *
	 * @param string              $code Código do erro.
	 * @param string              $message Mensagem do erro.
	 * @param array<string,mixed> $data Dados do erro.
	 */
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}

	/**
	 * Retorna o código do erro fixture.
	 *
	 * @return string Código do erro.
	 */
	public function get_error_code(): string {
		return $this->code;
	}
}

/** Identifica um fixture de erro do WordPress. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

/** Sanitiza uma chave sintética. */
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }

/** Sanitiza um texto sintético. */
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }

/** Converte um valor sintético em inteiro não negativo. */
function absint( mixed $value ): int { return abs( (int) $value ); }

/** Codifica um payload sintético do WordPress. */
function wp_json_encode( mixed $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }

/** Devolve a chave HMAC estável somente para o teste local. */
function wp_salt( string $scheme = 'auth' ): string { return LOGISTICS_TEST_SALT . $scheme; }

/** Devolve o instante sintético da cotação. */
function current_time( mixed $type = '', mixed $gmt = false ): string { return LOGISTICS_TEST_QUOTED_AT; }

/** Aplica barras como a API de meta do WordPress espera receber. */
function wp_slash( mixed $value ): mixed { return is_string( $value ) ? addslashes( $value ) : $value; }

/** Remove as barras exatamente como a API de meta do WordPress faz ao gravar. */
function wp_unslash( mixed $value ): mixed { return is_string( $value ) ? stripslashes( $value ) : $value; }

/** Registra as actions disparadas para inspeção do teste. */
function do_action( mixed ...$args ): bool {
	$GLOBALS['logistics_test_actions'][] = array(
		'hook' => (string) ( $args[0] ?? '' ),
		'args' => array_slice( $args, 1 ),
	);

	return true;
}

/** Stub de registro de action não relacionada ao seam testado. */
function add_action( mixed ...$args ): bool { return true; }

/** Stub de registro de filtro não relacionado ao seam testado. */
function add_filter( mixed ...$args ): bool { return true; }

/** Devolve o valor original de um filtro não instalado. */
function apply_filters( mixed $hook, mixed $value ): mixed { return $value; }

/** Stub de registro de rota não relacionada ao seam testado. */
function register_rest_route( mixed ...$args ): bool { return true; }

/** Devolve um nome sintético de vendor. */
function get_user_meta( mixed $user_id, mixed $key, mixed $single ): string { return 'Vendor Centro'; }

/** Nenhum usuário real participa do teste. */
function get_userdata( mixed $user_id ): ?object { return null; }

/** Converte centímetros da fixture para milímetros, como o WooCommerce. */
function wc_get_dimension( mixed $value, string $to ): float { return 'mm' === $to ? (float) $value * 10 : (float) $value; }

/** Converte quilogramas da fixture para gramas, como o WooCommerce. */
function wc_get_weight( mixed $value, string $to ): float { return 'g' === $to ? (float) $value * 1000 : (float) $value; }

/** Devolve o produto sintético do catálogo do teste. */
function wc_get_product( mixed $product_id ): ?object { return $GLOBALS['logistics_test_products'][ (int) $product_id ] ?? null; }

/**
 * Produto WooCommerce sintético com atributos físicos controláveis.
 */
class Logistics_Test_Product {
	/**
	 * Cria um produto com peso e dimensões fixos.
	 *
	 * @param string $weight Peso na unidade configurada.
	 * @param string $length Comprimento na unidade configurada.
	 * @param string $width Largura na unidade configurada.
	 * @param string $height Altura na unidade configurada.
	 */
	public function __construct( private string $weight, private string $length, private string $width, private string $height ) {}

	/**
	 * Retorna o peso do produto.
	 *
	 * @return string Peso.
	 */
	public function get_weight(): string { return $this->weight; }

	/**
	 * Retorna o comprimento do produto.
	 *
	 * @return string Comprimento.
	 */
	public function get_length(): string { return $this->length; }

	/**
	 * Retorna a largura do produto.
	 *
	 * @return string Largura.
	 */
	public function get_width(): string { return $this->width; }

	/**
	 * Retorna a altura do produto.
	 *
	 * @return string Altura.
	 */
	public function get_height(): string { return $this->height; }
}

/**
 * Banco falso que devolve os perfis e regras de embalagem do teste.
 */
class Logistics_Test_WPDB {
	public string $prefix = 'wp_';

	/**
	 * Marca a consulta como preparada sem executar SQL.
	 *
	 * @param string $query Consulta SQL.
	 * @param mixed  ...$args Valores da consulta.
	 * @return string Consulta marcada.
	 */
	public function prepare( string $query, mixed ...$args ): string { return $query; }

	/**
	 * Retorna a fixture correspondente à tabela solicitada.
	 *
	 * @param string $query Consulta preparada.
	 * @param mixed  $output Formato pedido pelo WordPress.
	 * @return array<int,array<string,mixed>> Linhas da fixture.
	 */
	public function get_results( string $query, mixed $output = null ): array {
		return str_contains( $query, 'packaging_profiles' ) ? $GLOBALS['logistics_test_profiles'] : $GLOBALS['logistics_test_rules'];
	}
}

/**
 * Pedido WooCommerce sintético que aplica `wp_unslash` como a API real de meta.
 */
class Logistics_Test_Order {
	/** @var array<string,mixed> */
	private array $meta = array();

	/**
	 * Grava uma meta desfazendo as barras, como o WordPress faz.
	 *
	 * @param string $key Chave da meta.
	 * @param mixed  $value Valor recebido já com barras.
	 * @return void
	 */
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = wp_unslash( $value ); }

	/**
	 * Lê uma meta já gravada.
	 *
	 * @param string $key Chave da meta.
	 * @param bool   $single Compatibilidade com a assinatura do WooCommerce.
	 * @return mixed Valor gravado ou string vazia.
	 */
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }

	/**
	 * Informa se alguma meta foi gravada.
	 *
	 * @return bool Se o pedido recebeu meta.
	 */
	public function has_meta(): bool { return array() !== $this->meta; }
}

/** Normaliza um CEP sintético. */
function papelito_shipping_normalize_cep( mixed $value ): string { return (string) preg_replace( '/\D+/', '', (string) $value ); }

/** Declara que o vendor do teste cobre o CEP pedido. */
function papelito_matching_vendor_ids( int $cep ): array { return array( LOGISTICS_TEST_VENDOR_ID ); }

/** Devolve o envelope bruto dos Correios configurado pelo cenário. */
function papelito_correios_quote( int $vendor_id, string $destination_cep, array $items ): mixed { return $GLOBALS['logistics_test_correios_quote']; }

/** Devolve o pacote sintético dos Correios configurado pelo cenário. */
function papelito_shipping_build_package( array $items ): mixed { return $GLOBALS['logistics_test_legacy_package']; }

$GLOBALS['logistics_test_profiles'] = array();
$GLOBALS['logistics_test_rules']    = array();
$GLOBALS['logistics_test_products'] = array();
$GLOBALS['logistics_test_actions']  = array();
$GLOBALS['logistics_test_legacy_package'] = array();
$GLOBALS['logistics_test_correios_quote'] = array();
$GLOBALS['wpdb'] = new Logistics_Test_WPDB();
$GLOBALS['logistics_test_products'][ LOGISTICS_TEST_PRODUCT_ID ] = new Logistics_Test_Product( '0.850', '10', '8', '5' );

require_once dirname( __DIR__ ) . '/includes/packaging.php';
require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';
require_once dirname( __DIR__ ) . '/includes/order_routing.php';

$failures = 0;

/** Confere um comportamento da persistência do snapshot logístico. */
function logistics_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Monta a linha de perfil de caixa usada pelo caminho Braspress. */
function logistics_test_profile( int $length_mm = 400, int $width_mm = 300, int $height_mm = 200 ): array {
	return array(
		'id'            => LOGISTICS_TEST_PROFILE_ID,
		'vendor_id'     => LOGISTICS_TEST_VENDOR_ID,
		'code'          => LOGISTICS_TEST_PROFILE_CODE,
		'label'         => LOGISTICS_TEST_PROFILE_CODE,
		'length_mm'     => $length_mm,
		'width_mm'      => $width_mm,
		'height_mm'     => $height_mm,
		'tare_weight_g' => 250,
		'max_payload_g' => 30000,
		'source'        => 'custom',
		'active'        => 1,
		'version'       => LOGISTICS_TEST_PROFILE_VERSION,
	);
}

/** Monta o item de carrinho usado nos cenários. */
function logistics_test_items(): array {
	return array(
		array(
			'product_id'           => LOGISTICS_TEST_PRODUCT_ID,
			'qty'                  => 1,
			'declared_value_cents' => LOGISTICS_TEST_LINE_CENTS,
		),
	);
}

/** Monta o contexto autoritativo que acompanha a cotação. */
function logistics_test_context(): array {
	return array(
		'destination_cep'         => LOGISTICS_TEST_DESTINATION_CEP,
		'origin_cep'              => LOGISTICS_TEST_ORIGIN_CEP,
		'merchandise_value_cents' => LOGISTICS_TEST_MERCHANDISE_CENTS,
	);
}

/**
 * Monta o pacote sintético dos Correios, com peso em GRAMAS e dimensão em cm.
 *
 * `papelito_shipping_add_product_to_package()` acumula com `wc_get_weight(..., 'g')`
 * e `PAPELITO_SHIPPING_MAX_WEIGHT_G` confirma a unidade: tratar isso como quilo
 * gravaria um snapshot mil vezes mais pesado.
 */
function logistics_test_legacy_package( string $source = 'legacy_synthetic' ): array {
	return array(
		'weight'             => 850.0,
		'length'             => 16.0,
		'width'              => 11.0,
		'height'             => 5.0,
		'value'              => 125.0,
		'measurement_source' => $source,
	);
}

/** Monta a opção normalizada de um provider a partir da embalagem informada. */
function logistics_test_option( string $provider, string $service_code, string $physical_hash ): array {
	$option = papelito_shipping_normalize_provider_option(
		$provider,
		array(
			'code'          => $service_code,
			'service'       => $provider,
			'name'          => $provider,
			'price'         => 32.9,
			'delivery_time' => 5,
			'physical_hash' => $physical_hash,
		),
		LOGISTICS_TEST_QUOTED_AT,
		null
	);

	return is_array( $option ) ? $option : array();
}

/** Reinicia as actions observadas entre cenários. */
function logistics_test_reset_actions(): void { $GLOBALS['logistics_test_actions'] = array(); }

/** Informa se a action de divergência foi disparada no cenário corrente. */
function logistics_test_mismatch_fired(): bool {
	foreach ( $GLOBALS['logistics_test_actions'] as $action ) {
		if ( LOGISTICS_TEST_MISMATCH_ACTION === $action['hook'] ) {
			return true;
		}
	}

	return false;
}

/** Lê o snapshot gravado no pedido de volta para array. */
function logistics_test_stored_snapshot( Logistics_Test_Order $order ): mixed {
	return json_decode( (string) $order->get_meta( LOGISTICS_TEST_SNAPSHOT_META ), true );
}

echo "Scenario 1: conversão do pacote legado preserva grama e converte centímetro\n";
$legacy = papelito_packaging_legacy_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_legacy_package(), logistics_test_context() );
logistics_assert( 'converte o pacote legado em snapshot', is_array( $legacy ) );
logistics_assert( 'centímetro vira milímetro', is_array( $legacy ) && 160 === $legacy['packages'][0]['length_mm'] && 110 === $legacy['packages'][0]['width_mm'] && 50 === $legacy['packages'][0]['height_mm'] );
logistics_assert( 'grama continua grama', is_array( $legacy ) && 850 === $legacy['packages'][0]['weight_g'] );
logistics_assert( 'totais são coerentes com packages[]', is_array( $legacy ) && 1 === $legacy['total_volumes'] && 850 === $legacy['total_weight_g'] && 1 === $legacy['packages'][0]['count'] );
logistics_assert( 'o snapshot legado é da versão canônica', is_array( $legacy ) && PAPELITO_PACKAGING_SNAPSHOT_SCHEMA_VERSION === $legacy['schema_version'] );

echo "Scenario 2: pedido Correios grava o snapshot legado\n";
$GLOBALS['logistics_test_legacy_package'] = logistics_test_legacy_package();
logistics_test_reset_actions();
$correios_option   = logistics_test_option( 'correios', LOGISTICS_TEST_CORREIOS_CODE, '' );
$correios_snapshot = papelito_order_routing_logistics_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items(), $correios_option, logistics_test_context() );
logistics_assert( 'pedido Correios produz snapshot', is_array( $correios_snapshot ) );
logistics_assert( 'origem da medida é legacy_synthetic', is_array( $correios_snapshot ) && 'legacy_synthetic' === $correios_snapshot['measurement_source'] );
logistics_assert( 'approval_version é nulo no legado', is_array( $correios_snapshot ) && null === $correios_snapshot['approval_version'] );
logistics_assert( 'Correios declara que não havia o que verificar', is_array( $correios_snapshot ) && LOGISTICS_TEST_NOT_APPLICABLE === $correios_snapshot['verification'] );
logistics_assert( 'ausência de verificação não dispara alarme de divergência', ! logistics_test_mismatch_fired() );

echo "Scenario 3: pedido Braspress grava o snapshot de perfil verificado\n";
$GLOBALS['logistics_test_profiles'] = array( logistics_test_profile() );
$profile_snapshot = papelito_packaging_profile_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items(), logistics_test_context() );
logistics_assert( 'o caminho de perfil expõe o snapshot', is_array( $profile_snapshot ) && 'profile' === $profile_snapshot['measurement_source'] );
$braspress_option   = logistics_test_option( 'braspress', 'rodoviario', (string) ( $profile_snapshot['physical_hash'] ?? '' ) );
$braspress_snapshot = papelito_order_routing_logistics_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items(), $braspress_option, logistics_test_context() );
logistics_assert( 'pedido Braspress produz snapshot de perfil', is_array( $braspress_snapshot ) && 'profile' === $braspress_snapshot['measurement_source'] );
logistics_assert( 'a versão de aprovação do perfil é preservada', is_array( $braspress_snapshot ) && LOGISTICS_TEST_PROFILE_VERSION === $braspress_snapshot['approval_version'] );
logistics_assert( 'fingerprint da opção aceita confere', is_array( $braspress_snapshot ) && LOGISTICS_TEST_VERIFIED === $braspress_snapshot['verification'] );

echo "Scenario 4: embalagem trocada entre cotar e fechar grava marcada, sem derrubar o pedido\n";
logistics_test_reset_actions();
$stale_option    = logistics_test_option( 'braspress', 'rodoviario', 'hash-de-uma-caixa-que-nao-e-mais-a-atual' );
$stale_snapshot  = papelito_order_routing_logistics_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items(), $stale_option, logistics_test_context() );
logistics_assert( 'divergência ainda produz snapshot', is_array( $stale_snapshot ) );
logistics_assert( 'divergência não vira erro', ! is_wp_error( $stale_snapshot ) );
logistics_assert( 'divergência é marcada como mismatch', is_array( $stale_snapshot ) && LOGISTICS_TEST_MISMATCH === $stale_snapshot['verification'] );
logistics_assert( 'mismatch não se confunde com ausência de verificação', is_array( $stale_snapshot ) && LOGISTICS_TEST_NOT_APPLICABLE !== $stale_snapshot['verification'] );
logistics_assert( 'divergência dispara a action de observabilidade', logistics_test_mismatch_fired() );

echo "Scenario 5: gravação no pedido publica as duas metas\n";
$order = new Logistics_Test_Order();
papelito_order_routing_store_logistics_snapshot( $order, $braspress_snapshot );
$stored = logistics_test_stored_snapshot( $order );
logistics_assert( 'o snapshot gravado volta de json_decode', is_array( $stored ) );
logistics_assert( 'a meta de hash é igual ao physical_hash de dentro do snapshot', is_array( $stored ) && $order->get_meta( LOGISTICS_TEST_HASH_META ) === $stored['physical_hash'] );
logistics_assert( 'o snapshot gravado preserva os pacotes', is_array( $stored ) && 1 === count( $stored['packages'] ) );

echo "Scenario 6: JSON com barra sobrevive ao unslash da API de meta\n";
$accented_order = new Logistics_Test_Order();
$accented       = is_array( $braspress_snapshot ) ? array_merge( $braspress_snapshot, array( 'operator_note' => LOGISTICS_TEST_ACCENTED ) ) : array();
papelito_order_routing_store_logistics_snapshot( $accented_order, $accented );
$accented_stored = logistics_test_stored_snapshot( $accented_order );
logistics_assert( 'valor com acento sobrevive ao round-trip', is_array( $accented_stored ) && LOGISTICS_TEST_ACCENTED === ( $accented_stored['operator_note'] ?? '' ) );

echo "Scenario 7: o hash é determinístico entre dois pedidos da mesma carga\n";
$first_snapshot  = papelito_packaging_profile_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items(), logistics_test_context() );
$second_snapshot = papelito_packaging_profile_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items(), logistics_test_context() );
logistics_assert( 'mesma carga produz o mesmo physical_hash', is_array( $first_snapshot ) && is_array( $second_snapshot ) && $first_snapshot['physical_hash'] === $second_snapshot['physical_hash'] );
$other_vendor_snapshot = papelito_packaging_profile_snapshot( LOGISTICS_TEST_OTHER_VENDOR_ID, logistics_test_items(), logistics_test_context() );
logistics_assert( 'vendor diferente produz hash diferente', is_array( $other_vendor_snapshot ) && $first_snapshot['physical_hash'] !== $other_vendor_snapshot['physical_hash'] );

echo "Scenario 8: o snapshot não carrega credencial nem dado pessoal\n";
$serialized = wp_json_encode( array( $braspress_snapshot, $correios_snapshot ) );
logistics_assert( 'sem usuário, senha ou CPF no snapshot', ! str_contains( $serialized, LOGISTICS_TEST_USERNAME ) && ! str_contains( $serialized, LOGISTICS_TEST_PASSWORD ) && ! str_contains( $serialized, LOGISTICS_TEST_CPF ) );
logistics_assert( 'sem envelope de segredo no snapshot', ! str_contains( $serialized, 'credentials' ) && ! str_contains( $serialized, 'password' ) );

echo "Scenario 9: pedido sem embalagem resolvível não inventa snapshot\n";
$GLOBALS['logistics_test_profiles'] = array();
$empty_snapshot = papelito_order_routing_logistics_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items(), $braspress_option, logistics_test_context() );
logistics_assert( 'sem perfil o pedido Braspress não grava snapshot', null === $empty_snapshot );
$empty_order = new Logistics_Test_Order();
papelito_order_routing_store_logistics_snapshot( $empty_order, $empty_snapshot );
logistics_assert( 'snapshot nulo não grava meta nenhuma', ! $empty_order->has_meta() );

echo "Scenario 10: o valor mercantil vem do contexto precificado, não da reconstrução\n";
$GLOBALS['logistics_test_profiles'] = array( logistics_test_profile() );
$with_context = papelito_packaging_profile_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items(), logistics_test_context() );
logistics_assert( 'contexto informado vence a soma das linhas', is_array( $with_context ) && LOGISTICS_TEST_MERCHANDISE_CENTS === $with_context['merchandise_value_cents'] );
$without_context = papelito_packaging_profile_snapshot( LOGISTICS_TEST_VENDOR_ID, logistics_test_items() );
logistics_assert( 'sem contexto o valor cai para as linhas', is_array( $without_context ) && LOGISTICS_TEST_LINE_CENTS === $without_context['merchandise_value_cents'] );
logistics_assert( 'a fonte do valor não altera o hash físico', is_array( $with_context ) && is_array( $without_context ) && $with_context['physical_hash'] === $without_context['physical_hash'] );

echo "Scenario 11: o caminho do pedido sempre entrega o quote_context ao produtor\n";
$GLOBALS['logistics_test_legacy_package'] = logistics_test_legacy_package();
$correios_raw = array(
	'code'          => LOGISTICS_TEST_CORREIOS_CODE,
	'service'       => 'PAC',
	'name'          => 'PAC',
	'price'         => 20.44,
	'delivery_time' => 6,
);
$GLOBALS['logistics_test_correios_quote'] = array(
	'origin_cep'      => LOGISTICS_TEST_ORIGIN_CEP,
	'destination_cep' => LOGISTICS_TEST_DESTINATION_CEP,
	'vendor_id'       => LOGISTICS_TEST_VENDOR_ID,
	'options'         => array( $correios_raw ),
);
$quoted_correios = papelito_shipping_normalize_provider_option( 'correios', $correios_raw, LOGISTICS_TEST_QUOTED_AT, null );
$checkout_payload = array(
	'shipping' => array(
		'destination_cep'               => LOGISTICS_TEST_DESTINATION_CEP,
		'selected_option_key'           => $quoted_correios['option_key'],
		'expected_fingerprint'          => $quoted_correios['fingerprint'],
		'expected_customer_price_cents' => $quoted_correios['customer_price_cents'],
		'expected_delivery_time'        => $quoted_correios['delivery_time'],
		'expected_expires_at'           => $quoted_correios['expires_at'],
	),
);
$resolved = papelito_order_routing_resolve_checkout_shipping(
	$checkout_payload,
	array( 'zip_code' => LOGISTICS_TEST_DESTINATION_CEP ),
	LOGISTICS_TEST_VENDOR_ID,
	array( array( 'product_id' => LOGISTICS_TEST_PRODUCT_ID, 'qty' => 1, 'total_cents' => LOGISTICS_TEST_LINE_CENTS ) ),
	array( 'merchandise_value_cents' => LOGISTICS_TEST_MERCHANDISE_CENTS )
);
logistics_assert( 'o checkout resolve o frete sem erro', is_array( $resolved ) && ! is_wp_error( $resolved ) );
$routed_snapshot = is_array( $resolved ) ? ( $resolved['logistics_snapshot'] ?? null ) : null;
logistics_assert( 'o checkout devolve o snapshot junto da opção', is_array( $routed_snapshot ) );
logistics_assert( 'o quote_context chegou ao produtor pelo caminho do pedido', is_array( $routed_snapshot ) && LOGISTICS_TEST_MERCHANDISE_CENTS === $routed_snapshot['merchandise_value_cents'] );
logistics_assert( 'o destino resolvido chegou ao snapshot', is_array( $routed_snapshot ) && LOGISTICS_TEST_DESTINATION_CEP === $routed_snapshot['destination_cep'] );
logistics_assert( 'pedido Correios do caminho real declara not_applicable', is_array( $routed_snapshot ) && LOGISTICS_TEST_NOT_APPLICABLE === $routed_snapshot['verification'] );

if ( $failures > 0 ) {
	echo "FAILED: {$failures}\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
