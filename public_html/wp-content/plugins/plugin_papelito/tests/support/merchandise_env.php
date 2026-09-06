<?php
/**
 * Ambiente compartilhado das harnesses standalone do catálogo de brindes.
 *
 * Não tem prefixo `test-` de propósito: não é um teste, é o cenário que
 * `test-merchandise-catalog.php` e `test-kit-merchandise-link.php` carregam. O
 * domínio cruza dois módulos (catálogo e Kits) e duplicar os stubs em dois
 * arquivos deixaria as duas cópias divergirem na primeira mudança de schema.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

class WP_REST_Response {
	public function __construct( public array $data = array(), public int $status = 200 ) {}
}

class WP_REST_Request implements ArrayAccess {
	public function __construct( private array $params = array(), private array $json = array() ) {}
	public function get_json_params(): array {
		return $this->json;
	}
	public function __get( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
	public function offsetExists( mixed $key ): bool {
		return isset( $this->params[ $key ] );
	}
	public function offsetGet( mixed $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
	public function offsetSet( mixed $key, mixed $value ): void {}
	public function offsetUnset( mixed $key ): void {}
}

class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
	public const EDITABLE  = 'PUT';
	public const DELETABLE = 'DELETE';
}

class WC_Product {
	public function __construct(
		private int $id = 0,
		private string $name = 'Produto',
		private string $weight = '0.1',
		private string $status = 'publish'
	) {}
	public function get_id(): int {
		return $this->id;
	}
	public function get_name(): string {
		return $this->name;
	}
	public function get_slug(): string {
		return 'slug-' . $this->id;
	}
	public function get_sku(): string {
		return 'SKU-' . $this->id;
	}
	public function get_weight( string $context = 'view' ): string {
		return $this->weight;
	}
	public function get_price(): string {
		return '10';
	}
	public function get_regular_price(): string {
		return '10';
	}
	public function get_sale_price(): string {
		return '';
	}
	public function get_short_description(): string {
		return '';
	}
	public function get_description(): string {
		return '';
	}
	public function get_status(): string {
		return $this->status;
	}
	public function get_image_id(): int {
		return 0;
	}
	public function get_gallery_image_ids(): array {
		return array();
	}
	public function is_on_sale(): bool {
		return false;
	}
	public function is_type( string $type ): bool {
		return 'simple' === $type;
	}
	public function set_name( string $value ): void {
		$this->name = $value;
	}
	public function set_slug( string $value ): void {}
	public function set_status( string $value ): void {
		$this->status = $value;
	}
	public function set_catalog_visibility( string $value ): void {}
	public function set_regular_price( string $value ): void {}
	public function set_sale_price( string $value ): void {}
	public function set_short_description( string $value ): void {}
	public function set_description( string $value ): void {}
	public function set_image_id( int $value ): void {}
	public function update_meta_data( string $key, mixed $value ): void {}
	public function save(): int {
		if ( in_array( $this->id, $GLOBALS['pap_fail_product_save'], true ) ) {
			throw new RuntimeException( 'falha simulada ao gravar o produto' );
		}
		if ( $this->id <= 0 ) {
			$this->id = ++$GLOBALS['pap_next_product_id'];
		}
		$GLOBALS['pap_products'][ $this->id ] = $this;
		return $this->id;
	}
}

class WC_Product_Simple extends WC_Product {}

/**
 * Banco em memória com as tabelas do catálogo, do vínculo e dos Kits.
 */
class Papelito_Merchandise_Test_DB {
	public string $prefix     = 'wp_';
	public string $posts      = 'wp_posts';
	public string $postmeta   = 'wp_postmeta';
	public string $options    = 'wp_options';
	public string $last_error = '';
	public int $insert_id     = 0;

	public array $merchandise = array();
	public array $links       = array();
	public array $kits        = array();
	public array $kit_items   = array();
	public array $queries     = array();
	public array $deletes     = array();

	/** Tabelas em que a próxima escrita deve falhar, para exercitar rollback. */
	public array $fail_insert_on = array();
	public array $fail_delete_on = array();

