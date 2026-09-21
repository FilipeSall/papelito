<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.
/**
 * Tíquete de reautenticação da integração Braspress do vendor.
 *
 * Fixa o contrato do step-up depois que o campo de senha saiu do formulário e
 * foi para um modal: que confirmar a senha emite uma prova de curta duração em
 * vez de deixar a senha no navegador, que a prova é do dono e morre com o uso,
 * que um erro de formulário no meio do caminho não a queima, e que trocar o CEP
 * de origem — reversível e visível na tela — deixou de exigir prova nenhuma.
 *
 * Usage: php tests/test-vendor-integration-reauth.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_DIGITS_REGEX', '/\D+/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'PAPELITO_REST_NAMESPACE', 'papelito/v1' );
define( 'PAPELITO_AUTH_RATE_LIMIT_MESSAGE', 'Muitas tentativas. Tente novamente em alguns minutos.' );

const REAUTH_TEST_VENDOR_ID    = 8180;
const REAUTH_TEST_NEIGHBOUR_ID = 9190;
const REAUTH_TEST_CNPJ         = '20024291000165';
const REAUTH_TEST_CEP          = '14711142';
const REAUTH_TEST_OTHER_CEP    = '01001000';
const REAUTH_TEST_SHORT_CEP    = '1471';
const REAUTH_TEST_PASSWORD     = 'senha-da-conta-papelito';
const REAUTH_TEST_WRONG_PASS   = 'senha-que-nao-e-a-da-conta';
const REAUTH_TEST_USERNAME     = 'braspress-usuario-do-contrato';
const REAUTH_TEST_SECRET       = 'Qx4-senha-da-braspress';
const REAUTH_TEST_ENVELOPE     = 'k1:envelope-selado';
const REAUTH_TEST_TICKET_CODE  = 'papelito_vendor_integration_reauth_ticket_invalid';
const REAUTH_TEST_PASSWORD_COD = 'papelito_vendor_integration_current_password_invalid';

$GLOBALS['reauth_meta']       = array();
$GLOBALS['reauth_row']        = null;
$GLOBALS['reauth_audit']      = array();
$GLOBALS['reauth_transients'] = array();
$GLOBALS['reauth_buckets']    = array();
$GLOBALS['reauth_rate_ok']    = true;
$GLOBALS['reauth_clock']      = 0;
$GLOBALS['reauth_serial']     = 0;
$GLOBALS['reauth_current']    = REAUTH_TEST_VENDOR_ID;
$GLOBALS['reauth_routes']     = array();

/** Erro do core reduzido ao que os módulos leem. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

/** Usuário do core, com a senha cifrada que a reautenticação confere. */
class WP_User {
	public function __construct( public int $ID = 0, public string $user_pass = '', public string $user_email = '' ) {}
}

/** Requisição REST reduzida ao corpo JSON que o handler lê. */
class WP_REST_Request {
	public function __construct( private array $payload = array() ) {}
	public function get_json_params(): array { return $this->payload; }
}

/** Resposta REST reduzida ao corpo e ao status que o teste inspeciona. */
class WP_REST_Response {
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
	public function get_data(): mixed { return $this->data; }
}

class WP_REST_Server { const READABLE = 'GET'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const CREATABLE = 'POST'; }

/** `$wpdb` mínimo, com uma linha de integração por vez e a trilha em memória. */
class Papelito_Reauth_Wpdb {
	public string $prefix = 'wp_';

