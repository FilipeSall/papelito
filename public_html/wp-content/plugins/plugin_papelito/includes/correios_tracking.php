<?php
/**
 * Rastreamento confiavel de envios pelos Correios.
 *
 * A API Rastro e a unica fonte capaz de concluir uma entrega. A integracao de
 * pre-postagem fica atras de um adapter server-side porque o schema contratado
 * e publicado apenas no CWS autenticado dos Correios.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_TRACKING_SHIPMENTS_TABLE' ) ) {
	define( 'PAPELITO_TRACKING_SHIPMENTS_TABLE', 'papelito_shipments' );
	define( 'PAPELITO_TRACKING_EVENTS_TABLE', 'papelito_tracking_events' );
	define( 'PAPELITO_TRACKING_POLL_HOOK', 'papelito_correios_tracking_poll_due' );
	define( 'PAPELITO_PREPOST_RECONCILE_HOOK', 'papelito_correios_prepostage_reconcile_due' );
	define( 'PAPELITO_TRACKING_SOURCE_POLL', 'correios_poll' );
	define( 'PAPELITO_TRACKING_SOURCE_LOCAL_SIMULATION', 'local_simulation' );
}

if ( ! defined( 'PAPELITO_REST_NAMESPACE' ) ) {
	define( 'PAPELITO_REST_NAMESPACE', 'papelito/v1' );
}

if ( ! defined( 'PAPELITO_TRACKING_MYSQL_DATETIME' ) ) {
	define( 'PAPELITO_TRACKING_MYSQL_DATETIME', 'Y-m-d H:i:s' );
	define( 'PAPELITO_TRACKING_POSTED_AT_PATTERN', '/^\d{4}-\d{2}-\d{2}$/' );
	define( 'PAPELITO_TRACKING_PRIVATE_LABEL_HEADER', 'X-Papelito-Private-Label' );
	define( 'PAPELITO_TRACKING_MSG_INVALID_ORDER', 'Pedido invalido.' );
	define( 'PAPELITO_TRACKING_MSG_ORDER_NOT_READY', 'O pedido precisa estar pago e em separacao.' );
	define( 'PAPELITO_TRACKING_MSG_INVALID_CODE', 'Informe um codigo de rastreamento S10 valido.' );
	define( 'PAPELITO_TRACKING_MSG_LABEL_STORAGE_FAILED', 'Nao foi possivel armazenar a etiqueta.' );
	define( 'PAPELITO_TRACKING_MSG_LABEL_STORAGE_UNAVAILABLE', 'Nao foi possivel preparar o armazenamento privado da etiqueta.' );
}

/** Abre a transacao usada pelas secoes que serializam envios com SELECT ... FOR UPDATE. */
function papelito_tracking_begin_transaction(): void {
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
}

/** Resolve o nome da tabela de envios. */
function papelito_tracking_shipments_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . PAPELITO_TRACKING_SHIPMENTS_TABLE;
}

/** Resolve o nome da tabela de eventos. */
function papelito_tracking_events_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . PAPELITO_TRACKING_EVENTS_TABLE;
}

/** Cria as tabelas de envios e eventos brutos. */
function papelito_tracking_install_tables(): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$shipments       = papelito_tracking_shipments_table_name();
	$events          = papelito_tracking_events_table_name();

	$sql_shipments = "CREATE TABLE {$shipments} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  direction VARCHAR(12) NOT NULL DEFAULT 'outbound',
  provider VARCHAR(24) NOT NULL DEFAULT 'correios',
  generation_status VARCHAR(24) NOT NULL DEFAULT 'generated',
  creation_outcome VARCHAR(24) NOT NULL DEFAULT 'created',
  reconciliation_status VARCHAR(24) NOT NULL DEFAULT 'none',
  reconciliation_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_reconciliation_at DATETIME NULL DEFAULT NULL,
  support_review_required TINYINT(1) NOT NULL DEFAULT 0,
  manual_fallback_eligible TINYINT(1) NOT NULL DEFAULT 0,
  manual_fallback_consumed_at DATETIME NULL DEFAULT NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  idempotency_key CHAR(64) NULL DEFAULT NULL,
  tracking_code VARCHAR(32) NULL DEFAULT NULL,
  external_reference VARCHAR(96) NULL DEFAULT NULL,
  prepost_id VARCHAR(64) NULL DEFAULT NULL,
  service_code VARCHAR(20) NULL DEFAULT NULL,
  posted_at DATE NULL DEFAULT NULL,
  label_storage_key VARCHAR(191) NULL DEFAULT NULL,
  label_sha256 CHAR(64) NULL DEFAULT NULL,
  label_created_at DATETIME NULL DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'tracking_pending',
  status_rank SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  external_status VARCHAR(96) NULL DEFAULT NULL,
  last_event_code VARCHAR(12) NULL DEFAULT NULL,
  last_event_type VARCHAR(12) NULL DEFAULT NULL,
  last_event_at DATETIME NULL DEFAULT NULL,
  last_event_description TEXT NULL,
  last_event_location VARCHAR(255) NULL DEFAULT NULL,
  delivered_at DATETIME NULL DEFAULT NULL,
  next_poll_at DATETIME NULL DEFAULT NULL,
  poll_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code VARCHAR(64) NULL DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_idempotency_key (idempotency_key),
  UNIQUE KEY uq_tracking_code (tracking_code),
  KEY idx_provider_external_reference (provider, external_reference),
  UNIQUE KEY uq_prepost_id (prepost_id),
  KEY idx_order_active (order_id, active),
  KEY idx_vendor_order (vendor_id, order_id),
  KEY idx_poll_due (active, next_poll_at),
  KEY idx_reconciliation_due (active, generation_status, next_reconciliation_at)
) {$charset_collate};";

	$sql_events = "CREATE TABLE {$events} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  event_key CHAR(64) NOT NULL,
  source VARCHAR(24) NOT NULL,
  event_code VARCHAR(12) NULL DEFAULT NULL,
  event_type VARCHAR(12) NULL DEFAULT NULL,
  event_at DATETIME NULL DEFAULT NULL,
  description TEXT NULL,
  location VARCHAR(255) NULL DEFAULT NULL,
  raw_payload LONGTEXT NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_event_key (event_key),
  KEY idx_shipment_event (shipment_id, event_at),
  KEY idx_received (received_at)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_shipments );
	dbDelta( $sql_events );
}

/**
 * Normaliza e valida um codigo S10 dos Correios.
 *
 * @param mixed $value Codigo informado.
 */
function papelito_tracking_normalize_code( $value ): string {
	$code = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( (string) $value ) ) );
	return 1 === preg_match( '/^[A-Z]{2}\d{9}[A-Z]{2}$/', $code ) ? $code : '';
}

/** Catalogo publico dos erros que podem liberar cadastro manual. */
function papelito_tracking_manual_fallback_error_catalog(): array {
	return array(
		'papelito_correios_integration_not_configured' => array( 'category' => 'not_configured', 'message' => 'A geracao automatica ainda nao esta configurada.' ),
		'papelito_correios_provider_not_implemented'   => array( 'category' => 'not_configured', 'message' => 'A integracao de Pre-Postagem ainda nao esta disponivel.' ),
		'papelito_correios_credentials_invalid'        => array( 'category' => 'invalid_credentials', 'message' => 'As credenciais dos Correios precisam ser atualizadas pelo suporte.' ),
		'papelito_correios_service_not_authorized'     => array( 'category' => 'not_authorized', 'message' => 'A chave configurada nao tem permissao para gerar etiquetas.' ),
		'papelito_correios_service_not_contracted'     => array( 'category' => 'not_contracted', 'message' => 'O contrato ou cartao nao possui a API de Pre-Postagem.' ),
		'papelito_correios_data_incomplete'            => array( 'category' => 'invalid_order', 'message' => 'O pedido nao possui todos os dados obrigatorios.' ),
		'papelito_correios_validation_failed'          => array( 'category' => 'validation', 'message' => 'Os Correios rejeitaram os dados da postagem.' ),
		'papelito_correios_rate_limited'               => array( 'category' => 'temporarily_unavailable', 'message' => 'Os Correios limitaram temporariamente as solicitacoes.' ),
		'papelito_correios_unavailable'                => array( 'category' => 'temporarily_unavailable', 'message' => 'O servico dos Correios esta temporariamente indisponivel.' ),
		'papelito_correios_dev_health_unhealthy'       => array( 'category' => 'dev_health_unhealthy', 'message' => 'A verificacao local indicou que a integracao nao esta disponivel.' ),
		'papelito_correios_dev_health_unknown'         => array( 'category' => 'dev_health_unknown', 'message' => 'Nao foi possivel confirmar a saude da integracao no teste local.' ),
		'papelito_label_storage_failed'                => array( 'category' => 'storage', 'message' => PAPELITO_TRACKING_MSG_LABEL_STORAGE_FAILED ),
		'papelito_label_storage_unavailable'           => array( 'category' => 'storage', 'message' => PAPELITO_TRACKING_MSG_LABEL_STORAGE_UNAVAILABLE ),
		'papelito_support_manual_release'              => array( 'category' => 'support_release', 'message' => 'O suporte liberou o cadastro manual depois de revisar a tentativa anterior.' ),
	);
}

/** Confirma se codigo e contexto permitem fallback sem risco de duplicidade. */
function papelito_tracking_error_allows_manual_fallback( string $code, string $outcome ): bool {
	if ( 'not_created' !== $outcome || ! function_exists( 'papelito_correios_manual_tracking_enabled' ) || ! papelito_correios_manual_tracking_enabled() ) {
		return false;
	}
	$catalog = papelito_tracking_manual_fallback_error_catalog();
	if ( ! isset( $catalog[ $code ] ) ) {
		return false;
	}
	if ( 0 === strpos( $code, 'papelito_correios_dev_health_' ) ) {
		return function_exists( 'papelito_correios_prepostage_is_test_environment' ) && papelito_correios_prepostage_is_test_environment();
	}
	return true;
}

/** Busca a tentativa automatica elegivel, opcionalmente bloqueando-a. */
function papelito_tracking_manual_fallback_attempt( int $order_id, int $vendor_id = 0, bool $for_update = false ): ?array {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$sql   = "SELECT * FROM {$table} WHERE order_id = %d AND provider <> 'manual' AND generation_status = 'failed' AND creation_outcome = 'not_created' AND manual_fallback_eligible = 1 AND active = 0";
	$args  = array( $order_id );
	if ( $vendor_id > 0 ) {
		$sql   .= ' AND vendor_id = %d';
		$args[] = $vendor_id;
	}
	$sql .= ' ORDER BY id DESC LIMIT 1';
	if ( $for_update ) {
		$sql .= ' FOR UPDATE';
	}
	$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return is_array( $row ) ? $row : null;
}

/** Reconstrui um erro publico persistido sem reutilizar mensagem externa. */
function papelito_tracking_manual_fallback_error_from_row( array $row ): WP_Error {
	$code    = sanitize_key( (string) ( $row['last_error_code'] ?? '' ) );
	$catalog = papelito_tracking_manual_fallback_error_catalog();
	$item    = $catalog[ $code ] ?? array( 'category' => 'unknown', 'message' => 'Não foi possível gerar a etiqueta automaticamente.' );
	return new WP_Error( $code ?: 'papelito_correios_generation_failed', $item['message'], array( 'status' => 409, 'category' => $item['category'], 'retryable' => false, 'creation_outcome' => 'not_created', 'manual_fallback_available' => true ) );
}

