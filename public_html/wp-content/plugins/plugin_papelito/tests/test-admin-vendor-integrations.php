<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.
/**
 * Superfície administrativa da integração Braspress de um vendor.
 *
 * O que esta suíte fixa: quem passa pelos porteiros das três rotas, que o id da
 * rota precisa ser mesmo um vendor, que a gravação administrativa não pede
 * senha nem tíquete de reautenticação, que nenhuma resposta carrega usuário,
 * senha ou envelope, que desligar pelo painel administrativo tira a Braspress
 * da cotação, e que o caminho do vendor continua exigindo a prova de identidade
 * que sempre exigiu.
 *
 * Usage: php tests/test-admin-vendor-integrations.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_DIGITS_REGEX', '/\D+/' );
define( 'PAPELITO_REST_NAMESPACE', 'papelito/v1' );
define( 'PAPELITO_ADMIN_VENDORS_REST_NAMESPACE', 'papelito/v1/admin' );
define( 'PAPELITO_AUTH_RATE_LIMIT_MESSAGE', 'Muitas tentativas. Tente novamente em alguns minutos.' );

const ADMIN_INT_TEST_VENDOR_ID     = 4101;
const ADMIN_INT_TEST_OTHER_ID      = 4202;
const ADMIN_INT_TEST_ADMIN_ID      = 4303;
const ADMIN_INT_TEST_CUSTOMER_ID   = 4404;
const ADMIN_INT_TEST_VENDOR_CNPJ   = '20024291000165';
const ADMIN_INT_TEST_VENDOR_CEP    = '14711142';
const ADMIN_INT_TEST_ORIGIN_CEP    = '14700000';
const ADMIN_INT_TEST_USERNAME      = 'braspress-usuario-do-contrato';
const ADMIN_INT_TEST_SECRET        = 'Zk7-senha-do-contrato-da-loja';
const ADMIN_INT_TEST_ENVELOPE      = 'k1:envelope-cifrado-da-loja';
const ADMIN_INT_TEST_INTEGRATIONS  = '/vendors/(?P<id>\d+)/integrations';
const ADMIN_INT_TEST_BRASPRESS     = '/vendors/(?P<id>\d+)/integrations/braspress';

$GLOBALS['admin_int_meta']       = array();
$GLOBALS['admin_int_roles']      = array();
$GLOBALS['admin_int_caps']       = array();
$GLOBALS['admin_int_current']    = 0;
$GLOBALS['admin_int_routes']     = array();
$GLOBALS['admin_int_rate_ok']    = true;
$GLOBALS['admin_int_mails']      = array();
$GLOBALS['admin_int_transients'] = array();

/** Erro do core com a leitura de código, mensagem e `status` usada pelas rotas. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

/** Usuário do core, com os papéis que o porteiro e a validação do alvo leem. */
class WP_User {
	public array $roles = array();
	public function __construct( public int $ID = 0, public string $user_pass = '', public string $user_email = '' ) {}
	public function exists(): bool { return $this->ID > 0; }
}

/** Requisição REST reduzida ao id da rota e ao corpo JSON. */
class WP_REST_Request implements ArrayAccess {
	public function __construct( private array $params = array(), private array $payload = array() ) {}
	public function get_json_params(): mixed { return $this->payload; }
	public function offsetExists( mixed $offset ): bool { return isset( $this->params[ $offset ] ); }
	public function offsetGet( mixed $offset ): mixed { return $this->params[ $offset ] ?? null; }
	public function offsetSet( mixed $offset, mixed $value ): void { $this->params[ $offset ] = $value; }
	public function offsetUnset( mixed $offset ): void { unset( $this->params[ $offset ] ); }
}

/** Resposta REST reduzida ao par dado/status que o teste inspeciona. */
class WP_REST_Response {
	public function __construct( private mixed $data = null, private int $status = 200 ) {}
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

/** Verbos do core, usados no registro das rotas administrativas. */
class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
	const CREATABLE = 'POST';
}

/**
 * Lê da cláusula `WHERE` o vendor que a consulta declarou.
 *
 * O harness devolve linha apenas para consulta escopada: sem a cláusula, a
 * leitura não encontra nada e a consulta fica registrada como sem escopo.
 */
