<?php
/**
 * Contas financeiras do vendor por gateway e ambiente (hoje: vínculo OAuth do Mercado Pago).
 *
 * Repositório próprio, separado da integração Braspress: a política de credencial de transporte
 * não serve para dinheiro. Cada linha é uma versão de conta; só uma fica vigente por
 * vendor/provider/ambiente (`current_key` único) e um collector vigente pertence a um vendor só
 * por ambiente (`collector_key` único). Contas superadas continuam endereçáveis pelo ID que a
 * tentativa de pagamento gravou — consulta e estorno de cobrança antiga usam a conta histórica.
 *
 * A regra de ouro: tokens só existem cifrados (`papelito_vendor_secret_encrypt`) e só saem
 * decifrados para o adapter, nunca para REST, log ou navegador. Falha de cifra não grava; envelope
 * adulterado falha fechado. Rotação de token é compare-and-set por `token_version`.
 *
 * O que este módulo não faz: não fala com o gateway, não decide aptidão e não aprova KYC.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_PAYMENT_ACCOUNTS_TABLE = 'papelito_vendor_payment_accounts';

/**
 * Nome completo da tabela de contas.
 *
 * @return string
 */
function papelito_vendor_payment_accounts_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_VENDOR_PAYMENT_ACCOUNTS_TABLE;
}

/**
 * Cria ou atualiza a tabela (aditiva; rollback por flag não a remove).
 *
 * @return bool
 */
function papelito_vendor_payment_accounts_install_table(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table_name      = papelito_vendor_payment_accounts_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
vendor_id BIGINT UNSIGNED NOT NULL,
provider VARCHAR(20) NOT NULL,
environment VARCHAR(10) NOT NULL,
account_version INT UNSIGNED NOT NULL DEFAULT 1,
status VARCHAR(20) NOT NULL,
current_key VARCHAR(100) NULL,
collector_id VARCHAR(64) NOT NULL DEFAULT '',
collector_key VARCHAR(120) NULL,
access_token_envelope TEXT NULL,
refresh_token_envelope TEXT NULL,
public_key VARCHAR(160) NOT NULL DEFAULT '',
scope TEXT NOT NULL,
live_mode TINYINT(1) NOT NULL DEFAULT 0,
token_expires_at DATETIME NULL,
token_version INT UNSIGNED NOT NULL DEFAULT 1,
last_refresh_at DATETIME NULL,
cnpj_fingerprint CHAR(64) NOT NULL DEFAULT '',
configuration_version VARCHAR(64) NOT NULL DEFAULT '',
identity_status VARCHAR(30) NOT NULL DEFAULT 'verification_pending',
identity_verified_at DATETIME NULL,
identity_verified_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
identity_evidence VARCHAR(255) NOT NULL DEFAULT '',
health_status VARCHAR(30) NOT NULL DEFAULT '',
health_reason VARCHAR(80) NOT NULL DEFAULT '',
health_checked_at DATETIME NULL,
health_success_at DATETIME NULL,
last_error_code VARCHAR(80) NOT NULL DEFAULT '',
connected_at DATETIME NOT NULL,
disconnected_at DATETIME NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uniq_current (current_key),
UNIQUE KEY uniq_collector (collector_key),
KEY idx_vendor_provider (vendor_id, provider, environment),
KEY idx_token_expiry (status, token_expires_at)
) {$charset_collate};";

	dbDelta( $sql );

	return true;
}

/**
 * Chave de vigência por vendor/provider/ambiente.
 *
 * @param int    $vendor_id   Vendor.
 * @param string $provider    Gateway.
 * @param string $environment Ambiente.
 * @return string
 */
function papelito_vendor_payment_account_current_key( int $vendor_id, string $provider, string $environment ): string {
	return $vendor_id . ':' . $provider . ':' . $environment;
}

/**
 * Chave de unicidade do collector vigente por ambiente.
 *
 * @param string $provider     Gateway.
 * @param string $environment  Ambiente.
 * @param string $collector_id Collector remoto.
 * @return string
 */
function papelito_vendor_payment_account_collector_key( string $provider, string $environment, string $collector_id ): string {
	return $provider . ':' . $environment . ':' . $collector_id;
}

/**
 * Data UTC no formato do MySQL a partir de um timestamp.
 *
 * @param int $timestamp Timestamp.
 * @return string
 */
