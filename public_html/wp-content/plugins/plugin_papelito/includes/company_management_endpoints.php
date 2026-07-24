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
	$writes = papelito_b2b_require_company_writes();
	if ( is_wp_error( $writes ) ) {
		return $writes;
	}
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
		'status'      => papelito_company_member_is_operationally_active( $row ) ? (string) $row['member_status'] : 'expired',
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

function papelito_company_mgmt_send_billing_email_confirmation( int $company_id, string $email ) {
	$token   = wp_generate_password( 48, false, false );
	$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
	$updated = papelito_company_update( $company_id, array( 'pending_billing_email' => $email, 'pending_billing_email_token_hash' => hash( 'sha256', $token ), 'pending_billing_email_expires_at' => $expires, 'billing_email_verification_sent_at' => current_time( 'mysql', true ) ) );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	$base = rtrim( (string) papelito_env( 'PAPELITO_FRONTEND_URL', 'http://localhost:3000' ), '/' );
	wp_mail( $email, 'Confirme o e-mail de faturamento da Papelito', "Confirme o e-mail de faturamento: {$base}/confirmar-email-faturamento?token=" . rawurlencode( $token ) );
	return true;
}

function papelito_company_mgmt_update_details( int $actor, int $company_id, array $body ) {
	$loaded = papelito_company_authz_load( $actor, $company_id );
	if ( is_wp_error( $loaded ) || ! papelito_company_authz_can_manage( $loaded['membership'] ?? array() ) ) {
		return is_wp_error( $loaded ) ? $loaded : new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );
	}
	$company = $loaded['company'];
	if ( array_key_exists( 'phone', $body ) ) {
		$phone = sanitize_text_field( (string) $body['phone'] );
		$result = papelito_company_update( $company_id, array( 'phone' => $phone ?: null ) );
		if ( is_wp_error( $result ) ) { return $result; }
		papelito_company_audit( $company_id, $actor, 'company_phone_updated', array( 'target_user_id' => $actor ) );
	}
	if ( array_key_exists( 'billingEmail', $body ) ) {
		$email = sanitize_email( (string) $body['billingEmail'] );
		if ( '' === $email || ! is_email( $email ) ) { return new WP_Error( 'papelito_b2b_invalid_billing_email', 'E-mail de faturamento inválido.', array( 'status' => 422 ) ); }
		$result = papelito_company_mgmt_send_billing_email_confirmation( $company_id, $email );
		if ( is_wp_error( $result ) ) { return $result; }
		papelito_company_audit( $company_id, $actor, 'billing_email_confirmation_requested', array( 'target_user_id' => $actor ) );
	}
	return true;
}

