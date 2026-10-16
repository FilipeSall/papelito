<?php
/**
 * Perfil financeiro comum do vendor, separado do estado de cada gateway.
 *
 * Reúne os dados que o vendor preenche uma vez — empresa, representante legal, conta PJ e
 * transferência — no mesmo formato de campos do draft legado da Pagar.me, para que adapters e
 * formulário continuem falando a mesma língua. O CNPJ não mora aqui: vem do cadastro autoritativo.
 *
 * A regra de ouro: CPF e nascimento do representante só existem cifrados
 * (`papelito_pii_encrypt`). A projeção pública devolve indicadores (`hasDocument`,
 * `documentLast4`, `hasBirthdate`), e uma edição que omite esses campos os preserva. Valor
 * mascarado nunca vira dado novo, e falha de cifra não grava nada.
 *
 * Toda gravação é versionada e feita por compare-and-set: versão obsoleta é conflito (409).
 *
 * O que este módulo não faz: não valida requisitos de gateway (cada adapter tem os seus), não
 * aprova KYC e não remove o draft legado — ele continua lido para compatibilidade e rollback.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_FINANCIAL_PROFILE_META   = 'papelito_vendor_financial_profile';
const PAPELITO_VENDOR_FINANCIAL_PROFILE_SCHEMA = 1;
const PAPELITO_VENDOR_FINANCIAL_PROFILE_MASK   = '/[*•]/u';

/**
 * Estrutura vazia do perfil, sem dado inventado: só os defaults de configuração da conta.
 *
 * @return array<string,mixed>
 */
function papelito_vendor_financial_profile_empty_data(): array {
	return array(
		'companyName'      => '',
		'tradingName'      => '',
		'corporationType'  => '',
		'foundingDate'     => '',
		'annualRevenue'    => '',
		'managingPartners' => array(
			array(
				'name'                            => '',
				'email'                           => '',
				'motherName'                      => '',
				'monthlyIncome'                   => '',
				'professionalOccupation'          => '',
				'selfDeclaredLegalRepresentative' => false,
				'address'                         => array(
					'zipCode'      => '',
					'street'       => '',
					'streetNumber' => '',
					'complement'   => '',
					'neighborhood' => '',
					'city'         => '',
					'state'        => '',
				),
			),
		),
		'bankAccount'      => array(
			'holderName'        => '',
			'holderType'        => 'company',
			'holderDocument'    => '',
			'bankCode'          => '',
			'branchNumber'      => '',
			'branchCheckDigit'  => '',
			'accountNumber'     => '',
			'accountCheckDigit' => '',
			'type'              => 'checking',
		),
		'transfer'         => array(
			'interval' => 'Daily',
			'day'      => '0',
		),
	);
}

/**
 * Aplica sobre `$base` apenas as chaves conhecidas presentes em `$input`, sanitizadas.
 *
 * Chave ausente preserva o valor anterior; chave desconhecida é ignorada.
 *
 * @param array<string,mixed> $base  Estrutura de referência.
 * @param array<string,mixed> $input Valores recebidos.
 * @return array<string,mixed>
 */
function papelito_vendor_financial_profile_merge( array $base, array $input ): array {
	foreach ( $base as $key => $current ) {
		if ( ! array_key_exists( $key, $input ) ) {
			continue;
		}

		if ( 'managingPartners' === $key ) {
			$partner         = is_array( $input[ $key ][0] ?? null ) ? $input[ $key ][0] : array();
			$base[ $key ][0] = papelito_vendor_financial_profile_merge( $current[0], $partner );
			continue;
		}

		if ( is_array( $current ) ) {
			$base[ $key ] = is_array( $input[ $key ] ) ? papelito_vendor_financial_profile_merge( $current, $input[ $key ] ) : $current;
			continue;
		}

		$base[ $key ] = is_bool( $current ) ? (bool) $input[ $key ] : sanitize_text_field( (string) $input[ $key ] );
	}

	return $base;
}

/**
 * Perfil gravado, cru (com envelopes).
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>|null
 */
