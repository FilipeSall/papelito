<?php
/**
 * Adapter HTTP Braspress para cotação, sem chamar rede fora do provider elegível.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_BRASPRESS_QUOTE_URL      = 'https://api.braspress.com/v1/cotacao/calcular/json';
const PAPELITO_BRASPRESS_QUOTE_TIMEZONE = 'America/Sao_Paulo';

/**
 * Categorias de erro do vocabulário em docs/braspress/08-error-handling-and-observability.md.
 */
const PAPELITO_BRASPRESS_ERROR_AUTHENTICATION   = 'authentication_error';
const PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED  = 'provider_account_blocked';
const PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE    = 'not_available';
const PAPELITO_BRASPRESS_ERROR_PROVIDER_4XX     = 'provider_4xx';
const PAPELITO_BRASPRESS_ERROR_PROVIDER_5XX     = 'provider_5xx';

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

	$messages = array();
	foreach ( (array) ( $body['errorList'] ?? array() ) as $entry ) {
		if ( is_scalar( $entry ) ) {
			$messages[] = sanitize_text_field( (string) $entry );
		}
	}

	foreach ( (array) ( $body['errors'] ?? array() ) as $field => $entries ) {
		foreach ( (array) $entries as $entry ) {
			if ( is_scalar( $entry ) ) {
				$label      = is_string( $field ) ? sanitize_text_field( $field ) . ': ' : '';
				$messages[] = $label . sanitize_text_field( (string) $entry );
			}
		}
	}

	if ( empty( $messages ) && isset( $body['message'] ) && is_scalar( $body['message'] ) ) {
		$messages[] = sanitize_text_field( (string) $body['message'] );
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
		'trace_id' => isset( $body['traceId'] ) && is_scalar( $body['traceId'] ) ? sanitize_text_field( (string) $body['traceId'] ) : '',
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
	if ( ! papelito_vendor_integration_config_complete( $config ) || ! papelito_braspress_package_is_valid( $package ) || 14 !== strlen( papelito_vendor_integration_normalize_document( $recipient_cnpj ) ) || ! papelito_braspress_cep_is_quotable( $destination_cep ) || $merchandise_value_cents <= 0 ) {
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
 * Registra a falha do provider sem expor credencial nem dados do comprador.
 *
 * @param int                                                              $vendor_id ID do vendor.
 * @param int                                                              $status Código HTTP devolvido.
 * @param array{category:string,messages:array<int,string>,trace_id:string} $classified Classificação da falha.
 * @return void
 */
function papelito_braspress_log_failure( int $vendor_id, int $status, array $classified ): void {
	$entry = array(
		'papelito_braspress' => array(
			'vendor_id'       => $vendor_id,
			'provider_status' => $status,
			'category'        => $classified['category'],
			'messages'        => $classified['messages'],
			'trace_id'        => $classified['trace_id'],
		),
	);

	error_log( wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Converte a falha da Braspress em erro de domínio com a reação certa.
 *
 * Rota não atendida é resultado normal e não pode marcar a integração como
 * defeituosa; conta bloqueada precisa parar de tentar e aparecer para o vendor.
 *
 * @param array<string,mixed> $integration Integração resolvida para o vendor.
 * @param int                 $status Código HTTP devolvido.
 * @param mixed               $body Corpo decodificado da resposta.
 * @return WP_Error Erro classificado.
 */
function papelito_braspress_quote_error( array $integration, int $status, $body ): WP_Error {
	$classified = papelito_braspress_classify_error( $status, $body );
	$category   = $classified['category'];
	$vendor_id  = (int) ( $integration['vendor_id'] ?? 0 );
	$data       = array(
		'status'          => 502,
		'provider_status' => $status,
		'error_category'  => $category,
	);

	if ( PAPELITO_BRASPRESS_ERROR_NOT_AVAILABLE === $category ) {
		return new WP_Error(
			'papelito_braspress_route_not_available',
			'A Braspress não atende este destino.',
			array(
				'status'          => 422,
				'provider_status' => $status,
				'error_category'  => $category,
			)
		);
	}

	if ( function_exists( 'papelito_vendor_integration_set_braspress_operational_state' ) ) {
		if ( PAPELITO_BRASPRESS_ERROR_AUTHENTICATION === $category ) {
			papelito_vendor_integration_set_braspress_operational_state( $vendor_id, PAPELITO_VENDOR_INTEGRATION_INVALID, $category );
		} elseif ( PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED === $category ) {
			papelito_vendor_integration_set_braspress_operational_state( $vendor_id, PAPELITO_VENDOR_INTEGRATION_BLOCKED, $category );
		}
	}

	papelito_braspress_log_failure( $vendor_id, $status, $classified );

	if ( PAPELITO_BRASPRESS_ERROR_AUTHENTICATION === $category ) {
		return new WP_Error( 'papelito_braspress_credentials_invalid', 'As credenciais Braspress precisam ser atualizadas.', $data );
	}

	if ( PAPELITO_BRASPRESS_ERROR_ACCOUNT_BLOCKED === $category ) {
		return new WP_Error( 'papelito_braspress_account_blocked', 'A conta Braspress desta loja está bloqueada.', $data );
	}

	return new WP_Error( 'papelito_braspress_quote_failed', 'Não foi possível cotar pela Braspress.', $data );
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
	$payload = papelito_braspress_build_quote_payload( $integration, $recipient_cnpj, $destination_cep, $package, $merchandise_value_cents );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}

	$credentials = is_array( $integration['credentials'] ?? null ) ? $integration['credentials'] : array();
	$username    = (string) ( $credentials['username'] ?? '' );
	$password    = (string) ( $credentials['password'] ?? '' );
	if ( '' === $username || '' === $password ) {
		return new WP_Error( 'papelito_braspress_credentials_unavailable', 'A integração Braspress não está disponível.', array( 'status' => 503 ) );
	}

	$response = wp_remote_post(
		PAPELITO_BRASPRESS_QUOTE_URL,
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
			'body'    => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'papelito_braspress_unavailable', 'A Braspress está temporariamente indisponível.', array( 'status' => 502 ) );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		return papelito_braspress_quote_error( $integration, $status, $body );
	}

	$id    = isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
	$price = $body['totalFrete'] ?? null;
	$days  = $body['prazo'] ?? null;
	if ( '' === $id || ! is_numeric( $price ) || (float) $price < 0 || ! is_numeric( $days ) || (int) $days <= 0 ) {
		return new WP_Error( 'papelito_braspress_response_invalid', 'A Braspress retornou uma cotação inválida.', array( 'status' => 502 ) );
	}

	$expires = papelito_braspress_quote_expiry( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

	$result = array(
		'service'           => 'Braspress',
		'code'              => $id,
		'name'              => 'Braspress',
		'price'             => (float) $price,
		'delivery_time'     => (int) $days,
		'external_quote_id' => $id,
		'expires_at'        => $expires,
	);

	if ( function_exists( 'papelito_vendor_integration_set_braspress_operational_state' ) ) {
		papelito_vendor_integration_set_braspress_operational_state( (int) ( $integration['vendor_id'] ?? 0 ), PAPELITO_VENDOR_INTEGRATION_ACTIVE );
	}

	return $result;
}