/** Escreve um log estruturado sem credenciais ou dados sensiveis. */
function papelito_tracking_log( string $event, array $context = array() ): void {
	$allowed = array(
		'order_id',
		'shipment_id',
		'vendor_id',
		'idempotency_key',
		'previous_status',
		'new_status',
		'creation_outcome',
		'reconciliation_status',
		'provider',
		'error_code',
		'origin',
	);
	$payload = array( 'event' => sanitize_key( $event ) );
	foreach ( $allowed as $key ) {
		if ( isset( $context[ $key ] ) && '' !== (string) $context[ $key ] ) {
			$payload[ $key ] = sanitize_text_field( (string) $context[ $key ] );
		}
	}
	error_log( 'papelito_tracking ' . wp_json_encode( $payload ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Mapeia somente combinacoes confirmadas no manual oficial publico.
 * Combinacoes adicionais devem vir do schema oficial habilitado no contrato.
 *
 * @return array{status:string,rank:int,terminal:bool}|null
 */
function papelito_tracking_map_event( string $code, string $type ): ?array {
	$key = strtoupper( $code ) . '/' . strtoupper( $type );
	$map = array(
		'PO/01'  => array( 'status' => 'posted', 'rank' => 30, 'terminal' => false ),
		'RO/01'  => array( 'status' => 'in_transit', 'rank' => 40, 'terminal' => false ),
		'OEC/03' => array( 'status' => 'out_for_delivery', 'rank' => 50, 'terminal' => false ),
		'BDE/01' => array( 'status' => 'delivered', 'rank' => 100, 'terminal' => true ),
	);

	$map = apply_filters( 'papelito_correios_tracking_event_map', $map );
	if ( ! is_array( $map ) || ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
		return null;
	}

	$item = $map[ $key ];
	if ( empty( $item['status'] ) || ! isset( $item['rank'] ) ) {
		return null;
	}

	return array(
		'status'   => sanitize_key( (string) $item['status'] ),
		'rank'     => max( 0, absint( $item['rank'] ) ),
		'terminal' => ! empty( $item['terminal'] ),
	);
}

function papelito_tracking_simulation_event_definitions(): array {
	return array(
		'posted'           => array( 'code' => 'PO', 'type' => '01', 'offset_minutes' => 0, 'description' => 'Objeto de teste postado.' ),
		'in_transit'       => array( 'code' => 'RO', 'type' => '01', 'offset_minutes' => 1, 'description' => 'Objeto de teste em transito.' ),
		'out_for_delivery' => array( 'code' => 'OEC', 'type' => '03', 'offset_minutes' => 2, 'description' => 'Objeto de teste em rota de entrega.' ),
		'delivered'        => array( 'code' => 'BDE', 'type' => '01', 'offset_minutes' => 3, 'description' => 'Objeto de teste entregue ao destinatario.' ),
	);
}

function papelito_tracking_simulation_fixture_event( string $status, DateTimeImmutable $started_at ): ?array {
	$definitions = papelito_tracking_simulation_event_definitions();
	$status      = sanitize_key( $status );
	if ( ! isset( $definitions[ $status ] ) ) {
		return null;
	}

	$definition = $definitions[ $status ];
	$event_at   = $started_at->modify( '+' . absint( $definition['offset_minutes'] ) . ' minutes' );
	return array(
		'codigo'      => $definition['code'],
		'tipo'        => $definition['type'],
		'dtHrCriado'  => $event_at->format( DATE_ATOM ),
		'descricao'   => $definition['description'],
		'unidade'     => array(
			'endereco' => array(
				'cidade' => 'AMBIENTE LOCAL',
				'uf'     => 'DEV',
			),
		),
	);
}

function papelito_tracking_simulation_started_at( array $shipment, string $value = '' ): ?DateTimeImmutable {
	$candidate = '' !== trim( $value ) ? trim( $value ) : sanitize_text_field( (string) ( $shipment['created_at'] ?? '' ) );
	if ( '' === $candidate ) {
		return null;
	}

	try {
		return ( new DateTimeImmutable( $candidate, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	} catch ( Exception $exception ) {
		return null;
	}
}

function papelito_tracking_simulation_apply_sequence( array $shipment, array $sequence, DateTimeImmutable $started_at ): array {
	$results = array();
	foreach ( $sequence as $status ) {
		$event = papelito_tracking_simulation_fixture_event( (string) $status, $started_at );
		if ( ! is_array( $event ) ) {
			continue;
		}
		$results[] = array(
			'status'   => sanitize_key( (string) $status ),
			'event'    => $event,
			'ingested' => papelito_tracking_ingest_event( $shipment, $event, PAPELITO_TRACKING_SOURCE_LOCAL_SIMULATION ),
		);
	}
	return $results;
}

function papelito_tracking_simulation_sequence_until( string $state ): array {
	$states = array_keys( papelito_tracking_simulation_event_definitions() );
	$index  = array_search( sanitize_key( $state ), $states, true );
	return false === $index ? array() : array_slice( $states, 0, $index + 1 );
}

function papelito_tracking_simulation_shipments( int $order_id ): array {
	return array_values(
		array_filter(
			papelito_tracking_order_shipments( $order_id ),
			static fn( array $shipment ): bool => 'outbound' === sanitize_key( (string) ( $shipment['direction'] ?? '' ) )
		)
	);
}

function papelito_tracking_simulation_mark_as_test( array $shipment ): array {
	global $wpdb;
	if ( ! empty( $shipment['is_test'] ) ) {
		return $shipment;
	}

	$wpdb->update(
		papelito_tracking_shipments_table_name(),
		array(
			'is_test'      => 1,
			'next_poll_at' => null,
			'updated_at'   => current_time( 'mysql', true ),
		),
		array( 'id' => absint( $shipment['id'] ?? 0 ) )
	);
	$shipment['is_test']      = 1;
	$shipment['next_poll_at'] = null;
	return $shipment;
}

/**
 * Converte data ISO da API para UTC MySQL sem depender do timezone do host.
 *
 * @param mixed $value Data recebida da API.
 */
function papelito_tracking_event_datetime( $value ): ?string {
	$text = sanitize_text_field( (string) $value );
	if ( '' === $text ) {
		return null;
	}

	try {
		$date = new DateTimeImmutable( $text, wp_timezone() );
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( PAPELITO_TRACKING_MYSQL_DATETIME );
	} catch ( Exception $exception ) {
		return null;
	}
}

/** Extrai localidade sem persistir informacoes pessoais do recebedor. */
function papelito_tracking_event_location( array $event ): string {
	$address = isset( $event['unidade']['endereco'] ) && is_array( $event['unidade']['endereco'] )
		? $event['unidade']['endereco']
		: array();
	$parts   = array_filter(
		array(
			sanitize_text_field( (string) ( $address['cidade'] ?? '' ) ),
			sanitize_text_field( (string) ( $address['uf'] ?? '' ) ),
		)
	);
	return implode( ' - ', $parts );
}

/** Produz uma chave idempotente estavel para um evento. */
function papelito_tracking_event_key( int $shipment_id, array $event ): string {
	$data = array(
		$shipment_id,
		strtoupper( sanitize_key( (string) ( $event['codigo'] ?? '' ) ) ),
		strtoupper( sanitize_key( (string) ( $event['tipo'] ?? '' ) ) ),
		sanitize_text_field( (string) ( $event['dtHrCriado'] ?? '' ) ),
		sanitize_text_field( (string) ( $event['descricao'] ?? '' ) ),
		papelito_tracking_event_location( $event ),
	);
	return hash( 'sha256', implode( '|', $data ) );
}

/** Busca os envios de um pedido. */
function papelito_tracking_order_shipments( int $order_id, bool $include_inactive = false, string $direction = 'outbound' ): array {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$sql   = "SELECT * FROM {$table} WHERE order_id = %d";
	$args  = array( $order_id );
	if ( '' !== $direction ) {
		$sql .= ' AND direction = %s';
		$args[] = sanitize_key( $direction );
	}
	if ( ! $include_inactive ) {
		$sql .= ' AND active = 1';
	}
	$sql .= ' ORDER BY id ASC';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return is_array( $rows ) ? $rows : array();
}

/** Monta o read model publico de um envio. */
function papelito_tracking_public_shipment( array $shipment ): array {
	return array(
		'id'              => absint( $shipment['id'] ?? 0 ),
		'provider'        => sanitize_key( (string) ( $shipment['provider'] ?? 'correios' ) ),
		'is_test'         => ! empty( $shipment['is_test'] ),
		'generation_status' => sanitize_key( (string) ( $shipment['generation_status'] ?? 'generated' ) ),
		'creation_outcome' => sanitize_key( (string) ( $shipment['creation_outcome'] ?? 'created' ) ),
		'reconciliation_status' => sanitize_key( (string) ( $shipment['reconciliation_status'] ?? 'none' ) ),
		'reconciliation_attempts' => absint( $shipment['reconciliation_attempts'] ?? 0 ),
		'next_reconciliation_at' => (string) ( $shipment['next_reconciliation_at'] ?? '' ),
		'support_review_required' => ! empty( $shipment['support_review_required'] ),
		'tracking_code'   => sanitize_text_field( (string) ( $shipment['tracking_code'] ?? '' ) ),
		'external_reference' => sanitize_text_field( (string) ( $shipment['external_reference'] ?? '' ) ),
		'service_code'    => sanitize_text_field( (string) ( $shipment['service_code'] ?? '' ) ),
		'posted_at'       => (string) ( $shipment['posted_at'] ?? '' ),
		'status'          => sanitize_key( (string) ( $shipment['status'] ?? 'tracking_pending' ) ),
		'external_status' => sanitize_text_field( (string) ( $shipment['external_status'] ?? '' ) ),
		'last_event_code' => sanitize_text_field( (string) ( $shipment['last_event_code'] ?? '' ) ),
		'last_event_type' => sanitize_text_field( (string) ( $shipment['last_event_type'] ?? '' ) ),
		'last_event_at'   => (string) ( $shipment['last_event_at'] ?? '' ),
		'last_event_description' => sanitize_text_field( (string) ( $shipment['last_event_description'] ?? '' ) ),
		'last_event_location' => sanitize_text_field( (string) ( $shipment['last_event_location'] ?? '' ) ),
		'delivered_at'    => (string) ( $shipment['delivered_at'] ?? '' ),
		'has_error'       => ! empty( $shipment['last_error_code'] ),
		'label_available' => ! empty( $shipment['label_storage_key'] ),
	);
}

/**
 * Recorte da remessa que o comprador pode ver.
 *
 * A allowlist é explícita porque a remessa carrega chave idempotente, caminho da
 * etiqueta privada, id de pré-postagem e código de erro interno — nada disso é
 * assunto de quem comprou. `provider` e `external_reference` entram: sem eles a
 * conta do comprador não sabe qual transportadora leva o pedido, e uma remessa
 * Braspress, que não tem S10, ficaria sem nenhuma referência de acompanhamento.
 *
 * @param array<string,mixed> $shipment Remessa persistida ou já serializada.
 * @return array<string,mixed> Campos liberados para o comprador.
 */
function papelito_tracking_customer_shipment( array $shipment ): array {
	$allowed = array(
		'id',
		'provider',
		'tracking_code',
		'external_reference',
		'posted_at',
		'status',
		'last_event_at',
		'last_event_description',
		'last_event_location',
		'delivered_at',
	);

	$customer             = array_intersect_key( $shipment, array_flip( $allowed ) );
	$customer['provider'] = sanitize_key( (string) ( $shipment['provider'] ?? 'correios' ) );

	return $customer;
}

/** Ordem logistica usada para escolher o estado mais avancado ainda nao entregue. */
function papelito_tracking_status_ranks(): array {
	return array(
		'tracking_pending' => 0,
		'preposted'        => 10,
		'posted'           => 30,
		'in_transit'       => 40,
		'out_for_delivery' => 50,
		'pickup_available' => 55,
		'delivery_failed'  => 60,
		'returning'        => 70,
		'returned'         => 80,
		'lost'             => 90,
		'delivered'        => 100,
	);
}

/** Retorna a data mais antiga entre a atual e a candidata, ignorando candidata vazia. */
function papelito_tracking_earlier_datetime( string $current, string $candidate ): string {
	if ( '' === $candidate ) {
		return $current;
	}
	return '' === $current || $candidate < $current ? $candidate : $current;
}

/** Retorna a data mais recente entre a atual e a candidata, ignorando candidata vazia. */
function papelito_tracking_later_datetime( string $current, string $candidate ): string {
	return '' !== $candidate && $candidate > $current ? $candidate : $current;
}

/** Resume geracao e reconciliacao das remessas, considerando a tentativa elegivel ao fallback. */
function papelito_tracking_snapshot_generation( array $rows, ?array $fallback_attempt ): array {
	$summary = array(
		'generation_status'       => empty( $rows ) ? 'not_started' : 'generated',
		'creation_outcome'        => empty( $rows ) ? 'not_created' : 'created',
		'reconciliation_status'   => 'none',
		'reconciliation_attempts' => 0,
		'next_reconciliation_at'  => '',
		'support_review_required' => false,
	);
	if ( is_array( $fallback_attempt ) ) {
		$summary['generation_status'] = 'failed';
		$summary['creation_outcome']  = 'not_created';
	}

	foreach ( $rows as $row ) {
		$row_generation = sanitize_key( (string) ( $row['generation_status'] ?? 'generated' ) );
		if ( in_array( $row_generation, array( 'generating', 'uncertain', 'failed' ), true ) ) {
			$summary['generation_status'] = $row_generation;
		}
		$row_reconciliation = sanitize_key( (string) ( $row['reconciliation_status'] ?? 'none' ) );
		if ( 'none' !== $row_reconciliation ) {
			$summary['reconciliation_status'] = $row_reconciliation;
		}
		$summary['support_review_required'] = $summary['support_review_required'] || ! empty( $row['support_review_required'] );
		$summary['creation_outcome']        = sanitize_key( (string) ( $row['creation_outcome'] ?? $summary['creation_outcome'] ) );
		$summary['reconciliation_attempts'] = max( $summary['reconciliation_attempts'], absint( $row['reconciliation_attempts'] ?? 0 ) );
		$summary['next_reconciliation_at']  = papelito_tracking_earlier_datetime( $summary['next_reconciliation_at'], (string) ( $row['next_reconciliation_at'] ?? '' ) );
	}

	return $summary;
}

/** Resume o avanco logistico das remessas: estado atual, conclusao e ultimo evento. */
function papelito_tracking_snapshot_delivery( array $rows ): array {
	$ranks     = papelito_tracking_status_ranks();
	$status    = empty( $rows ) ? 'not_started' : 'tracking_pending';
	$all_done  = ! empty( $rows );
	$latest_at = '';
	$max_rank  = -1;

	foreach ( $rows as $row ) {
		$latest_at  = papelito_tracking_later_datetime( $latest_at, (string) ( $row['last_event_at'] ?? '' ) );
		$row_status = sanitize_key( (string) $row['status'] );
		if ( 'delivered' === $row_status ) {
			continue;
		}
		$all_done = false;
		$row_rank = $ranks[ $row_status ] ?? absint( $row['status_rank'] ?? 0 );
		if ( $row_rank > $max_rank ) {
			$status   = $row_status;
			$max_rank = $row_rank;
		}
	}

	return array(
		'status'    => $all_done ? 'delivered' : $status,
		'all_done'  => $all_done,
		'latest_at' => $latest_at,
	);
}

/** Resume a logistica de um pedido para seller e comprador. */
function papelito_tracking_order_snapshot( int $order_id ): array {
	$rows             = papelito_tracking_order_shipments( $order_id );
	$fallback_attempt = papelito_tracking_manual_fallback_attempt( $order_id );
	$manual_enabled   = function_exists( 'papelito_correios_manual_tracking_enabled' ) && papelito_correios_manual_tracking_enabled();
	$generation       = papelito_tracking_snapshot_generation( $rows, $fallback_attempt );
	$delivery         = papelito_tracking_snapshot_delivery( $rows );

	return array(
		'status'                       => $delivery['status'],
		'generation_status'            => $generation['generation_status'],
		'creation_outcome'             => $generation['creation_outcome'],
		'reconciliation_status'        => $generation['reconciliation_status'],
		'reconciliation_attempts'      => $generation['reconciliation_attempts'],
		'next_reconciliation_at'       => $generation['next_reconciliation_at'],
		'support_review_required'      => $generation['support_review_required'],
		'automatic_generation_enabled' => function_exists( 'papelito_correios_prepostage_readiness' ) && ! is_wp_error( papelito_correios_prepostage_readiness() ),
		'manual_registration_enabled'  => $manual_enabled,
		'manual_fallback_available'    => $manual_enabled && ! $generation['support_review_required'] && is_array( $fallback_attempt ),
		'generation_error_code'        => is_array( $fallback_attempt ) ? sanitize_key( (string) ( $fallback_attempt['last_error_code'] ?? '' ) ) : '',
		'all_packages_done'            => $delivery['all_done'],
		'packages_total'               => count( $rows ),
		'packages_delivered'           => count( array_filter( $rows, static fn( array $row ): bool => 'delivered' === $row['status'] ) ),
		'last_event_at'                => $delivery['latest_at'],
		'shipments'                    => array_map( 'papelito_tracking_public_shipment', $rows ),
	);
}

/** Cria um envio somente a partir de uma fonte confiavel do backend. */
function papelito_tracking_create_shipment( int $order_id, int $vendor_id, array $data ) {
	global $wpdb;

	$provider      = sanitize_key( (string) ( $data['provider'] ?? 'correios' ) );
	$direction     = in_array( sanitize_key( (string) ( $data['direction'] ?? 'outbound' ) ), array( 'outbound', 'reverse' ), true ) ? sanitize_key( (string) ( $data['direction'] ?? 'outbound' ) ) : 'outbound';
	$return_request_id = 'reverse' === $direction ? absint( $data['return_request_id'] ?? 0 ) : 0;
	$is_test       = ! empty( $data['is_test'] );
	$tracking_code = papelito_tracking_normalize_code( $data['tracking_code'] ?? '' );
	$prepost_id    = sanitize_text_field( (string) ( $data['prepost_id'] ?? '' ) );
	$posted_at     = sanitize_text_field( (string) ( $data['posted_at'] ?? '' ) );
	if ( '' === $tracking_code ) {
		return new WP_Error( 'papelito_tracking_invalid_code', PAPELITO_TRACKING_MSG_INVALID_CODE, array( 'status' => 422, 'category' => 'validation', 'retryable' => false ) );
	}

	$inserted = $wpdb->insert(
		papelito_tracking_shipments_table_name(),
		array(
			'order_id'       => $order_id,
			'vendor_id'      => $vendor_id,
			'direction'      => $direction,
			'return_request_id' => $return_request_id ?: null,
			'provider'       => '' !== $provider ? $provider : 'correios',
			'generation_status' => sanitize_key( (string) ( $data['generation_status'] ?? 'generated' ) ),
			'creation_outcome' => 'created',
			'reconciliation_status' => 'none',
			'reconciliation_attempts' => 0,
			'next_reconciliation_at' => null,
			'support_review_required' => 0,
			'manual_fallback_eligible' => 0,
			'is_test'        => $is_test ? 1 : 0,
			'idempotency_key' => ! empty( $data['idempotency_key'] ) ? sanitize_text_field( (string) $data['idempotency_key'] ) : null,
			'tracking_code'  => $tracking_code,
			'prepost_id'     => '' !== $prepost_id ? $prepost_id : null,
			'service_code'   => sanitize_text_field( (string) ( $data['service_code'] ?? '' ) ),
			'posted_at'      => 1 === preg_match( PAPELITO_TRACKING_POSTED_AT_PATTERN, $posted_at ) ? $posted_at : null,
			'status'         => sanitize_key( (string) ( $data['status'] ?? 'preposted' ) ),
			'status_rank'    => absint( $data['status_rank'] ?? 10 ),
			'next_poll_at'   => $is_test ? null : current_time( 'mysql', true ),
			'created_at'     => current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		$status = false !== strpos( strtolower( (string) $wpdb->last_error ), 'duplicate' ) ? 409 : 500;
		return new WP_Error( 'papelito_tracking_shipment_not_created', 'Nao foi possivel associar o envio ao pedido.', array( 'status' => $status ) );
	}

	return absint( $wpdb->insert_id );
}

/**
 * Chave estavel que impede duas criacoes para o mesmo pacote logico.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_generation_idempotency_key( $order, int $vendor_id, string $provider ): string {
	$order_id     = is_object( $order ) && method_exists( $order, 'get_id' ) ? absint( $order->get_id() ) : 0;
	$service_code = is_object( $order ) && method_exists( $order, 'get_meta' )
		? sanitize_text_field( (string) $order->get_meta( '_papelito_shipping_service_code', true ) )
		: '';

	return hash( 'sha256', implode( '|', array( 'shipment-v1', $order_id, $vendor_id, sanitize_key( $provider ), $service_code ) ) );
}

/** Reserva a tentativa no banco antes de qualquer chamada ao provider. */
function papelito_tracking_reserve_generation( int $order_id, int $vendor_id, string $provider, string $idempotency_key ) {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$now   = current_time( 'mysql', true );

	$inserted = $wpdb->insert(
		$table,
		array(
			'order_id'          => $order_id,
			'vendor_id'         => $vendor_id,
			'direction'         => 'outbound',
			'provider'          => sanitize_key( $provider ),
			'generation_status' => 'generating',
			'creation_outcome' => 'uncertain',
			'reconciliation_status' => 'pending',
			'reconciliation_attempts' => 0,
			'next_reconciliation_at' => current_time( 'mysql', true ),
			'support_review_required' => 0,
			'manual_fallback_eligible' => 0,
			'is_test'          => 'mock' === sanitize_key( $provider ) ? 1 : 0,
			'idempotency_key'   => $idempotency_key,
			'status'            => 'tracking_pending',
			'active'            => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		),
		array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
	);
	if ( false !== $inserted ) {
		return array( 'id' => absint( $wpdb->insert_id ), 'replay' => false );
	}

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $idempotency_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! is_array( $row ) ) {
		return new WP_Error( 'papelito_tracking_reservation_failed', 'Nao foi possivel reservar a geracao da etiqueta.', array( 'status' => 500, 'category' => 'internal', 'retryable' => true ) );
	}

	if ( 'failed' === sanitize_key( (string) ( $row['generation_status'] ?? '' ) ) && empty( $row['active'] ) && empty( $row['manual_fallback_eligible'] ) ) {
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET generation_status = 'generating', creation_outcome = 'uncertain', reconciliation_status = 'pending', next_reconciliation_at = %s, active = 1, last_error_code = NULL, support_review_required = 0, updated_at = %s WHERE id = %d AND generation_status = 'failed' AND active = 0 AND manual_fallback_eligible = 0",
				$now,
				$now,
				absint( $row['id'] )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 === $updated ) {
			return array( 'id' => absint( $row['id'] ), 'replay' => false );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $row['id'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	return array( 'id' => absint( $row['id'] ?? 0 ), 'replay' => true, 'row' => is_array( $row ) ? $row : array() );
}

/** Marca uma tentativa como falha segura ou como resultado externo incerto. */
function papelito_tracking_fail_generation( int $shipment_id, WP_Error $error ): bool {
	global $wpdb;
	$data       = $error->get_error_data();
	$outcome    = is_array( $data ) ? sanitize_key( (string) ( $data['creation_outcome'] ?? '' ) ) : '';
	$not_created = 'not_created' === $outcome;
	$eligible    = papelito_tracking_error_allows_manual_fallback( sanitize_key( $error->get_error_code() ), $outcome );
	$wpdb->update(
		papelito_tracking_shipments_table_name(),
		array(
			'generation_status' => $not_created ? 'failed' : 'uncertain',
			'creation_outcome'  => in_array( $outcome, array( 'not_created', 'created' ), true ) ? $outcome : 'uncertain',
			'reconciliation_status' => $not_created ? 'not_needed' : 'pending',
			'next_reconciliation_at' => $not_created ? null : current_time( 'mysql', true ),
			'support_review_required' => 0,
			'manual_fallback_eligible' => $eligible ? 1 : 0,
			'last_error_code'   => sanitize_key( $error->get_error_code() ),
			'active'            => $not_created ? 0 : 1,
			'updated_at'        => current_time( 'mysql', true ),
		),
		array( 'id' => $shipment_id ),
		array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ),
		array( '%d' )
	);
	if ( method_exists( $error, 'add_data' ) ) {
		$error_data = is_array( $data ) ? $data : array();
		$error_data['manual_fallback_available'] = $eligible;
		$error->add_data( $error_data );
	}
	return $eligible;
}

/** Diretorio privado, deliberadamente fora do webroot do WordPress. */
function papelito_tracking_private_labels_dir(): string {
	if ( function_exists( 'papelito_correios_prepostage_config' ) ) {
		$configured = papelito_correios_prepostage_config( 'PAPELITO_PRIVATE_LABELS_DIR', '' );
		if ( '' !== $configured ) {
			return $configured;
		}
	}
	$base = dirname( untrailingslashit( ABSPATH ) ) . '/papelito-private/labels';
	if ( is_dir( dirname( $base ) ) || is_writable( dirname( dirname( $base ) ) ) ) {
		return (string) apply_filters( 'papelito_tracking_private_labels_dir', $base );
	}
	if ( defined( 'WP_CONTENT_DIR' ) ) {
		$base = trailingslashit( WP_CONTENT_DIR ) . 'uploads/papelito-private-labels';
	}
	return (string) apply_filters( 'papelito_tracking_private_labels_dir', $base );
}

/** Tenta bloquear acesso HTTP quando o fallback local cair dentro de uploads. */
function papelito_tracking_harden_private_labels_dir( string $directory ): void {
	if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $directory, trailingslashit( WP_CONTENT_DIR ) . 'uploads/' ) ) {
		$htaccess = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$index = trailingslashit( $directory ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}

/** Persiste um PDF privado e retorna somente sua chave opaca e checksum. */
function papelito_tracking_store_private_label( string $idempotency_key, string $contents, string $provider = '' ) {
	$creation_outcome = 'mock' === sanitize_key( $provider )
		&& function_exists( 'papelito_correios_prepostage_is_test_environment' )
		&& papelito_correios_prepostage_is_test_environment()
		? 'not_created'
		: 'created';
	if ( '' === $contents || 0 !== strpos( $contents, '%PDF-' ) ) {
		return new WP_Error( 'papelito_label_invalid', 'O provider nao retornou um PDF de etiqueta valido.', array( 'status' => 502, 'category' => 'invalid_provider_response', 'creation_outcome' => $creation_outcome ) );
	}
	$directory = papelito_tracking_private_labels_dir();
	if ( ! wp_mkdir_p( $directory ) ) {
		return new WP_Error( 'papelito_label_storage_unavailable', PAPELITO_TRACKING_MSG_LABEL_STORAGE_UNAVAILABLE, array( 'status' => 500, 'category' => 'storage', 'creation_outcome' => $creation_outcome ) );
	}
	papelito_tracking_harden_private_labels_dir( $directory );
	$key  = hash( 'sha256', $idempotency_key . '|label' ) . '.pdf';
	$path = trailingslashit( $directory ) . $key;
	if ( strlen( $contents ) !== file_put_contents( $path, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return new WP_Error( 'papelito_label_storage_failed', PAPELITO_TRACKING_MSG_LABEL_STORAGE_FAILED, array( 'status' => 500, 'category' => 'storage', 'creation_outcome' => $creation_outcome ) );
	}

	return array( 'key' => $key, 'sha256' => hash( 'sha256', $contents ) );
}

/** Conclui atomicamente a reserva com os identificadores devolvidos. */
function papelito_tracking_complete_generation( int $shipment_id, string $provider, array $data, array $label = array() ) {
	global $wpdb;
	$tracking_code = papelito_tracking_normalize_code( $data['tracking_code'] ?? '' );
	if ( '' === $tracking_code ) {
		return new WP_Error( 'papelito_tracking_invalid_code', 'O provider nao retornou um codigo de rastreamento valido.', array( 'status' => 502, 'category' => 'invalid_provider_response', 'creation_outcome' => 'mock' === $provider ? 'not_created' : 'created' ) );
	}

	$updated = $wpdb->update(
		papelito_tracking_shipments_table_name(),
		array(
			'generation_status' => 'generated',
			'creation_outcome'  => 'created',
			'reconciliation_status' => 'resolved_created',
			'next_reconciliation_at' => null,
			'support_review_required' => 0,
			'manual_fallback_eligible' => 0,
			'is_test'           => 'mock' === $provider ? 1 : 0,
			'tracking_code'     => $tracking_code,
			'prepost_id'        => sanitize_text_field( (string) ( $data['prepost_id'] ?? '' ) ),
			'service_code'      => sanitize_text_field( (string) ( $data['service_code'] ?? '' ) ),
			'label_storage_key' => sanitize_file_name( (string) ( $label['key'] ?? '' ) ),
			'label_sha256'      => sanitize_text_field( (string) ( $label['sha256'] ?? '' ) ),
			'label_created_at'  => ! empty( $label ) ? current_time( 'mysql', true ) : null,
			'status'            => 'preposted',
			'status_rank'       => 10,
			'next_poll_at'      => 'mock' === $provider ? null : current_time( 'mysql', true ),
			'last_error_code'   => null,
			'updated_at'        => current_time( 'mysql', true ),
		),
		array( 'id' => $shipment_id )
	);
	if ( 1 !== $updated ) {
		return new WP_Error( 'papelito_tracking_completion_failed', 'Nao foi possivel concluir a geracao da etiqueta.', array( 'status' => 500, 'category' => 'internal' ) );
	}

	return true;
}

/** Monta um erro publico para resultado incerto com o estado atual da verificacao. */
function papelito_tracking_generation_uncertain_error( array $row ): WP_Error {
	return new WP_Error(
		'papelito_correios_generation_uncertain',
		empty( $row['support_review_required'] )
			? 'A solicitação anterior foi enviada e ainda esta sendo verificada para evitar duplicidade.'
			: 'A solicitação anterior precisa de revisão do suporte antes de qualquer nova etiqueta.',
		array(
			'status'                    => 409,
			'category'                  => 'uncertain',
			'retryable'                 => false,
			'creation_outcome'          => sanitize_key( (string) ( $row['creation_outcome'] ?? 'uncertain' ) ),
			'reconciliation_status'     => sanitize_key( (string) ( $row['reconciliation_status'] ?? 'pending' ) ),
			'reconciliation_attempts'   => absint( $row['reconciliation_attempts'] ?? 0 ),
			'next_reconciliation_at'    => (string) ( $row['next_reconciliation_at'] ?? '' ),
			'support_review_required'   => ! empty( $row['support_review_required'] ),
			'manual_fallback_available' => false,
		)
	);
}

/** Agenda nova reconciliacao de uma tentativa incerta com backoff e jitter. */
function papelito_tracking_schedule_next_reconciliation( array $row, string $status, string $error_code = '' ): void {
	global $wpdb;
	$attempts = min( 6, absint( $row['reconciliation_attempts'] ?? 0 ) + 1 );
	$delay    = papelito_tracking_apply_jitter( min( 2 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempts - 1 ) ) ) );
	$wpdb->update(
		papelito_tracking_shipments_table_name(),
		array(
			'reconciliation_status'   => sanitize_key( $status ),
			'reconciliation_attempts' => $attempts,
			'next_reconciliation_at'  => gmdate( PAPELITO_TRACKING_MYSQL_DATETIME, time() + $delay ),
			'last_error_code'         => '' !== $error_code ? sanitize_key( $error_code ) : sanitize_key( (string) ( $row['last_error_code'] ?? '' ) ),
			'updated_at'              => current_time( 'mysql', true ),
		),
		array( 'id' => absint( $row['id'] ) ),
		array( '%s', '%d', '%s', '%s', '%s' ),
		array( '%d' )
	);
}

/** Marca uma tentativa como dependente de revisao humana auditavel. */
function papelito_tracking_mark_support_review_required( array $row, string $reason = 'provider_reconciliation_unavailable' ): void {
	global $wpdb;
	$wpdb->update(
		papelito_tracking_shipments_table_name(),
		array(
			'generation_status'        => 'uncertain',
			'reconciliation_status'    => 'needs_support',
			'next_reconciliation_at'   => null,
			'support_review_required'  => 1,
			'manual_fallback_eligible' => 0,
			'last_error_code'          => sanitize_key( $reason ),
			'updated_at'               => current_time( 'mysql', true ),
		),
		array( 'id' => absint( $row['id'] ) ),
		array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' ),
		array( '%d' )
	);
}

/**
 * Reconstroi deterministicamente uma pre-postagem mock sem chamada externa.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_mock_reconciliation_data( $order, int $vendor_id ) {
	if ( ! function_exists( 'papelito_correios_prepostage_is_test_environment' ) || ! papelito_correios_prepostage_is_test_environment() ) {
		return array( 'status' => 'needs_support', 'reason' => 'mock_reconciliation_forbidden' );
	}
	if ( ! class_exists( 'PapelitoCorreiosMockPrepostageAdapter' ) ) {
		return array( 'status' => 'needs_support', 'reason' => 'mock_adapter_missing' );
	}
	$adapter = new PapelitoCorreiosMockPrepostageAdapter();
	$data    = $adapter->create( $order, $vendor_id );
	if ( is_wp_error( $data ) ) {
		$error_data = $data->get_error_data();
		return array(
			'status' => ! empty( $error_data['creation_outcome'] ) && 'not_created' === sanitize_key( (string) $error_data['creation_outcome'] ) ? 'not_created' : 'still_uncertain',
			'error'  => $data,
		);
	}
	return array( 'status' => 'created', 'data' => $data );
}

/**
 * Bloqueia a tentativa e, se ainda estiver em aberto, marca a verificacao em curso.
 *
 * @return array{row:array,pending:bool}|WP_Error
 */
function papelito_tracking_claim_reconciliation( int $shipment_id ) {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	papelito_tracking_begin_transaction();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $shipment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! is_array( $row ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_reconciliation_attempt_missing', 'Tentativa de envio nao encontrada.', array( 'status' => 404 ) );
	}

	$pending = in_array( sanitize_key( (string) ( $row['generation_status'] ?? '' ) ), array( 'generating', 'uncertain' ), true );
	if ( $pending ) {
		$wpdb->update(
			$table,
			array( 'reconciliation_status' => 'checking', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $shipment_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
	$wpdb->query( 'COMMIT' );

	return array( 'row' => $row, 'pending' => $pending );
}

/** Armazena a etiqueta devolvida na reconciliacao; em falha, encerra ou reagenda a tentativa. */
function papelito_tracking_reconciliation_label( array $row, int $shipment_id, string $provider, array $data ) {
	if ( ! isset( $data['label_contents'] ) ) {
		return array();
	}

	$label = papelito_tracking_store_private_label( sanitize_text_field( (string) ( $row['idempotency_key'] ?? '' ) ), (string) $data['label_contents'], $provider );
	if ( ! is_wp_error( $label ) ) {
		return $label;
	}

	$error_data = $label->get_error_data();
	if ( is_array( $error_data ) && 'not_created' === sanitize_key( (string) ( $error_data['creation_outcome'] ?? '' ) ) ) {
		papelito_tracking_fail_generation( $shipment_id, $label );
	} else {
		papelito_tracking_schedule_next_reconciliation( $row, 'still_uncertain', $label->get_error_code() );
	}
	return $label;
}

/** Conclui a tentativa que o provider confirmou como criada. */
function papelito_tracking_reconciliation_created( array $row, int $shipment_id, string $provider, array $data, array $log_context ) {
	$label = papelito_tracking_reconciliation_label( $row, $shipment_id, $provider, $data );
	if ( is_wp_error( $label ) ) {
		return $label;
	}

	$completed = papelito_tracking_complete_generation( $shipment_id, $provider, $data, $label );
	if ( is_wp_error( $completed ) ) {
		papelito_tracking_schedule_next_reconciliation( $row, 'still_uncertain', $completed->get_error_code() );
		return $completed;
	}
	if ( 'mock' === $provider ) {
		papelito_tracking_apply_test_fixture_status( $shipment_id );
	}
	papelito_tracking_log( 'reconciliation_created', array_merge( $log_context, array( 'previous_status' => $row['generation_status'], 'new_status' => 'generated', 'creation_outcome' => 'created' ) ) );
	return papelito_tracking_order_snapshot( absint( $row['order_id'] ) );
}

/** Encerra a tentativa que o provider confirmou como nao criada. */
function papelito_tracking_reconciliation_not_created( array $row, int $shipment_id, array $result, array $log_context ): array {
	$error = isset( $result['error'] ) && is_wp_error( $result['error'] )
		? $result['error']
		: new WP_Error(
			sanitize_key( (string) ( $result['error_code'] ?? $row['last_error_code'] ?? 'papelito_correios_unavailable' ) ),
			'Os Correios confirmaram que a pre-postagem anterior não foi criada.',
			array( 'status' => 409, 'category' => 'not_created', 'retryable' => true, 'creation_outcome' => 'not_created' )
		);
	papelito_tracking_fail_generation( $shipment_id, $error );
	papelito_tracking_log( 'reconciliation_not_created', array_merge( $log_context, array( 'previous_status' => $row['generation_status'], 'new_status' => 'failed', 'creation_outcome' => 'not_created' ) ) );
	return papelito_tracking_order_snapshot( absint( $row['order_id'] ) );
}

/** Reagenda a tentativa que o provider ainda nao conseguiu confirmar. */
function papelito_tracking_reconciliation_still_uncertain( array $row, array $result ): WP_Error {
	$error = isset( $result['error'] ) && is_wp_error( $result['error'] ) ? $result['error']->get_error_code() : sanitize_key( (string) ( $result['reason'] ?? '' ) );
	papelito_tracking_schedule_next_reconciliation( $row, 'still_uncertain', $error );
	return papelito_tracking_generation_uncertain_error( array_merge( $row, array( 'reconciliation_status' => 'still_uncertain' ) ) );
}

/**
 * Reconcilia uma tentativa cujo resultado externo ficou incerto.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_reconcile_generation( $order, int $vendor_id, array $attempt, string $origin = 'automatic' ) {
	$shipment_id = absint( $attempt['id'] ?? 0 );
	if ( $shipment_id <= 0 ) {
		return new WP_Error( 'papelito_reconciliation_attempt_invalid', 'Tentativa de envio invalida.', array( 'status' => 500 ) );
	}

	$claim = papelito_tracking_claim_reconciliation( $shipment_id );
	if ( is_wp_error( $claim ) ) {
		return $claim;
	}
	$row = $claim['row'];
	if ( ! $claim['pending'] ) {
		return papelito_tracking_order_snapshot( absint( $row['order_id'] ) );
	}

	$provider    = sanitize_key( (string) ( $row['provider'] ?? 'correios' ) );
	$log_context = array( 'order_id' => $row['order_id'], 'shipment_id' => $shipment_id, 'vendor_id' => $vendor_id, 'provider' => $provider, 'origin' => $origin );
	$result      = 'mock' === $provider
		? papelito_tracking_mock_reconciliation_data( $order, $vendor_id )
		: apply_filters( 'papelito_correios_reconcile_prepostage', null, $row, $order, $vendor_id );

	if ( is_wp_error( $result ) ) {
		papelito_tracking_schedule_next_reconciliation( $row, 'still_uncertain', $result->get_error_code() );
		papelito_tracking_log( 'reconciliation_uncertain', array_merge( $log_context, array( 'error_code' => $result->get_error_code() ) ) );
		return papelito_tracking_generation_uncertain_error( array_merge( $row, array( 'reconciliation_status' => 'still_uncertain' ) ) );
	}
	if ( ! is_array( $result ) || empty( $result['status'] ) ) {
		papelito_tracking_mark_support_review_required( $row );
		papelito_tracking_log( 'reconciliation_needs_support', $log_context );
		return papelito_tracking_generation_uncertain_error( array_merge( $row, array( 'reconciliation_status' => 'needs_support', 'support_review_required' => 1 ) ) );
	}

	$status = sanitize_key( (string) $result['status'] );
	if ( 'created' === $status && isset( $result['data'] ) && is_array( $result['data'] ) ) {
		return papelito_tracking_reconciliation_created( $row, $shipment_id, $provider, $result['data'], $log_context );
	}
	if ( 'not_created' === $status ) {
		return papelito_tracking_reconciliation_not_created( $row, $shipment_id, $result, $log_context );
	}
	if ( 'still_uncertain' === $status ) {
		return papelito_tracking_reconciliation_still_uncertain( $row, $result );
	}

	papelito_tracking_mark_support_review_required( $row, sanitize_key( (string) ( $result['reason'] ?? 'reconciliation_needs_support' ) ) );
	return papelito_tracking_generation_uncertain_error( array_merge( $row, array( 'reconciliation_status' => 'needs_support', 'support_review_required' => 1 ) ) );
}

/**
 * Explica por que o pedido nao pode gerar etiqueta agora, ou null quando pode.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_generation_blocker( $order ): ?WP_Error {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return new WP_Error( 'papelito_tracking_order_invalid', PAPELITO_TRACKING_MSG_INVALID_ORDER, array( 'status' => 422 ) );
	}
	if ( function_exists( 'papelito_correios_prepostage_mode' ) && 'disabled' === papelito_correios_prepostage_mode() ) {
		return new WP_Error( 'papelito_correios_prepostage_disabled', 'A geracao automatica de etiqueta esta desabilitada. Informe o rastreamento apos a postagem manual.', array( 'status' => 409 ) );
	}
	$status = function_exists( 'papelito_vendor_dashboard_order_status' )
		? papelito_vendor_dashboard_order_status( $order )
		: '';
	if ( 'em_separacao' !== $status ) {
		return new WP_Error(
			'papelito_tracking_order_not_ready',
			'O pedido precisa estar pago e em separação para gerar a etiqueta.',
			array( 'status' => 409 )
		);
	}
	if ( ! method_exists( $order, 'is_paid' ) || ! $order->is_paid() ) {
		return new WP_Error( 'papelito_tracking_order_not_paid', 'O pagamento precisa estar confirmado antes de gerar a etiqueta.', array( 'status' => 409, 'category' => 'invalid_order', 'retryable' => false ) );
	}
	$wc_status = method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '';
	if ( in_array( $wc_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
		return new WP_Error( 'papelito_tracking_order_closed', 'O pedido nao aceita um novo envio.', array( 'status' => 409 ) );
	}
	return null;
}

/**
 * Responde a uma tentativa ja existente sem criar outra.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_resume_generation( $order, int $vendor_id, array $row, string $generation_status, string $origin ) {
	if ( 'generated' === $generation_status ) {
		return papelito_tracking_order_snapshot( (int) $order->get_id() );
	}
	if ( 'uncertain' === $generation_status ) {
		return papelito_tracking_reconcile_generation( $order, $vendor_id, $row, $origin );
	}
	if ( 'failed' === $generation_status && ! empty( $row['manual_fallback_eligible'] ) ) {
		return papelito_tracking_manual_fallback_error_from_row( $row );
	}
	return new WP_Error( 'papelito_correios_generation_in_progress', 'A etiqueta deste pedido ja esta sendo gerada.', array( 'status' => 409, 'category' => 'in_progress', 'retryable' => true ) );
}

/**
 * Chama o adapter de pre-postagem; em falha, encerra a reserva e devolve o erro.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_request_prepostage( $order, int $vendor_id, int $shipment_id ) {
	if ( function_exists( 'papelito_correios_prepostage_readiness' ) ) {
		$readiness = papelito_correios_prepostage_readiness();
		if ( is_wp_error( $readiness ) ) {
			papelito_tracking_fail_generation( $shipment_id, $readiness );
			return $readiness;
		}
	}

	$result = apply_filters( 'papelito_correios_generate_prepostage', null, $order, $vendor_id );
	if ( is_array( $result ) ) {
		return $result;
	}

	$error = is_wp_error( $result )
		? $result
		: new WP_Error(
			'papelito_correios_provider_not_implemented',
			'A integração de Pre-Postagem ainda não foi conectada ao contrato dos Correios.',
			array( 'status' => 503, 'category' => 'not_configured', 'retryable' => false, 'creation_outcome' => 'not_created' )
		);
	papelito_tracking_fail_generation( $shipment_id, $error );
	return $error;
}

/**
 * Guarda a etiqueta, conclui a reserva e registra a nota do pedido.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_finish_generation( $order, int $shipment_id, string $provider, string $key, array $result ) {
	$label = array();
	if ( isset( $result['label_contents'] ) ) {
		$label = papelito_tracking_store_private_label( $key, (string) $result['label_contents'], $provider );
	}
	$completed = is_wp_error( $label ) ? $label : papelito_tracking_complete_generation( $shipment_id, $provider, $result, $label );
	if ( is_wp_error( $completed ) ) {
		papelito_tracking_fail_generation( $shipment_id, $completed );
		return $completed;
	}

	if ( method_exists( $order, 'add_order_note' ) ) {
		$note = 'mock' === $provider
			? sprintf( 'Etiqueta simulada SEM VALIDADE associada ao envio #%d. Nenhuma chamada aos Correios foi realizada.', $shipment_id )
			: sprintf( 'Pre-postagem Correios associada ao envio #%d.', $shipment_id );
		$order->add_order_note( $note );
		$order->save();
	}
	if ( 'mock' === $provider ) {
		papelito_tracking_apply_test_fixture_status( $shipment_id );
	}

	return papelito_tracking_order_snapshot( (int) $order->get_id() );
}

/**
 * Adapter de pre-postagem. O callback do filtro deve usar exclusivamente o
 * schema oficial do CWS contratado e devolver prepost_id/tracking_code.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_generate_shipment( $order, int $vendor_id ) {
	$blocker = papelito_tracking_generation_blocker( $order );
	if ( null !== $blocker ) {
		return $blocker;
	}
	$existing = papelito_tracking_order_shipments( (int) $order->get_id() );
	if ( ! empty( $existing ) ) {
		return papelito_tracking_resume_generation( $order, $vendor_id, $existing[0], sanitize_key( (string) ( $existing[0]['generation_status'] ?? 'generated' ) ), 'vendor_retry' );
	}

	$provider = function_exists( 'papelito_correios_prepostage_mode' ) && 'mock' === papelito_correios_prepostage_mode() ? 'mock' : 'correios';
	$key      = papelito_tracking_generation_idempotency_key( $order, $vendor_id, $provider );
	$reserved = papelito_tracking_reserve_generation( (int) $order->get_id(), $vendor_id, $provider, $key );
	if ( is_wp_error( $reserved ) ) {
		return $reserved;
	}
	$shipment_id = absint( $reserved['id'] ?? 0 );
	if ( ! empty( $reserved['replay'] ) ) {
		return papelito_tracking_resume_generation( $order, $vendor_id, $reserved['row'], sanitize_key( (string) ( $reserved['row']['generation_status'] ?? 'generating' ) ), 'idempotent_replay' );
	}

	$result = papelito_tracking_request_prepostage( $order, $vendor_id, $shipment_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return papelito_tracking_finish_generation( $order, $shipment_id, $provider, $key, $result );
}

/** Aplica uma fixture local sem consultar a API Rastro. */
function papelito_tracking_apply_test_fixture_status( int $shipment_id ): void {
	global $wpdb;
	if ( ! function_exists( 'papelito_correios_prepostage_is_test_environment' ) || ! papelito_correios_prepostage_is_test_environment() ) {
		return;
	}
	$scenario = function_exists( 'papelito_correios_prepostage_config' )
		? sanitize_key( papelito_correios_prepostage_config( 'PAPELITO_CORREIOS_DEV_TRACKING_SCENARIO', 'preposted' ) )
		: 'preposted';
	$fixtures = array(
		'preposted'  => array( 'status' => 'preposted', 'rank' => 10, 'description' => 'Etiqueta de teste gerada localmente.' ),
		'posted'     => array( 'status' => 'posted', 'rank' => 30, 'description' => 'Objeto de teste postado.' ),
		'in_transit' => array( 'status' => 'in_transit', 'rank' => 40, 'description' => 'Objeto de teste em transito.' ),
		'delivered'  => array( 'status' => 'delivered', 'rank' => 100, 'description' => 'Objeto de teste entregue.' ),
		'cancelled'  => array( 'status' => 'cancelled', 'rank' => 85, 'description' => 'Pre-postagem de teste cancelada.' ),
		'expired'    => array( 'status' => 'expired', 'rank' => 85, 'description' => 'Pre-postagem de teste expirada.' ),
	);
	if ( ! isset( $fixtures[ $scenario ] ) ) {
		$scenario = 'preposted';
	}
	$fixture = $fixtures[ $scenario ];
	$row     = $wpdb->get_row(
		$wpdb->prepare( 'SELECT order_id FROM ' . papelito_tracking_shipments_table_name() . ' WHERE id = %d', $shipment_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->update(
		papelito_tracking_shipments_table_name(),
		array(
			'status'                 => $fixture['status'],
			'status_rank'            => $fixture['rank'],
			'last_event_at'          => current_time( 'mysql', true ),
			'last_event_description' => $fixture['description'],
			'last_event_location'    => 'AMBIENTE LOCAL',
			'delivered_at'           => 'delivered' === $fixture['status'] ? current_time( 'mysql', true ) : null,
			'next_poll_at'           => null,
			'updated_at'             => current_time( 'mysql', true ),
		),
		array( 'id' => $shipment_id, 'is_test' => 1 ),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
		array( '%d', '%d' )
	);
	if ( is_array( $row ) && in_array( $fixture['status'], array( 'posted', 'in_transit', 'out_for_delivery', 'pickup_available', 'delivery_failed', 'returning', 'returned', 'lost', 'delivered' ), true ) ) {
		papelito_tracking_reconcile_order_status( absint( $row['order_id'] ?? 0 ) );
	}
}

/**
 * Valida pedido e dados da postagem manual antes de abrir a transacao.
 *
 * @param WC_Order $order Pedido do vendor.
 * @return array{tracking_code:string,service_code:string,posted_at:string}|WP_Error
 */
function papelito_tracking_manual_registration_input( $order, string $tracking_code, array $manual_data ) {
	if ( ! function_exists( 'papelito_correios_manual_tracking_enabled' ) || ! papelito_correios_manual_tracking_enabled() ) {
		return new WP_Error( 'papelito_manual_tracking_disabled', 'O cadastro manual de rastreamento nao esta habilitado.', array( 'status' => 403, 'category' => 'not_configured', 'retryable' => false ) );
	}
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return new WP_Error( 'papelito_tracking_order_invalid', PAPELITO_TRACKING_MSG_INVALID_ORDER, array( 'status' => 422 ) );
	}
	$status = function_exists( 'papelito_vendor_dashboard_order_status' ) ? papelito_vendor_dashboard_order_status( $order ) : '';
	if ( 'em_separacao' !== $status || ! method_exists( $order, 'is_paid' ) || ! $order->is_paid() ) {
		return new WP_Error( 'papelito_tracking_order_not_ready', PAPELITO_TRACKING_MSG_ORDER_NOT_READY, array( 'status' => 409, 'category' => 'invalid_order', 'retryable' => false ) );
	}
	$normalized = papelito_tracking_normalize_code( $tracking_code );
	if ( '' === $normalized ) {
		return new WP_Error( 'papelito_tracking_invalid_code', PAPELITO_TRACKING_MSG_INVALID_CODE, array( 'status' => 422, 'category' => 'validation', 'retryable' => false ) );
	}

	$service_code = sanitize_text_field( (string) ( $manual_data['service_code'] ?? '' ) );
	if ( '' === $service_code && method_exists( $order, 'get_meta' ) ) {
		$service_code = sanitize_text_field( (string) $order->get_meta( '_papelito_shipping_service_code', true ) );
	}
	if ( '' === $service_code ) {
		return new WP_Error( 'papelito_manual_service_required', 'Informe o servico usado na postagem manual.', array( 'status' => 422, 'category' => 'validation', 'retryable' => false ) );
	}
	$posted_at = sanitize_text_field( (string) ( $manual_data['posted_at'] ?? '' ) );
	if ( 1 !== preg_match( PAPELITO_TRACKING_POSTED_AT_PATTERN, $posted_at ) ) {
		return new WP_Error( 'papelito_manual_posted_at_required', 'Informe a data da postagem ou geracao manual.', array( 'status' => 422, 'category' => 'validation', 'retryable' => false ) );
	}

	return array(
		'tracking_code' => $normalized,
		'service_code'  => $service_code,
		'posted_at'     => $posted_at,
	);
}

/**
 * Registra no pedido a nota e o status "enviado" da postagem manual.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_note_manual_shipment( $order, int $shipment_id, array $input ): void {
	if ( ! method_exists( $order, 'add_order_note' ) ) {
		return;
	}
	$order->add_order_note(
		sprintf(
			'Postagem manual confirmada pelo vendor no envio #%d. Código: %s. Serviço: %s. Data: %s.',
			$shipment_id,
			$input['tracking_code'],
			$input['service_code'],
			$input['posted_at']
		)
	);
	$order->update_meta_data( '_papelito_vendor_status', PAPELITO_VENDOR_STATUS_SHIPPED );
	$order->update_meta_data( '_papelito_vendor_status_source', 'vendor_manual_tracking' );
	$order->save();
}

/**
 * Registra a postagem manual depois que o vendor entregou o pacote aos Correios.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_register_manual_shipment( $order, int $vendor_id, string $tracking_code, array $manual_data = array() ) {
	global $wpdb;
	$input = papelito_tracking_manual_registration_input( $order, $tracking_code, $manual_data );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$order_id = absint( $order->get_id() );
	$table    = papelito_tracking_shipments_table_name();
	papelito_tracking_begin_transaction();
	$active = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND vendor_id = %d AND active = 1 ORDER BY id ASC LIMIT 1 FOR UPDATE", $order_id, $vendor_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( is_array( $active ) ) {
		$wpdb->query( 'ROLLBACK' );
		if ( 'manual' === ( $active['provider'] ?? '' ) && hash_equals( (string) ( $active['tracking_code'] ?? '' ), $input['tracking_code'] ) ) {
			return papelito_tracking_order_snapshot( $order_id );
		}
		return new WP_Error( 'papelito_tracking_shipment_exists', 'O pedido ja possui um envio ativo.', array( 'status' => 409, 'category' => 'duplicate', 'retryable' => false ) );
	}
	$shipment_id = papelito_tracking_create_shipment(
		$order_id,
		$vendor_id,
		array(
			'provider'          => 'manual',
			'tracking_code'     => $input['tracking_code'],
			'service_code'      => $input['service_code'],
			'posted_at'         => $input['posted_at'],
			'generation_status' => 'manual',
			'status'            => 'posted',
			'status_rank'       => 30,
			'idempotency_key'   => hash( 'sha256', implode( '|', array( 'manual-v1', $order_id, $vendor_id, $input['service_code'] ) ) ),
			'is_test'           => false,
		)
	);
	if ( is_wp_error( $shipment_id ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $shipment_id;
	}
	$wpdb->query( 'COMMIT' );
	papelito_tracking_note_manual_shipment( $order, absint( $shipment_id ), $input );
	papelito_tracking_notify_manual_shipment( $order_id, $vendor_id, absint( $shipment_id ), $input['tracking_code'], false );

	return papelito_tracking_order_snapshot( $order_id );
}

/** Bloqueia o envio manual do pedido, restrito ao vendor quando nao for administrador. */
function papelito_tracking_lock_manual_shipment( int $shipment_id, int $order_id, int $vendor_id, bool $is_admin ): ?array {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$sql   = "SELECT * FROM {$table} WHERE id = %d AND order_id = %d AND active = 1";
	$args  = array( $shipment_id, $order_id );
	if ( ! $is_admin ) {
		$sql   .= ' AND vendor_id = %d';
		$args[] = $vendor_id;
	}
	$sql .= ' FOR UPDATE';
	$row  = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return is_array( $row ) ? $row : null;
}

/** Explica por que o envio bloqueado nao aceita correcao, ou null quando aceita. */
function papelito_tracking_manual_update_blocker( ?array $row, bool $is_admin ): ?WP_Error {
	if ( ! is_array( $row ) || 'manual' !== sanitize_key( (string) $row['provider'] ) ) {
		return new WP_Error( 'papelito_tracking_shipment_not_found', 'Envio manual nao encontrado.', array( 'status' => 404 ) );
	}
	if ( 'delivered' === sanitize_key( (string) $row['status'] ) && ! $is_admin ) {
		return new WP_Error( 'papelito_tracking_delivered_locked', 'O rastreamento so pode ser corrigido pelo administrador depois da entrega.', array( 'status' => 409 ) );
	}
	return null;
}

/**
 * Traduz o resultado do UPDATE do codigo manual em erro publico, ou null quando gravou.
 *
 * @param int|false $updated Linhas afetadas pelo UPDATE.
 */
function papelito_tracking_manual_update_error( $updated ): ?WP_Error {
	global $wpdb;
	if ( 0 === $updated ) {
		return new WP_Error( 'papelito_tracking_update_conflict', 'O envio foi atualizado por outra solicitacao. Atualize a pagina e tente novamente.', array( 'status' => 409 ) );
	}
	if ( false !== $updated ) {
		return null;
	}
	if ( false !== strpos( strtolower( (string) $wpdb->last_error ), 'duplicate' ) ) {
		return new WP_Error( 'papelito_tracking_code_already_used', 'Este codigo de rastreamento ja pertence a outro envio.', array( 'status' => 409 ) );
	}
	return new WP_Error( 'papelito_tracking_update_failed', 'Nao foi possivel corrigir o rastreamento.', array( 'status' => 500 ) );
}

/**
 * Registra a correcao do codigo no pedido e avisa as partes.
 *
 * @param WC_Order $order Pedido do envio.
 */
function papelito_tracking_note_manual_correction( $order, array $row, int $vendor_id, string $normalized, bool $is_admin ): void {
	$shipment_id        = absint( $row['id'] );
	$shipment_vendor_id = absint( $row['vendor_id'] );
	if ( $shipment_vendor_id <= 0 ) {
		$shipment_vendor_id = $vendor_id;
	}
	$order->add_order_note( sprintf( 'Código de rastreamento do envio #%d corrigido de %s para %s pelo %s.', $shipment_id, $row['tracking_code'], $normalized, $is_admin ? 'administrador' : 'vendor' ) );
	$order->save();
	if ( $shipment_vendor_id > 0 ) {
		papelito_tracking_notify_manual_shipment( absint( $order->get_id() ), $shipment_vendor_id, $shipment_id, $normalized, true );
	}
}

/**
 * Corrige um codigo manual sem permitir regressao depois da entrega.
 *
 * @param WC_Order $order Pedido do envio.
 */
function papelito_tracking_update_manual_shipment( $order, int $vendor_id, int $shipment_id, string $tracking_code, array $data = array(), bool $is_admin = false ) {
	global $wpdb;
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return new WP_Error( 'papelito_tracking_order_invalid', PAPELITO_TRACKING_MSG_INVALID_ORDER, array( 'status' => 422 ) );
	}
	$normalized = papelito_tracking_normalize_code( $tracking_code );
	if ( '' === $normalized ) {
		return new WP_Error( 'papelito_tracking_invalid_code', PAPELITO_TRACKING_MSG_INVALID_CODE, array( 'status' => 422 ) );
	}
	$table    = papelito_tracking_shipments_table_name();
	$order_id = absint( $order->get_id() );
	papelito_tracking_begin_transaction();
	$row     = papelito_tracking_lock_manual_shipment( $shipment_id, $order_id, $vendor_id, $is_admin );
	$blocker = papelito_tracking_manual_update_blocker( $row, $is_admin );
	if ( null !== $blocker ) {
		$wpdb->query( 'ROLLBACK' );
		return $blocker;
	}
	if ( hash_equals( (string) $row['tracking_code'], $normalized ) ) {
		$wpdb->query( 'ROLLBACK' );
		return papelito_tracking_order_snapshot( $order_id );
	}
	$posted_at = sanitize_text_field( (string) ( $data['posted_at'] ?? $row['posted_at'] ?? '' ) );
	if ( 1 !== preg_match( PAPELITO_TRACKING_POSTED_AT_PATTERN, $posted_at ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_manual_posted_at_required', 'Informe a data da postagem.', array( 'status' => 422 ) );
	}
	// Um novo S10 invalida os eventos do codigo anterior; a regressao para posted e intencional.
	$updated = $wpdb->update(
		$table,
		array(
			'tracking_code'          => $normalized,
			'posted_at'              => $posted_at,
			'status'                 => 'posted',
			'status_rank'            => 30,
			'last_event_code'        => null,
			'last_event_type'        => 'manual',
			'last_event_at'          => current_time( 'mysql', true ),
			'last_event_description' => 'Código de rastreamento corrigido pelo responsável pelo envio.',
			'last_event_location'    => null,
			'next_poll_at'           => current_time( 'mysql', true ),
			'last_error_code'        => null,
			'updated_at'             => current_time( 'mysql', true ),
		),
		array( 'id' => $shipment_id )
	);
	$update_error = papelito_tracking_manual_update_error( $updated );
	if ( null !== $update_error ) {
		$wpdb->query( 'ROLLBACK' );
		return $update_error;
	}
	$wpdb->query( 'COMMIT' );
	papelito_tracking_note_manual_correction( $order, array_merge( $row, array( 'id' => $shipment_id ) ), $vendor_id, $normalized, $is_admin );
	return papelito_tracking_order_snapshot( $order_id );
}

/**
 * Libera fallback manual por decisao auditada do suporte.
 *
 * @param WC_Order $order Pedido com tentativa incerta.
 */
function papelito_tracking_admin_release_manual_fallback( $order, int $admin_id, string $reason, string $evidence ) {
	global $wpdb;
	if ( ! function_exists( 'papelito_correios_manual_tracking_enabled' ) || ! papelito_correios_manual_tracking_enabled() ) {
		return new WP_Error( 'papelito_manual_tracking_disabled', 'O cadastro manual de rastreamento nao esta habilitado.', array( 'status' => 403 ) );
	}
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return new WP_Error( 'papelito_tracking_order_invalid', PAPELITO_TRACKING_MSG_INVALID_ORDER, array( 'status' => 422 ) );
	}
	$reason   = sanitize_textarea_field( $reason );
	$evidence = sanitize_textarea_field( $evidence );
	if ( strlen( $reason ) < 12 || strlen( $evidence ) < 12 ) {
		return new WP_Error( 'papelito_manual_release_reason_required', 'Informe motivo e evidencia da liberacao manual.', array( 'status' => 422 ) );
	}
	$order_id = absint( $order->get_id() );
	$table    = papelito_tracking_shipments_table_name();
	papelito_tracking_begin_transaction();
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND active = 1 AND generation_status = 'uncertain' ORDER BY id DESC LIMIT 1 FOR UPDATE", $order_id ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! is_array( $row ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_manual_release_not_available', 'Nao ha tentativa incerta ativa para liberar.', array( 'status' => 409 ) );
	}
	$updated = $wpdb->update(
		$table,
		array(
			'generation_status'        => 'failed',
			'creation_outcome'         => 'not_created',
			'reconciliation_status'    => 'support_released_manual',
			'next_reconciliation_at'   => null,
			'support_review_required'  => 0,
			'manual_fallback_eligible' => 1,
			'last_error_code'          => 'papelito_support_manual_release',
			'active'                   => 0,
			'updated_at'               => current_time( 'mysql', true ),
		),
		array( 'id' => absint( $row['id'] ) ),
		array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ),
		array( '%d' )
	);
	if ( 1 !== $updated ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_manual_release_conflict', 'A tentativa mudou durante a liberacao.', array( 'status' => 409 ) );
	}
	$wpdb->query( 'COMMIT' );
	if ( method_exists( $order, 'add_order_note' ) ) {
		$order->add_order_note( sprintf( 'Fallback manual liberado pelo suporte (usuário #%d). Motivo: %s. Evidencia: %s', $admin_id, $reason, $evidence ) );
		$order->save();
	}
	papelito_tracking_log( 'manual_fallback_released', array( 'order_id' => $order_id, 'shipment_id' => $row['id'], 'vendor_id' => $row['vendor_id'], 'origin' => 'support' ) );
	return papelito_tracking_order_snapshot( $order_id );
}

/**
 * Reabre somente uma tentativa mock local ainda nao consumida.
 *
 * @param WC_Order $order Pedido do vendor.
 */
function papelito_tracking_retry_local_generation( $order, int $vendor_id ) {
	global $wpdb;
	if ( ! function_exists( 'papelito_correios_prepostage_is_test_environment' ) || ! papelito_correios_prepostage_is_test_environment() || 'mock' !== papelito_correios_prepostage_mode() ) {
		return new WP_Error( 'papelito_dev_retry_forbidden', 'A nova tentativa simulada so existe no ambiente local.', array( 'status' => 403 ) );
	}
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'is_paid' ) || ! $order->is_paid() ) {
		return new WP_Error( 'papelito_tracking_order_not_ready', PAPELITO_TRACKING_MSG_ORDER_NOT_READY, array( 'status' => 409 ) );
	}
	$status = function_exists( 'papelito_vendor_dashboard_order_status' ) ? papelito_vendor_dashboard_order_status( $order ) : '';
	if ( 'em_separacao' !== $status ) {
		return new WP_Error( 'papelito_tracking_order_not_ready', PAPELITO_TRACKING_MSG_ORDER_NOT_READY, array( 'status' => 409 ) );
	}
	$order_id = absint( $order->get_id() );
	$table    = papelito_tracking_shipments_table_name();
	papelito_tracking_begin_transaction();
	$attempt = papelito_tracking_manual_fallback_attempt( $order_id, $vendor_id, true );
	$active  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d AND vendor_id = %d AND active = 1 LIMIT 1 FOR UPDATE", $order_id, $vendor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! is_array( $attempt ) || $active ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_dev_retry_not_available', 'A tentativa simulada nao pode ser reaberta.', array( 'status' => 409 ) );
	}
	$retired_key = hash( 'sha256', (string) $attempt['idempotency_key'] . '|retired|' . absint( $attempt['id'] ) );
	$updated     = $wpdb->update(
		$table,
		array( 'idempotency_key' => $retired_key, 'manual_fallback_eligible' => 0, 'manual_fallback_consumed_at' => current_time( 'mysql', true ), 'last_error_code' => 'papelito_dev_retry_retired', 'updated_at' => current_time( 'mysql', true ) ),
		array( 'id' => absint( $attempt['id'] ), 'manual_fallback_eligible' => 1, 'is_test' => 1 ),
		array( '%s', '%d', '%s', '%s', '%s' ),
		array( '%d', '%d', '%d' )
	);
	if ( 1 !== $updated ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_dev_retry_conflict', 'A tentativa simulada mudou durante a reabertura.', array( 'status' => 409 ) );
	}
	$wpdb->query( 'COMMIT' );
	return papelito_tracking_generate_shipment( $order, $vendor_id );
}

/** Consulta um objeto na API Rastro oficial. */
function papelito_tracking_fetch_correios_object( string $tracking_code ) {
	$credentials = papelito_correios_credentials();
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}
	$token = papelito_correios_get_token( $credentials );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$url = papelito_correios_base_url( $credentials['environment'] ) . 'srorastro/v1/objetos/' . rawurlencode( $tracking_code ) . '?resultado=T';
	return papelito_correios_request_json(
		'GET',
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . sanitize_text_field( (string) $token['token'] ),
			),
		)
	);
}

