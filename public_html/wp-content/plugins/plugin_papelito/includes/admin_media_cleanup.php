<?php

defined( 'ABSPATH' ) || exit;

function papelito_admin_media_cleanup_referenced( int $attachment_id ): bool {
	global $wpdb;

	$attachment = get_post( $attachment_id );
	if ( $attachment && (int) $attachment->post_parent > 0 && get_post( (int) $attachment->post_parent ) ) {
		return true;
	}
	$attachment_url = wp_get_attachment_url( $attachment_id );
	$id_like        = '%' . $wpdb->esc_like( (string) $attachment_id ) . '%';
	$url_like       = is_string( $attachment_url ) && '' !== $attachment_url ? '%' . $wpdb->esc_like( $attachment_url ) . '%' : '';
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key != %s AND meta_value LIKE %s LIMIT 1", PAPELITO_TEMPORARY_ADMIN_MEDIA_META, $id_like ) ) ) {
		return true;
	}
	if ( '' !== $url_like && $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 1", $url_like ) ) ) {
		return true;
	}
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", $id_like ) ) ) {
		return true;
	}
	$tables = function_exists( 'papelito_kits_table_names' ) ? papelito_kits_table_names() : array();
	foreach ( array( 'kits', 'merchandise' ) as $key ) {
		if ( isset( $tables[ $key ] ) && $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$tables[ $key ]} WHERE image_attachment_id = %d LIMIT 1", $attachment_id ) ) ) {
			return true;
		}
	}
	if ( function_exists( 'papelito_product_taxonomy_table_names' ) ) {
		$taxonomy_tables = papelito_product_taxonomy_table_names();
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$taxonomy_tables['categories']} WHERE icon_attachment_id = %d LIMIT 1", $attachment_id ) ) ) {
			return true;
		}
	}
	if ( function_exists( 'papelito_product_benefits_table_names' ) ) {
		$benefit_tables = papelito_product_benefits_table_names();
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$benefit_tables['items']} WHERE icon_attachment_id = %d LIMIT 1", $attachment_id ) ) ) {
			return true;
		}
	}
	return false;
}

function papelito_admin_media_cleanup( WP_REST_Request $request ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ) );
	if ( empty( $ids ) || count( $ids ) > 50 ) {
		return new WP_Error( 'papelito_admin_media_cleanup_invalid', 'Informe entre uma e cinquenta mídias válidas.', array( 'status' => 422 ) );
	}
	$removed = array();
	$ignored = array();
	foreach ( $ids as $attachment_id ) {
		$attachment = get_post( $attachment_id );
		$raw_marker = get_post_meta( $attachment_id, PAPELITO_TEMPORARY_ADMIN_MEDIA_META, true );
		$marker = is_string( $raw_marker ) ? json_decode( $raw_marker, true ) : null;
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! is_array( $marker ) || (int) ( $marker['user_id'] ?? 0 ) !== get_current_user_id() || (int) ( $marker['expires_at'] ?? 0 ) < time() || (int) $attachment->post_parent > 0 || papelito_admin_media_cleanup_referenced( $attachment_id ) ) {
			$ignored[] = $attachment_id;
			continue;
		}
		if ( wp_trash_post( $attachment_id ) ) {
			$removed[] = $attachment_id;
		} else {
			$ignored[] = $attachment_id;
		}
	}
	return new WP_REST_Response( array( 'removedIds' => $removed, 'ignoredIds' => $ignored ), 200 );
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'papelito/v1', '/admin/media/cleanup', array(
		'methods' => WP_REST_Server::CREATABLE,
		'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		'callback' => 'papelito_admin_media_cleanup',
	) );
} );
