<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Adaptador físico Braspress — BRASPRESS-003B.
 *
 * Testa leitura de perfis, conversão WooCommerce, escolha de uma caixa,
 * conversão do snapshot e propagação do physical_hash, sem banco ou rede.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

const PACKAGE_ADAPTER_TEST_VENDOR_ID       = 2163;
const PACKAGE_ADAPTER_TEST_PRODUCT_ID      = 1001;
const PACKAGE_ADAPTER_TEST_KIT_PRODUCT_ID  = 2001;
const PACKAGE_ADAPTER_TEST_KIT_ID          = 3001;
const PACKAGE_ADAPTER_TEST_SMALL_ID        = 11;
const PACKAGE_ADAPTER_TEST_LARGE_ID        = 12;
const PACKAGE_ADAPTER_TEST_SMALL_CODE      = 'P';
const PACKAGE_ADAPTER_TEST_LARGE_CODE      = 'M';
const PACKAGE_ADAPTER_TEST_QUOTE_ID        = 'quote-1';
const PACKAGE_ADAPTER_TEST_QUOTE_TIME      = '2026-09-16 12:00:00';
const PACKAGE_ADAPTER_TEST_SALT            = 'package-adapter-test-salt';
const PACKAGE_ADAPTER_TEST_ORIGIN_CEP      = '14700000';
const PACKAGE_ADAPTER_TEST_DESTINATION_CEP = '30110000';
const PACKAGE_ADAPTER_TEST_CNPJ            = '20024291000165';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Erro mínimo compatível com o contrato usado pelos módulos testados.
 */
class Package_Adapter_Test_WP_Error {
	private string $code;

	private string $message;

