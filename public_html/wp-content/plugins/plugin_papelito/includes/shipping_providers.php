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
const PAPELITO_SHIPPING_PROVIDERS           = array( PAPELITO_SHIPPING_PROVIDER_CORREIOS, PAPELITO_SHIPPING_PROVIDER_BRASPRESS );
const PAPELITO_SHIPPING_FAILURE_CATEGORIES  = array( 'configuration_error', 'validation_error', 'timeout', 'network_error', 'rate_limited', 'provider_4xx', 'provider_5xx', 'invalid_response', 'unknown_error' );
const PAPELITO_SHIPPING_LOCAL_VALIDATION_CODES = array( 'papelito_shipping_package_exceeds_limits', 'papelito_shipping_product_dimensions_missing', 'papelito_shipping_kit_package_not_supported', 'papelito_kit_package_dimensions_missing' );

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

/** Exige um único código canônico, sem corrigir silenciosamente aliases ou chaves divergentes. */
function papelito_shipping_provider_service_code( string $provider, array $raw ): ?string {
	$code = $raw['service_code'] ?? $raw['code'] ?? null;
	if ( ! is_string( $code ) || '' === $code || sanitize_key( $code ) !== $code ) {
		return null;
	}
	foreach ( array( 'code', 'service_code' ) as $alias ) {
		if ( array_key_exists( $alias, $raw ) && $raw[ $alias ] !== $code ) {
			return null;
		}
	}
	$option_key = papelito_shipping_provider_option_key( $provider, $code );
	if ( '' === $option_key || ( array_key_exists( 'option_key', $raw ) && $raw['option_key'] !== $option_key ) ) {
		return null;
	}
	return $code;
}

/**
 * Adiciona os campos comuns e assinatura de integridade a uma opção.
 *
 * @param string              $provider Identificador do provider.
 * @param array<string,mixed> $raw Resposta bruta de cotação do provider.
 * @param string              $quoted_at Data UTC obrigatória em que a opção foi gerada.
 * @param string|null         $expires_at Validade padrão; uma validade individual válida tem precedência.
 * @return array<string,mixed>|null Opção pública normalizada ou nula quando inválida.
 */
function papelito_shipping_normalize_provider_option( string $provider, array $raw, string $quoted_at, ?string $expires_at ): ?array {
	$provider      = sanitize_key( $provider );
	$code          = papelito_shipping_provider_service_code( $provider, $raw );
	$service       = papelito_shipping_provider_string( $raw['service'] ?? null );
	$name          = papelito_shipping_provider_string( $raw['name'] ?? null );
	$price_cents   = papelito_shipping_provider_price_cents( $raw );
	$delivery_time = papelito_shipping_provider_delivery_time( $raw );
	$carrier_cost  = papelito_shipping_provider_carrier_cost( $raw, $price_cents );
	$external_id   = array_key_exists( 'external_quote_id', $raw ) ? papelito_shipping_provider_nullable_string( $raw['external_quote_id'] ) : null;
	$option_expiry = papelito_shipping_provider_string( $raw['expires_at'] ?? null );
	if ( null !== $option_expiry && '' !== $option_expiry && false !== strtotime( $option_expiry ) ) {
		$expires_at = $option_expiry;
	}

	if ( ! in_array( $provider, PAPELITO_SHIPPING_PROVIDERS, true ) || null === $code || '' === $code || null === $service || '' === $service || null === $name || '' === $name || null === $price_cents || false === $delivery_time || null === $carrier_cost || false === $external_id || '' === $quoted_at ) {
		return null;
	}

	$option_key = papelito_shipping_provider_option_key( $provider, $code );
	return array(
		'provider'              => $provider,
		'option_key'            => $option_key,
		'service_code'          => $code,
		'service'               => $service,
		'code'                  => $code,
		'name'                  => $name,
		'carrier_cost_cents'    => $carrier_cost,
		'customer_price_cents'  => $price_cents,
		'price'                 => $price_cents / 100,
		'delivery_time'         => $delivery_time,
		'quoted_at'             => $quoted_at,
		'expires_at'            => papelito_shipping_nullable_date( $expires_at ),
		'external_quote_id'     => $external_id,
		'fingerprint'           => hash_hmac( 'sha256', wp_json_encode( array( $provider, $option_key, $price_cents, $delivery_time, papelito_shipping_nullable_date( $expires_at ) ) ), wp_salt( 'auth' ) ),
	);
}

