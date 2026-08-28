<?php
/**
 * Standalone regression test do evento `purchase` enviado ao GA4.
 *
 * Cobre a identidade que vem do navegador, a composicao do payload do Measurement Protocol, a
 * idempotencia exigida por um gancho reentrante de pagamento, o agendamento que mantem a entrega
 * fora do caminho do checkout e o apagamento dos identificadores a pedido do titular.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$papelito_test_env  = array();
$papelito_http_log  = array();
$papelito_http_code = 204;
$papelito_scheduled = array();
$papelito_orders    = array();

function add_action() {}
function add_filter() {}
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function wp_json_encode( mixed $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( mixed $thing ) { return $thing instanceof WP_Error; }
function is_email( mixed $value ) { return is_string( $value ) && str_contains( $value, '@' ); }

function papelito_env( string $key, $default = null ) {
	global $papelito_test_env;
	return $papelito_test_env[ $key ] ?? $default;
}

function add_query_arg( array $args, string $url ) {
	return $url . '?' . http_build_query( $args );
}

function wp_remote_post( string $url, array $args ) {
	global $papelito_http_log, $papelito_http_code;
	$papelito_http_log[] = array(
		'url'  => $url,
		'body' => json_decode( (string) $args['body'], true ),
	);

	return array( 'response' => array( 'code' => $papelito_http_code ) );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0;
}

function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ) {
	global $papelito_scheduled;
	$papelito_scheduled[] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);

	return true;
}

function wp_next_scheduled( string $hook, array $args = array() ) {
	global $papelito_scheduled;

	foreach ( $papelito_scheduled as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args ) {
			return $event['timestamp'];
		}
	}

	return false;
}

function wc_get_order( int $order_id ) {
	global $papelito_orders;
	return $papelito_orders[ $order_id ] ?? null;
}

function wc_get_orders( array $args ) {
	global $papelito_orders;

	if ( 1 !== (int) ( $args['page'] ?? 1 ) ) {
		return array();
	}

	return array_values( $papelito_orders );
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message() { return $this->message; }
}

class Test_Order_Item {
	public function __construct(
		private int $product_id,
		private string $name,
		private int $quantity,
		private float $total
	) {}
	public function get_product_id() { return $this->product_id; }
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->quantity; }
	public function get_total() { return $this->total; }
}

class Test_Order {
	public array $meta = array();
	public int $saves  = 0;

	public function __construct(
		private int $id = 10,
		private string $number = '1042',
		private float $total = 259.8,
		private float $shipping = 19.9,
		private array $items = array(),
		private float $tax = 0.0
	) {}

	public function get_id() { return $this->id; }
	public function get_order_number() { return $this->number; }
	public function get_total() { return $this->total; }
	public function get_shipping_total() { return $this->shipping; }
	public function get_total_tax() { return $this->tax; }
	public function get_currency() { return 'BRL'; }
	public function get_items() { return $this->items; }
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ) { unset( $this->meta[ $key ] ); }
	public function save() { ++$this->saves; }
}

require_once __DIR__ . '/../includes/analytics_ga4.php';

$assertions = 0;
$failures   = array();

function papelito_test_assert( string $label, $expected, $actual ): void {
	global $assertions, $failures;
	++$assertions;

	if ( $expected !== $actual ) {
		$failures[] = sprintf(
			"%s\n  esperado: %s\n  recebido: %s",
			$label,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
	}
}

function papelito_test_order( float $tax = 0.0 ): Test_Order {
	return new Test_Order(
		10,
		'1042',
		259.8,
		19.9,
		array(
			new Test_Order_Item( 501, 'Seda King Size', 2, 179.8 ),
			new Test_Order_Item( 777, 'Piteira de vidro', 1, 60.1 ),
		),
		$tax
	);
}

function papelito_test_identified_order( float $tax = 0.0 ): Test_Order {
	$order = papelito_test_order( $tax );
	papelito_ga4_store_order_identifiers(
		$order,
		array(
			'client_id'  => '1189418253.1777895566',
			'session_id' => '1787849320',
		)
	);

	return $order;
}

// Identidade do navegador: formato exato do cookie, nada mais.
papelito_test_assert(
	'client_id valido passa',
	'1189418253.1777895566',
	papelito_ga4_sanitize_client_id( '1189418253.1777895566' )
);
papelito_test_assert( 'client_id sem ponto e recusado', '', papelito_ga4_sanitize_client_id( '1189418253' ) );
papelito_test_assert( 'client_id com texto e recusado', '', papelito_ga4_sanitize_client_id( 'GA1.1.abc.def' ) );
papelito_test_assert( 'client_id nao string e recusado', '', papelito_ga4_sanitize_client_id( array( 'x' ) ) );
papelito_test_assert( 'session_id valido passa', '1787849320', papelito_ga4_sanitize_session_id( '1787849320' ) );
papelito_test_assert( 'session_id com sufixo e recusado', '', papelito_ga4_sanitize_session_id( '1787849320$o3' ) );

// Persistencia no pedido.
$order = papelito_test_identified_order();
papelito_test_assert(
	'client_id gravado no pedido',
	'1189418253.1777895566',
	$order->meta[ PAPELITO_GA4_CLIENT_ID_META ] ?? ''
);
papelito_test_assert(
	'session_id gravado no pedido',
	'1787849320',
	$order->meta[ PAPELITO_GA4_SESSION_ID_META ] ?? ''
);

$order_sem_ids = papelito_test_order();
papelito_ga4_store_order_identifiers( $order_sem_ids, array( 'client_id' => 'invalido' ) );
papelito_test_assert( 'payload invalido nao grava meta', array(), $order_sem_ids->meta );
papelito_test_assert( 'payload invalido nao salva o pedido', 0, $order_sem_ids->saves );

// Payload do Measurement Protocol.
$payload = papelito_ga4_build_purchase_payload( $order );
papelito_test_assert( 'client_id vai na raiz', '1189418253.1777895566', $payload['client_id'] );
papelito_test_assert( 'evento e purchase', 'purchase', $payload['events'][0]['name'] );

$params = $payload['events'][0]['params'];
papelito_test_assert( 'transaction_id e o numero do pedido', '1042', $params['transaction_id'] );
papelito_test_assert( 'currency e BRL', 'BRL', $params['currency'] );
papelito_test_assert( 'session_id vai no evento', '1787849320', $params['session_id'] );
papelito_test_assert( 'item_id usa o id do produto', '501', $params['items'][0]['item_id'] );
papelito_test_assert( 'preco unitario sai do total da linha', 89.9, $params['items'][0]['price'] );
papelito_test_assert( 'quantidade preservada', 2, $params['items'][0]['quantity'] );

/**
 * O GA4 espera `value` = soma de `price * quantity` dos itens. Frete e imposto viajam em campos
 * proprios; somados dentro do `value` inflariam a receita atribuida a campanha e deixariam o
 * evento incoerente consigo mesmo.
 */
