<?php
/**
 * Standalone regression test for the receipt e-mail attachment.
 *
 * O envio roda em requisicao REST, onde wp-admin/includes/file.php nao esta
 * carregado: usar wp_tempnam() ali derruba o site com erro critico.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
const RECEIPT_TEST_VENDOR_NAME = 'Açúcar & Cia';

function add_filter() {
	// Dublê: o teste chama as funções do arquivo direto, sem passar por hook.
}
function add_action() {
	// Dublê: o teste chama as funções do arquivo direto, sem passar por hook.
}
function wp_strip_all_tags( mixed $value ) { return strip_tags( (string) $value ); }
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_key( mixed $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function sanitize_email( mixed $value ) { return trim( (string) $value ); }
function is_email( mixed $value ) { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function absint( mixed $value ) { return abs( (int) $value ); }
function wp_date( string $format, int $timestamp ) { return gmdate( $format, $timestamp ); }
function is_wp_error( mixed $thing ) { return $thing instanceof WP_Error; }
function wp_json_encode( mixed $value ) { return json_encode( $value ); }
function trailingslashit( string $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_generate_password( int $length = 12, bool $special_chars = true ) { return substr( str_repeat( 'aB3xY9zQ7kM2', 4 ), 0, $length ); }
function get_current_user_id() { return 77; }

$transients = array();
function get_transient( string $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $ttl ) { global $transients; $transients[ $key ] = $value; return true; }

$temp_dir = '';
function get_temp_dir() { global $temp_dir; return $temp_dir; }

$deleted_files = array();
function wp_delete_file( string $path ): void { global $deleted_files; $deleted_files[] = $path; unlink( $path ); }

$mail_calls = array();
$mail_result = true;
function wp_mail( string $to, string $subject, string $body, array $headers = array(), array $attachments = array() ): bool {
	global $mail_calls, $mail_result;
	$attachment_bytes = array();
	foreach ( $attachments as $name => $path ) {
		$attachment_bytes[ $name ] = is_file( $path ) ? file_get_contents( $path ) : null;
	}
	$mail_calls[] = array(
		'to'          => $to,
		'subject'     => $subject,
		'body'        => $body,
		'headers'     => $headers,
		'attachments' => $attachments,
		'bytes'       => $attachment_bytes,
	);
	return $mail_result;
}

class WP_Error { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o is_wp_error() do código sob teste.
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_User { // NOSONAR -- o nome é o da classe do WordPress; renomear quebraria o instanceof do código sob teste.
	public int $ID = 0; // NOSONAR -- propriedade pública do WP_User do WordPress.
	public string $user_email = ''; // NOSONAR -- propriedade pública do WP_User do WordPress.

	public function __construct( int $id, string $email ) {
		$this->ID         = $id;
		$this->user_email = $email;
	}
}

function get_userdata( int $user_id ) { return new WP_User( $user_id, 'comprador@example.com' ); }

$receipt_store = array();
$vendor_parts  = array();

function papelito_pagarme_order_payment_snapshot( object $order ): array { return array( 'state' => 'paid' ); }
function papelito_pagarme_payment_state_is_paid( string $state ): bool { return in_array( $state, array( 'paid', 'captured' ), true ); }
function papelito_receipt_get_by_order( int $order_id ): ?array { global $receipt_store; return $receipt_store[ $order_id ] ?? null; }
function papelito_receipt_vendor_parts( int $receipt_id ): array { global $vendor_parts; return $vendor_parts[ $receipt_id ] ?? array(); }
function papelito_receipt_issue_for_order( int $order_id, string $origin = 'payment' ) {
	global $receipt_store;
	return $receipt_store[ $order_id ] ?? new WP_Error( 'papelito_receipt_payment_not_confirmed', 'Pagamento nao confirmado.', array( 'status' => 409 ) );
}

class ReceiptTestOrder {
	/**
	 * @param array<string,mixed> $meta Metadados do pedido.
	 */
	public function __construct(
		private int $id = 4242,
		private array $meta = array( '_papelito_vendor_status' => 'em_separacao' ),
		private string $status = 'processing'
	) {}
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_customer_id() { return 77; }
	public function get_status() { return $this->status; }
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
}