	/**
	 * Cria um erro observável pelo teste standalone.
	 *
	 * @param string              $code Código do erro.
	 * @param string              $message Mensagem do erro.
	 * @param array<string,mixed> $data Dados do erro.
	 */
	public function __construct( string $code, string $message, array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Retorna o código do erro.
	 *
	 * @return string Código.
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * Retorna a mensagem do erro.
	 *
	 * @return string Mensagem.
	 */
	public function get_error_message(): string {
		return $this->message;
	}

	/**
	 * Retorna dados vazios para o stub de transporte.
	 *
	 * @return array<string,mixed> Dados.
	 */
	public function get_error_data(): array {
		return array();
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class_alias( Package_Adapter_Test_WP_Error::class, 'WP_Error' );
}

/**
 * Produto WooCommerce mínimo para o adaptador.
 */
class Package_Adapter_Test_Product {
	private string $weight;

	private string $length;

	private string $width;

	private string $height;

	/**
	 * Cria um produto com atributos físicos controláveis.
	 *
	 * @param string $weight Peso na unidade configurada pelo WooCommerce.
	 * @param string $length Comprimento na unidade configurada pelo WooCommerce.
	 * @param string $width Largura na unidade configurada pelo WooCommerce.
	 * @param string $height Altura na unidade configurada pelo WooCommerce.
	 */
	public function __construct( string $weight, string $length, string $width, string $height ) {
		$this->weight = $weight;
		$this->length = $length;
		$this->width  = $width;
		$this->height = $height;
	}

	/**
	 * Retorna o peso do produto.
	 *
	 * @return string Peso.
	 */
	public function get_weight(): string {
		return $this->weight;
	}

	/**
	 * Retorna o comprimento do produto.
	 *
	 * @return string Comprimento.
	 */
	public function get_length(): string {
		return $this->length;
	}

	/**
	 * Retorna a largura do produto.
	 *
	 * @return string Largura.
	 */
	public function get_width(): string {
		return $this->width;
	}

	/**
	 * Retorna a altura do produto.
	 *
	 * @return string Altura.
	 */
	public function get_height(): string {
		return $this->height;
	}
}

/**
 * Banco falso que registra prepare e devolve fixtures de embalagem.
 */
class Package_Adapter_Test_WPDB {
	public string $prefix = 'wp_';

	public int $prepare_calls = 0;

	/**
	 * Marca a consulta como preparada sem executar SQL.
	 *
	 * @param string $query Consulta SQL.
	 * @param mixed  ...$args Valores da consulta.
	 * @return string Consulta marcada.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		++$this->prepare_calls;
		return $query;
	}

	/**
	 * Retorna a fixture correspondente à tabela solicitada.
	 *
	 * @param string $query Consulta preparada.
	 * @param mixed  $output Formato pedido pelo WordPress.
	 * @return array<int,array<string,mixed>> Linhas da fixture.
	 */
	public function get_results( string $query, mixed $output = null ): array {
		if ( str_contains( $query, 'packaging_profiles' ) ) {
			return $GLOBALS['package_adapter_test_profiles'];
		}

		return $GLOBALS['package_adapter_test_rules'];
	}
}

$GLOBALS['package_adapter_test_profiles'] = array();
$GLOBALS['package_adapter_test_rules']    = array();
$GLOBALS['package_adapter_test_products'] = array();
$GLOBALS['package_adapter_test_kits']     = array();
$GLOBALS['package_adapter_test_kit_items'] = array();
$GLOBALS['package_adapter_test_kit_merchandise'] = array();
$GLOBALS['package_adapter_test_filters']  = array();
$GLOBALS['package_adapter_test_transients'] = array();
$GLOBALS['package_adapter_test_http_calls'] = 0;
$GLOBALS['wpdb'] = new Package_Adapter_Test_WPDB();

/**
 * Testa se um valor é um erro WordPress.
 *
 * @param mixed $value Valor observado.
 * @return bool Se é erro.
 */
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

/**
 * Sanitiza uma chave no formato usado pelo WordPress.
 *
 * @param mixed $value Valor cru.
 * @return string Chave sanitizada.
 */
function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
}

/**
 * Sanitiza texto sem dependência do WordPress.
 *
 * @param mixed $value Valor cru.
 * @return string Texto.
 */
function sanitize_text_field( mixed $value ): string {
	return trim( strip_tags( (string) $value ) );
}

/**
 * Converte um ID para inteiro absoluto no stub WooCommerce.
 *
 * @param mixed $value Valor cru.
 * @return int ID.
 */
function absint( mixed $value ): int {
	return abs( (int) $value );
}

/**
 * Codifica JSON com a assinatura aceita pelo WordPress.
 *
 * @param mixed $value Valor a codificar.
 * @param int   $flags Flags JSON.
 * @param int   $depth Profundidade máxima.
 * @return string JSON.
 */
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string {
	return (string) json_encode( $value, $flags, $depth );
}

/**
 * Fornece o salt fixo do teste.
 *
 * @param mixed $scheme Esquema ignorado pelo stub.
 * @return string Salt.
 */
function wp_salt( mixed $scheme = '' ): string {
	return PACKAGE_ADAPTER_TEST_SALT;
}

/**
 * Registra callback de filtro para execução pelo teste.
 *
 * @param mixed ...$args Argumentos do registro.
 * @return bool Registro aceito.
 */
function add_filter( mixed ...$args ): bool {
	$GLOBALS['package_adapter_test_filters'][ (string) $args[0] ] = array(
		'callback' => $args[1],
		'accepted' => (int) ( $args[3] ?? 1 ),
	);

	return true;
}

/**
 * Executa o callback de filtro registrado.
 *
 * @param mixed $hook Hook.
 * @param mixed $value Valor inicial.
 * @param mixed ...$args Argumentos adicionais.
 * @return mixed Valor filtrado.
 */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed {
	$filter = $GLOBALS['package_adapter_test_filters'][ (string) $hook ] ?? null;
	if ( ! is_array( $filter ) || ! is_callable( $filter['callback'] ?? null ) ) {
		return $value;
	}

	return call_user_func( $filter['callback'], $value, ...$args );
}

/**
 * Obtém um produto da fixture WooCommerce.
 *
 * @param int $product_id ID do produto.
 * @return Package_Adapter_Test_Product|null Produto ou nulo.
 */
function wc_get_product( int $product_id ): ?Package_Adapter_Test_Product {
	return $GLOBALS['package_adapter_test_products'][ $product_id ] ?? null;
}

/**
 * Converte centímetros da fixture para milímetros.
 *
 * @param mixed  $value Valor em centímetros.
 * @param string $to Unidade de destino.
 * @return float Valor convertido.
 */
function wc_get_dimension( mixed $value, string $to ): float {
	return 'mm' === $to ? (float) $value * 10 : (float) $value;
}

/**
 * Converte quilogramas da fixture para gramas.
 *
 * @param mixed  $value Valor em quilogramas.
 * @param string $to Unidade de destino.
 * @return float Valor convertido.
 */
function wc_get_weight( mixed $value, string $to ): float {
	return 'g' === $to ? (float) $value * 1000 : (float) $value;
}

/**
 * Retorna o registro de Kit da fixture.
 *
 * @param int $product_id Produto comercial do Kit.
 * @return array<string,mixed>|null Linha do Kit.
 */
function papelito_kit_get_by_product( int $product_id ): ?array {
	return $GLOBALS['package_adapter_test_kits'][ $product_id ] ?? null;
}

/**
 * Retorna componentes da fixture do Kit.
 *
 * @param int $kit_id ID da entidade Kit.
 * @return array<int,array<string,mixed>> Componentes.
 */
function papelito_kit_items( int $kit_id ): array {
	return $GLOBALS['package_adapter_test_kit_items'][ $kit_id ] ?? array();
}

/**
 * Retorna brindes da fixture do Kit.
 *
 * @param int $kit_id ID da entidade Kit.
 * @return array<int,array<string,mixed>> Brindes.
 */
function papelito_kit_merchandise( int $kit_id ): array {
	return $GLOBALS['package_adapter_test_kit_merchandise'][ $kit_id ] ?? array();
}

/**
 * Retorna uma cotação HTTP falsa sem abrir conexão.
 *
 * @param string              $url URL recebida.
 * @param array<string,mixed> $args Argumentos HTTP.
 * @return array<string,mixed> Resposta HTTP.
 */
function wp_remote_request( string $url, array $args ): array {
	++$GLOBALS['package_adapter_test_http_calls'];

	return array(
		'code' => 200,
		'body' => wp_json_encode(
			array(
				'id'         => PACKAGE_ADAPTER_TEST_QUOTE_ID,
				'totalFrete' => 42.50,
				'prazo'      => 4,
			)
		),
	);
}

/**
 * Lê o status da resposta HTTP falsa.
 *
 * @param array<string,mixed> $response Resposta.
 * @return int Status.
 */
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['code'] ?? 0 );
}

