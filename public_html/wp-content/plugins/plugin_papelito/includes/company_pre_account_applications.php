<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS', 30 );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE', 'Não foi possível concluir esta candidatura.' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE', 'Candidatura não encontrada.' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE', 'Esta candidatura não está pendente.' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION', 'START TRANSACTION' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL', 'pending_email_verification' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_EMAIL_UNCONFIRMED_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_EMAIL_UNCONFIRMED_MESSAGE', 'Confirme seu e-mail para continuar a candidatura.' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_EMAIL_SEND_FAILED_MESSAGE' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_EMAIL_SEND_FAILED_MESSAGE', 'Não foi possível enviar o e-mail de confirmação. Tente novamente em alguns instantes.' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK', 'papelito_pre_account_application_purge_document' );
}

if ( ! defined( 'PAPELITO_PRE_ACCOUNT_SWEEP_HOOK' ) ) {
	define( 'PAPELITO_PRE_ACCOUNT_SWEEP_HOOK', 'papelito_pre_account_applications_sweep' );
}

class PapelitoPreAccountTransactionException extends RuntimeException {}

function papelito_pre_account_application_external_id( int $application_id ): string {
	return 'pre:' . $application_id;
}

function papelito_pre_account_application_new_token(): string {
	return bin2hex( random_bytes( 32 ) );
}

function papelito_pre_account_application_token_hash( string $token ): string {
	return hash( 'sha256', $token );
}

function papelito_pre_account_application_expires_at(): string {
	return gmdate( 'Y-m-d H:i:s', time() + ( PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS * DAY_IN_SECONDS ) );
}

function papelito_pre_account_application_get( int $application_id ): ?array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE id = %d", $application_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
}

