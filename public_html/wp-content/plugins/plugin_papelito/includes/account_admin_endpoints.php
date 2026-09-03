<?php
/**
 * Rotas administrativas de estado de conta e de empresa.
 *
 * Suspender e reativar são as duas únicas ações que mudam o estado comercial de uma conta ou de
 * uma empresa. Ambas exigem justificativa na suspensão e ficam registradas em histórico — a de
 * conta em `papelito_account_status_log`, a de empresa na auditoria da própria empresa.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Permissão das rotas administrativas de conta.
 */
function papelito_account_admin_require_capability(): bool {
	return current_user_can( 'manage_options' ) || current_user_can( 'papelito_manage_companies' );
}

/**
 * Extrai a justificativa do corpo da requisição.
 */
function papelito_account_admin_request_reason( WP_REST_Request $request ): string {
	$body = $request->get_json_params();

	return is_array( $body ) ? (string) ( $body['reason'] ?? '' ) : '';
}

/**
 * POST /admin/users/{id}/suspend
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_account_admin_handle_suspend_user( WP_REST_Request $request ) {
	$result = papelito_account_suspend(
		absint( $request->get_param( 'id' ) ),
		get_current_user_id(),
		papelito_account_admin_request_reason( $request )
	);

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * POST /admin/users/{id}/reactivate
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_account_admin_handle_reactivate_user( WP_REST_Request $request ) {
	$result = papelito_account_reactivate(
		absint( $request->get_param( 'id' ) ),
		get_current_user_id(),
		papelito_account_admin_request_reason( $request )
	);

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * GET /admin/users/{id}/status-history
 */
function papelito_account_admin_handle_user_history( WP_REST_Request $request ): WP_REST_Response {
	$user_id = absint( $request->get_param( 'id' ) );

	return new WP_REST_Response(
		array(
			'userId'     => $user_id,
			'status'     => papelito_account_status( $user_id ),
			'suspension' => papelito_account_suspension_details( $user_id ),
			'events'     => papelito_account_status_history( $user_id ),
		),
		200
	);
}

/**
 * Muda o `company_status` da empresa, registrando auditoria.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_account_admin_transition_company( int $company_id, string $target_status, int $actor_user_id, string $raw_reason ) {
	$company = papelito_company_get( $company_id );

	if ( ! is_array( $company ) ) {
		return new WP_Error( 'papelito_b2b_company_not_found', 'Empresa não encontrada.', array( 'status' => 404 ) );
	}

	$current = (string) $company['company_status'];

	if ( 'archived' === $current ) {
		return new WP_Error( 'papelito_company_archived', 'Empresa arquivada não muda de estado por aqui.', array( 'status' => 409 ) );
	}

	$reason = papelito_account_normalize_reason( $raw_reason, 'suspended' === $target_status );

	if ( is_wp_error( $reason ) ) {
		return $reason;
	}

	if ( $current === $target_status ) {
		return array(
			'companyId'     => $company_id,
			'companyStatus' => $current,
			'replayed'      => true,
		);
	}

	if ( 'active' === $target_status && 'verified' !== (string) $company['ownership_status'] ) {
		return new WP_Error(
			'papelito_company_ownership_not_verified',
			'A empresa precisa ter titularidade verificada para voltar a operar.',
			array( 'status' => 409 )
		);
	}

	$updated = papelito_company_update( $company_id, array( 'company_status' => $target_status ) );

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	papelito_company_audit_log(
		$company_id,
		'suspended' === $target_status ? 'company_suspended' : 'company_reactivated',
		$actor_user_id,
		array(
			'reason'         => $reason,
			'previousStatus' => $current,
		)
	);

	return array(
		'companyId'     => $company_id,
		'companyStatus' => $target_status,
		'replayed'      => false,
	);
}

/**
 * POST /admin/companies/{id}/suspend
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_account_admin_handle_suspend_company( WP_REST_Request $request ) {
	$result = papelito_account_admin_transition_company(
		absint( $request->get_param( 'id' ) ),
		'suspended',
		get_current_user_id(),
		papelito_account_admin_request_reason( $request )
	);

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * POST /admin/companies/{id}/reactivate
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_account_admin_handle_reactivate_company( WP_REST_Request $request ) {
	$result = papelito_account_admin_transition_company(
		absint( $request->get_param( 'id' ) ),
		'active',
		get_current_user_id(),
		papelito_account_admin_request_reason( $request )
	);

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		$routes = array(
			'/users/(?P<id>\d+)/suspend'      => 'papelito_account_admin_handle_suspend_user',
			'/users/(?P<id>\d+)/reactivate'   => 'papelito_account_admin_handle_reactivate_user',
			'/companies/(?P<id>\d+)/suspend'    => 'papelito_account_admin_handle_suspend_company',
			'/companies/(?P<id>\d+)/reactivate' => 'papelito_account_admin_handle_reactivate_company',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				'papelito/v1/admin',
				$route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_account_admin_require_capability',
					'callback'            => $callback,
				)
			);
		}

		register_rest_route(
			'papelito/v1/admin',
			'/users/(?P<id>\d+)/status-history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_account_admin_require_capability',
				'callback'            => 'papelito_account_admin_handle_user_history',
			)
		);
	}
);
