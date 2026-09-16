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