function papelito_pre_account_application_by_token( string $token ): ?array {
	if ( '' === $token ) {
		return null;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE resume_token_hash = %s", papelito_pre_account_application_token_hash( $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
}

/**
 * Autoriza uma candidatura pelo id interno, sem passar pelo `resume_token`.
 *
 * Existe para o tiquete de upload direto: guardar o token cru num transient deixaria o segredo em
 * claro em `wp_options`. O id sozinho nao autoriza nada — quem o obtem ja passou pelo token uma vez,
 * e a validade da candidatura e revalidada aqui do mesmo jeito.
 *
 * @param int $application_id Id interno da candidatura.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pre_account_application_authorize_by_id( int $application_id ): array|WP_Error {
	global $wpdb;
	$tables      = papelito_company_table_names();
	$application = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE id = %d", $application_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return papelito_pre_account_application_assert_open( is_array( $application ) ? $application : null );
}

function papelito_pre_account_application_authorize( string $token ): array|WP_Error {
	return papelito_pre_account_application_assert_open( papelito_pre_account_application_by_token( $token ) );
}

/**
 * Guarda comum de validade da candidatura.
 *
 * @param array<string,mixed>|null $application Linha carregada, ou null.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pre_account_application_assert_open( ?array $application ): array|WP_Error {
	if ( ! $application || empty( $application['resume_token_expires_at'] ) || strtotime( (string) $application['resume_token_expires_at'] ) < time() ) {
		return new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}
	return $application;
}

function papelito_pre_account_application_view( array $application ): array {
	return array(
		'applicationId' => papelito_pre_account_application_external_id( (int) $application['id'] ),
		'status'        => (string) $application['application_status'],
		'reviewPath'    => $application['review_path'] ?? null,
		'canUpload'     => 'document_required' === (string) $application['application_status'] && papelito_pre_account_application_email_is_verified( $application ),
		'emailVerified' => papelito_pre_account_application_email_is_verified( $application ),
		'expiresAt'     => $application['expires_at'] ?? null,
	);
}

function papelito_pre_account_application_admin_recipients(): array {
	$recipients = array();
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id > 0 && user_can( $user_id, 'papelito_manage_companies' ) ) {
			$recipients[] = $user_id;
		}
	}

	return $recipients;
}

function papelito_pre_account_application_notification_exists( int $recipient_id, int $application_id ): bool {
	if ( ! function_exists( 'papelito_notifications_table_name' ) ) {
		return false;
	}

	global $wpdb;
	$notification_id = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM ' . papelito_notifications_table_name() . ' WHERE user_id = %d AND type = %s AND dedupe_key = %s LIMIT 1',
			$recipient_id,
			PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING,
			'pre-account-application:' . $application_id
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	return (int) $notification_id > 0;
}

function papelito_pre_account_application_notify_pending( array $application ): bool {
	if ( ! function_exists( 'papelito_dispatch_notification' ) || ! defined( 'PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING' ) ) {
		return false;
	}

	$application_id = (int) $application['id'];
	$company_name   = papelito_pii_decrypt( (string) ( $application['legal_name_ciphertext'] ?? '' ) );
	$payload        = array(
		'applicationId' => papelito_pre_account_application_external_id( $application_id ),
		'source'        => 'pre_account',
		'companyName'   => is_string( $company_name ) && '' !== $company_name ? $company_name : 'Cadastro empresarial',
		'href'          => '/admin/users?preAccountApplication=' . rawurlencode( papelito_pre_account_application_external_id( $application_id ) ),
	);

	$recipients = papelito_pre_account_application_admin_recipients();
	if ( empty( $recipients ) ) {
		return false;
	}

	foreach ( $recipients as $recipient_id ) {
		if ( false === papelito_dispatch_notification( $recipient_id, PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING, $payload, 'pre-account-application:' . $application_id ) && ! papelito_pre_account_application_notification_exists( $recipient_id, $application_id ) ) {
			return false;
		}
	}

	return true;
}

function papelito_pre_account_application_backfill_pending_notifications(): int {
	global $wpdb;
	$tables = papelito_company_table_names();
	$applications = $wpdb->get_results(
		"SELECT id, legal_name_ciphertext FROM {$tables['pre_account_applications']} WHERE application_status = 'pending_manual_review' AND is_open = 1",
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$backfilled = 0;
	foreach ( is_array( $applications ) ? $applications : array() as $application ) {
		$backfilled += papelito_pre_account_application_notify_pending( $application ) ? 1 : 0;
	}

	return $backfilled;
}

/**
 * Carimba como confirmadas as candidaturas anteriores ao gate de e-mail.
 *
 * O fluxo antigo nunca pediu confirmacao, entao sem este carimbo quem candidatou antes
 * da migracao ficaria com o upload travado por um passo que nao existia. A option trava
 * a execucao unica: a lista de migracoes roda inteira a cada bump de schema, e um bump
 * futuro nao pode carimbar candidatura nascida ja sob o gate.
 */
function papelito_pre_account_application_backfill_email_verification(): void {
	if ( '1' === get_option( 'papelito_pre_account_email_verification_backfill_v1', '0' ) ) {
		return;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->query( "UPDATE {$tables['pre_account_applications']} SET email_verified_at = created_at WHERE email_verified_at IS NULL" ); // phpcs:ignore WordPress.DB

	update_option( 'papelito_pre_account_email_verification_backfill_v1', '1', false );
}

/**
 * Localiza a candidatura pelo token de confirmacao de e-mail.
 *
 * @param string $token Token em claro, como veio do link.
 * @return array<string,mixed>|null
 */
function papelito_pre_account_application_by_email_token( string $token ): ?array {
	if ( '' === $token ) {
		return null;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE email_verification_token_hash = %s", papelito_pre_account_application_token_hash( $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
}

/**
 * Diz se a candidatura ja provou posse da caixa de e-mail.
 *
 * E esta resposta — nao o `application_status` — que libera o upload do documento.
 *
 * @param array<string,mixed> $application Candidatura persistida.
 */
function papelito_pre_account_application_email_is_verified( array $application ): bool {
	return ! empty( $application['email_verified_at'] );
}

/**
 * Gera o token de confirmacao e guarda apenas o hash na linha da candidatura.
 *
 * Espelha `papelito_auth_prepare_email_verification_token()`, mas mora na candidatura
 * porque aqui ainda nao existe `wp_user` onde pendurar usermeta.
 *
 * @param int $application_id Id interno da candidatura.
 * @return string|WP_Error Token em claro, que so pode sair daqui dentro do e-mail.
 */
function papelito_pre_account_application_prepare_email_token( int $application_id ): string|WP_Error {
	$token = papelito_pre_account_application_new_token();

	global $wpdb;
	$tables  = papelito_company_table_names();
	$updated = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'email_verification_token_hash'       => papelito_pre_account_application_token_hash( $token ),
			'email_verification_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'updated_at'                          => current_time( 'mysql', true ),
		),
		array( 'id' => $application_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $updated ) {
		return new WP_Error( 'papelito_pre_account_email_token_failed', PAPELITO_PRE_ACCOUNT_EMAIL_SEND_FAILED_MESSAGE, array( 'status' => 500 ) );
	}

	return $token;
}

/**
 * Monta e envia o e-mail de confirmacao da candidatura.
 *
 * O link leva e-mail e token no fragmento, como o da conta: GTM, GA4 e access log
 * registram a query, nunca o que vem depois do `#`. O `scope` diz ao frontend que o
 * token e de candidatura, e nao de uma conta ja existente.
 *
 * @param array<string,mixed> $application Candidatura persistida.
 * @param string              $token       Token em claro.
 */
function papelito_pre_account_application_send_verification_email( array $application, string $token ): bool {
	$email = papelito_pii_decrypt( (string) ( $application['contact_email_ciphertext'] ?? '' ) );
	$email = is_string( $email ) ? sanitize_email( $email ) : '';
	if ( '' === $email ) {
		return false;
	}

	$link = papelito_frontend_link( sprintf( 'confirmar-email#scope=candidatura&email=%s&token=%s', rawurlencode( $email ), rawurlencode( $token ) ) );
	if ( is_wp_error( $link ) ) {
		return false;
	}

	$name     = papelito_pii_decrypt( (string) ( $application['full_name_ciphertext'] ?? '' ) );
	$parts    = preg_split( '/\s+/', trim( is_string( $name ) ? $name : '' ), 2 );
	$greeting = is_array( $parts ) && '' !== (string) ( $parts[0] ?? '' ) ? (string) $parts[0] : $email;
	$view     = array(
		'kicker'       => 'Confirmação de e-mail',
		'headline'     => 'Confirme seu e-mail.',
		'lead'         => sprintf(
			'Olá %s, recebemos a sua candidatura empresarial na Papelito. Confirme seu e-mail para que ela siga para análise.',
			$greeting
		),
		'cta'          => array(
			'label' => 'Confirmar e-mail',
			'url'   => (string) $link,
		),
		'notes'        => array( 'Este link expira em 24 horas.' ),
		'footer_lines' => array( 'Se você não fez essa candidatura, ignore esta mensagem.' ),
	);

	return papelito_email_send(
		$email,
		'Confirme seu e-mail - Papelito',
		papelito_email_notice_html( $view ),
		papelito_email_notice_text( $view )
	);
}

/**
 * Rotaciona o token e dispara o e-mail de confirmacao da candidatura.
 *
 * Melhor esforco de proposito: falha de envio nao desfaz a candidatura, porque o
 * `resume_token` ja voltou na resposta e o reenvio cobre o caso.
 *
 * @param array<string,mixed> $application Candidatura persistida.
 */
function papelito_pre_account_application_dispatch_email_verification( array $application ): bool {
	$application_id = (int) $application['id'];
	$token          = papelito_pre_account_application_prepare_email_token( $application_id );
	if ( is_wp_error( $token ) || ! papelito_pre_account_application_send_verification_email( $application, $token ) ) {
		return false;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->update( $tables['pre_account_applications'], array( 'email_verification_sent_at' => current_time( 'mysql', true ) ), array( 'id' => $application_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return true;
}

/**
 * Estado para o qual a candidatura anda depois de confirmado o e-mail.
 *
 * @param array<string,mixed> $application Candidatura persistida.
 */
function papelito_pre_account_application_status_after_email( array $application ): string {
	return 'document_required' === (string) ( $application['review_path'] ?? '' ) ? 'document_required' : 'pending_manual_review';
}

/**
 * Consome o token de confirmacao e empurra a candidatura para a etapa seguinte.
 *
 * E esta funcao, e nao a criacao, que notifica o administrador: candidatura sem posse
 * de caixa comprovada nunca chega a fila de analise. A notificacao e melhor esforco —
 * falhar nela nao pode invalidar um link que a pessoa clicou corretamente, e
 * `papelito_pre_account_application_backfill_pending_notifications()` e a rede de
 * seguranca que reenvia o que ficou para tras.
 *
 * Devolve um `resume_token` novo junto com a visao: quem confirma pode estar em outro
 * aparelho, sem o cookie da candidatura, e sem ele a etapa 3 diria "nenhuma candidatura
 * encontrada". O link de e-mail ja e prova de posse, e o token antigo deixa de valer.
 *
 * @param string $token Token em claro, como veio do link.
 * @return array<string,mixed>|WP_Error Visao publica da candidatura mais o `resume_token`.
 */
function papelito_pre_account_application_confirm_email( string $token ): array|WP_Error {
	$application = papelito_pre_account_application_assert_open( papelito_pre_account_application_by_email_token( $token ) );
	if ( is_wp_error( $application ) ) {
		return $application;
	}

	if ( papelito_pre_account_application_email_is_verified( $application ) ) {
		return new WP_Error( 'papelito_pre_account_email_already_confirmed', 'Este e-mail já foi confirmado.', array( 'status' => 409 ) );
	}

	$expires_at = (string) ( $application['email_verification_token_expires_at'] ?? '' );
	if ( '' === $expires_at || strtotime( $expires_at ) < time() ) {
		return new WP_Error( 'papelito_pre_account_email_token_expired', 'Link de confirmação expirado. Solicite um novo e-mail para continuar.', array( 'status' => 410 ) );
	}

	$application_id = (int) $application['id'];
	$now            = current_time( 'mysql', true );
	$next_status    = papelito_pre_account_application_status_after_email( $application );
	$resume_token   = papelito_pre_account_application_new_token();

	global $wpdb;
	$tables  = papelito_company_table_names();
	$updated = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'application_status'                  => $next_status,
			'email_verified_at'                   => $now,
			'email_verification_token_hash'       => null,
			'email_verification_token_expires_at' => null,
			'resume_token_hash'                   => papelito_pre_account_application_token_hash( $resume_token ),
			'resume_token_expires_at'             => papelito_pre_account_application_expires_at(),
			'updated_at'                          => $now,
		),
		array(
			'id'                 => $application_id,
			'application_status' => PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL,
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( ! $updated ) {
		return new WP_Error( 'papelito_pre_account_email_confirm_conflict', PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE, array( 'status' => 409 ) );
	}

	$application['application_status'] = $next_status;
	$application['email_verified_at']  = $now;

	if ( 'pending_manual_review' === $next_status ) {
		papelito_pre_account_application_notify_pending( $application );
	}

	return array(
		'application'  => papelito_pre_account_application_view( $application ),
		'resume_token' => $resume_token,
	);
}

/**
 * Reenvia o link de confirmacao respeitando o mesmo intervalo de um minuto da conta.
 *
 * Devolve `true` tambem quando nada foi enviado (ja confirmada ou dentro do intervalo):
 * a resposta ao candidato e deliberadamente indistinguivel, para nao virar sonda.
 *
 * @param array<string,mixed> $application Candidatura persistida.
 * @return true|WP_Error
 */
function papelito_pre_account_application_resend_email_verification( array $application ): true|WP_Error {
	if ( papelito_pre_account_application_email_is_verified( $application ) ) {
		return true;
	}

	$last_sent_at = (string) ( $application['email_verification_sent_at'] ?? '' );
	$last_sent_ts = '' !== $last_sent_at ? strtotime( $last_sent_at ) : false;
	if ( false !== $last_sent_ts && ( time() - $last_sent_ts ) < MINUTE_IN_SECONDS ) {
		return true;
	}

	if ( ! papelito_pre_account_application_dispatch_email_verification( $application ) ) {
		return new WP_Error( 'papelito_pre_account_email_send_failed', PAPELITO_PRE_ACCOUNT_EMAIL_SEND_FAILED_MESSAGE, array( 'status' => 500 ) );
	}

	return true;
}

/**
 * Localiza a candidatura aberta de um e-mail.
 *
 * Nao autoriza nada sozinha: quem chama ou ja provou posse por outro meio (token de retomada,
 * senha) ou responde de forma neutra, para a busca nao virar sonda de cadastro.
 *
 * @param string $email E-mail informado.
 * @return array<string,mixed>|null
 */
function papelito_pre_account_application_find_open_by_email( string $email ): ?array {
	$email = sanitize_email( $email );
	if ( '' === $email ) {
		return null;
	}

	$hmac = papelito_pii_hmac( strtolower( trim( $email ) ) );
	if ( is_wp_error( $hmac ) ) {
		return null;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE contact_email_hmac = %s AND is_open = 1 ORDER BY id DESC LIMIT 1", $hmac ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
}

/**
 * Retoma a candidatura aberta a partir do par e-mail + senha escolhido na etapa 2.
 *
 * Existe para quem tenta entrar pelo login antes de a conta existir. So quem acerta a
 * senha recebe resposta diferente, entao nao abre enumeracao: senha errada e
 * candidatura inexistente devolvem exatamente o mesmo erro.
 *
 * @param string $email    E-mail informado no login.
 * @param string $password Senha em claro informada no login.
 * @return array<string,mixed>|WP_Error Visao publica mais o `resume_token` novo.
 */
function papelito_pre_account_application_resume_by_credentials( string $email, string $password ): array|WP_Error {
	$generic = new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	$email   = sanitize_email( $email );
	if ( '' === $email || '' === $password ) {
		return $generic;
	}

	$application = papelito_pre_account_application_find_open_by_email( $email );
	if ( ! is_array( $application ) || empty( $application['password_hash'] ) ) {
		return $generic;
	}

	global $wpdb;
	$tables = papelito_company_table_names();

	if ( ! wp_check_password( $password, (string) $application['password_hash'] ) ) {
		return $generic;
	}

	$authorized = papelito_pre_account_application_assert_open( $application );
	if ( is_wp_error( $authorized ) ) {
		return $generic;
	}

	$token   = papelito_pre_account_application_new_token();
	$updated = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'resume_token_hash'       => papelito_pre_account_application_token_hash( $token ),
			'resume_token_expires_at' => papelito_pre_account_application_expires_at(),
			'updated_at'              => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $application['id'] )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $updated ) {
		return $generic;
	}

	return array(
		'application'  => papelito_pre_account_application_view( $application ),
		'resume_token' => $token,
	);
}

function papelito_pre_account_application_identity( array $input ): array|WP_Error {
	$identity = array(
		'email'    => sanitize_email( (string) ( $input['email'] ?? '' ) ),
		// Normalizado antes de validar E de persistir: e este valor que segue para o cruzamento de
		// QSA e para o e-mail do candidato, entao nao pode guardar NBSP nem espaco duplicado.
		'name'     => papelito_normalize_unicode_spaces( sanitize_text_field( (string) ( $input['full_name'] ?? '' ) ) ),
		'phone'    => papelito_normalize_unicode_spaces( sanitize_text_field( (string) ( $input['phone'] ?? '' ) ) ),
		'cpf'      => papelito_normalize_cpf( (string) ( $input['cpf'] ?? '' ) ),
		'birth'    => sanitize_text_field( (string) ( $input['birth_date'] ?? '' ) ),
		'cnpj'     => papelito_normalize_cnpj( (string) ( $input['cnpj'] ?? '' ) ),
		'password' => (string) ( $input['password'] ?? '' ),
	);
	$errors = array();
	if ( ! is_email( $identity['email'] ) ) {
		$errors['email'] = array( 'Informe um e-mail válido.' );
	}
	$name_error = papelito_full_name_validation_error( $identity['name'] );
	if ( $name_error ) {
		$errors['full_name'] = array( $name_error );
	}
	$phone_error = papelito_phone_validation_error( $identity['phone'] );
	if ( $phone_error ) {
		$errors['phone'] = array( $phone_error );
	}
	if ( ! papelito_validate_cpf( $identity['cpf'] ) ) {
		$errors['cpf'] = array( 'Informe um CPF válido.' );
	}
	$birth_date_error = papelito_company_birth_date_validation_error( $identity['birth'] );
	if ( $birth_date_error ) {
		$errors['birth_date'] = array( $birth_date_error->get_error_message() );
	}
	if ( ! papelito_validate_cnpj( $identity['cnpj'] ) ) {
		$errors['cnpj'] = array( 'Informe um CNPJ válido.' );
	}
	if ( strlen( $identity['password'] ) < 8 ) {
		$errors['password'] = array( 'A senha precisa ter pelo menos 8 caracteres.' );
	}

	if ( empty( $errors ) ) {
		return $identity;
	}

	return new WP_Error(
		'papelito_pre_account_invalid_input',
		'Revise os dados informados.',
		array(
			'status' => 422,
			'errors' => $errors,
		)
	);
}

function papelito_pre_account_application_address( array $input ): array|WP_Error {
	$address = array(
		'cep'          => preg_replace( '/\\D+/', '', (string) ( $input['cep'] ?? '' ) ) ?? '',
		'street'       => sanitize_text_field( (string) ( $input['street'] ?? '' ) ),
		'number'       => sanitize_text_field( (string) ( $input['number'] ?? '' ) ),
		'complement'   => sanitize_text_field( (string) ( $input['complement'] ?? '' ) ),
		'neighborhood' => sanitize_text_field( (string) ( $input['neighborhood'] ?? '' ) ),
		'city'         => sanitize_text_field( (string) ( $input['city'] ?? '' ) ),
		'state'        => strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ),
	);
	$is_valid = papelito_validate_cep_format( $address['cep'] )
		&& '' !== $address['street']
		&& '' !== $address['number']
		&& '' !== $address['neighborhood']
		&& '' !== $address['city']
		&& array_key_exists( $address['state'], papelito_brazilian_states() );

	return $is_valid ? $address : new WP_Error( 'papelito_pre_account_invalid_address', 'Endereço inválido.', array( 'status' => 422 ) );
}

function papelito_pre_account_application_seal( array $identity, array $address, string $legal_name ): array|WP_Error {
	$sealed = array(
		'email_hmac' => papelito_pii_hmac( strtolower( $identity['email'] ) ),
		'cpf_hmac'   => papelito_cpf_hmac( $identity['cpf'] ),
	);
	$plaintext = array(
		'email'      => $identity['email'],
		'name'       => $identity['name'],
		'phone'      => $identity['phone'],
		'cpf'        => $identity['cpf'],
		'birth'      => $identity['birth'],
		'address'    => wp_json_encode( $address ),
		'legal_name' => $legal_name,
	);
	foreach ( $plaintext as $key => $value ) {
		$sealed[ $key ] = papelito_pii_encrypt( $value );
		if ( is_wp_error( $sealed[ $key ] ) ) {
			return $sealed[ $key ];
		}
	}
	if ( is_wp_error( $sealed['email_hmac'] ) ) {
		return $sealed['email_hmac'];
	}

	return is_wp_error( $sealed['cpf_hmac'] ) ? $sealed['cpf_hmac'] : $sealed;
}

function papelito_pre_account_application_prepare( array $input ): array|WP_Error {
	$identity = papelito_pre_account_application_identity( $input );
	if ( is_wp_error( $identity ) ) {
		return $identity;
	}
	// Mensagem deliberadamente neutra e idêntica nos três casos: distinguir "CNPJ já cadastrado"
	// de "e-mail já cadastrado" permitiria enumerar empresas e usuários.
	if ( papelito_company_find_by_cnpj( $identity['cnpj'] ) || email_exists( $identity['email'] ) || username_exists( $identity['email'] ) ) {
		return new WP_Error( 'papelito_pre_account_unavailable', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 409 ) );
	}
	$address = papelito_pre_account_application_address( $input );
	if ( is_wp_error( $address ) ) {
		return $address;
	}
	$validated = papelito_company_validate_owner_registry( $identity['cpf'], $identity['birth'], $identity['cnpj'], $identity['name'] );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}
	$sealed = papelito_pre_account_application_seal( $identity, $address, (string) ( $validated['lookup']['legal_name'] ?? '' ) );

	return is_wp_error( $sealed ) ? $sealed : array(
		'identity'  => $identity,
		'validated' => $validated,
		'sealed'    => $sealed,
	);
}

function papelito_pre_account_application_persist( array $prepared ): array|WP_Error {
	$identity   = $prepared['identity'];
	$validated  = $prepared['validated'];
	$sealed     = $prepared['sealed'];
	$token      = papelito_pre_account_application_new_token();
	$now        = current_time( 'mysql', true );
	$expires_at = papelito_pre_account_application_expires_at();
	$path       = (string) $validated['review_path'];

	global $wpdb;
	$tables   = papelito_company_table_names();
	$wpdb->query( PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$inserted = $wpdb->insert(
		$tables['pre_account_applications'],
		array(
			'contact_email_hmac'       => $sealed['email_hmac'],
			'contact_email_ciphertext' => $sealed['email'],
			'full_name_ciphertext'     => $sealed['name'],
			'phone_ciphertext'         => $sealed['phone'],
			'cpf_hmac'                 => $sealed['cpf_hmac'],
			'cpf_ciphertext'           => $sealed['cpf'],
			'birth_date_ciphertext'    => $sealed['birth'],
			'address_ciphertext'       => $sealed['address'],
			'password_hash'            => wp_hash_password( $identity['password'] ),
			'canonical_cnpj'           => $identity['cnpj'],
			'legal_name_ciphertext'    => $sealed['legal_name'],
			'review_path'              => $path,
			'application_status'       => PAPELITO_PRE_ACCOUNT_STATUS_PENDING_EMAIL,
			'is_open'                  => 1,
			'resume_token_hash'        => papelito_pre_account_application_token_hash( $token ),
			'resume_token_expires_at'  => $expires_at,
			'evidence_json'            => wp_json_encode( papelito_company_owner_application_safe_evidence( $validated['evidence'] ) ),
			'provider_source'          => sanitize_key( (string) ( $validated['lookup']['source'] ?? '' ) ),
			'provider_checked_at'      => $now,
			'provider_data_hash'       => (string) ( $validated['evidence']['hash'] ?? '' ),
			'expires_at'               => $expires_at,
			'created_at'               => $now,
			'updated_at'               => $now,
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $inserted ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_pre_account_unavailable', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 409 ) );
	}
	$application = papelito_pre_account_application_get( (int) $wpdb->insert_id );
	if ( ! $application ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'papelito_pre_account_persist_failed', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 500 ) );
	}
	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	papelito_pre_account_application_dispatch_email_verification( $application );

	return array(
		'application'  => papelito_pre_account_application_view( $application ),
		'resume_token' => $token,
	);
}

function papelito_pre_account_application_create( array $input ): array|WP_Error {
	$prepared = papelito_pre_account_application_prepare( $input );

	return is_wp_error( $prepared ) ? $prepared : papelito_pre_account_application_persist( $prepared );
}

function papelito_pre_account_application_upload( string $token, array $file ): array|WP_Error {
	return papelito_pre_account_application_upload_authorized( papelito_pre_account_application_authorize( $token ), $file );
}

/**
 * Recebe o documento de uma candidatura ja autorizada.
 *
 * Separado de `papelito_pre_account_application_upload()` para o upload direto poder autorizar pelo
 * id da candidatura, sem precisar do `resume_token` em claro.
 *
 * @param array<string,mixed>|WP_Error $application Candidatura autorizada.
 * @param array<string,mixed>          $file        Arquivo recebido.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pre_account_application_upload_authorized( array|WP_Error $application, array $file ): array|WP_Error {
	if ( is_wp_error( $application ) ) {
		return $application;
	}
	if ( ! papelito_pre_account_application_email_is_verified( $application ) ) {
		return new WP_Error( 'papelito_pre_account_email_unconfirmed', PAPELITO_PRE_ACCOUNT_EMAIL_UNCONFIRMED_MESSAGE, array( 'status' => 409 ) );
	}
	if ( 'document_required' !== (string) $application['application_status'] || empty( $application['is_open'] ) || ! empty( $application['document_storage_key'] ) ) {
		return new WP_Error( 'papelito_pre_account_upload_not_allowed', 'Esta candidatura não aceita um novo documento.', array( 'status' => 409 ) );
	}
	$validated = papelito_company_document_validate_upload( $file );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}
	$stored = papelito_company_document_store( $file, $validated );
	if ( is_wp_error( $stored ) ) {
		return $stored;
	}
	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->query( PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	try {
		$locked = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['pre_account_applications']} WHERE id = %d FOR UPDATE", (int) $application['id'] ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $locked ) || 'document_required' !== (string) $locked['application_status'] || empty( $locked['is_open'] ) || ! empty( $locked['document_storage_key'] ) || ! papelito_pre_account_application_email_is_verified( $locked ) ) {
			throw new DomainException( 'application_not_uploadable' );
		}

		$now     = current_time( 'mysql', true );
		$updated = $wpdb->update( $tables['pre_account_applications'], array( 'application_status' => 'pending_manual_review', 'document_storage_key' => $stored['key'], 'document_original_name' => $validated['original_name'], 'document_mime' => $validated['mime'], 'document_size' => $validated['size'], 'document_sha256' => $validated['sha256'], 'document_uploaded_at' => $now, 'updated_at' => $now ), array( 'id' => (int) $locked['id'], 'application_status' => 'document_required' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $updated ) {
			throw new PapelitoPreAccountTransactionException( 'application_update_failed' );
		}
		$locked['id']                 = (int) $locked['id'];
		$locked['application_status'] = 'pending_manual_review';
		if ( ! papelito_pre_account_application_notify_pending( $locked ) ) {
			throw new PapelitoPreAccountTransactionException( 'notification_failed' );
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} catch ( DomainException $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_company_document_discard_path( $stored['path'] );
		return new WP_Error( 'papelito_pre_account_upload_conflict', 'A candidatura foi atualizada. Atualize a página.', array( 'status' => 409 ) );
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		papelito_company_document_discard_path( $stored['path'] );
		return new WP_Error( 'papelito_pre_account_upload_failed', 'Não foi possível encaminhar o documento para análise. Tente novamente.', array( 'status' => 500 ) );
	}
	$updated_application = papelito_pre_account_application_get( (int) $application['id'] );
	return $updated_application ? papelito_pre_account_application_view( $updated_application ) : new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
}

/**
 * Apaga o arquivo privado de uma candidatura já decidida ou expirada.
 *
 * Espelha papelito_company_owner_application_purge_document(): em falha de unlink reagenda,
 * porque deixar o binário em disco é justamente o que a retenção precisa evitar.
 */
function papelito_pre_account_application_purge_document( int $application_id ): bool {
	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application || ! in_array( (string) $application['application_status'], array( 'approved', 'rejected', 'expired' ), true ) ) {
		return false;
	}

	$key = (string) ( $application['document_storage_key'] ?? '' );
	if ( '' === $key ) {
		return true;
	}

	$deleted = false;
	if ( papelito_company_document_key_is_valid( $key ) ) {
		$directory = papelito_company_documents_prepare_dir();
		if ( ! is_wp_error( $directory ) ) {
			$path    = trailingslashit( $directory ) . $key;
			$deleted = ! is_file( $path ) || unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	if ( ! $deleted ) {
		if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK, array( $application_id ) ) ) {
			wp_schedule_single_event( time() + ( 5 * MINUTE_IN_SECONDS ), PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK, array( $application_id ) );
		}

		return false;
	}

	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->update(
		$tables['pre_account_applications'],
		array(
			'document_storage_key'   => null,
			'document_original_name' => null,
			'document_sha256'        => null,
			'document_deleted_at'    => current_time( 'mysql', true ),
			'updated_at'             => current_time( 'mysql', true ),
		),
		array( 'id' => $application_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return true;
}
add_action( PAPELITO_PRE_ACCOUNT_DOCUMENT_PURGE_HOOK, 'papelito_pre_account_application_purge_document', 10, 1 );

/**
 * Zera os dados pessoais de uma candidatura vencida, preservando o rastro auditável.
 *
 * Sobra o que não é reversível nem identificável isoladamente (hashes, CNPJ, decisão,
 * IDs criados). As colunas cifradas são NOT NULL, então recebem string vazia, não NULL;
 * `password_hash = NULL` é o sentinela de "já purgada".
 */
function papelito_pre_account_application_purge_pii( int $application_id ): bool {
	papelito_pre_account_application_purge_document( $application_id );

	global $wpdb;
	$tables  = papelito_company_table_names();
	$updated = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'contact_email_ciphertext' => '',
			'full_name_ciphertext'     => '',
			'phone_ciphertext'         => '',
			'cpf_ciphertext'           => '',
			'birth_date_ciphertext'    => '',
			'address_ciphertext'       => '',
			'legal_name_ciphertext'    => null,
			'password_hash'            => null,
			'resume_token_hash'        => '',
			'evidence_json'            => null,
			'updated_at'               => current_time( 'mysql', true ),
		),
		array( 'id' => $application_id )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return 1 === $updated;
}

/**
 * Fecha candidaturas abertas vencidas e purga os dados pessoais das que passaram do TTL.
 *
 * @return array{expired:int,purged:int}
 */
function papelito_pre_account_applications_sweep(): array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$now    = current_time( 'mysql', true );

	$expired = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$tables['pre_account_applications']} SET application_status = %s, is_open = NULL, updated_at = %s WHERE is_open = 1 AND expires_at < %s",
			'expired',
			$now,
			$now
		)
	);

	$due = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id FROM {$tables['pre_account_applications']} WHERE password_hash IS NOT NULL AND expires_at < %s LIMIT 200",
			$now
		)
	);

	$purged = 0;
	foreach ( is_array( $due ) ? $due : array() as $id ) {
		$purged += papelito_pre_account_application_purge_pii( (int) $id ) ? 1 : 0;
	}

	return array(
		'expired' => $expired,
		'purged'  => $purged,
	);
}
add_action( PAPELITO_PRE_ACCOUNT_SWEEP_HOOK, 'papelito_pre_account_applications_sweep' );

