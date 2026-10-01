<?php
/**
 * Contratos REST de gestão e apresentação de descrições.
 *
 * Identidade de escrita vem somente do JWT. A apresentação pública aceita
 * vendor como seletor de leitura, sem devolver conteúdo privado de gestão.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/** Permission callback de leitura; o serviço também repete a autorização. */
function papelito_vendor_product_customization_read_permission(): bool|WP_Error {
	$user = papelito_vendor_product_customization_require_seller();
	return is_wp_error( $user ) ? $user : true;
}

/** Permission callback de mutação com bloqueio comercial. */
function papelito_vendor_product_customization_write_permission(): bool|WP_Error {
	$user = papelito_vendor_product_customization_require_seller( true );
	return is_wp_error( $user ) ? $user : true;
}

/** Devolve resposta sem cache, ou o erro original para serialização REST. */
function papelito_vendor_product_customization_response( array|WP_Error $result ): WP_REST_Response|WP_Error {
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$response = new WP_REST_Response( $result, 200 );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

/** Leitura de gestão do produto no escopo do usuário corrente. */
function papelito_vendor_product_customization_get_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_vendor_product_customization_response( papelito_vendor_product_customization_get( (int) $request->get_url_params()['product_id'] ) );
}

/** Escrita sanitizada da descrição, sem aceitar identidade no corpo. */
function papelito_vendor_product_customization_put_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	return papelito_vendor_product_customization_response(
		papelito_vendor_product_customization_save( (int) $request->get_url_params()['product_id'], $request->get_json_params() )
	);
}

/** Restauração explícita, com representação canônica atual na resposta. */
function papelito_vendor_product_customization_delete_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$body = $request->get_json_params();
	if ( ! empty( $body ) ) {
		return new WP_Error( 'papelito_customization_invalid_body', 'A restauração não aceita campos no corpo.', array( 'status' => 422 ) );
	}
	return papelito_vendor_product_customization_response( papelito_vendor_product_customization_restore( (int) $request->get_url_params()['product_id'] ) );
}

/** Leitura pública; vendor inválido não vira ausência de override silenciosa. */
function papelito_product_presentation_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$vendor_id = $request->get_query_params()['vendor_id'] ?? null;
	return papelito_vendor_product_customization_response(
		papelito_product_presentation_resolve( (int) $request->get_url_params()['product_id'], null === $vendor_id ? null : (int) $vendor_id )
	);
}

/** Registra as quatro operações com IDs positivos e permissões explícitas. */
function papelito_vendor_product_customization_register_routes(): void {
	$product_args = array(
		'product_id' => array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		),
	);
	register_rest_route(
		'papelito/v1',
		'/vendor/me/products/(?P<product_id>\d+)/customization',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'papelito_vendor_product_customization_get_endpoint',
				'permission_callback' => 'papelito_vendor_product_customization_read_permission',
				'args'                => $product_args,
			),
			array(
				'methods'             => 'PUT',
				'callback'            => 'papelito_vendor_product_customization_put_endpoint',
				'permission_callback' => 'papelito_vendor_product_customization_write_permission',
				'args'                => $product_args,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => 'papelito_vendor_product_customization_delete_endpoint',
				'permission_callback' => 'papelito_vendor_product_customization_write_permission',
				'args'                => $product_args,
			),
		)
	);
	register_rest_route(
		'papelito/v1',
		'/products/(?P<product_id>\d+)/presentation',
		array(
			'methods'             => 'GET',
			'callback'            => 'papelito_product_presentation_endpoint',
			'permission_callback' => '__return_true',
			'args'                => array_merge(
				$product_args,
				array(
					'vendor_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				)
			),
		)
	);
}

add_action( 'rest_api_init', 'papelito_vendor_product_customization_register_routes' );
