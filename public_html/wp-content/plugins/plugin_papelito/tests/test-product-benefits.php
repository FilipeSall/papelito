<?php
/**
 * Standalone regression test for scoped product benefits.
 *
 * Cobre validação de payload, política de ícone e — o que mais importa — a
 * matriz de precedência `produto > coleção > categoria > global`. A resolução é
 * exercitada de verdade: o índice é injetado pelo transient, que é exatamente
 * o caminho que `papelito_product_benefits_index()` usa em produção, então
 * nenhum `$wpdb` precisa entrar aqui.
 *
 * Usage: php tests/test-product-benefits.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'MINUTE_IN_SECONDS', 60 );

/**
 * Valores que precisam casar entre cenários distintos: o mesmo título de item
 * ou nome de grupo é montado no índice e conferido na asserção.
 */
const PAPELITO_TEST_ICON_URL      = 'https://wp.example/uploads/icone.svg';
const PAPELITO_TEST_GROUP_DEFAULT = 'Padrão';
const PAPELITO_TEST_ITEM_SHIPPING = 'Frete Grátis';
const PAPELITO_TEST_ITEM_RETURNS  = '30 Dias';
const PAPELITO_TEST_ITEM_SEDAS    = 'Sedas A';
const PAPELITO_TEST_ITEM_PREMIUM  = 'Premium A';
const PAPELITO_TEST_PRODUCT_KEY   = '11760';

$GLOBALS['pap_options']             = array();
$GLOBALS['pap_transients']          = array();
$GLOBALS['pap_routes']              = array();
$GLOBALS['pap_attachments']         = array( 10 => PAPELITO_TEST_ICON_URL );
$GLOBALS['pap_products']            = array( 11760 => 'Seda Premium King Size', 11761 => 'Piteira Longa' );
$GLOBALS['pap_categories']          = array( 3 => array( 'id' => 3, 'name' => 'Sedas', 'slug' => 'sedas' ) );
$GLOBALS['pap_product_collections'] = array();
$GLOBALS['pap_product_category']    = array();
$GLOBALS['pap_can_manage_options']  = true;

function add_action( string $hook, callable $callback ) {
	if ( 'rest_api_init' === $hook ) {
		$callback();
	}
}
function register_rest_route( string $namespace, string $route, array $args ) {
	$GLOBALS['pap_routes'][ $namespace . $route ][] = $args;
}
function apply_filters( string $hook, mixed $value ) { return $value; }
function do_action( string $hook, mixed ...$args ): void {
	// Stub inerte: nenhum listener é exercitado aqui, só a chamada precisa existir.
}
function current_user_can( mixed $cap ) { return 'manage_options' === $cap && $GLOBALS['pap_can_manage_options']; }
function get_option( string $key, mixed $default = false ) { return $GLOBALS['pap_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, mixed $autoload = null ) { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function get_transient( string $key ) { return $GLOBALS['pap_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ) { $GLOBALS['pap_transients'][ $key ] = $value; return true; }
function current_time( string $type, mixed $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function absint( mixed $value ) { return abs( (int) $value ); }
function sanitize_key( mixed $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_title( mixed $value ) { return preg_replace( '/[^a-z0-9\-]/', '', strtolower( str_replace( ' ', '-', (string) $value ) ) ); }
function remove_accents( mixed $value ) { return (string) $value; }
function sanitize_text_field( mixed $value ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) ); }
function sanitize_textarea_field( mixed $value ) { return trim( wp_strip_all_tags( (string) $value ) ); }
function wp_strip_all_tags( mixed $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( mixed $value ) { return json_encode( $value ); }
function esc_url_raw( mixed $value ) { return (string) $value; }
function wp_http_validate_url( mixed $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_URL ); }
function wp_parse_url( mixed $value, int $component = -1 ) { return parse_url( $value, $component ); }
function wp_get_attachment_url( mixed $id ) { return $GLOBALS['pap_attachments'][ $id ] ?? false; }
function get_post_mime_type( mixed $id ) { return 10 === (int) $id ? 'image/svg+xml' : false; }
function get_the_title( mixed $id ) { return $GLOBALS['pap_products'][ (int) $id ] ?? ''; }
function rest_sanitize_boolean( mixed $value ) { return ! in_array( $value, array( false, 0, '0', 'false', '', null ), true ); }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }

// Domínio vizinho, stubado: a taxonomia tem testes próprios.
function papelito_curated_collections(): array { return array( 'premium', 'kits' ); }
function papelito_taxonomy_is_product( mixed $id ) { return isset( $GLOBALS['pap_products'][ (int) $id ] ); }
function papelito_category_get( mixed $id ) { return $GLOBALS['pap_categories'][ (int) $id ] ?? null; }
function papelito_product_get_collections( mixed $id ) { return $GLOBALS['pap_product_collections'][ (int) $id ] ?? array(); }
function papelito_product_get_category( mixed $id ) {
	$category_id = $GLOBALS['pap_product_category'][ (int) $id ] ?? 0;
	return $category_id > 0 ? papelito_category_get( $category_id ) : null;
}

class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;

	public function __construct( string $code = '', string $message = '', mixed $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

class WP_REST_Response {
	public mixed $data;
	public int $status;

	public function __construct( mixed $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class WP_REST_Request implements ArrayAccess {
	private array $params;
	private array $attributes;

	public function __construct( array $params = array(), array $attributes = array() ) {
		$this->params     = $params;
		$this->attributes = $attributes;
	}
	public function get_json_params(): array { return $this->params; }
	public function offsetExists( mixed $offset ): bool { return isset( $this->attributes[ $offset ] ); }
	public function offsetGet( mixed $offset ): mixed { return $this->attributes[ $offset ] ?? null; }
	public function offsetSet( mixed $offset, mixed $value ): void { $this->attributes[ $offset ] = $value; }
	public function offsetUnset( mixed $offset ): void { unset( $this->attributes[ $offset ] ); }
}

class WP_REST_Server {
	const READABLE   = 'GET';
	const CREATABLE  = 'POST';
	const EDITABLE   = 'POST, PUT, PATCH';
	const DELETABLE  = 'DELETE';
}

require_once __DIR__ . '/../includes/home_assets.php';
require_once __DIR__ . '/../includes/product_benefits.php';
require_once __DIR__ . '/../includes/product_benefits_rest.php';

$failures = 0;
function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
	} else {
		$failures++;
		echo "  FAIL: {$label} - expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

/**
 * Injeta um índice de benefícios pelo mesmo transient que a produção usa.
 *
 * @param array<int, array<string,mixed>> $groups Grupos, indexados por id.
 * @return void
 */
function papelito_test_set_index( array $groups ): void {
	$targets   = array();
	$global_id = 0;

	foreach ( $groups as $id => $group ) {
		if ( ! empty( $group['isGlobal'] ) ) {
			$global_id = $id;
		}

		foreach ( $group['targets'] as $target ) {
			$targets[ $target['type'] . ':' . $target['key'] ] = $id;
		}
	}

	$key                          = 'papelito_benefits_index_v' . papelito_product_benefits_version();
	$GLOBALS['pap_transients'][ $key ] = array(
		'groups'  => $groups,
		'targets' => $targets,
		'global'  => $global_id,
	);
}

/**
 * Atalho para montar um grupo de teste.
 *
 * @param int                             $id       Id.
 * @param string                          $name     Nome.
 * @param array<int, array<string,mixed>> $targets  Alvos.
 * @param string[]                        $titles   Títulos dos itens ativos.
 * @param bool                            $active   Grupo ativo.
 * @param bool                            $global   Grupo global.
 * @return array<string,mixed>
 */
function papelito_test_group( int $id, string $name, array $targets, array $titles, bool $active = true, bool $global = false ): array {
	$items = array();

	foreach ( array_values( $titles ) as $index => $title ) {
		$items[] = array(
			'id'                 => ( $id * 100 ) + $index,
			'iconType'           => 'emoji',
			'iconEmoji'          => '⭐',
			'iconAttachmentId'   => 0,
			'iconUrl'            => '',
			'title'              => ltrim( $title, '!' ),
			'description'        => 'texto',
			'descriptionContent' => null,
			'sortOrder'          => $index,
			// Título prefixado com "!" nasce inativo.
			'isActive'           => 0 !== strncmp( $title, '!', 1 ),
		);
	}

	return array(
		'id'       => $id,
		'name'     => $name,
		'isGlobal' => $global,
		'isActive' => $active,
		'items'    => $items,
		'targets'  => $targets,
	);
}

/**
 * Títulos dos itens resolvidos, na ordem.
 *
 * @param array<string,mixed> $resolved Resultado da resolução.
 * @return string[]
 */
function papelito_test_titles( array $resolved ): array {
	return array_map(
		static function ( $item ) {
			return $item['title'];
		},
		$resolved['items']
	);
}

echo "Benefícios: seed padrão preserva o bloco atual da PDP\n";

$defaults = papelito_product_benefits_default_items();
papelito_assert( 'seed tem três itens', 3, count( $defaults ) );
papelito_assert( 'primeiro é o frete grátis', PAPELITO_TEST_ITEM_SHIPPING, $defaults[0]['title'] );
papelito_assert( 'segundo é a troca', PAPELITO_TEST_ITEM_RETURNS, $defaults[1]['title'] );
papelito_assert( 'terceiro é o pagamento', 'Pagamento', $defaults[2]['title'] );
papelito_assert( 'ícones são os emojis atuais', array( '🚚', '↩️', '🔒' ), array_column( $defaults, 'iconEmoji' ) );
papelito_assert(
	'frete grátis usa o token, não o valor',
	'frete_gratis.minimo',
	$defaults[0]['descriptionContent'][1]['token']
);
papelito_assert(
	'frete grátis degrada para o texto de hoje quando o token não resolve',
	'Com cupom',
	$defaults[0]['description']
);
papelito_assert(
	'nenhum valor em reais escrito à mão no seed',
	false,
	false !== strpos( wp_json_encode( $defaults ), 'R$' )
);

echo "\nBenefícios: política de ícone\n";

papelito_assert( 'emoji simples passa', '🚚', papelito_benefit_normalize_emoji( '🚚' ) );
papelito_assert( 'emoji composto passa', '↩️', papelito_benefit_normalize_emoji( '↩️' ) );
papelito_assert( 'script é recusado inteiro, não só as tags', '', papelito_benefit_normalize_emoji( '<script>alert(1)</script>' ) );
papelito_assert( 'palavra é recusada', '', papelito_benefit_normalize_emoji( 'frete' ) );
papelito_assert( 'entidade html é recusada', '', papelito_benefit_normalize_emoji( '&lt;' ) );
papelito_assert( 'aspas são recusadas', '', papelito_benefit_normalize_emoji( '"onload=x' ) );
papelito_assert( 'texto longo é recusado', '', papelito_benefit_normalize_emoji( 'texto muito longo demais' ) );
papelito_assert( 'caminho interno passa', '/images/icons/truck.svg', papelito_benefit_normalize_icon_path( '/images/icons/truck.svg' ) );
papelito_assert( 'url absoluta é recusada', '', papelito_benefit_normalize_icon_path( 'https://malicioso.example/x.svg' ) );
papelito_assert( 'url protocol-relative é recusada', '', papelito_benefit_normalize_icon_path( '//malicioso.example/x.svg' ) );
papelito_assert( 'caminho sem extensão svg é recusado', '', papelito_benefit_normalize_icon_path( '/images/icons/truck' ) );
papelito_assert( 'caminho longo não é truncado silenciosamente', '', papelito_benefit_normalize_icon_path( '/' . str_repeat( 'a', 252 ) . '.svg' ) );
papelito_assert( 'attachment svg resolve', PAPELITO_TEST_ICON_URL, papelito_benefit_resolve_svg_url( 10, '' ) );
papelito_assert( 'attachment de outro mime não resolve', '', papelito_benefit_resolve_svg_url( 11, '' ) );

echo "\nBenefícios: validação do payload\n";

$valid = papelito_benefit_validate_group_payload(
	array(
		'name'  => 'Premium',
		'items' => array(
			array( 'iconType' => 'emoji', 'iconEmoji' => '🌱', 'title' => 'Material sustentável', 'description' => 'Produção responsável' ),
			array( 'iconType' => 'svg', 'iconAttachmentId' => 10, 'title' => 'Envio rápido', 'description' => 'Postagem em 24h' ),
		),
		'targets' => array( 'collections' => array( 'premium' ) ),
	)
);
papelito_assert( 'payload válido não é erro', false, is_wp_error( $valid ) );
papelito_assert( 'aceita dois itens, não exatamente três', 2, count( $valid['items'] ) );
papelito_assert( 'sort_order vem da posição', array( 0, 1 ), array_column( $valid['items'], 'sortOrder' ) );
papelito_assert( 'svg resolveu a url do attachment', PAPELITO_TEST_ICON_URL, $valid['items'][1]['iconUrl'] );
papelito_assert( 'alvo de coleção normalizado', array( array( 'type' => 'collection', 'key' => 'premium' ) ), $valid['targets'] );

$no_name = papelito_benefit_validate_group_payload( array( 'name' => '', 'items' => array() ) );
papelito_assert( 'nome vazio é recusado', 'papelito_benefit_missing_name', $no_name->get_error_code() );

$bad_icon = papelito_benefit_validate_group_payload(
	array( 'name' => 'X', 'items' => array( array( 'iconType' => 'html', 'title' => 'A' ) ) )
);
papelito_assert( 'tipo de ícone inválido é recusado', 'papelito_benefit_invalid_icon_type', $bad_icon->get_error_code() );

$injected = papelito_benefit_validate_group_payload(
	array( 'name' => 'X', 'items' => array( array( 'iconType' => 'emoji', 'iconEmoji' => '<img src=x onerror=1>', 'title' => 'A' ) ) )
);
papelito_assert( 'emoji com markup é recusado', 'papelito_benefit_invalid_emoji', $injected->get_error_code() );

$long_title = papelito_benefit_validate_group_payload(
	array( 'name' => 'X', 'items' => array( array( 'iconType' => 'emoji', 'iconEmoji' => '⭐', 'title' => str_repeat( 'a', 49 ) ) ) )
);
papelito_assert( 'título acima do limite é recusado', 'papelito_benefit_title_too_long', $long_title->get_error_code() );

$unknown_collection = papelito_benefit_validate_group_payload(
	array( 'name' => 'X', 'items' => array(), 'targets' => array( 'collections' => array( 'inexistente' ) ) )
);
papelito_assert( 'coleção desconhecida é recusada', 'papelito_benefit_unknown_collection', $unknown_collection->get_error_code() );

$unknown_product = papelito_benefit_validate_group_payload(
	array( 'name' => 'X', 'items' => array(), 'targets' => array( 'products' => array( 99999 ) ) )
);
papelito_assert( 'produto inexistente é recusado', 'papelito_benefit_unknown_product', $unknown_product->get_error_code() );

$global_payload = papelito_benefit_validate_group_payload(
	array( 'name' => PAPELITO_TEST_GROUP_DEFAULT, 'items' => array(), 'targets' => array( 'collections' => array( 'premium' ) ) ),
	true
);
papelito_assert( 'global descarta alvos', array(), $global_payload['targets'] );

$rich = papelito_benefit_validate_group_payload(
	array(
		'name'  => 'X',
		'items' => array(
			array(
				'iconType'           => 'emoji',
				'iconEmoji'          => '🚚',
				'title'              => 'Frete',
				'description'        => 'Com cupom',
				'descriptionContent' => array(
					array( 'type' => 'text', 'text' => 'A partir de ' ),
					array( 'type' => 'token', 'token' => 'frete_gratis.minimo' ),
					array( 'type' => 'token', 'token' => 'token.inventado' ),
				),
			),
		),
	)
);
papelito_assert( 'token conhecido sobrevive', 'frete_gratis.minimo', $rich['items'][0]['descriptionContent'][1]['token'] );
papelito_assert( 'token fora da whitelist é descartado', 2, count( $rich['items'][0]['descriptionContent'] ) );

echo "\nBenefícios: precedência produto > coleção > categoria > global\n";

$GLOBALS['pap_product_collections'][11760] = array( 'premium' );
$GLOBALS['pap_product_category'][11760]    = 3;

papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING, PAPELITO_TEST_ITEM_RETURNS, 'Pagamento' ), true, true ),
	)
);
$resolved = papelito_product_benefits_resolve( 11760 );
papelito_assert( 'sem config específica cai no global', 'global', $resolved['source'] );
papelito_assert( 'global entrega os três itens', array( PAPELITO_TEST_ITEM_SHIPPING, PAPELITO_TEST_ITEM_RETURNS, 'Pagamento' ), papelito_test_titles( $resolved ) );

papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING ), true, true ),
		3 => papelito_test_group( 3, 'Sedas', array( array( 'type' => 'category', 'key' => '3' ) ), array( PAPELITO_TEST_ITEM_SEDAS, 'Sedas B' ) ),
	)
);
$resolved = papelito_product_benefits_resolve( 11760 );
papelito_assert( 'categoria configurada vence o global', 'category', $resolved['source'] );
papelito_assert( 'categoria entrega seus itens', array( PAPELITO_TEST_ITEM_SEDAS, 'Sedas B' ), papelito_test_titles( $resolved ) );

papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING ), true, true ),
		3 => papelito_test_group( 3, 'Sedas', array( array( 'type' => 'category', 'key' => '3' ) ), array( PAPELITO_TEST_ITEM_SEDAS ) ),
		7 => papelito_test_group( 7, 'Premium', array( array( 'type' => 'collection', 'key' => 'premium' ) ), array( PAPELITO_TEST_ITEM_PREMIUM ) ),
	)
);
$resolved = papelito_product_benefits_resolve( 11760 );
papelito_assert( 'coleção vence categoria', 'collection', $resolved['source'] );
papelito_assert( 'coleção entrega seus itens', array( PAPELITO_TEST_ITEM_PREMIUM ), papelito_test_titles( $resolved ) );

papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING ), true, true ),
		3 => papelito_test_group( 3, 'Sedas', array( array( 'type' => 'category', 'key' => '3' ) ), array( PAPELITO_TEST_ITEM_SEDAS ) ),
		7 => papelito_test_group( 7, 'Premium', array( array( 'type' => 'collection', 'key' => 'premium' ) ), array( PAPELITO_TEST_ITEM_PREMIUM ) ),
		9 => papelito_test_group( 9, 'Só este produto', array( array( 'type' => 'product', 'key' => PAPELITO_TEST_PRODUCT_KEY ) ), array( 'Exclusivo' ) ),
	)
);
$resolved = papelito_product_benefits_resolve( 11760 );
papelito_assert( 'produto vence tudo', 'product', $resolved['source'] );
papelito_assert( 'produto entrega seus itens', array( 'Exclusivo' ), papelito_test_titles( $resolved ) );
papelito_assert( 'resolução é estável ao repetir', 'product', papelito_product_benefits_resolve( 11760 )['source'] );

$GLOBALS['pap_product_collections'][11760] = array( 'kits', 'premium' );
papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING ), true, true ),
		5 => papelito_test_group( 5, 'Kits', array( array( 'type' => 'collection', 'key' => 'kits' ) ), array( 'Kits A' ) ),
		7 => papelito_test_group( 7, 'Premium', array( array( 'type' => 'collection', 'key' => 'premium' ) ), array( PAPELITO_TEST_ITEM_PREMIUM ) ),
	)
);
$resolved = papelito_product_benefits_resolve( 11760 );
papelito_assert(
	'duas coleções: vence a ordem curada (premium antes de kits)',
	array( PAPELITO_TEST_ITEM_PREMIUM ),
	papelito_test_titles( $resolved )
);