if ( function_exists( 'add_action' ) ) {
	add_action( 'init', static function (): void {
		if ( ! wp_next_scheduled( PAPELITO_PRE_ACCOUNT_SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', PAPELITO_PRE_ACCOUNT_SWEEP_HOOK );
		}
	} );
}

function papelito_pre_account_application_admin_list( string $status = 'pending_manual_review' ): array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$allowed_statuses = array( 'document_required', 'pending_manual_review', 'approved', 'rejected' );
	if ( ! in_array( $status, $allowed_statuses, true ) ) {
		$status = 'pending_manual_review';
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, contact_email_ciphertext, full_name_ciphertext, legal_name_ciphertext, canonical_cnpj, application_status, review_path, document_uploaded_at, created_at FROM {$tables['pre_account_applications']} WHERE application_status = %s ORDER BY COALESCE(document_uploaded_at, created_at) ASC", $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array_map( static function ( array $row ): array {
		return array( 'applicationId' => papelito_pre_account_application_external_id( (int) $row['id'] ), 'email' => papelito_pii_decrypt( (string) $row['contact_email_ciphertext'] ) ?: null, 'fullName' => papelito_pii_decrypt( (string) $row['full_name_ciphertext'] ) ?: null, 'companyName' => papelito_pii_decrypt( (string) $row['legal_name_ciphertext'] ) ?: null, 'cnpj' => (string) $row['canonical_cnpj'], 'status' => (string) $row['application_status'], 'reviewPath' => $row['review_path'] ?? null, 'submittedAt' => $row['document_uploaded_at'] ?? null, 'createdAt' => (string) $row['created_at'] );
	}, is_array( $rows ) ? $rows : array() );
}

