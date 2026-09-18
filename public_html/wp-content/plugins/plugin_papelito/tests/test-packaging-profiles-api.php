<?php
// phpcs:ignoreFile -- Standalone test doubles WordPress and wpdb boundaries by design.

/**
 * API de perfis de embalagem do vendor.
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PAPELITO_REST_NAMESPACE', 'papelito/v1' );

const PACKAGING_API_VENDOR_A = 7301;
const PACKAGING_API_VENDOR_B = 7302;
const PACKAGING_API_USER_A   = PACKAGING_API_VENDOR_A;
const PACKAGING_API_USER_B   = PACKAGING_API_VENDOR_B;

/**
 * Estado de autenticação do harness.
 *
 * @var int
 */
$packaging_api_current_user_id = PACKAGING_API_USER_A;

/**
 * Retorna o usuário autenticado no harness.
 *
 * @return int
 */
function get_current_user_id(): int {
    global $packaging_api_current_user_id;

    return (int) $packaging_api_current_user_id;
}

/**
 * Sanitiza texto no formato mínimo necessário ao contrato.
 *
 * @param mixed $value Valor cru.
 * @return string
 */
function sanitize_text_field( $value ): string {
    return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}

/**
 * Retorna o valor sem slash do harness.
 *
 * @param mixed $value Valor.
 * @return mixed
 */
function wp_unslash( $value ) {
    return $value;
}

/**
 * Converte valor para inteiro positivo de rota.
 *
 * @param mixed $value Valor.
 * @return int
 */
function absint( $value ): int {
    return abs( (int) $value );
}

/**
 * Serializa arrays no formato usado pelo plugin.
 *
 * @param mixed $value Valor.
 * @return string
 */
function wp_json_encode( $value ): string {
    return (string) json_encode( $value );
}

/**
 * Stub do rate limit autenticado.
 *
 * @param string $bucket Balde.
 * @param string $identity Identidade.
 * @param int $max Limite.
 * @param int $window Janela.
 * @return bool
 */
function papelito_rate_limit( string $bucket, string $identity, int $max, int $window ): bool {
    unset( $bucket, $identity, $max, $window );

    return true;
}

/**
 * Retorna uma identidade autenticada estável.
 *
 * @param string $fallback_scope Escopo alternativo.
 * @return string
 */
function papelito_rate_limit_identity( string $fallback_scope = '' ): string {
    unset( $fallback_scope );

    return 'user:' . get_current_user_id();
}

/**
 * Stub do registrador de hooks.
 *
 * @param mixed ...$args Argumentos ignorados.
 * @return void
 */
function add_action( ...$args ): void {
    unset( $args );
}

/**
 * Stub do registrador de rotas.
 *
 * @param mixed ...$args Argumentos ignorados.
 * @return void
 */
function register_rest_route( ...$args ): void {
    global $packaging_api_registered_routes;
    $packaging_api_registered_routes[] = $args;
}

/**
 * Rotas registradas no harness.
 *
 * @var array<int,array<int,mixed>>
 */
$packaging_api_registered_routes = array();

/**
 * Stub do servidor REST.
 */
class WP_REST_Server {
    public const READABLE = 'GET';
    public const CREATABLE = 'POST';
    public const EDITABLE = 'PUT';
}

/**
 * Erro REST mínimo para o harness.
 */
class WP_Error {
    /** @var string */
    public string $code;

    /** @var string */
    public string $message;

    /** @var array<string,mixed> */
    public array $data;

    /**
     * Cria um erro observável.
     *
     * @param string $code Código.
     * @param string $message Mensagem.
     * @param array<string,mixed> $data Dados.
     */
    public function __construct( string $code = '', string $message = '', array $data = array() ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    /**
     * Retorna o primeiro código do erro.
     *
     * @return string
     */
    public function get_error_code(): string {
        return $this->code;
    }

    /**
     * Retorna a mensagem do erro.
     *
     * @return string
     */
    public function get_error_message(): string {
        return $this->message;
    }

    /**
     * Retorna os dados do erro.
     *
     * @return array<string,mixed>
     */
    public function get_error_data(): array {
        return $this->data;
    }
}

/**
 * Indica se o valor é um WP_Error.
 *
 * @param mixed $value Valor.
 * @return bool
 */
function is_wp_error( $value ): bool {
    return $value instanceof WP_Error;
}

/**
 * Resposta REST mínima para o harness.
 */
class WP_REST_Response {
    /** @var mixed */
    private $data;

