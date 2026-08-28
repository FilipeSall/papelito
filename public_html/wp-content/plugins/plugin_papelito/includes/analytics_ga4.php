<?php
/**
 * Evento `purchase` do GA4 enviado pelo Measurement Protocol.
 *
 * A confirmacao de pagamento e a unica fonte confiavel de venda: Pix e boleto sao pagos fora do
 * site e o comprador quase nunca volta a pagina de sucesso, entao um `purchase` disparado pelo
 * navegador contaria apenas cartao e enviesaria o retorno por campanha pelo meio de pagamento.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PAPELITO_GA4_ENDPOINT' ) ) {
	define( 'PAPELITO_GA4_ENDPOINT', 'https://www.google-analytics.com/mp/collect' );
}

if ( ! defined( 'PAPELITO_GA4_TIMEOUT' ) ) {
	define( 'PAPELITO_GA4_TIMEOUT', 5 );
}

if ( ! defined( 'PAPELITO_GA4_PURCHASE_HOOK' ) ) {
	define( 'PAPELITO_GA4_PURCHASE_HOOK', 'papelito_ga4_purchase_event' );
}

if ( ! defined( 'PAPELITO_GA4_MAX_ATTEMPTS' ) ) {
	define( 'PAPELITO_GA4_MAX_ATTEMPTS', 3 );
}

if ( ! defined( 'PAPELITO_GA4_CLIENT_ID_META' ) ) {
	define( 'PAPELITO_GA4_CLIENT_ID_META', '_papelito_ga_client_id' );
}

if ( ! defined( 'PAPELITO_GA4_SESSION_ID_META' ) ) {
	define( 'PAPELITO_GA4_SESSION_ID_META', '_papelito_ga_session_id' );
}

if ( ! defined( 'PAPELITO_GA4_PURCHASE_SENT_META' ) ) {
	define( 'PAPELITO_GA4_PURCHASE_SENT_META', '_papelito_ga4_purchase_sent_at' );
}

if ( ! defined( 'PAPELITO_GA4_PURCHASE_ATTEMPTS_META' ) ) {
	define( 'PAPELITO_GA4_PURCHASE_ATTEMPTS_META', '_papelito_ga4_purchase_attempts' );
}

/**
 * Identificador da propriedade do GA4 que recebe a venda.
 */
