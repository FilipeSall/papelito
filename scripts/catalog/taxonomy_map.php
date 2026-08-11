<?php
/**
 * Mapa de migração da taxonomia de produtos: `product_cat` → entidade Papelito.
 *
 * Só dados e funções puras. Nada aqui consulta banco, escreve ou imprime — é o
 * que permite o mesmo mapa servir ao dry-run (fase 3), à migração real (fase 4) e
 * ao teste que trava as armadilhas de derivação.
 *
 * Os dados são expostos por função, não por variável de topo: `wp eval-file`
 * executa o arquivo dentro de uma função, então variável de topo do include
 * vazaria para o escopo de quem inclui e não sobreviveria a um segundo include.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Categorias e subcategorias propostas, com faceta.
 *
 * `required_facets` é o que define "produto classificado": faltando qualquer uma
 * delas, o produto vai para revisão em vez de entrar incompleto.
 *
 * `missing_facet_decision` é a decisão humana que desbloqueia aquela faceta.
 *
 * @return array<string,mixed>
 */
function papelito_taxonomy_seed() {
	return array(
		'sedas'      => array(
			'name'                   => 'Sedas',
			'sort_order'             => 0,
			'required_facets'        => array( 'material', 'formato' ),
			'missing_facet_decision' => array( 'material' => 'D1' ),
			'subcategories'          => array(
				array(
					'slug'  => 'tradicional',
					'name'  => 'Tradicional',
					'facet' => 'material',
				),
				array(
					'slug'  => 'brown',
					'name'  => 'Brown',
					'facet' => 'material',
				),
				array(
					'slug'  => 'hemp',
					'name'  => 'Hemp',
					'facet' => 'material',
				),
				array(
					'slug'  => 'alfafa',
					'name'  => 'Alfafa',
					'facet' => 'material',
				),
				array(
					'slug'  => 'slim',
					'name'  => 'Slim',
					'facet' => 'formato',
				),
				array(
					'slug'  => 'king-size',
					'name'  => 'King Size',
					'facet' => 'formato',
				),
				array(
					'slug'  => 'mini-size',
					'name'  => 'Mini Size',
					'facet' => 'formato',
				),
				array(
					'slug'  => 'longa',
					'name'  => 'Longa',
					'facet' => 'formato',
				),
				array(
					'slug'  => 'com-piteira',
					'name'  => 'Com Piteira',
					'facet' => 'formato',
				),
				array(
					'slug'  => 'insane',
					'name'  => 'Insane',
					'facet' => 'linha',
				),
				array(
					'slug'  => 'pink',
					'name'  => 'Pink',
					'facet' => 'linha',
				),
			),
		),
		'piteiras'   => array(
			'name'                   => 'Piteiras',
			'sort_order'             => 1,
			'required_facets'        => array( 'tamanho' ),
			'missing_facet_decision' => array(),
			'subcategories'          => array(
				array(
					'slug'  => 'tradicional',
					'name'  => 'Tradicional',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'slim',
					'name'  => 'Slim',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'large',
					'name'  => 'Large',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'longa',
					'name'  => 'Longa',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'mega-longa',
					'name'  => 'Mega Longa',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'ultra-longa',
					'name'  => 'Ultra Longa',
					'facet' => 'tamanho',
				),
			),
		),
		'filtros'    => array(
			'name'                   => 'Filtros',
			'sort_order'             => 2,
			'required_facets'        => array( 'tipo' ),
			'missing_facet_decision' => array( 'tipo' => 'D4' ),
			'subcategories'          => array(
				array(
					'slug'  => 'tradicional',
					'name'  => 'Tradicional',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'bio',
					'name'  => 'Bio',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'mentol',
					'name'  => 'Mentol',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'gomado',
					'name'  => 'Gomado',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'slim',
					'name'  => 'Slim',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'longo',
					'name'  => 'Longo',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'ultra-longo',
					'name'  => 'Ultra Longo',
					'facet' => 'tamanho',
				),
			),
		),
		'acessorios' => array(
			'name'                   => 'Acessórios',
			'sort_order'             => 3,
			'required_facets'        => array( 'tipo' ),
			'missing_facet_decision' => array( 'tipo' => 'D5' ),
			'subcategories'          => array(
				array(
					'slug'  => 'dichavador',
					'name'  => 'Dichavador',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'tubelito',
					'name'  => 'Tubelito',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'bandeja',
					'name'  => 'Bandeja',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'bandeja-chaveiro',
					'name'  => 'Bandeja Chaveiro',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'cinzeiro',
					'name'  => 'Cinzeiro',
					'facet' => 'tipo',
				),
				array(
					'slug'  => 'p',
					'name'  => 'P',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'm',
					'name'  => 'M',
					'facet' => 'tamanho',
				),
				array(
					'slug'  => 'g',
					'name'  => 'G',
					'facet' => 'tamanho',
				),
			),
		),
	);
}