function admin_int_scoped_vendor( string $sql ): int {
	return preg_match( '/vendor_id\s*=\s*(\d+)/', $sql, $matches ) ? (int) $matches[1] : 0;
}

/** `$wpdb` que honra o escopo por vendor e guarda a trilha para inspeção. */
class Papelito_Admin_Int_Test_Wpdb {
	public string $prefix = 'wp_';
	public array $rows = array();
	public array $audit = array();
	public array $unscoped_reads = array();
	private int $next_id = 500;

	public function get_charset_collate(): string { return ''; }

	public function prepare( mixed $query, mixed ...$args ): string {
		$sql = (string) $query;
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
			$sql         = (string) preg_replace( '/%[dsf]/', $replacement, $sql, 1 );
		}

		return $sql;
	}

	public function get_row( mixed $query, mixed $output = null ): ?array {
		$vendor_id = admin_int_scoped_vendor( (string) $query );
		if ( 0 === $vendor_id ) {
			$this->unscoped_reads[] = (string) $query;

			return null;
		}

		foreach ( $this->rows as $row ) {
			if ( (int) ( $row['vendor_id'] ?? 0 ) === $vendor_id ) {
				return $row;
			}
		}

		return null;
	}

	public function insert( mixed $table, array $data, mixed $format = null ): int {
		if ( str_contains( (string) $table, 'audit' ) ) {
			$this->audit[] = $data;

			return 1;
		}

		$id                = ++$this->next_id;
		$this->rows[ $id ] = array_merge( array( 'id' => $id ), $data );

		return 1;
	}

	public function update( mixed $table, array $data, mixed $where, mixed $format = null, mixed $where_format = null ): int {
		$id = (int) ( ( (array) $where )['id'] ?? 0 );
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}

		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );

		return 1;
	}

	public function delete( mixed $table, mixed $where, mixed $format = null ): int {
		$conditions = (array) $where;
		$vendor_id  = (int) ( $conditions['vendor_id'] ?? 0 );
		$removed    = 0;

		foreach ( $this->rows as $id => $row ) {
			$same_vendor   = (int) ( $row['vendor_id'] ?? 0 ) === $vendor_id;
			$same_provider = ( $conditions['provider'] ?? '' ) === ( $row['provider'] ?? '' );
			if ( $same_vendor && $same_provider ) {
				unset( $this->rows[ $id ] );
				++$removed;
			}
		}

		return $removed;
	}
}

$GLOBALS['wpdb'] = new Papelito_Admin_Int_Test_Wpdb();

