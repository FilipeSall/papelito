<?php
/**
 * Rotas de administrador da venda no estande: repasse à Cifal, terminais e estorno.
 *
 * Só `manage_options`. O repasse sai do ledger em JSON ou CSV; a conferência de terminais lista os
 * terminais da conta Papelito e marca o configurado no evento; o modo da máquina (PDV para o
 * evento, STANDALONE depois) e o estorno agem só no terminal e nas orders do evento.
 *
 * O que este módulo não faz: não grava o ledger de estorno; isso chega pelo webhook `refunded`.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gate das rotas de admin: evento certo e `manage_options`.
 *
 * @param WP_REST_Request $request Requisição com `event_id`.
 * @return true|WP_Error
 */
function papelito_event_sales_require_admin( WP_REST_Request $request ): bool|WP_Error {
	if ( PAPELITO_EVENT_SALES_ID !== (string) $request->get_param( 'event_id' ) ) {
		return new WP_Error( 'papelito_event_not_found', 'Evento não encontrado.', array( 'status' => 404 ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( PAPELITO_EVENT_SALES_FORBIDDEN_CODE, 'Só administradores veem o repasse do evento.', array( 'status' => PAPELITO_EVENT_SALES_FORBIDDEN_STATUS ) );
	}

	return true;
}

/**
 * Nome do arquivo CSV do repasse.
 *
 * @return string
 */
function papelito_event_sales_settlement_filename(): string {
	return 'repasse-cifal-' . PAPELITO_EVENT_SALES_ID . '.csv';
}

/**
 * Soma uma coluna de valores conhecidos do ledger.
 *
 * @param array<int,array<string,mixed>> $rows   Linhas.
 * @param string                         $column Coluna.
 * @return int
 */
function papelito_event_sales_admin_sum( array $rows, string $column ): int {
	$total = 0;

	foreach ( $rows as $row ) {
		$total += null === $row[ $column ] ? 0 : (int) $row[ $column ];
	}

	return $total;
}

/**
 * Ledger do evento com os totais devidos à Cifal e quantas linhas ainda têm taxa a conferir.
 *
 * @param string $event_id Evento.
 * @return array{rows:array<int,array<string,mixed>>,totals:array<string,int>}
 */
function papelito_event_sales_settlement_summary( string $event_id ): array {
	$rows    = papelito_event_sales_settlement_rows( $event_id );
	$pending = 0;

	foreach ( $rows as $row ) {
		$pending += null === $row['fee_cents'] ? 1 : 0;
	}

	return array(
		'rows'   => $rows,
		'totals' => array(
			'grossCents'     => papelito_event_sales_admin_sum( $rows, 'gross_cents' ),
			'feeCents'       => papelito_event_sales_admin_sum( $rows, 'fee_cents' ),
			'netCents'       => papelito_event_sales_admin_sum( $rows, 'net_cents' ),
			'pendingFeeRows' => $pending,
		),
	);
}

/**
 * Terminais da lista da API, aceitando `data.terminals` ou `terminals`.
 *
 * @param array<string,mixed> $response Resposta de `/terminals/v1/list`.
 * @return array<int,array<string,mixed>>
 */
function papelito_event_sales_terminals_from_list( array $response ): array {
	$terminals = $response['data']['terminals'] ?? $response['terminals'] ?? array();

	return is_array( $terminals ) ? array_values( array_filter( $terminals, 'is_array' ) ) : array();
}

/**
 * Terminais da conta Papelito, marcando o configurado no evento e se ele está em PDV.
 *
 * @return array{terminals:array<int,array{id:string,operatingMode:string,configured:bool}>,ready:bool}|WP_Error
 */
function papelito_event_sales_terminals_check(): array|WP_Error {
	$list = papelito_mercadopago_point_list_terminals();
	if ( is_wp_error( $list ) ) {
		return $list;
	}

	$configured = papelito_event_sales_config()['terminal_id'];
	$terminals  = array();
	$ready      = false;

	foreach ( papelito_event_sales_terminals_from_list( $list ) as $terminal ) {
		$id          = (string) ( $terminal['id'] ?? '' );
		$mode        = (string) ( $terminal['operating_mode'] ?? '' );
		$is_event    = '' !== $configured && $configured === $id;
		$ready       = $ready || ( $is_event && 'PDV' === $mode );
		$terminals[] = array(
			'id'            => $id,
			'operatingMode' => $mode,
			'configured'    => $is_event,
		);
	}

	return array(
		'terminals' => $terminals,
		'ready'     => $ready,
	);
}

/**
 * Troca o modo da máquina configurada no evento. Outro terminal nunca é tocado.
 *
 * @param string $mode `PDV` ou `STANDALONE`.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_terminal_mode( string $mode ): array|WP_Error {
	$terminal = papelito_event_sales_config()['terminal_id'];

	if ( '' === $terminal || ! in_array( $mode, array( 'PDV', 'STANDALONE' ), true ) ) {
		return new WP_Error( 'papelito_event_terminal_mode_invalid', 'Modo inválido ou terminal do evento não configurado.', array( 'status' => 400 ) );
	}

	$result = papelito_mercadopago_point_set_mode( $terminal, $mode );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'terminalId' => $terminal,
		'mode'       => $mode,
	);
}

/**
 * Estorna na Point a order paga do pedido. O ledger negativo chega pelo webhook `refunded`.
 *
 * @param object $order Pedido do evento.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_admin_refund( object $order ): array|WP_Error {
	$attempt  = papelito_payment_attempt_latest_for_order( (int) $order->get_id() );
	$eligible = is_array( $attempt ) && 'mercadopago_point' === $attempt['provider'] && 'paid' === $attempt['state'] && '' !== (string) $attempt['remote_id'];

	if ( ! $eligible ) {
		return new WP_Error( 'papelito_event_refund_not_allowed', 'Só a cobrança paga na maquininha pode ser estornada aqui.', array( 'status' => 409 ) );
	}

	$result = papelito_mercadopago_point_refund_order( (string) $attempt['remote_id'], 'refund-' . (string) $attempt['attempt_ref'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'orderId'   => (int) $order->get_id(),
		'requested' => true,
	);
}

/**
 * `GET event/{id}/admin/settlement[?format=csv]`. O CSV sai como download e encerra a requisição.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|null
 */
function papelito_event_sales_settlement_endpoint( WP_REST_Request $request ): ?WP_REST_Response {
	if ( 'csv' === (string) $request->get_param( 'format' ) ) {
		papelito_admin_reports_output_download( papelito_event_sales_settlement_csv( papelito_event_sales_settlement_rows( PAPELITO_EVENT_SALES_ID ) ), papelito_event_sales_settlement_filename(), 'text/csv; charset=UTF-8' );
		return null;
	}

	return papelito_event_sales_response( papelito_event_sales_settlement_summary( PAPELITO_EVENT_SALES_ID ) );
}

/**
 * `GET event/{id}/admin/terminals/check`.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_terminals_check_endpoint(): WP_REST_Response|WP_Error {
	return papelito_event_sales_respond( papelito_event_sales_terminals_check() );
}

/**
 * `POST event/{id}/admin/terminals/mode`.
 *
 * @param WP_REST_Request $request Requisição com `mode`.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_terminal_mode_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_respond( papelito_event_sales_terminal_mode( (string) ( papelito_event_sales_json_body( $request )['mode'] ?? '' ) ) );
}

/**
 * `POST event/{id}/admin/orders/{orderId}/refund`.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_admin_refund_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_event_sales_order_action( $request, static fn( object $order ): array|WP_Error => papelito_event_sales_admin_refund( $order ) );
}

/**
 * Registra as rotas de admin do evento.
 *
 * @return void
 */
function papelito_event_sales_register_admin_routes(): void {
	$routes = array(
		'/admin/settlement'      => array( 'GET', 'papelito_event_sales_settlement_endpoint' ),
		'/admin/terminals/check' => array( 'GET', 'papelito_event_sales_terminals_check_endpoint' ),
		'/admin/terminals/mode'  => array( 'POST', 'papelito_event_sales_terminal_mode_endpoint' ),
		'/admin' . PAPELITO_EVENT_SALES_ORDER_ROUTE . '/refund' => array( 'POST', 'papelito_event_sales_admin_refund_endpoint' ),
	);

	foreach ( $routes as $path => $route ) {
		papelito_event_sales_register_route(
			$path,
			array(
				'methods'             => $route[0],
				'callback'            => $route[1],
				'permission_callback' => 'papelito_event_sales_require_admin',
			)
		);
	}
}
add_action( 'rest_api_init', 'papelito_event_sales_register_admin_routes' );