    /** @var int */
    private int $status;

    /**
     * Cria resposta.
     *
     * @param mixed $data Corpo.
     * @param int $status Status.
     */
    public function __construct( $data, int $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }

    /**
     * Retorna o corpo.
     *
     * @return mixed
     */
    public function get_data() {
        return $this->data;
    }

    /**
     * Retorna o status.
     *
     * @return int
     */
    public function get_status(): int {
        return $this->status;
    }
}

/**
 * Request REST mínima para o harness.
 */
class WP_REST_Request {
    /** @var array<string,mixed> */
    private array $params;

    /** @var array<string,mixed> */
    private array $json;

    /**
     * Cria request.
     *
     * @param array<string,mixed> $json Corpo.
     * @param array<string,mixed> $params Parâmetros.
     */
    public function __construct( array $json = array(), array $params = array() ) {
        $this->json   = $json;
        $this->params = $params;
    }

    /**
     * Retorna o corpo JSON.
     *
     * @return array<string,mixed>
     */
    public function get_json_params(): array {
        return $this->json;
    }

    /**
     * Retorna parâmetro de rota.
     *
     * @param string $key Chave.
     * @return mixed
     */
    public function get_param( string $key ) {
        return $this->params[ $key ] ?? null;
    }
}

/**
 * wpdb de memória que executa apenas a tabela de perfis.
 */
class Papelito_Packaging_Api_Wpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';

    /** @var array<int,array<string,mixed>> */
    public array $rows = array();

    /**
     * Prepara uma query simples com placeholders %d/%s.
     *
     * @param string $query Query.
     * @param mixed ...$args Valores.
     * @return string
     */
    public function prepare( string $query, ...$args ): string {
        foreach ( $args as $arg ) {
            $replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
            $query       = preg_replace( '/%[ds]/', $replacement, $query, 1 );
        }

        return $query;
    }

    /**
     * Retorna linhas da tabela conforme os filtros presentes na query.
     *
     * @param string $query Query.
     * @param string $output Formato.
     * @return array<int,array<string,mixed>>
     */
    public function get_results( string $query, string $output = '' ): array {
        unset( $output );
        $rows = array_values( array_filter( $this->rows, static function ( array $row ) use ( $query ): bool {
            if ( preg_match( '/vendor_id\s*=\s*(\d+)/', $query, $match ) && (int) $match[1] !== (int) $row['vendor_id'] ) {
                return false;
            }

            if ( preg_match( '/\bid\s*=\s*(\d+)/', $query, $match ) && (int) $match[1] !== (int) $row['id'] ) {
                return false;
            }

            if ( preg_match( '/code\s*=\s*\'([^\']*)\'/', $query, $match ) && $match[1] !== $row['code'] ) {
                return false;
            }

            if ( preg_match( '/active\s*=\s*(\d+)/', $query, $match ) && (int) $match[1] !== (int) $row['active'] ) {
                return false;
            }

            return true;
        } ) );

        usort( $rows, static fn ( array $left, array $right ): int => $left['id'] <=> $right['id'] );

        return $rows;
    }

    /**
     * Responde a um valor único.
     *
     * Só COUNT(*) é necessário aqui: é o que a guarda do teto de caixas ativas usa. Qualquer outra
     * agregação devolve nulo em vez de um número inventado, para o teste falhar alto se alguém
     * passar a depender dela sem ensinar o stub.
     *
     * @param string $query Query.
     * @return string|null
     */
    public function get_var( string $query ): ?string {
        if ( ! preg_match( '/^\s*SELECT\s+COUNT\(\*\)/i', $query ) ) {
            return null;
        }

        return (string) count( $this->get_results( $query ) );
    }

