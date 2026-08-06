<?php
/**
 * Testes das fixtures locais de CNPJ (includes/cnpj_dev_fixtures.php).
 *
 * Cobre: interceptacao dos quatro hosts reais, ausencia total de egress, isolamento de cache
 * (o ponto critico — um `active` ficticio nao pode sobreviver ao desligamento da flag), a matriz
 * ambiente x flag e a cadeia REAL de evidencia de QSA ate o review_path.
 *
 * O estilo dos stubs segue o das demais harnesses standalone em tests/ (test-cnpj-providers.php,
 * test-company-qsa-auto-approval.php).
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
function is_wp_error( mixed $v ) { return $v instanceof WP_Error; }

/** Placeholder: papelito_company_owner_evidence so toca WP_User quando recebe um usuario. */
class WP_User {}

/* --- Ambiente e flags controlaveis --- */

$papelito_environment_type = 'local';
$papelito_flag_enabled     = true;
$papelito_test_env         = array();

function wp_get_environment_type(): string { global $papelito_environment_type; return $papelito_environment_type; }
function papelito_env( string $key, $default = null ) { global $papelito_test_env; return $papelito_test_env[ $key ] ?? $default; }
function papelito_env_bool( string $key, bool $default = false ): bool {
	global $papelito_flag_enabled;
	return 'PAPELITO_CNPJ_DEV_FIXTURES_ENABLED' === $key ? $papelito_flag_enabled : $default;
}
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' ); }

/* --- Registry de filtros --- */

