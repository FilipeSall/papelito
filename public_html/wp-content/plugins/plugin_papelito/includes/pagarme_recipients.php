<?php
/**
 * Recebedores Pagar.me por vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_PAGARME_RECIPIENT_ID_META' ) ) {
	define( 'PAPELITO_PAGARME_RECIPIENT_ID_META', 'papelito_pagarme_recipient_id' );
	define( 'PAPELITO_PAGARME_RECIPIENT_STATUS_META', 'papelito_pagarme_recipient_status' );
	define( 'PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META', 'papelito_pagarme_recipient_last_sync_at' );
	define( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META', 'papelito_pagarme_recipient_last_error' );
	define( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META', 'papelito_pagarme_recipient_last_error_detail' );
	define( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_CODE_META', 'papelito_pagarme_recipient_last_error_code' );
	define( 'PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_FIELDS_META', 'papelito_pagarme_recipient_last_error_fields' );
	define( 'PAPELITO_PAGARME_RECIPIENT_KYC_STATUS_META', 'papelito_pagarme_recipient_kyc_status' );
	define( 'PAPELITO_PAGARME_RECIPIENT_KYC_STATUS_REASON_META', 'papelito_pagarme_recipient_kyc_status_reason' );
	define( 'PAPELITO_PAGARME_KYC_LINK_LIMITS_TABLE', 'papelito_pagarme_kyc_link_limits' );
	define( 'PAPELITO_PAGARME_DIGITS_PATTERN', '/\\D+/' );
	define( 'PAPELITO_PAGARME_RECIPIENTS_PATH', 'recipients/' );
}

/** Retorna a tabela de quota atomica para links KYC. */
function papelito_pagarme_kyc_link_limits_table(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_PAGARME_KYC_LINK_LIMITS_TABLE;
}

/** Cria a tabela de quota atomica para links KYC. */
function papelito_pagarme_install_kyc_link_limits_table(): void {
	global $wpdb;

	$table   = papelito_pagarme_kyc_link_limits_table();
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$sql = "CREATE TABLE {$table} (
		vendor_id BIGINT UNSIGNED NOT NULL,
		attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		window_started_at DATETIME NOT NULL,
		expires_at DATETIME NOT NULL,
		PRIMARY KEY  (vendor_id)
	) {$charset};";
	dbDelta( $sql );
}

/**
 * Retorna o recipient_id atual do vendor.
 */
function papelito_pagarme_get_vendor_recipient_id( int $user_id ): string {
	return sanitize_text_field( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_ID_META, true ) );
}

/**
 * Retorna o status atual do recebedor do vendor.
 */
function papelito_pagarme_get_vendor_recipient_status( int $user_id ): string {
	return sanitize_key( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_STATUS_META, true ) );
}

/**
 * Indica se o recebedor esta ativo.
 */
function papelito_pagarme_vendor_recipient_is_active( int $user_id ): bool {
	return 'active' === papelito_pagarme_get_vendor_recipient_status( $user_id );
}

/**
 * Persiste o estado do recebedor no usermeta.
 *
 * @param array<string,mixed> $recipient Resposta Pagar.me.
 */
function papelito_pagarme_save_vendor_recipient_state( int $user_id, array $recipient ): array {
	$recipient_id = sanitize_text_field( (string) ( $recipient['id'] ?? '' ) );
	$status       = sanitize_key( (string) ( $recipient['status'] ?? '' ) );
	$kyc_details  = isset( $recipient['kyc_details'] ) && is_array( $recipient['kyc_details'] ) ? $recipient['kyc_details'] : array();
	$kyc_status   = sanitize_key( (string) ( $kyc_details['status'] ?? '' ) );
	$kyc_reason   = sanitize_key( (string) ( $kyc_details['status_reason'] ?? '' ) );

	if ( '' !== $recipient_id ) {
		update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_ID_META, $recipient_id );
	}

	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_STATUS_META, $status );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_KYC_STATUS_META, $kyc_status );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_KYC_STATUS_REASON_META, $kyc_reason );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META, papelito_current_utc_mysql() );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META, '' );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META, '' );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_CODE_META, '' );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_FIELDS_META, '' );

	delete_user_meta( $user_id, 'papelito_pagarme_recipient_kyc_url' );

	return papelito_pagarme_get_vendor_recipient_state( $user_id );
}

/**
 * Catalogo dos campos que a Pagar.me costuma recusar no recebedor.
 *
 * A v5 nomeia o campo ora pela chave (`register_information.document`), ora dentro da propria
 * mensagem (`invalid_parameter | agencia | Value too long`), e em portugues ou ingles conforme o
 * endpoint. Por isso a busca e por trecho sobre chave e mensagem juntas, do mais especifico para o
 * mais generico: `holder_document` precisa vencer `document`, e `agencia_dv` precisa vencer
 * `agencia`.
 *
 * @return array<int,array<string,mixed>> Entradas na ordem de precedencia.
 */
function papelito_pagarme_rejected_field_catalog(): array {
	return array_merge(
		papelito_pagarme_rejected_bank_fields(),
		papelito_pagarme_rejected_partner_fields(),
		papelito_pagarme_rejected_company_fields()
	);
}