    /**
     * Retorna a primeira linha encontrada.
     *
     * @param string $query Query.
     * @param string $output Formato.
     * @return array<string,mixed>|null
     */
    public function get_row( string $query, string $output = '' ): ?array {
        return $this->get_results( $query, $output )[0] ?? null;
    }

    /**
     * Executa INSERT/UPDATE preparados do teste.
     *
     * @param string $query Query.
     * @return int
     */
    public function query( string $query ): int {
        $this->last_error = '';

        if ( str_starts_with( strtoupper( ltrim( $query ) ), 'INSERT' ) ) {
            return $this->insert_row( $query );
        }

        if ( str_starts_with( strtoupper( ltrim( $query ) ), 'UPDATE' ) ) {
            return $this->update_rows( $query );
        }

        if ( str_starts_with( strtoupper( ltrim( $query ) ), 'DELETE' ) ) {
            return $this->delete_rows( $query );
        }

        $this->last_error = 'Unsupported query';

        return false;
    }

    /**
     * Apaga as linhas que casam com os filtros da query.
     *
     * A tabela de regras não existe neste stub: um DELETE que não cita `profiles` sai como zero
     * linhas afetadas, que é o mesmo que o banco responderia para uma tabela vazia.
     *
     * @param string $query Query.
     * @return int Linhas apagadas.
     */
    private function delete_rows( string $query ): int {
        if ( ! str_contains( $query, 'profiles' ) ) {
            return 0;
        }

        $doomed = $this->get_results( $query );
        if ( empty( $doomed ) ) {
            return 0;
        }

        $ids         = array_map( static fn ( array $row ): int => (int) $row['id'], $doomed );
        $this->rows  = array_values( array_filter( $this->rows, static fn ( array $row ): bool => ! in_array( (int) $row['id'], $ids, true ) ) );

        return count( $ids );
    }

    /**
     * Retorna collation do teste.
     *
     * @return string
     */
    public function get_charset_collate(): string {
        return '';
    }

    /**
     * Insere uma linha a partir da query preparada.
     *
     * @param string $query Query.
     * @return int
     */
    private function insert_row( string $query ): int {
        if ( ! preg_match( '/INSERT INTO\s+[^ ]+\s*\(([^)]*)\)\s*VALUES\s*\((.*)\)/is', $query, $match ) ) {
            $this->last_error = 'Invalid insert';

            return false;
        }

        $columns = array_map( 'trim', explode( ',', $match[1] ) );
        $values  = $this->split_values( $match[2] );
        $row     = array();
        foreach ( $columns as $index => $column ) {
            $row[ $column ] = $this->parse_value( $values[ $index ] ?? '' );
        }

        foreach ( $this->rows as $existing ) {
            if ( (int) $existing['vendor_id'] === (int) $row['vendor_id'] && $existing['code'] === $row['code'] ) {
                $this->last_error = 'Duplicate entry';

                return false;
            }
        }

        $row['id']        = count( $this->rows ) + 1;
        $row['created_at'] = '2026-09-17 12:00:00';
        $row['updated_at'] = $row['created_at'];
        $this->rows[]     = $row;
        $this->insert_id  = (int) $row['id'];

        return 1;
    }

    /**
     * Atualiza linhas conforme a cláusula WHERE.
     *
     * @param string $query Query.
     * @return int
     */
    private function update_rows( string $query ): int {
        if ( ! preg_match( '/SET\s+(.*?)\s+WHERE\s+(.*)$/is', $query, $match ) ) {
            $this->last_error = 'Invalid update';

            return false;
        }

        $assignments = array();
        foreach ( explode( ',', $match[1] ) as $assignment ) {
            [ $column, $value ] = array_pad( explode( '=', $assignment, 2 ), 2, '' );
            $assignments[ trim( $column ) ] = $this->parse_value( trim( $value ) );
        }

        $changed = 0;
        foreach ( $this->rows as &$row ) {
            $where = $match[2];
            if ( preg_match( '/\bid\s*=\s*(\d+)/', $where, $id_match ) && (int) $row['id'] !== (int) $id_match[1] ) {
                continue;
            }
            if ( preg_match( '/vendor_id\s*=\s*(\d+)/', $where, $vendor_match ) && (int) $row['vendor_id'] !== (int) $vendor_match[1] ) {
                continue;
            }

            foreach ( $assignments as $column => $value ) {
                $row[ $column ] = 'version + 1' === strtolower( (string) $value ) ? (int) $row[ $column ] + 1 : $value;
            }
            $row['updated_at'] = '2026-09-17 12:01:00';
            ++$changed;
        }
        unset( $row );

        return $changed;
    }

