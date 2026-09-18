<?php
// phpcs:ignoreFile -- Standalone test stubs WordPress helpers by design.

/**
 * Gate de elegibilidade por embalagem cadastrada.
 *
 * Vendor sem o mínimo de caixas ativas some da cobertura como quem está sem
 * estoque, sem perder conta nem painel. O gate nasce desligado: ligá-lo antes
 * de avisar os vendors apagaria a vitrine inteira, porque hoje quase ninguém
 * cadastrou caixa.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

const GATE_TEST_ELIGIBLE_VENDOR_ID   = 5310;
const GATE_TEST_SHORT_VENDOR_ID      = 5311;
const GATE_TEST_EMPTY_VENDOR_ID      = 5312;
const GATE_TEST_UNPAID_VENDOR_ID     = 5313;
const GATE_TEST_CEP                  = 22041001;
const GATE_TEST_MIN_CEP              = '20000000';
const GATE_TEST_MAX_CEP              = '23000000';
const GATE_TEST_STORE_NAME           = 'Papelaria Central';
const GATE_TEST_VENDOR_EMAIL         = 'vendor-sintetico@example.test';
const GATE_TEST_NOTIF_TYPE           = 'vendor_packaging_profiles_pending';
const GATE_TEST_CUBAGEM_PATH         = '/vendor/cubagem';

/** Identifica um fixture de erro do WordPress. */
function is_wp_error( mixed $value ): bool { return false; }

/** Sanitiza uma chave sintética. */
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }

/** Sanitiza um texto sintético. */
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }

/** Sanitiza um e-mail sintético. */
function sanitize_email( mixed $value ): string { return trim( (string) $value ); }

/** Aceita o e-mail sintético do fixture. */
function is_email( mixed $value ): bool { return is_string( $value ) && str_contains( $value, '@' ); }

/** Converte um valor sintético em inteiro não negativo. */
function absint( mixed $value ): int { return abs( (int) $value ); }

/** Codifica um payload sintético do WordPress. */
function wp_json_encode( mixed $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }

/** Remove barras de um valor sintético. */
function wp_unslash( mixed $value ): mixed { return is_string( $value ) ? stripslashes( $value ) : $value; }

/** Devolve o instante sintético corrente. */
function current_time( mixed $type = '', mixed $gmt = false ): string { return '2026-09-17 12:00:00'; }

/** Stub de registro de action não relacionada ao seam testado. */
function add_action( mixed ...$args ): bool { return true; }

/** Stub de registro de filtro não relacionado ao seam testado. */
function add_filter( mixed ...$args ): bool { return true; }

/** Devolve o valor do filtro, deixando o cenário ligar o gate. */
function apply_filters( mixed $hook, mixed $value ): mixed {
	return 'papelito_packaging_profile_gate_enabled' === $hook ? ( $GLOBALS['gate_test_enabled'] ?? $value ) : $value;
}

/** Registra as actions disparadas para inspeção do teste. */
function do_action( mixed ...$args ): bool {
	$GLOBALS['gate_test_actions'][] = array( 'hook' => (string) ( $args[0] ?? '' ), 'args' => array_slice( $args, 1 ) );

	return true;
}

/** Stub de registro de rota não relacionada ao seam testado. */
function register_rest_route( mixed ...$args ): bool { return true; }

/** Devolve os vendors sintéticos, respeitando `fields => ID` como o WordPress. */
function get_users( mixed $args = array() ): array {
	$users = $GLOBALS['gate_test_users'];

	return 'ID' === ( $args['fields'] ?? '' ) ? array_map( static fn( WP_User $u ): int => $u->ID, $users ) : $users;
}

/** Devolve as faixas de CEP sintéticas do vendor. */
function get_user_meta( mixed $user_id, mixed $key, mixed $single = false ): mixed {
	if ( 'store_name' === $key ) {
		return GATE_TEST_STORE_NAME;
	}

	return 'min_cep' === $key ? array( GATE_TEST_MIN_CEP ) : array( GATE_TEST_MAX_CEP );
}

/** Todos os vendors sintéticos podem receber, menos o marcado no cenário. */
function papelito_vendor_can_receive_payments( int $vendor_id ): bool { return GATE_TEST_UNPAID_VENDOR_ID !== $vendor_id; }

/** Nenhum vendor sintético está suspenso. */
function papelito_account_is_suspended( int $vendor_id ): bool { return false; }

/**
 * Usuário WordPress sintético.
 */
class WP_User {
	/**
	 * Cria um usuário com identidade mínima.
	 *
	 * @param int    $id ID do usuário.
	 * @param string $user_email E-mail do usuário.
	 * @param string $display_name Nome exibido.
	 */
	public function __construct( public int $ID, public string $user_email = GATE_TEST_VENDOR_EMAIL, public string $display_name = GATE_TEST_STORE_NAME ) {}
}

/** Devolve o usuário sintético pelo ID. */
function get_user_by( mixed $field, mixed $value ): mixed { return new WP_User( (int) $value ); }

/**
 * Banco falso que responde a contagem de perfis ativos por vendor.
 */
