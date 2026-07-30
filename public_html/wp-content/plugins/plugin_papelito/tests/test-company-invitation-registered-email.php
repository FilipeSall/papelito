<?php

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function papelito_company_authz_load(): array { return array( 'membership' => array() ); }
function papelito_company_authz_can_manage(): bool { return true; }
function papelito_company_assignable_roles(): array { return array( 'admin', 'buyer', 'viewer' ); }
function sanitize_email( string $email ): string { return strtolower( trim( $email ) ); }
function is_email( string $email ): bool { return false !== strpos( $email, '@' ); }
function papelito_company_invitation_find_pending_by_email(): ?array { return null; }
function papelito_company_invitation_create(): array { return array( 'id' => 77, 'token' => 'token' ); }
function papelito_company_audit(): void {}
function papelito_company_invitation_send_email(): void {}

$source = file_get_contents( __DIR__ . '/../includes/company_invitation_services.php' );
preg_match( '/function papelito_company_invitation_issue\(.*?\n}/s', $source, $matches );
eval( $matches[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

$result = papelito_company_invitation_issue( 1, 1, array( 'invited_email' => 'existing@papelito.test' ) );

if ( $result instanceof WP_Error || 77 !== $result['id'] ) {
	echo "FAIL: registered emails must be accepted for an invitation\n";
	exit( 1 );
}

echo "PASS: registered emails can receive an invitation\n";
