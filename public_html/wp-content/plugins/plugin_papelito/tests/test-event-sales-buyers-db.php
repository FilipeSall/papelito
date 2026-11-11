<?php
/**
 * Cadastro rápido do comprador no estande contra o banco real.
 *
 * Prova que repetir o cadastro com o mesmo CNPJ e e-mail não duplica empresa, usuário nem vínculo,
 * e que o vínculo nasce pendente com origem `event`.
 *
 *   docker compose exec -T web wp --allow-root eval-file \
 *     wp-content/plugins/plugin_papelito/tests/test-event-sales-buyers-db.php
 *
 * Usa um CNPJ de exemplo com o resultado da Receita no cache e apaga as próprias linhas no fim.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- WP-CLI test script prints results by design.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Rode com: wp eval-file tests/test-event-sales-buyers-db.php\n" );
	exit( 1 );
}

const EVENT_BUYER_DB_TEST_CNPJ  = '11222333000181';
const EVENT_BUYER_DB_TEST_EMAIL = 'comprador-evento@papelito.invalid';

global $wpdb, $failures;

$failures = 0;

/**
 * Registra uma asserção.
 *
 * @param string $label     Descrição.
 * @param bool   $condition Resultado.
 * @return void
 */
function event_buyer_db_check( string $label, bool $condition ): void {
	global $failures;

	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$label}\n";
}

/**
 * Apaga empresa, vínculos e usuário criados pelo teste.
 *
 * @return void
 */
function event_buyer_db_cleanup(): void {
	global $wpdb;

	$tables  = papelito_company_table_names();
	$company = papelito_company_find_by_cnpj( EVENT_BUYER_DB_TEST_CNPJ );
	if ( null !== $company ) {
		$wpdb->delete( $tables['members'], array( 'company_id' => (int) $company['id'] ) );
		$wpdb->delete( $tables['companies'], array( 'id' => (int) $company['id'] ) );
	}

	$user = get_user_by( 'email', EVENT_BUYER_DB_TEST_EMAIL );
	if ( $user instanceof WP_User ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
	}
}

event_buyer_db_cleanup();

set_transient(
	apply_filters( 'papelito_cnpj_cache_key', PAPELITO_CNPJ_CACHE_PREFIX . EVENT_BUYER_DB_TEST_CNPJ, EVENT_BUYER_DB_TEST_CNPJ ),
	array(
		'status'         => 'active',
		'source'         => 'test',
		'legal_name'     => 'Loja Teste do Evento LTDA',
		'trade_name'     => 'Loja Teste',
		'fiscal_address' => array( 'cep' => '01001000', 'state' => 'SP', 'city' => 'São Paulo', 'street' => 'Praça da Sé' ),
	),
	300
);

$operator = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
$input    = array(
	'cnpj'  => EVENT_BUYER_DB_TEST_CNPJ,
	'name'  => 'Comprador do Evento',
	'phone' => '(11) 98765-4321',
	'email' => EVENT_BUYER_DB_TEST_EMAIL,
);

echo "test_upsert_creates_company_user_member_once\n";

$first  = papelito_event_sales_upsert_buyer( $operator, $input );
$second = papelito_event_sales_upsert_buyer( $operator, $input );

event_buyer_db_check( 'primeira chamada cria', is_array( $first ) && true === $first['created']['company'] && true === $first['created']['user'] );
event_buyer_db_check( 'segunda chamada reaproveita', is_array( $second ) && false === $second['created']['company'] && false === $second['created']['user'] );
event_buyer_db_check( 'mesma empresa e mesmo usuário', is_array( $second ) && $first['companyId'] === $second['companyId'] && $first['buyerUserId'] === $second['buyerUserId'] );

$tables       = papelito_company_table_names();
$company_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['companies']} WHERE cnpj = %s", EVENT_BUYER_DB_TEST_CNPJ ) );
$member_rows  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['members']} WHERE company_id = %d", (int) $first['companyId'] ) );
event_buyer_db_check( 'uma empresa', 1 === $company_rows );
event_buyer_db_check( 'um vínculo', 1 === $member_rows );

$member  = papelito_company_member_get( (int) $first['companyId'], (int) $first['buyerUserId'] );
$company = papelito_company_get( (int) $first['companyId'] );
$user    = get_userdata( (int) $first['buyerUserId'] );
event_buyer_db_check( 'vínculo pendente com origem event', 'pending_company_approval' === $member['member_status'] && 'event' === $member['membership_origin'] && 'buyer' === $member['member_role'] );
event_buyer_db_check( 'empresa com dados da Receita', 'Loja Teste do Evento LTDA' === $company['legal_name'] && 'active' === $company['registry_status'] && 'SP' === $company['fiscal_state'] );
event_buyer_db_check( 'empresa criada pelo operador', $operator === (int) $company['created_by_user_id'] );
event_buyer_db_check( 'usuário customer com nome separado', $user instanceof WP_User && in_array( 'customer', $user->roles, true ) && 'Comprador' === $user->first_name && 'do Evento' === $user->last_name );
event_buyer_db_check( 'senha marcada como não definida', '1' === get_user_meta( (int) $first['buyerUserId'], PAPELITO_AUTH_PASSWORD_UNSET_META, true ) );

$wpdb->update( $tables['members'], array( 'member_status' => 'active' ), array( 'id' => (int) $member['id'] ) );
papelito_event_sales_upsert_buyer( $operator, $input );
$kept = papelito_company_member_get( (int) $first['companyId'], (int) $first['buyerUserId'] );
event_buyer_db_check( 'vínculo ativo não é rebaixado', 'active' === $kept['member_status'] );

event_buyer_db_cleanup();

echo 0 === $failures ? "OK\n" : "{$failures} falha(s)\n";
if ( $failures > 0 ) {
	exit( 1 );
}
