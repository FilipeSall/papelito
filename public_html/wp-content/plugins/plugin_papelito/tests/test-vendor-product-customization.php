<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Standalone reúne classes stub das APIs externas.
/**
 * Regras puras de composição, publicação e identidade, sem banco.
 *
 * Stubs representam somente WordPress/WooCommerce e a fronteira de persistência.
 * SQL, sanitização e dispatch REST são exercitados nos testes WP-CLI.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName,Generic.Files.OneObjectStructurePerFile.MultipleFound,Universal.Files.SeparateFunctionsFromOO.Mixed -- Standalone reúne os stubs das APIs externas.
// phpcs:disable WordPress.Security.EscapeOutput -- Teste CLI não renderiza HTML.

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
require_once ABSPATH . 'wp-includes/class-wp-error.php';

/** Produto mínimo para verificar a composição sem carregar WooCommerce. */
class WC_Product {
	/** Stub tipado da API externa. */
	public function __construct(
		public int $id,
		public string $status = 'publish',
		public string $description = '<p>Canônico.</p>',
		public string $short_description = '',
		public int $parent_id = 0
	) {
		$this->id = $id;
	}
	/** Stub tipado da API externa. */
	public function get_id(): int {
		return $this->id;
	}
	/** Stub tipado da API externa. */
	public function get_status( string $context = 'view' ): string {
		return 'edit' === $context || 'view' === $context ? $this->status : '';
	}
	/** Stub tipado da API externa. */
	public function get_description( string $context = 'view' ): string {
		return 'edit' === $context || 'view' === $context ? $this->description : '';
	}
	/** Stub tipado da API externa. */
	public function get_short_description( string $context = 'view' ): string {
		return 'edit' === $context || 'view' === $context ? $this->short_description : '';
	}
	/** Stub tipado da API externa. */
	public function get_parent_id(): int {
		return $this->parent_id;
	}
	/** Stub tipado da API externa. */
	public function is_type( string $type ): bool {
		return 'variation' === $type && $this->parent_id > 0;
	}
}

/** Identidade mínima, separada dos requisitos operacionais de venda. */
class WP_User {
	/**
	 * Identificador conforme a API do WordPress.
	 *
	 * @var int
	 */
	public int $ID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- Propriedade da API WordPress.
	/** Stub tipado da API externa. */
	public function __construct( int $id, public string $role = 'seller' ) {
		$this->ID = $id;
	}
	/** Stub tipado da API externa. */
	public function exists(): bool {
		return $this->ID > 0;
	}
}

/** Stub tipado da API externa. */
function do_action( string $hook_name, mixed ...$args ): void {
	$GLOBALS['customization_hooks'][] = array( $hook_name, $args );
}
/** Stub tipado da API externa. */
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}
/** Stub tipado da API externa. */
function wc_get_product( int $id ): WC_Product|false {
	return $GLOBALS['customization_products'][ $id ] ?? false;
}
/** Stub tipado da API externa. */
function get_post_type( int $id ): string {
	return isset( $GLOBALS['customization_products'][ $id ] ) ? 'product' : '';
}
/** Stub tipado da API externa. */
function wp_strip_all_tags( string $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Implementação do próprio stub wp_strip_all_tags.
	return strip_tags( $value );
}
/** Stub tipado da API externa. */
function wp_get_current_user(): WP_User {
	return $GLOBALS['customization_user'];
}
/** Stub tipado da API externa. */
function papelito_user_is_effective_seller( mixed $user ): bool {
	return $user instanceof WP_User && 'seller' === $user->role;
}
/** Stub tipado da API externa. */
function papelito_account_is_suspended( int $id ): bool {
	return $id === $GLOBALS['customization_user']->ID && $GLOBALS['customization_suspended'];
}
/** Stub tipado da API externa. */
function papelito_account_guard_commercial( int $id ): bool|WP_Error {
	return papelito_account_is_suspended( $id ) ? new WP_Error( 'suspended', 'Suspensa', array( 'status' => 403 ) ) : true;
}
/** Stub tipado da API externa. */
function papelito_vendor_product_overrides_get_many( int $vendor_id, array $product_ids ): array|WP_Error {
	$GLOBALS['customization_repository_calls'][] = array( $vendor_id, $product_ids );
	return $GLOBALS['customization_rows'];
}

