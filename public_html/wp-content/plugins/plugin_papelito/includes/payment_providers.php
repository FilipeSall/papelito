<?php
/**
 * Fronteira comum dos gateways de pagamento (Pagar.me e Mercado Pago).
 *
 * Governa duas perguntas que nenhum adapter pode responder sozinho: em qual modo financeiro o
 * vendor está hoje (legado, fluxo novo ou suspenso) e qual gateway cobrou um pedido já criado.
 *
 * A regra de ouro: o gateway de uma cobrança é histórico. Ele sai do snapshot gravado na criação
 * da tentativa, nunca da preferência atual do vendor. Pedido antigo sem snapshot só é lido como
 * Pagar.me quando carrega evidência da Pagar.me; sem evidência, falha explícita.
 *
 * O marcador `papelito_vendor_payment_policy_enforced` é gravado no primeiro ingresso do vendor na
 * coorte e nunca é apagado aqui: desligar a flag ou tirar o vendor da allowlist suspende novas
 * compras dele em vez de devolvê-lo ao caminho financeiro legado.
 *
 * O que este módulo não faz: não fala com gateway, não escolhe gateway para uma compra (isso é do
 * resolver) e não mexe em estoque.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_PAYMENT_PROVIDER_PAGARME     = 'pagarme';
const PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO = 'mercadopago';

const PAPELITO_PAYMENT_PROVIDER_META    = '_papelito_payment_provider';
const PAPELITO_PAYMENT_ENVIRONMENT_META = '_papelito_payment_environment';
const PAPELITO_PAYMENT_ACCOUNT_ID_META  = '_papelito_payment_account_id';
const PAPELITO_PAYMENT_ACCOUNT_REF_META = '_papelito_payment_account_ref';
const PAPELITO_PAYMENT_ATTEMPT_ID_META  = '_papelito_payment_attempt_id';

const PAPELITO_VENDOR_PAYMENT_POLICY_ENFORCED_META = 'papelito_vendor_payment_policy_enforced';

const PAPELITO_VENDOR_PAYMENT_MODE_LEGACY    = 'legacy';
const PAPELITO_VENDOR_PAYMENT_MODE_GATEWAYS  = 'gateways';
const PAPELITO_VENDOR_PAYMENT_MODE_SUSPENDED = 'suspended';

/**
 * Gateways conhecidos, na ordem em que o painel os apresenta.
 *
 * @return array<int,string>
 */
function papelito_payment_providers(): array {
	return array( PAPELITO_PAYMENT_PROVIDER_PAGARME, PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO );
}

/**
 * Diz se o identificador é um gateway conhecido.
 *
 * @param string $provider Identificador cru.
 * @return bool
 */
function papelito_payment_provider_is_valid( string $provider ): bool {
	return in_array( $provider, papelito_payment_providers(), true );
}

/**
 * Lê configuração do rollout: constante do wp-config primeiro, ambiente depois.
 *
 * @param string $name     Nome da configuração.
 * @param string $fallback Valor quando nada foi definido.
 * @return string
 */
function papelito_payment_gateways_config( string $name, string $fallback = '' ): string {
	if ( defined( $name ) ) {
		return trim( (string) constant( $name ) );
	}

	$value = function_exists( 'papelito_env' ) ? papelito_env( $name, $fallback ) : getenv( $name );

	return is_string( $value ) ? trim( $value ) : $fallback;
}

/**
 * Flag server-side que libera o fluxo novo para a coorte. Nasce desligada.
 *
 * Controla novas compras e onboarding; webhook, reconciliação e estorno de cobranças já emitidas
 * continuam rodando com ela desligada.
 *
 * @return bool
 */
function papelito_payment_gateways_enabled(): bool {
	return true === filter_var( papelito_payment_gateways_config( 'PAPELITO_PAYMENT_GATEWAYS_ENABLED', 'false' ), FILTER_VALIDATE_BOOLEAN );
}

/**
 * Vendors liberados explicitamente para o fluxo novo.
 *
 * @return array<int,int>
 */
