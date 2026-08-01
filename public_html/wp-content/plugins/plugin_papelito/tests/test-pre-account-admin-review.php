<?php
/**
 * Cobertura do encaminhamento administrativo de candidaturas pré-conta.
 *
 * Executar: php public_html/wp-content/plugins/plugin_papelito/tests/test-pre-account-admin-review.php
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'PAPELITO_NOTIF_COMPANY_OWNER_REVIEW_PENDING', 'company_owner_review_pending' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {}
function wp_next_scheduled( string $hook, array $args = array() ): bool { return false; }
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): void {}
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): void {}
function current_time( string $type, bool $gmt = false ): string { return '2026-08-01 12:00:00'; }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_textarea_field( string $value ): string { return trim( $value ); }
function sanitize_key( string $value ): string { return strtolower( $value ); }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function papelito_pii_decrypt( string $value ): string { return $value; }
function papelito_notifications_table_name(): string { return 'wp_papelito_notifications'; }
function papelito_company_table_names(): array {
	return array( 'pre_account_applications' => 'wp_papelito_company_pre_account_applications' );
}
$decision_email = array();
function wp_mail( string $to, string $subject, string $body, array $headers ): bool {
	global $decision_email;
	$decision_email = compact( 'to', 'subject', 'body', 'headers' );
	return true;
}

class Papelito_Test_WPDB {
	public string $prefix = 'wp_';
	public string $users = 'wp_users';
	public string $usermeta = 'wp_usermeta';

	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_var( string $query ): int {
		global $existing_notification_id;
		unset( $query );
		return $existing_notification_id;
	}
	public function get_row( string $query, mixed $output = null ): ?array {
		global $decision_application;
		unset( $query, $output );
		return is_array( $decision_application ) ? $decision_application : null;
	}
	public function get_results( string $query, mixed $output = null ): array {
		global $pre_account_list_rows;
		unset( $query, $output );
		return $pre_account_list_rows;
	}
	public function update( string $table, array $data, array $where ): int {
		global $decision_application;
		unset( $table );
		if ( ! is_array( $decision_application ) || (int) $decision_application['id'] !== (int) ( $where['id'] ?? 0 ) ) {
			return 0;
		}
		foreach ( $where as $column => $expected ) {
			if ( 'id' !== $column && ( $decision_application[ $column ] ?? null ) !== $expected ) {
				return 0;
			}
		}
		$decision_application = array_merge( $decision_application, $data );
		return 1;
	}
}
$wpdb = new Papelito_Test_WPDB();
$existing_notification_id = 0;
$decision_application     = null;
$pre_account_list_rows    = array();

$notification_recipients = array( 3, 7, 12 );
$notification_dispatches = array();
$failing_recipient       = 0;

function get_users( array $args ): array {
	global $notification_recipients;
	return $notification_recipients;
}
function user_can( int $user_id, string $capability ): bool {
	unset( $capability );
	return in_array( $user_id, array( 3, 12 ), true );
}
function papelito_dispatch_notification( int $user_id, string $type, array $payload, string $dedupe_key ): int|false {
	global $notification_dispatches, $failing_recipient;
	if ( $failing_recipient === $user_id ) {
		return false;
	}
	$notification_dispatches[] = compact( 'user_id', 'type', 'payload', 'dedupe_key' );
	return count( $notification_dispatches );
}

require __DIR__ . '/../includes/company_pre_account_applications.php';
require __DIR__ . '/../includes/admin_users.php';

$failures = 0;
function admin_review_assert( string $label, mixed $expected, mixed $actual ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n";
}

$application = array(
	'id'                    => 71,
	'legal_name_ciphertext' => 'Empresa de Teste LTDA',
);

admin_review_assert(
	'notifica todos e somente administradores autorizados',
	true,
	papelito_pre_account_application_notify_pending( $application )
);
admin_review_assert( 'duas notificacoes persistidas', 2, count( $notification_dispatches ) );
admin_review_assert( 'destinatario inicial autorizado', 3, $notification_dispatches[0]['user_id'] ?? null );
admin_review_assert( 'destinatario final autorizado', 12, $notification_dispatches[1]['user_id'] ?? null );
admin_review_assert( 'id externo nao revela id numerico isolado', 'pre:71', $notification_dispatches[0]['payload']['applicationId'] ?? null );
admin_review_assert( 'link abre a analise dentro da tabela administrativa', '/admin/users?preAccountApplication=pre%3A71', $notification_dispatches[0]['payload']['href'] ?? null );
admin_review_assert( 'deduplicacao esta vinculada a candidatura', 'pre-account-application:71', $notification_dispatches[0]['dedupe_key'] ?? null );

$notification_dispatches = array();
$failing_recipient       = 12;
admin_review_assert(
	'falha de persistencia da notificacao interrompe encaminhamento',
	false,
	papelito_pre_account_application_notify_pending( $application )
);

$notification_dispatches  = array();
$existing_notification_id = 99;
admin_review_assert(
	'repeticao reconhece notificacao ja persistida sem duplicar',
	true,
	papelito_pre_account_application_notify_pending( $application )
);

papelito_pre_account_application_send_decision_email(
	array(
		'application_status'       => 'rejected',
		'contact_email_ciphertext' => 'candidato@example.test',
		'rejection_reason'         => 'MOTIVO INTERNO CONFIDENCIAL',
	)
);
admin_review_assert(
	'mensagem ao candidato nao revela motivo interno',
	false,
	str_contains( (string) ( $decision_email['body'] ?? '' ), 'MOTIVO INTERNO CONFIDENCIAL' )
);
admin_review_assert(
	'mensagem ao candidato informa nova tentativa',
	true,
	str_contains( (string) ( $decision_email['body'] ?? '' ), 'nova tentativa' )
);

$decision_application = array(
	'id'                       => 72,
	'application_status'       => 'pending_manual_review',
	'is_open'                  => 1,
	'document_storage_key'     => null,
	'contact_email_ciphertext' => 'candidato@example.test',
);
$decision_email = array();
$decision = papelito_pre_account_application_decide( 72, 3, false, 'MOTIVO INTERNO CONFIDENCIAL' );
admin_review_assert( 'reprovacao persiste estado terminal', 'rejected', $decision['status'] ?? null );
admin_review_assert( 'reprovacao fecha candidatura', true, array_key_exists( 'is_open', $decision_application ) && null === $decision_application['is_open'] );
admin_review_assert( 'reprovacao notifica candidato sem motivo interno', false, str_contains( (string) ( $decision_email['body'] ?? '' ), 'MOTIVO INTERNO CONFIDENCIAL' ) );

$pre_account_list_rows = array(
	array(
		'id'                       => 84,
		'contact_email_ciphertext' => 'candidato@example.test',
		'full_name_ciphertext'     => 'Pessoa Candidata',
		'legal_name_ciphertext'    => 'Empresa Candidata LTDA',
		'canonical_cnpj'           => '11222333000181',
		'application_status'       => 'pending_manual_review',
		'review_path'              => 'document_required',
		'document_uploaded_at'     => '2026-08-01 12:00:00',
		'created_at'               => '2026-08-01 11:00:00',
	),
);
$snapshot = papelito_admin_users_get_snapshot(
	array(
		'page'    => 1,
		'perPage' => 1,
		'role'    => 'all',
		'search'  => '',
	)
);
admin_review_assert( 'candidatura aparece na tabela administrativa sem usuario', 'pre:84', $snapshot['rows'][0]['id'] ?? null );
admin_review_assert( 'linha pre-conta informa status sob analise', 'Sob análise', $snapshot['rows'][0]['accountStatusLabel'] ?? null );
admin_review_assert( 'candidatura conta na paginacao administrativa', 1, $snapshot['totalRows'] ?? null );

exit( $failures > 0 ? 1 : 0 );
