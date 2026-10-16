<?php
/**
 * Importação em massa do código do vendor a partir da planilha do ERP.
 *
 * Duas etapas, ambas no escopo do vendor autenticado: a prévia lê o arquivo,
 * associa cada linha ao catálogo e não grava nada; a confirmação recebe só as
 * linhas que o vendor decidiu aplicar, refaz a associação do zero (o navegador
 * não é fonte de verdade) e grava numa transação única, linha a linha contra o
 * valor visto na prévia. Grava só em `papelito_vendor_item_settings`, pelo mesmo
 * `papelito_vendor_item_settings_save_code()` da edição manual — nunca no SKU
 * da Papelito, nunca para outro vendor e nunca cria produto.
 *
 * Regras de associação: `vendor_code_import_matcher.php`. Contrato REST:
 * `docs/integration-contracts.md`, "Importação de códigos do vendor".
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Erro marcado com a etapa (`match` ou `persist`) para o painel explicar onde falhou.
 *
 * @param string $code    Código do erro.
 * @param string $message Mensagem exibível.
 * @param int    $status  Status HTTP.
 * @param string $stage   Etapa da importação.
 */
function papelito_vendor_code_import_error( string $code, string $message, int $status, string $stage ): WP_Error {
	return new WP_Error(
		$code,
		$message,
		array(
			'status' => $status,
			'stage'  => $stage,
		)
	);
}

/**
 * Produtos e variações publicados, com SKU da Papelito e descrição.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_vendor_code_import_catalog_posts(): array {
	global $wpdb;
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID AS id, p.post_parent AS parent_id, p.post_type AS post_type, p.post_title AS title, p.post_content AS content, sku.meta_value AS sku
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
			WHERE p.post_type IN (%s, %s) AND p.post_status = %s
			ORDER BY p.ID",
			'product',
			'product_variation',
			'publish'
		),
		ARRAY_A
	);
}

/**
 * Valores de atributo das variações (`attribute_pa_cor` etc.) como palavras normalizadas.
 *
 * @param int[] $variation_ids Variações publicadas.
 * @return array<int,string>
 */
function papelito_vendor_code_import_variation_labels( array $variation_ids ): array {
	global $wpdb;
	if ( ! $variation_ids ) {
		return array();
	}
	$placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );
	$rows         = (array) $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN contém apenas placeholders gerados.
		$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND post_id IN ({$placeholders}) ORDER BY meta_id", array_merge( array( $wpdb->esc_like( 'attribute_' ) . '%' ), $variation_ids ) ),
		ARRAY_A
	);
	$labels = array();
	foreach ( $rows as $row ) {
		$words         = papelito_vendor_code_import_words( papelito_vendor_code_import_normalize( str_replace( '-', ' ', (string) $row['meta_value'] ) ) );
		$id            = (int) $row['post_id'];
		$labels[ $id ] = trim( ( $labels[ $id ] ?? '' ) . ' ' . implode( ' ', $words ) );
	}
	return $labels;
}

/**
 * Agrupa o catálogo em famílias: produto simples ou kit é alvo de si mesmo; produto variável, das variações.
 *
 * @param array<int,array<string,mixed>> $posts  Posts do catálogo.
 * @param array<int,string>              $labels Rótulo de atributo por variação.
 * @return array<int,array<string,mixed>>
 */
function papelito_vendor_code_import_group_families( array $posts, array $labels ): array {
	$families = array();
	foreach ( $posts as $post ) {
		if ( 'product' === $post['post_type'] ) {
			$families[ (int) $post['id'] ] = array(
				'family_id' => (int) $post['id'],
				'name'      => (string) $post['title'],
				'content'   => (string) $post['content'],
				'self'      => papelito_vendor_code_import_target( $post, '' ),
				'targets'   => array(),
			);
		}
	}
	foreach ( $posts as $post ) {
		$parent = (int) $post['parent_id'];
		if ( 'product_variation' === $post['post_type'] && isset( $families[ $parent ] ) ) {
			$families[ $parent ]['targets'][] = papelito_vendor_code_import_target( $post, $labels[ (int) $post['id'] ] ?? '' );
		}
	}
	foreach ( $families as $id => $family ) {
		$families[ $id ]['targets'] = $family['targets'] ? $family['targets'] : array( $family['self'] );
		unset( $families[ $id ]['self'] );
	}
	return $families;
}

