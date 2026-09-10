<?php
/** Encerramento do chamado: só loja e Papelito, e "encerrado" bloqueia mensagem nova de verdade. */

require_once __DIR__ . '/support/chamado_env.php';
require_once __DIR__ . '/../includes/vendor_messaging.php';

/** Executa o encerramento com a linha informada, como o usuário informado. */
function chamado_close_as( int $user_id, array $thread ) {
	papelito_test_as( $user_id );
	papelito_test_reset_wpdb();
	$GLOBALS['wpdb']->thread = $thread;

	return papelito_messaging_handle_close( new WP_REST_Request( array( 'id' => (int) $thread['id'] ) ) );
}

$aberto    = papelito_test_thread();
$encerrado = papelito_test_thread(
	array(
		'status'    => 'ENCERRADO',
		'closed_at' => '2026-09-05 08:00:00',
		'closed_by' => CHAMADO_TEST_VENDOR_ID,
	)
);

$cliente          = chamado_close_as( CHAMADO_TEST_CUSTOMER_ID, $aberto );
$cliente_updates  = $GLOBALS['wpdb']->updates;

$estranho         = chamado_close_as( CHAMADO_TEST_STRANGER_ID, $aberto );
$estranho_updates = $GLOBALS['wpdb']->updates;

$vendor          = chamado_close_as( CHAMADO_TEST_VENDOR_ID, $aberto );
$vendor_update   = $GLOBALS['wpdb']->updates[0] ?? '';
$vendor_actions  = $GLOBALS['papelito_test_actions'];

$admin         = chamado_close_as( CHAMADO_TEST_ADMIN_ID, $aberto );
$admin_update  = $GLOBALS['wpdb']->updates[0] ?? '';

$repetido         = chamado_close_as( CHAMADO_TEST_VENDOR_ID, $encerrado );
$repetido_updates = $GLOBALS['wpdb']->updates;
$repetido_actions = $GLOBALS['papelito_test_actions'];

// --- mensagem, escalonamento e leitura em chamado encerrado ------------------
papelito_test_as( CHAMADO_TEST_CUSTOMER_ID );
papelito_test_reset_wpdb();
$GLOBALS['wpdb']->thread = $encerrado;
$mensagem         = papelito_messaging_handle_post_message(
	new WP_REST_Request( array( 'id' => CHAMADO_TEST_THREAD_ID, 'body' => 'ainda preciso de ajuda' ) )
);
$mensagem_inserts = $GLOBALS['wpdb']->inserts;

papelito_test_reset_wpdb();
$GLOBALS['wpdb']->thread = $encerrado;
$escalonamento = papelito_messaging_handle_escalate( new WP_REST_Request( array( 'id' => CHAMADO_TEST_THREAD_ID ) ) );

papelito_test_reset_wpdb();
$GLOBALS['wpdb']->thread = $encerrado;
$leitura = papelito_messaging_handle_mark_read( new WP_REST_Request( array( 'id' => CHAMADO_TEST_THREAD_ID ) ) );

$fechou_pelo_vendor = is_string( $vendor_update ) && str_contains( $vendor_update, 'UPDATE' );

papelito_test_report(
	array(
		'o cliente não encerra: 403 e nenhuma escrita' =>
			is_wp_error( $cliente )
			&& 'papelito_message_close_forbidden' === $cliente->get_error_code()
			&& 403 === $cliente->get_error_data()['status']
			&& array() === $cliente_updates,
		'vendor não relacionado é bloqueado antes de chegar ao encerramento' =>
			is_wp_error( $estranho )
			&& 'papelito_message_thread_forbidden' === $estranho->get_error_code()
			&& array() === $estranho_updates,
		'o vendor do pedido encerra' => $vendor instanceof WP_REST_Response && 200 === $vendor->get_status(),
		'o encerramento grava status, autor e data em UTC' =>
			$fechou_pelo_vendor
			&& str_contains( $vendor_update, 'ENCERRADO' )
			&& str_contains( $vendor_update, (string) CHAMADO_TEST_VENDOR_ID )
			&& str_contains( $vendor_update, '2026-09-09 12:00:00' ),
		'o encerramento é compare-and-set: só troca quem está ABERTO' =>
			$fechou_pelo_vendor && str_contains( $vendor_update, 'AND status = %s' ),
		'a Papelito encerra' =>
			$admin instanceof WP_REST_Response
			&& is_string( $admin_update )
			&& str_contains( $admin_update, (string) CHAMADO_TEST_ADMIN_ID ),
		'o encerramento emite evento de domínio, não notificação direta' =>
			1 === count( $vendor_actions )
			&& 'papelito_support_chamado_closed' === $vendor_actions[0]['hook']
			&& array( CHAMADO_TEST_THREAD_ID, CHAMADO_TEST_VENDOR_ID ) === $vendor_actions[0]['args'],
		'encerrar de novo devolve 200 sem sobrescrever quem encerrou primeiro' =>
			$repetido instanceof WP_REST_Response
			&& 200 === $repetido->get_status()
			&& array() === $repetido_updates
			&& array() === $repetido_actions,
		'chamado encerrado recusa mensagem nova com código estável' =>
			is_wp_error( $mensagem )
			&& 'papelito_message_thread_closed' === $mensagem->get_error_code()
			&& 409 === $mensagem->get_error_data()['status']
			&& array() === $mensagem_inserts,
		'chamado encerrado não pode ser escalado' =>
			is_wp_error( $escalonamento )
			&& 'papelito_message_thread_closed' === $escalonamento->get_error_code(),
		'marcar como lido continua permitido em chamado encerrado' =>
			$leitura instanceof WP_REST_Response,
		'o vocabulário de status tem exatamente dois estados' =>
			array( 'ABERTO', 'ENCERRADO' ) === papelito_messaging_statuses(),
		'linha antiga sem status é tratada como aberta' =>
			'ABERTO' === papelito_messaging_thread_status( array() ),
	)
);
