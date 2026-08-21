<?php
/**
 * Precificação autoritativa de carrinho, campanhas e cupons.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PRICING_MAX_ITEMS' ) ) {
	define( 'PAPELITO_PRICING_MAX_ITEMS', 120 );
}

const PAPELITO_PRICING_INSTALLMENT_CONFIG_OPTION = 'papelito_pricing_installment_config';
const PAPELITO_PRICING_DEFAULT_MAX_INSTALLMENTS = 6;
const PAPELITO_PRICING_DEFAULT_INSTALLMENT_MINIMUM_CENTS = 100;
const PAPELITO_PRICING_MAX_INSTALLMENTS_LIMIT = 12;

function papelito_pricing_normalize_positive_int( $value ): ?int {
	if ( is_int( $value ) && $value > 0 ) {
		return $value;
	}

	if ( is_string( $value ) && 1 === preg_match( '/^[1-9]\d*$/', $value ) ) {
		$normalized = (int) $value;
		return $normalized > 0 ? $normalized : null;
	}

	return null;
}

function papelito_pricing_normalize_installment_count( $value ): ?int {
	$normalized = papelito_pricing_normalize_positive_int( $value );
	return null !== $normalized && $normalized <= PAPELITO_PRICING_MAX_INSTALLMENTS_LIMIT ? $normalized : null;
}

function papelito_pricing_get_installment_config(): array {
	$value = get_option( PAPELITO_PRICING_INSTALLMENT_CONFIG_OPTION, array() );
	$value = is_array( $value ) ? $value : array();

	return array(
		'maxInstallments'         => papelito_pricing_normalize_installment_count( $value['maxInstallments'] ?? null )
			?? PAPELITO_PRICING_DEFAULT_MAX_INSTALLMENTS,
		'installmentMinimumCents' => papelito_pricing_normalize_positive_int( $value['installmentMinimumCents'] ?? null )
			?? PAPELITO_PRICING_DEFAULT_INSTALLMENT_MINIMUM_CENTS,
	);
}

function papelito_pricing_get_installment_config_snapshot(): array {
	return papelito_pricing_get_installment_config();
}

function papelito_pricing_update_installment_config( $max_installments, $installment_minimum_cents ) {
	$max_installments          = papelito_pricing_normalize_installment_count( $max_installments );
	$installment_minimum_cents = papelito_pricing_normalize_positive_int( $installment_minimum_cents );

	if ( null === $max_installments || null === $installment_minimum_cents ) {
		return new WP_Error(
			'papelito_pricing_invalid_installment_config',
			'Informe de 1 a ' . PAPELITO_PRICING_MAX_INSTALLMENTS_LIMIT . ' parcelas e um valor mínimo positivo por parcela.',
			array( 'status' => 422 )
		);
	}

	update_option(
		PAPELITO_PRICING_INSTALLMENT_CONFIG_OPTION,
		array(
			'maxInstallments'         => $max_installments,
			'installmentMinimumCents' => $installment_minimum_cents,
		),
		false
	);

	return papelito_pricing_get_installment_config_snapshot();
}

function papelito_pricing_require_admin() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	return new WP_Error(
		'papelito_pricing_forbidden',
		'Acesso administrativo necessário.',
		array( 'status' => 403 )
	);
}

/**
 * Converte valor monetário em centavos inteiros.
 *
 * @param mixed $value Valor monetário.
 * @return int
 */
function papelito_pricing_to_cents( $value ): int {
	$decimal = function_exists( 'wc_format_decimal' )
		? wc_format_decimal( wp_unslash( (string) $value ) )
		: (string) $value;

	return is_numeric( $decimal ) ? max( 0, (int) round( (float) $decimal * 100 ) ) : 0;
}

/**
 * Converte centavos para o formato monetário usado pelo WooCommerce.
 */
function papelito_pricing_from_cents( int $value ): float {
	return round( max( 0, $value ) / 100, 2 );
}

/**
 * Nome autoritativo do vendor.
 */
function papelito_pricing_vendor_name( int $vendor_id ): string {
	$meta_name = sanitize_text_field( (string) get_user_meta( $vendor_id, 'store_name', true ) );
	if ( '' !== $meta_name ) {
		return $meta_name;
	}

	$user = get_userdata( $vendor_id );
	return $user instanceof WP_User ? sanitize_text_field( (string) $user->display_name ) : '';
}

