<?php
/**
 * Vinculação da conta Mercado Pago do vendor por OAuth (marketplace 1:1).
 *
 * O vendor autoriza a aplicação da Papelito na própria conta; nunca informa senha nem cola token.
 * O retorno do Mercado Pago chega ao callback do Next, que confirma a sessão iniciadora e repassa
 * code/state server-to-server para cá, com a autenticação do vendor.
 *
 * A regra de ouro: o state é opaco, de uso único e curto, e só vale para o mesmo vendor, a mesma
 * sessão (hash do binding guardado em cookie httpOnly pelo Next), o mesmo CNPJ e a mesma
 * configuração da aplicação. O state é consumido antes da troca do código; qualquer divergência
 * recusa sem tocar na vinculação anterior. PKCE S256 sempre.
 *
 * Vincular não é verificar: a conta nasce `verification_pending` — a API não oferece prova
 * documentada de titularidade PJ ou habilitação (MP-001), então a confirmação é administrativa.
 *
 * O que este módulo não faz: não cria cobrança, não renova token (isso é o refresh) e não guarda
 * token fora do repositório de contas.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_MERCADOPAGO_OAUTH_STATE_PREFIX           = 'papelito_mp_oauth_';
const PAPELITO_MERCADOPAGO_OAUTH_STATE_TTL              = 600;
const PAPELITO_MERCADOPAGO_OAUTH_CALLBACK_PATH          = '/api/vendor/mercadopago/callback';
const PAPELITO_MERCADOPAGO_OAUTH_AUTO_IDENTITY_EVIDENCE = 'auto:mercadopago-users-me';

/**
 * Codifica em base64url sem padding.
 *
 * @param string $bytes Bytes.
 * @return string
 */
