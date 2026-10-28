<?php
/**
 * Standalone regression test for the CNPJ provider pipeline (fallback, conflict, alphanumeric,
 * cache, budget).
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

require_once __DIR__ . '/support/cnpj_provider_stubs/class-wp-error.php';

/** Reconhece o erro da fronteira WordPress. */
function is_wp_error( mixed $v ): bool {
	return $v instanceof WP_Error; }
$papelito_test_env = array();
/** Lê somente a configuração declarada pelo cenário. */
function papelito_env( string $key, mixed $fallback = null ): mixed {
	global $papelito_test_env;
	return $papelito_test_env[ $key ] ?? $fallback; }

/* Filter registry (only papelito_cnpj_http_response is used). */
$papelito_filters = array();
/** Registra o filtro de fronteira em memória. */
function add_filter( string $tag, callable $cb ): bool {
	global $papelito_filters;
	$papelito_filters[ $tag ][] = $cb;
	return true; }
/** Executa os filtros reais de coordenação com a fronteira substituída. */
function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
	global $papelito_filters;
	foreach ( $papelito_filters[ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}

/* In-memory transients. */
$papelito_transients = array();
/** Retorna um transient do cenário atual. */
function get_transient( string $k ): mixed {
	global $papelito_transients;
	return $papelito_transients[ $k ] ?? false; }
/** Guarda conteúdo e TTL sem persistir fora do processo. */
function set_transient( string $k, mixed $v, int $ttl ): bool {
	global $papelito_transients;
	$papelito_transients[ $k ]                = $v;
	$GLOBALS['papelito_transient_ttls'][ $k ] = $ttl;
	return true; }
/** Impede acesso real à rede e conserva os argumentos para diagnóstico. */
function wp_remote_get( string $url, array $args = array() ): WP_Error {
	return new WP_Error( 'no_network', 'network disabled in test', array( $url, $args ) ); }
/** Retorna o status HTTP da fixture. */
function wp_remote_retrieve_response_code( mixed $r ): int {
	return is_array( $r ) ? (int) ( $r['status'] ?? 0 ) : 0; }
/** Retorna o corpo HTTP da fixture. */
function wp_remote_retrieve_body( mixed $r ): string {
	return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }

/** Codifica fixtures como a fronteira WordPress usada em produção. */
function wp_json_encode( mixed $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Implementa o stub standalone da função do core.
	return (string) json_encode( $value );
}

/** Escapa a saída de diagnóstico como a função WordPress. */
function esc_html( mixed $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

require_once __DIR__ . '/../includes/cnpj_validation.php';
require_once __DIR__ . '/../includes/cnpj_providers.php';

$failures = 0;
/** Falha o processo quando o contrato observado diverge do esperado. */
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo esc_html( "  PASS: {$label}\n" );
		return; }
	++$failures;
	echo esc_html( "  FAIL: {$label} (expected " . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . ")\n" );
}

/**
 * Installs a scripted HTTP responder keyed by provider host, then clears the transient cache.
 *
 * @param array<string,array<string,mixed>> $by_host host-substring => wp_remote-style response.
 */
function papelito_test_script_http( array $by_host ): void {
	global $papelito_filters, $papelito_transients, $papelito_http_calls;
	$papelito_filters    = array();
	$papelito_transients = array();
	$papelito_http_calls = array();
	add_filter(
		'papelito_cnpj_http_response',
		static function ( mixed $pre, string $url, array $args ) use ( $by_host ): mixed {
			$GLOBALS['papelito_http_calls'][] = array(
				'url'  => $url,
				'args' => $args,
			);
			foreach ( $by_host as $needle => $resp ) {
				if ( false !== strpos( $url, $needle ) ) {
					return $resp;
				}
			}
			return new WP_Error( 'no_match', 'no scripted response' );
		}
	);
}

/** Constrói a resposta HTTP sem simular regras de negócio. */
function papelito_resp( int $status, array $json ): array {
	return array(
		'status' => $status,
		'body'   => wp_json_encode( $json ),
	);
}

