<?php
/**
 * Validação e armazenamento genéricos de arquivos privados fora do webroot.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registro de formatos aceitos por arquivos privados.
 *
 * A chave é a extensão canônica usada no armazenamento.
 *
 * @return array<string,array{extensions:array<int,string>,mimes:array<int,string>,canonical_mime:string,verifier:string,error:string,error_message:string}>
 */
function papelito_private_file_formats(): array {
	return array(
		'jpg' => array(
			'extensions'     => array( 'jpg', 'jpeg' ),
			'mimes'          => array( 'image/jpeg' ),
			'canonical_mime' => 'image/jpeg',
			'verifier'       => 'papelito_private_file_verify_image',
			'error'          => 'image_invalid',
			'error_message'  => 'A imagem enviada é inválida.',
		),
		'png' => array(
			'extensions'     => array( 'png' ),
			'mimes'          => array( 'image/png' ),
			'canonical_mime' => 'image/png',
			'verifier'       => 'papelito_private_file_verify_image',
			'error'          => 'image_invalid',
			'error_message'  => 'A imagem enviada é inválida.',
		),
		'pdf' => array(
			'extensions'     => array( 'pdf' ),
			'mimes'          => array( 'application/pdf', 'application/x-pdf' ),
			'canonical_mime' => 'application/pdf',
			'verifier'       => 'papelito_private_file_verify_pdf',
			'error'          => 'pdf_invalid',
			'error_message'  => 'O PDF enviado é inválido.',
		),
		'xml' => array(
			'extensions'     => array( 'xml' ),
			'mimes'          => array( 'application/xml', 'text/xml' ),
			'canonical_mime' => 'application/xml',
			'verifier'       => 'papelito_private_file_verify_xml',
			'error'          => 'xml_invalid',
			'error_message'  => 'O XML enviado é inválido.',
		),
	);
}

/**
 * Formatos de um spec, preservando a ordem declarada.
 *
 * @param array<string,mixed> $spec Política do chamador.
 * @return array<string,array<string,mixed>>
 */
function papelito_private_file_spec_formats( array $spec ): array {
	$registry = papelito_private_file_formats();
	$selected = array();

	foreach ( (array) ( $spec['formats'] ?? array() ) as $key ) {
		$key = (string) $key;
		if ( isset( $registry[ $key ] ) ) {
			$selected[ $key ] = $registry[ $key ];
		}
	}

	return $selected;
}

function papelito_private_file_error( string $code_prefix, string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code_prefix . '_' . $code, $message, array( 'status' => $status ) );
}

/**
 * Diretório absoluto e fora do webroot.
 */
function papelito_private_files_dir( string $env_var, string $default_subdir ): string {
	$configured = function_exists( 'papelito_env' )
		? trim( (string) papelito_env( $env_var, '' ) )
		: '';

	if ( '' !== $configured ) {
		return wp_normalize_path( $configured );
	}

	return wp_normalize_path( dirname( untrailingslashit( ABSPATH ) ) . '/papelito-private/' . $default_subdir );
}

/**
 * Prepara armazenamento privado sem aceitar fallback dentro do webroot.
 *
 * @return string|WP_Error
 */
function papelito_private_files_prepare_dir( string $directory, string $code_prefix ) {
	$webroot = trailingslashit( wp_normalize_path( ABSPATH ) );

	if ( '' === $directory || '/' !== substr( $directory, 0, 1 ) || 0 === strpos( trailingslashit( $directory ), $webroot ) ) {
		return papelito_private_file_error( $code_prefix, 'storage_public', 'O armazenamento privado não está configurado corretamente.', 500 );
	}

	if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
		return papelito_private_file_error( $code_prefix, 'storage_unavailable', 'Não foi possível preparar o armazenamento privado.', 500 );
	}

	if ( ! is_writable( $directory ) ) {
		return papelito_private_file_error( $code_prefix, 'storage_unwritable', 'O armazenamento privado está indisponível.', 500 );
	}

	$real_directory = realpath( $directory );
	if ( false === $real_directory ) {
		return papelito_private_file_error( $code_prefix, 'storage_unavailable', 'O armazenamento privado está indisponível.', 500 );
	}
	$real_directory = wp_normalize_path( $real_directory );
	if ( 0 === strpos( trailingslashit( $real_directory ), $webroot ) ) {
		return papelito_private_file_error( $code_prefix, 'storage_public', 'O armazenamento privado não está configurado corretamente.', 500 );
	}

	chmod( $real_directory, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
	$permissions = fileperms( $real_directory );
	if ( false === $permissions || 0 !== ( $permissions & 0077 ) ) {
		return papelito_private_file_error( $code_prefix, 'storage_permissions', 'O armazenamento privado não possui permissões seguras.', 500 );
	}

	return $real_directory;
}

