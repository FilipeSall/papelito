<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private string $code;
	private string $message;
	private array $data;

	/**
	 * Cria um fixture tipado de erro do WordPress.
	 *
	 * @param string              $code Código do erro.
	 * @param string              $message Mensagem do erro.
	 * @param array<string,mixed> $data Dados do erro.
	 */
	public function __construct( string $code = '', string $message = '', array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/**
	 * Retorna o código do erro fixture.
	 *
	 * @return string Código do erro.
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * Retorna a mensagem do erro fixture.
	 *
	 * @return string Mensagem do erro.
	 */
	public function get_error_message(): string {
		return $this->message;
	}

	/**
	 * Retorna os dados do erro fixture.
	 *
	 * @return array<string,mixed> Dados do erro.
	 */
	public function get_error_data(): array {
		return $this->data;
	}
}

/** Identifica um fixture de erro do WordPress. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

/** Sanitiza uma string sintética do provider. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }

/** Sanitiza uma chave sintética do provider. */
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }

/** Converte um valor sintético em inteiro não negativo. */
function absint( mixed $value ): int { return abs( (int) $value ); }

/** Codifica um payload sintético do WordPress. */
function wp_json_encode( mixed $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }

/** Devolve a chave HMAC estável somente para o teste local. */
function wp_salt( string $scheme = 'auth' ): string { return BRASPRESS_OPTION_TEST_SALT . $scheme; }

/** Devolve o instante sintético usado como `quoted_at` do envelope. */
function current_time( mixed $type = '', mixed $gmt = false ): string { return BRASPRESS_OPTION_TEST_QUOTED_AT; }

/** Executa a action de observabilidade sem instalar um coletor de produção. */
function do_action( mixed ...$args ): bool { return true; }

/** Stub de registro de action não relacionada ao seam testado. */
function add_action( mixed ...$args ): bool { return true; }

/** Stub de registro de rota não relacionada ao seam testado. */
function register_rest_route( mixed ...$args ): bool { return true; }

/** Lê o status de uma resposta HTTP sintética. */
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['status'] ?? 0 ); }

/** Lê o corpo de uma resposta HTTP sintética. */
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }

/** Lê uma cotação sintética do transient em memória. */
function get_transient( string $key ): mixed { return $GLOBALS['braspress_option_cache'][ $key ] ?? false; }

/** Persiste uma cotação sintética no transient em memória. */
function set_transient( string $key, mixed $value, int $expiration ): bool {
	$GLOBALS['braspress_option_cache'][ $key ] = $value;

	return true;
}

/** Executa somente o transporte falso configurado pela fixture. */
function wp_remote_request( string $url, array $args ): mixed {
	$GLOBALS['braspress_option_http_calls']++;

	return $GLOBALS['braspress_option_http_response'];
}

/** Confirma que a configuração sintética está completa. */
function papelito_vendor_integration_config_complete( array $config ): bool { return ! empty( $config ); }

/** Normaliza um documento sintético de fixture. */
function papelito_vendor_integration_normalize_document( mixed $value ): string { return (string) preg_replace( '/\D+/', '', (string) $value ); }

/** Normaliza um CEP sintético de fixture. */
function papelito_vendor_integration_normalize_cep( mixed $value ): string { return (string) preg_replace( '/\D+/', '', (string) $value ); }

/** Registra uma transição de estado sintética da integração. */
function papelito_vendor_integration_set_braspress_operational_state( int $vendor_id, string $status, string $error_category = '' ): void {}

require_once dirname( __DIR__ ) . '/includes/shipping.php';
require_once dirname( __DIR__ ) . '/includes/shipping_providers.php';
require_once dirname( __DIR__ ) . '/includes/braspress.php';
require_once dirname( __DIR__ ) . '/includes/packaging.php';

