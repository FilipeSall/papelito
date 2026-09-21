<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Avaliador central de elegibilidade do vendor.
 *
 * Recebedor Pagar.me saudável, conta ativa e o mínimo configurado de caixas decidem se a loja
 * vende. O mínimo nasce em 2 e o recomendado em 3; ficar entre os dois é recomendação, nunca
 * bloqueio.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES', 24 );

const ELIG_TEST_VENDOR_ID         = 7410;
const ELIG_TEST_RECIPIENT_ID      = 're_test_papelito';
const ELIG_TEST_SYNC_AT           = '2026-09-21 09:00:00';
const ELIG_TEST_PAGARME_RAW_ERROR = 'bank_account.holder_document: documento invalido';

/**
 * Erro sintético do WordPress.
 */
class WP_Error {
	/**
	 * Guarda código, mensagem e dados do erro.
	 *
	 * @param string              $code Código estável.
	 * @param string              $message Mensagem legível.
	 * @param array<string,mixed> $data Dados do erro.
	 */
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}

	/** Devolve o código do erro. */
	public function get_error_code(): string {
		return $this->code;
	}

	/** Devolve a mensagem do erro. */
	public function get_error_message(): string {
		return $this->message;
	}

	/** Devolve os dados do erro. */
	public function get_error_data(): array {
		return $this->data;
	}
}

/** Identifica um erro sintético do WordPress. */
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

/** Sanitiza uma chave sintética. */
function sanitize_key( mixed $value ): string {
	return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}

/** Sanitiza um texto sintético. */
function sanitize_text_field( mixed $value ): string {
	return trim( (string) $value );
}

/** Stub de registro de action não relacionada ao seam testado. */
function add_action( mixed ...$args ): bool {
	return true;
}

/** Stub de registro de rota não relacionada ao seam testado. */
function register_rest_route( mixed ...$args ): bool {
	return true;
}

/** Lê a option sintética da elegibilidade. */
function get_option( mixed $name, mixed $default_value = false ): mixed {
	return $GLOBALS['elig_test_options'][ (string) $name ] ?? $default_value;
}

/** Grava a option sintética da elegibilidade. */
function update_option( mixed $name, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['elig_test_options'][ (string) $name ] = $value;

	return true;
}

/** Conta as caixas ativas do cenário corrente. */
function papelito_packaging_active_profile_count( int $vendor_id ): int {
	return (int) ( $GLOBALS['elig_test_boxes'] ?? 0 );
}

/** Liga ou desliga o gate de embalagem no cenário corrente. */
function papelito_packaging_profile_gate_enabled(): bool {
	return (bool) ( $GLOBALS['elig_test_gate'] ?? true );
}

/** Suspende a conta apenas quando o cenário pedir. */
function papelito_account_is_suspended( int $user_id ): bool {
	return (bool) ( $GLOBALS['elig_test_suspended'] ?? false );
}

/** Devolve o estado sintético do recebedor Pagar.me. */
function papelito_pagarme_get_vendor_recipient_state( int $user_id ): array {
	return $GLOBALS['elig_test_recipient'];
}

/** Reproduz a regra oficial de KYC pendente da Pagar.me. */
function papelito_pagarme_kyc_action_required( string $recipient_status, string $kyc_status, string $kyc_status_reason ): bool {
	return 'affiliation' === $recipient_status
		&& 'partially_denied' === $kyc_status
		&& 'additional_documents_required' === $kyc_status_reason;
}

$GLOBALS['elig_test_options']   = array();
$GLOBALS['elig_test_boxes']     = 0;
$GLOBALS['elig_test_gate']      = true;
$GLOBALS['elig_test_suspended'] = false;

require_once dirname( __DIR__ ) . '/includes/vendor_eligibility.php';

$failures = 0;

/** Confere um comportamento do avaliador de elegibilidade. */
function elig_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Monta um estado de recebedor sintético. */
function elig_recipient( string $status, string $recipient_id = ELIG_TEST_RECIPIENT_ID, string $last_error = '', string $kyc_status = '', string $kyc_reason = '' ): array {
	return array(
		'recipient_id'      => $recipient_id,
		'status'            => $status,
		'kyc_status'        => $kyc_status,
		'kyc_status_reason' => $kyc_reason,
		'last_sync_at'      => ELIG_TEST_SYNC_AT,
		'last_error'        => $last_error,
		'last_error_code'   => '' === $last_error ? '' : 'papelito_pagarme_request_failed',
	);
}

