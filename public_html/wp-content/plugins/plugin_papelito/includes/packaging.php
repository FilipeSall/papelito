<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.
/**
 * Perfis de caixa do vendor e escolha determinística da embalagem.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/*
 * Folga assumida por não fazermos encaixe 3D: a fração do volume interno que a
 * regra considera realmente aproveitável. Nasce conservador e é ajustável por
 * vendor. Ver seção 12.4 de docs/braspress/13-correios-physical-packaging-research.md.
 */
const PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR = 0.75;

const PAPELITO_PACKAGING_SNAPSHOT_SCHEMA_VERSION = 1;
const PAPELITO_PACKAGING_MEASUREMENT_PROFILE     = 'profile';
const PAPELITO_PACKAGING_MEASUREMENT_LEGACY      = 'legacy_synthetic';

/**
 * Volume interno de um perfil, em milímetros cúbicos.
 *
 * @param array<string,mixed> $profile Perfil de caixa do vendor.
 * @return int Volume interno; zero quando alguma dimensão não é positiva.
 */
function papelito_packaging_profile_volume_mm3( array $profile ): int {
	$length = (int) ( $profile['length_mm'] ?? 0 );
	$width  = (int) ( $profile['width_mm'] ?? 0 );
	$height = (int) ( $profile['height_mm'] ?? 0 );

	return $length > 0 && $width > 0 && $height > 0 ? $length * $width * $height : 0;
}

/**
 * Escolhe a menor caixa do vendor que comporta o conjunto de itens.
 *
 * @param array<int,array<string,mixed>> $profiles Perfis ativos do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return array<string,mixed>|null Perfil escolhido ou nulo quando nenhum serve.
 */
function papelito_packaging_choose_profile( array $profiles, array $items, float $usable_factor = PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR ): ?array {
	foreach ( papelito_packaging_order_profiles( $profiles ) as $profile ) {
		if ( papelito_packaging_profile_fits( $profile, $items, $usable_factor ) ) {
			return $profile;
		}
	}

	return null;
}

/**
 * Diz se um perfil comporta o conjunto, sem encaixe 3D.
 *
 * @param array<string,mixed>            $profile Perfil de caixa do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return bool Se o conjunto passa em todos os testes de caber.
 */
function papelito_packaging_profile_fits( array $profile, array $items, float $usable_factor = PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR ): bool {
	return papelito_packaging_volume_fits( $profile, $items, $usable_factor )
		&& papelito_packaging_largest_piece_fits( $profile, $items )
		&& papelito_packaging_weight_fits( $profile, $items );
}

/**
 * Normaliza o fator de aproveitamento para a faixa útil.
 *
 * Fator fora de `(0, 1]` é dado inválido, não intenção: zero ou negativo
 * tornaria todo vendor inelegível, e acima de 1 prometeria caber mais do que a
 * caixa comporta. Nos dois casos vale o padrão conservador.
 *
 * @param float $factor Fator informado pelo vendor ou pela configuração.
 * @return float Fator utilizável.
 */
function papelito_packaging_normalize_usable_factor( float $factor ): float {
	return $factor > 0.0 && $factor <= 1.0 ? $factor : PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR;
}

/**
 * Volume total dos itens do conjunto, em milímetros cúbicos.
 *
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return int Soma de volume vezes quantidade.
 */
function papelito_packaging_items_volume_mm3( array $items ): int {
	$volume = 0;

	foreach ( $items as $item ) {
		$item    = is_array( $item ) ? $item : array();
		$volume += papelito_packaging_profile_volume_mm3( $item ) * max( 0, (int) ( $item['qty'] ?? 0 ) );
	}

	return $volume;
}

/**
 * Diz se o volume dos itens cabe no volume aproveitável da caixa.
 *
 * @param array<string,mixed>            $profile Perfil de caixa do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @param float                          $usable_factor Fração aproveitável do volume interno.
 * @return bool Se o conjunto cabe em volume.
 */
function papelito_packaging_volume_fits( array $profile, array $items, float $usable_factor ): bool {
	$usable = papelito_packaging_profile_volume_mm3( $profile ) * papelito_packaging_normalize_usable_factor( $usable_factor );

	return $usable > 0.0 && papelito_packaging_items_volume_mm3( $items ) <= $usable;
}

/**
 * Dimensões de uma caixa ou item em ordem decrescente.
 *
 * Comparar ordenado é o que permite girar a peça sem calcular orientação.
 *
 * @param array<string,mixed> $source Perfil ou item com dimensões em milímetros.
 * @return array<int,int> Comprimento, largura e altura, do maior para o menor.
 */
function papelito_packaging_sorted_dimensions_mm( array $source ): array {
	$dimensions = array(
		(int) ( $source['length_mm'] ?? 0 ),
		(int) ( $source['width_mm'] ?? 0 ),
		(int) ( $source['height_mm'] ?? 0 ),
	);
	rsort( $dimensions );

	return $dimensions;
}

/**
 * Diz se a maior peça do conjunto entra na caixa pelas dimensões ordenadas.
 *
 * Impede que um volume total pequeno "caiba" numa caixa em que a peça
 * fisicamente não entra.
 *
 * @param array<string,mixed>            $profile Perfil de caixa do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return bool Se toda peça entra.
 */
