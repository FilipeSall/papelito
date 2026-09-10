<?php
/** Regressão da mensagem canônica enviada ao vendor ao solicitar devolução. */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {
	// Só a montagem da mensagem é exercitada; hooks não participam.
}
function register_rest_route( ...$args ) {
	// Idem para rotas.
}
function absint( mixed $value ): int { return abs( (int) $value ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function sanitize_textarea_field( mixed $value ): string { return trim( (string) $value ); }
function wp_strip_all_tags( mixed $value ): string { return strip_tags( (string) $value ); }
function rest_sanitize_boolean( mixed $value ): bool { return ! in_array( $value, array( false, 0, '0', '', 'false', null ), true ); }
function get_user_meta( ...$args ): string { return 'Loja Exemplo'; }
function get_userdata( ...$args ) { return null; }
function get_avatar_url( ...$args ): string { return ''; }
function papelito_return_reasons() { return array( 'regret', 'defective', 'damaged', 'incorrect_item', 'incomplete_item', 'other' ); }
function papelito_return_reason_labels() {
	return array(
		'regret'          => 'Desistência da compra',
		'defective'       => 'Produto com defeito',
		'damaged'         => 'Danificado no transporte',
		'incorrect_item'  => 'Item errado',
		'incomplete_item' => 'Item incompleto',
		'other'           => 'Outro motivo',
	);
}
function papelito_return_reason_label( string $reason, string $other = '' ) {
	$labels = papelito_return_reason_labels();
	$label  = $labels[ sanitize_key( $reason ) ] ?? 'Outro motivo';
	$other  = trim( $other );
	return 'other' === sanitize_key( $reason ) && '' !== $other ? $label . ' — ' . $other : $label;
}

class WP_Error {}
class WP_REST_Request {}
class WP_REST_Response {}

require_once __DIR__ . '/../includes/vendor_messaging.php';

$message = papelito_messaging_return_support_message( 'defective' );
$body    = $message['body'];
$content = $message['content'];

$other = papelito_messaging_return_support_message( 'other', 'A caixa chegou aberta' );

$every_reason_has_label = true;
foreach ( papelito_return_reasons() as $reason ) {
	$label = papelito_messaging_return_support_message( $reason )['body'];
	if ( '' === trim( str_replace( "Olá! Gostaria de solicitar a devolução deste pedido.\nMotivo: ", '', $label ) ) ) {
		$every_reason_has_label = false;
	}
}

$assertions = array(
	'a mensagem é curta e traz o motivo escolhido' =>
		"Olá! Gostaria de solicitar a devolução deste pedido.\nMotivo: Produto com defeito" === $body,
	'não repete o número do pedido' => ! str_contains( $body, 'Pedido: #' ),
	'não repete a loja' => ! str_contains( $body, 'Loja: ' ),
	'não repete as datas da compra e da entrega' =>
		! str_contains( $body, 'Compra realizada em' ) && ! str_contains( $body, 'Entrega confirmada em' ),
	'não repete itens nem valores' => ! str_contains( $body, 'unidade(s)' ) && ! str_contains( $body, 'R$' ),
	'não expõe o marcador técnico na conversa' =>
		! str_contains( $body, papelito_messaging_return_support_marker() ),
	'o motivo nunca fica vazio' => ! preg_match( '/Motivo:\s*$/', $body ),
	'o rótulo "Motivo:" vem em negrito e o motivo em texto normal' =>
		4 === count( $content )
		&& 'Motivo: ' === $content[2]['text']
		&& true === ( $content[2]['bold'] ?? false )
		&& ! array_key_exists( 'bold', $content[3] ),
	'a projeção plana não pode divergir do conteúdo' =>
		papelito_messaging_content_to_plain( $content ) === $body,
	'"outro motivo" carrega o texto do comprador' =>
		str_contains( $other['body'], 'Outro motivo — A caixa chegou aberta' ),
	'todo motivo de devolução produz rótulo' => $every_reason_has_label,
	'o marcador legado continua existindo para a migração e o fallback' =>
		'[Papelito: solicitacao-devolucao]' === papelito_messaging_return_support_marker(),
);

$failures = 0;
foreach ( $assertions as $label => $condition ) {
	echo ( $condition ? 'PASS: ' : 'FALHOU: ' ) . $label . "\n";
	$failures += $condition ? 0 : 1;
}
exit( $failures > 0 ? 1 : 0 );
