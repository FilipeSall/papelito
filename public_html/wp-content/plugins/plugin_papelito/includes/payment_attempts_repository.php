<?php
/**
 * Repositório SQL das tentativas de pagamento.
 *
 * Gravação, leitura e transição por compare-and-set da tabela `papelito_payment_attempts`. A
 * máquina de estados e a projeção pública vivem em `payment_attempts.php`; aqui só se garante que
 * cada transição respeita a máquina e que uma única requisição vence cada corrida.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

/**
 * Nome completo da tabela de tentativas.
 *
 * @return string
 */
function papelito_payment_attempts_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_PAYMENT_ATTEMPTS_TABLE;
}

/**
 * Cria ou atualiza a tabela de tentativas (aditiva; rollback por flag não a remove).
 *
 * @return bool
 */
function papelito_payment_attempts_install_table(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table_name      = papelito_payment_attempts_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
attempt_ref CHAR(32) NOT NULL,
order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
checkout_attempt_id VARCHAR(120) NOT NULL DEFAULT '',
buyer_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
vendor_id BIGINT UNSIGNED NOT NULL,
provider VARCHAR(20) NOT NULL,
environment VARCHAR(10) NOT NULL,
account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
account_ref VARCHAR(64) NOT NULL DEFAULT '',
external_reference VARCHAR(80) NOT NULL DEFAULT '',
remote_id VARCHAR(80) NOT NULL DEFAULT '',
remote_payment_ids TEXT NULL,
amount_cents BIGINT UNSIGNED NOT NULL,
currency CHAR(3) NOT NULL DEFAULT 'BRL',
state VARCHAR(30) NOT NULL,
flow VARCHAR(30) NOT NULL DEFAULT '',
payment_method VARCHAR(20) NOT NULL DEFAULT '',
fallback_reason VARCHAR(60) NOT NULL DEFAULT '',
configuration_version VARCHAR(64) NOT NULL DEFAULT '',
commercial_hash CHAR(64) NOT NULL DEFAULT '',
redirect_url TEXT NULL,
expires_at DATETIME NULL,
last_error_code VARCHAR(80) NOT NULL DEFAULT '',
next_reconcile_at DATETIME NULL,
reconcile_failures INT UNSIGNED NOT NULL DEFAULT 0,
incident VARCHAR(60) NOT NULL DEFAULT '',
lock_version INT UNSIGNED NOT NULL DEFAULT 0,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uniq_attempt_ref (attempt_ref),
KEY idx_order (order_id),
KEY idx_vendor_state (vendor_id, state),
KEY idx_state_reconcile (state, next_reconcile_at),
KEY idx_provider_remote (provider, remote_id),
KEY idx_external_reference (external_reference)
) {$charset_collate};";

	dbDelta( $sql );

	return true;
}

/**
 * Colunas que a criação e as transições podem gravar.
 *
 * @return array<string,string> coluna => formato do wpdb.
 */
function papelito_payment_attempt_columns(): array {
	return array(
		'order_id'              => '%d',
		'checkout_attempt_id'   => '%s',
		'buyer_user_id'         => '%d',
		'company_id'            => '%d',
		'vendor_id'             => '%d',
		'provider'              => '%s',
		'environment'           => '%s',
		'account_id'            => '%d',
		'account_ref'           => '%s',
		'external_reference'    => '%s',
		'remote_id'             => '%s',
		'remote_payment_ids'    => '%s',
		'amount_cents'          => '%d',
		'currency'              => '%s',
		'state'                 => '%s',
		'flow'                  => '%s',
		'payment_method'        => '%s',
		'fallback_reason'       => '%s',
		'configuration_version' => '%s',
		'commercial_hash'       => '%s',
		'redirect_url'          => '%s',
		'expires_at'            => '%s',
		'last_error_code'       => '%s',
		'next_reconcile_at'     => '%s',
		'reconcile_failures'    => '%d',
		'incident'              => '%s',
	);
}

