<?php
/**
 * Standalone regression test: convite nunca deixa o convidado sem forma de autenticacao.
 *
 * O bug original nao era "conta ativada sem senha" — era um beco sem saida no ponto de entrada:
 * quem ja tinha conta recebia 409 do cadastro por convite e ficava sem caminho, e o login Google
 * mandava o convidado fazer candidatura empresarial. Este teste fixa as tres garantias:
 *
 *   1. o preview diz se o e-mail convidado ja tem conta e por onde ela entra;
 *   2. cadastro por convite para e-mail existente RETOMA e NUNCA sobrescreve a credencial;
 *   3. o Google cria conta apenas com um convite pendente para aquele mesmo e-mail.
 *
 * Usage: php tests/test-invitation-credential-gate.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['pap_meta']             = array();
$GLOBALS['pap_users']            = array();
$GLOBALS['pap_emails']           = array();
$GLOBALS['pap_password_updates'] = array();
$GLOBALS['pap_inserted']         = array();
$GLOBALS['pap_mail']             = array();
$GLOBALS['pap_invitation']       = null;
$GLOBALS['pap_next_user_id']     = 500;

function add_action( mixed ...$args ): void { $GLOBALS['pap_hooks'][] = $args; }
function add_filter( mixed ...$args ): void { $GLOBALS['pap_hooks'][] = $args; }
function do_action( mixed ...$args ): void {}
function register_rest_route( mixed ...$args ): void { $GLOBALS['pap_routes'][] = $args; }
function sanitize_key( mixed $v ): string { return strtolower( (string) $v ); }
function sanitize_text_field( mixed $v ): string { return trim( (string) $v ); }
function sanitize_email( mixed $v ): string { return strtolower( trim( (string) $v ) ); }
function is_email( mixed $v ): bool { return false !== strpos( (string) $v, '@' ); }
function wp_unslash( mixed $v ): mixed { return $v; }
function is_wp_error( mixed $v ): bool { return $v instanceof WP_Error; }
function get_current_user_id(): int { return 0; }
function is_user_logged_in(): bool { return false; }
function current_user_can( string $c ): bool { return false; }
function current_time( string $type, bool $gmt = false ): string { return '2026-09-04 00:00:00'; }
function get_userdata( int $id ): object|false { return $GLOBALS['pap_users'][ $id ] ?? false; }
function get_user_meta( int $id, string $k, bool $single = false ): mixed { return $GLOBALS['pap_meta'][ $id ][ $k ] ?? ''; }
function update_user_meta( int $id, string $k, mixed $v, mixed $prev = '' ): bool { $GLOBALS['pap_meta'][ $id ][ $k ] = $v; return true; }
function add_user_meta( int $id, string $k, mixed $v, bool $unique = false ): bool { if ( $unique && isset( $GLOBALS['pap_meta'][ $id ][ $k ] ) ) { return false; } $GLOBALS['pap_meta'][ $id ][ $k ] = $v; return true; }
function delete_user_meta( int $id, string $k ): bool { unset( $GLOBALS['pap_meta'][ $id ][ $k ] ); return true; }
function email_exists( mixed $email ): int|false { return $GLOBALS['pap_emails'][ strtolower( (string) $email ) ] ?? false; }
function username_exists( mixed ...$a ): false { return false; }
function wp_insert_user( array $data ): int {
	$id = ++$GLOBALS['pap_next_user_id'];
	$GLOBALS['pap_inserted'][]          = $data;
	$GLOBALS['pap_emails'][ strtolower( (string) $data['user_email'] ) ] = $id;
	$GLOBALS['pap_users'][ $id ]        = new WP_User( $id );
	$GLOBALS['pap_users'][ $id ]->user_email = (string) $data['user_email'];
	$GLOBALS['pap_users'][ $id ]->user_pass  = 'hash:' . (string) $data['user_pass'];
	return $id;
}
function wp_update_user( mixed ...$a ): int { return 0; }
function wp_set_password( string $password, int $id ): void { $GLOBALS['pap_password_updates'][] = array( $password, $id ); }
function wp_check_password( mixed ...$a ): bool { return false; }
function wp_hash_password( string $p ): string { return 'hash:' . $p; }
function wp_generate_password( int $len = 12, bool $s = true, bool $e = false ): string { return str_repeat( 'x', $len ); }
function wp_generate_uuid4(): string { return 'session-version'; }
function wp_mail( mixed ...$args ): bool { $GLOBALS['pap_mail'][] = $args; return true; }
function papelito_frontend_link( string $path ): string { return 'https://papelito.test/' . $path; }
function papelito_normalize_email( string $e ): string { return strtolower( trim( $e ) ); }
function papelito_normalize_unicode_spaces( string $v ): string { return $v; }
function papelito_b2b_mark_cohort( int $id ): void { $GLOBALS['pap_meta'][ $id ]['papelito_b2b_required'] = '1'; }
function papelito_b2b_company_model_enabled(): bool { return true; }
function papelito_rate_limit( mixed ...$a ): bool { return true; }
function papelito_rate_limit_identity( string $f = '' ): string { return 'test'; }
function get_transient( mixed ...$a ): false { return false; }
function set_transient( mixed ...$a ): true { return true; }
function wp_remote_get( mixed ...$a ): WP_Error { return new WP_Error( 'unused' ); }
function wp_remote_retrieve_response_code( mixed ...$a ): int { return 500; }
function wp_remote_retrieve_body( mixed ...$a ): string { return ''; }
function check_password_reset_key( mixed ...$a ): WP_Error { return new WP_Error( 'unused' ); }
function reset_password( mixed ...$a ): void {}
function papelito_company_onboarding_get( int $id ): ?array { return null; }
function papelito_company_onboarding_mark_google( int $id ): void { $GLOBALS['pap_meta'][ $id ]['papelito_onboarding_google'] = '1'; }
function papelito_emails_match( string $a, string $b ): bool { return strtolower( trim( $a ) ) === strtolower( trim( $b ) ); }
function papelito_name_part_validation_error( string $value, string $message ): ?string { return '' === trim( $value ) ? $message : null; }
function papelito_validate_cpf( string $cpf ): bool { return '52998224725' === preg_replace( '/\D+/', '', $cpf ); }
$GLOBALS['pap_cpf_owner'] = null;
function papelito_customer_profile_upsert( int $user_id, string $cpf, array $fields = array() ): true { return true; }
function papelito_customer_profile_find_user_by_cpf( string $cpf ) { return $GLOBALS['pap_cpf_owner']; }
function wp_delete_user( int $user_id ): bool { unset( $GLOBALS['pap_users'][ $user_id ] ); return true; }
function papelito_company_invitation_find_pending_by_token( string $token ): ?array {
	$invitation = $GLOBALS['pap_invitation'];
	return ( null !== $invitation && $token === $invitation['token'] ) ? $invitation : null;
}

class PapelitoTestUser {
	private array $attributes;
	public function __construct( int $id ) {
		$this->attributes = array( 'ID' => $id, 'user_email' => '', 'user_pass' => '', 'first_name' => '', 'last_name' => '', 'roles' => array( 'customer' ) );
	}
	public function __get( string $n ): mixed { return $this->attributes[ $n ] ?? null; }
	public function __set( string $n, mixed $v ): void { $this->attributes[ $n ] = $v; }
	public function exists(): bool { return true; }
}
class PapelitoTestError {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_codes(): array { return array( $this->code ); }
	public function add( string $code, string $message = '', mixed $data = null ): void { $this->code = $code; $this->message = $message; }
	public function add_data( mixed $data ): void { $this->data = $data; }
	public function has_errors(): bool { return '' !== $this->code; }
}
class PapelitoTestRestRequest {
	public function __construct( private array $params = array() ) {}
	public function get_json_params(): array { return $this->params; }
	public function get_params(): array { return $this->params; }
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
}
class PapelitoTestRestResponse {
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
}
class PapelitoTestSessionTokens {
	public static function get_instance( int $id ): self { return new self(); }
	public function destroy_all(): void {}
}
class_alias( PapelitoTestUser::class, 'WP_User' );
class_alias( PapelitoTestError::class, 'WP_Error' );
class_alias( PapelitoTestRestRequest::class, 'WP_REST_Request' );
class_alias( PapelitoTestRestResponse::class, 'WP_REST_Response' );
class_alias( PapelitoTestSessionTokens::class, 'WP_Session_Tokens' );

require_once __DIR__ . '/../includes/auth_endpoints.php';

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

/* ---------- 1. meios de autenticacao disponiveis ---------- */
echo "Cenario 1: authMethods reflete o que a conta consegue usar\n";

