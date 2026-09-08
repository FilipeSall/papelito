<?php
/**
 * Standalone regression test for the vendor pending-registration rules.
 *
 * Natureza juridica, data de fundacao e nome da mae do socio sao OPCIONAIS no
 * contrato de dados minimos da Pagar.me, mas o Papelito os tratava como
 * bloqueantes. O telefone, que a Pagar.me exige em `phone_numbers` do recebedor
 * e do representante legal, nao era cobrado em lugar nenhum — o payload saia com
 * um numero de preenchimento.
 *
 * Este teste fixa a regra nova nos dois sentidos: os tres campos nao bloqueiam
 * mais, o telefone bloqueia, e o payload da Pagar.me omite os opcionais vazios
 * em vez de mandar string vazia (que a API recusa por formato).
 *
 * Usage: php tests/test-vendor-pending-required-fields.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'PAPELITO_TEST_PARTNER_NAME', 'Ana Souza' );
define( 'PAPELITO_TEST_PARTNER_MOTHER_NAME', 'Maria Souza' );

/**
 * Stub inerte do registrador de acoes.
 *
 * @param mixed ...$args Ignorados.
 * @return void
 */
function add_action( ...$args ) {}

/**
 * Stub inerte do registrador de filtros.
 *
 * @param mixed ...$args Ignorados.
 * @return void
 */
function add_filter( ...$args ) {}

/**
 * Stub inerte do registrador de rotas REST.
 *
 * @param mixed ...$args Ignorados.
 * @return void
 */
function register_rest_route( ...$args ) {}

/**
 * Sanitiza texto simples.
 *
 * @param mixed $value Valor cru.
 * @return string
 */
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) );
}

/**
 * Sanitiza e-mail.
 *
 * @param mixed $value Valor cru.
 * @return string
 */
function sanitize_email( $value ) {
	return (string) $value;
}

/**
 * Valida e-mail de forma suficiente para o teste.
 *
 * @param mixed $value Valor cru.
 * @return bool
 */
function is_email( $value ) {
	return false !== strpos( (string) $value, '@' );
}

/**
 * Normaliza telefone para digitos.
 *
 * @param mixed $value Valor cru.
 * @return string
 */
function papelito_auth_normalize_phone( $value ) {
	$digits = preg_replace( '/\D+/', '', (string) $value );

	if ( ( 12 === strlen( $digits ) || 13 === strlen( $digits ) ) && 0 === strpos( $digits, '55' ) ) {
		return substr( $digits, 2 );
	}

	return $digits;
}

/**
 * Valida telefone brasileiro com DDD para o harness standalone.
 *
 * @param string $phone Telefone cru.
 * @return string|null
 */
function papelito_phone_validation_error( $phone ) {
	$phone = (string) $phone;
	if ( '' === $phone || 1 !== preg_match( '/^[\d\s()+-]+$/', $phone ) ) {
		return 'Informe um telefone válido com DDD.';
	}

	$local = papelito_auth_normalize_phone( $phone );

	return ! in_array( strlen( $local ), array( 10, 11 ), true ) || 1 === preg_match( '/^(\d)\1+$/', $local )
		? 'Informe um telefone válido com DDD.'
		: null;
}

/**
 * Estados aceitos no teste.
 *
 * @return array<string,string>
 */
function papelito_brazilian_states() {
	return array( 'DF' => 'Distrito Federal' );
}

/**
 * Substituto minimo de WP_Error para os validadores.
 */
class WP_Error {
	/** @var array<int,string> */
	public $codes = array();

	/**
	 * Aceita o mesmo construtor do WP_Error real.
	 *
	 * @param string $code    Codigo inicial, opcional.
	 * @param string $message Mensagem, ignorada.
	 * @param mixed  $data    Dados, ignorados.
	 */
	public function __construct( $code = '', $message = '', $data = null ) {
		unset( $message, $data );
		if ( '' !== (string) $code ) {
			$this->codes[] = (string) $code;
		}
	}

	/**
	 * Registra um codigo de erro.
	 *
	 * @param string $code    Codigo.
	 * @param string $message Mensagem, ignorada.
	 * @return void
	 */
	public function add( $code, $message = '' ) {
		unset( $message );
		$this->codes[] = $code;
	}

