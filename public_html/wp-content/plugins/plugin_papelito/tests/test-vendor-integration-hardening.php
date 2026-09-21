<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.
/**
 * Endurecimento da configuração Braspress apontado em revisão.
 *
 * Fixa quatro coisas que a suíte de auditoria não cobria: que a recusa por
 * limite de escrita não escreve uma linha por requisição, que a recusa do
 * porteiro — a tentativa de acesso que a auditoria existe para capturar — deixa
 * rastro, que remover a integração fecha o alerta de degradação em vez de
 * deixá-lo aberto para sempre, e que a tabela de trilha tem retenção.
 *
 * Usage: php tests/test-vendor-integration-hardening.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_DIGITS_REGEX', '/\D+/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'PAPELITO_AUTH_RATE_LIMIT_MESSAGE', 'Muitas tentativas. Tente novamente em alguns minutos.' );

const HARDENING_TEST_VENDOR_ID   = 5150;
const HARDENING_TEST_INTRUDER_ID = 6160;
const HARDENING_TEST_CNPJ        = '20024291000165';
const HARDENING_TEST_CEP         = '14711142';
const HARDENING_TEST_PASSWORD    = 'senha-da-conta-do-vendor';
const HARDENING_TEST_USERNAME    = 'braspress-usuario-do-teste';
const HARDENING_TEST_SECRET      = 'Zk7-senha-secretissima';
const HARDENING_TEST_ENVELOPE    = 'k1:envelope-do-cofre';

$GLOBALS['hard_meta']       = array();
$GLOBALS['hard_row']        = null;
$GLOBALS['hard_audit']      = array();
$GLOBALS['hard_rate_ok']    = true;
$GLOBALS['hard_transients'] = array();
$GLOBALS['hard_alerts']     = array();
$GLOBALS['hard_deleted']    = array();
$GLOBALS['hard_insert_ok']  = true;
$GLOBALS['hard_permission'] = true;
$GLOBALS['hard_current']    = HARDENING_TEST_VENDOR_ID;

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

class WP_REST_Request {}
class WP_REST_Response {}
class WP_REST_Server { const READABLE = 'GET'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const CREATABLE = 'POST'; }

/** `$wpdb` mínimo, que guarda a trilha e honra a remoção por idade. */
class Papelito_Hardening_Wpdb {
	public string $prefix = 'wp_';

	public function get_charset_collate(): string { return ''; }
	public function get_row( mixed $query, mixed $output = null ): ?array { return $GLOBALS['hard_row']; }
	public function prepare( mixed $query, mixed ...$args ): string {
		$sql = (string) $query;
		foreach ( $args as $arg ) {
			$sql = (string) preg_replace( '/%[ds]/', "'" . (string) $arg . "'", $sql, 1 );
		}

		return $sql;
	}
	public function insert( mixed $table, array $data, mixed $format = null ): mixed {
		if ( ! $GLOBALS['hard_insert_ok'] ) {
			return false;
		}
		if ( str_contains( (string) $table, 'audit' ) ) {
			$GLOBALS['hard_audit'][] = $data;

			return 1;
		}
		$GLOBALS['hard_row'] = array_merge( array( 'id' => 7 ), $data );

		return 1;
	}
	public function update( mixed $table, array $data, mixed $where, mixed $format = null, mixed $where_format = null ): int {
		$GLOBALS['hard_row'] = array_merge( (array) $GLOBALS['hard_row'], $data );

		return 1;
	}
	public function delete( mixed $table, mixed $where, mixed $format = null ): int {
		$GLOBALS['hard_row'] = null;

		return 1;
	}
	public function query( mixed $sql ): int {
		$GLOBALS['hard_deleted'][] = (string) $sql;

		return 1;
	}
}

$GLOBALS['wpdb'] = new Papelito_Hardening_Wpdb();

