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
	$value = trim( $value );

	if ( class_exists( 'Normalizer' ) ) {
		$normalized = Normalizer::normalize( $value, Normalizer::FORM_D );
		if ( is_string( $normalized ) ) {
			$value = $normalized;
		}
	}

	$value = remove_accents( $value );
	$value = preg_replace( '/\p{Mn}+/u', '', $value ) ?? $value;
	$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) ?? $value;
	$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );

	return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
}

function papelito_company_name_tokens( string $value, bool $without_particles = false ): array {
	$tokens = array_values( array_filter( explode( ' ', papelito_company_normalize_name( $value ) ) ) );
	if ( ! $without_particles ) {
		return $tokens;
	}
	return array_values( array_filter( $tokens, static fn( string $token ): bool => ! in_array( $token, array( 'DE', 'DA', 'DO', 'DAS', 'DOS', 'E' ), true ) ) );
}

function papelito_company_names_match( string $left, string $right ): bool {
	$left_full  = papelito_company_name_tokens( $left );
	$right_full = papelito_company_name_tokens( $right );
	if ( empty( $left_full ) || empty( $right_full ) ) {
		return false;
	}
	if ( $left_full === $right_full ) {
		return true;
	}

	// A única tolerância permitida é a inserção/remoção das partículas brasileiras. Não há
	// reordenação, prefixo, similaridade ou comparação parcial de nomes.
	return papelito_company_name_tokens( $left, true ) === papelito_company_name_tokens( $right, true );
}