$numeric = '11222333000181';
$alpha   = '12ABC34501DE35';

/* 1. BrasilAPI active → early success, source brasilapi. */
papelito_test_script_http(
	array(
		'brasilapi' => papelito_resp(
			200,
			array(
				'cnpj'                         => $numeric,
				'descricao_situacao_cadastral' => 'ATIVA',
				'razao_social'                 => 'ACME LTDA',
			)
		),
	)
);
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'brasilapi active → active', 'active', $r['status'] );
papelito_assert_same( 'active source is brasilapi', 'brasilapi', $r['source'] );

/* 2. BrasilAPI unavailable (500) → fallback to CNPJ.ws active. */
papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp( 500, array() ),
		'publica.cnpj.ws' => papelito_resp(
			200,
			array(
				'razao_social'    => 'ACME',
				'estabelecimento' => array( 'situacao_cadastral' => 'Ativa' ),
			)
		),
	)
);
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'fallback to cnpjws → active', 'active', $r['status'] );
papelito_assert_same( 'fallback source cnpjws', 'cnpjws', $r['source'] );

/* 3. All not_found → not_found. */
papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp( 404, array() ),
		'publica.cnpj.ws' => papelito_resp( 404, array() ),
		'receitaws'       => papelito_resp( 404, array() ),
	)
);
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'all 404 → not_found', 'not_found', $r['status'] );

/* 4. All unavailable → unavailable. */
papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp( 503, array() ),
		'publica.cnpj.ws' => papelito_resp( 429, array() ),
		'receitaws'       => papelito_resp( 500, array() ),
	)
);
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'all 5xx/429 → unavailable', 'unavailable', $r['status'] );

/* 5. Conflict: brasilapi inactive, cnpjws active → conflict (blocks). */
papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp( 200, array( 'descricao_situacao_cadastral' => 'BAIXADA' ) ),
		'publica.cnpj.ws' => papelito_resp( 200, array( 'estabelecimento' => array( 'situacao_cadastral' => 'Ativa' ) ) ),
	)
);
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'active vs inactive → conflict', 'conflict', $r['status'] );

/* 6. Alphanumeric CNPJ + providers without support → provider_unsupported (never invalid). */
papelito_test_script_http( array() );
$r = papelito_cnpj_lookup( $alpha );
papelito_assert_same( 'alpha + unsupported providers → provider_unsupported', 'provider_unsupported', $r['status'] );

/* 7. Structurally invalid CNPJ → invalid, no provider call. */
papelito_test_script_http( array() );
$r = papelito_cnpj_lookup( '11222333000180' );
papelito_assert_same( 'invalid CNPJ → invalid', 'invalid', $r['status'] );

/* 8. Cache: second lookup returns from cache without new HTTP. */
papelito_test_script_http(
	array(
		'brasilapi' => papelito_resp( 200, array( 'descricao_situacao_cadastral' => 'ATIVA' ) ),
	)
);
$first = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'first not from cache', true, empty( $first['from_cache'] ) );
global $papelito_filters;
$papelito_filters = array();
$second           = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'second served from cache', true, ! empty( $second['from_cache'] ) );
papelito_assert_same( 'cached status preserved', 'active', $second['status'] );

/* 9. Cache never stores raw QSA. */
papelito_test_script_http(
	array(
		'brasilapi' => papelito_resp(
			200,
			array(
				'descricao_situacao_cadastral' => 'ATIVA',
				'qsa'                          => array( array( 'nome_socio' => 'FULANO' ) ),
			)
		),
	)
);
papelito_cnpj_lookup( $numeric );
global $papelito_transients;
$cached_entry = $papelito_transients[ PAPELITO_CNPJ_CACHE_PREFIX . $numeric ] ?? array();
papelito_assert_same( 'raw QSA not cached', true, ! isset( $cached_entry['qsa'] ) );

/* 10. A successful response with no registration status is not active. */
papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp( 200, array( 'razao_social' => 'SEM STATUS' ) ),
		'publica.cnpj.ws' => papelito_resp( 404, array() ),
		'receitaws'       => papelito_resp( 404, array() ),
	)
);
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( '200 without status → unavailable', 'unavailable', $r['status'] );

