<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Override de embalagem do vendor — BRASPRESS-003.
 *
 * A regra de faixa é opcional e vence o cálculo quando se aplica. Ela descreve
 * um alvo — "este produto vai sempre na caixa G" —, então só manda quando o
 * conjunto é daquele alvo; carrinho misto volta para o cálculo.
 *
 * @package Papelito
 */

define('ABSPATH', __DIR__);

const OVERRIDE_TEST_PRODUCT_ID = 11836;
const OVERRIDE_TEST_OTHER_ID   = 11794;
const OVERRIDE_TEST_SMALL_CODE = 'P';
const OVERRIDE_TEST_FORCED_CODE = 'G';

require_once dirname(__DIR__) . '/includes/packaging.php';

$failures = 0;
function override_assert(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "  PASS: {$label}\n";
        return;
    }
    ++$failures;
    echo "  FAIL: {$label}\n";
}

$profiles = array(
    array('id' => 1, 'code' => OVERRIDE_TEST_SMALL_CODE, 'length_mm' => 200, 'width_mm' => 150, 'height_mm' => 100, 'tare_weight_g' => 50, 'max_payload_g' => 5000),
    array('id' => 9, 'code' => OVERRIDE_TEST_FORCED_CODE, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 300, 'tare_weight_g' => 200, 'max_payload_g' => 30000),
);

function override_test_line(int $target_id, int $qty): array
{
    return array(
        'target_type' => 'product',
        'target_id'   => $target_id,
        'qty'         => $qty,
        'length_mm'   => 90,
        'width_mm'    => 80,
        'height_mm'   => 40,
        'weight_g'    => 120,
    );
}

$rules = array(
    array('target_type' => 'product', 'target_id' => OVERRIDE_TEST_PRODUCT_ID, 'min_qty' => 1, 'max_qty' => 100, 'profile_id' => 9),
);

echo "Scenario 1: o alvo com override vai na caixa forcada, mesmo cabendo na menor\n";
$chosen = papelito_packaging_resolve_profile($profiles, $rules, array(override_test_line(OVERRIDE_TEST_PRODUCT_ID, 2)));
override_assert('devolve a caixa G', is_array($chosen) && OVERRIDE_TEST_FORCED_CODE === $chosen['code']);

echo "Scenario 2: sem override aplicavel o calculo decide\n";
$chosen = papelito_packaging_resolve_profile($profiles, $rules, array(override_test_line(OVERRIDE_TEST_OTHER_ID, 2)));
override_assert('devolve a caixa P', is_array($chosen) && OVERRIDE_TEST_SMALL_CODE === $chosen['code']);

echo "Scenario 3: carrinho misto volta para o calculo\n";
$mixed = array(override_test_line(OVERRIDE_TEST_PRODUCT_ID, 1), override_test_line(OVERRIDE_TEST_OTHER_ID, 1));
$chosen = papelito_packaging_resolve_profile($profiles, $rules, $mixed);
override_assert('devolve a caixa P', is_array($chosen) && OVERRIDE_TEST_SMALL_CODE === $chosen['code']);

echo "Scenario 4: quantidade fora da faixa nao aciona o override\n";
$narrow = array(array('target_type' => 'product', 'target_id' => OVERRIDE_TEST_PRODUCT_ID, 'min_qty' => 5, 'max_qty' => 10, 'profile_id' => 9));
$chosen = papelito_packaging_resolve_profile($profiles, $narrow, array(override_test_line(OVERRIDE_TEST_PRODUCT_ID, 2)));
override_assert('abaixo da faixa devolve a caixa P', is_array($chosen) && OVERRIDE_TEST_SMALL_CODE === $chosen['code']);
$chosen = papelito_packaging_resolve_profile($profiles, $narrow, array(override_test_line(OVERRIDE_TEST_PRODUCT_ID, 6)));
override_assert('dentro da faixa devolve a caixa G', is_array($chosen) && OVERRIDE_TEST_FORCED_CODE === $chosen['code']);

echo "Scenario 5: override apontando para perfil inexistente cai no calculo\n";
$broken = array(array('target_type' => 'product', 'target_id' => OVERRIDE_TEST_PRODUCT_ID, 'min_qty' => 1, 'max_qty' => 100, 'profile_id' => 404));
$chosen = papelito_packaging_resolve_profile($profiles, $broken, array(override_test_line(OVERRIDE_TEST_PRODUCT_ID, 2)));
override_assert('devolve a caixa P', is_array($chosen) && OVERRIDE_TEST_SMALL_CODE === $chosen['code']);

echo "Scenario 6: faixas sobrepostas nao escolhem pela ordem da consulta\n";
$overlapping = array(
    array('target_type' => 'product', 'target_id' => OVERRIDE_TEST_PRODUCT_ID, 'min_qty' => 1, 'max_qty' => 10, 'profile_id' => 9),
    array('target_type' => 'product', 'target_id' => OVERRIDE_TEST_PRODUCT_ID, 'min_qty' => 5, 'max_qty' => 15, 'profile_id' => 1),
);
$chosen = papelito_packaging_resolve_profile($profiles, $overlapping, array(override_test_line(OVERRIDE_TEST_PRODUCT_ID, 6)));
override_assert('sobreposicao cai no calculo', is_array($chosen) && OVERRIDE_TEST_SMALL_CODE === $chosen['code']);
$chosen = papelito_packaging_resolve_profile($profiles, array_reverse($overlapping), array(override_test_line(OVERRIDE_TEST_PRODUCT_ID, 6)));
override_assert('ordem invertida continua no calculo', is_array($chosen) && OVERRIDE_TEST_SMALL_CODE === $chosen['code']);

if ($failures > 0) {
    exit(1);
}

echo "RESULT: all assertions passed\n";
