<?php
/**
 * Regressão do catálogo global de brindes.
 *
 * Brinde é entidade própria: nasce fora de qualquer Kit, é editável de um lugar
 * só e não pode ser excluído enquanto algum Kit o referenciar.
 *
 * Usage: php tests/test-merchandise-catalog.php
 *
 * @package Papelito
 */

require __DIR__ . '/support/merchandise_env.php';

$GLOBALS['pap_products'][101] = new WC_Product( 101, 'Seda King Size', '0.5' );

echo "Scenario 1: criar brinde no catálogo global\n";
$created = papelito_merchandise_create( papelito_test_merchandise_payload() );
papelito_assert( 'a criação devolve a linha persistida', is_array( $created ) && 1 === (int) $created['id'] );
papelito_assert( 'guarda nome, peso e dimensões', 'Piteira Especial' === $created['name'] && 0.05 === (float) $created['weight'] && 14.0 === (float) $created['length'] );
papelito_assert( 'não nasce preso a Kit nenhum', ! array_key_exists( 'kit_id', $created ) );
papelito_assert( 'não tem preço, SKU nem estoque', ! array_key_exists( 'price', $created ) && ! array_key_exists( 'sku', $created ) && ! array_key_exists( 'stock', $created ) );

echo "Scenario 2: payload inválido é recusado antes de gravar\n";
$no_name = papelito_merchandise_create( papelito_test_merchandise_payload( array( 'name' => '  ' ) ) );
papelito_assert( 'nome vazio devolve 422', is_wp_error( $no_name ) && 'papelito_merchandise_name_invalid' === $no_name->code && 422 === $no_name->data['status'] );
$no_image = papelito_merchandise_create( papelito_test_merchandise_payload( array( 'imageAttachmentId' => 0 ) ) );
papelito_assert( 'imagem ausente devolve 422', is_wp_error( $no_image ) && 'papelito_merchandise_image_required' === $no_image->code );
$no_weight = papelito_merchandise_create( papelito_test_merchandise_payload( array( 'weight' => '0' ) ) );
papelito_assert( 'peso zero devolve 422', is_wp_error( $no_weight ) && 'papelito_merchandise_weight_invalid' === $no_weight->code );
$too_long = papelito_merchandise_create( papelito_test_merchandise_payload( array( 'length' => '140' ) ) );
papelito_assert( 'dimensão acima do limite devolve 422', is_wp_error( $too_long ) && 'papelito_merchandise_dimensions_invalid' === $too_long->code );
papelito_assert( 'nenhuma recusa gravou linha', 1 === count( $wpdb->merchandise ) );

echo "Scenario 3: editar o brinde muda a fonte única\n";
$updated = papelito_merchandise_update( 1, papelito_test_merchandise_payload( array( 'name' => 'Piteira Especial XL', 'weight' => '0.08' ) ) );
papelito_assert( 'a edição conclui', is_array( $updated ) && ! is_wp_error( $updated ) );
papelito_assert( 'o catálogo passa a responder o valor novo', 0.08 === (float) papelito_merchandise_get( 1 )['weight'] );
papelito_assert( 'sem Kits envolvidos, nada é despublicado', array() === $updated['unpublishedKits'] );

echo "Scenario 3.1: trocar a imagem solta o anexo antigo\n";
$GLOBALS['pap_deleted_attachments'] = array();
papelito_merchandise_update( 1, papelito_test_merchandise_payload( array( 'name' => 'Piteira Especial XL', 'weight' => '0.08', 'imageAttachmentId' => papelito_test_attachment( 81 ) ) ) );
papelito_assert( 'a linha passa a apontar para a imagem nova', 81 === (int) papelito_merchandise_get( 1 )['image_attachment_id'] );
papelito_assert( 'o anexo substituído é apagado', in_array( 71, $GLOBALS['pap_deleted_attachments'], true ) );
$GLOBALS['pap_referenced_media'] = array( 81 );
papelito_merchandise_update( 1, papelito_test_merchandise_payload( array( 'name' => 'Piteira Especial XL', 'weight' => '0.08' ) ) );
papelito_assert( 'anexo antigo ainda referenciado é preservado', ! in_array( 81, $GLOBALS['pap_deleted_attachments'], true ) );
$GLOBALS['pap_referenced_media'] = array();
$GLOBALS['pap_deleted_attachments'] = array();

echo "Scenario 4: brinde sem uso pode ser excluído e leva a imagem exclusiva\n";
$disposable = papelito_merchandise_create( papelito_test_merchandise_payload( array( 'name' => 'Adesivo Papelito', 'imageAttachmentId' => papelito_test_attachment( 72 ) ) ) );
$deleted    = papelito_merchandise_delete( (int) $disposable['id'] );
papelito_assert( 'a exclusão conclui', is_array( $deleted ) && true === $deleted['deleted'] );
papelito_assert( 'a linha sai do catálogo', null === papelito_merchandise_get( (int) $disposable['id'] ) );
papelito_assert( 'a imagem exclusiva é apagada', true === $deleted['imageDeleted'] && in_array( 72, $GLOBALS['pap_deleted_attachments'], true ) );

