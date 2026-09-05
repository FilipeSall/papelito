<?php
/**
 * Geração e saneamento de SKU do catálogo Papelito.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PRODUCT_SKU_PREFIX' ) ) {
	define( 'PAPELITO_PRODUCT_SKU_PREFIX', 'PPL-' );
}

if ( ! defined( 'PAPELITO_PRODUCT_SKU_ID_WIDTH' ) ) {
	define( 'PAPELITO_PRODUCT_SKU_ID_WIDTH', 6 );
}

if ( ! defined( 'PAPELITO_PRODUCT_SKU_DEFAULT_BATCH' ) ) {
	define( 'PAPELITO_PRODUCT_SKU_DEFAULT_BATCH', 100 );
}

if ( ! defined( 'PAPELITO_PRODUCT_SKU_MAX_BATCH' ) ) {
	define( 'PAPELITO_PRODUCT_SKU_MAX_BATCH', 500 );
}

function papelito_product_sku_for_id( int $product_id ): string {
	return PAPELITO_PRODUCT_SKU_PREFIX . str_pad( (string) absint( $product_id ), PAPELITO_PRODUCT_SKU_ID_WIDTH, '0', STR_PAD_LEFT );
}

function papelito_product_sku_unique_for_id( int $product_id ): string {
	$base      = papelito_product_sku_for_id( $product_id );
	$candidate = $base;
	$suffix    = 2;

	if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
		return $candidate;
	}

	while ( true ) {
		$existing_id = absint( wc_get_product_id_by_sku( $candidate ) );
		if ( 0 === $existing_id || absint( $product_id ) === $existing_id ) {
			return $candidate;
		}

		$candidate = $base . '-' . $suffix;
		++$suffix;
	}
}

function papelito_product_sku_guard_save( object $product ): void {
	if ( ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_sku' ) || ! method_exists( $product, 'set_sku' ) ) {
		return;
	}

	$product_id = absint( $product->get_id() );
	if ( 0 === $product_id ) {
		return;
	}

	$stored_sku = trim( (string) get_post_meta( $product_id, '_sku', true ) );
	if ( '' === $stored_sku ) {
		$product->set_sku( papelito_product_sku_unique_for_id( $product_id ) );
		return;
	}

	if ( trim( (string) $product->get_sku() ) !== $stored_sku ) {
		$product->set_sku( $stored_sku );
	}
}

function papelito_product_sku_assign_on_create( int $product_id, $product = null ): void {
	$product_id = absint( $product_id );
	if ( 0 === $product_id ) {
		return;
	}

	if ( ! is_object( $product ) && function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $product_id );
	}

	if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) || ! method_exists( $product, 'set_sku' ) || ! method_exists( $product, 'save' ) ) {
		return;
	}

	if ( '' !== trim( (string) $product->get_sku() ) ) {
		return;
	}

	$product->set_sku( papelito_product_sku_unique_for_id( $product_id ) );
	$product->save();
}

function papelito_product_sku_backfill_batch( int $batch ): int {
	if ( $batch <= 0 ) {
		return PAPELITO_PRODUCT_SKU_DEFAULT_BATCH;
	}

	return min( PAPELITO_PRODUCT_SKU_MAX_BATCH, $batch );
}

/**
 * Processa um item do backfill e devolve o resultado sem alterar o acumulador.
 *
 * @return array{status:string,item?:array{id:int,name:string,sku:string,status:string},error?:string}
 */
function papelito_product_sku_backfill_product( int $product_id, bool $dry_run ): array {
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) ) {
		return array(
			'status' => 'failed',
			'error'  => sprintf( 'Produto #%d não pôde ser carregado.', $product_id ),
		);
	}

	$current_sku = trim( (string) $product->get_sku() );
	if ( '' !== $current_sku ) {
		return array( 'status' => 'skipped' );
	}

	$generated_sku = papelito_product_sku_unique_for_id( $product_id );
	$item          = array(
		'id'     => $product_id,
		'name'   => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
		'sku'    => $generated_sku,
		'status' => $dry_run ? 'would_generate' : 'generated',
	);

	if ( $dry_run ) {
		return array(
			'status' => 'missing',
			'item'   => $item,
		);
	}

	if ( ! method_exists( $product, 'set_sku' ) || ! method_exists( $product, 'save' ) ) {
		$item['status'] = 'failed';
		return array(
			'status' => 'failed',
			'item'   => $item,
			'error'  => sprintf( 'Produto #%d não permite gravação.', $product_id ),
		);
	}

	try {
		$product->set_sku( $generated_sku );
		$product->save();
	} catch ( Throwable $error ) {
		$item['status'] = 'failed';
		return array(
			'status' => 'failed',
			'item'   => $item,
			'error'  => sprintf( 'Produto #%d falhou ao salvar: %s', $product_id, $error->getMessage() ),
		);
	}

	if ( trim( (string) $product->get_sku() ) !== $generated_sku ) {
		$item['status'] = 'failed';
		return array(
			'status' => 'failed',
			'item'   => $item,
			'error'  => sprintf( 'Produto #%d não confirmou o SKU.', $product_id ),
		);
	}

	return array(
		'status' => 'generated',
		'item'   => $item,
	);
}

