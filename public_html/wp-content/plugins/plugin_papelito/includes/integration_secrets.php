<?php
/**
 * Cofre de credenciais editáveis pelo painel administrativo.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_INTEGRATION_SECRETS_OPTION = 'papelito_integration_secrets';
const PAPELITO_INTEGRATION_SECRET_AUDIT_TABLE = 'papelito_integration_secret_audit';
const PAPELITO_INTEGRATION_SECRET_PENDING_PREFIX = '__pending__:';
const PAPELITO_INTEGRATION_SECRET_CONFIRMATION_TTL = 900;

function papelito_integration_secret_forbidden_slugs(): array {
	return array(
		'papelito_pii_encryption_key',
		'papelito_pii_lookup_key',
		'graphql_jwt_auth_secret_key',
	);
}

function papelito_integration_secret_catalog(): array {
	$catalog = array(
		'ga4_measurement_id' => array(
			'slug'  => 'ga4_measurement_id',
			'label' => 'ID de medição do GA4',
			'class' => 'analytics',
			'env'   => 'GA4_MEASUREMENT_ID',
		),
		'ga4_api_secret'     => array(
			'slug'  => 'ga4_api_secret',
			'label' => 'Segredo da API do GA4',
			'class' => 'analytics',
			'env'   => 'GA4_API_SECRET',
		),
	);

	return apply_filters( 'papelito_integration_secret_catalog', $catalog );
}

function papelito_integration_secret_catalog_item( string $slug ) {
	$slug = sanitize_key( $slug );

	if ( true === in_array( $slug, papelito_integration_secret_forbidden_slugs(), true ) ) {
		return new WP_Error( 'papelito_integration_secret_forbidden_slug', 'Esta credencial não pode ser gerenciada pelo painel.', array( 'status' => 422 ) );
	}

	$catalog = papelito_integration_secret_catalog();
	$item    = $catalog[ $slug ] ?? null;

	if ( ! is_array( $item ) || 0 !== strcmp( $slug, (string) ( $item['slug'] ?? '' ) ) ) {
		return new WP_Error( 'papelito_integration_secret_unknown_slug', 'Integração desconhecida.', array( 'status' => 404 ) );
	}

	return $item;
}

function papelito_integration_secret_store(): array {
	$stored = get_option( PAPELITO_INTEGRATION_SECRETS_OPTION, array() );

	return is_array( $stored ) ? $stored : array();
}

function papelito_integration_secret_save_store( array $store ): void {
	if ( false === get_option( PAPELITO_INTEGRATION_SECRETS_OPTION, false ) ) {
		add_option( PAPELITO_INTEGRATION_SECRETS_OPTION, array(), '', false );
	}

	update_option( PAPELITO_INTEGRATION_SECRETS_OPTION, $store, false );
}

function papelito_integration_secret_environment_value( array $item ): string {
	$environment_key = (string) ( $item['env'] ?? '' );
	$value           = '' !== $environment_key && function_exists( 'papelito_env' ) ? papelito_env( $environment_key, '' ) : '';

	return is_string( $value ) ? trim( $value ) : '';
}

function papelito_integration_secret_from_vault( string $slug ): string {
	$envelope = papelito_integration_secret_store()[ $slug ] ?? '';

	if ( ! is_string( $envelope ) || '' === $envelope || ! function_exists( 'papelito_pii_decrypt' ) ) {
		return '';
	}

	$value = papelito_pii_decrypt( $envelope );

	return is_string( $value ) ? $value : '';
}

function papelito_integration_secret( string $slug ): string {
	$item = papelito_integration_secret_catalog_item( $slug );

	if ( is_wp_error( $item ) ) {
		return '';
	}

	$environment_value = papelito_integration_secret_environment_value( $item );

	if ( '' !== $environment_value ) {
		return $environment_value;
	}

	return papelito_integration_secret_from_vault( $slug );
}

function papelito_integration_secret_source( string $slug ): ?string {
	$item = papelito_integration_secret_catalog_item( $slug );

	if ( is_wp_error( $item ) ) {
		return null;
	}

	if ( '' !== papelito_integration_secret_environment_value( $item ) ) {
		return 'env';
	}

	return '' !== papelito_integration_secret_from_vault( $slug ) ? 'vault' : null;
}

function papelito_integration_secret_last4( string $value ): ?string {
	return '' === $value ? null : substr( $value, -4 );
}

function papelito_integration_secret_audit_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_INTEGRATION_SECRET_AUDIT_TABLE;
}

function papelito_integration_secret_install_tables(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table_name      = papelito_integration_secret_audit_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
slug VARCHAR(100) NOT NULL,
actor_user_id BIGINT UNSIGNED NULL,
action VARCHAR(40) NOT NULL,
ip VARCHAR(45) NULL,
user_agent VARCHAR(255) NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY idx_slug_created (slug, created_at),
KEY idx_actor_created (actor_user_id, created_at)
) {$charset_collate};";

	dbDelta( $sql );

	return true;
}

function papelito_integration_secret_request_ip(): string {
	$value = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return substr( $value, 0, 45 );
}

function papelito_integration_secret_request_user_agent(): string {
	$value = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	return substr( $value, 0, 255 );
}

function papelito_integration_secret_audit( string $slug, int $actor_user_id, string $action ): void {
	global $wpdb;

	$wpdb->insert(
		papelito_integration_secret_audit_table_name(),
		array(
			'slug'          => $slug,
			'actor_user_id' => $actor_user_id,
			'action'        => $action,
			'ip'            => papelito_integration_secret_request_ip(),
			'user_agent'    => papelito_integration_secret_request_user_agent(),
			'created_at'    => current_time( 'mysql', true ),
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s' )
	);
}

function papelito_integration_secret_last_audit( string $slug ): ?array {
	global $wpdb;

	$audit_table = papelito_integration_secret_audit_table_name();
	$sql         = 'SELECT audit.created_at, audit.actor_user_id, users.display_name FROM ' . $audit_table . ' audit LEFT JOIN ' . $wpdb->users . ' users ON users.ID = audit.actor_user_id WHERE audit.slug = %s ORDER BY audit.id DESC LIMIT 1'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Names originate only from the WordPress table prefix.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The table name cannot be a SQL placeholder.
	$row         = $wpdb->get_row(
		$wpdb->prepare(
			$sql,
			$slug
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	return is_array( $row ) ? $row : null;
}

function papelito_integration_secret_send_alert( string $slug, string $action, int $actor_user_id, ?string $confirmation_token = null ): bool {
	$user = get_user_by( 'id', $actor_user_id );

	if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
		return false;
	}

	$item    = papelito_integration_secret_catalog_item( $slug );
	$label   = is_array( $item ) ? (string) $item['label'] : $slug;
	$subject = 'Alteração de integração solicitada na Papelito';
	$body    = "Integração: {$label}" . PHP_EOL . "Ação: {$action}" . PHP_EOL;

	if ( null !== $confirmation_token ) {
		$link = papelito_frontend_link( 'admin/config?integrationSecretToken=' . rawurlencode( $confirmation_token ) );
		if ( is_wp_error( $link ) ) {
			return false;
		}
		$body .= PHP_EOL . 'Confirme a alteração de pagamento neste link (válido por 15 minutos):' . PHP_EOL . $link;
	}

	return wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
}

function papelito_integration_secret_metadata( string $slug ): array {
	$item  = papelito_integration_secret_catalog_item( $slug );
	$source = papelito_integration_secret_source( $slug );
	$value  = papelito_integration_secret( $slug );
	$audit  = papelito_integration_secret_last_audit( $slug );

	return array(
		'slug'       => $slug,
		'label'      => (string) $item['label'],
		'class'      => (string) $item['class'],
		'configured' => '' !== $value,
		'last4'      => papelito_integration_secret_last4( $value ),
		'source'     => $source,
		'updated_at' => $audit['created_at'] ?? null,
		'updated_by' => $audit['display_name'] ?? null,
	);
}

function papelito_integration_secret_require_admin(): bool {
	return current_user_can( 'manage_options' );
}

function papelito_integration_secret_handler_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'papelito_integration_secret_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );
	}

	return true;
}

function papelito_integration_secret_verify_password( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	if ( ! papelito_auth_rate_limit( 'integration_secrets_write', 5, 300, 'user:' . $user_id ) ) {
		return new WP_Error( 'papelito_integration_secret_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$payload  = (array) $request->get_json_params();
	$password = (string) ( $payload['currentPassword'] ?? '' );
	$user     = get_user_by( 'id', $user_id );

	if ( ! $user instanceof WP_User || '' === $password || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
		return new WP_Error( 'papelito_integration_secret_current_password_invalid', 'Não foi possível confirmar a senha atual.', array( 'status' => 403 ) );
	}

	return true;
}

function papelito_integration_secret_pending_key( string $slug ): string {
	return PAPELITO_INTEGRATION_SECRET_PENDING_PREFIX . $slug;
}

function papelito_integration_secret_write_pending( string $slug, string $action, string $secret, int $actor_user_id ): string {
	$token   = wp_generate_password( 48, false, false );
	$payload = wp_json_encode(
		array(
			'action'     => $action,
			'secret'     => $secret,
			'token_hash' => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
			'expires_at' => time() + PAPELITO_INTEGRATION_SECRET_CONFIRMATION_TTL,
			'actor'      => $actor_user_id,
		)
	);
	$envelope = papelito_pii_encrypt( (string) $payload );

	if ( is_wp_error( $envelope ) ) {
		return '';
	}

	$store[ papelito_integration_secret_pending_key( $slug ) ] = $envelope;
	papelito_integration_secret_save_store( $store );

	return $token;
}

function papelito_integration_secret_mutate( WP_REST_Request $request, string $action ) {
	$authorization = papelito_integration_secret_handler_admin();
	if ( is_wp_error( $authorization ) ) {
		return $authorization;
	}

	$password = papelito_integration_secret_verify_password( $request );
	if ( is_wp_error( $password ) ) {
		return $password;
	}

	$slug = sanitize_key( (string) $request['slug'] );
	$item = papelito_integration_secret_catalog_item( $slug );
	if ( is_wp_error( $item ) ) {
		return $item;
	}

	$secret = '';
	if ( 'set' === $action ) {
		$payload = (array) $request->get_json_params();
		$secret  = (string) ( $payload['secret'] ?? '' );
		if ( '' === $secret ) {
			return new WP_Error( 'papelito_integration_secret_value_required', 'Informe a credencial.', array( 'status' => 422 ) );
		}
	}

	$actor_user_id = get_current_user_id();
	if ( 'pagamento' === (string) $item['class'] ) {
		$token = papelito_integration_secret_write_pending( $slug, $action, $secret, $actor_user_id );
		if ( '' === $token ) {
			return new WP_Error( 'papelito_integration_secret_encrypt_failed', 'Não foi possível preparar a credencial.', array( 'status' => 500 ) );
		}
		if ( ! papelito_integration_secret_send_alert( $slug, 'confirmação pendente', $actor_user_id, $token ) ) {
			$store = papelito_integration_secret_store();
			unset( $store[ papelito_integration_secret_pending_key( $slug ) ] );
			papelito_integration_secret_save_store( $store );
			return new WP_Error( 'papelito_integration_secret_email_failed', 'Não foi possível enviar o e-mail de confirmação.', array( 'status' => 500 ) );
		}
		papelito_integration_secret_audit( $slug, $actor_user_id, 'payment_' . $action . '_pending' );
		return new WP_REST_Response( array( 'pending_confirmation' => true ), 202 );
	}

	$previous_store = papelito_integration_secret_store();
	$store          = $previous_store;
	if ( 'set' === $action ) {
		$envelope = papelito_pii_encrypt( $secret );
		if ( is_wp_error( $envelope ) ) {
			return new WP_Error( 'papelito_integration_secret_encrypt_failed', 'Não foi possível gravar a credencial.', array( 'status' => 500 ) );
		}
		$store[ $slug ] = $envelope;
	} else {
		unset( $store[ $slug ] );
	}
	papelito_integration_secret_save_store( $store );
	if ( ! papelito_integration_secret_send_alert( $slug, $action, $actor_user_id ) ) {
		papelito_integration_secret_save_store( $previous_store );
		return new WP_Error( 'papelito_integration_secret_email_failed', 'Não foi possível enviar o e-mail de alerta.', array( 'status' => 500 ) );
	}
	papelito_integration_secret_audit( $slug, $actor_user_id, $action );

	return new WP_REST_Response( papelito_integration_secret_metadata( $slug ), 200 );
}

function papelito_integration_secret_list_endpoint() {
	$authorization = papelito_integration_secret_handler_admin();
	if ( is_wp_error( $authorization ) ) {
		return $authorization;
	}

	$items = array();
	foreach ( papelito_integration_secret_catalog() as $item ) {
		if ( is_array( $item ) && isset( $item['slug'] ) ) {
			$items[] = papelito_integration_secret_metadata( (string) $item['slug'] );
		}
	}

	return new WP_REST_Response( array( 'items' => $items ), 200 );
}

function papelito_integration_secret_set_endpoint( WP_REST_Request $request ) {
	return papelito_integration_secret_mutate( $request, 'set' );
}

function papelito_integration_secret_delete_endpoint( WP_REST_Request $request ) {
	return papelito_integration_secret_mutate( $request, 'delete' );
}

function papelito_integration_secret_confirm_endpoint( WP_REST_Request $request ) {
	$authorization = papelito_integration_secret_handler_admin();
	if ( is_wp_error( $authorization ) ) {
		return $authorization;
	}

	$payload = (array) $request->get_json_params();
	$token   = (string) ( $payload['token'] ?? '' );
	if ( '' === $token ) {
		return new WP_Error( 'papelito_integration_secret_invalid_token', 'Link de confirmação inválido ou já utilizado.', array( 'status' => 404 ) );
	}

	$store = papelito_integration_secret_store();
	foreach ( papelito_integration_secret_catalog() as $item ) {
		if ( ! is_array( $item ) || 'pagamento' !== (string) ( $item['class'] ?? '' ) ) {
			continue;
		}
		$slug     = (string) $item['slug'];
		$key      = papelito_integration_secret_pending_key( $slug );
		$envelope = $store[ $key ] ?? '';
		$decoded  = is_string( $envelope ) ? papelito_pii_decrypt( $envelope ) : '';
		$pending  = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $pending ) || ! hash_equals( (string) ( $pending['token_hash'] ?? '' ), hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) ) ) {
			continue;
		}
		if ( (int) ( $pending['expires_at'] ?? 0 ) <= time() ) {
			return new WP_Error( 'papelito_integration_secret_token_expired', 'Link de confirmação expirado.', array( 'status' => 410 ) );
		}
		if ( 'set' === (string) $pending['action'] ) {
			$secret_envelope = papelito_pii_encrypt( (string) $pending['secret'] );
			if ( is_wp_error( $secret_envelope ) ) {
				return new WP_Error( 'papelito_integration_secret_encrypt_failed', 'Não foi possível confirmar a credencial.', array( 'status' => 500 ) );
			}
			$store[ $slug ] = $secret_envelope;
		} else {
			unset( $store[ $slug ] );
		}
		unset( $store[ $key ] );
		papelito_integration_secret_save_store( $store );
		papelito_integration_secret_audit( $slug, get_current_user_id(), 'payment_' . (string) $pending['action'] . '_confirmed' );

		return new WP_REST_Response( papelito_integration_secret_metadata( $slug ), 200 );
	}

	return new WP_Error( 'papelito_integration_secret_invalid_token', 'Link de confirmação inválido ou já utilizado.', array( 'status' => 404 ) );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/integration-secrets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_integration_secret_require_admin',
				'callback'            => 'papelito_integration_secret_list_endpoint',
			)
		);
		register_rest_route(
			'papelito/v1',
			'/integration-secrets/(?P<slug>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_integration_secret_require_admin',
					'callback'            => 'papelito_integration_secret_set_endpoint',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => 'papelito_integration_secret_require_admin',
					'callback'            => 'papelito_integration_secret_delete_endpoint',
				),
			)
		);
		register_rest_route(
			'papelito/v1',
			'/integration-secrets/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_integration_secret_require_admin',
				'callback'            => 'papelito_integration_secret_confirm_endpoint',
			)
		);
	}
);
