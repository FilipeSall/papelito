<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * O physical_hash entra no fingerprint do frete — BRASPRESS-003.
 *
 * Sem ele, trocar a embalagem sem mexer no preço deixava a seleção do checkout
 * válida: o pedido nascia com uma caixa e era despachado em outra. O fingerprint
 * precisa mudar quando a embalagem muda, mesmo com preço, prazo e validade
 * idênticos.
 *
 * @package Papelito
 */

define('ABSPATH', __DIR__);

const PHYSICAL_FP_TEST_CODE     = '03298';
const PHYSICAL_FP_TEST_NOW      = '2026-09-16 12:00:00';
const PHYSICAL_FP_TEST_HASH_ONE = 'hash-caixa-p';
const PHYSICAL_FP_TEST_HASH_TWO = 'hash-caixa-m';
const PHYSICAL_FP_TEST_SALT     = 'salt-de-teste';

function sanitize_key(mixed $value): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
}
function sanitize_text_field(mixed $value): string
{
    return trim((string) $value);
}
function absint(mixed $value): int
{
    return abs((int) $value);
}
function wp_json_encode(mixed $value): string
{
    return (string) json_encode($value);
}
function wp_salt(mixed $scheme = ''): string
{
    return PHYSICAL_FP_TEST_SALT;
}
function is_wp_error(mixed $value): bool
{
    return false;
}
function apply_filters(mixed $hook, mixed $value): mixed
{
    return $value;
}
function add_filter(mixed ...$args): bool
{
    return true;
}
function add_action(mixed ...$args): bool
{
    return true;
}

require_once dirname(__DIR__) . '/includes/shipping_providers.php';

$failures = 0;
function physical_fp_assert(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "  PASS: {$label}\n";
        return;
    }
    ++$failures;
    echo "  FAIL: {$label}\n";
}

function physical_fp_option(?string $physical_hash): array
{
    $raw = array(
        'code'                 => PHYSICAL_FP_TEST_CODE,
        'service'              => 'PAC',
        'name'                 => 'PAC CONTRATO AG',
        'customer_price_cents' => 2044,
        'delivery_time'        => 6,
    );
    if (null !== $physical_hash) {
        $raw['physical_hash'] = $physical_hash;
    }

    return (array) papelito_shipping_normalize_provider_option('correios', $raw, PHYSICAL_FP_TEST_NOW, null);
}

$box_p = physical_fp_option(PHYSICAL_FP_TEST_HASH_ONE);
$box_m = physical_fp_option(PHYSICAL_FP_TEST_HASH_TWO);

echo "Scenario 1: mesma cotacao com embalagem diferente tem fingerprint diferente\n";
physical_fp_assert('preco identico', $box_p['customer_price_cents'] === $box_m['customer_price_cents']);
physical_fp_assert('prazo identico', $box_p['delivery_time'] === $box_m['delivery_time']);
physical_fp_assert('fingerprint muda', $box_p['fingerprint'] !== $box_m['fingerprint']);

echo "Scenario 2: o fingerprint é estavel para a mesma embalagem\n";
physical_fp_assert('mesma entrada, mesmo fingerprint', $box_p['fingerprint'] === physical_fp_option(PHYSICAL_FP_TEST_HASH_ONE)['fingerprint']);

echo "Scenario 3: o snapshot do checkout recusa a selecao quando a caixa mudou\n";
$snapshot = array(
    'fingerprint'          => $box_p['fingerprint'],
    'customer_price_cents' => $box_p['customer_price_cents'],
    'delivery_time'        => $box_p['delivery_time'],
    'expires_at'           => null,
);
physical_fp_assert('a mesma caixa continua valendo', papelito_shipping_option_matches_checkout_snapshot($box_p, $box_p['option_key'], $snapshot));
physical_fp_assert('a caixa trocada é recusada', ! papelito_shipping_option_matches_checkout_snapshot($box_m, $box_m['option_key'], $snapshot));

echo "Scenario 4: cotacao sem snapshot fisico continua cotando\n";
$legacy = physical_fp_option(null);
physical_fp_assert('gera opcao valida', is_array($legacy) && PHYSICAL_FP_TEST_CODE === ($legacy['service_code'] ?? ''));
physical_fp_assert('nao vaza o campo fisico na opcao publica', ! array_key_exists('physical_hash', $legacy));
physical_fp_assert('a opcao com snapshot tambem nao vaza', ! array_key_exists('physical_hash', $box_p));

if ($failures > 0) {
    exit(1);
}

echo "RESULT: all assertions passed\n";