function papelito_vendor_financial_profile_stored( int $vendor_id ): ?array {
	$stored = get_user_meta( $vendor_id, PAPELITO_VENDOR_FINANCIAL_PROFILE_META, true );

	return is_array( $stored ) && PAPELITO_VENDOR_FINANCIAL_PROFILE_SCHEMA === (int) ( $stored['schema'] ?? 0 ) ? $stored : null;
}

/**
 * Impressão digital do CNPJ vigente do vendor.
 *
 * @param int $vendor_id Vendor.
 * @return string
 */
function papelito_vendor_financial_profile_cnpj_fingerprint( int $vendor_id ): string {
	$digits = preg_replace( '/\D+/', '', (string) get_user_meta( $vendor_id, 'cnpj', true ) );

	return '' === $digits ? '' : hash_hmac( 'sha256', (string) $digits, wp_salt( 'papelito_vendor_financial_profile' ) );
}

/**
 * Impressão digital estável de um draft legado, para detectar divergência na migração.
 *
 * @param array<string,mixed> $draft Draft legado.
 * @return string
 */
function papelito_vendor_financial_profile_legacy_hash( array $draft ): string {
	return hash_hmac( 'sha256', (string) wp_json_encode( papelito_vendor_financial_profile_sort( $draft ) ), wp_salt( 'papelito_vendor_financial_profile' ) );
}

/**
 * Ordena chaves recursivamente para hash estável.
 *
 * @param mixed $value Valor.
 * @return mixed
 */
function papelito_vendor_financial_profile_sort( mixed $value ): mixed {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	foreach ( $value as $key => $item ) {
		$value[ $key ] = papelito_vendor_financial_profile_sort( $item );
	}
	ksort( $value );

	return $value;
}

/**
 * Valida e cifra CPF/nascimento recebidos, aplicando-os sobre os envelopes existentes.
 *
 * Campo omitido mantém o envelope; máscara, remoção e formato inválido são recusados.
 *
 * @param array<string,mixed> $sensitive Envelopes atuais.
 * @param array<string,mixed> $partner   Sócio recebido.
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_financial_profile_apply_sensitive( array $sensitive, array $partner ) {
	$fields = array(
		'document'  => 'partnerDocument',
		'birthdate' => 'partnerBirthdate',
	);

	foreach ( $fields as $input_key => $envelope_key ) {
		if ( ! array_key_exists( $input_key, $partner ) || null === $partner[ $input_key ] ) {
			continue;
		}

		$value = sanitize_text_field( (string) $partner[ $input_key ] );
		$error = papelito_vendor_financial_profile_sensitive_error( $input_key, $value );
		if ( $error instanceof WP_Error ) {
			return $error;
		}

		$plain     = 'document' === $input_key ? preg_replace( '/\D+/', '', $value ) : $value;
		$encrypted = papelito_pii_encrypt( (string) $plain );
		if ( is_wp_error( $encrypted ) ) {
			return new WP_Error( 'papelito_financial_profile_encryption_failed', 'Não foi possível proteger os dados do representante. Nada foi salvo.', array( 'status' => 500 ) );
		}

		$sensitive[ $envelope_key ] = $encrypted;
		if ( 'document' === $input_key ) {
			$sensitive['partnerDocumentLast4'] = substr( (string) $plain, -4 );
		}
	}

	return $sensitive;
}

/**
 * Recusa de um valor sensível recebido.
 *
 * @param string $field Campo (`document` ou `birthdate`).
 * @param string $value Valor sanitizado.
 * @return WP_Error|null
 */
