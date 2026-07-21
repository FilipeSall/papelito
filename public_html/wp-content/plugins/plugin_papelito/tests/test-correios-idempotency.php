<?php
/** Standalone regression test for pre-provider shipment reservation. */

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
function sanitize_file_name( $value ) { return basename( (string) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $type, $gmt = false ) { return '2026-07-21 18:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function papelito_correios_manual_tracking_enabled() { return true; }
function papelito_correios_prepostage_is_test_environment() { return true; }

class WP_Error {
	private $code;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
	public function add_data( $data ) { $this->data = $data; }
}

final class Papelito_Idempotency_Test_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 0;
	public $rows = array();

	public function get_charset_collate() { return ''; }

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[ds]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function insert( $table, $data, $format = null ) {
		foreach ( $this->rows as $row ) {
			if ( isset( $data['idempotency_key'] ) && $row['idempotency_key'] === $data['idempotency_key'] ) {
				$this->last_error = 'Duplicate entry for uq_idempotency_key';
				return false;
			}
		}
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->rows[] = $data;
		return 1;
	}

	public function get_row( $query, $format ) {
		foreach ( $this->rows as $row ) {
			if ( false !== strpos( $query, (string) $row['idempotency_key'] ) ) {
				return $row;
			}
		}
		return null;
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
}

$GLOBALS['wpdb'] = new Papelito_Idempotency_Test_Wpdb();

require __DIR__ . '/../includes/correios_tracking.php';

$failures = 0;
function papelito_idempotency_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Scenario: two requests reserve only one logical shipment\n";
$key = hash( 'sha256', 'same-order-vendor-package' );
$first = papelito_tracking_reserve_generation( 11887, 55, 'correios', $key );
$second = papelito_tracking_reserve_generation( 11887, 55, 'correios', $key );
papelito_idempotency_assert( 'first request owns the reservation', false, $first['replay'] );
papelito_idempotency_assert( 'second request is an idempotent replay', true, $second['replay'] );
papelito_idempotency_assert( 'both requests resolve to the same shipment', $first['id'], $second['id'] );
papelito_idempotency_assert( 'database contains only one logical attempt', 1, count( $GLOBALS['wpdb']->rows ) );

echo "Scenario: only explicit not-created allowlisted failures enable manual fallback\n";
papelito_idempotency_assert( 'contract absence is safe after not_created', true, papelito_tracking_error_allows_manual_fallback( 'papelito_correios_service_not_contracted', 'not_created' ) );
papelito_idempotency_assert( 'unknown provider error is never promoted', false, papelito_tracking_error_allows_manual_fallback( 'unexpected_provider_error', 'not_created' ) );
papelito_idempotency_assert( 'uncertain result never enables manual fallback', false, papelito_tracking_error_allows_manual_fallback( 'papelito_correios_unavailable', 'uncertain' ) );
papelito_idempotency_assert( 'local unhealthy health is safe only in test environment', true, papelito_tracking_error_allows_manual_fallback( 'papelito_correios_dev_health_unhealthy', 'not_created' ) );

echo "Scenario: a safe failure persists fallback and cannot be reopened automatically\n";
$error = new WP_Error( 'papelito_correios_service_not_contracted', 'safe', array( 'creation_outcome' => 'not_created' ) );
papelito_tracking_fail_generation( $first['id'], $error );
$failed_row = $GLOBALS['wpdb']->rows[0];
papelito_idempotency_assert( 'safe failure becomes inactive', 0, $failed_row['active'] );
papelito_idempotency_assert( 'safe failure records not_created', 'not_created', $failed_row['creation_outcome'] );
papelito_idempotency_assert( 'safe failure opens manual fallback', 1, $failed_row['manual_fallback_eligible'] );
papelito_idempotency_assert( 'error response exposes manual fallback', true, $error->get_error_data()['manual_fallback_available'] );
$third = papelito_tracking_reserve_generation( 11887, 55, 'correios', $key );
papelito_idempotency_assert( 'automatic retry is blocked while manual fallback is open', true, $third['replay'] );
papelito_idempotency_assert( 'failed row remains failed', 'failed', $third['row']['generation_status'] );

exit( $failures > 0 ? 1 : 0 );