/**
 * Lê o corpo da resposta HTTP falsa.
 *
 * @param array<string,mixed> $response Resposta.
 * @return string Corpo.
 */
function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

/**
 * Executa action sem produzir efeitos externos.
 *
 * @param mixed ...$args Argumentos da action.
 * @return bool Action ignorada.
 */
function do_action( mixed ...$args ): bool {
	return true;
}

/**
 * Devolve o pacote sem publicar a origem da medida.
 *
 * O emissor real vive em `shipping.php`, que este teste não carrega. A emissão
 * em si é coberta por `test-shipping-measurement-metrics.php`.
 *
 * @param mixed $package Pacote resolvido ou erro.
 * @return mixed Pacote intocado.
 */
function papelito_shipping_notify_package_built( mixed $package ): mixed {
	return $package;
}

/**
 * Obtém transient da fixture sem persistência entre casos.
 *
 * @param string $key Chave.
 * @return mixed Valor ou nulo.
 */
function get_transient( string $key ): mixed {
	return $GLOBALS['package_adapter_test_transients'][ $key ] ?? null;
}

/**
 * Persiste transient somente dentro da fixture.
 *
 * @param string $key Chave.
 * @param mixed  $value Valor.
 * @param int    $expiration Expiração.
 * @return bool Escrita aceita.
 */
