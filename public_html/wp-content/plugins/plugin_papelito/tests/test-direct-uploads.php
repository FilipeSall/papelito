<?php

define( 'ABSPATH', __DIR__ . '/../' );
if ( ! defined( 'UPLOAD_ERR_OK' ) ) {
	define( 'UPLOAD_ERR_OK', 0 );
}
if ( ! defined( 'UPLOAD_ERR_NO_FILE' ) ) {
	define( 'UPLOAD_ERR_NO_FILE', 4 );
}

$GLOBALS['pap_upload_transients'] = array();
$GLOBALS['pap_upload_options']    = array();

function add_action( mixed ...$args ): void { unset( $args ); }
function register_rest_route( mixed ...$args ): void { unset( $args ); }
function rest_url( string $path ): string { return 'https://wp.example/wp-json/' . $path; }
function set_transient( string $key, mixed $value, int $ttl ): bool { unset( $ttl ); $GLOBALS['pap_upload_transients'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['pap_upload_transients'][ $key ] ?? false; }
function delete_transient( string $key ): bool { unset( $GLOBALS['pap_upload_transients'][ $key ] ); return true; }

// Modela o indice unico de `option_name`: a segunda insercao da mesma chave falha, e e isso que
// torna o consumo do tiquete atomico.
function add_option( string $key, mixed $value = '', string $deprecated = '', bool $autoload = true ): bool {
	unset( $deprecated, $autoload );

	if ( array_key_exists( $key, $GLOBALS['pap_upload_options'] ) ) {
		return false;
	}

	$GLOBALS['pap_upload_options'][ $key ] = $value;

	return true;
}

class WP_Error { // NOSONAR - Test double must preserve the WordPress API class name.
	public string $code;
	public string $message;
	public array $data;
	public function __construct( string $code = '', string $message = '', array $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

class WP_REST_Request { // NOSONAR - Test double must preserve the WordPress API class name.
	private array $files;
	public function __construct( string $method = 'POST', string $route = '' ) { unset( $method, $route ); $this->files = array(); }
	public function get_file_params(): array { return $this->files; }
	public function set_file_params( array $files ): void { $this->files = $files; }
}

require_once __DIR__ . '/../includes/direct_uploads.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label}\n";
	}
}

$created = papelito_direct_upload_ticket_create( 'media', array( 'user_id' => 9 ) );
papelito_assert( 'ticket has 43 URL-safe bytes', 43, strlen( $created['ticket'] ) );
papelito_assert( 'ticket response has direct URL', 'https://wp.example/wp-json/papelito/v1/uploads/direct', $created['uploadUrl'] );

$consumed = papelito_direct_upload_ticket_consume( $created['ticket'] );
papelito_assert( 'ticket preserves purpose', 'media', $consumed['purpose'] );
papelito_assert( 'ticket preserves server-only context', 9, $consumed['context']['user_id'] );
papelito_assert( 'ticket cannot be consumed twice', true, is_wp_error( papelito_direct_upload_ticket_consume( $created['ticket'] ) ) );

$request = new WP_REST_Request();
$request->set_file_params( array( 'file' => array( 'tmp_name' => '/tmp/file', 'error' => UPLOAD_ERR_OK, 'size' => PAPELITO_DIRECT_UPLOAD_MAX_BYTES + 1 ) ) );
$large = papelito_direct_upload_file( $request );
papelito_assert( 'file above 10 MB is rejected', true, is_wp_error( $large ) );
papelito_assert( 'large file returns 413', 413, $large->data['status'] );

// Duas requisicoes concorrentes com o mesmo tiquete: o claim atomico precisa eleger uma so. Com o
// `get_transient` + `delete_transient` anterior, ambas liam o valor antes de qualquer delete e
// ambas passavam.
$racing = papelito_direct_upload_ticket_create( 'catalog', array( 'user_id' => 11 ) );
$hash   = hash( 'sha256', $racing['ticket'] );
papelito_assert( 'first concurrent claim wins', true, papelito_direct_upload_ticket_claim( $hash ) );
papelito_assert( 'second concurrent claim loses', false, papelito_direct_upload_ticket_claim( $hash ) );

$raced = papelito_direct_upload_ticket_consume( $racing['ticket'] );
papelito_assert( 'ticket already claimed cannot be consumed', true, is_wp_error( $raced ) );
papelito_assert( 'claimed ticket returns 401', 401, $raced->data['status'] );

// Tiquete de pre-conta guarda o id da candidatura, nunca o `resume_token`: o transient vive em
// wp_options e o token e credencial.
$pre = papelito_direct_upload_ticket_create( 'pre-account-document', array( 'application_id' => 77 ) );
$pre_consumed = papelito_direct_upload_ticket_consume( $pre['ticket'] );
papelito_assert( 'pre-account ticket keeps the application id', 77, $pre_consumed['context']['application_id'] );
papelito_assert( 'pre-account ticket has no raw token', false, array_key_exists( 'application_token', $pre_consumed['context'] ) );

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit( 0 === $failures ? 0 : 1 );