function papelito_packaging_largest_piece_fits( array $profile, array $items ): bool {
	$box = papelito_packaging_sorted_dimensions_mm( $profile );

	foreach ( $items as $item ) {
		$piece = papelito_packaging_sorted_dimensions_mm( is_array( $item ) ? $item : array() );
		foreach ( $piece as $index => $side ) {
			if ( $side <= 0 || $side > $box[ $index ] ) {
				return false;
			}
		}
	}

	return true;
}

/**
 * Peso total dos itens do conjunto, em gramas.
 *
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return int Soma de peso vezes quantidade.
 */
function papelito_packaging_items_weight_g( array $items ): int {
	$weight = 0;

	foreach ( $items as $item ) {
		$item    = is_array( $item ) ? $item : array();
		$weight += max( 0, (int) ( $item['weight_g'] ?? 0 ) ) * max( 0, (int) ( $item['qty'] ?? 0 ) );
	}

	return $weight;
}

/**
 * Diz se o peso dos itens mais a tara cabe na carga máxima do perfil.
 *
 * @param array<string,mixed>            $profile Perfil de caixa do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return bool Se o peso cabe; perfil sem carga máxima declarada não limita.
 */
function papelito_packaging_weight_fits( array $profile, array $items ): bool {
	$max_payload = $profile['max_payload_g'] ?? null;
	if ( null === $max_payload ) {
		return true;
	}

	$loaded = papelito_packaging_items_weight_g( $items ) + max( 0, (int) ( $profile['tare_weight_g'] ?? 0 ) );

	return $loaded <= (int) $max_payload;
}

/**
 * Ordena perfis do menor para o maior volume interno, com desempate estável por código.
 *
 * @param array<int,array<string,mixed>> $profiles Perfis ativos do vendor.
 * @return array<int,array<string,mixed>> Perfis ordenados.
 */
function papelito_packaging_order_profiles( array $profiles ): array {
	$ordered = array_values( $profiles );
	usort(
		$ordered,
		static function ( array $left, array $right ): int {
			$volumes = papelito_packaging_profile_volume_mm3( $left ) <=> papelito_packaging_profile_volume_mm3( $right );

			return 0 !== $volumes ? $volumes : strcmp( (string) ( $left['code'] ?? '' ), (string) ( $right['code'] ?? '' ) );
		}
	);

	return $ordered;
}

/**
 * Resolve a embalagem de um conjunto, deixando o override do vendor mandar.
 *
 * @param array<int,array<string,mixed>> $profiles Perfis ativos do vendor.
 * @param array<int,array<string,mixed>> $rules Regras de faixa do vendor.
 * @param array<int,array<string,mixed>> $lines Linhas a embalar, com alvo e dimensões.
 * @param float                          $usable_factor Fração aproveitável do volume interno.
 * @return array<string,mixed>|null Perfil resolvido ou nulo quando nenhum serve.
 */
function papelito_packaging_resolve_profile( array $profiles, array $rules, array $lines, float $usable_factor = PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR ): ?array {
	$override = papelito_packaging_override_profile( $profiles, $rules, $lines );

	return null !== $override ? $override : papelito_packaging_choose_profile( $profiles, $lines, $usable_factor );
}

/**
 * Perfil forçado por uma regra do vendor, quando ela se aplica ao conjunto.
 *
 * A regra descreve um alvo, então só manda quando o conjunto inteiro é daquele
 * alvo. Carrinho misto não tem alvo único e volta para o cálculo.
 *
 * @param array<int,array<string,mixed>> $profiles Perfis ativos do vendor.
 * @param array<int,array<string,mixed>> $rules Regras de faixa do vendor.
 * @param array<int,array<string,mixed>> $lines Linhas a embalar.
 * @return array<string,mixed>|null Perfil forçado ou nulo quando não há override aplicável.
 */
function papelito_packaging_override_profile( array $profiles, array $rules, array $lines ): ?array {
	$target = papelito_packaging_single_target( $lines );
	if ( null === $target ) {
		return null;
	}

	$matching_rules = papelito_packaging_matching_rules( $rules, $target );
	if ( 1 !== count( $matching_rules ) ) {
		return null;
	}

	return papelito_packaging_profile_by_id( $profiles, (int) ( $matching_rules[0]['profile_id'] ?? 0 ) );
}

/**
 * Encontra as regras que cobrem um alvo e quantidade determinados.
 *
 * A tabela evita o mesmo início de faixa, mas não representa exclusão entre
 * intervalos. Enquanto o writer transacional da API não existe, duas regras
 * aplicáveis invalidam o override para a escolha nunca depender da ordem SQL.
 *
 * @param array<int,array<string,mixed>>                 $rules Regras de faixa do vendor.
 * @param array{target_type:string,target_id:int,qty:int} $target Alvo único do conjunto.
 * @return array<int,array<string,mixed>> Regras que cobrem exatamente o alvo.
 */
function papelito_packaging_matching_rules( array $rules, array $target ): array {
	$matching_rules = array();

	foreach ( $rules as $rule ) {
		if ( is_array( $rule ) && papelito_packaging_rule_matches( $rule, $target ) ) {
			$matching_rules[] = $rule;
		}
	}

	return $matching_rules;
}

