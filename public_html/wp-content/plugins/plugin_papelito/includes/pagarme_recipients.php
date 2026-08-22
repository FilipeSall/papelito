<?php
/**
 * Recebedores Pagar.me por vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PAGARME_RECIPIENT_ID_META' ) ) {
	define( 'PAPELITO_PAGARME_RECIPIENT_ID_META', 'papelito_pagarme_recipient_id' );
	define( 'PAPELITO_PAGARME_RECIPIENT_STATUS_META', 'papelito_pagarme_recipient_status' );
	define( 'PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META', 'papelito_pagarme_recipient_last_sync_at' );
	define( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META', 'papelito_pagarme_recipient_last_error' );
	define( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META', 'papelito_pagarme_recipient_last_error_detail' );
	define( 'PAPELITO_PAGARME_RECIPIENT_KYC_URL_META', 'papelito_pagarme_recipient_kyc_url' );
}

/**
 * Retorna o recipient_id atual do vendor.
 */
function papelito_pagarme_get_vendor_recipient_id( int $user_id ): string {
	return sanitize_text_field( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_ID_META, true ) );
}

/**
 * Retorna o status atual do recebedor do vendor.
 */
function papelito_pagarme_get_vendor_recipient_status( int $user_id ): string {
	return sanitize_key( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_STATUS_META, true ) );
}

/**
 * Indica se o recebedor esta ativo.
 */
function papelito_pagarme_vendor_recipient_is_active( int $user_id ): bool {
	return 'active' === papelito_pagarme_get_vendor_recipient_status( $user_id );
}

/**
 * Persiste o estado do recebedor no usermeta.
 *
 * @param array<string,mixed> $recipient Resposta Pagar.me.
 */
function papelito_pagarme_save_vendor_recipient_state( int $user_id, array $recipient ): array {
	$recipient_id = sanitize_text_field( (string) ( $recipient['id'] ?? '' ) );
	$status       = sanitize_key( (string) ( $recipient['status'] ?? '' ) );
	$kyc_url      = sanitize_url( (string) ( $recipient['kyc_details']['url'] ?? $recipient['kyc_url'] ?? '' ) );

	if ( '' !== $recipient_id ) {
		update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_ID_META, $recipient_id );
	}

	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_STATUS_META, $status );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META, papelito_current_utc_mysql() );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META, '' );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META, '' );

	if ( '' !== $kyc_url ) {
		update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_KYC_URL_META, $kyc_url );
	}

	return papelito_pagarme_get_vendor_recipient_state( $user_id );
}

/**
 * Persiste ultimo erro de sincronizacao do recebedor.
 *
 * Quando recebe o WP_Error completo, guarda tambem os detalhes crus de
 * validacao do Pagar.me (`pagarme_body`) num meta separado para diagnostico.
 *
 * @param int             $user_id Usuario.
 * @param WP_Error|string $error   Erro ou mensagem.
 */
function papelito_pagarme_save_vendor_recipient_error( int $user_id, $error ): void {
	$message = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;

	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META, papelito_current_utc_mysql() );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META, sanitize_text_field( $message ) );

	$detail = '';

	if ( $error instanceof WP_Error ) {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['pagarme_body'] ) && is_array( $data['pagarme_body'] ) ) {
			$encoded = wp_json_encode( $data['pagarme_body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$detail  = is_string( $encoded ) ? $encoded : '';
		}
	}

	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META, $detail );
}

/**
 * Monta a resposta REST de erro do recebedor sem vazar detalhe tecnico.
 *
 * So retorna o codigo estavel do erro (usado pelo front para mapear uma
 * mensagem amigavel) e o status HTTP. O corpo cru do gateway (`response_body`,
 * `pagarme_body`) e os detalhes de validacao (`agencia | Value too long`, etc.)
 * ficam apenas nos logs / no meta `last_error_detail`, nunca na resposta REST.
 *
 * @param WP_Error $error Erro original.
 * @return WP_Error
 */
function papelito_pagarme_recipient_error_response( WP_Error $error ): WP_Error {
	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

	return new WP_Error(
		$error->get_error_code(),
		'Não foi possível validar os dados do recebedor.',
		array( 'status' => $status )
	);
}

/**
 * Retorna o estado serializado do recebedor.
 *
 * @return array<string,string>
 */
