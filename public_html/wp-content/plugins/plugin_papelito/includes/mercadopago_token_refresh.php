<?php
/**
 * Renovação antecipada dos tokens OAuth do Mercado Pago.
 *
 * A validade vem do `expires_in` gravado na conta, nunca de um prazo fixo no código. A renovação
 * acontece dentro da janela de antecedência, uma conta por vez sob trava nomeada, e grava o novo
 * par access/refresh por compare-and-set — o refresh token é rotativo, então perder a corrida não
 * pode sobrescrever o par vencedor.
 *
 * A regra de ouro: recusa do gateway (`invalid_grant`, 401) significa reconectar; timeout, 429 e
 * 5xx são `degraded` e não tiram a prova de aptidão. Conta desconectada ou de configuração
 * obsoleta nunca é reativada por uma resposta de renovação.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_MERCADOPAGO_REFRESH_HOOK   = 'papelito_mercadopago_refresh_tokens';
const PAPELITO_MERCADOPAGO_REFRESH_WINDOW = 30;

/**
 * Diz se a conta está na janela de renovação.
 *
 * @param array<string,mixed> $account Conta.
 * @param int                 $now     Instante atual.
 * @return bool
 */
function papelito_mercadopago_token_needs_refresh( array $account, int $now ): bool {
	$expires = strtotime( (string) ( $account['token_expires_at'] ?? '' ) . ' UTC' );

	if ( false === $expires || '' === (string) ( $account['token_expires_at'] ?? '' ) ) {
		return false;
	}

	return $expires <= $now + PAPELITO_MERCADOPAGO_REFRESH_WINDOW * DAY_IN_SECONDS;
}

/**
 * Renova uma conta e devolve o desfecho.
 *
 * `refreshed`, `busy` (outro worker com a trava), `stale` (outro worker renovou antes),
 * `revoked`, `degraded`, `store_failed` (tokens novos não gravados) ou `skipped`.
 *
 * @param int $account_id Conta.
 * @return string
 */
function papelito_mercadopago_refresh_account( int $account_id ): string {
	$lock = 'refresh:account:' . $account_id;

	if ( ! papelito_payment_attempt_acquire_lock( $lock, 0 ) ) {
		return 'busy';
	}

	try {
		return papelito_mercadopago_refresh_account_locked( $account_id );
	} finally {
		papelito_payment_attempt_release_lock( $lock );
	}
}

/**
 * Corpo da renovação, já sob a trava da conta.
 *
 * @param int $account_id Conta.
 * @return string
 */
