<?php
/**
 * Catálogo global de brindes.
 *
 * Brinde é entidade própria, e não produto WooCommerce: não tem preço, SKU,
 * estoque por vendor, categoria nem vitrine. Ele existe para compor Kits — peso
 * e dimensões entram na cotação de frete — e o mesmo registro é reutilizável
 * por vários Kits. A quantidade pertence ao vínculo, não ao brinde.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_MERCHANDISE_REST_NAMESPACE    = 'papelito/v1';
const PAPELITO_MERCHANDISE_NOT_FOUND_MESSAGE = 'Brinde não encontrado.';
const PAPELITO_MERCHANDISE_NAME_MAX_LENGTH   = 160;
const PAPELITO_MERCHANDISE_MAX_DIMENSION_CM  = 100.0;
const PAPELITO_MERCHANDISE_MAX_WEIGHT_KG     = 30.0;
const PAPELITO_MERCHANDISE_DEFAULT_IMAGE     = '/images/categorias/icons/kit.webp';
const PAPELITO_MERCHANDISE_LEGACY_TABLE      = 'papelito_kit_merchandise';

/**
 * Nomes das tabelas do catálogo e do vínculo com Kit.
 *
 * @return array<string,string>
 */
function papelito_merchandise_table_names(): array {
	global $wpdb;

	return array(
		'merchandise' => $wpdb->prefix . 'papelito_merchandise',
		'kit_items'   => $wpdb->prefix . 'papelito_kit_merchandise_items',
	);
}

/**
 * Cria o catálogo e a tabela de vínculo.
 *
 * @return void
 */
function papelito_merchandise_install_tables(): void {
	global $wpdb;

	$tables  = papelito_merchandise_table_names();
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$tables['merchandise']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  image_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  weight DECIMAL(12,4) NOT NULL,
  length DECIMAL(12,2) NOT NULL,
  width DECIMAL(12,2) NOT NULL,
  height DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_name (name)
) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$tables['kit_items']} (
  kit_id BIGINT UNSIGNED NOT NULL,
  merchandise_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  PRIMARY KEY  (kit_id, merchandise_id),
  KEY idx_merchandise (merchandise_id)
) {$charset};"
	);
}

/**
 * Remove a tabela em que o brinde era filho do Kit.
 *
 * O catálogo global a substitui por completo e produção nunca teve brinde
 * cadastrado, então manter a tabela antiga só deixaria duas verdades no schema.
 *
 * @return void
 */
function papelito_merchandise_drop_legacy_table(): void {
	global $wpdb;

	$legacy = $wpdb->prefix . PAPELITO_MERCHANDISE_LEGACY_TABLE;

	$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Lê um brinde do catálogo.
 *
 * @param int $merchandise_id Id do brinde.
 * @return array<string,mixed>|null
 */
function papelito_merchandise_get( int $merchandise_id ): ?array {
	global $wpdb;

	$tables = papelito_merchandise_table_names();
	$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['merchandise']} WHERE id = %d", $merchandise_id ), ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * Catálogo inteiro, ordenado por nome.
 *
 * @return array<int,array<string,mixed>>
 */
function papelito_merchandise_list(): array {
	global $wpdb;

	$tables = papelito_merchandise_table_names();
	$rows   = $wpdb->get_results( "SELECT * FROM {$tables['merchandise']} ORDER BY name ASC, id ASC", ARRAY_A );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Lê vários brindes numa consulta, indexados por id.
 *
 * @param array<int,mixed> $merchandise_ids Ids do catálogo.
 * @return array<int,array<string,mixed>>
 */
function papelito_merchandise_get_many( array $merchandise_ids ): array {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'absint', $merchandise_ids ) ) ) );
	if ( empty( $ids ) ) {
		return array();
	}

	$tables       = papelito_merchandise_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows         = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$tables['merchandise']} WHERE id IN ({$placeholders})", $ids ),
		ARRAY_A
	);

	$indexed = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$indexed[ (int) $row['id'] ] = $row;
	}

	return $indexed;
}