function papelito_payment_gateways_cohort_ids(): array {
	$raw = papelito_payment_gateways_config( 'PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS', '' );
	$ids = array();

	foreach ( explode( ',', $raw ) as $piece ) {
		$piece = trim( $piece );
		if ( 1 === preg_match( '/^\d+$/', $piece ) && (int) $piece > 0 ) {
			$ids[] = (int) $piece;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Diz se o vendor está na coorte habilitada agora (flag ligada e allowlist).
 *
 * @param int $vendor_id Vendor avaliado.
 * @return bool
 */
function papelito_payment_gateways_vendor_in_cohort( int $vendor_id ): bool {
	return $vendor_id > 0
		&& papelito_payment_gateways_enabled()
		&& in_array( $vendor_id, papelito_payment_gateways_cohort_ids(), true );
}

/**
 * Diz se o vendor já ingressou na política financeira nova.
 *
 * @param int $vendor_id Vendor avaliado.
 * @return bool
 */
function papelito_vendor_payment_policy_enforced( int $vendor_id ): bool {
	return $vendor_id > 0 && '' !== (string) get_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_POLICY_ENFORCED_META, true );
}

/**
 * Grava o marcador persistente de ingresso na política. Idempotente; nunca sobrescreve.
 *
 * @param int $vendor_id Vendor que ingressou.
 * @return void
 */
function papelito_vendor_payment_policy_mark_enforced( int $vendor_id ): void {
	if ( $vendor_id <= 0 || papelito_vendor_payment_policy_enforced( $vendor_id ) ) {
		return;
	}

	add_user_meta( $vendor_id, PAPELITO_VENDOR_PAYMENT_POLICY_ENFORCED_META, current_time( 'mysql', true ), true );
}

/**
 * Modo financeiro vigente do vendor.
 *
 * `legacy`: nunca ingressou e não está na coorte — Pagar.me como sempre foi.
 * `gateways`: na coorte — política CNAE, preferência e resolver valem.
 * `suspended`: já ingressou e saiu da coorte/flag — sem novas compras, histórico preservado.
 *
 * A avaliação de um vendor da coorte grava o marcador; passe `$mark = false` em leituras que não
 * podem ter efeito colateral.
 *
 * @param int  $vendor_id Vendor avaliado.
 * @param bool $mark      Se o primeiro ingresso deve gravar o marcador.
 * @return string
 */
function papelito_vendor_payment_mode( int $vendor_id, bool $mark = true ): string {
	if ( papelito_payment_gateways_vendor_in_cohort( $vendor_id ) ) {
		if ( $mark ) {
			papelito_vendor_payment_policy_mark_enforced( $vendor_id );
		}

		return PAPELITO_VENDOR_PAYMENT_MODE_GATEWAYS;
	}

	return papelito_vendor_payment_policy_enforced( $vendor_id )
		? PAPELITO_VENDOR_PAYMENT_MODE_SUSPENDED
		: PAPELITO_VENDOR_PAYMENT_MODE_LEGACY;
}

/**
 * Gateway que cobrou o pedido, lido do snapshot histórico.
 *
 * Sem snapshot, só a evidência Pagar.me (ID de pedido ou cobrança) autoriza ler como Pagar.me.
 * Nunca consulta a preferência atual do vendor.
 *
 * @param object $order Pedido WooCommerce.
 * @return string|WP_Error
 */
function papelito_payment_provider_for_order( object $order ) {
	$stored = method_exists( $order, 'get_meta' ) ? sanitize_key( (string) $order->get_meta( PAPELITO_PAYMENT_PROVIDER_META, true ) ) : '';

	if ( '' !== $stored ) {
		return papelito_payment_provider_is_valid( $stored )
			? $stored
			: new WP_Error( 'papelito_payment_provider_unknown', 'Gateway do pedido não reconhecido.', array( 'status' => 409 ) );
	}

	if ( papelito_payment_order_has_pagarme_evidence( $order ) ) {
		return PAPELITO_PAYMENT_PROVIDER_PAGARME;
	}

	return new WP_Error( 'papelito_payment_provider_unknown', 'Não há evidência do gateway que cobrou este pedido.', array( 'status' => 409 ) );
}

/**
 * Diz se o pedido carrega identificadores remotos da Pagar.me.
 *
 * @param object $order Pedido WooCommerce.
 * @return bool
 */
function papelito_payment_order_has_pagarme_evidence( object $order ): bool {
	if ( ! method_exists( $order, 'get_meta' ) ) {
		return false;
	}

	foreach ( array( 'PAPELITO_PAGARME_ORDER_ID_META', 'PAPELITO_PAGARME_CHARGE_ID_META' ) as $constant ) {
		if ( defined( $constant ) && '' !== trim( (string) $order->get_meta( constant( $constant ), true ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Grava no pedido o gateway, ambiente e conta da tentativa que vai cobrá-lo.
 *
 * Deve rodar antes da chamada remota: é o que permite reconciliar uma cobrança mesmo se o processo
 * morrer depois do POST. O chamador salva o pedido.
 *
 * @param object              $order    Pedido WooCommerce.
 * @param array<string,mixed> $snapshot provider, environment, account_id, account_ref, attempt_id.
 * @return true|WP_Error
 */
function papelito_payment_store_order_snapshot( object $order, array $snapshot ) {
	$provider    = sanitize_key( (string) ( $snapshot['provider'] ?? '' ) );
	$environment = sanitize_key( (string) ( $snapshot['environment'] ?? '' ) );

	if ( ! papelito_payment_provider_is_valid( $provider ) ) {
		return new WP_Error( 'papelito_payment_provider_unknown', 'Gateway inválido para o pedido.', array( 'status' => 500 ) );
	}

	if ( ! in_array( $environment, array( 'test', 'live' ), true ) ) {
		return new WP_Error( 'papelito_payment_environment_invalid', 'Ambiente de pagamento inválido.', array( 'status' => 500 ) );
	}

	$order->update_meta_data( PAPELITO_PAYMENT_PROVIDER_META, $provider );
	$order->update_meta_data( PAPELITO_PAYMENT_ENVIRONMENT_META, $environment );
	$order->update_meta_data( PAPELITO_PAYMENT_ACCOUNT_ID_META, absint( $snapshot['account_id'] ?? 0 ) );
	$order->update_meta_data( PAPELITO_PAYMENT_ACCOUNT_REF_META, sanitize_text_field( (string) ( $snapshot['account_ref'] ?? '' ) ) );
	$order->update_meta_data( PAPELITO_PAYMENT_ATTEMPT_ID_META, absint( $snapshot['attempt_id'] ?? 0 ) );

	return true;
}

/**
 * Conta uma métrica operacional, quando a observabilidade estiver carregada.
 *
 * @param string $metric Métrica do catálogo de `payment_observability.php`.
 * @return void
 */
function papelito_payment_observe_metric( string $metric ): void {
	if ( function_exists( 'papelito_payment_metrics_increment' ) ) {
		papelito_payment_metrics_increment( $metric );
	}
}

/**
 * Snapshot de cobrança gravado no pedido.
 *
 * @param object $order Pedido WooCommerce.
 * @return array{provider:string,environment:string,account_id:int,account_ref:string,attempt_id:int}
 */
function papelito_payment_order_snapshot( object $order ): array {
	$provider = papelito_payment_provider_for_order( $order );

	return array(
		'provider'    => is_wp_error( $provider ) ? '' : $provider,
		'environment' => sanitize_key( (string) $order->get_meta( PAPELITO_PAYMENT_ENVIRONMENT_META, true ) ),
		'account_id'  => absint( $order->get_meta( PAPELITO_PAYMENT_ACCOUNT_ID_META, true ) ),
		'account_ref' => sanitize_text_field( (string) $order->get_meta( PAPELITO_PAYMENT_ACCOUNT_REF_META, true ) ),
		'attempt_id'  => absint( $order->get_meta( PAPELITO_PAYMENT_ATTEMPT_ID_META, true ) ),
	);
}