function papelito_vendor_financial_profile_sensitive_error( string $field, string $value ): ?WP_Error {
	if ( '' === $value ) {
		return new WP_Error(
			'papelito_financial_profile_sensitive_removal',
			'CPF e data de nascimento do representante não podem ser apagados; informe o novo valor.',
			array(
				'status' => 422,
				'field'  => $field,
			)
		);
	}

	if ( 1 === preg_match( PAPELITO_VENDOR_FINANCIAL_PROFILE_MASK, $value ) ) {
		return new WP_Error(
			'papelito_financial_profile_masked_value',
			'Valor mascarado não pode ser salvo como dado novo.',
			array(
				'status' => 422,
				'field'  => $field,
			)
		);
	}

	$valid = 'document' === $field
		? function_exists( 'papelito_revendedor_validate_cpf' ) && papelito_revendedor_validate_cpf( $value )
		: 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );

	return $valid ? null : new WP_Error(
		'papelito_financial_profile_invalid_' . $field,
		'document' === $field ? 'Informe um CPF válido para o representante.' : 'Informe a data de nascimento no formato AAAA-MM-DD.',
		array(
			'status' => 422,
			'field'  => $field,
		)
	);
}

/**
 * Grava o registro por compare-and-set contra o registro lido.
 *
 * @param int                      $vendor_id Vendor.
 * @param array<string,mixed>      $record    Registro novo.
 * @param array<string,mixed>|null $previous  Registro lido antes da alteração.
 * @return bool Se a gravação venceu.
 */
function papelito_vendor_financial_profile_save( int $vendor_id, array $record, ?array $previous ): bool {
	if ( null === $previous ) {
		return (bool) add_user_meta( $vendor_id, PAPELITO_VENDOR_FINANCIAL_PROFILE_META, $record, true );
	}

	return (bool) update_user_meta( $vendor_id, PAPELITO_VENDOR_FINANCIAL_PROFILE_META, $record, $previous );
}

/**
 * Monta um registro novo a partir de dados e envelopes.
 *
 * @param int                      $vendor_id Vendor.
 * @param array<string,mixed>      $data      Dados não sensíveis.
 * @param array<string,mixed>      $sensitive Envelopes.
 * @param array<string,mixed>|null $previous  Registro anterior.
 * @param array<string,string>     $origin    `source` e `legacyHash`.
 * @return array<string,mixed>
 */
function papelito_vendor_financial_profile_record( int $vendor_id, array $data, array $sensitive, ?array $previous, array $origin ): array {
	return array(
		'schema'          => PAPELITO_VENDOR_FINANCIAL_PROFILE_SCHEMA,
		'version'         => (int) ( $previous['version'] ?? 0 ) + 1,
		'source'          => $origin['source'],
		'legacyHash'      => $origin['legacyHash'] ?? (string) ( $previous['legacyHash'] ?? '' ),
		'cnpjFingerprint' => papelito_vendor_financial_profile_cnpj_fingerprint( $vendor_id ),
		'updatedAt'       => current_time( 'mysql', true ),
		'data'            => $data,
		'sensitive'       => $sensitive,
	);
}

/**
 * Aplica uma edição parcial ao perfil, exigindo a versão lida pelo cliente.
 *
 * @param int                 $vendor_id        Vendor.
 * @param array<string,mixed> $patch            Campos alterados no formato do perfil.
 * @param int                 $expected_version Versão que o cliente leu.
 * @param string              $source           Origem da gravação, para auditoria.
 * @return array<string,mixed>|WP_Error Projeção pública atualizada.
 */
function papelito_vendor_financial_profile_patch( int $vendor_id, array $patch, int $expected_version, string $source = 'profile' ) {
	$previous = papelito_vendor_financial_profile_stored( $vendor_id );

	if ( (int) ( $previous['version'] ?? 0 ) !== $expected_version ) {
		return papelito_vendor_financial_profile_conflict();
	}

	$data      = papelito_vendor_financial_profile_merge( is_array( $previous['data'] ?? null ) ? $previous['data'] : papelito_vendor_financial_profile_empty_data(), $patch );
	$partner   = is_array( $patch['managingPartners'][0] ?? null ) ? $patch['managingPartners'][0] : array();
	$sensitive = papelito_vendor_financial_profile_apply_sensitive( is_array( $previous['sensitive'] ?? null ) ? $previous['sensitive'] : array(), $partner );

	if ( is_wp_error( $sensitive ) ) {
		return $sensitive;
	}

	$record = papelito_vendor_financial_profile_record( $vendor_id, $data, $sensitive, $previous, array( 'source' => $source ) );

	if ( ! papelito_vendor_financial_profile_save( $vendor_id, $record, $previous ) ) {
		return papelito_vendor_financial_profile_conflict();
	}

	do_action( 'papelito_vendor_financial_profile_updated', $vendor_id, $record['version'] );

	return papelito_vendor_financial_profile_public( $vendor_id );
}

