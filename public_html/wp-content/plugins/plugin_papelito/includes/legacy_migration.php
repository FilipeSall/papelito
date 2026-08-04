<?php
/**
 * Migração assistida de usuários legados em modo de aviso (Step 4 B2B).
 *
 * O coorte legado é estável e separado do coorte B2B definitivo. Durante o período de graça,
 * usuários legados seguem no checkout antigo; apenas a conclusão atômica da migração marca
 * papelito_b2b_required=1.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_B2B_LEGACY_COHORT_META' ) ) {
	define( 'PAPELITO_B2B_LEGACY_COHORT_META', 'papelito_b2b_legacy_cohort' );
	define( 'PAPELITO_B2B_LEGACY_COHORT_VERSION_META', 'papelito_b2b_legacy_cohort_version' );
	define( 'PAPELITO_B2B_LEGACY_MARKED_AT_META', 'papelito_b2b_legacy_marked_at' );
	define( 'PAPELITO_B2B_LEGACY_STATUS_META', 'papelito_b2b_legacy_migration_status' );
	define( 'PAPELITO_B2B_LEGACY_EXEMPT_REASON_META', 'papelito_b2b_legacy_exempt_reason' );
	define( 'PAPELITO_B2B_LEGACY_WARNING_VIEWED_META', 'papelito_b2b_legacy_warning_viewed_at' );
	define( 'PAPELITO_B2B_LEGACY_WARNING_VIEW_COUNT_META', 'papelito_b2b_legacy_warning_view_count' );
	define( 'PAPELITO_B2B_LEGACY_COHORT_VERSION', 'step4-2026-07' );
	define( 'PAPELITO_B2B_LEGACY_EMAIL_HOOK', 'papelito_b2b_legacy_send_campaign_batch' );
}

function papelito_legacy_flag( string $name ): bool {
	return papelito_b2b_flag( $name );
}

function papelito_legacy_env_datetime( string $name ): ?string {
	$value = trim( (string) papelito_env( $name, '' ) );
	if ( '' === $value ) {
		return null;
	}

	$timestamp = strtotime( $value );
	return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
}

function papelito_legacy_grace_end_at(): ?string {
	return papelito_legacy_env_datetime( 'PAPELITO_B2B_LEGACY_GRACE_END_AT' );
}

function papelito_legacy_is_cohort( int $user_id ): bool {
	return '1' === (string) get_user_meta( $user_id, PAPELITO_B2B_LEGACY_COHORT_META, true );
}

function papelito_legacy_status( int $user_id ): ?string {
	$status = sanitize_key( (string) get_user_meta( $user_id, PAPELITO_B2B_LEGACY_STATUS_META, true ) );
	return '' === $status ? null : $status;
}

function papelito_legacy_set_status( int $user_id, string $status ): void {
	$allowed = array( 'eligible', 'notified', 'onboarding_started', 'pending_company_review', 'pending_membership_approval', 'migrated', 'needs_support', 'invalid_legacy_data', 'exempt' );
	if ( in_array( $status, $allowed, true ) ) {
		update_user_meta( $user_id, PAPELITO_B2B_LEGACY_STATUS_META, $status );
	}
}

function papelito_legacy_mark_user( int $user_id, string $status = 'eligible', bool $force = false ): bool {
	if ( ! $force && papelito_legacy_is_cohort( $user_id ) ) {
		return false;
	}

	update_user_meta( $user_id, PAPELITO_B2B_LEGACY_COHORT_META, '1' );
	update_user_meta( $user_id, PAPELITO_B2B_LEGACY_COHORT_VERSION_META, PAPELITO_B2B_LEGACY_COHORT_VERSION );
	update_user_meta( $user_id, PAPELITO_B2B_LEGACY_MARKED_AT_META, current_time( 'mysql', true ) );
	papelito_legacy_set_status( $user_id, $status );

	return true;
}

function papelito_legacy_user_is_candidate( WP_User $user, string $cutoff ): bool {
	if ( ! papelito_user_has_role( $user, 'customer' ) ) {
		return false;
	}
	if ( papelito_user_has_role( $user, 'administrator' ) || papelito_user_is_effective_seller( $user ) ) {
		return false;
	}
	if ( '1' === (string) get_user_meta( $user->ID, PAPELITO_B2B_REQUIRED_META, true ) ) {
		return false;
	}
	if ( strtotime( $user->user_registered ) >= strtotime( $cutoff ) ) {
		return false;
	}
	if ( function_exists( 'papelito_company_members_active_for_user' ) && ! empty( papelito_company_members_active_for_user( $user->ID ) ) ) {
		return false;
	}

	return true;
}

function papelito_legacy_mask_document( string $document ): string {
	$normalized = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $document ) ?? '' );
	if ( strlen( $normalized ) <= 4 ) {
		return '';
	}

	return substr( $normalized, 0, 2 ) . str_repeat( '*', max( 0, strlen( $normalized ) - 6 ) ) . substr( $normalized, -4 );
}

function papelito_legacy_document_hash( string $document ): string {
	$normalized = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $document ) ?? '' );
	return hash_hmac( 'sha256', $normalized, wp_salt( 'papelito_legacy_document' ) );
}

function papelito_legacy_user_has_orders( int $user_id ): bool {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return false;
	}

	$orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'limit'       => 1,
			'return'      => 'ids',
		)
	);

	return ! empty( $orders );
}

function papelito_legacy_user_classification( WP_User $user ): array {
	$cpf       = (string) get_user_meta( $user->ID, 'cpf', true );
	$cnpj      = (string) get_user_meta( $user->ID, 'cnpj', true );
	$cnpj_norm = function_exists( 'papelito_normalize_cnpj' ) ? papelito_normalize_cnpj( $cnpj ) : strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $cnpj ) ?? '' );
	$reasons   = array();

	if ( '' !== $cnpj_norm ) {
		if ( preg_match( '/[A-Z]/', $cnpj_norm ) ) {
			$reasons[] = 'cnpj_alphanumeric';
		}
		$reasons[] = papelito_validate_cnpj( $cnpj_norm ) ? 'cnpj_valid' : 'cnpj_invalid';
	} elseif ( papelito_validate_cpf( $cpf ) ) {
		$reasons[] = 'cpf_only';
	} else {
		$reasons[] = 'missing_or_invalid_document';
	}

	if ( '1' !== (string) get_user_meta( $user->ID, 'papelito_profile_complete', true ) && get_user_meta( $user->ID, 'google_sub', true ) ) {
		$reasons[] = 'google_incomplete';
	}
	if ( papelito_legacy_user_has_orders( $user->ID ) ) {
		$reasons[] = 'has_orders';
	}
	if ( papelito_legacy_is_cohort( $user->ID ) ) {
		$reasons[] = 'already_legacy_cohort';
	}
	if ( ! empty( papelito_company_members_pending_for_user( $user->ID ) ) ) {
		$reasons[] = 'pending_membership';
	}
	if ( function_exists( 'papelito_company_members_active_for_user' ) && ! empty( papelito_company_members_active_for_user( $user->ID ) ) ) {
		$reasons[] = 'active_membership';
	}
	if ( papelito_user_is_effective_seller( $user ) ) {
		$reasons[] = 'seller_excluded';
	}
	if ( papelito_user_has_role( $user, 'administrator' ) ) {
		$reasons[] = 'admin_excluded';
	}

	return array(
		'user_id'      => $user->ID,
		'email_hash'   => hash_hmac( 'sha256', strtolower( (string) $user->user_email ), wp_salt( 'papelito_legacy_email' ) ),
		'cnpj_masked'  => '' !== $cnpj_norm ? papelito_legacy_mask_document( $cnpj_norm ) : '',
		'cnpj_hash'    => '' !== $cnpj_norm ? papelito_legacy_document_hash( $cnpj_norm ) : '',
		'reasons'      => $reasons,
	);
}

function papelito_legacy_warning_level(): string {
	$grace = papelito_legacy_grace_end_at();
	if ( null === $grace ) {
		return 'info';
	}

	$days = (int) floor( ( strtotime( $grace ) - time() ) / DAY_IN_SECONDS );
	if ( $days <= 2 ) {
		return 'urgent';
	}
	if ( $days <= 7 ) {
		return 'warning';
	}
	return 'info';
}

function papelito_legacy_context( int $user_id ): array {
	$is_b2b  = function_exists( 'papelito_b2b_is_cohort' ) && papelito_b2b_is_cohort( $user_id );
	$is_legacy = papelito_legacy_is_cohort( $user_id );
	$status = papelito_legacy_status( $user_id );
	$purchase_mode = $is_b2b ? 'b2b' : 'legacy';
	if ( ! $is_b2b && $is_legacy && papelito_legacy_flag( 'PAPELITO_B2B_PURCHASE_ENFORCED' ) ) {
		$purchase_mode = 'blocked';
	}

	return array(
		'purchaseMode'                 => $purchase_mode,
		'isLegacyCohort'               => $is_legacy,
		'legacyMigrationStatus'        => $status,
		'legacyGraceEndsAt'            => papelito_legacy_grace_end_at(),
		'legacyWarningLevel'           => $is_legacy && ! $is_b2b && papelito_legacy_flag( 'PAPELITO_B2B_LEGACY_WARNING_ENABLED' ) ? papelito_legacy_warning_level() : 'none',
		'legacyCanPurchaseDuringGrace' => $is_legacy && ! $is_b2b && 'blocked' !== $purchase_mode,
	);
}

function papelito_legacy_complete_if_ready( int $user_id, string $source = 'membership_active' ): bool {
	if ( ! papelito_legacy_is_cohort( $user_id ) ) {
		return false;
	}

	$members = function_exists( 'papelito_company_members_active_for_user' ) ? array_values( papelito_company_members_active_for_user( $user_id ) ) : array();
	if ( empty( $members ) ) {
		return false;
	}

	foreach ( $members as $member ) {
		$company = papelito_company_get( (int) $member['company_id'] );
		if ( ! $company ) {
			continue;
		}
		if ( 'active' === (string) $company['company_status'] && 'active' === (string) $company['registry_status'] && 'verified' === (string) $company['ownership_status'] ) {
			global $wpdb;
			$tables = papelito_company_table_names();
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			try {
				$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['members']} WHERE id = %d AND user_id = %d AND member_status = 'active' FOR UPDATE", (int) $member['id'], $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( ! is_array( $locked ) ) {
					throw new RuntimeException( 'membership changed' );
				}
				update_user_meta( $user_id, PAPELITO_B2B_REQUIRED_META, '1' );
				papelito_legacy_set_status( $user_id, 'migrated' );
				if ( 1 === count( $members ) ) {
					papelito_company_active_set_selection( $user_id, (int) $member['company_id'] );
				}
				papelito_company_audit( (int) $member['company_id'], $user_id, 'legacy_migration_completed', array( 'source' => $source ) );
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return true;
			} catch ( Throwable $error ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
		}
	}

	return false;
}

function papelito_legacy_start( int $user_id, array $data ) {
	if ( ! papelito_legacy_flag( 'PAPELITO_B2B_LEGACY_MIGRATION_ENABLED' ) ) {
		return new WP_Error( 'papelito_legacy_migration_disabled', 'Migração empresarial temporariamente indisponível.', array( 'status' => 503 ) );
	}
	if ( ! papelito_legacy_is_cohort( $user_id ) || papelito_b2b_is_cohort( $user_id ) ) {
		return new WP_Error( 'papelito_legacy_not_eligible', 'Usuário não elegível para migração assistida.', array( 'status' => 409 ) );
	}

	$intent = (string) ( $data['intent'] ?? 'create_company' );
	if ( ! in_array( $intent, array( 'create_company', 'join_company' ), true ) ) {
		return new WP_Error( 'papelito_legacy_invalid_intent', 'Escolha criar empresa ou solicitar acesso.', array( 'status' => 422 ) );
	}

	$cnpj = (string) ( $data['cnpj'] ?? get_user_meta( $user_id, 'cnpj', true ) );
	if ( ! papelito_validate_cnpj( $cnpj ) ) {
		papelito_legacy_set_status( $user_id, 'invalid_legacy_data' );
		return new WP_Error( 'papelito_legacy_invalid_cnpj', 'Informe um CNPJ válido para continuar.', array( 'status' => 422 ) );
	}

	papelito_company_onboarding_upsert( $user_id, 'legacy_migration', $cnpj, 'pending_onboarding' );
	papelito_legacy_set_status( $user_id, 'onboarding_started' );

	if ( 'join_company' === $intent ) {
		$result = papelito_company_access_request_submit( $user_id, $cnpj );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		papelito_legacy_set_status( $user_id, 'pending_membership_approval' );
		return papelito_legacy_context( $user_id );
	}

	$cpf = (string) ( $data['cpf'] ?? get_user_meta( $user_id, 'cpf', true ) );
	$birth_date = (string) ( $data['birthDate'] ?? $data['birth_date'] ?? '' );
	if ( ! papelito_validate_cpf( $cpf ) || '' === $birth_date ) {
		return new WP_Error( 'papelito_legacy_profile_required', 'CPF e data de nascimento são necessários para criar a empresa.', array( 'status' => 422 ) );
	}

	$result = papelito_company_create_owner_candidate(
		$user_id,
		array(
			'cpf'        => $cpf,
			'birth_date' => $birth_date,
			'cnpj'       => $cnpj,
		)
	);
	if ( is_wp_error( $result ) && 'papelito_company_cnpj_exists' === $result->get_error_code() ) {
		papelito_legacy_set_status( $user_id, 'needs_support' );
		return new WP_Error( 'papelito_legacy_existing_company', 'Este CNPJ já possui empresa. Solicite acesso ou fale com o suporte.', array( 'status' => 409 ) );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	papelito_legacy_set_status( $user_id, 'pending_company_review' );
	return papelito_legacy_context( $user_id );
}

function papelito_legacy_email_log_get( int $user_id, string $campaign, string $version = '1' ): ?array {
	global $wpdb;
	$table = papelito_company_table_names()['legacy_email_log'];
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND campaign = %s AND campaign_version = %s", $user_id, sanitize_key( $campaign ), $version ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	return is_array( $row ) ? $row : null;
}

function papelito_legacy_email_log_upsert( int $user_id, string $campaign, array $data = array() ): void {
	global $wpdb;
	$table = papelito_company_table_names()['legacy_email_log'];
	$campaign = sanitize_key( $campaign );
	$version = sanitize_text_field( (string) ( $data['campaign_version'] ?? '1' ) );
	$existing = papelito_legacy_email_log_get( $user_id, $campaign, $version );
	$now = current_time( 'mysql', true );
	$row = array_merge(
		array(
			'user_id'          => $user_id,
			'campaign'         => $campaign,
			'campaign_version' => $version,
			'updated_at'       => $now,
		),
		$data
	);
	if ( null === $existing ) {
		$row['created_at'] = $now;
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} else {
		unset( $row['user_id'], $row['campaign'], $row['campaign_version'] );
		$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

function papelito_legacy_send_campaign_to_user( int $user_id, string $campaign, string $version = '1' ): bool {
	if ( ! papelito_legacy_flag( 'PAPELITO_B2B_LEGACY_EMAIL_ENABLED' ) ) {
		return false;
	}
	if ( ! papelito_legacy_is_cohort( $user_id ) || papelito_b2b_is_cohort( $user_id ) || in_array( papelito_legacy_status( $user_id ), array( 'migrated', 'exempt' ), true ) ) {
		return false;
	}
	$existing = papelito_legacy_email_log_get( $user_id, $campaign, $version );
	if ( is_array( $existing ) && 'sent' === (string) $existing['status'] ) {
		return true;
	}

	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User || ! is_email( (string) $user->user_email ) ) {
		papelito_legacy_email_log_upsert( $user_id, $campaign, array( 'status' => 'failed', 'attempts' => (int) ( $existing['attempts'] ?? 0 ) + 1, 'last_error_code' => 'invalid_email', 'campaign_version' => $version ) );
		return false;
	}

	$link = papelito_frontend_link( 'perfil/empresa' );
	if ( is_wp_error( $link ) ) {
		papelito_legacy_email_log_upsert( $user_id, $campaign, array( 'status' => 'failed', 'attempts' => (int) ( $existing['attempts'] ?? 0 ) + 1, 'last_error_code' => 'frontend_url_unresolved', 'campaign_version' => $version ) );
		return false;
	}
	$subject = 'Atualize seu cadastro empresarial na Papelito';
	$body = "A Papelito passará a operar somente com contas empresariais.\n\nAcesse sua conta para cadastrar uma empresa ou solicitar acesso a uma empresa existente:\n{$link}\n\nNenhum CPF ou CNPJ é enviado neste link.";
	$sent = wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	papelito_legacy_email_log_upsert(
		$user_id,
		$campaign,
		array(
			'status'           => $sent ? 'sent' : 'failed',
			'attempts'         => (int) ( $existing['attempts'] ?? 0 ) + 1,
			'last_error_code'  => $sent ? null : 'wp_mail_failed',
			'sent_at'          => $sent ? current_time( 'mysql', true ) : null,
			'next_retry_at'    => $sent ? null : gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			'campaign_version' => $version,
		)
	);
	if ( $sent && 'eligible' === papelito_legacy_status( $user_id ) ) {
		papelito_legacy_set_status( $user_id, 'notified' );
	}

	return (bool) $sent;
}

function papelito_legacy_send_campaign_batch( string $campaign = 'initial_notice', int $limit = 25 ): int {
	$users = get_users(
		array(
			'role'       => 'customer',
			'number'     => $limit,
			'meta_query' => array(
				array(
					'key'   => PAPELITO_B2B_LEGACY_COHORT_META,
					'value' => '1',
				),
			),
			'fields'     => 'ID',
		)
	);
	$count = 0;
	foreach ( $users as $user_id ) {
		if ( papelito_legacy_send_campaign_to_user( (int) $user_id, $campaign ) ) {
			++$count;
		}
	}
	return $count;
}
add_action( PAPELITO_B2B_LEGACY_EMAIL_HOOK, 'papelito_legacy_send_campaign_batch', 10, 2 );

function papelito_legacy_schedule_email_cron(): void {
	if ( ! wp_next_scheduled( PAPELITO_B2B_LEGACY_EMAIL_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', PAPELITO_B2B_LEGACY_EMAIL_HOOK, array( 'initial_notice', 25 ) );
	}
}
add_action( 'init', 'papelito_legacy_schedule_email_cron' );

function papelito_legacy_admin_capability(): bool {
	return current_user_can( 'papelito_manage_companies' );
}

function papelito_legacy_summary(): array {
	global $wpdb;
	$meta = $wpdb->usermeta;
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$meta} WHERE meta_key = %s AND meta_value = '1'", PAPELITO_B2B_LEGACY_COHORT_META ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$statuses = array( 'eligible', 'notified', 'onboarding_started', 'pending_company_review', 'pending_membership_approval', 'migrated', 'needs_support', 'invalid_legacy_data', 'exempt' );
	$counts = array_fill_keys( $statuses, 0 );
	foreach ( $statuses as $status ) {
		$counts[ $status ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$meta} cohort INNER JOIN {$meta} status_meta ON cohort.user_id = status_meta.user_id WHERE cohort.meta_key = %s AND cohort.meta_value = '1' AND status_meta.meta_key = %s AND status_meta.meta_value = %s", PAPELITO_B2B_LEGACY_COHORT_META, PAPELITO_B2B_LEGACY_STATUS_META, $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$days = null;
	$grace = papelito_legacy_grace_end_at();
	if ( null !== $grace ) {
		$days = max( 0, (int) floor( ( strtotime( $grace ) - time() ) / DAY_IN_SECONDS ) );
	}
	return array(
		'total'          => $total,
		'counts'         => $counts,
		'completionRate' => $total > 0 ? round( ( $counts['migrated'] / $total ) * 100, 2 ) : 0,
		'daysRemaining'  => $days,
		'graceEndsAt'    => $grace,
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		$ns = 'papelito/v1';
		register_rest_route(
			$ns,
			'/legacy-migration/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn(): bool => get_current_user_id() > 0,
				'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( papelito_legacy_context( get_current_user_id() ), 200 ),
			)
		);
		register_rest_route(
			$ns,
			'/legacy-migration/start',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => get_current_user_id() > 0,
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = papelito_legacy_start( get_current_user_id(), (array) $request->get_json_params() );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
			)
		);
		register_rest_route(
			$ns,
			'/legacy-migration/warning-viewed',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => get_current_user_id() > 0,
				'callback'            => static function (): WP_REST_Response {
					$user_id = get_current_user_id();
					if ( ! papelito_legacy_is_cohort( $user_id ) || papelito_b2b_is_cohort( $user_id ) ) {
						return new WP_REST_Response( array( 'ok' => false ), 409 );
					}
					update_user_meta( $user_id, PAPELITO_B2B_LEGACY_WARNING_VIEWED_META, current_time( 'mysql', true ) );
					update_user_meta( $user_id, PAPELITO_B2B_LEGACY_WARNING_VIEW_COUNT_META, (int) get_user_meta( $user_id, PAPELITO_B2B_LEGACY_WARNING_VIEW_COUNT_META, true ) + 1 );
					if ( 'eligible' === papelito_legacy_status( $user_id ) ) {
						papelito_legacy_set_status( $user_id, 'notified' );
					}
					return new WP_REST_Response( array( 'ok' => true ), 200 );
				},
			)
		);
		register_rest_route(
			$ns,
			'/legacy-migration/restart',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => get_current_user_id() > 0,
				'callback'            => static function (): WP_REST_Response {
					$user_id = get_current_user_id();
					if ( ! papelito_legacy_flag( 'PAPELITO_B2B_LEGACY_MIGRATION_ENABLED' ) ) {
						return new WP_REST_Response( array( 'code' => 'papelito_legacy_migration_disabled' ), 503 );
					}
					if ( ! papelito_legacy_is_cohort( $user_id ) || papelito_b2b_is_cohort( $user_id ) ) {
						return new WP_REST_Response( array( 'code' => 'papelito_legacy_not_eligible' ), 409 );
					}
					papelito_company_onboarding_upsert( $user_id, 'legacy_migration', null, 'pending_onboarding' );
					papelito_legacy_set_status( $user_id, 'onboarding_started' );
					return new WP_REST_Response( papelito_legacy_context( $user_id ), 200 );
				},
			)
		);
		register_rest_route( $ns, '/admin/legacy-migration/summary', array( 'methods' => 'GET', 'permission_callback' => 'papelito_legacy_admin_capability', 'callback' => static fn(): WP_REST_Response => new WP_REST_Response( papelito_legacy_summary(), 200 ) ) );
		register_rest_route(
			$ns,
			'/admin/legacy-migration/users',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_legacy_admin_capability',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$status = sanitize_key( (string) $request->get_param( 'status' ) );
					$args = array(
						'role'       => 'customer',
						'number'     => min( 100, max( 1, (int) ( $request->get_param( 'perPage' ) ?: 25 ) ) ),
						'meta_query' => array(
							array(
								'key'   => PAPELITO_B2B_LEGACY_COHORT_META,
								'value' => '1',
							),
						),
					);
					if ( '' !== $status ) {
						$args['meta_query'][] = array( 'key' => PAPELITO_B2B_LEGACY_STATUS_META, 'value' => $status );
					}
					$users = get_users( $args );
					return new WP_REST_Response(
						array(
							'items' => array_map(
								static fn( WP_User $user ): array => array(
									'userId' => $user->ID,
									'emailHash' => hash_hmac( 'sha256', strtolower( (string) $user->user_email ), wp_salt( 'papelito_legacy_email' ) ),
									'status' => papelito_legacy_status( $user->ID ),
									'markedAt' => get_user_meta( $user->ID, PAPELITO_B2B_LEGACY_MARKED_AT_META, true ),
								),
								$users
							),
						),
						200
					);
				},
			)
		);
		register_rest_route( $ns, '/admin/legacy-migration/(?P<userId>\d+)/resend', array( 'methods' => 'POST', 'permission_callback' => 'papelito_legacy_admin_capability', 'callback' => static fn( WP_REST_Request $r ) => new WP_REST_Response( array( 'sent' => papelito_legacy_send_campaign_to_user( (int) $r['userId'], 'initial_notice' ) ), 200 ) ) );
		register_rest_route(
			$ns,
			'/admin/legacy-migration/(?P<userId>\d+)/exempt',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_legacy_admin_capability',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$user_id = (int) $request['userId'];
					$body = (array) $request->get_json_params();
					update_user_meta( $user_id, PAPELITO_B2B_LEGACY_EXEMPT_REASON_META, sanitize_text_field( (string) ( $body['reason'] ?? '' ) ) );
					papelito_legacy_set_status( $user_id, 'exempt' );
					return new WP_REST_Response( array( 'ok' => true ), 200 );
				},
			)
		);
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * WP-CLI para auditoria e rollout de legados.
	 */
	class Papelito_Legacy_Migration_CLI {
		public function audit( array $args, array $assoc_args ): void {
			$users = get_users( array( 'role' => 'customer', 'number' => -1 ) );
			$rows = array();
			$counts = array();
			foreach ( $users as $user ) {
				$rows[] = papelito_legacy_user_classification( $user );
			}
			$cnpj_hash_counts = array();
			foreach ( $rows as $row ) {
				if ( '' !== $row['cnpj_hash'] ) {
					$cnpj_hash_counts[ $row['cnpj_hash'] ] = ( $cnpj_hash_counts[ $row['cnpj_hash'] ] ?? 0 ) + 1;
				}
			}
			foreach ( $rows as $index => $row ) {
				if ( '' !== $row['cnpj_hash'] && ( $cnpj_hash_counts[ $row['cnpj_hash'] ] ?? 0 ) > 1 ) {
					$rows[ $index ]['reasons'][] = 'cnpj_duplicate';
				} elseif ( in_array( 'cnpj_valid', $row['reasons'], true ) ) {
					$rows[ $index ]['reasons'][] = 'cnpj_valid_unique';
				}
				foreach ( $rows[ $index ]['reasons'] as $reason ) {
					$counts[ $reason ] = ( $counts[ $reason ] ?? 0 ) + 1;
				}
			}
			if ( ! empty( $assoc_args['output'] ) ) {
				$handle = fopen( (string) $assoc_args['output'], 'w' );
				if ( false !== $handle ) {
					fputcsv( $handle, array( 'user_id', 'email_hash', 'cnpj_masked', 'cnpj_hash', 'reasons' ) );
					foreach ( $rows as $row ) {
						fputcsv( $handle, array( $row['user_id'], $row['email_hash'], $row['cnpj_masked'], $row['cnpj_hash'], implode( '|', $row['reasons'] ) ) );
					}
					fclose( $handle );
				}
			}
			WP_CLI::success( wp_json_encode( $counts ) );
		}

		public function mark_cohort( array $args, array $assoc_args ): void {
			$cutoff = (string) ( $assoc_args['cutoff'] ?? '' );
			if ( '' === $cutoff || false === strtotime( $cutoff ) ) {
				WP_CLI::error( 'Informe --cutoff="AAAA-MM-DD HH:MM:SS".' );
			}
			$apply = ! empty( $assoc_args['apply'] );
			if ( ! $apply && empty( $assoc_args['dry-run'] ) ) {
				WP_CLI::error( 'Use --dry-run ou --apply.' );
			}
			$users = get_users( array( 'role' => 'customer', 'number' => -1 ) );
			$eligible = 0;
			$marked = 0;
			foreach ( $users as $user ) {
				if ( ! papelito_legacy_user_is_candidate( $user, $cutoff ) ) {
					continue;
				}
				++$eligible;
				if ( $apply && papelito_legacy_mark_user( $user->ID ) ) {
					++$marked;
				}
			}
			WP_CLI::success( sprintf( 'eligible=%d marked=%d dry_run=%s', $eligible, $marked, $apply ? 'false' : 'true' ) );
		}

		public function status(): void {
			WP_CLI::success( wp_json_encode( papelito_legacy_summary() ) );
		}

		public function send_campaign( array $args, array $assoc_args ): void {
			$campaign = sanitize_key( (string) ( $assoc_args['campaign'] ?? 'initial_notice' ) );
			$limit = max( 1, min( 100, (int) ( $assoc_args['limit'] ?? 25 ) ) );
			if ( empty( $assoc_args['apply'] ) && empty( $assoc_args['dry-run'] ) ) {
				WP_CLI::error( 'Use --dry-run ou --apply.' );
			}
			if ( ! empty( $assoc_args['dry-run'] ) ) {
				WP_CLI::success( sprintf( 'campaign=%s limit=%d dry_run=true', $campaign, $limit ) );
				return;
			}
			WP_CLI::success( sprintf( 'sent=%d', papelito_legacy_send_campaign_batch( $campaign, $limit ) ) );
		}
	}

	WP_CLI::add_command( 'papelito b2b legacy', 'Papelito_Legacy_Migration_CLI' );
}
