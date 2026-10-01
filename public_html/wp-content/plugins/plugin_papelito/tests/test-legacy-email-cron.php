<?php
/**
 * Regressão do agendamento do lote horário da campanha legada.
 *
 * O agendador consultava o hook sem os args com que agendava, nunca achava o
 * evento e criava um novo a cada requisição. O stub de cron guarda eventos por
 * hook + args, como o WP-Cron, para que o defeito apareça no teste.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {}

$legacy_cron_test_events = array();
$legacy_cron_failures    = 0;

function add_action( mixed ...$args ): bool { return true; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

function legacy_cron_test_key( string $hook, array $args ): string {
	return $hook . '|' . serialize( $args ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
}

function wp_next_scheduled( string $hook, array $args = array() ): int|false {
	global $legacy_cron_test_events;
	return $legacy_cron_test_events[ legacy_cron_test_key( $hook, $args ) ][0] ?? false;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
	global $legacy_cron_test_events;
	$legacy_cron_test_events[ legacy_cron_test_key( $hook, $args ) ][] = $timestamp;
	return 'hourly' === $recurrence;
}

function wp_unschedule_hook( string $hook, bool $wp_error = false ): int {
	global $legacy_cron_test_events;
	$removed = 0;
	foreach ( array_keys( $legacy_cron_test_events ) as $key ) {
		if ( str_starts_with( $key, $hook . '|' ) ) {
			$removed += count( $legacy_cron_test_events[ $key ] );
			unset( $legacy_cron_test_events[ $key ] );
		}
	}
	return $removed;
}

function legacy_cron_test_count( string $hook ): int {
	global $legacy_cron_test_events;
	$total = 0;
	foreach ( $legacy_cron_test_events as $key => $timestamps ) {
		if ( str_starts_with( $key, $hook . '|' ) ) {
			$total += count( $timestamps );
		}
	}
	return $total;
}

function legacy_cron_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $legacy_cron_failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$legacy_cron_failures;
	echo "FAIL: {$label} expected " . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) . "\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
}

require_once dirname( __DIR__ ) . '/includes/legacy_migration.php';

for ( $request = 0; $request < 50; $request++ ) {
	papelito_legacy_schedule_email_cron();
}
legacy_cron_assert_same( 'requisições repetidas mantêm um único evento', 1, legacy_cron_test_count( PAPELITO_B2B_LEGACY_EMAIL_HOOK ) );
legacy_cron_assert_same(
	'o evento agendado é achado com os mesmos args',
	true,
	false !== wp_next_scheduled( PAPELITO_B2B_LEGACY_EMAIL_HOOK, papelito_legacy_email_cron_args() )
);

for ( $duplicate = 0; $duplicate < 40; $duplicate++ ) {
	wp_schedule_event( 1000 + $duplicate, 'hourly', PAPELITO_B2B_LEGACY_EMAIL_HOOK, papelito_legacy_email_cron_args() );
}
wp_schedule_event( 5000, 'hourly', PAPELITO_B2B_LEGACY_EMAIL_HOOK );
wp_schedule_event( 6000, 'hourly', 'papelito_outro_hook' );
legacy_cron_assert_same( 'o acúmulo do defeito foi reproduzido', 42, legacy_cron_test_count( PAPELITO_B2B_LEGACY_EMAIL_HOOK ) );

legacy_cron_assert_same( 'a migração confirma o evento esperado', true, papelito_legacy_prune_email_cron() );
legacy_cron_assert_same( 'a migração deixa exatamente um evento', 1, legacy_cron_test_count( PAPELITO_B2B_LEGACY_EMAIL_HOOK ) );
legacy_cron_assert_same( 'a migração não toca outros hooks', 1, legacy_cron_test_count( 'papelito_outro_hook' ) );

papelito_legacy_prune_email_cron();
legacy_cron_assert_same( 'rodar a migração de novo continua com um evento', 1, legacy_cron_test_count( PAPELITO_B2B_LEGACY_EMAIL_HOOK ) );

if ( $legacy_cron_failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