/** Chave de deduplicacao de uma notificacao de envio. */
function papelito_tracking_notification_dedupe_key( int $shipment_id, string $type ): string {
	return 'shipment:' . $shipment_id . ':' . $type;
}

/** Notifica as partes apenas para marcos relevantes e de forma deduplicada. */
function papelito_tracking_notify_event( int $order_id, int $vendor_id, int $shipment_id, string $status, string $event_key ): void {
	if ( ! function_exists( 'papelito_dispatch_notification' ) || ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
		return;
	}
	$type_map = array(
		'posted'           => 'shipment_posted',
		'out_for_delivery' => 'shipment_out_for_delivery',
		'delivered'        => 'shipment_delivered',
		'delivery_failed'  => 'shipment_delivery_failed',
		'pickup_available' => 'shipment_pickup_available',
		'returned'         => 'shipment_returned',
		'lost'             => 'shipment_exception',
	);
	if ( ! isset( $type_map[ $status ] ) ) {
		return;
	}
	$notification_meta = '_papelito_tracking_notification_' . $shipment_id . '_' . $type_map[ $status ];
	if ( '1' === (string) $order->get_meta( $notification_meta, true ) ) {
		return;
	}
	global $wpdb;
	$legacy_exists = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM ' . papelito_notifications_table_name() . ' WHERE type = %s AND payload LIKE %s LIMIT 1',
			$type_map[ $status ],
			'%"shipment_id":' . $shipment_id . '%'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( $legacy_exists ) {
		$order->update_meta_data( $notification_meta, '1' );
		$order->save();
		return;
	}
	$dedupe  = papelito_tracking_notification_dedupe_key( $shipment_id, $type_map[ $status ] );
	$payload = array( 'order_id' => $order_id, 'shipment_id' => $shipment_id, 'status' => $status, 'recipient_role' => 'customer' );
	papelito_dispatch_notification( absint( $order->get_customer_id() ), $type_map[ $status ], $payload, $dedupe );
	$payload['recipient_role'] = 'seller';
	papelito_dispatch_notification( $vendor_id, $type_map[ $status ], $payload, $dedupe );
	$order->update_meta_data( $notification_meta, '1' );
	$order->save();
}

