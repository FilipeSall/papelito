<?php
/**
 * Cobertura do endereço fiscal na aprovação de candidaturas pré-conta.
 *
 * A candidatura coleta o endereço, mas quem decide a compra é
 * papelito_company_purchase_capability(), que lê as colunas fiscal_* da EMPRESA. Empresa aprovada
 * sem essas colunas nasce permanentemente impedida de comprar, sem tela que a conserte.
 *
 * Executar: php public_html/wp-content/plugins/plugin_papelito/tests/test-pre-account-approval-fiscal-address.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING', 'company_owner_review_pending' );

const FISCAL_TEST_EMAIL = 'candidato@example.test';
const FISCAL_TEST_CNPJ  = '11222333000181';
const FISCAL_TEST_CEP   = '70879060';

class WP_Error { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o instanceof do código sob teste.
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) { /* stub: só carrega os dados do erro. */ }
}

class WP_User { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o instanceof do código sob teste.
	public int $ID = 0; // NOSONAR -- propriedade pública do WP_User do WordPress.
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_textarea_field( string $value ): string { return trim( $value ); }
function sanitize_key( string $value ): string { return strtolower( $value ); }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function current_time( string $type, bool $gmt = false ): string { return '2026-08-05 10:00:00'; }
function papelito_normalize_cnpj( string $value ): string { return preg_replace( '/\D+/', '', $value ) ?? ''; }
function papelito_validate_cnpj( string $value ): bool { return 14 === strlen( papelito_normalize_cnpj( $value ) ); }
function papelito_normalize_email( string $value ): string { return strtolower( trim( $value ) ); }
function papelito_pii_decrypt( string $value ): string { return $value; }
function papelito_company_table_names(): array {
	return array(
		'companies'                => 'wp_papelito_companies',
		'members'                  => 'wp_papelito_company_members',
		'pre_account_applications' => 'wp_papelito_company_pre_account_applications',
	);
}
function papelito_company_validate_owner_registry( string $cpf, string $birth, string $cnpj, string $name ): array {
	return array(
		'review_path' => 'auto_approved',
		'lookup'      => array( 'legal_name' => 'EMPRESA CANDIDATA LTDA', 'trade_name' => 'Candidata' ),
	);
}
function email_exists( string $email ): bool { return false; }
function username_exists( string $login ): bool { return false; }
function wp_insert_user( array $data ): int { return 4242; }
function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string { return str_repeat( 'x', $length ); }
function papelito_auth_mark_email_pending( int $user_id ): void { /* stub: verificação de e-mail não faz parte deste caso. */ }
function clean_user_cache( int $user_id ): void { /* stub: sem cache de usuário fora do WordPress. */ }
function update_user_meta( int $user_id, string $key, mixed $value ): bool { return true; }
function get_userdata( int $user_id ): ?WP_User { return null; }
function wp_delete_user( int $user_id ): bool { return true; }
function papelito_company_profile_upsert( int $user_id, string $cpf, string $birth ): bool { return true; }
function papelito_company_onboarding_upsert( int $user_id, string $type, string $cnpj, string $status ): bool { return true; }
function papelito_company_onboarding_mark_completed( int $user_id, int $company_id, int $membership_id ): bool { return true; }
function papelito_company_onboarding_save_address( int $user_id, string $cep, array $data ): bool {
	global $saved_user_address;
	$saved_user_address = array( 'cep' => $cep, 'data' => $data );
	return true;
}
function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void { /* stub: hooks não são exercitados aqui. */ }
function wp_next_scheduled( string $hook, array $args = array() ): bool { return false; }
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): void { /* stub: cron não é exercitado aqui. */ }
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): void { /* stub: cron não é exercitado aqui. */ }

/**
 * $wpdb mínimo: registra o que foi inserido em cada tabela e trata qualquer UPDATE como aplicado.
 * get_row devolve null de propósito — nenhuma empresa/membership/candidatura preexiste.
 */
class PapelitoFiscalTestWpdb {
	public string $users     = 'wp_users';
	public string $last_error = ''; // NOSONAR -- propriedade pública do $wpdb do WordPress.
	public int $insert_id     = 0; // NOSONAR -- propriedade pública do $wpdb do WordPress.
	public array $inserts     = array();

	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function query( string $query ): int { return 1; }
	public function get_row( string $query, mixed $output = null ): ?array { return null; }
	public function get_var( string $query ): int { return 0; }
	public function insert( string $table, array $data ): int {
		$this->inserts[ $table ][] = $data;
		++$this->insert_id;
		return 1;
	}
	public function update( string $table, array $data, array $where ): int { return 1; }
}

$wpdb               = new PapelitoFiscalTestWpdb();
$saved_user_address = array();

require_once __DIR__ . '/../includes/company_repository.php';
require_once __DIR__ . '/../includes/company_pre_account_applications.php';

$failures = 0;
function fiscal_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