function set_transient( string $key, mixed $value, int $expiration ): bool {
	$GLOBALS['package_adapter_test_transients'][ $key ] = $value;

	return true;
}

/**
 * Completa a configuração mínima esperada pelo payload Braspress.
 *
 * @param array<string,mixed> $config Configuração.
 * @return bool Se todos os campos estão preenchidos.
 */
function papelito_vendor_integration_config_complete( array $config ): bool {
	return ! empty( $config['sender_cnpj'] )
		&& ! empty( $config['origin_cep'] )
		&& ! empty( $config['modal'] )
		&& ! empty( $config['freight_type'] )
		&& ! empty( $config['tracking_tomador_cnpj'] );
}

/**
 * Normaliza documento na fixture.
 *
 * @param mixed $value Documento.
 * @return string Dígitos.
 */
function papelito_vendor_integration_normalize_document( mixed $value ): string {
	return preg_replace( '/\D+/', '', (string) $value );
}

/**
 * Normaliza CEP na fixture.
 *
 * @param mixed $value CEP.
 * @return string Dígitos.
 */
function papelito_vendor_integration_normalize_cep( mixed $value ): string {
	return preg_replace( '/\D+/', '', (string) $value );
}

/**
 * Gera fingerprint determinístico para o cache Braspress falso.
 *
 * @param string $value Identidade serializada.
 * @return string Hash.
 */
function papelito_shipping_cache_fingerprint( string $value ): string {
	return hash( 'sha256', $value );
}

const PAPELITO_VENDOR_INTEGRATION_ACTIVE          = 'active';
const PAPELITO_VENDOR_INTEGRATION_INVALID         = 'invalid_credentials';
const PAPELITO_VENDOR_INTEGRATION_BLOCKED         = 'provider_blocked';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED  = 'applied';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE    = 'stale';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED   = 'failed';

require_once dirname( __DIR__ ) . '/includes/packaging.php';
require_once dirname( __DIR__ ) . '/includes/braspress.php';
require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';

$failures = 0;

/**
 * Registra uma asserção do teste standalone.
 *
 * @param string $label Nome da regra.
 * @param bool   $condition Resultado esperado.
 * @return void
 */
function package_adapter_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/**
 * Cria uma fixture de perfil de caixa.
 *
 * @param int         $id Identificador.
 * @param string      $code Código.
 * @param int         $length Comprimento em mm.
 * @param int         $width Largura em mm.
 * @param int         $height Altura em mm.
 * @param int         $tare Tara em g.
 * @param int|null    $max_payload Carga máxima em g.
 * @param int         $active Atividade.
 * @param int         $version Versão.
 * @return array<string,mixed> Perfil.
 */
function package_adapter_profile( int $id, string $code, int $length, int $width, int $height, int $tare, ?int $max_payload, int $active = 1, int $version = 1 ): array {
	return array(
		'id'             => $id,
		'vendor_id'      => PACKAGE_ADAPTER_TEST_VENDOR_ID,
		'code'           => $code,
		'label'          => $code,
		'length_mm'      => $length,
		'width_mm'       => $width,
		'height_mm'      => $height,
		'tare_weight_g'  => $tare,
		'max_payload_g'  => $max_payload,
		'source'         => 'custom',
		'active'         => $active,
		'version'        => $version,
	);
}

/**
 * Cria uma linha de carrinho para o teste.
 *
 * @param int $product_id Produto.
 * @param int $qty Quantidade.
 * @param int $declared_value_cents Valor declarado.
 * @return array<string,int> Linha.
 */
