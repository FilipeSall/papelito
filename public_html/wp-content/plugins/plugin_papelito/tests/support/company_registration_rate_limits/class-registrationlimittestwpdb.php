<?php
/**
 * Fronteira WordPress do teste de cotas de cadastro; não implementa regras de negócio.
 *
 * @package Papelito
 */

/** Fronteira de leitura: tokens conhecidos devolvem uma candidatura ainda sem e-mail confirmado. */
class RegistrationLimitTestWpdb {
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var int
	 */
	public int $reads = 0;

	/** Preserva os argumentos na consulta para a fronteira de leitura da fixture. */
	public function prepare( string $query, mixed ...$args ): string {
		return $query . ' ' . implode( ' ', array_map( 'strval', $args ) );
	}

	/**
	 * Lê a candidatura da fixture somente no formato contratado pelo serviço.
	 *
	 * @throws InvalidArgumentException Se o serviço deixar de solicitar ARRAY_A.
	 */
	public function get_row( string $query, mixed $output = null ): ?array {
		if ( ARRAY_A !== $output ) {
			throw new InvalidArgumentException( 'Formato de leitura inesperado.' );
		}
		++$this->reads;
		foreach ( array( REGISTRATION_LIMIT_TEST_TOKEN, REGISTRATION_LIMIT_TEST_OTHER_TOKEN ) as $token ) {
			if ( str_contains( $query, hash( 'sha256', $token ) ) ) {
				return array(
					'id'                      => 1,
					'resume_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					'application_status'      => 'document_required',
					'is_open'                 => 1,
				);
			}
		}
		return null;
	}
}
