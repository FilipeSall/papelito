<?php
/**
 * Relatorios administrativos Papelito.
 *
 * @package Papelito
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metadados do relatorio de usuarios cadastrados.
 *
 * @return array<string, string>
 */
function papelito_admin_reports_users_definition(): array {
	return array(
		'key'     => 'users-registered-v1',
		'label'   => 'Usuários cadastrados v1',
		'version' => 'v1',
		'output'  => 'xlsx',
	);
}

/**
 * Metadados do relatorio de vendas.
 *
 * @return array<string, string>
 */
function papelito_admin_reports_sales_definition(): array {
	return array(
		'key'     => 'sales-orders-v1',
		'label'   => 'Vendas WooCommerce v1',
		'version' => 'v1',
		'output'  => 'xlsx',
	);
}

/**
 * Valor padrao de data para os filtros.
 *
 * @param string $modifier Modificador DateTime.
 * @return string
 */
function papelito_admin_reports_default_date( string $modifier ): string {
	try {
		$date = new DateTimeImmutable( $modifier, wp_timezone() );
		return $date->format( 'Y-m-d' );
	} catch ( Exception $exception ) {
		return gmdate( 'Y-m-d' );
	}
}

/**
 * Garante um valor enumerado valido.
 *
 * @param string $value Valor bruto.
 * @param array  $allowed Lista permitida.
 * @param string $fallback Fallback.
 * @return string
 */
function papelito_admin_reports_normalize_enum( string $value, array $allowed, string $fallback ): string {
	return in_array( $value, $allowed, true ) ? $value : $fallback;
}

/**
 * Normaliza filtros da request.
 *
 * @param WP_REST_Request $request Request REST.
 * @return array<string, int|string>
 */
function papelito_admin_reports_parse_users_filters( WP_REST_Request $request ): array {
	$from         = sanitize_text_field( (string) $request->get_param( 'from' ) );
	$to           = sanitize_text_field( (string) $request->get_param( 'to' ) );
	$default_to   = papelito_admin_reports_default_date( 'now' );
	$default_from = papelito_admin_reports_default_date( '-29 days' );
	$valid_date   = '/^\d{4}-\d{2}-\d{2}$/';

	if ( 1 !== preg_match( $valid_date, $from ) ) {
		$from = $default_from;
	}

	if ( 1 !== preg_match( $valid_date, $to ) ) {
		$to = $default_to;
	}

	if ( $from > $to ) {
		$tmp  = $from;
		$from = $to;
		$to   = $tmp;
	}

	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = max( 1, min( 100, (int) $request->get_param( 'perPage' ) ) );

	if ( $per_page <= 0 ) {
		$per_page = 20;
	}

	return array(
		'search'            => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		'role'              => papelito_admin_reports_normalize_enum(
			sanitize_text_field( (string) $request->get_param( 'role' ) ),
			array( 'all', 'administrator', 'customer', 'seller' ),
			'all'
		),
		'state'             => strtoupper( sanitize_text_field( (string) $request->get_param( 'state' ) ) ),
		'city'              => sanitize_text_field( (string) $request->get_param( 'city' ) ),
		'coverage'          => papelito_admin_reports_normalize_enum(
			sanitize_text_field( (string) $request->get_param( 'coverage' ) ),
			array( 'all', 'with_coverage', 'without_coverage' ),
			'all'
		),
		'from'              => $from,
		'to'                => $to,
		'page'              => $page,
		'perPage'           => $per_page,
	);
}

/**
 * Filtros simples de exportacao.
 *
 * @param WP_REST_Request $request Request.
 * @return array<string, string>
 */
function papelito_admin_reports_parse_simple_export_filters( WP_REST_Request $request ): array {
	$from       = sanitize_text_field( (string) $request->get_param( 'from' ) );
	$to         = sanitize_text_field( (string) $request->get_param( 'to' ) );
	$default_to = papelito_admin_reports_default_date( 'now' );
	$valid_date = '/^\d{4}-\d{2}-\d{2}$/';

	if ( 1 !== preg_match( $valid_date, $from ) ) {
		$from = papelito_admin_reports_default_date( '-29 days' );
	}

	if ( 1 !== preg_match( $valid_date, $to ) ) {
		$to = $default_to;
	}

	if ( $from > $to ) {
		$tmp  = $from;
		$from = $to;
		$to   = $tmp;
	}

	return array(
		'format' => papelito_admin_reports_normalize_enum(
			sanitize_text_field( (string) $request->get_param( 'format' ) ),
			array( 'xlsx', 'csv' ),
			'xlsx'
		),
		'from'   => $from,
		'to'     => $to,
	);
}

/**
 * Filtros do export de usuarios.
 *
 * O intervalo aqui recorta `user_registered` (data de cadastro), nao periodo de venda.
 * `role` reaproveita o mesmo vocabulario do relatorio de usuarios ja existente.
 *
 * @param WP_REST_Request $request Request.
 * @return array<string, string>
 */
function papelito_admin_reports_parse_users_export_filters( WP_REST_Request $request ): array {
	$filters         = papelito_admin_reports_parse_simple_export_filters( $request );
	$filters['role'] = papelito_admin_reports_normalize_enum(
		sanitize_text_field( (string) $request->get_param( 'role' ) ),
		array( 'all', 'administrator', 'customer', 'seller' ),
		'all'
	);

	return $filters;
}

/**
 * Filtros do snapshot financeiro administrativo.
 *
 * @param WP_REST_Request $request Request REST.
 * @return array<string, string>
 */
function papelito_admin_reports_parse_sales_snapshot_filters( WP_REST_Request $request ): array {
	$filters = papelito_admin_reports_parse_simple_export_filters( $request );

	return array(
		'from'     => $filters['from'],
		'to'       => $filters['to'],
		'interval' => papelito_admin_reports_normalize_enum(
			sanitize_key( (string) $request->get_param( 'interval' ) ),
			array( 'day', 'month' ),
			'day'
		),
		'segment'  => papelito_admin_reports_normalize_enum(
			sanitize_key( (string) $request->get_param( 'segment' ) ),
			array( 'all', 'discounted', 'refunded' ),
			'all'
		),
	);
}

/**
 * SQL base do relatorio.
 *
 * @return string
 */