    /**
     * Divide uma lista de valores SQL respeitando aspas.
     *
     * @param string $values Valores.
     * @return array<int,string>
     */
    private function split_values( string $values ): array {
        $parts = array();
        $part  = '';
        $quote = false;
        $length = strlen( $values );

        for ( $index = 0; $index < $length; ++$index ) {
            $character = $values[ $index ];
            if ( "'" === $character ) {
                $quote = ! $quote;
            }
            if ( ',' === $character && ! $quote ) {
                $parts[] = trim( $part );
                $part    = '';
                continue;
            }
            $part .= $character;
        }
        $parts[] = trim( $part );

        return $parts;
    }

    /**
     * Converte literal SQL do harness.
     *
     * @param string $value Literal.
     * @return mixed
     */
    private function parse_value( string $value ) {
        $value = trim( $value );
        if ( 'NULL' === strtoupper( $value ) ) {
            return null;
        }
        if ( strlen( $value ) >= 2 && "'" === $value[0] && "'" === $value[ strlen( $value ) - 1 ] ) {
            return str_replace( "''", "'", substr( $value, 1, -1 ) );
        }
        return is_numeric( $value ) ? (int) $value : $value;
    }
}

global $wpdb;
$wpdb = new Papelito_Packaging_Api_Wpdb();

require_once dirname( __DIR__ ) . '/includes/packaging.php';

$failures = 0;

/**
 * Registra uma asserção do teste.
 *
 * @param string $label Cenário.
 * @param bool $condition Resultado.
 * @return void
 */
function packaging_api_assert( string $label, bool $condition ): void {
    global $failures;
    if ( $condition ) {
        echo "  PASS: {$label}\n";
        return;
    }
    ++$failures;
    echo "  FAIL: {$label}\n";
}

/**
 * Extrai status de um retorno REST.
 *
 * @param mixed $result Retorno.
 * @return int
 */
function packaging_api_status( $result ): int {
    if ( $result instanceof WP_REST_Response ) {
        return $result->get_status();
    }
    if ( $result instanceof WP_Error ) {
        return (int) ( $result->get_error_data()['status'] ?? 0 );
    }

    return 0;
}

/**
 * Lê item único da resposta de lista.
 *
 * @param mixed $result Retorno.
 * @return array<string,mixed>|null
 */
function packaging_api_first_item( $result ): ?array {
    if ( ! $result instanceof WP_REST_Response ) {
        return null;
    }

    $data = $result->get_data();

    return is_array( $data ) && isset( $data['items'][0] ) && is_array( $data['items'][0] ) ? $data['items'][0] : null;
}

/**
 * Muda o usuário do cenário.
 *
 * @param int $user_id Usuário.
 * @return void
 */
function packaging_api_as_user( int $user_id ): void {
    global $packaging_api_current_user_id;
    $packaging_api_current_user_id = $user_id;
}

/**
 * Payload RPC válido.
 *
 * @param string $code Código.
 * @return array<string,mixed>
 */
function packaging_api_payload( string $code = 'P04' ): array {
    return array(
        'code'          => $code,
        'label'         => 'Caixa ' . $code,
        'length_mm'     => 160,
        'width_mm'      => 120,
        'height_mm'     => 40,
        'tare_weight_g' => 100,
        'max_payload_g' => 1500,
        'source'        => 'rpc',
    );
}

/**
 * Payload de caixa própria, para códigos fora do catálogo RPC.
 *
 * @param string $code Código.
 * @return array<string,mixed>
 */