/**
 * Trava as linhas do catálogo para escrita e devolve as que existem.
 *
 * É o que serializa "excluir brinde" e "vincular brinde a Kit": as duas
 * operações passam por aqui dentro da própria transação, então uma espera a
 * outra em vez de o Kit gravar vínculo para uma linha recém-excluída. Sem chave
 * estrangeira física — dbDelta não a mantém —, o lock é a garantia que resta.
 *
 * @param array<int,mixed> $merchandise_ids Ids do catálogo.
 * @return array<int,int> Ids existentes, já travados.
 */
function papelito_merchandise_lock_ids( array $merchandise_ids ): array {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'absint', $merchandise_ids ) ) ) );
	if ( empty( $ids ) ) {
		return array();
	}

	$tables       = papelito_merchandise_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows         = $wpdb->get_col(
		$wpdb->prepare( "SELECT id FROM {$tables['merchandise']} WHERE id IN ({$placeholders}) FOR UPDATE", $ids )
	);

	return array_values( array_map( 'absint', is_array( $rows ) ? $rows : array() ) );
}

/**
 * Kits que usam cada brinde informado.
 *
 * Delega ao módulo de Kits porque quem sabe ler Kit é ele; aqui só se consome o
 * resultado.
 *
 * @param array<int,mixed> $merchandise_ids Ids do catálogo.
 * @return array<int,array<int,array<string,mixed>>>
 */
function papelito_merchandise_usage( array $merchandise_ids ): array {
	if ( ! function_exists( 'papelito_kits_using_merchandise' ) ) {
		return array();
	}

	return papelito_kits_using_merchandise( $merchandise_ids );
}

/**
 * Kits que usam um brinde específico.
 *
 * @param int $merchandise_id Id do brinde.
 * @return array<int,array<string,mixed>>
 */
function papelito_merchandise_kits( int $merchandise_id ): array {
	$usage = papelito_merchandise_usage( array( $merchandise_id ) );

	return $usage[ $merchandise_id ] ?? array();
}

/**
 * Extrai peso e dimensões do payload, já normalizados.
 *
 * @param array<string,mixed> $payload Payload administrativo.
 * @return array<string,float>
 */
function papelito_merchandise_physical_values( array $payload ): array {
	$read = static fn( $value ): float => (float) wc_format_decimal( (string) $value );

	return array(
		'weight' => $read( $payload['weight'] ?? 0 ),
		'length' => $read( $payload['length'] ?? 0 ),
		'width'  => $read( $payload['width'] ?? 0 ),
		'height' => $read( $payload['height'] ?? 0 ),
	);
}

/**
 * Conta caracteres respeitando acentuação quando mbstring existe.
 *
 * @param string $value Texto.
 * @return int
 */
function papelito_merchandise_text_length( string $value ): int {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
}

/**
 * Valida o payload de escrita de um brinde.
 *
 * @param array<string,mixed> $payload Payload administrativo.
 * @return array<string,mixed>|WP_Error Colunas prontas para escrita.
 */
function papelito_merchandise_validate_payload( array $payload ) {
	$name          = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
	$attachment_id = absint( $payload['imageAttachmentId'] ?? 0 );
	$physical      = papelito_merchandise_physical_values( $payload );

	if ( '' === $name || papelito_merchandise_text_length( $name ) > PAPELITO_MERCHANDISE_NAME_MAX_LENGTH ) {
		return new WP_Error( 'papelito_merchandise_name_invalid', 'Informe um nome de até 160 caracteres para o brinde.', array( 'status' => 422 ) );
	}
	if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
		return new WP_Error( 'papelito_merchandise_image_required', 'Envie uma imagem do brinde antes de salvar.', array( 'status' => 422 ) );
	}
	if ( $physical['weight'] <= 0 || $physical['weight'] > PAPELITO_MERCHANDISE_MAX_WEIGHT_KG ) {
		return new WP_Error( 'papelito_merchandise_weight_invalid', 'O peso do brinde precisa ser positivo e no máximo 30 kg.', array( 'status' => 422 ) );
	}
	foreach ( array( 'length', 'width', 'height' ) as $field ) {
		if ( $physical[ $field ] <= 0 || $physical[ $field ] > PAPELITO_MERCHANDISE_MAX_DIMENSION_CM ) {
			return new WP_Error( 'papelito_merchandise_dimensions_invalid', 'Comprimento, largura e altura do brinde precisam ser positivos e no máximo 100 cm.', array( 'status' => 422 ) );
		}
	}

	return array_merge(
		array(
			'name'                => $name,
			'image_attachment_id' => $attachment_id,
		),
		$physical
	);
}

