<?php
/**
 * Compatibilidade entre a taxonomia própria e o `product_cat` do WooCommerce.
 *
 * Este módulo é o período de transição da migração, e existe para ser removido na
 * fase 7. Ele resolve três coisas:
 *
 * 1. **Feature flag** `PAPELITO_PRODUCT_TAXONOMY_ENABLED`, desligada por padrão
 *    inclusive em produção. Com ela desligada nada aqui tem efeito.
 * 2. **Dual-write**: com a flag ligada, gravar a taxonomia Papelito de um produto
 *    também sincroniza os termos equivalentes de `product_cat`, mantendo a loja
 *    WP/Elementor legada e os relatórios do WooCommerce funcionando.
 * 3. **Reconciliação**: relatório que compara as duas fontes e aponta divergência,
 *    porque dual-write silencioso é dual-write que dessincroniza.
 *
 * O mapa `papelito_taxonomy_legacy_map` (em `wp_options`) é escrito pela migração.
 * Ele inverte a relação com uma sutileza: `Brown Slim` era UM termo e virou DUAS
 * subcategorias, então a chave de filho é o CONJUNTO ordenado de slugs, não um
 * slug só. Sem isso, um produto Brown+Slim voltaria para `product_cat` como
 * Brown, perdendo a informação.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

// ------------------------------------------------------------------
// Feature flag
// ------------------------------------------------------------------

/**
 * A taxonomia própria está ligada?
 *
 * Nasce desligada. Segue o padrão das flags B2B: variável de ambiente lida do
 * `wp-config.php`, nunca opção de banco — flag em banco é editável pelo admin e
 * deixa de ser decisão de deploy.
 *
 * @return bool
 */
function papelito_product_taxonomy_enabled() {
	$enabled = function_exists( 'papelito_env_bool' )
		? papelito_env_bool( 'PAPELITO_PRODUCT_TAXONOMY_ENABLED', false )
		: false;

	return (bool) apply_filters( 'papelito_product_taxonomy_enabled', $enabled );
}

// ------------------------------------------------------------------
// Mapa de equivalência com product_cat
// ------------------------------------------------------------------

/**
 * Mapa de equivalência Papelito → `product_cat`.
 *
 * Forma: `{ roots: { <slug_categoria>: <term_id> }, children: { "<slug_categoria>|<slugs,ordenados>": <term_id> }, collections: { <slug_colecao>: <term_id> } }`
 *
 * `collections` existe porque `Premium` era um termo de `product_cat` e virou
 * coleção: sem essa entrada, o dual-write devolveria o produto a `product_cat`
 * como apenas `Papel` e a informação de Premium se perderia na loja legada.
 *
 * @return array{roots:array<string,int>,children:array<string,int>,collections:array<string,int>}
 */
function papelito_taxonomy_legacy_term_map() {
	$stored = get_option( 'papelito_taxonomy_legacy_map', array() );

	return array(
		'roots'       => is_array( $stored['roots'] ?? null ) ? array_map( 'intval', $stored['roots'] ) : array(),
		'children'    => is_array( $stored['children'] ?? null ) ? array_map( 'intval', $stored['children'] ) : array(),
		'collections' => is_array( $stored['collections'] ?? null ) ? array_map( 'intval', $stored['collections'] ) : array(),
	);
}

/**
 * Grava o mapa de equivalência. Chamado pela migração.
 *
 * @param array{roots:array<string,int>,children:array<string,int>} $map Mapa.
 * @return void
 */
function papelito_taxonomy_set_legacy_term_map( array $map ) {
	update_option(
		'papelito_taxonomy_legacy_map',
		array(
			'roots'       => array_map( 'intval', $map['roots'] ?? array() ),
			'children'    => array_map( 'intval', $map['children'] ?? array() ),
			'collections' => array_map( 'intval', $map['collections'] ?? array() ),
		),
		false
	);
}

/**
 * Slugs de subcategoria que existem como termo em `product_cat` para a categoria.
 *
 * @param string            $category_slug Slug da categoria.
 * @param array<string,int> $children      Mapa de filhos.
 * @return string[]
 */