/**
 * Alvo de gravação: o item da linha de estoque.
 *
 * @param array<string,mixed> $post  Post do catálogo.
 * @param string              $color Rótulo da variação; vazio para produto simples.
 * @return array{product_id:int,name:string,papelito_sku:?string,color:string,current_code:?string}
 */
function papelito_vendor_code_import_target( array $post, string $color ): array {
	return array(
		'product_id'   => (int) $post['id'],
		'name'         => (string) $post['title'],
		'papelito_sku' => null === $post['sku'] || '' === $post['sku'] ? null : (string) $post['sku'],
		'color'        => $color,
		'current_code' => null,
	);
}

/**
 * Catálogo pronto para associação, com o código atual do vendor em cada alvo.
 *
 * O universo é o mesmo de `/vendor/estoque`: todo produto, variação e kit
 * publicado, inclusive os que o vendor nunca configurou.
 *
 * @param int   $vendor_id  Vendor autenticado.
 * @param array $dictionary Dicionário.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_vendor_code_import_families( int $vendor_id, array $dictionary ): array|WP_Error {
	$posts         = papelito_vendor_code_import_catalog_posts();
	$variation_ids = array();
	foreach ( $posts as $post ) {
		if ( 'product_variation' === $post['post_type'] ) {
			$variation_ids[] = (int) $post['id'];
		}
	}
	$families = papelito_vendor_code_import_group_families( $posts, papelito_vendor_code_import_variation_labels( $variation_ids ) );
	$codes    = papelito_vendor_item_settings_get_many( $vendor_id, array_column( $posts, 'id' ) );
	if ( is_wp_error( $codes ) ) {
		return papelito_vendor_code_import_error( 'papelito_vendor_code_import_storage_error', 'Não foi possível ler os códigos atuais.', 500, 'match' );
	}
	foreach ( $families as $id => $family ) {
		$families[ $id ]['features'] = papelito_vendor_code_import_family_features( $family, $dictionary );
		foreach ( $family['targets'] as $index => $target ) {
			$families[ $id ]['targets'][ $index ]['current_code'] = $codes[ $target['product_id'] ]['vendor_code'] ?? null;
		}
		unset( $families[ $id ]['content'] );
	}
	return array_values( $families );
}

/**
 * Perfil do produto: título pelo dicionário, quantidade e dimensões pela descrição.
 *
 * @param array $family     Família com `name` e `content`.
 * @param array $dictionary Dicionário.
 * @return array<string,mixed>
 */
function papelito_vendor_code_import_family_features( array $family, array $dictionary ): array {
	$features          = papelito_vendor_code_import_features( (string) $family['name'], $dictionary );
	$numbers           = papelito_vendor_code_import_catalog_numbers( (string) $family['content'] );
	$features['qty'] ??= $numbers['qty'];
	$features['dim']   = array_values( array_unique( array_merge( $features['dim'], $numbers['dim'] ) ) );
	return $features;
}

/**
 * Valor que será gravado no item: o código do ERP, ou null quando ele é o próprio SKU da Papelito.
 *
 * Espelha a edição manual: para o vendor existe um SKU só, e repetir o da
 * Papelito equivale a não ter código próprio.
 *
 * @param string $code   Código normalizado.
 * @param array  $target Alvo com `papelito_sku`.
 */
function papelito_vendor_code_import_stored_code( string $code, array $target ): ?string {
	return $code === $target['papelito_sku'] ? null : $code;
}

/**
 * Anota em cada alvo o código novo e diz quantos itens mudariam.
 *
 * @param array  $view Família com `targets`.
 * @param string $code Código normalizado.
 * @return array{view:array,changes:int}
 */
function papelito_vendor_code_import_with_new_code( array $view, string $code ): array {
	$changes = 0;
	foreach ( $view['targets'] as $index => $target ) {
		$new                                   = papelito_vendor_code_import_stored_code( $code, $target );
		$view['targets'][ $index ]['new_code'] = $new;
		$changes                              += $new === $target['current_code'] ? 0 : 1;
		unset( $view['targets'][ $index ]['color'] );
	}
	return array(
		'view'    => $view,
		'changes' => $changes,
	);
}

/**
 * Normaliza o código e associa a linha ao catálogo.
 *
 * @param array $row        Linha da planilha.
 * @param array $families   Catálogo.
 * @param array $dictionary Dicionário.
 * @return array<string,mixed>
 */
