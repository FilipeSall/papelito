<?php
/**
 * Cofre das credenciais de transportadora do vendor, separado do cofre de PII.
 *
 * A credencial Braspress de um seller e o CPF de um comprador são segredos de
 * domínios diferentes, com donos, ciclos de vida e consequências de vazamento
 * diferentes. Compartilhar chave junta os dois riscos: quem obtivesse a chave de
 * transporte leria PII, e rotacionar PII derrubaria toda cotação do marketplace.
 * Este módulo dá à credencial de transporte chave própria, sem mudar a
 * construção criptográfica já validada no `customer_identity.php` — AES-256-GCM,
 * autenticada, com envelope versionado.
 *
 * A chave é resolvida em duas fontes, nesta ordem. A explícita
 * (`PAPELITO_VENDOR_SECRET_KEY`) é a que operação provisiona quando quer raiz
 * independente. Sem ela, a chave é **derivada** da chave de PII por HMAC com
 * rótulo de domínio: material distinto, impossível de reverter para a chave de
 * PII e incapaz de abrir envelope do outro cofre. A derivação existe porque em
 * produção o `wp-config.php` é editado à mão e variável nova não chega junto com
 * o deploy — falhar fechado ali deixaria todo vendor sem conseguir salvar
 * credencial até alguém entrar no servidor.
 *
 * O envelope legado, escrito quando o segredo do vendor ainda usava a chave de
 * PII, continua sendo lido pelo prefixo `v`. Recusá-lo apagaria em silêncio a
 * integração de todo vendor já cadastrado.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_SECRET_CIPHER     = 'aes-256-gcm';
const PAPELITO_VENDOR_SECRET_PREFIX     = 'k';
const PAPELITO_VENDOR_SECRET_LEGACY     = 'v';
const PAPELITO_VENDOR_SECRET_TAG_LENGTH = 16;

/**
 * Rótulo de domínio da derivação.
 *
 * Trocar esta string invalida todos os envelopes derivados existentes, então ela
 * é parte do formato, não um detalhe interno.
 */
const PAPELITO_VENDOR_SECRET_DOMAIN = 'papelito:vendor-integration-secret:v1';

/**
 * Versão corrente da chave do cofre de integração.
 *
 * @return int Versão positiva; entrada inválida cai em 1.
 */
function papelito_vendor_secret_key_version(): int {
	$declared = (int) papelito_env( 'PAPELITO_VENDOR_SECRET_KEY_VERSION', '0' );
	if ( $declared > 0 ) {
		return $declared;
	}

	return function_exists( 'papelito_pii_current_key_version' ) ? papelito_pii_current_key_version() : 1;
}

/**
 * Nome da variável que guarda a chave explícita de uma versão.
 *
 * @param int    $version Versão da chave.
 * @param string $base Prefixo da variável.
 * @return string Nome da variável de ambiente.
 */
function papelito_vendor_secret_env_name( int $version, string $base ): string {
	return 1 === $version ? $base : $base . '_V' . $version;
}

/**
 * Deriva material de chave próprio a partir da chave de PII.
 *
 * HMAC-SHA256 com rótulo de domínio: o resultado não permite recuperar a chave
 * de PII e não abre envelope do outro cofre. A saída é binária de 32 bytes,
 * exatamente o tamanho que o AES-256 pede.
 *
 * @param string $root Chave de PII já validada.
 * @return string Chave derivada de 32 bytes.
 */
function papelito_vendor_secret_derive( string $root ): string {
	return hash_hmac( 'sha256', PAPELITO_VENDOR_SECRET_DOMAIN, $root, true );
}

/**
 * Resolve a chave do cofre de integração para uma versão.
 *
 * @param int $version Versão da chave.
 * @return string|WP_Error Chave utilizável ou falha de configuração.
 */
function papelito_vendor_secret_key_for_version( int $version ) {
	$explicit = papelito_env( papelito_vendor_secret_env_name( $version, 'PAPELITO_VENDOR_SECRET_KEY' ), '' );
	if ( '' !== $explicit ) {
		return $explicit;
	}

	if ( ! function_exists( 'papelito_pii_get_encryption_key_for_version' ) ) {
		return new WP_Error( 'papelito_vendor_secret_key_missing', 'O cofre de credenciais do vendor não está configurado.' );
	}

	$root = papelito_pii_get_encryption_key_for_version( $version );
	if ( is_wp_error( $root ) ) {
		return new WP_Error( 'papelito_vendor_secret_key_missing', 'O cofre de credenciais do vendor não está configurado.' );
	}

	return papelito_vendor_secret_derive( (string) $root );
}