/** Nenhum listener é executado; o teste inspeciona retorno e tabela. */
function add_action( mixed $hook, mixed $callback = null, mixed $priority = 10, mixed $accepted = 1 ): bool { return true; }
/** Nenhum filtro instalado. */
function add_filter( mixed ...$args ): bool { return true; }
/** Os eventos publicados não são consumidos por este teste. */
function do_action( mixed ...$args ): bool { return true; }
/** Devolve o valor recebido, sem filtro instalado. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed { return $value; }
/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Guarda o registro das rotas para conferir método, porteiro e handler. */
function register_rest_route( mixed $namespace, mixed $route, mixed $args = array(), mixed $override = false ): bool {
	$GLOBALS['admin_int_routes'][ (string) $route ] = (array) $args;

	return true;
}
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Sanitização de URL não exercitada, presente para o módulo carregar. */
function sanitize_url( mixed $value ): string { return (string) $value; }
/** Escape de URL não exercitado, presente para o módulo carregar. */
function esc_url_raw( mixed $value ): string { return (string) $value; }
/** Serialização usada pela configuração persistida e pelas asserções. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Relógio fixo do teste. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-22 12:00:00'; }
/** Relógio fixo exigido pelos módulos carregados junto. */
function papelito_current_utc_mysql(): string { return '2026-09-22 12:00:00'; }
/** Cadastro do vendor, lido pela configuração derivada. */
function get_user_meta( mixed $user_id, string $key, bool $single = false ): string {
	return $GLOBALS['admin_int_meta'][ (int) $user_id ][ $key ] ?? '';
}
/** Gravação de cadastro usada apenas para montar fixturas. */
function update_user_meta( mixed $user_id, string $key, mixed $value ): bool {
	$GLOBALS['admin_int_meta'][ (int) $user_id ][ $key ] = $value;

	return true;
}
/** Remoção de cadastro usada apenas para montar fixturas. */
function delete_user_meta( mixed $user_id, string $key ): bool {
	unset( $GLOBALS['admin_int_meta'][ (int) $user_id ][ $key ] );

	return true;
}
/** Monta o usuário da fixture com o papel daquele ID. */
function admin_int_user( int $user_id ): WP_User {
	$user        = new WP_User( $user_id, 'hash:senha', 'conta' . $user_id . '@example.test' );
	$user->roles = $GLOBALS['admin_int_roles'][ $user_id ] ?? array();

	return $user;
}
/** Usuário autenticado da requisição sob teste. */
function wp_get_current_user(): WP_User { return admin_int_user( (int) $GLOBALS['admin_int_current'] ); }
/** Identidade da sessão, única fonte do ator das rotas. */
function get_current_user_id(): int { return (int) $GLOBALS['admin_int_current']; }
/** Usuário buscado pela validação do alvo e pela reautenticação. */
function get_user_by( mixed $field, mixed $value ): ?WP_User {
	$user_id = (int) $value;

	return isset( $GLOBALS['admin_int_roles'][ $user_id ] ) ? admin_int_user( $user_id ) : null;
}
/** Dados do usuário para o aviso de alteração sensível. */
function get_userdata( mixed $user_id ): WP_User { return admin_int_user( (int) $user_id ); }
/** Capability do ator autenticado, controlada pela fixture. */
function current_user_can( mixed $capability, mixed ...$args ): bool {
	$granted = $GLOBALS['admin_int_caps'][ (int) $GLOBALS['admin_int_current'] ] ?? array();

	return in_array( (string) $capability, $granted, true );
}
/** Conferência de senha do core, contra o hash da fixture. */
function wp_check_password( mixed $password, mixed $hash, mixed $user_id = '' ): bool {
	return 'hash:' . (string) $password === (string) $hash;
}
/** Gerador do tíquete de reautenticação do vendor. */
function wp_generate_password( mixed $length = 12, mixed $special = true, mixed $extra = false ): string {
	return 'ticket-gerado-para-o-vendor';
}
/** Sal fixo para o HMAC do tíquete. */
function wp_salt( mixed $scheme = 'auth' ): string { return 'sal-do-teste'; }
/** Valida o e-mail do destinatário do aviso. */
function is_email( mixed $value ): bool { return str_contains( (string) $value, '@' ); }
/** Guarda o aviso sem enviar e-mail de verdade. */
function wp_mail( mixed $to, mixed $subject = '', mixed $message = '', mixed $headers = '', mixed $attachments = array() ): bool {
	$GLOBALS['admin_int_mails'][] = array(
		'to'      => (string) $to,
		'message' => (string) $message,
	);

	return true;
}
/** Transients em memória, usados pela deduplicação de recusa repetida. */
function get_transient( mixed $key ): mixed { return $GLOBALS['admin_int_transients'][ (string) $key ] ?? false; }
/** Grava o transient em memória. */
function set_transient( mixed $key, mixed $value, mixed $ttl = 0 ): bool {
	$GLOBALS['admin_int_transients'][ (string) $key ] = $value;

	return true;
}
/** Apaga o transient em memória. */
function delete_transient( mixed $key ): bool {
	unset( $GLOBALS['admin_int_transients'][ (string) $key ] );

	return true;
}
/** Limite de escrita controlado pela fixture; a política tem suíte própria. */
function papelito_auth_rate_limit( mixed ...$args ): bool { return (bool) $GLOBALS['admin_int_rate_ok']; }
/** Cofre de integração, com envelope reconhecível nas asserções. */
function papelito_vendor_secret_encrypt( string $plain ): string { return ADMIN_INT_TEST_ENVELOPE; }
/** Abertura do cofre, exercitada pela resolução do adapter. */
function papelito_vendor_secret_decrypt( string $envelope ): string {
	return wp_json_encode(
		array(
			'username' => ADMIN_INT_TEST_USERNAME,
			'password' => ADMIN_INT_TEST_SECRET,
		)
	);
}
/** O CNPJ da fixture é válido. */
function papelito_validate_cnpj( string $cnpj ): bool { return ADMIN_INT_TEST_VENDOR_CNPJ === $cnpj; }
/** Validador de id de vendor das rotas administrativas, definido junto das rotas reais. */
function papelito_admin_vendors_validate_id( mixed $value ): bool { return is_numeric( $value ) && (int) $value > 0; }