function papelito_private_file_verify_image( string $tmp_name, string $mime ): bool {
	$image = getimagesize( $tmp_name );

	return false !== $image && (string) ( $image['mime'] ?? '' ) === $mime;
}

function papelito_private_file_verify_pdf( string $tmp_name, string $mime ): bool {
	unset( $mime );
	$handle    = fopen( $tmp_name, 'rb' );
	$signature = $handle ? fread( $handle, 5 ) : '';
	if ( $handle ) {
		fclose( $handle );
	}

	return '%PDF-' === $signature;
}

/**
 * Construções hostis de XML, checadas por varredura de bytes antes de qualquer
 * parse. `<!DOCTYPE` habilita entidade externa; `<!ENTITY` é a expansão em si.
 */
function papelito_private_file_xml_is_hostile( string $contents ): bool {
	$normalized = strtolower( $contents );

	return false !== strpos( $normalized, '<!doctype' ) || false !== strpos( $normalized, '<!entity' );
}

/**
 * Aceita apenas XML textual sem DOCTYPE/ENTITY. O parse real e a checagem de
 * raiz ficam em fiscal_document_validation.php, depois do arquivo em disco.
 */
function papelito_private_file_verify_xml( string $tmp_name, string $mime ): bool {
	unset( $mime );
	$handle   = fopen( $tmp_name, 'rb' );
	$contents = $handle ? fread( $handle, 1048576 ) : '';
	if ( $handle ) {
		fclose( $handle );
	}

	if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
		return false;
	}

	if ( papelito_private_file_xml_is_hostile( $contents ) ) {
		return false;
	}

	return 1 === preg_match( '/^(?:\xEF\xBB\xBF)?\s*<(?:\?xml|[A-Za-z_])/', $contents );
}

/**
 * Mensagem de limite derivada do spec, para não divergir do valor aplicado.
 */
function papelito_private_file_size_message( int $max_bytes ): string {
	return sprintf( 'O documento deve ter no máximo %d MB.', (int) round( $max_bytes / 1048576 ) );
}

/**
 * Mensagem de extensão derivada do spec, para não divergir dos formatos aceitos.
 *
 * @param array<int,string> $extensions Extensões aceitas, na ordem do spec.
 */
function papelito_private_file_extension_message( array $extensions ): string {
	$labels = array_map( 'strtoupper', $extensions );
	$last   = (string) array_pop( $labels );
	$label  = empty( $labels ) ? $last : implode( ', ', $labels ) . ' ou ' . $last;

	return 'Envie um arquivo ' . $label . '.';
}

/**
 * Valida um upload contra o spec do chamador, conferindo conteúdo e não só extensão.
 *
 * @param array<string,mixed> $spec Política do chamador.
 * @return array{extension:string,mime:string,size:int,sha256:string,original_name:string}|WP_Error
 */
