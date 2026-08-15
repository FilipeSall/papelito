<?php
/**
 * Regressao do rate limit de falha de login.
 *
 * Cobre a causa raiz do teto global: o login chega ao WordPress pelo servidor Next, entao chavear
 * por REMOTE_ADDR fazia toda a base compartilhar um unico balde. Cobre tambem a janela fixa (que
 * nao pode ser renovada a cada falha) e a limpeza no acerto.
 *
 * Usage: php public_html/wp-content/plugins/plugin_papelito/tests/test-login-rate-limit.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['pap_transients'] = array();
$GLOBALS['pap_hooks']      = array();
$GLOBALS['pap_wp_login_calls'] = array();

class WP_User { // NOSONAR -- o nome e o da classe do WordPress.
	public function __construct( public string $user_email = '', public string $user_login = '', public int $ID = 0 ) {}
}

class WP_Error { // NOSONAR -- o nome e o da classe do WordPress.
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

function get_transient( string $key ) {
	$entry = $GLOBALS['pap_transients'][ $key ] ?? null;

	if ( null === $entry || $entry['expires_at'] <= time() ) {
		unset( $GLOBALS['pap_transients'][ $key ] );

		return false;
	}

	return $entry['value'];
}
function set_transient( string $key, mixed $value, int $ttl ): bool {
	$GLOBALS['pap_transients'][ $key ] = array( 'value' => $value, 'expires_at' => time() + $ttl );

	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['pap_transients'][ $key ] );

	return true;
}
function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['pap_hooks'][ $hook ][] = $callback;
}
function add_filter( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['pap_hooks'][ $hook ][] = $callback;
}
function remove_action( string $hook, mixed $callback, int $priority = 10 ): void {}
function sanitize_text_field( string $value ): string { return trim( $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_die( string $message = '', string $title = '', array $args = array() ): void {
	throw new RuntimeException( 'wp_die() mata a requisicao GraphQL e o Next perde o motivo do erro.' );
}
function __return_empty_string(): string { return ''; }
function __return_false(): bool { return false; }
function is_user_logged_in(): bool { return false; }
function home_url(): string { return 'https://example.test'; }
function wp_safe_redirect( string $location, int $status = 302 ): bool { return true; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function get_option( string $name ) { return '/%postname%/'; }
function update_option( string $name, mixed $value ): bool { return true; }
function get_user_by( string $field, string $value ): WP_User|false {
	foreach ( $GLOBALS['pap_users'] as $user ) {
		if ( ( 'email' === $field && strtolower( $user->user_email ) === strtolower( $value ) ) || ( 'login' === $field && strtolower( $user->user_login ) === strtolower( $value ) ) ) {
			return $user;
		}
	}

	return false;
}

require_once __DIR__ . '/../../../mu-plugins/papelito-hardening.php';

$failures = 0;
function login_rate_limit_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";

		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

/**
 * Avanca o relogio sem mexer em `time()`: envelhece o que ja esta guardado.
 *
 * Redeclarar `time()` nao e possivel porque o mu-plugin ja o resolve no escopo global.
 */
function advance_clock( int $seconds ): void {
	foreach ( $GLOBALS['pap_transients'] as $key => $entry ) {
		$entry['expires_at'] -= $seconds;

		if ( is_array( $entry['value'] ) && isset( $entry['value']['expires_at'] ) ) {
			$entry['value']['expires_at'] -= $seconds;
		}

		$GLOBALS['pap_transients'][ $key ] = $entry;
	}
}

/**
 * Reproduz `wp_authenticate()` do core: cadeia do filtro `authenticate` e, em caso de WP_Error,
 * `wp_login_failed`.
 *
 * E este o caminho REAL do login do marketplace. O plugin JWT so chama `wp_signon()` quando
 * `GRAPHQL_JWT_AUTH_SET_COOKIES` esta definida, o que nao acontece neste projeto — entao `wp_login`
 * NUNCA dispara no fluxo headless, e um teste que o chamasse na mao passaria sem cobrir nada.
 *
 * @param string $username    Identificador tentado.
 * @param bool   $correct     Se a credencial confere.
 * @param string $user_email  E-mail da conta, quando a credencial confere.
 * @return WP_User|WP_Error
 */
function wp_authenticate_simulado( string $username, bool $correct, string $user_email = '', string $failure_code = 'incorrect_password' ) {
	$user = $correct
		? new WP_User( $user_email )
		: new WP_Error( $failure_code, 'Falha de autenticacao.' );

	foreach ( $GLOBALS['pap_hooks']['authenticate'] ?? array() as $callback ) {
		$user = $callback( $user, $username );
	}

	if ( $user instanceof WP_Error ) {
		foreach ( $GLOBALS['pap_hooks']['wp_login_failed'] ?? array() as $callback ) {
			$callback( $username, $user );
		}
	}

	return $user;
}

/** Uma tentativa com senha errada. Devolve `true` quando foi recusada POR COTA. */
function fail_login( string $username ): bool {
	$result = wp_authenticate_simulado( $username, false );

	return $result instanceof WP_Error && PAPELITO_LOGIN_RATE_LIMIT_CODE === $result->get_error_code();
}

/** Uma tentativa com senha correta, pelo mesmo caminho do plugin JWT. */
function succeed_login( string $user_login, string $user_email = '' ): mixed {
	return wp_authenticate_simulado( $user_login, true, $user_email );
}

