<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.
/**
 * Integrações de transportadora isoladas por vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_INTEGRATIONS_TABLE       = 'papelito_vendor_integrations';
const PAPELITO_VENDOR_INTEGRATION_AUDIT_TABLE  = 'papelito_vendor_integration_audit';
const PAPELITO_VENDOR_INTEGRATION_PROVIDER     = 'braspress';
const PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED = 'unconfigured';
const PAPELITO_VENDOR_INTEGRATION_READY        = 'ready';
const PAPELITO_VENDOR_INTEGRATION_ACTIVE       = 'active';
const PAPELITO_VENDOR_INTEGRATION_INVALID      = 'invalid_credentials';

/**
 * Retorna o nome da tabela principal de integrações por vendor.
 *
 * @return string Nome com prefixo WordPress.
 */
function papelito_vendor_integrations_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_VENDOR_INTEGRATIONS_TABLE;
}

/**
 * Retorna o nome da tabela de auditoria sem segredos.
 *
 * @return string Nome com prefixo WordPress.
 */
function papelito_vendor_integration_audit_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_VENDOR_INTEGRATION_AUDIT_TABLE;
}

/**
 * Cria ou atualiza as tabelas da integração.
 *
 * @return bool Se a rotina foi acionada.
 */
function papelito_vendor_integrations_install_tables(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();
	$integrations    = papelito_vendor_integrations_table_name();
	$audit           = papelito_vendor_integration_audit_table_name();

	$sql_integrations = "CREATE TABLE {$integrations} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
vendor_id BIGINT UNSIGNED NOT NULL,
provider VARCHAR(32) NOT NULL,
config_json LONGTEXT NOT NULL,
secret_envelope LONGTEXT NULL DEFAULT NULL,
configuration_version INT UNSIGNED NOT NULL DEFAULT 1,
enabled TINYINT(1) NOT NULL DEFAULT 0,
status VARCHAR(32) NOT NULL DEFAULT 'unconfigured',
last_successful_quote_at DATETIME NULL DEFAULT NULL,
last_health_checked_at DATETIME NULL DEFAULT NULL,
last_error_category VARCHAR(64) NULL DEFAULT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
created_by BIGINT UNSIGNED NULL DEFAULT NULL,
updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uq_vendor_provider (vendor_id, provider),
KEY idx_provider_eligibility (provider, enabled, status),
KEY idx_vendor_provider (vendor_id, provider)
) {$charset_collate};";

	$sql_audit = "CREATE TABLE {$audit} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
vendor_id BIGINT UNSIGNED NOT NULL,
provider VARCHAR(32) NOT NULL,
actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
action VARCHAR(40) NOT NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY idx_vendor_created (vendor_id, created_at),
KEY idx_provider_created (provider, created_at)
) {$charset_collate};";

	dbDelta( $sql_integrations );
	dbDelta( $sql_audit );

	return true;
}

/**
 * Fornece a estrutura pública de configuração ainda vazia.
 *
 * @return array<string,string> Configuração sem segredos.
 */
function papelito_vendor_integration_empty_config(): array {
	return array(
		'sender_cnpj'           => '',
		'origin_cep'            => '',
		'modal'                 => '',
		'freight_type'          => '',
		'consignee_cnpj'        => '',
		'weight_unit'           => '',
		'quote_timezone'        => '',
		'tracking_tomador_cnpj' => '',
	);
}

/**
 * Mantém somente os dígitos de CPF/CNPJ usados pela API.
 *
 * @param mixed $value Documento a normalizar.
 * @return string Documento numérico.
 */
function papelito_vendor_integration_normalize_document( $value ): string {
	$digits = preg_replace( PAPELITO_DIGITS_REGEX, '', (string) $value );

	return is_string( $digits ) ? $digits : '';
}

/**
 * Mantém somente os dígitos de um CEP.
 *
 * @param mixed $value CEP a normalizar.
 * @return string CEP numérico.
 */
function papelito_vendor_integration_normalize_cep( $value ): string {
	$digits = preg_replace( PAPELITO_DIGITS_REGEX, '', (string) $value );

	return is_string( $digits ) ? $digits : '';
}

