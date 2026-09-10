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
function wp_timezone() { return new DateTimeZone( 'America/Sao_Paulo' ); }

class WP_Error {
	public function __construct( private string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code() { return $this->code; }
}
class WP_REST_Request {
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

$deadline = papelito_return_window_deadline( '2026-09-05 20:42:51' );
assert_return( 'janela começa no dia seguinte à entrega, no fuso do site', '2026-09-12 23:59:59', $deadline?->format( 'Y-m-d H:i:s' ) );
assert_return( 'janela é ancorada no fuso do site, não em UTC', 'America/Sao_Paulo', $deadline?->getTimezone()->getName() );
// Entrega 21h local vira o dia seguinte em UTC: a janela nao pode ganhar um dia por isso.
assert_return( 'entrega noturna não desloca a janela em um dia', '2026-09-12 23:59:59', papelito_return_window_deadline( '2026-09-06 00:42:51' )?->format( 'Y-m-d H:i:s' ) );
assert_return( 'entrega ilegível não vira prazo silencioso', null, papelito_return_window_deadline( 'nao-e-data' ) );

$source = (string) file_get_contents( __DIR__ . '/../includes/returns.php' );
assert_return( 'domínio não chama wc_create_refund', false, str_contains( $source, 'wc_create_refund' ) );
assert_return( 'domínio não chama endpoint de refund Pagar.me', false, str_contains( strtolower( $source ), 'pagarme' ) );
assert_return( 'vendor só formaliza retorno a partir de um chamado específico', true, str_contains( $source, 'papelito_messaging_return_chamado_for_vendor' ) );
assert_return( 'autorização reversa notifica o comprador', true, str_contains( $source, 'return_authorization_issued' ) );

// --- repasse do motivo escolhido pelo comprador no chamado -------------------
class Papelito_Return_Reason_Request extends WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}

$sem_reason  = new Papelito_Return_Reason_Request();
$com_reason  = new Papelito_Return_Reason_Request( array( 'reason' => 'regret' ) );
$do_chamado  = array( 'reason' => 'defective', 'other' => '' );

$repassado = papelito_return_validate_reason( $sem_reason, $do_chamado );
$vencedor  = papelito_return_validate_reason( $com_reason, $do_chamado, true );
$sem_nada  = papelito_return_validate_reason( $sem_reason, null );
$fallback_invalido = papelito_return_validate_reason( $sem_reason, array( 'reason' => 'duvida_pedido', 'other' => '' ) );

assert_return( 'o motivo do chamado entra como padrão na formalização', 'defective', is_array( $repassado ) ? $repassado['reason'] : null );
assert_return( 'a formalização ignora a escolha enviada pelo vendor', 'defective', is_array( $vencedor ) ? $vencedor['reason'] : null );
assert_return( 'sem motivo e sem padrão continua sendo 422', true, $sem_nada instanceof WP_Error );
assert_return( 'o padrão vindo do chamado também é validado', true, $fallback_invalido instanceof WP_Error );

// Sem comentários: o docblock do helper extraído cita a função pelo nome.
$codigo = '';
foreach ( token_get_all( $source ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}
	$codigo .= is_array( $token ) ? $token[1] : $token;
}
$aberturas = preg_match_all( '/papelito_return_open\s*\(/', $codigo );
assert_return( 'papelito_return_open tem um chamador além da definição', 2, $aberturas );
assert_return( 'a formalização lê o motivo gravado no chamado específico', true, str_contains( $source, 'papelito_messaging_return_chamado_for_vendor' ) && str_contains( $source, 'fallback_only' ) );

exit( $failures > 0 ? 1 : 0 );