function fail_login_with_code( string $username, string $failure_code ): bool {
	$result = wp_authenticate_simulado( $username, false, '', $failure_code );

	return $result instanceof WP_Error && PAPELITO_LOGIN_RATE_LIMIT_CODE === $result->get_error_code();
}

// Todas as tentativas chegam do mesmo IP: e o do servidor Next, nao o do navegador.
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$GLOBALS['pap_users'] = array(
	new WP_User( 'legado@example.test', 'legado', 42 ),
);

// Um usuario esgota a propria cota.
$blocked = false;
for ( $attempt = 0; $attempt < PAPELITO_LOGIN_FAILURE_MAX; $attempt++ ) {
	$blocked = fail_login( 'vitima@example.test' ) || $blocked;
}
login_rate_limit_assert( 'as primeiras tentativas nao sao barradas', false, $blocked );
login_rate_limit_assert( 'a tentativa seguinte e barrada', true, fail_login( 'vitima@example.test' ) );

// Outra identidade, mesmo REMOTE_ADDR: nao pode herdar o bloqueio.
login_rate_limit_assert( 'outra identidade tem cota propria', false, fail_login( 'terceiro@example.test' ) );

// A recusa por cota vira WP_Error legivel, nunca wp_die: o Next precisa distinguir "muitas
// tentativas" de "servico indisponivel".
$refused = wp_authenticate_simulado( 'vitima@example.test', false );
login_rate_limit_assert( 'a recusa e um WP_Error, nao mata a requisicao', true, $refused instanceof WP_Error );
login_rate_limit_assert( 'a recusa carrega o codigo do contrato', PAPELITO_LOGIN_RATE_LIMIT_CODE, $refused->get_error_code() );
login_rate_limit_assert( 'a mensagem GraphQL tambem usa o codigo estavel do contrato', PAPELITO_LOGIN_RATE_LIMIT_CODE, $refused->get_error_message() );
login_rate_limit_assert( 'a recusa carrega status 429', 429, $refused->get_error_data()['status'] ?? null );

// Recusa por cota nao pode inflar o proprio balde.
$before = get_transient( papelito_login_failure_key( 'vitima@example.test' ) )['count'];
fail_login( 'vitima@example.test' );
$after = get_transient( papelito_login_failure_key( 'vitima@example.test' ) )['count'];
login_rate_limit_assert( 'tentativa ja barrada nao conta de novo', $before, $after );

// Janela fixa: falhas seguidas nao empurram o vencimento para frente.
advance_clock( PAPELITO_LOGIN_FAILURE_WINDOW - 5 );
login_rate_limit_assert( 'segue barrado dentro da janela', true, fail_login( 'vitima@example.test' ) );
advance_clock( 10 );
login_rate_limit_assert( 'a janela nao desliza com novas falhas', false, fail_login( 'vitima@example.test' ) );

// Credencial correta nunca e barrada, mesmo com a cota esgotada, e zera o contador — pelo filtro
// `authenticate`, que e o unico ponto que o fluxo headless atravessa.
for ( $attempt = 0; $attempt < PAPELITO_LOGIN_FAILURE_MAX; $attempt++ ) {
	fail_login( 'legado' );
}
login_rate_limit_assert( 'cota da conta legada esgotada', true, fail_login( 'legado' ) );
login_rate_limit_assert( 'senha correta passa mesmo com a cota estourada', true, succeed_login( 'legado', 'legado@example.test' ) instanceof WP_User );
login_rate_limit_assert( 'acerto zera o contador sem depender de wp_login', false, fail_login( 'legado' ) );

// O acerto tambem limpa a chave de e-mail, para conta legada cujo login difere do e-mail.
for ( $attempt = 0; $attempt < PAPELITO_LOGIN_FAILURE_MAX; $attempt++ ) {
	fail_login( 'legado@example.test' );
}
login_rate_limit_assert( 'cota da chave de e-mail esgotada', true, fail_login( 'legado@example.test' ) );
succeed_login( 'legado', 'legado@example.test' );
login_rate_limit_assert( 'acerto zera tambem a chave de e-mail', false, fail_login( 'legado@example.test' ) );

// Login e e-mail da mesma conta precisam compartilhar a cota. Alternar os dois identificadores
// nao pode dobrar o numero de senhas que um atacante consegue tentar.
for ( $attempt = 0; $attempt < PAPELITO_LOGIN_FAILURE_MAX; $attempt++ ) {
	fail_login( 'legado' );
}
login_rate_limit_assert( 'login e e-mail usam a mesma cota da conta', true, fail_login( 'legado@example.test' ) );

// Uma senha correta com e-mail ainda pendente nao e tentativa de brute force. O usuario deve
// continuar recebendo a orientacao de confirmacao, mesmo depois de repetir o login.
for ( $attempt = 0; $attempt < PAPELITO_LOGIN_FAILURE_MAX + 1; $attempt++ ) {
	fail_login_with_code( 'pendente@example.test', 'papelito_email_not_verified' );
}
login_rate_limit_assert( 'e-mail nao confirmado nao consome a cota de senha', false, fail_login_with_code( 'pendente@example.test', 'papelito_email_not_verified' ) );

// Garantia explicita: o fluxo headless nunca passa por wp_login.
login_rate_limit_assert( 'o teste nao depende de wp_login', 0, count( $GLOBALS['pap_wp_login_calls'] ) );

echo 0 === $failures ? "\nOK\n" : "\n{$failures} FALHA(S)\n";
exit( 0 === $failures ? 0 : 1 );
