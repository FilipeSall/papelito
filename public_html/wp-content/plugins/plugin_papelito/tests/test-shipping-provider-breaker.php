<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Disjuntor da Braspress: abre, meia-abre, fecha e nunca encosta nos Correios.
 *
 * Toda a máquina de estados é exercitada com relógio injetado, porque um
 * disjuntor testado com `time()` real ou dorme na suíte ou não prova nada. Os
 * dois cenários que mais importam não são a abertura: são o de que rota não
 * atendida e credencial recusada **não** abrem o disjuntor — abriria a cada
 * destino fora da malha —, e o de que os Correios seguem cotando com a Braspress
 * aberta.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'MINUTE_IN_SECONDS', 60 );

const BREAKER_TEST_VENDOR_ID    = 2163;
const BREAKER_TEST_OTHER_VENDOR = 4477;
const BREAKER_TEST_CORREIOS     = 'correios';
const BREAKER_TEST_BRASPRESS    = 'braspress';
const BREAKER_TEST_START        = 1789000000;

$GLOBALS['breaker_test_options'] = array();

/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Nenhum listener é registrado neste teste. */
function add_action( mixed ...$args ): bool { return true; }
/** Nenhum listener é executado neste teste. */
function do_action( mixed ...$args ): bool { return true; }

/** Lê a option do armazenamento em memória do teste. */
function get_option( mixed $option, mixed $default_value = false ): mixed {
	return array_key_exists( (string) $option, $GLOBALS['breaker_test_options'] )
		? $GLOBALS['breaker_test_options'][ (string) $option ]
		: $default_value;
}

/** Grava a option no armazenamento em memória do teste. */
function update_option( mixed $option, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['breaker_test_options'][ (string) $option ] = $value;

	return true;
}

/** Remove a option do armazenamento em memória do teste. */
function delete_option( mixed $option ): bool {
	unset( $GLOBALS['breaker_test_options'][ (string) $option ] );

	return true;
}

require_once dirname( __DIR__ ) . '/includes/shipping_breaker.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function breaker_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Zera o armazenamento entre cenários. */
function breaker_reset(): void {
	$GLOBALS['breaker_test_options'] = array();
}

/** Registra um desfecho da Braspress no instante informado. */
function breaker_record( string $outcome, int $now, int $vendor_id = BREAKER_TEST_VENDOR_ID ): void {
	papelito_shipping_breaker_record( BREAKER_TEST_BRASPRESS, $vendor_id, $outcome, $now );
}

/** Registra uma sequência de desfechos iguais no mesmo instante. */
function breaker_record_many( string $outcome, int $times, int $now, int $vendor_id = BREAKER_TEST_VENDOR_ID ): void {
	for ( $i = 0; $i < $times; $i++ ) {
		breaker_record( $outcome, $now, $vendor_id );
	}
}

echo "Cenário 1: falhas seguidas de indisponibilidade abrem o disjuntor\n";
breaker_reset();

breaker_record_many( 'timeout', PAPELITO_SHIPPING_BREAKER_THRESHOLD - 1, BREAKER_TEST_START );

breaker_assert(
	'Abaixo do limite o disjuntor continua fechado',
	'closed' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
	&& papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);

breaker_record( 'provider_5xx', BREAKER_TEST_START );

breaker_assert(
	'No limite o disjuntor abre',
	'open' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);
breaker_assert(
	'Aberto, a Braspress deixa de ser chamada',
	false === papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);

echo "\nCenário 2: o disjuntor meia-abre depois do descanso e libera uma sonda\n";
breaker_reset();
breaker_record_many( 'timeout', PAPELITO_SHIPPING_BREAKER_THRESHOLD, BREAKER_TEST_START );

$almost = BREAKER_TEST_START + PAPELITO_SHIPPING_BREAKER_COOLDOWN - 1;
$after  = BREAKER_TEST_START + PAPELITO_SHIPPING_BREAKER_COOLDOWN;

breaker_assert(
	'Um segundo antes do descanso terminar ainda está aberto',
	'open' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $almost )
	&& false === papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $almost )
);
breaker_assert(
	'Terminado o descanso, o disjuntor meia-abre',
	'half_open' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after )
);
breaker_assert(
	'Meia-aberto, uma sonda é liberada',
	true === papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after )
);
breaker_assert(
	'A sonda é uma só: a chamada seguinte volta a ser barrada',
	false === papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after )
);

