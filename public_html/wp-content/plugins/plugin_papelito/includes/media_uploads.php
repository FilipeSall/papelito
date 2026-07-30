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

add_filter(
	'upload_mimes',
	static function ( $mimes ) {
		if ( ! is_array( $mimes ) || ! papelito_media_can_upload_svg() ) {
			return $mimes;
		}

		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';

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
		$checked['type']            = 'image/svg+xml';
		$checked['proper_filename'] = false;

		return $checked;
	},
	10,
	3
);

add_filter(
	'wp_handle_upload_prefilter',
	static function ( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) ) {
			return $file;
		}

		if ( ! papelito_media_is_svg_filename( (string) $file['name'] ) ) {
			return $file;
		}

		if ( ! papelito_media_can_upload_svg() ) {
			$file['error'] = 'Envio de SVG permitido apenas para administradores.';
			return $file;
		}

		$path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		if ( '' === $path || ! is_readable( $path ) ) {
			$file['error'] = 'Não foi possível ler o arquivo SVG enviado.';
			return $file;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents || ! papelito_media_svg_contents_are_safe( (string) $contents ) ) {
			$file['error'] = 'SVG recusado: remova scripts, handlers de evento e referências externas antes de enviar.';
		}

		return $file;
	}
);