/**
 * Entradas de conta bancaria do catalogo de recusa.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_pagarme_rejected_bank_fields(): array {
	return array(
		array(
			'match' => array( 'holder_document', 'documento do titular' ),
			'field' => 'bank_account.holder_document',
			'group' => 'bank_account',
			'label' => 'Documento do titular da conta',
			'hint'  => 'A conta precisa estar no CNPJ da empresa. Conta de pessoa física, mesmo a do sócio, é recusada.',
		),
		array(
			'match' => array( 'holder_name', 'titular' ),
			'field' => 'bank_account.holder_name',
			'group' => 'bank_account',
			'label' => 'Nome do titular da conta',
			'hint'  => 'Use a razão social como aparece no banco, com no máximo 30 caracteres.',
		),
		array(
			'match' => array( 'branch_check_digit', 'agencia_dv' ),
			'field' => 'bank_account.branch_check_digit',
			'group' => 'bank_account',
			'label' => 'Dígito da agência',
			'hint'  => 'Deixe em branco quando o seu banco não usa dígito de agência.',
		),
		array(
			'match' => array( 'branch_number', 'agencia' ),
			'field' => 'bank_account.branch_number',
			'group' => 'bank_account',
			'label' => 'Agência',
			'hint'  => 'Informe só os números da agência, no máximo quatro dígitos e sem o dígito verificador.',
		),
		array(
			'match' => array( 'account_check_digit', 'conta_dv' ),
			'field' => 'bank_account.account_check_digit',
			'group' => 'bank_account',
			'label' => 'Dígito da conta',
			'hint'  => 'Informe o dígito separado do número da conta.',
		),
		array(
			'match' => array( 'account_number', 'conta' ),
			'field' => 'bank_account.account_number',
			'group' => 'bank_account',
			'label' => 'Número da conta',
			'hint'  => 'Informe só os números da conta, sem o dígito verificador.',
		),
		array(
			'match' => array( 'bank', 'banco' ),
			'field' => 'bank_account.bank',
			'group' => 'bank_account',
			'label' => 'Banco',
			'hint'  => 'Confira o código do banco na lista oficial da Febraban.',
		),
		array(
			'match' => array( 'default_bank_account' ),
			'field' => 'bank_account',
			'group' => 'bank_account',
			'label' => 'Conta bancária',
			'hint'  => 'Revise banco, agência, conta e titular.',
		),
	);
}

/**
 * Entradas do responsavel legal no catalogo de recusa.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_pagarme_rejected_partner_fields(): array {
	$partner = 'managing_partners';

	return array(
		array(
			'match' => array( $partner . '.*document', 'cpf' ),
			'field' => 'partner.document',
			'group' => 'partner',
			'label' => 'CPF do responsável legal',
			'hint'  => 'Informe o CPF da pessoa que responde pela empresa, com 11 dígitos.',
		),
		array(
			'match' => array( $partner . '.*birthdate', 'birthdate', 'data_nascimento' ),
			'field' => 'partner.birthdate',
			'group' => 'partner',
			'label' => 'Data de nascimento do responsável legal',
			'hint'  => 'Use o formato AAAA-MM-DD.',
		),
		array(
			'match' => array( $partner . '.*mother_name', 'mother_name' ),
			'field' => 'partner.mother_name',
			'group' => 'partner',
			'label' => 'Nome da mãe do responsável legal',
			'hint'  => 'A Pagar.me pede o nome completo da mãe para a verificação de identidade.',
		),
		array(
			'match' => array( 'professional_occupation' ),
			'field' => 'partner.professional_occupation',
			'group' => 'partner',
			'label' => 'Profissão do responsável legal',
			'hint'  => 'Preencha a ocupação declarada do responsável legal.',
		),
		array(
			'match' => array( 'monthly_income' ),
			'field' => 'partner.monthly_income',
			'group' => 'partner',
			'label' => 'Renda mensal do responsável legal',
			'hint'  => 'Informe um valor maior que zero, sem centavos.',
		),
		array(
			'match' => array( $partner . '.*address' ),
			'field' => 'partner.address',
			'group' => 'partner',
			'label' => 'Endereço do responsável legal',
			'hint'  => 'Rua, número, bairro, cidade, estado e CEP do responsável legal.',
		),
		array(
			'match' => array( $partner . '.*phone', $partner . '.*telefone' ),
			'field' => 'partner.phone',
			'group' => 'partner',
			'label' => 'Telefone do responsável legal',
			'hint'  => 'Informe DDD e número, apenas dígitos.',
		),
		array(
			'match' => array( $partner . '.*email' ),
			'field' => 'partner.email',
			'group' => 'partner',
			'label' => 'E-mail do responsável legal',
			'hint'  => 'Informe um e-mail válido do responsável legal.',
		),
		array(
			'match' => array( $partner . '.*name', 'self_declared_legal_representative' ),
			'field' => 'partner.name',
			'group' => 'partner',
			'label' => 'Responsável legal',
			'hint'  => 'Cadastre ao menos um sócio administrador com nome completo.',
		),
		array(
			'match' => array( $partner ),
			'field' => 'partner',
			'group' => 'partner',
			'label' => 'Responsavel legal',
			'hint'  => 'Revise os dados do sócio administrador informado.',
		),
	);
}

/**
 * Entradas da empresa no catalogo de recusa.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_pagarme_rejected_company_fields(): array {
	return array(
		array(
			'match' => array( 'main_address', 'endereco', 'address', 'zip_code', 'cep' ),
			'field' => 'company.address',
			'group' => 'company',
			'label' => 'Endereço da empresa',
			'hint'  => 'Rua, número, bairro, cidade, estado e CEP do endereço comercial.',
		),
		array(
			'match' => array( 'annual_revenue' ),
			'field' => 'company.annual_revenue',
			'group' => 'company',
			'label' => 'Faturamento anual',
			'hint'  => 'Informe um valor maior que zero, sem centavos.',
		),
		array(
			'match' => array( 'founding_date' ),
			'field' => 'company.founding_date',
			'group' => 'company',
			'label' => 'Data de abertura da empresa',
			'hint'  => 'Use o formato AAAA-MM-DD, ou deixe em branco.',
		),
		array(
			'match' => array( 'corporation_type' ),
			'field' => 'company.corporation_type',
			'group' => 'company',
			'label' => 'Tipo societário',
			'hint'  => 'Informe a natureza jurídica como no cartão CNPJ, ou deixe em branco.',
		),
		array(
			'match' => array( 'trading_name', 'nome_fantasia' ),
			'field' => 'company.trading_name',
			'group' => 'company',
			'label' => 'Nome fantasia',
			'hint'  => 'Use o nome fantasia registrado no CNPJ.',
		),
		array(
			'match' => array( 'company_name', 'razao_social', 'razao social' ),
			'field' => 'company.company_name',
			'group' => 'company',
			'label' => 'Razão social',
			'hint'  => 'Use a razão social exatamente como no cartão CNPJ.',
		),
		array(
			'match' => array( 'document', 'cnpj' ),
			'field' => 'company.document',
			'group' => 'company',
			'label' => 'CNPJ da empresa',
			'hint'  => 'Informe o CNPJ com 14 dígitos, ativo na Receita Federal.',
		),
		array(
			'match' => array( 'phone' ),
			'field' => 'company.phone',
			'group' => 'company',
			'label' => 'Telefone da empresa',
			'hint'  => 'Informe DDD e numero, apenas digitos.',
		),
		array(
			'match' => array( 'email' ),
			'field' => 'company.email',
			'group' => 'company',
			'label' => 'E-mail da empresa',
			'hint'  => 'Informe um e-mail válido da conta.',
		),
	);
}

/**
 * Casa um detalhe cru da Pagar.me com uma entrada do catalogo.
 *
 * @param string $haystack Chave e mensagem em minusculas.
 * @return array<string,mixed>|null Entrada do catalogo, ou nulo quando nada casa.
 */
function papelito_pagarme_match_rejected_field( string $haystack ): ?array {
	foreach ( papelito_pagarme_rejected_field_catalog() as $entry ) {
		foreach ( $entry['match'] as $needle ) {
			if ( papelito_pagarme_detail_matches( $haystack, $needle ) ) {
				return $entry;
			}
		}
	}

	return null;
}

/**
 * Diz se o detalhe casa com um trecho do catalogo.
 *
 * O `.*` no trecho vira curinga, para `managing_partners.*document` alcancar
 * `register_information.managing_partners[0].document` sem listar cada indice. Os dois lados
 * passam pela mesma normalizacao, entao `holder_name` tambem alcanca "holder name" — a Pagar.me
 * escreve o campo nas duas formas conforme o endpoint.
 *
 * @param string $haystack Chave e mensagem ja normalizadas.
 * @param string $needle   Trecho do catalogo.
 * @return bool Se o detalhe pertence aquela entrada.
 */
function papelito_pagarme_detail_matches( string $haystack, string $needle ): bool {
	$normalized = papelito_pagarme_normalize_detail( $needle );

	if ( false === strpos( $normalized, '.*' ) ) {
		return false !== strpos( $haystack, $normalized );
	}

	return 1 === preg_match( '/' . str_replace( '\.\*', '.*', preg_quote( $normalized, '/' ) ) . '/', $haystack );
}

