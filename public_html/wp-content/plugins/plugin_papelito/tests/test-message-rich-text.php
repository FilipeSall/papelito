<?php
/** Corpo estruturado da mensagem: nada de HTML entra, e a projeção plana é derivada. */

require_once __DIR__ . '/support/chamado_env.php';
require_once __DIR__ . '/../includes/vendor_messaging.php';

papelito_test_as( CHAMADO_TEST_CUSTOMER_ID );

function rich( mixed $content ) {
	return papelito_messaging_normalize_body_content( $content );
}

function rich_code( mixed $result ): ?string {
	return is_wp_error( $result ) ? $result->get_error_code() : null;
}

$vazio_null  = rich( null );
$vazio_lista = rich( array() );
$valido      = rich(
	array(
		array( 'type' => 'text', 'text' => 'oi' ),
		array( 'type' => 'text', 'text' => 'forte', 'bold' => true ),
	)
);
$booleanos = rich(
	array(
		array( 'type' => 'text', 'text' => 'a', 'italic' => '1' ),
		array( 'type' => 'text', 'text' => 'b', 'italic' => '0' ),
		array( 'type' => 'text', 'text' => 'c', 'bold' => 1, 'italic' => false ),
	)
);
$quebra    = rich( array( array( 'type' => 'text', 'text' => "linha 1\nlinha 2" ) ) );
$espacos   = rich(
	array(
		array( 'type' => 'text', 'text' => 'até ' ),
		array( 'type' => 'text', 'text' => '6x', 'bold' => true ),
	)
);
$demais = rich(
	array_fill( 0, PAPELITO_MESSAGE_CONTENT_MAX_NODES + 1, array( 'type' => 'text', 'text' => 'x' ) )
);
$no_limite = rich(
	array_fill( 0, PAPELITO_MESSAGE_CONTENT_MAX_NODES, array( 'type' => 'text', 'text' => 'x' ) )
);

$maliciosos = array(
	'script'      => rich( array( array( 'type' => 'text', 'text' => '<script>alert(1)</script>' ) ) ),
	'img_onerror' => rich( array( array( 'type' => 'text', 'text' => '<img src=x onerror=alert(1)>' ) ) ),
	'negrito_html' => rich( array( array( 'type' => 'text', 'text' => '<b>oi</b>' ) ) ),
	'javascript_href' => rich( array( array( 'type' => 'text', 'text' => '<a href="javascript:alert(1)">x</a>' ) ) ),
);

$estruturais = array(
	'token'      => rich( array( array( 'type' => 'token', 'token' => 'produto.nome' ) ) ),
	'html_node'  => rich( array( array( 'type' => 'html', 'html' => '<script>' ) ) ),
	'sem_type'   => rich( array( array( 'text' => 'sem type' ) ) ),
	'texto_array' => rich( array( array( 'type' => 'text', 'text' => array( 'a' ) ) ) ),
	'associativo' => rich( array( 'primeiro' => array( 'type' => 'text', 'text' => 'x' ) ) ),
);

// --- projeção derivada, nunca aceita ----------------------------------------
$spoof = papelito_messaging_validate_message(
	new WP_REST_Request(
		array(
			'body'    => 'inocente',
			'content' => array( array( 'type' => 'text', 'text' => 'transfere R$ 5000' ) ),
		)
	)
);
$longo = papelito_messaging_validate_message(
	new WP_REST_Request(
		array(
			'content' => array_fill( 0, 50, array( 'type' => 'text', 'text' => str_repeat( 'a', 41 ) ) ),
		)
	)
);
$plano = papelito_messaging_validate_message( new WP_REST_Request( array( 'body' => 'só texto' ) ) );

// --- leitura ----------------------------------------------------------------
$lido_ok = papelito_messaging_map_message(
	array( 'id' => 1, 'sender_id' => CHAMADO_TEST_CUSTOMER_ID, 'body' => 'oi', 'body_content' => '[{"type":"text","text":"oi"}]', 'created_at' => 'x' ),
	CHAMADO_TEST_CUSTOMER_ID
);
$lido_corrompido = papelito_messaging_map_message(
	array( 'id' => 2, 'sender_id' => CHAMADO_TEST_CUSTOMER_ID, 'body' => 'oi', 'body_content' => '{corrompido', 'created_at' => 'x' ),
	CHAMADO_TEST_CUSTOMER_ID
);
$lido_legado = papelito_messaging_map_message(
	array( 'id' => 3, 'sender_id' => CHAMADO_TEST_CUSTOMER_ID, 'body' => 'linha antiga', 'body_content' => null, 'created_at' => 'x' ),
	CHAMADO_TEST_CUSTOMER_ID
);

