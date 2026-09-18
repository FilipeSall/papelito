<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Métrica de resíduo de embalagem — consumidor das actions da Fase 1.
 *
 * Fixa três coisas que só se veem juntas: que o contador agrega por dia civil
 * em vez de gravar linha por cotação, que a recusa é contada por código de erro
 * sem mexer em nenhuma origem, e que o caminho de perfil também emite. O último
 * é o que prova a correção da assimetria — antes dela o `profile` nunca subia e
 * a métrica reportaria 100% de medida legada para sempre.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

const METRICS_TEST_SMALL_PRODUCT   = 301;
const METRICS_TEST_KIT_PRODUCT     = 303;
const METRICS_TEST_VENDOR_ID       = 2163;
const METRICS_TEST_FIRST_DAY       = '2026-09-17';
const METRICS_TEST_SECOND_DAY      = '2026-09-18';
const METRICS_TEST_LEGACY_SOURCE   = 'legacy_synthetic';
const METRICS_TEST_KIT_SOURCE      = 'kit_declared';
const METRICS_TEST_PROFILE_SOURCE  = 'profile';
const METRICS_TEST_LIMITS_CODE     = 'papelito_shipping_package_exceeds_limits';
const METRICS_TEST_NOT_APPROVED    = 'papelito_braspress_package_not_approved';
const METRICS_TEST_ORIGIN_CEP      = '14700000';
const METRICS_TEST_DESTINATION_CEP = '22041001';
const METRICS_TEST_CNPJ            = '20024291000165';
const METRICS_TEST_CREDENTIAL      = 'braspress-access-token-do-teste';

$GLOBALS['metrics_test_day']         = METRICS_TEST_FIRST_DAY;
$GLOBALS['metrics_test_options']     = array();
$GLOBALS['metrics_test_write_fails'] = false;
$GLOBALS['metrics_test_actions']     = array();
$GLOBALS['metrics_test_package']     = null;

/** Erro WordPress mínimo com a leitura de código que o contador usa. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

/** Produto do catálogo com medidas controladas pela fixture. */
class WC_Product {
	public function __construct(
		private float $weight,
		private float $length,
		private float $width,
		private float $height
	) {}
	public function get_weight(): float { return $this->weight; }
	public function get_length(): float { return $this->length; }
	public function get_width(): float { return $this->width; }
	public function get_height(): float { return $this->height; }
	public function get_price(): float { return 10.0; }
	public function get_name(): string { return 'Produto de teste'; }
}

/** Registra o listener para que `do_action` execute o código real do contador. */
function add_action( mixed $hook, mixed $callback = null, mixed $priority = 10, mixed $accepted = 1 ): bool {
	$GLOBALS['metrics_test_actions'][ (string) $hook ][] = $callback;

	return true;
}

/** Executa os listeners registrados, como o WordPress faria na cotação real. */
function do_action( mixed $hook, mixed ...$args ): bool {
	foreach ( $GLOBALS['metrics_test_actions'][ (string) $hook ] ?? array() as $callback ) {
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, ...$args );
		}
	}

	return true;
}

/** Nenhum filtro é registrado; o pacote físico vem direto da fixture. */
function add_filter( mixed ...$args ): bool { return true; }

/** Devolve a fixture de pacote físico somente para o filtro do caminho de perfil. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed {
	return 'papelito_braspress_physical_package' === (string) $hook ? $GLOBALS['metrics_test_package'] : $value;
}

/** Aceita a fixture como pacote Braspress válido sem carregar o adaptador. */
function papelito_braspress_package_is_valid( array $package ): bool {
	return ! empty( $package['volumes'] ) && ! empty( $package['cubagem'] );
}

