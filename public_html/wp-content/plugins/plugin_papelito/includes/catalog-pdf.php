<?php
/**
 * Endpoints REST para gerenciar o PDF do catálogo de produtos.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PAPELITO_CATALOG_PDF_OPTION_ID       = 'papelito_catalog_pdf_id';
const PAPELITO_CATALOG_PDF_CACHE_VERSION   = 'papelito_catalog_pdf_version';
const PAPELITO_CATALOG_DEFAULT_PUBLIC_PATH = '/pdf/catalogo-papelito.pdf';
const PAPELITO_CATALOG_MAX_FILE_SIZE       = 15728640;

function papelito_catalog_pdf_default_catalog(): array {
	return array(
		'source'      => 'default',
		'id'          => 0,
		'url'         => PAPELITO_CATALOG_DEFAULT_PUBLIC_PATH,
		'filename'    => 'catalogo-papelito.pdf',
		'isAvailable' => true,
	);
}

function papelito_catalog_pdf_cache_version(): int {
	return (int) get_option( PAPELITO_CATALOG_PDF_CACHE_VERSION, 1 );
}

function papelito_catalog_pdf_bump_cache_version(): void {
	update_option( PAPELITO_CATALOG_PDF_CACHE_VERSION, papelito_catalog_pdf_cache_version() + 1, false );
}

function papelito_catalog_pdf_attachment_snapshot( int $attachment_id ): ?array {
	if ( $attachment_id <= 0 ) {
		return null;
	}

	$file_path = get_attached_file( $attachment_id );
	$url       = wp_get_attachment_url( $attachment_id );
	$filename  = $file_path ? basename( $file_path ) : '';

	return array(
		'source'      => 'custom',
		'id'          => $attachment_id,
		'url'         => $url ?: '',
		'filename'    => $filename,
		'isAvailable' => (bool) ( $file_path && is_readable( $file_path ) && filesize( $file_path ) > 0 ),
	);
}

function papelito_catalog_pdf_snapshot(): array {
	$default_catalog    = papelito_catalog_pdf_default_catalog();
	$attachment_id      = (int) get_option( PAPELITO_CATALOG_PDF_OPTION_ID, 0 );
	$configured_catalog = papelito_catalog_pdf_attachment_snapshot( $attachment_id );
	$active_catalog     = $configured_catalog && $configured_catalog['isAvailable'] ? $configured_catalog : $default_catalog;

	return array(
		'activeCatalog'     => $active_catalog,
		'configuredCatalog' => $configured_catalog,
		'defaultCatalog'    => $default_catalog,
		'cacheVersion'      => papelito_catalog_pdf_cache_version(),
		'issues'            => $configured_catalog && ! $configured_catalog['isAvailable']
			? array( 'O catálogo personalizado está indisponível. O catálogo padrão será usado automaticamente.' )
			: array(),
	);
}

function papelito_catalog_pdf_response( array $data, int $status = 200 ): WP_REST_Response {
	return new WP_REST_Response( $data, $status );
}

function papelito_catalog_pdf_error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

function papelito_catalog_pdf_is_uploaded_file( string $path ): bool {
	return is_uploaded_file( $path ) || ( 'cli' === PHP_SAPI && file_exists( $path ) );
}

function papelito_catalog_pdf_require_admin(): bool {
	return current_user_can( 'manage_options' );
}

function papelito_catalog_pdf_validate_upload( array $file ) {
	if ( empty( $file['tmp_name'] ) || ! papelito_catalog_pdf_is_uploaded_file( $file['tmp_name'] ) ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_invalid_upload', 'Upload inválido.', 400 );
	}

	if ( (int) ( $file['size'] ?? 0 ) <= 0 ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_empty_file', 'O PDF enviado está vazio.', 422 );
	}

	if ( (int) $file['size'] > PAPELITO_CATALOG_MAX_FILE_SIZE ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_file_too_large', 'O PDF excede o limite permitido de 15 MB.', 413 );
	}

	$extension = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
	if ( 'pdf' !== $extension ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_invalid_extension', 'Apenas arquivos PDF são aceitos.', 415 );
	}

	$finfo         = finfo_open( FILEINFO_MIME_TYPE );
	$detected_mime = $finfo ? finfo_file( $finfo, $file['tmp_name'] ) : '';
	if ( $finfo ) {
		finfo_close( $finfo );
	}

	if ( ! in_array( $detected_mime, array( 'application/pdf', 'application/x-pdf' ), true ) ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_invalid_type', 'Apenas arquivos PDF válidos são aceitos.', 415 );
	}

	$handle    = fopen( $file['tmp_name'], 'rb' );
	$signature = $handle ? fread( $handle, 5 ) : '';
	if ( $handle ) {
		fclose( $handle );
	}

	if ( '%PDF-' !== $signature ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_corrupt_pdf', 'O arquivo enviado não possui assinatura PDF válida.', 422 );
	}

	return true;
}

function papelito_catalog_pdf_upload( WP_REST_Request $request ) {
	$files = $request->get_file_params();

	if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_no_file', 'Nenhum arquivo enviado.', 400 );
	}

	$file = $files['file'];

	if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_upload_error', 'Erro ao receber o upload.', 500 );
	}

	$validation = papelito_catalog_pdf_validate_upload( $file );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	foreach ( array( 'image.php', 'file.php', 'media.php' ) as $admin_include ) {
		$include_path = ABSPATH . 'wp-admin/includes/' . $admin_include;
		if ( file_exists( $include_path ) ) {
			require_once $include_path;
		}
	}

	$safe_name = sanitize_file_name( (string) $file['name'] );
	if ( '' === $safe_name ) {
		$safe_name = 'catalogo-papelito.pdf';
	}

	$sideload = array(
		'name'     => 'catalogo-papelito-' . gmdate( 'YmdHis' ) . '-' . $safe_name,
		'tmp_name' => $file['tmp_name'],
		'type'     => 'application/pdf',
		'error'    => UPLOAD_ERR_OK,
		'size'     => (int) $file['size'],
	);

	$old_attachment_id = (int) get_option( PAPELITO_CATALOG_PDF_OPTION_ID, 0 );
	$attachment_id     = media_handle_sideload( $sideload, 0, 'Catálogo de Produtos - Papelito' );

	if ( is_wp_error( $attachment_id ) ) {
		return papelito_catalog_pdf_error( 'papelito_catalog_save_error', $attachment_id->get_error_message(), 500 );
	}

	$new_file_path = get_attached_file( (int) $attachment_id );
	if ( ! $new_file_path || ! is_readable( $new_file_path ) || filesize( $new_file_path ) <= 0 ) {
		wp_delete_attachment( (int) $attachment_id, true );
		return papelito_catalog_pdf_error( 'papelito_catalog_saved_file_missing', 'O PDF enviado não ficou disponível para leitura.', 500 );
	}

	update_option( PAPELITO_CATALOG_PDF_OPTION_ID, (int) $attachment_id, false );
	papelito_catalog_pdf_bump_cache_version();

	if ( $old_attachment_id > 0 && $old_attachment_id !== (int) $attachment_id ) {
		wp_delete_attachment( $old_attachment_id, true );
	}

	return papelito_catalog_pdf_response( papelito_catalog_pdf_snapshot(), 201 );
}

function papelito_catalog_pdf_restore_default() {
	$old_attachment_id = (int) get_option( PAPELITO_CATALOG_PDF_OPTION_ID, 0 );

	delete_option( PAPELITO_CATALOG_PDF_OPTION_ID );
	papelito_catalog_pdf_bump_cache_version();

	if ( $old_attachment_id > 0 ) {
		wp_delete_attachment( $old_attachment_id, true );
	}

	return papelito_catalog_pdf_response( papelito_catalog_pdf_snapshot(), 200 );
}

function papelito_catalog_pdf_stream() {
	$snapshot = papelito_catalog_pdf_snapshot();
	$active   = $snapshot['activeCatalog'];

	if ( 'custom' !== $active['source'] ) {
		return papelito_catalog_pdf_response( $snapshot, 200 );
	}

	$file_path = get_attached_file( (int) $active['id'] );
	if ( ! $file_path || ! is_readable( $file_path ) || filesize( $file_path ) <= 0 ) {
		return papelito_catalog_pdf_response( $snapshot, 404 );
	}

	$filename = basename( $file_path );
	$filesize = filesize( $file_path );

	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: inline; filename="' . $filename . '"' );
	header( 'Content-Length: ' . $filesize );
	header( 'Cache-Control: public, max-age=3600' );
	readfile( $file_path );
	exit;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/catalog-pdf',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => 'papelito_catalog_pdf_stream',
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => 'papelito_catalog_pdf_require_admin',
					'callback'            => 'papelito_catalog_pdf_upload',
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => 'papelito_catalog_pdf_require_admin',
					'callback'            => 'papelito_catalog_pdf_restore_default',
				),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/catalog-pdf-info',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					return papelito_catalog_pdf_response( papelito_catalog_pdf_snapshot(), 200 );
				},
			)
		);
	}
);
