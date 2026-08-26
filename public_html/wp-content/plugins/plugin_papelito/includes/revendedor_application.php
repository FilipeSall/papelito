<?php
/**
 * Fluxo de triagem do programa de revendedores.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PAPELITO_VENDOR_APPLICATION_STATUS_META             = 'application_status';
const PAPELITO_VENDOR_APPLICATION_INCOMPLETE_STATUS       = 'incomplete';
const PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META   = 'application_rejection_reason';
const PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META        = 'application_reviewed_by';
const PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META        = 'application_reviewed_at';
const PAPELITO_VENDOR_APPLICATION_SUBMITTED_AT_META       = 'application_submitted_at';
const PAPELITO_VENDOR_APPLICATION_DISCOVERY_CHANNEL_META  = 'seller_application_discovery_channel';
const PAPELITO_ADMIN_VENDOR_DISCOVERY_CHANNEL_DEFAULT     = 'Criado pelo painel admin';
const PAPELITO_ADMIN_VENDOR_HAS_SOLD_PAPELITO_DEFAULT     = 'nao_informado';
const PAPELITO_VENDOR_APPLICATION_HAS_SOLD_PAPELITO_META  = 'seller_application_has_sold_papelito';
const PAPELITO_VENDOR_APPLICATION_STREET_META             = 'seller_application_street';
const PAPELITO_VENDOR_APPLICATION_NUMBER_META             = 'seller_application_number';
const PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META         = 'seller_application_complement';
const PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META       = 'seller_application_neighborhood';
const PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META        = 'papelito_pagarme_recipient_draft';
const PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_UPDATED_AT  = 'papelito_pagarme_recipient_draft_updated_at';
const PAPELITO_VENDOR_PENDING_FIELDS_META                 = 'papelito_vendor_pending_registration_fields';
const PAPELITO_VENDOR_PENDING_FIELDS_UPDATED_AT_META      = 'papelito_vendor_pending_registration_fields_updated_at';
const PAPELITO_VENDOR_DATE_PATTERN                        = '/^\d{4}\-\d{2}\-\d{2}$/';
const PAPELITO_VENDOR_CNPJ_PATTERN                        = '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/';
const PAPELITO_VENDOR_BANK_CODE_PATTERN                   = '/^\d{3}$/';
const PAPELITO_VENDOR_DIGITS_PATTERN                      = '/^\d+$/';
const PAPELITO_VENDOR_ACCOUNT_CHECK_DIGIT_PATTERN         = '/^[0-9A-Za-z]+$/';
const PAPELITO_VENDOR_MISSING_STORE_NAME_MESSAGE         = 'Informe o nome da loja.';
const PAPELITO_VENDOR_UNAUTHENTICATED_MESSAGE            = 'Usuario nao autenticado.';
const PAPELITO_VENDOR_INVALID_PAYLOAD_MESSAGE            = 'Payload invalido.';
const PAPELITO_VENDOR_NOT_FOUND_MESSAGE                  = 'Vendor nao encontrado.';

/**
 * Retorna data/hora em UTC no mesmo formato usado pelo fluxo de mensagens.
 *
 * @return string
 */
function papelito_current_utc_mysql(): string {
	return current_time( 'mysql', true );
}

/**
 * Retorna o status atual da triagem do revendedor.
 *
 * @param int $user_id Usuario.
 * @return string
 */
function papelito_get_seller_application_status( int $user_id ): string {
	return papelito_user_is_effective_seller( $user_id ) ? 'approved' : 'none';
}

/**
 * Sincroniza o status persistido do vendor com os campos pendentes do cadastro.
 *
 * @param int                  $user_id Usuario.
 * @param array<int, string>   $pending_fields Campos pendentes normalizados.
 * @return void
 */
function papelito_sync_vendor_pending_registration_status( int $user_id, array $pending_fields ): void {
	if ( empty( $pending_fields ) ) {
		update_user_meta( $user_id, 'papelito_profile_complete', '1' );
		return;
	}

	update_user_meta( $user_id, 'papelito_profile_complete', '0' );
}

/**
 * Dispara a sincronizacao automatica do recebedor Pagar.me quando o cadastro
 * do vendor esta completo e ainda nao ha recebedor ativo.
 *
 * Reusa a mesma regra de completude do cadastro (campos pendentes vazios) e e
 * idempotente: o handler de `papelito_vendor_approved` faz upsert (POST se nao
 * houver recipient_id salvo, PUT caso contrario), entao nao duplica conta na
 * Pagar.me. Vendors ja `active` sao ignorados para evitar resync desnecessario.
 * Falhas sao tratadas pelo proprio handler (gravam last_error e nao bloqueiam
 * a conclusao do cadastro).
 *
 * @param int                $user_id        Usuario.
 * @param array<int, string> $pending_fields Campos pendentes normalizados.
 * @return void
 */
function papelito_maybe_autosync_vendor_recipient( int $user_id, array $pending_fields ): void {
	if ( $user_id <= 0 || ! empty( $pending_fields ) ) {
		return;
	}

	if (
		function_exists( 'papelito_pagarme_vendor_recipient_is_active' )
		&& papelito_pagarme_vendor_recipient_is_active( $user_id )
	) {
		return;
	}

	do_action( 'papelito_vendor_approved', $user_id );
}

/**
 * Sincroniza o recebedor apos atualizacao de cadastro completo.
 *
 * Diferente do autosync de aprovacao, este fluxo tambem deve atualizar vendors
 * ja ativos quando seus dados bancarios/KYC forem editados.
 *
 * @param int                $user_id Usuario.
 * @param array<int, string> $pending_fields Campos pendentes normalizados.
 * @return void
 */
function papelito_sync_vendor_recipient_after_registration_update( int $user_id, array $pending_fields ): void {
	if ( $user_id <= 0 || ! empty( $pending_fields ) ) {
		return;
	}

	if (
		! function_exists( 'papelito_pagarme_is_configured' )
		|| ! function_exists( 'papelito_pagarme_upsert_vendor_recipient' )
		|| ! papelito_pagarme_is_configured()
	) {
		return;
	}

	papelito_pagarme_upsert_vendor_recipient( $user_id, false );
}

/**
 * Monta o resumo da triagem de revendedor para o usuario.
 *
 * @param int $user_id Usuario.
 * @return array<string, string>
 */
function papelito_get_seller_application_data( int $user_id ): array {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return array(
			'status'           => 'none',
			'submittedAt'      => '',
			'storeName'        => '',
			'firstName'        => '',
			'lastName'         => '',
			'email'            => '',
			'phoneNumber'      => '',
			'cnpj'             => '',
			'instagram'        => '',
			'state'            => '',
			'city'             => '',
			'cep'              => '',
			'minCep'           => '',
			'maxCep'           => '',
			'discoveryChannel' => '',
			'hasSoldPapelito'  => '',
		);
	}

	return array(
		'status'           => papelito_get_seller_application_status( $user_id ),
		'submittedAt'      => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_SUBMITTED_AT_META, true ),
		'storeName'        => (string) get_user_meta( $user_id, 'store_name', true ),
		'firstName'        => (string) get_user_meta( $user_id, 'first_name', true ),
		'lastName'         => (string) get_user_meta( $user_id, 'last_name', true ),
		'email'            => (string) $user->user_email,
		'phoneNumber'      => (string) get_user_meta( $user_id, 'phone_number', true ),
		'cnpj'             => (string) get_user_meta( $user_id, 'cnpj', true ),
		'instagram'        => (string) get_user_meta( $user_id, 'instagram', true ),
		'state'            => (string) get_user_meta( $user_id, 'state', true ),
		'city'             => (string) get_user_meta( $user_id, 'city', true ),
		'cep'              => (string) get_user_meta( $user_id, 'cep', true ),
		'minCep'           => (string) get_user_meta( $user_id, 'min_cep', true ),
		'maxCep'           => (string) get_user_meta( $user_id, 'max_cep', true ),
		'discoveryChannel' => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_DISCOVERY_CHANNEL_META, true ),
		'hasSoldPapelito'  => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_HAS_SOLD_PAPELITO_META, true ),
	);
}

/**
 * Retorna o endereco comercial salvo para a candidatura.
 *
 * @param int $user_id Usuario.
 * @return array<string, string>
 */
function papelito_get_seller_application_address_data( int $user_id ): array {
	return array(
		'cep'          => (string) get_user_meta( $user_id, 'cep', true ),
		'street'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, true ),
		'number'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, true ),
		'complement'   => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, true ),
		'neighborhood' => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, true ),
		'city'         => (string) get_user_meta( $user_id, 'city', true ),
		'state'        => (string) get_user_meta( $user_id, 'state', true ),
		'minCep'       => (string) get_user_meta( $user_id, 'min_cep', true ),
		'maxCep'       => (string) get_user_meta( $user_id, 'max_cep', true ),
		'coverageRanges' => papelito_get_vendor_coverage_ranges( $user_id ),
	);
}

/**
 * Retorna as faixas de cobertura salvas para o vendor.
 *
 * @param int $user_id Usuario.
 * @return array<int, array<string, string>>
 */
function papelito_get_vendor_coverage_ranges( int $user_id ): array {
	$min_ranges = (array) get_user_meta( $user_id, 'min_cep', false );
	$max_ranges = (array) get_user_meta( $user_id, 'max_cep', false );
	$count      = min( count( $min_ranges ), count( $max_ranges ) );
	$ranges     = array();

	for ( $index = 0; $index < $count; $index++ ) {
		$min_cep = sanitize_text_field( (string) $min_ranges[ $index ] );
		$max_cep = sanitize_text_field( (string) $max_ranges[ $index ] );

		if ( '' === $min_cep || '' === $max_cep ) {
			continue;
		}

		$ranges[] = array(
			'minCep' => $min_cep,
			'maxCep' => $max_cep,
		);
	}

	return $ranges;
}

/**
 * Decodifica o draft de recebedor Pagar.me salvo em usermeta.
 *
 * @param int $user_id Usuario.
 * @return array<string, mixed>|null
 */
function papelito_get_vendor_pagarme_recipient_draft( int $user_id ): ?array {
	$raw = (string) get_user_meta( $user_id, PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META, true );

	if ( '' === $raw ) {
		return null;
	}

	$decoded = json_decode( $raw, true );

	return is_array( $decoded ) ? $decoded : null;
}

/**
 * Retorna os dados bancarios do draft do recebedor para uso no admin.
 *
 * @param int $user_id Usuario.
 * @return array<string, string>|null
 */
function papelito_get_vendor_bank_account_detail( int $user_id ): ?array {
	$draft = papelito_get_vendor_pagarme_recipient_draft( $user_id );

	if ( ! is_array( $draft ) || ! isset( $draft['bankAccount'] ) || ! is_array( $draft['bankAccount'] ) ) {
		return null;
	}

	$bank_account = $draft['bankAccount'];

	return array(
		'holderName'        => sanitize_text_field( (string) ( $bank_account['holderName'] ?? '' ) ),
		'holderType'        => sanitize_text_field( (string) ( $bank_account['holderType'] ?? '' ) ),
		'holderDocument'    => sanitize_text_field( (string) ( $bank_account['holderDocument'] ?? '' ) ),
		'bankCode'          => sanitize_text_field( (string) ( $bank_account['bankCode'] ?? '' ) ),
		'branchNumber'      => sanitize_text_field( (string) ( $bank_account['branchNumber'] ?? '' ) ),
		'branchCheckDigit'  => sanitize_text_field( (string) ( $bank_account['branchCheckDigit'] ?? '' ) ),
		'accountNumber'     => sanitize_text_field( (string) ( $bank_account['accountNumber'] ?? '' ) ),
		'accountCheckDigit' => sanitize_text_field( (string) ( $bank_account['accountCheckDigit'] ?? '' ) ),
		'type'              => sanitize_text_field( (string) ( $bank_account['type'] ?? '' ) ),
	);
}

/**
 * Lista fixa dos campos do step 3 que podem ficar pendentes apos cadastro admin.
 *
 * @return array<int, string>
 */
function papelito_vendor_pending_registration_allowed_fields(): array {
	return array(
		'companyName',
		'tradingName',
		'corporationType',
		'foundingDate',
		'annualRevenue',
		'partner.name',
		'partner.email',
		'partner.document',
		'partner.motherName',
		'partner.birthdate',
		'partner.monthlyIncome',
		'partner.professionalOccupation',
		'partner.address.zipCode',
		'partner.address.street',
		'partner.address.streetNumber',
		'partner.address.neighborhood',
		'partner.address.city',
		'partner.address.state',
		'bankAccount.holderName',
		'bankAccount.holderDocument',
		'bankAccount.bankCode',
		'bankAccount.branchNumber',
		'bankAccount.accountNumber',
		'bankAccount.accountCheckDigit',
	);
}

/**
 * Retorna o draft parcial default do recebedor.
 *
 * @return array<string, mixed>
 */
function papelito_vendor_empty_pagarme_step3(): array {
	return array(
		'companyName'      => '',
		'tradingName'      => '',
		'corporationType'  => '',
		'foundingDate'     => '',
		'annualRevenue'    => '',
		'managingPartners' => array(
			array(
				'name'                            => '',
				'email'                           => '',
				'document'                        => '',
				'motherName'                      => '',
				'birthdate'                       => '',
				'monthlyIncome'                   => '',
				'professionalOccupation'          => '',
				'selfDeclaredLegalRepresentative' => true,
				'address'                         => array(
					'zipCode'      => '',
					'street'       => '',
					'streetNumber' => '',
					'complement'   => '',
					'neighborhood' => '',
					'city'         => '',
					'state'        => '',
				),
			),
		),
		'bankAccount'      => array(
			'holderName'        => '',
			'holderType'        => 'company',
			'holderDocument'    => '',
			'bankCode'          => '',
			'branchNumber'      => '',
			'branchCheckDigit'  => '',
			'accountNumber'     => '',
			'accountCheckDigit' => '',
			'type'              => 'checking',
		),
		'transfer'         => array(
			'interval' => 'Daily',
			'day'      => '0',
		),
	);
}