/**
 * Alvo único de um conjunto de linhas, com a quantidade somada.
 *
 * @param array<int,array<string,mixed>> $lines Linhas a embalar.
 * @return array{target_type:string,target_id:int,qty:int}|null Alvo único ou nulo quando o conjunto é misto.
 */
function papelito_packaging_single_target( array $lines ): ?array {
	$target = null;

	foreach ( $lines as $line ) {
		$line    = is_array( $line ) ? $line : array();
		$type    = (string) ( $line['target_type'] ?? '' );
		$id      = (int) ( $line['target_id'] ?? 0 );
		$qty     = max( 0, (int) ( $line['qty'] ?? 0 ) );
		if ( '' === $type || $id <= 0 ) {
			return null;
		}
		if ( null === $target ) {
			$target = array( 'target_type' => $type, 'target_id' => $id, 'qty' => 0 );
		}
		if ( $target['target_type'] !== $type || $target['target_id'] !== $id ) {
			return null;
		}
		$target['qty'] += $qty;
	}

	return $target;
}

/**
 * Diz se uma regra cobre o alvo e a quantidade do conjunto.
 *
 * @param array<string,mixed>                             $rule Regra de faixa do vendor.
 * @param array{target_type:string,target_id:int,qty:int} $target Alvo único do conjunto.
 * @return bool Se a regra se aplica.
 */
function papelito_packaging_rule_matches( array $rule, array $target ): bool {
	return (string) ( $rule['target_type'] ?? '' ) === $target['target_type']
		&& (int) ( $rule['target_id'] ?? 0 ) === $target['target_id']
		&& $target['qty'] >= (int) ( $rule['min_qty'] ?? 0 )
		&& $target['qty'] <= (int) ( $rule['max_qty'] ?? 0 );
}

/**
 * Localiza um perfil ativo do vendor pelo identificador.
 *
 * @param array<int,array<string,mixed>> $profiles Perfis ativos do vendor.
 * @param int                            $profile_id Identificador procurado.
 * @return array<string,mixed>|null Perfil encontrado ou nulo.
 */
function papelito_packaging_profile_by_id( array $profiles, int $profile_id ): ?array {
	foreach ( $profiles as $profile ) {
		if ( is_array( $profile ) && $profile_id > 0 && (int) ( $profile['id'] ?? 0 ) === $profile_id ) {
			return $profile;
		}
	}

	return null;
}

/**
 * Nomes das tabelas de embalagem, com o prefixo do $wpdb.
 *
 * @return array<string,string>
 */
function papelito_packaging_table_names(): array {
	global $wpdb;

	return array(
		'profiles' => $wpdb->prefix . 'papelito_packaging_profiles',
		'rules'    => $wpdb->prefix . 'papelito_packing_rules',
	);
}

/**
 * Cria/atualiza as tabelas de embalagem via dbDelta.
 *
 * Chamado pelo bootstrap de migration em `plugin_papelito.php` quando
 * `papelito_db_version` for inferior à versão atual. Aditivo e idempotente.
 *
 * @return void
 */
function papelito_packaging_install_tables(): void {
	global $wpdb;

	$tables          = papelito_packaging_table_names();
	$charset_collate = $wpdb->get_charset_collate();

	$profiles_sql = "CREATE TABLE {$tables['profiles']} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_id BIGINT(20) UNSIGNED NOT NULL,
  code VARCHAR(32) NOT NULL,
  label VARCHAR(96) NOT NULL,
  length_mm INT(10) UNSIGNED NOT NULL,
  width_mm INT(10) UNSIGNED NOT NULL,
  height_mm INT(10) UNSIGNED NOT NULL,
  tare_weight_g INT(10) UNSIGNED NOT NULL DEFAULT 0,
  max_payload_g INT(10) UNSIGNED NULL DEFAULT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'custom',
  active TINYINT(1) NOT NULL DEFAULT 1,
  version INT(10) UNSIGNED NOT NULL DEFAULT 1,
  updated_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_vendor_code (vendor_id, code),
  KEY idx_vendor_active (vendor_id, active)
) {$charset_collate};";

	$rules_sql = "CREATE TABLE {$tables['rules']} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_id BIGINT(20) UNSIGNED NOT NULL,
  target_type VARCHAR(16) NOT NULL,
  target_id BIGINT(20) UNSIGNED NOT NULL,
  min_qty INT(10) UNSIGNED NOT NULL,
  max_qty INT(10) UNSIGNED NOT NULL,
  profile_id BIGINT(20) UNSIGNED NOT NULL,
  version INT(10) UNSIGNED NOT NULL DEFAULT 1,
  updated_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_vendor_target_range (vendor_id, target_type, target_id, min_qty),
  KEY idx_vendor_target (vendor_id, target_type, target_id),
  KEY idx_profile (profile_id)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( $profiles_sql );
	dbDelta( $rules_sql );
}

/**
 * Normaliza um pacote para a unidade canônica, em inteiros.
 *
 * @param array<string,mixed> $package Pacote cru.
 * @return array{length_mm:int,width_mm:int,height_mm:int,weight_g:int,count:int}|null Pacote canônico ou nulo quando inválido.
 */
