<?php
/**
 * Regressão do vínculo N:N entre Kit e brinde.
 *
 * O mesmo registro de brinde serve vários Kits; a quantidade é do vínculo, não
 * do brinde. Desvincular e excluir Kit nunca apagam o catálogo.
 *
 * Usage: php tests/test-kit-merchandise-link.php
 *
 * @package Papelito
 */

require __DIR__ . '/support/merchandise_env.php';

$GLOBALS['pap_products'][101] = new WC_Product( 101, 'Seda King Size', '0.5' );

$piteira = papelito_merchandise_create( papelito_test_merchandise_payload() );
$adesivo = papelito_merchandise_create( papelito_test_merchandise_payload( array( 'name' => 'Adesivo Papelito', 'imageAttachmentId' => papelito_test_attachment( 72 ), 'weight' => '0.01' ) ) );

echo "Scenario 1: associar um brinde do catálogo ao Kit\n";
$kit_a = papelito_kit_write( papelito_test_kit_payload( array( 'merchandise' => array( array( 'merchandiseId' => 1, 'quantity' => 2 ) ) ) ) );
papelito_assert( 'o Kit é criado', is_array( $kit_a ) && ! is_wp_error( $kit_a ) );
$composition_a = papelito_kit_merchandise( (int) $kit_a['id'] );
papelito_assert( 'a composição resolve o brinde do catálogo', 1 === count( $composition_a ) && 1 === (int) $composition_a[0]['id'] );
papelito_assert( 'traz peso e dimensões globais', '0.05' === (string) $composition_a[0]['weight'] && '14' === (string) $composition_a[0]['length'] );
papelito_assert( 'traz a quantidade do vínculo', 2 === (int) $composition_a[0]['quantity'] );
papelito_assert( 'a resposta administrativa referencia por id', 1 === papelito_kit_response( $kit_a )['merchandise'][0]['merchandiseId'] );

echo "Scenario 2: o mesmo brinde serve outro Kit, sem cópia\n";
$kit_b = papelito_kit_write( papelito_test_kit_payload( array( 'name' => 'Kit Smoking', 'merchandise' => array( array( 'merchandiseId' => 1, 'quantity' => 1 ) ) ) ) );
papelito_assert( 'o segundo Kit é criado', is_array( $kit_b ) && ! is_wp_error( $kit_b ) );
papelito_assert( 'o catálogo continua com dois brindes', 2 === count( $wpdb->merchandise ) );
papelito_assert( 'os dois Kits apontam para o mesmo registro', 1 === (int) papelito_kit_merchandise( (int) $kit_b['id'] )[0]['id'] );
papelito_assert( 'o uso lista os dois Kits', 2 === count( papelito_merchandise_kits( 1 ) ) );

echo "Scenario 3: a quantidade é do vínculo, não do brinde\n";
papelito_assert( 'Kit A usa duas unidades', 2 === (int) papelito_kit_merchandise( (int) $kit_a['id'] )[0]['quantity'] );
papelito_assert( 'Kit B usa uma unidade', 1 === (int) papelito_kit_merchandise( (int) $kit_b['id'] )[0]['quantity'] );

echo "Scenario 4: o mesmo brinde não entra duas vezes no mesmo Kit\n";
$duplicated = papelito_kit_write(
	papelito_test_kit_payload(
		array(
			'name'        => 'Kit Duplicado',
			'merchandise' => array( array( 'merchandiseId' => 1, 'quantity' => 1 ), array( 'merchandiseId' => 1, 'quantity' => 3 ) ),
		)
	)
);
papelito_assert( 'devolve 422 de duplicidade', is_wp_error( $duplicated ) && 'papelito_kit_merchandise_duplicate' === $duplicated->code && 422 === $duplicated->data['status'] );

echo "Scenario 5: referência inválida é recusada\n";
$missing = papelito_kit_write( papelito_test_kit_payload( array( 'name' => 'Kit Fantasma', 'merchandise' => array( array( 'merchandiseId' => 4242, 'quantity' => 1 ) ) ) ) );
papelito_assert( 'brinde inexistente devolve 422', is_wp_error( $missing ) && 'papelito_kit_merchandise_not_found' === $missing->code );
$zero_quantity = papelito_kit_write( papelito_test_kit_payload( array( 'name' => 'Kit Zerado', 'merchandise' => array( array( 'merchandiseId' => 1, 'quantity' => 0 ) ) ) ) );
papelito_assert( 'quantidade zero devolve 422', is_wp_error( $zero_quantity ) && 'papelito_kit_merchandise_invalid' === $zero_quantity->code );

