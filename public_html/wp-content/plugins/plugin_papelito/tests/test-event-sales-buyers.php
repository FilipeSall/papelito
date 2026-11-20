<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public string $code;
	public array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

const EVENT_BUYER_TEST_CNPJ  = '11222333000181';
const EVENT_BUYER_TEST_EMAIL = 'loja@exemplo.com.br';
const EVENT_BUYER_TEST_PHONE = '(11) 98765-4321';
const EVENT_BUYER_TEST_NAME  = 'Maria da Silva';

$GLOBALS['buyer_lookup'] = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function is_email( mixed $value ): bool { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function papelito_normalize_cnpj( string $raw ): string { return preg_replace( '/\D+/', '', $raw ); }
function papelito_validate_cnpj( string $raw ): bool { return EVENT_BUYER_TEST_CNPJ === papelito_normalize_cnpj( $raw ); }
function papelito_normalize_email( string $email ): string { return strtolower( trim( $email ) ); }
function papelito_auth_normalize_phone( string $phone ): string { return preg_replace( '/\D+/', '', $phone ); }
function papelito_cnpj_lookup( string $raw_cnpj, bool $include_evidence = false ): array { return $GLOBALS['buyer_lookup']; }
function papelito_company_find_by_cnpj( string $raw_cnpj ): ?array { return $GLOBALS['buyer_existing'] ?? null; }
function sanitize_user( string $username, bool $strict = false ): string { return (string) preg_replace( '/[^a-z0-9 _.\-@]/i', '', $username ); }
function username_exists( string $username ): int|false { return in_array( $username, $GLOBALS['buyer_logins'] ?? array(), true ) ? 1 : false; }
function get_user_by( string $field, mixed $value ): Event_Buyer_Test_User|false { return $GLOBALS['buyer_account'] ?? false; }

const PAPELITO_EVENT_SALES_NAME = 'ExpoCannabis Brasil 2026';

/**
 * Conta mínima com os campos que o e-mail de boas-vindas lê.
 */
class Event_Buyer_Test_User {
	public int $ID           = 41;
	public string $user_login = EVENT_BUYER_TEST_EMAIL;
	public string $user_email = EVENT_BUYER_TEST_EMAIL;
	public array $roles       = array( 'customer' );
}
class_alias( Event_Buyer_Test_User::class, 'WP_User' );

$GLOBALS['buyer_mail']     = array();
$GLOBALS['buyer_reset_ok'] = true;

function get_password_reset_key( WP_User $user ): string|WP_Error { return $GLOBALS['buyer_reset_ok'] ? 'chave-' . $user->ID : new WP_Error( 'no_password_reset' ); }
function papelito_auth_build_password_reset_link( string $login, string $key ): string { return 'https://papelito.test/redefinir-senha?login=' . rawurlencode( $login ) . '&key=' . $key; }
function get_user_meta( int $user_id, string $key, bool $single = false ): string { return 'first_name' === $key ? 'Maria' : ''; }
function sanitize_email( mixed $value ): string { return strtolower( trim( (string) $value ) ); }
function papelito_email_notice_html( array $view ): string { return (string) wp_json_encode_test( $view ); }
function papelito_email_notice_text( array $view ): string { return (string) $view['lead']; }
function papelito_email_send( string $to, string $subject, string $html, string $text ): bool { $GLOBALS['buyer_mail'][] = compact( 'to', 'subject', 'html', 'text' ); return true; }
function wp_json_encode_test( mixed $value ): string|false { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }

require_once dirname( __DIR__ ) . '/includes/event_sales_buyers.php';

$failures = 0;

function event_buyer_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

function event_buyer_error( mixed $result, string $code, int $status ): bool {
	return $result instanceof WP_Error && $code === $result->get_error_code() && $status === ( $result->get_error_data()['status'] ?? 0 );
}

function test_lookup_maps_statuses(): void {
	$cases = array(
		'inactive'             => array( 'papelito_event_cnpj_inactive', 422 ),
		'not_found'            => array( 'papelito_event_cnpj_not_found', 404 ),
		'unavailable'          => array( 'papelito_event_cnpj_unavailable', 503 ),
		'provider_unsupported' => array( 'papelito_event_cnpj_unavailable', 503 ),
		'invalid'              => array( 'papelito_event_cnpj_invalid', 400 ),
	);

	foreach ( $cases as $status => $expected ) {
		$GLOBALS['buyer_lookup'] = array( 'status' => $status );
		event_buyer_assert( "status {$status} vira {$expected[0]}", event_buyer_error( papelito_event_sales_lookup_buyer( EVENT_BUYER_TEST_CNPJ ), $expected[0], $expected[1] ) );
	}

	event_buyer_assert( 'dígito errado nem consulta', event_buyer_error( papelito_event_sales_lookup_buyer( '11222333000180' ), 'papelito_event_cnpj_invalid', 400 ) );

	$GLOBALS['buyer_lookup'] = array(
		'status'         => 'active',
		'legal_name'     => 'Loja Exemplo LTDA',
		'trade_name'     => null,
		'fiscal_address' => array( 'cep' => '01001000', 'state' => 'SP', 'city' => 'São Paulo', 'street' => 'Praça da Sé' ),
	);
	$buyer                   = papelito_event_sales_lookup_buyer( '11.222.333/0001-81' );
	event_buyer_assert( 'CNPJ ativo devolve o CNPJ em dígitos', EVENT_BUYER_TEST_CNPJ === $buyer['cnpj'] );
	event_buyer_assert( 'sem nome fantasia usa a razão social', 'Loja Exemplo LTDA' === $buyer['tradeName'] );
	event_buyer_assert( 'endereço vem sem número e bairro', '' === $buyer['address']['number'] && '' === $buyer['address']['neighborhood'] );
	event_buyer_assert( 'cidade vem da Receita', 'São Paulo' === $buyer['address']['city'] );
	event_buyer_assert( 'empresa nova não tem id', null === $buyer['companyId'] );
}

function test_upsert_validates_input(): void {
	$valid = array(
		'cnpj'  => EVENT_BUYER_TEST_CNPJ,
		'name'  => EVENT_BUYER_TEST_NAME,
		'phone' => EVENT_BUYER_TEST_PHONE,
		'email' => EVENT_BUYER_TEST_EMAIL,
	);

	$normalized = papelito_event_sales_validate_buyer_input( $valid );
	event_buyer_assert( 'entrada válida normaliza o telefone', '11987654321' === $normalized['phone'] );

	event_buyer_assert( 'CNPJ inválido', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'cnpj' => '123' ) ) ), 'papelito_event_cnpj_invalid', 400 ) );
	event_buyer_assert( 'nome curto', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'name' => 'M' ) ) ), 'papelito_event_buyer_name', 400 ) );
	event_buyer_assert( 'WhatsApp com 9 dígitos', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'phone' => '987654321' ) ) ), 'papelito_event_buyer_phone', 400 ) );
	event_buyer_assert( 'e-mail inválido', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'email' => 'loja@' ) ) ), 'papelito_event_buyer_email', 400 ) );
	event_buyer_assert( 'e-mail acima de 100 caracteres', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'email' => str_repeat( 'a', 90 ) . '@exemplo.com.br' ) ) ), 'papelito_event_buyer_email', 400 ) );
	event_buyer_assert( 'telefone só de zeros', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'phone' => '0000000000' ) ) ), 'papelito_event_buyer_phone', 400 ) );
	event_buyer_assert( 'fixo com DDD continua aceito', is_array( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'phone' => '(61) 3322-4455' ) ) ) ) );
	event_buyer_assert( 'nome separa primeiro e último', array( 'Maria', 'da Silva' ) === papelito_event_sales_split_name( EVENT_BUYER_TEST_NAME ) );
}

