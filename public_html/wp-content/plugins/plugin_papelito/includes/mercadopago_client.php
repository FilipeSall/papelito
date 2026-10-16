<?php
/**
 * Configuração da aplicação e transporte HTTP do Mercado Pago.
 *
 * Centraliza segredos da aplicação (client id/secret, segredo de webhook, redirect URI e ambiente
 * explícito test/live) e o único caminho de saída para a API, em host HTTPS fixo
 * (`api.mercadopago.com`), sem seguir redirecionamentos — um token de vendor nunca vai a outro host.
 *
 * A regra de ouro: erro vira categoria, não corpo. `unknown_outcome` significa que a cobrança pode
 * ter sido criada (timeout, 5xx ou resposta ilegível em escrita) e proíbe repetir ou trocar de
 * gateway sem reconciliar; só falha de conexão comprovadamente anterior ao envio é `unavailable`.
 * Nada de token, segredo, CPF ou corpo remoto atravessa para mensagem de erro ou log.
 *
 * O que este módulo não faz: não escolhe vendor, conta ou token (o chamador entrega o token da
 * conta que resolveu), não lê preferência de gateway e nunca usa credencial global da Papelito
 * como alternativa ao token do vendor.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_MERCADOPAGO_API_HOST       = 'api.mercadopago.com';
const PAPELITO_MERCADOPAGO_AUTH_URL       = 'https://auth.mercadopago.com.br/authorization';
const PAPELITO_MERCADOPAGO_TIMEOUT        = 15;
const PAPELITO_MERCADOPAGO_MAX_RESPONSE   = 1048576;
const PAPELITO_MERCADOPAGO_ERROR_CATEGORY = array(
	'configuration',
	'authentication',
	'authorization',
	'rate_limit',
	'validation',
	'business_restriction',
	'unavailable',
	'unknown_outcome',
);

/**
 * Códigos remotos que representam restrição do vendedor, não erro de payload nem indisponibilidade.
 *
 * @return array<int,string>
 */
function papelito_mercadopago_business_restriction_codes(): array {
	return array( 'collector_does_not_comply_with_current_regulation' );
}

/**
 * Lê uma configuração: constante do wp-config, ambiente e, para segredos, o cofre administrativo.
 *
 * @param string $name Nome da variável.
 * @param string $slug Slug no cofre de integrações, quando é segredo editável.
 * @return string
 */
function papelito_mercadopago_setting( string $name, string $slug = '' ): string {
	if ( defined( $name ) ) {
		return trim( (string) constant( $name ) );
	}

	$value = function_exists( 'papelito_env' ) ? papelito_env( $name, '' ) : getenv( $name );
	$value = is_string( $value ) ? trim( $value ) : '';

	if ( '' === $value && '' !== $slug && function_exists( 'papelito_integration_secret' ) ) {
		$value = papelito_integration_secret( $slug );
	}

	return $value;
}

/**
 * Configuração vigente da aplicação Mercado Pago.
 *
 * @return array{client_id:string,client_secret:string,webhook_secret:string,redirect_uri:string,environment:string,auth_url:string}
 */
function papelito_mercadopago_config(): array {
	$auth_url = papelito_mercadopago_setting( 'MERCADOPAGO_AUTH_URL' );

	return array(
		'client_id'      => papelito_mercadopago_setting( 'MERCADOPAGO_CLIENT_ID' ),
		'client_secret'  => papelito_mercadopago_setting( 'MERCADOPAGO_CLIENT_SECRET', 'mercadopago_client_secret' ),
		'webhook_secret' => papelito_mercadopago_setting( 'MERCADOPAGO_WEBHOOK_SECRET', 'mercadopago_webhook_secret' ),
		'redirect_uri'   => papelito_mercadopago_setting( 'MERCADOPAGO_REDIRECT_URI' ),
		'environment'    => sanitize_key( papelito_mercadopago_setting( 'MERCADOPAGO_ENVIRONMENT' ) ),
		'auth_url'       => '' !== $auth_url ? $auth_url : PAPELITO_MERCADOPAGO_AUTH_URL,
	);
}

/**
 * Tipo de ambiente do WordPress.
 *
 * @return string
 */
function papelito_mercadopago_wp_environment(): string {
	return function_exists( 'wp_get_environment_type' ) ? sanitize_key( wp_get_environment_type() ) : 'production';
}

