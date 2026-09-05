<?php
/**
 * Endpoints REST de autenticação headless.
 *
 * Expoe GET /wp-json/papelito/v1/auth/me e POST /auth/google, /auth/register,
 * /auth/verify-email, /auth/resend-verification e /auth/welcome-toast/claim.
 * O login por credenciais e o
 * fluxo Google continuam compatíveis com o par {authToken, refreshToken}
 * usado pela mutation `login` do plugin wp-graphql-jwt-authentication.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maquina de estados do toast de boas-vindas: ausente (legado) -> 'pending' (conta criada) ->
 * 'shown' (reivindicado uma unica vez, para sempre).
 */
const PAPELITO_WELCOME_TOAST_META = 'papelito_welcome_toast_status';
const PAPELITO_AUTH_API_NAMESPACE = 'papelito/v1';
const PAPELITO_AUTH_INVALID_EMAIL_MESSAGE = 'E-mail inválido.';
const PAPELITO_AUTH_NEW_USER_LOOKUP_MESSAGE = 'Falha ao carregar usuário recém-criado.';
const PAPELITO_AUTH_ONBOARDING_RESUME_MESSAGE = 'Retome seu onboarding B2B para continuar.';
const PAPELITO_AUTH_RATE_LIMIT_MESSAGE = 'Muitas tentativas. Tente novamente em alguns instantes.';
const PAPELITO_AUTH_INVALID_VERIFICATION_MESSAGE = 'Link de confirmação inválido ou expirado.';
const PAPELITO_AUTH_CPF_IN_USE_MESSAGE = 'Este CPF já está associado a outra conta.';
const PAPELITO_AUTH_SESSION_VERSION_META = 'papelito_auth_session_version';
const PAPELITO_AUTH_PASSWORD_UNSET_META = 'papelito_auth_password_unset';

add_filter(
	'graphql_jwt_auth_expire',
	static function () {
		return 12 * HOUR_IN_SECONDS;
	}
);

add_filter(
	'password_reset_expiration',
	static function () {
		return DAY_IN_SECONDS;
	}
);

/**
 * Acrescenta a versao de sessao quando ela existe, preservando JWTs legados ate uma invalidação explicita.
 *
 * @param array   $token Payload JWT.
 * @param WP_User $user Usuario dono do token.
 * @return array
 */
function papelito_auth_add_session_version_to_token( array $token, WP_User $user ): array {
	$version = (string) get_user_meta( $user->ID, PAPELITO_AUTH_SESSION_VERSION_META, true );

	if ( '' === $version ) {
		return $token;
	}

	if ( ! isset( $token['data'] ) || ! is_array( $token['data'] ) ) {
		$token['data'] = array();
	}
	if ( ! isset( $token['data']['user'] ) || ! is_array( $token['data']['user'] ) ) {
		$token['data']['user'] = array();
	}

	$token['data']['user']['papelito_session_version'] = $version;

	return $token;
}

add_filter( 'graphql_jwt_auth_token_before_sign', 'papelito_auth_add_session_version_to_token', 20, 2 );

/**
 * Rejeita JWTs emitidos antes da ultima invalidacao de sessao da conta.
 *
 * @param object $token JWT decodificado pelo plugin de autenticacao.
 * @return object|WP_Error
 */
function papelito_auth_validate_session_version( $token ) {
	$user_id = isset( $token->data->user->id ) ? (int) $token->data->user->id : 0;

	if ( $user_id <= 0 ) {
		return $token;
	}

	$expected_version = (string) get_user_meta( $user_id, PAPELITO_AUTH_SESSION_VERSION_META, true );
	if ( '' === $expected_version ) {
		return $token;
	}

	$token_version = isset( $token->data->user->papelito_session_version )
		? (string) $token->data->user->papelito_session_version
		: '';

	if ( ! hash_equals( $expected_version, $token_version ) ) {
		return new WP_Error( 'papelito_session_invalidated', 'Sessão expirada. Faça login novamente.', array( 'status' => 401 ) );
	}

	return $token;
}

add_filter( 'graphql_jwt_auth_validate_token', 'papelito_auth_validate_session_version' );

/**
 * Valida formatos comuns de CEP usados no projeto.
 *
 * @param string $cep
 * @return bool
 */
function papelito_auth_is_valid_cep( string $cep ): bool {
	return 1 === preg_match( '/^\d{5}-?\d{3}$/', $cep ) || 1 === preg_match( '/^\d{2}\.\d{3}-\d{3}$/', $cep );
}

/**
 * Normaliza telefones brasileiros para dígitos locais.
 *
 * @param string $phone
 * @return string
 */
function papelito_auth_normalize_phone( string $phone ): string {
	return papelito_normalize_phone_digits( $phone );
}

/**
 * Formata telefone brasileiro para o padrão visual usado pelo projeto.
 *
 * @param string $phone
 * @return string
 */
function papelito_auth_format_phone( string $phone ): string {
	$digits = papelito_auth_normalize_phone( $phone );

	if ( 11 === strlen( $digits ) ) {
		return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 5 ), substr( $digits, 7, 4 ) );
	}

	if ( 10 === strlen( $digits ) ) {
		return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 4 ), substr( $digits, 6, 4 ) );
	}

	return sanitize_text_field( $phone );
}

/**
 * Gera o par {authToken, refreshToken, user, profileComplete} para um WP_User.
 * Retorna WP_Error se o plugin de JWT não estiver disponível.
 *
 * @param WP_User $user
 * @return array|WP_Error
 */
function papelito_auth_build_token_response( WP_User $user ) {
	$jwt_class = '\\WPGraphQL\\JWT_Authentication\\Auth';

	if ( ! class_exists( $jwt_class ) ) {
		return new WP_Error(
			'papelito_jwt_unavailable',
			'Plugin wp-graphql-jwt-authentication não está ativo.',
			array( 'status' => 500 )
		);
	}

	$auth_token    = call_user_func( array( $jwt_class, 'get_token' ), $user, false );
	$refresh_token = call_user_func( array( $jwt_class, 'get_refresh_token' ), $user, false );

	if ( is_wp_error( $auth_token ) ) {
		return $auth_token;
	}

	if ( is_wp_error( $refresh_token ) ) {
		return $refresh_token;
	}

	$profile_complete = '1' === (string) get_user_meta( $user->ID, 'papelito_profile_complete', true );

	return array(
		'authToken'       => $auth_token,
		'refreshToken'    => $refresh_token,
		'user'            => array(
			'databaseId' => $user->ID,
			'email'      => $user->user_email,
			'firstName'  => (string) get_user_meta( $user->ID, 'first_name', true ),
			'lastName'   => (string) get_user_meta( $user->ID, 'last_name', true ),
		),
		'profileComplete' => $profile_complete,
	);
}

/**
 * Normalize the primary Papelito role for the authenticated user.
 *
 * Prioritizes administrator over seller/customer when a user has multiple roles.
 *
 * @param WP_User $user
 * @return string
 */
function papelito_auth_normalize_primary_role( WP_User $user ): string {
	$roles = array_values(
		array_filter(
			array_map( 'sanitize_key', (array) $user->roles )
		)
	);

	if ( in_array( 'administrator', $roles, true ) ) {
		return 'administrator';
	}

	if ( function_exists( 'papelito_user_can_access_seller_area' ) && papelito_user_can_access_seller_area( $user ) ) {
		return 'seller';
	}

	if ( in_array( 'customer', $roles, true ) ) {
		return 'customer';
	}

	if ( in_array( 'seller', $roles, true ) ) {
		return 'customer';
	}

	return isset( $roles[0] ) ? $roles[0] : '';
}

