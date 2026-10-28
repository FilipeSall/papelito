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
	define( 'PAPELITO_CNPJ_CACHE_PREFIX', 'papelito_cnpj_v2_' );
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
	$defaults = array(
		'timeout'     => PAPELITO_CNPJ_PROVIDER_TIMEOUT,
		'headers'     => array( 'Accept' => PAPELITO_CNPJ_JSON_CONTENT_TYPE ),
		'redirection' => 0,
	);
	$args     = array_merge( $defaults, $args );
	$pre      = apply_filters( 'papelito_cnpj_http_response', null, $url, $args );
	if ( null !== $pre ) {
		return $pre;
	}

	return wp_remote_get( $url, $args );
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
	if ( ! isset( $body[ $key ] ) || ! is_scalar( $body[ $key ] ) ) {
		return null;
	}

	return (string) $body[ $key ];
}

/** Aceita somente indicadores afirmativos explícitos, nunca a coerção booleana de texto. */
function papelito_cnpj_is_mei( mixed $value ): bool {
	if ( true === $value || 1 === $value ) {
		return true;
	}

	return is_string( $value ) && in_array( strtolower( trim( $value ) ), array( 'sim', 'true', '1' ), true );
}

/** Extrai apenas o código de natureza; dígitos na descrição não compõem o código. */
function papelito_cnpj_legal_nature_code( mixed $value ): ?string {
	if ( ! is_scalar( $value ) || ! preg_match( '/^(\d{3})[-.]?(\d)(?:\D|$)/', trim( (string) $value ), $matches ) ) {
		return null;
	}

	return $matches[1] . $matches[2];
}

/** Normaliza faixas textuais conhecidas sem criar evidência para faixas desconhecidas. */
function papelito_cnpj_normalize_qsa( array $entries ): array {
	foreach ( $entries as &$entry ) {
		if ( ! is_array( $entry ) || isset( $entry['codigo_faixa_etaria'] ) ) {
			continue;
		}

		$band = papelito_cnpj_nullable_string( $entry, 'faixa_etaria' );
		if ( null !== $band && preg_match( '/^(?:entre\s+)?(\d+)\s+a\s+(\d+)\s+anos$/i', trim( $band ), $matches ) ) {
			$ranges = array( '0-12', '13-20', '21-30', '31-40', '41-50', '51-60', '61-70', '71-80' );
			$index  = array_search( $matches[1] . '-' . $matches[2], $ranges, true );
			if ( false !== $index ) {
				$entry['codigo_faixa_etaria'] = $index + 1;
			}
		} elseif ( null !== $band && preg_match( '/^(?:maior(?:es)? de|acima de)\s+80\s+anos$/i', trim( $band ) ) ) {
			$entry['codigo_faixa_etaria'] = 9;
		}
	}
	unset( $entry );

	return $entries;
}

/** Retorna atualização declarada pela fonte; consulta e situação cadastral não são atualização. */
function papelito_cnpj_source_updated_at( array $body, array $keys ): ?string {
	foreach ( $keys as $key ) {
		$value = papelito_cnpj_nullable_string( $body, $key );
		if ( null !== $value && '' !== trim( $value ) ) {
			return $value;
		}
	}

	return null;
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
			'legal_nature_code' => papelito_cnpj_legal_nature_code( $body['codigo_natureza_juridica'] ?? null ),
			'is_mei'            => papelito_cnpj_is_mei( $body['opcao_pelo_mei'] ?? null ),
			'source_updated_at' => papelito_cnpj_source_updated_at( $body, array( 'updated_at', 'atualizado_em', 'data_atualizacao' ) ),
			'fiscal_address'    => array(
				'cep'    => papelito_cnpj_nullable_string( $body, 'cep' ),
				'state'  => papelito_cnpj_nullable_string( $body, 'uf' ),
				'city'   => papelito_cnpj_nullable_string( $body, 'municipio' ),
				'street' => papelito_cnpj_nullable_string( $body, 'logradouro' ),
			),
			'qsa'               => papelito_cnpj_normalize_qsa( papelito_cnpj_array_value( $body, 'qsa' ) ),
			'cnaes'             => function_exists( 'papelito_vendor_cnae_extract' ) ? papelito_vendor_cnae_extract( $body ) : null,
		)
	);
}

