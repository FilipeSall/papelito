<?php
/**
 * Adapter HTTP Braspress para cotação, sem chamar rede fora do provider elegível.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_BRASPRESS_QUOTE_URL = 'https://api.braspress.com/v1/cotacao/calcular/json';

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
 * Valida o contrato físico independente usado pela Braspress.
 *
 * @param array<string,mixed> $package Pacote com peso, volumes e cubagem em metros.
 * @return bool Se o pacote pode ser cotado.
 */
function papelito_braspress_package_is_valid( array $package ): bool {
	$weight  = $package['weight_kg'] ?? null;
	$volumes = $package['volumes'] ?? null;
	$cubagem = $package['cubagem'] ?? null;

	if ( ! is_numeric( $weight ) || (float) $weight <= 0 || ! is_int( $volumes ) || $volumes <= 0 || ! is_array( $cubagem ) || empty( $cubagem ) ) {
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
	if ( ! papelito_vendor_integration_config_complete( $config ) || ! papelito_braspress_package_is_valid( $package ) || 14 !== strlen( papelito_vendor_integration_normalize_document( $recipient_cnpj ) ) || 8 !== strlen( papelito_vendor_integration_normalize_cep( $destination_cep ) ) || $merchandise_value_cents <= 0 ) {
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

	$payload = array(
		'cnpjRemetente'    => $config['sender_cnpj'],
		'cnpjDestinatario' => papelito_vendor_integration_normalize_document( $recipient_cnpj ),
		'modal'            => $config['modal'],
		'tipoFrete'        => $config['freight_type'],
		'cepOrigem'        => $config['origin_cep'],
		'cepDestino'       => papelito_vendor_integration_normalize_cep( $destination_cep ),
		'vlrMercadoria'    => papelito_braspress_decimal( $merchandise_value_cents ),
		'peso'             => 'g' === $config['weight_unit'] ? (int) round( (float) $package['weight_kg'] * 1000 ) : (float) $package['weight_kg'],
		'volumes'          => $package['volumes'],
		'cubagem'          => $cubagem,
	);

	if ( '3' === $config['freight_type'] ) {
		$payload['cnpjConsignado'] = $config['consignee_cnpj'];
	}

	return $payload;
}

/**
 * Calcula a expiração no fim do dia comercial configurado.
 *
 * @param string $timezone Timezone IANA contratual.
 * @return string Data UTC em ISO-8601 ou vazio quando inválida.
 */
function papelito_braspress_quote_expiry( string $timezone ): string {
	try {
		$local = new DateTimeImmutable( 'today 23:59:59', new DateTimeZone( $timezone ) );
	} catch ( Exception $exception ) {
		return '';
	}

	return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM );
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
	if ( 401 === $status || 403 === $status ) {
		if ( function_exists( 'papelito_vendor_integration_set_braspress_operational_state' ) ) {
			papelito_vendor_integration_set_braspress_operational_state( (int) ( $integration['vendor_id'] ?? 0 ), PAPELITO_VENDOR_INTEGRATION_INVALID, 'credentials_invalid' );
		}
		return new WP_Error(
			'papelito_braspress_credentials_invalid',
			'As credenciais Braspress precisam ser atualizadas.',
			array(
				'status'          => 502,
				'provider_status' => $status,
			)
		);
	}

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		return new WP_Error(
			'papelito_braspress_quote_failed',
			'Não foi possível cotar pela Braspress.',
			array(
				'status'          => 502,
				'provider_status' => $status,
			)
		);
	}

	$id    = isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
	$price = $body['totalFrete'] ?? null;
	$days  = $body['prazo'] ?? null;
	if ( '' === $id || ! is_numeric( $price ) || (float) $price < 0 || ! is_numeric( $days ) || (int) $days < 0 ) {
		return new WP_Error( 'papelito_braspress_response_invalid', 'A Braspress retornou uma cotação inválida.', array( 'status' => 502 ) );
	}

	$timezone = (string) ( $integration['config']['quote_timezone'] ?? '' );
	$expires  = papelito_braspress_quote_expiry( $timezone );
	if ( '' === $expires ) {
		return new WP_Error( 'papelito_braspress_expiry_invalid', 'A validade da cotação Braspress não está configurada.', array( 'status' => 503 ) );
	}

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