/**
 * Traduz os detalhes crus da Pagar.me na lista de campos a corrigir.
 *
 * O vendor recebe o nome do campo em portugues e o que fazer com ele; a frase crua da Pagar.me
 * viaja junto em `detail` porque e ela que distingue "campo obrigatorio" de "valor longo demais",
 * e nenhum dicionario acompanharia isso sozinho. Detalhe que nao casa com campo nenhum ainda
 * aparece, como pendencia generica, em vez de sumir e deixar o vendor sem pista.
 *
 * @param array<int,string> $details Detalhes de `papelito_pagarme_collect_error_details()`.
 * @return array<int,array<string,string>> Campos recusados, sem repeticao.
 */
function papelito_pagarme_rejected_fields_from_details( array $details ): array {
	$fields = array();

	foreach ( $details as $detail ) {
		$text  = sanitize_text_field( (string) $detail );
		$entry = papelito_pagarme_match_rejected_field( papelito_pagarme_normalize_detail( $text ) );
		$field = null === $entry ? 'outros' : (string) $entry['field'];

		if ( isset( $fields[ $field ] ) ) {
			continue;
		}

		$fields[ $field ] = array(
			'field'  => $field,
			'group'  => null === $entry ? 'outros' : (string) $entry['group'],
			'label'  => null === $entry ? 'Outro dado do cadastro' : (string) $entry['label'],
			'hint'   => null === $entry ? 'A Pagar.me recusou um dado que a Papelito ainda não sabe nomear. Envie esta mensagem ao suporte.' : (string) $entry['hint'],
			'detail' => papelito_pagarme_trim_detail( $text ),
		);
	}

	return array_values( $fields );
}

/**
 * Normaliza um detalhe cru para a busca no catalogo.
 *
 * O underscore vira espaco nos dois lados da comparacao: a Pagar.me manda `holder_name` na chave
 * e "holder name" na frase, e sem isso a segunda escapava do catalogo e caia numa entrada mais
 * generica.
 *
 * @param string $detail Detalhe cru.
 * @return string Texto em minusculas, sem acento e sem underscore.
 */
function papelito_pagarme_normalize_detail( string $detail ): string {
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $detail, 'UTF-8' ) : strtolower( $detail );

	return strtr(
		$lower,
		array(
			'á' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'à' => 'a',
			'é' => 'e',
			'ê' => 'e',
			'í' => 'i',
			'ó' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ú' => 'u',
			'ç' => 'c',
			'_' => ' ',
		)
	);
}

/**
 * Encurta a frase da Pagar.me para caber numa linha da interface.
 *
 * @param string $detail Detalhe sanitizado.
 * @return string Detalhe com no maximo 160 caracteres.
 */
function papelito_pagarme_trim_detail( string $detail ): string {
	if ( strlen( $detail ) <= 160 ) {
		return $detail;
	}

	return rtrim( substr( $detail, 0, 157 ) ) . '...';
}

/**
 * Extrai os campos recusados de um WP_Error da Pagar.me.
 *
 * Nem toda recusa vem com `errors` por campo: boa parte chega como uma frase única em `message`
 * — foi assim com "Bank account holder name must be lower than 30 characters.", que travou um
 * vendor real. Quando não há `details`, a frase é submetida ao mesmo catálogo, e só vira linha se
 * nomear um campo; frase genérica continua fora, para não repetir o banner com outras palavras.
 *
 * @param mixed $error Erro devolvido pelo cliente.
 * @return array<int,array<string,string>> Campos recusados.
 */
function papelito_pagarme_rejected_fields_from_error( mixed $error ): array {
	if ( ! $error instanceof WP_Error ) {
		return array();
	}

	$data = $error->get_error_data();
	$body = is_array( $data ) && isset( $data['pagarme_body'] ) && is_array( $data['pagarme_body'] ) ? $data['pagarme_body'] : array();

	if ( isset( $body['details'] ) && is_array( $body['details'] ) && ! empty( $body['details'] ) ) {
		return papelito_pagarme_rejected_fields_from_details( $body['details'] );
	}

	return papelito_pagarme_rejected_fields_from_message( (string) ( $body['message'] ?? '' ) );
}

/**
 * Traduz a recusa que veio como frase única, sem mapa de campos.
 *
 * @param string $message Mensagem da Pagar.me.
 * @return array<int,array<string,string>> Uma linha, ou nenhuma quando a frase não nomeia campo.
 */
function papelito_pagarme_rejected_fields_from_message( string $message ): array {
	$text = sanitize_text_field( $message );

	if ( '' === $text || null === papelito_pagarme_match_rejected_field( papelito_pagarme_normalize_detail( $text ) ) ) {
		return array();
	}

	return papelito_pagarme_rejected_fields_from_details( array( $text ) );
}

/**
 * Le os campos recusados na ultima tentativa de sincronizacao.
 *
 * Vendor que ja estava travado antes desta traducao existir nao tem o meta novo, so o diagnostico
 * antigo em `last_error_detail`. Em vez de exigir uma nova tentativa para o painel voltar a
 * explicar o motivo, a leitura reconstroi a lista a partir dele — o diagnostico guarda exatamente
 * os mesmos `message` e `details` que a traducao consome.
 *
 * @param int $user_id Usuario vendor.
 * @return array<int,array<string,string>> Campos recusados.
 */
function papelito_pagarme_get_vendor_rejected_fields( int $user_id ): array {
	$stored  = get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_FIELDS_META, true );
	$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;

	if ( is_array( $decoded ) && ! empty( $decoded ) ) {
		return array_values( array_filter( $decoded, 'is_array' ) );
	}

	return papelito_pagarme_rejected_fields_from_legacy_detail( $user_id );
}

/**
 * Reconstroi os campos recusados a partir do diagnostico guardado para o suporte.
 *
 * @param int $user_id Usuario vendor.
 * @return array<int,array<string,string>> Campos recusados, ou lista vazia.
 */
function papelito_pagarme_rejected_fields_from_legacy_detail( int $user_id ): array {
	$raw = get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META, true );

	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}

	$body = json_decode( $raw, true );

	if ( ! is_array( $body ) ) {
		return array();
	}

	if ( isset( $body['details'] ) && is_array( $body['details'] ) && ! empty( $body['details'] ) ) {
		return papelito_pagarme_rejected_fields_from_details( $body['details'] );
	}

	return papelito_pagarme_rejected_fields_from_message( (string) ( $body['message'] ?? '' ) );
}

/**
 * Persiste ultimo erro de sincronizacao do recebedor.
 *
 * Quando recebe o WP_Error completo, guarda tambem um diagnostico sanitizado
 * da validacao da Pagar.me (`pagarme_body`) num meta separado para suporte.
 *
 * @param int             $user_id Usuario.
 * @param WP_Error|string $error   Erro ou mensagem.
 */
