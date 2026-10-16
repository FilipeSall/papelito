<?php
/**
 * Saúde independente de cada gateway do vendor (`GatewayStatus`).
 *
 * Separa o que a tela e o resolver precisam distinguir: conexão, KYC, restrição comercial local,
 * saúde operacional e a pergunta final — `canReceive`. Tudo sai de estado persistido (conta OAuth,
 * recipient Pagar.me, política CNAE); nenhuma chamada externa acontece aqui, para que cobertura e
 * vitrine leiam em lote.
 *
 * A regra de ouro: estado que não comprova aptidão nunca vira `ready`. OAuth conectado sem
 * verificação é `verification_pending`; falha temporária de reconsulta é `degraded` e não apaga a
 * prova anterior; token revogado, CNPJ trocado, configuração obsoleta ou bloqueio confirmado
 * retiram `canReceive`. O estado de um gateway nunca contamina o do outro.
 *
 * O que este módulo não faz: não renova token (`mercadopago_token_refresh.php`), não escolhe
 * gateway (resolver) e não expõe erro bruto, token ou ID interno de conta.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_PAYMENT_GATEWAY_HEALTH_SNAPSHOT_META = 'papelito_payment_gateway_health_snapshot';

/**
 * Estrutura base do GatewayStatus.
 *
 * @param string              $provider Gateway.
 * @param string              $state    Estado de apresentação.
 * @param array<string,mixed> $fields   Campos que sobrescrevem a base.
 * @return array<string,mixed>
 */
function papelito_payment_gateway_status_shape( string $provider, string $state, array $fields = array() ): array {
	return array_merge(
		array(
			'provider'             => $provider,
			'state'                => $state,
			'connectionStatus'     => 'not_connected',
			'kycStatus'            => 'unknown',
			'restrictionCode'      => '',
			'healthStatus'         => '',
			'canReceive'           => false,
			'reasonCodes'          => array(),
			'pendingFields'        => array(),
			'cnaeCodes'            => array(),
			'lastCheckedAt'        => '',
			'lastSuccessAt'        => '',
			'configurationVersion' => '',
		),
		$fields
	);
}

/**
 * GatewayStatus de um gateway do vendor.
 *
 * @param int    $vendor_id Vendor.
 * @param string $provider  Gateway.
 * @return array<string,mixed>
 */
function papelito_payment_gateway_status( int $vendor_id, string $provider ): array {
	return PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO === $provider
		? papelito_payment_gateway_mercadopago_status( $vendor_id )
		: papelito_payment_gateway_pagarme_status( $vendor_id );
}

/**
 * GatewayStatus dos dois gateways, na ordem de `papelito_payment_providers()`.
 *
 * @param int $vendor_id Vendor.
 * @return array<int,array<string,mixed>>
 */
function papelito_payment_gateway_statuses( int $vendor_id ): array {
	return array_map(
		static fn( string $provider ): array => papelito_payment_gateway_status( $vendor_id, $provider ),
		papelito_payment_providers()
	);
}

/**
 * Diz se o gateway pode receber nova cobrança do vendor agora.
 *
 * @param int    $vendor_id Vendor.
 * @param string $provider  Gateway.
 * @return bool
 */
function papelito_payment_gateway_can_receive( int $vendor_id, string $provider ): bool {
	return (bool) papelito_payment_gateway_status( $vendor_id, $provider )['canReceive'];
}

/**
 * GatewayStatus da Pagar.me: política CNAE primeiro, recipient depois.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>
 */
function papelito_payment_gateway_pagarme_status( int $vendor_id ): array {
	$provider = PAPELITO_PAYMENT_PROVIDER_PAGARME;
	$policy   = function_exists( 'papelito_vendor_pagarme_policy' ) ? papelito_vendor_pagarme_policy( $vendor_id ) : array(
		'status' => 'allowed',
		'codes'  => array(),
		'reason' => '',
	);

	if ( 'blocked_cnae' === $policy['status'] ) {
		return papelito_payment_gateway_status_shape(
			$provider,
			'blocked_cnae',
			array(
				'restrictionCode' => 'blocked_cnae',
				'reasonCodes'     => array( 'cnae_restricted' ),
				'cnaeCodes'       => $policy['codes'],
			)
		);
	}

	$recipient = function_exists( 'papelito_pagarme_get_vendor_recipient_state' ) ? papelito_pagarme_get_vendor_recipient_state( $vendor_id ) : array();
	$base      = papelito_payment_gateway_pagarme_recipient_status( $vendor_id, $recipient );

	if ( 'verification_pending' === $policy['status'] ) {
		$base['state']         = 'verification_pending';
		$base['canReceive']    = false;
		$base['reasonCodes'][] = 'cnae_verification_pending';
	}

	return $base;
}

