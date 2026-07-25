<?php
/**
 * Gestão de memberships por owner/admin (Fase 1B).
 *
 * Alterar papel, suspender, revogar e transferir ownership. Toda mutação recarrega company +
 * membership do banco (via company_authz.php) antes de agir, aplica a matriz RBAC e as proteções
 * de invariante (último owner, admin×owner). Transferência de ownership é transacional.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Altera o papel de um membro (para admin/buyer/viewer; owner só por transferência).
 *
 * @return array<string,mixed>|WP_Error Membership resultante.
 */
function papelito_company_member_change_role( int $actor_user_id, int $company_id, int $target_user_id, string $new_role ) {
	$guard = papelito_company_authz_guard_member_action( $actor_user_id, $company_id, $target_user_id, 'change_role', $new_role );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$member = papelito_company_member_upsert(
		$company_id,
		$target_user_id,
		array(
			'member_role'             => $new_role,
			'member_status'           => 'active',
			'role_changed_at'         => current_time( 'mysql', true ),
			'role_changed_by_user_id' => $actor_user_id,
		)
	);
	if ( is_wp_error( $member ) ) {
		return $member;
	}

	papelito_company_audit(
		$company_id,
		$actor_user_id,
		'member_role_changed',
		array(
			'target_user_id' => $target_user_id,
			'new_role'       => $new_role,
		)
	);

	return (array) papelito_company_member_get( $company_id, $target_user_id );
}

/**
 * Transição genérica de status de um membro (suspend / revoke / reactivate).
 *
 * @param string $action suspend|revoke|reactivate
 * @return array<string,mixed>|WP_Error
 */
function papelito_company_member_set_status( int $actor_user_id, int $company_id, int $target_user_id, string $action ) {
	$map = array(
		'suspend'    => 'suspended',
		'revoke'     => 'revoked',
		'reactivate' => 'active',
	);
	if ( ! isset( $map[ $action ] ) ) {
		return new WP_Error( 'papelito_b2b_invalid_member_action', 'Ação de membro inválida.', array( 'status' => 422 ) );
	}

	$guard = papelito_company_authz_guard_member_action( $actor_user_id, $company_id, $target_user_id, 'reactivate' === $action ? 'change_role' : $action );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$now    = current_time( 'mysql', true );
	$fields = array( 'member_status' => $map[ $action ] );
	if ( 'suspend' === $action ) {
		$fields['suspended_at']         = $now;
		$fields['suspended_by_user_id'] = $actor_user_id;
	} elseif ( 'revoke' === $action ) {
		$fields['revoked_at']         = $now;
		$fields['revoked_by_user_id'] = $actor_user_id;
	}

	$member = papelito_company_member_upsert( $company_id, $target_user_id, $fields );
	if ( is_wp_error( $member ) ) {
		return $member;
	}
	if ( 'reactivate' === $action && function_exists( 'papelito_legacy_complete_if_ready' ) ) {
		papelito_legacy_complete_if_ready( $target_user_id, 'membership_reactivated' );
	}

	// Ao suspender/revogar, invalida a seleção de empresa ativa do alvo se apontava para esta empresa.
	if ( in_array( $action, array( 'suspend', 'revoke' ), true ) && papelito_company_active_get_selection( $target_user_id ) === $company_id ) {
		papelito_company_active_clear_selection( $target_user_id );
	}

	papelito_company_audit( $company_id, $actor_user_id, 'member_' . $action, array( 'target_user_id' => $target_user_id ) );

	return (array) papelito_company_member_get( $company_id, $target_user_id );
}

/**
 * Define/atualiza a expiração de uma membership.
 *
 * @param string|null $expires_at Data MySQL UTC ou null para remover a expiração.
 * @return array<string,mixed>|WP_Error
 */