/**
 * Filtra os campos para as colunas conhecidas, com o formato de cada uma.
 *
 * @param array<string,mixed> $fields Campos crus.
 * @return array{data:array<string,mixed>,formats:array<int,string>}
 */
function papelito_payment_attempt_prepare_fields( array $fields ): array {
	$columns = papelito_payment_attempt_columns();
	$data    = array();
	$formats = array();

	foreach ( $fields as $column => $value ) {
		if ( ! isset( $columns[ $column ] ) ) {
			continue;
		}
		$data[ $column ] = is_array( $value ) ? wp_json_encode( $value ) : $value;
		$formats[]       = $columns[ $column ];
	}

	return array(
		'data'    => $data,
		'formats' => $formats,
	);
}

/**
 * Grava uma tentativa nova, já com attemptRef, antes de qualquer chamada remota.
 *
 * @param array<string,mixed> $fields Campos da tentativa.
 * @return array<string,mixed>|WP_Error Linha gravada.
 */
function papelito_payment_attempt_create( array $fields ) {
	global $wpdb;

	$fields['state'] = (string) ( $fields['state'] ?? 'not_started' );
	$valid           = papelito_payment_attempt_validate_new( $fields );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$prepared = papelito_payment_attempt_prepare_fields( $fields );
	$now      = current_time( 'mysql', true );
	$data     = array_merge(
		$prepared['data'],
		array(
			'attempt_ref' => papelito_payment_attempt_generate_ref(),
			'created_at'  => $now,
			'updated_at'  => $now,
		)
	);
	$formats  = array_merge( $prepared['formats'], array( '%s', '%s', '%s' ) );

	$inserted = $wpdb->insert( papelito_payment_attempts_table_name(), $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $inserted ) {
		return new WP_Error( 'papelito_payment_attempt_store_failed', 'Não foi possível registrar a tentativa de pagamento.', array( 'status' => 500 ) );
	}

	$row = papelito_payment_attempt_find( (int) $wpdb->insert_id );

	return is_array( $row ) ? $row : new WP_Error( 'papelito_payment_attempt_store_failed', 'Tentativa registrada não pôde ser relida.', array( 'status' => 500 ) );
}

/**
 * Lê uma tentativa pelo ID interno.
 *
 * @param int $attempt_id ID interno.
 * @return array<string,mixed>|null
 */
function papelito_payment_attempt_find( int $attempt_id ): ?array {
	global $wpdb;

	if ( $attempt_id <= 0 ) {
		return null;
	}

	$table = papelito_payment_attempts_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $attempt_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? papelito_payment_attempt_hydrate( $row ) : null;
}

/**
 * Lê uma tentativa pela referência opaca da notification URL.
 *
 * @param string $attempt_ref Referência opaca.
 * @return array<string,mixed>|null
 */
function papelito_payment_attempt_find_by_ref( string $attempt_ref ): ?array {
	global $wpdb;

	if ( ! papelito_payment_attempt_ref_is_valid( $attempt_ref ) ) {
		return null;
	}

	$table = papelito_payment_attempts_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE attempt_ref = %s", $attempt_ref ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? papelito_payment_attempt_hydrate( $row ) : null;
}

/**
 * Lê uma tentativa pelo gateway e ID remoto (preferência MP, pedido Pagar.me).
 *
 * @param string $provider  Gateway.
 * @param string $remote_id ID remoto.
 * @return array<string,mixed>|null
 */
function papelito_payment_attempt_find_by_remote( string $provider, string $remote_id ): ?array {
	global $wpdb;

	if ( '' === $remote_id || ! papelito_payment_provider_is_valid( $provider ) ) {
		return null;
	}

	$table = papelito_payment_attempts_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE provider = %s AND remote_id = %s ORDER BY id DESC LIMIT 1", $provider, $remote_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? papelito_payment_attempt_hydrate( $row ) : null;
}

/**
 * Tentativas de um pedido, da mais recente para a mais antiga.
 *
 * @param int $order_id Pedido WooCommerce.
 * @return array<int,array<string,mixed>>
 */