require_once dirname( __DIR__ ) . '/includes/support.php';
require_once dirname( __DIR__ ) . '/includes/account_status.php';
require_once dirname( __DIR__ ) . '/includes/pagarme_recipients.php';
require_once dirname( __DIR__ ) . '/includes/vendor_dashboard.php';
require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';
require_once dirname( __DIR__ ) . '/includes/admin_vendor_integrations.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function admin_int_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";

		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Recria o estado do banco e das fixturas entre cenários. */
function admin_int_reset(): void {
	$GLOBALS['wpdb']                 = new Papelito_Admin_Int_Test_Wpdb();
	$GLOBALS['admin_int_transients'] = array();
	$GLOBALS['admin_int_mails']      = array();
	$GLOBALS['admin_int_rate_ok']    = true;
	$GLOBALS['admin_int_meta']       = array(
		ADMIN_INT_TEST_VENDOR_ID => array(
			'cnpj' => ADMIN_INT_TEST_VENDOR_CNPJ,
			'cep'  => ADMIN_INT_TEST_VENDOR_CEP,
		),
	);
	$GLOBALS['admin_int_roles']      = array(
		ADMIN_INT_TEST_VENDOR_ID   => array( 'seller' ),
		ADMIN_INT_TEST_OTHER_ID    => array( 'seller' ),
		ADMIN_INT_TEST_ADMIN_ID    => array( 'administrator' ),
		ADMIN_INT_TEST_CUSTOMER_ID => array( 'customer' ),
	);
	$GLOBALS['admin_int_caps']       = array(
		ADMIN_INT_TEST_ADMIN_ID => array( 'manage_options' ),
	);
}

/** Autentica a requisição seguinte como o ator informado. */
function admin_int_login( int $user_id ): void {
	$GLOBALS['admin_int_current'] = $user_id;
}

/** Grava a integração do vendor pelo caminho administrativo. */
function admin_int_save( int $vendor_id, array $payload ): mixed {
	return papelito_admin_vendor_integrations_handle_save_braspress(
		new WP_REST_Request( array( 'id' => $vendor_id ), $payload )
	);
}

/** Lê o estado das integrações do vendor pelo caminho administrativo. */
function admin_int_list( int $vendor_id ): mixed {
	return papelito_admin_vendor_integrations_handle_list(
		new WP_REST_Request( array( 'id' => $vendor_id ) )
	);
}

/** Devolve a entrada da Braspress dentro do envelope de listagem. */
function admin_int_braspress_item( mixed $response ): array {
	$data = $response instanceof WP_REST_Response ? (array) $response->get_data() : array();

	return (array) ( ( $data['items'] ?? array() )[0] ?? array() );
}

/** Credencial completa aceita pela gravação administrativa. */
function admin_int_credential_payload( bool $enabled = true ): array {
	return array(
		'enabled'   => $enabled,
		'originCep' => ADMIN_INT_TEST_ORIGIN_CEP,
		'username'  => ADMIN_INT_TEST_USERNAME,
		'password'  => ADMIN_INT_TEST_SECRET,
	);
}

echo "Scenario 1: as três rotas administrativas são registradas com porteiro próprio\n";
admin_int_reset();
papelito_admin_vendor_integrations_register_routes();
$listing   = $GLOBALS['admin_int_routes'][ ADMIN_INT_TEST_INTEGRATIONS ] ?? array();
$braspress = $GLOBALS['admin_int_routes'][ ADMIN_INT_TEST_BRASPRESS ] ?? array();
admin_int_assert( 'a rota de listagem existe', array() !== $listing );
admin_int_assert( 'a listagem é GET com o porteiro de leitura', 'GET' === ( $listing['methods'] ?? '' ) && 'papelito_admin_vendor_integrations_permission_read' === ( $listing['permission_callback'] ?? '' ) );
admin_int_assert( 'a rota da Braspress existe', array() !== $braspress );
$braspress_methods = array();
foreach ( $braspress as $entry ) {
	if ( is_array( $entry ) && isset( $entry['methods'] ) ) {
		$braspress_methods[ (string) $entry['methods'] ] = (string) ( $entry['permission_callback'] ?? '' );
	}
}
admin_int_assert( 'a Braspress aceita escrita com o porteiro de escrita', 'papelito_admin_vendor_integrations_permission_write' === ( $braspress_methods['POST, PUT, PATCH'] ?? '' ) );
admin_int_assert( 'a Braspress aceita remoção com o porteiro de escrita', 'papelito_admin_vendor_integrations_permission_write' === ( $braspress_methods['DELETE'] ?? '' ) );

