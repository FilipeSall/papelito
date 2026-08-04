<?php
/**
 * Standalone regression test for billing-email confirmation tokens.
 *
 * Cobre uso unico, expiracao, substituicao por reenvio e as respostas distintas de token invalido
 * (404) e expirado (410) — antes os dois caiam no mesmo 404 e o usuario nao sabia se pedir reenvio
 * resolvia.
 *
 * Usage: php tests/test-billing-email-token.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['pap_transients'] = array();
function get_transient( $key ) { return $GLOBALS['pap_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $window = 0 ) { $GLOBALS['pap_transients'][ $key ] = $value; return true; }

$GLOBALS['pap_companies'] = array();
$GLOBALS['pap_audit']     = array();
$GLOBALS['pap_mail']      = array();
$GLOBALS['pap_last_args'] = array();
$GLOBALS['pap_env']       = array( 'PAPELITO_FRONTEND_URL' => 'https://marketplace.papelito.com' );
$GLOBALS['pap_environment'] = 'production';
$GLOBALS['pap_mail_ok']   = true;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ? $email : '';
}
function is_email( $email ) { return '' !== sanitize_email( $email ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	// Precisa variar entre chamadas: o teste de reenvio verifica que o token anterior e descartado.
	static $counter = 0;
	++$counter;
	return substr( str_pad( "token{$counter}", $length, 'x' ), 0, $length );
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_get_environment_type() { return $GLOBALS['pap_environment']; }
function papelito_env( string $key, $default = null ) {
	$value = $GLOBALS['pap_env'][ $key ] ?? '';
	return '' === $value ? $default : $value;
}
function wp_mail( $to, $subject, $body, $headers = array() ) {
	$GLOBALS['pap_mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers );
	return (bool) $GLOBALS['pap_mail_ok'];
}
function get_userdata( $id ) { return $GLOBALS['pap_users'][ $id ] ?? false; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['pap_meta'][ $id ][ $key ] ?? ''; }
function papelito_auth_requires_email_verification( $id ) { return false; }
function papelito_auth_rate_limit( ...$args ) { return true; }
function papelito_company_table_names() { return array( 'companies' => 'wp_papelito_companies' ); }
function papelito_company_audit( $company_id, $actor, $action, $payload = array() ) {
	$GLOBALS['pap_audit'][] = array( 'company_id' => $company_id, 'action' => $action, 'payload' => $payload );
}
function papelito_company_update( $company_id, $fields ) {
	if ( ! isset( $GLOBALS['pap_companies'][ $company_id ] ) ) {
		return new WP_Error( 'papelito_company_update_failed', 'Empresa inexistente.' );
	}
	foreach ( $fields as $key => $value ) {
		$GLOBALS['pap_companies'][ $company_id ][ $key ] = $value;
	}
	return true;
}
function papelito_company_authz_load( ...$args ) { return array(); }
function papelito_company_authz_can_manage( ...$args ) { return true; }
function papelito_company_member_is_operationally_active( ...$args ) { return true; }
function papelito_company_context( ...$args ) { return array(); }
function papelito_b2b_require_company_writes() { return true; }
function get_current_user_id() { return 10; }

class WP_User {
	public $ID; public $user_email;
	public function __construct( $id, $email ) { $this->ID = $id; $this->user_email = $email; }
}
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
class WP_REST_Request { public function get_json_params() { return array(); } public function get_header( $n ) { return ''; } }
class WP_REST_Response { public $data; public $status; public function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } }

class Papelito_Test_Wpdb {
	public $last_error = '';
	public function prepare( $sql, ...$args ) { $GLOBALS['pap_last_args'] = $args; return $sql; }
	public function get_row( $sql, $output = null ) {
		$hash = (string) ( $GLOBALS['pap_last_args'][0] ?? '' );
		foreach ( $GLOBALS['pap_companies'] as $id => $company ) {
			if ( (string) ( $company['pending_billing_email_token_hash'] ?? '' ) === $hash && '' !== $hash ) {
				return array_merge( array( 'id' => $id ), $company );
			}
		}
		return null;
	}
}
$GLOBALS['wpdb'] = new Papelito_Test_Wpdb();

require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/frontend_links.php';
require_once __DIR__ . '/../includes/billing_email_sync.php';
require_once __DIR__ . '/../includes/company_management_endpoints.php';

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

function papelito_test_pending_company( int $id, string $email, string $token, int $expires_in ): void {
	$GLOBALS['pap_companies'][ $id ] = array(
		'billing_email'                    => 'antigo@empresa.com',
		'billing_email_verified_at'        => null,
		'pending_billing_email'            => $email,
		'pending_billing_email_token_hash' => hash( 'sha256', $token ),
		'pending_billing_email_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
	);
}

/* --- token valido confirma, promove o endereco e limpa a pendencia --- */
papelito_test_pending_company( 1, 'novo@empresa.com', 'token-valido', DAY_IN_SECONDS );
$GLOBALS['pap_audit'] = array();
$response = papelito_company_mgmt_confirm_billing_email( 'token-valido' );
papelito_assert( 'token valido responde 200', 200, $response->status );
papelito_assert( 'token valido responde ok', array( 'ok' => true ), $response->data );
papelito_assert( 'endereco promovido', 'novo@empresa.com', $GLOBALS['pap_companies'][1]['billing_email'] );
papelito_assert( 'verified_at gravado', true, ! empty( $GLOBALS['pap_companies'][1]['billing_email_verified_at'] ) );
papelito_assert( 'pendencia limpa', null, $GLOBALS['pap_companies'][1]['pending_billing_email'] );
papelito_assert( 'hash limpo', null, $GLOBALS['pap_companies'][1]['pending_billing_email_token_hash'] );
papelito_assert( 'expiracao limpa', null, $GLOBALS['pap_companies'][1]['pending_billing_email_expires_at'] );
papelito_assert( 'auditoria registrada', 'billing_email_verified', $GLOBALS['pap_audit'][0]['action'] );
papelito_assert( 'auditoria marca a origem', 'token', $GLOBALS['pap_audit'][0]['payload']['source'] );

