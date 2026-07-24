<?php
/**
 * Autorização central do painel da empresa B2B (Fase 1B).
 *
 * Regra de ouro: a autorização SEMPRE recarrega company + membership do actor do banco a cada
 * mutação — nunca confia em companyId, role, status ou canPurchase vindos do navegador. Toda a
 * matriz RBAC vive aqui, num único lugar auditável.
 *
 * Matriz (papéis: owner > admin > buyer > viewer):
 *
 * | Ação                       | owner | admin      | buyer | viewer |
 * |----------------------------|-------|------------|-------|--------|
 * | comprar                    | sim   | sim        | sim   | não    |
 * | listar membros             | sim   | sim        | não   | não    |
 * | convidar                   | sim   | sim        | não   | não    |
 * | aprovar/rejeitar acesso    | sim   | sim        | não   | não    |
 * | alterar buyer/viewer/admin | sim   | limitado*  | não   | não    |
 * | suspender/revogar member   | sim   | limitado*  | não   | não    |
 * | alterar/remover owner      | transferência explícita | não | não | não |
 *
 * *limitado = admin não age sobre owner (não remove, não rebaixa, não promove a owner) e não
 * promove ninguém a owner; owner só via transferência de ownership.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Papéis atribuíveis por owner/admin (owner nunca via convite/patch — só transferência).
 *
 * @return array<int,string>
 */
function papelito_company_assignable_roles(): array {
	return array( 'admin', 'buyer', 'viewer' );
}

/**
 * Papéis com permissão de compra.
 *
 * @return array<int,string>
 */
function papelito_company_purchasing_roles(): array {
	return array( 'owner', 'admin', 'buyer' );
}

/**
 * Recarrega, do banco, o contexto de autorização de um actor sobre uma empresa.
 *
 * @return array{company:array<string,mixed>,membership:array<string,mixed>}|WP_Error
 */
function papelito_company_authz_load( int $actor_user_id, int $company_id ) {
	$company = papelito_company_get( $company_id );
	if ( null === $company ) {
		return new WP_Error( 'papelito_b2b_company_not_found', 'Empresa não encontrada.', array( 'status' => 404 ) );
	}

	$membership = papelito_company_member_get( $company_id, $actor_user_id );
	$is_active = function_exists( 'papelito_company_member_is_operationally_active' )
		? papelito_company_member_is_operationally_active( $membership )
		: null !== $membership && 'active' === $membership['member_status'];
	if ( ! $is_active ) {
		// Anti-enumeração: quem não é membro ativo não distingue "não existe" de "sem permissão".
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );
	}

	return array(
		'company'    => $company,
		'membership' => $membership,
	);
}

/**
 * O actor pode gerenciar membros (listar, convidar, aprovar/rejeitar)?
 *
 * @param array<string,mixed> $membership
 */
function papelito_company_authz_can_manage( array $membership ): bool {
	return in_array( (string) $membership['member_role'], array( 'owner', 'admin' ), true );
}

/**
 * Autoriza uma ação sobre um MEMBRO-ALVO (mudar papel, suspender, revogar, remover).
 *
 * Recarrega tudo do banco. Cobre as proteções obrigatórias:
 *   - admin não age sobre owner (rebaixar/remover/suspender) nem promove a owner;
 *   - ninguém rebaixa/remove o último owner ativo;
 *   - owner não se auto-remove/rebaixa se for o último owner.
 *
 * @param string|null $new_role Papel-alvo quando a ação é mudança de papel (null para suspender/revogar/remover).
 * @return true|WP_Error
 */
function papelito_company_authz_guard_member_action( int $actor_user_id, int $company_id, int $target_user_id, string $action, ?string $new_role = null ) {
	$loaded = papelito_company_authz_load( $actor_user_id, $company_id );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}

	$actor_role = (string) $loaded['membership']['member_role'];
	if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
		return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
	}

	$target = papelito_company_member_get( $company_id, $target_user_id );
	if ( null === $target ) {
		return new WP_Error( 'papelito_b2b_member_not_found', 'Membro não encontrado.', array( 'status' => 404 ) );
	}

	$target_role = (string) $target['member_role'];

	// Nenhum papel é promovido a owner por esta via — só transferência de ownership.
	if ( 'change_role' === $action && 'owner' === (string) $new_role ) {
		return new WP_Error( 'papelito_b2b_owner_via_transfer_only', 'Ownership só muda por transferência explícita.', array( 'status' => 422 ) );
	}
	if ( 'change_role' === $action && ! in_array( (string) $new_role, papelito_company_assignable_roles(), true ) ) {
		return new WP_Error( 'papelito_b2b_invalid_role', 'Papel inválido.', array( 'status' => 422 ) );
	}

	// Admin nunca age sobre um owner.
	if ( 'admin' === $actor_role && 'owner' === $target_role ) {
		return new WP_Error( 'papelito_b2b_admin_cannot_touch_owner', 'Admin não pode alterar o owner.', array( 'status' => 403 ) );
	}

	// Proteção do último owner: rebaixar, suspender, revogar ou remover um owner só é
	// permitido se restar outro owner ativo (transferência trata a troca).
	$owner_affecting = ( 'owner' === $target_role ) && in_array( $action, array( 'change_role', 'suspend', 'revoke', 'remove' ), true );
	if ( $owner_affecting ) {
		$active_owners = papelito_company_count_active_owners( $company_id );
		$still_owner   = ( 'change_role' === $action ) && ( 'owner' === (string) $new_role );
		if ( $active_owners <= 1 && ! $still_owner ) {
			return new WP_Error( 'papelito_b2b_last_owner_protected', 'A empresa não pode ficar sem owner ativo.', array( 'status' => 409 ) );
		}
	}

	return true;
}
