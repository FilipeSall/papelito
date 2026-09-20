<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Cofre das credenciais de transportadora do vendor, com chave própria.
 *
 * Até aqui a credencial Braspress do vendor era cifrada com a mesma chave que
 * protege CPF, data de nascimento, razão social e e-mail de candidatura. Uma
 * chave só para as duas coisas significa que comprometer o cofre de transporte
 * expõe PII de comprador, e que rotacionar PII derruba toda cotação.
 *
 * Fixa a separação de verdade — o envelope do vendor **não** abre com a chave de
 * PII — sem quebrar quem já está cadastrado: envelope legado continua sendo
 * lido, porque falhar nele apagaria silenciosamente a integração de todo vendor
 * configurado.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_PII_CIPHER', 'aes-256-gcm' );

const VAULT_TEST_PII_KEY       = 'chave-de-pii-do-teste-com-tamanho-suficiente-1234';
const VAULT_TEST_PII_KEY_V2    = 'chave-de-pii-v2-do-teste-com-tamanho-suficiente-9';
const VAULT_TEST_SECRET        = '{"username":"usuario-sintetico","password":"senha-sintetica"}';
const VAULT_TEST_LEGACY_PLAIN  = '{"username":"legado","password":"legado"}';
const VAULT_TEST_LEGACY_ENVELOPE = 'v1:aWl2:dGFn:Y2lwaGVy';

$GLOBALS['vault_env'] = array();

/** Erro WordPress mínimo. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

/** Reconhece o erro produzido pelo módulo real. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

/** Ambiente controlado pela fixture. */
function papelito_env( string $name, string $fallback = '' ): string {
	return (string) ( $GLOBALS['vault_env'][ $name ] ?? $fallback );
}

/** Reaproveita a validação de chave do cofre de PII, com o mesmo contrato. */
function papelito_pii_get_key( string $env_key ) {
	$value = papelito_env( $env_key, '' );
	if ( '' === $value ) {
		return new WP_Error( 'papelito_pii_key_missing', 'Chave ausente.', array( 'env_key' => $env_key ) );
	}

	return $value;
}

/** Versão corrente do cofre de PII, como a produção resolve. */
function papelito_pii_current_key_version(): int {
	$version = (int) papelito_env( 'PAPELITO_PII_KEY_VERSION', '1' );

	return $version > 0 ? $version : 1;
}

/** Resolução de chave por versão idêntica à da produção: a corrente não tem sufixo. */
function papelito_pii_get_encryption_key_for_version( int $version ) {
	if ( papelito_pii_current_key_version() === $version ) {
		return papelito_pii_get_key( 'PAPELITO_PII_ENCRYPTION_KEY' );
	}

	return papelito_pii_get_key( 'PAPELITO_PII_ENCRYPTION_KEY_V' . $version );
}

/** Só o envelope legado da fixture decifra pelo caminho antigo. */
function papelito_pii_decrypt( string $envelope ) {
	return VAULT_TEST_LEGACY_ENVELOPE === $envelope
		? VAULT_TEST_LEGACY_PLAIN
		: new WP_Error( 'papelito_pii_decrypt_failed', 'Falha ao decifrar.' );
}

require_once dirname( __DIR__ ) . '/includes/vendor_secrets.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function vault_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Recria o ambiente com apenas a chave de PII provisionada. */
function vault_reset( array $env = array() ): void {
	$GLOBALS['vault_env'] = array_merge( array( 'PAPELITO_PII_ENCRYPTION_KEY' => VAULT_TEST_PII_KEY ), $env );
}

/** Decifra um envelope com uma chave crua, para provar separação de chaves. */
function vault_opens_with( string $envelope, string $key ): bool {
	$parts = explode( ':', $envelope, 4 );
	if ( 4 !== count( $parts ) ) {
		return false;
	}

	$plain = openssl_decrypt(
		base64_decode( $parts[3], true ),
		PAPELITO_PII_CIPHER,
		$key,
		OPENSSL_RAW_DATA,
		base64_decode( $parts[1], true ),
		base64_decode( $parts[2], true )
	);

	return false !== $plain;
}

echo "Cenário 1: o segredo do vendor vai e volta pelo cofre próprio\n";
vault_reset();

