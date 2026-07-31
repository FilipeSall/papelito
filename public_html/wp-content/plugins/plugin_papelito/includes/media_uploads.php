<?php
/**
 * Regras de upload de midia do Papelito.
 *
 * O WordPress bloqueia SVG por padrao. As logos administraveis do marketplace sao vetoriais, entao
 * o formato e liberado apenas para quem tem `manage_options` e o conteudo e recusado quando traz
 * script, entidade externa ou handler de evento. Rejeitar (em vez de higienizar) evita entregar ao
 * admin um arquivo silenciosamente diferente do que ele enviou.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_MEDIA_SVG_MIME_TYPE              = 'image/svg+xml';
const PAPELITO_MEDIA_STORAGE_UNAVAILABLE_CODE   = 'papelito_media_upload_directory_unavailable';
const PAPELITO_MEDIA_STORAGE_UNAVAILABLE_NOTICE = 'O armazenamento de mídia está temporariamente indisponível.';

/**
 * Determina se o usuario atual pode enviar SVG.
 *
 * @return bool
 */
function papelito_media_can_upload_svg(): bool {
	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

/**
 * Padroes recusados dentro de um SVG.
 *
 * @return array<int, string>
 */
function papelito_media_svg_blocked_patterns(): array {
	return array(
		'#<\s*script#i',
		'#<\s*foreignObject#i',
		'#<\s*iframe#i',
		'#<\s*embed#i',
		'#<\s*object#i',
		'#<!ENTITY#i',
		'#<\s*!DOCTYPE[^>]*ENTITY#is',
		'#javascript\s*:#i',
		'#\son[a-z]+\s*=#i',
		'#(?:xlink:)?href\s*=\s*["\']\s*(?:https?:)?//#i',
	);
}

/**
 * Verifica se o conteudo de um SVG e aceitavel.
 *
 * @param string $contents Conteudo bruto do arquivo.
 * @return bool
 */
function papelito_media_svg_contents_are_safe( string $contents ): bool {
	if ( '' === trim( $contents ) ) {
		return false;
	}

	foreach ( papelito_media_svg_blocked_patterns() as $pattern ) {
		if ( preg_match( $pattern, $contents ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Determina se um nome de arquivo aparenta ser SVG.
 *
 * @param string $filename Nome do arquivo.
 * @return bool
 */
function papelito_media_is_svg_filename( string $filename ): bool {
	$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

	return in_array( $extension, array( 'svg', 'svgz' ), true );
}

/**
 * Registra falha de armazenamento de midia publica.
 *
 * @param string $stage  Etapa que falhou.
 * @param string $path   Caminho de destino.
 * @param string $reason Causa da falha.
 * @return void
 */
function papelito_media_log_upload_failure( string $stage, string $path, string $reason ): void {
	$entry = wp_json_encode(
		array(
			'event'  => 'media_upload_failure',
			'stage'  => $stage,
			'path'   => $path,
			'reason' => $reason,
		)
	);

	if ( is_string( $entry ) ) {
		error_log( $entry ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Identifica a primeira falha do diretorio publico de upload.
 *
 * @return array<string, string> Falha com `stage`, `path` e `reason`; array vazio quando o diretorio esta pronto.
 */
function papelito_media_upload_directory_failure(): array {
	$upload_dir = wp_upload_dir();
	$path       = isset( $upload_dir['path'] ) ? (string) $upload_dir['path'] : '';
	$error      = isset( $upload_dir['error'] ) ? (string) $upload_dir['error'] : '';
	$failure    = array();

	if ( '' !== $error || '' === $path ) {
		$failure = array(
			'stage'  => 'directory_resolution',
			'reason' => '' !== $error ? $error : 'Upload directory path is empty.',
		);
	} elseif ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
		$failure = array(
			'stage'  => 'directory_creation',
			'reason' => 'wp_mkdir_p returned false.',
		);
	} elseif ( ! is_dir( $path ) || ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Preflight must verify the PHP process permissions before the native media upload runs.
		$failure = array(
			'stage'  => 'directory_writability',
			'reason' => 'Upload directory is not writable by the PHP process.',
		);
	}

	if ( array() !== $failure ) {
		$failure['path'] = $path;
	}

	return $failure;
}

/**
 * Garante que o diretorio publico de upload esta pronto para gravacao.
 *
 * @return true|WP_Error
 */
function papelito_media_prepare_public_upload() {
	$failure = papelito_media_upload_directory_failure();

	if ( array() === $failure ) {
		return true;
	}

	papelito_media_log_upload_failure( $failure['stage'], $failure['path'], $failure['reason'] );

	return new WP_Error(
		PAPELITO_MEDIA_STORAGE_UNAVAILABLE_CODE,
		PAPELITO_MEDIA_STORAGE_UNAVAILABLE_NOTICE,
		array( 'status' => 503 )
	);
}

/**
 * Interrompe uploads REST quando o armazenamento publico nao esta disponivel.
 *
 * @param mixed           $result  Resultado atual do REST.
 * @param WP_REST_Server  $server  Servidor REST, exigido pela assinatura do filtro.
 * @param WP_REST_Request $request Requisicao REST.
 * @return mixed
 */
function papelito_media_preflight_rest_upload( $result, $server, $request ) {
	if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || '/wp/v2/media' !== $request->get_route() ) {
		return $result;
	}

	$prepared = papelito_media_prepare_public_upload();

	return is_wp_error( $prepared ) ? $prepared : $result;
}

/**
 * Registra a causa devolvida pelo manipulador nativo de arquivos.
 *
 * @param mixed  $upload  Resultado do manipulador.
 * @param string $context Etapa de movimentacao.
 * @return mixed
 */
function papelito_media_capture_upload_error( $upload, string $context ) {
	if ( ! is_array( $upload ) || empty( $upload['error'] ) ) {
		return $upload;
	}

	$upload_dir = wp_upload_dir();
	$path       = isset( $upload_dir['path'] ) ? (string) $upload_dir['path'] : '';
	papelito_media_log_upload_failure( $context, $path, (string) $upload['error'] );

	return $upload;
}

/**
 * Motivo da recusa de um SVG enviado.
 *
 * @param array<string, mixed> $file Arquivo em processamento pelo WordPress.
 * @return string Motivo da recusa, ou string vazia quando o arquivo e aceito.
 */
function papelito_media_svg_upload_error( array $file ): string {
	$path  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
	$error = '';

	if ( ! papelito_media_can_upload_svg() ) {
		$error = 'Envio de SVG permitido apenas para administradores.';
	} elseif ( '' === $path || ! is_readable( $path ) ) {
		$error = 'Não foi possível ler o arquivo SVG enviado.';
	} else {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents || ! papelito_media_svg_contents_are_safe( (string) $contents ) ) {
			$error = 'SVG recusado: remova scripts, handlers de evento e referências externas antes de enviar.';
		}
	}

	return $error;
}

add_filter( 'rest_pre_dispatch', 'papelito_media_preflight_rest_upload', 10, 3 );
add_filter(
	'wp_handle_upload',
	static function ( $upload ) {
		return papelito_media_capture_upload_error( $upload, 'move_uploaded_file' );
	}
);
add_filter(
	'wp_handle_sideload',
	static function ( $upload ) {
		return papelito_media_capture_upload_error( $upload, 'move_sideload_file' );
	}
);

add_filter(
	'upload_mimes',
	static function ( $mimes ) {
		if ( ! is_array( $mimes ) || ! papelito_media_can_upload_svg() ) {
			return $mimes;
		}

		$mimes['svg']  = PAPELITO_MEDIA_SVG_MIME_TYPE;
		$mimes['svgz'] = PAPELITO_MEDIA_SVG_MIME_TYPE;

		return $mimes;
	}
);

add_filter(
	'wp_check_filetype_and_ext',
	static function ( $checked, $file, $filename ) {
		if ( ! is_array( $checked ) || ! papelito_media_can_upload_svg() ) {
			return $checked;
		}

		if ( ! papelito_media_is_svg_filename( (string) $filename ) ) {
			return $checked;
		}

		$checked['ext']             = 'svg';
		$checked['type']            = PAPELITO_MEDIA_SVG_MIME_TYPE;
		$checked['proper_filename'] = false;

		return $checked;
	},
	10,
	3
);

add_filter(
	'wp_handle_upload_prefilter',
	static function ( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) || ! papelito_media_is_svg_filename( (string) $file['name'] ) ) {
			return $file;
		}

		$error = papelito_media_svg_upload_error( $file );

		if ( '' !== $error ) {
			$file['error'] = $error;
		}

		return $file;
	}
);
