<?php
/**
 * Standalone regression tests for automatic/manual owner-review routing.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( private string $code, private string $message, private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): array { return $this->data; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

$lookup_fixture   = array();
$evidence_fixture = array();

function papelito_cnpj_lookup( string $cnpj, bool $include_evidence = false ): array {
	global $lookup_fixture;
	unset( $cnpj, $include_evidence );
	return $lookup_fixture;
}

function papelito_company_owner_evidence(): array {
	global $evidence_fixture;
	return $evidence_fixture;
}

function papelito_company_owner_review_path( array $evidence ): string|WP_Error {
	if ( true !== ( $evidence['qsa_sufficient'] ?? false ) ) {
		return 'document_required';
	}
	return true === ( $evidence['partner_match'] ?? false )
		? 'qsa_review'
		: new WP_Error( 'papelito_b2b_qsa_mismatch', 'mismatch', array( 'status' => 422 ) );
}

$source = file_get_contents( __DIR__ . '/../includes/company_services.php' );
if ( ! preg_match( '/function papelito_company_validate_owner_registry\(.*?\n}/s', $source, $match ) ) {
	echo "FAIL: could not isolate papelito_company_validate_owner_registry\n";
	exit( 1 );
}
eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

$failures = 0;
function assert_review( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

$lookup_fixture = array( 'status' => 'active', 'legal_name' => 'EMPRESA TESTE', 'is_mei' => false );
$evidence_fixture = array( 'qsa_available' => false, 'qsa_sufficient' => false );
$manual = papelito_company_validate_owner_registry( '52998224725', '1990-01-01', '11222333000181', 'Pessoa Teste' );
assert_review( 'QSA ausente abre etapa documental', true, is_array( $manual ) && true === $manual['review_required'] );

$evidence_fixture = array( 'qsa_available' => true, 'qsa_sufficient' => false );
$manual = papelito_company_validate_owner_registry( '52998224725', '1990-01-01', '11222333000181', 'Pessoa Teste' );
assert_review( 'QSA incompleto abre etapa documental', true, is_array( $manual ) && true === $manual['review_required'] );

$evidence_fixture = array( 'qsa_available' => true, 'qsa_sufficient' => true );
$mismatch = papelito_company_validate_owner_registry( '52998224725', '1990-01-01', '11222333000181', 'Pessoa Teste' );
assert_review( 'QSA suficiente divergente bloqueia correcao antes da candidatura', 'papelito_b2b_qsa_mismatch', $mismatch instanceof WP_Error ? $mismatch->get_error_code() : null );

$evidence_fixture = array( 'qsa_available' => true, 'qsa_sufficient' => true, 'partner_match' => true );
$approved = papelito_company_validate_owner_registry( '52998224725', '1990-01-01', '11222333000181', 'Pessoa Teste' );
assert_review( 'QSA compativel segue para revisao sem documento', 'qsa_review', is_array( $approved ) ? $approved['review_path'] : null );

$lookup_fixture = array( 'status' => 'unavailable' );
$unavailable = papelito_company_validate_owner_registry( '52998224725', '1990-01-01', '11222333000181', 'Pessoa Teste' );
assert_review( 'indisponibilidade permanece erro tecnico', 'papelito_b2b_qsa_unavailable', $unavailable instanceof WP_Error ? $unavailable->get_error_code() : null );
assert_review( 'erro tecnico usa 503', 503, $unavailable instanceof WP_Error ? $unavailable->get_error_data()['status'] : null );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
