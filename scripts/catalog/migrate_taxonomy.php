#!/usr/bin/env php
<?php
/**
 * Migração da taxonomia de produtos: `product_cat` → entidade própria da Papelito.
 *
 * Roda em dois modos. **Dry-run é o padrão**: lê o par (raiz, filho) de
 * `product_cat` de cada produto, aplica o mapa determinístico de
 * `taxonomy_map.php`, deriva o que falta do título por casamento EXATO de token,
 * e imprime o destino de cada produto mais a lista do que precisa de decisão
 * humana — sem escrever nada.
 *
 * Com `PAPELITO_TAXONOMY_APPLY=1` (fase 4) ele grava: cria o seed de categorias e
 * subcategorias, classifica os produtos sem pendência, marca os pendentes com
 * `_papelito_taxonomy_todo`.
 *
 * **Escreve SOMENTE nas tabelas `wp_papelito_*`.** `product_cat` não é tocado.
 *
 *   docker cp scripts/catalog/taxonomy_map.php papelito-web:/tmp/
 *   docker cp scripts/catalog/migrate_taxonomy.php papelito-web:/tmp/
 *   docker compose exec -T web wp eval-file /tmp/migrate_taxonomy.php
 *
 * Variáveis de ambiente:
 *   PAPELITO_TAXONOMY_REPORT   caminho do CSV de saída (default /tmp/papelito-taxonomy-dryrun.csv)
 *   PAPELITO_TAXONOMY_VERBOSE  1 para listar produto por produto
 *   PAPELITO_TAXONOMY_APPLY    1 para GRAVAR (default: dry-run)
 *   PAPELITO_TAXONOMY_RESET    1 (com APPLY) para limpar os vínculos antes — o rollback da fase 4
 *
 * Regras que o script segue, e que são o motivo dele existir:
 *
 * - Termo combinado é DECOMPOSTO: `Brown Slim` → Brown + Slim, `Slim Longo` →
 *   Slim + Longo. Foi a combinação virando termo que gerou a explosão atual.
 * - `Premium` e `Kits` NÃO são categoria: viram coleção curada.
 * - `Longas` (bucket de tamanho das piteiras) morre; cada produto recebe o
 *   tamanho preciso que só existia no título.
 * - O que não dá para derivar NÃO é inventado: vai para a lista de revisão com o
 *   motivo e o código da decisão que desbloqueia.
 *
 * @package Papelito
 */