// Sem comentários: o docblock cita a função da home justamente para dizer que não a chama.
$codigo = '';
foreach ( token_get_all( file_get_contents( __DIR__ . '/../includes/vendor_messaging.php' ) ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}
	$codigo .= is_array( $token ) ? $token[1] : $token;
}

papelito_test_report(
	array(
		'conteúdo ausente não é erro: cliente de texto plano continua funcionando' =>
			null === $vazio_null && null === $vazio_lista,
		'texto com negrito é normalizado e a flag só aparece quando verdadeira' =>
			is_array( $valido )
			&& ! array_key_exists( 'bold', $valido[0] )
			&& true === $valido[1]['bold'],
		'booleanos frouxos são coagidos e a chave falsa é omitida' =>
			is_array( $booleanos )
			&& true === ( $booleanos[0]['italic'] ?? false )
			&& ! array_key_exists( 'italic', $booleanos[1] )
			&& true === ( $booleanos[2]['bold'] ?? false )
			&& ! array_key_exists( 'italic', $booleanos[2] ),
		'a quebra de linha sobrevive' =>
			is_array( $quebra ) && str_contains( $quebra[0]['text'], "\n" ),
		'o espaço entre um trecho em negrito e a palavra seguinte é preservado' =>
			is_array( $espacos ) && 'até 6x' === papelito_messaging_content_to_plain( $espacos ),
		'script é rejeitado, não manglado' => 'papelito_message_content_html' === rich_code( $maliciosos['script'] ),
		'img com onerror é rejeitada' => 'papelito_message_content_html' === rich_code( $maliciosos['img_onerror'] ),
		'nem HTML benigno passa' => 'papelito_message_content_html' === rich_code( $maliciosos['negrito_html'] ),
		'href javascript é rejeitado' => 'papelito_message_content_html' === rich_code( $maliciosos['javascript_href'] ),
		'nó de token da home não é legal no chamado' =>
			'papelito_message_content_node_invalid' === rich_code( $estruturais['token'] ),
		'nó de tipo desconhecido é rejeitado' =>
			'papelito_message_content_node_invalid' === rich_code( $estruturais['html_node'] ),
		'nó sem type é rejeitado' =>
			'papelito_message_content_node_invalid' === rich_code( $estruturais['sem_type'] ),
		'texto que não é string é rejeitado em vez de virar "Array"' =>
			'papelito_message_content_node_invalid' === rich_code( $estruturais['texto_array'] ),
		'objeto no lugar de lista é rejeitado' =>
			'papelito_message_content_invalid' === rich_code( $estruturais['associativo'] ),
		'excesso de nós é rejeitado, e o limite exato é aceito' =>
			'papelito_message_content_too_many_nodes' === rich_code( $demais )
			&& is_array( $no_limite ),
		'a projeção plana é derivada do conteúdo, não aceita do cliente' =>
			! is_wp_error( $spoof ) && 'transfere R$ 5000' === $spoof['body'],
		'o limite de 2000 caracteres vale para a projeção' =>
			is_wp_error( $longo ) && 'papelito_message_body_too_long' === $longo->get_error_code(),
		'mensagem só de texto continua válida e sem conteúdo estruturado' =>
			! is_wp_error( $plano ) && 'só texto' === $plano['body'] && null === $plano['content'],
		'a leitura decodifica o conteúdo estruturado' =>
			array( array( 'type' => 'text', 'text' => 'oi' ) ) === $lido_ok['content'],
		'JSON corrompido degrada para texto plano em vez de quebrar' =>
			null === $lido_corrompido['content'] && 'oi' === $lido_corrompido['body'],
		'linha legada sem conteúdo continua servindo o corpo' =>
			null === $lido_legado['content'] && 'linha antiga' === $lido_legado['body'],
		'a validação do chamado não reusa o normalizador da home, que colapsa quebra de linha' =>
			! str_contains( $codigo, 'papelito_home_assets_normalize_rich_text_content' ),
	)
);