/** Notifica a confirmacao manual sem alegar que ela veio da API Rastro. */
function papelito_tracking_notify_manual_shipment( int $order_id, int $vendor_id, int $shipment_id, string $tracking_code, bool $corrected ): void {
	if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'papelito_dispatch_notification' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
		return;
	}
	$type    = $corrected ? 'shipment_tracking_updated' : 'shipment_posted';
	$dedupe  = papelito_tracking_notification_dedupe_key( $shipment_id, $type );
	$payload = array( 'order_id' => $order_id, 'shipment_id' => $shipment_id, 'tracking_code' => $tracking_code, 'recipient_role' => 'customer' );
	papelito_dispatch_notification( absint( $order->get_customer_id() ), $type, $payload, $dedupe );
	$payload['recipient_role'] = 'seller';
	papelito_dispatch_notification( $vendor_id, $type, $payload, $dedupe );
	$order->update_meta_data( '_papelito_tracking_notification_' . $shipment_id . '_' . $type, '1' );
	$order->save();
	do_action( 'papelito_manual_shipment_notified', $order, $type, $tracking_code, $shipment_id );
}

/** Recalcula o status operacional sem permitir regressao ou entrega manual. */
function papelito_tracking_reconcile_order_status( int $order_id ): void {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return;
	}
	$snapshot = papelito_tracking_order_snapshot( $order_id );
	$current  = sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) );
	$current_logistics = sanitize_key( (string) $order->get_meta( '_papelito_logistics_status', true ) );
	if ( $current_logistics !== $snapshot['status'] ) {
		$order->update_meta_data( '_papelito_logistics_status', $snapshot['status'] );
		$order->update_meta_data( '_papelito_logistics_updated_at', current_time( 'mysql', true ) );
	}

	$wc_status = method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '';
	if ( in_array( $current, array( 'cancelado', 'cancelamento_solicitado', 'estornado', 'entregue' ), true ) || 'refunded' === $wc_status ) {
		$order->save();
		return;
	}

	$next     = null;
	if ( ! empty( $snapshot['all_packages_done'] ) ) {
		$next = 'entregue';
	} elseif ( in_array( $snapshot['status'], array( 'posted', 'in_transit', 'out_for_delivery', 'pickup_available', 'delivery_failed', 'returning', 'returned', 'lost' ), true ) ) {
		$next = 'enviado';
	}
	if ( null === $next || $next === $current ) {
		$order->save();
		return;
	}

	$order->update_meta_data( '_papelito_vendor_status', $next );
	$order->update_meta_data( '_papelito_vendor_status_source', 'correios_rastro' );
	$order->add_order_note( 'Status logistico atualizado automaticamente pela API Rastro dos Correios: ' . $next . '.' );
	$order->save();
}