function papelito_mercadopago_oauth_base64url( string $bytes ): string {
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Par PKCE: verifier de 43 caracteres e challenge S256.
 *
 * @return array{verifier:string,challenge:string}
 */
function papelito_mercadopago_oauth_pkce_pair(): array {
	$verifier = papelito_mercadopago_oauth_base64url( random_bytes( 32 ) );

	return array(
		'verifier'  => $verifier,
		'challenge' => papelito_mercadopago_oauth_base64url( hash( 'sha256', $verifier, true ) ),
	);
}

/**
 * Versão da configuração da aplicação; muda quando client, ambiente ou redirect mudam.
 *
 * @return string
 */
function papelito_mercadopago_configuration_version(): string {
	$config = papelito_mercadopago_config();

	return substr( hash( 'sha256', $config['client_id'] . '|' . $config['environment'] . '|' . $config['redirect_uri'] ), 0, 16 );
}

/**
 * Impressão digital do CNPJ atual do vendor.
 *
 * @param int $vendor_id Vendor.
 * @return string
 */
function papelito_mercadopago_oauth_cnpj_fingerprint( int $vendor_id ): string {
	$digits = preg_replace( '/\D+/', '', (string) get_user_meta( $vendor_id, 'cnpj', true ) );

	return '' === $digits ? '' : hash_hmac( 'sha256', (string) $digits, wp_salt( 'papelito_mercadopago_oauth' ) );
}

/**
 * Chave do transient do state, derivada do state (nunca o state em claro no nome).
 *
 * @param string $state State opaco.
 * @return string
 */
function papelito_mercadopago_oauth_state_key( string $state ): string {
	return PAPELITO_MERCADOPAGO_OAUTH_STATE_PREFIX . substr( hash( 'sha256', $state ), 0, 40 );
}

/**
 * Hash do binding de sessão enviado pelo Next.
 *
 * @param string $binding Valor do cookie httpOnly.
 * @return string
 */
function papelito_mercadopago_oauth_binding_hash( string $binding ): string {
	return hash_hmac( 'sha256', $binding, wp_salt( 'papelito_mercadopago_oauth_binding' ) );
}

/**
 * Diz se o vendor pode iniciar/alterar a vinculação agora.
 *
 * @param int $vendor_id Vendor.
 * @return true|WP_Error
 */
function papelito_mercadopago_oauth_can_link( int $vendor_id ) {
	if ( PAPELITO_VENDOR_PAYMENT_MODE_GATEWAYS !== papelito_vendor_payment_mode( $vendor_id ) ) {
		return new WP_Error( 'papelito_payment_gateways_disabled', 'A conexão com o Mercado Pago ainda não está liberada para esta loja.', array( 'status' => 403 ) );
	}

	$config_error = papelito_mercadopago_config_error();

	return $config_error instanceof WP_Error ? $config_error : true;
}

/**
 * Inicia a vinculação: grava o state de uso único e devolve a URL de autorização.
 *
 * @param int    $vendor_id Vendor autenticado.
 * @param string $binding   Valor aleatório do cookie de sessão do Next (mín. 32 caracteres).
 * @return array{authorizationUrl:string,expiresIn:int}|WP_Error
 */
function papelito_mercadopago_oauth_begin( int $vendor_id, string $binding ) {
	$allowed = papelito_mercadopago_oauth_can_link( $vendor_id );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	if ( strlen( $binding ) < 32 ) {
		return new WP_Error( 'papelito_mercadopago_oauth_session_invalid', 'Sessão de conexão inválida. Recarregue a página e tente de novo.', array( 'status' => 400 ) );
	}

	$fingerprint = papelito_mercadopago_oauth_cnpj_fingerprint( $vendor_id );
	if ( '' === $fingerprint ) {
		return new WP_Error( 'papelito_mercadopago_oauth_cnpj_missing', 'Cadastre o CNPJ da loja antes de conectar o Mercado Pago.', array( 'status' => 422 ) );
	}

	$verifier = papelito_mercadopago_oauth_pkce_pair();
	$envelope = papelito_vendor_secret_encrypt( $verifier['verifier'] );
	if ( is_wp_error( $envelope ) ) {
		return new WP_Error( 'papelito_mercadopago_oauth_unavailable', 'Não foi possível iniciar a conexão com segurança.', array( 'status' => 500 ) );
	}

	$redirect_uri = papelito_mercadopago_oauth_redirect_uri();
	if ( '' === $redirect_uri ) {
		return new WP_Error( 'papelito_mercadopago_oauth_unavailable', 'O Mercado Pago não está configurado para este endereço da loja.', array( 'status' => 503 ) );
	}

	$state  = papelito_mercadopago_oauth_base64url( random_bytes( 32 ) );
	$config = papelito_mercadopago_config();

	set_transient(
		papelito_mercadopago_oauth_state_key( $state ),
		array(
			'vendor_id'             => $vendor_id,
			'binding'               => papelito_mercadopago_oauth_binding_hash( $binding ),
			'cnpj_fingerprint'      => $fingerprint,
			'environment'           => $config['environment'],
			'configuration_version' => papelito_mercadopago_configuration_version(),
			'verifier'              => $envelope,
			'redirect_uri'          => $redirect_uri,
			'expires_at'            => time() + PAPELITO_MERCADOPAGO_OAUTH_STATE_TTL,
		),
		PAPELITO_MERCADOPAGO_OAUTH_STATE_TTL
	);

	return array(
		'authorizationUrl' => papelito_mercadopago_oauth_authorization_url( $config, $redirect_uri, $state, $verifier['challenge'] ),
		'expiresIn'        => PAPELITO_MERCADOPAGO_OAUTH_STATE_TTL,
	);
}

/**
 * Redirect URL do OAuth para o frontend que iniciou a conexão.
 *
 * `{domínio da requisição}/api/vendor/mercadopago/callback` quando o domínio está na allowlist
 * (`papelito_frontend_request_base()`), para teste e produção usarem o próprio callback sem trocar
 * configuração; sem domínio, `MERCADOPAGO_REDIRECT_URI`. As duas URLs precisam estar cadastradas
 * na aplicação do Mercado Pago.
 *
 * @return string Vazio quando não há domínio nem redirect configurado.
 */
function papelito_mercadopago_oauth_redirect_uri(): string {
	$base = function_exists( 'papelito_frontend_request_base' ) ? papelito_frontend_request_base() : '';

	if ( '' !== $base && papelito_frontend_base_is_usable( $base ) ) {
		return $base . PAPELITO_MERCADOPAGO_OAUTH_CALLBACK_PATH;
	}

	return (string) papelito_mercadopago_config()['redirect_uri'];
}

/**
 * URL de autorização com PKCE.
 *
 * @param array<string,string> $config       Configuração da aplicação.
 * @param string               $redirect_uri Redirect da conexão, gravado no state.
 * @param string               $state        State opaco.
 * @param string               $challenge    Code challenge S256.
 * @return string
 */
function papelito_mercadopago_oauth_authorization_url( array $config, string $redirect_uri, string $state, string $challenge ): string {
	return add_query_arg(
		array(
			'client_id'             => $config['client_id'],
			'response_type'         => 'code',
			'platform_id'           => 'mp',
			'state'                 => $state,
			'redirect_uri'          => $redirect_uri,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		),
		$config['auth_url']
	);
}

/**
 * Consome o state (uso único) e confere vendor, sessão, CNPJ, configuração e validade.
 *
 * @param int    $vendor_id Vendor autenticado no retorno.
 * @param string $state     State recebido.
 * @param string $binding   Binding da sessão que retornou.
 * @return array<string,mixed>|WP_Error Registro do state.
 */
function papelito_mercadopago_oauth_consume_state( int $vendor_id, string $state, string $binding ) {
	$invalid = new WP_Error( 'papelito_mercadopago_oauth_state_invalid', 'Esta conexão expirou ou não pertence a esta sessão. Inicie a conexão novamente.', array( 'status' => 400 ) );

	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{43,64}$/', $state ) ) {
		return $invalid;
	}

	$key    = papelito_mercadopago_oauth_state_key( $state );
	$record = get_transient( $key );

	if ( ! is_array( $record ) || (int) $record['vendor_id'] !== $vendor_id || ! hash_equals( (string) $record['binding'], papelito_mercadopago_oauth_binding_hash( $binding ) ) ) {
		return $invalid;
	}

	if ( ! delete_transient( $key ) ) {
		return $invalid;
	}

	$current = (int) $record['expires_at'] >= time()
		&& hash_equals( (string) $record['cnpj_fingerprint'], papelito_mercadopago_oauth_cnpj_fingerprint( $vendor_id ) )
		&& papelito_mercadopago_configuration_version() === (string) $record['configuration_version']
		&& papelito_mercadopago_environment() === (string) $record['environment'];

	return $current ? $record : $invalid;
}

