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
	$status = (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META, true );

	if ( '' !== $status ) {
		return $status;
	}

	if ( papelito_user_is_effective_seller( $user_id ) ) {
		return 'approved';
	}

	return 'none';
}

/**
 * Sincroniza o status persistido do vendor com os campos pendentes do cadastro.
 *
 * @param int                  $user_id Usuario.
 * @param array<int, string>   $pending_fields Campos pendentes normalizados.
 * @return void
 */
function papelito_sync_vendor_pending_registration_status( int $user_id, array $pending_fields ): void {
	$status = papelito_get_seller_application_status( $user_id );

	if ( empty( $pending_fields ) ) {
		update_user_meta( $user_id, 'papelito_profile_complete', '1' );

		if ( PAPELITO_VENDOR_APPLICATION_INCOMPLETE_STATUS === $status ) {
			update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META, 'approved' );
			update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META, papelito_current_utc_mysql() );
			delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META );
		}

		return;
	}

	update_user_meta( $user_id, 'papelito_profile_complete', '0' );

	if ( ! in_array( $status, array( 'pending', 'rejected' ), true ) ) {
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META, PAPELITO_VENDOR_APPLICATION_INCOMPLETE_STATUS );
	}
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

	$normalized = array(
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

	return $normalized;
}

/**
 * Calcula os campos pendentes do draft financeiro.
 *
 * @param array<string, mixed> $step3 Draft normalizado.
 * @return array<int, string>
 */
