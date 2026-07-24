<?php
/**
 * Idempotência durável compartilhada das mutações B2B (Fase 1B).
 *
 * Extraído de company_admin_endpoints.php (Fase 1A) para reutilização por toda a superfície de
 * mutação do painel da empresa. O mecanismo é o mesmo: uma tabela durável (papelito_company_idempotency)
 * indexada por (actor_user_id, operation, key_hash) UNIQUE, que:
 *
 *   - primeira vez         → executa e grava (request_hash + resource_id + response_code);
 *   - replay (mesma req)   → NÃO reexecuta; devolve o resultado anterior;
 *   - chave reusada difere → 409 (request_hash não confere, comparado com hash_equals).
 *
 * A durabilidade + o índice UNIQUE seguram requisições concorrentes com a mesma chave (uma
 * insere, a outra colide). Nunca persiste a chave em claro (só o sha256).
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_IDEMPOTENCY_TTL_SECONDS' ) ) {
	define( 'PAPELITO_IDEMPOTENCY_TTL_SECONDS', DAY_IN_SECONDS );
}

/**
 * Deriva o hash canônico de uma requisição (para detectar reuso indevido da chave).
 *
 * @param array<string,mixed> $payload Dados que identificam unicamente a intenção da mutação.
 */
function papelito_company_idempotency_request_hash( array $payload ): string {
	ksort( $payload );

	return hash( 'sha256', (string) wp_json_encode( $payload ) );
}

/**
 * Verifica o estado de idempotência de uma operação.
 *
 * @return array{error:WP_Error}|array{resource_id:int,response_code:int}|null
 *   - array com 'error'                  → chave ausente/mismatch (retornar ao cliente);
 *   - array com resource_id/response_code → replay (devolver resposta anterior);
 *   - null                               → primeira vez (seguir para execução).
 */
function papelito_company_idempotency_check( int $actor_user_id, string $operation, string $key, string $request_hash ): ?array {
	if ( '' === trim( $key ) ) {
		return array( 'error' => new WP_Error( 'papelito_b2b_idempotency_required', 'Idempotency-Key obrigatório.', array( 'status' => 400 ) ) );
	}

	global $wpdb;
	$tables   = papelito_company_table_names();
	$key_hash = hash( 'sha256', $key );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT request_hash, resource_id, response_code FROM {$tables['idempotency']} WHERE actor_user_id = %d AND operation = %s AND key_hash = %s", $actor_user_id, $operation, $key_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( ! is_array( $row ) ) {
		return null;
	}

	if ( ! hash_equals( (string) $row['request_hash'], $request_hash ) ) {
		return array( 'error' => new WP_Error( 'papelito_b2b_idempotency_mismatch', 'Chave reutilizada com requisição diferente.', array( 'status' => 409 ) ) );
	}

	return array(
		'resource_id'   => (int) $row['resource_id'],
		'response_code' => (int) $row['response_code'],
	);
}

/**
 * Persiste o resultado de uma mutação idempotente. Seguro contra corrida: o UNIQUE
 * (actor_user_id, operation, key_hash) garante um único vencedor entre requisições concorrentes.
 */
function papelito_company_idempotency_store( int $actor_user_id, string $operation, string $key, string $request_hash, int $resource_id, int $response_code ): void {
	global $wpdb;
	$tables = papelito_company_table_names();

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT IGNORE INTO {$tables['idempotency']} ( actor_user_id, operation, key_hash, request_hash, resource_id, response_code, created_at, expires_at ) VALUES ( %d, %s, %s, %s, %d, %d, %s, %s )",
			$actor_user_id,
			$operation,
			hash( 'sha256', $key ),
			$request_hash,
			$resource_id,
			$response_code,
			current_time( 'mysql', true ),
			gmdate( 'Y-m-d H:i:s', time() + PAPELITO_IDEMPOTENCY_TTL_SECONDS )
		)
	);
}
