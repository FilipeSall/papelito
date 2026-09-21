<?php
/**
 * Elegibilidade operacional do vendor para vender.
 *
 * Fonte única da pergunta "esta loja pode vender hoje?". Reúne os requisitos que já viviam
 * espalhados — recebedor Pagar.me, conta suspensa e mínimo de caixas — num veredito só, com a
 * lista de pendências que o painel do vendor exibe e que a cobertura regional aplica.
 *
 * A regra de ouro: quem decide é este módulo, e mais ninguém. O painel do vendor só desenha o que
 * `papelito_vendor_eligibility()` devolve, e a cobertura regional chama
 * `papelito_vendor_is_eligible_to_sell()` no lugar das guardas soltas que existiam antes.
 *
 * O que ele deliberadamente não faz: não escolhe caixa, não fala com a Pagar.me e não bloqueia
 * conta nem login. Vendor inelegível perde a vitrine, não o acesso.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_ELIGIBILITY_OPTION = 'papelito_vendor_eligibility';

/**
 * Mínimo de caixas ativas que o marketplace exige por padrão.
 *
 * Duas caixas bastam para a escolha automática ter alternativa quando a primeira não comporta o
 * pedido. O número real é configurável e sai de `papelito_vendor_minimum_boxes()`; esta constante
 * é só o valor de instalação e o fallback de ambiente que nunca gravou a configuração.
 */
const PAPELITO_VENDOR_BOXES_MINIMUM_DEFAULT = 2;

/**
 * Quantidade de caixas ativas que o marketplace recomenda por padrão.
 *
 * É recomendação operacional, nunca bloqueio: com três caixas o vendor cobre pedido pequeno,
 * médio e grande sem depender de uma única medida.
 */
const PAPELITO_VENDOR_BOXES_RECOMMENDED_DEFAULT = 3;

/** Piso absoluto do mínimo configurável: sem nenhuma caixa nenhuma transportadora cota. */
const PAPELITO_VENDOR_BOXES_MINIMUM_FLOOR = 1;

/**
 * Configuração de instalação da elegibilidade.
 *
 * @return array{minimum_boxes:int,recommended_boxes:int}
 */
function papelito_vendor_eligibility_defaults(): array {
	return array(
		'minimum_boxes'     => PAPELITO_VENDOR_BOXES_MINIMUM_DEFAULT,
		'recommended_boxes' => PAPELITO_VENDOR_BOXES_RECOMMENDED_DEFAULT,
	);
}

/**
 * Teto de caixas que o mínimo configurado não pode ultrapassar.
 *
 * Acompanha o teto de caixas ativas por vendor: um mínimo acima dele deixaria todo vendor
 * permanentemente inelegível, sem caminho de saída no painel.
 *
 * @return int Maior mínimo aceito.
 */
function papelito_vendor_boxes_ceiling(): int {
	return defined( 'PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES' ) ? (int) PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES : 24;
}

/**
 * Normaliza um par de números vindo da option ou do payload administrativo.
 *
 * O recomendado nunca fica abaixo do mínimo: os dois descrevem a mesma escada, e um recomendado
 * menor transformaria a recomendação em contradição na tela do vendor. Valor fora da faixa é
 * puxado para dentro dela em vez de derrubar a leitura, porque a leitura roda no caminho da
 * vitrine e precisa sempre responder.
 *
 * @param mixed $minimum     Mínimo cru.
 * @param mixed $recommended Recomendado cru.
 * @return array{minimum_boxes:int,recommended_boxes:int}
 */
function papelito_vendor_eligibility_normalize_boxes( mixed $minimum, mixed $recommended ): array {
	$ceiling       = papelito_vendor_boxes_ceiling();
	$minimum_boxes = max( PAPELITO_VENDOR_BOXES_MINIMUM_FLOOR, min( $ceiling, (int) $minimum ) );

	return array(
		'minimum_boxes'     => $minimum_boxes,
		'recommended_boxes' => max( $minimum_boxes, min( $ceiling, (int) $recommended ) ),
	);
}