echo "Scenario 2: só quem tem manage_options passa pelos porteiros\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
admin_int_assert( 'o administrador passa na leitura', true === papelito_admin_vendor_integrations_permission_read() );
admin_int_assert( 'o administrador passa na escrita', true === papelito_admin_vendor_integrations_permission_write() );
foreach ( array( ADMIN_INT_TEST_VENDOR_ID, ADMIN_INT_TEST_CUSTOMER_ID, 0 ) as $actor ) {
	admin_int_login( $actor );
	$denied = papelito_admin_vendor_integrations_permission_write();
	admin_int_assert( "o ator {$actor} é recusado na escrita", is_wp_error( $denied ) && 403 === ( $denied->get_error_data()['status'] ?? 0 ) );
}
admin_int_login( ADMIN_INT_TEST_VENDOR_ID );
papelito_admin_vendor_integrations_permission_write();
$denied_trail = array_filter(
	$GLOBALS['wpdb']->audit,
	static fn ( array $row ): bool => PAPELITO_VENDOR_INTEGRATION_AUDIT_DENIED === ( $row['status'] ?? '' )
);
admin_int_assert( 'a recusa fica registrada na trilha', array() !== $denied_trail );

echo "Scenario 3: o id da rota precisa ser mesmo um vendor\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
$not_vendor = admin_int_list( ADMIN_INT_TEST_CUSTOMER_ID );
admin_int_assert( 'comprador não é alvo válido', is_wp_error( $not_vendor ) && 'papelito_admin_vendor_integration_vendor_not_found' === $not_vendor->get_error_code() );
$missing = admin_int_save( 99999, admin_int_credential_payload() );
admin_int_assert( 'usuário inexistente devolve 404', is_wp_error( $missing ) && 404 === ( $missing->get_error_data()['status'] ?? 0 ) );

echo "Scenario 4: vendor sem configuração aparece como não integrado\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
$empty = admin_int_braspress_item( admin_int_list( ADMIN_INT_TEST_VENDOR_ID ) );
admin_int_assert( 'a Braspress aparece mesmo sem linha no banco', 'braspress' === ( $empty['provider'] ?? '' ) );
admin_int_assert( 'o rótulo do catálogo acompanha a entrada', 'Braspress' === ( $empty['label'] ?? '' ) );
admin_int_assert( 'o estado é unconfigured', PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED === ( $empty['status'] ?? '' ) );
admin_int_assert( 'não há credencial configurada', false === ( $empty['credentials_configured'] ?? true ) );

echo "Scenario 5: o administrador cadastra a credencial sem senha de ninguém\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
$saved = admin_int_save( ADMIN_INT_TEST_VENDOR_ID, admin_int_credential_payload() );
admin_int_assert( 'a gravação é aceita sem tíquete e sem senha', ! is_wp_error( $saved ) );
$state = $saved instanceof WP_REST_Response ? (array) $saved->get_data() : array();
admin_int_assert( 'a integração fica habilitada', true === ( $state['enabled'] ?? false ) );
admin_int_assert( 'a integração fica pronta para cotar', PAPELITO_VENDOR_INTEGRATION_READY === ( $state['status'] ?? '' ) );
admin_int_assert( 'a credencial é reconhecida como configurada', true === ( $state['credentials_configured'] ?? false ) );
$stored = papelito_vendor_integration_find_row( ADMIN_INT_TEST_VENDOR_ID );
admin_int_assert( 'o envelope guardado é o do cofre', ADMIN_INT_TEST_ENVELOPE === ( $stored['secret_envelope'] ?? '' ) );
admin_int_assert( 'a linha guarda o administrador como autor', ADMIN_INT_TEST_ADMIN_ID === (int) ( $stored['updated_by'] ?? 0 ) );

