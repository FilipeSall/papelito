<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Schema dos perfis de caixa do vendor — BRASPRESS-003.
 *
 * Estrutural: as invariantes do modelo são chaves do banco, não validação de
 * aplicação. Código de caixa único por vendor e início de faixa único por alvo
 * precisam existir como UNIQUE KEY; sobreposição completa exige validação de
 * intervalo no writer. O instalador só roda se estiver na lista de migration
 * com a versão de schema avançada.
 *
 * @package Papelito
 */

define('ABSPATH', __DIR__);

const PACKAGING_SCHEMA_TEST_PREFIX  = 'wp_';
const PACKAGING_SCHEMA_TEST_VERSION = '1.49.0';

class Papelito_Packaging_Schema_Wpdb
{
    public string $prefix = PACKAGING_SCHEMA_TEST_PREFIX;
    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}

global $wpdb;
$wpdb = new Papelito_Packaging_Schema_Wpdb();

require_once dirname(__DIR__) . '/includes/packaging.php';

$failures = 0;
function packaging_schema_assert(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "  PASS: {$label}\n";
        return;
    }
    ++$failures;
    echo "  FAIL: {$label}\n";
}

$tables = papelito_packaging_table_names();
$source = (string) file_get_contents(dirname(__DIR__) . '/includes/packaging.php');
$boot   = (string) file_get_contents(dirname(__DIR__) . '/plugin_papelito.php');

echo "Scenario 1: as duas tabelas existem com o prefixo do wpdb\n";
packaging_schema_assert('perfis', (($tables['profiles'] ?? '')) === PACKAGING_SCHEMA_TEST_PREFIX . 'papelito_packaging_profiles');
packaging_schema_assert('regras', (($tables['rules'] ?? '')) === PACKAGING_SCHEMA_TEST_PREFIX . 'papelito_packing_rules');

echo "Scenario 2: o perfil guarda medida interna, tara e carga maxima\n";
foreach (array('length_mm', 'width_mm', 'height_mm', 'tare_weight_g', 'max_payload_g', 'source', 'active') as $column) {
    packaging_schema_assert("coluna {$column}", str_contains($source, $column));
}

echo "Scenario 3: as chaves de banco cobrem as identidades modelaveis\n";
packaging_schema_assert('codigo de caixa unico por vendor', 1 === preg_match('/UNIQUE KEY\s+\w+\s*\(vendor_id, ?code\)/', $source));
packaging_schema_assert('inicio de faixa unico por alvo', 1 === preg_match('/UNIQUE KEY\s+\w+\s*\(vendor_id, ?target_type, ?target_id, ?min_qty\)/', $source));

echo "Scenario 4: a regra carrega versao e auditoria, sem fila de aprovacao\n";
packaging_schema_assert('version alimenta o physical_hash', 1 === preg_match('/\n\s*version INT/', $source));
packaging_schema_assert('updated_by registra quem mexeu', 1 === preg_match('/\n\s*updated_by BIGINT/', $source));
packaging_schema_assert('nao existe coluna de aprovacao', ! str_contains($source, 'approved_by'));

echo "Scenario 5: o instalador roda no bootstrap de migration\n";
packaging_schema_assert('registrado na lista', str_contains($boot, "'papelito_packaging_install_tables'"));
packaging_schema_assert('modulo carregado pelo plugin', str_contains($boot, "includes/packaging.php"));
packaging_schema_assert(
    'versao de schema avancou',
    1 === preg_match("/define\(\s*'PAPELITO_DB_VERSION',\s*'([0-9.]+)'/", $boot, $m) && version_compare($m[1], PACKAGING_SCHEMA_TEST_VERSION, '>')
);

if ($failures > 0) {
    exit(1);
}

echo "RESULT: all assertions passed\n";
