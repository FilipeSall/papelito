<?php
/**
 * Standalone regression test: aprovação automática da pré-conta depois do e-mail confirmado.
 *
 * Com `PAPELITO_QSA_AUTO_APPROVE_ENABLED` ligada, a candidatura `qsa_review` cujo QSA ainda confirma
 * o titular é aprovada sem administrador; qualquer outra saída (flag desligada, caminho de documento,
 * provedor fora, QSA divergente, aprovação recusada) devolve null e a candidatura segue para a fila.
 * Cobre também a validação dos dados comerciais (`lead`) que o formulário do /revendedor envia.
 *
 * Usage: php tests/test-pre-account-auto-approval.php
 *
 * @package Papelito
 */

const AUTO_APPROVAL_TEST_APPLICATION_ID = 41;
const AUTO_APPROVAL_TEST_COMPANY_ID     = 77;
const AUTO_APPROVAL_TEST_INVALID_CODE   = 'papelito_pre_account_invalid_input';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

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
	public string $code;

	/** @var array<string,mixed> */
	public array $data;

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code = $code;
		$this->data = is_array( $data ) ? $data : array();
		unset( $message );
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

/**
 * Estado que os stubs leem e gravam, reiniciado a cada caso.
 */
final class AutoApprovalWorld {
	public static bool $flag = true;

	public static mixed $registry = null;

	public static mixed $approval = null;

	/** @var array<int,array<string,mixed>> */
	public static array $approve_calls = array();

	/** @var array<int,array<string,mixed>> */
	public static array $audits = array();

	/** @var array<int,array<string,mixed>> */
	public static array $emails = array();