	public function get_charset_collate(): string { return ''; }
	public function get_row( mixed $query, mixed $output = null ): ?array { return $GLOBALS['reauth_row']; }
	public function prepare( mixed $query, mixed ...$args ): string {
		$sql = (string) $query;
		foreach ( $args as $arg ) {
			$sql = (string) preg_replace( '/%[ds]/', "'" . (string) $arg . "'", $sql, 1 );
		}

		return $sql;
	}
	public function insert( mixed $table, array $data, mixed $format = null ): int {
		if ( str_contains( (string) $table, 'audit' ) ) {
			$GLOBALS['reauth_audit'][] = $data;

			return 1;
		}
		$GLOBALS['reauth_row'] = array_merge( array( 'id' => 11 ), $data );

		return 1;
	}
	public function update( mixed $table, array $data, mixed $where, mixed $format = null, mixed $where_format = null ): int {
		$GLOBALS['reauth_row'] = array_merge( (array) $GLOBALS['reauth_row'], $data );

		return 1;
	}
	public function delete( mixed $table, mixed $where, mixed $format = null ): int {
		$GLOBALS['reauth_row'] = null;

		return 1;
	}
	public function query( mixed $sql ): int { return 1; }
}

$GLOBALS['wpdb'] = new Papelito_Reauth_Wpdb();

/** Relógio do teste, que avança sozinho só quando a fixture manda. */
function reauth_test_now(): int { return time() + (int) $GLOBALS['reauth_clock']; }
/** Nenhum listener é executado. */
function add_action( mixed ...$args ): bool { return true; }
/** Nenhum filtro instalado. */
function add_filter( mixed ...$args ): bool { return true; }
/** Nenhum hook publicado é observado aqui. */
function do_action( mixed ...$args ): bool { return true; }
/** Devolve o valor recebido. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed { return $value; }
/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Serialização usada pela configuração persistida. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Guarda a rota registrada, para o teste conferir o porteiro do step-up. */
function register_rest_route( mixed $namespace, mixed $route, mixed $args = array() ): bool {
	$GLOBALS['reauth_routes'][ (string) $route ] = $args;

	return true;
}
/** Relógio fixo da gravação. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-21 09:00:00'; }
/** Cadastro do vendor, lido pela configuração derivada. */
function get_user_meta( mixed $user_id, string $key, bool $single = false ): string {
	return $GLOBALS['reauth_meta'][ (int) $user_id ][ $key ] ?? '';
}
/** Usuário buscado pela reautenticação. */
function get_user_by( mixed $field, mixed $value ): WP_User {
	return new WP_User( (int) $value, 'hash:' . REAUTH_TEST_PASSWORD, 'loja@example.test' );
}
/** Dados do usuário para o aviso de alteração sensível. */
function get_userdata( mixed $user_id ): WP_User { return new WP_User( (int) $user_id, '', 'loja@example.test' ); }
/** Identidade da sessão sob teste. */
function get_current_user_id(): int { return (int) $GLOBALS['reauth_current']; }
/** Conferência de senha do core. */
function wp_check_password( mixed $password, mixed $hash, mixed $user_id = '' ): bool {
	return 'hash:' . (string) $password === (string) $hash;
}
/** Segredo do site usado no HMAC do tíquete. */
function wp_salt( mixed $scheme = 'auth' ): string { return 'sal-fixo-do-teste'; }
/** Tíquete distinto a cada emissão, para o teste distinguir um do outro. */
function wp_generate_password( mixed $length = 12, mixed $special = true, mixed $extra = true ): string {
	++$GLOBALS['reauth_serial'];

	return 'ticket-' . $GLOBALS['reauth_serial'];
}
/** Valida o e-mail do destinatário do aviso. */
function is_email( mixed $value ): bool { return str_contains( (string) $value, '@' ); }
/** Nenhum e-mail é enviado. */
function wp_mail( mixed ...$args ): bool { return true; }
/** Registra o balde consultado, que é o que separa confirmar senha de gravar. */
function papelito_auth_rate_limit( mixed $bucket, mixed $max = 20, mixed $window = 60, mixed $identifier = '' ): bool {
	$GLOBALS['reauth_buckets'][] = (string) $bucket;

	return (bool) $GLOBALS['reauth_rate_ok'];
}
/** Transient em memória que honra o prazo, para o tíquete poder expirar. */
function get_transient( mixed $key ): mixed {
	$entry = $GLOBALS['reauth_transients'][ (string) $key ] ?? null;
	if ( ! is_array( $entry ) ) {
		return false;
	}
	if ( $entry['expires_at'] > 0 && $entry['expires_at'] <= reauth_test_now() ) {
		unset( $GLOBALS['reauth_transients'][ (string) $key ] );

		return false;
	}

	return $entry['value'];
}
/** Grava o transient em memória com o prazo pedido. */
function set_transient( mixed $key, mixed $value, mixed $ttl = 0 ): bool {
	$GLOBALS['reauth_transients'][ (string) $key ] = array(
		'value'      => $value,
		'expires_at' => (int) $ttl > 0 ? reauth_test_now() + (int) $ttl : 0,
	);

	return true;
}
/** Apaga o transient em memória. */
function delete_transient( mixed $key ): bool {
	unset( $GLOBALS['reauth_transients'][ (string) $key ] );

	return true;
}
/** Cofre de integração com envelope distinguível. */
function papelito_vendor_secret_encrypt( string $plain ): string { return REAUTH_TEST_ENVELOPE; }
/** Abertura do cofre, não exercitada aqui. */
function papelito_vendor_secret_decrypt( string $envelope ): string {
	return wp_json_encode( array( 'username' => REAUTH_TEST_USERNAME, 'password' => REAUTH_TEST_SECRET ) );
}
/** Porteiro comercial do painel, satisfeito nesta suíte. */
function papelito_vendor_dashboard_permission_seller_commercial(): bool { return true; }
/** Porteiro de leitura do painel, satisfeito nesta suíte. */
function papelito_vendor_dashboard_permission_seller(): bool { return true; }

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';

