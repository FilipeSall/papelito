<?php
/**
 * `POST /mercadopago/webhook`: notificação server-to-server do Mercado Pago.
 *
 * Assinatura oficial (`x-signature` = HMAC-SHA256 do manifesto `id:{data.id};request-id:{...};ts:{...};`
 * com o segredo da aplicação) e, só então, consulta autenticada do pagamento com a conta da
 * tentativa apontada pelo `attemptRef` da notification URL. O corpo da notificação nunca é
 * confiado; o attemptRef só escolhe a conta candidata.
 *
 * A regra de ouro: notificação sem assinatura válida responde 401 e não muda nada — só agenda a
 * reconciliação puxada daquela tentativa (a documentação não garante assinatura na URL por
 * preferência). Erro transitório na consulta responde 500 para o Mercado Pago reenviar; o
 * processamento é idempotente, então reentrega não repete efeito.
 *
 * O que este módulo não faz: não consulta contas de todos os vendors, não usa token global e não
 * loga a query (que carrega o attemptRef).
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

/**
 * Manifesto assinado, omitindo os pares ausentes.
 *
 * @param string $data_id    `data.id` da query.
 * @param string $request_id Cabeçalho `x-request-id`.
 * @param string $ts         Timestamp do `x-signature`.
 * @return string
 */
function papelito_mercadopago_webhook_manifest( string $data_id, string $request_id, string $ts ): string {
	$manifest = '';

	if ( '' !== $data_id ) {
		$manifest .= 'id:' . strtolower( $data_id ) . ';';
	}
	if ( '' !== $request_id ) {
		$manifest .= 'request-id:' . $request_id . ';';
	}
	if ( '' !== $ts ) {
		$manifest .= 'ts:' . $ts . ';';
	}

	return $manifest;
}

/**
 * Decompõe `ts=...,v1=...`.
 *
 * @param string $header Cabeçalho `x-signature`.
 * @return array<string,string>
 */
function papelito_mercadopago_webhook_parse_signature( string $header ): array {
	$parts = array();

	foreach ( explode( ',', $header ) as $piece ) {
		$pair = explode( '=', trim( $piece ), 2 );
		if ( 2 === count( $pair ) ) {
			$parts[ trim( $pair[0] ) ] = trim( $pair[1] );
		}
	}

	return $parts;
}

/**
 * `data.id` da notificação: query primeiro (o PHP converte `data.id` em `data_id`).
 *
 * @param WP_REST_Request $request Requisição.
 * @return string
 */
function papelito_mercadopago_webhook_data_id( WP_REST_Request $request ): string {
	$query = (array) $request->get_query_params();
	$value = (string) ( $query['data_id'] ?? $query['data.id'] ?? '' );

	return 1 === preg_match( '/^[A-Za-z0-9]{1,64}$/', $value ) ? $value : '';
}

/**
 * Confere a assinatura com o segredo da aplicação, em tempo constante.
 *
 * @param WP_REST_Request $request Requisição.
 * @return bool
 */
function papelito_mercadopago_webhook_signature_valid( WP_REST_Request $request ): bool {
	$secret    = papelito_mercadopago_config()['webhook_secret'];
	$signature = papelito_mercadopago_webhook_parse_signature( (string) $request->get_header( 'x-signature' ) );

	if ( '' === $secret || '' === ( $signature['ts'] ?? '' ) || '' === ( $signature['v1'] ?? '' ) ) {
		return false;
	}

	$manifest = papelito_mercadopago_webhook_manifest( papelito_mercadopago_webhook_data_id( $request ), (string) $request->get_header( 'x-request-id' ), $signature['ts'] );

	return hash_equals( hash_hmac( 'sha256', $manifest, $secret ), strtolower( $signature['v1'] ) );
}

/**
 * Tentativa candidata pelo attemptRef da notification URL.
 *
 * @param WP_REST_Request $request Requisição.
 * @return array<string,mixed>|null
 */
function papelito_mercadopago_webhook_attempt( WP_REST_Request $request ): ?array {
	$ref     = (string) ( ( (array) $request->get_query_params() )['attempt'] ?? '' );
	$attempt = papelito_payment_attempt_find_by_ref( $ref );

	return is_array( $attempt ) && PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO === $attempt['provider'] ? $attempt : null;
}

/**
 * Agenda reconciliação puxada imediata da tentativa ainda em aberto.
 *
 * @param array<string,mixed>|null $attempt Tentativa candidata.
 * @return void
 */
function papelito_mercadopago_webhook_schedule( ?array $attempt ): void {
	if ( is_array( $attempt ) && in_array( $attempt['state'], array( 'pending', 'unknown', 'expired' ), true ) ) {
		papelito_payment_attempt_update( $attempt, array( 'next_reconcile_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}
}

/**
 * Processa a notificação.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response
 */
function papelito_mercadopago_webhook_handle( WP_REST_Request $request ): WP_REST_Response {
	if ( function_exists( 'papelito_rate_limit' ) && ! papelito_rate_limit( 'mercadopago_webhook', papelito_rate_limit_identity(), 240, 60 ) ) {
		return new WP_REST_Response( array( 'received' => false ), 429 );
	}

	$attempt = papelito_mercadopago_webhook_attempt( $request );

	if ( ! papelito_mercadopago_webhook_signature_valid( $request ) ) {
		papelito_payment_observe_metric( 'webhook_rejected' );
		papelito_mercadopago_webhook_schedule( $attempt );
		return new WP_REST_Response( array( 'received' => false ), 401 );
	}

	$query   = (array) $request->get_query_params();
	$body    = (array) $request->get_json_params();
	$type    = sanitize_key( (string) ( $query['type'] ?? $body['type'] ?? '' ) );
	$data_id = papelito_mercadopago_webhook_data_id( $request );

	if ( 'payment' !== $type || '' === $data_id || null === $attempt ) {
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	$lock = 'attempt:' . (int) $attempt['id'];
	if ( ! papelito_payment_attempt_acquire_lock( $lock, 3 ) ) {
		papelito_mercadopago_webhook_schedule( $attempt );
		return new WP_REST_Response( array( 'received' => false ), 500 );
	}

	try {
		$result = papelito_mercadopago_reconcile_payment_id( papelito_payment_attempt_find( (int) $attempt['id'] ) ?? $attempt, $data_id );
	} finally {
		papelito_payment_attempt_release_lock( $lock );
	}

	if ( is_wp_error( $result ) && in_array( (string) ( (array) $result->get_error_data() )['category'] ?? '', array( 'unavailable', 'rate_limit', 'unknown_outcome' ), true ) ) {
		papelito_mercadopago_webhook_schedule( $attempt );
		return new WP_REST_Response( array( 'received' => false ), 500 );
	}

	return new WP_REST_Response( array( 'received' => true ), 200 );
}

/**
 * Registra a rota pública do webhook (autenticada pela assinatura).
 *
 * @return void
 */
function papelito_mercadopago_webhook_register_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/mercadopago/webhook',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'callback'            => 'papelito_mercadopago_webhook_handle',
		)
	);
}

add_action( 'rest_api_init', 'papelito_mercadopago_webhook_register_routes' );
