<?php
/**
 * Benefícios da página de produto contra o banco real.
 *
 * O teste standalone (`tests/test-product-benefits.php`) cobre validação e
 * precedência injetando o índice. Aqui o alvo é o que só o MySQL prova: o
 * schema sai do `dbDelta`, a PRIMARY KEY de alvos impede fisicamente que dois
 * grupos disputem o mesmo produto, e os writers atravessam banco e cache.
 *
 *   wp eval-file tests/test-product-benefits-db.php
 *
 * Cria grupos e produtos descartáveis com prefixo reservado e apaga tudo no fim.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-product-benefits-db.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * Prefixo reservado: nada fora dele é tocado pela limpeza.
 */
const PAPELITO_BENEFITS_TEST_PREFIX = 'zzz-teste-beneficios';

global $wpdb, $failures, $checks, $created_groups, $created_products, $created_categories;

$failures           = 0;
$checks             = 0;
$created_groups     = array();
$created_products   = array();
$created_categories = array();

/**
 * Compara valores e contabiliza falhas do teste.
 *
 * @param string $label    Descrição.
 * @param mixed  $expected Esperado.
 * @param mixed  $actual   Obtido.
 * @return void
 */
function papelito_benefits_check( $label, $expected, $actual ) {
	global $failures, $checks;

	$checks++;

	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";
		return;
	}

	$failures++;
	echo "  FAIL: {$label} - esperado " . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

/**
 * Cria um produto descartável.
 *
 * @param string $suffix Sufixo do título.
 * @return int
 */
function papelito_benefits_make_product( $suffix ) {
	global $created_products;

	$product_id = wp_insert_post(
		array(
			'post_title'  => PAPELITO_BENEFITS_TEST_PREFIX . $suffix,
			'post_type'   => 'product',
			'post_status' => 'publish',
		)
	);

	$created_products[] = (int) $product_id;

	return (int) $product_id;
}

/**
 * Payload mínimo de um grupo.
 *
 * @param string   $name    Nome.
 * @param string[] $titles  Títulos dos itens.
 * @param array    $targets Alvos.
 * @return array<string,mixed>
 */
function papelito_benefits_payload( $name, array $titles, array $targets = array() ) {
	$items = array();

	foreach ( $titles as $title ) {
		$items[] = array(
			'iconType'    => 'emoji',
			'iconEmoji'   => '⭐',
			'title'       => $title,
			'description' => 'texto auxiliar',
		);
	}

	return array(
		'name'    => PAPELITO_BENEFITS_TEST_PREFIX . '-' . $name,
		'items'   => $items,
		'targets' => $targets,
	);
}

/**
 * Cria um grupo pelo caminho real de escrita.
 *
 * @param string   $name    Nome.
 * @param string[] $titles  Títulos.
 * @param array    $targets Alvos.
 * @return array<string,mixed>|WP_Error
 */
function papelito_benefits_create( $name, array $titles, array $targets = array() ) {
	global $created_groups;

	$validated = papelito_benefit_validate_group_payload( papelito_benefits_payload( $name, $titles, $targets ) );

	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	$created = papelito_benefit_group_create( $validated );

	if ( ! is_wp_error( $created ) ) {
		$created_groups[] = (int) $created['id'];
	}

	return $created;
}

/**
 * Títulos ativos resolvidos para um produto.
 *
 * @param int $product_id Id.
 * @return string[]
 */
function papelito_benefits_titles( $product_id ) {
	return array_map(
		static function ( $item ) {
			return $item['title'];
		},
		papelito_product_benefits_resolve( $product_id )['items']
	);
}

echo "Benefícios (banco): schema\n";

$version_before_install = papelito_product_benefits_version();
papelito_product_benefits_install_tables();
papelito_benefits_check(
	'instalação invalida o índice cacheado',
	true,
	papelito_product_benefits_version() > $version_before_install
);

