<?php
/**
 * Política de personalização: seller governa somente sua descrição.
 *
 * Todo produto publicado está no escopo, independentemente do saldo. Variações
 * pertencem ao pai; pendências de venda não impedem preparar o conteúdo.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_DESCRIPTION_LIMIT = 20000;

/**
 * Valida publicação e normaliza variação para o produto pai.
 *
 * @return WC_Product|WP_Error
 */
function papelito_vendor_product_customization_product( int $product_id ): WC_Product|WP_Error {
	$product = $product_id > 0 ? wc_get_product( $product_id ) : false;
	if ( ! $product || 'publish' !== $product->get_status( 'edit' ) ) {
		return new WP_Error( 'papelito_customization_product_not_found', 'Produto publicado não encontrado.', array( 'status' => 404 ) );
	}
	if ( $product->is_type( 'variation' ) ) {
		$product = wc_get_product( $product->get_parent_id() );
	}
	if ( ! $product || 'product' !== get_post_type( $product->get_id() ) || 'publish' !== $product->get_status( 'edit' ) ) {
		return new WP_Error( 'papelito_customization_product_not_found', 'Produto publicado não encontrado.', array( 'status' => 404 ) );
	}
	return $product;
}

/**
 * Autoriza identidade no serviço; mutações também aplicam a guarda comercial.
 *
 * @return WP_User|WP_Error
 */
function papelito_vendor_product_customization_require_seller( bool $mutate = false ): WP_User|WP_Error {
	$user = wp_get_current_user();
	if ( ! $user->exists() ) {
		return new WP_Error( 'papelito_not_authenticated', 'Não autenticado.', array( 'status' => 401 ) );
	}
	if ( ! papelito_user_is_effective_seller( $user ) ) {
		return new WP_Error( 'papelito_forbidden', 'Acesso restrito a vendors.', array( 'status' => 403 ) );
	}
	if ( $mutate ) {
		$guard = papelito_account_guard_commercial( (int) $user->ID );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
	}
	return $user;
}

