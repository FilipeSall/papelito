<?php
/**
 * Standalone regression test for the vendor stock bulk write, the catalog summary and the
 * product data request.
 *
 * As invariantes cobertas aqui:
 *
 * - o lote aplica a mesma quantidade a todos os produtos em uma chamada, e uma falha no meio
 *   nao interrompe os demais nem e reportada como sucesso;
 * - o resumo do catalogo sai em UMA consulta, e `coverage_percent` e presenca no catalogo
 *   (disponiveis / elegiveis), nunca participacao no volume fisico;
 * - a solicitacao de dados recalcula no servidor o que falta, recusa produto completo e nao
 *   duplica pedido pendente.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PAPELITO_NOTIF_VENDOR_PRODUCT_DATA_REQUEST', 'vendor_product_data_request' );

function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function absint( $value ) { return abs( (int) $value ); }
function sanitize_title( $value ) { return strtolower( trim( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function current_time( $type, $gmt = false ) { return '2026-09-03 12:00:00'; }
function admin_url( $path = '' ) { return 'https://papelito.local/wp-admin/' . $path; }
function wp_get_attachment_image_url( $attachment_id, $size ) { return 'https://cdn.local/seda.webp'; }
function wp_get_object_terms( $ids, $taxonomy, $args ) { return array(); }
function update_meta_cache( $type, $ids ) { return true; }
function papelito_curated_collections() { return array( 'premium' ); }
function papelito_product_taxonomy_table_names() {
	return array(
		'product_category'   => 'wp_papelito_product_category',
		'product_collection' => 'wp_papelito_product_collection',
	);
}
function papelito_taxonomy_exists_clause( $product_expr, $category_id, array $subcategory_ids, $unresolved = false ) { return null; }
function papelito_products_category_map( array $ids ) { return array(); }
function papelito_products_subcategory_map( array $ids ) { return array(); }
function papelito_kits_table_names() {
	return array(
		'kits'  => 'wp_papelito_kits',
		'items' => 'wp_papelito_kit_items',
	);
}

$GLOBALS['papelito_test_actions_fired'] = array();
function do_action( $hook, ...$args ) {
	$GLOBALS['papelito_test_actions_fired'][] = $hook;
}

/** Produto de teste: o que falta e decidido pelas flags, nao pelo corpo da requisicao. */
class Papelito_Test_Product {
	public function __construct(
		private int $id,
		private string $name = 'Seda Papelito Tropical Mini',
		private string $sku = 'PPL-001',
		private string $weight = '0.02',
		private string $price = '9.90',
		private string $side = '10'
	) {}

	public function get_id() { return $this->id; }
	public function get_parent_id() { return 0; }
	public function get_name() { return $this->name; }
	public function get_sku() { return $this->sku; }
	public function get_weight( $context = 'view' ) { return $this->weight; }
	public function get_price( $context = 'view' ) { return $this->price; }
	public function get_length() { return $this->side; }
	public function get_width() { return $this->side; }
	public function get_height() { return $this->side; }
	public function get_status() { return 'publish'; }
	public function is_type( $type ) { return 'simple' === $type; }
	public function get_children() { return array(); }
}

$GLOBALS['papelito_test_products'] = array(
	// 501: cadastro completo. 502: sem peso e sem preco.
	501 => new Papelito_Test_Product( 501 ),
	502 => new Papelito_Test_Product( 502, 'Dichavador Neon', 'PPL-002', '', '0', '' ),
);

function wc_get_product( $product_id ) {
	return $GLOBALS['papelito_test_products'][ (int) $product_id ] ?? null;
}
function wc_format_decimal( $value ) { return (string) $value; }
function papelito_product_has_valid_weight( $product ) { return '' !== (string) $product->get_weight(); }
function papelito_product_has_valid_price( $product ) { return (float) $product->get_price() > 0; }
function papelito_product_get_category( $product_id ) { return array( 'id' => 7 ); }
function papelito_kit_get_by_product( $product_id ) { return null; }

function get_user_by( $field, $value ) {
	$user               = new stdClass();
	$user->display_name = 'CIFAL Distribuidora';
	return $user;
}
function get_user_meta( $user_id, $key, $single = false ) { return 'CIFAL'; }
function get_users( $args ) { return array( 11, 12 ); }