$benefit_dbdelta_alters = array();
$capture_benefit_alters = static function ( $query ) use ( &$benefit_dbdelta_alters ) {
	if ( 0 === stripos( $query, 'ALTER TABLE' ) ) {
		$benefit_dbdelta_alters[] = $query;
	}

	return $query;
};
add_filter( 'query', $capture_benefit_alters );
papelito_product_benefits_install_tables();
remove_filter( 'query', $capture_benefit_alters );
papelito_benefits_check( 'segunda instalação não emite ALTER TABLE', array(), $benefit_dbdelta_alters );

$tables = papelito_product_benefits_table_names();

foreach ( $tables as $label => $table ) {
	papelito_benefits_check(
		"tabela {$label} existe",
		$table,
		$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
	);
}

echo "\nBenefícios (banco): seed do grupo global\n";

papelito_product_benefits_seed_global();

$global_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['groups']} WHERE is_global = 1" );
papelito_benefits_check( 'existe exatamente um grupo global', 1, $global_count );

papelito_product_benefits_seed_global();
papelito_benefits_check(
	'seed rodado de novo não duplica',
	1,
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['groups']} WHERE is_global = 1" )
);

// A garantia também precisa existir fora do caminho de seed: duas execuções
// concorrentes não podem criar dois grupos globais.
$suppressed       = $wpdb->suppress_errors( true );
$duplicate_global = $wpdb->insert(
	$tables['groups'],
	array(
		'name'       => PAPELITO_BENEFITS_TEST_PREFIX . '-global-duplicado',
		'is_global'  => 1,
		'global_key' => 1,
		'is_active'  => 1,
		'created_at' => current_time( 'mysql', true ),
		'updated_at' => current_time( 'mysql', true ),
	),
	array( '%s', '%d', '%d', '%d', '%s', '%s' )
);
$wpdb->suppress_errors( $suppressed );
papelito_benefits_check( 'INSERT direto de grupo global duplicado é recusado pelo MySQL', false, (bool) $duplicate_global );

$product_id = papelito_benefits_make_product( '-produto-a' );

papelito_benefits_check(
	'produto sem configuração recebe os três benefícios do seed',
	array( 'Frete Grátis', '30 Dias', 'Pagamento' ),
	papelito_benefits_titles( $product_id )
);
papelito_benefits_check(
	'e a origem é global',
	'global',
	papelito_product_benefits_resolve( $product_id )['source']
);

echo "\nBenefícios (banco): a PK de alvos é a invariante\n";

$claimed = papelito_benefits_create( 'grupo-a', array( 'A1' ), array( 'products' => array( $product_id ) ) );
papelito_benefits_check( 'grupo com alvo de produto é criado', false, is_wp_error( $claimed ) );
papelito_benefits_check( 'e o produto passa a usá-lo', array( 'A1' ), papelito_benefits_titles( $product_id ) );

$duplicate = papelito_benefits_create( 'grupo-b', array( 'B1' ), array( 'products' => array( $product_id ) ) );
papelito_benefits_check(
	'segundo grupo não pode reivindicar o mesmo produto',
	'papelito_benefit_target_taken',
	is_wp_error( $duplicate ) ? $duplicate->get_error_code() : 'sem erro'
);
papelito_benefits_check(
	'e a recusa não deixou grupo órfão',
	1,
	(int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['groups']} WHERE name LIKE %s",
			$wpdb->esc_like( PAPELITO_BENEFITS_TEST_PREFIX ) . '%'
		)
	)
);

// A garantia é do banco, não da checagem em PHP: gravar direto tem de falhar.
$suppressed = $wpdb->suppress_errors( true );
$raw_insert = $wpdb->insert(
	$tables['targets'],
	array( 'target_type' => 'product', 'target_key' => (string) $product_id, 'group_id' => 999999 ),
	array( '%s', '%s', '%d' )
);
$wpdb->suppress_errors( $suppressed );
papelito_benefits_check( 'INSERT direto de alvo duplicado é recusado pelo MySQL', false, (bool) $raw_insert );

echo "\nBenefícios (banco): ordem, desativação e exclusão\n";

$group = papelito_benefit_group_get( $created_groups[0] );

