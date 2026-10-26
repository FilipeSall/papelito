<?php
/**
 * Importação de produtos do admin: prévia sem gravar, confirmação e criação do lote.
 *
 * A prévia lê a planilha, analisa com as regras de `admin_product_import_rules.php` e guarda a
 * tabela lida num transient amarrado ao admin, devolvendo um token. A confirmação recebe só o
 * token e as decisões (quais duplicados criar, se aceita reimportar o mesmo arquivo): as linhas
 * nunca voltam do navegador, e a análise é refeita contra o catálogo do momento.
 *
 * Regra de ouro: um lote entra inteiro ou não entra. A criação pelo WooCommerce não é
 * transacional, então qualquer falha no meio apaga os produtos que o lote já tinha criado antes
 * de responder. Todo produto nasce rascunho — imagem não vem pela planilha e a vitrine exige —,
 * recebe o SKU `PPL-` pelo hook de `product_sku.php` e guarda o lote em `_papelito_import_lot`.
 *
 * Não altera produto existente nem estoque: estoque é do vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_PRODUCT_IMPORT_SESSION_TTL = 30 * MINUTE_IN_SECONDS;
const PAPELITO_PRODUCT_IMPORT_LOT_META    = '_papelito_import_lot';
const PAPELITO_PRODUCT_IMPORT_LINE_META   = '_papelito_import_line';

/**
 * Índice de subcategorias ativas de uma categoria, pelas chaves de `papelito_product_import_index_keys()`.
 * A primeira subcategoria que reclama uma chave fica com ela.
 *
 * @param int                 $category_id Categoria.
 * @param array<string,mixed> $terms       Saída de `papelito_product_import_terms()`.
 * @return array<string,array{id:int,slug:string,name:string}>
 */
function papelito_product_import_subcategory_index( int $category_id, array $terms ): array {
	$index = array();
	foreach ( papelito_subcategories_list( $category_id ) as $sub ) {
		$entry = array(
			'id'   => (int) $sub['id'],
			'slug' => (string) $sub['slug'],
			'name' => (string) $sub['name'],
		);
		foreach ( papelito_product_import_index_keys( array( $entry['slug'], $entry['name'] ), $terms ) as $key ) {
			$index[ $key ] ??= $entry;
		}
	}
	return $index;
}

/**
 * Produtos do catálogo por categoria e nome normalizado, para achar o "já existe".
 *
 * Conta qualquer produto que não esteja na lixeira: um rascunho com o mesmo nome também é
 * cadastro em andamento, e criar outro por cima é o acidente que a trava evita.
 *
 * A chave é o nome na forma canônica, para "Triturador Neon" encontrar "Dichavador Neon".
 *
 * @param array<string,mixed> $terms Saída de `papelito_product_import_terms()`.
 * @return array<int,array<string,array{id:int,sku:string,name:string}>>
 */
function papelito_product_import_catalog_index( array $terms ): array {
	global $wpdb;

	$tables = papelito_product_taxonomy_table_names();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Nome de tabela não vira placeholder; a consulta não tem entrada de usuário.
	$rows = $wpdb->get_results(
		"SELECT p.ID AS id, p.post_title AS name, c.category_id, m.meta_value AS sku
		FROM {$wpdb->posts} p
		INNER JOIN {$tables['product_category']} c ON c.product_id = p.ID
		LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
		WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private','future')",
		ARRAY_A
	);
	// phpcs:enable

	$index = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$index[ (int) $row['category_id'] ][ papelito_product_import_canonical( $row['name'], $terms ) ] ??= array(
			'id'   => (int) $row['id'],
			'sku'  => (string) $row['sku'],
			'name' => (string) $row['name'],
		);
	}
	return $index;
}

/**
 * Contexto da análise lido do banco: categorias ativas com subcategorias, coleções manuais e catálogo.
 *
 * @return array{terms:array<string,mixed>,categories:array<string,array<string,mixed>>,collections:string[],catalog:array<int,array<string,array<string,mixed>>>}
 */
function papelito_product_import_context(): array {
	$terms      = papelito_product_import_terms( papelito_vendor_code_import_dictionary() );
	$categories = array();
	foreach ( papelito_categories_list() as $category ) {
		$entry = array(
			'id'   => (int) $category['id'],
			'slug' => (string) $category['slug'],
			'name' => (string) $category['name'],
			'subs' => papelito_product_import_subcategory_index( (int) $category['id'], $terms ),
		);
		foreach ( papelito_product_import_index_keys( array( $entry['slug'], $entry['name'] ), $terms ) as $key ) {
			$categories[ $key ] ??= $entry;
		}
	}
	return array(
		'terms'       => $terms,
		'categories'  => $categories,
		'collections' => array_values( array_map( 'strval', papelito_curated_collections() ) ),
		'catalog'     => papelito_product_import_catalog_index( $terms ),
	);
}

