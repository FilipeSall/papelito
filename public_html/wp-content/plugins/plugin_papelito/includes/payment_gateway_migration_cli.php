<?php
/**
 * Migração assistida de um vendor para o fluxo de gateways (MP-021).
 *
 * `wp papelito payment-gateways migrate --vendor-id=<id> --dry-run` mostra o que seria feito sem
 * gravar, sem consultar gateway ou BrasilAPI e sem consumir OAuth; `--apply --expected-version=<v>`
 * aplica só se nada mudou desde o preview. A migração importa o draft legado para o perfil comum,
 * grava o marcador persistente da política e reavalia a saúde dos gateways — nada além disso.
 *
 * A regra de ouro: preservar e não cobrar. Recipient, draft e KYC da Pagar.me ficam intactos;
 * preferência explícita nunca é sobrescrita (o default implícito já é Mercado Pago); tentativa em
 * criação ou de resultado incerto interrompe a migração até a reconciliação. A ativação da coorte
 * continua sendo a allowlist no ambiente, editada à mão no servidor.
 *
 * O preview só traz contagens, códigos e estados — nunca CNPJ, documento, token ou payload de KYC.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

/**
 * Preview da migração do vendor.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>|WP_Error
 */
function papelito_payment_migration_plan( int $vendor_id ) {
	$user = get_userdata( $vendor_id );

	if ( ! $user instanceof WP_User || ! papelito_user_is_effective_seller( $user ) ) {
		return new WP_Error( 'papelito_payment_migration_vendor_invalid', 'Vendor não encontrado ou sem papel de vendor.' );
	}

	$cnae       = papelito_vendor_cnae_current( $vendor_id );
	$profile    = papelito_vendor_financial_profile_import_legacy( $vendor_id, true );
	$preference = papelito_vendor_payment_preference( $vendor_id );
	$recipient  = papelito_pagarme_get_vendor_recipient_state( $vendor_id );
	$account    = papelito_vendor_payment_account_latest( $vendor_id, PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO, papelito_mercadopago_environment() );
	$open       = papelito_payment_attempts_open_counts( $vendor_id );
	$enforced   = papelito_vendor_payment_policy_enforced( $vendor_id );

	$plan = array(
		'vendorId'     => $vendor_id,
		'cnae'         => array(
			'status'    => (string) $cnae['status'],
			'primary'   => (string) ( $cnae['primary'] ?? '' ),
			'secondary' => array_values( (array) ( $cnae['secondary'] ?? array() ) ),
		),
		'pagarme'      => array(
			'policy'          => (string) papelito_vendor_payment_policy_classify( $cnae )['status'],
			'recipientStatus' => (string) ( $recipient['status'] ?? '' ),
		),
		'mercadopago'  => array(
			'accountStatus'  => is_array( $account ) ? (string) $account['status'] : 'not_connected',
			'identityStatus' => is_array( $account ) ? (string) $account['identity_status'] : '',
		),
		'profile'      => $profile,
		'preference'   => $preference,
		'enforced'     => $enforced,
		'inCohort'     => papelito_payment_gateways_vendor_in_cohort( $vendor_id ),
		'openAttempts' => $open,
		'operations'   => papelito_payment_migration_operations( $profile, $enforced ),
		'blockers'     => (int) ( $open['unknown'] ?? 0 ) + (int) ( $open['creating'] ?? 0 ) > 0 ? array( 'active_attempts_unknown' ) : array(),
	);

	$plan['version'] = substr( hash( 'sha256', (string) wp_json_encode( $plan ) ), 0, 12 );

	return $plan;
}

/**
 * Operações que a aplicação executaria.
 *
 * @param array<string,mixed> $profile  Resultado do preview de importação do perfil.
 * @param bool                $enforced Se o marcador já existe.
 * @return array<int,string>
 */
function papelito_payment_migration_operations( array $profile, bool $enforced ): array {
	$operations = array();

	if ( in_array( (string) ( $profile['action'] ?? '' ), array( 'create', 'update' ), true ) ) {
		$operations[] = 'import_profile';
	}

	if ( ! $enforced ) {
		$operations[] = 'mark_policy_enforced';
	}

	return $operations;
}

