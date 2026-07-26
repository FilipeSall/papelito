<?php
/**
 * Providers de pre-postagem dos Correios.
 *
 * O provider real permanece deliberadamente sem implementacao ate que o
 * OpenAPI autorizado do contrato seja fornecido. O mock nunca e registrado
 * em producao e nunca realiza chamadas de rede.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/** Contrato interno para providers de pre-postagem. */
interface Papelito_Correios_Prepostage_Adapter {
	/** Retorna a saude do provider sem criar objetos. */
	public function health(): array;

	/** Cria uma pre-postagem. */
	public function create( $order, int $vendor_id );

	/** Recupera ou reemite um rotulo existente. */
	public function get_or_regenerate_label( array $shipment );

	/** Reconcilia uma tentativa cujo resultado externo e incerto. */
	public function reconcile( array $attempt );

	/** Cancela uma pre-postagem ainda nao postada. */
	public function cancel( array $shipment );
}

/** Le uma configuracao sem registrar ou imprimir segredos. */
function papelito_correios_prepostage_config( string $name, string $default = '' ): string {
	if ( defined( $name ) ) {
		return trim( (string) constant( $name ) );
	}

	$value = getenv( $name );
	return false === $value ? $default : trim( (string) $value );
}

/** Retorna o modo efetivo: disabled, mock ou real. */
function papelito_correios_prepostage_mode(): string {
	$mode = sanitize_key( papelito_correios_prepostage_config( 'PAPELITO_CORREIOS_PREPOST_MODE', 'disabled' ) );
	return in_array( $mode, array( 'disabled', 'mock', 'real' ), true ) ? $mode : 'disabled';
}

/** Retorna o ambiente WordPress normalizado. */
function papelito_correios_prepostage_environment(): string {
	if ( function_exists( 'wp_get_environment_type' ) ) {
		return sanitize_key( wp_get_environment_type() );
	}

	return defined( 'WP_ENVIRONMENT_TYPE' ) ? sanitize_key( (string) WP_ENVIRONMENT_TYPE ) : 'production';
}

/** Permite recursos de teste somente em ambientes explicitamente locais. */
function papelito_correios_prepostage_is_test_environment(): bool {
	return in_array( papelito_correios_prepostage_environment(), array( 'local', 'development' ), true );
}

/** Alias mantido para compatibilidade com testes e extensoes existentes. */
function papelito_correios_prepostage_is_production(): bool {
	return 'production' === papelito_correios_prepostage_environment();
}

/** Flag explicita para o fallback de cadastro manual pelo vendor. */
function papelito_correios_manual_tracking_enabled(): bool {
	$default = 'disabled' === papelito_correios_prepostage_mode() ? 'true' : 'false';
	$value = strtolower( papelito_correios_prepostage_config( 'PAPELITO_CORREIOS_MANUAL_TRACKING_ENABLED', $default ) );
	return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
}

/** Cria um erro estruturado e seguro para a API/UI. */
function papelito_correios_prepostage_error( string $code, string $message, int $status, string $category, bool $retryable = false, string $creation_outcome = 'not_created' ): WP_Error {
	return new WP_Error(
		$code,
		$message,
		array(
			'status'           => $status,
			'category'         => sanitize_key( $category ),
			'retryable'        => $retryable,
			'creation_outcome' => sanitize_key( $creation_outcome ),
		)
	);
}

/** Retorna a fonte de saude usada pelo provider mock. */
function papelito_correios_dev_health_source(): string {
	$source = sanitize_key( papelito_correios_prepostage_config( 'PAPELITO_CORREIOS_DEV_HEALTH_SOURCE', 'mock' ) );
	return in_array( $source, array( 'mock', 'real' ), true ) ? $source : 'mock';
}

/** Detecta flags de desenvolvimento configuradas fora da allowlist segura. */
function papelito_correios_dev_flags_forbidden(): bool {
	if ( papelito_correios_prepostage_is_test_environment() ) {
		return false;
	}

	foreach ( array( 'PAPELITO_CORREIOS_DEV_HEALTH_SOURCE', 'PAPELITO_CORREIOS_DEV_HEALTH_SCENARIO', 'PAPELITO_CORREIOS_DEV_TRACKING_SCENARIO', 'PAPELITO_CORREIOS_DEV_ALLOW_REAL_TRACKING' ) as $name ) {
		if ( '' !== papelito_correios_prepostage_config( $name ) ) {
			return true;
		}
	}

	return false;
}