function packaging_api_custom_payload( string $code ): array {
    return array_merge( packaging_api_payload( $code ), array( 'source' => 'custom' ) );
}

echo "Scenario 1: vendor cria e lista as próprias caixas\n";
packaging_api_as_user( PACKAGING_API_USER_A );
$created = papelito_packaging_handle_create_profile( new WP_REST_Request( array_merge( packaging_api_payload(), array( 'vendor_id' => PACKAGING_API_VENDOR_B ) ) ) );
packaging_api_assert( 'criação responde 201', 201 === packaging_api_status( $created ) );
packaging_api_assert( 'vendor_id do corpo é ignorado', PACKAGING_API_VENDOR_A === (int) $wpdb->rows[0]['vendor_id'] );
$listed = papelito_packaging_handle_get_profiles();
packaging_api_assert( 'lista responde 200', 200 === packaging_api_status( $listed ) );
packaging_api_assert( 'lista contém a caixa criada', 1 === count( $listed->get_data()['items'] ?? array() ) );

echo "Scenario 2: editar incrementa versão e grava updated_by\n";
$profile_id = (int) $wpdb->rows[0]['id'];
$updated = papelito_packaging_handle_update_profile(
    new WP_REST_Request(
        array(
            'label'         => 'Caixa P04 revisada',
            'code'          => 'p04',
            'length_mm'     => 170,
            'width_mm'      => 120,
            'height_mm'     => 40,
            'tare_weight_g' => 0,
            'max_payload_g' => null,
        ),
        array( 'id' => $profile_id )
    )
);
packaging_api_assert( 'edição responde 200', 200 === packaging_api_status( $updated ) );
packaging_api_assert( 'versão incrementa', 2 === (int) $wpdb->rows[0]['version'] );
packaging_api_assert( 'updated_by é o usuário autenticado', PACKAGING_API_USER_A === (int) $wpdb->rows[0]['updated_by'] );
packaging_api_assert( 'código é normalizado em maiúsculas', 'P04' === $wpdb->rows[0]['code'] );
packaging_api_assert( 'carga máxima nula é preservada', null === $wpdb->rows[0]['max_payload_g'] );

echo "Scenario 3: vendor diferente recebe 404 sem revelar o perfil\n";
packaging_api_as_user( PACKAGING_API_USER_B );
$foreign_update = papelito_packaging_handle_update_profile(
    new WP_REST_Request( packaging_api_payload(), array( 'id' => $profile_id ) )
);
$foreign_deactivate = papelito_packaging_handle_deactivate_profile( new WP_REST_Request( array(), array( 'id' => $profile_id ) ) );
packaging_api_assert( 'editar perfil alheio responde 404', 404 === packaging_api_status( $foreign_update ) );
packaging_api_assert( 'desativar perfil alheio responde 404', 404 === packaging_api_status( $foreign_deactivate ) );

echo "Scenario 4: o mesmo código só conflita dentro do vendor\n";
$other_vendor = papelito_packaging_handle_create_profile( new WP_REST_Request( packaging_api_payload( 'P04' ) ) );
packaging_api_assert( 'mesmo código em outro vendor é aceito', 201 === packaging_api_status( $other_vendor ) );
packaging_api_as_user( PACKAGING_API_USER_A );
$duplicate = papelito_packaging_handle_create_profile( new WP_REST_Request( packaging_api_payload( 'p04' ) ) );
packaging_api_assert( 'código duplicado no vendor responde 409', 409 === packaging_api_status( $duplicate ) );

echo "Scenario 5: validações recusam valores inválidos\n";
$invalid_payloads = array(
    'medida zero'       => array_merge( packaging_api_payload(), array( 'length_mm' => 0 ) ),
    'medida negativa'   => array_merge( packaging_api_payload(), array( 'width_mm' => -1 ) ),
    'medida não inteira' => array_merge( packaging_api_payload(), array( 'height_mm' => 40.5 ) ),
    'tara negativa'     => array_merge( packaging_api_payload(), array( 'tare_weight_g' => -1 ) ),
    'código RPC inválido' => array_merge( packaging_api_payload( 'ZZ99' ), array( 'source' => 'rpc' ) ),
);
foreach ( $invalid_payloads as $label => $payload ) {
    $invalid = papelito_packaging_handle_create_profile( new WP_REST_Request( $payload ) );
    packaging_api_assert( $label . ' é recusado', 422 === packaging_api_status( $invalid ) );
}

