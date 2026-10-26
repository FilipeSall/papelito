<?php
/**
 * Regras da importação de produtos do admin: colunas aceitas, validação por linha e situação.
 *
 * Diferente da importação do vendor, que associa uma linha a um produto que já existe, esta
 * cadastra produto novo no catálogo da Papelito. Por isso a chave de "já existe" é
 * determinística — nome normalizado (sem acento, caixa e pontuação) dentro da mesma
 * categoria — e não há casamento aproximado. A normalização lê grafias e sinônimos do mesmo
 * dicionário da importação do vendor (`papelito_vendor_code_import_dictionary()`): "triturador"
 * é dichavador, "ks" é king size. Leitura por sinônimo vira nota na linha, nunca erro.
 *
 * Tudo aqui é puro: recebe a planilha já lida e um contexto com categorias, coleções e
 * catálogo carregado do banco (`papelito_product_import_context()`), e devolve a análise.
 * Não grava nada; quem cria produto é `admin_product_import.php`.
 *
 * Situações de uma linha:
 * - `erro`: falta ou está inválido algo que a vitrine exige; não entra.
 * - `repetida`: igual a uma linha anterior do mesmo arquivo; só a primeira entra.
 * - `duplicado`: já existe no catálogo com o mesmo nome e categoria; só entra se o admin pedir.
 * - `incompleto`: entra, mas sem peso ou medidas o frete não cota até alguém preencher.
 * - `novo`: entra.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_PRODUCT_IMPORT_REQUIRED      = array( 'nome', 'categoria', 'preco_regular' );
const PAPELITO_PRODUCT_IMPORT_SHIPPING      = array( 'peso_kg', 'comprimento_cm', 'largura_cm', 'altura_cm' );
const PAPELITO_PRODUCT_IMPORT_LIST_SEP      = '|';
const PAPELITO_PRODUCT_IMPORT_NAME_MAX      = 200;
const PAPELITO_PRODUCT_IMPORT_TEXT_MAX      = 5000;
const PAPELITO_PRODUCT_IMPORT_STATUS_ERROR  = 'erro';
const PAPELITO_PRODUCT_IMPORT_STATUS_REPEAT = 'repetida';
const PAPELITO_PRODUCT_IMPORT_STATUS_DUPE   = 'duplicado';
const PAPELITO_PRODUCT_IMPORT_STATUS_PARTLY = 'incompleto';
const PAPELITO_PRODUCT_IMPORT_STATUS_NEW    = 'novo';

/**
 * Colunas do modelo de importação, com o rótulo exibível e os cabeçalhos aceitos já normalizados.
 *
 * A ordem é a do modelo CSV que o painel oferece para download.
 *
 * @return array<string,array{label:string,aliases:string[]}>
 */
function papelito_product_import_columns(): array {
	return array(
		'nome'              => array(
			'label'   => 'Nome',
			'aliases' => array( 'nome', 'produto', 'nome do produto', 'titulo' ),
		),
		'categoria'         => array(
			'label'   => 'Categoria',
			'aliases' => array( 'categoria', 'categoria principal' ),
		),
		'subcategorias'     => array(
			'label'   => 'Subcategorias',
			'aliases' => array( 'subcategorias', 'subcategoria' ),
		),
		'preco_regular'     => array(
			'label'   => 'Preço regular',
			'aliases' => array( 'preco regular', 'preco' ),
		),
		'preco_promocional' => array(
			'label'   => 'Preço promocional',
			'aliases' => array( 'preco promocional', 'preco promo' ),
		),
		'peso_kg'           => array(
			'label'   => 'Peso (kg)',
			'aliases' => array( 'peso kg', 'peso' ),
		),
		'comprimento_cm'    => array(
			'label'   => 'Comprimento (cm)',
			'aliases' => array( 'comprimento cm', 'comprimento' ),
		),
		'largura_cm'        => array(
			'label'   => 'Largura (cm)',
			'aliases' => array( 'largura cm', 'largura' ),
		),
		'altura_cm'         => array(
			'label'   => 'Altura (cm)',
			'aliases' => array( 'altura cm', 'altura' ),
		),
		'descricao_curta'   => array(
			'label'   => 'Descrição curta',
			'aliases' => array( 'descricao curta', 'resumo' ),
		),
		'descricao'         => array(
			'label'   => 'Descrição longa',
			'aliases' => array( 'descricao', 'descricao longa' ),
		),
		'colecoes'          => array(
			'label'   => 'Coleções',
			'aliases' => array( 'colecoes', 'colecao' ),
		),
		'tags'              => array(
			'label'   => 'Tags',
			'aliases' => array( 'tags', 'tag' ),
		),
	);
}

