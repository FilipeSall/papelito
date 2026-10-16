<?php
/**
 * Evidência de CNAE do vendor, obtida exclusivamente da BrasilAPI.
 *
 * Alimenta a política financeira que restringe a Pagar.me para tabacaria. Só uma resposta BrasilAPI
 * com código principal e lista secundária verificáveis vira evidência `verified`; CNPJ.ws e
 * ReceitaWS servem ao cadastro, mas não provam ausência de CNAE bloqueado.
 *
 * A regra de ouro: falha nunca vira permissão. Timeout, 429, 5xx, 404, resposta incompleta ou
 * código malformado deixam a evidência pendente; falha de renovação não apaga a evidência anterior
 * — um bloqueio confirmado continua visível mesmo com a BrasilAPI fora do ar. Evidência vencida
 * aparece como `expired`, com os códigos preservados.
 *
 * O que este módulo não faz: não decide gateway (isso é `vendor_payment_policy.php`), não consulta
 * por produto nem por render de painel, e não guarda QSA nem o CNPJ em claro na evidência.
 *
 * @package Papelito
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_CNAE_EVIDENCE_META    = 'papelito_vendor_cnae_evidence';
const PAPELITO_VENDOR_CNAE_EVIDENCE_VERSION = 1;
const PAPELITO_VENDOR_CNAE_REFRESH_HOOK     = 'papelito_vendor_cnae_refresh_due';

/**
 * Validade da evidência positiva, em segundos.
 *
 * @return int
 */
function papelito_vendor_cnae_valid_seconds(): int {
	return 7 * DAY_IN_SECONDS;
}

/**
 * Intervalo mínimo entre consultas depois de indisponibilidade, em segundos.
 *
 * @return int
 */
function papelito_vendor_cnae_unavailable_backoff(): int {
	return 10 * MINUTE_IN_SECONDS;
}

/**
 * Normaliza um código CNAE para sete dígitos.
 *
 * Devolve `''` para o placeholder zero (a BrasilAPI manda `codigo: 0` quando não há secundário) e
 * `null` para código malformado, que o chamador precisa tratar como evidência incompleta.
 *
 * @param mixed $value Código cru.
 * @return string|null
 */
function papelito_vendor_cnae_normalize_code( mixed $value ): ?string {
	if ( is_int( $value ) ) {
		$value = (string) $value;
	}

	if ( ! is_string( $value ) ) {
		return null;
	}

	$digits = preg_replace( '/[\s.\/-]+/', '', trim( $value ) );

	if ( '' === $digits || 1 !== preg_match( '/^\d+$/', $digits ) ) {
		return null;
	}

	if ( 0 === (int) $digits ) {
		return '';
	}

	if ( 6 === strlen( $digits ) ) {
		$digits = '0' . $digits;
	}

	return 7 === strlen( $digits ) ? $digits : null;
}

/**
 * Extrai principal e secundários de uma resposta BrasilAPI.
 *
 * `complete` só é verdadeiro com principal válido, lista secundária presente e nenhum código
 * malformado. Lista vazia explícita (ou só com o placeholder zero) é válida.
 *
 * @param array<string,mixed> $body Corpo da BrasilAPI.
 * @return array{primary:?string,secondary:?array<int,string>,complete:bool}
 */
function papelito_vendor_cnae_extract( array $body ): array {
	$primary = array_key_exists( 'cnae_fiscal', $body ) ? papelito_vendor_cnae_normalize_code( $body['cnae_fiscal'] ) : null;
	$primary = '' === $primary ? null : $primary;

	$secondary = null;
	$malformed = false;

	if ( isset( $body['cnaes_secundarios'] ) && is_array( $body['cnaes_secundarios'] ) ) {
		$secondary = array();
		foreach ( $body['cnaes_secundarios'] as $entry ) {
			$code = is_array( $entry ) && array_key_exists( 'codigo', $entry ) ? papelito_vendor_cnae_normalize_code( $entry['codigo'] ) : null;
			if ( null === $code ) {
				$malformed = true;
				continue;
			}
			if ( '' !== $code && ! in_array( $code, $secondary, true ) ) {
				$secondary[] = $code;
			}
		}
	}

	return array(
		'primary'   => $primary,
		'secondary' => $secondary,
		'complete'  => null !== $primary && null !== $secondary && ! $malformed,
	);
}