/** Extrai codigo, tipo e data UTC normalizados de um evento da API Rastro. */
function papelito_tracking_event_fields( array $event ): array {
	return array(
		'code'     => strtoupper( sanitize_key( (string) ( $event['codigo'] ?? '' ) ) ),
		'type'     => strtoupper( sanitize_key( (string) ( $event['tipo'] ?? '' ) ) ),
		'event_at' => array_key_exists( 'event_at', $event )
			? $event['event_at']
			: papelito_tracking_event_datetime( $event['dtHrCriado'] ?? '' ),
	);
}

/** Grava o evento bruto; false quando a chave idempotente ja existia. */
function papelito_tracking_insert_event( int $shipment_id, string $event_key, string $source, array $event, array $fields ): bool {
	global $wpdb;
	$raw      = wp_json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$inserted = $wpdb->insert(
		papelito_tracking_events_table_name(),
		array(
			'shipment_id' => $shipment_id,
			'event_key'   => $event_key,
			'source'      => sanitize_key( $source ),
			'event_code'  => $fields['code'],
			'event_type'  => $fields['type'],
			'event_at'    => $fields['event_at'],
			'description' => sanitize_textarea_field( (string) ( $event['descricao'] ?? '' ) ),
			'location'    => papelito_tracking_event_location( $event ),
			'raw_payload' => false === $raw ? '{}' : $raw,
			'received_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	return false !== $inserted;
}

/** Calcula a atualizacao da remessa para o evento, ou null quando ele nao avanca o estado. */
function papelito_tracking_event_projection( array $locked, ?array $mapping, array $event, array $fields ): ?array {
	if ( ! is_array( $mapping ) ) {
		return null;
	}
	$current_rank = absint( $locked['status_rank'] ?? 0 );
	$current_at   = (string) ( $locked['last_event_at'] ?? '' );
	$is_newer     = null !== $fields['event_at'] && ( '' === $current_at || $fields['event_at'] >= $current_at );
	$advances     = $mapping['rank'] > $current_rank || ( $mapping['rank'] === $current_rank && $is_newer );
	if ( ! $advances ) {
		return null;
	}

	$update = array(
		'status'                 => $mapping['status'],
		'status_rank'            => $mapping['rank'],
		'last_event_code'        => $fields['code'],
		'last_event_type'        => $fields['type'],
		'last_event_at'          => $fields['event_at'],
		'last_event_description' => sanitize_text_field( (string) ( $event['descricao'] ?? '' ) ),
		'last_event_location'    => papelito_tracking_event_location( $event ),
		'last_error_code'        => null,
		'poll_attempts'          => 0,
		'updated_at'             => current_time( 'mysql', true ),
	);
	if ( 'delivered' === $mapping['status'] ) {
		$update['delivered_at'] = $fields['event_at'] ?: current_time( 'mysql', true );
		$update['next_poll_at'] = null;
	}
	return $update;
}

/** Propaga a transicao para a devolucao ou para o status do pedido e as notificacoes. */
function papelito_tracking_after_event_transition( array $locked, int $shipment_id, string $status, string $event_key ): void {
	if ( 'reverse' !== sanitize_key( (string) ( $locked['direction'] ?? 'outbound' ) ) ) {
		papelito_tracking_reconcile_order_status( absint( $locked['order_id'] ) );
		papelito_tracking_notify_event( absint( $locked['order_id'] ), absint( $locked['vendor_id'] ), $shipment_id, $status, $event_key );
		return;
	}
	if ( function_exists( 'papelito_return_tracking_event' ) ) {
		papelito_return_tracking_event( $locked, $status, $event_key );
	}
}

/** Persiste um evento e aplica a transicao somente se nao for regressiva. */
function papelito_tracking_ingest_event( array $shipment, array $event, string $source = PAPELITO_TRACKING_SOURCE_POLL ): bool {
	global $wpdb;
	$shipment_id = absint( $shipment['id'] ?? 0 );
	if ( $shipment_id <= 0 ) {
		return false;
	}

	$fields    = papelito_tracking_event_fields( $event );
	$event_key = papelito_tracking_event_key( $shipment_id, $event );
	$provider  = sanitize_key( (string) ( $shipment['provider'] ?? 'correios' ) );
	$mapping   = in_array( $provider, array( 'correios', 'mock', 'manual' ), true )
		? papelito_tracking_map_event( $fields['code'], $fields['type'] )
		: apply_filters( 'papelito_tracking_provider_event_map', null, $provider, $fields, $event, $shipment );

	papelito_tracking_begin_transaction();
	$locked = $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . papelito_tracking_shipments_table_name() . ' WHERE id = %d FOR UPDATE', $shipment_id ),
		ARRAY_A
	);
	if ( ! is_array( $locked ) || ! papelito_tracking_insert_event( $shipment_id, $event_key, $source, $event, $fields ) ) {
		$wpdb->query( 'ROLLBACK' );
		return false;
	}

	$update  = papelito_tracking_event_projection( $locked, $mapping, $event, $fields );
	$changed = null !== $update && false !== $wpdb->update( papelito_tracking_shipments_table_name(), $update, array( 'id' => $shipment_id ) );
	$wpdb->query( 'COMMIT' );

	if ( $changed ) {
		papelito_tracking_after_event_transition( $locked, $shipment_id, $mapping['status'], $event_key );
	}
	return true;
}