/**
 * Conclui a vinculação: consome o state, troca o código e grava a conta vigente.
 *
 * Erro em qualquer etapa preserva a vinculação anterior.
 *
 * @param int    $vendor_id Vendor autenticado.
 * @param string $code      Código de autorização.
 * @param string $state     State opaco.
 * @param string $binding   Binding da sessão.
 * @return array{account:array<string,mixed>}|WP_Error
 */
function papelito_mercadopago_oauth_complete( int $vendor_id, string $code, string $state, string $binding ) {
	$allowed = papelito_mercadopago_oauth_can_link( $vendor_id );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$record = papelito_mercadopago_oauth_consume_state( $vendor_id, $state, $binding );
	if ( is_wp_error( $record ) ) {
		return $record;
	}

	$verifier = papelito_vendor_secret_decrypt( (string) $record['verifier'] );
	if ( is_wp_error( $verifier ) || '' === $code ) {
		return new WP_Error( 'papelito_mercadopago_oauth_state_invalid', 'Conexão inválida. Inicie novamente.', array( 'status' => 400 ) );
	}

	$token = papelito_mercadopago_oauth_token_request(
		array(
			'grant_type'    => 'authorization_code',
			'code'          => sanitize_text_field( $code ),
			'redirect_uri'  => (string) ( $record['redirect_uri'] ?? papelito_mercadopago_config()['redirect_uri'] ),
			'code_verifier' => $verifier,
		)
	);

	if ( is_wp_error( $token ) ) {
		papelito_payment_observe_metric( 'connection_failed' );
		return new WP_Error(
			'papelito_mercadopago_oauth_exchange_failed',
			'O Mercado Pago não confirmou a autorização. Tente conectar novamente.',
			array(
				'status'   => 502,
				'category' => $token->get_error_data()['category'] ?? '',
			)
		);
	}

	$validated = papelito_mercadopago_oauth_validate_token( $token, (string) $record['environment'] );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	$account = papelito_vendor_payment_account_connect(
		$vendor_id,
		PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO,
		(string) $record['environment'],
		array_merge(
			$validated,
			array(
				'cnpj_fingerprint'      => (string) $record['cnpj_fingerprint'],
				'configuration_version' => (string) $record['configuration_version'],
			)
		)
	);

	if ( is_wp_error( $account ) ) {
		return $account;
	}

	$account = papelito_mercadopago_oauth_check_identity( $vendor_id, $account, (string) $validated['access_token'] );

	do_action( 'papelito_payment_gateway_account_changed', $vendor_id, PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO );

	return array( 'account' => papelito_vendor_payment_account_public( $account ) );
}