/**
 * Formatos de escrita das colunas do catálogo, na ordem da validação.
 *
 * @return array<int,string>
 */
function papelito_merchandise_write_formats(): array {
	return array( '%s', '%d', '%f', '%f', '%f', '%f' );
}

/**
 * URL da imagem do brinde, com o mesmo fallback usado pelos Kits.
 *
 * @param array<string,mixed> $row Linha do catálogo.
 * @return string
 */
function papelito_merchandise_image_url( array $row ): string {
	$url = wp_get_attachment_image_url( absint( $row['image_attachment_id'] ?? 0 ), 'thumbnail' );

	return is_string( $url ) && '' !== $url ? $url : PAPELITO_MERCHANDISE_DEFAULT_IMAGE;
}

/**
 * Resposta administrativa de um brinde.
 *
 * @param array<string,mixed>             $row  Linha do catálogo.
 * @param array<int,array<string,mixed>>  $kits Kits que usam o brinde.
 * @return array<string,mixed>
 */
function papelito_merchandise_response( array $row, array $kits = array() ): array {
	return array(
		'id'                => (int) $row['id'],
		'name'              => sanitize_text_field( (string) $row['name'] ),
		'imageAttachmentId' => (int) $row['image_attachment_id'],
		'imageUrl'          => papelito_merchandise_image_url( $row ),
		'weight'            => (string) $row['weight'],
		'length'            => (string) $row['length'],
		'width'             => (string) $row['width'],
		'height'            => (string) $row['height'],
		'kits'              => array_values( $kits ),
		'kitCount'          => count( $kits ),
	);
}

/**
 * Cria um brinde no catálogo.
 *
 * @param array<string,mixed> $payload Payload administrativo.
 * @return array<string,mixed>|WP_Error
 */
function papelito_merchandise_create( array $payload ) {
	global $wpdb;

	$validated = papelito_merchandise_validate_payload( $payload );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	$tables   = papelito_merchandise_table_names();
	$inserted = $wpdb->insert( $tables['merchandise'], $validated, papelito_merchandise_write_formats() );
	if ( ! $inserted ) {
		return new WP_Error( 'papelito_merchandise_write_failed', 'Não foi possível salvar o brinde.', array( 'status' => 500 ) );
	}

	$row = papelito_merchandise_get( (int) $wpdb->insert_id );

	return is_array( $row ) ? $row : new WP_Error( 'papelito_merchandise_write_failed', 'Não foi possível salvar o brinde.', array( 'status' => 500 ) );
}

/**
 * Kits afetados por uma alteração física e quais deixariam de poder ficar publicados.
 *
 * @param int                 $merchandise_id Id do brinde.
 * @param array<string,float> $physical       Valores propostos.
 * @return array{affectedKits:array<int,array<string,mixed>>,breakingKits:array<int,array<string,mixed>>}
 */
function papelito_merchandise_change_impact( int $merchandise_id, array $physical ): array {
	if ( ! function_exists( 'papelito_kits_merchandise_change_impact' ) ) {
		return array(
			'affectedKits' => array(),
			'breakingKits' => array(),
		);
	}

	return papelito_kits_merchandise_change_impact( $merchandise_id, $physical );
}

/**
 * Diz se a alteração muda algum atributo que entra na logística do Kit.
 *
 * Só peso e dimensões mudam frete e publicação; nome e imagem não têm como
 * invalidar Kit nenhum, então não devem exigir confirmação do admin.
 *
 * @param array<string,mixed> $current   Linha atual.
 * @param array<string,mixed> $validated Colunas validadas.
 * @return bool
 */