echo "Scenario 5b: tara é opcional e a carga máxima é do conteúdo\n";
$sem_tara = papelito_packaging_handle_create_profile(
    new WP_REST_Request( array_diff_key( packaging_api_custom_payload( 'SEM-TARA' ), array( 'tare_weight_g' => true ) ) )
);
packaging_api_assert( 'caixa sem tara é aceita', 201 === packaging_api_status( $sem_tara ) );
$linha_sem_tara = array_values( array_filter( $wpdb->rows, static fn ( array $row ): bool => 'SEM-TARA' === $row['code'] ) )[0] ?? array();
packaging_api_assert( 'tara ausente vira zero', 0 === (int) ( $linha_sem_tara['tare_weight_g'] ?? -1 ) );

$perfil_com_tara = array( 'max_payload_g' => 1000, 'tare_weight_g' => 300 );
$itens_no_limite = array( array( 'weight_g' => 1000, 'qty' => 1 ) );
packaging_api_assert(
    'tara não desconta da carga máxima',
    true === papelito_packaging_weight_fits( $perfil_com_tara, $itens_no_limite )
);
packaging_api_assert(
    'conteúdo acima da carga máxima continua recusado',
    false === papelito_packaging_weight_fits( $perfil_com_tara, array( array( 'weight_g' => 1001, 'qty' => 1 ) ) )
);
$wpdb->rows = array_values( array_filter( $wpdb->rows, static fn ( array $row ): bool => 'SEM-TARA' !== $row['code'] ) );

echo "Scenario 6: desativar preserva a linha e a versão\n";
$before_deactivate_version = (int) $wpdb->rows[0]['version'];
$deactivated = papelito_packaging_handle_deactivate_profile( new WP_REST_Request( array(), array( 'id' => $profile_id ) ) );
packaging_api_assert( 'desativação responde 200', 200 === packaging_api_status( $deactivated ) );
packaging_api_assert( 'linha não é apagada', 2 === count( $wpdb->rows ) );
packaging_api_assert( 'active vira zero', 0 === (int) $wpdb->rows[0]['active'] );
packaging_api_assert( 'versão não incrementa na desativação', $before_deactivate_version === (int) $wpdb->rows[0]['version'] );
$inactive_list = papelito_packaging_handle_get_profiles();
packaging_api_assert( 'lista inclui caixa inativa', 1 === count( $inactive_list->get_data()['items'] ?? array() ) );

echo "Scenario 6b: reativar devolve a caixa e não incrementa a versão\n";
$before_reactivate_version = (int) $wpdb->rows[0]['version'];
$reactivated = papelito_packaging_handle_reactivate_profile( new WP_REST_Request( array(), array( 'id' => $profile_id ) ) );
packaging_api_assert( 'reativação responde 200', 200 === packaging_api_status( $reactivated ) );
packaging_api_assert( 'active volta a um', 1 === (int) $wpdb->rows[0]['active'] );
packaging_api_assert( 'versão não incrementa na reativação', $before_reactivate_version === (int) $wpdb->rows[0]['version'] );

echo "Scenario 6c: excluir só aceita caixa desativada\n";
$delete_active = papelito_packaging_handle_delete_profile( new WP_REST_Request( array(), array( 'id' => $profile_id ) ) );
packaging_api_assert( 'excluir caixa ativa responde 409', 409 === packaging_api_status( $delete_active ) );
packaging_api_assert( 'caixa ativa continua na tabela', 2 === count( $wpdb->rows ) );
papelito_packaging_handle_deactivate_profile( new WP_REST_Request( array(), array( 'id' => $profile_id ) ) );
$deleted = papelito_packaging_handle_delete_profile( new WP_REST_Request( array(), array( 'id' => $profile_id ) ) );
packaging_api_assert( 'excluir caixa inativa responde 200', 200 === packaging_api_status( $deleted ) );
packaging_api_assert( 'linha some da tabela', 1 === count( $wpdb->rows ) );

