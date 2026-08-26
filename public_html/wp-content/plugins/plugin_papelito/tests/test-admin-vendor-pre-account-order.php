<?php

$failures = 0;

function papelito_assert( string $label, bool $condition ): void {
	global $failures;

	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

function papelito_function_source( string $source, string $function, string $next_marker ): string {
	$start = strpos( $source, "function {$function}(" );
	$end   = strpos( $source, $next_marker, false === $start ? 0 : $start );

	return false === $start || false === $end ? '' : substr( $source, $start, $end - $start );
}

$source = (string) file_get_contents( __DIR__ . '/../includes/revendedor_application.php' );
$create = papelito_function_source(
	$source,
	'papelito_admin_vendors_create_direct_vendor',
	'function papelito_admin_vendors_resolve_direct_source'
);
$prepare = papelito_function_source(
	$source,
	'papelito_admin_vendors_prepare_direct_vendor',
	'function papelito_admin_vendors_persist_direct_vendor'
);

$persist = strpos( $create, 'papelito_admin_vendors_persist_direct_vendor( $prepared )' );
$failure = strpos( $create, 'if ( is_wp_error( $user_id ) )' );
$reject  = strpos( $create, 'papelito_pre_account_application_reject_open_for_vendor( $prepared[\'email\'], $reviewer_id )' );

papelito_assert( 'encontrou o coordenador de criacao direta', '' !== $create );
papelito_assert( 'encontrou a preparacao do vendor', '' !== $prepare );
papelito_assert( 'preparacao nao encerra candidatura empresarial', false === strpos( $prepare, 'papelito_pre_account_application_reject_open_for_vendor' ) );
papelito_assert( 'persistencia ocorre antes de encerrar candidatura', false !== $persist && false !== $reject && $persist < $reject );
papelito_assert( 'falha de persistencia retorna antes de encerrar candidatura', false !== $failure && false !== $reject && $failure < $reject );

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAIL\n";
exit( 0 === $failures ? 0 : 1 );
