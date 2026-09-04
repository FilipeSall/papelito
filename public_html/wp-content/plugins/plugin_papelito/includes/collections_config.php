<?php
/**
 * Configuração administrativa das coleções derivadas da vitrine.
 *
 * Coleção derivada é a que não tem curadoria própria: a pertinência sai de um critério calculado
 * (data de publicação em Recém-chegados, preço promocional em Promoções) e o administrador governa
 * apenas o recorte. Segue o padrão de `contact_config.php` e do mínimo de frete grátis — option com
 * normalizador tipado, leitura pública e escrita administrativa — para não criar tabela nova.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PAPELITO_COLLECTIONS_NEW_ARRIVALS_LIMIT_OPTION      = 'papelito_collections_new_arrivals_limit';
const PAPELITO_COLLECTIONS_NEW_ARRIVALS_EXPIRATION_OPTION = 'papelito_collections_new_arrivals_expiration_days';
const PAPELITO_COLLECTIONS_PROMOTIONS_LIMIT_OPTION        = 'papelito_collections_promotions_limit';

const PAPELITO_COLLECTIONS_DEFAULT_NEW_ARRIVALS_LIMIT = 10;
const PAPELITO_COLLECTIONS_MAX_LIMIT                  = 60;
const PAPELITO_COLLECTIONS_MAX_EXPIRATION_DAYS        = 365;
const PAPELITO_COLLECTIONS_NAMESPACE                  = 'papelito/v1';

/**
 * Normaliza um teto de coleção.
 *
 * Zero é valor legítimo e significa "sem teto"; o padrão só entra quando o valor gravado é
 * inutilizável, para que uma option corrompida não derrube a vitrine.
 *
 * @param mixed $value    Valor cru da option ou do payload.
 * @param int   $fallback Valor de retorno quando o cru é inválido.
 * @return int
 */
function papelito_collections_normalize_limit( mixed $value, int $fallback ): int {
	if ( is_bool( $value ) || null === $value || '' === $value ) {
		return $fallback;
	}

	if ( ! is_numeric( $value ) ) {
		return $fallback;
	}

	$normalized = (int) $value;

	if ( $normalized < 0 || $normalized > PAPELITO_COLLECTIONS_MAX_LIMIT ) {
		return $fallback;
	}

	return $normalized;
}

/**
 * Normaliza o prazo de validade de Recém-chegados, em dias.
 *
 * @param mixed $value Valor cru da option ou do payload.
 * @return int Dias, ou 0 para "sem prazo".
 */
function papelito_collections_normalize_expiration_days( mixed $value ): int {
	if ( is_bool( $value ) || null === $value || '' === $value || ! is_numeric( $value ) ) {
		return 0;
	}

	$normalized = (int) $value;

	if ( $normalized <= 0 || $normalized > PAPELITO_COLLECTIONS_MAX_EXPIRATION_DAYS ) {
		return 0;
	}

	return $normalized;
}

/**
 * Configuração vigente das coleções derivadas.
 *
 * @return array<string, array<string, int>>
 */
function papelito_collections_get_config(): array {
	return array(
		'newArrivals' => array(
			'limit'          => papelito_collections_normalize_limit(
				get_option( PAPELITO_COLLECTIONS_NEW_ARRIVALS_LIMIT_OPTION, PAPELITO_COLLECTIONS_DEFAULT_NEW_ARRIVALS_LIMIT ),
				PAPELITO_COLLECTIONS_DEFAULT_NEW_ARRIVALS_LIMIT
			),
			'expirationDays' => papelito_collections_normalize_expiration_days(
				get_option( PAPELITO_COLLECTIONS_NEW_ARRIVALS_EXPIRATION_OPTION, 0 )
			),
		),
		'promotions'  => array(
			'limit' => papelito_collections_normalize_limit(
				get_option( PAPELITO_COLLECTIONS_PROMOTIONS_LIMIT_OPTION, 0 ),
				0
			),
		),
	);
}

/**
 * Valida um inteiro de configuração dentro de uma faixa fechada.
 *
 * Quando o mínimo é zero, ausência de valor equivale a zero — é assim que "sem prazo" e "sem teto"
 * chegam do formulário. Quando o mínimo é um, ausência é erro, porque a coleção não pode ficar sem
 * quantidade nenhuma.
 *
 * @param mixed  $value   Valor cru do payload.
 * @param int    $minimum Menor valor aceito.
 * @param int    $maximum Maior valor aceito.
 * @param string $code    Código do WP_Error quando o valor é inválido.
 * @param string $message Mensagem do WP_Error quando o valor é inválido.
 * @return int|WP_Error
 */
function papelito_collections_validate_count( mixed $value, int $minimum, int $maximum, string $code, string $message ) {
	if ( 0 === $minimum && ( null === $value || '' === $value ) ) {
		return 0;
	}

	// Decimal é recusado em vez de truncado: aceitar 1.5 e gravar 1 devolveria 200 para um valor
	// que o administrador não pediu.
	$is_integer = is_int( $value )
		|| ( is_float( $value ) && floor( $value ) === $value )
		|| ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', trim( $value ) ) );

	if ( ! $is_integer || (int) $value < $minimum || (int) $value > $maximum ) {
		return new WP_Error( $code, $message, array( 'status' => 422 ) );
	}

	return (int) $value;
}

