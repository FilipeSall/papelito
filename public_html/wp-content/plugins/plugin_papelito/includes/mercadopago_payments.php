<?php
/**
 * Emissão do Checkout Pro (Preferences API) na conta Mercado Pago do vendor.
 *
 * Cada tentativa vira no máximo uma preferência, criada com o token OAuth da conta gravada na
 * tentativa e `marketplace_fee: 0` — a Papelito não recebe nada. Itens, frete e descontos são
 * representados em centavos exatos: uma linha por item com o total já descontado e um item
 * "Frete" (o Checkout Pro não aceita `shipments.cost` nem campo de desconto).
 *
 * A regra de ouro: a preferência não tem idempotência remota documentada. Por isso a emissão só
 * acontece depois do compare-and-set `→ creating`; timeout, 5xx ou resposta ilegível viram
 * `unknown` (reconciliar antes de qualquer nova emissão); e só falha de conexão comprovadamente
 * anterior ao envio vira `failed` com motivo `unavailable`, apta a fallback. Link só é entregue
 * quando o collector é o da conta e o host é do Mercado Pago.
 *
 * O que este módulo não faz: não cria pedido nem reserva estoque (orquestrador do checkout), não
 * confirma pagamento (webhook/reconciliação) e nunca usa credencial global da Papelito.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_MERCADOPAGO_PREFERENCE_TTL      = 3600;
const PAPELITO_MERCADOPAGO_OFFLINE_PAYMENT_TTL = DAY_IN_SECONDS;
const PAPELITO_MERCADOPAGO_RECONCILE_DELAY     = 120;

/**
 * Referência externa estável da tentativa (até 64 caracteres, só letras, números, `-` e `_`).
 *
 * @param array<string,mixed> $attempt Tentativa.
 * @return string
 */
function papelito_mercadopago_external_reference( array $attempt ): string {
	return sprintf( 'ppl-%d-%d', (int) $attempt['order_id'], (int) $attempt['id'] );
}

/**
 * URL de notificação da preferência, com o attemptRef opaco; vazia quando não há HTTPS público.
 *
 * O attemptRef só ajuda a achar a conta candidata no webhook; não autentica nada.
 *
 * @param string $attempt_ref Referência opaca da tentativa.
 * @return string
 */
function papelito_mercadopago_notification_url( string $attempt_ref ): string {
	$base = function_exists( 'papelito_mercadopago_setting' ) ? papelito_mercadopago_setting( 'MERCADOPAGO_NOTIFICATION_BASE_URL' ) : '';
	$base = '' !== $base ? $base : rest_url( 'papelito/v1/mercadopago/webhook' );

	if ( ! papelito_mercadopago_is_https_url( $base ) ) {
		return '';
	}

	return add_query_arg( array( 'attempt' => $attempt_ref ), $base );
}

/**
 * Página do frontend que consulta o estado autoritativo do pedido no retorno.
 *
 * @param string $frontend_base Base canônica do frontend.
 * @param int    $order_id      Pedido.
 * @return string
 */
function papelito_mercadopago_back_url( string $frontend_base, int $order_id ): string {
	return rtrim( $frontend_base, '/' ) . '/checkout/pagamento/' . $order_id . '?retorno=mercadopago';
}

/**
 * Data no formato aceito pela API (`yyyy-MM-dd'T'HH:mm:ss.SSSz`).
 *
 * @param int $timestamp Timestamp.
 * @return string
 */
function papelito_mercadopago_api_date( int $timestamp ): string {
	return gmdate( 'Y-m-d\TH:i:s.000', $timestamp ) . '+00:00';
}

/**
 * Itens da preferência em centavos exatos, a partir das linhas e do frete cobrado.
 *
 * @param array<string,mixed> $order_data Dados autoritativos do pedido.
 * @return array<int,array<string,mixed>>
 */
function papelito_mercadopago_preference_items( array $order_data ): array {
	$items = array();

	foreach ( (array) $order_data['lines'] as $line ) {
		$cents = max( 0, (int) $line['total_cents'] );
		if ( $cents <= 0 ) {
			continue;
		}

		$qty     = max( 1, (int) $line['qty'] );
		$name    = sanitize_text_field( (string) $line['name'] );
		$items[] = array(
			'id'          => 'product-' . (int) $line['product_id'],
			'title'       => substr( $qty > 1 ? $qty . ' x ' . $name : $name, 0, 250 ),
			'quantity'    => 1,
			'currency_id' => 'BRL',
			'unit_price'  => round( $cents / 100, 2 ),
		);
	}

	$shipping_cents = max( 0, (int) $order_data['shipping_cents'] );
	if ( $shipping_cents > 0 ) {
		$items[] = array(
			'id'          => 'shipping',
			'title'       => 'Frete',
			'quantity'    => 1,
			'currency_id' => 'BRL',
			'unit_price'  => round( $shipping_cents / 100, 2 ),
		);
	}

	return $items;
}

