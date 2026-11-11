<?php
/**
 * Ledger do repasse à Cifal das vendas do estande.
 *
 * A Point cobra na conta da Papelito, e a Papelito repassa à Cifal depois do evento. Cada pagamento
 * confirmado, liberação manual ou estorno vira uma linha com bruto, taxa e líquido, exportada em
 * CSV para o acerto.
 *
 * Regra de ouro: uma linha por tentativa e tipo (chave única), gravada com `INSERT IGNORE`, então
 * webhook, consulta de status e cron podem registrar o mesmo pagamento sem duplicar. Estorno entra
 * negativo e nenhuma linha é apagada. Taxa e líquido nulos significam "a conferir no extrato".
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_SETTLEMENT_KINDS = array( 'payment', 'manual_release', 'refund' );
const PAPELITO_EVENT_SALES_CSV_UNKNOWN      = 'a conferir';

/**
 * Nome completo (com prefixo) da tabela do ledger.
 *
 * @return string
 */
function papelito_event_sales_settlement_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'papelito_event_settlements';
}

/**
 * Cria ou atualiza a tabela do ledger.
 *
 * @return bool
 */
function papelito_event_sales_install_tables(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = papelito_event_sales_settlement_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(40) NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  attempt_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  payment_method VARCHAR(20) NOT NULL DEFAULT '',
  remote_order_id VARCHAR(80) NOT NULL DEFAULT '',
  remote_payment_id VARCHAR(80) NOT NULL DEFAULT '',
  nsu VARCHAR(40) NOT NULL DEFAULT '',
  gross_cents BIGINT NOT NULL,
  fee_cents BIGINT NULL DEFAULT NULL,
  net_cents BIGINT NULL DEFAULT NULL,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_attempt_kind (attempt_id, kind),
  KEY idx_event (event_id, created_at)
) {$charset};"
	);

	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Valor em centavos com o sinal do tipo: estorno é sempre negativo; nulo continua nulo.
 *
 * @param mixed  $value Valor informado.
 * @param string $kind  Tipo da linha.
 * @return int|null
 */
function papelito_event_sales_settlement_signed( mixed $value, string $kind ): ?int {
	if ( null === $value || '' === $value ) {
		return null;
	}

	$cents = abs( (int) $value );

	return 'refund' === $kind ? -$cents : $cents;
}

/**
 * Grava uma linha do ledger. Repetir a mesma tentativa e tipo não grava de novo e devolve true.
 *
 * @param array<string,mixed> $row Linha com `event_id`, `order_id`, `attempt_id`, `kind`, valores e origem.
 * @return bool
 */
