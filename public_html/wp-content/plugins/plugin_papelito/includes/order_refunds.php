<?php
/**
 * Estorno do pedido pago que o vendor cancela.
 *
 * Separa a decisao comercial (cancelar) do fato financeiro (devolver): pedido pago nunca chega a
 * `cancelado`. Fica em `cancelamento_solicitado` enquanto o dinheiro esta com o vendor e so vira
 * `estornado` quando a devolucao e confirmada, pela Pagar.me ou pelo registro manual do vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_VENDOR_STATUS_CANCEL_REQUESTED' ) ) {
	define( 'PAPELITO_VENDOR_STATUS_CANCEL_REQUESTED', 'cancelamento_solicitado' );
}

if ( ! defined( 'PAPELITO_VENDOR_STATUS_REFUNDED' ) ) {
	define( 'PAPELITO_VENDOR_STATUS_REFUNDED', 'estornado' );
}

const PAPELITO_ORDER_REFUND_STATUS_PROCESSING     = 'processando';
const PAPELITO_ORDER_REFUND_STATUS_REFUNDED       = 'reembolsado';
const PAPELITO_ORDER_REFUND_STATUS_FAILED         = 'falhou';
const PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING = 'manual_pendente';
const PAPELITO_ORDER_REFUND_MODE_API              = 'api';
const PAPELITO_ORDER_REFUND_MODE_MANUAL           = 'manual';
const PAPELITO_ORDER_REFUND_MODE_NONE             = 'nao_aplicavel';
const PAPELITO_ORDER_REFUND_PIX_API_WINDOW_DAYS   = 90;
const PAPELITO_ORDER_REFUND_MANUAL_DUE_DAYS       = 7;
const PAPELITO_ORDER_REFUND_MAX_API_ATTEMPTS      = 5;
const PAPELITO_ORDER_REFUND_MAX_PENDING_PROOFS    = 5;
const PAPELITO_ORDER_REFUND_RETRY_HOOK            = 'papelito_order_refunds_retry';
const PAPELITO_ORDER_REFUND_RECEIPT_PREFIX        = 'PPE';
const PAPELITO_ORDER_REFUND_MYSQL_FORMAT          = 'Y-m-d H:i:s';
const PAPELITO_ORDER_REFUND_PIX_KEY_TYPES         = array( 'cpf', 'cnpj', 'email', 'telefone', 'aleatoria' );
const PAPELITO_ORDER_REFUND_MSG_NOT_FOUND         = 'Estorno não encontrado.';
const PAPELITO_ORDER_DOCUMENTS_SURFACE_ORDER      = 'pedido';
const PAPELITO_ORDER_DOCUMENTS_SURFACE_REFUND     = 'estorno';

/**
 * Nomes das tabelas do estorno, com o prefixo do $wpdb.
 *
 * @return array<string,string>
 */
function papelito_order_refund_tables(): array {
	global $wpdb;

	return array(
		'refunds'   => $wpdb->prefix . 'papelito_order_refunds',
		'events'    => $wpdb->prefix . 'papelito_order_refund_events',
		'proofs'    => $wpdb->prefix . 'papelito_order_refund_proofs',
		'pix_keys'  => $wpdb->prefix . 'papelito_refund_pix_keys',
		'sequences' => $wpdb->prefix . 'papelito_order_refund_sequences',
	);
}

/**
 * Cria ou atualiza as tabelas do estorno.
 */
function papelito_order_refund_install_tables(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$tables  = papelito_order_refund_tables();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$tables['refunds']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  mode VARCHAR(16) NOT NULL,
  status VARCHAR(32) NOT NULL,
  payment_method VARCHAR(32) NOT NULL DEFAULT '',
  pagarme_charge_id VARCHAR(64) NOT NULL DEFAULT '',
  pagarme_order_id VARCHAR(64) NOT NULL DEFAULT '',
  amount_cents BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  next_attempt_at DATETIME NULL,
  reason TEXT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'vendor_cancel',
  requested_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  performed_by_vendor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  recorded_by_user_id BIGINT UNSIGNED NULL,
  proof_id BIGINT UNSIGNED NULL,
  manual_reference VARCHAR(191) NULL,
  manual_notes TEXT NULL,
  refund_due_at DATETIME NULL,
  receipt_number VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  receipt_year SMALLINT UNSIGNED NULL,
  receipt_sequence BIGINT UNSIGNED NULL,
  requested_at DATETIME NOT NULL,
  settled_at DATETIME NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_order (order_id),
  UNIQUE KEY uq_idempotency (idempotency_key),
  UNIQUE KEY uq_proof (proof_id),
  UNIQUE KEY uq_receipt (receipt_number),
  KEY idx_status_due (status,refund_due_at),
  KEY idx_status_next (status,next_attempt_at),
  KEY idx_vendor_status (vendor_id,status)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$tables['events']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  refund_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(64) NOT NULL,
  from_status VARCHAR(32) NULL,
  to_status VARCHAR(32) NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  payload LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_refund (refund_id,id),
  KEY idx_order (order_id)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$tables['proofs']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  refund_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  original_name VARCHAR(191) NOT NULL,
  mime VARCHAR(64) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  attached_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_storage (storage_key),
  KEY idx_refund_vendor (refund_id,vendor_id)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$tables['pix_keys']} (
  user_id BIGINT UNSIGNED NOT NULL,
  key_type VARCHAR(16) NOT NULL,
  key_ciphertext LONGTEXT NOT NULL,
  key_hint VARCHAR(96) NOT NULL,
  holder_name VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (user_id)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$tables['sequences']} (
  sequence_year SMALLINT UNSIGNED NOT NULL,
  next_sequence BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (sequence_year)
) {$charset};"
	);
}

/**
 * Estados do eixo financeiro do estorno.
 *
 * @return array<int,string>
 */
function papelito_order_refund_statuses(): array {
	return array(
		PAPELITO_ORDER_REFUND_STATUS_PROCESSING,
		PAPELITO_ORDER_REFUND_STATUS_REFUNDED,
		PAPELITO_ORDER_REFUND_STATUS_FAILED,
		PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING,
	);
}

/**
 * Estados operacionais que encerram o pedido. `cancelamento_solicitado` fica de fora: e trabalho
 * em aberto, com dinheiro ainda do lado do vendor.
 *
 * @return array<int,string>
 */
function papelito_order_refund_terminal_vendor_statuses(): array {
	return array( PAPELITO_VENDOR_STATUS_CANCELLED, PAPELITO_VENDOR_STATUS_REFUNDED );
}

/**
 * Estados operacionais fora da esteira de envio: nao geram venda, nota nem avanco logistico.
 *
 * @return array<int,string>
 */
function papelito_order_refund_closed_vendor_statuses(): array {
	return array( PAPELITO_VENDOR_STATUS_CANCELLED, PAPELITO_VENDOR_STATUS_CANCEL_REQUESTED, PAPELITO_VENDOR_STATUS_REFUNDED );
}

/**
 * Caminho do estorno para um pedido: pela API da Pagar.me, manual pelo vendor, ou nenhum.
 *
 * Cartao e PIX dentro da janela do arranjo voltam pela API. Boleto pago nao volta pela API desde
 * 01/10/2026, PIX fora da janela e meio desconhecido tambem vao para o manual, que obriga alguem a
 * devolver em vez de deixar o valor parado.
 *
 * @param string   $method  Meio de pagamento gravado no pedido.
 * @param bool     $paid    O pedido teve pagamento confirmado.
 * @param int|null $paid_at Momento do pagamento, em timestamp.
 * @param int      $now     Momento de referência, em timestamp.
 */
function papelito_order_refund_mode( string $method, bool $paid, ?int $paid_at, int $now ): string {
	if ( ! $paid ) {
		return PAPELITO_ORDER_REFUND_MODE_NONE;
	}

	$method = sanitize_key( $method );

	if ( 'credit_card' === $method ) {
		return PAPELITO_ORDER_REFUND_MODE_API;
	}

	if ( 'pix' === $method && null !== $paid_at && $paid_at > 0 && ( $now - $paid_at ) <= PAPELITO_ORDER_REFUND_PIX_API_WINDOW_DAYS * DAY_IN_SECONDS ) {
		return PAPELITO_ORDER_REFUND_MODE_API;
	}

	return PAPELITO_ORDER_REFUND_MODE_MANUAL;
}

/**
 * Chave de idempotencia do DELETE na Pagar.me, estavel por pedido e cobranca.
 *
 * @param int    $order_id  Id do pedido.
 * @param string $charge_id Id da cobrança na Pagar.me.
 */
function papelito_order_refund_idempotency_key( int $order_id, string $charge_id ): string {
	return 'papelito-refund-' . $order_id . '-' . substr( hash( 'sha256', $charge_id ), 0, 16 );
}

/**
 * Numero do recibo de estorno.
 *
 * @param int $year     Ano da numeração.
 * @param int $sequence Sequencial do ano.
 */
function papelito_order_refund_format_receipt_number( int $year, int $sequence ): string {
	return sprintf( '%s-%04d-%06d', PAPELITO_ORDER_REFUND_RECEIPT_PREFIX, $year, $sequence );
}

/**
 * Prazo do estorno manual, em UTC.
 *
 * @param int $now Momento de referência, em timestamp.
 */
function papelito_order_refund_due_at( int $now ): string {
	return gmdate( PAPELITO_ORDER_REFUND_MYSQL_FORMAT, $now + ( PAPELITO_ORDER_REFUND_MANUAL_DUE_DAYS * DAY_IN_SECONDS ) );
}

/**
 * Espera ate a proxima tentativa pela API, crescendo por tentativa e limitada a seis horas.
 *
 * @param int $attempts Tentativas já feitas pela API.
 */
function papelito_order_refund_retry_delay( int $attempts ): int {
	$exponent = min( 10, max( 0, $attempts - 1 ) );

	return (int) min( 6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** $exponent ) );
}

/**
 * Esgotou as tentativas pela API e deve cair para o estorno manual.
 *
 * @param int $attempts Tentativas já feitas pela API.
 */
function papelito_order_refund_api_exhausted( int $attempts ): bool {
	return $attempts >= PAPELITO_ORDER_REFUND_MAX_API_ATTEMPTS;
}

/**
 * A cobranca ja foi devolvida integralmente na Pagar.me.
 *
 * @param array<string,mixed> $charge       Cobranca retornada pela API.
 * @param int                 $amount_cents Valor do estorno, em centavos.
 */
function papelito_order_refund_charge_is_refunded( array $charge, int $amount_cents ): bool {
	$status = sanitize_key( (string) ( $charge['status'] ?? '' ) );

	if ( in_array( $status, array( 'canceled', 'cancelled' ), true ) ) {
		return true;
	}

	$canceled = (int) ( $charge['canceled_amount'] ?? 0 );

	return $amount_cents > 0 && $canceled >= $amount_cents;
}