/**
 * Normaliza um draft parcial do recebedor sem exigir validacao completa.
 *
 * @param array<string, mixed> $step3 Draft bruto.
 * @param array<string, mixed> $context Contexto opcional com defaults.
 * @return array<string, mixed>
 */
function papelito_sanitize_vendor_pagarme_step3_partial( array $step3, array $context = array() ): array {
	$base = papelito_vendor_empty_pagarme_step3();

	$store_name = sanitize_text_field( (string) ( $context['storeName'] ?? '' ) );
	$email      = sanitize_email( (string) ( $context['email'] ?? '' ) );
	$cnpj       = sanitize_text_field( (string) ( $context['cnpj'] ?? '' ) );
	$cep        = (string) ( $context['cep'] ?? '' );
	$street     = sanitize_text_field( (string) ( $context['street'] ?? '' ) );
	$number     = sanitize_text_field( (string) ( $context['number'] ?? '' ) );
	$complement = sanitize_text_field( (string) ( $context['complement'] ?? '' ) );
	$neighborhood = sanitize_text_field( (string) ( $context['neighborhood'] ?? '' ) );
	$city       = sanitize_text_field( (string) ( $context['city'] ?? '' ) );
	$state      = sanitize_text_field( (string) ( $context['state'] ?? '' ) );
	$first_name = sanitize_text_field( (string) ( $context['firstName'] ?? '' ) );
	$last_name  = sanitize_text_field( (string) ( $context['lastName'] ?? '' ) );
	$partner_name_fallback = trim( $first_name . ' ' . $last_name );
	$partner_address_zip   = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( $cep ) : '';

	$partner_input = isset( $step3['managingPartners'][0] ) && is_array( $step3['managingPartners'][0] )
		? $step3['managingPartners'][0]
		: array();
	$partner_address_input = isset( $partner_input['address'] ) && is_array( $partner_input['address'] )
		? $partner_input['address']
		: array();
	$bank_account_input = isset( $step3['bankAccount'] ) && is_array( $step3['bankAccount'] )
		? $step3['bankAccount']
		: array();

	return array(
		'companyName'      => sanitize_text_field( (string) ( $step3['companyName'] ?? $store_name ) ),
		'tradingName'      => sanitize_text_field( (string) ( $step3['tradingName'] ?? $store_name ) ),
		'corporationType'  => sanitize_text_field( (string) ( $step3['corporationType'] ?? '' ) ),
		'foundingDate'     => sanitize_text_field( (string) ( $step3['foundingDate'] ?? '' ) ),
		'annualRevenue'    => sanitize_text_field( (string) ( $step3['annualRevenue'] ?? '' ) ),
		'managingPartners' => array(
			array(
				'name'                            => sanitize_text_field( (string) ( $partner_input['name'] ?? $partner_name_fallback ) ),
				'email'                           => sanitize_email( (string) ( $partner_input['email'] ?? $email ) ),
				'document'                        => sanitize_text_field( (string) ( $partner_input['document'] ?? '' ) ),
				'motherName'                      => sanitize_text_field( (string) ( $partner_input['motherName'] ?? '' ) ),
				'birthdate'                       => sanitize_text_field( (string) ( $partner_input['birthdate'] ?? '' ) ),
				'monthlyIncome'                   => sanitize_text_field( (string) ( $partner_input['monthlyIncome'] ?? '' ) ),
				'professionalOccupation'          => sanitize_text_field( (string) ( $partner_input['professionalOccupation'] ?? '' ) ),
				'selfDeclaredLegalRepresentative' => ! isset( $partner_input['selfDeclaredLegalRepresentative'] ) || (bool) $partner_input['selfDeclaredLegalRepresentative'],
				'address'                         => array(
					'zipCode'      => sanitize_text_field( (string) ( $partner_address_input['zipCode'] ?? $partner_address_zip ) ),
					'street'       => sanitize_text_field( (string) ( $partner_address_input['street'] ?? $street ) ),
					'streetNumber' => sanitize_text_field( (string) ( $partner_address_input['streetNumber'] ?? $number ) ),
					'complement'   => sanitize_text_field( (string) ( $partner_address_input['complement'] ?? $complement ) ),
					'neighborhood' => sanitize_text_field( (string) ( $partner_address_input['neighborhood'] ?? $neighborhood ) ),
					'city'         => sanitize_text_field( (string) ( $partner_address_input['city'] ?? $city ) ),
					'state'        => sanitize_text_field( (string) ( $partner_address_input['state'] ?? $state ) ),
				),
			),
		),
		'bankAccount'      => array(
			'holderName'        => sanitize_text_field( (string) ( $bank_account_input['holderName'] ?? $store_name ) ),
			'holderType'        => 'individual' === sanitize_text_field( (string) ( $bank_account_input['holderType'] ?? '' ) ) ? 'individual' : 'company',
			'holderDocument'    => sanitize_text_field( (string) ( $bank_account_input['holderDocument'] ?? $cnpj ) ),
			'bankCode'          => papelito_admin_vendors_normalize_document_digits( (string) ( $bank_account_input['bankCode'] ?? '' ) ),
			'branchNumber'      => papelito_admin_vendors_normalize_document_digits( (string) ( $bank_account_input['branchNumber'] ?? '' ) ),
			'branchCheckDigit'  => sanitize_text_field( (string) ( $bank_account_input['branchCheckDigit'] ?? '' ) ),
			'accountNumber'     => papelito_admin_vendors_normalize_document_digits( (string) ( $bank_account_input['accountNumber'] ?? '' ) ),
			'accountCheckDigit' => sanitize_text_field( (string) ( $bank_account_input['accountCheckDigit'] ?? '' ) ),
			'type'              => 'savings' === sanitize_text_field( (string) ( $bank_account_input['type'] ?? '' ) ) ? 'savings' : 'checking',
		),
		'transfer'         => array(
			'interval' => sanitize_text_field( (string) ( $step3['transfer']['interval'] ?? $base['transfer']['interval'] ) ),
			'day'      => sanitize_text_field( (string) ( $step3['transfer']['day'] ?? $base['transfer']['day'] ) ),
		),
	);
}

/**
 * Calcula os campos pendentes do draft financeiro.
 *
 * @param array<string, mixed> $step3 Draft normalizado.
 * @return array<int, string>
 */
function papelito_collect_vendor_pending_registration_fields( array $step3 ): array {
	$pending = array_merge(
		papelito_collect_vendor_pending_company_fields( $step3 ),
		papelito_collect_vendor_pending_partner_fields( $step3 ),
		papelito_collect_vendor_pending_bank_fields( $step3 )
	);

	return array_values( array_intersect( papelito_vendor_pending_registration_allowed_fields(), array_unique( $pending ) ) );
}

/**
 * Coleta campos empresariais pendentes do step 3.
 *
 * @param array $step3 Dados do step 3.
 * @return array<int, string>
 */
function papelito_collect_vendor_pending_company_fields( array $step3 ): array {
	$pending          = array();
	$company_name     = sanitize_text_field( (string) ( $step3['companyName'] ?? '' ) );
	$trading_name     = sanitize_text_field( (string) ( $step3['tradingName'] ?? '' ) );
	$corporation_type = sanitize_text_field( (string) ( $step3['corporationType'] ?? '' ) );
	$founding_date    = sanitize_text_field( (string) ( $step3['foundingDate'] ?? '' ) );
	$annual_revenue   = str_replace( ',', '.', sanitize_text_field( (string) ( $step3['annualRevenue'] ?? '' ) ) );

	if ( '' === $company_name ) {
		$pending[] = 'companyName';
	}
	if ( '' === $trading_name ) {
		$pending[] = 'tradingName';
	}
	if ( '' === $corporation_type ) {
		$pending[] = 'corporationType';
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_DATE_PATTERN, $founding_date ) ) {
		$pending[] = 'foundingDate';
	}
	if ( ! is_numeric( $annual_revenue ) || (float) $annual_revenue <= 0 ) {
		$pending[] = 'annualRevenue';
	}

	return $pending;
}

/**
 * Coleta os campos pessoais pendentes do socio administrador.
 *
 * @param array $partner Dados do socio.
 * @return array<int, string>
 */
function papelito_collect_vendor_pending_partner_identity_fields( array $partner ): array {
	$pending = array();

	if ( '' === sanitize_text_field( (string) ( $partner['name'] ?? '' ) ) ) {
		$pending[] = 'partner.name';
	}
	if ( ! is_email( sanitize_email( (string) ( $partner['email'] ?? '' ) ) ) ) {
		$pending[] = 'partner.email';
	}
	if ( ! papelito_revendedor_validate_cpf( (string) ( $partner['document'] ?? '' ) ) ) {
		$pending[] = 'partner.document';
	}
	if ( '' === sanitize_text_field( (string) ( $partner['motherName'] ?? '' ) ) ) {
		$pending[] = 'partner.motherName';
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_DATE_PATTERN, sanitize_text_field( (string) ( $partner['birthdate'] ?? '' ) ) ) ) {
		$pending[] = 'partner.birthdate';
	}

	$monthly_income = str_replace( ',', '.', sanitize_text_field( (string) ( $partner['monthlyIncome'] ?? '' ) ) );
	if ( ! is_numeric( $monthly_income ) || (float) $monthly_income <= 0 ) {
		$pending[] = 'partner.monthlyIncome';
	}
	if ( '' === sanitize_text_field( (string) ( $partner['professionalOccupation'] ?? '' ) ) ) {
		$pending[] = 'partner.professionalOccupation';
	}

	return $pending;
}

/**
 * Coleta os campos de endereco pendentes do socio administrador.
 *
 * @param array $partner Dados do socio.
 * @return array<int, string>
 */
function papelito_collect_vendor_pending_partner_address_fields( array $partner ): array {
	$pending = array();
	$address = isset( $partner['address'] ) && is_array( $partner['address'] ) ? $partner['address'] : array();

	$zip = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $address['zipCode'] ?? '' ) ) : '';
	if ( '' === $zip ) {
		$pending[] = 'partner.address.zipCode';
	}
	foreach ( array( 'street', 'streetNumber', 'neighborhood', 'city' ) as $field ) {
		if ( '' === sanitize_text_field( (string) ( $address[ $field ] ?? '' ) ) ) {
			$pending[] = 'partner.address.' . $field;
		}
	}

	$state = sanitize_text_field( (string) ( $address['state'] ?? '' ) );
	if ( '' === $state || ! array_key_exists( $state, papelito_brazilian_states() ) ) {
		$pending[] = 'partner.address.state';
	}

	return $pending;
}

/**
 * Coleta campos do socio administrador pendentes do step 3.
 *
 * @param array $step3 Dados do step 3.
 * @return array<int, string>
 */
function papelito_collect_vendor_pending_partner_fields( array $step3 ): array {
	$partner = isset( $step3['managingPartners'][0] ) && is_array( $step3['managingPartners'][0] ) ? $step3['managingPartners'][0] : array();

	return array_merge(
		papelito_collect_vendor_pending_partner_identity_fields( $partner ),
		papelito_collect_vendor_pending_partner_address_fields( $partner )
	);
}

/**
 * Coleta campos bancarios pendentes do step 3.
 *
 * @param array $step3 Dados do step 3.
 * @return array<int, string>
 */
function papelito_collect_vendor_pending_bank_fields( array $step3 ): array {
	$pending        = array();
	$bank_account   = isset( $step3['bankAccount'] ) && is_array( $step3['bankAccount'] ) ? $step3['bankAccount'] : array();
	$holder_type    = sanitize_text_field( (string) ( $bank_account['holderType'] ?? '' ) );
	$holder_document = (string) ( $bank_account['holderDocument'] ?? '' );

	if ( '' === sanitize_text_field( (string) ( $bank_account['holderName'] ?? '' ) ) ) {
		$pending[] = 'bankAccount.holderName';
	}
	$document_valid = 'individual' === $holder_type
		? papelito_revendedor_validate_cpf( $holder_document )
		: 1 === preg_match( PAPELITO_VENDOR_CNPJ_PATTERN, $holder_document );
	if ( ! $document_valid ) {
		$pending[] = 'bankAccount.holderDocument';
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_BANK_CODE_PATTERN, sanitize_text_field( (string) ( $bank_account['bankCode'] ?? '' ) ) ) ) {
		$pending[] = 'bankAccount.bankCode';
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_DIGITS_PATTERN, sanitize_text_field( (string) ( $bank_account['branchNumber'] ?? '' ) ) ) ) {
		$pending[] = 'bankAccount.branchNumber';
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_DIGITS_PATTERN, sanitize_text_field( (string) ( $bank_account['accountNumber'] ?? '' ) ) ) ) {
		$pending[] = 'bankAccount.accountNumber';
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_ACCOUNT_CHECK_DIGIT_PATTERN, sanitize_text_field( (string) ( $bank_account['accountCheckDigit'] ?? '' ) ) ) ) {
		$pending[] = 'bankAccount.accountCheckDigit';
	}

	return $pending;
}

/**
 * Le os campos pendentes salvos para o vendor.
 *
 * @param int $user_id Usuario.
 * @return array<int, string>
 */
function papelito_get_vendor_pending_registration_fields( int $user_id ): array {
	$raw = get_user_meta( $user_id, PAPELITO_VENDOR_PENDING_FIELDS_META, true );

	if ( is_string( $raw ) && '' !== $raw ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$raw = $decoded;
		}
	}

	if ( ! is_array( $raw ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map(
				static function ( $field ) {
					$field = sanitize_text_field( (string) $field );
					return in_array( $field, papelito_vendor_pending_registration_allowed_fields(), true ) ? $field : '';
				},
				$raw
			),
			'strlen'
		)
	);
}

