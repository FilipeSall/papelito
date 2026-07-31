<?php
/**
 * Validação local de documentos fiscais.
 *
 * Nada aqui consulta rede nem afirma validação perante o fisco: é conferência
 * estrutural da chave, do CNPJ e do XML, mais cruzamentos informativos com o
 * pedido. Divergência vira flag e revisão — nunca bloqueia pagamento,
 * fulfillment, postagem ou entrega.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_FISCAL_XML_MAX_BYTES' ) ) {
	define( 'PAPELITO_FISCAL_XML_MAX_BYTES', 2 * 1048576 );
}

if ( ! defined( 'PAPELITO_FISCAL_PDF_MAX_BYTES' ) ) {
	define( 'PAPELITO_FISCAL_PDF_MAX_BYTES', 10 * 1048576 );
}

/**
 * Níveis de validação **local**. Nenhum deles afirma validação perante o fisco:
 * 1 arquivo aceito, 2 chave estruturalmente válida, 3 XML coerente com a chave,
 * 4 emitente confere, 5 valor confere com a parcela do recibo.
 */
if ( ! defined( 'PAPELITO_FISCAL_LEVEL_FILE' ) ) {
	define( 'PAPELITO_FISCAL_LEVEL_FILE', 1 );
	define( 'PAPELITO_FISCAL_LEVEL_KEY', 2 );
	define( 'PAPELITO_FISCAL_LEVEL_XML', 3 );
	define( 'PAPELITO_FISCAL_LEVEL_ISSUER', 4 );
	define( 'PAPELITO_FISCAL_LEVEL_AMOUNT', 5 );
}

/**
 * Raízes de XML aceitas. Qualquer outra é recusada antes de ler conteúdo.
 *
 * @return array<int,string>
 */
function papelito_fiscal_xml_allowed_roots(): array {
	return array( 'nfeProc', 'NFe', 'enviNFe', 'nfseProc', 'NFSe', 'DPS' );
}

/**
 * Modelos de documento por tipo, para conferir coerência com a chave.
 *
 * @return array<string,array<int,string>>
 */
function papelito_fiscal_document_models(): array {
	return array(
		'nfe'  => array( '55' ),
		'nfce' => array( '65' ),
	);
}

function papelito_fiscal_key_normalize( string $raw ): string {
	return (string) preg_replace( '/\D/', '', $raw );
}

/**
 * Dígito verificador da chave de acesso: módulo 11 com pesos 2..9 da direita
 * para a esquerda; resto 0 ou 1 resulta em DV zero.
 */
function papelito_fiscal_key_check_digit( string $key43 ): int {
	if ( 43 !== strlen( $key43 ) || 1 !== preg_match( '/^\d{43}$/', $key43 ) ) {
		return -1;
	}

	$sum    = 0;
	$weight = 2;

	for ( $index = 42; $index >= 0; $index-- ) {
		$sum   += (int) $key43[ $index ] * $weight;
		$weight = $weight >= 9 ? 2 : $weight + 1;
	}

	$remainder = $sum % 11;

	return $remainder < 2 ? 0 : 11 - $remainder;
}

function papelito_fiscal_key_is_valid( string $key ): bool {
	$key = papelito_fiscal_key_normalize( $key );

	if ( 44 !== strlen( $key ) ) {
		return false;
	}

	return papelito_fiscal_key_check_digit( substr( $key, 0, 43 ) ) === (int) $key[43];
}

/**
 * Campos embutidos na chave de acesso de NF-e/NFC-e.
 *
 * @return array<string,string>
 */
function papelito_fiscal_key_parts( string $key ): array {
	$key = papelito_fiscal_key_normalize( $key );

	if ( 44 !== strlen( $key ) ) {
		return array();
	}

	return array(
		'uf'          => substr( $key, 0, 2 ),
		'year_month'  => substr( $key, 2, 4 ),
		'issuer_cnpj' => substr( $key, 6, 14 ),
		'model'       => substr( $key, 20, 2 ),
		'series'      => substr( $key, 22, 3 ),
		'number'      => substr( $key, 25, 9 ),
		'issue_type'  => substr( $key, 34, 1 ),
		'code'        => substr( $key, 35, 8 ),
		'check_digit' => substr( $key, 43, 1 ),
	);
}

