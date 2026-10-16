<?php
/**
 * Dicionário de nomenclatura da importação de códigos do vendor.
 *
 * Fonte única dos termos que o matcher reconhece: tipos de produto, atributos
 * por faceta, palavras de enchimento e extratores numéricos. Cada termo tem
 * `forms` (grafias do mesmo nome, como `longa`/`longo`) e `synonyms` (nome
 * comercial diferente para a mesma coisa, como `triturador` para `dichavador`).
 * Só sinônimo marca a linha como "correspondência por sinônimo".
 *
 * Regra de ouro: sinônimo entra aqui só com fundamento no catálogo ou na
 * nomenclatura que ele já usou. Termo parecido não é termo igual — `slim` e
 * `king size` vivem em facetas diferentes justamente para nunca se anularem.
 * Extensão sem tocar no código: filtro `papelito_vendor_code_import_dictionary`.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dicionário completo, já passado pelo filtro de extensão.
 *
 * As facetas definem o que é contradição: dois valores diferentes na mesma
 * faceta tornam produtos incompatíveis, e a faceta `combo` presente de um lado
 * só também. Cores não moram aqui; vêm das variações do próprio catálogo.
 *
 * @return array{types:array<string,array<string,string[]>>,facets:array<string,array<string,array<string,string[]>>>,fillers:array<string,string[]>,subgroup_prefixes:string[]}
 */
function papelito_vendor_code_import_dictionary(): array {
	$dictionary = array(
		'types'             => array(
			'seda'       => array( 'forms' => array( 'seda' ) ),
			'piteira'    => array( 'forms' => array( 'piteira' ) ),
			'filtro'     => array( 'forms' => array( 'filtro' ) ),
			'dichavador' => array(
				'forms'    => array( 'dichavador' ),
				'synonyms' => array( 'triturador', 'grinder' ),
			),
			'tubelito'   => array( 'forms' => array( 'tubelito' ) ),
			'bandeja'    => array( 'forms' => array( 'bandeja' ) ),
			'cinzeiro'   => array( 'forms' => array( 'cinzeiro' ) ),
			'kit'        => array( 'forms' => array( 'kit' ) ),
		),
		'facets'            => array(
			'tamanho' => array(
				'extra-large' => array( 'forms' => array( 'extra large' ) ),
				'king-size'   => array(
					'forms'    => array( 'king size', 'kingsize' ),
					'synonyms' => array( 'ks' ),
				),
				'mini-size'   => array(
					'forms'    => array( 'mini size', 'minisize', 'mini' ),
					'synonyms' => array( 'ms' ),
				),
				'ultra-longa' => array( 'forms' => array( 'ultra longa', 'ultra longo' ) ),
				'mega-longa'  => array( 'forms' => array( 'mega longa', 'mega longo' ) ),
				'hiper-longa' => array( 'forms' => array( 'hiper longa', 'hiper longo' ) ),
				'longa'       => array( 'forms' => array( 'longa', 'longo' ) ),
				'large'       => array( 'forms' => array( 'large' ) ),
			),
			'largura' => array(
				'slim' => array( 'forms' => array( 'slim' ) ),
			),
			'linha'   => array(
				'tradicional' => array( 'forms' => array( 'tradicional' ) ),
				'brown'       => array( 'forms' => array( 'brown' ) ),
				'hemp'        => array( 'forms' => array( 'hemp' ) ),
				'alfafa'      => array( 'forms' => array( 'alfafa' ) ),
				'pink'        => array( 'forms' => array( 'pink' ) ),
				'tropical'    => array( 'forms' => array( 'tropical' ) ),
				'bio'         => array(
					'forms'    => array( 'bio' ),
					'synonyms' => array( 'biodegradavel', 'biodegradaveis' ),
				),
				'mentol'      => array(
					'forms'    => array( 'mentol' ),
					'synonyms' => array( 'mentolado' ),
				),
				'gomado'      => array( 'forms' => array( 'gomado' ) ),
				'cores'       => array( 'forms' => array( 'cores' ) ),
				'brilho'      => array( 'forms' => array( 'brilho' ) ),
				'neon'        => array( 'forms' => array( 'neon' ) ),
				'cristal'     => array( 'forms' => array( 'cristal' ) ),
			),
			'colecao' => array(
				'insane' => array( 'forms' => array( 'insane' ) ),
				'fun'    => array( 'forms' => array( 'fun' ) ),
			),
			'formato' => array(
				'dupla' => array( 'forms' => array( 'dupla', 'duplo' ) ),
			),
			'combo'   => array(
				'com-piteira' => array( 'forms' => array( 'com piteira' ) ),
				'com-bandeja' => array( 'forms' => array( 'com bandeja' ) ),
			),
		),
		'fillers'           => array(
			'*'          => array( 'papelito', 'display', 'de', 'da', 'do', 'das', 'dos', 'e', 'com', 'c', 'un', 'und', 'unid', 'unidade', 'unidades', 'pct', 'cx' ),
			'piteira'    => array( 'papel', 'verge' ),
			'filtro'     => array( 'enrolar', 'acetato' ),
			'dichavador' => array( 'plastico', 'policarbonato' ),
			'tubelito'   => array( 'plastico' ),
		),
		'subgroup_prefixes' => array( 'headshop' ),
	);

	/**
	 * Estende o dicionário da importação de códigos do vendor.
	 *
	 * @param array $dictionary Dicionário padrão.
	 */
	return (array) apply_filters( 'papelito_vendor_code_import_dictionary', $dictionary );
}

/**
 * Expressões numéricas, aplicadas antes da tokenização sobre o texto normalizado.
 *
 * Quantidade é a do display (`c/50`, `25 livretos`); dimensão é `LxC` em mm;
 * gramatura em gramas. O catálogo só declara quantidade e dimensão na descrição,
 * por isso gramatura da planilha nunca confirma uma correspondência sozinha.
 *
 * @return array{qty:string[],dim:string,weight:string,catalog_qty:string}
 */
function papelito_vendor_code_import_number_patterns(): array {
	return array(
		'qty'         => array(
			'/\bc\s*\/\s*(\d{1,4})\b/',
			'/\b(\d{1,4})\s*livretos?\b/',
		),
		'dim'         => '/\b(\d+(?:[.,]\d+)?)\s*x\s*(\d+(?:[.,]\d+)?)\s*(?:mm|m)?\b/',
		'weight'      => '/\b(\d+(?:[.,]\d+)?)\s*g\b(?!\s*\/)/',
		'catalog_qty' => '/\b(\d{1,4})\s*livretos?\s+por\s+display\b/',
	);
}