/**
 * Configuração vigente da elegibilidade.
 *
 * Option ausente, corrompida ou parcial cai nos padrões campo a campo: é o que mantém vendor
 * antigo e ambiente que nunca gravou nada com a mesma regra de quem gravou.
 *
 * @return array{minimum_boxes:int,recommended_boxes:int}
 */
function papelito_vendor_eligibility_config(): array {
	$defaults = papelito_vendor_eligibility_defaults();
	$stored   = function_exists( 'get_option' ) ? get_option( PAPELITO_VENDOR_ELIGIBILITY_OPTION, array() ) : array();

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return papelito_vendor_eligibility_normalize_boxes(
		$stored['minimum_boxes'] ?? $defaults['minimum_boxes'],
		$stored['recommended_boxes'] ?? $defaults['recommended_boxes']
	);
}

/**
 * Mínimo de caixas ativas exigido hoje.
 *
 * @return int Caixas ativas necessárias.
 */
function papelito_vendor_minimum_boxes(): int {
	return papelito_vendor_eligibility_config()['minimum_boxes'];
}

/**
 * Quantidade de caixas ativas recomendada hoje.
 *
 * @return int Caixas ativas recomendadas.
 */
function papelito_vendor_recommended_boxes(): int {
	return papelito_vendor_eligibility_config()['recommended_boxes'];
}

/**
 * Valida e monta a configuração a partir do payload administrativo.
 *
 * Diferente da leitura, a escrita recusa em vez de corrigir: quem digitou 40 no mínimo precisa
 * saber que o valor não entrou, e não descobrir depois que virou 24 em silêncio.
 *
 * @param mixed $payload Corpo cru do REST.
 * @return array{minimum_boxes:int,recommended_boxes:int}|WP_Error
 */
function papelito_vendor_eligibility_config_from_payload( mixed $payload ) {
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'papelito_invalid_vendor_eligibility', 'Informe o mínimo e o recomendado de caixas.', array( 'status' => 422 ) );
	}

	$current = papelito_vendor_eligibility_config();
	$minimum = array_key_exists( 'minimum_boxes', $payload ) ? $payload['minimum_boxes'] : $current['minimum_boxes'];
	$wanted  = array_key_exists( 'recommended_boxes', $payload ) ? $payload['recommended_boxes'] : $current['recommended_boxes'];

	$invalid = papelito_vendor_eligibility_payload_error( $minimum, $wanted );

	if ( $invalid instanceof WP_Error ) {
		return $invalid;
	}

	return array(
		'minimum_boxes'     => (int) $minimum,
		'recommended_boxes' => (int) $wanted,
	);
}

/**
 * Recusa de payload administrativo fora da faixa ou inconsistente.
 *
 * @param mixed $minimum     Mínimo informado.
 * @param mixed $recommended Recomendado informado.
 * @return WP_Error|null Erro a devolver, ou nulo quando o par é aceitável.
 */
function papelito_vendor_eligibility_payload_error( mixed $minimum, mixed $recommended ): ?WP_Error {
	$ceiling = papelito_vendor_boxes_ceiling();

	if ( ! papelito_vendor_eligibility_is_whole_number( $minimum ) || ! papelito_vendor_eligibility_is_whole_number( $recommended ) ) {
		return new WP_Error( 'papelito_invalid_vendor_eligibility', 'Informe o mínimo e o recomendado como números inteiros.', array( 'status' => 422 ) );
	}

	if ( (int) $minimum < PAPELITO_VENDOR_BOXES_MINIMUM_FLOOR || (int) $minimum > $ceiling ) {
		return new WP_Error(
			'papelito_invalid_vendor_minimum_boxes',
			sprintf( 'O mínimo de caixas precisa ficar entre %d e %d.', PAPELITO_VENDOR_BOXES_MINIMUM_FLOOR, $ceiling ),
			array(
				'status' => 422,
				'field'  => 'minimum_boxes',
			)
		);
	}

	if ( (int) $recommended > $ceiling ) {
		return new WP_Error(
			'papelito_invalid_vendor_recommended_boxes',
			sprintf( 'O recomendado de caixas não pode passar de %d.', $ceiling ),
			array(
				'status' => 422,
				'field'  => 'recommended_boxes',
			)
		);
	}

	if ( (int) $recommended < (int) $minimum ) {
		return new WP_Error(
			'papelito_inconsistent_vendor_boxes',
			'O recomendado de caixas não pode ser menor que o mínimo.',
			array(
				'status' => 422,
				'field'  => 'recommended_boxes',
			)
		);
	}

	return null;
}