/**
 * Reversao que a Papelito nao iniciou e que o estado da cobranca sozinho nao revela.
 *
 * @param array<string,mixed> $charge Cobranca retornada pela API.
 * @return string `chargeback`, `partial_refund` ou vazio.
 */
function papelito_order_refund_charge_anomaly( array $charge ): string {
	$status = sanitize_key( (string) ( $charge['status'] ?? '' ) );

	if ( 'chargedback' === $status ) {
		return 'chargeback';
	}

	$canceled = (int) ( $charge['canceled_amount'] ?? 0 );
	$amount   = (int) ( $charge['amount'] ?? 0 );

	if ( 'paid' === $status && $canceled > 0 && ( $amount <= 0 || $canceled < $amount ) ) {
		return 'partial_refund';
	}

	return '';
}

/**
 * Conta suspensa so cancela pedido feito antes da suspensao. Sem data registrada nao ha como
 * provar a ordem, entao nega.
 *
 * @param string $suspended_at     Data mysql UTC da suspensao.
 * @param int    $order_created_at Criação do pedido, em timestamp.
 */
function papelito_order_refund_suspension_allows_cancel( string $suspended_at, int $order_created_at ): bool {
	$suspended_at = trim( $suspended_at );

	if ( '' === $suspended_at || $order_created_at <= 0 ) {
		return false;
	}

	$suspended_ts = strtotime( $suspended_at . ' UTC' );

	return false !== $suspended_ts && $order_created_at < $suspended_ts;
}

/**
 * Normaliza e valida uma chave PIX conforme o tipo.
 *
 * @param string $type Tipo da chave: cpf, cnpj, email, telefone ou aleatoria.
 * @param string $raw  Chave digitada.
 * @return string|WP_Error
 */
function papelito_refund_pix_normalize_key( string $type, string $raw ) {
	$type = sanitize_key( $type );
	$raw  = trim( $raw );

	if ( ! in_array( $type, PAPELITO_ORDER_REFUND_PIX_KEY_TYPES, true ) ) {
		return new WP_Error( 'papelito_refund_pix_type_invalid', 'Tipo de chave PIX inválido.', array( 'status' => 422 ) );
	}

	$invalid = new WP_Error( 'papelito_refund_pix_key_invalid', 'Chave PIX inválida para o tipo escolhido.', array( 'status' => 422 ) );

	if ( 'cpf' === $type ) {
		$digits = (string) preg_replace( '/\D+/', '', $raw );
		$valid  = 11 === strlen( $digits ) && ( ! function_exists( 'papelito_validate_cpf' ) || papelito_validate_cpf( $digits ) );

		return $valid ? $digits : $invalid;
	}

	if ( 'cnpj' === $type ) {
		$digits = (string) preg_replace( '/\D+/', '', $raw );
		$valid  = 14 === strlen( $digits ) && ( ! function_exists( 'papelito_validate_cnpj' ) || papelito_validate_cnpj( $digits ) );

		return $valid ? $digits : $invalid;
	}

	if ( 'email' === $type ) {
		$email = strtolower( $raw );

		return strlen( $email ) <= 77 && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : $invalid;
	}

	if ( 'telefone' === $type ) {
		$digits = (string) preg_replace( '/\D+/', '', $raw );

		if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '55' ) ) {
			$digits = substr( $digits, 2 );
		}

		return in_array( strlen( $digits ), array( 10, 11 ), true ) ? '+55' . $digits : $invalid;
	}

	$key = strtolower( $raw );

	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key ) ? $key : $invalid;
}

/**
 * Pista da chave para exibir sem decifrar: nunca a chave inteira.
 *
 * @param string $type Tipo da chave PIX.
 * @param string $key  Chave PIX normalizada.
 */
function papelito_refund_pix_key_hint( string $type, string $key ): string {
	if ( 'email' === $type && false !== strpos( $key, '@' ) ) {
		list( $local, $domain ) = explode( '@', $key, 2 );

		return substr( $local, 0, 1 ) . '***@' . $domain;
	}

	return '****' . substr( $key, -4 );
}

/**
 * Nome do titular da chave, usado pelo vendor para conferir o destino antes de pagar.
 *
 * @param string $raw Nome digitado.
 * @return string|WP_Error
 */
function papelito_refund_pix_normalize_holder( string $raw ) {
	$holder = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $holder ) : strlen( $holder );

	if ( $length < 3 || $length > 120 ) {
		return new WP_Error( 'papelito_refund_pix_holder_invalid', 'Informe o nome do titular da chave PIX.', array( 'status' => 422 ) );
	}

	return $holder;
}

/**
 * Agora, em UTC, no formato das colunas DATETIME.
 */
function papelito_order_refund_now(): string {
	return gmdate( PAPELITO_ORDER_REFUND_MYSQL_FORMAT );
}

/**
 * Erro REST do dominio de estorno.
 *
 * @param string $code    Código do erro.
 * @param string $message Mensagem do erro.
 * @param int    $status  Status HTTP.
 */
function papelito_order_refund_error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

/**
 * Estorno do pedido, opcionalmente travado para escrita.
 *
 * @param int  $order_id   Id do pedido.
 * @param bool $for_update Trava a linha para escrita.
 * @return array<string,mixed>|null
 */
