<?php
/**
 * Proteção de PII (CPF) e camada de acesso a wp_papelito_customer_profiles.
 *
 * - HMAC-SHA256 determinístico (PAPELITO_PII_LOOKUP_KEY) para busca/unicidade do CPF.
 * - Cifra reversível AES-256-GCM (PAPELITO_PII_ENCRYPTION_KEY) com envelope VERSIONADO:
 *       v<key_version>:<iv_b64>:<tag_b64>:<ciphertext_b64>
 *   IV de 12 bytes aleatório por operação, auth tag de 16 bytes. A versão permite rotação
 *   de chave (decriptar registros antigos com a chave anterior).
 * - Guardas: chave ausente/curta/inválida/"change-me" nunca resultam em gravação de PII em
 *   claro; retornam WP_Error. O valor sensível nunca é logado.
 *
 * Fase 0: infraestrutura pronta e testada, NÃO conectada a nenhum fluxo existente.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_CUSTOMER_PROFILES_TABLE' ) ) {
	define( 'PAPELITO_CUSTOMER_PROFILES_TABLE', 'papelito_customer_profiles' );
}

if ( ! defined( 'PAPELITO_PII_CIPHER' ) ) {
	define( 'PAPELITO_PII_CIPHER', 'aes-256-gcm' );
}

if ( ! defined( 'PAPELITO_PII_MIN_KEY_LENGTH' ) ) {
	define( 'PAPELITO_PII_MIN_KEY_LENGTH', 32 );
}

/**
 * Nome completo (com prefixo) da tabela de perfis de customer.
 */
function papelito_customer_profiles_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_CUSTOMER_PROFILES_TABLE;
}

/* --- Gestão de chaves --- */

/**
 * Recupera uma chave de PII validada a partir do ambiente.
 *
 * Rejeita ausência, valor placeholder "change-me" ou comprimento insuficiente. Nunca loga
 * o valor da chave.
 *
 * @return string|WP_Error
 */
function papelito_pii_get_key( string $env_key ) {
	$value = (string) papelito_env( $env_key, '' );

	if ( '' === $value ) {
		return new WP_Error(
			'papelito_pii_key_missing',
			sprintf( 'A chave de PII %s não está configurada.', $env_key ),
			array( 'env_key' => $env_key )
		);
	}

	if ( 'change-me' === strtolower( $value ) ) {
		return new WP_Error(
			'papelito_pii_key_placeholder',
			sprintf( 'A chave de PII %s ainda está com o valor placeholder.', $env_key ),
			array( 'env_key' => $env_key )
		);
	}

	if ( strlen( $value ) < PAPELITO_PII_MIN_KEY_LENGTH ) {
		return new WP_Error(
			'papelito_pii_key_too_short',
			sprintf( 'A chave de PII %s é curta demais (mínimo %d caracteres).', $env_key, PAPELITO_PII_MIN_KEY_LENGTH ),
			array( 'env_key' => $env_key )
		);
	}

	return $value;
}

/**
 * Versão corrente da chave de cifra (para o envelope). Default 1.
 */
function papelito_pii_current_key_version(): int {
	$version = (int) papelito_env( 'PAPELITO_PII_KEY_VERSION', '1' );

	return $version > 0 ? $version : 1;
}

/**
 * Resolve a chave de cifra para uma versão específica.
 *
 * A versão corrente vem de PAPELITO_PII_ENCRYPTION_KEY. Versões anteriores (para rotação)
 * vêm de PAPELITO_PII_ENCRYPTION_KEY_V<n>, mantidas apenas enquanto houver dados a migrar.
 *
 * @return string|WP_Error
 */
function papelito_pii_get_encryption_key_for_version( int $version ) {
	if ( papelito_pii_current_key_version() === $version ) {
		return papelito_pii_get_key( 'PAPELITO_PII_ENCRYPTION_KEY' );
	}

	return papelito_pii_get_key( 'PAPELITO_PII_ENCRYPTION_KEY_V' . $version );
}

/* --- HMAC determinístico (busca / unicidade) --- */

/**
 * Calcula o HMAC-SHA256 de um valor de PII para lookup/unicidade.
 *
 * @return string|WP_Error HMAC hexadecimal (64 chars) ou WP_Error se a chave estiver ausente.
 */
function papelito_pii_hmac( string $value ) {
	$key = papelito_pii_get_key( 'PAPELITO_PII_LOOKUP_KEY' );

	if ( is_wp_error( $key ) ) {
		return $key;
	}

	return hash_hmac( 'sha256', $value, $key );
}