function papelito_pagarme_save_vendor_recipient_error( int $user_id, $error ): void {
	$message = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;

	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META, papelito_current_utc_mysql() );
	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META, sanitize_text_field( $message ) );
	update_user_meta(
		$user_id,
		PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_CODE_META,
		$error instanceof WP_Error ? sanitize_key( (string) $error->get_error_code() ) : ''
	);

	$detail = '';

	if ( $error instanceof WP_Error ) {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['pagarme_body'] ) && is_array( $data['pagarme_body'] ) ) {
			$encoded = wp_json_encode( $data['pagarme_body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$detail  = is_string( $encoded ) ? $encoded : '';
		}
	}

	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_DETAIL_META, $detail );

	$fields  = papelito_pagarme_rejected_fields_from_error( $error );
	$encoded = empty( $fields ) ? '' : wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	update_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_FIELDS_META, is_string( $encoded ) ? $encoded : '' );
}

/**
 * Monta a resposta REST de erro do recebedor sem vazar detalhe tecnico.
 *
 * Retorna o codigo estavel do erro, o status HTTP e a lista de campos que a Pagar.me recusou,
 * ja traduzida — sem ela o vendor lia "nao foi possivel validar" e nao tinha o que corrigir. O
 * corpo cru do gateway (`response_body`) continua sem sair do backend; o que viaja e o nome do
 * campo, o que fazer com ele e a frase de validacao sanitizada.
 *
 * @param WP_Error $error Erro original.
 * @return WP_Error
 */
function papelito_pagarme_recipient_error_response( WP_Error $error ): WP_Error {
	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
	$fields = papelito_pagarme_rejected_fields_from_error( $error );

	return new WP_Error(
		$error->get_error_code(),
		'Não foi possível validar os dados do recebedor.',
		array(
			'status' => $status,
			'fields' => $fields,
		)
	);
}

/**
 * Retorna o estado serializado do recebedor.
 *
 * `last_error_fields` e a lista traduzida do que a Pagar.me recusou na ultima tentativa; fica
 * vazia depois de uma sincronizacao bem-sucedida, junto com os demais metas de erro.
 *
 * @return array<string,mixed>
 */
function papelito_pagarme_get_vendor_recipient_state( int $user_id ): array {
	return array(
		'recipient_id'      => papelito_pagarme_get_vendor_recipient_id( $user_id ),
		'status'            => papelito_pagarme_get_vendor_recipient_status( $user_id ),
		'kyc_status'        => sanitize_key( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_KYC_STATUS_META, true ) ),
		'kyc_status_reason' => sanitize_key( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_KYC_STATUS_REASON_META, true ) ),
		'last_sync_at'      => sanitize_text_field( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_SYNC_META, true ) ),
		'last_error'        => sanitize_text_field( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_META, true ) ),
		'last_error_code'   => sanitize_key( (string) get_user_meta( $user_id, PAPELITO_PAGARME_RECIPIENT_LAST_ERROR_CODE_META, true ) ),
		'last_error_fields' => papelito_pagarme_get_vendor_rejected_fields( $user_id ),
	);
}

/**
 * Indica se o estado oficial exige que o responsavel conclua o KYC na WebApp.
 */
function papelito_pagarme_kyc_action_required( string $recipient_status, string $kyc_status, string $kyc_status_reason ): bool {
	return 'affiliation' === sanitize_key( $recipient_status )
		&& 'partially_denied' === sanitize_key( $kyc_status )
		&& 'additional_documents_required' === sanitize_key( $kyc_status_reason );
}

/**
 * Indica se o vendor tem uma pendencia que pode ser resolvida pela WebApp.
 */
function papelito_pagarme_vendor_kyc_action_required( int $user_id ): bool {
	$state = papelito_pagarme_get_vendor_recipient_state( $user_id );

	return papelito_pagarme_kyc_action_required(
		$state['status'],
		$state['kyc_status'],
		$state['kyc_status_reason']
	);
}

/**
 * Normaliza telefone brasileiro para o payload do Pagar.me.
 *
 * @return array<string,string>
 */
function papelito_pagarme_phone_payload( string $value ): array {
	$normalized = function_exists( 'papelito_auth_normalize_phone' )
		? papelito_auth_normalize_phone( $value )
		: preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', $value );

	$digits = is_string( $normalized ) ? $normalized : '';

	if ( strlen( $digits ) < 10 ) {
		return array(
			'country_code' => '55',
			'area_code'    => '11',
			'number'       => '000000000',
		);
	}

	return array(
		'country_code' => '55',
		'area_code'    => substr( $digits, 0, 2 ),
		'number'       => substr( $digits, 2 ),
	);
}

/**
 * Normaliza um endereco para o contrato do Pagar.me.
 *
 * @param array<string,mixed> $input Dados crus.
 * @return array<string,string>
 */
function papelito_pagarme_address_payload( array $input ): array {
	$street       = sanitize_text_field( (string) ( $input['street'] ?? $input['line_1'] ?? '' ) );
	$number       = sanitize_text_field( (string) ( $input['number'] ?? '' ) );
	$complement   = sanitize_text_field( (string) ( $input['complement'] ?? $input['complementary'] ?? '' ) );
	$neighborhood = sanitize_text_field( (string) ( $input['neighborhood'] ?? '' ) );

	$line_1 = trim( $street . ( '' !== $number ? ', ' . $number : '' ) . ( '' !== $neighborhood ? ', ' . $neighborhood : '' ) );
	$line_2 = trim( $complement );

	return array(
		'line_1'     => $line_1,
		'line_2'     => $line_2,
		'zip_code'   => function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( (string) ( $input['zipCode'] ?? $input['zip_code'] ?? '' ) ) : preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', (string) ( $input['zipCode'] ?? $input['zip_code'] ?? '' ) ),
		'city'       => sanitize_text_field( (string) ( $input['city'] ?? '' ) ),
		'state'      => strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ),
		'country'    => 'BR',
	);
}

/**
 * Normaliza um endereco para o contrato de recebedor do Pagar.me.
 *
 * Diferente de papelito_pagarme_address_payload() (usada no objeto `customer`
 * de pedidos, que aceita line_1/line_2), o `register_information` do recebedor
 * exige os campos separados.
 *
 * @param array<string,mixed> $input Dados crus.
 * @return array<string,string>
 */
function papelito_pagarme_recipient_address_payload( array $input ): array {
	$zip_raw       = (string) ( $input['zipCode'] ?? $input['zip_code'] ?? '' );
	$complementary = sanitize_text_field( (string) ( $input['complement'] ?? $input['complementary'] ?? '' ) );
	$reference     = sanitize_text_field( (string) ( $input['referencePoint'] ?? $input['reference_point'] ?? '' ) );

	return array(
		'street'          => sanitize_text_field( (string) ( $input['street'] ?? $input['line_1'] ?? '' ) ),
		'street_number'   => sanitize_text_field( (string) ( $input['number'] ?? $input['streetNumber'] ?? $input['street_number'] ?? '' ) ),
		'complementary'   => '' !== $complementary ? $complementary : 'N/A',
		'neighborhood'    => sanitize_text_field( (string) ( $input['neighborhood'] ?? '' ) ),
		'reference_point' => '' !== $reference ? $reference : 'N/A',
		'zip_code'        => function_exists( 'papelito_normalize_cep' ) ? papelito_normalize_cep( $zip_raw ) : preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', $zip_raw ),
		'city'            => sanitize_text_field( (string) ( $input['city'] ?? '' ) ),
		'state'           => strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ),
	);
}