function papelito_event_sales_settlement_record( array $row ): bool {
	global $wpdb;

	$kind = (string) ( $row['kind'] ?? '' );
	if ( ! in_array( $kind, PAPELITO_EVENT_SALES_SETTLEMENT_KINDS, true ) ) {
		return false;
	}

	$table  = papelito_event_sales_settlement_table();
	$values = array(
		substr( sanitize_text_field( (string) ( $row['event_id'] ?? '' ) ), 0, 40 ),
		(int) ( $row['order_id'] ?? 0 ),
		(int) ( $row['attempt_id'] ?? 0 ),
		$kind,
		substr( sanitize_key( (string) ( $row['payment_method'] ?? '' ) ), 0, 20 ),
		substr( sanitize_text_field( (string) ( $row['remote_order_id'] ?? '' ) ), 0, 80 ),
		substr( sanitize_text_field( (string) ( $row['remote_payment_id'] ?? '' ) ), 0, 80 ),
		substr( sanitize_text_field( (string) ( $row['nsu'] ?? '' ) ), 0, 40 ),
		(int) papelito_event_sales_settlement_signed( $row['gross_cents'] ?? 0, $kind ),
		papelito_event_sales_settlement_signed( $row['fee_cents'] ?? null, $kind ),
		papelito_event_sales_settlement_signed( $row['net_cents'] ?? null, $kind ),
		(int) ( $row['created_by'] ?? 0 ),
		current_time( 'mysql', true ),
	);

	$fee = null === $values[9] ? 'NULL' : '%d';
	$net = null === $values[10] ? 'NULL' : '%d';
	$sql = "INSERT IGNORE INTO {$table} (event_id, order_id, attempt_id, kind, payment_method, remote_order_id, remote_payment_id, nsu, gross_cents, fee_cents, net_cents, created_by, created_at) VALUES (%s, %d, %d, %s, %s, %s, %s, %s, %d, {$fee}, {$net}, %d, %s)";

	$result = $wpdb->query( $wpdb->prepare( $sql, array_values( array_filter( $values, 'papelito_event_sales_is_not_null' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	return false !== $result;
}

/**
 * Diz se o valor não é nulo; usado para tirar do `prepare` os campos gravados como NULL literal.
 *
 * @param mixed $value Valor.
 * @return bool
 */
function papelito_event_sales_is_not_null( mixed $value ): bool {
	return null !== $value;
}

/**
 * Linhas do ledger do evento em ordem cronológica.
 *
 * @param string $event_id Evento.
 * @return array<int,array<string,mixed>>
 */
function papelito_event_sales_settlement_rows( string $event_id ): array {
	global $wpdb;

	$table = papelito_event_sales_settlement_table();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %s ORDER BY created_at ASC, id ASC", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $rows ) ? $rows : array();
}

/**
 * Centavos no formato brasileiro do CSV (`1234,56`), ou "a conferir" quando nulo.
 *
 * @param mixed $cents Valor em centavos ou nulo.
 * @return string
 */
function papelito_event_sales_csv_money( mixed $cents ): string {
	if ( null === $cents ) {
		return PAPELITO_EVENT_SALES_CSV_UNKNOWN;
	}

	$value = (int) $cents;
	$sign  = $value < 0 ? '-' : '';
	$abs   = abs( $value );

	return sprintf( '%s%d,%02d', $sign, intdiv( $abs, 100 ), $abs % 100 );
}

/**
 * Soma os valores conhecidos de uma coluna, ignorando os nulos.
 *
 * @param array<int,array<string,mixed>> $rows   Linhas.
 * @param string                         $column Coluna.
 * @return int
 */
function papelito_event_sales_settlement_sum( array $rows, string $column ): int {
	return (int) array_sum( array_map( 'intval', array_filter( array_column( $rows, $column ), 'papelito_event_sales_is_not_null' ) ) );
}

/**
 * Planilha do repasse, separada por `;`, com uma linha de total ao final.
 *
 * @param array<int,array<string,mixed>> $rows Linhas do ledger.
 * @return string
 */
function papelito_event_sales_settlement_csv( array $rows ): string {
	$lines = array( 'pedido;data;tipo;forma;order_mp;pagamento_mp;nsu;bruto;taxa;liquido' );

	foreach ( $rows as $row ) {
		$lines[] = implode(
			';',
			array(
				(int) $row['order_id'],
				(string) $row['created_at'],
				(string) $row['kind'],
				(string) $row['payment_method'],
				(string) $row['remote_order_id'],
				(string) $row['remote_payment_id'],
				(string) $row['nsu'],
				papelito_event_sales_csv_money( $row['gross_cents'] ),
				papelito_event_sales_csv_money( $row['fee_cents'] ),
				papelito_event_sales_csv_money( $row['net_cents'] ),
			)
		);
	}

	$lines[] = 'TOTAL;;;;;;;' . implode(
		';',
		array(
			papelito_event_sales_csv_money( papelito_event_sales_settlement_sum( $rows, 'gross_cents' ) ),
			papelito_event_sales_csv_money( papelito_event_sales_settlement_sum( $rows, 'fee_cents' ) ),
			papelito_event_sales_csv_money( papelito_event_sales_settlement_sum( $rows, 'net_cents' ) ),
		)
	);

	return implode( "\n", $lines ) . "\n";
}