function papelito_vendor_code_import_evaluate( array $row, array $families, array $dictionary ): array {
	$code   = papelito_vendor_item_settings_normalize_code( (string) ( $row['code'] ?? '' ) );
	$result = array(
		'line'        => (int) ( $row['line'] ?? 0 ),
		'description' => sanitize_text_field( (string) ( $row['description'] ?? '' ) ),
		'subgroup'    => sanitize_text_field( (string) ( $row['subgroup'] ?? '' ) ),
		'code'        => is_string( $code ) ? $code : sanitize_text_field( (string) ( $row['code'] ?? '' ) ),
		'status'      => 'invalid',
		'match'       => 'invalid',
		'reason'      => '',
		'family'      => null,
		'candidates'  => array(),
		'changes'     => 0,
	);
	if ( ! is_string( $code ) ) {
		$result['reason'] = is_wp_error( $code ) ? $code->get_error_message() : 'Linha sem código do produto.';
		return $result;
	}
	if ( '' === $result['description'] ) {
		$result['reason'] = 'Linha sem descrição do produto.';
		return $result;
	}
	$match            = papelito_vendor_code_import_match_row( $result, $families, $dictionary );
	$result['status'] = $match['match'];
	$result['match']  = $match['match'];
	$result['reason'] = $match['reason'];
	if ( null !== $match['family'] ) {
		$annotated         = papelito_vendor_code_import_with_new_code( $match['family'], $code );
		$result['family']  = $annotated['view'];
		$result['changes'] = $annotated['changes'];
	}
	foreach ( $match['candidates'] as $candidate ) {
		$result['candidates'][] = papelito_vendor_code_import_with_new_code( $candidate, $code )['view'];
	}
	return $result;
}

/**
 * Marca conflitos e depois linhas repetidas entre as linhas que vão gravar.
 *
 * Conflito: dois códigos diferentes para o mesmo item, ou o mesmo código para
 * produtos diferentes. Todas as linhas envolvidas no conflito ficam de fora;
 * nenhuma é escolhida por ordem do arquivo. O conflito é apurado antes da
 * repetição: mesma descrição e mesmo código em subgrupos que resolvem para
 * famílias diferentes é conflito, não repetição da primeira linha.
 *
 * @param array<int,array> $rows     Linhas avaliadas.
 * @param callable         $eligible Diz se a linha participa (tem família resolvida e vai gravar).
 * @return array<int,array>
 */
function papelito_vendor_code_import_flag_conflicts( array $rows, callable $eligible ): array {
	$by_item = array();
	$by_code = array();
	foreach ( $rows as $index => $row ) {
		if ( ! $eligible( $row ) ) {
			continue;
		}
		foreach ( $row['family']['targets'] as $target ) {
			$by_item[ $target['product_id'] ][ $row['code'] ][] = $index;
		}
		$by_code[ $row['code'] ][ $row['family']['family_id'] ][] = $index;
	}
	$conflicts = array_merge(
		papelito_vendor_code_import_conflicting_rows( $by_item ),
		papelito_vendor_code_import_conflicting_rows( $by_code )
	);
	foreach ( array_unique( $conflicts ) as $index ) {
		$rows[ $index ]['status'] = 'conflict';
		$rows[ $index ]['reason'] = 'Outra linha da planilha disputa este produto ou este código. Mantenha só uma delas.';
	}
	return papelito_vendor_code_import_flag_duplicates( $rows, $eligible );
}

/**
 * Marca como repetida a linha que leva o mesmo texto e o mesmo código ao mesmo destino de uma anterior.
 *
 * Roda depois dos conflitos: linha já em conflito deixa de ser elegível e não vira repetida.
 *
 * @param array<int,array> $rows     Linhas com os conflitos marcados.
 * @param callable         $eligible Diz se a linha ainda participa.
 * @return array<int,array>
 */
function papelito_vendor_code_import_flag_duplicates( array $rows, callable $eligible ): array {
	$seen = array();
	foreach ( $rows as $index => $row ) {
		if ( ! $eligible( $row ) ) {
			continue;
		}
		$key = papelito_vendor_code_import_destination_key( $row );
		if ( isset( $seen[ $key ] ) ) {
			$rows[ $index ]['status'] = 'duplicate';
			$rows[ $index ]['reason'] = sprintf( 'Linha repetida: igual à linha %d.', $seen[ $key ] );
			continue;
		}
		$seen[ $key ] = $row['line'];
	}
	return $rows;
}

