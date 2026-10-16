<?php
/**
 * Handlers REST de gateways, preferência, perfil financeiro e payment-context.
 *
 * Os handlers são finos: aqui se prova autorização, versão, reautenticação e que nada é
 * decidido pelo payload. As regras ficam nos módulos testados à parte.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

class WP_User {
	public function __construct( public int $ID = 0, public string $user_pass = 'hash' ) {}
}

class WP_REST_Request {
	public function __construct( private array $json = array(), private array $params = array() ) {}

	public function get_json_params(): array {
		return $this->json;
	}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
}

class WP_REST_Response {
	public array $headers = array();

	public function __construct( public mixed $data = null, public int $status = 200 ) {}

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	public function get_data(): mixed {
		return $this->data;
	}
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'POST, PUT, PATCH';
}

$GLOBALS['rest_user']        = 201;
$GLOBALS['rest_admin']       = false;
$GLOBALS['rest_calls']       = array();
$GLOBALS['rest_password_ok'] = true;
$GLOBALS['rest_rate_ok']     = true;

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function register_rest_route( mixed ...$args ): bool { $GLOBALS['rest_routes'][] = $args[1]; return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function get_current_user_id(): int { return $GLOBALS['rest_user']; }
function current_user_can( string $cap ): bool { return $GLOBALS['rest_admin']; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return 'cnpj' === $key ? '11.222.333/0001-81' : ''; }
function get_user_by( string $field, int $id ): WP_User { return new WP_User( $id ); }
function get_userdata( int $id ): ?WP_User { return 999 === $id ? null : new WP_User( $id ); }
function wp_check_password( string $password, string $hash, int $user_id ): bool { return $GLOBALS['rest_password_ok']; }
function papelito_auth_rate_limit( mixed ...$args ): bool { return $GLOBALS['rest_rate_ok']; }
function papelito_user_is_effective_seller( mixed $user ): bool { return true; }
function papelito_vendor_dashboard_require_seller(): mixed { return $GLOBALS['rest_user'] > 0 ? new WP_User( $GLOBALS['rest_user'] ) : new WP_Error( 'papelito_vendor_auth_required', 'x', array( 'status' => 401 ) ); }
function papelito_vendor_payment_mode( int $vendor_id, bool $mark = true ): string { return 'gateways'; }
function papelito_payment_gateway_statuses( int $vendor_id ): array {
	$GLOBALS['rest_calls'][] = array( 'statuses', $vendor_id );
	return array( array( 'provider' => 'pagarme', 'state' => 'blocked_cnae', 'canReceive' => false ), array( 'provider' => 'mercadopago', 'state' => 'ready', 'canReceive' => true ) );
}
function papelito_payment_gateway_health_record( int $vendor_id, string $provider ): array { $GLOBALS['rest_calls'][] = array( 'health', $vendor_id, $provider ); return array(); }
function papelito_vendor_payment_preference( int $vendor_id ): array { return array( 'preferredProvider' => 'mercadopago', 'version' => 2, 'explicit' => true ); }
function papelito_vendor_payment_preference_save( int $vendor_id, string $provider, int $version ) {
	$GLOBALS['rest_calls'][] = array( 'save_preference', $vendor_id, $provider, $version );
	return 'pagarme' === $provider ? new WP_Error( 'papelito_payment_gateway_blocked_cnae', 'x', array( 'status' => 422 ) ) : array( 'preferredProvider' => $provider, 'version' => $version + 1, 'explicit' => true );
}
function papelito_vendor_financial_profile_public( int $vendor_id ): array { return array( 'version' => 3, 'profile' => array( 'managingPartners' => array( array( 'hasDocument' => true, 'documentLast4' => '0909' ) ) ) ); }
function papelito_vendor_financial_profile_patch( int $vendor_id, array $patch, int $version, string $source = 'profile' ) {
	$GLOBALS['rest_calls'][] = array( 'patch_profile', $vendor_id, $patch, $version );
	return 3 === $version ? papelito_vendor_financial_profile_public( $vendor_id ) : new WP_Error( 'papelito_financial_profile_version_conflict', 'x', array( 'status' => 409 ) );
}
function papelito_pagarme_financial_profile_pending( int $vendor_id ): array { return array( 'policy' => 'blocked_cnae', 'pendingFields' => array() ); }
function papelito_vendor_payment_account_current( int $vendor_id, string $provider, string $environment ): ?array { return null; }
function papelito_vendor_payment_account_latest( int $vendor_id, string $provider, string $environment ): ?array { return 202 === $vendor_id ? array( 'id' => 9, 'vendor_id' => 202, 'collector_id' => 'col-9' ) : null; }
function papelito_vendor_payment_identity_audit_append( int $vendor_id, array $entry ): void { $GLOBALS['rest_calls'][] = array( 'audit', $vendor_id, $entry['accountId'], $entry['collectorId'], $entry['status'], $entry['actorId'] ); }
function papelito_vendor_payment_account_set_identity( int $account_id, string $status, int $actor, string $evidence ): bool { $GLOBALS['rest_calls'][] = array( 'identity', $account_id, $status, $actor, $evidence ); return true; }
function papelito_mercadopago_environment(): string { return 'test'; }
function papelito_pagarme_get_vendor_recipient_id( int $vendor_id ): string { return 're_1'; }
function papelito_pagarme_sync_vendor_recipient( int $vendor_id ): array { $GLOBALS['rest_calls'][] = array( 'pagarme_sync', $vendor_id ); return array(); }
function papelito_vendor_cnae_refresh( int $vendor_id ): array { $GLOBALS['rest_calls'][] = array( 'cnae_refresh', $vendor_id ); return array(); }
function do_action( mixed ...$args ): void { $GLOBALS['rest_calls'][] = array( 'action', $args[0] ); }
function papelito_order_routing_require_customer(): mixed { return true; }
function papelito_order_routing_resolve_b2b_snapshot( int $user_id, array $payload ) {
	return 55 === (int) ( $payload['expected_company_id'] ?? 0 ) ? array( 'company_id' => 55 ) : new WP_Error( 'papelito_checkout_company_context_changed', 'x', array( 'status' => 409 ) );
}
function papelito_order_routing_normalize_items( mixed $items ): mixed { return is_array( $items ) && ! empty( $items ) ? $items : new WP_Error( 'papelito_checkout_empty_items', 'x', array( 'status' => 422 ) ); }
function papelito_order_routing_resolve_items( array $items ): array { return array( 'vendor_id' => 301, 'lines' => array() ); }
function papelito_payment_gateway_resolve( int $vendor_id, array $options = array() ) {
	$GLOBALS['rest_calls'][] = array( 'resolve', $vendor_id );
	return array( 'provider' => 'mercadopago', 'flow' => 'redirect', 'configurationVersion' => 'cfg', 'fallbackReason' => '', 'legacy' => false );
}
function wc_create_order(): never { throw new RuntimeException( 'payment-context não pode criar pedido' ); }

require_once dirname( __DIR__ ) . '/includes/vendor_payment_rest.php';
require_once dirname( __DIR__ ) . '/includes/payment_context_rest.php';

$failures = 0;

function rest_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function rest_data( mixed $response ): mixed {
	return $response instanceof WP_REST_Response ? $response->get_data() : $response;
}

papelito_vendor_payment_rest_register_routes();
papelito_payment_context_rest_register_routes();
foreach ( array( '/vendor/payment-gateways', '/vendor/payment-preferences', '/vendor/financial-profile', '/vendor/payment-gateways/refresh', '/checkout/payment-context', '/admin/vendors/(?P<id>\d+)/payment-gateways', '/admin/vendors/(?P<id>\d+)/mercadopago/identity' ) as $route ) {
	rest_assert( "rota {$route} registrada", in_array( $route, $GLOBALS['rest_routes'], true ) );
}

$gateways = rest_data( papelito_vendor_payment_rest_gateways() );
rest_assert( 'GET gateways lê só o vendor autenticado', array( 'statuses', 201 ) === $GLOBALS['rest_calls'][0] );
rest_assert( 'GET gateways devolve os dois status e a preferência', 2 === count( $gateways['gateways'] ) && 'mercadopago' === $gateways['preference']['preferredProvider'] );

$GLOBALS['rest_calls'] = array();
$saved = rest_data( papelito_vendor_payment_rest_preferences( new WP_REST_Request( array( 'preferredProvider' => 'mercadopago', 'configurationVersion' => 2, 'vendorId' => 999 ) ) ) );
rest_assert( 'PUT preferência usa o vendor autenticado, nunca o do payload', array( 'save_preference', 201, 'mercadopago', 2 ) === $GLOBALS['rest_calls'][0] );
rest_assert( 'PUT preferência devolve versão nova', 3 === $saved['preference']['version'] );
$blocked = papelito_vendor_payment_rest_preferences( new WP_REST_Request( array( 'preferredProvider' => 'pagarme', 'configurationVersion' => 3 ) ) );
rest_assert( 'preferência em gateway bloqueado volta com código próprio', is_wp_error( $blocked ) && 'papelito_payment_gateway_blocked_cnae' === $blocked->get_error_code() );
$missing_version = papelito_vendor_payment_rest_preferences( new WP_REST_Request( array( 'preferredProvider' => 'mercadopago' ) ) );
rest_assert( 'preferência sem versão é recusada', is_wp_error( $missing_version ) );

$profile = rest_data( papelito_vendor_payment_rest_profile_get() );
rest_assert( 'GET perfil traz pendências separadas por gateway', isset( $profile['pending']['pagarme'] ) && 'blocked_cnae' === $profile['pending']['pagarme']['policy'] );
rest_assert( 'GET perfil não carrega CPF', false === strpos( json_encode( $profile ), '"document"' ) );
rest_assert( 'GET perfil traz o CNPJ do cadastro autoritativo da loja', '11.222.333/0001-81' === ( $profile['cnpj'] ?? null ) );

$GLOBALS['rest_calls'] = array();
$patched = papelito_vendor_payment_rest_profile_patch( new WP_REST_Request( array( 'version' => 3, 'profile' => array( 'tradingName' => 'Nova' ) ) ) );
rest_assert( 'PATCH de campo comum não exige senha', ! is_wp_error( $patched ) && 'patch_profile' === $GLOBALS['rest_calls'][0][0] );

$GLOBALS['rest_calls'] = array();
$GLOBALS['rest_password_ok'] = false;
$denied = papelito_vendor_payment_rest_profile_patch( new WP_REST_Request( array( 'version' => 3, 'profile' => array( 'bankAccount' => array( 'accountNumber' => '1' ) ), 'currentPassword' => 'errada' ) ) );
rest_assert( 'PATCH de conta bancária exige reautenticação', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] && array() === $GLOBALS['rest_calls'] );
$GLOBALS['rest_password_ok'] = true;

$conflict = papelito_vendor_payment_rest_profile_patch( new WP_REST_Request( array( 'version' => 1, 'profile' => array( 'tradingName' => 'x' ) ) ) );
rest_assert( 'PATCH com versão obsoleta é 409', is_wp_error( $conflict ) && 409 === $conflict->get_error_data()['status'] );

$GLOBALS['rest_calls'] = array();
$refreshed = papelito_vendor_payment_rest_refresh();
$kinds     = array_column( $GLOBALS['rest_calls'], 0 );
rest_assert( 'refresh reconsulta CNAE, recipient e saúde sem criar cobrança', in_array( 'cnae_refresh', $kinds, true ) && in_array( 'pagarme_sync', $kinds, true ) && in_array( 'health', $kinds, true ) && ! is_wp_error( $refreshed ) );
$GLOBALS['rest_rate_ok'] = false;
$limited = papelito_vendor_payment_rest_refresh();
rest_assert( 'refresh respeita limite por vendor', is_wp_error( $limited ) && 429 === $limited->get_error_data()['status'] );
$GLOBALS['rest_rate_ok'] = true;

$GLOBALS['rest_calls'] = array();
$context = rest_data( papelito_payment_context_rest( new WP_REST_Request( array( 'expected_company_id' => 55, 'items' => array( array( 'product_id' => 1, 'qty' => 1, 'vendor_id' => 999 ) ) ) ) ) );
rest_assert( 'payment-context resolve pelo vendor do carrinho autoritativo', array( 'resolve', 301 ) === $GLOBALS['rest_calls'][0] );
rest_assert( 'payment-context devolve PaymentContext sem campo interno', 'redirect' === $context['flow'] && ! array_key_exists( 'legacy', $context ) );
$company = papelito_payment_context_rest( new WP_REST_Request( array( 'expected_company_id' => 1, 'items' => array( array( 'product_id' => 1 ) ) ) ) );
rest_assert( 'payment-context recusa empresa diferente da ativa', is_wp_error( $company ) && 409 === $company->get_error_data()['status'] );

rest_assert( 'detalhe administrativo exige permissão', is_wp_error( papelito_admin_vendor_payment_rest_permission() ) );
$GLOBALS['rest_admin'] = true;
rest_assert( 'admin com permissão passa', true === papelito_admin_vendor_payment_rest_permission() );
$admin_view = rest_data( papelito_admin_vendor_payment_rest_get( new WP_REST_Request( array(), array( 'id' => 202 ) ) ) );
rest_assert( 'admin vê os gateways do vendor pedido', 2 === count( $admin_view['gateways'] ) );

$GLOBALS['rest_calls'] = array();
$identity = papelito_admin_vendor_mercadopago_identity( new WP_REST_Request( array( 'status' => 'verified', 'evidenceRef' => 'ticket-123', 'accountId' => 9 ), array( 'id' => 202 ) ) );
rest_assert( 'admin confirma titularidade com evidência', ! is_wp_error( $identity ) && array( 'identity', 9, 'verified', 201, 'ticket-123' ) === $GLOBALS['rest_calls'][0] );
rest_assert( 'confirmação entra na trilha de auditoria com conta e collector', in_array( array( 'audit', 202, 9, 'col-9', 'verified', 201 ), $GLOBALS['rest_calls'], true ) );

$GLOBALS['rest_calls'] = array();
$stale_account = papelito_admin_vendor_mercadopago_identity( new WP_REST_Request( array( 'status' => 'verified', 'evidenceRef' => 'ticket-124', 'accountId' => 8 ), array( 'id' => 202 ) ) );
rest_assert( 'confirmação de conta que já foi trocada é 409 sem gravar', is_wp_error( $stale_account ) && 409 === $stale_account->get_error_data()['status'] && array() === $GLOBALS['rest_calls'] );
$no_account_id = papelito_admin_vendor_mercadopago_identity( new WP_REST_Request( array( 'status' => 'verified', 'evidenceRef' => 'ticket-125' ), array( 'id' => 202 ) ) );
rest_assert( 'confirmação sem accountId é recusada', is_wp_error( $no_account_id ) && array() === $GLOBALS['rest_calls'] );
$no_evidence = papelito_admin_vendor_mercadopago_identity( new WP_REST_Request( array( 'status' => 'verified', 'accountId' => 9 ), array( 'id' => 202 ) ) );
rest_assert( 'confirmação sem referência de evidência é recusada', is_wp_error( $no_evidence ) );
$no_account = papelito_admin_vendor_mercadopago_identity( new WP_REST_Request( array( 'status' => 'verified', 'evidenceRef' => 'x', 'accountId' => 1 ), array( 'id' => 203 ) ) );
rest_assert( 'confirmação sem conta conectada é 404', is_wp_error( $no_account ) && 404 === $no_account->get_error_data()['status'] );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