function papelito_taxonomy_legacy_known_slugs( $category_slug, array $children ) {
	$prefix = $category_slug . '|';
	$slugs  = array();

	foreach ( array_keys( $children ) as $key ) {
		if ( 0 !== strpos( $key, $prefix ) ) {
			continue;
		}

		foreach ( explode( ',', substr( $key, strlen( $prefix ) ) ) as $slug ) {
			if ( '' !== $slug ) {
				$slugs[ $slug ] = true;
			}
		}
	}

	return array_keys( $slugs );
}

/**
 * Termos de `product_cat` equivalentes à classificação Papelito de um produto.
 *
 * Só considera as subcategorias que algum dia existiram como termo: `king-size`
 * nunca foi termo, então não participa da inversão. `brown` + `slim` +
 * `king-size` reduz a `brown,slim`, que é o termo `Brown Slim`.
 *
 * @param int $product_id Id do produto.
 * @return int[] Ids de termo, vazio quando não há equivalência.
 */
function papelito_taxonomy_legacy_terms_for_product( $product_id ) {
	$category = papelito_product_get_category( $product_id );

	if ( null === $category ) {
		return array();
	}

	$map  = papelito_taxonomy_legacy_term_map();
	$root = (int) ( $map['roots'][ $category['slug'] ] ?? 0 );

	if ( $root <= 0 ) {
		return array();
	}

	$terms   = array( $root );
	$known   = papelito_taxonomy_legacy_known_slugs( $category['slug'], $map['children'] );
	$product = array_column( papelito_product_get_subcategories( $product_id ), 'slug' );
	$subset  = array_values( array_intersect( $product, $known ) );

	sort( $subset );

	$child = (int) ( $map['children'][ $category['slug'] . '|' . implode( ',', $subset ) ] ?? 0 );

	if ( $child > 0 ) {
		$terms[] = $child;
	}

	foreach ( papelito_product_get_collections( $product_id ) as $collection ) {
		$term = (int) ( $map['collections'][ $collection ] ?? 0 );

		if ( $term > 0 ) {
			$terms[] = $term;
		}
	}

	return array_values( array_unique( array_filter( $terms ) ) );
}

// ------------------------------------------------------------------
// Dual-write
// ------------------------------------------------------------------

/**
 * Liga/desliga a supressão do dual-write no processo corrente.
 *
 * A migração roda com a supressão LIGADA: a fase 4 escreve somente nas tabelas
 * Papelito, e `product_cat` não pode ser tocado por ela.
 *
 * @param bool|null $suppress `null` apenas consulta.
 * @return bool Estado corrente.
 */
function papelito_taxonomy_suppress_dual_write( $suppress = null ) {
	static $suppressed = false;

	if ( null !== $suppress ) {
		$suppressed = (bool) $suppress;
	}

	return $suppressed;
}

/**
 * Sincroniza `product_cat` a partir da taxonomia Papelito de um produto.
 *
 * @param int $product_id Id do produto.
 * @return bool True quando escreveu.
 */
function papelito_taxonomy_dual_write_product( $product_id ) {
	$product_id = (int) $product_id;

	if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
		return false;
	}

	$terms = papelito_taxonomy_legacy_terms_for_product( $product_id );

	if ( empty( $terms ) ) {
		return false;
	}

	wp_set_object_terms( $product_id, $terms, 'product_cat', false );

	return true;
}

/**
 * O dual-write está ativo?
 *
 * **Não depende da feature flag, de propósito.** A flag governa a LEITURA: quando
 * ligada, o catálogo passa a se orientar pela taxonomia Papelito. O dual-write é
 * do caminho de ESCRITA, e precisa valer antes disso — desde o momento em que o
 * admin passa a classificar produto pela taxonomia nova, `product_cat` tem de
 * acompanhar, senão a vitrine (que ainda lê `product_cat`) fica desatualizada no
 * produto recém-salvo.
 *
 * Amarrar as duas coisas na mesma flag criaria uma janela em que salvar um
 * produto pelo admin o tiraria da vitrine.
 *
 * @return bool
 */