/**
 * Erro de versão concorrente.
 *
 * @return WP_Error
 */
function papelito_vendor_financial_profile_conflict(): WP_Error {
	return new WP_Error( 'papelito_financial_profile_version_conflict', 'Os dados financeiros foram alterados em outra sessão. Recarregue antes de salvar.', array( 'status' => 409 ) );
}

/**
 * Importa o draft legado da Pagar.me para o perfil, de forma idempotente.
 *
 * `create` sem perfil; `update` quando o perfil ainda é a importação anterior e o draft mudou;
 * `noop` quando nada mudou; `diverged` quando o perfil foi editado pelo caminho novo — nesse caso o
 * draft antigo nunca o sobrescreve. Com `$dry_run` nada é gravado.
 *
 * @param int  $vendor_id Vendor.
 * @param bool $dry_run   Só calcular a ação.
 * @return array{action:string,version:int}|WP_Error
 */
function papelito_vendor_financial_profile_import_legacy( int $vendor_id, bool $dry_run ) {
	$draft    = function_exists( 'papelito_get_vendor_pagarme_recipient_draft' ) ? papelito_get_vendor_pagarme_recipient_draft( $vendor_id ) : null;
	$previous = papelito_vendor_financial_profile_stored( $vendor_id );
	$version  = (int) ( $previous['version'] ?? 0 );

	if ( ! is_array( $draft ) ) {
		return array(
			'action'  => 'no_draft',
			'version' => $version,
		);
	}

	$hash   = papelito_vendor_financial_profile_legacy_hash( $draft );
	$action = papelito_vendor_financial_profile_import_action( $previous, $hash );

	if ( $dry_run || ! in_array( $action, array( 'create', 'update' ), true ) ) {
		return array(
			'action'  => $action,
			'version' => $version,
		);
	}

	$partner   = is_array( $draft['managingPartners'][0] ?? null ) ? $draft['managingPartners'][0] : array();
	$sensitive = papelito_vendor_financial_profile_import_sensitive( $partner );

	if ( is_wp_error( $sensitive ) ) {
		return $sensitive;
	}

	unset( $partner['document'], $partner['birthdate'] );
	$draft['managingPartners'] = array( $partner );
	$data                      = papelito_vendor_financial_profile_merge( papelito_vendor_financial_profile_empty_data(), $draft );
	$record                    = papelito_vendor_financial_profile_record(
		$vendor_id,
		$data,
		$sensitive,
		$previous,
		array(
			'source'     => 'legacy_draft',
			'legacyHash' => $hash,
		)
	);

	if ( ! papelito_vendor_financial_profile_save( $vendor_id, $record, $previous ) ) {
		return papelito_vendor_financial_profile_conflict();
	}

	return array(
		'action'  => $action,
		'version' => $record['version'],
	);
}

/**
 * Decide a ação de importação comparando o perfil com o draft atual.
 *
 * @param array<string,mixed>|null $previous Perfil gravado.
 * @param string                   $hash     Impressão digital do draft.
 * @return string
 */
function papelito_vendor_financial_profile_import_action( ?array $previous, string $hash ): string {
	if ( null === $previous ) {
		return 'create';
	}

	if ( 'legacy_draft' !== ( $previous['source'] ?? '' ) ) {
		return 'diverged';
	}

	return hash_equals( (string) ( $previous['legacyHash'] ?? '' ), $hash ) ? 'noop' : 'update';
}

/**
 * Cifra CPF/nascimento vindos do draft legado, sem validar formato: o legado é aceito como está.
 *
 * Campo vazio no draft fica ausente no perfil (continua pendente), nunca preenchido.
 *
 * @param array<string,mixed> $partner Sócio do draft.
 * @return array<string,string>|WP_Error
 */