/**
 * Aplica a migração se o estado ainda é o do preview.
 *
 * @param int    $vendor_id        Vendor.
 * @param string $expected_version Versão devolvida pelo preview.
 * @return array<string,mixed>|WP_Error Resumo do que foi feito.
 */
function papelito_payment_migration_apply( int $vendor_id, string $expected_version ) {
	$plan = papelito_payment_migration_plan( $vendor_id );

	if ( is_wp_error( $plan ) ) {
		return $plan;
	}

	if ( ! hash_equals( (string) $plan['version'], $expected_version ) ) {
		return new WP_Error( 'papelito_payment_migration_stale', 'O estado do vendor mudou desde o preview. Rode o --dry-run de novo.' );
	}

	if ( ! empty( $plan['blockers'] ) ) {
		return new WP_Error( 'papelito_payment_migration_blocked', 'Há tentativa de pagamento em criação ou incerta. Reconcilie antes de migrar.' );
	}

	if ( in_array( 'import_profile', $plan['operations'], true ) ) {
		$imported = papelito_vendor_financial_profile_import_legacy( $vendor_id, false );
		if ( is_wp_error( $imported ) ) {
			return $imported;
		}
	}

	if ( in_array( 'mark_policy_enforced', $plan['operations'], true ) ) {
		papelito_vendor_payment_policy_mark_enforced( $vendor_id );
	}

	foreach ( papelito_payment_providers() as $provider ) {
		papelito_payment_gateway_health_record( $vendor_id, $provider );
	}

	return array(
		'vendorId'  => $vendor_id,
		'applied'   => $plan['operations'],
		'nextSteps' => array( 'vendor_connects_mercadopago', 'admin_confirms_identity', 'add_vendor_to_allowlist' ),
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'Papelito_Payment_Gateways_CLI' ) ) {
	/**
	 * Comando `wp papelito payment-gateways`.
	 */
	class Papelito_Payment_Gateways_CLI {

		/**
		 * Migra um vendor para o fluxo de gateways.
		 *
		 * ## OPTIONS
		 *
		 * --vendor-id=<id>
		 * : ID do vendor.
		 *
		 * [--dry-run]
		 * : Mostra o preview sem gravar.
		 *
		 * [--apply]
		 * : Aplica a migração.
		 *
		 * [--expected-version=<version>]
		 * : Versão devolvida pelo preview (obrigatória com --apply).
		 *
		 * @param array<int,string>    $args       Posicionais.
		 * @param array<string,string> $assoc_args Opções.
		 * @return void
		 */
		public function migrate( array $args, array $assoc_args ): void {
			$vendor_id = absint( $assoc_args['vendor-id'] ?? 0 );
			$dry_run   = isset( $assoc_args['dry-run'] );
			$apply     = isset( $assoc_args['apply'] );

			if ( $dry_run === $apply ) {
				WP_CLI::error( 'Use exatamente uma opção: --dry-run ou --apply --expected-version=<versão>.' );
			}

			$result = $dry_run
				? papelito_payment_migration_plan( $vendor_id )
				: papelito_payment_migration_apply( $vendor_id, (string) ( $assoc_args['expected-version'] ?? '' ) );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}

		/**
		 * Reconsulta o CNAE do vendor na BrasilAPI (ação externa explícita, separada do preview).
		 *
		 * @subcommand refresh-cnae
		 *
		 * ## OPTIONS
		 *
		 * --vendor-id=<id>
		 * : ID do vendor.
		 *
		 * @param array<int,string>    $args       Posicionais.
		 * @param array<string,string> $assoc_args Opções.
		 * @return void
		 */
		public function refresh_cnae( array $args, array $assoc_args ): void {
			$evidence = papelito_vendor_cnae_refresh( absint( $assoc_args['vendor-id'] ?? 0 ) );

			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'status'    => $evidence['status'],
						'reason'    => $evidence['reason'],
						'primary'   => $evidence['primary'],
						'secondary' => $evidence['secondary'],
					),
					JSON_PRETTY_PRINT
				)
			);
		}
	}

	WP_CLI::add_command( 'papelito payment-gateways', 'Papelito_Payment_Gateways_CLI' );
}