class Gate_Test_WPDB {
	public string $prefix = 'wp_';

	public string $last_error = '';

	public int $insert_id = 0;

	/**
	 * Marca a consulta como preparada, guardando os valores.
	 *
	 * @param string $query Consulta SQL.
	 * @param mixed  ...$args Valores da consulta.
	 * @return string Consulta com os valores aplicados.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', (string) $arg, $query, 1 );
		}

		return $query;
	}

	/**
	 * Devolve a contagem de perfis ativos do vendor citado na consulta.
	 *
	 * @param string $query Consulta preparada.
	 * @return string Contagem como o MySQL devolveria.
	 */
	public function get_var( string $query ): string {
		foreach ( $GLOBALS['gate_test_profiles'] as $vendor_id => $count ) {
			if ( str_contains( $query, (string) $vendor_id ) ) {
				return (string) $count;
			}
		}

		return '0';
	}

	/**
	 * Registra uma inserção sintética e simula o índice único de dedupe.
	 *
	 * @param string             $table Tabela alvo.
	 * @param array<string,mixed> $data Linha a inserir.
	 * @param array<int,string>  $format Formatos da linha.
	 * @return bool Se a linha foi aceita.
	 */
	public function insert( string $table, array $data, array $format = array() ): bool {
		$key = $table . '|' . ( $data['dedupe_key'] ?? '' );
		if ( isset( $GLOBALS['gate_test_rows'][ $key ] ) ) {
			$this->last_error = 'Duplicate entry';
			return false;
		}
		$GLOBALS['gate_test_rows'][ $key ] = $data;
		$this->last_error                  = '';
		$this->insert_id                   = count( $GLOBALS['gate_test_rows'] );

		return true;
	}

	/**
	 * Registra um UPDATE de arquivamento sem executar SQL.
	 *
	 * @param string $query Consulta preparada.
	 * @return int Linhas afetadas.
	 */
	public function query( string $query ): int {
		$GLOBALS['gate_test_queries'][] = $query;

		return 1;
	}

	/**
	 * Devolve linhas vazias para consultas não modeladas.
	 *
	 * @param string $query Consulta preparada.
	 * @param mixed  $output Formato pedido.
	 * @return array<int,array<string,mixed>> Linhas.
	 */
	public function get_results( string $query, mixed $output = null ): array { return array(); }
}

/** Captura os e-mails que seriam enviados. */
function papelito_email_send( string $recipient, string $subject, string $html, string $text, array $attachments = array() ): bool {
	$GLOBALS['gate_test_emails'][] = array( 'to' => $recipient, 'subject' => $subject, 'html' => $html, 'text' => $text );

	return true;
}

/** Devolve a base sintética do frontend. */
function papelito_auth_get_frontend_url(): string { return 'https://papelito.test'; }

$GLOBALS['gate_test_users']    = array();
$GLOBALS['gate_test_profiles'] = array();
$GLOBALS['gate_test_actions']  = array();
$GLOBALS['gate_test_rows']     = array();
$GLOBALS['gate_test_queries']  = array();
$GLOBALS['gate_test_emails']   = array();
$GLOBALS['wpdb']               = new Gate_Test_WPDB();

require_once dirname( __DIR__ ) . '/includes/packaging.php';
require_once dirname( __DIR__ ) . '/includes/products_filter.php';

$failures = 0;

/** Confere um comportamento do gate de embalagem. */
function gate_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Define quantos perfis ativos cada vendor sintético tem. */
function gate_set_profiles( array $by_vendor ): void { $GLOBALS['gate_test_profiles'] = $by_vendor; }

/** Define os vendors sintéticos devolvidos por get_users(). */
function gate_set_users( array $ids ): void {
	$GLOBALS['gate_test_users'] = array_map( static fn( int $id ): WP_User => new WP_User( $id ), $ids );
}

/** Liga ou desliga o gate pelo filtro de configuração. */
function gate_set_enabled( bool $enabled ): void { $GLOBALS['gate_test_enabled'] = $enabled; }

echo "Scenario 1: elegibilidade é a contagem de caixas ativas contra o mínimo\n";
gate_set_profiles( array( GATE_TEST_ELIGIBLE_VENDOR_ID => 3, GATE_TEST_SHORT_VENDOR_ID => 2, GATE_TEST_EMPTY_VENDOR_ID => 0 ) );
gate_assert( 'o mínimo do marketplace é três caixas', 3 === PAPELITO_PACKAGING_MIN_ACTIVE_PROFILES );
gate_assert( 'vendor com três caixas é elegível', papelito_packaging_vendor_is_eligible( GATE_TEST_ELIGIBLE_VENDOR_ID ) );
gate_assert( 'vendor com duas caixas não é elegível', ! papelito_packaging_vendor_is_eligible( GATE_TEST_SHORT_VENDOR_ID ) );
gate_assert( 'vendor sem caixa não é elegível', ! papelito_packaging_vendor_is_eligible( GATE_TEST_EMPTY_VENDOR_ID ) );
gate_assert( 'a contagem devolve inteiro', 2 === papelito_packaging_active_profile_count( GATE_TEST_SHORT_VENDOR_ID ) );