/**
 * Adapter BrasilAPI. Endpoint: /api/cnpj/v1/{cnpj}.
 *
 * @return array<string,mixed>
 */
function papelito_cnpj_adapter_brasilapi( string $cnpj, float $timeout = PAPELITO_CNPJ_PROVIDER_TIMEOUT ): array {
	$response = papelito_cnpj_http_get( 'https://brasilapi.com.br/api/cnpj/v1/' . rawurlencode( $cnpj ), array( 'timeout' => $timeout ) );

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
function papelito_cnpj_adapter_cnpjws( string $cnpj, float $timeout = PAPELITO_CNPJ_PROVIDER_TIMEOUT ): array {
	$token = (string) papelito_env( 'PAPELITO_CNPJWS_TOKEN', '' );
	$args  = array( 'timeout' => $timeout );
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
	$establishment = papelito_cnpj_array_value( $body, 'estabelecimento' );
	$nature        = papelito_cnpj_array_value( $body, 'natureza_juridica' );
	$simples       = papelito_cnpj_array_value( $body, 'simples' );
	$state         = papelito_cnpj_array_value( $establishment, 'estado' );
	$city          = papelito_cnpj_array_value( $establishment, 'cidade' );
	$situacao      = strtolower( (string) ( $establishment['situacao_cadastral'] ?? '' ) );

	return papelito_cnpj_result(
		papelito_cnpj_status_from_situacao( $situacao ),
		'cnpjws',
		array(
			'http_code'         => 200,
			'cnpj'              => papelito_cnpj_nullable_string( $establishment, 'cnpj' ),
			'legal_name'        => papelito_cnpj_nullable_string( $body, 'razao_social' ),
			'trade_name'        => papelito_cnpj_nullable_string( $establishment, 'nome_fantasia' ),
			'legal_nature_code' => papelito_cnpj_legal_nature_code( $nature['id'] ?? null ),
			'is_mei'            => papelito_cnpj_is_mei( $simples['mei'] ?? null ),
			'source_updated_at' => papelito_cnpj_source_updated_at( $body, array( 'atualizado_em' ) ),
			'fiscal_address'    => array(
				'cep'    => papelito_cnpj_nullable_string( $establishment, 'cep' ),
				'state'  => papelito_cnpj_nullable_string( $state, 'sigla' ),
				'city'   => papelito_cnpj_nullable_string( $city, 'nome' ),
				'street' => papelito_cnpj_nullable_string( $establishment, 'logradouro' ),
			),
			'qsa'               => papelito_cnpj_normalize_qsa( papelito_cnpj_array_value( $body, 'socios' ) ),
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
	$simei    = papelito_cnpj_array_value( $body, 'simei' );

	return papelito_cnpj_result(
		papelito_cnpj_status_from_situacao( $situacao ),
		'receitaws',
		array(
			'http_code'         => 200,
			'cnpj'              => papelito_cnpj_nullable_string( $body, 'cnpj' ),
			'legal_name'        => papelito_cnpj_nullable_string( $body, 'nome' ),
			'trade_name'        => papelito_cnpj_nullable_string( $body, 'fantasia' ),
			'legal_nature_code' => papelito_cnpj_legal_nature_code( $body['natureza_juridica'] ?? null ),
			'is_mei'            => papelito_cnpj_is_mei( $simei['optante'] ?? null ),
			'source_updated_at' => papelito_cnpj_source_updated_at( $body, array( 'ultima_atualizacao' ) ),
			'fiscal_address'    => array(
				'cep'    => papelito_cnpj_nullable_string( $body, 'cep' ),
				'state'  => papelito_cnpj_nullable_string( $body, 'uf' ),
				'city'   => papelito_cnpj_nullable_string( $body, 'municipio' ),
				'street' => papelito_cnpj_nullable_string( $body, 'logradouro' ),
			),
			'qsa'               => papelito_cnpj_normalize_qsa( papelito_cnpj_array_value( $body, 'qsa' ) ),
		)
	);
}

/** Consulta a ReceitaWS sem retry; o timeout recebido respeita o orçamento do orquestrador. */
function papelito_cnpj_adapter_receitaws( string $cnpj, float $timeout = PAPELITO_CNPJ_PROVIDER_TIMEOUT ): array {
	$token = (string) papelito_env( 'PAPELITO_RECEITAWS_TOKEN', '' );
	$args  = array( 'timeout' => $timeout );
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

/** Exige os campos comparáveis do mesmo sócio; qualificação preenchida não autoriza aprovação. */
function papelito_cnpj_qsa_is_comparable( array $entry ): bool {
	$partner       = papelito_cnpj_array_value( $entry, 'socio' );
	$name          = $entry['nome_socio'] ?? $entry['nome'] ?? $partner['nome'] ?? '';
	$document      = $entry['cnpj_cpf_do_socio'] ?? $entry['cpf_cnpj_socio'] ?? $entry['cpf'] ?? $partner['cpf_cnpj_socio'] ?? $partner['cpf'] ?? '';
	$qualification = $entry['qualificacao_socio'] ?? $entry['qualificacao'] ?? $entry['qual'] ?? '';
	if ( is_array( $qualification ) ) {
		$qualification = $qualification['descricao'] ?? $qualification['nome'] ?? '';
	}
	$qualification = is_scalar( $qualification ) ? strtolower( trim( (string) $qualification ) ) : '';
	$mask          = is_scalar( $document ) ? preg_replace( '/[^0-9*]+/', '', (string) $document ) : '';
	$age_band      = papelito_cnpj_nullable_string( $entry, 'codigo_faixa_etaria' );

	return is_scalar( $name ) && '' !== trim( (string) $name )
		&& 11 === strlen( $mask ) && preg_match_all( '/\d/', $mask ) >= 6
		&& in_array( $age_band, array( '1', '2', '3', '4', '5', '6', '7', '8', '9' ), true )
		&& ! in_array( $qualification, array( '', 'unknown', 'desconhecida', 'não informada', 'nao informada' ), true );
}

/** Identifica evidência suficiente de uma única fonte, sem decidir elegibilidade do representante. */
function papelito_cnpj_has_ownership_evidence( array $result ): bool {
	if ( '' === trim( (string) ( $result['legal_name'] ?? '' ) ) ) {
		return false;
	}

	if ( true === ( $result['is_mei'] ?? false ) && '2135' === ( $result['legal_nature_code'] ?? null ) ) {
		return true;
	}

	foreach ( $result['qsa'] ?? array() as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		if ( papelito_cnpj_qsa_is_comparable( $entry ) ) {
			return true;
		}
	}

	return false;
}

/** Interrompe o fallback ao obter o contrato pedido ou uma divergência já observada. */
function papelito_cnpj_collection_complete( array $state, bool $include_evidence ): bool {
	if ( null === $state['active_result'] ) {
		return false;
	}

	return null !== $state['inactive_result'] || ! $include_evidence || papelito_cnpj_has_ownership_evidence( $state['active_result'] );
}

/** Consulta o fallback só enquanto faltarem evidências; nunca combina QSA entre fontes. */
function papelito_cnpj_collect_provider_results( string $cnpj, bool $include_evidence = false ): array {
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
		$remaining = $deadline - microtime( true );
		if ( $remaining <= 0 ) {
			break;
		}

		if ( $is_alpha && ! $provider['supports_alphanumeric'] ) {
			$state['saw_unsupported'] = true;
			$state['last_result']     = papelito_cnpj_result( 'provider_unsupported', $provider['source'], array( 'cnpj' => $cnpj ) );
			continue;
		}

		$started              = microtime( true );
		$result               = call_user_func( $provider['fn'], $cnpj, min( PAPELITO_CNPJ_PROVIDER_TIMEOUT, $remaining ) );
		$result['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$state['last_result'] = $result;

		switch ( $result['status'] ) {
			case 'active':
				if ( null === $state['active_result'] || papelito_cnpj_has_ownership_evidence( $result ) ) {
					$state['active_result'] = $result;
				}
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

		if ( papelito_cnpj_collection_complete( $state, $include_evidence ) ) {
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

	$state = papelito_cnpj_collect_provider_results( $cnpj, $include_evidence );
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
