<?php
/**
 * Fluxo de triagem do programa de revendedores.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retorna o status atual da triagem do revendedor.
 *
 * @param int $user_id
 * @return string
 */
function papelito_get_seller_application_status( int $user_id ): string {
	$status = (string) get_user_meta( $user_id, 'seller_application_status', true );

	if ( '' !== $status ) {
		return $status;
	}

	$user = get_userdata( $user_id );

	if ( $user instanceof WP_User && in_array( 'seller', (array) $user->roles, true ) ) {
		return 'approved';
	}

	return 'none';
}

/**
 * Monta o resumo da triagem de revendedor para o usuário.
 *
 * @param int $user_id
 * @return array<string, string>
 */
function papelito_get_seller_application_data( int $user_id ): array {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return array(
			'status'            => 'none',
			'submittedAt'       => '',
			'storeName'         => '',
			'firstName'         => '',
			'lastName'          => '',
			'email'             => '',
			'phoneNumber'       => '',
			'cnpj'              => '',
			'instagram'         => '',
			'state'             => '',
			'city'              => '',
			'cep'               => '',
			'minCep'            => '',
			'maxCep'            => '',
			'discoveryChannel'  => '',
			'hasSoldPapelito'   => '',
		);
	}

	return array(
		'status'           => papelito_get_seller_application_status( $user_id ),
		'submittedAt'      => (string) get_user_meta( $user_id, 'seller_application_submitted_at', true ),
		'storeName'        => (string) get_user_meta( $user_id, 'store_name', true ),
		'firstName'        => (string) get_user_meta( $user_id, 'first_name', true ),
		'lastName'         => (string) get_user_meta( $user_id, 'last_name', true ),
		'email'            => (string) $user->user_email,
		'phoneNumber'      => (string) get_user_meta( $user_id, 'phone_number', true ),
		'cnpj'             => (string) get_user_meta( $user_id, 'cnpj', true ),
		'instagram'        => (string) get_user_meta( $user_id, 'instagram', true ),
		'state'            => (string) get_user_meta( $user_id, 'state', true ),
		'city'             => (string) get_user_meta( $user_id, 'city', true ),
		'cep'              => (string) get_user_meta( $user_id, 'cep', true ),
		'minCep'           => (string) get_user_meta( $user_id, 'min_cep', true ),
		'maxCep'           => (string) get_user_meta( $user_id, 'max_cep', true ),
		'discoveryChannel' => (string) get_user_meta( $user_id, 'seller_application_discovery_channel', true ),
		'hasSoldPapelito'  => (string) get_user_meta( $user_id, 'seller_application_has_sold_papelito', true ),
	);
}

/**
 * Valida o payload da triagem.
 *
 * @param array $input
 * @return WP_Error|null
 */
function papelito_validate_seller_application_input( array $input ) {
	$errors = new WP_Error();

	$required_fields = array(
		'storeName'       => 'Informe o nome da loja.',
		'firstName'       => 'Informe o nome do responsável.',
		'lastName'        => 'Informe o sobrenome.',
		'email'           => 'Informe um e-mail válido.',
		'phoneNumber'     => 'Informe um telefone válido.',
		'cnpj'            => 'Informe um CNPJ válido.',
		'instagram'       => 'Informe o Instagram da loja.',
		'city'            => 'Informe a cidade.',
		'state'           => 'Selecione o estado.',
		'cep'             => 'Informe o CEP de operação.',
		'minCep'          => 'Informe o CEP inicial da região atendida.',
		'maxCep'          => 'Informe o CEP final da região atendida.',
		'hasSoldPapelito' => 'Escolha se você já vende produtos Papelito.',
	);

	foreach ( $required_fields as $field => $message ) {
		if ( empty( $input[ $field ] ) ) {
			$errors->add( $field, $message );
		}
	}

	$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
	if ( '' === $email || ! is_email( $email ) ) {
		$errors->add( 'email', 'Informe um e-mail válido.' );
	}

	$phone_digits = papelito_auth_normalize_phone( isset( $input['phoneNumber'] ) ? (string) $input['phoneNumber'] : '' );
	if ( ! in_array( strlen( $phone_digits ), array( 10, 11 ), true ) ) {
		$errors->add( 'phoneNumber', 'Informe um telefone válido.' );
	}

	$cnpj = isset( $input['cnpj'] ) ? (string) $input['cnpj'] : '';
	if ( ! preg_match( '/^\d{2}(\.\d{3}){2}\/\d{4}\-\d{2}$/', $cnpj ) ) {
		$errors->add( 'cnpj', 'Informe um CNPJ válido.' );
	}

	$state = isset( $input['state'] ) ? (string) $input['state'] : '';
	if ( '' !== $state && ! array_key_exists( $state, papelito_brazilian_states() ) ) {
		$errors->add( 'state', 'Selecione um estado válido.' );
	}

	$has_sold = isset( $input['hasSoldPapelito'] ) ? (string) $input['hasSoldPapelito'] : '';
	if ( '' !== $has_sold && ! in_array( $has_sold, array( 'sim', 'nao' ), true ) ) {
		$errors->add( 'hasSoldPapelito', 'Escolha uma opção válida.' );
	}

	$cep_fields = array(
		'cep'    => 'Informe um CEP de operação válido (8 dígitos).',
		'minCep' => 'Informe um CEP inicial válido (8 dígitos).',
		'maxCep' => 'Informe um CEP final válido (8 dígitos).',
	);

	$normalized_ceps = array();
	foreach ( $cep_fields as $field => $message ) {
		$raw        = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';
		$normalized = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( $raw ) : '';
		if ( '' === $normalized ) {
			$errors->add( $field, $message );
		}
		$normalized_ceps[ $field ] = $normalized;
	}

	if ( '' !== $normalized_ceps['minCep'] && '' !== $normalized_ceps['maxCep'] && (int) $normalized_ceps['minCep'] > (int) $normalized_ceps['maxCep'] ) {
		$errors->add( 'maxCep', 'O CEP final precisa ser maior ou igual ao CEP inicial.' );
	}

	return $errors->has_errors() ? $errors : null;
}

