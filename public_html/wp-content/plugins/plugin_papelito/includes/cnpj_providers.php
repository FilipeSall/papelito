<?php
/**
 * Pipeline de validação remota de CNPJ (Fase 0).
 *
 * Adapters BrasilAPI → CNPJ.ws → ReceitaWS, cada um retornando o mesmo contrato normalizado.
 * Toda consulta ocorre no backend. Regras:
 *   - orçamento global síncrono = 6s; timeout por provider ~3s; SEM retry no caminho síncrono;
 *   - cada adapter declara supports_alphanumeric; provider sem suporte, ao receber CNPJ
 *     alfanumérico, é IGNORADO e marcado provider_unsupported (nunca invalid);
 *   - providers que discordam active/inactive → provider_conflict (bloqueia, vai p/ revisão);
 *   - indisponibilidade (timeout/429/5xx) não vira inatividade: tenta o próximo;
 *   - cache por transient pelo CNPJ canônico, TTL por status; QSA completo só em memória;
 *   - NUNCA logar PII, QSA completo, token de provider ou resposta bruta.
 *
 * Fase 0: funções prontas e testadas, NENHUMA rota REST pública registrada. O gate público
 * (Fase 1+) deve ficar atrás de PAPELITO_B2B_COMPANY_MODEL_ENABLED.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_CNPJ_PROVIDER_TIMEOUT' ) ) {
	define( 'PAPELITO_CNPJ_PROVIDER_TIMEOUT', 3 );
}

if ( ! defined( 'PAPELITO_CNPJ_TOTAL_BUDGET' ) ) {
	define( 'PAPELITO_CNPJ_TOTAL_BUDGET', 6 );
}

if ( ! defined( 'PAPELITO_CNPJ_CACHE_PREFIX' ) ) {
	define( 'PAPELITO_CNPJ_CACHE_PREFIX', 'papelito_cnpj_' );
}

if ( ! defined( 'PAPELITO_CNPJ_JSON_CONTENT_TYPE' ) ) {
	define( 'PAPELITO_CNPJ_JSON_CONTENT_TYPE', 'application/json' );
}

/**
 * TTL de cache (segundos) por status de resultado.
 *
 * @return array<string,int>
 */
function papelito_cnpj_cache_ttl(): array {
	return array(
		'active'               => 7 * DAY_IN_SECONDS,
		'inactive'             => DAY_IN_SECONDS,
		'not_found'            => HOUR_IN_SECONDS,
		'unavailable'          => 10 * MINUTE_IN_SECONDS,
		'provider_unsupported' => 10 * MINUTE_IN_SECONDS,
		'conflict'             => 30 * MINUTE_IN_SECONDS,
	);
}

/**
 * Descreve os adapters na ordem de fallback obrigatória.
 *
 * `supports_alphanumeric` é conservador (false) enquanto não houver confirmação oficial de
 * cada provider quanto ao CNPJ alfanumérico.
 *
 * @return array<int,array{source:string,fn:string,supports_alphanumeric:bool}>
 */
function papelito_cnpj_providers(): array {
	return array(
		array(
			'source'                => 'brasilapi',
			'fn'                    => 'papelito_cnpj_adapter_brasilapi',
			'supports_alphanumeric' => false,
		),
		array(
			'source'                => 'cnpjws',
			'fn'                    => 'papelito_cnpj_adapter_cnpjws',
			'supports_alphanumeric' => false,
		),
		array(
			'source'                => 'receitaws',
			'fn'                    => 'papelito_cnpj_adapter_receitaws',
			'supports_alphanumeric' => false,
		),
	);
}

/**
 * Executa uma requisição HTTP para um provider.
 *
 * Extraído para permitir mock em testes via o filtro `papelito_cnpj_http_response`, que, se
 * retornar um valor não-nulo, substitui a chamada de rede.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_cnpj_http_get( string $url, array $args = array() ) {
	$pre = apply_filters( 'papelito_cnpj_http_response', null, $url, $args );
	if ( null !== $pre ) {
		return $pre;
	}

	$defaults = array(
		'timeout' => PAPELITO_CNPJ_PROVIDER_TIMEOUT,
		'headers' => array( 'Accept' => PAPELITO_CNPJ_JSON_CONTENT_TYPE ),
	);

	return wp_remote_get( $url, array_merge( $defaults, $args ) );
}

/**
 * Contrato normalizado base para um resultado de provider.
 *
 * @return array<string,mixed>
 */