function papelito_order_refund_get_by_order( int $order_id, bool $for_update = false ): ?array {
	global $wpdb;

	$table = papelito_order_refund_tables()['refunds'];
	$sql   = "SELECT * FROM {$table} WHERE order_id = %d" . ( $for_update ? ' FOR UPDATE' : '' );
	$row   = $wpdb->get_row( $wpdb->prepare( $sql, $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

	return is_array( $row ) ? $row : null;
}

/**
 * Estorno pelo id, opcionalmente travado para escrita.
 *
 * @param int  $refund_id  Id do estorno.
 * @param bool $for_update Trava a linha para escrita.
 * @return array<string,mixed>|null
 */
function papelito_order_refund_get( int $refund_id, bool $for_update = false ): ?array {
	global $wpdb;

	$table = papelito_order_refund_tables()['refunds'];
	$sql   = "SELECT * FROM {$table} WHERE id = %d" . ( $for_update ? ' FOR UPDATE' : '' );
	$row   = $wpdb->get_row( $wpdb->prepare( $sql, $refund_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

	return is_array( $row ) ? $row : null;
}

/**
 * Registra um evento imutavel do estorno.
 *
 * @param int                 $refund_id Id do estorno.
 * @param int                 $order_id  Id do pedido.
 * @param string              $event     Nome do evento.
 * @param string|null         $from      Estado anterior.
 * @param string|null         $to        Estado novo.
 * @param int                 $actor     Usuário que executa a ação.
 * @param array<string,mixed> $payload   Dados do evento.
 */
function papelito_order_refund_event( int $refund_id, int $order_id, string $event, ?string $from, ?string $to, int $actor, array $payload = array() ): void {
	global $wpdb;

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_order_refund_tables()['events'],
		array(
			'refund_id'     => $refund_id,
			'order_id'      => $order_id,
			'event'         => $event,
			'from_status'   => $from,
			'to_status'     => $to,
			'actor_user_id' => $actor,
			'payload'       => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'created_at'    => papelito_order_refund_now(),
		)
	);
}

/**
 * Qual conjunto de documentos as telas de pedido apresentam.
 *
 * Pedido estornado fecha a área de documentos do fluxo normal — recibo do
 * pedido e nota fiscal saem de cena — e no lugar dela fica a documentação do
 * estorno. A decisão é do WordPress e não do navegador: vendor e comprador
 * leem o mesmo campo, e nenhuma das duas telas deduz isso de `status`.
 *
 * Fecha pelo estado do pedido, não pela existência do estorno: pedido
 * estornado antes desta regra, ou devolvido direto no painel da Pagar.me, não
 * tem linha em `papelito_order_refunds` e mesmo assim não pode voltar a
 * mostrar o recibo do pedido como se nada tivesse acontecido.
 *
 * Fechar a superfície **não apaga nem bloqueia nada**: recibo, nota fiscal e
 * arquivos continuam onde estavam, alcançáveis por rota autenticada, porque o
 * recibo é registro contábil imutável e a nota é documento fiscal do vendor.
 *
 * @param object                   $order  Pedido WooCommerce.
 * @param array<string,mixed>|null $refund Estorno do pedido, quando já lido.
 */
function papelito_order_documents_surface( object $order, ?array $refund = null ): string {
	if ( is_array( $refund ) && PAPELITO_ORDER_REFUND_STATUS_REFUNDED === (string) ( $refund['status'] ?? '' ) ) {
		return PAPELITO_ORDER_DOCUMENTS_SURFACE_REFUND;
	}

	$status = function_exists( 'papelito_vendor_dashboard_order_status' )
		? papelito_vendor_dashboard_order_status( $order )
		: sanitize_key( (string) $order->get_meta( '_papelito_vendor_status', true ) );

	if ( PAPELITO_VENDOR_STATUS_REFUNDED === $status ) {
		return PAPELITO_ORDER_DOCUMENTS_SURFACE_REFUND;
	}

	return method_exists( $order, 'get_status' ) && 'refunded' === sanitize_key( (string) $order->get_status() )
		? PAPELITO_ORDER_DOCUMENTS_SURFACE_REFUND
		: PAPELITO_ORDER_DOCUMENTS_SURFACE_ORDER;
}

/**
 * O pedido ja teve pagamento confirmado, mesmo que a cobranca tenha sido devolvida depois.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_order_refund_order_was_paid( object $order ): bool {
	if ( function_exists( 'papelito_pagarme_order_has_paid_status' ) && papelito_pagarme_order_has_paid_status( $order ) ) {
		return true;
	}

	if ( method_exists( $order, 'get_status' ) && 'refunded' === sanitize_key( (string) $order->get_status() ) ) {
		return true;
	}

	return method_exists( $order, 'get_id' ) && is_array( papelito_order_refund_get_by_order( (int) $order->get_id() ) );
}

/**
 * Valor integral do pedido, em centavos: produtos e frete.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_order_refund_order_amount_cents( object $order ): int {
	$total = method_exists( $order, 'get_total' ) ? $order->get_total() : 0;

	return function_exists( 'papelito_pricing_to_cents' )
		? papelito_pricing_to_cents( $total )
		: max( 0, (int) round( (float) $total * 100 ) );
}

/**
 * Momento do pagamento, quando o WooCommerce o registrou.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_order_refund_order_paid_at( object $order ): ?int {
	$date = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;

	return is_object( $date ) && method_exists( $date, 'getTimestamp' ) ? (int) $date->getTimestamp() : null;
}

/**
 * Conta suspensa pode cancelar pedido feito antes da suspensao.
 *
 * @param object $order     Pedido WooCommerce.
 * @param int    $vendor_id Vendor do pedido.
 * @return true|WP_Error
 */
function papelito_order_refund_vendor_cancel_guard( object $order, int $vendor_id ) {
	if ( ! function_exists( 'papelito_account_is_suspended' ) || ! papelito_account_is_suspended( $vendor_id ) ) {
		return true;
	}

	$details = function_exists( 'papelito_account_suspension_details' ) ? papelito_account_suspension_details( $vendor_id ) : null;
	$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
	$created = is_object( $created ) && method_exists( $created, 'getTimestamp' ) ? (int) $created->getTimestamp() : 0;

	if ( papelito_order_refund_suspension_allows_cancel( (string) ( $details['at'] ?? '' ), $created ) ) {
		return true;
	}

	return papelito_order_refund_error( 'papelito_vendor_suspended_cancel_forbidden', 'Com a conta suspensa, só é possível cancelar pedidos feitos antes da suspensão.', 403 );
}

/**
 * Insere a linha do estorno numa transacao propria. A trava de duplicidade e a UNIQUE do pedido.
 *
 * @param object $order     Pedido WooCommerce.
 * @param int    $vendor_id Vendor do pedido.
 * @param string $mode      Caminho do estorno.
 * @param string $reason    Justificativa do cancelamento.
 * @param int    $actor_id  Usuário que executa a ação.
 * @param string $source    Origem do estorno: vendor_cancel ou external.
 * @return int|WP_Error Id do estorno.
 */
function papelito_order_refund_insert( object $order, int $vendor_id, string $mode, string $reason, int $actor_id, string $source ) {
	global $wpdb;

	$order_id  = (int) $order->get_id();
	$amount    = papelito_order_refund_order_amount_cents( $order );
	$charge_id = sanitize_text_field( (string) $order->get_meta( '_papelito_pagarme_charge_id', true ) );
	$status    = PAPELITO_ORDER_REFUND_MODE_API === $mode ? PAPELITO_ORDER_REFUND_STATUS_PROCESSING : PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING;
	$now       = papelito_order_refund_now();

	if ( $amount <= 0 ) {
		return papelito_order_refund_error( 'papelito_order_refund_amount_invalid', 'Não há valor a estornar neste pedido.', 409 );
	}

	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( is_array( papelito_order_refund_get_by_order( $order_id, true ) ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return papelito_order_refund_error( 'papelito_order_refund_duplicate', 'Este pedido já tem um estorno em andamento.', 409 );
	}

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_order_refund_tables()['refunds'],
		array(
			'order_id'               => $order_id,
			'vendor_id'              => $vendor_id,
			'customer_id'            => (int) $order->get_customer_id(),
			'mode'                   => $mode,
			'status'                 => $status,
			'payment_method'         => sanitize_key( (string) $order->get_meta( '_papelito_pagarme_payment_method', true ) ),
			'pagarme_charge_id'      => $charge_id,
			'pagarme_order_id'       => sanitize_text_field( (string) $order->get_meta( '_papelito_pagarme_order_id', true ) ),
			'amount_cents'           => $amount,
			'idempotency_key'        => papelito_order_refund_idempotency_key( $order_id, $charge_id ),
			'reason'                 => $reason,
			'source'                 => $source,
			'requested_by_user_id'   => $actor_id,
			'performed_by_vendor_id' => $vendor_id,
			'refund_due_at'          => PAPELITO_ORDER_REFUND_MODE_MANUAL === $mode ? papelito_order_refund_due_at( time() ) : null,
			'requested_at'           => $now,
			'version'                => 1,
			'created_at'             => $now,
			'updated_at'             => $now,
		)
	);

	if ( false === $inserted ) {
		$duplicate = false !== stripos( (string) $wpdb->last_error, 'duplicate' );
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $duplicate
			? papelito_order_refund_error( 'papelito_order_refund_duplicate', 'Este pedido já tem um estorno em andamento.', 409 )
			: papelito_order_refund_error( 'papelito_order_refund_not_saved', 'Não foi possível registrar o estorno.', 500 );
	}

	$refund_id = (int) $wpdb->insert_id;
	papelito_order_refund_event(
		$refund_id,
		$order_id,
		'refund_requested',
		null,
		$status,
		$actor_id,
		array(
			'mode'         => $mode,
			'amount_cents' => $amount,
			'source'       => $source,
		)
	);
	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return $refund_id;
}

/**
 * Caminho do estorno de um pedido pago, a partir do meio e da data de pagamento gravados. Sem id
 * de cobranca nao ha o que devolver pela API.
 *
 * @param object $order Pedido WooCommerce.
 */
function papelito_order_refund_resolve_mode( object $order ): string {
	$method = sanitize_key( (string) $order->get_meta( '_papelito_pagarme_payment_method', true ) );
	$mode   = papelito_order_refund_mode( $method, true, papelito_order_refund_order_paid_at( $order ), time() );

	if ( PAPELITO_ORDER_REFUND_MODE_API === $mode && '' === sanitize_text_field( (string) $order->get_meta( '_papelito_pagarme_charge_id', true ) ) ) {
		return PAPELITO_ORDER_REFUND_MODE_MANUAL;
	}

	return $mode;
}

/**
 * O que acontece com o dinheiro se o pedido for cancelado agora, para o vendor saber antes de
 * confirmar. So informa: o cancelamento decide de novo.
 *
 * @param object $order Pedido WooCommerce.
 * @return array{mode:string,amountCents:int,manualDueDays:int}|null
 */
function papelito_order_refund_preview( object $order ): ?array {
	if ( ! function_exists( 'papelito_vendor_dashboard_order_is_paid' ) || ! papelito_vendor_dashboard_order_is_paid( $order ) ) {
		return null;
	}

	if ( is_array( papelito_order_refund_get_by_order( (int) $order->get_id() ) ) ) {
		return null;
	}

	return array(
		'mode'          => papelito_order_refund_resolve_mode( $order ),
		'amountCents'   => papelito_order_refund_order_amount_cents( $order ),
		'manualDueDays' => PAPELITO_ORDER_REFUND_MANUAL_DUE_DAYS,
	);
}

/**
 * Cancelamento de pedido pago: abre o estorno e, se for pela API, ja tenta devolver.
 *
 * @param object $order     Pedido WooCommerce ja validado como do vendor.
 * @param int    $vendor_id Vendor do pedido.
 * @param string $reason    Justificativa do cancelamento.
 * @param int    $actor_id  Usuário que executa a ação.
 * @return array<string,mixed>|WP_Error Linha do estorno.
 */
function papelito_order_refund_request( object $order, int $vendor_id, string $reason, int $actor_id ) {
	$mode      = papelito_order_refund_resolve_mode( $order );
	$refund_id = papelito_order_refund_insert( $order, $vendor_id, $mode, $reason, $actor_id, 'vendor_cancel' );

	if ( is_wp_error( $refund_id ) ) {
		return $refund_id;
	}

	$order->update_meta_data( '_papelito_vendor_status', PAPELITO_VENDOR_STATUS_CANCEL_REQUESTED );
	$order->update_meta_data( '_papelito_vendor_status_source', 'vendor_action' );
	$order->update_meta_data( '_papelito_vendor_cancel_reason', $reason );
	$order->add_order_note(
		sprintf(
			'Pedido pago cancelado pelo usuário #%d. Estorno %s aberto. Justificativa: %s',
			$actor_id,
			PAPELITO_ORDER_REFUND_MODE_API === $mode ? 'automático' : 'manual',
			$reason
		)
	);
	$order->save();

	$row = papelito_order_refund_get( $refund_id );

	if ( is_array( $row ) ) {
		papelito_order_refund_notify_customer( $order, $row, 'pending' );
	}

	if ( PAPELITO_ORDER_REFUND_MODE_API === $mode ) {
		$attempted = papelito_order_refund_attempt_api( $refund_id, $actor_id );

		if ( is_array( $attempted ) ) {
			return $attempted;
		}
	}

	return is_array( $row ) ? $row : papelito_order_refund_error( 'papelito_order_refund_not_found', PAPELITO_ORDER_REFUND_MSG_NOT_FOUND, 404 );
}

/**
 * Tenta devolver pela Pagar.me. Reentrante: o claim por versao impede duas tentativas simultaneas,
 * a leitura previa da cobranca evita devolver o que ja voltou e a chave de idempotencia cobre a
 * janela de 24h da API.
 *
 * @param int $refund_id Id do estorno.
 * @param int $actor_id  Usuário que executa a ação.
 * @return array<string,mixed>|WP_Error Linha do estorno depois da tentativa.
 */
function papelito_order_refund_attempt_api( int $refund_id, int $actor_id = 0 ) {
	global $wpdb;

	$row = papelito_order_refund_get( $refund_id );

	if ( ! is_array( $row ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_not_found', PAPELITO_ORDER_REFUND_MSG_NOT_FOUND, 404 );
	}

	if ( PAPELITO_ORDER_REFUND_MODE_API !== (string) $row['mode'] || ! in_array( (string) $row['status'], array( PAPELITO_ORDER_REFUND_STATUS_PROCESSING, PAPELITO_ORDER_REFUND_STATUS_FAILED ), true ) ) {
		return $row;
	}

	$table   = papelito_order_refund_tables()['refunds'];
	$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"UPDATE {$table} SET attempts = attempts + 1, version = version + 1, next_attempt_at = NULL, updated_at = %s WHERE id = %d AND version = %d AND status IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			papelito_order_refund_now(),
			$refund_id,
			(int) $row['version'],
			PAPELITO_ORDER_REFUND_STATUS_PROCESSING,
			PAPELITO_ORDER_REFUND_STATUS_FAILED
		)
	);

	if ( 1 !== (int) $claimed ) {
		$current = papelito_order_refund_get( $refund_id );
		return is_array( $current ) ? $current : $row;
	}

	$order_id = (int) $row['order_id'];
	$attempts = (int) $row['attempts'] + 1;
	$amount   = (int) $row['amount_cents'];
	$charge   = 'charges/' . rawurlencode( (string) $row['pagarme_charge_id'] );
	$precheck = papelito_pagarme_request( 'GET', $charge );

	if ( is_array( $precheck ) && papelito_order_refund_charge_is_refunded( $precheck, $amount ) ) {
		return papelito_order_refund_complete( $refund_id, 'api_precheck', $actor_id, array( 'charge_status' => (string) ( $precheck['status'] ?? '' ) ) );
	}

	$response = papelito_pagarme_request( 'DELETE', $charge, null, array( 'idempotency_key' => (string) $row['idempotency_key'] ) );

	if ( is_array( $response ) && papelito_order_refund_charge_is_refunded( $response, $amount ) ) {
		return papelito_order_refund_complete( $refund_id, 'api', $actor_id, array( 'charge_status' => (string) ( $response['status'] ?? '' ) ) );
	}

	$now = time();

	if ( is_array( $response ) ) {
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'status'          => PAPELITO_ORDER_REFUND_STATUS_PROCESSING,
				'last_error'      => null,
				'next_attempt_at' => gmdate( PAPELITO_ORDER_REFUND_MYSQL_FORMAT, $now + papelito_order_refund_retry_delay( $attempts ) ),
				'updated_at'      => papelito_order_refund_now(),
			),
			array( 'id' => $refund_id )
		);
		papelito_order_refund_event(
			$refund_id,
			$order_id,
			'refund_api_accepted',
			(string) $row['status'],
			PAPELITO_ORDER_REFUND_STATUS_PROCESSING,
			$actor_id,
			array(
				'charge_status' => (string) ( $response['status'] ?? '' ),
				'attempt'       => $attempts,
			)
		);

		return papelito_order_refund_get( $refund_id ) ?? $row;
	}

	$message = substr( sanitize_text_field( $response->get_error_message() ), 0, 500 );

	if ( papelito_order_refund_api_exhausted( $attempts ) ) {
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'mode'            => PAPELITO_ORDER_REFUND_MODE_MANUAL,
				'status'          => PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING,
				'last_error'      => $message,
				'next_attempt_at' => null,
				'refund_due_at'   => papelito_order_refund_due_at( $now ),
				'updated_at'      => papelito_order_refund_now(),
			),
			array( 'id' => $refund_id )
		);
		papelito_order_refund_event(
			$refund_id,
			$order_id,
			'refund_api_exhausted',
			(string) $row['status'],
			PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING,
			$actor_id,
			array(
				'error'   => $message,
				'attempt' => $attempts,
			)
		);

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$fresh = papelito_order_refund_get( $refund_id );

		if ( is_object( $order ) && is_array( $fresh ) ) {
			$order->add_order_note( sprintf( 'A Pagar.me não aceitou o estorno após %d tentativas. O vendor precisa devolver o valor manualmente.', $attempts ) );
			$order->save();
			papelito_order_refund_notify_customer( $order, $fresh, 'pending' );
		}

		return $fresh ?? $row;
	}

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table,
		array(
			'status'          => PAPELITO_ORDER_REFUND_STATUS_FAILED,
			'last_error'      => $message,
			'next_attempt_at' => gmdate( PAPELITO_ORDER_REFUND_MYSQL_FORMAT, $now + papelito_order_refund_retry_delay( $attempts ) ),
			'updated_at'      => papelito_order_refund_now(),
		),
		array( 'id' => $refund_id )
	);
	papelito_order_refund_event(
		$refund_id,
		$order_id,
		'refund_api_failed',
		(string) $row['status'],
		PAPELITO_ORDER_REFUND_STATUS_FAILED,
		$actor_id,
		array(
			'error'   => $message,
			'attempt' => $attempts,
		)
	);

	return papelito_order_refund_get( $refund_id ) ?? $row;
}

