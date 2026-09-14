<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.
/**
 * Contrato normalizado e orquestração de providers de frete.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_SHIPPING_PROVIDER_CORREIOS  = 'correios';
const PAPELITO_SHIPPING_PROVIDER_BRASPRESS = 'braspress';

/**
 * Cria a chave estável e namespaced de uma opção de frete.
 *
 * @param string $provider Identificador do provider.
 * @param string $code Código de serviço ou cotação externa.
 * @return string Chave de opção ou vazio quando inválida.
 */
function papelito_shipping_provider_option_key( string $provider, string $code ): string {
	$provider = sanitize_key( $provider );
	$code     = sanitize_key( $code );

	return '' === $provider || '' === $code ? '' : $provider . ':' . $code;
}

/**
 * Adiciona os campos comuns e assinatura de integridade a uma opção.
 *
 * @param string                 $provider Identificador do provider.
 * @param array<string,mixed>    $option Resposta de cotação do provider.
 * @param string|null            $quoted_at Data UTC em que a opção foi gerada.
 * @return array<string,mixed> Opção pública normalizada.
 */
function papelito_shipping_normalize_provider_option( string $provider, array $option, ?string $quoted_at = null ): array {
	$provider  = sanitize_key( $provider );
	$code      = sanitize_text_field( (string) ( $option['code'] ?? '' ) );
	$price     = (float) ( $option['price'] ?? 0 );
	$quoted_at = null !== $quoted_at ? $quoted_at : current_time( 'mysql', true );

	$option['provider']             = $provider;
	$option['option_key']           = papelito_shipping_provider_option_key( $provider, $code );
	$option['carrier_cost_cents']   = (int) round( $price * 100 );
	$option['customer_price_cents'] = (int) round( $price * 100 );
	$option['external_quote_id']    = isset( $option['external_quote_id'] ) ? (string) $option['external_quote_id'] : null;
	$option['quoted_at']            = $quoted_at;
	$option['expires_at']           = isset( $option['expires_at'] ) ? (string) $option['expires_at'] : null;
	$option['fingerprint']          = hash_hmac(
		'sha256',
		wp_json_encode(
			array(
				'provider'           => $provider,
				'option_key'         => $option['option_key'],
				'carrier_cost_cents' => $option['carrier_cost_cents'],
				'customer_price_cents' => $option['customer_price_cents'],
				'delivery_time'      => isset( $option['delivery_time'] ) && null !== $option['delivery_time'] ? absint( $option['delivery_time'] ) : null,
				'external_quote_id'  => $option['external_quote_id'],
				'expires_at'         => $option['expires_at'],
			),
		),
		wp_salt( 'auth' )
	);

	return $option;
}

/**
 * Normaliza todas as opções de um provider preservando os dados de origem.
 *
 * @param string              $provider Identificador do provider.
 * @param array<string,mixed> $quote Resultado de cotação.
 * @return array<string,mixed> Resultado com opções normalizadas.
 */
function papelito_shipping_normalize_provider_result( string $provider, array $quote ): array {
	$options = isset( $quote['options'] ) && is_array( $quote['options'] ) ? $quote['options'] : array();

	$quote['options'] = array_map(
		static function ( $option ) use ( $provider ): array {
			return is_array( $option ) ? papelito_shipping_normalize_provider_option( $provider, $option ) : array();
		},
		$options
	);

	return $quote;
}

/**
 * Compara seleção do checkout com uma opção normalizada.
 *
 * A forma legada sem namespace só é aceita para Correios.
 *
 * @param array<string,mixed> $option Opção cotada.
 * @param string              $selection Chave escolhida pelo cliente.
 * @return bool Se a seleção corresponde à opção.
 */
