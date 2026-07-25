<?php
/**
 * Standalone regression test for the QSA name-evidence normalizer.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );

class WP_User {}

function remove_accents( string $value ): string {
	return strtr( $value, array( 'Á' => 'A', 'á' => 'a', 'À' => 'A', 'à' => 'a', 'Â' => 'A', 'â' => 'a', 'Ã' => 'A', 'ã' => 'a', 'É' => 'E', 'é' => 'e', 'Ê' => 'E', 'ê' => 'e', 'Í' => 'I', 'í' => 'i', 'Ó' => 'O', 'ó' => 'o', 'Ô' => 'O', 'ô' => 'o', 'Õ' => 'O', 'õ' => 'o', 'Ú' => 'U', 'ú' => 'u', 'Ç' => 'C', 'ç' => 'c' ) );
}

require __DIR__ . '/../includes/company_services.php';

$failures = 0;
function papelito_company_name_assert_same( string $label, string $expected, string $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "PASS: {$label}\n"; return; }
	++$failures;
	echo "FAIL: {$label}\n";
}
function papelito_company_name_assert_different( string $label, string $left, string $right ): void {
	global $failures;
	if ( $left !== $right ) { echo "PASS: {$label}\n"; return; }
	++$failures;
	echo "FAIL: {$label}\n";
}

papelito_company_name_assert_same( 'trims edges and collapses whitespace', 'FILIPE REGES DE SALLES', papelito_company_normalize_name( "  Filipe   Reges de Salles  " ) );
papelito_company_name_assert_same( 'case is ignored', 'FILIPE REGES DE SALLES', papelito_company_normalize_name( 'filipe reges de salles' ) );
papelito_company_name_assert_same( 'accents are ignored', 'JOAO DA SILVA', papelito_company_normalize_name( 'João da Silva' ) );
papelito_company_name_assert_same( 'unicode combining accents are ignored', 'JOAO DA SILVA', papelito_company_normalize_name( "Joa\u{0303}o da Silva" ) );
papelito_company_name_assert_same( 'hyphens apostrophes and punctuation are token separators', 'ANA MARIA D AVILA', papelito_company_normalize_name( "Ana-Maria D'Ávila," ) );
papelito_company_name_assert_same( 'particles are preserved', 'NOME DE DA DO DAS DOS E SOBRENOME', papelito_company_normalize_name( 'Nome de da do das dos e Sobrenome' ) );
papelito_company_name_assert_different( 'omitting de is not equivalent', papelito_company_normalize_name( 'Filipe Reges de Salles' ), papelito_company_normalize_name( 'Filipe Reges Salles' ) );
papelito_company_name_assert_different( 'different surname is not equivalent', papelito_company_normalize_name( 'Filipe Reges de Salles' ), papelito_company_normalize_name( 'Filipe Rego de Salles' ) );
papelito_company_name_assert_different( 'different compound first name is not equivalent', papelito_company_normalize_name( 'Ana Maria Souza' ), papelito_company_normalize_name( 'Ana Mariana Souza' ) );

exit( $failures > 0 ? 1 : 0 );
