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
 * Normaliza um e-mail para persistencia e comparacao.
 *
 * Unica forma canonica do projeto: quem comparar dois enderecos precisa passar os dois por aqui.
 * `sanitize_email` sozinho preserva a caixa, entao `Fiscal@Empresa.com` e `fiscal@empresa.com`
 * eram gravados e comparados como enderecos diferentes.
 *
 * Nao altera a parte local alem da caixa: remover ponto ou sufixo `+tag` associaria contas
 * distintas ao mesmo endereco.
 *
 * @param string $email E-mail cru.
 * @return string E-mail normalizado, ou string vazia quando invalido.
 */
function papelito_normalize_email( string $email ): string {
	return strtolower( sanitize_email( trim( $email ) ) );
}

/**
 * Compara dois e-mails pela forma normalizada.
 *
 * Endereco vazio nunca casa com nada — evita que dois campos nulos sejam considerados iguais.
 *
 * @param string $left  Primeiro e-mail.
 * @param string $right Segundo e-mail.
 * @return bool
 */
function papelito_emails_match( string $left, string $right ): bool {
	$normalized_left  = papelito_normalize_email( $left );
	$normalized_right = papelito_normalize_email( $right );

	if ( '' === $normalized_left || '' === $normalized_right ) {
		return false;
	}

	return hash_equals( $normalized_left, $normalized_right );
}

/**
 * Contador de tentativas por identidade, em janela deslizante de transient.
 *
 * A identidade e parametro de proposito. Chavear por `REMOTE_ADDR` so funciona quando quem chama e
 * o navegador; endpoint consumido pelo proxy Next ve o IP do servidor de frontend e passa a
 * compartilhar um unico balde entre todos os usuarios — o 21o upload do marketplace inteiro tomava
 * 429. Mesmo raciocinio ja aplicado em `papelito_shipping_rate_limit()`.
 *
 * @param string $bucket   Nome do balde (um por endpoint).
 * @param string $identity Identidade ja resolvida pelo chamador (`user:12`, `company:3`, `ip:...`).
 * @param int    $max      Tentativas permitidas na janela.
 * @param int    $window   Janela em segundos.
 * @return bool `false` quando a cota acabou.
 */
function papelito_rate_limit( string $bucket, string $identity, int $max, int $window ): bool {
	$key   = 'papelito_rl_' . $bucket . '_' . md5( $identity );
	$count = (int) get_transient( $key );

	if ( $count >= $max ) {
		return false;
	}

	set_transient( $key, $count + 1, $window );

	return true;
}

/**
 * Identidade de rate limit para uma requisicao REST.
 *
 * Prefere o usuario autenticado, porque e o unico identificador que sobrevive ao proxy. Cai para o
 * IP apenas quando nao ha sessao — ai o IP e mesmo o do chamador.
 *
 * @param string $fallback_scope Sufixo do escopo anonimo, quando o chamador tem um melhor que IP.
 * @return string
 */