/**
 * Build the authenticated identity payload consumed by the headless frontend.
 *
 * @param WP_User $user
 * @return array<string, mixed>
 */
function papelito_auth_build_identity_response( WP_User $user ): array {
	$primary_role = papelito_auth_normalize_primary_role( $user );
	$profile_complete = '1' === (string) get_user_meta( $user->ID, 'papelito_profile_complete', true );

	$response = array(
		'user' => array(
			'databaseId'      => $user->ID,
			'email'           => $user->user_email,
			'displayName'     => $user->display_name,
			'firstName'       => (string) get_user_meta( $user->ID, 'first_name', true ),
			'lastName'        => (string) get_user_meta( $user->ID, 'last_name', true ),
			'roles'           => array_values( array_map( 'sanitize_key', (array) $user->roles ) ),
			'role'            => $primary_role,
			'isAdministrator' => 'administrator' === $primary_role,
			'profileComplete' => $profile_complete,
		),
	);
	if ( function_exists( 'papelito_company_context' ) ) {
		$response['b2b'] = papelito_company_context( $user->ID );
	}
	return $response;
}

function papelito_auth_onboarding_required_error(): WP_Error {
	return new WP_Error( 'onboarding_required', PAPELITO_AUTH_ONBOARDING_RESUME_MESSAGE, array( 'status' => 409, 'onboardingRequired' => true ) );
}

function papelito_auth_complete_create_company_onboarding( int $user_id, WP_User $user, array $onboarding ) {
	$profile = papelito_company_profile_get( $user_id );
	$cpf     = $profile ? papelito_customer_profile_get_cpf( $user_id ) : null;
	$birth   = $profile && ! empty( $profile['birth_date_ciphertext'] ) ? papelito_pii_decrypt( (string) $profile['birth_date_ciphertext'] ) : null;

	if ( ! is_string( $cpf ) || ! is_string( $birth ) ) {
		return papelito_auth_onboarding_required_error();
	}

	$address = array();
	foreach ( array( 'cep', 'street', 'number', 'complement', 'neighborhood', 'city', 'state' ) as $field ) {
		$meta_key = 'papelito_b2b_onboarding_address_' . $field;
		if ( metadata_exists( 'user', $user_id, $meta_key ) ) {
			$address[ $field ] = (string) get_user_meta( $user_id, $meta_key, true );
		}
	}

	$result = papelito_company_create_owner_candidate(
		$user_id,
		array_merge(
			$address,
			array(
				'cpf'        => $cpf,
				'birth_date' => $birth,
				'cnpj'       => (string) $onboarding['target_cnpj'],
				'full_name'  => trim( $user->first_name . ' ' . $user->last_name ),
				'phone'      => (string) get_user_meta( $user_id, 'phone_number', true ),
			)
		)
	);

	if ( is_wp_error( $result ) ) {
		papelito_company_onboarding_mark_error( $user_id, $result->get_error_code() );
		return $result;
	}

	$company_id = (int) ( $result['company_id'] ?? 0 );
	papelito_company_onboarding_mark_completed( $user_id, $company_id, (int) ( $result['membership_id'] ?? 0 ) );

	return array( 'status' => 'completed', 'company_id' => $company_id );
}

function papelito_auth_complete_join_company_onboarding( int $user_id, array $onboarding ) {
	$result = papelito_company_access_request_submit( $user_id, (string) $onboarding['target_cnpj'] );
	if ( is_wp_error( $result ) ) {
		papelito_company_onboarding_mark_error( $user_id, $result->get_error_code() );
		return $result;
	}

	$pending = papelito_company_members_pending_for_user( $user_id );
	papelito_company_onboarding_mark_completed( $user_id, null, ! empty( $pending[0]['id'] ) ? (int) $pending[0]['id'] : null );

	return array( 'status' => 'completed' );
}

function papelito_auth_complete_b2b_onboarding( int $user_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return papelito_auth_onboarding_required_error();
	}

	$onboarding = papelito_company_onboarding_get( $user_id );
	if ( null === $onboarding || ( ! empty( $onboarding['expires_at'] ) && strtotime( (string) $onboarding['expires_at'] ) < time() ) ) {
		return papelito_auth_onboarding_required_error();
	}

	if ( 'completed' === (string) $onboarding['status'] ) {
		return array( 'status' => 'completed', 'idempotent' => true );
	}

	if ( 'create_company' === (string) $onboarding['onboarding_type'] ) {
		return papelito_auth_complete_create_company_onboarding( $user_id, $user, $onboarding );
	}

	if ( 'join_company' === (string) $onboarding['onboarding_type'] ) {
		return papelito_auth_complete_join_company_onboarding( $user_id, $onboarding );
	}

	return papelito_auth_onboarding_required_error();
}

/**
 * Retorna timestamp UTC no formato MySQL usado pelo projeto.
 *
 * @return string
 */
