<?php
/**
 * Standalone regression test for collection cards.
 *
 * Usage: php tests/test-collection-cards.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['pap_options'] = array();
$GLOBALS['pap_routes']  = array();

function add_action( mixed $hook, mixed $callback ) {
	if ( 'rest_api_init' === $hook ) {
		$callback();
	}
}

function register_rest_route( mixed $namespace, mixed $route, mixed $args ) {
	$GLOBALS['pap_routes'][ $namespace . $route ][] = $args;
}

function current_user_can( mixed $cap ) { return 'manage_options' === $cap; }
function get_option( mixed $key, mixed $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( mixed $key, mixed $value, mixed $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function delete_option( mixed $key ) { unset( $GLOBALS['pap_options'][ $key ] ); return true; }
function get_transient( mixed $key ) { return $GLOBALS['pap_options'][ '_transient_' . $key ] ?? false; }
function set_transient( mixed $key, mixed $value, mixed $expiration = 0 ) { $GLOBALS['pap_options'][ '_transient_' . $key ] = $value; return true; }
function delete_transient( mixed $key ) { unset( $GLOBALS['pap_options'][ '_transient_' . $key ] ); return true; }
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_key( mixed $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) ); }
function wp_strip_all_tags( mixed $value ) { return strip_tags( (string) $value ); }
function wp_generate_uuid4() { return 'generated-card-id'; }
function current_time( mixed $type, mixed $gmt = false ) { return '2026-09-08 00:00:00'; }
function wp_list_pluck( array $list, mixed $field ) { return array_map( static fn( array $item ) => $item[ $field ] ?? null, $list ); }
function is_wp_error( mixed $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;

	public function __construct( string $code = '', string $message = '', mixed $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_REST_Response {
	public mixed $data;
	public int $status;

	public function __construct( mixed $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class WP_REST_Request {
	public function __construct( private mixed $params = array() ) {}
	public function get_json_params() { return $this->params; }
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'POST, PUT, PATCH';
	const CREATABLE = 'POST';
	const DELETABLE = 'DELETE';
}

class FakePapelitoWpdb {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public array $collections = array(
		array( 'id' => 1, 'slug' => 'premium', 'name' => 'Premium', 'description' => '', 'system_key' => '', 'image_attachment_id' => 0, 'image_url' => '', 'sort_order' => 1, 'is_active' => 1, 'archived_at' => null ),
		array( 'id' => 2, 'slug' => 'promocoes', 'name' => 'Promoções', 'description' => '', 'system_key' => 'promotions', 'image_attachment_id' => 0, 'image_url' => '', 'sort_order' => 2, 'is_active' => 1, 'archived_at' => null ),
		array( 'id' => 3, 'slug' => 'linha-eco', 'name' => 'Linha Eco', 'description' => '', 'system_key' => '', 'image_attachment_id' => 0, 'image_url' => '', 'sort_order' => 3, 'is_active' => 1, 'archived_at' => null ),
		array( 'id' => 4, 'slug' => 'novidades', 'name' => 'Recém Chegados', 'description' => '', 'system_key' => 'new_arrivals', 'image_attachment_id' => 0, 'image_url' => '', 'sort_order' => 4, 'is_active' => 1, 'archived_at' => null ),
		array( 'id' => 5, 'slug' => 'kits', 'name' => 'Kits', 'description' => '', 'system_key' => 'kits', 'image_attachment_id' => 0, 'image_url' => '', 'sort_order' => 5, 'is_active' => 1, 'archived_at' => null ),
	);
	public array $cards = array(
		'old-card' => array( 'id' => 'old-card', 'collection_id' => 3, 'title' => 'Linha Eco', 'subtitle' => 'Antes', 'indicator_key' => 'ITEM_COUNT', 'sort_order' => 1, 'is_active' => 0 ),
	);

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			if ( is_array( $arg ) ) {
				foreach ( $arg as $value ) {
					$replacement = "'" . addslashes( (string) $value ) . "'";
					$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
				}
				continue;
			}
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_results( string $query, mixed $output = null ): array {
		if ( str_contains( $query, 'SELECT * FROM wp_papelito_collections' ) ) {
			return array_values( array_filter( $this->collections, static fn( array $row ) => 1 === (int) $row['is_active'] ) );
		}
		if ( str_contains( $query, 'SELECT * FROM wp_papelito_collection_cards' ) ) {
			return array_values( $this->cards );
		}
		if ( str_contains( $query, 'SELECT c.id AS collection_id' ) || str_contains( $query, 'SELECT p.ID, p.post_date' ) ) {
			return array();
		}
		return array();
	}

	public function get_row( string $query, mixed $output = null ): ?array {
		if ( preg_match( '/collection_id = (\d+)/', $query, $match ) ) {
			foreach ( $this->cards as $card ) {
				if ( (int) $card['collection_id'] === (int) $match[1] ) {
					return array( 'id' => $card['id'] );
				}
			}
		}
		return null;
	}

	public function get_var( string $query ) {
		if ( str_contains( $query, 'SHOW TABLES LIKE' ) ) {
			return 'wp_papelito_collection_cards';
		}
		return null;
	}

	public function replace( string $table, array $data, array $formats = array() ) {
		$this->cards[ $data['id'] ] = $data;
		return 1;
	}

	public function query( string $query ) {
		if ( str_starts_with( $query, 'DELETE FROM wp_papelito_collection_cards WHERE id NOT IN' ) ) {
			preg_match_all( "/'([^']+)'/", $query, $matches );
			$keep = $matches[1];
			foreach ( array_keys( $this->cards ) as $id ) {
				if ( ! in_array( $id, $keep, true ) ) {
					unset( $this->cards[ $id ] );
				}
			}
		}
		return 1;
	}

	public function get_charset_collate() { return ''; }
}

function papelito_product_taxonomy_table_names(): array {
	return array(
		'collections'       => 'wp_papelito_collections',
		'product_collection' => 'wp_papelito_product_collection',
	);
}
function papelito_product_taxonomy_version() { return 12; }
function papelito_product_taxonomy_touch( mixed $scope, mixed $subject = 0 ) {}

$GLOBALS['wpdb'] = new FakePapelitoWpdb();

require_once __DIR__ . '/../includes/collection_cards.php';
require_once __DIR__ . '/../includes/home_assets.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} - expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

echo "Collection cards: policies and resilience\n";
$policy = papelito_collection_cards_policy( $GLOBALS['wpdb']->collections[1] );
papelito_assert( 'raw system_key locks promotions to max discount', true, $policy['locked'] );
papelito_assert( 'raw system_key chooses max discount', 'MAX_DISCOUNT_PERCENT', $policy['required'] );
papelito_assert( 'unknown indicator falls back to item count copy', '1 item para explorar', papelito_collection_cards_metric_copy( 'BROKEN_KEY', array( 'itemCount' => 1 ) )['text'] );
papelito_assert( 'common collections allow disabling dynamic text', true, in_array( 'NONE', papelito_collection_cards_policy( $GLOBALS['wpdb']->collections[2] )['allowed'], true ) );
papelito_assert( 'disabled dynamic text has no computed copy', '', papelito_collection_cards_metric_copy( 'NONE', array( 'itemCount' => 99 ) )['text'] );
papelito_assert( 'new arrivals keep a locked indicator', 'NEW_ITEMS_COUNT', papelito_collection_cards_policy( $GLOBALS['wpdb']->collections[3] )['required'] );
papelito_assert( 'kits keep a locked indicator', 'ITEM_COUNT', papelito_collection_cards_policy( $GLOBALS['wpdb']->collections[4] )['required'] );
papelito_assert( 'seven cards exceed the corridor limit', 'papelito_collection_card_limit', papelito_collection_cards_save( array_fill( 0, 7, array() ) )->get_error_code() );

echo "Collection cards: persistence and validation\n";
$saved = papelito_collection_cards_save(
		array(
			array( 'id' => 'new-card', 'collectionId' => 3, 'title' => 'Linha Eco', 'subtitle' => 'Depois', 'isActive' => true ),
		)
);
papelito_assert( 'reusing an inactive collection card keeps one row', 1, count( $GLOBALS['wpdb']->cards ) );
papelito_assert( 'reusing an inactive collection card preserves its row id', true, isset( $GLOBALS['wpdb']->cards['old-card'] ) );
papelito_assert( 'common collection defaults to no dynamic text', 'NONE', $GLOBALS['wpdb']->cards['old-card']['indicator_key'] );
papelito_assert( 'duplicate collection is rejected', 'papelito_collection_card_duplicate', papelito_collection_cards_save(
	array(
		array( 'id' => 'a', 'collectionId' => 1, 'title' => 'A', 'subtitle' => '', 'isActive' => true ),
		array( 'id' => 'b', 'collectionId' => 1, 'title' => 'B', 'subtitle' => '', 'isActive' => true ),
	)
)->get_error_code() );
papelito_assert( 'oversized title is rejected', 'papelito_collection_card_text_invalid', papelito_collection_cards_save(
	array( array( 'id' => 'long', 'collectionId' => 1, 'title' => str_repeat( 'a', 25 ), 'subtitle' => '', 'isActive' => true ) )
)->get_error_code() );
$punctuation_result = papelito_collection_cards_save(
	array( array( 'id' => '!!!', 'collectionId' => 1, 'title' => 'Premium', 'subtitle' => '', 'isActive' => true ) )
);
papelito_assert( 'punctuation-only ids receive a generated id', true, true === $punctuation_result && isset( $GLOBALS['wpdb']->cards['generated-card-id'] ) );

echo "Collection cards: REST error contract\n";
$route = $GLOBALS['pap_routes']['papelito/v1/admin/assets/collections-nav'][1]['callback'];
$response = $route( new WP_REST_Request( array( 'items' => array(
	array( 'id' => 'one', 'collectionId' => 1, 'title' => 'A', 'subtitle' => '', 'isActive' => true ),
	array( 'id' => 'two', 'collectionId' => 1, 'title' => 'B', 'subtitle' => '', 'isActive' => true ),
) ) ) );
papelito_assert( 'duplicate collection returns REST 409 instead of TypeError', 409, $response->status );

echo $failures > 0 ? "\n{$failures} failure(s)\n" : "\nAll assertions passed\n";
exit( $failures > 0 ? 1 : 0 );
