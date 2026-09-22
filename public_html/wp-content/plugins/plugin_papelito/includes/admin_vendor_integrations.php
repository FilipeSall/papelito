<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.
/**
 * Superfície administrativa das integrações de transportadora de um vendor.
 *
 * Existe por conveniência operacional: boa parte dos vendors não quer
 * configurar a transportadora, e a Papelito faz isso por eles. Por isso o
 * módulo **não** cria estado próprio — ele escreve na mesma linha, no mesmo
 * cofre e no mesmo interruptor `enabled` que o painel do vendor usa, através
 * das funções de `vendor_integrations.php`. O vendor continua dono da própria
 * integração e pode ligar, desligar ou trocar a credencial quando quiser.
 *
 * O que este módulo deliberadamente não faz: não pede a senha de ninguém (a
 * capability do administrador é a autorização), não devolve credencial em
 * nenhuma resposta e não introduz um estado "bloqueado pela Papelito" — quem
 * desliga aqui desliga o mesmo interruptor que o vendor vê.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Teto de escrita administrativa por janela.
 *
 * É mais folgado que o do vendor de propósito: o administrador atende vários
 * vendors em sequência, e o limite aqui existe contra laço automatizado, não
 * contra o ritmo de quem trabalha na fila.
 */
const PAPELITO_ADMIN_VENDOR_INTEGRATION_RATE_LIMIT  = 30;
const PAPELITO_ADMIN_VENDOR_INTEGRATION_RATE_WINDOW = 300;

/**
 * Ações administrativas registradas na trilha da integração.
 *
 * São distintas das do vendor para que a leitura da trilha responda quem agiu
 * sem depender de cruzar `actor_user_id` com a tabela de usuários.
 */
const PAPELITO_ADMIN_VENDOR_INTEGRATION_ACTION_READ    = 'admin_configuration_read';
const PAPELITO_ADMIN_VENDOR_INTEGRATION_ACTION_WRITE   = 'admin_configuration_saved';
const PAPELITO_ADMIN_VENDOR_INTEGRATION_ACTION_REMOVED = 'admin_removed';

/**
 * Integrações que o painel administrativo sabe exibir.
 *
 * A lista é o ponto de extensão da tela: uma transportadora nova entra aqui com
 * o seu rótulo e passa a aparecer no painel sem que o frontend precise saber
 * quantos provedores existem. Hoje só a Braspress tem configuração por vendor.
 *
 * @return array<int,array<string,string>> Catálogo com provider, rótulo e natureza.
 */
function papelito_admin_vendor_integrations_catalog(): array {
	return array(
		array(
			'provider' => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
			'label'    => 'Braspress',
			'kind'     => 'carrier',
		),
	);
}

/**
 * Porteiro das rotas administrativas, com a recusa registrada na trilha.
 *
 * @param string $action Ação que o ator tentaria executar.
 * @return true|WP_Error Resultado do porteiro.
 */