echo "Scenario 6: nenhuma resposta administrativa devolve credencial\n";
$serialized = wp_json_encode( admin_int_list( ADMIN_INT_TEST_VENDOR_ID )->get_data() );
admin_int_assert( 'a listagem não traz o usuário Braspress', ! str_contains( $serialized, ADMIN_INT_TEST_USERNAME ) );
admin_int_assert( 'a listagem não traz a senha Braspress', ! str_contains( $serialized, ADMIN_INT_TEST_SECRET ) );
admin_int_assert( 'a listagem não traz o envelope cifrado', ! str_contains( $serialized, ADMIN_INT_TEST_ENVELOPE ) );
admin_int_assert( 'a gravação também não devolve segredo', ! str_contains( wp_json_encode( $state ), ADMIN_INT_TEST_SECRET ) );
admin_int_assert( 'nenhuma consulta escapou do escopo por vendor', array() === $GLOBALS['wpdb']->unscoped_reads );

echo "Scenario 7: a trilha distingue a gravação administrativa da do vendor\n";
$admin_actions = array_column( $GLOBALS['wpdb']->audit, 'action' );
admin_int_assert( 'a ação gravada é marcada como administrativa', in_array( 'admin_credentials_saved', $admin_actions, true ) );
$credential_entry = array_values(
	array_filter(
		$GLOBALS['wpdb']->audit,
		static fn ( array $row ): bool => 'admin_credentials_saved' === ( $row['action'] ?? '' )
	)
)[0] ?? array();
admin_int_assert( 'a trilha guarda o vendor afetado', ADMIN_INT_TEST_VENDOR_ID === (int) ( $credential_entry['vendor_id'] ?? 0 ) );
admin_int_assert( 'a trilha guarda o administrador que agiu', ADMIN_INT_TEST_ADMIN_ID === (int) ( $credential_entry['actor_user_id'] ?? 0 ) );
admin_int_assert( 'a trilha guarda quando aconteceu', '' !== (string) ( $credential_entry['created_at'] ?? '' ) );
admin_int_assert( 'a trilha não guarda segredo', ! str_contains( wp_json_encode( $credential_entry ), ADMIN_INT_TEST_SECRET ) );
admin_int_assert( 'o titular é avisado da alteração de credencial', array() !== $GLOBALS['admin_int_mails'] );
admin_int_assert( 'o aviso ao titular não carrega segredo', ! str_contains( wp_json_encode( $GLOBALS['admin_int_mails'] ), ADMIN_INT_TEST_SECRET ) );

echo "Scenario 8: desligar pelo painel administrativo tira a Braspress da cotação\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
admin_int_save( ADMIN_INT_TEST_VENDOR_ID, admin_int_credential_payload() );
admin_int_assert( 'ligada, a integração é resolvida para o adapter', is_array( papelito_vendor_integration_resolve_braspress( ADMIN_INT_TEST_VENDOR_ID ) ) );
$turned_off = admin_int_save(
	ADMIN_INT_TEST_VENDOR_ID,
	array(
		'enabled'   => false,
		'originCep' => ADMIN_INT_TEST_ORIGIN_CEP,
	)
);
admin_int_assert( 'desligar não pede credencial nem prova de identidade', ! is_wp_error( $turned_off ) );
admin_int_assert( 'o adapter deixa de resolver a integração', null === papelito_vendor_integration_resolve_braspress( ADMIN_INT_TEST_VENDOR_ID ) );
$preserved = papelito_vendor_integration_find_row( ADMIN_INT_TEST_VENDOR_ID );
admin_int_assert( 'a credencial continua guardada depois de desligar', ADMIN_INT_TEST_ENVELOPE === ( $preserved['secret_envelope'] ?? '' ) );
$turned_on = admin_int_save(
	ADMIN_INT_TEST_VENDOR_ID,
	array(
		'enabled'   => true,
		'originCep' => ADMIN_INT_TEST_ORIGIN_CEP,
	)
);
admin_int_assert( 'religar não exige reinformar a credencial', ! is_wp_error( $turned_on ) );
admin_int_assert( 'o adapter volta a resolver a integração', is_array( papelito_vendor_integration_resolve_braspress( ADMIN_INT_TEST_VENDOR_ID ) ) );

