<?php
/**
 * Auditoria e contadores operacionais dos gateways de pagamento.
 *
 * Cada transição de tentativa vira uma linha estruturada no log com identidade interna (tentativa,
 * pedido, vendor), provider, ambiente, versão da configuração, motivo de fallback e a transição —
 * o suficiente para localizar um incidente pelo ID, sem attemptRef, documento, conta ou token.
 * Contadores diários separam o que exige atenção diferente: conexão, criação desconhecida, fallback,
 * webhook recusado, reconciliação adiada e falha de estorno.
 *
 * O que este módulo não faz: não decide nada, não alerta ninguém e não é monitoramento global do
 * gateway — recusa de comprador não conta como indisponibilidade.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_PAYMENT_METRICS_OPTION = 'papelito_payment_metrics';
const PAPELITO_PAYMENT_METRICS_DAYS   = 14;

/**
 * Métricas conhecidas, todas zeradas.
 *
 * @return array<string,int>
 */
function papelito_payment_metrics_catalog(): array {
	return array(
		'creation_unknown'   => 0,
		'fallback'           => 0,
		'connection_failed'  => 0,
		'webhook_rejected'   => 0,
		'reconcile_deferred' => 0,
		'refund_failed'      => 0,
		'paid'               => 0,
		'expired'            => 0,
	);
}

/**
 * Incrementa um contador do dia (UTC), descartando dias além da janela.
 *
 * @param string $metric Métrica do catálogo.
 * @return bool Se a métrica existe e foi contada.
 */
function papelito_payment_metrics_increment( string $metric ): bool {
	if ( ! array_key_exists( $metric, papelito_payment_metrics_catalog() ) ) {
		return false;
	}

	$stored = get_option( PAPELITO_PAYMENT_METRICS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$day    = gmdate( 'Y-m-d' );
	$cutoff = gmdate( 'Y-m-d', time() - PAPELITO_PAYMENT_METRICS_DAYS * DAY_IN_SECONDS );

	foreach ( array_keys( $stored ) as $key ) {
		if ( (string) $key < $cutoff ) {
			unset( $stored[ $key ] );
		}
	}

	$today            = array_merge( papelito_payment_metrics_catalog(), is_array( $stored[ $day ] ?? null ) ? $stored[ $day ] : array() );
	$today[ $metric ] = (int) $today[ $metric ] + 1;
	$stored[ $day ]   = $today;

	update_option( PAPELITO_PAYMENT_METRICS_OPTION, $stored, false );

	return true;
}

/**
 * Contadores de hoje.
 *
 * @return array<string,int>
 */
function papelito_payment_metrics_today(): array {
	$stored = get_option( PAPELITO_PAYMENT_METRICS_OPTION, array() );
	$today  = is_array( $stored ) && is_array( $stored[ gmdate( 'Y-m-d' ) ] ?? null ) ? $stored[ gmdate( 'Y-m-d' ) ] : array();

	return array_intersect_key( array_merge( papelito_payment_metrics_catalog(), $today ), papelito_payment_metrics_catalog() );
}

/**
 * Registra uma transição de tentativa e conta o que for relevante.
 *
 * @param array<string,mixed> $attempt Tentativa antes da transição.
 * @param string              $from    Estado anterior.
 * @param string              $to      Novo estado.
 * @param array<string,mixed> $fields  Campos gravados junto (só o motivo é lido).
 * @return void
 */
function papelito_payment_observe_transition( array $attempt, string $from, string $to, array $fields = array() ): void {
	error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		sprintf(
			'[papelito_payment] attempt=%d order=%d vendor=%d provider=%s env=%s cfg=%s %s->%s reason=%s fallback=%s',
			(int) ( $attempt['id'] ?? 0 ),
			(int) ( $attempt['order_id'] ?? 0 ),
			(int) ( $attempt['vendor_id'] ?? 0 ),
			sanitize_key( (string) ( $attempt['provider'] ?? '' ) ),
			sanitize_key( (string) ( $attempt['environment'] ?? '' ) ),
			sanitize_key( (string) ( $attempt['configuration_version'] ?? '' ) ),
			sanitize_key( $from ),
			sanitize_key( $to ),
			sanitize_key( (string) ( $fields['last_error_code'] ?? '' ) ),
			sanitize_key( (string) ( $attempt['fallback_reason'] ?? '' ) )
		)
	);

	$metrics = array(
		'unknown' => 'creation_unknown',
		'paid'    => 'paid',
		'expired' => 'expired',
	);

	if ( isset( $metrics[ $to ] ) ) {
		papelito_payment_metrics_increment( $metrics[ $to ] );
	}

	if ( '' !== (string) ( $attempt['fallback_reason'] ?? '' ) && in_array( $from, array( 'not_started' ), true ) ) {
		papelito_payment_metrics_increment( 'fallback' );
	}
}

/**
 * Listener da transição gravada pelo repositório.
 *
 * @param mixed $attempt Tentativa antes da transição.
 * @param mixed $from    Estado anterior.
 * @param mixed $to      Novo estado.
 * @param mixed $fields  Campos gravados.
 * @return void
 */
function papelito_payment_on_attempt_transition( mixed $attempt, mixed $from, mixed $to, mixed $fields ): void {
	if ( is_array( $attempt ) ) {
		papelito_payment_observe_transition( $attempt, (string) $from, (string) $to, is_array( $fields ) ? $fields : array() );
	}
}

add_action( 'papelito_payment_attempt_transitioned', 'papelito_payment_on_attempt_transition', 10, 4 );

/**
 * Exige administrador.
 *
 * @return true|WP_Error
 */
function papelito_payment_observability_permission() {
	return current_user_can( 'manage_options' ) ? true : new WP_Error( 'papelito_forbidden', 'Acesso restrito à administração.', array( 'status' => 403 ) );
}

/**
 * GET /admin/payment-gateways/metrics: contadores da janela e incidentes abertos por ID interno.
 *
 * @return WP_REST_Response
 */
function papelito_payment_observability_rest(): WP_REST_Response {
	$stored = get_option( PAPELITO_PAYMENT_METRICS_OPTION, array() );

	return new WP_REST_Response(
		array(
			'metrics'   => is_array( $stored ) ? $stored : array(),
			'incidents' => function_exists( 'papelito_payment_attempts_incidents' ) ? papelito_payment_attempts_incidents( 50 ) : array(),
		),
		200
	);
}

/**
 * Registra a rota administrativa de observabilidade.
 *
 * @return void
 */
function papelito_payment_observability_register_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/admin/payment-gateways/metrics',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_payment_observability_permission',
			'callback'            => 'papelito_payment_observability_rest',
		)
	);
}

add_action( 'rest_api_init', 'papelito_payment_observability_register_routes' );