/**
 * Normaliza as opções válidas do resultado bruto de um provider.
 *
 * @param string              $provider Identificador do provider.
 * @param array<string,mixed> $quote Envelope bruto com opções e contexto do provider.
 * @param string              $quoted_at Data UTC obrigatória da cotação.
 * @param string|null         $expires_at Data UTC opcional de expiração.
 * @return array{origin_cep:string,destination_cep:string,vendor_id:int,options:array<int,array<string,mixed>>} Envelope seguro com opções normalizadas.
 */
function papelito_shipping_normalize_provider_result( string $provider, array $quote, string $quoted_at, ?string $expires_at ): array {
	$quote_quoted_at = papelito_shipping_provider_string( $quote['quoted_at'] ?? null );
	$quote_expires_at = array_key_exists( 'expires_at', $quote )
		? papelito_shipping_provider_nullable_string( $quote['expires_at'] )
		: $expires_at;
	$quoted_at = null === $quote_quoted_at || '' === $quote_quoted_at ? $quoted_at : $quote_quoted_at;
	$expires_at = false === $quote_expires_at ? null : $quote_expires_at;
	$options = array();
	foreach ( is_array( $quote['options'] ?? null ) ? $quote['options'] : array() as $raw ) {
		$option = is_array( $raw ) ? papelito_shipping_normalize_provider_option( $provider, $raw, $quoted_at, $expires_at ) : null;
		if ( null !== $option ) {
			$options[] = $option;
		}
	}

	return array(
		'origin_cep'      => papelito_shipping_safe_string( $quote['origin_cep'] ?? '' ),
		'destination_cep' => papelito_shipping_safe_string( $quote['destination_cep'] ?? '' ),
		'vendor_id'       => isset( $quote['vendor_id'] ) ? (int) $quote['vendor_id'] : 0,
		'options'         => $options,
	);
}

/** Retorna uma string segura sem transportar valores brutos de providers. */
function papelito_shipping_safe_string( mixed $value ): string {
	return is_string( $value ) ? sanitize_text_field( $value ) : '';
}

/** Aceita somente strings textuais da resposta de um provider. */
function papelito_shipping_provider_string( mixed $value ): ?string {
	return is_string( $value ) ? papelito_shipping_safe_string( $value ) : null;
}

/** Aceita ID externo nulo ou string segura e converte string vazia em nulo. */
function papelito_shipping_provider_nullable_string( mixed $value ) {
	if ( null === $value ) {
		return null;
	}
	$string = papelito_shipping_provider_string( $value );
	if ( null === $string ) {
		return false;
	}

	return '' === $string ? null : $string;
}

/** Recusa preço não finito ou fora do intervalo inteiro antes de converter centavos. */
function papelito_shipping_provider_decimal_cents( mixed $price ): ?int {
	if ( ( ! is_int( $price ) && ! is_float( $price ) ) || ! is_finite( (float) $price ) || $price < 0 ) {
		return null;
	}
	$cents = round( $price * 100 );
	if ( ! is_finite( $cents ) || $cents >= PHP_INT_MAX ) {
		return null;
	}
	return (int) $cents;
}

/** Valida cada representação monetária fornecida antes de escolher os centavos canônicos. */
function papelito_shipping_provider_price_cents( array $raw ): ?int {
	$has_customer_cents = array_key_exists( 'customer_price_cents', $raw );
	$has_decimal_price  = array_key_exists( 'price', $raw );
	$decimal_cents      = papelito_shipping_provider_decimal_cents( $raw['price'] ?? null );
	if ( $has_customer_cents && ( ! is_int( $raw['customer_price_cents'] ) || $raw['customer_price_cents'] < 0 ) ) {
		return null;
	}
	if ( $has_decimal_price && null === $decimal_cents ) {
		return null;
	}
	if ( $has_customer_cents ) {
		return $raw['customer_price_cents'];
	}
	return $decimal_cents;
}

/** Aceita custo transportador inteiro ou reutiliza o preço canônico. */
function papelito_shipping_provider_carrier_cost( array $raw, ?int $price_cents ): ?int {
	if ( ! array_key_exists( 'carrier_cost_cents', $raw ) ) {
		return $price_cents;
	}
	return is_int( $raw['carrier_cost_cents'] ) && $raw['carrier_cost_cents'] >= 0 ? $raw['carrier_cost_cents'] : null;
}