$address = array(
	'cep'          => FISCAL_TEST_CEP,
	'street'       => 'Quadra SQN 416 Bloco F',
	'number'       => '108',
	'complement'   => 'apt 101',
	'neighborhood' => 'Asa Norte',
	'city'         => 'Brasília',
	'state'        => 'DF',
);

$application = array(
	'id'                       => 71,
	'canonical_cnpj'           => FISCAL_TEST_CNPJ,
	'application_status'       => 'pending_manual_review',
	'is_open'                  => 1,
	'document_storage_key'     => 'doc.pdf',
	'password_hash'            => '$P$Bhash',
	'contact_email_ciphertext' => FISCAL_TEST_EMAIL,
	'full_name_ciphertext'     => 'Pessoa Candidata',
	'phone_ciphertext'         => '61998272992',
	'cpf_ciphertext'           => '12345678909',
	'birth_date_ciphertext'    => '1990-01-01',
	'address_ciphertext'       => json_encode( $address ),
);

$approved = papelito_pre_account_application_approve( $application, 9 );
fiscal_assert( 'aprovacao conclui sem erro', false, is_wp_error( $approved ) );

$company = $wpdb->inserts['wp_papelito_companies'][0] ?? array();

fiscal_assert( 'cep fiscal vem da candidatura', FISCAL_TEST_CEP, $company['fiscal_cep'] ?? null );
fiscal_assert( 'estado fiscal vem da candidatura', 'DF', $company['fiscal_state'] ?? null );
fiscal_assert( 'cidade fiscal vem da candidatura', 'Brasília', $company['fiscal_city'] ?? null );
fiscal_assert( 'bairro fiscal vem da candidatura', 'Asa Norte', $company['fiscal_neighborhood'] ?? null );
fiscal_assert( 'logradouro fiscal vem da candidatura', 'Quadra SQN 416 Bloco F', $company['fiscal_street'] ?? null );
fiscal_assert( 'numero fiscal vem da candidatura', '108', $company['fiscal_number'] ?? null );
fiscal_assert( 'complemento fiscal vem da candidatura', 'apt 101', $company['fiscal_complement'] ?? null );

// Espelha a guarda de papelito_company_purchase_capability(): qualquer coluna vazia bloqueia a compra.
$incomplete = array();
foreach ( array( 'fiscal_cep', 'fiscal_state', 'fiscal_city', 'fiscal_neighborhood', 'fiscal_street', 'fiscal_number' ) as $column ) {
	if ( '' === trim( (string) ( $company[ $column ] ?? '' ) ) ) {
		$incomplete[] = $column;
	}
}
fiscal_assert( 'empresa aprovada nasce apta a comprar', array(), $incomplete );

fiscal_assert( 'empresa aprovada nasce ativa', 'active', $company['company_status'] ?? null );
fiscal_assert( 'titularidade aprovada nasce verificada', 'verified', $company['ownership_status'] ?? null );
fiscal_assert( 'situacao cadastral aprovada nasce ativa', 'active', $company['registry_status'] ?? null );
fiscal_assert( 'e-mail de faturamento normalizado', FISCAL_TEST_EMAIL, $company['billing_email'] ?? null );
fiscal_assert( 'e-mail de faturamento nasce sem verificacao', true, array_key_exists( 'billing_email_verified_at', $company ) && null === $company['billing_email_verified_at'] );
fiscal_assert( 'endereco do usuario continua sendo espelhado', FISCAL_TEST_CEP, $saved_user_address['cep'] ?? null );

// Criação sem endereço não pode inventar valor: as colunas ficam nulas e o gate segue bloqueando.
$wpdb->inserts = array();
papelito_company_create( FISCAL_TEST_CNPJ, array( 'created_by_user_id' => 7, 'billing_email' => '  Fiscal@Empresa.COM ' ) );
$plain = $wpdb->inserts['wp_papelito_companies'][0] ?? array();
fiscal_assert( 'sem endereco informado a coluna fica nula', true, array_key_exists( 'fiscal_cep', $plain ) && null === $plain['fiscal_cep'] );
fiscal_assert( 'sem endereco informado o complemento fica nulo', true, array_key_exists( 'fiscal_complement', $plain ) && null === $plain['fiscal_complement'] );
fiscal_assert( 'status padrao preservado', 'onboarding', $plain['company_status'] ?? null );
fiscal_assert( 'situacao cadastral padrao preservada', 'pending', $plain['registry_status'] ?? null );
fiscal_assert( 'razao social padrao preservada', '', $plain['legal_name'] ?? null );
fiscal_assert( 'nome fantasia padrao preservado', true, array_key_exists( 'trade_name', $plain ) && null === $plain['trade_name'] );
fiscal_assert( 'e-mail de faturamento normalizado na criacao direta', 'fiscal@empresa.com', $plain['billing_email'] ?? null );

exit( $failures > 0 ? 1 : 0 );