/**
 * Envia e-mail de triagem para o marketing.
 *
 * @param array $application
 * @return void
 */
function papelito_notify_seller_application( array $application ): void {
	$to          = 'marketing@papelitobrasil.com';
	$subject     = sprintf( 'Nova triagem PDV Perfeito: %s', $application['storeName'] );
	$reply_to    = isset( $application['email'] ) ? sanitize_email( (string) $application['email'] ) : '';
	$headers     = array( 'Content-Type: text/plain; charset=UTF-8' );
	$body_lines  = array(
		'Nova triagem enviada pelo fluxo /revendedor.',
		'',
		sprintf( 'Status: %s', $application['status'] ),
		sprintf( 'Enviado em: %s', $application['submittedAt'] ),
		sprintf( 'Loja: %s', $application['storeName'] ),
		sprintf( 'Responsável: %s %s', $application['firstName'], $application['lastName'] ),
		sprintf( 'E-mail: %s', $application['email'] ),
		sprintf( 'Telefone: %s', $application['phoneNumber'] ),
		sprintf( 'CNPJ: %s', $application['cnpj'] ),
		sprintf( 'Instagram: @%s', ltrim( (string) $application['instagram'], '@' ) ),
		sprintf( 'Cidade/Estado: %s - %s', $application['city'], $application['state'] ),
		sprintf( 'CEP de operação: %s', $application['cep'] ?? '' ),
		sprintf( 'Faixa atendida: %s - %s', $application['minCep'] ?? '', $application['maxCep'] ?? '' ),
		sprintf( 'Origem do contato: %s', $application['discoveryChannel'] ),
		sprintf( 'Já vende Papelito?: %s', $application['hasSoldPapelito'] ),
		'',
		'TODO(admin-triage-panel): espelhar esta triagem em um painel administrativo dedicado para admins.',
	);

	if ( '' !== $reply_to ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	wp_mail( $to, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Submete a triagem do usuário autenticado.
 *
 * @param int   $user_id
 * @param array $input
 * @return array<string, mixed>|WP_Error
 */
function papelito_submit_seller_application( int $user_id, array $input ) {
	$status = papelito_get_seller_application_status( $user_id );

	if ( 'pending' === $status ) {
		return new WP_Error(
			'papelito_application_pending',
			'Sua triagem ja foi enviada e esta em analise.',
			array( 'status' => 409 )
		);
	}

	if ( 'approved' === $status ) {
		return new WP_Error(
			'papelito_application_approved',
			'Sua conta ja foi aprovada para o programa de revendedores.',
			array( 'status' => 409 )
		);
	}

	$validation = papelito_validate_seller_application_input( $input );
	if ( $validation instanceof WP_Error ) {
		return $validation;
	}

	$current_user = get_userdata( $user_id );
	if ( ! $current_user instanceof WP_User ) {
		return new WP_Error( 'papelito_user_not_found', 'Usuario nao encontrado.', array( 'status' => 404 ) );
	}

	$email = sanitize_email( (string) $input['email'] );
	if ( $email !== $current_user->user_email && email_exists( $email ) ) {
		return new WP_Error( 'papelito_email_exists', 'Ja existe uma conta com este e-mail.', array( 'status' => 409 ) );
	}

	$result = wp_update_user(
		array(
			'ID'         => $user_id,
			'user_email' => $email,
			'first_name' => sanitize_text_field( (string) $input['firstName'] ),
			'last_name'  => sanitize_text_field( (string) $input['lastName'] ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	update_user_meta( $user_id, 'store_name', sanitize_text_field( (string) $input['storeName'] ) );
	update_user_meta( $user_id, 'phone_number', papelito_auth_format_phone( (string) $input['phoneNumber'] ) );
	update_user_meta( $user_id, 'cnpj', sanitize_text_field( (string) $input['cnpj'] ) );
	update_user_meta( $user_id, 'instagram', sanitize_text_field( ltrim( (string) $input['instagram'], '@' ) ) );
	update_user_meta( $user_id, 'state', sanitize_text_field( (string) $input['state'] ) );
	update_user_meta( $user_id, 'city', sanitize_text_field( (string) $input['city'] ) );

	$cep_base = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['cep'] ?? '' ) ) : '';
	$min_cep  = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['minCep'] ?? '' ) ) : '';
	$max_cep  = function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['maxCep'] ?? '' ) ) : '';

	update_user_meta( $user_id, 'cep', $cep_base );
	delete_user_meta( $user_id, 'min_cep' );
	delete_user_meta( $user_id, 'max_cep' );
	add_user_meta( $user_id, 'min_cep', $min_cep, false );
	add_user_meta( $user_id, 'max_cep', $max_cep, false );

	if ( function_exists( 'papelito_apply_vendor_geo' ) ) {
		papelito_apply_vendor_geo( $user_id, $cep_base );
	}

	update_user_meta( $user_id, 'seller_application_discovery_channel', sanitize_text_field( (string) ( $input['discoveryChannel'] ?? '' ) ) );
	update_user_meta( $user_id, 'seller_application_has_sold_papelito', sanitize_text_field( (string) $input['hasSoldPapelito'] ) );
	update_user_meta( $user_id, 'seller_application_status', 'pending' );
	update_user_meta( $user_id, 'seller_application_submitted_at', current_time( 'mysql' ) );

	$application = papelito_get_seller_application_data( $user_id );

	papelito_notify_seller_application( $application );

	return array(
		'success'     => true,
		'message'     => 'Triagem enviada com sucesso. Nosso time vai analisar seus dados.',
		'application' => $application,
	);
}