/**
 * Payload da preferência, com conferência exata de centavos.
 *
 * @param array<string,mixed> $order_data Dados autoritativos (order_id, total_cents, shipping_cents, lines, payer, frontend_base, max_installments).
 * @param array<string,mixed> $attempt    Tentativa já gravada.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_preference_payload( array $order_data, array $attempt ) {
	$items = papelito_mercadopago_preference_items( $order_data );
	$sum   = 0;

	foreach ( $items as $item ) {
		$sum += (int) round( $item['unit_price'] * 100 ) * (int) $item['quantity'];
	}

	if ( empty( $items ) || (int) $order_data['total_cents'] !== $sum || (int) $attempt['amount_cents'] !== $sum ) {
		return new WP_Error( 'papelito_checkout_total_mismatch', 'Os valores do pedido ficaram inconsistentes antes do pagamento. Atualize o carrinho e tente novamente.', array( 'status' => 409 ) );
	}

	$now     = time();
	$payload = array(
		'items'                => $items,
		'payer'                => array(
			'name'           => sanitize_text_field( (string) $order_data['payer']['legal_name'] ),
			'email'          => (string) $order_data['payer']['email'],
			'identification' => array(
				'type'   => 'CNPJ',
				'number' => preg_replace( '/\D+/', '', (string) $order_data['payer']['cnpj'] ),
			),
		),
		'external_reference'   => papelito_mercadopago_external_reference( $attempt ),
		'marketplace_fee'      => 0,
		'expires'              => true,
		'expiration_date_from' => papelito_mercadopago_api_date( $now ),
		'expiration_date_to'   => papelito_mercadopago_api_date( $now + PAPELITO_MERCADOPAGO_PREFERENCE_TTL ),
		'date_of_expiration'   => papelito_mercadopago_api_date( $now + PAPELITO_MERCADOPAGO_OFFLINE_PAYMENT_TTL ),
		'payment_methods'      => array( 'installments' => max( 1, (int) ( $order_data['max_installments'] ?? 1 ) ) ),
		'statement_descriptor' => 'PAPELITO',
		'metadata'             => array(
			'papelito_order_id'   => (int) $order_data['order_id'],
			'papelito_attempt_id' => (int) $attempt['id'],
		),
	);

	$frontend_base = trim( (string) $order_data['frontend_base'] );
	if ( '' !== $frontend_base ) {
		$back_url               = papelito_mercadopago_back_url( $frontend_base, (int) $order_data['order_id'] );
		$payload['back_urls']   = array(
			'success' => $back_url,
			'pending' => $back_url,
			'failure' => $back_url,
		);
		$payload['auto_return'] = 'approved';
	}

	$notification = papelito_mercadopago_notification_url( (string) $attempt['attempt_ref'] );
	if ( '' !== $notification ) {
		$payload['notification_url'] = $notification;
	}

	return $payload;
}

/**
 * Diz se o link de checkout pode ser entregue ao navegador.
 *
 * @param string $url Link devolvido pela API.
 * @return bool
 */
