<?php
/**
 * Cotacao headless de frete via Correios.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PAPELITO_SHIPPING_FREE_SHIPPING_MINIMUM_OPTION        = 'papelito_shipping_free_shipping_minimum_cents';
const PAPELITO_SHIPPING_DEFAULT_FREE_SHIPPING_MINIMUM_CENTS = 9900;
const PAPELITO_CORREIOS_JSON_CONTENT_TYPE                   = 'application/json';
const PAPELITO_REST_NAMESPACE                               = 'papelito/v1';

/**
 * Normaliza o mínimo de frete grátis vindo de origem não confiável.
 *
 * @param mixed $value Valor cru da option ou do payload REST.
 * @return int|null Centavos positivos ou null quando o valor é inválido.
 */
function papelito_shipping_normalize_free_shipping_minimum_cents( mixed $value ): ?int {
	if ( is_int( $value ) && $value > 0 ) {
		return $value;
	}

	if ( is_string( $value ) && 1 === preg_match( '/^[1-9]\d*$/', $value ) ) {
		$normalized = (int) $value;
		return $normalized > 0 ? $normalized : null;
	}

	return null;
}

function papelito_shipping_get_free_shipping_minimum_cents(): int {
	$value = get_option(
		PAPELITO_SHIPPING_FREE_SHIPPING_MINIMUM_OPTION,
		PAPELITO_SHIPPING_DEFAULT_FREE_SHIPPING_MINIMUM_CENTS
	);

	return papelito_shipping_normalize_free_shipping_minimum_cents( $value )
		?? PAPELITO_SHIPPING_DEFAULT_FREE_SHIPPING_MINIMUM_CENTS;
}

// TODO(frete-gratis): definir estratégia de concessão do benefício após atingir o valor mínimo configurado. Avaliar geração automática de cupom versus fluxo manual via admin/vendor.
function papelito_shipping_get_free_shipping_threshold_snapshot(): array {
	return array(
		'minimumOrderCents' => papelito_shipping_get_free_shipping_minimum_cents(),
	);
}

/**
 * Persiste o mínimo de frete grátis, recusando qualquer valor que não seja inteiro positivo.
 *
 * @param mixed $value Valor cru do payload REST.
 * @return array<string, int>|WP_Error
 */
function papelito_shipping_update_free_shipping_minimum_cents( mixed $value ) {
	if ( ! is_int( $value ) || $value <= 0 ) {
		return new WP_Error(
			'papelito_shipping_invalid_free_shipping_minimum',
			'Informe um valor mínimo positivo em centavos.',
			array( 'status' => 422 )
		);
	}

	update_option( PAPELITO_SHIPPING_FREE_SHIPPING_MINIMUM_OPTION, $value, false );

	return papelito_shipping_get_free_shipping_threshold_snapshot();
}

function papelito_shipping_require_admin() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	return new WP_Error(
		'papelito_shipping_forbidden',
		'Acesso administrativo necessário.',
		array( 'status' => 403 )
	);
}

/**
 * Le uma variavel de ambiente/constante de Correios.
 *
 * @param string $key Nome sem prefixo PAPELITO_CORREIOS_.
 * @param string $default Valor padrao.
 * @return string
 */
function papelito_correios_env( string $key, string $default = '' ): string {
	$constant = 'PAPELITO_CORREIOS_' . $key;

	if ( defined( $constant ) ) {
		return trim( (string) constant( $constant ) );
	}

	$value = getenv( $constant );

	if ( false === $value || '' === trim( (string) $value ) ) {
		return $default;
	}

	return trim( (string) $value );
}

/**
 * Normaliza CEP para 8 digitos.
 *
 * @param mixed $value Valor informado.
 * @return string
 */
function papelito_shipping_normalize_cep( $value ): string {
	$digits = preg_replace( '/\D+/', '', sanitize_text_field( (string) $value ) );

	return is_string( $digits ) && 8 === strlen( $digits ) ? $digits : '';
}

/**
 * Converte valor monetario retornado pela API.
 *
 * @param mixed $value Valor da API.
 * @return float
 */
function papelito_shipping_parse_money( $value ): float {
	if ( is_numeric( $value ) ) {
		return round( (float) $value, 2 );
	}

	$text       = trim( (string) $value );
	$normalized = false !== strpos( $text, ',' )
		? str_replace( array( '.', ',' ), array( '', '.' ), $text )
		: $text;

	return round( max( 0, (float) $normalized ), 2 );
}

/**
 * Retorna a primeira chave existente em um array.
 *
 * @param array<int|string, mixed> $data Dados da API.
 * @param array<int, string>       $keys Chaves candidatas.
 * @return mixed|null
 */
function papelito_shipping_first_value( array $data, array $keys ) {
	foreach ( $keys as $key ) {
		if ( array_key_exists( $key, $data ) && null !== $data[ $key ] && '' !== $data[ $key ] ) {
			return $data[ $key ];
		}
	}

	return null;
}

/**
 * Retorna as credenciais configuradas.
 *
 * @return array<string, string>|WP_Error
 */
