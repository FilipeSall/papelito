<?php
/**
 * Fronteira WordPress do teste de cotas de cadastro; não implementa regras de negócio.
 *
 * @package Papelito
 */

/** Resposta REST cujo status pode ser inspecionado sem serializar HTTP. */
class WP_REST_Response {
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var mixed
	 */
	private mixed $data;
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var int
	 */
	private int $status;
	/** Inicializa os valores recebidos pela fronteira WordPress. */
	public function __construct( mixed $data, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	/** Devolve o status definido pelo callback REST real. */
	public function get_status(): int {
		return $this->status;
	}
	/** Devolve o corpo definido pelo callback REST real. */
	public function get_data(): mixed {
		return $this->data;
	}
}