/**
 * HMAC específico do CPF (normaliza antes de calcular).
 *
 * @return string|WP_Error
 */
function papelito_cpf_hmac( string $raw_cpf ) {
	$cpf = papelito_normalize_cpf( $raw_cpf );

	if ( '' === $cpf ) {
		return new WP_Error( 'papelito_pii_invalid_cpf', 'CPF inválido para HMAC.' );
	}

	return papelito_pii_hmac( $cpf );
}

/* --- Cifra reversível AES-256-GCM (envelope versionado) --- */

/**
 * Cifra um valor de PII, retornando o envelope versionado.
 *
 * Formato: v<key_version>:<iv_b64>:<tag_b64>:<ciphertext_b64>
 *
 * @return string|WP_Error
 */
function papelito_pii_encrypt( string $plaintext ) {
	$version = papelito_pii_current_key_version();
	$key     = papelito_pii_get_encryption_key_for_version( $version );

	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$iv_length = openssl_cipher_iv_length( PAPELITO_PII_CIPHER );
	if ( false === $iv_length || $iv_length <= 0 ) {
		return new WP_Error( 'papelito_pii_cipher_unavailable', 'Cipher de PII indisponível no ambiente.' );
	}

	$iv  = random_bytes( $iv_length );
	$tag = '';

	$ciphertext = openssl_encrypt(
		$plaintext,
		PAPELITO_PII_CIPHER,
		$key,
		OPENSSL_RAW_DATA,
		$iv,
		$tag,
		'',
		16
	);

	if ( false === $ciphertext ) {
		return new WP_Error( 'papelito_pii_encrypt_failed', 'Falha ao cifrar o dado de PII.' );
	}

	// base64 apenas para serializar bytes binários (IV/tag/ciphertext) no envelope textual — não é ofuscação.
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	return sprintf( 'v%d:%s:%s:%s', $version, base64_encode( $iv ), base64_encode( $tag ), base64_encode( $ciphertext ) );
}

/**
 * Decifra um envelope versionado de PII.
 *
 * Seleciona a chave pela versão embutida no envelope (suporta rotação). Falha se a tag de
 * autenticação não conferir (tampering) ou se o envelope estiver malformado.
 *
 * @return string|WP_Error
 */
function papelito_pii_decrypt( string $envelope ) {
	$parts = explode( ':', $envelope, 4 );

	if ( 4 !== count( $parts ) || 'v' !== substr( $parts[0], 0, 1 ) ) {
		return new WP_Error( 'papelito_pii_envelope_malformed', 'Envelope de PII malformado.' );
	}

	$version = (int) substr( $parts[0], 1 );
	if ( $version <= 0 ) {
		return new WP_Error( 'papelito_pii_envelope_version', 'Versão de chave inválida no envelope.' );
	}

	$key = papelito_pii_get_encryption_key_for_version( $version );
	if ( is_wp_error( $key ) ) {
		return $key;
	}

	// base64 apenas para desserializar bytes binários do envelope textual — não é ofuscação.
	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$iv         = base64_decode( $parts[1], true );
	$tag        = base64_decode( $parts[2], true );
	$ciphertext = base64_decode( $parts[3], true );
	// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

	if ( false === $iv || false === $tag || false === $ciphertext ) {
		return new WP_Error( 'papelito_pii_envelope_base64', 'Envelope de PII com base64 inválido.' );
	}

	$plaintext = openssl_decrypt(
		$ciphertext,
		PAPELITO_PII_CIPHER,
		$key,
		OPENSSL_RAW_DATA,
		$iv,
		$tag
	);

	if ( false === $plaintext ) {
		return new WP_Error( 'papelito_pii_decrypt_failed', 'Falha ao decifrar o dado de PII (tag inválida ou chave incorreta).' );
	}

	return $plaintext;
}

/**
 * Extrai os quatro últimos dígitos do CPF para exibição mascarada.
 */
function papelito_cpf_last4( string $raw_cpf ): string {
	$cpf = papelito_normalize_cpf( $raw_cpf );

	return '' === $cpf ? '' : substr( $cpf, -4 );
}

/* --- Repository de wp_papelito_customer_profiles --- */

/**
 * Cria ou atualiza o perfil de customer com CPF protegido.
 *
 * Só grava se HMAC e cifra do CPF tiverem sucesso — nunca persiste CPF em claro.
 *
 * @param array<string,mixed> $fields identity_status, identity_method, identity_checked_at.
 * @return true|WP_Error
 */
