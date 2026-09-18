<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.
/**
 * Contrato de opções e isolamento de falhas com adapters e relógio locais.
 *
 * @package Papelito
 */

define('ABSPATH', __DIR__ . '/');
define('PAPELITO_BRASPRESS_ENABLED', true);
define('PAPELITO_BRASPRESS_VENDOR_ALLOWLIST', '7');
define('SHIPPING_PROVIDER_TEST_NOW', '2026-09-14T12:00:00+00:00');
define('SHIPPING_PROVIDER_TEST_QUOTED_AT', '2026-09-14T11:45:00+00:00');
define('SHIPPING_PROVIDER_TEST_PAC_CODE', '03298');
define('SHIPPING_PROVIDER_TEST_TIMEOUT_MESSAGE', 'Timed out');
define('SHIPPING_PROVIDER_TEST_FUTURE_EXPIRY', '2999-01-01T00:00:00+00:00');
define('SHIPPING_PROVIDER_TEST_PAST_EXPIRY', '2000-01-01T00:00:00+00:00');
define('SHIPPING_PROVIDER_TEST_LIMIT_CODE', 'papelito_shipping_package_exceeds_limits');
define('SHIPPING_PROVIDER_TEST_PRIVATE', 'private-upstream-data');
define('SHIPPING_PROVIDER_TEST_CANONICAL_CODE', 'expresso-1_a');
define('SHIPPING_PROVIDER_TEST_BRASPRESS_KEY', 'braspress:03298');
define('SHIPPING_PROVIDER_TEST_DESTINATION_CEP', '22041001');

class WP_Error
{
	private string $code;
	private mixed $data;
	public function __construct(string $code, string $message, mixed $data = null)
	{
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_code(): string
	{
		return $this->code;
	}
	public function get_error_data(): mixed
	{
		return $this->data;
	}
}

function sanitize_key(mixed $value): string
{
	return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value));
}
function sanitize_text_field(mixed $value): string
{
	return trim((string) $value);
}
function absint(mixed $value): int
{
	return abs((int) $value);
}
function current_time(mixed $type = '', mixed $gmt = false): string
{
	return SHIPPING_PROVIDER_TEST_NOW;
}
function wp_json_encode(mixed $value): string
{
	return (string) json_encode($value);
}
function wp_salt(mixed $scheme = ''): string
{
	return 'test-salt';
}
function add_action(mixed ...$args): bool
{
	return true;
}
function apply_filters(mixed $hook, mixed $value, mixed ...$args): mixed
{
	if ('papelito_braspress_physical_package' === $hook) {
		return $GLOBALS['shipping_provider_approved_package'] ?? $value;
	}
	return $value;
}
function is_wp_error(mixed $value): bool
{
	return $value instanceof WP_Error;
}

$shipping_provider_correios_result = array(
	'origin_cep' => '01001000',
	'destination_cep' => '22041001',
	'vendor_id' => 7,
	'options' => array(array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'name' => 'PAC', 'service' => 'PAC', 'price' => 15.88, 'delivery_time' => 5)),
);
$shipping_provider_braspress_result = array('code' => 'EXPRESSO', 'name' => 'Braspress Expresso', 'service' => 'EXPRESSO', 'price' => 22.5, 'delivery_time' => 3, 'external_quote_id' => 'quote-1');
$shipping_provider_braspress_calls = 0;
$shipping_provider_braspress_resolver = null;

function papelito_correios_quote(int $vendor_id, string $destination_cep, array $items): array|WP_Error
{
	global $shipping_provider_correios_result;
	return $shipping_provider_correios_result;
}
function papelito_vendor_integration_resolve_braspress(int $vendor_id): array|WP_Error|null
{
	global $shipping_provider_braspress_resolver;
	return $shipping_provider_braspress_resolver;
}
function papelito_braspress_package_is_valid(array $package): bool
{
	return true;
}
function papelito_braspress_quote(array $integration, string $recipient_cnpj, string $destination_cep, array $package, int $merchandise_value_cents): array|WP_Error
{
	global $shipping_provider_braspress_calls, $shipping_provider_braspress_result;
	$shipping_provider_braspress_calls++;
	return $shipping_provider_braspress_result;
}
function papelito_vendor_integration_normalize_document(mixed $document): string
{
	return preg_replace('/\D+/', '', (string) $document);
}

