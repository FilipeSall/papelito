<?php
/**
 * Endpoints REST do painel da empresa B2B (Fase 1B).
 *
 * Todas as rotas /companies/current/* operam sobre a EMPRESA ATIVA recarregada pelo backend a
 * partir da sessão — nunca sobre companyId vindo do navegador. Mutações exigem Idempotency-Key
 * durável (company_idempotency.php), passam por rate limit e pela matriz RBAC (company_authz.php),
 * e retornam erros estáveis + auditoria sem PII.
 *
 * Rotas:
 *   POST   /companies/request-access
 *   GET    /companies/current
 *   POST   /companies/current/select
 *   GET    /companies/current/members
 *   PATCH  /companies/current/members/{id}
 *   DELETE /companies/current/members/{id}
 *   GET    /companies/current/invitations
 *   POST   /companies/current/invitations
 *   POST   /companies/current/invitations/{id}/resend
 *   DELETE /companies/current/invitations/{id}
 *   GET    /companies/current/access-requests
 *   POST   /companies/current/access-requests/{id}/approve
 *   POST   /companies/current/access-requests/{id}/reject
 *   POST   /companies/current/transfer-ownership
 *   GET    /company-invitations/{token}         (preview neutro pós-clique)
 *   POST   /company-invitations/{token}/accept
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Permission callback compartilhado: exige usuário autenticado.
 */
function papelito_company_mgmt_permission(): bool {
	return get_current_user_id() > 0;
}

/**
 * Resolve o id da EMPRESA ATIVA do usuário (server-side). Erro estável se não houver ativa.
 *
 * @return int|WP_Error
 */
function papelito_company_mgmt_active_company_id( int $user_id ) {
	$context = papelito_company_context( $user_id );

	if ( ! empty( $context['companySelectionRequired'] ) ) {
		return new WP_Error( 'papelito_b2b_company_selection_required', 'Selecione a empresa ativa.', array( 'status' => 409 ) );
	}
	if ( empty( $context['companyId'] ) || 'complete' !== ( $context['onboardingStatus'] ?? '' ) ) {
		return new WP_Error( 'papelito_b2b_no_active_company', 'Nenhuma empresa ativa.', array( 'status' => 409 ) );
	}

	return (int) $context['companyId'];
}

/**
 * Envelope de idempotência para mutações: executa $run apenas uma vez por (actor, op, key).
 *
 * @param callable $run function(): int|WP_Error — retorna o resource_id afetado.
 * @return array{replayed:bool,resourceId:int}|WP_Error
 */
function papelito_company_mgmt_idempotent( WP_REST_Request $request, int $actor, string $operation, array $intent, callable $run ) {
	$key  = (string) $request->get_header( 'Idempotency-Key' );
	$hash = papelito_company_idempotency_request_hash( $intent );

	$previous = papelito_company_idempotency_check( $actor, $operation, $key, $hash );
	if ( isset( $previous['error'] ) ) {
		return $previous['error'];
	}
	if ( null !== $previous ) {
		return array(
			'replayed'   => true,
			'resourceId' => (int) $previous['resource_id'],
		);
	}

	$result = $run();
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$resource_id = (int) $result;
	papelito_company_idempotency_store( $actor, $operation, $key, $hash, $resource_id, 200 );

	return array(
		'replayed'   => false,
		'resourceId' => $resource_id,
	);
}