/**
 * Mapa determinístico `raiz_slug|filho_slug` → destino.
 *
 * Chave com filho vazio cobre o produto que só tem a raiz. `decisions` sobrepõe
 * a decisão padrão da categoria quando a origem tem um motivo próprio.
 *
 * @return array<string,array<string,mixed>>
 */
function papelito_taxonomy_legacy_map() {
	return array(
		'papel|tradicional'             => array(
			'category'      => 'sedas',
			'subcategories' => array( 'tradicional' ),
		),
		'papel|brown'                   => array(
			'category'      => 'sedas',
			'subcategories' => array( 'brown' ),
		),
		// `Papel > Slim` é LARGURA, não material: o material do papel nunca foi registrado.
		'papel|slim'                    => array(
			'category'      => 'sedas',
			'subcategories' => array( 'slim' ),
			'decisions'     => array( 'material' => 'D8' ),
		),
		'papel|hemp'                    => array(
			'category'      => 'sedas',
			'subcategories' => array( 'hemp' ),
		),
		'papel|brown-slim'              => array(
			'category'      => 'sedas',
			'subcategories' => array( 'brown', 'slim' ),
		),
		'papel|premium'                 => array(
			'category'      => 'sedas',
			'subcategories' => array(),
			'collections'   => array( 'premium' ),
			'decisions'     => array( 'material' => 'D1' ),
		),
		'papel|'                        => array(
			'category'      => 'sedas',
			'subcategories' => array(),
		),

		'piteiras|tradicional-piteiras' => array(
			'category'      => 'piteiras',
			'subcategories' => array( 'tradicional' ),
		),
		'piteiras|slim-piteiras'        => array(
			'category'      => 'piteiras',
			'subcategories' => array( 'slim' ),
		),
		'piteiras|large'                => array(
			'category'      => 'piteiras',
			'subcategories' => array( 'large' ),
		),
		'piteiras|longas'               => array(
			'category'      => 'piteiras',
			'subcategories' => array(),
		),
		'piteiras|'                     => array(
			'category'      => 'piteiras',
			'subcategories' => array(),
		),

		'filtro|tradicional-filtros'    => array(
			'category'      => 'filtros',
			'subcategories' => array( 'tradicional' ),
		),
		'filtro|bio'                    => array(
			'category'      => 'filtros',
			'subcategories' => array( 'bio' ),
		),
		'filtro|bio-longo'              => array(
			'category'      => 'filtros',
			'subcategories' => array( 'bio', 'longo' ),
		),
		'filtro|mentol'                 => array(
			'category'      => 'filtros',
			'subcategories' => array( 'mentol' ),
		),
		'filtro|gomado'                 => array(
			'category'      => 'filtros',
			'subcategories' => array( 'gomado' ),
		),
		'filtro|longo'                  => array(
			'category'      => 'filtros',
			'subcategories' => array( 'longo' ),
		),
		'filtro|ultra-longo'            => array(
			'category'      => 'filtros',
			'subcategories' => array( 'ultra-longo' ),
		),
		'filtro|slim-filtros'           => array(
			'category'      => 'filtros',
			'subcategories' => array( 'slim' ),
		),
		'filtro|slim-longo'             => array(
			'category'      => 'filtros',
			'subcategories' => array( 'slim', 'longo' ),
		),
		'filtro|'                       => array(
			'category'      => 'filtros',
			'subcategories' => array(),
		),

		'acessorios|dichavador'         => array(
			'category'      => 'acessorios',
			'subcategories' => array( 'dichavador' ),
		),
		'acessorios|tubelito'           => array(
			'category'      => 'acessorios',
			'subcategories' => array( 'tubelito' ),
		),
		'acessorios|bandeja-chaveiro'   => array(
			'category'      => 'acessorios',
			'subcategories' => array( 'bandeja-chaveiro' ),
		),
		'acessorios|'                   => array(
			'category'      => 'acessorios',
			'subcategories' => array(),
		),

		// Coleção, não categoria. Sem produto hoje; a entrada existe para o dia em que houver.
		'kits|'                         => array(
			'category'      => null,
			'subcategories' => array(),
			'collections'   => array( 'kits' ),
		),
		'sem-categoria|'                => array(
			'category'      => null,
			'subcategories' => array(),
		),
	);
}

