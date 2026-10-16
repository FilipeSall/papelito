<?php
/**
 * Política financeira local: restrição da Pagar.me para CNAEs de fumo.
 *
 * Regra única, reutilizada por cadastro, KYC, seleção de gateway e cobrança. Os códigos 4729601,
 * 4636201 e 4636202, em posição principal ou secundária, tornam a Pagar.me indisponível para o
 * vendor. Sem evidência BrasilAPI verificada, a Pagar.me também fica indisponível
 * (`verification_pending`) — ausência de prova nunca vira permissão.
 *
 * A regra de ouro: a restrição é da Papelito, não resposta da Pagar.me. Nada aqui grava status
 * remoto `refused`, apaga recipient ou KYC, ou decide que o Mercado Pago aceita o vendor.
 *
 * Só vale para vendor fora do modo legado: quem nunca ingressou na coorte segue o fluxo antigo.
 * Depois do ingresso (marcador persistente), a restrição continua mesmo com a flag desligada.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_PAYMENT_POLICY_RULE_VERSION = '2026-10-08.1';
const PAPELITO_VENDOR_PAYMENT_POLICY_STATE_META   = 'papelito_vendor_payment_policy_state';
const PAPELITO_VENDOR_PAYMENT_POLICY_AUDIT_META   = 'papelito_vendor_payment_policy_audit';
const PAPELITO_VENDOR_PAYMENT_POLICY_AUDIT_LIMIT  = 20;

/**
 * CNAEs que bloqueiam a Pagar.me, com a descrição oficial do IBGE.
 *
 * @return array<string,string>
 */
function papelito_vendor_payment_policy_blocked_cnaes(): array {
	return array(
		'4729601' => 'Comércio varejista de produtos do fumo',
		'4636201' => 'Comércio atacadista de fumo beneficiado',
		'4636202' => 'Comércio atacadista de cigarros, cigarrilhas e charutos',
	);
}

/**
 * Classifica a evidência CNAE para a Pagar.me.
 *
 * Bloqueio confirmado prevalece mesmo com evidência vencida; ausência de bloqueio só vira
 * `allowed` com evidência verificada e dentro da validade.
 *
 * @param array<string,mixed> $evidence Evidência de `papelito_vendor_cnae_current()`.
 * @return array{status:string,codes:array<int,array<string,string>>,rule_version:string,evidence_status:string,reason:string}
 */
function papelito_vendor_payment_policy_classify( array $evidence ): array {
	$evidence_status = (string) ( $evidence['status'] ?? 'pending' );
	$codes           = in_array( $evidence_status, array( 'verified', 'expired' ), true )
		? papelito_vendor_payment_policy_matching_codes( $evidence )
		: array();

	if ( ! empty( $codes ) ) {
		$status = 'blocked_cnae';
	} elseif ( 'verified' === $evidence_status ) {
		$status = 'allowed';
	} else {
		$status = 'verification_pending';
	}

	return array(
		'status'          => $status,
		'codes'           => $codes,
		'rule_version'    => PAPELITO_VENDOR_PAYMENT_POLICY_RULE_VERSION,
		'evidence_status' => $evidence_status,
		'reason'          => (string) ( $evidence['reason'] ?? '' ),
	);
}

/**
 * Códigos restritos encontrados, com a posição em que apareceram.
 *
 * @param array<string,mixed> $evidence Evidência CNAE.
 * @return array<int,array{code:string,position:string,label:string}>
 */
function papelito_vendor_payment_policy_matching_codes( array $evidence ): array {
	$blocked = papelito_vendor_payment_policy_blocked_cnaes();
	$found   = array();
	$primary = (string) ( $evidence['primary'] ?? '' );

	if ( isset( $blocked[ $primary ] ) ) {
		$found[] = array(
			'code'     => $primary,
			'position' => 'primary',
			'label'    => $blocked[ $primary ],
		);
	}

	foreach ( (array) ( $evidence['secondary'] ?? array() ) as $code ) {
		$code = (string) $code;
		if ( isset( $blocked[ $code ] ) ) {
			$found[] = array(
				'code'     => $code,
				'position' => 'secondary',
				'label'    => $blocked[ $code ],
			);
		}
	}

	return $found;
}

