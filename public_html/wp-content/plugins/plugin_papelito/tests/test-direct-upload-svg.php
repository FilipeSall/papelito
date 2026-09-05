<?php
/**
 * Standalone regression test do SVG no upload direto.
 *
 * O validador raster reconhece formato por assinatura de bytes; SVG e texto e nao tem assinatura,
 * entao ele recusava com 415 todo SVG — justamente o formato que a tela de Assets pede para logo e
 * para icone de beneficio. Este teste fixa o caminho novo: aceita SVG limpo, recusa conteudo
 * perigoso, recusa `.svgz` e recusa arquivo que so se diz SVG.
 *
 * Usage: php tests/test-direct-upload-svg.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
const HOUR_IN_SECONDS = 3600;

class WP_Error { // NOSONAR - dublê precisa manter o nome da classe do WordPress.
	public string $code;
	public string $message;
	public array $data;
	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function current_user_can( string $capability ): bool { return true; }
function sanitize_title( string $value ): string {
	$value = strtolower( trim( $value ) );

	return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
}
function add_filter( ...$args ): bool { return true; }
function add_action( ...$args ): bool { return true; }
function wp_json_encode( mixed $value ): string { return (string) json_encode( $value ); }
function wp_upload_dir(): array { return array( 'path' => '/tmp' ); }
function sanitize_key( string $value ): string { return strtolower( $value ); }

require_once __DIR__ . '/../includes/media_uploads.php';
require_once __DIR__ . '/../includes/image_validation.php';
require_once __DIR__ . '/../includes/direct_uploads.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";

		return;
	}

	++$failures;
	echo '  FAIL: ' . $label . ' — esperado ' . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

/**
 * Grava bytes num arquivo temporario e devolve a entrada de $_FILES equivalente.
 *
 * @param string $bytes Conteudo do arquivo.
 * @param string $name  Nome declarado.
 * @param string $type  Content-Type declarado.
 * @return array<string,mixed>
 */
function papelito_test_file( string $bytes, string $name, string $type = 'image/svg+xml' ): array {
	$path = tempnam( sys_get_temp_dir(), 'papsvg' );
	file_put_contents( $path, $bytes );

	return array(
		'tmp_name' => $path,
		'size'     => strlen( $bytes ),
		'name'     => $name,
		'type'     => $type,
	);
}

/**
 * Roda a validacao e devolve o codigo de erro, ou o mime aceito.
 *
 * @param array<string,mixed> $file Entrada de $_FILES.
 * @return string
 */
function papelito_test_outcome( array $file ): string {
	$result = papelito_direct_upload_validate_svg( $file );
	unlink( $file['tmp_name'] );

	return is_wp_error( $result ) ? $result->code : (string) $result['mime'];
}

$clean = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>';

echo "SVG limpo\n";
papelito_assert( 'aceita SVG bem formado', 'image/svg+xml', papelito_test_outcome( papelito_test_file( $clean, 'Logo Papelito.svg' ) ) );
papelito_assert( 'aceita SVG sem prólogo XML', 'image/svg+xml', papelito_test_outcome( papelito_test_file( '<svg viewBox="0 0 8 8"/>', 'icone.svg' ) ) );
papelito_assert( 'normaliza o nome preservando .svg', 'logo-papelito.svg', papelito_direct_upload_svg_file_name( 'Logo Papelito.svg' ) );
papelito_assert( 'nome vazio vira imagem.svg', 'imagem.svg', papelito_direct_upload_svg_file_name( '.svg' ) );

echo "Conteúdo perigoso\n";
papelito_assert( 'recusa script embutido', 'papelito_svg_unsafe', papelito_test_outcome( papelito_test_file( '<svg><script>alert(1)</script></svg>', 'x.svg' ) ) );
papelito_assert( 'recusa handler de evento', 'papelito_svg_unsafe', papelito_test_outcome( papelito_test_file( '<svg onload="alert(1)"><path d="M0 0"/></svg>', 'x.svg' ) ) );
papelito_assert( 'recusa referência externa', 'papelito_svg_unsafe', papelito_test_outcome( papelito_test_file( '<svg><image xlink:href="//evil.test/x.png"/></svg>', 'x.svg' ) ) );
papelito_assert( 'recusa entidade externa', 'papelito_svg_unsafe', papelito_test_outcome( papelito_test_file( '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>', 'x.svg' ) ) );

echo "Conteúdo que não é SVG\n";
papelito_assert( 'recusa PNG renomeado para .svg', 'papelito_image_content_mismatch', papelito_test_outcome( papelito_test_file( "\x89PNG\r\n\x1a\n" . str_repeat( "\0", 24 ), 'falso.svg' ) ) );
papelito_assert( 'recusa arquivo vazio', 'papelito_image_empty', papelito_test_outcome( papelito_test_file( '', 'vazio.svg' ) ) );
papelito_assert( 'recusa gzip (.svgz)', 'papelito_svg_compressed', papelito_test_outcome( papelito_test_file( (string) gzencode( $clean ), 'logo.svgz' ) ) );
papelito_assert( 'recusa acima de 2 MB', 'papelito_svg_too_large', papelito_test_outcome( papelito_test_file( '<svg>' . str_repeat( ' ', 2 * 1024 * 1024 ) . '</svg>', 'grande.svg' ) ) );

echo "Roteamento entre validador raster e SVG\n";
papelito_assert( 'reconhece pelo nome', true, papelito_direct_upload_is_svg( array( 'name' => 'logo.svg', 'type' => '' ) ) );
papelito_assert( 'reconhece pelo Content-Type', true, papelito_direct_upload_is_svg( array( 'name' => 'logo', 'type' => 'image/svg+xml' ) ) );
papelito_assert( 'PNG continua no validador raster', false, papelito_direct_upload_is_svg( array( 'name' => 'foto.png', 'type' => 'image/png' ) ) );

echo $failures > 0 ? "\n{$failures} falha(s)\n" : "\nTodos os testes passaram\n";
exit( $failures > 0 ? 1 : 0 );
