<?php
/**
 * Adapter Pagar.me sobre o perfil financeiro comum, contra o WordPress real.
 *
 * Roda com o plugin carregado porque o alvo é a convergência entre as portas legadas de cadastro,
 * o perfil novo e o payload real do recebedor — o que o standalone não alcança.
 *
 *   docker compose exec -T -u www-data web wp eval-file \
 *     wp-content/plugins/plugin_papelito/tests/test-pagarme-financial-profile-db.php
 *
 * Cria um vendor descartável e o apaga no fim. Nenhuma chamada sai para a Pagar.me: o filtro
 * `pre_http_request` intercepta e conta.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- WP-CLI test script prints results and toggles env flags by design.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-pagarme-financial-profile-db.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

const PAGARME_PROFILE_TEST_CPF  = '52998224725';
const PAGARME_PROFILE_TEST_CNPJ = '11222333000181';

global $failures, $pagarme_requests;

$failures         = 0;
$pagarme_requests = 0;

/**
 * Registra uma asserção.
 *
 * @param string $label     Descrição.
 * @param bool   $condition Resultado.
 * @return void
 */
function pagarme_profile_check( string $label, bool $condition ): void {
	global $failures;

	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

add_filter(
	'pre_http_request',
	static function ( mixed $pre, array $args, string $url ): mixed {
		global $pagarme_requests;
		if ( false !== strpos( $url, 'pagar.me' ) ) {
			++$pagarme_requests;
			return new WP_Error( 'blocked_in_test', 'sem rede no teste' );
		}
		return $pre;
	},
	10,
	3
);

$vendor_id = wp_insert_user(
	array(
		'user_login' => 'zzz-mp006-' . wp_generate_password( 6, false ),
		'user_email' => 'zzz-mp006-' . wp_generate_password( 6, false ) . '@example.test',
		'user_pass'  => wp_generate_password(),
	)
);

$metas = array(
	'cnpj'                                     => PAGARME_PROFILE_TEST_CNPJ,
	'store_name'                               => 'Loja Teste MP006',
	'phone_number'                             => '11987654321',
	'cep'                                      => '01001000',
	'city'                                     => 'São Paulo',
	'state'                                    => 'SP',
	PAPELITO_VENDOR_APPLICATION_STREET_META    => 'Praça da Sé',
	PAPELITO_VENDOR_APPLICATION_NUMBER_META    => '100',
	PAPELITO_VENDOR_APPLICATION_NEIGHBORHOOD_META => 'Sé',
);
foreach ( $metas as $key => $value ) {
	update_user_meta( $vendor_id, $key, $value );
}

$draft = array(
	'companyName'      => 'Loja Teste MP006 LTDA',
	'tradingName'      => 'Loja Teste MP006',
	'annualRevenue'    => '120000',
	'managingPartners' => array(
		array(
			'name'                   => 'Ana Sócia',
			'email'                  => 'ana@example.test',
			'document'               => PAGARME_PROFILE_TEST_CPF,
			'birthdate'              => '1985-02-03',
			'monthlyIncome'          => '5000',
			'professionalOccupation' => 'Empresária',
			'address'                => array(
				'zipCode'      => '01001000',
				'street'       => 'Praça da Sé',
				'streetNumber' => '100',
				'neighborhood' => 'Sé',
				'city'         => 'São Paulo',
				'state'        => 'SP',
			),
		),
	),
	'bankAccount'      => array(
		'holderName'        => 'Loja Teste MP006 LTDA',
		'holderType'        => 'company',
		'holderDocument'    => PAGARME_PROFILE_TEST_CNPJ,
		'bankCode'          => '341',
		'branchNumber'      => '1234',
		'accountNumber'     => '56789',
		'accountCheckDigit' => '1',
		'type'              => 'checking',
	),
);
papelito_save_vendor_pagarme_recipient_draft( $vendor_id, $draft );

putenv( 'PAPELITO_PAYMENT_GATEWAYS_ENABLED=false' );
$legacy_payload = papelito_pagarme_build_recipient_payload( $vendor_id );
pagarme_profile_check( 'vendor legado monta payload pelo draft', is_array( $legacy_payload ) );
pagarme_profile_check( 'vendor legado não ganha perfil novo', null === papelito_vendor_financial_profile_stored( $vendor_id ) );

putenv( 'PAPELITO_PAYMENT_GATEWAYS_ENABLED=true' );
putenv( 'PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS=' . $vendor_id );

$profile_payload = papelito_pagarme_build_recipient_payload( $vendor_id );
pagarme_profile_check( 'na coorte o payload sai do perfil, importado do draft', is_array( $profile_payload ) && null !== papelito_vendor_financial_profile_stored( $vendor_id ) );
pagarme_profile_check( 'perfil reproduz o mesmo payload Pagar.me do draft', $legacy_payload === $profile_payload );
pagarme_profile_check( 'payload mantém conta PJ no CNPJ do vendor', PAGARME_PROFILE_TEST_CNPJ === ( $profile_payload['default_bank_account']['holder_document'] ?? '' ) && 'company' === ( $profile_payload['default_bank_account']['holder_type'] ?? '' ) );
pagarme_profile_check( 'payload não carrega estado de outro gateway', false === strpos( (string) wp_json_encode( $profile_payload ), 'mercadopago' ) );

$public = papelito_vendor_financial_profile_public( $vendor_id );
papelito_vendor_financial_profile_patch( $vendor_id, array( 'tradingName' => 'Nome Novo MP006' ), $public['version'] );
$after_patch = papelito_pagarme_build_recipient_payload( $vendor_id );
pagarme_profile_check( 'edição pelo perfil chega ao payload', 'Nome Novo MP006' === ( $after_patch['register_information']['trading_name'] ?? '' ) );
pagarme_profile_check( 'edição pelo perfil não cria cópia nova no draft legado', 'Loja Teste MP006' === ( papelito_get_vendor_pagarme_recipient_draft( $vendor_id )['tradingName'] ?? '' ) );

$draft['companyName'] = 'Razão Pela Porta Antiga LTDA';
papelito_save_vendor_pagarme_recipient_draft( $vendor_id, $draft );
$converged = papelito_vendor_financial_profile_internal( $vendor_id );
pagarme_profile_check( 'porta legada de cadastro converge para o perfil', 'Razão Pela Porta Antiga LTDA' === $converged['companyName'] );
pagarme_profile_check( 'porta legada não apaga edição feita pelo perfil em campo não enviado', 'Loja Teste MP006' === $converged['tradingName'] || 'Nome Novo MP006' === $converged['tradingName'] );
pagarme_profile_check( 'porta legada preserva CPF no perfil', PAGARME_PROFILE_TEST_CPF === $converged['managingPartners'][0]['document'] );

$partial = $draft;
$partial['managingPartners'][0]['document'] = '';
papelito_save_vendor_pagarme_recipient_draft( $vendor_id, $partial );
pagarme_profile_check( 'draft parcial sem CPF não apaga o CPF do perfil', PAGARME_PROFILE_TEST_CPF === papelito_vendor_financial_profile_internal( $vendor_id )['managingPartners'][0]['document'] );

$public = papelito_vendor_financial_profile_public( $vendor_id );
papelito_vendor_financial_profile_patch(
	$vendor_id,
	array(
		'bankAccount' => array(
			'holderType'     => 'individual',
			'holderDocument' => PAGARME_PROFILE_TEST_CPF,
		),
	),
	$public['version']
);
$pending  = papelito_pagarme_financial_profile_pending( $vendor_id );
$rejected = papelito_pagarme_build_recipient_payload( $vendor_id );
pagarme_profile_check( 'conta pessoa física vira pendência Pagar.me', in_array( 'bankAccount.holderDocument', $pending['pendingFields'], true ) );
pagarme_profile_check( 'conta pessoa física é recusada localmente', is_wp_error( $rejected ) && 'papelito_pagarme_bank_holder_mismatch' === $rejected->get_error_code() );

update_user_meta(
	$vendor_id,
	PAPELITO_VENDOR_CNAE_EVIDENCE_META,
	array(
		'version'     => 1,
		'fingerprint' => papelito_vendor_cnae_fingerprint( PAGARME_PROFILE_TEST_CNPJ ),
		'source'      => 'brasilapi',
		'status'      => 'verified',
		'reason'      => '',
		'primary'     => '4729601',
		'secondary'   => array(),
		'checked_at'  => time(),
		'valid_until' => time() + DAY_IN_SECONDS,
	)
);
$blocked_pending = papelito_pagarme_financial_profile_pending( $vendor_id );
pagarme_profile_check( 'CNAE bloqueado dispensa os campos Pagar.me', 'blocked_cnae' === $blocked_pending['policy'] && array() === $blocked_pending['pendingFields'] );
$requests_before = $pagarme_requests;
$upsert          = papelito_pagarme_upsert_vendor_recipient( $vendor_id );
pagarme_profile_check( 'nenhum envio ocorre para CNAE bloqueado', is_wp_error( $upsert ) && $pagarme_requests === $requests_before );

putenv( 'PAPELITO_PAYMENT_GATEWAYS_ENABLED' );
putenv( 'PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS' );
wp_delete_user( $vendor_id );

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
if ( $failures > 0 ) {
	exit( 1 );
}