function test_existing_company_still_needs_active_cnpj(): void {
	$GLOBALS['buyer_existing'] = array( 'id' => 30, 'cnpj' => EVENT_BUYER_TEST_CNPJ );
	$GLOBALS['buyer_lookup']   = array( 'status' => 'inactive' );
	$buyer                     = array( 'cnpj' => EVENT_BUYER_TEST_CNPJ, 'name' => EVENT_BUYER_TEST_NAME, 'phone' => '11987654321', 'email' => EVENT_BUYER_TEST_EMAIL );
	event_buyer_assert( 'empresa já cadastrada com CNPJ inativo é recusada', event_buyer_error( papelito_event_sales_find_or_create_company( 7, $buyer ), 'papelito_event_cnpj_inactive', 422 ) );

	$GLOBALS['buyer_lookup'] = array( 'status' => 'active', 'legal_name' => 'Loja Exemplo LTDA' );
	$found                   = papelito_event_sales_find_or_create_company( 7, $buyer );
	event_buyer_assert( 'empresa já cadastrada com CNPJ ativo é reaproveitada', is_array( $found ) && 30 === $found['company_id'] && false === $found['created'] );
	$GLOBALS['buyer_existing'] = null;
}

function test_welcome_email_speaks_of_the_event(): void {
	$GLOBALS['buyer_mail']     = array();
	$GLOBALS['buyer_reset_ok'] = true;
	$sent = papelito_event_sales_send_welcome_email( new WP_User() );
	$mail = $GLOBALS['buyer_mail'][0] ?? array();
	event_buyer_assert( 'e-mail de boas-vindas é enviado', true === $sent && 1 === count( $GLOBALS['buyer_mail'] ) );
	event_buyer_assert( 'vai para o e-mail digitado no estande', EVENT_BUYER_TEST_EMAIL === ( $mail['to'] ?? '' ) );
	event_buyer_assert( 'assunto fala de conta criada, não de redefinir senha', 'Sua conta Papelito foi criada - crie sua senha' === ( $mail['subject'] ?? '' ) );
	event_buyer_assert( 'texto cita o evento e chama pelo nome', str_contains( $mail['text'] ?? '', 'Olá Maria, criamos sua conta no estande da Papelito na ExpoCannabis Brasil 2026' ) );
	event_buyer_assert( 'botão leva ao link de criar senha', str_contains( $mail['html'] ?? '', 'https://papelito.test/redefinir-senha?login=loja%40exemplo.com.br&key=chave-41' ) );
	event_buyer_assert( 'botão diz criar senha', str_contains( $mail['html'] ?? '', 'Criar minha senha' ) );
	event_buyer_assert( 'texto não fala em redefinir', ! str_contains( strtolower( ( $mail['html'] ?? '' ) . ( $mail['text'] ?? '' ) ), 'redefin' . 'ir a senha' ) );
}

