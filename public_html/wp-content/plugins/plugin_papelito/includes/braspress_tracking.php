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

require_once __DIR__ . '/braspress.php';

const PAPELITO_BRASPRESS_TRACKING_PATH   = 'v3/tracking/byNumPedido';
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
	if ( '' === $reference || 14 !== strlen( $tomador ) ) {
		return new WP_Error( 'papelito_braspress_tracking_invalid_context', 'A consulta de tracking Braspress não está disponível.', array( 'status' => 503 ) );
	}

	$path    = PAPELITO_BRASPRESS_TRACKING_PATH . '/' . rawurlencode( $tomador ) . '/' . rawurlencode( $reference ) . '/json';
	$started = microtime( true );
	$response = papelito_braspress_http_request( $integration, 'GET', $path );
	$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
	if ( is_wp_error( $response ) ) {
		return papelito_braspress_handle_transport_error( $integration, $response, 'tracking', $duration );
	}

	return $response['body'];
}

/**
 * Converte uma data da Braspress para o instante UTC que a tabela guarda.
 *
 * A Braspress publica `dd/MM/yyyy` e `dd/MM/yyyy HH:mm` em America/Sao_Paulo.
 * O parser genérico do PHP lê barra como formato americano, então `01/02/2026`
 * viraria 2 de janeiro em silêncio; por isso o formato é exigido, e não inferido.
 * O fuso é fixo no código porque descreve o dado recebido, não o servidor que o
 * está lendo — em produção o servidor não roda em São Paulo.
 *
 * @param mixed $value Data publicada pela Braspress.
 * @return string|null Datetime MySQL em UTC, ou null quando não é uma das formas do contrato.
 */
