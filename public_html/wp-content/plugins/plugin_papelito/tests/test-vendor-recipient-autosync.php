<?php
/**
 * Standalone regression test for papelito_maybe_autosync_vendor_recipient().
 *
 * Verifies the auto-sync gate: the Pagar.me recipient sync (via the
 * `papelito_vendor_approved` action) only fires when the vendor registration is
 * complete (no pending fields) AND the recipient is not already active. This
 * makes completing the cadastro trigger a sync without duplicating an active
 * recipient.
 *
 * Usage: php tests/test-vendor-recipient-autosync.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['__fired_user_ids'] = array();
$GLOBALS['__active_user_ids'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}

function do_action( $hook, ...$args ) {
	if ( 'papelito_vendor_approved' === $hook ) {
		$GLOBALS['__fired_user_ids'][] = (int) ( $args[0] ?? 0 );
	}
}

function papelito_pagarme_vendor_recipient_is_active( int $user_id ): bool {
	return in_array( $user_id, $GLOBALS['__active_user_ids'], true );
}

require __DIR__ . '/../includes/revendedor_application.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

function fired_for( int $user_id ): bool {
	return in_array( $user_id, $GLOBALS['__fired_user_ids'], true );
}

echo "Scenario 1: complete registration + inactive recipient -> fires sync\n";
$GLOBALS['__fired_user_ids'] = array();
$GLOBALS['__active_user_ids'] = array();
papelito_maybe_autosync_vendor_recipient( 10, array() );
papelito_assert( 'fired for user 10', true, fired_for( 10 ) );

echo "Scenario 2: pending fields present -> does NOT fire\n";
$GLOBALS['__fired_user_ids'] = array();
$GLOBALS['__active_user_ids'] = array();
papelito_maybe_autosync_vendor_recipient( 11, array( 'bankAccount.accountNumber' ) );
papelito_assert( 'did NOT fire for user 11', false, fired_for( 11 ) );

echo "Scenario 3: recipient already active -> does NOT fire (no duplicate)\n";
$GLOBALS['__fired_user_ids'] = array();
$GLOBALS['__active_user_ids'] = array( 12 );
papelito_maybe_autosync_vendor_recipient( 12, array() );
papelito_assert( 'did NOT fire for active user 12', false, fired_for( 12 ) );

echo "Scenario 4: invalid user id -> does NOT fire\n";
$GLOBALS['__fired_user_ids'] = array();
$GLOBALS['__active_user_ids'] = array();
papelito_maybe_autosync_vendor_recipient( 0, array() );
papelito_assert( 'did NOT fire for user 0', false, fired_for( 0 ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