echo "\nCenário 3: a sonda bem-sucedida fecha o disjuntor\n";
breaker_reset();
breaker_record_many( 'timeout', PAPELITO_SHIPPING_BREAKER_THRESHOLD, BREAKER_TEST_START );

papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after );
breaker_record( 'success', $after );

breaker_assert(
	'Uma cotação válida fecha o disjuntor',
	'closed' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after )
);
breaker_assert(
	'Fechado, a Braspress volta a ser chamada sem limite de sonda',
	papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after )
	&& papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after )
);

echo "\nCenário 4: a sonda que falha reabre e reinicia o descanso\n";
breaker_reset();
breaker_record_many( 'timeout', PAPELITO_SHIPPING_BREAKER_THRESHOLD, BREAKER_TEST_START );

papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after );
breaker_record( 'network_error', $after );

breaker_assert(
	'A sonda recusada reabre o disjuntor',
	'open' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after )
);
breaker_assert(
	'O descanso recomeça do instante da sonda, não da primeira abertura',
	'open' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after + PAPELITO_SHIPPING_BREAKER_COOLDOWN - 1 )
	&& 'half_open' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, $after + PAPELITO_SHIPPING_BREAKER_COOLDOWN )
);

echo "\nCenário 5: só indisponibilidade conta; regra de negócio e credencial não\n";
breaker_reset();

breaker_record_many( 'not_available', PAPELITO_SHIPPING_BREAKER_THRESHOLD * 3, BREAKER_TEST_START );
breaker_assert(
	'Destino fora da malha nunca abre o disjuntor',
	'closed' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);

breaker_reset();
breaker_record_many( 'authentication_error', PAPELITO_SHIPPING_BREAKER_THRESHOLD * 3, BREAKER_TEST_START );
breaker_assert(
	'Credencial recusada é tratada pelo estado da integração, não pelo disjuntor',
	'closed' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);

breaker_reset();
breaker_record_many( 'validation_error', PAPELITO_SHIPPING_BREAKER_THRESHOLD * 3, BREAKER_TEST_START );
breaker_assert(
	'Erro de dado local não desliga a transportadora',
	'closed' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);

echo "\nCenário 6: uma cotação boa no meio zera a contagem\n";
breaker_reset();

breaker_record_many( 'timeout', PAPELITO_SHIPPING_BREAKER_THRESHOLD - 1, BREAKER_TEST_START );
breaker_record( 'success', BREAKER_TEST_START );
breaker_record_many( 'timeout', PAPELITO_SHIPPING_BREAKER_THRESHOLD - 1, BREAKER_TEST_START );

breaker_assert(
	'O disjuntor abre com falhas seguidas, não com falhas somadas ao longo do dia',
	'closed' === papelito_shipping_breaker_state( BREAKER_TEST_BRASPRESS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);

echo "\nCenário 7: o disjuntor não atravessa provider nem vendor\n";
breaker_reset();

breaker_record_many( 'timeout', PAPELITO_SHIPPING_BREAKER_THRESHOLD * 2, BREAKER_TEST_START );

breaker_assert(
	'Com a Braspress aberta, os Correios continuam liberados',
	papelito_shipping_breaker_allows( BREAKER_TEST_CORREIOS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);
breaker_assert(
	'Os Correios nunca entram em estado protegido',
	'closed' === papelito_shipping_breaker_state( BREAKER_TEST_CORREIOS, BREAKER_TEST_VENDOR_ID, BREAKER_TEST_START )
);
breaker_assert(
	'A conta quebrada de uma loja não derruba a Braspress da outra',
	papelito_shipping_breaker_allows( BREAKER_TEST_BRASPRESS, BREAKER_TEST_OTHER_VENDOR, BREAKER_TEST_START )
);

echo "\nCenário 8: os Correios não registram estado nem quando falham\n";
breaker_reset();

papelito_shipping_breaker_record( BREAKER_TEST_CORREIOS, BREAKER_TEST_VENDOR_ID, 'timeout', BREAKER_TEST_START );

breaker_assert(
	'Falha dos Correios não cria estado de disjuntor',
	array() === $GLOBALS['breaker_test_options']
);

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
