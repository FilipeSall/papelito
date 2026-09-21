<?php
/**
 * Standalone regression test for the carrier named in the vendor status errors.
 *
 * Both refusals of `papelito_vendor_dashboard_update_order_status()` used to
 * name the Correios: the protected status claimed the confirmation comes from
 * the Rastro API, and the cancellation guard claimed a pre-postagem existed.
 * Neither is true for a Braspress order, which is tracked by the order number
 * the seller agreed outside the platform and never gets a label.
 *
 * Usage: php tests/test-vendor-status-transportadora-copy.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function sanitize_key( mixed $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( mixed $value ): string {
	return trim( (string) $value );
}

function sanitize_textarea_field( mixed $value ): string {
	return trim( (string) $value );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function sanitize_email( mixed $value ): string {
	return trim( (string) $value );
}

function wc_format_datetime( mixed $date, string $format = '' ): string {
	return '';
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

function get_current_user_id(): int {
	return 1;
}

function papelito_pagarme_payment_state_is_paid( string $state ): bool {
	return in_array( sanitize_key( $state ), array( 'paid', 'captured' ), true );
}

/** O pedido do teste sempre tem envio ativo: é o gatilho da recusa em exame. */
function papelito_tracking_order_shipments( int $order_id, bool $include_inactive = false, string $direction = 'outbound' ): array {
	return array( array( 'id' => 71, 'order_id' => $order_id ) );
}

class WP_Error {
	private string $code;
	private string $message;
	private array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

class WC_Order {
	public array $meta;
	public array $notes = array();
	public bool $saved  = false;
	private string $wc_status;
	private int $id;

	public function __construct( int $id, array $meta, string $wc_status ) {
		$this->id        = $id;
		$this->meta      = $meta;
		$this->wc_status = $wc_status;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta( mixed $key, bool $single = true ): mixed {
		return $this->meta[ (string) $key ] ?? '';
	}

	public function update_meta_data( mixed $key, mixed $value ): void {
		$this->meta[ (string) $key ] = $value;
	}

	public function get_status(): string {
		return $this->wc_status;
	}

	public function add_order_note( mixed $note ): void {
		$this->notes[] = (string) $note;
	}

	public function save(): bool {
		$this->saved = true;

		return true;
	}

	public function get_customer_id(): int {
		return 2158;
	}

	public function get_items( string $type = 'line_item' ): array {
		return array();
	}

	public function get_date_created(): ?object {
		return null;
	}

	public function get_date_paid(): ?object {
		return null;
	}

	/** Getters de leitura que o mapeamento toca e o teste não avalia. */
	public function __call( string $name, array $arguments ): string {
		return '';
	}
}

$GLOBALS['papelito_test_order'] = null;

function wc_get_order( mixed $order_id ): ?WC_Order {
	return $GLOBALS['papelito_test_order'];
}

require_once __DIR__ . '/../includes/vendor_dashboard.php';

function papelito_make_order( string $provider, string $vendor_status ): WC_Order {
	return new WC_Order(
		11890,
		array(
			'_papelito_vendor_status'     => $vendor_status,
			'_papelito_vendor_id'         => '10',
			'_papelito_shipping_provider' => $provider,
		),
		'processing'
	);
}

function papelito_refusal_message( string $provider, string $vendor_status, string $next ): string {
	$GLOBALS['papelito_test_order'] = papelito_make_order( $provider, $vendor_status );
	$result                         = papelito_vendor_dashboard_update_order_status( 11890, 10, $next, 'Sem estoque.' );

	return is_wp_error( $result ) ? $result->get_error_message() : '';
}

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

function papelito_assert_lacks( string $label, string $needle, string $haystack ): void {
	papelito_assert( $label, false, false !== stripos( $haystack, $needle ) );
}

function papelito_assert_contains( string $label, string $needle, string $haystack ): void {
	papelito_assert( $label, true, false !== stripos( $haystack, $needle ) );
}

echo "Scenario 1: enviado and entregue are projected, and the projection is not the Correios API\n";
$protected_braspress = papelito_refusal_message( 'braspress', 'em_separacao', 'entregue' );
papelito_assert_lacks( 'the refusal does not name the Correios on a Braspress order', 'correios', $protected_braspress );
papelito_assert_contains( 'the refusal still says where the confirmation comes from', 'transportadora', $protected_braspress );

$protected_correios = papelito_refusal_message( 'correios', 'em_separacao', 'entregue' );
papelito_assert( 'the same sentence serves both transportadoras', $protected_braspress, $protected_correios );

echo "Scenario 2: the cancellation guard describes the envio the order really has\n";
$cancel_braspress = papelito_refusal_message( 'braspress', 'em_separacao', 'cancelado' );
papelito_assert_lacks( 'no pre-postagem is claimed for Braspress', 'pre-postagem', $cancel_braspress );
papelito_assert_lacks( 'no pré-postagem is claimed for Braspress', 'pré-postagem', $cancel_braspress );
papelito_assert_lacks( 'the Correios are not named on a Braspress order', 'correios', $cancel_braspress );
papelito_assert_contains( 'the transportadora of the order is named', 'braspress', $cancel_braspress );
papelito_assert_contains( 'the way out is still the administrative cancellation', 'cancelamento administrativo', $cancel_braspress );

$cancel_correios = papelito_refusal_message( 'correios', 'em_separacao', 'cancelado' );
papelito_assert_contains( 'the Correios order keeps its pre-postagem', 'pre-postagem', $cancel_correios );
papelito_assert_contains( 'and keeps saying where it also has to be cancelled', 'correios', $cancel_correios );

echo "Scenario 3: an order written before the multicarrier contract is read as Correios\n";
$cancel_legacy = papelito_refusal_message( '', 'em_separacao', 'cancelado' );
papelito_assert( 'the legacy order gets the Correios sentence', $cancel_correios, $cancel_legacy );

echo "Scenario 4: the order list carries the transportadora, not only the detail\n";
$listed = papelito_vendor_dashboard_map_order( papelito_make_order( 'braspress', 'enviado' ), 10, false );
papelito_assert( 'the list payload names the provider', 'braspress', $listed['shipping_provider'] ?? '' );

$detailed = papelito_vendor_dashboard_map_order( papelito_make_order( 'braspress', 'enviado' ), 10, true );
papelito_assert( 'and keeps saying the same thing in the detail', 'braspress', $detailed['shipping_provider'] ?? '' );

$listed_legacy = papelito_vendor_dashboard_map_order( papelito_make_order( '', 'enviado' ), 10, false );
papelito_assert( 'an order without provider stays empty, for the reader to decide', '', $listed_legacy['shipping_provider'] ?? 'ausente' );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
