#!/usr/bin/env php
<?php
/**
 * Testes do mapa de migração da taxonomia.
 *
 * Trava as armadilhas que fariam a migração classificar errado em silêncio:
 * casamento de token mais longo primeiro, a guarda de tamanho da bandeja, a
 * decomposição dos termos combinados, e a integridade referencial entre o mapa
 * legado e o seed.
 *
 *   docker cp scripts/catalog/taxonomy_map.php papelito-web:/tmp/
 *   docker cp scripts/catalog/test_taxonomy_map.php papelito-web:/tmp/
 *   docker compose exec -T web wp eval-file /tmp/test_taxonomy_map.php
 *
 * Não toca no banco.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file test_taxonomy_map.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once __DIR__ . '/taxonomy_map.php';

global $failures, $checks;

$failures = 0;
$checks   = 0;

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param string $label    Descrição da checagem.
 * @param mixed  $expected Valor esperado.
 * @param mixed  $actual   Valor obtido.
 * @return void
 */
function assert_map( string $label, $expected, $actual ): void {
	global $failures, $checks;

	++$checks;

	if ( $expected === $actual ) {
		echo '  ok   ' . esc_html( $label ) . "\n";

		return;
	}

	++$failures;

	echo '  FAIL ' . esc_html( $label ) . "\n";
	echo '       esperado: ' . esc_html( (string) wp_json_encode( $expected ) ) . "\n";
	echo '       obtido:   ' . esc_html( (string) wp_json_encode( $actual ) ) . "\n";
}

/**
 * Resolve um destino e devolve as subcategorias ordenadas, para comparação estável.
 *
 * @param string   $title    Título do produto.
 * @param string[] $roots    Raízes.
 * @param string[] $children Filhos.
 * @return string[]
 */
function map_subs( string $title, array $roots, array $children ): array {
	$destino = papelito_taxonomy_resolve_destination( $title, $roots, $children );
	$subs    = $destino['subcategories'];

	sort( $subs );

	return $subs;
}

echo "\nCasamento de token: o mais longo primeiro\n";

assert_map( 'Piteira Mega Longa nao casa Longa', array( 'mega-longa' ), map_subs( 'Piteira Mega Longa', array( 'piteiras' ), array( 'longas' ) ) );
assert_map( 'Piteira Ultra Longa nao casa Longa', array( 'ultra-longa' ), map_subs( 'Piteira Ultra Longa', array( 'piteiras' ), array( 'longas' ) ) );
assert_map( 'Piteira Longa casa Longa', array( 'longa' ), map_subs( 'Piteira Longa', array( 'piteiras' ), array( 'longas' ) ) );
assert_map( 'Filtro Ultra Longo nao casa Longo', array( 'ultra-longo' ), map_subs( 'Filtro Ultra Longo', array( 'filtro' ), array( 'ultra-longo' ) ) );

echo "\nGuarda de tamanho da bandeja\n";

assert_map( 'Bandeja P vira bandeja + tamanho P', array( 'bandeja', 'p' ), map_subs( 'Bandeja P', array( 'acessorios' ), array() ) );
assert_map( 'Bandeja G vira bandeja + tamanho G', array( 'bandeja', 'g' ), map_subs( 'Bandeja G', array( 'acessorios' ), array() ) );
assert_map( 'Bandeja Chaveiro P Amarelo NÃO vira tamanho P', array( 'bandeja-chaveiro' ), map_subs( 'Bandeja Chaveiro P Amarelo', array( 'acessorios' ), array( 'bandeja-chaveiro' ) ) );
assert_map( 'Bandeja Chaveiro Black não inventa tamanho', array( 'bandeja-chaveiro' ), map_subs( 'Bandeja Chaveiro Black', array( 'acessorios' ), array( 'bandeja-chaveiro' ) ) );
assert_map( 'Cinzeiro vira tipo cinzeiro', array( 'cinzeiro' ), map_subs( 'Cinzeiro', array( 'acessorios' ), array() ) );

echo "\nDecomposição de termo combinado\n";

assert_map( 'Brown Slim decompõe em brown + slim', array( 'brown', 'king-size', 'slim' ), map_subs( 'Seda Brown Slim King Size', array( 'papel' ), array( 'brown-slim' ) ) );
assert_map( 'Slim Longo decompõe em slim + longo', array( 'longo', 'slim' ), map_subs( 'Filtro Slim Longo', array( 'filtro' ), array( 'slim-longo' ) ) );
assert_map( 'Bio Longo decompõe em bio + longo', array( 'bio', 'longo' ), map_subs( 'Filtro Bio Longo', array( 'filtro' ), array( 'bio-longo' ) ) );

echo "\nDerivação do título\n";

assert_map( 'Seda brown Longa casa mesmo em minuscula', array( 'brown', 'longa' ), map_subs( 'Seda brown Longa', array( 'papel' ), array( 'brown' ) ) );
assert_map( 'Com Piteira é consumido antes de Piteira sobrar', array( 'brown', 'com-piteira' ), map_subs( 'Seda brown Com Piteira', array( 'papel' ), array( 'brown' ) ) );
assert_map( 'Insane Brown traz material brown e linha insane', array( 'brown', 'insane', 'king-size' ), map_subs( 'Seda Insane Brown King Size', array( 'papel' ), array( 'premium' ) ) );
assert_map( 'Alfafa é material e vem do título', array( 'alfafa', 'king-size' ), map_subs( 'Seda Alfafa King Size', array( 'papel' ), array( 'premium' ) ) );