/** Informa se a geracao automatica pode ser oferecida pela interface. */
function papelito_correios_prepostage_readiness() {
	$mode = papelito_correios_prepostage_mode();
	if ( 'disabled' === $mode ) {
		return papelito_correios_prepostage_error(
			'papelito_correios_integration_not_configured',
			'A geracao automatica de etiquetas ainda nao foi configurada. Use o fluxo manual ou fale com o suporte.',
			503,
			'not_configured'
		);
	}

	if ( papelito_correios_dev_flags_forbidden() || ( 'mock' === $mode && ! papelito_correios_prepostage_is_test_environment() ) ) {
		return papelito_correios_prepostage_error(
			'papelito_correios_mock_forbidden_outside_local',
			'O provider de testes foi bloqueado neste ambiente.',
			503,
			'configuration_error'
		);
	}

	if ( 'real' === $mode && function_exists( 'has_filter' ) && false === has_filter( 'papelito_correios_generate_prepostage' ) ) {
		return papelito_correios_prepostage_error(
			'papelito_correios_provider_not_implemented',
			'A integracao de Pre-Postagem ainda nao foi conectada ao contrato dos Correios.',
			503,
			'not_configured'
		);
	}

	return true;
}

/** Calcula o digito verificador S10 a partir dos oito digitos seriais. */
function papelito_correios_s10_check_digit( string $serial ): int {
	$weights = array( 8, 6, 4, 2, 3, 5, 9, 7 );
	$sum     = 0;
	for ( $index = 0; $index < 8; ++$index ) {
		$sum += absint( $serial[ $index ] ?? 0 ) * $weights[ $index ];
	}
	$digit = 11 - ( $sum % 11 );
	if ( 10 === $digit ) {
		return 0;
	}
	if ( 11 === $digit ) {
		return 5;
	}
	return $digit;
}

/** Gera um S10 deterministico e sintaticamente valido, sem validade postal. */
function papelito_correios_mock_tracking_code( int $order_id, int $vendor_id ): string {
	$number = hexdec( substr( hash( 'sha256', 'papelito-local|' . $order_id . '|' . $vendor_id ), 0, 7 ) ) % 100000000;
	$serial = str_pad( (string) $number, 8, '0', STR_PAD_LEFT );
	return 'FA' . $serial . papelito_correios_s10_check_digit( $serial ) . 'BR';
}

/** Request unico para health check real, sem retry e sem operacao postal. */
function papelito_correios_dev_health_request( string $method, string $url, array $args = array() ) {
	$args['method']  = strtoupper( $method );
	$args['timeout'] = min( 8, max( 1, absint( $args['timeout'] ?? 8 ) ) );
	$response        = wp_safe_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return array( 'status' => 'unknown', 'reason' => 'network' );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	return array( 'http_status' => $status, 'body' => is_array( $body ) ? $body : array() );
}

