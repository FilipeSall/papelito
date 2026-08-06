<?php
/** Standalone regression tests for the fail-closed pre-postage provider. */

define( 'ABSPATH', __DIR__ );

$papelito_environment_type = 'local';

function add_filter( ...$args ) {}
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_get_environment_type() { return $GLOBALS['papelito_environment_type']; }
function has_filter( $hook ) { return false; }

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = array() ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }

require __DIR__ . '/../includes/correios_prepostage.php';

$failures = 0;
function papelito_prepostage_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

final class Papelito_Prepostage_Test_Order {
	public function get_id() { return 11887; }
	public function get_meta( $key, $single = true ) { return '03298'; }
}

echo "Scenario 1: integration is disabled by default\n";
putenv( 'PAPELITO_CORREIOS_PREPOST_MODE=disabled' );
$disabled = papelito_correios_prepostage_readiness();
papelito_prepostage_assert( 'disabled mode is fail-closed', true, is_wp_error( $disabled ) );
papelito_prepostage_assert( 'disabled mode has a specific code', 'papelito_correios_integration_not_configured', $disabled->get_error_code() );

echo "Scenario 2: mock is deterministic, explicit and offline\n";
putenv( 'PAPELITO_CORREIOS_PREPOST_MODE=mock' );
putenv( 'PAPELITO_CORREIOS_PREPOST_MOCK_SCENARIO=success' );
$GLOBALS['papelito_environment_type'] = 'local';
$adapter = new PapelitoCorreiosMockPrepostageAdapter();
$first = $adapter->create( new Papelito_Prepostage_Test_Order(), 55 );
$second = $adapter->create( new Papelito_Prepostage_Test_Order(), 55 );
papelito_prepostage_assert( 'same order has same fake tracking id', $first['tracking_code'], $second['tracking_code'] );
papelito_prepostage_assert( 'fake code has a valid local S10 shape', true, 1 === preg_match( '/^FA[0-9]{9}BR$/', $first['tracking_code'] ) );
papelito_prepostage_assert( 'fake code has the computed check digit', (string) papelito_correios_s10_check_digit( substr( $first['tracking_code'], 2, 8 ) ), substr( $first['tracking_code'], 10, 1 ) );
papelito_prepostage_assert( 'mock result is marked as test', true, $first['is_test'] );
papelito_prepostage_assert( 'mock label is a PDF', true, 0 === strpos( $first['label_contents'], '%PDF-' ) );
papelito_prepostage_assert( 'mock label says SEM VALIDADE', true, false !== strpos( $first['label_contents'], 'SEM VALIDADE' ) );

echo "Scenario 3: all requested HTTP failures can be simulated\n";
foreach ( array( '400', '401', '403', '404', '409', '422', '429', '500', '503' ) as $scenario ) {
	putenv( 'PAPELITO_CORREIOS_PREPOST_MOCK_SCENARIO=' . $scenario );
	$error = $adapter->create( new Papelito_Prepostage_Test_Order(), 55 );
	papelito_prepostage_assert( "scenario {$scenario} returns WP_Error", true, is_wp_error( $error ) );
	papelito_prepostage_assert( "scenario {$scenario} returns matching HTTP status", (int) $scenario, (int) $error->get_error_data()['status'] );
}

echo "Scenario 4: mock can never run in production\n";
putenv( 'PAPELITO_CORREIOS_PREPOST_MOCK_SCENARIO=success' );
$GLOBALS['papelito_environment_type'] = 'production';
$blocked = papelito_correios_prepostage_readiness();
papelito_prepostage_assert( 'production blocks mock', 'papelito_correios_mock_forbidden_outside_local', $blocked->get_error_code() );

echo "Scenario 5: staging also blocks every development provider\n";
$GLOBALS['papelito_environment_type'] = 'staging';
$blocked = papelito_correios_prepostage_readiness();
papelito_prepostage_assert( 'staging blocks mock', 'papelito_correios_mock_forbidden_outside_local', $blocked->get_error_code() );

echo "Scenario 6: local health controls fake generation without remote creation\n";
$GLOBALS['papelito_environment_type'] = 'local';
putenv( 'PAPELITO_CORREIOS_DEV_HEALTH_SOURCE=mock' );
putenv( 'PAPELITO_CORREIOS_DEV_HEALTH_SCENARIO=unhealthy' );
$health_error = $adapter->create( new Papelito_Prepostage_Test_Order(), 55 );
papelito_prepostage_assert( 'unhealthy local state blocks fake generation', 'papelito_correios_dev_health_unhealthy', $health_error->get_error_code() );
putenv( 'PAPELITO_CORREIOS_DEV_HEALTH_SCENARIO=unknown' );
$health_error = $adapter->create( new Papelito_Prepostage_Test_Order(), 55 );
papelito_prepostage_assert( 'unknown local state is distinct', 'papelito_correios_dev_health_unknown', $health_error->get_error_code() );
putenv( 'PAPELITO_CORREIOS_DEV_HEALTH_SCENARIO=healthy' );
$healthy_result = $adapter->create( new Papelito_Prepostage_Test_Order(), 55 );
papelito_prepostage_assert( 'healthy local state generates a fake', false, is_wp_error( $healthy_result ) );

echo "Scenario 7: manual fallback requires an explicit flag\n";
putenv( 'PAPELITO_CORREIOS_MANUAL_TRACKING_ENABLED=false' );
papelito_prepostage_assert( 'manual fallback defaults off', false, papelito_correios_manual_tracking_enabled() );
putenv( 'PAPELITO_CORREIOS_MANUAL_TRACKING_ENABLED=true' );
papelito_prepostage_assert( 'manual fallback can be explicitly enabled', true, papelito_correios_manual_tracking_enabled() );

exit( $failures > 0 ? 1 : 0 );
