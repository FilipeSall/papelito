<?php
/**
 * Helpers compartilhados do plugin.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lista de estados brasileiros usada nos fluxos de cadastro e perfil.
 *
 * @return array<string,string>
 */
function papelito_brazilian_states(): array {
	return array(
		''   => 'Selecione o estado da sua empresa',
		'AC' => 'Acre',
		'AL' => 'Alagoas',
		'AP' => 'Amapa',
		'AM' => 'Amazonas',
		'BA' => 'Bahia',
		'CE' => 'Ceara',
		'DF' => 'Distrito Federal',
		'ES' => 'Espirito Santo',
		'GO' => 'Goias',
		'MA' => 'Maranhao',
		'MT' => 'Mato Grosso',
		'MS' => 'Mato Grosso do Sul',
		'MG' => 'Minas Gerais',
		'PA' => 'Para',
		'PB' => 'Paraiba',
		'PR' => 'Parana',
		'PE' => 'Pernambuco',
		'PI' => 'Piaui',
		'RJ' => 'Rio de Janeiro',
		'RN' => 'Rio Grande do Norte',
		'RS' => 'Rio Grande do Sul',
		'RO' => 'Rondonia',
		'RR' => 'Roraima',
		'SC' => 'Santa Catarina',
		'SP' => 'Sao Paulo',
		'SE' => 'Sergipe',
		'TO' => 'Tocantins',
	);
}

/**
 * Cria uma excecao adequada para mutations GraphQL sem depender do typehint.
 *
 * Evita diagnostico de tipo indefinido quando o WPGraphQL nao esta indexado
 * pelo analisador estatico local.
 *
 * @param string $message Mensagem de erro.
 * @return Exception
 */
function papelito_graphql_user_error( string $message ): Exception {
	$graphql_user_error = '\GraphQL\Error\UserError';

	if ( class_exists( $graphql_user_error ) ) {
		return new $graphql_user_error( $message );
	}

	return new RuntimeException( $message );
}

/**
 * Indica se o usuario possui uma role especifica.
 *
 * @param WP_User $user Usuario.
 * @param string  $role Role esperada.
 * @return bool
 */
function papelito_user_has_role( WP_User $user, string $role ): bool {
	$normalized_role = sanitize_key( $role );
	$user_roles      = array_values(
		array_filter(
			array_map( 'sanitize_key', (array) $user->roles )
		)
	);

	return in_array( $normalized_role, $user_roles, true );
}

/**
 * Retorna se o usuario possui ao menos uma faixa de cobertura de vendor.
 *
 * Mantem compatibilidade com sellers legados que existiam antes da meta
 * `application_status`, mas evita promover como vendor contas que receberam a
 * role `seller` por engano e nao possuem configuracao minima de cobertura.
 *
 * @param int $user_id Usuario.
 * @return bool
 */
function papelito_user_has_vendor_coverage( int $user_id ): bool {
	$min_ceps = (array) get_user_meta( $user_id, 'min_cep', false );
	$max_ceps = (array) get_user_meta( $user_id, 'max_cep', false );
	$count    = min( count( $min_ceps ), count( $max_ceps ) );

	for ( $index = 0; $index < $count; $index++ ) {
		$min_cep = preg_replace( '/\D+/', '', (string) $min_ceps[ $index ] );
		$max_cep = preg_replace( '/\D+/', '', (string) $max_ceps[ $index ] );

		if ( is_string( $min_cep ) && is_string( $max_cep ) && '' !== $min_cep && '' !== $max_cep ) {
			return true;
		}
	}

	return false;
}

/**
 * Retorna se o usuario deve ser tratado como seller aprovado no sistema.
 *
 * Regras:
 * - precisa ter a role `seller`;
 * - se existir `application_status`, apenas `approved` libera acesso seller;
 * - sem status explicito, aceita somente sellers legados com cobertura salva.
 *
 * @param int|WP_User $user Usuario ou ID.
 * @return bool
 */
function papelito_user_is_effective_seller( $user ): bool {
	if ( is_numeric( $user ) ) {
		$user = get_userdata( (int) $user );
	}

	if ( ! $user instanceof WP_User || ! papelito_user_has_role( $user, 'seller' ) ) {
		return false;
	}

	$status_meta_key = defined( 'PAPELITO_VENDOR_APPLICATION_STATUS_META' )
		? PAPELITO_VENDOR_APPLICATION_STATUS_META
		: 'application_status';
	$status          = sanitize_key( (string) get_user_meta( $user->ID, $status_meta_key, true ) );

	if ( '' !== $status ) {
		return 'approved' === $status;
	}

	return papelito_user_has_vendor_coverage( $user->ID );
}