/**
 * Normaliza somente os campos que o browser pode declarar sobre uma linha.
 * Preços enviados pelo cliente são deliberadamente ignorados.
 *
 * @param mixed $items Linhas cruas.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_pricing_normalize_items( $items ) {
	if ( ! is_array( $items ) || empty( $items ) ) {
		return new WP_Error( 'papelito_checkout_empty_items', 'Carrinho vazio para concluir o pedido.', array( 'status' => 422 ) );
	}

	if ( count( $items ) > PAPELITO_PRICING_MAX_ITEMS ) {
		return new WP_Error(
			'papelito_checkout_too_many_items',
			'O carrinho excedeu o limite de itens permitido.',
			array( 'status' => 422, 'maximum' => PAPELITO_PRICING_MAX_ITEMS )
		);
	}

	$normalized = array();
	$product_ids = array();
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$product_id = absint( $item['product_id'] ?? $item['productId'] ?? 0 );
		$qty        = max( 0, (int) ( $item['qty'] ?? $item['quantity'] ?? 0 ) );
		$vendor_id  = absint( $item['vendor_id'] ?? $item['vendorId'] ?? 0 );
		$context    = sanitize_text_field( (string) ( $item['promotion_context'] ?? $item['promotionContext'] ?? '' ) );

		if ( $product_id <= 0 || $qty <= 0 || $vendor_id <= 0 ) {
			return new WP_Error( 'papelito_checkout_invalid_items', 'Itens invalidos para concluir o pedido.', array( 'status' => 422 ) );
		}

		if ( isset( $product_ids[ $product_id ] ) ) {
			return new WP_Error(
				'papelito_checkout_duplicate_item',
				'O carrinho possui produtos duplicados.',
				array( 'status' => 422, 'product_id' => $product_id )
			);
		}
		$product_ids[ $product_id ] = true;

		$normalized[] = array(
			'product_id'       => $product_id,
			'qty'              => $qty,
			'vendor_id'        => $vendor_id,
			'vendor_name'      => sanitize_text_field( (string) ( $item['vendor_name'] ?? $item['vendorName'] ?? '' ) ),
			'promotion_context' => $context,
		);
	}

	return empty( $normalized )
		? new WP_Error( 'papelito_checkout_empty_items', 'Carrinho vazio para concluir o pedido.', array( 'status' => 422 ) )
		: $normalized;
}

/**
 * Resolve produto, estoque, vendor e possíveis campanhas sem aplicar cupom.
 *
 * @param array<int,array<string,mixed>> $items Linhas normalizadas.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pricing_resolve_items( array $items ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error( 'papelito_checkout_woocommerce_unavailable', 'WooCommerce indisponivel para concluir o pedido.', array( 'status' => 500 ) );
	}

	$vendor_ids = array_values( array_unique( array_map( static fn( array $item ): int => (int) $item['vendor_id'], $items ) ) );
	if ( 1 !== count( $vendor_ids ) ) {
		return new WP_Error( 'papelito_checkout_mixed_vendor_not_supported', 'O checkout atual suporta apenas um vendor por pedido.', array( 'status' => 422 ) );
	}

	$vendor_id = (int) $vendor_ids[0];
	$vendor    = papelito_shipping_get_vendor( $vendor_id );
	if ( is_wp_error( $vendor ) ) {
		return $vendor;
	}

	$vendor_name = papelito_pricing_vendor_name( $vendor_id );
	$lines       = array();
	$adjustments = array();

	foreach ( $items as $item ) {
		$product = wc_get_product( $item['product_id'] );
		if ( ! $product ) {
			return new WP_Error( 'papelito_product_not_found', 'Produto do carrinho nao encontrado.', array( 'status' => 404 ) );
		}

		$is_kit = function_exists( 'papelito_kit_is_product' ) && papelito_kit_is_product( (int) $item['product_id'] );
		$current_stock = (int) papelito_get_vendor_stock( $vendor_id, $item['product_id'] );
		if ( $is_kit ) {
			$kit_stock = papelito_kit_vendor_has_stock( (int) $item['product_id'], (int) $item['qty'], $vendor_id );
			if ( is_wp_error( $kit_stock ) ) {
				return $kit_stock;
			}
		} elseif ( $current_stock < (int) $item['qty'] ) {
			return new WP_Error(
				'papelito_checkout_insufficient_stock',
				sprintf( 'Estoque insuficiente para o produto "%s".', $product->get_name() ),
				array( 'status' => 409, 'product_id' => (int) $item['product_id'], 'available' => $current_stock, 'requested' => (int) $item['qty'] )
			);
		}

		$normal_unit_cents = papelito_pricing_to_cents( $product->get_price( 'edit' ) );
		$promotion = null;
		$campaign  = function_exists( 'papelito_flash_sale_get_active_campaign_for_product' )
			? papelito_flash_sale_get_active_campaign_for_product( (int) $item['product_id'] )
			: null;
		$context   = is_array( $campaign ) && function_exists( 'papelito_flash_sale_create_promotion_context' )
			? papelito_flash_sale_create_promotion_context( $campaign, (int) $item['product_id'] )
			: '';

		if ( is_array( $campaign ) ) {
			$reference_unit_cents = papelito_pricing_to_cents( $product->get_regular_price( 'edit' ) );
			if ( $reference_unit_cents <= 0 ) {
				$reference_unit_cents = $normal_unit_cents;
			}
			$discount_percent = min( 99, max( 0, (int) ( $campaign['discountPercent'] ?? 0 ) ) );
			$promotion        = array(
				'reference_unit_cents' => $reference_unit_cents,
				'total_cents'          => ( (int) round( $reference_unit_cents * ( 100 - $discount_percent ) / 100 ) ) * (int) $item['qty'],
			);
		}

		$lines[] = array(
			'product'                => $product,
			'product_id'             => (int) $item['product_id'],
			'qty'                    => (int) $item['qty'],
			'vendor_id'              => $vendor_id,
			'vendor_name'            => $vendor_name,
			'normal_unit_cents'      => $normal_unit_cents,
			'normal_subtotal_cents'  => $normal_unit_cents * (int) $item['qty'],
			'promotion'              => $promotion,
			'promotion_context'      => $context,
			'kit_snapshot'           => $is_kit ? papelito_kit_snapshot( (int) $item['product_id'], (int) $item['qty'] ) : array(),
		);
	}

	return array( 'vendor_id' => $vendor_id, 'vendor_name' => $vendor_name, 'lines' => $lines, 'adjustments' => $adjustments );
}

/**
 * Rateia centavos de desconto preservando o total exato.
 *
 * @param array<int,array<string,mixed>> $lines Linhas base.
 * @param array<int>                     $product_ids Produtos elegíveis.
 * @return array<int,int> Desconto por índice.
 */