/**
 * Persiste a lista de campos pendentes do vendor.
 *
 * @param int                $user_id Usuario.
 * @param array<int, string> $fields Campos pendentes.
 * @return void
 */
function papelito_save_vendor_pending_registration_fields( int $user_id, array $fields ): void {
	$fields = array_values(
		array_filter(
			array_map(
				static function ( $field ) {
					$field = sanitize_text_field( (string) $field );
					return in_array( $field, papelito_vendor_pending_registration_allowed_fields(), true ) ? $field : '';
				},
				$fields
			),
			'strlen'
		)
	);

	if ( empty( $fields ) ) {
		delete_user_meta( $user_id, PAPELITO_VENDOR_PENDING_FIELDS_META );
		delete_user_meta( $user_id, PAPELITO_VENDOR_PENDING_FIELDS_UPDATED_AT_META );
		return;
	}

	update_user_meta( $user_id, PAPELITO_VENDOR_PENDING_FIELDS_META, wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_PENDING_FIELDS_UPDATED_AT_META, papelito_current_utc_mysql() );
}

/**
 * Resolve os campos pendentes atuais, recalculando a partir do draft quando necessario.
 *
 * @param int $user_id Usuario.
 * @return array<int, string>
 */
function papelito_resolve_vendor_pending_registration_fields( int $user_id ): array {
	$stored = papelito_get_vendor_pending_registration_fields( $user_id );
	if ( ! empty( $stored ) ) {
		return $stored;
	}

	$draft = papelito_get_vendor_pagarme_recipient_draft( $user_id );
	if ( ! is_array( $draft ) ) {
		return array();
	}

	$user = get_userdata( $user_id );
	$normalized = papelito_sanitize_vendor_pagarme_step3_partial(
		$draft,
		array(
			'storeName'    => (string) get_user_meta( $user_id, 'store_name', true ),
			'email'        => $user instanceof WP_User ? (string) $user->user_email : '',
			'cnpj'         => (string) get_user_meta( $user_id, 'cnpj', true ),
			'cep'          => (string) get_user_meta( $user_id, 'cep', true ),
			'street'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, true ),
			'number'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, true ),
			'complement'   => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, true ),
			'neighborhood' => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, true ),
			'city'         => (string) get_user_meta( $user_id, 'city', true ),
			'state'        => (string) get_user_meta( $user_id, 'state', true ),
			'firstName'    => (string) get_user_meta( $user_id, 'first_name', true ),
			'lastName'     => (string) get_user_meta( $user_id, 'last_name', true ),
		)
	);
	$fields     = papelito_collect_vendor_pending_registration_fields( $normalized );

	if ( ! empty( $fields ) ) {
		papelito_save_vendor_pending_registration_fields( $user_id, $fields );
	}

	return $fields;
}

/**
 * Recalcula e persiste os campos pendentes com base no draft informado.
 *
 * @param int                  $user_id Usuario.
 * @param array<string, mixed> $step3 Draft normalizado.
 * @return array<int, string>
 */
function papelito_refresh_vendor_pending_registration_state( int $user_id, array $step3 ): array {
	$fields = papelito_collect_vendor_pending_registration_fields( $step3 );
	papelito_save_vendor_pending_registration_fields( $user_id, $fields );
	return $fields;
}

/**
 * Monta a resposta REST das pendencias de cadastro do vendor.
 *
 * @param int $user_id Usuario.
 * @return array<string, mixed>
 */
function papelito_get_vendor_pending_registration_rest_response( int $user_id ): array {
	$application = papelito_get_vendor_application_rest_response( $user_id );

	return array(
		'application'   => array(
			'step1'          => isset( $application['application']['step1'] ) && is_array( $application['application']['step1'] )
				? $application['application']['step1']
				: array(),
			'step2'          => isset( $application['application']['step2'] ) && is_array( $application['application']['step2'] )
				? $application['application']['step2']
				: array(),
			'coverageRanges' => papelito_get_vendor_coverage_ranges( $user_id ),
		),
		'draft'         => papelito_get_vendor_pagarme_recipient_draft( $user_id ),
		'pendingFields' => papelito_resolve_vendor_pending_registration_fields( $user_id ),
		'updatedAt'     => (string) get_user_meta( $user_id, PAPELITO_VENDOR_PENDING_FIELDS_UPDATED_AT_META, true ),
	);
}

/**
 * Constrói o payload REST do wizard de revendedor.
 *
 * @param int $user_id Usuario.
 * @return array<string, mixed>
 */
function papelito_get_vendor_application_rest_response( int $user_id ): array {
	$summary = papelito_get_seller_application_data( $user_id );
	$address = papelito_get_seller_application_address_data( $user_id );

	return array(
		'status'       => $summary['status'],
		'submittedAt'  => $summary['submittedAt'],
		'pendingFields' => papelito_resolve_vendor_pending_registration_fields( $user_id ),
		'application'  => array(
			'step1' => array(
				'storeName'        => $summary['storeName'],
				'firstName'        => $summary['firstName'],
				'lastName'         => $summary['lastName'],
				'cnpj'             => $summary['cnpj'],
				'phone'            => $summary['phoneNumber'],
				'email'            => $summary['email'],
				'instagram'        => $summary['instagram'],
				'hasSoldPapelito'  => $summary['hasSoldPapelito'],
				'discoveryChannel' => $summary['discoveryChannel'],
			),
			'step2' => array(
				'cep'          => $address['cep'],
				'street'       => $address['street'],
				'number'       => $address['number'],
				'complement'   => $address['complement'],
				'neighborhood' => $address['neighborhood'],
				'city'         => $address['city'],
				'state'        => $address['state'],
				'minCep'       => $address['minCep'],
				'maxCep'       => $address['maxCep'],
				'coverageRanges' => isset( $address['coverageRanges'] ) && is_array( $address['coverageRanges'] ) ? $address['coverageRanges'] : array(),
			),
		),
		'pagarmeDraft' => papelito_get_vendor_pagarme_recipient_draft( $user_id ),
	);
}

/**
 * Salva o endereco comercial complementar do wizard.
 *
 * @param int   $user_id Usuario.
 * @param array $step2 Dados do step 2.
 * @return void
 */
