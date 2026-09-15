<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function add_action( mixed ...$args ): bool { return true; }

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
/**
 * Resposta literal da API de produção para uma referência desconhecida.
 * Ela chega com HTTP 200, e não 404.
 */
$nao_encontrado = array(
	'conhecimentos'    => array(),
	'totalNf'          => 0,
	'fluxoAtendimento' => null,
);

braspress_tracking_assert(
	'consulta sem conhecimento é reconhecida como não encontrada',
	papelito_braspress_tracking_is_empty( $nao_encontrado )
);
braspress_tracking_assert(
	'consulta sem conhecimento não inventa status para exibir',
	'' === papelito_braspress_tracking_external_status( $nao_encontrado )
);
braspress_tracking_assert(
	'resposta sem a lista de conhecimentos também é não encontrada',
	papelito_braspress_tracking_is_empty( array( 'totalNf' => 0 ) )
);
braspress_tracking_assert(
	'status externo é lido de dentro do conhecimento, não da raiz',
	'Em rota para a filial' === papelito_braspress_tracking_external_status(
		array( 'conhecimentos' => array( array( 'descricaoUltimaOcorrencia' => 'Em rota para a filial' ) ) )
	)
);
braspress_tracking_assert(
	'status de transporte serve quando não há última ocorrência',
	'EM TRANSITO' === papelito_braspress_tracking_external_status(
		array( 'conhecimentos' => array( array( 'statusTransporte' => 'EM TRANSITO' ) ) )
	)
);
braspress_tracking_assert(
	'status na raiz é ignorado porque o contrato não o publica ali',
	'' === papelito_braspress_tracking_external_status( array( 'situacao' => 'Em rota', 'conhecimentos' => array() ) )
);
braspress_tracking_assert(
	'conhecimento sem nenhum campo conhecido não quebra o poll',
	'Atualização recebida' === papelito_braspress_tracking_external_status(
		array( 'conhecimentos' => array( array( 'numero' => 123456 ) ) )
	)
);

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