function papelito_private_file_validate_upload( array $file, array $spec ) {
	$code_prefix = (string) ( $spec['code_prefix'] ?? 'papelito_private_file' );
	$max_bytes   = (int) ( $spec['max_bytes'] ?? 0 );
	$formats     = papelito_private_file_spec_formats( $spec );

	if ( empty( $formats ) || $max_bytes <= 0 ) {
		return papelito_private_file_error( $code_prefix, 'spec_invalid', 'O armazenamento privado não está configurado corretamente.', 500 );
	}

	$allowed_extensions = array();
	$mime_index         = array();
	foreach ( $formats as $format_key => $format ) {
		foreach ( $format['extensions'] as $extension ) {
			$allowed_extensions[] = $extension;
		}
		foreach ( $format['mimes'] as $mime_type ) {
			$mime_index[ $mime_type ] = $format_key;
		}
	}

	$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
	if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) ) {
		return papelito_private_file_error( $code_prefix, 'upload_invalid', 'Envie um documento válido.', 422 );
	}

	$tmp_name = (string) $file['tmp_name'];
	if ( ! is_uploaded_file( $tmp_name ) && ! ( 'cli' === PHP_SAPI && is_file( $tmp_name ) ) ) {
		return papelito_private_file_error( $code_prefix, 'upload_invalid', 'O upload recebido é inválido.', 400 );
	}

	$actual_size = filesize( $tmp_name );
	$size        = false === $actual_size ? 0 : (int) $actual_size;
	if ( $size <= 0 || $size > $max_bytes ) {
		return papelito_private_file_error( $code_prefix, 'size_invalid', papelito_private_file_size_message( $max_bytes ), 413 );
	}

	$original_name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
	$extension     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
	if ( ! in_array( $extension, $allowed_extensions, true ) ) {
		return papelito_private_file_error( $code_prefix, 'extension_invalid', papelito_private_file_extension_message( $allowed_extensions ), 415 );
	}

	$finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
	$mime  = $finfo ? (string) finfo_file( $finfo, $tmp_name ) : '';
	if ( $finfo ) {
		finfo_close( $finfo );
	}

	if ( ! isset( $mime_index[ $mime ] ) ) {
		return papelito_private_file_error( $code_prefix, 'mime_invalid', 'O conteúdo do arquivo não corresponde a um formato permitido.', 415 );
	}

	$format_key = $mime_index[ $mime ];
	$format     = $formats[ $format_key ];
	if ( ! in_array( $extension, $format['extensions'], true ) ) {
		return papelito_private_file_error( $code_prefix, 'type_mismatch', 'A extensão não corresponde ao conteúdo do arquivo.', 415 );
	}

	$verifier = (string) $format['verifier'];
	if ( ! function_exists( $verifier ) || ! $verifier( $tmp_name, $mime ) ) {
		return papelito_private_file_error( $code_prefix, (string) $format['error'], (string) $format['error_message'], 422 );
	}

	if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
		$wp_allowed = array();
		foreach ( $formats as $candidate ) {
			$wp_allowed[ implode( '|', $candidate['extensions'] ) ] = $candidate['canonical_mime'];
		}
		$wp_type = wp_check_filetype_and_ext( $tmp_name, $original_name, $wp_allowed );
		if ( ( empty( $wp_type['ext'] ) || empty( $wp_type['type'] ) ) && 'xml' !== $format_key ) {
			return papelito_private_file_error( $code_prefix, 'wp_type_invalid', 'O WordPress não reconheceu o tipo do documento.', 415 );
		}
	}

	$fallback_basename = (string) ( $spec['fallback_basename'] ?? 'documento' );

	return array(
		'extension'     => $format_key,
		'mime'          => (string) $format['canonical_mime'],
		'size'          => $size,
		'sha256'        => hash_file( 'sha256', $tmp_name ),
		'original_name' => '' !== $original_name ? substr( $original_name, 0, 191 ) : $fallback_basename . '.' . $format_key,
	);
}

/**
 * Grava o arquivo com nome aleatório e permissão restrita.
 *
 * @param array{extension:string,mime:string,size:int,sha256:string,original_name:string} $validated Metadados validados.
 * @return array{key:string,path:string}|WP_Error
 */
function papelito_private_file_store( array $file, array $validated, string $directory, string $code_prefix ) {
	try {
		$key = bin2hex( random_bytes( 32 ) ) . '.' . $validated['extension'];
	} catch ( Throwable $error ) {
		return papelito_private_file_error( $code_prefix, 'random_failed', 'Não foi possível preparar o documento.', 500 );
	}

	$path  = trailingslashit( $directory ) . $key;
	$moved = move_uploaded_file( (string) $file['tmp_name'], $path );
	if ( ! $moved && 'cli' === PHP_SAPI ) {
		$moved = copy( (string) $file['tmp_name'], $path );
	}
	if ( ! $moved || ! is_file( $path ) ) {
		return papelito_private_file_error( $code_prefix, 'store_failed', 'Não foi possível armazenar o documento.', 500 );
	}

	if ( ! chmod( $path, 0600 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		papelito_private_file_discard_path( $path );
		return papelito_private_file_error( $code_prefix, 'permissions_failed', 'Não foi possível proteger o documento.', 500 );
	}

	return array(
		'key'  => $key,
		'path' => $path,
	);
}

/**
 * Remove um arquivo recém-gravado em caso de rollback/conflito.
 */
function papelito_private_file_discard_path( string $path ): void {
	if ( is_file( $path ) ) {
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}

/**
 * Valida uma storage key antes de qualquer leitura ou exclusão em disco.
 *
 * @param array<int,string> $format_keys Extensões canônicas aceitas.
 */
function papelito_private_file_key_is_valid( string $key, array $format_keys ): bool {
	$registry = papelito_private_file_formats();
	$allowed  = array();

	foreach ( $format_keys as $format_key ) {
		$format_key = (string) $format_key;
		if ( isset( $registry[ $format_key ] ) ) {
			$allowed[] = preg_quote( $format_key, '/' );
		}
	}

	if ( empty( $allowed ) ) {
		return false;
	}

	return 1 === preg_match( '/^[a-f0-9]{64}\.(?:' . implode( '|', $allowed ) . ')$/', $key );
}