/**
 * Normaliza telefone brasileiro para o contrato de recebedor do Pagar.me.
 *
 * O recebedor espera { ddd, number, type }, diferente do objeto `customer`
 * de pedidos (country_code/area_code/number) montado por
 * papelito_pagarme_phone_payload().
 *
 * @return array<string,string>
 */
function papelito_pagarme_recipient_phone_payload( string $value ): array {
	$normalized = function_exists( 'papelito_auth_normalize_phone' )
		? papelito_auth_normalize_phone( $value )
		: preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', $value );

	$digits = is_string( $normalized ) ? $normalized : '';

	if ( strlen( $digits ) < 10 ) {
		return array(
			'ddd'    => '11',
			'number' => '000000000',
			'type'   => 'mobile',
		);
	}

	return array(
		'ddd'    => substr( $digits, 0, 2 ),
		'number' => substr( $digits, 2 ),
		'type'   => 'mobile',
	);
}

/**
 * Monta o payload de um parceiro administrador.
 *
 * @param array<string,mixed> $partner       Dados do draft.
 * @param string              $fallback_phone Telefone do vendor (o wizard nao coleta o do socio).
 * @return array<string,mixed>
 */
function papelito_pagarme_partner_payload( array $partner, string $fallback_phone = '' ): array {
	$document = preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', (string) ( $partner['document'] ?? '' ) );
	$phone    = (string) ( $partner['phone'] ?? $partner['phoneNumber'] ?? '' );

	if ( '' === trim( $phone ) ) {
		$phone = $fallback_phone;
	}

	$payload = array(
		'name'                             => sanitize_text_field( (string) ( $partner['name'] ?? '' ) ),
		'email'                            => sanitize_email( (string) ( $partner['email'] ?? '' ) ),
		'document'                         => $document,
		'type'                             => 'individual',
		'birthdate'                        => sanitize_text_field( (string) ( $partner['birthdate'] ?? '' ) ),
		'monthly_income'                   => (int) round( (float) str_replace( ',', '.', preg_replace( '/[^\d,.-]/', '', (string) ( $partner['monthlyIncome'] ?? '0' ) ) ) ),
		'professional_occupation'          => sanitize_text_field( (string) ( $partner['professionalOccupation'] ?? '' ) ),
		'self_declared_legal_representative' => ! empty( $partner['selfDeclaredLegalRepresentative'] ),
		'phone_numbers'                    => array(
			papelito_pagarme_recipient_phone_payload( $phone ),
		),
		'address'                          => papelito_pagarme_recipient_address_payload( isset( $partner['address'] ) && is_array( $partner['address'] ) ? $partner['address'] : array() ),
	);

	// `mother_name` e opcional no contrato do representante legal. Enviar string
	// vazia e recusado como formato invalido, entao o campo so entra preenchido.
	$mother_name = sanitize_text_field( (string) ( $partner['motherName'] ?? '' ) );

	if ( '' !== $mother_name ) {
		$payload['mother_name'] = $mother_name;
	}

	return $payload;
}

/**
 * Normaliza a conta bancaria do vendor para o contrato `default_bank_account`.
 *
 * Os digitos verificadores (`branch_check_digit`/`account_check_digit`) sao
 * OMITIDOS quando vazios: contas como o Nubank (agencia 0001) nao tem digito de
 * agencia, e a Pagar.me rejeita a string vazia com
 * "invalid_parameter | agencia_dv | Invalid format".
 *
 * O titular sai sempre como `company` com o CNPJ do recebedor: a Pagar.me exige
 * `holder_document` igual ao documento do recebedor, e o recebedor e sempre `corporation`.
 *
 * @param array<string,mixed> $bank_account Dados crus do draft (`bankAccount`).
 * @param string              $fallback_holder_name Nome usado quando holderName vazio.
 * @param string              $recipient_document  CNPJ do recebedor.
 * @return array<string,string>
 */