function papelito_mercadopago_redirect_is_allowed( string $url ): bool {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

	if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || '' === $host ) {
		return false;
	}

	foreach ( array( 'mercadopago.com.br', 'mercadopago.com', 'mercadolivre.com.br' ) as $domain ) {
		if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Emite a preferência da tentativa e devolve a tentativa no estado resultante.
 *
 * Só quem vence o compare-and-set `→ creating` chama a API; os demais recebem a tentativa como
 * está, sem nova emissão.
 *
 * @param array<string,mixed> $attempt    Tentativa em `not_started` ou `awaiting_payment_input`.
 * @param array<string,mixed> $order_data Dados autoritativos do pedido.
 * @return array<string,mixed>
 */
function papelito_mercadopago_create_preference( array $attempt, array $order_data ): array {
	$id = (int) $attempt['id'];

	if ( ! papelito_payment_attempt_transition( $id, (string) $attempt['state'], 'creating', array( 'external_reference' => papelito_mercadopago_external_reference( $attempt ) ) ) ) {
		return papelito_payment_attempt_find( $id ) ?? $attempt;
	}

	$account = papelito_vendor_payment_account_find( (int) $attempt['account_id'] );
	$tokens  = is_array( $account ) && 'active' === $account['status'] && (int) $account['vendor_id'] === (int) $attempt['vendor_id']
		? papelito_vendor_payment_account_tokens( $account )
		: new WP_Error( 'papelito_payment_account_reauthorization_required', 'Conta indisponível.' );

	if ( is_wp_error( $tokens ) ) {
		return papelito_mercadopago_fail_attempt( $id, 'configuration' );
	}

	$payload = papelito_mercadopago_preference_payload( $order_data, $attempt );
	if ( is_wp_error( $payload ) ) {
		return papelito_mercadopago_fail_attempt( $id, 'total_mismatch' );
	}

	$response = papelito_mercadopago_request(
		'POST',
		'/checkout/preferences',
		array(
			'token' => $tokens['access_token'],
			'body'  => $payload,
		)
	);

	if ( is_wp_error( $response ) ) {
		return papelito_mercadopago_handle_creation_error( $attempt, $account, $response );
	}

	return papelito_mercadopago_accept_preference( $id, $account, $response );
}

/**
 * Valida a preferência criada e entrega o link, ou marca `unknown` quando ela não é confiável.
 *
 * @param int                 $attempt_id Tentativa.
 * @param array<string,mixed> $account    Conta usada.
 * @param array<string,mixed> $response   Resposta da API.
 * @return array<string,mixed>
 */
function papelito_mercadopago_accept_preference( int $attempt_id, array $account, array $response ): array {
	$preference_id = sanitize_text_field( (string) ( $response['id'] ?? '' ) );
	$init_point    = (string) ( $response['init_point'] ?? '' );
	$collector     = (string) ( $response['collector_id'] ?? '' );
	$trusted       = '' !== $preference_id
		&& (string) $account['collector_id'] === $collector
		&& papelito_mercadopago_redirect_is_allowed( $init_point );

	if ( ! $trusted ) {
		papelito_payment_attempt_transition(
			$attempt_id,
			'creating',
			'unknown',
			array(
				'remote_id'         => $preference_id,
				'last_error_code'   => 'untrusted_preference',
				'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() + PAPELITO_MERCADOPAGO_RECONCILE_DELAY ),
			)
		);

		return papelito_payment_attempt_find( $attempt_id ) ?? array();
	}

	papelito_payment_attempt_transition(
		$attempt_id,
		'creating',
		'pending',
		array(
			'remote_id'         => $preference_id,
			'redirect_url'      => esc_url_raw( $init_point ),
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + PAPELITO_MERCADOPAGO_PREFERENCE_TTL ),
			'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ),
		)
	);

	return papelito_payment_attempt_find( $attempt_id ) ?? array();
}

/**
 * Trata a falha da criação conforme a categoria do cliente.
 *
 * @param array<string,mixed>      $attempt Tentativa.
 * @param array<string,mixed>|null $account Conta usada.
 * @param WP_Error                 $error   Erro categorizado.
 * @return array<string,mixed>
 */
function papelito_mercadopago_handle_creation_error( array $attempt, ?array $account, WP_Error $error ): array {
	$id       = (int) $attempt['id'];
	$category = (string) ( ( (array) $error->get_error_data() )['category'] ?? 'unknown_outcome' );

	if ( 'unknown_outcome' === $category ) {
		papelito_payment_attempt_transition(
			$id,
			'creating',
			'unknown',
			array(
				'last_error_code'   => 'unknown_outcome',
				'next_reconcile_at' => gmdate( 'Y-m-d H:i:s', time() + PAPELITO_MERCADOPAGO_RECONCILE_DELAY ),
			)
		);

		return papelito_payment_attempt_find( $id ) ?? $attempt;
	}

	if ( 'business_restriction' === $category && is_array( $account ) ) {
		papelito_vendor_payment_account_update_health(
			(int) $account['id'],
			array(
				'health_status' => 'kyc_pending',
				'health_reason' => 'regulatory_pending',
			)
		);
		papelito_payment_gateway_health_record( (int) $attempt['vendor_id'], PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO );
	}

	return papelito_mercadopago_fail_attempt( $id, $category );
}

/**
 * Marca a tentativa em criação como falha comprovadamente anterior à criação remota.
 *
 * @param int    $attempt_id Tentativa.
 * @param string $reason     Categoria do motivo.
 * @return array<string,mixed>
 */
function papelito_mercadopago_fail_attempt( int $attempt_id, string $reason ): array {
	papelito_payment_attempt_transition( $attempt_id, 'creating', 'failed', array( 'last_error_code' => sanitize_key( $reason ) ) );

	return papelito_payment_attempt_find( $attempt_id ) ?? array();
}
