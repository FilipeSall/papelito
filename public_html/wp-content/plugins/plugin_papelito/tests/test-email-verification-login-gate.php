<?php
/**
 * Regressao: conta com e-mail pendente e indistinguivel de conta verificada ate a senha conferir.
 *
 * Senha errada precisa trazer o mesmo codigo, a mesma mensagem e o mesmo numero de hashes da conta
 * verificada; senao o texto ou o tempo de resposta revelam que o endereco tem cadastro pendente.
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
const GATE_TEST_NOT_VERIFIED     = 'papelito_email_not_verified';
const GATE_TEST_AUTHENTICATED    = 'authenticated';
const GATE_TEST_PENDING_EMAIL    = 'pendente@example.test';
const GATE_TEST_VERIFIED_EMAIL   = 'verificada@example.test';

$GLOBALS['pap_hooks']           = array();
$GLOBALS['pap_meta']            = array();
$GLOBALS['pap_users']           = array();
$GLOBALS['pap_password_checks'] = 0;

function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['pap_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}
function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
	add_filter( $hook, $callback, $priority, $accepted_args );
}
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$registered = $GLOBALS['pap_hooks'][ $hook ] ?? array();
	ksort( $registered );

	foreach ( $registered as $callbacks ) {
		foreach ( $callbacks as list( $callback, $accepted_args ) ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}

	return $value;
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['pap_meta'][ $user_id ][ $key ] ?? ''; }
function wp_hash_password( string $password ): string { return 'hash:' . $password; }
function wp_check_password( string $password, string $hash, int $user_id = 0 ): bool {
	++$GLOBALS['pap_password_checks'];

	return wp_hash_password( $password ) === $hash;
}
function get_user_by( string $field, string $value ): WP_User|false {
	foreach ( $GLOBALS['pap_users'] as $user ) {
		if ( ( 'login' === $field ? $user->user_login : $user->user_email ) === $value ) {
			return $user;
		}
	}

	return false;
}

class PapelitoGateTestUser {
	private array $attributes;

	public function __construct( int $id, string $email ) {
		$this->attributes = array(
			'ID'         => $id,
			'user_login' => $email,
			'user_email' => $email,
			'user_pass'  => wp_hash_password( GATE_TEST_CORRECT_PASSWORD ),
		);
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

/**
 * Reproduz `wp_authenticate_username_password()` do core 6.9: busca por login, filtro, senha.
 */
function core_authenticate_username_password( $user, string $username, string $password ) {
	if ( $user instanceof WP_User ) {
		return $user;
	}

	$found = get_user_by( 'login', $username );
	if ( ! $found ) {
		return new WP_Error( 'invalid_username', 'The username is not registered on this site.' );
	}

	return core_check_credentials( $found, $password, 'The password you entered for the username ' . $username . ' is incorrect.' );
}

/**
 * Reproduz `wp_authenticate_email_password()` do core 6.9: roda mesmo depois de um WP_Error.
 */
function core_authenticate_email_password( $user, string $email, string $password ) {
	if ( $user instanceof WP_User || ! str_contains( $email, '@' ) ) {
		return $user;
	}

	$found = get_user_by( 'email', $email );
	if ( ! $found ) {
		return new WP_Error( 'invalid_email', 'Unknown email address. Check again or try your username.' );
	}

	return core_check_credentials( $found, $password, 'The password you entered for the email address ' . $email . ' is incorrect.' );
}

/**
 * Trecho comum do core: `wp_authenticate_user` antes de conferir a senha.
 */
function core_check_credentials( WP_User $user, string $password, string $incorrect_message ) {
	$user = apply_filters( 'wp_authenticate_user', $user, $password );
	if ( is_wp_error( $user ) ) {
		return $user;
	}

	return wp_check_password( $password, $user->user_pass, $user->ID ) ? $user : new WP_Error( 'incorrect_password', $incorrect_message );
}

add_filter( 'authenticate', 'core_authenticate_username_password', 20, 3 );
add_filter( 'authenticate', 'core_authenticate_email_password', 20, 3 );

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
 * Uma tentativa pela cadeia `authenticate`, como o `wp_authenticate()` do plugin JWT.
 *
 * @return array{outcome:string,message:string,hashes:int}
 */
function login_attempt( string $email, string $password ): array {
	$GLOBALS['pap_password_checks'] = 0;
	$result                         = apply_filters( 'authenticate', null, $email, $password );

	return array(
		'outcome' => $result instanceof WP_Error ? $result->get_error_code() : GATE_TEST_AUTHENTICATED,
		'message' => $result instanceof WP_Error ? str_replace( $email, '<email>', $result->get_error_message() ) : '',
		'hashes'  => $GLOBALS['pap_password_checks'],
	);
}

$GLOBALS['pap_users'] = array(
	new WP_User( 1, GATE_TEST_PENDING_EMAIL ),
	new WP_User( 2, GATE_TEST_VERIFIED_EMAIL ),
	new WP_User( 3, 'legado@example.test' ),
	new WP_User( 4, 'estranho@example.test' ),
);
$GLOBALS['pap_meta'][1][ GATE_TEST_STATUS_META ] = 'pending';
$GLOBALS['pap_meta'][2][ GATE_TEST_STATUS_META ] = 'verified';
$GLOBALS['pap_meta'][4][ GATE_TEST_STATUS_META ] = 'status-inesperado';

$verified_wrong = login_attempt( GATE_TEST_VERIFIED_EMAIL, GATE_TEST_WRONG_PASSWORD );
$pending_wrong  = login_attempt( GATE_TEST_PENDING_EMAIL, GATE_TEST_WRONG_PASSWORD );

gate_assert( 'conta verificada com senha errada e recusada', 'incorrect_password', $verified_wrong['outcome'] );
gate_assert( 'conta pendente com senha errada recebe o mesmo codigo da verificada', $verified_wrong['outcome'], $pending_wrong['outcome'] );
gate_assert( 'conta pendente com senha errada recebe a mesma mensagem da verificada', $verified_wrong['message'], $pending_wrong['message'] );
gate_assert( 'conta pendente com senha errada custa os mesmos hashes da verificada', $verified_wrong['hashes'], $pending_wrong['hashes'] );
gate_assert( 'conta pendente com senha certa e orientada a confirmar o e-mail', GATE_TEST_NOT_VERIFIED, login_attempt( GATE_TEST_PENDING_EMAIL, GATE_TEST_CORRECT_PASSWORD )['outcome'] );
gate_assert( 'status desconhecido com senha certa continua barrado', GATE_TEST_NOT_VERIFIED, login_attempt( 'estranho@example.test', GATE_TEST_CORRECT_PASSWORD )['outcome'] );
gate_assert( 'conta verificada com senha certa entra', GATE_TEST_AUTHENTICATED, login_attempt( GATE_TEST_VERIFIED_EMAIL, GATE_TEST_CORRECT_PASSWORD )['outcome'] );
gate_assert( 'conta legada sem meta com senha certa entra', GATE_TEST_AUTHENTICATED, login_attempt( 'legado@example.test', GATE_TEST_CORRECT_PASSWORD )['outcome'] );
gate_assert( 'e-mail desconhecido segue com o erro do core', 'invalid_email', login_attempt( 'ninguem@example.test', GATE_TEST_CORRECT_PASSWORD )['outcome'] );

echo 0 === $failures ? "\nOK\n" : "\n{$failures} FALHA(S)\n";
exit( 0 === $failures ? 0 : 1 );