function papelito_auth_current_utc_mysql(): string {
	if ( function_exists( 'papelito_current_utc_mysql' ) ) {
		return papelito_current_utc_mysql();
	}

	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Informa se o usuario ainda precisa confirmar o e-mail.
 *
 * Ausencia da meta significa usuario legado ja verificado.
 *
 * @param int $user_id
 * @return bool
 */
function papelito_auth_requires_email_verification( int $user_id ): bool {
	$status = (string) get_user_meta( $user_id, 'papelito_email_verification_status', true );

	if ( '' === $status ) {
		return false;
	}

	return 'verified' !== $status;
}

/**
 * Marca o usuario como verificado e limpa o token temporario.
 *
 * @param int $user_id
 * @return void
 */
function papelito_auth_mark_email_verified( int $user_id ): void {
	update_user_meta( $user_id, 'papelito_email_verification_status', 'verified' );
	update_user_meta( $user_id, 'papelito_email_verified_at', papelito_auth_current_utc_mysql() );
	delete_user_meta( $user_id, 'papelito_email_verification_token_hash' );
	delete_user_meta( $user_id, 'papelito_email_verification_token_expires_at' );
	delete_user_meta( $user_id, 'papelito_post_email_verification_path' );

	/**
	 * Disparado sempre que o e-mail principal passa a valer como verificado.
	 *
	 * Centraliza os quatro caminhos que marcam verificado (login Google, /auth/verify-email,
	 * ativacao manual pelo admin e redefinicao de senha) num unico ponto de extensao, para que o
	 * e-mail de faturamento igual ao principal seja confirmado sem pedir nada ao usuario.
	 *
	 * @param int $user_id Usuario verificado.
	 */
	do_action( 'papelito_email_verified', $user_id );
}

/**
 * Lista os meios de autenticacao que a conta realmente consegue usar hoje.
 *
 * Ausencia da meta de senha nao-definida significa senha escolhida pelo proprio usuario
 * (todo cadastro por senha e a base legada), portanto 'password' e o default.
 *
 * @param int $user_id Usuario avaliado.
 * @return array<int,string> Subconjunto de {'password','google'}.
 */
function papelito_auth_credential_methods( int $user_id ): array {
	$methods = array();

	if ( '1' !== (string) get_user_meta( $user_id, PAPELITO_AUTH_PASSWORD_UNSET_META, true ) ) {
		$methods[] = 'password';
	}

	if ( '' !== (string) get_user_meta( $user_id, 'google_sub', true ) ) {
		$methods[] = 'google';
	}

	return $methods;
}

/**
 * Marca que a conta nasceu sem senha escolhida (senha aleatoria, so entra por OAuth).
 *
 * @param int $user_id Conta recem-criada.
 * @return void
 */
function papelito_auth_mark_password_unset( int $user_id ): void {
	update_user_meta( $user_id, PAPELITO_AUTH_PASSWORD_UNSET_META, '1' );
}

/**
 * Registra que a conta passou a ter senha propria (cadastro, troca ou redefinicao).
 *
 * @param int $user_id Conta que definiu a senha.
 * @return void
 */
function papelito_auth_mark_password_set( int $user_id ): void {
	delete_user_meta( $user_id, PAPELITO_AUTH_PASSWORD_UNSET_META );
}

/**
 * Marca o usuario como pendente de verificacao.
 *
 * @param int $user_id
 * @return void
 */
function papelito_auth_mark_email_pending( int $user_id ): void {
	update_user_meta( $user_id, 'papelito_email_verification_status', 'pending' );
	delete_user_meta( $user_id, 'papelito_email_verified_at' );
}

/**
 * Arma o toast de boas-vindas para uma conta recem-criada.
 *
 * add_user_meta com $unique = true e no-op quando a meta ja existe (em 'pending' ou 'shown'),
 * entao rearmar e impossivel por construcao. So deve ser chamado nos pontos de criacao de conta:
 * chamar em papelito_auth_mark_email_verified() rearmaria a base toda, porque o login Google de
 * usuario existente passa por la a cada autenticacao.
 *
 * Contas anteriores a este fluxo ficam sem a meta e nunca sao elegiveis — nao ha backfill.
 *
 * @param int $user_id
 * @return void
 */
function papelito_auth_welcome_toast_arm( int $user_id ): void {
	add_user_meta( $user_id, PAPELITO_WELCOME_TOAST_META, 'pending', true );
}

/**
 * Reivindica a exibicao unica do toast de boas-vindas.
 *
 * Elegibilidade: conta armada no cadastro + e-mail confirmado + conta aprovada (membership ativa
 * numa empresa ativa, que e o onboardingStatus 'complete' do contexto B2B). As checagens vao da
 * mais barata para a mais cara: a leitura da usermeta corta usuario legado e quem ja viu antes de
 * tocar no contexto de empresa.
 *
 * A transicao 'pending' -> 'shown' usa update_user_meta com $prev_value, que vira
 * UPDATE ... WHERE meta_value = 'pending'. Em concorrencia (duas abas, dois dispositivos) apenas
 * um chamador recebe true.
 *
 * @param int $user_id
 * @return array{shown:bool,firstName:string}
 */
function papelito_auth_welcome_toast_claim( int $user_id ): array {
	$denied = array(
		'shown'     => false,
		'firstName' => '',
	);

	if ( 'pending' !== (string) get_user_meta( $user_id, PAPELITO_WELCOME_TOAST_META, true ) ) {
		return $denied;
	}

	if ( papelito_auth_requires_email_verification( $user_id ) ) {
		return $denied;
	}

	$context = function_exists( 'papelito_company_context' ) ? papelito_company_context( $user_id ) : array();

	if ( 'complete' !== (string) ( $context['onboardingStatus'] ?? '' ) ) {
		return $denied;
	}

	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return $denied;
	}

	if ( ! update_user_meta( $user_id, PAPELITO_WELCOME_TOAST_META, 'shown', 'pending' ) ) { // NOSONAR: o quarto argumento do WordPress implementa o compare-and-swap.
		return $denied;
	}

	update_user_meta( $user_id, 'papelito_welcome_toast_shown_at', papelito_auth_current_utc_mysql() );

	return array(
		'shown'     => true,
		'firstName' => papelito_auth_welcome_toast_first_name( $user ),
	);
}

/**
 * Primeiro nome exibido no toast, com fallback para o display_name.
 *
 * @param WP_User $user
 * @return string
 */
function papelito_auth_welcome_toast_first_name( WP_User $user ): string {
	$first_name = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );

	if ( '' === $first_name ) {
		$first_name = trim( (string) $user->display_name );
	}

	if ( '' === $first_name ) {
		return '';
	}

	$parts = preg_split( '/\s+/', $first_name );

	return is_array( $parts ) && isset( $parts[0] ) ? (string) $parts[0] : '';
}

/**
 * Gera token de verificacao e persiste apenas hash + expiracao.
 *
 * @param int $user_id
 * @return string|WP_Error
 */
function papelito_auth_prepare_email_verification_token( int $user_id ) {
	try {
		$token = bin2hex( random_bytes( 32 ) );
	} catch ( Exception $exception ) {
		$token = wp_generate_password( 64, false, false );
	}

	if ( '' === $token ) {
		return new WP_Error(
			'papelito_email_verification_token_failed',
			'Não foi possível preparar a confirmação de e-mail.',
			array( 'status' => 500 )
		);
	}

	papelito_auth_mark_email_pending( $user_id );
	update_user_meta( $user_id, 'papelito_email_verification_token_hash', hash( 'sha256', $token ) );
	update_user_meta( $user_id, 'papelito_email_verification_token_expires_at', gmdate( 'c', time() + DAY_IN_SECONDS ) );

	return $token;
}

/**
 * Resolve a base publica do frontend usada em links de e-mail.
 *
 * Mantida para os chamadores que montam link informativo dentro de um e-mail ja em voo e nao tem
 * como abortar o envio. Em ambiente remoto sem configuracao devolve string vazia (link relativo,
 * degradado e logado) em vez de `localhost`.
 *
 * @return string
 */