/* 11. Commercial CNPJ.ws endpoint is selected when the token is configured. */
global $papelito_test_env;
$papelito_test_env['PAPELITO_CNPJWS_TOKEN'] = 'test-token';
$seen_commercial                            = false;
global $papelito_filters, $papelito_transients;
$papelito_filters    = array();
$papelito_transients = array();
add_filter(
	'papelito_cnpj_http_response',
	static function ( mixed $pre, string $url, array $args ) use ( &$seen_commercial ): array {
		$GLOBALS['papelito_commercial_args'] = $args;
		if ( false !== strpos( $url, 'comercial.cnpj.ws' ) ) {
			$seen_commercial = true;
			return papelito_resp(
				200,
				array(
					'razao_social'    => 'ACME',
					'estabelecimento' => array( 'situacao_cadastral' => 'Ativa' ),
				)
			); }
		return papelito_resp( 503, array() );
	}
);
papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'token uses commercial cnpjws endpoint', true, $seen_commercial );
$papelito_test_env = array();

define( 'CNPJ_PROVIDER_TEST_MEI_NAME', '12.345.678 ANA PAULA RIBEIRO' );
define( 'CNPJ_PROVIDER_TEST_UPDATED_AT', '2025-04-08T12:30:00.000Z' );
define( 'CNPJ_PROVIDER_TEST_QSA_NAME', 'ANA PAULA RIBEIRO' );

$mei_body   = array(
	'descricao_situacao_cadastral' => 'ATIVA',
	'razao_social'                 => CNPJ_PROVIDER_TEST_MEI_NAME,
	'opcao_pelo_mei'               => true,
	'codigo_natureza_juridica'     => 2135,
	'qsa'                          => array(),
);
$later_body = array( 'estabelecimento' => array( 'situacao_cadastral' => 'Ativa' ) );
papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp( 200, $mei_body ),
		'publica.cnpj.ws' => papelito_resp( 200, $later_body ),
	)
);
$r = papelito_cnpj_lookup( $numeric, true );
papelito_assert_same( 'complete MEI evidence keeps first active source', 'brasilapi', $r['source'] );
papelito_assert_same( 'complete MEI evidence preserves reason social', CNPJ_PROVIDER_TEST_MEI_NAME, $r['legal_name'] );
papelito_assert_same( 'complete MEI requires no later provider', 1, count( $papelito_http_calls ) );
papelito_assert_same( 'cache namespace invalidates the old contract', false, 'papelito_cnpj_' === PAPELITO_CNPJ_CACHE_PREFIX );

papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp( 200, array( 'descricao_situacao_cadastral' => 'ATIVA' ) ),
		'publica.cnpj.ws' => papelito_resp( 200, $later_body ),
	)
);
$r = papelito_cnpj_lookup( $numeric );
papelito_assert_same( 'status-only lookup stops at first active', 1, count( $papelito_http_calls ) );
papelito_assert_same( 'status-only lookup preserves first source', 'brasilapi', $r['source'] );