$failures = 0;

/** Registra o desfecho de uma asserção e conta a falha. */
function reauth_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";

		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Zera a loja, a trilha e as provas entre cenários. */
function reauth_reset(): void {
	$GLOBALS['reauth_row']        = null;
	$GLOBALS['reauth_audit']      = array();
	$GLOBALS['reauth_transients'] = array();
	$GLOBALS['reauth_buckets']    = array();
	$GLOBALS['reauth_rate_ok']    = true;
	$GLOBALS['reauth_clock']      = 0;
	$GLOBALS['reauth_current']    = REAUTH_TEST_VENDOR_ID;
	$GLOBALS['reauth_meta']       = array(
		REAUTH_TEST_VENDOR_ID    => array( 'cnpj' => REAUTH_TEST_CNPJ, 'cep' => REAUTH_TEST_CEP ),
		REAUTH_TEST_NEIGHBOUR_ID => array( 'cnpj' => REAUTH_TEST_CNPJ, 'cep' => REAUTH_TEST_CEP ),
	);
}

/** Corpo de gravação sem credencial: só o que a tela mostra sempre. */
function reauth_config_payload( string $cep = REAUTH_TEST_CEP ): array {
	return array( 'enabled' => false, 'originCep' => $cep );
}

/** Corpo de gravação que substitui a credencial write-only. */
function reauth_credential_payload( array $proof, string $cep = REAUTH_TEST_CEP ): array {
	return array_merge(
		array( 'enabled' => false, 'originCep' => $cep, 'username' => REAUTH_TEST_USERNAME, 'password' => REAUTH_TEST_SECRET ),
		$proof
	);
}

/** Código do erro devolvido, ou vazio quando a operação passou. */
function reauth_code( mixed $result ): string {
	return $result instanceof WP_Error ? $result->get_error_code() : '';
}

/** Pede um tíquete pela rota, como o modal de senha faz. */
function reauth_request_ticket( string $password ): mixed {
	return papelito_vendor_integration_handle_reauth_braspress( new WP_REST_Request( array( 'currentPassword' => $password ) ) );
}

/** Ações auditadas até aqui, na ordem em que entraram na trilha. */
function reauth_audited(): array {
	return array_map( static fn( array $line ): string => $line['action'] . ':' . $line['status'], $GLOBALS['reauth_audit'] );
}

