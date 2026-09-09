<?php
/**
 * Standalone regression test for the admin vendor update operation.
 *
 * A edicao administrativa nao ganhou caminho de escrita proprio: ela reaproveita
 * papelito_update_vendor_pending_registration_rest(), a mesma operacao que o proprio
 * vendor usa. Este teste fixa o que a camada admin adiciona por cima dela:
 *
 * - so administrador escreve, e so sobre quem ja tem a role `seller` (customer nao
 *   vira vendor por aqui);
 * - bloco ausente no payload preserva o que estava gravado, e string vazia limpa;
 * - id, role, sourceUserId e temporaryPassword no payload sao ignorados;
 * - o recebedor Pagar.me e ressincronizado quando o cadastro fica completo.
 *
 * Usage: php tests/test-admin-vendor-update.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

$papelito_test_users           = array();
$papelito_test_meta            = array();
$papelito_test_current_user    = 0;
$papelito_test_can_manage      = true;
$papelito_test_recipient_syncs = array();
$papelito_test_actions         = array();

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
 * Registra acoes disparadas para inspecao.
 *
 * @param string $hook Nome do hook.
 * @param mixed  ...$args Argumentos.
 * @return void
 */
function do_action( $hook, ...$args ) {
	global $papelito_test_actions;
	$papelito_test_actions[] = array(
		'hook' => (string) $hook,
		'args' => $args,
	);
}

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
	return trim( (string) $value );
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
 * Converte para inteiro positivo.
 *
 * @param mixed $value Valor cru.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}

/**
 * Serializa em JSON como o WordPress.
 *
 * @param mixed $value Valor.
 * @param int   $flags Flags do json_encode.
 * @return string|false
 */
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, (int) $flags );
}

/**
 * Data/hora fixa para o harness.
 *
 * @param string $type Formato.
 * @param bool   $gmt  Fuso.
 * @return string
 */
