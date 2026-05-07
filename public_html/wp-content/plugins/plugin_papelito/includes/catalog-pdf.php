<?php
/**
 * Endpoints REST para gerenciar o PDF do catálogo de produtos.
 *
 * GET  /papelito/v1/catalog-pdf   — Retorna o PDF armazenado ou 404.
 * POST /papelito/v1/catalog-pdf   — Faz upload de um novo PDF (admin only).
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/catalog-pdf',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$attachment_id = (int) get_option( 'papelito_catalog_pdf_id', 0 );

					if ( $attachment_id <= 0 ) {
						return new WP_REST_Response(
							array( 'code' => 'papelito_catalog_not_found', 'message' => 'Nenhum catálogo cadastrado.' ),
							404
						);
					}

					$file_path = get_attached_file( $attachment_id );

					if ( ! $file_path || ! file_exists( $file_path ) ) {
						return new WP_REST_Response(
							array( 'code' => 'papelito_catalog_file_missing', 'message' => 'Arquivo não encontrado no servidor.' ),
							404
						);
					}

					$filename   = basename( $file_path );
					$filesize   = filesize( $file_path );
					$mime_type  = wp_check_filetype( $file_path )['type'] ?: 'application/pdf';

					header( 'Content-Type: ' . $mime_type );
					header( 'Content-Disposition: inline; filename="' . $filename . '"' );
					header( 'Content-Length: ' . $filesize );
					header( 'Cache-Control: public, max-age=3600' );
					readfile( $file_path );
					exit;
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/catalog-pdf',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$files = $request->get_file_params();

					if ( empty( $files['file'] ) ) {
						return new WP_REST_Response(
							array( 'code' => 'papelito_catalog_no_file', 'message' => 'Nenhum arquivo enviado.' ),
							400
						);
					}

					$file = $files['file'];

					if ( $file['error'] !== UPLOAD_ERR_OK ) {
						return new WP_REST_Response(
							array( 'code' => 'papelito_catalog_upload_error', 'message' => 'Erro no upload: ' . $file['error'] ),
							500
						);
					}

					$allowed_types = array( 'application/pdf' );
					$finfo         = finfo_open( FILEINFO_MIME_TYPE );
					$detected_mime = finfo_file( $finfo, $file['tmp_name'] );
					finfo_close( $finfo );

					if ( ! in_array( $detected_mime, $allowed_types, true ) ) {
						return new WP_REST_Response(
							array( 'code' => 'papelito_catalog_invalid_type', 'message' => 'Apenas arquivos PDF são aceitos.' ),
							415
						);
					}

					$old_attachment_id = (int) get_option( 'papelito_catalog_pdf_id', 0 );
					if ( $old_attachment_id > 0 ) {
						wp_delete_attachment( $old_attachment_id, true );
					}

					require_once ABSPATH . 'wp-admin/includes/image.php';
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/media.php';

					$upload_overrides = array(
						'test_form'   => false,
						'mimes'       => array( 'pdf' => 'application/pdf' ),
					);

					$attachment_id = media_handle_sideload(
						array(
							'name'     => $file['name'],
							'tmp_name' => $file['tmp_name'],
						),
						0,
						'Catálogo de Produtos - Papelito'
					);

					if ( is_wp_error( $attachment_id ) ) {
						return new WP_REST_Response(
							array( 'code' => 'papelito_catalog_save_error', 'message' => $attachment_id->get_error_message() ),
							500
						);
					}

					update_option( 'papelito_catalog_pdf_id', $attachment_id );

					$url = wp_get_attachment_url( $attachment_id );

					return new WP_REST_Response(
						array(
							'id'  => $attachment_id,
							'url' => $url,
						),
						201
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/catalog-pdf-info',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$attachment_id = (int) get_option( 'papelito_catalog_pdf_id', 0 );

					if ( $attachment_id <= 0 ) {
						return new WP_REST_Response( array( 'hasPdf' => false ), 200 );
					}

					$url = wp_get_attachment_url( $attachment_id );

					return new WP_REST_Response(
						array(
							'hasPdf' => true,
							'url'    => $url,
						),
						200
					);
				},
			)
		);
	}
);
