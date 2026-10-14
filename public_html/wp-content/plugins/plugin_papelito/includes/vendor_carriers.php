<?php
/**
 * Escolha das transportadoras regulares de cada vendor.
 *
 * Regra de ouro: a loja nunca fica com as duas transportadoras desligadas. Os
 * Correios só podem sair da cotação regular quando a Braspress do vendor está
 * **disponível** — configurada (CNPJ, CEP de origem e credencial cifrada, o que
 * tira a integração de `unconfigured`) e liberada pela plataforma (flag global e
 * allowlist). Desligar os Correios com a Braspress desligada liga a Braspress;
 * desligar a Braspress com os Correios desligados religa os Correios. Sem
 * Braspress disponível os Correios ficam ligados à força, e a preferência
 * gravada é esquecida para não ressurgir quando a Braspress voltar.
 *
 * A preferência é usermeta do próprio vendor e só existe quando ele desligou os
 * Correios: ausência significa ligado, que é o comportamento de todo vendor
 * anterior a esta regra. Desligar não remove os Correios da contingência —
 * `papelito_shipping_quote_all_providers()` ainda os chama quando a Braspress
 * falha tecnicamente.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_CORREIOS_DISABLED_META = 'papelito_correios_disabled';
const PAPELITO_VENDOR_CORREIOS_DISABLED_FLAG = '1';

/**
 * Informa se a Braspress do vendor está configurada, com ou sem habilitação.
 *
 * Recalcula o perfil a cada chamada em vez de confiar só no `status` gravado:
 * o CNPJ vem do cadastro, e um cadastro alterado por fora tira a configuração
 * sem passar pela escrita da integração.
 *
 * @param array<string,mixed>|null $row Linha da integração.
 * @param int                      $vendor_id ID do vendor.
 * @return bool Se a integração tem perfil completo e credencial guardada.
 */
function papelito_vendor_carriers_row_configured( ?array $row, int $vendor_id ): bool {
	if ( ! is_array( $row ) || empty( $row['secret_envelope'] ) ) {
		return false;
	}

	if ( PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED === sanitize_key( (string) ( $row['status'] ?? '' ) ) ) {
		return false;
	}

	return papelito_vendor_integration_config_complete( papelito_vendor_integration_braspress_config( $vendor_id, $row['config_json'] ?? '' ) );
}

/**
 * Informa se a plataforma deixa a Braspress cotar para este vendor.
 *
 * Com a flag global desligada ou o vendor fora da allowlist a Braspress nunca
 * entra na cotação, por mais que o interruptor do vendor esteja ligado — tratá-la
 * como ligada deixaria a loja sem transportadora nenhuma.
 *
 * @param int $vendor_id ID do vendor.
 * @return bool Se a Braspress pode participar da cotação deste vendor.
 */
function papelito_vendor_carriers_braspress_released( int $vendor_id ): bool {
	if ( ! function_exists( 'papelito_shipping_feature_enabled' ) ) {
		return true;
	}

	return papelito_shipping_feature_enabled( PAPELITO_SHIPPING_PROVIDER_BRASPRESS )
		&& papelito_shipping_provider_vendor_allowed( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, $vendor_id );
}

/**
 * Estado da Braspress que importa para os Correios, lido de uma vez.
 *
 * `available` é configurada e liberada pela plataforma: é o que destrava o
 * interruptor dos Correios. `enabled` é disponível e com o interruptor da
 * Braspress ligado: é o que permite os Correios ficarem desligados.
 *
 * @param int $vendor_id ID do vendor.
 * @return array{available: bool, enabled: bool, row: array<string,mixed>|null} Estado da Braspress.
 */
function papelito_vendor_carriers_braspress_state( int $vendor_id ): array {
	$row = $vendor_id > 0 && function_exists( 'papelito_vendor_integration_find_row' )
		? papelito_vendor_integration_find_row( $vendor_id )
		: null;

	$available = papelito_vendor_carriers_row_configured( $row, $vendor_id ) && papelito_vendor_carriers_braspress_released( $vendor_id );

	return array(
		'available' => $available,
		'enabled'   => $available && ! empty( $row['enabled'] ),
		'row'       => $row,
	);
}