function papelito_packaging_normalize_package( array $package ): ?array {
	$canonical = array(
		'length_mm' => (int) ( $package['length_mm'] ?? 0 ),
		'width_mm'  => (int) ( $package['width_mm'] ?? 0 ),
		'height_mm' => (int) ( $package['height_mm'] ?? 0 ),
		'weight_g'  => (int) ( $package['weight_g'] ?? 0 ),
		'count'     => (int) ( $package['count'] ?? 0 ),
	);

	if ( $canonical['length_mm'] <= 0 || $canonical['width_mm'] <= 0 || $canonical['height_mm'] <= 0 ) {
		return null;
	}

	return $canonical['weight_g'] < 0 || $canonical['count'] < 1 ? null : $canonical;
}

/**
 * Normaliza a lista de pacotes de um snapshot.
 *
 * @param mixed $packages Lista crua de pacotes.
 * @return array<int,array<string,int>>|null Pacotes canônicos ou nulo quando algum é inválido ou a lista é vazia.
 */
function papelito_packaging_normalize_packages( mixed $packages ): ?array {
	if ( ! is_array( $packages ) || array() === $packages ) {
		return null;
	}

	$canonical = array();
	foreach ( $packages as $package ) {
		$normalized = is_array( $package ) ? papelito_packaging_normalize_package( $package ) : null;
		if ( null === $normalized ) {
			return null;
		}
		$canonical[] = $normalized;
	}

	return $canonical;
}

/**
 * Assinatura estável da embalagem de um snapshot.
 *
 * Cobre o que descreve o objeto físico: vendor, origem da medida, versão do
 * perfil, pacotes e totais. Destino, origem e valor mercantil ficam de fora de
 * propósito — são contexto da cotação, e a chave de cache os acrescenta ao lado
 * deste hash em vez de diluí-los dentro dele.
 *
 * @param array<string,mixed> $snapshot Snapshot com os pacotes já canônicos.
 * @return string Hash hexadecimal estável.
 */
function papelito_packaging_physical_hash( array $snapshot ): string {
	return hash(
		'sha256',
		(string) wp_json_encode(
			array(
				'schema_version'     => $snapshot['schema_version'] ?? null,
				'vendor_id'          => $snapshot['vendor_id'] ?? null,
				'measurement_source' => $snapshot['measurement_source'] ?? null,
				'approval_version'   => $snapshot['approval_version'] ?? null,
				'packages'           => $snapshot['packages'] ?? array(),
				'total_volumes'      => $snapshot['total_volumes'] ?? null,
				'total_weight_g'     => $snapshot['total_weight_g'] ?? null,
			)
		)
	);
}

/**
 * Monta o LogisticsSnapshot canônico de uma cotação.
 *
 * Os totais são derivados dos pacotes, nunca recebidos: `total_volumes` é a soma
 * exata de `packages[].count` e `total_weight_g` a soma de peso vezes count.
 *
 * @param array<string,mixed> $input Vendor, CEPs, valor mercantil, origem da medida, versão e pacotes.
 * @return array<string,mixed>|null Snapshot completo ou nulo quando a embalagem não é válida.
 */
function papelito_packaging_build_snapshot( array $input ): ?array {
	$packages = papelito_packaging_normalize_packages( $input['packages'] ?? null );
	if ( null === $packages ) {
		return null;
	}

	$source   = (string) ( $input['measurement_source'] ?? PAPELITO_PACKAGING_MEASUREMENT_LEGACY );
	$volumes  = 0;
	$weight_g = 0;
	foreach ( $packages as $package ) {
		$volumes  += $package['count'];
		$weight_g += $package['weight_g'] * $package['count'];
	}

	$snapshot = array(
		'schema_version'          => PAPELITO_PACKAGING_SNAPSHOT_SCHEMA_VERSION,
		'vendor_id'               => (int) ( $input['vendor_id'] ?? 0 ),
		'origin_cep'              => (string) ( $input['origin_cep'] ?? '' ),
		'destination_cep'         => (string) ( $input['destination_cep'] ?? '' ),
		'merchandise_value_cents' => max( 0, (int) ( $input['merchandise_value_cents'] ?? 0 ) ),
		'measurement_source'      => $source,
		'approval_version'        => PAPELITO_PACKAGING_MEASUREMENT_PROFILE === $source ? (int) ( $input['approval_version'] ?? 0 ) : null,
		'packages'                => $packages,
		'total_volumes'           => $volumes,
		'total_weight_g'          => $weight_g,
	);

	$snapshot['physical_hash'] = papelito_packaging_physical_hash( $snapshot );

	return $snapshot;
}

/**
 * Lê os perfis ativos de embalagem de um vendor.
 *
 * O filtro `active` é aplicado no SQL e novamente na normalização para que uma
 * linha inconsistente nunca se torne uma caixa elegível por acidente.
 *
 * @param int $vendor_id ID do vendor.
 * @return array<int,array<string,mixed>> Perfis ativos e válidos.
 */
