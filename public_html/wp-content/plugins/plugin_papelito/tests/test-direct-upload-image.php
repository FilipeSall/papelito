<?php
/**
 * Standalone regression test da validacao de imagem do upload direto.
 *
 * Cobre o que se perdeu quando o upload deixou de passar pelo proxy Next: assinatura real do
 * arquivo, divergencia entre conteudo e extensao/Content-Type, e truncamento. Sem isso o unico juiz
 * seria o WordPress, que aceita qualquer mime da allowlist do site.
 *
 * Usage: php tests/test-direct-upload-image.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

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

require_once __DIR__ . '/../includes/image_validation.php';

const MIME_PNG  = 'image/png';
const MIME_JPEG = 'image/jpeg';
const MIME_GIF  = 'image/gif';
const MIME_WEBP = 'image/webp';
const MIME_AVIF = 'image/avif';

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
function papelito_test_file( string $bytes, string $name, string $type = '' ): array {
	$path = tempnam( sys_get_temp_dir(), 'papimg' );
	file_put_contents( $path, $bytes );
	$GLOBALS['pap_tmp_files'][] = $path;

	return array( 'tmp_name' => $path, 'name' => $name, 'type' => $type, 'size' => strlen( $bytes ) );
}

$GLOBALS['pap_tmp_files'] = array();

/* Fixtures minimas, mas validas na assinatura e no fechamento de cada formato. */
$png  = "\x89PNG\r\n\x1a\n" . str_repeat( "\x00", 24 ) . 'IEND' . "\xae\x42\x60\x82";
$jpeg = "\xff\xd8\xff\xe0" . str_repeat( "\x00", 32 ) . "\xff\xd9";
$gif  = 'GIF89a' . str_repeat( "\x00", 32 );

/* WEBP: "RIFF" + tamanho LE do restante + "WEBP" + payload. */
$webp_payload = 'WEBPVP8 ' . str_repeat( "\x00", 24 );
$webp         = 'RIFF' . pack( 'V', strlen( $webp_payload ) ) . $webp_payload;

/* AVIF: box ftyp com a marca "avif" na lista. */
$avif_box = 'ftypavif' . str_repeat( "\x00", 16 );
$avif     = pack( 'N', strlen( $avif_box ) + 4 ) . $avif_box;

/* --- caso 1: formatos validos passam e o mime detectado vence o declarado --- */
foreach (
	array(
		'png'  => array( $png, 'foto.png', MIME_PNG ),
		'jpeg' => array( $jpeg, 'foto.jpg', MIME_JPEG ),
		'gif'  => array( $gif, 'foto.gif', MIME_GIF ),
		'webp' => array( $webp, 'foto.webp', MIME_WEBP ),
		'avif' => array( $avif, 'foto.avif', MIME_AVIF ),
	) as $label => $fixture
) {
	$result = papelito_direct_upload_validate_image( papelito_test_file( $fixture[0], $fixture[1], $fixture[2] ) );
	papelito_assert( "{$label} valido e aceito", false, is_wp_error( $result ) );
	papelito_assert( "{$label} detecta o mime real", $fixture[2], is_array( $result ) ? $result['mime'] : null );
}

/* --- caso 2: `image/jpg` e sinonimo aceito de `image/jpeg` --- */
$result = papelito_direct_upload_validate_image( papelito_test_file( $jpeg, 'foto.jpg', 'image/jpg' ) );
papelito_assert( 'image/jpg nao conta como divergencia', false, is_wp_error( $result ) );

/* --- caso 3: conteudo diverge do Content-Type declarado --- */
$result = papelito_direct_upload_validate_image( papelito_test_file( $png, 'foto.png', MIME_WEBP ) );
papelito_assert( 'PNG rotulado como WebP e recusado', 'papelito_image_content_mismatch', is_wp_error( $result ) ? $result->code : null );

/* --- caso 4: conteudo diverge da extensao --- */
$result = papelito_direct_upload_validate_image( papelito_test_file( $png, 'foto.jpg' ) );
papelito_assert( 'PNG com extensao .jpg e recusado', 'papelito_image_content_mismatch', is_wp_error( $result ) ? $result->code : null );

/* --- caso 5: nao e imagem nenhuma --- */
$result = papelito_direct_upload_validate_image( papelito_test_file( "<?php echo 'oi';", 'shell.png', MIME_PNG ) );
papelito_assert( 'PHP disfarcado de PNG e recusado', 'papelito_image_unknown_content', is_wp_error( $result ) ? $result->code : null );
papelito_assert( 'recusa de conteudo desconhecido responde 415', 415, is_wp_error( $result ) ? $result->data['status'] : null );

/* --- caso 6: truncamento por formato --- */
$result = papelito_direct_upload_validate_image( papelito_test_file( substr( $png, 0, 20 ), 'foto.png', MIME_PNG ) );
papelito_assert( 'PNG sem IEND e recusado', 'papelito_image_truncated', is_wp_error( $result ) ? $result->code : null );

$result = papelito_direct_upload_validate_image( papelito_test_file( substr( $jpeg, 0, 20 ), 'foto.jpg', MIME_JPEG ) );
papelito_assert( 'JPEG sem EOI e recusado', 'papelito_image_truncated', is_wp_error( $result ) ? $result->code : null );

$truncated_webp = 'RIFF' . pack( 'V', 4096 ) . $webp_payload;
$result         = papelito_direct_upload_validate_image( papelito_test_file( $truncated_webp, 'foto.webp', MIME_WEBP ) );
papelito_assert( 'WebP menor que o tamanho declarado e recusado', 'papelito_image_truncated', is_wp_error( $result ) ? $result->code : null );

/* --- caso 7: arquivo vazio e ilegivel --- */
$result = papelito_direct_upload_validate_image( papelito_test_file( '', 'foto.png', MIME_PNG ) );
papelito_assert( 'arquivo vazio e recusado', 'papelito_image_empty', is_wp_error( $result ) ? $result->code : null );

$result = papelito_direct_upload_validate_image( array( 'tmp_name' => '/caminho/inexistente', 'name' => 'foto.png', 'size' => 10 ) );
papelito_assert( 'arquivo ilegivel e recusado', 'papelito_image_unreadable', is_wp_error( $result ) ? $result->code : null );

/* --- caso 8: o nome sai normalizado para a extensao do tipo real --- */
$result = papelito_direct_upload_validate_image( papelito_test_file( $png, 'Foto do Produto (final).png', MIME_PNG ) );
papelito_assert( 'nome normalizado', 'Foto-do-Produto-final.png', is_array( $result ) ? $result['file_name'] : null );

$result = papelito_direct_upload_validate_image( papelito_test_file( $png, '???.png', MIME_PNG ) );
papelito_assert( 'nome sem caracteres uteis vira produto.png', 'produto.png', is_array( $result ) ? $result['file_name'] : null );

foreach ( $GLOBALS['pap_tmp_files'] as $path ) {
	@unlink( $path );
}

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAIL\n";
exit( 0 === $failures ? 0 : 1 );
