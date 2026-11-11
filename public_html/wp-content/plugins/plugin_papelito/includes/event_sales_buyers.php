<?php
/**
 * Comprador do estande: consulta de CNPJ na Receita e cadastro rápido de empresa, usuário e vínculo.
 *
 * O lojista não tem conta quando chega ao estande. O operador digita o CNPJ, confere a loja e
 * informa nome, WhatsApp e e-mail; este módulo cria (ou reaproveita) a empresa pelo CNPJ, o usuário
 * `customer` pelo e-mail e o vínculo `buyer` com origem `event`, e manda o e-mail de definir senha.
 *
 * Regra de ouro: repetir o cadastro não duplica nada e nunca rebaixa um vínculo que já existe. O
 * vínculo novo nasce `pending_company_approval`; a venda do estande não depende dele, porque quem
 * vende é o operador.
 *
 * O que este módulo não faz: não aprova empresa, não verifica identidade e não grava CPF.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_CNPJ_INVALID = 'papelito_event_cnpj_invalid';
const PAPELITO_EVENT_SALES_BAD_REQUEST  = 400;

/**
 * Erro do comprador com o status HTTP no formato do REST do WordPress.
 *
 * @param string $code    Código do erro.
 * @param string $message Mensagem para o operador.
 * @param int    $status  Status HTTP.
 * @return WP_Error
 */
function papelito_event_sales_buyer_error( string $code, string $message, int $status = PAPELITO_EVENT_SALES_BAD_REQUEST ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

/**
 * Traduz o status da consulta de CNPJ no erro que o tablet mostra.
 *
 * @param string $status Status normalizado de `papelito_cnpj_lookup()`.
 * @return WP_Error
 */
function papelito_event_sales_lookup_error( string $status ): WP_Error {
	if ( 'invalid' === $status ) {
		return papelito_event_sales_buyer_error( PAPELITO_EVENT_SALES_CNPJ_INVALID, 'Esse CNPJ não existe.' );
	}

	if ( 'inactive' === $status ) {
		return papelito_event_sales_buyer_error( 'papelito_event_cnpj_inactive', 'Esse CNPJ está inativo na Receita.', 422 );
	}

	if ( 'not_found' === $status ) {
		return papelito_event_sales_buyer_error( 'papelito_event_cnpj_not_found', 'Não achamos esse CNPJ na Receita.', 404 );
	}

	return papelito_event_sales_buyer_error( 'papelito_event_cnpj_unavailable', 'A Receita não respondeu.', 503 );
}

/**
 * Consulta o CNPJ e devolve a loja no formato do tablet, ou o erro do status da Receita.
 *
 * Número e bairro voltam vazios porque nenhum provedor os traz; a tela de dados pede os dois.
 *
 * @param string $raw_cnpj CNPJ como digitado.
 * @return array<string,mixed>|WP_Error
 */
function papelito_event_sales_lookup_buyer( string $raw_cnpj ): array|WP_Error {
	$cnpj = papelito_normalize_cnpj( $raw_cnpj );
	if ( '' === $cnpj || ! papelito_validate_cnpj( $cnpj ) ) {
		return papelito_event_sales_lookup_error( 'invalid' );
	}

	$result = papelito_cnpj_lookup( $cnpj );
	$status = (string) ( $result['status'] ?? '' );
	if ( 'active' !== $status ) {
		return papelito_event_sales_lookup_error( $status );
	}

	$legal   = trim( (string) ( $result['legal_name'] ?? '' ) );
	$trade   = trim( (string) ( $result['trade_name'] ?? '' ) );
	$address = is_array( $result['fiscal_address'] ?? null ) ? $result['fiscal_address'] : array();
	$company = papelito_company_find_by_cnpj( $cnpj );

	return array(
		'cnpj'      => $cnpj,
		'legalName' => $legal,
		'tradeName' => '' !== $trade ? $trade : $legal,
		'address'   => array(
			'cep'          => preg_replace( '/\D+/', '', (string) ( $address['cep'] ?? '' ) ),
			'street'       => (string) ( $address['street'] ?? '' ),
			'city'         => (string) ( $address['city'] ?? '' ),
			'state'        => strtoupper( (string) ( $address['state'] ?? '' ) ),
			'number'       => '',
			'neighborhood' => '',
		),
		'companyId' => null === $company ? null : (int) $company['id'],
	);
}

/**
 * Valida e normaliza os dados do comprador digitados no tablet.
 *
 * @param array<string,mixed> $input `cnpj`, `name`, `phone` e `email`.
 * @return array{cnpj:string,name:string,phone:string,email:string}|WP_Error
 */
function papelito_event_sales_validate_buyer_input( array $input ): array|WP_Error {
	$cnpj  = papelito_normalize_cnpj( (string) ( $input['cnpj'] ?? '' ) );
	$name  = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $input['name'] ?? '' ) ) ) );
	$phone = papelito_auth_normalize_phone( (string) ( $input['phone'] ?? '' ) );
	$email = papelito_normalize_email( (string) ( $input['email'] ?? '' ) );

	if ( '' === $cnpj || ! papelito_validate_cnpj( $cnpj ) ) {
		return papelito_event_sales_lookup_error( 'invalid' );
	}

	if ( 1 !== preg_match( '/^.{2,}$/u', $name ) ) {
		return papelito_event_sales_buyer_error( 'papelito_event_buyer_name', 'Informe o nome de quem compra.' );
	}

	if ( ! in_array( strlen( $phone ), array( 10, 11 ), true ) ) {
		return papelito_event_sales_buyer_error( 'papelito_event_buyer_phone', 'O WhatsApp precisa do DDD e do número.' );
	}

	if ( ! is_email( $email ) ) {
		return papelito_event_sales_buyer_error( 'papelito_event_buyer_email', 'Confira o e-mail.' );
	}

	return array(
		'cnpj'  => $cnpj,
		'name'  => $name,
		'phone' => $phone,
		'email' => $email,
	);
}