$GLOBALS['pap_product_collections'][11760] = array( 'premium' );
papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING ), true, true ),
		3 => papelito_test_group( 3, 'Sedas', array( array( 'type' => 'category', 'key' => '3' ) ), array( PAPELITO_TEST_ITEM_SEDAS ) ),
		7 => papelito_test_group( 7, 'Premium', array( array( 'type' => 'collection', 'key' => 'premium' ) ), array( PAPELITO_TEST_ITEM_PREMIUM ), false ),
	)
);
$resolved = papelito_product_benefits_resolve( 11760 );
papelito_assert( 'grupo inativo é ignorado e cai no próximo nível', 'category', $resolved['source'] );
papelito_assert( 'e entrega os itens do nível seguinte', array( PAPELITO_TEST_ITEM_SEDAS ), papelito_test_titles( $resolved ) );

echo "\nBenefícios: itens inativos, ordem e ausência de fallback\n";

papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING ), true, true ),
		7 => papelito_test_group( 7, 'Premium', array( array( 'type' => 'collection', 'key' => 'premium' ) ), array( 'Visível', '!Escondido', 'Também visível' ) ),
	)
);
$resolved = papelito_product_benefits_resolve( 11760 );
papelito_assert( 'item inativo não sai na vitrine', array( 'Visível', 'Também visível' ), papelito_test_titles( $resolved ) );

papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING ), true, true ),
		7 => papelito_test_group( 7, 'Premium', array( array( 'type' => 'collection', 'key' => 'premium' ) ), array( '!Tudo desligado' ) ),
	)
);
$resolved = papelito_product_benefits_resolve( 11760 );
papelito_assert( 'grupo vencedor sem item ativo NÃO cai para o global', 'collection', $resolved['source'] );
papelito_assert( 'e entrega lista vazia', array(), $resolved['items'] );

$many = array_map(
	static function ( $n ) {
		return 'Item ' . $n;
	},
	range( 1, 5 )
);
papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), $many, true, true ),
	)
);
papelito_assert( 'quantidade dinâmica: cinco itens', 5, count( papelito_product_benefits_resolve( 11761 )['items'] ) );

papelito_test_set_index( array( 1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( 'A' ), false, true ) ) );
papelito_assert( 'sem global ativo o bloco some', 'none', papelito_product_benefits_resolve( 11761 )['source'] );
papelito_assert( 'produto inválido devolve vazio', 'none', papelito_product_benefits_resolve( 0 )['source'] );

echo "\nBenefícios: conflito de alvo e avisos\n";

papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( 'A' ), true, true ),
		7 => papelito_test_group( 7, 'Premium', array( array( 'type' => 'product', 'key' => PAPELITO_TEST_PRODUCT_KEY ) ), array( 'A' ) ),
	)
);
$conflict = papelito_benefit_find_target_conflict( 9, array( array( 'type' => 'product', 'key' => PAPELITO_TEST_PRODUCT_KEY ) ) );
papelito_assert( 'alvo de outro grupo é recusado', 'papelito_benefit_target_taken', $conflict->get_error_code() );
papelito_assert( 'a mensagem diz qual grupo detém o alvo', true, false !== strpos( $conflict->get_error_message(), 'Premium' ) );
papelito_assert( 'a mensagem nomeia o produto', true, false !== strpos( $conflict->get_error_message(), 'Seda Premium King Size' ) );
papelito_assert(
	'o mesmo grupo pode regravar seus próprios alvos',
	true,
	papelito_benefit_find_target_conflict( 7, array( array( 'type' => 'product', 'key' => PAPELITO_TEST_PRODUCT_KEY ) ) )
);

$issues = papelito_benefit_collect_group_issues(
	papelito_benefit_group_admin_shape( papelito_test_group( 4, 'Órfã', array(), array( 'A' ) ) )
);
papelito_assert( 'grupo ativo sem alvo vira aviso', 1, count( $issues ) );

$issues = papelito_benefit_collect_group_issues(
	papelito_benefit_group_admin_shape(
		papelito_test_group( 4, 'Vazia', array( array( 'type' => 'collection', 'key' => 'kits' ) ), array( '!A' ) )
	)
);
papelito_assert( 'grupo sem item ativo vira aviso', 1, count( $issues ) );

echo "\nBenefícios: payload público\n";

papelito_test_set_index(
	array(
		1 => papelito_test_group( 1, PAPELITO_TEST_GROUP_DEFAULT, array(), array( PAPELITO_TEST_ITEM_SHIPPING, '!Oculto' ), true, true ),
	)
);
$payload = papelito_benefits_public_payload( 11761 );
papelito_assert( 'payload público informa a origem', 'global', $payload['source'] );
papelito_assert( 'payload público filtra inativo', 1, count( $payload['items'] ) );
papelito_assert(
	'payload público não vaza sortOrder nem isActive',
	array( 'id', 'iconType', 'iconEmoji', 'iconUrl', 'title', 'description', 'descriptionContent' ),
	array_keys( $payload['items'][0] )
);
$payload = papelito_benefits_public_payload( 99999 );
papelito_assert( 'payload público de id que não é produto é vazio', 'none', $payload['source'] );
papelito_assert( 'e não entrega o grupo global', 0, count( $payload['items'] ) );

echo "\n";

if ( $failures > 0 ) {
	echo "FAILURES: {$failures}\n";
	exit( 1 );
}

echo "All product benefits assertions passed.\n";