/**
 * Publica o desfecho de um poll com o provider da remessa, sem nada mais.
 *
 * Fica no agendamento do próximo poll porque é o único ponto por onde os dois
 * pollers passam nos dois desfechos; instrumentar cada poller faria a próxima
 * transportadora nascer sem métrica. Só provider, desfecho e código de erro
 * atravessam — nem ID de remessa, nem código de rastreio, nem pedido.
 *
 * @param string $provider Provider persistido na remessa.
 * @param bool   $failed Se o poll falhou.
 * @param string $error_code Código do erro, vazio no sucesso.
 */
function papelito_tracking_publish_poll_result( string $provider, bool $failed, string $error_code = '' ): void {
	$provider = sanitize_key( $provider );
	if ( '' === $provider ) {
		return;
	}

	do_action( 'papelito_tracking_poll_result', $provider, $failed, sanitize_key( $error_code ) );
}

/** Agenda a proxima consulta com backoff em falhas. */
function papelito_tracking_schedule_next_poll( int $shipment_id, bool $failed, string $error_code = '' ): void {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT status, poll_attempts, provider FROM {$table} WHERE id = %d", $shipment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! is_array( $row ) ) {
		return;
	}

	papelito_tracking_publish_poll_result( (string) ( $row['provider'] ?? '' ), $failed, $error_code );
	if ( 'delivered' === $row['status'] ) {
		$wpdb->update(
			$table,
			array( 'next_poll_at' => null, 'poll_attempts' => 0, 'last_error_code' => null, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $shipment_id )
		);
		return;
	}

	$attempts = $failed ? min( 10, absint( $row['poll_attempts'] ) + 1 ) : 0;
	if ( $failed ) {
		$delay = min( 6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempts - 1 ) ) );
	} else {
		$delay = 'out_for_delivery' === $row['status'] ? 10 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
	}
	$delay = papelito_tracking_apply_jitter( $delay );
	$wpdb->update(
		$table,
		array(
			'next_poll_at'   => gmdate( PAPELITO_TRACKING_MYSQL_DATETIME, time() + $delay ),
			'poll_attempts'   => $attempts,
			'last_error_code' => $failed ? sanitize_key( $error_code ) : null,
			'updated_at'      => current_time( 'mysql', true ),
		),
		array( 'id' => $shipment_id )
	);
}