function current_time( $type = 'mysql', $gmt = false ) {
	unset( $type, $gmt );
	return '2026-09-08 12:00:00';
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
 * Formata telefone como o fluxo de auth.
 *
 * @param mixed $value Valor cru.
 * @return string
 */
function papelito_auth_format_phone( $value ) {
	$local = papelito_auth_normalize_phone( $value );

	if ( 11 === strlen( $local ) ) {
		return '(' . substr( $local, 0, 2 ) . ') ' . substr( $local, 2, 5 ) . '-' . substr( $local, 7 );
	}

	if ( 10 === strlen( $local ) ) {
		return '(' . substr( $local, 0, 2 ) . ') ' . substr( $local, 2, 4 ) . '-' . substr( $local, 6 );
	}

	return $local;
}

/**
 * Valida telefone brasileiro com DDD.
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
	return array( 'SP' => 'Sao Paulo' );
}

/**
 * Substituto minimo de WP_User.
 */
class WP_User {
	/** @var int */
	public $ID = 0;

	/** @var string */
	public $user_email = '';

	/** @var array<int,string> */
	public $roles = array();

	/** @var string */
	public $display_name = '';

	/**
	 * Monta um usuario de teste.
	 *
	 * @param int    $id    Identificador.
	 * @param string $email E-mail.
	 * @param array  $roles Roles.
	 */
	public function __construct( int $id, string $email, array $roles ) {
		$this->ID         = $id;
		$this->user_email = $email;
		$this->roles      = $roles;
	}
}

/**
 * Substituto minimo de WP_Error.
 */
class WP_Error {
	/** @var array<int,string> */
	public $codes = array();

	/** @var array<int,string> */
	public $messages = array();

	/**
	 * Aceita o mesmo construtor do WP_Error real.
	 *
	 * @param string $code    Codigo inicial, opcional.
	 * @param string $message Mensagem.
	 * @param mixed  $data    Dados, ignorados.
	 */
	public function __construct( $code = '', $message = '', $data = null ) {
		unset( $data );
		if ( '' !== (string) $code ) {
			$this->codes[]    = (string) $code;
			$this->messages[] = (string) $message;
		}
	}

	/**
	 * Registra um codigo de erro.
	 *
	 * @param string $code    Codigo.
	 * @param string $message Mensagem.
	 * @return void
	 */
	public function add( $code, $message = '' ) {
		$this->codes[]    = (string) $code;
		$this->messages[] = (string) $message;
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

/**
 * Detecta WP_Error.
 *
 * @param mixed $thing Valor.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Capacidade do usuario corrente.
 *
 * @param string $capability Capacidade.
 * @return bool
 */
function current_user_can( $capability ) {
	global $papelito_test_can_manage;
	return 'manage_options' === $capability ? (bool) $papelito_test_can_manage : false;
}

/**
 * Usuario corrente.
 *
 * @return int
 */
function get_current_user_id() {
	global $papelito_test_current_user;
	return (int) $papelito_test_current_user;
}

/**
 * Busca usuario por id.
 *
 * @param int $user_id Identificador.
 * @return WP_User|null
 */
function get_userdata( $user_id ) {
	global $papelito_test_users;
	return $papelito_test_users[ (int) $user_id ] ?? null;
}

/**
 * Busca usuario por campo.
 *
 * @param string $field Campo.
 * @param mixed  $value Valor.
 * @return WP_User|false
 */
function get_user_by( $field, $value ) {
	global $papelito_test_users;

	if ( 'email' !== $field ) {
		return false;
	}

	foreach ( $papelito_test_users as $user ) {
		if ( strtolower( $user->user_email ) === strtolower( (string) $value ) ) {
			return $user;
		}
	}

	return false;
}

/**
 * Le usermeta do store em memoria.
 *
 * @param int    $user_id Usuario.
 * @param string $key     Chave.
 * @param bool   $single  Unico.
 * @return mixed
 */
function get_user_meta( $user_id, $key, $single = false ) {
	global $papelito_test_meta;
	$values = $papelito_test_meta[ (int) $user_id ][ $key ] ?? array();

	if ( $single ) {
		return $values[0] ?? '';
	}

	return $values;
}

/**
 * Grava usermeta unico.
 *
 * @param int    $user_id Usuario.
 * @param string $key     Chave.
 * @param mixed  $value   Valor.
 * @return bool
 */
function update_user_meta( $user_id, $key, $value ) {
	global $papelito_test_meta;
	$papelito_test_meta[ (int) $user_id ][ $key ] = array( $value );
	return true;
}

/**
 * Acrescenta usermeta multivalorado.
 *
 * @param int    $user_id Usuario.
 * @param string $key     Chave.
 * @param mixed  $value   Valor.
 * @param bool   $unique  Unicidade.
 * @return bool
 */
function add_user_meta( $user_id, $key, $value, $unique = false ) {
	global $papelito_test_meta;
	unset( $unique );
	$papelito_test_meta[ (int) $user_id ][ $key ][] = $value;
	return true;
}

/**
 * Remove usermeta.
 *
 * @param int    $user_id Usuario.
 * @param string $key     Chave.
 * @return bool
 */
function delete_user_meta( $user_id, $key ) {
	global $papelito_test_meta;
	unset( $papelito_test_meta[ (int) $user_id ][ $key ] );
	return true;
}

/**
 * Atualiza campos do usuario.
 *
 * @param array $data Campos.
 * @return int|WP_Error
 */
function wp_update_user( $data ) {
	global $papelito_test_users;
	$user_id = (int) ( $data['ID'] ?? 0 );
	$user    = $papelito_test_users[ $user_id ] ?? null;

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'invalid_user', 'Usuario inexistente.' );
	}

	if ( isset( $data['user_email'] ) ) {
		$user->user_email = (string) $data['user_email'];
	}
	if ( isset( $data['display_name'] ) ) {
		$user->display_name = (string) $data['display_name'];
	}
	foreach ( array( 'first_name', 'last_name' ) as $meta_key ) {
		if ( isset( $data[ $meta_key ] ) ) {
			update_user_meta( $user_id, $meta_key, (string) $data[ $meta_key ] );
		}
	}

	return $user_id;
}

/**
 * Role do usuario.
 *
 * @param WP_User $user Usuario.
 * @param string  $role Role.
 * @return bool
 */
function papelito_user_has_role( WP_User $user, string $role ): bool {
	return in_array( $role, (array) $user->roles, true );
}

/**
 * Seller efetivo para o status da triagem.
 *
 * @param int $user_id Usuario.
 * @return bool
 */
function papelito_user_is_effective_seller( int $user_id ): bool {
	$user = get_userdata( $user_id );
	return $user instanceof WP_User && papelito_user_has_role( $user, 'seller' );
}

/**
 * Pagar.me configurada no harness.
 *
 * @return bool
 */
function papelito_pagarme_is_configured(): bool {
	return true;
}

/**
 * Registra a ressincronizacao do recebedor.
 *
 * @param int  $user_id Usuario.
 * @param bool $force   Forca.
 * @return void
 */
function papelito_pagarme_upsert_vendor_recipient( int $user_id, bool $force = false ): void {
	global $papelito_test_recipient_syncs;
	unset( $force );
	$papelito_test_recipient_syncs[] = $user_id;
}

/**
 * Substituto minimo de wpdb para a checagem de CNPJ duplicado.
 */
class Papelito_Test_Wpdb {
	/** @var string */
	public $usermeta = 'wp_usermeta';

	/**
	 * Interpola os parametros como o wpdb.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Argumentos.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . (string) $arg . "'", (string) $query, 1 );
		}

		return $query;
	}

	/**
	 * Devolve os CNPJs gravados no store em memoria.
	 *
	 * @param string $query  Query, ignorada.
	 * @param string $output Formato, ignorado.
	 * @return array<int,array<string,string>>
	 */
	public function get_results( $query, $output = null ) {
		global $papelito_test_meta;
		unset( $query, $output );
		$rows = array();

		foreach ( $papelito_test_meta as $user_id => $meta ) {
			$value = $meta['cnpj'][0] ?? '';
			if ( '' !== (string) $value ) {
				$rows[] = array(
					'user_id'    => (string) $user_id,
					'meta_value' => (string) $value,
				);
			}
		}

		return $rows;
	}
}

$GLOBALS['wpdb'] = new Papelito_Test_Wpdb();

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
	echo "  FAIL: {$label} -> esperado " . papelito_test_dump( $expected ) . ', obtido ' . papelito_test_dump( $actual ) . "\n";
}