echo "Scenario 6: desvincular do Kit não exclui o brinde\n";
$kit_b_detached = papelito_kit_write( papelito_test_kit_payload( array( 'name' => 'Kit Smoking', 'merchandise' => array() ) ), (int) $kit_b['id'] );
papelito_assert( 'o Kit salva sem brindes', is_array( $kit_b_detached ) && array() === papelito_kit_merchandise( (int) $kit_b['id'] ) );
papelito_assert( 'o brinde continua no catálogo', is_array( papelito_merchandise_get( 1 ) ) );
papelito_assert( 'a imagem do brinde não é apagada', ! in_array( 71, $GLOBALS['pap_deleted_attachments'], true ) );
papelito_assert( 'o uso volta a apontar só o Kit A', 1 === count( papelito_merchandise_kits( 1 ) ) );

echo "Scenario 7: excluir o Kit não exclui o brinde\n";
$deleted_kit = papelito_kit_admin_delete( new WP_REST_Request( array( 'id' => (int) $kit_b['id'] ) ) );
papelito_assert( 'o Kit é excluído', $deleted_kit instanceof WP_REST_Response && true === $deleted_kit->data['deleted'] );
papelito_assert( 'os dois brindes seguem no catálogo', 2 === count( $wpdb->merchandise ) );
papelito_assert( 'a imagem do brinde sobrevive ao Kit', ! in_array( 71, $GLOBALS['pap_deleted_attachments'], true ) );
papelito_assert( 'a exclusão só desfaz o vínculo', in_array( 'wp_papelito_kit_merchandise_items', array_column( $wpdb->deletes, 0 ), true ) && ! in_array( 'wp_papelito_merchandise', array_column( $wpdb->deletes, 0 ), true ) );

echo "Scenario 8: o brinde continua entrando no peso e na cotação\n";
$weight = papelito_kit_calculate_weight_grams( (int) $kit_a['id'] );
papelito_assert( 'peso soma componentes e brindes', 1100.0 === $weight );
$logistics = papelito_kit_logistics( (int) $kit_a['product_id'] );
papelito_assert( 'a logística cota com esse peso', is_array( $logistics ) && 1100.0 === $logistics['weight'] && 30.0 === $logistics['length'] );
$snapshot = papelito_kit_snapshot( (int) $kit_a['product_id'], 1 );
papelito_assert( 'o snapshot congela o brinde do Kit', 1 === ( $snapshot['merchandise'][0]['id'] ?? 0 ) && 2 === ( $snapshot['merchandise'][0]['quantity'] ?? 0 ) );

echo "Scenario 9: editar o brinde altera todos os Kits que o referenciam\n";
papelito_kit_write( papelito_test_kit_payload( array( 'name' => 'Kit Smoking 2', 'merchandise' => array( array( 'merchandiseId' => 1, 'quantity' => 1 ) ) ) ) );
$edited = papelito_merchandise_update( 1, papelito_test_merchandise_payload( array( 'weight' => '0.10' ) ) );
papelito_assert( 'a edição conclui sem quebrar Kit', is_array( $edited ) && array() === $edited['unpublishedKits'] );
papelito_assert( 'Kit A passa a pesar 1200g', 1200.0 === papelito_kit_calculate_weight_grams( (int) $kit_a['id'] ) );
$kit_c_id = array_key_last( $wpdb->kits );
papelito_assert( 'Kit Smoking 2 usa o peso novo com a própria quantidade', 1100.0 === papelito_kit_calculate_weight_grams( $kit_c_id ) );
papelito_assert( 'invalida o cache público dos Kits', in_array( 'papelito_kits_public_v2', $GLOBALS['pap_deleted_transients'], true ) );

echo "Scenario 10: alteração que quebra Kit publicado exige confirmação\n";
$breaking_payload = papelito_test_merchandise_payload( array( 'weight' => '30' ) );
$refused          = papelito_merchandise_update( 1, $breaking_payload );
papelito_assert( 'devolve 409 pedindo confirmação', is_wp_error( $refused ) && 'papelito_merchandise_impact_confirmation_required' === $refused->code && 409 === $refused->data['status'] );
$impact = $refused->data['impact'];
papelito_assert( 'lista os Kits afetados', 2 === count( $impact['affectedKits'] ) );
papelito_assert( 'lista os Kits que deixariam de atender à publicação', 2 === count( $impact['breakingKits'] ) );
papelito_assert( 'nomeia os Kits para o admin', 'Kit Premium' === $impact['breakingKits'][0]['name'] );
papelito_assert( 'nada foi gravado sem confirmação', 0.1 === (float) papelito_merchandise_get( 1 )['weight'] );