	public static function reset(): void {
		self::$flag          = true;
		self::$registry      = array( 'review_required' => false );
		self::$approval      = array( 'status' => 'approved' );
		self::$approve_calls = array();
		self::$audits        = array();
		self::$emails        = array();
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function sanitize_key( string $value ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
}

function sanitize_text_field( string $value ): string {
	return trim( wp_strip_all_tags( $value ) );
}

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

function papelito_b2b_flag( string $name ): bool {
	return 'PAPELITO_QSA_AUTO_APPROVE_ENABLED' === $name && AutoApprovalWorld::$flag;
}

/**
 * @param array<string,mixed> $application Candidatura.
 * @return array<string,string>
 */
function papelito_pre_account_application_decrypt_values( array $application ): array {
	unset( $application );

	return array(
		'cpf'   => '12345678909',
		'birth' => '1985-03-12',
		'name'  => 'Joana Fixture de Almeida',
	);
}

function papelito_company_validate_owner_registry( string $cpf, string $birth, string $cnpj, string $name ): mixed {
	unset( $cpf, $birth, $cnpj, $name );

	return AutoApprovalWorld::$registry;
}

/**
 * @param array<string,mixed>      $application Candidatura.
 * @param array<string,mixed>|null $registry    Registro já validado.
 */
function papelito_pre_account_application_approve( array $application, int $actor_user_id, ?array $registry = null ): mixed {
	AutoApprovalWorld::$approve_calls[] = array(
		'id'       => (int) $application['id'],
		'actor'    => $actor_user_id,
		'registry' => $registry,
	);

	return AutoApprovalWorld::$approval;
}

/**
 * @return array<string,mixed>|null
 */
function papelito_pre_account_application_get( int $application_id ): ?array {
	return array(
		'id'                 => $application_id,
		'application_status' => 'approved',
		'created_company_id' => AUTO_APPROVAL_TEST_COMPANY_ID,
	);
}

/**
 * @param array<string,mixed> $payload Dados da auditoria.
 */
function papelito_company_audit( int $company_id, ?int $actor_user_id, string $action, array $payload = array() ): void {
	AutoApprovalWorld::$audits[] = compact( 'company_id', 'actor_user_id', 'action', 'payload' );
}

/**
 * @param array<string,mixed> $application Candidatura decidida.
 */
function papelito_pre_account_application_send_decision_email( array $application ): void {
	AutoApprovalWorld::$emails[] = $application;
}

/**
 * Carrega só as funções sob teste, sem o restante do módulo e suas dependências de banco.
 *
 * @param string            $file      Arquivo de `includes/`.
 * @param array<int,string> $functions Funções a isolar.
 */
function auto_approval_load( string $file, array $functions ): void {
	$source = (string) file_get_contents( __DIR__ . '/../includes/' . $file );
	foreach ( $functions as $function ) {
		if ( ! preg_match( '/function ' . $function . '\(.*?\n}/s', $source, $match ) ) {
			echo "FAIL: não foi possível isolar {$function}\n";
			exit( 1 );
		}
		eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

define( 'PAPELITO_PRE_ACCOUNT_DISCOVERY_CHANNELS', array( 'instagram', 'indicacao', 'evento', 'busca', 'outro' ) );
auto_approval_load( 'company_services.php', array( 'papelito_company_qsa_auto_approve_enabled' ) );
auto_approval_load( 'company_pre_account_applications.php', array( 'papelito_pre_account_application_email_is_verified', 'papelito_pre_account_application_try_auto_approve', 'papelito_pre_account_application_lead', 'papelito_pre_account_application_lead_view' ) );

/**
 * Candidatura com e-mail confirmado, no caminho de QSA.
 *
 * @param string $review_path Caminho de análise.
 * @return array<string,mixed>
 */
function auto_approval_application( string $review_path = 'qsa_review' ): array {
	return array(
		'id'                => AUTO_APPROVAL_TEST_APPLICATION_ID,
		'review_path'       => $review_path,
		'canonical_cnpj'    => '99999001000159',
		'email_verified_at' => '2026-10-28 12:00:00',
	);
}

echo "Cenário 1: flag ligada e QSA confirmando aprova sem administrador\n";
AutoApprovalWorld::reset();
$view = papelito_pre_account_application_try_auto_approve( auto_approval_application() );
papelito_assert( 'devolve a visão aprovada', array( 'status' => 'approved' ), $view );
papelito_assert( 'aprova com ator 0 (sistema)', 0, AutoApprovalWorld::$approve_calls[0]['actor'] ?? null );
papelito_assert( 'reaproveita o registro já consultado', array( 'review_required' => false ), AutoApprovalWorld::$approve_calls[0]['registry'] ?? null );
papelito_assert( 'audita a origem qsa_auto', 'qsa_auto', AutoApprovalWorld::$audits[0]['payload']['source'] ?? null );
papelito_assert( 'auditoria sem ator humano', true, array_key_exists( 'actor_user_id', AutoApprovalWorld::$audits[0] ?? array() ) && null === AutoApprovalWorld::$audits[0]['actor_user_id'] );
papelito_assert( 'auditoria na empresa criada', AUTO_APPROVAL_TEST_COMPANY_ID, AutoApprovalWorld::$audits[0]['company_id'] ?? null );
papelito_assert( 'envia o e-mail de decisão uma vez', 1, count( AutoApprovalWorld::$emails ) );

echo "Cenário 2: flag desligada mantém a fila manual\n";
AutoApprovalWorld::reset();
AutoApprovalWorld::$flag = false;
papelito_assert( 'devolve null', null, papelito_pre_account_application_try_auto_approve( auto_approval_application() ) );
papelito_assert( 'não aprova', 0, count( AutoApprovalWorld::$approve_calls ) );
papelito_assert( 'gate da flag desligado', false, papelito_company_qsa_auto_approve_enabled() );

echo "Cenário 3: caminho de documento nunca é aprovado sozinho\n";
AutoApprovalWorld::reset();
papelito_assert( 'document_review devolve null', null, papelito_pre_account_application_try_auto_approve( auto_approval_application( 'document_review' ) ) );
papelito_assert( 'não aprova', 0, count( AutoApprovalWorld::$approve_calls ) );

echo "Cenário 4: QSA que deixou de bater ou provedor fora caem na fila\n";
AutoApprovalWorld::reset();
AutoApprovalWorld::$registry = array( 'review_required' => true );
papelito_assert( 'QSA divergente devolve null', null, papelito_pre_account_application_try_auto_approve( auto_approval_application() ) );
AutoApprovalWorld::$registry = new WP_Error( 'papelito_company_lookup_unavailable' );
papelito_assert( 'provedor fora devolve null', null, papelito_pre_account_application_try_auto_approve( auto_approval_application() ) );
papelito_assert( 'nenhuma aprovação tentada', 0, count( AutoApprovalWorld::$approve_calls ) );

echo "Cenário 5: aprovação recusada (CNPJ ou e-mail já existe) não audita nem avisa\n";
AutoApprovalWorld::reset();
AutoApprovalWorld::$approval = new WP_Error( 'papelito_pre_account_unavailable' );
papelito_assert( 'devolve null', null, papelito_pre_account_application_try_auto_approve( auto_approval_application() ) );
papelito_assert( 'sem auditoria', 0, count( AutoApprovalWorld::$audits ) );
papelito_assert( 'sem e-mail', 0, count( AutoApprovalWorld::$emails ) );

echo "Cenário 6: sem confirmação de e-mail não tenta aprovar\n";
AutoApprovalWorld::reset();
$unverified                      = auto_approval_application();
$unverified['email_verified_at'] = null;
papelito_assert( 'sem e-mail confirmado devolve null', null, papelito_pre_account_application_try_auto_approve( $unverified ) );
papelito_assert( 'sem e-mail confirmado não chama aprovação', 0, count( AutoApprovalWorld::$approve_calls ) );

echo "Cenário 7: dados comerciais do /revendedor\n";
$lead = papelito_pre_account_application_lead(
	array(
		'lead' => array(
			'source'           => 'revendedor',
			'storeName'        => '  Tabacaria do Centro ',
			'instagram'        => '@tabacaria.centro',
			'discoveryChannel' => 'instagram',
			'hasSoldPapelito'  => 'sim',
		),
	)
);
papelito_assert(
	'lead válido é normalizado',
	array(
		'source'            => 'revendedor',
		'store_name'        => 'Tabacaria do Centro',
		'instagram'         => 'tabacaria.centro',
		'discovery_channel' => 'instagram',
		'has_sold_papelito' => 'sim',
	),
	$lead
);
papelito_assert(
	'sem lead vira origem cadastro com campos nulos',
	array(
		'source'            => 'cadastro',
		'store_name'        => null,
		'instagram'         => null,
		'discovery_channel' => null,
		'has_sold_papelito' => null,
	),
	papelito_pre_account_application_lead( array() )
);
papelito_assert( 'origem desconhecida vira cadastro', 'cadastro', papelito_pre_account_application_lead( array( 'lead' => array( 'source' => 'outro-site' ) ) )['source'] ?? null );
papelito_assert( 'nome da loja corta em 120', 120, mb_strlen( (string) papelito_pre_account_application_lead( array( 'lead' => array( 'storeName' => str_repeat( 'á', 200 ) ) ) )['store_name'], 'UTF-8' ) );

$invalid = papelito_pre_account_application_lead(
	array(
		'lead' => array(
			'instagram'        => 'loja com espaço!',
			'discoveryChannel' => 'tv',
			'hasSoldPapelito'  => 'talvez',
		),
	)
);
papelito_assert( 'lead inválido é recusado', AUTO_APPROVAL_TEST_INVALID_CODE, is_wp_error( $invalid ) ? $invalid->get_error_code() : null );
papelito_assert( 'recusa é 422', 422, is_wp_error( $invalid ) ? ( $invalid->get_error_data()['status'] ?? null ) : null );
papelito_assert( 'erros por campo', array( 'instagram', 'discovery_channel', 'has_sold_papelito' ), is_wp_error( $invalid ) ? array_keys( $invalid->get_error_data()['errors'] ?? array() ) : null );
papelito_assert( 'instagram com 31 caracteres é recusado', true, is_wp_error( papelito_pre_account_application_lead( array( 'lead' => array( 'instagram' => str_repeat( 'a', 31 ) ) ) ) ) );

echo "Cenário 7: o painel lê o lead gravado\n";
papelito_assert(
	'lead_json vira o formato do painel',
	array(
		'source'           => 'revendedor',
		'storeName'        => 'Tabacaria do Centro',
		'instagram'        => 'tabacaria.centro',
		'discoveryChannel' => 'instagram',
		'hasSoldPapelito'  => 'sim',
	),
	papelito_pre_account_application_lead_view( array( 'lead_json' => json_encode( $lead ) ) )
);
papelito_assert( 'candidatura anterior à coluna devolve null', null, papelito_pre_account_application_lead_view( array( 'lead_json' => null ) ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
