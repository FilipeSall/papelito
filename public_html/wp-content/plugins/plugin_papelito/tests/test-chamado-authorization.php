<?php
/** Autorização do chamado: quem lê, quem abre, e a defesa contra IDOR na criação. */

require_once __DIR__ . '/support/chamado_env.php';
require_once __DIR__ . '/../includes/vendor_messaging.php';

$thread     = papelito_test_thread();
$escalado   = papelito_test_thread( array( 'escalated_at' => '2026-09-02 09:00:00' ) );
$pagarme    = papelito_test_thread(
	array(
		'order_id'    => null,
		'customer_id' => 0,
		'context'     => 'pagarme_bank_account_update',
		'reason'      => null,
	)
);
$devolucao  = papelito_test_thread( array( 'order_id' => null, 'context' => 'return' ) );

papelito_test_as( CHAMADO_TEST_ADMIN_ID );
$admin_role_sem_escala = papelito_messaging_access_role( $thread, CHAMADO_TEST_ADMIN_ID );
$admin_role_escalado   = papelito_messaging_access_role( $escalado, CHAMADO_TEST_ADMIN_ID );

$papeis = array(
	'customer'  => papelito_messaging_access_role( $thread, CHAMADO_TEST_CUSTOMER_ID ),
	'seller'    => papelito_messaging_access_role( $thread, CHAMADO_TEST_VENDOR_ID ),
	'stranger'  => papelito_messaging_access_role( $thread, CHAMADO_TEST_STRANGER_ID ),
	'anonimo'   => papelito_messaging_access_role( $thread, 0 ),
);

// --- criação: participantes vêm do pedido, nunca da request ------------------
papelito_test_as( CHAMADO_TEST_CUSTOMER_ID );
papelito_test_reset_wpdb();
$criado = papelito_messaging_handle_create_thread(
	new WP_REST_Request(
		array(
			'order_id' => CHAMADO_TEST_ORDER_ID,
			'reason'   => 'duvida_pedido',
			'body'     => 'A entrega não chegou.',
		)
	)
);
$insert_thread = $GLOBALS['wpdb']->inserts[0]['data'] ?? array();
$GLOBALS['papelito_test_lock_trace'] = $GLOBALS['wpdb']->queries;

// A request tenta forjar os participantes e o status.
papelito_test_reset_wpdb();
papelito_messaging_handle_create_thread(
	new WP_REST_Request(
		array(
			'order_id'    => CHAMADO_TEST_ORDER_ID,
			'reason'      => 'duvida_pedido',
			'body'        => 'oi',
			'customer_id' => CHAMADO_TEST_STRANGER_ID,
			'vendor_id'   => CHAMADO_TEST_STRANGER_ID,
			'status'      => 'ENCERRADO',
			'closed_by'   => CHAMADO_TEST_STRANGER_ID,
		)
	)
);
$forjado = $GLOBALS['wpdb']->inserts[0]['data'] ?? array();

// Terceiro sem relação com o pedido.
papelito_test_as( CHAMADO_TEST_STRANGER_ID );
papelito_test_reset_wpdb();
$idor = papelito_messaging_handle_create_thread(
	new WP_REST_Request(
		array( 'order_id' => CHAMADO_TEST_ORDER_ID, 'reason' => 'duvida_pedido', 'body' => 'me deixa ver' )
	)
);
$idor_inserts = $GLOBALS['wpdb']->inserts;

// Sem motivo, mesmo sendo o comprador.
papelito_test_as( CHAMADO_TEST_CUSTOMER_ID );
papelito_test_reset_wpdb();
$sem_motivo         = papelito_messaging_handle_create_thread(
	new WP_REST_Request( array( 'order_id' => CHAMADO_TEST_ORDER_ID, 'body' => 'oi' ) )
);
$sem_motivo_inserts = $GLOBALS['wpdb']->inserts;

papelito_test_report(
	array(
		'o comprador do pedido é customer' => 'customer' === $papeis['customer'],
		'o vendor do pedido é seller' => 'seller' === $papeis['seller'],
		'vendor não relacionado é bloqueado com 403' =>
			is_wp_error( $papeis['stranger'] )
			&& 'papelito_message_thread_forbidden' === $papeis['stranger']->get_error_code()
			&& 403 === $papeis['stranger']->get_error_data()['status'],
		'usuário anônimo é bloqueado' => is_wp_error( $papeis['anonimo'] ),
		'a Papelito atende chamado não escalado' => 'administrator' === $admin_role_sem_escala,
		'a Papelito atende chamado escalado' => 'administrator' === $admin_role_escalado,
		'só loja e Papelito encerram' =>
			false === papelito_messaging_can_close( 'customer' )
			&& true === papelito_messaging_can_close( 'seller' )
			&& true === papelito_messaging_can_close( 'administrator' ),
		'chamado é conversa com pedido' => true === papelito_messaging_is_chamado( $thread ),
		'canal da Pagar.me não é chamado' => false === papelito_messaging_is_chamado( $pagarme ),
		'thread de Return Request não é chamado' => false === papelito_messaging_is_chamado( $devolucao ),
		'conversa sem pedido não é chamado' =>
			false === papelito_messaging_is_chamado( papelito_test_thread( array( 'order_id' => 0 ) ) ),
		'a abertura é serializada por pedido: trava e destrava' =>
			1 === count( array_filter( $GLOBALS['papelito_test_lock_trace'], static fn( $q ) => str_contains( $q, 'GET_LOCK' ) ) )
			&& 1 === count( array_filter( $GLOBALS['papelito_test_lock_trace'], static fn( $q ) => str_contains( $q, 'RELEASE_LOCK' ) ) ),
		'o chamado nasce com o pedido, o comprador e o vendor do pedido' =>
			$criado instanceof WP_REST_Response
			&& CHAMADO_TEST_ORDER_ID === $insert_thread['order_id']
			&& CHAMADO_TEST_CUSTOMER_ID === $insert_thread['customer_id']
			&& CHAMADO_TEST_VENDOR_ID === $insert_thread['vendor_id']
			&& 'duvida_pedido' === $insert_thread['reason']
			&& 'ABERTO' === $insert_thread['status'],
		'a request não consegue forjar participantes nem status' =>
			CHAMADO_TEST_CUSTOMER_ID === $forjado['customer_id']
			&& CHAMADO_TEST_VENDOR_ID === $forjado['vendor_id']
			&& 'ABERTO' === $forjado['status']
			&& ! array_key_exists( 'closed_by', $forjado ),
		'terceiro não abre chamado no pedido de outro, e nada é escrito' =>
			is_wp_error( $idor )
			&& 'papelito_message_order_forbidden' === $idor->get_error_code()
			&& 404 === $idor->get_error_data()['status']
			&& array() === $idor_inserts,
		'sem motivo o chamado não é criado' =>
			is_wp_error( $sem_motivo )
			&& 'papelito_message_reason_invalid' === $sem_motivo->get_error_code()
			&& array() === $sem_motivo_inserts,
	)
);
