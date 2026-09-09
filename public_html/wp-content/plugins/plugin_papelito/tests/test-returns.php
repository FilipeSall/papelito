<?php
/** Standalone guardrails for the return domain's pure policy. */
define( 'ABSPATH', __DIR__ );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_key( mixed $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function sanitize_textarea_field( mixed $value ) { return trim( (string) $value ); }
function get_option( string $key, mixed $default = false ) { return $default; }
function absint( mixed $value ) { return abs( (int) $value ); }
function wp_normalize_path( string $value ) { return $value; }
function untrailingslashit( string $value ) { return rtrim( $value, '/' ); }
function trailingslashit( string $value ) { return rtrim( $value, '/' ) . '/'; }
function add_action( mixed ...$args ) { unset( $args ); }

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code() { return $this->code; }
}

require_once __DIR__ . '/../includes/private_files.php';
require_once __DIR__ . '/../includes/returns.php';

$failures = 0;
function assert_return( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "PASS: {$label}\n"; return; }
	++$failures; echo "FAIL: {$label}\n";
}

assert_return( 'prazo mínimo padrão é 7 dias', 7, papelito_return_window_days() );
assert_return( 'autorização tem máximo próprio de 7 dias', 7, PAPELITO_RETURN_AUTHORIZATION_DAYS );
assert_return( 'código de autorização não é estado postal', true, ! in_array( 'authorization_code', papelito_return_statuses(), true ) );
assert_return( 'fluxo contém expiração sem inventar evento', true, in_array( 'posting_expired', papelito_return_statuses(), true ) );
assert_return( 'cancelamento só existe no fluxo anterior ao recebimento', false, in_array( 'cancelled', papelito_return_active_statuses(), true ) );
assert_return( 'estorno concluído continua consumindo quantidade', true, in_array( 'refunded', papelito_return_consuming_statuses(), true ) );
assert_return( 'motivo other é suportado', true, in_array( 'other', papelito_return_reasons(), true ) );
assert_return( 'centavos arredondam sem ponto flutuante persistido', 1099, papelito_return_decimal_to_cents( '10.99' ) );
assert_return( 'centavos preservam sinal negativo', -1099, papelito_return_decimal_to_cents( '-10.99' ) );
assert_return( 'prova aceita apenas formatos privados esperados', array( 'pdf', 'jpg', 'png' ), papelito_return_proof_spec()['formats'] );
assert_return( 'estorno igual ao teto é permitido', true, papelito_return_refund_amount_allowed( 5000, 5000 ) );
assert_return( 'estorno acima do teto é recusado', false, papelito_return_refund_amount_allowed( 5001, 5000 ) );
assert_return( 'estorno sem valor é recusado', false, papelito_return_refund_amount_allowed( 0, 5000 ) );

$source = (string) file_get_contents( __DIR__ . '/../includes/returns.php' );
assert_return( 'domínio não chama wc_create_refund', false, str_contains( $source, 'wc_create_refund' ) );
assert_return( 'domínio não chama endpoint de refund Pagar.me', false, str_contains( strtolower( $source ), 'pagarme' ) );

exit( $failures > 0 ? 1 : 0 );