/**
 * Situação estrutural da chave: `valida`, `invalida` ou `ausente`.
 *
 * DV inválido é armazenável como `invalida` quando a entrada é estruturalmente
 * segura — a chave continua servindo para busca e sinalização administrativa.
 */
function papelito_fiscal_key_status( string $raw ): string {
	$key = papelito_fiscal_key_normalize( $raw );

	if ( '' === $key ) {
		return 'ausente';
	}

	return papelito_fiscal_key_is_valid( $key ) ? 'valida' : 'invalida';
}

/**
 * Parse sem rede, sem entidade externa e apenas com raiz esperada.
 *
 * @return SimpleXMLElement|WP_Error
 */
function papelito_fiscal_xml_parse( string $contents ) {
	if ( '' === trim( $contents ) ) {
		return new WP_Error( 'papelito_fiscal_xml_empty', 'O XML enviado está vazio.', array( 'status' => 422 ) );
	}

	if ( strlen( $contents ) > PAPELITO_FISCAL_XML_MAX_BYTES ) {
		return new WP_Error( 'papelito_fiscal_xml_too_large', 'O XML deve ter no máximo 2 MB.', array( 'status' => 413 ) );
	}

	if ( ! function_exists( 'papelito_private_file_xml_is_hostile' ) || papelito_private_file_xml_is_hostile( $contents ) ) {
		return new WP_Error( 'papelito_fiscal_xml_unsafe', 'O XML enviado contém construções não permitidas.', array( 'status' => 422 ) );
	}

	if ( ! class_exists( 'SimpleXMLElement' ) ) {
		return new WP_Error( 'papelito_fiscal_xml_unsupported', 'O servidor não consegue processar XML.', array( 'status' => 500 ) );
	}

	$previous = libxml_use_internal_errors( true );
	libxml_clear_errors();

	try {
		$xml = new SimpleXMLElement( $contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
	} catch ( Throwable $error ) {
		$xml = null;
	}

	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $xml instanceof SimpleXMLElement ) {
		return new WP_Error( 'papelito_fiscal_xml_malformed', 'O XML enviado é inválido.', array( 'status' => 422 ) );
	}

	if ( ! in_array( $xml->getName(), papelito_fiscal_xml_allowed_roots(), true ) ) {
		return new WP_Error( 'papelito_fiscal_xml_root_invalid', 'O XML enviado não é um documento fiscal reconhecido.', array( 'status' => 422 ) );
	}

	return $xml;
}

/**
 * Primeiro valor não vazio para uma lista de caminhos XPath.
 *
 * @param array<int,string> $paths Caminhos, na ordem de preferência.
 */
function papelito_fiscal_xml_first_value( SimpleXMLElement $xml, array $paths ): string {
	foreach ( $paths as $path ) {
		$found = @$xml->xpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- xpath emite warning em namespace ausente.

		if ( is_array( $found ) && ! empty( $found ) ) {
			$value = trim( (string) $found[0] );

			if ( '' !== $value ) {
				return $value;
			}
		}
	}

	return '';
}

/**
 * Extrai o que interessa do XML, sem interpretar nada como autorizado.
 *
 * @return array<string,string>
 */