/**
 * Motivo pelo qual a integração não pode operar, ou nulo.
 *
 * Configuração inválida desliga o Mercado Pago, nunca o bootstrap do plugin. Produção exige `live`;
 * local e development exigem `test`.
 *
 * @return WP_Error|null
 */
function papelito_mercadopago_config_error(): ?WP_Error {
	$config  = papelito_mercadopago_config();
	$missing = array_keys(
		array_filter(
			array(
				'client_id'     => $config['client_id'],
				'client_secret' => $config['client_secret'],
				'redirect_uri'  => $config['redirect_uri'],
				'environment'   => $config['environment'],
			),
			static fn( string $value ): bool => '' === $value
		)
	);

	if ( ! empty( $missing ) ) {
		return papelito_mercadopago_error( 'configuration', 'Mercado Pago não configurado neste ambiente.', array( 'missing' => $missing ) );
	}

	if ( ! in_array( $config['environment'], array( 'test', 'live' ), true ) ) {
		return papelito_mercadopago_error( 'configuration', 'Ambiente do Mercado Pago inválido.' );
	}

	$wp_environment = papelito_mercadopago_wp_environment();
	$required       = papelito_mercadopago_required_environment( $wp_environment );

	if ( '' !== $required && $required !== $config['environment'] ) {
		return papelito_mercadopago_error( 'configuration', 'O ambiente do Mercado Pago não corresponde ao ambiente do site.' );
	}

	if ( ! papelito_mercadopago_is_https_url( $config['redirect_uri'] ) || ! papelito_mercadopago_is_https_url( $config['auth_url'] ) ) {
		return papelito_mercadopago_error( 'configuration', 'URLs do Mercado Pago precisam usar HTTPS.' );
	}

	return null;
}

/**
 * Ambiente Mercado Pago exigido pelo ambiente do site; vazio quando qualquer um serve.
 *
 * @param string $wp_environment Tipo de ambiente do WordPress.
 * @return string
 */
function papelito_mercadopago_required_environment( string $wp_environment ): string {
	if ( 'production' === $wp_environment ) {
		return 'live';
	}

	return in_array( $wp_environment, array( 'local', 'development' ), true ) ? 'test' : '';
}

/**
 * Diz se a URL é HTTPS com host.
 *
 * @param string $url URL.
 * @return bool
 */
function papelito_mercadopago_is_https_url( string $url ): bool {
	return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
}

/**
 * Diz se a integração pode operar.
 *
 * @return bool
 */
function papelito_mercadopago_is_configured(): bool {
	return null === papelito_mercadopago_config_error();
}

/**
 * Ambiente configurado (`test` ou `live`).
 *
 * @return string
 */
function papelito_mercadopago_environment(): string {
	return papelito_mercadopago_config()['environment'];
}

/**
 * Monta um erro categorizado do Mercado Pago.
 *
 * @param string              $category Categoria do vocabulário fechado.
 * @param string              $message  Mensagem já sem segredo.
 * @param array<string,mixed> $extra    Dados extras seguros.
 * @return WP_Error
 */
function papelito_mercadopago_error( string $category, string $message, array $extra = array() ): WP_Error {
	$category = in_array( $category, PAPELITO_MERCADOPAGO_ERROR_CATEGORY, true ) ? $category : 'unknown_outcome';
	$statuses = array(
		'configuration'        => 503,
		'authentication'       => 502,
		'authorization'        => 502,
		'rate_limit'           => 503,
		'validation'           => 502,
		'business_restriction' => 422,
		'unavailable'          => 503,
		'unknown_outcome'      => 502,
	);

	return new WP_Error(
		'papelito_mercadopago_' . $category,
		papelito_mercadopago_redact( $message ),
		array_merge(
			array(
				'status'   => $statuses[ $category ],
				'category' => $category,
			),
			$extra
		)
	);
}

/**
 * Remove tokens, segredos, documentos e e-mails de um texto antes de ele sair do módulo.
 *
 * @param string $text Texto cru.
 * @return string
 */