/**
 * Bloco de pessoa da tela administrativa, tolerante a falha de decifragem.
 *
 * @param array<string,string>|WP_Error $values Valores decifrados da candidatura.
 * @return array<string,mixed>
 */
function papelito_pre_account_application_admin_person( array|WP_Error $values ): array {
	if ( is_wp_error( $values ) ) {
		return array( 'userId' => null, 'fullName' => null, 'email' => null, 'cpf' => null, 'birthDate' => null, 'phone' => null );
	}

	return array(
		'userId'    => null,
		'fullName'  => $values['name'],
		'email'     => $values['email'],
		'cpf'       => $values['cpf'],
		'birthDate' => $values['birth'],
		'phone'     => $values['phone'],
	);
}

/**
 * Ciclo de vida do arquivo privado da candidatura, no mesmo vocabulário das candidaturas de titular.
 *
 * A tabela de pré-conta não tem coluna `document_purge_status`, então o estado é derivado. O valor
 * `not_applicable` é o que distingue "nunca houve documento" (revisão pelo QSA) de "o arquivo foi
 * eliminado após a decisão" — a tela admin decide a mensagem por ele, não pela ausência do arquivo.
 *
 * @param array<string,mixed> $application Candidatura persistida.
 */
function papelito_pre_account_application_document_purge_status( array $application ): string {
	if ( ! empty( $application['document_storage_key'] ) ) {
		return 'retained';
	}

	if ( ! empty( $application['document_deleted_at'] ) || ! empty( $application['document_uploaded_at'] ) ) {
		return 'deleted';
	}

	return 'not_applicable';
}