/**
 * Tokens de título por categoria, DO MAIS LONGO PARA O MAIS CURTO.
 *
 * A ordem é significativa: o trecho casado é consumido do título, então
 * `Mega Longa` tem de ser testado antes de `Longa`. Sem isso, `Piteira Mega
 * Longa` casaria `Longa` e seria classificada com o tamanho errado.
 *
 * @return array<string,array<string,string|array<string>>>
 */
function papelito_taxonomy_title_tokens() {
	return array(
		'sedas'      => array(
			'Com Piteira' => 'com-piteira',
			'King Size'   => 'king-size',
			'Mini Size'   => 'mini-size',
			'Brown Slim'  => array( 'brown', 'slim' ),
			'Tradicional' => 'tradicional',
			'Alfafa'      => 'alfafa',
			'Insane'      => 'insane',
			'Brown'       => 'brown',
			'Hemp'        => 'hemp',
			'Longa'       => 'longa',
			'Slim'        => 'slim',
			'Pink'        => 'pink',
		),
		'piteiras'   => array(
			'Mega Longa'  => 'mega-longa',
			'Ultra Longa' => 'ultra-longa',
			'Tradicional' => 'tradicional',
			'Large'       => 'large',
			'Longa'       => 'longa',
			'Slim'        => 'slim',
		),
		'filtros'    => array(
			'Ultra Longo' => 'ultra-longo',
			'Slim Longo'  => array( 'slim', 'longo' ),
			'Bio Longo'   => array( 'bio', 'longo' ),
			'Tradicional' => 'tradicional',
			'Mentol'      => 'mentol',
			'Gomado'      => 'gomado',
			'Longo'       => 'longo',
			'Slim'        => 'slim',
			'Bio'         => 'bio',
		),
		'acessorios' => array(
			'Bandeja Chaveiro' => 'bandeja-chaveiro',
			'Dichavador'       => 'dichavador',
			'Tubelito'         => 'tubelito',
			'Cinzeiro'         => 'cinzeiro',
			'Bandeja'          => 'bandeja',
		),
	);
}

/**
 * Decisões humanas pendentes, para o relatório apontar o que desbloqueia o quê.
 *
 * @return array<string,string>
 */