/**
 * Texto comparável: sem acento, minúsculo, pontuação vira espaço. Vale para cabeçalho e nome.
 *
 * @param mixed $value Valor bruto.
 */
function papelito_product_import_normalize( mixed $value ): string {
	return papelito_vendor_code_import_header_key( $value );
}

/**
 * Grafias e sinônimos do dicionário do vendor, cada um apontando para o nome canônico, mais a
 * lista dos tipos de produto (dichavador, tubelito…). A expressão já vem montada, da grafia mais
 * longa para a mais curta, para "ultra longo" não ser lido como "ultra" + "longa".
 *
 * @param array<string,mixed> $dictionary Saída de `papelito_vendor_code_import_dictionary()`.
 * @return array{map:array<string,string>,types:string[],pattern:string}
 */
function papelito_product_import_terms( array $dictionary ): array {
	$map   = array();
	$types = array();
	foreach ( (array) ( $dictionary['types'] ?? array() ) as $key => $term ) {
		$types[] = papelito_product_import_normalize( $key );
		$map     = array_merge( $map, papelito_product_import_term_forms( (string) $key, (array) $term ) );
	}
	foreach ( (array) ( $dictionary['facets'] ?? array() ) as $values ) {
		foreach ( (array) $values as $key => $term ) {
			$map = array_merge( $map, papelito_product_import_term_forms( (string) $key, (array) $term ) );
		}
	}
	uksort( $map, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
	$quoted = array_map( static fn ( string $form ): string => preg_quote( $form, '/' ), array_keys( $map ) );

	return array(
		'map'     => $map,
		'types'   => $types,
		'pattern' => $quoted ? '/\b(' . implode( '|', $quoted ) . ')\b/' : '',
	);
}

/**
 * Grafias e sinônimos de um termo, normalizados, apontando para o nome canônico dele.
 *
 * @param string              $key  Chave canônica do termo (`king-size`).
 * @param array<string,mixed> $term `forms` e `synonyms` do dicionário.
 * @return array<string,string>
 */
function papelito_product_import_term_forms( string $key, array $term ): array {
	$canonical = papelito_product_import_normalize( $key );
	$forms     = array( $canonical => $canonical );
	foreach ( array_merge( (array) ( $term['forms'] ?? array() ), (array) ( $term['synonyms'] ?? array() ) ) as $form ) {
		$forms[ papelito_product_import_normalize( $form ) ] = $canonical;
	}
	return $forms;
}

/**
 * Texto normalizado com cada grafia e sinônimo trocado pelo nome canônico: "Triturador KS"
 * vira "dichavador king size". Sem dicionário, é só a normalização.
 *
 * @param mixed               $value Texto bruto.
 * @param array<string,mixed> $terms Saída de `papelito_product_import_terms()`.
 */
function papelito_product_import_canonical( mixed $value, array $terms ): string {
	$normalized = papelito_product_import_normalize( $value );
	if ( '' === $normalized || '' === (string) ( $terms['pattern'] ?? '' ) ) {
		return $normalized;
	}
	return (string) preg_replace_callback(
		(string) $terms['pattern'],
		static fn ( array $found ): string => (string) $terms['map'][ $found[1] ],
		$normalized
	);
}

/**
 * Chaves pelas quais uma categoria ou subcategoria é encontrada: slug, nome, singular (quando o
 * slug está no plural) e a forma canônica de cada um.
 *
 * @param string[]            $labels Slug e nome.
 * @param array<string,mixed> $terms  Saída de `papelito_product_import_terms()`.
 * @return string[]
 */
function papelito_product_import_index_keys( array $labels, array $terms ): array {
	$keys = array();
	foreach ( $labels as $label ) {
		$normalized = papelito_product_import_normalize( $label );
		$keys[]     = $normalized;
		$keys[]     = papelito_product_import_canonical( $label, $terms );
		if ( str_ends_with( $normalized, 's' ) && ! str_contains( $normalized, ' ' ) ) {
			$keys[] = substr( $normalized, 0, -1 );
		}
	}
	return array_values( array_unique( array_filter( $keys, 'strlen' ) ) );
}

/**
 * Mapa coluna → índice reconhecido numa linha; vazio quando a linha não parece cabeçalho.
 *
 * @param array<int,mixed> $cells Células da linha.
 * @return array<string,int>
 */
function papelito_product_import_header_map( array $cells ): array {
	$map = array();
	foreach ( array_values( $cells ) as $index => $cell ) {
		$key = papelito_product_import_normalize( $cell );
		foreach ( papelito_product_import_columns() as $column => $definition ) {
			if ( ! isset( $map[ $column ] ) && in_array( $key, $definition['aliases'], true ) ) {
				$map[ $column ] = $index;
				break;
			}
		}
	}
	return $map;
}

/**
 * Diz se o mapa tem as três colunas sem as quais a linha não vira produto.
 *
 * @param array<string,int> $map Mapa do cabeçalho.
 */
function papelito_product_import_map_is_complete( array $map ): bool {
	return array() === array_diff( PAPELITO_PRODUCT_IMPORT_REQUIRED, array_keys( $map ) );
}

/**
 * Delimitador do CSV que revela o cabeçalho do modelo de produtos; cai no mais frequente.
 *
 * @param string $content CSV já em UTF-8.
 */
function papelito_product_import_csv_delimiter( string $content ): string {
	$candidates = papelito_vendor_code_import_csv_head_lines( $content );
	foreach ( array( ';', ',', "\t" ) as $delimiter ) {
		foreach ( $candidates as $line ) {
			if ( papelito_product_import_map_is_complete( papelito_product_import_header_map( str_getcsv( $line, $delimiter, '"', '' ) ) ) ) {
				return $delimiter;
			}
		}
	}
	$first  = (string) ( $candidates[0] ?? '' );
	$counts = array(
		';'  => substr_count( $first, ';' ),
		','  => substr_count( $first, ',' ),
		"\t" => substr_count( $first, "\t" ),
	);
	arsort( $counts );
	return (string) array_key_first( $counts );
}

/**
 * Célula como texto limpo, com o teto do campo; número inteiro sai sem casa decimal.
 *
 * @param mixed  $value Valor lido do arquivo.
 * @param string $field Coluna de destino; a descrição longa preserva quebra de linha.
 */
function papelito_product_import_cell( mixed $value, string $field ): string {
	if ( is_float( $value ) && floor( $value ) === $value ) {
		$value = (string) (int) $value;
	}
	if ( ! is_scalar( $value ) || ! mb_check_encoding( (string) $value, 'UTF-8' ) ) {
		return '';
	}
	if ( 'descricao' === $field || 'descricao_curta' === $field ) {
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, PAPELITO_PRODUCT_IMPORT_TEXT_MAX, 'UTF-8' );
	}
	return mb_substr( sanitize_text_field( (string) $value ), 0, PAPELITO_PRODUCT_IMPORT_NAME_MAX, 'UTF-8' );
}

