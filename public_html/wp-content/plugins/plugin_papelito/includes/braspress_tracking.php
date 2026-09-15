<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.
/**
 * Tracking Braspress por número de pedido informado após postagem externa.
 *
 * A API pública não documenta contratação, coleta, etiqueta ou cancelamento.
 * Este adapter só registra a referência que o vendor já confirmou na
 * Braspress e consulta o endpoint byNumPedido no backend.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_BRASPRESS_TRACKING_URL    = 'https://api.braspress.com/v3/tracking/byNumPedido';
const PAPELITO_BRASPRESS_TRACKING_SOURCE = 'braspress_poll';

/**
 * Normaliza o número de pedido externo, separado do ID interno e do S10.
 *
 * @param mixed $value Referência informada pelo vendor.
 * @return string Referência permitida ou vazio quando inválida.
 */
function papelito_braspress_tracking_normalize_reference( $value ): string {
	$reference = trim( sanitize_text_field( (string) $value ) );
	if ( '' === $reference || strlen( $reference ) > 96 || 1 !== preg_match( '/^[A-Za-z0-9._\/-]+$/', $reference ) ) {
		return '';
	}

	return $reference;
}

/**
 * Garante que apenas o provider escolhido possa registrar a referência.
 *
 * @param mixed $order Pedido WooCommerce.
 * @param int   $vendor_id ID do vendor solicitante.
 * @return true|WP_Error Elegibilidade ou motivo de bloqueio.
 */
function papelito_braspress_tracking_order_is_eligible( $order, int $vendor_id ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'is_paid' ) || ! $order->is_paid() ) {
		return new WP_Error( 'papelito_tracking_order_not_ready', 'O pedido precisa estar pago antes de registrar a postagem.', array( 'status' => 409 ) );
	}
	if ( $vendor_id <= 0 || absint( $order->get_meta( '_papelito_vendor_id', true ) ) !== $vendor_id ) {
		return new WP_Error( 'papelito_tracking_forbidden', 'Ação não permitida para este pedido.', array( 'status' => 403 ) );
	}
	if ( 'braspress' !== sanitize_key( (string) $order->get_meta( '_papelito_shipping_provider', true ) ) ) {
		return new WP_Error( 'papelito_braspress_tracking_provider_mismatch', 'Este pedido não foi cotado pela Braspress.', array( 'status' => 409 ) );
	}

	return true;
}

/**
 * Persiste a referência externa sem inferir uma remessa no provider.
 *
 * @param mixed  $order Pedido WooCommerce.
 * @param int    $vendor_id ID do vendor.
 * @param string $external_reference Número de pedido confirmado na Braspress.
 * @param string $posted_at Data de postagem declarada pelo vendor.
 * @return array<string,mixed>|WP_Error
 */