function papelito_taxonomy_decisions() {
	return array(
		'D1' => 'Material das sedas Premium sem material no título (Insane King Size, Pink King Size).',
		'D2' => '"Insane" e "Pink" são linha comercial ou material?',
		'D3' => 'Acabamento de acessório (Brilho, Cores, Cristal, Neon, Black, P Amarelo, P Relax): subcategoria ou atributo pa_cor?',
		'D4' => 'Filtro com tamanho mas sem tipo (Longo, Ultra Longo, Slim, Slim Longo): assumir tipo Tradicional ou deixar sem tipo?',
		'D5' => 'Acessório precisa de subcategoria obrigatória? (Cinzeiro, Bandeja P/M/G)',
		'D8' => 'As 4 "Seda Slim" não têm material: `Papel > Slim` registrava LARGURA, não material. O SKU trata Slim como família própria (02), distinta de Tradicional (01), e Brown Slim (04) como distinta de Brown (03) — o que sugere Slim = papel tradicional em largura slim. Confirmar.',
	);
}

/**
 * Inversas explícitas para termos-bucket que não têm inversa natural.
 *
 * `Longas` era um bucket de tamanho: `piteiras|longas` não declara subcategoria
 * nenhuma, então a inversão automática não sabe voltar a ele e o dual-write
 * devolveria a piteira a `product_cat` como apenas `Piteiras` — fazendo os 3
 * produtos desaparecerem do arquivo `Longas` na loja legada.
 *
 * Chave: `<categoria>|<slugs,ordenados>`. Valor: slug do termo legado.
 *
 * @return array<string,string>
 */
function papelito_taxonomy_legacy_inverse_hints() {
	return array(
		'piteiras|longa'       => 'longas',
		'piteiras|mega-longa'  => 'longas',
		'piteiras|ultra-longa' => 'longas',
	);
}

/**
 * Faceta de uma subcategoria dentro de uma categoria do seed.
 *
 * @param string $category_slug Slug da categoria.
 * @param string $sub_slug      Slug da subcategoria.
 * @return string|null
 */
function papelito_seed_facet( $category_slug, $sub_slug ) {
	$seed = papelito_taxonomy_seed();

	foreach ( $seed[ $category_slug ]['subcategories'] ?? array() as $subcategory ) {
		if ( $subcategory['slug'] === $sub_slug ) {
			return $subcategory['facet'];
		}
	}

	return null;
}

/**
 * Deriva subcategorias do título, consumindo o trecho casado.
 *
 * Casamento exato de token, sem heurística difusa: token desconhecido não vira
 * classificação, vira ausência — que o relatório trata como pendência. Foi a
 * classificação por substring que fazia toda categoria desconhecida cair em
 * ACESSÓRIOS no frontend.
 *
 * @param string                             $title  Título do produto.
 * @param array<string,string|array<string>> $tokens Tokens da categoria.
 * @return string[] Slugs de subcategoria.
 */
function papelito_derive_from_title( $title, array $tokens ) {
	$rest  = ' ' . preg_replace( '/\s+/', ' ', (string) $title ) . ' ';
	$found = array();

	foreach ( $tokens as $needle => $slugs ) {
		$position = stripos( $rest, ' ' . $needle . ' ' );

		if ( false === $position ) {
			continue;
		}

		$rest  = substr_replace( $rest, ' ', $position, strlen( $needle ) + 2 );
		$found = array_merge( $found, is_array( $slugs ) ? $slugs : array( $slugs ) );
	}

	return array_values( array_unique( $found ) );
}

/**
 * Tamanho P/M/G de bandeja.
 *
 * Restrito ao tipo `bandeja`: `Bandeja Chaveiro P Amarelo` tem um "P" que é cor,
 * não tamanho. Derivar sem essa guarda classificaria chaveiro como tamanho P.
 *
 * @param string   $title Título do produto.
 * @param string[] $types Subcategorias já derivadas.
 * @return string[]
 */
function papelito_derive_tray_size( $title, array $types ) {
	if ( ! in_array( 'bandeja', $types, true ) || in_array( 'bandeja-chaveiro', $types, true ) ) {
		return array();
	}

	if ( preg_match( '/\bBandeja\s+([PMG])\b/i', (string) $title, $matches ) ) {
		return array( strtolower( $matches[1] ) );
	}

	return array();
}

