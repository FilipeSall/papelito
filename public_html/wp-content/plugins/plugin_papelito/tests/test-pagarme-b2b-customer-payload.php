<?php
/**
 * Standalone regression test for the B2B Pagar.me customer address payload.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function sanitize_email( mixed $value ): string { return trim( (string) $value ); }
function sanitize_key( mixed $value ): string { return strtolower( trim( (string) $value ) ); }
function is_email( mixed $value ): bool { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function papelito_normalize_cep( string $value ): string { return preg_replace( '/\D+/', '', $value ) ?? ''; }
function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {}

class PapelitoB2bPayloadTestOrder {
	public function __construct( private array $meta ) {}

	public function get_meta( string $key, bool $single = true ): string {
		return (string) ( $this->meta[ $key ] ?? '' );
	}
}

require_once __DIR__ . '/../includes/pagarme_recipients.php';
require_once __DIR__ . '/../includes/pagarme_payments.php';

$failures = 0;

function papelito_b2b_payload_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

$payload = papelito_pagarme_b2b_customer_payload_from_order(
	new PapelitoB2bPayloadTestOrder(
		array(
			'_papelito_company_id'           => '10',
			'_papelito_company_legal_name'   => 'Empresa Teste LTDA',
			'_papelito_company_billing_email' => 'fiscal@empresa.test',
			'_papelito_company_cnpj'         => '12345678000195',
			'_papelito_company_phone'        => '11999999999',
			'_papelito_fiscal_cep'           => '01001-000',
			'_papelito_fiscal_state'         => 'SP',
			'_papelito_fiscal_city'          => 'São Paulo',
			'_papelito_fiscal_neighborhood'  => 'Sé',
			'_papelito_fiscal_street'        => 'Praça da Sé',
			'_papelito_fiscal_number'        => '1',
		)
	)
);

papelito_b2b_payload_assert_same( 'payload B2B contém CEP no contrato Pagar.me', '01001000', $payload['address']['zip_code'] ?? null );

if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}

echo "RESULT: all assertions passed\n";
exit( 0 );