function papelito_ga4_measurement_id(): string {
	if ( function_exists( 'papelito_integration_secret' ) ) {
		return papelito_integration_secret( 'ga4_measurement_id' );
	}

	$value = papelito_env( 'GA4_MEASUREMENT_ID', '' );

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Segredo do Measurement Protocol. Nunca chega ao navegador.
 */
function papelito_ga4_api_secret(): string {
	if ( function_exists( 'papelito_integration_secret' ) ) {
		return papelito_integration_secret( 'ga4_api_secret' );
	}

	$value = papelito_env( 'GA4_API_SECRET', '' );

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Se a propriedade e o segredo do Measurement Protocol estao configurados nesta instalacao.
 *
 * O `function_exists` nao e paranoia: em producao o `wp-config.php` e mantido a mao e ja houve o
 * caso de `papelito_env()` faltar la. Este codigo roda dentro da confirmacao de pagamento, e
 * analytics jamais pode derrubar esse caminho.
 */
function papelito_ga4_is_configured(): bool {
	if ( ! function_exists( 'papelito_env' ) ) {
		return false;
	}

	return '' !== papelito_ga4_measurement_id() && '' !== papelito_ga4_api_secret();
}

/**
 * Valida o `client_id` que veio do navegador antes de reenviá-lo ao Google.
 *
 * O valor e controlado pelo cliente e sai daqui direto num request externo, entao o formato do
 * cookie `_ga` e exigido literalmente: dois inteiros separados por ponto.
 *
 * @param mixed $value Valor cru recebido no payload do checkout.
 */
function papelito_ga4_sanitize_client_id( $value ): string {
	$candidate = is_string( $value ) ? trim( $value ) : '';

	return preg_match( '/^\d{1,20}\.\d{1,20}$/', $candidate ) ? $candidate : '';
}

/**
 * Valida o `session_id` que veio do navegador.
 *
 * @param mixed $value Valor cru recebido no payload do checkout.
 */
function papelito_ga4_sanitize_session_id( $value ): string {
	$candidate = is_string( $value ) ? trim( $value ) : '';

	return preg_match( '/^\d{1,20}$/', $candidate ) ? $candidate : '';
}

/**
 * Guarda no pedido a identidade da sessao que originou a compra.
 *
 * Sem esses dois valores o evento enviado pelo servidor vira um usuario novo sem origem e a venda
 * cai em `direct`, que e exatamente a atribuicao que este trabalho existe para preservar.
 *
 * @param object $order     Pedido WooCommerce recem-criado.
 * @param array  $analytics Bloco `analytics` do payload do checkout.
 */
function papelito_ga4_store_order_identifiers( $order, array $analytics ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
		return;
	}

	$client_id  = papelito_ga4_sanitize_client_id( $analytics['client_id'] ?? '' );
	$session_id = papelito_ga4_sanitize_session_id( $analytics['session_id'] ?? '' );

	if ( '' === $client_id && '' === $session_id ) {
		return;
	}

	if ( '' !== $client_id ) {
		$order->update_meta_data( PAPELITO_GA4_CLIENT_ID_META, $client_id );
	}

	if ( '' !== $session_id ) {
		$order->update_meta_data( PAPELITO_GA4_SESSION_ID_META, $session_id );
	}

	if ( method_exists( $order, 'save' ) ) {
		$order->save();
	}
}

/**
 * Traduz as linhas do pedido para o formato de item do GA4.
 *
 * `item_id` usa o id do produto, o mesmo que o navegador envia em `view_item` e `add_to_cart`:
 * chave divergente entre as etapas quebra o funil no relatorio.
 *
 * @param object $order Pedido WooCommerce.
 * @return array<int,array<string,mixed>>
 */
function papelito_ga4_build_items( $order ): array {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
		return array();
	}

	$items = array();

	foreach ( (array) $order->get_items() as $line ) {
		if ( ! is_object( $line ) || ! method_exists( $line, 'get_product_id' ) ) {
			continue;
		}

		$quantity = max( 1, (int) $line->get_quantity() );
		$total    = (float) $line->get_total();

		$items[] = array(
			'item_id'   => (string) (int) $line->get_product_id(),
			'item_name' => (string) $line->get_name(),
			'price'     => round( $total / $quantity, 2 ),
			'quantity'  => $quantity,
		);
	}

	return $items;
}

/**
 * Soma a receita dos itens, que e o `value` que o GA4 espera no `purchase`.
 *
 * Frete e imposto NAO entram: eles viajam nos campos `shipping` e `tax`. Somar o total do pedido
 * aqui deixaria `value` diferente do somatorio de `items` no mesmo evento, inflando a receita
 * atribuida a campanha e impedindo que receita de produto e da transacao fechem no relatorio.
 * A soma sai dos itens ja montados para nao divergir por arredondamento.
 *
 * @param array<int,array<string,mixed>> $items Itens ja no formato do GA4.
 */
function papelito_ga4_items_value( array $items ): float {
	$value = 0.0;

	foreach ( $items as $item ) {
		$value += (float) $item['price'] * (int) $item['quantity'];
	}

	return round( $value, 2 );
}

/**
 * Monta o corpo do Measurement Protocol para um pedido pago.
 *
 * @param object $order Pedido WooCommerce.
 * @return array<string,mixed>|null Payload pronto, ou null quando falta a identidade do navegador.
 */
function papelito_ga4_build_purchase_payload( $order ): ?array {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return null;
	}

	$client_id = papelito_ga4_sanitize_client_id( $order->get_meta( PAPELITO_GA4_CLIENT_ID_META, true ) );

	if ( '' === $client_id ) {
		return null;
	}

	$session_id = papelito_ga4_sanitize_session_id( $order->get_meta( PAPELITO_GA4_SESSION_ID_META, true ) );
	$items      = papelito_ga4_build_items( $order );

	if ( empty( $items ) ) {
		return null;
	}

	$currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '';
	$shipping = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
	$tax      = method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : 0.0;

	$params = array(
		'transaction_id'       => (string) $order->get_order_number(),
		'value'                => papelito_ga4_items_value( $items ),
		'currency'             => '' !== $currency ? $currency : 'BRL',
		'shipping'             => round( $shipping, 2 ),
		'tax'                  => round( $tax, 2 ),
		'items'                => $items,
		// Evento de servidor nao tem tempo de tela; sem isto o GA4 descarta a sessao do evento.
		'engagement_time_msec' => 1,
	);

	if ( '' !== $session_id ) {
		$params['session_id'] = $session_id;
	}

	return array(
		'client_id' => $client_id,
		'events'    => array(
			array(
				'name'   => 'purchase',
				'params' => $params,
			),
		),
	);
}