function papelito_rate_limit_identity( string $fallback_scope = '' ): string {
	$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

	if ( $user_id > 0 ) {
		return 'user:' . $user_id;
	}

	if ( '' !== $fallback_scope ) {
		return $fallback_scope;
	}

	return 'ip:' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' );
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
 * Cobertura e requisito operacional separado da role do usuario.
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
 * Retorna se o usuario possui o papel de vendor.
 *
 * A identidade do vendor e definida exclusivamente pela role `seller`.
 * Cobertura, estoque e recebedor continuam sendo requisitos operacionais de
 * venda, mas nao alteram o papel da conta.
 *
 * @param int|WP_User $user Usuario ou ID.
 * @return bool
 */
function papelito_user_is_effective_seller( $user ): bool {
	if ( is_numeric( $user ) ) {
		$user = get_userdata( (int) $user );
	}

	return $user instanceof WP_User && papelito_user_has_role( $user, 'seller' );
}

/**
 * Retorna se o usuario pode acessar a area autenticada de vendor.
 *
 * O acesso autenticado de vendor tambem depende exclusivamente da role.
 *
 * @param int|WP_User $user Usuario ou ID.
 * @return bool
 */
function papelito_user_can_access_seller_area( $user ): bool {
	if ( is_numeric( $user ) ) {
		$user = get_userdata( (int) $user );
	}

	return $user instanceof WP_User && papelito_user_has_role( $user, 'seller' );
}

/**
 * Nome de pessoa: normalizacao e validacao compartilhadas por todas as superficies de cadastro.
 *
 * A regra vive aqui, e nao em cada endpoint, porque "nome e nome de pessoa" e regra de dominio:
 * pre-conta, `/auth/register` e `/auth/register-invitation` precisam concordar. As mensagens sao
 * identicas as do validador do frontend (`papelito-web/src/lib/validation/person.ts`) para que o
 * usuario leia o mesmo texto independentemente de qual camada barrou.
 */
const PAPELITO_PERSON_NAME_MAX_LENGTH = 120;

/**
 * Palavra de um nome: letras, opcionalmente ligadas por apostrofo ou hifen.
 *
 * Deliberadamente aplicada palavra a palavra, nunca a frase inteira. Uma regex que aceitasse o
 * espaco tanto dentro do grupo de ligacao quanto entre palavras seria ambigua e teria backtracking
 * exponencial: 62 caracteres ja custavam 17 s no motor do navegador.
 */
const PAPELITO_PERSON_NAME_WORD_PATTERN = "/^\\p{L}+(?:['\\x{2019}\\-]\\p{L}+)*$/u";

/**
 * Colapsa qualquer separador de espaco Unicode num espaco simples.
 *
 * `\s` do PCRE nao cobre NBSP, mas o `\s` do JavaScript cobre. Sem isto, um nome colado de PDF ou
 * Word passava no frontend e voltava 422 do backend com um erro que o usuario nao consegue ver.
 *
 * @param string $value Texto cru.
 * @return string
 */
function papelito_normalize_unicode_spaces( string $value ): string {
	$normalized = preg_replace( '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $value );

	return trim( $normalized ?? $value );
}

/**
 * Quebra um nome em palavras ja normalizadas.
 *
 * @param string $value Nome cru.
 * @return string[] Vazio quando nao sobrou nada depois da normalizacao.
 */
function papelito_person_name_words( string $value ): array {
	$normalized = papelito_normalize_unicode_spaces( $value );

	return '' === $normalized ? array() : explode( ' ', $normalized );
}

/**
 * Comprimento em pontos de codigo do nome ja normalizado.
 *
 * @param string[] $words Palavras do nome.
 * @return int
 */
function papelito_person_name_length( array $words ): int {
	$normalized = implode( ' ', $words );

	return function_exists( 'mb_strlen' ) ? mb_strlen( $normalized, 'UTF-8' ) : strlen( $normalized );
}

/**
 * Primeiro erro de conjunto de caracteres entre as palavras do nome.
 *
 * @param string[] $words Palavras do nome.
 * @return string|null
 */
function papelito_person_name_charset_error( array $words ): ?string {
	foreach ( $words as $word ) {
		if ( 1 !== preg_match( PAPELITO_PERSON_NAME_WORD_PATTERN, $word ) ) {
			return 'Informe apenas letras, espaços, apóstrofos e hífens no nome.';
		}
	}

	return null;
}

/**
 * Valida um nome completo: pelo menos duas palavras, so letras.
 *
 * @param string $name Nome cru informado pelo usuario.
 * @return string|null Mensagem de erro, ou `null` quando valido.
 */
function papelito_full_name_validation_error( string $name ): ?string {
	$words = papelito_person_name_words( $name );

	if ( array() === $words ) {
		return 'Informe seu nome completo.';
	}
	if ( papelito_person_name_length( $words ) > PAPELITO_PERSON_NAME_MAX_LENGTH ) {
		return 'Informe um nome com até ' . PAPELITO_PERSON_NAME_MAX_LENGTH . ' caracteres.';
	}
	if ( count( $words ) < 2 ) {
		return 'Informe nome e sobrenome.';
	}

	return papelito_person_name_charset_error( $words );
}

/**
 * Valida uma parte isolada do nome (`first_name` ou `last_name`), que pode ter uma palavra so.
 *
 * @param string $value         Parte crua do nome.
 * @param string $empty_message Mensagem especifica do campo quando vazio.
 * @return string|null
 */
function papelito_name_part_validation_error( string $value, string $empty_message ): ?string {
	$words = papelito_person_name_words( $value );

	if ( array() === $words ) {
		return $empty_message;
	}
	if ( papelito_person_name_length( $words ) > PAPELITO_PERSON_NAME_MAX_LENGTH ) {
		return 'Informe um nome com até ' . PAPELITO_PERSON_NAME_MAX_LENGTH . ' caracteres.';
	}

	return papelito_person_name_charset_error( $words );
}

/**
 * Digitos locais de um telefone brasileiro, sem o codigo de pais.
 *
 * O prefixo `55` so e removido em 12 ou 13 digitos: um fixo do DDD 55 (Santa Maria/RS) tem 10
 * digitos e precisa sobreviver inteiro.
 *
 * @param string $phone Telefone cru.
 * @return string
 */
function papelito_normalize_phone_digits( string $phone ): string {
	$digits = preg_replace( '/\D+/', '', $phone ) ?? '';

	if ( ( 12 === strlen( $digits ) || 13 === strlen( $digits ) ) && 0 === strpos( $digits, '55' ) ) {
		return substr( $digits, 2 );
	}

	return $digits;
}

/**
 * Valida um telefone brasileiro com DDD.
 *
 * @param string $phone Telefone cru.
 * @return string|null Mensagem de erro, ou `null` quando valido.
 */
function papelito_phone_validation_error( string $phone ): ?string {
	$phone = papelito_normalize_unicode_spaces( $phone );

	if ( '' === $phone ) {
		return 'Informe seu telefone.';
	}
	if ( 1 !== preg_match( '/^[\d\s()+-]+$/', $phone ) ) {
		return 'Informe um telefone válido com DDD.';
	}

	$local = papelito_normalize_phone_digits( $phone );

	if ( ! in_array( strlen( $local ), array( 10, 11 ), true ) || 1 === preg_match( '/^(\d)\1+$/', $local ) ) {
		return 'Informe um telefone válido com DDD.';
	}

	return null;
}