echo "Scenario 11: confirmação explícita aplica e despublica o que quebrou\n";
$confirmed = papelito_merchandise_update( 1, array_merge( $breaking_payload, array( 'confirmImpact' => true ) ) );
papelito_assert( 'a alteração é aplicada', is_array( $confirmed ) && 30.0 === (float) papelito_merchandise_get( 1 )['weight'] );
papelito_assert( 'informa os Kits despublicados', 2 === count( $confirmed['unpublishedKits'] ) );
papelito_assert( 'o produto comercial vira rascunho', 'draft' === $GLOBALS['pap_products'][ (int) $kit_a['product_id'] ]->get_status() );

echo "Scenario 12: alteração sem efeito logístico não pede confirmação\n";
$GLOBALS['pap_deleted_transients'] = array();
$renamed                           = papelito_merchandise_update( 1, papelito_test_merchandise_payload( array( 'name' => 'Piteira Renomeada', 'weight' => '30' ) ) );
papelito_assert( 'renomear passa direto', is_array( $renamed ) && 'Piteira Renomeada' === papelito_merchandise_get( 1 )['name'] );
papelito_assert( 'não reavalia Kit à toa', array() === $renamed['unpublishedKits'] && array() === $GLOBALS['pap_deleted_transients'] );

echo "Scenario 13: falha de escrita não deixa o Kit com composição parcial\n";
$wpdb->queries          = array();
$wpdb->fail_insert_on   = array( 'wp_papelito_kit_merchandise_items' );
$broken_write           = papelito_kit_write(
	papelito_test_kit_payload(
		array(
			'name'        => 'Kit Escrita Falha',
			'status'      => 'draft',
			'merchandise' => array( array( 'merchandiseId' => 2, 'quantity' => 1 ) ),
		)
	)
);
$wpdb->fail_insert_on = array();
papelito_assert( 'insert que falha vira erro explícito', is_wp_error( $broken_write ) && 'papelito_kit_merchandise_write_failed' === $broken_write->code );
papelito_assert( 'a transação é desfeita', in_array( 'ROLLBACK', $wpdb->queries, true ) && ! in_array( 'COMMIT', $wpdb->queries, true ) );

echo "Scenario 14: vínculo para brinde que sumiu é recusado sob lock\n";
$missing_link = papelito_kit_persist_merchandise( 1, array( array( 'id' => 4242, 'quantity' => 1 ) ) );
papelito_assert( 'lock não encontra a linha e devolve 409', is_wp_error( $missing_link ) && 'papelito_kit_merchandise_not_found' === $missing_link->code && 409 === $missing_link->data['status'] );

echo "Scenario 15: confirmação só aceita booleano verdadeiro\n";
$published_kit = papelito_kit_write(
	papelito_test_kit_payload(
		array(
			'name'        => 'Kit Confirmação',
			'merchandise' => array( array( 'merchandiseId' => 2, 'quantity' => 1 ) ),
		)
	)
);
papelito_assert( 'o Kit publicado é criado', is_array( $published_kit ) && ! is_wp_error( $published_kit ) );
$heavy_adesivo = papelito_test_merchandise_payload( array( 'name' => 'Adesivo Papelito', 'imageAttachmentId' => 72, 'weight' => '30' ) );
$string_false  = papelito_merchandise_update( 2, array_merge( $heavy_adesivo, array( 'confirmImpact' => 'false' ) ) );
papelito_assert( 'a string "false" não confirma', is_wp_error( $string_false ) && 'papelito_merchandise_impact_confirmation_required' === $string_false->code );
$truthy_number = papelito_merchandise_update( 2, array_merge( $heavy_adesivo, array( 'confirmImpact' => 1 ) ) );
papelito_assert( 'o número 1 não confirma', is_wp_error( $truthy_number ) && 'papelito_merchandise_impact_confirmation_required' === $truthy_number->code );
papelito_assert( 'nada foi gravado nas duas tentativas', 0.01 === (float) papelito_merchandise_get( 2 )['weight'] );

echo "Scenario 16: Kit que resiste à despublicação é relatado, não escondido\n";
$GLOBALS['pap_fail_product_save'] = array( (int) $published_kit['product_id'] );
$settled = papelito_merchandise_update( 2, array_merge( $heavy_adesivo, array( 'confirmImpact' => true ) ) );
$GLOBALS['pap_fail_product_save'] = array();
papelito_assert( 'a alteração do brinde é aplicada', is_array( $settled ) && 30.0 === (float) papelito_merchandise_get( 2 )['weight'] );
papelito_assert( 'o Kit que não despublicou aparece em failedKits', 1 === count( $settled['failedKits'] ) && 'Kit Confirmação' === $settled['failedKits'][0]['name'] );
papelito_assert( 'ele não é contado como despublicado', array() === $settled['unpublishedKits'] );
papelito_assert( 'Kit que não precisava de rebaixamento não polui o relato', 'skipped' === papelito_kit_demote_outcome( papelito_kit_get( (int) $kit_a['id'] ) ) );

papelito_test_result();