function papelito_braspress_tracking_parse_datetime( $value ): ?string {
	$text = trim( sanitize_text_field( (string) $value ) );

	if ( '' === $text ) {
		return null;
	}

	$timezone = new DateTimeZone( 'America/Sao_Paulo' );

	foreach ( array( 'd/m/Y H:i', 'd/m/Y' ) as $format ) {
		$parsed = DateTimeImmutable::createFromFormat( '!' . $format, $text, $timezone );

		if ( false === $parsed || $parsed->format( $format ) !== $text ) {
			continue;
		}

		return $parsed->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	return null;
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
 * Lê descrição e data de uma ocorrência, tolerando os nomes que a Braspress usa.
 *
 * A documentação oficial garante que cada item de `timeline[]` e `ocorrencias[]`
 * traz descrição e data, mas não fixa o nome do campo, e nenhuma remessa real
 * existia para conferir. Por isso os candidatos são tentados em ordem, como já
 * acontece na leitura do status externo.
 *
 * @param array<string,mixed> $entry Item de timeline ou ocorrência.
 * @return array{descricao:string,event_at:?string}|null Ocorrência legível, ou null sem descrição.
 */
function papelito_braspress_tracking_read_occurrence( array $entry ): ?array {
	$description = '';
	foreach ( array( 'descricao', 'descricaoOcorrencia', 'ocorrencia', 'status', 'situacao' ) as $field ) {
		if ( isset( $entry[ $field ] ) && is_scalar( $entry[ $field ] ) ) {
			$description = trim( sanitize_text_field( (string) $entry[ $field ] ) );
			if ( '' !== $description ) {
				break;
			}
		}
	}

	if ( '' === $description ) {
		return null;
	}

	$event_at = null;
	foreach ( array( 'data', 'dataHora', 'dataOcorrencia', 'dtOcorrencia', 'dataHoraOcorrencia' ) as $field ) {
		if ( ! isset( $entry[ $field ] ) || ! is_scalar( $entry[ $field ] ) ) {
			continue;
		}
		$event_at = papelito_braspress_tracking_parse_datetime( $entry[ $field ] );
		if ( null !== $event_at ) {
			break;
		}
	}

	return array(
		'descricao' => $description,
		'event_at'  => $event_at,
	);
}

/**
 * Reduz a resposta v3 inteira a uma linha do tempo, em ordem cronológica.
 *
 * Um pedido pode viajar em vários conhecimentos, e ler só o primeiro perderia
 * as caixas restantes. `timeline[]` e `ocorrencias[]` repetem a mesma ocorrência
 * com frequência, então a mesma trinca conhecimento/data/descrição entra uma vez.
 * Ocorrência sem data legível é preservada e vai para o fim: perder o texto seria
 * pior do que exibi-lo sem quando.
 *
 * @param array<string,mixed> $response Corpo devolvido pela Braspress.
 * @return array<int,array<string,mixed>> Eventos normalizados, do mais antigo ao mais novo.
 */
function papelito_braspress_tracking_events( array $response ): array {
	$conhecimentos = $response['conhecimentos'] ?? null;

	if ( ! is_array( $conhecimentos ) ) {
		return array();
	}

	$events = array();

	foreach ( $conhecimentos as $conhecimento ) {
		if ( ! is_array( $conhecimento ) ) {
			continue;
		}

		$numero = sanitize_text_field( (string) ( $conhecimento['numero'] ?? '' ) );

		foreach ( array( 'ocorrencias', 'timeline' ) as $collection ) {
			foreach ( (array) ( $conhecimento[ $collection ] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$occurrence = papelito_braspress_tracking_read_occurrence( $entry );

				if ( null === $occurrence ) {
					continue;
				}

				$key = $numero . '|' . (string) $occurrence['event_at'] . '|' . $occurrence['descricao'];

				$events[ $key ] = array(
					'codigo'       => 'BRASPRESS',
					'tipo'         => 'OCOR',
					'descricao'    => $occurrence['descricao'],
					'event_at'     => $occurrence['event_at'],
					'conhecimento' => $numero,
				);
			}
		}
	}

	return papelito_braspress_tracking_sort_events( array_values( $events ) );
}

/**
 * Ordena do mais antigo ao mais novo, empurrando o que não tem data para o fim.
 *
 * @param array<int,array<string,mixed>> $events Eventos normalizados.
 * @return array<int,array<string,mixed>> Eventos em ordem cronológica.
 */
function papelito_braspress_tracking_sort_events( array $events ): array {
	usort(
		$events,
		static function ( array $first, array $second ): int {
			if ( null === $first['event_at'] || null === $second['event_at'] ) {
				return ( null === $first['event_at'] ? 1 : 0 ) - ( null === $second['event_at'] ? 1 : 0 );
			}

			return strcmp( $first['event_at'], $second['event_at'] );
		}
	);

	return $events;
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
 * Reconcilia a linha do tempo da Braspress preservando `posted` sem mapa de status.
 *
 * Cada ocorrência vira um evento datado e idempotente. Enquanto o modelo não
 * representa conhecimento por volume — bloqueado na BRASPRESS-008 pela
 * cardinalidade não confirmada —, duas caixas com a mesma ocorrência no mesmo
 * minuto colapsam num evento só, o que a chave idempotente faria de qualquer forma.
 * Sem nenhuma ocorrência legível, o texto de status externo ainda registra que
 * houve atualização.
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
	$events          = papelito_braspress_tracking_events( $response );

	if ( empty( $events ) ) {
		$events = array(
			array(
				'codigo'    => 'BRASPRESS',
				'tipo'      => 'EXTERNAL',
				'descricao' => 'Braspress: ' . $external_status,
				'event_at'  => null,
			),
		);
	}

	foreach ( $events as $event ) {
		papelito_tracking_ingest_event(
			$shipment,
			array(
				'codigo'       => $event['codigo'],
				'tipo'         => $event['tipo'],
				'descricao'    => $event['descricao'],
				'dtHrCriado'   => (string) $event['event_at'],
				'event_at'     => $event['event_at'],
				'conhecimento' => $event['conhecimento'] ?? '',
			),
			PAPELITO_BRASPRESS_TRACKING_SOURCE
		);
	}
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
