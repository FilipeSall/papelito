<?php
/**
 * Standalone regression test for duplicate company CNPJ handling.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public function __construct( private string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
}

class WP_User {
	public string $first_name = 'Test';
	public string $last_name = 'Owner';
	public function __construct( public int $ID, public string $user_email ) {}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function get_userdata( int $user_id ): ?WP_User { return 7 === $user_id ? new WP_User( 7, 'owner@example.test' ) : null; }
function get_user_meta(): string { return ''; }
function remove_accents( string $value ): string { return $value; }
function wp_json_encode( mixed $value ): string { return json_encode( $value ) ?: ''; }
function current_time( string $type, bool $gmt = false ): string { return '2026-07-25 00:00:00'; }
function wp_update_user( array $data ): int { return (int) $data['ID']; }
function papelito_customer_profile_upsert( int $user_id, string $cpf, array $fields = array() ): true { return true; }
function papelito_pii_encrypt( string $value ): string { return 'test-ciphertext'; }
function papelito_customer_profiles_table_name(): string { return 'wp_papelito_customer_profiles'; }
function papelito_cnpj_adapter_brasilapi( string $cnpj ): array {
	return array(
		'status'     => 'active',
		'source'     => 'test',
		'checked_at' => '2026-07-25T00:00:00Z',
		'qsa'        => array(
			array(
				'nome_socio'            => 'Test Owner',
				'cnpj_cpf_do_socio'     => '***112108**',
				'codigo_faixa_etaria'   => '4',
			),
		),
	);
}
function papelito_normalize_cnpj( string $cnpj ): string { return preg_replace( '/\D+/', '', $cnpj ) ?? ''; }

class Papelito_Owner_Candidate_Test_WPDB {
	public function query( string $query ): int { return 1; }
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_row( string $query, mixed $mode = null ): array {
		if ( str_contains( $query, 'owner_applications' ) ) {
			return array( 'application_status' => 'rejected', 'is_open' => null );
		}
		return array( 'id' => 42, 'created_by_user_id' => 99 );
	}
	public function update( string $table, array $data, array $where ): int { return 1; }
}
$wpdb = new Papelito_Owner_Candidate_Test_WPDB();
function papelito_company_table_names(): array { return array( 'companies' => 'wp_papelito_companies', 'owner_applications' => 'wp_papelito_company_owner_applications' ); }

require __DIR__ . '/../includes/company_services.php';

$result = papelito_company_create_owner_candidate(
	7,
	array(
		'cpf'        => '10011210826',
		'birth_date' => '1990-01-01',
		'cnpj'       => '11222333000181',
		'full_name'  => 'Test Owner',
	)
);

if ( ! is_wp_error( $result ) || 'papelito_company_cnpj_exists' !== $result->get_error_code() ) {
	echo 'FAIL: duplicate CNPJ must be rejected (got ' . ( is_wp_error( $result ) ? $result->get_error_code() : get_debug_type( $result ) ) . ")\n";
	exit( 1 );
}

echo "PASS: duplicate CNPJ must be rejected\n";