/**
 * Aloca o sequencial anual do recibo de estorno. Roda dentro da transacao do chamador.
 *
 * @param int $year Ano da numeração.
 */
function papelito_order_refund_claim_receipt_sequence( int $year ): int {
	global $wpdb;

	$table = papelito_order_refund_tables()['sequences'];
	$read  = "SELECT next_sequence FROM {$table} WHERE sequence_year = %d FOR UPDATE";
	$row   = $wpdb->get_row( $wpdb->prepare( $read, $year ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

	if ( ! is_array( $row ) ) {
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (sequence_year, next_sequence) VALUES (%d, 1)", $year ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( $read, $year ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	if ( ! is_array( $row ) ) {
		return 0;
	}

	$sequence = max( 1, (int) $row['next_sequence'] );
	$updated  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_sequence = next_sequence + 1, updated_at = CURRENT_TIMESTAMP WHERE sequence_year = %d", $year ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

	return false === $updated ? 0 : $sequence;
}

/**
 * Conclui o estorno: grava o recibo de estorno e, fora da transacao, projeta no pedido.
 * Idempotente — concluir de novo devolve a mesma linha.
 *
 * @param int                 $refund_id Id do estorno.
 * @param string              $source    Origem da conclusão.
 * @param int                 $actor_id  Usuário que executa a ação.
 * @param array<string,mixed> $payload   Dados do evento.
 * @param array<string,mixed> $manual    proof_id, refunded_at, reference, notes, recorded_by (caminho manual).
 * @return array<string,mixed>|WP_Error
 */
function papelito_order_refund_complete( int $refund_id, string $source, int $actor_id, array $payload = array(), array $manual = array() ) {
	global $wpdb;

	$tables = papelito_order_refund_tables();
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$row = papelito_order_refund_get( $refund_id, true );

	if ( ! is_array( $row ) ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return papelito_order_refund_error( 'papelito_order_refund_not_found', PAPELITO_ORDER_REFUND_MSG_NOT_FOUND, 404 );
	}

	if ( PAPELITO_ORDER_REFUND_STATUS_REFUNDED === (string) $row['status'] ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_order_refund_apply_to_order( $row, $source );
		return $row;
	}

	$proof_id = absint( $manual['proof_id'] ?? 0 );

	if ( $proof_id > 0 ) {
		$proof = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['proofs']} WHERE id = %d FOR UPDATE", $proof_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		if ( ! is_array( $proof ) || (int) $proof['refund_id'] !== $refund_id || (int) $proof['vendor_id'] !== (int) $row['vendor_id'] || ! empty( $proof['attached_at'] ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return papelito_order_refund_error( 'papelito_order_refund_proof_invalid', 'O comprovante não pertence a este estorno ou já foi utilizado.', 422 );
		}
	}

	$year     = (int) gmdate( 'Y' );
	$sequence = papelito_order_refund_claim_receipt_sequence( $year );

	if ( $sequence <= 0 ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return papelito_order_refund_error( 'papelito_order_refund_receipt_sequence', 'Não foi possível numerar o recibo de estorno.', 500 );
	}

	$now     = papelito_order_refund_now();
	$updates = array(
		'status'           => PAPELITO_ORDER_REFUND_STATUS_REFUNDED,
		'settled_at'       => (string) ( $manual['refunded_at'] ?? $now ),
		'receipt_year'     => $year,
		'receipt_sequence' => $sequence,
		'receipt_number'   => papelito_order_refund_format_receipt_number( $year, $sequence ),
		'last_error'       => null,
		'next_attempt_at'  => null,
		'version'          => (int) $row['version'] + 1,
		'updated_at'       => $now,
	);

	if ( $proof_id > 0 ) {
		$updates['proof_id']            = $proof_id;
		$updates['recorded_by_user_id'] = absint( $manual['recorded_by'] ?? $actor_id );
		$updates['manual_reference']    = (string) ( $manual['reference'] ?? '' );
		$updates['manual_notes']        = (string) ( $manual['notes'] ?? '' );
	}

	$updated = $wpdb->update(
		$tables['refunds'],
		$updates,
		array(
			'id'      => $refund_id,
			'version' => (int) $row['version'],
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( 1 !== $updated ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return papelito_order_refund_error( 'papelito_order_refund_conflict', 'O estorno foi alterado por outra operação. Atualize a página.', 409 );
	}

	if ( $proof_id > 0 ) {
		$attached = $wpdb->update(
			$tables['proofs'],
			array( 'attached_at' => $now ),
			array(
				'id'          => $proof_id,
				'attached_at' => null,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( 1 !== $attached ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return papelito_order_refund_error( 'papelito_order_refund_proof_conflict', 'O comprovante foi alterado durante o registro.', 409 );
		}
	}

	papelito_order_refund_event(
		$refund_id,
		(int) $row['order_id'],
		'refund_completed',
		(string) $row['status'],
		PAPELITO_ORDER_REFUND_STATUS_REFUNDED,
		$actor_id,
		array_merge(
			$payload,
			array(
				'source'         => $source,
				'receipt_number' => $updates['receipt_number'],
				'proof_id'       => $proof_id,
			)
		)
	);
	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$completed = papelito_order_refund_get( $refund_id );

	if ( is_array( $completed ) ) {
		papelito_order_refund_apply_to_order( $completed, $source );
	}

	return $completed ?? $row;
}

/**
 * Projeta o estorno concluido no pedido: `estornado`, reembolso no WooCommerce e aviso ao comprador.
 *
 * @param array<string,mixed> $row    Estorno concluido.
 * @param string              $source Origem da conclusão.
 * @return bool Se todas as projeções necessárias foram concluídas.
 */
function papelito_order_refund_apply_to_order( array $row, string $source ): bool {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['order_id'] ) : null;

	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
		return false;
	}

	$refund_id       = (int) $row['id'];
	$receipt_number  = (string) $row['receipt_number'];
	$state_marker    = '_papelito_refund_projection_state_' . $refund_id;
	$notice_marker   = '_papelito_refund_projection_notice_' . $refund_id;
	$complete_marker = '_papelito_refund_projection_complete_' . $refund_id;

	if ( $receipt_number === (string) $order->get_meta( $complete_marker, true ) ) {
		return true;
	}

	$money = function_exists( 'papelito_receipt_money_cents' )
		? papelito_receipt_money_cents( (int) $row['amount_cents'] )
		: number_format( (int) $row['amount_cents'] / 100, 2, ',', '.' );

	if ( $receipt_number !== (string) $order->get_meta( $state_marker, true ) ) {
		$order->update_meta_data( '_papelito_vendor_status', PAPELITO_VENDOR_STATUS_REFUNDED );
		$order->update_meta_data( '_papelito_vendor_status_source', 'refund_completed' );
		$order->update_meta_data( $state_marker, $receipt_number );
		$order->add_order_note(
			sprintf(
				'Estorno concluído (%s): %s devolvidos ao comprador. Recibo de estorno %s.',
				in_array( $source, array( 'manual', 'admin_manual' ), true ) ? 'registro manual' : 'Pagar.me',
				$money,
				$receipt_number
			)
		);
		$order->save();
	}

	if ( ! papelito_order_refund_record_wc_refund( $order, $row ) ) {
		return false;
	}

	if ( $receipt_number !== (string) $order->get_meta( $notice_marker, true ) ) {
		papelito_order_refund_notify_customer( $order, $row, 'refunded' );
		$order->update_meta_data( $notice_marker, $receipt_number );
		$order->save();
	}

	$order->update_meta_data( $complete_marker, $receipt_number );
	$order->save();
	do_action( 'papelito_order_refunded', $order, $row );

	return true;
}

/**
 * Reembolso escritural no WooCommerce, para relatorios e `get_total_refunded()` ficarem coerentes.
 * Sem restock e sem chamar gateway: o dinheiro ja voltou por outro caminho e o estoque nao retorna.
 *
 * @param object              $order Pedido WooCommerce.
 * @param array<string,mixed> $row   Estorno concluido.
 * @return bool Se o lançamento existe ou foi criado com sucesso.
 */
function papelito_order_refund_record_wc_refund( object $order, array $row ): bool {
	if ( ! function_exists( 'wc_create_refund' ) || ! method_exists( $order, 'get_total_refunded' ) ) {
		return false;
	}

	$marker = '_papelito_refund_projection_wc_' . (int) $row['id'];
	if ( (string) $row['receipt_number'] === (string) $order->get_meta( $marker, true ) ) {
		return true;
	}

	$remaining = round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 );

	if ( $remaining <= 0 ) {
		$order->update_meta_data( $marker, (string) $row['receipt_number'] );
		$order->save();
		return true;
	}

	$amount   = min( round( (int) $row['amount_cents'] / 100, 2 ), $remaining );
	$suppress = static fn(): bool => false;

	add_filter( 'woocommerce_email_enabled_customer_refunded_order', $suppress );

	$refund = wc_create_refund(
		array(
			'amount'         => number_format( $amount, 2, '.', '' ),
			'reason'         => 'Estorno Papelito ' . (string) $row['receipt_number'],
			'order_id'       => (int) $order->get_id(),
			'refund_payment' => false,
			'restock_items'  => false,
		)
	);

	remove_filter( 'woocommerce_email_enabled_customer_refunded_order', $suppress );

	if ( is_wp_error( $refund ) ) {
		$order->add_order_note( 'Estorno concluído, mas o reembolso não foi registrado no WooCommerce: ' . $refund->get_error_message() );
		$order->save();
		return false;
	}

	$order->update_meta_data( $marker, (string) $row['receipt_number'] );
	$order->save();

	return true;
}

/**
 * A Pagar.me reportou a cobranca de um pedido pago como cancelada. Conclui o estorno aberto ou
 * registra o que foi feito por fora (painel da Pagar.me) — nunca marca o pedido como falha.
 *
 * @param object $order Pedido WooCommerce.
 * @param string $state Estado da cobrança na Pagar.me.
 */
function papelito_order_refund_handle_psp_reversal( object $order, string $state ): void {
	$row = papelito_order_refund_get_by_order( (int) $order->get_id() );

	if ( is_array( $row ) ) {
		if ( PAPELITO_ORDER_REFUND_STATUS_REFUNDED !== (string) $row['status'] ) {
			papelito_order_refund_complete( (int) $row['id'], 'webhook', 0, array( 'charge_state' => $state ) );
		} else {
			papelito_order_refund_apply_to_order( $row, 'webhook' );
		}

		return;
	}

	$vendor_id = absint( $order->get_meta( '_papelito_vendor_id', true ) );
	$refund_id = papelito_order_refund_insert( $order, $vendor_id, PAPELITO_ORDER_REFUND_MODE_API, 'Estorno feito fora da Papelito, direto na Pagar.me.', 0, 'external' );

	if ( is_wp_error( $refund_id ) ) {
		$order->add_order_note( 'A Pagar.me reportou a cobrança como cancelada, mas o estorno não pôde ser registrado: ' . $refund_id->get_error_message() );
		$order->save();
		return;
	}

	papelito_order_refund_event( $refund_id, (int) $order->get_id(), 'external_refund_detected', null, PAPELITO_ORDER_REFUND_STATUS_PROCESSING, 0, array( 'charge_state' => $state ) );
	papelito_order_refund_complete( $refund_id, 'external', 0, array( 'charge_state' => $state ) );
}

/**
 * Deixa registrado no pedido o estorno parcial ou o chargeback que a Pagar.me reportou. Nao muda
 * o estado: estorno parcial nao e fluxo da Papelito, e chargeback segue o rito da operadora.
 *
 * @param object              $order  Pedido WooCommerce.
 * @param array<string,mixed> $charge Cobranca retornada pela API.
 */
function papelito_order_refund_detect_charge_anomaly( object $order, array $charge ): void {
	$kind = papelito_order_refund_charge_anomaly( $charge );

	if ( '' === $kind || ! method_exists( $order, 'get_meta' ) ) {
		return;
	}

	$canceled    = (int) ( $charge['canceled_amount'] ?? 0 );
	$meta_key    = '_papelito_pagarme_anomaly_' . $kind;
	$fingerprint = $kind . ':' . $canceled;

	if ( $fingerprint === (string) $order->get_meta( $meta_key, true ) ) {
		return;
	}

	$money = function_exists( 'papelito_receipt_money_cents' ) ? papelito_receipt_money_cents( $canceled ) : (string) $canceled;

	$order->update_meta_data( $meta_key, $fingerprint );
	$order->add_order_note(
		'chargeback' === $kind
			? 'A Pagar.me reportou chargeback nesta cobrança.'
			: sprintf( 'A Pagar.me reportou estorno parcial de %s nesta cobrança, feito fora da Papelito. O pedido não foi alterado.', $money )
	);
	$order->save();

	do_action( 'papelito_order_payment_anomaly', $order, $kind, $charge );
}

/**
 * Registro do estorno manual pelo vendor, ou pela Papelito como excecao.
 *
 * @param int                 $order_id         Id do pedido.
 * @param array<string,mixed> $input            proofId, refundedAt, reference, notes.
 * @param int                 $actor_id         Usuário que executa a ação.
 * @param bool                $is_admin         O registro é exceção da Papelito.
 * @param string              $exception_reason Justificativa da exceção.
 * @return array<string,mixed>|WP_Error
 */
function papelito_order_refund_register_manual( int $order_id, array $input, int $actor_id, bool $is_admin, string $exception_reason = '' ) {
	$row = papelito_order_refund_get_by_order( $order_id );

	if ( ! is_array( $row ) || ( ! $is_admin && (int) $row['vendor_id'] !== $actor_id ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_not_found', PAPELITO_ORDER_REFUND_MSG_NOT_FOUND, 404 );
	}

	$exception_reason = sanitize_textarea_field( $exception_reason );

	if ( $is_admin && '' === $exception_reason ) {
		return papelito_order_refund_error( 'papelito_order_refund_exception_reason_required', 'O registro pela Papelito exige justificativa.', 422 );
	}

	if ( PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING !== (string) $row['status'] ) {
		return papelito_order_refund_error( 'papelito_order_refund_manual_unavailable', 'O estorno manual só pode ser registrado enquanto estiver pendente.', 409 );
	}

	$proof_id = absint( $input['proofId'] ?? 0 );

	if ( $proof_id <= 0 ) {
		return papelito_order_refund_error( 'papelito_order_refund_proof_required', 'Anexe o comprovante da transferência.', 422 );
	}

	$raw_date = sanitize_text_field( (string) ( $input['refundedAt'] ?? '' ) );

	try {
		$refunded_at = ( new DateTimeImmutable( '' !== $raw_date ? $raw_date : 'now', wp_timezone() ) )
			->setTimezone( new DateTimeZone( 'UTC' ) )
			->format( PAPELITO_ORDER_REFUND_MYSQL_FORMAT );
	} catch ( Exception $exception ) {
		return papelito_order_refund_error( 'papelito_order_refund_date_invalid', 'Informe uma data de estorno válida.', 422 );
	}

	if ( strtotime( $refunded_at . ' UTC' ) > time() + HOUR_IN_SECONDS ) {
		return papelito_order_refund_error( 'papelito_order_refund_date_future', 'A data do estorno não pode estar no futuro.', 422 );
	}

	return papelito_order_refund_complete(
		(int) $row['id'],
		$is_admin ? 'admin_manual' : 'manual',
		$actor_id,
		array( 'admin_exception_reason' => $is_admin ? $exception_reason : null ),
		array(
			'proof_id'    => $proof_id,
			'refunded_at' => $refunded_at,
			'reference'   => substr( sanitize_text_field( (string) ( $input['reference'] ?? '' ) ), 0, 191 ),
			'notes'       => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
			'recorded_by' => $actor_id,
		)
	);
}

/**
 * Regras do arquivo de comprovante do estorno manual.
 *
 * @return array<string,mixed>
 */
function papelito_order_refund_proof_spec(): array {
	return array(
		'code_prefix'       => 'papelito_order_refund_proof',
		'formats'           => array( 'pdf', 'jpg', 'png' ),
		'max_bytes'         => 10 * MB_IN_BYTES,
		'fallback_basename' => 'comprovante-estorno',
	);
}

/**
 * Diretorio privado dos comprovantes.
 */
function papelito_order_refund_proofs_dir(): string {
	return papelito_private_files_dir( 'PAPELITO_PRIVATE_ORDER_REFUND_PROOFS_DIR', 'order-refund-proofs' );
}

/**
 * Tiquete de upload do comprovante, emitido so para o vendor do pedido com estorno manual pendente.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @param int             $user_id Vendor que pede o tíquete.
 * @return WP_REST_Response|WP_Error
 */
function papelito_order_refund_proof_ticket( WP_REST_Request $request, int $user_id ) {
	if ( $user_id <= 0 ) {
		return papelito_order_refund_error( 'papelito_upload_not_authenticated', 'Autenticação necessária.', 401 );
	}

	$order = papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'orderId' ) ), $user_id );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$row = papelito_order_refund_get_by_order( (int) $order->get_id() );

	if ( ! is_array( $row ) || PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING !== (string) $row['status'] ) {
		return papelito_order_refund_error( 'papelito_order_refund_proof_unavailable', 'O comprovante só pode ser anexado enquanto o estorno manual estiver pendente.', 409 );
	}

	return new WP_REST_Response(
		papelito_direct_upload_ticket_create(
			'order-refund-proof',
			array(
				'vendor_id' => $user_id,
				'order_id'  => (int) $order->get_id(),
			)
		),
		201
	);
}