$soma_dos_itens = 0.0;
foreach ( $params['items'] as $item ) {
	$soma_dos_itens += $item['price'] * $item['quantity'];
}
papelito_test_assert( 'value e a soma dos itens', round( $soma_dos_itens, 2 ), $params['value'] );
papelito_test_assert( 'value exclui o frete do pedido', 239.9, $params['value'] );
papelito_test_assert( 'frete vai em campo proprio', 19.9, $params['shipping'] );

$order_com_imposto = papelito_test_identified_order( 12.35 );
$params_com_imposto = papelito_ga4_build_purchase_payload( $order_com_imposto )['events'][0]['params'];
papelito_test_assert( 'imposto vai em campo proprio', 12.35, $params_com_imposto['tax'] );
papelito_test_assert( 'imposto nao entra no value', 239.9, $params_com_imposto['value'] );

$sem_identidade = papelito_test_order();
papelito_test_assert(
	'pedido sem client_id nao vira payload',
	null,
	papelito_ga4_build_purchase_payload( $sem_identidade )
);

// Envio: exige configuracao, e acontece uma vez so.
$papelito_test_env = array();
papelito_test_assert( 'sem credenciais nao envia', false, papelito_ga4_send_purchase( $order ) );
papelito_test_assert( 'sem credenciais nao faz request', 0, count( $papelito_http_log ) );

$papelito_test_env = array(
	'GA4_MEASUREMENT_ID' => 'G-M82VLH1QVR',
	'GA4_API_SECRET'     => 'segredo',
);

papelito_test_assert( 'primeiro envio acontece', true, papelito_ga4_send_purchase( $order ) );
papelito_test_assert( 'um request foi feito', 1, count( $papelito_http_log ) );
papelito_test_assert(
	'transaction_id chegou ao Google',
	'1042',
	$papelito_http_log[0]['body']['events'][0]['params']['transaction_id']
);
papelito_test_assert(
	'pedido marcado como enviado',
	true,
	'' !== (string) ( $order->meta[ PAPELITO_GA4_PURCHASE_SENT_META ] ?? '' )
);

papelito_test_assert( 'webhook repetido nao reenvia', false, papelito_ga4_send_purchase( $order ) );
papelito_test_assert( 'nenhum request extra', 1, count( $papelito_http_log ) );

// Falha de entrega mantem o pedido elegivel para a proxima tentativa.
$papelito_http_code = 500;
$order_com_falha    = papelito_test_identified_order();
papelito_test_assert( 'HTTP 500 nao conta como enviado', false, papelito_ga4_send_purchase( $order_com_falha ) );
papelito_test_assert(
	'pedido nao marcado apos falha',
	'',
	(string) ( $order_com_falha->meta[ PAPELITO_GA4_PURCHASE_SENT_META ] ?? '' )
);

$papelito_http_code = 204;
papelito_test_assert( 'retentativa apos falha envia', true, papelito_ga4_send_purchase( $order_com_falha ) );

/**
 * O gancho de pagamento roda dentro do checkout quando o cartao e aprovado na hora: ele so pode
 * enfileirar, nunca falar com o Google.
 */
$papelito_scheduled = array();
$papelito_http_log  = array();
$order_agendado     = papelito_test_identified_order();