function papelito_payment_attempts_for_order( int $order_id ): array {
	global $wpdb;

	if ( $order_id <= 0 ) {
		return array();
	}

	$table = papelito_payment_attempts_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $rows ) ? array_map( 'papelito_payment_attempt_hydrate', $rows ) : array();
}

/**
 * Tentativa mais recente do pedido.
 *
 * @param int $order_id Pedido WooCommerce.
 * @return array<string,mixed>|null
 */
function papelito_payment_attempt_latest_for_order( int $order_id ): ?array {
	$attempts = papelito_payment_attempts_for_order( $order_id );

	return $attempts[0] ?? null;
}

/**
 * Converte tipos da linha crua.
 *
 * @param array<string,mixed> $row Linha do banco.
 * @return array<string,mixed>
 */
function papelito_payment_attempt_hydrate( array $row ): array {
	foreach ( array( 'id', 'order_id', 'buyer_user_id', 'company_id', 'vendor_id', 'account_id', 'amount_cents', 'reconcile_failures', 'lock_version' ) as $int_column ) {
		$row[ $int_column ] = (int) ( $row[ $int_column ] ?? 0 );
	}

	$payment_ids               = json_decode( (string) ( $row['remote_payment_ids'] ?? '' ), true );
	$row['remote_payment_ids'] = is_array( $payment_ids ) ? array_values( array_map( 'strval', $payment_ids ) ) : array();

	return $row;
}

/**
 * Transição por compare-and-set: só grava se o estado atual for o esperado.
 *
 * É o mecanismo contra emissão dupla — quem perde a corrida recebe `false` e deve reler a
 * tentativa. Transição fora da máquina de estados nunca chega ao banco.
 *
 * @param int                 $attempt_id ID interno.
 * @param string              $from       Estado esperado.
 * @param string              $to         Novo estado.
 * @param array<string,mixed> $fields     Campos extras a gravar junto.
 * @return bool Se esta chamada fez a transição.
 */
function papelito_payment_attempt_transition( int $attempt_id, string $from, string $to, array $fields = array() ): bool {
	global $wpdb;

	if ( $attempt_id <= 0 || ! papelito_payment_attempt_can_transition( $from, $to ) ) {
		return false;
	}

	unset( $fields['state'] );
	$prepared = papelito_payment_attempt_prepare_fields( $fields );
	$sets     = array( 'state = %s', 'updated_at = %s', 'lock_version = lock_version + 1' );
	$values   = array( $to, current_time( 'mysql', true ) );

	foreach ( $prepared['data'] as $column => $value ) {
		if ( null === $value ) {
			$sets[] = "{$column} = NULL";
			continue;
		}
		$sets[]   = "{$column} = " . papelito_payment_attempt_columns()[ $column ];
		$values[] = $value;
	}

	$values[] = $attempt_id;
	$values[] = $from;
	$table    = papelito_payment_attempts_table_name();
	$sql      = "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND state = %s';
	$updated  = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

	if ( 1 !== (int) $updated ) {
		return false;
	}

	$attempt = papelito_payment_attempt_find( $attempt_id );
	if ( is_array( $attempt ) ) {
		do_action( 'papelito_payment_attempt_transitioned', $attempt, $from, $to, $fields );
	}

	return true;
}

/**
 * Atualiza campos operacionais sem mudar o estado (link, IDs remotos, agenda de reconciliação).
 *
 * Usa `lock_version` como compare-and-set: dado obsoleto não sobrescreve gravação mais nova.
 *
 * @param array<string,mixed> $attempt Tentativa como lida.
 * @param array<string,mixed> $fields  Campos a gravar.
 * @return bool Se a gravação venceu.
 */