/**
 * Chave de repetição: descrição normalizada, código e destino resolvido (família e itens).
 *
 * O destino entra na chave porque o subgrupo participa da resolução: o mesmo texto pode
 * cair em famílias diferentes, e aí não é a mesma linha.
 *
 * @param array $row Linha com família resolvida.
 */
function papelito_vendor_code_import_destination_key( array $row ): string {
	$items = array_map( 'intval', array_column( $row['family']['targets'], 'product_id' ) );
	sort( $items );
	return implode(
		'|',
		array(
			papelito_vendor_code_import_normalize( $row['description'] ),
			$row['code'],
			(int) $row['family']['family_id'],
			implode( ',', $items ),
		)
	);
}

/**
 * Índices das linhas cujo agrupamento tem mais de uma chave interna.
 *
 * @param array<int|string,array<int|string,int[]>> $groups Linhas por item→código ou código→família.
 * @return int[]
 */
function papelito_vendor_code_import_conflicting_rows( array $groups ): array {
	$indexes = array();
	foreach ( $groups as $inner ) {
		if ( count( $inner ) > 1 ) {
			$indexes = array_merge( $indexes, array_merge( ...array_values( $inner ) ) );
		}
	}
	return $indexes;
}

/**
 * Linha da prévia que grava automaticamente (exata ou por sinônimo).
 *
 * @param array $row Linha avaliada.
 */
function papelito_vendor_code_import_is_automatic( array $row ): bool {
	return in_array( $row['status'], array( 'exact', 'synonym' ), true ) && null !== $row['family'];
}

/**
 * Itens da família cujo código muda, indexados pelo ID.
 *
 * @param array $family Família com `targets` anotados por `with_new_code()`.
 * @return array<int,true>
 */
function papelito_vendor_code_import_changed_items( array $family ): array {
	$changed = array();
	foreach ( $family['targets'] as $target ) {
		if ( $target['new_code'] !== $target['current_code'] ) {
			$changed[ (int) $target['product_id'] ] = true;
		}
	}
	return $changed;
}

/**
 * Totais da prévia por status e o número de itens distintos que mudariam.
 *
 * Item alcançado por duas linhas automáticas com o mesmo código (uma cor e a
 * família inteira) conta uma vez.
 *
 * @param array<int,array> $rows Linhas da prévia.
 * @return array<string,int>
 */
function papelito_vendor_code_import_summary( array $rows ): array {
	$summary = array_fill_keys( array( 'exact', 'synonym', 'review', 'not_found', 'conflict', 'invalid', 'duplicate', 'up_to_date' ), 0 );
	$changed = array();
	foreach ( $rows as $row ) {
		++$summary[ $row['status'] ];
		if ( papelito_vendor_code_import_is_automatic( $row ) ) {
			$changed += papelito_vendor_code_import_changed_items( $row['family'] );
		}
	}
	return array_merge(
		array(
			'total'   => count( $rows ),
			'changes' => count( $changed ),
		),
		$summary
	);
}

/**
 * Prévia completa da planilha; não grava nada.
 *
 * @param int   $vendor_id Vendor autenticado.
 * @param array $rows      Linhas lidas do arquivo.
 * @return array{summary:array<string,int>,rows:array}|WP_Error
 */
function papelito_vendor_code_import_preview( int $vendor_id, array $rows ): array|WP_Error {
	$dictionary = papelito_vendor_code_import_dictionary();
	$families   = papelito_vendor_code_import_families( $vendor_id, $dictionary );
	if ( is_wp_error( $families ) ) {
		return $families;
	}
	$evaluated = array();
	foreach ( $rows as $row ) {
		$evaluated[] = papelito_vendor_code_import_evaluate( $row, $families, $dictionary );
	}
	$evaluated = papelito_vendor_code_import_flag_conflicts( $evaluated, 'papelito_vendor_code_import_is_automatic' );
	foreach ( $evaluated as $index => $row ) {
		if ( papelito_vendor_code_import_is_automatic( $row ) && 0 === $row['changes'] ) {
			$evaluated[ $index ]['status'] = 'up_to_date';
			$evaluated[ $index ]['reason'] = 'O código deste produto já é este.';
		}
	}
	return array(
		'summary' => papelito_vendor_code_import_summary( $evaluated ),
		'rows'    => $evaluated,
	);
}