/**
 * Campos configuráveis, cada um com sua option, faixa e erro.
 *
 * A tabela existe para que a validação seja um laço só: com um ramo por campo, a função de escrita
 * passava de trinta de complexidade cognitiva e crescia a cada campo novo.
 *
 * @param array<string, mixed>|null $new_arrivals Bloco de Recém-chegados, ou null quando ausente.
 * @param array<string, mixed>|null $promotions   Bloco de Promoções, ou null quando ausente.
 * @return array<int, array<string, mixed>>
 */
function papelito_collections_configurable_fields( ?array $new_arrivals, ?array $promotions ): array {
	return array(
		array(
			'source'  => $new_arrivals,
			'key'     => 'limit',
			'option'  => PAPELITO_COLLECTIONS_NEW_ARRIVALS_LIMIT_OPTION,
			'minimum' => 1,
			'maximum' => PAPELITO_COLLECTIONS_MAX_LIMIT,
			'code'    => 'papelito_collections_invalid_new_arrivals_limit',
			'message' => sprintf( 'Informe entre 1 e %d produtos em Recém-chegados.', PAPELITO_COLLECTIONS_MAX_LIMIT ),
		),
		array(
			'source'  => $new_arrivals,
			'key'     => 'expirationDays',
			'option'  => PAPELITO_COLLECTIONS_NEW_ARRIVALS_EXPIRATION_OPTION,
			'minimum' => 0,
			'maximum' => PAPELITO_COLLECTIONS_MAX_EXPIRATION_DAYS,
			'code'    => 'papelito_collections_invalid_new_arrivals_expiration',
			'message' => sprintf( 'Informe entre 1 e %d dias, ou nenhum prazo.', PAPELITO_COLLECTIONS_MAX_EXPIRATION_DAYS ),
		),
		array(
			'source'  => $promotions,
			'key'     => 'limit',
			'option'  => PAPELITO_COLLECTIONS_PROMOTIONS_LIMIT_OPTION,
			'minimum' => 0,
			'maximum' => PAPELITO_COLLECTIONS_MAX_LIMIT,
			'code'    => 'papelito_collections_invalid_promotions_limit',
			'message' => sprintf( 'Informe até %d produtos em Promoções, ou nenhum teto.', PAPELITO_COLLECTIONS_MAX_LIMIT ),
		),
	);
}

/**
 * Valida e persiste a configuração das coleções.
 *
 * Recusa o payload inteiro antes de escrever qualquer option: gravar metade da configuração
 * deixaria a vitrine num estado que o administrador não pediu.
 *
 * @param mixed $payload Payload cru do REST.
 * @return array<string, array<string, int>>|WP_Error
 */
function papelito_collections_update_config( mixed $payload ) {
	$invalid_payload = new WP_Error(
		'papelito_collections_invalid_payload',
		'Informe a configuração das coleções.',
		array( 'status' => 422 )
	);

	if ( ! is_array( $payload ) ) {
		return $invalid_payload;
	}

	$new_arrivals = isset( $payload['newArrivals'] ) && is_array( $payload['newArrivals'] ) ? $payload['newArrivals'] : null;
	$promotions   = isset( $payload['promotions'] ) && is_array( $payload['promotions'] ) ? $payload['promotions'] : null;

	if ( null === $new_arrivals && null === $promotions ) {
		return $invalid_payload;
	}

	$writes = array();

	foreach ( papelito_collections_configurable_fields( $new_arrivals, $promotions ) as $field ) {
		if ( null === $field['source'] || ! array_key_exists( $field['key'], $field['source'] ) ) {
			continue;
		}

		$value = papelito_collections_validate_count(
			$field['source'][ $field['key'] ],
			$field['minimum'],
			$field['maximum'],
			$field['code'],
			$field['message']
		);

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$writes[ $field['option'] ] = $value;
	}

	foreach ( $writes as $option => $value ) {
		update_option( $option, $value, false );
	}

	return papelito_collections_get_config();
}

/**
 * Exige capacidade administrativa.
 *
 * @return bool|WP_Error
 */
function papelito_collections_require_admin() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	return new WP_Error(
		'papelito_collections_forbidden',
		'Acesso administrativo necessário.',
		array( 'status' => 403 )
	);
}

/**
 * Registra as rotas REST da configuração de coleções.
 *
 * @return void
 */
function papelito_collections_register_routes(): void {
	register_rest_route(
		PAPELITO_COLLECTIONS_NAMESPACE,
		'/collections-config',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => static fn() => new WP_REST_Response( papelito_collections_get_config(), 200 ),
		)
	);

	register_rest_route(
		PAPELITO_COLLECTIONS_NAMESPACE,
		'/admin/collections-config',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_collections_require_admin',
			'callback'            => static fn() => new WP_REST_Response( papelito_collections_get_config(), 200 ),
		)
	);

	register_rest_route(
		PAPELITO_COLLECTIONS_NAMESPACE,
		'/admin/collections-config',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'permission_callback' => 'papelito_collections_require_admin',
			'callback'            => static function ( WP_REST_Request $request ) {
				$result = papelito_collections_update_config( $request->get_json_params() );

				return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
			},
		)
	);
}

add_action( 'rest_api_init', 'papelito_collections_register_routes' );