$reordered = papelito_benefit_validate_group_payload(
	papelito_benefits_payload( 'grupo-a', array( 'Terceiro', 'Primeiro', 'Segundo' ), array( 'products' => array( $product_id ) ) )
);
papelito_benefit_group_update( $group['id'], $reordered );
papelito_benefits_check(
	'a ordem gravada é a ordem enviada',
	array( 'Terceiro', 'Primeiro', 'Segundo' ),
	papelito_benefits_titles( $product_id )
);

$payload                       = papelito_benefits_payload( 'grupo-a', array( 'Visível', 'Oculto' ), array( 'products' => array( $product_id ) ) );
$payload['items'][1]['isActive'] = false;
papelito_benefit_group_update( $group['id'], papelito_benefit_validate_group_payload( $payload ) );
papelito_benefits_check( 'item inativo não chega à vitrine', array( 'Visível' ), papelito_benefits_titles( $product_id ) );

$rollback_group  = papelito_benefits_create( 'grupo-rollback', array( 'Original' ) );
$rollback_before = papelito_benefit_group_get( $rollback_group['id'] );
$rollback_payload = papelito_benefit_validate_group_payload(
	papelito_benefits_payload( 'grupo-rollback-renomeado', array( 'Não deve persistir' ), array( 'products' => array( $product_id ) ) )
);
$rollback_result = papelito_benefit_group_update( $rollback_group['id'], $rollback_payload );
papelito_benefits_check(
	'conflito no update responde target_taken',
	'papelito_benefit_target_taken',
	is_wp_error( $rollback_result ) ? $rollback_result->get_error_code() : 'sem erro'
);
$rollback_after = papelito_benefit_group_get( $rollback_group['id'] );
papelito_benefits_check( 'conflito não persiste metadados do grupo', $rollback_before['name'], $rollback_after['name'] );

$global_id = (int) $wpdb->get_var( "SELECT id FROM {$tables['groups']} WHERE is_global = 1 LIMIT 1" );
$deleted   = papelito_benefit_group_delete( $global_id );
papelito_benefits_check(
	'o grupo global não pode ser excluído',
	'papelito_benefit_global_not_deletable',
	is_wp_error( $deleted ) ? $deleted->get_error_code() : 'sem erro'
);

papelito_benefit_group_delete( $group['id'] );
papelito_benefits_check(
	'excluir o grupo devolve o produto ao global',
	array( 'Frete Grátis', '30 Dias', 'Pagamento' ),
	papelito_benefits_titles( $product_id )
);
papelito_benefits_check(
	'e os alvos do grupo excluído somem',
	'0',
	(string) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['targets']} WHERE group_id = %d", $group['id'] )
	)
);

$created_groups = array_values( array_diff( $created_groups, array( $group['id'] ) ) );

echo "\nBenefícios (banco): persistência sobrevive ao cache\n";

$persisted = papelito_benefits_create( 'persistente', array( 'Sobrevivi' ), array( 'products' => array( $product_id ) ) );
papelito_benefits_check( 'alvo do grupo excluído pode ser atribuído de novo', false, is_wp_error( $persisted ) );
delete_transient( 'papelito_benefits_index_v' . papelito_product_benefits_version() );
papelito_benefits_check(
	'após limpar o cache o dado continua no banco',
	array( 'Sobrevivi' ),
	papelito_benefits_titles( $product_id )
);

echo "\nLimpando\n";

foreach ( $created_groups as $group_id ) {
	$wpdb->delete( $tables['items'], array( 'group_id' => $group_id ), array( '%d' ) );
	$wpdb->delete( $tables['targets'], array( 'group_id' => $group_id ), array( '%d' ) );
	$wpdb->delete( $tables['groups'], array( 'id' => $group_id ), array( '%d' ) );
}

foreach ( $created_products as $product ) {
	wp_delete_post( $product, true );
}

papelito_product_benefits_touch( 'test' );

echo "\n{$checks} checagens\n";

if ( $failures > 0 ) {
	echo "FALHAS: {$failures}\n";
	exit( 1 );
}

echo "Todas as checagens de benefícios no banco passaram.\n";