if ( ! defined( 'WP_CLI' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file migrate_taxonomy.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once __DIR__ . '/taxonomy_map.php';

// ------------------------------------------------------------------
// Leitura do estado atual
// ------------------------------------------------------------------

global $wpdb;

$sql = "SELECT p.ID, p.post_title, p.post_status,
               raiz.slug AS root_slug, filho.slug AS child_slug
          FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->term_relationships} tr1 ON tr1.object_id = p.ID
          LEFT JOIN {$wpdb->term_taxonomy} tt1 ON tt1.term_taxonomy_id = tr1.term_taxonomy_id AND tt1.taxonomy = 'product_cat' AND tt1.parent = 0
          LEFT JOIN {$wpdb->terms} raiz ON raiz.term_id = tt1.term_id
          LEFT JOIN {$wpdb->term_relationships} tr2 ON tr2.object_id = p.ID
          LEFT JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id AND tt2.taxonomy = 'product_cat' AND tt2.parent = tt1.term_id
          LEFT JOIN {$wpdb->terms} filho ON filho.term_id = tt2.term_id
         WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')
         ORDER BY p.ID ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$rows     = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$products = array();

foreach ( is_array( $rows ) ? $rows : array() as $row ) {
	// `$id` é global do WordPress: usar aqui dispara GlobalVariablesOverride.
	$product_id = (int) $row['ID'];

	if ( ! isset( $products[ $product_id ] ) ) {
		$products[ $product_id ] = array(
			'id'       => $product_id,
			'title'    => (string) $row['post_title'],
			'status'   => (string) $row['post_status'],
			'roots'    => array(),
			'children' => array(),
		);
	}

	if ( ! empty( $row['root_slug'] ) ) {
		$products[ $product_id ]['roots'][ (string) $row['root_slug'] ] = true;
	}

	if ( ! empty( $row['child_slug'] ) ) {
		$products[ $product_id ]['children'][ (string) $row['child_slug'] ] = true;
	}
}

// ------------------------------------------------------------------
// Mapeamento
// ------------------------------------------------------------------

$resultados = array();

foreach ( $products as $product ) {
	$roots    = array_keys( $product['roots'] );
	$children = array_keys( $product['children'] );
	$destino  = papelito_taxonomy_resolve_destination( $product['title'], $roots, $children );

	$resultados[] = array_merge(
		$destino,
		array(
			'id'     => $product['id'],
			'title'  => $product['title'],
			'status' => $product['status'],
			'origem' => ( empty( $roots ) ? '(sem raiz)' : implode( '+', $roots ) ) . ' > ' . ( empty( $children ) ? '(sem filho)' : implode( '+', $children ) ),
		)
	);
}

// ------------------------------------------------------------------
// Escrita (fase 4)
// ------------------------------------------------------------------

/**
 * Encontra ou cria a categoria do seed, e mantém nome e ordem em sincronia.
 *
 * Idempotente: identifica por slug, inclusive arquivada.
 *
 * @param string              $slug Slug da categoria.
 * @param array<string,mixed> $def  Definição no seed.
 * @return int|WP_Error
 */
function papelito_migration_ensure_category( $slug, array $def ) {
	$existente = papelito_category_get_by_slug( $slug );

	if ( null !== $existente ) {
		papelito_category_update(
			$existente['id'],
			array(
				'name'      => $def['name'],
				'sortOrder' => $def['sort_order'],
				'isActive'  => true,
			)
		);

		if ( null !== $existente['archivedAt'] ) {
			papelito_category_restore( $existente['id'] );
		}

		return $existente['id'];
	}

	return papelito_category_create(
		array(
			'name'      => $def['name'],
			'slug'      => $slug,
			'sortOrder' => $def['sort_order'],
		)
	);
}

/**
 * Encontra ou cria a subcategoria do seed.
 *
 * @param int                 $category_id Id da categoria.
 * @param array<string,mixed> $def         Definição no seed.
 * @param int                 $position    Ordem dentro da categoria.
 * @return int|WP_Error
 */
function papelito_migration_ensure_subcategory( $category_id, array $def, $position ) {
	$existente = papelito_subcategory_get_by_slug( $category_id, $def['slug'] );

	if ( null !== $existente ) {
		papelito_subcategory_update(
			$existente['id'],
			array(
				'name'      => $def['name'],
				'facet'     => $def['facet'],
				'sortOrder' => $position,
				'isActive'  => true,
			)
		);

		return $existente['id'];
	}

	return papelito_subcategory_create(
		array(
			'categoryId' => $category_id,
			'name'       => $def['name'],
			'slug'       => $def['slug'],
			'facet'      => $def['facet'],
			'sortOrder'  => $position,
		)
	);
}

$aplicar = '1' === (string) getenv( 'PAPELITO_TAXONOMY_APPLY' );
$resetar = '1' === (string) getenv( 'PAPELITO_TAXONOMY_RESET' );
$escrita = array(
	'categorias'    => 0,
	'subcategorias' => 0,
	'completos'     => 0,
	'parciais'      => 0,
	'sem_categoria' => 0,
	'erros'         => array(),
);

if ( $aplicar ) {
	papelito_product_taxonomy_install_tables();

	if ( $resetar ) {
		$tabelas = papelito_product_taxonomy_table_names();

		foreach ( array( 'product_subcategory', 'product_collection', 'product_category' ) as $chave ) {
			$wpdb->query( "DELETE FROM {$tabelas[ $chave ]}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		WP_CLI::log( 'RESET: vínculos de produto apagados antes de reaplicar.' );
	}

	$ids_categoria    = array();
	$ids_subcategoria = array();

	foreach ( papelito_taxonomy_seed() as $slug => $def ) {
		$category_id = papelito_migration_ensure_category( $slug, $def );

		if ( is_wp_error( $category_id ) ) {
			$escrita['erros'][] = 'categoria ' . $slug . ': ' . $category_id->get_error_message();
			continue;
		}

		$ids_categoria[ $slug ] = (int) $category_id;
		++$escrita['categorias'];

		foreach ( $def['subcategories'] as $position => $sub_def ) {
			$sub_id = papelito_migration_ensure_subcategory( (int) $category_id, $sub_def, $position );

			if ( is_wp_error( $sub_id ) ) {
				$escrita['erros'][] = 'subcategoria ' . $slug . '/' . $sub_def['slug'] . ': ' . $sub_id->get_error_message();
				continue;
			}

			$ids_subcategoria[ $slug ][ $sub_def['slug'] ] = (int) $sub_id;
			++$escrita['subcategorias'];
		}
	}

	foreach ( $resultados as $r ) {
		$category_slug = $r['category'];

		if ( null === $category_slug || ! isset( $ids_categoria[ $category_slug ] ) ) {
			++$escrita['sem_categoria'];
			update_post_meta( $r['id'], '_papelito_taxonomy_todo', $r['pendencias'] );
			continue;
		}

		$resultado = papelito_product_set_category( $r['id'], $ids_categoria[ $category_slug ] );

		if ( is_wp_error( $resultado ) ) {
			$escrita['erros'][] = '#' . $r['id'] . ' categoria: ' . $resultado->get_error_message();
			continue;
		}

		$sub_ids = array();

		foreach ( $r['subcategories'] as $sub_slug ) {
			if ( isset( $ids_subcategoria[ $category_slug ][ $sub_slug ] ) ) {
				$sub_ids[] = $ids_subcategoria[ $category_slug ][ $sub_slug ];
			}
		}

		if ( ! empty( $sub_ids ) ) {
			$resultado = papelito_product_set_subcategories( $r['id'], $sub_ids );

			if ( is_wp_error( $resultado ) ) {
				$escrita['erros'][] = '#' . $r['id'] . ' subcategorias: ' . $resultado->get_error_message();
			}
		}

		$resultado = papelito_product_set_collections( $r['id'], $r['collections'] );

		if ( is_wp_error( $resultado ) ) {
			$escrita['erros'][] = '#' . $r['id'] . ' coleções: ' . $resultado->get_error_message();
		}

		// Pendência NÃO impede a classificação parcial: a categoria e as
		// subcategorias derivadas são determinísticas, e deixar o produto sem
		// categoria o tiraria do catálogo na fase 6. O que falta fica marcado.
		if ( empty( $r['pendencias'] ) ) {
			delete_post_meta( $r['id'], '_papelito_taxonomy_todo' );
			++$escrita['completos'];
		} else {
			update_post_meta( $r['id'], '_papelito_taxonomy_todo', $r['pendencias'] );
			++$escrita['parciais'];
		}
	}

}

// ------------------------------------------------------------------
// Relatório
// ------------------------------------------------------------------

$automaticos = array_values(
	array_filter(
		$resultados,
		static function ( array $r ): bool {
			return empty( $r['pendencias'] );
		}
	)
);
$pendentes   = array_values(
	array_filter(
		$resultados,
		static function ( array $r ): bool {
			return ! empty( $r['pendencias'] );
		}
	)
);

WP_CLI::log( '' );
WP_CLI::log( $aplicar ? '=== APPLY: gravado nas tabelas wp_papelito_* ===' : '=== DRY-RUN: nada foi escrito no banco ===' );
WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Produtos analisados: %d', count( $resultados ) ) );
WP_CLI::log( sprintf( '  automáticos:      %d', count( $automaticos ) ) );
WP_CLI::log( sprintf( '  revisão manual:   %d', count( $pendentes ) ) );
WP_CLI::log( '' );

$por_categoria = array();

foreach ( $resultados as $r ) {
	$slug                                 = $r['category'] ?? '(nenhuma)';
	$por_categoria[ $slug ]['total']      = ( $por_categoria[ $slug ]['total'] ?? 0 ) + 1;
	$por_categoria[ $slug ]['publicados'] = ( $por_categoria[ $slug ]['publicados'] ?? 0 ) + ( 'publish' === $r['status'] ? 1 : 0 );
	$por_categoria[ $slug ]['pendentes']  = ( $por_categoria[ $slug ]['pendentes'] ?? 0 ) + ( empty( $r['pendencias'] ) ? 0 : 1 );
}

WP_CLI::log( 'Destino por categoria:' );

foreach ( $por_categoria as $slug => $contagem ) {
	WP_CLI::log( sprintf( '  %-12s total %2d | publicados %2d | pendentes %d', $slug, $contagem['total'], $contagem['publicados'], $contagem['pendentes'] ) );
}

$uso_sub = array();

foreach ( $resultados as $r ) {
	foreach ( $r['subcategories'] as $slug ) {
		$chave             = $r['category'] . ' / ' . $slug;
		$uso_sub[ $chave ] = ( $uso_sub[ $chave ] ?? 0 ) + 1;
	}
}

ksort( $uso_sub );

WP_CLI::log( '' );
WP_CLI::log( 'Subcategorias que receberiam produto:' );

foreach ( $uso_sub as $chave => $total ) {
	WP_CLI::log( sprintf( '  %-34s %d', $chave, $total ) );
}

$sem_uso = array();

foreach ( papelito_taxonomy_seed() as $slug => $categoria ) {
	foreach ( $categoria['subcategories'] as $subcategory ) {
		if ( ! isset( $uso_sub[ $slug . ' / ' . $subcategory['slug'] ] ) ) {
			$sem_uso[] = $slug . ' / ' . $subcategory['slug'] . ' [' . $subcategory['facet'] . ']';
		}
	}
}

WP_CLI::log( '' );

if ( empty( $sem_uso ) ) {
	WP_CLI::log( 'Toda subcategoria do seed recebe ao menos um produto — nenhum termo nasceria vazio.' );
} else {
	WP_CLI::log( 'Subcategorias do seed que ficariam VAZIAS (candidatas a não criar):' );

	foreach ( $sem_uso as $item ) {
		WP_CLI::log( '  ' . $item );
	}
}

WP_CLI::log( '' );
WP_CLI::log( '=== REVISÃO MANUAL (' . count( $pendentes ) . ') ===' );

foreach ( $pendentes as $r ) {
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '#%d %s [%s]', $r['id'], $r['title'], $r['status'] ) );
	WP_CLI::log( '   origem:  ' . $r['origem'] );
	WP_CLI::log( '   destino: ' . ( $r['category'] ?? '(nenhum)' ) . ( empty( $r['subcategories'] ) ? '' : ' / ' . implode( ' + ', $r['subcategories'] ) ) );

	foreach ( $r['pendencias'] as $motivo ) {
		WP_CLI::log( '   ! ' . $motivo );
	}
}

$notas = array();

foreach ( $resultados as $r ) {
	foreach ( $r['notas'] as $nota ) {
		$notas[ $nota ][] = $r['id'];
	}
}

if ( ! empty( $notas ) ) {
	WP_CLI::log( '' );
	WP_CLI::log( '=== NOTAS (não bloqueiam, mas dependem de decisão) ===' );

	foreach ( $notas as $nota => $ids ) {
		WP_CLI::log( sprintf( '  %s', $nota ) );
		WP_CLI::log( sprintf( '    %d produto(s): %s', count( $ids ), implode( ', ', $ids ) ) );
	}
}

$decisoes_citadas = array();

foreach ( $pendentes as $r ) {
	foreach ( $r['pendencias'] as $motivo ) {
		if ( preg_match( '/\((D\d+)\)/', $motivo, $m ) ) {
			$decisoes_citadas[ $m[1] ] = true;
		}
	}
}

foreach ( array_keys( $notas ) as $nota ) {
	if ( preg_match( '/(D\d+)/', $nota, $m ) ) {
		$decisoes_citadas[ $m[1] ] = true;
	}
}

if ( ! empty( $decisoes_citadas ) ) {
	$catalogo = papelito_taxonomy_decisions();

	ksort( $decisoes_citadas );

	WP_CLI::log( '' );
	WP_CLI::log( '=== DECISÕES QUE DESBLOQUEIAM A FASE 4 ===' );

	foreach ( array_keys( $decisoes_citadas ) as $codigo ) {
		WP_CLI::log( '  ' . $codigo . ' — ' . ( $catalogo[ $codigo ] ?? '?' ) );
	}
}

if ( '1' === (string) getenv( 'PAPELITO_TAXONOMY_VERBOSE' ) ) {
	WP_CLI::log( '' );
	WP_CLI::log( '=== TODOS OS PRODUTOS ===' );

	foreach ( $resultados as $r ) {
		WP_CLI::log(
			sprintf(
				'  %s #%-6d %-32s %-11s %s%s',
				empty( $r['pendencias'] ) ? 'ok ' : 'REV',
				$r['id'],
				$r['title'],
				$r['category'] ?? '(nenhuma)',
				implode( ' + ', $r['subcategories'] ),
				empty( $r['collections'] ) ? '' : '  {' . implode( ',', $r['collections'] ) . '}'
			)
		);
	}
}

$csv_path = getenv( 'PAPELITO_TAXONOMY_REPORT' );
$csv_path = $csv_path ? $csv_path : '/tmp/papelito-taxonomy-dryrun.csv';
$linhas   = array( 'id;status;titulo;origem;categoria;subcategorias;colecoes;pendencias;notas' );

foreach ( $resultados as $r ) {
	$linhas[] = implode(
		';',
		array(
			$r['id'],
			$r['status'],
			str_replace( ';', ',', $r['title'] ),
			str_replace( ';', ',', $r['origem'] ),
			$r['category'] ?? '',
			implode( '|', $r['subcategories'] ),
			implode( '|', $r['collections'] ),
			str_replace( ';', ',', implode( ' // ', $r['pendencias'] ) ),
			str_replace( ';', ',', implode( ' // ', $r['notas'] ) ),
		)
	);
}

if ( false !== file_put_contents( $csv_path, implode( "\n", $linhas ) . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	WP_CLI::log( '' );
	WP_CLI::log( 'CSV para revisão: ' . $csv_path );
}

WP_CLI::log( '' );
if ( $aplicar ) {
	WP_CLI::log( '' );
	WP_CLI::log( '=== ESCRITA ===' );
	WP_CLI::log( sprintf( '  categorias criadas/atualizadas:    %d', $escrita['categorias'] ) );
	WP_CLI::log( sprintf( '  subcategorias criadas/atualizadas: %d', $escrita['subcategorias'] ) );
	WP_CLI::log( sprintf( '  produtos classificados por completo: %d', $escrita['completos'] ) );
	WP_CLI::log( sprintf( '  produtos com classificação parcial:  %d (marcados com _papelito_taxonomy_todo)', $escrita['parciais'] ) );
	WP_CLI::log( sprintf( '  produtos sem categoria:              %d', $escrita['sem_categoria'] ) );


	if ( ! empty( $escrita['erros'] ) ) {
		WP_CLI::log( '' );
		WP_CLI::log( '  ERROS:' );

		foreach ( $escrita['erros'] as $erro ) {
			WP_CLI::log( '    ' . $erro );
		}
	}

	$integridade = papelito_category_integrity_report();

	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '  publicados sem categoria: %d', count( $integridade['publishedWithoutCategory'] ) ) );
	WP_CLI::log( sprintf( '  vínculos órfãos: %d categoria, %d subcategoria', count( $integridade['danglingCategory'] ), count( $integridade['danglingSubcategory'] ) ) );
	WP_CLI::log( sprintf( '  subcategoria de outra categoria: %d', count( $integridade['crossCategorySubcategory'] ) ) );
	WP_CLI::log( sprintf( '  relatório de integridade limpo: %s', $integridade['isClean'] ? 'sim' : 'NÃO' ) );

}

WP_CLI::log( '' );
WP_CLI::success( $aplicar ? 'Migração aplicada. product_cat NÃO foi alterado.' : 'Dry-run concluído. Nenhuma escrita no banco.' );
