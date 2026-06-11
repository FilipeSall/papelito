<?php
/**
 * Cliente HTTP do Pagar.me Core API v5.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PAGARME_TIMEOUT' ) ) {
	define( 'PAPELITO_PAGARME_TIMEOUT', 20 );
}

/**
 * Retorna a URL base da API.
 */
function papelito_pagarme_base_url(): string {
	$base_url = papelito_env( 'PAGARME_BASE_URL', 'https://api.pagar.me/core/v5' );

	return rtrim( is_string( $base_url ) ? $base_url : 'https://api.pagar.me/core/v5', '/' );
}

/**
 * Retorna a secret key configurada.
 */
function papelito_pagarme_secret_key(): string {
	$key = papelito_env( 'PAGARME_SECRET_KEY', '' );

	return is_string( $key ) ? trim( $key ) : '';
}

/**
 * Retorna o usuario de Basic Auth do webhook.
 */
function papelito_pagarme_webhook_user(): string {
	$value = papelito_env( 'PAGARME_WEBHOOK_USER', '' );

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Retorna a senha de Basic Auth do webhook.
 */
function papelito_pagarme_webhook_pass(): string {
	$value = papelito_env( 'PAGARME_WEBHOOK_PASS', '' );

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Indica se a integracao server-side esta configurada.
 */
function papelito_pagarme_is_configured(): bool {
	return '' !== papelito_pagarme_secret_key();
}

/**
 * Gera a chave de idempotencia persistida por pedido.
 *
 * @param WC_Order $order Pedido WooCommerce.
 */
function papelito_pagarme_order_idempotency_key( $order ): string {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) ) {
		return wp_generate_uuid4();
	}

	$key = sanitize_text_field( (string) $order->get_meta( '_papelito_pagarme_idempotency_key', true ) );

	if ( '' !== $key ) {
		return $key;
	}

	$key = wp_generate_uuid4();
	$order->update_meta_data( '_papelito_pagarme_idempotency_key', $key );
	$order->save();

	return $key;
}

/**
 * Extrai uma mensagem amigavel da resposta do Pagar.me.
 *
 * @param mixed $body Corpo decodificado.
 */
function papelito_pagarme_error_message( $body, int $status ): string {
	if ( is_string( $body ) && '' !== trim( $body ) ) {
		return sanitize_text_field( $body );
	}

	if ( is_array( $body ) ) {
		$message = sanitize_text_field( (string) ( $body['message'] ?? '' ) );
		$details = papelito_pagarme_collect_error_details( $body );

		if ( '' !== $message && ! empty( $details ) ) {
			return $message . ' (' . implode( '; ', $details ) . ')';
		}

		if ( '' !== $message ) {
			return $message;
		}

		if ( ! empty( $details ) ) {
			return implode( '; ', $details );
		}
	}

	return sprintf( 'Pagar.me retornou erro HTTP %d.', $status );
}

/**
 * Coleta as mensagens de erro de validacao da resposta do Pagar.me v5.
 *
 * A v5 costuma retornar `errors` como mapa `{ "request.campo": ["msg1"] }`,
 * mas tambem aceita lista de objetos `{ message|description }`. Cobre os dois.
 *
 * @param array<string,mixed> $body Corpo decodificado.
 * @return array<int,string>
 */
function papelito_pagarme_collect_error_details( array $body ): array {
	$sources = array();

	if ( isset( $body['errors'] ) && is_array( $body['errors'] ) ) {
		$sources[] = $body['errors'];
	}

	if ( isset( $body['gateway_response']['errors'] ) && is_array( $body['gateway_response']['errors'] ) ) {
		$sources[] = $body['gateway_response']['errors'];
	}

	$details = array();

	foreach ( $sources as $errors ) {
		foreach ( $errors as $field => $error ) {
			$label = is_string( $field ) ? sanitize_text_field( $field ) : '';

			if ( is_array( $error ) ) {
				$message = sanitize_text_field( (string) ( $error['message'] ?? $error['description'] ?? '' ) );

				if ( '' === $message ) {
					foreach ( $error as $item ) {
						if ( is_string( $item ) && '' !== trim( $item ) ) {
							$message = sanitize_text_field( $item );
							break;
						}
					}
				}
			} else {
				$message = sanitize_text_field( (string) $error );
			}

			if ( '' === $message ) {
				continue;
			}

			$details[] = '' !== $label && ! is_numeric( $field ) ? $label . ': ' . $message : $message;

			if ( count( $details ) >= 3 ) {
				return $details;
			}
		}
	}

	return $details;
}

/**
 * Faz uma requisicao autenticada ao Pagar.me.
 *
 * @param string               $method Metodo HTTP.
 * @param string               $path   Caminho relativo.
 * @param array<string,mixed>|null $body Corpo JSON.
 * @param array<string,mixed>  $args   Opcoes adicionais.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_request( string $method, string $path, ?array $body = null, array $args = array() ) {
	$secret_key = papelito_pagarme_secret_key();

	if ( '' === $secret_key ) {
		return new WP_Error(
			'papelito_pagarme_not_configured',
			'Pagar.me nao configurado no ambiente.',
			array( 'status' => 500 )
		);
	}

	$url     = papelito_pagarme_base_url() . '/' . ltrim( $path, '/' );
	$headers = array(
		'Accept'        => 'application/json',
		'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ),
	);

	if ( null !== $body ) {
		$headers['Content-Type'] = 'application/json';
	}

	if ( isset( $args['idempotency_key'] ) && is_string( $args['idempotency_key'] ) && '' !== $args['idempotency_key'] ) {
		$headers['Idempotency-Key'] = $args['idempotency_key'];
	}

	$request_args = array(
		'method'      => strtoupper( $method ),
		'timeout'     => isset( $args['timeout'] ) ? (int) $args['timeout'] : PAPELITO_PAGARME_TIMEOUT,
		'headers'     => $headers,
		'body'        => null !== $body ? wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
		'data_format' => 'body',
	);

	$response = wp_remote_request( $url, $request_args );

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'papelito_pagarme_network_error',
			$response->get_error_message(),
			array( 'status' => 502 )
		);
	}

	$status   = (int) wp_remote_retrieve_response_code( $response );
	$raw_body = (string) wp_remote_retrieve_body( $response );
	$decoded  = '' !== $raw_body ? json_decode( $raw_body, true ) : array();

	if ( $status < 200 || $status >= 300 ) {
		error_log(
			sprintf(
				'[papelito_pagarme] %s %s -> HTTP %d: %s',
				strtoupper( $method ),
				$path,
				$status,
				$raw_body
			)
		);

		return new WP_Error(
			'papelito_pagarme_request_failed',
			papelito_pagarme_error_message( $decoded, $status ),
			array(
				'status'        => $status,
				'pagarme_body'  => is_array( $decoded ) ? $decoded : array(),
				'response_body' => $raw_body,
			)
		);
	}

	return is_array( $decoded ) ? $decoded : array();
}

