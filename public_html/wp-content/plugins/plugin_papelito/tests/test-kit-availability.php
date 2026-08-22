<?php
/**
 * Standalone regression test para a disponibilidade de Kit.
 *
 * O brinde é adereço fixo da composição: entra no peso e nas dimensões do
 * pacote, mas não é recurso contado por vendor. Quem decide se um Kit está
 * disponível é o estoque dos produtos-componentes naquele vendor, igual a
 * qualquer produto do catálogo.
 *
 * Usage: php tests/test-kit-availability.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );

class Papelito_Kits_Test_WPDB {
	public string $prefix           = 'wp_';
	public array $kits              = array();
	public array $items             = array();
	public array $merchandise       = array();
	public array $forbidden_queries = array();

	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( array( '%d', '%s', '%f' ), '%s', $query ), $args );
	}

	public function get_row( string $query, $output = null ) {
		$this->guard( $query );
		preg_match( '/product_id = (\d+)/', $query, $matches );
		return $this->kits[ (int) ( $matches[1] ?? 0 ) ] ?? null;
	}

	public function get_results( string $query, $output = null ) {
		$this->guard( $query );
		preg_match( '/kit_id = (\d+)/', $query, $matches );
		$kit_id = (int) ( $matches[1] ?? 0 );
		if ( str_contains( $query, 'wp_papelito_kit_items' ) ) {
			return $this->items[ $kit_id ] ?? array();
		}
		if ( str_contains( $query, 'wp_papelito_kit_merchandise' ) ) {
			return $this->merchandise[ $kit_id ] ?? array();
		}
		return array();
	}

	public function get_var( string $query ) {
		$this->guard( $query );
		return null;
	}

	public function query( string $query ) {
		$this->guard( $query );
		return 1;
	}

	private function guard( string $query ): void {
		if ( str_contains( $query, 'merchandise_stock' ) ) {
			$this->forbidden_queries[] = $query;
		}
	}
}

class WP_Error {
	public string $code;
	public string $message;
	public array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function add_action( string $hook, callable $callback, int $priority = 10 ): void {}

$GLOBALS['pap_products']      = array( 101 => true, 102 => true );
$GLOBALS['pap_vendor_stock']  = array();
$GLOBALS['pap_stock_adjusts'] = array();

function wc_get_product( int $product_id ) {
	return ( $GLOBALS['pap_products'][ $product_id ] ?? false ) ? new stdClass() : false;
}

function papelito_get_vendor_stock( int $vendor_id, int $product_id ): int {
	return (int) ( $GLOBALS['pap_vendor_stock'][ "{$vendor_id}:{$product_id}" ] ?? 0 );
}

function papelito_adjust_vendor_stock( int $vendor_id, int $product_id, int $delta, string $reason ) {
	$GLOBALS['pap_stock_adjusts'][] = array( $vendor_id, $product_id, $delta );
	return true;
}

$wpdb = new Papelito_Kits_Test_WPDB();

require __DIR__ . '/../includes/kits.php';

$failures = 0;
function papelito_kit_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

$wpdb->kits        = array( 900 => array( 'id' => 7, 'product_id' => 900 ) );
$wpdb->items       = array( 7 => array( array( 'product_id' => 101, 'quantity' => 2 ), array( 'product_id' => 102, 'quantity' => 1 ) ) );
$wpdb->merchandise = array( 7 => array( array( 'id' => 55, 'name' => 'Caneta', 'image_attachment_id' => 3, 'quantity' => 1, 'weight' => '0.05', 'length' => '14', 'width' => '2', 'height' => '2' ) ) );

echo "Scenario 1: brinde sem saldo não torna o Kit indisponível\n";
$GLOBALS['pap_vendor_stock'] = array( '12:101' => 10, '12:102' => 10 );
$result                      = papelito_kit_vendor_has_stock( 900, 1, 12 );
papelito_kit_assert( 'vendor com os produtos consegue montar o Kit', true === $result );

echo "Scenario 2: a checagem de estoque do produto continua valendo\n";
$GLOBALS['pap_vendor_stock'] = array( '12:101' => 1, '12:102' => 10 );
$result                      = papelito_kit_vendor_has_stock( 900, 1, 12 );
papelito_kit_assert( 'produto sem saldo bloqueia com 409', is_wp_error( $result ) && 'papelito_checkout_insufficient_stock' === $result->code );
papelito_kit_assert( 'o erro aponta o produto que faltou', is_wp_error( $result ) && 101 === ( $result->data['product_id'] ?? 0 ) );

echo "Scenario 3: a baixa de estoque ignora os brindes do snapshot\n";
$snapshot                      = array(
	'components'  => array( array( 'productId' => 101, 'quantity' => 2 ) ),
	'merchandise' => array( array( 'id' => 55, 'quantity' => 1 ) ),
);
$GLOBALS['pap_stock_adjusts'] = array();
$result                       = papelito_kit_adjust_snapshot_stock( $snapshot, 12, -1, 'kit_order' );
papelito_kit_assert( 'a baixa conclui sem erro', true === $result );
papelito_kit_assert( 'só o produto-componente foi ajustado', array( array( 12, 101, -2 ) ) === $GLOBALS['pap_stock_adjusts'] );

echo "Scenario 4: nenhuma consulta encosta na tabela de saldo de brinde\n";
papelito_kit_assert( 'sem SQL em merchandise_stock', array() === $wpdb->forbidden_queries );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) failed\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