/**
 * Chave do transient da prévia; o token nunca vai ao banco em claro.
 *
 * @param string $token Token devolvido ao painel.
 */
function papelito_product_import_session_key( string $token ): string {
	return 'papelito_pimp_' . hash( 'sha256', $token );
}

/**
 * Lê o arquivo, analisa e guarda a prévia. Nada é gravado no catálogo.
 *
 * @param int                 $user_id Admin que enviou.
 * @param array<string,mixed> $file    Arquivo do upload direto.
 * @return array<string,mixed>|WP_Error
 */
function papelito_product_import_preview_file( int $user_id, array $file ): array|WP_Error {
	$matrix = papelito_vendor_code_import_read_matrix( $file, 'papelito_product_import_csv_delimiter' );
	$table  = is_wp_error( $matrix ) ? $matrix : papelito_product_import_table_from_matrix( $matrix );
	if ( is_wp_error( $table ) ) {
		return $table;
	}

	$token    = wp_generate_password( 40, false );
	$hash     = (string) hash_file( 'sha256', (string) $file['tmp_name'] );
	$name     = sanitize_file_name( (string) ( $file['name'] ?? 'planilha.csv' ) );
	$analysis = papelito_product_import_analyze( $table, papelito_product_import_context() );

	set_transient(
		papelito_product_import_session_key( $token ),
		array(
			'user_id' => $user_id,
			'name'    => $name,
			'hash'    => $hash,
			'table'   => $table,
		),
		PAPELITO_PRODUCT_IMPORT_SESSION_TTL
	);

	return array(
		'token'           => $token,
		'fileName'        => $name,
		'headers'         => $table['headers'],
		'columns'         => $table['columns'],
		'alreadyImported' => papelito_report_operation_find_import( $hash ),
		'summary'         => $analysis['summary'],
		'rows'            => papelito_product_import_public_rows( $analysis['rows'], $table['rows'] ),
	);
}

/**
 * Linhas da prévia no formato público, cada uma com as células cruas do arquivo.
 *
 * @param array<int,array<string,mixed>> $rows Linhas analisadas.
 * @param array<int,array<string,mixed>> $raw  Linhas lidas, na mesma ordem.
 * @return array<int,array<string,mixed>>
 */
function papelito_product_import_public_rows( array $rows, array $raw ): array {
	$public = array();
	foreach ( $rows as $index => $row ) {
		$public[] = papelito_product_import_public_row( $row, (array) ( $raw[ $index ]['cells'] ?? array() ) );
	}
	return $public;
}

/**
 * Prévia guardada do admin; recusa token de outro usuário ou expirado.
 *
 * @param int    $user_id Admin autenticado.
 * @param string $token   Token da prévia.
 * @return array<string,mixed>|WP_Error
 */
function papelito_product_import_load_session( int $user_id, string $token ): array|WP_Error {
	$session = '' === $token ? false : get_transient( papelito_product_import_session_key( $token ) );
	if ( ! is_array( $session ) || (int) ( $session['user_id'] ?? 0 ) !== $user_id ) {
		return new WP_Error( 'papelito_product_import_expired', 'A conferência expirou. Envie a planilha de novo.', array( 'status' => 410 ) );
	}
	return $session;
}

/**
 * Linhas que entram no lote: novas, incompletas e os duplicados que o admin pediu para criar.
 *
 * @param array<int,array<string,mixed>> $rows         Linhas analisadas.
 * @param int[]                          $create_lines Linhas duplicadas confirmadas pelo admin.
 * @return array<int,array<string,mixed>>
 */
function papelito_product_import_selected_rows( array $rows, array $create_lines ): array {
	return array_values(
		array_filter(
			$rows,
			static fn ( array $row ): bool => in_array( $row['status'], array( PAPELITO_PRODUCT_IMPORT_STATUS_NEW, PAPELITO_PRODUCT_IMPORT_STATUS_PARTLY ), true )
				|| ( PAPELITO_PRODUCT_IMPORT_STATUS_DUPE === $row['status'] && in_array( (int) $row['line'], $create_lines, true ) )
		)
	);
}

/**
 * Ids das tags pelo nome, criando as que faltam.
 *
 * @param string[] $names Nomes da célula.
 * @return int[]
 */