function papelito_customer_profile_upsert( int $user_id, string $raw_cpf, array $fields = array() ) {
	global $wpdb;

	if ( ! papelito_validate_cpf( $raw_cpf ) ) {
		return new WP_Error( 'papelito_pii_invalid_cpf', 'CPF inválido.' );
	}

	$hmac = papelito_cpf_hmac( $raw_cpf );
	if ( is_wp_error( $hmac ) ) {
		return $hmac;
	}

	$cipher = papelito_pii_encrypt( papelito_normalize_cpf( $raw_cpf ) );
	if ( is_wp_error( $cipher ) ) {
		return $cipher;
	}

	$table = papelito_customer_profiles_table_name();
	$now   = current_time( 'mysql', true );

	$data = array(
		'user_id'         => $user_id,
		'cpf_hmac'        => $hmac,
		'cpf_ciphertext'  => $cipher,
		'cpf_last4'       => papelito_cpf_last4( $raw_cpf ),
		'identity_status' => isset( $fields['identity_status'] ) ? (string) $fields['identity_status'] : 'pending',
		'identity_method' => isset( $fields['identity_method'] ) ? (string) $fields['identity_method'] : null,
		'updated_at'      => $now,
	);

	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( null === $existing ) {
		$data['created_at'] = $now;
		$result             = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} else {
		$result = $wpdb->update( $table, $data, array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	if ( false === $result ) {
		return new WP_Error( 'papelito_pii_persist_failed', 'Falha ao persistir o perfil de customer.' );
	}

	return true;
}

/**
 * Remove o perfil protegido de customer associado ao usuário.
 */
function papelito_customer_profile_delete( int $user_id ): bool {
	global $wpdb;

	return false !== $wpdb->delete(
		papelito_customer_profiles_table_name(),
		array( 'user_id' => $user_id ),
		array( '%d' )
	);
}

if ( function_exists( 'add_action' ) ) {
	add_action(
		'delete_user',
		static function ( int $user_id ): void {
			papelito_customer_profile_delete( $user_id );
		},
		10,
		1
	);
}

/**
 * Localiza o user_id de um perfil pelo CPF (via HMAC), sem expor o CPF.
 *
 * @return int|null|WP_Error
 */
function papelito_customer_profile_find_user_by_cpf( string $raw_cpf ) {
	global $wpdb;

	$hmac = papelito_cpf_hmac( $raw_cpf );
	if ( is_wp_error( $hmac ) ) {
		return $hmac;
	}

	$table   = papelito_customer_profiles_table_name();
	$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE cpf_hmac = %s", $hmac ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	return null === $user_id ? null : (int) $user_id;
}

/**
 * Recupera o CPF em claro de um perfil (uso restrito e auditável).
 *
 * @return string|null|WP_Error
 */
function papelito_customer_profile_get_cpf( int $user_id ) {
	global $wpdb;

	$table  = papelito_customer_profiles_table_name();
	$cipher = $wpdb->get_var( $wpdb->prepare( "SELECT cpf_ciphertext FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( null === $cipher ) {
		return null;
	}

	return papelito_pii_decrypt( (string) $cipher );
}

/**
 * Barra CPF invalido antes de virar `wp_usermeta`.
 *
 * A escrita do perfil passa pela API de customer do WooCommerce, fora dos endpoints Papelito, e
 * chegava sem nenhuma verificacao — `111.111.111-11` era aceito e persistido. Como o modelo B2B
 * inteiro apoia identidade em CPF, a guarda fica no ponto de escrita para valer em qualquer
 * caminho, nao so no que a UI usa hoje.
 *
 * @param mixed  $check      Curto-circuito da metadata API (null segue o fluxo normal).
 * @param int    $object_id  Usuario.
 * @param string $meta_key   Chave.
 * @param mixed  $meta_value Valor.
 * @return mixed
 */
function papelito_reject_invalid_cpf_usermeta( $check, $object_id, $meta_key, $meta_value ) {
	if ( 'cpf' !== $meta_key || null !== $check ) {
		return $check;
	}

	$raw = is_scalar( $meta_value ) ? (string) $meta_value : '';

	if ( '' === trim( $raw ) ) {
		return $check;
	}

	if ( function_exists( 'papelito_validate_cpf' ) && ! papelito_validate_cpf( $raw ) ) {
		return false;
	}

	return $check;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'update_user_metadata', 'papelito_reject_invalid_cpf_usermeta', 10, 4 );
	add_filter( 'add_user_metadata', 'papelito_reject_invalid_cpf_usermeta', 10, 4 );
}