/**
 * Cabeçalho do arquivo com a coluna do modelo para onde cada um vai (null quando é ignorado).
 *
 * @param array<int,mixed>  $cells Linha do cabeçalho.
 * @param array<string,int> $map   Mapa reconhecido.
 * @return array<int,array{header:string,field:?string}>
 */
function papelito_product_import_describe_columns( array $cells, array $map ): array {
	$by_index = array_flip( $map );
	$columns  = array();
	foreach ( array_values( $cells ) as $index => $cell ) {
		$columns[] = array(
			'header' => papelito_vendor_code_import_cell_text( $cell ),
			'field'  => $by_index[ $index ] ?? null,
		);
	}
	return $columns;
}

/**
 * Recusa de planilha sem as colunas obrigatórias, levando o cabeçalho lido para o painel mostrar.
 *
 * @param array<int,array<int,mixed>> $matrix Linhas da planilha.
 */
function papelito_product_import_missing_columns_error( array $matrix ): WP_Error {
	$header = array();
	foreach ( array_slice( $matrix, 0, PAPELITO_VENDOR_CODE_IMPORT_HEADER_SCAN ) as $cells ) {
		if ( ! papelito_vendor_code_import_csv_row_is_blank( (array) $cells ) ) {
			$header = (array) $cells;
			break;
		}
	}
	$map     = papelito_product_import_header_map( $header );
	$missing = array_values( array_diff( PAPELITO_PRODUCT_IMPORT_REQUIRED, array_keys( $map ) ) );

	return new WP_Error(
		'papelito_product_import_missing_columns',
		'A planilha precisa das colunas nome, categoria e preco_regular. Use o modelo CSV ou renomeie os cabeçalhos.',
		array(
			'status'  => 422,
			'stage'   => 'read',
			'missing' => $missing,
			'columns' => papelito_product_import_describe_columns( $header, $map ),
		)
	);
}