function papelito_shipping_option_matches_selection( array $option, string $selection ): bool {
	$selection  = sanitize_text_field( $selection );
	$option_key = sanitize_text_field( (string) ( $option['option_key'] ?? '' ) );

	if ( '' !== $option_key && hash_equals( $option_key, $selection ) ) {
		return true;
	}

	return false === strpos( $selection, ':' )
		&& PAPELITO_SHIPPING_PROVIDER_CORREIOS === sanitize_key( (string) ( $option['provider'] ?? '' ) )
		&& hash_equals( sanitize_text_field( (string) ( $option['code'] ?? '' ) ), $selection );
}

/**
 * Confere a opção recotada contra o snapshot que foi apresentado ao comprador.
 *
 * A chave apenas identifica o serviço. O fingerprint liga preço, prazo e
 * validade à cotação original e evita aceitar uma recotação silenciosa no
 * momento de criar o pedido.
 *
 * @param array<string,mixed> $option Opção recém-cotada pelo backend.
 * @param string              $selection Chave escolhida pelo cliente.
 * @param array<string,mixed> $expected Snapshot recebido no checkout.
 * @return bool Se a seleção e o snapshot ainda correspondem à cotação.
 */
function papelito_shipping_option_matches_checkout_snapshot( array $option, string $selection, array $expected ): bool {
	if ( ! papelito_shipping_option_matches_selection( $option, $selection ) ) {
		return false;
	}

	$fingerprint = sanitize_text_field( (string) ( $expected['fingerprint'] ?? '' ) );
	if ( '' === $fingerprint || ! hash_equals( (string) ( $option['fingerprint'] ?? '' ), $fingerprint ) ) {
		return false;
	}

	if ( ! isset( $expected['customer_price_cents'] ) || (int) $expected['customer_price_cents'] !== (int) ( $option['customer_price_cents'] ?? -1 ) ) {
		return false;
	}

	$expected_delivery = $expected['delivery_time'] ?? null;
	$actual_delivery   = isset( $option['delivery_time'] ) && null !== $option['delivery_time'] ? absint( $option['delivery_time'] ) : null;
	if ( null === $expected_delivery ? null !== $actual_delivery : ( ! is_numeric( $expected_delivery ) || absint( $expected_delivery ) !== $actual_delivery ) ) {
		return false;
	}

	$expected_expiry = isset( $expected['expires_at'] ) && null !== $expected['expires_at'] ? sanitize_text_field( (string) $expected['expires_at'] ) : null;
	$actual_expiry   = isset( $option['expires_at'] ) && null !== $option['expires_at'] ? sanitize_text_field( (string) $option['expires_at'] ) : null;
	$expiry_timestamp = null !== $actual_expiry ? strtotime( $actual_expiry ) : false;
	if ( false !== $expiry_timestamp && $expiry_timestamp <= time() ) {
		return false;
	}

	return $expected_expiry === $actual_expiry;
}

/**
 * Monta o contexto que somente o backend pode fornecer a uma transportadora.
 *
 * O valor mercantil é o total autoritativo das linhas depois de desconto de
 * itens. Frete e desconto de frete ficam deliberadamente fora dele.
 *
 * @param array<int,array<string,mixed>> $resolved Itens resolvidos no backend.
 * @param string                         $coupon_code Cupom informado no carrinho.
 * @param int                            $user_id Usuário autenticado.
 * @param string                         $recipient_cnpj CNPJ B2B autorizado.
 * @return array<string,mixed>|WP_Error Contexto seguro ou erro de precificação.
 */
function papelito_shipping_provider_quote_context( array $resolved, string $coupon_code, int $user_id, string $recipient_cnpj ) {
	if ( ! function_exists( 'papelito_pricing_apply_discounts' ) ) {
		return new WP_Error( 'papelito_shipping_pricing_unavailable', 'Não foi possível preparar a cotação de frete.', array( 'status' => 503 ) );
	}

	$priced = papelito_pricing_apply_discounts( $resolved, $coupon_code, $user_id, 0 );
	if ( is_wp_error( $priced ) ) {
		return $priced;
	}

	return array(
		'recipient_cnpj'          => papelito_vendor_integration_normalize_document( $recipient_cnpj ),
		'merchandise_value_cents' => max( 0, (int) ( $priced['totals']['itemsCents'] ?? 0 ) ),
		'priced_lines'            => is_array( $priced['lines'] ?? null ) ? $priced['lines'] : array(),
	);
}