/**
 * Cifra um segredo de integração do vendor com a chave corrente do cofre.
 *
 * @param string $plaintext Segredo a proteger.
 * @return string|WP_Error Envelope `k<versão>:iv:tag:ciphertext` ou falha.
 */
function papelito_vendor_secret_encrypt( string $plaintext ) {
	$version = papelito_vendor_secret_key_version();
	$key     = papelito_vendor_secret_key_for_version( $version );
	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$iv_length = openssl_cipher_iv_length( PAPELITO_VENDOR_SECRET_CIPHER );
	if ( false === $iv_length || $iv_length <= 0 ) {
		return new WP_Error( 'papelito_vendor_secret_cipher_unavailable', 'Cifra indisponível no ambiente.' );
	}

	$iv  = random_bytes( $iv_length );
	$tag = '';

	$ciphertext = openssl_encrypt(
		$plaintext,
		PAPELITO_VENDOR_SECRET_CIPHER,
		$key,
		OPENSSL_RAW_DATA,
		$iv,
		$tag,
		'',
		PAPELITO_VENDOR_SECRET_TAG_LENGTH
	);

	if ( false === $ciphertext ) {
		return new WP_Error( 'papelito_vendor_secret_encrypt_failed', 'Não foi possível proteger a credencial.' );
	}

	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- serializa bytes binários no envelope textual.
	return sprintf(
		'%s%d:%s:%s:%s',
		PAPELITO_VENDOR_SECRET_PREFIX,
		$version,
		base64_encode( $iv ),
		base64_encode( $tag ),
		base64_encode( $ciphertext )
	);
	// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Lê a versão declarada por um envelope deste cofre.
 *
 * @param string $envelope Envelope textual.
 * @return int Versão positiva, ou zero quando o envelope não é deste cofre.
 */
function papelito_vendor_secret_envelope_version( string $envelope ): int {
	if ( ! str_starts_with( $envelope, PAPELITO_VENDOR_SECRET_PREFIX ) ) {
		return 0;
	}

	$parts = explode( ':', $envelope, 4 );
	if ( 4 !== count( $parts ) ) {
		return 0;
	}

	$version = (int) substr( $parts[0], strlen( PAPELITO_VENDOR_SECRET_PREFIX ) );

	return $version > 0 ? $version : 0;
}

/**
 * Decifra um envelope deste cofre ou delega o envelope legado ao cofre de PII.
 *
 * @param string $envelope Envelope persistido na integração.
 * @return string|WP_Error Segredo em claro ou falha.
 */
function papelito_vendor_secret_decrypt( string $envelope ) {
	if ( str_starts_with( $envelope, PAPELITO_VENDOR_SECRET_LEGACY ) && function_exists( 'papelito_pii_decrypt' ) ) {
		return papelito_pii_decrypt( $envelope );
	}

	$version = papelito_vendor_secret_envelope_version( $envelope );
	if ( $version <= 0 ) {
		return new WP_Error( 'papelito_vendor_secret_envelope_malformed', 'Envelope de credencial malformado.' );
	}

	$key = papelito_vendor_secret_key_for_version( $version );
	if ( is_wp_error( $key ) ) {
		return $key;
	}

	return papelito_vendor_secret_open( $envelope, (string) $key );
}

/**
 * Abre um envelope já roteado, com a chave já resolvida.
 *
 * @param string $envelope Envelope textual deste cofre.
 * @param string $key Chave da versão declarada no envelope.
 * @return string|WP_Error Segredo em claro ou falha.
 */
function papelito_vendor_secret_open( string $envelope, string $key ) {
	$parts = explode( ':', $envelope, 4 );

	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- desserializa bytes binários do envelope textual.
	$iv         = base64_decode( $parts[1], true );
	$tag        = base64_decode( $parts[2], true );
	$ciphertext = base64_decode( $parts[3], true );
	// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

	if ( false === $iv || false === $tag || false === $ciphertext ) {
		return new WP_Error( 'papelito_vendor_secret_envelope_base64', 'Envelope de credencial com base64 inválido.' );
	}

	$plaintext = openssl_decrypt(
		$ciphertext,
		PAPELITO_VENDOR_SECRET_CIPHER,
		$key,
		OPENSSL_RAW_DATA,
		$iv,
		$tag
	);

	if ( false === $plaintext ) {
		return new WP_Error( 'papelito_vendor_secret_decrypt_failed', 'Não foi possível abrir a credencial protegida.' );
	}

	return $plaintext;
}