function papelito_collect_vendor_pending_registration_fields( array $step3 ): array {
	$pending = array();

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
	if ( 1 !== preg_match( '/^\d{4}\-\d{2}\-\d{2}$/', $founding_date ) ) {
		$pending[] = 'foundingDate';
	}
	if ( ! is_numeric( $annual_revenue ) || (float) $annual_revenue <= 0 ) {
		$pending[] = 'annualRevenue';
	}

	$partner = isset( $step3['managingPartners'][0] ) && is_array( $step3['managingPartners'][0] )
		? $step3['managingPartners'][0]
		: array();
	$partner_address = isset( $partner['address'] ) && is_array( $partner['address'] )
		? $partner['address']
		: array();

	if ( '' === sanitize_text_field( (string) ( $partner['name'] ?? '' ) ) ) {
		$pending[] = 'partner.name';
	}
	if ( ! is_email( sanitize_email( (string) ( $partner['email'] ?? '' ) ) ) ) {
		$pending[] = 'partner.email';
	}
	if ( ! papelito_validate_cpf( (string) ( $partner['document'] ?? '' ) ) ) {
		$pending[] = 'partner.document';
	}
	if ( '' === sanitize_text_field( (string) ( $partner['motherName'] ?? '' ) ) ) {
		$pending[] = 'partner.motherName';
	}
	if ( 1 !== preg_match( '/^\d{4}\-\d{2}\-\d{2}$/', sanitize_text_field( (string) ( $partner['birthdate'] ?? '' ) ) ) ) {
		$pending[] = 'partner.birthdate';
	}

	$monthly_income = str_replace( ',', '.', sanitize_text_field( (string) ( $partner['monthlyIncome'] ?? '' ) ) );
	if ( ! is_numeric( $monthly_income ) || (float) $monthly_income <= 0 ) {
		$pending[] = 'partner.monthlyIncome';
	}
	if ( '' === sanitize_text_field( (string) ( $partner['professionalOccupation'] ?? '' ) ) ) {
		$pending[] = 'partner.professionalOccupation';
	}

	$zip = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $partner_address['zipCode'] ?? '' ) ) : '';
	if ( '' === $zip ) {
		$pending[] = 'partner.address.zipCode';
	}
	if ( '' === sanitize_text_field( (string) ( $partner_address['street'] ?? '' ) ) ) {
		$pending[] = 'partner.address.street';
	}
	if ( '' === sanitize_text_field( (string) ( $partner_address['streetNumber'] ?? '' ) ) ) {
		$pending[] = 'partner.address.streetNumber';
	}
	if ( '' === sanitize_text_field( (string) ( $partner_address['neighborhood'] ?? '' ) ) ) {
		$pending[] = 'partner.address.neighborhood';
	}
	if ( '' === sanitize_text_field( (string) ( $partner_address['city'] ?? '' ) ) ) {
		$pending[] = 'partner.address.city';
	}

	$partner_state = sanitize_text_field( (string) ( $partner_address['state'] ?? '' ) );
	if ( '' === $partner_state || ! array_key_exists( $partner_state, papelito_brazilian_states() ) ) {
		$pending[] = 'partner.address.state';
	}

	$bank_account    = isset( $step3['bankAccount'] ) && is_array( $step3['bankAccount'] ) ? $step3['bankAccount'] : array();
	$holder_type     = sanitize_text_field( (string) ( $bank_account['holderType'] ?? '' ) );
	$holder_document = (string) ( $bank_account['holderDocument'] ?? '' );

	if ( '' === sanitize_text_field( (string) ( $bank_account['holderName'] ?? '' ) ) ) {
		$pending[] = 'bankAccount.holderName';
	}
	if ( 'individual' === $holder_type ) {
		if ( ! papelito_validate_cpf( $holder_document ) ) {
			$pending[] = 'bankAccount.holderDocument';
		}
	} elseif ( 1 !== preg_match( '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', $holder_document ) ) {
		$pending[] = 'bankAccount.holderDocument';
	}
	if ( 1 !== preg_match( '/^\d{3}$/', sanitize_text_field( (string) ( $bank_account['bankCode'] ?? '' ) ) ) ) {
		$pending[] = 'bankAccount.bankCode';
	}
	if ( 1 !== preg_match( '/^\d+$/', sanitize_text_field( (string) ( $bank_account['branchNumber'] ?? '' ) ) ) ) {
		$pending[] = 'bankAccount.branchNumber';
	}
	if ( 1 !== preg_match( '/^\d+$/', sanitize_text_field( (string) ( $bank_account['accountNumber'] ?? '' ) ) ) ) {
		$pending[] = 'bankAccount.accountNumber';
	}
	if ( 1 !== preg_match( '/^[0-9A-Za-z]+$/', sanitize_text_field( (string) ( $bank_account['accountCheckDigit'] ?? '' ) ) ) ) {
		$pending[] = 'bankAccount.accountCheckDigit';
	}

	return array_values( array_intersect( papelito_vendor_pending_registration_allowed_fields(), array_unique( $pending ) ) );
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

	papelito_maybe_autosync_vendor_recipient( $user_id, $pending_fields );

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
		$step1 = isset( $application['step1'] ) && is_array( $application['step1'] ) ? $application['step1'] : array();
		$step2 = isset( $application['step2'] ) && is_array( $application['step2'] ) ? $application['step2'] : array();

		$email = sanitize_email( (string) ( $step1['email'] ?? '' ) );
		$cnpj  = sanitize_text_field( (string) ( $step1['cnpj'] ?? '' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'papelito_vendor_invalid_email', 'Informe um e-mail valido.', array( 'status' => 422 ) );
		}

		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user instanceof WP_User && (int) $existing_user->ID !== $user_id ) {
			return new WP_Error( 'papelito_vendor_email_exists', 'Ja existe uma conta com este e-mail.', array( 'status' => 409 ) );
		}

		if ( 1 !== preg_match( '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', $cnpj ) ) {
			return new WP_Error( 'papelito_vendor_invalid_cnpj', 'Informe um CNPJ valido.', array( 'status' => 422 ) );
		}

		if ( papelito_admin_vendors_cnpj_exists( $cnpj, $user_id ) ) {
			return new WP_Error( 'papelito_vendor_cnpj_exists', 'Ja existe uma conta com este CNPJ.', array( 'status' => 409 ) );
		}

		$ranges = papelito_admin_vendors_normalize_coverage_ranges( $application['coverageRanges'] ?? null );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}

		$street       = sanitize_text_field( (string) ( $step2['street'] ?? '' ) );
		$number       = sanitize_text_field( (string) ( $step2['number'] ?? '' ) );
		$neighborhood = sanitize_text_field( (string) ( $step2['neighborhood'] ?? '' ) );
		$state        = sanitize_text_field( (string) ( $step2['state'] ?? '' ) );
		$city         = sanitize_text_field( (string) ( $step2['city'] ?? '' ) );
		$store_name   = sanitize_text_field( (string) ( $step1['storeName'] ?? '' ) );
		$first_name   = sanitize_text_field( (string) ( $step1['firstName'] ?? '' ) );
		$last_name    = sanitize_text_field( (string) ( $step1['lastName'] ?? '' ) );
		$display_name = trim( $first_name . ' ' . $last_name );

		if ( '' === $display_name ) {
			$display_name = '' !== $store_name ? $store_name : $email;
		}

		if ( '' === $store_name ) {
			return new WP_Error( 'papelito_vendor_missing_store_name', 'Informe o nome da loja.', array( 'status' => 422 ) );
		}

		if ( '' === $street || '' === $number || '' === $neighborhood || '' === $city || '' === $state ) {
			return new WP_Error( 'papelito_vendor_incomplete_address', 'Informe o endereco comercial completo do vendor.', array( 'status' => 422 ) );
		}

		$user_update = wp_update_user(
			array(
				'ID'           => $user_id,
				'user_email'   => $email,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $display_name,
			)
		);

		if ( is_wp_error( $user_update ) ) {
			return $user_update;
		}

		update_user_meta( $user_id, 'store_name', $store_name );
		update_user_meta( $user_id, 'phone_number', papelito_auth_format_phone( (string) ( $step1['phone'] ?? '' ) ) );
		update_user_meta( $user_id, 'cnpj', $cnpj );
		update_user_meta( $user_id, 'instagram', sanitize_text_field( ltrim( (string) ( $step1['instagram'] ?? '' ), '@' ) ) );
		update_user_meta( $user_id, 'state', $state );
		update_user_meta( $user_id, 'city', $city );
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, $street );
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, $number );
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, sanitize_text_field( (string) ( $step2['complement'] ?? '' ) ) );
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, $neighborhood );
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_DISCOVERY_CHANNEL_META, sanitize_text_field( (string) ( $step1['discoveryChannel'] ?? '' ) ) );
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_HAS_SOLD_PAPELITO_META, sanitize_text_field( (string) ( $step1['hasSoldPapelito'] ?? '' ) ) );

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

	papelito_maybe_autosync_vendor_recipient( $user_id, $pending_fields );

	return papelito_get_vendor_pending_registration_rest_response( $user_id );
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
function papelito_validate_cpf( string $value ): bool {
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
		$errors->add( 'corporationType', 'Informe a natureza juridica.' );
	}
	if ( 1 !== preg_match( '/^\d{4}\-\d{2}\-\d{2}$/', $founding_date ) ) {
		$errors->add( 'foundingDate', 'Informe uma data de fundacao valida.' );
	}
	if ( ! is_numeric( $annual_revenue ) || (float) $annual_revenue <= 0 ) {
		$errors->add( 'annualRevenue', 'Informe o faturamento anual.' );
	}

	$partners = isset( $step3['managingPartners'] ) && is_array( $step3['managingPartners'] ) ? $step3['managingPartners'] : array();
	if ( empty( $partners ) ) {
		$errors->add( 'managingPartners', 'Informe ao menos um socio administrador.' );
	} else {
		$partner = is_array( $partners[0] ) ? $partners[0] : array();

		if ( '' === sanitize_text_field( (string) ( $partner['name'] ?? '' ) ) ) {
			$errors->add( 'partnerName', 'Informe o nome do socio administrador.' );
		}
		if ( ! is_email( sanitize_email( (string) ( $partner['email'] ?? '' ) ) ) ) {
			$errors->add( 'partnerEmail', 'Informe um e-mail valido para o socio.' );
		}
		if ( ! papelito_validate_cpf( (string) ( $partner['document'] ?? '' ) ) ) {
			$errors->add( 'partnerDocument', 'Informe um CPF valido para o socio.' );
		}
		if ( '' === sanitize_text_field( (string) ( $partner['motherName'] ?? '' ) ) ) {
			$errors->add( 'partnerMotherName', 'Informe o nome da mae do socio.' );
		}
		if ( 1 !== preg_match( '/^\d{4}\-\d{2}\-\d{2}$/', sanitize_text_field( (string) ( $partner['birthdate'] ?? '' ) ) ) ) {
			$errors->add( 'partnerBirthdate', 'Informe a data de nascimento do socio.' );
		}

		$monthly_income = str_replace( ',', '.', sanitize_text_field( (string) ( $partner['monthlyIncome'] ?? '' ) ) );
		if ( ! is_numeric( $monthly_income ) || (float) $monthly_income <= 0 ) {
			$errors->add( 'partnerMonthlyIncome', 'Informe a renda mensal do socio.' );
		}
		if ( '' === sanitize_text_field( (string) ( $partner['professionalOccupation'] ?? '' ) ) ) {
			$errors->add( 'partnerOccupation', 'Informe a ocupacao profissional do socio.' );
		}

		$address = isset( $partner['address'] ) && is_array( $partner['address'] ) ? $partner['address'] : array();
		$zip     = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $address['zipCode'] ?? '' ) ) : '';

		if ( '' === $zip ) {
			$errors->add( 'partnerZipCode', 'Informe um CEP valido para o socio.' );
		}
		if ( '' === sanitize_text_field( (string) ( $address['street'] ?? '' ) ) ) {
			$errors->add( 'partnerStreet', 'Informe o logradouro do socio.' );
		}
		if ( '' === sanitize_text_field( (string) ( $address['streetNumber'] ?? '' ) ) ) {
			$errors->add( 'partnerStreetNumber', 'Informe o numero do endereco do socio.' );
		}
		if ( '' === sanitize_text_field( (string) ( $address['neighborhood'] ?? '' ) ) ) {
			$errors->add( 'partnerNeighborhood', 'Informe o bairro do socio.' );
		}
		if ( '' === sanitize_text_field( (string) ( $address['city'] ?? '' ) ) ) {
			$errors->add( 'partnerCity', 'Informe a cidade do socio.' );
		}

		$partner_state = sanitize_text_field( (string) ( $address['state'] ?? '' ) );
		if ( '' === $partner_state || ! array_key_exists( $partner_state, papelito_brazilian_states() ) ) {
			$errors->add( 'partnerState', 'Selecione um estado valido para o socio.' );
		}
	}

	$bank_account = isset( $step3['bankAccount'] ) && is_array( $step3['bankAccount'] ) ? $step3['bankAccount'] : array();
	$holder_type  = sanitize_text_field( (string) ( $bank_account['holderType'] ?? '' ) );

	if ( '' === sanitize_text_field( (string) ( $bank_account['holderName'] ?? '' ) ) ) {
		$errors->add( 'bankHolderName', 'Informe o titular da conta.' );
	}

	$holder_document = (string) ( $bank_account['holderDocument'] ?? '' );
	if ( 'individual' === $holder_type ) {
		if ( ! papelito_validate_cpf( $holder_document ) ) {
			$errors->add( 'bankHolderDocument', 'Informe um CPF valido para o titular.' );
		}
	} elseif ( 1 !== preg_match( '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', $holder_document ) ) {
		$errors->add( 'bankHolderDocument', 'Informe um CNPJ valido para o titular.' );
	}

	if ( 1 !== preg_match( '/^\d{3}$/', sanitize_text_field( (string) ( $bank_account['bankCode'] ?? '' ) ) ) ) {
		$errors->add( 'bankCode', 'Informe um codigo bancario com 3 digitos.' );
	}
	if ( 1 !== preg_match( '/^\d+$/', sanitize_text_field( (string) ( $bank_account['branchNumber'] ?? '' ) ) ) ) {
		$errors->add( 'branchNumber', 'Informe uma agencia valida.' );
	}
	if ( 1 !== preg_match( '/^\d+$/', sanitize_text_field( (string) ( $bank_account['accountNumber'] ?? '' ) ) ) ) {
		$errors->add( 'accountNumber', 'Informe uma conta valida.' );
	}
	if ( 1 !== preg_match( '/^[0-9A-Za-z]+$/', sanitize_text_field( (string) ( $bank_account['accountCheckDigit'] ?? '' ) ) ) ) {
		$errors->add( 'accountCheckDigit', 'Informe o digito da conta.' );
	}

	return $errors->has_errors() ? $errors : null;
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
		$errors->add( 'number', 'Informe o numero.' );
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
 * @return array<string, string>
 */