/** Nenhum listener é executado. */
function add_action( mixed ...$args ): bool { return true; }
/** Nenhum filtro instalado. */
function add_filter( mixed ...$args ): bool { return true; }
/** Guarda o alerta de saúde publicado. */
function do_action( mixed ...$args ): bool {
	if ( 'papelito_shipping_provider_alert' === (string) ( $args[0] ?? '' ) ) {
		$GLOBALS['hard_alerts'][] = array_slice( $args, 1 );
	}

	return true;
}
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
/** Nenhuma rota REST é exercitada. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Relógio fixo do teste. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-20 12:00:00'; }
/** Cadastro do vendor, lido pela configuração derivada. */
function get_user_meta( mixed $user_id, string $key, bool $single = false ): string {
	return $GLOBALS['hard_meta'][ (int) $user_id ][ $key ] ?? '';
}
/** Usuário buscado pela reautenticação. */
function get_user_by( mixed $field, mixed $value ): WP_User {
	return new WP_User( (int) $value, 'hash:' . HARDENING_TEST_PASSWORD, 'loja@example.test' );
}
/** Dados do usuário para o aviso de alteração sensível. */
function get_userdata( mixed $user_id ): WP_User { return new WP_User( (int) $user_id, '', 'loja@example.test' ); }
/** Identidade da sessão sob teste. */
function get_current_user_id(): int { return (int) $GLOBALS['hard_current']; }
/** Conferência de senha do core. */
function wp_check_password( mixed $password, mixed $hash, mixed $user_id = '' ): bool {
	return 'hash:' . (string) $password === (string) $hash;
}
/** Valida o e-mail do destinatário do aviso. */
function is_email( mixed $value ): bool { return str_contains( (string) $value, '@' ); }
/** Nenhum e-mail é enviado. */
function wp_mail( mixed ...$args ): bool { return true; }
/** Limite de escrita controlado pela fixture. */
function papelito_auth_rate_limit( mixed ...$args ): bool { return (bool) $GLOBALS['hard_rate_ok']; }
/** Transients em memória, para o teste medir a deduplicação. */
function get_transient( mixed $key ): mixed { return $GLOBALS['hard_transients'][ (string) $key ] ?? false; }
/** Grava o transient em memória. */
function set_transient( mixed $key, mixed $value, mixed $ttl = 0 ): bool {
	$GLOBALS['hard_transients'][ (string) $key ] = $value;

	return true;
}
/** Apaga o transient em memória; é por aqui que o tíquete de reautenticação é queimado. */
function delete_transient( mixed $key ): bool {
	unset( $GLOBALS['hard_transients'][ (string) $key ] );

	return true;
}
/** Cofre de integração com envelope distinguível. */
function papelito_vendor_secret_encrypt( string $plain ): string { return HARDENING_TEST_ENVELOPE; }
/** Abertura do cofre, não exercitada aqui. */
function papelito_vendor_secret_decrypt( string $envelope ): string {
	return wp_json_encode( array( 'username' => HARDENING_TEST_USERNAME, 'password' => HARDENING_TEST_SECRET ) );
}
/** O CNPJ da fixture é válido. */
function papelito_validate_cnpj( string $cnpj ): bool { return HARDENING_TEST_CNPJ === $cnpj; }
/** Porteiro de seller, controlado pela fixture. */
function papelito_vendor_dashboard_permission_seller() {
	return $GLOBALS['hard_permission'] ? true : new WP_Error( 'papelito_vendor_forbidden', 'Acesso restrito a vendors.', array( 'status' => 403 ) );
}
/** Porteiro comercial, controlado pela fixture. */
function papelito_vendor_dashboard_permission_seller_commercial() {
	return $GLOBALS['hard_permission'] ? true : new WP_Error( 'papelito_account_suspended', 'Conta suspensa.', array( 'status' => 403 ) );
}
/** Publica o alerta de saúde; o teste inspeciona o que foi publicado. */
function papelito_shipping_provider_alert( string $provider, string $status, array $context = array() ): void {
	$GLOBALS['hard_alerts'][] = array( $provider, $status, $context );
}
/** Fecha o disjuntor; o teste só confere que foi chamado. */
function papelito_shipping_breaker_close( string $provider, int $vendor_id ): void {
	$GLOBALS['hard_alerts'][] = array( 'breaker_closed', $provider, $vendor_id );
}