/** Define o cenário avaliado. */
function elig_scenario( array $recipient, int $boxes, bool $gate = true, bool $suspended = false ): void {
	$GLOBALS['elig_test_recipient']  = $recipient;
	$GLOBALS['elig_test_boxes']      = $boxes;
	$GLOBALS['elig_test_gate']       = $gate;
	$GLOBALS['elig_test_suspended']  = $suspended;
}

/** Devolve um requisito do veredito pelo identificador. */
function elig_requirement( array $verdict, string $id ): array {
	foreach ( $verdict['requirements'] as $requirement ) {
		if ( $id === $requirement['id'] ) {
			return $requirement;
		}
	}

	return array();
}

/** Grava a configuração administrativa sem passar pelo REST. */
function elig_configure( mixed $minimum, mixed $recommended ): mixed {
	$config = papelito_vendor_eligibility_config_from_payload(
		array( 'minimum_boxes' => $minimum, 'recommended_boxes' => $recommended )
	);

	if ( $config instanceof WP_Error ) {
		return $config;
	}

	update_option( PAPELITO_VENDOR_ELIGIBILITY_OPTION, $config, false );

	return $config;
}

echo "Scenario 1: a configuração ausente vale 2 de mínimo e 3 de recomendado\n";
elig_assert( 'o mínimo padrão é 2', 2 === papelito_vendor_minimum_boxes() );
elig_assert( 'o recomendado padrão é 3', 3 === papelito_vendor_recommended_boxes() );
elig_assert( 'a option vazia não é gravada só por ser lida', array() === $GLOBALS['elig_test_options'] );

echo "Scenario 2: recebedor ativo com o mínimo de caixas vende\n";
elig_scenario( elig_recipient( 'active' ), 2 );
$duas = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'duas caixas deixam o vendor apto', true === $duas['can_sell'] );
elig_assert( 'e papelito_vendor_is_eligible_to_sell concorda', papelito_vendor_is_eligible_to_sell( ELIG_TEST_VENDOR_ID ) );
elig_assert( 'o requisito de caixas está atendido', true === elig_requirement( $duas, 'boxes' )['satisfied'] );
elig_assert( 'sem bloquear', false === elig_requirement( $duas, 'boxes' )['blocking'] );
elig_assert( 'mas avisando que o recomendado é maior', true === elig_requirement( $duas, 'boxes' )['advisory'] );
elig_assert( 'com o motivo abaixo do recomendado', 'below_recommended' === elig_requirement( $duas, 'boxes' )['reason'] );
elig_assert( 'o veredito devolve a configuração vigente', 3 === $duas['config']['recommended_boxes'] );

echo "Scenario 3: no recomendado não sobra nem aviso\n";
elig_scenario( elig_recipient( 'active' ), 3 );
$tres = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'três caixas deixam o vendor apto', true === $tres['can_sell'] );
elig_assert( 'sem recomendação pendente', false === elig_requirement( $tres, 'boxes' )['advisory'] );
elig_assert( 'e sem motivo a exibir', '' === elig_requirement( $tres, 'boxes' )['reason'] );
elig_scenario( elig_recipient( 'active' ), 6 );
elig_assert( 'acima do recomendado segue apto e silencioso', papelito_vendor_is_eligible_to_sell( ELIG_TEST_VENDOR_ID ) );

echo "Scenario 4: abaixo do mínimo é pendência impeditiva\n";
elig_scenario( elig_recipient( 'active' ), 1 );
$uma = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'uma caixa não vende', false === $uma['can_sell'] );
elig_assert( 'o requisito de caixas bloqueia', true === elig_requirement( $uma, 'boxes' )['blocking'] );
elig_assert( 'com o motivo abaixo do mínimo', 'below_minimum' === elig_requirement( $uma, 'boxes' )['reason'] );
elig_assert( 'e o painel recebe a contagem real', 1 === elig_requirement( $uma, 'boxes' )['active_count'] );
elig_scenario( elig_recipient( 'active' ), 0 );
elig_assert( 'vendor sem caixa nenhuma também não vende', ! papelito_vendor_is_eligible_to_sell( ELIG_TEST_VENDOR_ID ) );