/** Aceita prazo inteiro positivo ou nulo, sem permitir coerção de arrays. */
function papelito_shipping_provider_delivery_time( array $raw ) {
	if ( ! array_key_exists( 'delivery_time', $raw ) || null === $raw['delivery_time'] ) {
		return null;
	}
	return is_int( $raw['delivery_time'] ) && $raw['delivery_time'] >= 0 ? $raw['delivery_time'] : false;
}

/** Converte uma data opcional em valor seguro para o envelope público. */
function papelito_shipping_nullable_date( ?string $value ): ?string {
	return null === $value || '' === $value ? null : papelito_shipping_safe_string( $value );
}

/** Classifica o resultado bruto de um provider como sucesso, pulo ou falha segura. */
function papelito_shipping_provider_outcome( string $provider, mixed $result ): array {
	$provider = sanitize_key( $provider );
	if ( null === $result ) {
		return array( 'provider' => $provider, 'status' => 'skipped' );
	}
	if ( is_array( $result ) ) {
		return array( 'provider' => $provider, 'status' => 'success', 'result' => $result );
	}
	if ( is_wp_error( $result ) ) {
		$category = papelito_shipping_failure_category( $provider, $result->get_error_code() );
		$code     = in_array( $result->get_error_code(), PAPELITO_SHIPPING_LOCAL_VALIDATION_CODES, true ) ? $result->get_error_code() : $category;
		$data     = papelito_shipping_public_error_data( $code, $result->get_error_data() );
		return array( 'provider' => $provider, 'status' => 'failure', 'code' => $category, 'public_code' => $code, 'http_status' => $data['status'], 'public_data' => $data );
	}
	return array( 'provider' => $provider, 'status' => 'failure', 'code' => 'invalid_response', 'http_status' => 502 );
}

/** Expõe status e somente os valores físicos documentados de uma validação local reconhecida. */
function papelito_shipping_public_error_data( string $code, mixed $data ): array {
	$data = is_array( $data ) ? $data : array();
	$safe = array( 'status' => isset( $data['status'] ) ? absint( $data['status'] ) : 503 );
	if ( 'papelito_shipping_package_exceeds_limits' !== $code ) {
		return $safe;
	}
	$allowed = array(
		'limit'              => array( 'dimension', 'dimension_sum', 'weight' ),
		'measurement_source' => array( 'legacy_synthetic', 'kit_declared' ),
	);
	foreach ( $allowed as $key => $values ) {
		if ( in_array( $data[ $key ] ?? null, $values, true ) ) {
			$safe[ $key ] = $data[ $key ];
		}
	}
	return $safe;
}

/** Traduz códigos externos de erro para a taxonomia interna fechada. */
function papelito_shipping_failure_category( string $provider, string $code ): string {
	if ( in_array( $code, PAPELITO_SHIPPING_LOCAL_VALIDATION_CODES, true ) ) {
		return 'validation_error';
	}
	$code = sanitize_key( $code );
	$braspress_prefix = strpos( $code, 'braspress_' );
	if ( PAPELITO_SHIPPING_PROVIDER_BRASPRESS === $provider && false !== $braspress_prefix ) {
		$code = substr( $code, $braspress_prefix + strlen( 'braspress_' ) );
	}
	if ( in_array( $code, PAPELITO_SHIPPING_FAILURE_CATEGORIES, true ) ) {
		return $code;
	}
	foreach ( array( 'configuration' => 'configuration_error', 'validation' => 'validation_error', 'timeout' => 'timeout', 'network' => 'network_error', 'rate' => 'rate_limited', '4xx' => 'provider_4xx', '5xx' => 'provider_5xx', 'invalid' => 'invalid_response' ) as $needle => $category ) {
		if ( str_contains( $code, $needle ) ) {
			return $category;
		}
	}
	return 'unknown_error';
}

/**
 * Classifica o resultado de um provider para o agregador.
 *
 * Só `failure` e `success` com envelope de array têm efeito; `skipped`, status
 * desconhecido e sucesso sem corpo caem em `ignore` e somem da agregação.
 *
 * @param mixed $outcome Resultado bruto devolvido por um provider.
 * @return string `failure`, `success` ou `ignore`.
 */
