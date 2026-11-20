<?php
// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin tests use underscore filenames.
/**
 * Pedido grande dos Correios dividido em várias caixas.
 *
 * O pacote sintético empilha a altura de cada unidade numa caixa só; com 15 a 20 itens ele passava
 * do volume único e a cotação inteira falhava, travando o estande e a loja. Este teste fixa a
 * divisão em volumes, a recusa de uma unidade que não cabe sozinha, o teto de caixas e a soma do
 * preço por serviço com o prazo consultado uma vez só.
 *
 * Usage: php tests/test-shipping-correios-volumes.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

const VOLUMES_TEST_SMALL_PRODUCT = 301;
const VOLUMES_TEST_WIDE_PRODUCT  = 302;
const VOLUMES_TEST_HEAVY_PRODUCT = 303;
const VOLUMES_TEST_EXCEEDS_CODE  = 'papelito_shipping_package_exceeds_limits';
const VOLUMES_TEST_SEDEX_CODE    = '03220';
const VOLUMES_TEST_PAC_CODE      = '03298';
const VOLUMES_TEST_LAST_BOX_CM   = 5.0;

class PapelitoVolumesTestError {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): array { return $this->data; }
}
class_alias( PapelitoVolumesTestError::class, 'WP_Error' );

class PapelitoVolumesTestProduct {
	public function __construct( private float $weight, private float $length, private float $width, private float $height ) {}
	public function get_weight(): float { return $this->weight; }
	public function get_length(): float { return $this->length; }
	public function get_width(): float { return $this->width; }
	public function get_height(): float { return $this->height; }
	public function get_name(): string { return 'Produto de teste'; }
	public function get_price(): float { return 10.0; }
}

$GLOBALS['volumes_requests'] = array();

function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function register_rest_route( mixed ...$args ): bool { return true; }
function apply_filters( mixed $hook, mixed $value ): mixed { return $value; }
function do_action( mixed ...$args ): bool { return true; }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function remove_accents( mixed $value ): string { return (string) $value; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wc_get_weight( mixed $value, mixed $unit ): float { return (float) $value; }
function wc_get_dimension( mixed $value, mixed $unit ): float { return (float) $value; }
function papelito_kit_is_product( int $product_id ): bool { return false; }
function papelito_kit_shipping_items( array $items ): array { return $items; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) $response['code']; }
function wp_remote_retrieve_body( mixed $response ): string { return (string) $response['body']; }
function wp_remote_retrieve_header( mixed ...$args ): string { return ''; }

function wc_get_product( int $product_id ): ?PapelitoVolumesTestProduct {
	return match ( $product_id ) {
		VOLUMES_TEST_SMALL_PRODUCT => new PapelitoVolumesTestProduct( 100.0, 20.0, 15.0, 5.0 ),
		VOLUMES_TEST_WIDE_PRODUCT  => new PapelitoVolumesTestProduct( 100.0, 90.0, 80.0, 35.0 ),
		VOLUMES_TEST_HEAVY_PRODUCT => new PapelitoVolumesTestProduct( 4000.0, 20.0, 15.0, 2.0 ),
		default                    => null,
	};
}

/**
 * Resposta HTTP no formato que os stubs de `wp_remote_retrieve_*` leem.
 *
 * @param int    $code Status HTTP.
 * @param string $body Corpo JSON.
 * @return array{code:int,body:string}
 */
function papelito_volumes_response( int $code, string $body ): array {
	return array(
		'code' => $code,
		'body' => $body,
	);
}

/**
 * Resposta falsa dos Correios: o preço vale o peso do volume em centavos por grama e o SEDEX
 * recusa a caixa de 5 cm de altura, para provar que o serviço sai da lista se falhar em uma caixa.
 */
function wp_safe_remote_get( string $url, array $args = array() ): array {
	$GLOBALS['volumes_requests'][] = $url;
	parse_str( substr( (string) strstr( $url, '?' ), 1 ), $query );

	if ( str_contains( $url, 'prazo/v1' ) ) {
		return papelito_volumes_response( 200, '{"prazoEntrega":5}' );
	}

	if ( str_contains( $url, VOLUMES_TEST_SEDEX_CODE ) && VOLUMES_TEST_LAST_BOX_CM === (float) $query['altura'] ) {
		return papelito_volumes_response( 400, '{"msgs":["recusado"]}' );
	}

	return papelito_volumes_response( 200, sprintf( '{"pcFinal":%s}', (float) $query['psObjeto'] / 100 ) );
}

