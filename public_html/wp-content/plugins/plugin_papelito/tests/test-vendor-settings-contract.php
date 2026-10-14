<?php
/**
 * Standalone regression test for the vendor settings surface.
 *
 * Cobre os dois contratos que a pagina /vendor/configuracoes passou a depender:
 * o codigo estavel do ultimo erro do recebedor (para o front traduzir sem exibir
 * texto cru da Pagar.me) e a distincao entre prazo de manuseio configurado e
 * prazo apenas herdado do padrao.
 *
 * Usage: php tests/test-vendor-settings-contract.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pap_usermeta'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( ...$args ) {}
function do_action( ...$args ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }

function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['pap_usermeta'][ (int) $user_id ][ $key ] ?? '';
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['pap_usermeta'][ (int) $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['pap_usermeta'][ (int) $user_id ][ $key ] );
	return true;
}

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_url( $value ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function papelito_current_utc_mysql(): string { return '2026-08-31 18:59:47'; }

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function get_current_user_id(): int { return (int) ( $GLOBALS['pap_current_user'] ?? 0 ); }

class WP_REST_Request {
	private array $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
}

class WP_REST_Response {
	public mixed $data;
	public int $status;

	public function __construct( mixed $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

require __DIR__ . '/../includes/pagarme_recipients.php';
require __DIR__ . '/../includes/vendor_dashboard.php';
require_once __DIR__ . '/../includes/vendor_carriers.php';

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} — expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

echo "Recebedor: codigo do ultimo erro\n";

/* --- caso 1: falha grava o codigo estavel, nao so a mensagem --- */
papelito_pagarme_save_vendor_recipient_error(
	7,
	new WP_Error( 'papelito_pagarme_missing_partner', 'A Pagar.me exige um responsavel legal.', array( 'status' => 422 ) )
);
$state = papelito_pagarme_get_vendor_recipient_state( 7 );
papelito_assert( 'codigo do erro fica disponivel para o front traduzir', 'papelito_pagarme_missing_partner', $state['last_error_code'] );
papelito_assert( 'mensagem crua continua no estado, para suporte', 'A Pagar.me exige um responsavel legal.', $state['last_error'] );

/* --- caso 2: sincronizacao bem-sucedida limpa o codigo junto com a mensagem --- */
papelito_pagarme_save_vendor_recipient_state( 7, array( 'id' => 're_123', 'status' => 'active' ) );
$state = papelito_pagarme_get_vendor_recipient_state( 7 );
papelito_assert( 'sucesso limpa o codigo do erro anterior', '', $state['last_error_code'] );
papelito_assert( 'sucesso limpa a mensagem do erro anterior', '', $state['last_error'] );
papelito_assert( 'sucesso mantem o status sincronizado', 'active', $state['status'] );

/* --- caso 3: erro em string (sem WP_Error) nao inventa codigo --- */
papelito_pagarme_save_vendor_recipient_error( 8, 'Falha de rede' );
papelito_assert( 'erro sem WP_Error deixa o codigo vazio', '', papelito_pagarme_get_vendor_recipient_state( 8 )['last_error_code'] );

echo "\nPrazo de manuseio: configurado x herdado\n";

/* --- caso 4: vendor que nunca definiu prazo recebe o padrao marcado como nao configurado --- */
$settings = papelito_vendor_dashboard_settings( 9 );
papelito_assert( 'prazo nao definido cai no padrao', 2, $settings['shipping_lead_time_days'] );
papelito_assert( 'prazo nao definido nao passa por escolha do vendor', false, $settings['shipping_lead_time_configured'] );

/* --- caso 5: prazo igual ao padrao, mas escolhido, e configurado --- */
update_user_meta( 9, 'shipping_lead_time_days', 2 );
$settings = papelito_vendor_dashboard_settings( 9 );
papelito_assert( 'prazo 2 escolhido continua valendo 2', 2, $settings['shipping_lead_time_days'] );
papelito_assert( 'prazo 2 escolhido e marcado como configurado', true, $settings['shipping_lead_time_configured'] );

/* --- caso 6: valor fora da faixa nao conta como configurado --- */
update_user_meta( 9, 'shipping_lead_time_days', 99 );
$settings = papelito_vendor_dashboard_settings( 9 );
papelito_assert( 'prazo fora da faixa volta ao padrao', 2, $settings['shipping_lead_time_days'] );
papelito_assert( 'prazo fora da faixa nao conta como configurado', false, $settings['shipping_lead_time_configured'] );

echo "\nCorreios: estado efetivo exposto nas configuracoes\n";

/* --- caso 7: sem Braspress configurada os Correios ficam ligados e travados --- */
update_user_meta( 9, 'papelito_correios_disabled', '1' );
$settings = papelito_vendor_dashboard_settings( 9 );
papelito_assert( 'sem Braspress os Correios aparecem ligados', true, $settings['correios_enabled'] );
papelito_assert( 'sem Braspress o interruptor dos Correios fica travado', true, $settings['correios_locked'] );
papelito_assert( 'a preferencia de desligado sem Braspress e esquecida', '', get_user_meta( 9, 'papelito_correios_disabled', true ) );

echo "\nPUT /vendor/me/settings: atualizacao parcial\n";

$GLOBALS['pap_current_user'] = 9;
update_user_meta( 9, 'shipping_lead_time_days', 4 );

/* --- caso 8: corpo vazio e recusado --- */
$empty = papelito_vendor_dashboard_handle_update_settings( new WP_REST_Request() );
papelito_assert( 'corpo sem campo conhecido e recusado', 'papelito_vendor_settings_empty', is_wp_error( $empty ) ? $empty->get_error_code() : null );

/* --- caso 9: desligar Correios sem Braspress e recusado sem gravar o prazo junto --- */
$locked = papelito_vendor_dashboard_handle_update_settings( new WP_REST_Request( array( 'correios_enabled' => false, 'shipping_lead_time_days' => 7 ) ) );
papelito_assert( 'desligar Correios sem Braspress devolve o erro da regra', 'papelito_vendor_correios_locked', is_wp_error( $locked ) ? $locked->get_error_code() : null );
papelito_assert( 'a recusa nao grava o prazo enviado no mesmo corpo', 4, get_user_meta( 9, 'shipping_lead_time_days', true ) );

/* --- caso 10: valor ambiguo para Correios nao vira desligado --- */
$ambiguous = papelito_vendor_dashboard_handle_update_settings( new WP_REST_Request( array( 'correios_enabled' => 'off' ) ) );
papelito_assert( 'valor ambiguo para Correios e recusado', 'papelito_vendor_invalid_correios', is_wp_error( $ambiguous ) ? $ambiguous->get_error_code() : null );

/* --- caso 11: so o prazo continua funcionando como antes --- */
$lead = papelito_vendor_dashboard_handle_update_settings( new WP_REST_Request( array( 'shipping_lead_time_days' => 5 ) ) );
papelito_assert( 'prazo sozinho e gravado', 5, $lead instanceof WP_REST_Response ? $lead->data['shipping_lead_time_days'] : null );
papelito_assert( 'resposta do prazo traz o estado dos Correios', true, $lead instanceof WP_REST_Response ? $lead->data['correios_enabled'] : null );

echo "\n";
if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s)\n";
	exit( 1 );
}
echo "OK\n";
