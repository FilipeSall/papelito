<?php

defined( 'ABSPATH' ) || exit;

function papelito_company_profile_get( int $user_id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . papelito_customer_profiles_table_name() . ' WHERE user_id = %d', $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	return is_array( $row ) ? $row : null;
}

function papelito_company_profile_upsert( int $user_id, string $cpf, string $birth_date ): true|WP_Error {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birth_date ) || false === strtotime( $birth_date ) ) {
		return new WP_Error( 'papelito_b2b_invalid_birth_date', 'Data de nascimento inválida.', array( 'status' => 422 ) );
	}
	$result = papelito_customer_profile_upsert( $user_id, $cpf, array( 'identity_status' => 'verified', 'identity_method' => 'email_verified', 'identity_checked_at' => current_time( 'mysql', true ) ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$encrypted = papelito_pii_encrypt( $birth_date );
	if ( is_wp_error( $encrypted ) ) {
		return $encrypted;
	}
	global $wpdb;
	$updated = $wpdb->update( papelito_customer_profiles_table_name(), array( 'birth_date_ciphertext' => $encrypted, 'updated_at' => current_time( 'mysql', true ) ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return false === $updated ? new WP_Error( 'papelito_b2b_profile_persist_failed', 'Falha ao salvar perfil.', array( 'status' => 500 ) ) : true;
}

function papelito_company_normalize_name( string $value ): string {
	$value = remove_accents( strtoupper( trim( $value ) ) );
	return preg_replace( '/\s+/', ' ', $value ) ?? '';
}

function papelito_company_age_band( string $birth_date ): int {
	$birth = DateTimeImmutable::createFromFormat( '!Y-m-d', $birth_date, new DateTimeZone( 'UTC' ) );
	if ( ! $birth ) { return 0; }
	$age = $birth->diff( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->y;
	if ( $age <= 12 ) { return 1; }
	if ( $age <= 20 ) { return 2; }
	if ( $age <= 30 ) { return 3; }
	if ( $age <= 40 ) { return 4; }
	if ( $age <= 50 ) { return 5; }
	if ( $age <= 60 ) { return 6; }
	if ( $age <= 70 ) { return 7; }
	if ( $age <= 80 ) { return 8; }
	return 9;
}

function papelito_company_owner_evidence( WP_User $user, string $cpf, string $birth_date, array $lookup ): array {
	$evidence = array( 'qsa_available' => false, 'cpf_mask_match' => 'unknown', 'name_match' => 'unknown', 'age_band_match' => 'unknown', 'eligible_qualification' => 'unknown', 'provider' => (string) ( $lookup['source'] ?? '' ), 'checked_at' => (string) ( $lookup['checked_at'] ?? gmdate( 'c' ) ) );
	$full_name = papelito_company_normalize_name( trim( (string) get_user_meta( $user->ID, 'first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'last_name', true ) ) );
	$eligible = array( 'SOCIO', 'SOCIO ADMINISTRADOR', 'TITULAR', 'ADMINISTRADOR', 'PRESIDENTE', 'DIRETOR', 'REPRESENTANTE LEGAL' );
	foreach ( (array) ( $lookup['qsa'] ?? array() ) as $entry ) {
		if ( ! is_array( $entry ) ) { continue; }
		$evidence['qsa_available'] = true;
		$name = papelito_company_normalize_name( (string) ( $entry['nome_socio'] ?? $entry['nome'] ?? '' ) );
		$mask = preg_replace( '/\D+/', '', (string) ( $entry['cnpj_cpf_do_socio'] ?? $entry['cpf_cnpj_socio'] ?? '' ) ) ?? '';
		$qualification = papelito_company_normalize_name( (string) ( $entry['qualificacao_socio']['descricao'] ?? $entry['qualificacao'] ?? '' ) );
		if ( '' !== $name && $name === $full_name ) { $evidence['name_match'] = true; }
		if ( '' !== $mask && substr( $cpf, -4 ) === substr( $mask, -4 ) ) { $evidence['cpf_mask_match'] = true; }
		if ( isset( $entry['faixa_etaria'] ) || isset( $entry['codigo_faixa_etaria'] ) ) { $evidence['age_band_match'] = (string) papelito_company_age_band( $birth_date ) === (string) ( $entry['codigo_faixa_etaria'] ?? $entry['faixa_etaria'] ); }
		if ( in_array( $qualification, $eligible, true ) ) { $evidence['eligible_qualification'] = true; }
	}
	$evidence['hash'] = hash( 'sha256', wp_json_encode( $evidence ) ?: '' );
	return $evidence;
}

function papelito_company_audit( int $company_id, ?int $actor_user_id, string $action, array $payload = array() ): void {
	global $wpdb;
	$tables = papelito_company_table_names();
	$allowed = array( 'target_user_id', 'requester_user_id', 'invitation_id', 'attempt', 'role', 'invited_role', 'cpf_locked', 'has_expiration', 'registry_status', 'provider', 'source' );
	$safe    = array();
	foreach ( $allowed as $key ) {
		if ( array_key_exists( $key, $payload ) && is_scalar( $payload[ $key ] ) ) {
			$safe[ $key ] = $payload[ $key ];
		}
	}
	$wpdb->insert( $tables['audit'], array( 'company_id' => $company_id, 'actor_user_id' => $actor_user_id, 'action' => sanitize_key( $action ), 'payload_json' => wp_json_encode( $safe ), 'created_at' => current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function papelito_company_context_view( array $company, ?array $membership ): array {
	$role       = (string) ( $membership['member_role'] ?? '' );
	$is_manager = in_array( $role, array( 'owner', 'admin' ), true );
	$verified   = ! empty( $company['billing_email_verified_at'] );
	$status     = ! empty( $company['pending_billing_email'] ) ? 'pending' : ( $verified ? 'verified' : 'unverified' );
	$address    = array_filter( array( 'cep' => $company['fiscal_cep'], 'state' => $company['fiscal_state'], 'city' => $company['fiscal_city'], 'neighborhood' => $company['fiscal_neighborhood'], 'street' => $company['fiscal_street'], 'number' => $company['fiscal_number'], 'complement' => $company['fiscal_complement'] ), static fn( $value ) => null !== $value && '' !== $value );

	return array(
		'legalName'          => (string) $company['legal_name'],
		'tradeName'          => $company['trade_name'] ?? null,
		'cnpj'               => (string) $company['cnpj'],
		'registryStatus'     => (string) $company['registry_status'],
		'ownershipStatus'    => (string) $company['ownership_status'],
		'status'             => (string) $company['company_status'],
		'fiscalAddress'      => empty( $address ) ? null : $address,
		'providerSource'     => $company['provider_source'] ?? null,
		'providerCheckedAt'  => $company['provider_checked_at'] ?? null,
		'billingEmail'       => ( $verified || $is_manager ) ? (string) $company['billing_email'] : null,
		'pendingBillingEmail'=> $is_manager ? ( $company['pending_billing_email'] ?? null ) : null,
		'billingEmailStatus' => $status,
		'phone'              => $company['phone'] ?? null,
	);
}

function papelito_company_create_owner_candidate( int $user_id, array $input ): array|WP_Error {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) { return new WP_Error( 'papelito_b2b_user_not_found', 'Usuário inválido.', array( 'status' => 404 ) ); }
	$profile = papelito_company_profile_upsert( $user_id, (string) $input['cpf'], (string) $input['birth_date'] );
	if ( is_wp_error( $profile ) ) { return $profile; }
	$lookup = papelito_cnpj_lookup( (string) $input['cnpj'], true );
	$evidence = papelito_company_owner_evidence( $user, (string) $input['cpf'], (string) $input['birth_date'], $lookup );
	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['companies']} WHERE cnpj = %s FOR UPDATE", papelito_normalize_cnpj( (string) $input['cnpj'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( null !== $existing ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'papelito_company_cnpj_exists', 'Já existe uma empresa com este CNPJ.', array( 'status' => 409 ) );
		}
		$company_id = papelito_company_create( (string) $input['cnpj'], array( 'legal_name' => (string) ( $lookup['legal_name'] ?? '' ), 'trade_name' => (string) ( $lookup['trade_name'] ?? '' ), 'billing_email' => $user->user_email, 'phone' => (string) get_user_meta( $user_id, 'phone_number', true ), 'registry_status' => (string) $lookup['status'], 'ownership_status' => 'pending_manual_review', 'company_status' => 'onboarding', 'created_by_user_id' => $user_id ) );
		if ( is_wp_error( $company_id ) ) { throw new RuntimeException( $company_id->get_error_code() ); }
		$member = papelito_company_member_upsert( $company_id, $user_id, array( 'member_role' => 'owner', 'member_status' => 'pending_company_approval', 'membership_origin' => 'owner_candidate', 'requested_at' => current_time( 'mysql', true ) ) );
		if ( is_wp_error( $member ) ) { throw new RuntimeException( $member->get_error_code() ); }
		$updated = papelito_company_update( $company_id, array( 'provider_source' => (string) $lookup['source'], 'provider_checked_at' => current_time( 'mysql', true ), 'provider_data_hash' => (string) $evidence['hash'] ) );
		if ( is_wp_error( $updated ) ) { throw new RuntimeException( $updated->get_error_code() ); }
		papelito_company_audit( $company_id, $user_id, 'company_created', array( 'registry_status' => $lookup['status'], 'evidence' => $evidence ) );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_b2b_onboarding_transaction_failed', 'Não foi possível concluir o onboarding da empresa.', array( 'status' => 409 ) );
	}
	papelito_b2b_mark_cohort( $user_id );
	return array( 'company_id' => $company_id, 'membership_id' => (int) $member, 'registry_status' => $lookup['status'], 'ownership_status' => 'pending_manual_review' );
}

function papelito_company_context( int $user_id ): array {
	$profile = papelito_company_profile_get( $user_id );
	$base    = array(
		'isB2bCohort'              => papelito_b2b_is_cohort( $user_id ),
		'identityStatus'             => $profile['identity_status'] ?? 'incomplete',
		'companyId'                  => null,
		'companyStatus'              => null,
		'companyRegistryStatus'      => null,
		'companyOwnershipStatus'     => null,
		'membershipRole'             => null,
		'membershipStatus'           => null,
		'onboardingStatus'           => 'none',
		'companySelectionRequired'   => false,
		'availableCompanies'         => array(),
		'canPurchase'                => false,
		'purchaseBlockReason'        => null,
	);

	// Empresas onde o usuário pode operar (membership ativa) alimentam a máquina de empresa ativa;
	// candidaturas pendentes (owner ou solicitação) alimentam o onboardingStatus quando não há ativa.
	$active_members  = papelito_company_members_active_for_user( $user_id );
	$active_members  = array_values( $active_members );
	$pending_members = papelito_company_members_pending_for_user( $user_id );
	$onboarding      = papelito_company_onboarding_get( $user_id );

	$base['availableCompanies'] = papelito_company_context_available_list( $active_members );

	$resolution = papelito_company_active_resolve( $user_id, $active_members );

	if ( 'company_selection_required' === $resolution['status'] ) {
		$base['onboardingStatus']         = 'company_selection_required';
		$base['companySelectionRequired'] = true;
		return papelito_company_context_with_purchase_capability( $user_id, $base );
	}

	if ( 'none' === $resolution['status'] || null === $resolution['member'] ) {
		if ( null !== $onboarding && in_array( (string) $onboarding['status'], array( 'pending_onboarding', 'pending_email' ), true ) ) {
			$base['onboardingStatus'] = 'incomplete';
			return papelito_company_context_with_purchase_capability( $user_id, $base );
		}
		// Sem empresa ativa: reflete a candidatura pendente mais recente, se houver.
		if ( ! empty( $pending_members ) ) {
			$pending             = $pending_members[0];
			$pending_company     = papelito_company_get( (int) $pending['company_id'] );
			$base['companyId']   = $pending_company ? (int) $pending_company['id'] : null;
			$base['companyStatus']          = $pending_company['company_status'] ?? null;
			$base['companyRegistryStatus']  = $pending_company['registry_status'] ?? null;
			$base['companyOwnershipStatus'] = $pending_company['ownership_status'] ?? null;
			$base['membershipRole']         = $pending['member_role'];
			$base['membershipStatus']       = $pending['member_status'];
			$base['onboardingStatus']       = 'pending';
		}
		return papelito_company_context_with_purchase_capability( $user_id, $base );
	}

	$member  = $resolution['member'];
	$company = papelito_company_get( (int) $member['company_id'] );
	if ( ! $company ) {
		return papelito_company_context_with_purchase_capability( $user_id, $base );
	}

	$base = array_merge(
		$base,
		array(
			'companyId'              => (int) $company['id'],
			'companyStatus'          => $company['company_status'],
			'companyRegistryStatus'  => $company['registry_status'],
			'companyOwnershipStatus' => $company['ownership_status'],
			'membershipRole'         => $member['member_role'],
			'membershipStatus'       => $member['member_status'],
			'onboardingStatus'       => 'complete',
			'membershipExpiresAt'    => $member['expires_at'] ?? null,
		)
	);
	$base['company'] = papelito_company_context_view( $company, $member );
	return papelito_company_context_with_purchase_capability( $user_id, $base );
}

/** @param array<string,mixed> $context @return array<string,mixed> */
function papelito_company_context_with_purchase_capability( int $user_id, array $context ): array {
	$capability = papelito_company_purchase_capability( $user_id, $context );
	$context['canPurchase'] = $capability['canPurchase'];
	$context['purchaseBlockReason'] = $capability['purchaseBlockReason'];
	return $context;
}

/**
 * Resumo (sem PII) das empresas onde o usuário tem membership ativa, para o seletor de empresa.
 *
 * @param array<int,array<string,mixed>> $active_members
 * @return array<int,array{companyId:int,legalName:string,tradeName:?string,role:string}>
 */
function papelito_company_context_available_list( array $active_members ): array {
	$list = array();
	foreach ( $active_members as $member ) {
		$company = papelito_company_get( (int) $member['company_id'] );
		if ( ! $company ) {
			continue;
		}
		$list[] = array(
			'companyId' => (int) $company['id'],
			'legalName' => (string) $company['legal_name'],
			'tradeName' => $company['trade_name'] ?? null,
			'role'      => (string) $member['member_role'],
		);
	}
	return $list;
}

/**
 * Candidaturas/solicitações pendentes do usuário (owner pendente ou solicitação de acesso),
 * mais recentes primeiro. Usado para refletir o estado quando não há empresa ativa.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_company_members_pending_for_user( int $user_id ): array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['members']} WHERE user_id = %d AND member_status IN ( 'pending_company_approval', 'pending_identity' ) ORDER BY updated_at DESC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return is_array( $rows ) ? $rows : array();
}

function papelito_company_resubmit_owner_candidate( int $user_id ) {
	$context = papelito_company_context( $user_id );
	if ( empty( $context['companyId'] ) || 'rejected' !== $context['companyOwnershipStatus'] ) { return new WP_Error( 'papelito_b2b_invalid_resubmission', 'Não há candidatura rejeitada para reenviar.', array( 'status' => 409 ) ); }
	$profile = papelito_company_profile_get( $user_id );
	if ( ! $profile || empty( $profile['birth_date_ciphertext'] ) ) { return new WP_Error( 'papelito_b2b_profile_incomplete', 'Perfil B2B incompleto.', array( 'status' => 422 ) ); }
	$cpf = papelito_pii_decrypt( (string) $profile['cpf_ciphertext'] ); $birth = papelito_pii_decrypt( (string) $profile['birth_date_ciphertext'] );
	if ( is_wp_error( $cpf ) || is_wp_error( $birth ) ) { return new WP_Error( 'papelito_b2b_profile_unavailable', 'Não foi possível recuperar o perfil.', array( 'status' => 500 ) ); }
	$company = papelito_company_get( (int) $context['companyId'] ); $user = get_userdata( $user_id );
	if ( ! $company || ! $user instanceof WP_User ) { return new WP_Error( 'papelito_b2b_company_not_found', 'Empresa não encontrada.', array( 'status' => 404 ) ); }
	$lookup = papelito_cnpj_lookup( (string) $company['cnpj'], true );
	$evidence = papelito_company_owner_evidence( $user, $cpf, $birth, $lookup );
	papelito_company_update( (int) $company['id'], array( 'registry_status' => (string) $lookup['status'], 'ownership_status' => 'pending_manual_review', 'ownership_rejection_reason' => null, 'ownership_rejected_by_user_id' => null, 'ownership_rejected_at' => null, 'provider_source' => (string) $lookup['source'], 'provider_checked_at' => current_time( 'mysql', true ), 'provider_data_hash' => (string) $evidence['hash'] ) );
	papelito_company_member_upsert( (int) $company['id'], $user_id, array( 'member_role' => 'owner', 'member_status' => 'pending_company_approval', 'requested_at' => current_time( 'mysql', true ) ) );
	papelito_company_audit( (int) $company['id'], $user_id, 'owner_resubmitted', array( 'registry_status' => $lookup['status'], 'evidence' => $evidence ) );
	return papelito_company_context( $user_id );
}

function papelito_can_purchase( int $user_id, ?array $context = null ): bool {
	if ( ! papelito_b2b_is_cohort( $user_id ) ) { return true; }
	$context = $context ?? papelito_company_context( $user_id );
	$expires = isset( $context['membershipExpiresAt'] ) ? strtotime( (string) $context['membershipExpiresAt'] ) : false;
	return 'verified' === $context['identityStatus'] && 'active' === $context['companyStatus'] && 'active' === $context['companyRegistryStatus'] && 'verified' === $context['companyOwnershipStatus'] && 'active' === $context['membershipStatus'] && in_array( $context['membershipRole'], array( 'owner', 'admin', 'buyer' ), true ) && ( false === $expires || $expires > time() );
}

/**
 * Calcula a capacidade de compra B2B e o motivo estável de bloqueio.
 *
 * @return array{canPurchase:bool,purchaseBlockReason:?string,company:?array<string,mixed>,membership:?array<string,mixed>}
 */
function papelito_company_purchase_capability( int $user_id, ?array $context = null ): array {
	if ( ! papelito_b2b_is_cohort( $user_id ) ) {
		return array( 'canPurchase' => true, 'purchaseBlockReason' => null, 'company' => null, 'membership' => null );
	}

	$context = $context ?? papelito_company_context( $user_id );
	if ( empty( $context['companyId'] ) ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'company_required', 'company' => null, 'membership' => null );
	}
	$company = papelito_company_get( (int) $context['companyId'] );
	$membership = $company ? papelito_company_member_get( (int) $company['id'], $user_id ) : null;
	if ( ! $company || ! $membership || ! papelito_can_purchase( $user_id, $context ) ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'company_or_membership_not_approved', 'company' => $company, 'membership' => $membership );
	}
	if ( empty( $company['billing_email_verified_at'] ) || ! is_email( (string) $company['billing_email'] ) ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'billing_email_not_confirmed', 'company' => $company, 'membership' => $membership );
	}
	$length = static function( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	};
	if ( '' === trim( (string) $company['legal_name'] ) || $length( (string) $company['legal_name'] ) > 64 ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'pagarme_customer_name_invalid', 'company' => $company, 'membership' => $membership );
	}
	if ( $length( (string) $company['billing_email'] ) > 64 ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'pagarme_customer_email_too_long', 'company' => $company, 'membership' => $membership );
	}
	$customer_code = 'papelito-company-' . (int) $company['id'];
	if ( $length( $customer_code ) > 52 ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'pagarme_customer_code_too_long', 'company' => $company, 'membership' => $membership );
	}
	global $wpdb;
	$tables = papelito_company_table_names();
	$duplicate_email = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['companies']} WHERE billing_email = %s AND id != %d LIMIT 1", (string) $company['billing_email'], (int) $company['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( null !== $duplicate_email ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'pagarme_billing_email_conflict', 'company' => $company, 'membership' => $membership );
	}
	foreach ( array( 'fiscal_cep', 'fiscal_state', 'fiscal_city', 'fiscal_neighborhood', 'fiscal_street', 'fiscal_number' ) as $field ) {
		if ( '' === trim( (string) ( $company[ $field ] ?? '' ) ) ) {
			return array( 'canPurchase' => false, 'purchaseBlockReason' => 'fiscal_address_incomplete', 'company' => $company, 'membership' => $membership );
		}
	}
	$phone = function_exists( 'papelito_auth_normalize_phone' ) ? papelito_auth_normalize_phone( (string) $company['phone'] ) : preg_replace( '/\\D+/', '', (string) $company['phone'] );
	if ( ! is_string( $phone ) || ! in_array( strlen( $phone ), array( 10, 11 ), true ) ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'business_phone_invalid', 'company' => $company, 'membership' => $membership );
	}
	if ( function_exists( 'papelito_cnpj_is_alphanumeric' ) && papelito_cnpj_is_alphanumeric( (string) $company['cnpj'] ) && ! papelito_b2b_flag( 'PAPELITO_ALPHANUMERIC_CNPJ_PAYMENT_ENABLED' ) ) {
		return array( 'canPurchase' => false, 'purchaseBlockReason' => 'cnpj_alphanumeric_payment_unsupported', 'company' => $company, 'membership' => $membership );
	}
	return array( 'canPurchase' => true, 'purchaseBlockReason' => null, 'company' => $company, 'membership' => $membership );
}