/**
 * Estado derivado do recipient Pagar.me persistido.
 *
 * @param int                  $vendor_id Vendor.
 * @param array<string,string> $recipient Estado do recipient.
 * @return array<string,mixed>
 */
function papelito_payment_gateway_pagarme_recipient_status( int $vendor_id, array $recipient ): array {
	$provider  = PAPELITO_PAYMENT_PROVIDER_PAGARME;
	$status    = (string) ( $recipient['status'] ?? '' );
	$has_error = '' !== (string) ( $recipient['last_error'] ?? '' );
	$common    = array(
		'connectionStatus' => '' !== (string) ( $recipient['recipient_id'] ?? '' ) ? 'connected' : 'not_connected',
		'kycStatus'        => '' !== (string) ( $recipient['kyc_status'] ?? '' ) ? sanitize_key( (string) $recipient['kyc_status'] ) : 'unknown',
		'lastCheckedAt'    => (string) ( $recipient['last_sync_at'] ?? '' ),
	);

	if ( 'active' === $status ) {
		return papelito_payment_gateway_status_shape(
			$provider,
			$has_error ? 'degraded' : 'ready',
			array_merge(
				$common,
				array(
					'canReceive'    => true,
					'healthStatus'  => $has_error ? 'degraded' : 'ok',
					'reasonCodes'   => $has_error ? array( 'last_sync_failed' ) : array(),
					'lastSuccessAt' => (string) ( $recipient['last_sync_at'] ?? '' ),
				)
			)
		);
	}

	if ( in_array( $status, array( 'registration', 'affiliation' ), true ) ) {
		return papelito_payment_gateway_status_shape( $provider, 'kyc_pending', array_merge( $common, array( 'reasonCodes' => array( 'in_review' ) ) ) );
	}

	if ( in_array( $status, array( 'refused', 'suspended', 'blocked', 'inactive' ), true ) ) {
		return papelito_payment_gateway_status_shape( $provider, 'unavailable', array_merge( $common, array( 'reasonCodes' => array( 'recipient_' . $status ) ) ) );
	}

	$pending = function_exists( 'papelito_pagarme_financial_profile_pending' ) ? papelito_pagarme_financial_profile_pending( $vendor_id )['pendingFields'] : array();

	return papelito_payment_gateway_status_shape(
		$provider,
		'unconfigured',
		array_merge(
			$common,
			array(
				'pendingFields' => $pending,
				'reasonCodes'   => array( $has_error ? 'sync_error' : 'never_synced' ),
			)
		)
	);
}

/**
 * GatewayStatus do Mercado Pago a partir da conta OAuth persistida.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>
 */
function papelito_payment_gateway_mercadopago_status( int $vendor_id ): array {
	$provider = PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO;

	if ( ! papelito_mercadopago_is_configured() ) {
		return papelito_payment_gateway_status_shape( $provider, 'unavailable', array( 'reasonCodes' => array( 'gateway_not_configured' ) ) );
	}

	$account = papelito_vendor_payment_account_latest( $vendor_id, $provider, papelito_mercadopago_environment() );

	if ( ! is_array( $account ) || 'disconnected' === $account['status'] || 'superseded' === $account['status'] ) {
		return papelito_payment_gateway_status_shape( $provider, 'unconfigured', array( 'reasonCodes' => array( 'not_connected' ) ) );
	}

	$common = array(
		'connectionStatus'     => 'active' === $account['status'] ? 'connected' : 'reauthorization_required',
		'kycStatus'            => 'not_exposed',
		'healthStatus'         => (string) ( $account['health_status'] ?? '' ),
		'lastCheckedAt'        => (string) ( $account['health_checked_at'] ?? '' ),
		'lastSuccessAt'        => (string) ( $account['health_success_at'] ?? '' ),
		'configurationVersion' => (string) ( $account['configuration_version'] ?? '' ),
	);

	$blocker = papelito_payment_gateway_mercadopago_blocker( $vendor_id, $account );

	if ( null !== $blocker ) {
		return papelito_payment_gateway_status_shape( $provider, $blocker['state'], array_merge( $common, array( 'reasonCodes' => array( $blocker['reason'] ) ) ) );
	}

	$degraded = 'degraded' === ( $account['health_status'] ?? '' );

	return papelito_payment_gateway_status_shape(
		$provider,
		$degraded ? 'degraded' : 'ready',
		array_merge(
			$common,
			array(
				'canReceive'  => true,
				'reasonCodes' => $degraded ? array( (string) $account['health_reason'] ) : array(),
			)
		)
	);
}