/**
 * Política da Pagar.me vigente para o vendor.
 *
 * Vendor legado recebe `allowed` com motivo `legacy`: a restrição não se aplica a quem nunca
 * ingressou no fluxo novo.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>
 */
function papelito_vendor_pagarme_policy( int $vendor_id ): array {
	if ( PAPELITO_VENDOR_PAYMENT_MODE_LEGACY === papelito_vendor_payment_mode( $vendor_id ) ) {
		return array(
			'status'          => 'allowed',
			'codes'           => array(),
			'rule_version'    => PAPELITO_VENDOR_PAYMENT_POLICY_RULE_VERSION,
			'evidence_status' => '',
			'reason'          => 'legacy',
		);
	}

	return papelito_vendor_payment_policy_classify( papelito_vendor_cnae_current( $vendor_id ) );
}

/**
 * Guarda de toda operação nova na Pagar.me: cadastro, KYC e cobrança.
 *
 * Consulta e reconciliação de cobrança antiga não passam por aqui.
 *
 * @param int $vendor_id Vendor.
 * @return true|WP_Error
 */
function papelito_vendor_pagarme_policy_guard( int $vendor_id ) {
	$policy = papelito_vendor_pagarme_policy( $vendor_id );

	if ( 'blocked_cnae' === $policy['status'] ) {
		return new WP_Error(
			'papelito_pagarme_blocked_cnae',
			'Pagar.me indisponível para este CNAE.',
			array(
				'status'      => 422,
				'cnaeCodes'   => $policy['codes'],
				'ruleVersion' => $policy['rule_version'],
			)
		);
	}

	if ( 'verification_pending' === $policy['status'] ) {
		return new WP_Error(
			'papelito_pagarme_cnae_verification_pending',
			'A atividade econômica da empresa ainda não foi verificada para a Pagar.me.',
			array(
				'status' => 409,
				'reason' => $policy['reason'],
			)
		);
	}

	return true;
}

/**
 * Reavalia a política depois de mudança de evidência ou CNPJ.
 *
 * Só transição real (status ou códigos) audita e invalida a cobertura regional, para não
 * derrubar o cache em lote a cada reconsulta sem efeito.
 *
 * @param int $vendor_id Vendor.
 * @return void
 */
function papelito_vendor_payment_policy_evidence_changed( int $vendor_id ): void {
	if ( $vendor_id <= 0 || PAPELITO_VENDOR_PAYMENT_MODE_LEGACY === papelito_vendor_payment_mode( $vendor_id, false ) ) {
		return;
	}

	$policy   = papelito_vendor_payment_policy_classify( papelito_vendor_cnae_current( $vendor_id ) );
	$codes    = array_map( static fn( array $code ): string => $code['position'] . ':' . $code['code'], $policy['codes'] );
	$snapshot = array(
		'status'       => $policy['status'],
		'codes'        => $codes,
		'rule_version' => $policy['rule_version'],
	);
	$previous = get_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_POLICY_STATE_META, true );

	if ( is_array( $previous ) && ( $previous['status'] ?? '' ) === $snapshot['status'] && ( $previous['codes'] ?? array() ) === $snapshot['codes'] && ( $previous['rule_version'] ?? '' ) === $snapshot['rule_version'] ) {
		return;
	}

	update_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_POLICY_STATE_META, $snapshot );
	papelito_vendor_payment_policy_audit( $vendor_id, array_merge( $snapshot, array( 'at' => current_time( 'mysql', true ) ) ) );

	if ( function_exists( 'papelito_coverage_bump_cache_version' ) ) {
		papelito_coverage_bump_cache_version();
	}
}

/**
 * Acrescenta uma entrada ao anel de auditoria da política do vendor.
 *
 * @param int                 $vendor_id Vendor.
 * @param array<string,mixed> $entry     Entrada sem PII.
 * @return void
 */
function papelito_vendor_payment_policy_audit( int $vendor_id, array $entry ): void {
	$log   = get_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_POLICY_AUDIT_META, true );
	$log   = is_array( $log ) ? $log : array();
	$log[] = $entry;

	update_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_POLICY_AUDIT_META, array_slice( $log, -PAPELITO_VENDOR_PAYMENT_POLICY_AUDIT_LIMIT ) );
}