const PAPELITO_VENDOR_INTEGRATION_ACTIVE  = 'active';
const PAPELITO_VENDOR_INTEGRATION_INVALID = 'invalid_credentials';
const PAPELITO_VENDOR_INTEGRATION_BLOCKED = 'provider_blocked';
const BRASPRESS_OPTION_TEST_SALT              = 'salt-de-opcao-sintetico';
const BRASPRESS_OPTION_TEST_VENDOR_ID         = 3110;
const BRASPRESS_OPTION_TEST_OTHER_VENDOR_ID   = 3111;
const BRASPRESS_OPTION_TEST_INTEGRATION_ID    = 44;
const BRASPRESS_OPTION_TEST_CONFIGURATION_VERSION = 3;
const BRASPRESS_OPTION_TEST_USERNAME          = 'usuario-de-opcao-sintetico';
const BRASPRESS_OPTION_TEST_PASSWORD          = 'senha-de-opcao-sintetica';
const BRASPRESS_OPTION_TEST_SENDER_CNPJ       = '12345678000195';
const BRASPRESS_OPTION_TEST_RECIPIENT_CNPJ    = '12345678000199';
const BRASPRESS_OPTION_TEST_DESTINATION_CEP   = '22041001';
const BRASPRESS_OPTION_TEST_ORIGIN_CEP        = '01310930';
const BRASPRESS_OPTION_TEST_MERCHANDISE_CENTS = 48900;
const BRASPRESS_OPTION_TEST_QUOTE_PRICE       = 22.35;
const BRASPRESS_OPTION_TEST_CHANGED_PRICE     = 24.9;
const BRASPRESS_OPTION_TEST_QUOTE_DAYS        = 4;
const BRASPRESS_OPTION_TEST_CHANGED_DAYS      = 6;
const BRASPRESS_OPTION_TEST_FIRST_QUOTE_ID    = '90881271';
const BRASPRESS_OPTION_TEST_SECOND_QUOTE_ID   = '90884319';
const BRASPRESS_OPTION_TEST_EXPECTED_CODE     = 'rodoviario';
const BRASPRESS_OPTION_TEST_EXPECTED_KEY      = 'braspress:rodoviario';
const BRASPRESS_OPTION_TEST_QUOTED_AT         = '2026-09-17 12:00:00';
const BRASPRESS_OPTION_TEST_QUOTE_UTC         = '2026-09-17 15:00:00.000000';
const BRASPRESS_OPTION_TEST_LATER_UTC         = '2026-09-17 15:20:00.000000';

$GLOBALS['braspress_option_cache']         = array();
$GLOBALS['braspress_option_http_calls']    = 0;
$GLOBALS['braspress_option_http_response'] = array();
$failures                                  = 0;

/** Confere um comportamento do código estável da opção Braspress. */
function braspress_option_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

/** Monta uma integração resolvida com credenciais exclusivamente sintéticas. */
function braspress_option_fixture_integration( int $vendor_id = BRASPRESS_OPTION_TEST_VENDOR_ID, string $modal = 'R' ): array {
	return array(
		'id'                    => BRASPRESS_OPTION_TEST_INTEGRATION_ID,
		'vendor_id'             => $vendor_id,
		'configuration_version' => BRASPRESS_OPTION_TEST_CONFIGURATION_VERSION,
		'credentials'           => array(
			'username' => BRASPRESS_OPTION_TEST_USERNAME,
			'password' => BRASPRESS_OPTION_TEST_PASSWORD,
		),
		'config'                => array(
			'sender_cnpj'           => BRASPRESS_OPTION_TEST_SENDER_CNPJ,
			'origin_cep'            => BRASPRESS_OPTION_TEST_ORIGIN_CEP,
			'modal'                 => $modal,
			'freight_type'          => '1',
			'tracking_tomador_cnpj' => BRASPRESS_OPTION_TEST_SENDER_CNPJ,
		),
	);
}

/**
 * Monta o pacote físico aprovado de um vendor.
 *
 * O `physical_hash` sai do snapshot logístico real, que já cobre o vendor: é ele
 * que mantém a seleção de um vendor fora do alcance de outro depois que a chave
 * da opção deixou de carregar o identificador da cotação.
 */
