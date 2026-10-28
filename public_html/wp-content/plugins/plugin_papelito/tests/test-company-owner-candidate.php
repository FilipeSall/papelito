<?php
/**
 * Standalone regression test for duplicate company CNPJ handling.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
const OWNER_CANDIDATE_TEST_NAME  = 'Test Owner';
const OWNER_CANDIDATE_TEST_EMAIL = 'owner@example.test';

class WP_Error {
	public function __construct( private string $code = '', public string $message = '', public mixed $data = null ) {
		$this->code = $code; }
	public function get_error_code(): string {
		return $this->code; }
}

class WP_User {
	public string $first_name = 'Test';
	public string $last_name  = 'Owner';
	public function __construct( public int $ID, public string $user_email ) {
		$this->user_email = $user_email;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function get_userdata( int $user_id ): ?WP_User {
	return 7 === $user_id ? new WP_User( 7, OWNER_CANDIDATE_TEST_EMAIL ) : null; }
function get_user_meta( int $user_id, string $key = '', bool $single = false ): string {
	unset( $user_id, $key, $single );
	return '';
}
function remove_accents( string $value ): string {
	return $value; }
function wp_json_encode( mixed $value ): string {
	return json_encode( $value ) ?: ''; }
function current_time( string $type, bool $gmt = false ): string {
	return '2026-07-25 00:00:00'; }
function wp_update_user( array $data ): int {
	return (int) $data['ID']; }
/** Ambiente descartável: as chaves de teste só cifram fixtures em memória. */
function papelito_env( string $name, mixed $fallback = null ): mixed {
	return str_starts_with( $name, 'PAPELITO_PII_' ) && str_ends_with( $name, '_KEY' ) ? str_repeat( 'fixture-key-', 4 ) : $fallback;
}
/** Flags reais permanecem nos defaults seguros; nenhuma aprovação automática nesta regressão. */
function papelito_env_bool( string $name, bool $fallback = false ): bool {
	unset( $name );
	return $fallback;
}
/** Fronteiras WordPress necessárias ao bootstrap dos serviços reais. */
function add_action( mixed ...$args ): bool {
	unset( $args );
	return true;
}
function add_filter( mixed ...$args ): bool {
	unset( $args );
	return true;
}
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	unset( $hook, $args );
	return $value;
}
function sanitize_email( mixed $value ): string {
	return trim( (string) $value ); }
function sanitize_text_field( mixed $value ): string {
	return trim( (string) $value ); }
function sanitize_key( mixed $value ): string {
	return strtolower( (string) $value ); }
function get_transient( string $key ): mixed {
	return $GLOBALS['owner_candidate_test_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration ): bool {
	unset( $expiration );
	$GLOBALS['owner_candidate_test_transients'][ $key ] = $value;
	return true;
}
/** Resposta HTTP BrasilAPI; fallback indisponível não inventa conflito cadastral. */
function wp_remote_get( string $url, array $args = array() ): array|WP_Error {
	unset( $args );
	if ( ! str_contains( $url, 'brasilapi.com.br' ) ) {
		return new WP_Error( 'http_request_failed', 'Provedor sem fixture HTTP.' );
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			array(
				'descricao_situacao_cadastral' => 'ATIVA',
				'qsa'                          => array(
					array(
						'nome_socio'          => OWNER_CANDIDATE_TEST_NAME,
						'cnpj_cpf_do_socio'   => '***112108**',
						'codigo_faixa_etaria' => '4',
					),
				),
			)
		),
	);
}
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) $response['response']['code']; }
function wp_remote_retrieve_body( array $response ): string {
	return $response['body']; }

class Papelito_Owner_Candidate_Test_WPDB {
	/**
	 * Prefixo usado pelo schema real para resolver nomes de tabela.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';
	public function query( string $query ): int {
		return 1; }
	public function prepare( string $query, mixed ...$args ): string {
		return $query; }
	public function get_row( string $query, mixed $mode = null ): array {
		if ( str_contains( $query, 'owner_applications' ) ) {
			return array(
				'application_status' => 'rejected',
				'is_open'            => null,
			);
		}
		return array(
			'id'                 => 42,
			'created_by_user_id' => 99,
		);
	}
	public function update( string $table, array $data, array $where ): int {
		return 1; }
	public function get_var( string $query ): mixed {
		unset( $query );
		return null;
	}
	public function insert( string $table, array $data ): int {
		unset( $table, $data );
		return 1;
	}
}
$wpdb = new Papelito_Owner_Candidate_Test_WPDB();

require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/auth_endpoints.php';
require_once __DIR__ . '/../includes/company_flags.php';
require_once __DIR__ . '/../includes/cnpj_validation.php';
require_once __DIR__ . '/../includes/cnpj_providers.php';
require_once __DIR__ . '/../includes/customer_identity.php';
require_once __DIR__ . '/../includes/company_schema.php';
require_once __DIR__ . '/../includes/billing_email_sync.php';
require_once __DIR__ . '/../includes/company_services.php';

$result = papelito_company_create_owner_candidate(
	7,
	array(
		'cpf'        => '10011210826',
		'birth_date' => '1990-01-01',
		'cnpj'       => '11222333000181',
		'full_name'  => OWNER_CANDIDATE_TEST_NAME,
	)
);

if ( ! is_wp_error( $result ) || 'papelito_company_cnpj_exists' !== $result->get_error_code() ) {
	echo 'FAIL: duplicate CNPJ must be rejected (got ' . ( is_wp_error( $result ) ? $result->get_error_code() : get_debug_type( $result ) ) . ")\n";
	exit( 1 );
}

echo "PASS: duplicate CNPJ must be rejected\n";