/**
 * Obtém o CNPJ do destinatário a partir do contexto B2B, nunca do browser.
 *
 * @param int $user_id Usuário autenticado.
 * @return string CNPJ normalizado ou vazio quando não autorizado.
 */
function papelito_shipping_recipient_cnpj_for_user( int $user_id ): string {
	if ( $user_id <= 0 || ! function_exists( 'papelito_company_purchase_capability' ) ) {
		return '';
	}

	$capability = papelito_company_purchase_capability( $user_id );
	$company    = is_array( $capability['company'] ?? null ) ? $capability['company'] : array();
	$cnpj       = papelito_vendor_integration_normalize_document( $company['cnpj'] ?? '' );

	return ! empty( $capability['canPurchase'] ) && 14 === strlen( $cnpj ) ? $cnpj : '';
}

/**
 * Verifica o feature flag global de um provider.
 *
 * @param string $provider Identificador do provider.
 * @return bool Se o provider pode participar da cotação.
 */
function papelito_shipping_feature_enabled( string $provider ): bool {
	if ( PAPELITO_SHIPPING_PROVIDER_BRASPRESS !== $provider ) {
		return true;
	}

	$value = defined( 'PAPELITO_BRASPRESS_ENABLED' ) ? PAPELITO_BRASPRESS_ENABLED : ( function_exists( 'papelito_env' ) ? papelito_env( 'PAPELITO_BRASPRESS_ENABLED', 'false' ) : getenv( 'PAPELITO_BRASPRESS_ENABLED' ) );

	return true === filter_var( $value, FILTER_VALIDATE_BOOLEAN );
}

/**
 * Verifica a allowlist operacional por vendor.
 *
 * @param string $provider Identificador do provider.
 * @param int    $vendor_id ID do vendor.
 * @return bool Se o vendor foi liberado explicitamente.
 */
function papelito_shipping_provider_vendor_allowed( string $provider, int $vendor_id ): bool {
	if ( PAPELITO_SHIPPING_PROVIDER_BRASPRESS !== $provider ) {
		return true;
	}

	$raw = defined( 'PAPELITO_BRASPRESS_VENDOR_ALLOWLIST' ) ? (string) PAPELITO_BRASPRESS_VENDOR_ALLOWLIST : ( function_exists( 'papelito_env' ) ? (string) papelito_env( 'PAPELITO_BRASPRESS_VENDOR_ALLOWLIST', '' ) : (string) getenv( 'PAPELITO_BRASPRESS_VENDOR_ALLOWLIST' ) );
	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

	return ! empty( $ids ) && in_array( $vendor_id, $ids, true );
}

/**
 * Obtém o pacote físico aprovado para a cotação Braspress.
 *
 * @param int                      $vendor_id ID do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return array<string,mixed>|WP_Error Pacote aprovado ou bloqueio seguro.
 */
function papelito_shipping_braspress_physical_package( int $vendor_id, array $items ) {
	/**
	 * Não existe fallback para o pacote sintético dos Correios. Logística deve
	 * instalar explicitamente este contrato com peso em kg, volumes e grupos de
	 * cubagem em metros, inclusive a versão de aprovação que o originou.
	 */
	$package = apply_filters( 'papelito_braspress_physical_package', null, $vendor_id, $items );
	if ( ! is_array( $package ) || empty( $package['approval_version'] ) || ! function_exists( 'papelito_braspress_package_is_valid' ) || ! papelito_braspress_package_is_valid( $package ) ) {
		return new WP_Error( 'papelito_braspress_package_not_approved', 'A embalagem física Braspress ainda não foi aprovada.', array( 'status' => 503 ) );
	}

	return $package;
}

