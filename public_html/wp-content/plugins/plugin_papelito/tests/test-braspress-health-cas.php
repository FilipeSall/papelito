<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.
/**
 * Transição de saúde da integração Braspress sob troca de configuração.
 *
 * Uma cotação leva até 15 segundos, e nesse intervalo o vendor pode desabilitar
 * a integração, trocar a credencial ou mudar a origem. Sem comparar a versão da
 * configuração, a resposta que chega depois marca `active` uma conta que já não
 * existe mais — e o `UPDATE` casava só por `vendor_id` + `provider`.
 *
 * Fixa também as duas assimetrias que só aparecem em corrida: falha de escrita
 * não é o mesmo que configuração trocada, e credencial recusada não pode ser
 * apagada por uma cotação boa da mesma versão.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'PAPELITO_DIGITS_REGEX', '/\D+/' );
define( 'ARRAY_A', 'ARRAY_A' );

const CAS_TEST_VENDOR_ID    = 2163;
const CAS_TEST_OTHER_VENDOR = 4477;
const CAS_TEST_CNPJ         = '20024291000165';
const CAS_TEST_CEP          = '14711142';
const CAS_TEST_ENVELOPE     = 'papelito:v1:envelope-cifrado-do-teste';
const CAS_TEST_VERSION      = 7;
const CAS_TEST_NEXT_VERSION = 8;
const CAS_TEST_AUTH         = 'authentication_error';

$GLOBALS['cas_test_meta']    = array();
$GLOBALS['cas_test_row']     = null;
$GLOBALS['cas_test_updates'] = array();
$GLOBALS['cas_test_where']   = array();
$GLOBALS['cas_test_fail']    = false;
$GLOBALS['cas_test_ghost']   = false;
$GLOBALS['cas_test_concurrent'] = '';

/** Erro WordPress mínimo. */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

class WP_REST_Request {}
class WP_REST_Response {}

/** `$wpdb` mínimo que honra a cláusula de versão do `UPDATE`. */
class Papelito_Cas_Test_Wpdb {
	public string $prefix = 'wp_';

	public function get_charset_collate(): string { return ''; }

	public function get_row( mixed $query, mixed $output = null ): ?array {
		$row = $GLOBALS['cas_test_row'];

		if ( '' !== (string) $GLOBALS['cas_test_concurrent'] ) {
			$GLOBALS['cas_test_row']['status'] = $GLOBALS['cas_test_concurrent'];
			$GLOBALS['cas_test_concurrent']    = '';
		}

		return $row;
	}

	public function prepare( mixed $query, mixed ...$args ): string { return (string) $query; }

	/**
	 * Aplica a escrita só quando o `WHERE` casa, como o MySQL faria.
	 *
	 * `$GLOBALS['cas_test_ghost']` simula a corrida real: a linha muda de versão
	 * entre o `SELECT` e o `UPDATE`, então o `WHERE` não casa e zero linhas são
	 * afetadas sem que haja erro.
	 */
	public function update( mixed $table, array $data, mixed $where, mixed $format = null, mixed $where_format = null ): mixed {
		$GLOBALS['cas_test_updates'][] = $data;
		$GLOBALS['cas_test_where'][]   = $where;

		if ( true === $GLOBALS['cas_test_fail'] ) {
			return false;
		}

		if ( true === $GLOBALS['cas_test_ghost'] ) {
			return 0;
		}

		foreach ( array( 'configuration_version', 'status' ) as $column ) {
			if ( isset( $where[ $column ] ) && (string) $where[ $column ] !== (string) ( $GLOBALS['cas_test_row'][ $column ] ?? '' ) ) {
				return 0;
			}
		}

		$changed = false;
		foreach ( $data as $column => $value ) {
			if ( ( $GLOBALS['cas_test_row'][ $column ] ?? null ) !== $value ) {
				$changed = true;
			}
		}

		$GLOBALS['cas_test_row'] = array_merge( (array) $GLOBALS['cas_test_row'], $data );

		return $changed ? 1 : 0;
	}

	public function insert( mixed $table, array $data, mixed $format = null ): int { return 1; }
	public function delete( mixed $table, mixed $where, mixed $format = null ): int { return 1; }
}

$GLOBALS['wpdb'] = new Papelito_Cas_Test_Wpdb();

