<?php
/**
 * Escolha de gateway do vendor e resolução do gateway efetivo de cada compra.
 *
 * A escolha é exclusiva: o vendor recebe por um gateway só, e o outro nunca é usado — nem como
 * alternativa automática. Gateway escolhido inapto tira a loja do checkout até o vendor resolver
 * a pendência ou escolher o outro. Enquanto o vendor não escolhe, vale o padrão da coorte:
 * Mercado Pago se estiver apto, senão Pagar.me se estiver apta.
 *
 * A regra de ouro: tentativa em criação, `unknown`, pendente, paga ou com cobrança recusada nunca
 * muda de gateway, e falha do gateway escolhido antes da emissão encerra a compra em vez de
 * cobrar pelo outro.
 *
 * Vendor legado segue a Pagar.me direta como sempre; vendor marcado fora da coorte fica sem novas
 * compras. Nada aqui confia em gateway escolhido pelo navegador.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_PAYMENT_PREFERENCE_META = 'papelito_vendor_preferred_payment_provider';

/**
 * Gateway escolhido pelo vendor, ou o padrão da coorte quando ele ainda não escolheu.
 *
 * O padrão depende da aptidão atual (`explicit: false`): Mercado Pago apto, senão Pagar.me apta,
 * senão Mercado Pago. Só a escolha gravada é estável.
 *
 * @param int                                    $vendor_id Vendor.
 * @param array<string,array<string,mixed>>|null $statuses  Status por provider já lidos, para não reler.
 * @return array{preferredProvider:string,version:int,explicit:bool}
 */
function papelito_vendor_payment_preference( int $vendor_id, ?array $statuses = null ): array {
	$stored = get_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_PREFERENCE_META, true );

	if ( is_array( $stored ) && papelito_payment_provider_is_valid( (string) ( $stored['provider'] ?? '' ) ) ) {
		return array(
			'preferredProvider' => (string) $stored['provider'],
			'version'           => (int) ( $stored['version'] ?? 1 ),
			'explicit'          => true,
		);
	}

	return array(
		'preferredProvider' => papelito_vendor_payment_default_provider( $statuses ?? papelito_payment_gateway_statuses_by_provider( $vendor_id ) ),
		'version'           => 0,
		'explicit'          => false,
	);
}

/**
 * Padrão da coorte para quem ainda não escolheu: o primeiro gateway apto, Mercado Pago antes.
 *
 * @param array<string,array<string,mixed>> $statuses Status por provider.
 * @return string
 */
function papelito_vendor_payment_default_provider( array $statuses ): string {
	foreach ( array( PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO, PAPELITO_PAYMENT_PROVIDER_PAGARME ) as $provider ) {
		if ( ! empty( $statuses[ $provider ]['canReceive'] ) ) {
			return $provider;
		}
	}

	return PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO;
}

/**
 * Status dos gateways do vendor indexados pelo provider.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,array<string,mixed>>
 */
function papelito_payment_gateway_statuses_by_provider( int $vendor_id ): array {
	$indexed = array();

	foreach ( papelito_payment_gateway_statuses( $vendor_id ) as $status ) {
		$indexed[ (string) $status['provider'] ] = $status;
	}

	return $indexed;
}

/**
 * Salva a escolha exclusiva, exigindo gateway apto e a versão lida pelo cliente.
 *
 * Não altera pedidos existentes, não aprova KYC e não limpa erros. Trocar de gateway muda a
 * elegibilidade da loja, então invalida o cache de cobertura.
 *
 * @param int    $vendor_id        Vendor.
 * @param string $provider         Gateway escolhido.
 * @param int    $expected_version Versão lida.
 * @return array{preferredProvider:string,version:int,explicit:bool}|WP_Error
 */
function papelito_vendor_payment_preference_save( int $vendor_id, string $provider, int $expected_version ) {
	if ( ! papelito_payment_provider_is_valid( $provider ) ) {
		return new WP_Error( 'papelito_payment_provider_unknown', 'Gateway inválido.', array( 'status' => 422 ) );
	}

	$current = papelito_vendor_payment_preference( $vendor_id );
	if ( $current['version'] !== $expected_version ) {
		return new WP_Error( 'papelito_payment_preference_version_conflict', 'A preferência foi alterada em outra sessão. Atualize a página.', array( 'status' => 409 ) );
	}

	$status = papelito_payment_gateway_status( $vendor_id, $provider );
	if ( 'blocked_cnae' === $status['state'] ) {
		return new WP_Error( 'papelito_payment_gateway_blocked_cnae', 'Pagar.me indisponível para este CNAE.', array( 'status' => 422 ) );
	}

	if ( empty( $status['canReceive'] ) ) {
		return new WP_Error(
			'papelito_payment_gateway_not_eligible',
			'Este gateway ainda não está apto a receber pagamentos da loja.',
			array(
				'status'      => 422,
				'reasonCodes' => $status['reasonCodes'],
			)
		);
	}

	$record   = array(
		'provider'   => $provider,
		'version'    => $expected_version + 1,
		'updated_at' => current_time( 'mysql', true ),
	);
	$previous = get_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_PREFERENCE_META, true );
	$saved    = is_array( $previous )
		? update_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_PREFERENCE_META, $record, $previous )
		: add_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_PREFERENCE_META, $record, true );

	if ( ! $saved ) {
		return new WP_Error( 'papelito_payment_preference_version_conflict', 'A preferência foi alterada em outra sessão. Atualize a página.', array( 'status' => 409 ) );
	}

	if ( $current['preferredProvider'] !== $provider && function_exists( 'papelito_coverage_bump_cache_version' ) ) {
		papelito_coverage_bump_cache_version();
	}

	return papelito_vendor_payment_preference( $vendor_id );
}

