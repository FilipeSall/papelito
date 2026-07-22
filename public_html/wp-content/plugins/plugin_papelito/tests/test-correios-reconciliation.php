<?php
/** Standalone regression tests for uncertain pre-postage reconciliation. */

define( 'ABSPATH', __DIR__ );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/papelito-test-content' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );

putenv( 'PAPELITO_PRIVATE_LABELS_DIR=' . sys_get_temp_dir() . '/papelito-test-labels' );
putenv( 'PAPELITO_CORREIOS_PREPOST_MODE=mock' );
putenv( 'PAPELITO_CORREIOS_PREPOST_MOCK_SCENARIO=success' );
putenv( 'PAPELITO_CORREIOS_DEV_HEALTH_SOURCE=mock' );
putenv( 'PAPELITO_CORREIOS_DEV_HEALTH_SCENARIO=healthy' );
putenv( 'PAPELITO_CORREIOS_MANUAL_TRACKING_ENABLED=true' );

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function apply_filters( $hook, $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function sanitize_file_name( $value ) { return basename( (string) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $type, $gmt = false ) { return '2026-07-21 18:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_get_environment_type() { return 'local'; }
function wp_mkdir_p( $path ) { return is_dir( $path ) || @mkdir( $path, 0777, true ); }
function trailingslashit( $value ) { return rtrim( (string) $value, '/' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
	public function add_data( $data ) { $this->data = $data; }
}

final class Papelito_Reconciliation_Test_Order {
	public function get_id() { return 11889; }
	public function get_meta( $key, $single = true ) { return '03298'; }
}

final class Papelito_Reconciliation_Test_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $rows = array();

	public function get_charset_collate() { return ''; }
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[ds]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function get_row( $query, $format = ARRAY_A ) {
		foreach ( $this->rows as $row ) {
			if ( false !== strpos( $query, 'WHERE id = ' . (int) $row['id'] ) || false !== strpos( $query, "id = '" . (int) $row['id'] . "'" ) ) {
				return $row;
			}
		}
		return null;
	}
	public function get_results( $query, $format = ARRAY_A ) {
		return array_values( array_filter( $this->rows, static fn( array $row ): bool => ! empty( $row['active'] ) ) );
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		foreach ( $this->rows as $index => $row ) {
			if ( isset( $where['id'] ) && (int) $row['id'] !== (int) $where['id'] ) {
				continue;
			}
			$this->rows[ $index ] = array_merge( $row, $data );
			return 1;
		}
		return 0;
	}
	public function query( $query ) { return true; }
}

$GLOBALS['wpdb'] = new Papelito_Reconciliation_Test_Wpdb();

require __DIR__ . '/../includes/correios_prepostage.php';
require __DIR__ . '/../includes/correios_tracking.php';

$failures = 0;
function papelito_reconciliation_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario 1: uncertain mock attempt is reconciled as created\n";
$attempt = array(
	'id'                      => 2,
	'order_id'                => 11889,
	'vendor_id'               => 2150,
	'provider'                => 'mock',
	'generation_status'       => 'uncertain',
	'creation_outcome'        => 'created',
	'reconciliation_status'   => 'pending',
	'reconciliation_attempts' => 0,
	'idempotency_key'         => hash( 'sha256', 'shipment-v1|11889|2150|mock|03298' ),
	'status'                  => 'tracking_pending',
	'active'                  => 1,
	'is_test'                 => 1,
);
$GLOBALS['wpdb']->rows = array( $attempt );
$result = papelito_tracking_reconcile_generation( new Papelito_Reconciliation_Test_Order(), 2150, $attempt, 'test' );
$row = $GLOBALS['wpdb']->rows[0];
papelito_reconciliation_assert( 'reconciliation returns a snapshot', true, is_array( $result ) && isset( $result['shipments'] ) );
papelito_reconciliation_assert( 'attempt becomes generated', 'generated', $row['generation_status'] );
papelito_reconciliation_assert( 'created outcome is preserved', 'created', $row['creation_outcome'] );
papelito_reconciliation_assert( 'tracking code is restored', true, '' !== (string) $row['tracking_code'] );
papelito_reconciliation_assert( 'private label key is stored', true, '' !== (string) $row['label_storage_key'] );

echo "Scenario 2: real provider without reconciliation support requires support review\n";
$real_attempt = array_merge(
	$attempt,
	array(
		'id'                    => 3,
		'provider'              => 'correios',
		'generation_status'     => 'uncertain',
		'tracking_code'         => null,
		'label_storage_key'     => null,
		'support_review_required' => 0,
	)
);
$GLOBALS['wpdb']->rows = array( $real_attempt );
$error = papelito_tracking_reconcile_generation( new Papelito_Reconciliation_Test_Order(), 2150, $real_attempt, 'test' );
$row = $GLOBALS['wpdb']->rows[0];
papelito_reconciliation_assert( 'unsupported real reconciliation returns WP_Error', true, is_wp_error( $error ) );
papelito_reconciliation_assert( 'support review is required', 1, $row['support_review_required'] );
papelito_reconciliation_assert( 'manual fallback remains blocked', 0, $row['manual_fallback_eligible'] );

echo "Scenario 3: local mock storage failures expose manual fallback\n";
$blocked_dir = sys_get_temp_dir() . '/papelito-test-labels-blocked';
@unlink( $blocked_dir );
file_put_contents( $blocked_dir, 'blocked' );
putenv( 'PAPELITO_PRIVATE_LABELS_DIR=' . $blocked_dir );
$storage_attempt = array_merge(
	$attempt,
	array(
		'id'                    => 4,
		'provider'              => 'mock',
		'generation_status'     => 'uncertain',
		'tracking_code'         => null,
		'label_storage_key'     => null,
		'support_review_required' => 0,
	)
);
$GLOBALS['wpdb']->rows = array( $storage_attempt );
$error = papelito_tracking_reconcile_generation( new Papelito_Reconciliation_Test_Order(), 2150, $storage_attempt, 'test' );
$row = $GLOBALS['wpdb']->rows[0];
papelito_reconciliation_assert( 'mock storage failure returns WP_Error', true, is_wp_error( $error ) );
papelito_reconciliation_assert( 'mock storage failure is safe not-created', 'not_created', $row['creation_outcome'] );
papelito_reconciliation_assert( 'mock storage failure becomes failed', 'failed', $row['generation_status'] );
papelito_reconciliation_assert( 'mock storage failure opens manual fallback', 1, $row['manual_fallback_eligible'] );
papelito_reconciliation_assert( 'error response exposes manual fallback', true, $error->get_error_data()['manual_fallback_available'] );
@unlink( $blocked_dir );

exit( $failures > 0 ? 1 : 0 );
