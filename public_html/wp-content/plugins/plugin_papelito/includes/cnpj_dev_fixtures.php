<?php
/**
 * CNPJs fictícios para percorrer o cadastro B2B em ambiente local.
 *
 * O cadastro de pré-conta só avança quando o QSA devolvido pela Receita bate com o CPF, o nome e a
 * data de nascimento digitados. Sem uma empresa real da qual se seja sócio, o fluxo sempre morre em
 * `papelito_b2b_qsa_mismatch`. Este módulo responde à consulta HTTP dos providers com um payload
 * fictício, no formato BRUTO da BrasilAPI — a normalização continua sendo feita pelo adapter de
 * produção, então a simulação não pode divergir do contrato interno.
 *
 * Gate duplo e obrigatório (AND):
 *   - ambiente em `local` ou `development`; E
 *   - PAPELITO_CNPJ_DEV_FIXTURES_ENABLED=true.
 *
 * Nenhuma das duas condições basta sozinha. Fora do gate as fixtures nem sequer são carregadas
 * (`papelito_cnpj_dev_fixtures()` devolve array vazio), os filtros não são registrados e os
 * callbacks ainda revalidam o gate em runtime.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_CNPJ_DEV_FIXTURES_CACHE_PREFIX' ) ) {
	define( 'PAPELITO_CNPJ_DEV_FIXTURES_CACHE_PREFIX', 'papelito_cnpj_devfix_' );
}

/** Host do único provider que recebe o payload completo; os demais são bloqueados. */
if ( ! defined( 'PAPELITO_CNPJ_DEV_FIXTURES_PAYLOAD_HOST' ) ) {
	define( 'PAPELITO_CNPJ_DEV_FIXTURES_PAYLOAD_HOST', 'brasilapi.com.br' );
}

/** Retorna o ambiente WordPress normalizado. */
function papelito_cnpj_dev_fixtures_environment(): string {
	if ( function_exists( 'wp_get_environment_type' ) ) {
		return sanitize_key( wp_get_environment_type() );
	}

	return defined( 'WP_ENVIRONMENT_TYPE' ) ? sanitize_key( (string) WP_ENVIRONMENT_TYPE ) : 'production';
}

/**
 * Gate duplo: ambiente permitido E flag explícita.
 *
 * Ligar a variável de ambiente em produção não tem efeito, e estar em local sem a variável também
 * não. Staging é tratado como produção — não existe WordPress de homologação.
 */
function papelito_cnpj_dev_fixtures_enabled(): bool {
	if ( ! in_array( papelito_cnpj_dev_fixtures_environment(), array( 'local', 'development' ), true ) ) {
		return false;
	}

	return papelito_env_bool( 'PAPELITO_CNPJ_DEV_FIXTURES_ENABLED', false );
}

/**
 * Empresas fictícias, indexadas pelo CNPJ canônico, no shape bruto da BrasilAPI.
 *
 * Fora do gate devolve array vazio: o dado fictício não existe em memória, mesmo que alguém chame
 * esta função diretamente. Acrescentar um cenário é acrescentar uma entrada aqui.
 *
 * A máscara `cnpj_cpf_do_socio` é POSICIONAL (`***456789**` expõe os dígitos 4 a 9 do CPF) e
 * `codigo_faixa_etaria` é comparado contra a idade calculada HOJE — trocar o CPF ou a data de
 * nascimento do cenário exige recalcular os dois.
 *
 * @return array<string,array<string,mixed>>
 */
