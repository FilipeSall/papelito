<?php
/**
 * Superfície da importação de produtos do admin: finalidade de upload e rota de confirmação.
 *
 * A prévia chega pelo upload direto (`/uploads/direct`, finalidade `admin-product-import`),
 * com o admin fixado no tíquete; a confirmação é JSON em `POST /admin/products/import`. As
 * duas exigem `manage_options` relendo o usuário, e o corpo da confirmação nunca carrega as
 * linhas: só o token da prévia e as decisões.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Limite por admin para prévia e confirmação: as duas leem o catálogo inteiro.
 *
 * @param int $user_id Admin autenticado.
 * @return true|WP_Error
 */
function papelito_product_import_rate_limit( int $user_id ): bool|WP_Error {
	if ( ! papelito_rate_limit( 'admin_product_import', 'user:' . $user_id, 10, 60 ) ) {
		return new WP_Error( 'papelito_product_import_rate_limited', 'Muitas importações seguidas. Aguarde um minuto e tente novamente.', array( 'status' => 429 ) );
	}
	return true;
}

/**
 * Emite o tíquete de upload da planilha para o administrador autenticado.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_product_import_ticket(): WP_REST_Response|WP_Error {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
		return papelito_direct_upload_error( 'papelito_upload_not_allowed', 'Você não tem permissão para importar produtos.', 403 );
	}
	return new WP_REST_Response( papelito_direct_upload_ticket_create( 'admin-product-import', array( 'user_id' => $user_id ) ), 201 );
}

/**
 * Recebe a planilha pelo upload direto e devolve a prévia; o arquivo não é guardado.
 *
 * @param array<string,mixed> $ticket Tíquete consumido, com o admin no contexto.
 * @param array<string,mixed> $file   Arquivo enviado.
 * @return array<string,mixed>|WP_Error
 */
function papelito_product_import_receive( array $ticket, array $file ): array|WP_Error {
	$user_id = absint( $ticket['context']['user_id'] ?? 0 );
	if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) {
		return papelito_direct_upload_error( 'papelito_upload_not_allowed', 'Você não tem permissão para importar produtos.', 403 );
	}
	$limit = papelito_product_import_rate_limit( $user_id );
	return is_wp_error( $limit ) ? $limit : papelito_product_import_preview_file( $user_id, $file );
}

/**
 * Corpo da confirmação validado: token, linhas duplicadas a criar e o aceite de reimportação.
 *
 * @param mixed $body JSON da requisição.
 * @return array{token:string,createLines:int[],acknowledgeRepeat:bool}|WP_Error
 */
function papelito_product_import_validate_payload( mixed $body ): array|WP_Error {
	$token = is_array( $body ) ? sanitize_text_field( (string) ( $body['token'] ?? '' ) ) : '';
	$lines = is_array( $body ) && is_array( $body['createLines'] ?? null ) ? $body['createLines'] : array();
	if ( '' === $token || count( $lines ) > PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS ) {
		return new WP_Error( 'papelito_product_import_invalid', 'Confirmação da importação fora do formato esperado.', array( 'status' => 422 ) );
	}
	return array(
		'token'             => $token,
		'createLines'       => array_values( array_filter( array_map( 'absint', $lines ) ) ),
		'acknowledgeRepeat' => true === ( $body['acknowledgeRepeat'] ?? false ),
	);
}

/**
 * POST da confirmação: gate, validação do corpo e criação do lote.
 *
 * @param WP_REST_Request $request Corpo `{ token, createLines, acknowledgeRepeat }`.
 */
function papelito_product_import_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = get_current_user_id();
	$limit   = papelito_product_import_rate_limit( $user_id );
	if ( is_wp_error( $limit ) ) {
		return $limit;
	}
	$payload = papelito_product_import_validate_payload( $request->get_json_params() );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}
	$result = papelito_product_import_confirm( $user_id, $payload );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
}

/** Registra a rota de confirmação, restrita a administradores. */
function papelito_product_import_register_routes(): void {
	register_rest_route(
		'papelito/v1/admin',
		'/products/import',
		array(
			'methods'             => 'POST',
			'callback'            => 'papelito_product_import_endpoint',
			'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
		)
	);
}

add_action( 'rest_api_init', 'papelito_product_import_register_routes' );