echo "\nDecisões de classificação confirmadas\n";

$insane = papelito_taxonomy_resolve_destination( 'Seda Insane King Size', array( 'papel' ), array( 'premium' ) );
assert_map( 'Insane usa linha e família Tradicional confirmada pelo SKU', array( 'insane', 'king-size', 'tradicional' ), map_subs( 'Seda Insane King Size', array( 'papel' ), array( 'premium' ) ) );
assert_map( 'Insane sem material não fica pendente', array(), $insane['pendencias'] );

$slim = papelito_taxonomy_resolve_destination( 'Seda Slim King Size', array( 'papel' ), array( 'slim' ) );
assert_map( 'Seda Slim é variante válida sem material implícito', array(), $slim['pendencias'] );

$filtro_slim = papelito_taxonomy_resolve_destination( 'Filtro Slim', array( 'filtro' ), array( 'slim-filtros' ) );
assert_map( 'Filtro sem tipo fica classificado pela variante conhecida', array(), $filtro_slim['pendencias'] );

$sem_raiz = papelito_taxonomy_resolve_destination( 'Produto solto', array(), array() );
assert_map( 'produto sem raiz fica pendente', 1, count( $sem_raiz['pendencias'] ) );
assert_map( 'produto sem raiz não recebe categoria', null, $sem_raiz['category'] );

$duas_raizes = papelito_taxonomy_resolve_destination( 'Produto confuso', array( 'papel', 'filtro' ), array() );
assert_map( 'produto em duas raízes fica pendente', 1, count( $duas_raizes['pendencias'] ) );
assert_map( 'produto em duas raízes não recebe categoria', null, $duas_raizes['category'] );

$filho_novo = papelito_taxonomy_resolve_destination( 'Seda Tradicional King Size', array( 'papel' ), array( 'termo-que-nao-existe' ) );
assert_map( 'filho fora do mapa cai na regra da raiz', 'sedas', $filho_novo['category'] );
assert_map( 'filho fora do mapa gera nota', true, ! empty( $filho_novo['notas'] ) );

echo "\nColeções, não categorias\n";

$premium = papelito_taxonomy_resolve_destination( 'Seda Pink King Size', array( 'papel' ), array( 'premium' ) );
assert_map( 'Premium vira coleção', array( 'premium' ), $premium['collections'] );
assert_map( 'Premium continua na categoria sedas', 'sedas', $premium['category'] );

$kits = papelito_taxonomy_resolve_destination( 'Kit qualquer', array( 'kits' ), array() );
assert_map( 'Kits não vira categoria', null, $kits['category'] );
assert_map( 'Kits vira coleção', array( 'kits' ), $kits['collections'] );

echo "\nIntegridade do seed e do mapa\n";

$seed       = papelito_taxonomy_seed();
$erros_slug = array();

foreach ( $seed as $slug => $categoria ) {
	$vistos = array();

	foreach ( $categoria['subcategories'] as $subcategory ) {
		if ( isset( $vistos[ $subcategory['slug'] ] ) ) {
			$erros_slug[] = $slug . '/' . $subcategory['slug'];
		}

		$vistos[ $subcategory['slug'] ] = true;
	}
}

assert_map( 'nenhum slug duplicado dentro da mesma categoria', array(), $erros_slug );

$fora_do_seed = array();

foreach ( papelito_taxonomy_legacy_map() as $chave => $regra ) {
	if ( null === $regra['category'] ) {
		continue;
	}

	foreach ( $regra['subcategories'] as $sub ) {
		if ( null === papelito_seed_facet( $regra['category'], $sub ) ) {
			$fora_do_seed[] = $chave . ' → ' . $regra['category'] . '/' . $sub;
		}
	}
}

assert_map( 'toda subcategoria do mapa legado existe no seed', array(), $fora_do_seed );

$tokens_fora = array();

foreach ( papelito_taxonomy_title_tokens() as $categoria => $tokens ) {
	foreach ( $tokens as $needle => $slugs ) {
		foreach ( is_array( $slugs ) ? $slugs : array( $slugs ) as $sub ) {
			if ( null === papelito_seed_facet( $categoria, $sub ) ) {
				$tokens_fora[] = $categoria . ' "' . $needle . '" → ' . $sub;
			}
		}
	}
}

assert_map( 'todo token de título aponta para subcategoria do seed', array(), $tokens_fora );

$decisoes_conhecidas = array_keys( papelito_taxonomy_decisions() );
$decisoes_usadas     = array();

foreach ( $seed as $categoria ) {
	foreach ( $categoria['missing_facet_decision'] ?? array() as $codigo ) {
		$decisoes_usadas[ $codigo ] = true;
	}
}

foreach ( papelito_taxonomy_legacy_map() as $regra ) {
	foreach ( $regra['decisions'] ?? array() as $codigo ) {
		$decisoes_usadas[ $codigo ] = true;
	}
}

assert_map( 'toda decisão citada tem texto no catálogo', array(), array_values( array_diff( array_keys( $decisoes_usadas ), $decisoes_conhecidas ) ) );

echo "\n";
echo $failures > 0
	? 'FALHOU: ' . esc_html( (string) $failures ) . ' de ' . esc_html( (string) $checks ) . " checagens\n"
	: 'OK: ' . esc_html( (string) $checks ) . " checagens\n";

exit( $failures > 0 ? 1 : 0 );
