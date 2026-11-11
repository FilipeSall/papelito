<?php
/**
 * Configuração e autorização da venda no estande da ExpoCannabis Brasil 2026.
 *
 * O operador fecha no tablet pedidos B2B de um único vendor (a Cifal) e cobra na Point Smart 2 da
 * conta Mercado Pago da Papelito. Este módulo responde três perguntas: o evento está configurado,
 * quem pode vender e se a janela do evento está aberta.
 *
 * Regra de ouro: as rotas `event/*` existem sempre, e todo callback de rota passa por
 * `papelito_event_sales_require_seller()` ou `papelito_event_sales_require_supervisor()`, que releem
 * usuário, janela e configuração a cada chamada. Sem vendor ou terminal configurado, tudo é 503.
 *
 * O que este módulo não faz: não calcula preço, não cria pedido e não fala com o Mercado Pago.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EVENT_SALES_ID               = 'expocannabis-2026';
const PAPELITO_EVENT_SALES_NAME             = 'ExpoCannabis Brasil 2026';
const PAPELITO_EVENT_SALES_NAMESPACE        = 'papelito/v1';
const PAPELITO_EVENT_SALES_ROUTE_PREFIX     = '/event/(?P<event_id>[a-z0-9-]+)';
const PAPELITO_EVENT_SALES_CAP_SELL         = 'papelito_event_sell';
const PAPELITO_EVENT_SALES_CAP_SUPERVISE    = 'papelito_event_supervise';
const PAPELITO_EVENT_SALES_OPERATOR_ROLE    = 'papelito_event_operator';
const PAPELITO_EVENT_SALES_DEFAULT_STARTS   = '2026-11-20T00:00:00-03:00';
const PAPELITO_EVENT_SALES_DEFAULT_ENDS     = '2026-11-22T23:59:59-03:00';
const PAPELITO_EVENT_SALES_FORBIDDEN_CODE   = 'papelito_event_forbidden';
const PAPELITO_EVENT_SALES_FORBIDDEN_STATUS = 403;

/**
 * Converte data ISO 8601 da configuração em timestamp, caindo no padrão quando ela é inválida.
 *
 * @param string $name     Nome da configuração.
 * @param string $fallback Data ISO padrão.
 * @return int
 */
function papelito_event_sales_config_time( string $name, string $fallback ): int {
	$parsed = strtotime( papelito_payment_gateways_config( $name, $fallback ) );

	return false === $parsed ? (int) strtotime( $fallback ) : $parsed;
}

/**
 * Lista de ids inteiros positivos de uma configuração CSV, descartando o que não é número.
 *
 * @param string $name Nome da configuração.
 * @return array<int,int>
 */
