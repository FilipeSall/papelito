<?php
/**
 * Standalone regression test do token de minimo de frete gratis nos assets da home.
 *
 * Cobre a causa raiz do BUG-002: alterar os defaults nao alcanca instalacoes em que a option
 * ja existe, entao o valor antigo continua persistido e volta a vitrine assim que o override
 * do frontend deixa de casar.
 *
 * Usage: php tests/test-home-assets-free-shipping-placeholder.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['pap_options'] = array();

function add_action( $hook, $callback ) {}
function register_rest_route( $namespace, $route, $args ) {}
function get_option( $key, $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['pap_options'][ $key ] ); return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_kses_post( $value ) { return (string) $value; }
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
class WP_REST_Server { const READABLE = 'GET'; const EDITABLE = 'PUT'; }

function papelito_assert( $label, $expected, $actual ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $label . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

require __DIR__ . '/../includes/home_assets.php';

$marquee_option  = papelito_home_assets_promo_marquee_option_name();
$features_option = papelito_home_assets_features_option_name();

// Os defaults de codigo ja nascem com o token.
$default_marquee = papelito_home_assets_default_promo_marquee_items();
$default_feature = papelito_home_assets_default_features();
papelito_assert(
	'default do marquee usa o token',
	true,
	false !== strpos( $default_marquee[5]['text'], PAPELITO_HOME_ASSETS_FREE_SHIPPING_TOKEN )
);
papelito_assert(
	'default do beneficio usa o token',
	true,
	false !== strpos( $default_feature[0]['subtitle'], PAPELITO_HOME_ASSETS_FREE_SHIPPING_TOKEN )
);

// Instalacao existente com os literais do BUG-002 persistidos.
$GLOBALS['pap_options'][ $marquee_option ] = array(
	array(
		'id'       => 'default-marquee-5',
		'text'     => '🏆 A #1 DO BRASIL em papéis para enrolar',
		'order'    => 5,
		'isActive' => true,
	),
	array(
		'id'       => 'default-marquee-6',
		'text'     => '🔥 FRETE GRÁTIS acima de R$79',
		'order'    => 6,
		'isActive' => true,
	),
);
$GLOBALS['pap_options'][ $features_option ] = array(
	array(
		'id'       => 'frete-gratis',
		'title'    => 'Frete Grátis',
		'subtitle' => 'Acima de R$500',
		'iconId'   => 0,
		'iconUrl'  => '/images/icons/truck.svg',
	),
	array(
		'id'       => 'troca-facil',
		'title'    => 'Troca Fácil',
		'subtitle' => '15 dias para troca',
		'iconId'   => 0,
		'iconUrl'  => '/images/icons/refresh.svg',
	),
);

papelito_home_assets_migrate_free_shipping_placeholder();

papelito_assert(
	'converte o texto legado do marquee',
	PAPELITO_HOME_ASSETS_FREE_SHIPPING_MARQUEE_TEXT,
	$GLOBALS['pap_options'][ $marquee_option ][1]['text']
);
papelito_assert(
	'converte o subtitulo legado do beneficio',
	PAPELITO_HOME_ASSETS_FREE_SHIPPING_SUBTITLE,
	$GLOBALS['pap_options'][ $features_option ][0]['subtitle']
);
papelito_assert(
	'nao toca em mensagem sem relacao com frete',
	'🏆 A #1 DO BRASIL em papéis para enrolar',
	$GLOBALS['pap_options'][ $marquee_option ][0]['text']
);
papelito_assert(
	'nao toca em beneficio sem relacao com frete',
	'15 dias para troca',
	$GLOBALS['pap_options'][ $features_option ][1]['subtitle']
);

// Idempotencia: rodar de novo nao altera nada.
$snapshot = array( $GLOBALS['pap_options'][ $marquee_option ], $GLOBALS['pap_options'][ $features_option ] );
papelito_home_assets_migrate_free_shipping_placeholder();
papelito_assert(
	'migracao e idempotente',
	$snapshot,
	array( $GLOBALS['pap_options'][ $marquee_option ], $GLOBALS['pap_options'][ $features_option ] )
);

// Texto ja customizado pelo administrador e uma decisao dele: a migracao nao sobrescreve.
$GLOBALS['pap_options'][ $marquee_option ][1]['text']      = '🔥 FRETE GRÁTIS só hoje';
$GLOBALS['pap_options'][ $features_option ][0]['subtitle'] = 'Consulte condições';
papelito_home_assets_migrate_free_shipping_placeholder();
papelito_assert(
	'preserva texto customizado do marquee',
	'🔥 FRETE GRÁTIS só hoje',
	$GLOBALS['pap_options'][ $marquee_option ][1]['text']
);
papelito_assert(
	'preserva subtitulo customizado do beneficio',
	'Consulte condições',
	$GLOBALS['pap_options'][ $features_option ][0]['subtitle']
);

// Instalacao nova: sem option, a migracao nao cria nada.
unset( $GLOBALS['pap_options'][ $marquee_option ], $GLOBALS['pap_options'][ $features_option ] );
papelito_home_assets_migrate_free_shipping_placeholder();
papelito_assert( 'nao cria option de marquee do zero', false, isset( $GLOBALS['pap_options'][ $marquee_option ] ) );
papelito_assert( 'nao cria option de beneficios do zero', false, isset( $GLOBALS['pap_options'][ $features_option ] ) );

echo "Home assets free-shipping placeholder: ok\n";