function papelito_packaging_profiles_for_vendor( int $vendor_id ): array {
	if ( $vendor_id <= 0 ) {
		return array();
	}

	global $wpdb;
	$tables = papelito_packaging_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, vendor_id, code, label, length_mm, width_mm, height_mm, tare_weight_g, max_payload_g, source, active, version FROM {$tables['profiles']} WHERE vendor_id = %d AND active = %d ORDER BY id ASC",
			$vendor_id,
			1
		),
		ARRAY_A
	);

	$profiles = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$profile = papelito_packaging_profile_row( is_array( $row ) ? $row : (array) $row );
		if ( null !== $profile ) {
			$profiles[] = $profile;
		}
	}

	return $profiles;
}

/**
 * Lê as regras de override de embalagem de um vendor.
 *
 * Regras para perfis inativos ou inexistentes permanecem no formato do
 * resolvedor, que então recua para a escolha física determinística.
 *
 * @param int $vendor_id ID do vendor.
 * @return array<int,array<string,mixed>> Regras de faixa válidas.
 */
function papelito_packaging_rules_for_vendor( int $vendor_id ): array {
	if ( $vendor_id <= 0 ) {
		return array();
	}

	global $wpdb;
	$tables = papelito_packaging_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, vendor_id, target_type, target_id, min_qty, max_qty, profile_id, version FROM {$tables['rules']} WHERE vendor_id = %d ORDER BY id ASC",
			$vendor_id
		),
		ARRAY_A
	);

	$rules = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$rule = papelito_packaging_rule_row( is_array( $row ) ? $row : (array) $row );
		if ( null !== $rule ) {
			$rules[] = $rule;
		}
	}

	return $rules;
}

/**
 * Normaliza uma linha de perfil lida do banco.
 *
 * @param array<string,mixed> $row Linha crua.
 * @return array<string,mixed>|null Perfil válido ou nulo.
 */
function papelito_packaging_profile_row( array $row ): ?array {
	if ( 1 !== (int) ( $row['active'] ?? 0 ) || (int) ( $row['vendor_id'] ?? 0 ) <= 0 || '' === (string) ( $row['code'] ?? '' ) ) {
		return null;
	}

	$profile = array(
		'id'            => (int) ( $row['id'] ?? 0 ),
		'vendor_id'     => (int) ( $row['vendor_id'] ?? 0 ),
		'code'          => (string) $row['code'],
		'label'         => (string) ( $row['label'] ?? '' ),
		'length_mm'     => (int) ( $row['length_mm'] ?? 0 ),
		'width_mm'      => (int) ( $row['width_mm'] ?? 0 ),
		'height_mm'     => (int) ( $row['height_mm'] ?? 0 ),
		'tare_weight_g' => max( 0, (int) ( $row['tare_weight_g'] ?? 0 ) ),
		'max_payload_g' => null === ( $row['max_payload_g'] ?? null ) ? null : max( 0, (int) $row['max_payload_g'] ),
		'source'        => (string) ( $row['source'] ?? 'custom' ),
		'active'        => 1,
		'version'       => (int) ( $row['version'] ?? 0 ),
	);

	return $profile['id'] > 0 && $profile['version'] > 0 && papelito_packaging_profile_volume_mm3( $profile ) > 0 ? $profile : null;
}

/**
 * Normaliza uma linha de regra lida do banco.
 *
 * @param array<string,mixed> $row Linha crua.
 * @return array<string,mixed>|null Regra válida ou nulo.
 */
function papelito_packaging_rule_row( array $row ): ?array {
	$rule = array(
		'id'          => (int) ( $row['id'] ?? 0 ),
		'vendor_id'   => (int) ( $row['vendor_id'] ?? 0 ),
		'target_type' => (string) ( $row['target_type'] ?? '' ),
		'target_id'   => (int) ( $row['target_id'] ?? 0 ),
		'min_qty'     => (int) ( $row['min_qty'] ?? 0 ),
		'max_qty'     => (int) ( $row['max_qty'] ?? 0 ),
		'profile_id'  => (int) ( $row['profile_id'] ?? 0 ),
		'version'     => (int) ( $row['version'] ?? 0 ),
	);

	return $rule['id'] > 0 && $rule['vendor_id'] > 0 && '' !== $rule['target_type'] && $rule['target_id'] > 0 && $rule['min_qty'] > 0 && $rule['max_qty'] >= $rule['min_qty'] && $rule['profile_id'] > 0 ? $rule : null;
}

/**
 * Converte todos os itens do carrinho em linhas físicas canônicas.
 *
 * A conversão é fail-closed: uma única linha sem peso ou dimensão positiva
 * invalida a lista inteira e impede um pacote parcial.
 *
 * @param array<int,array<string,mixed>> $items Itens do filtro Braspress.
 * @return array<int,array<string,mixed>>|null Linhas físicas ou nulo.
 */
function papelito_packaging_items_to_lines( array $items ): ?array {
	if ( empty( $items ) || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}

	$lines = array();
	foreach ( $items as $item ) {
		$line = is_array( $item ) ? papelito_packaging_item_to_line( $item ) : null;
		if ( null === $line ) {
			return null;
		}
		$lines[] = $line;
	}

	return $lines;
}