/** Nenhum listener é executado; o teste inspeciona a escrita. */
function add_action( mixed ...$args ): bool { return true; }
/** Nenhum listener é executado. */
function do_action( mixed ...$args ): bool { return true; }
/** Nenhum filtro instalado. */
function add_filter( mixed ...$args ): bool { return true; }
/** Devolve o valor recebido. */
function apply_filters( mixed $hook, mixed $value, mixed ...$args ): mixed { return $value; }
/** Reconhece o erro produzido pelos módulos reais. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
/** Sanitização textual suficiente para as fixturas. */
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
/** Sanitização de identidade igual à chave do WordPress. */
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
/** Serialização usada pela configuração persistida. */
function wp_json_encode( mixed $value, mixed $flags = 0 ): string { return (string) json_encode( $value, (int) $flags ); }
/** Conversão inteira do WordPress. */
function absint( mixed $value ): int { return abs( (int) $value ); }
/** Nenhuma rota REST é exercitada. */
function register_rest_route( mixed ...$args ): bool { return true; }
/** Relógio fixo do teste. */
function current_time( mixed $format = 'mysql', mixed $gmt = false ): string { return '2026-09-20 12:00:00'; }
/** Cadastro do vendor, lido pela configuração derivada. */
function get_user_meta( int $user_id, string $key, bool $single = false ): string {
	return $GLOBALS['cas_test_meta'][ $user_id ][ $key ] ?? '';
}
/** Envelope da fixture decifra para credenciais sintéticas. */
function papelito_pii_decrypt( string $envelope ): string {
	return wp_json_encode( array( 'username' => 'usuario-sintetico', 'password' => 'senha-sintetica' ) );
}
/** Cifragem não exercitada aqui. */
function papelito_pii_encrypt( string $plain ): string { return CAS_TEST_ENVELOPE; }
/** O CNPJ da fixture é válido. */
function papelito_validate_cnpj( string $cnpj ): bool { return CAS_TEST_CNPJ === $cnpj; }

require_once dirname( __DIR__ ) . '/includes/vendor_integrations.php';

$failures = 0;

/** Registra uma asserção sem interromper os cenários seguintes. */
function cas_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Recria a integração do vendor na versão e no estado informados. */
function cas_reset( string $status = PAPELITO_VENDOR_INTEGRATION_READY, int $version = CAS_TEST_VERSION ): void {
	$GLOBALS['cas_test_meta'][ CAS_TEST_VENDOR_ID ] = array( 'cnpj' => CAS_TEST_CNPJ, 'cep' => CAS_TEST_CEP );
	$GLOBALS['cas_test_row']                        = array(
		'id'                    => 7,
		'vendor_id'             => CAS_TEST_VENDOR_ID,
		'provider'              => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'config_json'           => wp_json_encode( array( 'origin_cep' => CAS_TEST_CEP ) ),
		'secret_envelope'       => CAS_TEST_ENVELOPE,
		'configuration_version' => $version,
		'enabled'               => 1,
		'status'                => $status,
	);
	$GLOBALS['cas_test_updates'] = array();
	$GLOBALS['cas_test_where']   = array();
	$GLOBALS['cas_test_fail']    = false;
	$GLOBALS['cas_test_ghost']   = false;
	$GLOBALS['cas_test_concurrent'] = '';
}

echo "Cenário 1: a transição só aplica sobre a versão que originou a cotação\n";
cas_reset();

$outcome = papelito_vendor_integration_apply_braspress_health(
	CAS_TEST_VENDOR_ID,
	PAPELITO_VENDOR_INTEGRATION_ACTIVE,
	'',
	CAS_TEST_VERSION
);

cas_assert( 'A versão correta aplica', PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED === $outcome );
cas_assert( 'O estado virou active', PAPELITO_VENDOR_INTEGRATION_ACTIVE === $GLOBALS['cas_test_row']['status'] );
cas_assert(
	'A versão entra na cláusula do UPDATE, não só o vendor',
	CAS_TEST_VERSION === ( $GLOBALS['cas_test_where'][0]['configuration_version'] ?? null )
);

echo "\nCenário 2: configuração trocada em trânsito não é marcada ativa\n";
cas_reset( PAPELITO_VENDOR_INTEGRATION_READY, CAS_TEST_NEXT_VERSION );

$outcome = papelito_vendor_integration_apply_braspress_health(
	CAS_TEST_VENDOR_ID,
	PAPELITO_VENDOR_INTEGRATION_ACTIVE,
	'',
	CAS_TEST_VERSION
);

cas_assert( 'A versão obsoleta é recusada', PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE === $outcome );
cas_assert( 'Nada foi escrito', array() === $GLOBALS['cas_test_updates'] );
cas_assert(
	'O estado da configuração nova é preservado',
	PAPELITO_VENDOR_INTEGRATION_READY === $GLOBALS['cas_test_row']['status']
);

echo "\nCenário 3: a corrida entre o SELECT e o UPDATE também é recusada\n";
cas_reset();
$GLOBALS['cas_test_ghost'] = true;

$outcome = papelito_vendor_integration_apply_braspress_health(
	CAS_TEST_VENDOR_ID,
	PAPELITO_VENDOR_INTEGRATION_ACTIVE,
	'',
	CAS_TEST_VERSION
);

cas_assert(
	'Zero linhas afetadas com versão na cláusula é obsolescência, não sucesso',
	PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE === $outcome
);

