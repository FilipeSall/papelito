<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Traducao do que a Pagar.me recusou no recebedor do vendor.
 *
 * O painel dizia apenas "nao foi possivel validar seus dados". O detalhe ja existia no
 * diagnostico guardado para o suporte; este arquivo cobre a traducao dele em campo, orientacao e
 * frase de validacao que o vendor consegue agir.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

const REJ_TEST_VENDOR_ID   = 8120;
const REJ_TEST_HOLDER_LIMIT = 'Bank account holder name must be lower than 30 characters.';

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

/** Sanitiza um e-mail sintético. */
function sanitize_email( mixed $value ): string {
	return trim( (string) $value );
}

/** Codifica um payload sintético do WordPress. */
function wp_json_encode( mixed $value, int $flags = 0 ): string {
	return (string) json_encode( $value, $flags );
}

/** Stub de registro de action não relacionada ao seam testado. */
function add_action( mixed ...$args ): bool {
	return true;
}

/** Stub de registro de rota não relacionada ao seam testado. */
function register_rest_route( mixed ...$args ): bool {
	return true;
}

/** Lê o usermeta sintético. */
function get_user_meta( mixed $user_id, mixed $key, mixed $single = false ): mixed {
	return $GLOBALS['rej_test_meta'][ (string) $key ] ?? ( $single ? '' : array() );
}

/** Grava o usermeta sintético. */
function update_user_meta( mixed $user_id, mixed $key, mixed $value ): bool {
	$GLOBALS['rej_test_meta'][ (string) $key ] = $value;

	return true;
}

/** Remove o usermeta sintético. */
function delete_user_meta( mixed $user_id, mixed $key ): bool {
	unset( $GLOBALS['rej_test_meta'][ (string) $key ] );

	return true;
}

/** Devolve o instante sintético corrente. */
function papelito_current_utc_mysql(): string {
	return '2026-09-21 09:00:00';
}

$GLOBALS['rej_test_meta'] = array();

require_once dirname( __DIR__ ) . '/includes/pagarme_recipients.php';

$failures = 0;

/** Confere um comportamento da tradução de campos recusados. */
function rej_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Traduz um detalhe cru e devolve o primeiro campo resolvido. */
function rej_first( string $detail ): array {
	$fields = papelito_pagarme_rejected_fields_from_details( array( $detail ) );

	return $fields[0] ?? array();
}

/** Monta o WP_Error como o cliente Pagar.me o devolve. */
function rej_error( array $details ): WP_Error {
	return new WP_Error(
		'papelito_pagarme_validation_failed',
		'Pagar.me recusou.',
		array( 'status' => 422, 'pagarme_body' => array( 'message' => 'The request is invalid.', 'details' => $details ) )
	);
}

/** Monta o WP_Error de uma recusa que veio só como frase, sem mapa de campos. */
function rej_message_error( string $message ): WP_Error {
	return new WP_Error(
		'papelito_pagarme_request_failed',
		$message,
		array( 'status' => 422, 'pagarme_body' => array( 'message' => $message ) )
	);
}

echo "Scenario 1: o campo da chave vira rótulo em português com orientação\n";
$agencia = rej_first( 'default_bank_account.branch_number: The branch_number field is invalid' );
rej_assert( 'a agência é reconhecida', 'bank_account.branch_number' === ( $agencia['field'] ?? '' ) );
rej_assert( 'com rótulo legível e acentuado', 'Agência' === ( $agencia['label'] ?? '' ) );
rej_assert( 'e orientação do que fazer', str_contains( (string) ( $agencia['hint'] ?? '' ), 'quatro dígitos' ) );
rej_assert( 'preservando a frase da Pagar.me', str_contains( (string) ( $agencia['detail'] ?? '' ), 'branch_number field is invalid' ) );

echo "Scenario 2: o campo citado só na mensagem também é reconhecido\n";
rej_assert( 'agência longa demais', 'bank_account.branch_number' === ( rej_first( 'invalid_parameter: agencia | Value too long' )['field'] ?? '' ) );
rej_assert( 'dígito de agência vence agência', 'bank_account.branch_check_digit' === ( rej_first( 'invalid_parameter: agencia_dv | Invalid format' )['field'] ?? '' ) );
rej_assert( 'dígito de conta vence conta', 'bank_account.account_check_digit' === ( rej_first( 'invalid_parameter: conta_dv | Invalid format' )['field'] ?? '' ) );