function papelito_vendor_payment_account_mysql_time( int $timestamp ): string {
	return gmdate( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Tira a conta da vigência, liberando chave de vigência e collector para a sucessora.
 *
 * @param int    $account_id Conta.
 * @param string $now        Instante UTC.
 * @return bool Se a escrita foi aceita.
 */
function papelito_vendor_payment_account_supersede( int $account_id, string $now ): bool {
	global $wpdb;

	return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_vendor_payment_accounts_table_name(),
		array(
			'status'        => 'superseded',
			'current_key'   => null,
			'collector_key' => null,
			'updated_at'    => $now,
		),
		array( 'id' => $account_id )
	);
}

/**
 * Vincula uma conta nova como vigente, superando a anterior do mesmo vendor/provider/ambiente.
 *
 * Collector vigente de outro vendor no mesmo ambiente é recusado. Falha de cifra não grava nada, e
 * superar a anterior e gravar a nova é uma transação: se a nova não grava, a anterior segue vigente.
 *
 * @param int                 $vendor_id   Vendor.
 * @param string              $provider    Gateway.
 * @param string              $environment `test` ou `live`.
 * @param array<string,mixed> $data        collector_id, access_token, refresh_token, expires_in, public_key, scope, live_mode, configuration_version, cnpj_fingerprint.
 * @return array<string,mixed>|WP_Error Conta gravada.
 */
function papelito_vendor_payment_account_connect( int $vendor_id, string $provider, string $environment, array $data ) {
	global $wpdb;

	$collector_id = sanitize_text_field( (string) ( $data['collector_id'] ?? '' ) );
	if ( $vendor_id <= 0 || '' === $collector_id || ! papelito_payment_provider_is_valid( $provider ) || ! in_array( $environment, array( 'test', 'live' ), true ) ) {
		return new WP_Error( 'papelito_payment_account_invalid', 'Conta de pagamento inválida.', array( 'status' => 422 ) );
	}

	$access  = papelito_vendor_secret_encrypt( (string) ( $data['access_token'] ?? '' ) );
	$refresh = papelito_vendor_secret_encrypt( (string) ( $data['refresh_token'] ?? '' ) );
	if ( is_wp_error( $access ) || is_wp_error( $refresh ) || '' === (string) ( $data['access_token'] ?? '' ) ) {
		return new WP_Error( 'papelito_payment_account_encryption_failed', 'Não foi possível proteger a credencial da conta.', array( 'status' => 500 ) );
	}

	$lock_key = 'account:' . papelito_vendor_payment_account_current_key( $vendor_id, $provider, $environment );
	if ( ! papelito_payment_attempt_acquire_lock( $lock_key ) ) {
		return new WP_Error( 'papelito_payment_account_busy', 'Outra vinculação desta conta está em andamento.', array( 'status' => 409 ) );
	}

	try {
		$table         = papelito_vendor_payment_accounts_table_name();
		$collector_key = papelito_vendor_payment_account_collector_key( $provider, $environment, $collector_id );
		$owner         = $wpdb->get_var( $wpdb->prepare( "SELECT vendor_id FROM {$table} WHERE collector_key = %s", $collector_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null !== $owner && (int) $owner !== $vendor_id ) {
			return new WP_Error( 'papelito_payment_account_collector_in_use', 'Esta conta do gateway já está vinculada a outra loja.', array( 'status' => 409 ) );
		}

		$previous = papelito_vendor_payment_account_current( $vendor_id, $provider, $environment );
		$now      = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$superseded = ! is_array( $previous ) || papelito_vendor_payment_account_supersede( (int) $previous['id'], $now );

		$inserted = $superseded && false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'vendor_id'              => $vendor_id,
				'provider'               => $provider,
				'environment'            => $environment,
				'account_version'        => papelito_vendor_payment_account_next_version( $vendor_id, $provider, $environment ),
				'status'                 => 'active',
				'current_key'            => papelito_vendor_payment_account_current_key( $vendor_id, $provider, $environment ),
				'collector_id'           => $collector_id,
				'collector_key'          => $collector_key,
				'access_token_envelope'  => $access,
				'refresh_token_envelope' => $refresh,
				'public_key'             => sanitize_text_field( (string) ( $data['public_key'] ?? '' ) ),
				'scope'                  => sanitize_text_field( (string) ( $data['scope'] ?? '' ) ),
				'live_mode'              => ! empty( $data['live_mode'] ) ? 1 : 0,
				'token_expires_at'       => papelito_vendor_payment_account_expiry( $data ),
				'cnpj_fingerprint'       => sanitize_text_field( (string) ( $data['cnpj_fingerprint'] ?? '' ) ),
				'configuration_version'  => sanitize_text_field( (string) ( $data['configuration_version'] ?? '' ) ),
				'identity_status'        => 'verification_pending',
				'connected_at'           => $now,
				'created_at'             => $now,
				'updated_at'             => $now,
			)
		);

		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'papelito_payment_account_store_failed', 'Não foi possível registrar a conta do gateway.', array( 'status' => 500 ) );
		}

		$account_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return papelito_vendor_payment_account_find( $account_id ) ?? new WP_Error( 'papelito_payment_account_store_failed', 'Conta registrada não pôde ser relida.', array( 'status' => 500 ) );
	} finally {
		papelito_payment_attempt_release_lock( $lock_key );
	}
}