/**
 * Lê o arquivo do upload e monta a prévia do vendor dono do tíquete.
 *
 * @param int   $vendor_id Vendor fixado no tíquete.
 * @param array $file      Arquivo recebido.
 * @return array|WP_Error
 */
function papelito_vendor_code_import_preview_file( int $vendor_id, array $file ): array|WP_Error {
	$rows = papelito_vendor_code_import_read_file( $file );
	return is_wp_error( $rows ) ? $rows : papelito_vendor_code_import_preview( $vendor_id, $rows );
}

/**
 * Valida o corpo da confirmação: lista de no máximo 2.000 linhas com família e valores vistos.
 *
 * @param mixed $payload Corpo JSON.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function papelito_vendor_code_import_validate_payload( mixed $payload ): array|WP_Error {
	$rows = is_array( $payload ) ? ( $payload['rows'] ?? null ) : null;
	if ( ! is_array( $rows ) || ! $rows || count( $rows ) > PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS || ! array_is_list( $rows ) ) {
		return papelito_vendor_code_import_error( 'papelito_vendor_code_import_invalid', 'Envie de 1 a 2.000 linhas para aplicar.', 422, 'match' );
	}
	$valid = array();
	foreach ( $rows as $row ) {
		$parsed = papelito_vendor_code_import_parse_row( $row );
		if ( null === $parsed ) {
			return papelito_vendor_code_import_error( 'papelito_vendor_code_import_invalid', 'Há linha fora do formato esperado.', 422, 'match' );
		}
		$valid[] = $parsed;
	}
	return $valid;
}

/**
 * Uma linha da confirmação, ou null quando foge do contrato.
 *
 * @param mixed $row Linha recebida.
 * @return array{line:int,description:string,subgroup:string,code:string,family_id:int,expected:array<int,?string>}|null
 */