function papelito_flatten_vendor_application_payload( array $payload ): array {
	$step1 = isset( $payload['step1'] ) && is_array( $payload['step1'] ) ? $payload['step1'] : array();
	$step2 = isset( $payload['step2'] ) && is_array( $payload['step2'] ) ? $payload['step2'] : array();

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
		'minCep'          => (string) ( $step2['minCep'] ?? '' ),
		'maxCep'          => (string) ( $step2['maxCep'] ?? '' ),
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
	$step3 = isset( $payload['step3'] ) && is_array( $payload['step3'] ) ? $payload['step3'] : array();
	$step2 = isset( $payload['step2'] ) && is_array( $payload['step2'] ) ? $payload['step2'] : array();

	$step2_validation = papelito_validate_vendor_address_step2( $step2 );
	if ( $step2_validation instanceof WP_Error ) {
		$step2_validation->add_data( array( 'status' => 422 ) );
		return $step2_validation;
	}

	$step3_validation = papelito_validate_vendor_pagarme_step3( $step3 );
	if ( $step3_validation instanceof WP_Error ) {
		$step3_validation->add_data( array( 'status' => 422 ) );
		return $step3_validation;
	}

	$legacy_input = papelito_flatten_vendor_application_payload( $payload );
	$result       = papelito_submit_seller_application( $user_id, $legacy_input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	papelito_save_seller_application_address_data( $user_id, $step2 );
	papelito_save_vendor_pagarme_recipient_draft( $user_id, $step3 );
	papelito_refresh_vendor_pending_registration_state( $user_id, papelito_sanitize_vendor_pagarme_step3_partial( $step3 ) );

	$response            = papelito_get_vendor_application_rest_response( $user_id );
	$response['message'] = 'Triagem enviada com sucesso. Nosso time vai analisar seus dados.';

	return $response;
}

/**
 * Valida o payload da triagem.
 *
 * @param array $input Payload.
 * @return WP_Error|null
 */
function papelito_validate_seller_application_input( array $input ) {
	$errors = new WP_Error();

	$required_fields = array(
		'storeName'       => 'Informe o nome da loja.',
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

	$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
	if ( '' === $email || ! is_email( $email ) ) {
		$errors->add( 'email', 'Informe um e-mail válido.' );
	}

	$phone_digits = papelito_auth_normalize_phone( isset( $input['phoneNumber'] ) ? (string) $input['phoneNumber'] : '' );
	if ( ! in_array( strlen( $phone_digits ), array( 10, 11 ), true ) ) {
		$errors->add( 'phoneNumber', 'Informe um telefone válido.' );
	}

	$cnpj = isset( $input['cnpj'] ) ? (string) $input['cnpj'] : '';
	if ( ! preg_match( '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', $cnpj ) ) {
		$errors->add( 'cnpj', 'Informe um CNPJ válido.' );
	}

	$state = isset( $input['state'] ) ? (string) $input['state'] : '';
	if ( '' !== $state && ! array_key_exists( $state, papelito_brazilian_states() ) ) {
		$errors->add( 'state', 'Selecione um estado válido.' );
	}

	$has_sold = isset( $input['hasSoldPapelito'] ) ? (string) $input['hasSoldPapelito'] : '';
	if ( '' !== $has_sold && ! in_array( $has_sold, array( 'sim', 'nao' ), true ) ) {
		$errors->add( 'hasSoldPapelito', 'Escolha uma opção válida.' );
	}

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

	if ( '' !== $normalized_ceps['minCep'] && '' !== $normalized_ceps['maxCep'] && (int) $normalized_ceps['minCep'] > (int) $normalized_ceps['maxCep'] ) {
		$errors->add( 'maxCep', 'O CEP final precisa ser maior ou igual ao CEP inicial.' );
	}

	return $errors->has_errors() ? $errors : null;
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
		sprintf( 'Responsavel: %s %s', $application['firstName'], $application['lastName'] ),
		sprintf( 'E-mail: %s', $application['email'] ),
		sprintf( 'Telefone: %s', $application['phoneNumber'] ),
		sprintf( 'CNPJ: %s', $application['cnpj'] ),
		sprintf( 'Cidade/Estado: %s - %s', $application['city'], $application['state'] ),
		sprintf( 'CEP de operacao: %s', $application['cep'] ?? '' ),
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
	$status = papelito_get_seller_application_status( $user_id );

	if ( 'pending' === $status ) {
		return new WP_Error(
			'papelito_application_pending',
			'Sua triagem ja foi enviada e esta em analise.',
			array( 'status' => 409 )
		);
	}

	if ( 'approved' === $status ) {
		return new WP_Error(
			'papelito_application_approved',
			'Sua conta ja foi aprovada para o programa de revendedores.',
			array( 'status' => 409 )
		);
	}

	$validation = papelito_validate_seller_application_input( $input );
	if ( $validation instanceof WP_Error ) {
		return $validation;
	}

	$current_user = get_userdata( $user_id );
	if ( ! $current_user instanceof WP_User ) {
		return new WP_Error( 'papelito_user_not_found', 'Usuario nao encontrado.', array( 'status' => 404 ) );
	}

	$email = sanitize_email( (string) $input['email'] );
	if ( $email !== $current_user->user_email && email_exists( $email ) ) {
		return new WP_Error( 'papelito_email_exists', 'Ja existe uma conta com este e-mail.', array( 'status' => 409 ) );
	}

	$result = wp_update_user(
		array(
			'ID'         => $user_id,
			'user_email' => $email,
			'first_name' => sanitize_text_field( (string) $input['firstName'] ),
			'last_name'  => sanitize_text_field( (string) $input['lastName'] ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	update_user_meta( $user_id, 'store_name', sanitize_text_field( (string) $input['storeName'] ) );
	update_user_meta( $user_id, 'phone_number', papelito_auth_format_phone( (string) $input['phoneNumber'] ) );
	update_user_meta( $user_id, 'cnpj', sanitize_text_field( (string) $input['cnpj'] ) );
	update_user_meta( $user_id, 'instagram', sanitize_text_field( ltrim( (string) $input['instagram'], '@' ) ) );
	update_user_meta( $user_id, 'state', sanitize_text_field( (string) $input['state'] ) );
	update_user_meta( $user_id, 'city', sanitize_text_field( (string) $input['city'] ) );

	$cep_base = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['cep'] ?? '' ) ) : '';
	$min_cep  = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['minCep'] ?? '' ) ) : '';
	$max_cep  = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['maxCep'] ?? '' ) ) : '';

	update_user_meta( $user_id, 'cep', $cep_base );
	delete_user_meta( $user_id, 'min_cep' );
	delete_user_meta( $user_id, 'max_cep' );
	add_user_meta( $user_id, 'min_cep', $min_cep, false );
	add_user_meta( $user_id, 'max_cep', $max_cep, false );

	if ( function_exists( 'papelito_apply_vendor_geo' ) ) {
		papelito_apply_vendor_geo( $user_id, $cep_base );
	}

	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_DISCOVERY_CHANNEL_META, sanitize_text_field( (string) ( $input['discoveryChannel'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_HAS_SOLD_PAPELITO_META, sanitize_text_field( (string) $input['hasSoldPapelito'] ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META, 'pending' );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_SUBMITTED_AT_META, papelito_current_utc_mysql() );

	papelito_reset_vendor_application_review( $user_id );

	$application = papelito_get_seller_application_data( $user_id );

	papelito_notify_seller_application( $application );
	papelito_notify_seller_application_received( $application );
	do_action( 'papelito_vendor_application_submitted', $user_id );

	return array(
		'success'     => true,
		'message'     => 'Triagem enviada com sucesso. Nosso time vai analisar seus dados.',
		'application' => $application,
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/vendor/application',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static function (): bool {
						return is_user_logged_in();
					},
					'callback'            => static function () {
						$user_id = get_current_user_id();

						if ( $user_id <= 0 ) {
							return new WP_Error( 'papelito_not_authenticated', 'Usuario nao autenticado.', array( 'status' => 401 ) );
						}

						return new WP_REST_Response( papelito_get_vendor_application_rest_response( $user_id ), 200 );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => static function (): bool {
						return is_user_logged_in();
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$user_id = get_current_user_id();

						if ( $user_id <= 0 ) {
							return new WP_Error( 'papelito_not_authenticated', 'Usuario nao autenticado.', array( 'status' => 401 ) );
						}

						$payload = $request->get_json_params();
						if ( ! is_array( $payload ) ) {
							return new WP_Error( 'papelito_invalid_payload', 'Payload invalido.', array( 'status' => 400 ) );
						}

						$result = papelito_submit_vendor_application_rest( $user_id, $payload );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( $result, 200 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/recipient-draft',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static function (): bool {
						return is_user_logged_in();
					},
					'callback'            => static function () {
						$user_id = get_current_user_id();

						if ( $user_id <= 0 ) {
							return new WP_Error( 'papelito_not_authenticated', 'Usuario nao autenticado.', array( 'status' => 401 ) );
						}

						return new WP_REST_Response(
							array(
								'draft' => papelito_get_vendor_pagarme_recipient_draft( $user_id ),
							),
							200
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => static function (): bool {
						return is_user_logged_in();
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$user_id = get_current_user_id();

						if ( $user_id <= 0 ) {
							return new WP_Error( 'papelito_not_authenticated', 'Usuario nao autenticado.', array( 'status' => 401 ) );
						}

						$payload = $request->get_json_params();
						if ( ! is_array( $payload ) ) {
							return new WP_Error( 'papelito_invalid_payload', 'Payload invalido.', array( 'status' => 400 ) );
						}

						$result = papelito_update_vendor_pagarme_recipient_draft_rest( $user_id, $payload );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return new WP_REST_Response( $result, 200 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor/registration-pending',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static function () {
						$check = function_exists( 'papelito_vendor_dashboard_require_seller' )
							? papelito_vendor_dashboard_require_seller()
							: false;
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function () {
						$user_id = get_current_user_id();

						if ( $user_id <= 0 ) {
							return new WP_Error( 'papelito_not_authenticated', 'Usuario nao autenticado.', array( 'status' => 401 ) );
						}

						return new WP_REST_Response( papelito_get_vendor_pending_registration_rest_response( $user_id ), 200 );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => static function () {
						$check = function_exists( 'papelito_vendor_dashboard_require_seller' )
							? papelito_vendor_dashboard_require_seller()
							: false;
						return is_wp_error( $check ) ? $check : true;
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						$user_id = get_current_user_id();

						if ( $user_id <= 0 ) {
							return new WP_Error( 'papelito_not_authenticated', 'Usuario nao autenticado.', array( 'status' => 401 ) );
						}

						$payload = $request->get_json_params();
						if ( ! is_array( $payload ) ) {
							return new WP_Error( 'papelito_invalid_payload', 'Payload invalido.', array( 'status' => 400 ) );
						}

						$result = papelito_update_vendor_pending_registration_rest( $user_id, $payload );

						return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
					},
				),
			)
		);
	}
);

add_action(
	'graphql_register_types',
	static function (): void {
		if ( ! function_exists( 'register_graphql_object_type' ) || ! function_exists( 'register_graphql_field' ) || ! function_exists( 'register_graphql_mutation' ) ) {
			return;
		}

		register_graphql_object_type(
			'PapelitoSellerApplication',
			array(
				'description' => 'Resumo da triagem do programa de revendedores.',
				'fields'      => array(
					'status'           => array( 'type' => 'String' ),
					'submittedAt'      => array( 'type' => 'String' ),
					'storeName'        => array( 'type' => 'String' ),
					'firstName'        => array( 'type' => 'String' ),
					'lastName'         => array( 'type' => 'String' ),
					'email'            => array( 'type' => 'String' ),
					'phoneNumber'      => array( 'type' => 'String' ),
					'cnpj'             => array( 'type' => 'String' ),
					'instagram'        => array( 'type' => 'String' ),
					'state'            => array( 'type' => 'String' ),
					'city'             => array( 'type' => 'String' ),
					'cep'              => array( 'type' => 'String' ),
					'minCep'           => array( 'type' => 'String' ),
					'maxCep'           => array( 'type' => 'String' ),
					'discoveryChannel' => array( 'type' => 'String' ),
					'hasSoldPapelito'  => array( 'type' => 'String' ),
				),
			)
		);

		register_graphql_field(
			'Customer',
			'sellerApplication',
			array(
				'type'        => 'PapelitoSellerApplication',
				'description' => 'Status atual da triagem do cliente para o programa de revendedores.',
				'resolve'     => static function () {
					$user_id = get_current_user_id();
					return $user_id > 0 ? papelito_get_seller_application_data( $user_id ) : null;
				},
			)
		);

		register_graphql_mutation(
			'submitSellerApplication',
			array(
				'inputFields'         => array(
					'storeName'        => array( 'type' => array( 'non_null' => 'String' ) ),
					'firstName'        => array( 'type' => array( 'non_null' => 'String' ) ),
					'lastName'         => array( 'type' => array( 'non_null' => 'String' ) ),
					'email'            => array( 'type' => array( 'non_null' => 'String' ) ),
					'phoneNumber'      => array( 'type' => array( 'non_null' => 'String' ) ),
					'cnpj'             => array( 'type' => array( 'non_null' => 'String' ) ),
					'instagram'        => array( 'type' => array( 'non_null' => 'String' ) ),
					'city'             => array( 'type' => array( 'non_null' => 'String' ) ),
					'state'            => array( 'type' => array( 'non_null' => 'String' ) ),
					'cep'              => array( 'type' => array( 'non_null' => 'String' ) ),
					'minCep'           => array( 'type' => array( 'non_null' => 'String' ) ),
					'maxCep'           => array( 'type' => array( 'non_null' => 'String' ) ),
					'discoveryChannel' => array( 'type' => 'String' ),
					'hasSoldPapelito'  => array( 'type' => array( 'non_null' => 'String' ) ),
				),
				'outputFields'        => array(
					'success'     => array( 'type' => 'Boolean' ),
					'message'     => array( 'type' => 'String' ),
					'application' => array( 'type' => 'PapelitoSellerApplication' ),
				),
				'mutateAndGetPayload' => static function ( $input ) {
					$user_id = get_current_user_id();

					if ( $user_id <= 0 ) {
						throw papelito_graphql_user_error( 'Usuario nao autenticado.' );
					}

					$result = papelito_submit_seller_application( $user_id, is_array( $input ) ? $input : array() );

					if ( is_wp_error( $result ) ) {
						throw papelito_graphql_user_error( $result->get_error_message() );
					}

					return $result;
				},
			)
		);
	}
);

/**
 * Retorna o detalhe completo da triagem para o painel admin.
 *
 * @param int $user_id Usuario.
 * @return array<string, mixed>|WP_Error
 */
function papelito_get_vendor_application_detail( int $user_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	if ( 'none' === papelito_get_seller_application_status( $user_id ) ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
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
 * Envia e-mail ao vendor com a decisao da triagem.
 *
 * @param WP_User $user Usuario.
 * @param string  $decision approved|rejected.
 * @param string  $reason Motivo opcional.
 * @return void
 */
function papelito_notify_vendor_decision( WP_User $user, string $decision, string $reason = '' ): void {
	$email = sanitize_email( (string) $user->user_email );
	if ( '' === $email ) {
		return;
	}

	$store    = (string) get_user_meta( $user->ID, 'store_name', true );
	$greeting = trim( (string) $user->first_name );
	if ( '' === $greeting ) {
		$greeting = '' !== $store ? $store : (string) $user->display_name;
	}

	if ( 'approved' === $decision ) {
		$subject    = 'Sua solicitacao foi aprovada - Papelito';
		$body_lines = array(
			sprintf( 'Ola %s,', $greeting ),
			'',
			'Boas noticias! Sua solicitacao para ser revendedor Papelito foi aprovada.',
			'Voce ja pode acessar a area do vendedor com suas credenciais atuais.',
			'',
			'Acesse: https://papelitobrasil.com/entrar',
			'',
			'Bem-vindo ao programa PDV Perfeito.',
			'Time Papelito',
		);
	} else {
		$subject    = 'Atualizacao da sua solicitacao - Papelito';
		$body_lines = array(
			sprintf( 'Ola %s,', $greeting ),
			'',
			'Recebemos e analisamos sua solicitacao para o programa de revendedores Papelito.',
			'No momento, ela nao foi aprovada.',
		);

		if ( '' !== $reason ) {
			$body_lines[] = '';
			$body_lines[] = 'Motivo informado pelo nosso time:';
			$body_lines[] = $reason;
		}

		$body_lines[] = '';
		$body_lines[] = 'Voce pode entrar em contato com marketing@papelitobrasil.com para mais informacoes.';
		$body_lines[] = '';
		$body_lines[] = 'Time Papelito';
	}

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	wp_mail( $email, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Valida o motivo de rejeicao.
 *
 * @param string $reason Texto livre.
 * @return string|WP_Error
 */
function papelito_validate_vendor_rejection_reason( string $reason ) {
	$clean = sanitize_textarea_field( $reason );
	$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) : strlen( $clean );

	if ( $len < 10 || $len > 500 ) {
		return new WP_Error(
			'papelito_invalid_rejection_reason',
			'Informe um motivo entre 10 e 500 caracteres.',
			array( 'status' => 422 )
		);
	}

	return $clean;
}

/**
 * Aprova a triagem do vendor.
 *
 * @param int $user_id Usuario.
 * @param int $reviewer_id Admin.
 * @return array<string, mixed>|WP_Error
 */
function papelito_approve_seller_application( int $user_id, int $reviewer_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$status = papelito_get_seller_application_status( $user_id );
	if ( 'pending' !== $status ) {
		return new WP_Error(
			'papelito_invalid_state',
			sprintf( 'Triagem nao esta pendente (status atual: %s).', $status ),
			array( 'status' => 409 )
		);
	}

	$user->set_role( 'seller' );

	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META, 'approved' );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META, papelito_current_utc_mysql() );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META, $reviewer_id );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META );

	papelito_notify_vendor_decision( $user, 'approved' );
	do_action( 'papelito_vendor_approved', $user_id );

	return papelito_get_vendor_application_detail( $user_id );
}

/**
 * Rejeita a triagem do vendor.
 *
 * @param int    $user_id Usuario.
 * @param int    $reviewer_id Admin.
 * @param string $reason Motivo.
 * @return array<string, mixed>|WP_Error
 */
function papelito_reject_seller_application( int $user_id, int $reviewer_id, string $reason ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$status = papelito_get_seller_application_status( $user_id );
	if ( 'pending' !== $status ) {
		return new WP_Error(
			'papelito_invalid_state',
			sprintf( 'Triagem nao esta pendente (status atual: %s).', $status ),
			array( 'status' => 409 )
		);
	}

	$clean_reason = papelito_validate_vendor_rejection_reason( $reason );
	if ( is_wp_error( $clean_reason ) ) {
		return $clean_reason;
	}

	if ( in_array( 'seller', (array) $user->roles, true ) ) {
		$user->set_role( 'customer' );
	}

	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META, 'rejected' );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META, papelito_current_utc_mysql() );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META, $reviewer_id );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META, $clean_reason );

	papelito_notify_vendor_decision( $user, 'rejected', $clean_reason );
	do_action( 'papelito_vendor_rejected', $user_id, $clean_reason );

	return papelito_get_vendor_application_detail( $user_id );
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
		'status'  => papelito_admin_reports_normalize_enum(
			sanitize_text_field( (string) $request->get_param( 'status' ) ),
			array( 'all', 'pending', PAPELITO_VENDOR_APPLICATION_INCOMPLETE_STATUS, 'approved', 'rejected' ),
			'pending'
		),
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
		LEFT JOIN {$usermeta_table} application_status ON application_status.user_id = u.ID AND application_status.meta_key = '" . PAPELITO_VENDOR_APPLICATION_STATUS_META . "'
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

	$conditions = array(
		'(application_status.meta_value IN (%s, %s, %s, %s))',
	);

	array_push( $args, 'pending', PAPELITO_VENDOR_APPLICATION_INCOMPLETE_STATUS, 'approved', 'rejected' );

	if ( ! empty( $filters['search'] ) && is_string( $filters['search'] ) ) {
		$term         = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		$conditions[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR store_name.meta_value LIKE %s OR first_name.meta_value LIKE %s OR last_name.meta_value LIKE %s OR cnpj.meta_value LIKE %s)';
		array_push( $args, $term, $term, $term, $term, $term, $term );
	}

	if ( $include_status && 'all' !== $filters['status'] && is_string( $filters['status'] ) ) {
		$conditions[] = 'application_status.meta_value = %s';
		$args[]       = $filters['status'];
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
			COALESCE(application_status.meta_value, '') AS application_status,
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
		$display_name = isset( $raw_row['display_name'] ) ? trim( (string) $raw_row['display_name'] ) : '';
		$first_name   = isset( $raw_row['first_name'] ) ? trim( (string) $raw_row['first_name'] ) : '';
		$last_name    = isset( $raw_row['last_name'] ) ? trim( (string) $raw_row['last_name'] ) : '';
		$name         = $display_name;

		if ( '' === $name ) {
			$name = trim( $first_name . ' ' . $last_name );
		}

		if ( '' === $name ) {
			$name = isset( $raw_row['user_email'] ) ? (string) $raw_row['user_email'] : 'Usuario sem nome';
		}

		$role = function_exists( 'papelito_admin_reports_detect_role' )
			? papelito_admin_reports_detect_role( isset( $raw_row['capabilities'] ) ? (string) $raw_row['capabilities'] : '' )
			: 'other';

		$rows[] = array(
			'id'                     => isset( $raw_row['id'] ) ? (int) $raw_row['id'] : 0,
			'name'                   => $name,
			'email'                  => isset( $raw_row['user_email'] ) ? (string) $raw_row['user_email'] : '',
			'role'                   => $role,
			'roleLabel'              => function_exists( 'papelito_admin_reports_role_label' ) ? papelito_admin_reports_role_label( $role ) : ucfirst( $role ),
			'applicationStatus'      => isset( $raw_row['application_status'] ) && '' !== $raw_row['application_status'] ? (string) $raw_row['application_status'] : 'none',
			'applicationStatusLabel' => function_exists( 'papelito_admin_reports_application_status_label' ) ? papelito_admin_reports_application_status_label( isset( $raw_row['application_status'] ) ? (string) $raw_row['application_status'] : '' ) : '',
			'storeName'              => isset( $raw_row['store_name'] ) ? (string) $raw_row['store_name'] : '',
			'phoneNumber'            => isset( $raw_row['phone_number'] ) ? (string) $raw_row['phone_number'] : '',
			'cnpj'                   => isset( $raw_row['cnpj'] ) ? (string) $raw_row['cnpj'] : '',
			'state'                  => isset( $raw_row['state'] ) ? (string) $raw_row['state'] : '',
			'city'                   => isset( $raw_row['city'] ) ? (string) $raw_row['city'] : '',
			'registeredAt'           => isset( $raw_row['user_registered'] ) ? (string) $raw_row['user_registered'] : '',
			'coverageSummary'        => 'Sem cobertura',
		);
	}

	return function_exists( 'papelito_admin_reports_attach_coverage_summary' )
		? papelito_admin_reports_attach_coverage_summary( $rows )
		: $rows;
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
			SUM(CASE WHEN application_status.meta_value = 'pending' THEN 1 ELSE 0 END) AS pending_applications,
			SUM(CASE WHEN application_status.meta_value = 'incomplete' THEN 1 ELSE 0 END) AS incomplete_applications,
			SUM(CASE WHEN application_status.meta_value = 'approved' THEN 1 ELSE 0 END) AS approved_sellers,
			SUM(CASE WHEN {$coverage_exists} THEN 1 ELSE 0 END) AS users_with_coverage
		" . $base_sql . $search_only_where,
		$args
	);

	$summary = $wpdb->get_row( $sql, ARRAY_A );

	return array(
		'filteredUsers'       => $filtered_users,
		'pendingApplications' => isset( $summary['pending_applications'] ) ? (int) $summary['pending_applications'] : 0,
		'incompleteApplications' => isset( $summary['incomplete_applications'] ) ? (int) $summary['incomplete_applications'] : 0,
		'approvedSellers'     => isset( $summary['approved_sellers'] ) ? (int) $summary['approved_sellers'] : 0,
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
			'Informe os dados bancarios.',
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
		if ( ! papelito_validate_cpf( $normalized['holderDocument'] ) ) {
			$errors->add( 'bankHolderDocument', 'Informe um CPF valido para o titular.' );
		}
	} elseif ( 'company' === $holder_type && 1 !== preg_match( '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', $normalized['holderDocument'] ) ) {
		$errors->add( 'bankHolderDocument', 'Informe um CNPJ valido para o titular.' );
	}

	if ( 1 !== preg_match( '/^\d{3}$/', $normalized['bankCode'] ) ) {
		$errors->add( 'bankCode', 'Informe um codigo bancario com 3 digitos.' );
	}

	if ( 1 !== preg_match( '/^\d+$/', $normalized['branchNumber'] ) ) {
		$errors->add( 'branchNumber', 'Informe uma agencia valida.' );
	}

	if ( 1 !== preg_match( '/^\d+$/', $normalized['accountNumber'] ) ) {
		$errors->add( 'accountNumber', 'Informe uma conta valida.' );
	}

	if ( 1 !== preg_match( '/^[0-9A-Za-z]+$/', $normalized['accountCheckDigit'] ) ) {
		$errors->add( 'accountCheckDigit', 'Informe o digito da conta.' );
	}

	if ( ! in_array( $type, array( 'checking', 'savings' ), true ) ) {
		$errors->add( 'bankAccountType', 'Informe o tipo da conta bancaria.' );
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
	$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$cnpj  = sanitize_text_field( (string) ( $input['cnpj'] ?? '' ) );

	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'papelito_admin_vendor_invalid_email', 'Informe um e-mail valido.', array( 'status' => 422 ) );
	}

	$existing_user = get_user_by( 'email', $email );
	if ( $existing_user instanceof WP_User && ! papelito_admin_vendors_is_convertible_customer( $existing_user ) ) {
		return new WP_Error( 'papelito_admin_vendor_email_exists', 'Ja existe uma conta com este e-mail.', array( 'status' => 409 ) );
	}

	if ( 1 !== preg_match( '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', $cnpj ) ) {
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

	$street       = sanitize_text_field( (string) ( $input['street'] ?? '' ) );
	$number       = sanitize_text_field( (string) ( $input['number'] ?? '' ) );
	$neighborhood = sanitize_text_field( (string) ( $input['neighborhood'] ?? '' ) );
	$state        = sanitize_text_field( (string) ( $input['state'] ?? '' ) );
	$city         = sanitize_text_field( (string) ( $input['city'] ?? '' ) );

	$first_name   = sanitize_text_field( (string) ( $input['firstName'] ?? '' ) );
	$last_name    = sanitize_text_field( (string) ( $input['lastName'] ?? '' ) );
	$store_name   = sanitize_text_field( (string) ( $input['storeName'] ?? '' ) );
	$display_name = trim( $first_name . ' ' . $last_name );

	if ( '' === $display_name ) {
		$display_name = '' !== $store_name ? $store_name : $email;
	}

	if ( '' === $store_name ) {
		return new WP_Error( 'papelito_admin_vendor_missing_store_name', 'Informe o nome da loja.', array( 'status' => 422 ) );
	}

	if ( '' === $street || '' === $number || '' === $neighborhood || '' === $city || '' === $state ) {
		return new WP_Error(
			'papelito_admin_vendor_incomplete_address',
			'Informe o endereco comercial completo do vendor.',
			array( 'status' => 422 )
		);
	}

	$raw_pagarme_draft = isset( $input['pagarmeDraft'] ) && is_array( $input['pagarmeDraft'] ) ? $input['pagarmeDraft'] : array();
	$pagarme_draft     = papelito_sanitize_vendor_pagarme_step3_partial(
		$raw_pagarme_draft,
		array(
			'storeName'    => $store_name,
			'email'        => $email,
			'cnpj'         => $cnpj,
			'cep'          => (string) ( $input['cep'] ?? '' ),
			'street'       => $street,
			'number'       => $number,
			'complement'   => (string) ( $input['complement'] ?? '' ),
			'neighborhood' => $neighborhood,
			'city'         => $city,
			'state'        => $state,
			'firstName'    => $first_name,
			'lastName'     => $last_name,
		)
	);
	$pagarme_draft['companyName'] = sanitize_text_field( (string) ( $raw_pagarme_draft['companyName'] ?? '' ) );
	$pagarme_draft['tradingName'] = sanitize_text_field( (string) ( $raw_pagarme_draft['tradingName'] ?? '' ) );

	$is_new_user = ! $existing_user instanceof WP_User;
	$user_id     = $is_new_user
		? wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $display_name,
				'role'         => 'seller',
			)
		)
		: wp_update_user(
			array(
				'ID'           => $existing_user_id,
				'user_email'   => $email,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $display_name,
			)
		);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$user_id = (int) $user_id;
	$user    = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$user->set_role( 'seller' );

	update_user_meta( $user_id, 'store_name', $store_name );
	update_user_meta( $user_id, 'phone_number', papelito_auth_format_phone( (string) ( $input['phoneNumber'] ?? '' ) ) );
	update_user_meta( $user_id, 'cnpj', $cnpj );
	update_user_meta( $user_id, 'instagram', sanitize_text_field( ltrim( (string) ( $input['instagram'] ?? '' ), '@' ) ) );
	update_user_meta( $user_id, 'state', $state );
	update_user_meta( $user_id, 'city', $city );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, $street );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, $number );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, sanitize_text_field( (string) ( $input['complement'] ?? '' ) ) );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, $neighborhood );

	$cep_base = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['cep'] ?? '' ) ) : '';
	update_user_meta( $user_id, 'cep', $cep_base );

	delete_user_meta( $user_id, 'min_cep' );
	delete_user_meta( $user_id, 'max_cep' );
	foreach ( $ranges as $range ) {
		add_user_meta( $user_id, 'min_cep', $range['minCep'], false );
		add_user_meta( $user_id, 'max_cep', $range['maxCep'], false );
	}

	$discovery_channel = sanitize_text_field( (string) ( $input['discoveryChannel'] ?? '' ) );
	if ( '' === $discovery_channel ) {
		$discovery_channel = PAPELITO_ADMIN_VENDOR_DISCOVERY_CHANNEL_DEFAULT;
	}

	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_DISCOVERY_CHANNEL_META, $discovery_channel );
	$has_sold = sanitize_text_field( (string) ( $input['hasSoldPapelito'] ?? '' ) );
	if ( '' === $has_sold ) {
		$has_sold = PAPELITO_ADMIN_VENDOR_HAS_SOLD_PAPELITO_DEFAULT;
	}
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_HAS_SOLD_PAPELITO_META, $has_sold );
	update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_SUBMITTED_AT_META, papelito_current_utc_mysql() );
	delete_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REJECTION_REASON_META );

	papelito_save_vendor_pagarme_recipient_draft(
		$user_id,
		$pagarme_draft
	);
	$pending_fields = papelito_refresh_vendor_pending_registration_state( $user_id, $pagarme_draft );
	papelito_sync_vendor_pending_registration_status( $user_id, $pending_fields );

	if ( empty( $pending_fields ) ) {
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META, 'approved' );
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_AT_META, papelito_current_utc_mysql() );
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_REVIEWED_BY_META, $reviewer_id );
		update_user_meta( $user_id, 'papelito_profile_complete', '1' );
	} else {
		update_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STATUS_META, PAPELITO_VENDOR_APPLICATION_INCOMPLETE_STATUS );
		update_user_meta( $user_id, 'papelito_profile_complete', '0' );
		papelito_reset_vendor_application_review( $user_id );
	}

	if ( function_exists( 'papelito_apply_vendor_geo' ) && '' !== $cep_base ) {
		papelito_apply_vendor_geo( $user_id, $cep_base );
	}

	if ( $is_new_user ) {
		wp_new_user_notification( $user_id, null, 'user' );
	}
	papelito_maybe_autosync_vendor_recipient( $user_id, $pending_fields );
	if ( ! empty( $pending_fields ) ) {
		do_action( 'papelito_vendor_pending_registration_created', $user_id, $pending_fields );
	}

	return papelito_get_vendor_application_detail( $user_id );
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
		return new WP_Error( 'papelito_invalid_payload', 'Payload invalido.', array( 'status' => 400 ) );
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