function papelito_save_seller_application_address_data( int $user_id, array $step2 ): void {
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, sanitize_text_field( (string) ( $step2['street'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, sanitize_text_field( (string) ( $step2['number'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, sanitize_text_field( (string) ( $step2['complement'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, sanitize_text_field( (string) ( $step2['neighborhood'] ?? '' ) ) );
}

/**
 * Salva o draft do recebedor Pagar.me.
 *
 * @param int   $user_id Usuario.
 * @param array $step3 Dados do step 3.
 * @return void
 */
function papelito_save_vendor_pagarme_recipient_draft( int $user_id, array $step3 ): void {
	$sanitized = papelito_sanitize_vendor_pagarme_draft( $step3 );
	$encoded   = wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( false === $encoded ) {
		return;
	}

	update_user_meta( $user_id, PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_META, $encoded );
	update_user_meta( $user_id, PAPELITO_VENDOR_PAGARME_RECIPIENT_DRAFT_UPDATED_AT, papelito_current_utc_mysql() );
}

/**
 * Salva apenas o draft financeiro do recebedor.
 *
 * @param int   $user_id Usuario.
 * @param array $step3 Dados do step 3.
 * @return array<string,mixed>|WP_Error
 */
function papelito_update_vendor_pagarme_recipient_draft_rest( int $user_id, array $step3 ) {
	$step3_validation = papelito_validate_vendor_pagarme_step3( $step3 );
	if ( $step3_validation instanceof WP_Error ) {
		$step3_validation->add_data( array( 'status' => 422 ) );
		return $step3_validation;
	}

	papelito_save_vendor_pagarme_recipient_draft( $user_id, $step3 );
	$pending_fields = papelito_refresh_vendor_pending_registration_state( $user_id, papelito_sanitize_vendor_pagarme_step3_partial( $step3 ) );
	papelito_sync_vendor_pending_registration_status( $user_id, $pending_fields );

	if ( defined( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META' ) ) {
		update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META, '' );
	}

	if ( defined( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META' ) ) {
		update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META, '' );
	}

	papelito_sync_vendor_recipient_after_registration_update( $user_id, $pending_fields );

	return array(
		'draft'         => papelito_get_vendor_pagarme_recipient_draft( $user_id ),
		'pendingFields' => $pending_fields,
	);
}

/**
 * Salva o draft parcial do onboarding financeiro e recalcula pendencias.
 *
 * @param int                  $user_id Usuario.
 * @param array<string, mixed> $step3 Dados do step 3.
 * @return array<string, mixed>|WP_Error
 */
function papelito_update_vendor_pending_registration_rest( int $user_id, array $step3 ) {
	$application = isset( $step3['application'] ) && is_array( $step3['application'] ) ? $step3['application'] : null;
	$draft_input = isset( $step3['draft'] ) && is_array( $step3['draft'] ) ? $step3['draft'] : $step3;

	if ( is_array( $application ) ) {
		$application_result = papelito_apply_vendor_pending_registration_application( $user_id, $application );
		if ( is_wp_error( $application_result ) ) {
			return $application_result;
		}
	}

	$user = get_userdata( $user_id );

	$context = array(
		'storeName'    => (string) get_user_meta( $user_id, 'store_name', true ),
		'email'        => $user instanceof WP_User ? (string) $user->user_email : '',
		'cnpj'         => (string) get_user_meta( $user_id, 'cnpj', true ),
		'cep'          => (string) get_user_meta( $user_id, 'cep', true ),
		'street'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, true ),
		'number'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, true ),
		'complement'   => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, true ),
		'neighborhood' => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, true ),
		'city'         => (string) get_user_meta( $user_id, 'city', true ),
		'state'        => (string) get_user_meta( $user_id, 'state', true ),
		'firstName'    => (string) get_user_meta( $user_id, 'first_name', true ),
		'lastName'     => (string) get_user_meta( $user_id, 'last_name', true ),
	);

	$normalized = papelito_sanitize_vendor_pagarme_step3_partial( $draft_input, $context );

	papelito_save_vendor_pagarme_recipient_draft( $user_id, $normalized );
	$pending_fields = papelito_refresh_vendor_pending_registration_state( $user_id, $normalized );
	papelito_sync_vendor_pending_registration_status( $user_id, $pending_fields );

	if ( defined( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META' ) && empty( $pending_fields ) ) {
		update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META, '' );
	}

	if ( defined( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META' ) && empty( $pending_fields ) ) {
		update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META, '' );
	}

	papelito_sync_vendor_recipient_after_registration_update( $user_id, $pending_fields );

	return papelito_get_vendor_pending_registration_rest_response( $user_id );
}

/**
 * Valida e-mail e CNPJ enviados no onboarding financeiro.
 *
 * @param int   $user_id Usuario.
 * @param array $step1 Dados do step 1.
 * @return WP_Error|null
 */
function papelito_validate_vendor_pending_registration_account( int $user_id, array $step1 ) {
	$email = sanitize_email( (string) ( $step1['email'] ?? '' ) );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'papelito_vendor_invalid_email', 'Informe um e-mail valido.', array( 'status' => 422 ) );
	}

	$existing_user = get_user_by( 'email', $email );
	if ( $existing_user instanceof WP_User && (int) $existing_user->ID !== $user_id ) {
		return new WP_Error( 'papelito_vendor_email_exists', 'Ja existe uma conta com este e-mail.', array( 'status' => 409 ) );
	}

	$cnpj = sanitize_text_field( (string) ( $step1['cnpj'] ?? '' ) );
	if ( 1 !== preg_match( PAPELITO_VENDOR_CNPJ_PATTERN, $cnpj ) ) {
		return new WP_Error( 'papelito_vendor_invalid_cnpj', 'Informe um CNPJ valido.', array( 'status' => 422 ) );
	}
	if ( papelito_admin_vendors_cnpj_exists( $cnpj, $user_id ) ) {
		return new WP_Error( 'papelito_vendor_cnpj_exists', 'Ja existe uma conta com este CNPJ.', array( 'status' => 409 ) );
	}

	return null;
}

/**
 * Valida a loja e o endereco comercial do onboarding financeiro.
 *
 * @param array $step1 Dados do step 1.
 * @param array $step2 Dados do step 2.
 * @return WP_Error|null
 */
function papelito_validate_vendor_pending_registration_store( array $step1, array $step2 ) {
	if ( '' === sanitize_text_field( (string) ( $step1['storeName'] ?? '' ) ) ) {
		return new WP_Error( 'papelito_vendor_missing_store_name', PAPELITO_VENDOR_MISSING_STORE_NAME_MESSAGE, array( 'status' => 422 ) );
	}

	foreach ( array( 'street', 'number', 'neighborhood', 'city', 'state' ) as $field ) {
		if ( '' === sanitize_text_field( (string) ( $step2[ $field ] ?? '' ) ) ) {
			return new WP_Error( 'papelito_vendor_incomplete_address', 'Informe o endereco comercial completo do vendor.', array( 'status' => 422 ) );
		}
	}

	return null;
}

/**
 * Resolve o nome de exibicao do vendor a partir do step 1.
 *
 * @param array  $step1 Dados do step 1.
 * @param string $email E-mail ja validado.
 * @return string
 */
function papelito_vendor_pending_registration_display_name( array $step1, string $email ): string {
	$first_name   = sanitize_text_field( (string) ( $step1['firstName'] ?? '' ) );
	$last_name    = sanitize_text_field( (string) ( $step1['lastName'] ?? '' ) );
	$display_name = trim( $first_name . ' ' . $last_name );
	if ( '' !== $display_name ) {
		return $display_name;
	}

	$store_name = sanitize_text_field( (string) ( $step1['storeName'] ?? '' ) );

	return '' !== $store_name ? $store_name : $email;
}

/**
 * Grava os metadados cadastrais enviados no onboarding financeiro.
 *
 * @param int   $user_id Usuario.
 * @param array $step1 Dados do step 1.
 * @param array $step2 Dados do step 2.
 * @return void
 */
function papelito_persist_vendor_pending_registration_metas( int $user_id, array $step1, array $step2 ): void {
	update_user_meta( $user_id, 'store_name', sanitize_text_field( (string) ( $step1['storeName'] ?? '' ) ) );
	update_user_meta( $user_id, 'phone_number', papelito_auth_format_phone( (string) ( $step1['phone'] ?? '' ) ) );
	update_user_meta( $user_id, 'cnpj', sanitize_text_field( (string) ( $step1['cnpj'] ?? '' ) ) );
	update_user_meta( $user_id, 'instagram', sanitize_text_field( ltrim( (string) ( $step1['instagram'] ?? '' ), '@' ) ) );
	update_user_meta( $user_id, 'state', sanitize_text_field( (string) ( $step2['state'] ?? '' ) ) );
	update_user_meta( $user_id, 'city', sanitize_text_field( (string) ( $step2['city'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, sanitize_text_field( (string) ( $step2['street'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, sanitize_text_field( (string) ( $step2['number'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, sanitize_text_field( (string) ( $step2['complement'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, sanitize_text_field( (string) ( $step2['neighborhood'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_DISCOVERY_CHANNEL_META, sanitize_text_field( (string) ( $step1['discoveryChannel'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_HAS_SOLD_PAPELITO_META, sanitize_text_field( (string) ( $step1['hasSoldPapelito'] ?? '' ) ) );
}

/**
 * Grava o CEP base e as faixas de cobertura do vendor.
 *
 * @param int   $user_id Usuario.
 * @param array $step2 Dados do step 2.
 * @param array $ranges Faixas de cobertura normalizadas.
 * @return void
 */
function papelito_persist_vendor_pending_registration_coverage( int $user_id, array $step2, array $ranges ): void {
	$cep_base = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $step2['cep'] ?? '' ) ) : '';
	update_user_meta( $user_id, 'cep', $cep_base );
	delete_user_meta( $user_id, 'min_cep' );
	delete_user_meta( $user_id, 'max_cep' );

	foreach ( $ranges as $range ) {
		add_user_meta( $user_id, 'min_cep', $range['minCep'], false );
		add_user_meta( $user_id, 'max_cep', $range['maxCep'], false );
	}

	if ( function_exists( 'papelito_apply_vendor_geo' ) && '' !== $cep_base ) {
		papelito_apply_vendor_geo( $user_id, $cep_base );
	}
}

/**
 * Atualiza os dados cadastrais enviados junto do onboarding financeiro.
 *
 * @param int   $user_id Usuario.
 * @param array $application Aplicacao aninhada.
 * @return true|WP_Error
 */
function papelito_apply_vendor_pending_registration_application( int $user_id, array $application ) {
	$step1 = isset( $application['step1'] ) && is_array( $application['step1'] ) ? $application['step1'] : array();
	$step2 = isset( $application['step2'] ) && is_array( $application['step2'] ) ? $application['step2'] : array();

	$account_error = papelito_validate_vendor_pending_registration_account( $user_id, $step1 );
	if ( $account_error instanceof WP_Error ) {
		return $account_error;
	}

	$ranges = papelito_admin_vendors_normalize_coverage_ranges( $application['coverageRanges'] ?? null );
	if ( is_wp_error( $ranges ) ) {
		return $ranges;
	}

	$store_error = papelito_validate_vendor_pending_registration_store( $step1, $step2 );
	if ( $store_error instanceof WP_Error ) {
		return $store_error;
	}

	$email       = sanitize_email( (string) ( $step1['email'] ?? '' ) );
	$user_update = wp_update_user(
		array(
			'ID'           => $user_id,
			'user_email'   => $email,
			'first_name'   => sanitize_text_field( (string) ( $step1['firstName'] ?? '' ) ),
			'last_name'    => sanitize_text_field( (string) ( $step1['lastName'] ?? '' ) ),
			'display_name' => papelito_vendor_pending_registration_display_name( $step1, $email ),
		)
	);
	if ( is_wp_error( $user_update ) ) {
		return $user_update;
	}

	papelito_persist_vendor_pending_registration_metas( $user_id, $step1, $step2 );
	papelito_persist_vendor_pending_registration_coverage( $user_id, $step2, $ranges );

	return true;
}

/**
 * Sanitiza o draft de recebedor de forma recursiva.
 *
 * @param mixed $value Valor bruto.
 * @return mixed
 */
function papelito_sanitize_vendor_pagarme_draft( $value ) {
	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_numeric( $value ) ) {
		return (string) $value;
	}

	if ( is_string( $value ) ) {
		return sanitize_text_field( $value );
	}

	if ( ! is_array( $value ) ) {
		return '';
	}

	$sanitized = array();
	foreach ( $value as $key => $item ) {
		$sanitized[ $key ] = papelito_sanitize_vendor_pagarme_draft( $item );
	}

	return $sanitized;
}

/**
 * Valida CPF com dígitos verificadores.
 *
 * @param string $value CPF.
 * @return bool
 */
function papelito_revendedor_validate_cpf( string $value ): bool {
	$digits = preg_replace( '/\D+/', '', $value );

	if ( ! is_string( $digits ) || 11 !== strlen( $digits ) || preg_match( '/^(\d)\1{10}$/', $digits ) ) {
		return false;
	}

	$base         = substr( $digits, 0, 9 );
	$first_digit  = papelito_calculate_cpf_digit( $base, 10 );
	$second_digit = papelito_calculate_cpf_digit( $base . $first_digit, 11 );

	return substr( $digits, -2 ) === (string) $first_digit . (string) $second_digit;
}

/**
 * Calcula dígito verificador do CPF.
 *
 * @param string $value Base numérica.
 * @param int    $weight Peso inicial.
 * @return int
 */
function papelito_calculate_cpf_digit( string $value, int $weight ): int {
	$total = 0;

	for ( $index = 0; $index < strlen( $value ); $index++ ) {
		$total += (int) $value[ $index ] * ( $weight - $index );
	}

	$remainder = ( $total * 10 ) % 11;

	return 10 === $remainder ? 0 : $remainder;
}

/**
 * Valida o payload do step 3 do wizard.
 *
 * @param array $step3 Dados do step 3.
 * @return WP_Error|null
 */
function papelito_validate_vendor_pagarme_step3( array $step3 ) {
	$errors = new WP_Error();
	papelito_validate_vendor_pagarme_company_fields( $step3, $errors );
	papelito_validate_vendor_pagarme_partner_fields( $step3, $errors );
	papelito_validate_vendor_pagarme_bank_fields( $step3, $errors );

	return $errors->has_errors() ? $errors : null;
}

/**
 * Valida os dados empresariais do step 3.
 *
 * @param array    $step3 Dados do step 3.
 * @param WP_Error $errors Acumulador de erros.
 * @return void
 */
function papelito_validate_vendor_pagarme_company_fields( array $step3, WP_Error $errors ): void {

	$company_name     = sanitize_text_field( (string) ( $step3['companyName'] ?? '' ) );
	$trading_name     = sanitize_text_field( (string) ( $step3['tradingName'] ?? '' ) );
	$corporation_type = sanitize_text_field( (string) ( $step3['corporationType'] ?? '' ) );
	$founding_date    = sanitize_text_field( (string) ( $step3['foundingDate'] ?? '' ) );
	$annual_revenue   = str_replace( ',', '.', sanitize_text_field( (string) ( $step3['annualRevenue'] ?? '' ) ) );

	if ( '' === $company_name ) {
		$errors->add( 'companyName', 'Informe a razao social.' );
	}
	if ( '' === $trading_name ) {
		$errors->add( 'tradingName', 'Informe o nome fantasia.' );
	}
	if ( '' === $corporation_type ) {
		$errors->add( 'corporationType', 'Informe a natureza jurídica.' );
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_DATE_PATTERN, $founding_date ) ) {
		$errors->add( 'foundingDate', 'Informe uma data de fundacao válida.' );
	}
	if ( ! is_numeric( $annual_revenue ) || (float) $annual_revenue <= 0 ) {
		$errors->add( 'annualRevenue', 'Informe o faturamento anual.' );
	}
}

/**
 * Valida os dados pessoais do socio administrador do step 3.
 *
 * @param array    $partner Dados do socio.
 * @param WP_Error $errors Acumulador de erros.
 * @return void
 */
function papelito_validate_vendor_pagarme_partner_identity( array $partner, WP_Error $errors ): void {
	if ( '' === sanitize_text_field( (string) ( $partner['name'] ?? '' ) ) ) {
		$errors->add( 'partnerName', 'Informe o nome do socio administrador.' );
	}
	if ( ! is_email( sanitize_email( (string) ( $partner['email'] ?? '' ) ) ) ) {
		$errors->add( 'partnerEmail', 'Informe um e-mail válido para o socio.' );
	}
	if ( ! papelito_revendedor_validate_cpf( (string) ( $partner['document'] ?? '' ) ) ) {
		$errors->add( 'partnerDocument', 'Informe um CPF válido para o socio.' );
	}
	if ( '' === sanitize_text_field( (string) ( $partner['motherName'] ?? '' ) ) ) {
		$errors->add( 'partnerMotherName', 'Informe o nome da mae do socio.' );
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_DATE_PATTERN, sanitize_text_field( (string) ( $partner['birthdate'] ?? '' ) ) ) ) {
		$errors->add( 'partnerBirthdate', 'Informe a data de nascimento do socio.' );
	}

	$monthly_income = str_replace( ',', '.', sanitize_text_field( (string) ( $partner['monthlyIncome'] ?? '' ) ) );
	if ( ! is_numeric( $monthly_income ) || (float) $monthly_income <= 0 ) {
		$errors->add( 'partnerMonthlyIncome', 'Informe a renda mensal do socio.' );
	}
	if ( '' === sanitize_text_field( (string) ( $partner['professionalOccupation'] ?? '' ) ) ) {
		$errors->add( 'partnerOccupation', 'Informe a ocupacao profissional do socio.' );
	}
}

/**
 * Valida o endereco do socio administrador do step 3.
 *
 * @param array    $partner Dados do socio.
 * @param WP_Error $errors Acumulador de erros.
 * @return void
 */
function papelito_validate_vendor_pagarme_partner_address( array $partner, WP_Error $errors ): void {
	$address = isset( $partner['address'] ) && is_array( $partner['address'] ) ? $partner['address'] : array();
	$zip     = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $address['zipCode'] ?? '' ) ) : '';

	if ( '' === $zip ) {
		$errors->add( 'partnerZipCode', 'Informe um CEP válido para o socio.' );
	}
	if ( '' === sanitize_text_field( (string) ( $address['street'] ?? '' ) ) ) {
		$errors->add( 'partnerStreet', 'Informe o logradouro do socio.' );
	}
	if ( '' === sanitize_text_field( (string) ( $address['streetNumber'] ?? '' ) ) ) {
		$errors->add( 'partnerStreetNumber', 'Informe o número do endereço do socio.' );
	}
	if ( '' === sanitize_text_field( (string) ( $address['neighborhood'] ?? '' ) ) ) {
		$errors->add( 'partnerNeighborhood', 'Informe o bairro do socio.' );
	}
	if ( '' === sanitize_text_field( (string) ( $address['city'] ?? '' ) ) ) {
		$errors->add( 'partnerCity', 'Informe a cidade do socio.' );
	}

	$partner_state = sanitize_text_field( (string) ( $address['state'] ?? '' ) );
	if ( '' === $partner_state || ! array_key_exists( $partner_state, papelito_brazilian_states() ) ) {
		$errors->add( 'partnerState', 'Selecione um estado válido para o socio.' );
	}
}

/**
 * Valida os dados do socio administrador do step 3.
 *
 * @param array    $step3 Dados do step 3.
 * @param WP_Error $errors Acumulador de erros.
 * @return void
 */
function papelito_validate_vendor_pagarme_partner_fields( array $step3, WP_Error $errors ): void {
	$partners = isset( $step3['managingPartners'] ) && is_array( $step3['managingPartners'] ) ? $step3['managingPartners'] : array();
	if ( empty( $partners ) ) {
		$errors->add( 'managingPartners', 'Informe ao menos um socio administrador.' );

		return;
	}

	$partner = is_array( $partners[0] ) ? $partners[0] : array();

	papelito_validate_vendor_pagarme_partner_identity( $partner, $errors );
	papelito_validate_vendor_pagarme_partner_address( $partner, $errors );
}

/**
 * Valida os dados bancarios do step 3.
 *
 * @param array    $step3 Dados do step 3.
 * @param WP_Error $errors Acumulador de erros.
 * @return void
 */
function papelito_validate_vendor_pagarme_bank_fields( array $step3, WP_Error $errors ): void {
	$bank_account = isset( $step3['bankAccount'] ) && is_array( $step3['bankAccount'] ) ? $step3['bankAccount'] : array();
	$holder_type  = sanitize_text_field( (string) ( $bank_account['holderType'] ?? '' ) );

	if ( '' === sanitize_text_field( (string) ( $bank_account['holderName'] ?? '' ) ) ) {
		$errors->add( 'bankHolderName', 'Informe o titular da conta.' );
	}

	$holder_document = (string) ( $bank_account['holderDocument'] ?? '' );
	if ( 'individual' === $holder_type ) {
if ( ! papelito_revendedor_validate_cpf( $holder_document ) ) {
			$errors->add( 'bankHolderDocument', 'Informe um CPF válido para o titular.' );
		}
	} elseif ( 1 !== preg_match( PAPELITO_VENDOR_CNPJ_PATTERN, $holder_document ) ) {
		$errors->add( 'bankHolderDocument', 'Informe um CNPJ válido para o titular.' );
	}

	if ( 1 !== preg_match( PAPELITO_VENDOR_BANK_CODE_PATTERN, sanitize_text_field( (string) ( $bank_account['bankCode'] ?? '' ) ) ) ) {
		$errors->add( 'bankCode', 'Informe um código bancário com 3 digitos.' );
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_DIGITS_PATTERN, sanitize_text_field( (string) ( $bank_account['branchNumber'] ?? '' ) ) ) ) {
		$errors->add( 'branchNumber', 'Informe uma agência válida.' );
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_DIGITS_PATTERN, sanitize_text_field( (string) ( $bank_account['accountNumber'] ?? '' ) ) ) ) {
		$errors->add( 'accountNumber', 'Informe uma conta válida.' );
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_ACCOUNT_CHECK_DIGIT_PATTERN, sanitize_text_field( (string) ( $bank_account['accountCheckDigit'] ?? '' ) ) ) ) {
		$errors->add( 'accountCheckDigit', 'Informe o digito da conta.' );
	}
}

/**
 * Valida os campos adicionais de endereco do step 2.
 *
 * @param array $step2 Dados do step 2.
 * @return WP_Error|null
 */
function papelito_validate_vendor_address_step2( array $step2 ) {
	$errors = new WP_Error();

	if ( '' === sanitize_text_field( (string) ( $step2['street'] ?? '' ) ) ) {
		$errors->add( 'street', 'Informe o logradouro.' );
	}
	if ( '' === sanitize_text_field( (string) ( $step2['number'] ?? '' ) ) ) {
		$errors->add( 'number', 'Informe o número.' );
	}
	if ( '' === sanitize_text_field( (string) ( $step2['neighborhood'] ?? '' ) ) ) {
		$errors->add( 'neighborhood', 'Informe o bairro.' );
	}

	return $errors->has_errors() ? $errors : null;
}

/**
 * Converte o payload aninhado do wizard para o contrato legado de triagem.
 *
 * @param array $payload Payload do REST.
 * @return array<string, mixed>
 */
function papelito_flatten_vendor_application_payload( array $payload ): array {
	$step1 = isset( $payload['step1'] ) && is_array( $payload['step1'] ) ? $payload['step1'] : array();
	$step2 = isset( $payload['step2'] ) && is_array( $payload['step2'] ) ? $payload['step2'] : array();
	$coverage_ranges = isset( $step2['coverageRanges'] ) && is_array( $step2['coverageRanges'] ) ? $step2['coverageRanges'] : array();
	$first_range = isset( $coverage_ranges[0] ) && is_array( $coverage_ranges[0] ) ? $coverage_ranges[0] : array();
	$min_cep = isset( $first_range['minCep'] ) ? (string) $first_range['minCep'] : (string) ( $step2['minCep'] ?? '' );
	$max_cep = isset( $first_range['maxCep'] ) ? (string) $first_range['maxCep'] : (string) ( $step2['maxCep'] ?? '' );

	return array(
		'storeName'       => (string) ( $step1['storeName'] ?? '' ),
		'firstName'       => (string) ( $step1['firstName'] ?? '' ),
		'lastName'        => (string) ( $step1['lastName'] ?? '' ),
		'email'           => (string) ( $step1['email'] ?? '' ),
		'phoneNumber'     => (string) ( $step1['phone'] ?? '' ),
		'cnpj'            => (string) ( $step1['cnpj'] ?? '' ),
		'instagram'       => (string) ( $step1['instagram'] ?? '' ),
		'city'            => (string) ( $step2['city'] ?? '' ),
		'state'           => (string) ( $step2['state'] ?? '' ),
		'cep'             => (string) ( $step2['cep'] ?? '' ),
		'minCep'          => $min_cep,
		'maxCep'          => $max_cep,
		'coverageRanges'  => $coverage_ranges,
		'discoveryChannel' => (string) ( $step1['discoveryChannel'] ?? '' ),
		'hasSoldPapelito' => (string) ( $step1['hasSoldPapelito'] ?? '' ),
	);
}

/**
 * Submete o wizard completo do revendedor via REST.
 *
 * @param int   $user_id Usuario.
 * @param array $payload Payload aninhado.
 * @return array<string, mixed>|WP_Error
 */
function papelito_submit_vendor_application_rest( int $user_id, array $payload ) {
	return new WP_Error(
		'papelito_vendor_self_registration_removed',
		'O autocadastro de vendors foi removido.',
		array( 'status' => 410 )
	);
}

/**
 * Valida o payload da triagem.
 *
 * @param array $input Payload.
 * @return WP_Error|null
 */
function papelito_validate_seller_application_input( array $input ) {
	$errors = new WP_Error();
	papelito_validate_seller_required_fields( $input, $errors );
	papelito_validate_seller_identity_fields( $input, $errors );
	$coverage_error = papelito_validate_seller_coverage_fields( $input, $errors );
	if ( is_wp_error( $coverage_error ) ) {
		return $coverage_error;
	}

	return $errors->has_errors() ? $errors : null;
}

/**
 * Valida a presenca dos campos obrigatorios da triagem.
 *
 * @param array    $input Payload.
 * @param WP_Error $errors Acumulador de erros.
 * @return void
 */
function papelito_validate_seller_required_fields( array $input, WP_Error $errors ): void {

	$required_fields = array(
		'storeName'       => PAPELITO_VENDOR_MISSING_STORE_NAME_MESSAGE,
		'firstName'       => 'Informe o nome do responsável.',
		'lastName'        => 'Informe o sobrenome.',
		'email'           => 'Informe um e-mail válido.',
		'phoneNumber'     => 'Informe um telefone válido.',
		'cnpj'            => 'Informe um CNPJ válido.',
		'instagram'       => 'Informe o Instagram da loja.',
		'city'            => 'Informe a cidade.',
		'state'           => 'Selecione o estado.',
		'cep'             => 'Informe o CEP de operação.',
		'minCep'          => 'Informe o CEP inicial da região atendida.',
		'maxCep'          => 'Informe o CEP final da região atendida.',
		'hasSoldPapelito' => 'Escolha se você já vende produtos Papelito.',
	);

	foreach ( $required_fields as $field => $message ) {
		if ( empty( $input[ $field ] ) ) {
			$errors->add( $field, $message );
		}
	}
}

/**
 * Valida identidade, contato e estado da triagem.
 *
 * @param array    $input Payload.
 * @param WP_Error $errors Acumulador de erros.
 * @return void
 */
function papelito_validate_seller_identity_fields( array $input, WP_Error $errors ): void {
	$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
	if ( '' === $email || ! is_email( $email ) ) {
		$errors->add( 'email', 'Informe um e-mail válido.' );
	}

	$phone_digits = papelito_auth_normalize_phone( isset( $input['phoneNumber'] ) ? (string) $input['phoneNumber'] : '' );
	if ( ! in_array( strlen( $phone_digits ), array( 10, 11 ), true ) ) {
		$errors->add( 'phoneNumber', 'Informe um telefone válido.' );
	}

	$cnpj = isset( $input['cnpj'] ) ? (string) $input['cnpj'] : '';
	if ( ! preg_match( PAPELITO_VENDOR_CNPJ_PATTERN, $cnpj ) ) {
		$errors->add( 'cnpj', 'Informe um CNPJ válido.' );
	}

	$state = isset( $input['state'] ) ? (string) $input['state'] : '';
	if ( '' !== $state && ! array_key_exists( $state, papelito_brazilian_states() ) ) {
		$errors->add( 'state', 'Selecione um estado válido.' );
	}
}

/**
 * Valida as faixas de cobertura informadas na triagem.
 *
 * @param array $input Payload.
 * @return WP_Error|null
 */
function papelito_validate_seller_coverage_ranges( array $input ) {
	$coverage_ranges = isset( $input['coverageRanges'] ) && is_array( $input['coverageRanges'] ) ? $input['coverageRanges'] : array();
	if ( empty( $coverage_ranges ) ) {
		return null;
	}

	$normalized_ranges = papelito_admin_vendors_normalize_coverage_ranges( $coverage_ranges );

	return is_wp_error( $normalized_ranges ) ? $normalized_ranges : null;
}

/**
 * Normaliza e valida os CEPs da triagem.
 *
 * @param array    $input Payload.
 * @param WP_Error $errors Acumulador de erros.
 * @return array<string, string>
 */
function papelito_validate_seller_cep_fields( array $input, WP_Error $errors ): array {
	$cep_fields = array(
		'cep'    => 'Informe um CEP de operação válido (8 dígitos).',
		'minCep' => 'Informe um CEP inicial válido (8 dígitos).',
		'maxCep' => 'Informe um CEP final válido (8 dígitos).',
	);

	$normalized_ceps = array();
	foreach ( $cep_fields as $field => $message ) {
		$raw        = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';
		$normalized = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( $raw ) : '';
		if ( '' === $normalized ) {
			$errors->add( $field, $message );
		}
		$normalized_ceps[ $field ] = $normalized;
	}

	return $normalized_ceps;
}

/**
 * Valida a cobertura e os CEPs da triagem.
 *
 * @param array    $input Payload.
 * @param WP_Error $errors Acumulador de erros.
 * @return WP_Error|null
 */
function papelito_validate_seller_coverage_fields( array $input, WP_Error $errors ) {
	$ranges_error = papelito_validate_seller_coverage_ranges( $input );
	if ( $ranges_error instanceof WP_Error ) {
		return $ranges_error;
	}

	$has_sold = isset( $input['hasSoldPapelito'] ) ? (string) $input['hasSoldPapelito'] : '';
	if ( '' !== $has_sold && ! in_array( $has_sold, array( 'sim', 'nao' ), true ) ) {
		$errors->add( 'hasSoldPapelito', 'Escolha uma opção válida.' );
	}

	$normalized_ceps = papelito_validate_seller_cep_fields( $input, $errors );
	$min_cep         = $normalized_ceps['minCep'];
	$max_cep         = $normalized_ceps['maxCep'];

	if ( '' !== $min_cep && '' !== $max_cep && (int) $min_cep > (int) $max_cep ) {
		$errors->add( 'maxCep', 'O CEP final precisa ser maior ou igual ao CEP inicial.' );
	}

	return null;
}

/**
 * Envia e-mail de triagem para o marketing.
 *
 * @param array $application Dados da candidatura.
 * @return void
 */
function papelito_notify_seller_application( array $application ): void {
	$to         = 'marketing@papelitobrasil.com';
	$subject    = sprintf( 'Nova triagem PDV Perfeito: %s', $application['storeName'] );
	$reply_to   = isset( $application['email'] ) ? sanitize_email( (string) $application['email'] ) : '';
	$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
	$body_lines = array(
		'Nova triagem enviada pelo fluxo /revendedor.',
		'',
		sprintf( 'Status: %s', $application['status'] ),
		sprintf( 'Enviado em: %s', $application['submittedAt'] ),
		sprintf( 'Loja: %s', $application['storeName'] ),
		sprintf( 'Responsável: %s %s', $application['firstName'], $application['lastName'] ),
		sprintf( 'E-mail: %s', $application['email'] ),
		sprintf( 'Telefone: %s', $application['phoneNumber'] ),
		sprintf( 'CNPJ: %s', $application['cnpj'] ),
		sprintf( 'Instagram: @%s', ltrim( (string) $application['instagram'], '@' ) ),
		sprintf( 'Cidade/Estado: %s - %s', $application['city'], $application['state'] ),
		sprintf( 'CEP de operação: %s', $application['cep'] ?? '' ),
		sprintf( 'Faixa atendida: %s - %s', $application['minCep'] ?? '', $application['maxCep'] ?? '' ),
		sprintf( 'Origem do contato: %s', $application['discoveryChannel'] ),
		sprintf( 'Já vende Papelito?: %s', $application['hasSoldPapelito'] ),
	);

	if ( '' !== $reply_to ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	wp_mail( $to, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Envia e-mail de confirmacao da triagem para o candidato.
 *
 * @param array $application Dados da candidatura.
 * @return void
 */
function papelito_notify_seller_application_received( array $application ): void {
	$to = isset( $application['email'] ) ? sanitize_email( (string) $application['email'] ) : '';

	if ( '' === $to ) {
		return;
	}

	$subject    = 'Recebemos sua triagem - Papelito';
	$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
	$body_lines = array(
		sprintf( 'Ola %s,', trim( (string) $application['firstName'] ) !== '' ? $application['firstName'] : $application['storeName'] ),
		'',
		'Recebemos sua triagem para o programa de revendedores Papelito.',
		'Nosso time vai analisar os dados enviados e entrara em contato pelos canais cadastrados.',
		'',
		'Resumo enviado:',
		sprintf( 'Loja: %s', $application['storeName'] ),
		sprintf( 'Responsável: %s %s', $application['firstName'], $application['lastName'] ),
		sprintf( 'E-mail: %s', $application['email'] ),
		sprintf( 'Telefone: %s', $application['phoneNumber'] ),
		sprintf( 'CNPJ: %s', $application['cnpj'] ),
		sprintf( 'Cidade/Estado: %s - %s', $application['city'], $application['state'] ),
		sprintf( 'CEP de operação: %s', $application['cep'] ?? '' ),
		sprintf( 'Faixa atendida: %s - %s', $application['minCep'] ?? '', $application['maxCep'] ?? '' ),
		sprintf( 'Enviado em: %s', $application['submittedAt'] ),
		'',
		'Se precisar atualizar alguma informacao, responda este e-mail ou fale com marketing@papelitobrasil.com.',
		'',
		'Time Papelito',
	);

	wp_mail( $to, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Limpa dados da ultima revisao da candidatura.
 *
 * @param int $user_id Usuario.
 * @return void
 */
function papelito_reset_vendor_application_review( int $user_id ): void {
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META );
}

/**
 * Submete a triagem do usuario autenticado.
 *
 * @param int   $user_id Usuario.
 * @param array $input Payload.
 * @return array<string, mixed>|WP_Error
 */
function papelito_submit_seller_application( int $user_id, array $input ) {
	return new WP_Error(
		'papelito_vendor_self_registration_removed',
		'O autocadastro de vendors foi removido.',
		array( 'status' => 410 )
	);
}

add_action( 'rest_api_init', 'papelito_register_vendor_application_routes' );

/**
 * Registra as rotas REST do onboarding de vendors.
 *
 * @return void
 */
function papelito_register_vendor_application_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/vendor/recipient-draft',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_vendor_application_require_seller',
				'callback'            => 'papelito_get_vendor_recipient_draft_rest',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_vendor_application_require_seller',
				'callback'            => 'papelito_update_vendor_recipient_draft_rest_callback',
			),
		)
	);

	register_rest_route(
		'papelito/v1',
		'/vendor/registration-pending',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_vendor_application_require_seller',
				'callback'            => 'papelito_get_vendor_pending_registration_rest',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_vendor_application_require_seller',
				'callback'            => 'papelito_update_vendor_pending_registration_rest_callback',
			),
		)
	);
}

/**
 * Autoriza o dashboard de vendor nas rotas de onboarding.
 *
 * @return bool|WP_Error
 */
function papelito_vendor_application_require_seller() {
	$check = function_exists( 'papelito_vendor_dashboard_require_seller' ) ? papelito_vendor_dashboard_require_seller() : false;
	return is_wp_error( $check ) ? $check : true;
}

/**
 * Retorna o draft financeiro atual.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_get_vendor_recipient_draft_rest() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return new WP_Error( 'papelito_not_authenticated', PAPELITO_VENDOR_UNAUTHENTICATED_MESSAGE, array( 'status' => 401 ) );
	}
	return new WP_REST_Response( array( 'draft' => papelito_get_vendor_pagarme_recipient_draft( $user_id ) ), 200 );
}

/**
 * Atualiza o draft financeiro.
 *
 * @param WP_REST_Request $request Requisicao REST.
 * @return WP_REST_Response|WP_Error
 */
function papelito_update_vendor_recipient_draft_rest_callback( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return new WP_Error( 'papelito_not_authenticated', PAPELITO_VENDOR_UNAUTHENTICATED_MESSAGE, array( 'status' => 401 ) );
	}
	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'papelito_invalid_payload', PAPELITO_VENDOR_INVALID_PAYLOAD_MESSAGE, array( 'status' => 400 ) );
	}
	$result = papelito_update_vendor_pagarme_recipient_draft_rest( $user_id, $payload );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Retorna os campos pendentes do onboarding.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_get_vendor_pending_registration_rest() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return new WP_Error( 'papelito_not_authenticated', PAPELITO_VENDOR_UNAUTHENTICATED_MESSAGE, array( 'status' => 401 ) );
	}
	return new WP_REST_Response( papelito_get_vendor_pending_registration_rest_response( $user_id ), 200 );
}

/**
 * Atualiza os campos pendentes do onboarding.
 *
 * @param WP_REST_Request $request Requisicao REST.
 * @return WP_REST_Response|WP_Error
 */
function papelito_update_vendor_pending_registration_rest_callback( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return new WP_Error( 'papelito_not_authenticated', PAPELITO_VENDOR_UNAUTHENTICATED_MESSAGE, array( 'status' => 401 ) );
	}
	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'papelito_invalid_payload', PAPELITO_VENDOR_INVALID_PAYLOAD_MESSAGE, array( 'status' => 400 ) );
	}
	$result = papelito_update_vendor_pending_registration_rest( $user_id, $payload );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Retorna o detalhe completo da triagem para o painel admin.
 *
 * @param int $user_id Usuario.
 * @return array<string, mixed>|WP_Error
 */
function papelito_get_vendor_application_detail( int $user_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', PAPELITO_VENDOR_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	if ( ! papelito_user_has_role( $user, 'seller' ) ) {
		return new WP_Error( 'papelito_vendor_not_found', PAPELITO_VENDOR_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$base = papelito_get_seller_application_data( $user_id );
	$bank_account = papelito_get_vendor_bank_account_detail( $user_id );

	$min_ranges = (array) get_user_meta( $user_id, 'min_cep', false );
	$max_ranges = (array) get_user_meta( $user_id, 'max_cep', false );

	$reviewer_id  = (int) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META, true );
	$reviewer     = $reviewer_id > 0 ? get_userdata( $reviewer_id ) : null;
	$reviewer_out = null;

	if ( $reviewer instanceof WP_User ) {
		$reviewer_out = array(
			'id'    => (int) $reviewer->ID,
			'name'  => (string) $reviewer->display_name,
			'email' => (string) $reviewer->user_email,
		);
	}

	$detail                     = $base;
	$detail['id']               = (int) $user->ID;
	$detail['name']             = trim( (string) $user->display_name );
	$detail['minCepRanges']     = array_values( array_filter( array_map( 'strval', $min_ranges ), 'strlen' ) );
	$detail['maxCepRanges']     = array_values( array_filter( array_map( 'strval', $max_ranges ), 'strlen' ) );
	$detail['rejectionReason']  = (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META, true );
	$detail['reviewedAt']       = (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META, true );
	$detail['reviewedBy']       = $reviewer_out;
	$detail['registeredAt']     = (string) $user->user_registered;
	$detail['bankAccount']      = $bank_account;

	return $detail;
}

/**
 * Normaliza os filtros do admin de vendors.
 *
 * @param WP_REST_Request $request Request.
 * @return array<string, int|string>
 */
function papelito_admin_vendors_parse_filters( WP_REST_Request $request ): array {
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = max( 1, min( 100, (int) $request->get_param( 'perPage' ) ) );

	if ( $per_page <= 0 ) {
		$per_page = 20;
	}

	return array(
		'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		'status'  => 'all',
		'page'    => $page,
		'perPage' => $per_page,
	);
}

/**
 * SQL base da listagem de vendors.
 *
 * @return string
 */
function papelito_admin_vendors_base_sql(): string {
	/** @var wpdb $wpdb */
	global $wpdb;

	$users_table    = $wpdb->users;
	$usermeta_table = $wpdb->usermeta;
	$capabilities   = $wpdb->prefix . 'capabilities';

	return "
		FROM {$users_table} u
		LEFT JOIN {$usermeta_table} cap ON cap.user_id = u.ID AND cap.meta_key = '{$capabilities}'
		LEFT JOIN {$usermeta_table} store_name ON store_name.user_id = u.ID AND store_name.meta_key = 'store_name'
		LEFT JOIN {$usermeta_table} phone_number ON phone_number.user_id = u.ID AND phone_number.meta_key = 'phone_number'
		LEFT JOIN {$usermeta_table} cnpj ON cnpj.user_id = u.ID AND cnpj.meta_key = 'cnpj'
		LEFT JOIN {$usermeta_table} state_meta ON state_meta.user_id = u.ID AND state_meta.meta_key = 'state'
		LEFT JOIN {$usermeta_table} city_meta ON city_meta.user_id = u.ID AND city_meta.meta_key = 'city'
		LEFT JOIN {$usermeta_table} first_name ON first_name.user_id = u.ID AND first_name.meta_key = 'first_name'
		LEFT JOIN {$usermeta_table} last_name ON last_name.user_id = u.ID AND last_name.meta_key = 'last_name'
	";
}

/**
 * Clausula WHERE compartilhada da listagem de vendors.
 *
 * @param array<string, int|string> $filters Filtros.
 * @param array<int, mixed>         $args Args preparados.
 * @return string
 */
function papelito_admin_vendors_where_sql( array $filters, array &$args, bool $include_status = true ): string {
	/** @var wpdb $wpdb */
	global $wpdb;

	$conditions = array( 'cap.meta_value LIKE %s' );
	$args[]     = '%s:6:"seller";b:1%';

	if ( ! empty( $filters['search'] ) && is_string( $filters['search'] ) ) {
		$term         = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		$conditions[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR store_name.meta_value LIKE %s OR first_name.meta_value LIKE %s OR last_name.meta_value LIKE %s OR cnpj.meta_value LIKE %s)';
		array_push( $args, $term, $term, $term, $term, $term, $term );
	}

	return ' WHERE ' . implode( ' AND ', $conditions );
}

/**
 * Conta quantos vendors existem no recorte atual da listagem.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return int
 */
function papelito_admin_vendors_count_filtered_rows( array $filters ): int {
	/** @var wpdb $wpdb */
	global $wpdb;

	$args      = array();
	$base_sql  = papelito_admin_vendors_base_sql();
	$where_sql = papelito_admin_vendors_where_sql( $filters, $args, true );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare( 'SELECT COUNT(*) ' . $base_sql . $where_sql, $args );

	return (int) $wpdb->get_var( $sql );
}

/**
 * Consulta as linhas da listagem admin de vendors.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return array<int, array<string, mixed>>
 */
function papelito_admin_vendors_query_rows( array $filters ): array {
	/** @var wpdb $wpdb */
	global $wpdb;

	$args      = array();
	$base_sql  = papelito_admin_vendors_base_sql();
	$where_sql = papelito_admin_vendors_where_sql( $filters, $args );
	$select    = "
		SELECT
			u.ID AS id,
			u.display_name AS display_name,
			u.user_email AS user_email,
			u.user_registered AS user_registered,
			COALESCE(cap.meta_value, '') AS capabilities,
			COALESCE(store_name.meta_value, '') AS store_name,
			COALESCE(phone_number.meta_value, '') AS phone_number,
			COALESCE(cnpj.meta_value, '') AS cnpj,
			COALESCE(state_meta.meta_value, '') AS state,
			COALESCE(city_meta.meta_value, '') AS city,
			COALESCE(first_name.meta_value, '') AS first_name,
			COALESCE(last_name.meta_value, '') AS last_name
	";

	$offset = ( (int) $filters['page'] - 1 ) * (int) $filters['perPage'];

	$args[] = (int) $filters['perPage'];
	$args[] = $offset;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql      = $wpdb->prepare( $select . $base_sql . $where_sql . ' ORDER BY u.user_registered DESC, u.ID DESC LIMIT %d OFFSET %d', $args );
	$raw_rows = $wpdb->get_results( $sql, ARRAY_A );
	$rows     = array();

	foreach ( $raw_rows as $raw_row ) {
		$rows[] = papelito_admin_vendors_build_row( $raw_row );
	}

	if ( function_exists( 'papelito_admin_reports_attach_coverage_summary' ) ) {
		return papelito_admin_reports_attach_coverage_summary( $rows );
	}

	return $rows;
}

/**
 * Le um campo textual da linha bruta da fila admin.
 *
 * @param array<string, mixed> $raw_row Linha bruta.
 * @param string               $key Chave desejada.
 * @return string
 */
function papelito_admin_vendors_row_text( array $raw_row, string $key ): string {
	return isset( $raw_row[ $key ] ) ? (string) $raw_row[ $key ] : '';
}

/**
 * Resolve o nome exibido do vendor na fila admin.
 *
 * @param array<string, mixed> $raw_row Linha bruta.
 * @return string
 */
function papelito_admin_vendors_row_name( array $raw_row ): string {
	$display_name = trim( papelito_admin_vendors_row_text( $raw_row, 'display_name' ) );
	if ( '' !== $display_name ) {
		return $display_name;
	}

	$first_name = trim( papelito_admin_vendors_row_text( $raw_row, 'first_name' ) );
	$last_name  = trim( papelito_admin_vendors_row_text( $raw_row, 'last_name' ) );
	$full_name  = trim( $first_name . ' ' . $last_name );
	if ( '' !== $full_name ) {
		return $full_name;
	}

	return isset( $raw_row['user_email'] ) ? (string) $raw_row['user_email'] : 'Usuário sem nome';
}

/**
 * Resolve o papel e o rotulo do vendor na fila admin.
 *
 * @param array<string, mixed> $raw_row Linha bruta.
 * @return array{role: string, label: string}
 */
function papelito_admin_vendors_row_role( array $raw_row ): array {
	$role = 'other';
	if ( function_exists( 'papelito_admin_reports_detect_role' ) ) {
		$role = papelito_admin_reports_detect_role( papelito_admin_vendors_row_text( $raw_row, 'capabilities' ) );
	}

	$label = function_exists( 'papelito_admin_reports_role_label' )
		? papelito_admin_reports_role_label( $role )
		: ucfirst( $role );

	return array(
		'role'  => $role,
		'label' => $label,
	);
}

/**
 * Converte uma linha SQL em um item da fila admin.
 *
 * @param array<string, mixed> $raw_row Linha bruta.
 * @return array<string, mixed>
 */
function papelito_admin_vendors_build_row( array $raw_row ): array {
	$role = papelito_admin_vendors_row_role( $raw_row );

	return array(
		'id'              => isset( $raw_row['id'] ) ? (int) $raw_row['id'] : 0,
		'name'            => papelito_admin_vendors_row_name( $raw_row ),
		'email'           => papelito_admin_vendors_row_text( $raw_row, 'user_email' ),
		'role'            => $role['role'],
		'roleLabel'       => $role['label'],
		'storeName'       => papelito_admin_vendors_row_text( $raw_row, 'store_name' ),
		'phoneNumber'     => papelito_admin_vendors_row_text( $raw_row, 'phone_number' ),
		'cnpj'            => papelito_admin_vendors_row_text( $raw_row, 'cnpj' ),
		'state'           => papelito_admin_vendors_row_text( $raw_row, 'state' ),
		'city'            => papelito_admin_vendors_row_text( $raw_row, 'city' ),
		'registeredAt'    => papelito_admin_vendors_row_text( $raw_row, 'user_registered' ),
		'coverageSummary' => 'Sem cobertura',
	);
}

/**
 * Consulta totais da listagem admin de vendors.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return array<string, int>
 */
function papelito_admin_vendors_query_summary( array $filters ): array {
	/** @var wpdb $wpdb */
	global $wpdb;

	$filtered_users = papelito_admin_vendors_count_filtered_rows( $filters );

	$args              = array();
	$base_sql          = papelito_admin_vendors_base_sql();
	$search_only_where = papelito_admin_vendors_where_sql( $filters, $args, false );

	$coverage_exists = "EXISTS (
		SELECT 1 FROM {$wpdb->usermeta} coverage_min
		WHERE coverage_min.user_id = u.ID
		AND coverage_min.meta_key = 'min_cep'
		AND coverage_min.meta_value <> ''
	) AND EXISTS (
		SELECT 1 FROM {$wpdb->usermeta} coverage_max
		WHERE coverage_max.user_id = u.ID
		AND coverage_max.meta_key = 'max_cep'
		AND coverage_max.meta_value <> ''
	)";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		"
		SELECT
			COUNT(*) AS sellers_count,
			SUM(CASE WHEN {$coverage_exists} THEN 1 ELSE 0 END) AS users_with_coverage
		" . $base_sql . $search_only_where,
		$args
	);

	$summary = $wpdb->get_row( $sql, ARRAY_A );

	return array(
		'filteredUsers'       => $filtered_users,
		'totalVendors'        => isset( $summary['sellers_count'] ) ? (int) $summary['sellers_count'] : 0,
		'usersWithCoverage'   => isset( $summary['users_with_coverage'] ) ? (int) $summary['users_with_coverage'] : 0,
	);
}