/**
 * Informa se a Braspress do vendor está configurada, com ou sem habilitação.
 *
 * @param int $vendor_id ID do vendor.
 * @return bool Se a integração tem perfil completo e credencial guardada.
 */
function papelito_vendor_carriers_braspress_configured( int $vendor_id ): bool {
	if ( $vendor_id <= 0 || ! function_exists( 'papelito_vendor_integration_find_row' ) ) {
		return false;
	}

	return papelito_vendor_carriers_row_configured( papelito_vendor_integration_find_row( $vendor_id ), $vendor_id );
}

/**
 * Esquece a preferência de Correios desligados, devolvendo o padrão ligado.
 *
 * @param int $vendor_id ID do vendor.
 * @return void
 */
function papelito_vendor_carriers_forget_correios_preference( int $vendor_id ): void {
	delete_user_meta( $vendor_id, PAPELITO_VENDOR_CORREIOS_DISABLED_META );
}

/**
 * Estado efetivo dos Correios do vendor, que é a autoridade da cotação.
 *
 * Tem efeito colateral de propósito: com preferência de desligado gravada e a
 * Braspress desligada ou indisponível, a preferência é apagada aqui. Assim a
 * loja nunca fica sem transportadora, mesmo que a Braspress tenha sido
 * desligada, removida ou descadastrada por um caminho que não passa por este
 * módulo — o painel do admin, a remoção da credencial, o cadastro do CNPJ.
 *
 * @param int $vendor_id ID do vendor.
 * @return array{enabled: bool, locked: bool, braspress_enabled: bool} Correios ligados, se o vendor pode mexer e a Braspress efetiva.
 */
function papelito_vendor_carriers_correios_state( int $vendor_id ): array {
	$braspress = papelito_vendor_carriers_braspress_state( $vendor_id );
	$disabled  = PAPELITO_VENDOR_CORREIOS_DISABLED_FLAG === (string) get_user_meta( $vendor_id, PAPELITO_VENDOR_CORREIOS_DISABLED_META, true );

	if ( $disabled && ! $braspress['enabled'] ) {
		papelito_vendor_carriers_forget_correios_preference( $vendor_id );
		$disabled = false;
	}

	return array(
		'enabled'           => ! $disabled,
		'locked'            => ! $braspress['available'],
		'braspress_enabled' => $braspress['enabled'],
	);
}

/**
 * Informa se os Correios entram na cotação regular do vendor.
 *
 * @param int $vendor_id ID do vendor.
 * @return bool Se os Correios são oferecidos sem depender de contingência.
 */
function papelito_vendor_carriers_correios_enabled( int $vendor_id ): bool {
	return papelito_vendor_carriers_correios_state( $vendor_id )['enabled'];
}

/**
 * Liga a Braspress do vendor pela mesma porta do interruptor da tela.
 *
 * Passa por `papelito_vendor_integration_apply_braspress_save()`: titularidade,
 * cota de escrita de configuração, auditoria, `configuration_version` e sondagem
 * de credencial. Sem a cota, desligar os Correios em rajada sondaria a Braspress
 * sem limite. A origem gravada é reenviada como está para não ser alterada.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $row Linha atual da integração.
 * @param int                 $actor_user_id Quem assina a gravação na trilha.
 * @return true|WP_Error Verdadeiro quando a Braspress ficou ligada.
 */