function papelito_braspress_tracking_register_external_shipment( $order, int $vendor_id, string $external_reference, string $posted_at ) {
	global $wpdb;

	$eligible = papelito_braspress_tracking_order_is_eligible( $order, $vendor_id );
	if ( is_wp_error( $eligible ) ) {
		return $eligible;
	}

	$reference = papelito_braspress_tracking_normalize_reference( $external_reference );
	$posted_at = sanitize_text_field( $posted_at );
	if ( '' === $reference ) {
		return new WP_Error( 'papelito_braspress_tracking_reference_invalid', 'Informe o número de pedido confirmado na Braspress.', array( 'status' => 422 ) );
	}
	if ( 1 !== preg_match( PAPELITO_TRACKING_POSTED_AT_PATTERN, $posted_at ) ) {
		return new WP_Error( 'papelito_manual_posted_at_required', 'Informe a data de postagem.', array( 'status' => 422 ) );
	}

	$integration = papelito_vendor_integration_resolve_braspress( $vendor_id );
	if ( null === $integration || is_wp_error( $integration ) ) {
		return new WP_Error( 'papelito_braspress_tracking_unavailable', 'A integração Braspress do vendor não está disponível.', array( 'status' => 409 ) );
	}

	$table    = papelito_tracking_shipments_table_name();
	$order_id = absint( $order->get_id() );
	$existing = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives exclusively from $wpdb->prefix.
			"SELECT * FROM {$table} WHERE order_id = %d AND direction = 'outbound' AND active = 1 LIMIT 1",
			$order_id
		),
		ARRAY_A
	);
	if ( is_array( $existing ) ) {
		if ( 'braspress' === sanitize_key( (string) ( $existing['provider'] ?? '' ) ) && hash_equals( (string) ( $existing['external_reference'] ?? '' ), $reference ) ) {
			return papelito_tracking_order_snapshot( $order_id );
		}
		return new WP_Error( 'papelito_tracking_shipment_exists', 'O pedido já possui um envio ativo.', array( 'status' => 409 ) );
	}

	$now      = current_time( 'mysql', true );
	$inserted = $wpdb->insert(
		$table,
		array(
			'order_id'              => $order_id,
			'vendor_id'             => $vendor_id,
			'direction'             => 'outbound',
			'provider'              => 'braspress',
			'generation_status'     => 'external_registered',
			'creation_outcome'      => 'created',
			'reconciliation_status' => 'not_applicable',
			'is_test'               => 0,
			'idempotency_key'       => hash_hmac( 'sha256', implode( '|', array( 'braspress-by-order', $order_id, $vendor_id, $reference ) ), wp_salt( 'auth' ) ),
			'external_reference'    => $reference,
			'service_code'          => 'byNumPedido',
			'posted_at'             => $posted_at,
			'status'                => 'posted',
			'status_rank'           => 30,
			'next_poll_at'          => $now,
			'active'                => 1,
			'created_at'            => $now,
			'updated_at'            => $now,
		)
	);
	if ( false === $inserted ) {
		$status = false !== strpos( strtolower( (string) $wpdb->last_error ), 'duplicate' ) ? 409 : 500;
		return new WP_Error( 'papelito_braspress_tracking_save_failed', 'Não foi possível registrar o pedido externo.', array( 'status' => $status ) );
	}

	$shipment_id = absint( $wpdb->insert_id );
	$order->add_order_note( sprintf( 'Postagem Braspress confirmada pelo vendor no envio #%d. Referência externa: %s. Data: %s.', $shipment_id, $reference, $posted_at ) );
	$order->save();

	return papelito_tracking_order_snapshot( $order_id );
}

/**
 * Consulta a Braspress uma vez, com Basic Auth somente no backend.
 *
 * @param array<string,mixed> $integration Integração resolvida para o vendor.
 * @param string              $external_reference Número de pedido externo.
 * @return array<string,mixed>|WP_Error Corpo do provider ou erro redigido.
 */
function papelito_braspress_tracking_by_order( array $integration, string $external_reference ) {
	$reference = papelito_braspress_tracking_normalize_reference( $external_reference );
	$config    = is_array( $integration['config'] ?? null ) ? $integration['config'] : array();
	$tomador   = papelito_vendor_integration_normalize_document( $config['tracking_tomador_cnpj'] ?? '' );
	$auth      = is_array( $integration['credentials'] ?? null ) ? $integration['credentials'] : array();
	$username  = (string) ( $auth['username'] ?? '' );
	$password  = (string) ( $auth['password'] ?? '' );
	if ( '' === $reference || 14 !== strlen( $tomador ) || '' === $username || '' === $password ) {
		return new WP_Error( 'papelito_braspress_tracking_invalid_context', 'A consulta de tracking Braspress não está disponível.', array( 'status' => 503 ) );
	}

	$response = wp_remote_get(
		PAPELITO_BRASPRESS_TRACKING_URL . '/' . rawurlencode( $tomador ) . '/' . rawurlencode( $reference ) . '/json',
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'papelito_braspress_tracking_unavailable', 'A Braspress está temporariamente indisponível.', array( 'status' => 502 ) );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 401 === $status || 403 === $status ) {
		papelito_vendor_integration_set_braspress_operational_state( (int) ( $integration['vendor_id'] ?? 0 ), PAPELITO_VENDOR_INTEGRATION_INVALID, 'credentials_invalid' );
		return new WP_Error( 'papelito_braspress_credentials_invalid', 'As credenciais Braspress precisam ser atualizadas.', array( 'status' => 502 ) );
	}
	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		return new WP_Error( 'papelito_braspress_tracking_failed', 'Não foi possível consultar o tracking Braspress.', array( 'status' => 502 ) );
	}

	return $body;
}

/**
 * Informa se a Braspress ainda não conhece a remessa consultada.
 *
 * Uma consulta sem resultado volta HTTP 200 com a lista vazia, e não 404.
 * Tratar isso como sucesso inventaria um rastreio que não existe.
 *
 * @param array<string,mixed> $response Corpo devolvido pela Braspress.
 * @return bool Se nenhum conhecimento foi encontrado.
 */
function papelito_braspress_tracking_is_empty( array $response ): bool {
	$conhecimentos = $response['conhecimentos'] ?? null;

	return ! is_array( $conhecimentos ) || empty( $conhecimentos );
}

