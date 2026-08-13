<?php
/**
 * Superfície REST dos benefícios da página de produto.
 *
 * Roteamento, sanitização e formato de resposta apenas — o domínio vive em
 * `product_benefits.php`. Toda lógica fica fora das closures de propósito: é o
 * que permite ao teste standalone exercitar validação e resolução sem WordPress.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Só administrador escreve configuração de benefícios.
 *
 * @return bool
 */
function papelito_benefits_admin_permission() {
	return current_user_can( 'manage_options' );
}

/**
 * Payload público de benefícios de um produto.
 *
 * O texto plano acompanha o conteúdo rico porque é o degrau de degradação
 * quando um token não resolve — o frontend decide, mas precisa do material.
 *
 * @param int $product_id Id do produto.
 * @return array<string,mixed>
 */
function papelito_benefits_public_payload( $product_id ) {
	if ( ! papelito_taxonomy_is_product( $product_id ) ) {
		return array(
			'groupId' => 0,
			'source'  => 'none',
			'items'   => array(),
		);
	}

	$resolved = papelito_product_benefits_resolve( $product_id );
	$items    = array();

	foreach ( $resolved['items'] as $item ) {
		$items[] = array(
			'id'                 => $item['id'],
			'iconType'           => $item['iconType'],
			'iconEmoji'          => $item['iconEmoji'],
			'iconUrl'            => $item['iconUrl'],
			'title'              => $item['title'],
			'description'        => $item['description'],
			'descriptionContent' => $item['descriptionContent'],
		);
	}

	return array(
		'groupId' => $resolved['groupId'],
		'source'  => $resolved['source'],
		'items'   => $items,
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/products/(?P<id>\d+)/benefits',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					return new WP_REST_Response(
						papelito_benefits_public_payload( (int) $request['id'] ),
						200
					);
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/benefit-groups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_benefits_admin_permission',
					'callback'            => static function (): WP_REST_Response {
						return new WP_REST_Response( papelito_product_benefits_admin_snapshot(), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_benefits_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$validated = papelito_benefit_validate_group_payload( $request->get_json_params() );

						if ( is_wp_error( $validated ) ) {
							return $validated;
						}

						$created = papelito_benefit_group_create( $validated );

						if ( is_wp_error( $created ) ) {
							return $created;
						}

						return new WP_REST_Response( papelito_product_benefits_admin_snapshot(), 201 );
					},
				),
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/benefit-groups/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_benefits_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$group = papelito_benefit_group_get( (int) $request['id'] );

						if ( null === $group ) {
							return papelito_benefits_error(
								'papelito_benefit_group_not_found',
								'Configuração não encontrada.',
								404
							);
						}

						return new WP_REST_Response( papelito_benefit_group_admin_shape( $group ), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_benefits_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$group_id = (int) $request['id'];
						$group    = papelito_benefit_group_get( $group_id );

						if ( null === $group ) {
							return papelito_benefits_error(
								'papelito_benefit_group_not_found',
								'Configuração não encontrada.',
								404
							);
						}

						$validated = papelito_benefit_validate_group_payload(
							$request->get_json_params(),
							$group['isGlobal']
						);

						if ( is_wp_error( $validated ) ) {
							return $validated;
						}

						$updated = papelito_benefit_group_update( $group_id, $validated );

						if ( is_wp_error( $updated ) ) {
							return $updated;
						}

						return new WP_REST_Response( papelito_product_benefits_admin_snapshot(), 200 );
					},
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => 'papelito_benefits_admin_permission',
					'callback'            => static function ( WP_REST_Request $request ) {
						$deleted = papelito_benefit_group_delete( (int) $request['id'] );

						if ( is_wp_error( $deleted ) ) {
							return $deleted;
						}

						return new WP_REST_Response( papelito_product_benefits_admin_snapshot(), 200 );
					},
				),
			)
		);
	}
);