function papelito_pre_account_application_admin_detail( int $application_id ): array|WP_Error {
	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application ) {
		return new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$values  = papelito_pre_account_application_decrypt_values( $application );
	$address = is_wp_error( $values ) ? array() : json_decode( (string) $values['address'], true );
	$evidence = json_decode( (string) ( $application['evidence_json'] ?? '' ), true );
	$legal_name = papelito_pii_decrypt( (string) ( $application['legal_name_ciphertext'] ?? '' ) );

	return array(
		'application' => array(
			'applicationId'      => papelito_pre_account_application_external_id( $application_id ),
			'companyId'          => null,
			'attemptNumber'      => 1,
			'status'             => (string) $application['application_status'],
			'fileName'           => in_array( (string) $application['application_status'], array( 'document_required', 'pending_manual_review' ), true ) ? ( $application['document_original_name'] ?? null ) : null,
			'submittedAt'        => $application['document_uploaded_at'] ?? null,
			'decidedAt'          => $application['decided_at'] ?? null,
			'canUpload'          => 'document_required' === (string) $application['application_status'] && empty( $application['document_storage_key'] ),
			'canRestart'         => 'rejected' === (string) $application['application_status'],
			'documentMime'       => $application['document_mime'] ?? null,
			'documentSize'       => isset( $application['document_size'] ) ? (int) $application['document_size'] : null,
			'documentAvailable'  => 'pending_manual_review' === (string) $application['application_status'] && ! empty( $application['document_storage_key'] ),
			'documentPurgeStatus'=> papelito_pre_account_application_document_purge_status( $application ),
			'rejectionReason'    => $application['rejection_reason'] ?? null,
			'decidedByUserId'    => ! empty( $application['decided_by_user_id'] ) ? (int) $application['decided_by_user_id'] : null,
		),
		'person'      => papelito_pre_account_application_admin_person( $values ),
		'company'     => array(
			'id'               => null,
			'cnpj'             => (string) $application['canonical_cnpj'],
			'legalName'        => is_string( $legal_name ) ? $legal_name : null,
			'tradeName'        => null,
			'registryStatus'   => null,
			'ownershipStatus'  => null,
			'companyStatus'    => null,
			'providerSource'   => $application['provider_source'] ?? null,
			'providerCheckedAt'=> $application['provider_checked_at'] ?? null,
			'fiscalAddress'    => is_array( $address ) ? $address : array(),
		),
		'membership'  => null,
		'evidence'    => is_array( $evidence ) ? $evidence : array(),
	);
}

