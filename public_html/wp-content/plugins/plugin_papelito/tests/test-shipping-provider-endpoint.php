<?php
/**
 * Exercita a borda REST real com pacote físico local e sem transporte HTTP.
 * A agregação deve preservar a validação do pacote sem publicar diagnósticos.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_BRASPRESS_ENABLED', false );
define( 'PAPELITO_CORREIOS_USERNAME', 'local-test-user' );
define( 'PAPELITO_CORREIOS_ACCESS_CODE', 'local-test-access' );
define( 'PAPELITO_CORREIOS_POSTING_CARD', '0012345678' );
define( 'PAPELITO_CORREIOS_CONTRACT', 'local-test-contract' );
const SHIPPING_ENDPOINT_TEST_LIMIT_CODE = 'papelito_shipping_package_exceeds_limits';
const SHIPPING_ENDPOINT_TEST_PRIVATE = 'private-upstream-data';

/** Erro WordPress mínimo que permite observar a resposta pública e injetar metadados privados. */
class WP_Error {
	public string $code;
	public string $message;
	public array $data;
	public function __construct( string $code, string $message, array $data = array() ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

/** Vendor autenticável no lookup WordPress local. */
class WP_User {
	public array $roles = array( 'seller' );
}

/** Corpo da requisição consumido pelo endpoint real. */
class WP_REST_Request {
	private array $params;
	public function __construct( array $params ) { $this->params = $params; }
	public function get_json_params(): array { return $this->params; }
}

/** Produto local cujas medidas vêm da fixture de cada cenário. */
class WC_Product {
	private array $measurements;
	public function __construct( array $measurements ) { $this->measurements = $measurements; }
	public function get_weight(): float { return $this->measurements['weight']; }
	public function get_length(): float { return $this->measurements['length']; }
	public function get_width(): float { return $this->measurements['width']; }
	public function get_height(): float { return $this->measurements['height']; }
	public function get_price(): float { return 10.0; }
	public function get_name(): string { return 'Produto local'; }
}

/** Doubles WordPress retornam somente fixtures locais. */
function add_action( mixed ...$args ): bool { return true; }
/** Sanitização textual compatível com as fixtures simples do teste. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Reconhece erros produzidos pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Identidade autenticada usada no rate limit real. */
function get_current_user_id(): int { return 9; }
/** O cache local começa vazio em cada chamada. */
function get_transient( mixed $key ): bool { return false; }
/** A gravação de rate limit não possui backend externo no teste. */
function set_transient( mixed ...$args ): bool { return true; }
/** Segredo HMAC restrito ao teste. */
function wp_salt( mixed $scheme ): string { return 'shipping-endpoint-test-salt'; }
/** Data estável da agregação local. */
function current_time( mixed ...$args ): string { return '2026-09-15T12:00:00+00:00'; }
/** Lookup do vendor sem banco. */
function get_userdata( mixed $vendor_id ): WP_User { return new WP_User(); }
/** CEP de origem local do vendor. */
function get_user_meta( mixed ...$args ): string { return '01001000'; }
/** Consulta do catálogo usa apenas a fixture corrente. */
function wc_get_product( mixed $product_id ): WC_Product { return new WC_Product( $GLOBALS['shipping_endpoint_test_measurements'] ); }
/** A fixture já declara peso em gramas. */
function wc_get_weight( mixed $value, mixed $unit ): float { return (float) $value; }
/** A fixture já declara dimensões em centímetros. */
function wc_get_dimension( mixed $value, mixed $unit ): float { return (float) $value; }
/** A fronteira de observabilidade injeta campos privados para testar sua remoção. */
function do_action( mixed $hook, mixed ...$args ): bool {
	if ( 'papelito_shipping_package_rejected' === $hook && $args[0] instanceof WP_Error ) {
		$args[0]->data['correios_status'] = 500;
		$args[0]->data['correios_message'] = SHIPPING_ENDPOINT_TEST_PRIVATE;
		$args[0]->data['body'] = SHIPPING_ENDPOINT_TEST_PRIVATE;
		$args[0]->data['token'] = SHIPPING_ENDPOINT_TEST_PRIVATE;
	}
	return true;
}
/** Qualquer tentativa de transporte é falha imediata; este teste não faz rede. */
function wp_remote_request( mixed ...$args ): never {
	throw new RuntimeException( 'HTTP proibido no teste local da borda REST.' );
}

require_once dirname( __DIR__ ) . '/includes/shipping.php';
require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';

$failures = 0;
/** Registra falhas sem interromper os demais cenários. */
function shipping_endpoint_assert( string $label, bool $condition ): void {
	if ( ! $condition ) {
		++$GLOBALS['failures'];
		echo "FAIL: {$label}\n";
	}
}

$cases = array(
	'dimension' => array( 'weight' => 100.0, 'length' => 101.0, 'width' => 15.0, 'height' => 5.0 ),
	'dimension_sum' => array( 'weight' => 100.0, 'length' => 90.0, 'width' => 80.0, 'height' => 35.0 ),
	'weight' => array( 'weight' => 31000.0, 'length' => 20.0, 'width' => 15.0, 'height' => 5.0 ),
);
foreach ( $cases as $limit => $shipping_endpoint_test_measurements ) {
	$request = new WP_REST_Request( array( 'vendor_id' => 7, 'destination_cep' => '22041001', 'items' => array( array( 'product_id' => 201, 'qty' => 1 ) ) ) );
	$response = papelito_shipping_quote_endpoint( $request );
	shipping_endpoint_assert( 'endpoint mantém código físico: ' . $limit, $response instanceof WP_Error && SHIPPING_ENDPOINT_TEST_LIMIT_CODE === $response->get_error_code() );
	shipping_endpoint_assert( 'endpoint mantém 422 e só metadados físicos: ' . $limit, $response instanceof WP_Error && array( 'status' => 422, 'limit' => $limit, 'measurement_source' => 'legacy_synthetic' ) === $response->get_error_data() );
	shipping_endpoint_assert( 'endpoint mantém mensagem genérica: ' . $limit, $response instanceof WP_Error && 'Não foi possível cotar o frete.' === $response->get_error_message() );
}

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