/**
 * Impressão digital do CNPJ, para ligar a evidência ao documento sem guardá-lo em claro.
 *
 * @param string $cnpj CNPJ só com dígitos.
 * @return string
 */
function papelito_vendor_cnae_fingerprint( string $cnpj ): string {
	return hash_hmac( 'sha256', $cnpj, wp_salt( 'papelito_vendor_cnae' ) );
}

/**
 * CNPJ do cadastro autoritativo do vendor, só dígitos.
 *
 * @param int $vendor_id Vendor.
 * @return string Vazio quando ausente ou fora do formato numérico.
 */
function papelito_vendor_cnae_vendor_cnpj( int $vendor_id ): string {
	$digits = preg_replace( '/\D+/', '', (string) get_user_meta( $vendor_id, 'cnpj', true ) );

	return 14 === strlen( (string) $digits ) ? (string) $digits : '';
}

/**
 * Monta a evidência a partir de um resultado de lookup normalizado.
 *
 * @param string              $fingerprint Impressão digital do CNPJ.
 * @param array<string,mixed> $lookup      Resultado do adapter ou do cache BrasilAPI.
 * @param int                 $now         Instante da consulta.
 * @return array<string,mixed>
 */
function papelito_vendor_cnae_build_evidence( string $fingerprint, array $lookup, int $now ): array {
	$evidence = array(
		'version'             => PAPELITO_VENDOR_CNAE_EVIDENCE_VERSION,
		'fingerprint'         => $fingerprint,
		'source'              => 'brasilapi',
		'status'              => 'pending',
		'reason'              => '',
		'primary'             => null,
		'secondary'           => array(),
		'checked_at'          => $now,
		'valid_until'         => 0,
		'last_attempt_at'     => $now,
		'last_attempt_status' => 'pending',
	);

	$status = (string) ( $lookup['status'] ?? '' );
	$cnaes  = is_array( $lookup['cnaes'] ?? null ) ? $lookup['cnaes'] : array();

	if ( 'unavailable' === $status ) {
		$evidence['status'] = 'unavailable';
		$evidence['reason'] = 'brasilapi_unavailable';
	} elseif ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
		$evidence['reason'] = '' !== $status ? $status : 'lookup_failed';
	} elseif ( 'brasilapi' !== ( $lookup['source'] ?? '' ) ) {
		$evidence['reason'] = 'source_not_brasilapi';
	} elseif ( empty( $cnaes['complete'] ) ) {
		$evidence['reason'] = 'incomplete_cnaes';
	} else {
		$evidence['status']      = 'verified';
		$evidence['primary']     = (string) $cnaes['primary'];
		$evidence['secondary']   = array_values( (array) $cnaes['secondary'] );
		$evidence['valid_until'] = $now + papelito_vendor_cnae_valid_seconds();
	}

	$evidence['last_attempt_status'] = $evidence['status'];

	return $evidence;
}

/**
 * Evidência gravada, sem interpretação de validade.
 *
 * @param int $vendor_id Vendor.
 * @return array<string,mixed>|null
 */
function papelito_vendor_cnae_stored( int $vendor_id ): ?array {
	$stored = get_user_meta( $vendor_id, PAPELITO_VENDOR_CNAE_EVIDENCE_META, true );

	return is_array( $stored ) && PAPELITO_VENDOR_CNAE_EVIDENCE_VERSION === (int) ( $stored['version'] ?? 0 ) ? $stored : null;
}

/**
 * Evidência aplicável agora ao CNPJ atual do vendor.
 *
 * `pending` sem códigos quando nunca foi consultada ou quando o CNPJ mudou; `expired` (com os
 * códigos) quando a validade passou. Só `verified` autoriza classificar como permitido.
 *
 * @param int      $vendor_id Vendor.
 * @param int|null $now       Instante de referência.
 * @return array<string,mixed>
 */
