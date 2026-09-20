<?php
/**
 * Executor das migrações de schema e o registro do que cada uma fez.
 *
 * O executor antigo era `foreach { $callback(); }`: sem `try`, sem conferir
 * retorno e sem deixar rastro. Uma instalação de tabela que falhasse não
 * aparecia em lugar nenhum e a versão do schema era marcada como concluída do
 * mesmo jeito — o defeito reaparecia como coluna faltando, meses depois, sem
 * nada que dissesse quando começou. O registro existe para responder a uma
 * pergunta só: **o que rodou aqui, quando, e deu certo?**
 *
 * A semântica de conclusão **não** mudou: uma migração que falha continua não
 * bloqueando o avanço de `papelito_db_version`. Bloquear faria cada requisição
 * tentar de novo, pegando o lock do banco, e transformaria uma migração
 * permanentemente quebrada em site fora do ar. A troca é deliberada: o defeito
 * fica visível no registro em vez de derrubar o site.
 *
 * O registro é uma option sem autoload, em anel: ele é lido por operação depois
 * de um deploy, não a cada requisição, e nunca deve crescer sem limite.
 *
 * Deste módulo **não** sai mensagem de exceção. Erro de SQL costuma ecoar o
 * valor que causou o conflito, e isso é dado de cliente; só a classe do erro
 * atravessa, e a mensagem completa fica no `error_log` restrito.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_DB_MIGRATION_LOG_OPTION = 'papelito_db_migration_log';
const PAPELITO_DB_MIGRATION_LOG_LIMIT  = 200;
const PAPELITO_DB_MIGRATION_OK         = 'ok';
const PAPELITO_DB_MIGRATION_SKIPPED    = 'skipped';
const PAPELITO_DB_MIGRATION_FAILED     = 'failed';

/**
 * Registro das últimas migrações executadas, da mais antiga para a mais recente.
 *
 * @return array<int,array<string,string>> Entradas do anel.
 */
function papelito_db_migration_log(): array {
	$stored = get_option( PAPELITO_DB_MIGRATION_LOG_OPTION, array() );

	return is_array( $stored ) ? $stored : array();
}

/**
 * Acrescenta uma entrada ao anel, engolindo qualquer falha de escrita.
 *
 * O registro é observação: ele não pode ser o motivo de uma migração parar no
 * meio, nem de o deploy falhar.
 *
 * @param string $migration Nome do callback executado.
 * @param string $outcome Desfecho do vocabulário fechado.
 * @param string $error Classe do erro, quando houve.
 * @return void
 */
function papelito_db_migration_record( string $migration, string $outcome, string $error = '' ): void {
	try {
		$entry = array(
			'migration' => sanitize_key( $migration ),
			'outcome'   => $outcome,
			'version'   => PAPELITO_DB_VERSION,
			'at'        => (string) current_time( 'mysql', true ),
		);
		if ( '' !== $error ) {
			$entry['error'] = sanitize_text_field( $error );
		}

		$log   = papelito_db_migration_log();
		$log[] = $entry;

		update_option(
			PAPELITO_DB_MIGRATION_LOG_OPTION,
			array_slice( $log, -PAPELITO_DB_MIGRATION_LOG_LIMIT ),
			false
		);
	} catch ( Throwable $unused ) {
		return;
	}
}

/**
 * Executa uma migração isolada, sem deixar a falha dela alcançar as seguintes.
 *
 * @param string $callback Nome do callback de migração.
 * @return void
 */
function papelito_run_db_migration( string $callback ): void {
	if ( ! function_exists( $callback ) ) {
		papelito_db_migration_record( $callback, PAPELITO_DB_MIGRATION_SKIPPED );
		return;
	}

	try {
		$callback();
		papelito_db_migration_record( $callback, PAPELITO_DB_MIGRATION_OK );
	} catch ( Throwable $error ) {
		papelito_db_migration_record( $callback, PAPELITO_DB_MIGRATION_FAILED, get_class( $error ) );
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( 'papelito_db_migration_failed %s %s: %s', $callback, get_class( $error ), $error->getMessage() )
		);
	}
}

/**
 * Executa a lista de migrações opcionais, registrando o desfecho de cada uma.
 *
 * @param array<int,string> $callbacks Nomes dos callbacks de migração.
 * @return void
 */
function papelito_run_optional_db_migrations( array $callbacks ): void {
	foreach ( $callbacks as $callback ) {
		papelito_run_db_migration( (string) $callback );
	}
}