echo "Cenário 1: confirmar a senha emite a prova; errar não emite nada\n";
reauth_reset();
$issued = reauth_request_ticket( REAUTH_TEST_PASSWORD );
reauth_assert( 'A senha certa devolve um tíquete', $issued instanceof WP_REST_Response && '' !== (string) $issued->get_data()['ticket'] );
reauth_assert( 'A resposta diz por quanto tempo a prova vale', PAPELITO_VENDOR_INTEGRATION_REAUTH_TTL === $issued->get_data()['expires_in'] );
reauth_assert( 'A resposta não devolve o hash guardado', ! str_contains( wp_json_encode( $issued->get_data() ), hash_hmac( 'sha256', (string) $issued->get_data()['ticket'], wp_salt( 'auth' ) ) ) );
reauth_assert( 'Confirmar a senha entra na trilha', in_array( 'reauth:success', reauth_audited(), true ) );
reauth_assert( 'Confirmar a senha não consome o balde de escrita', array( 'vendor_braspress_reauth' ) === $GLOBALS['reauth_buckets'] );

reauth_reset();
$refused = reauth_request_ticket( REAUTH_TEST_WRONG_PASS );
reauth_assert( 'A senha errada é recusada', REAUTH_TEST_PASSWORD_COD === reauth_code( $refused ) );
reauth_assert( 'A senha errada não deixa prova para trás', array() === $GLOBALS['reauth_transients'] );
reauth_assert( 'A senha errada entra na trilha como recusa', in_array( 'reauth:denied', reauth_audited(), true ) );

echo "\nCenário 2: a prova é do dono e substitui a senha no corpo\n";
reauth_reset();
$ticket = reauth_request_ticket( REAUTH_TEST_PASSWORD )->get_data()['ticket'];
$saved  = papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_credential_payload( array( 'reauthTicket' => $ticket ) ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'O tíquete autoriza a troca de credencial sem a senha no corpo', ! is_wp_error( $saved ) && true === $saved['credentials_configured'] );

reauth_reset();
$GLOBALS['reauth_current'] = REAUTH_TEST_VENDOR_ID;
$mine                      = reauth_request_ticket( REAUTH_TEST_PASSWORD )->get_data()['ticket'];
$stolen                    = papelito_vendor_integration_save_braspress( REAUTH_TEST_NEIGHBOUR_ID, reauth_credential_payload( array( 'reauthTicket' => $mine ) ), REAUTH_TEST_NEIGHBOUR_ID );
reauth_assert( 'A prova de um vendor não autoriza a loja do vizinho', REAUTH_TEST_TICKET_CODE === reauth_code( $stolen ) );
reauth_assert( 'A tentativa com prova alheia não grava nada', null === $GLOBALS['reauth_row'] );

echo "\nCenário 3: a prova expira e morre com o uso\n";
reauth_reset();
$expiring                = reauth_request_ticket( REAUTH_TEST_PASSWORD )->get_data()['ticket'];
$GLOBALS['reauth_clock'] = PAPELITO_VENDOR_INTEGRATION_REAUTH_TTL + 1;
$late                    = papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_credential_payload( array( 'reauthTicket' => $expiring ) ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'Prova vencida é recusada', REAUTH_TEST_TICKET_CODE === reauth_code( $late ) );

reauth_reset();
$once  = reauth_request_ticket( REAUTH_TEST_PASSWORD )->get_data()['ticket'];
papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_credential_payload( array( 'reauthTicket' => $once ) ), REAUTH_TEST_VENDOR_ID );
$replay = papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_credential_payload( array( 'reauthTicket' => $once ) ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'A prova usada com sucesso não serve de novo', REAUTH_TEST_TICKET_CODE === reauth_code( $replay ) );

