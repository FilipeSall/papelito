<?php
/**
 * Política e contrato REST dos atributos do vendor por item.
 *
 * O item é a linha do estoque: produto simples, produto comercial do kit ou
 * variação, sem subir para o pai. Identidade vem só do JWT; o código fica
 * privado ao vendor e nunca altera o SKU da Papelito.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valida que o item existe, está publicado e é produto ou variação.
 *
 * @param int $product_id Produto ou variação da linha de estoque.
 * @return WC_Product|WP_Error
 */
function papelito_vendor_item_settings_item( int $product_id ): WC_Product|WP_Error {
	$product = $product_id > 0 ? wc_get_product( $product_id ) : false;
	if (
		! $product
		|| 'publish' !== $product->get_status( 'edit' )
		|| ! in_array( get_post_type( $product->get_id() ), array( 'product', 'product_variation' ), true )
	) {
		return new WP_Error( 'papelito_item_settings_product_not_found', 'Produto publicado não encontrado.', array( 'status' => 404 ) );
	}
	return $product;
}

/**
 * Erro 422 único para corpo fora do contrato.
 *
 * @param string $message Mensagem exibível ao vendor.
 */
function papelito_vendor_item_settings_invalid( string $message ): WP_Error {
	return new WP_Error( 'papelito_item_settings_invalid', $message, array( 'status' => 422 ) );
}

/**
 * Normaliza o código: texto puro, bordas aparadas, vazio vira null.
 *
 * Nunca converte para número; zeros à esquerda fazem parte do código no ERP.
 *
 * @param mixed $raw Valor recebido no corpo.
 * @return string|WP_Error|null
 */
function papelito_vendor_item_settings_normalize_code( mixed $raw ): string|WP_Error|null {
	if ( null === $raw ) {
		return null;
	}
	if ( ! is_string( $raw ) || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
		return papelito_vendor_item_settings_invalid( 'Envie o código como texto.' );
	}
	$code = sanitize_text_field( $raw );
	if ( '' === $code ) {
		return null;
	}
	if ( mb_strlen( $code, 'UTF-8' ) > PAPELITO_VENDOR_CODE_MAX_LENGTH ) {
		return papelito_vendor_item_settings_invalid( 'O código deve ter no máximo 60 caracteres.' );
	}
	return $code;
}

/**
 * Aceita somente `vendor_code`; identidade ou campo desconhecido no corpo é recusado.
 *
 * @param mixed $payload Corpo JSON já decodificado.
 * @return array{vendor_code:?string}|WP_Error
 */
function papelito_vendor_item_settings_validate( mixed $payload ): array|WP_Error {
	if ( ! is_array( $payload ) || ! array_key_exists( 'vendor_code', $payload ) || array_diff( array_keys( $payload ), array( 'vendor_code' ) ) ) {
		return papelito_vendor_item_settings_invalid( 'Envie somente o código do produto.' );
	}
	$code = papelito_vendor_item_settings_normalize_code( $payload['vendor_code'] );
	return is_wp_error( $code ) ? $code : array( 'vendor_code' => $code );
}

/**
 * Visão privada do item para o vendor autenticado.
 *
 * @param int        $vendor_id Vendor autenticado.
 * @param WC_Product $product   Item já validado.
 * @return array{product_id:int,vendor_code:?string,updated_at:?string}|WP_Error
 */
function papelito_vendor_item_settings_view( int $vendor_id, WC_Product $product ): array|WP_Error {
	$rows = papelito_vendor_item_settings_get_many( $vendor_id, array( $product->get_id() ) );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$row = $rows[ $product->get_id() ] ?? null;
	return array(
		'product_id'  => $product->get_id(),
		'vendor_code' => $row['vendor_code'] ?? null,
		'updated_at'  => $row['updated_at'] ?? null,
	);
}

