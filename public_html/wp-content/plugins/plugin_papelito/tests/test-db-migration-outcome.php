<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Desfecho que o executor de migrações grava quando o callback não lança.
 *
 * O registro só considerava exceção, e migração de schema quase nunca lança:
 * `dbDelta()` engole o erro do servidor e `$wpdb->query()` devolve `false` com
 * a supressão de erro ligada. Um `ALTER TABLE` recusado por permissão ou lock
 * virava `ok` no anel, a versão do schema avançava e a migração nunca era
 * retentada — o defeito voltava como coluna faltando, meses depois.
 *
 * Fixa a distinção que separa fracasso de rotina: `false` explícito é fracasso,
 * `null` de callback `void` é sucesso. Confundir os dois marcaria como quebrada
 * a maioria das migrações do plugin, que não devolvem nada.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_DB_VERSION', '1.51.0' );

const MIGRATION_OUTCOME_TEST_SENSITIVE = 'Duplicate entry 123.456.789-09 for key uniq_documento';
const MIGRATION_OUTCOME_TEST_CLOCK     = '2026-09-20 12:00:00';

$GLOBALS['migration_outcome_test_options'] = array();
$GLOBALS['migration_outcome_test_ran']     = array();

/** Lê a option do armazenamento em memória do teste. */
function get_option( mixed $option, mixed $default_value = false ): mixed {
	return array_key_exists( (string) $option, $GLOBALS['migration_outcome_test_options'] )
		? $GLOBALS['migration_outcome_test_options'][ (string) $option ]
		: $default_value;
}

/** Grava a option no armazenamento em memória do teste. */
function update_option( mixed $option, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['migration_outcome_test_options'][ (string) $option ] = $value;

	return true;
}

/** Relógio fixo do teste. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return MIGRATION_OUTCOME_TEST_CLOCK; }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Serialização usada nas asserções. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }

/** Instalador que reporta fracasso pelo retorno, como `dbDelta` recusado faria. */
function migration_outcome_test_returns_false(): bool {
	$GLOBALS['migration_outcome_test_ran'][] = 'returns_false';

	return false;
}

/** Instalador que conclui e devolve o sucesso explicitamente. */
function migration_outcome_test_returns_true(): bool {
	$GLOBALS['migration_outcome_test_ran'][] = 'returns_true';

	return true;
}

/** Migração `void`, a forma da maioria dos callbacks registrados. */
function migration_outcome_test_returns_void(): void {
	$GLOBALS['migration_outcome_test_ran'][] = 'returns_void';
}

/** Migração que estoura com valor de cliente na mensagem. */
function migration_outcome_test_throws(): void {
	$GLOBALS['migration_outcome_test_ran'][] = 'throws';

	throw new RuntimeException( MIGRATION_OUTCOME_TEST_SENSITIVE );
}

/** Migração que roda depois da que reportou fracasso. */
function migration_outcome_test_after(): void {
	$GLOBALS['migration_outcome_test_ran'][] = 'after';
}

require_once dirname( __DIR__ ) . '/includes/db_migrations.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function migration_outcome_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Zera o armazenamento entre cenários. */
function migration_outcome_reset(): void {
	$GLOBALS['migration_outcome_test_options'] = array();
	$GLOBALS['migration_outcome_test_ran']     = array();
}

/** Localiza a entrada do log de uma migração. */
function migration_outcome_entry( string $callback ): ?array {
	foreach ( papelito_db_migration_log() as $entry ) {
		if ( $callback === ( $entry['migration'] ?? '' ) ) {
			return $entry;
		}
	}

	return null;
}

echo "Cenário 1: instalador que devolve false é registrado como fracasso\n";
migration_outcome_reset();

papelito_run_optional_db_migrations( array( 'migration_outcome_test_returns_false' ) );

migration_outcome_assert( 'A migração rodou', array( 'returns_false' ) === $GLOBALS['migration_outcome_test_ran'] );
migration_outcome_assert(
	'O retorno false vira failed no registro',
	PAPELITO_DB_MIGRATION_FAILED === ( migration_outcome_entry( 'migration_outcome_test_returns_false' )['outcome'] ?? null )
);
migration_outcome_assert(
	'Fracasso sem exceção não inventa classe de erro',
	null === ( migration_outcome_entry( 'migration_outcome_test_returns_false' )['error'] ?? null )
);

echo "\nCenário 2: migração void continua sendo sucesso\n";
migration_outcome_reset();

papelito_run_optional_db_migrations( array( 'migration_outcome_test_returns_void' ) );

migration_outcome_assert(
	'O null de um callback void não é fracasso',
	PAPELITO_DB_MIGRATION_OK === ( migration_outcome_entry( 'migration_outcome_test_returns_void' )['outcome'] ?? null )
);

echo "\nCenário 3: instalador que devolve true continua sendo sucesso\n";
migration_outcome_reset();

papelito_run_optional_db_migrations( array( 'migration_outcome_test_returns_true' ) );

migration_outcome_assert(
	'O retorno true segue registrado como ok',
	PAPELITO_DB_MIGRATION_OK === ( migration_outcome_entry( 'migration_outcome_test_returns_true' )['outcome'] ?? null )
);

echo "\nCenário 4: exceção continua failed, com a classe e sem a mensagem\n";
migration_outcome_reset();

papelito_run_optional_db_migrations( array( 'migration_outcome_test_throws' ) );

migration_outcome_assert(
	'A exceção segue registrada como failed',
	PAPELITO_DB_MIGRATION_FAILED === ( migration_outcome_entry( 'migration_outcome_test_throws' )['outcome'] ?? null )
);
migration_outcome_assert(
	'O registro identifica o tipo do erro',
	'RuntimeException' === ( migration_outcome_entry( 'migration_outcome_test_throws' )['error'] ?? null )
);
migration_outcome_assert(
	'Nenhum dado da mensagem atravessa para a option',
	false === strpos( wp_json_encode( $GLOBALS['migration_outcome_test_options'] ), '123.456.789-09' )
);

echo "\nCenário 5: fracasso reportado pelo retorno não impede a migração seguinte\n";
migration_outcome_reset();

papelito_run_optional_db_migrations( array( 'migration_outcome_test_returns_false', 'migration_outcome_test_after' ) );

migration_outcome_assert(
	'A migração seguinte roda do mesmo jeito',
	array( 'returns_false', 'after' ) === $GLOBALS['migration_outcome_test_ran']
);

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