echo "Scenario 5: imagem usada em outro conteúdo sobrevive à exclusão\n";
$shared_image                    = papelito_test_attachment( 73 );
$GLOBALS['pap_referenced_media'] = array( $shared_image );
$shared                          = papelito_merchandise_create( papelito_test_merchandise_payload( array( 'name' => 'Isqueiro', 'imageAttachmentId' => $shared_image ) ) );
$deleted_shared                  = papelito_merchandise_delete( (int) $shared['id'] );
papelito_assert( 'exclui o brinde', true === $deleted_shared['deleted'] );
papelito_assert( 'preserva a imagem referenciada', false === $deleted_shared['imageDeleted'] && ! in_array( 73, $GLOBALS['pap_deleted_attachments'], true ) );
$GLOBALS['pap_referenced_media'] = array();

echo "Scenario 6: brinde em uso não pode ser excluído\n";
$kit = papelito_kit_write( papelito_test_kit_payload( array( 'merchandise' => array( array( 'merchandiseId' => 1, 'quantity' => 2 ) ) ) ) );
papelito_assert( 'o Kit é criado com o brinde do catálogo', is_array( $kit ) && ! is_wp_error( $kit ) );
$blocked = papelito_merchandise_delete( 1 );
papelito_assert( 'a exclusão devolve 409', is_wp_error( $blocked ) && 'papelito_merchandise_in_use' === $blocked->code && 409 === $blocked->data['status'] );
papelito_assert( 'o erro nomeia o Kit que ainda usa', 'Kit Premium' === ( $blocked->data['kits'][0]['name'] ?? '' ) );
papelito_assert( 'o brinde continua no catálogo', is_array( papelito_merchandise_get( 1 ) ) );

echo "Scenario 7: listagem administrativa mostra o uso de cada brinde\n";
$unused   = papelito_merchandise_create( papelito_test_merchandise_payload( array( 'name' => 'Adesivo Novo', 'imageAttachmentId' => papelito_test_attachment( 74 ) ) ) );
$response = papelito_merchandise_admin_list();
$items    = $response->data['items'];
$by_name  = array_column( $items, null, 'name' );
papelito_assert( 'lista os dois brindes vivos', 2 === count( $items ) );
papelito_assert( 'ordena por nome', 'Adesivo Novo' === $items[0]['name'] );
papelito_assert( 'conta os Kits que usam', 1 === $by_name['Piteira Especial XL']['kitCount'] );
papelito_assert( 'expõe quais Kits usam', 'Kit Premium' === $by_name['Piteira Especial XL']['kits'][0]['name'] );
papelito_assert( 'marca o brinde sem uso', 0 === $by_name['Adesivo Novo']['kitCount'] && array() === $by_name['Adesivo Novo']['kits'] );
papelito_assert( 'resolve a URL da imagem', str_contains( (string) $by_name['Adesivo Novo']['imageUrl'], '74' ) );

echo "Scenario 8: contrato REST administrativo\n";
$create_response = papelito_merchandise_admin_create( new WP_REST_Request( array(), papelito_test_merchandise_payload( array( 'name' => 'Filtro', 'imageAttachmentId' => papelito_test_attachment( 75 ) ) ) ) );
papelito_assert( 'POST devolve 201 com o brinde', $create_response instanceof WP_REST_Response && 201 === $create_response->status && 'Filtro' === $create_response->data['merchandise']['name'] );
$rest_id     = (int) $create_response->data['merchandise']['id'];
$get_response = papelito_merchandise_admin_get( new WP_REST_Request( array( 'id' => $rest_id ) ) );
papelito_assert( 'GET devolve 200 com o brinde', $get_response instanceof WP_REST_Response && 200 === $get_response->status && $rest_id === $get_response->data['merchandise']['id'] );
$update_response = papelito_merchandise_admin_update( new WP_REST_Request( array( 'id' => $rest_id ), papelito_test_merchandise_payload( array( 'name' => 'Filtro Longo', 'imageAttachmentId' => 75 ) ) ) );
papelito_assert( 'PUT devolve 200 e o estado novo', $update_response instanceof WP_REST_Response && 'Filtro Longo' === $update_response->data['merchandise']['name'] );
papelito_assert( 'PUT informa Kits despublicados', array() === $update_response->data['unpublishedKits'] );
$delete_response = papelito_merchandise_admin_delete( new WP_REST_Request( array( 'id' => $rest_id ) ) );
papelito_assert( 'DELETE devolve 200', $delete_response instanceof WP_REST_Response && true === $delete_response->data['deleted'] );
$missing = papelito_merchandise_admin_get( new WP_REST_Request( array( 'id' => 9999 ) ) );
papelito_assert( 'id inexistente devolve 404', is_wp_error( $missing ) && 404 === $missing->data['status'] );

echo "Scenario 9: o catálogo é área administrativa\n";
papelito_assert( 'admin passa', true === papelito_merchandise_require_admin() );
$GLOBALS['pap_is_admin'] = false;
$forbidden               = papelito_merchandise_require_admin();
papelito_assert( 'não-admin recebe 403', is_wp_error( $forbidden ) && 403 === $forbidden->data['status'] );
$GLOBALS['pap_is_admin'] = true;

papelito_test_result();
