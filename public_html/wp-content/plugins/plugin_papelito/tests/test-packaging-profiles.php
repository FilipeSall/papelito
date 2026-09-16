<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Escolha determinística da caixa do vendor — BRASPRESS-003.
 *
 * A regra é a menor caixa que comporta o conjunto, pelos três testes da seção
 * 12.4 de docs/braspress/13-correios-physical-packaging-research.md: volume com
 * fator de aproveitamento, maior peça pelas dimensões ordenadas e peso com tara.
 * Não há encaixe 3D.
 *
 * @package Papelito
 */

define('ABSPATH', __DIR__);

const PACKAGING_TEST_SMALL_CODE  = 'P';
const PACKAGING_TEST_MEDIUM_CODE = 'M';

function packaging_test_profile(string $code, int $length_mm, int $width_mm, int $height_mm, int $tare_g, ?int $max_payload_g): array
{
    return array(
        'code'           => $code,
        'length_mm'      => $length_mm,
        'width_mm'       => $width_mm,
        'height_mm'      => $height_mm,
        'tare_weight_g'  => $tare_g,
        'max_payload_g'  => $max_payload_g,
    );
}

function packaging_test_item(int $length_mm, int $width_mm, int $height_mm, int $weight_g, int $qty): array
{
    return array(
        'length_mm' => $length_mm,
        'width_mm'  => $width_mm,
        'height_mm' => $height_mm,
        'weight_g'  => $weight_g,
        'qty'       => $qty,
    );
}

require_once dirname(__DIR__) . '/includes/packaging.php';

$failures = 0;
function packaging_assert(string $label, bool $condition): void
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
    packaging_test_profile(PACKAGING_TEST_SMALL_CODE, 200, 150, 100, 50, 5000),
    packaging_test_profile(PACKAGING_TEST_MEDIUM_CODE, 400, 300, 200, 120, 20000),
);

echo "Scenario 1: o conjunto cabe nas duas caixas e a menor vence\n";
$chosen = papelito_packaging_choose_profile($profiles, array(packaging_test_item(150, 120, 80, 400, 1)));
packaging_assert('devolve a caixa P', is_array($chosen) && PACKAGING_TEST_SMALL_CODE === $chosen['code']);

echo "Scenario 2: a maior peca nao entra na P pelas dimensoes ordenadas\n";
$chosen = papelito_packaging_choose_profile($profiles, array(packaging_test_item(210, 20, 20, 300, 1)));
packaging_assert('pula a P e devolve a M', is_array($chosen) && PACKAGING_TEST_MEDIUM_CODE === $chosen['code']);

echo "Scenario 3: peso com tara estoura a carga maxima da P\n";
$chosen = papelito_packaging_choose_profile($profiles, array(packaging_test_item(100, 100, 50, 6000, 1)));
packaging_assert('pula a P e devolve a M', is_array($chosen) && PACKAGING_TEST_MEDIUM_CODE === $chosen['code']);

echo "Scenario 4: o volume do conjunto estoura a P depois do fator de aproveitamento\n";
$three_cubes = array(packaging_test_item(100, 100, 100, 100, 3));
$chosen = papelito_packaging_choose_profile($profiles, $three_cubes, 0.8);
packaging_assert('com fator 0.8 pula a P e devolve a M', is_array($chosen) && PACKAGING_TEST_MEDIUM_CODE === $chosen['code']);

echo "Scenario 5: sem folga declarada o mesmo conjunto cabe na P\n";
$chosen = papelito_packaging_choose_profile($profiles, $three_cubes, 1.0);
packaging_assert('com fator 1.0 devolve a P', is_array($chosen) && PACKAGING_TEST_SMALL_CODE === $chosen['code']);

echo "Scenario 6: conjunto maior que toda caixa do vendor nao tem perfil\n";
$chosen = papelito_packaging_choose_profile($profiles, array(packaging_test_item(500, 400, 300, 1000, 1)));
packaging_assert('devolve nulo', null === $chosen);

echo "Scenario 7: o fator padrao é conservador\n";
packaging_assert('menor que 1', PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR < 1.0);
packaging_assert('maior que zero', PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR > 0.0);

echo "Scenario 8: volumes iguais desempatam por codigo, de forma estavel\n";
$tied = array(
    packaging_test_profile('B', 300, 100, 100, 40, 5000),
    packaging_test_profile('A', 200, 150, 100, 40, 5000),
);
$small = array(packaging_test_item(90, 90, 90, 100, 1));
$first  = papelito_packaging_choose_profile($tied, $small);
$second = papelito_packaging_choose_profile(array_reverse($tied), $small);
packaging_assert('devolve o codigo A', is_array($first) && 'A' === $first['code']);
packaging_assert('a ordem de entrada nao muda a escolha', is_array($second) && 'A' === $second['code']);

echo "Scenario 9: fator fora de (0,1] nao é obedecido em silencio\n";
packaging_assert('fator zero cai no padrao', PACKAGING_TEST_SMALL_CODE === (papelito_packaging_choose_profile($profiles, $small, 0.0)['code'] ?? ''));
packaging_assert('fator negativo cai no padrao', PACKAGING_TEST_SMALL_CODE === (papelito_packaging_choose_profile($profiles, $small, -2.0)['code'] ?? ''));
packaging_assert('fator acima de 1 cai no padrao', PACKAGING_TEST_MEDIUM_CODE === (papelito_packaging_choose_profile($profiles, $three_cubes, 4.0)['code'] ?? ''));

echo "Scenario 10: item sem dimensao nao entra em caixa nenhuma\n";
packaging_assert('devolve nulo', null === papelito_packaging_choose_profile($profiles, array(packaging_test_item(0, 0, 0, 100, 1))));

echo "Scenario 11: vendor sem perfil nao tem escolha\n";
packaging_assert('devolve nulo', null === papelito_packaging_choose_profile(array(), $small));

if ($failures > 0) {
    exit(1);
}

echo "RESULT: all assertions passed\n";