add_action(
	'graphql_register_types',
	static function (): void {
		if ( ! function_exists( 'register_graphql_object_type' ) || ! function_exists( 'register_graphql_field' ) || ! function_exists( 'register_graphql_mutation' ) ) {
			return;
		}

		register_graphql_object_type(
			'PapelitoSellerApplication',
			array(
				'description' => 'Resumo da triagem do programa de revendedores.',
				'fields'      => array(
					'status'           => array( 'type' => 'String' ),
					'submittedAt'      => array( 'type' => 'String' ),
					'storeName'        => array( 'type' => 'String' ),
					'firstName'        => array( 'type' => 'String' ),
					'lastName'         => array( 'type' => 'String' ),
					'email'            => array( 'type' => 'String' ),
					'phoneNumber'      => array( 'type' => 'String' ),
					'cnpj'             => array( 'type' => 'String' ),
					'instagram'        => array( 'type' => 'String' ),
					'state'            => array( 'type' => 'String' ),
					'city'             => array( 'type' => 'String' ),
					'cep'              => array( 'type' => 'String' ),
					'minCep'           => array( 'type' => 'String' ),
					'maxCep'           => array( 'type' => 'String' ),
					'discoveryChannel' => array( 'type' => 'String' ),
					'hasSoldPapelito'  => array( 'type' => 'String' ),
				),
			)
		);

		register_graphql_field(
			'Customer',
			'sellerApplication',
			array(
				'type'        => 'PapelitoSellerApplication',
				'description' => 'Status atual da triagem do cliente para o programa de revendedores.',
				'resolve'     => static function () {
					$user_id = get_current_user_id();
					return $user_id > 0 ? papelito_get_seller_application_data( $user_id ) : null;
				},
			)
		);

		register_graphql_mutation(
			'submitSellerApplication',
			array(
				'inputFields'         => array(
					'storeName'       => array( 'type' => array( 'non_null' => 'String' ) ),
					'firstName'       => array( 'type' => array( 'non_null' => 'String' ) ),
					'lastName'        => array( 'type' => array( 'non_null' => 'String' ) ),
					'email'           => array( 'type' => array( 'non_null' => 'String' ) ),
					'phoneNumber'     => array( 'type' => array( 'non_null' => 'String' ) ),
					'cnpj'            => array( 'type' => array( 'non_null' => 'String' ) ),
					'instagram'       => array( 'type' => array( 'non_null' => 'String' ) ),
					'city'            => array( 'type' => array( 'non_null' => 'String' ) ),
					'state'           => array( 'type' => array( 'non_null' => 'String' ) ),
					'cep'             => array( 'type' => array( 'non_null' => 'String' ) ),
					'minCep'          => array( 'type' => array( 'non_null' => 'String' ) ),
					'maxCep'          => array( 'type' => array( 'non_null' => 'String' ) ),
					'discoveryChannel' => array( 'type' => 'String' ),
					'hasSoldPapelito' => array( 'type' => array( 'non_null' => 'String' ) ),
				),
				'outputFields'        => array(
					'success'     => array( 'type' => 'Boolean' ),
					'message'     => array( 'type' => 'String' ),
					'application' => array( 'type' => 'PapelitoSellerApplication' ),
				),
				'mutateAndGetPayload' => static function ( $input ) {
					$user_id = get_current_user_id();

					if ( $user_id <= 0 ) {
						throw papelito_graphql_user_error( 'Usuario nao autenticado.' );
					}

					$result = papelito_submit_seller_application( $user_id, is_array( $input ) ? $input : array() );

					if ( is_wp_error( $result ) ) {
						throw papelito_graphql_user_error( $result->get_error_message() );
					}

					return $result;
				},
			)
		);
	}
);