	/**
	 * Indica se ha algum erro acumulado.
	 *
	 * @return bool
	 */
	public function has_errors() {
		return ! empty( $this->codes );
	}

	/**
	 * Anexa dados ao erro.
	 *
	 * @param mixed $data Ignorado.
	 * @return void
	 */
	public function add_data( $data ) {
		unset( $data );
	}

	/**
	 * Primeiro codigo registrado.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->codes[0] ?? '';
	}
}

require_once __DIR__ . '/../includes/revendedor_application.php';

$failures = 0;

/**
 * Compara valor esperado e obtido.
 *
 * @param string $label    Nome do caso.
 * @param mixed  $expected Esperado.
 * @param mixed  $actual   Obtido.
 * @return void
 */
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> esperado " . wp_json_encode_fallback( $expected ) . ', obtido ' . wp_json_encode_fallback( $actual ) . "\n";
}

/**
 * Serializa um valor para a mensagem de falha.
 *
 * @param mixed $value Valor.
 * @return string
 */
function wp_json_encode_fallback( $value ): string {
	return is_scalar( $value ) ? var_export( $value, true ) : wp_json_encode_compact( $value );
}

/**
 * Serializa arrays de forma curta.
 *
 * @param mixed $value Valor.
 * @return string
 */
function wp_json_encode_compact( $value ): string {
	return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Draft completo e valido, do qual cada caso remove o que quer testar.
 *
 * @return array<string,mixed>
 */
function papelito_test_complete_draft(): array {
	return array(
		'companyName'      => 'Cifal Comercial de Tabacos Ltda',
		'tradingName'      => 'Cifal',
		'corporationType'  => 'LTDA',
		'foundingDate'     => '2015-03-10',
		'annualRevenue'    => '850000',
		'managingPartners' => array(
			array(
				'name'                            => PAPELITO_TEST_PARTNER_NAME,
				'email'                           => 'ana@cifal.test',
				'document'                        => '19100000000',
				'motherName'                      => PAPELITO_TEST_PARTNER_MOTHER_NAME,
				'birthdate'                       => '1984-10-30',
				'monthlyIncome'                   => '12000',
				'professionalOccupation'          => 'Empresaria',
				'selfDeclaredLegalRepresentative' => true,
				'address'                         => array(
					'zipCode'      => '70000000',
					'street'       => 'SIA Trecho 4',
					'streetNumber' => '375',
					'neighborhood' => 'SIA',
					'city'         => 'Brasilia',
					'state'        => 'DF',
				),
			),
		),
		'bankAccount'      => array(
			'holderName'        => 'Cifal Comercial de Tabacos Ltda',
			'holderType'        => 'company',
			'holderDocument'    => '11.444.777/0001-61',
			'bankCode'          => '341',
			'branchNumber'      => '1234',
			'branchCheckDigit'  => '',
			'accountNumber'     => '12345',
			'accountCheckDigit' => '6',
			'type'              => 'checking',
		),
	);
}

$valid_phone = '(61) 99999-9999';

echo "Campos opcionais nao bloqueiam mais o cadastro\n";

$draft = papelito_test_complete_draft();
$draft['corporationType']                   = '';
$draft['foundingDate']                      = '';
$draft['managingPartners'][0]['motherName'] = '';

papelito_assert(
	'sem natureza juridica, data de fundacao e nome da mae o cadastro fica completo',
	array(),
	papelito_collect_vendor_pending_registration_fields( $draft, $valid_phone )
);

papelito_assert(
	'os tres campos sairam da lista de pendencias possiveis',
	array(),
	array_values(
		array_intersect(
			array( 'corporationType', 'foundingDate', 'partner.motherName' ),
			papelito_vendor_pending_registration_allowed_fields()
		)
	)
);

papelito_assert(
	'o validador estrito tambem aceita o draft sem os tres campos',
	null,
	papelito_validate_vendor_pagarme_step3( $draft )
);

$draft_bad_date                 = papelito_test_complete_draft();
$draft_bad_date['foundingDate'] = '10/03/2015';
$date_errors                    = papelito_validate_vendor_pagarme_step3( $draft_bad_date );
papelito_assert(
	'data de fundacao preenchida com formato errado continua sendo recusada',
	true,
	$date_errors instanceof WP_Error && in_array( 'foundingDate', $date_errors->codes, true )
);

echo "\nTelefone passou a ser obrigatorio\n";

$complete = papelito_test_complete_draft();

papelito_assert(
	'telefone vazio deixa o cadastro incompleto',
	array( 'phoneNumber' ),
	papelito_collect_vendor_pending_registration_fields( $complete, '' )
);

papelito_assert(
	'telefone sem DDD nao satisfaz a regra',
	array( 'phoneNumber' ),
	papelito_collect_vendor_pending_registration_fields( $complete, '99999999' )
);

papelito_assert(
	'telefone com DDD conclui o cadastro',
	array(),
	papelito_collect_vendor_pending_registration_fields( $complete, $valid_phone )
);

papelito_assert( 'fixo com 10 digitos e aceito', true, papelito_vendor_phone_is_valid( '(61) 3333-4444' ) );
papelito_assert( 'celular com 11 digitos e aceito', true, papelito_vendor_phone_is_valid( '61999999999' ) );
papelito_assert( 'telefone com prefixo +55 e aceito', true, papelito_vendor_phone_is_valid( '+55 (61) 99999-9999' ) );
papelito_assert( 'DDD local 55 permanece valido', true, papelito_vendor_phone_is_valid( '55999999999' ) );
papelito_assert( 'numero curto e recusado', false, papelito_vendor_phone_is_valid( '61999' ) );
papelito_assert( 'sequencia de dez zeros e recusada', false, papelito_vendor_phone_is_valid( '0000000000' ) );
papelito_assert( 'sequencia de onze uns e recusada', false, papelito_vendor_phone_is_valid( '11111111111' ) );

echo "\nO backend recusa o estado invalido mesmo se o frontend for contornado\n";

$step2 = array(
	'street'       => 'SIA Trecho 4',
	'number'       => '375',
	'neighborhood' => 'SIA',
	'city'         => 'Brasilia',
	'state'        => 'DF',
);

$without_phone = papelito_validate_vendor_pending_registration_store(
	array(
		'storeName' => 'Cifal',
		'phone'     => '',
	),
	$step2
);
papelito_assert(
	'salvar cadastro sem telefone devolve WP_Error',
	'papelito_vendor_invalid_phone',
	$without_phone instanceof WP_Error ? $without_phone->get_error_code() : 'sem erro'
);

papelito_assert(
	'salvar cadastro com telefone valido passa',
	null,
	papelito_validate_vendor_pending_registration_store(
		array(
			'storeName' => 'Cifal',
			'phone'     => $valid_phone,
		),
		$step2
	)
);

$repeated_phone = papelito_validate_vendor_pending_registration_store(
	array(
		'storeName' => 'Cifal',
		'phone'     => '0000000000',
	),
	$step2
);
papelito_assert(
	'salvar cadastro com telefone repetido devolve WP_Error',
	'papelito_vendor_invalid_phone',
	$repeated_phone instanceof WP_Error ? $repeated_phone->get_error_code() : 'sem erro'
);

echo "\nO payload da Pagar.me omite os opcionais vazios\n";

require_once __DIR__ . '/../includes/pagarme_recipients.php';

$partner_without_mother = papelito_pagarme_partner_payload(
	array(
		'name'       => PAPELITO_TEST_PARTNER_NAME,
		'email'      => 'ana@cifal.test',
		'document'   => '191.000.000-00',
		'motherName' => '',
		'birthdate'  => '1984-10-30',
	),
	$valid_phone
);
papelito_assert(
	'nome da mae vazio nao vai como string vazia',
	false,
	array_key_exists( 'mother_name', $partner_without_mother )
);

$partner_with_mother = papelito_pagarme_partner_payload(
	array(
		'name'       => PAPELITO_TEST_PARTNER_NAME,
		'motherName' => PAPELITO_TEST_PARTNER_MOTHER_NAME,
	),
	$valid_phone
);
papelito_assert(
	'nome da mae preenchido continua sendo enviado',
	PAPELITO_TEST_PARTNER_MOTHER_NAME,
	$partner_with_mother['mother_name'] ?? null
);

papelito_assert(
	'telefone informado chega no payload do representante legal',
	array(
		'ddd'    => '61',
		'number' => '999999999',
		'type'   => 'mobile',
	),
	$partner_with_mother['phone_numbers'][0] ?? array()
);

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