function papelito_shipping_provider_outcome_kind( mixed $outcome ): string {
	if ( ! is_array( $outcome ) ) {
		return 'ignore';
	}

	$status = $outcome['status'] ?? '';
	if ( 'failure' === $status ) {
		return 'failure';
	}

	if ( 'success' === $status && is_array( $outcome['result'] ?? null ) ) {
		return 'success';
	}

	return 'ignore';
}

/**
 * Funde um resultado normalizado no agregado.
 *
 * Origem, destino e vendor só são sobrescritos por valor preenchido — provider
 * que responde sem esses campos não pode apagar o que outro já informou.
 *
 * @param array<string,mixed> $result     Agregado corrente.
 * @param array<string,mixed> $normalized Resultado normalizado de um provider.
 * @return array<string,mixed>
 */
function papelito_shipping_merge_provider_result( array $result, array $normalized ): array {
	if ( '' !== $normalized['origin_cep'] ) {
		$result['origin_cep'] = $normalized['origin_cep'];
	}
	if ( '' !== $normalized['destination_cep'] ) {
		$result['destination_cep'] = $normalized['destination_cep'];
	}
	if ( 0 !== $normalized['vendor_id'] ) {
		$result['vendor_id'] = $normalized['vendor_id'];
	}
	$result['options'] = array_merge( $result['options'], $normalized['options'] );

	return $result;
}

/**
 * Converte a primeira falha registrada no erro que o cliente vê.
 *
 * Mantém a identidade pública de validações locais conhecidas, separada da
 * categoria interna. Mensagem e dados técnicos do provider nunca atravessam.
 *
 * @param mixed $first_failure Primeiro resultado de falha, se houve algum.
 * @return WP_Error
 */
function papelito_shipping_provider_aggregate_failure( mixed $first_failure ): WP_Error {
	$failure = is_array( $first_failure ) ? $first_failure : array();
	$code = $failure['public_code'] ?? $failure['code'] ?? 'unknown_error';
	if ( ! in_array( $code, array_merge( PAPELITO_SHIPPING_LOCAL_VALIDATION_CODES, PAPELITO_SHIPPING_FAILURE_CATEGORIES ), true ) ) {
		$code = 'unknown_error';
	}
	$data = is_array( $failure['public_data'] ?? null ) ? $failure['public_data'] : array();
	$data['status'] = (int) ( $failure['http_status'] ?? 503 );

	return new WP_Error(
		$code,
		'Não foi possível cotar o frete.',
		papelito_shipping_public_error_data( $code, $data )
	);
}