function braspress_option_fixture_package( int $vendor_id = BRASPRESS_OPTION_TEST_VENDOR_ID ): array {
	$snapshot = papelito_packaging_build_snapshot(
		array(
			'vendor_id'               => $vendor_id,
			'origin_cep'              => BRASPRESS_OPTION_TEST_ORIGIN_CEP,
			'destination_cep'         => BRASPRESS_OPTION_TEST_DESTINATION_CEP,
			'merchandise_value_cents' => BRASPRESS_OPTION_TEST_MERCHANDISE_CENTS,
			'packages'                => array(
				array(
					'length_mm' => 300,
					'width_mm'  => 200,
					'height_mm' => 100,
					'weight_g'  => 2500,
					'count'     => 1,
				),
			),
		)
	);

	return array(
		'weight_kg'     => 2.5,
		'volumes'       => 1,
		'physical_hash' => (string) ( $snapshot['physical_hash'] ?? '' ),
		'cubagem'       => array(
			array(
				'length_m' => 0.3,
				'width_m'  => 0.2,
				'height_m' => 0.1,
				'volumes'  => 1,
			),
		),
	);
}

/** Retorna uma data UTC injetada na cotação. */
function braspress_option_fixture_time( string $value ): DateTimeImmutable {
	return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
}

/** Monta a resposta de sucesso com o identificador de cotação informado. */
function braspress_option_success_response( string $quote_id, float $price = BRASPRESS_OPTION_TEST_QUOTE_PRICE, int $days = BRASPRESS_OPTION_TEST_QUOTE_DAYS ): array {
	return array(
		'status' => 200,
		'body'   => wp_json_encode(
			array(
				'id'         => $quote_id,
				'totalFrete' => $price,
				'prazo'      => $days,
			)
		),
	);
}

/**
 * Cota uma vez com o cache vazio, como acontece depois da expiração do transient.
 *
 * @return array<string,mixed>|WP_Error Opção bruta devolvida pelo adapter.
 */
function braspress_option_quote_with_cold_cache( array $integration, array $package, array $response, DateTimeImmutable $quoted_at ) {
	$GLOBALS['braspress_option_cache']         = array();
	$GLOBALS['braspress_option_http_response'] = $response;

	return papelito_braspress_quote_at(
		$integration,
		BRASPRESS_OPTION_TEST_RECIPIENT_CNPJ,
		BRASPRESS_OPTION_TEST_DESTINATION_CEP,
		$package,
		BRASPRESS_OPTION_TEST_MERCHANDISE_CENTS,
		$quoted_at
	);
}

/**
 * Normaliza a opção bruta no envelope público com `option_key` e fingerprint.
 *
 * @return array<string,mixed>|null Opção normalizada ou nulo quando recusada.
 */
function braspress_option_normalize( mixed $raw ): ?array {
	return is_array( $raw )
		? papelito_shipping_normalize_provider_option( PAPELITO_SHIPPING_PROVIDER_BRASPRESS, $raw, BRASPRESS_OPTION_TEST_QUOTED_AT, null )
		: null;
}

/**
 * Monta o snapshot que o checkout apresentou ao comprador a partir de uma opção.
 *
 * @return array<string,mixed> Snapshot com fingerprint, preço, prazo e validade.
 */
function braspress_option_snapshot_of( array $option ): array {
	return array(
		'fingerprint'          => $option['fingerprint'],
		'customer_price_cents' => $option['customer_price_cents'],
		'delivery_time'        => $option['delivery_time'],
		'expires_at'           => $option['expires_at'],
	);
}

$integration  = braspress_option_fixture_integration();
$package      = braspress_option_fixture_package();
$quoted_at    = braspress_option_fixture_time( BRASPRESS_OPTION_TEST_QUOTE_UTC );
$later        = braspress_option_fixture_time( BRASPRESS_OPTION_TEST_LATER_UTC );

$first_raw  = braspress_option_quote_with_cold_cache( $integration, $package, braspress_option_success_response( BRASPRESS_OPTION_TEST_FIRST_QUOTE_ID ), $quoted_at );
$second_raw = braspress_option_quote_with_cold_cache( $integration, $package, braspress_option_success_response( BRASPRESS_OPTION_TEST_SECOND_QUOTE_ID ), $later );