function papelito_vendor_cnae_current( int $vendor_id, ?int $now = null ): array {
	$now      = $now ?? time();
	$cnpj     = papelito_vendor_cnae_vendor_cnpj( $vendor_id );
	$evidence = papelito_vendor_cnae_stored( $vendor_id );

	if ( '' === $cnpj ) {
		return papelito_vendor_cnae_placeholder( 'cnpj_missing' );
	}

	if ( null === $evidence ) {
		return papelito_vendor_cnae_placeholder( 'never_checked' );
	}

	if ( ! hash_equals( (string) $evidence['fingerprint'], papelito_vendor_cnae_fingerprint( $cnpj ) ) ) {
		return papelito_vendor_cnae_placeholder( 'cnpj_changed' );
	}

	if ( 'verified' === $evidence['status'] && $now > (int) $evidence['valid_until'] ) {
		$evidence['status'] = 'expired';
		$evidence['reason'] = 'evidence_expired';
	}

	return $evidence;
}

/**
 * Evidência vazia com um motivo, para quando não há o que aplicar.
 *
 * @param string $reason Código do motivo.
 * @return array<string,mixed>
 */
function papelito_vendor_cnae_placeholder( string $reason ): array {
	return array(
		'version'     => PAPELITO_VENDOR_CNAE_EVIDENCE_VERSION,
		'fingerprint' => '',
		'source'      => 'brasilapi',
		'status'      => 'pending',
		'reason'      => $reason,
		'primary'     => null,
		'secondary'   => array(),
		'checked_at'  => 0,
		'valid_until' => 0,
	);
}

/**
 * Resultado BrasilAPI para o CNPJ: do cache quando já traz CNAEs completos, senão da rede.
 *
 * Cache legado sem CNAEs, ou preenchido por outro provider, é renovado — nunca promovido.
 *
 * @param string $cnpj CNPJ só com dígitos.
 * @return array<string,mixed>
 */
function papelito_vendor_cnae_lookup( string $cnpj ): array {
	$cache_key = PAPELITO_CNPJ_CACHE_PREFIX . $cnpj;
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && 'brasilapi' === ( $cached['source'] ?? '' ) && ! empty( $cached['cnaes']['complete'] ) ) {
		return $cached;
	}

	$result = papelito_cnpj_adapter_brasilapi( $cnpj );

	if ( in_array( $result['status'] ?? '', array( 'active', 'inactive' ), true ) && function_exists( 'papelito_cnpj_cache_result' ) ) {
		papelito_cnpj_cache_result( $cache_key, $result );
	}

	unset( $result['qsa'] );

	return $result;
}

/**
 * Reconsulta a evidência do vendor e devolve a aplicável.
 *
 * Respeita o backoff de indisponibilidade. Resultado não verificado não substitui evidência
 * verificada do mesmo CNPJ: só registra a tentativa.
 *
 * @param int      $vendor_id Vendor.
 * @param int|null $now       Instante da consulta.
 * @return array<string,mixed>
 */
function papelito_vendor_cnae_refresh( int $vendor_id, ?int $now = null ): array {
	$now  = $now ?? time();
	$cnpj = papelito_vendor_cnae_vendor_cnpj( $vendor_id );

	if ( '' === $cnpj ) {
		return papelito_vendor_cnae_placeholder( 'cnpj_missing' );
	}

	$fingerprint = papelito_vendor_cnae_fingerprint( $cnpj );
	$previous    = papelito_vendor_cnae_stored( $vendor_id );
	$same_cnpj   = null !== $previous && hash_equals( (string) $previous['fingerprint'], $fingerprint );

	if ( $same_cnpj && papelito_vendor_cnae_in_backoff( $previous, $now ) ) {
		return papelito_vendor_cnae_current( $vendor_id, $now );
	}

	$fresh = papelito_vendor_cnae_build_evidence( $fingerprint, papelito_vendor_cnae_lookup( $cnpj ), $now );

	if ( 'verified' !== $fresh['status'] && $same_cnpj && 'verified' === ( $previous['status'] ?? '' ) ) {
		$previous['last_attempt_at']     = $now;
		$previous['last_attempt_status'] = $fresh['status'];
		$fresh                           = $previous;
	}

	update_user_meta( $vendor_id, PAPELITO_VENDOR_CNAE_EVIDENCE_META, $fresh );

	if ( function_exists( 'papelito_vendor_payment_policy_evidence_changed' ) ) {
		papelito_vendor_payment_policy_evidence_changed( $vendor_id );
	}

	return papelito_vendor_cnae_current( $vendor_id, $now );
}

