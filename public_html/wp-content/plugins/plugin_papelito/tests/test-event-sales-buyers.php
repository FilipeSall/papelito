<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public string $code;
	public array $data;

	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

const EVENT_BUYER_TEST_CNPJ  = '11222333000181';
const EVENT_BUYER_TEST_EMAIL = 'loja@exemplo.com.br';
const EVENT_BUYER_TEST_PHONE = '(11) 98765-4321';
const EVENT_BUYER_TEST_NAME  = 'Maria da Silva';

$GLOBALS['buyer_lookup'] = array();

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function is_email( mixed $value ): bool { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function papelito_normalize_cnpj( string $raw ): string { return preg_replace( '/\D+/', '', $raw ); }
function papelito_validate_cnpj( string $raw ): bool { return EVENT_BUYER_TEST_CNPJ === papelito_normalize_cnpj( $raw ); }
function papelito_normalize_email( string $email ): string { return strtolower( trim( $email ) ); }
function papelito_auth_normalize_phone( string $phone ): string { return preg_replace( '/\D+/', '', $phone ); }
function papelito_cnpj_lookup( string $raw_cnpj, bool $include_evidence = false ): array { return $GLOBALS['buyer_lookup']; }
function papelito_company_find_by_cnpj( string $raw_cnpj ): ?array { return null; }

require_once dirname( __DIR__ ) . '/includes/event_sales_buyers.php';

$failures = 0;

function event_buyer_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

function event_buyer_error( mixed $result, string $code, int $status ): bool {
	return $result instanceof WP_Error && $code === $result->get_error_code() && $status === ( $result->get_error_data()['status'] ?? 0 );
}

function test_lookup_maps_statuses(): void {
	$cases = array(
		'inactive'             => array( 'papelito_event_cnpj_inactive', 422 ),
		'not_found'            => array( 'papelito_event_cnpj_not_found', 404 ),
		'unavailable'          => array( 'papelito_event_cnpj_unavailable', 503 ),
		'provider_unsupported' => array( 'papelito_event_cnpj_unavailable', 503 ),
		'invalid'              => array( 'papelito_event_cnpj_invalid', 400 ),
	);

	foreach ( $cases as $status => $expected ) {
		$GLOBALS['buyer_lookup'] = array( 'status' => $status );
		event_buyer_assert( "status {$status} vira {$expected[0]}", event_buyer_error( papelito_event_sales_lookup_buyer( EVENT_BUYER_TEST_CNPJ ), $expected[0], $expected[1] ) );
	}

	event_buyer_assert( 'dígito errado nem consulta', event_buyer_error( papelito_event_sales_lookup_buyer( '11222333000180' ), 'papelito_event_cnpj_invalid', 400 ) );

	$GLOBALS['buyer_lookup'] = array(
		'status'         => 'active',
		'legal_name'     => 'Loja Exemplo LTDA',
		'trade_name'     => null,
		'fiscal_address' => array( 'cep' => '01001000', 'state' => 'SP', 'city' => 'São Paulo', 'street' => 'Praça da Sé' ),
	);
	$buyer                   = papelito_event_sales_lookup_buyer( '11.222.333/0001-81' );
	event_buyer_assert( 'CNPJ ativo devolve o CNPJ em dígitos', EVENT_BUYER_TEST_CNPJ === $buyer['cnpj'] );
	event_buyer_assert( 'sem nome fantasia usa a razão social', 'Loja Exemplo LTDA' === $buyer['tradeName'] );
	event_buyer_assert( 'endereço vem sem número e bairro', '' === $buyer['address']['number'] && '' === $buyer['address']['neighborhood'] );
	event_buyer_assert( 'cidade vem da Receita', 'São Paulo' === $buyer['address']['city'] );
	event_buyer_assert( 'empresa nova não tem id', null === $buyer['companyId'] );
}

function test_upsert_validates_input(): void {
	$valid = array(
		'cnpj'  => EVENT_BUYER_TEST_CNPJ,
		'name'  => EVENT_BUYER_TEST_NAME,
		'phone' => EVENT_BUYER_TEST_PHONE,
		'email' => EVENT_BUYER_TEST_EMAIL,
	);

	$normalized = papelito_event_sales_validate_buyer_input( $valid );
	event_buyer_assert( 'entrada válida normaliza o telefone', '11987654321' === $normalized['phone'] );

	event_buyer_assert( 'CNPJ inválido', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'cnpj' => '123' ) ) ), 'papelito_event_cnpj_invalid', 400 ) );
	event_buyer_assert( 'nome curto', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'name' => 'M' ) ) ), 'papelito_event_buyer_name', 400 ) );
	event_buyer_assert( 'WhatsApp com 9 dígitos', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'phone' => '987654321' ) ) ), 'papelito_event_buyer_phone', 400 ) );
	event_buyer_assert( 'e-mail inválido', event_buyer_error( papelito_event_sales_validate_buyer_input( array_merge( $valid, array( 'email' => 'loja@' ) ) ), 'papelito_event_buyer_email', 400 ) );
	event_buyer_assert( 'nome separa primeiro e último', array( 'Maria', 'da Silva' ) === papelito_event_sales_split_name( EVENT_BUYER_TEST_NAME ) );
}

test_lookup_maps_statuses();
test_upsert_validates_input();

if ( $failures > 0 ) {
	echo "{$failures} falha(s)\n";
	exit( 1 );
}

echo "OK\n";
