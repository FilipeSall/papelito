<?php
/**
 * Validacao de imagem recebida por upload direto.
 *
 * Espelho PHP de `src/lib/server/image-upload.ts`, com os mesmos motivos de recusa e as mesmas
 * mensagens de `rejectionToFailure()`. Enquanto o upload passava pelo proxy Next a conferencia
 * acontecia la; com o browser falando direto com o WordPress, ela precisa existir deste lado — e
 * como o unico juiz seria o WordPress, que aceita qualquer mime da allowlist do site.
 *
 * Modulo isolado de proposito: nao depende de mais nada do plugin, o que permite exercita-lo em
 * teste standalone sem carregar os endpoints REST.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_IMAGE_MIME_BY_EXTENSION = array(
	'avif' => 'image/avif',
	'gif'  => 'image/gif',
	'jpeg' => 'image/jpeg',
	'jpg'  => 'image/jpeg',
	'png'  => 'image/png',
	'webp' => 'image/webp',
);

const PAPELITO_IMAGE_EXTENSION_BY_MIME = array(
	'image/avif' => 'avif',
	'image/gif'  => 'gif',
	'image/jpeg' => 'jpg',
	'image/png'  => 'png',
	'image/webp' => 'webp',
);

/**
 * Detecta o tipo real pelos bytes iniciais.
 *
 * Extensao e `Content-Type` sao declarados por quem envia; so a assinatura diz o que o arquivo e.
 *
 * @param string $header Primeiros bytes do arquivo (>= 32 recomendado).
 * @return string Mime detectado, ou string vazia.
 */
function papelito_image_detect_mime( string $header ): string {
	if ( str_starts_with( $header, "\x89PNG\r\n\x1a\n" ) ) {
		return 'image/png';
	}

	if ( str_starts_with( $header, "\xff\xd8\xff" ) ) {
		return 'image/jpeg';
	}

	if ( str_starts_with( $header, 'GIF87a' ) || str_starts_with( $header, 'GIF89a' ) ) {
		return 'image/gif';
	}

	if ( str_starts_with( $header, 'RIFF' ) && 'WEBP' === substr( $header, 8, 4 ) ) {
		return 'image/webp';
	}

	if ( papelito_image_is_avif( $header ) ) {
		return 'image/avif';
	}

	return '';
}

/**
 * Reconhece AVIF pela lista de marcas do box `ftyp`.
 *
 * @param string $header Primeiros bytes do arquivo.
 * @return bool
 */
