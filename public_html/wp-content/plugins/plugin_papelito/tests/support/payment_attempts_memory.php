<?php
/**
 * Repositório de tentativas em memória para testes standalone.
 *
 * Implementa o mesmo contrato de `includes/payment_attempts_repository.php` (compare-and-set de
 * estado e de lock_version) sem banco, usando a máquina de estados real de `payment_attempts.php`.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Test support stubs repository functions by design.

$GLOBALS['memory_attempts'] = array();
$GLOBALS['memory_locks']    = array();

function papelito_payment_attempt_create( array $fields ) {
	$fields['state'] = (string) ( $fields['state'] ?? 'not_started' );
	$valid           = papelito_payment_attempt_validate_new( $fields );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$id                                = count( $GLOBALS['memory_attempts'] ) + 1;
	$GLOBALS['memory_attempts'][ $id ] = array_merge(
		array(
			'order_id'              => 0,
			'checkout_attempt_id'   => '',
			'buyer_user_id'         => 0,
			'company_id'            => 0,
			'account_id'            => 0,
			'account_ref'           => '',
			'external_reference'    => '',
			'remote_id'             => '',
			'remote_payment_ids'    => array(),
			'currency'              => 'BRL',
			'flow'                  => '',
			'payment_method'        => '',
			'fallback_reason'       => '',
			'configuration_version' => '',
			'commercial_hash'       => '',
			'redirect_url'          => '',
			'expires_at'            => null,
			'last_error_code'       => '',
			'next_reconcile_at'     => null,
			'reconcile_failures'    => 0,
			'incident'              => '',
			'created_at'            => gmdate( 'Y-m-d H:i:s' ),
		),
		$fields,
		array(
			'id'           => $id,
			'attempt_ref'  => papelito_payment_attempt_generate_ref(),
			'lock_version' => 0,
		)
	);
	return $GLOBALS['memory_attempts'][ $id ];
}

function papelito_payment_attempt_find( int $id ): ?array {
	return $GLOBALS['memory_attempts'][ $id ] ?? null;
}

function papelito_payment_attempt_find_by_ref( string $ref ): ?array {
	foreach ( $GLOBALS['memory_attempts'] as $attempt ) {
		if ( $attempt['attempt_ref'] === $ref ) {
			return $attempt;
		}
	}
	return null;
}

function papelito_payment_attempt_find_by_remote( string $provider, string $remote_id ): ?array {
	foreach ( array_reverse( $GLOBALS['memory_attempts'] ) as $attempt ) {
		if ( $attempt['provider'] === $provider && $attempt['remote_id'] === $remote_id && '' !== $remote_id ) {
			return $attempt;
		}
	}
	return null;
}

function papelito_payment_attempts_for_order( int $order_id ): array {
	$rows = array_values( array_filter( $GLOBALS['memory_attempts'], static fn( array $row ): bool => $row['order_id'] === $order_id ) );
	return array_reverse( $rows );
}

function papelito_payment_attempt_latest_for_order( int $order_id ): ?array {
	return papelito_payment_attempts_for_order( $order_id )[0] ?? null;
}

function papelito_payment_attempt_transition( int $id, string $from, string $to, array $fields = array() ): bool {
	$row = $GLOBALS['memory_attempts'][ $id ] ?? null;
	if ( null === $row || $row['state'] !== $from || ! papelito_payment_attempt_can_transition( $from, $to ) ) {
		return false;
	}
	unset( $fields['state'] );
	$GLOBALS['memory_attempts'][ $id ] = array_merge( $row, $fields, array( 'state' => $to, 'lock_version' => $row['lock_version'] + 1 ) );
	return true;
}

function papelito_payment_attempt_update( array $attempt, array $fields ): bool {
	$row = $GLOBALS['memory_attempts'][ $attempt['id'] ] ?? null;
	if ( null === $row || $row['lock_version'] !== $attempt['lock_version'] ) {
		return false;
	}
	unset( $fields['state'] );
	$GLOBALS['memory_attempts'][ $attempt['id'] ] = array_merge( $row, $fields, array( 'lock_version' => $row['lock_version'] + 1 ) );
	return true;
}

function papelito_payment_attempt_acquire_lock( string $key, int $timeout = 10 ): bool {
	if ( isset( $GLOBALS['memory_locks'][ $key ] ) ) {
		return false;
	}
	$GLOBALS['memory_locks'][ $key ] = true;
	return true;
}

function papelito_payment_attempt_release_lock( string $key ): void {
	unset( $GLOBALS['memory_locks'][ $key ] );
}
