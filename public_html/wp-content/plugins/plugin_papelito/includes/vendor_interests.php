<?php
/**
 * Manifestacoes de interesse de customers em se tornarem vendors.
 *
 * Este modulo registra somente a triagem inicial. Ele nunca altera roles,
 * dados operacionais de vendor ou informacoes do recebedor Pagar.me.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_VENDOR_INTERESTS_TABLE' ) ) {
	define( 'PAPELITO_VENDOR_INTERESTS_TABLE', 'papelito_vendor_interests' );
}

/**
 * Retorna o nome completo da tabela de manifestacoes.
 */
function papelito_vendor_interests_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_VENDOR_INTERESTS_TABLE;
}

/**
 * Cria/atualiza a tabela de manifestacoes de interesse.
 */
function papelito_vendor_interests_install_table(): void {
	global $wpdb;

	$table           = papelito_vendor_interests_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  visibility ENUM('customer','public') NOT NULL DEFAULT 'customer',
  store_name VARCHAR(191) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  cnpj VARCHAR(24) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  email VARCHAR(191) NOT NULL,
  instagram VARCHAR(191) NOT NULL,
  discovery_channel VARCHAR(191) NULL DEFAULT NULL,
  has_sold_papelito VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_customer_user_id (customer_user_id),
  UNIQUE KEY uq_public_cnpj (cnpj, visibility),
  UNIQUE KEY uq_public_email (email, visibility),
  KEY idx_visibility_created (visibility, created_at),
  KEY idx_created_at (created_at)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Preserva triagens legadas de customers na nova entidade sem carregar status.
 *
 * A migracao e idempotente e nao dispara novas notificacoes administrativas.
 */
function papelito_vendor_interests_backfill_legacy(): void {
	global $wpdb;

	if ( '1' === get_option( 'papelito_vendor_interests_legacy_backfill_v1', '0' ) ) {
		return;
	}

	$legacy_users = get_users(
		array(
			'role'       => 'customer',
			'fields'     => 'all',
			'meta_key'   => 'application_status',
			'meta_value' => array( 'pending', 'rejected' ),
			'meta_compare' => 'IN',
		)
	);

	foreach ( is_array( $legacy_users ) ? $legacy_users : array() as $user ) {
		if ( ! $user instanceof WP_User || null !== papelito_vendor_interests_find_by_customer( (int) $user->ID ) ) {
			continue;
		}

		$submitted_at = (string) get_user_meta( $user->ID, 'application_submitted_at', true );
		if ( '' === $submitted_at ) {
			$submitted_at = (string) $user->user_registered;
		}

		$wpdb->insert(
			papelito_vendor_interests_table_name(),
			array(
				'customer_user_id'   => (int) $user->ID,
				'store_name'          => (string) get_user_meta( $user->ID, 'store_name', true ),
				'first_name'          => (string) get_user_meta( $user->ID, 'first_name', true ),
				'last_name'           => (string) get_user_meta( $user->ID, 'last_name', true ),
				'cnpj'                => (string) get_user_meta( $user->ID, 'cnpj', true ),
				'phone'               => (string) get_user_meta( $user->ID, 'phone_number', true ),
				'email'               => (string) $user->user_email,
				'instagram'           => (string) get_user_meta( $user->ID, 'instagram', true ),
				'discovery_channel'   => (string) get_user_meta( $user->ID, 'seller_application_discovery_channel', true ),
				'has_sold_papelito'   => (string) get_user_meta( $user->ID, 'seller_application_has_sold_papelito', true ),
				'created_at'          => $submitted_at,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	update_option( 'papelito_vendor_interests_legacy_backfill_v1', '1', true );
}

/**
 * Retorna true somente para contas customer autenticadas.
 *
 * @param int $user_id Usuario.
 */
function papelito_vendor_interests_is_customer( int $user_id ): bool {
	$user = get_userdata( $user_id );

	return $user instanceof WP_User && papelito_user_has_role( $user, 'customer' );
}

/**
 * Permission callback do endpoint do customer.
 *
 * @return true|WP_Error
 */
function papelito_vendor_interests_require_customer() {
	$user_id = get_current_user_id();

	if ( $user_id <= 0 ) {
		return new WP_Error( 'papelito_not_authenticated', 'Usuario nao autenticado.', array( 'status' => 401 ) );
	}

	if ( ! papelito_vendor_interests_is_customer( $user_id ) ) {
		return new WP_Error(
			'papelito_vendor_interest_customer_only',
			'Apenas customers podem registrar interesse em se tornar vendor.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Permission callback compartilhado entre customer autenticado e visitante anonimo.
 *
 * Para customers, exige role `customer`. Para visitantes, exige rate limit por IP
 * dentro do bucket `vendor_interest_public`. Visitantes que violem o rate limit
 * recebem 429 sem distinguir de origem do bloqueio.
 *
 * @return true|WP_Error
 */
function papelito_vendor_interests_require_visitor_or_customer() {
	$user_id = get_current_user_id();

	if ( $user_id > 0 ) {
		if ( ! papelito_vendor_interests_is_customer( $user_id ) ) {
			return new WP_Error(
				'papelito_vendor_interest_customer_only',
				'Apenas customers podem registrar interesse em se tornar vendor.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	if ( ! function_exists( 'papelito_auth_rate_limit' ) ) {
		return new WP_Error(
			'papelito_rate_limit_unavailable',
			'Não foi possível validar o envio agora.',
			array( 'status' => 503 )
		);
	}

	if ( ! papelito_auth_rate_limit( 'vendor_interest_public', 5, 60 ) ) {
		return new WP_Error(
			'papelito_rate_limited',
			'Você excedeu o limite de envios. Tente novamente em alguns minutos.',
			array( 'status' => 429 )
		);
	}

	return true;
}

/**
 * Permission callback dos endpoints administrativos.
 */
function papelito_vendor_interests_require_admin(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Normaliza uma linha do banco para o contrato REST.
 *
 * @param object|array<string,mixed> $row Linha.
 * @return array<string,mixed>
 */
function papelito_vendor_interests_normalize_row( $row ): array {
	$data = is_object( $row ) ? get_object_vars( $row ) : (array) $row;

	$customer_user_id = isset( $data['customer_user_id'] ) ? (int) $data['customer_user_id'] : 0;
	$visibility       = isset( $data['visibility'] ) ? (string) $data['visibility'] : 'customer';

	return array(
		'id'                => absint( $data['id'] ?? 0 ),
		'customerUserId'    => $customer_user_id > 0 ? $customer_user_id : null,
		'visibility'        => in_array( $visibility, array( 'customer', 'public' ), true ) ? $visibility : 'customer',
		'storeName'         => (string) ( $data['store_name'] ?? '' ),
		'firstName'         => (string) ( $data['first_name'] ?? '' ),
		'lastName'          => (string) ( $data['last_name'] ?? '' ),
		'cnpj'              => (string) ( $data['cnpj'] ?? '' ),
		'phone'             => (string) ( $data['phone'] ?? '' ),
		'email'             => (string) ( $data['email'] ?? '' ),
		'instagram'         => (string) ( $data['instagram'] ?? '' ),
		'discoveryChannel'  => (string) ( $data['discovery_channel'] ?? '' ),
		'hasSoldPapelito'   => (string) ( $data['has_sold_papelito'] ?? '' ),
		'createdAt'         => (string) ( $data['created_at'] ?? '' ),
	);
}

/**
 * Busca a manifestacao unica de um customer.
 *
 * @param int $customer_user_id Customer.
 * @return array<string,mixed>|null
 */
function papelito_vendor_interests_find_by_customer( int $customer_user_id ): ?array {
	global $wpdb;

	$table = papelito_vendor_interests_table_name();
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE customer_user_id = %d LIMIT 1",
			$customer_user_id
		)
	);

	return $row ? papelito_vendor_interests_normalize_row( $row ) : null;
}

/**
 * Sanitiza e valida o formulario de triagem.
 *
 * @param array<string,mixed> $input Payload.
 * @return array<string,string>|WP_Error
 */
function papelito_vendor_interests_validate_input( array $input ) {
	$values = array(
		'store_name'        => sanitize_text_field( (string) ( $input['storeName'] ?? '' ) ),
		'first_name'        => sanitize_text_field( (string) ( $input['firstName'] ?? '' ) ),
		'last_name'         => sanitize_text_field( (string) ( $input['lastName'] ?? '' ) ),
		'cnpj'              => sanitize_text_field( (string) ( $input['cnpj'] ?? '' ) ),
		'phone'             => sanitize_text_field( (string) ( $input['phone'] ?? '' ) ),
		'email'             => sanitize_email( (string) ( $input['email'] ?? '' ) ),
		'instagram'         => sanitize_text_field( ltrim( (string) ( $input['instagram'] ?? '' ), '@' ) ),
		'discovery_channel' => sanitize_text_field( (string) ( $input['discoveryChannel'] ?? '' ) ),
		'has_sold_papelito' => sanitize_key( (string) ( $input['hasSoldPapelito'] ?? '' ) ),
	);

	$required = array( 'store_name', 'first_name', 'last_name', 'cnpj', 'phone', 'email', 'instagram' );
	foreach ( $required as $field ) {
		if ( '' === $values[ $field ] ) {
			return new WP_Error( 'papelito_vendor_interest_missing_field', 'Preencha todos os campos obrigatorios.', array( 'status' => 422 ) );
		}
	}

	$cnpj_normalized = function_exists( 'papelito_normalize_cnpj' ) ? papelito_normalize_cnpj( $values['cnpj'] ) : preg_replace( '/\D+/', '', $values['cnpj'] );
	if ( ! is_string( $cnpj_normalized ) || 14 !== strlen( $cnpj_normalized ) ) {
		return new WP_Error( 'papelito_vendor_interest_invalid_cnpj', 'Informe um CNPJ valido.', array( 'status' => 422 ) );
	}
	if ( function_exists( 'papelito_validate_cnpj' ) && ! papelito_validate_cnpj( $values['cnpj'] ) ) {
		return new WP_Error( 'papelito_vendor_interest_invalid_cnpj', 'Informe um CNPJ valido.', array( 'status' => 422 ) );
	}
	$values['cnpj'] = $cnpj_normalized;

	$phone_digits = preg_replace( '/\D+/', '', $values['phone'] );
	if ( ! is_string( $phone_digits ) || strlen( $phone_digits ) < 10 || strlen( $phone_digits ) > 13 ) {
		return new WP_Error( 'papelito_vendor_interest_invalid_phone', 'Informe um telefone valido com DDD.', array( 'status' => 422 ) );
	}

	if ( ! is_email( $values['email'] ) ) {
		return new WP_Error( 'papelito_vendor_interest_invalid_email', 'Informe um e-mail valido.', array( 'status' => 422 ) );
	}

	if ( ! in_array( $values['has_sold_papelito'], array( 'sim', 'nao' ), true ) ) {
		return new WP_Error( 'papelito_vendor_interest_invalid_sales_answer', 'Informe se a loja ja vende produtos Papelito.', array( 'status' => 422 ) );
	}

	return $values;
}

/**
 * Procura uma manifestacao publica duplicada por CNPJ canônico ou e-mail.
 *
 * @param string $cnpj  CNPJ já normalizado (14 caracteres canônicos).
 * @param string $email E-mail já validado.
 * @return array<string,mixed>|null
 */
function papelito_vendor_interests_find_public_duplicate( string $cnpj, string $email ): ?array {
	global $wpdb;

	$table = papelito_vendor_interests_table_name();

	$by_cnpj = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE visibility = %s AND cnpj = %s ORDER BY id DESC LIMIT 1",
			'public',
			$cnpj
		)
	);
	if ( $by_cnpj ) {
		return papelito_vendor_interests_normalize_row( $by_cnpj );
	}

	$by_email = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE visibility = %s AND email = %s ORDER BY id DESC LIMIT 1",
			'public',
			$email
		)
	);

	return $by_email ? papelito_vendor_interests_normalize_row( $by_email ) : null;
}

/**
 * Registra a manifestacao sem modificar o usuario.
 *
 * Para customer autenticado: $customer_user_id > 0, $visibility = 'customer'.
 * Para visitante anonimo: $customer_user_id = 0, $visibility = 'public'.
 *
 * @param int                 $customer_user_id Customer autenticado (0 se visitante).
 * @param array<string,mixed> $input Payload.
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_interests_create( int $customer_user_id, array $input ) {
	global $wpdb;

	$is_customer = $customer_user_id > 0 && papelito_vendor_interests_is_customer( $customer_user_id );
	$visibility  = $is_customer ? 'customer' : 'public';

	if ( $customer_user_id > 0 && ! $is_customer ) {
		return new WP_Error( 'papelito_vendor_interest_customer_only', 'Apenas customers podem registrar interesse.', array( 'status' => 403 ) );
	}

	$values = papelito_vendor_interests_validate_input( $input );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	$existing = null;
	if ( $is_customer ) {
		$existing = papelito_vendor_interests_find_by_customer( $customer_user_id );
	} else {
		$existing = papelito_vendor_interests_find_public_duplicate( (string) $values['cnpj'], (string) $values['email'] );
	}

	if ( null !== $existing ) {
		return new WP_Error(
			'papelito_vendor_interest_already_exists',
			'O interesse desta loja já foi registrado.',
			array( 'status' => 409, 'interest' => $existing )
		);
	}

	$created_at = current_time( 'mysql', true );
	$inserted   = $wpdb->insert(
		papelito_vendor_interests_table_name(),
		array_merge(
			$values,
			array(
				'customer_user_id' => $is_customer ? $customer_user_id : null,
				'visibility'       => $visibility,
				'created_at'       => $created_at,
			)
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	if ( false === $inserted ) {
		$existing = $is_customer
			? papelito_vendor_interests_find_by_customer( $customer_user_id )
			: papelito_vendor_interests_find_public_duplicate( (string) $values['cnpj'], (string) $values['email'] );
		if ( null !== $existing ) {
			return new WP_Error(
				'papelito_vendor_interest_already_exists',
				'O interesse desta loja já foi registrado.',
				array( 'status' => 409, 'interest' => $existing )
			);
		}

		return new WP_Error( 'papelito_vendor_interest_insert_failed', 'Nao foi possivel registrar o interesse.', array( 'status' => 500 ) );
	}

	$interest_id = (int) $wpdb->insert_id;
	$interest    = $is_customer
		? papelito_vendor_interests_find_by_customer( $customer_user_id )
		: papelito_vendor_interests_find_public_duplicate( (string) $values['cnpj'], (string) $values['email'] );

	do_action( 'papelito_vendor_interest_submitted', $interest_id, $customer_user_id, $interest );

	return array(
		'success'    => true,
		'visibility' => $visibility,
		'interest'   => $interest,
	);
}

/**
 * Lista manifestacoes cujos usuarios continuam sendo customers.
 *
 * @param WP_REST_Request $request Requisicao.
 * @return array<string,mixed>
 */
function papelito_vendor_interests_admin_list( WP_REST_Request $request ): array {
	global $wpdb;

	$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
	$per_page = min( 100, max( 1, absint( $request->get_param( 'perPage' ) ?: 20 ) ) );
	$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
	$offset   = ( $page - 1 ) * $per_page;
	$table    = papelito_vendor_interests_table_name();
	$caps_key = $wpdb->get_blog_prefix() . 'capabilities';

	$customer_where = 'EXISTS (SELECT 1 FROM ' . $wpdb->usermeta . ' role_meta WHERE role_meta.user_id = customer_user_id AND role_meta.meta_key = %s AND role_meta.meta_value LIKE %s)';
	$where          = "(visibility = %s OR ({$customer_where}))";
	$params         = array( 'public', $caps_key, '%s:8:"customer";b:1%' );

	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where   .= ' AND (store_name LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR cnpj LIKE %s)';
		$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
	}

	$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
	$rows_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
	$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
	$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
	$items     = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$item = papelito_vendor_interests_normalize_row( $row );
		$is_public = isset( $item['visibility'] ) && 'public' === $item['visibility'];
		if ( $is_public || papelito_vendor_interests_is_customer( (int) $item['customerUserId'] ) ) {
			$items[] = $item;
		}
	}

	return array(
		'items'      => $items,
		'page'       => $page,
		'perPage'    => $per_page,
		'total'      => $total,
		'totalPages' => max( 1, (int) ceil( $total / $per_page ) ),
	);
}

/**
 * Busca detalhe admin e garante que a conta ainda e customer.
 *
 * @param int $interest_id Manifestacao.
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_interests_admin_detail( int $interest_id ) {
	global $wpdb;

	$table = papelito_vendor_interests_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $interest_id ) );

	if ( ! $row ) {
		return new WP_Error( 'papelito_vendor_interest_not_found', 'Manifestacao nao encontrada.', array( 'status' => 404 ) );
	}

	$interest = papelito_vendor_interests_normalize_row( $row );
	$is_public = isset( $interest['visibility'] ) && 'public' === $interest['visibility'];

	if ( ! $is_public ) {
		$user = get_userdata( (int) $interest['customerUserId'] );
		if ( ! $user instanceof WP_User || ! papelito_user_has_role( $user, 'customer' ) ) {
			return new WP_Error( 'papelito_vendor_interest_customer_unavailable', 'Este customer nao esta mais disponivel para promocao.', array( 'status' => 409 ) );
		}
		$interest['customer'] = array(
			'id'          => (int) $user->ID,
			'displayName' => (string) $user->display_name,
			'email'       => (string) $user->user_email,
		);
	}

	return $interest;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/vendor-interests/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_interests_require_customer',
				'callback'            => static function () {
					$interest = papelito_vendor_interests_find_by_customer( get_current_user_id() );

					return new WP_REST_Response( array( 'exists' => null !== $interest, 'interest' => $interest ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/vendor-interests',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_vendor_interests_require_visitor_or_customer',
				'callback'            => static function ( WP_REST_Request $request ) {
					$payload = $request->get_json_params();
					if ( ! is_array( $payload ) ) {
						return new WP_Error( 'papelito_invalid_payload', 'Payload invalido.', array( 'status' => 400 ) );
					}

					$user_id  = get_current_user_id();
					$result   = papelito_vendor_interests_create( $user_id, $payload );

					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/vendor-interests',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_interests_require_admin',
				'callback'            => static function ( WP_REST_Request $request ) {
					return new WP_REST_Response( papelito_vendor_interests_admin_list( $request ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/vendor-interests/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_interests_require_admin',
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $value ): bool {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_vendor_interests_admin_detail( (int) $request['id'] );

					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
			)
		);
	}
);
