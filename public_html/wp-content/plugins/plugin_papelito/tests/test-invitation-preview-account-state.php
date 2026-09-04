<?php
/**
 * Standalone regression test: o preview do convite diz se ja existe conta e por onde ela entra.
 *
 * Sem esses dois campos a landing precisava oferecer "Entrar" E "Criar conta" as cegas, e o
 * convidado novo que escolhia "Entrar" caia numa tela de login que nao cria conta.
 *
 * Nao e enumeracao de e-mail: so o portador do token chega neste endpoint, e `invitedEmail`
 * ja era devolvido antes desta mudanca.
 *
 * Usage: php tests/test-invitation-preview-account-state.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pap_emails']     = array();
$GLOBALS['pap_methods']    = array();
$GLOBALS['pap_invitation'] = null;

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function email_exists( mixed $email ): int|false { return $GLOBALS['pap_emails'][ strtolower( (string) $email ) ] ?? false; }
function papelito_auth_credential_methods( int $user_id ): array { return $GLOBALS['pap_methods'][ $user_id ] ?? array(); }
function papelito_company_get( int $id ): ?array { return array( 'trade_name' => 'CERRADO PAPEIS', 'legal_name' => 'CERRADO PAPEIS LTDA', 'cnpj' => '99999003000148', 'billing_email' => 'financeiro@cerrado.test', 'fiscal_cep' => '71200030' ); }
function papelito_company_invitation_find_pending_by_token( string $token ): ?array {
	$invitation = $GLOBALS['pap_invitation'];
	return ( null !== $invitation && $token === $invitation['token'] ) ? $invitation : null;
}

require_once __DIR__ . '/../includes/company_invitation_services.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo '  FAIL: ' . $label . ' — esperado ' . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

$GLOBALS['pap_invitation'] = array(
	'token'         => 'token-valido',
	'id'            => 7,
	'company_id'    => 21,
	'invited_email' => 'convidado@test.com',
	'invited_role'  => 'buyer',
);

echo "Cenario 1: token invalido nunca revela nada\n";
$invalid = papelito_company_invitation_preview( 'token-errado' );
papelito_assert( 'token desconhecido devolve convite invalido', 'papelito_b2b_invitation_invalid', $invalid->get_error_code() );

echo "Cenario 2: e-mail convidado ainda sem conta\n";
$new = papelito_company_invitation_preview( 'token-valido' );
papelito_assert( 'sem conta, accountExists e false', false, $new['accountExists'] );
papelito_assert( 'sem conta, authMethods vem vazio', array(), $new['authMethods'] );
papelito_assert( 'preview segue devolvendo a empresa', 'CERRADO PAPEIS', $new['companyName'] );
papelito_assert( 'preview segue devolvendo o papel', 'buyer', $new['invitedRole'] );

// O CNPJ da empresa que convidou e' parte do contrato: o convidado precisa ver a QUAL empresa
// esta se vinculando, e essa e' a unica origem do CNPJ no fluxo — nenhuma tela o aceita digitado.
// Registro publico na Receita, e so o portador do token chega aqui. O resto do dado fiscal
// (endereco, e-mail de faturamento, situacao) continua fora.
papelito_assert( 'preview devolve o CNPJ da empresa do convite', '99999003000148', $new['companyCnpj'] );
papelito_assert( 'preview nao vaza endereco fiscal', false, array_key_exists( 'fiscalAddress', $new ) || array_key_exists( 'fiscal_cep', $new ) );
papelito_assert( 'preview nao vaza e-mail de faturamento', false, array_key_exists( 'billingEmail', $new ) || array_key_exists( 'billing_email', $new ) );

echo "Cenario 3: e-mail convidado ja tem conta com senha\n";
$GLOBALS['pap_emails']['convidado@test.com'] = 30;
$GLOBALS['pap_methods'][30]                  = array( 'password' );
$existing = papelito_company_invitation_preview( 'token-valido' );
papelito_assert( 'com conta, accountExists e true', true, $existing['accountExists'] );
papelito_assert( 'com conta, authMethods traz o meio real', array( 'password' ), $existing['authMethods'] );

echo "Cenario 4: conta existente que entra so pelo Google\n";
$GLOBALS['pap_methods'][30] = array( 'google' );
$oauth = papelito_company_invitation_preview( 'token-valido' );
papelito_assert( 'conta OAuth nao e oferecida como login por senha', array( 'google' ), $oauth['authMethods'] );

echo "\n" . ( 0 === $failures ? "ALL PASS\n" : "{$failures} FAILURE(S)\n" );
exit( 0 === $failures ? 0 : 1 );