/**
 * Confere sozinha a titularidade da conta recém-conectada pelo documento do titular.
 *
 * Consulta `/users/me` com o token da própria loja: CNPJ igual ao do cadastro marca `verified`;
 * CNPJ diferente ou conta pessoa física marca `mismatch` e a conta não recebe. Sem resposta ou
 * sem documento, nada muda e a conta segue `verification_pending` para o admin conferir. A
 * consulta bem-sucedida também fica registrada como a primeira verificação da conta.
 *
 * @param int                 $vendor_id    Vendor.
 * @param array<string,mixed> $account      Conta recém-gravada.
 * @param string              $access_token Token da loja.
 * @return array<string,mixed> Conta relida.
 */
function papelito_mercadopago_oauth_check_identity( int $vendor_id, array $account, string $access_token ): array {
	$owner = papelito_mercadopago_request( 'GET', '/users/me', array( 'token' => $access_token ) );
	if ( is_wp_error( $owner ) ) {
		return $account;
	}

	papelito_vendor_payment_account_update_health(
		(int) $account['id'],
		array(
			'health_status' => 'ok',
			'health_reason' => '',
			'success'       => true,
		)
	);

	$status = papelito_mercadopago_oauth_identity_status( $vendor_id, $owner );

	if ( '' === $status ) {
		return $account;
	}

	papelito_vendor_payment_account_set_identity( (int) $account['id'], $status, 0, PAPELITO_MERCADOPAGO_OAUTH_AUTO_IDENTITY_EVIDENCE );
	papelito_vendor_payment_identity_audit_append(
		$vendor_id,
		array(
			'accountId'   => (int) $account['id'],
			'collectorId' => (string) $account['collector_id'],
			'status'      => $status,
			'actorId'     => 0,
			'evidenceRef' => PAPELITO_MERCADOPAGO_OAUTH_AUTO_IDENTITY_EVIDENCE,
		)
	);

	return papelito_vendor_payment_account_find( (int) $account['id'] ) ?? $account;
}

/**
 * Veredito da titularidade pelo documento do titular da conta Mercado Pago.
 *
 * @param int                 $vendor_id Vendor.
 * @param array<string,mixed> $owner     Resposta de `/users/me` da conta conectada.
 * @return string `verified`, `mismatch` ou vazio quando não dá para decidir.
 */
function papelito_mercadopago_oauth_identity_status( int $vendor_id, array $owner ): string {
	$document = preg_replace( '/\D+/', '', (string) ( $owner['identification']['number'] ?? '' ) );
	$cnpj     = preg_replace( '/\D+/', '', (string) get_user_meta( $vendor_id, 'cnpj', true ) );

	if ( '' === $document || '' === $cnpj ) {
		return '';
	}

	$is_company = 'CNPJ' === strtoupper( (string) ( $owner['identification']['type'] ?? '' ) );

	return $is_company && hash_equals( $cnpj, $document ) ? 'verified' : 'mismatch';
}