function papelito_payment_attempt_update( array $attempt, array $fields ): bool {
	global $wpdb;

	unset( $fields['state'] );
	$prepared = papelito_payment_attempt_prepare_fields( $fields );

	if ( empty( $prepared['data'] ) ) {
		return true;
	}

	$sets   = array( 'updated_at = %s', 'lock_version = lock_version + 1' );
	$values = array( current_time( 'mysql', true ) );

	foreach ( $prepared['data'] as $column => $value ) {
		if ( null === $value ) {
			$sets[] = "{$column} = NULL";
			continue;
		}
		$sets[]   = "{$column} = " . papelito_payment_attempt_columns()[ $column ];
		$values[] = $value;
	}

	$values[] = (int) $attempt['id'];
	$values[] = (int) $attempt['lock_version'];
	$table    = papelito_payment_attempts_table_name();
	$sql      = "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND lock_version = %d';
	$updated  = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

	return 1 === (int) $updated;
}

/**
 * Trava nomeada do MySQL para serializar a emissão de um pedido/checkout.
 *
 * Complementa o compare-and-set: impede que duas requisições criem duas tentativas para o mesmo
 * pedido antes de qualquer uma existir.
 *
 * @param string $key     Chave estável (ex.: `order:123`).
 * @param int    $timeout Segundos de espera.
 * @return bool Se a trava foi obtida.
 */
function papelito_payment_attempt_acquire_lock( string $key, int $timeout = 10 ): bool {
	global $wpdb;

	$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', papelito_payment_attempt_lock_name( $key ), $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return '1' === (string) $locked;
}

/**
 * Libera a trava nomeada.
 *
 * @param string $key Chave usada na aquisição.
 * @return void
 */
function papelito_payment_attempt_release_lock( string $key ): void {
	global $wpdb;

	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', papelito_payment_attempt_lock_name( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Nome da trava, curto o bastante para o limite de 64 caracteres do MySQL.
 *
 * @param string $key Chave lógica.
 * @return string
 */
function papelito_payment_attempt_lock_name( string $key ): string {
	return 'papelito_pay_' . substr( hash( 'sha256', $key ), 0, 40 );
}

/**
 * Tentativas abertas com reconciliação vencida, `unknown` primeiro.
 *
 * @param int $limit Máximo de tentativas no lote.
 * @return array<int,array<string,mixed>>
 */
function papelito_payment_attempts_due( int $limit ): array {
	global $wpdb;

	$table = papelito_payment_attempts_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ((state IN ('unknown', 'creating', 'pending', 'awaiting_payment_input') AND (next_reconcile_at IS NULL OR next_reconcile_at <= UTC_TIMESTAMP())) OR (state = 'expired' AND next_reconcile_at IS NOT NULL AND next_reconcile_at <= UTC_TIMESTAMP())) ORDER BY FIELD(state, 'unknown', 'creating', 'pending', 'awaiting_payment_input', 'expired'), next_reconcile_at ASC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $rows ) ? array_map( 'papelito_payment_attempt_hydrate', $rows ) : array();
}

/**
 * Tentativas que pedem atenção humana: incidente marcado, unknown antigo ou reconciliação falhando.
 *
 * Só identificadores internos e códigos; nada de referência remota ou conta.
 *
 * @param int $limit Máximo de linhas.
 * @return array<int,array<string,mixed>>
 */
function papelito_payment_attempts_incidents( int $limit ): array {
	global $wpdb;

	$table = papelito_payment_attempts_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, vendor_id, provider, environment, state, incident, last_error_code, reconcile_failures, created_at, updated_at FROM {$table} WHERE incident <> '' OR reconcile_failures >= 3 OR (state = 'unknown' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)) ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $rows ) ? $rows : array();
}

/**
 * Quantas tentativas abertas o vendor tem por estado, para a migração assistida.
 *
 * @param int $vendor_id Vendor.
 * @return array{unknown:int,creating:int,pending:int}
 */
function papelito_payment_attempts_open_counts( int $vendor_id ): array {
	global $wpdb;

	$table  = papelito_payment_attempts_table_name();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT state, COUNT(*) AS total FROM {$table} WHERE vendor_id = %d AND state IN ('unknown', 'creating', 'pending') GROUP BY state", $vendor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$counts = array(
		'unknown'  => 0,
		'creating' => 0,
		'pending'  => 0,
	);

	foreach ( (array) $rows as $row ) {
		$counts[ (string) $row['state'] ] = (int) $row['total'];
	}

	return $counts;
}
