<?php
/**
 * Standalone regression test: convite não vai para quem já tem vínculo vivo com a empresa.
 *
 * Convidar um membro ativo não escalava privilégio (o aceite é idempotente e não muda papel), mas
 * disparava e-mail, criava uma segunda linha na lista de convites para o mesmo endereço e fazia o
 * admin acreditar que tinha promovido alguém. Vínculo encerrado continua convidável — readmitir
 * por convite é o caminho previsto.
 *
 * Usage: php tests/test-company-invitation-membership-guard.php
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

/** Contas por e-mail e vínculos por (empresa, usuário), montados em cada cenário. */
$GLOBALS['pap_emails']  = array();
$GLOBALS['pap_members'] = array();

function email_exists( string $email ) { return $GLOBALS['pap_emails'][ strtolower( $email ) ] ?? false; }
function papelito_company_member_get( int $company_id, int $user_id ): ?array {
	return $GLOBALS['pap_members'][ "{$company_id}:{$user_id}" ] ?? null;
}

$source = file_get_contents( dirname( __DIR__ ) . '/includes/company_invitation_services.php' );
if ( false === $source || ! preg_match( '/function papelito_company_invitation_blocking_membership.*?\n}/s', $source, $match ) ) {
	echo "FAIL: nao isolou papelito_company_invitation_blocking_membership\n";
	exit( 1 );
}
eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

echo "Cenario 1: e-mail sem conta segue convidavel\n";
papelito_assert( 'sem conta nao bloqueia', null, papelito_company_invitation_blocking_membership( 21, 'novo@test.com' ) );

echo "Cenario 2: e-mail com conta mas sem vinculo segue convidavel\n";
$GLOBALS['pap_emails']['externo@test.com'] = 90;
papelito_assert( 'conta sem vinculo nao bloqueia', null, papelito_company_invitation_blocking_membership( 21, 'externo@test.com' ) );

echo "Cenario 3: vinculo vivo bloqueia\n";
$GLOBALS['pap_emails']['membro@test.com'] = 91;
foreach ( array( 'active', 'suspended', 'pending_company_approval', 'pending_identity' ) as $status ) {
	$GLOBALS['pap_members']['21:91'] = array( 'member_status' => $status );
	$blocked                         = papelito_company_invitation_blocking_membership( 21, 'membro@test.com' );
	papelito_assert( "status {$status} bloqueia", true, is_wp_error( $blocked ) );
	papelito_assert( "status {$status} responde 409", 409, is_wp_error( $blocked ) ? $blocked->get_error_data()['status'] : null );
	papelito_assert( "status {$status} usa codigo estavel", 'papelito_b2b_invitation_already_member', is_wp_error( $blocked ) ? $blocked->get_error_code() : null );
	papelito_assert( "status {$status} explica o caminho certo", true, is_wp_error( $blocked ) && '' !== $blocked->get_error_message() );
}

echo "Cenario 4: vinculo encerrado volta a ser convidavel\n";
foreach ( array( 'revoked', 'rejected' ) as $status ) {
	$GLOBALS['pap_members']['21:91'] = array( 'member_status' => $status );
	papelito_assert( "status {$status} nao bloqueia", null, papelito_company_invitation_blocking_membership( 21, 'membro@test.com' ) );
}

echo "Cenario 5: o bloqueio e por empresa, nao global\n";
$GLOBALS['pap_members']['21:91'] = array( 'member_status' => 'active' );
papelito_assert( 'membro da empresa 21 nao bloqueia convite da 22', null, papelito_company_invitation_blocking_membership( 22, 'membro@test.com' ) );

echo "\n" . ( 0 === $failures ? "ALL PASS\n" : "{$failures} FAILURE(S)\n" );
exit( 0 === $failures ? 0 : 1 );
