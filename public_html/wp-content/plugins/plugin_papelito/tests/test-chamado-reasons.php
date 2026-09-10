<?php
/** Motivo do chamado: obrigatório, validado antes de qualquer escrita, e disjunto do de devolução. */

require_once __DIR__ . '/support/chamado_env.php';
require_once __DIR__ . '/../includes/vendor_messaging.php';

papelito_test_as( CHAMADO_TEST_CUSTOMER_ID );
papelito_test_reset_wpdb();

$sem_motivo   = papelito_messaging_validate_reason( new WP_REST_Request( array() ) );
$inexistente  = papelito_messaging_validate_reason( new WP_REST_Request( array( 'reason' => 'nao_existe' ) ) );
$outro_vazio  = papelito_messaging_validate_reason( new WP_REST_Request( array( 'reason' => 'outro' ) ) );
$outro_ok     = papelito_messaging_validate_reason( new WP_REST_Request( array( 'reason' => 'outro', 'reason_other' => 'Nota fiscal' ) ) );
$simples      = papelito_messaging_validate_reason( new WP_REST_Request( array( 'reason' => 'atraso_entrega' ) ) );

$dev_sem_return = papelito_messaging_validate_reason(
	new WP_REST_Request( array( 'reason' => 'devolucao' ) )
);
$dev_defective = papelito_messaging_validate_reason(
	new WP_REST_Request( array( 'reason' => 'devolucao', 'return_reason' => 'defective' ) )
);
$dev_invalido = papelito_messaging_validate_reason(
	new WP_REST_Request( array( 'reason' => 'devolucao', 'return_reason' => 'duvida_pedido' ) )
);
$dev_other_ko = papelito_messaging_validate_reason(
	new WP_REST_Request( array( 'reason' => 'devolucao', 'return_reason' => 'other' ) )
);
$dev_other_ok = papelito_messaging_validate_reason(
	new WP_REST_Request( array( 'reason' => 'devolucao', 'return_reason' => 'other', 'reason_other' => 'Chegou molhado' ) )
);

$labels        = papelito_messaging_reason_labels();
$reasons       = papelito_messaging_reasons();
$todos_rotulos = count( array_diff( $reasons, array_keys( $labels ) ) ) === 0
	&& count( array_diff( array_keys( $labels ), $reasons ) ) === 0;

$return_labels    = papelito_return_reason_labels();
$return_completos = count( array_diff( papelito_return_reasons(), array_keys( $return_labels ) ) ) === 0;

papelito_test_report(
	array(
		'os dois vocabulários de motivo são disjuntos' =>
			array() === array_intersect( papelito_messaging_reasons(), papelito_return_reasons() ),
		'todo motivo de chamado tem rótulo, e todo rótulo tem motivo' => $todos_rotulos,
		'todo motivo de devolução tem rótulo' => $return_completos,
		'motivo ausente é rejeitado com 422' =>
			is_wp_error( $sem_motivo )
			&& 'papelito_message_reason_invalid' === $sem_motivo->get_error_code()
			&& 422 === $sem_motivo->get_error_data()['status'],
		'motivo fora do vocabulário é rejeitado' =>
			is_wp_error( $inexistente ) && 422 === $inexistente->get_error_data()['status'],
		'"outro assunto" sem descrição é rejeitado' =>
			is_wp_error( $outro_vazio ) && 422 === $outro_vazio->get_error_data()['status'],
		'"outro assunto" com descrição é aceito e preserva o texto' =>
			! is_wp_error( $outro_ok ) && 'Nota fiscal' === $outro_ok['reason_other'],
		'motivo simples é aceito sem exigir detalhe' =>
			! is_wp_error( $simples )
			&& 'atraso_entrega' === $simples['reason']
			&& null === $simples['return_reason'],
		'devolução sem o motivo do vocabulário de devolução é rejeitada' =>
			is_wp_error( $dev_sem_return ) && 422 === $dev_sem_return->get_error_data()['status'],
		'motivo de chamado no lugar do de devolução é rejeitado' =>
			is_wp_error( $dev_invalido ) && 422 === $dev_invalido->get_error_data()['status'],
		'devolução com motivo válido guarda os dois vocabulários em colunas separadas' =>
			! is_wp_error( $dev_defective )
			&& 'devolucao' === $dev_defective['reason']
			&& 'defective' === $dev_defective['return_reason'],
		'devolução "outro motivo" sem texto é rejeitada' =>
			is_wp_error( $dev_other_ko ) && 422 === $dev_other_ko->get_error_data()['status'],
		'devolução "outro motivo" com texto é aceita' =>
			! is_wp_error( $dev_other_ok ) && 'Chegou molhado' === $dev_other_ok['reason_other'],
		'a validação do motivo não escreve nada no banco' => array() === $GLOBALS['wpdb']->inserts,
	)
);