function papelito_pricing_allocate_discount( array $lines, array $product_ids, int $discount_cents ): array {
	$indexes  = array();
	$subtotal = 0;
	foreach ( $lines as $index => $line ) {
		if ( in_array( (int) $line['product_id'], $product_ids, true ) ) {
			$indexes[] = $index;
			$subtotal += (int) $line['normal_subtotal_cents'];
		}
	}

	if ( empty( $indexes ) || $subtotal <= 0 || $discount_cents <= 0 ) {
		return array();
	}

	$remaining  = min( $discount_cents, $subtotal );
	$allocation = array();
	$last       = count( $indexes ) - 1;
	foreach ( $indexes as $position => $index ) {
		$line_subtotal = (int) $lines[ $index ]['normal_subtotal_cents'];
		$share         = $position === $last
			? $remaining
			: (int) round( $line_subtotal * $discount_cents / $subtotal );
		$share                = max( 0, min( $share, $line_subtotal, $remaining ) );
		$allocation[ $index ] = $share;
		$remaining           -= $share;
	}

	return $allocation;
}

/**
 * Aplica cupom e campanha escolhendo o menor total por linha.
 *
 * @param array<string,mixed> $resolved Itens autoritativos.
 * @return array<string,mixed>|WP_Error
 */
function papelito_pricing_apply_discounts( array $resolved, string $coupon_code, int $user_id, int $shipping_cents = 0 ) {
	$lines       = (array) ( $resolved['lines'] ?? array() );
	$adjustments = (array) ( $resolved['adjustments'] ?? array() );
	$coupon      = null;
	$allocation  = array();
	$code        = strtoupper( trim( sanitize_text_field( $coupon_code ) ) );

	if ( '' !== $code ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'papelito_coupon_auth_required', 'Faca login para aplicar cupons.', array( 'status' => 401 ) );
		}

		$coupon_lines = array_filter(
			$lines,
			static fn( array $line ): bool => ! is_array( $line['promotion'] ?? null )
		);
		$cart_items = array_map(
			static fn( array $line ): array => array(
				'product_id' => (int) $line['product_id'],
				'vendor_id'  => (int) $line['vendor_id'],
				'qty'        => (int) $line['qty'],
				'price'      => papelito_pricing_from_cents( (int) $line['normal_unit_cents'] ),
			),
			$lines
		);
		$coupon = papelito_coupon_apply_resolve( $code, $cart_items, $user_id );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}
		$eligible_product_ids = array_map(
			static fn( array $line ): int => (int) $line['product_id'],
			$coupon_lines
		);
		$allocation = papelito_pricing_allocate_discount(
			$lines,
			array_values(
				array_intersect(
					array_map( 'intval', (array) ( $coupon['applied_product_ids'] ?? array() ) ),
					$eligible_product_ids
				)
			),
			papelito_pricing_to_cents( $coupon['discount_value'] ?? 0 )
		);
	}

	$priced_lines             = array();
	$effective_coupon_cents   = 0;
	$subtotal_cents           = 0;
	$discount_cents           = 0;
	$items_total_cents        = 0;

	foreach ( $lines as $index => $line ) {
		$normal_subtotal = (int) $line['normal_subtotal_cents'];
		$coupon_discount = (int) ( $allocation[ $index ] ?? 0 );
		$coupon_total    = max( 0, $normal_subtotal - $coupon_discount );
		$promotion       = is_array( $line['promotion'] ?? null ) ? $line['promotion'] : null;

		$source        = $coupon_discount > 0 ? 'coupon' : 'none';
		$line_subtotal = $normal_subtotal;
		$line_total    = $coupon_total;
		$line_discount = $coupon_discount;

		if ( null !== $promotion ) {
			$source        = 'flash_sale';
			$line_subtotal = (int) $promotion['reference_unit_cents'] * (int) $line['qty'];
			$line_total    = (int) $promotion['total_cents'];
			$line_discount = max( 0, $line_subtotal - $line_total );
		} elseif ( 'coupon' === $source ) {
			$effective_coupon_cents += $coupon_discount;
		}

		$subtotal_cents    += $line_subtotal;
		$discount_cents    += $line_discount;
		$items_total_cents += $line_total;

		$priced_lines[] = array_merge(
			$line,
			array(
				'productId'        => (int) $line['product_id'],
				'vendorId'         => (int) $line['vendor_id'],
				'unit_price'       => papelito_pricing_from_cents( (int) round( $line_total / max( 1, (int) $line['qty'] ) ) ),
				'subtotal'        => papelito_pricing_from_cents( $line_subtotal ),
				'total'           => papelito_pricing_from_cents( $line_total ),
				'discount'        => papelito_pricing_from_cents( $line_discount ),
				'subtotal_cents'  => $line_subtotal,
				'total_cents'     => $line_total,
				'discount_cents'  => $line_discount,
				'discount_source' => $source,
				'normalUnitCents'  => (int) $line['normal_unit_cents'],
				'subtotalCents'    => $line_subtotal,
				'totalCents'       => $line_total,
				'discountCents'    => $line_discount,
				'discountSource'   => $source,
				'promotionContext' => (string) ( $line['promotion_context'] ?? '' ),
			)
		);
	}

	$coupon_response = null;
	if ( is_array( $coupon ) ) {
		$coupon_response = array(
			'code'               => (string) $coupon['code'],
			'discountType'       => (string) $coupon['discount_type'],
			'discountValueCents' => $effective_coupon_cents,
			'appliedProductIds'  => array_map( 'intval', (array) $coupon['applied_product_ids'] ),
			'applied'            => $effective_coupon_cents > 0,
		);
		if ( 0 === $effective_coupon_cents ) {
			$coupon_response['message'] = 'A oferta relâmpago já concede um desconto maior; o cupom não reduziu o total.';
			$adjustments[] = array( 'type' => 'coupon_no_additional_discount', 'message' => $coupon_response['message'] );
		}
		$coupon['discount_value'] = papelito_pricing_from_cents( $effective_coupon_cents );
	}

	$shipping_cents = max( 0, $shipping_cents );
	$total_cents    = $items_total_cents + $shipping_cents;

	return array(
		'vendor_id'   => (int) ( $resolved['vendor_id'] ?? 0 ),
		'vendor_name' => (string) ( $resolved['vendor_name'] ?? '' ),
		'lines'       => $priced_lines,
		'coupon_data' => $effective_coupon_cents > 0 ? $coupon : null,
		'coupon'      => $coupon_response,
		'adjustments' => $adjustments,
		'totals'      => array(
			'subtotalCents' => $subtotal_cents,
			'discountCents' => $discount_cents,
			'itemsCents'    => $items_total_cents,
			'shippingCents' => $shipping_cents,
			'totalCents'    => $total_cents,
		),
		'paymentRestrictions' => papelito_pricing_payment_restrictions( $total_cents ),
	);
}