function papelito_admin_reports_users_base_sql(): string {
	global $wpdb;

	$users_table    = $wpdb->users;
	$usermeta_table = $wpdb->usermeta;
	$capabilities   = $wpdb->prefix . 'capabilities';

	return "
		FROM {$users_table} u
		LEFT JOIN {$usermeta_table} cap ON cap.user_id = u.ID AND cap.meta_key = '{$capabilities}'
		LEFT JOIN {$usermeta_table} store_name ON store_name.user_id = u.ID AND store_name.meta_key = 'store_name'
		LEFT JOIN {$usermeta_table} phone_number ON phone_number.user_id = u.ID AND phone_number.meta_key = 'phone_number'
		LEFT JOIN {$usermeta_table} cnpj ON cnpj.user_id = u.ID AND cnpj.meta_key = 'cnpj'
		LEFT JOIN {$usermeta_table} state_meta ON state_meta.user_id = u.ID AND state_meta.meta_key = 'state'
		LEFT JOIN {$usermeta_table} city_meta ON city_meta.user_id = u.ID AND city_meta.meta_key = 'city'
		LEFT JOIN {$usermeta_table} first_name ON first_name.user_id = u.ID AND first_name.meta_key = 'first_name'
		LEFT JOIN {$usermeta_table} last_name ON last_name.user_id = u.ID AND last_name.meta_key = 'last_name'
	";
}

/**
 * Clausula WHERE compartilhada do relatorio.
 *
 * @param array<string, int|string> $filters Filtros.
 * @param array<int, mixed>         $args Args preparados.
 * @return string
 */
function papelito_admin_reports_users_where_sql( array $filters, array &$args ): string {
	global $wpdb;

	$conditions = array( '1=1' );

	if ( ! empty( $filters['search'] ) && is_string( $filters['search'] ) ) {
		$term         = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		$conditions[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR store_name.meta_value LIKE %s OR first_name.meta_value LIKE %s OR last_name.meta_value LIKE %s OR cnpj.meta_value LIKE %s)';
		array_push( $args, $term, $term, $term, $term, $term, $term );
	}

	if ( 'all' !== $filters['role'] && is_string( $filters['role'] ) ) {
		$conditions[] = 'cap.meta_value LIKE %s';
		$args[]       = '%"' . $filters['role'] . '"%';
	}

	if ( ! empty( $filters['state'] ) && is_string( $filters['state'] ) ) {
		$conditions[] = 'state_meta.meta_value = %s';
		$args[]       = $filters['state'];
	}

	if ( ! empty( $filters['city'] ) && is_string( $filters['city'] ) ) {
		$conditions[] = 'city_meta.meta_value LIKE %s';
		$args[]       = '%' . $wpdb->esc_like( $filters['city'] ) . '%';
	}

	if ( ! empty( $filters['from'] ) && is_string( $filters['from'] ) ) {
		$conditions[] = 'u.user_registered >= %s';
		$args[]       = $filters['from'] . ' 00:00:00';
	}

	if ( ! empty( $filters['to'] ) && is_string( $filters['to'] ) ) {
		$conditions[] = 'u.user_registered <= %s';
		$args[]       = $filters['to'] . ' 23:59:59';
	}

	$coverage_exists = "EXISTS (
		SELECT 1 FROM {$wpdb->usermeta} coverage_min
		WHERE coverage_min.user_id = u.ID
		AND coverage_min.meta_key = 'min_cep'
		AND coverage_min.meta_value <> ''
	) AND EXISTS (
		SELECT 1 FROM {$wpdb->usermeta} coverage_max
		WHERE coverage_max.user_id = u.ID
		AND coverage_max.meta_key = 'max_cep'
		AND coverage_max.meta_value <> ''
	)";

	if ( 'with_coverage' === $filters['coverage'] ) {
		$conditions[] = $coverage_exists;
	} elseif ( 'without_coverage' === $filters['coverage'] ) {
		$conditions[] = 'NOT (' . $coverage_exists . ')';
	}

	return ' WHERE ' . implode( ' AND ', $conditions );
}

/**
 * Role normalizado a partir do capabilities.
 *
 * @param string $capabilities Valor cru.
 * @return string
 */
function papelito_admin_reports_detect_role( string $capabilities ): string {
	$parsed = maybe_unserialize( $capabilities );

	if ( is_array( $parsed ) ) {
		if ( ! empty( $parsed['administrator'] ) ) {
			return 'administrator';
		}

		if ( ! empty( $parsed['seller'] ) ) {
			return 'seller';
		}

		if ( ! empty( $parsed['customer'] ) ) {
			return 'customer';
		}

		$keys = array_keys( $parsed );
		return ! empty( $keys[0] ) && is_string( $keys[0] ) ? $keys[0] : 'other';
	}

	if ( false !== strpos( $capabilities, '"administrator"' ) ) {
		return 'administrator';
	}

	if ( false !== strpos( $capabilities, '"seller"' ) ) {
		return 'seller';
	}

	if ( false !== strpos( $capabilities, '"customer"' ) ) {
		return 'customer';
	}

	return 'other';
}

/**
 * Label de role.
 *
 * @param string $role Role normalizado.
 * @return string
 */
function papelito_admin_reports_role_label( string $role ): string {
	switch ( $role ) {
		case 'administrator':
			return 'Administrador';
		case 'seller':
			return 'Seller';
		case 'customer':
			return 'Customer';
		default:
			return 'Outro';
	}
}

/**
 * Monta resumo de cobertura por CEP.
 *
 * @param array<int, string> $min_ranges CEPs minimos.
 * @param array<int, string> $max_ranges CEPs maximos.
 * @return string
 */
function papelito_admin_reports_format_coverage_summary( array $min_ranges, array $max_ranges ): string {
	$count = min( count( $min_ranges ), count( $max_ranges ) );

	if ( $count <= 0 ) {
		return 'Sem cobertura';
	}

	$pairs = array();

	for ( $index = 0; $index < $count; $index++ ) {
		$min = sanitize_text_field( (string) $min_ranges[ $index ] );
		$max = sanitize_text_field( (string) $max_ranges[ $index ] );

		if ( '' === $min || '' === $max ) {
			continue;
		}

		$pairs[] = $min . ' -> ' . $max;
	}

	if ( empty( $pairs ) ) {
		return 'Sem cobertura';
	}

	if ( count( $pairs ) > 2 ) {
		return sprintf(
			'%1$s | %2$s | +%3$d faixas',
			$pairs[0],
			$pairs[1],
			count( $pairs ) - 2
		);
	}

	return implode( ' | ', $pairs );
}