$GLOBALS['hard_log_file'] = tempnam( sys_get_temp_dir(), 'papelito-hardening-' );
ini_set( 'error_log', $GLOBALS['hard_log_file'] );

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function hard_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Recria o cadastro do vendor e zera o que cada cenário mede. */
function hard_reset( ?array $row = null ): void {
	$GLOBALS['hard_meta'][ HARDENING_TEST_VENDOR_ID ] = array( 'cnpj' => HARDENING_TEST_CNPJ, 'cep' => HARDENING_TEST_CEP );
	$GLOBALS['hard_row']        = $row;
	$GLOBALS['hard_audit']      = array();
	$GLOBALS['hard_rate_ok']    = true;
	$GLOBALS['hard_transients'] = array();
	$GLOBALS['hard_alerts']     = array();
	$GLOBALS['hard_deleted']    = array();
	$GLOBALS['hard_insert_ok']  = true;
	file_put_contents( $GLOBALS['hard_log_file'], '' );
	$GLOBALS['hard_permission'] = true;
	$GLOBALS['hard_current']    = HARDENING_TEST_VENDOR_ID;
}

/** Corpo de requisição que troca a credencial write-only. */
function hard_payload(): array {
	return array(
		'currentPassword' => HARDENING_TEST_PASSWORD,
		'origin_cep'      => HARDENING_TEST_CEP,
		'enabled'         => true,
		'username'        => HARDENING_TEST_USERNAME,
		'password'        => HARDENING_TEST_SECRET,
	);
}

/** Linha persistida de uma integração degradada. */
function hard_degraded_row(): array {
	return array(
		'id'                    => 7,
		'vendor_id'             => HARDENING_TEST_VENDOR_ID,
		'provider'              => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'config_json'           => wp_json_encode( array( 'origin_cep' => HARDENING_TEST_CEP ) ),
		'secret_envelope'       => HARDENING_TEST_ENVELOPE,
		'configuration_version' => 4,
		'enabled'               => 1,
		'status'                => PAPELITO_VENDOR_INTEGRATION_BLOCKED,
	);
}

echo "Cenário 1: o limite de escrita não escreve uma linha por requisição\n";
hard_reset();
$GLOBALS['hard_rate_ok'] = false;

for ( $i = 0; $i < 12; $i++ ) {
	papelito_vendor_integration_save_braspress( HARDENING_TEST_VENDOR_ID, hard_payload(), HARDENING_TEST_VENDOR_ID );
}

hard_assert( 'Doze recusas por limite deixam uma linha só', 1 === count( $GLOBALS['hard_audit'] ) );
hard_assert( 'E a linha registra a recusa', 'denied' === ( $GLOBALS['hard_audit'][0]['status'] ?? null ) );

echo "\nCenário 2: recusa que não é por limite continua auditada uma a uma\n";
hard_reset();
$payload                    = hard_payload();
$payload['currentPassword'] = 'senha-errada';

for ( $i = 0; $i < 3; $i++ ) {
	papelito_vendor_integration_save_braspress( HARDENING_TEST_VENDOR_ID, $payload, HARDENING_TEST_VENDOR_ID );
}

hard_assert( 'Três reautenticações erradas deixam três linhas', 3 === count( $GLOBALS['hard_audit'] ) );

echo "\nCenário 3: a recusa do porteiro deixa rastro\n";
hard_reset();
$GLOBALS['hard_permission'] = false;

$barrado = papelito_vendor_integration_permission_write();

hard_assert( 'O porteiro continua recusando', is_wp_error( $barrado ) );
hard_assert( 'A recusa do porteiro é auditada', 1 === count( $GLOBALS['hard_audit'] ) );
hard_assert( 'E entra como recusa', 'denied' === ( $GLOBALS['hard_audit'][0]['status'] ?? null ) );
hard_assert( 'Com o ator identificado', HARDENING_TEST_VENDOR_ID === ( $GLOBALS['hard_audit'][0]['actor_user_id'] ?? null ) );

echo "\nCenário 4: anônimo barrado não escreve na trilha\n";
hard_reset();
$GLOBALS['hard_permission'] = false;
$GLOBALS['hard_current']    = 0;

papelito_vendor_integration_permission_write();

hard_assert( 'Sem sessão não há ator para registrar, e nada é escrito', array() === $GLOBALS['hard_audit'] );