/**
 * Distribui o proximo poll com jitter para evitar thundering herd (objetos
 * criados/atualizados juntos venceriam no mesmo tick de cron). Aplica +/-20%
 * sobre o delay, com deslocamento minimo de 60s.
 */
function papelito_tracking_apply_jitter( int $delay ): int {
	$spread = (int) max( MINUTE_IN_SECONDS, round( $delay * 0.2 ) );
	$offset = function_exists( 'wp_rand' ) ? wp_rand( -$spread, $spread ) : random_int( -$spread, $spread );
	return (int) max( MINUTE_IN_SECONDS, $delay + $offset );
}

/**
 * Transportadoras que sabem consultar o próprio rastreio, por provider.
 *
 * `mock` e `manual` compartilham o caminho dos Correios porque nasceram como
 * variações dele: código S10, mesmos eventos, mesma ingestão. Quem acrescentar
 * transportadora registra aqui pelo filtro, sem tocar no núcleo do polling.
 *
 * @return array<string,string>
 */
function papelito_tracking_provider_pollers(): array {
	$pollers = array(
		'correios'  => 'papelito_tracking_poll_correios_shipment',
		'mock'      => 'papelito_tracking_poll_correios_shipment',
		'manual'    => 'papelito_tracking_poll_correios_shipment',
		'braspress' => 'papelito_braspress_tracking_poll_shipment',
	);

	$filtered = apply_filters( 'papelito_tracking_providers', $pollers );

	return is_array( $filtered ) ? $filtered : $pollers;
}

/**
 * Resolve quem consulta o rastreio deste provider, ou null quando ninguém sabe.
 *
 * Registro que não dá para chamar é tratado como ausente: um plugin desativado
 * deixaria o envio ser consultado pelo caminho errado, e ser consultado como
 * Correios é pior do que não ser consultado.
 *
 * @param string $provider Provider persistido na remessa.
 */
function papelito_tracking_resolve_poller( string $provider ): ?string {
	$provider = sanitize_key( $provider );

	if ( '' === $provider ) {
		return null;
	}

	$poller = papelito_tracking_provider_pollers()[ $provider ] ?? null;

	return is_string( $poller ) && function_exists( $poller ) ? $poller : null;
}

/**
 * Entrega a remessa a quem sabe consultar o rastreio dela.
 *
 * @param array<string,mixed> $shipment Linha da tabela de remessas.
 */
function papelito_tracking_poll_shipment( array $shipment ): void {
	$shipment_id = absint( $shipment['id'] ?? 0 );
	if ( ! empty( $shipment['is_test'] ) ) {
		return;
	}
	$poller = papelito_tracking_resolve_poller( (string) ( $shipment['provider'] ?? 'correios' ) );
	if ( null === $poller ) {
		papelito_tracking_schedule_next_poll( $shipment_id, true, 'tracking_provider_unsupported' );
		return;
	}
	$poller( $shipment );
}

/**
 * Consulta o objeto na API Rastro e ingere os eventos na ordem cronológica.
 *
 * @param array<string,mixed> $shipment Linha da tabela de remessas.
 */
function papelito_tracking_poll_correios_shipment( array $shipment ): void {
	$shipment_id   = absint( $shipment['id'] ?? 0 );
	$tracking_code = papelito_tracking_normalize_code( $shipment['tracking_code'] ?? '' );
	if ( $shipment_id <= 0 || '' === $tracking_code ) {
		return;
	}

	$response = papelito_tracking_fetch_correios_object( $tracking_code );
	if ( is_wp_error( $response ) ) {
		papelito_tracking_schedule_next_poll( $shipment_id, true, $response->get_error_code() );
		return;
	}

	$object = isset( $response['objetos'][0] ) && is_array( $response['objetos'][0] ) ? $response['objetos'][0] : array();
	if ( ! empty( $object['codObjeto'] ) && papelito_tracking_normalize_code( $object['codObjeto'] ) !== $tracking_code ) {
		papelito_tracking_schedule_next_poll( $shipment_id, true, 'tracking_code_mismatch' );
		return;
	}
	$events = isset( $object['eventos'] ) && is_array( $object['eventos'] ) ? $object['eventos'] : array();
	foreach ( array_reverse( $events ) as $event ) {
		if ( is_array( $event ) ) {
			papelito_tracking_ingest_event( $shipment, $event );
		}
	}
	papelito_tracking_schedule_next_poll( $shipment_id, false );
}

/**
 * Processa os envios vencidos respeitando um teto de lote por execucao. O teto
 * e filtravel para acompanhar o crescimento sem editar codigo; o cron de 5 min
 * drena o backlog em ciclos. Ordena por next_poll_at (id como desempate estavel).
 */
function papelito_tracking_poll_due_shipments(): void {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$batch = (int) max( 1, min( 500, (int) apply_filters( 'papelito_tracking_poll_batch_size', 100 ) ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE active = 1 AND is_test = 0 AND (tracking_code IS NOT NULL OR external_reference IS NOT NULL) AND next_poll_at <= %s AND created_at >= %s ORDER BY next_poll_at ASC, id ASC LIMIT %d",
			current_time( 'mysql', true ),
			gmdate( PAPELITO_TRACKING_MYSQL_DATETIME, time() - ( 90 * DAY_IN_SECONDS ) ),
			$batch
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( is_array( $rows ) ? $rows : array() as $shipment ) {
		papelito_tracking_poll_shipment( $shipment );
	}
}
add_action( PAPELITO_TRACKING_POLL_HOOK, 'papelito_tracking_poll_due_shipments' );

/** Processa tentativas de pre-postagem incertas vencidas. */
function papelito_tracking_reconcile_due_generations(): void {
	global $wpdb;
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$table = papelito_tracking_shipments_table_name();
	$batch = (int) max( 1, min( 50, (int) apply_filters( 'papelito_prepostage_reconciliation_batch_size', 20 ) ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE active = 1 AND generation_status = 'uncertain' AND support_review_required = 0 AND (next_reconciliation_at IS NULL OR next_reconciliation_at <= %s) ORDER BY COALESCE(next_reconciliation_at, created_at) ASC, id ASC LIMIT %d",
			current_time( 'mysql', true ),
			$batch
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$order = wc_get_order( absint( $row['order_id'] ?? 0 ) );
		if ( is_object( $order ) ) {
			papelito_tracking_reconcile_generation( $order, absint( $row['vendor_id'] ?? 0 ), $row, 'scheduled' );
		}
	}
}
add_action( PAPELITO_PREPOST_RECONCILE_HOOK, 'papelito_tracking_reconcile_due_generations' );

/** Retorna indicadores operacionais sem expor credenciais ou dados pessoais. */
function papelito_tracking_health_snapshot(): array {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$now   = current_time( 'mysql', true );
	$day_ago = gmdate( PAPELITO_TRACKING_MYSQL_DATETIME, time() - DAY_IN_SECONDS );
	$ninety_days_ago = gmdate( PAPELITO_TRACKING_MYSQL_DATETIME, time() - ( 90 * DAY_IN_SECONDS ) );

	return array(
		'active'       => absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE active = 1" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'due'          => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND next_poll_at <= %s", $now ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'with_errors'  => absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND last_error_code IS NOT NULL" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'stalled_24h'  => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND status <> 'delivered' AND updated_at <= %s", $day_ago ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'expired_90d'  => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND status <> 'delivered' AND created_at < %s", $ninety_days_ago ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'prepostage'   => array(
			'mode'                        => function_exists( 'papelito_correios_prepostage_mode' ) ? papelito_correios_prepostage_mode() : 'disabled',
			'automatic_generation_enabled' => function_exists( 'papelito_correios_prepostage_readiness' ) && ! is_wp_error( papelito_correios_prepostage_readiness() ),
			'manual_registration_enabled'  => function_exists( 'papelito_correios_manual_tracking_enabled' ) && papelito_correios_manual_tracking_enabled(),
		),
		'generated_at' => $now,
	);
}

/** Instala Action Scheduler quando disponivel, com fallback para WP-Cron. */
function papelito_tracking_ensure_schedule(): void {
	if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
		if ( ! as_has_scheduled_action( PAPELITO_TRACKING_POLL_HOOK ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, PAPELITO_TRACKING_POLL_HOOK, array(), 'papelito' );
		}
		if ( ! as_has_scheduled_action( PAPELITO_PREPOST_RECONCILE_HOOK ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, PAPELITO_PREPOST_RECONCILE_HOOK, array(), 'papelito' );
		}
		return;
	}
	if ( ! wp_next_scheduled( PAPELITO_TRACKING_POLL_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'papelito_five_minutes', PAPELITO_TRACKING_POLL_HOOK );
	}
	if ( ! wp_next_scheduled( PAPELITO_PREPOST_RECONCILE_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'papelito_five_minutes', PAPELITO_PREPOST_RECONCILE_HOOK );
	}
}
add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		$schedules['papelito_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'A cada cinco minutos' );
		return $schedules;
	}
);
add_action( 'init', 'papelito_tracking_ensure_schedule', 20 );

/** Recria um PDF mock local quando o arquivo efemero foi perdido. */
function papelito_tracking_restore_missing_mock_label( array $row, string $path ) {
	if (
		'mock' !== sanitize_key( (string) ( $row['provider'] ?? '' ) )
		|| empty( $row['is_test'] )
		|| ! function_exists( 'papelito_correios_prepostage_is_test_environment' )
		|| ! papelito_correios_prepostage_is_test_environment()
		|| ! function_exists( 'papelito_correios_mock_pdf' )
	) {
		return new WP_Error( 'papelito_label_file_missing', 'O arquivo da etiqueta nao esta disponivel.', array( 'status' => 404 ) );
	}

	$prepost_id    = sanitize_text_field( (string) ( $row['prepost_id'] ?? '' ) );
	$tracking_code = papelito_tracking_normalize_code( $row['tracking_code'] ?? '' );
	if ( '' === $prepost_id || '' === $tracking_code ) {
		return new WP_Error( 'papelito_label_file_missing', 'O arquivo da etiqueta nao esta disponivel.', array( 'status' => 404 ) );
	}

	$contents = papelito_correios_mock_pdf( $prepost_id, $tracking_code );
	$sha256   = hash( 'sha256', $contents );
	if ( ! hash_equals( (string) ( $row['label_sha256'] ?? '' ), $sha256 ) ) {
		return new WP_Error( 'papelito_label_integrity_failed', 'A etiqueta falhou na verificacao de integridade.', array( 'status' => 500 ) );
	}

	$directory = dirname( $path );
	if ( ! wp_mkdir_p( $directory ) ) {
		return new WP_Error( 'papelito_label_storage_unavailable', PAPELITO_TRACKING_MSG_LABEL_STORAGE_UNAVAILABLE, array( 'status' => 500 ) );
	}
	papelito_tracking_harden_private_labels_dir( $directory );
	if ( strlen( $contents ) !== file_put_contents( $path, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return new WP_Error( 'papelito_label_storage_failed', PAPELITO_TRACKING_MSG_LABEL_STORAGE_FAILED, array( 'status' => 500 ) );
	}

	return $contents;
}

/** Carrega um rotulo privado depois da verificacao de pedido e vendor. */
function papelito_tracking_private_label_response( int $order_id, int $shipment_id, int $vendor_id ) {
	global $wpdb;
	$table = papelito_tracking_shipments_table_name();
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, provider, is_test, prepost_id, tracking_code, label_storage_key, label_sha256 FROM {$table} WHERE id = %d AND order_id = %d AND vendor_id = %d AND active = 1",
			$shipment_id,
			$order_id,
			$vendor_id
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! is_array( $row ) || empty( $row['label_storage_key'] ) ) {
		return new WP_Error( 'papelito_label_not_found', 'Etiqueta nao encontrada.', array( 'status' => 404 ) );
	}
	$key = sanitize_file_name( (string) $row['label_storage_key'] );
	if ( $key !== (string) $row['label_storage_key'] || 1 !== preg_match( '/^[a-f0-9]{64}\.pdf$/', $key ) ) {
		return new WP_Error( 'papelito_label_key_invalid', 'A referencia da etiqueta e invalida.', array( 'status' => 500 ) );
	}
	$path = trailingslashit( papelito_tracking_private_labels_dir() ) . $key;
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		$restored = papelito_tracking_restore_missing_mock_label( $row, $path );
		if ( is_wp_error( $restored ) ) {
			return $restored;
		}
		$contents = $restored;
	} else {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
	if ( false === $contents || ! hash_equals( (string) $row['label_sha256'], hash( 'sha256', $contents ) ) ) {
		return new WP_Error( 'papelito_label_integrity_failed', 'A etiqueta falhou na verificacao de integridade.', array( 'status' => 500 ) );
	}

	$response = new WP_REST_Response( $contents, 200 );
	$response->header( 'Content-Type', 'application/pdf' );
	$response->header( 'Content-Disposition', 'inline; filename="etiqueta-' . $shipment_id . '.pdf"' );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	$response->header( PAPELITO_TRACKING_PRIVATE_LABEL_HEADER, '1' );
	return $response;
}

