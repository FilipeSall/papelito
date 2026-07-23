<?php
/**
 * Standalone regression test for PII crypto/HMAC (envelope, rotation, tampering, guards).
 *
 * Exercises only the crypto/key layer of customer_identity.php (no $wpdb needed).
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;
	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }

$papelito_test_env = array();
function papelito_env( string $key, $default = null ) {
	global $papelito_test_env;
	return array_key_exists( $key, $papelito_test_env ) ? $papelito_test_env[ $key ] : $default;
}

require __DIR__ . '/../includes/cnpj_validation.php';
require __DIR__ . '/../includes/customer_identity.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}
function papelito_assert_true( string $label, mixed $actual ): void {
	papelito_assert_same( $label, true, (bool) $actual );
}

/* Keys for v1. */
$papelito_test_env = array(
	'PAPELITO_PII_LOOKUP_KEY'     => str_repeat( 'a', 48 ),
	'PAPELITO_PII_ENCRYPTION_KEY' => str_repeat( 'b', 48 ),
	'PAPELITO_PII_KEY_VERSION'    => '1',
);

/* ---- HMAC ---- */
$h1 = papelito_pii_hmac( '52998224725' );
$h2 = papelito_pii_hmac( '52998224725' );
papelito_assert_true( 'HMAC is a string', is_string( $h1 ) );
papelito_assert_same( 'HMAC is deterministic', $h1, $h2 );
papelito_assert_same( 'HMAC length 64', 64, strlen( $h1 ) );
papelito_assert_true( 'HMAC differs for different CPF', $h1 !== papelito_pii_hmac( '11144477735' ) );

/* ---- Encrypt / decrypt round-trip ---- */
$env = papelito_pii_encrypt( '52998224725' );
papelito_assert_true( 'encrypt returns versioned envelope', is_string( $env ) && str_starts_with( $env, 'v1:' ) );
papelito_assert_same( 'decrypt round-trips', '52998224725', papelito_pii_decrypt( $env ) );

/* ---- IV uniqueness: same plaintext → different envelopes ---- */
$env_a = papelito_pii_encrypt( 'same-value' );
$env_b = papelito_pii_encrypt( 'same-value' );
papelito_assert_true( 'IV unique per operation (envelopes differ)', $env_a !== $env_b );
papelito_assert_same( 'both envelopes decrypt equal', papelito_pii_decrypt( $env_a ), papelito_pii_decrypt( $env_b ) );

/* ---- Tampering: flipping the ciphertext breaks the GCM tag ---- */
$parts        = explode( ':', $env, 4 );
$ct           = base64_decode( $parts[3], true );
$ct[0]        = $ct[0] ^ "\x01";
$tampered     = $parts[0] . ':' . $parts[1] . ':' . $parts[2] . ':' . base64_encode( $ct );
papelito_assert_true( 'tampered ciphertext fails to decrypt', is_wp_error( papelito_pii_decrypt( $tampered ) ) );

/* ---- Malformed envelope ---- */
papelito_assert_true( 'malformed envelope rejected', is_wp_error( papelito_pii_decrypt( 'not-an-envelope' ) ) );

/* ---- Key rotation: v2 current, v1 kept → both decrypt ---- */
$env_v1 = $env; // encrypted under v1
$papelito_test_env['PAPELITO_PII_KEY_VERSION']       = '2';
$papelito_test_env['PAPELITO_PII_ENCRYPTION_KEY']    = str_repeat( 'c', 48 ); // v2 (current)
$papelito_test_env['PAPELITO_PII_ENCRYPTION_KEY_V1'] = str_repeat( 'b', 48 ); // v1 kept for rotation
$env_v2 = papelito_pii_encrypt( '52998224725' );
papelito_assert_true( 'v2 envelope tagged v2', str_starts_with( (string) $env_v2, 'v2:' ) );
papelito_assert_same( 'v2 decrypts under current key', '52998224725', papelito_pii_decrypt( $env_v2 ) );
papelito_assert_same( 'v1 still decrypts after rotation', '52998224725', papelito_pii_decrypt( $env_v1 ) );

/* ---- Guards: missing / placeholder / short keys ---- */
$papelito_test_env = array(); // no keys
papelito_assert_true( 'missing lookup key → WP_Error', is_wp_error( papelito_pii_hmac( 'x' ) ) );
papelito_assert_true( 'missing enc key → WP_Error', is_wp_error( papelito_pii_encrypt( 'x' ) ) );

$papelito_test_env = array(
	'PAPELITO_PII_LOOKUP_KEY'     => 'change-me',
	'PAPELITO_PII_ENCRYPTION_KEY' => 'change-me',
);
papelito_assert_true( 'placeholder lookup key → WP_Error', is_wp_error( papelito_pii_hmac( 'x' ) ) );
papelito_assert_true( 'placeholder enc key → WP_Error', is_wp_error( papelito_pii_encrypt( 'x' ) ) );

$papelito_test_env = array(
	'PAPELITO_PII_LOOKUP_KEY'     => 'short',
	'PAPELITO_PII_ENCRYPTION_KEY' => 'short',
);
papelito_assert_true( 'short lookup key → WP_Error', is_wp_error( papelito_pii_hmac( 'x' ) ) );
papelito_assert_true( 'short enc key → WP_Error', is_wp_error( papelito_pii_encrypt( 'x' ) ) );

/* ---- last4 ---- */
papelito_assert_same( 'cpf last4', '4725', papelito_cpf_last4( '529.982.247-25' ) );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
