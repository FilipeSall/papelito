<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function add_action() {}

require_once dirname( __DIR__ ) . '/includes/braspress_tracking.php';

$failures = 0;
function braspress_tracking_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

braspress_tracking_assert(
	'referência externa aceita número de pedido sem virar código S10',
	'PED-2026/001' === papelito_braspress_tracking_normalize_reference( ' PED-2026/001 ' )
);
braspress_tracking_assert(
	'referência externa recusa caracteres de controle e URL',
	'' === papelito_braspress_tracking_normalize_reference( 'pedido 001?x=1' )
);
braspress_tracking_assert(
	'status externo é preservado sem mapeá-lo para entrega',
	'Em rota para a filial' === papelito_braspress_tracking_external_status( array( 'situacao' => 'Em rota para a filial' ) )
);
braspress_tracking_assert(
	'resposta sem status recebe rótulo neutro',
	'Atualização recebida' === papelito_braspress_tracking_external_status( array( 'itens' => array() ) )
);

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