/**
 * Serializa um valor para a mensagem de falha.
 *
 * @param mixed $value Valor.
 * @return string
 */
function papelito_test_dump( $value ): string {
	return is_scalar( $value ) || null === $value
		? var_export( $value, true )
		: (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Draft financeiro completo do vendor gravado.
 *
 * @return array<string,mixed>
 */
function papelito_test_stored_draft(): array {
	return array(
		'companyName'      => 'Papelaria Ana LTDA',
		'tradingName'      => 'Papelaria Ana',
		'corporationType'  => 'MEI',
		'foundingDate'     => '2020-01-15',
		'annualRevenue'    => '250000',
		'managingPartners' => array(
			array(
				'name'                            => 'Ana Souza',
				'email'                           => 'ana@example.com',
				'document'                        => '11144477735',
				'motherName'                      => 'Maria Souza',
				'birthdate'                       => '1990-05-04',
				'monthlyIncome'                   => '9000',
				'professionalOccupation'          => 'Empresaria',
				'selfDeclaredLegalRepresentative' => true,
				'address'                         => array(
					'zipCode'      => '01310930',
					'street'       => 'Avenida Paulista',
					'streetNumber' => '1000',
					'complement'   => 'Sala 2',
					'neighborhood' => 'Bela Vista',
					'city'         => 'Sao Paulo',
					'state'        => 'SP',
				),
			),
		),
		'bankAccount'      => array(
			'holderName'        => 'Papelaria Ana',
			'holderType'        => 'company',
			'holderDocument'    => '65.326.368/0001-90',
			'bankCode'          => '260',
			'branchNumber'      => '1',
			'branchCheckDigit'  => '',
			'accountNumber'     => '123456',
			'accountCheckDigit' => '7',
			'type'              => 'checking',
		),
		'transfer'         => array(
			'interval' => 'Daily',
			'day'      => '0',
		),
	);
}

/**
 * Representacao editavel completa, equivalente ao que o formulario envia.
 *
 * @return array<string,mixed>
 */
function papelito_test_full_payload(): array {
	return array(
		'application' => array(
			'step1'          => array(
				'storeName'        => 'Papelaria Ana',
				'firstName'        => 'Ana',
				'lastName'         => 'Souza',
				'cnpj'             => '65.326.368/0001-90',
				'phone'            => '(11) 99999-9999',
				'email'            => 'ana@example.com',
				'instagram'        => 'ana',
				'hasSoldPapelito'  => 'sim',
				'discoveryChannel' => 'Instagram',
			),
			'step2'          => array(
				'cep'          => '01310-930',
				'street'       => 'Avenida Paulista',
				'number'       => '1000',
				'complement'   => 'Sala 2',
				'neighborhood' => 'Bela Vista',
				'city'         => 'Sao Paulo',
				'state'        => 'SP',
			),
			'coverageRanges' => array(
				array(
					'minCep' => '01000-000',
					'maxCep' => '02000-000',
				),
			),
		),
		'draft'       => papelito_test_stored_draft(),
	);
}

/**
 * Recria o estado do vendor gravado antes de cada caso.
 *
 * @return void
 */
function papelito_test_reset_vendor(): void {
	global $papelito_test_users, $papelito_test_meta, $papelito_test_current_user;
	global $papelito_test_can_manage, $papelito_test_recipient_syncs, $papelito_test_actions;

	$papelito_test_users           = array(
		1  => new WP_User( 1, 'admin@papelito.test', array( 'administrator' ) ),
		42 => new WP_User( 42, 'ana@example.com', array( 'seller' ) ),
		77 => new WP_User( 77, 'cliente@example.com', array( 'customer' ) ),
	);
	$papelito_test_meta            = array(
		42 => array(
			'store_name'                                  => array( 'Papelaria Ana' ),
			'first_name'                                  => array( 'Ana' ),
			'last_name'                                   => array( 'Souza' ),
			'phone_number'                                => array( '(11) 99999-9999' ),
			'cnpj'                                        => array( '65.326.368/0001-90' ),
			'instagram'                                   => array( 'ana' ),
			'state'                                       => array( 'SP' ),
			'city'                                        => array( 'Sao Paulo' ),
			'cep'                                         => array( '01310930' ),
			'min_cep'                                     => array( '01000000' ),
			'max_cep'                                     => array( '02000000' ),
			PAPELITO_VENDOR_APPLICATION_STREET_META       => array( 'Avenida Paulista' ),
			PAPELITO_VENDOR_APPLICATION_NUMBER_META       => array( '1000' ),
			PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META   => array( 'Sala 2' ),
			PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META => array( 'Bela Vista' ),
			PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META  => array(
				(string) json_encode( papelito_test_stored_draft() ),
			),
		),
		77 => array(
			'cnpj' => array( '' ),
		),
	);
	$papelito_test_current_user    = 1;
	$papelito_test_can_manage      = true;
	$papelito_test_recipient_syncs = array();
	$papelito_test_actions         = array();
}

/**
 * Le o draft gravado do vendor.
 *
 * @param int $user_id Usuario.
 * @return array<string,mixed>
 */
function papelito_test_read_draft( int $user_id ): array {
	$raw     = (string) get_user_meta( $user_id, PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META, true );
	$decoded = json_decode( $raw, true );

	return is_array( $decoded ) ? $decoded : array();
}

echo "Autorizacao\n";

papelito_test_reset_vendor();
$papelito_test_can_manage = false;
$result                   = papelito_admin_vendors_update_vendor( 42, papelito_test_full_payload(), 1 );
papelito_assert(
	'nao-admin e recusado com papelito_admin_forbidden',
	'papelito_admin_forbidden',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);
papelito_assert(
	'nao-admin nao altera a loja gravada',
	'Papelaria Ana',
	(string) get_user_meta( 42, 'store_name', true )
);

papelito_test_reset_vendor();
$result = papelito_admin_vendors_update_vendor( 42, papelito_test_full_payload(), 999 );
papelito_assert(
	'reviewer diferente do usuario logado e recusado',
	'papelito_admin_forbidden',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);

echo "\nAlvo da operacao\n";

papelito_test_reset_vendor();
$result = papelito_admin_vendors_update_vendor( 77, papelito_test_full_payload(), 1 );
papelito_assert(
	'customer comum nao e tratado como vendor',
	'papelito_vendor_not_found',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);
papelito_assert(
	'customer recusado continua sem role seller',
	array( 'customer' ),
	get_userdata( 77 )->roles
);
papelito_assert(
	'customer recusado nao recebe store_name',
	'',
	(string) get_user_meta( 77, 'store_name', true )
);

papelito_test_reset_vendor();
$result = papelito_admin_vendors_update_vendor( 4242, papelito_test_full_payload(), 1 );
papelito_assert(
	'vendor inexistente devolve 404 de dominio',
	'papelito_vendor_not_found',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);

echo "\nPayload invalido\n";

papelito_test_reset_vendor();
$result = papelito_admin_vendors_update_vendor( 42, array( 'draft' => papelito_test_stored_draft() ), 1 );
papelito_assert(
	'payload sem application e recusado',
	'papelito_admin_vendor_missing_application',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);

papelito_test_reset_vendor();
$payload                                 = papelito_test_full_payload();
$payload['application']['step1']['cnpj'] = '65.326.368/0001-91';
$result                                  = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert(
	'CNPJ com digito fabricado e recusado',
	'papelito_vendor_invalid_cnpj',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);

papelito_test_reset_vendor();
$payload                                 = papelito_test_full_payload();
$payload['application']['step2']['city'] = '';
$result                                  = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert(
	'endereco comercial incompleto e recusado',
	'papelito_vendor_incomplete_address',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);
papelito_assert(
	'validacao rejeitada nao apaga a cidade gravada',
	'Sao Paulo',
	(string) get_user_meta( 42, 'city', true )
);

papelito_test_reset_vendor();
$payload                                  = papelito_test_full_payload();
$payload['application']['step1']['phone'] = '0000000000';
$result                                   = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert(
	'telefone sem DDD valido e recusado',
	'papelito_vendor_invalid_phone',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);

echo "\nAtualizacao de um campo\n";

papelito_test_reset_vendor();
$payload                                      = papelito_test_full_payload();
$payload['application']['step1']['storeName'] = 'Papelaria Nova';
$result                                       = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert( 'atualizacao valida nao devolve erro', false, is_wp_error( $result ) );
papelito_assert( 'nome da loja e gravado', 'Papelaria Nova', (string) get_user_meta( 42, 'store_name', true ) );
papelito_assert( 'CNPJ nao alterado permanece', '65.326.368/0001-90', (string) get_user_meta( 42, 'cnpj', true ) );
papelito_assert( 'endereco nao alterado permanece', 'Avenida Paulista', (string) get_user_meta( 42, PAPELITO_VENDOR_APPLICATION_STREET_META, true ) );
papelito_assert( 'conta bancaria nao alterada permanece', '123456', (string) ( papelito_test_read_draft( 42 )['bankAccount']['accountNumber'] ?? '' ) );
papelito_assert( 'CPF do socio nao alterado permanece', '11144477735', (string) ( papelito_test_read_draft( 42 )['managingPartners'][0]['document'] ?? '' ) );
papelito_assert(
	'resposta devolve o estado atualizado',
	'Papelaria Nova',
	(string) ( $result['application']['step1']['storeName'] ?? '' )
);
papelito_assert( 'cadastro completo nao deixa pendencias', array(), $result['pendingFields'] ?? null );
papelito_assert( 'recebedor Pagar.me e ressincronizado', array( 42 ), $GLOBALS['papelito_test_recipient_syncs'] );
papelito_assert(
	'a edicao nao redispara a aprovacao do vendor',
	array(),
	array_values( array_filter( $GLOBALS['papelito_test_actions'], static fn ( $entry ) => 'papelito_vendor_approved' === $entry['hook'] ) )
);

echo "\nBlocos ausentes e limpeza explicita\n";

papelito_test_reset_vendor();
$payload = papelito_test_full_payload();
unset( $payload['draft']['bankAccount'] );
$result = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert( 'draft sem bankAccount nao devolve erro', false, is_wp_error( $result ) );
papelito_assert(
	'bloco bancario ausente preserva a conta gravada',
	'123456',
	(string) ( papelito_test_read_draft( 42 )['bankAccount']['accountNumber'] ?? '' )
);
papelito_assert(
	'bloco bancario ausente preserva o banco gravado',
	'260',
	(string) ( papelito_test_read_draft( 42 )['bankAccount']['bankCode'] ?? '' )
);

papelito_test_reset_vendor();
$payload = papelito_test_full_payload();
unset( $payload['draft'] );
$result = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert( 'payload sem draft nenhum nao devolve erro', false, is_wp_error( $result ) );
papelito_assert(
	'draft ausente por inteiro preserva a razao social',
	'Papelaria Ana LTDA',
	(string) ( papelito_test_read_draft( 42 )['companyName'] ?? '' )
);
papelito_assert(
	'draft ausente por inteiro preserva a conta',
	'123456',
	(string) ( papelito_test_read_draft( 42 )['bankAccount']['accountNumber'] ?? '' )
);

papelito_test_reset_vendor();
$payload = papelito_test_full_payload();
$payload['draft']['managingPartners'][0]['motherName'] = '';
$result = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert( 'limpeza explicita nao devolve erro', false, is_wp_error( $result ) );
papelito_assert(
	'string vazia limpa o campo opcional',
	'',
	(string) ( papelito_test_read_draft( 42 )['managingPartners'][0]['motherName'] ?? 'nao limpou' )
);

echo "\nCampos protegidos\n";

papelito_test_reset_vendor();
$payload                               = papelito_test_full_payload();
$payload['id']                         = 999;
$payload['sourceUserId']               = 77;
$payload['temporaryPassword']          = 'senha-forjada';
$payload['role']                       = 'administrator';
$payload['application']['step1']['id'] = 999;
$result                                = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert( 'payload com campos protegidos nao devolve erro', false, is_wp_error( $result ) );
papelito_assert(
	'o vendor editado continua sendo o do path, nao o id do payload',
	'ana@example.com',
	(string) get_userdata( 42 )->user_email
);
papelito_assert(
	'o id forjado no payload nao cria nem toca outra conta',
	null,
	get_userdata( 999 )
);
papelito_assert( 'role do vendor permanece seller', array( 'seller' ), get_userdata( 42 )->roles );
papelito_assert( 'role do admin nao e concedida ao vendor', false, in_array( 'administrator', get_userdata( 42 )->roles, true ) );
papelito_assert(
	'senha forjada nao vira usermeta',
	'',
	(string) get_user_meta( 42, 'temporaryPassword', true )
);
papelito_assert(
	'customer alvo de sourceUserId permanece intocado',
	array( 'customer' ),
	get_userdata( 77 )->roles
);

echo "\nCNPJ duplicado\n";

papelito_test_reset_vendor();
$GLOBALS['papelito_test_meta'][77]['cnpj'] = array( '11.222.333/0001-81' );
$payload                                   = papelito_test_full_payload();
$payload['application']['step1']['cnpj']   = '11.222.333/0001-81';
$result                                    = papelito_admin_vendors_update_vendor( 42, $payload, 1 );
papelito_assert(
	'CNPJ de outra conta e recusado',
	'papelito_vendor_cnpj_exists',
	is_wp_error( $result ) ? $result->get_error_code() : 'sem erro'
);

papelito_test_reset_vendor();
$result = papelito_admin_vendors_update_vendor( 42, papelito_test_full_payload(), 1 );
papelito_assert(
	'o proprio CNPJ do vendor nao conflita consigo mesmo',
	false,
	is_wp_error( $result )
);

echo "\nLeitura da representacao editavel\n";

papelito_test_reset_vendor();
$registration = papelito_admin_vendors_get_registration( 42 );
papelito_assert( 'leitura do vendor nao devolve erro', false, is_wp_error( $registration ) );
papelito_assert(
	'leitura traz o endereco completo do step2',
	'Sala 2',
	(string) ( $registration['application']['step2']['complement'] ?? '' )
);
papelito_assert(
	'leitura traz as faixas de cobertura',
	array(
		array(
			'minCep' => '01000000',
			'maxCep' => '02000000',
		),
	),
	$registration['application']['coverageRanges'] ?? null
);
papelito_assert(
	'leitura traz o draft financeiro',
	'123456',
	(string) ( $registration['draft']['bankAccount']['accountNumber'] ?? '' )
);

papelito_test_reset_vendor();
$registration = papelito_admin_vendors_get_registration( 77 );
papelito_assert(
	'leitura de customer comum devolve 404 de dominio',
	'papelito_vendor_not_found',
	is_wp_error( $registration ) ? $registration->get_error_code() : 'sem erro'
);

echo "\n";

if ( $failures > 0 ) {
	echo "FALHOU: {$failures} caso(s).\n";
	exit( 1 );
}

echo "OK: todos os casos passaram.\n";