/**
 * Próxima versão de conta do vendor/provider/ambiente.
 *
 * @param int    $vendor_id   Vendor.
 * @param string $provider    Gateway.
 * @param string $environment Ambiente.
 * @return int
 */
function papelito_vendor_payment_account_next_version( int $vendor_id, string $provider, string $environment ): int {
	global $wpdb;

	$table = papelito_vendor_payment_accounts_table_name();
	$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(account_version) FROM {$table} WHERE vendor_id = %d AND provider = %s AND environment = %s", $vendor_id, $provider, $environment ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return (int) $max + 1;
}

/**
 * Expiração do token a partir do `expires_in` devolvido pelo gateway; nunca fixada no código.
 *
 * @param array<string,mixed> $data Dados do token.
 * @return string|null
 */
function papelito_vendor_payment_account_expiry( array $data ): ?string {
	$expires_in = (int) ( $data['expires_in'] ?? 0 );

	return $expires_in > 0 ? papelito_vendor_payment_account_mysql_time( time() + $expires_in ) : null;
}

/**
 * Conta vigente para novas compras.
 *
 * @param int    $vendor_id   Vendor.
 * @param string $provider    Gateway.
 * @param string $environment Ambiente.
 * @return array<string,mixed>|null
 */
function papelito_vendor_payment_account_current( int $vendor_id, string $provider, string $environment ): ?array {
	global $wpdb;

	$table = papelito_vendor_payment_accounts_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE current_key = %s", papelito_vendor_payment_account_current_key( $vendor_id, $provider, $environment ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? papelito_vendor_payment_account_hydrate( $row ) : null;
}

/**
 * Conta mais recente do vendor/provider/ambiente, em qualquer status.
 *
 * Serve ao painel para explicar uma conta revogada ou desconectada; cobrança nova usa só
 * `papelito_vendor_payment_account_current()`.
 *
 * @param int    $vendor_id   Vendor.
 * @param string $provider    Gateway.
 * @param string $environment Ambiente.
 * @return array<string,mixed>|null
 */
function papelito_vendor_payment_account_latest( int $vendor_id, string $provider, string $environment ): ?array {
	global $wpdb;

	$table = papelito_vendor_payment_accounts_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE vendor_id = %d AND provider = %s AND environment = %s ORDER BY id DESC LIMIT 1", $vendor_id, $provider, $environment ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? papelito_vendor_payment_account_hydrate( $row ) : null;
}

/**
 * Conta pelo ID, vigente ou histórica.
 *
 * @param int $account_id ID interno.
 * @return array<string,mixed>|null
 */
function papelito_vendor_payment_account_find( int $account_id ): ?array {
	global $wpdb;

	if ( $account_id <= 0 ) {
		return null;
	}

	$table = papelito_vendor_payment_accounts_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $account_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? papelito_vendor_payment_account_hydrate( $row ) : null;
}

/**
 * Contas vigentes com token a vencer até o instante dado, para a renovação programada.
 *
 * @param string $provider Gateway.
 * @param int    $until    Timestamp limite.
 * @param int    $limit    Máximo de contas.
 * @return array<int,array<string,mixed>>
 */
function papelito_vendor_payment_accounts_expiring( string $provider, int $until, int $limit ): array {
	global $wpdb;

	$table = papelito_vendor_payment_accounts_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE provider = %s AND status = 'active' AND token_expires_at IS NOT NULL AND token_expires_at <= %s ORDER BY token_expires_at ASC LIMIT %d", $provider, papelito_vendor_payment_account_mysql_time( $until ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $rows ) ? array_map( 'papelito_vendor_payment_account_hydrate', $rows ) : array();
}

/**
 * Converte tipos da linha.
 *
 * @param array<string,mixed> $row Linha crua.
 * @return array<string,mixed>
 */
function papelito_vendor_payment_account_hydrate( array $row ): array {
	foreach ( array( 'id', 'vendor_id', 'account_version', 'token_version', 'identity_verified_by' ) as $column ) {
		$row[ $column ] = (int) ( $row[ $column ] ?? 0 );
	}
	$row['live_mode'] = '1' === (string) ( $row['live_mode'] ?? '0' );

	return $row;
}