$GLOBALS['pap_users'][10] = new WP_User( 10 );
papelito_assert( 'conta legada sem meta continua valendo por senha', array( 'password' ), papelito_auth_credential_methods( 10 ) );

$GLOBALS['pap_meta'][11]['google_sub'] = 'sub-11';
papelito_assert( 'conta com Google vinculado soma os dois meios', array( 'password', 'google' ), papelito_auth_credential_methods( 11 ) );

papelito_auth_mark_password_unset( 12 );
$GLOBALS['pap_meta'][12]['google_sub'] = 'sub-12';
papelito_assert( 'conta nascida no OAuth entra so pelo Google', array( 'google' ), papelito_auth_credential_methods( 12 ) );

papelito_auth_mark_password_set( 12 );
papelito_assert( 'definir senha depois habilita o login por senha', array( 'password', 'google' ), papelito_auth_credential_methods( 12 ) );

/* ---------- 2. retomada nunca sobrescreve credencial ---------- */
echo "Cenario 2: cadastro por convite para e-mail existente retoma, nao sobrescreve\n";

$GLOBALS['pap_invitation'] = array( 'token' => 'token-valido', 'invited_email' => 'convidado@test.com' );
$GLOBALS['pap_emails']['convidado@test.com'] = 20;
$GLOBALS['pap_users'][20]                    = new WP_User( 20 );
$GLOBALS['pap_users'][20]->user_email        = 'convidado@test.com';
$GLOBALS['pap_meta'][20]['papelito_email_verification_status']  = 'pending';
$GLOBALS['pap_meta'][20]['papelito_email_verification_sent_at'] = gmdate( 'Y-m-d H:i:s' );