echo "Scenario 6d: o teto de caixas ativas barra a criação e a reativação\n";
packaging_api_as_user( PACKAGING_API_USER_A );
$wpdb->rows = array();
$wpdb->insert_id = 0;
for ( $i = 0; $i < PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES; $i++ ) {
    papelito_packaging_handle_create_profile( new WP_REST_Request( packaging_api_custom_payload( 'CX' . $i ) ) );
}
packaging_api_assert(
    'teto cheio tem ' . PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES . ' caixas ativas',
    PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES === count( $wpdb->rows )
);
$over_limit = papelito_packaging_handle_create_profile( new WP_REST_Request( packaging_api_custom_payload( 'EXTRA' ) ) );
packaging_api_assert( 'criação acima do teto responde 409', 409 === packaging_api_status( $over_limit ) );
packaging_api_assert(
    'nada foi inserido acima do teto',
    PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES === count( $wpdb->rows )
);
$freed_id = (int) $wpdb->rows[0]['id'];
papelito_packaging_handle_deactivate_profile( new WP_REST_Request( array(), array( 'id' => $freed_id ) ) );
$after_free = papelito_packaging_handle_create_profile( new WP_REST_Request( packaging_api_custom_payload( 'EXTRA' ) ) );
packaging_api_assert( 'desativar abre vaga para criar', 201 === packaging_api_status( $after_free ) );
$reactivate_over_limit = papelito_packaging_handle_reactivate_profile( new WP_REST_Request( array(), array( 'id' => $freed_id ) ) );
packaging_api_assert( 'reativação acima do teto responde 409', 409 === packaging_api_status( $reactivate_over_limit ) );
packaging_api_assert( 'caixa recusada continua inativa', 0 === (int) $wpdb->rows[0]['active'] );

echo "Scenario 7: catálogo RPC tem exatamente 21 formatos\n";
$catalog = papelito_packaging_rpc_catalog();
$expected_catalog = array(
    'P04' => array( 'category' => 'Pequena', 'length_mm' => 160, 'width_mm' => 120, 'height_mm' => 40, 'max_payload_g' => 1500 ),
    'C08' => array( 'category' => 'Comprida', 'length_mm' => 320, 'width_mm' => 120, 'height_mm' => 80, 'max_payload_g' => 3000 ),
    'C12' => array( 'category' => 'Comprida', 'length_mm' => 320, 'width_mm' => 120, 'height_mm' => 120, 'max_payload_g' => null ),
    'M08' => array( 'category' => 'Média', 'length_mm' => 240, 'width_mm' => 160, 'height_mm' => 80, 'max_payload_g' => 3000 ),
    'M12' => array( 'category' => 'Média', 'length_mm' => 240, 'width_mm' => 160, 'height_mm' => 120, 'max_payload_g' => 4000 ),
    'G08' => array( 'category' => 'Grande', 'length_mm' => 320, 'width_mm' => 240, 'height_mm' => 80, 'max_payload_g' => 6000 ),
    'G12' => array( 'category' => 'Grande', 'length_mm' => 320, 'width_mm' => 240, 'height_mm' => 120, 'max_payload_g' => 9000 ),
    'G16' => array( 'category' => 'Grande', 'length_mm' => 320, 'width_mm' => 240, 'height_mm' => 160, 'max_payload_g' => 11000 ),
    'G20' => array( 'category' => 'Grande', 'length_mm' => 320, 'width_mm' => 240, 'height_mm' => 200, 'max_payload_g' => 14000 ),
    'S04' => array( 'category' => 'Super', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 40, 'max_payload_g' => 6000 ),
    'S08' => array( 'category' => 'Super', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 80, 'max_payload_g' => 11000 ),
    'S12' => array( 'category' => 'Super', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 120, 'max_payload_g' => 17000 ),
    'S16' => array( 'category' => 'Super', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 160, 'max_payload_g' => 22000 ),
    'S20' => array( 'category' => 'Super', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 200, 'max_payload_g' => 28000 ),
    'S24' => array( 'category' => 'Super', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 240, 'max_payload_g' => null ),
    'S28' => array( 'category' => 'Super', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 280, 'max_payload_g' => null ),
    'H12' => array( 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 120, 'max_payload_g' => null ),
    'H20' => array( 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 200, 'max_payload_g' => null ),
    'H28' => array( 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 280, 'max_payload_g' => null ),
    'H32' => array( 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 320, 'max_payload_g' => null ),
    'H48' => array( 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 480, 'max_payload_g' => null ),
);
packaging_api_assert( 'catálogo tem 21 itens', 21 === count( $catalog ) );
packaging_api_assert( 'catálogo não inclui envelopes', ! isset( $catalog['EM'], $catalog['EG'] ) );
foreach ( $expected_catalog as $code => $expected ) {
    $actual = $catalog[ $code ] ?? array();
    packaging_api_assert( $code . ' tem categoria e medidas corretas', $expected['category'] === ( $actual['category'] ?? null ) && $expected['length_mm'] === ( $actual['length_mm'] ?? null ) && $expected['width_mm'] === ( $actual['width_mm'] ?? null ) && $expected['height_mm'] === ( $actual['height_mm'] ?? null ) );
    packaging_api_assert( $code . ' tem carga sugerida correta', $expected['max_payload_g'] === ( $actual['max_payload_g'] ?? null ) );
}

