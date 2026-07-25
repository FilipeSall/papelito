<?php
/**
 * Solicitações de entrada em empresa existente (Fase 1B).
 *
 * Um usuário autenticado informa um CNPJ. O backend valida/normaliza e, sem revelar se a empresa
 * existe (anti-enumeração), cria/atualiza uma membership em pending_company_approval quando há
 * empresa válida. A resposta é sempre a mesma forma, exista ou não a empresa.
 *
 * Owners/admins ativos veem a solicitação na lista administrativa da empresa e podem aprovar ou
 * rejeitar com motivo. Reenvio após rejeição segue política explícita e auditada: cooldown entre
 * tentativas + limite máximo de tentativas.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_ACCESS_REQUEST_COOLDOWN_HOURS' ) ) {
	define( 'PAPELITO_ACCESS_REQUEST_COOLDOWN_HOURS', 24 );
}

if ( ! defined( 'PAPELITO_ACCESS_REQUEST_MAX_ATTEMPTS' ) ) {
	define( 'PAPELITO_ACCESS_REQUEST_MAX_ATTEMPTS', 3 );
}

/**
 * Resposta neutra e estável (não revela existência/estado da empresa).
 *
 * @return array{status:string}
 */
function papelito_company_access_request_neutral_response(): array {
	return array( 'status' => 'received' );
}

/**
 * Cria/atualiza uma solicitação de entrada por CNPJ.
 *
 * Nunca revela ao solicitante se a empresa existe ou seus dados. Marca o coorte B2B assim que o
 * usuário tenta entrar no mundo B2B (sticky), independentemente de a empresa existir.
 *
 * @return array{status:string}|WP_Error WP_Error apenas para erros de VALIDAÇÃO do próprio input
 *   (CNPJ malformado) ou de política do solicitante (cooldown/limite) — nunca sobre a empresa.
 */
function papelito_company_access_request_submit( int $user_id, string $raw_cnpj ) {
	if ( ! papelito_validate_cnpj( $raw_cnpj ) ) {
		return new WP_Error( 'papelito_b2b_invalid_cnpj', 'CNPJ inválido.', array( 'status' => 422 ) );
	}

	if ( ! function_exists( 'papelito_legacy_is_cohort' ) || ! papelito_legacy_is_cohort( $user_id ) ) {
		// Entrar no mundo B2B marca o coorte, mesmo que a empresa não exista (evita burlar o gate).
		papelito_b2b_mark_cohort( $user_id );
	}

	$company = papelito_company_find_by_cnpj( $raw_cnpj );

	// Empresa inexistente: resposta neutra idêntica (anti-enumeração).
	if ( null === $company ) {
		if ( function_exists( 'papelito_company_onboarding_mark_completed' ) ) { papelito_company_onboarding_mark_completed( $user_id ); }
		return papelito_company_access_request_neutral_response();
	}

	$company_id = (int) $company['id'];
	$existing   = papelito_company_member_get( $company_id, $user_id );

	if ( null !== $existing ) {
		$status = (string) $existing['member_status'];

		// Já é membro ativo/suspenso ou já tem solicitação pendente → resposta neutra (idempotente).
		if ( in_array( $status, array( 'active', 'suspended', 'pending_company_approval', 'pending_identity' ), true ) ) {
			if ( function_exists( 'papelito_company_onboarding_mark_completed' ) ) { papelito_company_onboarding_mark_completed( $user_id, $company_id, (int) $existing['id'] ); }
			return papelito_company_access_request_neutral_response();
		}

		// Rejeitado/revogado/expirado: política de reenvio (cooldown + limite).
		$policy = papelito_company_access_request_check_resubmit_policy( $existing );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
	}

	$now      = current_time( 'mysql', true );
	$attempts = null !== $existing ? ( (int) $existing['request_count'] ) : 0;

	$member = papelito_company_member_upsert(
		$company_id,
		$user_id,
		array(
			'member_role'         => 'buyer',
			'member_status'       => 'pending_company_approval',
			'membership_origin'   => 'access_request',
			'requested_at'        => $now,
			'last_request_at'     => $now,
			'request_count'       => $attempts + 1,
			// Zera marcadores de decisão anterior num reenvio.
			'rejected_at'         => null,
			'rejected_reason'     => null,
			'rejected_by_user_id' => null,
		)
	);
	if ( is_wp_error( $member ) ) {
		return $member;
	}
	if ( function_exists( 'papelito_company_onboarding_mark_completed' ) ) { papelito_company_onboarding_mark_completed( $user_id, $company_id, (int) $member ); }

	papelito_company_audit(
		$company_id,
		$user_id,
		'access_requested',
		array(
			'requester_user_id' => $user_id,
			'attempt'           => $attempts + 1,
		)
	);

	return papelito_company_access_request_neutral_response();
}

/**
 * Aplica a política de reenvio após rejeição: cooldown mínimo + limite de tentativas.
 *
 * @param array<string,mixed> $existing Membership atual do solicitante.
 * @return true|WP_Error
 */