/**
 * Resolve uma cotação completa a partir de linhas não confiáveis.
 *
 * @param mixed                    $items              Linhas cruas.
 * @param array<string,mixed>|null $shipping_selection Serviço de frete a revalidar.
 */
function papelito_pricing_quote( mixed $items, string $coupon_code, int $user_id, int $shipping_cents = 0, ?array $shipping_selection = null ) {
	$normalized = papelito_pricing_normalize_items( $items );
	if ( is_wp_error( $normalized ) ) {
		return $normalized;
	}

	$resolved = papelito_pricing_resolve_items( $normalized );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	if ( null !== $shipping_selection ) {
		if ( ! function_exists( 'papelito_shipping_normalize_cep' ) || ! function_exists( 'papelito_order_routing_resolve_shipping' ) ) {
			return new WP_Error( 'papelito_checkout_shipping_unavailable', 'Não foi possível recalcular o frete.', array( 'status' => 503 ) );
		}

		$destination_cep = papelito_shipping_normalize_cep( $shipping_selection['destination_cep'] ?? $shipping_selection['destinationCep'] ?? '' );
		$selected_code   = sanitize_text_field( (string) ( $shipping_selection['selected_code'] ?? $shipping_selection['selectedCode'] ?? '' ) );
		if ( '' === $destination_cep || '' === $selected_code ) {
			return new WP_Error( 'papelito_checkout_invalid_shipping', 'Selecione uma opção de frete válida.', array( 'status' => 422 ) );
		}

		$shipping = papelito_order_routing_resolve_shipping(
			(int) $resolved['vendor_id'],
			$destination_cep,
			$selected_code,
			(array) $resolved['lines']
		);
		if ( is_wp_error( $shipping ) ) {
			return $shipping;
		}
		$shipping_cents = papelito_pricing_to_cents( $shipping['price'] ?? 0 );
	}

	return papelito_pricing_apply_discounts( $resolved, $coupon_code, $user_id, $shipping_cents );
}

