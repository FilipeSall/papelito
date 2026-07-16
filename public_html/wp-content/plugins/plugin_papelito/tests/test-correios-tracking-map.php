<?php
/** Standalone regression tests for the conservative Correios event map. */

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
function current_time( $type, $gmt = false ) { return '2026-07-16 15:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }

class WP_Error {}

require __DIR__ . '/../includes/correios_tracking.php';

$failures = 0;
function papelito_tracking_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: S10 tracking code validation\n";
papelito_tracking_assert( 'valid code is normalized', 'DG049186226BR', papelito_tracking_normalize_code( ' dg049186226br ' ) );
papelito_tracking_assert( 'invalid code is rejected', '', papelito_tracking_normalize_code( 'DG04918622BR' ) );

echo "Scenario 2: only officially confirmed public event combinations transition state\n";
papelito_tracking_assert( 'PO/01 means posted', 'posted', papelito_tracking_map_event( 'PO', '01' )['status'] ?? null );
papelito_tracking_assert( 'RO/01 means in transit', 'in_transit', papelito_tracking_map_event( 'RO', '01' )['status'] ?? null );
papelito_tracking_assert( 'OEC/03 means out for delivery', 'out_for_delivery', papelito_tracking_map_event( 'OEC', '03' )['status'] ?? null );
$delivered = papelito_tracking_map_event( 'BDE', '01' );
papelito_tracking_assert( 'BDE/01 means delivered', 'delivered', $delivered['status'] ?? null );
papelito_tracking_assert( 'BDE/01 is terminal', true, $delivered['terminal'] ?? false );
papelito_tracking_assert( 'different BDE type is not delivery proof', null, papelito_tracking_map_event( 'BDE', '02' ) );
papelito_tracking_assert( 'unknown final-looking event is ignored', null, papelito_tracking_map_event( 'LDI', '01' ) );

echo "Scenario 3: event fingerprint is deterministic and duplicate-safe\n";
$event = array(
	'codigo'      => 'BDE',
	'tipo'        => '01',
	'dtHrCriado'  => '2026-07-16T10:30:00',
	'descricao'   => 'Objeto entregue ao destinatario',
	'unidade'     => array( 'endereco' => array( 'cidade' => 'Brasilia', 'uf' => 'DF' ) ),
);
$first = papelito_tracking_event_key( 10, $event );
papelito_tracking_assert( 'same event has same fingerprint', $first, papelito_tracking_event_key( 10, $event ) );
$event['dtHrCriado'] = '2026-07-16T10:31:00';
papelito_tracking_assert( 'different timestamp has different fingerprint', false, $first === papelito_tracking_event_key( 10, $event ) );

class Papelito_Tracking_Test_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 0;
	public $shipment;
	public $shipments = array();
	public $event_keys = array();

	public function __construct() {
		$this->shipment = array(
			'id' => 10,
			'order_id' => 20,
			'vendor_id' => 30,
			'status' => 'preposted',
			'status_rank' => 10,
			'last_event_at' => null,
		);
		$this->shipments = array( $this->shipment );
	}

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}

	public function query( $query ) { return true; }

	public function get_row( $query, $format ) {
		return false !== strpos( $query, 'WHERE id = 10' ) ? $this->shipment : null;
	}

	public function get_results( $query, $format ) { return $this->shipments; }

	public function insert( $table, $data, $format = null ) {
		if ( false !== strpos( $table, 'tracking_events' ) ) {
			if ( isset( $this->event_keys[ $data['event_key'] ] ) ) {
				$this->last_error = 'Duplicate entry';
				return false;
			}
			$this->event_keys[ $data['event_key'] ] = true;
		}
		$this->insert_id++;
		return 1;
	}

	public function update( $table, $data, $where ) {
		if ( false !== strpos( $table, 'shipments' ) && (int) $where['id'] === 10 ) {
			$this->shipment = array_merge( $this->shipment, $data );
		}
		return 1;
	}
}

echo "Scenario 4: duplicate and out-of-order events cannot regress delivery\n";
$GLOBALS['wpdb'] = new Papelito_Tracking_Test_Wpdb();
$delivered_event = array(
	'codigo' => 'BDE',
	'tipo' => '01',
	'dtHrCriado' => '2026-07-16T14:00:00',
	'descricao' => 'Objeto entregue ao destinatario',
);
papelito_tracking_assert( 'first delivery event is stored', true, papelito_tracking_ingest_event( $GLOBALS['wpdb']->shipment, $delivered_event ) );
papelito_tracking_assert( 'shipment becomes delivered', 'delivered', $GLOBALS['wpdb']->shipment['status'] );
papelito_tracking_assert( 'duplicate delivery event is ignored', false, papelito_tracking_ingest_event( $GLOBALS['wpdb']->shipment, $delivered_event ) );
$older_event = array(
	'codigo' => 'OEC',
	'tipo' => '03',
	'dtHrCriado' => '2026-07-16T12:00:00',
	'descricao' => 'Objeto em rota de entrega',
);
papelito_tracking_assert( 'older event remains auditable', true, papelito_tracking_ingest_event( $GLOBALS['wpdb']->shipment, $older_event ) );
papelito_tracking_assert( 'older event does not regress delivered state', 'delivered', $GLOBALS['wpdb']->shipment['status'] );

echo "Scenario 5: one delivered package cannot complete a split order\n";
$GLOBALS['wpdb']->shipments = array(
	array_merge( $GLOBALS['wpdb']->shipment, array( 'id' => 10, 'status' => 'delivered', 'status_rank' => 100 ) ),
	array_merge( $GLOBALS['wpdb']->shipment, array( 'id' => 11, 'status' => 'in_transit', 'status_rank' => 40 ) ),
);
$snapshot = papelito_tracking_order_snapshot( 20 );
papelito_tracking_assert( 'aggregate remains in transit', 'in_transit', $snapshot['status'] );
papelito_tracking_assert( 'all packages done is false', false, $snapshot['all_packages_done'] );
papelito_tracking_assert( 'only one package is delivered', 1, $snapshot['packages_delivered'] );

exit( $failures > 0 ? 1 : 0 );