function papelito_vendor_carriers_enable_braspress( int $vendor_id, array $row, int $actor_user_id ) {
	$stored = json_decode( (string) ( $row['config_json'] ?? '' ), true );
	$saved  = papelito_vendor_integration_apply_braspress_save(
		$vendor_id,
		array(
			'enabled'    => true,
			'origin_cep' => is_array( $stored ) ? (string) ( $stored['origin_cep'] ?? '' ) : '',
		),
		$actor_user_id
	);

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return empty( $saved['enabled'] )
		? new WP_Error( 'papelito_vendor_correios_braspress_enable_failed', 'Não foi possível habilitar a Braspress para desligar os Correios.', array( 'status' => 500 ) )
		: true;
}

/**
 * Grava a escolha do vendor sobre os Correios, recusando o que a regra proíbe.
 *
 * Desligar sem Braspress disponível é recusado com `422`, e não normalizado
 * para ligado: devolver sucesso a uma escrita que não aconteceu faria a tela
 * mostrar um estado falso. Desligar com a Braspress desligada liga a Braspress
 * antes, e uma falha nessa etapa recusa a operação inteira. A gravação é
 * conferida relendo o estado, porque `update_user_meta()` devolve `false` tanto
 * em falha quanto em valor igual.
 *
 * @param int  $vendor_id ID do vendor dono da preferência.
 * @param bool $enabled Se os Correios devem ficar ligados.
 * @param int  $actor_user_id Quem assina uma eventual gravação da Braspress; zero usa o próprio vendor.
 * @return array{enabled: bool, locked: bool, braspress_enabled: bool}|WP_Error Estado efetivo após a gravação ou erro.
 */
function papelito_vendor_carriers_set_correios_enabled( int $vendor_id, bool $enabled, int $actor_user_id = 0 ) {
	if ( $vendor_id <= 0 ) {
		return new WP_Error( 'papelito_vendor_correios_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );
	}

	if ( $enabled ) {
		papelito_vendor_carriers_forget_correios_preference( $vendor_id );

		return papelito_vendor_carriers_confirm_correios( $vendor_id, true );
	}

	$braspress = papelito_vendor_carriers_braspress_state( $vendor_id );
	if ( ! $braspress['available'] ) {
		return new WP_Error(
			'papelito_vendor_correios_locked',
			'Para desabilitar os Correios, configure primeiro sua conta da Braspress.',
			array( 'status' => 422 )
		);
	}

	if ( ! $braspress['enabled'] ) {
		$switched = papelito_vendor_carriers_enable_braspress( $vendor_id, (array) $braspress['row'], $actor_user_id > 0 ? $actor_user_id : $vendor_id );
		if ( is_wp_error( $switched ) ) {
			return $switched;
		}
	}

	update_user_meta( $vendor_id, PAPELITO_VENDOR_CORREIOS_DISABLED_META, PAPELITO_VENDOR_CORREIOS_DISABLED_FLAG );

	return papelito_vendor_carriers_confirm_correios( $vendor_id, false );
}

/**
 * Relê o estado efetivo e recusa a gravação que não pegou.
 *
 * @param int  $vendor_id ID do vendor.
 * @param bool $enabled Valor que a gravação pretendia deixar.
 * @return array{enabled: bool, locked: bool, braspress_enabled: bool}|WP_Error Estado efetivo ou erro.
 */
function papelito_vendor_carriers_confirm_correios( int $vendor_id, bool $enabled ) {
	$state = papelito_vendor_carriers_correios_state( $vendor_id );
	if ( $state['enabled'] !== $enabled ) {
		return new WP_Error( 'papelito_vendor_correios_save_failed', 'Não foi possível salvar a preferência dos Correios.', array( 'status' => 500 ) );
	}

	return $state;
}

/**
 * Lê o `correios_enabled` de um corpo de requisição, sem adivinhar.
 *
 * @param mixed $value Valor recebido.
 * @return bool|null Booleano declarado, ou nulo quando o valor não é booleano.
 */
function papelito_vendor_carriers_parse_enabled( mixed $value ): ?bool {
	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( in_array( $value, array( 1, '1', 'true' ), true ) ) {
		return true;
	}

	return in_array( $value, array( 0, '0', 'false' ), true ) ? false : null;
}