/**
 * Diz se o valor cru representa um inteiro.
 *
 * @param mixed $value Valor do payload.
 * @return bool Se pode virar inteiro sem perder informação.
 */
function papelito_vendor_eligibility_is_whole_number( mixed $value ): bool {
	if ( is_int( $value ) ) {
		return true;
	}

	return is_string( $value ) && 1 === preg_match( '/^\d+$/', trim( $value ) );
}

/**
 * Requisito de recebedor Pagar.me do vendor.
 *
 * O veredito é o estado persistido do recebedor, nunca a existência de credencial: só `active`
 * recebe. Erro de comunicação guardado sobre um recebedor já ativo vira aviso, porque a falha
 * é da última tentativa e não derruba o que a Pagar.me já confirmou.
 *
 * @param int $vendor_id Vendor avaliado.
 * @return array<string,mixed> Requisito normalizado.
 */
function papelito_vendor_pagarme_requirement( int $vendor_id ): array {
	if ( ! function_exists( 'papelito_pagarme_get_vendor_recipient_state' ) ) {
		return papelito_vendor_requirement( 'pagarme', true, false, '' );
	}

	$state     = papelito_pagarme_get_vendor_recipient_state( $vendor_id );
	$has_error = '' !== (string) $state['last_error'];

	if ( 'active' === $state['status'] ) {
		return papelito_vendor_requirement( 'pagarme', true, false, $has_error ? 'last_sync_failed' : '' );
	}

	return papelito_vendor_requirement( 'pagarme', false, true, papelito_vendor_pagarme_reason( $state, $has_error ) );
}

/**
 * Traduz o estado do recebedor no motivo estável que o painel exibe.
 *
 * @param array<string,string> $state     Estado persistido do recebedor.
 * @param bool                 $has_error Se há erro de sincronização guardado.
 * @return string Código do motivo.
 */
function papelito_vendor_pagarme_reason( array $state, bool $has_error ): string {
	if ( '' === $state['status'] ) {
		if ( $has_error ) {
			return 'sync_error';
		}

		return '' === $state['recipient_id'] ? 'never_synced' : 'sync_error';
	}

	if ( 'registration' === $state['status'] ) {
		return 'in_review';
	}

	if ( 'affiliation' === $state['status'] ) {
		return papelito_vendor_pagarme_affiliation_reason( $state );
	}

	return in_array( $state['status'], array( 'refused', 'suspended', 'blocked', 'inactive' ), true ) ? 'rejected' : 'unknown';
}

/**
 * Motivo de um recebedor em credenciamento.
 *
 * @param array<string,string> $state Estado persistido do recebedor.
 * @return string Código do motivo.
 */
function papelito_vendor_pagarme_affiliation_reason( array $state ): string {
	$kyc_required = function_exists( 'papelito_pagarme_kyc_action_required' )
		&& papelito_pagarme_kyc_action_required( $state['status'], $state['kyc_status'], $state['kyc_status_reason'] );

	return $kyc_required ? 'kyc_required' : 'in_review';
}

