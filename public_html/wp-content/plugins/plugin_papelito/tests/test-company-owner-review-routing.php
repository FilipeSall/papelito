<?php
/**
 * Registry routing through the real ownership decision functions.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'MB_IN_BYTES', 1048576 );
require_once __DIR__ . '/support/owner_registry/class-wp-error.php';

$lookup_fixture = array();
$flag_enabled   = false;
$failures       = 0;

/** WordPress error boundary. */
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

/** WordPress accent boundary. */
function remove_accents( string $value ): string {
	$ascii = iconv( 'UTF-8', 'ASCII//TRANSLIT', $value );
	return false === $ascii ? $value : $ascii;
}

/** WordPress JSON boundary. */
function wp_json_encode( mixed $value ): string {
	$encoded = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Implementa a fronteira wp_json_encode sem carregar WordPress.
	return false === $encoded ? '' : $encoded;
}

/** Records WordPress hook registration at module load. */
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['registry_test_hooks'][ $hook ] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

/** Configuration boundary. */
function papelito_b2b_flag( string $name ): bool {
	global $flag_enabled;
	return 'PAPELITO_QSA_AUTO_APPROVE_ENABLED' === $name && $flag_enabled;
}

/**
 * Remote registry boundary: all decision functions are real.
 *
 * @throws RuntimeException If ownership evidence was not requested.
 */
function papelito_cnpj_lookup( string $cnpj, bool $include_evidence = false ): array {
	global $lookup_fixture;
	if ( '11222333000181' !== $cnpj || ! $include_evidence ) {
		throw new RuntimeException( 'Registry evidence was not requested.' );
	}
	return $lookup_fixture;
}

require_once __DIR__ . '/../includes/company_services.php';
require_once __DIR__ . '/../includes/company_owner_applications.php';

/** Reports a behavioral assertion. */
function assert_review( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . $label . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

/** Runs registry validation with synthetic applicant data. */
function registry_review(): array|WP_Error {
	return papelito_company_validate_owner_registry( '52998224725', '1990-01-01', '11222333000181', 'Ana Paula Ribeiro' );
}

$lookup_fixture = array(
	'status'     => 'active',
	'legal_name' => 'EMPRESA TESTE',
	'is_mei'     => false,
	'qsa'        => array(),
);
assert_review( 'QSA ausente exige documento', 'document_required', registry_review()['review_path'] );
$lookup_fixture = array_merge(
	$lookup_fixture,
	array(
		'is_mei'            => true,
		'legal_nature_code' => '2135',
		'legal_name'        => '11.222.333 ANA PAULA RIBEIRO',
	)
);
assert_review( 'MEI compativel nao exige QSA inexistente', 'qsa_review', registry_review()['review_path'] );
assert_review( 'flag desligada preserva revisao humana', true, registry_review()['review_required'] );
$flag_enabled = true;
assert_review( 'MEI compativel respeita flag ligada', false, registry_review()['review_required'] );
$lookup_fixture['legal_name'] = '11.222.333 OUTRA PESSOA';
assert_review( 'MEI com nome divergente exige documento', 'document_required', registry_review()['review_path'] );
$lookup_fixture['legal_name'] = '11.222.333 ANA PAULA RIBEIRO';
$lookup_fixture['is_mei']     = 'true';
assert_review( 'MEI textual nao ativa excecao', 'document_required', registry_review()['review_path'] );
$lookup_fixture['is_mei']            = true;
$lookup_fixture['legal_nature_code'] = '2062';
assert_review( 'natureza divergente nao ativa excecao', 'document_required', registry_review()['review_path'] );

$partner        = array(
	'nome_socio'          => 'ANA PAULA RIBEIRO',
	'cnpj_cpf_do_socio'   => '***982247**',
	'codigo_faixa_etaria' => papelito_company_age_band( '1990-01-01' ),
	'qualificacao_socio'  => 'Sócio-Administrador',
);
$lookup_fixture = array(
	'status'            => 'active',
	'is_mei'            => false,
	'qsa'               => array( $partner ),
	'source_updated_at' => '2026-10-01T00:00:00Z',
);
assert_review( 'administrador compativel pode autoaprovar', false, registry_review()['review_required'] );
$lookup_fixture['qsa'][0]['qualificacao_socio'] = 'Sócio';
assert_review( 'socio sem administracao exige revisao', true, registry_review()['review_required'] );
unset( $lookup_fixture['qsa'][0]['qualificacao_socio'] );
assert_review( 'qualificacao desconhecida exige revisao', true, registry_review()['review_required'] );
$lookup_fixture['qsa'][] = array_merge( $partner, array( 'nome_socio' => 'OUTRA PESSOA' ) );
assert_review( 'administrador diferente nao empresta qualificacao', true, registry_review()['review_required'] );
$lookup_fixture['qsa'] = array( array_merge( $partner, array( 'codigo_faixa_etaria' => 9 ) ) );
$age_review            = registry_review();
assert_review( 'faixa etaria divergente isolada permite analise', true, is_array( $age_review ) && true === $age_review['review_required'] );
$lookup_fixture['qsa'][0]['cnpj_cpf_do_socio'] = '***111111**';
$mismatch                                      = registry_review();
assert_review( 'CPF divergente bloqueia', 'papelito_b2b_qsa_mismatch', $mismatch instanceof WP_Error ? $mismatch->get_error_code() : null );
$lookup_fixture['qsa'] = array( $partner );
$evidence              = registry_review()['evidence'];
assert_review( 'data da fonte separada', '2026-10-01T00:00:00Z', $evidence['source_updated_at'] ?? null );
$safe = papelito_company_owner_application_safe_evidence(
	array_merge(
		$evidence,
		array(
			'qsa' => array( $partner ),
			'cpf' => '52998224725',
		)
	)
);
assert_review( 'qualificacao da mesma pessoa persistida', true, $safe['eligible_partner_match'] ?? null );
assert_review( 'CPF e QSA nao persistidos', false, isset( $safe['cpf'] ) || isset( $safe['qsa'] ) );

foreach ( array(
	'unavailable'          => 'papelito_b2b_qsa_unavailable',
	'conflict'             => 'papelito_b2b_registry_conflict',
	'provider_unsupported' => 'papelito_b2b_registry_unsupported',
	'inactive'             => 'papelito_b2b_registry_inactive',
	'not_found'            => 'papelito_b2b_registry_not_found',
	'invalid'              => 'papelito_b2b_registry_invalid',
) as $registry_status => $code ) {
	$lookup_fixture = array( 'status' => $registry_status );
	$registry_error = registry_review();
	assert_review( $registry_status . ' codigo', $code, $registry_error instanceof WP_Error ? $registry_error->get_error_code() : null );
	assert_review( $registry_status . ' HTTP', in_array( $registry_status, array( 'unavailable', 'conflict', 'provider_unsupported' ), true ) ? 503 : 422, $registry_error instanceof WP_Error ? $registry_error->get_error_data()['status'] : null );
}

exit( $failures > 0 ? 1 : 0 );