/**
 * Confere a resposta do token: credenciais presentes, collector numérico e ambiente coerente.
 *
 * @param array<string,mixed> $token       Resposta de /oauth/token.
 * @param string              $environment Ambiente do state.
 * @return array<string,mixed>|WP_Error Dados para o repositório.
 */
function papelito_mercadopago_oauth_validate_token( array $token, string $environment ) {
	$collector = (string) ( $token['user_id'] ?? '' );

	if ( '' === (string) ( $token['access_token'] ?? '' ) || 1 !== preg_match( '/^\d{1,20}$/', $collector ) ) {
		return new WP_Error( 'papelito_mercadopago_oauth_exchange_failed', 'Resposta de autorização incompleta do Mercado Pago.', array( 'status' => 502 ) );
	}

	$expects_live = 'live' === $environment;

	if ( array_key_exists( 'live_mode', $token ) && $expects_live !== (bool) $token['live_mode'] ) {
		return new WP_Error( 'papelito_mercadopago_oauth_environment_mismatch', 'A conta autorizada não corresponde ao ambiente de pagamento configurado.', array( 'status' => 409 ) );
	}

	return array(
		'collector_id'  => $collector,
		'access_token'  => (string) $token['access_token'],
		'refresh_token' => (string) ( $token['refresh_token'] ?? '' ),
		'expires_in'    => (int) ( $token['expires_in'] ?? 0 ),
		'public_key'    => (string) ( $token['public_key'] ?? '' ),
		'scope'         => (string) ( $token['scope'] ?? '' ),
		'live_mode'     => ! empty( $token['live_mode'] ),
	);
}

/**
 * Erro legível para retorno com `error` do Mercado Pago (loja negou ou falha no consentimento).
 *
 * @param string $error Código recebido.
 * @return WP_Error
 */
function papelito_mercadopago_oauth_callback_error( string $error ): WP_Error {
	if ( 'access_denied' === sanitize_key( $error ) ) {
		return new WP_Error( 'papelito_mercadopago_oauth_denied', 'A autorização no Mercado Pago foi cancelada. Nada foi alterado.', array( 'status' => 400 ) );
	}

	return new WP_Error( 'papelito_mercadopago_oauth_failed', 'O Mercado Pago não concluiu a autorização. Nada foi alterado.', array( 'status' => 400 ) );
}

/**
 * Desativa a conta vigente para novas compras; histórico e cobranças antigas preservados.
 *
 * Não há endpoint documentado de revogação remota: a loja revoga no próprio Mercado Pago se quiser.
 *
 * @param int $vendor_id Vendor.
 * @return true|WP_Error
 */
function papelito_mercadopago_oauth_disconnect( int $vendor_id ) {
	$account = papelito_vendor_payment_account_current( $vendor_id, PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO, papelito_mercadopago_environment() );

	if ( ! is_array( $account ) ) {
		return new WP_Error( 'papelito_mercadopago_not_connected', 'Não há conta Mercado Pago conectada.', array( 'status' => 404 ) );
	}

	papelito_vendor_payment_account_disconnect( (int) $account['id'] );
	do_action( 'papelito_payment_gateway_account_changed', $vendor_id, PAPELITO_PAYMENT_PROVIDER_MERCADOPAGO );

	return true;
}

/**
 * Exige vendedor autenticado.
 *
 * @return true|WP_Error
 */
function papelito_mercadopago_oauth_permission() {
	$check = function_exists( 'papelito_vendor_dashboard_require_seller' ) ? papelito_vendor_dashboard_require_seller() : false;

	if ( is_wp_error( $check ) ) {
		return $check;
	}

	return false === $check ? new WP_Error( 'papelito_forbidden', 'Acesso restrito à loja.', array( 'status' => 403 ) ) : true;
}