function papelito_pagarme_get_vendor_recipient_state( int $user_id ): array {
	return array(
		'recipient_id' => papelito_pagarme_get_vendor_recipient_id( $user_id ),
		'status'       => papelito_pagarme_get_vendor_recipient_status( $user_id ),
		'last_sync_at' => sanitize_text_field( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META, true ) ),
		'kyc_url'      => sanitize_url( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_KYC_URL_META, true ) ),
		'last_error'   => sanitize_text_field( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META, true ) ),
	);
}

/**
 * Normaliza telefone brasileiro para o payload do Pagar.me.
 *
 * @return array<string,string>
 */
function papelito_pagarme_phone_payload( string $value ): array {
	$normalized = function_exists( 'papelito_auth_normalize_phone' )
		? papelito_auth_normalize_phone( $value )
		: preg_replace( '/\D+/', '', $value );

	$digits = is_string( $normalized ) ? $normalized : '';

	if ( strlen( $digits ) < 10 ) {
		return array(
			'country_code' => '55',
			'area_code'    => '11',
			'number'       => '000000000',
		);
	}

	return array(
		'country_code' => '55',
		'area_code'    => substr( $digits, 0, 2 ),
		'number'       => substr( $digits, 2 ),
	);
}

/**
 * Normaliza um endereco para o contrato do Pagar.me.
 *
 * @param array<string,mixed> $input Dados crus.
 * @return array<string,string>
 */
function papelito_pagarme_address_payload( array $input ): array {
	$street       = sanitize_text_field( (string) ( $input['street'] ?? $input['line_1'] ?? '' ) );
	$number       = sanitize_text_field( (string) ( $input['number'] ?? '' ) );
	$complement   = sanitize_text_field( (string) ( $input['complement'] ?? $input['complementary'] ?? '' ) );
	$neighborhood = sanitize_text_field( (string) ( $input['neighborhood'] ?? '' ) );

	$line_1 = trim( $street . ( '' !== $number ? ', ' . $number : '' ) . ( '' !== $neighborhood ? ', ' . $neighborhood : '' ) );
	$line_2 = trim( $complement );

	return array(
		'line_1'     => $line_1,
		'line_2'     => $line_2,
		'zip_code'   => function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['zipCode'] ?? $input['zip_code'] ?? '' ) ) : preg_replace( '/\D+/', '', (string) ( $input['zipCode'] ?? $input['zip_code'] ?? '' ) ),
		'city'       => sanitize_text_field( (string) ( $input['city'] ?? '' ) ),
		'state'      => strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ),
		'country'    => 'BR',
	);
}

/**
 * Normaliza um endereco para o contrato de recebedor do Pagar.me.
 *
 * Diferente de papelito_pagarme_address_payload() (usada no objeto `customer`
 * de pedidos, que aceita line_1/line_2), o `register_information` do recebedor
 * exige os campos separados.
 *
 * @param array<string,mixed> $input Dados crus.
 * @return array<string,string>
 */
function papelito_pagarme_recipient_address_payload( array $input ): array {
	$zip_raw       = (string) ( $input['zipCode'] ?? $input['zip_code'] ?? '' );
	$complementary = sanitize_text_field( (string) ( $input['complement'] ?? $input['complementary'] ?? '' ) );
	$reference     = sanitize_text_field( (string) ( $input['referencePoint'] ?? $input['reference_point'] ?? '' ) );

	return array(
		'street'          => sanitize_text_field( (string) ( $input['street'] ?? $input['line_1'] ?? '' ) ),
		'street_number'   => sanitize_text_field( (string) ( $input['number'] ?? $input['streetNumber'] ?? $input['street_number'] ?? '' ) ),
		'complementary'   => '' !== $complementary ? $complementary : 'N/A',
		'neighborhood'    => sanitize_text_field( (string) ( $input['neighborhood'] ?? '' ) ),
		'reference_point' => '' !== $reference ? $reference : 'N/A',
		'zip_code'        => function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( $zip_raw ) : preg_replace( '/\D+/', '', $zip_raw ),
		'city'            => sanitize_text_field( (string) ( $input['city'] ?? '' ) ),
		'state'           => strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ),
	);
}

/**
 * Normaliza telefone brasileiro para o contrato de recebedor do Pagar.me.
 *
 * O recebedor espera { ddd, number, type }, diferente do objeto `customer`
 * de pedidos (country_code/area_code/number) montado por
 * papelito_pagarme_phone_payload().
 *
 * @return array<string,string>
 */