echo "Scenario 5: sem o gate de embalagem a falta vira aviso, não bloqueio\n";
elig_scenario( elig_recipient( 'active' ), 0, false );
$sem_gate = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'com o gate desligado o vendor continua vendendo', true === $sem_gate['can_sell'] );
elig_assert( 'mas a pendência continua visível', false === elig_requirement( $sem_gate, 'boxes' )['satisfied'] );
elig_assert( 'como aviso', true === elig_requirement( $sem_gate, 'boxes' )['advisory'] );

echo "Scenario 6: Pagar.me nunca sincronizada bloqueia mesmo com caixas de sobra\n";
elig_scenario( elig_recipient( '', '' ), 6 );
$nunca = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'vendor sem recebedor não vende', false === $nunca['can_sell'] );
elig_assert( 'o requisito da Pagar.me bloqueia', true === elig_requirement( $nunca, 'pagarme' )['blocking'] );
elig_assert( 'com o motivo nunca sincronizado', 'never_synced' === elig_requirement( $nunca, 'pagarme' )['reason'] );
elig_assert( 'e as caixas seguem em dia', true === elig_requirement( $nunca, 'boxes' )['satisfied'] );

echo "Scenario 7: sincronização com erro bloqueia\n";
elig_scenario( elig_recipient( '', '', ELIG_TEST_PAGARME_RAW_ERROR ), 6 );
$erro = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'erro de sincronização não vende', false === $erro['can_sell'] );
elig_assert( 'com o motivo de erro de sincronização', 'sync_error' === elig_requirement( $erro, 'pagarme' )['reason'] );
elig_assert( 'e o erro cru da Pagar.me não viaja no veredito', ! str_contains( (string) json_encode( $erro ), ELIG_TEST_PAGARME_RAW_ERROR ) );

echo "Scenario 8: cada estado oficial da Pagar.me tem motivo próprio\n";
$estados = array(
	'registration' => 'in_review',
	'refused'      => 'rejected',
	'suspended'    => 'rejected',
	'blocked'      => 'rejected',
	'inactive'     => 'rejected',
	'teleported'   => 'unknown',
);
foreach ( $estados as $status => $reason ) {
	elig_scenario( elig_recipient( $status ), 6 );
	$verdict = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
	elig_assert( "o estado {$status} bloqueia com motivo {$reason}", false === $verdict['can_sell'] && $reason === elig_requirement( $verdict, 'pagarme' )['reason'] );
}
elig_scenario( elig_recipient( 'affiliation', ELIG_TEST_RECIPIENT_ID, '', 'partially_denied', 'additional_documents_required' ), 6 );
elig_assert( 'KYC pendente vira motivo próprio', 'kyc_required' === elig_requirement( papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID ), 'pagarme' )['reason'] );
elig_scenario( elig_recipient( 'affiliation', ELIG_TEST_RECIPIENT_ID, '', 'pending' ), 6 );
elig_assert( 'credenciamento em análise é em revisão', 'in_review' === elig_requirement( papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID ), 'pagarme' )['reason'] );

echo "Scenario 9: falha temporária da API não derruba recebedor já ativo\n";
elig_scenario( elig_recipient( 'active', ELIG_TEST_RECIPIENT_ID, ELIG_TEST_PAGARME_RAW_ERROR ), 3 );
$temporaria = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'o vendor continua apto', true === $temporaria['can_sell'] );
elig_assert( 'o requisito segue atendido', true === elig_requirement( $temporaria, 'pagarme' )['satisfied'] );
elig_assert( 'com aviso de última sincronização falha', 'last_sync_failed' === elig_requirement( $temporaria, 'pagarme' )['reason'] );
elig_assert( 'e sem bloquear', false === elig_requirement( $temporaria, 'pagarme' )['blocking'] );