function papelito_company_access_request_check_resubmit_policy( array $existing ) {
	$attempts = (int) $existing['request_count'];
	if ( $attempts >= PAPELITO_ACCESS_REQUEST_MAX_ATTEMPTS ) {
		return new WP_Error( 'papelito_b2b_access_request_limit', 'Limite de solicitações atingido para esta empresa.', array( 'status' => 429 ) );
	}

	$last = ! empty( $existing['last_request_at'] ) ? strtotime( (string) $existing['last_request_at'] ) : false;
	if ( false !== $last ) {
		$cooldown = PAPELITO_ACCESS_REQUEST_COOLDOWN_HOURS * HOUR_IN_SECONDS;
		if ( ( time() - $last ) < $cooldown ) {
			return new WP_Error( 'papelito_b2b_access_request_cooldown', 'Aguarde antes de solicitar acesso novamente.', array( 'status' => 429 ) );
		}
	}

	return true;
}

/**
 * Lista as solicitações de acesso pendentes de uma empresa (owner/admin).
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_company_access_requests_list( int $actor_user_id, int $company_id ) {
	$loaded = papelito_company_authz_load( $actor_user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
	}

	$rows = papelito_company_members_list( $company_id, array( 'pending_company_approval' ) );
	$list = array();
	foreach ( $rows as $row ) {
		// Só solicitações de acesso (não a candidatura de owner original, que passa por review admin).
		if ( 'access_request' !== (string) ( $row['membership_origin'] ?? '' ) ) {
			continue;
		}
		$requester = get_userdata( (int) $row['user_id'] );
		$list[]    = array(
			'memberId'    => (int) $row['id'],
			'userId'      => (int) $row['user_id'],
			'displayName' => $requester instanceof WP_User ? $requester->display_name : '',
			'email'       => $requester instanceof WP_User ? $requester->user_email : '',
			'requestedAt' => $row['last_request_at'] ?? $row['requested_at'] ?? null,
			'attempts'    => (int) $row['request_count'],
		);
	}

	return $list;
}

/**
 * Aprova uma solicitação de acesso pendente (owner/admin). O solicitante vira membro ativo.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_company_access_request_approve( int $actor_user_id, int $company_id, int $target_user_id, string $role = 'buyer' ) {
	$loaded = papelito_company_authz_load( $actor_user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
	}
	if ( ! in_array( $role, papelito_company_assignable_roles(), true ) ) {
		return new WP_Error( 'papelito_b2b_invalid_role', 'Papel inválido.', array( 'status' => 422 ) );
	}

	$target = papelito_company_member_get( $company_id, $target_user_id );
	if ( null === $target || 'pending_company_approval' !== (string) $target['member_status'] || 'access_request' !== (string) ( $target['membership_origin'] ?? '' ) ) {
		return new WP_Error( 'papelito_b2b_access_request_not_pending', 'Solicitação não está pendente.', array( 'status' => 409 ) );
	}

	$member = papelito_company_member_upsert(
		$company_id,
		$target_user_id,
		array(
			'member_role'         => $role,
			'member_status'       => 'active',
			'approved_by_user_id' => $actor_user_id,
			'approved_at'         => current_time( 'mysql', true ),
		)
	);
	if ( is_wp_error( $member ) ) {
		return $member;
	}

	papelito_b2b_mark_cohort( $target_user_id );
	if ( function_exists( 'papelito_legacy_complete_if_ready' ) ) {
		papelito_legacy_complete_if_ready( $target_user_id, 'access_request_approved' );
	}
	papelito_company_audit(
		$company_id,
		$actor_user_id,
		'access_request_approved',
		array(
			'target_user_id' => $target_user_id,
			'role'           => $role,
		)
	);

	return (array) papelito_company_member_get( $company_id, $target_user_id );
}

/**
 * Rejeita uma solicitação de acesso pendente com motivo (owner/admin).
 *
 * @return true|WP_Error
 */
function papelito_company_access_request_reject( int $actor_user_id, int $company_id, int $target_user_id, string $reason ) {
	$loaded = papelito_company_authz_load( $actor_user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
	}
	if ( '' === trim( $reason ) ) {
		return new WP_Error( 'papelito_b2b_rejection_reason_required', 'Motivo da rejeição obrigatório.', array( 'status' => 422 ) );
	}

	$target = papelito_company_member_get( $company_id, $target_user_id );
	if ( null === $target || 'pending_company_approval' !== (string) $target['member_status'] || 'access_request' !== (string) ( $target['membership_origin'] ?? '' ) ) {
		return new WP_Error( 'papelito_b2b_access_request_not_pending', 'Solicitação não está pendente.', array( 'status' => 409 ) );
	}

	$member = papelito_company_member_upsert(
		$company_id,
		$target_user_id,
		array(
			'member_status'       => 'rejected',
			'rejected_at'         => current_time( 'mysql', true ),
			'rejected_reason'     => sanitize_text_field( $reason ),
			'rejected_by_user_id' => $actor_user_id,
		)
	);
	if ( is_wp_error( $member ) ) {
		return $member;
	}

	papelito_company_audit( $company_id, $actor_user_id, 'access_request_rejected', array( 'target_user_id' => $target_user_id ) );

	return true;
}
