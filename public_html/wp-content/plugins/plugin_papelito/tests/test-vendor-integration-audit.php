<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.
/**
 * Auditoria da configuração Braspress: ator, vendor, ação e status.
 *
 * Fixa o que a tabela sozinha não conta: que a tentativa **recusada** também
 * deixa rastro — sem ela, quem tenta trocar a credencial de outra loja e leva
 * 403 some do histórico —, que a linha diz o que foi tentado e o que aconteceu
 * em campos separados, e que nem a senha nova, nem um prefixo dela, nem o seu
 * tamanho chegam ao banco.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_DIGITS_REGEX', '/\D+/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_AUTH_RATE_LIMIT_MESSAGE', 'Muitas tentativas. Tente novamente em alguns minutos.' );

const AUDIT_TEST_VENDOR_ID    = 2163;
const AUDIT_TEST_INTRUDER_ID  = 4477;
const AUDIT_TEST_CNPJ         = '20024291000165';
const AUDIT_TEST_CEP          = '14711142';
const AUDIT_TEST_ACCOUNT_PASS = 'senha-da-conta-do-vendor';
const AUDIT_TEST_USERNAME     = 'braspress-usuario-do-teste';
const AUDIT_TEST_SECRET       = 'Zk7-senha-secretissima-do-contrato';
const AUDIT_TEST_ENVELOPE     = 'k1:envelope-do-cofre-de-integracao';
const AUDIT_TEST_PII_ENVELOPE = 'v1:envelope-do-cofre-de-pii';

$GLOBALS['audit_test_meta']      = array();
$GLOBALS['audit_test_row']       = null;
$GLOBALS['audit_test_rows']      = array();
$GLOBALS['audit_test_rate_ok']   = true;
$GLOBALS['audit_test_actions']   = array();
$GLOBALS['audit_test_alerts']    = array();
$GLOBALS['audit_test_transients'] = array();

/** Erro WordPress mínimo, com a leitura de código e `status` que a auditoria usa. */
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

/** `$wpdb` mínimo: guarda as linhas de auditoria e a integração da fixture. */
class Papelito_Audit_Test_Wpdb {
	public string $prefix = 'wp_';

	public function get_charset_collate(): string { return ''; }

	public function get_row( mixed $query, mixed $output = null ): ?array {
		return $GLOBALS['audit_test_row'];
	}

	public function prepare( mixed $query, mixed ...$args ): string { return (string) $query; }

	public function insert( mixed $table, array $data, mixed $format = null ): int {
		if ( str_contains( (string) $table, 'audit' ) ) {
			$GLOBALS['audit_test_rows'][] = $data;

			return 1;
		}

		$GLOBALS['audit_test_row'] = array_merge( array( 'id' => 7 ), $data );

		return 1;
	}

	public function update( mixed $table, array $data, mixed $where, mixed $format = null, mixed $where_format = null ): int {
		$GLOBALS['audit_test_row'] = array_merge( (array) $GLOBALS['audit_test_row'], $data );

		return 1;
	}

	public function delete( mixed $table, mixed $where, mixed $format = null ): int {
		$GLOBALS['audit_test_row'] = null;

		return 1;
	}
}

$GLOBALS['wpdb'] = new Papelito_Audit_Test_Wpdb();