function papelito_pagarme_recipient_phone_payload( string $value ): array {
	$normalized = function_exists( 'papelito_auth_normalize_phone' )
		? papelito_auth_normalize_phone( $value )
		: preg_replace( '/\D+/', '', $value );

	$digits = is_string( $normalized ) ? $normalized : '';

	if ( strlen( $digits ) < 10 ) {
		return array(
			'ddd'    => '11',
			'number' => '000000000',
			'type'   => 'mobile',
		);
	}

	return array(
		'ddd'    => substr( $digits, 0, 2 ),
		'number' => substr( $digits, 2 ),
		'type'   => 'mobile',
	);
}

/**
 * Monta o payload de um parceiro administrador.
 *
 * @param array<string,mixed> $partner       Dados do draft.
 * @param string              $fallback_phone Telefone do vendor (o wizard nao coleta o do socio).
 * @return array<string,mixed>
 */
function papelito_pagarme_partner_payload( array $partner, string $fallback_phone = '' ): array {
	$document = preg_replace( '/\D+/', '', (string) ( $partner['document'] ?? '' ) );
	$phone    = (string) ( $partner['phone'] ?? $partner['phoneNumber'] ?? '' );

	if ( '' === trim( $phone ) ) {
		$phone = $fallback_phone;
	}

	return array(
		'name'                             => sanitize_text_field( (string) ( $partner['name'] ?? '' ) ),
		'email'                            => sanitize_email( (string) ( $partner['email'] ?? '' ) ),
		'document'                         => $document,
		'type'                             => 'individual',
		'mother_name'                      => sanitize_text_field( (string) ( $partner['motherName'] ?? '' ) ),
		'birthdate'                        => sanitize_text_field( (string) ( $partner['birthdate'] ?? '' ) ),
		'monthly_income'                   => (int) round( (float) str_replace( ',', '.', preg_replace( '/[^\d,.-]/', '', (string) ( $partner['monthlyIncome'] ?? '0' ) ) ) ),
		'professional_occupation'          => sanitize_text_field( (string) ( $partner['professionalOccupation'] ?? '' ) ),
		'self_declared_legal_representative' => ! empty( $partner['selfDeclaredLegalRepresentative'] ),
		'phone_numbers'                    => array(
			papelito_pagarme_recipient_phone_payload( $phone ),
		),
		'address'                          => papelito_pagarme_recipient_address_payload( isset( $partner['address'] ) && is_array( $partner['address'] ) ? $partner['address'] : array() ),
	);
}

/**
 * Monta o payload completo de criacao/edicao do recebedor.
 *
 * @return array<string,mixed>|WP_Error
 */
/**
 * Normaliza a conta bancaria do vendor para o contrato `default_bank_account`.
 *
 * Os digitos verificadores (`branch_check_digit`/`account_check_digit`) sao
 * OMITIDOS quando vazios: contas como o Nubank (agencia 0001) nao tem digito de
 * agencia, e a Pagar.me rejeita a string vazia com
 * "invalid_parameter | agencia_dv | Invalid format".
 *
 * @param array<string,mixed> $bank_account Dados crus do draft (`bankAccount`).
 * @param string              $fallback_holder_name Nome usado quando holderName vazio.
 * @param string              $fallback_document   CNPJ usado quando holderDocument vazio.
 * @return array<string,string>
 */
function papelito_pagarme_bank_account_payload( array $bank_account, string $fallback_holder_name, string $fallback_document ): array {
	// A agencia (branch_number) da Pagar.me aceita no maximo 4 digitos; valores
	// mais longos disparam "invalid_parameter | agencia | Value too long".
	$branch_number = substr( preg_replace( '/\D+/', '', (string) ( $bank_account['branchNumber'] ?? '' ) ), 0, 4 );

	$payload = array(
		'holder_name'     => sanitize_text_field( (string) ( $bank_account['holderName'] ?? $fallback_holder_name ) ),
		'holder_type'     => sanitize_text_field( (string) ( $bank_account['holderType'] ?? 'company' ) ),
		'holder_document' => preg_replace( '/\D+/', '', (string) ( $bank_account['holderDocument'] ?? $fallback_document ) ),
		'bank'            => sanitize_text_field( (string) ( $bank_account['bankCode'] ?? '' ) ),
		'branch_number'   => $branch_number,
		'account_number'  => sanitize_text_field( (string) ( $bank_account['accountNumber'] ?? '' ) ),
		'type'            => sanitize_text_field( (string) ( $bank_account['type'] ?? 'checking' ) ),
	);

	$branch_check_digit  = sanitize_text_field( (string) ( $bank_account['branchCheckDigit'] ?? '' ) );
	$account_check_digit = sanitize_text_field( (string) ( $bank_account['accountCheckDigit'] ?? '' ) );

	if ( '' !== $branch_check_digit ) {
		$payload['branch_check_digit'] = $branch_check_digit;
	}

	if ( '' !== $account_check_digit ) {
		$payload['account_check_digit'] = $account_check_digit;
	}

	return $payload;
}