$pending = papelito_auth_handle_invitation_register(
	new WP_REST_Request(
		array( 'token' => 'token-valido', 'email' => 'convidado@test.com', 'password' => 'SenhaDoAtacante1', 'first_name' => 'Ana', 'last_name' => 'Silva', 'cpf' => '52998224725' )
	)
);
papelito_assert( 'conta pendente nao vira erro 409', false, is_wp_error( $pending ) );
papelito_assert( 'conta pendente responde 200, nao 201', 200, $pending->status );
papelito_assert( 'conta pendente segue na confirmacao de e-mail', true, $pending->data['requiresEmailVerification'] );
papelito_assert( 'conta pendente nao e mandada ao login', false, $pending->data['requiresLogin'] );
papelito_assert( 'retomada informa que a conta ja existe', true, $pending->data['accountExists'] );
papelito_assert( 'retomada NAO grava a senha enviada', 0, count( $GLOBALS['pap_password_updates'] ) );
papelito_assert( 'retomada NAO cria conta duplicada', 0, count( $GLOBALS['pap_inserted'] ) );
papelito_assert( 'retomada preserva o hash existente', '', $GLOBALS['pap_users'][20]->user_pass );

papelito_auth_mark_email_verified( 20 );
$verified = papelito_auth_handle_invitation_register(
	new WP_REST_Request(
		array( 'token' => 'token-valido', 'email' => 'convidado@test.com', 'password' => 'SenhaDoAtacante1', 'first_name' => 'Ana', 'last_name' => 'Silva', 'cpf' => '52998224725' )
	)
);
papelito_assert( 'conta verificada e mandada ao login', true, $verified->data['requiresLogin'] );
papelito_assert( 'conta verificada nao pede confirmacao de novo', false, $verified->data['requiresEmailVerification'] );
papelito_assert( 'conta verificada devolve os meios de entrada', array( 'password' ), $verified->data['authMethods'] );
papelito_assert( 'nenhuma senha foi sobrescrita em nenhum dos ramos', 0, count( $GLOBALS['pap_password_updates'] ) );

/* ---------- 3. e-mail novo continua criando conta com senha propria ---------- */
echo "Cenario 3: e-mail sem conta cria credencial de verdade\n";