/**
 * Monta o snapshot da fila admin de vendors.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return array<string, mixed>
 */
function papelito_admin_vendors_get_snapshot( array $filters ): array {
	$summary     = papelito_admin_vendors_query_summary( $filters );
	$total_rows  = (int) $summary['filteredUsers'];
	$total_pages = max( 1, (int) ceil( $total_rows / max( 1, (int) $filters['perPage'] ) ) );
	$safe_page   = min( max( 1, (int) $filters['page'] ), $total_pages );

	if ( $safe_page !== (int) $filters['page'] ) {
		$filters['page'] = $safe_page;
	}

	$rows = papelito_admin_vendors_query_rows( $filters );

	return array(
		'rows'        => $rows,
		'summary'     => $summary,
		'currentPage' => $safe_page,
		'perPage'     => (int) $filters['perPage'],
		'totalRows'   => $total_rows,
		'totalPages'  => $total_pages,
		'issues'      => array(),
	);
}

/**
 * Normaliza um CNPJ para comparacoes de unicidade.
 *
 * @param string $value CNPJ bruto.
 * @return string
 */
function papelito_admin_vendors_normalize_document_digits( string $value ): string {
	$digits = preg_replace( '/\D+/', '', $value );

	return is_string( $digits ) ? $digits : '';
}

