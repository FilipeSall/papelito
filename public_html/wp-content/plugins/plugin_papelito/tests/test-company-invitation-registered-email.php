<?php

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function papelito_company_authz_load(): array { return array( 'membership' => array() ); }
function papelito_company_authz_can_manage(): bool { return true; }
function sanitize_email( string $email ): string { return strtolower( trim( $email ) ); }
function is_email( string $email ): bool { return false !== strpos( $email, '@' ); }
function email_exists( string $email ): int|false { return 'existing@papelito.test' === $email ? 1 : false; }

$source = file_get_contents( __DIR__ . '/../includes/company_invitation_services.php' );
preg_match( '/function papelito_company_invitation_issue\(.*?\n}/s', $source, $matches );
eval( $matches[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

$result = papelito_company_invitation_issue( 1, 1, array( 'invited_email' => 'existing@papelito.test' ) );

if ( ! $result instanceof WP_Error || 'papelito_b2b_invitation_email_registered' !== $result->code ) {
	echo "FAIL: registered emails must be rejected before invitation creation\n";
	exit( 1 );
}

echo "PASS: registered emails are rejected before invitation creation\n";