/**
 * Armazena um comprovante privado ainda nao vinculado ao estorno.
 *
 * @param int                 $order_id  Id do pedido.
 * @param int                 $vendor_id Vendor do pedido.
 * @param array<string,mixed> $file      Arquivo recebido.
 * @param int                 $actor     Usuário que executa a ação.
 * @return array<string,mixed>|WP_Error
 */
function papelito_order_refund_proof_attach_file( int $order_id, int $vendor_id, array $file, int $actor ) {
	global $wpdb;

	$row = papelito_order_refund_get_by_order( $order_id );

	if ( ! is_array( $row ) || $vendor_id <= 0 || (int) $row['vendor_id'] !== $vendor_id ) {
		return papelito_order_refund_error( 'papelito_order_refund_not_found', PAPELITO_ORDER_REFUND_MSG_NOT_FOUND, 404 );
	}

	if ( PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING !== (string) $row['status'] ) {
		return papelito_order_refund_error( 'papelito_order_refund_proof_unavailable', 'O comprovante só pode ser anexado enquanto o estorno manual estiver pendente.', 409 );
	}

	$tables  = papelito_order_refund_tables();
	$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['proofs']} WHERE refund_id = %d AND attached_at IS NULL", (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

	if ( $pending >= PAPELITO_ORDER_REFUND_MAX_PENDING_PROOFS ) {
		return papelito_order_refund_error( 'papelito_order_refund_proof_limit', 'Use um comprovante já enviado antes de anexar outro.', 409 );
	}

	$validated = papelito_private_file_validate_upload( $file, papelito_order_refund_proof_spec() );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	$directory = papelito_private_files_prepare_dir( papelito_order_refund_proofs_dir(), 'papelito_order_refund_proof' );
	if ( is_wp_error( $directory ) ) {
		return $directory;
	}

	$stored = papelito_private_file_store( $file, $validated, $directory, 'papelito_order_refund_proof' );
	if ( is_wp_error( $stored ) ) {
		return $stored;
	}

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables['proofs'],
		array(
			'refund_id'     => (int) $row['id'],
			'order_id'      => $order_id,
			'vendor_id'     => $vendor_id,
			'storage_key'   => $stored['key'],
			'original_name' => $validated['original_name'],
			'mime'          => $validated['mime'],
			'size_bytes'    => $validated['size'],
			'sha256'        => $validated['sha256'],
			'uploaded_by'   => $actor,
			'created_at'    => papelito_order_refund_now(),
		)
	);

	if ( false === $inserted ) {
		papelito_private_file_discard_path( $stored['path'] );
		return papelito_order_refund_error( 'papelito_order_refund_proof_not_saved', 'Não foi possível registrar o comprovante.', 500 );
	}

	$proof_id = absint( $wpdb->insert_id );
	papelito_order_refund_event( (int) $row['id'], $order_id, 'refund_proof_uploaded', null, null, $actor, array( 'proof_id' => $proof_id ) );

	return array(
		'id'           => $proof_id,
		'originalName' => $validated['original_name'],
		'mime'         => $validated['mime'],
		'sizeBytes'    => $validated['size'],
	);
}