require_once __DIR__ . '/../includes/shipping.php';

$failures = 0;

/**
 * Registra uma asserção e conta a falha sem interromper os demais cenários.
 *
 * @param string $label     O que está sendo garantido.
 * @param bool   $condition Resultado da conferência.
 * @return void
 */
function papelito_volumes_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

/**
 * Carrinho com um produto só.
 *
 * @param int $product_id Produto.
 * @param int $qty        Quantidade.
 * @return array<int,array{product_id:int,qty:int}>
 */
function papelito_volumes_cart( int $product_id, int $qty ): array {
	return array(
		array(
			'product_id' => $product_id,
			'qty'        => $qty,
		),
	);
}

/**
 * Volumes de um carrinho com um produto só.
 *
 * @param int $product_id Produto.
 * @param int $qty        Quantidade.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_volumes_of( int $product_id, int $qty ): array|WP_Error {
	return papelito_shipping_build_packages( papelito_volumes_cart( $product_id, $qty ) );
}

echo "Cenario 1: carrinho que cabe numa caixa continua em um volume\n";
$single = papelito_volumes_of( VOLUMES_TEST_SMALL_PRODUCT, 3 );
papelito_volumes_assert( 'um volume', is_array( $single ) && 1 === count( $single ) );
papelito_volumes_assert( 'mesmo pacote do construtor antigo', is_array( $single ) && papelito_shipping_build_package( papelito_volumes_cart( VOLUMES_TEST_SMALL_PRODUCT, 3 ) ) === $single[0] );

echo "Cenario 2: altura que estoura 100 cm abre outra caixa\n";
$tall = papelito_volumes_of( VOLUMES_TEST_SMALL_PRODUCT, 21 );
papelito_volumes_assert( 'antes era recusado', is_wp_error( papelito_shipping_build_package( papelito_volumes_cart( VOLUMES_TEST_SMALL_PRODUCT, 21 ) ) ) );
papelito_volumes_assert( 'agora vira dois volumes', is_array( $tall ) && 2 === count( $tall ) );
papelito_volumes_assert( 'primeira caixa cheia até 100 cm', is_array( $tall ) && 100.0 === $tall[0]['height'] );
papelito_volumes_assert( 'segunda caixa com a unidade que sobrou', is_array( $tall ) && VOLUMES_TEST_LAST_BOX_CM === $tall[1]['height'] );
papelito_volumes_assert( 'peso dividido entre as caixas', is_array( $tall ) && 2000.0 === $tall[0]['weight'] && 100.0 === $tall[1]['weight'] );

echo "Cenario 3: peso acima de 30 kg tambem divide\n";
$heavy = papelito_volumes_of( VOLUMES_TEST_HEAVY_PRODUCT, 10 );
papelito_volumes_assert( 'dois volumes', is_array( $heavy ) && 2 === count( $heavy ) );
papelito_volumes_assert( 'nenhuma caixa passa de 30 kg', is_array( $heavy ) && 28000.0 === $heavy[0]['weight'] && 12000.0 === $heavy[1]['weight'] );

echo "Cenario 4: unidade que nao cabe sozinha continua recusada\n";
$wide = papelito_volumes_of( VOLUMES_TEST_WIDE_PRODUCT, 1 );
papelito_volumes_assert( 'recusa com o erro de limite', is_wp_error( $wide ) && VOLUMES_TEST_EXCEEDS_CODE === $wide->code );
papelito_volumes_assert( 'aponta a soma dos lados', is_wp_error( $wide ) && 'dimension_sum' === ( $wide->data['limit'] ?? null ) );

echo "Cenario 5: mais caixas do que o teto e recusado\n";
$too_many = papelito_volumes_of( VOLUMES_TEST_SMALL_PRODUCT, ( PAPELITO_SHIPPING_MAX_VOLUMES * 20 ) + 1 );
papelito_volumes_assert( 'recusa acima do teto', is_wp_error( $too_many ) && VOLUMES_TEST_EXCEEDS_CODE === $too_many->code );
papelito_volumes_assert( 'aponta o teto de volumes', is_wp_error( $too_many ) && 'volumes' === ( $too_many->data['limit'] ?? null ) );
$at_limit = papelito_volumes_of( VOLUMES_TEST_SMALL_PRODUCT, PAPELITO_SHIPPING_MAX_VOLUMES * 20 );
papelito_volumes_assert( 'exatamente no teto ainda cota', is_array( $at_limit ) && PAPELITO_SHIPPING_MAX_VOLUMES === count( $at_limit ) );

echo "Cenario 6: preco somado por servico e prazo consultado uma vez\n";
$services = array(
	array(
		'service' => 'PAC',
		'code'    => VOLUMES_TEST_PAC_CODE,
		'name'    => 'PAC',
	),
	array(
		'service' => 'SEDEX',
		'code'    => VOLUMES_TEST_SEDEX_CODE,
		'name'    => 'SEDEX',
	),
);

$volumes                     = papelito_volumes_of( VOLUMES_TEST_SMALL_PRODUCT, 41 );
$GLOBALS['volumes_requests'] = array();

$quoted = papelito_correios_quote_volume_services(
	array(
		'environment' => 'production',
		'contract'    => '',
	),
	array( 'token' => 't' ),
	$services,
	'01001000',
	'70000000',
	$volumes
);

$pac_prices = array_values( array_filter( $quoted['options'], static fn( array $option ): bool => 'PAC' === $option['service'] ) );
papelito_volumes_assert( 'tres caixas: duas iguais e uma com a sobra', is_array( $volumes ) && 3 === count( $volumes ) );
papelito_volumes_assert( 'PAC soma as tres caixas', 1 === count( $pac_prices ) && 41.0 === $pac_prices[0]['price'] );
papelito_volumes_assert( 'PAC mantem o prazo', 1 === count( $pac_prices ) && 5 === $pac_prices[0]['delivery_time'] );
papelito_volumes_assert( 'SEDEX recusado em uma caixa sai da lista', 1 === count( $quoted['options'] ) );
papelito_volumes_assert( 'a recusa fica registrada', $quoted['first_error'] instanceof WP_Error );
$prazo_calls = count( array_filter( $GLOBALS['volumes_requests'], static fn( string $url ): bool => str_contains( $url, 'prazo/v1' ) ) );
papelito_volumes_assert( 'prazo consultado uma vez por servico', 2 === $prazo_calls );
$price_calls = count( array_filter( $GLOBALS['volumes_requests'], static fn( string $url ): bool => str_contains( $url, 'preco/v1' ) ) );
papelito_volumes_assert( 'preco consultado uma vez por medida de caixa e servico', 4 === $price_calls );

echo "Cenario 7: caixas extras desistem quando o tempo acaba\n";
$GLOBALS['volumes_requests'] = array();

$late = papelito_correios_quote_volume_services(
	array(
		'environment' => 'production',
		'contract'    => '',
	),
	array( 'token' => 't' ),
	$services,
	'01001000',
	'70000000',
	$volumes,
	microtime( true ) - 1
);
papelito_volumes_assert( 'nenhum servico sai pela metade', array() === $late['options'] );
papelito_volumes_assert( 'a desistencia vira timeout', $late['first_error'] instanceof WP_Error && 'papelito_correios_timeout' === $late['first_error']->code );

echo "Cenario 8: recusa dos Correios nao se confunde com Correios fora do ar\n";
papelito_volumes_assert( 'caixa recusada com 400 vira validacao', $quoted['first_error'] instanceof WP_Error && 'papelito_correios_validation_rejected' === $quoted['first_error']->code );

$failure_code = static fn( int $status ): string => papelito_correios_quote_failure( new WP_Error( 'x', 'x', array( 'correios_status' => $status ) ) )->code;
papelito_volumes_assert( 'CEP inexistente (400) vira validacao', 'papelito_correios_validation_rejected' === $failure_code( 400 ) );
papelito_volumes_assert( 'credencial recusada (401) vira configuracao', 'papelito_correios_configuration_rejected' === $failure_code( 401 ) );
papelito_volumes_assert( 'limite de requisicoes (429) segue como falha', 'papelito_shipping_quote_failed' === $failure_code( 429 ) );
papelito_volumes_assert( 'erro 503 segue como falha', 'papelito_shipping_quote_failed' === $failure_code( 503 ) );
papelito_volumes_assert( 'sem resposta segue como falha', 'papelito_shipping_quote_failed' === papelito_correios_quote_failure( new WP_Error( 'papelito_correios_unreachable', 'x' ) )->code );

if ( $failures > 0 ) {
	echo "FAILED: {$failures}\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