function papelito_cnpj_dev_fixtures(): array {
	if ( ! papelito_cnpj_dev_fixtures_enabled() ) {
		return array();
	}

	return array(
		// Cenário 1 — QSA completo. Digite CPF 123.456.789-09, nascimento 1985-03-12 e
		// nome "Joana Fixture de Almeida" para cair em review_path = qsa_review.
		'99999001000159' => array(
			'cnpj'                         => '99999001000159',
			'razao_social'                 => 'PAPELANDIA DISTRIBUIDORA DE PAPEIS LTDA',
			'nome_fantasia'                => 'PAPELANDIA',
			'descricao_situacao_cadastral' => 'ATIVA',
			'data_situacao_cadastral'      => '2015-04-20',
			'codigo_natureza_juridica'     => '2062',
			'natureza_juridica'            => 'Sociedade Empresária Limitada',
			'porte'                        => 'DEMAIS',
			'opcao_pelo_mei'               => false,
			'opcao_pelo_simples'           => false,
			'data_inicio_atividade'        => '2015-04-20',
			'cnae_fiscal'                  => 4647801,
			'cnae_fiscal_descricao'        => 'Comércio atacadista de artigos de escritório e de papelaria',
			'cep'                          => '01310100',
			'uf'                           => 'SP',
			'municipio'                    => 'SAO PAULO',
			'bairro'                       => 'BELA VISTA',
			'logradouro'                   => 'AVENIDA PAULISTA',
			'numero'                       => '1000',
			'complemento'                  => 'SALA 12',
			'ddd_telefone_1'               => '1140028922',
			'email'                        => 'contato@papelandia.fixture.test',
			'capital_social'               => 250000,
			'qsa'                          => array(
				array(
					'nome_socio'             => 'JOANA FIXTURE DE ALMEIDA',
					'cnpj_cpf_do_socio'      => '***456789**',
					'qualificacao_socio'     => '49-Sócio-Administrador',
					// Nascimento 1985-03-12: faixa 5 (41 a 50 anos). Vira 6 em 12/03/2036.
					'codigo_faixa_etaria'    => 5,
					'faixa_etaria'           => 'entre 41 a 50 anos',
					'data_entrada_sociedade' => '2015-04-20',
					'identificador_de_socio' => 2,
					'pais'                   => null,
					'codigo_pais'            => null,
				),
			),
		),

		// Cenário 2 — QSA SEM CPF mascarado: qsa_sufficient fica false e o fluxo exige upload de
		// documento. Digite CPF 987.654.321-00, nascimento 1992-11-05 e nome "Ricardo Mock de Souza".
		'99999002000101' => array(
			'cnpj'                         => '99999002000101',
			'razao_social'                 => 'IMPERIO DO PAPEL COMERCIO LTDA',
			'nome_fantasia'                => 'IMPERIO DO PAPEL',
			'descricao_situacao_cadastral' => 'ATIVA',
			'data_situacao_cadastral'      => '2019-09-02',
			'codigo_natureza_juridica'     => '2062',
			'natureza_juridica'            => 'Sociedade Empresária Limitada',
			'porte'                        => 'ME',
			'opcao_pelo_mei'               => false,
			'opcao_pelo_simples'           => true,
			'data_inicio_atividade'        => '2019-09-02',
			'cnae_fiscal'                  => 4761003,
			'cnae_fiscal_descricao'        => 'Comércio varejista de artigos de papelaria',
			'cep'                          => '20040002',
			'uf'                           => 'RJ',
			'municipio'                    => 'RIO DE JANEIRO',
			'bairro'                       => 'CENTRO',
			'logradouro'                   => 'AVENIDA RIO BRANCO',
			'numero'                       => '250',
			'complemento'                  => 'ANDAR 3',
			'ddd_telefone_1'               => '2140028922',
			'email'                        => 'contato@imperiodopapel.fixture.test',
			'capital_social'               => 80000,
			'qsa'                          => array(
				array(
					'nome_socio'             => 'RICARDO MOCK DE SOUZA',
					'qualificacao_socio'     => '22-Sócio',
					// Nascimento 1992-11-05: faixa 4 (31 a 40 anos). Vira 5 em 05/11/2033.
					'codigo_faixa_etaria'    => 4,
					'faixa_etaria'           => 'entre 31 a 40 anos',
					'data_entrada_sociedade' => '2019-09-02',
					'identificador_de_socio' => 2,
					'pais'                   => null,
					'codigo_pais'            => null,
				),
			),
		),
	);
}

/**
 * Impressão digital curta do conjunto de fixtures.
 *
 * Entra no cache key para que editar uma fixture invalide o transient dela automaticamente.
 */
function papelito_cnpj_dev_fixtures_revision(): string {
	return substr( sha1( (string) wp_json_encode( papelito_cnpj_dev_fixtures() ) ), 0, 8 );
}