function papelito_pagarme_bank_account_payload( array $bank_account, string $fallback_holder_name, string $recipient_document ): array {
	// A agencia (branch_number) da Pagar.me aceita no maximo 4 digitos; valores
	// mais longos disparam "invalid_parameter | agencia | Value too long".
	$branch_number = substr( preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', (string) ( $bank_account['branchNumber'] ?? '' ) ), 0, 4 );

	$payload = array(
		'holder_name'     => sanitize_text_field( (string) ( $bank_account['holderName'] ?? $fallback_holder_name ) ),
		'holder_type'     => 'company',
		'holder_document' => preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', $recipient_document ),
		'bank'            => sanitize_text_field( (string) ( $bank_account['bankCode'] ?? '' ) ),
		'branch_number'   => $branch_number,
		'account_number'  => sanitize_text_field( (string) ( $bank_account['accountNumber'] ?? '' ) ),
		'type'            => sanitize_text_field( (string) ( $bank_account['type'] ?? 'checking' ) ),
	);

	$branch_check_digit  = sanitize_text_field( (string) ( $bank_account['branchCheckDigit'] ?? '' ) );
	$account_check_digit = sanitize_text_field( (string) ( $bank_account['accountCheckDigit'] ?? '' ) );

	if ( '' !== $branch_check_digit ) {
		$payload['branch_check_digit'] = $branch_check_digit;
	}

	if ( '' !== $account_check_digit ) {
		$payload['account_check_digit'] = $account_check_digit;
	}

	return $payload;
}

/**
 * Carrega os dados necessarios para montar o payload do recebedor.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_get_recipient_context( int $user_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$draft = function_exists( 'papelito_get_vendor_pagarme_recipient_draft' )
		? papelito_get_vendor_pagarme_recipient_draft( $user_id )
		: null;

	if ( ! is_array( $draft ) ) {
		return new WP_Error(
			'papelito_pagarme_missing_draft',
			'Os dados financeiros do vendor ainda não foram preenchidos.',
			array( 'status' => 422 )
		);
	}

	return array(
		'user'         => $user,
		'draft'        => $draft,
		'store_name'   => sanitize_text_field( (string) get_user_meta( $user_id, 'store_name', true ) ),
		'phone'        => sanitize_text_field( (string) get_user_meta( $user_id, 'phone_number', true ) ),
		'cnpj'         => preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', (string) get_user_meta( $user_id, 'cnpj', true ) ),
		'address'      => array(
			'street'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_STREET_META, true ),
			'number'       => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NUMBER_META, true ),
			'complement'   => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_COMPLEMENT_META, true ),
			'neighborhood' => (string) get_user_meta( $user_id, PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META, true ),
			'city'         => (string) get_user_meta( $user_id, 'city', true ),
			'state'        => (string) get_user_meta( $user_id, 'state', true ),
			'zip_code'     => (string) get_user_meta( $user_id, 'cep', true ),
		),
		'partners'     => isset( $draft['managingPartners'] ) && is_array( $draft['managingPartners'] ) ? $draft['managingPartners'] : array(),
		'bank_account' => isset( $draft['bankAccount'] ) && is_array( $draft['bankAccount'] ) ? $draft['bankAccount'] : array(),
		'transfer'     => isset( $draft['transfer'] ) && is_array( $draft['transfer'] ) ? $draft['transfer'] : array(),
	);
}

/**
 * Valida os campos obrigatorios do recebedor antes de chamar a Pagar.me.
 *
 * @param array<string,mixed> $context Dados carregados do vendor.
 * @return WP_Error|null
 */
function papelito_pagarme_validate_recipient_context( array $context ) {
	$cnpj     = (string) $context['cnpj'];
	$phone    = (string) $context['phone'];
	$partners = $context['partners'];

	if ( '' === $cnpj ) {
		return new WP_Error(
			'papelito_pagarme_missing_document',
			'O vendor precisa ter CNPJ válido para criar o recebedor.',
			array( 'status' => 422 )
		);
	}

	if ( ! papelito_vendor_phone_is_valid( $phone ) ) {
		return new WP_Error(
			'papelito_pagarme_invalid_phone',
			'O vendor precisa ter telefone válido com DDD para criar o recebedor.',
			array( 'status' => 422 )
		);
	}

	if ( empty( $partners ) || ! is_array( $partners[0] ) ) {
		return new WP_Error(
			'papelito_pagarme_missing_partner',
			'Informe ao menos um administrador responsável pelo recebedor.',
			array( 'status' => 422 )
		);
	}

	$main_address = papelito_pagarme_recipient_address_payload( $context['address'] );
	$missing_address_field = array_filter(
		array(
			$main_address['street'],
			$main_address['street_number'],
			$main_address['zip_code'],
			$main_address['city'],
			$main_address['state'],
		),
		static function ( string $value ): bool {
			return '' === $value;
		}
	);

	if ( ! empty( $missing_address_field ) ) {
		return new WP_Error(
			'papelito_pagarme_missing_address',
			'O vendor precisa ter endereço comercial completo para criar o recebedor.',
			array( 'status' => 422 )
		);
	}

	$company_name = sanitize_text_field( (string) ( $context['draft']['companyName'] ?? $context['store_name'] ) );

	// A Pagar.me exige razao social com pelo menos alguns caracteres; nomes muito
	// curtos disparam "invalid_parameter | legal_name | Value too short".
	if ( mb_strlen( trim( $company_name ) ) < 5 ) {
		return new WP_Error(
			'papelito_pagarme_invalid_company_name',
			'A razao social do recebedor precisa ter ao menos 5 caracteres.',
			array( 'status' => 422 )
		);
	}

	if ( ! papelito_vendor_bank_holder_matches_recipient( $context['bank_account'], $cnpj ) ) {
		return new WP_Error(
			'papelito_pagarme_bank_holder_mismatch',
			'A conta bancária do recebedor precisa estar no CNPJ da empresa (conta PJ).',
			array( 'status' => 422 )
		);
	}

	return null;
}

/**
 * Monta o bloco `register_information` para o payload do recebedor.
 *
 * @param array<string,mixed> $context Dados validados do vendor.
 * @return array<string,mixed>
 */
function papelito_pagarme_build_register_information( array $context ): array {
	$user         = $context['user'];
	$draft        = $context['draft'];
	$store_name   = (string) $context['store_name'];
	$phone        = (string) $context['phone'];
	$cnpj         = (string) $context['cnpj'];
	$partners     = $context['partners'];
	$company_name = sanitize_text_field( (string) ( $draft['companyName'] ?? $store_name ) );
	$main_address = papelito_pagarme_recipient_address_payload( $context['address'] );
	$partner      = papelito_pagarme_partner_payload( $partners[0], $phone );

	$register_information = array(
		'type'              => 'corporation',
		'company_name'      => $company_name,
		'trading_name'      => sanitize_text_field( (string) ( $draft['tradingName'] ?? $store_name ) ),
		'email'             => sanitize_email( (string) $user->user_email ),
		'document'          => $cnpj,
		'annual_revenue'    => (int) round( (float) str_replace( ',', '.', preg_replace( '/[^\d,.-]/', '', (string) ( $draft['annualRevenue'] ?? '0' ) ) ) ),
		'phone_numbers'     => array(
			papelito_pagarme_recipient_phone_payload( $phone ),
		),
		'main_address'      => $main_address,
		'managing_partners' => array( $partner ),
	);

	// `corporation_type` e `founding_date` sao opcionais no contrato de dados
	// minimos da Pagar.me. String vazia e recusada como formato invalido, entao
	// os dois so entram no payload quando o vendor os preencheu.
	$corporation_type = sanitize_text_field( (string) ( $draft['corporationType'] ?? '' ) );
	$founding_date    = sanitize_text_field( (string) ( $draft['foundingDate'] ?? '' ) );

	if ( '' !== $corporation_type ) {
		$register_information['corporation_type'] = $corporation_type;
	}

	if ( '' !== $founding_date ) {
		$register_information['founding_date'] = $founding_date;
	}

	return $register_information;
}

/**
 * Monta o payload completo de criacao/edicao do recebedor.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_build_recipient_payload( int $user_id ) {
	$context = papelito_pagarme_get_recipient_context( $user_id );

	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$validation_error = papelito_pagarme_validate_recipient_context( $context );

	if ( $validation_error instanceof WP_Error ) {
		return $validation_error;
	}

	$draft        = $context['draft'];
	$store_name   = (string) $context['store_name'];
	$cnpj         = (string) $context['cnpj'];
	$bank_account = $context['bank_account'];
	$transfer     = $context['transfer'];

	return array(
		'code'                 => sprintf( 'vendor-%d', $user_id ),
		'payment_mode'         => 'bank_transfer',
		'transfer_settings'    => array(
			'transfer_enabled'  => true,
			'transfer_interval' => sanitize_text_field( (string) ( $transfer['interval'] ?? 'Daily' ) ),
			'transfer_day'      => (int) ( $transfer['day'] ?? 0 ),
		),
		'default_bank_account' => papelito_pagarme_bank_account_payload( $bank_account, $store_name, $cnpj ),
		'register_information' => papelito_pagarme_build_register_information( $context ),
		'metadata'             => array(
			'user_id'    => (string) $user_id,
			'store_name' => $store_name,
		),
	);
}

/**
 * Emite o evento de dominio que aciona a notificacao centralizada do vendor.
 *
 * @param int $user_id Usuario vendor.
 * @return void
 */
function papelito_pagarme_signal_vendor_sync_pending( int $user_id ): void {
	if ( $user_id > 0 && ! papelito_pagarme_vendor_recipient_is_active( $user_id ) ) {
		do_action( 'papelito_vendor_pagarme_sync_pending', $user_id );
	}
}

/**
 * Monta o payload da conta bancaria do recebedor.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pagarme_build_recipient_bank_account_payload( int $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'papelito_vendor_not_found', 'Vendor nao encontrado.', array( 'status' => 404 ) );
	}

	$draft = function_exists( 'papelito_get_vendor_pagarme_recipient_draft' )
		? papelito_get_vendor_pagarme_recipient_draft( $user_id )
		: null;

	if ( ! is_array( $draft ) ) {
		return new WP_Error(
			'papelito_pagarme_missing_draft',
			'Os dados financeiros do vendor ainda não foram preenchidos.',
			array( 'status' => 422 )
		);
	}

	$store_name   = sanitize_text_field( (string) get_user_meta( $user_id, 'store_name', true ) );
	$cnpj         = preg_replace( PAPELITO_PAGARME_DIGITS_PATTERN, '', (string) get_user_meta( $user_id, 'cnpj', true ) );
	$bank_account = isset( $draft['bankAccount'] ) && is_array( $draft['bankAccount'] ) ? $draft['bankAccount'] : array();

	if ( ! papelito_vendor_bank_holder_matches_recipient( $bank_account, $cnpj ) ) {
		return new WP_Error(
			'papelito_pagarme_bank_holder_mismatch',
			'A conta bancária do recebedor precisa estar no CNPJ da empresa (conta PJ).',
			array( 'status' => 422 )
		);
	}

	return papelito_pagarme_bank_account_payload( $bank_account, $store_name, $cnpj );
}

/**
 * Atualiza a conta bancaria padrao do recebedor.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_update_vendor_recipient_bank_account( int $user_id, string $recipient_id ) {
	$bank_account = papelito_pagarme_build_recipient_bank_account_payload( $user_id );

	if ( is_wp_error( $bank_account ) ) {
		return $bank_account;
	}

	return papelito_pagarme_request(
		'PATCH',
		PAPELITO_PAGARME_RECIPIENTS_PATH . rawurlencode( $recipient_id ) . '/default-bank-account',
		array(
			'bank_account' => $bank_account,
			'payment_mode' => 'bank_transfer',
		)
	);
}

/**
 * Sincroniza o recebedor ja criado.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pagarme_sync_vendor_recipient( int $user_id ) {
	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $user_id );

	if ( '' === $recipient_id ) {
		return new WP_Error(
			'papelito_pagarme_missing_recipient',
			'O vendor ainda não possui recebedor criado.',
			array( 'status' => 404 )
		);
	}

	$result = papelito_pagarme_request( 'GET', PAPELITO_PAGARME_RECIPIENTS_PATH . rawurlencode( $recipient_id ) );

	if ( is_wp_error( $result ) ) {
		papelito_pagarme_save_vendor_recipient_error( $user_id, $result );
		papelito_pagarme_signal_vendor_sync_pending( $user_id );
		return $result;
	}

	return papelito_pagarme_save_vendor_recipient_state( $user_id, $result );
}

/**
 * Envia ao e-mail da conta o link temporario da WebApp de KYC.
 */
function papelito_pagarme_send_vendor_kyc_link_email( int $user_id, string $url ): bool {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) || '' === $url ) {
		return false;
	}

	$name      = sanitize_text_field( $user->display_name );
	$greeting  = '' !== $name ? 'Olá, ' . $name . '.' : 'Olá.';
	$body_html = sprintf(
		'<h1 class="papelito-headline" style="margin:0 0 16px;font-family:%1$s;font-size:30px;font-weight:900;line-height:34px;letter-spacing:-0.04em;color:%2$s;">Conclua sua verificação</h1><p style="margin:0 0 16px;font-family:%1$s;font-size:15px;font-weight:500;line-height:24px;color:%3$s;">%4$s A Pagar.me solicitou uma etapa de identificação para liberar os recebimentos da sua loja.</p><p style="margin:0 0 24px;font-family:%1$s;font-size:15px;font-weight:500;line-height:24px;color:%3$s;">Este link é individual e fica disponível por 20 minutos.</p>%5$s',
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( PAPELITO_EMAIL_INK ),
		esc_attr( PAPELITO_EMAIL_TEXT_SOFT ),
		esc_html( $greeting ),
		papelito_email_button( $url, 'Concluir verificação' )
	);
	$html      = papelito_email_shell(
		array(
			'kicker'       => 'PAGAMENTOS',
			'preheader'    => 'Conclua sua verificação na Pagar.me.',
			'body_html'    => $body_html,
			'footer_lines' => array( 'Se você não solicitou esta ação, ignore este e-mail.' ),
		)
	);
	$text      = implode(
		"\n",
		array(
			$greeting,
			'',
			'A Pagar.me solicitou uma etapa de identificação para liberar os recebimentos da sua loja.',
			'O link é individual e fica disponível por 20 minutos.',
			'',
			'Concluir verificação: ' . $url,
			'',
			'Se você não solicitou esta ação, ignore este e-mail.',
		)
	);

	return papelito_email_send( sanitize_email( $user->user_email ), 'Conclua sua verificação na Pagar.me', $html, $text );
}