	private int $next_merchandise_id = 0;
	private int $next_kit_id         = 0;

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare( string $query, ...$args ) {
		$values = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
		foreach ( $values as $value ) {
			$query = preg_replace( '/%[dfs]/', is_numeric( $value ) ? (string) $value : "'" . (string) $value . "'", $query, 1 );
		}
		return $query;
	}

	public function get_row( string $query, $output = null ) {
		if ( str_contains( $query, 'FROM wp_papelito_merchandise WHERE id = ' ) ) {
			return $this->merchandise[ $this->idIn( $query, '/WHERE id = (\d+)/' ) ] ?? null;
		}
		if ( str_contains( $query, 'FROM wp_papelito_kits WHERE id = ' ) ) {
			return $this->kits[ $this->idIn( $query, '/WHERE id = (\d+)/' ) ] ?? null;
		}
		if ( str_contains( $query, 'FROM wp_papelito_kits WHERE product_id = ' ) ) {
			$product_id = $this->idIn( $query, '/WHERE product_id = (\d+)/' );
			foreach ( $this->kits as $kit ) {
				if ( (int) $kit['product_id'] === $product_id ) {
					return $kit;
				}
			}
		}
		return null;
	}

	public function get_results( string $query, $output = null ) {
		if ( str_contains( $query, 'wp_papelito_kit_merchandise_items l INNER JOIN wp_papelito_merchandise m' ) ) {
			return $this->kitMerchandise( $this->idIn( $query, '/l\.kit_id = (\d+)/' ) );
		}
		if ( str_contains( $query, 'wp_papelito_kit_merchandise_items l INNER JOIN wp_papelito_kits k' ) ) {
			return $this->merchandiseUsage( $this->idsIn( $query ) );
		}
		if ( str_contains( $query, 'FROM wp_papelito_merchandise WHERE id IN' ) ) {
			return array_values( array_intersect_key( $this->merchandise, array_flip( $this->idsIn( $query ) ) ) );
		}
		if ( str_contains( $query, 'FROM wp_papelito_merchandise ORDER BY' ) ) {
			$rows = array_values( $this->merchandise );
			usort( $rows, static fn( array $a, array $b ): int => array( $a['name'], $a['id'] ) <=> array( $b['name'], $b['id'] ) );
			return $rows;
		}
		if ( str_contains( $query, 'FROM wp_papelito_kit_items' ) ) {
			return $this->kit_items[ $this->idIn( $query, '/kit_id = (\d+)/' ) ] ?? array();
		}
		return array();
	}

	public function get_var( string $query ) {
		return null;
	}

	/**
	 * Atende o `SELECT id ... FOR UPDATE` do lock do catálogo.
	 */
	public function get_col( string $query ) {
		if ( str_contains( $query, 'FROM wp_papelito_merchandise WHERE id IN' ) ) {
			$this->queries[] = $query;
			return array_values( array_intersect( array_keys( $this->merchandise ), $this->idsIn( $query ) ) );
		}
		return array();
	}

	public function query( string $query ) {
		$this->queries[] = $query;
		return 1;
	}