class WP_User {}

$GLOBALS['papelito_test_notifications'] = array();
function papelito_dispatch_notification( $user_id, $type, $payload = array(), $dedupe_key = null ) {
	$signature = $user_id . '|' . $type . '|' . $dedupe_key;

	if ( isset( $GLOBALS['papelito_test_notifications'][ $signature ] ) ) {
		return false;
	}

	$GLOBALS['papelito_test_notifications'][ $signature ] = $payload;

	return count( $GLOBALS['papelito_test_notifications'] );
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_REST_Request {}
class WP_REST_Response {}

class Papelito_Bulk_Test_WPDB {
	public string $prefix   = 'wp_';
	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';

	public array $queries      = array();
	public array $inserts      = array();
	public array $prepared     = array();
	public int $row_queries    = 0;
	public array $summary_rows = array();
	/** Produtos cuja escrita deve falhar, para o lote nao mascarar erro. */
	public array $fail_products = array();
	/** Produto => anexo de thumbnail. Sem entrada aqui, o produto conta como sem imagem. */
	public array $thumbnails = array( 501 => 4001 );

	public function esc_like( $text ) { return $text; }

	public function prepare( $query, ...$args ) {
		$flat             = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$this->prepared[] = array( 'query' => $query, 'params' => $flat );

		foreach ( $flat as $param ) {
			$query = preg_replace( '/%[dsf]/', is_string( $param ) ? "'" . $param . "'" : (string) $param, $query, 1 );
		}

		return $query;
	}

	public function query( $query ) {
		$this->queries[] = $query;

		foreach ( $this->fail_products as $product_id ) {
			if ( str_contains( $query, 'INSERT INTO wp_papelito_vendor_stock' ) && str_contains( $query, ', ' . $product_id . ', ' ) ) {
				return false;
			}
		}

		return 1;
	}

	public function get_row( $query, $output = null ) {
		++$this->row_queries;

		if ( str_contains( $query, 'situacao' ) ) {
			return $this->summary_rows;
		}

		return null;
	}

	public function get_var( $query ) { return 0; }
	public function get_results( $query, $output = null ) {
		if ( str_contains( $query, '_thumbnail_id' ) ) {
			$rows = array();

			foreach ( $this->thumbnails as $product_id => $thumbnail_id ) {
				$rows[] = array( 'post_id' => $product_id, 'meta_value' => $thumbnail_id );
			}

			return $rows;
		}

		return array();
	}

	public function insert( $table, $data, $format = null ) {
		$this->inserts[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}

	public function get_charset_collate() { return ''; }
}

$wpdb = new Papelito_Bulk_Test_WPDB();

require_once __DIR__ . '/../includes/vendor_stock.php';

$failures = 0;
$checks   = 0;

function papelito_assert( bool $condition, string $message ): void {
	global $failures, $checks;

	++$checks;

	if ( ! $condition ) {
		++$failures;
		echo "FALHOU: {$message}\n";
	}
}

/* ---------------------------------------------------------------- filtros */

papelito_assert(
	array( 'all', 'with_stock', 'low_stock', 'zeroed_only', 'unconfigured', 'incomplete' ) === papelito_vendor_stock_filters(),
	'os recortes de estoque incluem estoque baixo, nao configurado e dados incompletos'
);

papelito_assert( papelito_vendor_stock_low_threshold() >= 1, 'o limite de estoque baixo e positivo' );

/* ------------------------------------------------------------------ lote */

$wpdb->queries = array();
$wpdb->inserts = array();

$result = papelito_vendor_stock_set_many( 77, array( 501, 502, 501 ), 15 );

papelito_assert( 2 === $result['updated'], 'o lote deduplica a selecao e aplica uma vez por produto' );
papelito_assert( array() === $result['failed'], 'o lote sem erro nao reporta falha' );

$upserts = array_filter(
	$wpdb->queries,
	static fn( string $query ): bool => str_contains( $query, 'INSERT INTO wp_papelito_vendor_stock' )
);

papelito_assert( 2 === count( $upserts ), 'cada produto do lote recebe um upsert' );

foreach ( $upserts as $upsert ) {
	papelito_assert( str_contains( $upsert, ', 15,' ), 'a quantidade do lote vai igual para todos os produtos' );
}

$logged = array_filter(
	$wpdb->inserts,
	static fn( array $insert ): bool => 'wp_papelito_vendor_stock_log' === $insert['table']
);

papelito_assert( 2 === count( $logged ), 'o lote grava log de ajuste por produto' );

foreach ( $logged as $entry ) {
	papelito_assert( 'vendor_bulk_update' === $entry['data']['reason'], 'o log do lote se identifica como lote' );
}

$wpdb->fail_products = array( 502 );
$wpdb->queries       = array();

$partial = papelito_vendor_stock_set_many( 77, array( 501, 502 ), 3 );

papelito_assert( 1 === $partial['updated'], 'falha em um produto nao impede os outros do lote' );
papelito_assert( 1 === count( $partial['failed'] ), 'o produto que falhou volta na resposta' );
papelito_assert( 502 === $partial['failed'][0]['product_id'], 'a falha identifica o produto' );

$wpdb->fail_products = array();

/* ---------------------------------------------------------------- resumo */

$wpdb->summary_rows = array(
	'eligible'     => 500,
	'available'    => 350,
	'low_stock'    => 18,
	'out_of_stock' => 7,
	'unconfigured' => 143,
	'incomplete'   => 11,
);
$wpdb->row_queries  = 0;
$wpdb->prepared     = array();

$summary = papelito_vendor_stock_summary( 77, 'products' );

papelito_assert( 1 === $wpdb->row_queries, 'o resumo do catalogo sai em uma consulta' );
papelito_assert( 70.0 === $summary['coverage_percent'], 'cobertura e disponiveis sobre elegiveis (350/500 = 70%)' );
papelito_assert( 500 === $summary['eligible'] && 350 === $summary['available'], 'o resumo devolve numerador e denominador da cobertura' );
papelito_assert( 143 === $summary['unconfigured'], 'nunca configurado e contado separado de sem estoque' );
papelito_assert( 7 === $summary['out_of_stock'], 'sem estoque conta apenas quem ja teve saldo lancado' );
papelito_assert(
	papelito_vendor_stock_low_threshold() === $summary['low_stock_threshold'],
	'o resumo carrega o limite de estoque baixo para o front nao ter uma segunda copia'
);

$summary_sql = $wpdb->prepared[ count( $wpdb->prepared ) - 1 ]['query'];

papelito_assert(
	str_contains( $summary_sql, 'NOT EXISTS ( SELECT 1 FROM wp_papelito_kits' ),
	'o resumo do segmento de produtos exclui a fachada de kit'
);
papelito_assert(
	str_contains( $summary_sql, "thumb_meta.meta_key = '_thumbnail_id'" )
	&& str_contains( $summary_sql, "weight_meta.meta_key = '_weight'" )
	&& str_contains( $summary_sql, 'wp_papelito_product_category' ),
	'a contagem de dados incompletos olha imagem, peso, dimensao, preco e categoria'
);

$wpdb->summary_rows = array( 'eligible' => 0, 'available' => 0, 'low_stock' => 0, 'out_of_stock' => 0, 'unconfigured' => 0, 'incomplete' => 0 );
$zero_summary       = papelito_vendor_stock_summary( 77, 'products' );

papelito_assert( 0.0 === $zero_summary['coverage_percent'], 'catalogo vazio nao divide por zero' );

$kit_sql_before = count( $wpdb->prepared );
papelito_vendor_stock_summary( 77, 'kits' );
$kit_sql = $wpdb->prepared[ $kit_sql_before ]['query'];

papelito_assert(
	! str_contains( $kit_sql, '_thumbnail_id' ),
	'no segmento de kits o resumo nao julga o cadastro pelo postmeta do produto comercial'
);
papelito_assert(
	str_contains( $kit_sql, '1 AS configured' ),
	'no segmento de kits a configuracao e derivada da disponibilidade montavel'
);

$zeroed_kit_sql_before = count( $wpdb->prepared );
papelito_vendor_stock_query(
	77,
	array(
		'type'      => 'kits',
		'filter'    => 'zeroed_only',
		'paginate'  => false,
	)
);
$zeroed_kit_sql = implode( '\n', array_map( static fn( array $entry ): string => $entry['query'], array_slice( $wpdb->prepared, $zeroed_kit_sql_before ) ) );

papelito_assert(
	! str_contains( $zeroed_kit_sql, 'vs.qty IS NOT NULL' ),
	'zeroed_only de kits usa a disponibilidade montavel, nao a linha de estoque do kit'
);

/* ------------------------------------------------- auditoria de cadastro */

papelito_assert(
	'' !== papelito_vendor_stock_product_image_url( 501 ),
	'o produto com thumbnail resolve imagem'
);
papelito_assert(
	'' === papelito_vendor_stock_product_image_url( 502 ),
	'o produto sem thumbnail nao inventa imagem'
);

$complete = papelito_vendor_stock_product_audit( 501, true );

papelito_assert( array() === $complete['missing'], 'produto completo nao acusa campo faltando' );
papelito_assert( true === $complete['publicly_viewable'], 'produto com peso e publicavel' );

$incomplete = papelito_vendor_stock_product_audit( 502, false );

papelito_assert(
	array( 'image', 'price', 'weight', 'dimensions' ) === $incomplete['missing'],
	'o audite lista o que falta na ordem em que o painel mostra (veio: ' . implode( ', ', $incomplete['missing'] ) . ')'
);
papelito_assert( false === $incomplete['publicly_viewable'], 'produto sem peso nao e publicavel' );

/* --------------------------------------------- solicitacao de dados */

$GLOBALS['papelito_test_notifications'] = array();

$request = papelito_vendor_stock_request_product_data( 77, 502, 'Tenho 40 unidades paradas.' );

papelito_assert( ! is_wp_error( $request ), 'a solicitacao de dados e aceita para produto incompleto' );
papelito_assert( 2 === $request['created'], 'a solicitacao chega a todos os administradores' );
papelito_assert( false === $request['already_pending'], 'a primeira solicitacao nao e tratada como duplicada' );
papelito_assert(
	array( 'image', 'price', 'weight', 'dimensions' ) === $request['missing_fields'],
	'os campos faltantes sao recalculados no servidor, nao lidos do corpo'
);

$payload = $GLOBALS['papelito_test_notifications']['11|vendor_product_data_request|vendor-data-request:77:502'] ?? null;

papelito_assert( is_array( $payload ), 'a notificacao usa chave de deduplicacao por vendor e produto' );
papelito_assert( 'Tenho 40 unidades paradas.' === ( $payload['message'] ?? '' ), 'o recado do vendor viaja no payload' );
papelito_assert( 77 === ( $payload['vendor_id'] ?? 0 ), 'a notificacao identifica o vendor que pediu' );
papelito_assert( 'CIFAL' === ( $payload['vendor_store'] ?? '' ), 'a notificacao identifica a loja do vendor' );
papelito_assert( ! empty( $payload['admin_url'] ), 'a notificacao leva o admin ao produto' );

$again = papelito_vendor_stock_request_product_data( 77, 502, 'De novo.' );

papelito_assert( ! is_wp_error( $again ), 'repetir a solicitacao nao vira erro' );
papelito_assert( 0 === $again['created'], 'solicitacao pendente identica nao gera uma segunda notificacao' );
papelito_assert( true === $again['already_pending'], 'a resposta diz que ja havia solicitacao pendente' );

$refused = papelito_vendor_stock_request_product_data( 77, 501, '' );

papelito_assert( is_wp_error( $refused ), 'produto com cadastro completo nao aceita solicitacao' );
papelito_assert( 'papelito_product_data_complete' === $refused->get_error_code(), 'a recusa diz que o cadastro esta completo' );

$missing_product = papelito_vendor_stock_request_product_data( 77, 999, '' );

papelito_assert( is_wp_error( $missing_product ), 'produto inexistente nao aceita solicitacao' );

echo "{$checks} verificacoes, {$failures} falhas\n";

exit( $failures > 0 ? 1 : 0 );