echo "Scenario 3: o titular da conta ganha a orientação do CNPJ e do limite de caracteres\n";
$titular = rej_first( 'default_bank_account.holder_document: holder_document must match the recipient document' );
rej_assert( 'documento do titular vence documento da empresa', 'bank_account.holder_document' === ( $titular['field'] ?? '' ) );
rej_assert( 'e explica que a conta tem de ser PJ', str_contains( (string) ( $titular['hint'] ?? '' ), 'CNPJ da empresa' ) );
rej_assert( 'nome do titular cita o limite de 30 caracteres', str_contains( (string) ( rej_first( 'default_bank_account.holder_name: must have 30 characters or fewer' )['hint'] ?? '' ), '30 caracteres' ) );

echo "Scenario 4: o índice do sócio não atrapalha o reconhecimento\n";
$socio = rej_first( 'register_information.managing_partners[0].document: The document field is invalid' );
rej_assert( 'o CPF do responsável legal é reconhecido', 'partner.document' === ( $socio['field'] ?? '' ) );
rej_assert( 'e fica no grupo do responsável', 'partner' === ( $socio['group'] ?? '' ) );
rej_assert( 'nascimento do sócio é próprio', 'partner.birthdate' === ( rej_first( 'register_information.managing_partners[0].birthdate: Invalid format' )['field'] ?? '' ) );
rej_assert( 'endereço do sócio não vira endereço da empresa', 'partner.address' === ( rej_first( 'register_information.managing_partners[0].address.zip_code: required' )['field'] ?? '' ) );

echo "Scenario 5: os campos da empresa são reconhecidos\n";
rej_assert( 'CNPJ da empresa', 'company.document' === ( rej_first( 'register_information.document: The document field is invalid' )['field'] ?? '' ) );
rej_assert( 'razão social', 'company.company_name' === ( rej_first( 'register_information.company_name: required' )['field'] ?? '' ) );
rej_assert( 'faturamento anual', 'company.annual_revenue' === ( rej_first( 'register_information.annual_revenue: must be greater than 0' )['field'] ?? '' ) );
rej_assert( 'endereço comercial', 'company.address' === ( rej_first( 'register_information.main_address.zip_code: required' )['field'] ?? '' ) );

echo "Scenario 6: detalhe desconhecido vira pendência genérica em vez de sumir\n";
$outro = rej_first( 'quantum_flux: the flux capacitor is misaligned' );
rej_assert( 'o detalhe continua visível', 'outros' === ( $outro['field'] ?? '' ) );
rej_assert( 'com a frase original', str_contains( (string) ( $outro['detail'] ?? '' ), 'flux capacitor' ) );
rej_assert( 'e orientação de acionar o suporte', str_contains( (string) ( $outro['hint'] ?? '' ), 'suporte' ) );

echo "Scenario 7: o mesmo campo citado duas vezes aparece uma só\n";
$repetido = papelito_pagarme_rejected_fields_from_details(
	array( 'default_bank_account.branch_number: required', 'default_bank_account.branch_number: invalid' )
);
rej_assert( 'sem linha duplicada', 1 === count( $repetido ) );
rej_assert( 'detalhe vazio não vira linha', 0 === count( papelito_pagarme_rejected_fields_from_details( array() ) ) );

echo "Scenario 8: a frase longa demais é encurtada\n";
$longo = rej_first( 'default_bank_account.bank: ' . str_repeat( 'x', 400 ) );
rej_assert( 'o detalhe cabe numa linha', strlen( (string) $longo['detail'] ) <= 160 );
rej_assert( 'e é marcado como truncado', str_ends_with( (string) $longo['detail'], '...' ) );

echo "Scenario 9: os campos entram na resposta REST e no estado persistido\n";
$erro      = rej_error( array( 'default_bank_account.holder_name: must have 30 characters or fewer' ) );
$resposta  = papelito_pagarme_recipient_error_response( $erro );
$dados     = $resposta->get_error_data();
rej_assert( 'a resposta REST leva os campos', 1 === count( $dados['fields'] ?? array() ) );
rej_assert( 'o status original é preservado', 422 === ( $dados['status'] ?? 0 ) );
rej_assert( 'a mensagem genérica continua sem detalhe técnico', 'Não foi possível validar os dados do recebedor.' === $resposta->get_error_message() );
rej_assert( 'e o corpo cru da Pagar.me não viaja', ! isset( $dados['pagarme_body'] ) && ! isset( $dados['response_body'] ) );