/**
 * Preserva a semântica externa sem criar transição interna.
 *
 * O status real vive dentro de `conhecimentos[]`, nunca na raiz da resposta.
 * Nenhum campo é obrigatório no contrato publicado, então cada um é opcional.
 *
 * @param array<string,mixed> $response Corpo devolvido pela Braspress.
 * @return string Status externo exibível ou vazio quando não há conhecimento.
 */
function papelito_braspress_tracking_external_status( array $response ): string {
	if ( papelito_braspress_tracking_is_empty( $response ) ) {
		return '';
	}

	$conhecimento = null;
	foreach ( $response['conhecimentos'] as $entry ) {
		if ( is_array( $entry ) ) {
			$conhecimento = $entry;
			break;
		}
	}

	if ( null === $conhecimento ) {
		return '';
	}

	$fields = array( 'descricaoUltimaOcorrencia', 'statusTransporte', 'status', 'situacao', 'descricao' );
	foreach ( $fields as $field ) {
		if ( isset( $conhecimento[ $field ] ) && is_scalar( $conhecimento[ $field ] ) ) {
			$value = sanitize_text_field( (string) $conhecimento[ $field ] );
			if ( '' !== $value ) {
				return substr( $value, 0, 96 );
			}
		}
	}

	return 'Atualização recebida';
}

/**
 * Salva resposta bruta e preserva `posted` sem mapa de status contratado.
 *
 * @param array<string,mixed> $shipment Remessa persistida.
 * @return void
 */
function papelito_braspress_tracking_poll_shipment( array $shipment ): void {
	global $wpdb;

	$shipment_id = absint( $shipment['id'] ?? 0 );
	$vendor_id   = absint( $shipment['vendor_id'] ?? 0 );
	$reference   = papelito_braspress_tracking_normalize_reference( $shipment['external_reference'] ?? '' );
	if ( $shipment_id <= 0 || $vendor_id <= 0 || '' === $reference ) {
		return;
	}

	$integration = papelito_vendor_integration_resolve_braspress( $vendor_id );
	if ( null === $integration || is_wp_error( $integration ) ) {
		papelito_tracking_schedule_next_poll( $shipment_id, true, 'braspress_integration_unavailable' );
		return;
	}

	$response = papelito_braspress_tracking_by_order( $integration, $reference );
	if ( is_wp_error( $response ) ) {
		papelito_tracking_schedule_next_poll( $shipment_id, true, $response->get_error_code() );
		return;
	}

	if ( papelito_braspress_tracking_is_empty( $response ) ) {
		papelito_tracking_schedule_next_poll( $shipment_id, true, 'braspress_tracking_not_found' );
		return;
	}

	$external_status = papelito_braspress_tracking_external_status( $response );
	papelito_tracking_ingest_event(
		$shipment,
		array(
			'codigo'       => 'BRASPRESS',
			'tipo'         => 'EXTERNAL',
			'descricao'    => 'Braspress: ' . $external_status,
			'dtHrCriado'   => '',
			'raw_response' => $response,
		),
		PAPELITO_BRASPRESS_TRACKING_SOURCE
	);
	$wpdb->update(
		papelito_tracking_shipments_table_name(),
		array(
			'external_status' => $external_status,
			'last_error_code' => null,
			'updated_at'      => current_time( 'mysql', true ),
		),
		array( 'id' => $shipment_id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);
	papelito_tracking_schedule_next_poll( $shipment_id, false );
}

/**
 * Recebe a confirmação de postagem externa feita pelo vendor.
 *
 * @param WP_REST_Request $request Requisição autenticada do painel do vendor.
 * @return WP_REST_Response|WP_Error Resultado da criação ou erro.
 */
function papelito_braspress_tracking_rest_register( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_vendor_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$result = papelito_braspress_tracking_register_external_shipment(
		$order,
		get_current_user_id(),
		(string) $request->get_param( 'external_order_number' ),
		(string) $request->get_param( 'posted_at' )
	);

	return papelito_tracking_rest_result( $result, 201 );
}

/**
 * Registra a rota de confirmação de postagem por número de pedido.
 *
 * @return void
 */
function papelito_braspress_tracking_register_routes(): void {
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/shipments/braspress',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_tracking_rest_seller_permission',
			'callback'            => 'papelito_braspress_tracking_rest_register',
			'args'                => array(
				'external_order_number' => array(
					'type'     => 'string',
					'required' => true,
				),
				'posted_at'             => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
}

add_action( 'rest_api_init', 'papelito_braspress_tracking_register_routes' );