function papelito_pre_account_application_admin_document( int $application_id ) {
	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application ) {
		return new WP_Error( 'papelito_pre_account_application_not_found', PAPELITO_PRE_ACCOUNT_APPLICATION_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}
	if ( 'pending_manual_review' !== (string) $application['application_status'] || empty( $application['document_storage_key'] ) ) {
		return new WP_Error( 'papelito_pre_account_document_unavailable', 'O documento não está mais disponível.', array( 'status' => 410 ) );
	}

	$key = (string) $application['document_storage_key'];
	if ( ! papelito_company_document_key_is_valid( $key ) ) {
		return new WP_Error( 'papelito_pre_account_document_invalid', 'Documento inválido.', array( 'status' => 500 ) );
	}
	$directory = papelito_company_documents_prepare_dir();
	if ( is_wp_error( $directory ) ) {
		return $directory;
	}
	$path = trailingslashit( $directory ) . $key;
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return new WP_Error( 'papelito_pre_account_document_missing', 'Documento não encontrado.', array( 'status' => 410 ) );
	}

	nocache_headers();
	header( 'Content-Type: ' . (string) $application['document_mime'] );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	header( 'Content-Disposition: inline; filename="' . str_replace( array( '"', "\r", "\n" ), '', (string) $application['document_original_name'] ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

/**
 * Decifra os campos necessários para provisionar a conta.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pre_account_application_decrypt_values( array $application ): array|WP_Error {
	$columns = array(
		'email'   => 'contact_email_ciphertext',
		'name'    => 'full_name_ciphertext',
		'phone'   => 'phone_ciphertext',
		'cpf'     => 'cpf_ciphertext',
		'birth'   => 'birth_date_ciphertext',
		'address' => 'address_ciphertext',
	);

	$values = array();
	foreach ( $columns as $key => $column ) {
		$value = papelito_pii_decrypt( (string) $application[ $column ] );
		if ( ! is_string( $value ) || '' === $value ) {
			return new WP_Error( 'papelito_pre_account_decrypt_failed', 'Não foi possível concluir a candidatura.', array( 'status' => 500 ) );
		}
		$values[ $key ] = $value;
	}

	return $values;
}

function papelito_pre_account_application_reject( array $application, int $actor_user_id, string $reason ): array|WP_Error {
	global $wpdb;
	$tables = papelito_company_table_names();
	$now    = current_time( 'mysql', true );
	$updated = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'application_status' => 'rejected',
			'is_open'            => null,
			'decided_by_user_id' => $actor_user_id,
			'decided_at'         => $now,
			'rejection_reason'   => $reason,
			'updated_at'         => $now,
		),
		array(
			'id'                 => (int) $application['id'],
			'application_status' => 'pending_manual_review',
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( 1 !== $updated ) {
		return new WP_Error( 'papelito_pre_account_decision_conflict', PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE, array( 'status' => 409 ) );
	}

	papelito_pre_account_application_purge_document( (int) $application['id'] );

	return papelito_pre_account_application_view( papelito_pre_account_application_get( (int) $application['id'] ) ?: $application );
}

/**
 * Encerra candidaturas abertas quando o fluxo de vendor vence por e-mail.
 *
 * @param string $email         E-mail da conta vendor.
 * @param int    $actor_user_id Administrador responsável pela decisão.
 * @return true|WP_Error
 */
function papelito_pre_account_application_reject_open_for_vendor( string $email, int $actor_user_id ): true|WP_Error {
	global $wpdb;
	$tables = papelito_company_table_names();
	$hmac   = papelito_pii_hmac( strtolower( trim( $email ) ) );
	if ( is_wp_error( $hmac ) ) {
		return $hmac;
	}

	$applications = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id FROM {$tables['pre_account_applications']} WHERE contact_email_hmac = %s AND is_open = 1 AND application_status IN ('pending_email_verification', 'document_required', 'pending_manual_review') FOR UPDATE",
			$hmac
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( ! is_array( $applications ) ) {
		return new WP_Error( 'papelito_pre_account_vendor_conflict_lookup_failed', 'Não foi possível verificar candidaturas empresariais abertas.', array( 'status' => 500 ) );
	}

	$now = current_time( 'mysql', true );
	foreach ( $applications as $application ) {
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['pre_account_applications']} SET application_status = 'rejected', is_open = NULL, decided_by_user_id = %d, decided_at = %s, rejection_reason = %s, updated_at = %s WHERE id = %d AND is_open = 1",
				$actor_user_id,
				$now,
				'Conta direcionada para o fluxo de vendor por decisão administrativa.',
				$now,
				(int) $application['id']
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $updated ) {
			return new WP_Error( 'papelito_pre_account_vendor_decision_failed', 'Não foi possível fechar a candidatura empresarial aberta para este e-mail.', array( 'status' => 500 ) );
		}

		if ( 1 !== $updated ) {
			return new WP_Error( 'papelito_pre_account_vendor_decision_conflict', PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE, array( 'status' => 409 ) );
		}

		papelito_pre_account_application_purge_document( (int) $application['id'] );
		$decided = papelito_pre_account_application_get( (int) $application['id'] );
		if ( $decided ) {
			papelito_pre_account_application_send_decision_email( $decided );
		}
	}

	return true;
}

