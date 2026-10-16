<?php
/**
 * Associação entre a descrição do ERP do vendor e os produtos do catálogo.
 *
 * Funções puras: recebem texto e perfis já carregados e não tocam o banco. A
 * decisão é estrutural — tipo, atributos por faceta, números e palavras que
 * sobram — e nunca por nota de similaridade. `similar_text` só ordena as
 * sugestões de revisão. Contradição em qualquer faceta torna o par
 * incompatível; informação presente de um lado só manda para revisão humana.
 *
 * Não decide quem pode gravar nem grava: isso é de `vendor_code_import.php`.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minúsculas, sem acento, sem HTML, travessões unificados e espaços colapsados.
 *
 * Preserva `/`, `+`, `,` e `x` porque os extratores numéricos dependem deles.
 *
 * @param string $text Descrição da planilha ou título/descrição do catálogo.
 */
function papelito_vendor_code_import_normalize( string $text ): string {
	$text = remove_accents( wp_strip_all_tags( $text ) );
	$text = mb_strtolower( $text, 'UTF-8' );
	$text = str_replace( array( "\u{2013}", "\u{2014}" ), '-', $text );
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Converte o restante do texto em palavras: `+` e `c/` viram "com", o resto da pontuação vira espaço.
 *
 * @param string $text Texto já normalizado e sem os trechos numéricos.
 * @return string[]
 */
function papelito_vendor_code_import_words( string $text ): array {
	$text = str_replace( '+', ' com ', $text );
	$text = (string) preg_replace( '/\bc\s*\//', ' com ', $text );
	$text = (string) preg_replace( '/[^a-z0-9]+/', ' ', $text );
	return array_values( array_filter( explode( ' ', $text ), 'strlen' ) );
}

/**
 * Número decimal da planilha (`12,5`) no formato canônico (`12.5`), sem zeros sobrando.
 *
 * @param string $raw Número como veio no texto.
 */
function papelito_vendor_code_import_decimal( string $raw ): string {
	$value = str_replace( ',', '.', $raw );
	return str_contains( $value, '.' ) ? rtrim( rtrim( $value, '0' ), '.' ) : ltrim( $value, '0' );
}

/**
 * Extrai quantidade, dimensões e gramatura e devolve o texto sem esses trechos.
 *
 * @param string $text Texto normalizado.
 * @return array{text:string,qty:?int,dim:string[],weight:string[]}
 */
function papelito_vendor_code_import_extract_numbers( string $text ): array {
	$patterns = papelito_vendor_code_import_number_patterns();
	$numbers  = array(
		'qty'    => null,
		'dim'    => array(),
		'weight' => array(),
	);
	foreach ( $patterns['qty'] as $pattern ) {
		if ( null === $numbers['qty'] && preg_match( $pattern, $text, $found ) ) {
			$numbers['qty'] = (int) $found[1];
		}
		$text = (string) preg_replace( $pattern, ' ', $text );
	}
	if ( preg_match_all( $patterns['dim'], $text, $dims, PREG_SET_ORDER ) ) {
		foreach ( $dims as $dim ) {
			$numbers['dim'][] = papelito_vendor_code_import_decimal( $dim[1] ) . 'x' . papelito_vendor_code_import_decimal( $dim[2] );
		}
		$text = (string) preg_replace( $patterns['dim'], ' ', $text );
	}
	if ( preg_match_all( $patterns['weight'], $text, $weights ) ) {
		$numbers['weight'] = array_map( 'papelito_vendor_code_import_decimal', $weights[1] );
		$text              = (string) preg_replace( $patterns['weight'], ' ', $text );
	}
	$numbers['text'] = $text;
	return $numbers;
}

/**
 * Quantidade e dimensões declaradas na descrição do produto do catálogo.
 *
 * @param string $content Descrição longa do produto.
 * @return array{qty:?int,dim:string[]}
 */
function papelito_vendor_code_import_catalog_numbers( string $content ): array {
	$text     = papelito_vendor_code_import_normalize( $content );
	$patterns = papelito_vendor_code_import_number_patterns();
	$qty      = preg_match( $patterns['catalog_qty'], $text, $found ) ? (int) $found[1] : null;
	$dims     = array();
	if ( preg_match_all( $patterns['dim'], $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $dim ) {
			$dims[] = papelito_vendor_code_import_decimal( $dim[1] ) . 'x' . papelito_vendor_code_import_decimal( $dim[2] );
		}
	}
	return array(
		'qty' => $qty,
		'dim' => array_values( array_unique( $dims ) ),
	);
}

/**
 * Entradas do dicionário achatadas e ordenadas da frase mais longa para a mais curta.
 *
 * "ultra longa" precisa ser consumida antes de "longa", e "com piteira" antes
 * de "piteira", senão o combo viraria o tipo do produto.
 *
 * @param array $dictionary Dicionário de `papelito_vendor_code_import_dictionary()`.
 * @return array<int,array{words:string[],kind:string,facet:string,value:string,synonym:bool}>
 */
function papelito_vendor_code_import_entries( array $dictionary ): array {
	$entries = array();
	foreach ( $dictionary['types'] as $value => $term ) {
		$entries = array_merge( $entries, papelito_vendor_code_import_term_entries( 'type', '', (string) $value, $term ) );
	}
	foreach ( $dictionary['facets'] as $facet => $terms ) {
		foreach ( $terms as $value => $term ) {
			$entries = array_merge( $entries, papelito_vendor_code_import_term_entries( 'facet', (string) $facet, (string) $value, $term ) );
		}
	}
	usort( $entries, 'papelito_vendor_code_import_compare_entries' );
	return $entries;
}

/**
 * Entradas de um termo: uma por grafia e uma por sinônimo.
 *
 * @param string              $kind  `type` ou `facet`.
 * @param string              $facet Faceta do termo; vazio para tipo.
 * @param string              $value Valor canônico.
 * @param array<string,array> $term  `forms` e `synonyms`.
 * @return array<int,array{words:string[],kind:string,facet:string,value:string,synonym:bool}>
 */
function papelito_vendor_code_import_term_entries( string $kind, string $facet, string $value, array $term ): array {
	$entries = array();
	foreach ( array( 'forms', 'synonyms' ) as $group ) {
		foreach ( (array) ( $term[ $group ] ?? array() ) as $phrase ) {
			$entries[] = array(
				'words'   => explode( ' ', (string) $phrase ),
				'kind'    => $kind,
				'facet'   => $facet,
				'value'   => $value,
				'synonym' => 'synonyms' === $group,
			);
		}
	}
	return $entries;
}

/**
 * Ordena entradas pela quantidade de palavras, da maior para a menor.
 *
 * @param array $a Entrada.
 * @param array $b Entrada.
 */
function papelito_vendor_code_import_compare_entries( array $a, array $b ): int {
	return count( $b['words'] ) <=> count( $a['words'] );
}

/**
 * Vocabulário de palavras soltas do dicionário, para levar plurais ao singular.
 *
 * @param array $entries Entradas achatadas.
 * @return array<string,true>
 */
function papelito_vendor_code_import_vocabulary( array $entries ): array {
	$vocabulary = array();
	foreach ( $entries as $entry ) {
		foreach ( $entry['words'] as $word ) {
			$vocabulary[ $word ] = true;
		}
	}
	return $vocabulary;
}

/**
 * Leva "piteiras" e "trituradores" ao singular só quando o singular é termo conhecido.
 *
 * @param string             $word       Palavra.
 * @param array<string,true> $vocabulary Vocabulário do dicionário.
 */
function papelito_vendor_code_import_singular( string $word, array $vocabulary ): string {
	if ( isset( $vocabulary[ $word ] ) ) {
		return $word;
	}
	foreach ( array( 'es', 's' ) as $suffix ) {
		$stem = str_ends_with( $word, $suffix ) ? substr( $word, 0, -strlen( $suffix ) ) : '';
		if ( '' !== $stem && isset( $vocabulary[ $stem ] ) ) {
			return $stem;
		}
	}
	return $word;
}

/**
 * Tenta casar a entrada a partir da posição; devolve quantas palavras consumiu.
 *
 * @param string[] $words    Palavras do texto.
 * @param int      $position Posição inicial.
 * @param array    $entry    Entrada do dicionário.
 */
function papelito_vendor_code_import_entry_length( array $words, int $position, array $entry ): int {
	$length = count( $entry['words'] );
	return array_slice( $words, $position, $length ) === $entry['words'] ? $length : 0;
}

/**
 * Perfil estrutural de um texto: tipo, facetas, números e palavras que sobraram.
 *
 * O segundo tipo encontrado no mesmo texto não substitui o primeiro; vai para
 * as sobras e impede correspondência exata. `synonym` diz se algum termo só foi
 * reconhecido por sinônimo comercial.
 *
 * @param string $text       Texto bruto.
 * @param array  $dictionary Dicionário.
 * @return array{type:?string,facets:array<string,string[]>,qty:?int,dim:string[],weight:string[],residual:string[],synonym:bool,normalized:string}
 */
function papelito_vendor_code_import_features( string $text, array $dictionary ): array {
	$entries    = papelito_vendor_code_import_entries( $dictionary );
	$vocabulary = papelito_vendor_code_import_vocabulary( $entries );
	$numbers    = papelito_vendor_code_import_extract_numbers( papelito_vendor_code_import_normalize( $text ) );
	$words      = array();
	foreach ( papelito_vendor_code_import_words( $numbers['text'] ) as $word ) {
		$words[] = papelito_vendor_code_import_singular( $word, $vocabulary );
	}
	$profile  = array(
		'type'       => null,
		'facets'     => array(),
		'qty'        => $numbers['qty'],
		'dim'        => $numbers['dim'],
		'weight'     => $numbers['weight'],
		'residual'   => array(),
		'synonym'    => false,
		'normalized' => implode( ' ', $words ),
	);
	$position = 0;
	$total    = count( $words );
	while ( $position < $total ) {
		$consumed  = papelito_vendor_code_import_consume( $words, $position, $entries, $profile );
		$position += max( 1, $consumed );
	}
	$profile['residual'] = papelito_vendor_code_import_strip_fillers( $profile['residual'], $profile['type'], $dictionary );
	sort( $profile['residual'] );
	foreach ( $profile['facets'] as $facet => $values ) {
		$profile['facets'][ $facet ] = array_values( array_unique( $values ) );
		sort( $profile['facets'][ $facet ] );
	}
	return $profile;
}

/**
 * Consome a maior entrada que casa na posição e anota no perfil; sem entrada, a palavra sobra.
 *
 * @param string[] $words    Palavras do texto.
 * @param int      $position Posição atual.
 * @param array    $entries  Entradas ordenadas.
 * @param array    $profile  Perfil em construção, alterado por referência.
 */
function papelito_vendor_code_import_consume( array $words, int $position, array $entries, array &$profile ): int {
	foreach ( $entries as $entry ) {
		$length = papelito_vendor_code_import_entry_length( $words, $position, $entry );
		if ( $length > 0 ) {
			papelito_vendor_code_import_apply_entry( $entry, $profile );
			return $length;
		}
	}
	$profile['residual'][] = $words[ $position ];
	return 1;
}

/**
 * Anota tipo ou faceta reconhecidos; segundo tipo diferente vira sobra.
 *
 * @param array $entry   Entrada casada.
 * @param array $profile Perfil em construção, alterado por referência.
 */
function papelito_vendor_code_import_apply_entry( array $entry, array &$profile ): void {
	$profile['synonym'] = $profile['synonym'] || $entry['synonym'];
	if ( 'facet' === $entry['kind'] ) {
		$profile['facets'][ $entry['facet'] ][] = $entry['value'];
		return;
	}
	if ( null === $profile['type'] || $profile['type'] === $entry['value'] ) {
		$profile['type'] = $entry['value'];
		return;
	}
	$profile['residual'][] = $entry['value'];
}

/**
 * Remove palavras de enchimento globais e as do tipo reconhecido.
 *
 * @param string[]    $residual   Palavras que sobraram.
 * @param string|null $type       Tipo reconhecido.
 * @param array       $dictionary Dicionário.
 * @return string[]
 */
function papelito_vendor_code_import_strip_fillers( array $residual, ?string $type, array $dictionary ): array {
	$fillers = array_merge( (array) ( $dictionary['fillers']['*'] ?? array() ), (array) ( $dictionary['fillers'][ (string) $type ] ?? array() ) );
	return array_values( array_diff( $residual, $fillers ) );
}

/**
 * Tipo de produto indicado pelo subgrupo do ERP ("HEADSHOP - TRITURADORES" → dichavador).
 *
 * @param string $subgroup   Subgrupo da planilha.
 * @param array  $dictionary Dicionário.
 */
function papelito_vendor_code_import_subgroup_type( string $subgroup, array $dictionary ): ?string {
	if ( '' === trim( $subgroup ) ) {
		return null;
	}
	$text = papelito_vendor_code_import_normalize( $subgroup );
	foreach ( (array) ( $dictionary['subgroup_prefixes'] ?? array() ) as $prefix ) {
		$text = (string) preg_replace( '/^' . preg_quote( (string) $prefix, '/' ) . '\b\s*-?\s*/', '', $text );
	}
	return papelito_vendor_code_import_features( $text, $dictionary )['type'];
}

/**
 * Compara a mesma faceta dos dois lados.
 *
 * @param string   $facet Faceta.
 * @param string[] $row   Valores da planilha.
 * @param string[] $item  Valores do catálogo.
 * @return string `ok`, `review` ou `incompatible`.
 */
function papelito_vendor_code_import_compare_facet( string $facet, array $row, array $item ): string {
	if ( $row === $item ) {
		return 'ok';
	}
	if ( 'combo' === $facet ) {
		return 'incompatible';
	}
	if ( ! $row || ! $item ) {
		return 'review';
	}
	return array_intersect( $row, $item ) ? 'review' : 'incompatible';
}

/**
 * Compara quantidade, dimensões e gramatura.
 *
 * Quantidade e dimensão diferentes dos dois lados são contradição. Número que
 * só a planilha tem não confirma nada: revisão.
 *
 * @param array $row  Perfil da linha.
 * @param array $item Perfil do produto.
 * @return string `ok`, `review` ou `incompatible`.
 */
function papelito_vendor_code_import_compare_numbers( array $row, array $item ): string {
	if ( null !== $row['qty'] && null !== $item['qty'] && $row['qty'] !== $item['qty'] ) {
		return 'incompatible';
	}
	if ( $row['dim'] && $item['dim'] && ! array_intersect( $row['dim'], $item['dim'] ) ) {
		return 'incompatible';
	}
	$unverified = ( null !== $row['qty'] && null === $item['qty'] ) || ( $row['dim'] && ! $item['dim'] ) || $row['weight'];
	return $unverified ? 'review' : 'ok';
}

/**
 * Separa da linha a cor de uma variação da família e devolve as variações dessa cor.
 *
 * Sem cor na linha, a família inteira é alvo (decisão de produto: o ERP não
 * diferencia cor). Família simples não tem cor e devolve seus alvos intactos.
 *
 * @param array $row    Perfil da linha.
 * @param array $family Família do catálogo.
 * @return array{row:array,targets:array}
 */
function papelito_vendor_code_import_resolve_color( array $row, array $family ): array {
	$colors = array();
	foreach ( $family['targets'] as $target ) {
		if ( '' !== (string) ( $target['color'] ?? '' ) ) {
			$colors[ $target['color'] ] = explode( ' ', $target['color'] );
		}
	}
	uasort( $colors, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );
	foreach ( $colors as $color => $words ) {
		if ( ! array_diff( $words, $row['residual'] ) ) {
			$row['residual'] = array_values( array_diff( $row['residual'], $words ) );
			$targets         = array_values( array_filter( $family['targets'], static fn( array $target ): bool => $target['color'] === $color ) );
			return array(
				'row'     => $row,
				'targets' => $targets,
			);
		}
	}
	return array(
		'row'     => $row,
		'targets' => $family['targets'],
	);
}

/**
 * Classifica o par linha × família.
 *
 * Além do veredito, devolve `gaps` (quantas verificações ficaram sem confirmação)
 * e `shared` (quantos atributos os dois lados têm em comum), usados só para
 * filtrar sugestões de revisão sem nenhuma relação com a linha.
 *
 * @param array $row    Perfil da linha, com `type` já resolvido pelo subgrupo.
 * @param array $family Família com `features` e `targets`.
 * @return array{outcome:string,targets:array,gaps:int,shared:int}
 */
function papelito_vendor_code_import_compare( array $row, array $family ): array {
	$item = $family['features'];
	if ( null !== $row['type'] && $row['type'] !== $item['type'] ) {
		return array(
			'outcome' => 'incompatible',
			'targets' => array(),
			'gaps'    => 0,
			'shared'  => 0,
		);
	}
	$resolved = papelito_vendor_code_import_resolve_color( $row, $family );
	$row      = $resolved['row'];
	$verdicts = array( null === $row['type'] ? 'review' : 'ok', papelito_vendor_code_import_compare_numbers( $row, $item ) );
	$shared   = $row['residual'] && $row['residual'] === $item['residual'] ? 1 : 0;
	foreach ( array_unique( array_merge( array_keys( $row['facets'] ), array_keys( $item['facets'] ) ) ) as $facet ) {
		$row_values  = $row['facets'][ $facet ] ?? array();
		$item_values = $item['facets'][ $facet ] ?? array();
		$verdicts[]  = papelito_vendor_code_import_compare_facet( (string) $facet, $row_values, $item_values );
		$shared     += count( array_intersect( $row_values, $item_values ) );
	}
	$verdicts[] = $row['residual'] === $item['residual'] ? 'ok' : 'review';

	$outcome = 'review';
	if ( in_array( 'incompatible', $verdicts, true ) ) {
		$outcome = 'incompatible';
	} elseif ( ! in_array( 'review', $verdicts, true ) ) {
		$outcome = $row['synonym'] ? 'synonym' : 'exact';
	}
	return array(
		'outcome' => $outcome,
		'targets' => $resolved['targets'],
		'gaps'    => count( array_keys( $verdicts, 'review', true ) ),
		'shared'  => $shared,
	);
}

/**
 * Família com os alvos resolvidos, no formato devolvido ao painel.
 *
 * @param array $family  Família do catálogo.
 * @param array $targets Variações ou o próprio produto.
 * @return array{family_id:int,name:string,targets:array}
 */
function papelito_vendor_code_import_family_view( array $family, array $targets ): array {
	return array(
		'family_id' => (int) $family['family_id'],
		'name'      => (string) $family['name'],
		'targets'   => array_values( $targets ),
	);
}

/**
 * Nota usada só para ordenar sugestões de revisão; nunca decide associação.
 *
 * @param array $row    Perfil da linha.
 * @param array $family Família do catálogo.
 */
function papelito_vendor_code_import_rank( array $row, array $family ): float {
	similar_text( $row['normalized'], $family['features']['normalized'], $percent );
	return (float) $percent;
}

/**
 * Associa uma linha da planilha ao catálogo.
 *
 * Exatamente um candidato forte (exato ou sinônimo) vira associação automática.
 * Dois fortes, ou só candidatos parciais, vão para revisão com até cinco
 * sugestões — nunca há escolha arbitrária. Candidato parcial precisa dividir ao
 * menos um atributo com a linha e estar entre os de menor lacuna. Tipo da descrição contradizendo o
 * subgrupo é conflito.
 *
 * @param array{description:string,subgroup:string} $row      Linha da planilha.
 * @param array                                     $families Famílias do catálogo, com `features`.
 * @param array                                     $dictionary Dicionário.
 * @return array{match:string,reason:string,family:?array,candidates:array}
 */
function papelito_vendor_code_import_match_row( array $row, array $families, array $dictionary ): array {
	$profile       = papelito_vendor_code_import_features( (string) $row['description'], $dictionary );
	$subgroup_type = papelito_vendor_code_import_subgroup_type( (string) ( $row['subgroup'] ?? '' ), $dictionary );
	if ( null !== $profile['type'] && null !== $subgroup_type && $profile['type'] !== $subgroup_type ) {
		return papelito_vendor_code_import_match_result( 'conflict', 'A descrição e o subgrupo indicam tipos de produto diferentes.' );
	}
	$profile['type'] = $profile['type'] ?? $subgroup_type;

	$strong  = array();
	$partial = array();
	foreach ( $families as $family ) {
		$compared = papelito_vendor_code_import_compare( $profile, $family );
		if ( 'incompatible' === $compared['outcome'] || ! $compared['targets'] ) {
			continue;
		}
		$entry = array(
			'outcome' => $compared['outcome'],
			'gaps'    => $compared['gaps'],
			'rank'    => papelito_vendor_code_import_rank( $profile, $family ),
			'view'    => papelito_vendor_code_import_family_view( $family, $compared['targets'] ),
		);
		if ( 'review' !== $compared['outcome'] ) {
			$strong[] = $entry;
		} elseif ( $compared['shared'] > 0 ) {
			$partial[] = $entry;
		}
	}
	return papelito_vendor_code_import_classify( $strong, $partial );
}

/**
 * Decide o status a partir dos candidatos fortes e parciais.
 *
 * @param array $strong  Candidatos exatos ou por sinônimo.
 * @param array $partial Candidatos que exigem revisão.
 * @return array{match:string,reason:string,family:?array,candidates:array}
 */
function papelito_vendor_code_import_classify( array $strong, array $partial ): array {
	if ( 1 === count( $strong ) ) {
		$result           = papelito_vendor_code_import_match_result( $strong[0]['outcome'], '' );
		$result['family'] = $strong[0]['view'];
		return $result;
	}
	if ( count( $strong ) > 1 ) {
		$result               = papelito_vendor_code_import_match_result( 'review', 'Mais de um produto corresponde a esta descrição.' );
		$result['candidates'] = papelito_vendor_code_import_top_candidates( $strong );
		return $result;
	}
	if ( $partial ) {
		$result               = papelito_vendor_code_import_match_result( 'review', 'A descrição não bate integralmente com nenhum produto; confira a sugestão.' );
		$result['candidates'] = papelito_vendor_code_import_top_candidates( papelito_vendor_code_import_closest( $partial ) );
		return $result;
	}
	return papelito_vendor_code_import_match_result( 'not_found', 'Nenhum produto publicado é compatível com esta descrição.' );
}

/**
 * Candidatos parciais com a menor lacuna; quem precisa de mais suposições sai da lista.
 *
 * @param array $entries Candidatos com `gaps`.
 * @return array
 */
function papelito_vendor_code_import_closest( array $entries ): array {
	$min = min( array_column( $entries, 'gaps' ) );
	return array_values( array_filter( $entries, static fn( array $entry ): bool => $entry['gaps'] === $min ) );
}

/**
 * Até cinco candidatos, do mais parecido para o menos.
 *
 * @param array $entries Candidatos com `rank` e `view`.
 * @return array<int,array>
 */
function papelito_vendor_code_import_top_candidates( array $entries ): array {
	usort( $entries, static fn( array $a, array $b ): int => $b['rank'] <=> $a['rank'] );
	return array_column( array_slice( $entries, 0, 5 ), 'view' );
}

/**
 * Resultado vazio de associação.
 *
 * @param string $status Status da associação.
 * @param string $reason Motivo exibível.
 * @return array{match:string,reason:string,family:?array,candidates:array}
 */
function papelito_vendor_code_import_match_result( string $status, string $reason ): array {
	return array(
		'match'      => $status,
		'reason'     => $reason,
		'family'     => null,
		'candidates' => array(),
	);
}
