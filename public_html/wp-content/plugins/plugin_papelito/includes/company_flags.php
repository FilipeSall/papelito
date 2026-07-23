<?php
/**
 * Feature flags do modelo B2B (Fase 0).
 *
 * Todas as flags nascem DESLIGADAS. Elas são a fonte autoritativa (server-side) de gate; o
 * frontend recebe capacidades já calculadas e nunca decide sozinho.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Flags conhecidas e seus defaults. Todas false na Fase 0.
 *
 * @return array<string,bool>
 */
function papelito_b2b_flags(): array {
	return array(
		'PAPELITO_B2B_COMPANY_MODEL_ENABLED'         => false,
		'PAPELITO_B2B_PURCHASE_ENFORCED'             => false,
		'PAPELITO_COMPANY_MANUAL_APPROVAL_ENABLED'   => false,
		'PAPELITO_QSA_AUTO_APPROVE_ENABLED'          => false,
		'PAPELITO_ALPHANUMERIC_CNPJ_ENABLED'         => false,
		'PAPELITO_ALPHANUMERIC_CNPJ_PAYMENT_ENABLED' => false,
	);
}

/**
 * Lê uma feature flag do B2B via ambiente, com default seguro (false).
 *
 * Flags desconhecidas retornam false.
 */
function papelito_b2b_flag( string $name ): bool {
	$flags = papelito_b2b_flags();

	if ( ! array_key_exists( $name, $flags ) ) {
		return false;
	}

	return papelito_env_bool( $name, $flags[ $name ] );
}