/**
 * Resolve o destino de um produto a partir da origem em `product_cat`.
 *
 * Função pura: recebe o que foi lido do banco, devolve o destino e as pendências.
 *
 * @param string   $title       Título do produto.
 * @param string[] $root_slugs  Slugs das raízes de `product_cat`.
 * @param string[] $child_slugs Slugs dos filhos de `product_cat`.
 * @return array<string,mixed>
 */
function papelito_taxonomy_resolve_destination( $title, array $root_slugs, array $child_slugs ) {
	$map     = papelito_taxonomy_legacy_map();
	$seed    = papelito_taxonomy_seed();
	$destino = array(
		'category'      => null,
		'subcategories' => array(),
		'collections'   => array(),
		'pendencias'    => array(),
		'notas'         => array(),
	);

	if ( empty( $root_slugs ) ) {
		$destino['pendencias'][] = 'sem categoria raiz em product_cat — classificar à mão';

		return $destino;
	}

	if ( count( $root_slugs ) > 1 ) {
		$destino['pendencias'][] = 'produto em mais de uma raiz (' . implode( ', ', $root_slugs ) . ') — escolher a categoria principal';

		return $destino;
	}

	$root  = $root_slugs[0];
	$child = '';

	foreach ( $child_slugs as $candidate ) {
		if ( isset( $map[ $root . '|' . $candidate ] ) ) {
			$child = $candidate;
			break;
		}
	}

	if ( '' === $child && ! empty( $child_slugs ) ) {
		$destino['notas'][] = 'filho "' . implode( ', ', $child_slugs ) . '" não está no mapa; usada a regra da raiz';
	}

	$key = $root . '|' . $child;

	if ( ! isset( $map[ $key ] ) ) {
		$destino['pendencias'][] = 'par "' . $key . '" fora do mapa determinístico';

		return $destino;
	}

	$regra                  = $map[ $key ];
	$destino['collections'] = $regra['collections'] ?? array();
	$category               = $regra['category'];

	if ( null === $category ) {
		$destino['pendencias'][] = 'origem "' . $key . '" não corresponde a nenhuma categoria nova (virou coleção ou foi descartada)';

		return $destino;
	}

	$destino['category'] = $category;

	$subs = array_merge(
		$regra['subcategories'] ?? array(),
		papelito_derive_from_title( $title, papelito_taxonomy_title_tokens()[ $category ] ?? array() )
	);
	$subs = array_values( array_unique( $subs ) );
	$subs = array_values( array_unique( array_merge( $subs, papelito_derive_tray_size( $title, $subs ) ) ) );

	$validas    = array();
	$por_faceta = array();

	foreach ( $subs as $slug ) {
		$facet = papelito_seed_facet( $category, $slug );

		if ( null === $facet ) {
			$destino['notas'][] = 'subcategoria "' . $slug . '" derivada mas ausente do seed de ' . $category;
			continue;
		}

		$validas[]              = $slug;
		$por_faceta[ $facet ][] = $slug;
	}

	$destino['subcategories'] = $validas;
	$decisoes                 = array_merge(
		$seed[ $category ]['missing_facet_decision'] ?? array(),
		$regra['decisions'] ?? array()
	);

	foreach ( $seed[ $category ]['required_facets'] as $facet ) {
		if ( empty( $por_faceta[ $facet ] ) ) {
			$codigo                  = $decisoes[ $facet ] ?? '';
			$destino['pendencias'][] = 'faceta obrigatória "' . $facet . '" vazia' . ( '' === $codigo ? '' : ' (' . $codigo . ')' );
		}
	}

	if ( 'acessorios' === $category && array_intersect( $validas, array( 'dichavador', 'tubelito', 'bandeja-chaveiro' ) ) ) {
		$destino['notas'][] = 'acabamento/cor do título não virou subcategoria — depende de D3';
	}

	return $destino;
}