function papelito_pagarme_build_recipient_payload( int $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$draft = function_exists( 'papelito_get_vendor_pagarme_recipient_draft' )
		? papelito_get_vendor_pagarme_recipient_draft( $user_id )
		: null;

	if ( ! is_array( $draft ) ) {
		return new WP_Error(
			'papelito_pagarme_missing_draft',
			'Os dados financeiros do vendor ainda não foram preenchidos.',
			array( 'status' => 422 )
		);
	}

	$store_name = sanitize_text_field( (string) get_user_meta( $user_id, 'store_name', true ) );
	$phone      = sanitize_text_field( (string) get_user_meta( $user_id, 'phone_number', true ) );
	$cnpj       = preg_replace( '/\D+/', '', (string) get_user_meta( $user_id, 'cnpj', true ) );
	$address    = array(
		'street'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, true ),
		'number'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, true ),
		'complement'   => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, true ),
		'neighborhood' => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, true ),
		'city'         => (string) get_user_meta( $user_id, 'city', true ),
		'state'        => (string) get_user_meta( $user_id, 'state', true ),
		'zip_code'     => (string) get_user_meta( $user_id, 'cep', true ),
	);

	$partners     = isset( $draft['managingPartners'] ) && is_array( $draft['managingPartners'] ) ? $draft['managingPartners'] : array();
	$bank_account = isset( $draft['bankAccount'] ) && is_array( $draft['bankAccount'] ) ? $draft['bankAccount'] : array();
	$transfer     = isset( $draft['transfer'] ) && is_array( $draft['transfer'] ) ? $draft['transfer'] : array();

	if ( '' === $cnpj ) {
		return new WP_Error(
			'papelito_pagarme_missing_document',
			'O vendor precisa ter CNPJ válido para criar o recebedor.',
			array( 'status' => 422 )
		);
	}

	if ( empty( $partners ) || ! is_array( $partners[0] ) ) {
		return new WP_Error(
			'papelito_pagarme_missing_partner',
			'Informe ao menos um administrador responsável pelo recebedor.',
			array( 'status' => 422 )
		);
	}

	$main_address = papelito_pagarme_recipient_address_payload( $address );
	$partner      = papelito_pagarme_partner_payload( $partners[0], $phone );

	if ( '' === $main_address['street'] || '' === $main_address['street_number'] || '' === $main_address['zip_code'] || '' === $main_address['city'] || '' === $main_address['state'] ) {
		return new WP_Error(
			'papelito_pagarme_missing_address',
			'O vendor precisa ter endereço comercial completo para criar o recebedor.',
			array( 'status' => 422 )
		);
	}

	$company_name = sanitize_text_field( (string) ( $draft['companyName'] ?? $store_name ) );

	// A Pagar.me exige razao social com pelo menos alguns caracteres; nomes muito
	// curtos disparam "invalid_parameter | legal_name | Value too short".
	if ( mb_strlen( trim( $company_name ) ) < 5 ) {
		return new WP_Error(
			'papelito_pagarme_invalid_company_name',
			'A razao social do recebedor precisa ter ao menos 5 caracteres.',
			array( 'status' => 422 )
		);
	}

	return array(
		'code'                 => sprintf( 'vendor-%d', $user_id ),
		'payment_mode'         => 'bank_transfer',
		'transfer_settings'    => array(
			'transfer_enabled'  => true,
			'transfer_interval' => sanitize_text_field( (string) ( $transfer['interval'] ?? 'Daily' ) ),
			'transfer_day'      => (int) ( $transfer['day'] ?? 0 ),
		),
		'default_bank_account' => papelito_pagarme_bank_account_payload( $bank_account, $store_name, $cnpj ),
		'register_information' => array(
			'type'               => 'corporation',
			'company_name'       => $company_name,
			'trading_name'       => sanitize_text_field( (string) ( $draft['tradingName'] ?? $store_name ) ),
			'email'              => sanitize_email( (string) $user->user_email ),
			'document'           => $cnpj,
			'corporation_type'   => sanitize_text_field( (string) ( $draft['corporationType'] ?? '' ) ),
			'founding_date'      => sanitize_text_field( (string) ( $draft['foundingDate'] ?? '' ) ),
			'annual_revenue'     => (int) round( (float) str_replace( ',', '.', preg_replace( '/[^\d,.-]/', '', (string) ( $draft['annualRevenue'] ?? '0' ) ) ) ),
			'phone_numbers'      => array(
				papelito_pagarme_recipient_phone_payload( $phone ),
			),
			'main_address'       => $main_address,
			'managing_partners'  => array( $partner ),
		),
		'metadata'             => array(
			'user_id'    => (string) $user_id,
			'store_name' => $store_name,
		),
	);
}