/** Verifica autenticacao e autorizacao 86720 sem criar uma pre-postagem. */
function papelito_correios_dev_health_real(): array {
	if ( ! function_exists( 'papelito_correios_credentials' ) || ! function_exists( 'papelito_correios_base_url' ) ) {
		return array( 'status' => 'unhealthy', 'reason' => 'integration_missing' );
	}
	$credentials = papelito_correios_credentials();
	if ( is_wp_error( $credentials ) ) {
		return array( 'status' => 'unhealthy', 'reason' => 'credentials_missing' );
	}

	$fingerprint = hash_hmac( 'sha256', implode( '|', array( $credentials['access_code'], $credentials['username'] ) ), wp_salt( 'auth' ) );
	$cache_key   = 'papelito_correios_dev_health_' . md5( implode( '|', array( papelito_correios_prepostage_environment(), 'real', $credentials['environment'], $credentials['contract'], $credentials['posting_card'], $fingerprint ) ) );
	$cached      = get_transient( $cache_key );
	if ( is_array( $cached ) && isset( $cached['status'] ) ) {
		return $cached;
	}

	$lock_key = $cache_key . '_lock';
	if ( ! add_option( $lock_key, time(), '', false ) ) {
		$locked_at = absint( get_option( $lock_key, 0 ) );
		if ( $locked_at > time() - 30 ) {
			return array( 'status' => 'unknown', 'reason' => 'check_in_progress' );
		}
		delete_option( $lock_key );
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			return array( 'status' => 'unknown', 'reason' => 'check_in_progress' );
		}
	}

	$base_url = papelito_correios_base_url( $credentials['environment'] );
	$token_response = papelito_correios_dev_health_request(
		'POST',
		$base_url . 'token/v1/autentica/cartaopostagem',
		array(
			'body'    => wp_json_encode( array( 'numero' => $credentials['posting_card'] ) ),
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['access_code'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		)
	);
	$result = array( 'status' => 'unknown', 'reason' => 'authentication_unavailable' );
	if ( isset( $token_response['http_status'] ) && in_array( $token_response['http_status'], array( 401, 403 ), true ) ) {
		$result = array( 'status' => 'unhealthy', 'reason' => 'credentials_invalid' );
	} elseif ( 200 === ( $token_response['http_status'] ?? 0 ) && ! empty( $token_response['body']['token'] ) ) {
		$token    = $token_response['body'];
		$contract = sanitize_text_field( (string) ( $token['cartaoPostagem']['contrato'] ?? $credentials['contract'] ) );
		$card     = sanitize_text_field( (string) ( $token['cartaoPostagem']['numero'] ?? $credentials['posting_card'] ) );
		$cnpj     = preg_replace( '/\D+/', '', (string) ( $token['cnpj'] ?? '' ) );
		if ( '' === $contract || '' === $card || '' === $cnpj ) {
			$result = array( 'status' => 'unhealthy', 'reason' => 'contract_missing' );
		} else {
			$url = $base_url . 'meucontrato/v1/empresas/' . rawurlencode( $cnpj ) . '/contratos/' . rawurlencode( $contract ) . '/cartoes/' . rawurlencode( $card ) . '/servicos/86720';
			$service_response = papelito_correios_dev_health_request(
				'GET',
				$url,
				array( 'headers' => array( 'Authorization' => 'Bearer ' . sanitize_text_field( (string) $token['token'] ), 'Accept' => 'application/json' ) )
			);
			$service_status = (int) ( $service_response['http_status'] ?? 0 );
			if ( $service_status >= 200 && $service_status < 300 ) {
				$result = array( 'status' => 'healthy', 'reason' => 'service_authorized' );
			} elseif ( in_array( $service_status, array( 401, 403, 404 ), true ) ) {
				$result = array( 'status' => 'unhealthy', 'reason' => 'service_not_authorized' );
			}
		}
	}

	set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
	delete_option( $lock_key );
	return $result;
}

/** Retorna a saude que controla exclusivamente a geracao mock local. */
function papelito_correios_dev_health(): array {
	if ( ! papelito_correios_prepostage_is_test_environment() ) {
		return array( 'status' => 'unhealthy', 'reason' => 'environment_forbidden' );
	}
	if ( 'real' === papelito_correios_dev_health_source() ) {
		return papelito_correios_dev_health_real();
	}

	$scenario = sanitize_key( papelito_correios_prepostage_config( 'PAPELITO_CORREIOS_DEV_HEALTH_SCENARIO', 'healthy' ) );
	return array(
		'source' => 'mock',
		'status' => in_array( $scenario, array( 'healthy', 'unhealthy', 'unknown' ), true ) ? $scenario : 'unknown',
		'reason' => 'configured_scenario',
	);
}

/** Gera um PDF minimo e valido, sempre marcado como sem validade. */
function papelito_correios_mock_pdf( string $prepost_id, string $tracking_code ): string {
	$text    = 'ETIQUETA DE TESTE - SEM VALIDADE | ' . $prepost_id . ' | ' . $tracking_code;
	$text    = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	$stream  = "BT /F1 18 Tf 48 760 Td ({$text}) Tj ET";
	$objects = array(
		'1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
		'2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
		'3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj',
		"4 0 obj << /Length " . strlen( $stream ) . " >> stream\n{$stream}\nendstream endobj",
		'5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> endobj',
	);
	$pdf     = "%PDF-1.4\n";
	$offsets = array( 0 );
	foreach ( $objects as $object ) {
		$offsets[] = strlen( $pdf );
		$pdf      .= $object . "\n";
	}
	$xref = strlen( $pdf );
	$pdf .= "xref\n0 6\n0000000000 65535 f \n";
	for ( $index = 1; $index <= 5; ++$index ) {
		$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $index ] );
	}
	$pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

	return $pdf;
}

/** Adapter local deterministico, sem rede e sem objetos postais reais. */
final class Papelito_Correios_Mock_Prepostage_Adapter implements Papelito_Correios_Prepostage_Adapter {
	/** {@inheritDoc} */
	public function health(): array {
		return array( 'provider' => 'mock', 'status' => 'available', 'external_calls' => false );
	}