function papelito_admin_vendor_integrations_permission( string $action ): mixed {
	$check = current_user_can( 'manage_options' )
		? true
		: new WP_Error( 'papelito_admin_vendor_integration_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );

	return papelito_vendor_integration_audit_permission( $check, $action );
}

/**
 * Porteiro de leitura das integrações de um vendor.
 *
 * @return true|WP_Error Resultado do porteiro.
 */
function papelito_admin_vendor_integrations_permission_read(): mixed {
	return papelito_admin_vendor_integrations_permission( PAPELITO_ADMIN_VENDOR_INTEGRATION_ACTION_READ );
}

/**
 * Porteiro de escrita das integrações de um vendor.
 *
 * @return true|WP_Error Resultado do porteiro.
 */
function papelito_admin_vendor_integrations_permission_write(): mixed {
	return papelito_admin_vendor_integrations_permission( PAPELITO_ADMIN_VENDOR_INTEGRATION_ACTION_WRITE );
}

/**
 * Confirma que o id da rota é mesmo um vendor antes de tocar na integração.
 *
 * Sem esta checagem a rota gravaria uma linha de integração para um id de
 * comprador, que nenhuma tela leria e nenhuma cotação usaria — lixo silencioso
 * numa tabela com chave única por vendor.
 *
 * @param int $vendor_id ID recebido na rota.
 * @return int|WP_Error ID validado ou recusa.
 */
function papelito_admin_vendor_integrations_require_vendor( int $vendor_id ) {
	$user = $vendor_id > 0 ? get_user_by( 'id', $vendor_id ) : null;

	if ( ! $user instanceof WP_User || ! papelito_user_has_role( $user, 'seller' ) ) {
		return new WP_Error( 'papelito_admin_vendor_integration_vendor_not_found', 'Vendor não encontrado.', array( 'status' => 404 ) );
	}

	return $vendor_id;
}

/**
 * Aplica o limite de escrita administrativa ao administrador autenticado.
 *
 * O balde é do administrador, e não do vendor alvo: quem se protege contra o
 * laço é o autor da chamada, e um balde por vendor deixaria o mesmo ator
 * escrever sem limite bastando trocar o id da rota.
 *
 * @param int $actor_user_id ID do administrador autenticado.
 * @return true|WP_Error Liberação ou recusa.
 */
function papelito_admin_vendor_integrations_rate_limit( int $actor_user_id ) {
	$allowed = papelito_auth_rate_limit(
		'admin_vendor_integration_write',
		PAPELITO_ADMIN_VENDOR_INTEGRATION_RATE_LIMIT,
		PAPELITO_ADMIN_VENDOR_INTEGRATION_RATE_WINDOW,
		'user:' . $actor_user_id
	);

	if ( ! $allowed ) {
		return new WP_Error( 'papelito_admin_vendor_integration_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	return true;
}

/**
 * Monta o estado público de todas as integrações conhecidas de um vendor.
 *
 * Devolve uma entrada por provider do catálogo mesmo quando o vendor nunca
 * configurou nenhuma: a tela precisa saber que a Braspress existe e está por
 * configurar, e não apenas que a lista veio vazia.
 *
 * @param int $vendor_id ID do vendor.
 * @return array<string,mixed> Envelope com a lista de integrações, sem segredos.
 */
function papelito_admin_vendor_integrations_snapshot( int $vendor_id ): array {
	$items = array();

	foreach ( papelito_admin_vendor_integrations_catalog() as $entry ) {
		$row = papelito_vendor_integration_find_row( $vendor_id, $entry['provider'] );

		$items[] = array_merge(
			$entry,
			papelito_vendor_integration_public_record( $row, $vendor_id )
		);
	}

	return array(
		'vendor_id' => $vendor_id,
		'items'     => $items,
	);
}

/**
 * Grava a configuração Braspress de um vendor em nome da operação.
 *
 * Reaproveita `papelito_vendor_integration_write_braspress()`, que é a única
 * escrita da integração — validação de CEP, par usuário/senha, cifragem,
 * recálculo de status e trilha são exatamente os mesmos do painel do vendor.
 * O que muda é só quem assina: `actor_user_id` é o administrador.
 *
 * @param int                 $vendor_id ID do vendor alvo.
 * @param array<string,mixed> $payload Corpo da requisição.
 * @param int                 $actor_user_id ID do administrador autenticado.
 * @return array<string,mixed>|WP_Error Estado público ou erro.
 */
function papelito_admin_vendor_integration_save_braspress( int $vendor_id, array $payload, int $actor_user_id ) {
	$limited = papelito_admin_vendor_integrations_rate_limit( $actor_user_id );
	if ( is_wp_error( $limited ) ) {
		return $limited;
	}

	return papelito_vendor_integration_audit_attempt(
		$vendor_id,
		$actor_user_id,
		PAPELITO_ADMIN_VENDOR_INTEGRATION_ACTION_WRITE,
		papelito_vendor_integration_write_braspress( $vendor_id, $payload, $actor_user_id )
	);
}

/**
 * Remove a integração Braspress de um vendor em nome da operação.
 *
 * @param int $vendor_id ID do vendor alvo.
 * @param int $actor_user_id ID do administrador autenticado.
 * @return array<string,mixed>|WP_Error Estado vazio ou erro.
 */
function papelito_admin_vendor_integration_delete_braspress( int $vendor_id, int $actor_user_id ) {
	$limited = papelito_admin_vendor_integrations_rate_limit( $actor_user_id );
	if ( is_wp_error( $limited ) ) {
		return $limited;
	}

	return papelito_vendor_integration_audit_attempt(
		$vendor_id,
		$actor_user_id,
		PAPELITO_ADMIN_VENDOR_INTEGRATION_ACTION_REMOVED,
		papelito_vendor_integration_erase_braspress( $vendor_id, $actor_user_id )
	);
}

/**
 * GET /admin/vendors/{id}/integrations — estado de todas as integrações.
 *
 * @param WP_REST_Request $request Requisição autenticada.
 * @return WP_REST_Response|WP_Error Resposta sem segredos ou recusa.
 */
function papelito_admin_vendor_integrations_handle_list( WP_REST_Request $request ) {
	$vendor_id = papelito_admin_vendor_integrations_require_vendor( (int) $request['id'] );
	if ( is_wp_error( $vendor_id ) ) {
		return $vendor_id;
	}

	return new WP_REST_Response( papelito_admin_vendor_integrations_snapshot( $vendor_id ), 200 );
}

/**
 * PUT /admin/vendors/{id}/integrations/braspress — grava parâmetros e credencial.
 *
 * @param WP_REST_Request $request Requisição autenticada.
 * @return WP_REST_Response|WP_Error Estado público ou recusa.
 */
function papelito_admin_vendor_integrations_handle_save_braspress( WP_REST_Request $request ) {
	$vendor_id = papelito_admin_vendor_integrations_require_vendor( (int) $request['id'] );
	if ( is_wp_error( $vendor_id ) ) {
		return $vendor_id;
	}

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'papelito_admin_vendor_integration_invalid_payload', 'Corpo inválido.', array( 'status' => 400 ) );
	}

	$result = papelito_admin_vendor_integration_save_braspress( $vendor_id, $payload, get_current_user_id() );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * DELETE /admin/vendors/{id}/integrations/braspress — apaga a integração.
 *
 * @param WP_REST_Request $request Requisição autenticada.
 * @return WP_REST_Response|WP_Error Estado vazio ou recusa.
 */
function papelito_admin_vendor_integrations_handle_delete_braspress( WP_REST_Request $request ) {
	$vendor_id = papelito_admin_vendor_integrations_require_vendor( (int) $request['id'] );
	if ( is_wp_error( $vendor_id ) ) {
		return $vendor_id;
	}

	$result = papelito_admin_vendor_integration_delete_braspress( $vendor_id, get_current_user_id() );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Registra as rotas administrativas de integração por vendor.
 *
 * @return void
 */
function papelito_admin_vendor_integrations_register_routes(): void {
	$id_arg = array(
		'id' => array(
			'validate_callback' => 'papelito_admin_vendors_validate_id',
		),
	);

	register_rest_route(
		PAPELITO_ADMIN_VENDORS_REST_NAMESPACE,
		'/vendors/(?P<id>\d+)/integrations',
		array(
			'args'                => $id_arg,
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_admin_vendor_integrations_permission_read',
			'callback'            => 'papelito_admin_vendor_integrations_handle_list',
		)
	);

	register_rest_route(
		PAPELITO_ADMIN_VENDORS_REST_NAMESPACE,
		'/vendors/(?P<id>\d+)/integrations/braspress',
		array(
			'args' => $id_arg,
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_admin_vendor_integrations_permission_write',
				'callback'            => 'papelito_admin_vendor_integrations_handle_save_braspress',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => 'papelito_admin_vendor_integrations_permission_write',
				'callback'            => 'papelito_admin_vendor_integrations_handle_delete_braspress',
			),
		)
	);
}

add_action( 'rest_api_init', 'papelito_admin_vendor_integrations_register_routes' );