/**
 * Converte um item do carrinho em uma linha física.
 *
 * Kits usam o ID da entidade Kit e as dimensões declaradas da embalagem;
 * produtos comuns usam o ID e os atributos físicos do WooCommerce.
 *
 * @param array<string,mixed> $item Item cru.
 * @return array<string,mixed>|null Linha física ou nulo.
 */
function papelito_packaging_item_to_line( array $item ): ?array {
	$product_id = (int) ( $item['product_id'] ?? 0 );
	$qty        = (int) ( $item['qty'] ?? 0 );
	if ( $product_id <= 0 || $qty <= 0 ) {
		return null;
	}

	$product = wc_get_product( $product_id );
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_weight' ) ) {
		return null;
	}

	$kit = function_exists( 'papelito_kit_get_by_product' ) ? papelito_kit_get_by_product( $product_id ) : null;
	if ( is_array( $kit ) ) {
		return papelito_packaging_kit_line( $kit, $qty );
	}

	return papelito_packaging_product_line( $product, $product_id, $qty );
}

/**
 * Monta a linha física de um produto WooCommerce.
 *
 * @param object $product Produto WooCommerce.
 * @param int    $product_id ID do produto.
 * @param int    $qty Quantidade.
 * @return array<string,mixed>|null Linha ou nulo quando incompleta.
 */
function papelito_packaging_product_line( object $product, int $product_id, int $qty ): ?array {
	if ( ! method_exists( $product, 'get_length' ) || ! method_exists( $product, 'get_width' ) || ! method_exists( $product, 'get_height' ) ) {
		return null;
	}

	$dimensions = papelito_packaging_product_dimensions_mm( $product );
	$weight_g   = papelito_packaging_weight_g( $product->get_weight() );
	if ( null === $dimensions || null === $weight_g ) {
		return null;
	}

	return array_merge(
		array(
			'target_type' => 'product',
			'target_id'   => $product_id,
			'qty'         => $qty,
			'weight_g'    => $weight_g,
		),
		$dimensions
	);
}

/**
 * Monta a linha física de um Kit.
 *
 * A dimensão vem de `package_*` em centímetros; o peso é recalculado dos
 * componentes e brindes sem reaplicar limites exclusivos dos Correios.
 *
 * @param array<string,mixed> $kit Linha da entidade Kit.
 * @param int                 $qty Quantidade de Kits.
 * @return array<string,mixed>|null Linha ou nulo quando incompleta.
 */
function papelito_packaging_kit_line( array $kit, int $qty ): ?array {
	$kit_id = (int) ( $kit['id'] ?? 0 );
	$dimensions = papelito_packaging_kit_dimensions_mm( $kit );
	$weight_g   = papelito_packaging_kit_weight_g( $kit_id );
	if ( $kit_id <= 0 || null === $dimensions || null === $weight_g ) {
		return null;
	}

	return array_merge(
		array(
			'target_type' => 'kit',
			'target_id'   => $kit_id,
			'qty'         => $qty,
			'weight_g'    => $weight_g,
		),
		$dimensions
	);
}

/**
 * Lê e converte dimensões de um produto WooCommerce para milímetros inteiros.
 *
 * @param object $product Produto físico.
 * @return array{length_mm:int,width_mm:int,height_mm:int}|null Dimensões ou nulo.
 */
function papelito_packaging_product_dimensions_mm( object $product ): ?array {
	$dimensions = array(
		'length_mm' => papelito_packaging_dimension_mm( $product->get_length() ),
		'width_mm'  => papelito_packaging_dimension_mm( $product->get_width() ),
		'height_mm' => papelito_packaging_dimension_mm( $product->get_height() ),
	);

	return in_array( null, $dimensions, true ) ? null : $dimensions;
}

/**
 * Converte dimensões declaradas do Kit, armazenadas em centímetros.
 *
 * @param array<string,mixed> $kit Linha do Kit.
 * @return array{length_mm:int,width_mm:int,height_mm:int}|null Dimensões ou nulo.
 */
function papelito_packaging_kit_dimensions_mm( array $kit ): ?array {
	$dimensions = array(
		'length_mm' => papelito_packaging_centimeters_to_mm( $kit['package_length'] ?? null ),
		'width_mm'  => papelito_packaging_centimeters_to_mm( $kit['package_width'] ?? null ),
		'height_mm' => papelito_packaging_centimeters_to_mm( $kit['package_height'] ?? null ),
	);

	return in_array( null, $dimensions, true ) ? null : $dimensions;
}

/**
 * Converte uma medida WooCommerce para milímetros sem aceitar zero ou inválido.
 *
 * @param mixed $value Medida na unidade configurada pelo WooCommerce.
 * @return int|null Milímetros inteiros ou nulo.
 */
function papelito_packaging_dimension_mm( mixed $value ): ?int {
	if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value <= 0 || ! function_exists( 'wc_get_dimension' ) ) {
		return null;
	}

	$converted = wc_get_dimension( $value, 'mm' );
	$millimeters = is_numeric( $converted ) && is_finite( (float) $converted ) ? (int) round( (float) $converted, 0, PHP_ROUND_HALF_UP ) : 0;

	return $millimeters > 0 ? $millimeters : null;
}