/**
 * Retorna se ja existe usuario com o CNPJ informado.
 *
 * @param string $cnpj CNPJ.
 * @return bool
 */
function papelito_admin_vendors_cnpj_exists( string $cnpj, int $exclude_user_id = 0 ): bool {
	/** @var wpdb $wpdb */
	global $wpdb;

	$target = papelito_admin_vendors_normalize_document_digits( $cnpj );

	if ( '' === $target ) {
		return false;
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
			'cnpj'
		),
		ARRAY_A
	);

	foreach ( $rows as $row ) {
		$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		if ( $exclude_user_id > 0 && $exclude_user_id === $user_id ) {
			continue;
		}

		$value = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
		if ( $target === papelito_admin_vendors_normalize_document_digits( $value ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Retorna se o usuario existente pode ser promovido para seller.
 *
 * @param WP_User $user Usuario encontrado pelo e-mail.
 * @return bool
 */
function papelito_admin_vendors_is_convertible_customer( WP_User $user ): bool {
	$roles = array_values(
		array_filter(
			array_map( 'strval', (array) $user->roles ),
			'strlen'
		)
	);

	return 1 === count( $roles ) && 'customer' === $roles[0];
}

/**
 * Valida e normaliza as faixas de CEP enviadas pelo admin.
 *
 * @param mixed $ranges Faixas brutas.
 * @return array<int, array<string, string>>|WP_Error
 */
function papelito_admin_vendors_normalize_coverage_ranges( $ranges ) {
	if ( ! is_array( $ranges ) || empty( $ranges ) ) {
		return new WP_Error(
			'papelito_admin_vendor_missing_coverage',
			'Informe ao menos uma faixa de CEP.',
			array( 'status' => 422 )
		);
	}

	$normalized = array();

	foreach ( $ranges as $index => $range ) {
		if ( ! is_array( $range ) ) {
			return new WP_Error(
				'papelito_admin_vendor_invalid_coverage',
				'Informe faixas de CEP validas.',
				array( 'status' => 422 )
			);
		}

		$min_cep = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $range['minCep'] ?? '' ) ) : '';
		$max_cep = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $range['maxCep'] ?? '' ) ) : '';

		if ( '' === $min_cep || '' === $max_cep ) {
			return new WP_Error(
				'papelito_admin_vendor_invalid_coverage',
				sprintf( 'Informe CEP inicial e final validos na faixa %d.', $index + 1 ),
				array( 'status' => 422 )
			);
		}

		if ( (int) $min_cep > (int) $max_cep ) {
			return new WP_Error(
				'papelito_admin_vendor_invalid_coverage_order',
				sprintf( 'O CEP final precisa ser maior ou igual ao inicial na faixa %d.', $index + 1 ),
				array( 'status' => 422 )
			);
		}

		$normalized[] = array(
			'minCep' => $min_cep,
			'maxCep' => $max_cep,
		);
	}

	return $normalized;
}

