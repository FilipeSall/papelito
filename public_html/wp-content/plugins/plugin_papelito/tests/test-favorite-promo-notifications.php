<?php
/**
 * Standalone regression tests for favorite-on-promo notifications.
 *
 * Usage: php tests/test-favorite-promo-notifications.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['papelito_actions']   = array();
$GLOBALS['papelito_filters']   = array();
$GLOBALS['papelito_users']     = array();
$GLOBALS['papelito_user_meta'] = array();
$GLOBALS['papelito_products']  = array();
$GLOBALS['papelito_posts']     = array();
$GLOBALS['papelito_post_meta'] = array();
$GLOBALS['papelito_options']   = array();
$GLOBALS['papelito_mail_log']  = array();
$GLOBALS['papelito_now']       = strtotime( '2026-06-12 12:00:00 UTC' );

class WP_User {
	public $ID;
	public $user_email;
	public $display_name;
	public $roles;

	public function __construct( int $id, string $email, string $display_name, array $roles = array() ) {
		$this->ID           = $id;
		$this->user_email   = $email;
		$this->display_name = $display_name;
		$this->roles        = $roles;
	}
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( string $code, string $message, array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;
	public $post_name;
	public $post_title;

	public function __construct( int $id, string $post_type, string $post_status, string $post_name, string $post_title ) {
		$this->ID          = $id;
		$this->post_type   = $post_type;
		$this->post_status = $post_status;
		$this->post_name   = $post_name;
		$this->post_title  = $post_title;
	}
}

class WP_REST_Response {}
class WP_REST_Request {}
class WP_REST_Server {
	const READABLE   = 'GET';
	const EDITABLE   = 'POST';
	const CREATABLE  = 'POST';
	const DELETABLE  = 'DELETE';
}

class WC_DateTime {
	private $timestamp;

	public function __construct( int $timestamp ) {
		$this->timestamp = $timestamp;
	}

	public function getTimestamp(): int {
		return $this->timestamp;
	}
}

class WC_Product {
	private $id;
	private $name;
	private $slug;
	private $status;
	private $regular_price;
	private $sale_price;
	private $weight;
	private $starts_at;
	private $ends_at;

	public function __construct( array $args ) {
		$this->id            = (int) $args['id'];
		$this->name          = (string) $args['name'];
		$this->slug          = (string) ( $args['slug'] ?? sanitize_title( $this->name ) );
		$this->status        = (string) ( $args['status'] ?? 'publish' );
		$this->regular_price = (string) ( $args['regular_price'] ?? '' );
		$this->sale_price    = (string) ( $args['sale_price'] ?? '' );
		$this->weight        = (string) ( $args['weight'] ?? '1' );
		$this->starts_at     = isset( $args['starts_at'] ) ? (int) $args['starts_at'] : 0;
		$this->ends_at       = isset( $args['ends_at'] ) ? (int) $args['ends_at'] : 0;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_sale_price( $context = 'view' ) {
		return $this->sale_price;
	}

	public function get_regular_price( $context = 'view' ) {
		return $this->regular_price;
	}

	public function get_price( $context = 'view' ) {
		return '' !== $this->sale_price ? $this->sale_price : $this->regular_price;
	}

	public function get_date_on_sale_from( $context = 'view' ) {
		return $this->starts_at > 0 ? new WC_DateTime( $this->starts_at ) : null;
	}

	public function get_date_on_sale_to( $context = 'view' ) {
		return $this->ends_at > 0 ? new WC_DateTime( $this->ends_at ) : null;
	}

	public function is_on_sale() {
		$now = (int) $GLOBALS['papelito_now'];

		if ( 'publish' !== $this->status || '' === $this->sale_price ) {
			return false;
		}

		$sale    = (float) $this->sale_price;
		$regular = (float) $this->regular_price;

		if ( $sale <= 0 || ( $regular > 0 && $sale >= $regular ) ) {
			return false;
		}

		if ( $this->starts_at > 0 && $now < $this->starts_at ) {
			return false;
		}

		if ( $this->ends_at > 0 && $now > $this->ends_at ) {
			return false;
		}

		return true;
	}

	public function get_image_id() {
		return 0;
	}

	public function get_average_rating() {
		return 0;
	}

	public function get_review_count() {
		return 0;
	}

	public function get_sku() {
		return '';
	}

	public function is_type( $type ) {
		return false;
	}

	public function get_children() {
		return array();
	}

	public function get_weight( $context = 'view' ) {
		return $this->weight;
	}
}

class WC_Coupon {
	private $id;
	private $code = '';
	private $discount_type = '';
	private $amount = 0;
	private $status = 'draft';

	public function __construct( ?int $id = null ) {
		$this->id = $id;
	}

	public function set_code( string $code ) {
		$this->code = $code;
	}

	public function set_discount_type( string $discount_type ) {
		$this->discount_type = $discount_type;
	}

	public function set_amount( $amount ) {
		$this->amount = $amount;
	}

	public function set_date_expires( $expires ) {}
	public function set_usage_limit( $value ) {}
	public function set_usage_limit_per_user( $value ) {}
	public function set_minimum_amount( $value ) {}

	public function set_status( string $status ) {
		$this->status = $status;
	}

	public function save() {
		if ( ! $this->id ) {
			$this->id = isset( $GLOBALS['papelito_coupon_auto_id'] ) ? ++$GLOBALS['papelito_coupon_auto_id'] : 501;
		}

		$GLOBALS['papelito_posts'][ $this->id ] = array(
			'post_type'    => 'shop_coupon',
			'post_status'  => $this->status,
			'post_name'    => sanitize_title( $this->code ),
			'post_title'   => $this->code,
			'modified_gmt' => (string) $GLOBALS['papelito_now'],
			'discount_type' => $this->discount_type,
			'amount'       => $this->amount,
		);

		return $this->id;
	}
}

class Papelito_Test_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 0;
	public $notification_rows = array();
	public $email_log_rows = array();
	private $next_notification_id = 1;
	private $next_email_log_id = 1;

	public function get_charset_collate() {
		return '';
	}

	public function prepare( $query, ...$args ) {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function get_results( $prepared, $output = ARRAY_A ) {
		$args    = is_array( $prepared ) ? $prepared['args'] : array();
		$user_id = (int) ( $args[0] ?? 0 );
		$type    = (string) ( $args[1] ?? '' );

		return array_values(
			array_filter(
				$this->notification_rows,
				static function ( array $row ) use ( $user_id, $type ) {
					return $row['user_id'] === $user_id && $row['type'] === $type;
				}
			)
		);
	}

	public function update( $table, $data, $where, $formats = array(), $where_formats = array() ) {
		foreach ( $this->notification_rows as $index => $row ) {
			if ( (int) $row['id'] === (int) ( $where['id'] ?? 0 ) ) {
				$this->notification_rows[ $index ] = array_merge( $row, $data );
				return 1;
			}
		}

		return 0;
	}

	public function insert( $table, $data, $formats ) {
		$this->last_error = '';

		if ( false !== strpos( $table, 'papelito_notifications' ) ) {
			foreach ( $this->notification_rows as $row ) {
				if (
					$row['user_id'] === $data['user_id'] &&
					$row['type'] === $data['type'] &&
					null !== $row['dedupe_key'] &&
					$row['dedupe_key'] === $data['dedupe_key']
				) {
					$this->last_error = 'Duplicate entry';
					return false;
				}
			}

			$data['id'] = $this->next_notification_id++;
			$this->insert_id = $data['id'];
			$this->notification_rows[] = $data;
			return 1;
		}

		if ( false !== strpos( $table, 'papelito_notification_email_log' ) ) {
			foreach ( $this->email_log_rows as $row ) {
				if (
					$row['user_id'] === $data['user_id'] &&
					$row['type'] === $data['type'] &&
					$row['dedupe_key'] === $data['dedupe_key']
				) {
					$this->last_error = 'Duplicate entry';
					return false;
				}
			}

			$data['id'] = $this->next_email_log_id++;
			$this->insert_id = $data['id'];
			$this->email_log_rows[] = $data;
			return 1;
		}

		return false;
	}
}

$wpdb = new Papelito_Test_WPDB();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['papelito_actions'][ $hook ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);
}

function do_action( $hook, ...$args ) {
	if ( empty( $GLOBALS['papelito_actions'][ $hook ] ) ) {
		return;
	}

	ksort( $GLOBALS['papelito_actions'][ $hook ] );

	foreach ( $GLOBALS['papelito_actions'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $item ) {
			call_user_func_array( $item['callback'], array_slice( $args, 0, $item['accepted_args'] ) );
		}
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['papelito_filters'][ $hook ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);
}

function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['papelito_filters'][ $hook ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['papelito_filters'][ $hook ] );

	foreach ( $GLOBALS['papelito_filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $item ) {
			$params = array_merge( array( $value ), array_slice( $args, 0, $item['accepted_args'] - 1 ) );
			$value  = call_user_func_array( $item['callback'], $params );
		}
	}

	return $value;
}

function register_rest_route( ...$args ) {}
function is_user_logged_in() { return true; }
function current_user_can( ...$args ) { return true; }
function user_can( $user_id, $capability ) { return false; }
function wp_is_post_revision( $post_id ) { return false; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $expiration ) { return true; }
function get_posts( $args ) { return array(); }
function rest_sanitize_boolean( $value ) { return (bool) $value; }

function sanitize_key( $value ) {
	$value = strtolower( trim( (string) $value ) );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}

function sanitize_title( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( $value, '-' );
}

function absint( $value ) {
	return abs( (int) $value );
}

function is_email( $value ) {
	return false !== strpos( (string) $value, '@' );
}

function sanitize_email( $value ) {
	return trim( (string) $value );
}

function wp_unslash( $value ) {
	return $value;
}

function wp_strip_all_tags( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function current_time( $type, $gmt = false ) {
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s', (int) $GLOBALS['papelito_now'] );
	}

	if ( 'timestamp' === $type ) {
		return (int) $GLOBALS['papelito_now'];
	}

	return (int) $GLOBALS['papelito_now'];
}

function wp_date( $format, $timestamp ) {
	return gmdate( $format, (int) $timestamp );
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function __return_true() {
	return true;
}

function get_user_meta( $user_id, $key, $single = true ) {
	if ( ! isset( $GLOBALS['papelito_user_meta'][ $user_id ][ $key ] ) ) {
		return $single ? '' : array();
	}

	$value = $GLOBALS['papelito_user_meta'][ $user_id ][ $key ];
	return $single ? $value : (array) $value;
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['papelito_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['papelito_user_meta'][ $user_id ][ $key ] );
	return true;
}

function get_user_by( $field, $value ) {
	if ( 'id' !== $field ) {
		return false;
	}

	return $GLOBALS['papelito_users'][ (int) $value ] ?? false;
}

function get_users( $args = array() ) {
	$users = array_values( $GLOBALS['papelito_users'] );

	if ( isset( $args['role'] ) ) {
		$users = array_values(
			array_filter(
				$users,
				static function ( WP_User $user ) use ( $args ) {
					return in_array( $args['role'], $user->roles, true );
				}
			)
		);
	}

	if ( isset( $args['meta_key'] ) ) {
		$users = array_values(
			array_filter(
				$users,
				static function ( WP_User $user ) use ( $args ) {
					return ! empty( get_user_meta( $user->ID, $args['meta_key'], true ) );
				}
			)
		);
	}

	if ( isset( $args['fields'] ) && 'ID' === $args['fields'] ) {
		return array_map(
			static function ( WP_User $user ) {
				return $user->ID;
			},
			$users
		);
	}

	return $users;
}

function wc_get_product( $product_id ) {
	return $GLOBALS['papelito_products'][ (int) $product_id ] ?? null;
}

function get_post_status( $post_id ) {
	$product = wc_get_product( (int) $post_id );
	if ( $product instanceof WC_Product ) {
		return $product->get_status();
	}

	return $GLOBALS['papelito_posts'][ (int) $post_id ]['post_status'] ?? '';
}

function get_post( $post_id ) {
	if ( ! isset( $GLOBALS['papelito_posts'][ (int) $post_id ] ) ) {
		return null;
	}

	$post = $GLOBALS['papelito_posts'][ (int) $post_id ];
	return new WP_Post( (int) $post_id, $post['post_type'], $post['post_status'], $post['post_name'], $post['post_title'] );
}

function get_post_field( $field, $post_id ) {
	if ( 'post_name' === $field ) {
		$product = wc_get_product( (int) $post_id );
		return $product instanceof WC_Product ? sanitize_title( $product->get_name() ) : ( $GLOBALS['papelito_posts'][ (int) $post_id ]['post_name'] ?? '' );
	}

	return '';
}

function get_the_title( $post_id ) {
	$product = wc_get_product( (int) $post_id );
	return $product instanceof WC_Product ? $product->get_name() : '';
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['papelito_post_meta'][ (int) $post_id ][ $key ] = $value;
}

function get_post_modified_time( $format, $gmt, $post_id ) {
	return $GLOBALS['papelito_posts'][ (int) $post_id ]['modified_gmt'] ?? '';
}

function get_option( $key, $default = array() ) {
	return $GLOBALS['papelito_options'][ $key ] ?? $default;
}

function update_option( $key, $value, $autoload = false ) {
	$GLOBALS['papelito_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['papelito_options'][ $key ] );
	return true;
}

function wp_timezone(): DateTimeZone {
	return new DateTimeZone( 'UTC' );
}

function wc_format_decimal( $value ) {
	return str_replace( ',', '.', (string) $value );
}

function wc_price( $value ) {
	return 'R$ ' . number_format( (float) $value, 2, ',', '.' );
}

function get_permalink( $post_id ) {
	return 'https://papelito.test/produtos/' . (int) $post_id;
}

function wp_get_attachment_image_url( $image_id, $size ) {
	return '';
}

function wc_get_product_terms( $product_id, $taxonomy, $args ) {
	return array();
}

function wp_mail( $to, $subject, $message, $headers ) {
	$GLOBALS['papelito_mail_log'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
	);
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['papelito_scheduled_events'][ $hook ] = array(
		'timestamp' => (int) $timestamp,
		'args'      => $args,
	);
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	unset( $GLOBALS['papelito_scheduled_events'][ $hook ] );
	return true;
}

function function_exists_override() {
	return true;
}

$GLOBALS['papelito_scheduled_events'] = array();

require __DIR__ . '/../includes/favorites.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/flash_sale.php';
require __DIR__ . '/../includes/coupons.php';

function papelito_reset_notification_state() {
	global $wpdb;

	$wpdb->notification_rows         = array();
	$wpdb->email_log_rows           = array();
	$GLOBALS['papelito_mail_log']   = array();
	$GLOBALS['papelito_scheduled_events'] = array();
}

function papelito_seed_user( int $user_id, string $email, bool $email_enabled, array $favorites ) {
	$GLOBALS['papelito_users'][ $user_id ] = new WP_User( $user_id, $email, 'Cliente ' . $user_id, array( 'customer' ) );
	update_user_meta( $user_id, PAPELITO_FAVORITES_META_KEY, $favorites );
	update_user_meta( $user_id, PAPELITO_FAVORITE_PROMO_EMAIL_META, $email_enabled ? '1' : '0' );
	update_user_meta( $user_id, 'first_name', 'Cliente' . $user_id );
}

function papelito_seed_product( array $args ) {
	$product = new WC_Product( $args );
	$GLOBALS['papelito_products'][ $product->get_id() ] = $product;
	return $product;
}

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

papelito_seed_user(
	11,
	'cliente11@papelito.test',
	true,
	array(
		array(
			'product_id' => 10,
			'added_at'   => '2026-06-10T10:00:00Z',
		),
	)
);

papelito_seed_product(
	array(
		'id'            => 10,
		'name'          => 'Tubelito Tradicional',
		'status'        => 'publish',
		'regular_price' => '20.00',
		'sale_price'    => '',
	)
);

echo "Scenario 1: coupon publish creates one in-app notification and one email\n";
papelito_reset_notification_state();
$coupon_id = papelito_coupon_persist(
	array(
		'code'                 => 'PAPELITO25',
		'discount_type'        => 'percent',
		'amount'               => 25,
		'date_expires_ts'      => null,
		'usage_limit'          => 0,
		'usage_limit_per_user' => 0,
		'minimum_amount'       => 0,
		'status'               => 'publish',
		'role'                 => 'customer',
		'vendor_ids'           => array(),
		'product_ids'          => array( 10 ),
	),
	null
);
papelito_assert( 'coupon saved', true, ! is_wp_error( $coupon_id ) );
papelito_assert( 'notification count after coupon publish', 1, count( $wpdb->notification_rows ) );
papelito_assert( 'email count after coupon publish', 1, count( $GLOBALS['papelito_mail_log'] ) );
papelito_assert( 'email log claim count after coupon publish', 1, count( $wpdb->email_log_rows ) );

echo "Scenario 2: reprocessing the same coupon event does not duplicate\n";
$same_event_key = $wpdb->notification_rows[0]['dedupe_key'];
do_action(
	'papelito_product_on_promo',
	10,
	array(
		'promo_type'      => 'coupon',
		'promo_label'     => 'PAPELITO25',
		'promo_event_key' => $same_event_key,
		'discount_percent' => 25,
	)
);
papelito_assert( 'notification count stays stable on duplicate event', 1, count( $wpdb->notification_rows ) );
papelito_assert( 'email count stays stable on duplicate event', 1, count( $GLOBALS['papelito_mail_log'] ) );

echo "Scenario 3: different promo events for the same product generate separate notifications\n";
do_action(
	'papelito_product_on_promo',
	10,
	array(
		'promo_type'      => 'flash_sale',
		'promo_label'     => 'Oferta Relâmpago',
		'promo_event_key' => 'flash_sale:10:2026-06-12T14:00:00+00:00',
		'discount_percent' => 30,
		'regular_price'    => 20,
		'sale_price'       => 14,
	)
);
papelito_assert( 'notification count grows for a new promo event', 2, count( $wpdb->notification_rows ) );
papelito_assert( 'email count grows for a new promo event', 2, count( $GLOBALS['papelito_mail_log'] ) );

echo "Scenario 4: preference off still sends in-app notification but skips email\n";
papelito_reset_notification_state();
update_user_meta( 11, PAPELITO_FAVORITE_PROMO_EMAIL_META, '0' );
do_action(
	'papelito_product_on_promo',
	10,
	array(
		'promo_type'      => 'coupon',
		'promo_label'     => 'PAPELITO15',
		'promo_event_key' => 'coupon:900:10:1718199999',
		'discount_percent' => 15,
	)
);
papelito_assert( 'notification still created with email disabled', 1, count( $wpdb->notification_rows ) );
papelito_assert( 'email skipped with preference disabled', 0, count( $GLOBALS['papelito_mail_log'] ) );
papelito_assert( 'email log not claimed when preference disabled', 0, count( $wpdb->email_log_rows ) );
update_user_meta( 11, PAPELITO_FAVORITE_PROMO_EMAIL_META, '1' );

echo "Scenario 5: future-dated manual sale schedules first, then dispatches at start time\n";
papelito_reset_notification_state();
$future_start = $GLOBALS['papelito_now'] + 3600;
$future_end   = $future_start + 3600;
$current_product = new WC_Product(
	array(
		'id'            => 10,
		'name'          => 'Tubelito Tradicional',
		'status'        => 'publish',
		'regular_price' => '20.00',
		'sale_price'    => '15.00',
		'starts_at'     => $future_start,
		'ends_at'       => $future_end,
	)
);
papelito_capture_product_promo_state_before_save( $current_product );
$GLOBALS['papelito_products'][10] = $current_product;
papelito_handle_product_promo_state_after_save( 10, $current_product );
papelito_assert( 'no immediate notification for future manual sale', 0, count( $wpdb->notification_rows ) );
papelito_assert( 'manual sale schedule created', PAPELITO_PRODUCT_PROMO_SCHEDULE_HOOK, array_key_first( $GLOBALS['papelito_scheduled_events'] ) );
$GLOBALS['papelito_now'] = $future_start + 5;
papelito_process_scheduled_product_promo( 10 );
papelito_assert( 'manual sale dispatches when schedule runs', 1, count( $wpdb->notification_rows ) );
papelito_assert( 'manual sale sends one email when schedule runs', 1, count( $GLOBALS['papelito_mail_log'] ) );

echo "Scenario 6: future flash sale waits until scheduled hook\n";
papelito_reset_notification_state();
$real_now = time();
$GLOBALS['papelito_now'] = $real_now;
$GLOBALS['papelito_products'][10] = papelito_seed_product(
	array(
		'id'            => 10,
		'name'          => 'Tubelito Tradicional',
		'status'        => 'publish',
		'regular_price' => '20.00',
		'sale_price'    => '',
	)
);
$campaign = array(
	'title'           => 'Flash Teste',
	'starts_at'       => gmdate( DATE_ATOM, $real_now + 7200 ),
	'ends_at'         => gmdate( DATE_ATOM, $real_now + 10800 ),
	'productIds'      => array( 10 ),
	'discountPercent' => 40,
	'label'           => 'Oferta Relâmpago',
	'supportingText'  => '',
);
update_option( papelito_flash_sale_option_name(), $campaign, false );
papelito_flash_sale_sync_promo_schedule( $campaign );
papelito_assert( 'flash sale schedule created', PAPELITO_FLASH_SALE_PROMO_HOOK, array_key_first( $GLOBALS['papelito_scheduled_events'] ) );
papelito_assert( 'flash sale has not notified before start', 0, count( $wpdb->notification_rows ) );
$active_campaign = $campaign;
$active_campaign['starts_at'] = gmdate( DATE_ATOM, $real_now - 60 );
$active_campaign['ends_at']   = gmdate( DATE_ATOM, $real_now + 3600 );
update_option( papelito_flash_sale_option_name(), $active_campaign, false );
$GLOBALS['papelito_now'] = $real_now + 120;
papelito_flash_sale_dispatch_scheduled_promo_events();
papelito_assert( 'flash sale dispatches one notification at start', 1, count( $wpdb->notification_rows ) );
papelito_assert( 'flash sale dispatches one email at start', 1, count( $GLOBALS['papelito_mail_log'] ) );

echo "Scenario 7: removed favorites and unpublished products do not notify\n";
papelito_reset_notification_state();
update_user_meta( 11, PAPELITO_FAVORITES_META_KEY, array() );
do_action(
	'papelito_product_on_promo',
	10,
	array(
		'promo_type'      => 'coupon',
		'promo_label'     => 'SEM-FAVORITO',
		'promo_event_key' => 'coupon:901:10:1718201111',
	)
);
papelito_assert( 'no notifications after favorite removal', 0, count( $wpdb->notification_rows ) );
update_user_meta(
	11,
	PAPELITO_FAVORITES_META_KEY,
	array(
		array(
			'product_id' => 10,
			'added_at'   => '2026-06-10T10:00:00Z',
		),
	)
);
$GLOBALS['papelito_products'][10] = papelito_seed_product(
	array(
		'id'            => 10,
		'name'          => 'Tubelito Tradicional',
		'status'        => 'draft',
		'regular_price' => '20.00',
		'sale_price'    => '10.00',
	)
);
do_action(
	'papelito_product_on_promo',
	10,
	array(
		'promo_type'      => 'sale_price',
		'promo_label'     => 'Produto em rascunho',
		'promo_event_key' => 'sale_price:10:10.00:20.00:0:0',
	)
);
papelito_assert( 'no notifications for unpublished product', 0, count( $wpdb->notification_rows ) );

echo "Scenario 8: product price and weight use one consolidated notification\n";
papelito_reset_notification_state();
$GLOBALS['papelito_users'][99] = new WP_User( 99, 'admin@papelito.test', 'Admin', array( 'administrator' ) );
$GLOBALS['papelito_products'][20] = papelito_seed_product(
	array(
		'id'            => 20,
		'name'          => 'Produto pendente',
		'status'        => 'publish',
		'regular_price' => '',
		'weight'        => '1',
	)
);
papelito_sync_product_data_notification( 20 );
papelito_assert( 'only missing price creates one notification', 1, count( $wpdb->notification_rows ) );
papelito_assert( 'price-only type is consolidated', PAPELITO_NOTIF_PRODUCT_DATA_INCOMPLETE, $wpdb->notification_rows[0]['type'] );
$payload = json_decode( $wpdb->notification_rows[0]['payload'], true );
papelito_assert( 'price-only payload marks price', true, $payload['missing_price'] );
papelito_assert( 'price-only payload keeps weight valid', false, $payload['missing_weight'] );
papelito_sync_product_data_notification( 20 );
papelito_assert( 'repeat scan does not duplicate notification', 1, count( $wpdb->notification_rows ) );

$GLOBALS['papelito_products'][20] = papelito_seed_product(
	array(
		'id'            => 20,
		'name'          => 'Produto pendente',
		'status'        => 'publish',
		'regular_price' => '10.00',
		'weight'        => '',
	)
);
papelito_sync_product_data_notification( 20 );
$payload = json_decode( $wpdb->notification_rows[0]['payload'], true );
papelito_assert( 'weight-only keeps one notification', 1, count( $wpdb->notification_rows ) );
papelito_assert( 'weight-only payload clears price issue', false, $payload['missing_price'] );
papelito_assert( 'weight-only payload marks weight', true, $payload['missing_weight'] );

$GLOBALS['papelito_products'][20] = papelito_seed_product(
	array(
		'id'            => 20,
		'name'          => 'Produto pendente',
		'status'        => 'publish',
		'regular_price' => '',
		'weight'        => '',
	)
);
papelito_sync_product_data_notification( 20 );
$payload = json_decode( $wpdb->notification_rows[0]['payload'], true );
papelito_assert( 'both issues remain in one notification', 1, count( $wpdb->notification_rows ) );
papelito_assert( 'both payload marks price', true, $payload['missing_price'] );
papelito_assert( 'both payload marks weight', true, $payload['missing_weight'] );

$GLOBALS['papelito_products'][20] = papelito_seed_product(
	array(
		'id'            => 20,
		'name'          => 'Produto pendente',
		'status'        => 'publish',
		'regular_price' => '10.00',
		'weight'        => '1',
	)
);
papelito_sync_product_data_notification( 20 );
papelito_assert( 'correction resolves the existing notification', true, ! empty( $wpdb->notification_rows[0]['read_at'] ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