papelito_ga4_schedule_purchase( $order_agendado );
papelito_test_assert( 'confirmacao de pagamento agenda a entrega', 1, count( $papelito_scheduled ) );
papelito_test_assert( 'agendou o hook certo', PAPELITO_GA4_PURCHASE_HOOK, $papelito_scheduled[0]['hook'] );
papelito_test_assert( 'agendou com o id do pedido', array( 10 ), $papelito_scheduled[0]['args'] );
papelito_test_assert( 'confirmacao de pagamento nao chama o Google', 0, count( $papelito_http_log ) );

papelito_ga4_schedule_purchase( $order_agendado );
papelito_test_assert( 'webhook reemitido nao duplica agendamento', 1, count( $papelito_scheduled ) );

$papelito_scheduled = array();
$order_ja_enviado   = papelito_test_identified_order();
$order_ja_enviado->update_meta_data( PAPELITO_GA4_PURCHASE_SENT_META, (string) time() );
papelito_ga4_schedule_purchase( $order_ja_enviado );
papelito_test_assert( 'pedido ja enviado nao agenda', 0, count( $papelito_scheduled ) );

$papelito_test_env = array();
$papelito_scheduled = array();
papelito_ga4_schedule_purchase( papelito_test_identified_order() );
papelito_test_assert( 'sem credenciais nao agenda', 0, count( $papelito_scheduled ) );

$papelito_test_env = array(
	'GA4_MEASUREMENT_ID' => 'G-M82VLH1QVR',
	'GA4_API_SECRET'     => 'segredo',
);

// Entrega agendada: sucesso, falha com backoff e desistencia.
$papelito_scheduled = array();
$papelito_http_log  = array();
$papelito_http_code = 204;
$order_cron         = papelito_test_identified_order();
$papelito_orders    = array( 10 => $order_cron );

papelito_ga4_run_scheduled_purchase( 10 );
papelito_test_assert( 'cron entrega o evento', 1, count( $papelito_http_log ) );
papelito_test_assert( 'cron nao reagenda apos sucesso', 0, count( $papelito_scheduled ) );

$papelito_http_code = 500;
$papelito_http_log  = array();
$order_cron_falha   = papelito_test_identified_order();
$papelito_orders    = array( 10 => $order_cron_falha );

papelito_ga4_run_scheduled_purchase( 10 );
papelito_test_assert( 'falha reagenda', 1, count( $papelito_scheduled ) );
papelito_test_assert(
	'backoff cresce na segunda tentativa',
	true,
	$papelito_scheduled[0]['timestamp'] > time(),
);
papelito_test_assert( 'tentativa contabilizada', '1', (string) $order_cron_falha->meta[ PAPELITO_GA4_PURCHASE_ATTEMPTS_META ] );

$papelito_scheduled = array();
$order_cron_falha->update_meta_data( PAPELITO_GA4_PURCHASE_ATTEMPTS_META, (string) ( PAPELITO_GA4_MAX_ATTEMPTS - 1 ) );
papelito_ga4_run_scheduled_purchase( 10 );
papelito_test_assert( 'desiste apos o teto de tentativas', 0, count( $papelito_scheduled ) );

$papelito_scheduled = array();
$papelito_orders    = array( 10 => papelito_test_order() );
papelito_ga4_run_scheduled_purchase( 10 );
papelito_test_assert( 'pedido sem identidade nao entra em retry infinito', 0, count( $papelito_scheduled ) );

// Apagamento a pedido do titular.
$papelito_http_code = 204;
$order_titular      = papelito_test_identified_order();
$papelito_orders    = array( 10 => $order_titular );

$resultado = papelito_ga4_erase_order_identifiers( 'comprador@empresa.com.br' );
papelito_test_assert( 'eraser remove identificadores', true, $resultado['items_removed'] );
papelito_test_assert( 'client_id apagado', '', (string) ( $order_titular->meta[ PAPELITO_GA4_CLIENT_ID_META ] ?? '' ) );
papelito_test_assert( 'session_id apagado', '', (string) ( $order_titular->meta[ PAPELITO_GA4_SESSION_ID_META ] ?? '' ) );
papelito_test_assert( 'varredura concluida em uma pagina', true, $resultado['done'] );

$sem_nada = papelito_ga4_erase_order_identifiers( 'comprador@empresa.com.br' );
papelito_test_assert( 'nada a remover na segunda passada', false, $sem_nada['items_removed'] );

$erasers = papelito_ga4_register_privacy_eraser( array() );
papelito_test_assert(
	'eraser registrado no mecanismo do WordPress',
	'papelito_ga4_erase_order_identifiers',
	$erasers['papelito-ga4-identifiers']['callback']
);

if ( empty( $failures ) ) {
	printf( "OK: %d asserções passaram.\n", $assertions );
	exit( 0 );
}

printf( "FALHOU: %d de %d asserções.\n\n", count( $failures ), $assertions );
foreach ( $failures as $failure ) {
	printf( "%s\n\n", $failure );
}
exit( 1 );
