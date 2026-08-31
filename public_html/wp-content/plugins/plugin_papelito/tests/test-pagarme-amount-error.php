<?php
/**
 * Standalone regression test for Pagar.me amount error mapping.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
function sanitize_text_field( mixed $value ) { return trim( (string) $value ); }
function sanitize_key( mixed $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}
function wp_json_encode( mixed $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function papelito_env( $key, $default = '' ) { return 'sk_test_example'; }
class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;
	public function __construct( string $code, string $message, mixed $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( mixed $value ) { return $value instanceof WP_Error; }
$papelito_test_http_response = array();
function wp_remote_request( $url, $args ) {
	global $papelito_test_http_response;
	return $papelito_test_http_response;
}
function wp_remote_retrieve_response_code( mixed $response ) { return (int) ( $response['status'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ) { return (string) ( $response['body'] ?? '' ); }

require __DIR__ . '/../includes/pagarme_client.php';

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

papelito_assert_same(
	'amount validation error is detected',
	true,
	papelito_pagarme_is_amount_error(
		array( 'message' => 'The amount must be greater than or equal to 100' )
	)
);

$papelito_test_http_response = array(
	'status' => 400,
	'body'   => json_encode( array( 'errors' => array( 'request.items[0].amount' => array( 'must be greater than 0' ) ) ) ),
);
$http_400 = papelito_pagarme_request( 'POST', 'orders', array( 'items' => array() ) );
papelito_assert_same( 'HTTP 400 amount response has own code', 'papelito_pagarme_amount_rejected', $http_400->get_error_code() );
papelito_assert_same( 'HTTP 400 status is preserved', 400, $http_400->get_error_data()['status'] ?? null );

$papelito_test_http_response = array(
	'status' => 422,
	'body'   => json_encode( array( 'errors' => array( 'request.customer.email' => array( 'is invalid' ) ) ) ),
);
$http_422 = papelito_pagarme_request( 'POST', 'orders', array( 'items' => array() ) );
papelito_assert_same( 'unrelated HTTP 422 remains generic', 'papelito_pagarme_request_failed', $http_422->get_error_code() );
papelito_assert_same( 'HTTP 422 status is preserved', 422, $http_422->get_error_data()['status'] ?? null );
papelito_assert_same(
	'HTTP 422 preserves a safe validation diagnostic',
	array( 'details' => array( 'request.customer.email: is invalid' ) ),
	$http_422->get_error_data()['pagarme_body'] ?? null
);
papelito_assert_same(
	'field amount error is detected',
	true,
	papelito_pagarme_is_amount_error(
		array( 'errors' => array( 'request.payments[0].amount' => array( 'valor mínimo inválido' ) ) )
	)
);
papelito_assert_same(
	'unrelated validation error is not remapped',
	false,
	papelito_pagarme_is_amount_error(
		array( 'errors' => array( 'request.customer.email' => array( 'is invalid' ) ) )
	)
);

$papelito_test_http_response = array(
	'status' => 412,
	'body'   => json_encode( array( 'message' => 'Request denied. Second authentication factor is necessary.' ) ),
);
$http_412 = papelito_pagarme_request( 'PATCH', 'recipients/re_example/default-bank-account', array( 'bank_account' => array() ) );
papelito_assert_same(
	'bank account update requiring second authentication has its own code',
	'papelito_pagarme_bank_account_update_auth_required',
	$http_412->get_error_code()
);
papelito_assert_same(
	'bank account update keeps a safe actionable message',
	'A Pagar.me exige uma autorização adicional para atualizar a conta bancária do recebedor.',
	$http_412->get_error_message()
);

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