function papelito_merchandise_changes_logistics( array $current, array $validated ): bool {
	foreach ( array( 'weight', 'length', 'width', 'height' ) as $field ) {
		if ( (float) $current[ $field ] !== (float) $validated[ $field ] ) {
			return true;
		}
	}

	return false;
}

/**
 * Atualiza um brinde do catálogo.
 *
 * A alteração é global por definição. Quando ela derruba Kits publicados das
 * regras de logística, o pedido só passa com `confirmImpact`: o admin precisa
 * ver a lista antes de um erro de digitação despublicar catálogo.
 *
 * @param int                 $merchandise_id Id do brinde.
 * @param array<string,mixed> $payload        Payload administrativo.
 * @return array<string,mixed>|WP_Error
 */
function papelito_merchandise_update( int $merchandise_id, array $payload ) {
	global $wpdb;

	$current = papelito_merchandise_get( $merchandise_id );
	if ( ! $current ) {
		return new WP_Error( 'papelito_merchandise_not_found', PAPELITO_MERCHANDISE_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$validated = papelito_merchandise_validate_payload( $payload );
	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	$touches_logistics = papelito_merchandise_changes_logistics( $current, $validated );
	$impact            = $touches_logistics
		? papelito_merchandise_change_impact( $merchandise_id, $validated )
		: array(
			'affectedKits' => array(),
			'breakingKits' => array(),
		);

	// Estritamente `true`: a string "false" e o número 1 são truthy em PHP, e
	// um cliente desatento não pode despublicar catálogo por acidente de tipo.
	$confirmed = true === ( $payload['confirmImpact'] ?? false );

	if ( ! empty( $impact['breakingKits'] ) && ! $confirmed ) {
		return new WP_Error(
			'papelito_merchandise_impact_confirmation_required',
			'Esta alteração faz Kits publicados deixarem de atender às regras de logística. Confirme para continuar.',
			array(
				'status' => 409,
				'impact' => $impact,
			)
		);
	}

	$tables  = papelito_merchandise_table_names();
	$updated = $wpdb->update(
		$tables['merchandise'],
		$validated,
		array( 'id' => $merchandise_id ),
		papelito_merchandise_write_formats(),
		array( '%d' )
	);
	if ( false === $updated ) {
		return new WP_Error( 'papelito_merchandise_write_failed', 'Não foi possível salvar o brinde.', array( 'status' => 500 ) );
	}

	$row = papelito_merchandise_get( $merchandise_id );
	if ( ! is_array( $row ) ) {
		return new WP_Error( 'papelito_merchandise_write_failed', 'Não foi possível salvar o brinde.', array( 'status' => 500 ) );
	}

	papelito_merchandise_release_replaced_image( $current, $validated );

	$settlement = $touches_logistics
		? papelito_merchandise_settle_kits( $impact['breakingKits'] )
		: array(
			'unpublished' => array(),
			'failed'      => array(),
		);

	if ( $touches_logistics && function_exists( 'papelito_kits_invalidate_public_cache' ) ) {
		papelito_kits_invalidate_public_cache();
	}

	return array(
		'merchandise'     => $row,
		'unpublishedKits' => $settlement['unpublished'],
		'failedKits'      => $settlement['failed'],
	);
}

/**
 * Solta a imagem substituída quando nada mais a referencia.
 *
 * Roda depois do UPDATE: com a linha já apontando para a imagem nova, a antiga
 * deixa de ter referência aqui e a guarda de mídia decide o resto. Sem isto,
 * cada troca de foto deixaria um anexo órfão no WordPress.
 *
 * @param array<string,mixed> $current   Linha antes da escrita.
 * @param array<string,mixed> $validated Colunas gravadas.
 * @return void
 */
function papelito_merchandise_release_replaced_image( array $current, array $validated ): void {
	$previous_id = absint( $current['image_attachment_id'] ?? 0 );

	if ( $previous_id > 0 && $previous_id !== absint( $validated['image_attachment_id'] ?? 0 ) ) {
		papelito_merchandise_release_attachment( $previous_id );
	}
}

/**
 * Despublica os Kits que a alteração quebrou e relata os que resistiram.
 *
 * Recebe a lista que a projeção de impacto já calculou, em vez de reconsultar o
 * uso e reavaliar Kit por Kit: quem quebrou já é sabido, e reavaliar quem não
 * quebrou seria trabalho por definição inútil.
 *
 * `papelito_kit_demote_outcome()` separa "não precisava" de "precisava e a
 * escrita falhou". O contrato promete rascunho; quando não cumprimos, o Kit vai
 * para `failed` e quem administra precisa saber, em vez de receber sucesso.
 *
 * @param array<int,array<string,mixed>> $breaking_kits Kits que deixaram de cotar.
 * @return array{unpublished:array<int,array<string,mixed>>,failed:array<int,array<string,mixed>>}
 */
function papelito_merchandise_settle_kits( array $breaking_kits ): array {
	$unpublished = array();
	$failed      = array();

	if ( ! function_exists( 'papelito_kit_get' ) || ! function_exists( 'papelito_kit_demote_outcome' ) ) {
		return array(
			'unpublished' => $unpublished,
			'failed'      => $failed,
		);
	}

	foreach ( $breaking_kits as $kit_reference ) {
		$kit = papelito_kit_get( (int) ( $kit_reference['kitId'] ?? 0 ) );
		if ( ! is_array( $kit ) ) {
			continue;
		}
		$outcome = papelito_kit_demote_outcome( $kit );
		if ( 'demoted' === $outcome ) {
			$unpublished[] = $kit_reference;
		} elseif ( 'failed' === $outcome ) {
			$failed[] = $kit_reference;
		}
	}

	return array(
		'unpublished' => $unpublished,
		'failed'      => $failed,
	);
}


/**
 * Exclui um brinde do catálogo.
 *
 * Brinde em uso não é excluído: o vínculo é a razão de ele existir, e apagá-lo
 * mudaria silenciosamente o frete dos Kits que o carregam.
 *
 * @param int $merchandise_id Id do brinde.
 * @return array<string,mixed>|WP_Error
 */
function papelito_merchandise_delete( int $merchandise_id ) {
	global $wpdb;

	$row = papelito_merchandise_get( $merchandise_id );
	if ( ! $row ) {
		return new WP_Error( 'papelito_merchandise_not_found', PAPELITO_MERCHANDISE_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$tables = papelito_merchandise_table_names();
	$wpdb->query( 'START TRANSACTION' );

	// A checagem de uso e a remoção precisam ver o mesmo estado: sem o lock, um
	// Kit sendo salvo em paralelo grava o vínculo entre uma e outra e fica
	// apontando para um brinde que já não existe.
	if ( empty( papelito_merchandise_lock_ids( array( $merchandise_id ) ) ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_merchandise_not_found', PAPELITO_MERCHANDISE_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	$kits = papelito_merchandise_kits( $merchandise_id );
	if ( ! empty( $kits ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error(
			'papelito_merchandise_in_use',
			sprintf(
				'Este brinde é usado em %d %s e não pode ser excluído. Remova-o desses Kits primeiro.',
				count( $kits ),
				1 === count( $kits ) ? 'Kit' : 'Kits'
			),
			array(
				'status' => 409,
				'kits'   => array_values( $kits ),
			)
		);
	}

	if ( 1 !== $wpdb->delete( $tables['merchandise'], array( 'id' => $merchandise_id ), array( '%d' ) ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'papelito_merchandise_delete_failed', 'Não foi possível excluir o brinde.', array( 'status' => 500 ) );
	}

	$wpdb->query( 'COMMIT' );

	return array(
		'deleted'       => true,
		'merchandiseId' => $merchandise_id,
		'imageDeleted'  => papelito_merchandise_release_attachment( absint( $row['image_attachment_id'] ) ),
	);
}

/**
 * Apaga um anexo que o catálogo largou, quando nada mais o referencia.
 *
 * Roda sempre depois de a linha já não apontar para ele — exclusão do brinde ou
 * troca de imagem —, senão o próprio registro contaria como referência e a mídia
 * ficaria órfã para sempre.
 *
 * @param int $attachment_id Id do anexo.
 * @return bool
 */
function papelito_merchandise_release_attachment( int $attachment_id ): bool {
	if ( $attachment_id <= 0 || ! function_exists( 'papelito_admin_media_cleanup_referenced' ) ) {
		return false;
	}
	if ( papelito_admin_media_cleanup_referenced( $attachment_id ) ) {
		return false;
	}

	return (bool) wp_delete_attachment( $attachment_id, true );
}

/**
 * Só administrador escreve no catálogo de brindes.
 *
 * @return true|WP_Error
 */
function papelito_merchandise_require_admin() {
	return current_user_can( 'manage_options' ) ? true : new WP_Error( 'papelito_merchandise_forbidden', 'Acesso administrativo necessário.', array( 'status' => 403 ) );
}

/**
 * Lista o catálogo com o uso de cada brinde resolvido em lote.
 *
 * @return WP_REST_Response
 */
function papelito_merchandise_admin_list() {
	$rows  = papelito_merchandise_list();
	$usage = papelito_merchandise_usage( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );

	$items = array();
	foreach ( $rows as $row ) {
		$items[] = papelito_merchandise_response( $row, $usage[ (int) $row['id'] ] ?? array() );
	}

	return new WP_REST_Response( array( 'items' => $items ), 200 );
}

/**
 * Lê um brinde.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_merchandise_admin_get( WP_REST_Request $request ) {
	$merchandise_id = absint( $request['id'] );
	$row            = papelito_merchandise_get( $merchandise_id );

	if ( ! $row ) {
		return new WP_Error( 'papelito_merchandise_not_found', PAPELITO_MERCHANDISE_NOT_FOUND_MESSAGE, array( 'status' => 404 ) );
	}

	return new WP_REST_Response( array( 'merchandise' => papelito_merchandise_response( $row, papelito_merchandise_kits( $merchandise_id ) ) ), 200 );
}

/**
 * Cria um brinde.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_merchandise_admin_create( WP_REST_Request $request ) {
	$row = papelito_merchandise_create( (array) $request->get_json_params() );

	if ( is_wp_error( $row ) ) {
		return $row;
	}

	return new WP_REST_Response( array( 'merchandise' => papelito_merchandise_response( $row ) ), 201 );
}

/**
 * Atualiza um brinde.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_merchandise_admin_update( WP_REST_Request $request ) {
	$result = papelito_merchandise_update( absint( $request['id'] ), (array) $request->get_json_params() );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$merchandise_id = (int) $result['merchandise']['id'];

	return new WP_REST_Response(
		array(
			'merchandise'     => papelito_merchandise_response( $result['merchandise'], papelito_merchandise_kits( $merchandise_id ) ),
			'unpublishedKits' => $result['unpublishedKits'],
			'failedKits'      => $result['failedKits'],
		),
		200
	);
}

/**
 * Exclui um brinde não utilizado.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_merchandise_admin_delete( WP_REST_Request $request ) {
	$result = papelito_merchandise_delete( absint( $request['id'] ) );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			PAPELITO_MERCHANDISE_REST_NAMESPACE,
			'/admin/merchandise',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_merchandise_require_admin',
					'callback'            => 'papelito_merchandise_admin_list',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'papelito_merchandise_require_admin',
					'callback'            => 'papelito_merchandise_admin_create',
				),
			)
		);
		register_rest_route(
			PAPELITO_MERCHANDISE_REST_NAMESPACE,
			'/admin/merchandise/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'papelito_merchandise_require_admin',
					'callback'            => 'papelito_merchandise_admin_get',
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => 'papelito_merchandise_require_admin',
					'callback'            => 'papelito_merchandise_admin_update',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => 'papelito_merchandise_require_admin',
					'callback'            => 'papelito_merchandise_admin_delete',
				),
			)
		);
	}
);