/**
 * Devolve o pacote sem publicar a origem da medida.
 *
 * O emissor real vive em `shipping.php`, que este teste nao carrega. A emissao
 * em si e coberta por `test-shipping-measurement-metrics.php`.
 *
 * @param mixed $package Pacote resolvido ou erro.
 * @return mixed Pacote intocado.
 */
function papelito_shipping_notify_package_built(mixed $package): mixed
{
	return $package;
}

require_once dirname(__DIR__) . '/includes/shipping_providers.php';

$failures = 0;
function shipping_provider_assert(string $label, bool $condition): void
{
	global $failures;
	if (! $condition) {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

$correios = papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'price' => 15.88, 'delivery_time' => 5), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('preserva preço e prazo Correios', 1588 === $correios['customer_price_cents'] && 15.88 === $correios['price'] && 5 === $correios['delivery_time']);
shipping_provider_assert('inclui contrato canônico', 'correios:03298' === $correios['option_key'] && SHIPPING_PROVIDER_TEST_PAC_CODE === $correios['service_code'] && null === $correios['expires_at']);
$braspress = papelito_shipping_normalize_provider_option('braspress', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'EXPRESSO', 'name' => 'Braspress', 'price' => 15.88, 'delivery_time' => 5), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('códigos iguais entre providers não colidem', 'correios:03298' !== $braspress['option_key'] && 'braspress:03298' === $braspress['option_key']);
shipping_provider_assert('seleção legada permanece exclusiva dos Correios', papelito_shipping_option_matches_selection($correios, SHIPPING_PROVIDER_TEST_PAC_CODE) && ! papelito_shipping_option_matches_selection($braspress, SHIPPING_PROVIDER_TEST_PAC_CODE));
shipping_provider_assert('chave Braspress arbitrária não seleciona Correios', ! papelito_shipping_option_matches_selection($correios, 'braspress:03298'));
shipping_provider_assert('provider desconhecido não seleciona opção', ! papelito_shipping_option_matches_selection($correios, 'outro:03298'));
shipping_provider_assert('código legado continua exclusivo de Correios', papelito_shipping_option_matches_selection($correios, SHIPPING_PROVIDER_TEST_PAC_CODE) && ! papelito_shipping_option_matches_selection($braspress, SHIPPING_PROVIDER_TEST_PAC_CODE));
$correios_quote_with_metadata = $shipping_provider_correios_result;
$correios_quote_with_metadata['options'][] = array('code' => '03220', 'name' => 'SEDEX', 'service' => 'SEDEX', 'price' => 25.88, 'delivery_time' => 2);
$correios_quote_with_metadata['quoted_at'] = SHIPPING_PROVIDER_TEST_QUOTED_AT;
$correios_quote_with_metadata['expires_at'] = null;
$normalized_quote_with_metadata = papelito_shipping_aggregate_provider_results(
	array(papelito_shipping_provider_outcome('correios', $correios_quote_with_metadata)),
	SHIPPING_PROVIDER_TEST_NOW,
	null
);
$metadata_options = $normalized_quote_with_metadata['options'] ?? array();
shipping_provider_assert(
	'metadados do envelope Correios chegam a todas as opções',
	2 === count($metadata_options)
		&& SHIPPING_PROVIDER_TEST_QUOTED_AT === ($metadata_options[0]['quoted_at'] ?? null)
		&& SHIPPING_PROVIDER_TEST_QUOTED_AT === ($metadata_options[1]['quoted_at'] ?? null)
		&& array_key_exists('expires_at', $metadata_options[0]) && null === $metadata_options[0]['expires_at']
		&& array_key_exists('expires_at', $metadata_options[1]) && null === $metadata_options[1]['expires_at']
);
shipping_provider_assert(
	'quoted at não altera fingerprint',
	$correios['fingerprint'] === ($metadata_options[0]['fingerprint'] ?? null)
);
$snapshot = array('fingerprint' => $correios['fingerprint'], 'customer_price_cents' => $correios['customer_price_cents'], 'delivery_time' => $correios['delivery_time'], 'expires_at' => $correios['expires_at']);
shipping_provider_assert('snapshot atual confirma a cotação selecionada', papelito_shipping_option_matches_checkout_snapshot($correios, 'correios:03298', $snapshot));
$safe = papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'price' => 15.88, 'password' => 'secret', 'sender_cnpj' => '12345678000195'), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('não serializa segredos do provider', ! isset($safe['password']) && ! isset($safe['sender_cnpj']) && 14 === count($safe));
shipping_provider_assert('rejeita provider desconhecido e código vazio', null === papelito_shipping_normalize_provider_option('desconhecido', array('code' => 'X', 'service' => 'X', 'name' => 'X', 'price' => 1), SHIPPING_PROVIDER_TEST_NOW, null) && null === papelito_shipping_normalize_provider_option('correios', array('service' => 'PAC', 'name' => 'PAC', 'price' => 1), SHIPPING_PROVIDER_TEST_NOW, null));
shipping_provider_assert('rejeita campo de serviço não escalar sem warning', null === papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => array('PAC'), 'name' => 'PAC', 'price' => 15.88), SHIPPING_PROVIDER_TEST_NOW, null));
$external_id_null = papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'price' => 15.88, 'external_quote_id' => null), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('aceita external quote id nulo', is_array($external_id_null) && null === $external_id_null['external_quote_id']);
$external_id_empty = papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'price' => 15.88, 'external_quote_id' => ''), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('normaliza external quote id vazio como nulo', is_array($external_id_empty) && null === $external_id_empty['external_quote_id']);
shipping_provider_assert('rejeita preço decimal negativo', null === papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'price' => -15.88), SHIPPING_PROVIDER_TEST_NOW, null));
shipping_provider_assert('rejeita centavos do cliente negativos', null === papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'customer_price_cents' => -1588), SHIPPING_PROVIDER_TEST_NOW, null));
shipping_provider_assert('rejeita custo da transportadora negativo', null === papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'price' => 15.88, 'carrier_cost_cents' => -1588), SHIPPING_PROVIDER_TEST_NOW, null));
shipping_provider_assert('rejeita preço negativo mesmo com centavos válidos', null === papelito_shipping_normalize_provider_option('correios', array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'price' => -15.88, 'customer_price_cents' => 1588), SHIPPING_PROVIDER_TEST_NOW, null));
foreach (array('INF' => INF, 'NAN' => NAN, 'float overflow' => PHP_FLOAT_MAX, 'integer overflow' => PHP_INT_MAX / 100) as $label => $invalid_price) {
	$invalid_money_option = array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service' => 'PAC', 'name' => 'PAC', 'price' => $invalid_price);
	shipping_provider_assert('rejeita preço não representável: ' . $label, null === papelito_shipping_normalize_provider_option('correios', $invalid_money_option, SHIPPING_PROVIDER_TEST_NOW, null));
	$invalid_money_option['customer_price_cents'] = 1588;
	shipping_provider_assert('centavos válidos não escondem preço inválido: ' . $label, null === papelito_shipping_normalize_provider_option('correios', $invalid_money_option, SHIPPING_PROVIDER_TEST_NOW, null));
}
$partial = papelito_shipping_aggregate_provider_results(array(papelito_shipping_provider_outcome('correios', $shipping_provider_correios_result), papelito_shipping_provider_outcome('braspress', new WP_Error('timeout', SHIPPING_PROVIDER_TEST_TIMEOUT_MESSAGE, array('status' => 504)))), SHIPPING_PROVIDER_TEST_NOW, null);
foreach (array('!!!', 'EXPRESSO', 'exp resso', 'exp:resso', ' expresso ', '<b>expresso</b>') as $invalid_code) {
	$invalid_identity = array('code' => $invalid_code, 'service' => 'PAC', 'name' => 'PAC', 'price' => 15.88);
	shipping_provider_assert('rejeita identidade não canônica: ' . $invalid_code, null === papelito_shipping_normalize_provider_option('correios', $invalid_identity, SHIPPING_PROVIDER_TEST_NOW, null));
}
$identity_alias_conflict = array('code' => SHIPPING_PROVIDER_TEST_PAC_CODE, 'service_code' => '03220', 'service' => 'PAC', 'name' => 'PAC', 'price' => 15.88);
shipping_provider_assert('rejeita aliases de serviço conflitantes', null === papelito_shipping_normalize_provider_option('correios', $identity_alias_conflict, SHIPPING_PROVIDER_TEST_NOW, null));
$identity_alias_conflict['service_code'] = SHIPPING_PROVIDER_TEST_PAC_CODE;
$identity_alias_conflict['option_key'] = 'braspress:03298';
shipping_provider_assert('rejeita chave fornecida que diverge do serviço e provider', null === papelito_shipping_normalize_provider_option('correios', $identity_alias_conflict, SHIPPING_PROVIDER_TEST_NOW, null));
$canonical_service = papelito_shipping_normalize_provider_option('braspress', array('service_code' => SHIPPING_PROVIDER_TEST_CANONICAL_CODE, 'service' => 'EXPRESSO', 'name' => 'Braspress', 'price' => 22.5), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('chave usa exatamente o código canônico publicado', is_array($canonical_service) && 'braspress:expresso-1_a' === $canonical_service['option_key'] && SHIPPING_PROVIDER_TEST_CANONICAL_CODE === $canonical_service['service_code'] && SHIPPING_PROVIDER_TEST_CANONICAL_CODE === $canonical_service['code']);
shipping_provider_assert('mantém Correios quando Braspress expira', is_array($partial) && 1 === count($partial['options']) && 'correios' === $partial['options'][0]['provider']);
shipping_provider_assert('preserva categoria Braspress reconhecida', 'timeout' === papelito_shipping_provider_outcome('braspress', new WP_Error('papelito_braspress_timeout', SHIPPING_PROVIDER_TEST_TIMEOUT_MESSAGE, array('status' => 504)))['code']);
$unavailable = papelito_shipping_aggregate_provider_results(array(papelito_shipping_provider_outcome('correios', new WP_Error('timeout', SHIPPING_PROVIDER_TEST_TIMEOUT_MESSAGE, array('status' => 504))), papelito_shipping_provider_outcome('braspress', new WP_Error('network_error', 'Network failed', array('status' => 502)))), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('duas falhas devolvem erro seguro', is_wp_error($unavailable) && 'timeout' === $unavailable->get_error_code() && array('status' => 504) === $unavailable->get_error_data());
$physical_error = new WP_Error(SHIPPING_PROVIDER_TEST_LIMIT_CODE, SHIPPING_PROVIDER_TEST_PRIVATE, array('status' => 422, 'limit' => 'dimension', 'measurement_source' => 'legacy_synthetic', 'correios_status' => 500, 'correios_message' => SHIPPING_PROVIDER_TEST_PRIVATE, 'body' => SHIPPING_PROVIDER_TEST_PRIVATE, 'token' => SHIPPING_PROVIDER_TEST_PRIVATE));
$physical_outcome = papelito_shipping_provider_outcome('correios', $physical_error);
$physical_failure = papelito_shipping_aggregate_provider_results(array($physical_outcome), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('erro físico mantém categoria interna de validação', 'validation_error' === $physical_outcome['code']);
shipping_provider_assert('erro físico mantém identidade pública e só metadados seguros', SHIPPING_PROVIDER_TEST_LIMIT_CODE === $physical_failure->get_error_code() && array('status' => 422, 'limit' => 'dimension', 'measurement_source' => 'legacy_synthetic') === $physical_failure->get_error_data());
foreach (array('papelito_shipping_product_dimensions_missing', 'papelito_shipping_kit_package_not_supported', 'papelito_kit_package_dimensions_missing') as $local_code) {
	$local_outcome = papelito_shipping_provider_outcome('correios', new WP_Error($local_code, SHIPPING_PROVIDER_TEST_PRIVATE, array('status' => 422, 'body' => SHIPPING_PROVIDER_TEST_PRIVATE)));
	$local_failure = papelito_shipping_aggregate_provider_results(array($local_outcome), SHIPPING_PROVIDER_TEST_NOW, null);
	shipping_provider_assert('preserva validação local reconhecida: ' . $local_code, $local_code === $local_failure->get_error_code() && array('status' => 422) === $local_failure->get_error_data());
}
$unsafe_outcome = papelito_shipping_provider_outcome('correios', new WP_Error('unrecognized_secret', SHIPPING_PROVIDER_TEST_PRIVATE, array('status' => 502, 'limit' => 'dimension', 'measurement_source' => 'legacy_synthetic', 'body' => SHIPPING_PROVIDER_TEST_PRIVATE)));
$unsafe_failure = papelito_shipping_aggregate_provider_results(array($unsafe_outcome), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('código desconhecido não ganha identidade nem metadados públicos', 'unknown_error' === $unsafe_failure->get_error_code() && array('status' => 502) === $unsafe_failure->get_error_data());
$malformed_physical = papelito_shipping_provider_outcome('correios', new WP_Error(SHIPPING_PROVIDER_TEST_LIMIT_CODE, SHIPPING_PROVIDER_TEST_PRIVATE, array('status' => 422, 'limit' => 'private measurement', 'measurement_source' => array(SHIPPING_PROVIDER_TEST_PRIVATE))));
$malformed_failure = papelito_shipping_aggregate_provider_results(array($malformed_physical), SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('metadados físicos fora do vocabulário público são omitidos', array('status' => 422) === $malformed_failure->get_error_data());
$missing_contract_quote = papelito_shipping_quote_all_providers(7, '22041001', array(), array('recipient_cnpj' => '12345678000195', 'merchandise_value_cents' => 1000));
shipping_provider_assert('resolver nulo não chama a Braspress', is_array($missing_contract_quote) && 1 === count($missing_contract_quote['options']) && 0 === $shipping_provider_braspress_calls);

$shipping_provider_braspress_resolver = array('config' => array('origin_cep' => '01001000'));
$shipping_provider_approved_package = array('approval_version' => 'test-approved');
$shipping_provider_braspress_result['code'] = SHIPPING_PROVIDER_TEST_PAC_CODE;
$shipping_provider_braspress_result['expires_at'] = SHIPPING_PROVIDER_TEST_FUTURE_EXPIRY;
$shipping_provider_test_context = array('recipient_cnpj' => '12345678000195', 'merchandise_value_cents' => 1000);
$expiry_quote = papelito_shipping_quote_all_providers(7, SHIPPING_PROVIDER_TEST_DESTINATION_CEP, array(), $shipping_provider_test_context);
$expiry_option = $expiry_quote['options'][1] ?? array();
shipping_provider_assert('validade individual Braspress atravessa wrapper e agregação', SHIPPING_PROVIDER_TEST_FUTURE_EXPIRY === ($expiry_option['expires_at'] ?? null));
shipping_provider_assert('snapshot Braspress vigente é aceito', papelito_shipping_option_matches_checkout_snapshot($expiry_option, SHIPPING_PROVIDER_TEST_BRASPRESS_KEY, $expiry_option));
$shipping_provider_braspress_result['expires_at'] = SHIPPING_PROVIDER_TEST_PAST_EXPIRY;
$expired_quote = papelito_shipping_quote_all_providers(7, SHIPPING_PROVIDER_TEST_DESTINATION_CEP, array(), $shipping_provider_test_context);
$expired_option = $expired_quote['options'][1] ?? array();
shipping_provider_assert('snapshot com validade individual vencida é recusado', ! papelito_shipping_option_matches_checkout_snapshot($expired_option, SHIPPING_PROVIDER_TEST_BRASPRESS_KEY, $expired_option));
$expiry_envelope = papelito_shipping_quote_braspress(7, SHIPPING_PROVIDER_TEST_DESTINATION_CEP, array(), $shipping_provider_test_context);
$expiry_envelope['expires_at'] = SHIPPING_PROVIDER_TEST_FUTURE_EXPIRY;
$precedence_quote = papelito_shipping_normalize_provider_result('braspress', $expiry_envelope, SHIPPING_PROVIDER_TEST_NOW, null);
shipping_provider_assert('validade individual vence default do envelope', SHIPPING_PROVIDER_TEST_PAST_EXPIRY === ($precedence_quote['options'][0]['expires_at'] ?? null));

echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit($failures > 0 ? 1 : 0);
