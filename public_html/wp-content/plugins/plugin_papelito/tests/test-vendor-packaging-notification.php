<?php
/**
 * Aviso ao vendor sem o mínimo de caixas cadastradas.
 *
 * O gate de embalagem tira da vitrine quem não cadastrou caixa, e o vendor não
 * tem como adivinhar isso. Notificação e e-mail são a etapa que precede a
 * ativação do gate na ordem obrigatória da BRASPRESS-003.
 *
 * Usage: php tests/test-vendor-packaging-notification.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

const PACKNOTIF_TEST_VENDOR_ID    = 6120;
const PACKNOTIF_TEST_VENDOR_EMAIL = 'loja-sintetica@example.test';
const PACKNOTIF_TEST_STORE_NAME   = 'Papelaria Sintética';
const PACKNOTIF_TEST_FRONTEND     = 'https://papelito.test';
const PACKNOTIF_TEST_CUBAGEM_URL  = 'https://papelito.test/vendor/cubagem';
const PACKNOTIF_TEST_TYPE         = 'vendor_packaging_profiles_pending';
const PACKNOTIF_TEST_SECRET       = 'senha-secreta-do-vendor';
const PACKNOTIF_TEST_CPF          = '39053344705';

/**
 * Usuário WordPress sintético.
 */
class WP_User {
	/**
	 * Cria o usuário com a identidade mínima usada pelos avisos.
	 *
	 * @param int    $id ID do usuário.
	 * @param string $user_email E-mail do usuário.
	 * @param string $display_name Nome exibido.
	 * @param array<int,string> $roles Papéis do usuário.
	 */
	public function __construct( public int $ID = PACKNOTIF_TEST_VENDOR_ID, public string $user_email = PACKNOTIF_TEST_VENDOR_EMAIL, public string $display_name = PACKNOTIF_TEST_STORE_NAME, public array $roles = array( 'seller' ) ) {}
}

/**
 * Erro do WordPress sintético.
 */
class WP_Error {
	/**
	 * Cria o erro com código, mensagem e dados.
	 *
	 * @param string              $code Código do erro.
	 * @param string              $message Mensagem do erro.
	 * @param array<string,mixed> $data Dados do erro.
	 */
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}

	/**
	 * Retorna o código do erro fixture.
	 *
	 * @return string Código.
	 */
	public function get_error_code(): string {
		return $this->code;
	}
}

$GLOBALS['packnotif_rows']       = array();
$GLOBALS['packnotif_queries']    = array();
$GLOBALS['packnotif_mail']       = array();
$GLOBALS['packnotif_profiles']   = 0;

/**
 * Banco falso com índice único de dedupe e contagem de caixas ativas.
 */
class PackNotif_Test_WPDB {
	public string $prefix = 'wp_';

	public string $last_error = '';

	public int $insert_id = 0;

	/**
	 * Aplica os valores na consulta sem executar SQL.
	 *
	 * @param string $query Consulta SQL.
	 * @param mixed  ...$args Valores.
	 * @return string Consulta preenchida.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[dsf]/', (string) $arg, $query, 1 );
		}

		return $query;
	}

	/**
	 * Devolve a contagem de caixas ativas configurada pelo cenário.
	 *
	 * @param string $query Consulta preparada.
	 * @return string Contagem.
	 */
	public function get_var( string $query ): string {
		return (string) $GLOBALS['packnotif_profiles'];
	}

	/**
	 * Insere respeitando o índice único de `dedupe_key` por tabela.
	 *
	 * @param string              $table Tabela alvo.
	 * @param array<string,mixed> $data Linha.
	 * @param array<int,string>   $format Formatos.
	 * @return bool Se a linha entrou.
	 */
	public function insert( string $table, array $data, array $format = array() ): bool {
		$key = $table . '|' . ( $data['dedupe_key'] ?? uniqid( '', true ) );
		if ( isset( $GLOBALS['packnotif_rows'][ $key ] ) ) {
			$this->last_error = 'Duplicate entry for key';
			return false;
		}
		$GLOBALS['packnotif_rows'][ $key ] = $data + array( 'table' => $table );
		$this->last_error                  = '';
		$this->insert_id                   = count( $GLOBALS['packnotif_rows'] );

		return true;
	}

	/**
	 * Guarda o UPDATE de arquivamento para inspeção.
	 *
	 * @param string $query Consulta preparada.
	 * @return int Linhas afetadas.
	 */
	public function query( string $query ): int {
		$GLOBALS['packnotif_queries'][] = $query;

		return 1;
	}