echo "Scenario 2: o gate nasce desligado\n";
gate_assert( 'sem configuração explícita o gate está desligado', ! papelito_packaging_profile_gate_enabled() );
gate_set_users( array( GATE_TEST_ELIGIBLE_VENDOR_ID, GATE_TEST_SHORT_VENDOR_ID, GATE_TEST_EMPTY_VENDOR_ID ) );
$cobertura_desligada = papelito_matching_vendor_ids( GATE_TEST_CEP );
gate_assert( 'com o gate desligado ninguém some da cobertura', 3 === count( $cobertura_desligada ) );
gate_assert( 'vendor sem caixa nenhuma continua vendendo', in_array( GATE_TEST_EMPTY_VENDOR_ID, $cobertura_desligada, true ) );

echo "Scenario 3: ligado, o gate tira da cobertura quem está abaixo do mínimo\n";
gate_set_enabled( true );
gate_assert( 'o gate lê a configuração ligada', papelito_packaging_profile_gate_enabled() );
$cobertura_ligada = papelito_matching_vendor_ids( GATE_TEST_CEP );
gate_assert( 'vendor com três caixas permanece na cobertura', in_array( GATE_TEST_ELIGIBLE_VENDOR_ID, $cobertura_ligada, true ) );
gate_assert( 'vendor com duas caixas sai da cobertura', ! in_array( GATE_TEST_SHORT_VENDOR_ID, $cobertura_ligada, true ) );
gate_assert( 'vendor sem caixa sai da cobertura', ! in_array( GATE_TEST_EMPTY_VENDOR_ID, $cobertura_ligada, true ) );

echo "Scenario 4: cair de três para dois devolve à inelegibilidade no mesmo instante\n";
gate_set_profiles( array( GATE_TEST_ELIGIBLE_VENDOR_ID => 2, GATE_TEST_SHORT_VENDOR_ID => 2, GATE_TEST_EMPTY_VENDOR_ID => 0 ) );
gate_assert( 'desativar uma caixa remove o vendor da cobertura', ! in_array( GATE_TEST_ELIGIBLE_VENDOR_ID, papelito_matching_vendor_ids( GATE_TEST_CEP ), true ) );
gate_set_profiles( array( GATE_TEST_ELIGIBLE_VENDOR_ID => 3, GATE_TEST_SHORT_VENDOR_ID => 2, GATE_TEST_EMPTY_VENDOR_ID => 0 ) );

echo "Scenario 5: o gate não substitui as guardas que já existiam\n";
gate_set_users( array( GATE_TEST_ELIGIBLE_VENDOR_ID, GATE_TEST_UNPAID_VENDOR_ID ) );
gate_set_profiles( array( GATE_TEST_ELIGIBLE_VENDOR_ID => 3, GATE_TEST_UNPAID_VENDOR_ID => 5 ) );
$cobertura_pagamento = papelito_matching_vendor_ids( GATE_TEST_CEP );
gate_assert( 'vendor sem recebedor continua fora mesmo com caixas de sobra', ! in_array( GATE_TEST_UNPAID_VENDOR_ID, $cobertura_pagamento, true ) );
gate_assert( 'vendor apto continua dentro', in_array( GATE_TEST_ELIGIBLE_VENDOR_ID, $cobertura_pagamento, true ) );

echo "Scenario 6: a varredura acha quem nunca cadastrou nada\n";
gate_set_users( array( GATE_TEST_ELIGIBLE_VENDOR_ID, GATE_TEST_SHORT_VENDOR_ID, GATE_TEST_EMPTY_VENDOR_ID ) );
gate_set_profiles( array( GATE_TEST_ELIGIBLE_VENDOR_ID => 3, GATE_TEST_SHORT_VENDOR_ID => 2, GATE_TEST_EMPTY_VENDOR_ID => 0 ) );
$GLOBALS['gate_test_actions'] = array();
$simulacao = papelito_packaging_sweep_vendor_eligibility( true );
gate_assert( 'a simulação avalia todos os vendors', 3 === $simulacao['vendors'] );
gate_assert( 'a simulação separa pendentes de elegíveis', 2 === $simulacao['pendentes'] && 1 === $simulacao['elegiveis'] );
gate_assert( 'a simulação não avisa ninguém', 0 === count( $GLOBALS['gate_test_actions'] ) );

$GLOBALS['gate_test_actions'] = array();
$varredura = papelito_packaging_sweep_vendor_eligibility();
gate_assert( 'a varredura real avisa cada vendor avaliado', 3 === count( $GLOBALS['gate_test_actions'] ) );
gate_assert( 'o aviso vai pelo evento de mudança de caixas', 'papelito_vendor_packaging_profiles_changed' === ( $GLOBALS['gate_test_actions'][0]['hook'] ?? '' ) );
gate_assert( 'a varredura é idempotente na contagem', $varredura['pendentes'] === $simulacao['pendentes'] );

if ( $failures > 0 ) {
	echo "FAILED: {$failures}\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