function papelito_company_member_set_expiration( int $actor_user_id, int $company_id, int $target_user_id, ?string $expires_at ) {
	$guard = papelito_company_authz_guard_member_action( $actor_user_id, $company_id, $target_user_id, 'suspend' );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$target = papelito_company_member_get( $company_id, $target_user_id );
	if ( null === $target ) {
		return new WP_Error( 'papelito_b2b_member_not_found', 'Membro não encontrado.', array( 'status' => 404 ) );
	}
	if ( 'owner' === (string) $target['member_role'] && null !== $expires_at ) {
		return new WP_Error( 'papelito_b2b_owner_expiration_forbidden', 'Owner não pode possuir expiração.', array( 'status' => 422 ) );
	}

	$member = papelito_company_member_upsert( $company_id, $target_user_id, array( 'expires_at' => $expires_at ) );
	if ( is_wp_error( $member ) ) {
		return $member;
	}

	papelito_company_audit(
		$company_id,
		$actor_user_id,
		'member_expiration_set',
		array(
			'target_user_id' => $target_user_id,
			'has_expiration' => null !== $expires_at,
		)
	);

	return (array) papelito_company_member_get( $company_id, $target_user_id );
}

/**
 * Transferência de ownership: o owner atual passa o papel de owner para um membro ativo.
 *
 * Fluxo explícito, transacional e auditado. O owner atual vira admin; o alvo vira owner e
 * torna-se o owner_user_id canônico. A empresa nunca fica sem owner.
 *
 * @return true|WP_Error
 */
function papelito_company_transfer_ownership( int $actor_user_id, int $company_id, int $target_user_id ) {
	$loaded = papelito_company_authz_load( $actor_user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}

	// Só o owner ativo pode transferir.
	if ( 'owner' !== (string) $loaded['membership']['member_role'] ) {
		return new WP_Error( 'papelito_b2b_only_owner_transfers', 'Apenas o owner pode transferir a titularidade.', array( 'status' => 403 ) );
	}
	if ( $actor_user_id === $target_user_id ) {
		return new WP_Error( 'papelito_b2b_transfer_to_self', 'Selecione outro membro para a transferência.', array( 'status' => 422 ) );
	}

	$target = papelito_company_member_get( $company_id, $target_user_id );
	if ( null === $target || 'active' !== $target['member_status'] ) {
		return new WP_Error( 'papelito_b2b_transfer_target_invalid', 'O destinatário precisa ser um membro ativo.', array( 'status' => 422 ) );
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$now    = current_time( 'mysql', true );

	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['companies']} WHERE id = %d FOR UPDATE", $company_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $locked ) || (int) $locked['owner_user_id'] !== $actor_user_id ) {
			throw new RuntimeException( 'owner changed' );
		}

		$demoted  = $wpdb->update(
			$tables['members'],
			array(
				'member_role'             => 'admin',
				'role_changed_at'         => $now,
				'role_changed_by_user_id' => $actor_user_id,
				'updated_at'              => $now,
			),
			array(
				'company_id'  => $company_id,
				'user_id'     => $actor_user_id,
				'member_role' => 'owner',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$promoted = $wpdb->update(
			$tables['members'],
			array(
				'member_role'             => 'owner',
				'member_status'           => 'active',
				'role_changed_at'         => $now,
				'role_changed_by_user_id' => $actor_user_id,
				'updated_at'              => $now,
			),
			array(
				'company_id' => $company_id,
				'user_id'    => $target_user_id,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $demoted || false === $promoted ) {
			throw new RuntimeException( 'transfer write failed' );
		}

		$wpdb->update(
			$tables['companies'],
			array(
				'owner_user_id' => $target_user_id,
				'updated_at'    => $now,
			),
			array( 'id' => $company_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_b2b_transfer_failed', 'Não foi possível transferir a titularidade.', array( 'status' => 409 ) );
	}

	papelito_company_audit(
		$company_id,
		$actor_user_id,
		'ownership_transferred',
		array(
			'from_user_id' => $actor_user_id,
			'to_user_id'   => $target_user_id,
		)
	);

	return true;
}