/**
 * Confirma que a timezone existe no catálogo IANA.
 *
 * @param string $timezone Timezone informada pelo contrato.
 * @return bool Se a timezone é válida.
 */
function papelito_vendor_integration_timezone_is_valid( string $timezone ): bool {
	return in_array( $timezone, DateTimeZone::listIdentifiers(), true );
}

/**
 * Normaliza e valida os parâmetros contratuais não secretos.
 *
 * @param array<string,mixed> $raw Dados recebidos da API do vendor.
 * @return array<string,string>|WP_Error Configuração segura ou erro de validação.
 */
function papelito_vendor_integration_normalize_config( array $raw ) {
	$config = array(
		'sender_cnpj'           => papelito_vendor_integration_normalize_document( $raw['sender_cnpj'] ?? $raw['senderCnpj'] ?? '' ),
		'origin_cep'            => papelito_vendor_integration_normalize_cep( $raw['origin_cep'] ?? $raw['originCep'] ?? '' ),
		'modal'                 => strtoupper( sanitize_text_field( (string) ( $raw['modal'] ?? '' ) ) ),
		'freight_type'          => sanitize_text_field( (string) ( $raw['freight_type'] ?? $raw['freightType'] ?? '' ) ),
		'consignee_cnpj'        => papelito_vendor_integration_normalize_document( $raw['consignee_cnpj'] ?? $raw['consigneeCnpj'] ?? '' ),
		'weight_unit'           => strtolower( sanitize_text_field( (string) ( $raw['weight_unit'] ?? $raw['weightUnit'] ?? '' ) ) ),
		'quote_timezone'        => sanitize_text_field( (string) ( $raw['quote_timezone'] ?? $raw['quoteTimezone'] ?? '' ) ),
		'tracking_tomador_cnpj' => papelito_vendor_integration_normalize_document( $raw['tracking_tomador_cnpj'] ?? $raw['trackingTomadorCnpj'] ?? '' ),
	);

	foreach ( array( 'sender_cnpj', 'consignee_cnpj', 'tracking_tomador_cnpj' ) as $field ) {
		if ( '' === $config[ $field ] ) {
			continue;
		}

		if ( 14 !== strlen( $config[ $field ] ) || ( function_exists( 'papelito_validate_cnpj' ) && ! papelito_validate_cnpj( $config[ $field ] ) ) ) {
			return new WP_Error( 'papelito_vendor_integration_invalid_document', 'Informe um CNPJ válido.', array( 'status' => 422 ) );
		}
	}

	if ( '' !== $config['origin_cep'] && 8 !== strlen( $config['origin_cep'] ) ) {
		return new WP_Error( 'papelito_vendor_integration_invalid_origin_cep', 'Informe um CEP de origem com 8 dígitos.', array( 'status' => 422 ) );
	}

	if ( '' !== $config['modal'] && ! in_array( $config['modal'], array( 'R', 'A' ), true ) ) {
		return new WP_Error( 'papelito_vendor_integration_invalid_modal', 'Informe o modal rodoviário ou aéreo.', array( 'status' => 422 ) );
	}

	if ( '' !== $config['freight_type'] && ! in_array( $config['freight_type'], array( '1', '2', '3' ), true ) ) {
		return new WP_Error( 'papelito_vendor_integration_invalid_freight_type', 'Informe um tipo de frete válido.', array( 'status' => 422 ) );
	}

	if ( '3' === $config['freight_type'] && '' === $config['consignee_cnpj'] ) {
		return new WP_Error( 'papelito_vendor_integration_consignee_required', 'Informe o CNPJ do consignatário.', array( 'status' => 422 ) );
	}

	if ( '' !== $config['weight_unit'] && ! in_array( $config['weight_unit'], array( 'kg', 'g' ), true ) ) {
		return new WP_Error( 'papelito_vendor_integration_invalid_weight_unit', 'Informe uma unidade de peso válida.', array( 'status' => 422 ) );
	}

	if ( '' !== $config['quote_timezone'] && ! papelito_vendor_integration_timezone_is_valid( $config['quote_timezone'] ) ) {
		return new WP_Error( 'papelito_vendor_integration_invalid_timezone', 'Informe um fuso horário válido.', array( 'status' => 422 ) );
	}

	return $config;
}