/** Agrega somente opções canônicas e esconde detalhes de falha dos providers. */
function papelito_shipping_aggregate_provider_results( array $outcomes, string $quoted_at, ?string $expires_at ) {
	$result        = array( 'origin_cep' => '', 'destination_cep' => '', 'vendor_id' => 0, 'options' => array() );
	$first_failure = null;

	foreach ( $outcomes as $outcome ) {
		$kind = papelito_shipping_provider_outcome_kind( $outcome );
		if ( 'failure' === $kind ) {
			$first_failure = $first_failure ?? $outcome;
			continue;
		}
		if ( 'success' !== $kind ) {
			continue;
		}
		$normalized = papelito_shipping_normalize_provider_result( (string) ( $outcome['provider'] ?? '' ), $outcome['result'], $quoted_at, $expires_at );
		$result     = papelito_shipping_merge_provider_result( $result, $normalized );
	}

	if ( ! empty( $result['options'] ) ) {
		return $result;
	}

	return papelito_shipping_provider_aggregate_failure( $first_failure );
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
 * Lê o prazo declarado numa opção cotada, em dias inteiros.
 *
 * @param array<string,mixed> $option Opção cotada.
 * @return int|null Prazo em dias ou nulo quando a opção não declara prazo.
 */
function papelito_shipping_option_delivery_time( array $option ): ?int {
	if ( ! isset( $option['delivery_time'] ) || null === $option['delivery_time'] ) {
		return null;
	}

	return absint( $option['delivery_time'] );
}

/**
 * Compara o prazo do snapshot com o da opção recotada.
 *
 * Ausência dos dois lados combina. Prazo não numérico no snapshot nunca combina:
 * é sinal de snapshot adulterado, não de cotação sem prazo.
 *
 * @param mixed    $expected Prazo como veio no snapshot do checkout.
 * @param int|null $actual   Prazo da opção recotada.
 * @return bool
 */
function papelito_shipping_snapshot_delivery_matches( mixed $expected, ?int $actual ): bool {
	if ( null === $expected ) {
		return null === $actual;
	}

	return is_numeric( $expected ) && absint( $expected ) === $actual;
}

/**
 * Lê a marca de validade de uma opção ou de um snapshot.
 *
 * @param array<string,mixed> $source Opção cotada ou snapshot do checkout.
 * @return string|null Marca saneada ou nulo quando a cotação não expira.
 */
function papelito_shipping_snapshot_expiry( array $source ): ?string {
	if ( ! isset( $source['expires_at'] ) || null === $source['expires_at'] ) {
		return null;
	}

	return sanitize_text_field( (string) $source['expires_at'] );
}

/**
 * Diz se uma marca de validade já venceu.
 *
 * Texto que o PHP não consegue interpretar não vence — o desencontro entre
 * snapshot e recotação é quem recusa o pedido nesse caso.
 *
 * @param string $expiry Marca de validade saneada.
 * @return bool
 */
function papelito_shipping_expiry_is_past( string $expiry ): bool {
	$timestamp = strtotime( $expiry );

	return false !== $timestamp && $timestamp <= time();
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

	if ( ! papelito_shipping_snapshot_delivery_matches( $expected['delivery_time'] ?? null, papelito_shipping_option_delivery_time( $option ) ) ) {
		return false;
	}

	$actual_expiry = papelito_shipping_snapshot_expiry( $option );
	if ( null !== $actual_expiry && papelito_shipping_expiry_is_past( $actual_expiry ) ) {
		return false;
	}

	return papelito_shipping_snapshot_expiry( $expected ) === $actual_expiry;
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
 * Lê configuração de provider na ordem constante, `papelito_env()` e ambiente.
 *
 * A constante vence porque é o que os testes standalone e o `wp-config.php` de
 * produção definem; `getenv()` é o último recurso, para quando o bootstrap do
 * plugin ainda não subiu.
 *
 * @param string $name     Nome da constante, que é também o da variável de ambiente.
 * @param string $fallback Valor devolvido por `papelito_env()` quando nada está definido.
 * @return mixed Valor cru da primeira fonte que responder.
 */
function papelito_shipping_provider_config( string $name, string $fallback ) {
	if ( defined( $name ) ) {
		return constant( $name );
	}

	if ( function_exists( 'papelito_env' ) ) {
		return papelito_env( $name, $fallback );
	}

	return getenv( $name );
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

	$value = papelito_shipping_provider_config( 'PAPELITO_BRASPRESS_ENABLED', 'false' );

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

	$raw = (string) papelito_shipping_provider_config( 'PAPELITO_BRASPRESS_VENDOR_ALLOWLIST', '' );
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
 * Erros reais da Braspress propagam para a agregação; nulo é somente pulo de elegibilidade.
 *
 * @param int                      $vendor_id ID do vendor.
 * @param string                   $destination_cep CEP de destino.
 * @param array<int,array<string,mixed>> $items Itens resolvidos.
 * @param array<string,mixed>      $context Dados autoritativos de precificação e destinatário.
 * @return array<string,mixed>|WP_Error|null Cotação, erro real propagado ou nulo no pulo de elegibilidade.
 */
function papelito_shipping_quote_braspress( int $vendor_id, string $destination_cep, array $items, array $context = array() ) {
	if ( ! papelito_shipping_feature_enabled( PAPELITO_SHIPPING_PROVIDER_BRASPRESS ) || ! papelito_shipping_provider_vendor_allowed( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, $vendor_id ) ) {
		return null;
	}

	$integration = papelito_vendor_integration_resolve_braspress( $vendor_id );
	if ( null === $integration ) {
		return null;
	}
	if ( is_wp_error( $integration ) ) {
		return $integration;
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
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
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
	$quoted_at = current_time( 'mysql', true );

	return papelito_shipping_aggregate_provider_results(
		array(
			papelito_shipping_provider_outcome( PAPELITO_SHIPPING_PROVIDER_CORREIOS, $correios ),
			papelito_shipping_provider_outcome( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, $braspress ),
		),
		$quoted_at,
		null
	);
}