/**
 * Reserva uma das cotas de KYC do vendor em uma unica escrita atomica.
 *
 * `INSERT ... ON DUPLICATE KEY UPDATE` bloqueia a chave primaria do vendor
 * durante a decisao. Em MySQL, a escrita aceita retorna 1 (insert) ou 2
 * (update); quando a cota ja acabou, nenhuma coluna muda e retorna 0.
 *
 * @param int $user_id        Vendor autenticado.
 * @param int $max_attempts   Limite da janela.
 * @param int $window_seconds Duracao da janela.
 * @return bool Se a cota foi reservada.
 */
function papelito_pagarme_reserve_kyc_link_quota( int $user_id, int $max_attempts, int $window_seconds ): bool {
	global $wpdb;

	if ( $user_id <= 0 || $max_attempts <= 0 || $window_seconds <= 0 ) {
		return false;
	}

	$table  = papelito_pagarme_kyc_link_limits_table();
	$query  = $wpdb->prepare(
		'INSERT INTO %i (vendor_id, attempts, window_started_at, expires_at)
		VALUES (%d, 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND))
		ON DUPLICATE KEY UPDATE
			attempts = IF(expires_at <= UTC_TIMESTAMP(), 1, IF(attempts < %d, attempts + 1, attempts)),
			window_started_at = IF(expires_at <= UTC_TIMESTAMP(), UTC_TIMESTAMP(), window_started_at),
			expires_at = IF(expires_at <= UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), expires_at)',
		$table,
		$user_id,
		$window_seconds,
		$max_attempts,
		$window_seconds
	);
	$result = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared with placeholders above.

	return 1 === $result || 2 === $result;
}

/**
 * Gera um link temporario somente quando a Pagar.me informou uma pendencia elegivel.
 *
 * @return array<string,mixed>|WP_Error
 */