function papelito_event_sales_config_ids( string $name ): array {
	$ids = array();

	foreach ( explode( ',', papelito_payment_gateways_config( $name, '' ) ) as $piece ) {
		$piece = trim( $piece );
		if ( 1 === preg_match( '/^\d+$/', $piece ) && (int) $piece > 0 ) {
			$ids[] = (int) $piece;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Ids de terminal de uma configuração CSV, sem espaço, repetição ou valor fora do formato da API.
 *
 * @param string $name Nome da configuração.
 * @return array<int,string>
 */
function papelito_event_sales_config_terminals( string $name ): array {
	$ids = array();

	foreach ( explode( ',', papelito_payment_gateways_config( $name, '' ) ) as $piece ) {
		$piece = trim( $piece );
		if ( 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/', $piece ) ) {
			$ids[] = $piece;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Configuração corrente do evento: janela, vendor que vende, maquininhas que cobram e produtos do kit.
 *
 * Relê constante e ambiente a cada chamada; vendor 0 ou nenhuma maquininha significam "não
 * configurado". `PAPELITO_EVENT_SALES_TERMINAL_ID` aceita lista separada por vírgula; a primeira é
 * a padrão do tablet.
 *
 * @return array{event_id:string, starts_at:int, ends_at:int, vendor_id:int, terminal_ids:array<int,string>, kit_product_ids:array<int,int>}
 */
function papelito_event_sales_config(): array {
	return array(
		'event_id'        => PAPELITO_EVENT_SALES_ID,
		'starts_at'       => papelito_event_sales_config_time( 'PAPELITO_EVENT_SALES_STARTS_AT', PAPELITO_EVENT_SALES_DEFAULT_STARTS ),
		'ends_at'         => papelito_event_sales_config_time( 'PAPELITO_EVENT_SALES_ENDS_AT', PAPELITO_EVENT_SALES_DEFAULT_ENDS ),
		'vendor_id'       => max( 0, (int) papelito_payment_gateways_config( 'PAPELITO_EVENT_SALES_VENDOR_ID', '0' ) ),
		'terminal_ids'    => papelito_event_sales_config_terminals( 'PAPELITO_EVENT_SALES_TERMINAL_ID' ),
		'kit_product_ids' => papelito_event_sales_config_ids( 'PAPELITO_EVENT_SALES_KIT_PRODUCT_IDS' ),
	);
}

/**
 * Maquininha da cobrança: a pedida, se for do evento, ou a primeira configurada quando nada foi pedido.
 *
 * Uma cobrança vai para uma máquina só; maquininha de fora da lista (inclusive as do B2C) é recusada.
 *
 * @param string $requested Id pedido pelo tablet, ou vazio.
 * @return string|WP_Error
 */
function papelito_event_sales_resolve_terminal( string $requested ): string|WP_Error {
	$terminals = papelito_event_sales_config()['terminal_ids'];
	$requested = trim( $requested );

	if ( '' === $requested && array() !== $terminals ) {
		return $terminals[0];
	}

	if ( in_array( $requested, $terminals, true ) ) {
		return $requested;
	}

	return new WP_Error( 'papelito_event_terminal_invalid', 'Essa maquininha não é do evento.', array( 'status' => 400 ) );
}

/**
 * Nome curto da maquininha para o tablet, pelos quatro últimos dígitos do número de série.
 *
 * @param string $terminal_id Id do terminal na API.
 * @return string
 */
function papelito_event_sales_terminal_label( string $terminal_id ): string {
	return 'Maquininha final ' . substr( $terminal_id, -4 );
}

/**
 * Maquininhas do evento no formato do contexto do tablet, a padrão primeiro.
 *
 * @return array<int,array{id:string,label:string}>
 */
function papelito_event_sales_public_terminals(): array {
	$terminals = array();

	foreach ( papelito_event_sales_config()['terminal_ids'] as $terminal_id ) {
		$terminals[] = array(
			'id'    => $terminal_id,
			'label' => papelito_event_sales_terminal_label( $terminal_id ),
		);
	}

	return $terminals;
}

/**
 * Diz se o usuário pode vender agora. Administrador ignora a janela para homologar antes do evento.
 *
 * @param int     $now  Timestamp de referência.
 * @param WP_User $user Usuário que opera.
 * @return bool
 */
function papelito_event_sales_is_open( int $now, WP_User $user ): bool {
	if ( user_can( $user, 'manage_options' ) ) {
		return true;
	}

	$config = papelito_event_sales_config();

	return $now >= $config['starts_at'] && $now <= $config['ends_at'];
}

/**
 * Gate de toda rota de venda do evento, na ordem: evento, login, capability, janela e configuração.
 *
 * É o `permission_callback` das rotas; a ordem importa porque o tablet mostra o primeiro erro.
 *
 * @param WP_REST_Request $request Requisição com o parâmetro de rota `event_id`.
 * @return true|WP_Error
 */
function papelito_event_sales_require_seller( WP_REST_Request $request ): bool|WP_Error {
	if ( PAPELITO_EVENT_SALES_ID !== (string) $request->get_param( 'event_id' ) ) {
		return new WP_Error( 'papelito_event_not_found', 'Evento não encontrado.', array( 'status' => 404 ) );
	}

	if ( get_current_user_id() <= 0 ) {
		return new WP_Error( 'papelito_event_auth_required', 'Entre com a conta de operador do evento.', array( 'status' => 401 ) );
	}

	if ( ! current_user_can( PAPELITO_EVENT_SALES_CAP_SELL ) ) {
		return new WP_Error( PAPELITO_EVENT_SALES_FORBIDDEN_CODE, 'Esta conta não vende no evento.', array( 'status' => PAPELITO_EVENT_SALES_FORBIDDEN_STATUS ) );
	}

	if ( ! papelito_event_sales_is_open( time(), wp_get_current_user() ) ) {
		return new WP_Error( 'papelito_event_closed', 'A venda do evento está fechada agora.', array( 'status' => PAPELITO_EVENT_SALES_FORBIDDEN_STATUS ) );
	}

	$config = papelito_event_sales_config();
	if ( $config['vendor_id'] <= 0 || array() === $config['terminal_ids'] ) {
		return new WP_Error( 'papelito_event_not_configured', 'O evento ainda não foi configurado.', array( 'status' => 503 ) );
	}

	return true;
}

/**
 * Gate das ações de supervisor (liberação manual): o mesmo do vendedor e mais `papelito_event_supervise`.
 *
 * @param WP_REST_Request $request Requisição com o parâmetro de rota `event_id`.
 * @return true|WP_Error
 */
function papelito_event_sales_require_supervisor( WP_REST_Request $request ): bool|WP_Error {
	$seller = papelito_event_sales_require_seller( $request );
	if ( true !== $seller ) {
		return $seller;
	}

	if ( ! current_user_can( PAPELITO_EVENT_SALES_CAP_SUPERVISE ) ) {
		return new WP_Error( PAPELITO_EVENT_SALES_FORBIDDEN_CODE, 'Só o supervisor libera pagamento manualmente.', array( 'status' => PAPELITO_EVENT_SALES_FORBIDDEN_STATUS ) );
	}

	return true;
}

/**
 * Cria o papel "Operador do evento" e dá ao administrador as duas capabilities do evento.
 *
 * O supervisor é um operador que recebe `papelito_event_supervise` pelo editor de papéis.
 *
 * @return void
 */
function papelito_event_sales_install_roles(): void {
	if ( null === get_role( PAPELITO_EVENT_SALES_OPERATOR_ROLE ) ) {
		add_role(
			PAPELITO_EVENT_SALES_OPERATOR_ROLE,
			'Operador do evento',
			array(
				'read'                        => true,
				PAPELITO_EVENT_SALES_CAP_SELL => true,
			)
		);
	}

	$admin = get_role( 'administrator' );
	if ( $admin instanceof WP_Role ) {
		$admin->add_cap( PAPELITO_EVENT_SALES_CAP_SELL );
		$admin->add_cap( PAPELITO_EVENT_SALES_CAP_SUPERVISE );
	}
}
add_action( 'init', 'papelito_event_sales_install_roles' );

/**
 * Registra uma rota do evento sob `papelito/v1/event/{event_id}`.
 *
 * @param string              $path Sufixo depois de `event/{event_id}`.
 * @param array<string,mixed> $args Argumentos de `register_rest_route`.
 * @return void
 */
function papelito_event_sales_register_route( string $path, array $args ): void {
	register_rest_route( PAPELITO_EVENT_SALES_NAMESPACE, PAPELITO_EVENT_SALES_ROUTE_PREFIX . $path, $args );
}