function papelito_vendor_financial_profile_import_sensitive( array $partner ) {
	$sensitive = array();
	$document  = preg_replace( '/\D+/', '', (string) ( $partner['document'] ?? '' ) );
	$birthdate = sanitize_text_field( (string) ( $partner['birthdate'] ?? '' ) );

	foreach ( array(
		'partnerDocument'  => (string) $document,
		'partnerBirthdate' => $birthdate,
	) as $key => $value ) {
		if ( '' === $value ) {
			continue;
		}
		$encrypted = papelito_pii_encrypt( $value );
		if ( is_wp_error( $encrypted ) ) {
			return new WP_Error( 'papelito_financial_profile_encryption_failed', 'Não foi possível proteger os dados do representante. Nada foi salvo.', array( 'status' => 500 ) );
		}
		$sensitive[ $key ] = $encrypted;
	}

	if ( '' !== $document ) {
		$sensitive['partnerDocumentLast4'] = substr( (string) $document, -4 );
	}

	return $sensitive;
}

/**
 * Perfil completo, com CPF e nascimento decifrados, no formato do draft legado.
 *
 * Uso exclusivo de adapters server-side. Nunca devolver em REST nem logar.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>|null Nulo quando não há perfil.
 */
function papelito_vendor_financial_profile_internal( int $vendor_id ): ?array {
	$stored = papelito_vendor_financial_profile_stored( $vendor_id );

	if ( null === $stored ) {
		return null;
	}

	$data      = papelito_vendor_financial_profile_merge( papelito_vendor_financial_profile_empty_data(), (array) $stored['data'] );
	$sensitive = (array) ( $stored['sensitive'] ?? array() );

	$data['managingPartners'][0]['document']  = papelito_vendor_financial_profile_decrypt( (string) ( $sensitive['partnerDocument'] ?? '' ) );
	$data['managingPartners'][0]['birthdate'] = papelito_vendor_financial_profile_decrypt( (string) ( $sensitive['partnerBirthdate'] ?? '' ) );

	return $data;
}

/**
 * Decifra um envelope; envelope ausente ou inválido vira vazio (campo pendente), nunca erro fatal.
 *
 * @param string $envelope Envelope de PII.
 * @return string
 */
function papelito_vendor_financial_profile_decrypt( string $envelope ): string {
	if ( '' === $envelope ) {
		return '';
	}

	$plain = papelito_pii_decrypt( $envelope );

	return is_string( $plain ) ? $plain : '';
}

/**
 * Projeção segura do perfil para REST e painel.
 *
 * @param int $vendor_id Vendor.
 * @return array{version:int,source:string,updatedAt:string,cnpjChanged:bool,profile:array<string,mixed>}
 */
function papelito_vendor_financial_profile_public( int $vendor_id ): array {
	$stored    = papelito_vendor_financial_profile_stored( $vendor_id );
	$data      = papelito_vendor_financial_profile_merge( papelito_vendor_financial_profile_empty_data(), is_array( $stored['data'] ?? null ) ? $stored['data'] : array() );
	$sensitive = is_array( $stored['sensitive'] ?? null ) ? $stored['sensitive'] : array();

	$data['managingPartners'][0]['hasDocument']   = '' !== (string) ( $sensitive['partnerDocument'] ?? '' );
	$data['managingPartners'][0]['documentLast4'] = (string) ( $sensitive['partnerDocumentLast4'] ?? '' );
	$data['managingPartners'][0]['hasBirthdate']  = '' !== (string) ( $sensitive['partnerBirthdate'] ?? '' );

	$stored_fingerprint = (string) ( $stored['cnpjFingerprint'] ?? '' );

	return array(
		'version'     => (int) ( $stored['version'] ?? 0 ),
		'source'      => (string) ( $stored['source'] ?? '' ),
		'updatedAt'   => (string) ( $stored['updatedAt'] ?? '' ),
		'cnpjChanged' => '' !== $stored_fingerprint && ! hash_equals( $stored_fingerprint, papelito_vendor_financial_profile_cnpj_fingerprint( $vendor_id ) ),
		'profile'     => $data,
	);
}