/**
 * Converte centímetros declarados do Kit para milímetros inteiros.
 *
 * @param mixed $value Medida em centímetros.
 * @return int|null Milímetros inteiros ou nulo.
 */
function papelito_packaging_centimeters_to_mm( mixed $value ): ?int {
	if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value <= 0 ) {
		return null;
	}

	$millimeters = (int) round( (float) $value * 10, 0, PHP_ROUND_HALF_UP );

	return $millimeters > 0 ? $millimeters : null;
}

/**
 * Converte um peso WooCommerce para gramas inteiros.
 *
 * @param mixed $value Peso na unidade configurada pelo WooCommerce.
 * @return int|null Gramas inteiros ou nulo.
 */
function papelito_packaging_weight_g( mixed $value ): ?int {
	if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value <= 0 || ! function_exists( 'wc_get_weight' ) ) {
		return null;
	}

	$converted = wc_get_weight( $value, 'g' );
	$grams = is_numeric( $converted ) && is_finite( (float) $converted ) ? (int) round( (float) $converted, 0, PHP_ROUND_HALF_UP ) : 0;

	return $grams > 0 ? $grams : null;
}

/**
 * Calcula o peso de um Kit a partir de seus componentes físicos.
 *
 * A rotina não chama o validador legado de Kit, pois o limite de 30 kg dos
 * Correios não pertence à cotação Braspress.
 *
 * @param int $kit_id ID da entidade Kit.
 * @return int|null Peso de uma unidade em gramas ou nulo.
 */
function papelito_packaging_kit_weight_g( int $kit_id ): ?int {
	if ( $kit_id <= 0 || ! function_exists( 'papelito_kit_items' ) || ! function_exists( 'papelito_kit_merchandise' ) ) {
		return null;
	}

	$weight_g = papelito_packaging_kit_component_weight_g( papelito_kit_items( $kit_id ) );
	if ( null === $weight_g ) {
		return null;
	}

	$merchandise_weight_g = papelito_packaging_kit_merchandise_weight_g( papelito_kit_merchandise( $kit_id ) );
	if ( null === $merchandise_weight_g ) {
		return null;
	}

	$total = $weight_g + $merchandise_weight_g;

	return $total > 0 ? $total : null;
}

/**
 * Soma o peso dos produtos componentes de um Kit.
 *
 * @param array<int,array<string,mixed>> $items Componentes.
 * @return int|null Peso em gramas ou nulo.
 */
function papelito_packaging_kit_component_weight_g( array $items ): ?int {
	if ( empty( $items ) ) {
		return null;
	}

	$total = 0;
	foreach ( $items as $item ) {
		$product_id = (int) ( $item['product_id'] ?? 0 );
		$quantity   = (int) ( $item['quantity'] ?? 0 );
		$product    = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$weight_g   = is_object( $product ) && method_exists( $product, 'get_weight' ) ? papelito_packaging_weight_g( $product->get_weight() ) : null;
		if ( null === $weight_g || $quantity <= 0 ) {
			return null;
		}
		$total += $weight_g * $quantity;
	}

	return $total > 0 ? $total : null;
}

/**
 * Soma o peso dos brindes físicos de um Kit.
 *
 * @param array<int,array<string,mixed>> $items Brindes.
 * @return int|null Peso em gramas ou nulo.
 */
function papelito_packaging_kit_merchandise_weight_g( array $items ): ?int {
	$total = 0;
	foreach ( $items as $item ) {
		$quantity = (int) ( $item['quantity'] ?? 0 );
		$weight_g = papelito_packaging_weight_g( $item['weight'] ?? null );
		if ( null === $weight_g || $quantity <= 0 ) {
			return null;
		}
		$total += $weight_g * $quantity;
	}

	return $total;
}

/**
 * Soma os valores declarados dos itens sem misturá-los ao peso físico.
 *
 * @param array<int,array<string,mixed>> $items Itens do carrinho.
 * @return int Valor em centavos.
 */
function papelito_packaging_declared_value_cents( array $items ): int {
	$total = 0;
	foreach ( $items as $item ) {
		$total += max( 0, (int) ( $item['declared_value_cents'] ?? 0 ) );
	}

	return $total;
}

/**
 * Converte o perfil resolvido em um snapshot e contrato físico Braspress.
 *
 * O snapshot continua sendo a fonte do hash e dos totais; a saída somente
 * traduz mm/g canônicos para metros/kg do provider.
 *
 * @param int                      $vendor_id ID do vendor.
 * @param array<string,mixed>      $profile Perfil escolhido.
 * @param array<int,array<string,mixed>> $lines Linhas físicas.
 * @param array<int,array<string,mixed>> $items Itens originais.
 * @return array<string,mixed>|null Pacote Braspress ou nulo.
 */