/**
 * Requisito de caixas cadastradas do vendor.
 *
 * Abaixo do mínimo é pendência impeditiva enquanto o gate de embalagem estiver em vigor; com o
 * gate desligado a mesma falta continua aparecendo, só que como aviso, para o painel não anunciar
 * um bloqueio que a cobertura não aplica. Entre o mínimo e o recomendado o vendor vende e recebe
 * apenas a recomendação.
 *
 * @param int $vendor_id Vendor avaliado.
 * @return array<string,mixed> Requisito normalizado, com os números da escada.
 */
function papelito_vendor_boxes_requirement( int $vendor_id ): array {
	$config       = papelito_vendor_eligibility_config();
	$active_count = function_exists( 'papelito_packaging_active_profile_count' )
		? papelito_packaging_active_profile_count( $vendor_id )
		: 0;

	$numbers = array(
		'active_count'      => $active_count,
		'minimum_boxes'     => $config['minimum_boxes'],
		'recommended_boxes' => $config['recommended_boxes'],
	);

	if ( $active_count < $config['minimum_boxes'] ) {
		return papelito_vendor_requirement( 'boxes', false, papelito_vendor_boxes_gate_enforced(), 'below_minimum', $numbers );
	}

	$below_recommended = $active_count < $config['recommended_boxes'];

	return papelito_vendor_requirement( 'boxes', true, false, $below_recommended ? 'below_recommended' : '', $numbers );
}

/**
 * Diz se a falta de caixas realmente tira o vendor da cobertura agora.
 *
 * @return bool Se o gate de embalagem está em vigor.
 */
function papelito_vendor_boxes_gate_enforced(): bool {
	return ! function_exists( 'papelito_packaging_profile_gate_enabled' ) || papelito_packaging_profile_gate_enabled();
}

/**
 * Requisito de conta ativa do vendor.
 *
 * @param int $vendor_id Vendor avaliado.
 * @return array<string,mixed> Requisito normalizado.
 */
function papelito_vendor_account_requirement( int $vendor_id ): array {
	$suspended = function_exists( 'papelito_account_is_suspended' ) && papelito_account_is_suspended( $vendor_id );

	return papelito_vendor_requirement( 'account', ! $suspended, $suspended, $suspended ? 'suspended' : '' );
}

/**
 * Monta um requisito no formato único que o REST devolve.
 *
 * `advisory` é derivado, nunca informado: é ter motivo a exibir sem impedir a venda. Cobre de uma
 * vez a recomendação de caixas, a falha de sincronização sobre recebedor ativo e a falta de caixas
 * com o gate de embalagem desligado — três casos que a interface trata igual e que um parâmetro
 * separado deixaria fora de sincronia com `blocking`.
 *
 * @param string            $id        Identificador do requisito.
 * @param bool              $satisfied Se o mínimo do requisito está atendido.
 * @param bool              $blocking  Se a pendência impede a venda agora.
 * @param string            $reason    Código estável do motivo, vazio quando não há o que dizer.
 * @param array<string,int> $numbers   Números do requisito, quando existirem.
 * @return array<string,mixed> Requisito normalizado.
 */
function papelito_vendor_requirement( string $id, bool $satisfied, bool $blocking, string $reason, array $numbers = array() ): array {
	return array_merge(
		array(
			'id'        => $id,
			'satisfied' => $satisfied,
			'blocking'  => $blocking,
			'advisory'  => ! $blocking && '' !== $reason,
			'reason'    => $reason,
		),
		$numbers
	);
}

/**
 * Veredito completo de elegibilidade do vendor.
 *
 * É a resposta que o painel desenha e a mesma que a cobertura aplica. Quem precisa só do
 * sim ou não usa `papelito_vendor_is_eligible_to_sell()`, que não monta a lista.
 *
 * @param int $vendor_id Vendor avaliado.
 * @return array<string,mixed> Veredito com configuração e requisitos.
 */