function papelito_pagarme_create_vendor_kyc_link( int $user_id ) {
	$state = papelito_pagarme_sync_vendor_recipient( $user_id );

	if ( is_wp_error( $state ) ) {
		return $state;
	}

	if ( ! papelito_pagarme_vendor_kyc_action_required( $user_id ) ) {
		return new WP_Error(
			'papelito_pagarme_kyc_not_required',
			'A Pagar.me ainda não solicitou uma ação de verificação para este recebedor.',
			array( 'status' => 409 )
		);
	}

	if ( ! papelito_pagarme_reserve_kyc_link_quota( $user_id, 5, 20 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error(
			'papelito_pagarme_kyc_link_rate_limited',
			'Aguarde alguns minutos antes de gerar outro link de verificação.',
			array( 'status' => 429 )
		);
	}

	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $user_id );
	$result       = papelito_pagarme_request( 'POST', PAPELITO_PAGARME_RECIPIENTS_PATH . rawurlencode( $recipient_id ) . '/kyc_link', array() );

	if ( is_wp_error( $result ) ) {
		papelito_pagarme_save_vendor_recipient_error( $user_id, $result );
		return $result;
	}

	$url  = esc_url_raw( (string) ( $result['url'] ?? '' ) );
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if (
		'' === $url
		|| 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) )
		|| ( 'pagar.me' !== $host && ! str_ends_with( $host, '.pagar.me' ) )
	) {
		return new WP_Error(
			'papelito_pagarme_kyc_link_invalid_response',
			'A Pagar.me não retornou um link de verificação válido.',
			array( 'status' => 502 )
		);
	}

	return array_merge(
		papelito_pagarme_get_vendor_recipient_state( $user_id ),
		array(
			'url'        => $url,
			'email_sent' => papelito_pagarme_send_vendor_kyc_link_email( $user_id, $url ),
		)
	);
}

/**
 * Cria ou atualiza o recebedor do vendor.
 *
 * @return array<string,string>|WP_Error
 */
function papelito_pagarme_upsert_vendor_recipient( int $user_id ) {
	$payload = papelito_pagarme_build_recipient_payload( $user_id );

	if ( is_wp_error( $payload ) ) {
		papelito_pagarme_save_vendor_recipient_error( $user_id, $payload );
		papelito_pagarme_signal_vendor_sync_pending( $user_id );
		return $payload;
	}

	$recipient_id = papelito_pagarme_get_vendor_recipient_id( $user_id );
	$path         = '' === $recipient_id ? rtrim( PAPELITO_PAGARME_RECIPIENTS_PATH, '/' ) : PAPELITO_PAGARME_RECIPIENTS_PATH . rawurlencode( $recipient_id );
	$method       = '' === $recipient_id ? 'POST' : 'PUT';
	$body         = $payload;

	if ( 'PUT' === $method ) {
		$body = array(
			'register_information' => $payload['register_information'],
			'metadata'             => $payload['metadata'],
		);
	}

	$result = papelito_pagarme_request( $method, $path, $body );

	if ( is_wp_error( $result ) ) {
		papelito_pagarme_save_vendor_recipient_error( $user_id, $result );
		papelito_pagarme_signal_vendor_sync_pending( $user_id );
		return $result;
	}

	if ( 'PUT' === $method ) {
		$bank_update = papelito_pagarme_update_vendor_recipient_bank_account( $user_id, $recipient_id );

		if ( is_wp_error( $bank_update ) ) {
			papelito_pagarme_save_vendor_recipient_error( $user_id, $bank_update );
			papelito_pagarme_signal_vendor_sync_pending( $user_id );
			return $bank_update;
		}
	}

	$state = papelito_pagarme_save_vendor_recipient_state( $user_id, $result );

	if ( papelito_pagarme_vendor_recipient_is_active( $user_id ) ) {
		do_action( 'papelito_vendor_pagarme_sync_completed', $user_id );
	} else {
		papelito_pagarme_signal_vendor_sync_pending( $user_id );
	}

	return $state;
}

/**
 * Auto-cria recebedor apos aprovacao do vendor.
 */
function papelito_pagarme_handle_vendor_approved( int $user_id ): void {
	if ( $user_id <= 0 || ! papelito_pagarme_is_configured() ) {
		return;
	}

	papelito_pagarme_upsert_vendor_recipient( $user_id );
}
add_action( 'papelito_vendor_approved', 'papelito_pagarme_handle_vendor_approved', 20, 1 );

/**
 * Restringe os endpoints de recebedor ao vendedor autenticado.
 *
 * @return bool|WP_Error
 */
function papelito_pagarme_vendor_recipient_permission() {
	$check = function_exists( 'papelito_vendor_dashboard_require_seller' )
		? papelito_vendor_dashboard_require_seller()
		: false;

	return is_wp_error( $check ) ? $check : true;
}

/**
 * Consulta e atualiza o estado local do recebedor autenticado.
 */
function papelito_pagarme_rest_get_vendor_recipient(): WP_REST_Response {
	$user_id = get_current_user_id();
	$state   = papelito_pagarme_get_vendor_recipient_state( $user_id );

	if ( '' === $state['recipient_id'] ) {
		papelito_pagarme_signal_vendor_sync_pending( $user_id );
		return new WP_REST_Response( $state, 200 );
	}

	$synced = papelito_pagarme_sync_vendor_recipient( $user_id );

	if ( ! is_wp_error( $synced ) ) {
		$state = $synced;
	}

	return new WP_REST_Response( $state, 200 );
}

/**
 * Cria ou atualiza o recebedor do vendedor autenticado.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_pagarme_rest_upsert_vendor_recipient() {
	$result = papelito_pagarme_upsert_vendor_recipient( get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		return papelito_pagarme_recipient_error_response( $result );
	}

	return new WP_REST_Response( $result, 200 );
}

/**
 * Cria o link temporario de KYC para o vendedor autenticado.
 *
 * @return WP_REST_Response|WP_Error
 */
function papelito_pagarme_rest_create_vendor_kyc_link() {
	$result = papelito_pagarme_create_vendor_kyc_link( get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		return papelito_pagarme_recipient_error_response( $result );
	}

	$response = new WP_REST_Response( $result, 200 );
	$response->header( 'Cache-Control', 'no-store, private' );

	return $response;
}

/**
 * Registra os endpoints REST de recebedor.
 */
function papelito_pagarme_register_recipient_routes(): void {
	register_rest_route(
		'papelito/v1',
		'/vendor/recipient',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_pagarme_vendor_recipient_permission',
				'callback'            => 'papelito_pagarme_rest_get_vendor_recipient',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'papelito_pagarme_vendor_recipient_permission',
				'callback'            => 'papelito_pagarme_rest_upsert_vendor_recipient',
			),
		)
	);

	register_rest_route(
		'papelito/v1',
		'/vendor/recipient/kyc-link',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_pagarme_vendor_recipient_permission',
			'callback'            => 'papelito_pagarme_rest_create_vendor_kyc_link',
		)
	);
}
add_action( 'rest_api_init', 'papelito_pagarme_register_recipient_routes' );

/**
 * Metade financeira da dupla aprovacao do vendor.
 *
 * Vender exige faixa de CEP que cubra o destino E recebedor `active`. Fica aqui, junto do resto
 * da regra de recebedor, e nao no filtro de catalogo, para que a origem da decisao seja obvia.
 * O `function_exists` do lado do chamador garante que uma ordem de carregamento diferente nao
 * esvazie o catalogo inteiro.
 *
 * @param int $vendor_id Vendor.
 * @return bool
 */
function papelito_vendor_can_receive_payments( int $vendor_id ): bool {
	return papelito_pagarme_vendor_recipient_is_active( $vendor_id );
}