/**
 * Fluxo de pagamento de cada gateway.
 *
 * @param string $provider Gateway.
 * @return string
 */
function papelito_payment_gateway_flow( string $provider ): string {
	return PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO === $provider ? PAPELITO_PAYMENT_FLOW_REDIRECT : PAPELITO_PAYMENT_FLOW_DIRECT;
}

/**
 * Resolve o `PaymentContext` da compra do vendor, sem cobrar nada.
 *
 * Só o gateway escolhido é usado. Opção `exclude`: gateways que falharam comprovadamente antes da
 * emissão nesta tentativa — se o escolhido está entre eles, não há gateway para a compra.
 *
 * @param int                 $vendor_id Vendor autoritativo (do carrinho, nunca do navegador).
 * @param array<string,mixed> $options   Opções.
 * @return array<string,mixed>|WP_Error
 */
function papelito_payment_gateway_resolve( int $vendor_id, array $options = array() ) {
	$mode = papelito_vendor_payment_mode( $vendor_id );

	if ( PAPELITO_VENDOR_PAYMENT_MODE_LEGACY === $mode ) {
		return papelito_payment_gateway_context( PAPELITO_PAYMENT_PROVIDER_PAGARME, '', 'legacy', true );
	}

	if ( PAPELITO_VENDOR_PAYMENT_MODE_SUSPENDED === $mode ) {
		return new WP_Error( 'papelito_payment_vendor_suspended', 'Esta loja está temporariamente sem pagamentos disponíveis. Tente outra loja ou volte mais tarde.', array( 'status' => 409 ) );
	}

	$statuses   = papelito_payment_gateway_statuses_by_provider( $vendor_id );
	$preference = papelito_vendor_payment_preference( $vendor_id, $statuses );
	$excluded   = array_map( 'strval', (array) ( $options['exclude'] ?? array() ) );
	$selected   = $preference['preferredProvider'];

	if ( papelito_payment_gateway_usable( $statuses, $selected, $excluded ) ) {
		return papelito_payment_gateway_context( $selected, '', papelito_payment_gateway_configuration_version( $preference, $statuses ), false );
	}

	return new WP_Error( 'papelito_payment_no_gateway_available', 'Nenhuma forma de pagamento está disponível para esta loja agora. Tente novamente mais tarde.', array( 'status' => 409 ) );
}

/**
 * Diz se o gateway pode ser usado nesta resolução.
 *
 * @param array<string,array<string,mixed>> $statuses Status por provider.
 * @param string                            $provider Gateway.
 * @param array<int,string>                 $excluded Excluídos nesta tentativa.
 * @return bool
 */
function papelito_payment_gateway_usable( array $statuses, string $provider, array $excluded ): bool {
	return ! in_array( $provider, $excluded, true ) && ! empty( $statuses[ $provider ]['canReceive'] );
}

/**
 * Monta o PaymentContext.
 *
 * @param string $provider Gateway efetivo.
 * @param string $reason   Motivo do fallback, vazio quando é o preferido.
 * @param string $version  Versão da configuração usada na decisão.
 * @param bool   $legacy   Se é o fluxo legado.
 * @return array<string,mixed>
 */
function papelito_payment_gateway_context( string $provider, string $reason, string $version, bool $legacy ): array {
	return array(
		'provider'             => $provider,
		'flow'                 => papelito_payment_gateway_flow( $provider ),
		'configurationVersion' => $version,
		'fallbackReason'       => $reason,
		'legacy'               => $legacy,
	);
}

/**
 * Versão da configuração que embasou a decisão: muda com preferência ou aptidão.
 *
 * @param array<string,mixed>               $preference Preferência.
 * @param array<string,array<string,mixed>> $statuses   Status por provider.
 * @return string
 */
function papelito_payment_gateway_configuration_version( array $preference, array $statuses ): string {
	$parts = array( $preference['preferredProvider'], (string) $preference['version'] );

	foreach ( papelito_payment_providers() as $provider ) {
		$parts[] = $provider . ':' . ( empty( $statuses[ $provider ]['canReceive'] ) ? '0' : '1' ) . ':' . ( $statuses[ $provider ]['state'] ?? '' );
	}

	return substr( hash( 'sha256', implode( '|', $parts ) ), 0, 16 );
}