/**
 * Procura o cabeçalho nas primeiras linhas e converte o resto em linhas da importação.
 *
 * @param array<int,array<int,mixed>> $matrix Linhas da planilha, na ordem do arquivo.
 * @return array{headers:string[],columns:array<int,array{header:string,field:?string}>,rows:array<int,array{line:int,cells:string[],data:array<string,string>}>}|WP_Error
 */
function papelito_product_import_table_from_matrix( array $matrix ): array|WP_Error {
	$matrix = array_values( $matrix );
	foreach ( array_slice( $matrix, 0, PAPELITO_VENDOR_CODE_IMPORT_HEADER_SCAN ) as $offset => $cells ) {
		$map = papelito_product_import_header_map( (array) $cells );
		if ( papelito_product_import_map_is_complete( $map ) ) {
			return papelito_product_import_collect_table( (array) $cells, $map, array_slice( $matrix, $offset + 1 ), $offset + 2 );
		}
	}
	return papelito_product_import_missing_columns_error( $matrix );
}

/**
 * Converte as linhas de dados, ignorando as totalmente vazias e mantendo a numeração do arquivo.
 *
 * @param array<int,mixed>            $header     Linha do cabeçalho.
 * @param array<string,int>           $map        Índices das colunas reconhecidas.
 * @param array<int,array<int,mixed>> $data       Linhas depois do cabeçalho.
 * @param int                         $first_line Número da primeira linha de dados no arquivo.
 * @return array<string,mixed>|WP_Error
 */
function papelito_product_import_collect_table( array $header, array $map, array $data, int $first_line ): array|WP_Error {
	$rows = array();
	foreach ( $data as $offset => $cells ) {
		$cells = array_values( (array) $cells );
		if ( papelito_vendor_code_import_csv_row_is_blank( $cells ) ) {
			continue;
		}
		if ( count( $rows ) >= PAPELITO_VENDOR_CODE_IMPORT_MAX_ROWS ) {
			return papelito_vendor_code_import_too_many_rows_error();
		}
		$row = array();
		foreach ( $map as $field => $index ) {
			$row[ $field ] = papelito_product_import_cell( $cells[ $index ] ?? '', $field );
		}
		$rows[] = array(
			'line'  => $first_line + $offset,
			'cells' => array_map( 'papelito_vendor_code_import_cell_text', $cells ),
			'data'  => $row,
		);
	}
	if ( ! $rows ) {
		return papelito_vendor_code_import_read_error( 'papelito_product_import_empty', 'A planilha não tem nenhuma linha de produto abaixo do cabeçalho.' );
	}
	return array(
		'headers' => array_map( 'papelito_vendor_code_import_cell_text', array_values( $header ) ),
		'columns' => papelito_product_import_describe_columns( $header, $map ),
		'rows'    => $rows,
	);
}

/**
 * Número decimal escrito no padrão brasileiro ou no internacional (`84,90`, `1.234,56`, `R$ 52`, `0.4`).
 *
 * @param string $raw Texto da célula.
 * @return float|false|null Null quando vazio; false quando não é número.
 */
function papelito_product_import_decimal( string $raw ): float|false|null {
	$text = trim( (string) preg_replace( '/^r\$\s*/i', '', trim( $raw ) ) );
	if ( '' === $text ) {
		return null;
	}
	if ( str_contains( $text, ',' ) ) {
		$text = str_replace( array( '.', ',' ), array( '', '.' ), $text );
	}
	return is_numeric( $text ) ? (float) $text : false;
}

/**
 * Itens de uma célula de lista (`brown|slim`), sem vazios.
 *
 * @param string $raw Texto da célula.
 * @return string[]
 */
function papelito_product_import_list( string $raw ): array {
	return array_values( array_filter( array_map( 'trim', explode( PAPELITO_PRODUCT_IMPORT_LIST_SEP, $raw ) ), 'strlen' ) );
}