	public function insert( string $table, array $data, array $formats ) {
		if ( in_array( $table, $this->fail_insert_on, true ) ) {
			return false;
		}
		if ( 'wp_papelito_merchandise' === $table ) {
			$id                       = ++$this->next_merchandise_id;
			$this->merchandise[ $id ] = array_merge( array( 'id' => $id ), $data );
			$this->insert_id          = $id;
			return 1;
		}
		if ( 'wp_papelito_kit_merchandise_items' === $table ) {
			$this->links[ $data['kit_id'] . ':' . $data['merchandise_id'] ] = $data;
			return 1;
		}
		if ( 'wp_papelito_kits' === $table ) {
			$id                = ++$this->next_kit_id;
			$this->kits[ $id ] = array_merge( array( 'id' => $id ), $data );
			$this->insert_id   = $id;
			return 1;
		}
		if ( 'wp_papelito_kit_items' === $table ) {
			$this->kit_items[ (int) $data['kit_id'] ][] = $data;
			return 1;
		}
		return 1;
	}

	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ) {
		if ( 'wp_papelito_merchandise' === $table && isset( $this->merchandise[ (int) $where['id'] ] ) ) {
			$this->merchandise[ (int) $where['id'] ] = array_merge( $this->merchandise[ (int) $where['id'] ], $data );
			return 1;
		}
		if ( 'wp_papelito_kits' === $table && isset( $this->kits[ (int) $where['id'] ] ) ) {
			$this->kits[ (int) $where['id'] ] = array_merge( $this->kits[ (int) $where['id'] ], $data );
			return 1;
		}
		return 1;
	}

	public function delete( string $table, array $where, array $formats = array() ) {
		$this->deletes[] = array( $table, $where );
		if ( in_array( $table, $this->fail_delete_on, true ) ) {
			return false;
		}
		if ( 'wp_papelito_merchandise' === $table ) {
			unset( $this->merchandise[ (int) $where['id'] ] );
			return 1;
		}
		if ( 'wp_papelito_kit_merchandise_items' === $table ) {
			foreach ( array_keys( $this->links ) as $key ) {
				if ( (int) $this->links[ $key ]['kit_id'] === (int) $where['kit_id'] ) {
					unset( $this->links[ $key ] );
				}
			}
			return 1;
		}
		if ( 'wp_papelito_kit_items' === $table ) {
			unset( $this->kit_items[ (int) $where['kit_id'] ] );
			return 1;
		}
		if ( 'wp_papelito_kits' === $table ) {
			unset( $this->kits[ (int) $where['id'] ] );
			return 1;
		}
		return 1;
	}

	public function esc_like( string $value ): string {
		return $value;
	}

	/**
	 * Vínculos de um Kit resolvidos contra o catálogo, como o JOIN faz.
	 */
	private function kitMerchandise( int $kit_id ): array {
		$rows = array();
		foreach ( $this->links as $link ) {
			$merchandise = $this->merchandise[ (int) $link['merchandise_id'] ] ?? null;
			if ( (int) $link['kit_id'] !== $kit_id || null === $merchandise ) {
				continue;
			}
			$rows[] = array(
				'id'                  => (int) $merchandise['id'],
				'name'                => $merchandise['name'],
				'image_attachment_id' => (int) $merchandise['image_attachment_id'],
				'quantity'            => (int) $link['quantity'],
				'weight'              => (string) $merchandise['weight'],
				'length'              => (string) $merchandise['length'],
				'width'               => (string) $merchandise['width'],
				'height'              => (string) $merchandise['height'],
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => array( $a['name'], $a['id'] ) <=> array( $b['name'], $b['id'] ) );
		return $rows;
	}

	/**
	 * Kits que usam cada brinde, com título e status vindos do produto comercial.
	 */
	private function merchandiseUsage( array $merchandise_ids ): array {
		$rows = array();
		foreach ( $this->links as $link ) {
			$kit = $this->kits[ (int) $link['kit_id'] ] ?? null;
			if ( null === $kit || ! in_array( (int) $link['merchandise_id'], $merchandise_ids, true ) ) {
				continue;
			}
			$product = $GLOBALS['pap_products'][ (int) $kit['product_id'] ] ?? null;
			$rows[]  = array(
				'merchandise_id' => (int) $link['merchandise_id'],
				'quantity'       => (int) $link['quantity'],
				'kit_id'         => (int) $kit['id'],
				'product_id'     => (int) $kit['product_id'],
				'post_title'     => $product ? $product->get_name() : '',
				'post_status'    => $product ? $product->get_status() : 'draft',
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => $a['post_title'] <=> $b['post_title'] );
		return $rows;
	}

	private function idIn( string $query, string $pattern ): int {
		preg_match( $pattern, $query, $matches );
		return (int) ( $matches[1] ?? 0 );
	}

	private function idsIn( string $query ): array {
		preg_match( '/IN \(([^)]*)\)/', $query, $matches );
		return array_values( array_filter( array_map( 'intval', explode( ',', $matches[1] ?? '' ) ) ) );
	}
}

$GLOBALS['pap_products']            = array();
$GLOBALS['pap_next_product_id']     = 900;
$GLOBALS['pap_attachments']         = array();
$GLOBALS['pap_deleted_attachments'] = array();
$GLOBALS['pap_referenced_media']    = array();
$GLOBALS['pap_deleted_transients']  = array();
$GLOBALS['pap_is_admin']            = true;
$GLOBALS['pap_fail_product_save']   = array();

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function absint( mixed $value ): int {
	return abs( (int) $value ); }
function sanitize_text_field( string $value ): string {
	return trim( $value ); }
function sanitize_title( string $value ): string {
	return $value; }
function sanitize_key( string $value ): string {
	return strtolower( $value ); }
function wp_kses_post( string $value ): string {
	return $value; }
function wc_format_decimal( string $value ): string {
	return str_replace( ',', '.', $value ); }
function wc_get_weight( float $weight, string $unit ): float {
	return 'g' === $unit ? $weight * 1000 : $weight; }
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
function do_action( string $hook, ...$args ): void {}
function register_rest_route( string $namespace, string $route, array $args ): void {}
function delete_transient( string $key ): bool {
	$GLOBALS['pap_deleted_transients'][] = $key;
	return true; }
function current_user_can( string $capability ): bool {
	return (bool) $GLOBALS['pap_is_admin']; }
function wp_attachment_is_image( int $attachment_id ): bool {
	return isset( $GLOBALS['pap_attachments'][ $attachment_id ] ); }
function wp_get_attachment_image_url( int $attachment_id, string $size = 'thumbnail' ) {
	return isset( $GLOBALS['pap_attachments'][ $attachment_id ] ) ? "https://cdn.test/{$attachment_id}.webp" : false; }
function wp_delete_attachment( int $attachment_id, bool $force = false ) {
	$GLOBALS['pap_deleted_attachments'][] = $attachment_id;
	unset( $GLOBALS['pap_attachments'][ $attachment_id ] );
	return true; }
function papelito_admin_media_cleanup_referenced( int $attachment_id ): bool {
	return in_array( $attachment_id, $GLOBALS['pap_referenced_media'], true ); }
function wc_get_product( int $product_id ) {
	return $GLOBALS['pap_products'][ $product_id ] ?? null; }
function wp_delete_post( int $post_id, bool $force = false ) {
	unset( $GLOBALS['pap_products'][ $post_id ] );
	return true; }

$wpdb = new Papelito_Merchandise_Test_DB();

require __DIR__ . '/../../includes/merchandise.php';
require __DIR__ . '/../../includes/kits.php';

$GLOBALS['papelito_test_failures'] = 0;

/**
 * Assert mínimo, no mesmo formato das demais harnesses standalone.
 */
function papelito_assert( string $label, bool $condition ): void {
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$GLOBALS['papelito_test_failures'];
	echo "  FAIL: {$label}\n";
}

/**
 * Encerra a harness com o código de saída correto.
 */
function papelito_test_result(): void {
	$failures = (int) $GLOBALS['papelito_test_failures'];
	if ( $failures > 0 ) {
		echo "RESULT: {$failures} assertion(s) failed\n";
		exit( 1 );
	}
	echo "RESULT: all assertions passed\n";
}

/**
 * Registra um anexo de imagem utilizável nos payloads.
 */
function papelito_test_attachment( int $attachment_id ): int {
	$GLOBALS['pap_attachments'][ $attachment_id ] = true;
	return $attachment_id;
}

/**
 * Payload administrativo de brinde.
 */
function papelito_test_merchandise_payload( array $overrides = array() ): array {
	return array_merge(
		array(
			'name'              => 'Piteira Especial',
			'imageAttachmentId' => papelito_test_attachment( 71 ),
			'weight'            => '0.05',
			'length'            => '14',
			'width'             => '2',
			'height'            => '2',
		),
		$overrides
	);
}

/**
 * Payload administrativo de Kit.
 */
function papelito_test_kit_payload( array $overrides = array() ): array {
	return array_merge(
		array(
			'name'              => 'Kit Premium',
			'price'             => '90.00',
			'status'            => 'publish',
			'imageSource'       => 'custom',
			'imageAttachmentId' => papelito_test_attachment( 70 ),
			'shortDescription'  => '',
			'description'       => '',
			'packageDimensions' => array(
				'length' => '30',
				'width'  => '20',
				'height' => '10',
			),
			'items'             => array( array( 'productId' => 101, 'quantity' => 2 ) ),
			'merchandise'       => array(),
		),
		$overrides
	);
}
