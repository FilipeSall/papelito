<?php
/**
 * Regressão da edição e exclusão definitiva de Kits.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_TEMPORARY_ADMIN_MEDIA_META', '_papelito_temporary_admin_media' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

class WP_REST_Response {
	public function __construct( public array $data = array(), public int $status = 200 ) {}
}

class WP_REST_Request implements ArrayAccess {
	public function __construct( private array $params = array(), private array $json = array() ) {}
	public function get_json_params(): array { return $this->json; }
	public function __get( string $key ): mixed { return $this->params[ $key ] ?? null; }
	public function offsetExists( mixed $key ): bool { return isset( $this->params[ $key ] ); }
	public function offsetGet( mixed $key ): mixed { return $this->params[ $key ] ?? null; }
	public function offsetSet( mixed $key, mixed $value ): void {}
	public function offsetUnset( mixed $key ): void {}
}

class WP_REST_Server {
	public const READABLE = 'GET';
	public const CREATABLE = 'POST';
	public const EDITABLE = 'PUT';
	public const DELETABLE = 'DELETE';
}

class Kit_Admin_Delete_DB {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public string $postmeta = 'wp_postmeta';
	public string $posts = 'wp_posts';
	public string $options = 'wp_options';
	public array $kits = array(
		2 => array( 'id' => 2, 'product_id' => 900, 'image_source' => 'custom', 'image_attachment_id' => 70, 'package_length' => '20', 'package_width' => '10', 'package_height' => '5' ),
	);
	public array $merchandise = array(
		array( 'id' => 4, 'kit_id' => 2, 'name' => 'Brinde', 'image_attachment_id' => 71, 'quantity' => 1, 'weight' => '0.1', 'length' => '2', 'width' => '2', 'height' => '2' ),
	);
	public array $deletes = array();
	public array $queries = array();

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 );
			$query = preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 );
		}
		return $query;
	}
	public function get_row( string $query, $output = null ): ?array {
		if ( preg_match( '/WHERE id = (\d+)/', $query, $matches ) ) {
			return $this->kits[ (int) $matches[1] ] ?? null;
		}
		if ( preg_match( '/WHERE product_id = (\d+)/', $query, $matches ) ) {
			foreach ( $this->kits as $kit ) {
				if ( (int) $kit['product_id'] === (int) $matches[1] ) {
					return $kit;
				}
			}
		}
		return null;
	}
	public function get_results( string $query, $output = null ): array {
		return str_contains( $query, 'kit_merchandise' ) ? $this->merchandise : array();
	}
	public function get_var( string $query ): mixed { return null; }
	public function esc_like( string $value ): string { return $value; }
	public function delete( string $table, array $where, array $formats ): int|false {
		$this->deletes[] = array( $table, $where );
		if ( str_contains( $table, 'kits' ) && isset( $where['id'] ) ) {
			unset( $this->kits[ (int) $where['id'] ] );
		}
		return 1;
	}
	public function update( string $table, array $data, array $where, array $format, array $where_format ): int { return 1; }
	public function insert( string $table, array $data, array $format ): int { return 1; }
	public function query( string $query ): int {
		$this->queries[] = $query;
		return 1;
	}
}

class WC_Product {
	public function __construct( private int $id = 900 ) {}
	public function get_id(): int { return $this->id; }
	public function get_image_id(): int { return 70; }
	public function get_gallery_image_ids(): array { return array( 72 ); }
	public function set_name( string $value ): void {}
	public function set_slug( string $value ): void {}
	public function set_status( string $value ): void {}
	public function set_catalog_visibility( string $value ): void {}
	public function set_regular_price( string $value ): void {}
	public function set_sale_price( string $value ): void {}
	public function set_short_description( string $value ): void {}
	public function set_description( string $value ): void {}
	public function set_image_id( int $value ): void {}
	public function save(): int { return $this->id; }
}

class WC_Product_Simple extends WC_Product {}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
function register_rest_route( string $namespace, string $route, array $args ): void {}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_title( string $value ): string { return $value; }
function sanitize_key( string $value ): string { return $value; }
function wp_kses_post( string $value ): string { return $value; }
function wc_format_decimal( string $value ): string { return $value; }
function wp_attachment_is_image( int $attachment_id ): bool { return $attachment_id > 0; }
function wp_get_attachment_url( int $attachment_id ): string { return "https://example.test/media/{$attachment_id}.webp"; }
function wp_get_attachment_image_url( int $attachment_id, string $size ): string { return wp_get_attachment_url( $attachment_id ); }
function get_post( int $post_id ): ?object {
	$entry = $GLOBALS['pap_attachments'][ $post_id ] ?? null;
	if ( null === $entry ) {
		return null;
	}
	return is_object( $entry ) ? $entry : (object) array( 'post_parent' => 0 );
}
function wp_delete_attachment( int $attachment_id, bool $force ): bool { $GLOBALS['pap_deleted_attachments'][] = $attachment_id; unset( $GLOBALS['pap_attachments'][ $attachment_id ] ); return true; }
function wp_delete_post( int $post_id, bool $force ): bool { $GLOBALS['pap_deleted_products'][] = $post_id; return ! $GLOBALS['pap_fail_product_delete']; }
function delete_transient( string $key ): void { $GLOBALS['pap_deleted_transients'][] = $key; }
function current_user_can( string $capability ): bool { return 'manage_options' === $capability; }
function wc_get_product( int $product_id ): ?WC_Product { return $product_id > 0 ? new WC_Product( $product_id ) : null; }

$wpdb = new Kit_Admin_Delete_DB();
$GLOBALS['pap_attachments'] = array( 70 => true, 71 => true, 72 => true );
$GLOBALS['pap_deleted_attachments'] = array();
$GLOBALS['pap_deleted_products'] = array();
$GLOBALS['pap_deleted_transients'] = array();
$GLOBALS['pap_fail_product_delete'] = false;
require __DIR__ . '/../includes/admin_media_cleanup.php';
require __DIR__ . '/../includes/kits.php';

$failures = 0;
function kit_admin_delete_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

echo "Scenario 1: edição persistida aceita sucesso booleano\n";
$result = papelito_kit_persist_items( 2, array(), new WC_Product( 900 ) );
kit_admin_delete_assert( 'não lança TypeError e retorna true', true === $result );

echo "Scenario 2: exclusão remove Kit, produto e mídia exclusiva\n";
$response = papelito_kit_admin_delete( new WP_REST_Request( array( 'id' => 2 ) ) );
kit_admin_delete_assert( 'retorna sucesso', $response instanceof WP_REST_Response && true === $response->data['deleted'] );
kit_admin_delete_assert( 'remove o produto comercial', array( 900 ) === $GLOBALS['pap_deleted_products'] );
kit_admin_delete_assert( 'deduplica e remove as mídias exclusivas', array( 70, 72, 71 ) === $GLOBALS['pap_deleted_attachments'] );
kit_admin_delete_assert( 'invalida o cache público', array( 'papelito_kits_public' ) === $GLOBALS['pap_deleted_transients'] );

echo "Scenario 3: mídia vinculada a outro conteúdo é preservada\n";
$GLOBALS['pap_attachments'] = array( 88 => (object) array( 'post_parent' => 999 ), 999 => true );
$GLOBALS['pap_deleted_attachments'] = array();
$media_cleanup = papelito_kit_delete_attachments( array( 88 ) );
kit_admin_delete_assert( 'preserva mídia ainda vinculada', array( 88 ) === $media_cleanup['preservedIds'] );
kit_admin_delete_assert( 'não apaga mídia compartilhada', array() === $GLOBALS['pap_deleted_attachments'] );

echo "Scenario 4: falha do produto interrompe a exclusão antes da mídia\n";
$wpdb->kits[2] = array( 'id' => 2, 'product_id' => 900, 'image_source' => 'custom', 'image_attachment_id' => 70, 'package_length' => '20', 'package_width' => '10', 'package_height' => '5' );
$GLOBALS['pap_attachments'] = array( 70 => true, 71 => true, 72 => true );
$GLOBALS['pap_deleted_attachments'] = array();
$GLOBALS['pap_fail_product_delete'] = true;
$result = papelito_kit_admin_delete( new WP_REST_Request( array( 'id' => 2 ) ) );
kit_admin_delete_assert( 'retorna erro explícito', is_wp_error( $result ) && 'papelito_kit_product_delete_failed' === $result->code );
kit_admin_delete_assert( 'não apaga mídia se o produto falha', array() === $GLOBALS['pap_deleted_attachments'] );
kit_admin_delete_assert( 'executa rollback', in_array( 'ROLLBACK', $wpdb->queries, true ) );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) failed\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