function papelito_cnpj_result( string $status, string $source, array $extra = array() ): array {
	return array_merge(
		array(
			'status'     => $status,
			'source'     => $source,
			'checked_at' => gmdate( 'c' ),
		),
		$extra
	);
}

/** Resolve o status normalizado a partir da situacao cadastral. */
function papelito_cnpj_status_from_situacao( string $situacao ): string {
	if ( 'ativa' === $situacao ) {
		return 'active';
	}

	if ( '' === $situacao ) {
		return 'unavailable';
	}

	return 'inactive';
}

/** Retorna um campo textual opcional do corpo do provider. */
function papelito_cnpj_nullable_string( array $body, string $key ): ?string {
	if ( ! isset( $body[ $key ] ) ) {
		return null;
	}

	return (string) $body[ $key ];
}

/** Retorna um campo de lista do corpo do provider. */
function papelito_cnpj_array_value( array $body, string $key ): array {
	if ( ! isset( $body[ $key ] ) || ! is_array( $body[ $key ] ) ) {
		return array();
	}

	return $body[ $key ];
}

/**
 * Interpreta uma resposta HTTP de provider em um resultado normalizado.
 *
 * Diferencia unavailable (timeout/429/5xx) de not_found (404) e de active/inactive.
 *
 * @param array<string,mixed>|WP_Error $response
 * @return array<string,mixed>
 */
