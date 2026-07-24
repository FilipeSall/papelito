<?php
/**
 * Empresa ativa e coorte B2B (Fase 1B).
 *
 * A empresa ativa da sessão é resolvida e PERSISTIDA no servidor (usermeta B2B), nunca confiada
 * ao navegador. A cada leitura de contexto (ex.: /auth/me) a seleção é revalidada contra a
 * membership ativa; se a empresa selecionada não tiver mais membership ativa, a seleção é limpa
 * e a compra bloqueada.
 *
 * Regras (decisão do cliente):
 *   - nenhuma membership ativa      → onboardingStatus=none,                       canPurchase=false
 *   - exatamente uma ativa          → seleção automática (persistida),             canPurchase=regra
 *   - mais de uma e nenhuma escolha → onboardingStatus=company_selection_required, canPurchase=false
 *   - selecionada sem membership    → limpa a seleção e bloqueia                   canPurchase=false
 *
 * Qualquer entrada no mundo B2B (criar empresa, solicitar acesso, aceitar convite, receber
 * membership) marca o coorte de forma "sticky": o usuário não volta ao fluxo legado para burlar
 * as regras.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_B2B_ACTIVE_COMPANY_META' ) ) {
	define( 'PAPELITO_B2B_ACTIVE_COMPANY_META', 'papelito_b2b_active_company_id' );
}

if ( ! defined( 'PAPELITO_B2B_REQUIRED_META' ) ) {
	define( 'PAPELITO_B2B_REQUIRED_META', 'papelito_b2b_required' );
}

/**
 * Marca (sticky) que o usuário pertence ao coorte B2B. Idempotente.
 */
function papelito_b2b_mark_cohort( int $user_id ): void {
	if ( '1' !== (string) get_user_meta( $user_id, PAPELITO_B2B_REQUIRED_META, true ) ) {
		update_user_meta( $user_id, PAPELITO_B2B_REQUIRED_META, '1' );
	}
}

/**
 * Usuário está no coorte B2B (sujeito às regras de canPurchase)?
 */
function papelito_b2b_is_cohort( int $user_id ): bool {
	return '1' === (string) get_user_meta( $user_id, PAPELITO_B2B_REQUIRED_META, true );
}

/**
 * Lê o id da empresa ativa persistido no servidor (0 se não houver).
 */
function papelito_company_active_get_selection( int $user_id ): int {
	return (int) get_user_meta( $user_id, PAPELITO_B2B_ACTIVE_COMPANY_META, true );
}

/**
 * Persiste (server-side) a empresa ativa selecionada.
 */
function papelito_company_active_set_selection( int $user_id, int $company_id ): void {
	update_user_meta( $user_id, PAPELITO_B2B_ACTIVE_COMPANY_META, $company_id );
}

/**
 * Limpa a seleção de empresa ativa.
 */
function papelito_company_active_clear_selection( int $user_id ): void {
	delete_user_meta( $user_id, PAPELITO_B2B_ACTIVE_COMPANY_META );
}

/**
 * Resolve a empresa ativa a partir das memberships ativas + seleção persistida.
 *
 * Efeito colateral controlado: normaliza a seleção persistida (auto-seleção quando há exatamente
 * uma; limpeza quando a seleção não corresponde mais a uma membership ativa).
 *
 * @param array<int,array<string,mixed>> $active_members Memberships com member_status = active.
 * @return array{status:string,member:array<string,mixed>|null}
 *   status ∈ none | company_selection_required | selected
 */
function papelito_company_active_resolve( int $user_id, array $active_members ): array {
	if ( empty( $active_members ) ) {
		papelito_company_active_clear_selection( $user_id );
		return array(
			'status' => 'none',
			'member' => null,
		);
	}

	if ( 1 === count( $active_members ) ) {
		$member = $active_members[0];
		papelito_company_active_set_selection( $user_id, (int) $member['company_id'] );
		return array(
			'status' => 'selected',
			'member' => $member,
		);
	}

	// Múltiplas memberships ativas: exige seleção explícita e válida.
	$selected_id = papelito_company_active_get_selection( $user_id );
	if ( $selected_id > 0 ) {
		foreach ( $active_members as $member ) {
			if ( (int) $member['company_id'] === $selected_id ) {
				return array(
					'status' => 'selected',
					'member' => $member,
				);
			}
		}
		// Seleção aponta para empresa sem membership ativa → limpa e volta a exigir seleção.
		papelito_company_active_clear_selection( $user_id );
	}

	return array(
		'status' => 'company_selection_required',
		'member' => null,
	);
}

/**
 * Seleciona explicitamente a empresa ativa. Valida a membership ativa no servidor.
 *
 * @return true|WP_Error
 */
function papelito_company_active_select( int $user_id, int $company_id ) {
	$member = papelito_company_member_get( $company_id, $user_id );

	$is_active = function_exists( 'papelito_company_member_is_operationally_active' )
		? papelito_company_member_is_operationally_active( $member )
		: null !== $member && 'active' === $member['member_status'];
	if ( ! $is_active ) {
		return new WP_Error( 'papelito_b2b_membership_not_active', 'Você não possui uma associação ativa nesta empresa.', array( 'status' => 403 ) );
	}

	papelito_company_active_set_selection( $user_id, $company_id );
	papelito_company_audit( $company_id, $user_id, 'active_company_selected', array() );

	return true;
}
