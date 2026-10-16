<?php
/**
 * Superfície da importação de códigos do vendor: finalidade de upload e rota de confirmação.
 *
 * A prévia chega pelo upload direto (`/uploads/direct`, finalidade
 * `vendor-sku-import`), com o vendor fixado no tíquete; a confirmação é JSON em
 * `POST /vendor/me/products/vendor-codes/import`. As duas repetem a checagem de
 * seller e de conta suspensa; o corpo nunca carrega identidade.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Limite por vendor para prévia e confirmação: a associação percorre o catálogo inteiro.
 *
 * @param int $vendor_id Vendor autenticado.
 * @return true|WP_Error
 */
function papelito_vendor_code_import_rate_limit( int $vendor_id ): bool|WP_Error {
	if ( ! papelito_rate_limit( 'vendor_code_import', 'user:' . $vendor_id, 10, 60 ) ) {
		return new WP_Error( 'papelito_vendor_code_import_rate_limited', 'Muitas importações seguidas. Aguarde um minuto e tente novamente.', array( 'status' => 429 ) );
	}
	return true;
}

/**
 * Emite o tíquete de upload da planilha para o seller autenticado, sem conta suspensa.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_vendor_code_import_ticket(): WP_REST_Response|WP_Error {
	$user = papelito_vendor_stock_require_seller_commercial();
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	return new WP_REST_Response( papelito_direct_upload_ticket_create( 'vendor-sku-import', array( 'vendor_id' => (int) $user->ID ) ), 201 );
}

/**
 * Recebe a planilha pelo upload direto e devolve a prévia; o arquivo não é guardado.
 *
 * @param array<string,mixed> $ticket Tíquete consumido, com o vendor no contexto.
 * @param array<string,mixed> $file   Arquivo enviado.
 * @return array|WP_Error
 */
function papelito_vendor_code_import_receive( array $ticket, array $file ): array|WP_Error {
	$vendor_id = absint( $ticket['context']['vendor_id'] ?? 0 );
	$vendor    = $vendor_id > 0 ? get_user_by( 'id', $vendor_id ) : false;
	if ( ! $vendor || ! papelito_vendor_stock_is_operational_vendor( $vendor ) ) {
		return papelito_direct_upload_error( 'papelito_upload_not_allowed', 'Você não tem permissão para importar códigos.', 403 );
	}
	$limit = papelito_vendor_code_import_rate_limit( $vendor_id );
	return is_wp_error( $limit ) ? $limit : papelito_vendor_code_import_preview_file( $vendor_id, $file );
}

/**
 * POST da confirmação: gate, validação do corpo e gravação.
 *
 * @param WP_REST_Request $request Corpo `{ rows: [...] }`.
 */
function papelito_vendor_code_import_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user = papelito_vendor_stock_require_seller_commercial();
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	$limit = papelito_vendor_code_import_rate_limit( (int) $user->ID );
	if ( is_wp_error( $limit ) ) {
		return $limit;
	}
	$rows = papelito_vendor_code_import_validate_payload( $request->get_json_params() );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	return papelito_vendor_item_settings_response( papelito_vendor_code_import_confirm( (int) $user->ID, $rows ) );
}

/** Registra a rota de confirmação com a mesma permissão de escrita da edição manual. */
function papelito_vendor_code_import_register_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/vendor/me/products/vendor-codes/import',
		array(
			'methods'             => 'POST',
			'callback'            => 'papelito_vendor_code_import_endpoint',
			'permission_callback' => 'papelito_vendor_item_settings_write_permission',
		)
	);
}

add_action( 'rest_api_init', 'papelito_vendor_code_import_register_routes' );