	/**
	 * Não há leitura em lote nos caminhos exercitados.
	 *
	 * @param string $query Consulta preparada.
	 * @param mixed  $output Formato pedido.
	 * @return array<int,array<string,mixed>> Linhas.
	 */
	public function get_results( string $query, mixed $output = null ): array {
		return array();
	}
}

$GLOBALS['wpdb'] = new PackNotif_Test_WPDB();

/** Entrega o usuário sintético do vendor. */
function get_user_by( mixed $field, mixed $value ): mixed { return new WP_User( (int) $value ); }

/** Entrega o nome da loja sintética. */
function get_user_meta( mixed $user_id, mixed $key, mixed $single = false ): mixed { return 'store_name' === $key ? PACKNOTIF_TEST_STORE_NAME : ''; }

/** Registra o e-mail que teria sido enviado. */
function wp_mail( mixed $to, mixed $subject, mixed $message, mixed $headers = array(), mixed $attachments = array() ): bool {
	$GLOBALS['packnotif_mail'][] = array( 'to' => (string) $to, 'subject' => (string) $subject, 'body' => (string) $message );

	return true;
}

/** Devolve a base sintética do frontend. */
function papelito_auth_get_frontend_url(): string { return PACKNOTIF_TEST_FRONTEND; }

/** Sanitiza um e-mail sintético. */
function sanitize_email( mixed $value ): string { return trim( (string) $value ); }

/** Aceita o e-mail sintético. */
function is_email( mixed $value ): bool { return is_string( $value ) && str_contains( $value, '@' ); }

/** Sanitiza um texto sintético. */
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }

/** Sanitiza uma chave sintética. */
function sanitize_key( mixed $value ): string { return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }

/** Codifica um payload sintético. */
function wp_json_encode( mixed $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }

/** Devolve o instante sintético corrente. */
function current_time( mixed $type = '', mixed $gmt = false ): string { return '2026-09-17 12:00:00'; }

/** Nenhum filtro instalado altera o fluxo, exceto onde o cenário manda. */
function apply_filters( mixed $hook, mixed $value ): mixed { return $value; }

/** Registra as actions disparadas. */
function do_action( mixed ...$args ): bool { return true; }

/** Stub de rota REST. */
function register_rest_route( mixed ...$args ): bool { return true; }

/** Identifica erro do WordPress. */
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require_once __DIR__ . '/support/email_presentation_boot.php';
require_once dirname( __DIR__ ) . '/includes/notification_emails.php';
require_once dirname( __DIR__ ) . '/includes/notifications.php';
require_once dirname( __DIR__ ) . '/includes/packaging.php';

$failures = 0;