function papelito_mercadopago_redact( string $text ): string {
	$patterns = array(
		'/(APP_USR|TEST|TG)-[A-Za-z0-9_-]+/'               => '[token]',
		'/Bearer\s+\S+/i'                                  => 'Bearer [token]',
		'/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/'               => '[documento]',
		'/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/'       => '[documento]',
		'/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[email]',
	);
	$text     = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
	$secrets  = array_filter( array( papelito_mercadopago_config()['client_secret'], papelito_mercadopago_config()['webhook_secret'] ) );

	foreach ( $secrets as $secret ) {
		$text = str_replace( $secret, '[segredo]', $text );
	}

	return substr( sanitize_text_field( $text ), 0, 300 );
}

/**
 * Valida o caminho relativo da API: começa com `/`, sem esquema, host, `..` ou `@`.
 *
 * @param string $path Caminho.
 * @return bool
 */
function papelito_mercadopago_path_is_safe( string $path ): bool {
	return 1 === preg_match( '#^/[A-Za-z0-9/_.-]*(\?[A-Za-z0-9_.=&%-]*)?$#', $path )
		&& false === strpos( $path, '//' )
		&& false === strpos( $path, '..' );
}

/**
 * Requisição autenticada com o token de uma conta de vendor já resolvida pelo chamador.
 *
 * Args: `token` (obrigatório), `body`, `query`, `idempotency_key`, `timeout`.
 * Nenhum POST é repetido aqui: retry de escrita só depois de reconciliar.
 *
 * @param string              $method Método HTTP.
 * @param string              $path   Caminho relativo da API.
 * @param array<string,mixed> $args   Opções.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_request( string $method, string $path, array $args ) {
	$token = (string) ( $args['token'] ?? '' );

	if ( '' === $token ) {
		return papelito_mercadopago_error( 'configuration', 'Conta Mercado Pago do vendor sem credencial.' );
	}

	return papelito_mercadopago_send( $method, $path, $args, array( 'Authorization' => 'Bearer ' . $token ) );
}

/**
 * Troca de código ou refresh OAuth no endpoint oficial, com as credenciais da aplicação.
 *
 * @param array<string,string> $fields Campos do grant (sem client_id/secret).
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_oauth_token_request( array $fields ) {
	$config_error = papelito_mercadopago_config_error();

	if ( $config_error instanceof WP_Error ) {
		return $config_error;
	}

	$config = papelito_mercadopago_config();
	$body   = array_merge(
		array(
			'client_id'     => $config['client_id'],
			'client_secret' => $config['client_secret'],
		),
		$fields
	);

	if ( 'test' === $config['environment'] && 'authorization_code' === ( $fields['grant_type'] ?? '' ) ) {
		$body['test_token'] = 'true';
	}

	return papelito_mercadopago_send( 'POST', '/oauth/token', array( 'body' => $body ), array() );
}

/**
 * Envia a requisição e categoriza o resultado.
 *
 * @param string               $method  Método HTTP.
 * @param string               $path    Caminho relativo.
 * @param array<string,mixed>  $args    Opções.
 * @param array<string,string> $headers Cabeçalhos de autenticação.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_send( string $method, string $path, array $args, array $headers ) {
	$method = strtoupper( $method );

	if ( ! papelito_mercadopago_path_is_safe( $path ) ) {
		return papelito_mercadopago_error( 'configuration', 'Caminho de API Mercado Pago inválido.' );
	}

	$url = 'https://' . PAPELITO_MERCADOPAGO_API_HOST . $path;
	if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
		$url = add_query_arg( $args['query'], $url );
	}

	$headers['Accept'] = 'application/json';
	if ( isset( $args['body'] ) ) {
		$headers['Content-Type'] = 'application/json';
	}
	if ( ! empty( $args['idempotency_key'] ) ) {
		$headers['X-Idempotency-Key'] = (string) $args['idempotency_key'];
	}

	$request_args = array(
		'method'              => $method,
		'timeout'             => (int) ( $args['timeout'] ?? PAPELITO_MERCADOPAGO_TIMEOUT ),
		'redirection'         => 0,
		'limit_response_size' => PAPELITO_MERCADOPAGO_MAX_RESPONSE,
		'headers'             => $headers,
		'body'                => isset( $args['body'] ) ? wp_json_encode( $args['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
		'data_format'         => 'body',
	);

	$response = apply_filters( 'papelito_mercadopago_pre_request', null, $method, $url, $request_args );
	if ( null === $response ) {
		$response = wp_remote_request( $url, $request_args );
	}

	$result = papelito_mercadopago_interpret( $method, $response );

	if ( is_wp_error( $result ) ) {
		papelito_mercadopago_log_failure( $method, $path, $result );
	}

	return $result;
}

/**
 * Converte a resposta crua em array ou erro categorizado.
 *
 * @param string $method   Método HTTP.
 * @param mixed  $response Resposta do transporte.
 * @return array<string,mixed>|WP_Error
 */