echo "Scenario 9: ligar com cadastro incompleto é recusado\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
delete_user_meta( ADMIN_INT_TEST_VENDOR_ID, 'cnpj' );
$incomplete = admin_int_save( ADMIN_INT_TEST_VENDOR_ID, admin_int_credential_payload() );
admin_int_assert( 'sem CNPJ no cadastro a integração não liga', is_wp_error( $incomplete ) && 'papelito_vendor_integration_profile_incomplete' === $incomplete->get_error_code() );
admin_int_assert( 'a recusa devolve 422', is_wp_error( $incomplete ) && 422 === ( $incomplete->get_error_data()['status'] ?? 0 ) );

echo "Scenario 10: usuário e senha continuam sendo um par\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
$half = admin_int_save(
	ADMIN_INT_TEST_VENDOR_ID,
	array(
		'enabled'   => false,
		'originCep' => ADMIN_INT_TEST_ORIGIN_CEP,
		'username'  => ADMIN_INT_TEST_USERNAME,
	)
);
admin_int_assert( 'usuário sem senha é recusado', is_wp_error( $half ) && 'papelito_vendor_integration_credentials_incomplete' === $half->get_error_code() );

echo "Scenario 11: o administrador remove a integração\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
admin_int_save( ADMIN_INT_TEST_VENDOR_ID, admin_int_credential_payload() );
$removed = papelito_admin_vendor_integrations_handle_delete_braspress(
	new WP_REST_Request( array( 'id' => ADMIN_INT_TEST_VENDOR_ID ) )
);
admin_int_assert( 'a remoção é aceita sem prova de identidade', ! is_wp_error( $removed ) );
admin_int_assert( 'a linha deixa de existir', null === papelito_vendor_integration_find_row( ADMIN_INT_TEST_VENDOR_ID ) );
admin_int_assert( 'a remoção é marcada como administrativa', in_array( 'admin_removed', array_column( $GLOBALS['wpdb']->audit, 'action' ), true ) );

echo "Scenario 12: a integração de um vendor não alcança a do outro\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
admin_int_save( ADMIN_INT_TEST_VENDOR_ID, admin_int_credential_payload() );
$other = admin_int_braspress_item( admin_int_list( ADMIN_INT_TEST_OTHER_ID ) );
admin_int_assert( 'o outro vendor continua sem credencial', false === ( $other['credentials_configured'] ?? true ) );
admin_int_assert( 'o outro vendor continua desligado', false === ( $other['enabled'] ?? true ) );

echo "Scenario 13: o caminho do vendor grava a própria loja, e só ela\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_VENDOR_ID );
$own = papelito_vendor_integration_save_braspress(
	ADMIN_INT_TEST_VENDOR_ID,
	admin_int_credential_payload(),
	ADMIN_INT_TEST_VENDOR_ID
);
admin_int_assert( 'o vendor troca a própria credencial sem confirmar a senha', ! is_wp_error( $own ) );
$foreign = papelito_vendor_integration_save_braspress(
	ADMIN_INT_TEST_OTHER_ID,
	admin_int_credential_payload(),
	ADMIN_INT_TEST_VENDOR_ID
);
admin_int_assert( 'o vendor não escreve na integração de outro', is_wp_error( $foreign ) && 'papelito_vendor_integration_forbidden' === $foreign->get_error_code() );
admin_int_assert( 'a gravação do vendor não é marcada como administrativa', in_array( 'credentials_saved', array_column( $GLOBALS['wpdb']->audit, 'action' ), true ) );

echo "Scenario 14: o limite de escrita administrativa é aplicado\n";
admin_int_reset();
admin_int_login( ADMIN_INT_TEST_ADMIN_ID );
$GLOBALS['admin_int_rate_ok'] = false;
$limited                      = admin_int_save( ADMIN_INT_TEST_VENDOR_ID, admin_int_credential_payload() );
admin_int_assert( 'a escrita é recusada com 429', is_wp_error( $limited ) && 429 === ( $limited->get_error_data()['status'] ?? 0 ) );
admin_int_assert( 'nada foi gravado sob limite', null === papelito_vendor_integration_find_row( ADMIN_INT_TEST_VENDOR_ID ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
