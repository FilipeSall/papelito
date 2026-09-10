<?php
/**
 * Vínculo formal entre chamado de devolução e Return Request.
 *
 * @package Papelito
 */

require_once __DIR__ . '/support/chamado_env.php';
require_once __DIR__ . '/../includes/vendor_messaging.php';

$valid = papelito_test_thread(
	array(
		'reason'       => 'devolucao',
		'return_reason' => 'defective',
	)
);

$legacy = papelito_test_thread(
	array(
		'reason'       => 'devolucao',
		'return_reason' => null,
	)
);

$not_return = papelito_test_thread();
$closed = papelito_test_thread(
	array(
		'reason'       => 'devolucao',
		'return_reason' => 'defective',
		'status'       => 'ENCERRADO',
	)
);

$assertions = array();

foreach ( array( 'valid' => $valid, 'legacy' => $legacy, 'not_return' => $not_return, 'closed' => $closed ) as $key => $thread ) {
	papelito_test_reset_wpdb();
	$GLOBALS['wpdb']->thread = $thread;
	$result = papelito_messaging_return_chamado_for_vendor(
		CHAMADO_TEST_THREAD_ID,
		CHAMADO_TEST_ORDER_ID,
		CHAMADO_TEST_CUSTOMER_ID,
		CHAMADO_TEST_VENDOR_ID
	);
	$assertions[ $key ] = $result;
}

papelito_test_reset_wpdb();
$GLOBALS['wpdb']->thread = $valid;
$attached = papelito_messaging_attach_return_to_chamado(
	CHAMADO_TEST_THREAD_ID,
	55,
	CHAMADO_TEST_ORDER_ID,
	CHAMADO_TEST_CUSTOMER_ID,
	CHAMADO_TEST_VENDOR_ID
);

papelito_test_reset_wpdb();
$GLOBALS['wpdb']->thread = papelito_test_thread(
	array(
		'reason'            => 'devolucao',
		'return_reason'     => 'defective',
		'return_request_id' => 55,
	)
);
$closed = papelito_messaging_close_chamado_for_return( 55, CHAMADO_TEST_VENDOR_ID );

papelito_test_report(
	array(
		'chamado de devolução válido pertence ao pedido, customer e vendor' => is_array( $assertions['valid'] ) && 'defective' === $assertions['valid']['return_reason'],
		'chamado legado sem motivo não formaliza devolução' => is_wp_error( $assertions['legacy'] ) && 'papelito_return_reason_missing' === $assertions['legacy']->get_error_code(),
		'chamado de outro motivo não formaliza devolução' => is_wp_error( $assertions['not_return'] ) && 'papelito_return_thread_not_return' === $assertions['not_return']->get_error_code(),
		'chamado encerrado não formaliza devolução' => is_wp_error( $assertions['closed'] ) && 'papelito_return_thread_closed' === $assertions['closed']->get_error_code(),
		'vínculo grava return_request_id apenas com chamado aberto e sem vínculo' => true === $attached && 1 === count( $GLOBALS['wpdb']->updates ),
		'estorno encerra o chamado associado uma única vez' => true === $closed && 1 === count( $GLOBALS['wpdb']->updates ),
	)
);
