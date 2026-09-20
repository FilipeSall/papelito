<?php
/**
 * Adapter HTTP Braspress para cotação, sem chamar rede fora do provider elegível.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_BRASPRESS_PRODUCTION_BASE_URL = 'https://api.braspress.com/';
const PAPELITO_BRASPRESS_QUOTE_PATH          = 'v1/cotacao/calcular/json';
const PAPELITO_BRASPRESS_QUOTE_TIMEZONE      = 'America/Sao_Paulo';
const PAPELITO_BRASPRESS_LIMIT_RESPONSE_SIZE = 1048576;
const PAPELITO_BRASPRESS_QUOTE_CACHE_PREFIX  = 'papelito_braspress_quote_v1_';
const PAPELITO_BRASPRESS_QUOTE_CACHE_MAX_TTL = 600;

/**
 * Código estável da opção de frete por modal contratado.
 *
 * A Braspress não tem catálogo de serviços: o modal é a modalidade contratada e
 * é ele, não o identificador da cotação, que identifica a opção no checkout.
 *
 * Só o rodoviário é contratado. O aéreo foi descartado na BRASPRESS-001 por ser
 * materialmente mais caro no teste real, e modal fora deste mapa não vira opção.
 */
const PAPELITO_BRASPRESS_SERVICE_CODE_BY_MODAL = array(
	'R' => 'rodoviario',
);

/**
 * Categorias de erro do vocabulário em docs/braspress/08-error-handling-and-observability.md.
 */
const PAPELITO_BRASPRESS_ERROR_AUTHENTICATION  = 'authentication_error';
const PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED = 'provider_account_blocked';
const PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE   = 'not_available';
const PAPELITO_BRASPRESS_ERROR_PROVIDER_4XX    = 'provider_4xx';
const PAPELITO_BRASPRESS_ERROR_PROVIDER_5XX    = 'provider_5xx';

/**
 * Resolve a base URL pela allowlist de ambiente server-side.
 *
 * @return string|WP_Error Base oficial normalizada ou falha de configuração.
 */
function papelito_braspress_base_url() {
	$configured = function_exists( 'papelito_shipping_provider_config' )
		? papelito_shipping_provider_config( 'BRASPRESS_BASE_URL', PAPELITO_BRASPRESS_PRODUCTION_BASE_URL )
		: false;

	if ( false === $configured || null === $configured ) {
		return PAPELITO_BRASPRESS_PRODUCTION_BASE_URL;
	}

	$value   = (string) $configured;
	$allowed = array(
		PAPELITO_BRASPRESS_PRODUCTION_BASE_URL,
		rtrim( PAPELITO_BRASPRESS_PRODUCTION_BASE_URL, '/' ),
	);

	if ( ! in_array( $value, $allowed, true ) ) {
		return papelito_braspress_http_error( 'configuration_error', 0, papelito_braspress_classify_error( 0, null ) );
	}

	return rtrim( $value, '/' ) . '/';
}

/**
 * Mantém o path relativo a uma base Braspress já validada.
 *
 * @param string $path Path relativo do provider.
 * @return bool Se o path pode ser acrescentado com segurança.
 */
function papelito_braspress_request_path_is_valid( string $path ): bool {
	return '' !== $path
		&& '/' !== $path[0]
		&& false === str_contains( $path, '..' )
		&& 1 === preg_match( '/^[A-Za-z0-9._~%\/-]+$/', $path );
}

/**
 * Monta os dados públicos anexados à falha interna do transporte.
 *
 * @param string              $category Categoria interna de frete.
 * @param int                 $provider_status Status HTTP ou zero em falha de transporte.
 * @param array<string,mixed> $classification Classificação do erro do provider.
 * @param bool                $credential_error Se a autenticação foi rejeitada.
 * @return WP_Error Erro categorizado sem corpo do provider ou credenciais.
 */
function papelito_braspress_http_error( string $category, int $provider_status, array $classification = array(), bool $credential_error = false ): WP_Error {
	if ( empty( $classification ) ) {
		$classification = papelito_braspress_classify_error( $provider_status, null );
	}

	$status = 'configuration_error' === $category ? 503 : 502;
	$data   = array(
		'status'           => $status,
		'provider_status'  => $provider_status,
		'error_category'   => $category,
		'classification'   => $classification,
		'credential_error' => $credential_error,
	);

	return new WP_Error( 'papelito_braspress_' . $category, 'A comunicação com a Braspress não pôde ser concluída.', $data );
}

/**
 * Detecta as variantes de timeout retornadas pelos transportes HTTP do WordPress.
 *
 * @param WP_Error $error Erro retornado pela API HTTP do WordPress.
 * @return bool Se a falha é um timeout.
 */
function papelito_braspress_is_timeout_error( WP_Error $error ): bool {
	$value = strtolower( $error->get_error_code() . ' ' . $error->get_error_message() );

	return str_contains( $value, 'timeout' ) || str_contains( $value, 'timed out' ) || str_contains( $value, 'error 28' );
}