function package_adapter_item( int $product_id, int $qty = 1, int $declared_value_cents = 1250 ): array {
	return array(
		'product_id'          => $product_id,
		'qty'                 => $qty,
		'declared_value_cents' => $declared_value_cents,
	);
}

/**
 * Obtém o pacote pelo callback público do filtro.
 *
 * @param array<int,array<string,int>> $items Itens.
 * @return array<string,mixed>|null Pacote ou nulo.
 */
function package_adapter_filter_package( array $items ): ?array {
	return papelito_packaging_braspress_package_filter( null, PACKAGE_ADAPTER_TEST_VENDOR_ID, $items );
}

$GLOBALS['package_adapter_test_products'][ PACKAGE_ADAPTER_TEST_PRODUCT_ID ] = new Package_Adapter_Test_Product( '0.850', '10', '8', '5' );
$GLOBALS['package_adapter_test_products'][ PACKAGE_ADAPTER_TEST_KIT_PRODUCT_ID ] = new Package_Adapter_Test_Product( '0.400', '1', '1', '1' );
$GLOBALS['package_adapter_test_kits'][ PACKAGE_ADAPTER_TEST_KIT_PRODUCT_ID ] = array(
	'id'             => PACKAGE_ADAPTER_TEST_KIT_ID,
	'product_id'     => PACKAGE_ADAPTER_TEST_KIT_PRODUCT_ID,
	'package_length' => '15.0',
	'package_width'  => '10.0',
	'package_height' => '5.0',
);
$GLOBALS['package_adapter_test_kit_items'][ PACKAGE_ADAPTER_TEST_KIT_ID ] = array(
	array(
		'product_id' => PACKAGE_ADAPTER_TEST_PRODUCT_ID,
		'quantity'   => 1,
	),
);
$GLOBALS['package_adapter_test_kit_merchandise'][ PACKAGE_ADAPTER_TEST_KIT_ID ] = array();
$GLOBALS['package_adapter_test_profiles'] = array(
	package_adapter_profile( PACKAGE_ADAPTER_TEST_SMALL_ID, PACKAGE_ADAPTER_TEST_SMALL_CODE, 200, 150, 100, 120, 5000, 1, 3 ),
	package_adapter_profile( PACKAGE_ADAPTER_TEST_LARGE_ID, PACKAGE_ADAPTER_TEST_LARGE_CODE, 400, 300, 200, 250, 30000, 1, 4 ),
	package_adapter_profile( 99, 'INACTIVE', 1000, 1000, 1000, 100, 100000, 0, 9 ),
);
$GLOBALS['package_adapter_test_rules'] = array();

echo "Scenario 1: conjunto cabível monta pacote Braspress com tara e versão\n";
$package = package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) );
package_adapter_assert( 'pacote existe', is_array( $package ) );
package_adapter_assert( 'tara entra no peso em kg', is_array( $package ) && 0.97 === $package['weight_kg'] );
package_adapter_assert( 'tem um volume', is_array( $package ) && 1 === $package['volumes'] );
package_adapter_assert( 'versao vem do perfil', is_array( $package ) && 3 === $package['approval_version'] );
package_adapter_assert( 'origem de medida é profile', is_array( $package ) && 'profile' === $package['measurement_source'] );
$filtered_package = papelito_shipping_braspress_physical_package( PACKAGE_ADAPTER_TEST_VENDOR_ID, array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) );
package_adapter_assert( 'filtro Braspress devolve pacote sem WP_Error', is_array( $filtered_package ) && ! is_wp_error( $filtered_package ) );