function test_welcome_email_without_key_is_not_sent(): void {
	$GLOBALS['buyer_mail']     = array();
	$GLOBALS['buyer_reset_ok'] = false;
	event_buyer_assert( 'sem chave de senha nada é enviado', false === papelito_event_sales_send_welcome_email( new WP_User() ) && array() === $GLOBALS['buyer_mail'] );
	$GLOBALS['buyer_reset_ok'] = true;
}

function test_welcome_email_without_name_still_greets(): void {
	event_buyer_assert( 'sem nome a saudação continua correta', str_starts_with( papelito_event_sales_welcome_email_view( '', 'https://x' )['lead'], 'Olá, criamos sua conta' ) );
}

function test_only_customer_accounts_become_buyers(): void {
	$buyer   = array( 'cnpj' => EVENT_BUYER_TEST_CNPJ, 'name' => EVENT_BUYER_TEST_NAME, 'phone' => '11987654321', 'email' => EVENT_BUYER_TEST_EMAIL );
	$account = new Event_Buyer_Test_User();

	foreach ( array( 'administrator', 'seller', 'papelito_event_operator' ) as $role ) {
		$account->roles            = array( $role );
		$GLOBALS['buyer_account'] = $account;
		event_buyer_assert( "conta {$role} não vira comprador", event_buyer_error( papelito_event_sales_find_or_create_user( $buyer ), 'papelito_event_buyer_email_staff', 409 ) );
	}

	$account->roles            = array( 'customer' );
	$GLOBALS['buyer_account'] = $account;
	$found                     = papelito_event_sales_find_or_create_user( $buyer );
	event_buyer_assert( 'conta de cliente é reaproveitada', is_array( $found ) && 41 === $found['user_id'] && false === $found['created'] );
	$GLOBALS['buyer_account'] = false;
}

function test_login_fits_wordpress_limits(): void {
	$GLOBALS['buyer_logins'] = array();
	event_buyer_assert( 'e-mail comum vira o login', EVENT_BUYER_TEST_EMAIL === papelito_event_sales_user_login( EVENT_BUYER_TEST_EMAIL ) );

	$plus = papelito_event_sales_user_login( 'loja+estande@exemplo.com.br' );
	event_buyer_assert( 'e-mail com + ganha login próprio', str_starts_with( $plus, 'lojista-' ) );

	$long = papelito_event_sales_user_login( str_repeat( 'a', 55 ) . '@exemplo.com.br' );
	event_buyer_assert( 'e-mail longo cabe nos 60 caracteres', str_starts_with( $long, 'lojista-' ) && strlen( $long ) <= 60 );

	$GLOBALS['buyer_logins'] = array( EVENT_BUYER_TEST_EMAIL, $plus );
	event_buyer_assert( 'login já usado ganha outro', ! in_array( papelito_event_sales_user_login( EVENT_BUYER_TEST_EMAIL ), $GLOBALS['buyer_logins'], true ) );
	event_buyer_assert( 'login gerado repetido ganha sufixo', $plus . '-2' === papelito_event_sales_user_login( 'loja+estande@exemplo.com.br' ) );
	$GLOBALS['buyer_logins'] = array();
}

test_lookup_maps_statuses();
test_only_customer_accounts_become_buyers();
test_login_fits_wordpress_limits();
test_welcome_email_speaks_of_the_event();
test_welcome_email_without_key_is_not_sent();
test_welcome_email_without_name_still_greets();
test_upsert_validates_input();
test_existing_company_still_needs_active_cnpj();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