function papelito_pre_account_application_send_decision_email( array $application ): void {
	$status = (string) $application['application_status'];
	if ( ! in_array( $status, array( 'approved', 'rejected' ), true ) ) {
		return;
	}

	$recipient = papelito_pii_decrypt( (string) ( $application['contact_email_ciphertext'] ?? '' ) );
	if ( ! is_string( $recipient ) || ! is_email( $recipient ) ) {
		return;
	}

	if ( 'approved' === $status ) {
		$subject = 'Cadastro empresarial aprovado - Papelito';
		$view    = array(
			'kicker'   => 'Cadastro empresarial',
			'headline' => 'Seu cadastro empresarial foi aprovado.',
			'lead'     => 'Sua conta já foi criada e você pode entrar com o e-mail e a senha que cadastrou.',
			'notes'    => array( 'Este endereço já está confirmado e passa a receber os documentos fiscais dos pedidos.' ),
		);
	} else {
		$subject = 'Cadastro empresarial não aprovado - Papelito';
		$view    = array(
			'kicker'   => 'Cadastro empresarial',
			'headline' => 'Seu cadastro empresarial não foi aprovado.',
			'lead'     => 'Não foi possível aprovar seu cadastro empresarial porque encontramos divergências nos dados analisados.',
			'notes'    => array( 'Esta solicitação foi encerrada. Para realizar uma nova tentativa, será necessário iniciar novamente o processo de cadastro empresarial.' ),
		);
	}

	papelito_email_send(
		$recipient,
		$subject,
		papelito_email_notice_html( $view ),
		papelito_email_notice_text( $view )
	);
}