echo "Scenario 2: cubagem é agrupada em metros e passa na validação Braspress\n";
package_adapter_assert( 'há um grupo de cubagem', is_array( $package ) && 1 === count( $package['cubagem'] ) );
package_adapter_assert( 'comprimento exato em metros', is_array( $package ) && 0.2 === $package['cubagem'][0]['length_m'] );
package_adapter_assert( 'largura exata em metros', is_array( $package ) && 0.15 === $package['cubagem'][0]['width_m'] );
package_adapter_assert( 'altura exata em metros', is_array( $package ) && 0.1 === $package['cubagem'][0]['height_m'] );
package_adapter_assert( 'soma dos grupos bate volumes', is_array( $package ) && 1 === $package['cubagem'][0]['volumes'] && $package['volumes'] === $package['cubagem'][0]['volumes'] );
package_adapter_assert( 'pacote passa no contrato Braspress', is_array( $package ) && papelito_braspress_package_is_valid( $package ) );

echo "Scenario 3: override força perfil e ausência de regra deixa cálculo decidir\n";
$GLOBALS['package_adapter_test_rules'] = array(
	array(
		'id'          => 1,
		'vendor_id'   => PACKAGE_ADAPTER_TEST_VENDOR_ID,
		'target_type' => 'product',
		'target_id'   => PACKAGE_ADAPTER_TEST_PRODUCT_ID,
		'min_qty'     => 1,
		'max_qty'     => 5,
		'profile_id'  => PACKAGE_ADAPTER_TEST_LARGE_ID,
		'version'     => 1,
	),
);
$overridden = package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) );
package_adapter_assert( 'override força M', is_array( $overridden ) && 0.4 === $overridden['cubagem'][0]['length_m'] );
$GLOBALS['package_adapter_test_rules'] = array();
$calculated = package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) );
package_adapter_assert( 'sem regra escolhe P', is_array( $calculated ) && 0.2 === $calculated['cubagem'][0]['length_m'] );

echo "Scenario 4: Kit usa dimensoes declaradas do proprio Kit\n";
$kit_package = package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_KIT_PRODUCT_ID ) ) );
$kit_lines = papelito_packaging_items_to_lines( array( package_adapter_item( PACKAGE_ADAPTER_TEST_KIT_PRODUCT_ID ) ) );
package_adapter_assert( 'Kit usa alvo kit', is_array( $kit_lines ) && 'kit' === $kit_lines[0]['target_type'] && PACKAGE_ADAPTER_TEST_KIT_ID === $kit_lines[0]['target_id'] );
package_adapter_assert( 'Kit usa comprimento declarado', is_array( $kit_lines ) && 150 === $kit_lines[0]['length_mm'] );

echo "Scenario 5: vendor sem perfil ativo e perfil inativo nao cotam\n";
$saved_profiles = $GLOBALS['package_adapter_test_profiles'];
$GLOBALS['package_adapter_test_profiles'] = array( package_adapter_profile( 99, 'INACTIVE', 1000, 1000, 1000, 100, 100000, 0, 9 ) );
package_adapter_assert( 'vendor sem perfil ativo devolve nulo', null === package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) ) );
$GLOBALS['package_adapter_test_profiles'] = $saved_profiles;
package_adapter_assert( 'perfil inativo é ignorado', 2 === count( papelito_packaging_profiles_for_vendor( PACKAGE_ADAPTER_TEST_VENDOR_ID ) ) );

echo "Scenario 6: dado fisico ausente ou zero invalida o conjunto inteiro\n";
$GLOBALS['package_adapter_test_products'][ PACKAGE_ADAPTER_TEST_PRODUCT_ID ] = new Package_Adapter_Test_Product( '', '10', '8', '5' );
package_adapter_assert( 'peso ausente devolve nulo', null === package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) ) );
$GLOBALS['package_adapter_test_products'][ PACKAGE_ADAPTER_TEST_PRODUCT_ID ] = new Package_Adapter_Test_Product( '0.850', '', '8', '5' );
package_adapter_assert( 'dimensao ausente devolve nulo', null === package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) ) );
$GLOBALS['package_adapter_test_products'][ PACKAGE_ADAPTER_TEST_PRODUCT_ID ] = new Package_Adapter_Test_Product( '0.850', '0', '8', '5' );
package_adapter_assert( 'dimensao zero devolve nulo', null === package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) ) );
$GLOBALS['package_adapter_test_products'][ PACKAGE_ADAPTER_TEST_PRODUCT_ID ] = new Package_Adapter_Test_Product( '0.850', '10', '8', '5' );