/** Confere um comportamento do aviso de embalagem. */
function packnotif_assert( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

/** Zera o estado observável entre cenários. */
function packnotif_reset(): void {
	$GLOBALS['packnotif_rows']    = array();
	$GLOBALS['packnotif_queries'] = array();
	$GLOBALS['packnotif_mail']    = array();
}

/** Conta as notificações gravadas na tabela de notificações. */
function packnotif_notifications(): array {
	return array_values(
		array_filter(
			$GLOBALS['packnotif_rows'],
			static fn( array $row ): bool => str_contains( (string) $row['table'], 'notifications' ) && ! str_contains( (string) $row['table'], 'email_log' )
		)
	);
}

echo "Scenario 1: o tipo novo é reconhecido pelo despacho\n";
packnotif_assert( 'a constante do tipo existe', defined( 'PAPELITO_NOTIF_VENDOR_PACKAGING_PROFILES_PENDING' ) );
packnotif_assert( 'o tipo está na allowlist', in_array( PACKNOTIF_TEST_TYPE, papelito_notification_allowed_types(), true ) );

echo "Scenario 2: vendor abaixo do mínimo recebe notificação e e-mail\n";
packnotif_reset();
$GLOBALS['packnotif_profiles'] = 1;
papelito_handle_vendor_packaging_profiles_notification( PACKNOTIF_TEST_VENDOR_ID );
$notificacoes = packnotif_notifications();
packnotif_assert( 'uma notificação foi gravada', 1 === count( $notificacoes ) );
packnotif_assert( 'a notificação é do tipo novo', 1 === count( $notificacoes ) && PACKNOTIF_TEST_TYPE === $notificacoes[0]['type'] );
packnotif_assert( 'a notificação aponta para a página de cubagem', 1 === count( $notificacoes ) && str_contains( (string) $notificacoes[0]['payload'], '/vendor/cubagem' ) );
packnotif_assert( 'um e-mail foi enviado', 1 === count( $GLOBALS['packnotif_mail'] ) );
packnotif_assert( 'o e-mail foi para o vendor', 1 === count( $GLOBALS['packnotif_mail'] ) && PACKNOTIF_TEST_VENDOR_EMAIL === $GLOBALS['packnotif_mail'][0]['to'] );

echo "Scenario 3: o e-mail diz o que fazer e para onde ir\n";
$email = $GLOBALS['packnotif_mail'][0] ?? array( 'subject' => '', 'body' => '' );
packnotif_assert( 'o assunto identifica o produto', str_contains( (string) $email['subject'], 'Papelito' ) );
packnotif_assert( 'o assunto fala de caixas', false !== stripos( (string) $email['subject'], 'caixa' ) );
packnotif_assert( 'o corpo leva para a página de cubagem', str_contains( (string) $email['body'], PACKNOTIF_TEST_CUBAGEM_URL ) );
packnotif_assert( 'o corpo informa quantas caixas já existem contra o mínimo', str_contains( (string) $email['body'], sprintf( '1 de %d', PAPELITO_PACKAGING_MIN_ACTIVE_PROFILES ) ) );
packnotif_assert( 'o corpo diz quantas faltam', str_contains( (string) $email['body'], sprintf( '%d caixas', PAPELITO_PACKAGING_MIN_ACTIVE_PROFILES - 1 ) ) );
packnotif_assert( 'o texto do e-mail está acentuado corretamente', str_contains( (string) $email['body'], 'voc&#234;' ) || str_contains( (string) $email['body'], 'você' ) );
packnotif_assert( 'o corpo diz que a loja sai da vitrine, não que foi bloqueada', false === stripos( (string) $email['body'], 'bloquead' ) );

echo "Scenario 4: repetir o aviso não duplica notificação nem e-mail\n";
papelito_handle_vendor_packaging_profiles_notification( PACKNOTIF_TEST_VENDOR_ID );
packnotif_assert( 'a notificação continua única', 1 === count( packnotif_notifications() ) );
packnotif_assert( 'o e-mail continua único', 1 === count( $GLOBALS['packnotif_mail'] ) );

echo "Scenario 5: atingir o mínimo arquiva o aviso e não manda e-mail\n";
packnotif_reset();
$GLOBALS['packnotif_profiles'] = PAPELITO_PACKAGING_MIN_ACTIVE_PROFILES;
papelito_handle_vendor_packaging_profiles_notification( PACKNOTIF_TEST_VENDOR_ID );
packnotif_assert( 'vendor elegível não gera notificação nova', 0 === count( packnotif_notifications() ) );
packnotif_assert( 'vendor elegível não recebe e-mail', 0 === count( $GLOBALS['packnotif_mail'] ) );
packnotif_assert( 'o aviso aberto é arquivado', 1 === count( $GLOBALS['packnotif_queries'] ) && str_contains( $GLOBALS['packnotif_queries'][0], 'read_at' ) );
packnotif_assert( 'o arquivamento é do tipo certo', 1 === count( $GLOBALS['packnotif_queries'] ) && str_contains( $GLOBALS['packnotif_queries'][0], PACKNOTIF_TEST_TYPE ) );

echo "Scenario 6: vendor inválido não produz nada\n";
packnotif_reset();
$GLOBALS['packnotif_profiles'] = 0;
papelito_handle_vendor_packaging_profiles_notification( 0 );
packnotif_assert( 'vendor zero é ignorado', 0 === count( packnotif_notifications() ) && 0 === count( $GLOBALS['packnotif_mail'] ) );

echo "Scenario 7: o aviso não carrega segredo nem documento\n";
packnotif_reset();
$GLOBALS['packnotif_profiles'] = 0;
papelito_handle_vendor_packaging_profiles_notification( PACKNOTIF_TEST_VENDOR_ID );
$serializado = wp_json_encode( array( packnotif_notifications(), $GLOBALS['packnotif_mail'] ) );
packnotif_assert( 'sem senha no aviso', ! str_contains( $serializado, PACKNOTIF_TEST_SECRET ) );
packnotif_assert( 'sem CPF no aviso', ! str_contains( $serializado, PACKNOTIF_TEST_CPF ) );

if ( $failures > 0 ) {
	echo "FAILED: {$failures}\n";
	exit( 1 );
}
echo "RESULT: all assertions passed\n";