/**
 * Envia a venda ao GA4, uma unica vez por pedido.
 *
 * O gancho de pagamento e reentrante por desenho: webhook repetido da Pagar.me reemite o evento.
 * Sem a marca de envio, cada reemissao somaria o mesmo faturamento outra vez no relatorio.
 *
 * @param object $order Pedido WooCommerce ja pago.
 */
function papelito_ga4_send_purchase( $order ): bool {
	if ( ! papelito_ga4_is_configured() || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return false;
	}

	if ( '' !== (string) $order->get_meta( PAPELITO_GA4_PURCHASE_SENT_META, true ) ) {
		return false;
	}

	$payload = papelito_ga4_build_purchase_payload( $order );

	if ( null === $payload ) {
		return false;
	}

	$url = add_query_arg(
		array(
			'measurement_id' => papelito_ga4_measurement_id(),
			'api_secret'     => papelito_ga4_api_secret(),
		),
		PAPELITO_GA4_ENDPOINT
	);

	$response = wp_remote_post(
		$url,
		array(
			'timeout'     => PAPELITO_GA4_TIMEOUT,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'data_format' => 'body',
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log(
			sprintf(
				'[papelito_ga4] purchase pedido %d falhou: %s',
				(int) $order->get_id(),
				substr( sanitize_text_field( $response->get_error_message() ), 0, 300 )
			)
		);

		return false;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( $status < 200 || $status >= 300 ) {
		error_log( sprintf( '[papelito_ga4] purchase pedido %d -> HTTP %d', (int) $order->get_id(), $status ) );

		return false;
	}

	// Marcado so depois da entrega: falha deixa o pedido elegivel para a proxima reemissao do
	// webhook, e perder uma venda no relatorio e menos grave que contar duas.
	$order->update_meta_data( PAPELITO_GA4_PURCHASE_SENT_META, (string) time() );
	$order->save();

	return true;
}

/**
 * Espera entre tentativas de entrega, em segundos.
 *
 * @param int $attempt Tentativas ja realizadas.
 */
function papelito_ga4_retry_delay( int $attempt ): int {
	$delays = array( 0, 120, 600 );

	return $delays[ $attempt ] ?? 1800;
}

/**
 * Agenda a entrega do `purchase` em vez de faze-la no ato.
 *
 * `papelito_order_payment_confirmed` dispara tambem dentro de
 * `papelito_pagarme_create_order_payment()`, ou seja, no request de checkout do comprador quando o
 * cartao e aprovado na hora. Um POST bloqueante ao Google ali prenderia a resposta do checkout pelo
 * tempo do timeout se o Google demorasse — analytics segurando pagamento, exatamente o que este
 * modulo nao pode fazer. O caminho do pagamento so enfileira; quem entrega e o cron.
 *
 * @param object $order Pedido WooCommerce ja pago.
 */
function papelito_ga4_schedule_purchase( $order ): void {
	if ( ! papelito_ga4_is_configured() || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return;
	}

	if ( '' !== (string) $order->get_meta( PAPELITO_GA4_PURCHASE_SENT_META, true ) ) {
		return;
	}

	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		return;
	}

	$order_id = (int) $order->get_id();
	$args     = array( $order_id );

	// Webhook reemitido chama este gancho de novo; sem esta checagem cada reemissao criaria mais um
	// evento agendado para o mesmo pedido.
	if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( PAPELITO_GA4_PURCHASE_HOOK, $args ) ) {
		return;
	}

	wp_schedule_single_event( time() + papelito_ga4_retry_delay( 0 ), PAPELITO_GA4_PURCHASE_HOOK, $args );
}
add_action( 'papelito_order_payment_confirmed', 'papelito_ga4_schedule_purchase', 20, 1 );

/**
 * Reagenda a entrega apos falha, com espera crescente e teto de tentativas.
 *
 * @param object $order    Pedido WooCommerce.
 * @param int    $order_id Id do pedido.
 */