$papelito_filters = array();
function add_filter( string $tag, callable $cb, int $priority = 10, int $accepted = 1 ) { global $papelito_filters; $papelito_filters[ $tag ][] = $cb; }
function apply_filters( string $tag, $value, ...$args ) {
	global $papelito_filters;
	foreach ( $papelito_filters[ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}

/* --- Transients em memoria --- */

$papelito_transients = array();
function get_transient( string $k ) { global $papelito_transients; return $papelito_transients[ $k ] ?? false; }
function set_transient( string $k, $v, $ttl ) { global $papelito_transients; $papelito_transients[ $k ] = $v; return true; }

/* --- Rede: sempre erra, e conta as tentativas (o teste exige ZERO para CNPJ de fixture) --- */

$papelito_remote_calls = 0;
function wp_remote_get( string $url, array $args = array() ) {
	global $papelito_remote_calls;
	++$papelito_remote_calls;
	return new WP_Error( 'no_network', 'network disabled in test' );
}
// ATENCAO: estes stubs espelham o WordPress REAL, que le $r['response']['code'] — e nao a
// convencao ['status' => ...] usada pelas harnesses mais antigas em tests/. Um mock que devolve
// a forma antiga faz o codigo virar 0 e todo provider responder `unavailable` em producao,
// enquanto o teste passa. Foi exatamente esse bug que estes stubs precisam continuar pegando.
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- esta funcao E o stub de wp_parse_url.
function get_user_meta( int $user_id, string $key, bool $single = false ) { return ''; }

/**
 * Espelha o remove_accents do WordPress no subconjunto que os nomes das fixtures usam.
 *
 * Os pares acentuados vivem em duas strings paralelas para nao virar um array gigante de uma
 * entrada por linha. O split usa PCRE com /u porque ext-mbstring nao esta garantida no PHP que
 * roda estes scripts — o mesmo motivo do guard de `mb_strtoupper` em company_services.php.
 */
function remove_accents( string $value ): string {
	$split    = static fn( string $chars ): array => preg_split( '//u', $chars, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	$accented = $split( 'áàãâéêíóôõúçñÁÀÃÂÉÊÍÓÔÕÚÇÑ' );
	$plain    = $split( 'aaaaeeioooucnAAAAEEIOOOUCN' );

	return strtr( $value, array_combine( $accented, $plain ) );
}

require __DIR__ . '/../includes/cnpj_validation.php';
require __DIR__ . '/../includes/cnpj_providers.php';
require __DIR__ . '/../includes/cnpj_dev_fixtures.php';

/*
 * Funcoes reais de QSA: company_services.php nao e requerivel standalone (depende de $wpdb,
 * WooCommerce e afins), entao isolamos por regex, como faz test-company-qsa-auto-approval.php.
 */

$papelito_services_source = (string) file_get_contents( __DIR__ . '/../includes/company_services.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- leitura local de fonte, sem HTTP.
$papelito_services_needed = array(
	'papelito_company_normalize_name',
	'papelito_company_name_tokens',
	'papelito_company_names_match',
	'papelito_company_mei_owner_name_matches',
	'papelito_company_age_band',
	'papelito_company_cpf_mask_matches',
	'papelito_company_cpf_mask_is_comparable',
	'papelito_company_qsa_qualification',
	'papelito_company_qsa_partner_name',
	'papelito_company_qsa_partner_cpf_mask',
	'papelito_company_owner_evidence',
	'papelito_company_owner_review_path',
);
foreach ( $papelito_services_needed as $papelito_fn ) {
	if ( ! preg_match( '/function ' . $papelito_fn . '\(.*?\n}/s', $papelito_services_source, $papelito_match ) ) {
		echo "  FAIL: could not isolate {$papelito_fn} from company_services.php\n";
		exit( 1 );
	}
	eval( $papelito_match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}

/* --- Helpers de assercao --- */

$failures = 0;
function papelito_assert_same( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) { echo "  PASS: {$label}\n"; return; }
	++$failures;
	echo "  FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
}

/** Zera cache, contador de rede e o log de respostas antes de cada cenario. */
$papelito_seen = array();
function papelito_reset(): void {
	global $papelito_transients, $papelito_remote_calls, $papelito_seen;
	$papelito_transients   = array();
	$papelito_remote_calls = 0;
	$papelito_seen         = array();
}

// Spy registrado DEPOIS do responder de fixtures: recebe o valor final entregue ao pipeline para
// cada URL, entao prova o que cada provider realmente recebeu.
add_filter(
	'papelito_cnpj_http_response',
	static function ( $value, $url, $args = array() ) {
		global $papelito_seen;
		$papelito_seen[ (string) wp_parse_url( $url, PHP_URL_HOST ) ] = is_array( $value ) ? wp_remote_retrieve_response_code( $value ) : null;
		return $value;
	}
);

const FIXTURE_A = '99999001000159';
const FIXTURE_B = '99999002000101';
const REAL_CNPJ = '11222333000181';

echo "== Documentos das fixtures passam nos validadores oficiais ==\n";
papelito_assert_same( 'CNPJ da fixture A e valido', true, papelito_validate_cnpj( FIXTURE_A ) );
papelito_assert_same( 'CNPJ da fixture B e valido', true, papelito_validate_cnpj( FIXTURE_B ) );
papelito_assert_same( 'CPF do socio A e valido', true, papelito_validate_cpf( '12345678909' ) );
papelito_assert_same( 'CPF do socio B e valido', true, papelito_validate_cpf( '98765432100' ) );

echo "\n== 1-3. Fixtures respondem e nao geram egress ==\n";
papelito_reset();
$a = papelito_cnpj_lookup( FIXTURE_A );
papelito_assert_same( 'fixture A → active', 'active', $a['status'] );
papelito_assert_same( 'fixture A → source brasilapi', 'brasilapi', $a['source'] );
papelito_assert_same( 'fixture A → razao social', 'PAPELANDIA DISTRIBUIDORA DE PAPEIS LTDA', $a['legal_name'] );
papelito_assert_same( 'fixture A → nome fantasia', 'PAPELANDIA', $a['trade_name'] );
papelito_assert_same( 'fixture A → natureza juridica', '2062', $a['legal_nature_code'] );
papelito_assert_same( 'fixture A → nao e MEI', false, $a['is_mei'] );
papelito_assert_same( 'fixture A → UF do endereco fiscal', 'SP', $a['fiscal_address']['state'] );
papelito_assert_same( 'fixture A → 1 socio no QSA', 1, count( $a['qsa'] ) );
papelito_assert_same( 'fixture A → ZERO chamadas de rede', 0, $papelito_remote_calls );

papelito_reset();
$b = papelito_cnpj_lookup( FIXTURE_B );
papelito_assert_same( 'fixture B → active', 'active', $b['status'] );
papelito_assert_same( 'fixture B → outra empresa', 'IMPERIO DO PAPEL COMERCIO LTDA', $b['legal_name'] );
papelito_assert_same( 'fixture B → socio sem CPF mascarado', false, isset( $b['qsa'][0]['cnpj_cpf_do_socio'] ) );
papelito_assert_same( 'fixture B → ZERO chamadas de rede', 0, $papelito_remote_calls );

echo "\n== 4. publica.cnpj.ws e receitaws.com.br recebem 404, brasilapi recebe o payload ==\n";
papelito_reset();
$r = papelito_cnpj_lookup( FIXTURE_A );
papelito_assert_same( 'brasilapi recebeu 200', 200, $papelito_seen['brasilapi.com.br'] ?? null );
papelito_assert_same( 'publica.cnpj.ws recebeu 404', 404, $papelito_seen['publica.cnpj.ws'] ?? null );
papelito_assert_same( 'receitaws recebeu 404', 404, $papelito_seen['receitaws.com.br'] ?? null );
papelito_assert_same( 'o 404 dos secundarios nao vira conflict nem not_found', 'active', $r['status'] );
papelito_assert_same( 'resultado rico da brasilapi prevalece (QSA intacto)', 1, count( $r['qsa'] ) );

echo "\n== 5. Endpoint comercial do CNPJ.ws tambem e interceptado ==\n";
papelito_reset();
$papelito_test_env['PAPELITO_CNPJWS_TOKEN'] = 'token-comercial-ficticio';
$r = papelito_cnpj_lookup( FIXTURE_A );
papelito_assert_same( 'comercial.cnpj.ws foi o host consultado', true, isset( $papelito_seen['comercial.cnpj.ws'] ) );
papelito_assert_same( 'comercial.cnpj.ws recebeu 404', 404, $papelito_seen['comercial.cnpj.ws'] ?? null );
papelito_assert_same( 'com token, ainda ZERO chamadas de rede', 0, $papelito_remote_calls );
papelito_assert_same( 'com token, resultado continua active', 'active', $r['status'] );
$papelito_test_env = array();

echo "\n== 6-7. CNPJ comum e \$pre pre-existente ==\n";
papelito_reset();
$r = papelito_cnpj_lookup( REAL_CNPJ );
papelito_assert_same( 'CNPJ comum nao e interceptado (cai na rede real)', 3, $papelito_remote_calls );
papelito_assert_same( 'CNPJ comum sem rede → unavailable', 'unavailable', $r['status'] );

$sentinel = array(
	'body'     => '{"quem":"respondeu antes"}',
	'response' => array( 'code' => 418 ),
);
papelito_assert_same(
	'$pre de outro filtro e preservado, mesmo para CNPJ de fixture',
	$sentinel,
	papelito_cnpj_dev_fixtures_http_response( $sentinel, 'https://brasilapi.com.br/api/cnpj/v1/' . FIXTURE_A )
);

// Regressao: a resposta simulada precisa ter a forma do wp_remote_get REAL. Devolver
// ['status' => 200] faz wp_remote_retrieve_response_code() ler 0 e todo provider virar
// `unavailable` em producao, com o teste passando se o stub concordar com o mock.
$shape = papelito_cnpj_dev_fixtures_http_response( null, 'https://brasilapi.com.br/api/cnpj/v1/' . FIXTURE_A );
papelito_assert_same( 'resposta simulada usa response.code, como o wp_remote_get real', 200, $shape['response']['code'] ?? null );
papelito_assert_same( 'resposta simulada nao usa a convencao ["status"] das harnesses antigas', false, isset( $shape['status'] ) );
papelito_assert_same( 'resposta simulada traz o body em JSON', true, is_array( json_decode( (string) $shape['body'], true ) ) );

echo "\n== 8-10. Isolamento de cache ==\n";
papelito_reset();
papelito_cnpj_lookup( FIXTURE_A );
$expected_key = 'papelito_cnpj_devfix_' . papelito_cnpj_dev_fixtures_revision() . '_' . FIXTURE_A;
papelito_assert_same( 'fixture cacheia na chave namespaced', true, isset( $papelito_transients[ $expected_key ] ) );
papelito_assert_same( 'fixture NAO ocupa a chave canonica', false, isset( $papelito_transients[ 'papelito_cnpj_' . FIXTURE_A ] ) );
papelito_assert_same( 'revisao tem 8 chars', 8, strlen( papelito_cnpj_dev_fixtures_revision() ) );
papelito_assert_same(
	'revisao acompanha o conteudo das fixtures (editar uma invalida o cache dela)',
	substr( sha1( (string) wp_json_encode( papelito_cnpj_dev_fixtures() ) ), 0, 8 ),
	papelito_cnpj_dev_fixtures_revision()
);
papelito_assert_same( 'CNPJ comum continua na chave canonica', 'papelito_cnpj_' . REAL_CNPJ, papelito_cnpj_dev_fixtures_cache_key( 'papelito_cnpj_' . REAL_CNPJ, REAL_CNPJ ) );

// Regressao principal: com a fixture ja cacheada, desligar a flag NAO pode continuar servindo
// `active`. O recheck de runtime devolve a chave canonica, que nunca recebeu dado ficticio.
$papelito_flag_enabled = false;
$papelito_remote_calls = 0;
$stale                 = papelito_cnpj_lookup( FIXTURE_A );
papelito_assert_same( 'flag desligada nao serve o active ficticio do cache', false, 'active' === $stale['status'] );
papelito_assert_same( 'flag desligada cai na rede real → unavailable', 'unavailable', $stale['status'] );
papelito_assert_same( 'flag desligada volta a consultar os providers', 3, $papelito_remote_calls );
papelito_assert_same( 'transient ficticio fica orfao na chave namespaced', true, isset( $papelito_transients[ $expected_key ] ) );
$papelito_flag_enabled = true;

echo "\n== 11. Recheck de runtime com o callback JA registrado ==\n";
$fixture_url = 'https://brasilapi.com.br/api/cnpj/v1/' . FIXTURE_A;
foreach ( array(
	array( 'production', true ),
	array( 'staging', true ),
	array( 'development', false ),
	array( 'local', false ),
) as $case ) {
	list( $papelito_environment_type, $papelito_flag_enabled ) = $case;
	$label = $case[0] . ' + flag ' . var_export( $case[1], true );
	papelito_assert_same( "gate reprovado ({$label}) devolve \$pre", null, papelito_cnpj_dev_fixtures_http_response( null, $fixture_url ) );
	papelito_assert_same( "gate reprovado ({$label}) nao namespeia o cache", 'papelito_cnpj_' . FIXTURE_A, papelito_cnpj_dev_fixtures_cache_key( 'papelito_cnpj_' . FIXTURE_A, FIXTURE_A ) );
	papelito_assert_same( "gate reprovado ({$label}) nao carrega fixtures", 0, count( papelito_cnpj_dev_fixtures() ) );
}
foreach ( array( 'local', 'development' ) as $allowed ) {
	$papelito_environment_type = $allowed;
	$papelito_flag_enabled     = true;
	$response                  = papelito_cnpj_dev_fixtures_http_response( null, $fixture_url );
	papelito_assert_same( "gate aprovado ({$allowed}) responde a fixture", 200, is_array( $response ) ? wp_remote_retrieve_response_code( $response ) : null );
	papelito_assert_same( "gate aprovado ({$allowed}) carrega as 2 fixtures", 2, count( papelito_cnpj_dev_fixtures() ) );
}
$papelito_environment_type = 'local';
$papelito_flag_enabled     = true;

echo "\n== 12-13. Cadeia real: lookup → owner_evidence → owner_review_path ==\n";
papelito_reset();
$lookup_a   = papelito_cnpj_lookup( FIXTURE_A, true );
$evidence_a = papelito_company_owner_evidence( null, '12345678909', '1985-03-12', $lookup_a, 'Joana Fixture de Almeida' );
papelito_assert_same( 'A: registro ativo', 'active', $lookup_a['status'] );
papelito_assert_same( 'A: QSA disponivel', true, $evidence_a['qsa_available'] );
papelito_assert_same( 'A: QSA suficiente', true, $evidence_a['qsa_sufficient'] );
papelito_assert_same( 'A: nome do socio bate', true, $evidence_a['name_match'] );
papelito_assert_same( 'A: mascara de CPF bate', true, $evidence_a['cpf_mask_match'] );
papelito_assert_same( 'A: faixa etaria bate', true, $evidence_a['age_band_match'] );
papelito_assert_same( 'A: socio confirmado', true, $evidence_a['partner_match'] ?? null );
papelito_assert_same( 'A: review_path = qsa_review', 'qsa_review', papelito_company_owner_review_path( $evidence_a ) );

papelito_reset();
$lookup_b   = papelito_cnpj_lookup( FIXTURE_B, true );
$evidence_b = papelito_company_owner_evidence( null, '98765432100', '1992-11-05', $lookup_b, 'Ricardo Mock de Souza' );
papelito_assert_same( 'B: registro ativo', 'active', $lookup_b['status'] );
papelito_assert_same( 'B: QSA disponivel', true, $evidence_b['qsa_available'] );
papelito_assert_same( 'B: QSA insuficiente (sem CPF mascarado)', false, $evidence_b['qsa_sufficient'] );
papelito_assert_same( 'B: nome do socio bate', true, $evidence_b['name_match'] );
papelito_assert_same( 'B: review_path = document_required', 'document_required', papelito_company_owner_review_path( $evidence_b ) );

echo "\n== Registro dos filtros em processo separado (o require roda uma vez por processo) ==\n";
$boot = __DIR__ . '/support/cnpj_dev_fixtures_boot.php';
foreach ( array(
	array( 'production', 'true', false, 0 ),
	array( 'staging', 'true', false, 0 ),
	array( 'development', 'false', false, 0 ),
	array( 'local', 'false', false, 0 ),
	array( 'local', 'missing', false, 0 ),
	array( 'local', 'true', true, 2 ),
	array( 'development', 'true', true, 2 ),
) as $case ) {
	list( $env, $flag, $expect_registered, $expect_count ) = $case;
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $boot ) . ' ' . escapeshellarg( $env ) . ' ' . escapeshellarg( $flag );
	$raw     = (string) shell_exec( $command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- o gate de registro so pode ser testado em outro processo.
	$result  = json_decode( $raw, true );
	if ( ! is_array( $result ) ) {
		++$failures;
		echo "  FAIL: boot {$env}/{$flag} nao retornou JSON ({$raw})\n";
		continue;
	}
	papelito_assert_same( "boot {$env}/{$flag}: filtro HTTP registrado", $expect_registered, $result['registered'] );
	papelito_assert_same( "boot {$env}/{$flag}: filtro de cache registrado", $expect_registered, $result['cache_filter'] );
	papelito_assert_same( "boot {$env}/{$flag}: fixtures carregadas", $expect_count, $result['fixture_count'] );
}

echo "\n";
if ( $failures > 0 ) {
	echo "RESULT: {$failures} assertion(s) failed\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