/**
 * Enriquece linhas com resumo de cobertura.
 *
 * @param array<int, array<string, mixed>> $rows Linhas.
 * @return array<int, array<string, mixed>>
 */
function papelito_admin_reports_attach_coverage_summary( array $rows ): array {
	global $wpdb;

	if ( empty( $rows ) ) {
		return $rows;
	}

	$user_ids = array_values(
		array_filter(
			array_map(
				static function ( array $row ): int {
					return isset( $row['id'] ) ? (int) $row['id'] : 0;
				},
				$rows
			)
		)
	);

	if ( empty( $user_ids ) ) {
		return $rows;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
	$args         = $user_ids;
	array_unshift( $args, 'min_cep', 'max_cep' );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		"SELECT user_id, meta_key, meta_value
		FROM {$wpdb->usermeta}
		WHERE meta_key IN (%s, %s)
		AND user_id IN ({$placeholders})
		ORDER BY umeta_id ASC",
		$args
	);

	$meta_rows = $wpdb->get_results( $sql, ARRAY_A );
	$coverage  = array();

	foreach ( $meta_rows as $meta_row ) {
		$user_id  = isset( $meta_row['user_id'] ) ? (int) $meta_row['user_id'] : 0;
		$meta_key = isset( $meta_row['meta_key'] ) ? (string) $meta_row['meta_key'] : '';
		$value    = isset( $meta_row['meta_value'] ) ? (string) $meta_row['meta_value'] : '';

		if ( $user_id <= 0 || '' === $meta_key || '' === $value ) {
			continue;
		}

		if ( ! isset( $coverage[ $user_id ] ) ) {
			$coverage[ $user_id ] = array(
				'min_cep' => array(),
				'max_cep' => array(),
			);
		}

		$coverage[ $user_id ][ $meta_key ][] = $value;
	}

	foreach ( $rows as $index => $row ) {
		$user_id    = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$min_ranges = isset( $coverage[ $user_id ]['min_cep'] ) ? $coverage[ $user_id ]['min_cep'] : array();
		$max_ranges = isset( $coverage[ $user_id ]['max_cep'] ) ? $coverage[ $user_id ]['max_cep'] : array();

		$rows[ $index ]['coverageSummary'] = papelito_admin_reports_format_coverage_summary( $min_ranges, $max_ranges );
	}

	return $rows;
}

/**
 * Consulta linhas do relatorio.
 *
 * @param array<string, int|string> $filters Filtros.
 * @param bool                      $for_export Exportacao sem paginacao.
 * @return array<int, array<string, mixed>>
 */
function papelito_admin_reports_query_user_rows( array $filters, bool $for_export = false ): array {
	global $wpdb;

	$args      = array();
	$base_sql  = papelito_admin_reports_users_base_sql();
	$where_sql = papelito_admin_reports_users_where_sql( $filters, $args );
	$select    = "
		SELECT
			u.ID AS id,
			u.display_name AS display_name,
			u.user_email AS user_email,
			u.user_registered AS user_registered,
			COALESCE(cap.meta_value, '') AS capabilities,
			COALESCE(store_name.meta_value, '') AS store_name,
			COALESCE(phone_number.meta_value, '') AS phone_number,
			COALESCE(cnpj.meta_value, '') AS cnpj,
			COALESCE(state_meta.meta_value, '') AS state,
			COALESCE(city_meta.meta_value, '') AS city,
			COALESCE(first_name.meta_value, '') AS first_name,
			COALESCE(last_name.meta_value, '') AS last_name
	";

	$order_limit = ' ORDER BY u.user_registered DESC, u.ID DESC';

	if ( ! $for_export ) {
		$offset       = ( (int) $filters['page'] - 1 ) * (int) $filters['perPage'];
		$order_limit .= ' LIMIT %d OFFSET %d';
		$args[]       = (int) $filters['perPage'];
		$args[]       = $offset;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql      = $wpdb->prepare( $select . $base_sql . $where_sql . $order_limit, $args );
	$raw_rows = $wpdb->get_results( $sql, ARRAY_A );
	$rows     = array();

	foreach ( $raw_rows as $raw_row ) {
		$display_name = isset( $raw_row['display_name'] ) ? trim( (string) $raw_row['display_name'] ) : '';
		$first_name   = isset( $raw_row['first_name'] ) ? trim( (string) $raw_row['first_name'] ) : '';
		$last_name    = isset( $raw_row['last_name'] ) ? trim( (string) $raw_row['last_name'] ) : '';
		$name         = $display_name;

		if ( '' === $name ) {
			$name = trim( $first_name . ' ' . $last_name );
		}

		if ( '' === $name ) {
			$name = isset( $raw_row['user_email'] ) ? (string) $raw_row['user_email'] : 'Usuário sem nome';
		}

		$role = papelito_admin_reports_detect_role( isset( $raw_row['capabilities'] ) ? (string) $raw_row['capabilities'] : '' );
		$rows[] = array(
			'id'                     => isset( $raw_row['id'] ) ? (int) $raw_row['id'] : 0,
			'name'                   => $name,
			'email'                  => isset( $raw_row['user_email'] ) ? (string) $raw_row['user_email'] : '',
			'role'                   => $role,
			'roleLabel'              => papelito_admin_reports_role_label( $role ),
			'storeName'              => isset( $raw_row['store_name'] ) ? (string) $raw_row['store_name'] : '',
			'phoneNumber'            => isset( $raw_row['phone_number'] ) ? (string) $raw_row['phone_number'] : '',
			'cnpj'                   => isset( $raw_row['cnpj'] ) ? (string) $raw_row['cnpj'] : '',
			'state'                  => isset( $raw_row['state'] ) ? (string) $raw_row['state'] : '',
			'city'                   => isset( $raw_row['city'] ) ? (string) $raw_row['city'] : '',
			'registeredAt'           => isset( $raw_row['user_registered'] ) ? (string) $raw_row['user_registered'] : '',
			'coverageSummary'        => 'Sem cobertura',
		);
	}

	return papelito_admin_reports_attach_coverage_summary( $rows );
}

/**
 * Consulta totais do relatorio.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return array<string, int>
 */
function papelito_admin_reports_query_users_summary( array $filters ): array {
	global $wpdb;

	$args      = array();
	$base_sql  = papelito_admin_reports_users_base_sql();
	$where_sql = papelito_admin_reports_users_where_sql( $filters, $args );

	$coverage_exists = "EXISTS (
		SELECT 1 FROM {$wpdb->usermeta} coverage_min
		WHERE coverage_min.user_id = u.ID
		AND coverage_min.meta_key = 'min_cep'
		AND coverage_min.meta_value <> ''
	) AND EXISTS (
		SELECT 1 FROM {$wpdb->usermeta} coverage_max
		WHERE coverage_max.user_id = u.ID
		AND coverage_max.meta_key = 'max_cep'
		AND coverage_max.meta_value <> ''
	)";

	$args[] = '%"seller"%';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		"
		SELECT
			COUNT(*) AS filtered_users,
			SUM(CASE WHEN cap.meta_value LIKE %s THEN 1 ELSE 0 END) AS vendors_count,
			SUM(CASE WHEN {$coverage_exists} THEN 1 ELSE 0 END) AS users_with_coverage
		" . $base_sql . $where_sql,
		$args
	);

	$summary = $wpdb->get_row( $sql, ARRAY_A );

	return array(
		'filteredUsers'      => isset( $summary['filtered_users'] ) ? (int) $summary['filtered_users'] : 0,
		'vendorsCount'       => isset( $summary['vendors_count'] ) ? (int) $summary['vendors_count'] : 0,
		'usersWithCoverage'  => isset( $summary['users_with_coverage'] ) ? (int) $summary['users_with_coverage'] : 0,
	);
}