/**
 * Cota por vendor (identidade do usuário, não IP compartilhado do proxy).
 *
 * @param string $bucket Balde.
 * @param int    $max    Máximo na janela.
 * @return true|WP_Error
 */
function papelito_mercadopago_oauth_rate_limit( string $bucket, int $max ) {
	if ( function_exists( 'papelito_auth_rate_limit' ) && ! papelito_auth_rate_limit( $bucket, $max, 600, 'user:' . get_current_user_id() ) ) {
		return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Aguarde alguns minutos.', array( 'status' => 429 ) );
	}

	return true;
}

/**
 * REST: inicia a conexão.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_mercadopago_oauth_rest_connect( WP_REST_Request $request ) {
	$limited = papelito_mercadopago_oauth_rate_limit( 'mp_oauth_connect', 10 );
	if ( is_wp_error( $limited ) ) {
		return $limited;
	}

	$body   = (array) $request->get_json_params();
	$result = papelito_mercadopago_oauth_begin( get_current_user_id(), (string) ( $body['binding'] ?? '' ) );

	return is_wp_error( $result ) ? $result : papelito_mercadopago_oauth_no_store( $result );
}

/**
 * REST: conclui a conexão com code/state repassados pelo callback do Next.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_mercadopago_oauth_rest_callback( WP_REST_Request $request ) {
	$limited = papelito_mercadopago_oauth_rate_limit( 'mp_oauth_callback', 10 );
	if ( is_wp_error( $limited ) ) {
		return $limited;
	}

	$body = (array) $request->get_json_params();

	if ( '' !== (string) ( $body['error'] ?? '' ) ) {
		return papelito_mercadopago_oauth_callback_error( (string) $body['error'] );
	}

	$result = papelito_mercadopago_oauth_complete(
		get_current_user_id(),
		(string) ( $body['code'] ?? '' ),
		(string) ( $body['state'] ?? '' ),
		(string) ( $body['binding'] ?? '' )
	);

	return is_wp_error( $result ) ? $result : papelito_mercadopago_oauth_no_store( $result );
}

/**
 * REST: desconecta com reautenticação por senha atual.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_mercadopago_oauth_rest_disconnect( WP_REST_Request $request ) {
	$limited = papelito_mercadopago_oauth_rate_limit( 'mp_oauth_disconnect', 5 );
	if ( is_wp_error( $limited ) ) {
		return $limited;
	}

	$password = (string) ( ( (array) $request->get_json_params() )['currentPassword'] ?? '' );
	$user     = get_user_by( 'id', get_current_user_id() );

	if ( ! $user instanceof WP_User || '' === $password || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
		return new WP_Error( 'papelito_reauthentication_failed', 'Não foi possível confirmar a senha atual.', array( 'status' => 403 ) );
	}

	$result = papelito_mercadopago_oauth_disconnect( (int) $user->ID );

	return is_wp_error( $result ) ? $result : papelito_mercadopago_oauth_no_store( array( 'disconnected' => true ) );
}

/**
 * Resposta sem cache.
 *
 * @param array<string,mixed> $data Corpo.
 * @return WP_REST_Response
 */
function papelito_mercadopago_oauth_no_store( array $data ): WP_REST_Response {
	$response = new WP_REST_Response( $data, 200 );
	$response->header( 'Cache-Control', 'no-store, private' );

	return $response;
}

/**
 * Registra as rotas de vinculação.
 *
 * @return void
 */
function papelito_mercadopago_oauth_register_routes(): void {
	$routes = array(
		'/vendor/mercadopago/connect'    => 'papelito_mercadopago_oauth_rest_connect',
		'/mercadopago/oauth/callback'    => 'papelito_mercadopago_oauth_rest_callback',
		'/vendor/mercadopago/disconnect' => 'papelito_mercadopago_oauth_rest_disconnect',
	);

	foreach ( $routes as $route => $callback ) {
		register_rest_route(
			'papelito/v1',
			$route,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_mercadopago_oauth_permission',
				'callback'            => $callback,
			)
		);
	}
}

add_action( 'rest_api_init', 'papelito_mercadopago_oauth_register_routes' );