echo "Scenario 7: conjunto maior que a maior caixa nao divide nem inventa caixa\n";
$GLOBALS['package_adapter_test_products'][ PACKAGE_ADAPTER_TEST_PRODUCT_ID ] = new Package_Adapter_Test_Product( '0.850', '50', '8', '5' );
package_adapter_assert( 'carga que nao cabe devolve nulo', null === package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) ) );
$GLOBALS['package_adapter_test_products'][ PACKAGE_ADAPTER_TEST_PRODUCT_ID ] = new Package_Adapter_Test_Product( '0.850', '10', '8', '5' );

echo "Scenario 8: hash fisico é estavel e muda com a caixa\n";
$GLOBALS['package_adapter_test_rules'] = array();
$first_package = package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) );
$second_package = package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) );
package_adapter_assert( 'mesma carga produz hash estavel', is_array( $first_package ) && is_array( $second_package ) && $first_package['physical_hash'] === $second_package['physical_hash'] );
$GLOBALS['package_adapter_test_rules'] = array();
$GLOBALS['package_adapter_test_profiles'] = array( package_adapter_profile( PACKAGE_ADAPTER_TEST_LARGE_ID, PACKAGE_ADAPTER_TEST_LARGE_CODE, 400, 300, 200, 250, 30000, 1, 4 ) );
$different_package = package_adapter_filter_package( array( package_adapter_item( PACKAGE_ADAPTER_TEST_PRODUCT_ID ) ) );
package_adapter_assert( 'caixa diferente muda hash', is_array( $first_package ) && is_array( $different_package ) && $first_package['physical_hash'] !== $different_package['physical_hash'] );

echo "Scenario 9: physical_hash chega a opcao e muda fingerprint com mesmo preco\n";
$quote = is_array( $different_package ) ? papelito_braspress_quote(
	array(
		'vendor_id' => PACKAGE_ADAPTER_TEST_VENDOR_ID,
		'credentials' => array( 'username' => 'user', 'password' => 'pass' ),
		'config' => array(
			'sender_cnpj'           => PACKAGE_ADAPTER_TEST_CNPJ,
			'origin_cep'            => PACKAGE_ADAPTER_TEST_ORIGIN_CEP,
			'modal'                 => 'R',
			'freight_type'          => '1',
			'tracking_tomador_cnpj' => PACKAGE_ADAPTER_TEST_CNPJ,
		),
	),
	PACKAGE_ADAPTER_TEST_CNPJ,
	PACKAGE_ADAPTER_TEST_DESTINATION_CEP,
	$different_package,
	1250
) : null;
package_adapter_assert( 'cotacao carrega physical_hash', is_array( $quote ) && $quote['physical_hash'] === $different_package['physical_hash'] );
$same_price_one = papelito_shipping_normalize_provider_option( 'braspress', (array) $quote, PACKAGE_ADAPTER_TEST_QUOTE_TIME, null );
$same_price_two = papelito_shipping_normalize_provider_option(
	'braspress',
	array_merge( (array) $quote, array( 'physical_hash' => $first_package['physical_hash'] ) ),
	PACKAGE_ADAPTER_TEST_QUOTE_TIME,
	null
);
package_adapter_assert( 'mesmo preco e caixas diferentes mudam fingerprint', is_array( $same_price_one ) && is_array( $same_price_two ) && $same_price_one['customer_price_cents'] === $same_price_two['customer_price_cents'] && $same_price_one['fingerprint'] !== $same_price_two['fingerprint'] );
package_adapter_assert( 'consultas usam prepare', $GLOBALS['wpdb']->prepare_calls >= 10 );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