function papelito_ga4_reschedule_purchase( $order, int $order_id ): void {
	$attempts = (int) $order->get_meta( PAPELITO_GA4_PURCHASE_ATTEMPTS_META, true ) + 1;

	if ( $attempts >= PAPELITO_GA4_MAX_ATTEMPTS || ! function_exists( 'wp_schedule_single_event' ) ) {
		error_log( sprintf( '[papelito_ga4] purchase pedido %d desistiu apos %d tentativas', $order_id, $attempts ) );

		return;
	}

	$order->update_meta_data( PAPELITO_GA4_PURCHASE_ATTEMPTS_META, (string) $attempts );
	$order->save();

	wp_schedule_single_event( time() + papelito_ga4_retry_delay( $attempts ), PAPELITO_GA4_PURCHASE_HOOK, array( $order_id ) );
}

/**
 * Entrega agendada, fora do caminho do pagamento.
 *
 * @param int $order_id Id do pedido.
 */
function papelito_ga4_run_scheduled_purchase( $order_id ): void {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( (int) $order_id );

	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return;
	}

	if ( papelito_ga4_send_purchase( $order ) ) {
		return;
	}

	// Reagenda so quando a entrega falhou. Falta de credencial ou de identidade do navegador nao
	// melhora com o tempo, e ficar reagendando isso encheria o cron de trabalho que nunca conclui.
	if ( ! papelito_ga4_is_configured() ) {
		return;
	}

	if ( '' !== (string) $order->get_meta( PAPELITO_GA4_PURCHASE_SENT_META, true ) ) {
		return;
	}

	if ( null === papelito_ga4_build_purchase_payload( $order ) ) {
		return;
	}

	papelito_ga4_reschedule_purchase( $order, (int) $order_id );
}
add_action( PAPELITO_GA4_PURCHASE_HOOK, 'papelito_ga4_run_scheduled_purchase', 10, 1 );
/**
 * Registra o apagamento dos identificadores de analytics no mecanismo de titular do WordPress.
 *
 * O pedido em si nao pode ser apagado — retencao fiscal —, mas `client_id` e `session_id` nao tem
 * exigencia legal nenhuma pendurada neles. Depois que passaram a viver dentro de um pedido, que tem
 * nome, CNPJ e endereco, eles deixaram de ser pseudonimos e precisam ser purgaveis a pedido.
 *
 * @param array<string,array<string,mixed>> $erasers Apagadores ja registrados.
 * @return array<string,array<string,mixed>>
 */
function papelito_ga4_register_privacy_eraser( array $erasers ): array {
	$erasers['papelito-ga4-identifiers'] = array(
		'eraser_friendly_name' => 'Identificadores de analytics dos pedidos',
		'callback'             => 'papelito_ga4_erase_order_identifiers',
	);

	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'papelito_ga4_register_privacy_eraser' );

/**
 * Apaga `client_id` e `session_id` dos pedidos de um titular.
 *
 * @param string $email E-mail do titular.
 * @param int    $page  Pagina da varredura, controlada pelo WordPress.
 * @return array<string,mixed>
 */
function papelito_ga4_erase_order_identifiers( string $email, int $page = 1 ): array {
	$response = array(
		'items_removed'  => false,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);

	if ( ! function_exists( 'wc_get_orders' ) || ! is_email( $email ) ) {
		return $response;
	}

	$per_page = 50;
	$orders   = wc_get_orders(
		array(
			'billing_email' => $email,
			'limit'         => $per_page,
			'page'          => max( 1, $page ),
			'type'          => 'shop_order',
		)
	);

	if ( ! is_array( $orders ) ) {
		return $response;
	}

	foreach ( $orders as $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'delete_meta_data' ) ) {
			continue;
		}

		$had_identifiers = '' !== (string) $order->get_meta( PAPELITO_GA4_CLIENT_ID_META, true )
			|| '' !== (string) $order->get_meta( PAPELITO_GA4_SESSION_ID_META, true );

		if ( ! $had_identifiers ) {
			continue;
		}

		$order->delete_meta_data( PAPELITO_GA4_CLIENT_ID_META );
		$order->delete_meta_data( PAPELITO_GA4_SESSION_ID_META );
		$order->save();

		$response['items_removed'] = true;
	}

	$response['done'] = count( $orders ) < $per_page;

	return $response;
}