function papelito_mercadopago_interpret( string $method, mixed $response ) {
	$is_write = 'GET' !== $method;

	if ( is_wp_error( $response ) ) {
		$before_send = 1 === preg_match( '/cURL error (6|7):/', $response->get_error_message() );
		return papelito_mercadopago_error( $is_write && ! $before_send ? 'unknown_outcome' : 'unavailable', 'Falha de comunicação com o Mercado Pago.' );
	}

	$status  = (int) wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( $status >= 200 && $status < 300 ) {
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		return papelito_mercadopago_error( $is_write ? 'unknown_outcome' : 'unavailable', 'Resposta ilegível do Mercado Pago.', array( 'http_status' => $status ) );
	}

	$remote_code = is_array( $decoded ) ? sanitize_key( (string) ( $decoded['error'] ?? $decoded['code'] ?? '' ) ) : '';
	$message     = is_array( $decoded ) ? (string) ( $decoded['message'] ?? '' ) : '';
	$extra       = array(
		'http_status' => $status,
		'remote_code' => $remote_code,
	);

	return papelito_mercadopago_error( papelito_mercadopago_status_category( $status, $remote_code, $message, $is_write ), '' !== $message ? $message : 'Erro do Mercado Pago.', $extra );
}

/**
 * Categoria de um status HTTP de erro.
 *
 * 400/403 isolados não são indisponibilidade: só `unavailable` libera fallback, e eles não liberam.
 *
 * @param int    $status      HTTP status.
 * @param string $remote_code Código remoto.
 * @param string $message     Mensagem remota.
 * @param bool   $is_write    Se a requisição altera estado remoto.
 * @return string
 */
function papelito_mercadopago_status_category( int $status, string $remote_code, string $message, bool $is_write ): string {
	foreach ( papelito_mercadopago_business_restriction_codes() as $code ) {
		if ( $code === $remote_code || false !== strpos( $message, $code ) ) {
			return 'business_restriction';
		}
	}

	$map = array(
		401 => 'authentication',
		403 => 'authorization',
		429 => 'rate_limit',
	);

	if ( isset( $map[ $status ] ) ) {
		return $map[ $status ];
	}

	if ( $status >= 500 ) {
		return $is_write ? 'unknown_outcome' : 'unavailable';
	}

	return 'validation';
}

/**
 * Registra a falha sem segredo, corpo ou query string.
 *
 * @param string   $method Método HTTP.
 * @param string   $path   Caminho.
 * @param WP_Error $error  Erro categorizado.
 * @return void
 */
function papelito_mercadopago_log_failure( string $method, string $path, WP_Error $error ): void {
	$data = (array) $error->get_error_data();

	error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		sprintf(
			'[papelito_mercadopago] %s %s -> %s http=%d remote=%s',
			$method,
			strtok( $path, '?' ),
			(string) ( $data['category'] ?? '' ),
			(int) ( $data['http_status'] ?? 0 ),
			(string) ( $data['remote_code'] ?? '' )
		)
	);
}

/**
 * Acrescenta ao cofre administrativo os segredos editáveis do Mercado Pago.
 *
 * Variável de ambiente continua tendo precedência sobre o cofre.
 *
 * @param array<string,array<string,string>> $catalog Catálogo atual.
 * @return array<string,array<string,string>>
 */
function papelito_mercadopago_secret_catalog( array $catalog ): array {
	$catalog['mercadopago_client_secret']  = array(
		'slug'  => 'mercadopago_client_secret',
		'label' => 'Client secret do Mercado Pago',
		'class' => 'payments',
		'env'   => 'MERCADOPAGO_CLIENT_SECRET',
	);
	$catalog['mercadopago_webhook_secret'] = array(
		'slug'  => 'mercadopago_webhook_secret',
		'label' => 'Segredo de assinatura dos webhooks do Mercado Pago',
		'class' => 'payments',
		'env'   => 'MERCADOPAGO_WEBHOOK_SECRET',
	);

	return $catalog;
}

add_filter( 'papelito_integration_secret_catalog', 'papelito_mercadopago_secret_catalog' );
