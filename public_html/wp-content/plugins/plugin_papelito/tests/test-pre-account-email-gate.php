<?php
/**
 * Standalone regression test: a candidatura so chega ao administrador depois do e-mail confirmado.
 *
 * O fluxo antigo mandava a candidatura para a fila no mesmo instante da submissao, sem ninguem ter
 * provado posse da caixa. Agora ela nasce em `pending_email_verification`, nao notifica, e e o
 * clique no link que decide o destino: `document_required` quando o QSA pediu documento,
 * `pending_manual_review` caso contrario — e so ai o administrador e avisado.
 *
 * As assercoes cobrem o gate do upload, a expiracao do token, o intervalo de reenvio e a retomada
 * por credenciais, que precisa responder a mesma coisa para senha errada e candidatura inexistente.
 *
 * Usage: php tests/test-pre-account-email-gate.php
 *
 * @package Papelito
 */

const EMAIL_GATE_TEST_EMAIL          = 'candidata@empresa.com.br';
const EMAIL_GATE_TEST_PASSWORD       = 'senha-forte-da-candidatura';
const EMAIL_GATE_TEST_WRONG_PASSWORD = 'senha-que-nao-e-a-dela';
const EMAIL_GATE_TEST_TOKEN          = 'token-de-confirmacao-em-claro';
const EMAIL_GATE_TEST_CNPJ           = '19131243000197';
const EMAIL_GATE_TEST_NOW            = '2026-09-21 12:00:00';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING', 'company_owner_review_pending' );

// O WordPress mantem o fuso do PHP em UTC, e o intervalo de reenvio compara um datetime gravado em
// UTC com `time()`. Fora do WordPress o fuso do sistema faria o caso passar ou falhar pela hora do
// relogio, entao o teste reproduz o ambiente real em vez de depender dele.
date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

$failures = 0;

/**
 * Compara valor esperado e obtido, contabilizando a falha no total do arquivo.
 *
 * @param string $label    Nome do caso.
 * @param mixed  $expected Valor esperado.
 * @param mixed  $actual   Valor obtido.
 */
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";

		return;
	}

	++$failures;
	echo '  FAIL: ' . $label . ' — esperado ' . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

class WP_Error {
	/** @var array<int,string> */
	public array $codes = array();

	/** @var array<string,mixed> */
	public array $data = array();

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->codes   = array( $code );
		$this->message = $message;
		$this->data    = is_array( $data ) ? $data : array();
	}

	public string $message = '';

	public function get_error_code(): string {
		return $this->codes[0] ?? '';
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

/**
 * Banco em memoria com o suficiente para o ciclo de vida da candidatura.
 *
 * Guarda uma linha so: os casos deste arquivo nunca precisam de duas candidaturas ao mesmo tempo.
 */
class PapelitoFakeWpdb {
	/** @var array<string,mixed> */
	public array $row = array();

	/** @var array<int,string> */
	public array $queries = array();

	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
			$sql         = preg_replace( '/%[sd]/', $replacement, $sql, 1 ) ?? $sql;
		}

		return $sql;
	}

	public function query( string $sql ): int {
		$this->queries[] = $sql;

		return 1;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, mixed $output = null ): ?array {
		foreach ( array( 'email_verification_token_hash', 'resume_token_hash', 'contact_email_hmac' ) as $column ) {
			if ( ! str_contains( $sql, $column . ' = ' ) ) {
				continue;
			}

			return str_contains( $sql, "'" . (string) ( $this->row[ $column ] ?? '' ) . "'" ) ? $this->row : null;
		}

		return str_contains( $sql, 'id = ' . (int) ( $this->row['id'] ?? 0 ) ) ? $this->row : null;
	}

	/**
	 * @param array<string,mixed> $data  Colunas a gravar.
	 * @param array<string,mixed> $where Condicao da atualizacao.
	 */
	public function update( string $table, array $data, array $where ): int|false {
		foreach ( $where as $column => $value ) {
			if ( ( $this->row[ $column ] ?? null ) != $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				return 0;
			}
		}

		$this->row = array_merge( $this->row, $data );

		return 1;
	}
}