/**
 * Tokens decifrados da conta, para uso exclusivo do adapter.
 *
 * @param array<string,mixed> $account Conta.
 * @return array{access_token:string,refresh_token:string}|WP_Error
 */
function papelito_vendor_payment_account_tokens( array $account ) {
	$access_envelope = (string) ( $account['access_token_envelope'] ?? '' );

	if ( '' === $access_envelope ) {
		return new WP_Error( 'papelito_payment_account_reauthorization_required', 'A conta do gateway precisa ser reautorizada pela loja.', array( 'status' => 409 ) );
	}

	$access  = papelito_vendor_secret_decrypt( $access_envelope );
	$refresh = '' !== (string) ( $account['refresh_token_envelope'] ?? '' ) ? papelito_vendor_secret_decrypt( (string) $account['refresh_token_envelope'] ) : '';

	if ( is_wp_error( $access ) || is_wp_error( $refresh ) || '' === $access ) {
		return new WP_Error( 'papelito_payment_account_credential_unreadable', 'Credencial da conta do gateway ilegível.', array( 'status' => 500 ) );
	}

	return array(
		'access_token'  => $access,
		'refresh_token' => $refresh,
	);
}

/**
 * Grava tokens renovados por compare-and-set na versão de token lida.
 *
 * Resultado de renovação obsoleta (outro worker já renovou) não sobrescreve.
 *
 * @param array<string,mixed> $account Conta como lida antes da renovação.
 * @param array<string,mixed> $tokens  access_token, refresh_token, expires_in.
 * @return bool|WP_Error Verdadeiro se esta gravação venceu.
 */
function papelito_vendor_payment_account_rotate_tokens( array $account, array $tokens ) {
	global $wpdb;

	$access  = papelito_vendor_secret_encrypt( (string) ( $tokens['access_token'] ?? '' ) );
	$refresh = papelito_vendor_secret_encrypt( (string) ( $tokens['refresh_token'] ?? '' ) );

	if ( is_wp_error( $access ) || is_wp_error( $refresh ) || '' === (string) ( $tokens['access_token'] ?? '' ) ) {
		return new WP_Error( 'papelito_payment_account_encryption_failed', 'Não foi possível proteger a credencial renovada.', array( 'status' => 500 ) );
	}

	$table   = papelito_vendor_payment_accounts_table_name();
	$expires = papelito_vendor_payment_account_expiry( $tokens );
	$now     = current_time( 'mysql', true );
	$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"UPDATE {$table} SET access_token_envelope = %s, refresh_token_envelope = %s, token_expires_at = %s, last_refresh_at = %s, updated_at = %s, token_version = token_version + 1 WHERE id = %d AND token_version = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$access,
			$refresh,
			(string) $expires,
			$now,
			$now,
			(int) $account['id'],
			(int) $account['token_version']
		)
	);

	return 1 === (int) $updated;
}

/**
 * Desativa a conta para novas compras, apagando credenciais e preservando a referência.
 *
 * Estorno de cobrança antiga depois disso exige reautorização da loja.
 *
 * @param int $account_id Conta.
 * @return bool
 */
function papelito_vendor_payment_account_disconnect( int $account_id ): bool {
	global $wpdb;

	$now     = current_time( 'mysql', true );
	$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_vendor_payment_accounts_table_name(),
		array(
			'status'                 => 'disconnected',
			'current_key'            => null,
			'collector_key'          => null,
			'access_token_envelope'  => null,
			'refresh_token_envelope' => null,
			'disconnected_at'        => $now,
			'updated_at'             => $now,
		),
		array( 'id' => $account_id )
	);

	return false !== $updated && $updated > 0;
}

/**
 * Marca a conta como revogada (token recusado pelo gateway); a loja precisa reconectar.
 *
 * @param int    $account_id Conta.
 * @param string $reason     Código do motivo.
 * @return void
 */
function papelito_vendor_payment_account_mark_revoked( int $account_id, string $reason ): void {
	global $wpdb;

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_vendor_payment_accounts_table_name(),
		array(
			'status'          => 'revoked',
			'current_key'     => null,
			'collector_key'   => null,
			'last_error_code' => sanitize_key( $reason ),
			'updated_at'      => current_time( 'mysql', true ),
		),
		array(
			'id'     => $account_id,
			'status' => 'active',
		)
	);
}

/**
 * Registra a verificação de titularidade/habilitação da conta.
 *
 * `verified` só por confirmação administrativa com referência de evidência restrita; `mismatch`
 * impede receber.
 *
 * @param int    $account_id   Conta.
 * @param string $status       `verified`, `mismatch` ou `verification_pending`.
 * @param int    $actor_id     Admin que confirmou.
 * @param string $evidence_ref Referência curta da evidência, sem documento.
 * @return bool
 */