/**
 * Linha de recibo persistido, como o banco a devolveria.
 *
 * @return array<string,mixed>
 */
function receipt_email_fixture( int $order_id, string $number ): array {
	$items  = array(
		array(
			'name'           => 'Caderno 10 matérias',
			'quantity'       => 2,
			'subtotal_cents' => 5100,
			'discount_cents' => 600,
			'total_cents'    => 4500,
			'vendor_id'      => 9,
			'vendor_name'    => RECEIPT_TEST_VENDOR_NAME,
		),
	);
	$totals = array(
		'subtotal_cents' => 5100,
		'discount_cents' => 600,
		'shipping_cents' => 990,
		'total_cents'    => 5490,
	);

	return array(
		'id'                   => $order_id,
		'receipt_number'       => $number,
		'order_id'             => $order_id,
		'buyer_label'          => 'Papelaria São José LTDA',
		'company_cnpj'         => '12345678000190',
		'company_legal_name'   => 'Papelaria São José LTDA',
		'payment_method'       => 'credit_card',
		'payment_method_title' => 'Cartão de crédito',
		'payment_state'        => 'paid',
		'order_status'         => 'processing',
		'subtotal_cents'       => $totals['subtotal_cents'],
		'discount_cents'       => $totals['discount_cents'],
		'shipping_cents'       => $totals['shipping_cents'],
		'total_cents'          => $totals['total_cents'],
		'ordered_at'           => '2026-07-01 12:00:00',
		'paid_at'              => '2026-07-03 09:30:00',
		'issued_at'            => '2026-07-03 09:31:00',
		'snapshot_json'        => wp_json_encode(
			array(
				'version' => 1,
				'order'   => array( 'id' => $order_id, 'number' => (string) $order_id, 'status' => 'processing' ),
				'items'   => $items,
				'totals'  => $totals,
				'vendors' => array(
					array( 'vendor_id' => 9, 'vendor_name' => RECEIPT_TEST_VENDOR_NAME, 'total_cents' => $totals['total_cents'], 'items' => $items ),
				),
			)
		),
	);
}

require_once __DIR__ . '/../includes/order_receipt.php';

$failures = 0;
function assert_receipt_email( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

function assert_receipt_email_true( string $label, bool $condition ): void {
	assert_receipt_email( $label, true, $condition );
}

$temp_dir = sys_get_temp_dir() . '/papelito-receipt-email-' . getmypid();
mkdir( $temp_dir, 0700, true );
$temp_dir .= '/';

// --- Guarda estrutural: o envio roda em REST, sem wp-admin/includes/file.php. ---

/**
 * Identificadores realmente chamados no arquivo, ignorando comentarios e strings.
 *
 * @return array<int,string>
 */
function receipt_source_identifiers( string $path ): array {
	$names = array();

	foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
		if ( is_array( $token ) && T_STRING === $token[0] ) {
			$names[] = $token[1];
		}
	}

	return array_values( array_unique( $names ) );
}

$identifiers = receipt_source_identifiers( __DIR__ . '/../includes/order_receipt.php' );
$admin_only  = array( 'wp_tempnam', 'WP_Filesystem', 'download_url', 'wp_handle_upload', 'wp_handle_sideload', 'media_handle_upload', 'media_sideload_image', 'wp_crop_image', 'unzip_file', 'request_filesystem_credentials' );

foreach ( $admin_only as $function ) {
	assert_receipt_email(
		"order_receipt.php nao chama {$function}() (so existe em wp-admin/includes/file.php)",
		false,
		in_array( $function, $identifiers, true )
	);
}

// --- papelito_receipt_filename ---

$order = new ReceiptTestOrder();
assert_receipt_email( 'nome do arquivo do recibo', 'recibo-pedido-4242.pdf', papelito_receipt_filename( $order ) );

