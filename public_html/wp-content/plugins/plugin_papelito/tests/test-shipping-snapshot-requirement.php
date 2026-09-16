<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Quem exige o snapshot completo da cotação e quem só precisa da seleção.
 *
 * O recálculo do carrinho não cria pedido: ele mostra preço. Exigir fingerprint
 * ali derrubava todo /cart/pricing com 409 assim que o cliente escolhia o frete.
 * O place-order continua fail-closed.
 *
 * @package Papelito
 */

define('ABSPATH', __DIR__);

const SNAPSHOT_TEST_CEP         = '01310930';
const SNAPSHOT_TEST_OPTION_KEY  = 'correios:03298';
const SNAPSHOT_TEST_FINGERPRINT = 'fingerprint-valido';
const SNAPSHOT_TEST_STALE_CODE  = 'papelito_checkout_shipping_stale';

class WP_Error
{
	public function __construct(public string $code = '', public string $message = '', public array $data = array()) {}
	public function get_error_code(): string
	{
		return $this->code;
	}
}

function add_action(mixed ...$args): bool
{
	return true;
}
function add_filter(mixed ...$args): bool
{
	return true;
}
function register_rest_route(mixed ...$args): bool
{
	return true;
}
function apply_filters(mixed $hook, mixed $value): mixed
{
	return $value;
}
function do_action(mixed ...$args): bool
{
	return true;
}
function is_wp_error(mixed $value): bool
{
	return $value instanceof WP_Error;
}
function sanitize_key(mixed $value): string
{
	return strtolower(trim((string) $value));
}
function sanitize_text_field(mixed $value): string
{
	return trim((string) $value);
}
function absint(mixed $value): int
{
	return abs((int) $value);
}
function get_user_meta(mixed $user_id, mixed $key, mixed $single): string
{
	return 'Vendor Centro';
}
function get_userdata(mixed $user_id): ?object
{
	return null;
}

function papelito_shipping_quote_all_providers(mixed ...$args): array
{
	return array(
		'options' => array(
			array(
				'option_key'           => SNAPSHOT_TEST_OPTION_KEY,
				'provider'             => 'correios',
				'code'                 => '03298',
				'service'              => 'PAC',
				'price'                => 20.44,
				'customer_price_cents' => 2044,
				'delivery_time'        => 6,
				'expires_at'           => null,
				'fingerprint'          => SNAPSHOT_TEST_FINGERPRINT,
			),
		),
	);
}

function papelito_shipping_option_matches_selection(array $option, string $selection): bool
{
	return ($option['option_key'] ?? '') === $selection;
}

function papelito_shipping_option_matches_checkout_snapshot(array $option, string $selection, array $expected): bool
{
	if (! papelito_shipping_option_matches_selection($option, $selection)) {
		return false;
	}
	$fingerprint = (string) ($expected['fingerprint'] ?? '');

	return '' !== $fingerprint && $fingerprint === ($option['fingerprint'] ?? '');
}

require_once __DIR__ . '/../includes/order_routing.php';

$failures = 0;
function snapshot_assert(string $label, bool $condition): void
{
	global $failures;
	if ($condition) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

$lines = array(array('product_id' => 11836, 'qty' => 1, 'total_cents' => 7999));
$full  = array('fingerprint' => SNAPSHOT_TEST_FINGERPRINT);

echo "Scenario 1: checkout exige snapshot — sem ele continua recusando\n";
$r = papelito_order_routing_resolve_shipping(2303, SNAPSHOT_TEST_CEP, SNAPSHOT_TEST_OPTION_KEY, $lines, array(), array(), true);
snapshot_assert('snapshot vazio é recusado no modo estrito', is_wp_error($r) && SNAPSHOT_TEST_STALE_CODE === $r->get_error_code());

echo "Scenario 2: checkout com snapshot completo continua aceitando\n";
$r = papelito_order_routing_resolve_shipping(2303, SNAPSHOT_TEST_CEP, SNAPSHOT_TEST_OPTION_KEY, $lines, array(), $full, true);
snapshot_assert('snapshot válido devolve a opção', is_array($r) && SNAPSHOT_TEST_OPTION_KEY === $r['option_key']);

echo "Scenario 3: recálculo do carrinho casa só pela seleção\n";
$r = papelito_order_routing_resolve_shipping(2303, SNAPSHOT_TEST_CEP, SNAPSHOT_TEST_OPTION_KEY, $lines, array(), array(), false);
snapshot_assert('sem snapshot devolve a opção escolhida', is_array($r) && SNAPSHOT_TEST_OPTION_KEY === $r['option_key']);
snapshot_assert('devolve o preço recotado', is_array($r) && 2044 === $r['customer_price_cents']);

echo "Scenario 4: relaxar não vira passe-livre\n";
$r = papelito_order_routing_resolve_shipping(2303, SNAPSHOT_TEST_CEP, 'braspress:inexistente', $lines, array(), array(), false);
snapshot_assert('seleção inexistente continua recusada', is_wp_error($r) && SNAPSHOT_TEST_STALE_CODE === $r->get_error_code());

if ($failures > 0) {
	exit(1);
}
echo "RESULT: all assertions passed\n";