/**
 * Verifica se todos os parâmetros necessários à cotação estão presentes.
 *
 * @param array<string,mixed> $config Configuração normalizada.
 * @return bool Se a configuração é suficiente.
 */
function papelito_vendor_integration_config_complete( array $config ): bool {
	$required = array( 'sender_cnpj', 'origin_cep', 'modal', 'freight_type', 'weight_unit', 'quote_timezone', 'tracking_tomador_cnpj' );

	foreach ( $required as $field ) {
		if ( empty( $config[ $field ] ) ) {
			return false;
		}
	}

	return '3' !== $config['freight_type'] || ! empty( $config['consignee_cnpj'] );
}

/**
 * Decodifica configuração persistida e aplica os valores vazios conhecidos.
 *
 * @param mixed $json JSON persistido.
 * @return array<string,string> Configuração pública.
 */
function papelito_vendor_integration_decode_config( $json ): array {
	$decoded = is_string( $json ) ? json_decode( $json, true ) : null;

	return array_merge( papelito_vendor_integration_empty_config(), is_array( $decoded ) ? $decoded : array() );
}

/**
 * Detecta uma alteração efetiva nos parâmetros contratuais não secretos.
 *
 * @param array<string,mixed> $existing Integração persistida.
 * @param array<string,string> $config Configuração normalizada recebida.
 * @return bool Se o contrato mudou e precisa ser validado de novo.
 */
function papelito_vendor_integration_config_changed( array $existing, array $config ): bool {
	$keys            = papelito_vendor_integration_empty_config();
	$existing_config = array_intersect_key( papelito_vendor_integration_decode_config( $existing['config_json'] ?? '' ), $keys );

	return $existing_config !== $config;
}

/**
 * Monta a representação pública, omitindo envelope e credenciais.
 *
 * @param array<string,mixed>|null $row Linha persistida.
 * @return array<string,mixed> Estado seguro para REST.
 */
function papelito_vendor_integration_public_record( ?array $row = null ): array {
	$row    = is_array( $row ) ? $row : array();
	$config = papelito_vendor_integration_decode_config( $row['config_json'] ?? '' );

	return array(
		'provider'                 => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'enabled'                  => ! empty( $row['enabled'] ),
		'status'                   => sanitize_key( (string) ( $row['status'] ?? PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED ) ),
		'configuration_version'    => max( 0, (int) ( $row['configuration_version'] ?? 0 ) ),
		'configured'               => papelito_vendor_integration_config_complete( $config ),
		'credentials_configured'   => ! empty( $row['secret_envelope'] ),
		'config'                   => $config,
		'last_successful_quote_at' => $row['last_successful_quote_at'] ?? null,
		'last_error_category'      => $row['last_error_category'] ?? null,
	);
}

/**
 * Busca a única integração de um provider para um vendor.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $provider Identificador do provider.
 * @return array<string,mixed>|null Linha encontrada.
 */
