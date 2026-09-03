<?php
/**
 * Carrega apenas as funcoes de versionamento do cache de cobertura de `rest_api.php`.
 *
 * O arquivo inteiro depende de WooCommerce e do restante do plugin; aqui interessa so a regra de
 * invalidacao por usermeta, entao as funcoes sao extraidas por recorte de texto.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

$papelito_rest_api_source = (string) file_get_contents( __DIR__ . '/../../includes/rest_api.php' );

$papelito_wanted = array(
	'function papelito_coverage_cache_version(',
	'function papelito_coverage_bump_cache_version(',
	'function papelito_coverage_products_cache_key(',
	'function papelito_coverage_maybe_bump_user_meta_cache(',
);

$papelito_extracted = '';

foreach ( $papelito_wanted as $papelito_signature ) {
	$papelito_start = strpos( $papelito_rest_api_source, $papelito_signature );

	if ( false === $papelito_start ) {
		throw new RuntimeException( "Funcao ausente em rest_api.php: {$papelito_signature}" );
	}

	$papelito_depth  = 0;
	$papelito_open   = strpos( $papelito_rest_api_source, '{', $papelito_start );
	$papelito_cursor = $papelito_open;

	do {
		$papelito_char = $papelito_rest_api_source[ $papelito_cursor ];

		if ( '{' === $papelito_char ) {
			++$papelito_depth;
		} elseif ( '}' === $papelito_char ) {
			--$papelito_depth;
		}

		++$papelito_cursor;
	} while ( $papelito_depth > 0 && $papelito_cursor < strlen( $papelito_rest_api_source ) );

	$papelito_extracted .= substr( $papelito_rest_api_source, $papelito_start, $papelito_cursor - $papelito_start ) . "\n";
}

eval( $papelito_extracted ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

add_action( 'added_user_meta', 'papelito_coverage_maybe_bump_user_meta_cache', 10, 3 );
add_action( 'updated_user_meta', 'papelito_coverage_maybe_bump_user_meta_cache', 10, 3 );
add_action( 'deleted_user_meta', 'papelito_coverage_maybe_bump_user_meta_cache', 10, 3 );