/** Registra o listener sem executá-lo; o teste inspeciona a tabela, não o hook. */
function add_action( mixed $hook, mixed $callback = null, mixed $priority = 10, mixed $accepted = 1 ): bool { return true; }
/** Guarda o alerta publicado para inspeção; os demais eventos passam direto. */
function do_action( mixed ...$args ): bool {
	if ( 'papelito_shipping_provider_alert' === (string) ( $args[0] ?? '' ) ) {
		$GLOBALS['audit_test_alerts'][] = array_slice( $args, 1 );
	}

	return true;
}
/** Nenhum filtro instalado. */
function add_filter( mixed ...$args ): bool { return true; }
/** Devolve o valor recebido, sem filtro instalado. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed { return $value; }
/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Serialização usada pelo envelope e pelo log. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Nenhuma rota REST é exercitada. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Relógio fixo do teste. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-19 12:00:00'; }
/** Cadastro do vendor, lido pela configuração derivada. */
function get_user_meta( int $user_id, string $key, bool $single = false ): string {
	return $GLOBALS['audit_test_meta'][ $user_id ][ $key ] ?? '';
}
/** O vendor da fixture existe; qualquer outro ID também, para exercitar o 403. */
function get_user_by( mixed $field, mixed $value ): WP_User {
	return new WP_User( (int) $value, 'hash:' . AUDIT_TEST_ACCOUNT_PASS, 'vendor@example.test' );
}
/** Dados do usuário para o e-mail de alteração sensível. */
function get_userdata( int $user_id ): WP_User { return new WP_User( $user_id, '', 'vendor@example.test' ); }
/** Conferência de senha do core, contra o hash da fixture. */
function wp_check_password( mixed $password, mixed $hash, mixed $user_id = '' ): bool {
	return 'hash:' . (string) $password === (string) $hash;
}
/** Valida o e-mail do destinatário do aviso. */
function is_email( mixed $value ): bool { return false !== strpos( (string) $value, '@' ); }
/** Nenhum e-mail é enviado de verdade. */
function wp_mail( mixed ...$args ): bool { return true; }
/** Transients em memória, usados pela deduplicação de recusa repetida. */
function get_transient( mixed $key ): mixed { return $GLOBALS['audit_test_transients'][ (string) $key ] ?? false; }
/** Grava o transient em memória. */
function set_transient( mixed $key, mixed $value, mixed $ttl = 0 ): bool {
	$GLOBALS['audit_test_transients'][ (string) $key ] = $value;

	return true;
}
/** Apaga o transient em memória; é por aqui que o tíquete de reautenticação é queimado. */
function delete_transient( mixed $key ): bool {
	unset( $GLOBALS['audit_test_transients'][ (string) $key ] );

	return true;
}
/** Limite de escrita controlado pela fixture. */
function papelito_auth_rate_limit( mixed ...$args ): bool { return (bool) $GLOBALS['audit_test_rate_ok']; }
/** O cofre de PII não deve mais ser usado para credencial de transportadora. */
function papelito_pii_encrypt( string $plain ): string { return AUDIT_TEST_PII_ENVELOPE; }
/** Cofre próprio da credencial do vendor, com envelope distinguível. */
function papelito_vendor_secret_encrypt( string $plain ): string { return AUDIT_TEST_ENVELOPE; }
/** Abertura do cofre próprio, restrita ao teste. */
function papelito_vendor_secret_decrypt( string $envelope ): string {
	return wp_json_encode( array( 'username' => AUDIT_TEST_USERNAME, 'password' => AUDIT_TEST_SECRET ) );
}
/** O envelope da fixture decifra para a credencial da fixture. */
function papelito_pii_decrypt( string $envelope ): mixed {
	return wp_json_encode( array( 'username' => AUDIT_TEST_USERNAME, 'password' => AUDIT_TEST_SECRET ) );
}
/** O CNPJ da fixture é válido. */
function papelito_validate_cnpj( string $cnpj ): bool { return AUDIT_TEST_CNPJ === $cnpj; }

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';
require_once dirname( __DIR__ ) . '/includes/shipping_observability.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function audit_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Recria o cadastro do vendor e zera as linhas de auditoria. */
function audit_reset( ?array $row = null ): void {
	$GLOBALS['audit_test_meta'][ AUDIT_TEST_VENDOR_ID ] = array( 'cnpj' => AUDIT_TEST_CNPJ, 'cep' => AUDIT_TEST_CEP );
	$GLOBALS['audit_test_row']                          = $row;
	$GLOBALS['audit_test_rows']                         = array();
	$GLOBALS['audit_test_rate_ok']                      = true;
	$GLOBALS['audit_test_alerts']                       = array();
	$GLOBALS['audit_test_transients']                   = array();
}

/** Corpo de requisição que troca a credencial write-only. */
function audit_credential_payload(): array {
	return array(
		'currentPassword' => AUDIT_TEST_ACCOUNT_PASS,
		'origin_cep'      => AUDIT_TEST_CEP,
		'enabled'         => true,
		'username'        => AUDIT_TEST_USERNAME,
		'password'        => AUDIT_TEST_SECRET,
	);
}