/* --- o mesmo token nao serve duas vezes --- */
$reused = papelito_company_mgmt_confirm_billing_email( 'token-valido' );
papelito_assert( 'reuso e WP_Error', true, is_wp_error( $reused ) );
papelito_assert( 'reuso responde 404', 404, $reused->data['status'] );
papelito_assert( 'reuso tem codigo de invalido', 'papelito_b2b_invalid_billing_token', $reused->code );

/* --- token desconhecido e token vazio --- */
$unknown = papelito_company_mgmt_confirm_billing_email( 'nunca-existiu' );
papelito_assert( 'token desconhecido responde 404', 404, $unknown->data['status'] );
$empty = papelito_company_mgmt_confirm_billing_email( '' );
papelito_assert( 'token vazio responde 422', 422, $empty->data['status'] );

/* --- token expirado responde 410, e nao 404 --- */
papelito_test_pending_company( 2, 'novo@empresa.com', 'token-expirado', -60 );
$expired = papelito_company_mgmt_confirm_billing_email( 'token-expirado' );
papelito_assert( 'token expirado e WP_Error', true, is_wp_error( $expired ) );
papelito_assert( 'token expirado responde 410', 410, $expired->data['status'] );
papelito_assert( 'token expirado tem codigo proprio', 'papelito_b2b_billing_token_expired', $expired->code );
papelito_assert( 'token expirado nao confirma nada', null, $GLOBALS['pap_companies'][2]['billing_email_verified_at'] );

/* --- pedir confirmacao de outro endereco invalida o token anterior --- */
papelito_test_pending_company( 3, 'primeiro@empresa.com', 'token-antigo', DAY_IN_SECONDS );
$GLOBALS['pap_mail'] = array();
$sent = papelito_company_mgmt_send_billing_email_confirmation( 3, 'segundo@empresa.com' );
papelito_assert( 'novo envio deu certo', true, $sent );
papelito_assert( 'pendencia aponta para o novo endereco', 'segundo@empresa.com', $GLOBALS['pap_companies'][3]['pending_billing_email'] );
$stale = papelito_company_mgmt_confirm_billing_email( 'token-antigo' );
papelito_assert( 'token do endereco substituido nao vale', true, is_wp_error( $stale ) );
papelito_assert( 'token substituido responde 404', 404, $stale->data['status'] );

/* --- reenvio rotaciona o token: o link anterior deixa de funcionar --- */
$first_hash = (string) $GLOBALS['pap_companies'][3]['pending_billing_email_token_hash'];
papelito_company_mgmt_send_billing_email_confirmation( 3, 'segundo@empresa.com' );
$second_hash = (string) $GLOBALS['pap_companies'][3]['pending_billing_email_token_hash'];
papelito_assert( 'reenvio troca o hash do token', false, $first_hash === $second_hash );

/* --- o e-mail leva o link do ambiente, nunca localhost --- */
papelito_assert( 'um e-mail por envio', 2, count( $GLOBALS['pap_mail'] ) );
$last = end( $GLOBALS['pap_mail'] );
papelito_assert( 'assunto do e-mail', 'Confirme o e-mail de faturamento da Papelito', $last['subject'] );
papelito_assert( 'destinatario e o endereco pendente', 'segundo@empresa.com', $last['to'] );
papelito_assert( 'link usa o dominio do ambiente', true, str_contains( $last['body'], 'https://marketplace.papelito.com/confirmar-email-faturamento?token=' ) );
papelito_assert( 'link nao usa localhost', false, str_contains( $last['body'], 'localhost' ) );
papelito_assert( 'corpo avisa da expiracao', true, str_contains( $last['body'], 'expira em 24 horas' ) );
papelito_assert( 'define content-type', true, in_array( 'Content-Type: text/plain; charset=UTF-8', $last['headers'], true ) );

