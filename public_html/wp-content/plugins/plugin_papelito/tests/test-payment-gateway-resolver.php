<?php
/**
 * Escolha exclusiva de gateway por vendor, resolução do gateway efetivo e regras de troca.
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

$GLOBALS['resolver_env']      = array(
	'PAPELITO_PAYMENT_GATEWAYS_ENABLED'    => 'true',
	'PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS' => '91',
);
$GLOBALS['resolver_meta']     = array();
$GLOBALS['resolver_statuses'] = array();
$GLOBALS['resolver_coverage_bumps'] = 0;

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-16 12:00:00'; }
function papelito_env( string $key, mixed $default = null ): mixed { return $GLOBALS['resolver_env'][ $key ] ?? $default; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['resolver_meta'][ $user_id ][ $key ] ?? ''; }
function add_user_meta( int $user_id, string $key, mixed $value, bool $unique = false ): bool {
	if ( $unique && isset( $GLOBALS['resolver_meta'][ $user_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['resolver_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function update_user_meta( int $user_id, string $key, mixed $value, mixed $prev = '' ): bool {
	$current = $GLOBALS['resolver_meta'][ $user_id ][ $key ] ?? '';
	if ( '' !== $prev && $current !== $prev ) {
		return false;
	}
	$GLOBALS['resolver_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function papelito_payment_gateway_status( int $vendor_id, string $provider ): array {
	return array_merge(
		array( 'provider' => $provider, 'state' => 'ready', 'canReceive' => true, 'reasonCodes' => array(), 'restrictionCode' => '' ),
		$GLOBALS['resolver_statuses'][ $provider ] ?? array()
	);
}
function papelito_coverage_bump_cache_version( mixed ...$args ): void { ++$GLOBALS['resolver_coverage_bumps']; }
function papelito_payment_gateway_statuses( int $vendor_id ): array {
	return array( papelito_payment_gateway_status( $vendor_id, 'pagarme' ), papelito_payment_gateway_status( $vendor_id, 'mercadopago' ) );
}

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/payment_attempts.php';
require_once dirname( __DIR__ ) . '/includes/payment_fallback_policy.php';
require_once dirname( __DIR__ ) . '/includes/payment_gateway_resolver.php';

const RESOLVER_VENDOR = 91;

$failures = 0;

function resolver_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

function resolver_set( string $provider, bool $can, string $state = 'ready', string $reason = '' ): void {
	$GLOBALS['resolver_statuses'][ $provider ] = array( 'canReceive' => $can, 'state' => $state, 'reasonCodes' => '' === $reason ? array() : array( $reason ) );
}

$preference = papelito_vendor_payment_preference( RESOLVER_VENDOR );
resolver_assert( 'Mercado Pago é o preferido inicial da coorte', 'mercadopago' === $preference['preferredProvider'] && false === $preference['explicit'] );

$context = papelito_payment_gateway_resolve( RESOLVER_VENDOR );
resolver_assert( 'preferido apto é usado', 'mercadopago' === $context['provider'] && 'redirect' === $context['flow'] && '' === $context['fallbackReason'] );

resolver_set( 'mercadopago', false, 'verification_pending', 'identity_verification_pending' );
$context = papelito_payment_gateway_resolve( RESOLVER_VENDOR );
resolver_assert( 'sem escolha explícita, o padrão é o gateway apto', 'pagarme' === papelito_vendor_payment_preference( RESOLVER_VENDOR )['preferredProvider'] && 'pagarme' === $context['provider'] && '' === $context['fallbackReason'] );

resolver_set( 'mercadopago', true );
resolver_set( 'pagarme', true );
$saved = papelito_vendor_payment_preference_save( RESOLVER_VENDOR, 'pagarme', $preference['version'] );
resolver_assert( 'vendor escolhe Pagar.me apta', ! is_wp_error( $saved ) && 'pagarme' === papelito_vendor_payment_preference( RESOLVER_VENDOR )['preferredProvider'] );
resolver_assert( 'trocar o gateway invalida o cache de cobertura', 1 === $GLOBALS['resolver_coverage_bumps'] );
resolver_assert( 'preferência Pagar.me resolve Pagar.me', 'pagarme' === papelito_payment_gateway_resolve( RESOLVER_VENDOR )['provider'] );

resolver_set( 'pagarme', false, 'blocked_cnae', 'cnae_restricted' );
$context = papelito_payment_gateway_resolve( RESOLVER_VENDOR );
resolver_assert( 'gateway escolhido inapto não troca para o outro', is_wp_error( $context ) && 'papelito_payment_no_gateway_available' === $context->get_error_code() );
resolver_assert( 'escolha salva continua registrada mesmo inapta', 'pagarme' === papelito_vendor_payment_preference( RESOLVER_VENDOR )['preferredProvider'] );

$version  = papelito_vendor_payment_preference( RESOLVER_VENDOR )['version'];
$rejected = papelito_vendor_payment_preference_save( RESOLVER_VENDOR, 'pagarme', $version );
resolver_assert( 'seleção manual de gateway bloqueado é recusada', is_wp_error( $rejected ) && 'papelito_payment_gateway_blocked_cnae' === $rejected->get_error_code() );

resolver_set( 'pagarme', false, 'kyc_pending', 'in_review' );
$rejected = papelito_vendor_payment_preference_save( RESOLVER_VENDOR, 'pagarme', $version );
resolver_assert( 'seleção manual de gateway inapto é recusada', is_wp_error( $rejected ) && 'papelito_payment_gateway_not_eligible' === $rejected->get_error_code() );

$stale = papelito_vendor_payment_preference_save( RESOLVER_VENDOR, 'mercadopago', $version - 1 );
resolver_assert( 'versão obsoleta da preferência é conflito', is_wp_error( $stale ) && 409 === $stale->get_error_data()['status'] );

resolver_set( 'mercadopago', false, 'connection_pending', 'not_connected' );
$none = papelito_payment_gateway_resolve( RESOLVER_VENDOR );
resolver_assert( 'nenhum gateway apto bloqueia a compra de forma recuperável', is_wp_error( $none ) && 'papelito_payment_no_gateway_available' === $none->get_error_code() && 409 === $none->get_error_data()['status'] );

resolver_set( 'mercadopago', true );
resolver_set( 'pagarme', true );
$excluded = papelito_payment_gateway_resolve( RESOLVER_VENDOR, array( 'exclude' => array( 'pagarme' ) ) );
resolver_assert( 'falha do gateway escolhido antes da emissão não vira cobrança no outro', is_wp_error( $excluded ) && 'papelito_payment_no_gateway_available' === $excluded->get_error_code() );

$before = papelito_payment_gateway_resolve( RESOLVER_VENDOR );
resolver_set( 'mercadopago', false, 'unavailable', 'token_revoked' );
$after = papelito_payment_gateway_resolve( RESOLVER_VENDOR );
resolver_assert( 'mudança de aptidão muda a configurationVersion', $before['configurationVersion'] !== $after['configurationVersion'] );

$unavailable = new WP_Error( 'papelito_mercadopago_unavailable', 'x', array( 'category' => 'unavailable' ) );
$unknown     = new WP_Error( 'papelito_mercadopago_unknown_outcome', 'x', array( 'category' => 'unknown_outcome' ) );
$validation  = new WP_Error( 'papelito_mercadopago_validation', 'x', array( 'category' => 'validation' ) );
$regulation  = new WP_Error( 'papelito_mercadopago_business_restriction', 'x', array( 'category' => 'business_restriction' ) );
$pagarme_net = new WP_Error( 'papelito_pagarme_network_error', 'x', array( 'status' => 502 ) );
resolver_assert( 'indisponibilidade antes da criação libera fallback', papelito_payment_gateway_error_allows_fallback( 'mercadopago', $unavailable ) );
resolver_assert( 'timeout/unknown nunca libera fallback', ! papelito_payment_gateway_error_allows_fallback( 'mercadopago', $unknown ) );
resolver_assert( 'erro de payload não é indisponibilidade', ! papelito_payment_gateway_error_allows_fallback( 'mercadopago', $validation ) );
resolver_assert( 'restrição regulatória não é contornada com outro gateway', ! papelito_payment_gateway_error_allows_fallback( 'mercadopago', $regulation ) );
resolver_assert( 'erro de rede Pagar.me não prova ausência de cobrança', ! papelito_payment_gateway_error_allows_fallback( 'pagarme', $pagarme_net ) );

foreach ( array( 'creating', 'unknown', 'pending', 'paid' ) as $state ) {
	resolver_assert( "tentativa {$state} impede troca automática", ! papelito_payment_gateway_attempt_allows_switch( array( 'state' => $state ) ) );
}
resolver_assert( 'tentativa sem emissão permite troca', papelito_payment_gateway_attempt_allows_switch( array( 'state' => 'not_started' ) ) );
resolver_assert( 'cartão recusado (failed) não vira cobrança no outro gateway', ! papelito_payment_gateway_attempt_allows_switch( array( 'state' => 'failed', 'remote_id' => 'or_1' ) ) );
resolver_assert( 'falha sem criação remota comprovada permite troca', papelito_payment_gateway_attempt_allows_switch( array( 'state' => 'failed', 'remote_id' => '', 'last_error_code' => 'unavailable' ) ) );
resolver_assert( 'credencial revogada antes do envio permite troca', papelito_payment_gateway_attempt_allows_switch( array( 'state' => 'failed', 'remote_id' => '', 'last_error_code' => 'configuration' ) ) );
resolver_assert( 'falha de payload não permite troca', ! papelito_payment_gateway_attempt_allows_switch( array( 'state' => 'failed', 'remote_id' => '', 'last_error_code' => 'validation' ) ) );

$GLOBALS['resolver_env']['PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS'] = '';
$suspended = papelito_payment_gateway_resolve( RESOLVER_VENDOR );
resolver_assert( 'vendor marcado fora da coorte fica sem novas compras', is_wp_error( $suspended ) && 'papelito_payment_vendor_suspended' === $suspended->get_error_code() );

$legacy = papelito_payment_gateway_resolve( 7 );
resolver_assert( 'vendor legado segue Pagar.me direta', 'pagarme' === $legacy['provider'] && true === $legacy['legacy'] );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