/** Última linha de auditoria gravada. */
function audit_last_row(): array {
	return $GLOBALS['audit_test_rows'][ count( $GLOBALS['audit_test_rows'] ) - 1 ] ?? array();
}

echo "Cenário 1: a troca de credencial é auditada com ator, vendor, ação e status\n";
audit_reset();

papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, audit_credential_payload(), AUDIT_TEST_VENDOR_ID );
$row = audit_last_row();

audit_assert( 'Uma linha de auditoria foi gravada', 1 === count( $GLOBALS['audit_test_rows'] ) );
audit_assert( 'A linha identifica o vendor', AUDIT_TEST_VENDOR_ID === ( $row['vendor_id'] ?? null ) );
audit_assert( 'A linha identifica o ator', AUDIT_TEST_VENDOR_ID === ( $row['actor_user_id'] ?? null ) );
audit_assert( 'A linha nomeia a ação tentada', 'credentials_saved' === ( $row['action'] ?? null ) );
audit_assert( 'A linha registra o status do desfecho', 'success' === ( $row['status'] ?? null ) );

echo "\nCenário 2: a tentativa recusada também deixa rastro\n";
audit_reset();

$forbidden = papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, audit_credential_payload(), AUDIT_TEST_INTRUDER_ID );
$row       = audit_last_row();

audit_assert( 'A tentativa de mexer em outra loja é recusada', is_wp_error( $forbidden ) );
audit_assert( 'A recusa por autorização é auditada', 1 === count( $GLOBALS['audit_test_rows'] ) );
audit_assert( 'O status distingue recusa de sucesso', 'denied' === ( $row['status'] ?? null ) );
audit_assert( 'O ator registrado é quem tentou, não o dono da loja', AUDIT_TEST_INTRUDER_ID === ( $row['actor_user_id'] ?? null ) );
audit_assert( 'O vendor registrado é a loja alvo', AUDIT_TEST_VENDOR_ID === ( $row['vendor_id'] ?? null ) );

echo "\nCenário 3: senha da conta errada não passa despercebida\n";
audit_reset();

$payload                    = audit_credential_payload();
$payload['currentPassword'] = 'senha-errada';
papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, $payload, AUDIT_TEST_VENDOR_ID );
$row = audit_last_row();

audit_assert( 'A reautenticação recusada é auditada', 1 === count( $GLOBALS['audit_test_rows'] ) );
audit_assert( 'Reautenticação recusada é recusa, não falha do sistema', 'denied' === ( $row['status'] ?? null ) );
audit_assert( 'A ação registrada é a que foi tentada', 'credentials_saved' === ( $row['action'] ?? null ) );

echo "\nCenário 4: o limite de escrita e a validação têm status próprios\n";
audit_reset();
$GLOBALS['audit_test_rate_ok'] = false;

papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, audit_credential_payload(), AUDIT_TEST_VENDOR_ID );
audit_assert( 'O bloqueio por limite de escrita é auditado como recusa', 'denied' === ( audit_last_row()['status'] ?? null ) );

audit_reset();
$payload               = audit_credential_payload();
$payload['origin_cep'] = '123';
papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, $payload, AUDIT_TEST_VENDOR_ID );
audit_assert( 'Dado inválido do formulário é rejeição, não recusa', 'rejected' === ( audit_last_row()['status'] ?? null ) );

echo "\nCenário 5: nenhum vestígio do segredo chega à auditoria\n";
audit_reset();

papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, audit_credential_payload(), AUDIT_TEST_VENDOR_ID );
$serialized = wp_json_encode( $GLOBALS['audit_test_rows'] );

foreach (
	array(
		'a senha Braspress'        => AUDIT_TEST_SECRET,
		'um prefixo da senha'      => substr( AUDIT_TEST_SECRET, 0, 6 ),
		'o usuário Braspress'      => AUDIT_TEST_USERNAME,
		'a senha da conta'         => AUDIT_TEST_ACCOUNT_PASS,
		'o envelope cifrado'       => AUDIT_TEST_ENVELOPE,
		'o CNPJ do vendor'         => AUDIT_TEST_CNPJ,
	) as $label => $needle
) {
	audit_assert( "A auditoria não guarda {$label}", false === strpos( $serialized, $needle ) );
}