/**
 * Retorna o detalhe completo da triagem para o painel admin.
 *
 * @param int $user_id
 * @return array<string, mixed>|WP_Error
 */
function papelito_get_vendor_application_detail( int $user_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$base = papelito_get_seller_application_data( $user_id );

	$min_ranges = (array) get_user_meta( $user_id, 'min_cep', false );
	$max_ranges = (array) get_user_meta( $user_id, 'max_cep', false );

	$reviewer_id  = (int) get_user_meta( $user_id, 'seller_application_reviewed_by', true );
	$reviewer     = $reviewer_id > 0 ? get_userdata( $reviewer_id ) : null;
	$reviewer_out = null;

	if ( $reviewer instanceof WP_User ) {
		$reviewer_out = array(
			'id'    => (int) $reviewer->ID,
			'name'  => (string) $reviewer->display_name,
			'email' => (string) $reviewer->user_email,
		);
	}

	$detail              = $base;
	$detail['id']        = (int) $user->ID;
	$detail['name']      = trim( (string) $user->display_name );
	$detail['minCepRanges'] = array_values( array_filter( array_map( 'strval', $min_ranges ), 'strlen' ) );
	$detail['maxCepRanges'] = array_values( array_filter( array_map( 'strval', $max_ranges ), 'strlen' ) );
	$detail['rejectionReason'] = (string) get_user_meta( $user_id, 'seller_application_rejection_reason', true );
	$detail['reviewedAt']      = (string) get_user_meta( $user_id, 'seller_application_reviewed_at', true );
	$detail['reviewedBy']      = $reviewer_out;
	$detail['registeredAt']    = (string) $user->user_registered;

	return $detail;
}

/**
 * Envia e-mail ao vendor com a decisao da triagem.
 *
 * @param WP_User $user
 * @param string  $decision 'approved' ou 'rejected'.
 * @param string  $reason
 * @return void
 */