/** Evita que o servidor REST serialize o PDF como JSON. */
add_filter(
	'rest_pre_serve_request',
	static function ( bool $served, $result ): bool {
		$headers = $result instanceof WP_REST_Response ? $result->get_headers() : array();
		if ( $served || ! $result instanceof WP_REST_Response || '1' !== ( $headers[ PAPELITO_TRACKING_PRIVATE_LABEL_HEADER ] ?? '' ) ) {
			return $served;
		}
		foreach ( $headers as $name => $value ) {
			if ( PAPELITO_TRACKING_PRIVATE_LABEL_HEADER !== $name ) {
				header( $name . ': ' . $value );
			}
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Conteudo PDF validado e autenticado.
		return true;
	},
	20,
	2
);

/** Libera as rotas de envio do painel somente para sellers autenticados. */
function papelito_tracking_rest_seller_permission() {
	$check = function_exists( 'papelito_vendor_dashboard_require_seller' ) ? papelito_vendor_dashboard_require_seller() : new WP_Error( 'forbidden', 'Acesso negado.' );
	return is_wp_error( $check ) ? $check : true;
}

/** Libera as rotas administrativas de rastreamento. */
function papelito_tracking_rest_admin_permission(): bool {
	return current_user_can( 'manage_woocommerce' );
}

/**
 * Envolve o resultado de uma operacao em resposta REST, preservando WP_Error.
 *
 * @param array|WP_Error $result Resultado da operacao.
 */
function papelito_tracking_rest_result( $result, int $status ) {
	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, $status );
}

/** Carrega o pedido do seller autenticado a partir do id da rota. */
function papelito_tracking_rest_vendor_order( WP_REST_Request $request ) {
	return papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'id' ) ), get_current_user_id() );
}

/** Carrega qualquer pedido a partir do id da rota administrativa. */
function papelito_tracking_rest_admin_order( WP_REST_Request $request ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $request->get_param( 'id' ) ) ) : null;
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return new WP_Error( 'papelito_order_not_found', 'Pedido nao encontrado.', array( 'status' => 404 ) );
	}
	return $order;
}

/** Gera a etiqueta do pedido do seller. */
function papelito_tracking_rest_vendor_generate( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_vendor_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	return papelito_tracking_rest_result( papelito_tracking_generate_shipment( $order, get_current_user_id() ), 201 );
}

/** Registra a postagem manual do pedido do seller. */
function papelito_tracking_rest_vendor_register_manual( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_vendor_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$result = papelito_tracking_register_manual_shipment(
		$order,
		get_current_user_id(),
		(string) $request->get_param( 'tracking_code' ),
		array(
			'service_code' => $request->get_param( 'service_code' ),
			'posted_at'    => $request->get_param( 'posted_at' ),
			'note'         => $request->get_param( 'note' ),
			'label_url'    => $request->get_param( 'label_url' ),
		)
	);
	return papelito_tracking_rest_result( $result, 201 );
}

/** Corrige o codigo manual de um envio do seller. */
function papelito_tracking_rest_vendor_update_manual( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_vendor_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$result = papelito_tracking_update_manual_shipment( $order, get_current_user_id(), absint( $request->get_param( 'shipment_id' ) ), (string) $request->get_param( 'tracking_code' ), array( 'posted_at' => $request->get_param( 'posted_at' ) ) );
	return papelito_tracking_rest_result( $result, 200 );
}

/** Reabre a tentativa simulada local do pedido do seller. */
function papelito_tracking_rest_vendor_retry_mock( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_vendor_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	return papelito_tracking_rest_result( papelito_tracking_retry_local_generation( $order, get_current_user_id() ), 201 );
}

/** Entrega o PDF privado da etiqueta ao seller dono do pedido. */
function papelito_tracking_rest_vendor_label( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_vendor_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	return papelito_tracking_private_label_response( absint( $request->get_param( 'id' ) ), absint( $request->get_param( 'shipment_id' ) ), get_current_user_id() );
}

/** Associa manualmente um envio existente para migracao ou auditoria. */
function papelito_tracking_rest_admin_attach( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_admin_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$id = papelito_tracking_create_shipment(
		(int) $order->get_id(),
		absint( $order->get_meta( '_papelito_vendor_id', true ) ),
		array(
			'tracking_code' => $request->get_param( 'tracking_code' ),
			'prepost_id'    => $request->get_param( 'prepost_id' ),
			'service_code'  => $request->get_param( 'service_code' ),
		)
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$order->add_order_note( sprintf( 'Envio #%d associado manualmente por administrador para migracao/auditoria.', $id ) );
	$order->save();
	return new WP_REST_Response( papelito_tracking_order_snapshot( (int) $order->get_id() ), 201 );
}

/** Libera o fallback manual por decisao do suporte. */
function papelito_tracking_rest_admin_manual_release( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_admin_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$result = papelito_tracking_admin_release_manual_fallback(
		$order,
		get_current_user_id(),
		(string) $request->get_param( 'reason' ),
		(string) $request->get_param( 'evidence' )
	);
	return papelito_tracking_rest_result( $result, 200 );
}

/** Corrige o codigo manual de qualquer envio, inclusive depois da entrega. */
function papelito_tracking_rest_admin_update_manual( WP_REST_Request $request ) {
	$order = papelito_tracking_rest_admin_order( $request );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	return papelito_tracking_update_manual_shipment( $order, 0, absint( $request->get_param( 'shipment_id' ) ), (string) $request->get_param( 'tracking_code' ), array( 'posted_at' => $request->get_param( 'posted_at' ) ), true );
}

/** Indicadores operacionais do rastreamento. */
function papelito_tracking_rest_admin_health(): WP_REST_Response {
	return new WP_REST_Response( papelito_tracking_health_snapshot(), 200 );
}

/** Eventos brutos de rastreamento de um pedido. */
function papelito_tracking_rest_admin_events( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;
	$order_id  = absint( $request->get_param( 'id' ) );
	$events    = papelito_tracking_events_table_name();
	$shipments = papelito_tracking_shipments_table_name();
	$rows      = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT e.* FROM {$events} e INNER JOIN {$shipments} s ON s.id = e.shipment_id WHERE s.order_id = %d ORDER BY e.event_at ASC, e.id ASC",
			$order_id
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return new WP_REST_Response( array( 'items' => is_array( $rows ) ? $rows : array() ), 200 );
}

/** Registra as rotas de envio do painel do vendor. */
function papelito_tracking_register_vendor_routes(): void {
	$shipments_route = '/vendor/me/orders/(?P<id>\d+)/shipments';
	$shipment_route  = $shipments_route . '/(?P<shipment_id>\d+)';

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		$shipments_route,
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_tracking_rest_seller_permission',
			'callback'            => 'papelito_tracking_rest_vendor_generate',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		$shipments_route . '/manual',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_tracking_rest_seller_permission',
			'callback'            => 'papelito_tracking_rest_vendor_register_manual',
			'args'                => array(
				'tracking_code' => array( 'type' => 'string', 'required' => true ),
				'service_code'  => array( 'type' => 'string', 'required' => false ),
				'posted_at'     => array( 'type' => 'string', 'required' => true ),
				// A confirmacao manual nao exige observacao do vendor; o evento e auditado
				// pelo codigo, data, usuario autenticado e nota automatica do pedido.
				'note'          => array( 'type' => 'string', 'required' => false ),
				'label_url'     => array( 'type' => 'string', 'required' => false ),
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		$shipment_route,
		array(
			'methods'             => 'PATCH',
			'permission_callback' => 'papelito_tracking_rest_seller_permission',
			'callback'            => 'papelito_tracking_rest_vendor_update_manual',
			'args'                => array(
				'tracking_code' => array( 'type' => 'string', 'required' => true ),
				'posted_at'     => array( 'type' => 'string', 'required' => true ),
			),
		)
	);

	if ( function_exists( 'papelito_correios_prepostage_is_test_environment' ) && papelito_correios_prepostage_is_test_environment() ) {
		register_rest_route(
			PAPELITO_REST_NAMESPACE,
			$shipments_route . '/retry-mock',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_tracking_rest_seller_permission',
				'callback'            => 'papelito_tracking_rest_vendor_retry_mock',
			)
		);
	}

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		$shipment_route . '/label',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_tracking_rest_seller_permission',
			'callback'            => 'papelito_tracking_rest_vendor_label',
		)
	);
}

/** Registra as rotas administrativas de envio e rastreamento. */
function papelito_tracking_register_admin_routes(): void {
	$shipments_route = '/admin/orders/(?P<id>\d+)/shipments';

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		$shipments_route,
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_tracking_rest_admin_permission',
			'callback'            => 'papelito_tracking_rest_admin_attach',
			'args'                => array(
				'tracking_code' => array( 'type' => 'string', 'required' => true ),
				'prepost_id'    => array( 'type' => 'string', 'required' => false ),
				'service_code'  => array( 'type' => 'string', 'required' => false ),
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		$shipments_route . '/manual-release',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_tracking_rest_admin_permission',
			'callback'            => 'papelito_tracking_rest_admin_manual_release',
			'args'                => array(
				'reason'   => array( 'type' => 'string', 'required' => true ),
				'evidence' => array( 'type' => 'string', 'required' => true ),
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		$shipments_route . '/(?P<shipment_id>\d+)',
		array(
			'methods'             => 'PATCH',
			'permission_callback' => 'papelito_tracking_rest_admin_permission',
			'callback'            => 'papelito_tracking_rest_admin_update_manual',
			'args'                => array(
				'tracking_code' => array( 'type' => 'string', 'required' => true ),
				'posted_at'     => array( 'type' => 'string', 'required' => true ),
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/admin/tracking/health',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_tracking_rest_admin_permission',
			'callback'            => 'papelito_tracking_rest_admin_health',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/admin/orders/(?P<id>\d+)/tracking-events',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_tracking_rest_admin_permission',
			'callback'            => 'papelito_tracking_rest_admin_events',
		)
	);
}

/** Registra endpoints de geracao e associacao administrativa para migracao. */
add_action( 'rest_api_init', 'papelito_tracking_register_vendor_routes' );
add_action( 'rest_api_init', 'papelito_tracking_register_admin_routes' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class PapelitoTrackingSimulationCli {
		/**
		 * Simula o rastreamento dos Correios de um pedido local ate o estado informado.
		 *
		 * ## OPTIONS
		 *
		 * <order_id>
		 * : ID do pedido WooCommerce.
		 *
		 * [<state>]
		 * : Estado final: posted, in_transit, out_for_delivery ou delivered. Padrao: delivered.
		 *
		 * [--shipment=<id>]
		 * : Limita a uma remessa. Sem ele, usa todas as remessas de saida ativas do pedido.
		 *
		 * [--at=<datetime>]
		 * : Data ISO base dos eventos. Sem ele, usa a criacao da remessa.
		 *
		 * [--dry-run]
		 * : Apenas mostra os eventos, sem gravar.
		 *
		 * ## EXAMPLES
		 *
		 *     wp papelito tracking simulate 123
		 *     wp papelito tracking simulate 123 in_transit
		 */
		public function simulate( array $args, array $assoc_args ): void {
			if ( ! function_exists( 'papelito_correios_prepostage_is_test_environment' ) || ! papelito_correios_prepostage_is_test_environment() ) {
				WP_CLI::error( 'A simulacao de rastreamento so pode rodar em local ou development.' );
			}

			$order_id = absint( $args[0] ?? 0 );
			if ( $order_id <= 0 ) {
				WP_CLI::error( 'Informe um ID de pedido valido.' );
			}

			$state    = sanitize_key( (string) ( $args[1] ?? 'delivered' ) );
			$sequence = papelito_tracking_simulation_sequence_until( $state );
			if ( empty( $sequence ) ) {
				WP_CLI::error( 'Estado invalido. Use posted, in_transit, out_for_delivery ou delivered.' );
			}

			$shipments = $this->shipments( $order_id, absint( $assoc_args['shipment'] ?? 0 ) );
			$dry_run   = ! empty( $assoc_args['dry-run'] );
			$at        = sanitize_text_field( (string) ( $assoc_args['at'] ?? '' ) );
			foreach ( $shipments as $shipment ) {
				$this->simulate_shipment( $shipment, $sequence, $at, $dry_run );
			}
			$this->report( $order_id, $state, count( $shipments ), $dry_run );
		}

		/** Remessas de saida ativas do pedido, opcionalmente limitadas a uma. */
		private function shipments( int $order_id, int $shipment_id ): array {
			$shipments = papelito_tracking_simulation_shipments( $order_id );
			if ( $shipment_id > 0 ) {
				$shipments = array_values( array_filter( $shipments, static fn( array $shipment ): bool => absint( $shipment['id'] ?? 0 ) === $shipment_id ) );
			}
			if ( empty( $shipments ) ) {
				WP_CLI::error( sprintf( 'O pedido %d nao tem remessa de saida ativa. Gere a etiqueta ou registre o rastreio no painel do vendor antes.', $order_id ) );
			}
			return $shipments;
		}

		/** Mostra ou grava a sequencia de eventos de uma remessa. */
		private function simulate_shipment( array $shipment, array $sequence, string $at, bool $dry_run ): void {
			$shipment_id = absint( $shipment['id'] ?? 0 );
			$started_at  = papelito_tracking_simulation_started_at( $shipment, $at );
			if ( ! $started_at instanceof DateTimeImmutable ) {
				WP_CLI::error( 'A data base da simulacao e invalida. Use --at em formato ISO 8601.' );
			}

			if ( $dry_run ) {
				foreach ( $sequence as $status ) {
					WP_CLI::log( sprintf( 'remessa %d: %s %s', $shipment_id, $status, wp_json_encode( papelito_tracking_simulation_fixture_event( $status, $started_at ) ) ) );
				}
				return;
			}

			if ( empty( $shipment['is_test'] ) ) {
				WP_CLI::log( sprintf( 'remessa %d: marcada como remessa de teste', $shipment_id ) );
			}
			$results = papelito_tracking_simulation_apply_sequence( papelito_tracking_simulation_mark_as_test( $shipment ), $sequence, $started_at );
			foreach ( $results as $result ) {
				WP_CLI::log( sprintf( 'remessa %d: %s %s', $shipment_id, $result['status'], $result['ingested'] ? 'gravado' : 'ja existia' ) );
			}
		}

		/** Resume o resultado com o status operacional atual do pedido. */
		private function report( int $order_id, string $state, int $shipments, bool $dry_run ): void {
			$order         = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			$vendor_status = is_object( $order ) ? (string) $order->get_meta( '_papelito_vendor_status' ) : '';
			WP_CLI::success(
				sprintf(
					'Pedido %d ate %s em %d remessa(s)%s. Status do vendor: %s.',
					$order_id,
					$state,
					$shipments,
					$dry_run ? ' (dry-run, nada gravado)' : '',
					'' !== $vendor_status ? $vendor_status : '-'
				)
			);
		}
	}

	WP_CLI::add_command( 'papelito tracking', 'PapelitoTrackingSimulationCli' );
}
