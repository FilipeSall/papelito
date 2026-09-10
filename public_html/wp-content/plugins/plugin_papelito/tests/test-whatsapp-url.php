<?php
/** Link de WhatsApp do vendor: número real, normalizado, e nunca um wa.me sem número. */

// support.php tem guard de ABSPATH e sairia em silêncio se fosse carregado antes dele.
define( 'ABSPATH', dirname( __DIR__ ) );
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/support/chamado_env.php';
require_once __DIR__ . '/../includes/vendor_messaging.php';

const TELEFONE_VENDOR = '(61) 99973-3064';
const WHATSAPP_VENDOR = 'https://wa.me/5561999733064';

$GLOBALS['papelito_test_phones'] = array(
	CHAMADO_TEST_VENDOR_ID   => TELEFONE_VENDOR,
	CHAMADO_TEST_STRANGER_ID => '',
);

papelito_test_as( CHAMADO_TEST_CUSTOMER_ID );
$GLOBALS['wpdb']->thread = papelito_test_thread();

$como_cliente = papelito_messaging_thread_detail( papelito_test_thread(), CHAMADO_TEST_CUSTOMER_ID );

papelito_test_as( CHAMADO_TEST_VENDOR_ID );
$como_vendor = papelito_messaging_thread_detail( papelito_test_thread(), CHAMADO_TEST_VENDOR_ID );

papelito_test_as( CHAMADO_TEST_ADMIN_ID );
$como_admin = papelito_messaging_thread_detail( papelito_test_thread(), CHAMADO_TEST_ADMIN_ID );

$GLOBALS['papelito_test_phones'][ CHAMADO_TEST_VENDOR_ID ] = '';
papelito_test_as( CHAMADO_TEST_CUSTOMER_ID );
$sem_telefone = papelito_messaging_thread_detail( papelito_test_thread(), CHAMADO_TEST_CUSTOMER_ID );

$codigo = '';
foreach ( token_get_all( file_get_contents( __DIR__ . '/../includes/vendor_messaging.php' ) ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}
	$codigo .= is_array( $token ) ? $token[1] : $token;
}
$sumario = substr( $codigo, strpos( $codigo, 'function papelito_messaging_map_thread_summary' ) );
$sumario = substr( $sumario, 0, strpos( $sumario, 'function papelito_messaging_thread_detail' ) );

papelito_test_report(
	array(
		'celular mascarado vira número internacional' =>
			WHATSAPP_VENDOR === papelito_whatsapp_url( TELEFONE_VENDOR ),
		'não duplica o código do país' =>
			WHATSAPP_VENDOR === papelito_whatsapp_url( '+55 (61) 99973-3064' )
			&& WHATSAPP_VENDOR === papelito_whatsapp_url( '5561999733064' ),
		'fixo de 10 dígitos é aceito' =>
			'https://wa.me/551132211234' === papelito_whatsapp_url( '(11) 3221-1234' ),
		'DDD 55 não perde o código do país' =>
			'https://wa.me/555532211234' === papelito_whatsapp_url( '(55) 3221-1234' ),
		'sem telefone não gera URL' =>
			null === papelito_whatsapp_url( '' )
			&& null === papelito_whatsapp_url( '1234' )
			&& null === papelito_whatsapp_url( 'abc' ),
		'usuário sem telefone cadastrado não gera URL' =>
			null === papelito_user_whatsapp_url( CHAMADO_TEST_STRANGER_ID ),
		'o texto pré-preenchido é escapado' =>
			WHATSAPP_VENDOR . '?text=Ol%C3%A1%21%20Falo%20sobre%20o%20pedido%20%2314094%20na%20Papelito.'
			=== papelito_whatsapp_url( TELEFONE_VENDOR, 'Olá! Falo sobre o pedido #14094 na Papelito.' ),
		'o comprador recebe o WhatsApp do vendor do chamado' =>
			WHATSAPP_VENDOR === substr( (string) ( $como_cliente['participants']['seller']['whatsapp_url'] ?? '' ), 0, strlen( WHATSAPP_VENDOR ) ),
		'o texto cita o pedido do chamado' =>
			str_contains( (string) ( $como_cliente['participants']['seller']['whatsapp_url'] ?? '' ), '%2314094' ),
		'a Papelito também recebe o link' =>
			null !== ( $como_admin['participants']['seller']['whatsapp_url'] ?? null ),
		'o vendor não recebe link para si mesmo' =>
			! array_key_exists( 'whatsapp_url', $como_vendor['participants']['seller'] ),
		'vendor sem telefone não vira link quebrado' =>
			array_key_exists( 'whatsapp_url', $sem_telefone['participants']['seller'] )
			&& null === $sem_telefone['participants']['seller']['whatsapp_url'],
		'a listagem não consulta telefone por linha' => ! str_contains( $sumario, 'whatsapp' ),
	)
);