braspress_option_assert( 'as duas cotações do mesmo contrato são bem-sucedidas', is_array( $first_raw ) && is_array( $second_raw ) );
braspress_option_assert( 'o código da opção vem da modalidade contratada', BRASPRESS_OPTION_TEST_EXPECTED_CODE === ( $first_raw['code'] ?? '' ) );
braspress_option_assert( 'cotações com IDs diferentes produzem o mesmo código', ( $first_raw['code'] ?? '' ) === ( $second_raw['code'] ?? null ) );
braspress_option_assert( 'o código da opção não é o ID da cotação', BRASPRESS_OPTION_TEST_FIRST_QUOTE_ID !== ( $first_raw['code'] ?? '' ) && BRASPRESS_OPTION_TEST_SECOND_QUOTE_ID !== ( $second_raw['code'] ?? '' ) );
braspress_option_assert( 'cada cotação preserva o próprio ID em external_quote_id', BRASPRESS_OPTION_TEST_FIRST_QUOTE_ID === ( $first_raw['external_quote_id'] ?? '' ) && BRASPRESS_OPTION_TEST_SECOND_QUOTE_ID === ( $second_raw['external_quote_id'] ?? '' ) );
braspress_option_assert( 'os dois IDs de cotação de fato diferem', ( $first_raw['external_quote_id'] ?? '' ) !== ( $second_raw['external_quote_id'] ?? null ) );

$first_option  = braspress_option_normalize( $first_raw );
$second_option = braspress_option_normalize( $second_raw );

braspress_option_assert( 'as duas opções sobrevivem à normalização', is_array( $first_option ) && is_array( $second_option ) );
braspress_option_assert( 'a option_key é estável e namespaced pela modalidade', BRASPRESS_OPTION_TEST_EXPECTED_KEY === ( $first_option['option_key'] ?? '' ) && BRASPRESS_OPTION_TEST_EXPECTED_KEY === ( $second_option['option_key'] ?? '' ) );
braspress_option_assert( 'a option_key normalizada não carrega o ID da cotação', ! str_contains( (string) ( $first_option['option_key'] ?? '' ), BRASPRESS_OPTION_TEST_FIRST_QUOTE_ID ) );
braspress_option_assert( 'o envelope público não expõe o physical_hash', is_array( $first_option ) && ! array_key_exists( 'physical_hash', $first_option ) );

$snapshot = braspress_option_snapshot_of( $first_option );

braspress_option_assert(
	'seleção feita antes da expiração do cache casa com a recotação seguinte',
	papelito_shipping_option_matches_checkout_snapshot( $second_option, BRASPRESS_OPTION_TEST_EXPECTED_KEY, $snapshot )
);

$changed_price  = braspress_option_normalize( braspress_option_quote_with_cold_cache( $integration, $package, braspress_option_success_response( BRASPRESS_OPTION_TEST_SECOND_QUOTE_ID, BRASPRESS_OPTION_TEST_CHANGED_PRICE ), $later ) );
$changed_days   = braspress_option_normalize( braspress_option_quote_with_cold_cache( $integration, $package, braspress_option_success_response( BRASPRESS_OPTION_TEST_SECOND_QUOTE_ID, BRASPRESS_OPTION_TEST_QUOTE_PRICE, BRASPRESS_OPTION_TEST_CHANGED_DAYS ), $later ) );

braspress_option_assert( 'preço diferente é recusado mesmo com a chave estável', is_array( $changed_price ) && ! papelito_shipping_option_matches_checkout_snapshot( $changed_price, BRASPRESS_OPTION_TEST_EXPECTED_KEY, $snapshot ) );
braspress_option_assert( 'prazo diferente é recusado mesmo com a chave estável', is_array( $changed_days ) && ! papelito_shipping_option_matches_checkout_snapshot( $changed_days, BRASPRESS_OPTION_TEST_EXPECTED_KEY, $snapshot ) );