function papelito_vendor_payment_account_set_identity( int $account_id, string $status, int $actor_id, string $evidence_ref ): bool {
	global $wpdb;

	if ( ! in_array( $status, array( 'verified', 'mismatch', 'verification_pending' ), true ) ) {
		return false;
	}

	$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_vendor_payment_accounts_table_name(),
		array(
			'identity_status'      => $status,
			'identity_verified_at' => 'verification_pending' === $status ? null : current_time( 'mysql', true ),
			'identity_verified_by' => $actor_id,
			'identity_evidence'    => substr( sanitize_text_field( $evidence_ref ), 0, 255 ),
			'updated_at'           => current_time( 'mysql', true ),
		),
		array( 'id' => $account_id )
	);

	return false !== $updated && $updated > 0;
}

const PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_META  = 'papelito_payment_identity_audit';
const PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_LIMIT = 50;

/**
 * Acrescenta uma conferência de titularidade à trilha do vendor.
 *
 * A coluna da conta guarda só a decisão vigente; a trilha preserva quem confirmou o quê, em qual
 * conta e collector, mesmo depois de nova confirmação. Mantém as últimas
 * `PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_LIMIT` entradas e nunca guarda documento.
 *
 * @param int                 $vendor_id Vendor.
 * @param array<string,mixed> $entry     accountId, collectorId, status, actorId, evidenceRef.
 * @return void
 */
function papelito_vendor_payment_identity_audit_append( int $vendor_id, array $entry ): void {
	$trail   = papelito_vendor_payment_identity_audit( $vendor_id );
	$trail[] = array(
		'accountId'   => (int) ( $entry['accountId'] ?? 0 ),
		'collectorId' => sanitize_text_field( (string) ( $entry['collectorId'] ?? '' ) ),
		'status'      => sanitize_key( (string) ( $entry['status'] ?? '' ) ),
		'actorId'     => (int) ( $entry['actorId'] ?? 0 ),
		'evidenceRef' => substr( sanitize_text_field( (string) ( $entry['evidenceRef'] ?? '' ) ), 0, 255 ),
		'at'          => gmdate( 'c' ),
	);

	update_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_META, array_slice( $trail, -PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_LIMIT ) );
}

/**
 * Trilha de conferências de titularidade do vendor, da mais antiga para a mais recente.
 *
 * @param int $vendor_id Vendor.
 * @return array<int,array<string,mixed>>
 */
function papelito_vendor_payment_identity_audit( int $vendor_id ): array {
	$trail = get_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_IDENTITY_AUDIT_META, true );

	return is_array( $trail ) ? array_values( array_filter( $trail, 'is_array' ) ) : array();
}

/**
 * Grava o resultado da última verificação de saúde da conta.
 *
 * @param int                 $account_id Conta.
 * @param array<string,mixed> $health     health_status, health_reason, success.
 * @return void
 */
function papelito_vendor_payment_account_update_health( int $account_id, array $health ): void {
	global $wpdb;

	$now  = current_time( 'mysql', true );
	$data = array(
		'health_status'     => sanitize_key( (string) ( $health['health_status'] ?? '' ) ),
		'health_reason'     => sanitize_key( (string) ( $health['health_reason'] ?? '' ) ),
		'health_checked_at' => $now,
		'updated_at'        => $now,
	);

	if ( ! empty( $health['success'] ) ) {
		$data['health_success_at'] = $now;
	}

	$wpdb->update( papelito_vendor_payment_accounts_table_name(), $data, array( 'id' => $account_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Resumo público da conta, sem credenciais.
 *
 * @param array<string,mixed> $account Conta.
 * @return array<string,mixed>
 */
function papelito_vendor_payment_account_public( array $account ): array {
	return array(
		'accountId'       => (int) $account['id'],
		'provider'        => (string) $account['provider'],
		'environment'     => (string) $account['environment'],
		'accountVersion'  => (int) $account['account_version'],
		'status'          => (string) $account['status'],
		'collectorId'     => (string) $account['collector_id'],
		'identityStatus'  => (string) $account['identity_status'],
		'tokenExpiresAt'  => (string) ( $account['token_expires_at'] ?? '' ),
		'connectedAt'     => (string) ( $account['connected_at'] ?? '' ),
		'healthStatus'    => (string) ( $account['health_status'] ?? '' ),
		'healthCheckedAt' => (string) ( $account['health_checked_at'] ?? '' ),
		'healthSuccessAt' => (string) ( $account['health_success_at'] ?? '' ),
	);
}
