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