/**
 * Monta o payload da conta bancaria do recebedor.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pagarme_build_recipient_bank_account_payload( int $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$draft = function_exists( 'papelito_get_vendor_pagarme_recipient_draft' )
		? papelito_get_vendor_pagarme_recipient_draft( $user_id )
		: null;

	if ( ! is_array( $draft ) ) {
		return new WP_Error(
			'papelito_pagarme_missing_draft',
			'Os dados financeiros do vendor ainda não foram preenchidos.',
			array( 'status' => 422 )
		);
	}

	$store_name   = sanitize_text_field( (string) get_user_meta( $user_id, 'store_name', true ) );
	$cnpj         = preg_replace( '/\D+/', '', (string) get_user_meta( $user_id, 'cnpj', true ) );
	$bank_account = isset( $draft['bankAccount'] ) && is_array( $draft['bankAccount'] ) ? $draft['bankAccount'] : array();

	return papelito_pagarme_bank_account_payload( $bank_account, $store_name, $cnpj );
}

/**
 * Atualiza a conta bancaria padrao do recebedor.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_update_vendor_recipient_bank_account( int $user_id, string $recipient_id ) {
	$bank_account = papelito_pagarme_build_recipient_bank_account_payload( $user_id );

	if ( is_wp_error( $bank_account ) ) {
		return $bank_account;
	}

	return papelito_pagarme_request(
		'PATCH',
		'recipients/' . rawurlencode( $recipient_id ) . '/default-bank-account',
		array(
			'bank_account' => $bank_account,
			'payment_mode' => 'bank_transfer',
		)
	);
}

/**
 * Sincroniza o recebedor ja criado.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pagarme_sync_vendor_recipient( int $user_id ) {
	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $user_id );

	if ( '' === $recipient_id ) {
		return new WP_Error(
			'papelito_pagarme_missing_recipient',
			'O vendor ainda não possui recebedor criado.',
			array( 'status' => 404 )
		);
	}

	$result = papelito_pagarme_request( 'GET', 'recipients/' . rawurlencode( $recipient_id ) );

	if ( is_wp_error( $result ) ) {
		papelito_pagarme_save_vendor_recipient_error( $user_id, $result );
		return $result;
	}

	return papelito_pagarme_save_vendor_recipient_state( $user_id, $result );
}

/**
 * Gera ou atualiza o link de KYC.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pagarme_refresh_vendor_kyc_link( int $user_id ) {
	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $user_id );

	if ( '' === $recipient_id ) {
		return new WP_Error(
			'papelito_pagarme_missing_recipient',
			'O vendor ainda não possui recebedor criado.',
			array( 'status' => 404 )
		);
	}

	$result = papelito_pagarme_request( 'POST', 'recipients/' . rawurlencode( $recipient_id ) . '/kyc_link', array() );

	if ( is_wp_error( $result ) ) {
		papelito_pagarme_save_vendor_recipient_error( $user_id, $result );
		return $result;
	}

	$url = sanitize_url( (string) ( $result['url'] ?? '' ) );
	if ( '' !== $url ) {
		update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_KYC_URL_META, $url );
	}

	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META, papelito_current_utc_mysql() );

	return papelito_pagarme_get_vendor_recipient_state( $user_id );
}

/**
 * Cria ou atualiza o recebedor do vendor.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pagarme_upsert_vendor_recipient( int $user_id, bool $refresh_kyc = false ) {
	$payload = papelito_pagarme_build_recipient_payload( $user_id );

	if ( is_wp_error( $payload ) ) {
		papelito_pagarme_save_vendor_recipient_error( $user_id, $payload );
		return $payload;
	}

	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $user_id );
	$path         = '' === $recipient_id ? 'recipients' : 'recipients/' . rawurlencode( $recipient_id );
	$method       = '' === $recipient_id ? 'POST' : 'PUT';
	$body         = $payload;

	if ( 'PUT' === $method ) {
		$body = array(
			'register_information' => $payload['register_information'],
			'metadata'             => $payload['metadata'],
		);
	}

	$result = papelito_pagarme_request( $method, $path, $body );

	if ( is_wp_error( $result ) ) {
		papelito_pagarme_save_vendor_recipient_error( $user_id, $result );
		return $result;
	}

	if ( 'PUT' === $method ) {
		$bank_update = papelito_pagarme_update_vendor_recipient_bank_account( $user_id, $recipient_id );

		if ( is_wp_error( $bank_update ) ) {
			papelito_pagarme_save_vendor_recipient_error( $user_id, $bank_update );
			return $bank_update;
		}
	}

	$state = papelito_pagarme_save_vendor_recipient_state( $user_id, $result );

	if ( $refresh_kyc && '' !== papelito_pagarme_get_vendor_recipient_id( $user_id ) ) {
		$kyc_state = papelito_pagarme_refresh_vendor_kyc_link( $user_id );

		if ( ! is_wp_error( $kyc_state ) ) {
			$state = $kyc_state;
		}
	}

	return $state;
}

/**
 * Auto-cria recebedor apos aprovacao do vendor.
 */