function papelito_auth_get_frontend_url(): string {
	$base = papelito_frontend_base_url();

	if ( '' === $base ) {
		error_log( 'papelito: PAPELITO_FRONTEND_URL/PAPELITO_ALLOWED_ORIGINS ausentes; links de e-mail sairao relativos.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	return $base;
}

/**
 * Monta o link publico de verificacao usado no e-mail.
 *
 * @param string $email
 * @param string $token
 * @return string
 */
function papelito_auth_build_email_verification_link( string $email, string $token ): string {
	$link = papelito_frontend_link(
		sprintf( 'confirmar-email?email=%s&token=%s', rawurlencode( $email ), rawurlencode( $token ) )
	);

	return is_wp_error( $link ) ? '' : (string) $link;
}

/**
 * Envia o e-mail com o link de verificacao.
 *
 * @param WP_User $user
 * @param string  $token
 * @return bool
 */
function papelito_auth_send_verification_email( WP_User $user, string $token ): bool {
	$first_name = (string) get_user_meta( $user->ID, 'first_name', true );
	$recipient  = sanitize_email( $user->user_email );

	if ( '' === $recipient ) {
		return false;
	}

	$link = papelito_auth_build_email_verification_link( $recipient, $token );

	if ( '' === $link ) {
		return false;
	}

	$return_path = (string) get_user_meta( $user->ID, 'papelito_post_email_verification_path', true );
	if ( '/convite' === $return_path ) {
		$link .= '&callbackUrl=' . rawurlencode( $return_path );
	}
	$subject    = 'Confirme seu e-mail - Papelito';
	$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
	$body_lines = array(
		sprintf( 'Ola %s,', '' !== $first_name ? $first_name : $recipient ),
		'',
		'Recebemos o seu cadastro na Papelito.',
		'Confirme seu e-mail para liberar o login com senha e concluir a ativacao da conta.',
		'',
		'Abra o link abaixo para confirmar:',
		$link,
		'',
		'Este link expira em 24 horas.',
		'Se você não fez esse cadastro, ignore esta mensagem.',
		'',
		'Time Papelito',
	);

	return wp_mail( $recipient, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Rotaciona e envia um novo token de verificacao por e-mail.
 *
 * @param WP_User $user
 * @return true|WP_Error
 */
function papelito_auth_dispatch_verification_email( WP_User $user ) {
	$token = papelito_auth_prepare_email_verification_token( $user->ID );

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	if ( ! papelito_auth_send_verification_email( $user, $token ) ) {
		return new WP_Error(
			'papelito_email_verification_send_failed',
			'Não foi possível enviar o e-mail de confirmação. Tente novamente em alguns instantes.',
			array( 'status' => 500 )
		);
	}

	update_user_meta( $user->ID, 'papelito_email_verification_sent_at', papelito_auth_current_utc_mysql() );

	return true;
}

/**
 * Monta o link publico de redefinicao de senha.
 *
 * @param string $login
 * @param string $key
 * @return string
 */
function papelito_auth_build_password_reset_link( string $login, string $key ): string {
	$link = papelito_frontend_link(
		sprintf( 'redefinir-senha?login=%s&key=%s', rawurlencode( $login ), rawurlencode( $key ) )
	);

	return is_wp_error( $link ) ? '' : (string) $link;
}

/**
 * Envia o e-mail de redefinicao de senha.
 *
 * @param WP_User $user
 * @param string  $key
 * @return bool
 */
function papelito_auth_send_password_reset_email( WP_User $user, string $key ): bool {
	$recipient  = sanitize_email( $user->user_email );
	$first_name = (string) get_user_meta( $user->ID, 'first_name', true );

	if ( '' === $recipient ) {
		return false;
	}

	$link = papelito_auth_build_password_reset_link( $user->user_login, $key );

	if ( '' === $link ) {
		return false;
	}

	$subject    = 'Redefina sua senha - Papelito';
	$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
	$body_lines = array(
		sprintf( 'Ola %s,', '' !== $first_name ? $first_name : $recipient ),
		'',
		'Recebemos uma solicitação para redefinir a senha da sua conta na Papelito.',
		'',
		'Abra o link abaixo para cadastrar uma nova senha:',
		$link,
		'',
		'Este link expira em 24 horas e pode ser usado uma única vez.',
		'Se você não fez esta solicitação, ignore esta mensagem.',
		'',
		'Time Papelito',
	);

	return wp_mail( $recipient, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Gera a chave nativa do WordPress e tenta enviar o e-mail de reset.
 *
 * @param WP_User $user
 * @return true|WP_Error
 */
function papelito_auth_dispatch_password_reset_email( WP_User $user ) {
	$key = get_password_reset_key( $user );

	if ( is_wp_error( $key ) || '' === (string) $key ) {
		return true;
	}

	if ( ! papelito_auth_send_password_reset_email( $user, $key ) ) {
		return new WP_Error(
			'papelito_password_reset_send_failed',
			'Não foi possível enviar o e-mail de redefinicao. Tente novamente em alguns instantes.',
			array( 'status' => 500 )
		);
	}

	return true;
}

/**
 * Reenvia a verificacao para contas pendentes sem quebrar a resposta generica.
 *
 * @param WP_User $user
 * @return true|WP_Error
 */
function papelito_auth_maybe_resend_pending_verification( WP_User $user ) {
	if ( ! papelito_auth_requires_email_verification( $user->ID ) ) {
		return true;
	}

	$last_sent_at = (string) get_user_meta( $user->ID, 'papelito_email_verification_sent_at', true );
	$last_sent_ts = '' !== $last_sent_at ? strtotime( $last_sent_at ) : false;

	if ( false !== $last_sent_ts && ( time() - $last_sent_ts ) < MINUTE_IN_SECONDS ) {
		return true;
	}

	return papelito_auth_dispatch_verification_email( $user );
}

/**
 * Converte erros nativos de chave de reset para mensagens amigaveis do frontend.
 *
 * @param WP_Error $error
 * @return WP_Error
 */
function papelito_auth_map_password_reset_key_error( WP_Error $error ) {
	$codes = array_values( $error->get_error_codes() );

	if ( in_array( 'expired_key', $codes, true ) ) {
		return new WP_Error(
			'papelito_password_reset_expired',
			'Link de redefinicao expirado. Solicite um novo e-mail para continuar.',
			array( 'status' => 410 )
		);
	}

	return new WP_Error(
		'papelito_password_reset_invalid',
		'Link de redefinicao inválido ou expirado.',
		array( 'status' => 400 )
	);
}

add_filter(
	'wp_authenticate_user',
	static function ( $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $user;
		}

		if ( ! papelito_auth_requires_email_verification( $user->ID ) ) {
			return $user;
		}

		return new WP_Error(
			'papelito_email_not_verified',
			'Confirme seu e-mail antes de entrar.'
		);
	},
	10,
	1
);

/**
 * Rate limit por identidade opaca do pedido. O WordPress recebe chamadas publicas pelo proxy
 * Next, portanto `REMOTE_ADDR` seria compartilhado por todo o marketplace.
 *
 * @param string $bucket Identificador do endpoint (ex: 'google', 'register').
 * @param int    $max        Máximo de tentativas na janela.
 * @param int    $window     Janela em segundos.
 * @param string $identifier Identidade recebida no payload, nunca gravada em claro. Chamadores
 *                           que passam pelo proxy Next devem sempre fornecê-la; o fallback existe
 *                           apenas para rotas legadas chamadas diretamente pelo navegador.
 * @return bool true se permitido, false se bloqueado.
 */
function papelito_auth_rate_limit( string $bucket, int $max = 20, int $window = 60, string $identifier = '' ): bool {
	$identifier = trim( $identifier );

	if ( '' === $identifier ) {
		return papelito_rate_limit( 'auth_' . $bucket, papelito_rate_limit_identity(), $max, $window );
	}

	return papelito_rate_limit( 'auth_' . $bucket, 'identity:' . hash( 'sha256', $identifier ), $max, $window );
}

/**
 * Invalida sessoes WordPress e todos os JWTs emitidos antes da troca de credencial.
 *
 * @param int $user_id Usuario cuja credencial mudou.
 * @return void
 */
function papelito_auth_invalidate_user_sessions( int $user_id ): void {
	update_user_meta( $user_id, PAPELITO_AUTH_SESSION_VERSION_META, wp_generate_uuid4() );

	if ( class_exists( 'WP_Session_Tokens' ) ) {
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
	}
}

/**
 * Troca a senha da propria conta somente apos confirmar a credencial atual.
 *
 * @param WP_User $user Usuario autenticado.
 * @param array   $data Payload validado pelo endpoint.
 * @return true|WP_Error
 */
function papelito_auth_change_password( WP_User $user, array $data ) {
	if ( ! papelito_rate_limit( 'password_change', papelito_rate_limit_identity(), 5, 300 ) ) {
		return new WP_Error( 'papelito_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$current_password = isset( $data['currentPassword'] ) ? (string) $data['currentPassword'] : '';
	$password         = isset( $data['password'] ) ? (string) $data['password'] : '';
	$confirm_password = isset( $data['confirmPassword'] ) ? (string) $data['confirmPassword'] : '';

	if ( '' === $current_password || ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
		return new WP_Error( 'papelito_current_password_invalid', 'Não foi possível confirmar a senha atual.', array( 'status' => 403 ) );
	}

	if ( strlen( $password ) < 8 ) {
		return new WP_Error( 'papelito_password_too_short', 'A nova senha precisa ter pelo menos 8 caracteres.', array( 'status' => 422 ) );
	}

	if ( $password !== $confirm_password ) {
		return new WP_Error( 'papelito_password_mismatch', 'As senhas precisam coincidir.', array( 'status' => 422 ) );
	}

	wp_set_password( $password, $user->ID );
	papelito_auth_mark_password_set( $user->ID );
	papelito_auth_invalidate_user_sessions( $user->ID );

	return true;
}

/**
 * Verifica um Google ID token via endpoint oficial tokeninfo. Sem deps externas.
 *
 * @param string $id_token
 * @return array|WP_Error Payload decodificado ou erro.
 */
function papelito_auth_verify_google_id_token( string $id_token ) {
	$response = wp_remote_get(
		'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ),
		array( 'timeout' => 5 )
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'papelito_google_unreachable',
			'Não foi possível validar o token com o Google.',
			array( 'status' => 502 )
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code || ! is_array( $body ) ) {
		return new WP_Error(
			'papelito_invalid_token',
			'Token Google inválido ou expirado.',
			array( 'status' => 401 )
		);
	}

	$expected_aud = defined( 'PAPELITO_GOOGLE_CLIENT_ID' ) ? PAPELITO_GOOGLE_CLIENT_ID : '';
	$received_aud = isset( $body['aud'] ) ? (string) $body['aud'] : '';

	if ( '' === $expected_aud || $expected_aud !== $received_aud ) {
		return new WP_Error(
			'papelito_invalid_token',
			'Audience do token Google não confere.',
			array( 'status' => 401 )
		);
	}

	if ( empty( $body['email'] ) || empty( $body['email_verified'] ) || 'true' !== (string) $body['email_verified'] ) {
		return new WP_Error(
			'papelito_unverified_email',
			'E-mail do Google não verificado.',
			array( 'status' => 401 )
		);
	}

	return $body;
}

/**
 * Carrega o WP_User já existente resolvido pelo e-mail do payload Google.
 *
 * @param int $user_id ID retornado por email_exists().
 * @return WP_User|WP_Error
 */
function papelito_auth_google_existing_user( int $user_id ) {
	$user = get_userdata( $user_id );

	return $user instanceof WP_User
		? $user
		: new WP_Error( 'papelito_user_lookup_failed', 'Falha ao carregar usuário.', array( 'status' => 500 ) );
}

function papelito_auth_sync_google_user( WP_User $user, string $email, array $payload ) {
	$known_google_sub = (string) get_user_meta( $user->ID, 'google_sub', true );
	if ( '' !== $known_google_sub && ! empty( $payload['sub'] ) && ! hash_equals( $known_google_sub, (string) $payload['sub'] ) ) {
		return new WP_Error( 'papelito_google_account_conflict', 'Esta conta já está vinculada a outra identidade Google.', array( 'status' => 409 ) );
	}

	if ( '' === $known_google_sub && ! empty( $payload['sub'] ) ) {
		update_user_meta( $user->ID, 'google_sub', sanitize_text_field( (string) $payload['sub'] ) );
	}

	if ( papelito_emails_match( (string) $user->user_email, $email ) ) {
		update_user_meta( $user->ID, 'papelito_email_verification_method', 'google' );
		papelito_auth_mark_email_verified( $user->ID );
	}

	if ( '1' !== (string) get_user_meta( $user->ID, 'papelito_profile_complete', true ) && null === papelito_company_onboarding_get( $user->ID ) ) {
		papelito_b2b_mark_cohort( $user->ID );
		papelito_company_onboarding_mark_google( $user->ID );
	}

	return $user;
}

/**
 * Informa se um token de convite pendente autoriza criar conta para exatamente este e-mail.
 *
 * @param string $token Token em claro (vazio quando o login nao parte de um convite).
 * @param string $email E-mail normalizado.
 * @return bool
 */
function papelito_auth_invitation_authorizes_email( string $token, string $email ): bool {
	if ( '' === trim( $token ) || '' === $email || ! function_exists( 'papelito_company_invitation_find_pending_by_token' ) ) {
		return false;
	}

	$invitation = papelito_company_invitation_find_pending_by_token( $token );

	return null !== $invitation && hash_equals( strtolower( (string) $invitation['invited_email'] ), strtolower( $email ) );
}

/**
 * Cria a conta de um convidado que optou por entrar pelo Google.
 *
 * Um convite pendente E a pre-aprovacao que a candidatura empresarial forneceria, portanto
 * dispensa o fluxo de pre-conta. A conta nasce sem senha propria (entra so por OAuth) e com
 * e-mail verificado, porque `papelito_auth_verify_google_id_token()` ja exige `email_verified`.
 *
 * @param string $email   E-mail normalizado do payload.
 * @param array  $payload Payload verificado do tokeninfo.
 * @return WP_User|WP_Error
 */
function papelito_auth_create_google_invited_user( string $email, array $payload ) {
	$user_id = wp_insert_user(
		array(
			'user_login' => $email,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'first_name' => papelito_normalize_unicode_spaces( sanitize_text_field( (string) ( $payload['given_name'] ?? '' ) ) ),
			'last_name'  => papelito_normalize_unicode_spaces( sanitize_text_field( (string) ( $payload['family_name'] ?? '' ) ) ),
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	update_user_meta( $user_id, 'papelito_profile_complete', '0' );
	papelito_auth_mark_email_verified( $user_id );
	papelito_auth_mark_password_unset( $user_id );
	papelito_auth_welcome_toast_arm( $user_id );
	papelito_b2b_mark_cohort( $user_id );

	$user = get_userdata( $user_id );

	return $user instanceof WP_User
		? $user
		: new WP_Error( 'papelito_user_lookup_failed', PAPELITO_AUTH_NEW_USER_LOOKUP_MESSAGE, array( 'status' => 500 ) );
}

/**
 * Resolve o WP_User a partir de um payload Google verificado.
 *
 * @param array  $payload          Payload do tokeninfo.
 * @param string $invitation_token Token de convite em claro, quando o login parte de um convite.
 * @return WP_User|WP_Error
 */
function papelito_auth_find_or_create_google_user( array $payload, string $invitation_token = '' ) {
	$email = papelito_normalize_email( (string) $payload['email'] );
	if ( '' === $email ) {
		return new WP_Error( 'papelito_invalid_email', PAPELITO_AUTH_INVALID_EMAIL_MESSAGE, array( 'status' => 400 ) );
	}

	$existing_id = email_exists( $email );
	if ( ! $existing_id ) {
		if ( papelito_auth_invitation_authorizes_email( $invitation_token, $email ) ) {
			$created = papelito_auth_create_google_invited_user( $email, $payload );

			// O vinculo da identidade Google (google_sub) vive num unico lugar: sem passar pelo
			// sync, a conta recem-criada ficaria sem nenhum meio de entrada declarado.
			return is_wp_error( $created ) ? $created : papelito_auth_sync_google_user( $created, $email, $payload );
		}

		return new WP_Error( 'papelito_pre_account_required', 'Conclua a candidatura empresarial antes de criar uma conta.', array( 'status' => 422 ) );
	}

	$user = papelito_auth_google_existing_user( (int) $existing_id );
	if ( is_wp_error( $user ) ) {
		return $user;
	}

	return papelito_auth_sync_google_user( $user, $email, $payload );
}

/**
 * Valida campos do registro (mesmas regras dos hooks de WooCommerce).
 *
 * @param WP_Error $errors Coletor preenchido com as falhas encontradas.
 * @param array    $data   Dados enviados no registro.
 * @return void
 */
function papelito_auth_validate_register_identity( WP_Error $errors, array $data ): void {
	$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
	if ( '' === $email || ! is_email( $email ) ) {
		$errors->add( 'email', PAPELITO_AUTH_INVALID_EMAIL_MESSAGE );
	}

	$password = isset( $data['password'] ) ? (string) $data['password'] : '';
	if ( strlen( $password ) < 8 ) {
		$errors->add( 'password', 'Senha precisa ter pelo menos 8 caracteres.' );
	}

	$first_name_error = papelito_name_part_validation_error( (string) ( $data['first_name'] ?? '' ), 'Informe o seu nome.' );
	if ( $first_name_error ) {
		$errors->add( 'first_name', $first_name_error );
	}

	$last_name_error = papelito_name_part_validation_error( (string) ( $data['last_name'] ?? '' ), 'Informe o seu sobrenome.' );
	if ( $last_name_error ) {
		$errors->add( 'last_name', $last_name_error );
	}
}

function papelito_auth_validate_register_contact( WP_Error $errors, array $data ): void {
	if ( papelito_phone_validation_error( isset( $data['phone_number'] ) ? (string) $data['phone_number'] : '' ) ) {
		$errors->add( 'phone_number', 'Telefone inválido. Formato esperado: (11) 99999-9999.' );
	}

	$cep = isset( $data['cep'] ) ? (string) $data['cep'] : '';
	if ( ! papelito_auth_is_valid_cep( $cep ) ) {
		$errors->add( 'cep', 'CEP inválido. Formato esperado: 01310-000.' );
	}

	foreach ( array( 'street', 'number', 'neighborhood', 'city', 'state' ) as $field ) {
		if ( '' === trim( (string) ( $data[ $field ] ?? '' ) ) ) {
			$errors->add( $field, 'Endereço incompleto.' );
		}
	}

	if ( ! papelito_validate_cnpj( (string) ( $data['cnpj'] ?? '' ) ) ) { $errors->add( 'cnpj', 'CNPJ inválido.' ); }
	if ( ! isset( $data['birth_date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $data['birth_date'] ) ) { $errors->add( 'birth_date', 'Data de nascimento inválida.' ); }
	if ( ! papelito_validate_cpf( (string) ( $data['cpf'] ?? '' ) ) ) { $errors->add( 'cpf', 'CPF inválido.' ); }

	if ( ! empty( $data['state'] ) && ! array_key_exists( (string) $data['state'], papelito_brazilian_states() ) ) {
		$errors->add( 'state', 'Estado inválido.' );
	}
}

function papelito_auth_validate_register_payload( array $data ) {
	$errors = new WP_Error();
	papelito_auth_validate_register_identity( $errors, $data );
	papelito_auth_validate_register_contact( $errors, $data );

	return $errors->has_errors() ? $errors : null;
}

/**
 * Cria um usuário a partir de um payload validado de cadastro.
 *
 * @param array $data
 * @return WP_User|WP_Error
 */
function papelito_auth_create_registered_user( array $data ) {
	$email = sanitize_email( (string) $data['email'] );

	if ( email_exists( $email ) ) {
		return new WP_Error(
			'papelito_email_exists',
			'Já existe uma conta com este e-mail.',
			array( 'status' => 409 )
		);
	}

	$user_id = wp_insert_user(
		array(
			'user_login' => $email,
			'user_email' => $email,
			'user_pass'  => (string) $data['password'],
			'first_name' => papelito_normalize_unicode_spaces( sanitize_text_field( (string) $data['first_name'] ) ),
			'last_name'  => papelito_normalize_unicode_spaces( sanitize_text_field( (string) $data['last_name'] ) ),
			'role'       => 'customer',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$meta_keys = array( 'phone_number', 'instagram', 'state', 'city', 'cep' );

	foreach ( $meta_keys as $key ) {
		if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
			$value = 'phone_number' === $key
				? papelito_auth_format_phone( (string) $data[ $key ] )
				: sanitize_text_field( (string) $data[ $key ] );
			update_user_meta( $user_id, $key, $value );
		}
	}

	update_user_meta( $user_id, 'papelito_profile_complete', '0' );
	papelito_auth_mark_email_pending( $user_id );
	papelito_auth_welcome_toast_arm( $user_id );

	$profile = papelito_company_profile_upsert( $user_id, (string) $data['cpf'], (string) ( $data['birth_date'] ?? '' ) );
	if ( is_wp_error( $profile ) ) { papelito_auth_delete_partial_user( $user_id ); return $profile; }
	$requested_onboarding = (string) ( $data['onboarding_type'] ?? $data['intent'] ?? '' );
	$onboarding_type      = in_array( $requested_onboarding, array( 'create_company', 'join_company' ), true )
		? $requested_onboarding
		: 'create_company';
	$onboarding = papelito_company_onboarding_upsert( $user_id, $onboarding_type, (string) ( $data['cnpj'] ?? '' ), 'pending_email' );
	if ( is_wp_error( $onboarding ) ) { papelito_auth_delete_partial_user( $user_id ); return $onboarding; }
	$address = papelito_company_onboarding_save_address( $user_id, (string) $data['cep'], $data );
	if ( is_wp_error( $address ) ) { papelito_auth_delete_partial_user( $user_id ); return $address; }
	papelito_b2b_mark_cohort( $user_id );

	$user = get_userdata( $user_id );

	return $user instanceof WP_User
		? $user
		: new WP_Error( 'papelito_user_lookup_failed', PAPELITO_AUTH_NEW_USER_LOOKUP_MESSAGE, array( 'status' => 500 ) );
}

/**
 * Remove uma conta criada pela metade, funcionando também fora do wp-admin.
 *
 * `wp_delete_user()` só é declarada em `wp-admin/includes/user.php`, que não é carregado numa
 * requisição REST. Chamá-la direto derruba o processo com erro crítico ANTES de apagar nada, e a
 * conta parcial fica no banco — sem perfil de CPF e, pior, sem a meta de verificação de e-mail,
 * que `papelito_auth_requires_email_verification()` lê como "conta legada verificada".
 */
function papelito_auth_delete_partial_user( int $user_id ): void {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	wp_delete_user( $user_id );
}

/** Validação mínima para uma conta criada exclusivamente a partir de convite empresarial. */
function papelito_auth_validate_invitation_register_payload( array $data ) {
	$errors = new WP_Error();
	$email = strtolower( sanitize_email( trim( (string) ( $data['email'] ?? '' ) ) ) );
	if ( '' === $email || ! is_email( $email ) ) {
		$errors->add( 'email', PAPELITO_AUTH_INVALID_EMAIL_MESSAGE );
	}
	if ( strlen( (string) ( $data['password'] ?? '' ) ) < 8 ) {
		$errors->add( 'password', 'Senha precisa ter pelo menos 8 caracteres.' );
	}
	$cpf = trim( (string) ( $data['cpf'] ?? '' ) );
	if ( '' === $cpf ) {
		$errors->add( 'cpf', 'Informe seu CPF.' );
	} elseif ( ! papelito_validate_cpf( $cpf ) ) {
		$errors->add( 'cpf', 'Informe um CPF válido.' );
	}
	if ( '' === trim( (string) ( $data['token'] ?? '' ) ) ) {
		$errors->add( 'token', 'Dados do convite incompletos.' );
	}
	foreach ( array( 'first_name', 'last_name' ) as $field ) {
		$name_error = papelito_name_part_validation_error( (string) ( $data[ $field ] ?? '' ), 'Dados do convite incompletos.' );
		if ( $name_error ) {
			$errors->add( $field, $name_error );
		}
	}
	return $errors->has_errors() ? $errors : null;
}

/** Cria uma conta com o perfil CPF; a autorização empresarial só é ativada no aceite posterior. */
function papelito_auth_create_invited_user( array $data ): WP_User|WP_Error {
	$email = strtolower( sanitize_email( trim( (string) $data['email'] ) ) );
	if ( email_exists( $email ) ) {
		return new WP_Error( 'papelito_invitation_registration_unavailable', 'Não foi possível criar uma conta para este convite.', array( 'status' => 409 ) );
	}
	// O CPF é conferido ANTES de existir conta: um CPF já vinculado a outra pessoa não pode
	// produzir uma conta pela metade nem um 500 genérico.
	$cpf_owner = papelito_customer_profile_find_user_by_cpf( (string) $data['cpf'] );
	if ( is_wp_error( $cpf_owner ) ) {
		return $cpf_owner;
	}
	if ( null !== $cpf_owner ) {
		return new WP_Error( 'papelito_pii_cpf_in_use', PAPELITO_AUTH_CPF_IN_USE_MESSAGE, array( 'status' => 409 ) );
	}
	$user_id = wp_insert_user(
		array(
			'user_login' => $email,
			'user_email' => $email,
			'user_pass' => (string) $data['password'],
			'first_name' => papelito_normalize_unicode_spaces( sanitize_text_field( (string) $data['first_name'] ) ),
			'last_name' => papelito_normalize_unicode_spaces( sanitize_text_field( (string) $data['last_name'] ) ),
			'role' => 'customer',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	// Antes de qualquer passo que possa falhar: sem esta meta, uma conta parcial passa por conta
	// legada e entra como e-mail já confirmado.
	papelito_auth_mark_email_pending( $user_id );
	$profile = papelito_customer_profile_upsert(
		$user_id,
		(string) $data['cpf'],
		array(
			'identity_status'     => 'verified',
			'identity_method'     => 'invitation_registration',
			'identity_checked_at' => current_time( 'mysql', true ),
		)
	);
	if ( is_wp_error( $profile ) ) {
		papelito_auth_delete_partial_user( $user_id );
		return 'papelito_pii_cpf_in_use' === $profile->get_error_code()
			? new WP_Error( 'papelito_pii_cpf_in_use', PAPELITO_AUTH_CPF_IN_USE_MESSAGE, array( 'status' => 409 ) )
			: $profile;
	}
	update_user_meta( $user_id, 'papelito_profile_complete', '0' );
	papelito_auth_welcome_toast_arm( $user_id );
	papelito_b2b_mark_cohort( $user_id );
	update_user_meta( $user_id, 'papelito_post_email_verification_path', '/convite' );
	$user = get_userdata( $user_id );
	return $user instanceof WP_User ? $user : new WP_Error( 'papelito_user_lookup_failed', PAPELITO_AUTH_NEW_USER_LOOKUP_MESSAGE, array( 'status' => 500 ) );
}

function papelito_auth_request_data( WP_REST_Request $request ): array {
	$data = $request->get_json_params();

	return is_array( $data ) ? $data : $request->get_params();
}

function papelito_auth_logged_in(): bool {
	return is_user_logged_in();
}

function papelito_auth_handle_current_user() {
	$user = wp_get_current_user();
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'papelito_not_authenticated', 'Usuário não autenticado.', array( 'status' => 401 ) );
	}

	return new WP_REST_Response( papelito_auth_build_identity_response( $user ), 200 );
}

function papelito_auth_handle_welcome_toast_claim() {
	$user = wp_get_current_user();
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'papelito_not_authenticated', 'Usuário não autenticado.', array( 'status' => 401 ) );
	}

	return new WP_REST_Response( papelito_auth_welcome_toast_claim( $user->ID ), 200 );
}

function papelito_auth_handle_google( WP_REST_Request $request ) {
	$id_token = (string) $request->get_param( 'id_token' );
	if ( '' === trim( $id_token ) ) {
		return new WP_Error( 'papelito_missing_token', 'id_token ausente.', array( 'status' => 400 ) );
	}
	if ( ! papelito_auth_rate_limit( 'google', 20, 60, $id_token ) ) {
		return new WP_Error( 'papelito_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$payload = papelito_auth_verify_google_id_token( $id_token );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}

	$user = papelito_auth_find_or_create_google_user( $payload, (string) $request->get_param( 'invitation_token' ) );
	if ( is_wp_error( $user ) ) {
		return $user;
	}

	$response = papelito_auth_build_token_response( $user );
	return is_wp_error( $response ) ? $response : new WP_REST_Response( $response, 200 );
}

function papelito_auth_handle_register( WP_REST_Request $request ) {
	if ( ! papelito_b2b_company_model_enabled() ) {
		return new WP_Error( 'papelito_b2b_company_rollout_disabled', 'Cadastro empresarial temporariamente indisponível.', array( 'status' => 503 ) );
	}

	$writes = papelito_b2b_require_company_writes();
	if ( is_wp_error( $writes ) ) {
		return $writes;
	}

	return new WP_Error( 'papelito_pre_account_required', 'Use o fluxo de candidatura empresarial para criar uma conta após aprovação.', array( 'status' => 422 ) );
}

function papelito_auth_handle_invitation_register( WP_REST_Request $request ) {
	if ( ! papelito_b2b_company_model_enabled() ) {
		return new WP_Error( 'papelito_rate_limited', 'Não foi possível processar este cadastro agora.', array( 'status' => 429 ) );
	}

	$data       = papelito_auth_request_data( $request );
	if ( ! papelito_auth_rate_limit( 'register_invitation', 10, 60, (string) ( $data['token'] ?? '' ) ) ) {
		return new WP_Error( 'papelito_rate_limited', 'Não foi possível processar este cadastro agora.', array( 'status' => 429 ) );
	}
	$validation = papelito_auth_validate_invitation_register_payload( $data );
	if ( is_wp_error( $validation ) ) {
		$validation->add_data( array( 'status' => 422 ) );
		return $validation;
	}

	$invitation = papelito_company_invitation_find_pending_by_token( (string) $data['token'] );
	$email      = strtolower( sanitize_email( trim( (string) $data['email'] ) ) );
	if ( null === $invitation || ! hash_equals( strtolower( (string) $invitation['invited_email'] ), $email ) ) {
		return new WP_Error( 'papelito_invitation_registration_unavailable', 'Não foi possível criar uma conta para este convite.', array( 'status' => 409 ) );
	}

	$existing_id = (int) email_exists( $email );
	if ( $existing_id > 0 ) {
		return papelito_auth_invitation_register_resume( $existing_id );
	}

	$user = papelito_auth_create_invited_user( $data );
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	papelito_auth_mark_password_set( $user->ID );
	// O token pendente, validado acima contra e-mail e expiração, prova a posse da caixa convidada.
	papelito_auth_mark_email_verified( $user->ID );
	return new WP_REST_Response(
		array(
			'ok'                        => true,
			'accountExists'             => false,
			'requiresEmailVerification' => false,
			'requiresLogin'             => true,
			'email'                     => $user->user_email,
		),
		201
	);
}

/**
 * Retoma um cadastro por convite cujo e-mail ja tem conta, em vez de devolver um 409 sem saida.
 *
 * INVARIANTE DE SEGURANCA: nunca grava a senha enviada no payload. Portar um token de convite
 * nao autoriza sobrescrever a credencial de uma conta existente — isso seria tomada de conta.
 * Conta pendente recebe novo e-mail de verificacao; conta verificada e mandada para o login.
 *
 * @param int $user_id Conta ja existente para o e-mail convidado.
 * @return WP_REST_Response|WP_Error
 */
function papelito_auth_invitation_register_resume( int $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_user_lookup_failed', PAPELITO_AUTH_NEW_USER_LOOKUP_MESSAGE, array( 'status' => 500 ) );
	}

	if ( papelito_auth_requires_email_verification( $user_id ) ) {
		$dispatch = papelito_auth_maybe_resend_pending_verification( $user );

		return new WP_REST_Response(
			array(
				'ok'                        => true,
				'accountExists'             => true,
				'requiresEmailVerification' => true,
				'requiresLogin'             => false,
				'email'                     => $user->user_email,
				'emailSent'                 => ! is_wp_error( $dispatch ),
				'authMethods'               => papelito_auth_credential_methods( $user_id ),
			),
			200
		);
	}

	return new WP_REST_Response(
		array(
			'ok'                        => true,
			'accountExists'             => true,
			'requiresEmailVerification' => false,
			'requiresLogin'             => true,
			'email'                     => $user->user_email,
			'authMethods'               => papelito_auth_credential_methods( $user_id ),
		),
		200
	);
}

function papelito_auth_handle_verify_email( WP_REST_Request $request ) {
	$data  = papelito_auth_request_data( $request );
	$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
	$token = isset( $data['token'] ) ? trim( (string) $data['token'] ) : '';
	if ( '' === $email || '' === $token ) {
		return new WP_Error( 'papelito_invalid_verification_request', PAPELITO_AUTH_INVALID_VERIFICATION_MESSAGE, array( 'status' => 400 ) );
	}

	$user_id = email_exists( $email );
	$user    = $user_id ? get_userdata( $user_id ) : false;
	if ( ! $user instanceof WP_User || ! papelito_auth_requires_email_verification( $user->ID ) ) {
		return new WP_Error( 'papelito_invalid_verification_token', PAPELITO_AUTH_INVALID_VERIFICATION_MESSAGE, array( 'status' => 400 ) );
	}

	$stored_hash   = (string) get_user_meta( $user->ID, 'papelito_email_verification_token_hash', true );
	$stored_expiry = (string) get_user_meta( $user->ID, 'papelito_email_verification_token_expires_at', true );
	if ( '' === $stored_hash || '' === $stored_expiry ) {
		return new WP_Error( 'papelito_invalid_verification_token', PAPELITO_AUTH_INVALID_VERIFICATION_MESSAGE, array( 'status' => 400 ) );
	}

	$expiry_ts = strtotime( $stored_expiry );
	if ( false === $expiry_ts || $expiry_ts < time() ) {
		return new WP_Error( 'papelito_verification_token_expired', 'Link de confirmação expirado. Solicite um novo e-mail para continuar.', array( 'status' => 410 ) );
	}

	if ( ! hash_equals( $stored_hash, hash( 'sha256', $token ) ) ) {
		return new WP_Error( 'papelito_invalid_verification_token', PAPELITO_AUTH_INVALID_VERIFICATION_MESSAGE, array( 'status' => 400 ) );
	}

	papelito_auth_mark_email_verified( $user->ID );
	papelito_company_onboarding_mark_email_confirmed( $user->ID );
	$b2b_result = papelito_auth_complete_b2b_onboarding( $user->ID );
	if ( is_wp_error( $b2b_result ) && 'onboarding_required' !== $b2b_result->get_error_code() ) {
		return $b2b_result;
	}

	update_user_meta( $user->ID, 'papelito_profile_complete', '1' );
	return new WP_REST_Response( array( 'ok' => true, 'onboardingRequired' => is_wp_error( $b2b_result ), 'b2b' => papelito_company_context( $user->ID ) ), 200 );
}

function papelito_auth_handle_resend_verification( WP_REST_Request $request ) {
	$data  = papelito_auth_request_data( $request );
	$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
	if ( '' === $email ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
	if ( ! papelito_auth_rate_limit( 'resend_verification', 10, 60, strtolower( $email ) ) ) {
		return new WP_Error( 'papelito_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$user_id = email_exists( $email );
	$user    = $user_id ? get_userdata( $user_id ) : false;
	if ( ! $user instanceof WP_User || ! papelito_auth_requires_email_verification( $user->ID ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	$last_sent_at = (string) get_user_meta( $user->ID, 'papelito_email_verification_sent_at', true );
	$last_sent_ts = '' !== $last_sent_at ? strtotime( $last_sent_at ) : false;
	if ( false !== $last_sent_ts && ( time() - $last_sent_ts ) < MINUTE_IN_SECONDS ) {
		return new WP_Error( 'papelito_verification_cooldown', 'Aguarde 60 segundos antes de solicitar um novo e-mail.', array( 'status' => 429 ) );
	}

	$dispatch = papelito_auth_dispatch_verification_email( $user );
	return is_wp_error( $dispatch ) ? $dispatch : new WP_REST_Response( array( 'ok' => true ), 200 );
}

function papelito_auth_handle_forgot_password( WP_REST_Request $request ) {
	$data  = papelito_auth_request_data( $request );
	$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'papelito_invalid_email', 'Informe um e-mail válido.', array( 'status' => 422 ) );
	}
	if ( ! papelito_auth_rate_limit( 'forgot_password', 10, 60, strtolower( $email ) ) ) {
		return new WP_Error( 'papelito_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$user_id = email_exists( $email );
	$user    = $user_id ? get_userdata( $user_id ) : false;
	if ( ! $user instanceof WP_User ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	$dispatch = papelito_auth_requires_email_verification( $user->ID )
		? papelito_auth_maybe_resend_pending_verification( $user )
		: papelito_auth_dispatch_password_reset_email( $user );

	return is_wp_error( $dispatch ) ? $dispatch : new WP_REST_Response( array( 'ok' => true ), 200 );
}

function papelito_auth_handle_change_password( WP_REST_Request $request ) {
	$user = wp_get_current_user();
	if ( ! $user instanceof WP_User || $user->ID <= 0 ) {
		return new WP_Error( 'papelito_not_authenticated', 'Não autenticado.', array( 'status' => 401 ) );
	}

	$result = papelito_auth_change_password( $user, papelito_auth_request_data( $request ) );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'ok' => true ), 200 );
}

function papelito_auth_handle_reset_password( WP_REST_Request $request ) {
	$data             = papelito_auth_request_data( $request );
	$login            = isset( $data['login'] ) ? sanitize_text_field( trim( (string) $data['login'] ) ) : '';
	$key              = isset( $data['key'] ) ? trim( (string) $data['key'] ) : '';
	$password         = isset( $data['password'] ) ? (string) $data['password'] : '';
	$confirm_password = isset( $data['confirmPassword'] ) ? (string) $data['confirmPassword'] : '';
	if ( '' === $login || '' === $key ) {
		return new WP_Error( 'papelito_password_reset_invalid_request', 'Link de redefinicao inválido ou expirado.', array( 'status' => 400 ) );
	}
	if ( ! papelito_auth_rate_limit( 'reset_password', 20, 60, $login ) ) {
		return new WP_Error( 'papelito_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	if ( strlen( $password ) < 8 ) {
		return new WP_Error( 'papelito_password_too_short', 'A nova senha precisa ter pelo menos 8 caracteres.', array( 'status' => 422 ) );
	}

	if ( $password !== $confirm_password ) {
		return new WP_Error( 'papelito_password_mismatch', 'As senhas precisam coincidir.', array( 'status' => 422 ) );
	}

	$user = check_password_reset_key( $key, $login );
	if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
		return papelito_auth_map_password_reset_key_error( $user instanceof WP_Error ? $user : new WP_Error( 'invalid_key' ) );
	}

	reset_password( $user, $password );
	papelito_auth_mark_password_set( $user->ID );
	papelito_auth_invalidate_user_sessions( $user->ID );
	papelito_auth_mark_email_verified( $user->ID );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/me',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_auth_logged_in',
				'callback'            => 'papelito_auth_handle_current_user',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/welcome-toast/claim',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_auth_logged_in',
				'callback'            => 'papelito_auth_handle_welcome_toast_claim',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/google',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'id_token' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => 'papelito_auth_handle_google',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/register',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_auth_handle_register',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/register-invitation',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_auth_handle_invitation_register',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/verify-email',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_auth_handle_verify_email',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/resend-verification',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_auth_handle_resend_verification',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/forgot-password',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_auth_handle_forgot_password',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/change-password',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_auth_logged_in',
				'callback'            => 'papelito_auth_handle_change_password',
			)
		);

		register_rest_route(
			PAPELITO_AUTH_API_NAMESPACE,
			'/auth/reset-password',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_auth_handle_reset_password',
			)
		);
	}
);