function papelito_fiscal_xml_extract( SimpleXMLElement $xml ): array {
	foreach ( $xml->getDocNamespaces( true ) as $prefix => $namespace ) {
		if ( '' === $prefix ) {
			$xml->registerXPathNamespace( 'nfe', $namespace );
		}
	}

	$key = papelito_fiscal_xml_first_value(
		$xml,
		array( '//nfe:infNFe/@Id', '//infNFe/@Id', '//nfe:chNFe', '//chNFe', '//nfe:infDPS/@Id', '//infDPS/@Id' )
	);

	$total = papelito_fiscal_xml_first_value(
		$xml,
		array( '//nfe:ICMSTot/nfe:vNF', '//ICMSTot/vNF', '//nfe:vNF', '//vNF', '//nfe:vLiq', '//vLiq', '//nfe:vServ', '//vServ' )
	);

	return array(
		'access_key'  => papelito_fiscal_key_normalize( $key ),
		'issuer_cnpj' => papelito_fiscal_key_normalize(
			papelito_fiscal_xml_first_value( $xml, array( '//nfe:emit/nfe:CNPJ', '//emit/CNPJ', '//nfe:prest/nfe:CNPJ', '//prest/CNPJ' ) )
		),
		'issuer_name' => papelito_fiscal_xml_first_value( $xml, array( '//nfe:emit/nfe:xNome', '//emit/xNome', '//nfe:prest/nfe:xNome', '//prest/xNome' ) ),
		'issued_at'   => papelito_fiscal_xml_first_value( $xml, array( '//nfe:ide/nfe:dhEmi', '//ide/dhEmi', '//nfe:ide/nfe:dEmi', '//ide/dEmi', '//nfe:dhEmi', '//dhEmi' ) ),
		'number'      => papelito_fiscal_xml_first_value( $xml, array( '//nfe:ide/nfe:nNF', '//ide/nNF', '//nfe:nNF', '//nNF' ) ),
		'series'      => papelito_fiscal_xml_first_value( $xml, array( '//nfe:ide/nfe:serie', '//ide/serie', '//nfe:serie', '//serie' ) ),
		'protocol'    => papelito_fiscal_xml_first_value( $xml, array( '//nfe:infProt/nfe:nProt', '//infProt/nProt', '//nfe:nProt', '//nProt' ) ),
		'total'       => $total,
	);
}

/**
 * Converte um valor monetário do XML para centavos, sem perder arredondamento.
 */
function papelito_fiscal_amount_to_cents( string $amount ): int {
	$amount = trim( $amount );

	if ( '' === $amount || 1 !== preg_match( '/^-?\d+(?:[.,]\d+)?$/', $amount ) ) {
		return 0;
	}

	return (int) round( (float) str_replace( ',', '.', $amount ) * 100 );
}

/**
 * Data do XML normalizada para mysql UTC, ou vazio quando não interpretável.
 */