function papelito_company_mgmt_audit_view( int $company_id, int $actor, array $row ): array {
	$payload = json_decode( (string) $row['payload_json'], true );
	$payload = is_array( $payload ) ? $payload : array();
	$target  = (int) ( $payload['target_user_id'] ?? $payload['requester_user_id'] ?? 0 );
	$person = static function ( int $user_id ) use ( $company_id ): ?array {
		if ( $user_id <= 0 ) { return null; }
		$user = get_userdata( $user_id ); $member = papelito_company_member_get( $company_id, $user_id );
		return array( 'displayName' => $user instanceof WP_User ? $user->display_name : 'Usuário removido', 'role' => $member['member_role'] ?? null );
	};
	return array( 'action' => (string) $row['action'], 'createdAt' => (string) $row['created_at'], 'actor' => $person( (int) $row['actor_user_id'] ), 'target' => $person( $target ) );
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
					$writes = papelito_b2b_require_company_writes(); if ( is_wp_error( $writes ) ) { return $writes; }
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

		register_rest_route(
			$ns,
			'/companies/current',
			array(
				'methods' => 'PATCH',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback' => static function ( WP_REST_Request $r ) {
					$actor = get_current_user_id(); $company_id = papelito_company_mgmt_active_company_id( $actor );
					if ( is_wp_error( $company_id ) ) { return $company_id; }
					$body = (array) $r->get_json_params();
					$outcome = papelito_company_mgmt_idempotent( $r, $actor, 'company_details_update', $body, static function () use ( $actor, $company_id, $body ) {
						$result = papelito_company_mgmt_update_details( $actor, $company_id, $body ); return is_wp_error( $result ) ? $result : $company_id;
					} );
					return is_wp_error( $outcome ) ? $outcome : new WP_REST_Response( papelito_company_context( $actor ), 200 );
				},
			)
		);

		register_rest_route(
			$ns,
			'/companies/current/audit',
			array(
				'methods' => 'GET',
				'permission_callback' => 'papelito_company_mgmt_permission',
				'callback' => static function ( WP_REST_Request $r ) {
					$actor = get_current_user_id(); $company_id = papelito_company_mgmt_active_company_id( $actor );
					if ( is_wp_error( $company_id ) ) { return $company_id; }
					$loaded = papelito_company_authz_load( $actor, $company_id );
					if ( is_wp_error( $loaded ) || ! in_array( (string) ( $loaded['membership']['member_role'] ?? '' ), array( 'owner', 'admin' ), true ) ) { return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida.', array( 'status' => 403 ) ); }
					$page = max( 1, (int) $r->get_param( 'page' ) ); $per_page = min( 50, max( 1, (int) ( $r->get_param( 'perPage' ) ?: 20 ) ) ); global $wpdb; $tables = papelito_company_table_names();
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT actor_user_id, action, payload_json, created_at FROM {$tables['audit']} WHERE company_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $company_id, $per_page, ( $page - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
					return new WP_REST_Response( array( 'items' => array_map( static fn( array $row ): array => papelito_company_mgmt_audit_view( $company_id, $actor, $row ), is_array( $rows ) ? $rows : array() ), 'page' => $page, 'perPage' => $per_page ), 200 );
				},
			)
		);

		register_rest_route(
			$ns,
			'/companies/current/billing-email/resend',
			array( 'methods' => 'POST', 'permission_callback' => 'papelito_company_mgmt_permission', 'callback' => static function ( WP_REST_Request $r ) {
				$actor = get_current_user_id(); $company_id = papelito_company_mgmt_active_company_id( $actor ); if ( is_wp_error( $company_id ) ) { return $company_id; }
				$outcome = papelito_company_mgmt_idempotent( $r, $actor, 'billing_email_resend', array(), static function () use ( $actor, $company_id ) { $loaded = papelito_company_authz_load( $actor, $company_id ); if ( is_wp_error( $loaded ) || ! papelito_company_authz_can_manage( $loaded['membership'] ?? array() ) ) { return new WP_Error( 'papelito_b2b_forbidden', 'Ação não permitida.', array( 'status' => 403 ) ); } $email = (string) ( $loaded['company']['pending_billing_email'] ?? '' ); if ( '' === $email ) { return new WP_Error( 'papelito_b2b_no_pending_billing_email', 'Não há e-mail pendente.', array( 'status' => 409 ) ); } $result = papelito_company_mgmt_send_billing_email_confirmation( $company_id, $email ); return is_wp_error( $result ) ? $result : $company_id; } );
				return is_wp_error( $outcome ) ? $outcome : new WP_REST_Response( papelito_company_context( $actor ), 200 );
			} )
		);

		register_rest_route(
			$ns,
			'/companies/billing-email/confirm',
			array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static function ( WP_REST_Request $r ) {
				if ( ! papelito_auth_rate_limit( 'billing_email_confirm', 20, 60 ) ) { return new WP_Error( 'papelito_rate_limited', 'Muitas tentativas.', array( 'status' => 429 ) ); }
				$token = (string) ( (array) $r->get_json_params() )['token']; if ( '' === $token ) { return new WP_Error( 'papelito_b2b_invalid_billing_token', 'Token inválido.', array( 'status' => 422 ) ); }
				global $wpdb; $tables = papelito_company_table_names(); $company = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['companies']} WHERE pending_billing_email_token_hash = %s AND pending_billing_email_expires_at > UTC_TIMESTAMP()", hash( 'sha256', $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( ! is_array( $company ) ) { return new WP_Error( 'papelito_b2b_invalid_billing_token', 'Token inválido ou expirado.', array( 'status' => 404 ) ); }
				$updated = papelito_company_update( (int) $company['id'], array( 'billing_email' => (string) $company['pending_billing_email'], 'billing_email_verified_at' => current_time( 'mysql', true ), 'pending_billing_email' => null, 'pending_billing_email_token_hash' => null, 'pending_billing_email_expires_at' => null ) );
				if ( is_wp_error( $updated ) ) { return $updated; } papelito_company_audit( (int) $company['id'], null, 'billing_email_verified', array() ); return new WP_REST_Response( array( 'ok' => true ), 200 );
			} )
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
					$writes = papelito_b2b_require_company_writes(); if ( is_wp_error( $writes ) ) { return $writes; }
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
								$raw_expires = $body['expiresAt'];
								if ( null === $raw_expires ) {
									$expires = null;
								} elseif ( ! is_string( $raw_expires ) || '' === trim( $raw_expires ) ) {
									return new WP_Error( 'papelito_b2b_invalid_expiration', 'Data de expiração inválida.', array( 'status' => 422 ) );
								} else {
									$date = DateTimeImmutable::createFromFormat( DateTimeInterface::RFC3339, $raw_expires );
									if ( ! $date || $date->getTimestamp() <= time() ) {
										return new WP_Error( 'papelito_b2b_invalid_expiration', 'Data de expiração deve estar no futuro.', array( 'status' => 422 ) );
									}
									$expires = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
								}
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
					$writes = papelito_b2b_require_company_writes(); if ( is_wp_error( $writes ) ) { return $writes; }
					$user_id = get_current_user_id();
					$result  = papelito_company_invitation_accept_token( $user_id, (string) $r['token'] );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
			)
		);
	}
);