/**
 * Diz se a última tentativa foi indisponibilidade recente demais para repetir.
 *
 * @param array<string,mixed> $evidence Evidência gravada.
 * @param int                 $now      Instante atual.
 * @return bool
 */
function papelito_vendor_cnae_in_backoff( array $evidence, int $now ): bool {
	return 'unavailable' === ( $evidence['last_attempt_status'] ?? '' )
		&& ( $now - (int) ( $evidence['last_attempt_at'] ?? 0 ) ) < papelito_vendor_cnae_unavailable_backoff();
}

/**
 * Invalida a evidência quando o CNPJ do vendor muda.
 *
 * A comparação de impressão digital já impede reuso; apagar evita guardar evidência órfã.
 *
 * @param mixed $meta_id  ID do meta (ignorado).
 * @param mixed $user_id  Usuário alterado.
 * @param mixed $meta_key Chave alterada.
 * @return void
 */
function papelito_vendor_cnae_on_cnpj_change( mixed $meta_id, mixed $user_id, mixed $meta_key ): void {
	if ( 'cnpj' !== $meta_key ) {
		return;
	}

	delete_user_meta( (int) $user_id, PAPELITO_VENDOR_CNAE_EVIDENCE_META );

	if ( function_exists( 'papelito_vendor_payment_policy_evidence_changed' ) ) {
		papelito_vendor_payment_policy_evidence_changed( (int) $user_id );
	}
}

add_action( 'updated_user_meta', 'papelito_vendor_cnae_on_cnpj_change', 10, 3 );
add_action( 'added_user_meta', 'papelito_vendor_cnae_on_cnpj_change', 10, 3 );

/**
 * Renovação programada: só vendors que ingressaram na política, com evidência a vencer em 1 dia.
 *
 * Limitada a dez consultas por execução para respeitar os termos da BrasilAPI.
 *
 * @return void
 */
function papelito_vendor_cnae_refresh_due_vendors(): void {
	if ( ! function_exists( 'get_users' ) || ! defined( 'PAPELITO_VENDOR_PAYMENT_POLICY_ENFORCED_META' ) ) {
		return;
	}

	$vendor_ids = get_users(
		array(
			'meta_key' => PAPELITO_VENDOR_PAYMENT_POLICY_ENFORCED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'fields'   => 'ID',
			'number'   => 200,
		)
	);
	$now        = time();
	$budget     = 10;

	foreach ( $vendor_ids as $vendor_id ) {
		if ( $budget <= 0 ) {
			return;
		}

		$current = papelito_vendor_cnae_current( (int) $vendor_id, $now );
		if ( 'verified' === $current['status'] && (int) $current['valid_until'] > $now + DAY_IN_SECONDS ) {
			continue;
		}

		papelito_vendor_cnae_refresh( (int) $vendor_id, $now );
		--$budget;
	}
}

add_action( PAPELITO_VENDOR_CNAE_REFRESH_HOOK, 'papelito_vendor_cnae_refresh_due_vendors' );

/**
 * Agenda a renovação diária.
 *
 * @return void
 */
function papelito_vendor_cnae_schedule_refresh(): void {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( PAPELITO_VENDOR_CNAE_REFRESH_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', PAPELITO_VENDOR_CNAE_REFRESH_HOOK );
	}
}

add_action( 'init', 'papelito_vendor_cnae_schedule_refresh' );
