<?php
/**
 * Auditoria e contadores dos gateways: eventos sem PII e incidentes localizáveis por ID interno.
 *
 * @package Papelito
 */

// phpcs:ignoreFile -- Standalone test stubs WordPress globals and classes by design.

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

$GLOBALS['obs_options'] = array();
$GLOBALS['obs_admin']   = false;
$GLOBALS['obs_log']     = tempnam( sys_get_temp_dir(), 'obs' );
ini_set( 'error_log', $GLOBALS['obs_log'] );
ini_set( 'display_errors', 'stderr' );

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function add_action( mixed ...$args ): bool { return true; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['obs_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['obs_options'][ $key ] = $value; return true; }
function current_user_can( string $cap ): bool { return $GLOBALS['obs_admin']; }

require_once dirname( __DIR__ ) . '/includes/payment_observability.php';

$failures = 0;

function obs_assert( string $label, bool $condition ): void {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL: {$label}\n";
		return;
	}
	echo "PASS: {$label}\n";
}

$attempt = array(
	'id'                    => 31,
	'order_id'              => 901,
	'vendor_id'             => 40,
	'provider'              => 'mercadopago',
	'environment'           => 'test',
	'configuration_version' => 'cfg-1',
	'fallback_reason'       => '',
	'attempt_ref'           => str_repeat( 'f', 32 ),
	'account_ref'           => '900001',
);

papelito_payment_observe_transition( $attempt, 'creating', 'unknown', array( 'last_error_code' => 'unknown_outcome', 'payer_document' => '52998224725' ) );
$log = (string) file_get_contents( $GLOBALS['obs_log'] );
obs_assert( 'transição fica registrada com IDs internos', false !== strpos( $log, 'attempt=31' ) && false !== strpos( $log, 'order=901' ) && false !== strpos( $log, 'vendor=40' ) && false !== strpos( $log, 'creating->unknown' ) );
obs_assert( 'registro traz provider, ambiente e versão', false !== strpos( $log, 'provider=mercadopago' ) && false !== strpos( $log, 'env=test' ) && false !== strpos( $log, 'cfg=cfg-1' ) );
obs_assert( 'registro não carrega attemptRef, documento nem conta', false === strpos( $log, str_repeat( 'f', 32 ) ) && false === strpos( $log, '52998224725' ) && false === strpos( $log, '900001' ) );
obs_assert( 'criação desconhecida conta em métrica própria', 1 === papelito_payment_metrics_today()['creation_unknown'] );

papelito_payment_observe_transition( array_merge( $attempt, array( 'fallback_reason' => 'preferred_failed_before_creation' ) ), 'not_started', 'awaiting_payment_input' );
obs_assert( 'fallback conta separado da criação desconhecida', 1 === papelito_payment_metrics_today()['fallback'] && 1 === papelito_payment_metrics_today()['creation_unknown'] );

papelito_payment_metrics_increment( 'webhook_rejected' );
papelito_payment_metrics_increment( 'refund_failed' );
papelito_payment_metrics_increment( 'connection_failed' );
$today = papelito_payment_metrics_today();
obs_assert( 'contadores distinguem webhook, estorno e conexão', 1 === $today['webhook_rejected'] && 1 === $today['refund_failed'] && 1 === $today['connection_failed'] );
obs_assert( 'métrica desconhecida é ignorada', ! array_key_exists( 'qualquer', papelito_payment_metrics_today() ) && false === papelito_payment_metrics_increment( 'qualquer' ) );

$GLOBALS['obs_options'][ PAPELITO_PAYMENT_METRICS_OPTION ]['2020-01-01'] = array( 'fallback' => 9 );
papelito_payment_metrics_increment( 'fallback' );
obs_assert( 'contadores antigos são descartados (janela de 14 dias)', ! isset( $GLOBALS['obs_options'][ PAPELITO_PAYMENT_METRICS_OPTION ]['2020-01-01'] ) );

obs_assert( 'métricas só para administradores', is_wp_error( papelito_payment_observability_permission() ) );
$GLOBALS['obs_admin'] = true;
obs_assert( 'administrador acessa métricas', true === papelito_payment_observability_permission() );

unlink( $GLOBALS['obs_log'] );
echo $failures > 0 ? "FAILED: {$failures}\n" : "OK\n";
exit( $failures > 0 ? 1 : 0 );