/**
 * Primeiro motivo que impede a conta Mercado Pago de receber, ou nulo.
 *
 * @param int                 $vendor_id Vendor.
 * @param array<string,mixed> $account   Conta mais recente.
 * @return array{state:string,reason:string}|null
 */
function papelito_payment_gateway_mercadopago_blocker( int $vendor_id, array $account ): ?array {
	$expires = strtotime( (string) ( $account['token_expires_at'] ?? '' ) . ' UTC' );
	$checks  = array(
		array( 'active' !== $account['status'] || '' === (string) ( $account['access_token_envelope'] ?? '' ), 'connection_pending', 'reauthorization_required' ),
		array( false !== $expires && $expires > 0 && $expires < time(), 'connection_pending', 'token_expired' ),
		array( papelito_mercadopago_configuration_version() !== (string) $account['configuration_version'], 'connection_pending', 'configuration_changed' ),
		array( ! hash_equals( (string) $account['cnpj_fingerprint'], papelito_mercadopago_oauth_cnpj_fingerprint( $vendor_id ) ), 'verification_pending', 'cnpj_changed' ),
		array( 'mismatch' === $account['identity_status'], 'unavailable', 'identity_mismatch' ),
		array( 'verified' !== $account['identity_status'], 'verification_pending', 'identity_verification_pending' ),
		array( 'kyc_pending' === ( $account['health_status'] ?? '' ), 'kyc_pending', (string) ( $account['health_reason'] ?? 'regulatory_pending' ) ),
	);

	foreach ( $checks as $check ) {
		if ( $check[0] ) {
			return array(
				'state'  => $check[1],
				'reason' => $check[2],
			);
		}
	}

	return null;
}

/**
 * Reavalia e grava a aptidão do gateway, invalidando a cobertura só quando ela muda.
 *
 * Chamada por conexão, desconexão, renovação, webhook e reconsulta — nunca por render.
 *
 * @param int    $vendor_id Vendor.
 * @param string $provider  Gateway.
 * @return array<string,mixed> GatewayStatus atual.
 */
function papelito_payment_gateway_health_record( int $vendor_id, string $provider ): array {
	$status   = papelito_payment_gateway_status( $vendor_id, $provider );
	$snapshot = get_user_meta( $vendor_id, PAPELITO_PAYMENT_GATEWAY_HEALTH_SNAPSHOT_META, true );
	$snapshot = is_array( $snapshot ) ? $snapshot : array();
	$previous = $snapshot[ $provider ] ?? null;
	$current  = array(
		'canReceive' => (bool) $status['canReceive'],
		'state'      => (string) $status['state'],
	);

	if ( $previous !== $current ) {
		$snapshot[ $provider ] = $current;
		papelito_payment_gateway_store_snapshot( $vendor_id, $snapshot );

		if ( ( $previous['canReceive'] ?? null ) !== $current['canReceive'] && function_exists( 'papelito_coverage_bump_cache_version' ) ) {
			papelito_coverage_bump_cache_version();
		}
	}

	return $status;
}

/**
 * Grava o snapshot de aptidão por gateway.
 *
 * @param int                 $vendor_id Vendor.
 * @param array<string,mixed> $snapshot  Aptidão por provider.
 * @return void
 */
function papelito_payment_gateway_store_snapshot( int $vendor_id, array $snapshot ): void {
	if ( function_exists( 'update_user_meta' ) ) {
		update_user_meta( $vendor_id, PAPELITO_PAYMENT_GATEWAY_HEALTH_SNAPSHOT_META, $snapshot );
		return;
	}

	add_user_meta( $vendor_id, PAPELITO_PAYMENT_GATEWAY_HEALTH_SNAPSHOT_META, $snapshot );
}

/**
 * Listener: conta de gateway mudou (conexão, desconexão, revogação, verificação).
 *
 * @param mixed $vendor_id Vendor.
 * @param mixed $provider  Gateway.
 * @return void
 */
function papelito_payment_gateway_on_account_changed( mixed $vendor_id, mixed $provider ): void {
	if ( papelito_payment_provider_is_valid( (string) $provider ) ) {
		papelito_payment_gateway_health_record( (int) $vendor_id, (string) $provider );
	}
}

add_action( 'papelito_payment_gateway_account_changed', 'papelito_payment_gateway_on_account_changed', 10, 2 );