/**
 * Linha da chave PIX de reembolso do usuario.
 *
 * @param int $user_id Usuário dono da chave PIX.
 * @return array<string,mixed>|null
 */
function papelito_refund_pix_key_row( int $user_id ): ?array {
	global $wpdb;

	$table = papelito_order_refund_tables()['pix_keys'];
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

	return is_array( $row ) ? $row : null;
}

/**
 * O usuario tem chave PIX de reembolso cadastrada.
 *
 * @param int $user_id Usuário dono da chave PIX.
 */
function papelito_refund_pix_key_exists( int $user_id ): bool {
	return $user_id > 0 && is_array( papelito_refund_pix_key_row( $user_id ) );
}

/**
 * Chave PIX sem a parte sensivel, para o proprio dono.
 *
 * @param int $user_id Usuário dono da chave PIX.
 * @return array{type:string,hint:string,holderName:string,updatedAt:string}|null
 */
function papelito_refund_pix_key_public( int $user_id ): ?array {
	$row = papelito_refund_pix_key_row( $user_id );

	if ( ! is_array( $row ) ) {
		return null;
	}

	return array(
		'type'       => (string) $row['key_type'],
		'hint'       => (string) $row['key_hint'],
		'holderName' => (string) $row['holder_name'],
		'updatedAt'  => (string) $row['updated_at'],
	);
}

/**
 * Cadastra ou troca a chave PIX de reembolso, cifrada em repouso.
 *
 * @param int    $user_id    Usuário dono da chave PIX.
 * @param string $type       Tipo da chave PIX.
 * @param string $raw_key    Chave PIX digitada.
 * @param string $raw_holder Nome do titular digitado.
 * @return array<string,string>|WP_Error
 */
