<?php
/**
 * Standalone regression test do rate limit por identidade.
 *
 * Cobre a causa raiz do teto global: endpoint consumido pelo proxy Next ve sempre o mesmo
 * REMOTE_ADDR, entao chavear por IP transformava a cota num limite do marketplace inteiro.
 *
 * Usage: php tests/test-rate-limit-identity.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pap_transients']  = array();
$GLOBALS['pap_current_user'] = 0;

function get_transient( string $key ) {
	$entry = $GLOBALS['pap_transients'][ $key ] ?? null;

	if ( null === $entry ) {
		return false;
	}

	if ( $entry['expires_at'] <= $GLOBALS['pap_now'] ) {
		unset( $GLOBALS['pap_transients'][ $key ] );

		return false;
	}

	return $entry['value'];
}
function set_transient( string $key, $value, int $window ): bool {
	$GLOBALS['pap_transients'][ $key ] = array( 'value' => $value, 'expires_at' => $GLOBALS['pap_now'] + $window );

	return true;
}
function get_current_user_id(): int { return $GLOBALS['pap_current_user']; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }

$GLOBALS['pap_now'] = 1000;

require __DIR__ . '/../includes/support.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";

		return;
	}

	++$failures;
	echo '  FAIL: ' . $label . ' — esperado ' . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

/* --- caso 1: usuarios distintos nao compartilham balde, mesmo com o mesmo IP do proxy --- */
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

$GLOBALS['pap_current_user'] = 7;
for ( $i = 0; $i < 3; $i++ ) {
	papelito_rate_limit( 'upload', papelito_rate_limit_identity(), 3, 60 );
}
papelito_assert( 'usuario 7 estoura na 4a', false, papelito_rate_limit( 'upload', papelito_rate_limit_identity(), 3, 60 ) );

$GLOBALS['pap_current_user'] = 8;
papelito_assert( 'usuario 8 nao herda a cota do 7', true, papelito_rate_limit( 'upload', papelito_rate_limit_identity(), 3, 60 ) );

/* --- caso 2: baldes distintos nao se misturam --- */
$GLOBALS['pap_current_user'] = 7;
papelito_assert( 'outro bucket comeca zerado', true, papelito_rate_limit( 'confirm', papelito_rate_limit_identity(), 3, 60 ) );

/* --- caso 3: a janela expira --- */
$GLOBALS['pap_now'] += 61;
papelito_assert( 'apos a janela o usuario 7 volta a passar', true, papelito_rate_limit( 'upload', papelito_rate_limit_identity(), 3, 60 ) );

/* --- caso 4: anonimo com escopo proprio nao cai no IP compartilhado --- */
$GLOBALS['pap_current_user'] = 0;
papelito_assert(
	'escopo explicito vence o IP',
	'app:abc',
	papelito_rate_limit_identity( 'app:abc' )
);
papelito_assert(
	'sem escopo e sem sessao usa o IP',
	'ip:10.0.0.1',
	papelito_rate_limit_identity()
);

/* --- caso 5: dois anonimos com escopos distintos sao independentes --- */
for ( $i = 0; $i < 2; $i++ ) {
	papelito_rate_limit( 'ticket', 'app:um', 2, 60 );
}
papelito_assert( 'candidatura "um" estoura', false, papelito_rate_limit( 'ticket', 'app:um', 2, 60 ) );
papelito_assert( 'candidatura "dois" segue livre', true, papelito_rate_limit( 'ticket', 'app:dois', 2, 60 ) );

/* --- caso 6: sessao autenticada ignora o escopo de fallback --- */
$GLOBALS['pap_current_user'] = 42;
papelito_assert( 'usuario logado vence o escopo anonimo', 'user:42', papelito_rate_limit_identity( 'app:abc' ) );

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAIL\n";
exit( 0 === $failures ? 0 : 1 );