/**
 * Monta snapshot JSON do relatorio.
 *
 * @param array<string, int|string> $filters Filtros.
 * @return array<string, mixed>
 */
function papelito_admin_reports_get_users_snapshot( array $filters ): array {
	$summary     = papelito_admin_reports_query_users_summary( $filters );
	$total_pages = max( 1, (int) ceil( $summary['filteredUsers'] / max( 1, (int) $filters['perPage'] ) ) );
	$safe_page   = min( max( 1, (int) $filters['page'] ), $total_pages );

	if ( $safe_page !== (int) $filters['page'] ) {
		$filters['page'] = $safe_page;
	}

	$rows = papelito_admin_reports_query_user_rows( $filters, false );

	return array(
		'report'      => papelito_admin_reports_users_definition(),
		'rows'        => $rows,
		'summary'     => $summary,
		'currentPage' => $safe_page,
		'perPage'     => (int) $filters['perPage'],
		'totalRows'   => $summary['filteredUsers'],
		'totalPages'  => $total_pages,
		'issues'      => array(),
	);
}

/**
 * Tenta carregar o autoload do Composer e validar a biblioteca de planilha.
 *
 * @return true|WP_Error
 */
function papelito_admin_reports_require_spreadsheet() {
	$candidates = array(
		dirname( __DIR__ ) . '/vendor/autoload.php',
		dirname( __DIR__, 2 ) . '/vendor/autoload.php',
		dirname( __DIR__, 5 ) . '/vendor/autoload.php',
		dirname( __DIR__, 4 ) . '/vendor/autoload.php',
		ABSPATH . 'vendor/autoload.php',
	);
	$autoload   = '';

	foreach ( $candidates as $candidate ) {
		if ( is_string( $candidate ) && '' !== $candidate && file_exists( $candidate ) ) {
			$autoload = $candidate;
			require_once $candidate;
			break;
		}
	}

	if ( '' === $autoload ) {
		return new WP_Error(
			'papelito_reports_xlsx_autoload_missing',
			'Autoload do Composer não encontrado para exportação XLSX.',
			array(
				'status'     => 500,
				'candidates' => $candidates,
			)
		);
	}

	if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) || ! class_exists( '\PhpOffice\PhpSpreadsheet\Writer\Xlsx' ) ) {
		return new WP_Error(
			'papelito_reports_xlsx_unavailable',
			'Biblioteca PhpSpreadsheet indisponível para exportação XLSX.',
			array(
				'status'   => 500,
				'autoload' => $autoload,
			)
		);
	}

	return true;
}

/**
 * Corrige texto com mojibake comum de UTF-8 lido como Latin-1/Windows-1252.
 *
 * Mantem valores ja saudaveis intactos e aplica o reparo apenas quando
 * encontra marcadores tipicos como "Ã", "Â", "â" ou caractere de substituicao.
 *
 * @param mixed $value Valor cru.
 * @return string
 */
function papelito_admin_reports_normalize_export_text( $value ): string {
	if ( null === $value ) {
		return '';
	}

	$text = (string) $value;

	if ( '' === $text ) {
		return '';
	}

	if ( 1 !== preg_match( '/(?:Ã.|Â.|â.|ð.|�)/u', $text ) ) {
		return $text;
	}

	$decoded = utf8_decode( $text );

	if ( ! is_string( $decoded ) || '' === $decoded ) {
		return $text;
	}

	return $decoded;
}

/**
 * Gera o binario XLSX para as linhas filtradas.
 *
 * @param array<int, array<string, mixed>> $rows Linhas.
 * @return string|WP_Error
 */
