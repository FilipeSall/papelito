<?php
/**
 * Validação local oficial de CPF, CNPJ (numérico e alfanumérico) e formato de CEP.
 *
 * Fonte autoritativa da Fase 0 do modelo B2B. Estas funções fazem apenas validação
 * ESTRUTURAL (dígitos verificadores / formato). A ACEITAÇÃO de um documento (ex.: se o
 * sistema processa um CNPJ alfanumérico) é governada por feature flags em company_flags.php
 * e não deve depender destas funções.
 *
 * CNPJ alfanumérico: as 12 primeiras posições podem conter A-Z e 0-9; as duas últimas são
 * sempre dígitos verificadores numéricos. O cálculo do DV usa o valor (ASCII - 48) de cada
 * caractere, conforme a especificação da Receita Federal.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normaliza um CPF para 11 dígitos numéricos (sem máscara).
 *
 * Retorna string vazia se não houver exatamente 11 dígitos.
 */
function papelito_normalize_cpf( string $raw ): string {
	$digits = preg_replace( '/\D+/', '', $raw );

	return 11 === strlen( $digits ) ? $digits : '';
}

/**
 * Valida um CPF pelos dígitos verificadores oficiais (mod 11).
 */
function papelito_validate_cpf( string $raw ): bool {
	$cpf = papelito_normalize_cpf( $raw );

	if ( '' === $cpf ) {
		return false;
	}

	if ( preg_match( '/^(\d)\1{10}$/', $cpf ) ) {
		return false;
	}

	for ( $t = 9; $t < 11; $t++ ) {
		$sum = 0;
		for ( $i = 0; $i < $t; $i++ ) {
			$sum += (int) $cpf[ $i ] * ( ( $t + 1 ) - $i );
		}
		$digit = ( ( 10 * $sum ) % 11 ) % 10;
		if ( (int) $cpf[ $t ] !== $digit ) {
			return false;
		}
	}

	return true;
}

/**
 * Canonicaliza um CNPJ: uppercase, mantém apenas A-Z e 0-9, exatamente 14 posições.
 *
 * NÃO usa `\D` (isso destruiria as letras do CNPJ alfanumérico). Retorna string vazia
 * quando o resultado não tiver 14 caracteres válidos.
 */
function papelito_normalize_cnpj( string $raw ): string {
	$upper    = strtoupper( $raw );
	$filtered = preg_replace( '/[^A-Z0-9]/', '', $upper );

	return 14 === strlen( $filtered ) ? $filtered : '';
}

/**
 * Indica se um CNPJ (já canônico ou bruto) contém letras nas 12 primeiras posições.
 *
 * Classificação apenas — não decide aceitação (isso é feature flag).
 */
function papelito_cnpj_is_alphanumeric( string $raw ): bool {
	$cnpj = papelito_normalize_cnpj( $raw );

	if ( '' === $cnpj ) {
		return false;
	}

	return (bool) preg_match( '/[A-Z]/', substr( $cnpj, 0, 12 ) );
}

/**
 * Valor posicional de um caractere do CNPJ para cálculo do DV: ASCII - 48.
 *
 * '0'..'9' → 0..9; 'A'..'Z' → 17..42. Conforme a especificação do CNPJ alfanumérico.
 */
function papelito_cnpj_char_value( string $char ): int {
	return ord( $char ) - 48;
}

/**
 * Calcula um dígito verificador do CNPJ (numérico ou alfanumérico) por mod 11.
 *
 * @param string $base    Base (12 ou 13 caracteres já canônicos).
 * @param int[]  $weights Pesos correspondentes a cada posição da base.
 */
function papelito_cnpj_calc_digit( string $base, array $weights ): int {
	$sum = 0;
	$len = strlen( $base );

	for ( $i = 0; $i < $len; $i++ ) {
		$sum += papelito_cnpj_char_value( $base[ $i ] ) * $weights[ $i ];
	}

	$remainder = $sum % 11;

	return $remainder < 2 ? 0 : 11 - $remainder;
}

/**
 * Valida um CNPJ pelos dígitos verificadores oficiais, aceitando o formato alfanumérico.
 *
 * Regras estruturais: 14 posições canônicas; as 12 primeiras em [A-Z0-9]; as 2 últimas
 * numéricas; DV correto. Validação estrutural — independe de qualquer feature flag.
 */
function papelito_validate_cnpj( string $raw ): bool {
	$cnpj = papelito_normalize_cnpj( $raw );

	if ( '' === $cnpj ) {
		return false;
	}

	$base  = substr( $cnpj, 0, 12 );
	$check = substr( $cnpj, 12, 2 );

	if ( ! preg_match( '/^[A-Z0-9]{12}$/', $base ) || ! preg_match( '/^\d{2}$/', $check ) ) {
		return false;
	}

	if ( preg_match( '/^0{12}\d{2}$/', $cnpj ) ) {
		return false;
	}

	$weights_first  = array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );
	$weights_second = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

	$first_digit  = papelito_cnpj_calc_digit( $base, $weights_first );
	$second_digit = papelito_cnpj_calc_digit( $base . (string) $first_digit, $weights_second );

	return $check === (string) $first_digit . (string) $second_digit;
}

/**
 * Normaliza um CEP para 8 dígitos numéricos (sem máscara).
 *
 * Retorna string vazia se não houver exatamente 8 dígitos.
 */
function papelito_normalize_cep( string $raw ): string {
	$digits = preg_replace( '/\D+/', '', $raw );

	return 8 === strlen( $digits ) ? $digits : '';
}

/**
 * Valida apenas o FORMATO estrutural do CEP (8 dígitos).
 *
 * NÃO comprova existência do CEP — a consulta remota (ViaCEP/BrasilAPI) permanece separada.
 * Diferencie os estados `cep_invalid` (falha aqui), `cep_not_found` e `cep_provider_unavailable`
 * (falhas da consulta remota) na camada que consome esta função.
 */
function papelito_validate_cep_format( string $raw ): bool {
	return '' !== papelito_normalize_cep( $raw );
}
