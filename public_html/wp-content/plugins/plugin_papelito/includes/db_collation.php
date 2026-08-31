<?php
/**
 * Collation das colunas de identificador das tabelas Papelito.
 *
 * `wpdb::get_table_charset()` reduz a `ascii` qualquer tabela que misture dois charsets. A
 * partir daí `wpdb::query()` recusa toda consulta que carregue texto acentuado: devolve `false`
 * sem chegar no MySQL, e quem só compara o retorno com `1` lê isso como conflito de negócio.
 * Uma única coluna `CHARACTER SET ascii` derruba a tabela inteira nesse buraco.
 *
 * Colunas de identificador (CNPJ, chave de acesso, hash, chave de storage) querem comparação
 * byte a byte, e `utf8mb4_bin` entrega a mesma exatidão sem quebrar o charset da tabela.
 *
 * `dbDelta` compara só o tipo da coluna e nunca migra collation, então base já instalada
 * precisa do ALTER explícito daqui.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_BINARY_COLUMN_CHARSET' ) ) {
	define( 'PAPELITO_BINARY_COLUMN_CHARSET', 'utf8mb4' );
}

if ( ! defined( 'PAPELITO_BINARY_COLUMN_COLLATION' ) ) {
	define( 'PAPELITO_BINARY_COLUMN_COLLATION', 'utf8mb4_bin' );
}

/**
 * Realinha para utf8mb4_bin as colunas de identificador que ficaram em outro charset.
 *
 * @param string                                               $table   Tabela com prefixo.
 * @param array<string, array{type:string, attributes:string}> $columns Coluna => tipo e atributos.
 * @return void
 */
function papelito_db_align_binary_columns( string $table, array $columns ): void {
	global $wpdb;

	if ( array() === $columns ) {
		return;
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			DB_NAME,
			$table
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( ! is_array( $rows ) || array() === $rows ) {
		return;
	}

	$current = array();
	foreach ( $rows as $row ) {
		$current[ (string) $row['COLUMN_NAME'] ] = (string) $row['COLLATION_NAME'];
	}

	$changes = array();
	foreach ( $columns as $name => $definition ) {
		if ( ! isset( $current[ $name ] ) || PAPELITO_BINARY_COLUMN_COLLATION === $current[ $name ] ) {
			continue;
		}

		$attributes = trim( (string) $definition['attributes'] );

		$changes[] = sprintf(
			'MODIFY COLUMN `%s` %s CHARACTER SET %s COLLATE %s%s',
			$name,
			(string) $definition['type'],
			PAPELITO_BINARY_COLUMN_CHARSET,
			PAPELITO_BINARY_COLUMN_COLLATION,
			'' !== $attributes ? ' ' . $attributes : ''
		);
	}

	if ( array() === $changes ) {
		return;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "ALTER TABLE `{$table}` " . implode( ', ', $changes ) );
}
