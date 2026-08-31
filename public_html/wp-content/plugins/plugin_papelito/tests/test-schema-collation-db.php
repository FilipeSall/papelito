<?php
/**
 * Charset das tabelas Papelito contra o banco real.
 *
 * `wpdb::get_table_charset()` reduz a UMA tabela com dois charsets diferentes
 * para `ascii`, e a partir daí `wpdb::query()` recusa toda consulta que carregue
 * texto acentuado — devolve `false` sem chegar no MySQL. Misturar uma coluna
 * `CHARACTER SET ascii` com as demais em `utf8mb4` derruba a tabela inteira
 * nesse buraco, silenciosamente.
 *
 *   wp eval-file tests/test-schema-collation-db.php
 *
 * Não escreve nada: o UPDATE de sonda casa com `id = 0`.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-schema-collation-db.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

global $wpdb, $failures, $checks;

$failures = 0;
$checks   = 0;

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param string $label    Descrição.
 * @param mixed  $expected Esperado.
 * @param mixed  $actual   Obtido.
 * @return void
 */
function papelito_collation_check( $label, $expected, $actual ) {
	global $failures, $checks;

	++$checks;

	if ( $expected === $actual ) {
		return;
	}

	++$failures;
	printf( "FAIL %s: esperado %s, obtido %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/**
 * Lê o charset que o wpdb resolve para a tabela.
 *
 * @param string $table Tabela com prefixo.
 * @return mixed
 */
function papelito_collation_table_charset( string $table ) {
	global $wpdb;

	$method = new ReflectionMethod( $wpdb, 'get_table_charset' );
	$method->setAccessible( true );

	return $method->invoke( $wpdb, $table );
}

$tables = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
		DB_NAME,
		$wpdb->esc_like( $wpdb->prefix . 'papelito_' ) . '%'
	)
);

papelito_collation_check( 'existem tabelas papelito', true, count( (array) $tables ) > 0 );

foreach ( (array) $tables as $table ) {
	papelito_collation_check(
		"wpdb nao trata {$table} como ascii",
		true,
		'ascii' !== papelito_collation_table_charset( $table )
	);
}

$pre_account = papelito_company_table_names()['pre_account_applications'];

$probe = $wpdb->query(
	$wpdb->prepare(
		"UPDATE {$pre_account} SET rejection_reason = %s WHERE id = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'Conta direcionada para o fluxo de vendor por decisão administrativa.'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

papelito_collation_check( 'wpdb aceita texto acentuado na candidatura', 0, $probe );

printf( "RESULT: %d checks, %d failures\n", $checks, $failures );

exit( $failures > 0 ? 1 : 0 );