function papelito_vendor_eligibility( int $vendor_id ): array {
	$requirements = papelito_vendor_requirements( $vendor_id );
	$blocking     = array_filter( $requirements, static fn( array $item ): bool => (bool) $item['blocking'] );

	return array(
		'can_sell'     => 0 === count( $blocking ),
		'config'       => papelito_vendor_eligibility_config(),
		'requirements' => array_values( $requirements ),
	);
}

/**
 * Os requisitos avaliados, na ordem em que o painel os lista.
 *
 * @param int $vendor_id Vendor avaliado.
 * @return array<int,array<string,mixed>> Requisitos.
 */
function papelito_vendor_requirements( int $vendor_id ): array {
	if ( $vendor_id <= 0 ) {
		return array( papelito_vendor_requirement( 'account', false, true, 'unknown_vendor' ) );
	}

	return array(
		papelito_vendor_account_requirement( $vendor_id ),
		papelito_vendor_pagarme_requirement( $vendor_id ),
		papelito_vendor_boxes_requirement( $vendor_id ),
	);
}

/**
 * Diz se o vendor pode vender agora.
 *
 * Único ponto de decisão do marketplace: a cobertura regional, a vitrine e o roteamento do
 * carrinho passam por aqui, e o painel do vendor mostra exatamente os mesmos requisitos.
 *
 * @param int $vendor_id Vendor avaliado.
 * @return bool Se o vendor está apto a vender.
 */
function papelito_vendor_is_eligible_to_sell( int $vendor_id ): bool {
	if ( $vendor_id <= 0 ) {
		return false;
	}

	foreach ( papelito_vendor_requirements( $vendor_id ) as $requirement ) {
		if ( $requirement['blocking'] ) {
			return false;
		}
	}

	return true;
}

/**
 * Persiste a configuração validada e devolve a vigente.
 *
 * @param WP_REST_Request $request Requisição administrativa.
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_eligibility_config_update( WP_REST_Request $request ) {
	$config = papelito_vendor_eligibility_config_from_payload( $request->get_json_params() );

	if ( is_wp_error( $config ) ) {
		return $config;
	}

	update_option( PAPELITO_VENDOR_ELIGIBILITY_OPTION, $config, false );

	return papelito_vendor_eligibility_config();
}

/**
 * Exige capacidade administrativa para escrever a configuração.
 *
 * @return bool
 */
function papelito_vendor_eligibility_require_admin(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Restringe a leitura do veredito ao próprio vendedor autenticado.
 *
 * @return bool|WP_Error
 */
function papelito_vendor_eligibility_permission() {
	$check = function_exists( 'papelito_vendor_dashboard_require_seller' )
		? papelito_vendor_dashboard_require_seller()
		: false;

	return is_wp_error( $check ) ? $check : true;
}

/**
 * Devolve o veredito do vendedor autenticado.
 *
 * @return WP_REST_Response
 */
function papelito_vendor_eligibility_rest_me(): WP_REST_Response {
	return new WP_REST_Response( papelito_vendor_eligibility( get_current_user_id() ), 200 );
}

/**
 * Registra as rotas de elegibilidade.
 *
 * @return void
 */
function papelito_vendor_eligibility_register_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/vendor/me/eligibility',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_eligibility_permission',
			'callback'            => 'papelito_vendor_eligibility_rest_me',
		)
	);

	register_rest_route(
		'papelito/v1',
		'/admin/vendor-eligibility',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'papelito_vendor_eligibility_require_admin',
			'callback'            => static fn(): array => papelito_vendor_eligibility_config(),
		)
	);

	register_rest_route(
		'papelito/v1',
		'/admin/vendor-eligibility',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'permission_callback' => 'papelito_vendor_eligibility_require_admin',
			'callback'            => 'papelito_vendor_eligibility_config_update',
		)
	);
}

add_action( 'rest_api_init', 'papelito_vendor_eligibility_register_routes' );