function papelito_company_mei_owner_name_matches( string $full_name, string $legal_name ): bool {
	// TODO(b2b-kyc): esta exceção de MEI não prova a titularidade. Substituir por Consulta CPF
	// (CPF+nascimento) do Serpro ou KYC contratado antes de ampliar o rollout para produção.
	$owner_name = preg_replace( '/^[0-9.\/-]+\s+/', '', trim( $legal_name ) ) ?? '';
	return '' !== $owner_name && papelito_company_names_match( $full_name, $owner_name );
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

/**
 * Compara o CPF do responsável com a máscara do QSA da Receita.
 *
 * A Receita mascara o CPF como `***112108**`: esconde os 3 primeiros e os 2 últimos dígitos e
 * expõe os dígitos 4 a 9. Comparar "últimos 4 contra últimos 4" nunca casa, porque os dígitos
 * visíveis da máscara ficam no meio do CPF, não no fim.
 */
function papelito_company_cpf_mask_matches( string $cpf, string $raw_mask ): bool {
	$cpf = preg_replace( '/\D+/', '', $cpf ) ?? '';
	if ( 11 !== strlen( $cpf ) ) {
		return false;
	}

	// Preserva as posições: só os '*' viram lacuna, os dígitos mantêm o índice original.
	$mask = preg_replace( '/[^0-9*]+/', '', trim( $raw_mask ) ) ?? '';
	if ( 11 !== strlen( $mask ) ) {
		return false;
	}

	$visible_digits = 0;
	for ( $i = 0; $i < 11; $i++ ) {
		$char = $mask[ $i ];
		if ( ! ctype_digit( $char ) ) {
			continue;
		}
		++$visible_digits;
		if ( $char !== $cpf[ $i ] ) {
			return false;
		}
	}

	return $visible_digits >= 6;
}

/**
 * Lê a qualificação do sócio nos formatos dos provedores suportados.
 *
 * BrasilAPI e ReceitaWS entregam string (`"Presidente"`); CNPJ.ws entrega objeto
 * (`{"descricao": "..."}`). Ler só o formato de objeto fazia toda qualificação virar "unknown".
 */
function papelito_company_qsa_qualification( array $entry ): string {
	$raw = $entry['qualificacao_socio'] ?? $entry['qualificacao'] ?? $entry['qual'] ?? '';

	if ( is_array( $raw ) ) {
		$raw = $raw['descricao'] ?? $raw['nome'] ?? '';
	}

	// ReceitaWS prefixa o código: "16-Presidente". O código não interessa, só a descrição.
	$raw = preg_replace( '/^\s*\d+\s*-\s*/', '', (string) $raw ) ?? (string) $raw;

	return papelito_company_normalize_name( $raw );
}

/**
 * Nome do sócio nos formatos dos provedores: BrasilAPI usa `nome_socio`, ReceitaWS usa `nome`,
 * CNPJ.ws aninha em `socio.nome`.
 */
function papelito_company_qsa_partner_name( array $entry ): string {
	$raw = $entry['nome_socio'] ?? $entry['nome'] ?? '';

	if ( '' === $raw && isset( $entry['socio'] ) && is_array( $entry['socio'] ) ) {
		$raw = $entry['socio']['nome'] ?? '';
	}

	return papelito_company_normalize_name( (string) $raw );
}

/**
 * CPF mascarado do sócio. Nem todo provedor entrega — a ReceitaWS, por exemplo, omite.
 */
function papelito_company_qsa_partner_cpf_mask( array $entry ): string {
	$raw = $entry['cnpj_cpf_do_socio'] ?? $entry['cpf_cnpj_socio'] ?? $entry['cpf'] ?? '';

	if ( '' === $raw && isset( $entry['socio'] ) && is_array( $entry['socio'] ) ) {
		$raw = $entry['socio']['cpf_cnpj_socio'] ?? $entry['socio']['cpf'] ?? '';
	}

	return (string) $raw;
}

function papelito_company_cpf_mask_is_comparable( string $raw_mask ): bool {
	$mask = preg_replace( '/[^0-9*]+/', '', trim( $raw_mask ) ) ?? '';
	return 11 === strlen( $mask ) && preg_match_all( '/\d/', $mask ) >= 6;
}

function papelito_company_owner_evidence( ?WP_User $user, string $cpf, string $birth_date, array $lookup, ?string $registered_name = null ): array {
	$mei_confirmed = true === ( $lookup['is_mei'] ?? false ) && '2135' === preg_replace( '/\D+/', '', (string) ( $lookup['legal_nature_code'] ?? '' ) );
	$evidence = array( 'qsa_available' => false, 'qsa_sufficient' => false, 'cpf_mask_match' => 'unknown', 'name_match' => 'unknown', 'age_band_match' => 'unknown', 'eligible_qualification' => 'unknown', 'mei_confirmed' => $mei_confirmed, 'provider' => (string) ( $lookup['source'] ?? '' ), 'checked_at' => (string) ( $lookup['checked_at'] ?? gmdate( 'c' ) ) );
	$full_name = null !== $registered_name ? $registered_name : ( $user instanceof WP_User ? trim( (string) get_user_meta( $user->ID, 'first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'last_name', true ) ) : '' );
	if ( $mei_confirmed ) {
		$evidence['mei_name_match'] = papelito_company_mei_owner_name_matches( $full_name, (string) ( $lookup['legal_name'] ?? '' ) );
	}
	foreach ( (array) ( $lookup['qsa'] ?? array() ) as $entry ) {
		if ( ! is_array( $entry ) ) { continue; }
		$evidence['qsa_available'] = true;
		$name = papelito_company_qsa_partner_name( $entry );
		$mask = papelito_company_qsa_partner_cpf_mask( $entry );
		$has_age_band = isset( $entry['codigo_faixa_etaria'] ) && '' !== trim( (string) $entry['codigo_faixa_etaria'] );
		if ( '' !== $name && papelito_company_cpf_mask_is_comparable( $mask ) && $has_age_band ) {
			$evidence['qsa_sufficient'] = true;
		}
		$name_match = '' !== $name && papelito_company_names_match( $full_name, $name );
		$cpf_match = '' !== $mask && papelito_company_cpf_mask_matches( $cpf, $mask );
		$age_match = false;
		if ( $has_age_band ) {
			$age_match = (string) papelito_company_age_band( $birth_date ) === (string) $entry['codigo_faixa_etaria'];
		}
		$evidence['name_match'] = true === $evidence['name_match'] || $name_match;
		$evidence['cpf_mask_match'] = true === $evidence['cpf_mask_match'] || $cpf_match;
		$evidence['age_band_match'] = true === $evidence['age_band_match'] || $age_match;
		if ( $name_match && $cpf_match && $age_match ) {
			$evidence['partner_match'] = true;
		}
	}
	$evidence['hash'] = hash( 'sha256', wp_json_encode( $evidence ) ?: '' );
	return $evidence;
}

/**
 * Decide se a titularidade pode ser aprovada automaticamente pela evidência do QSA.
 *
 * Critério: CNPJ ativo na Receita E o sócio encontrado bate nome E CPF mascarado. O CPF mascarado
 * é a prova real de vínculo — sozinho o nome é homônimo em potencial. A qualificação eleva a
 * confiança mas nem todo provedor a entrega de forma comparável, então ela não bloqueia quando
 * nome e CPF já casaram.
 */
function papelito_company_should_auto_approve_owner( array $evidence, array $lookup ): bool {
	if ( 'active' !== (string) ( $lookup['status'] ?? '' ) ) {
		return false;
	}
	if ( true === ( $lookup['is_mei'] ?? false ) && '2135' === preg_replace( '/\D+/', '', (string) ( $lookup['legal_nature_code'] ?? '' ) ) ) {
		return true === ( $evidence['mei_name_match'] ?? null );
	}

	if ( true !== ( $evidence['qsa_available'] ?? false ) ) {
		return false;
	}

	return true === ( $evidence['partner_match'] ?? null );
}

function papelito_company_owner_review_path( array $evidence ): string|WP_Error {
	if ( true !== ( $evidence['qsa_sufficient'] ?? false ) ) {
		return 'document_required';
	}

	if ( true === ( $evidence['partner_match'] ?? false ) ) {
		return 'qsa_review';
	}

	return new WP_Error( 'papelito_b2b_qsa_mismatch', 'Os dados informados não correspondem aos responsáveis cadastrados para este CNPJ. Confira seu nome, CPF e data de nascimento.', array( 'status' => 422 ) );
}

function papelito_company_validate_owner_registry( string $cpf, string $birth_date, string $cnpj, string $full_name ): array|WP_Error {
	$lookup = papelito_cnpj_lookup( $cnpj, true );
	if ( 'active' !== (string) $lookup['status'] ) {
		if ( 'unavailable' === (string) $lookup['status'] ) {
			return new WP_Error( 'papelito_b2b_qsa_unavailable', 'Não foi possível consultar o CNPJ agora. Tente novamente.', array( 'status' => 503 ) );
		}
		return new WP_Error( 'papelito_b2b_registry_inactive', 'O CNPJ informado não está ativo.', array( 'status' => 422 ) );
	}

	$evidence = papelito_company_owner_evidence( null, $cpf, $birth_date, $lookup, $full_name );
	$review_path = papelito_company_owner_review_path( $evidence );
	if ( is_wp_error( $review_path ) ) {
		return $review_path;
	}

	return array(
		'lookup'          => $lookup,
		'evidence'        => $evidence,
		'review_required' => true,
		'review_path'     => $review_path,
	);
}

function papelito_company_audit( int $company_id, ?int $actor_user_id, string $action, array $payload = array() ): void {
	global $wpdb;
	$tables = papelito_company_table_names();
	$allowed = array( 'target_user_id', 'requester_user_id', 'invitation_id', 'application_id', 'attempt', 'role', 'invited_role', 'cpf_locked', 'has_expiration', 'registry_status', 'provider', 'source' );
	$safe    = array();
	foreach ( $allowed as $key ) {
		if ( array_key_exists( $key, $payload ) && is_scalar( $payload[ $key ] ) ) {
			$safe[ $key ] = $payload[ $key ];
		}
	}
	$wpdb->insert( $tables['audit'], array( 'company_id' => $company_id, 'actor_user_id' => $actor_user_id, 'action' => sanitize_key( $action ), 'payload_json' => wp_json_encode( $safe ), 'created_at' => current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Estado retomável do onboarding, para o formulário de conclusão do cadastro B2B.
 *
 * Expõe apenas dados não sensíveis: CPF e data de nascimento ficam cifrados em repouso e são
 * redigitados pelo usuário (o upsert é idempotente por uniq_cpf_hmac). Devolver o CPF em claro
 * alargaria a superfície de PII sem ganho de produto.
 *
 * @param array<string,mixed>      $onboarding Linha de wp_papelito_b2b_onboarding.
 * @param array<string,mixed>|null $profile    Linha de wp_papelito_customer_profiles.
 * @return array<string,mixed>
 */
function papelito_company_onboarding_resume_view( array $onboarding, ?array $profile ): array {
	return array(
		'type'         => (string) ( $onboarding['onboarding_type'] ?? '' ),
		'targetCnpj'   => ! empty( $onboarding['target_cnpj'] ) ? (string) $onboarding['target_cnpj'] : null,
		'cpfLast4'     => ! empty( $profile['cpf_last4'] ) ? (string) $profile['cpf_last4'] : null,
		'hasBirthDate' => ! empty( $profile['birth_date_ciphertext'] ),
		'expiresAt'    => ! empty( $onboarding['expires_at'] ) ? (string) $onboarding['expires_at'] : null,
	);
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
	$validated = papelito_company_validate_owner_registry( (string) $input['cpf'], (string) $input['birth_date'], (string) $input['cnpj'], isset( $input['full_name'] ) ? (string) $input['full_name'] : trim( $user->first_name . ' ' . $user->last_name ) );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}
	$lookup        = $validated['lookup'];
	$evidence      = $validated['evidence'];
	$auto_approved = empty( $validated['review_required'] );
	$ownership_status = $auto_approved ? 'verified' : 'pending_evidence';
	$company_status   = $auto_approved ? 'active' : 'onboarding';
	$member_status    = $auto_approved ? 'active' : 'pending_company_approval';
	$address          = is_array( $lookup['fiscal_address'] ?? null ) ? $lookup['fiscal_address'] : array();
	foreach ( array( 'cep', 'state', 'city', 'neighborhood', 'street', 'number', 'complement' ) as $field ) {
		if ( '' !== trim( (string) ( $input[ $field ] ?? '' ) ) ) {
			$address[ $field ] = (string) $input[ $field ];
		}
	}
	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$profile = papelito_company_profile_upsert( $user_id, (string) $input['cpf'], (string) $input['birth_date'] );
		if ( is_wp_error( $profile ) ) { throw new RuntimeException( $profile->get_error_code() ); }
		if ( ! empty( $input['full_name'] ) ) {
			$parts = preg_split( '/\s+/', trim( (string) $input['full_name'] ), 2 ) ?: array();
			$updated_user = wp_update_user( array( 'ID' => $user_id, 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'display_name' => trim( (string) $input['full_name'] ) ) );
			if ( is_wp_error( $updated_user ) ) { throw new RuntimeException( $updated_user->get_error_code() ); }
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['companies']} WHERE cnpj = %s FOR UPDATE", papelito_normalize_cnpj( (string) $input['cnpj'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$email_verified_at = 'verified' === (string) get_user_meta( $user_id, 'papelito_email_verification_status', true ) ? (string) get_user_meta( $user_id, 'papelito_email_verified_at', true ) : null;
		if ( is_array( $existing ) ) {
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$tables['owner_applications']} WHERE company_id = %d ORDER BY attempt_number DESC LIMIT 1 FOR UPDATE",
					(int) $existing['id']
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( (int) $existing['created_by_user_id'] !== $user_id || ! is_array( $latest ) || 'rejected' !== (string) $latest['application_status'] || ! empty( $latest['is_open'] ) ) {
				throw new DomainException( 'company_cnpj_exists' );
			}
			$company_id = (int) $existing['id'];
		} else {
			$company_id = papelito_company_create( (string) $input['cnpj'], array( 'legal_name' => (string) ( $lookup['legal_name'] ?? '' ), 'trade_name' => (string) ( $lookup['trade_name'] ?? '' ), 'billing_email' => $user->user_email, 'billing_email_verified_at' => $email_verified_at, 'phone' => (string) ( $input['phone'] ?? get_user_meta( $user_id, 'phone_number', true ) ), 'registry_status' => (string) $lookup['status'], 'ownership_status' => $ownership_status, 'company_status' => $company_status, 'owner_user_id' => $auto_approved ? $user_id : null, 'created_by_user_id' => $user_id ) );
			if ( is_wp_error( $company_id ) ) { throw new RuntimeException( $company_id->get_error_code() ); }
		}
		$member = papelito_company_member_upsert(
			$company_id,
			$user_id,
			array(
				'member_role'        => 'owner',
				'member_status'      => $member_status,
				'membership_origin'  => 'owner_candidate',
				'requested_at'       => current_time( 'mysql', true ),
				'approved_by_user_id'=> $auto_approved ? 0 : null,
				'approved_at'        => $auto_approved ? current_time( 'mysql', true ) : null,
				'rejected_at'        => null,
				'rejected_reason'    => null,
				'rejected_by_user_id'=> null,
			)
		);
		if ( is_wp_error( $member ) ) { throw new RuntimeException( $member->get_error_code() ); }
		$company_fields = array(
			'legal_name'                     => (string) ( $lookup['legal_name'] ?? '' ),
			'trade_name'                     => (string) ( $lookup['trade_name'] ?? '' ),
			'billing_email'                  => $user->user_email,
			'billing_email_verified_at'      => $email_verified_at,
			'phone'                          => (string) ( $input['phone'] ?? get_user_meta( $user_id, 'phone_number', true ) ),
			'registry_status'                => (string) $lookup['status'],
			'ownership_status'               => $ownership_status,
			'company_status'                 => $company_status,
			'owner_user_id'                  => $auto_approved ? $user_id : null,
			'verified_by_user_id'            => $auto_approved ? 0 : null,
			'verified_at'                    => $auto_approved ? current_time( 'mysql', true ) : null,
			'ownership_rejection_reason'     => null,
			'ownership_rejected_by_user_id'  => null,
			'ownership_rejected_at'          => null,
			'provider_source'                => (string) $lookup['source'],
			'provider_checked_at'            => current_time( 'mysql', true ),
			'provider_data_hash'             => (string) $evidence['hash'],
		);
		foreach ( array( 'cep' => 'fiscal_cep', 'state' => 'fiscal_state', 'city' => 'fiscal_city', 'neighborhood' => 'fiscal_neighborhood', 'street' => 'fiscal_street', 'number' => 'fiscal_number', 'complement' => 'fiscal_complement' ) as $source_key => $column ) {
			if ( array_key_exists( $source_key, $input ) ) {
				$company_fields[ $column ] = (string) $input[ $source_key ];
				continue;
			}
			if ( ! empty( $address[ $source_key ] ) ) { $company_fields[ $column ] = (string) $address[ $source_key ]; }
		}
		$updated = papelito_company_update( $company_id, $company_fields );
		if ( is_wp_error( $updated ) ) { throw new RuntimeException( $updated->get_error_code() ); }
		$application_id = papelito_company_owner_application_create(
			$company_id,
			$user_id,
			$auto_approved ? 'auto_approved' : 'document_required',
			$evidence
		);
		if ( is_wp_error( $application_id ) ) { throw new RuntimeException( $application_id->get_error_code() ); }
		papelito_company_audit( $company_id, $user_id, is_array( $existing ) ? 'owner_application_restarted' : 'company_created', array( 'application_id' => $application_id, 'registry_status' => $lookup['status'] ) );
		if ( $auto_approved ) {
			papelito_company_audit( $company_id, null, 'ownership_auto_approved', array( 'application_id' => $application_id, 'provider' => (string) $lookup['source'] ) );
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( DomainException $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_company_cnpj_exists', 'Já existe uma empresa com este CNPJ.', array( 'status' => 409 ) );
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_b2b_onboarding_transaction_failed', 'Não foi possível concluir o onboarding da empresa.', array( 'status' => 409 ) );
	}
	if ( ! function_exists( 'papelito_legacy_is_cohort' ) || ! papelito_legacy_is_cohort( $user_id ) ) {
		papelito_b2b_mark_cohort( $user_id );
	}
	update_user_meta( $user_id, 'papelito_account_state', $auto_approved ? 'active' : 'pending_review' );
	$application = papelito_company_owner_application_get( (int) $application_id );
	return array(
		'company_id'      => $company_id,
		'membership_id'   => (int) $member,
		'application_id'  => (int) $application_id,
		'application'     => $application ? papelito_company_owner_application_view( $application ) : null,
		'registry_status' => $lookup['status'],
		'ownership_status'=> $ownership_status,
		'auto_approved'   => $auto_approved,
		'next_step'       => $auto_approved ? 'complete' : 'manual_document',
	);
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
		'purchaseMode'               => 'blocked',
		'requiresB2bOnboarding'      => false,
		'userContextType'            => 'customer',
		'isInternalAdmin'            => false,
		'isVendor'                   => false,
		'hasCustomerContext'         => false,
	);
	if ( function_exists( 'papelito_legacy_context' ) ) {
		$base = array_merge( $base, papelito_legacy_context( $user_id ) );
	}

	// Empresas onde o usuário pode operar (membership ativa) alimentam a máquina de empresa ativa;
	// candidaturas pendentes (owner ou solicitação) alimentam o onboardingStatus quando não há ativa.
	$active_members  = papelito_company_members_active_for_user( $user_id );
	$active_members  = array_values( $active_members );
	$pending_members = papelito_company_members_pending_for_user( $user_id );
	$onboarding      = papelito_company_onboarding_get( $user_id );
	$application     = papelito_company_owner_application_latest_for_user( $user_id );
	$fresh_restart   = $application
		&& 'rejected' === (string) $application['application_status']
		&& null !== $onboarding
		&& 'pending_onboarding' === (string) $onboarding['status'];
	if ( $application && ! $fresh_restart ) {
		$base['ownerApplication'] = papelito_company_owner_application_view( $application );
	}

	$base['availableCompanies'] = papelito_company_context_available_list( $active_members );

	$resolution = papelito_company_active_resolve( $user_id, $active_members );

	if ( 'company_selection_required' === $resolution['status'] ) {
		$base['onboardingStatus']         = 'company_selection_required';
		$base['companySelectionRequired'] = true;
		return papelito_company_context_with_purchase_capability( $user_id, $base );
	}

	if ( 'none' === $resolution['status'] || null === $resolution['member'] ) {
		if ( null !== $onboarding && in_array( (string) $onboarding['status'], array( 'pending_onboarding', 'pending_email', 'pending_document' ), true ) ) {
			$base['onboardingStatus'] = 'incomplete';
			$base['onboarding']       = papelito_company_onboarding_resume_view( $onboarding, $profile );
			if ( $application && ! $fresh_restart && in_array( (string) $application['application_status'], array( 'document_required', 'pending_manual_review', 'rejected' ), true ) ) {
				$application_company = papelito_company_get( (int) $application['company_id'] );
				$application_member  = papelito_company_member_get( (int) $application['company_id'], $user_id );
				$base['companyId']   = $application_company ? (int) $application_company['id'] : null;
				$base['companyStatus']          = $application_company['company_status'] ?? null;
				$base['companyRegistryStatus']  = $application_company['registry_status'] ?? null;
				$base['companyOwnershipStatus'] = $application_company['ownership_status'] ?? null;
				$base['membershipRole']         = $application_member['member_role'] ?? null;
				$base['membershipStatus']       = $application_member['member_status'] ?? null;
			}
			return papelito_company_context_with_purchase_capability( $user_id, $base );
		}
		if ( $application && 'rejected' === (string) $application['application_status'] ) {
			$application_company = papelito_company_get( (int) $application['company_id'] );
			$application_member  = papelito_company_member_get( (int) $application['company_id'], $user_id );
			$base['companyId']   = $application_company ? (int) $application_company['id'] : null;
			$base['companyStatus']          = $application_company['company_status'] ?? null;
			$base['companyRegistryStatus']  = $application_company['registry_status'] ?? null;
			$base['companyOwnershipStatus'] = $application_company['ownership_status'] ?? null;
			$base['membershipRole']         = $application_member['member_role'] ?? null;
			$base['membershipStatus']       = $application_member['member_status'] ?? null;
			$base['onboardingStatus']       = 'rejected';
			return papelito_company_context_with_purchase_capability( $user_id, $base );
		}
		if ( $application && 'pending_manual_review' === (string) $application['application_status'] ) {
			$application_company = papelito_company_get( (int) $application['company_id'] );
			$application_member  = papelito_company_member_get( (int) $application['company_id'], $user_id );
			$base['companyId']   = $application_company ? (int) $application_company['id'] : null;
			$base['companyStatus']          = $application_company['company_status'] ?? null;
			$base['companyRegistryStatus']  = $application_company['registry_status'] ?? null;
			$base['companyOwnershipStatus'] = $application_company['ownership_status'] ?? null;
			$base['membershipRole']         = $application_member['member_role'] ?? null;
			$base['membershipStatus']       = $application_member['member_status'] ?? null;
			$base['onboardingStatus']       = 'pending';
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
	$context['purchaseMode'] = $capability['purchaseMode'];
	$context['requiresB2bOnboarding'] = $capability['requiresB2bOnboarding'];
	$context['userContextType'] = $capability['userContextType'];
	$context['isInternalAdmin'] = $capability['isInternalAdmin'];
	$context['isVendor'] = $capability['isVendor'];
	$context['hasCustomerContext'] = $capability['hasCustomerContext'];
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
	return new WP_Error( 'papelito_b2b_resubmission_closed', 'Esta solicitação foi encerrada. Inicie novamente o cadastro empresarial.', array( 'status' => 410 ) );
}

function papelito_user_is_internal_admin( WP_User $user ): bool {
	return papelito_user_has_role( $user, 'administrator' ) || user_can( $user, 'manage_options' ) || user_can( $user, 'papelito_manage_companies' ) || user_can( $user, 'papelito_manage_b2b_companies' );
}

function papelito_user_is_vendor( WP_User $user ): bool {
	return papelito_user_is_effective_seller( $user );
}

function papelito_user_is_customer_buyer( WP_User $user ): bool {
	if ( papelito_user_has_role( $user, 'customer' ) || papelito_b2b_is_cohort( $user->ID ) ) {
		return true;
	}
	if ( function_exists( 'papelito_company_members_active_for_user' ) && ! empty( papelito_company_members_active_for_user( $user->ID ) ) ) {
		return true;
	}
	return function_exists( 'papelito_company_members_pending_for_user' ) && ! empty( papelito_company_members_pending_for_user( $user->ID ) );
}

function papelito_user_context_type( WP_User $user ): string {
	$internal_admin = papelito_user_is_internal_admin( $user );
	$vendor = papelito_user_is_vendor( $user );
	$customer = papelito_user_is_customer_buyer( $user );
	if ( $customer && ( $internal_admin || $vendor ) ) {
		return 'hybrid';
	}
	if ( $internal_admin ) {
		return 'internal_admin';
	}
	if ( $vendor ) {
		return 'vendor';
	}
	return 'customer';
}

function papelito_company_purchase_capability_result( array $base, bool $can_purchase, string $purchase_mode, ?string $reason, ?array $company, ?array $membership ): array {
	return array_merge(
		$base,
		array(
			'canPurchase' => $can_purchase,
			'purchaseMode' => $purchase_mode,
			'purchaseBlockReason' => $reason,
			'requiresB2bOnboarding' => 'blocked' === $purchase_mode,
			'company' => $company,
			'membership' => $membership,
		)
	);
}

function papelito_can_purchase( int $user_id, ?array $context = null ): bool {
	return ! empty( papelito_company_purchase_capability( $user_id, $context )['canPurchase'] );
}

function papelito_company_purchase_capability( int $user_id, ?array $context = null ): array {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return papelito_company_purchase_capability_result( array( 'userContextType' => 'customer', 'isInternalAdmin' => false, 'isVendor' => false, 'hasCustomerContext' => false ), false, 'not_buyer', 'not_a_customer_buyer', null, null );
	}
	$internal_admin = papelito_user_is_internal_admin( $user );
	$vendor = papelito_user_is_vendor( $user );
	$customer = papelito_user_is_customer_buyer( $user );
	$base = array(
		'userContextType' => papelito_user_context_type( $user ),
		'isInternalAdmin' => $internal_admin,
		'isVendor' => $vendor,
		'hasCustomerContext' => $customer,
	);
	if ( ! $customer ) {
		return papelito_company_purchase_capability_result( $base, false, 'not_buyer', 'not_a_customer_buyer', null, null );
	}
	$context = $context ?? papelito_company_context( $user_id );
	if ( ! empty( $context['companySelectionRequired'] ) ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_selection_required', null, null );
	}
	if ( empty( $context['companyId'] ) ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_missing', null, null );
	}
	$company = papelito_company_get( (int) $context['companyId'] );
	$membership = $company ? papelito_company_member_get( (int) $company['id'], $user_id ) : null;
	if ( ! $company ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_missing', null, null );
	}
	if ( 'suspended' === (string) $company['company_status'] ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_suspended', $company, $membership );
	}
	if ( 'archived' === (string) $company['company_status'] || 'rejected' === (string) $company['ownership_status'] ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_rejected', $company, $membership );
	}
	if ( 'onboarding' === (string) $company['company_status'] || 'verified' !== (string) $company['ownership_status'] ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_pending_review', $company, $membership );
	}
	if ( in_array( (string) $company['registry_status'], array( 'unavailable', 'provider_unsupported' ), true ) ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_registry_unavailable', $company, $membership );
	}
	if ( 'conflict' === (string) $company['registry_status'] ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_provider_conflict', $company, $membership );
	}
	if ( 'active' !== (string) $company['registry_status'] ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'company_registry_inactive', $company, $membership );
	}
	if ( ! $membership ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'membership_missing', $company, null );
	}
	$membership_status = (string) $membership['member_status'];
	if ( 'suspended' === $membership_status ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'membership_suspended', $company, $membership );
	}
	if ( ! empty( $membership['expires_at'] ) && strtotime( (string) $membership['expires_at'] ) <= time() ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'membership_expired', $company, $membership );
	}
	if ( 'active' !== $membership_status ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'membership_pending', $company, $membership );
	}
	// CPF é exigido apenas em memberships que representam responsável/legado. Um membro
	// aceito por convite possui autorização empresarial explícita e não recebe perfil CPF.
	if ( 'not_required' !== (string) ( $membership['identity_requirement'] ?? 'required' ) ) {
		$identity_status = (string) ( $context['identityStatus'] ?? 'incomplete' );
		if ( in_array( $identity_status, array( 'rejected', 'suspended' ), true ) ) {
			return papelito_company_purchase_capability_result( $base, false, 'blocked', 'identity_rejected', $company, $membership );
		}
		if ( 'verified' !== $identity_status ) {
			return papelito_company_purchase_capability_result( $base, false, 'blocked', 'identity_incomplete', $company, $membership );
		}
	}
	if ( ! in_array( (string) $membership['member_role'], papelito_company_purchasing_roles(), true ) ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'role_cannot_purchase', $company, $membership );
	}
	if ( empty( $company['billing_email_verified_at'] ) || ! is_email( (string) $company['billing_email'] ) ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'billing_email_unverified', $company, $membership );
	}
	foreach ( array( 'fiscal_cep', 'fiscal_state', 'fiscal_city', 'fiscal_neighborhood', 'fiscal_street', 'fiscal_number' ) as $field ) {
		if ( '' === trim( (string) ( $company[ $field ] ?? '' ) ) ) {
			return papelito_company_purchase_capability_result( $base, false, 'blocked', 'fiscal_address_incomplete', $company, $membership );
		}
	}
	$length = static fn( string $value ): int => function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	$phone = function_exists( 'papelito_auth_normalize_phone' ) ? papelito_auth_normalize_phone( (string) $company['phone'] ) : preg_replace( '/\\D+/', '', (string) $company['phone'] );
	$customer_code = 'papelito-company-' . (int) $company['id'];
	if ( '' === trim( (string) $company['legal_name'] ) || $length( (string) $company['legal_name'] ) > 64 || $length( (string) $company['billing_email'] ) > 64 || $length( $customer_code ) > 52 || ! is_string( $phone ) || ! in_array( strlen( $phone ), array( 10, 11 ), true ) ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'payment_profile_incomplete', $company, $membership );
	}
	if ( function_exists( 'papelito_cnpj_is_alphanumeric' ) && papelito_cnpj_is_alphanumeric( (string) $company['cnpj'] ) && ! papelito_b2b_flag( 'PAPELITO_ALPHANUMERIC_CNPJ_PAYMENT_ENABLED' ) ) {
		return papelito_company_purchase_capability_result( $base, false, 'blocked', 'alphanumeric_cnpj_payment_disabled', $company, $membership );
	}
	return papelito_company_purchase_capability_result( $base, true, 'b2b', null, $company, $membership );
}
