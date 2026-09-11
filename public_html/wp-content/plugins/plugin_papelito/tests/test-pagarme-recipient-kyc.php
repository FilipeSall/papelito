<?php
/**
 * Regressao do estado de KYC de recebedor Pagar.me.
 *
 * Usage: php tests/test-pagarme-recipient-kyc.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PAPELITO_EMAIL_FONT_STACK', 'sans-serif' );
define( 'PAPELITO_EMAIL_INK', '#000000' );
define( 'PAPELITO_EMAIL_TEXT_SOFT', '#333333' );

$GLOBALS['papelito_kyc_usermeta'] = array();
$GLOBALS['papelito_kyc_email']    = array();
$GLOBALS['papelito_kyc_email_calls'] = 0;
$GLOBALS['papelito_kyc_limits']   = array();
$GLOBALS['papelito_kyc_now']      = 0;
$GLOBALS['papelito_kyc_post_calls'] = 0;

function add_action( ...$args ) {}
function register_rest_route( ...$args ) {}
function get_user_meta( $user_id, $key, $single = false ) { return $GLOBALS['papelito_kyc_usermeta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['papelito_kyc_usermeta'][ $user_id ][ $key ] = $value; return true; }
function delete_user_meta( $user_id, $key ) { unset( $GLOBALS['papelito_kyc_usermeta'][ $user_id ][ $key ] ); return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ); }
function esc_url_raw( $value ) { return (string) $value; }
function esc_attr( $value ) { return (string) $value; }
function esc_html( $value ) { return (string) $value; }
function is_email( $value ) { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function do_action( ...$args ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function papelito_pagarme_request( $method, $path, $body = null ) {
	if ( 'GET' === $method ) {
		return array(
			'id'          => 'rp_123',
			'status'      => 'affiliation',
			'kyc_details' => array( 'status' => 'partially_denied', 'status_reason' => 'additional_documents_required' ),
		);
	}

	$GLOBALS['papelito_kyc_post_calls']++;
	return array( 'url' => 'https://www.pagar.me/kyc/token' );
}
function papelito_email_button( $url, $label ) { return '<a href="' . $url . '">' . $label . '</a>'; }
function papelito_email_shell( $parts ) { return (string) ( $parts['body_html'] ?? '' ); }
function papelito_email_send( $recipient, $subject, $html, $text ) {
	$GLOBALS['papelito_kyc_email_calls']++;
	$GLOBALS['papelito_kyc_email'] = compact( 'recipient', 'subject', 'html', 'text' );
	return true;
}
function papelito_current_utc_mysql(): string { return '2026-09-11 12:00:00'; }

class WP_Error {
	private string $code;

	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = (string) $code; }
	public function get_error_code(): string { return $this->code; }
}

class WP_User {
	public $display_name = 'Pessoa Responsável';
	public $user_email   = 'vendor@example.com';
}

function get_userdata( $user_id ) { return 7 === $user_id ? new WP_User() : false; }

class Papelito_Test_Kyc_Wpdb {
	public string $prefix = 'wp_';

	public function get_charset_collate(): string { return ''; }

	public function prepare( string $sql, mixed ...$args ): string {
		$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		return $sql . '|' . json_encode( $values );
	}

	public function query( string $query ): int {
		$parts  = explode( '|', $query, 2 );
		$values = json_decode( $parts[1] ?? '[]', true );
		$user_id = (int) ( $values[1] ?? 0 );
		$window  = (int) ( $values[2] ?? 0 );
		$max     = (int) ( $values[3] ?? 0 );
		$now     = (int) $GLOBALS['papelito_kyc_now'];
		$current = $GLOBALS['papelito_kyc_limits'][ $user_id ] ?? null;

		if ( ! is_array( $current ) || (int) $current['expires_at'] <= $now ) {
			$GLOBALS['papelito_kyc_limits'][ $user_id ] = array( 'attempts' => 1, 'expires_at' => $now + $window );
			return is_array( $current ) ? 2 : 1;
		}

		if ( (int) $current['attempts'] >= $max ) {
			return 0;
		}

		$GLOBALS['papelito_kyc_limits'][ $user_id ]['attempts']++;
		return 2;
	}
}

$GLOBALS['wpdb'] = new Papelito_Test_Kyc_Wpdb();

require __DIR__ . '/../includes/pagarme_recipients.php';

$failures = 0;
function papelito_kyc_assert( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}

	$failures++;
	echo "  FAIL: {$label} — expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

echo "Estado KYC do recebedor\n";

papelito_pagarme_save_vendor_recipient_state(
	7,
	array(
		'id'          => 'rp_123',
		'status'      => 'affiliation',
		'kyc_details' => array(
			'status'        => 'partially_denied',
			'status_reason' => 'additional_documents_required',
		),
	)
);

$state = papelito_pagarme_get_vendor_recipient_state( 7 );
papelito_kyc_assert( 'persiste o status de KYC', 'partially_denied', $state['kyc_status'] );
papelito_kyc_assert( 'persiste o motivo de KYC', 'additional_documents_required', $state['kyc_status_reason'] );
papelito_kyc_assert( 'combinação oficial libera a WebApp', true, papelito_pagarme_vendor_kyc_action_required( 7 ) );
papelito_kyc_assert(
	'analysis does not release the WebApp',
	false,
	papelito_pagarme_kyc_action_required( 'affiliation', 'pending', 'in_analysis' )
);
papelito_kyc_assert(
	'refusal does not release the WebApp',
	false,
	papelito_pagarme_kyc_action_required( 'refused', 'denied', 'fully_denied' )
);

$GLOBALS['papelito_kyc_usermeta'][7]['papelito_pagarme_recipient_kyc_url'] = 'https://expired.example';
$link = papelito_pagarme_create_vendor_kyc_link( 7 );
papelito_kyc_assert( 'gera a URL temporaria no clique', 'https://www.pagar.me/kyc/token', $link['url'] ?? '' );
papelito_kyc_assert( 'envia o link ao e-mail do usuario', 'vendor@example.com', $GLOBALS['papelito_kyc_email']['recipient'] ?? '' );
papelito_kyc_assert( 'e-mail contém o link temporario', true, false !== strpos( (string) ( $GLOBALS['papelito_kyc_email']['text'] ?? '' ), 'https://www.pagar.me/kyc/token' ) );
papelito_kyc_assert( 'remove qualquer URL antiga persistida', '', get_user_meta( 7, 'papelito_pagarme_recipient_kyc_url', true ) );

for ( $attempt = 0; $attempt < 4; $attempt++ ) {
	papelito_pagarme_create_vendor_kyc_link( 7 );
}

$limited = papelito_pagarme_create_vendor_kyc_link( 7 );
papelito_kyc_assert( 'a sexta tentativa nao gera link', true, is_wp_error( $limited ) );
papelito_kyc_assert( 'a sexta tentativa devolve 429 estavel', 'papelito_pagarme_kyc_link_rate_limited', $limited instanceof WP_Error ? $limited->get_error_code() : '' );
papelito_kyc_assert( 'a quota gravada nunca passa de cinco', 5, $GLOBALS['papelito_kyc_limits'][7]['attempts'] ?? 0 );
papelito_kyc_assert( 'a sexta tentativa nao chama a Pagar.me', 5, $GLOBALS['papelito_kyc_post_calls'] );
papelito_kyc_assert( 'a sexta tentativa nao envia outro e-mail', 5, $GLOBALS['papelito_kyc_email_calls'] );

if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s)\n";
	exit( 1 );
}

echo "OK\n";