/**
 * Informa se existe pacote físico aprovado para os itens.
 *
 * @param int                      $vendor_id ID do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return bool Se a Braspress pode receber o pacote.
 */
function papelito_shipping_braspress_package_is_approved( int $vendor_id, array $items ): bool {
	return is_array( papelito_shipping_braspress_physical_package( $vendor_id, $items ) );
}

/**
 * Cota Braspress somente quando todos os gates independentes passam.
 *
 * Falhas deste provider retornam nulo para não remover alternativas válidas.
 *
 * @param int                      $vendor_id ID do vendor.
 * @param string                   $destination_cep CEP de destino.
 * @param array<int,array<string,mixed>> $items Itens resolvidos.
 * @param array<string,mixed>      $context Dados autoritativos de precificação e destinatário.
 * @return array<string,mixed>|null Cotação Braspress ou nulo quando inelegível.
 */
function papelito_shipping_quote_braspress( int $vendor_id, string $destination_cep, array $items, array $context = array() ) {
	if ( ! papelito_shipping_feature_enabled( PAPELITO_SHIPPING_PROVIDER_BRASPRESS ) || ! papelito_shipping_provider_vendor_allowed( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, $vendor_id ) ) {
		return null;
	}

	$integration = papelito_vendor_integration_resolve_braspress( $vendor_id );
	if ( null === $integration || is_wp_error( $integration ) ) {
		return null;
	}

	$package = papelito_shipping_braspress_physical_package( $vendor_id, $items );
	if ( is_wp_error( $package ) ) {
		return null;
	}

	$recipient_cnpj          = papelito_vendor_integration_normalize_document( $context['recipient_cnpj'] ?? '' );
	$merchandise_value_cents = isset( $context['merchandise_value_cents'] ) ? (int) $context['merchandise_value_cents'] : 0;
	if ( 14 !== strlen( $recipient_cnpj ) || $merchandise_value_cents <= 0 || ! function_exists( 'papelito_braspress_quote' ) ) {
		return null;
	}

	$result = papelito_braspress_quote( $integration, $recipient_cnpj, $destination_cep, $package, $merchandise_value_cents );
	return is_wp_error( $result ) ? null : array(
		'origin_cep'      => $integration['config']['origin_cep'],
		'destination_cep' => $destination_cep,
		'vendor_id'       => $vendor_id,
		'options'         => array( $result ),
	);
}

/**
 * Cota cada provider de modo isolado e combina apenas opções válidas.
 *
 * @param int                      $vendor_id ID do vendor.
 * @param string                   $destination_cep CEP de destino.
 * @param array<int,array<string,mixed>> $items Itens resolvidos.
 * @param array<string,mixed>      $context Dados autoritativos de precificação e destinatário.
 * @return array<string,mixed>|WP_Error Resultado de cotação ou indisponibilidade total.
 */
function papelito_shipping_quote_all_providers( int $vendor_id, string $destination_cep, array $items, array $context = array() ) {
	$correios  = papelito_correios_quote( $vendor_id, $destination_cep, $items );
	$braspress = papelito_shipping_quote_braspress( $vendor_id, $destination_cep, $items, $context );

	if ( is_wp_error( $correios ) && ( null === $braspress || is_wp_error( $braspress ) ) ) {
		return $correios;
	}

	$result = is_array( $correios ) ? papelito_shipping_normalize_provider_result( PAPELITO_SHIPPING_PROVIDER_CORREIOS, $correios ) : array(
		'origin_cep'      => '',
		'destination_cep' => $destination_cep,
		'vendor_id'       => $vendor_id,
		'options'         => array(),
	);

	if ( is_array( $braspress ) ) {
		$normalized        = papelito_shipping_normalize_provider_result( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, $braspress );
		$result['options'] = array_merge( $result['options'], $normalized['options'] ?? array() );
	}

	if ( empty( $result['options'] ) ) {
		return new WP_Error( 'papelito_checkout_shipping_unavailable', 'Não foi possível cotar o frete.', array( 'status' => 503 ) );
	}

	return $result;
}