/**
 * Serializa um membro para a listagem (sem PII).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function papelito_company_mgmt_member_view( array $row ): array {
	$user = get_userdata( (int) $row['user_id'] );

	return array(
		'memberId'    => (int) $row['id'],
		'userId'      => (int) $row['user_id'],
		'displayName' => $user instanceof WP_User ? $user->display_name : '',
		'email'       => $user instanceof WP_User ? $user->user_email : '',
		'role'        => (string) $row['member_role'],
		'status'      => (string) $row['member_status'],
		'origin'      => (string) ( $row['membership_origin'] ?? '' ),
		'expiresAt'   => $row['expires_at'] ?? null,
	);
}

/**
 * Serializa um convite para a listagem (sem token).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function papelito_company_mgmt_invitation_view( array $row ): array {
	return array(
		'invitationId' => (int) $row['id'],
		'email'        => (string) $row['invited_email'],
		'role'         => (string) $row['invited_role'],
		'status'       => (string) $row['invitation_status'],
		'cpfLocked'    => ! empty( $row['invited_cpf_hmac'] ),
		'expiresAt'    => $row['expires_at'] ?? null,
		'resendCount'  => (int) ( $row['resend_count'] ?? 0 ),
		'createdAt'    => $row['created_at'] ?? null,
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		$ns = 'papelito/v1';

		/* --- Solicitar acesso a empresa existente --- */
		register_rest_route(
			$ns,
			'/companies/request-access',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function ( WP_REST_Request $r ) {
					if ( ! papelito_auth_rate_limit( 'company_request_access', 10, 60 ) ) {
						return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
					}
					$user_id = get_current_user_id();
					$body    = (array) $r->get_json_params();
					$cnpj    = isset( $body['cnpj'] ) ? (string) $body['cnpj'] : '';
					$result  = papelito_company_access_request_submit( $user_id, $cnpj );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 202 );
				},
			)
		);

		/* --- Contexto da empresa ativa --- */
		register_rest_route(
			$ns,
			'/companies/current',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static fn() => new WP_REST_Response( papelito_company_context( get_current_user_id() ), 200 ),
			)
		);

		/* --- Selecionar empresa ativa --- */
		register_rest_route(
			$ns,
			'/companies/current/select',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function ( WP_REST_Request $r ) {
					$user_id    = get_current_user_id();
					$company_id = (int) ( ( (array) $r->get_json_params() )['companyId'] ?? 0 );
					if ( $company_id <= 0 ) {
						return new WP_Error( 'papelito_b2b_invalid_company', 'Empresa inválida.', array( 'status' => 422 ) );
					}
					$selected = papelito_company_active_select( $user_id, $company_id );
					return is_wp_error( $selected ) ? $selected : new WP_REST_Response( papelito_company_context( $user_id ), 200 );
				},
			)
		);

		/* --- Membros --- */
		register_rest_route(
			$ns,
			'/companies/current/members',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function () {
					$user_id    = get_current_user_id();
					$company_id = papelito_company_mgmt_active_company_id( $user_id );
					if ( is_wp_error( $company_id ) ) {
						return $company_id;
					}
					$loaded = papelito_company_authz_load( $user_id, $company_id );
					if ( is_wp_error( $loaded ) ) {
						return $loaded;
					}
					if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
						return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
					}
					$rows = papelito_company_members_list( $company_id, array( 'active', 'suspended', 'pending_company_approval' ) );
					return new WP_REST_Response( array( 'items' => array_map( 'papelito_company_mgmt_member_view', $rows ) ), 200 );
				},
			)
		);

		register_rest_route(
			$ns,
			'/companies/current/members/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'permission_callback' => 'papelito_company_mgmt_permission',
					'callback'            => static function ( WP_REST_Request $r ) {
						$user_id    = get_current_user_id();
						$company_id = papelito_company_mgmt_active_company_id( $user_id );
						if ( is_wp_error( $company_id ) ) {
							return $company_id;
						}
						$member = papelito_company_member_get_by_id( (int) $r['id'] );
						if ( null === $member || (int) $member['company_id'] !== $company_id ) {
							return new WP_Error( 'papelito_b2b_member_not_found', 'Membro não encontrado.', array( 'status' => 404 ) );
						}
						$target_user_id = (int) $member['user_id'];
						$body           = (array) $r->get_json_params();

						$run = static function () use ( $user_id, $company_id, $target_user_id, $body ) {
							if ( isset( $body['role'] ) ) {
								$res = papelito_company_member_change_role( $user_id, $company_id, $target_user_id, (string) $body['role'] );
							} elseif ( isset( $body['status'] ) ) {
								$res = papelito_company_member_set_status( $user_id, $company_id, $target_user_id, (string) $body['status'] );
							} elseif ( array_key_exists( 'expiresAt', $body ) ) {
								$expires = empty( $body['expiresAt'] ) ? null : gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $body['expiresAt'] ) );
								$res     = papelito_company_member_set_expiration( $user_id, $company_id, $target_user_id, $expires );
							} else {
								return new WP_Error( 'papelito_b2b_no_op', 'Nada a atualizar.', array( 'status' => 422 ) );
							}
							return is_wp_error( $res ) ? $res : (int) ( $res['id'] ?? 0 );
						};

						$intent  = array(
							'member' => (int) $r['id'],
							'body'   => $body,
						);
						$outcome = papelito_company_mgmt_idempotent( $r, $user_id, 'member_patch', $intent, $run );
						if ( is_wp_error( $outcome ) ) {
							return $outcome;
						}
						return new WP_REST_Response(
							array(
								'ok'       => true,
								'replayed' => $outcome['replayed'],
							),
							200
						);
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => 'papelito_company_mgmt_permission',
					'callback'            => static function ( WP_REST_Request $r ) {
						$user_id    = get_current_user_id();
						$company_id = papelito_company_mgmt_active_company_id( $user_id );
						if ( is_wp_error( $company_id ) ) {
							return $company_id;
						}
						$member = papelito_company_member_get_by_id( (int) $r['id'] );
						if ( null === $member || (int) $member['company_id'] !== $company_id ) {
							return new WP_Error( 'papelito_b2b_member_not_found', 'Membro não encontrado.', array( 'status' => 404 ) );
						}
						$target_user_id = (int) $member['user_id'];
						$run     = static function () use ( $user_id, $company_id, $target_user_id ) {
							$res = papelito_company_member_set_status( $user_id, $company_id, $target_user_id, 'revoke' );
							return is_wp_error( $res ) ? $res : (int) ( $res['id'] ?? 0 );
						};
						$outcome = papelito_company_mgmt_idempotent( $r, $user_id, 'member_revoke', array( 'member' => (int) $r['id'] ), $run );
						if ( is_wp_error( $outcome ) ) {
							return $outcome;
						}
						return new WP_REST_Response(
							array(
								'ok'       => true,
								'replayed' => $outcome['replayed'],
							),
							200
						);
					},
				),
			)
		);

		/* --- Convites --- */
		register_rest_route(
			$ns,
			'/companies/current/invitations',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => 'papelito_company_mgmt_permission',
					'callback'            => static function () {
						$user_id    = get_current_user_id();
						$company_id = papelito_company_mgmt_active_company_id( $user_id );
						if ( is_wp_error( $company_id ) ) {
							return $company_id;
						}
						$loaded = papelito_company_authz_load( $user_id, $company_id );
						if ( is_wp_error( $loaded ) ) {
							return $loaded;
						}
						if ( ! papelito_company_authz_can_manage( $loaded['membership'] ) ) {
							return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida para seu papel.', array( 'status' => 403 ) );
						}
						$rows = papelito_company_invitations_list( $company_id );
						return new WP_REST_Response( array( 'items' => array_map( 'papelito_company_mgmt_invitation_view', $rows ) ), 200 );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => 'papelito_company_mgmt_permission',
					'callback'            => static function ( WP_REST_Request $r ) {
						if ( ! papelito_auth_rate_limit( 'company_invite', 20, 60 ) ) {
							return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
						}
						$user_id    = get_current_user_id();
						$company_id = papelito_company_mgmt_active_company_id( $user_id );
						if ( is_wp_error( $company_id ) ) {
							return $company_id;
						}
						$body = (array) $r->get_json_params();
						$run  = static function () use ( $user_id, $company_id, $body ) {
							$res = papelito_company_invitation_issue( $user_id, $company_id, $body );
							return is_wp_error( $res ) ? $res : (int) $res['id'];
						};
						$intent  = array(
							'email' => strtolower( (string) ( $body['invited_email'] ?? '' ) ),
							'role'  => (string) ( $body['invited_role'] ?? '' ),
						);
						$outcome = papelito_company_mgmt_idempotent( $r, $user_id, 'invitation_create', $intent, $run );
						if ( is_wp_error( $outcome ) ) {
							return $outcome;
						}
						return new WP_REST_Response(
							array(
								'invitationId' => $outcome['resourceId'],
								'replayed'     => $outcome['replayed'],
							),
							201
						);
					},
				),
			)
		);

		register_rest_route(
			$ns,
			'/companies/current/invitations/(?P<id>\d+)/resend',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function ( WP_REST_Request $r ) {
					if ( ! papelito_auth_rate_limit( 'company_invite_resend', 20, 60 ) ) {
						return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
					}
					$user_id    = get_current_user_id();
					$company_id = papelito_company_mgmt_active_company_id( $user_id );
					if ( is_wp_error( $company_id ) ) {
						return $company_id;
					}
					$invitation_id = (int) $r['id'];
					$run = static function () use ( $user_id, $company_id, $invitation_id ) {
						$res = papelito_company_invitation_reissue( $user_id, $company_id, $invitation_id );
						return is_wp_error( $res ) ? $res : (int) $res['id'];
					};
					$outcome = papelito_company_mgmt_idempotent( $r, $user_id, 'invitation_resend', array( 'invitation' => $invitation_id ), $run );
					if ( is_wp_error( $outcome ) ) {
						return $outcome;
					}
					return new WP_REST_Response(
						array(
							'ok'       => true,
							'replayed' => $outcome['replayed'],
						),
						200
					);
				},
			)
		);

		register_rest_route(
			$ns,
			'/companies/current/invitations/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function ( WP_REST_Request $r ) {
					$user_id    = get_current_user_id();
					$company_id = papelito_company_mgmt_active_company_id( $user_id );
					if ( is_wp_error( $company_id ) ) {
						return $company_id;
					}
					$invitation_id = (int) $r['id'];
					$reason        = sanitize_text_field( (string) ( ( (array) $r->get_json_params() )['reason'] ?? '' ) );
					$run = static function () use ( $user_id, $company_id, $invitation_id, $reason ) {
						$res = papelito_company_invitation_cancel( $user_id, $company_id, $invitation_id, $reason );
						return is_wp_error( $res ) ? $res : $invitation_id;
					};
					$outcome = papelito_company_mgmt_idempotent( $r, $user_id, 'invitation_revoke', array( 'invitation' => $invitation_id ), $run );
					if ( is_wp_error( $outcome ) ) {
						return $outcome;
					}
					return new WP_REST_Response(
						array(
							'ok'       => true,
							'replayed' => $outcome['replayed'],
						),
						200
					);
				},
			)
		);

		/* --- Solicitações de acesso --- */
		register_rest_route(
			$ns,
			'/companies/current/access-requests',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function () {
					$user_id    = get_current_user_id();
					$company_id = papelito_company_mgmt_active_company_id( $user_id );
					if ( is_wp_error( $company_id ) ) {
						return $company_id;
					}
					$list = papelito_company_access_requests_list( $user_id, $company_id );
					return is_wp_error( $list ) ? $list : new WP_REST_Response( array( 'items' => $list ), 200 );
				},
			)
		);

		register_rest_route(
			$ns,
			'/companies/current/access-requests/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function ( WP_REST_Request $r ) {
					$user_id    = get_current_user_id();
					$company_id = papelito_company_mgmt_active_company_id( $user_id );
					if ( is_wp_error( $company_id ) ) {
						return $company_id;
					}
					$member = papelito_company_member_get_by_id( (int) $r['id'] );
					if ( null === $member || (int) $member['company_id'] !== $company_id ) {
						return new WP_Error( 'papelito_b2b_member_not_found', 'Solicitação não encontrada.', array( 'status' => 404 ) );
					}
					$target_user_id = (int) $member['user_id'];
					$role           = (string) ( ( (array) $r->get_json_params() )['role'] ?? 'buyer' );
					$run = static function () use ( $user_id, $company_id, $target_user_id, $role ) {
						$res = papelito_company_access_request_approve( $user_id, $company_id, $target_user_id, $role );
						return is_wp_error( $res ) ? $res : (int) ( $res['id'] ?? 0 );
					};
					$outcome = papelito_company_mgmt_idempotent(
						$r,
						$user_id,
						'access_request_approve',
						array(
							'member' => (int) $r['id'],
							'role'   => $role,
						),
						$run
					);
					if ( is_wp_error( $outcome ) ) {
						return $outcome;
					}
					return new WP_REST_Response(
						array(
							'ok'       => true,
							'replayed' => $outcome['replayed'],
						),
						200
					);
				},
			)
		);

		register_rest_route(
			$ns,
			'/companies/current/access-requests/(?P<id>\d+)/reject',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function ( WP_REST_Request $r ) {
					$user_id    = get_current_user_id();
					$company_id = papelito_company_mgmt_active_company_id( $user_id );
					if ( is_wp_error( $company_id ) ) {
						return $company_id;
					}
					$member = papelito_company_member_get_by_id( (int) $r['id'] );
					if ( null === $member || (int) $member['company_id'] !== $company_id ) {
						return new WP_Error( 'papelito_b2b_member_not_found', 'Solicitação não encontrada.', array( 'status' => 404 ) );
					}
					$target_user_id = (int) $member['user_id'];
					$member_id      = (int) $member['id'];
					$reason         = sanitize_text_field( (string) ( ( (array) $r->get_json_params() )['reason'] ?? '' ) );
					$run = static function () use ( $user_id, $company_id, $target_user_id, $member_id, $reason ) {
						$res = papelito_company_access_request_reject( $user_id, $company_id, $target_user_id, $reason );
						return is_wp_error( $res ) ? $res : $member_id;
					};
					$outcome = papelito_company_mgmt_idempotent(
						$r,
						$user_id,
						'access_request_reject',
						array(
							'member' => (int) $r['id'],
							'reason' => $reason,
						),
						$run
					);
					if ( is_wp_error( $outcome ) ) {
						return $outcome;
					}
					return new WP_REST_Response(
						array(
							'ok'       => true,
							'replayed' => $outcome['replayed'],
						),
						200
					);
				},
			)
		);

		/* --- Transferência de ownership --- */
		register_rest_route(
			$ns,
			'/companies/current/transfer-ownership',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function ( WP_REST_Request $r ) {
					$user_id    = get_current_user_id();
					$company_id = papelito_company_mgmt_active_company_id( $user_id );
					if ( is_wp_error( $company_id ) ) {
						return $company_id;
					}
					$target_user_id = (int) ( ( (array) $r->get_json_params() )['targetUserId'] ?? 0 );
					if ( $target_user_id <= 0 ) {
						return new WP_Error( 'papelito_b2b_invalid_target', 'Destinatário inválido.', array( 'status' => 422 ) );
					}
					$run = static function () use ( $user_id, $company_id, $target_user_id ) {
						$res = papelito_company_transfer_ownership( $user_id, $company_id, $target_user_id );
						return is_wp_error( $res ) ? $res : $company_id;
					};
					$outcome = papelito_company_mgmt_idempotent( $r, $user_id, 'transfer_ownership', array( 'target' => $target_user_id ), $run );
					if ( is_wp_error( $outcome ) ) {
						return $outcome;
					}
					return new WP_REST_Response(
						array(
							'ok'       => true,
							'replayed' => $outcome['replayed'],
						),
						200
					);
				},
			)
		);

		/* --- Convites por token (público autenticado): preview + aceite --- */
		register_rest_route(
			$ns,
			'/company-invitations/(?P<token>[A-Za-z0-9]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					if ( ! papelito_auth_rate_limit( 'invitation_preview', 30, 60 ) ) {
						return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
					}
					$result = papelito_company_invitation_preview( (string) $r['token'] );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
			)
		);

		register_rest_route(
			$ns,
			'/company-invitations/(?P<token>[A-Za-z0-9]+)/accept',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback'            => static function ( WP_REST_Request $r ) {
					if ( ! papelito_auth_rate_limit( 'invitation_accept', 20, 60 ) ) {
						return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', array( 'status' => 429 ) );
					}
					$user_id = get_current_user_id();
					$result  = papelito_company_invitation_accept_token( $user_id, (string) $r['token'] );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
			)
		);
	}
);