/**
 * Valida e normaliza a conta bancaria enviada pelo admin.
 *
 * @param mixed $bank_account Conta bancaria bruta.
 * @return array<string, string>|WP_Error
 */
function papelito_admin_vendors_normalize_bank_account( $bank_account ) {
	if ( ! is_array( $bank_account ) ) {
		return new WP_Error(
			'papelito_admin_vendor_missing_bank_account',
			'Informe os dados bancários.',
			array( 'status' => 422 )
		);
	}

	$holder_type = sanitize_text_field( (string) ( $bank_account['holderType'] ?? '' ) );
	$type        = sanitize_text_field( (string) ( $bank_account['type'] ?? '' ) );
	$normalized  = array(
		'holderName'        => sanitize_text_field( (string) ( $bank_account['holderName'] ?? '' ) ),
		'holderType'        => $holder_type,
		'holderDocument'    => sanitize_text_field( (string) ( $bank_account['holderDocument'] ?? '' ) ),
		'bankCode'          => papelito_admin_vendors_normalize_document_digits( (string) ( $bank_account['bankCode'] ?? '' ) ),
		'branchNumber'      => papelito_admin_vendors_normalize_document_digits( (string) ( $bank_account['branchNumber'] ?? '' ) ),
		'branchCheckDigit'  => sanitize_text_field( (string) ( $bank_account['branchCheckDigit'] ?? '' ) ),
		'accountNumber'     => papelito_admin_vendors_normalize_document_digits( (string) ( $bank_account['accountNumber'] ?? '' ) ),
		'accountCheckDigit' => sanitize_text_field( (string) ( $bank_account['accountCheckDigit'] ?? '' ) ),
		'type'              => $type,
	);

	$errors = new WP_Error();

	if ( '' === $normalized['holderName'] ) {
		$errors->add( 'bankHolderName', 'Informe o titular da conta.' );
	}

	if ( ! in_array( $holder_type, array( 'company', 'individual' ), true ) ) {
		$errors->add( 'bankHolderType', 'Informe o tipo do titular da conta.' );
	}

	if ( 'individual' === $holder_type ) {
if ( ! papelito_revendedor_validate_cpf( $normalized['holderDocument'] ) ) {
			$errors->add( 'bankHolderDocument', 'Informe um CPF válido para o titular.' );
		}
	} elseif ( 'company' === $holder_type && 1 !== preg_match( PAPELITO_VENDOR_CNPJ_PATTERN, $normalized['holderDocument'] ) ) {
		$errors->add( 'bankHolderDocument', 'Informe um CNPJ válido para o titular.' );
	}

	if ( 1 !== preg_match( PAPELITO_VENDOR_BANK_CODE_PATTERN, $normalized['bankCode'] ) ) {
		$errors->add( 'bankCode', 'Informe um código bancário com 3 digitos.' );
	}

	if ( 1 !== preg_match( PAPELITO_VENDOR_DIGITS_PATTERN, $normalized['branchNumber'] ) ) {
		$errors->add( 'branchNumber', 'Informe uma agência válida.' );
	}

	if ( 1 !== preg_match( PAPELITO_VENDOR_DIGITS_PATTERN, $normalized['accountNumber'] ) ) {
		$errors->add( 'accountNumber', 'Informe uma conta válida.' );
	}

	if ( 1 !== preg_match( PAPELITO_VENDOR_ACCOUNT_CHECK_DIGIT_PATTERN, $normalized['accountCheckDigit'] ) ) {
		$errors->add( 'accountCheckDigit', 'Informe o digito da conta.' );
	}

	if ( ! in_array( $type, array( 'checking', 'savings' ), true ) ) {
		$errors->add( 'bankAccountType', 'Informe o tipo da conta bancária.' );
	}

	if ( $errors->has_errors() ) {
		$errors->add_data( array( 'status' => 422 ) );
		return $errors;
	}

	return $normalized;
}

/**
 * Monta e valida o draft Pagar.me enviado pelo admin.
 *
 * @param array<string, string> $bank_account Conta bancaria.
 * @param array<string, mixed>  $input Payload original.
 * @return array<string, mixed>|WP_Error
 */
function papelito_admin_vendors_build_pagarme_draft( array $bank_account, array $input ) {
	$draft = isset( $input['pagarmeDraft'] ) && is_array( $input['pagarmeDraft'] ) ? $input['pagarmeDraft'] : array();

	$normalized = array(
		'companyName'       => sanitize_text_field( (string) ( $draft['companyName'] ?? $input['storeName'] ?? '' ) ),
		'tradingName'       => sanitize_text_field( (string) ( $draft['tradingName'] ?? $input['storeName'] ?? '' ) ),
		'corporationType'   => sanitize_text_field( (string) ( $draft['corporationType'] ?? '' ) ),
		'foundingDate'      => sanitize_text_field( (string) ( $draft['foundingDate'] ?? '' ) ),
		'annualRevenue'     => sanitize_text_field( (string) ( $draft['annualRevenue'] ?? '' ) ),
		'managingPartners'  => isset( $draft['managingPartners'] ) && is_array( $draft['managingPartners'] ) ? $draft['managingPartners'] : array(),
		'bankAccount'       => $bank_account,
		'transfer'          => array(
			'interval' => sanitize_text_field( (string) ( $draft['transfer']['interval'] ?? 'Daily' ) ),
			'day'      => sanitize_text_field( (string) ( $draft['transfer']['day'] ?? '0' ) ),
		),
	);

	$validation = papelito_validate_vendor_pagarme_step3( $normalized );
	if ( $validation instanceof WP_Error ) {
		$validation->add_data( array( 'status' => 422 ) );
		return $validation;
	}

	return $normalized;
}

/**
 * Cria um vendor diretamente pelo painel admin.
 *
 * @param array<string, mixed> $input Payload.
 * @param int                  $reviewer_id Admin.
 * @return array<string, mixed>|WP_Error
 */
function papelito_admin_vendors_create_direct_vendor( array $input, int $reviewer_id ) {
	$prepared = papelito_admin_vendors_prepare_direct_vendor( $input, $reviewer_id );
	if ( is_wp_error( $prepared ) ) {
		return $prepared;
	}

	$user_id = papelito_admin_vendors_persist_direct_vendor( $prepared );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$closed_application = papelito_pre_account_application_reject_open_for_vendor( $prepared['email'], $reviewer_id );
	if ( is_wp_error( $closed_application ) ) {
		return $closed_application;
	}

	return papelito_get_vendor_application_detail( $user_id );
}

/**
 * Resolve a conta customer existente para promocao direta.
 *
 * @param string $email E-mail informado.
 * @param int    $source_user_id Usuario de origem.
 * @return WP_User|null|WP_Error
 */
function papelito_admin_vendors_resolve_direct_source( string $email, int $source_user_id ) {
	if ( $source_user_id > 0 ) {
		$source_user = get_userdata( $source_user_id );
		if ( ! $source_user instanceof WP_User || ! papelito_admin_vendors_is_convertible_customer( $source_user ) ) {
			return new WP_Error( 'papelito_admin_vendor_invalid_source_customer', 'O customer selecionado nao esta disponivel para promocao.', array( 'status' => 409 ) );
		}
		$email_owner = get_user_by( 'email', $email );
		if ( $email_owner instanceof WP_User && (int) $email_owner->ID !== $source_user_id ) {
			return new WP_Error( 'papelito_admin_vendor_email_exists', 'Ja existe outra conta com este e-mail.', array( 'status' => 409 ) );
		}
		return $source_user;
	}

	if ( get_user_by( 'email', $email ) instanceof WP_User ) {
		return new WP_Error( 'papelito_admin_vendor_source_customer_required', 'Este e-mail já pertence a uma conta. Selecione o customer pelo painel para promove-lo.', array( 'status' => 409 ) );
	}

	return null;
}

/**
 * Normaliza os dados de perfil e endereco do vendor.
 *
 * @param array  $input Dados recebidos.
 * @param string $email E-mail normalizado.
 * @return array<string, string>|WP_Error
 */