function papelito_refund_pix_key_save( int $user_id, string $type, string $raw_key, string $raw_holder ) {
	global $wpdb;

	if ( $user_id <= 0 ) {
		return papelito_order_refund_error( 'papelito_refund_pix_auth_required', 'Autenticação necessária.', 401 );
	}

	$key = papelito_refund_pix_normalize_key( $type, $raw_key );
	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$holder = papelito_refund_pix_normalize_holder( $raw_holder );
	if ( is_wp_error( $holder ) ) {
		return $holder;
	}

	if ( ! function_exists( 'papelito_pii_encrypt' ) ) {
		return papelito_order_refund_error( 'papelito_refund_pix_crypto_unavailable', 'Não foi possível proteger a chave PIX agora.', 500 );
	}

	$cipher = papelito_pii_encrypt( $key );
	if ( is_wp_error( $cipher ) ) {
		return papelito_order_refund_error( 'papelito_refund_pix_crypto_unavailable', 'Não foi possível proteger a chave PIX agora.', 500 );
	}

	$type  = sanitize_key( $type );
	$table = papelito_order_refund_tables()['pix_keys'];
	$now   = papelito_order_refund_now();
	$data  = array(
		'key_type'       => $type,
		'key_ciphertext' => $cipher,
		'key_hint'       => papelito_refund_pix_key_hint( $type, $key ),
		'holder_name'    => $holder,
		'updated_at'     => $now,
	);

	if ( is_array( papelito_refund_pix_key_row( $user_id ) ) ) {
		$saved = $wpdb->update( $table, $data, array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} else {
		$saved = $wpdb->insert(
			$table,
			array_merge(
				$data,
				array(
					'user_id'    => $user_id,
					'created_at' => $now,
				)
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	if ( false === $saved ) {
		return papelito_order_refund_error( 'papelito_refund_pix_not_saved', 'Não foi possível salvar a chave PIX.', 500 );
	}

	return papelito_refund_pix_key_public( $user_id ) ?? array();
}

/**
 * Remove a chave PIX de reembolso.
 *
 * @param int $user_id Usuário dono da chave PIX.
 */
function papelito_refund_pix_key_delete( int $user_id ): bool {
	global $wpdb;

	return false !== $wpdb->delete( papelito_order_refund_tables()['pix_keys'], array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Chave PIX em claro. So o endpoint de exibicao ao vendor chama, e ele registra o acesso.
 *
 * @param int $user_id Usuário dono da chave PIX.
 * @return array{type:string,key:string,holderName:string}|null|WP_Error
 */
function papelito_refund_pix_key_decrypt( int $user_id ) {
	$row = papelito_refund_pix_key_row( $user_id );

	if ( ! is_array( $row ) ) {
		return null;
	}

	$key = function_exists( 'papelito_pii_decrypt' ) ? papelito_pii_decrypt( (string) $row['key_ciphertext'] ) : null;

	if ( ! is_string( $key ) || '' === $key ) {
		return papelito_order_refund_error( 'papelito_refund_pix_decrypt_failed', 'Não foi possível ler a chave PIX do comprador.', 500 );
	}

	return array(
		'type'       => (string) $row['key_type'],
		'key'        => $key,
		'holderName' => (string) $row['holder_name'],
	);
}

/**
 * Estorno como a API expoe. O comprador nao ve tentativas nem o erro tecnico.
 *
 * @param array<string,mixed>|null $row      Linha do estorno.
 * @param string                   $audience customer, vendor ou admin.
 * @return array<string,mixed>|null
 */
function papelito_order_refund_payload( ?array $row, string $audience ): ?array {
	if ( ! is_array( $row ) ) {
		return null;
	}

	$status = (string) $row['status'];
	$due    = (string) ( $row['refund_due_at'] ?? '' );
	$due_ts = '' !== $due ? strtotime( $due . ' UTC' ) : false;

	$payload = array(
		'id'              => (int) $row['id'],
		'orderId'         => (int) $row['order_id'],
		'mode'            => (string) $row['mode'],
		'status'          => $status,
		'amountCents'     => (int) $row['amount_cents'],
		'paymentMethod'   => (string) $row['payment_method'],
		'requestedAt'     => (string) $row['requested_at'],
		'settledAt'       => (string) ( $row['settled_at'] ?? '' ),
		'refundDueAt'     => $due,
		'overdue'         => PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING === $status && false !== $due_ts && $due_ts <= time(),
		'receiptNumber'   => (string) ( $row['receipt_number'] ?? '' ),
		'proofId'         => absint( $row['proof_id'] ?? 0 ),
		'manualReference' => (string) ( $row['manual_reference'] ?? '' ),
	);

	if ( 'customer' !== $audience ) {
		$payload['attempts']          = (int) $row['attempts'];
		$payload['lastError']         = (string) ( $row['last_error'] ?? '' );
		$payload['customerHasPixKey'] = papelito_refund_pix_key_exists( (int) $row['customer_id'] );
	}

	if ( 'admin' === $audience ) {
		$payload['vendorId']   = (int) $row['vendor_id'];
		$payload['customerId'] = (int) $row['customer_id'];
		$payload['source']     = (string) $row['source'];
		$payload['reason']     = (string) ( $row['reason'] ?? '' );
	}

	return $payload;
}

/**
 * Estornos para a fila da Papelito.
 *
 * @param string $filter overdue, pending ou all.
 * @param int    $limit  Máximo de linhas.
 * @return array<int,array<string,mixed>>
 */
function papelito_order_refund_list( string $filter, int $limit = 100 ): array {
	global $wpdb;

	$table = papelito_order_refund_tables()['refunds'];
	$limit = max( 1, min( 200, $limit ) );

	if ( 'overdue' === $filter ) {
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND refund_due_at IS NOT NULL AND refund_due_at <= %s ORDER BY refund_due_at ASC LIMIT %d", PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING, papelito_order_refund_now(), $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} elseif ( 'pending' === $filter ) {
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN (%s, %s, %s) ORDER BY requested_at ASC LIMIT %d", PAPELITO_ORDER_REFUND_STATUS_PROCESSING, PAPELITO_ORDER_REFUND_STATUS_FAILED, PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY requested_at DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

	return is_array( $rows ) ? $rows : array();
}

/**
 * Avisa o comprador no painel e por e-mail. Chaves de dedupe por estorno e etapa tornam o aviso
 * seguro contra webhook repetido.
 *
 * @param object              $order Pedido WooCommerce.
 * @param array<string,mixed> $row   Estorno.
 * @param string              $kind  pending ou refunded.
 */
function papelito_order_refund_notify_customer( object $order, array $row, string $kind ): void {
	$customer_id = (int) $row['customer_id'];
	$type        = 'refunded' === $kind ? 'order_refunded' : 'order_refund_pending';
	$dedupe      = 'order_refund:' . (int) $row['id'] . ':' . $kind . ( 'pending' === $kind ? ':' . (string) $row['mode'] : '' );
	$payload     = array(
		'order_id'       => (int) $row['order_id'],
		'order_number'   => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $row['order_id'],
		'amount_cents'   => (int) $row['amount_cents'],
		'mode'           => (string) $row['mode'],
		'refund_due_at'  => (string) ( $row['refund_due_at'] ?? '' ),
		'receipt_number' => (string) ( $row['receipt_number'] ?? '' ),
	);

	if ( $customer_id > 0 && function_exists( 'papelito_dispatch_notification' ) ) {
		papelito_dispatch_notification( $customer_id, $type, $payload, $dedupe );
	}

	if ( 'external' !== (string) $row['source'] || 'refunded' === $kind ) {
		papelito_order_refund_send_customer_email( $order, $row, $kind, $type, $dedupe );
	}
}

/**
 * Conteudo do e-mail ao comprador.
 *
 * @param object              $order Pedido WooCommerce.
 * @param array<string,mixed> $row   Estorno.
 * @param string              $kind  Etapa: pending ou refunded.
 * @return array<string,mixed>
 */
function papelito_order_refund_email_view( object $order, array $row, string $kind ): array {
	$number    = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $row['order_id'];
	$money     = function_exists( 'papelito_receipt_money_cents' ) ? papelito_receipt_money_cents( (int) $row['amount_cents'] ) : (string) $row['amount_cents'];
	$is_manual = PAPELITO_ORDER_REFUND_MODE_MANUAL === (string) $row['mode'];
	$url       = function_exists( 'papelito_notification_frontend_link' ) ? (string) papelito_notification_frontend_link( sprintf( '/perfil/pedidos/%d', (int) $row['order_id'] ) ) : '';
	$contest   = 'Se o valor não chegar, abra um chamado no pedido e peça ajuda à Papelito.';
	$cta       = array(
		'label' => 'Acompanhar pedido',
		'url'   => $url,
	);

	if ( 'refunded' === $kind ) {
		return array(
			'subject'  => 'Estorno do seu pedido concluído - Papelito',
			'kicker'   => 'Estorno do pedido',
			'headline' => 'O estorno do seu pedido foi concluído.',
			'lead'     => $is_manual
				? sprintf( 'A loja registrou a devolução de %s referente ao pedido %s.', $money, $number )
				: sprintf( 'Devolvemos %s referentes ao pedido %s na forma de pagamento original.', $money, $number ),
			'facts'    => array(
				'Pedido'            => $number,
				'Valor devolvido'   => $money,
				'Recibo de estorno' => (string) $row['receipt_number'],
			),
			'cta'      => $cta,
			'notes'    => $is_manual ? array( $contest ) : array( 'O prazo para o valor aparecer depende do seu banco ou da operadora do cartão.' ),
		);
	}

	if ( $is_manual ) {
		$due_ts = strtotime( (string) $row['refund_due_at'] . ' UTC' );
		$due    = false !== $due_ts && function_exists( 'wp_date' ) ? (string) wp_date( 'd/m/Y', $due_ts ) : '';
		$notes  = array( $contest );

		if ( ! papelito_refund_pix_key_exists( (int) $row['customer_id'] ) ) {
			array_unshift( $notes, 'Cadastre uma chave PIX para reembolso no seu perfil para a loja saber para onde devolver.' );
		}

		return array(
			'subject'  => 'Seu pedido foi cancelado e o valor será devolvido - Papelito',
			'kicker'   => 'Cancelamento do pedido',
			'headline' => 'A loja vai devolver o valor do seu pedido.',
			'lead'     => sprintf( 'O pedido %s foi cancelado pela loja. A devolução de %s será feita por transferência.', $number, $money ),
			'facts'    => array(
				'Pedido'          => $number,
				'Valor a receber' => $money,
				'Prazo'           => $due,
			),
			'cta'      => $cta,
			'notes'    => $notes,
		);
	}

	return array(
		'subject'  => 'Seu pedido foi cancelado e o estorno está a caminho - Papelito',
		'kicker'   => 'Cancelamento do pedido',
		'headline' => 'O valor do seu pedido vai voltar para você.',
		'lead'     => sprintf( 'O pedido %s foi cancelado pela loja. Pedimos o estorno de %s na forma de pagamento original.', $number, $money ),
		'facts'    => array(
			'Pedido'          => $number,
			'Valor estornado' => $money,
		),
		'cta'      => $cta,
		'notes'    => array( 'O prazo para o valor aparecer depende do seu banco ou da operadora do cartão.' ),
	);
}

/**
 * Envia o e-mail ao comprador uma unica vez por etapa, para o e-mail verificado do pedido.
 *
 * @param object              $order  Pedido WooCommerce.
 * @param array<string,mixed> $row    Estorno.
 * @param string              $kind   Etapa: pending ou refunded.
 * @param string              $type   Tipo da notificação.
 * @param string              $dedupe Chave de deduplicação.
 */
function papelito_order_refund_send_customer_email( object $order, array $row, string $kind, string $type, string $dedupe ): void {
	if ( ! function_exists( 'papelito_email_send' ) || ! function_exists( 'papelito_receipt_email_recipient' ) || ! function_exists( 'papelito_email_notice_html' ) ) {
		return;
	}

	$recipient = papelito_receipt_email_recipient( $order );

	if ( is_wp_error( $recipient ) || '' === (string) $recipient ) {
		return;
	}

	if ( function_exists( 'papelito_claim_notification_email_dispatch' ) && ! papelito_claim_notification_email_dispatch( (int) $row['customer_id'], $type, $dedupe ) ) {
		return;
	}

	$view = papelito_order_refund_email_view( $order, $row, $kind );
	papelito_email_send( (string) $recipient, (string) $view['subject'], papelito_email_notice_html( $view ), papelito_email_notice_text( $view ) );
}

/**
 * Dados do recibo de estorno, reaproveitando comprador e vendor do recibo original quando existe.
 *
 * @param object              $order Pedido WooCommerce.
 * @param array<string,mixed> $row   Estorno concluido.
 * @return array<string,mixed>
 */
function papelito_order_refund_receipt_document( object $order, array $row ): array {
	$original = function_exists( 'papelito_receipt_document' ) ? papelito_receipt_document( $order ) : null;
	$original = is_array( $original ) ? $original : array();
	$method   = (string) $row['payment_method'];
	$billing  = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );

	return array(
		'receipt_number'   => (string) $row['receipt_number'],
		'order_number'     => (string) ( $original['order_number'] ?? $order->get_order_number() ),
		'issued_at'        => function_exists( 'papelito_receipt_datetime_label' ) ? papelito_receipt_datetime_label( (string) ( $row['settled_at'] ?? '' ) ) : '',
		'generated_at'     => function_exists( 'wp_date' ) ? (string) wp_date( 'd/m/Y H:i', time() ) : gmdate( 'd/m/Y H:i' ),
		'amount_cents'     => (int) $row['amount_cents'],
		'original_receipt' => (string) ( $original['receipt_number'] ?? '' ),
		'buyer'            => (string) ( $original['buyer']['label'] ?? $billing ),
		'buyer_cnpj'       => (string) ( $original['buyer']['cnpj'] ?? '' ),
		'vendor'           => (string) ( $original['order']['vendor'] ?? '' ),
		'payment_method'   => function_exists( 'papelito_order_routing_payment_method_label' ) ? papelito_order_routing_payment_method_label( $method ) : $method,
		'refund_method'    => PAPELITO_ORDER_REFUND_MODE_MANUAL === (string) $row['mode'] ? 'Transferência registrada pelo vendor' : 'Estorno na cobrança original',
		'reference'        => (string) ( $row['manual_reference'] ?? '' ),
	);
}

/**
 * PDF do recibo de estorno, com as primitivas de layout do recibo do pedido.
 *
 * @param object              $order Pedido WooCommerce.
 * @param array<string,mixed> $row   Estorno concluido.
 */
function papelito_order_refund_receipt_pdf( object $order, array $row ): string {
	$doc    = papelito_order_refund_receipt_document( $order, $row );
	$left   = PAPELITO_RECEIPT_PDF_LEFT;
	$width  = PAPELITO_RECEIPT_PDF_WIDTH;
	$header = papelito_receipt_pdf_page_header( $doc, false, 'RECIBO DE ESTORNO' );
	$ops    = array_merge( $header['ops'], papelito_receipt_pdf_identification( $doc, $header['y'] ) );
	$y      = $header['y'] - 52 - 22;

	$ops   = array_merge( $ops, papelito_receipt_pdf_block_header( 'VALOR DEVOLVIDO', $left, $y, $width ) );
	$y    -= 16;
	$ops[] = papelito_receipt_pdf_rect( $left, $y - 54, $width, 54, 'kraft' );
	$ops[] = papelito_receipt_pdf_frame( $left, $y - 54, $width, 54, 'rule' );
	$ops[] = papelito_receipt_pdf_text(
		papelito_receipt_money_cents( (int) $doc['amount_cents'] ),
		$left + 18,
		$y - 36,
		array(
			'size' => 20,
			'bold' => true,
		)
	);
	$y    -= 54 + 22;

	$fields = array_values(
		array_filter(
			array(
				array( 'COMPRADOR', $doc['buyer'] ),
				array( 'CNPJ', $doc['buyer_cnpj'] ),
				array( 'VENDOR', $doc['vendor'] ),
				array( 'FORMA DE PAGAMENTO ORIGINAL', $doc['payment_method'] ),
				array( 'MEIO DO ESTORNO', $doc['refund_method'] ),
				array( 'DATA DO ESTORNO', $doc['issued_at'] ),
				array( 'RECIBO ORIGINAL', $doc['original_receipt'] ),
				array( 'REFERÊNCIA DA TRANSFERÊNCIA', $doc['reference'] ),
			),
			static fn( array $field ): bool => '' !== trim( (string) $field[1] )
		)
	);

	$ops    = array_merge( $ops, papelito_receipt_pdf_block_header( 'DADOS DO ESTORNO', $left, $y, $width ) );
	$y     -= 16;
	$rows   = array_chunk( $fields, 2 );
	$column = ( $width - 54 ) / 2;
	$height = 18 + ( count( $rows ) * 36 );
	$ops[]  = papelito_receipt_pdf_frame( $left, $y - $height, $width, $height, 'rule' );
	$top    = $y - 22;

	foreach ( $rows as $pair ) {
		foreach ( $pair as $index => $field ) {
			$rendered = papelito_receipt_pdf_field( $field[0], (string) $field[1], $left + 18 + ( $index * ( $column + 18 ) ), $top, $column, 1 );
			$ops      = array_merge( $ops, $rendered['ops'] );
		}

		$top -= 36;
	}

	return papelito_pdf_assemble( array( array_merge( $ops, papelito_receipt_pdf_footer( $doc, 1, 1 ) ) ) );
}

/**
 * Recibo de estorno em PDF, com os mesmos headers de arquivo privado do recibo do pedido.
 *
 * `$download` separa ver de baixar: o visualizador da tela embute o PDF num
 * frame, e `attachment` ali faz o navegador baixar o arquivo em vez de
 * mostrá-lo. É a mesma distinção que o recibo do pedido já faz.
 *
 * @param object                   $order    Pedido WooCommerce.
 * @param array<string,mixed>|null $row      Estorno.
 * @param bool                     $download Força o anexo em vez da exibição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_order_refund_receipt_response( object $order, ?array $row, bool $download = false ) {
	if ( ! is_array( $row ) || PAPELITO_ORDER_REFUND_STATUS_REFUNDED !== (string) $row['status'] || '' === (string) ( $row['receipt_number'] ?? '' ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_receipt_unavailable', 'O recibo de estorno fica disponível quando o estorno for concluído.', 409 );
	}

	if ( ! function_exists( 'papelito_pdf_assemble' ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_receipt_unavailable', 'O recibo de estorno está indisponível no momento.', 500 );
	}

	$disposition = $download ? 'attachment' : 'inline';
	$response    = new WP_REST_Response( papelito_order_refund_receipt_pdf( $order, $row ), 200 );
	$response->header( 'Content-Type', 'application/pdf' );
	$response->header( 'Content-Disposition', $disposition . '; filename="recibo-estorno-pedido-' . absint( $order->get_id() ) . '.pdf"' );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	$response->header( 'X-Papelito-Receipt', '1' );

	return $response;
}

/**
 * Limite de escrita das rotas de estorno.
 *
 * @return true|WP_Error
 */
function papelito_order_refund_mutation_permission() {
	if ( ! is_user_logged_in() ) {
		return papelito_order_refund_error( 'papelito_order_refund_auth_required', 'Autenticação necessária.', 401 );
	}

	if ( function_exists( 'papelito_rate_limit' ) && ! papelito_rate_limit( 'order_refund_mutation', 'user:' . get_current_user_id(), 20, MINUTE_IN_SECONDS ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_rate_limited', 'Muitas alterações em pouco tempo. Tente novamente em instantes.', 429 );
	}

	return true;
}

/**
 * Exibe a chave PIX do comprador ao vendor do pedido, so com estorno manual pendente, e registra o acesso.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @return WP_REST_Response|WP_Error
 */
function papelito_order_refund_rest_reveal_pix_key( WP_REST_Request $request ) {
	$vendor_id = get_current_user_id();
	$order     = papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'id' ) ), $vendor_id );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$row = papelito_order_refund_get_by_order( (int) $order->get_id() );

	if ( ! is_array( $row ) || PAPELITO_ORDER_REFUND_STATUS_MANUAL_PENDING !== (string) $row['status'] ) {
		return papelito_order_refund_error( 'papelito_order_refund_pix_key_unavailable', 'A chave PIX do comprador só é exibida enquanto o estorno manual estiver pendente.', 409 );
	}

	$key = papelito_refund_pix_key_decrypt( (int) $row['customer_id'] );

	if ( is_wp_error( $key ) ) {
		return $key;
	}

	if ( null === $key ) {
		return papelito_order_refund_error( 'papelito_refund_pix_key_missing', 'O comprador ainda não cadastrou uma chave PIX para reembolso. Combine a devolução pelo chamado do pedido.', 404 );
	}

	papelito_order_refund_event( (int) $row['id'], (int) $row['order_id'], 'pix_key_revealed', null, null, $vendor_id, array( 'key_type' => $key['type'] ) );

	$response = new WP_REST_Response( $key, 200 );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0' );

	return $response;
}

/**
 * Registro do estorno manual pelo vendor.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @return WP_REST_Response|WP_Error
 */
function papelito_order_refund_rest_vendor_manual( WP_REST_Request $request ) {
	$seller = papelito_vendor_dashboard_require_seller();

	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	$order = papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'id' ) ), (int) $seller->ID );

	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$result = papelito_order_refund_register_manual(
		(int) $order->get_id(),
		array(
			'proofId'    => $request->get_param( 'proofId' ),
			'refundedAt' => $request->get_param( 'refundedAt' ),
			'reference'  => $request->get_param( 'reference' ),
			'notes'      => $request->get_param( 'notes' ),
		),
		(int) $seller->ID,
		false
	);

	return is_wp_error( $result ) ? $result : new WP_REST_Response( papelito_order_refund_payload( $result, 'vendor' ), 200 );
}

/**
 * Registro do estorno manual pela Papelito, como excecao justificada.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @return WP_REST_Response|WP_Error
 */
function papelito_order_refund_rest_admin_manual( WP_REST_Request $request ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_forbidden', 'Acesso restrito à Papelito.', 403 );
	}

	$result = papelito_order_refund_register_manual(
		absint( $request->get_param( 'orderId' ) ),
		array(
			'proofId'    => $request->get_param( 'proofId' ),
			'refundedAt' => $request->get_param( 'refundedAt' ),
			'reference'  => $request->get_param( 'reference' ),
			'notes'      => $request->get_param( 'notes' ),
		),
		get_current_user_id(),
		true,
		(string) $request->get_param( 'exceptionReason' )
	);

	return is_wp_error( $result ) ? $result : new WP_REST_Response( papelito_order_refund_payload( $result, 'admin' ), 200 );
}

