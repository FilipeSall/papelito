<?php
/**
 * Standalone regression test for Correios checkout service selection.
 *
 * Usage: php tests/test-shipping-service-selection.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}

function apply_filters( $hook_name, $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function remove_accents( $text ) {
	$converted = iconv( 'UTF-8', 'ASCII//TRANSLIT', (string) $text );
	return false === $converted ? (string) $text : $converted;
}

function absint( $value ) {
	return abs( (int) $value );
}

class WP_Error {}

require __DIR__ . '/../includes/shipping.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: checkout service filtering keeps only standard PAC/SEDEX\n";
papelito_assert( 'PAC Contrato AG is eligible', true, papelito_correios_is_checkout_service( 'PAC CONTRATO AG' ) );
papelito_assert( 'SEDEX Contrato AG is eligible', true, papelito_correios_is_checkout_service( 'SEDEX CONTRATO AG' ) );
papelito_assert( 'PAC reverso is blocked', false, papelito_correios_is_checkout_service( 'PAC REVERSO' ) );
papelito_assert( 'SEDEX locker is blocked', false, papelito_correios_is_checkout_service( 'SEDEX CONTRATO LOCKER' ) );
papelito_assert( 'LOG+ is blocked', false, papelito_correios_is_checkout_service( 'SEDEX 12 LOG+' ) );
papelito_assert( 'Packet is blocked', false, papelito_correios_is_checkout_service( 'PACKET STANDARD DISTRIBUICAO' ) );
papelito_assert( 'payment on delivery is blocked', false, papelito_correios_is_checkout_service( 'PAC CONTRATO AGENCIA PAGTO ENT' ) );

echo "Scenario 2: service type parsing does not mistake Packet/Empacotamento for PAC\n";
papelito_assert( 'PAC official code type', 'PAC', papelito_correios_service_type( '03298', 'Servico leve' ) );
papelito_assert( 'SEDEX official code type', 'SEDEX', papelito_correios_service_type( '03220', 'Servico expresso' ) );
papelito_assert( 'PAC type', 'PAC', papelito_correios_service_type_from_name( 'PAC PC CONTRATO AG' ) );
papelito_assert( 'SEDEX type', 'SEDEX', papelito_correios_service_type_from_name( 'SEDEX PC CONTRATO AG' ) );
papelito_assert( 'Packet ignored', '', papelito_correios_service_type_from_name( 'PACKET STANDARD DISTRIBUICAO' ) );
papelito_assert( 'Empacotamento ignored', '', papelito_correios_service_type_from_name( 'EMPACOTAMENTO DE ITENS' ) );

echo "Scenario 3: checkout service selection keeps only official PAC/SEDEX before quote\n";
$services = papelito_correios_select_checkout_services(
	array(
		array(
			'service' => 'PAC',
			'code'    => '04000',
			'name'    => 'PAC PC CONTRATO AG',
		),
		array(
			'service' => 'PAC',
			'code'    => '03298',
			'name'    => 'PAC CONTRATO AG',
		),
		array(
			'service' => 'SEDEX',
			'code'    => '04090',
			'name'    => 'SEDEX PC CONTRATO AG',
		),
		array(
			'service' => 'SEDEX',
			'code'    => '03220',
			'name'    => 'SEDEX CONTRATO AG',
		),
		array(
			'service' => 'MINI',
			'code'    => '04227',
			'name'    => 'MINI ENVIOS',
		),
	)
);

papelito_assert( 'only PAC and SEDEX are selected', 2, count( $services ) );
papelito_assert( 'official PAC code is selected', '03298', $services[0]['code'] ?? '' );
papelito_assert( 'official SEDEX code is selected', '03220', $services[1]['code'] ?? '' );

echo "Scenario 4: checkout service selection falls back by modality name\n";
$services = papelito_correios_select_checkout_services(
	array(
		array(
			'service' => 'PAC',
			'code'    => '04000',
			'name'    => 'PAC PC CONTRATO AG',
		),
		array(
			'service' => 'SEDEX',
			'code'    => '04090',
			'name'    => 'SEDEX PC CONTRATO AG',
		),
	)
);

papelito_assert( 'fallback PAC code is selected', '04000', $services[0]['code'] ?? '' );
papelito_assert( 'fallback SEDEX code is selected', '04090', $services[1]['code'] ?? '' );

echo "Scenario 5: checkout service selection handles missing PAC or SEDEX\n";
$services = papelito_correios_select_checkout_services(
	array(
		array(
			'service' => 'SEDEX',
			'code'    => '03220',
			'name'    => 'SEDEX CONTRATO AG',
		),
	)
);
papelito_assert( 'only SEDEX remains when PAC is missing', 1, count( $services ) );
papelito_assert( 'SEDEX survives missing PAC', '03220', $services[0]['code'] ?? '' );

$services = papelito_correios_select_checkout_services(
	array(
		array(
			'service' => 'PAC',
			'code'    => '03298',
			'name'    => 'PAC CONTRATO AG',
		),
	)
);
papelito_assert( 'only PAC remains when SEDEX is missing', 1, count( $services ) );
papelito_assert( 'PAC survives missing SEDEX', '03298', $services[0]['code'] ?? '' );

echo "Scenario 6: best option per modality uses lowest price first\n";
$best = papelito_correios_select_best_quoted_options(
	array(
		array(
			'service'       => 'PAC',
			'code'          => '04669',
			'name'          => 'PAC CONTRATO AGENCIA',
			'price'         => 22.51,
			'delivery_time' => 5,
		),
		array(
			'service'       => 'PAC',
			'code'          => '03298',
			'name'          => 'PAC CONTRATO AG',
			'price'         => 19.37,
			'delivery_time' => 5,
		),
		array(
			'service'       => 'PAC',
			'code'          => '04000',
			'name'          => 'PAC PC CONTRATO AG',
			'price'         => 18.91,
			'delivery_time' => 5,
		),
		array(
			'service'       => 'SEDEX',
			'code'          => '03220',
			'name'          => 'SEDEX CONTRATO AG',
			'price'         => 12.45,
			'delivery_time' => 1,
		),
		array(
			'service'       => 'SEDEX',
			'code'          => '04090',
			'name'          => 'SEDEX PC CONTRATO AG',
			'price'         => 12.21,
			'delivery_time' => 1,
		),
	)
);

$best_by_service = array();
foreach ( $best as $option ) {
	$best_by_service[ $option['service'] ] = $option;
}

papelito_assert( 'best PAC is cheapest', '04000', $best_by_service['PAC']['code'] ?? '' );
papelito_assert( 'best SEDEX is cheapest', '04090', $best_by_service['SEDEX']['code'] ?? '' );

echo "Scenario 7: tie on price uses shortest delivery time\n";
$best = papelito_correios_select_best_quoted_options(
	array(
		array(
			'service'       => 'SEDEX',
			'code'          => 'slow',
			'name'          => 'SEDEX CONTRATO AG',
			'price'         => 10.0,
			'delivery_time' => 3,
		),
		array(
			'service'       => 'SEDEX',
			'code'          => 'fast',
			'name'          => 'SEDEX PC CONTRATO AG',
			'price'         => 10.0,
			'delivery_time' => 1,
		),
	)
);

papelito_assert( 'best tied SEDEX is fastest', 'fast', $best[0]['code'] ?? '' );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