/**
 * Lista os métodos HTTP registrados para um caminho.
 *
 * O `register_rest_route` aceita tanto um descritor único quanto uma lista deles, e a rota por id
 * passou a usar a segunda forma ao ganhar o DELETE — por isso a leitura precisa cobrir as duas.
 *
 * @param string $path Caminho registrado.
 * @return array<int,string> Métodos encontrados.
 */
function packaging_api_route_methods( string $path ): array {
    global $packaging_api_registered_routes;

    $methods = array();
    foreach ( $packaging_api_registered_routes as $route ) {
        if ( (string) ( $route[1] ?? '' ) !== $path ) {
            continue;
        }

        $args        = (array) ( $route[2] ?? array() );
        $descriptors = isset( $args['methods'] ) ? array( $args ) : $args;
        foreach ( $descriptors as $descriptor ) {
            if ( is_array( $descriptor ) && isset( $descriptor['methods'] ) ) {
                $methods[] = (string) $descriptor['methods'];
            }
        }
    }

    return $methods;
}

echo "Scenario 8: rotas REST do vendor estão expostas\n";
papelito_packaging_register_vendor_routes();
$registered_paths = array_map( static fn ( array $route ): string => (string) ( $route[1] ?? '' ), $packaging_api_registered_routes );
packaging_api_assert( 'cinco registros de rota existem', 5 === count( $registered_paths ) );
packaging_api_assert( 'rota de lista existe', in_array( '/vendor/me/packaging-profiles', $registered_paths, true ) );
packaging_api_assert( 'rota de catálogo existe', in_array( '/vendor/me/packaging-profiles/catalog', $registered_paths, true ) );
packaging_api_assert( 'rota de desativação existe', in_array( '/vendor/me/packaging-profiles/(?P<id>\d+)/deactivate', $registered_paths, true ) );
packaging_api_assert( 'rota de reativação existe', in_array( '/vendor/me/packaging-profiles/(?P<id>\d+)/reactivate', $registered_paths, true ) );
packaging_api_assert( 'rota por id aceita PUT e DELETE', in_array( 'DELETE', packaging_api_route_methods( '/vendor/me/packaging-profiles/(?P<id>\d+)' ), true ) && in_array( 'PUT', packaging_api_route_methods( '/vendor/me/packaging-profiles/(?P<id>\d+)' ), true ) );

if ( $failures > 0 ) {
    exit( 1 );
}

echo "RESULT: all assertions passed\n";