echo "\nCenário 5: o porteiro barrado em laço também é deduplicado\n";
hard_reset();
$GLOBALS['hard_permission'] = false;

for ( $i = 0; $i < 9; $i++ ) {
	papelito_vendor_integration_permission_write();
}

hard_assert( 'Nove recusas seguidas deixam uma linha só', 1 === count( $GLOBALS['hard_audit'] ) );

echo "\nCenário 6: remover integração degradada fecha o alerta aberto\n";
hard_reset( hard_degraded_row() );

papelito_vendor_integration_delete_braspress(
	HARDENING_TEST_VENDOR_ID,
	array( 'currentPassword' => HARDENING_TEST_PASSWORD ),
	HARDENING_TEST_VENDOR_ID
);

$publicados = array_values( array_filter( $GLOBALS['hard_alerts'], static fn( $a ): bool => 'breaker_closed' !== ( $a[0] ?? '' ) ) );

hard_assert( 'A recuperação é publicada ao remover', 1 === count( $publicados ) );
hard_assert(
	'E ela diz de que estado a loja saiu',
	PAPELITO_VENDOR_INTEGRATION_BLOCKED === ( $publicados[0][2]['previous_state'] ?? null )
);

echo "\nCenário 7: remover integração fecha o disjuntor daquele vendor\n";
hard_reset( hard_degraded_row() );

papelito_vendor_integration_delete_braspress(
	HARDENING_TEST_VENDOR_ID,
	array( 'currentPassword' => HARDENING_TEST_PASSWORD ),
	HARDENING_TEST_VENDOR_ID
);

$fechou = array_values( array_filter( $GLOBALS['hard_alerts'], static fn( $a ): bool => 'breaker_closed' === ( $a[0] ?? '' ) ) );

hard_assert( 'O disjuntor da loja é fechado', 1 === count( $fechou ) );
hard_assert( 'E é o disjuntor daquele vendor', HARDENING_TEST_VENDOR_ID === ( $fechou[0][2] ?? null ) );

echo "\nCenário 8: remover integração saudável não inventa alerta\n";
hard_reset(
	array_merge( hard_degraded_row(), array( 'status' => PAPELITO_VENDOR_INTEGRATION_ACTIVE ) )
);

papelito_vendor_integration_delete_braspress(
	HARDENING_TEST_VENDOR_ID,
	array( 'currentPassword' => HARDENING_TEST_PASSWORD ),
	HARDENING_TEST_VENDOR_ID
);

$publicados = array_values( array_filter( $GLOBALS['hard_alerts'], static fn( $a ): bool => 'breaker_closed' !== ( $a[0] ?? '' ) ) );

hard_assert( 'Loja saudável removida não publica recuperação', array() === $publicados );

echo "\nCenário 9: a trilha tem retenção\n";
hard_reset();

papelito_vendor_integration_audit_prune();

hard_assert( 'A varredura apaga por idade', 1 === count( $GLOBALS['hard_deleted'] ) );
hard_assert(
	'E a remoção é limitada pela data de criação',
	str_contains( $GLOBALS['hard_deleted'][0] ?? '', 'created_at <' )
);

echo "\nCenário 10: falha de escrita da trilha não passa em silêncio\n";
hard_reset();
$GLOBALS['hard_insert_ok'] = false;

papelito_vendor_integration_audit( HARDENING_TEST_VENDOR_ID, PAPELITO_VENDOR_INTEGRATION_PROVIDER, HARDENING_TEST_VENDOR_ID, 'credentials_saved' );

$registrado = (string) file_get_contents( $GLOBALS['hard_log_file'] );

hard_assert( 'A falha de gravação da trilha é registrada', str_contains( $registrado, 'papelito_vendor_integration_audit_failed' ) );
hard_assert( 'E o registro não carrega segredo', ! str_contains( $registrado, HARDENING_TEST_SECRET ) );

echo "\nCenário 11: corpo com campo não escalar não vira warning\n";
hard_reset();

$acao = papelito_vendor_integration_intended_action( array( 'username' => array( 'x' ), 'password' => null ) );

hard_assert( 'A ação pretendida é deduzida sem converter array em texto', 'configuration_saved' === $acao );

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
