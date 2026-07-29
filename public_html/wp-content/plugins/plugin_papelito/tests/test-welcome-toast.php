<?php
/**
 * Standalone regression test for the one-shot welcome toast claim.
 *
 * Usage: php tests/test-welcome-toast.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pap_meta']    = array();
$GLOBALS['pap_users']   = array();
$GLOBALS['pap_context'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function is_user_logged_in() { return true; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return (string) $v; }
function get_userdata( $id ) { return $GLOBALS['pap_users'][ $id ] ?? false; }
function wp_get_current_user() { return false; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['pap_meta'][ $id ][ $key ] ?? ''; }
function delete_user_meta( $id, $key ) { unset( $GLOBALS['pap_meta'][ $id ][ $key ] ); return true; }

/** add_user_meta com $unique = true nao sobrescreve meta existente. */
function add_user_meta( $id, $key, $val, $unique = false ) {
	if ( $unique && isset( $GLOBALS['pap_meta'][ $id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['pap_meta'][ $id ][ $key ] = $val;
	return 1;
}

/** update_user_meta com $prev_value so escreve quando o valor atual bate (compare-and-swap). */
function update_user_meta( $id, $key, $val, $prev_value = '' ) {
	if ( '' !== $prev_value && ( $GLOBALS['pap_meta'][ $id ][ $key ] ?? null ) !== $prev_value ) {
		return false;
	}
	$GLOBALS['pap_meta'][ $id ][ $key ] = $val;
	return true;
}

function papelito_company_context( $user_id ) { return $GLOBALS['pap_context'][ $user_id ] ?? array( 'onboardingStatus' => 'none' ); }

class WP_User {
	public $ID; public $user_email; public $display_name; public $roles = array( 'customer' );
	function __construct( $id, $email = 'u@x.com', $display_name = 'Filipe Salles' ) { $this->ID = $id; $this->user_email = $email; $this->display_name = $display_name; }
	function exists() { return true; }
}
class WP_Error {
	public $code; public $message; public $data;
	function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_REST_Request {}
class WP_REST_Response { public $data; public $status; function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } }

require __DIR__ . '/../includes/auth_endpoints.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n";
	}
}

/**
 * Prepara um usuario com estado controlado.
 *
 * @param int    $id
 * @param string $toast    Valor da meta do toast ('' = ausente, como usuario legado).
 * @param string $email    Status de confirmacao de e-mail.
 * @param string $approval onboardingStatus do contexto B2B.
 */
function papelito_seed_user( int $id, string $toast, string $email, string $approval, string $first_name = 'Filipe' ): void {
	$GLOBALS['pap_users'][ $id ] = new WP_User( $id );
	$GLOBALS['pap_meta'][ $id ]  = array( 'first_name' => $first_name );
	if ( '' !== $toast ) {
		$GLOBALS['pap_meta'][ $id ][ PAPELITO_WELCOME_TOAST_META ] = $toast;
	}
	if ( '' !== $email ) {
		$GLOBALS['pap_meta'][ $id ]['papelito_email_verification_status'] = $email;
	}
	$GLOBALS['pap_context'][ $id ] = array( 'onboardingStatus' => $approval );
}

echo "case1: arme e idempotente\n";
$GLOBALS['pap_meta'][1] = array();
papelito_auth_welcome_toast_arm( 1 );
papelito_assert( 'case1: arma em pending', 'pending', $GLOBALS['pap_meta'][1][ PAPELITO_WELCOME_TOAST_META ] );
papelito_auth_welcome_toast_arm( 1 );
papelito_assert( 'case1: rearmar mantem pending', 'pending', $GLOBALS['pap_meta'][1][ PAPELITO_WELCOME_TOAST_META ] );
$GLOBALS['pap_meta'][1][ PAPELITO_WELCOME_TOAST_META ] = 'shown';
papelito_auth_welcome_toast_arm( 1 );
papelito_assert( 'case1: arme sobre shown nao rearma', 'shown', $GLOBALS['pap_meta'][1][ PAPELITO_WELCOME_TOAST_META ] );

echo "case2: e-mail ainda nao confirmado\n";
papelito_seed_user( 2, 'pending', 'pending', 'complete' );
$res = papelito_auth_welcome_toast_claim( 2 );
papelito_assert( 'case2: nao exibe', false, $res['shown'] );
papelito_assert( 'case2: meta segue pending', 'pending', $GLOBALS['pap_meta'][2][ PAPELITO_WELCOME_TOAST_META ] );
papelito_assert( 'case2: sem shown_at', false, isset( $GLOBALS['pap_meta'][2]['papelito_welcome_toast_shown_at'] ) );

echo "case3: confirmado mas conta ainda nao aprovada\n";
papelito_seed_user( 3, 'pending', 'verified', 'pending' );
$res = papelito_auth_welcome_toast_claim( 3 );
papelito_assert( 'case3: nao exibe', false, $res['shown'] );
papelito_assert( 'case3: meta segue pending', 'pending', $GLOBALS['pap_meta'][3][ PAPELITO_WELCOME_TOAST_META ] );

echo "case3b: onboarding B2B incompleto\n";
papelito_seed_user( 31, 'pending', 'verified', 'incomplete' );
papelito_assert( 'case3b: nao exibe', false, papelito_auth_welcome_toast_claim( 31 )['shown'] );
papelito_assert( 'case3b: meta segue pending', 'pending', $GLOBALS['pap_meta'][31][ PAPELITO_WELCOME_TOAST_META ] );

echo "case4: confirmado + aprovado exibe uma unica vez\n";
papelito_seed_user( 4, 'pending', 'verified', 'complete' );
$res = papelito_auth_welcome_toast_claim( 4 );
papelito_assert( 'case4: exibe', true, $res['shown'] );
papelito_assert( 'case4: primeiro nome', 'Filipe', $res['firstName'] );
papelito_assert( 'case4: meta vira shown', 'shown', $GLOBALS['pap_meta'][4][ PAPELITO_WELCOME_TOAST_META ] );
papelito_assert( 'case4: grava shown_at', true, isset( $GLOBALS['pap_meta'][4]['papelito_welcome_toast_shown_at'] ) );

echo "case5: segundo claim (refresh, novo login, outro dispositivo)\n";
$res = papelito_auth_welcome_toast_claim( 4 );
papelito_assert( 'case5: nao exibe de novo', false, $res['shown'] );
papelito_assert( 'case5: sem nome vazando', '', $res['firstName'] );
papelito_assert( 'case5: meta continua shown', 'shown', $GLOBALS['pap_meta'][4][ PAPELITO_WELCOME_TOAST_META ] );

echo "case6: usuario legado (sem a meta) nunca e elegivel\n";
papelito_seed_user( 6, '', 'verified', 'complete' );
papelito_assert( 'case6: nao exibe', false, papelito_auth_welcome_toast_claim( 6 )['shown'] );
papelito_assert( 'case6: nao cria meta', false, isset( $GLOBALS['pap_meta'][6][ PAPELITO_WELCOME_TOAST_META ] ) );

echo "case7: legado sem meta de e-mail tambem nao e elegivel\n";
papelito_seed_user( 7, '', '', 'complete' );
papelito_assert( 'case7: nao exibe', false, papelito_auth_welcome_toast_claim( 7 )['shown'] );

echo "case8: compare-and-swap protege contra claim concorrente\n";
papelito_seed_user( 8, 'pending', 'verified', 'complete' );
// Simula outra aba vencendo a corrida entre a leitura e a escrita deste chamador.
$GLOBALS['pap_meta'][8][ PAPELITO_WELCOME_TOAST_META ] = 'shown';
papelito_assert( 'case8: perdedor nao exibe', false, papelito_auth_welcome_toast_claim( 8 )['shown'] );
papelito_assert( 'case8: CAS com prev errado nao escreve', false, update_user_meta( 8, PAPELITO_WELCOME_TOAST_META, 'pending', 'pending' ) );
papelito_assert( 'case8: valor preservado', 'shown', $GLOBALS['pap_meta'][8][ PAPELITO_WELCOME_TOAST_META ] );

echo "case9: fallback de nome usa display_name\n";
papelito_seed_user( 9, 'pending', 'verified', 'complete', '' );
$GLOBALS['pap_users'][9] = new WP_User( 9, 'u@x.com', 'Maria Souza' );
papelito_assert( 'case9: primeira palavra do display_name', 'Maria', papelito_auth_welcome_toast_claim( 9 )['firstName'] );

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( $failures === 0 ? 0 : 1 );