/** Nenhuma rota REST é exercitada; o registro é aceito e descartado. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Sanitização textual suficiente para as fixturas do teste. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** As fixturas já vêm sem acento. */
function remove_accents( mixed $value ): string { return (string) $value; }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Segredo HMAC restrito ao teste. */
function wp_salt( mixed $scheme = '' ): string { return 'metrics-test-salt'; }
/** O cache de cotação começa vazio em cada chamada. */
function get_transient( mixed $key ): bool { return false; }
/** A gravação de cache não tem backend no teste. */
function set_transient( mixed ...$args ): bool { return true; }
/** A fixture já declara peso em gramas. */
function wc_get_weight( mixed $value, mixed $unit ): float { return (float) $value; }
/** A fixture já declara dimensões em centímetros. */
function wc_get_dimension( mixed $value, mixed $unit ): float { return (float) $value; }
/** O carrinho do teste nunca expande componentes de Kit. */
function papelito_kit_shipping_items( array $items ): array { return $items; }

/** Relógio do teste: o dia civil é a fixture, e o resto herda o mesmo dia. */
function current_time( mixed $format = 'Y-m-d', mixed $gmt = false ): string {
	$day = (string) $GLOBALS['metrics_test_day'];

	return 'Y-m-d' === (string) $format ? $day : $day . ' 12:00:00';
}

/** Lê a option do armazenamento em memória do teste. */
function get_option( mixed $option, mixed $default_value = false ): mixed {
	return array_key_exists( (string) $option, $GLOBALS['metrics_test_options'] )
		? $GLOBALS['metrics_test_options'][ (string) $option ]
		: $default_value;
}

/** Grava a option, ou explode quando a fixture pede falha de escrita. */
function update_option( mixed $option, mixed $value, mixed $autoload = null ): bool {
	if ( true === $GLOBALS['metrics_test_write_fails'] ) {
		throw new RuntimeException( 'Cache de objetos indisponível para gravar o contador.' );
	}

	$GLOBALS['metrics_test_options'][ (string) $option ] = $value;

	return true;
}

/** Remove a option do armazenamento em memória do teste. */
function delete_option( mixed $option ): bool {
	unset( $GLOBALS['metrics_test_options'][ (string) $option ] );

	return true;
}

/** Só o Kit da fixture é tratado como produto de Kit. */
function papelito_kit_is_product( int $product_id ): bool {
	return METRICS_TEST_KIT_PRODUCT === $product_id;
}

/** Dimensões declaradas do Kit da fixture. */
function papelito_kit_logistics( int $product_id, int $qty ): array {
	return array( 'weight' => 900.0, 'length' => 30.0, 'width' => 20.0, 'height' => 10.0 );
}

/** Catálogo local do teste. */
function wc_get_product( int $product_id ): ?WC_Product {
	return match ( $product_id ) {
		METRICS_TEST_SMALL_PRODUCT => new WC_Product( 100.0, 20.0, 15.0, 5.0 ),
		METRICS_TEST_KIT_PRODUCT   => new WC_Product( 900.0, 30.0, 20.0, 10.0 ),
		default                    => null,
	};
}

require_once dirname( __DIR__ ) . '/includes/shipping.php';
require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';
require_once dirname( __DIR__ ) . '/includes/shipping_metrics.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function metrics_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Zera o armazenamento e o relógio entre cenários. */
function metrics_reset( string $day = METRICS_TEST_FIRST_DAY ): void {
	$GLOBALS['metrics_test_options']     = array();
	$GLOBALS['metrics_test_write_fails'] = false;
	$GLOBALS['metrics_test_day']         = $day;
}

/** Cota o pacote sintético legado dos Correios. */
function metrics_quote_legacy( int $product_id = METRICS_TEST_SMALL_PRODUCT, int $qty = 1 ) {
	return papelito_shipping_build_package( array( array( 'product_id' => $product_id, 'qty' => $qty ) ) );
}

/** Cota o pacote de Kit, que declara a própria embalagem. */
function metrics_quote_kit() {
	return papelito_shipping_build_package( array( array( 'product_id' => METRICS_TEST_KIT_PRODUCT, 'qty' => 1 ) ) );
}

/** Cota pelo caminho de perfil, o mesmo que a Braspress consome. */
function metrics_quote_profile() {
	$GLOBALS['metrics_test_package'] = array(
		'weight_kg'          => 0.97,
		'volumes'            => 1,
		'cubagem'            => array( array( 'length_m' => 0.2, 'width_m' => 0.15, 'height_m' => 0.1, 'volumes' => 1 ) ),
		'approval_version'   => 3,
		'physical_hash'      => str_repeat( 'a', 64 ),
		'measurement_source' => METRICS_TEST_PROFILE_SOURCE,
	);

	return papelito_shipping_braspress_physical_package( METRICS_TEST_VENDOR_ID, array( array( 'product_id' => METRICS_TEST_SMALL_PRODUCT, 'qty' => 1 ) ) );
}