/** Extrai o CNPJ canônico da URL do provider (todos o colocam no último segmento do path). */
function papelito_cnpj_dev_fixtures_extract_cnpj( string $url ): string {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );

	if ( '' === $path ) {
		return '';
	}

	return papelito_normalize_cnpj( rawurldecode( basename( $path ) ) );
}

/**
 * Isola o cache do CNPJ fictício em um namespace próprio.
 *
 * Sem isso, um `active` fictício ficaria na chave canônica por até 7 dias e continuaria sendo
 * servido depois de o mock ser desligado — inclusive por `/companies/validate-cnpj`, que lê do
 * cache. Limpar no momento do desligamento é impossível: com a flag off este arquivo nem carrega.
 * Com o namespace, a chave canônica nunca recebe dado fictício e o mock desligado dá cache miss.
 */
function papelito_cnpj_dev_fixtures_cache_key( string $cache_key, string $cnpj ): string {
	if ( ! papelito_cnpj_dev_fixtures_enabled() ) {
		return $cache_key;
	}

	$fixtures = papelito_cnpj_dev_fixtures();
	if ( ! isset( $fixtures[ $cnpj ] ) ) {
		return $cache_key;
	}

	return PAPELITO_CNPJ_DEV_FIXTURES_CACHE_PREFIX . papelito_cnpj_dev_fixtures_revision() . '_' . $cnpj;
}

/**
 * Monta uma resposta no formato que o `wp_remote_get()` real devolve.
 *
 * `wp_remote_retrieve_response_code()` lê `$response['response']['code']` — NÃO `$response['status']`.
 * As harnesses standalone em tests/ stubam essas funções lendo `['status']`, e essa convenção vale
 * só lá dentro: devolver aquele formato aqui faz o código virar 0 e todo provider responder
 * `unavailable`, silenciosamente.
 *
 * @return array<string,mixed>
 */
function papelito_cnpj_dev_fixtures_response( int $code, string $body ): array {
	return array(
		'headers'  => array(),
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Not Found',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Responde à consulta HTTP de um provider quando o CNPJ pertence às fixtures.
 *
 * O orquestrador NÃO interrompe o laço ao encontrar `active` (um `active` posterior sobrescreve o
 * anterior), então responder só à BrasilAPI (a) mandaria o CNPJ fictício pela rede aos outros
 * providers e (b) deixaria um payload pobre, sem QSA, vencer o rico. Por isso o default é 404 para
 * QUALQUER host que não seja o do payload — inclusive um provider que venha a ser adicionado depois.
 * O 404 é seguro: o orquestrador testa `active_result` antes de `saw_not_found`.
 *
 * @param array<string,mixed>|WP_Error|null $pre  Resposta já produzida por outro filtro.
 * @param string                            $url  URL que seria consultada.
 * @param array<string,mixed>               $args Argumentos da requisição.
 * @return array<string,mixed>|WP_Error|null
 */
function papelito_cnpj_dev_fixtures_http_response( $pre, string $url, array $args = array() ) {
	unset( $args );

	if ( null !== $pre ) {
		return $pre;
	}

	if ( ! papelito_cnpj_dev_fixtures_enabled() ) {
		return $pre;
	}

	$fixtures = papelito_cnpj_dev_fixtures();
	$cnpj     = papelito_cnpj_dev_fixtures_extract_cnpj( $url );

	if ( '' === $cnpj || ! isset( $fixtures[ $cnpj ] ) ) {
		return $pre;
	}

	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

	if ( PAPELITO_CNPJ_DEV_FIXTURES_PAYLOAD_HOST !== $host ) {
		return papelito_cnpj_dev_fixtures_response( 404, '{}' );
	}

	return papelito_cnpj_dev_fixtures_response( 200, (string) wp_json_encode( $fixtures[ $cnpj ] ) );
}

if ( papelito_cnpj_dev_fixtures_enabled() ) {
	add_filter( 'papelito_cnpj_http_response', 'papelito_cnpj_dev_fixtures_http_response', 10, 3 );
	add_filter( 'papelito_cnpj_cache_key', 'papelito_cnpj_dev_fixtures_cache_key', 10, 2 );
}
