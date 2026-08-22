<?php
/**
 * Standalone regression test for the double approval rule of regional coverage.
 *
 * Vender exige as duas coisas: faixa de CEP que cubra o destino E recebedor Pagar.me `active`.
 * Sem a segunda, o produto aparecia disponivel e comprável na vitrine e o comprador só descobria
 * o problema no ultimo clique do checkout, com o cartao ja digitado.
 *
 * Usage: php tests/test-coverage-vendor-eligibility.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['qa_users']    = array();
$GLOBALS['qa_usermeta'] = array();

function absint( $value ) {
	return abs( (int) $value );
}

function add_action( ...$args ) {
	// Stub: o teste exercita apenas a resolução de vendors, não os hooks do catálogo.
}

function add_filter( ...$args ) {
	// Stub: idem.
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function get_users( array $args ) {
	$role = $args['role'] ?? '';

	return array_values(
		array_filter(
			$GLOBALS['qa_users'],
			static fn( object $user ): bool => '' === $role || in_array( $role, $user->roles, true )
		)
	);
}

function get_user_meta( int $user_id, string $key, bool $single = false ) {
	$value = $GLOBALS['qa_usermeta'][ $user_id ][ $key ] ?? ( $single ? '' : array() );

	if ( $single && is_array( $value ) ) {
		return $value[0] ?? '';
	}

	return $single ? $value : (array) $value;
}

function papelito_pagarme_get_vendor_recipient_status( int $user_id ): string {
	return sanitize_key( (string) get_user_meta( $user_id, 'papelito_pagarme_recipient_status', true ) );
}

function papelito_pagarme_vendor_recipient_is_active( int $user_id ): bool {
	return 'active' === papelito_pagarme_get_vendor_recipient_status( $user_id );
}

function papelito_vendor_can_receive_payments( int $vendor_id ): bool {
	return papelito_pagarme_vendor_recipient_is_active( $vendor_id );
}

function qa_add_vendor( int $id, array $ranges, string $recipient_status ): void {
	$GLOBALS['qa_users'][] = (object) array(
		'ID'    => $id,
		'roles' => array( 'seller' ),
	);

	$GLOBALS['qa_usermeta'][ $id ] = array(
		'min_cep'                            => array_column( $ranges, 0 ),
		'max_cep'                            => array_column( $ranges, 1 ),
		'papelito_pagarme_recipient_status'  => array( $recipient_status ),
	);
}

require __DIR__ . '/../includes/products_filter.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label} -> expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

qa_add_vendor( 10, array( array( '01000000', '05999999' ) ), 'active' );
qa_add_vendor( 20, array( array( '01000000', '09999999' ) ), '' );
qa_add_vendor( 30, array( array( '01000000', '09999999' ) ), 'registration' );
qa_add_vendor( 40, array( array( '06000000', '06999999' ) ), 'active' );

echo "Scenario 1: only vendors with an active recipient enter coverage\n";
papelito_assert( 'CEP coberto por 10 (active), 20 (sem recebedor) e 30 (registration)', array( 10 ), papelito_matching_vendor_ids( 1310100 ) );

echo "Scenario 2: a covering vendor with no active recipient never appears alone\n";
papelito_assert( 'CEP 07000000 nao tem vendor apto', array(), papelito_matching_vendor_ids( 7000000 ) );

echo "Scenario 3: a second range of an approved vendor still counts\n";
papelito_assert( 'CEP 06500000 resolve para o vendor 40', array( 40 ), papelito_matching_vendor_ids( 6500000 ) );

echo "Scenario 4: turning the recipient active puts the vendor back in coverage\n";
$GLOBALS['qa_usermeta'][20]['papelito_pagarme_recipient_status'] = array( 'active' );
papelito_assert( 'vendor 20 volta a cobrir', array( 10, 20 ), papelito_matching_vendor_ids( 1310100 ) );

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) FAILED\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
exit( 0 );