$cnpjws_body = array(
	'razao_social'      => CNPJ_PROVIDER_TEST_MEI_NAME,
	'simples'           => array( 'mei' => 'Sim' ),
	'natureza_juridica' => array( 'id' => '2135' ),
	'atualizado_em'     => CNPJ_PROVIDER_TEST_UPDATED_AT,
	'estabelecimento'   => array(
		'cnpj'               => $numeric,
		'situacao_cadastral' => 'Ativa',
		'nome_fantasia'      => 'PAPELARIA TESTE',
		'cep'                => '01001000',
		'estado'             => array( 'sigla' => 'SP' ),
		'cidade'             => array( 'nome' => 'São Paulo' ),
		'logradouro'         => 'PRAÇA DA SÉ',
	),
	'socios'            => array(
		array(
			'nome'         => CNPJ_PROVIDER_TEST_QSA_NAME,
			'faixa_etaria' => '31 a 40 anos',
		),
	),
);
$mapped      = papelito_cnpj_map_cnpjws( $cnpjws_body );
papelito_assert_same( 'cnpjws MEI indicator Sim is true', true, $mapped['is_mei'] ?? null );
papelito_assert_same( 'cnpjws normalizes legal nature', '2135', $mapped['legal_nature_code'] ?? null );
papelito_assert_same( 'cnpjws maps address city', 'São Paulo', $mapped['fiscal_address']['city'] ?? null );
papelito_assert_same( 'cnpjws maps address state', 'SP', $mapped['fiscal_address']['state'] ?? null );
papelito_assert_same( 'cnpjws normalizes textual age band', 4, $mapped['qsa'][0]['codigo_faixa_etaria'] ?? null );
papelito_assert_same( 'cnpjws preserves provider update separately', CNPJ_PROVIDER_TEST_UPDATED_AT, $mapped['source_updated_at'] ?? null );
foreach ( array(
	'entre 31 a 40 anos' => 4,
	'Maior de 80 anos'   => 9,
	'Maiores de 80 anos' => 9,
	'desconhecida'       => null,
) as $age_text => $age_code ) {
	$age_body                              = $cnpjws_body;
	$age_body['socios'][0]['faixa_etaria'] = $age_text;
	$mapped                                = papelito_cnpj_map_cnpjws( $age_body );
	papelito_assert_same( 'textual QSA age band: ' . $age_text, $age_code, $mapped['qsa'][0]['codigo_faixa_etaria'] ?? null );
}

$receitaws_body = array(
	'situacao'           => 'ATIVA',
	'nome'               => CNPJ_PROVIDER_TEST_MEI_NAME,
	'simei'              => array( 'optante' => true ),
	'natureza_juridica'  => '213-5 - Empresário (Individual)',
	'cep'                => '01001-000',
	'uf'                 => 'SP',
	'municipio'          => 'São Paulo',
	'logradouro'         => 'PRAÇA DA SÉ',
	'ultima_atualizacao' => CNPJ_PROVIDER_TEST_UPDATED_AT,
);
$mapped         = papelito_cnpj_map_receitaws( $receitaws_body );
papelito_assert_same( 'receitaws preserves strict MEI', true, $mapped['is_mei'] ?? null );
papelito_assert_same( 'receitaws extracts legal nature code only', '2135', $mapped['legal_nature_code'] ?? null );
papelito_assert_same( 'receitaws maps fiscal address', 'São Paulo', $mapped['fiscal_address']['city'] ?? null );
papelito_assert_same( 'receitaws preserves source update separately', CNPJ_PROVIDER_TEST_UPDATED_AT, $mapped['source_updated_at'] ?? null );
foreach ( array( 'Não', 'false', '0', false, null, array( true ) ) as $negative ) {
	$cnpjws_body['simples']['mei'] = $negative;
	$mapped                        = papelito_cnpj_map_cnpjws( $cnpjws_body );
	papelito_assert_same( 'cnpjws does not treat non-affirmative MEI as true', false, $mapped['is_mei'] ?? null );
	$receitaws_body['simei']['optante'] = $negative;
	$mapped                             = papelito_cnpj_map_receitaws( $receitaws_body );
	papelito_assert_same( 'receitaws does not treat non-affirmative MEI as true', false, $mapped['is_mei'] ?? null );
}
$no_update = papelito_cnpj_map_brasilapi( array_merge( $mei_body, array( 'data_situacao_cadastral' => '2020-01-01' ) ) );
papelito_assert_same( 'registration status date is never source updated_at', null, $no_update['source_updated_at'] ?? null );

