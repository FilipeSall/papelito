<?php
/**
 * Regressão da regra de publicação de Kits.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );

class Papelito_Kit_Publication_DB {
	public string $prefix = 'wp_';
	public array $kits = array();
	public array $items = array();
	public array $merchandise = array();

	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( array( '%d', '%s', '%f' ), '%s', $query ), $args );
	}

	public function get_row( string $query, $output = null ) {
		if ( preg_match( '/product_id = (\d+)/', $query, $matches ) ) {
			return $this->kits[ (int) $matches[1] ] ?? null;
		}

		return null;
	}

	public function get_results( string $query, $output = null ) {
		preg_match( '/kit_id = (\d+)/', $query, $matches );
		$kit_id = (int) ( $matches[1] ?? 0 );

		if ( str_contains( $query, 'papelito_kit_items' ) ) {
			return $this->items[ $kit_id ] ?? array();
		}

		if ( str_contains( $query, 'papelito_kit_merchandise_items' ) ) {
			return $this->merchandise[ $kit_id ] ?? array();
		}

		return array();
	}
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

class WC_Product {
	public function __construct( private int $id, private string $weight, private string $status = 'publish' ) {}
	public function get_id(): int { return $this->id; }
	public function get_name(): string { return 'Produto'; }
	public function get_weight( string $context = 'view' ): string { return $this->weight; }
	public function get_status(): string { return $this->status; }
	public function set_status( string $status ): void { $this->status = $status; }
	public function save(): void {}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
function do_action( string $hook, ...$args ): void {}
function wc_get_weight( float $weight, string $unit ): float { return 'g' === $unit ? $weight * 1000 : $weight; }
function wc_format_decimal( string $value ): string { return $value; }
function delete_transient( string $key ): bool { return true; }
function wc_get_product( int $product_id ) { return $GLOBALS['papelito_kit_publication_products'][ $product_id ] ?? null; }

$wpdb = new Papelito_Kit_Publication_DB();
require __DIR__ . '/../includes/merchandise.php';
require __DIR__ . '/../includes/kits.php';

$failures = 0;
function papelito_kit_publication_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

$GLOBALS['papelito_kit_publication_products'] = array(
	101 => new WC_Product( 101, '0.5' ),
	102 => new WC_Product( 102, '0.3' ),
);
$items = array(
	array( 'productId' => 101, 'quantity' => 2 ),
	array( 'productId' => 102, 'quantity' => 1 ),
);
$complete_package = array( 'length' => 30.0, 'width' => 20.0, 'height' => 10.0 );

echo "Scenario 1: Kit completo pode ser publicado\n";
$result = papelito_kit_validate_publication_payload( 'publish', $complete_package, $items, array() );
papelito_kit_publication_assert( 'payload completo é aceito', true === $result );

echo "Scenario 2: Kit incompleto pode permanecer em rascunho\n";
$result = papelito_kit_validate_publication_payload( 'draft', array( 'length' => 0.0, 'width' => 0.0, 'height' => 0.0 ), $items, array() );
papelito_kit_publication_assert( 'rascunho sem dimensões é aceito', true === $result );

echo "Scenario 3: publicação sem comprimento é bloqueada\n";
$result = papelito_kit_validate_publication_payload( 'publish', array( 'length' => 0.0, 'width' => 20.0, 'height' => 10.0 ), $items, array() );
papelito_kit_publication_assert( 'retorna erro de dimensões faltantes', is_wp_error( $result ) && 'papelito_kit_package_dimensions_missing' === $result->code );
papelito_kit_publication_assert( 'identifica somente comprimento', is_wp_error( $result ) && array( 'length' ) === $result->data['missing_dimensions'] );

echo "Scenario 4: publicação sem peso derivado é bloqueada\n";
$GLOBALS['papelito_kit_publication_products'][102] = new WC_Product( 102, '' );
$result = papelito_kit_validate_publication_payload( 'publish', $complete_package, $items, array() );
papelito_kit_publication_assert( 'retorna erro de peso do componente', is_wp_error( $result ) && 'papelito_kit_component_weight_missing' === $result->code );

echo "Scenario 5: dimensões abaixo do mínimo dos Correios são rejeitadas\n";
$GLOBALS['papelito_kit_publication_products'][102] = new WC_Product( 102, '0.3' );
$result = papelito_kit_validate_publication_payload( 'publish', array( 'length' => 4.0, 'width' => 4.0, 'height' => 0.4 ), $items, array() );
papelito_kit_publication_assert( 'retorna erro de limites dimensionais', is_wp_error( $result ) && 'papelito_kit_package_dimensions_invalid' === $result->code );

echo "Scenario 6: Kit publicado inconsistente é rebaixado e sai da disponibilidade\n";
$GLOBALS['papelito_kit_publication_products'][102] = new WC_Product( 102, '0.3' );
$wpdb->kits[900] = array(
	'id'              => 7,
	'product_id'      => 900,
	'package_length'  => '0',
	'package_width'   => '20',
	'package_height'  => '10',
);
$GLOBALS['papelito_kit_publication_products'][900] = new WC_Product( 900, '0', 'publish' );
$kit = $wpdb->kits[900];
papelito_kit_publication_assert( 'Kit inconsistente não está disponível', false === papelito_kit_is_available_for_sale( $kit ) );
papelito_kit_publication_assert( 'Kit inconsistente é rebaixado para rascunho', true === papelito_kit_demote_if_incomplete( $kit ) && 'draft' === $GLOBALS['papelito_kit_publication_products'][900]->get_status() );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) failed\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