function papelito_correios_credentials() {
	$credentials = array(
		'username'     => papelito_correios_env( 'USERNAME' ),
		'access_code'  => papelito_correios_env( 'ACCESS_CODE' ),
		'posting_card' => papelito_correios_env( 'POSTING_CARD' ),
		'contract'     => papelito_correios_env( 'CONTRACT' ),
		'environment'  => papelito_correios_env( 'ENV', 'production' ),
	);

	if ( '' === $credentials['username'] || '' === $credentials['access_code'] || '' === $credentials['posting_card'] ) {
		return new WP_Error(
			'papelito_correios_missing_credentials',
			'Credenciais dos Correios não configuradas.',
			array( 'status' => 500 )
		);
	}

	if ( 1 !== preg_match( '/^00\d{8}$/', $credentials['posting_card'] ) ) {
		return new WP_Error(
			'papelito_correios_invalid_card',
			'Cartao de postagem dos Correios inválido. Use 10 digitos iniciando com 00.',
			array( 'status' => 500 )
		);
	}

	return $credentials;
}

/**
 * Retorna a base da API dos Correios.
 *
 * @param string $environment Ambiente configurado.
 * @return string
 */
function papelito_correios_base_url( string $environment ): string {
	return 'staging' === $environment ? 'https://apihom.correios.com.br/' : 'https://api.correios.com.br/';
}

/**
 * Extrai uma mensagem segura da resposta dos Correios.
 *
 * @param mixed $body Corpo decodificado.
 * @return string
 */
function papelito_correios_response_message( $body ): string {
	if ( ! is_array( $body ) ) {
		return '';
	}

	$message = papelito_shipping_first_value( $body, array( 'msg', 'message', 'mensagem', 'erro' ) );

	if ( empty( $message ) && ! empty( $body['msgs'] ) && is_array( $body['msgs'] ) ) {
		$message = reset( $body['msgs'] );
	}

	if ( empty( $message ) && ! empty( $body['messages'] ) && is_array( $body['messages'] ) ) {
		$message = reset( $body['messages'] );
	}

	if ( is_array( $message ) ) {
		$message = papelito_shipping_first_value( $message, array( 'msg', 'message', 'mensagem', 'texto' ) );
	}

	return sanitize_text_field( (string) $message );
}

/**
 * Extrai um contexto seguro de erro dos Correios.
 *
 * @param WP_Error $error Erro retornado.
 * @return array<string, mixed>
 */
function papelito_correios_extract_error_data( WP_Error $error ): array {
	$data = $error->get_error_data();

	if ( ! is_array( $data ) ) {
		return array();
	}

	$result = array();

	if ( isset( $data['status'] ) ) {
		$result['status'] = absint( $data['status'] );
	}

	if ( isset( $data['correios_status'] ) ) {
		$result['correios_status'] = absint( $data['correios_status'] );
	}

	if ( ! empty( $data['correios_message'] ) ) {
		$result['correios_message'] = sanitize_text_field( (string) $data['correios_message'] );
	}

	return $result;
}

/**
 * Calcula espera curta para rate limit dos Correios.
 *
 * @param array<string, mixed> $response Resposta HTTP.
 * @return float Segundos.
 */
function papelito_correios_retry_after_seconds( array $response ): float {
	$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

	if ( is_array( $retry_after ) ) {
		$retry_after = reset( $retry_after );
	}

	if ( is_numeric( $retry_after ) ) {
		return min( 3.0, max( 0.5, (float) $retry_after ) );
	}

	if ( is_string( $retry_after ) && '' !== $retry_after ) {
		$timestamp = strtotime( $retry_after );
		if ( false !== $timestamp ) {
			return min( 3.0, max( 0.5, (float) ( $timestamp - time() ) ) );
		}
	}

	return 0.5;
}

/**
 * Cria uma impressão não reversível para chaves de cache.
 *
 * @param string $value Valor que diferencia o cache.
 * @return string
 */
function papelito_shipping_cache_fingerprint( string $value ): string {
	return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
}

/**
 * Executa request JSON na API dos Correios.
 *
 * @param string               $method Metodo HTTP.
 * @param string               $url URL completa.
 * @param array<string, mixed> $args Args adicionais.
 * @return array<string, mixed>|WP_Error
 */
function papelito_correios_request_json( string $method, string $url, array $args = array() ) {
	$request_args = array_merge(
		array(
			'timeout' => 30,
			'headers' => array(
				'Accept' => PAPELITO_CORREIOS_JSON_CONTENT_TYPE,
			),
		),
		$args
	);

	$attempts = 0;

	do {
		$response = 'POST' === strtoupper( $method )
			? wp_safe_remote_post( $url, $request_args )
			: wp_safe_remote_get( $url, $request_args );

		if ( ! is_wp_error( $response ) && 429 === (int) wp_remote_retrieve_response_code( $response ) && 0 === $attempts ) {
			usleep( (int) ( papelito_correios_retry_after_seconds( $response ) * 1000000 ) );
			++$attempts;
			continue;
		}

		break;
	} while ( $attempts < 2 );

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'papelito_correios_unreachable',
			'Não foi possível conectar aos Correios.',
			array( 'status' => 502 )
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		$error_data = array( 'status' => 502, 'correios_status' => $status );
		$message    = papelito_correios_response_message( $body );

		if ( '' !== $message ) {
			$error_data['correios_message'] = $message;
		}

		return new WP_Error(
			'papelito_correios_bad_response',
			'Correios respondeu com erro durante a cotação.',
			$error_data
		);
	}

	return $body;
}

/**
 * Obtem token Bearer da API dos Correios.
 *
 * @param array<string, string> $credentials Credenciais.
 * @return array<string, mixed>|WP_Error
 */
