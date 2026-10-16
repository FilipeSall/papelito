<?php
/**
 * Política de fallback entre gateways: quando é seguro tentar a alternativa.
 *
 * Regras puras, sem banco nem rede, compartilhadas pelo resolver, pelo checkout e pela
 * continuação. Só falha comprovadamente anterior à criação remota (conexão, rate limit,
 * credencial, configuração) libera a alternativa; resultado incerto, recusa de risco, regulatória
 * ou de payload nunca liberam — trocar de gateway ali arriscaria cobrar duas vezes ou contornar
 * uma recusa legítima.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

/**
 * Diz se o erro do gateway prova que nada foi criado e permite tentar a alternativa.
 *
 * @param string   $provider Gateway que falhou.
 * @param WP_Error $error    Erro.
 * @return bool
 */
function papelito_payment_gateway_error_allows_fallback( string $provider, WP_Error $error ): bool {
	if ( PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO !== $provider ) {
		return false;
	}

	$data = (array) $error->get_error_data();

	return in_array( (string) ( $data['category'] ?? '' ), papelito_payment_gateway_pre_creation_failures(), true );
}

/**
 * Categorias de falha que provam que nada foi criado no gateway e liberam a alternativa.
 *
 * @return array<int,string>
 */
function papelito_payment_gateway_pre_creation_failures(): array {
	return array( 'unavailable', 'rate_limit', 'authentication', 'configuration' );
}

/**
 * Diz se a tentativa existente permite trocar de gateway.
 *
 * Só tentativa sem emissão (`not_started`, `awaiting_payment_input`) ou falha sem criação remota
 * comprovada; qualquer cobrança emitida, recusada ou de resultado incerto prende o gateway.
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return bool
 */
function papelito_payment_gateway_attempt_allows_switch( array $attempt ): bool {
	$state = (string) ( $attempt['state'] ?? '' );

	if ( in_array( $state, array( 'not_started', 'awaiting_payment_input' ), true ) ) {
		return true;
	}

	return 'failed' === $state
		&& '' === (string) ( $attempt['remote_id'] ?? '' )
		&& in_array( (string) ( $attempt['last_error_code'] ?? '' ), papelito_payment_gateway_pre_creation_failures(), true );
}