/**
 * Monta os argumentos autenticados de uma requisição Braspress.
 *
 * @param string      $method Método HTTP.
 * @param string      $username Usuário da integração.
 * @param string      $password Senha da integração.
 * @param string|null $body Corpo serializado opcional.
 * @return array<string,mixed> Argumentos compatíveis com a API HTTP do WordPress.
 */
function papelito_braspress_http_request_args( string $method, string $username, string $password, ?string $body ): array {
	$args = array(
		'method'              => strtoupper( $method ),
		'timeout'             => 15,
		'redirection'         => 0,
		'limit_response_size' => PAPELITO_BRASPRESS_LIMIT_RESPONSE_SIZE,
		'sslverify'           => true,
		'headers'             => array(
			'Accept'        => 'application/json',
			'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		),
	);
	if ( null !== $body ) {
		$args['body']                    = $body;
		$args['headers']['Content-Type'] = 'application/json';
	}

	return $args;
}

/**
 * Decodifica um objeto JSON dentro do limite de resposta do provider.
 *
 * @param string $raw_body Corpo bruto devolvido pelo transporte.
 * @return array<string,mixed>|null Objeto decodificado ou nulo quando inválido.
 */
function papelito_braspress_decode_response_body( string $raw_body ): ?array {
	$trimmed = trim( $raw_body );
	if ( strlen( $raw_body ) > PAPELITO_BRASPRESS_LIMIT_RESPONSE_SIZE || '' === $trimmed || '{' !== $trimmed[0] ) {
		return null;
	}

	$decoded = json_decode( $raw_body, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
		return null;
	}

	return $decoded;
}

/**
 * Classifica uma falha de transporte HTTP do WordPress.
 *
 * @param WP_Error $error Erro devolvido pelo executor HTTP.
 * @return WP_Error Erro categorizado do transporte Braspress.
 */
function papelito_braspress_http_transport_error( WP_Error $error ): WP_Error {
	$category = papelito_braspress_is_timeout_error( $error ) ? 'timeout' : 'network_error';

	return papelito_braspress_http_error( $category, 0, papelito_braspress_classify_error( 0, null ) );
}

/**
 * Classifica uma resposta HTTP que não representa sucesso JSON.
 *
 * @param int                      $status Código HTTP devolvido.
 * @param array<string,mixed>|null $body_data Corpo JSON decodificado, se houver.
 * @return WP_Error Erro categorizado da resposta Braspress.
 */
function papelito_braspress_http_response_error( int $status, ?array $body_data ): WP_Error {
	$classification = papelito_braspress_classify_error( $status, $body_data );
	if ( 401 === $status || 403 === $status ) {
		return papelito_braspress_http_error( 'provider_4xx', $status, $classification, true );
	}
	if ( 429 === $status ) {
		return papelito_braspress_http_error( 'rate_limited', $status, $classification );
	}
	if ( $status >= 400 && $status < 500 ) {
		return papelito_braspress_http_error( 'provider_4xx', $status, $classification );
	}
	if ( $status >= 500 && $status < 600 ) {
		return papelito_braspress_http_error( 'provider_5xx', $status, $classification );
	}

	return papelito_braspress_http_error( 'invalid_response', $status, $classification );
}

/**
 * Executa uma requisição autenticada pela fronteira HTTP injetável da Braspress.
 *
 * @param array<string,mixed> $integration Integração do vendor com credenciais.
 * @param string              $method Método HTTP.
 * @param string              $path Path relativo do provider.
 * @param string|null         $body Corpo serializado opcional.
 * @param callable|null       $executor Executor HTTP compatível com o WordPress.
 * @return array{status:int,body:array<string,mixed>} Resposta JSON objeto ou erro categorizado.
 */
function papelito_braspress_http_request( array $integration, string $method, string $path, ?string $body = null, ?callable $executor = null ) {
	$base_url = papelito_braspress_base_url();
	if ( is_wp_error( $base_url ) ) {
		return $base_url;
	}
	if ( ! papelito_braspress_request_path_is_valid( $path ) ) {
		return papelito_braspress_http_error( 'configuration_error', 0 );
	}

	$credentials = is_array( $integration['credentials'] ?? null ) ? $integration['credentials'] : array();
	$username    = (string) ( $credentials['username'] ?? '' );
	$password    = (string) ( $credentials['password'] ?? '' );
	if ( '' === $username || '' === $password ) {
		return papelito_braspress_http_error( 'configuration_error', 0 );
	}

	$args     = papelito_braspress_http_request_args( $method, $username, $password, $body );
	$executor = $executor ?? 'wp_remote_request';
	$response = $executor( $base_url . $path, $args );
	if ( is_wp_error( $response ) ) {
		return papelito_braspress_http_transport_error( $response );
	}

	$status    = (int) wp_remote_retrieve_response_code( $response );
	$body_data = papelito_braspress_decode_response_body( (string) wp_remote_retrieve_body( $response ) );

	if ( $status >= 200 && $status < 300 && is_array( $body_data ) ) {
		return array(
			'status' => $status,
			'body'   => $body_data,
		);
	}

	if ( $status >= 200 && $status < 300 ) {
		return papelito_braspress_http_error( 'invalid_response', $status, papelito_braspress_classify_error( $status, $body_data ) );
	}

	return papelito_braspress_http_response_error( $status, $body_data );
}

/**
 * Converte centavos não-negativos sem arredondamento de ponto flutuante.
 *
 * @param int $cents Valor em centavos a formatar.
 * @return string Valor com duas casas ou vazio quando inválido.
 */
function papelito_braspress_decimal( int $cents ): string {
	if ( $cents < 0 ) {
		return '';
	}

	return (string) intdiv( $cents, 100 ) . '.' . str_pad( (string) ( $cents % 100 ), 2, '0', STR_PAD_LEFT );
}

/**
 * Normaliza o peso em quilogramas na fronteira do adapter.
 *
 * @param mixed $weight Peso físico total em kg.
 * @return float|null Peso aceito com duas casas ou nulo quando inválido.
 */
function papelito_braspress_weight_kg( $weight ): ?float {
	if ( ! is_numeric( $weight ) ) {
		return null;
	}

	$normalized = round( (float) $weight, 2, PHP_ROUND_HALF_UP );

	return $normalized > 0 ? $normalized : null;
}

/**
 * Restringe o texto do provider antes que ele alcance o log operacional.
 *
 * @param string            $message Mensagem fornecida pelo provider.
 * @param array<int,string> $secrets Valores que não podem aparecer na mensagem.
 * @return string Mensagem curta com sequências longas de dígitos mascaradas.
 */
function papelito_braspress_redact_provider_message( string $message, array $secrets = array() ): string {
	$sanitized = sanitize_text_field( $message );
	foreach ( $secrets as $secret ) {
		if ( is_string( $secret ) && '' !== $secret ) {
			$sanitized = str_replace( $secret, '[redacted]', $sanitized );
		}
	}

	$redacted = preg_replace( '/\d{8,}/', '[redacted]', $sanitized );

	return substr( (string) $redacted, 0, 256 );
}

/**
 * Extrai e redige mensagens do campo errorList.
 *
 * @param mixed $error_list Lista de mensagens do provider.
 * @return array<int,string> Mensagens redigidas.
 */
function papelito_braspress_error_list_messages( $error_list ): array {
	$messages = array();
	foreach ( (array) $error_list as $entry ) {
		if ( count( $messages ) >= 5 ) {
			break;
		}
		if ( is_scalar( $entry ) ) {
			$messages[] = papelito_braspress_redact_provider_message( (string) $entry );
		}
	}

	return $messages;
}

/**
 * Extrai e redige mensagens do envelope ProblemDetails.
 *
 * @param mixed $errors Campo errors devolvido pelo provider.
 * @param int   $limit Quantidade máxima de mensagens a extrair.
 * @return array<int,string> Mensagens redigidas.
 */
function papelito_braspress_problem_detail_messages( $errors, int $limit ): array {
	$messages = array();
	foreach ( (array) $errors as $field => $entries ) {
		foreach ( (array) $entries as $entry ) {
			if ( count( $messages ) >= $limit ) {
				return $messages;
			}
			if ( is_scalar( $entry ) ) {
				$label      = is_string( $field ) ? sanitize_text_field( $field ) . ': ' : '';
				$messages[] = papelito_braspress_redact_provider_message( $label . (string) $entry );
			}
		}
	}

	return $messages;
}

/**
 * Extrai as mensagens de erro dos dois formatos que a API realmente devolve.
 *
 * Erros de negócio chegam em `errorList`; erros de validação chegam no
 * ProblemDetails do ASP.NET, com os campos recusados em `errors`.
 *
 * @param mixed $body Corpo decodificado da resposta.
 * @return array<int,string> Mensagens sanitizadas, sem dados do comprador.
 */
function papelito_braspress_error_messages( $body ): array {
	if ( ! is_array( $body ) ) {
		return array();
	}

	$messages  = papelito_braspress_error_list_messages( $body['errorList'] ?? array() );
	$remaining = 5 - count( $messages );
	if ( $remaining > 0 ) {
		$messages = array_merge( $messages, papelito_braspress_problem_detail_messages( $body['errors'] ?? array(), $remaining ) );
	}

	if ( empty( $messages ) && isset( $body['message'] ) && is_scalar( $body['message'] ) ) {
		$messages[] = papelito_braspress_redact_provider_message( (string) $body['message'] );
	}

	return array_values( array_filter( $messages ) );
}

/**
 * Classifica a falha da Braspress para separar inelegibilidade de defeito.
 *
 * A API responde erro de negócio com HTTP 500, então o status sozinho não
 * distingue "não atendo esse destino" de "a integração está quebrada".
 *
 * @param int   $status Código HTTP devolvido.
 * @param mixed $body Corpo decodificado da resposta.
 * @return array{category:string,messages:array<int,string>,trace_id:string} Classificação e evidência.
 */
function papelito_braspress_classify_error( int $status, $body ): array {
	$messages = papelito_braspress_error_messages( $body );
	$joined   = implode( ' | ', $messages );

	if ( 401 === $status || 403 === $status ) {
		$category = PAPELITO_BRASPRESS_ERROR_AUTHENTICATION;
	} elseif ( 1 === preg_match( '/BLOQUEAD|INADIMPL/iu', $joined ) ) {
		$category = PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED;
	} elseif ( 1 === preg_match( '/CEP\s+(DESTINO|ORIGEM).*ENCONTRAD/iu', $joined ) ) {
		$category = PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE;
	} elseif ( $status >= 400 && $status < 500 ) {
		$category = PAPELITO_BRASPRESS_ERROR_PROVIDER_4XX;
	} else {
		$category = PAPELITO_BRASPRESS_ERROR_PROVIDER_5XX;
	}

	return array(
		'category' => $category,
		'messages' => $messages,
		'trace_id' => isset( $body['traceId'] ) && is_scalar( $body['traceId'] ) ? substr( sanitize_text_field( (string) $body['traceId'] ), 0, 128 ) : '',
	);
}

/**
 * Recusa CEP que a Braspress aceitaria sem cotar de verdade.
 *
 * A API não valida o formato do CEP: um destino só com zeros volta HTTP 200,
 * com prazo zero e um preço que não corresponde a nenhuma rota.
 *
 * @param string $cep CEP de destino.
 * @return bool Se o CEP pode ser cotado.
 */
function papelito_braspress_cep_is_quotable( string $cep ): bool {
	$digits = papelito_vendor_integration_normalize_cep( $cep );

	return 8 === strlen( $digits ) && 0 !== (int) $digits;
}

/**
 * Valida o contrato físico independente usado pela Braspress.
 *
 * @param array<string,mixed> $package Pacote com peso, volumes e cubagem em metros.
 * @return bool Se o pacote pode ser cotado.
 */
function papelito_braspress_package_is_valid( array $package ): bool {
	$weight  = $package['weight_kg'] ?? null;
	$volumes = $package['volumes'] ?? null;
	$cubagem = $package['cubagem'] ?? null;

	if ( null === papelito_braspress_weight_kg( $weight ) || ! is_int( $volumes ) || $volumes <= 0 || ! is_array( $cubagem ) || empty( $cubagem ) ) {
		return false;
	}

	$total = 0;
	foreach ( $cubagem as $group ) {
		if ( ! is_array( $group ) || ! is_numeric( $group['length_m'] ?? null ) || ! is_numeric( $group['width_m'] ?? null ) || ! is_numeric( $group['height_m'] ?? null ) || ! is_int( $group['volumes'] ?? null ) || (float) $group['length_m'] <= 0 || (float) $group['width_m'] <= 0 || (float) $group['height_m'] <= 0 || $group['volumes'] <= 0 ) {
			return false;
		}

		$total += $group['volumes'];
	}

	return $total === $volumes;
}

/**
 * Traduz o modal contratado no código estável da opção de frete.
 *
 * O código nomeia a modalidade, nunca a cotação: duas cotações seguidas do mesmo
 * contrato produzem a mesma `option_key`, e a seleção do checkout sobrevive à
 * expiração do cache. O identificador de cada cotação viaja em `external_quote_id`.
 *
 * @param mixed $modal Modal contratado, como viaja no payload oficial.
 * @return string Código canônico da modalidade ou vazio quando o modal é desconhecido.
 */
function papelito_braspress_service_code( mixed $modal ): string {
	$contracted = is_scalar( $modal ) ? strtoupper( trim( (string) $modal ) ) : '';

	return PAPELITO_BRASPRESS_SERVICE_CODE_BY_MODAL[ $contracted ] ?? '';
}

/**
 * Monta o corpo de cotação sem incluir credenciais.
 *
 * @param array<string,mixed> $integration Integração resolvida para o vendor.
 * @param string              $recipient_cnpj CNPJ do destinatário autorizado.
 * @param string              $destination_cep CEP de destino.
 * @param array<string,mixed> $package Pacote físico aprovado.
 * @param int                 $merchandise_value_cents Valor mercantil em centavos.
 * @return array<string,mixed>|WP_Error Corpo validado ou erro.
 */
function papelito_braspress_build_quote_payload( array $integration, string $recipient_cnpj, string $destination_cep, array $package, int $merchandise_value_cents ) {
	$config = is_array( $integration['config'] ?? null ) ? $integration['config'] : array();
	if ( ! papelito_vendor_integration_config_complete( $config ) || '' === papelito_braspress_service_code( $config['modal'] ?? '' ) || ! papelito_braspress_package_is_valid( $package ) || 14 !== strlen( papelito_vendor_integration_normalize_document( $recipient_cnpj ) ) || ! papelito_braspress_cep_is_quotable( $destination_cep ) || $merchandise_value_cents <= 0 ) {
		return new WP_Error( 'papelito_braspress_payload_invalid', 'Os dados necessários para a cotação Braspress não estão completos.', array( 'status' => 422 ) );
	}

	$cubagem = array_map(
		static function ( array $group ): array {
			return array(
				'comprimento' => (float) $group['length_m'],
				'largura'     => (float) $group['width_m'],
				'altura'      => (float) $group['height_m'],
				'volumes'     => $group['volumes'],
			);
		},
		$package['cubagem']
	);

	return array(
		'cnpjRemetente'    => $config['sender_cnpj'],
		'cnpjDestinatario' => papelito_vendor_integration_normalize_document( $recipient_cnpj ),
		'modal'            => $config['modal'],
		'tipoFrete'        => $config['freight_type'],
		'cepOrigem'        => $config['origin_cep'],
		'cepDestino'       => papelito_vendor_integration_normalize_cep( $destination_cep ),
		'vlrMercadoria'    => papelito_braspress_decimal( $merchandise_value_cents ),
		'peso'             => papelito_braspress_weight_kg( $package['weight_kg'] ),
		'volumes'          => $package['volumes'],
		'cubagem'          => $cubagem,
	);
}

/**
 * Calcula a expiração no fim do dia da Braspress.
 *
 * @param DateTimeInterface $quoted_at Instante em que a cotação foi solicitada.
 * @return string Data UTC em ISO-8601.
 */
function papelito_braspress_quote_expiry( DateTimeInterface $quoted_at ): string {
	$timezone = new DateTimeZone( PAPELITO_BRASPRESS_QUOTE_TIMEZONE );
	$local    = DateTimeImmutable::createFromInterface( $quoted_at )->setTimezone( $timezone );
	$expires  = $local->setTime( 23, 59, 59, 999000 );

	return $expires->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\\TH:i:s.vP' );
}

/**
 * Monta a chave opaca da cotação Braspress por vendor, integração e dia civil.
 *
 * O HMAC cobre somente identificadores não secretos, o payload oficial, o
 * pacote físico e a data de São Paulo; credenciais nunca entram na chave.
 *
 * @param int                    $vendor_id Vendor que responde a cotação.
 * @param array<string,mixed>    $integration Integração resolvida do vendor.
 * @param array<string,mixed>    $payload Payload oficial já montado.
 * @param string                 $physical_hash Impressão física do pacote.
 * @param DateTimeInterface|null $quoted_at Instante usado para o dia civil.
 * @return string Chave versionada e não reversível do transient.
 */
function papelito_braspress_quote_cache_key( int $vendor_id, array $integration, array $payload, string $physical_hash = '', ?DateTimeInterface $quoted_at = null ): string {
	$quoted_at = $quoted_at ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	$local     = DateTimeImmutable::createFromInterface( $quoted_at )->setTimezone( new DateTimeZone( PAPELITO_BRASPRESS_QUOTE_TIMEZONE ) );
	$identity  = array(
		'vendor_id'             => $vendor_id,
		'integration_id'        => (int) ( $integration['id'] ?? 0 ),
		'configuration_version' => (int) ( $integration['configuration_version'] ?? 0 ),
		'payload'               => $payload,
		'physical_hash'         => $physical_hash,
		'civil_date'            => $local->format( 'Y-m-d' ),
	);

	return PAPELITO_BRASPRESS_QUOTE_CACHE_PREFIX . papelito_shipping_cache_fingerprint( (string) wp_json_encode( $identity ) );
}

/**
 * Calcula o TTL seguro entre o instante da cotação e o corte de São Paulo.
 *
 * A parte fracionária evita que a cotação de 23:59 seja arredondada para um
 * TTL que atravesse o dia ou seja persistida com zero segundos.
 *
 * @param DateTimeInterface $quoted_at Instante da cotação.
 * @return int TTL em segundos, limitado pelo teto interno.
 */
function papelito_braspress_quote_cache_ttl( DateTimeInterface $quoted_at ): int {
	$quoted_timestamp = (float) DateTimeImmutable::createFromInterface( $quoted_at )->format( 'U.u' );
	$expiry_timestamp = (float) ( new DateTimeImmutable( papelito_braspress_quote_expiry( $quoted_at ) ) )->format( 'U.u' );
	$remaining        = (int) floor( $expiry_timestamp - $quoted_timestamp );

	return min( PAPELITO_BRASPRESS_QUOTE_CACHE_MAX_TTL, max( 0, $remaining ) );
}

/**
 * Lê uma opção Braspress normalizada do transient.
 *
 * Corpo bruto do provider, credenciais e payload não são aceitos como valor
 * de retorno: o transient guarda apenas o JSON da opção já normalizada.
 *
 * @param string $cache_key Chave opaca do transient.
 * @return array<string,mixed>|null Opção cacheada ou nulo em cache inválido.
 */
function papelito_braspress_quote_cached_result( string $cache_key ): ?array {
	$cached = get_transient( $cache_key );
	if ( ! is_string( $cached ) || '' === $cached ) {
		return null;
	}

	$result = json_decode( $cached, true );

	return is_array( $result ) ? $result : null;
}

/**
 * Registra a falha do provider sem expor credencial nem dados do comprador.
 *
 * @param int                                                               $vendor_id ID do vendor.
 * @param int                                                               $status Código HTTP devolvido.
 * @param array{category:string,messages:array<int,string>,trace_id:string} $classified Classificação da falha.
 * @param string                                                            $operation Operação que falhou.
 * @param int                                                               $duration_ms Duração do transporte em milissegundos.
 * @param array<int,string>                                                 $secrets Credenciais que não podem aparecer nas mensagens.
 * @return void
 */
function papelito_braspress_log_failure( int $vendor_id, int $status, array $classified, string $operation = 'quote', int $duration_ms = 0, array $secrets = array() ): void {
	$messages = array();
	foreach ( array_slice( (array) ( $classified['messages'] ?? array() ), 0, 5 ) as $message ) {
		if ( is_scalar( $message ) ) {
			$messages[] = papelito_braspress_redact_provider_message( (string) $message, $secrets );
		}
	}

	$entry = array(
		'papelito_braspress' => array(
			'vendor_id'       => $vendor_id,
			'operation'       => $operation,
			'duration_ms'     => max( 0, $duration_ms ),
			'provider_status' => $status,
			'category'        => (string) ( $classified['category'] ?? 'unknown_error' ),
			'traceId'         => (string) ( $classified['trace_id'] ?? '' ),
			'messages'        => $messages,
		),
	);

	error_log( wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Extrai a classificação do provider anexada ao erro de transporte.
 *
 * @param WP_Error $error Erro de transporte categorizado.
 * @return array{category:string,messages:array<int,string>,trace_id:string} Classificação segura.
 */
function papelito_braspress_error_classification( WP_Error $error ): array {
	$data           = $error->get_error_data();
	$classification = is_array( $data ) && is_array( $data['classification'] ?? null ) ? $data['classification'] : array();

	return array(
		'category' => (string) ( $classification['category'] ?? 'provider_5xx' ),
		'messages' => (array) ( $classification['messages'] ?? array() ),
		'trace_id' => (string) ( $classification['trace_id'] ?? '' ),
	);
}

/**
 * Lê a categoria interna e o status público de um erro de transporte.
 *
 * @param WP_Error $error Erro de transporte categorizado.
 * @return array{category:string,status:int,provider_status:int} Metadados do erro.
 */
function papelito_braspress_error_metadata( WP_Error $error ): array {
	$data = $error->get_error_data();
	$data = is_array( $data ) ? $data : array();

	return array(
		'category'        => (string) ( $data['error_category'] ?? 'unknown_error' ),
		'status'          => isset( $data['status'] ) ? (int) $data['status'] : 502,
		'provider_status' => isset( $data['provider_status'] ) ? (int) $data['provider_status'] : 0,
	);
}

/**
 * Converte um erro de transporte classificado em código e mensagem de domínio estáveis.
 *
 * @param string $category Categoria do erro.
 * @param string $operation Operação de domínio.
 * @return array{code:string,message:string} Erro público de domínio.
 */
function papelito_braspress_domain_error_definition( string $category, string $operation ): array {
	$special = array(
		PAPELITO_BRASPRESS_ERROR_AUTHENTICATION  => array(
			'papelito_braspress_credentials_invalid',
			'As credenciais Braspress precisam ser atualizadas.',
		),
		PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED => array(
			'papelito_braspress_account_blocked',
			'A conta Braspress desta loja está bloqueada.',
		),
		PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE   => array(
			'papelito_braspress_route_not_available',
			'A Braspress não atende este destino.',
		),
	);
	if ( isset( $special[ $category ] ) ) {
		return array(
			'code'    => $special[ $category ][0],
			'message' => $special[ $category ][1],
		);
	}

	$message = 'Não foi possível consultar o tracking Braspress.';
	if ( 'quote' === $operation ) {
		$message = 'Não foi possível cotar pela Braspress.';
	}
	if ( in_array( $category, array( 'timeout', 'network_error', 'rate_limited' ), true ) ) {
		$message = 'A Braspress está temporariamente indisponível.';
	}

	return array(
		'code'    => 'papelito_braspress_' . $category,
		'message' => $message,
	);
}

/**
 * Marca a conta quando a falha é da própria conta, e só nesse caso.
 *
 * Timeout, rede, `429` e `5xx` não passam por aqui de propósito: a credencial
 * continua boa e degradar a conta por indisponibilidade tiraria a Braspress do
 * checkout muito além do incidente. A versão que originou a tentativa viaja
 * junto para não marcar uma credencial que o vendor acabou de substituir.
 *
 * @param array<string,mixed> $integration Integração resolvida para o vendor.
 * @param string              $category Categoria classificada da falha.
 * @return void
 */
function papelito_braspress_apply_failure_health( array $integration, string $category ): void {
	$states = array(
		PAPELITO_BRASPRESS_ERROR_AUTHENTICATION  => PAPELITO_VENDOR_INTEGRATION_INVALID,
		PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED => PAPELITO_VENDOR_INTEGRATION_BLOCKED,
	);

	if ( ! isset( $states[ $category ] ) || ! function_exists( 'papelito_vendor_integration_apply_braspress_health' ) ) {
		return;
	}

	papelito_vendor_integration_apply_braspress_health(
		(int) ( $integration['vendor_id'] ?? 0 ),
		$states[ $category ],
		$category,
		(int) ( $integration['configuration_version'] ?? 0 )
	);
}

/**
 * Aplica estado de domínio, log restrito e dados públicos após falha do transporte.
 *
 * @param array<string,mixed> $integration Integração resolvida para o vendor.
 * @param WP_Error            $error Erro de transporte categorizado.
 * @param string              $operation Operação `quote` ou `tracking`.
 * @param int                 $duration_ms Duração do transporte em milissegundos.
 * @return WP_Error Erro de domínio sem corpo bruto do provider.
 */
function papelito_braspress_handle_transport_error( array $integration, WP_Error $error, string $operation, int $duration_ms ): WP_Error {
	$metadata       = papelito_braspress_error_metadata( $error );
	$classification = papelito_braspress_error_classification( $error );
	$category       = $classification['category'];
	$vendor_id      = (int) ( $integration['vendor_id'] ?? 0 );
	$credentials    = is_array( $integration['credentials'] ?? null ) ? $integration['credentials'] : array();
	$secrets        = array_filter(
		array(
			(string) ( $credentials['username'] ?? '' ),
			(string) ( $credentials['password'] ?? '' ),
			base64_encode( (string) ( $credentials['username'] ?? '' ) . ':' . (string) ( $credentials['password'] ?? '' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		)
	);

	papelito_braspress_apply_failure_health( $integration, $category );

	$domain_category = in_array( $category, array( PAPELITO_BRASPRESS_ERROR_AUTHENTICATION, PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED, PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE ), true ) ? $category : $metadata['category'];
	$definition      = papelito_braspress_domain_error_definition( $domain_category, $operation );
	if ( PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE !== $category ) {
		$log_category               = in_array( $category, array( PAPELITO_BRASPRESS_ERROR_AUTHENTICATION, PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED ), true ) ? $category : $metadata['category'];
		$classification['category'] = $log_category;
		papelito_braspress_log_failure( $vendor_id, $metadata['provider_status'], $classification, $operation, $duration_ms, $secrets );
	}

	$data = array(
		'status'          => PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE === $category ? 422 : $metadata['status'],
		'provider_status' => $metadata['provider_status'],
		'error_category'  => $domain_category,
		'classification'  => $classification,
	);

	return new WP_Error( $definition['code'], $definition['message'], $data );
}

/**
 * Solicita uma cotação Braspress uma única vez no backend.
 *
 * @param array<string,mixed> $integration Integração resolvida para o vendor.
 * @param string              $recipient_cnpj CNPJ do destinatário autorizado.
 * @param string              $destination_cep CEP de destino.
 * @param array<string,mixed> $package Pacote físico aprovado.
 * @param int                 $merchandise_value_cents Valor mercantil em centavos.
 * @return array<string,mixed>|WP_Error Cotação normalizável ou erro redigido.
 */
function papelito_braspress_quote( array $integration, string $recipient_cnpj, string $destination_cep, array $package, int $merchandise_value_cents ) {
	return papelito_braspress_quote_at( $integration, $recipient_cnpj, $destination_cep, $package, $merchandise_value_cents );
}

/**
 * Solicita uma cotação com relógio opcional para testar a fronteira de validade.
 *
 * @param array<string,mixed>    $integration Integração resolvida para o vendor.
 * @param string                 $recipient_cnpj CNPJ do destinatário autorizado.
 * @param string                 $destination_cep CEP de destino.
 * @param array<string,mixed>    $package Pacote físico aprovado.
 * @param int                    $merchandise_value_cents Valor mercantil em centavos.
 * @param DateTimeInterface|null $quoted_at Instante UTC da cotação.
 * @return array<string,mixed>|WP_Error Cotação normalizável ou erro redigido.
 */
function papelito_braspress_quote_at( array $integration, string $recipient_cnpj, string $destination_cep, array $package, int $merchandise_value_cents, ?DateTimeInterface $quoted_at = null ) {
	$payload = papelito_braspress_build_quote_payload( $integration, $recipient_cnpj, $destination_cep, $package, $merchandise_value_cents );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}

	$quoted_at     = $quoted_at ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	$physical_hash = is_scalar( $package['physical_hash'] ?? null ) ? (string) $package['physical_hash'] : '';
	$cache_key     = papelito_braspress_quote_cache_key( (int) ( $integration['vendor_id'] ?? 0 ), $integration, $payload, $physical_hash, $quoted_at );
	$cached        = papelito_braspress_quote_cached_result( $cache_key );
	if ( is_array( $cached ) ) {
		do_action( 'papelito_braspress_quote_cache_result', (int) ( $integration['vendor_id'] ?? 0 ), 'hit' );
		return $cached;
	}

	do_action( 'papelito_braspress_quote_cache_result', (int) ( $integration['vendor_id'] ?? 0 ), 'miss' );

	$started  = microtime( true );
	$response = papelito_braspress_http_request( $integration, 'POST', PAPELITO_BRASPRESS_QUOTE_PATH, wp_json_encode( $payload ) );
	$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
	if ( is_wp_error( $response ) ) {
		return papelito_braspress_handle_transport_error( $integration, $response, 'quote', $duration );
	}

	$status = (int) $response['status'];
	$body   = $response['body'];
	$id     = isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
	$price  = $body['totalFrete'] ?? null;
	$days   = $body['prazo'] ?? null;
	if ( '' === $id || ! is_numeric( $price ) || (float) $price < 0 || ! is_numeric( $days ) || (int) $days <= 0 ) {
		$classified = array(
			'category' => 'invalid_response',
			'messages' => array(),
			'trace_id' => '',
		);
		papelito_braspress_log_failure( (int) ( $integration['vendor_id'] ?? 0 ), $status, $classified, 'quote', $duration );
		return new WP_Error(
			'papelito_braspress_response_invalid',
			'A Braspress retornou uma cotação inválida.',
			array(
				'status'          => 502,
				'provider_status' => $status,
				'error_category'  => 'invalid_response',
			)
		);
	}

	$expires = papelito_braspress_quote_expiry( $quoted_at );

	$result = array(
		'service'           => 'Braspress',
		'code'              => papelito_braspress_service_code( $payload['modal'] ),
		'name'              => 'Braspress',
		'price'             => (float) $price,
		'delivery_time'     => (int) $days,
		'external_quote_id' => $id,
		'expires_at'        => $expires,
		'physical_hash'     => is_scalar( $package['physical_hash'] ?? null ) ? (string) $package['physical_hash'] : '',
	);

	if ( PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE === papelito_braspress_confirm_active( $integration, $status, $duration ) ) {
		return null;
	}

	$cache_ttl = papelito_braspress_quote_cache_ttl( $quoted_at );
	if ( $cache_ttl > 0 ) {
		set_transient( $cache_key, wp_json_encode( $result ), $cache_ttl );
	}

	return $result;
}

/**
 * Confirma a conta como ativa sobre a versão que originou esta cotação.
 *
 * Devolver `stale` é o que descarta a resposta em trânsito: entre o pedido e a
 * resposta cabem quinze segundos, e nesse intervalo o vendor pode ter
 * desabilitado a integração ou trocado a credencial. A cotação obsoleta não
 * vira opção nem entra no cache.
 *
 * @param array<string,mixed> $integration Integração resolvida para o vendor.
 * @param int                 $status Código HTTP da resposta aceita.
 * @param int                 $duration_ms Duração do transporte em milissegundos.
 * @return string Desfecho da transição, ou `applied` quando não há política instalada.
 */
function papelito_braspress_confirm_active( array $integration, int $status = 200, int $duration_ms = 0 ): string {
	if ( ! function_exists( 'papelito_vendor_integration_apply_braspress_health' ) ) {
		return PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED;
	}

	$vendor_id = (int) ( $integration['vendor_id'] ?? 0 );
	$outcome   = papelito_vendor_integration_apply_braspress_health(
		$vendor_id,
		PAPELITO_VENDOR_INTEGRATION_ACTIVE,
		'',
		(int) ( $integration['configuration_version'] ?? 0 )
	);

	$category = papelito_braspress_health_log_category( $outcome );
	if ( '' !== $category ) {
		papelito_braspress_log_failure(
			$vendor_id,
			$status,
			array(
				'category' => $category,
				'messages' => array(),
				'trace_id' => '',
			),
			'quote',
			$duration_ms
		);
	}

	return $outcome;
}

/**
 * Traduz o desfecho da transição de saúde na categoria registrada em log.
 *
 * Os dois casos existem para explicar uma opção que não apareceu ou uma conta
 * que continuou `ready` depois de cotar. Sucesso não gera linha.
 *
 * @param string $outcome Desfecho devolvido pela política de saúde.
 * @return string Categoria da taxonomia, ou vazio quando não há o que registrar.
 */
function papelito_braspress_health_log_category( string $outcome ): string {
	if ( PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE === $outcome ) {
		return 'integration_not_ready';
	}

	return PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED === $outcome ? 'persistence_error' : '';
}