function papelito_correios_get_token( array $credentials ) {
	$transient = 'papelito_correios_token_' . papelito_shipping_cache_fingerprint( implode( '|', array( $credentials['environment'], $credentials['username'], $credentials['posting_card'] ) ) );
	$cached    = get_transient( $transient );

	if ( is_string( $cached ) && '' !== $cached ) {
		$data = json_decode( $cached, true );
		if ( is_array( $data ) && ! empty( $data['token'] ) ) {
			return $data;
		}
	}

	$url  = papelito_correios_base_url( $credentials['environment'] ) . 'token/v1/autentica/cartaopostagem';
	$data = papelito_correios_request_json(
		'POST',
		$url,
		array(
			'body'    => wp_json_encode( array( 'numero' => $credentials['posting_card'] ) ),
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['access_code'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Accept'        => PAPELITO_CORREIOS_JSON_CONTENT_TYPE,
				'Content-Type'  => PAPELITO_CORREIOS_JSON_CONTENT_TYPE,
			),
		)
	);

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	if ( empty( $data['token'] ) ) {
		return new WP_Error(
			'papelito_correios_missing_token',
			'Correios não retornou token de autenticação.',
			array( 'status' => 502 )
		);
	}

	$expires_in = HOUR_IN_SECONDS;
	if ( ! empty( $data['expiraEm'] ) ) {
		$expires_at = strtotime( (string) $data['expiraEm'] );
		if ( false !== $expires_at ) {
			$expires_in = max( 300, $expires_at - time() - ( 30 * MINUTE_IN_SECONDS ) );
		}
	}

	set_transient( $transient, wp_json_encode( $data ), $expires_in );

	return $data;
}

/**
 * Retorna os codigos principais usados no checkout.
 *
 * @return array<string, string>
 */
function papelito_correios_primary_service_codes(): array {
	$codes = apply_filters(
		'papelito_correios_primary_service_codes',
		array(
			'PAC'   => '03298',
			'SEDEX' => '03220',
		)
	);

	if ( ! is_array( $codes ) ) {
		$codes = array();
	}

	return array(
		'PAC'   => isset( $codes['PAC'] ) ? sanitize_text_field( (string) $codes['PAC'] ) : '03298',
		'SEDEX' => isset( $codes['SEDEX'] ) ? sanitize_text_field( (string) $codes['SEDEX'] ) : '03220',
	);
}

/**
 * Classifica o servico Correios por codigo oficial quando possivel.
 *
 * @param string $code Codigo do servico.
 * @return string
 */
function papelito_correios_service_type_from_code( string $code ): string {
	$codes = papelito_correios_primary_service_codes();

	foreach ( $codes as $service => $service_code ) {
		if ( $service_code === $code ) {
			return $service;
		}
	}

	return '';
}

/**
 * Indica se o servico dos Correios pode aparecer no checkout.
 *
 * @param string $name Nome do servico.
 * @return bool
 */