/** Lê a contagem de uma origem da medida no relatório do período corrente. */
function metrics_built( string $source, string $from = '', string $to = '' ): int {
	$report = papelito_shipping_metrics_report( $from, $to );

	return (int) ( $report['built'][ $source ] ?? -1 );
}

/** Lê a contagem de uma recusa no relatório do período corrente. */
function metrics_rejected( string $code, string $from = '', string $to = '' ): int {
	$report = papelito_shipping_metrics_report( $from, $to );

	return (int) ( $report['rejected'][ $code ] ?? -1 );
}

echo "Scenario 1: pacote legado incrementa legacy_synthetic\n";
metrics_reset();
metrics_assert( 'cotação devolve pacote', is_array( metrics_quote_legacy() ) );
metrics_assert( 'conta uma medida legada', 1 === metrics_built( METRICS_TEST_LEGACY_SOURCE ) );
metrics_assert( 'não conta perfil', 0 === metrics_built( METRICS_TEST_PROFILE_SOURCE ) );
metrics_assert( 'não conta Kit', 0 === metrics_built( METRICS_TEST_KIT_SOURCE ) );

echo "Scenario 2: pacote de Kit incrementa kit_declared\n";
metrics_reset();
metrics_assert( 'cotação de Kit devolve pacote', is_array( metrics_quote_kit() ) );
metrics_assert( 'conta uma medida de Kit', 1 === metrics_built( METRICS_TEST_KIT_SOURCE ) );
metrics_assert( 'não conta medida legada', 0 === metrics_built( METRICS_TEST_LEGACY_SOURCE ) );

echo "Scenario 3: pacote de perfil incrementa profile (assimetria do emissor)\n";
metrics_reset();
$profile_package = metrics_quote_profile();
metrics_assert( 'caminho de perfil devolve pacote aprovado', is_array( $profile_package ) );
metrics_assert( 'conta uma embalagem cadastrada', 1 === metrics_built( METRICS_TEST_PROFILE_SOURCE ) );
metrics_assert( 'não conta medida legada no caminho de perfil', 0 === metrics_built( METRICS_TEST_LEGACY_SOURCE ) );
metrics_assert( 'não conta recusa quando o perfil é aprovado', 0 === metrics_rejected( METRICS_TEST_NOT_APPROVED ) );

echo "Scenario 4: duas cotações no mesmo dia somam no mesmo balde\n";
metrics_reset();
metrics_quote_legacy();
metrics_quote_legacy();
metrics_assert( 'soma as duas cotações', 2 === metrics_built( METRICS_TEST_LEGACY_SOURCE ) );
metrics_assert( 'grava um único registro', 1 === count( $GLOBALS['metrics_test_options'] ) );

echo "Scenario 5: virada do dia civil abre balde novo\n";
metrics_reset();
metrics_quote_legacy();
$GLOBALS['metrics_test_day'] = METRICS_TEST_SECOND_DAY;
metrics_quote_legacy();
metrics_assert( 'abre o segundo registro', 2 === count( $GLOBALS['metrics_test_options'] ) );
metrics_assert( 'o dia novo conta só a sua cotação', 1 === metrics_built( METRICS_TEST_LEGACY_SOURCE ) );
metrics_assert( 'o dia anterior preserva a contagem', 1 === metrics_built( METRICS_TEST_LEGACY_SOURCE, METRICS_TEST_FIRST_DAY, METRICS_TEST_FIRST_DAY ) );
metrics_assert( 'o período soma os dois dias', 2 === metrics_built( METRICS_TEST_LEGACY_SOURCE, METRICS_TEST_FIRST_DAY, METRICS_TEST_SECOND_DAY ) );