/**
 * Problema encontrado numa linha.
 *
 * @param string $field   Coluna do modelo.
 * @param string $message Texto exibível, já com a correção quando houver.
 * @param string $level   `error` barra a linha; `warn` deixa entrar com ressalva.
 * @return array{field:string,level:string,message:string}
 */
function papelito_product_import_issue( string $field, string $message, string $level = 'error' ): array {
	return array(
		'field'   => $field,
		'level'   => $level,
		'message' => $message,
	);
}

/**
 * Categoria de destino pelo slug, nome, singular ou sinônimo. Quando o texto é um tipo de
 * produto que só existe como subcategoria de uma categoria ("triturador"), a linha vai para
 * essa categoria e ganha a subcategoria. Sem nada disso, erro com sugestão.
 *
 * @param string              $raw     Texto da célula.
 * @param array<string,mixed> $context Contexto da importação.
 * @return array{category:?array,issue:?array,impliedSub:?array}
 */
function papelito_product_import_resolve_category( string $raw, array $context ): array {
	$terms = (array) ( $context['terms'] ?? array() );
	if ( '' === $raw ) {
		return array(
			'category'   => null,
			'issue'      => papelito_product_import_issue( 'categoria', 'Sem categoria. Produto sem categoria não aparece na vitrine.' ),
			'impliedSub' => null,
		);
	}
	$key       = papelito_product_import_normalize( $raw );
	$canonical = papelito_product_import_canonical( $raw, $terms );
	$direct    = $context['categories'][ $key ] ?? null;
	$category  = $direct ?? $context['categories'][ $canonical ] ?? null;
	if ( null !== $category ) {
		$note = null !== $direct ? null : papelito_product_import_issue( 'categoria', sprintf( 'Categoria “%s” lida como %s.', $raw, $category['name'] ), 'info' );
		return array(
			'category'   => $category,
			'issue'      => $note,
			'impliedSub' => null,
		);
	}
	return papelito_product_import_category_from_type( $raw, $canonical, $context );
}

/**
 * Categoria deduzida de um tipo de produto que mora numa única categoria, com a subcategoria dele.
 *
 * @param string              $raw       Texto da célula.
 * @param string              $canonical Texto na forma canônica.
 * @param array<string,mixed> $context   Contexto da importação.
 * @return array{category:?array,issue:?array,impliedSub:?array}
 */
function papelito_product_import_category_from_type( string $raw, string $canonical, array $context ): array {
	$owners = array();
	if ( in_array( $canonical, (array) ( $context['terms']['types'] ?? array() ), true ) ) {
		foreach ( $context['categories'] as $category ) {
			if ( isset( $category['subs'][ $canonical ] ) ) {
				$owners[ (int) $category['id'] ] = $category;
			}
		}
	}
	if ( 1 === count( $owners ) ) {
		$category = reset( $owners );
		$sub      = $category['subs'][ $canonical ];
		return array(
			'category'   => $category,
			'issue'      => papelito_product_import_issue( 'categoria', sprintf( 'Categoria “%s” lida como %s, subcategoria %s.', $raw, $category['name'], $sub['name'] ), 'info' ),
			'impliedSub' => $sub,
		);
	}
	$suggestion = papelito_product_import_suggest_category( papelito_product_import_normalize( $raw ), $context );
	$message    = null === $suggestion
		? sprintf( 'Categoria “%s” não existe ou está inativa.', $raw )
		: sprintf( 'Categoria “%s” não existe — você quis dizer “%s”?', $raw, $suggestion );

	return array(
		'category'   => null,
		'issue'      => papelito_product_import_issue( 'categoria', $message ),
		'impliedSub' => null,
	);
}

/**
 * Slug de categoria ativa que começa com o texto digitado (ou o contrário), para corrigir plural e erro de digitação curto.
 *
 * @param string              $key     Texto normalizado.
 * @param array<string,mixed> $context Contexto da importação.
 */
function papelito_product_import_suggest_category( string $key, array $context ): ?string {
	if ( strlen( $key ) < 3 ) {
		return null;
	}
	foreach ( $context['categories'] as $category ) {
		$slug = (string) $category['slug'];
		if ( str_starts_with( $slug, $key ) || str_starts_with( $key, substr( $slug, 0, -1 ) ) ) {
			return $slug;
		}
	}
	return null;
}