$qsa_body = array(
	'razao_social'    => 'EMPRESA COM SÓCIOS',
	'estabelecimento' => array( 'situacao_cadastral' => 'Ativa' ),
	'socios'          => array(
		array(
			'nome'               => CNPJ_PROVIDER_TEST_QSA_NAME,
			'faixa_etaria'       => '31 a 40 anos',
			'cpf_cnpj_socio'     => '***123456**',
			'qualificacao_socio' => array( 'descricao' => 'Sócio-Administrador' ),
		),
	),
);
foreach ( array( 'cnpj_cpf_do_socio', 'codigo_faixa_etaria', 'qualificacao_socio' ) as $missing_field ) {
	$partial_entry = array(
		'nome_socio'          => CNPJ_PROVIDER_TEST_QSA_NAME,
		'cnpj_cpf_do_socio'   => '***123456**',
		'codigo_faixa_etaria' => 4,
		'qualificacao_socio'  => 'Sócio-Administrador',
	);
	unset( $partial_entry[ $missing_field ] );
	papelito_test_script_http(
		array(
			'brasilapi'       => papelito_resp(
				200,
				array(
					'descricao_situacao_cadastral' => 'ATIVA',
					'razao_social'                 => 'EMPRESA PARCIAL',
					'qsa'                          => array( $partial_entry ),
				)
			),
			'publica.cnpj.ws' => papelito_resp( 200, $qsa_body ),
		)
	);
	$r = papelito_cnpj_lookup( $numeric, true );
	papelito_assert_same( 'partial QSA continues fallback when missing ' . $missing_field, 'cnpjws', $r['source'] );
}
papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp(
			200,
			array(
				'descricao_situacao_cadastral' => 'ATIVA',
				'qsa'                          => array( array( 'nome_socio' => 'INCOMPLETO' ) ),
			)
		),
		'publica.cnpj.ws' => papelito_resp( 200, $qsa_body ),
		'receitaws'       => papelito_resp(
			200,
			array(
				'situacao' => 'ATIVA',
				'nome'     => 'RESPOSTA SEM QSA',
			)
		),
	)
);
$r = papelito_cnpj_lookup( $numeric, true );
papelito_assert_same( 'evidence lookup falls back to first complete source', 'cnpjws', $r['source'] );
papelito_assert_same( 'QSA comes from one provider only', 1, count( $r['qsa'] ) );
papelito_assert_same( 'QSA complete source retained', CNPJ_PROVIDER_TEST_QSA_NAME, $r['qsa'][0]['nome'] ?? null );
papelito_assert_same( 'evidence lookup stops after sufficient evidence', 2, count( $papelito_http_calls ) );
papelito_assert_same( 'evidence result still never caches QSA', false, isset( $papelito_transients[ PAPELITO_CNPJ_CACHE_PREFIX . $numeric ]['qsa'] ) );

papelito_test_script_http(
	array(
		'brasilapi'       => papelito_resp( 200, array( 'descricao_situacao_cadastral' => 'ATIVA' ) ),
		'publica.cnpj.ws' => papelito_resp( 200, array( 'estabelecimento' => array( 'situacao_cadastral' => 'Baixada' ) ) ),
	)
);
$r = papelito_cnpj_lookup( $numeric, true );
papelito_assert_same( 'observed inactive during evidence fallback produces conflict', 'conflict', $r['status'] );

papelito_test_script_http( array() );
$timeouts = array();
add_filter(
	'papelito_cnpj_http_response',
	static function ( mixed $pre, string $url, array $args ) use ( &$timeouts ): array {
		$timeouts[] = $args['timeout'] ?? null;
		if ( count( $timeouts ) < 3 ) {
			usleep( 2000000 );
		}
		return papelito_resp( 503, array() );
	}
);
papelito_cnpj_lookup( $numeric, true );
papelito_assert_same( 'budget fallback calls each provider once', 3, count( $timeouts ) );
papelito_assert_same( 'HTTP filter observes actual provider timeout', true, is_numeric( $timeouts[0] ) && $timeouts[0] > 0 && $timeouts[0] <= 3 );
papelito_assert_same( 'last timeout is capped by remaining global budget', true, is_numeric( $timeouts[2] ) && $timeouts[2] > 0 && $timeouts[2] < 2.05 );

if ( $failures > 0 ) {
	echo esc_html( "RESULT: {$failures} assertion(s) FAILED\n" );
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
