<?php
/**
 * Standalone regression test for billing-email decision rules.
 *
 * Confirmacao especifica do e-mail de faturamento so deve existir quando o endereco e diferente do
 * e-mail principal ja verificado da conta. Este teste fixa a tabela de decisao.
 *
 * Usage: php tests/test-billing-email-rules.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pap_meta'] = array();

function add_action( ...$args ) {}
function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ? $email : '';
}
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['pap_meta'][ $id ][ $key ] ?? ''; }
function current_time( $type, $gmt = 0 ) { return '2026-08-04 12:00:00'; }
function papelito_auth_requires_email_verification( $id ) {
	$status = (string) get_user_meta( $id, 'papelito_email_verification_status', true );
	if ( '' === $status ) { return false; }
	return 'verified' !== $status;
}

class WP_User {
	public $ID; public $user_email;
	public function __construct( $id, $email ) { $this->ID = $id; $this->user_email = $email; }
}
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/billing_email_sync.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n";
	}
}

/* --- normalizacao: e a base de toda comparacao --- */
papelito_assert( 'normaliza caixa', 'fiscal@empresa.com', papelito_normalize_email( 'Fiscal@Empresa.COM' ) );
papelito_assert( 'normaliza espacos', 'fiscal@empresa.com', papelito_normalize_email( '  fiscal@empresa.com  ' ) );
papelito_assert( 'invalido vira vazio', '', papelito_normalize_email( 'nao-e-email' ) );
papelito_assert( 'preserva ponto na parte local', 'jo.ao@empresa.com', papelito_normalize_email( 'Jo.Ao@Empresa.com' ) );
papelito_assert( 'preserva sufixo +tag', 'joao+nf@empresa.com', papelito_normalize_email( 'joao+NF@empresa.com' ) );
papelito_assert( 'caixa nao cria diferenca', true, papelito_emails_match( 'Fiscal@Empresa.com', ' fiscal@empresa.COM ' ) );
papelito_assert( 'enderecos distintos nao casam', false, papelito_emails_match( 'a@x.com', 'b@x.com' ) );
papelito_assert( 'vazio nunca casa com vazio', false, papelito_emails_match( '', '' ) );
papelito_assert( 'nulo-como-vazio nunca casa', false, papelito_emails_match( 'a@x.com', '' ) );
papelito_assert( 'ponto na parte local importa', false, papelito_emails_match( 'jo.ao@empresa.com', 'joao@empresa.com' ) );

/* --- tabela de decisao do PATCH --- */
$verified_company = array(
	'billing_email'             => 'fiscal@empresa.com',
	'billing_email_verified_at' => '2026-07-01 00:00:00',
	'pending_billing_email'     => null,
);
$pending_company = array(
	'billing_email'             => 'fiscal@empresa.com',
	'billing_email_verified_at' => null,
	'pending_billing_email'     => 'novo@empresa.com',
);
$fresh_company = array(
	'billing_email'             => 'dono@empresa.com',
	'billing_email_verified_at' => null,
	'pending_billing_email'     => null,
);