/**
 * Separa o nome digitado em primeiro nome e sobrenome.
 *
 * @param string $name Nome completo.
 * @return array{0:string,1:string}
 */
function papelito_event_sales_split_name( string $name ): array {
	$parts = explode( ' ', trim( $name ), 2 );

	return array( $parts[0], $parts[1] ?? '' );
}

/**
 * Reaproveita o usuário do e-mail ou cria um `customer` sem senha escolhida.
 *
 * Conta nova recebe o e-mail de definir senha; falha no envio não impede a venda.
 *
 * @param array{cnpj:string,name:string,phone:string,email:string} $buyer Dados validados.
 * @return array{user_id:int,created:bool}|WP_Error
 */
function papelito_event_sales_find_or_create_user( array $buyer ): array|WP_Error {
	$existing = get_user_by( 'email', $buyer['email'] );
	if ( $existing instanceof WP_User ) {
		return array(
			'user_id' => (int) $existing->ID,
			'created' => false,
		);
	}

	list( $first, $last ) = papelito_event_sales_split_name( $buyer['name'] );

	$user_id = wp_insert_user(
		array(
			'user_login' => $buyer['email'],
			'user_email' => $buyer['email'],
			'user_pass'  => wp_generate_password( 32, true, true ),
			'first_name' => $first,
			'last_name'  => $last,
			'role'       => 'customer',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	update_user_meta( $user_id, 'billing_phone', $buyer['phone'] );
	update_user_meta( $user_id, 'phone_number', papelito_auth_format_phone( $buyer['phone'] ) );
	papelito_auth_mark_email_pending( $user_id );
	papelito_auth_mark_password_unset( $user_id );

	$user = get_userdata( $user_id );
	if ( $user instanceof WP_User ) {
		papelito_auth_dispatch_password_reset_email( $user );
	}

	return array(
		'user_id' => (int) $user_id,
		'created' => true,
	);
}

/**
 * Reaproveita a empresa do CNPJ ou cria uma com os dados da Receita.
 *
 * @param int                                                      $operator_id Operador que cadastra.
 * @param array{cnpj:string,name:string,phone:string,email:string} $buyer       Dados validados.
 * @return array{company_id:int,created:bool}|WP_Error
 */
function papelito_event_sales_find_or_create_company( int $operator_id, array $buyer ): array|WP_Error {
	$existing = papelito_company_find_by_cnpj( $buyer['cnpj'] );
	if ( null !== $existing ) {
		return array(
			'company_id' => (int) $existing['id'],
			'created'    => false,
		);
	}

	$lookup = papelito_event_sales_lookup_buyer( $buyer['cnpj'] );
	if ( is_wp_error( $lookup ) ) {
		return $lookup;
	}

	$company_id = papelito_company_create(
		$buyer['cnpj'],
		array(
			'legal_name'         => $lookup['legalName'],
			'trade_name'         => $lookup['tradeName'],
			'billing_email'      => $buyer['email'],
			'phone'              => $buyer['phone'],
			'created_by_user_id' => $operator_id,
			'registry_status'    => 'active',
			'fiscal_cep'         => $lookup['address']['cep'],
			'fiscal_state'       => $lookup['address']['state'],
			'fiscal_city'        => $lookup['address']['city'],
			'fiscal_street'      => $lookup['address']['street'],
		)
	);

	if ( is_wp_error( $company_id ) ) {
		return $company_id;
	}

	return array(
		'company_id' => (int) $company_id,
		'created'    => true,
	);
}

/**
 * Cadastra o comprador do estande: empresa pelo CNPJ, usuário pelo e-mail e vínculo `buyer`.
 *
 * Idempotente: repetir com o mesmo CNPJ e e-mail devolve os mesmos ids e `created` falso.
 *
 * @param int                 $operator_id Operador que cadastra.
 * @param array<string,mixed> $input       `cnpj`, `name`, `phone` e `email`.
 * @return array{companyId:int,buyerUserId:int,created:array{company:bool,user:bool}}|WP_Error
 */
function papelito_event_sales_upsert_buyer( int $operator_id, array $input ): array|WP_Error {
	$buyer = papelito_event_sales_validate_buyer_input( $input );
	if ( is_wp_error( $buyer ) ) {
		return $buyer;
	}

	$company = papelito_event_sales_find_or_create_company( $operator_id, $buyer );
	if ( is_wp_error( $company ) ) {
		return $company;
	}

	$user = papelito_event_sales_find_or_create_user( $buyer );
	if ( is_wp_error( $user ) ) {
		return $user;
	}

	if ( null === papelito_company_member_get( $company['company_id'], $user['user_id'] ) ) {
		$member = papelito_company_member_upsert(
			$company['company_id'],
			$user['user_id'],
			array(
				'member_role'       => 'buyer',
				'member_status'     => 'pending_company_approval',
				'membership_origin' => 'event',
			)
		);
		if ( is_wp_error( $member ) ) {
			return $member;
		}
	}

	return array(
		'companyId'   => $company['company_id'],
		'buyerUserId' => $user['user_id'],
		'created'     => array(
			'company' => $company['created'],
			'user'    => $user['created'],
		),
	);
}

/**
 * Corpo JSON da requisição como array, vazio quando não há corpo válido.
 *
 * @param WP_REST_Request $request Requisição.
 * @return array<string,mixed>
 */
function papelito_event_sales_json_body( WP_REST_Request $request ): array {
	$body = $request->get_json_params();

	return is_array( $body ) ? $body : array();
}

/**
 * Converte o resultado de uma ação em resposta REST sem cache, devolvendo o erro como veio.
 *
 * @param array<string,mixed>|WP_Error $result Resultado.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_respond( array|WP_Error $result ): WP_REST_Response|WP_Error {
	return is_wp_error( $result ) ? $result : papelito_event_sales_response( $result );
}

/**
 * Cota de consultas de CNPJ por operador, para não estourar o plano dos provedores.
 *
 * @return true|WP_Error
 */
function papelito_event_sales_cnpj_rate_limit(): bool|WP_Error {
	if ( papelito_rate_limit( 'event_cnpj', 'user:' . get_current_user_id(), 60, 60 ) ) {
		return true;
	}

	return papelito_event_sales_buyer_error( 'papelito_event_rate_limited', 'Muitas consultas seguidas. Espere um minuto.', 429 );
}

/**
 * `POST event/{id}/buyers/lookup`.
 *
 * @param WP_REST_Request $request Requisição com `cnpj`.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_lookup_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$allowed = papelito_event_sales_cnpj_rate_limit();
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	return papelito_event_sales_respond( papelito_event_sales_lookup_buyer( (string) ( papelito_event_sales_json_body( $request )['cnpj'] ?? '' ) ) );
}

/**
 * `POST event/{id}/buyers`.
 *
 * @param WP_REST_Request $request Requisição com `cnpj`, `name`, `phone` e `email`.
 * @return WP_REST_Response|WP_Error
 */
function papelito_event_sales_upsert_buyer_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$allowed = papelito_event_sales_cnpj_rate_limit();
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	return papelito_event_sales_respond( papelito_event_sales_upsert_buyer( get_current_user_id(), papelito_event_sales_json_body( $request ) ) );
}

/**
 * Registra as rotas de comprador do evento.
 *
 * @return void
 */
function papelito_event_sales_register_buyer_routes(): void {
	papelito_event_sales_register_route(
		'/buyers/lookup',
		array(
			'methods'             => 'POST',
			'callback'            => 'papelito_event_sales_lookup_endpoint',
			'permission_callback' => 'papelito_event_sales_require_seller',
		)
	);

	papelito_event_sales_register_route(
		'/buyers',
		array(
			'methods'             => 'POST',
			'callback'            => 'papelito_event_sales_upsert_buyer_endpoint',
			'permission_callback' => 'papelito_event_sales_require_seller',
		)
	);
}
add_action( 'rest_api_init', 'papelito_event_sales_register_buyer_routes' );
