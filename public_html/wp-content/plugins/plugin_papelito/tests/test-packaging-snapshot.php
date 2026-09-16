<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * LogisticsSnapshot canônico e physical_hash — BRASPRESS-003.
 *
 * A unidade base é milímetro e grama, em inteiros: cm com uma casa (Correios) e
 * metros com três (Braspress) saem dela sem erro de ponto flutuante. O
 * physical_hash descreve a embalagem, entra no fingerprint e é o que faz uma
 * troca de caixa invalidar a seleção mesmo quando o preço não muda.
 *
 * @package Papelito
 */

define('ABSPATH', __DIR__);

const SNAPSHOT_TEST_VENDOR_ID    = 2303;
const SNAPSHOT_TEST_ORIGIN_CEP   = '01310930';
const SNAPSHOT_TEST_DEST_CEP     = '22041001';
const SNAPSHOT_TEST_VALUE_CENTS  = 15990;
const SNAPSHOT_TEST_SOURCE       = 'profile';
const SNAPSHOT_TEST_LEGACY       = 'legacy_synthetic';

function wp_json_encode(mixed $value, int $flags = 0): string
{
    return (string) json_encode($value, $flags);
}

require_once dirname(__DIR__) . '/includes/packaging.php';

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

function snapshot_test_input(array $overrides = array()): array
{
    return array_merge(
        array(
            'vendor_id'               => SNAPSHOT_TEST_VENDOR_ID,
            'origin_cep'              => SNAPSHOT_TEST_ORIGIN_CEP,
            'destination_cep'         => SNAPSHOT_TEST_DEST_CEP,
            'merchandise_value_cents' => SNAPSHOT_TEST_VALUE_CENTS,
            'measurement_source'      => SNAPSHOT_TEST_SOURCE,
            'approval_version'        => 4,
            'packages'                => array(
                array('length_mm' => 200, 'width_mm' => 150, 'height_mm' => 100, 'weight_g' => 900, 'count' => 2),
            ),
        ),
        $overrides
    );
}

echo "Scenario 1: o snapshot fecha os totais a partir dos pacotes\n";
$snapshot = papelito_packaging_build_snapshot(snapshot_test_input());
snapshot_assert('versao de schema declarada', 1 === ($snapshot['schema_version'] ?? 0));
snapshot_assert('total_volumes é a soma dos count', 2 === ($snapshot['total_volumes'] ?? 0));
snapshot_assert('total_weight_g é peso vezes count', 1800 === ($snapshot['total_weight_g'] ?? 0));
snapshot_assert('preserva o valor mercantil', SNAPSHOT_TEST_VALUE_CENTS === ($snapshot['merchandise_value_cents'] ?? 0));
snapshot_assert('preserva a origem da medida', SNAPSHOT_TEST_SOURCE === ($snapshot['measurement_source'] ?? ''));
snapshot_assert('carrega physical_hash', is_string($snapshot['physical_hash'] ?? null) && '' !== $snapshot['physical_hash']);

echo "Scenario 2: medida legada nao tem versao de aprovacao\n";
$legacy = papelito_packaging_build_snapshot(snapshot_test_input(array('measurement_source' => SNAPSHOT_TEST_LEGACY, 'approval_version' => 7)));
snapshot_assert('approval_version é nulo', array_key_exists('approval_version', $legacy) && null === $legacy['approval_version']);

echo "Scenario 3: o hash é estavel e nao depende da ordem das chaves\n";
$shuffled = snapshot_test_input();
$shuffled['packages'][0] = array('count' => 2, 'weight_g' => 900, 'height_mm' => 100, 'width_mm' => 150, 'length_mm' => 200);
snapshot_assert('mesma entrada, mesmo hash', $snapshot['physical_hash'] === papelito_packaging_build_snapshot(snapshot_test_input())['physical_hash']);
snapshot_assert('ordem das chaves nao muda o hash', $snapshot['physical_hash'] === papelito_packaging_build_snapshot($shuffled)['physical_hash']);

echo "Scenario 4: trocar a embalagem muda o hash\n";
$taller = snapshot_test_input();
$taller['packages'][0]['height_mm'] = 120;
snapshot_assert('dimensao diferente muda o hash', $snapshot['physical_hash'] !== papelito_packaging_build_snapshot($taller)['physical_hash']);
$more = snapshot_test_input();
$more['packages'][0]['count'] = 3;
snapshot_assert('quantidade de volumes muda o hash', $snapshot['physical_hash'] !== papelito_packaging_build_snapshot($more)['physical_hash']);
snapshot_assert('origem da medida muda o hash', $snapshot['physical_hash'] !== $legacy['physical_hash']);
$revised = snapshot_test_input(array('approval_version' => 5));
snapshot_assert('versao do perfil muda o hash', $snapshot['physical_hash'] !== papelito_packaging_build_snapshot($revised)['physical_hash']);

echo "Scenario 5: o hash descreve a embalagem, nao o contexto da cotacao\n";
$other_dest = snapshot_test_input(array('destination_cep' => '70000000', 'merchandise_value_cents' => 99999));
snapshot_assert('destino e valor mercantil ficam fora do hash', $snapshot['physical_hash'] === papelito_packaging_build_snapshot($other_dest)['physical_hash']);

echo "Scenario 6: pacote sem medida valida nao vira snapshot\n";
snapshot_assert('devolve nulo', null === papelito_packaging_build_snapshot(snapshot_test_input(array('packages' => array(array('length_mm' => 0, 'width_mm' => 150, 'height_mm' => 100, 'weight_g' => 900, 'count' => 1))))));
snapshot_assert('sem pacote devolve nulo', null === papelito_packaging_build_snapshot(snapshot_test_input(array('packages' => array()))));

if ($failures > 0) {
    exit(1);
}

echo "RESULT: all assertions passed\n";
