<?php
/**
 * Gate de onboarding do vendor no fluxo de gateways: pendência exclusiva da Pagar.me não prende o vendor.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

function add_action( mixed ...$args ): bool { return true; }

require_once dirname( __DIR__ ) . '/includes/payment_providers.php';
require_once dirname( __DIR__ ) . '/includes/pagarme_financial_profile.php';

$failures = 0;

function onboarding_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

$pending = array( 'phoneNumber', 'companyName', 'annualRevenue', 'partner.document', 'partner.birthdate', 'bankAccount.accountNumber', 'bankAccount.holderDocument' );

onboarding_assert( 'vendor legado continua preso a todas as pendências', $pending === papelito_vendor_onboarding_filter_pending( $pending, 'legacy' ) );
onboarding_assert( 'no fluxo de gateways só pendências comuns prendem o onboarding', array( 'phoneNumber', 'companyName' ) === papelito_vendor_onboarding_filter_pending( $pending, 'gateways' ) );
onboarding_assert( 'vendor suspenso também não fica preso a campo da Pagar.me', array( 'phoneNumber', 'companyName' ) === papelito_vendor_onboarding_filter_pending( $pending, 'suspended' ) );

$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/revendedor_application.php' );
$start  = strpos( $source, 'function papelito_get_vendor_pending_registration_rest_response(' );
$body   = substr( $source, $start, (int) strpos( $source, "\n}\n", $start ) - $start );
onboarding_assert( 'resposta de pendências do onboarding aplica o filtro', false !== strpos( $body, 'papelito_vendor_onboarding_pending_fields' ) );
$start  = strpos( $source, 'function papelito_get_vendor_application_rest_response(' );
$body   = substr( $source, $start, (int) strpos( $source, "\n}\n", $start ) - $start );
onboarding_assert( 'resposta do wizard aplica o filtro', false !== strpos( $body, 'papelito_vendor_onboarding_pending_fields' ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
