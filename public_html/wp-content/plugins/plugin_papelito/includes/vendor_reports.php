<?php
/**
 * Exportacoes do painel do vendor.
 *
 * Reaproveita os geradores de planilha do relatorio administrativo; o que muda
 * aqui e o recorte, que sempre parte dos pedidos daquele vendor. O escopo vive
 * no servidor: nada de vendor_id vindo do navegador.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pedidos pagos do vendor dentro do periodo.
 *
 * @param int                   $vendor_id Vendor.
 * @param array<string, string> $filters   Filtros com from e to.
 * @return array<int, object>
 */
function papelito_vendor_reports_paid_orders( int $vendor_id, array $filters ): array {
	$orders = papelito_vendor_dashboard_orders_for_vendor( $vendor_id );

	return array_values(
		array_filter(
			$orders,
			static function ( $order ) use ( $filters ): bool {
				if ( ! papelito_vendor_dashboard_in_period( $order, $filters['from'], $filters['to'] ) ) {
					return false;
				}

				return papelito_vendor_dashboard_order_is_paid( $order );
			}
		)
	);
}

/**
 * Linhas do export de vendas do vendor.
 *
 * Mesmas colunas do export administrativo, para nao existirem dois formatos de
 * planilha de venda no produto.
 *
 * @param int                   $vendor_id Vendor.
 * @param array<string, string> $filters   Filtros.
 * @return array<int, array<string, string|float|int>>
 */
function papelito_vendor_reports_sales_rows( int $vendor_id, array $filters ): array {
	$rows = array();

	foreach ( papelito_vendor_reports_paid_orders( $vendor_id, $filters ) as $order ) {
		$rows[] = array(
			'order_id'       => (int) $order->get_id(),
			'order_number'   => (string) $order->get_order_number(),
			'created_at'     => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i:s' ) : '',
			'status'         => function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : (string) $order->get_status(),
			'customer_name'  => papelito_admin_reports_order_customer_name( $order ),
			'phone'          => (string) $order->get_billing_phone(),
			'postcode'       => (string) $order->get_billing_postcode(),
			'city'           => (string) $order->get_billing_city(),
			'state'          => (string) $order->get_billing_state(),
			'payment_method' => (string) $order->get_payment_method_title(),
			'total'          => (float) $order->get_total(),
		);
	}

	return $rows;
}

/**
 * Id do usuario comprador de um pedido.
 *
 * @param object $order Pedido.
 * @return int
 */
function papelito_vendor_reports_order_customer_id( $order ): int {
	$customer_id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;

	if ( $customer_id > 0 ) {
		return $customer_id;
	}

	return method_exists( $order, 'get_meta' )
		? (int) $order->get_meta( '_papelito_buyer_user_id', true )
		: 0;
}

/**
 * Linhas do export de clientes do vendor.
 *
 * Somente contas que compraram daquele vendor. Pedido de convidado nao entra:
 * sem conta, nao ha usuario a exportar — o nome dele ja consta no export de vendas.
 *
 * @param int                   $vendor_id Vendor.
 * @param array<string, string> $filters   Filtros.
 * @return array<int, array<string, string|int>>
 */
function papelito_vendor_reports_customers_rows( int $vendor_id, array $filters ): array {
	$rows = array();

	foreach ( papelito_vendor_reports_paid_orders( $vendor_id, $filters ) as $order ) {
		$customer_id = papelito_vendor_reports_order_customer_id( $order );

		if ( $customer_id <= 0 || isset( $rows[ $customer_id ] ) ) {
			continue;
		}

		$user = get_userdata( $customer_id );

		if ( ! $user ) {
			continue;
		}

		$rows[ $customer_id ] = array(
			'user_id'  => $customer_id,
			'username' => (string) $user->user_login,
			'email'    => (string) $user->user_email,
			'phone'    => (string) $order->get_billing_phone(),
			'postcode' => (string) $order->get_billing_postcode(),
			'city'     => (string) $order->get_billing_city(),
			'state'    => (string) $order->get_billing_state(),
		);
	}

	return array_values( $rows );
}

/**
 * Entrega o binario do export com o nome de arquivo do vendor.
 *
 * @param string|WP_Error $binary   Conteudo.
 * @param string          $slug     Prefixo do arquivo.
 * @param string          $format   xlsx|csv.
 * @return WP_Error|void
 */
function papelito_vendor_reports_download( $binary, string $slug, string $format ) {
	if ( is_wp_error( $binary ) ) {
		return $binary;
	}

	$extension    = 'csv' === $format ? 'csv' : 'xlsx';
	$content_type = 'csv' === $format
		? 'text/csv; charset=UTF-8'
		: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	papelito_admin_reports_output_download(
		$binary,
		$slug . '-' . wp_date( 'Y-m-d' ) . '.' . $extension,
		$content_type
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			PAPELITO_REST_NAMESPACE,
			'/vendor/me/reports/sales/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
				'callback'            => static function ( WP_REST_Request $request ) {
					$filters = papelito_admin_reports_parse_simple_export_filters( $request );
					$rows    = papelito_vendor_reports_sales_rows( get_current_user_id(), $filters );
					$binary  = 'csv' === $filters['format']
						? papelito_admin_reports_generate_simple_sales_csv( $rows )
						: papelito_admin_reports_generate_simple_sales_xlsx( $rows );

					return papelito_vendor_reports_download( $binary, 'minhas-vendas', $filters['format'] );
				},
			)
		);

		register_rest_route(
			PAPELITO_REST_NAMESPACE,
			'/vendor/me/reports/customers/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
				'callback'            => static function ( WP_REST_Request $request ) {
					$filters = papelito_admin_reports_parse_simple_export_filters( $request );
					$rows    = papelito_vendor_reports_customers_rows( get_current_user_id(), $filters );
					$binary  = 'csv' === $filters['format']
						? papelito_admin_reports_generate_simple_users_csv( $rows )
						: papelito_admin_reports_generate_simple_users_xlsx( $rows );

					return papelito_vendor_reports_download( $binary, 'meus-clientes', $filters['format'] );
				},
			)
		);
	}
);