// --- papelito_receipt_email_attachment_file ---

$written = papelito_receipt_email_attachment_file( 4242, '%PDF-1.4 conteudo' );
assert_receipt_email_true( 'temporario criado sem wp_tempnam', is_string( $written ) && is_file( $written ) );
assert_receipt_email( 'temporario preserva os bytes do PDF', '%PDF-1.4 conteudo', file_get_contents( $written ) );
assert_receipt_email( 'temporario tem extensao .pdf', 'pdf', pathinfo( $written, PATHINFO_EXTENSION ) );
assert_receipt_email_true( 'temporario fica no diretorio temporario', str_starts_with( $written, $temp_dir ) );
unlink( $written );

$temp_dir_backup = $temp_dir;
$temp_dir        = $temp_dir . 'diretorio-inexistente/';
$failed          = @papelito_receipt_email_attachment_file( 4242, '%PDF-1.4' );
$temp_dir        = $temp_dir_backup;
assert_receipt_email( 'diretorio sem escrita vira WP_Error controlado', 'papelito_receipt_email_attachment_failed', is_wp_error( $failed ) ? $failed->get_error_code() : 'sem erro' );
assert_receipt_email( 'erro de anexo responde 500', 500, is_wp_error( $failed ) ? ( $failed->get_error_data()['status'] ?? 0 ) : 0 );

// --- papelito_receipt_send_email: fluxo completo ---

$receipt_store[4242] = receipt_email_fixture( 4242, 'PPL-2026-000482' );
$vendor_parts[4242]  = array(
	array(
		'vendor_id'   => 9,
		'vendor_name' => RECEIPT_TEST_VENDOR_NAME,
		'total_cents' => 5490,
		'items_json'  => wp_json_encode( array() ),
	),
);

$sent = papelito_receipt_send_email( $order );

assert_receipt_email( 'envio conclui sem erro', true, $sent );
assert_receipt_email( 'um e-mail enviado', 1, count( $mail_calls ) );

$call = $mail_calls[0] ?? array();
assert_receipt_email( 'destinatario e o e-mail do comprador', 'comprador@example.com', $call['to'] ?? '' );
assert_receipt_email( 'assunto cita o pedido', 'Recibo do pedido #4242', $call['subject'] ?? '' );
assert_receipt_email( 'um anexo', 1, count( $call['attachments'] ?? array() ) );
assert_receipt_email( 'anexo chega nomeado como PDF, nao .tmp', array( 'recibo-pedido-4242.pdf' ), array_keys( $call['attachments'] ?? array() ) );

$bytes = $call['bytes']['recibo-pedido-4242.pdf'] ?? '';
assert_receipt_email_true( 'anexo continha o PDF no momento do envio', is_string( $bytes ) && str_starts_with( $bytes, '%PDF' ) );

$attachment_path = ( $call['attachments'] ?? array() )['recibo-pedido-4242.pdf'] ?? '';
assert_receipt_email_true( 'temporario removido apos o envio', '' !== $attachment_path && ! file_exists( $attachment_path ) );
assert_receipt_email( 'remocao usa wp_delete_file', array( $attachment_path ), $deleted_files );

// --- Rate limit: a quarta tentativa na mesma hora e barrada. ---

$mail_calls = array();
papelito_receipt_send_email( $order );
papelito_receipt_send_email( $order );
$blocked = papelito_receipt_send_email( $order );
assert_receipt_email( 'quarta tentativa na hora e barrada', 'papelito_receipt_email_rate_limited', is_wp_error( $blocked ) ? $blocked->get_error_code() : 'sem erro' );
assert_receipt_email( 'tentativa barrada nao envia e-mail', 2, count( $mail_calls ) );

rmdir( rtrim( $temp_dir, '/' ) );

echo $failures > 0 ? "\n{$failures} FALHA(S)\n" : "\nTodos os testes passaram.\n";
exit( $failures > 0 ? 1 : 0 );