function papelito_taxonomy_dual_write_enabled() {
	if ( papelito_taxonomy_suppress_dual_write() ) {
		return false;
	}

	return ! empty( papelito_taxonomy_legacy_term_map()['roots'] );
}

/**
 * Reage à escrita na taxonomia Papelito.
 *
 * @param string $scope   Escopo alterado.
 * @param int    $subject Id do produto quando o escopo é `product`.
 * @return void
 */
function papelito_taxonomy_maybe_dual_write( $scope = '', $subject = 0 ) {
	if ( 'product' !== $scope || ! papelito_taxonomy_dual_write_enabled() ) {
		return;
	}

	papelito_taxonomy_dual_write_product( (int) $subject );
}
add_action( 'papelito_product_taxonomy_changed', 'papelito_taxonomy_maybe_dual_write', 20, 2 );

// ------------------------------------------------------------------
// Reconciliação
// ------------------------------------------------------------------

/**
 * Termos de `product_cat` atualmente atribuídos a um produto.
 *
 * @param int $product_id Id do produto.
 * @return int[]
 */
function papelito_taxonomy_current_legacy_terms( $product_id ) {
	$terms = wp_get_object_terms( (int) $product_id, 'product_cat', array( 'fields' => 'ids' ) );

	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return array();
	}

	$ids = array_map( 'intval', $terms );

	sort( $ids );

	return $ids;
}

/**
 * Compara as duas fontes de classificação e aponta divergência.
 *
 * Divergência não é erro por si: durante a transição um produto pode ter sido
 * classificado na taxonomia nova e ainda não ter passado por uma escrita que
 * dispare o dual-write. O relatório existe para essa diferença ser visível em
 * vez de silenciosa.
 *
 * @return array<string,mixed>
 */
function papelito_taxonomy_reconcile_report() {
	global $wpdb;

	$tables = papelito_product_taxonomy_table_names();
	$sql    = "SELECT pc.product_id FROM {$tables['product_category']} pc INNER JOIN {$wpdb->posts} p ON p.ID = pc.product_id AND p.post_type = 'product' ORDER BY pc.product_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ids    = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	$divergentes  = array();
	$sem_mapa     = array();
	$conferidos   = 0;
	$mapa_ausente = empty( papelito_taxonomy_legacy_term_map()['roots'] );

	foreach ( array_map( 'intval', is_array( $ids ) ? $ids : array() ) as $product_id ) {
		$esperados = papelito_taxonomy_legacy_terms_for_product( $product_id );

		if ( empty( $esperados ) ) {
			$sem_mapa[] = $product_id;
			continue;
		}

		++$conferidos;

		$atuais = papelito_taxonomy_current_legacy_terms( $product_id );

		sort( $esperados );

		if ( $atuais === $esperados ) {
			continue;
		}

		// Perda e ganho não são a mesma coisa. Perder um termo que o produto tinha
		// faz ele desaparecer do arquivo correspondente na loja WP legada — é
		// regressão. Ganhar um termo derivado (o título dizia `Brown` e o termo
		// não estava lá) é enriquecimento, e não quebra nada.
		$perdidos    = array_values( array_diff( $atuais, $esperados ) );
		$adicionados = array_values( array_diff( $esperados, $atuais ) );

		$divergentes[] = array(
			'productId'   => $product_id,
			'esperado'    => $esperados,
			'atual'       => $atuais,
			'perdidos'    => $perdidos,
			'adicionados' => $adicionados,
		);
	}

	$com_perda = array_values(
		array_filter(
			$divergentes,
			static function ( array $d ): bool {
				return ! empty( $d['perdidos'] );
			}
		)
	);

	return array(
		'flagEnabled'     => papelito_product_taxonomy_enabled(),
		'legacyMapAbsent' => $mapa_ausente,
		'classificados'   => count( is_array( $ids ) ? $ids : array() ),
		'conferidos'      => $conferidos,
		'semEquivalencia' => $sem_mapa,
		'divergentes'     => $divergentes,
		'comPerda'        => $com_perda,
		'isClean'         => empty( $com_perda ),
	);
}
