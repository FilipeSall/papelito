<?php
/**
 * Standalone regression test: o CPF pessoal preenche uma vez e pertence a uma conta so.
 *
 * `/identity/cpf` existe para o membro convidado completar a identidade que faltou (conta criada
 * por Google, ou vinculo criado antes de o CPF passar a ser exigido). A troca de CPF vive em uma
 * operacao separada e exige a senha atual, sem permitir reciclar CPF entre contas.
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
class WP_User {
	public function __construct( public int $ID, public string $user_pass ) {}
}
function get_userdata( int $user_id ): ?WP_User { return $GLOBALS['pap_users'][ $user_id ] ?? null; }
function wp_check_password( string $password, string $hash, int $user_id ): bool { return $password === $hash; }
function papelito_user_context_type( WP_User $user ): string { return 'customer'; }

/** Perfis em memoria: user_id => array( identity_status ), e o dono de cada CPF. */
$GLOBALS['pap_profiles']  = array();
$GLOBALS['pap_cpf_owner'] = array();
$GLOBALS['pap_upserts']   = array();
$GLOBALS['pap_users']     = array( 10 => new WP_User( 10, 'senha-atual' ) );

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
$change_match = array();
if ( ! preg_match( '/function papelito_company_customer_cpf_change.*?\n}/s', $source, $change_match ) ) {
	echo "FAIL: nao isolou papelito_company_customer_cpf_change\n";
	exit( 1 );
}
eval( $change_match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

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

echo "Cenario 4: troca exige senha e preserva unicidade\n";
$wrong_password = papelito_company_customer_cpf_change( 10, 'senha-errada', '037.122.851-40' );
papelito_assert( 'senha errada recusa a troca', true, is_wp_error( $wrong_password ) );
papelito_assert( 'senha errada responde 403', 403, $wrong_password->get_error_data()['status'] );
papelito_assert( 'senha errada nao grava CPF', 1, count( $GLOBALS['pap_upserts'] ) );
$changed = papelito_company_customer_cpf_change( 10, 'senha-atual', '037.122.851-40' );
papelito_assert( 'senha correta permite a troca', true, $changed );
papelito_assert( 'novo CPF fica associado ao usuario', 10, papelito_customer_profile_find_user_by_cpf( '03712285140' ) );
papelito_assert( 'troca grava uma vez', 2, count( $GLOBALS['pap_upserts'] ) );
$GLOBALS['pap_cpf_owner']['16899535009'] = 20;
$taken_change = papelito_company_customer_cpf_change( 10, 'senha-atual', '168.995.350-09' );
papelito_assert( 'troca para CPF de terceiro e recusada', true, is_wp_error( $taken_change ) );
papelito_assert( 'troca para CPF de terceiro responde 409', 409, $taken_change->get_error_data()['status'] );
papelito_assert( 'CPF de terceiro nao e gravado', 2, count( $GLOBALS['pap_upserts'] ) );

echo "Cenario 5: identidade pendente ainda pode ser completada\n";
$GLOBALS['pap_profiles'][12] = array( 'identity_status' => 'pending' );
$retry                       = papelito_company_customer_cpf_upsert( 12, '987.654.321-00' );
papelito_assert( 'perfil pendente aceita o CPF', true, $retry );
papelito_assert( 'perfil pendente vira verificado', 'verified', $GLOBALS['pap_profiles'][12]['identity_status'] );

echo "\n" . ( 0 === $failures ? "ALL PASS\n" : "{$failures} FAILURE(S)\n" );
exit( 0 === $failures ? 0 : 1 );