echo "Scenario 6: recusa conta por código de erro e não mexe em origem\n";
metrics_reset();
$rejected = metrics_quote_legacy( METRICS_TEST_SMALL_PRODUCT, 21 );
metrics_assert( 'a cotação é recusada', is_wp_error( $rejected ) && METRICS_TEST_LIMITS_CODE === $rejected->get_error_code() );
metrics_assert( 'conta a recusa pelo código', 1 === metrics_rejected( METRICS_TEST_LIMITS_CODE ) );
metrics_assert( 'nenhuma origem foi incrementada', 0 === array_sum( papelito_shipping_metrics_report()['built'] ) );
$GLOBALS['metrics_test_package'] = null;
$not_approved = papelito_shipping_braspress_physical_package( METRICS_TEST_VENDOR_ID, array( array( 'product_id' => METRICS_TEST_SMALL_PRODUCT, 'qty' => 1 ) ) );
metrics_assert( 'o caminho de perfil recusa sem caixa', is_wp_error( $not_approved ) );
metrics_assert( 'separa vendor sem caixa de carrinho grande', 1 === metrics_rejected( METRICS_TEST_NOT_APPROVED ) && 1 === metrics_rejected( METRICS_TEST_LIMITS_CODE ) );

echo "Scenario 7: percentual de resíduo é (legacy + kit) sobre o total\n";
metrics_reset();
metrics_assert( 'período vazio devolve zero, sem divisão por zero', 0.0 === papelito_shipping_metrics_report()['residue_percent'] );
metrics_assert( 'período vazio devolve total zero', 0 === papelito_shipping_metrics_report()['total_built'] );
metrics_quote_legacy();
metrics_quote_kit();
metrics_quote_profile();
metrics_quote_profile();
$report = papelito_shipping_metrics_report();
metrics_assert( 'total soma as quatro embalagens', 4 === $report['total_built'] );
metrics_assert( 'resíduo soma legado e Kit', 2 === $report['residue_built'] );
metrics_assert( 'percentual de resíduo é 50', 50.0 === $report['residue_percent'] );
metrics_assert( 'razão de resíduo é 0.5', 0.5 === $report['residue_ratio'] );

echo "Scenario 8: falha na escrita do contador não derruba a cotação\n";
metrics_reset();
$GLOBALS['metrics_test_write_fails'] = true;
$survived = metrics_quote_legacy();
metrics_assert( 'a cotação continua devolvendo pacote', is_array( $survived ) && ! is_wp_error( $survived ) );
metrics_assert( 'nada foi gravado', 0 === count( $GLOBALS['metrics_test_options'] ) );
$survived_profile = metrics_quote_profile();
metrics_assert( 'o caminho de perfil também sobrevive', is_array( $survived_profile ) && ! is_wp_error( $survived_profile ) );

echo "Scenario 9: o contador não guarda CEP, CNPJ nem credencial\n";
metrics_reset();
metrics_quote_legacy();
metrics_quote_kit();
metrics_quote_profile();
metrics_quote_legacy( METRICS_TEST_SMALL_PRODUCT, 21 );
$stored = json_encode( $GLOBALS['metrics_test_options'] );
metrics_assert( 'sem CEP de origem', ! str_contains( $stored, METRICS_TEST_ORIGIN_CEP ) );
metrics_assert( 'sem CEP de destino', ! str_contains( $stored, METRICS_TEST_DESTINATION_CEP ) );
metrics_assert( 'sem CNPJ', ! str_contains( $stored, METRICS_TEST_CNPJ ) );
metrics_assert( 'sem credencial', ! str_contains( $stored, METRICS_TEST_CREDENTIAL ) );
metrics_assert( 'sem identificador de vendor', ! str_contains( $stored, (string) METRICS_TEST_VENDOR_ID ) );
metrics_assert( 'sem hash físico da embalagem', ! str_contains( $stored, str_repeat( 'a', 64 ) ) );
metrics_assert( 'origem da medida é vocabulário fechado', array( METRICS_TEST_PROFILE_SOURCE, METRICS_TEST_LEGACY_SOURCE, METRICS_TEST_KIT_SOURCE, 'unknown' ) === array_keys( papelito_shipping_metrics_report()['built'] ) );

if ( $failures > 0 ) {
	echo "FAILED: {$failures}\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