echo "Scenario 10: conta suspensa bloqueia sozinha\n";
elig_scenario( elig_recipient( 'active' ), 6, true, true );
$suspensa = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'conta suspensa não vende', false === $suspensa['can_sell'] );
elig_assert( 'com o motivo suspensa', 'suspended' === elig_requirement( $suspensa, 'account' )['reason'] );
elig_assert( 'vendor inexistente nunca é elegível', ! papelito_vendor_is_eligible_to_sell( 0 ) );

echo "Scenario 11: mudar o mínimo muda quem vende\n";
elig_configure( 4, 6 );
elig_scenario( elig_recipient( 'active' ), 3 );
elig_assert( 'com mínimo 4 o vendor de três caixas deixa de vender', ! papelito_vendor_is_eligible_to_sell( ELIG_TEST_VENDOR_ID ) );
elig_scenario( elig_recipient( 'active' ), 4 );
elig_assert( 'e volta ao atingir o novo mínimo', papelito_vendor_is_eligible_to_sell( ELIG_TEST_VENDOR_ID ) );
elig_configure( 1, 1 );
elig_scenario( elig_recipient( 'active' ), 1 );
elig_assert( 'baixar o mínimo para 1 devolve o vendor de uma caixa', papelito_vendor_is_eligible_to_sell( ELIG_TEST_VENDOR_ID ) );

echo "Scenario 12: mudar o recomendado nunca vira bloqueio\n";
elig_configure( 2, 8 );
elig_scenario( elig_recipient( 'active' ), 2 );
$recomendado_alto = papelito_vendor_eligibility( ELIG_TEST_VENDOR_ID );
elig_assert( 'recomendado 8 com duas caixas ainda vende', true === $recomendado_alto['can_sell'] );
elig_assert( 'e o aviso acompanha o novo número', 8 === elig_requirement( $recomendado_alto, 'boxes' )['recommended_boxes'] );
elig_assert( 'sem bloquear', false === elig_requirement( $recomendado_alto, 'boxes' )['blocking'] );

echo "Scenario 13: a escrita da configuração recusa valor inconsistente\n";
elig_assert( 'recomendado menor que o mínimo é recusado', elig_configure( 3, 2 ) instanceof WP_Error );
elig_assert( 'mínimo zero é recusado', elig_configure( 0, 3 ) instanceof WP_Error );
elig_assert( 'mínimo acima do teto de caixas ativas é recusado', elig_configure( 25, 25 ) instanceof WP_Error );
elig_assert( 'valor não numérico é recusado', elig_configure( 'duas', 3 ) instanceof WP_Error );
elig_assert( 'a recusa não altera o que estava gravado', 2 === papelito_vendor_minimum_boxes() && 8 === papelito_vendor_recommended_boxes() );
elig_assert( 'número em texto é aceito, como o REST entrega', ! ( elig_configure( '2', '3' ) instanceof WP_Error ) );
elig_assert( 'e passa a valer', 3 === papelito_vendor_recommended_boxes() );

echo "Scenario 14: option corrompida cai no padrão em vez de derrubar a vitrine\n";
$GLOBALS['elig_test_options'][ PAPELITO_VENDOR_ELIGIBILITY_OPTION ] = 'corrompida';
elig_assert( 'option em texto vira o mínimo padrão', 2 === papelito_vendor_minimum_boxes() );
$GLOBALS['elig_test_options'][ PAPELITO_VENDOR_ELIGIBILITY_OPTION ] = array( 'minimum_boxes' => 5 );
elig_assert( 'campo ausente cai no padrão', 5 === papelito_vendor_minimum_boxes() && 5 === papelito_vendor_recommended_boxes() );
$GLOBALS['elig_test_options'][ PAPELITO_VENDOR_ELIGIBILITY_OPTION ] = array( 'minimum_boxes' => 99, 'recommended_boxes' => -4 );
elig_assert( 'leitura fora da faixa é puxada para dentro dela', 24 === papelito_vendor_minimum_boxes() && 24 === papelito_vendor_recommended_boxes() );

echo "\n";
echo 0 === $failures ? "OK: elegibilidade do vendor\n" : "FAIL: {$failures} verificações\n";
exit( 0 === $failures ? 0 : 1 );
