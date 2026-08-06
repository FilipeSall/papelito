<?php
/**
 * Cobertura do contrato de erros por campo da candidatura pré-conta.
 *
 * Executar: php public_html/wp-content/plugins/plugin_papelito/tests/test-pre-account-validation-errors.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error { // NOSONAR -- o nome é o da classe do WordPress;
	private string $message;

	public function __construct( private string $code = '', string $message = '', private mixed $data = null ) {
		$this->message = $message;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
	public function get_error_message(): string { return $this->message; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void { /* Hooks não são executados por este teste standalone. */ }
function sanitize_email( string $value ): string { return trim( $value ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function papelito_normalize_cpf( string $value ): string { return $value; }
function papelito_normalize_cnpj( string $value ): string { return $value; }
function papelito_validate_cpf( string $value ): bool { return 'cpf-valido' === $value; }
function papelito_validate_cnpj( string $value ): bool { return 'cnpj-valido' === $value; }

require_once __DIR__ . '/../includes/company_services.php';
require_once __DIR__ . '/../includes/company_pre_account_applications.php';

$failures = 0;
function validation_errors_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

$invalid = papelito_pre_account_application_identity(
	array(
		'email'      => 'email-invalido',
		'full_name'  => '',
		'phone'      => '',
		'cpf'        => 'cpf-invalido',
		'birth_date' => 'data-invalida',
		'cnpj'       => 'cnpj-invalido',
		'password'   => 'curta',
	)
);

validation_errors_assert( 'dados inválidos retornam WP_Error', true, is_wp_error( $invalid ) );
validation_errors_assert( 'preserva o código do contrato', 'papelito_pre_account_invalid_input', $invalid instanceof WP_Error ? $invalid->get_error_code() : null );
validation_errors_assert( 'preserva o status 422', 422, $invalid instanceof WP_Error ? ( $invalid->get_error_data()['status'] ?? null ) : null );
validation_errors_assert(
	'acumula erros por campo',
	array( 'email', 'full_name', 'phone', 'cpf', 'birth_date', 'cnpj', 'password' ),
	$invalid instanceof WP_Error ? array_keys( $invalid->get_error_data()['errors'] ?? array() ) : null
);

$valid = papelito_pre_account_application_identity(
	array(
		'email'      => 'candidato@example.test',
		'full_name'  => 'Candidato de Teste',
		'phone'      => '11999999999',
		'cpf'        => 'cpf-valido',
		'birth_date' => '1990-01-01',
		'cnpj'       => 'cnpj-valido',
		'password'   => 'senha-secreta',
	)
);

validation_errors_assert( 'dados válidos preservam o caminho de sucesso', false, is_wp_error( $valid ) );

$today            = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
$adult_birth_date = $today->sub( new DateInterval( 'P18Y' ) )->format( 'Y-m-d' );
$minor_birth_date = $today->sub( new DateInterval( 'P18Y' ) )->modify( '+1 day' )->format( 'Y-m-d' );
$future_birth_date = $today->modify( '+1 day' )->format( 'Y-m-d' );

foreach (
	array(
		'adulto no aniversário' => array( $adult_birth_date, false ),
		'menor de idade'        => array( $minor_birth_date, true ),
		'data futura'           => array( $future_birth_date, true ),
	) as $label => $case
) {
	$result = papelito_pre_account_application_identity(
		array(
			'email'      => 'candidato@example.test',
			'full_name'  => 'Candidato de Teste',
			'phone'      => '11999999999',
			'cpf'        => 'cpf-valido',
			'birth_date' => $case[0],
			'cnpj'       => 'cnpj-valido',
			'password'   => 'senha-secreta',
		)
	);

	validation_errors_assert( $label, $case[1], is_wp_error( $result ) );
}

exit( $failures > 0 ? 1 : 0 );