reauth_reset();
$kept    = reauth_request_ticket( REAUTH_TEST_PASSWORD )->get_data()['ticket'];
$refused = papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_credential_payload( array( 'reauthTicket' => $kept ), REAUTH_TEST_SHORT_CEP ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'Erro de formulário é recusado depois da prova', 'papelito_vendor_integration_invalid_origin_cep' === reauth_code( $refused ) );
$retry = papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_credential_payload( array( 'reauthTicket' => $kept ) ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'Erro de formulário não queima a prova nem pede a senha de novo', ! is_wp_error( $retry ) );

echo "\nCenário 4: o step-up segue a intenção do corpo, não o método\n";
reauth_reset();
$plain = papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_config_payload( REAUTH_TEST_OTHER_CEP ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'Trocar só o CEP de origem não exige prova nenhuma', ! is_wp_error( $plain ) );
reauth_assert( 'E a mudança é gravada', REAUTH_TEST_OTHER_CEP === $plain['config']['origin_cep'] );
reauth_assert( 'Gravação sem credencial continua auditada', in_array( 'configuration_saved:success', reauth_audited(), true ) );

reauth_reset();
$naked = papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_credential_payload( array() ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'Trocar a credencial sem prova alguma é recusado', REAUTH_TEST_PASSWORD_COD === reauth_code( $naked ) );
reauth_assert( 'A recusa preserva a loja', null === $GLOBALS['reauth_row'] );

reauth_reset();
$purge = papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, array( 'enabled' => false, 'originCep' => REAUTH_TEST_CEP, 'removeCredentials' => true ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'Apagar a credencial pelo save também exige prova', REAUTH_TEST_PASSWORD_COD === reauth_code( $purge ) );

echo "\nCenário 5: a remoção exige prova sempre\n";
reauth_reset();
$bare = papelito_vendor_integration_delete_braspress( REAUTH_TEST_VENDOR_ID, array(), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'Remover sem prova é recusado', REAUTH_TEST_PASSWORD_COD === reauth_code( $bare ) );

reauth_reset();
$ticket = reauth_request_ticket( REAUTH_TEST_PASSWORD )->get_data()['ticket'];
papelito_vendor_integration_save_braspress( REAUTH_TEST_VENDOR_ID, reauth_credential_payload( array( 'reauthTicket' => $ticket ) ), REAUTH_TEST_VENDOR_ID );
$fresh   = reauth_request_ticket( REAUTH_TEST_PASSWORD )->get_data()['ticket'];
$removed = papelito_vendor_integration_delete_braspress( REAUTH_TEST_VENDOR_ID, array( 'reauthTicket' => $fresh ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'Com prova a remoção acontece', ! is_wp_error( $removed ) && false === $removed['credentials_configured'] );
reauth_assert( 'A remoção também queima a prova', array() === $GLOBALS['reauth_transients'] );

reauth_reset();
$legacy = papelito_vendor_integration_delete_braspress( REAUTH_TEST_VENDOR_ID, array( 'currentPassword' => REAUTH_TEST_PASSWORD ), REAUTH_TEST_VENDOR_ID );
reauth_assert( 'A senha crua no corpo continua valendo para quem chama a rota direto', ! is_wp_error( $legacy ) );

echo "\nCenário 6: a rota do step-up nasce atrás do porteiro comercial\n";
reauth_reset();
papelito_vendor_integration_register_routes();
$route = $GLOBALS['reauth_routes']['/vendor/me/integrations/braspress/reauth'] ?? array();
reauth_assert( 'A rota de confirmação existe', array() !== $route );
reauth_assert( 'Ela só aceita POST', WP_REST_Server::CREATABLE === ( $route['methods'] ?? '' ) );
reauth_assert( 'Ela usa o porteiro do step-up', 'papelito_vendor_integration_permission_reauth' === ( $route['permission_callback'] ?? '' ) );
reauth_assert( 'Ela não recebe a loja alvo do cliente', ! str_contains( wp_json_encode( $route ), 'vendor_id' ) );

echo "\n" . ( 0 === $failures ? "OK\n" : "{$failures} falha(s)\n" );
exit( 0 === $failures ? 0 : 1 );