	/** {@inheritDoc} */
	public function create( $order, int $vendor_id ) {
		$health = papelito_correios_dev_health();
		if ( 'healthy' !== ( $health['status'] ?? '' ) ) {
			$unknown = 'unknown' === ( $health['status'] ?? '' );
			return papelito_correios_prepostage_error(
				$unknown ? 'papelito_correios_dev_health_unknown' : 'papelito_correios_dev_health_unhealthy',
				$unknown ? 'Nao foi possivel confirmar a saude da integracao no teste local.' : 'A verificacao local indicou que a integracao nao esta disponivel.',
				$unknown ? 503 : 424,
				$unknown ? 'dev_health_unknown' : 'dev_health_unhealthy',
				$unknown
			);
		}

		$scenario = sanitize_key( papelito_correios_prepostage_config( 'PAPELITO_CORREIOS_PREPOST_MOCK_SCENARIO', 'success' ) );
		$errors   = array(
			'400' => array( 'papelito_correios_data_incomplete', 'O pedido nao possui todos os dados obrigatorios para a etiqueta.', 400, 'invalid_order', false ),
			'401' => array( 'papelito_correios_credentials_invalid', 'As credenciais dos Correios precisam ser atualizadas.', 401, 'invalid_credentials', false ),
			'403' => array( 'papelito_correios_service_not_authorized', 'Este acesso nao tem permissao para gerar etiquetas.', 403, 'not_authorized', false ),
			'404' => array( 'papelito_correios_service_not_contracted', 'A API de Pre-Postagem nao esta disponivel para este contrato ou cartao.', 404, 'not_contracted', false ),
			'409' => array( 'papelito_correios_duplicate_attempt', 'Ja existe uma geracao para este pedido.', 409, 'duplicate', false ),
			'422' => array( 'papelito_correios_validation_failed', 'Os Correios rejeitaram os dados da postagem.', 422, 'validation', false ),
			'429' => array( 'papelito_correios_rate_limited', 'Os Correios limitaram temporariamente as solicitacoes. Tente mais tarde.', 429, 'temporarily_unavailable', true ),
			'500' => array( 'papelito_correios_internal_error', 'Os Correios nao conseguiram processar a etiqueta agora.', 500, 'temporarily_unavailable', true ),
			'503' => array( 'papelito_correios_unavailable', 'O servico dos Correios esta temporariamente indisponivel.', 503, 'temporarily_unavailable', true ),
		);
		if ( isset( $errors[ $scenario ] ) ) {
			return papelito_correios_prepostage_error( ...$errors[ $scenario ] );
		}

		$order_id      = is_object( $order ) && method_exists( $order, 'get_id' ) ? absint( $order->get_id() ) : 0;
		$fingerprint   = strtoupper( substr( hash( 'sha256', $order_id . '|' . $vendor_id ), 0, 8 ) );
		$prepost_id    = 'MOCK-PREPOST-' . $order_id . '-' . $fingerprint;
		$tracking_code = papelito_correios_mock_tracking_code( $order_id, $vendor_id );
		$service_code  = is_object( $order ) && method_exists( $order, 'get_meta' )
			? sanitize_text_field( (string) $order->get_meta( '_papelito_shipping_service_code', true ) )
			: '';

		return array(
			'provider'       => 'mock',
			'prepost_id'     => $prepost_id,
			'tracking_code'  => $tracking_code,
			'service_code'   => $service_code,
			'label_contents' => papelito_correios_mock_pdf( $prepost_id, $tracking_code ),
			'is_test'        => true,
		);
	}

	/** {@inheritDoc} */
	public function get_or_regenerate_label( array $shipment ) {
		return papelito_correios_prepostage_error( 'papelito_mock_label_not_regenerated', 'O rotulo mock deve ser recuperado do armazenamento privado.', 409, 'not_supported' );
	}

	/** {@inheritDoc} */
	public function reconcile( array $attempt ) {
		return $attempt;
	}

	/** {@inheritDoc} */
	public function cancel( array $shipment ) {
		return array( 'cancelled' => true, 'external_calls' => false );
	}
}

/** Provider do filtro legado, registrado apenas no modo mock seguro. */
function papelito_correios_mock_generate_prepostage( $current, $order, int $vendor_id ) {
	if ( null !== $current ) {
		return $current;
	}

	$readiness = papelito_correios_prepostage_readiness();
	if ( is_wp_error( $readiness ) ) {
		return $readiness;
	}

	$adapter = new Papelito_Correios_Mock_Prepostage_Adapter();
	return $adapter->create( $order, $vendor_id );
}

if ( 'mock' === papelito_correios_prepostage_mode() && papelito_correios_prepostage_is_test_environment() ) {
	add_filter( 'papelito_correios_generate_prepostage', 'papelito_correios_mock_generate_prepostage', 10, 3 );
}