/**
 * Preenche SKUs ausentes em produtos e variações.
 *
 * @param array{dry_run?:bool,batch?:int} $options Opções da execução.
 * @return array{dryRun:bool,scanned:int,missing:int,generated:int,skipped:int,failed:int,items:array<int,array{id:int,name:string,sku:string,status:string}>,errors:array<int,string>}
 */
function papelito_product_sku_backfill( array $options = array() ): array {
	$dry_run = ! empty( $options['dry_run'] );
	$batch   = papelito_product_sku_backfill_batch( (int) ( $options['batch'] ?? PAPELITO_PRODUCT_SKU_DEFAULT_BATCH ) );
	$ids     = get_posts(
		array(
			'post_type'              => array( 'product', 'product_variation' ),
			'post_status'            => 'any',
			'posts_per_page'         => $batch,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$summary = array(
		'dryRun'    => $dry_run,
		'scanned'   => 0,
		'missing'   => 0,
		'generated' => 0,
		'skipped'   => 0,
		'failed'    => 0,
		'items'     => array(),
		'errors'    => array(),
	);

	foreach ( $ids as $product_id ) {
		++$summary['scanned'];
		$result = papelito_product_sku_backfill_product( absint( $product_id ), $dry_run );

		if ( 'skipped' === $result['status'] ) {
			++$summary['skipped'];
			continue;
		}

		if ( 'failed' === $result['status'] ) {
			++$summary['failed'];
			if ( isset( $result['error'] ) ) {
				$summary['errors'][] = $result['error'];
			}
		} else {
			++$summary['missing'];
			if ( 'generated' === $result['status'] ) {
				++$summary['generated'];
			}
		}

		if ( isset( $result['item'] ) ) {
			$summary['items'][] = $result['item'];
		}
	}

	return $summary;
}

function papelito_product_sku_backfill_rest( WP_REST_Request $request ): WP_REST_Response {
	$body    = $request->get_json_params();
	$body    = is_array( $body ) ? $body : array();
	$dry_run = function_exists( 'rest_sanitize_boolean' ) ? rest_sanitize_boolean( $body['dryRun'] ?? false ) : ! empty( $body['dryRun'] );
	$summary = papelito_product_sku_backfill(
		array(
			'dry_run' => $dry_run,
			'batch'   => absint( $body['batch'] ?? PAPELITO_PRODUCT_SKU_DEFAULT_BATCH ),
		)
	);

	return new WP_REST_Response( $summary, 200 );
}

add_action( 'woocommerce_before_product_object_save', 'papelito_product_sku_guard_save', 10, 1 );
add_action( 'woocommerce_new_product', 'papelito_product_sku_assign_on_create', 20, 2 );
add_action( 'woocommerce_new_product_variation', 'papelito_product_sku_assign_on_create', 20, 2 );
add_action( 'woocommerce_create_product_variation', 'papelito_product_sku_assign_on_create', 20, 2 );

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/admin/products/sku-backfill',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => 'papelito_product_sku_backfill_rest',
			)
		);
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Comando WP-CLI para o backfill de SKUs.
	 */
	class Papelito_Product_SKU_CLI {
		/**
		 * Gera SKUs ausentes. Sem --apply, apenas simula.
		 *
		 * @param array<int,string>    $args       Argumentos posicionais.
		 * @param array<string,string> $assoc_args Opções --dry-run, --apply e --batch.
		 */
		public function sku_backfill( array $args, array $assoc_args ): void {
			if ( ! empty( $assoc_args['dry-run'] ) && ! empty( $assoc_args['apply'] ) ) {
				WP_CLI::error( 'Use --dry-run ou --apply, nunca os dois.' );
			}

			$dry_run = empty( $assoc_args['apply'] );
			$summary = papelito_product_sku_backfill(
				array(
					'dry_run' => $dry_run,
					'batch'   => absint( $assoc_args['batch'] ?? PAPELITO_PRODUCT_SKU_DEFAULT_BATCH ),
				)
			);

			foreach ( $summary['items'] as $item ) {
				WP_CLI::log( sprintf( '#%d %s => %s [%s]', $item['id'], $item['name'], $item['sku'], $item['status'] ) );
			}

			WP_CLI::success(
				sprintf(
					'dry_run=%s scanned=%d missing=%d generated=%d skipped=%d failed=%d',
					$dry_run ? 'true' : 'false',
					$summary['scanned'],
					$summary['missing'],
					$summary['generated'],
					$summary['skipped'],
					$summary['failed']
				)
			);

			if ( ! empty( $summary['errors'] ) ) {
				WP_CLI::warning( implode( '; ', $summary['errors'] ) );
			}
		}
	}

	WP_CLI::add_command( 'papelito products', 'Papelito_Product_SKU_CLI' );
}
