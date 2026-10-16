<?php
/**
 * Adapter de cadastro da Pagar.me sobre o perfil financeiro comum.
 *
 * Para vendor fora do modo legado, o recebedor Pagar.me passa a ser montado a partir de
 * `VendorFinancialProfile` — mesmas exigências, mesmo payload, mesma titularidade PJ. As portas
 * antigas (cadastro, pendências, admin, recipient-draft) continuam gravando o draft legado e são
 * espelhadas no perfil, para que todas convirjam para o mesmo estado.
 *
 * A regra de ouro: aprovação não atravessa gateways. Nada daqui importa KYC do Mercado Pago, e a
 * restrição CNAE dispensa os campos exclusivos da Pagar.me em vez de transformá-los em barreira
 * global de venda.
 *
 * O que este módulo não faz: não chama a Pagar.me (isso continua em `pagarme_recipients.php`) e
 * não recria recipient para "corrigir" campo imutável no gateway.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

/**
 * Dados financeiros de onde sai o recebedor Pagar.me do vendor.
 *
 * Vendor legado: draft legado, como sempre. Demais: perfil comum, importado do draft na primeira
 * leitura quando ainda não existe.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>|null No formato do draft legado.
 */
function papelito_pagarme_recipient_source_draft( int $vendor_id ): ?array {
	$legacy_draft = function_exists( 'papelito_get_vendor_pagarme_recipient_draft' ) ? papelito_get_vendor_pagarme_recipient_draft( $vendor_id ) : null;

	if ( PAPELITO_VENDOR_PAYMENT_MODE_LEGACY === papelito_vendor_payment_mode( $vendor_id, false ) ) {
		return $legacy_draft;
	}

	if ( null === papelito_vendor_financial_profile_stored( $vendor_id ) && is_array( $legacy_draft ) ) {
		papelito_vendor_financial_profile_import_legacy( $vendor_id, false );
	}

	return papelito_vendor_financial_profile_internal( $vendor_id ) ?? $legacy_draft;
}

/**
 * Espelha no perfil o draft gravado por uma porta legada de cadastro.
 *
 * CPF ou nascimento vazios ou inválidos no draft (cadastro parcial) não apagam o que o perfil já
 * tem; os demais campos seguem o draft, que é o que a porta acabou de declarar.
 *
 * @param int                 $vendor_id Vendor.
 * @param array<string,mixed> $draft     Draft sanitizado recém-gravado.
 * @return void
 */
function papelito_pagarme_financial_profile_sync_from_draft( int $vendor_id, array $draft ): void {
	if ( $vendor_id <= 0 || PAPELITO_VENDOR_PAYMENT_MODE_LEGACY === papelito_vendor_payment_mode( $vendor_id, false ) ) {
		return;
	}

	$stored = papelito_vendor_financial_profile_stored( $vendor_id );

	if ( null === $stored ) {
		papelito_vendor_financial_profile_import_legacy( $vendor_id, false );
		return;
	}

	$patch = $draft;
	if ( is_array( $patch['managingPartners'][0] ?? null ) ) {
		$patch['managingPartners'][0] = papelito_pagarme_financial_profile_usable_sensitive( $patch['managingPartners'][0] );
	}

	papelito_vendor_financial_profile_patch( $vendor_id, $patch, (int) $stored['version'], 'legacy_port' );
}

/**
 * Remove do sócio vindo do draft os campos sensíveis que o perfil recusaria.
 *
 * @param array<string,mixed> $partner Sócio do draft.
 * @return array<string,mixed>
 */
function papelito_pagarme_financial_profile_usable_sensitive( array $partner ): array {
	foreach ( array( 'document', 'birthdate' ) as $field ) {
		$value = sanitize_text_field( (string) ( $partner[ $field ] ?? '' ) );
		if ( null !== papelito_vendor_financial_profile_sensitive_error( $field, $value ) ) {
			unset( $partner[ $field ] );
		}
	}

	return $partner;
}

/**
 * Listener do evento de draft gravado pelas portas legadas.
 *
 * @param mixed $vendor_id Vendor.
 * @param mixed $draft     Draft sanitizado.
 * @return void
 */
function papelito_pagarme_financial_profile_on_draft_saved( mixed $vendor_id, mixed $draft ): void {
	if ( is_array( $draft ) ) {
		papelito_pagarme_financial_profile_sync_from_draft( (int) $vendor_id, $draft );
	}
}

add_action( 'papelito_vendor_pagarme_draft_saved', 'papelito_pagarme_financial_profile_on_draft_saved', 10, 2 );

/**
 * Pendências exclusivas da Pagar.me, separadas da elegibilidade global.
 *
 * Com CNAE bloqueado não há pendência a cobrar: a seção Pagar.me fica indisponível e seus campos
 * não são requisito para vender pelo Mercado Pago.
 *
 * @param int $vendor_id Vendor.
 * @return array{policy:string,pendingFields:array<int,string>}
 */
function papelito_pagarme_financial_profile_pending( int $vendor_id ): array {
	$policy = papelito_vendor_pagarme_policy( $vendor_id );

	if ( 'blocked_cnae' === $policy['status'] ) {
		return array(
			'policy'        => 'blocked_cnae',
			'pendingFields' => array(),
		);
	}

	$source = papelito_pagarme_recipient_source_draft( $vendor_id ) ?? array();
	$step3  = function_exists( 'papelito_sanitize_vendor_pagarme_step3_partial' ) ? papelito_sanitize_vendor_pagarme_step3_partial( $source ) : $source;
	$fields = function_exists( 'papelito_collect_vendor_pending_registration_fields' )
		? papelito_collect_vendor_pending_registration_fields(
			$step3,
			sanitize_text_field( (string) get_user_meta( $vendor_id, 'phone_number', true ) ),
			sanitize_text_field( (string) get_user_meta( $vendor_id, 'cnpj', true ) )
		)
		: array();

	return array(
		'policy'        => (string) $policy['status'],
		'pendingFields' => array_values( $fields ),
	);
}

/**
 * Pendências de cadastro que prendem o onboarding do vendor, conforme o modo financeiro.
 *
 * No fluxo de gateways, representante legal, faturamento e conta bancária são exigências só da
 * Pagar.me: continuam como pendência do card Pagar.me, mas não impedem o vendor que vai receber
 * pelo Mercado Pago (ou que tem a Pagar.me bloqueada por CNAE) de chegar ao painel.
 *
 * @param array<int,string> $fields Pendências calculadas do draft.
 * @param string            $mode   Modo financeiro do vendor.
 * @return array<int,string>
 */
function papelito_vendor_onboarding_filter_pending( array $fields, string $mode ): array {
	if ( PAPELITO_VENDOR_PAYMENT_MODE_LEGACY === $mode ) {
		return $fields;
	}

	return array_values(
		array_filter(
			$fields,
			static fn( string $field ): bool => 'annualRevenue' !== $field && 0 !== strpos( $field, 'partner.' ) && 0 !== strpos( $field, 'bankAccount.' )
		)
	);
}

/**
 * Pendências do onboarding do vendor, já filtradas pelo modo financeiro.
 *
 * @param int $vendor_id Vendor.
 * @return array<int,string>
 */
function papelito_vendor_onboarding_pending_fields( int $vendor_id ): array {
	$fields = function_exists( 'papelito_resolve_vendor_pending_registration_fields' ) ? papelito_resolve_vendor_pending_registration_fields( $vendor_id ) : array();

	return papelito_vendor_onboarding_filter_pending( $fields, papelito_vendor_payment_mode( $vendor_id, false ) );
}
