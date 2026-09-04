<?php

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['pap_options']            = array();
$GLOBALS['pap_routes']             = array();
$GLOBALS['pap_can_manage_options'] = true;

function add_action( string $hook, callable $callback ): void {
	if ( 'rest_api_init' === $hook ) {
		$callback();
	}
}
function register_rest_route( string $namespace, string $route, array $args ): void { $GLOBALS['pap_routes'][ $namespace . $route ][] = $args; }
function get_option( string $key, mixed $fallback = false ): mixed { return $GLOBALS['pap_options'][ $key ] ?? $fallback; }
function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['pap_options'][ $key ] = $value; return true; }
function current_user_can( string $capability ): bool { return 'manage_options' === $capability && $GLOBALS['pap_can_manage_options']; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function __return_true(): bool { return true; }

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = '',
		private array $data = array()
	) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}
class WP_REST_Response {
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
}
class WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_json_params(): array { return $this->params; }
}
class WP_REST_Server {
	const READABLE = 'GET';
	const EDITABLE = 'PUT';
}

function papelito_assert( string $label, mixed $expected, mixed $actual ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $label . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

require_once __DIR__ . '/../includes/collections_config.php';

// Instalação sem option gravada tem de funcionar sem seed: dez produtos e sem prazo.
papelito_assert(
	'defaults without any stored option',
	array(
		'newArrivals' => array( 'limit' => 10, 'expirationDays' => 0 ),
		'promotions'  => array( 'limit' => 0 ),
	),
	papelito_collections_get_config()
);

// Option corrompida cai no padrão em vez de derrubar a vitrine.
$GLOBALS['pap_options'][ PAPELITO_COLLECTIONS_NEW_ARRIVALS_LIMIT_OPTION ]      = 'dez';
$GLOBALS['pap_options'][ PAPELITO_COLLECTIONS_NEW_ARRIVALS_EXPIRATION_OPTION ] = -5;
$GLOBALS['pap_options'][ PAPELITO_COLLECTIONS_PROMOTIONS_LIMIT_OPTION ]        = 999;
papelito_assert( 'a corrupted limit falls back to the default', 10, papelito_collections_get_config()['newArrivals']['limit'] );
papelito_assert( 'a negative expiration reads as no deadline', 0, papelito_collections_get_config()['newArrivals']['expirationDays'] );
papelito_assert( 'an out-of-range promotions cap falls back to no ceiling', 0, papelito_collections_get_config()['promotions']['limit'] );

$GLOBALS['pap_options'] = array();

$saved = papelito_collections_update_config(
	array(
		'newArrivals' => array( 'limit' => 8, 'expirationDays' => 30 ),
		'promotions'  => array( 'limit' => 12 ),
	)
);
papelito_assert( 'persists the new arrivals limit', 8, $saved['newArrivals']['limit'] );
papelito_assert( 'persists the deadline in days', 30, $saved['newArrivals']['expirationDays'] );
papelito_assert( 'persists the promotions ceiling', 12, $saved['promotions']['limit'] );

papelito_assert( 'zero clears the deadline', 0, papelito_collections_update_config( array( 'newArrivals' => array( 'expirationDays' => 0 ) ) )['newArrivals']['expirationDays'] );
papelito_assert( 'a partial payload preserves the untouched limit', 8, papelito_collections_get_config()['newArrivals']['limit'] );
papelito_assert( 'zero clears the promotions ceiling', 0, papelito_collections_update_config( array( 'promotions' => array( 'limit' => 0 ) ) )['promotions']['limit'] );

foreach ( array( 0, -1, 61, 'dez', null, 1.5, '2.5', 10.25 ) as $invalid ) {
	papelito_assert(
		'rejects an invalid new arrivals limit: ' . var_export( $invalid, true ),
		'papelito_collections_invalid_new_arrivals_limit',
		papelito_collections_update_config( array( 'newArrivals' => array( 'limit' => $invalid ) ) )->get_error_code()
	);
}

// Inteiro em float ou em string continua aceito: é assim que JSON entrega 10 e "10".
papelito_assert( 'accepts an integer sent as float', 20, papelito_collections_update_config( array( 'newArrivals' => array( 'limit' => 20.0 ) ) )['newArrivals']['limit'] );
papelito_assert( 'accepts an integer sent as string', 15, papelito_collections_update_config( array( 'newArrivals' => array( 'limit' => '15' ) ) )['newArrivals']['limit'] );
papelito_assert(
	'rejects a decimal expiration',
	'papelito_collections_invalid_new_arrivals_expiration',
	papelito_collections_update_config( array( 'newArrivals' => array( 'expirationDays' => 30.5 ) ) )->get_error_code()
);

papelito_assert(
	'rejects a deadline past the ceiling',
	'papelito_collections_invalid_new_arrivals_expiration',
	papelito_collections_update_config( array( 'newArrivals' => array( 'expirationDays' => 400 ) ) )->get_error_code()
);
papelito_assert(
	'rejects a promotions ceiling past the maximum',
	'papelito_collections_invalid_promotions_limit',
	papelito_collections_update_config( array( 'promotions' => array( 'limit' => 61 ) ) )->get_error_code()
);
papelito_assert(
	'rejects an empty payload',
	'papelito_collections_invalid_payload',
	papelito_collections_update_config( array() )->get_error_code()
);
papelito_assert( 'a refused write leaves the stored limit untouched', 15, papelito_collections_get_config()['newArrivals']['limit'] );

// Rotas: leitura pública, leitura e escrita administrativas.
$public_route = $GLOBALS['pap_routes']['papelito/v1/collections-config'][0];
papelito_assert( 'public read is open', true, $public_route['permission_callback']() );
papelito_assert( 'public read answers 200', 200, $public_route['callback']()->status );

$admin_routes = $GLOBALS['pap_routes']['papelito/v1/admin/collections-config'];
$GLOBALS['pap_can_manage_options'] = false;
papelito_assert( 'non-admin is rejected', 'papelito_collections_forbidden', $admin_routes[0]['permission_callback']()->get_error_code() );

$GLOBALS['pap_can_manage_options'] = true;
$written = $admin_routes[1]['callback'](
	new WP_REST_Request(
		array(
			'newArrivals' => array( 'limit' => 20, 'expirationDays' => 45 ),
			'promotions'  => array( 'limit' => 5 ),
		)
	)
);
papelito_assert( 'the write route answers 200', 200, $written->status );
papelito_assert( 'the write route persists the limit', 20, $written->data['newArrivals']['limit'] );
papelito_assert(
	'the write route surfaces the validation error',
	'papelito_collections_invalid_new_arrivals_limit',
	$admin_routes[1]['callback']( new WP_REST_Request( array( 'newArrivals' => array( 'limit' => 0 ) ) ) )->get_error_code()
);

echo "Collections config: ok\n";