function papelito_fiscal_normalize_datetime( string $value ): string {
	$value = trim( $value );

	if ( '' === $value ) {
		return '';
	}

	$timestamp = strtotime( $value );

	return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Compara o que foi digitado com o que o XML diz.
 *
 * O digitado **nunca** é sobrescrito: cada divergência vira uma flag.
 *
 * @param array<string,mixed> $declared  Dados informados pelo vendor.
 * @param array<string,mixed> $extracted Dados lidos do XML.
 * @return array<int,string>
 */
function papelito_fiscal_compare_declared( array $declared, array $extracted ): array {
	$flags = array();

	$pairs = array(
		'access_key'  => 'chave_divergente',
		'issuer_cnpj' => 'emitente_divergente',
		'number'      => 'numero_divergente',
		'series'      => 'serie_divergente',
	);

	foreach ( $pairs as $field => $flag ) {
		$left  = trim( (string) ( $declared[ $field ] ?? '' ) );
		$right = trim( (string) ( $extracted[ $field ] ?? '' ) );

		if ( '' !== $left && '' !== $right && ltrim( $left, '0' ) !== ltrim( $right, '0' ) ) {
			$flags[] = $flag;
		}
	}

	$declared_cents  = (int) ( $declared['total_cents'] ?? 0 );
	$extracted_cents = (int) ( $extracted['total_cents'] ?? 0 );

	if ( $declared_cents > 0 && $extracted_cents > 0 && $declared_cents !== $extracted_cents ) {
		$flags[] = 'valor_divergente';
	}

	return $flags;
}

/**
 * Coerência interna da chave: modelo, CNPJ do emitente e mês de emissão.
 *
 * @param array<string,mixed> $document Documento com chave, tipo, emitente e emissão.
 * @return array<int,string>
 */
function papelito_fiscal_key_coherence_flags( array $document ): array {
	$flags = array();
	$parts = papelito_fiscal_key_parts( (string) ( $document['access_key'] ?? '' ) );

	if ( empty( $parts ) ) {
		return $flags;
	}

	$models = papelito_fiscal_document_models();
	$type   = (string) ( $document['doc_type'] ?? '' );

	if ( isset( $models[ $type ] ) && ! in_array( $parts['model'], $models[ $type ], true ) ) {
		$flags[] = 'modelo_incoerente';
	}

	$issuer = papelito_fiscal_key_normalize( (string) ( $document['issuer_cnpj'] ?? '' ) );
	if ( '' !== $issuer && $issuer !== $parts['issuer_cnpj'] ) {
		$flags[] = 'emitente_fora_da_chave';
	}

	$issued_at = papelito_fiscal_normalize_datetime( (string) ( $document['issued_at'] ?? '' ) );
	if ( '' !== $issued_at ) {
		$key_year_month = substr( $issued_at, 2, 2 ) . substr( $issued_at, 5, 2 );

		if ( $key_year_month !== $parts['year_month'] ) {
			$flags[] = 'emissao_incoerente';
		}
	}

	if ( '' !== $issuer && function_exists( 'papelito_validate_cnpj' ) && ! papelito_validate_cnpj( $issuer ) ) {
		$flags[] = 'cnpj_emitente_invalido';
	}

	return $flags;
}

/**
 * Cruzamentos com o pedido. Estritamente informativos: nenhum deles pode tocar
 * estado de pagamento, de logística ou de fulfillment.
 *
 * @param array<string,mixed> $document Documento fiscal.
 * @param array<string,mixed> $context  Dados do pedido: vendor_cnpj, part_total_cents.
 * @return array<int,string>
 */
function papelito_fiscal_order_cross_flags( array $document, array $context ): array {
	$flags = array();

	$vendor_cnpj = papelito_fiscal_key_normalize( (string) ( $context['vendor_cnpj'] ?? '' ) );
	$issuer_cnpj = papelito_fiscal_key_normalize( (string) ( $document['issuer_cnpj'] ?? '' ) );

	if ( '' !== $vendor_cnpj && '' !== $issuer_cnpj && $vendor_cnpj !== $issuer_cnpj ) {
		$flags[] = 'emitente_nao_e_o_vendor';
	}

	$part_cents     = (int) ( $context['part_total_cents'] ?? 0 );
	$document_cents = (int) ( $document['total_cents'] ?? 0 );

	if ( $part_cents > 0 && $document_cents > 0 && $part_cents !== $document_cents ) {
		$flags[] = 'valor_fora_do_pedido';
	}

	return $flags;
}

/**
 * Nível de validação alcançado, de 1 (arquivo aceito) a 5 (valor confere).
 *
 * @param array<int,string>    $flags   Flags acumuladas.
 * @param array<string,mixed> $context Dados do pedido usados nos cruzamentos.
 */
function papelito_fiscal_validation_level( array $document, array $flags, array $context = array() ): int {
	if ( 'valida' !== papelito_fiscal_key_status( (string) ( $document['access_key'] ?? '' ) ) ) {
		return PAPELITO_FISCAL_LEVEL_FILE;
	}

	if ( empty( $document['has_xml'] ) ) {
		return PAPELITO_FISCAL_LEVEL_KEY;
	}

	if ( in_array( 'chave_divergente', $flags, true ) || in_array( 'modelo_incoerente', $flags, true ) || in_array( 'emissao_incoerente', $flags, true ) ) {
		return PAPELITO_FISCAL_LEVEL_XML;
	}

	$vendor_cnpj = papelito_fiscal_key_normalize( (string) ( $context['vendor_cnpj'] ?? '' ) );
	$issuer_cnpj = papelito_fiscal_key_normalize( (string) ( $document['issuer_cnpj'] ?? '' ) );
	if ( '' === $vendor_cnpj || '' === $issuer_cnpj ) {
		return PAPELITO_FISCAL_LEVEL_XML;
	}

	if ( in_array( 'emitente_divergente', $flags, true ) || in_array( 'emitente_fora_da_chave', $flags, true ) || in_array( 'emitente_nao_e_o_vendor', $flags, true ) || in_array( 'cnpj_emitente_invalido', $flags, true ) ) {
		return PAPELITO_FISCAL_LEVEL_XML;
	}

	$part_cents     = (int) ( $context['part_total_cents'] ?? 0 );
	$document_cents = (int) ( $document['total_cents'] ?? 0 );
	if ( $part_cents <= 0 || $document_cents <= 0 ) {
		return PAPELITO_FISCAL_LEVEL_ISSUER;
	}

	if ( in_array( 'valor_divergente', $flags, true ) || in_array( 'valor_fora_do_pedido', $flags, true ) ) {
		return PAPELITO_FISCAL_LEVEL_ISSUER;
	}

	return PAPELITO_FISCAL_LEVEL_AMOUNT;
}