echo "\nCenário 4: falha de escrita não é configuração trocada\n";
cas_reset();
$GLOBALS['cas_test_fail'] = true;

$outcome = papelito_vendor_integration_apply_braspress_health(
	CAS_TEST_VENDOR_ID,
	PAPELITO_VENDOR_INTEGRATION_ACTIVE,
	'',
	CAS_TEST_VERSION
);

cas_assert( 'O banco recusando a escrita é falha, não obsolescência', PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED === $outcome );

echo "\nCenário 5: credencial recusada prevalece sobre cotação boa da mesma versão\n";
cas_reset( PAPELITO_VENDOR_INTEGRATION_INVALID );

$outcome = papelito_vendor_integration_apply_braspress_health(
	CAS_TEST_VENDOR_ID,
	PAPELITO_VENDOR_INTEGRATION_ACTIVE,
	'',
	CAS_TEST_VERSION
);

cas_assert( 'A cotação concorrente não reativa a conta', PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE === $outcome );
cas_assert( 'Nada foi escrito', array() === $GLOBALS['cas_test_updates'] );
cas_assert(
	'A conta continua marcada como credencial inválida',
	PAPELITO_VENDOR_INTEGRATION_INVALID === $GLOBALS['cas_test_row']['status']
);

echo "\nCenário 6: credencial recusada ainda pode ser registrada sobre a mesma versão\n";
cas_reset();

$outcome = papelito_vendor_integration_apply_braspress_health(
	CAS_TEST_VENDOR_ID,
	PAPELITO_VENDOR_INTEGRATION_INVALID,
	CAS_TEST_AUTH,
	CAS_TEST_VERSION
);

cas_assert( 'O 401 confirmado aplica', PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED === $outcome );
cas_assert(
	'A conta passa a invalid_credentials sem perder enabled',
	PAPELITO_VENDOR_INTEGRATION_INVALID === $GLOBALS['cas_test_row']['status']
	&& 1 === (int) $GLOBALS['cas_test_row']['enabled']
);

echo "\nCenário 7: sem versão declarada, a transição continua valendo\n";
cas_reset();

$outcome = papelito_vendor_integration_apply_braspress_health( CAS_TEST_VENDOR_ID, PAPELITO_VENDOR_INTEGRATION_BLOCKED, 'provider_account_blocked' );

cas_assert( 'A chamada sem versão aplica', PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED === $outcome );
cas_assert(
	'Sem versão declarada o UPDATE não inventa uma cláusula',
	! array_key_exists( 'configuration_version', $GLOBALS['cas_test_where'][0] ?? array() )
);

echo "\nCenário 8: integração de outro vendor nunca é alcançada\n";
cas_reset();
$GLOBALS['cas_test_row'] = null;

$outcome = papelito_vendor_integration_apply_braspress_health(
	CAS_TEST_OTHER_VENDOR,
	PAPELITO_VENDOR_INTEGRATION_ACTIVE,
	'',
	CAS_TEST_VERSION
);

cas_assert( 'Vendor sem integração não escreve nada', PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE === $outcome );
cas_assert( 'Nenhuma escrita foi tentada', array() === $GLOBALS['cas_test_updates'] );

echo "\nCenário 9: 401 que grava entre o SELECT e o UPDATE não é sobrescrito\n";
cas_reset( PAPELITO_VENDOR_INTEGRATION_ACTIVE );
$GLOBALS['cas_test_concurrent'] = PAPELITO_VENDOR_INTEGRATION_INVALID;

$outcome = papelito_vendor_integration_apply_braspress_health(
	CAS_TEST_VENDOR_ID,
	PAPELITO_VENDOR_INTEGRATION_ACTIVE,
	'',
	CAS_TEST_VERSION
);

cas_assert(
	'A cotação boa que chega depois do 401 é descartada',
	PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE === $outcome
);
cas_assert(
	'A conta continua recusada, não volta para active',
	PAPELITO_VENDOR_INTEGRATION_INVALID === $GLOBALS['cas_test_row']['status']
);

echo "\nCenário 10: UPDATE que casa sem alterar nada é sucesso, não obsolescência\n";
cas_reset();

$first  = papelito_vendor_integration_apply_braspress_health( CAS_TEST_VENDOR_ID, PAPELITO_VENDOR_INTEGRATION_ACTIVE, '', CAS_TEST_VERSION );
$second = papelito_vendor_integration_apply_braspress_health( CAS_TEST_VENDOR_ID, PAPELITO_VENDOR_INTEGRATION_ACTIVE, '', CAS_TEST_VERSION );

cas_assert( 'A primeira cotação aplica', PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED === $first );
cas_assert(
	'A segunda cotação do mesmo segundo não é descartada como obsoleta',
	PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED === $second
);

echo "\n";
echo 0 === $failures ? "OK\n" : "FALHAS: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
