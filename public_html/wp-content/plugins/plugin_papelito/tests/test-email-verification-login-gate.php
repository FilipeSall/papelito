<?php
/**
 * Regressao: o gate de e-mail pendente so responde a quem acertou a senha.
 *
 * `wp_authenticate_user` roda antes da conferencia de senha do core; avisar "confirme seu e-mail"
 * para senha errada revelava que o endereco tem conta pendente.
 *
 * Usage: php public_html/wp-content/plugins/plugin_papelito/tests/test-email-verification-login-gate.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

const GATE_TEST_CORRECT_PASSWORD = 'SenhaCerta123';
const GATE_TEST_WRONG_PASSWORD   = 'SenhaErrada999';
const GATE_TEST_STATUS_META      = 'papelito_email_verification_status';
const GATE_TEST_INCORRECT        = 'incorrect_password';
const GATE_TEST_NOT_VERIFIED     = 'papelito_email_not_verified';
const GATE_TEST_AUTHENTICATED    = 'authenticated';

$GLOBALS['pap_hooks'] = array();
$GLOBALS['pap_meta']  = array();

function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['pap_hooks'][ $hook ][] = array( $callback, $accepted_args );
}
function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
	add_filter( $hook, $callback, $priority, $accepted_args );
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['pap_meta'][ $user_id ][ $key ] ?? ''; }
function wp_hash_password( string $password ): string { return 'hash:' . $password; }
function wp_check_password( string $password, string $hash, int $user_id = 0 ): bool { return wp_hash_password( $password ) === $hash; }

class PapelitoGateTestUser {
	private array $attributes;

	public function __construct( int $id, string $password_hash ) {
		$this->attributes = array( 'ID' => $id, 'user_pass' => $password_hash );
	}

	public function __get( string $name ): mixed {
		return $this->attributes[ $name ] ?? null;
	}
}
class PapelitoGateTestError {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}
class_alias( PapelitoGateTestUser::class, 'WP_User' );
class_alias( PapelitoGateTestError::class, 'WP_Error' );

require_once __DIR__ . '/../includes/auth_endpoints.php';

$failures = 0;
function gate_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";

		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

/**
 * Reproduz `wp_authenticate_username_password()` do core: filtro com `accepted_args`, depois senha.
 */
function login_simulado( int $user_id, string $password ): string {
	$user = new WP_User( $user_id, wp_hash_password( GATE_TEST_CORRECT_PASSWORD ) );

	foreach ( $GLOBALS['pap_hooks']['wp_authenticate_user'] ?? array() as list( $callback, $accepted_args ) ) {
		$user = $callback( ...array_slice( array( $user, $password ), 0, $accepted_args ) );
	}

	if ( is_wp_error( $user ) ) {
		return $user->get_error_code();
	}

	return wp_check_password( $password, $user->user_pass, $user->ID ) ? GATE_TEST_AUTHENTICATED : GATE_TEST_INCORRECT;
}

$GLOBALS['pap_meta'][1][ GATE_TEST_STATUS_META ] = 'pending';
$GLOBALS['pap_meta'][2][ GATE_TEST_STATUS_META ] = 'verified';
$GLOBALS['pap_meta'][4][ GATE_TEST_STATUS_META ] = 'status-inesperado';

gate_assert( 'conta pendente com senha errada recebe o mesmo erro de qualquer senha errada', GATE_TEST_INCORRECT, login_simulado( 1, GATE_TEST_WRONG_PASSWORD ) );
gate_assert( 'conta pendente com senha certa e orientada a confirmar o e-mail', GATE_TEST_NOT_VERIFIED, login_simulado( 1, GATE_TEST_CORRECT_PASSWORD ) );
gate_assert( 'status desconhecido com senha certa continua barrado', GATE_TEST_NOT_VERIFIED, login_simulado( 4, GATE_TEST_CORRECT_PASSWORD ) );
gate_assert( 'conta verificada com senha certa entra', GATE_TEST_AUTHENTICATED, login_simulado( 2, GATE_TEST_CORRECT_PASSWORD ) );
gate_assert( 'conta verificada com senha errada e recusada', GATE_TEST_INCORRECT, login_simulado( 2, GATE_TEST_WRONG_PASSWORD ) );
gate_assert( 'conta legada sem meta com senha certa entra', GATE_TEST_AUTHENTICATED, login_simulado( 3, GATE_TEST_CORRECT_PASSWORD ) );

echo 0 === $failures ? "\nOK\n" : "\n{$failures} FALHA(S)\n";
exit( 0 === $failures ? 0 : 1 );