/**
 * Mínimos configuráveis por método, todos em centavos.
 */
function papelito_pricing_payment_minimum_cents( string $method ): int {
	$defaults = array( 'credit_card' => 100, 'pix' => 1, 'boleto' => 1 );
	$minimum  = (int) ( $defaults[ $method ] ?? 1 );
	return max( 1, (int) apply_filters( 'papelito_payment_minimum_cents', $minimum, $method ) );
}

function papelito_pricing_installment_minimum_cents(): int {
	$config = papelito_pricing_get_installment_config();
	return max( 1, (int) apply_filters( 'papelito_installment_minimum_cents', $config['installmentMinimumCents'] ) );
}

function papelito_pricing_max_installments(): int {
	$config = papelito_pricing_get_installment_config();
	return min( PAPELITO_PRICING_MAX_INSTALLMENTS_LIMIT, max( 1, (int) apply_filters( 'papelito_max_installments', $config['maxInstallments'] ) ) );
}

function papelito_pricing_payment_restrictions( int $total_cents ): array {
	$installment_minimum = papelito_pricing_installment_minimum_cents();
	$max_installments    = $total_cents > 0
		? min( papelito_pricing_max_installments(), intdiv( $total_cents, $installment_minimum ) )
		: 0;

	return array(
		'creditCardMinimumCents' => papelito_pricing_payment_minimum_cents( 'credit_card' ),
		'pixMinimumCents'        => papelito_pricing_payment_minimum_cents( 'pix' ),
		'boletoMinimumCents'     => papelito_pricing_payment_minimum_cents( 'boleto' ),
		'installmentMinimumCents' => $installment_minimum,
		'maxInstallments'         => max( 0, $max_installments ),
	);
}

