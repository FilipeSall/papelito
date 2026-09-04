<?php
/**
 * Standalone regression test: o CPF pessoal preenche uma vez e pertence a uma conta so.
 *
 * `/identity/cpf` existe para o membro convidado completar a identidade que faltou (conta criada
 * por Google, ou vinculo criado antes de o CPF passar a ser exigido). Ele NAO e uma tela de troca
 * de CPF: sobrescrever um CPF ja verificado desfaria a evidencia que aprovou a titularidade (o CPF
 * conferido contra o QSA da Receita) e permitiria reciclar um CPF entre contas.
 *
 * Usage: php tests/test-customer-cpf-identity-gate.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";

		return;
	}

	++$failures;
	echo '  FAIL: ' . $label . ' — esperado ' . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function current_time( string $type, bool $gmt = false ): string { return '2026-09-04 12:00:00'; }

/** Perfis em memoria: user_id => array( identity_status ), e o dono de cada CPF. */
$GLOBALS['pap_profiles']  = array();
$GLOBALS['pap_cpf_owner'] = array();
$GLOBALS['pap_upserts']   = array();

function papelito_company_profile_get( int $user_id ): ?array {
	return $GLOBALS['pap_profiles'][ $user_id ] ?? null;
}

function papelito_customer_profile_find_user_by_cpf( string $cpf ) {
	$digits = preg_replace( '/\D+/', '', $cpf ) ?? '';

	return $GLOBALS['pap_cpf_owner'][ $digits ] ?? null;
}

function papelito_customer_profile_upsert( int $user_id, string $cpf, array $fields = array() ) {
	$digits                              = preg_replace( '/\D+/', '', $cpf ) ?? '';
	$GLOBALS['pap_upserts'][]            = array( 'user' => $user_id, 'cpf' => $digits );
	$GLOBALS['pap_cpf_owner'][ $digits ] = $user_id;
	$GLOBALS['pap_profiles'][ $user_id ] = array( 'identity_status' => (string) ( $fields['identity_status'] ?? 'pending' ) );

	return true;
}

$source = file_get_contents( dirname( __DIR__ ) . '/includes/company_services.php' );
if ( false === $source || ! preg_match( '/function papelito_company_customer_cpf_upsert.*?\n}/s', $source, $match ) ) {
	echo "FAIL: nao isolou papelito_company_customer_cpf_upsert\n";
	exit( 1 );
}
eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

echo "Cenario 1: conta sem identidade preenche o proprio CPF\n";
$result = papelito_company_customer_cpf_upsert( 10, '529.982.247-25' );
papelito_assert( 'CPF inedito e aceito', true, $result );
papelito_assert( 'perfil fica verificado', 'verified', $GLOBALS['pap_profiles'][10]['identity_status'] );
papelito_assert( 'gravou exatamente uma vez', 1, count( $GLOBALS['pap_upserts'] ) );

echo "Cenario 2: CPF de outra conta e recusado\n";
$taken = papelito_company_customer_cpf_upsert( 11, '529.982.247-25' );
papelito_assert( 'CPF de terceiro vira erro', true, is_wp_error( $taken ) );
papelito_assert( 'erro identifica CPF em uso', 'papelito_pii_cpf_in_use', $taken->get_error_code() );
papelito_assert( 'CPF de terceiro responde 409', 409, $taken->get_error_data()['status'] );
papelito_assert( 'nada foi gravado para a conta invasora', 1, count( $GLOBALS['pap_upserts'] ) );
papelito_assert( 'conta invasora segue sem perfil', null, papelito_company_profile_get( 11 ) );

echo "Cenario 3: identidade verificada nao e trocada por autoatendimento\n";
$swap = papelito_company_customer_cpf_upsert( 10, '111.222.333-96' );
papelito_assert( 'troca vira erro', true, is_wp_error( $swap ) );
papelito_assert( 'erro identifica identidade ja verificada', 'papelito_b2b_identity_already_verified', $swap->get_error_code() );
papelito_assert( 'troca responde 409', 409, $swap->get_error_data()['status'] );
papelito_assert( 'CPF original permanece o unico gravado', 1, count( $GLOBALS['pap_upserts'] ) );
papelito_assert( 'CPF original segue sendo o do usuario', 10, papelito_customer_profile_find_user_by_cpf( '52998224725' ) );

echo "Cenario 4: identidade pendente ainda pode ser completada\n";
$GLOBALS['pap_profiles'][12] = array( 'identity_status' => 'pending' );
$retry                       = papelito_company_customer_cpf_upsert( 12, '168.995.350-09' );
papelito_assert( 'perfil pendente aceita o CPF', true, $retry );
papelito_assert( 'perfil pendente vira verificado', 'verified', $GLOBALS['pap_profiles'][12]['identity_status'] );

echo "\n" . ( 0 === $failures ? "ALL PASS\n" : "{$failures} FAILURE(S)\n" );
exit( 0 === $failures ? 0 : 1 );