$tampered_fingerprint                = $snapshot;
$tampered_fingerprint['fingerprint'] = strrev( (string) $snapshot['fingerprint'] );
braspress_option_assert( 'fingerprint adulterado é recusado', ! papelito_shipping_option_matches_checkout_snapshot( $second_option, BRASPRESS_OPTION_TEST_EXPECTED_KEY, $tampered_fingerprint ) );

$empty_fingerprint                = $snapshot;
$empty_fingerprint['fingerprint'] = '';
braspress_option_assert( 'snapshot sem fingerprint é recusado', ! papelito_shipping_option_matches_checkout_snapshot( $second_option, BRASPRESS_OPTION_TEST_EXPECTED_KEY, $empty_fingerprint ) );

$other_vendor_package = braspress_option_fixture_package( BRASPRESS_OPTION_TEST_OTHER_VENDOR_ID );
braspress_option_assert( 'o snapshot logístico distingue os dois vendors', '' !== $package['physical_hash'] && $package['physical_hash'] !== $other_vendor_package['physical_hash'] );

$other_vendor_option = braspress_option_normalize(
	braspress_option_quote_with_cold_cache(
		braspress_option_fixture_integration( BRASPRESS_OPTION_TEST_OTHER_VENDOR_ID ),
		$other_vendor_package,
		braspress_option_success_response( BRASPRESS_OPTION_TEST_SECOND_QUOTE_ID ),
		$later
	)
);

braspress_option_assert( 'vendors diferentes publicam a mesma option_key', is_array( $other_vendor_option ) && BRASPRESS_OPTION_TEST_EXPECTED_KEY === ( $other_vendor_option['option_key'] ?? '' ) );
braspress_option_assert( 'opção de um vendor não casa com a seleção de outro', is_array( $other_vendor_option ) && ! papelito_shipping_option_matches_checkout_snapshot( $other_vendor_option, BRASPRESS_OPTION_TEST_EXPECTED_KEY, $snapshot ) );

$aereo_payload = papelito_braspress_build_quote_payload( braspress_option_fixture_integration( BRASPRESS_OPTION_TEST_VENDOR_ID, 'A' ), BRASPRESS_OPTION_TEST_RECIPIENT_CNPJ, BRASPRESS_OPTION_TEST_DESTINATION_CEP, $package, BRASPRESS_OPTION_TEST_MERCHANDISE_CENTS );
braspress_option_assert( 'aéreo não é contratado e não vira payload', is_wp_error( $aereo_payload ) && 'papelito_braspress_payload_invalid' === $aereo_payload->get_error_code() );
braspress_option_assert( 'o mapa de modalidades só contém o rodoviário', array( 'R' => 'rodoviario' ) === PAPELITO_BRASPRESS_SERVICE_CODE_BY_MODAL );

$unknown_modal_payload = papelito_braspress_build_quote_payload( braspress_option_fixture_integration( BRASPRESS_OPTION_TEST_VENDOR_ID, 'Z' ), BRASPRESS_OPTION_TEST_RECIPIENT_CNPJ, BRASPRESS_OPTION_TEST_DESTINATION_CEP, $package, BRASPRESS_OPTION_TEST_MERCHANDISE_CENTS );
braspress_option_assert( 'modal sem código conhecido não chega a virar payload', is_wp_error( $unknown_modal_payload ) && 'papelito_braspress_payload_invalid' === $unknown_modal_payload->get_error_code() );

$legacy_selection = PAPELITO_SHIPPING_PROVIDER_BRASPRESS . ':' . BRASPRESS_OPTION_TEST_FIRST_QUOTE_ID;
braspress_option_assert( 'chave legada com ID de cotação exige nova seleção', ! papelito_shipping_option_matches_checkout_snapshot( $second_option, $legacy_selection, $snapshot ) );
braspress_option_assert( 'seleção sem namespace nunca escolhe Braspress', ! papelito_shipping_option_matches_selection( $second_option, BRASPRESS_OPTION_TEST_EXPECTED_CODE ) );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