function papelito_vendor_integration_find_row( int $vendor_id, string $provider = PAPELITO_VENDOR_INTEGRATION_PROVIDER ): ?array {
	global $wpdb;

	$table = papelito_vendor_integrations_table_name();
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives exclusively from $wpdb->prefix.
			"SELECT * FROM {$table} WHERE vendor_id = %d AND provider = %s LIMIT 1",
			$vendor_id,
			$provider
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Registra uma ação sensível sem incluir configuração ou segredo.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $provider Identificador do provider.
 * @param int    $actor_user_id ID do autor autenticado.
 * @param string $action Ação auditável.
 * @return void
 */
function papelito_vendor_integration_audit( int $vendor_id, string $provider, int $actor_user_id, string $action ): void {
	global $wpdb;

	$wpdb->insert(
		papelito_vendor_integration_audit_table_name(),
		array(
			'vendor_id'     => $vendor_id,
			'provider'      => $provider,
			'actor_user_id' => $actor_user_id > 0 ? $actor_user_id : null,
			'action'        => sanitize_key( $action ),
			'created_at'    => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%d', '%s', '%s' )
	);
}

/**
 * Notifica uma alteração sensível sem incluir configuração ou segredo.
 *
 * O hook permite que a operação central envie alerta adicional sem acoplar a
 * integração a um destino específico. O e-mail para o titular reduz o tempo
 * de detecção de uma alteração indevida da credencial write-only.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $action Ação sensível ocorrida.
 * @return void
 */
function papelito_vendor_integration_security_event( int $vendor_id, string $action ): void {
	$action = sanitize_key( $action );
	do_action( 'papelito_vendor_integration_security_event', $vendor_id, PAPELITO_VENDOR_INTEGRATION_PROVIDER, $action );

	if ( ! in_array( $action, array( 'credentials_saved', 'credentials_removed', 'removed' ), true ) ) {
		return;
	}

	$user = get_userdata( $vendor_id );
	if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
		return;
	}

	wp_mail(
		$user->user_email,
		'Papelito: alteração na integração Braspress',
		'A integração Braspress da sua loja teve uma alteração de credencial ou foi removida. Nenhum segredo é enviado por este e-mail. Se você não reconhece a alteração, entre em contato com o suporte.'
	);
}

/**
 * Atualiza o estado operacional sem devolver ou registrar credenciais.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $status Novo estado operacional permitido.
 * @param string $error_category Categoria pública e redigida do erro.
 * @return void
 */
function papelito_vendor_integration_set_braspress_operational_state( int $vendor_id, string $status, string $error_category = '' ): void {
	global $wpdb;

	if ( ! in_array( $status, array( PAPELITO_VENDOR_INTEGRATION_ACTIVE, PAPELITO_VENDOR_INTEGRATION_INVALID ), true ) ) {
		return;
	}

	$data = array(
		'status'                 => $status,
		'last_health_checked_at' => current_time( 'mysql', true ),
		'last_error_category'    => '' !== $error_category ? sanitize_key( $error_category ) : null,
		'updated_at'             => current_time( 'mysql', true ),
	);
	if ( PAPELITO_VENDOR_INTEGRATION_ACTIVE === $status ) {
		$data['last_successful_quote_at'] = current_time( 'mysql', true );
	}

	$wpdb->update(
		papelito_vendor_integrations_table_name(),
		$data,
		array(
			'vendor_id' => $vendor_id,
			'provider'  => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		),
		array_fill( 0, count( $data ), '%s' ),
		array( '%d', '%s' )
	);
}

/**
 * Exige a senha atual antes de mutações de credencial.
 *
 * @param array<string,mixed> $payload Corpo da requisição.
 * @param int                 $vendor_id ID do vendor.
 * @return true|WP_Error Resultado da reautenticação.
 */
function papelito_vendor_integration_verify_current_password( array $payload, int $vendor_id ) {
	$password = (string) ( $payload['currentPassword'] ?? $payload['current_password'] ?? '' );
	$user     = get_user_by( 'id', $vendor_id );

	if ( ! $user instanceof WP_User || '' === $password || ! wp_check_password( $password, $user->user_pass, $vendor_id ) ) {
		return new WP_Error( 'papelito_vendor_integration_current_password_invalid', 'Não foi possível confirmar a senha atual.', array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Salva parâmetros e, opcionalmente, substitui a credencial write-only.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $payload Corpo autenticado da requisição.
 * @param int                 $actor_user_id ID do autor autenticado.
 * @return array<string,mixed>|WP_Error Estado público ou erro.
 */
function papelito_vendor_integration_save_braspress( int $vendor_id, array $payload, int $actor_user_id ) {
	global $wpdb;

	if ( $vendor_id <= 0 || $vendor_id !== $actor_user_id ) {
		return new WP_Error( 'papelito_vendor_integration_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );
	}

	if ( ! papelito_auth_rate_limit( 'vendor_braspress_write', 5, 300, 'user:' . $vendor_id ) ) {
		return new WP_Error( 'papelito_vendor_integration_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$password_check = papelito_vendor_integration_verify_current_password( $payload, $vendor_id );
	if ( is_wp_error( $password_check ) ) {
		return $password_check;
	}

	$config = papelito_vendor_integration_normalize_config( $payload );
	if ( is_wp_error( $config ) ) {
		return $config;
	}

	$enabled  = isset( $payload['enabled'] ) && true === filter_var( $payload['enabled'], FILTER_VALIDATE_BOOLEAN );
	$username = sanitize_text_field( (string) ( $payload['username'] ?? '' ) );
	$secret   = (string) ( $payload['password'] ?? '' );
	$row      = papelito_vendor_integration_find_row( $vendor_id );
	$existing = is_array( $row ) ? $row : array();
	$config_changed = papelito_vendor_integration_config_changed( $existing, $config );

	if ( ( '' === $username ) !== ( '' === $secret ) ) {
		return new WP_Error( 'papelito_vendor_integration_credentials_incomplete', 'Informe usuário e senha juntos.', array( 'status' => 422 ) );
	}

	$secret_envelope     = $existing['secret_envelope'] ?? null;
	$credentials_changed = '' !== $username;
	if ( $credentials_changed ) {
		$secret_envelope = papelito_pii_encrypt(
			wp_json_encode(
				array(
					'username' => $username,
					'password' => $secret,
				)
			)
		);
		if ( is_wp_error( $secret_envelope ) ) {
			return new WP_Error( 'papelito_vendor_integration_encrypt_failed', 'Não foi possível proteger a credencial.', array( 'status' => 500 ) );
		}
	}

	if ( ! empty( $payload['removeCredentials'] ) || ! empty( $payload['remove_credentials'] ) ) {
		$secret_envelope     = null;
		$credentials_changed = true;
	}

	$complete = papelito_vendor_integration_config_complete( $config );
	$status   = sanitize_key( (string) ( $existing['status'] ?? PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED ) );
	if ( ! $complete || empty( $secret_envelope ) ) {
		$status = PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED;
	} elseif ( $credentials_changed || ( $config_changed && PAPELITO_VENDOR_INTEGRATION_INVALID !== $status ) ) {
		$status = PAPELITO_VENDOR_INTEGRATION_READY;
	}

	$now     = current_time( 'mysql', true );
	$version = max( 0, (int) ( $existing['configuration_version'] ?? 0 ) ) + 1;
	$data    = array(
		'config_json'           => wp_json_encode( $config ),
		'secret_envelope'       => $secret_envelope,
		'configuration_version' => $version,
		'enabled'               => $enabled ? 1 : 0,
		'status'                => $status,
		'updated_at'            => $now,
		'updated_by'            => $actor_user_id,
	);

	if ( empty( $existing ) ) {
		$data['vendor_id']  = $vendor_id;
		$data['provider']   = PAPELITO_VENDOR_INTEGRATION_PROVIDER;
		$data['created_at'] = $now;
		$data['created_by'] = $actor_user_id;
		$written            = $wpdb->insert( papelito_vendor_integrations_table_name(), $data );
	} else {
		$written = $wpdb->update(
			papelito_vendor_integrations_table_name(),
			$data,
			array( 'id' => (int) $existing['id'] )
		);
	}

	if ( false === $written ) {
		return new WP_Error( 'papelito_vendor_integration_save_failed', 'Não foi possível salvar a integração.', array( 'status' => 500 ) );
	}

	$action = $credentials_changed
		? ( empty( $secret_envelope ) ? 'credentials_removed' : 'credentials_saved' )
		: 'configuration_saved';
	papelito_vendor_integration_audit( $vendor_id, PAPELITO_VENDOR_INTEGRATION_PROVIDER, $actor_user_id, $action );
	papelito_vendor_integration_security_event( $vendor_id, $action );

	return papelito_vendor_integration_public_record( papelito_vendor_integration_find_row( $vendor_id ) );
}

/**
 * Remove a integração e o envelope de credenciais após reautenticação.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $payload Corpo autenticado da requisição.
 * @param int                 $actor_user_id ID do autor autenticado.
 * @return array<string,mixed>|WP_Error Estado vazio ou erro.
 */
function papelito_vendor_integration_delete_braspress( int $vendor_id, array $payload, int $actor_user_id ) {
	global $wpdb;

	if ( $vendor_id <= 0 || $vendor_id !== $actor_user_id ) {
		return new WP_Error( 'papelito_vendor_integration_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );
	}

	if ( ! papelito_auth_rate_limit( 'vendor_braspress_write', 5, 300, 'user:' . $vendor_id ) ) {
		return new WP_Error( 'papelito_vendor_integration_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$password_check = papelito_vendor_integration_verify_current_password( $payload, $vendor_id );
	if ( is_wp_error( $password_check ) ) {
		return $password_check;
	}

	$deleted = $wpdb->delete(
		papelito_vendor_integrations_table_name(),
		array(
			'vendor_id' => $vendor_id,
			'provider'  => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		),
		array( '%d', '%s' )
	);

	if ( false === $deleted ) {
		return new WP_Error( 'papelito_vendor_integration_delete_failed', 'Não foi possível remover a integração.', array( 'status' => 500 ) );
	}

	papelito_vendor_integration_audit( $vendor_id, PAPELITO_VENDOR_INTEGRATION_PROVIDER, $actor_user_id, 'removed' );
	papelito_vendor_integration_security_event( $vendor_id, 'removed' );

	return papelito_vendor_integration_public_record();
}

/**
 * Resolve a integração com credenciais apenas para o adapter server-side.
 *
 * @param int $vendor_id ID do vendor.
 * @return array<string,mixed>|WP_Error|null Integração interna, erro ou inelegibilidade.
 */
function papelito_vendor_integration_resolve_braspress( int $vendor_id ) {
	$row = papelito_vendor_integration_find_row( $vendor_id );
	if ( ! is_array( $row ) || empty( $row['enabled'] ) || ! in_array( $row['status'], array( PAPELITO_VENDOR_INTEGRATION_READY, PAPELITO_VENDOR_INTEGRATION_ACTIVE ), true ) ) {
		return null;
	}

	$config = papelito_vendor_integration_decode_config( $row['config_json'] ?? '' );
	if ( ! papelito_vendor_integration_config_complete( $config ) || empty( $row['secret_envelope'] ) ) {
		return null;
	}

	$secret = papelito_pii_decrypt( (string) $row['secret_envelope'] );
	if ( is_wp_error( $secret ) ) {
		return new WP_Error( 'papelito_vendor_integration_secret_unavailable', 'A integração Braspress não está disponível.', array( 'status' => 503 ) );
	}

	$credentials = json_decode( $secret, true );
	if ( ! is_array( $credentials ) || empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
		return new WP_Error( 'papelito_vendor_integration_secret_invalid', 'A integração Braspress não está disponível.', array( 'status' => 503 ) );
	}

	return array(
		'id'                    => (int) $row['id'],
		'vendor_id'             => $vendor_id,
		'provider'              => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'config'                => $config,
		'configuration_version' => (int) $row['configuration_version'],
		'credentials'           => array(
			'username' => (string) $credentials['username'],
			'password' => (string) $credentials['password'],
		),
	);
}

/**
 * Entrega ao vendor somente o estado não secreto da sua integração.
 *
 * @return WP_REST_Response Resposta REST segura.
 */
function papelito_vendor_integration_handle_get_braspress() {
	return new WP_REST_Response( papelito_vendor_integration_public_record( papelito_vendor_integration_find_row( get_current_user_id() ) ), 200 );
}

/**
 * Salva a integração do vendor autenticado.
 *
 * @param WP_REST_Request $request Requisição REST autenticada.
 * @return WP_REST_Response|WP_Error Resultado da escrita.
 */
function papelito_vendor_integration_handle_save_braspress( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$result  = papelito_vendor_integration_save_braspress( get_current_user_id(), is_array( $payload ) ? $payload : array(), get_current_user_id() );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Remove a integração do vendor autenticado.
 *
 * @param WP_REST_Request $request Requisição REST autenticada.
 * @return WP_REST_Response|WP_Error Resultado da remoção.
 */
function papelito_vendor_integration_handle_delete_braspress( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$result  = papelito_vendor_integration_delete_braspress( get_current_user_id(), is_array( $payload ) ? $payload : array(), get_current_user_id() );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Registra as rotas REST autenticadas da integração Braspress.
 *
 * @return void
 */
function papelito_vendor_integration_register_routes(): void {
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/integrations/braspress',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
				'callback'            => 'papelito_vendor_integration_handle_get_braspress',
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller_commercial',
				'callback'            => 'papelito_vendor_integration_handle_save_braspress',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller_commercial',
				'callback'            => 'papelito_vendor_integration_handle_delete_braspress',
			),
		)
	);
}

add_action( 'rest_api_init', 'papelito_vendor_integration_register_routes' );