$wpdb                                      = new PapelitoFakeWpdb();
$GLOBALS['papelito_notified_applications'] = array();
$GLOBALS['papelito_sent_emails']           = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_email( mixed $value ): string { return trim( (string) $value ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return strtolower( (string) $value ); }
function wp_json_encode( mixed $value ): string { return (string) json_encode( $value ); }
function is_email( mixed $value ): bool { return str_contains( (string) $value, '@' ); }
function add_action( mixed ...$args ): bool { return true; }
function add_filter( mixed ...$args ): bool { return true; }
function do_action( mixed ...$args ): bool { return true; }
function wp_next_scheduled( mixed ...$args ): bool { return true; }
function wp_schedule_event( mixed ...$args ): bool { return true; }
function get_option( string $name, mixed $default_value = false ): mixed { return $default_value; }
function update_option( string $name, mixed $value, mixed $autoload = null ): bool { return true; }
function current_time( string $type, bool $gmt = false ): string { return EMAIL_GATE_TEST_NOW; }
function wp_check_password( string $password, string $hash ): bool { return hash( 'sha256', $password ) === $hash; }
function wp_hash_password( string $password ): string { return hash( 'sha256', $password ); }
function papelito_pii_hmac( string $value ): string { return hash( 'sha256', 'hmac:' . $value ); }
function papelito_pii_encrypt( string $value ): string { return 'enc:' . $value; }
function papelito_pii_decrypt( string $value ): string { return str_starts_with( $value, 'enc:' ) ? substr( $value, 4 ) : ''; }
function papelito_normalize_email( string $value ): string { return strtolower( trim( $value ) ); }
function papelito_frontend_link( string $path ): string { return 'https://marketplace.papelito.com/' . $path; }
function papelito_email_notice_html( array $view ): string { return (string) ( $view['headline'] ?? '' ); }
function papelito_email_notice_text( array $view ): string { return (string) ( $view['headline'] ?? '' ); }

function papelito_email_send( string $recipient, string $subject, string $html, string $text, array $attachments = array() ): bool {
	$GLOBALS['papelito_sent_emails'][] = array( 'to' => $recipient, 'subject' => $subject );

	return true;
}

function papelito_dispatch_notification( int $user_id, string $type, array $payload, string $dedupe_key ): bool {
	$GLOBALS['papelito_notified_applications'][] = $dedupe_key;

	return true;
}

function papelito_notifications_table_name(): string { return 'wp_papelito_notifications'; }
function get_users( array $args = array() ): array { return array( 7 ); }
function user_can( int $user_id, string $capability ): bool { return true; }

/**
 * @return array<string,string>
 */
function papelito_company_table_names(): array {
	return array( 'pre_account_applications' => 'wp_papelito_company_pre_account_applications' );
}

require_once dirname( __DIR__ ) . '/includes/company_pre_account_applications.php';

/**
 * Monta uma candidatura persistida no estado pedido.
 *
 * @param string $review_path       Caminho de revisao gravado na submissao.
 * @param string $status            Estado da candidatura.
 * @param bool   $email_verified    Se a posse da caixa ja foi comprovada.
 * @return array<string,mixed>
 */
function papelito_email_gate_fixture( string $review_path, string $status, bool $email_verified = false ): array {
	return array(
		'id'                                  => 31,
		'contact_email_hmac'                  => papelito_pii_hmac( EMAIL_GATE_TEST_EMAIL ),
		'contact_email_ciphertext'            => 'enc:' . EMAIL_GATE_TEST_EMAIL,
		'full_name_ciphertext'                => 'enc:Ana Paula Ribeiro',
		'legal_name_ciphertext'               => 'enc:Empresa Exemplo LTDA',
		'password_hash'                       => wp_hash_password( EMAIL_GATE_TEST_PASSWORD ),
		'canonical_cnpj'                      => EMAIL_GATE_TEST_CNPJ,
		'review_path'                         => $review_path,
		'application_status'                  => $status,
		'is_open'                             => 1,
		'resume_token_hash'                   => papelito_pre_account_application_token_hash( 'resume-antigo' ),
		'resume_token_expires_at'             => '2026-12-31 00:00:00',
		'email_verification_token_hash'       => papelito_pre_account_application_token_hash( EMAIL_GATE_TEST_TOKEN ),
		'email_verification_token_expires_at' => '2026-09-22 12:00:00',
		'email_verification_sent_at'          => null,
		'email_verified_at'                   => $email_verified ? EMAIL_GATE_TEST_NOW : null,
		'document_storage_key'                => null,
		'expires_at'                          => '2026-12-31 00:00:00',
		'created_at'                          => '2026-09-20 10:00:00',
	);
}

echo "\n--- confirmacao decide o destino e notifica so entao ---\n";

$wpdb->row                                 = papelito_email_gate_fixture( 'qsa_review', PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL );
$GLOBALS['papelito_notified_applications'] = array();
$confirmed                                 = papelito_pre_account_application_confirm_email( EMAIL_GATE_TEST_TOKEN );

papelito_assert( 'qsa suficiente vai direto para analise', 'pending_manual_review', $wpdb->row['application_status'] );
papelito_assert( 'a confirmacao carimba a posse da caixa', EMAIL_GATE_TEST_NOW, $wpdb->row['email_verified_at'] );
papelito_assert( 'o token de confirmacao e queimado no uso', null, $wpdb->row['email_verification_token_hash'] );
papelito_assert( 'administrador notificado uma unica vez', array( 'pre-account-application:31' ), $GLOBALS['papelito_notified_applications'] );
papelito_assert( 'a visao publica ja informa a posse comprovada', true, is_array( $confirmed ) && true === $confirmed['application']['emailVerified'] );
papelito_assert( 'a confirmacao devolve um resume_token para quem clicou de outro aparelho', $wpdb->row['resume_token_hash'], is_array( $confirmed ) ? papelito_pre_account_application_token_hash( $confirmed['resume_token'] ) : '' );
papelito_assert( 'o resume_token antigo deixa de valer', true, papelito_pre_account_application_token_hash( 'resume-antigo' ) !== $wpdb->row['resume_token_hash'] );

$wpdb->row                                 = papelito_email_gate_fixture( 'document_required', PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL );
$GLOBALS['papelito_notified_applications'] = array();
papelito_pre_account_application_confirm_email( EMAIL_GATE_TEST_TOKEN );

papelito_assert( 'qsa insuficiente cai na etapa de documento', 'document_required', $wpdb->row['application_status'] );
papelito_assert( 'etapa de documento ainda nao avisa o administrador', array(), $GLOBALS['papelito_notified_applications'] );

echo "\n--- o link so vale uma vez e nao vale para sempre ---\n";

$wpdb->row = papelito_email_gate_fixture( 'qsa_review', PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL );
$wpdb->row['email_verification_token_expires_at'] = '2026-09-20 12:00:00';
$expired = papelito_pre_account_application_confirm_email( EMAIL_GATE_TEST_TOKEN );

papelito_assert( 'link vencido devolve 410', 'papelito_pre_account_email_token_expired', is_wp_error( $expired ) ? $expired->get_error_code() : '' );
papelito_assert( 'link vencido nao move a candidatura', PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL, $wpdb->row['application_status'] );

$wpdb->row = papelito_email_gate_fixture( 'qsa_review', PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL );
$unknown   = papelito_pre_account_application_confirm_email( 'token-que-nunca-existiu' );
papelito_assert( 'token desconhecido nao vaza a existencia da candidatura', 'papelito_pre_account_application_not_found', is_wp_error( $unknown ) ? $unknown->get_error_code() : '' );

echo "\n--- upload exige posse comprovada ---\n";

$blocked = papelito_pre_account_application_upload_authorized( papelito_email_gate_fixture( 'document_required', 'document_required' ), array( 'name' => 'contrato.pdf' ) );
papelito_assert( 'sem confirmar o e-mail o upload e recusado', 'papelito_pre_account_email_unconfirmed', is_wp_error( $blocked ) ? $blocked->get_error_code() : '' );
papelito_assert( 'a recusa do upload e 409', 409, is_wp_error( $blocked ) ? ( $blocked->get_error_data()['status'] ?? 0 ) : 0 );

$view_locked = papelito_pre_account_application_view( papelito_email_gate_fixture( 'document_required', 'document_required' ) );
papelito_assert( 'a visao publica nao promete upload antes da confirmacao', false, $view_locked['canUpload'] );

$view_open = papelito_pre_account_application_view( papelito_email_gate_fixture( 'document_required', 'document_required', true ) );
papelito_assert( 'confirmada, a visao publica libera o upload', true, $view_open['canUpload'] );

echo "\n--- reenvio respeita o intervalo de um minuto ---\n";

$recent                               = papelito_email_gate_fixture( 'qsa_review', PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL );
$recent['email_verification_sent_at'] = gmdate( 'Y-m-d H:i:s' );
$wpdb->row                            = $recent;
$GLOBALS['papelito_sent_emails']      = array();
papelito_pre_account_application_resend_email_verification( $recent );
papelito_assert( 'reenvio dentro do intervalo nao dispara e-mail', array(), $GLOBALS['papelito_sent_emails'] );

$stale                               = papelito_email_gate_fixture( 'qsa_review', PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL );
$stale['email_verification_sent_at'] = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$wpdb->row                           = $stale;
$GLOBALS['papelito_sent_emails']     = array();
papelito_pre_account_application_resend_email_verification( $stale );
papelito_assert( 'passado o intervalo o link e reenviado', 1, count( $GLOBALS['papelito_sent_emails'] ) );
papelito_assert( 'o reenvio vai para o e-mail da candidatura', EMAIL_GATE_TEST_EMAIL, $GLOBALS['papelito_sent_emails'][0]['to'] ?? '' );

$verified                        = papelito_email_gate_fixture( 'qsa_review', 'pending_manual_review', true );
$wpdb->row                       = $verified;
$GLOBALS['papelito_sent_emails'] = array();
papelito_pre_account_application_resend_email_verification( $verified );
papelito_assert( 'candidatura ja confirmada nao recebe novo link', array(), $GLOBALS['papelito_sent_emails'] );

echo "\n--- retomada pelo login nao abre enumeracao ---\n";

$wpdb->row = papelito_email_gate_fixture( 'document_required', 'document_required', true );
$resumed   = papelito_pre_account_application_resume_by_credentials( EMAIL_GATE_TEST_EMAIL, EMAIL_GATE_TEST_PASSWORD );
papelito_assert( 'senha certa devolve um resume_token novo', true, is_array( $resumed ) && '' !== (string) $resumed['resume_token'] );
papelito_assert( 'o token devolvido e o que ficou gravado', $wpdb->row['resume_token_hash'], is_array( $resumed ) ? papelito_pre_account_application_token_hash( $resumed['resume_token'] ) : '' );
papelito_assert( 'a retomada aponta a etapa em que a candidatura parou', 'document_required', is_array( $resumed ) ? $resumed['application']['status'] : '' );

$wpdb->row = papelito_email_gate_fixture( 'document_required', 'document_required', true );
$wrong     = papelito_pre_account_application_resume_by_credentials( EMAIL_GATE_TEST_EMAIL, EMAIL_GATE_TEST_WRONG_PASSWORD );
$missing   = papelito_pre_account_application_resume_by_credentials( 'ninguem@exemplo.com', EMAIL_GATE_TEST_PASSWORD );

papelito_assert( 'senha errada e candidatura inexistente sao indistinguiveis', true, is_wp_error( $wrong ) && is_wp_error( $missing ) && $wrong->get_error_code() === $missing->get_error_code() );
papelito_assert( 'senha errada nao rotaciona o resume_token', papelito_pre_account_application_token_hash( 'resume-antigo' ), $wpdb->row['resume_token_hash'] );

echo "\n--- rate limit dos endpoints publicos e por identidade, nao por IP ---\n";

/* Estrutural de proposito: sem o quarto argumento a chave cai em `ip:REMOTE_ADDR`, que pelo proxy
Next e o mesmo endereco para o marketplace inteiro — um punhado de tentativas de qualquer pessoa
fecharia confirmacao, reenvio e retomada para todas as outras. */
$endpoints = (string) file_get_contents( dirname( __DIR__ ) . '/includes/company_endpoints.php' );

foreach ( array( 'pre_account_verify_email', 'pre_account_resend_verification', 'pre_account_resume' ) as $bucket ) {
	$matched = array();
	preg_match( "/papelito_auth_rate_limit\\( '" . $bucket . "', \\d+, \\d+([^)]*)\\)/", $endpoints, $matched );
	papelito_assert( "balde {$bucket} recebe identidade", true, '' !== trim( (string) ( $matched[1] ?? '' ) ) );
}

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAIL\n";
exit( 0 === $failures ? 0 : 1 );