/**
 * Lê os atributos do item no escopo do vendor autenticado.
 *
 * @param int $product_id Produto ou variação.
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_item_settings_get( int $product_id ): array|WP_Error {
	$user = papelito_vendor_stock_require_seller();
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	$product = papelito_vendor_item_settings_item( $product_id );
	return is_wp_error( $product ) ? $product : papelito_vendor_item_settings_view( (int) $user->ID, $product );
}

/**
 * Limite das gravações por usuário, folgado o bastante para preencher códigos em sequência.
 *
 * @param int $vendor_id Vendor autenticado.
 * @return true|WP_Error
 */
function papelito_vendor_item_settings_rate_limit( int $vendor_id ): bool|WP_Error {
	if ( ! papelito_rate_limit( 'vendor_item_settings', 'user:' . $vendor_id, 60, 60 ) ) {
		return new WP_Error( 'papelito_item_settings_rate_limited', 'Aguarde alguns instantes antes de alterar outro código.', array( 'status' => 429 ) );
	}
	return true;
}

/**
 * Grava os atributos enviados e devolve a visão confirmada pelo banco.
 *
 * @param int   $product_id Produto ou variação.
 * @param mixed $payload    Corpo JSON já decodificado.
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_item_settings_save( int $product_id, mixed $payload ): array|WP_Error {
	$user = papelito_vendor_stock_require_seller_commercial();
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	$product = papelito_vendor_item_settings_item( $product_id );
	if ( is_wp_error( $product ) ) {
		return $product;
	}
	$limit = papelito_vendor_item_settings_rate_limit( (int) $user->ID );
	if ( is_wp_error( $limit ) ) {
		return $limit;
	}
	$input = papelito_vendor_item_settings_validate( $payload );
	if ( is_wp_error( $input ) ) {
		return $input;
	}
	$saved = papelito_vendor_item_settings_save_code( (int) $user->ID, $product->get_id(), $input['vendor_code'] );
	return is_wp_error( $saved ) ? $saved : papelito_vendor_item_settings_view( (int) $user->ID, $product );
}

/**
 * Resposta privada sem cache, ou o erro original para serialização REST.
 *
 * @param array|WP_Error $result Visão do item ou erro do serviço.
 */
function papelito_vendor_item_settings_response( array|WP_Error $result ): WP_REST_Response|WP_Error {
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$response = new WP_REST_Response( $result, 200 );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

/** Permission callback de leitura; o serviço repete a autorização. */
function papelito_vendor_item_settings_read_permission(): bool|WP_Error {
	$user = papelito_vendor_stock_require_seller();
	return is_wp_error( $user ) ? $user : true;
}

/** Permission callback de escrita, com bloqueio de conta suspensa. */
function papelito_vendor_item_settings_write_permission(): bool|WP_Error {
	$user = papelito_vendor_stock_require_seller_commercial();
	return is_wp_error( $user ) ? $user : true;
}

/**
 * GET do item no escopo do vendor autenticado.
 *
 * @param WP_REST_Request $request Requisição com `product_id` na rota.
 */
function papelito_vendor_item_settings_get_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_vendor_item_settings_response( papelito_vendor_item_settings_get( (int) $request->get_url_params()['product_id'] ) );
}

/**
 * PATCH parcial; o corpo nunca carrega identidade.
 *
 * @param WP_REST_Request $request Requisição com `product_id` na rota.
 */
function papelito_vendor_item_settings_patch_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_vendor_item_settings_response(
		papelito_vendor_item_settings_save( (int) $request->get_url_params()['product_id'], $request->get_json_params() )
	);
}

/** Registra leitura e escrita com ID positivo e permissões explícitas. */
function papelito_vendor_item_settings_register_routes(): void {
	$product_args = array(
		'product_id' => array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		),
	);
	register_rest_route(
		'papelito/v1',
		'/vendor/me/products/(?P<product_id>\d+)/settings',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'papelito_vendor_item_settings_get_endpoint',
				'permission_callback' => 'papelito_vendor_item_settings_read_permission',
				'args'                => $product_args,
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => 'papelito_vendor_item_settings_patch_endpoint',
				'permission_callback' => 'papelito_vendor_item_settings_write_permission',
				'args'                => $product_args,
			),
		)
	);
}

add_action( 'rest_api_init', 'papelito_vendor_item_settings_register_routes' );