/**
 * POST /admin/vendors/{id}/approve — aprova a triagem pendente.
 */
function papelito_admin_vendors_handle_approve( WP_REST_Request $request ) {
	$result = papelito_approve_seller_application( (int) $request['id'], get_current_user_id() );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * POST /admin/vendors/{id}/reject — rejeita com motivo obrigatorio.
 */
function papelito_admin_vendors_handle_reject( WP_REST_Request $request ) {
	$body   = $request->get_json_params();
	$reason = is_array( $body ) && isset( $body['reason'] ) ? (string) $body['reason'] : '';
	$result = papelito_reject_seller_application( (int) $request['id'], get_current_user_id(), $reason );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
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

		register_rest_route(
			'papelito/v1/admin',
			'/vendors/(?P<id>\d+)/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_admin_vendors_require_admin',
				'args'                => array(
					'id' => array(
						'validate_callback' => 'papelito_admin_vendors_validate_id',
					),
				),
				'callback'            => 'papelito_admin_vendors_handle_approve',
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/vendors/(?P<id>\d+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_admin_vendors_require_admin',
				'args'                => array(
					'id' => array(
						'validate_callback' => 'papelito_admin_vendors_validate_id',
					),
				),
				'callback'            => 'papelito_admin_vendors_handle_reject',
			)
		);
	}
);