function papelito_vendor_code_import_parse_row( mixed $row ): ?array {
	if ( ! is_array( $row ) || ! is_string( $row['description'] ?? null ) || ! is_string( $row['code'] ?? null ) || ! is_array( $row['expected'] ?? null ) ) {
		return null;
	}
	$family_id = filter_var( $row['family_id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	$line      = filter_var( $row['line'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	if ( false === $family_id || false === $line ) {
		return null;
	}
	$expected = array();
	foreach ( $row['expected'] as $item ) {
		$product_id = is_array( $item ) ? filter_var( $item['product_id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
		$current    = is_array( $item ) ? ( $item['current_code'] ?? null ) : null;
		if ( false === $product_id || ( null !== $current && ! is_string( $current ) ) ) {
			return null;
		}
		$expected[ $product_id ] = $current;
	}
	return array(
		'line'        => $line,
		'description' => $row['description'],
		'subgroup'    => is_string( $row['subgroup'] ?? null ) ? $row['subgroup'] : '',
		'code'        => $row['code'],
		'family_id'   => $family_id,
		'expected'    => $expected,
	);
}

/**
 * Refaz a associação e aceita a família escolhida só se ela ainda for o match automático ou um candidato.
 *
 * @param array $row        Linha da confirmação.
 * @param array $families   Catálogo.
 * @param array $dictionary Dicionário.
 * @return array<string,mixed>
 */
function papelito_vendor_code_import_accept( array $row, array $families, array $dictionary ): array {
	$evaluated             = papelito_vendor_code_import_evaluate( $row, $families, $dictionary );
	$evaluated['expected'] = $row['expected'];
	$options               = array_merge( null === $evaluated['family'] ? array() : array( $evaluated['family'] ), $evaluated['candidates'] );
	foreach ( $options as $option ) {
		if ( (int) $option['family_id'] === (int) $row['family_id'] && in_array( $evaluated['match'], array( 'exact', 'synonym', 'review' ), true ) ) {
			$evaluated['family'] = $option;
			$evaluated['status'] = 'accepted';
			return $evaluated;
		}
	}
	$evaluated['status'] = 'rejected';
	$evaluated['reason'] = '' !== $evaluated['reason'] && 'invalid' === $evaluated['match']
		? $evaluated['reason']
		: 'A associação escolhida não é mais válida para esta descrição. Refaça a prévia.';
	return $evaluated;
}

/**
 * Trava as linhas dos itens alvo e devolve o código atual de cada um.
 *
 * @param int   $vendor_id   Vendor autenticado.
 * @param int[] $product_ids Itens alvo.
 * @return array<int,?string>|WP_Error
 */
function papelito_vendor_code_import_lock_codes( int $vendor_id, array $product_ids ): array|WP_Error {
	global $wpdb;
	$ids     = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
	$current = array_fill_keys( $ids, null );
	if ( ! $ids ) {
		return $current;
	}
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows         = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN contém apenas placeholders gerados.
		$wpdb->prepare( "SELECT product_id, vendor_code FROM %i WHERE vendor_id = %d AND product_id IN ({$placeholders}) FOR UPDATE", array_merge( array( papelito_vendor_item_settings_table_name(), $vendor_id ), $ids ) ),
		ARRAY_A
	);
	if ( '' !== $wpdb->last_error ) {
		return papelito_vendor_item_settings_error();
	}
	foreach ( (array) $rows as $row ) {
		$current[ (int) $row['product_id'] ] = null === $row['vendor_code'] ? null : (string) $row['vendor_code'];
	}
	return $current;
}

/**
 * Diz se algum alvo mudou desde a prévia; alvo sem valor visto também conta como mudança.
 *
 * @param array              $row     Linha aceita.
 * @param array<int,?string> $locked  Códigos travados no início da transação, nunca alterados pelo lote.
 */
function papelito_vendor_code_import_is_stale( array $row, array $locked ): bool {
	foreach ( $row['family']['targets'] as $target ) {
		$id     = $target['product_id'];
		$stored = $locked[ $id ] ?? null;
		if ( ! array_key_exists( $id, $row['expected'] ) || $stored !== $row['expected'][ $id ] ) {
			return true;
		}
	}
	return false;
}

/**
 * Grava os alvos de uma linha aceita e devolve o resultado dela.
 *
 * A linha é comparada com o snapshot travado (`$locked`), não com o que outra linha do
 * mesmo lote já gravou: linha da família inteira depois de linha de uma cor, com o mesmo
 * código, não é alteração concorrente.
 *
 * @param int                $vendor_id Vendor autenticado.
 * @param array              $row       Linha aceita.
 * @param array<int,?string> $locked    Códigos travados no início da transação.
 * @param array<int,?string> $written   Códigos já gravados por este lote, atualizados por referência.
 * @param string             $batch_id  Identificador da importação na trilha.
 * @return array<string,mixed>|WP_Error
 */
function papelito_vendor_code_import_apply_row( int $vendor_id, array $row, array $locked, array &$written, string $batch_id ): array|WP_Error {
	if ( papelito_vendor_code_import_is_stale( $row, $locked ) ) {
		return papelito_vendor_code_import_result_row( $row, 'stale', 'O código deste produto mudou desde a prévia. Refaça a importação.', array() );
	}
	$changed = array();
	foreach ( $row['family']['targets'] as $target ) {
		$id     = $target['product_id'];
		$new    = papelito_vendor_code_import_stored_code( $row['code'], $target );
		$stored = array_key_exists( $id, $written ) ? $written[ $id ] : ( $locked[ $id ] ?? null );
		if ( $stored === $new ) {
			continue;
		}
		$saved = papelito_vendor_item_settings_save_code(
			$vendor_id,
			$id,
			$new,
			array(
				'source'   => 'import',
				'batch_id' => $batch_id,
			)
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$changed[]      = array(
			'product_id' => $id,
			'name'       => $target['name'],
			'old_code'   => $stored,
			'new_code'   => $new,
		);
		$written[ $id ] = $new;
	}
	return $changed
		? papelito_vendor_code_import_result_row( $row, 'applied', '', $changed )
		: papelito_vendor_code_import_result_row( $row, 'up_to_date', 'O código deste produto já é este.', array() );
}

/**
 * Linha do resultado devolvido ao painel.
 *
 * @param array  $row     Linha avaliada.
 * @param string $status  `applied`, `up_to_date`, `stale`, `rejected`, `conflict` ou `duplicate`.
 * @param string $reason  Motivo exibível.
 * @param array  $targets Itens efetivamente gravados.
 * @return array<string,mixed>
 */
function papelito_vendor_code_import_result_row( array $row, string $status, string $reason, array $targets ): array {
	return array(
		'line'        => (int) $row['line'],
		'description' => (string) $row['description'],
		'code'        => (string) $row['code'],
		'status'      => $status,
		'reason'      => '' === $reason ? (string) ( $row['reason'] ?? '' ) : $reason,
		'targets'     => $targets,
	);
}

/**
 * Grava as linhas aceitas numa transação única; qualquer falha de SQL desfaz tudo.
 *
 * @param int    $vendor_id Vendor autenticado.
 * @param array  $rows      Linhas aceitas, sem conflito.
 * @param string $batch_id Identificador da importação.
 * @return array<int,array>|WP_Error
 */
function papelito_vendor_code_import_persist( int $vendor_id, array $rows, string $batch_id ): array|WP_Error {
	global $wpdb;
	$failure = papelito_vendor_code_import_error( 'papelito_vendor_code_import_storage_error', 'Não foi possível gravar os códigos. Nenhuma alteração foi salva.', 500, 'persist' );
	if ( ! $rows ) {
		return array();
	}
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		return $failure;
	}
	$ids = array();
	foreach ( $rows as $row ) {
		$ids = array_merge( $ids, array_column( $row['family']['targets'], 'product_id' ) );
	}
	$locked = papelito_vendor_code_import_lock_codes( $vendor_id, $ids );
	if ( is_wp_error( $locked ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $failure;
	}
	$written = array();
	$results = array();
	foreach ( $rows as $row ) {
		$result = papelito_vendor_code_import_apply_row( $vendor_id, $row, $locked, $written, $batch_id );
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $failure;
		}
		$results[] = $result;
	}
	if ( false === $wpdb->query( 'COMMIT' ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $failure;
	}
	return $results;
}

/**
 * Confirmação: revalida cada linha, descarta conflitos e grava o restante.
 *
 * O resultado só lista como `applied` o que o COMMIT confirmou.
 *
 * @param int   $vendor_id Vendor autenticado.
 * @param array $rows      Linhas validadas de `validate_payload()`.
 * @return array{batch_id:string,summary:array<string,int>,rows:array}|WP_Error
 */
function papelito_vendor_code_import_confirm( int $vendor_id, array $rows ): array|WP_Error {
	$dictionary = papelito_vendor_code_import_dictionary();
	$families   = papelito_vendor_code_import_families( $vendor_id, $dictionary );
	if ( is_wp_error( $families ) ) {
		return $families;
	}
	$evaluated = array();
	foreach ( $rows as $row ) {
		$evaluated[] = papelito_vendor_code_import_accept( $row, $families, $dictionary );
	}
	$evaluated = papelito_vendor_code_import_flag_conflicts( $evaluated, static fn( array $row ): bool => 'accepted' === $row['status'] );
	$accepted  = array_values( array_filter( $evaluated, static fn( array $row ): bool => 'accepted' === $row['status'] ) );
	$batch_id  = wp_generate_uuid4();
	$persisted = papelito_vendor_code_import_persist( $vendor_id, $accepted, $batch_id );
	if ( is_wp_error( $persisted ) ) {
		return $persisted;
	}
	$results = $persisted;
	foreach ( $evaluated as $row ) {
		if ( 'accepted' !== $row['status'] ) {
			$results[] = papelito_vendor_code_import_result_row( $row, $row['status'], '', array() );
		}
	}
	usort( $results, static fn( array $a, array $b ): int => $a['line'] <=> $b['line'] );
	return array(
		'batch_id' => $batch_id,
		'summary'  => papelito_vendor_code_import_result_summary( $results ),
		'rows'     => $results,
	);
}

/**
 * Totais do resultado: linhas por status e itens efetivamente gravados.
 *
 * @param array<int,array> $results Linhas do resultado.
 * @return array<string,int>
 */
function papelito_vendor_code_import_result_summary( array $results ): array {
	$summary = array_fill_keys( array( 'applied', 'up_to_date', 'stale', 'rejected', 'conflict', 'duplicate' ), 0 );
	$updated = 0;
	foreach ( $results as $result ) {
		++$summary[ $result['status'] ];
		$updated += count( $result['targets'] );
	}
	return array_merge( array( 'updated_items' => $updated ), $summary );
}