function papelito_image_is_avif( string $header ): bool {
	if ( 'ftyp' !== substr( $header, 4, 4 ) ) {
		return false;
	}

	$unpacked = unpack( 'N', substr( $header, 0, 4 ) );
	$box_size = min( false === $unpacked ? 0 : (int) $unpacked[1], strlen( $header ) );

	for ( $offset = 8; $offset + 4 <= $box_size; $offset += 4 ) {
		if ( in_array( substr( $header, $offset, 4 ), array( 'avif', 'avis' ), true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Informa se o arquivo terminou antes do fim esperado do formato.
 *
 * Arquivo truncado passa na assinatura e so falha depois, dentro do editor de imagem do WordPress,
 * com mensagem que nao diz nada ao admin.
 *
 * @param string $path   Caminho do arquivo.
 * @param string $mime   Mime detectado.
 * @param int    $size   Tamanho em bytes.
 * @param string $header Primeiros bytes ja lidos.
 * @return bool
 */
function papelito_image_is_truncated( string $path, string $mime, int $size, string $header ): bool {
	if ( 'image/webp' === $mime ) {
		$unpacked = unpack( 'V', substr( $header, 4, 4 ) );

		return false === $unpacked || ( (int) $unpacked[1] ) + 8 > $size;
	}

	if ( 'image/png' === $mime ) {
		return 'IEND' !== papelito_image_read( $path, max( 0, $size - 8 ), 4 );
	}

	if ( 'image/jpeg' === $mime ) {
		$window = min( $size, 65536 );

		return ! str_contains( papelito_image_read( $path, $size - $window, $window ), "\xff\xd9" );
	}

	return false;
}

/**
 * Le um trecho do arquivo sem carregar tudo em memoria.
 *
 * @param string $path   Caminho do arquivo.
 * @param int    $offset Deslocamento inicial.
 * @param int    $length Bytes a ler.
 * @return string
 */
function papelito_image_read( string $path, int $offset, int $length ): string {
	if ( $length <= 0 ) {
		return '';
	}

	$contents = file_get_contents( $path, false, null, max( 0, $offset ), $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	return false === $contents ? '' : $contents;
}

/**
 * Normaliza o nome do arquivo para a extensao do tipo real.
 *
 * @param string $file_name Nome enviado pelo navegador.
 * @param string $mime      Mime detectado.
 * @return string
 */
function papelito_image_safe_file_name( string $file_name, string $mime ): string {
	$base = (string) preg_replace( '/\.[^.]*$/', '', $file_name );
	$base = (string) preg_replace( '/[^\w\-]+/', '-', $base );
	$base = trim( $base, '-' );
	$base = substr( $base, 0, 80 );

	return ( '' !== $base ? $base : 'produto' ) . '.' . PAPELITO_IMAGE_EXTENSION_BY_MIME[ $mime ];
}

/**
 * Valida a imagem recebida por upload direto.
 *
 * @param array<string,mixed> $file Entrada de `$_FILES`.
 * @return array{mime:string,file_name:string,size:int}|WP_Error
 */
function papelito_direct_upload_validate_image( array $file ) {
	$path = (string) ( $file['tmp_name'] ?? '' );
	$size = (int) ( $file['size'] ?? 0 );

	if ( '' === $path || ! is_readable( $path ) ) {
		return new WP_Error( 'papelito_image_unreadable', 'Não foi possível ler o arquivo enviado. Selecione a imagem novamente.', array( 'status' => 422 ) );
	}

	if ( $size <= 0 ) {
		return new WP_Error( 'papelito_image_empty', 'O arquivo enviado está vazio. Selecione uma imagem e tente novamente.', array( 'status' => 422 ) );
	}

	$header   = papelito_image_read( $path, 0, 32 );
	$detected = papelito_image_detect_mime( $header );

	if ( '' === $detected ) {
		return new WP_Error( 'papelito_image_unknown_content', 'O arquivo enviado não é uma imagem reconhecida. Use WebP, PNG, JPEG, AVIF ou GIF.', array( 'status' => 415 ) );
	}

	$declared = strtolower( trim( (string) ( $file['type'] ?? '' ) ) );
	$declared = 'image/jpg' === $declared ? 'image/jpeg' : $declared;

	if ( isset( PAPELITO_IMAGE_EXTENSION_BY_MIME[ $declared ] ) && $declared !== $detected ) {
		return papelito_image_mismatch_error();
	}

	$extension = strtolower( (string) pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );

	if ( isset( PAPELITO_IMAGE_MIME_BY_EXTENSION[ $extension ] ) && PAPELITO_IMAGE_MIME_BY_EXTENSION[ $extension ] !== $detected ) {
		return papelito_image_mismatch_error();
	}

	if ( papelito_image_is_truncated( $path, $detected, $size, $header ) ) {
		return new WP_Error( 'papelito_image_truncated', 'A imagem parece corrompida ou incompleta. Gere o arquivo novamente e reenvie.', array( 'status' => 422 ) );
	}

	return array(
		'mime'      => $detected,
		'file_name' => papelito_image_safe_file_name( (string) ( $file['name'] ?? '' ), $detected ),
		'size'      => $size,
	);
}

/**
 * Erro unico de divergencia entre conteudo e rotulo do arquivo.
 *
 * @return WP_Error
 */
function papelito_image_mismatch_error(): WP_Error {
	return new WP_Error(
		'papelito_image_content_mismatch',
		'O conteúdo do arquivo não corresponde à extensão informada. Salve a imagem no formato correto e envie novamente.',
		array( 'status' => 415 )
	);
}
