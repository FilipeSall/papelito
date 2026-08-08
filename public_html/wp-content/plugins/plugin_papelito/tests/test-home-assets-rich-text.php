<?php
/**
 * Standalone regression test do conteudo rico das faixas da home.
 *
 * O formato persistido e uma lista plana de nos tipados; HTML nunca entra no pipeline. Este
 * teste protege a whitelist de tokens, a whitelist de formatos e o fallback para as faixas
 * gravadas antes do editor.
 *
 * Usage: php tests/test-home-assets-rich-text.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['pap_options'] = array();
$GLOBALS['pap_routes']  = array();

function add_action( $hook, $callback ) { if ( 'rest_api_init' === $hook ) { $callback(); } }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['pap_routes'][ $namespace . $route ][] = $args; }
function get_option( $key, $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['pap_options'][ $key ] ); return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( wp_strip_all_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( wp_strip_all_tags( (string) $value ) ); }
function sanitize_title( $value ) { return sanitize_key( $value ); }
function wp_strip_all_tags( $value ) { return preg_replace( '/<[^>]*>/', '', (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_parse_url( $value, $component = -1 ) { return parse_url( (string) $value, $component ); }
function rest_sanitize_boolean( $value ) { return filter_var( $value, FILTER_VALIDATE_BOOLEAN ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $capability ) { return true; }
function __return_true() { return true; }

class WP_Error {
	private $code;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}
class WP_REST_Request {
	public function get_json_params() { return array(); }
	public function get_params() { return array(); }
}
class WP_REST_Server {
	const READABLE  = 'GET';
	const EDITABLE  = 'PUT';
	const CREATABLE = 'POST';
	const DELETABLE = 'DELETE';
}

function papelito_assert( $label, $expected, $actual ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $label . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

require __DIR__ . '/../includes/home_assets.php';

// Negrito e italico sobrevivem; qualquer outra mark e descartada.
papelito_assert(
	'preserva apenas negrito e italico',
	array(
		array( 'type' => 'text', 'text' => 'Só hoje' ),
		array( 'type' => 'text', 'text' => '15% OFF', 'bold' => true, 'italic' => true ),
	),
	papelito_home_assets_normalize_rich_text_content(
		array(
			array( 'type' => 'text', 'text' => 'Só hoje' ),
			array( 'type' => 'text', 'text' => '15% OFF', 'bold' => true, 'italic' => true, 'underline' => true, 'color' => 'red' ),
		)
	)
);

// HTML enviado como texto e neutralizado pelo sanitizador, nunca interpretado.
papelito_assert(
	'neutraliza HTML no texto',
	array( array( 'type' => 'text', 'text' => 'alert(1)' ) ),
	papelito_home_assets_normalize_rich_text_content(
		array( array( 'type' => 'text', 'text' => '<script>alert(1)</script>' ) )
	)
);

// Token fora da whitelist nao entra.
papelito_assert(
	'recusa token desconhecido',
	null,
	papelito_home_assets_normalize_rich_text_content(
		array( array( 'type' => 'token', 'token' => 'promocao.inventada' ) )
	)
);

// Token de produto guarda referencia estavel, nunca um snapshot do dado.
papelito_assert(
	'guarda apenas a referencia do produto',
	array( array( 'type' => 'token', 'token' => 'produto.nome', 'params' => array( 'productId' => 123 ) ) ),
	papelito_home_assets_normalize_rich_text_content(
		array(
			array(
				'type'   => 'token',
				'token'  => 'produto.nome',
				'params' => array( 'productId' => '123', 'nome' => 'Seda King Size', 'preco' => '4.90' ),
			),
		)
	)
);

papelito_assert(
	'recusa produto sem id valido',
	array( array( 'type' => 'token', 'token' => 'produto.nome' ) ),
	papelito_home_assets_normalize_rich_text_content(
		array( array( 'type' => 'token', 'token' => 'produto.nome', 'params' => array( 'productId' => 0 ) ) )
	)
);

// Limite de nos.
$oversized = array_fill( 0, 100, array( 'type' => 'text', 'text' => 'x' ) );
papelito_assert(
	'limita a quantidade de nos',
	papelito_home_assets_rich_text_max_nodes(),
	count( papelito_home_assets_normalize_rich_text_content( $oversized ) )
);

// Faixa antiga, sem content, continua valendo.
$legacy = papelito_home_assets_normalize_promo_marquee_item(
	array( 'id' => 'default-marquee-1', 'text' => '⚡ COMPRE 3 LEVE 4', 'order' => 1, 'isActive' => true ),
	0
);
papelito_assert( 'faixa antiga mantem o texto', '⚡ COMPRE 3 LEVE 4', $legacy['text'] );
papelito_assert( 'faixa antiga nao inventa conteudo rico', null, $legacy['content'] );

// Faixa com conteudo rico deriva o texto puro a partir dos nos de texto.
$rich = papelito_home_assets_normalize_promo_marquee_item(
	array(
		'id'      => 'frete',
		'text'    => 'ignorado',
		'content' => array(
			array( 'type' => 'text', 'text' => 'Parcele em ' ),
			array( 'type' => 'token', 'token' => 'parcelamento.maximo' ),
			array( 'type' => 'text', 'text' => 'x' ),
		),
		'order'   => 1,
	),
	0
);
papelito_assert( 'texto puro deriva do conteudo rico', 'Parcele em x', $rich['text'] );
papelito_assert( 'preserva o espaco antes do token', 'Parcele em ', $rich['content'][0]['text'] );
papelito_assert( 'conteudo rico e preservado', 3, count( $rich['content'] ) );

// Beneficio com subtitulo rico.
$feature = papelito_home_assets_normalize_feature(
	array(
		'id'              => 'frete-gratis',
		'title'           => 'Frete Grátis',
		'subtitle'        => 'ignorado',
		'subtitleContent' => array(
			array( 'type' => 'text', 'text' => 'A partir de ' ),
			array( 'type' => 'token', 'token' => 'frete_gratis.minimo' ),
		),
		'iconId'          => 0,
		'iconUrl'         => '/images/icons/truck.svg',
	),
	0
);
papelito_assert( 'subtitulo rico deriva o texto puro', 'A partir de ', $feature['subtitle'] );
papelito_assert( 'subtitulo rico e preservado', 2, count( $feature['subtitleContent'] ) );

// Mensagem composta so por token nao e tratada como vazia.
$only_token = papelito_home_assets_validate_promo_marquee_payload(
	array(
		array( 'id' => 'a', 'text' => '', 'content' => array( array( 'type' => 'token', 'token' => 'promocao.nome' ) ), 'isActive' => true ),
		array( 'id' => 'b', 'text' => 'Segunda', 'isActive' => true ),
		array( 'id' => 'c', 'text' => 'Terceira', 'isActive' => true ),
	)
);
papelito_assert( 'aceita mensagem composta so por token', false, is_wp_error( $only_token ) );
papelito_assert( 'persiste o token da mensagem', 'promocao.nome', $only_token[0]['content'][0]['token'] );

// Espaco entre dois tokens sobrevive: sem ele os valores resolvidos colariam.
papelito_assert(
	'preserva o separador entre dois tokens',
	array(
		array( 'type' => 'token', 'token' => 'promocao.nome' ),
		array( 'type' => 'text', 'text' => ' ' ),
		array( 'type' => 'token', 'token' => 'promocao.desconto' ),
	),
	papelito_home_assets_normalize_rich_text_content(
		array(
			array( 'type' => 'token', 'token' => 'promocao.nome' ),
			array( 'type' => 'text', 'text' => ' ' ),
			array( 'type' => 'token', 'token' => 'promocao.desconto' ),
		)
	)
);

// O endpoint publico e o coletor de issues tambem precisam aceitar conteudo so-token, senao a
// mensagem some da vitrine (marquee) ou a barra inteira cai no default (beneficios).
papelito_assert(
	'texto vazio com conteudo rico e exibivel',
	true,
	papelito_home_assets_has_displayable_content( '', array( array( 'type' => 'token', 'token' => 'promocao.nome' ) ) )
);
papelito_assert(
	'texto vazio sem conteudo rico nao e exibivel',
	false,
	papelito_home_assets_has_displayable_content( '   ', null )
);

$GLOBALS['pap_options'][ papelito_home_assets_promo_marquee_option_name() ] = array(
	array( 'id' => 'a', 'text' => 'Primeira', 'order' => 1, 'isActive' => true ),
	array( 'id' => 'b', 'text' => 'Segunda', 'order' => 2, 'isActive' => true ),
	array( 'id' => 'c', 'text' => 'Terceira', 'order' => 3, 'isActive' => true ),
	array(
		'id'       => 'so-token',
		'text'     => '',
		'content'  => array( array( 'type' => 'token', 'token' => 'parcelamento.maximo' ) ),
		'order'    => 4,
		'isActive' => true,
	),
);
$marquee_route = $GLOBALS['pap_routes']['papelito/v1/home/promo-marquee'][0];
$public_ids    = array_map(
	static function ( array $item ): string {
		return (string) $item['id'];
	},
	$marquee_route['callback']()->data['messages']
);
papelito_assert( 'endpoint publico mantem mensagem so-token', array( 'a', 'b', 'c', 'so-token' ), $public_ids );

$features_with_token = papelito_home_assets_normalize_features(
	array(
		array(
			'id'              => 'frete-gratis',
			'title'           => 'Frete Grátis',
			'subtitle'        => '',
			'subtitleContent' => array( array( 'type' => 'token', 'token' => 'frete_gratis.minimo' ) ),
			'iconId'          => 0,
			'iconUrl'         => '/images/icons/truck.svg',
		),
	)
);
papelito_assert(
	'subtitulo so-token nao vira issue',
	array(),
	papelito_home_assets_collect_features_issues( array( $features_with_token[0] ) )
);

echo "Home assets rich text: ok\n";