/**
 * Subcategorias da célula, todas da mesma categoria e ativas.
 *
 * @param string[]                 $names    Itens da célula, mais a subcategoria deduzida da categoria.
 * @param array<string,mixed>|null $category Categoria resolvida.
 * @param array<string,mixed>      $terms    Saída de `papelito_product_import_terms()`.
 * @return array{ids:int[],names:string[],issues:array<int,array<string,string>>}
 */
function papelito_product_import_resolve_subcategories( array $names, ?array $category, array $terms = array() ): array {
	$result = array(
		'ids'    => array(),
		'names'  => array(),
		'issues' => array(),
	);
	if ( null === $category ) {
		return $result;
	}
	foreach ( $names as $name ) {
		$direct = $category['subs'][ papelito_product_import_normalize( $name ) ] ?? null;
		$sub    = $direct ?? $category['subs'][ papelito_product_import_canonical( $name, $terms ) ] ?? null;
		if ( null === $sub ) {
			$result['issues'][] = papelito_product_import_issue( 'subcategorias', sprintf( 'Subcategoria “%s” não existe em %s ou está inativa.', $name, $category['name'] ) );
			continue;
		}
		if ( null === $direct ) {
			$result['issues'][] = papelito_product_import_issue( 'subcategorias', sprintf( 'Subcategoria “%s” lida como %s.', $name, $sub['name'] ), 'info' );
		}
		$result['ids'][ $sub['id'] ]   = (int) $sub['id'];
		$result['names'][ $sub['id'] ] = (string) $sub['name'];
	}
	$result['names'] = array_values( $result['names'] );
	$result['ids']   = array_values( $result['ids'] );
	return $result;
}

/**
 * Preço regular obrigatório e maior que zero; promocional opcional e menor que o regular.
 *
 * @param array<string,string> $data Linha lida.
 * @return array{price:?float,sale:?float,issues:array<int,array<string,string>>}
 */
function papelito_product_import_resolve_prices( array $data ): array {
	$issues = array();
	$price  = papelito_product_import_decimal( (string) ( $data['preco_regular'] ?? '' ) );
	$sale   = papelito_product_import_decimal( (string) ( $data['preco_promocional'] ?? '' ) );

	if ( null === $price ) {
		$issues[] = papelito_product_import_issue( 'preco_regular', 'Sem preço regular.' );
	} elseif ( false === $price || $price <= 0 ) {
		$issues[] = papelito_product_import_issue( 'preco_regular', sprintf( 'Preço regular “%s” precisa ser um número maior que zero.', $data['preco_regular'] ) );
	}
	if ( false === $sale || ( is_float( $sale ) && $sale <= 0 ) ) {
		$issues[] = papelito_product_import_issue( 'preco_promocional', sprintf( 'Preço promocional “%s” precisa ser um número maior que zero.', $data['preco_promocional'] ) );
	} elseif ( is_float( $sale ) && is_float( $price ) && $sale >= $price ) {
		$issues[] = papelito_product_import_issue( 'preco_promocional', 'O preço promocional precisa ser menor que o regular.' );
	}

	return array(
		'price'  => is_float( $price ) && $price > 0 ? $price : null,
		'sale'   => is_float( $sale ) && $sale > 0 ? $sale : null,
		'issues' => $issues,
	);
}

/**
 * Peso e medidas: inválido barra a linha; ausente deixa entrar como incompleto.
 *
 * @param array<string,string> $data Linha lida.
 * @return array{values:array<string,?float>,missing:string[],issues:array<int,array<string,string>>}
 */
function papelito_product_import_resolve_shipping( array $data ): array {
	$columns = papelito_product_import_columns();
	$result  = array(
		'values'  => array(),
		'missing' => array(),
		'issues'  => array(),
	);
	foreach ( PAPELITO_PRODUCT_IMPORT_SHIPPING as $field ) {
		$value                      = papelito_product_import_decimal( (string) ( $data[ $field ] ?? '' ) );
		$result['values'][ $field ] = is_float( $value ) && $value > 0 ? $value : null;
		if ( null === $value ) {
			$result['missing'][] = mb_strtolower( strtok( $columns[ $field ]['label'], ' ' ), 'UTF-8' );
		} elseif ( false === $value || $value <= 0 ) {
			$result['issues'][] = papelito_product_import_issue( $field, sprintf( '%s “%s” precisa ser um número maior que zero.', $columns[ $field ]['label'], $data[ $field ] ) );
		}
	}
	if ( $result['missing'] ) {
		$result['issues'][] = papelito_product_import_issue( 'peso_kg', sprintf( 'Sem %s: entra incompleto e não cota frete até ser preenchido.', implode( ', ', $result['missing'] ) ), 'warn' );
	}
	return $result;
}