function papelito_admin_reports_generate_users_xlsx( array $rows ) {
	$ready = papelito_admin_reports_require_spreadsheet();

	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	try {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Usuários' );
		$sheet->fromArray(
			array(
				'ID',
				'Nome',
				'E-mail',
				'Role',
				'Loja',
				'Telefone',
				'CNPJ',
				'Cidade',
				'Estado',
				'Cobertura CEP',
				'Data de cadastro',
			),
			null,
			'A1'
		);

		$row_index = 2;

		foreach ( $rows as $row ) {
			$sheet->fromArray(
				array(
					isset( $row['id'] ) ? (int) $row['id'] : 0,
					papelito_admin_reports_normalize_export_text( $row['name'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['email'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['roleLabel'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['storeName'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['phoneNumber'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['cnpj'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['city'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['state'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['coverageSummary'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['registeredAt'] ?? '' ),
				),
				null,
				'A' . $row_index
			);
			$row_index++;
		}

		foreach ( range( 'A', 'L' ) as $column ) {
			$sheet->getColumnDimension( $column )->setAutoSize( true );
		}

		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
		ob_start();
		$writer->save( 'php://output' );
		$binary = ob_get_clean();
		$spreadsheet->disconnectWorksheets();
		unset( $spreadsheet );

		return is_string( $binary ) ? $binary : '';
	} catch ( Throwable $exception ) {
		return new WP_Error(
			'papelito_reports_xlsx_failed',
			'Não foi possível gerar o arquivo XLSX: ' . $exception->getMessage(),
			array( 'status' => 500 )
		);
	}
}

/**
 * Linhas simples do export de usuarios.
 *
 * @param array<string, string> $filters Filtros.
 * @return array<int, array<string, string|int>>
 */
function papelito_admin_reports_query_simple_user_rows( array $filters ): array {
	global $wpdb;

	$where = 'WHERE u.user_registered BETWEEN %s AND %s';
	$args  = array(
		$filters['from'] . ' 00:00:00',
		$filters['to'] . ' 23:59:59',
	);

	$role = isset( $filters['role'] ) ? (string) $filters['role'] : 'all';

	if ( 'all' !== $role ) {
		$where .= ' AND capabilities.meta_value LIKE %s';
		$args[] = '%"' . $role . '"%';
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		"
		SELECT
			u.ID AS user_id,
			u.user_login AS username,
			u.user_email AS email,
			COALESCE(billing_phone.meta_value, billing_cellphone.meta_value, phone_number.meta_value) AS phone,
			COALESCE(billing_postcode.meta_value, cep.meta_value) AS postcode,
			city.meta_value AS city,
			COALESCE(state.meta_value, billing_state.meta_value, shipping_state.meta_value) AS state
		FROM {$wpdb->users} AS u
		LEFT JOIN {$wpdb->usermeta} AS capabilities ON u.ID = capabilities.user_id AND capabilities.meta_key = '{$wpdb->prefix}capabilities'
		LEFT JOIN {$wpdb->usermeta} AS billing_phone ON u.ID = billing_phone.user_id AND billing_phone.meta_key = 'billing_phone'
		LEFT JOIN {$wpdb->usermeta} AS billing_cellphone ON u.ID = billing_cellphone.user_id AND billing_cellphone.meta_key = 'billing_cellphone'
		LEFT JOIN {$wpdb->usermeta} AS phone_number ON u.ID = phone_number.user_id AND phone_number.meta_key = 'phone_number'
		LEFT JOIN {$wpdb->usermeta} AS billing_postcode ON u.ID = billing_postcode.user_id AND billing_postcode.meta_key = 'billing_postcode'
		LEFT JOIN {$wpdb->usermeta} AS cep ON u.ID = cep.user_id AND cep.meta_key = 'cep'
		LEFT JOIN {$wpdb->usermeta} AS city ON u.ID = city.user_id AND city.meta_key = 'city'
		LEFT JOIN {$wpdb->usermeta} AS state ON u.ID = state.user_id AND state.meta_key = 'state'
		LEFT JOIN {$wpdb->usermeta} AS billing_state ON u.ID = billing_state.user_id AND billing_state.meta_key = 'billing_state'
		LEFT JOIN {$wpdb->usermeta} AS shipping_state ON u.ID = shipping_state.user_id AND shipping_state.meta_key = 'shipping_state'
		{$where}
		ORDER BY u.user_registered DESC
		",
		$args
	);

	return array_map(
		static function ( array $row ): array {
			return array(
				'user_id'  => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
				'username' => isset( $row['username'] ) ? (string) $row['username'] : '',
				'email'    => isset( $row['email'] ) ? (string) $row['email'] : '',
				'phone'    => isset( $row['phone'] ) ? (string) $row['phone'] : '',
				'postcode' => isset( $row['postcode'] ) ? (string) $row['postcode'] : '',
				'city'     => isset( $row['city'] ) ? (string) $row['city'] : '',
				'state'    => isset( $row['state'] ) ? (string) $row['state'] : '',
			);
		},
		(array) $wpdb->get_results( $sql, ARRAY_A )
	);
}

/**
 * XLSX simples de usuarios.
 *
 * @param array<int, array<string, string|int>> $rows Linhas.
 * @return string|WP_Error
 */
function papelito_admin_reports_generate_simple_users_xlsx( array $rows ) {
	$ready = papelito_admin_reports_require_spreadsheet();

	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	try {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Usuários' );
		$sheet->fromArray(
			array( 'user_id', 'username', 'email', 'phone', 'postcode', 'city', 'state' ),
			null,
			'A1'
		);

		$row_index = 2;
		foreach ( $rows as $row ) {
			$sheet->fromArray(
				array(
					$row['user_id'],
					papelito_admin_reports_normalize_export_text( $row['username'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['email'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['phone'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['postcode'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['city'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['state'] ?? '' ),
				),
				null,
				'A' . $row_index
			);
			$row_index++;
		}

		foreach ( range( 'A', 'G' ) as $column ) {
			$sheet->getColumnDimension( $column )->setAutoSize( true );
		}

		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
		ob_start();
		$writer->save( 'php://output' );
		$binary = ob_get_clean();
		$spreadsheet->disconnectWorksheets();
		unset( $spreadsheet );

		return is_string( $binary ) ? $binary : '';
	} catch ( Throwable $exception ) {
		return new WP_Error(
			'papelito_reports_users_xlsx_failed',
			'Não foi possível gerar o XLSX de usuários: ' . $exception->getMessage(),
			array( 'status' => 500 )
		);
	}
}

/**
 * CSV simples de usuarios.
 *
 * @param array<int, array<string, string|int>> $rows Linhas.
 * @return string
 */
function papelito_admin_reports_generate_simple_users_csv( array $rows ): string {
	$stream = fopen( 'php://temp', 'r+' );

	if ( false === $stream ) {
		return '';
	}

	fwrite( $stream, "\xEF\xBB\xBF" );
	fputcsv( $stream, array( 'user_id', 'username', 'email', 'phone', 'postcode', 'city', 'state' ) );

	foreach ( $rows as $row ) {
		fputcsv(
			$stream,
			array(
				$row['user_id'],
				papelito_admin_reports_normalize_export_text( $row['username'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['email'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['phone'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['postcode'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['city'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['state'] ?? '' ),
			)
		);
	}

	rewind( $stream );
	$csv = stream_get_contents( $stream );
	fclose( $stream );

	return is_string( $csv ) ? $csv : '';
}

/**
 * Busca pedidos criados no período para relatórios administrativos.
 *
 * @param array<string, string> $filters Filtros com from e to.
 * @return array<int, WC_Order>
 */
function papelito_admin_reports_query_sales_orders( array $filters ): array {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$statuses = array_map(
		static function ( string $status ): string {
			return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		},
		array_keys( wc_get_order_statuses() )
	);

	$orders = wc_get_orders(
		array(
			'date_created' => $filters['from'] . '...' . $filters['to'],
			'limit'        => -1,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'return'       => 'objects',
			'status'       => $statuses,
		)
	);

	return array_values(
		array_filter(
			is_array( $orders ) ? $orders : array(),
			static fn( $order ): bool => $order instanceof WC_Order
		)
	);
}

/**
 * Filtra pedidos que contam como venda confirmada.
 *
 * A regra canônica vive no domínio de pedidos do vendor e também considera
 * estados persistidos do pagamento quando o status WooCommerce é desconhecido.
 *
 * @param array<int, WC_Order> $orders Pedidos a filtrar.
 * @return array<int, WC_Order>
 */
function papelito_admin_reports_paid_sales_orders( array $orders ): array {
	return array_values(
		array_filter(
			$orders,
			static fn( WC_Order $order ): bool => papelito_vendor_dashboard_order_is_paid( $order )
		)
	);
}

/**
 * Retorna a chave de agrupamento de receita de um pedido.
 *
 * @param WC_Order $order Pedido WooCommerce.
 * @param string   $interval day|month.
 * @return string
 */
function papelito_admin_reports_sales_bucket( WC_Order $order, string $interval ): string {
	$date = $order->get_date_created();

	if ( ! $date instanceof WC_DateTime ) {
		return '';
	}

	return 'month' === $interval ? $date->date_i18n( 'Y-m' ) : $date->date_i18n( 'Y-m-d' );
}

/**
 * Pedido teve desconto monetario real.
 *
 * A regra canonica vive no dominio de pedidos do vendor; aqui e so a porta de
 * entrada do relatorio administrativo.
 *
 * @param WC_Order $order Pedido.
 * @return bool
 */
function papelito_admin_reports_order_has_discount( WC_Order $order ): bool {
	if ( function_exists( 'papelito_vendor_dashboard_order_has_discount' ) ) {
		return papelito_vendor_dashboard_order_has_discount( $order );
	}

	return (float) $order->get_discount_total() > 0.0;
}

/**
 * Pedido cancelado ou reembolsado, pelos status reais do WooCommerce.
 *
 * @param WC_Order $order Pedido.
 * @return bool
 */
function papelito_admin_reports_order_is_refunded_or_cancelled( WC_Order $order ): bool {
	if ( function_exists( 'papelito_vendor_dashboard_order_is_refunded_or_cancelled' ) ) {
		return papelito_vendor_dashboard_order_is_refunded_or_cancelled( $order );
	}

	$status = sanitize_key( (string) $order->get_status() );

	if ( in_array( $status, array( 'refunded', 'cancelled' ), true ) ) {
		return true;
	}

	return (float) $order->get_total_refunded() > 0.0;
}

/**
 * Recorta os pedidos que compoem o segmento pedido.
 *
 * @param array<int, WC_Order> $all_orders  Todos os pedidos da janela.
 * @param array<int, WC_Order> $paid_orders Pedidos pagos da janela.
 * @param string               $segment     all|discounted|refunded.
 * @return array{scope: array<int, WC_Order>, revenue: array<int, WC_Order>}
 */
function papelito_admin_reports_segment_orders( array $all_orders, array $paid_orders, string $segment ): array {
	if ( 'discounted' === $segment ) {
		$rows = array_values( array_filter( $paid_orders, 'papelito_admin_reports_order_has_discount' ) );

		return array(
			'scope'   => $rows,
			'revenue' => $rows,
		);
	}

	if ( 'refunded' === $segment ) {
		$rows = array_values(
			array_filter( $all_orders, 'papelito_admin_reports_order_is_refunded_or_cancelled' )
		);

		return array(
			'scope'   => $rows,
			'revenue' => $rows,
		);
	}

	return array(
		'scope'   => $all_orders,
		'revenue' => $paid_orders,
	);
}

/**
 * Janela imediatamente anterior, de mesma duracao, para a comparacao de receita.
 *
 * @param string $from Data inicial Y-m-d.
 * @param string $to   Data final Y-m-d.
 * @return array{from: string, to: string}|null
 */
function papelito_admin_reports_previous_window( string $from, string $to ): ?array {
	$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

	try {
		$start = new DateTimeImmutable( $from, $timezone );
		$end   = new DateTimeImmutable( $to, $timezone );
	} catch ( Exception $exception ) {
		return null;
	}

	$days = (int) $start->diff( $end )->days + 1;

	if ( $days < 1 ) {
		return null;
	}

	$previous_end   = $start->modify( '-1 day' );
	$previous_start = $previous_end->modify( '-' . ( $days - 1 ) . ' days' );

	return array(
		'from' => $previous_start->format( 'Y-m-d' ),
		'to'   => $previous_end->format( 'Y-m-d' ),
	);
}

/**
 * Receita bruta da janela anterior, no mesmo segmento.
 *
 * @param array<string, string> $filters Filtros da janela atual.
 * @return float|null
 */
function papelito_admin_reports_previous_gross_revenue( array $filters ): ?float {
	$window = papelito_admin_reports_previous_window( $filters['from'], $filters['to'] );

	if ( null === $window ) {
		return null;
	}

	$all_orders  = papelito_admin_reports_query_sales_orders( $window );
	$paid_orders = papelito_admin_reports_paid_sales_orders( $all_orders );
	$segmented   = papelito_admin_reports_segment_orders(
		$all_orders,
		$paid_orders,
		(string) ( $filters['segment'] ?? 'all' )
	);

	$total = 0.0;

	foreach ( $segmented['revenue'] as $order ) {
		$total += max( 0.0, (float) $order->get_total() );
	}

	return round( $total, 2 );
}

/**
 * Cria um snapshot financeiro com a regra canônica de pagamento.
 *
 * @param array<string, string> $filters Filtros com from, to e interval.
 * @return array<string, mixed>
 */
function papelito_admin_reports_get_sales_snapshot( array $filters ): array {
	$all_orders         = papelito_admin_reports_query_sales_orders( $filters );
	$paid_orders        = papelito_admin_reports_paid_sales_orders( $all_orders );
	$segment            = (string) ( $filters['segment'] ?? 'all' );
	$segmented          = papelito_admin_reports_segment_orders( $all_orders, $paid_orders, $segment );
	$scope_orders       = $segmented['scope'];
	$revenue_orders     = $segmented['revenue'];
	$gross_revenue      = 0.0;
	$discounts_total    = 0.0;
	$shipping_total     = 0.0;
	$taxes_total        = 0.0;
	$refunds_total      = 0.0;
	$items_sold         = 0;
	$revenue_by_bucket  = array();
	$status_counts      = array();
	$order_volume       = array();
	$product_revenue    = array();
	$payment_mix        = array();

	foreach ( $scope_orders as $order ) {
		$status = sanitize_key( (string) $order->get_status() );
		$status = '' === $status ? 'unknown' : $status;
		$status_counts[ $status ] = ( $status_counts[ $status ] ?? 0 ) + 1;

		$bucket = papelito_admin_reports_sales_bucket( $order, $filters['interval'] );
		if ( '' !== $bucket ) {
			$order_volume[ $bucket ] = ( $order_volume[ $bucket ] ?? 0 ) + 1;
		}
	}

	foreach ( $revenue_orders as $order ) {
		$total          = max( 0.0, (float) $order->get_total() );
		$discount        = max( 0.0, (float) $order->get_discount_total() );
		$shipping        = max( 0.0, (float) $order->get_shipping_total() );
		$taxes           = max( 0.0, (float) $order->get_total_tax() );
		$refunded        = max( 0.0, (float) $order->get_total_refunded() );
		$gross_revenue  += $total;
		$discounts_total += $discount;
		$shipping_total += $shipping;
		$taxes_total    += $taxes;
		$refunds_total  += $refunded;

		$bucket = papelito_admin_reports_sales_bucket( $order, $filters['interval'] );
		if ( '' !== $bucket ) {
			$revenue_by_bucket[ $bucket ] = ( $revenue_by_bucket[ $bucket ] ?? 0.0 ) + $total;
		}

		$payment_method = trim( (string) $order->get_payment_method_title() );
		$payment_method = '' === $payment_method ? 'não informado' : $payment_method;
		$payment_mix[ $payment_method ] = ( $payment_mix[ $payment_method ] ?? 0.0 ) + $total;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
				continue;
			}

			$name     = trim( (string) $item->get_name() );
			$name     = '' === $name ? 'item' : $name;
			$quantity = method_exists( $item, 'get_quantity' ) ? max( 0, (int) $item->get_quantity() ) : 0;
			$total    = method_exists( $item, 'get_total' ) ? max( 0.0, (float) $item->get_total() ) : 0.0;
			$items_sold += $quantity;
			$product_revenue[ $name ] = ( $product_revenue[ $name ] ?? 0.0 ) + $total;
		}
	}

	arsort( $status_counts );
	arsort( $product_revenue );
	arsort( $payment_mix );

	$product_rows = array_slice( $product_revenue, 0, 6, true );
	$product_total = array_sum( $product_rows );
	$leaderboard = array_map(
		static fn( string $label, float $value ): array => array(
			'label' => strtolower( $label ),
			'value' => round( $value, 2 ),
			'share' => $product_total > 0 ? round( ( $value / $product_total ) * 100, 1 ) : 0.0,
		),
		array_keys( $product_rows ),
		array_values( $product_rows )
	);

	$payment_rows = array_slice( $payment_mix, 0, 3, true );
	$payment_rest = array_slice( $payment_mix, 3, null, true );
	if ( ! empty( $payment_rest ) ) {
		$payment_rows['outros'] = array_sum( $payment_rest );
	}

	return array(
		'grossRevenue'      => round( $gross_revenue, 2 ),
		'netRevenue'        => round( max( 0.0, $gross_revenue - $shipping_total - $taxes_total - $refunds_total ), 2 ),
		'orders'            => count( $revenue_orders ),
		'avgOrderValue'     => count( $revenue_orders ) > 0 ? round( $gross_revenue / count( $revenue_orders ), 2 ) : 0.0,
		'discountsTotal'    => round( $discounts_total, 2 ),
		'shippingTotal'     => round( $shipping_total, 2 ),
		'taxesTotal'        => round( $taxes_total, 2 ),
		'refundsTotal'      => round( $refunds_total, 2 ),
		'itemsSold'         => $items_sold,
		'revenueByInterval' => $revenue_by_bucket,
		'orderVolumeByInterval' => $order_volume,
		'orderStatusSeries' => array_map(
			static fn( string $label, int $value ): array => array( 'label' => $label, 'value' => $value ),
			array_keys( array_slice( $status_counts, 0, 6, true ) ),
			array_values( array_slice( $status_counts, 0, 6, true ) )
		),
		'paymentMixSeries' => array_map(
			static fn( string $label, float $value ): array => array( 'label' => strtolower( $label ), 'value' => round( $value, 2 ) ),
			array_keys( $payment_rows ),
			array_values( $payment_rows )
		),
		'leaderboard'      => array_values( $leaderboard ),
		'segment'          => $segment,
		'previousGrossRevenue' => papelito_admin_reports_previous_gross_revenue( $filters ),
	);
}

/**
 * Nome do cliente para o relatorio de vendas.
 *
 * Em pedido B2B o comprador fiscal e a empresa: `billing_first_name`/`billing_last_name`
 * nascem vazios e a pessoa fica so em `shipping`. Ler apenas o billing fazia o export
 * gravar "Cliente nao identificado" em pedidos que tinham cliente. Reaproveita a mesma
 * ordem de resolucao ja usada pelo painel do vendor.
 *
 * @param WC_Order $order Pedido.
 * @return string
 */
function papelito_admin_reports_order_customer_name( WC_Order $order ): string {
	if ( function_exists( 'papelito_vendor_dashboard_customer_label' ) ) {
		$label = papelito_vendor_dashboard_customer_label( $order );

		if ( '' !== $label ) {
			return $label;
		}
	}

	$fallbacks = array(
		method_exists( $order, 'get_formatted_shipping_full_name' ) ? (string) $order->get_formatted_shipping_full_name() : '',
		method_exists( $order, 'get_shipping_company' ) ? (string) $order->get_shipping_company() : '',
		method_exists( $order, 'get_billing_company' ) ? (string) $order->get_billing_company() : '',
		method_exists( $order, 'get_formatted_billing_full_name' ) ? (string) $order->get_formatted_billing_full_name() : '',
	);

	foreach ( $fallbacks as $candidate ) {
		$candidate = sanitize_text_field( trim( $candidate ) );

		if ( '' !== $candidate ) {
			return $candidate;
		}
	}

	return 'Cliente não identificado';
}

/**
 * Linhas simples do export de vendas.
 *
 * @param array<string, string> $filters Filtros.
 * @return array<int, array<string, string|float|int>>
 */
function papelito_admin_reports_query_simple_sales_rows( array $filters ): array {
	$orders = papelito_admin_reports_paid_sales_orders(
		papelito_admin_reports_query_sales_orders( $filters )
	);

	$rows = array();

	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}

		$city = trim( (string) $order->get_billing_city() );

		$rows[] = array(
			'order_id'        => $order->get_id(),
			'order_number'    => $order->get_order_number(),
			'created_at'      => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i:s' ) : '',
			'status'          => wc_get_order_status_name( $order->get_status() ),
			'customer_name'   => papelito_admin_reports_order_customer_name( $order ),
			'phone'           => (string) $order->get_billing_phone(),
			'postcode'        => (string) $order->get_billing_postcode(),
			'city'            => $city,
			'state'           => (string) $order->get_billing_state(),
			'payment_method'  => (string) $order->get_payment_method_title(),
			'total'           => (float) $order->get_total(),
		);
	}

	return $rows;
}

/**
 * XLSX simples de vendas.
 *
 * @param array<int, array<string, string|float|int>> $rows Linhas.
 * @return string|WP_Error
 */
function papelito_admin_reports_generate_simple_sales_xlsx( array $rows ) {
	$ready = papelito_admin_reports_require_spreadsheet();

	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	try {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Vendas' );
		$sheet->fromArray(
			array(
				'order_id',
				'order_number',
				'created_at',
				'status',
				'customer_name',
				'phone',
				'postcode',
				'city',
				'state',
				'payment_method',
				'total',
			),
			null,
			'A1'
		);

		$row_index = 2;
		foreach ( $rows as $row ) {
			$sheet->fromArray(
				array(
					$row['order_id'],
					papelito_admin_reports_normalize_export_text( $row['order_number'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['created_at'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['status'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['customer_name'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['phone'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['postcode'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['city'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['state'] ?? '' ),
					papelito_admin_reports_normalize_export_text( $row['payment_method'] ?? '' ),
					$row['total'],
				),
				null,
				'A' . $row_index
			);
			$row_index++;
		}

		foreach ( range( 'A', 'K' ) as $column ) {
			$sheet->getColumnDimension( $column )->setAutoSize( true );
		}

		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
		ob_start();
		$writer->save( 'php://output' );
		$binary = ob_get_clean();
		$spreadsheet->disconnectWorksheets();
		unset( $spreadsheet );

		return is_string( $binary ) ? $binary : '';
	} catch ( Throwable $exception ) {
		return new WP_Error(
			'papelito_reports_sales_xlsx_failed',
			'Não foi possível gerar o XLSX de vendas: ' . $exception->getMessage(),
			array( 'status' => 500 )
		);
	}
}

/**
 * CSV simples de vendas.
 *
 * @param array<int, array<string, string|float|int>> $rows Linhas.
 * @return string
 */
function papelito_admin_reports_generate_simple_sales_csv( array $rows ): string {
	$stream = fopen( 'php://temp', 'r+' );

	if ( false === $stream ) {
		return '';
	}

	fwrite( $stream, "\xEF\xBB\xBF" );
	fputcsv(
		$stream,
		array(
			'order_id',
			'order_number',
			'created_at',
			'status',
			'customer_name',
			'phone',
			'postcode',
			'city',
			'state',
			'payment_method',
			'total',
		)
	);

	foreach ( $rows as $row ) {
		fputcsv(
			$stream,
			array(
				$row['order_id'],
				papelito_admin_reports_normalize_export_text( $row['order_number'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['created_at'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['status'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['customer_name'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['phone'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['postcode'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['city'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['state'] ?? '' ),
				papelito_admin_reports_normalize_export_text( $row['payment_method'] ?? '' ),
				$row['total'],
			)
		);
	}

	rewind( $stream );
	$csv = stream_get_contents( $stream );
	fclose( $stream );

	return is_string( $csv ) ? $csv : '';
}

/**
 * Envia download do arquivo.
 *
 * @param string $binary Conteudo.
 * @param string $filename Nome.
 * @param string $content_type Content type.
 * @return never
 */
function papelito_admin_reports_output_download( string $binary, string $filename, string $content_type ) {
	nocache_headers();
	header( 'Content-Type: ' . $content_type );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . strlen( $binary ) );
	echo $binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1/admin',
			'/sales/snapshot',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$filters = papelito_admin_reports_parse_sales_snapshot_filters( $request );
					return new WP_REST_Response( papelito_admin_reports_get_sales_snapshot( $filters ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/reports/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$filters = papelito_admin_reports_parse_users_filters( $request );
					return new WP_REST_Response( papelito_admin_reports_get_users_snapshot( $filters ), 200 );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/reports/users/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$filters = papelito_admin_reports_parse_users_export_filters( $request );
					$rows    = papelito_admin_reports_query_simple_user_rows( $filters );
					$binary  = 'csv' === $filters['format']
						? papelito_admin_reports_generate_simple_users_csv( $rows )
						: papelito_admin_reports_generate_simple_users_xlsx( $rows );

					if ( is_wp_error( $binary ) ) {
						return $binary;
					}

					$extension    = 'csv' === $filters['format'] ? 'csv' : 'xlsx';
					$content_type = 'csv' === $filters['format']
						? 'text/csv; charset=UTF-8'
						: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
					$filename     = 'usuarios-cadastrados-' . wp_date( 'Y-m-d' ) . '.' . $extension;

					papelito_admin_reports_output_download( $binary, $filename, $content_type );
				},
			)
		);

		register_rest_route(
			'papelito/v1/admin',
			'/reports/sales/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$filters = papelito_admin_reports_parse_simple_export_filters( $request );
					$rows    = papelito_admin_reports_query_simple_sales_rows( $filters );
					$binary  = 'csv' === $filters['format']
						? papelito_admin_reports_generate_simple_sales_csv( $rows )
						: papelito_admin_reports_generate_simple_sales_xlsx( $rows );

					if ( is_wp_error( $binary ) ) {
						return $binary;
					}

					$extension    = 'csv' === $filters['format'] ? 'csv' : 'xlsx';
					$content_type = 'csv' === $filters['format']
						? 'text/csv; charset=UTF-8'
						: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
					$filename     = 'vendas-woocommerce-' . wp_date( 'Y-m-d' ) . '.' . $extension;

					papelito_admin_reports_output_download( $binary, $filename, $content_type );
				},
			)
		);
	}
);