papelito_assert(
	'salvar o mesmo endereco verificado nao envia nada',
	'noop_same_verified',
	papelito_billing_email_decide_update( $verified_company, 'fiscal@empresa.com', 'dono@empresa.com', true )
);
papelito_assert(
	'mudar so a caixa nao envia nada',
	'noop_same_verified',
	papelito_billing_email_decide_update( $verified_company, papelito_normalize_email( ' Fiscal@Empresa.COM ' ), 'dono@empresa.com', true )
);
papelito_assert(
	'reenviar o mesmo pendente nao rotaciona token',
	'noop_same_pending',
	papelito_billing_email_decide_update( $pending_company, 'novo@empresa.com', 'dono@empresa.com', true )
);
papelito_assert(
	'igual ao e-mail verificado da conta autoconfirma',
	'confirm_matches_account',
	papelito_billing_email_decide_update( $fresh_company, 'dono@empresa.com', 'dono@empresa.com', true )
);
papelito_assert(
	'igual ao e-mail da conta com caixa diferente autoconfirma',
	'confirm_matches_account',
	papelito_billing_email_decide_update( $fresh_company, papelito_normalize_email( 'DONO@empresa.com' ), 'dono@empresa.com', true )
);
papelito_assert(
	'igual ao e-mail da conta NAO verificada pede confirmacao',
	'send_confirmation',
	papelito_billing_email_decide_update( $fresh_company, 'dono@empresa.com', 'dono@empresa.com', false )
);
papelito_assert(
	'endereco diferente pede confirmacao',
	'send_confirmation',
	papelito_billing_email_decide_update( $verified_company, 'contabilidade@parceiro.com', 'dono@empresa.com', true )
);
papelito_assert(
	'trocar o pendente por outro endereco pede confirmacao',
	'send_confirmation',
	papelito_billing_email_decide_update( $pending_company, 'terceiro@empresa.com', 'dono@empresa.com', true )
);
papelito_assert(
	'mesmo endereco ainda nao verificado pede confirmacao',
	'send_confirmation',
	papelito_billing_email_decide_update( $fresh_company, 'dono@empresa.com', 'outro@empresa.com', true )
);

/* --- tabela de decisao da sincronizacao com o e-mail principal --- */
papelito_assert(
	'faturamento igual ao principal verificado confirma',
	'confirm',
	papelito_billing_email_decide_sync( $fresh_company, 'dono@empresa.com', true )
);
papelito_assert(
	'principal ainda nao confirmado nao confirma faturamento',
	'skip_account_unverified',
	papelito_billing_email_decide_sync( $fresh_company, 'dono@empresa.com', false )
);
papelito_assert(
	'endereco diferente nunca e confirmado automaticamente',
	'skip_email_differs',
	papelito_billing_email_decide_sync(
		array( 'billing_email' => 'contabilidade@parceiro.com', 'billing_email_verified_at' => null ),
		'dono@empresa.com',
		true
	)
);
papelito_assert(
	'ja verificado tem precedencia sobre endereco divergente',
	'skip_already_verified',
	papelito_billing_email_decide_sync( $verified_company, 'dono@empresa.com', true )
);
papelito_assert(
	'ja verificado e no-op idempotente',
	'skip_already_verified',
	papelito_billing_email_decide_sync(
		array( 'billing_email' => 'dono@empresa.com', 'billing_email_verified_at' => '2026-07-01 00:00:00' ),
		'dono@empresa.com',
		true
	)
);
papelito_assert(
	'faturamento vazio nao herda verificacao',
	'skip_email_differs',
	papelito_billing_email_decide_sync( array( 'billing_email' => '', 'billing_email_verified_at' => null ), 'dono@empresa.com', true )
);

/* --- conta legada (sem meta) vale como verificada, como no resto do projeto --- */
$GLOBALS['pap_meta'][10] = array();
papelito_assert( 'conta legada conta como verificada', true, papelito_billing_email_account_is_verified( 10 ) );
papelito_assert( 'legada sem verified_at usa agora', '2026-08-04 12:00:00', papelito_billing_email_account_verified_at( 10 ) );

$GLOBALS['pap_meta'][11] = array( 'papelito_email_verification_status' => 'pending' );
papelito_assert( 'conta pendente nao vale como verificada', false, papelito_billing_email_account_is_verified( 11 ) );

$GLOBALS['pap_meta'][12] = array(
	'papelito_email_verification_status' => 'verified',
	'papelito_email_verified_at'         => '2026-07-15 08:30:00',
);
papelito_assert( 'conta verificada', true, papelito_billing_email_account_is_verified( 12 ) );
papelito_assert( 'usa o verified_at real', '2026-07-15 08:30:00', papelito_billing_email_account_verified_at( 12 ) );

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( $failures === 0 ? 0 : 1 );