function papelito_product_import_tag_ids( array $names ): array {
	$ids = array();
	foreach ( $names as $name ) {
		$found = term_exists( $name, 'product_tag' );
		$term  = $found ? $found : wp_insert_term( $name, 'product_tag' );
		if ( is_array( $term ) && isset( $term['term_id'] ) ) {
			$ids[] = (int) $term['term_id'];
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Monta o produto simples em rascunho com preço, frete, descrições e tags. Não salva.
 *
 * @param array<string,mixed> $row Linha analisada.
 */
function papelito_product_import_build_product( array $row ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( (string) $row['name'] );
	$product->set_status( 'draft' );
	$product->set_regular_price( wc_format_decimal( (float) $row['price'], 2 ) );
	if ( null !== $row['salePrice'] ) {
		$product->set_sale_price( wc_format_decimal( (float) $row['salePrice'], 2 ) );
	}
	$setters = array(
		'peso_kg'        => 'set_weight',
		'comprimento_cm' => 'set_length',
		'largura_cm'     => 'set_width',
		'altura_cm'      => 'set_height',
	);
	foreach ( $setters as $field => $setter ) {
		if ( null !== $row['shipping'][ $field ] ) {
			$product->{$setter}( wc_format_decimal( (float) $row['shipping'][ $field ] ) );
		}
	}
	$product->set_short_description( wp_kses_post( (string) $row['shortDescription'] ) );
	$product->set_description( wp_kses_post( (string) $row['description'] ) );
	$product->set_tag_ids( papelito_product_import_tag_ids( (array) $row['tags'] ) );
	return $product;
}

/**
 * Cria um produto do lote e vincula a taxonomia; quando a taxonomia recusa, apaga o produto
 * recém-criado antes de devolver o erro, para quem chama só precisar desfazer os anteriores.
 *
 * @param array<string,mixed> $row Linha analisada.
 * @param string              $lot Código do lote.
 * @return array{line:int,productId:int,sku:string,name:string,incomplete:bool}|WP_Error
 */
function papelito_product_import_create_product( array $row, string $lot ): array|WP_Error {
	$product_id = papelito_product_import_build_product( $row )->save();
	if ( $product_id <= 0 ) {
		return new WP_Error( 'papelito_product_import_rejected', sprintf( 'o WooCommerce recusou a linha %d.', (int) $row['line'] ) );
	}
	update_post_meta( $product_id, PAPELITO_PRODUCT_IMPORT_LOT_META, $lot );
	update_post_meta( $product_id, PAPELITO_PRODUCT_IMPORT_LINE_META, (int) $row['line'] );

	$taxonomy = papelito_product_replace_taxonomy(
		$product_id,
		array(
			'categoryId'     => (int) $row['category']['id'],
			'subcategoryIds' => $row['subcategoryIds'],
			'collections'    => $row['collections'],
		)
	);
	if ( is_wp_error( $taxonomy ) ) {
		papelito_product_import_rollback( array( $product_id ) );
		return new WP_Error( 'papelito_product_import_rejected', sprintf( 'linha %d — %s', (int) $row['line'], $taxonomy->get_error_message() ) );
	}

	return array(
		'line'       => (int) $row['line'],
		'productId'  => (int) $product_id,
		'sku'        => (string) get_post_meta( $product_id, '_sku', true ),
		'name'       => (string) $row['name'],
		'incomplete' => (bool) $row['incomplete'],
	);
}

/**
 * Apaga em definitivo os produtos criados por um lote que não terminou.
 *
 * @param int[] $product_ids Produtos já criados.
 */
function papelito_product_import_rollback( array $product_ids ): void {
	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product ) {
			$product->delete( true );
		}
	}
}

/**
 * Cria todas as linhas do lote ou nenhuma: a primeira recusa desfaz o que já foi criado.
 *
 * @param array<int,array<string,mixed>> $rows Linhas selecionadas.
 * @param string                         $lot  Código do lote.
 * @return array<int,array<string,mixed>>|WP_Error Produtos criados, na ordem do arquivo.
 */
function papelito_product_import_create_lot( array $rows, string $lot ): array|WP_Error {
	if ( function_exists( 'wc_set_time_limit' ) ) {
		wc_set_time_limit( 0 );
	}
	$created = array();
	$failure = null;
	try {
		foreach ( $rows as $row ) {
			$product = papelito_product_import_create_product( $row, $lot );
			if ( is_wp_error( $product ) ) {
				$failure = $product->get_error_message();
				break;
			}
			$created[] = $product;
		}
	} catch ( Throwable $unused ) {
		$failure = 'erro inesperado ao criar os produtos.';
	}
	if ( null === $failure ) {
		return $created;
	}
	papelito_product_import_rollback( array_column( $created, 'productId' ) );
	return new WP_Error(
		'papelito_product_import_failed',
		sprintf( 'Nenhum produto foi criado: %s O lote foi desfeito e a planilha pode ser enviada de novo.', $failure ),
		array(
			'status' => 500,
			'stage'  => 'persist',
		)
	);
}

/**
 * Linhas que ficaram de fora, com o motivo para o relatório.
 *
 * @param array<int,array<string,mixed>> $rows     Linhas analisadas.
 * @param int[]                          $selected Linhas que entraram no lote.
 * @return array{ignored:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
 */
function papelito_product_import_left_out( array $rows, array $selected ): array {
	$left = array(
		'ignored' => array(),
		'errors'  => array(),
	);
	foreach ( $rows as $row ) {
		if ( in_array( (int) $row['line'], $selected, true ) ) {
			continue;
		}
		$entry = array(
			'line'   => (int) $row['line'],
			'name'   => (string) $row['name'],
			'status' => (string) $row['status'],
			'issues' => $row['issues'],
		);
		$left[ PAPELITO_PRODUCT_IMPORT_STATUS_ERROR === $row['status'] ? 'errors' : 'ignored' ][] = $entry;
	}
	return $left;
}

/**
 * Confirma a prévia: reanalisa, aplica as decisões, cria o lote e registra a operação.
 *
 * @param int                 $user_id Admin autenticado.
 * @param array<string,mixed> $request token, createLines, acknowledgeRepeat (já validados).
 * @return array<string,mixed>|WP_Error
 */
function papelito_product_import_confirm( int $user_id, array $request ): array|WP_Error {
	$session = papelito_product_import_load_session( $user_id, (string) $request['token'] );
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	$already = papelito_report_operation_find_import( (string) $session['hash'] );
	if ( null !== $already && ! $request['acknowledgeRepeat'] ) {
		return new WP_Error( 'papelito_product_import_repeated_file', sprintf( 'Este arquivo já foi importado no lote %s. Confirme que quer importar de novo.', $already['lot'] ), array( 'status' => 409 ) );
	}
	$analysis = papelito_product_import_analyze( $session['table'], papelito_product_import_context() );
	$selected = papelito_product_import_selected_rows( $analysis['rows'], $request['createLines'] );
	if ( ! $selected ) {
		return new WP_Error( 'papelito_product_import_nothing_to_create', 'Nenhuma linha da planilha pode ser cadastrada.', array( 'status' => 422 ) );
	}
	delete_transient( papelito_product_import_session_key( (string) $request['token'] ) );

	return papelito_product_import_run_lot( $session, $analysis['rows'], $selected );
}

/**
 * Abre a operação, cria o lote e fecha a operação com o desfecho.
 *
 * @param array<string,mixed>            $session  Prévia guardada.
 * @param array<int,array<string,mixed>> $rows     Todas as linhas analisadas.
 * @param array<int,array<string,mixed>> $selected Linhas que entram.
 * @return array<string,mixed>|WP_Error
 */
function papelito_product_import_run_lot( array $session, array $rows, array $selected ): array|WP_Error {
	$operation = papelito_report_operation_start(
		array(
			'kind'      => 'import',
			'report'    => 'products',
			'title'     => (string) $session['name'],
			'params'    => array( 'fileName' => (string) $session['name'] ),
			'file_hash' => (string) $session['hash'],
		)
	);
	$lot       = papelito_report_operation_lot_code( $operation );
	$created   = papelito_product_import_create_lot( $selected, $lot );
	if ( is_wp_error( $created ) ) {
		papelito_report_operation_finish(
			$operation,
			array(
				'status' => PAPELITO_REPORT_OPERATION_FAILED,
				'rows'   => count( $rows ),
				'error'  => $created->get_error_message(),
			)
		);
		return $created;
	}

	$left   = papelito_product_import_left_out( $rows, array_column( $selected, 'line' ) );
	$result = array(
		'lot'         => $lot,
		'operationId' => $operation,
		'created'     => $created,
		'ignored'     => $left['ignored'],
		'errors'      => $left['errors'],
	);
	papelito_report_operation_finish(
		$operation,
		array(
			'status'  => $left['errors'] ? PAPELITO_REPORT_OPERATION_PARTIAL : PAPELITO_REPORT_OPERATION_DONE,
			'rows'    => count( $rows ),
			'created' => count( $created ),
			'ignored' => count( $left['ignored'] ),
			'errors'  => count( $left['errors'] ),
			'lot'     => $lot,
			'detail'  => $result,
		)
	);
	return $result;
}