require_once dirname( __DIR__ ) . '/includes/vendor_product_customization.php';
require_once dirname( __DIR__ ) . '/includes/product_presentation.php';

$GLOBALS['customization_products']  = array(
	1 => new WC_Product( 1 ),
	2 => new WC_Product( 2, parent_id: 1 ),
);
$GLOBALS['customization_user']      = new WP_User( 45 );
$GLOBALS['customization_suspended'] = false;
$GLOBALS['customization_rows']      = array();
$checks                             = 0;

/** Falha com exit code real em vez de confiar no bootstrap do WordPress. */
/** Stub tipado da API externa. */
function papelito_customization_unit_check( string $label, mixed $expected, mixed $actual ): void {
	++$GLOBALS['checks'];
	if ( $expected !== $actual ) {
		throw new RuntimeException( $label );
	}
}

$product   = $GLOBALS['customization_products'][1];
$canonical = papelito_product_presentation_compose( $product, null, null );
papelito_customization_unit_check( 'fallback', $product->description, $canonical['description'] );
papelito_customization_unit_check( 'origem canônica', 'papelito', $canonical['description_source'] );
papelito_customization_unit_check( 'resumo herda completo', $product->description, $canonical['summary'] );
$product->short_description = '<p>Resumo.</p>';
$override                   = papelito_product_presentation_compose( $product, 45, array( 'description' => '<p>Vendor.</p>' ) );
papelito_customization_unit_check( 'override', '<p>Vendor.</p>', $override['description'] );
papelito_customization_unit_check( 'origem vendor', 'vendor', $override['description_source'] );
papelito_customization_unit_check( 'resumo separado', '<p>Resumo.</p>', $override['summary'] );
papelito_customization_unit_check( 'NULL herda', 'papelito', papelito_product_presentation_compose( $product, 45, array( 'description' => null ) )['description_source'] );
$disabled = papelito_product_presentation_compose(
	$product,
	45,
	array(
		'description'         => '<p>Guardado.</p>',
		'description_enabled' => false,
	)
);
papelito_customization_unit_check( 'texto desligado não aparece', $product->description, $disabled['description'] );
papelito_customization_unit_check( 'texto desligado é origem Papelito', 'papelito', $disabled['description_source'] );
papelito_customization_unit_check( 'igual permanece override', 'vendor', papelito_product_presentation_compose( $product, 45, array( 'description' => $product->description ) )['description_source'] );
papelito_customization_unit_check( 'variação normaliza', 1, papelito_vendor_product_customization_product( 2 )->get_id() );
$product->status = 'draft';
papelito_customization_unit_check( 'pai draft bloqueia variação', true, is_wp_error( papelito_vendor_product_customization_product( 2 ) ) );
$product->status = 'publish';
papelito_customization_unit_check( 'inexistente', true, is_wp_error( papelito_vendor_product_customization_product( 999 ) ) );
foreach ( array( ' ', '<p></p>', '&nbsp;', '&#160;', "\u{200B}" ) as $empty ) {
	papelito_customization_unit_check( 'vazio visual', false, papelito_vendor_product_customization_has_text( $empty ) );
}
papelito_customization_unit_check( 'texto válido', true, papelito_vendor_product_customization_has_text( '<p>Texto</p>' ) );
$GLOBALS['customization_user'] = new WP_User( 0 );
papelito_customization_unit_check( 'anônimo', 401, papelito_vendor_product_customization_get( 1 )->get_error_data()['status'] );
$GLOBALS['customization_user'] = new WP_User( 45, 'customer' );
papelito_customization_unit_check( 'customer', 403, papelito_vendor_product_customization_get( 1 )->get_error_data()['status'] );
$GLOBALS['customization_user']      = new WP_User( 45 );
$GLOBALS['customization_suspended'] = true;
papelito_customization_unit_check( 'suspenso lê', false, papelito_vendor_product_customization_get( 1 )['can_edit'] );
papelito_customization_unit_check( 'suspenso não grava', true, is_wp_error( papelito_vendor_product_customization_require_seller( true ) ) );
$GLOBALS['customization_rows'] = new WP_Error( 'db_failure', 'Falha', array( 'status' => 500 ) );
papelito_customization_unit_check( 'erro não vira fallback', 'db_failure', papelito_vendor_product_customization_get( 1 )->get_error_code() );
echo "{$checks} checagens standalone passaram.\n";