$GLOBALS['pap_invitation'] = array( 'token' => 'token-novo', 'invited_email' => 'novo@test.com' );
$created = papelito_auth_handle_invitation_register(
	new WP_REST_Request(
		array( 'token' => 'token-novo', 'email' => 'novo@test.com', 'password' => 'SenhaEscolhida1', 'first_name' => 'Bruno', 'last_name' => 'Costa', 'cpf' => '52998224725' )
	)
);
papelito_assert( 'conta nova responde 201', 201, $created->status );
papelito_assert( 'conta nova nasce sem flag de existente', false, $created->data['accountExists'] );
papelito_assert( 'conta nova foi criada uma vez', 1, count( $GLOBALS['pap_inserted'] ) );
papelito_assert( 'conta nova guarda a senha escolhida pelo usuario', 'SenhaEscolhida1', $GLOBALS['pap_inserted'][0]['user_pass'] );
$new_id = $GLOBALS['pap_emails']['novo@test.com'];
papelito_assert( 'conta nova pode entrar por senha', array( 'password' ), papelito_auth_credential_methods( $new_id ) );
papelito_assert( 'conta nova nasce pendente de confirmacao', 'pending', $GLOBALS['pap_meta'][ $new_id ]['papelito_email_verification_status'] );

/* ---------- 3b. CPF ja usado por outra conta nao cria nada ---------- */
echo "Cenario 3b: CPF de outra pessoa e recusado antes de existir conta\n";

$before_inserts             = count( $GLOBALS['pap_inserted'] );
$GLOBALS['pap_cpf_owner']   = 4242;
$GLOBALS['pap_invitation']  = array( 'token' => 'token-dup', 'invited_email' => 'duplicado@test.com' );
$duplicated                 = papelito_auth_handle_invitation_register(
	new WP_REST_Request(
		array( 'token' => 'token-dup', 'email' => 'duplicado@test.com', 'password' => 'SenhaEscolhida1', 'first_name' => 'Duda', 'last_name' => 'Dias', 'cpf' => '52998224725' )
	)
);
papelito_assert( 'CPF de outra conta vira erro de dominio', true, is_wp_error( $duplicated ) );
papelito_assert( 'CPF de outra conta responde 409, nao 500', 'papelito_pii_cpf_in_use', $duplicated->get_error_code() );
papelito_assert( 'CPF de outra conta nao cria usuario', $before_inserts, count( $GLOBALS['pap_inserted'] ) );
papelito_assert( 'CPF de outra conta nao deixa e-mail registrado', false, isset( $GLOBALS['pap_emails']['duplicado@test.com'] ) );
$GLOBALS['pap_cpf_owner'] = null;

/* ---------- 4. convite autoriza o Google a criar conta ---------- */
echo "Cenario 4: Google cria conta apenas sob convite pendente do mesmo e-mail\n";

$GLOBALS['pap_invitation'] = array( 'token' => 'token-google', 'invited_email' => 'google@test.com' );
papelito_assert( 'convite valido autoriza o e-mail convidado', true, papelito_auth_invitation_authorizes_email( 'token-google', 'google@test.com' ) );
papelito_assert( 'convite nao autoriza outro e-mail', false, papelito_auth_invitation_authorizes_email( 'token-google', 'intruso@test.com' ) );
papelito_assert( 'token invalido nao autoriza', false, papelito_auth_invitation_authorizes_email( 'token-errado', 'google@test.com' ) );
papelito_assert( 'ausencia de token nao autoriza', false, papelito_auth_invitation_authorizes_email( '', 'google@test.com' ) );

$payload = array( 'email' => 'google@test.com', 'sub' => 'sub-google', 'given_name' => 'Carla', 'family_name' => 'Dias' );

$refused = papelito_auth_find_or_create_google_user( $payload, '' );
papelito_assert( 'sem convite o Google segue exigindo pre-conta', 'papelito_pre_account_required', $refused->get_error_code() );

$google_user = papelito_auth_find_or_create_google_user( $payload, 'token-google' );
papelito_assert( 'com convite o Google cria a conta', false, is_wp_error( $google_user ) );
$google_id = $google_user->ID;
papelito_assert( 'conta Google nasce verificada, pois o tokeninfo exige email_verified', 'verified', $GLOBALS['pap_meta'][ $google_id ]['papelito_email_verification_status'] );
papelito_assert( 'conta Google nasce sem senha propria', array( 'google' ), papelito_auth_credential_methods( $google_id ) );
papelito_assert( 'conta Google entra no coorte B2B', '1', $GLOBALS['pap_meta'][ $google_id ]['papelito_b2b_required'] );

echo "\n" . ( 0 === $failures ? "ALL PASS\n" : "{$failures} FAILURE(S)\n" );
exit( 0 === $failures ? 0 : 1 );