function papelito_admin_vendors_normalize_direct_profile( array $input, string $email ) {
	$profile = array(
		'street'       => sanitize_text_field( (string) ( $input['street'] ?? '' ) ),
		'number'       => sanitize_text_field( (string) ( $input['number'] ?? '' ) ),
		'neighborhood' => sanitize_text_field( (string) ( $input['neighborhood'] ?? '' ) ),
		'state'        => sanitize_text_field( (string) ( $input['state'] ?? '' ) ),
		'city'         => sanitize_text_field( (string) ( $input['city'] ?? '' ) ),
		'firstName'    => sanitize_text_field( (string) ( $input['firstName'] ?? '' ) ),
		'lastName'     => sanitize_text_field( (string) ( $input['lastName'] ?? '' ) ),
		'storeName'    => sanitize_text_field( (string) ( $input['storeName'] ?? '' ) ),
	);
	$profile['displayName'] = trim( $profile['firstName'] . ' ' . $profile['lastName'] );
	if ( '' === $profile['displayName'] ) {
		$profile['displayName'] = '' !== $profile['storeName'] ? $profile['storeName'] : $email;
	}
	if ( '' === $profile['storeName'] ) {
		return new WP_Error( 'papelito_admin_vendor_missing_store_name', PAPELITO_VENDOR_MISSING_STORE_NAME_MESSAGE, array( 'status' => 422 ) );
	}
	if ( '' === $profile['street'] || '' === $profile['number'] || '' === $profile['neighborhood'] || '' === $profile['city'] || '' === $profile['state'] ) {
		return new WP_Error( 'papelito_admin_vendor_incomplete_address', 'Informe o endereço comercial completo do vendor.', array( 'status' => 422 ) );
	}

	return $profile;
}

/**
 * Valida e normaliza os dados para criacao direta de vendor.
 *
 * @param array $input Dados recebidos.
 * @param int   $reviewer_id Admin responsavel.
 * @return array<string, mixed>|WP_Error
 */
function papelito_admin_vendors_prepare_direct_vendor( array $input, int $reviewer_id ) {
	if ( ! current_user_can( 'manage_options' ) || get_current_user_id() !== $reviewer_id ) {
		return new WP_Error( 'papelito_admin_forbidden', 'Apenas administradores podem criar vendors.', array( 'status' => 403 ) );
	}

	$email          = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$cnpj           = sanitize_text_field( (string) ( $input['cnpj'] ?? '' ) );
	$source_user_id = absint( $input['sourceUserId'] ?? 0 );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'papelito_admin_vendor_invalid_email', 'Informe um e-mail valido.', array( 'status' => 422 ) );
	}

	$existing_user = papelito_admin_vendors_resolve_direct_source( $email, $source_user_id );
	if ( is_wp_error( $existing_user ) ) {
		return $existing_user;
	}

	$temporary_password = isset( $input['temporaryPassword'] ) ? (string) $input['temporaryPassword'] : '';
	if ( ! $existing_user instanceof WP_User && '' === trim( $temporary_password ) ) {
		return new WP_Error( 'papelito_admin_vendor_missing_temporary_password', 'Informe uma senha temporária para a nova conta vendor.', array( 'status' => 422 ) );
	}
	if ( 1 !== preg_match( PAPELITO_VENDOR_CNPJ_PATTERN, $cnpj ) ) {
		return new WP_Error( 'papelito_admin_vendor_invalid_cnpj', 'Informe um CNPJ valido.', array( 'status' => 422 ) );
	}

	$existing_user_id = $existing_user instanceof WP_User ? (int) $existing_user->ID : 0;
	if ( papelito_admin_vendors_cnpj_exists( $cnpj, $existing_user_id ) ) {
		return new WP_Error( 'papelito_admin_vendor_cnpj_exists', 'Ja existe uma conta com este CNPJ.', array( 'status' => 409 ) );
	}
	$ranges = papelito_admin_vendors_normalize_coverage_ranges( $input['coverageRanges'] ?? null );
	if ( is_wp_error( $ranges ) ) {
		return $ranges;
	}

	$profile = papelito_admin_vendors_normalize_direct_profile( $input, $email );
	if ( is_wp_error( $profile ) ) {
		return $profile;
	}

	$raw_pagarme_draft = isset( $input['pagarmeDraft'] ) && is_array( $input['pagarmeDraft'] ) ? $input['pagarmeDraft'] : array();
	$pagarme_draft     = papelito_sanitize_vendor_pagarme_step3_partial(
		$raw_pagarme_draft,
		array(
			'storeName'    => $profile['storeName'],
			'email'        => $email,
			'cnpj'         => $cnpj,
			'cep'          => (string) ( $input['cep'] ?? '' ),
			'street'       => $profile['street'],
			'number'       => $profile['number'],
			'complement'   => (string) ( $input['complement'] ?? '' ),
			'neighborhood' => $profile['neighborhood'],
			'city'         => $profile['city'],
			'state'        => $profile['state'],
			'firstName'    => $profile['firstName'],
			'lastName'     => $profile['lastName'],
		)
	);
	$pagarme_draft['companyName'] = sanitize_text_field( (string) ( $raw_pagarme_draft['companyName'] ?? '' ) );
	$pagarme_draft['tradingName'] = sanitize_text_field( (string) ( $raw_pagarme_draft['tradingName'] ?? '' ) );

	return array(
		'input'           => $input,
		'email'           => $email,
		'cnpj'            => $cnpj,
		'ranges'          => $ranges,
		'existingUser'    => $existing_user,
		'existingUserId'  => $existing_user_id,
		'temporaryPassword' => $temporary_password,
		'firstName'       => $profile['firstName'],
		'lastName'        => $profile['lastName'],
		'displayName'     => $profile['displayName'],
		'storeName'       => $profile['storeName'],
		'street'          => $profile['street'],
		'number'          => $profile['number'],
		'neighborhood'    => $profile['neighborhood'],
		'state'           => $profile['state'],
		'city'            => $profile['city'],
		'pagarmeDraft'    => $pagarme_draft,
	);
}

/**
 * Persiste a conta e as metas de um vendor criado pelo admin.
 *
 * @param array<string, mixed> $vendor Dados normalizados.
 * @return int|WP_Error
 */
function papelito_admin_vendors_persist_direct_vendor( array $vendor ) {
	$existing_user      = $vendor['existingUser'];
	$is_new_user        = ! $existing_user instanceof WP_User;
	$temporary_password = (string) $vendor['temporaryPassword'];
	$password           = '' !== $temporary_password ? $temporary_password : wp_generate_password( 32, true, true );
	$user_id            = $is_new_user
		? wp_insert_user( array( 'user_login' => $vendor['email'], 'user_email' => $vendor['email'], 'user_pass' => $password, 'first_name' => $vendor['firstName'], 'last_name' => $vendor['lastName'], 'display_name' => $vendor['displayName'], 'role' => 'seller' ) )
		: wp_update_user( array( 'ID' => $vendor['existingUserId'], 'user_email' => $vendor['email'], 'first_name' => $vendor['firstName'], 'last_name' => $vendor['lastName'], 'display_name' => $vendor['displayName'] ) );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	$user_id = (int) $user_id;
	if ( $is_new_user ) {
		papelito_auth_mark_email_pending( $user_id );
	}
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', PAPELITO_VENDOR_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$user->set_role( 'seller' );
	$input = $vendor['input'];
	update_user_meta( $user_id, 'store_name', $vendor['storeName'] );
	update_user_meta( $user_id, 'phone_number', papelito_auth_format_phone( (string) ( $input['phoneNumber'] ?? '' ) ) );
	update_user_meta( $user_id, 'cnpj', $vendor['cnpj'] );
	update_user_meta( $user_id, 'instagram', sanitize_text_field( ltrim( (string) ( $input['instagram'] ?? '' ), '@' ) ) );
	update_user_meta( $user_id, 'state', $vendor['state'] );
	update_user_meta( $user_id, 'city', $vendor['city'] );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, $vendor['street'] );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, $vendor['number'] );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, sanitize_text_field( (string) ( $input['complement'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, $vendor['neighborhood'] );

	$cep_base = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['cep'] ?? '' ) ) : '';
	update_user_meta( $user_id, 'cep', $cep_base );
	delete_user_meta( $user_id, 'min_cep' );
	delete_user_meta( $user_id, 'max_cep' );
	foreach ( $vendor['ranges'] as $range ) {
		add_user_meta( $user_id, 'min_cep', $range['minCep'], false );
		add_user_meta( $user_id, 'max_cep', $range['maxCep'], false );
	}

	$discovery_channel = sanitize_text_field( (string) ( $input['discoveryChannel'] ?? '' ) );
	$discovery_channel = '' !== $discovery_channel ? $discovery_channel : PAPELITO_ADMIN_VENDOR_DISCOVERY_CHANNEL_DEFAULT;
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_DISCOVERY_CHANNEL_META, $discovery_channel );
	$has_sold = sanitize_text_field( (string) ( $input['hasSoldPapelito'] ?? '' ) );
	$has_sold = '' !== $has_sold ? $has_sold : PAPELITO_ADMIN_VENDOR_HAS_SOLD_PAPELITO_DEFAULT;
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_HAS_SOLD_PAPELITO_META, $has_sold );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_SUBMITTED_AT_META, papelito_current_utc_mysql() );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META );
	papelito_save_vendor_pagarme_recipient_draft( $user_id, $vendor['pagarmeDraft'] );
	$pending_fields = papelito_refresh_vendor_pending_registration_state( $user_id, $vendor['pagarmeDraft'] );
	papelito_sync_vendor_pending_registration_status( $user_id, $pending_fields );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META );
	if ( function_exists( 'papelito_apply_vendor_geo' ) && '' !== $cep_base ) {
		papelito_apply_vendor_geo( $user_id, $cep_base );
	}
	if ( $is_new_user ) {
		papelito_admin_vendors_dispatch_first_access_emails( $user, '' !== $temporary_password );
	}
	papelito_maybe_autosync_vendor_recipient( $user_id, $pending_fields );
	if ( ! empty( $pending_fields ) ) {
		do_action( 'papelito_vendor_pending_registration_created', $user_id, $pending_fields );
	}

	return $user_id;
}

/**
 * Dispara os e-mails de primeiro acesso do vendor criado pelo painel.
 *
 * A conta nasce com verificacao de e-mail `pending`, e o gate de login barra qualquer tentativa
 * enquanto isso — entao o link de confirmacao e obrigatorio, senao o vendor fica travado sem
 * nenhuma pista do que fazer. O e-mail nativo `wp_new_user_notification()` nao serve aqui: manda
 * o vendor definir senha no `wp-login.php`, expondo o backend que o headless esconde.
 *
 * Quando o admin nao informou senha temporaria, `wp_insert_user` gerou uma aleatoria que ninguem
 * conhece; nesse caso o vendor tambem precisa do link de redefinicao para conseguir entrar.
 *
 * @param WP_User $user                    Vendor recem-criado.
 * @param bool    $has_temporary_password  Se o admin informou uma senha temporaria.
 * @return void
 */
function papelito_admin_vendors_dispatch_first_access_emails( WP_User $user, bool $has_temporary_password ): void {
	if ( function_exists( 'papelito_auth_dispatch_verification_email' ) ) {
		$dispatched = papelito_auth_dispatch_verification_email( $user );

		if ( is_wp_error( $dispatched ) ) {
			error_log( 'papelito: vendor criado sem e-mail de confirmacao (' . $dispatched->get_error_code() . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	if ( ! $has_temporary_password && function_exists( 'papelito_auth_dispatch_password_reset_email' ) ) {
		$dispatched = papelito_auth_dispatch_password_reset_email( $user );

		if ( is_wp_error( $dispatched ) ) {
			error_log( 'papelito: vendor criado sem e-mail de definicao de senha (' . $dispatched->get_error_code() . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

/**
 * Permission callback compartilhado dos endpoints admin de vendors.
 */
function papelito_admin_vendors_require_admin(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Valida o parametro de id de vendor nas rotas REST.
 *
 * @param mixed $value Valor enviado.
 */
function papelito_admin_vendors_validate_id( $value ): bool {
	return is_numeric( $value ) && (int) $value > 0;
}

/**
 * GET /admin/vendors — fila paginada.
 */
function papelito_admin_vendors_handle_list( WP_REST_Request $request ) {
	return new WP_REST_Response(
		papelito_admin_vendors_get_snapshot( papelito_admin_vendors_parse_filters( $request ) ),
		200
	);
}

/**
 * POST /admin/vendors — cria vendor aprovado diretamente.
 */
function papelito_admin_vendors_handle_create( WP_REST_Request $request ) {
	$payload = $request->get_json_params();

	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'papelito_invalid_payload', PAPELITO_VENDOR_INVALID_PAYLOAD_MESSAGE, array( 'status' => 400 ) );
	}

	$result = papelito_admin_vendors_create_direct_vendor( $payload, get_current_user_id() );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
}

/**
 * GET /admin/vendors/{id} — detalhe da triagem.
 */
function papelito_admin_vendors_handle_get( WP_REST_Request $request ) {
	$detail = papelito_get_vendor_application_detail( (int) $request['id'] );

	return is_wp_error( $detail ) ? $detail : new WP_REST_Response( $detail, 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1/admin',
			'/vendors',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_admin_vendors_require_admin',
					'callback'            => 'papelito_admin_vendors_handle_list',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_admin_vendors_require_admin',
					'callback'            => 'papelito_admin_vendors_handle_create',
				),
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/vendors/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_admin_vendors_require_admin',
				'args'                => array(
					'id' => array(
						'validate_callback' => 'papelito_admin_vendors_validate_id',
					),
				),
				'callback'            => 'papelito_admin_vendors_handle_get',
			)
		);
	}
);