audit_assert(
	'A auditoria não guarda o tamanho do segredo',
	false === strpos( $serialized, (string) strlen( AUDIT_TEST_SECRET ) )
);

echo "\nCenário 6: a remoção da integração é auditada como remoção\n";
audit_reset( array( 'id' => 7, 'vendor_id' => AUDIT_TEST_VENDOR_ID, 'provider' => PAPELITO_VENDOR_INTEGRATION_PROVIDER, 'config_json' => wp_json_encode( array( 'origin_cep' => AUDIT_TEST_CEP ) ), 'secret_envelope' => AUDIT_TEST_ENVELOPE, 'enabled' => 1, 'status' => PAPELITO_VENDOR_INTEGRATION_ACTIVE ) );

papelito_vendor_integration_delete_braspress( AUDIT_TEST_VENDOR_ID, array( 'currentPassword' => AUDIT_TEST_ACCOUNT_PASS ), AUDIT_TEST_VENDOR_ID );
$row = audit_last_row();

audit_assert( 'A remoção é auditada', 'removed' === ( $row['action'] ?? null ) );
audit_assert( 'A remoção bem-sucedida tem status de sucesso', 'success' === ( $row['status'] ?? null ) );

echo "\nCenário 7: trocar a credencial recusada fecha o alerta aberto\n";
audit_reset(
	array(
		'id'                    => 7,
		'vendor_id'             => AUDIT_TEST_VENDOR_ID,
		'provider'              => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'config_json'           => wp_json_encode( array( 'origin_cep' => AUDIT_TEST_CEP ) ),
		'secret_envelope'       => AUDIT_TEST_ENVELOPE,
		'configuration_version' => 4,
		'enabled'               => 1,
		'status'                => PAPELITO_VENDOR_INTEGRATION_INVALID,
	)
);

papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, audit_credential_payload(), AUDIT_TEST_VENDOR_ID );

audit_assert(
	'A credencial nova tira a integração de invalid_credentials',
	PAPELITO_VENDOR_INTEGRATION_READY === ( $GLOBALS['audit_test_row']['status'] ?? null )
);
audit_assert(
	'A recuperação é publicada, para fechar o alerta que abriu no 401',
	1 === count( $GLOBALS['audit_test_alerts'] )
	&& PAPELITO_VENDOR_INTEGRATION_READY === ( $GLOBALS['audit_test_alerts'][0][1] ?? null )
	&& PAPELITO_VENDOR_INTEGRATION_INVALID === ( $GLOBALS['audit_test_alerts'][0][2]['previous_state'] ?? null )
);

echo "\nCenário 8: salvar sem sair de estado degradado não gera alerta\n";
audit_reset(
	array(
		'id'                    => 7,
		'vendor_id'             => AUDIT_TEST_VENDOR_ID,
		'provider'              => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'config_json'           => wp_json_encode( array( 'origin_cep' => AUDIT_TEST_CEP ) ),
		'secret_envelope'       => AUDIT_TEST_ENVELOPE,
		'configuration_version' => 4,
		'enabled'               => 1,
		'status'                => PAPELITO_VENDOR_INTEGRATION_ACTIVE,
	)
);

papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, audit_credential_payload(), AUDIT_TEST_VENDOR_ID );

audit_assert(
	'Trocar credencial de uma conta saudável não abre nem fecha alerta',
	array() === $GLOBALS['audit_test_alerts']
);

echo "\nCenário 9: a credencial da transportadora não usa o cofre de PII\n";
audit_reset();

papelito_vendor_integration_save_braspress( AUDIT_TEST_VENDOR_ID, audit_credential_payload(), AUDIT_TEST_VENDOR_ID );

audit_assert(
	'O envelope persistido vem do cofre de integração',
	AUDIT_TEST_ENVELOPE === ( $GLOBALS['audit_test_row']['secret_envelope'] ?? null )
);
audit_assert(
	'A chave que protege CPF e nascimento não é usada para credencial de vendor',
	AUDIT_TEST_PII_ENVELOPE !== ( $GLOBALS['audit_test_row']['secret_envelope'] ?? null )
);

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
