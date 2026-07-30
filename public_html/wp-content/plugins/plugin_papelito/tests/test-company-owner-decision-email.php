<?php
/**
 * The internal rejection reason must never be included in the customer email.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'PAPELITO_NOTIF_COMPANY_OWNER_APPROVED', 'company_owner_approved' );
define( 'PAPELITO_NOTIF_COMPANY_OWNER_REJECTED', 'company_owner_rejected' );

class WP_User {
	public function __construct( public int $ID, public string $user_email ) {}
}

$sent_email = array();
function get_userdata( int $user_id ): WP_User { return new WP_User( $user_id, 'cliente@example.test' ); }
function is_email( string $email ): bool { return true; }
function papelito_claim_notification_email_dispatch(): bool { return true; }
function wp_mail( string $to, string $subject, string $body, array $headers ): bool {
	global $sent_email;
	$sent_email = compact( 'to', 'subject', 'body', 'headers' );
	return true;
}

$source = file_get_contents( __DIR__ . '/../includes/company_owner_applications.php' );
if ( ! preg_match( '/function papelito_company_owner_application_send_decision_email\(.*?\n}/s', $source, $match ) ) {
	echo "FAIL: could not isolate decision email function\n";
	exit( 1 );
}
eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

papelito_company_owner_application_send_decision_email(
	array(
		'id'                 => 91,
		'user_id'            => 7,
		'application_status' => 'rejected',
		'rejection_reason'   => 'MOTIVO INTERNO CONFIDENCIAL',
	)
);

if ( str_contains( (string) $sent_email['body'], 'MOTIVO INTERNO CONFIDENCIAL' ) ) {
	echo "FAIL: internal reason leaked into customer email\n";
	exit( 1 );
}
if ( ! str_contains( (string) $sent_email['body'], 'encontramos divergências nos dados analisados' ) ) {
	echo "FAIL: standardized rejection message missing\n";
	exit( 1 );
}

echo "PASS: rejection email is standardized and does not leak the internal reason\n";