/* --- base de preview na allowlist gera link de preview --- */
$GLOBALS['pap_env']['PAPELITO_ALLOWED_ORIGINS'] = 'https://marketplace.papelito.com,https://papelito-web.vercel.app';
papelito_company_mgmt_send_billing_email_confirmation( 3, 'segundo@empresa.com', 'https://papelito-web.vercel.app' );
$preview = end( $GLOBALS['pap_mail'] );
papelito_assert( 'link honra a base de preview', true, str_contains( $preview['body'], 'https://papelito-web.vercel.app/confirmar-email-faturamento?token=' ) );

/* --- sem base configurada nao grava pendencia nem envia e-mail --- */
$GLOBALS['pap_env'] = array();
papelito_test_pending_company( 4, 'intocado@empresa.com', 'token-4', DAY_IN_SECONDS );
$before  = $GLOBALS['pap_companies'][4];
$mails   = count( $GLOBALS['pap_mail'] );
$blocked = papelito_company_mgmt_send_billing_email_confirmation( 4, 'outro@empresa.com' );
papelito_assert( 'sem base resolvida retorna WP_Error', true, is_wp_error( $blocked ) );
papelito_assert( 'codigo do erro', 'papelito_frontend_url_unresolved', $blocked->code );
papelito_assert( 'nenhuma pendencia foi sobrescrita', $before, $GLOBALS['pap_companies'][4] );
papelito_assert( 'nenhum e-mail foi enviado', $mails, count( $GLOBALS['pap_mail'] ) );

/* --- falha de entrega vira erro, e nao 200 silencioso --- */
$GLOBALS['pap_env']     = array( 'PAPELITO_FRONTEND_URL' => 'https://marketplace.papelito.com' );
$GLOBALS['pap_mail_ok'] = false;
$failed = papelito_company_mgmt_send_billing_email_confirmation( 4, 'outro@empresa.com' );
papelito_assert( 'falha de wp_mail retorna WP_Error', true, is_wp_error( $failed ) );
papelito_assert( 'falha de envio responde 500', 500, $failed->data['status'] );
$GLOBALS['pap_mail_ok'] = true;

/* --- cota por empresa: o endereco de destino vem do chamador, entao o envio tem teto --- */
$GLOBALS['pap_transients'] = array();
papelito_test_pending_company( 9, 'cota@empresa.com', 'token-9', DAY_IN_SECONDS );

for ( $attempt = 1; $attempt <= PAPELITO_BILLING_EMAIL_SEND_MAX; $attempt++ ) {
	papelito_assert(
		"envio {$attempt} dentro da cota",
		true,
		true === papelito_company_mgmt_send_billing_email_confirmation( 9, "alvo{$attempt}@externo.com" )
	);
}

$mails_before = count( $GLOBALS['pap_mail'] );
$throttled    = papelito_company_mgmt_send_billing_email_confirmation( 9, 'alvo-extra@externo.com' );
papelito_assert( 'envio acima da cota e recusado', true, is_wp_error( $throttled ) );
papelito_assert( 'cota estourada responde 429', 429, $throttled->data['status'] );
papelito_assert( 'codigo da cota', 'papelito_b2b_billing_email_rate_limited', $throttled->code );
papelito_assert( 'nenhum e-mail extra sai', $mails_before, count( $GLOBALS['pap_mail'] ) );

/* --- a cota e por empresa: outra empresa nao herda o teto --- */
papelito_test_pending_company( 10, 'outra@empresa.com', 'token-10', DAY_IN_SECONDS );
papelito_assert(
	'outra empresa segue livre',
	true,
	true === papelito_company_mgmt_send_billing_email_confirmation( 10, 'alvo@externo.com' )
);

/* --- ambiente sem base nao queima cota --- */
$GLOBALS['pap_transients'] = array();
$GLOBALS['pap_env']        = array();
papelito_company_mgmt_send_billing_email_confirmation( 10, 'alvo@externo.com' );
$GLOBALS['pap_env'] = array( 'PAPELITO_FRONTEND_URL' => 'https://marketplace.papelito.com' );
for ( $attempt = 1; $attempt <= PAPELITO_BILLING_EMAIL_SEND_MAX; $attempt++ ) {
	$result = papelito_company_mgmt_send_billing_email_confirmation( 10, "pos-erro{$attempt}@externo.com" );
}
papelito_assert( 'erro de configuracao nao consumiu tentativa', true, true === $result );

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( $failures === 0 ? 0 : 1 );
