<?php
/** Standalone guardrails for the order refund domain's pure policy. */
define( 'ABSPATH', __DIR__ );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PAPELITO_VENDOR_STATUS_CANCELLED', 'cancelado' );

function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_key( mixed $value ) { return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function sanitize_textarea_field( mixed $value ) { return trim( (string) $value ); }
function add_action( mixed ...$args ) { unset( $args ); }
function add_filter( mixed ...$args ) { unset( $args ); }
function remove_filter( mixed ...$args ) { unset( $args ); }
function do_action( mixed ...$args ) { unset( $args ); }
function papelito_validate_cpf( string $raw ): bool { return '52998224725' === $raw; }
function papelito_validate_cnpj( string $raw ): bool { return '11222333000181' === $raw; }

class WP_Error {
	public function __construct( private string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }

$refund_test_order = null;
$refund_test_wc_attempts = 0;
$refund_test_wc_should_fail = false;
function wc_get_order( int $order_id ) {
	global $refund_test_order;
	return $refund_test_order instanceof Papelito_Refund_Projection_Order && $refund_test_order->get_id() === $order_id ? $refund_test_order : null;
}
function wc_create_refund( array $args ) {
	global $refund_test_order, $refund_test_wc_attempts, $refund_test_wc_should_fail;
	++$refund_test_wc_attempts;
	if ( $refund_test_wc_should_fail ) {
		return new WP_Error( 'wc_refund_failed', 'falha simulada' );
	}
	$refund_test_order->record_refund( (float) ( $args['amount'] ?? 0 ) );
	return new stdClass();
}

require_once __DIR__ . '/../includes/order_refunds.php';

$failures = 0;
function assert_refund( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "PASS: {$label}\n"; return; }
	++$failures;
	echo "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}
function error_code( mixed $value ): string { return is_wp_error( $value ) ? $value->get_error_code() : ''; }

$now = 1_800_000_000;

assert_refund( 'pedido não pago não abre estorno', 'nao_aplicavel', papelito_order_refund_mode( 'credit_card', false, $now, $now ) );
assert_refund( 'cartão pago volta pela API', 'api', papelito_order_refund_mode( 'credit_card', true, $now - DAY_IN_SECONDS, $now ) );
assert_refund( 'PIX dentro dos 90 dias volta pela API', 'api', papelito_order_refund_mode( 'pix', true, $now - 10 * DAY_IN_SECONDS, $now ) );
assert_refund( 'PIX no 90º dia ainda volta pela API', 'api', papelito_order_refund_mode( 'pix', true, $now - 90 * DAY_IN_SECONDS, $now ) );
assert_refund( 'PIX fora dos 90 dias vai para o manual', 'manual', papelito_order_refund_mode( 'pix', true, $now - 91 * DAY_IN_SECONDS, $now ) );
assert_refund( 'PIX sem data de pagamento vai para o manual', 'manual', papelito_order_refund_mode( 'pix', true, null, $now ) );
assert_refund( 'boleto pago vai para o manual', 'manual', papelito_order_refund_mode( 'boleto', true, $now - DAY_IN_SECONDS, $now ) );
assert_refund( 'meio desconhecido vai para o manual, nunca some', 'manual', papelito_order_refund_mode( 'desconhecido', true, $now, $now ) );

assert_refund( 'idempotência é estável por pedido e cobrança', papelito_order_refund_idempotency_key( 42, 'ch_abc' ), papelito_order_refund_idempotency_key( 42, 'ch_abc' ) );
assert_refund( 'idempotência muda entre pedidos', false, papelito_order_refund_idempotency_key( 42, 'ch_abc' ) === papelito_order_refund_idempotency_key( 43, 'ch_abc' ) );
assert_refund( 'idempotência cabe no header', true, strlen( papelito_order_refund_idempotency_key( PHP_INT_MAX, str_repeat( 'x', 500 ) ) ) <= 64 );

assert_refund( 'recibo de estorno tem série própria', 'PPE-2026-000042', papelito_order_refund_format_receipt_number( 2026, 42 ) );
assert_refund( 'prazo manual é de 7 dias', gmdate( 'Y-m-d H:i:s', $now + 7 * DAY_IN_SECONDS ), papelito_order_refund_due_at( $now ) );

assert_refund( 'primeira nova tentativa em 5 minutos', 300, papelito_order_refund_retry_delay( 1 ) );
assert_refund( 'espera dobra a cada tentativa', 600, papelito_order_refund_retry_delay( 2 ) );
assert_refund( 'espera tem teto de 6 horas', 6 * HOUR_IN_SECONDS, papelito_order_refund_retry_delay( 50 ) );
assert_refund( 'quatro tentativas ainda não esgotam', false, papelito_order_refund_api_exhausted( 4 ) );
assert_refund( 'cinco tentativas caem para o manual', true, papelito_order_refund_api_exhausted( 5 ) );

assert_refund( 'cobrança canceled está devolvida', true, papelito_order_refund_charge_is_refunded( array( 'status' => 'canceled' ), 1000 ) );
assert_refund( 'canceled_amount integral está devolvido', true, papelito_order_refund_charge_is_refunded( array( 'status' => 'paid', 'canceled_amount' => 1000 ), 1000 ) );
assert_refund( 'estorno parcial não conta como devolvido', false, papelito_order_refund_charge_is_refunded( array( 'status' => 'paid', 'canceled_amount' => 400 ), 1000 ) );
assert_refund( 'cobrança paga sem estorno não está devolvida', false, papelito_order_refund_charge_is_refunded( array( 'status' => 'paid' ), 1000 ) );

assert_refund( 'chargeback é detectado', 'chargeback', papelito_order_refund_charge_anomaly( array( 'status' => 'chargedback' ) ) );
assert_refund( 'estorno parcial é detectado', 'partial_refund', papelito_order_refund_charge_anomaly( array( 'status' => 'paid', 'amount' => 1000, 'canceled_amount' => 400 ) ) );
assert_refund( 'cobrança paga normal não é anomalia', '', papelito_order_refund_charge_anomaly( array( 'status' => 'paid', 'amount' => 1000 ) ) );
assert_refund( 'estorno total não é anomalia', '', papelito_order_refund_charge_anomaly( array( 'status' => 'canceled', 'amount' => 1000, 'canceled_amount' => 1000 ) ) );

assert_refund( 'suspenso cancela pedido anterior à suspensão', true, papelito_order_refund_suspension_allows_cancel( gmdate( 'Y-m-d H:i:s', $now ), $now - HOUR_IN_SECONDS ) );
assert_refund( 'suspenso não cancela pedido posterior', false, papelito_order_refund_suspension_allows_cancel( gmdate( 'Y-m-d H:i:s', $now ), $now + HOUR_IN_SECONDS ) );
assert_refund( 'sem data de suspensão, nega', false, papelito_order_refund_suspension_allows_cancel( '', $now ) );

assert_refund( 'estornado é terminal', true, in_array( 'estornado', papelito_order_refund_terminal_vendor_statuses(), true ) );
assert_refund( 'cancelado é terminal', true, in_array( 'cancelado', papelito_order_refund_terminal_vendor_statuses(), true ) );
assert_refund( 'cancelamento_solicitado não é terminal: dinheiro ainda com o vendor', false, in_array( 'cancelamento_solicitado', papelito_order_refund_terminal_vendor_statuses(), true ) );
assert_refund( 'cancelamento_solicitado sai da esteira de envio', true, in_array( 'cancelamento_solicitado', papelito_order_refund_closed_vendor_statuses(), true ) );

assert_refund( 'CPF válido é normalizado para dígitos', '52998224725', papelito_refund_pix_normalize_key( 'cpf', '529.982.247-25' ) );
assert_refund( 'CPF inválido é recusado', 'papelito_refund_pix_key_invalid', error_code( papelito_refund_pix_normalize_key( 'cpf', '111.111.111-11' ) ) );
assert_refund( 'CNPJ válido é normalizado', '11222333000181', papelito_refund_pix_normalize_key( 'cnpj', '11.222.333/0001-81' ) );
assert_refund( 'e-mail vira minúsculo', 'fulano@exemplo.com.br', papelito_refund_pix_normalize_key( 'email', ' Fulano@Exemplo.com.br ' ) );
assert_refund( 'e-mail inválido é recusado', 'papelito_refund_pix_key_invalid', error_code( papelito_refund_pix_normalize_key( 'email', 'fulano@' ) ) );
assert_refund( 'telefone ganha +55', '+5511987654321', papelito_refund_pix_normalize_key( 'telefone', '(11) 98765-4321' ) );
assert_refund( 'telefone com +55 não duplica o DDI', '+5511987654321', papelito_refund_pix_normalize_key( 'telefone', '+55 11 98765-4321' ) );
assert_refund( 'telefone curto é recusado', 'papelito_refund_pix_key_invalid', error_code( papelito_refund_pix_normalize_key( 'telefone', '98765-4321' ) ) );
assert_refund( 'chave aleatória vira minúscula', '123e4567-e89b-42d3-a456-426614174000', papelito_refund_pix_normalize_key( 'aleatoria', '123E4567-E89B-42D3-A456-426614174000' ) );
assert_refund( 'chave aleatória malformada é recusada', 'papelito_refund_pix_key_invalid', error_code( papelito_refund_pix_normalize_key( 'aleatoria', 'nao-e-uuid' ) ) );
assert_refund( 'tipo desconhecido é recusado', 'papelito_refund_pix_type_invalid', error_code( papelito_refund_pix_normalize_key( 'boleto', '123' ) ) );

assert_refund( 'pista do CPF mostra só os 4 finais', '****4725', papelito_refund_pix_key_hint( 'cpf', '52998224725' ) );
assert_refund( 'pista do e-mail esconde o usuário', 'f***@exemplo.com.br', papelito_refund_pix_key_hint( 'email', 'fulano@exemplo.com.br' ) );
assert_refund( 'pista nunca é a chave inteira', false, str_contains( papelito_refund_pix_key_hint( 'telefone', '+5511987654321' ), '987654321' ) );
assert_refund( 'titular curto demais é recusado', 'papelito_refund_pix_holder_invalid', error_code( papelito_refund_pix_normalize_holder( 'Al' ) ) );
assert_refund( 'titular tem espaços normalizados', 'Maria da Silva', papelito_refund_pix_normalize_holder( "  Maria   da\tSilva " ) );

class Papelito_Test_Order {
	public function __construct( private array $meta, private ?int $paid_at ) {}
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function get_date_paid() { return null === $this->paid_at ? null : new DateTimeImmutable( '@' . $this->paid_at ); }
}

class Papelito_Refund_Projection_Order {
	private array $meta = array();
	private float $refunded = 0.0;
	public int $notes = 0;
	public function get_id(): int { return 991; }
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function add_order_note( string $note ): void { ++$this->notes; }
	public function save(): void {}
	public function get_total(): float { return 50.0; }
	public function get_total_refunded(): float { return $this->refunded; }
	public function record_refund( float $amount ): void { $this->refunded += $amount; }
}

$refund_test_order          = new Papelito_Refund_Projection_Order();
$refund_test_wc_attempts    = 0;
$refund_test_wc_should_fail = true;
$projection_row             = array(
	'id'             => 77,
	'order_id'       => 991,
	'customer_id'    => 0,
	'amount_cents'   => 5000,
	'mode'           => 'api',
	'receipt_number' => 'PPE-2026-000077',
	'source'         => 'api',
);
assert_refund( 'falha na projeção WooCommerce deixa reconciliação pendente', false, papelito_order_refund_apply_to_order( $projection_row, 'api' ) );
$refund_test_wc_should_fail = false;
assert_refund( 'reconciliação conclui a projeção depois da falha', true, papelito_order_refund_apply_to_order( $projection_row, 'reconciliation' ) );
assert_refund( 'reconciliação não duplica lançamento WooCommerce', 2, $refund_test_wc_attempts );
assert_refund( 'projeção concluída é idempotente', true, papelito_order_refund_apply_to_order( $projection_row, 'reconciliation' ) );
assert_refund( 'projeção idempotente não chama WooCommerce de novo', 2, $refund_test_wc_attempts );

$recent = time() - DAY_IN_SECONDS;
assert_refund( 'cartão com cobrança resolve para API', 'api', papelito_order_refund_resolve_mode( new Papelito_Test_Order( array( '_papelito_pagarme_payment_method' => 'credit_card', '_papelito_pagarme_charge_id' => 'ch_1' ), $recent ) ) );
assert_refund( 'cartão sem id de cobrança cai para o manual', 'manual', papelito_order_refund_resolve_mode( new Papelito_Test_Order( array( '_papelito_pagarme_payment_method' => 'credit_card' ), $recent ) ) );
assert_refund( 'PIX recente com cobrança resolve para API', 'api', papelito_order_refund_resolve_mode( new Papelito_Test_Order( array( '_papelito_pagarme_payment_method' => 'pix', '_papelito_pagarme_charge_id' => 'ch_2' ), $recent ) ) );
assert_refund( 'boleto com cobrança continua manual', 'manual', papelito_order_refund_resolve_mode( new Papelito_Test_Order( array( '_papelito_pagarme_payment_method' => 'boleto', '_papelito_pagarme_charge_id' => 'ch_3' ), $recent ) ) );

$source = (string) file_get_contents( __DIR__ . '/../includes/order_refunds.php' );
assert_refund( 'reembolso WooCommerce nunca repõe estoque', true, 1 === preg_match( "/'restock_items'\s*=>\s*false/", $source ) );
assert_refund( 'nenhuma chamada repõe estoque', 0, preg_match_all( "/'restock_items'\s*=>\s*true|wc_restock_refunded_items|wc_maybe_increase_stock_levels/", $source ) );
assert_refund( 'reembolso WooCommerce não chama gateway', true, 1 === preg_match( "/'refund_payment'\s*=>\s*false/", $source ) );
assert_refund( 'status cancelled do WooCommerce não é usado (ele repõe estoque)', 0, preg_match_all( "/update_status\(\s*'cancelled'/", $source ) );
assert_refund( 'e-mail nativo de refund do WooCommerce é suprimido', true, str_contains( $source, 'woocommerce_email_enabled_customer_refunded_order' ) );
assert_refund( 'chave PIX só é decifrada por um caminho', 1, substr_count( $source, 'papelito_refund_pix_key_decrypt( (int)' ) );
assert_refund( 'revelar chave PIX gera evento', true, str_contains( $source, "'pix_key_revealed'" ) );

echo "\n" . ( 0 === $failures ? 'OK' : "{$failures} falha(s)" ) . "\n";
exit( 0 === $failures ? 0 : 1 );
