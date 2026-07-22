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
function apply_filters( string $hook, mixed $value ) { return $value; }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_file_name( mixed $value ) { return basename( (string) $value ); }
function sanitize_key( mixed $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( mixed $value ) { return abs( (int) $value ); }
function current_time( string $type, bool $gmt = false ) { return '2026-07-21 18:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_get_environment_type() { return 'local'; }
function wp_mkdir_p( string $path ) { return is_dir( $path ) || @mkdir( $path, 0777, true ); }
function trailingslashit( mixed $value ) { return rtrim( (string) $value, '/' ) . '/'; }
function untrailingslashit( mixed $value ) { return rtrim( (string) $value, '/' ); }
function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }
function wc_get_order( mixed $order_id ) { return $GLOBALS['papelito_test_order'] ?? null; }

class WP_Error {
	private mixed $code;
	private mixed $message;
	private mixed $data;
	public function __construct( mixed $code = '', mixed $message = '', mixed $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
	public function add_data( mixed $data ) { $this->data = $data; }
}

class WP_REST_Response {
	private mixed $data;
	private int $status;
	private array $headers = array();
	public function __construct( mixed $data = null, int $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function header( string $key, mixed $value ) { $this->headers[ $key ] = $value; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function get_headers() { return $this->headers; }
}

final class Papelito_Reconciliation_Test_Order {
	public $meta = array( '_papelito_vendor_status' => 'em_separacao' );
	public function get_id() { return 11889; }
	public function get_meta( string $key, bool $single = true ) { return '_papelito_shipping_service_code' === $key ? '03298' : ( $this->meta[ $key ] ?? '' ); }
	public function update_meta_data( string $key, mixed $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( string $note ) {}
	public function save() {}
	public function get_status() { return 'processing'; }
}

final class Papelito_Reconciliation_Test_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $rows = array();

	public function get_charset_collate() { return ''; }
	public function prepare( string $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[ds]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function get_row( string $query, string $format = ARRAY_A ) {
		foreach ( $this->rows as $row ) {
			if ( false !== strpos( $query, 'WHERE id = ' . (int) $row['id'] ) || false !== strpos( $query, "id = '" . (int) $row['id'] . "'" ) ) {
				return $row;
			}
		}
		return null;
	}
	public function get_results( string $query, string $format = ARRAY_A ) {
		return array_values( array_filter( $this->rows, static fn( array $row ): bool => ! empty( $row['active'] ) ) );
	}
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
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
function papelito_reconciliation_assert( string $label, mixed $expected, mixed $actual ): void {
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

echo "Scenario 4: missing local mock label is regenerated on download\n";
$labels_dir = sys_get_temp_dir() . '/papelito-test-labels-regenerate';
putenv( 'PAPELITO_PRIVATE_LABELS_DIR=' . $labels_dir );
wp_mkdir_p( $labels_dir );
$prepost_id = 'MOCK-PREPOST-11889-REGEN';
$tracking_code = papelito_correios_mock_tracking_code( 11889, 2150 );
$contents = papelito_correios_mock_pdf( $prepost_id, $tracking_code );
$key = hash( 'sha256', 'regenerated-mock-label' ) . '.pdf';
$path = trailingslashit( $labels_dir ) . $key;
@unlink( $path );
$GLOBALS['wpdb']->rows = array(
	array_merge(
		$attempt,
		array(
			'id'                => 5,
			'provider'          => 'mock',
			'generation_status' => 'generated',
			'prepost_id'        => $prepost_id,
			'tracking_code'     => $tracking_code,
			'label_storage_key' => $key,
			'label_sha256'      => hash( 'sha256', $contents ),
			'active'            => 1,
			'is_test'           => 1,
		)
	),
);
$response = papelito_tracking_private_label_response( 11889, 5, 2150 );
papelito_reconciliation_assert( 'mock label response is served', true, $response instanceof WP_REST_Response );
papelito_reconciliation_assert( 'mock label file is recreated', true, is_file( $path ) );
papelito_reconciliation_assert( 'mock label contents match regenerated PDF', $contents, $response->get_data() );
@unlink( $path );

echo "Scenario 5: posted local mock fixture projects the order as shipped\n";
putenv( 'PAPELITO_CORREIOS_DEV_TRACKING_SCENARIO=posted' );
$GLOBALS['papelito_test_order'] = new Papelito_Reconciliation_Test_Order();
$GLOBALS['wpdb']->rows = array(
	array_merge(
		$attempt,
		array(
			'id'                => 6,
			'provider'          => 'mock',
			'generation_status' => 'generated',
			'tracking_code'     => papelito_correios_mock_tracking_code( 11889, 2150 ),
			'active'            => 1,
			'is_test'           => 1,
			'status'            => 'preposted',
			'status_rank'       => 10,
		)
	),
);
papelito_tracking_apply_test_fixture_status( 6 );
$row = $GLOBALS['wpdb']->rows[0];
papelito_reconciliation_assert( 'mock fixture becomes posted', 'posted', $row['status'] );
papelito_reconciliation_assert( 'posted mock projects vendor status as shipped', 'enviado', $GLOBALS['papelito_test_order']->meta['_papelito_vendor_status'] ?? '' );

exit( $failures > 0 ? 1 : 0 );
