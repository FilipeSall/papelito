<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'PAPELITO_BRASPRESS_ENABLED', true );
define( 'PAPELITO_BRASPRESS_VENDOR_ALLOWLIST', '7' );

function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function absint( $value ): int { return abs( (int) $value ); }
function current_time(): string { return '2026-09-14 12:00:00'; }
function wp_json_encode( $value ): string { return json_encode( $value ); }
function wp_salt(): string { return 'test-salt'; }
function add_action() {}
function is_wp_error(): bool { return false; }
function papelito_correios_quote( int $vendor_id, string $destination_cep, array $items ): array {
	return array(
		'origin_cep'      => '01001000',
		'destination_cep' => $destination_cep,
		'vendor_id'       => $vendor_id,
		'options'         => array(
			array(
				'code'          => '03298',
				'name'          => 'PAC',
				'service'       => 'PAC',
				'price'         => 15.88,
				'delivery_time' => 5,
			),
		),
	);
}
function papelito_vendor_integration_resolve_braspress() {
	return null;
}

require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';

$failures = 0;
function shipping_provider_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

$correios = papelito_shipping_normalize_provider_option(
	'correios',
	array(
		'code'          => '03298',
		'name'          => 'PAC',
		'price'         => 15.88,
		'delivery_time' => 5,
	)
);
$braspress = papelito_shipping_normalize_provider_option(
	'braspress',
	array(
		'code'          => '03298',
		'name'          => 'Braspress',
		'price'         => 15.88,
		'delivery_time' => 5,
	)
);

shipping_provider_assert( 'option key é namespaced pelo provider', 'correios:03298' === $correios['option_key'] && 'braspress:03298' === $braspress['option_key'] );
shipping_provider_assert( 'códigos iguais entre providers não colidem', $correios['option_key'] !== $braspress['option_key'] );
shipping_provider_assert( 'custos monetários são expostos em centavos', 1588 === $correios['carrier_cost_cents'] && 1588 === $correios['customer_price_cents'] );
shipping_provider_assert( 'option preserva o contrato legado', '03298' === $correios['code'] && 15.88 === $correios['price'] );
shipping_provider_assert( 'código sem namespace só seleciona Correios', papelito_shipping_option_matches_selection( $correios, '03298' ) && ! papelito_shipping_option_matches_selection( $braspress, '03298' ) );
$snapshot = array(
	'fingerprint'          => $correios['fingerprint'],
	'customer_price_cents' => $correios['customer_price_cents'],
	'delivery_time'        => $correios['delivery_time'],
	'expires_at'           => $correios['expires_at'],
);
shipping_provider_assert( 'snapshot atual confirma a cotação selecionada', papelito_shipping_option_matches_checkout_snapshot( $correios, 'correios:03298', $snapshot ) );
$repriced = papelito_shipping_normalize_provider_option(
	'correios',
	array(
		'code'          => '03298',
		'name'          => 'PAC',
		'price'         => 19.88,
		'delivery_time' => 5,
	)
);
shipping_provider_assert( 'snapshot anterior rejeita recotação com preço alterado', ! papelito_shipping_option_matches_checkout_snapshot( $repriced, 'correios:03298', $snapshot ) );

$missing_contract_quote = papelito_shipping_quote_all_providers( 7, '22041001', array() );
shipping_provider_assert( 'vendor na allowlist sem contrato recebe somente Correios', is_array( $missing_contract_quote ) && 1 === count( $missing_contract_quote['options'] ) && 'correios' === $missing_contract_quote['options'][0]['provider'] );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