function papelito_pagarme_handle_vendor_approved( int $user_id ): void {
	if ( $user_id <= 0 || ! papelito_pagarme_is_configured() ) {
		return;
	}

	$payload = papelito_pagarme_build_recipient_payload( $user_id );
	if ( is_wp_error( $payload ) ) {
		return;
	}

	papelito_pagarme_upsert_vendor_recipient( $user_id, true );
}
add_action( 'papelito_vendor_approved', 'papelito_pagarme_handle_vendor_approved', 20, 1 );

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/vendor/recipient',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function () {
						$check = function_exists( 'papelito_vendor_dashboard_require_seller' )
							? papelito_vendor_dashboard_require_seller()
							: false;
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function () {
						$state = papelito_pagarme_get_vendor_recipient_state( get_current_user_id() );

						if ( '' !== $state['recipient_id'] ) {
							$synced = papelito_pagarme_sync_vendor_recipient( get_current_user_id() );
							if ( ! is_wp_error( $synced ) ) {
								$state = $synced;
							}
						}

						return new WP_REST_Response( $state, 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => static function () {
						$check = function_exists( 'papelito_vendor_dashboard_require_seller' )
							? papelito_vendor_dashboard_require_seller()
							: false;
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$body        = $request->get_json_params();
						$refresh_kyc = is_array( $body ) && ! empty( $body['refresh_kyc'] );
						$result      = papelito_pagarme_upsert_vendor_recipient( get_current_user_id(), $refresh_kyc );

						if ( is_wp_error( $result ) ) {
							return papelito_pagarme_recipient_error_response( $result );
						}

						return new WP_REST_Response( $result, 200 );
					},
				),
			)
		);
	}
);

/**
 * Metade financeira da dupla aprovacao do vendor.
 *
 * Vender exige faixa de CEP que cubra o destino E recebedor `active`. Fica aqui, junto do resto
 * da regra de recebedor, e nao no filtro de catalogo, para que a origem da decisao seja obvia.
 * O `function_exists` do lado do chamador garante que uma ordem de carregamento diferente nao
 * esvazie o catalogo inteiro.
 *
 * @param int $vendor_id Vendor.
 * @return bool
 */
function papelito_vendor_can_receive_payments( int $vendor_id ): bool {
	return papelito_pagarme_vendor_recipient_is_active( $vendor_id );
}
