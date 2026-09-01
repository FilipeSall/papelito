<?php

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function apply_filters( $hook, $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $type, $gmt = false ) { return '2026-09-01 10:10:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }

class WP_Error {}

require __DIR__ . '/../includes/correios_tracking.php';

$failures = 0;
function papelito_tracking_simulation_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

class Papelito_Tracking_Simulation_Test_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 0;
	public $shipments = array();
	public $event_keys = array();
	public $events = array();

	public function __construct() {
		$this->shipments = array(
			array(
				'id' => 10,
				'order_id' => 20,
				'vendor_id' => 30,
				'direction' => 'outbound',
				'is_test' => 1,
				'active' => 1,
				'status' => 'preposted',
				'status_rank' => 10,
				'created_at' => '2026-09-01 10:00:00',
			),
			array(
				'id' => 11,
				'order_id' => 20,
				'vendor_id' => 30,
				'direction' => 'outbound',
				'is_test' => 0,
				'active' => 1,
				'status' => 'preposted',
				'status_rank' => 10,
				'created_at' => '2026-09-01 10:00:00',
			),
			array(
				'id' => 12,
				'order_id' => 20,
				'vendor_id' => 30,
				'direction' => 'inbound',
				'is_test' => 1,
				'active' => 1,
				'status' => 'preposted',
				'status_rank' => 10,
				'created_at' => '2026-09-01 10:00:00',
			),
		);
	}

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}

	public function query( $query ) { return true; }

	public function get_row( $query, $format ) {
		if ( preg_match( '/WHERE id = (\d+)/', $query, $matches ) ) {
			foreach ( $this->shipments as $shipment ) {
				if ( (int) $shipment['id'] === (int) $matches[1] ) {
					return $shipment;
				}
			}
		}
		return null;
	}

	public function get_results( $query, $format ) { return $this->shipments; }

	public function insert( $table, $data, $format = null ) {
		if ( false !== strpos( $table, 'tracking_events' ) ) {
			if ( isset( $this->event_keys[ $data['event_key'] ] ) ) {
				$this->last_error = 'Duplicate entry';
				return false;
			}
			$this->event_keys[ $data['event_key'] ] = true;
			$this->events[] = $data;
		}
		++$this->insert_id;
		return 1;
	}

	public function update( $table, $data, $where ) {
		foreach ( $this->shipments as $index => $shipment ) {
			if ( false !== strpos( $table, 'shipments' ) && (int) $shipment['id'] === (int) $where['id'] ) {
				$this->shipments[ $index ] = array_merge( $shipment, $data );
			}
		}
		return 1;
	}
}

$GLOBALS['wpdb'] = new Papelito_Tracking_Simulation_Test_Wpdb();

echo "Scenario 1: local fixtures use the documented Rastro event sequence\n";
$fixture = papelito_tracking_simulation_fixture_event( 'delivered', new DateTimeImmutable( '2026-09-01 10:00:00', new DateTimeZone( 'UTC' ) ) );
papelito_tracking_simulation_assert( 'delivered fixture uses BDE/01', 'BDE', $fixture['codigo'] ?? null );
papelito_tracking_simulation_assert( 'delivered fixture keeps a deterministic offset', '2026-09-01T10:03:00+00:00', $fixture['dtHrCriado'] ?? null );
papelito_tracking_simulation_assert( 'unsupported fixture is rejected', null, papelito_tracking_simulation_fixture_event( 'cancelled', new DateTimeImmutable() ) );

echo "Scenario 2: only outbound test shipments can be selected\n";
$shipments = papelito_tracking_simulation_test_shipments( 20 );
papelito_tracking_simulation_assert( 'one eligible shipment is returned', 1, count( $shipments ) );
papelito_tracking_simulation_assert( 'eligible shipment is the outbound test row', 10, $shipments[0]['id'] ?? null );

echo "Scenario 3: fixtures use the production event processor with an explicit local origin\n";
$started_at = papelito_tracking_simulation_started_at( $shipments[0] );
$results = papelito_tracking_simulation_apply_sequence( $shipments[0], array( 'posted', 'in_transit', 'out_for_delivery', 'delivered' ), $started_at );
papelito_tracking_simulation_assert( 'all four events are ingested', array( true, true, true, true ), array_column( $results, 'ingested' ) );
papelito_tracking_simulation_assert( 'shipment becomes delivered', 'delivered', $GLOBALS['wpdb']->shipments[0]['status'] );
papelito_tracking_simulation_assert( 'events retain local simulation origin', array( PAPELITO_TRACKING_SOURCE_LOCAL_SIMULATION, PAPELITO_TRACKING_SOURCE_LOCAL_SIMULATION, PAPELITO_TRACKING_SOURCE_LOCAL_SIMULATION, PAPELITO_TRACKING_SOURCE_LOCAL_SIMULATION ), array_column( $GLOBALS['wpdb']->events, 'source' ) );

echo "Scenario 4: a replay remains idempotent\n";
$replay = papelito_tracking_simulation_apply_sequence( $shipments[0], array( 'delivered' ), $started_at );
papelito_tracking_simulation_assert( 'duplicate event is not ingested again', false, $replay[0]['ingested'] ?? null );

exit( $failures > 0 ? 1 : 0 );