/**
 * Fila de estornos para a Papelito.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @return WP_REST_Response|WP_Error
 */
function papelito_order_refund_rest_admin_list( WP_REST_Request $request ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_forbidden', 'Acesso restrito à Papelito.', 403 );
	}

	$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
	$filter = in_array( $filter, array( 'overdue', 'pending', 'all' ), true ) ? $filter : 'overdue';

	return new WP_REST_Response(
		array(
			'filter' => $filter,
			'items'  => array_map( static fn( array $row ): ?array => papelito_order_refund_payload( $row, 'admin' ), papelito_order_refund_list( $filter ) ),
		),
		200
	);
}

/**
 * Download autenticado do comprovante. O comprador so ve comprovante ja vinculado ao estorno.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @return WP_Error|void
 */
function papelito_order_refund_rest_download_proof( WP_REST_Request $request ) {
	global $wpdb;

	$tables = papelito_order_refund_tables();
	$proof  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.*, r.customer_id FROM {$tables['proofs']} p INNER JOIN {$tables['refunds']} r ON r.id = p.refund_id WHERE p.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			absint( $request->get_param( 'proofId' ) )
		),
		ARRAY_A
	);

	$user_id  = get_current_user_id();
	$is_admin = current_user_can( 'manage_options' );
	$allowed  = is_array( $proof ) && (
		$is_admin
		|| (int) $proof['vendor_id'] === $user_id
		|| ( (int) $proof['customer_id'] === $user_id && ! empty( $proof['attached_at'] ) )
	);

	if ( ! $allowed ) {
		return papelito_order_refund_error( 'papelito_order_refund_proof_not_found', 'Comprovante não encontrado.', 404 );
	}

	$key = (string) $proof['storage_key'];

	if ( ! papelito_private_file_key_is_valid( $key, array( 'pdf', 'jpg', 'png' ) ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_proof_invalid', 'Comprovante indisponível.', 404 );
	}

	$path = trailingslashit( papelito_order_refund_proofs_dir() ) . $key;

	if ( ! is_file( $path ) ) {
		return papelito_order_refund_error( 'papelito_order_refund_proof_missing', 'Comprovante indisponível.', 404 );
	}

	nocache_headers();
	header( 'Content-Type: ' . (string) $proof['mime'] );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $proof['original_name'] ) . '"' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

/**
 * Rotas REST do estorno.
 */
function papelito_order_refund_register_routes(): void {
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/profile/me/refund-pix-key',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_profile_user',
				'callback'            => static fn() => new WP_REST_Response( array( 'pixKey' => papelito_refund_pix_key_public( get_current_user_id() ) ), 200 ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_order_refund_mutation_permission',
				'callback'            => static function ( WP_REST_Request $request ) {
					$saved = papelito_refund_pix_key_save(
						get_current_user_id(),
						(string) $request->get_param( 'type' ),
						(string) $request->get_param( 'key' ),
						(string) $request->get_param( 'holderName' )
					);

					return is_wp_error( $saved ) ? $saved : new WP_REST_Response( array( 'pixKey' => $saved ), 200 );
				},
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => 'papelito_order_refund_mutation_permission',
				'callback'            => static function () {
					return papelito_refund_pix_key_delete( get_current_user_id() )
						? new WP_REST_Response( array( 'pixKey' => null ), 200 )
						: papelito_order_refund_error( 'papelito_refund_pix_not_deleted', 'Não foi possível remover a chave PIX.', 500 );
				},
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/refund/pix-key',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
			'callback'            => 'papelito_order_refund_rest_reveal_pix_key',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/refund/manual',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_order_refund_mutation_permission',
			'callback'            => 'papelito_order_refund_rest_vendor_manual',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/orders/(?P<id>\d+)/refund-receipt',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
			'callback'            => static function ( WP_REST_Request $request ) {
				$order = papelito_vendor_dashboard_vendor_order( absint( $request->get_param( 'id' ) ), get_current_user_id() );

				return is_wp_error( $order )
					? $order
					: papelito_order_refund_receipt_response( $order, papelito_order_refund_get_by_order( (int) $order->get_id() ), '1' === (string) $request->get_param( 'download' ) );
			},
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/profile/me/orders/(?P<id>\d+)/refund-receipt',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_dashboard_permission_profile_user',
			'callback'            => static function ( WP_REST_Request $request ) {
				$order = papelito_vendor_dashboard_customer_order( absint( $request->get_param( 'id' ) ), get_current_user_id() );

				return is_wp_error( $order )
					? $order
					: papelito_order_refund_receipt_response( $order, papelito_order_refund_get_by_order( (int) $order->get_id() ), '1' === (string) $request->get_param( 'download' ) );
			},
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/order-refunds/proofs/(?P<proofId>\d+)/download',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => static fn() => is_user_logged_in(),
			'callback'            => 'papelito_order_refund_rest_download_proof',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/admin/order-refunds',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => static fn() => is_user_logged_in(),
			'callback'            => 'papelito_order_refund_rest_admin_list',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/admin/order-refunds/(?P<orderId>\d+)/manual',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_order_refund_mutation_permission',
			'callback'            => 'papelito_order_refund_rest_admin_manual',
		)
	);
}
add_action( 'rest_api_init', 'papelito_order_refund_register_routes' );

/**
 * Retoma estornos pela API que falharam ou ficaram sem confirmacao.
 */
function papelito_order_refund_run_retries(): void {
	global $wpdb;

	$table = papelito_order_refund_tables()['refunds'];
	$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE mode = %s AND status IN (%s, %s) AND (next_attempt_at IS NULL OR next_attempt_at <= %s) AND updated_at <= %s ORDER BY id ASC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			PAPELITO_ORDER_REFUND_MODE_API,
			PAPELITO_ORDER_REFUND_STATUS_PROCESSING,
			PAPELITO_ORDER_REFUND_STATUS_FAILED,
			papelito_order_refund_now(),
			gmdate( PAPELITO_ORDER_REFUND_MYSQL_FORMAT, time() - ( 5 * MINUTE_IN_SECONDS ) )
		)
	);

	foreach ( is_array( $ids ) ? $ids : array() as $refund_id ) {
		papelito_order_refund_attempt_api( (int) $refund_id, 0 );
	}

	$completed_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = %s ORDER BY updated_at ASC, id ASC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			PAPELITO_ORDER_REFUND_STATUS_REFUNDED
		)
	);
	foreach ( is_array( $completed_ids ) ? $completed_ids : array() as $refund_id ) {
		$row = papelito_order_refund_get( (int) $refund_id );
		if ( is_array( $row ) ) {
			papelito_order_refund_apply_to_order( $row, 'reconciliation' );
		}
	}
}
add_action( PAPELITO_ORDER_REFUND_RETRY_HOOK, 'papelito_order_refund_run_retries' );

/**
 * Agenda a retomada periodica dos estornos.
 */
function papelito_order_refund_schedule_retries(): void {
	if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( PAPELITO_ORDER_REFUND_RETRY_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', PAPELITO_ORDER_REFUND_RETRY_HOOK );
	}
}
add_action( 'init', 'papelito_order_refund_schedule_retries' );