function papelito_cnpj_interpret_response( $response, string $source, callable $map_body ): array {
	if ( is_wp_error( $response ) ) {
		return papelito_cnpj_result( 'unavailable', $source, array( 'http_code' => 0 ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 404 === $code ) {
		return papelito_cnpj_result( 'not_found', $source, array( 'http_code' => 404 ) );
	}

	if ( 429 === $code || $code >= 500 || $code < 200 ) {
		return papelito_cnpj_result( 'unavailable', $source, array( 'http_code' => $code ) );
	}

	if ( 200 !== $code || ! is_array( $body ) ) {
		return papelito_cnpj_result( 'unavailable', $source, array( 'http_code' => $code ) );
	}

	return $map_body( $body, $code );
}

/* --- Adapters --- */

/** Normaliza o corpo retornado pela BrasilAPI. */
function papelito_cnpj_map_brasilapi( array $body ): array {
	$situacao = strtolower( (string) ( $body['descricao_situacao_cadastral'] ?? '' ) );
	$status   = papelito_cnpj_status_from_situacao( $situacao );

	return papelito_cnpj_result(
		$status,
		'brasilapi',
		array(
			'http_code'         => 200,
			'cnpj'              => papelito_cnpj_nullable_string( $body, 'cnpj' ),
			'legal_name'        => papelito_cnpj_nullable_string( $body, 'razao_social' ),
			'trade_name'        => papelito_cnpj_nullable_string( $body, 'nome_fantasia' ),
			'legal_nature_code' => papelito_cnpj_nullable_string( $body, 'codigo_natureza_juridica' ),
			'is_mei'            => true === ( $body['opcao_pelo_mei'] ?? false ),
			'fiscal_address'    => array(
				'cep'    => papelito_cnpj_nullable_string( $body, 'cep' ),
				'state'  => papelito_cnpj_nullable_string( $body, 'uf' ),
				'city'   => papelito_cnpj_nullable_string( $body, 'municipio' ),
				'street' => papelito_cnpj_nullable_string( $body, 'logradouro' ),
			),
			'qsa'               => papelito_cnpj_array_value( $body, 'qsa' ),
		)
	);
}

/**
 * Adapter BrasilAPI. Endpoint: /api/cnpj/v1/{cnpj}.
 *
 * @return array<string,mixed>
 */
function papelito_cnpj_adapter_brasilapi( string $cnpj ): array {
	$response = papelito_cnpj_http_get( 'https://brasilapi.com.br/api/cnpj/v1/' . rawurlencode( $cnpj ) );

	return papelito_cnpj_interpret_response(
		$response,
		'brasilapi',
		'papelito_cnpj_map_brasilapi'
	);
}

/**
 * Adapter CNPJ.ws. Endpoint público: /cnpj/{cnpj}. Token comercial opcional via header.
 *
 * @return array<string,mixed>
 */
function papelito_cnpj_adapter_cnpjws( string $cnpj ): array {
	$token = (string) papelito_env( 'PAPELITO_CNPJWS_TOKEN', '' );
	$args  = array();
	if ( '' !== $token ) {
		$args['headers'] = array(
			'Accept'      => PAPELITO_CNPJ_JSON_CONTENT_TYPE,
			'x_api_token' => $token,
		);
	}

	$base_url = '' === $token ? 'https://publica.cnpj.ws/cnpj/' : 'https://comercial.cnpj.ws/cnpj/';
	$response = papelito_cnpj_http_get( $base_url . rawurlencode( $cnpj ), $args );

	return papelito_cnpj_interpret_response(
		$response,
		'cnpjws',
		'papelito_cnpj_map_cnpjws'
	);
}

/** Normaliza o corpo retornado pelo CNPJ.ws. */
function papelito_cnpj_map_cnpjws( array $body ): array {
	$situacao = '';
	if ( isset( $body['estabelecimento'] ) && is_array( $body['estabelecimento'] ) ) {
		$situacao = strtolower( (string) ( $body['estabelecimento']['situacao_cadastral'] ?? '' ) );
	}

	return papelito_cnpj_result(
		papelito_cnpj_status_from_situacao( $situacao ),
		'cnpjws',
		array(
			'http_code'  => 200,
			'legal_name' => papelito_cnpj_nullable_string( $body, 'razao_social' ),
			'qsa'        => papelito_cnpj_array_value( $body, 'socios' ),
		)
	);
}

/**
 * Adapter ReceitaWS. Endpoint público: /v1/cnpj/{cnpj}.
 *
 * @return array<string,mixed>
 */
function papelito_cnpj_map_receitaws( array $body ): array {
	if ( isset( $body['status'] ) && 'ERROR' === strtoupper( (string) $body['status'] ) ) {
		return papelito_cnpj_result( 'not_found', 'receitaws', array( 'http_code' => 200 ) );
	}

	$situacao = strtolower( (string) ( $body['situacao'] ?? '' ) );

	return papelito_cnpj_result(
		papelito_cnpj_status_from_situacao( $situacao ),
		'receitaws',
		array(
			'http_code'  => 200,
			'legal_name' => papelito_cnpj_nullable_string( $body, 'nome' ),
			'trade_name' => papelito_cnpj_nullable_string( $body, 'fantasia' ),
			'qsa'        => papelito_cnpj_array_value( $body, 'qsa' ),
		)
	);
}

function papelito_cnpj_adapter_receitaws( string $cnpj ): array {
	$token = (string) papelito_env( 'PAPELITO_RECEITAWS_TOKEN', '' );
	$args  = array();
	if ( '' !== $token ) {
		$args['headers'] = array(
			'Accept'        => PAPELITO_CNPJ_JSON_CONTENT_TYPE,
			'Authorization' => 'Bearer ' . $token,
		);
	}

	$response = papelito_cnpj_http_get( 'https://receitaws.com.br/v1/cnpj/' . rawurlencode( $cnpj ), $args );

	return papelito_cnpj_interpret_response(
		$response,
		'receitaws',
		'papelito_cnpj_map_receitaws'
	);
}

/* --- Orquestrador --- */

/** Consulta todos os providers e coleta as evidencias de fallback. */
function papelito_cnpj_collect_provider_results( string $cnpj ): array {
	$is_alpha = papelito_cnpj_is_alphanumeric( $cnpj );
	$deadline = microtime( true ) + PAPELITO_CNPJ_TOTAL_BUDGET;
	$state    = array(
		'last_result'     => null,
		'active_result'   => null,
		'inactive_result' => null,
		'saw_unsupported' => false,
		'saw_unavailable' => false,
		'saw_not_found'   => false,
	);

	foreach ( papelito_cnpj_providers() as $provider ) {
		if ( microtime( true ) >= $deadline ) {
			break;
		}

		if ( $is_alpha && ! $provider['supports_alphanumeric'] ) {
			$state['saw_unsupported'] = true;
			$state['last_result']     = papelito_cnpj_result( 'provider_unsupported', $provider['source'], array( 'cnpj' => $cnpj ) );
			continue;
		}

		$started              = microtime( true );
		$result               = call_user_func( $provider['fn'], $cnpj );
		$result['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$state['last_result'] = $result;

		switch ( $result['status'] ) {
			case 'active':
				$state['active_result'] = $result;
				break;
			case 'inactive':
				$state['inactive_result'] = $result;
				break;
			case 'unavailable':
				$state['saw_unavailable'] = true;
				break;
			case 'not_found':
				$state['saw_not_found'] = true;
				break;
			default:
				break;
		}
	}

	return $state;
}

/** Escolhe o resultado final segundo a precedencia de estados dos providers. */
function papelito_cnpj_finalize_lookup( string $cnpj, string $cache_key, array $state ): array {
	$active_result   = $state['active_result'];
	$inactive_result = $state['inactive_result'];
	$last_result     = $state['last_result'];

	if ( null !== $active_result && null !== $inactive_result ) {
		$final = papelito_cnpj_result( 'conflict', 'multiple', array( 'cnpj' => $cnpj ) );
		papelito_cnpj_cache_result( $cache_key, $final );
		return $final;
	}

	if ( null !== $active_result ) {
		papelito_cnpj_cache_result( $cache_key, $active_result );
		return $active_result;
	}

	if ( null !== $inactive_result ) {
		papelito_cnpj_cache_result( $cache_key, $inactive_result );
		return $inactive_result;
	}

	if ( $state['saw_unavailable'] ) {
		$final = papelito_cnpj_result( 'unavailable', 'multiple', array( 'cnpj' => $cnpj ) );
		papelito_cnpj_cache_result( $cache_key, $final );
		return $final;
	}

	if ( $state['saw_not_found'] ) {
		$final = papelito_cnpj_result( 'not_found', 'multiple', array( 'cnpj' => $cnpj ) );
		papelito_cnpj_cache_result( $cache_key, $final );
		return $final;
	}

	if ( $state['saw_unsupported'] && ( null === $last_result || 'provider_unsupported' === $last_result['status'] ) ) {
		$final = papelito_cnpj_result( 'provider_unsupported', 'multiple', array( 'cnpj' => $cnpj ) );
		papelito_cnpj_cache_result( $cache_key, $final );
		return $final;
	}

	if ( null === $last_result ) {
		return papelito_cnpj_result( 'unavailable', 'none', array( 'cnpj' => $cnpj ) );
	}

	papelito_cnpj_cache_result( $cache_key, $last_result );
	return $last_result;
}

/**
 * Consulta o CNPJ nos providers seguindo a ordem de fallback e as regras de status.
 *
 * Retorna o contrato normalizado. Persiste apenas evidências mínimas — o QSA que sai daqui
 * deve ser tratado como transitório (não persistir bruto).
 *
 * @return array<string,mixed>
 */
function papelito_cnpj_lookup( string $raw_cnpj, bool $include_evidence = false ): array {
	$cnpj = papelito_normalize_cnpj( $raw_cnpj );

	if ( '' === $cnpj || ! papelito_validate_cnpj( $cnpj ) ) {
		return papelito_cnpj_result( 'invalid', 'local' );
	}

	// Ponto de extensão do namespace do cache. Resultados simulados (fixtures de desenvolvimento)
	// NÃO podem ocupar a chave canônica: o transient é lido antes do filtro HTTP e sobrevive por
	// até 7 dias, então um `active` fictício continuaria sendo servido depois de o mock ser
	// desligado — e nesse momento não há mais código do mock rodando para limpar nada.
	$cache_key = apply_filters( 'papelito_cnpj_cache_key', PAPELITO_CNPJ_CACHE_PREFIX . $cnpj, $cnpj );
	$cached    = get_transient( $cache_key );
	if ( ! $include_evidence && is_array( $cached ) ) {
		$cached['from_cache'] = true;
		return $cached;
	}

	$state = papelito_cnpj_collect_provider_results( $cnpj );
	return papelito_cnpj_finalize_lookup( $cnpj, $cache_key, $state );
}

/**
 * Persiste o resultado no transient com TTL correspondente ao status.
 *
 * @param array<string,mixed> $result
 */
function papelito_cnpj_cache_result( string $cache_key, array $result ): void {
	$ttls = papelito_cnpj_cache_ttl();
	$ttl  = $ttls[ $result['status'] ] ?? MINUTE_IN_SECONDS * 5;

	// Não cachear o QSA bruto: guardar só a evidência mínima já normalizada.
	$to_cache = $result;
	unset( $to_cache['qsa'] );

	set_transient( $cache_key, $to_cache, $ttl );
}
