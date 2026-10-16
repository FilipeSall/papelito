<?php
/**
 * `POST /checkout/payment-context`: qual gateway e fluxo a compra vai usar, sem cobrar nada.
 *
 * O vendor sai dos itens resolvidos pelo backend (mesmo resolvedor do place-order), nunca do
 * vendor indicado pelo navegador; a empresa ativa é rechecada como no checkout. Não cria pedido,
 * reserva nem tentativa — a decisão definitiva é refeita na emissão, e a `configurationVersion`
 * permite ao checkout perceber que algo mudou entre a revisão e o pagamento.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

/**
 * Handler do payment-context.
 *
 * @param WP_REST_Request $request Requisição com `expected_company_id` e `items`.
 * @return WP_REST_Response|WP_Error
 */
function papelito_payment_context_rest( WP_REST_Request $request ) {
	$payload  = (array) $request->get_json_params();
	$snapshot = papelito_order_routing_resolve_b2b_snapshot( get_current_user_id(), $payload );

	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}

	$items = papelito_order_routing_normalize_items( $payload['items'] ?? null );
	if ( is_wp_error( $items ) ) {
		return $items;
	}

	$resolved = papelito_order_routing_resolve_items( $items );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$context = papelito_payment_gateway_resolve( (int) $resolved['vendor_id'] );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	unset( $context['legacy'] );

	$response = new WP_REST_Response( $context, 200 );
	$response->header( 'Cache-Control', 'no-store, private' );

	return $response;
}

/**
 * Registra a rota do payment-context.
 *
 * @return void
 */
function papelito_payment_context_rest_register_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/checkout/payment-context',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_order_routing_require_customer',
			'callback'            => 'papelito_payment_context_rest',
		)
	);
}

add_action( 'rest_api_init', 'papelito_payment_context_rest_register_routes' );