/** Detecta vazio visual, incluindo entidades e caracteres invisíveis. */
function papelito_vendor_product_customization_has_text( string $description ): bool {
	$text = html_entity_decode( wp_strip_all_tags( $description ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/[\s\p{Z}\p{Cf}]+/u', '', $text );
	return is_string( $text ) && '' !== $text;
}

/** Normaliza espaços nas bordas do parágrafo com a mesma regra Unicode do editor. */
function papelito_vendor_product_customization_trim_text( string $paragraph ): string {
	return (string) preg_replace( '/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $paragraph );
}

/** Conta o texto do editor em Unicode, incluindo quebras simples e duas entre parágrafos. */
function papelito_vendor_product_customization_text_length( string $description ): int {
	$text       = preg_replace( '/<br\s*\/?>(?:\r?\n)?/i', "\n", $description );
	$text       = preg_replace( '/<\/?p\s*>/i', "\n\n", (string) $text );
	$text       = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text       = str_replace( array( "\r\n", "\r" ), "\n", $text );
	$paragraphs = preg_split( '/\n{2,}/', $text );
	if ( false === $paragraphs ) {
		return mb_strlen( $text, 'UTF-8' );
	}
	$paragraphs = array_filter( array_map( 'papelito_vendor_product_customization_trim_text', $paragraphs ), 'strlen' );
	return mb_strlen( implode( "\n\n", $paragraphs ), 'UTF-8' );
}

/**
 * Aceita somente a descrição e limita o texto sanitizado, sem contar HTML nem truncar.
 *
 * @return string|WP_Error
 */
function papelito_vendor_product_customization_validate( mixed $payload ): string|WP_Error {
	if ( ! is_array( $payload ) || array( 'description' ) !== array_keys( $payload ) || ! is_string( $payload['description'] ) ) {
		return new WP_Error( 'papelito_customization_invalid_description', 'Envie somente uma descrição em texto.', array( 'status' => 422 ) );
	}
	$raw = $payload['description'];
	if ( ! mb_check_encoding( $raw, 'UTF-8' ) || strlen( $raw ) > PAPELITO_VENDOR_DESCRIPTION_LIMIT * 16 ) {
		return new WP_Error( 'papelito_customization_invalid_description', 'A descrição deve ter no máximo 20.000 caracteres.', array( 'status' => 422 ) );
	}
	$description = trim(
		wp_kses(
			$raw,
			array(
				'p'  => array(),
				'br' => array(),
			)
		)
	);
	if ( papelito_vendor_product_customization_text_length( $description ) > PAPELITO_VENDOR_DESCRIPTION_LIMIT ) {
		return new WP_Error( 'papelito_customization_invalid_description', 'A descrição deve ter no máximo 20.000 caracteres.', array( 'status' => 422 ) );
	}
	if ( ! papelito_vendor_product_customization_has_text( $description ) ) {
		return new WP_Error( 'papelito_customization_invalid_description', 'Preencha uma descrição. Para usar a original, restaure a descrição da Papelito.', array( 'status' => 422 ) );
	}
	return $description;
}

/**
 * Monta a visão privada usando a mesma composição da apresentação pública.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_product_customization_view( WC_Product $product, WP_User $user ): array|WP_Error {
	$rows = papelito_vendor_product_overrides_get_many( (int) $user->ID, array( $product->get_id() ) );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$row          = $rows[ $product->get_id() ] ?? null;
	$presentation = papelito_product_presentation_compose( $product, (int) $user->ID, $row );
	return array(
		'product_id'            => $product->get_id(),
		'vendor_id'             => (int) $user->ID,
		'canonical_description' => $product->get_description( 'edit' ),
		'vendor_description'    => $row['description'] ?? null,
		'effective_description' => $presentation['description'],
		'description_source'    => $presentation['description_source'],
		'can_edit'              => ! papelito_account_is_suspended( (int) $user->ID ),
		'updated_at'            => $row['updated_at'] ?? null,
	);
}

/**
 * Lê somente a gestão do usuário autenticado; não expõe rascunhos.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_product_customization_get( int $product_id ): array|WP_Error {
	$user = papelito_vendor_product_customization_require_seller();
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	$product = papelito_vendor_product_customization_product( $product_id );
	return is_wp_error( $product ) ? $product : papelito_vendor_product_customization_view( $product, $user );
}

/**
 * Guarda comum às mutações, por usuário e compartilhada entre PUT e DELETE.
 *
 * @return true|WP_Error
 */
function papelito_vendor_product_customization_rate_limit( int $vendor_id ): bool|WP_Error {
	if ( ! papelito_rate_limit( 'vendor_product_customization', 'user:' . $vendor_id, 30, 60 ) ) {
		return new WP_Error( 'papelito_customization_rate_limited', 'Aguarde alguns instantes antes de alterar outra descrição.', array( 'status' => 429 ) );
	}
	return true;
}

/**
 * Salva uma personalização explícita e devolve o conteúdo sanitizado.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_product_customization_save( int $product_id, mixed $payload ): array|WP_Error {
	$user = papelito_vendor_product_customization_require_seller( true );
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	$product = papelito_vendor_product_customization_product( $product_id );
	if ( is_wp_error( $product ) ) {
		return $product;
	}
	$limit = papelito_vendor_product_customization_rate_limit( (int) $user->ID );
	if ( is_wp_error( $limit ) ) {
		return $limit;
	}
	$description = papelito_vendor_product_customization_validate( $payload );
	if ( is_wp_error( $description ) ) {
		return $description;
	}
	$saved = papelito_vendor_product_override_upsert( (int) $user->ID, $product->get_id(), $description );
	return is_wp_error( $saved ) ? $saved : papelito_vendor_product_customization_view( $product, $user );
}

/**
 * Restaura por DELETE do par; não grava uma cópia da descrição original.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_product_customization_restore( int $product_id ): array|WP_Error {
	$user = papelito_vendor_product_customization_require_seller( true );
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	$product = papelito_vendor_product_customization_product( $product_id );
	if ( is_wp_error( $product ) ) {
		return $product;
	}
	$limit = papelito_vendor_product_customization_rate_limit( (int) $user->ID );
	if ( is_wp_error( $limit ) ) {
		return $limit;
	}
	$deleted = papelito_vendor_product_override_delete( (int) $user->ID, $product->get_id() );
	return is_wp_error( $deleted ) ? $deleted : papelito_vendor_product_customization_view( $product, $user );
}