/**
 * Coleções manuais da célula; só as que aceitam vínculo novo.
 *
 * @param string[]            $slugs   Itens da célula.
 * @param array<string,mixed> $context Contexto da importação.
 * @return array{slugs:string[],issues:array<int,array<string,string>>}
 */
function papelito_product_import_resolve_collections( array $slugs, array $context ): array {
	$result = array(
		'slugs'  => array(),
		'issues' => array(),
	);
	foreach ( $slugs as $slug ) {
		$key = sanitize_key( $slug );
		if ( in_array( $key, $context['collections'], true ) ) {
			$result['slugs'][ $key ] = $key;
			continue;
		}
		$result['issues'][] = papelito_product_import_issue( 'colecoes', sprintf( 'Coleção “%s” não existe ou está inativa.', $slug ) );
	}
	$result['slugs'] = array_values( $result['slugs'] );
	return $result;
}

/**
 * Valida uma linha e devolve o que será gravado mais a lista de problemas.
 *
 * @param array<string,string> $data    Linha lida.
 * @param array<string,mixed>  $context Contexto da importação.
 * @return array<string,mixed>
 */
function papelito_product_import_validate_row( array $data, array $context ): array {
	$name     = (string) ( $data['nome'] ?? '' );
	$category = papelito_product_import_resolve_category( (string) ( $data['categoria'] ?? '' ), $context );
	$names    = papelito_product_import_list( (string) ( $data['subcategorias'] ?? '' ) );
	$subs     = papelito_product_import_resolve_subcategories( null === $category['impliedSub'] ? $names : array_merge( array( $category['impliedSub']['slug'] ), $names ), $category['category'], (array) ( $context['terms'] ?? array() ) );
	$prices   = papelito_product_import_resolve_prices( $data );
	$shipping = papelito_product_import_resolve_shipping( $data );
	$cols     = papelito_product_import_resolve_collections( papelito_product_import_list( (string) ( $data['colecoes'] ?? '' ) ), $context );
	$issues   = '' === $name ? array( papelito_product_import_issue( 'nome', 'Sem nome do produto.' ) ) : array();

	return array(
		'name'             => $name,
		'category'         => $category['category'],
		'subcategoryIds'   => $subs['ids'],
		'subcategories'    => $subs['names'],
		'collections'      => $cols['slugs'],
		'price'            => $prices['price'],
		'salePrice'        => $prices['sale'],
		'shipping'         => $shipping['values'],
		'incomplete'       => (bool) $shipping['missing'],
		'shortDescription' => (string) ( $data['descricao_curta'] ?? '' ),
		'description'      => (string) ( $data['descricao'] ?? '' ),
		'tags'             => papelito_product_import_list( (string) ( $data['tags'] ?? '' ) ),
		'issues'           => array_values( array_filter( array_merge( $issues, array( $category['issue'] ), $subs['issues'], $prices['issues'], $shipping['issues'], $cols['issues'] ) ) ),
	);
}

/**
 * Diz se a lista de problemas barra a linha.
 *
 * @param array<int,array<string,string>> $issues Problemas da linha.
 */
function papelito_product_import_has_error( array $issues ): bool {
	return in_array( 'error', array_column( $issues, 'level' ), true );
}

/**
 * Situação de uma linha válida diante do catálogo e das linhas anteriores do arquivo.
 *
 * @param array<string,mixed> $row     Linha validada.
 * @param array<string,mixed> $context Contexto da importação.
 * @param array<string,int>   $seen    Chave nome+categoria → primeira linha que a usou.
 * @return array{status:string,repeatOf:?int,duplicateOf:?array}
 */
function papelito_product_import_classify( array $row, array $context, array $seen ): array {
	$name = papelito_product_import_canonical( $row['name'], (array) ( $context['terms'] ?? array() ) );
	$key  = $name . '|' . (int) $row['category']['id'];
	if ( isset( $seen[ $key ] ) ) {
		return array(
			'status'      => PAPELITO_PRODUCT_IMPORT_STATUS_REPEAT,
			'repeatOf'    => $seen[ $key ],
			'duplicateOf' => null,
		);
	}
	$existing = $context['catalog'][ (int) $row['category']['id'] ][ $name ] ?? null;
	$status   = $row['incomplete'] ? PAPELITO_PRODUCT_IMPORT_STATUS_PARTLY : PAPELITO_PRODUCT_IMPORT_STATUS_NEW;

	return array(
		'status'      => null === $existing ? $status : PAPELITO_PRODUCT_IMPORT_STATUS_DUPE,
		'repeatOf'    => null,
		'duplicateOf' => $existing,
	);
}