function papelito_correios_is_checkout_service( string $name ): bool {
	$normalized = remove_accents( strtolower( $name ) );
	$blocked    = apply_filters(
		'papelito_correios_checkout_blocked_service_terms',
		array(
			'empacotamento',
			'grand formato',
			'log+',
			'locker',
			'log +',
			'logistica',
			'packet',
			'pagto',
			'pagto entrega',
			'pgto entrega',
			'reverso',
			'reversa',
		)
	);

	foreach ( $blocked as $term ) {
		if ( false !== strpos( $normalized, remove_accents( strtolower( (string) $term ) ) ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Classifica o servico Correios como PAC/SEDEX quando for uma modalidade clara.
 *
 * @param string $name Nome do servico.
 * @return string
 */
function papelito_correios_service_type_from_name( string $name ): string {
	$normalized = remove_accents( strtolower( $name ) );

	if ( 1 === preg_match( '/(^|[^a-z0-9])sedex([^a-z0-9]|$)/', $normalized ) ) {
		return 'SEDEX';
	}

	if ( 1 === preg_match( '/(^|[^a-z0-9])pac([^a-z0-9]|$)/', $normalized ) ) {
		return 'PAC';
	}

	return '';
}

/**
 * Classifica o servico Correios por codigo oficial ou nome.
 *
 * @param string $code Codigo do servico.
 * @param string $name Nome do servico.
 * @return string
 */
function papelito_correios_service_type( string $code, string $name ): string {
	$service = papelito_correios_service_type_from_code( $code );

	if ( '' !== $service ) {
		return $service;
	}

	return papelito_correios_service_type_from_name( $name );
}

/**
 * Retorna o prazo de entrega de uma cotação, preservando nulos no fim da ordem.
 *
 * @param array<string, mixed> $option Cotação.
 * @return int
 */
function papelito_correios_quoted_option_delivery_time( array $option ): int {
	return isset( $option['delivery_time'] ) && null !== $option['delivery_time']
		? absint( $option['delivery_time'] )
		: PHP_INT_MAX;
}

/**
 * Indica se uma cotação é preferível por preço e, no empate, por prazo.
 *
 * @param array<string, mixed> $candidate Candidata.
 * @param array<string, mixed> $current Cotação atual.
 * @return bool
 */
function papelito_correios_is_better_quoted_option( array $candidate, array $current ): bool {
	$candidate_price = (float) ( $candidate['price'] ?? 0 );
	$current_price   = (float) ( $current['price'] ?? 0 );

	return $candidate_price < $current_price
		|| ( $candidate_price === $current_price && papelito_correios_quoted_option_delivery_time( $candidate ) < papelito_correios_quoted_option_delivery_time( $current ) );
}

/**
 * Escolhe uma única opção por modalidade: menor preço, depois menor prazo.
 *
 * @param array<int, array<string, mixed>> $options Opções cotadas.
 * @return array<int, array<string, mixed>>
 */
function papelito_correios_select_best_quoted_options( array $options ): array {
	$best_by_service = array();

	foreach ( $options as $option ) {
		$service = isset( $option['service'] ) ? (string) $option['service'] : '';

		if ( '' !== $service && ( ! isset( $best_by_service[ $service ] ) || papelito_correios_is_better_quoted_option( $option, $best_by_service[ $service ] ) ) ) {
			$best_by_service[ $service ] = $option;
		}
	}

	return array_values( $best_by_service );
}

/**
 * Limita a lista de servicos a PAC e SEDEX antes de cotar preco/prazo.
 *
 * @param array<int, array<string, mixed>> $services Servicos normalizados.
 * @return array<int, array<string, mixed>>
 */
function papelito_correios_select_checkout_services( array $services ): array {
	$primary_codes = papelito_correios_primary_service_codes();
	$grouped       = array(
		'PAC'   => array(),
		'SEDEX' => array(),
	);
	$selected      = array();

	foreach ( $services as $service ) {
		$type = isset( $service['service'] ) ? (string) $service['service'] : '';

		if ( isset( $grouped[ $type ] ) ) {
			$grouped[ $type ][] = $service;
		}
	}

	foreach ( array( 'PAC', 'SEDEX' ) as $type ) {
		if ( empty( $grouped[ $type ] ) ) {
			continue;
		}

		$primary_code = $primary_codes[ $type ] ?? '';
		$match        = null;

		foreach ( $grouped[ $type ] as $service ) {
			if ( $primary_code === (string) ( $service['code'] ?? '' ) ) {
				$match = $service;
				break;
			}
		}

		$selected[] = is_array( $match ) ? $match : $grouped[ $type ][0];
	}

	return $selected;
}

/**
 * Normaliza um serviço elegível retornado pelo contrato dos Correios.
 *
 * @param mixed $item Item retornado pela API.
 * @return array<string, string>|null
 */
function papelito_correios_contract_service( $item ): ?array {
	if ( ! is_array( $item ) || empty( $item['codigo'] ) ) {
		return null;
	}

	$code    = sanitize_text_field( (string) $item['codigo'] );
	$name    = isset( $item['descricao'] ) ? sanitize_text_field( (string) $item['descricao'] ) : $code;
	$service = papelito_correios_service_type( $code, $name );

	if ( '' === $service || ! papelito_correios_is_checkout_service( $name ) ) {
		return null;
	}

	return array(
		'service' => $service,
		'code'    => $code,
		'name'    => $name,
	);
}

/**
 * Lê os serviços de checkout já cacheados, descartando payload corrompido ou vazio.
 *
 * @param string $transient Chave do transient.
 * @return array<int, array<string, string>>|null
 */
function papelito_correios_cached_services( string $transient ): ?array {
	$cached = get_transient( $transient );

	if ( ! is_string( $cached ) || '' === $cached ) {
		return null;
	}

	$services = json_decode( $cached, true );

	return is_array( $services ) && ! empty( $services ) ? $services : null;
}

/**
 * Consulta e normaliza os serviços de checkout disponíveis no contrato.
 *
 * @param array<string, string> $credentials Credenciais.
 * @param array<string, mixed>  $token Token dos Correios.
 * @return array<int, array<string, string>>|WP_Error
 */
function papelito_correios_get_services( array $credentials, array $token ) {
	$contract = ! empty( $token['cartaoPostagem']['contrato'] )
		? (string) $token['cartaoPostagem']['contrato']
		: $credentials['contract'];
	$card     = ! empty( $token['cartaoPostagem']['numero'] )
		? (string) $token['cartaoPostagem']['numero']
		: $credentials['posting_card'];

	if ( empty( $token['cnpj'] ) || '' === $contract || '' === $card ) {
		return new WP_Error(
			'papelito_correios_contract_missing',
			'Contrato ou cartao de postagem dos Correios indisponível.',
			array( 'status' => 502 )
		);
	}

	$transient = 'papelito_correios_services_v5_' . papelito_shipping_cache_fingerprint( implode( '|', array( $credentials['environment'], $token['cnpj'], $contract, $card ) ) );
	$cached    = papelito_correios_cached_services( $transient );

	if ( null !== $cached ) {
		return $cached;
	}

	$endpoint = implode(
		'/',
		array(
			'meucontrato',
			'v1',
			'empresas',
			rawurlencode( (string) $token['cnpj'] ),
			'contratos',
			rawurlencode( $contract ),
			'cartoes',
			rawurlencode( $card ),
			'servicos',
		)
	);
	$url      = papelito_correios_base_url( $credentials['environment'] ) . $endpoint . '?page=0&size=500';
	$data     = papelito_correios_request_json(
		'GET',
		$url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token['token'],
				'Accept'        => PAPELITO_CORREIOS_JSON_CONTENT_TYPE,
			),
		)
	);

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$items    = isset( $data['itens'] ) && is_array( $data['itens'] ) ? $data['itens'] : array();
	$services = array();

	foreach ( $items as $item ) {
		$service = papelito_correios_contract_service( $item );

		if ( is_array( $service ) ) {
			$services[] = $service;
		}
	}

	if ( empty( $services ) ) {
		return new WP_Error(
			'papelito_correios_services_missing',
			'Nenhum serviço PAC/SEDEX disponível no contrato dos Correios.',
			array( 'status' => 502 )
		);
	}

	$services = papelito_correios_select_checkout_services( $services );

	set_transient( $transient, wp_json_encode( $services ), 12 * HOUR_IN_SECONDS );

	return $services;
}

/**
 * Monta pacote de produtos para cotacao.
 *
 * @param array<int, array<string, mixed>> $items Itens do carrinho.
 * @return array<string, float>|WP_Error
 */
/**
 * Monta o pacote de um Kit isolado ou retorna nulo quando o carrinho não o contém.
 *
 * @param array<int, array<string, mixed>> $items Itens do carrinho.
 * @return array<string, float>|WP_Error|null
 */
function papelito_shipping_build_kit_package( array $items ) {
	$kit_items = array();
	if ( ! function_exists( 'papelito_kit_is_product' ) ) {
		return null;
	}

	foreach ( $items as $item ) {
		$product_id = absint( $item['product_id'] ?? 0 );
		if ( $product_id > 0 && papelito_kit_is_product( $product_id ) ) {
			$kit_items[] = $item;
		}
	}

	if ( empty( $kit_items ) ) {
		return null;
	}

	$kit_item = $kit_items[0];
	if ( 1 !== count( $items ) || 1 !== count( $kit_items ) || max( 1, absint( $kit_item['qty'] ?? 1 ) ) !== 1 ) {
		return new WP_Error( 'papelito_shipping_kit_package_not_supported', 'A cotação de Kit exige uma única unidade, sem produtos adicionais no carrinho.', array( 'status' => 422 ) );
	}

	$logistics = papelito_kit_logistics( absint( $kit_item['product_id'] ), 1 );
	if ( is_wp_error( $logistics ) ) {
		return $logistics;
	}

	$kit_product = wc_get_product( absint( $kit_item['product_id'] ) );

	return array(
		'weight' => $logistics['weight'],
		'length' => $logistics['length'],
		'width'  => $logistics['width'],
		'height' => $logistics['height'],
		'value'  => $kit_product ? (float) $kit_product->get_price() : 0.0,
	);
}

/**
 * Inicializa os acumuladores de peso, dimensões e valor do pacote.
 *
 * @return array<string, float>
 */
function papelito_shipping_empty_package_totals(): array {
	return array(
		'weight' => 0.0,
		'length' => 0.0,
		'width'  => 0.0,
		'height' => 0.0,
		'value'  => 0.0,
	);
}

/**
 * Soma um brinde físico ao pacote.
 *
 * @param array<string, float> $totals Acumuladores atuais.
 * @param array<string, mixed> $merchandise Brinde físico.
 * @return array<string, float>|WP_Error
 */
function papelito_shipping_add_merchandise_to_package( array $totals, array $merchandise ) {
	$weight = (float) ( $merchandise['weight'] ?? 0 );
	$length = (float) ( $merchandise['length'] ?? 0 );
	$width  = (float) ( $merchandise['width'] ?? 0 );
	$height = (float) ( $merchandise['height'] ?? 0 );
	$qty    = max( 1, (int) ( $merchandise['required_quantity'] ?? 1 ) );

	if ( $weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0 ) {
		return new WP_Error( 'papelito_shipping_product_dimensions_missing', 'Um brinde do Kit precisa de peso e dimensões para cotar frete.', array( 'status' => 422 ) );
	}

	$totals['weight'] += (float) wc_get_weight( $weight, 'g' ) * $qty;
	$totals['length']  = max( $totals['length'], (float) wc_get_dimension( $length, 'cm' ) );
	$totals['width']   = max( $totals['width'], (float) wc_get_dimension( $width, 'cm' ) );
	$totals['height'] += (float) wc_get_dimension( $height, 'cm' ) * $qty;

	return $totals;
}

/**
 * Soma um produto WooCommerce ao pacote.
 *
 * @param array<string, float> $totals Acumuladores atuais.
 * @param array<string, mixed> $item Item do carrinho.
 * @return array<string, float>|WP_Error
 */
function papelito_shipping_add_product_to_package( array $totals, array $item ) {
	$product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
	$qty        = isset( $item['qty'] ) ? max( 1, absint( $item['qty'] ) ) : 1;
	$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

	if ( ! $product ) {
		return new WP_Error( 'papelito_shipping_product_not_found', 'Produto do carrinho não encontrado para cotação de frete.', array( 'status' => 422 ) );
	}

	$weight = (float) $product->get_weight();
	$length = (float) $product->get_length();
	$width  = (float) $product->get_width();
	$height = (float) $product->get_height();

	if ( $weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0 ) {
		return new WP_Error( 'papelito_shipping_product_dimensions_missing', sprintf( 'Produto "%s" precisa de peso e dimensões para cotar frete.', $product->get_name() ), array( 'status' => 422 ) );
	}

	$totals['weight'] += (float) wc_get_weight( $weight, 'g' ) * $qty;
	$totals['length']  = max( $totals['length'], (float) wc_get_dimension( $length, 'cm' ) );
	$totals['width']   = max( $totals['width'], (float) wc_get_dimension( $width, 'cm' ) );
	$totals['height'] += (float) wc_get_dimension( $height, 'cm' ) * $qty;
	$totals['value']  += array_key_exists( 'declared_value', $item ) ? (float) $item['declared_value'] : (float) $product->get_price() * $qty;

	return $totals;
}

/**
 * Encaminha um item para o cálculo de produto ou de brinde físico.
 *
 * @param array<string, float> $totals Acumuladores atuais.
 * @param array<string, mixed> $item Item do carrinho.
 * @return array<string, float>|WP_Error
 */
function papelito_shipping_add_item_to_package( array $totals, array $item ) {
	if ( isset( $item['merchandise'] ) && is_array( $item['merchandise'] ) ) {
		return papelito_shipping_add_merchandise_to_package( $totals, $item['merchandise'] );
	}

	return papelito_shipping_add_product_to_package( $totals, $item );
}

/**
 * Aplica os mínimos dos Correios aos totais válidos do pacote.
 *
 * @param array<string, float> $totals Acumuladores finais.
 * @return array<string, float>
 */
function papelito_shipping_finalize_package( array $totals ): array {
	return array(
		'weight' => max( 1, round( $totals['weight'], 2 ) ),
		'length' => max( 16, round( $totals['length'], 2 ) ),
		'width'  => max( 11, round( $totals['width'], 2 ) ),
		'height' => max( 2, round( $totals['height'], 2 ) ),
		'value'  => round( $totals['value'], 2 ),
	);
}

/**
 * Monta pacote de produtos para cotação.
 *
 * @param array<int, array<string, mixed>> $items Itens do carrinho.
 * @return array<string, float>|WP_Error
 */
function papelito_shipping_build_package( array $items ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error( 'papelito_woocommerce_unavailable', 'WooCommerce não esta disponível para cotação de frete.', array( 'status' => 500 ) );
	}

	$kit_package = papelito_shipping_build_kit_package( $items );
	if ( null !== $kit_package ) {
		return $kit_package;
	}

	if ( function_exists( 'papelito_kit_shipping_items' ) ) {
		$expanded_items = papelito_kit_shipping_items( $items );
		if ( is_wp_error( $expanded_items ) ) {
			return $expanded_items;
		}
		$items = $expanded_items;
	}

	$totals = papelito_shipping_empty_package_totals();
	foreach ( $items as $item ) {
		$totals = papelito_shipping_add_item_to_package( $totals, $item );
		if ( is_wp_error( $totals ) ) {
			return $totals;
		}
	}

	return papelito_shipping_finalize_package( $totals );
}

/**
 * Valida se o usuario informado e um vendor elegivel para a cotacao.
 *
 * @param int $vendor_id ID do vendor.
 * @return WP_User|WP_Error
 */
function papelito_shipping_get_vendor( int $vendor_id ) {
	$vendor = get_userdata( $vendor_id );

	if ( ! $vendor instanceof WP_User ) {
		return new WP_Error( 'papelito_shipping_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	if ( ! in_array( 'seller', (array) $vendor->roles, true ) ) {
		return new WP_Error( 'papelito_shipping_vendor_invalid', 'Usuario informado nao e um vendor elegivel para cotacao.', array( 'status' => 422 ) );
	}

	return $vendor;
}

/**
 * Cota um unico servico.
 *
 * @param array<string, string> $credentials Credenciais.
 * @param array<string, mixed>  $token Token.
 * @param array<string, mixed>  $service Servico.
 * @param string                $origin_cep CEP origem.
 * @param string                $destination_cep CEP destino.
 * @param array<string, float>  $package Pacote.
 * @return array<string, mixed>|WP_Error
 */
function papelito_correios_quote_service( array $credentials, array $token, array $service, string $origin_cep, string $destination_cep, array $package ) {
	$base_url = papelito_correios_base_url( $credentials['environment'] );
	$headers  = array(
		'Authorization' => 'Bearer ' . $token['token'],
		'Accept'        => PAPELITO_CORREIOS_JSON_CONTENT_TYPE,
	);
	$contract = ! empty( $token['cartaoPostagem']['contrato'] )
		? (string) $token['cartaoPostagem']['contrato']
		: $credentials['contract'];
	$dr       = ! empty( $token['cartaoPostagem']['dr'] ) ? (string) $token['cartaoPostagem']['dr'] : '';

	$price_args = array(
		'cepDestino'  => $destination_cep,
		'cepOrigem'   => $origin_cep,
		'psObjeto'    => $package['weight'],
		'tpObjeto'    => '2',
		'comprimento' => $package['length'],
		'largura'     => $package['width'],
		'altura'      => $package['height'],
	);

	if ( '' !== $contract ) {
		$price_args['nuContrato'] = $contract;
	}

	if ( '' !== $dr ) {
		$price_args['nuDR'] = $dr;
	}

	$price = papelito_correios_request_json(
		'GET',
		add_query_arg( $price_args, $base_url . 'preco/v1/nacional/' . rawurlencode( (string) $service['code'] ) ),
		array( 'headers' => $headers )
	);

	if ( is_wp_error( $price ) ) {
		return $price;
	}

	$time = papelito_correios_request_json(
		'GET',
		add_query_arg(
			array(
				'cepDestino' => $destination_cep,
				'cepOrigem'  => $origin_cep,
			),
			$base_url . 'prazo/v1/nacional/' . rawurlencode( (string) $service['code'] )
		),
		array( 'headers' => $headers )
	);

	if ( is_wp_error( $time ) ) {
		return $time;
	}

	$raw_price = papelito_shipping_first_value( $price, array( 'pcFinal', 'precoFinal', 'valor', 'Valor' ) );
	$raw_time  = papelito_shipping_first_value( $time, array( 'prazoEntrega', 'PrazoEntrega', 'delivery_time' ) );

	if ( null === $raw_price ) {
		return new WP_Error(
			'papelito_shipping_quote_missing_price',
			'Correios não retornou preço válido para o serviço cotado.',
			array( 'status' => 502 )
		);
	}

	return array(
		'service'       => $service['service'],
		'code'          => $service['code'],
		'name'          => $service['name'],
		'price'         => papelito_shipping_parse_money( $raw_price ),
		'delivery_time' => null === $raw_time ? null : absint( $raw_time ),
	);
}

/**
 * Retorna uma cotação válida já persistida em cache.
 *
 * @param string $cache_key Chave do transient.
 * @return array<string, mixed>|null
 */
function papelito_correios_quote_cached_result( string $cache_key ): ?array {
	$cached = get_transient( $cache_key );
	if ( ! is_string( $cached ) || '' === $cached ) {
		return null;
	}

	$data = json_decode( $cached, true );

	return is_array( $data ) ? $data : null;
}

/**
 * Cota todos os serviços disponíveis e preserva o primeiro erro para diagnóstico.
 *
 * @param array<string, string>            $credentials Credenciais.
 * @param array<string, mixed>             $token Token dos Correios.
 * @param array<int, array<string, mixed>> $services Serviços.
 * @param string                           $origin_cep CEP de origem.
 * @param string                           $destination_cep CEP de destino.
 * @param array<string, float>             $package Pacote.
 * @return array{options: array<int, array<string, mixed>>, first_error: WP_Error|null}
 */
function papelito_correios_quote_services( array $credentials, array $token, array $services, string $origin_cep, string $destination_cep, array $package ): array {
	$options     = array();
	$first_error = null;

	foreach ( $services as $service ) {
		$quoted_service = papelito_correios_quote_service( $credentials, $token, $service, $origin_cep, $destination_cep, $package );
		if ( is_wp_error( $quoted_service ) ) {
			if ( null === $first_error ) {
				$first_error = $quoted_service;
			}
			continue;
		}

		$options[] = $quoted_service;
	}

	return array(
		'options'     => $options,
		'first_error' => $first_error,
	);
}

/**
 * Converte uma lista de cotações inteiramente inválida no erro público padrão.
 *
 * @param mixed $first_error Primeiro erro de provider.
 * @return WP_Error
 */
function papelito_correios_quote_failure( $first_error ): WP_Error {
	$error_data = array( 'status' => 502 );
	if ( $first_error instanceof WP_Error ) {
		$error_data = array_merge( $error_data, papelito_correios_extract_error_data( $first_error ) );
	}

	return new WP_Error(
		'papelito_shipping_quote_failed',
		'Não foi possível cotar PAC/SEDEX nos Correios.',
		$error_data
	);
}

/**
 * Consolida e ordena as cotações exibidas no checkout.
 *
 * @param array<int, array<string, mixed>> $options Cotações.
 * @return array<int, array<string, mixed>>
 */
function papelito_correios_sort_quoted_options( array $options ): array {
	$options = papelito_correios_select_best_quoted_options( $options );
	usort(
		$options,
		static function ( array $left, array $right ): int {
			$service_order = array(
				'PAC'   => 1,
				'SEDEX' => 2,
			);
			$left_order    = $service_order[ $left['service'] ] ?? 99;
			$right_order   = $service_order[ $right['service'] ] ?? 99;

			if ( $left_order !== $right_order ) {
				return $left_order <=> $right_order;
			}

			return (float) $left['price'] <=> (float) $right['price'];
		}
	);

	return $options;
}

/**
 * Cota frete nos Correios.
 *
 * @param int                             $vendor_id Vendor.
 * @param string                          $destination_cep CEP destino.
 * @param array<int, array<string,mixed>> $items Itens.
 * @return array<string, mixed>|WP_Error
 */
function papelito_correios_quote( int $vendor_id, string $destination_cep, array $items ) {
	$vendor = papelito_shipping_get_vendor( $vendor_id );

	if ( is_wp_error( $vendor ) ) {
		return $vendor;
	}

	$origin_cep = papelito_shipping_normalize_cep( get_user_meta( $vendor_id, 'cep', true ) );

	if ( '' === $origin_cep || '' === $destination_cep ) {
		return new WP_Error( 'papelito_shipping_invalid_cep', 'CEP de origem ou destino invalido.', array( 'status' => 422 ) );
	}

	if ( empty( $items ) ) {
		return new WP_Error( 'papelito_shipping_empty_items', 'Informe ao menos um item para cotar frete.', array( 'status' => 422 ) );
	}

	$credentials = papelito_correios_credentials();
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	$package = papelito_shipping_build_package( $items );
	if ( is_wp_error( $package ) ) {
		return $package;
	}

	$cache_key = 'papelito_shipping_quote_v3_' . papelito_shipping_cache_fingerprint( (string) wp_json_encode( array( $vendor_id, $destination_cep, $items, $package ) ) );
	$cached    = papelito_correios_quote_cached_result( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$token = papelito_correios_get_token( $credentials );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$services = papelito_correios_get_services( $credentials, $token );
	if ( is_wp_error( $services ) ) {
		return $services;
	}

	$quoted = papelito_correios_quote_services( $credentials, $token, $services, $origin_cep, $destination_cep, $package );
	if ( empty( $quoted['options'] ) ) {
		return papelito_correios_quote_failure( $quoted['first_error'] );
	}

	$options = papelito_correios_sort_quoted_options( $quoted['options'] );

	$result = array(
		'origin_cep'      => $origin_cep,
		'destination_cep' => $destination_cep,
		'vendor_id'       => $vendor_id,
		'options'         => $options,
	);

	set_transient( $cache_key, wp_json_encode( $result ), 10 * MINUTE_IN_SECONDS );

	return $result;
}

/**
 * Rate limit para cotacao de frete.
 *
 * Quando a chamada vem do proxy Next autenticado, usa um identificador de
 * cliente assinado por token interno para evitar que todos os compradores
 * compartilhem o mesmo REMOTE_ADDR do servidor de frontend.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @param int             $max Máximo de chamadas na janela.
 * @param int             $window Janela em segundos.
 * @return bool
 */
function papelito_shipping_rate_limit( WP_REST_Request $request, int $max = 60, int $window = 60 ): bool {
	$user_id = get_current_user_id();
	if ( $user_id > 0 ) {
		$key   = 'papelito_shipping_rl_user_' . papelito_shipping_cache_fingerprint( (string) $user_id );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	$proxy_token = defined( 'PAPELITO_FRONT_PROXY_TOKEN' ) ? (string) PAPELITO_FRONT_PROXY_TOKEN : '';
	if ( '' === $proxy_token && function_exists( 'papelito_env' ) ) {
		$proxy_token = (string) papelito_env( 'PAPELITO_FRONT_PROXY_TOKEN', '' );
	}
	if ( '' === $proxy_token ) {
		$proxy_token = (string) getenv( 'PAPELITO_FRONT_PROXY_TOKEN' );
	}

	$sent_token  = (string) $request->get_header( 'x-papelito-proxy-token' );
	$client_key  = sanitize_text_field( (string) $request->get_header( 'x-papelito-client-key' ) );

	if ( '' !== $proxy_token && '' !== $client_key && hash_equals( $proxy_token, $sent_token ) ) {
		$key   = 'papelito_shipping_rl_proxy_' . papelito_shipping_cache_fingerprint( $client_key );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	return ! function_exists( 'papelito_auth_rate_limit' ) || papelito_auth_rate_limit( 'shipping_quote', $max, $window );
}

/**
 * Expõe o limite vigente de frete grátis.
 *
 * @return WP_REST_Response
 */
function papelito_shipping_free_shipping_threshold_response(): WP_REST_Response {
	return new WP_REST_Response( papelito_shipping_get_free_shipping_threshold_snapshot(), 200 );
}

/**
 * Atualiza o limite administrativo de frete grátis.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @return WP_REST_Response|WP_Error
 */
function papelito_shipping_update_free_shipping_threshold_endpoint( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) || ! array_key_exists( 'minimumOrderCents', $payload ) ) {
		return new WP_Error( 'papelito_shipping_invalid_free_shipping_minimum', 'Informe um valor mínimo positivo em centavos.', array( 'status' => 422 ) );
	}

	$result = papelito_shipping_update_free_shipping_minimum_cents( $payload['minimumOrderCents'] );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Recebe e responde uma cotação de frete.
 *
 * @param WP_REST_Request $request Requisição REST.
 * @return WP_REST_Response|WP_Error
 */
function papelito_shipping_quote_endpoint( WP_REST_Request $request ) {
	if ( ! papelito_shipping_rate_limit( $request, 60, 60 ) ) {
		return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
	}

	$data = $request->get_json_params();
	if ( ! is_array( $data ) ) {
		$data = $request->get_params();
	}

	$vendor_id       = isset( $data['vendor_id'] ) ? absint( $data['vendor_id'] ) : 0;
	$destination_cep = papelito_shipping_normalize_cep( $data['destination_cep'] ?? '' );
	$items           = isset( $data['items'] ) && is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
	$result          = papelito_correios_quote( $vendor_id, $destination_cep, $items );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Registra as rotas REST de frete.
 *
 * @return void
 */
function papelito_shipping_register_routes(): void {
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/shipping/free-shipping-threshold',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => 'papelito_shipping_free_shipping_threshold_response',
		)
	);
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/admin/shipping/free-shipping-threshold',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_shipping_require_admin',
			'callback'            => 'papelito_shipping_free_shipping_threshold_response',
		)
	);
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/admin/shipping/free-shipping-threshold',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'permission_callback' => 'papelito_shipping_require_admin',
			'callback'            => 'papelito_shipping_update_free_shipping_threshold_endpoint',
		)
	);
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/shipping/quote',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'papelito_shipping_quote_endpoint',
		)
	);
}

add_action( 'rest_api_init', 'papelito_shipping_register_routes' );
