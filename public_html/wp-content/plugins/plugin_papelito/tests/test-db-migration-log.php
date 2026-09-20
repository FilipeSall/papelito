<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Registro do que cada migração de schema fez, para poder auditar depois.
 *
 * O executor engolia tudo: `$callback()` sem `try`, sem conferir retorno e sem
 * deixar rastro. Uma instalação de tabela que falhasse não aparecia em lugar
 * nenhum, e a versão do schema era marcada como concluída do mesmo jeito — o
 * defeito só reaparecia como coluna faltando, meses depois, sem nada que dissesse
 * quando começou.
 *
 * Fixa também o que o registro **não** pode guardar: mensagem de exceção pode
 * carregar trecho de SQL com dado de cliente, então só a classe atravessa.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_DB_VERSION', '1.51.0' );

const MIGRATION_TEST_SENSITIVE = 'CPF 123.456.789-09 duplicado na linha 42';

$GLOBALS['migration_test_options'] = array();
$GLOBALS['migration_test_ran']     = array();
$GLOBALS['migration_test_fails']   = false;

/** Lê a option do armazenamento em memória do teste. */
function get_option( mixed $option, mixed $default_value = false ): mixed {
	return array_key_exists( (string) $option, $GLOBALS['migration_test_options'] )
		? $GLOBALS['migration_test_options'][ (string) $option ]
		: $default_value;
}

/** Grava a option, ou explode quando a fixture pede falha de escrita. */
function update_option( mixed $option, mixed $value, mixed $autoload = null ): bool {
	if ( true === $GLOBALS['migration_test_fails'] ) {
		throw new RuntimeException( 'Armazenamento de options indisponível.' );
	}

	$GLOBALS['migration_test_options'][ (string) $option ] = $value;

	return true;
}

/** Relógio fixo do teste. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-20 12:00:00'; }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Serialização usada nas asserções. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }

/** Migração que conclui normalmente. */
function migration_test_ok(): bool {
	$GLOBALS['migration_test_ran'][] = 'ok';

	return true;
}

/** Migração que estoura no meio, como um `dbDelta` sem permissão faria. */
function migration_test_explodes(): void {
	$GLOBALS['migration_test_ran'][] = 'explodes';

	throw new RuntimeException( MIGRATION_TEST_SENSITIVE );
}

/** Migração que roda depois da que falhou. */
function migration_test_after(): bool {
	$GLOBALS['migration_test_ran'][] = 'after';

	return true;
}

require_once dirname( __DIR__ ) . '/includes/db_migrations.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function migration_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Zera o armazenamento entre cenários. */
function migration_reset(): void {
	$GLOBALS['migration_test_options'] = array();
	$GLOBALS['migration_test_ran']     = array();
	$GLOBALS['migration_test_fails']   = false;
}

/** Localiza a entrada do log de uma migração. */
function migration_entry( string $callback ): ?array {
	foreach ( papelito_db_migration_log() as $entry ) {
		if ( $callback === ( $entry['migration'] ?? '' ) ) {
			return $entry;
		}
	}

	return null;
}

echo "Cenário 1: migração concluída deixa rastro de sucesso\n";
migration_reset();

papelito_run_optional_db_migrations( array( 'migration_test_ok' ) );

migration_assert( 'A migração rodou', array( 'ok' ) === $GLOBALS['migration_test_ran'] );
migration_assert( 'O sucesso é registrado', 'ok' === ( migration_entry( 'migration_test_ok' )['outcome'] ?? null ) );
migration_assert( 'A entrada diz em que versão do schema rodou', PAPELITO_DB_VERSION === ( migration_entry( 'migration_test_ok' )['version'] ?? null ) );
migration_assert( 'A entrada é datada', '2026-09-20 12:00:00' === ( migration_entry( 'migration_test_ok' )['at'] ?? null ) );

echo "\nCenário 2: migração de módulo ausente é registrada, não ignorada em silêncio\n";
migration_reset();

papelito_run_optional_db_migrations( array( 'migration_test_inexistente' ) );

migration_assert(
	'Callback que não existe aparece como pulado',
	'skipped' === ( migration_entry( 'migration_test_inexistente' )['outcome'] ?? null )
);

echo "\nCenário 3: migração que estoura é registrada e não derruba as seguintes\n";
migration_reset();

papelito_run_optional_db_migrations( array( 'migration_test_explodes', 'migration_test_after' ) );

migration_assert(
	'A falha não impede a migração seguinte',
	array( 'explodes', 'after' ) === $GLOBALS['migration_test_ran']
);
migration_assert( 'A falha é registrada', 'failed' === ( migration_entry( 'migration_test_explodes' )['outcome'] ?? null ) );
migration_assert(
	'O registro identifica o tipo do erro',
	'RuntimeException' === ( migration_entry( 'migration_test_explodes' )['error'] ?? null )
);

echo "\nCenário 4: a mensagem da exceção não entra no registro\n";

migration_assert(
	'Nenhum dado da mensagem atravessa para a option',
	false === strpos( wp_json_encode( $GLOBALS['migration_test_options'] ), 'CPF' )
	&& false === strpos( wp_json_encode( $GLOBALS['migration_test_options'] ), '123.456.789-09' )
);

echo "\nCenário 5: o registro não cresce sem limite\n";
migration_reset();

for ( $i = 0; $i < PAPELITO_DB_MIGRATION_LOG_LIMIT + 25; $i++ ) {
	papelito_run_optional_db_migrations( array( 'migration_test_ok' ) );
}

migration_assert(
	'O log é um anel do tamanho declarado',
	PAPELITO_DB_MIGRATION_LOG_LIMIT === count( papelito_db_migration_log() )
);

echo "\nCenário 6: falha ao gravar o registro não derruba a migração\n";
migration_reset();
$GLOBALS['migration_test_fails'] = true;

papelito_run_optional_db_migrations( array( 'migration_test_ok' ) );

migration_assert( 'A migração rodou mesmo sem conseguir registrar', array( 'ok' ) === $GLOBALS['migration_test_ran'] );
migration_assert( 'Nenhuma exceção escapou do executor', true );

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
