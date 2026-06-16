<?php
/**
 * Standalone regression test for vendor processing alerts.
 *
 * Guarantees that overdue processing timers only start from the payment
 * confirmation timestamp (`date_paid`) and never fall back to order creation.
 *
 * Usage: php tests/test-vendor-processing-alerts.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function add_action( ...$args ) {}
function get_user_meta( $user_id, $key, $single = true ) {
	return '';
}

class Papelito_Test_Date {
	private $timestamp;

	public function __construct( int $timestamp ) {
		$this->timestamp = $timestamp;
	}

	public function getTimestamp(): int {
		return $this->timestamp;
	}
}

class WC_Order {
	private $paid_date;
	private $created_date;

	public function __construct( ?Papelito_Test_Date $paid_date, Papelito_Test_Date $created_date ) {
		$this->paid_date    = $paid_date;
		$this->created_date = $created_date;
	}

	public function get_date_paid() {
		return $this->paid_date;
	}

	public function get_date_created() {
		return $this->created_date;
	}
}

require __DIR__ . '/../includes/vendor_processing_alerts.php';

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

$created_ts = strtotime( '2026-06-01 10:00:00 UTC' );
$paid_ts    = strtotime( '2026-06-02 15:30:00 UTC' );

echo "Scenario 1: unpaid order never starts the overdue clock from created_at\n";
$order = new WC_Order( null, new Papelito_Test_Date( $created_ts ) );
papelito_assert( 'paid timestamp stays zero without date_paid', 0, papelito_vendor_processing_paid_timestamp( $order ) );

echo "Scenario 2: paid order starts the overdue clock from date_paid\n";
$order = new WC_Order( new Papelito_Test_Date( $paid_ts ), new Papelito_Test_Date( $created_ts ) );
papelito_assert( 'paid timestamp comes from date_paid', $paid_ts, papelito_vendor_processing_paid_timestamp( $order ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
