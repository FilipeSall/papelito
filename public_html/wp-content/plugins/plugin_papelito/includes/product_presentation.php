<?php
/**
 * Projeção explícita do catálogo canônico no contexto de um vendor.
 *
 * A precedência vive aqui, sem filtros globais do WooCommerce, shortcodes ou
 * dependência de estoque. Gestão pode preparar conteúdo antes da elegibilidade.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compõe os campos efetivos; o chamador já validou produto e contexto.
 *
 * Texto do vendor desativado (`description_enabled` falso) fica guardado, mas a
 * descrição efetiva volta a ser a canônica.
 *
 * @return array<string,mixed>
 */
function papelito_product_presentation_compose( WC_Product $product, ?int $vendor_id, ?array $override ): array {
	$vendor_enabled     = false !== ( $override['description_enabled'] ?? true );
	$vendor_description = $vendor_enabled ? ( $override['description'] ?? null ) : null;
	$description        = $vendor_description ?? $product->get_description( 'edit' );
	$short_description  = $product->get_short_description( 'edit' );
	return array(
		'product_id'         => $product->get_id(),
		'vendor_id'          => $vendor_id,
		'description'        => $description,
		'summary'            => papelito_vendor_product_customization_has_text( $short_description ) ? $short_description : $description,
		'description_source' => null !== $vendor_description ? 'vendor' : 'papelito',
	);
}

/**
 * Resolve apresentação pública somente para produto publicado e seller elegível.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_product_presentation_resolve( int $product_id, ?int $vendor_id = null ): array|WP_Error {
	$product = papelito_vendor_product_customization_product( $product_id );
	if ( is_wp_error( $product ) ) {
		return $product;
	}
	$override = null;
	if ( null !== $vendor_id ) {
		if ( ! papelito_user_is_effective_seller( $vendor_id ) || ! papelito_vendor_is_eligible_to_sell( $vendor_id ) ) {
			return new WP_Error( 'papelito_customization_vendor_unavailable', 'O vendor informado não está disponível para venda.', array( 'status' => 403 ) );
		}
		$rows = papelito_vendor_product_overrides_get_many( $vendor_id, array( $product->get_id() ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$override = $rows[ $product->get_id() ] ?? null;
	}
	return papelito_product_presentation_compose( $product, $vendor_id, $override );
}