/**
 * Nota para o duplicado que só bate quando grafias e sinônimos são lidos iguais
 * ("Triturador Neon" e "Dichavador Neon"), para o admin entender por que a linha parou.
 *
 * @param array<string,mixed> $row  Linha validada.
 * @param array<string,mixed> $kind Saída de `papelito_product_import_classify()`.
 * @return array<int,array<string,string>>
 */
function papelito_product_import_duplicate_note( array $row, array $kind ): array {
	$existing = $kind['duplicateOf'];
	if ( null === $existing || papelito_product_import_normalize( $row['name'] ) === papelito_product_import_normalize( $existing['name'] ) ) {
		return array();
	}
	return array( papelito_product_import_issue( 'nome', sprintf( 'Mesmo produto que “%s” quando grafias e sinônimos são lidos iguais.', $existing['name'] ), 'info' ) );
}

/**
 * Analisa a planilha inteira: valida, classifica e resume, sem gravar.
 *
 * @param array<string,mixed> $table   Saída de `papelito_product_import_table_from_matrix()`.
 * @param array<string,mixed> $context Contexto da importação.
 * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int>}
 */
function papelito_product_import_analyze( array $table, array $context ): array {
	$rows = array();
	$seen = array();
	foreach ( $table['rows'] as $raw ) {
		$row  = array_merge( array( 'line' => (int) $raw['line'] ), papelito_product_import_validate_row( $raw['data'], $context ) );
		$kind = array(
			'status'      => PAPELITO_PRODUCT_IMPORT_STATUS_ERROR,
			'repeatOf'    => null,
			'duplicateOf' => null,
		);
		if ( ! papelito_product_import_has_error( $row['issues'] ) ) {
			$kind          = papelito_product_import_classify( $row, $context, $seen );
			$row['issues'] = array_merge( $row['issues'], papelito_product_import_duplicate_note( $row, $kind ) );
			$seen[ papelito_product_import_canonical( $row['name'], (array) ( $context['terms'] ?? array() ) ) . '|' . (int) $row['category']['id'] ] ??= $row['line'];
		}
		$rows[] = array_merge( $row, $kind );
	}
	return array(
		'rows'    => $rows,
		'summary' => papelito_product_import_summary( $rows ),
	);
}

/**
 * Contagem por situação, mais o total de linhas.
 *
 * @param array<int,array<string,mixed>> $rows Linhas analisadas.
 * @return array<string,int>
 */
function papelito_product_import_summary( array $rows ): array {
	$summary = array_fill_keys(
		array( PAPELITO_PRODUCT_IMPORT_STATUS_NEW, PAPELITO_PRODUCT_IMPORT_STATUS_PARTLY, PAPELITO_PRODUCT_IMPORT_STATUS_DUPE, PAPELITO_PRODUCT_IMPORT_STATUS_REPEAT, PAPELITO_PRODUCT_IMPORT_STATUS_ERROR ),
		0
	);
	foreach ( $rows as $row ) {
		++$summary[ $row['status'] ];
	}
	$summary['total'] = count( $rows );
	return $summary;
}

/**
 * Formato público de uma linha analisada para a prévia do painel.
 *
 * @param array<string,mixed> $row   Linha analisada.
 * @param string[]            $cells Células cruas da linha, na ordem do arquivo.
 * @return array<string,mixed>
 */
function papelito_product_import_public_row( array $row, array $cells ): array {
	return array(
		'line'          => $row['line'],
		'cells'         => $cells,
		'name'          => $row['name'],
		'category'      => null === $row['category'] ? null : array(
			'slug' => $row['category']['slug'],
			'name' => $row['category']['name'],
		),
		'subcategories' => $row['subcategories'],
		'price'         => $row['price'],
		'salePrice'     => $row['salePrice'],
		'incomplete'    => $row['incomplete'],
		'status'        => $row['status'],
		'issues'        => $row['issues'],
		'repeatOf'      => $row['repeatOf'],
		'duplicateOf'   => $row['duplicateOf'],
	);
}
