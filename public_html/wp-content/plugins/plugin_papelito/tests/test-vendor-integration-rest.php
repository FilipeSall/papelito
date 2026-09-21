<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.
/**
 * Camada REST da configuração Braspress do vendor: porteiro, escopo e write-only.
 *
 * A suíte de auditoria cobre o serviço; esta cobre a rota, que até agora nenhum
 * teste chamava. Ela fixa quem passa pelos dois `permission_callback`, que a
 * identidade da loja vem da sessão e não do corpo, que a leitura nunca carrega
 * usuário, senha ou envelope, e que um corpo recusado deixa a configuração
 * anterior exatamente como estava.
 *
 * Usage: php tests/test-vendor-integration-rest.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_DIGITS_REGEX', '/\D+/' );
define( 'PAPELITO_REST_NAMESPACE', 'papelito/v1' );
define( 'PAPELITO_AUTH_RATE_LIMIT_MESSAGE', 'Muitas tentativas. Tente novamente em alguns minutos.' );

const REST_TEST_VENDOR_A_ID    = 3101;
const REST_TEST_VENDOR_B_ID    = 3202;
const REST_TEST_CUSTOMER_ID    = 3303;
const REST_TEST_SUSPENDED_ID   = 3404;
const REST_TEST_VENDOR_A_CNPJ  = '20024291000165';
const REST_TEST_VENDOR_B_CNPJ  = '19131243000197';
const REST_TEST_VENDOR_A_CEP   = '14711142';
const REST_TEST_VENDOR_B_CEP   = '01310930';
const REST_TEST_ACCOUNT_PASS   = 'senha-da-conta-do-vendor';
const REST_TEST_USERNAME_A     = 'braspress-usuario-da-loja-a';
const REST_TEST_USERNAME_B     = 'braspress-usuario-da-loja-b';
const REST_TEST_SECRET_A       = 'Zk7-senha-do-contrato-da-loja-a';
const REST_TEST_SECRET_B       = 'Qw9-senha-do-contrato-da-loja-b';
const REST_TEST_ENVELOPE_A     = 'k1:envelope-da-loja-a';
const REST_TEST_ENVELOPE_B     = 'k1:envelope-da-loja-b';
const REST_TEST_SUSPENDED_META = 'suspended';

$GLOBALS['rest_test_meta']     = array();
$GLOBALS['rest_test_roles']    = array();
$GLOBALS['rest_test_current']  = 0;
$GLOBALS['rest_test_routes']   = array();
$GLOBALS['rest_test_rate_ok']  = true;
$GLOBALS['rest_test_envelope'] = REST_TEST_ENVELOPE_A;
$GLOBALS['rest_test_mails']    = array();
$GLOBALS['rest_test_transients'] = array();

/** Erro do core com a leitura de código, mensagem e `status` usada pela rota. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

/** Usuário do core, com papéis e senha cifrada que o porteiro e a reautenticação leem. */
class WP_User {
	public array $roles = array();
	public function __construct( public int $ID = 0, public string $user_pass = '', public string $user_email = '' ) {}
	public function exists(): bool { return $this->ID > 0; }
}

/** Requisição REST reduzida ao corpo JSON, única entrada que os handlers consomem. */
class WP_REST_Request {
	public function __construct( private array $payload = array() ) {}
	public function get_json_params(): array { return $this->payload; }
}

/** Resposta REST reduzida ao par dado/status que o teste inspeciona. */
class WP_REST_Response {
	public function __construct( private mixed $data = null, private int $status = 200 ) {}
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

/** Verbos do core, usados no registro das três rotas. */
class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
	const CREATABLE = 'POST';
}

/**
 * Lê da cláusula `WHERE` o vendor que a consulta declarou.
 *
 * O harness devolve linha apenas para consulta escopada: se a cláusula sumir,
 * a leitura não encontra nada e fica registrada como consulta sem escopo.
 */
function rest_test_scoped_vendor( string $sql ): int {
	return preg_match( '/vendor_id\s*=\s*(\d+)/', $sql, $matches ) ? (int) $matches[1] : 0;
}