/**
 * Bloqueia localmente valores que o método configurado não processa.
 *
 * @return true|WP_Error
 */
function papelito_pricing_validate_payment_amount( string $method, int $total_cents, int $installments = 1 ) {
	$minimum = papelito_pricing_payment_minimum_cents( $method );
	if ( $total_cents < $minimum ) {
		return new WP_Error(
			'papelito_checkout_amount_below_minimum',
			'O total mínimo para esta forma de pagamento é R$ ' . number_format( $minimum / 100, 2, ',', '.' ) . '.',
			array( 'status' => 422, 'minimum_cents' => $minimum, 'method' => $method )
		);
	}

	$installment_minimum = papelito_pricing_installment_minimum_cents();
	if ( 'credit_card' === $method && $installments > 1 && intdiv( $total_cents, $installments ) < $installment_minimum ) {
		return new WP_Error(
			'papelito_checkout_installment_below_minimum',
			'Cada parcela precisa ter valor mínimo de R$ ' . number_format( $installment_minimum / 100, 2, ',', '.' ) . '.',
			array( 'status' => 422, 'minimum_installment_cents' => $installment_minimum )
		);
	}

	if ( 'credit_card' === $method && $installments > papelito_pricing_max_installments() ) {
		return new WP_Error(
			'papelito_checkout_installments_exceeded',
			'O parcelamento máximo permitido é de ' . papelito_pricing_max_installments() . ' vezes.',
			array( 'status' => 422, 'maximum_installments' => papelito_pricing_max_installments() )
		);
	}

	return true;
}

/**
 * Protege a cotação pública contra abuso por IP.
 *
 * @return true|WP_Error
 */
function papelito_pricing_check_rate_limit() {
	if ( ! function_exists( 'papelito_auth_rate_limit' ) ) {
		return new WP_Error(
			'papelito_pricing_rate_limit_unavailable',
			'Não foi possível validar a cotação agora.',
			array( 'status' => 503 )
		);
	}

	if ( ! papelito_auth_rate_limit( 'cart_pricing', 60, 60 ) ) {
		return new WP_Error(
			'papelito_rate_limited',
			'Muitas tentativas de cotação. Tente novamente em alguns instantes.',
			array( 'status' => 429 )
		);
	}

	return true;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/home/payment-config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response(
						array(
							'maxInstallments'         => papelito_pricing_max_installments(),
							'installmentMinimumCents' => papelito_pricing_installment_minimum_cents(),
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/payment-config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_pricing_require_admin',
				'callback'            => static fn() => new WP_REST_Response( papelito_pricing_get_installment_config_snapshot(), 200 ),
			)
		);

		register_rest_route(
			'papelito/v1',
			'/admin/payment-config',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_pricing_require_admin',
				'callback'            => static function ( WP_REST_Request $request ) {
					$payload = $request->get_json_params();
					if ( ! is_array( $payload ) ) {
						return new WP_Error(
							'papelito_pricing_invalid_installment_config',
							'Informe máximo de parcelas e mínimo por parcela.',
							array( 'status' => 422 )
						);
					}

					$result = papelito_pricing_update_installment_config(
						$payload['maxInstallments'] ?? null,
						$payload['installmentMinimumCents'] ?? null
					);
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1',
			'/cart/pricing',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$rate_limit = papelito_pricing_check_rate_limit();
					if ( is_wp_error( $rate_limit ) ) {
						return $rate_limit;
					}

					$payload = $request->get_json_params();
					$payload = is_array( $payload ) ? $payload : array();
					$shipping_selection = isset( $payload['shipping'] ) && is_array( $payload['shipping'] )
						? $payload['shipping']
						: null;
					$quote              = papelito_pricing_quote(
						$payload['items'] ?? array(),
						(string) ( $payload['coupon_code'] ?? '' ),
						get_current_user_id(),
						0,
						$shipping_selection
					);

					return is_wp_error( $quote ) ? $quote : new WP_REST_Response( $quote, 200 );
				},
			),
		);
	}
);