function papelito_mercadopago_refresh_account_locked( int $account_id ): string {
	$account = papelito_vendor_payment_account_find( $account_id );

	if ( ! is_array( $account ) || 'active' !== $account['status'] || papelito_mercadopago_configuration_version() !== (string) $account['configuration_version'] ) {
		return 'skipped';
	}

	$tokens = papelito_vendor_payment_account_tokens( $account );
	if ( is_wp_error( $tokens ) || '' === $tokens['refresh_token'] ) {
		papelito_mercadopago_refresh_revoke( $account, 'refresh_token_missing' );
		return 'revoked';
	}

	$response = papelito_mercadopago_oauth_token_request(
		array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $tokens['refresh_token'],
		)
	);

	if ( is_wp_error( $response ) ) {
		return papelito_mercadopago_refresh_failure( $account, $response );
	}

	$stored = papelito_vendor_payment_account_rotate_tokens( $account, $response );

	if ( is_wp_error( $stored ) ) {
		papelito_vendor_payment_account_update_health(
			$account_id,
			array(
				'health_status' => 'degraded',
				'health_reason' => 'refresh_store_failed',
			)
		);
		error_log( sprintf( '[papelito_mercadopago] refresh_store_failed account=%d', $account_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return 'store_failed';
	}

	if ( false === $stored ) {
		return 'stale';
	}

	papelito_vendor_payment_account_update_health(
		$account_id,
		array(
			'health_status' => 'ok',
			'health_reason' => '',
			'success'       => true,
		)
	);
	do_action( 'papelito_payment_gateway_account_changed', (int) $account['vendor_id'], PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO );

	return 'refreshed';
}

/**
 * Trata a falha da renovação: recusa revoga, indisponibilidade degrada.
 *
 * @param array<string,mixed> $account Conta.
 * @param WP_Error            $error   Erro categorizado do cliente.
 * @return string
 */
function papelito_mercadopago_refresh_failure( array $account, WP_Error $error ): string {
	$data     = (array) $error->get_error_data();
	$category = (string) ( $data['category'] ?? '' );

	if ( in_array( $category, array( 'authentication', 'authorization', 'validation' ), true ) ) {
		$remote_code = (string) ( $data['remote_code'] ?? '' );
		papelito_mercadopago_refresh_revoke( $account, '' !== $remote_code ? $remote_code : $category );
		return 'revoked';
	}

	papelito_vendor_payment_account_update_health(
		(int) $account['id'],
		array(
			'health_status' => 'degraded',
			'health_reason' => 'refresh_unavailable',
		)
	);
	do_action( 'papelito_payment_gateway_account_changed', (int) $account['vendor_id'], PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO );

	return 'degraded';
}

/**
 * Marca a conta como revogada e avisa a saúde do gateway.
 *
 * @param array<string,mixed> $account Conta.
 * @param string              $reason  Motivo.
 * @return void
 */
function papelito_mercadopago_refresh_revoke( array $account, string $reason ): void {
	papelito_payment_observe_metric( 'connection_failed' );
	papelito_vendor_payment_account_mark_revoked( (int) $account['id'], $reason );
	do_action( 'papelito_payment_gateway_account_changed', (int) $account['vendor_id'], PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO );
}

/**
 * Cron: renova contas na janela de vencimento, em lote limitado.
 *
 * @return void
 */
function papelito_mercadopago_refresh_due_accounts(): void {
	if ( ! function_exists( 'papelito_mercadopago_is_configured' ) || ! papelito_mercadopago_is_configured() ) {
		return;
	}

	$now = time();

	foreach ( papelito_vendor_payment_accounts_expiring( PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO, $now + PAPELITO_MERCADOPAGO_REFRESH_WINDOW * DAY_IN_SECONDS, 20 ) as $account ) {
		if ( papelito_mercadopago_token_needs_refresh( $account, $now ) ) {
			papelito_mercadopago_refresh_account( (int) $account['id'] );
		}
	}
}

add_action( PAPELITO_MERCADOPAGO_REFRESH_HOOK, 'papelito_mercadopago_refresh_due_accounts' );

/**
 * Agenda a renovação diária.
 *
 * @return void
 */
function papelito_mercadopago_schedule_refresh(): void {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( PAPELITO_MERCADOPAGO_REFRESH_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', PAPELITO_MERCADOPAGO_REFRESH_HOOK );
	}
}

add_action( 'init', 'papelito_mercadopago_schedule_refresh' );

/**
 * Testa a conta Mercado Pago de verdade e registra o resultado como verificação.
 *
 * Consulta `/users/me` com o token da própria conta, sem cobrar nada. Sucesso marca a última
 * verificação e o último sucesso; falha registra `degraded`, que não tira a loja da vitrine, mas
 * aparece no painel.
 *
 * @param array<string,mixed> $account Conta vigente.
 * @return bool Se a conta respondeu.
 */
function papelito_mercadopago_probe_account( array $account ): bool {
	$tokens = papelito_vendor_payment_account_tokens( $account );
	if ( is_wp_error( $tokens ) ) {
		return false;
	}

	$owner = papelito_mercadopago_request( 'GET', '/users/me', array( 'token' => (string) $tokens['access_token'] ) );
	$ok    = ! is_wp_error( $owner );

	papelito_vendor_payment_account_update_health(
		(int) $account['id'],
		$ok
			? array(
				'health_status' => 'ok',
				'health_reason' => '',
				'success'       => true,
			)
			: array(
				'health_status' => 'degraded',
				'health_reason' => 'probe_failed',
			)
	);

	return $ok;
}
