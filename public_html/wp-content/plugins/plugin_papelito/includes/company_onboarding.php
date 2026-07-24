<?php
/**
 * Estado persistente do onboarding B2B.
 *
 * Transients não são fonte de verdade para cadastro, confirmação de e-mail ou criação de
 * empresa. Esta tabela mantém uma única operação de onboarding por usuário e torna a confirmação
 * idempotente e retomável.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_B2B_ONBOARDING_TTL_DAYS' ) ) {
	define( 'PAPELITO_B2B_ONBOARDING_TTL_DAYS', 30 );
}

function papelito_company_onboarding_get( int $user_id ): ?array {
	global $wpdb;
	$tables = papelito_company_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['onboarding']} WHERE user_id = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return is_array( $row ) ? $row : null;
}

function papelito_company_onboarding_upsert( int $user_id, string $type, ?string $target_cnpj = null, string $status = 'pending_email' ) {
	global $wpdb;
	$tables = papelito_company_table_names();
	$allowed = array( 'create_company', 'join_company', 'google_onboarding' );
	if ( ! in_array( $type, $allowed, true ) ) {
		return new WP_Error( 'papelito_b2b_invalid_onboarding_type', 'Tipo de onboarding inválido.', array( 'status' => 422 ) );
	}

	$cnpj = null;
	if ( null !== $target_cnpj && '' !== trim( $target_cnpj ) ) {
		$cnpj = papelito_normalize_cnpj( $target_cnpj );
		if ( '' === $cnpj || ! papelito_validate_cnpj( $cnpj ) ) {
			return new WP_Error( 'papelito_b2b_invalid_onboarding_cnpj', 'CNPJ inválido.', array( 'status' => 422 ) );
		}
	}

	$now      = current_time( 'mysql', true );
	$existing = papelito_company_onboarding_get( $user_id );
	$data     = array(
		'user_id'         => $user_id,
		'onboarding_type' => $type,
		'target_cnpj'     => $cnpj,
		'status'          => $status,
		'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + ( PAPELITO_B2B_ONBOARDING_TTL_DAYS * DAY_IN_SECONDS ) ),
		'updated_at'      => $now,
	);

	if ( null === $existing ) {
		$data['created_at'] = $now;
		$result             = $wpdb->insert( $tables['onboarding'], $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} else {
		$result = $wpdb->update( $tables['onboarding'], $data, array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	return false === $result ? new WP_Error( 'papelito_b2b_onboarding_persist_failed', 'Falha ao persistir onboarding.', array( 'status' => 500 ) ) : true;
}

function papelito_company_onboarding_mark_google( int $user_id ) {
	return papelito_company_onboarding_upsert( $user_id, 'google_onboarding', null, 'pending_onboarding' );
}

function papelito_company_onboarding_mark_email_confirmed( int $user_id ): void {
	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->query( $wpdb->prepare( "UPDATE {$tables['onboarding']} SET email_confirmed_at = %s, status = CASE WHEN status = 'pending_email' THEN 'pending_onboarding' ELSE status END, updated_at = %s WHERE user_id = %d", current_time( 'mysql', true ), current_time( 'mysql', true ), $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}

function papelito_company_onboarding_mark_error( int $user_id, string $code ): void {
	global $wpdb;
	$tables = papelito_company_table_names();
	$wpdb->update( $tables['onboarding'], array( 'last_error_code' => sanitize_key( $code ), 'updated_at' => current_time( 'mysql', true ) ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function papelito_company_onboarding_mark_completed( int $user_id, ?int $company_id = null, ?int $membership_id = null ): void {
	global $wpdb;
	$tables = papelito_company_table_names();
	$fields = array(
		'status'        => 'completed',
		'completed_at'  => current_time( 'mysql', true ),
		'last_error_code' => null,
		'updated_at'    => current_time( 'mysql', true ),
	);
	if ( null !== $company_id ) { $fields['company_id'] = $company_id; }
	if ( null !== $membership_id ) { $fields['membership_id'] = $membership_id; }
	$wpdb->update( $tables['onboarding'], $fields, array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function papelito_company_onboarding_state( int $user_id ): string {
	$row = papelito_company_onboarding_get( $user_id );
	if ( null === $row ) { return 'onboarding_required'; }
	if ( 'completed' === (string) $row['status'] ) { return 'completed'; }
	if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] ) < time() ) { return 'onboarding_required'; }
	return (string) $row['status'];
}