/** `$wpdb` que honra o escopo por vendor, para a leitura de um não alcançar o outro. */
class Papelito_Rest_Test_Wpdb {
	public string $prefix = 'wp_';
	public array $rows = array();
	public array $audit = array();
	public array $unscoped_reads = array();
	private int $next_id = 100;

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
		$vendor_id = rest_test_scoped_vendor( (string) $query );
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

$GLOBALS['wpdb'] = new Papelito_Rest_Test_Wpdb();

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
	$GLOBALS['rest_test_routes'][ (string) $route ] = (array) $args;

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
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-20 12:00:00'; }
/** Relógio fixo exigido pelo módulo de recebedores. */
function papelito_current_utc_mysql(): string { return '2026-09-20 12:00:00'; }
/** Cadastro do vendor e estado de conta, lidos pela configuração e pelo porteiro. */
function get_user_meta( mixed $user_id, string $key, bool $single = false ): string {
	return $GLOBALS['rest_test_meta'][ (int) $user_id ][ $key ] ?? '';
}
/** Gravação de cadastro usada apenas para montar fixturas. */
function update_user_meta( mixed $user_id, string $key, mixed $value ): bool {
	$GLOBALS['rest_test_meta'][ (int) $user_id ][ $key ] = $value;

	return true;
}
/** Remoção de cadastro usada apenas para montar fixturas. */
function delete_user_meta( mixed $user_id, string $key ): bool {
	unset( $GLOBALS['rest_test_meta'][ (int) $user_id ][ $key ] );

	return true;
}
/** Monta o usuário da fixture com o papel e a senha daquele ID. */
function rest_test_user( int $user_id ): WP_User {
	$user        = new WP_User( $user_id, 'hash:' . REST_TEST_ACCOUNT_PASS, 'loja' . $user_id . '@example.test' );
	$user->roles = $GLOBALS['rest_test_roles'][ $user_id ] ?? array();

	return $user;
}
/** Usuário autenticado da requisição sob teste. */
function wp_get_current_user(): WP_User { return rest_test_user( (int) $GLOBALS['rest_test_current'] ); }
/** Identidade da sessão, única fonte de vendor dos três handlers. */
function get_current_user_id(): int { return (int) $GLOBALS['rest_test_current']; }
/** Usuário buscado pela reautenticação. */
function get_user_by( mixed $field, mixed $value ): WP_User { return rest_test_user( (int) $value ); }
/** Dados do usuário para o aviso de alteração sensível. */
function get_userdata( mixed $user_id ): WP_User { return rest_test_user( (int) $user_id ); }
/** Conferência de senha do core, contra o hash da fixture. */
function wp_check_password( mixed $password, mixed $hash, mixed $user_id = '' ): bool {
	return 'hash:' . (string) $password === (string) $hash;
}
/** Valida o e-mail do destinatário do aviso. */
function is_email( mixed $value ): bool { return str_contains( (string) $value, '@' ); }
/** Guarda o aviso sem enviar e-mail de verdade. */
function wp_mail( mixed $to, mixed $subject = '', mixed $message = '', mixed $headers = '', mixed $attachments = array() ): bool {
	$GLOBALS['rest_test_mails'][] = array(
		'to'      => (string) $to,
		'message' => (string) $message,
	);

	return true;
}
/** Transients em memória, usados pela deduplicação de recusa repetida. */
function get_transient( mixed $key ): mixed { return $GLOBALS['rest_test_transients'][ (string) $key ] ?? false; }
/** Grava o transient em memória. */
function set_transient( mixed $key, mixed $value, mixed $ttl = 0 ): bool {
	$GLOBALS['rest_test_transients'][ (string) $key ] = $value;

	return true;
}
/** Apaga o transient em memória; é por aqui que o tíquete de reautenticação é queimado. */
function delete_transient( mixed $key ): bool {
	unset( $GLOBALS['rest_test_transients'][ (string) $key ] );

	return true;
}
/** Limite de escrita controlado pela fixture; a política tem suíte própria. */
function papelito_auth_rate_limit( mixed ...$args ): bool { return (bool) $GLOBALS['rest_test_rate_ok']; }
/** Cofre de integração com envelope distinguível por loja. */
function papelito_vendor_secret_encrypt( string $plain ): string { return (string) $GLOBALS['rest_test_envelope']; }
/** Abertura do cofre, não exercitada pela superfície REST. */
function papelito_vendor_secret_decrypt( string $envelope ): string {
	return wp_json_encode(
		array(
			'username' => REST_TEST_USERNAME_A,
			'password' => REST_TEST_SECRET_A,
		)
	);
}
/** Os dois CNPJs das fixturas são válidos. */
function papelito_validate_cnpj( string $cnpj ): bool {
	return in_array( $cnpj, array( REST_TEST_VENDOR_A_CNPJ, REST_TEST_VENDOR_B_CNPJ ), true );
}

require_once dirname( __DIR__ ) . '/includes/support.php';
require_once dirname( __DIR__ ) . '/includes/account_status.php';
require_once dirname( __DIR__ ) . '/includes/pagarme_recipients.php';
require_once dirname( __DIR__ ) . '/includes/vendor_dashboard.php';
require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function rest_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";

		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Recria as duas lojas, o comprador e a loja suspensa, sem nenhuma integração gravada. */
function rest_reset(): void {
	$GLOBALS['wpdb']              = new Papelito_Rest_Test_Wpdb();
	$GLOBALS['rest_test_rate_ok'] = true;
	$GLOBALS['rest_test_mails']   = array();
	$GLOBALS['rest_test_transients'] = array();
	$GLOBALS['rest_test_meta']    = array(
		REST_TEST_VENDOR_A_ID  => array(
			'cnpj' => REST_TEST_VENDOR_A_CNPJ,
			'cep'  => REST_TEST_VENDOR_A_CEP,
		),
		REST_TEST_VENDOR_B_ID  => array(
			'cnpj' => REST_TEST_VENDOR_B_CNPJ,
			'cep'  => REST_TEST_VENDOR_B_CEP,
		),
		REST_TEST_CUSTOMER_ID  => array(),
		REST_TEST_SUSPENDED_ID => array(
			'cnpj'                       => REST_TEST_VENDOR_A_CNPJ,
			'cep'                        => REST_TEST_VENDOR_A_CEP,
			PAPELITO_ACCOUNT_STATUS_META => REST_TEST_SUSPENDED_META,
		),
	);
	$GLOBALS['rest_test_roles'] = array(
		REST_TEST_VENDOR_A_ID  => array( 'seller' ),
		REST_TEST_VENDOR_B_ID  => array( 'seller' ),
		REST_TEST_CUSTOMER_ID  => array( 'customer' ),
		REST_TEST_SUSPENDED_ID => array( 'seller' ),
	);
}

/** Entra na sessão do usuário informado. */
function rest_login( int $user_id ): void {
	$GLOBALS['rest_test_current'] = $user_id;
}

/** Corpo que cadastra credencial e origem para a loja da sessão. */
function rest_payload( string $username, string $secret, string $origin_cep, bool $enabled = true ): array {
	return array(
		'currentPassword' => REST_TEST_ACCOUNT_PASS,
		'origin_cep'      => $origin_cep,
		'enabled'         => $enabled,
		'username'        => $username,
		'password'        => $secret,
	);
}

/** Executa o `PUT` da rota pela sessão corrente. */
function rest_put( array $payload ): mixed {
	return papelito_vendor_integration_handle_save_braspress( new WP_REST_Request( $payload ) );
}

/** Executa o `DELETE` da rota pela sessão corrente. */
function rest_delete( array $payload ): mixed {
	return papelito_vendor_integration_handle_delete_braspress( new WP_REST_Request( $payload ) );
}

/** Linha persistida da loja informada, ou array vazio. */
function rest_row( int $vendor_id ): array {
	foreach ( $GLOBALS['wpdb']->rows as $row ) {
		if ( (int) ( $row['vendor_id'] ?? 0 ) === $vendor_id ) {
			return $row;
		}
	}

	return array();
}

/** Cadastra a integração da loja informada, entrando e saindo da sessão dela. */
function rest_seed_integration( int $vendor_id, string $username, string $secret, string $envelope, string $origin_cep ): void {
	$previous                      = (int) $GLOBALS['rest_test_current'];
	$GLOBALS['rest_test_envelope'] = $envelope;
	rest_login( $vendor_id );
	rest_put( rest_payload( $username, $secret, $origin_cep ) );
	rest_login( $previous );
}

/** Código do erro devolvido, ou string vazia quando a chamada deu certo. */
function rest_error_code( mixed $result ): string {
	return $result instanceof WP_Error ? $result->get_error_code() : '';
}

/** Status HTTP do erro devolvido, ou zero. */
function rest_error_status( mixed $result ): int {
	return $result instanceof WP_Error ? (int) ( $result->get_error_data()['status'] ?? 0 ) : 0;
}

/** Código-fonte de uma função do plugin, para as asserções estruturais. */
function rest_function_source( string $name ): string {
	$reflection = new ReflectionFunction( $name );
	$lines      = file( (string) $reflection->getFileName() );

	return implode( '', array_slice( (array) $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
}

echo "Cenário 1: as três rotas registram o porteiro e o handler esperados\n";
rest_reset();
papelito_vendor_integration_register_routes();
$route = $GLOBALS['rest_test_routes']['/vendor/me/integrations/braspress'] ?? array();
$by_method = array();
foreach ( $route as $entry ) {
	$by_method[ (string) ( $entry['methods'] ?? '' ) ] = $entry;
}

rest_assert( 'A rota da integração é registrada', 3 === count( $route ) );
rest_assert(
	'A leitura passa pelo porteiro de seller',
	'papelito_vendor_integration_permission_read' === ( $by_method[ WP_REST_Server::READABLE ]['permission_callback'] ?? null )
);
rest_assert(
	'A escrita passa pelo porteiro comercial',
	'papelito_vendor_integration_permission_write' === ( $by_method[ WP_REST_Server::EDITABLE ]['permission_callback'] ?? null )
);
rest_assert(
	'A remoção passa pelo porteiro comercial',
	'papelito_vendor_integration_permission_write' === ( $by_method[ WP_REST_Server::DELETABLE ]['permission_callback'] ?? null )
);
rest_assert(
	'Os três métodos apontam para os handlers da integração',
	'papelito_vendor_integration_handle_get_braspress' === ( $by_method[ WP_REST_Server::READABLE ]['callback'] ?? null )
	&& 'papelito_vendor_integration_handle_save_braspress' === ( $by_method[ WP_REST_Server::EDITABLE ]['callback'] ?? null )
	&& 'papelito_vendor_integration_handle_delete_braspress' === ( $by_method[ WP_REST_Server::DELETABLE ]['callback'] ?? null )
);

echo "\nCenário 2: o porteiro decide antes de o handler existir\n";
rest_reset();

rest_login( 0 );
rest_assert( 'Anônimo não lê a integração', 401 === rest_error_status( papelito_vendor_integration_permission_read() ) );
rest_assert( 'Anônimo não escreve a integração', 401 === rest_error_status( papelito_vendor_integration_permission_write() ) );

rest_login( REST_TEST_CUSTOMER_ID );
rest_assert( 'Comprador não lê a integração de ninguém', 403 === rest_error_status( papelito_vendor_integration_permission_read() ) );
rest_assert( 'Comprador não escreve a integração de ninguém', 403 === rest_error_status( papelito_vendor_integration_permission_write() ) );

rest_login( REST_TEST_SUSPENDED_ID );
rest_assert( 'Loja suspensa continua consultando a própria integração', true === papelito_vendor_integration_permission_read() );
rest_assert(
	'Loja suspensa não altera a própria integração',
	'papelito_account_suspended' === rest_error_code( papelito_vendor_integration_permission_write() )
);

rest_login( REST_TEST_VENDOR_A_ID );
rest_assert( 'Loja ativa lê a própria integração', true === papelito_vendor_integration_permission_read() );
rest_assert( 'Loja ativa altera a própria integração', true === papelito_vendor_integration_permission_write() );

$barrados = array_column( $GLOBALS['wpdb']->audit, 'actor_user_id' );

rest_assert(
	'Cada ator identificado que é barrado deixa uma linha',
	array( REST_TEST_CUSTOMER_ID, REST_TEST_SUSPENDED_ID ) === $barrados
);
rest_assert(
	'E a linha entra como recusa, não como erro de formulário',
	array( 'denied', 'denied' ) === array_column( $GLOBALS['wpdb']->audit, 'status' )
);
rest_assert(
	'A tentativa sem sessão não vira linha, porque não há a quem atribuí-la',
	! in_array( 0, $barrados, true )
);

echo "\nCenário 3: a leitura enxerga só a loja da sessão\n";
rest_reset();
rest_seed_integration( REST_TEST_VENDOR_A_ID, REST_TEST_USERNAME_A, REST_TEST_SECRET_A, REST_TEST_ENVELOPE_A, REST_TEST_VENDOR_A_CEP );
rest_seed_integration( REST_TEST_VENDOR_B_ID, REST_TEST_USERNAME_B, REST_TEST_SECRET_B, REST_TEST_ENVELOPE_B, REST_TEST_VENDOR_B_CEP );

rest_login( REST_TEST_VENDOR_A_ID );
$read_a = papelito_vendor_integration_handle_get_braspress()->get_data();
rest_login( REST_TEST_VENDOR_B_ID );
$read_b = papelito_vendor_integration_handle_get_braspress()->get_data();

rest_assert( 'A loja A lê a origem da loja A', REST_TEST_VENDOR_A_CEP === ( $read_a['config']['origin_cep'] ?? null ) );
rest_assert( 'A loja B lê a origem da loja B', REST_TEST_VENDOR_B_CEP === ( $read_b['config']['origin_cep'] ?? null ) );
rest_assert( 'A loja A não vê o CNPJ da loja B', REST_TEST_VENDOR_A_CNPJ === ( $read_a['config']['sender_cnpj'] ?? null ) );
rest_assert( 'A loja B não vê o CNPJ da loja A', REST_TEST_VENDOR_B_CNPJ === ( $read_b['config']['sender_cnpj'] ?? null ) );
rest_assert( 'Nenhuma leitura saiu sem escopo de vendor', array() === $GLOBALS['wpdb']->unscoped_reads );

echo "\nCenário 4: a leitura não carrega credencial nem envelope\n";
$serialized = wp_json_encode( $read_a );

foreach (
	array(
		'o usuário Braspress' => REST_TEST_USERNAME_A,
		'a senha Braspress'   => REST_TEST_SECRET_A,
		'a senha da conta'    => REST_TEST_ACCOUNT_PASS,
		'o envelope cifrado'  => REST_TEST_ENVELOPE_A,
	) as $label => $needle
) {
	rest_assert( "A leitura não devolve {$label}", ! str_contains( $serialized, $needle ) );
}

rest_assert(
	'A leitura não expõe as colunas cruas da tabela',
	! array_key_exists( 'secret_envelope', $read_a ) && ! array_key_exists( 'config_json', $read_a )
);
rest_assert( 'A leitura diz apenas que existe credencial configurada', true === ( $read_a['credentials_configured'] ?? null ) );

echo "\nCenário 5: a escrita fica na loja da sessão, mesmo com o corpo apontando outra\n";
rest_reset();
rest_seed_integration( REST_TEST_VENDOR_A_ID, REST_TEST_USERNAME_A, REST_TEST_SECRET_A, REST_TEST_ENVELOPE_A, REST_TEST_VENDOR_A_CEP );
rest_seed_integration( REST_TEST_VENDOR_B_ID, REST_TEST_USERNAME_B, REST_TEST_SECRET_B, REST_TEST_ENVELOPE_B, REST_TEST_VENDOR_B_CEP );
$before_b = rest_row( REST_TEST_VENDOR_B_ID );

rest_login( REST_TEST_VENDOR_A_ID );
$GLOBALS['rest_test_envelope'] = REST_TEST_ENVELOPE_A;
$payload                       = rest_payload( REST_TEST_USERNAME_A, REST_TEST_SECRET_A, '04538133' );
$payload['vendor_id']          = REST_TEST_VENDOR_B_ID;
$payload['vendorId']           = REST_TEST_VENDOR_B_ID;
$written                       = rest_put( $payload );

rest_assert( 'A escrita é aceita', $written instanceof WP_REST_Response );
rest_assert( 'A origem nova foi gravada na loja da sessão', '04538133' === ( json_decode( (string) ( rest_row( REST_TEST_VENDOR_A_ID )['config_json'] ?? '{}' ), true )['origin_cep'] ?? null ) );
rest_assert( 'A loja apontada no corpo não foi tocada', rest_row( REST_TEST_VENDOR_B_ID ) === $before_b );
rest_assert( 'A resposta devolve a loja da sessão', REST_TEST_VENDOR_A_CNPJ === ( $written->get_data()['config']['sender_cnpj'] ?? null ) );

echo "\nCenário 6: corpo recusado não altera a configuração anterior\n";
rest_reset();
rest_seed_integration( REST_TEST_VENDOR_A_ID, REST_TEST_USERNAME_A, REST_TEST_SECRET_A, REST_TEST_ENVELOPE_A, REST_TEST_VENDOR_A_CEP );
$before_a = rest_row( REST_TEST_VENDOR_A_ID );
rest_login( REST_TEST_VENDOR_A_ID );

$invalid_origin = rest_put( rest_payload( REST_TEST_USERNAME_A, REST_TEST_SECRET_A, '123' ) );
rest_assert( 'Origem com menos de oito dígitos é recusada', 'papelito_vendor_integration_invalid_origin_cep' === rest_error_code( $invalid_origin ) );
rest_assert( 'Origem inválida responde 422', 422 === rest_error_status( $invalid_origin ) );
rest_assert( 'Origem inválida não altera a configuração anterior', rest_row( REST_TEST_VENDOR_A_ID ) === $before_a );

$half_credential = rest_payload( REST_TEST_USERNAME_A, '', REST_TEST_VENDOR_A_CEP );
$half_result     = rest_put( $half_credential );
rest_assert( 'Usuário sem senha é recusado', 'papelito_vendor_integration_credentials_incomplete' === rest_error_code( $half_result ) );
rest_assert( 'Credencial pela metade não altera a configuração anterior', rest_row( REST_TEST_VENDOR_A_ID ) === $before_a );

$wrong_password                    = rest_payload( REST_TEST_USERNAME_A, REST_TEST_SECRET_A, REST_TEST_VENDOR_A_CEP );
$wrong_password['currentPassword'] = 'senha-errada';
$reauth_result                     = rest_put( $wrong_password );
rest_assert( 'Reautenticação errada é recusada', 'papelito_vendor_integration_current_password_invalid' === rest_error_code( $reauth_result ) );
rest_assert( 'Reautenticação errada não altera a configuração anterior', rest_row( REST_TEST_VENDOR_A_ID ) === $before_a );

echo "\nCenário 7: habilitar sem CNPJ no cadastro explica o que falta\n";
rest_reset();
delete_user_meta( REST_TEST_VENDOR_A_ID, 'cnpj' );
rest_login( REST_TEST_VENDOR_A_ID );

$incomplete = rest_put( rest_payload( REST_TEST_USERNAME_A, REST_TEST_SECRET_A, REST_TEST_VENDOR_A_CEP ) );
rest_assert( 'Habilitar sem CNPJ é recusado', 'papelito_vendor_integration_profile_incomplete' === rest_error_code( $incomplete ) );
rest_assert( 'A recusa por cadastro incompleto responde 422', 422 === rest_error_status( $incomplete ) );
rest_assert(
	'A mensagem diz o que o vendor precisa fazer',
	$incomplete instanceof WP_Error
	&& str_contains( $incomplete->get_error_message(), 'CNPJ' )
	&& str_contains( $incomplete->get_error_message(), 'cadastro' )
);
rest_assert( 'A recusa não deixa integração gravada', array() === rest_row( REST_TEST_VENDOR_A_ID ) );

echo "\nCenário 8: a remoção alcança só a loja da sessão\n";
rest_reset();
rest_seed_integration( REST_TEST_VENDOR_A_ID, REST_TEST_USERNAME_A, REST_TEST_SECRET_A, REST_TEST_ENVELOPE_A, REST_TEST_VENDOR_A_CEP );
rest_seed_integration( REST_TEST_VENDOR_B_ID, REST_TEST_USERNAME_B, REST_TEST_SECRET_B, REST_TEST_ENVELOPE_B, REST_TEST_VENDOR_B_CEP );
$before_b = rest_row( REST_TEST_VENDOR_B_ID );

rest_login( REST_TEST_VENDOR_A_ID );
$removed = rest_delete(
	array(
		'currentPassword' => REST_TEST_ACCOUNT_PASS,
		'vendor_id'       => REST_TEST_VENDOR_B_ID,
	)
);

rest_assert( 'A remoção é aceita', $removed instanceof WP_REST_Response );
rest_assert( 'A integração da loja da sessão sai', array() === rest_row( REST_TEST_VENDOR_A_ID ) );
rest_assert( 'A integração da loja apontada no corpo fica', rest_row( REST_TEST_VENDOR_B_ID ) === $before_b );
rest_assert( 'A resposta da remoção não traz credencial', false === ( $removed->get_data()['credentials_configured'] ?? null ) );

echo "\nCenário 9: o handler não tem canal para um vendor vindo do cliente\n";

foreach (
	array(
		'papelito_vendor_integration_handle_get_braspress',
		'papelito_vendor_integration_handle_save_braspress',
		'papelito_vendor_integration_handle_delete_braspress',
	) as $handler
) {
	$source = rest_function_source( $handler );
	rest_assert(
		"O handler {$handler} identifica a loja só pela sessão",
		str_contains( $source, 'get_current_user_id()' )
		&& ! preg_match( '/get_param|\$_GET|\$_POST|\$_REQUEST/', $source )
		&& ! preg_match( '/\[\s*[\'"]vendor/i', $source )
	);
}

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