function papelito_notify_vendor_decision( WP_User $user, string $decision, string $reason = '' ): void {
	$email = sanitize_email( (string) $user->user_email );
	if ( '' === $email ) {
		return;
	}

	$store    = (string) get_user_meta( $user->ID, 'store_name', true );
	$greeting = trim( (string) $user->first_name );
	if ( '' === $greeting ) {
		$greeting = '' !== $store ? $store : (string) $user->display_name;
	}

	if ( 'approved' === $decision ) {
		$subject    = 'Sua solicitacao foi aprovada - Papelito';
		$body_lines = array(
			sprintf( 'Ola %s,', $greeting ),
			'',
			'Boas noticias! Sua solicitacao para ser revendedor Papelito foi aprovada.',
			'Voce ja pode acessar a area do vendedor com suas credenciais atuais.',
			'',
			'Acesse: https://papelitobrasil.com/entrar',
			'',
			'Bem-vindo ao programa PDV Perfeito.',
			'Time Papelito',
		);
	} else {
		$subject    = 'Atualizacao da sua solicitacao - Papelito';
		$body_lines = array(
			sprintf( 'Ola %s,', $greeting ),
			'',
			'Recebemos e analisamos sua solicitacao para o programa de revendedores Papelito.',
			'No momento, ela nao foi aprovada.',
		);

		if ( '' !== $reason ) {
			$body_lines[] = '';
			$body_lines[] = 'Motivo informado pelo nosso time:';
			$body_lines[] = $reason;
		}

		$body_lines[] = '';
		$body_lines[] = 'Voce pode entrar em contato com marketing@papelitobrasil.com para mais informacoes.';
		$body_lines[] = '';
		$body_lines[] = 'Time Papelito';
	}

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	wp_mail( $email, $subject, implode( PHP_EOL, $body_lines ), $headers );
}

/**
 * Aprova a triagem do vendor.
 *
 * @param int $user_id
 * @param int $reviewer_id
 * @return array<string, mixed>|WP_Error
 */
function papelito_approve_seller_application( int $user_id, int $reviewer_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$status = papelito_get_seller_application_status( $user_id );
	if ( 'pending' !== $status ) {
		return new WP_Error(
			'papelito_invalid_state',
			sprintf( 'Triagem nao esta pendente (status atual: %s).', $status ),
			array( 'status' => 409 )
		);
	}

	$user->set_role( 'seller' );

	update_user_meta( $user_id, 'seller_application_status', 'approved' );
	update_user_meta( $user_id, 'seller_application_reviewed_at', current_time( 'mysql' ) );
	update_user_meta( $user_id, 'seller_application_reviewed_by', $reviewer_id );
	delete_user_meta( $user_id, 'seller_application_rejection_reason' );

	papelito_notify_vendor_decision( $user, 'approved' );

	return papelito_get_vendor_application_detail( $user_id );
}

/**
 * Rejeita a triagem do vendor.
 *
 * @param int    $user_id
 * @param int    $reviewer_id
 * @param string $reason
 * @return array<string, mixed>|WP_Error
 */
function papelito_reject_seller_application( int $user_id, int $reviewer_id, string $reason ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$status = papelito_get_seller_application_status( $user_id );
	if ( 'pending' !== $status ) {
		return new WP_Error(
			'papelito_invalid_state',
			sprintf( 'Triagem nao esta pendente (status atual: %s).', $status ),
			array( 'status' => 409 )
		);
	}

	$clean_reason = sanitize_text_field( $reason );

	update_user_meta( $user_id, 'seller_application_status', 'rejected' );
	update_user_meta( $user_id, 'seller_application_reviewed_at', current_time( 'mysql' ) );
	update_user_meta( $user_id, 'seller_application_reviewed_by', $reviewer_id );
	update_user_meta( $user_id, 'seller_application_rejection_reason', $clean_reason );

	papelito_notify_vendor_decision( $user, 'rejected', $clean_reason );

	return papelito_get_vendor_application_detail( $user_id );
}

add_action(
	'rest_api_init',
	static function (): void {
		$permission = static function (): bool {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			'papelito/v1/admin',
			'/vendor-applications/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $value ): bool {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$detail = papelito_get_vendor_application_detail( (int) $request['id'] );
					if ( is_wp_error( $detail ) ) {
						return $detail;
					}
					return new WP_REST_Response( $detail, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/vendor-applications/(?P<id>\d+)/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $permission,
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_approve_seller_application( (int) $request['id'], get_current_user_id() );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return new WP_REST_Response( $result, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/vendor-applications/(?P<id>\d+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $permission,
				'callback'            => static function ( WP_REST_Request $request ) {
					$body   = $request->get_json_params();
					$reason = is_array( $body ) && isset( $body['reason'] ) ? (string) $body['reason'] : '';
					$result = papelito_reject_seller_application( (int) $request['id'], get_current_user_id(), $reason );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return new WP_REST_Response( $result, 200 );
				},
			)
		);
	}
);
