<?php
/**
 * Standalone regression test for duplicate company CNPJ handling.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( private string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
}

class WP_User {
	public function __construct( public int $ID, public string $user_email ) {}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function get_userdata( int $user_id ): ?WP_User { return 7 === $user_id ? new WP_User( 7, 'owner@example.test' ) : null; }
function get_user_meta(): string { return ''; }
function remove_accents( string $value ): string { return $value; }
function wp_json_encode( mixed $value ): string { return json_encode( $value ) ?: ''; }
function current_time( string $type, bool $gmt = false ): string { return '2026-07-25 00:00:00'; }
function papelito_customer_profile_upsert( int $user_id, string $cpf, array $fields = array() ): true { return true; }
function papelito_pii_encrypt( string $value ): string { return 'test-ciphertext'; }
function papelito_customer_profiles_table_name(): string { return 'wp_papelito_customer_profiles'; }
function papelito_cnpj_lookup( string $cnpj, bool $include_evidence = false ): array { return array( 'status' => 'active', 'source' => 'test', 'checked_at' => '2026-07-25T00:00:00Z', 'qsa' => array() ); }
function papelito_normalize_cnpj( string $cnpj ): string { return preg_replace( '/\D+/', '', $cnpj ) ?? ''; }

class Papelito_Owner_Candidate_Test_WPDB {
	public function query( string $query ): int { return 1; }
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_var( string $query ): string { return '42'; }
	public function update( string $table, array $data, array $where ): int { return 1; }
}
$wpdb = new Papelito_Owner_Candidate_Test_WPDB();
function papelito_company_table_names(): array { return array( 'companies' => 'wp_papelito_companies' ); }

require __DIR__ . '/../includes/company_services.php';

$result = papelito_company_create_owner_candidate(
	7,
	array(
		'cpf'        => 'test-cpf',
		'birth_date' => '1990-01-01',
		'cnpj'       => 'test-cnpj',
	)
);

if ( ! is_wp_error( $result ) || 'papelito_company_cnpj_exists' !== $result->get_error_code() ) {
	echo "FAIL: duplicate CNPJ must be rejected\n";
	exit( 1 );
}

echo "PASS: duplicate CNPJ must be rejected\n";
