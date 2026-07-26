<?php
/**
 * Resumable-onboarding contract tests.
 *
 * The B2B completion form (/cadastro/completar) rehydrates from this view. It must never leak
 * decrypted PII: CPF and birth date stay ciphered at rest and are retyped by the user.
 */

define( 'ABSPATH', __DIR__ );

$source = file_get_contents( __DIR__ . '/../includes/company_services.php' );
if ( false === $source || ! preg_match( '/function papelito_company_onboarding_resume_view.*?\n}/s', $source, $match ) ) {
	echo "FAIL: could not isolate papelito_company_onboarding_resume_view\n";
	exit( 1 );
}
eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

$failures = 0;
function assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

$google_fresh = papelito_company_onboarding_resume_view(
	array(
		'onboarding_type' => 'google_onboarding',
		'target_cnpj'     => null,
		'expires_at'      => '2026-08-23 00:00:00',
	),
	null
);

assert_same( 'google onboarding exposes its type', 'google_onboarding', $google_fresh['type'] );
assert_same( 'fresh google onboarding has no target cnpj', null, $google_fresh['targetCnpj'] );
assert_same( 'fresh google onboarding has no cpf last4', null, $google_fresh['cpfLast4'] );
assert_same( 'fresh google onboarding has no birth date', false, $google_fresh['hasBirthDate'] );
assert_same( 'onboarding ttl is exposed for resume', '2026-08-23 00:00:00', $google_fresh['expiresAt'] );

$partially_filled = papelito_company_onboarding_resume_view(
	array(
		'onboarding_type' => 'create_company',
		'target_cnpj'     => '12345678000195',
		'expires_at'      => '2026-08-23 00:00:00',
	),
	array(
		'cpf_last4'             => '0912',
		'cpf_ciphertext'        => 'v1:cipher',
		'cpf_hmac'              => 'hmac',
		'birth_date_ciphertext' => 'v1:cipher',
	)
);

assert_same( 'saved cnpj is restored for resume', '12345678000195', $partially_filled['targetCnpj'] );
assert_same( 'cpf last4 is restored as a hint', '0912', $partially_filled['cpfLast4'] );
assert_same( 'birth date presence is flagged', true, $partially_filled['hasBirthDate'] );

// PII invariant: the view must never carry decryptable material, however the row is shaped.
$leaks = array_intersect(
	array_keys( $partially_filled ),
	array( 'cpf', 'cpfCiphertext', 'cpf_ciphertext', 'cpfHmac', 'cpf_hmac', 'birthDate', 'birth_date', 'birth_date_ciphertext' )
);
assert_same( 'resume view leaks no cpf or birth date material', array(), $leaks );
assert_same( 'resume view exposes exactly the agreed keys', array( 'type', 'targetCnpj', 'cpfLast4', 'hasBirthDate', 'expiresAt' ), array_keys( $partially_filled ) );

$empty_cnpj = papelito_company_onboarding_resume_view(
	array( 'onboarding_type' => 'join_company', 'target_cnpj' => '', 'expires_at' => '' ),
	array( 'cpf_last4' => '' )
);
assert_same( 'empty target cnpj normalizes to null', null, $empty_cnpj['targetCnpj'] );
assert_same( 'empty cpf last4 normalizes to null', null, $empty_cnpj['cpfLast4'] );
assert_same( 'empty expiry normalizes to null', null, $empty_cnpj['expiresAt'] );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