function papelito_packaging_braspress_package_from_profile( int $vendor_id, array $profile, array $lines, array $items ): ?array {
	$version = (int) ( $profile['version'] ?? 0 );
	$weight_g = papelito_packaging_items_weight_g( $lines ) + max( 0, (int) ( $profile['tare_weight_g'] ?? 0 ) );
	$snapshot = papelito_packaging_build_snapshot(
		array(
			'vendor_id'               => $vendor_id,
			'merchandise_value_cents' => papelito_packaging_declared_value_cents( $items ),
			'measurement_source'      => PAPELITO_PACKAGING_MEASUREMENT_PROFILE,
			'approval_version'        => $version,
			'packages'                => array(
				array(
					'length_mm' => (int) ( $profile['length_mm'] ?? 0 ),
					'width_mm'  => (int) ( $profile['width_mm'] ?? 0 ),
					'height_mm' => (int) ( $profile['height_mm'] ?? 0 ),
					'weight_g'  => $weight_g,
					'count'     => 1,
				),
			),
		)
	);

	return null === $snapshot || $version <= 0 ? null : papelito_packaging_braspress_package_from_snapshot( $snapshot );
}

/**
 * Traduz o snapshot canônico para peso, volumes e cubagem Braspress.
 *
 * @param array<string,mixed> $snapshot Snapshot físico válido.
 * @return array<string,mixed>|null Pacote Braspress ou nulo.
 */
function papelito_packaging_braspress_package_from_snapshot( array $snapshot ): ?array {
	$total_weight_g = (int) ( $snapshot['total_weight_g'] ?? 0 );
	$volumes        = (int) ( $snapshot['total_volumes'] ?? 0 );
	$cubagem        = papelito_packaging_snapshot_cubagem( $snapshot['packages'] ?? array() );
	if ( $total_weight_g <= 0 || $volumes <= 0 || empty( $cubagem ) ) {
		return null;
	}

	return array(
		'weight_kg'         => round( $total_weight_g / 1000, 2, PHP_ROUND_HALF_UP ),
		'volumes'           => $volumes,
		'cubagem'           => $cubagem,
		'approval_version'  => (int) ( $snapshot['approval_version'] ?? 0 ),
		'physical_hash'     => (string) ( $snapshot['physical_hash'] ?? '' ),
		'measurement_source' => (string) ( $snapshot['measurement_source'] ?? '' ),
	);
}

/**
 * Agrupa pacotes canônicos iguais na cubagem em metros.
 *
 * @param mixed $packages Pacotes do snapshot.
 * @return array<int,array{length_m:float,width_m:float,height_m:float,volumes:int}> Grupos.
 */
function papelito_packaging_snapshot_cubagem( mixed $packages ): array {
	if ( ! is_array( $packages ) ) {
		return array();
	}

	$groups = array();
	foreach ( $packages as $package ) {
		if ( ! is_array( $package ) ) {
			return array();
		}

		$key = implode( ':', array( (int) ( $package['length_mm'] ?? 0 ), (int) ( $package['width_mm'] ?? 0 ), (int) ( $package['height_mm'] ?? 0 ) ) );
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'length_m' => round( (int) ( $package['length_mm'] ?? 0 ) / 1000, 3, PHP_ROUND_HALF_UP ),
				'width_m'  => round( (int) ( $package['width_mm'] ?? 0 ) / 1000, 3, PHP_ROUND_HALF_UP ),
				'height_m' => round( (int) ( $package['height_mm'] ?? 0 ) / 1000, 3, PHP_ROUND_HALF_UP ),
				'volumes'  => 0,
			);
		}
		$groups[ $key ]['volumes'] += (int) ( $package['count'] ?? 0 );
	}

	return array_values( $groups );
}

/**
 * Monta o pacote físico Braspress para o filtro de cotação.
 *
 * Qualquer ausência de perfil, regra resolvida, medida ou capacidade deixa o
 * provider inelegível e nunca produz um WP_Error ou medida de fallback.
 *
 * @param int                      $vendor_id ID do vendor.
 * @param array<int,array<string,mixed>> $items Itens do carrinho.
 * @return array<string,mixed>|null Pacote físico ou nulo.
 */
function papelito_packaging_braspress_package( int $vendor_id, array $items ): ?array {
	$profiles = papelito_packaging_profiles_for_vendor( $vendor_id );
	if ( empty( $profiles ) ) {
		return null;
	}

	$lines = papelito_packaging_items_to_lines( $items );
	if ( null === $lines ) {
		return null;
	}

	$profile = papelito_packaging_resolve_profile( $profiles, papelito_packaging_rules_for_vendor( $vendor_id ), $lines, PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR );
	if ( null === $profile ) {
		return null;
	}

	return papelito_packaging_braspress_package_from_profile( $vendor_id, $profile, $lines, $items );
}

/**
 * Callback do filtro de pacote físico da Braspress.
 *
 * O primeiro argumento é o valor inicial do filtro; a decisão usa somente o
 * vendor e os itens e retorna nulo em toda falha de elegibilidade.
 *
 * @param mixed                    $ignored Valor inicial do filtro.
 * @param int                      $vendor_id ID do vendor.
 * @param array<int,array<string,mixed>> $items Itens do carrinho.
 * @return array<string,mixed>|null Pacote aprovado ou nulo.
 */
function papelito_packaging_braspress_package_filter( mixed $ignored, int $vendor_id, array $items ): ?array {
	return papelito_packaging_braspress_package( $vendor_id, $items );
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'papelito_braspress_physical_package', 'papelito_packaging_braspress_package_filter', 10, 3 );
}