papelito_pagarme_save_vendor_recipient_error( REJ_TEST_VENDOR_ID, $erro );
$estado = papelito_pagarme_get_vendor_recipient_state( REJ_TEST_VENDOR_ID );
rej_assert( 'o estado guarda o campo recusado', 'bank_account.holder_name' === ( $estado['last_error_fields'][0]['field'] ?? '' ) );
rej_assert( 'para o painel mostrar depois de recarregar', 'Nome do titular da conta' === ( $estado['last_error_fields'][0]['label'] ?? '' ) );
rej_assert( 'e o texto exibido ao vendor vem acentuado', str_contains( (string) ( $estado['last_error_fields'][0]['hint'] ?? '' ), 'razão social' ) );

echo "Scenario 10: recusa que vem só como frase também nomeia o campo\n";
$frase = papelito_pagarme_rejected_fields_from_error( rej_message_error( REJ_TEST_HOLDER_LIMIT ) );
rej_assert( 'o caso real do titular longo demais vira uma linha', 1 === count( $frase ) );
rej_assert( 'apontando o nome do titular', 'bank_account.holder_name' === ( $frase[0]['field'] ?? '' ) );
rej_assert( 'com a frase original preservada', REJ_TEST_HOLDER_LIMIT === ( $frase[0]['detail'] ?? '' ) );
rej_assert( 'frase genérica não vira linha', 0 === count( papelito_pagarme_rejected_fields_from_error( rej_message_error( 'The request is invalid.' ) ) ) );
rej_assert( 'sem corpo nenhum também não vira linha', 0 === count( papelito_pagarme_rejected_fields_from_error( new WP_Error( 'x', 'y', array( 'status' => 500 ) ) ) ) );
rej_assert( 'details continua tendo precedência sobre message', 'bank_account.branch_number' === ( papelito_pagarme_rejected_fields_from_error( rej_error( array( 'default_bank_account.branch_number: required' ) ) )[0]['field'] ?? '' ) );

echo "Scenario 11: vendor travado antes da tradução não precisa de nova tentativa\n";
$GLOBALS['rej_test_meta'] = array(
	PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META        => REJ_TEST_HOLDER_LIMIT,
	PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_CODE_META   => 'papelito_pagarme_request_failed',
	PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META => wp_json_encode( array( 'message' => REJ_TEST_HOLDER_LIMIT ) ),
);
$legado = papelito_pagarme_get_vendor_rejected_fields( REJ_TEST_VENDOR_ID );
rej_assert( 'o diagnóstico antigo vira campo recusado', 1 === count( $legado ) );
rej_assert( 'apontando o nome do titular', 'bank_account.holder_name' === ( $legado[0]['field'] ?? '' ) );
$GLOBALS['rej_test_meta'][ PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META ] = wp_json_encode(
	array( 'message' => 'The request is invalid.', 'details' => array( 'register_information.managing_partners[0].document: invalid' ) )
);
rej_assert( 'diagnóstico antigo com details também é lido', 'partner.document' === ( papelito_pagarme_get_vendor_rejected_fields( REJ_TEST_VENDOR_ID )[0]['field'] ?? '' ) );
$GLOBALS['rej_test_meta'][ PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META ] = 'não é json';
rej_assert( 'diagnóstico corrompido não derruba a leitura', 0 === count( papelito_pagarme_get_vendor_rejected_fields( REJ_TEST_VENDOR_ID ) ) );
$GLOBALS['rej_test_meta'] = array();

echo "Scenario 12: sincronizar com sucesso limpa os campos recusados\n";
papelito_pagarme_save_vendor_recipient_error( REJ_TEST_VENDOR_ID, $erro );
papelito_pagarme_save_vendor_recipient_state( REJ_TEST_VENDOR_ID, array( 'id' => 're_ok', 'status' => 'active' ) );
$limpo = papelito_pagarme_get_vendor_recipient_state( REJ_TEST_VENDOR_ID );
rej_assert( 'nenhum campo recusado sobra', 0 === count( $limpo['last_error_fields'] ) );
rej_assert( 'e o estado fica ativo', 'active' === $limpo['status'] );

echo "\n";
echo 0 === $failures ? "OK: campos recusados pela Pagar.me\n" : "FAIL: {$failures} verificações\n";
exit( 0 === $failures ? 0 : 1 );