/**
 * Cria conta, perfil, empresa e membership a partir de uma candidatura aprovada.
 *
 * O `wp_user` nasce FORA da transação de propósito: `wp_insert_user` dispara `user_register`,
 * que terceiros (WooCommerce) escutam e podem gravar por fora do nosso controle transacional.
 * As tabelas próprias ficam dentro da transação; se ela falhar, o usuário é removido em
 * seguida. Sem isso, uma falha depois de `papelito_company_create` deixava empresa órfã e o
 * CNPJ travado para sempre em papelito_pre_account_application_prepare().
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_pre_account_application_approve( array $application, int $actor_user_id ): array|WP_Error {
	$application_id = (int) $application['id'];
	$values         = papelito_pre_account_application_decrypt_values( $application );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	$registry = papelito_company_validate_owner_registry( $values['cpf'], $values['birth'], (string) $application['canonical_cnpj'], $values['name'] );
	if ( is_wp_error( $registry ) ) {
		return $registry;
	}
	if ( 'document_required' === (string) $registry['review_path'] && empty( $application['document_storage_key'] ) ) {
		return new WP_Error( 'papelito_pre_account_document_required', 'A nova consulta exige documento antes da aprovação.', array( 'status' => 409 ) );
	}
	if ( email_exists( $values['email'] ) || username_exists( $values['email'] ) || papelito_company_find_by_cnpj( (string) $application['canonical_cnpj'] ) ) {
		return new WP_Error( 'papelito_pre_account_unavailable', PAPELITO_PRE_ACCOUNT_APPLICATION_UNAVAILABLE_MESSAGE, array( 'status' => 409 ) );
	}

	$parts   = preg_split( '/\s+/', trim( $values['name'] ), 2 ) ?: array();
	$user_id = wp_insert_user(
		array(
			'user_login'   => $values['email'],
			'user_email'   => $values['email'],
			'user_pass'    => wp_generate_password( 32, true, true ),
			'first_name'   => $parts[0] ?? '',
			'last_name'    => $parts[1] ?? '',
			'display_name' => $values['name'],
			'role'         => 'customer',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	papelito_auth_mark_email_pending( $user_id );

	global $wpdb;
	$now     = current_time( 'mysql', true );
	$address = json_decode( $values['address'], true );
	$address = is_array( $address ) ? $address : array();

	$wpdb->query( PAPELITO_PRE_ACCOUNT_SQL_START_TRANSACTION ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// A senha foi escolhida na candidatura e guardada já com hash; wp_insert_user acabou de
	// gravar uma aleatória e é ela que precisa ser substituída.
	$wpdb->update( $wpdb->users, array( 'user_pass' => (string) $application['password_hash'] ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	clean_user_cache( $user_id );

	$profile = papelito_company_profile_upsert( $user_id, $values['cpf'], $values['birth'] );
	if ( is_wp_error( $profile ) ) {
		return papelito_pre_account_application_abort_approval( $user_id, $profile );
	}

	// O e-mail de faturamento nasce igual ao da conta, mas nao herda verificacao nenhuma: a conta
	// acabou de nascer `pending`. Quem confirmar o e-mail principal dispara `papelito_email_verified`
	// e `papelito_billing_email_sync_for_user()` confirma esta empresa em cascata.
	$company_id = papelito_company_create(
		(string) $application['canonical_cnpj'],
		array(
			'legal_name'         => (string) ( $registry['lookup']['legal_name'] ?? '' ),
			'trade_name'         => (string) ( $registry['lookup']['trade_name'] ?? '' ),
			'billing_email'      => papelito_normalize_email( (string) $values['email'] ),
			'billing_email_verified_at' => null,
			'phone'              => $values['phone'],
			'registry_status'    => 'active',
			'ownership_status'   => 'verified',
			'company_status'     => 'active',
			'owner_user_id'      => $user_id,
			'created_by_user_id' => $user_id,
			'fiscal_cep'         => (string) ( $address['cep'] ?? '' ),
			'fiscal_state'       => (string) ( $address['state'] ?? '' ),
			'fiscal_city'        => (string) ( $address['city'] ?? '' ),
			'fiscal_neighborhood'=> (string) ( $address['neighborhood'] ?? '' ),
			'fiscal_street'      => (string) ( $address['street'] ?? '' ),
			'fiscal_number'      => (string) ( $address['number'] ?? '' ),
			'fiscal_complement'  => (string) ( $address['complement'] ?? '' ),
		)
	);
	if ( is_wp_error( $company_id ) ) {
		return papelito_pre_account_application_abort_approval( $user_id, $company_id );
	}

	$member_id = papelito_company_member_upsert(
		$company_id,
		$user_id,
		array(
			'member_role'         => 'owner',
			'member_status'       => 'active',
			'membership_origin'   => 'owner_candidate',
			'approved_by_user_id' => $actor_user_id,
			'approved_at'         => $now,
		)
	);
	if ( is_wp_error( $member_id ) ) {
		return papelito_pre_account_application_abort_approval( $user_id, $member_id );
	}

	papelito_company_onboarding_upsert( $user_id, 'create_company', (string) $application['canonical_cnpj'], 'pending_onboarding' );
	papelito_company_onboarding_save_address( $user_id, (string) ( $address['cep'] ?? '' ), $address );
	papelito_company_onboarding_mark_completed( $user_id, $company_id, $member_id );

	$tables  = papelito_company_table_names();
	$decided = $wpdb->update(
		$tables['pre_account_applications'],
		array(
			'application_status'    => 'approved',
			'is_open'               => null,
			'decided_by_user_id'    => $actor_user_id,
			'decided_at'            => $now,
			'created_user_id'       => $user_id,
			'created_company_id'    => $company_id,
			'created_membership_id' => $member_id,
			'updated_at'            => $now,
		),
		array(
			'id'                 => $application_id,
			'application_status' => 'pending_manual_review',
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Guarda de concorrência: outro administrador decidiu esta candidatura enquanto
	// provisionávamos. Desfaz tudo em vez de deixar duas contas para o mesmo CNPJ.
	if ( 1 !== $decided ) {
		return papelito_pre_account_application_abort_approval(
			$user_id,
			new WP_Error( 'papelito_pre_account_decision_conflict', PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE, array( 'status' => 409 ) )
		);
	}

	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	clean_user_cache( $user_id );
	update_user_meta( $user_id, 'papelito_account_state', 'active' );
	papelito_pre_account_application_purge_document( $application_id );

	// Depois do COMMIT de proposito: `papelito_email_verified` dispara
	// `papelito_billing_email_sync_for_user()`, que precisa enxergar a empresa ja gravada para
	// confirmar o e-mail de faturamento em cascata. A posse da caixa foi comprovada na
	// candidatura, entao a conta nao pede uma segunda confirmacao.
	papelito_auth_mark_email_verified( $user_id );

	return papelito_pre_account_application_view( papelito_pre_account_application_get( $application_id ) ?: $application );
}

/**
 * Desfaz um provisionamento parcial: rollback das tabelas próprias e remoção do wp_user.
 */
function papelito_pre_account_application_abort_approval( int $user_id, WP_Error $error ): WP_Error {
	global $wpdb;
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	wp_delete_user( $user_id );
	clean_user_cache( $user_id );

	return $error;
}

function papelito_pre_account_application_decide( int $application_id, int $actor_user_id, bool $approve, string $reason = '' ): array|WP_Error {
	$reason = trim( sanitize_textarea_field( $reason ) );
	if ( ! $approve && '' === $reason ) {
		return new WP_Error( 'papelito_pre_account_rejection_reason_required', 'Informe o motivo interno da reprovação.', array( 'status' => 422 ) );
	}

	$application = papelito_pre_account_application_get( $application_id );
	if ( ! $application || 'pending_manual_review' !== (string) $application['application_status'] || empty( $application['is_open'] ) ) {
		return new WP_Error( 'papelito_pre_account_decision_conflict', PAPELITO_PRE_ACCOUNT_DECISION_CONFLICT_MESSAGE, array( 'status' => 409 ) );
	}

	$result = $approve
		? papelito_pre_account_application_approve( $application, $actor_user_id )
		: papelito_pre_account_application_reject( $application, $actor_user_id, $reason );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$decided = papelito_pre_account_application_get( $application_id );
	if ( $decided ) {
		papelito_pre_account_application_send_decision_email( $decided );
	}

	return $result;
}