$envelope = papelito_vendor_secret_encrypt( VAULT_TEST_SECRET );

vault_assert( 'A cifragem devolve envelope, não erro', is_string( $envelope ) );
vault_assert( 'O envelope se identifica como cofre de integração', is_string( $envelope ) && str_starts_with( $envelope, 'k1:' ) );
vault_assert( 'O segredo volta íntegro', VAULT_TEST_SECRET === papelito_vendor_secret_decrypt( (string) $envelope ) );
vault_assert( 'O texto claro não aparece no envelope', is_string( $envelope ) && ! str_contains( $envelope, 'senha-sintetica' ) );

echo "\nCenário 2: o envelope do vendor não abre com a chave de PII\n";
vault_reset();

$envelope = (string) papelito_vendor_secret_encrypt( VAULT_TEST_SECRET );

vault_assert(
	'A chave de PII não decifra a credencial da transportadora',
	! vault_opens_with( $envelope, VAULT_TEST_PII_KEY )
);
vault_assert(
	'A chave derivada não é a chave de PII',
	VAULT_TEST_PII_KEY !== papelito_vendor_secret_key_for_version( 1 )
);

echo "\nCenário 3: envelope legado continua sendo lido\n";
vault_reset();

vault_assert(
	'Vendor já cadastrado não perde a credencial',
	VAULT_TEST_LEGACY_PLAIN === papelito_vendor_secret_decrypt( VAULT_TEST_LEGACY_ENVELOPE )
);

echo "\nCenário 4: o cofre não pede variável de ambiente própria\n";
vault_reset();

vault_assert(
	'A chave sai da raiz de PII já provisionada, sem segredo novo para operar',
	is_string( papelito_vendor_secret_key_for_version( 1 ) )
);
vault_assert(
	'Nenhuma variável exclusiva do cofre é consultada',
	array( 'PAPELITO_PII_ENCRYPTION_KEY' ) === array_keys( $GLOBALS['vault_env'] )
	&& is_string( papelito_vendor_secret_encrypt( VAULT_TEST_SECRET ) )
);

echo "\nCenário 5: rotação preserva o envelope da versão anterior\n";
vault_reset(
	array(
		'PAPELITO_PII_KEY_VERSION'       => '2',
		'PAPELITO_PII_ENCRYPTION_KEY_V1' => VAULT_TEST_PII_KEY,
		'PAPELITO_PII_ENCRYPTION_KEY'    => VAULT_TEST_PII_KEY_V2,
	)
);

$rotated = (string) papelito_vendor_secret_encrypt( VAULT_TEST_SECRET );

vault_assert( 'O envelope novo nasce na versão corrente', str_starts_with( $rotated, 'k2:' ) );
vault_assert( 'O envelope novo volta íntegro', VAULT_TEST_SECRET === papelito_vendor_secret_decrypt( $rotated ) );
vault_assert(
	'A versão anterior continua legível enquanto a chave antiga existir',
	VAULT_TEST_SECRET === papelito_vendor_secret_decrypt( $envelope )
);

echo "\nCenário 6: sem chave nenhuma o cofre falha fechado\n";
$GLOBALS['vault_env'] = array();

vault_assert( 'Cifrar sem chave devolve erro', is_wp_error( papelito_vendor_secret_encrypt( VAULT_TEST_SECRET ) ) );
vault_assert( 'Decifrar sem chave devolve erro', is_wp_error( papelito_vendor_secret_decrypt( $envelope ) ) );

echo "\nCenário 7: envelope adulterado não decifra\n";
vault_reset();

$sound   = (string) papelito_vendor_secret_encrypt( VAULT_TEST_SECRET );
$parts   = explode( ':', $sound, 4 );
$tampered = $parts[0] . ':' . $parts[1] . ':' . $parts[2] . ':' . base64_encode( 'conteudo-trocado' );

vault_assert( 'A tag autenticada recusa o conteúdo trocado', is_wp_error( papelito_vendor_secret_decrypt( $tampered ) ) );
vault_assert( 'Envelope malformado é recusado', is_wp_error( papelito_vendor_secret_decrypt( 'k1:sem-partes' ) ) );
vault_assert( 'Prefixo desconhecido é recusado', is_wp_error( papelito_vendor_secret_decrypt( 'x1:a:b:c' ) ) );

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
