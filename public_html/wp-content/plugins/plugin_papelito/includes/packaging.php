<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.

/**
 * Perfis de caixa do vendor e escolha determinística da embalagem.
 *
 * @package Papelito
 */

defined('ABSPATH') || exit;

/*
 * Folga assumida por não fazermos encaixe 3D: a fração do volume interno que a
 * regra considera realmente aproveitável. Nasce conservador e é ajustável por
 * vendor. Ver seção 12.4 de docs/braspress/13-correios-physical-packaging-research.md.
 */
const PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR = 0.75;

const PAPELITO_PACKAGING_SNAPSHOT_SCHEMA_VERSION = 1;
const PAPELITO_PACKAGING_MIN_ACTIVE_PROFILES     = 3;
const PAPELITO_PACKAGING_MEASUREMENT_PROFILE     = 'profile';
const PAPELITO_PACKAGING_MEASUREMENT_LEGACY      = 'legacy_synthetic';

const PAPELITO_PACKAGING_WRITE_RATE_LIMIT  = 20;
const PAPELITO_PACKAGING_WRITE_RATE_WINDOW = 60;

/**
 * Teto de caixas ativas por vendor.
 *
 * Inativas não contam: desativar é justamente o caminho para abrir vaga sem perder o histórico.
 */
const PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES = 24;

/**
 * Categoria dos modelos RPC Híper.
 */
const PAPELITO_PACKAGING_RPC_CATEGORY_HYPER = 'Híper';

/**
 * Mensagem pública para conflito de código de caixa.
 */
const PAPELITO_PACKAGING_CODE_CONFLICT_MESSAGE = 'Já existe uma caixa com este código.';

/**
 * Catálogo estático dos modelos RPC com unidades canônicas do domínio.
 *
 * Os modelos são referências para preenchimento e não são persistidos até o
 * vendor salvar um perfil. Os envelopes do guia ficam fora porque não têm altura.
 *
 * @return array<string,array<string,mixed>>
 */
function papelito_packaging_rpc_catalog(): array
{
	return array(
		'P04' => array('code' => 'P04', 'category' => 'Pequena', 'label' => 'Caixa RPC P04', 'length_mm' => 160, 'width_mm' => 120, 'height_mm' => 40, 'max_payload_g' => 1500),
		'C08' => array('code' => 'C08', 'category' => 'Comprida', 'label' => 'Caixa RPC C08', 'length_mm' => 320, 'width_mm' => 120, 'height_mm' => 80, 'max_payload_g' => 3000),
		'C12' => array('code' => 'C12', 'category' => 'Comprida', 'label' => 'Caixa RPC C12', 'length_mm' => 320, 'width_mm' => 120, 'height_mm' => 120, 'max_payload_g' => null),
		'M08' => array('code' => 'M08', 'category' => 'Média', 'label' => 'Caixa RPC M08', 'length_mm' => 240, 'width_mm' => 160, 'height_mm' => 80, 'max_payload_g' => 3000),
		'M12' => array('code' => 'M12', 'category' => 'Média', 'label' => 'Caixa RPC M12', 'length_mm' => 240, 'width_mm' => 160, 'height_mm' => 120, 'max_payload_g' => 4000),
		'G08' => array('code' => 'G08', 'category' => 'Grande', 'label' => 'Caixa RPC G08', 'length_mm' => 320, 'width_mm' => 240, 'height_mm' => 80, 'max_payload_g' => 6000),
		'G12' => array('code' => 'G12', 'category' => 'Grande', 'label' => 'Caixa RPC G12', 'length_mm' => 320, 'width_mm' => 240, 'height_mm' => 120, 'max_payload_g' => 9000),
		'G16' => array('code' => 'G16', 'category' => 'Grande', 'label' => 'Caixa RPC G16', 'length_mm' => 320, 'width_mm' => 240, 'height_mm' => 160, 'max_payload_g' => 11000),
		'G20' => array('code' => 'G20', 'category' => 'Grande', 'label' => 'Caixa RPC G20', 'length_mm' => 320, 'width_mm' => 240, 'height_mm' => 200, 'max_payload_g' => 14000),
		'S04' => array('code' => 'S04', 'category' => 'Super', 'label' => 'Caixa RPC S04', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 40, 'max_payload_g' => 6000),
		'S08' => array('code' => 'S08', 'category' => 'Super', 'label' => 'Caixa RPC S08', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 80, 'max_payload_g' => 11000),
		'S12' => array('code' => 'S12', 'category' => 'Super', 'label' => 'Caixa RPC S12', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 120, 'max_payload_g' => 17000),
		'S16' => array('code' => 'S16', 'category' => 'Super', 'label' => 'Caixa RPC S16', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 160, 'max_payload_g' => 22000),
		'S20' => array('code' => 'S20', 'category' => 'Super', 'label' => 'Caixa RPC S20', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 200, 'max_payload_g' => 28000),
		'S24' => array('code' => 'S24', 'category' => 'Super', 'label' => 'Caixa RPC S24', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 240, 'max_payload_g' => null),
		'S28' => array('code' => 'S28', 'category' => 'Super', 'label' => 'Caixa RPC S28', 'length_mm' => 480, 'width_mm' => 320, 'height_mm' => 280, 'max_payload_g' => null),
		'H12' => array('code' => 'H12', 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'label' => 'Caixa RPC H12', 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 120, 'max_payload_g' => null),
		'H20' => array('code' => 'H20', 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'label' => 'Caixa RPC H20', 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 200, 'max_payload_g' => null),
		'H28' => array('code' => 'H28', 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'label' => 'Caixa RPC H28', 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 280, 'max_payload_g' => null),
		'H32' => array('code' => 'H32', 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'label' => 'Caixa RPC H32', 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 320, 'max_payload_g' => null),
		'H48' => array('code' => 'H48', 'category' => PAPELITO_PACKAGING_RPC_CATEGORY_HYPER, 'label' => 'Caixa RPC H48', 'length_mm' => 640, 'width_mm' => 480, 'height_mm' => 480, 'max_payload_g' => null),
	);
}

/**
 * Volume interno de um perfil, em milímetros cúbicos.
 *
 * @param array<string,mixed> $profile Perfil de caixa do vendor.
 * @return int Volume interno; zero quando alguma dimensão não é positiva.
 */
function papelito_packaging_profile_volume_mm3(array $profile): int
{
	$length = (int) ($profile['length_mm'] ?? 0);
	$width  = (int) ($profile['width_mm'] ?? 0);
	$height = (int) ($profile['height_mm'] ?? 0);

	return $length > 0 && $width > 0 && $height > 0 ? $length * $width * $height : 0;
}

/**
 * Escolhe a menor caixa do vendor que comporta o conjunto de itens.
 *
 * @param array<int,array<string,mixed>> $profiles Perfis ativos do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return array<string,mixed>|null Perfil escolhido ou nulo quando nenhum serve.
 */
function papelito_packaging_choose_profile(array $profiles, array $items, float $usable_factor = PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR): ?array
{
	foreach (papelito_packaging_order_profiles($profiles) as $profile) {
		if (papelito_packaging_profile_fits($profile, $items, $usable_factor)) {
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
function papelito_packaging_profile_fits(array $profile, array $items, float $usable_factor = PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR): bool
{
	return papelito_packaging_volume_fits($profile, $items, $usable_factor)
		&& papelito_packaging_largest_piece_fits($profile, $items)
		&& papelito_packaging_weight_fits($profile, $items);
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
function papelito_packaging_normalize_usable_factor(float $factor): float
{
	return $factor > 0.0 && $factor <= 1.0 ? $factor : PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR;
}

/**
 * Volume total dos itens do conjunto, em milímetros cúbicos.
 *
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return int Soma de volume vezes quantidade.
 */
function papelito_packaging_items_volume_mm3(array $items): int
{
	$volume = 0;

	foreach ($items as $item) {
		$item    = is_array($item) ? $item : array();
		$volume += papelito_packaging_profile_volume_mm3($item) * max(0, (int) ($item['qty'] ?? 0));
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
function papelito_packaging_volume_fits(array $profile, array $items, float $usable_factor): bool
{
	$usable = papelito_packaging_profile_volume_mm3($profile) * papelito_packaging_normalize_usable_factor($usable_factor);

	return $usable > 0.0 && papelito_packaging_items_volume_mm3($items) <= $usable;
}

/**
 * Dimensões de uma caixa ou item em ordem decrescente.
 *
 * Comparar ordenado é o que permite girar a peça sem calcular orientação.
 *
 * @param array<string,mixed> $source Perfil ou item com dimensões em milímetros.
 * @return array<int,int> Comprimento, largura e altura, do maior para o menor.
 */
function papelito_packaging_sorted_dimensions_mm(array $source): array
{
	$dimensions = array(
		(int) ($source['length_mm'] ?? 0),
		(int) ($source['width_mm'] ?? 0),
		(int) ($source['height_mm'] ?? 0),
	);
	rsort($dimensions);

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
function papelito_packaging_largest_piece_fits(array $profile, array $items): bool
{
	$box = papelito_packaging_sorted_dimensions_mm($profile);

	foreach ($items as $item) {
		$piece = papelito_packaging_sorted_dimensions_mm(is_array($item) ? $item : array());
		foreach ($piece as $index => $side) {
			if ($side <= 0 || $side > $box[$index]) {
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
function papelito_packaging_items_weight_g(array $items): int
{
	$weight = 0;

	foreach ($items as $item) {
		$item    = is_array($item) ? $item : array();
		$weight += max(0, (int) ($item['weight_g'] ?? 0)) * max(0, (int) ($item['qty'] ?? 0));
	}

	return $weight;
}

/**
 * Diz se o peso dos itens cabe na carga máxima do perfil.
 *
 * A tara não entra nesta conta. O número do catálogo RPC é a "Resistência (kg)" do Guia Técnico de
 * Embalagens dos Correios (§1.4.1), estimada como o volume da caixa ocupado 100% por resmas de
 * papel a 0,9125 g/cm³ — ou seja, já é o peso do conteúdo, não do conjunto. Somar a caixa aqui
 * descontaria o peso dela do que ela pode levar e empurraria o pedido para uma caixa maior, e um
 * frete mais caro, sem motivo. A tara segue somando no peso declarado à transportadora, que é
 * outra conta.
 *
 * @param array<string,mixed>            $profile Perfil de caixa do vendor.
 * @param array<int,array<string,mixed>> $items Itens a embalar.
 * @return bool Se o peso cabe; perfil sem carga máxima declarada não limita.
 */
function papelito_packaging_weight_fits(array $profile, array $items): bool
{
	$max_payload = $profile['max_payload_g'] ?? null;
	if (null === $max_payload) {
		return true;
	}

	return papelito_packaging_items_weight_g($items) <= (int) $max_payload;
}

/**
 * Ordena perfis do menor para o maior volume interno, com desempate estável por código.
 *
 * @param array<int,array<string,mixed>> $profiles Perfis ativos do vendor.
 * @return array<int,array<string,mixed>> Perfis ordenados.
 */
function papelito_packaging_order_profiles(array $profiles): array
{
	$ordered = array_values($profiles);
	usort(
		$ordered,
		static function (array $left, array $right): int {
			$volumes = papelito_packaging_profile_volume_mm3($left) <=> papelito_packaging_profile_volume_mm3($right);

			return 0 !== $volumes ? $volumes : strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
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
function papelito_packaging_resolve_profile(array $profiles, array $rules, array $lines, float $usable_factor = PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR): ?array
{
	$override = papelito_packaging_override_profile($profiles, $rules, $lines);

	return null !== $override ? $override : papelito_packaging_choose_profile($profiles, $lines, $usable_factor);
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
function papelito_packaging_override_profile(array $profiles, array $rules, array $lines): ?array
{
	$target = papelito_packaging_single_target($lines);
	if (null === $target) {
		return null;
	}

	$matching_rules = papelito_packaging_matching_rules($rules, $target);
	if (1 !== count($matching_rules)) {
		return null;
	}

	return papelito_packaging_profile_by_id($profiles, (int) ($matching_rules[0]['profile_id'] ?? 0));
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
function papelito_packaging_matching_rules(array $rules, array $target): array
{
	$matching_rules = array();

	foreach ($rules as $rule) {
		if (is_array($rule) && papelito_packaging_rule_matches($rule, $target)) {
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
function papelito_packaging_single_target(array $lines): ?array
{
	$target = null;

	foreach ($lines as $line) {
		$line    = is_array($line) ? $line : array();
		$type    = (string) ($line['target_type'] ?? '');
		$id      = (int) ($line['target_id'] ?? 0);
		$qty     = max(0, (int) ($line['qty'] ?? 0));
		if ('' === $type || $id <= 0) {
			return null;
		}
		if (null === $target) {
			$target = array('target_type' => $type, 'target_id' => $id, 'qty' => 0);
		}
		if ($target['target_type'] !== $type || $target['target_id'] !== $id) {
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
function papelito_packaging_rule_matches(array $rule, array $target): bool
{
	return (string) ($rule['target_type'] ?? '') === $target['target_type']
		&& (int) ($rule['target_id'] ?? 0) === $target['target_id']
		&& $target['qty'] >= (int) ($rule['min_qty'] ?? 0)
		&& $target['qty'] <= (int) ($rule['max_qty'] ?? 0);
}

/**
 * Localiza um perfil ativo do vendor pelo identificador.
 *
 * @param array<int,array<string,mixed>> $profiles Perfis ativos do vendor.
 * @param int                            $profile_id Identificador procurado.
 * @return array<string,mixed>|null Perfil encontrado ou nulo.
 */
function papelito_packaging_profile_by_id(array $profiles, int $profile_id): ?array
{
	foreach ($profiles as $profile) {
		if (is_array($profile) && $profile_id > 0 && (int) ($profile['id'] ?? 0) === $profile_id) {
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
function papelito_packaging_table_names(): array
{
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
function papelito_packaging_install_tables(): void
{
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

	dbDelta($profiles_sql);
	dbDelta($rules_sql);
}

/**
 * Normaliza um pacote para a unidade canônica, em inteiros.
 *
 * @param array<string,mixed> $package Pacote cru.
 * @return array{length_mm:int,width_mm:int,height_mm:int,weight_g:int,count:int}|null Pacote canônico ou nulo quando inválido.
 */
function papelito_packaging_normalize_package(array $package): ?array
{
	$canonical = array(
		'length_mm' => (int) ($package['length_mm'] ?? 0),
		'width_mm'  => (int) ($package['width_mm'] ?? 0),
		'height_mm' => (int) ($package['height_mm'] ?? 0),
		'weight_g'  => (int) ($package['weight_g'] ?? 0),
		'count'     => (int) ($package['count'] ?? 0),
	);

	if ($canonical['length_mm'] <= 0 || $canonical['width_mm'] <= 0 || $canonical['height_mm'] <= 0) {
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
function papelito_packaging_normalize_packages(mixed $packages): ?array
{
	if (! is_array($packages) || array() === $packages) {
		return null;
	}

	$canonical = array();
	foreach ($packages as $package) {
		$normalized = is_array($package) ? papelito_packaging_normalize_package($package) : null;
		if (null === $normalized) {
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
function papelito_packaging_physical_hash(array $snapshot): string
{
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
function papelito_packaging_build_snapshot(array $input): ?array
{
	$packages = papelito_packaging_normalize_packages($input['packages'] ?? null);
	if (null === $packages) {
		return null;
	}

	$source   = (string) ($input['measurement_source'] ?? PAPELITO_PACKAGING_MEASUREMENT_LEGACY);
	$volumes  = 0;
	$weight_g = 0;
	foreach ($packages as $package) {
		$volumes  += $package['count'];
		$weight_g += $package['weight_g'] * $package['count'];
	}

	$snapshot = array(
		'schema_version'          => PAPELITO_PACKAGING_SNAPSHOT_SCHEMA_VERSION,
		'vendor_id'               => (int) ($input['vendor_id'] ?? 0),
		'origin_cep'              => (string) ($input['origin_cep'] ?? ''),
		'destination_cep'         => (string) ($input['destination_cep'] ?? ''),
		'merchandise_value_cents' => max(0, (int) ($input['merchandise_value_cents'] ?? 0)),
		'measurement_source'      => $source,
		'approval_version'        => PAPELITO_PACKAGING_MEASUREMENT_PROFILE === $source ? (int) ($input['approval_version'] ?? 0) : null,
		'packages'                => $packages,
		'total_volumes'           => $volumes,
		'total_weight_g'          => $weight_g,
	);

	$snapshot['physical_hash'] = papelito_packaging_physical_hash($snapshot);

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
function papelito_packaging_profiles_for_vendor(int $vendor_id): array
{
	if ($vendor_id <= 0) {
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
	foreach (is_array($rows) ? $rows : array() as $row) {
		$profile = papelito_packaging_profile_row(is_array($row) ? $row : (array) $row);
		if (null !== $profile) {
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
function papelito_packaging_rules_for_vendor(int $vendor_id): array
{
	if ($vendor_id <= 0) {
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
	foreach (is_array($rows) ? $rows : array() as $row) {
		$rule = papelito_packaging_rule_row(is_array($row) ? $row : (array) $row);
		if (null !== $rule) {
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
function papelito_packaging_profile_row(array $row): ?array
{
	if (1 !== (int) ($row['active'] ?? 0) || (int) ($row['vendor_id'] ?? 0) <= 0 || '' === (string) ($row['code'] ?? '')) {
		return null;
	}

	$profile = array(
		'id'            => (int) ($row['id'] ?? 0),
		'vendor_id'     => (int) ($row['vendor_id'] ?? 0),
		'code'          => (string) $row['code'],
		'label'         => (string) ($row['label'] ?? ''),
		'length_mm'     => (int) ($row['length_mm'] ?? 0),
		'width_mm'      => (int) ($row['width_mm'] ?? 0),
		'height_mm'     => (int) ($row['height_mm'] ?? 0),
		'tare_weight_g' => max(0, (int) ($row['tare_weight_g'] ?? 0)),
		'max_payload_g' => null === ($row['max_payload_g'] ?? null) ? null : max(0, (int) $row['max_payload_g']),
		'source'        => (string) ($row['source'] ?? 'custom'),
		'active'        => 1,
		'version'       => (int) ($row['version'] ?? 0),
	);

	return $profile['id'] > 0 && $profile['version'] > 0 && papelito_packaging_profile_volume_mm3($profile) > 0 ? $profile : null;
}

/**
 * Normaliza uma linha de perfil para a API do painel do vendor.
 *
 * Diferente do resolvedor de cotação, esta leitura mantém perfis inativos.
 *
 * @param array<string,mixed> $row Linha crua.
 * @return array<string,mixed>|null Perfil serializável ou nulo.
 */
function papelito_packaging_admin_profile_row(array $row): ?array
{
	$profile = array(
		'id'            => (int) ($row['id'] ?? 0),
		'code'          => (string) ($row['code'] ?? ''),
		'label'         => (string) ($row['label'] ?? ''),
		'length_mm'     => (int) ($row['length_mm'] ?? 0),
		'width_mm'      => (int) ($row['width_mm'] ?? 0),
		'height_mm'     => (int) ($row['height_mm'] ?? 0),
		'tare_weight_g' => (int) ($row['tare_weight_g'] ?? 0),
		'max_payload_g' => null === ($row['max_payload_g'] ?? null) ? null : (int) $row['max_payload_g'],
		'source'        => (string) ($row['source'] ?? ''),
		'active'        => 1 === (int) ($row['active'] ?? 0),
		'version'       => (int) ($row['version'] ?? 0),
		'created_at'    => (string) ($row['created_at'] ?? ''),
		'updated_at'    => (string) ($row['updated_at'] ?? ''),
	);

	return $profile['id'] > 0 && '' !== $profile['code'] && '' !== $profile['label'] && $profile['length_mm'] > 0 && $profile['width_mm'] > 0 && $profile['height_mm'] > 0 && $profile['tare_weight_g'] >= 0 && (null === $profile['max_payload_g'] || $profile['max_payload_g'] > 0) && in_array($profile['source'], array('rpc', 'custom'), true) && $profile['version'] > 0 ? $profile : null;
}

/**
 * Lê todos os perfis do vendor, incluindo os inativos.
 *
 * @param int $vendor_id ID do vendor autenticado.
 * @return array<int,array<string,mixed>> Perfis válidos.
 */
function papelito_packaging_profiles_for_vendor_admin(int $vendor_id): array
{
	if ($vendor_id <= 0) {
		return array();
	}

	global $wpdb;
	$tables = papelito_packaging_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, vendor_id, code, label, length_mm, width_mm, height_mm, tare_weight_g, max_payload_g, source, active, version, created_at, updated_at FROM {$tables['profiles']} WHERE vendor_id = %d ORDER BY id ASC",
			$vendor_id
		),
		ARRAY_A
	);

	$profiles = array();
	foreach (is_array($rows) ? $rows : array() as $row) {
		$profile = papelito_packaging_admin_profile_row(is_array($row) ? $row : (array) $row);
		if (null !== $profile) {
			$profiles[] = $profile;
		}
	}

	return $profiles;
}

/**
 * Cria um erro de validação ou autorização do perfil.
 *
 * @param string $code Código público.
 * @param string $message Mensagem para o vendor.
 * @param int $status Status HTTP.
 * @param string $field Campo afetado.
 * @return WP_Error
 */
function papelito_packaging_profile_error(string $code, string $message, int $status, string $field = '')
{
	$data = array('status' => $status);
	if ('' !== $field) {
		$data['field'] = $field;
	}

	return new WP_Error($code, $message, $data);
}

/**
 * Converte um valor estritamente inteiro recebido no JSON.
 *
 * @param mixed $value Valor cru.
 * @return int|null Inteiro ou nulo quando o formato não é inteiro.
 */
function papelito_packaging_parse_integer($value): ?int
{
	if (is_int($value)) {
		return $value;
	}

	if (! is_string($value) || ! preg_match('/^\d+$/', trim($value))) {
		return null;
	}

	$parsed = filter_var(trim($value), FILTER_VALIDATE_INT);

	return false === $parsed ? null : (int) $parsed;
}

/**
 * Normaliza o código de um perfil.
 *
 * @param array<string,mixed> $payload Corpo recebido.
 * @return string|WP_Error Código canônico ou erro.
 */
function papelito_packaging_normalize_profile_code(array $payload)
{
	$code = strtoupper(sanitize_text_field((string) ($payload['code'] ?? '')));
	if ('' === $code || strlen($code) > 32) {
		return papelito_packaging_profile_error('papelito_packaging_profile_invalid', 'Informe um código de caixa válido.', 422, 'code');
	}

	return $code;
}

/**
 * Normaliza o nome de um perfil.
 *
 * @param array<string,mixed> $payload Corpo recebido.
 * @return string|WP_Error Nome canônico ou erro.
 */
function papelito_packaging_normalize_profile_label(array $payload)
{
	$label = sanitize_text_field((string) ($payload['label'] ?? ''));
	if ('' === $label || strlen($label) > 96) {
		return papelito_packaging_profile_error('papelito_packaging_profile_invalid', 'Informe um nome de caixa válido.', 422, 'label');
	}

	return $label;
}

/**
 * Normaliza as dimensões de um perfil.
 *
 * @param array<string,mixed> $payload Corpo recebido.
 * @return array<string,int>|WP_Error Dimensões canônicas ou erro.
 */
function papelito_packaging_normalize_profile_dimensions(array $payload)
{
	$dimensions = array(
		'length_mm' => array('label' => 'comprimento', 'value' => $payload['length_mm'] ?? null),
		'width_mm'  => array('label' => 'largura', 'value' => $payload['width_mm'] ?? null),
		'height_mm' => array('label' => 'altura', 'value' => $payload['height_mm'] ?? null),
	);
	$normalized_dimensions = array();
	foreach ($dimensions as $field => $definition) {
		$dimension = papelito_packaging_parse_integer($definition['value']);
		if (null === $dimension || $dimension <= 0) {
			return papelito_packaging_profile_error('papelito_packaging_profile_invalid', 'Informe a ' . $definition['label'] . ' com um inteiro positivo.', 422, $field);
		}
		$normalized_dimensions[$field] = $dimension;
	}

	return $normalized_dimensions;
}

/**
 * Normaliza a tara de um perfil.
 *
 * @param array<string,mixed> $payload Corpo recebido.
 * @return int|WP_Error Tara canônica ou erro.
 */
function papelito_packaging_normalize_profile_tare(array $payload)
{
	if (! array_key_exists('tare_weight_g', $payload) || null === $payload['tare_weight_g'] || '' === $payload['tare_weight_g']) {
		$payload['tare_weight_g'] = 0;
	}


	$tare_weight = papelito_packaging_parse_integer($payload['tare_weight_g']);
	if (null === $tare_weight || $tare_weight < 0) {
		return papelito_packaging_profile_error('papelito_packaging_profile_invalid', 'Informe a tara com um inteiro igual ou maior que zero.', 422, 'tare_weight_g');
	}

	return $tare_weight;
}

/**
 * Normaliza a carga máxima de um perfil.
 *
 * @param array<string,mixed> $payload Corpo recebido.
 * @return int|null|WP_Error Carga canônica ou erro.
 */
function papelito_packaging_normalize_profile_max_payload(array $payload)
{
	$max_payload = $payload['max_payload_g'] ?? null;
	if (null === $max_payload) {
		return null;
	}

	$max_payload = papelito_packaging_parse_integer($max_payload);
	if (null === $max_payload || $max_payload <= 0) {
		return papelito_packaging_profile_error('papelito_packaging_profile_invalid', 'Informe a carga máxima com um inteiro positivo ou deixe o campo vazio.', 422, 'max_payload_g');
	}

	return $max_payload;
}

/**
 * Normaliza e valida a origem de um perfil.
 *
 * @param array<string,mixed> $payload Corpo recebido.
 * @param array<string,mixed>|null $existing Perfil persistido, em edição.
 * @param string $code Código normalizado.
 * @return string|WP_Error Origem canônica ou erro.
 */
function papelito_packaging_normalize_profile_source(array $payload, ?array $existing, string $code)
{
	$source = $existing['source'] ?? strtolower(sanitize_text_field((string) ($payload['source'] ?? '')));
	if (! in_array($source, array('rpc', 'custom'), true)) {
		return papelito_packaging_profile_error('papelito_packaging_profile_invalid', 'Selecione uma origem de caixa válida.', 422, 'source');
	}

	if ('rpc' === $source && ! isset(papelito_packaging_rpc_catalog()[$code])) {
		return papelito_packaging_profile_error('papelito_packaging_profile_rpc_code_invalid', 'O código RPC informado não existe no catálogo.', 422, 'code');
	}

	return $source;
}

/**
 * Normaliza e valida o corpo de um perfil.
 *
 * @param array<string,mixed> $payload Corpo recebido.
 * @param array<string,mixed>|null $existing Perfil persistido, em edição.
 * @return array<string,mixed>|WP_Error Dados canônicos ou erro.
 */
function papelito_packaging_normalize_profile_payload(array $payload, ?array $existing = null)
{
	$code = papelito_packaging_normalize_profile_code($payload);
	if (is_wp_error($code)) {
		return $code;
	}

	$label = papelito_packaging_normalize_profile_label($payload);
	if (is_wp_error($label)) {
		return $label;
	}

	$dimensions = papelito_packaging_normalize_profile_dimensions($payload);
	if (is_wp_error($dimensions)) {
		return $dimensions;
	}

	$tare_weight = papelito_packaging_normalize_profile_tare($payload);
	if (is_wp_error($tare_weight)) {
		return $tare_weight;
	}

	$max_payload = papelito_packaging_normalize_profile_max_payload($payload);
	if (is_wp_error($max_payload)) {
		return $max_payload;
	}

	$source = papelito_packaging_normalize_profile_source($payload, $existing, $code);
	if (is_wp_error($source)) {
		return $source;
	}

	return array_merge(
		array(
			'code'          => $code,
			'label'         => $label,
			'tare_weight_g' => $tare_weight,
			'max_payload_g' => $max_payload,
			'source'        => $source,
		),
		$dimensions
	);
}

/**
 * Conta as caixas ativas do vendor.
 *
 * @param int $vendor_id Vendor.
 * @return int Quantidade ativa.
 */
function papelito_packaging_active_profile_count(int $vendor_id): int
{
	global $wpdb;
	$tables = papelito_packaging_table_names();

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['profiles']} WHERE vendor_id = %d AND active = 1",
			$vendor_id
		)
	);
}

/**
 * Barra a criação e a reativação quando o vendor já bateu o teto de caixas ativas.
 *
 * @param int $vendor_id Vendor.
 * @return WP_Error|null Erro quando o teto foi atingido.
 */
function papelito_packaging_guard_active_limit(int $vendor_id): ?WP_Error
{
	if (papelito_packaging_active_profile_count($vendor_id) < PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES) {
		return null;
	}

	return papelito_packaging_profile_error(
		'papelito_packaging_profile_limit_reached',
		sprintf('Você chegou ao limite de %d caixas ativas. Desative uma caixa para abrir vaga.', PAPELITO_PACKAGING_MAX_ACTIVE_PROFILES),
		409
	);
}

/**
 * Verifica se já existe código de caixa no vendor.
 *
 * @param int $vendor_id Vendor.
 * @param string $code Código normalizado.
 * @param int $ignore_id Perfil ignorado na edição.
 * @return bool
 */
function papelito_packaging_profile_code_exists(int $vendor_id, string $code, int $ignore_id = 0): bool
{
	global $wpdb;
	$tables = papelito_packaging_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id FROM {$tables['profiles']} WHERE vendor_id = %d AND code = %s",
			$vendor_id,
			$code
		),
		ARRAY_A
	);

	foreach (is_array($rows) ? $rows : array() as $row) {
		if ((int) ($row['id'] ?? 0) !== $ignore_id) {
			return true;
		}
	}

	return false;
}

/**
 * Lê um perfil usando simultaneamente id e vendor.
 *
 * @param int $vendor_id Vendor autenticado.
 * @param int $profile_id Perfil.
 * @return array<string,mixed>|null Perfil ou nulo.
 */
function papelito_packaging_profile_for_vendor_admin(int $vendor_id, int $profile_id): ?array
{
	if ($vendor_id <= 0 || $profile_id <= 0) {
		return null;
	}

	global $wpdb;
	$tables = papelito_packaging_table_names();
	$row    = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, vendor_id, code, label, length_mm, width_mm, height_mm, tare_weight_g, max_payload_g, source, active, version, created_at, updated_at FROM {$tables['profiles']} WHERE id = %d AND vendor_id = %d",
			$profile_id,
			$vendor_id
		),
		ARRAY_A
	);

	return is_array($row) ? papelito_packaging_admin_profile_row($row) : null;
}

/**
 * Aplica o rate limit comum das escritas de embalagem.
 *
 * @return true|WP_Error
 */
function papelito_packaging_check_write_rate_limit()
{
	if (function_exists('papelito_rate_limit') && function_exists('papelito_rate_limit_identity') && ! papelito_rate_limit('vendor_packaging_write', papelito_rate_limit_identity(), PAPELITO_PACKAGING_WRITE_RATE_LIMIT, PAPELITO_PACKAGING_WRITE_RATE_WINDOW)) {
		return papelito_packaging_profile_error('papelito_packaging_rate_limited', 'Muitas alterações em pouco tempo. Tente novamente em instantes.', 429);
	}

	return true;
}

/**
 * Converte erro de persistência em resposta pública sem vazar SQL.
 *
 * @param string $last_error Erro do wpdb.
 * @return WP_Error
 */
function papelito_packaging_persistence_error(string $last_error)
{
	if (false !== stripos($last_error, 'duplicate')) {
		return papelito_packaging_profile_error('papelito_packaging_profile_code_conflict', PAPELITO_PACKAGING_CODE_CONFLICT_MESSAGE, 409, 'code');
	}

	return papelito_packaging_profile_error('papelito_packaging_profile_persistence_failed', 'Não foi possível salvar a caixa agora.', 500);
}

/**
 * Cria um perfil de embalagem do vendor.
 *
 * @param int $vendor_id Vendor autenticado.
 * @param array<string,mixed> $payload Corpo.
 * @param int $updated_by Usuário autenticado.
 * @return array<string,mixed>|WP_Error Perfil ou erro.
 */
function papelito_packaging_create_profile(int $vendor_id, array $payload, int $updated_by)
{
	$rate_limit = papelito_packaging_check_write_rate_limit();
	if (is_wp_error($rate_limit)) {
		return $rate_limit;
	}

	$profile = papelito_packaging_normalize_profile_payload($payload);
	if (is_wp_error($profile)) {
		return $profile;
	}

	$limit = papelito_packaging_guard_active_limit($vendor_id);
	if (null !== $limit) {
		return $limit;
	}

	if (papelito_packaging_profile_code_exists($vendor_id, $profile['code'])) {
		return papelito_packaging_profile_error('papelito_packaging_profile_code_conflict', PAPELITO_PACKAGING_CODE_CONFLICT_MESSAGE, 409, 'code');
	}

	global $wpdb;
	$tables = papelito_packaging_table_names();
	$fields = 'vendor_id, code, label, length_mm, width_mm, height_mm, tare_weight_g, max_payload_g, source, active, version, updated_by';
	if (null === $profile['max_payload_g']) {
		$query = $wpdb->prepare(
			"INSERT INTO {$tables['profiles']} ({$fields}) VALUES (%d, %s, %s, %d, %d, %d, %d, NULL, %s, 1, 1, %d)",
			$vendor_id,
			$profile['code'],
			$profile['label'],
			$profile['length_mm'],
			$profile['width_mm'],
			$profile['height_mm'],
			$profile['tare_weight_g'],
			$profile['source'],
			$updated_by
		);
	} else {
		$query = $wpdb->prepare(
			"INSERT INTO {$tables['profiles']} ({$fields}) VALUES (%d, %s, %s, %d, %d, %d, %d, %d, %s, 1, 1, %d)",
			$vendor_id,
			$profile['code'],
			$profile['label'],
			$profile['length_mm'],
			$profile['width_mm'],
			$profile['height_mm'],
			$profile['tare_weight_g'],
			$profile['max_payload_g'],
			$profile['source'],
			$updated_by
		);
	}

	if (false === $wpdb->query($query)) {
		return papelito_packaging_persistence_error((string) $wpdb->last_error);
	}

	$created = papelito_packaging_profile_for_vendor_admin($vendor_id, (int) $wpdb->insert_id);
	papelito_packaging_announce_profiles_changed($vendor_id);

	return $created;
}

/**
 * Edita um perfil do vendor e incrementa sua versão física.
 *
 * @param int $vendor_id Vendor autenticado.
 * @param int $profile_id Perfil.
 * @param array<string,mixed> $payload Corpo.
 * @param int $updated_by Usuário autenticado.
 * @return array<string,mixed>|WP_Error Perfil ou erro.
 */
function papelito_packaging_update_profile(int $vendor_id, int $profile_id, array $payload, int $updated_by)
{
	$rate_limit = papelito_packaging_check_write_rate_limit();
	if (is_wp_error($rate_limit)) {
		return $rate_limit;
	}

	$existing = papelito_packaging_profile_for_vendor_admin($vendor_id, $profile_id);
	if (null === $existing) {
		return papelito_packaging_profile_error('papelito_packaging_profile_not_found', 'Caixa não encontrada.', 404);
	}

	$profile = papelito_packaging_normalize_profile_payload($payload, $existing);
	if (is_wp_error($profile)) {
		return $profile;
	}

	if (papelito_packaging_profile_code_exists($vendor_id, $profile['code'], $profile_id)) {
		return papelito_packaging_profile_error('papelito_packaging_profile_code_conflict', PAPELITO_PACKAGING_CODE_CONFLICT_MESSAGE, 409, 'code');
	}

	global $wpdb;
	$tables = papelito_packaging_table_names();
	$set    = 'code = %s, label = %s, length_mm = %d, width_mm = %d, height_mm = %d, tare_weight_g = %d, source = %s, version = version + 1, updated_by = %d';
	if (null === $profile['max_payload_g']) {
		$query = $wpdb->prepare(
			"UPDATE {$tables['profiles']} SET {$set}, max_payload_g = NULL WHERE id = %d AND vendor_id = %d",
			$profile['code'],
			$profile['label'],
			$profile['length_mm'],
			$profile['width_mm'],
			$profile['height_mm'],
			$profile['tare_weight_g'],
			$profile['source'],
			$updated_by,
			$profile_id,
			$vendor_id
		);
	} else {
		$query = $wpdb->prepare(
			"UPDATE {$tables['profiles']} SET {$set}, max_payload_g = %d WHERE id = %d AND vendor_id = %d",
			$profile['code'],
			$profile['label'],
			$profile['length_mm'],
			$profile['width_mm'],
			$profile['height_mm'],
			$profile['tare_weight_g'],
			$profile['source'],
			$updated_by,
			$profile['max_payload_g'],
			$profile_id,
			$vendor_id
		);
	}

	if (false === $wpdb->query($query)) {
		return papelito_packaging_persistence_error((string) $wpdb->last_error);
	}

	$updated = papelito_packaging_profile_for_vendor_admin($vendor_id, $profile_id);
	papelito_packaging_announce_profiles_changed($vendor_id);

	return $updated;
}

/**
 * Desativa um perfil sem apagar seu histórico.
 *
 * @param int $vendor_id Vendor autenticado.
 * @param int $profile_id Perfil.
 * @param int $updated_by Usuário autenticado.
 * @return array<string,mixed>|WP_Error Perfil ou erro.
 */
function papelito_packaging_deactivate_profile(int $vendor_id, int $profile_id, int $updated_by)
{
	$rate_limit = papelito_packaging_check_write_rate_limit();
	if (is_wp_error($rate_limit)) {
		return $rate_limit;
	}

	$existing = papelito_packaging_profile_for_vendor_admin($vendor_id, $profile_id);
	if (null === $existing) {
		return papelito_packaging_profile_error('papelito_packaging_profile_not_found', 'Caixa não encontrada.', 404);
	}

	global $wpdb;
	$tables = papelito_packaging_table_names();
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tables['profiles']} SET active = 0, updated_by = %d WHERE id = %d AND vendor_id = %d",
			$updated_by,
			$profile_id,
			$vendor_id
		)
	);

	if (false === $updated) {
		return papelito_packaging_persistence_error((string) $wpdb->last_error);
	}

	return papelito_packaging_profile_for_vendor_admin($vendor_id, $profile_id);
}

/**
 * Reativa uma caixa desativada do vendor.
 *
 * O código é único por vendor entre perfis ativos e inativos, então a volta nunca colide com
 * outro cadastro. A versão não é incrementada: reativar não altera a medida aprovada.
 *
 * @param int $vendor_id Vendor dono da caixa.
 * @param int $profile_id Caixa alvo.
 * @param int $updated_by Autor da alteração.
 * @return array<string,mixed>|WP_Error Perfil atualizado ou erro.
 */
function papelito_packaging_reactivate_profile(int $vendor_id, int $profile_id, int $updated_by)
{
	$rate_limit = papelito_packaging_check_write_rate_limit();
	if (is_wp_error($rate_limit)) {
		return $rate_limit;
	}

	$existing = papelito_packaging_profile_for_vendor_admin($vendor_id, $profile_id);
	if (null === $existing) {
		return papelito_packaging_profile_error('papelito_packaging_profile_not_found', 'Caixa não encontrada.', 404);
	}

	$limit = papelito_packaging_guard_active_limit($vendor_id);
	if (null !== $limit) {
		return $limit;
	}

	global $wpdb;
	$tables  = papelito_packaging_table_names();
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tables['profiles']} SET active = 1, updated_by = %d WHERE id = %d AND vendor_id = %d",
			$updated_by,
			$profile_id,
			$vendor_id
		)
	);

	if (false === $updated) {
		return papelito_packaging_persistence_error((string) $wpdb->last_error);
	}

	return papelito_packaging_profile_for_vendor_admin($vendor_id, $profile_id);
}

/**
 * Apaga em definitivo uma caixa já desativada.
 *
 * Só a caixa inativa é apagável: a ativa sai de cena por desativação, que preserva a linha. O
 * histórico de frete não depende desta tabela — o snapshot enviado à transportadora copia as
 * medidas e guarda apenas `approval_version` —, então apagar aqui não reescreve pedido nenhum.
 * As regras de override que apontavam para o perfil saem junto: sem o perfil elas forçariam uma
 * caixa inexistente e a escolha cairia no automático sem aviso.
 *
 * @param int $vendor_id Vendor dono da caixa.
 * @param int $profile_id Caixa alvo.
 * @return true|WP_Error Verdadeiro ou erro.
 */
function papelito_packaging_delete_profile(int $vendor_id, int $profile_id)
{
	$rate_limit = papelito_packaging_check_write_rate_limit();
	if (is_wp_error($rate_limit)) {
		return $rate_limit;
	}

	$existing = papelito_packaging_profile_for_vendor_admin($vendor_id, $profile_id);
	if (null === $existing) {
		return papelito_packaging_profile_error('papelito_packaging_profile_not_found', 'Caixa não encontrada.', 404);
	}

	if (! empty($existing['active'])) {
		return papelito_packaging_profile_error(
			'papelito_packaging_profile_active',
			'Desative a caixa antes de excluí-la.',
			409
		);
	}

	global $wpdb;
	$tables = papelito_packaging_table_names();

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$tables['rules']} WHERE vendor_id = %d AND profile_id = %d",
			$vendor_id,
			$profile_id
		)
	);

	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$tables['profiles']} WHERE id = %d AND vendor_id = %d",
			$profile_id,
			$vendor_id
		)
	);

	if (false === $deleted) {
		return papelito_packaging_persistence_error((string) $wpdb->last_error);
	}

	papelito_packaging_announce_profiles_changed($vendor_id);

	return true;
}

/**
 * REST callback que lista as caixas do vendor autenticado.
 *
 * @return WP_REST_Response
 */
function papelito_packaging_handle_get_profiles()
{
	return new WP_REST_Response(
		array('items' => papelito_packaging_profiles_for_vendor_admin(get_current_user_id())),
		200
	);
}

/**
 * REST callback que expõe o catálogo estático RPC.
 *
 * @return WP_REST_Response
 */
function papelito_packaging_handle_get_catalog()
{
	return new WP_REST_Response(
		array('items' => array_values(papelito_packaging_rpc_catalog())),
		200
	);
}

/**
 * REST callback de criação de caixa.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_packaging_handle_create_profile(WP_REST_Request $request)
{
	$payload = $request->get_json_params();
	$result  = papelito_packaging_create_profile(
		get_current_user_id(),
		is_array($payload) ? $payload : array(),
		get_current_user_id()
	);

	if (is_wp_error($result)) {
		return $result;
	}

	return new WP_REST_Response(
		array('items' => papelito_packaging_profiles_for_vendor_admin(get_current_user_id())),
		201
	);
}

/**
 * REST callback de edição de caixa.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_packaging_handle_update_profile(WP_REST_Request $request)
{
	$payload = $request->get_json_params();
	$result  = papelito_packaging_update_profile(
		get_current_user_id(),
		absint($request->get_param('id')),
		is_array($payload) ? $payload : array(),
		get_current_user_id()
	);

	if (is_wp_error($result)) {
		return $result;
	}

	return new WP_REST_Response(
		array('items' => papelito_packaging_profiles_for_vendor_admin(get_current_user_id())),
		200
	);
}

/**
 * REST callback de desativação de caixa.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_packaging_handle_deactivate_profile(WP_REST_Request $request)
{
	$result = papelito_packaging_deactivate_profile(
		get_current_user_id(),
		absint($request->get_param('id')),
		get_current_user_id()
	);

	if (is_wp_error($result)) {
		return $result;
	}

	return new WP_REST_Response(
		array('items' => papelito_packaging_profiles_for_vendor_admin(get_current_user_id())),
		200
	);
}

/**
 * REST callback de reativação de caixa.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_packaging_handle_reactivate_profile(WP_REST_Request $request)
{
	$result = papelito_packaging_reactivate_profile(
		get_current_user_id(),
		absint($request->get_param('id')),
		get_current_user_id()
	);

	if (is_wp_error($result)) {
		return $result;
	}

	return new WP_REST_Response(
		array('items' => papelito_packaging_profiles_for_vendor_admin(get_current_user_id())),
		200
	);
}

/**
 * REST callback de exclusão definitiva de caixa.
 *
 * @param WP_REST_Request $request Requisição.
 * @return WP_REST_Response|WP_Error
 */
function papelito_packaging_handle_delete_profile(WP_REST_Request $request)
{
	$result = papelito_packaging_delete_profile(
		get_current_user_id(),
		absint($request->get_param('id'))
	);

	if (is_wp_error($result)) {
		return $result;
	}

	return new WP_REST_Response(
		array('items' => papelito_packaging_profiles_for_vendor_admin(get_current_user_id())),
		200
	);
}

/**
 * Registra as rotas REST de embalagem do vendor.
 *
 * @return void
 */
function papelito_packaging_register_vendor_routes(): void
{
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/packaging-profiles',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
				'callback'            => 'papelito_packaging_handle_get_profiles',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller_commercial',
				'callback'            => 'papelito_packaging_handle_create_profile',
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/packaging-profiles/catalog',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller',
			'callback'            => 'papelito_packaging_handle_get_catalog',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/packaging-profiles/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'PUT',
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller_commercial',
				'callback'            => 'papelito_packaging_handle_update_profile',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => 'papelito_vendor_dashboard_permission_seller_commercial',
				'callback'            => 'papelito_packaging_handle_delete_profile',
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/packaging-profiles/(?P<id>\d+)/deactivate',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller_commercial',
			'callback'            => 'papelito_packaging_handle_deactivate_profile',
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/packaging-profiles/(?P<id>\d+)/reactivate',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'papelito_vendor_dashboard_permission_seller_commercial',
			'callback'            => 'papelito_packaging_handle_reactivate_profile',
		)
	);
}

if (function_exists('add_action')) {
	add_action('rest_api_init', 'papelito_packaging_register_vendor_routes');
}

/**
 * Normaliza uma linha de regra lida do banco.
 *
 * @param array<string,mixed> $row Linha crua.
 * @return array<string,mixed>|null Regra válida ou nulo.
 */
function papelito_packaging_rule_row(array $row): ?array
{
	$rule = array(
		'id'          => (int) ($row['id'] ?? 0),
		'vendor_id'   => (int) ($row['vendor_id'] ?? 0),
		'target_type' => (string) ($row['target_type'] ?? ''),
		'target_id'   => (int) ($row['target_id'] ?? 0),
		'min_qty'     => (int) ($row['min_qty'] ?? 0),
		'max_qty'     => (int) ($row['max_qty'] ?? 0),
		'profile_id'  => (int) ($row['profile_id'] ?? 0),
		'version'     => (int) ($row['version'] ?? 0),
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
function papelito_packaging_items_to_lines(array $items): ?array
{
	if (empty($items) || ! function_exists('wc_get_product')) {
		return null;
	}

	$lines = array();
	foreach ($items as $item) {
		$line = is_array($item) ? papelito_packaging_item_to_line($item) : null;
		if (null === $line) {
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
function papelito_packaging_item_to_line(array $item): ?array
{
	$product_id = (int) ($item['product_id'] ?? 0);
	$qty        = (int) ($item['qty'] ?? 0);
	if ($product_id <= 0 || $qty <= 0) {
		return null;
	}

	$product = wc_get_product($product_id);
	if (! is_object($product) || ! method_exists($product, 'get_weight')) {
		return null;
	}

	$kit = function_exists('papelito_kit_get_by_product') ? papelito_kit_get_by_product($product_id) : null;
	if (is_array($kit)) {
		return papelito_packaging_kit_line($kit, $qty);
	}

	return papelito_packaging_product_line($product, $product_id, $qty);
}

/**
 * Monta a linha física de um produto WooCommerce.
 *
 * @param object $product Produto WooCommerce.
 * @param int    $product_id ID do produto.
 * @param int    $qty Quantidade.
 * @return array<string,mixed>|null Linha ou nulo quando incompleta.
 */
function papelito_packaging_product_line(object $product, int $product_id, int $qty): ?array
{
	if (! method_exists($product, 'get_length') || ! method_exists($product, 'get_width') || ! method_exists($product, 'get_height')) {
		return null;
	}

	$dimensions = papelito_packaging_product_dimensions_mm($product);
	$weight_g   = papelito_packaging_weight_g($product->get_weight());
	if (null === $dimensions || null === $weight_g) {
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
function papelito_packaging_kit_line(array $kit, int $qty): ?array
{
	$kit_id = (int) ($kit['id'] ?? 0);
	$dimensions = papelito_packaging_kit_dimensions_mm($kit);
	$weight_g   = papelito_packaging_kit_weight_g($kit_id);
	if ($kit_id <= 0 || null === $dimensions || null === $weight_g) {
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
function papelito_packaging_product_dimensions_mm(object $product): ?array
{
	$dimensions = array(
		'length_mm' => papelito_packaging_dimension_mm($product->get_length()),
		'width_mm'  => papelito_packaging_dimension_mm($product->get_width()),
		'height_mm' => papelito_packaging_dimension_mm($product->get_height()),
	);

	return in_array(null, $dimensions, true) ? null : $dimensions;
}

/**
 * Converte dimensões declaradas do Kit, armazenadas em centímetros.
 *
 * @param array<string,mixed> $kit Linha do Kit.
 * @return array{length_mm:int,width_mm:int,height_mm:int}|null Dimensões ou nulo.
 */
function papelito_packaging_kit_dimensions_mm(array $kit): ?array
{
	$dimensions = array(
		'length_mm' => papelito_packaging_centimeters_to_mm($kit['package_length'] ?? null),
		'width_mm'  => papelito_packaging_centimeters_to_mm($kit['package_width'] ?? null),
		'height_mm' => papelito_packaging_centimeters_to_mm($kit['package_height'] ?? null),
	);

	return in_array(null, $dimensions, true) ? null : $dimensions;
}

/**
 * Converte uma medida WooCommerce para milímetros sem aceitar zero ou inválido.
 *
 * @param mixed $value Medida na unidade configurada pelo WooCommerce.
 * @return int|null Milímetros inteiros ou nulo.
 */
function papelito_packaging_dimension_mm(mixed $value): ?int
{
	if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0 || ! function_exists('wc_get_dimension')) {
		return null;
	}

	$converted = wc_get_dimension($value, 'mm');
	$millimeters = is_numeric($converted) && is_finite((float) $converted) ? (int) round((float) $converted, 0, PHP_ROUND_HALF_UP) : 0;

	return $millimeters > 0 ? $millimeters : null;
}

/**
 * Converte centímetros declarados do Kit para milímetros inteiros.
 *
 * @param mixed $value Medida em centímetros.
 * @return int|null Milímetros inteiros ou nulo.
 */
function papelito_packaging_centimeters_to_mm(mixed $value): ?int
{
	if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
		return null;
	}

	$millimeters = (int) round((float) $value * 10, 0, PHP_ROUND_HALF_UP);

	return $millimeters > 0 ? $millimeters : null;
}

/**
 * Converte um peso WooCommerce para gramas inteiros.
 *
 * @param mixed $value Peso na unidade configurada pelo WooCommerce.
 * @return int|null Gramas inteiros ou nulo.
 */
function papelito_packaging_weight_g(mixed $value): ?int
{
	if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0 || ! function_exists('wc_get_weight')) {
		return null;
	}

	$converted = wc_get_weight($value, 'g');
	$grams = is_numeric($converted) && is_finite((float) $converted) ? (int) round((float) $converted, 0, PHP_ROUND_HALF_UP) : 0;

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
function papelito_packaging_kit_weight_g(int $kit_id): ?int
{
	if ($kit_id <= 0 || ! function_exists('papelito_kit_items') || ! function_exists('papelito_kit_merchandise')) {
		return null;
	}

	$weight_g = papelito_packaging_kit_component_weight_g(papelito_kit_items($kit_id));
	if (null === $weight_g) {
		return null;
	}

	$merchandise_weight_g = papelito_packaging_kit_merchandise_weight_g(papelito_kit_merchandise($kit_id));
	if (null === $merchandise_weight_g) {
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
function papelito_packaging_kit_component_weight_g(array $items): ?int
{
	if (empty($items)) {
		return null;
	}

	$total = 0;
	foreach ($items as $item) {
		$product_id = (int) ($item['product_id'] ?? 0);
		$quantity   = (int) ($item['quantity'] ?? 0);
		$product    = $product_id > 0 && function_exists('wc_get_product') ? wc_get_product($product_id) : null;
		$weight_g   = is_object($product) && method_exists($product, 'get_weight') ? papelito_packaging_weight_g($product->get_weight()) : null;
		if (null === $weight_g || $quantity <= 0) {
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
function papelito_packaging_kit_merchandise_weight_g(array $items): ?int
{
	$total = 0;
	foreach ($items as $item) {
		$quantity = (int) ($item['quantity'] ?? 0);
		$weight_g = papelito_packaging_weight_g($item['weight'] ?? null);
		if (null === $weight_g || $quantity <= 0) {
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
function papelito_packaging_declared_value_cents(array $items): int
{
	$total = 0;
	foreach ($items as $item) {
		$total += max(0, (int) ($item['declared_value_cents'] ?? 0));
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
function papelito_packaging_braspress_package_from_profile(int $vendor_id, array $profile, array $lines, array $items): ?array
{
	$snapshot = papelito_packaging_snapshot_from_profile($vendor_id, $profile, $lines, papelito_packaging_declared_value_cents($items));

	return null === $snapshot ? null : papelito_packaging_braspress_package_from_snapshot($snapshot);
}

/**
 * Copia para o snapshot o contexto de cotação que não entra no hash físico.
 *
 * Origem e destino descrevem a rota, não o objeto; ficam fora de
 * `papelito_packaging_physical_hash()` de propósito e por isso podem ser
 * preenchidos no pedido sem mudar o hash que a cotação produziu.
 *
 * @param array<string,mixed> $context Contexto autoritativo da cotação.
 * @return array<string,string> Campos de rota do snapshot.
 */
function papelito_packaging_snapshot_context(array $context): array
{
	return array(
		'origin_cep'      => (string) ($context['origin_cep'] ?? ''),
		'destination_cep' => (string) ($context['destination_cep'] ?? ''),
	);
}

/**
 * Monta o `LogisticsSnapshot` de um perfil de caixa já resolvido.
 *
 * @param int                            $vendor_id Vendor dono da embalagem.
 * @param array<string,mixed>            $profile Perfil de caixa escolhido.
 * @param array<int,array<string,mixed>> $lines Linhas físicas do carrinho.
 * @param int                            $merchandise_value_cents Valor mercantil declarado.
 * @param array<string,mixed>            $context Contexto de cotação opcional.
 * @return array<string,mixed>|null Snapshot canônico ou nulo quando o perfil não é versionado.
 */
function papelito_packaging_snapshot_from_profile(int $vendor_id, array $profile, array $lines, int $merchandise_value_cents, array $context = array()): ?array
{
	$version = (int) ($profile['version'] ?? 0);
	if ($version <= 0) {
		return null;
	}

	return papelito_packaging_build_snapshot(
		array_merge(
			papelito_packaging_snapshot_context($context),
			array(
				'vendor_id'               => $vendor_id,
				'merchandise_value_cents' => $merchandise_value_cents,
				'measurement_source'      => PAPELITO_PACKAGING_MEASUREMENT_PROFILE,
				'approval_version'        => $version,
				'packages'                => array(
					array(
						'length_mm' => (int) ($profile['length_mm'] ?? 0),
						'width_mm'  => (int) ($profile['width_mm'] ?? 0),
						'height_mm' => (int) ($profile['height_mm'] ?? 0),
						'weight_g'  => papelito_packaging_items_weight_g($lines) + max(0, (int) ($profile['tare_weight_g'] ?? 0)),
						'count'     => 1,
					),
				),
			)
		)
	);
}

/**
 * Traduz o snapshot canônico para peso, volumes e cubagem Braspress.
 *
 * @param array<string,mixed> $snapshot Snapshot físico válido.
 * @return array<string,mixed>|null Pacote Braspress ou nulo.
 */
function papelito_packaging_braspress_package_from_snapshot(array $snapshot): ?array
{
	$total_weight_g = (int) ($snapshot['total_weight_g'] ?? 0);
	$volumes        = (int) ($snapshot['total_volumes'] ?? 0);
	$cubagem        = papelito_packaging_snapshot_cubagem($snapshot['packages'] ?? array());
	if ($total_weight_g <= 0 || $volumes <= 0 || empty($cubagem)) {
		return null;
	}

	return array(
		'weight_kg'         => round($total_weight_g / 1000, 2, PHP_ROUND_HALF_UP),
		'volumes'           => $volumes,
		'cubagem'           => $cubagem,
		'approval_version'  => (int) ($snapshot['approval_version'] ?? 0),
		'physical_hash'     => (string) ($snapshot['physical_hash'] ?? ''),
		'measurement_source' => (string) ($snapshot['measurement_source'] ?? ''),
	);
}

/**
 * Agrupa pacotes canônicos iguais na cubagem em metros.
 *
 * @param mixed $packages Pacotes do snapshot.
 * @return array<int,array{length_m:float,width_m:float,height_m:float,volumes:int}> Grupos.
 */
function papelito_packaging_snapshot_cubagem(mixed $packages): array
{
	if (! is_array($packages)) {
		return array();
	}

	$groups = array();
	foreach ($packages as $package) {
		if (! is_array($package)) {
			return array();
		}

		$key = implode(':', array((int) ($package['length_mm'] ?? 0), (int) ($package['width_mm'] ?? 0), (int) ($package['height_mm'] ?? 0)));
		if (! isset($groups[$key])) {
			$groups[$key] = array(
				'length_m' => round((int) ($package['length_mm'] ?? 0) / 1000, 3, PHP_ROUND_HALF_UP),
				'width_m'  => round((int) ($package['width_mm'] ?? 0) / 1000, 3, PHP_ROUND_HALF_UP),
				'height_m' => round((int) ($package['height_mm'] ?? 0) / 1000, 3, PHP_ROUND_HALF_UP),
				'volumes'  => 0,
			);
		}
		$groups[$key]['volumes'] += (int) ($package['count'] ?? 0);
	}

	return array_values($groups);
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
function papelito_packaging_braspress_package(int $vendor_id, array $items): ?array
{
	$snapshot = papelito_packaging_profile_snapshot($vendor_id, $items);

	return null === $snapshot ? null : papelito_packaging_braspress_package_from_snapshot($snapshot);
}

/**
 * Resolve o `LogisticsSnapshot` da embalagem cadastrada do vendor.
 *
 * É a mesma escolha de caixa que alimenta a cotação Braspress, exposta para que
 * o pedido registre a embalagem que foi de fato cotada em vez de re-derivar o
 * pacote de dado mutável na hora da pré-postagem.
 *
 * @param int                            $vendor_id Vendor dono da embalagem.
 * @param array<int,array<string,mixed>> $items Itens do carrinho.
 * @param array<string,mixed>            $context Contexto de cotação opcional.
 * @return array<string,mixed>|null Snapshot canônico ou nulo quando o vendor não tem embalagem aplicável.
 */
function papelito_packaging_profile_snapshot(int $vendor_id, array $items, array $context = array()): ?array
{
	$profiles = papelito_packaging_profiles_for_vendor($vendor_id);
	if (empty($profiles)) {
		return null;
	}

	$lines = papelito_packaging_items_to_lines($items);
	if (null === $lines) {
		return null;
	}

	$profile = papelito_packaging_resolve_profile($profiles, papelito_packaging_rules_for_vendor($vendor_id), $lines, PAPELITO_PACKAGING_DEFAULT_USABLE_FACTOR);
	if (null === $profile) {
		return null;
	}

	return papelito_packaging_snapshot_from_profile($vendor_id, $profile, $lines, papelito_packaging_merchandise_value_cents($context, $items), $context);
}

/**
 * Escolhe o valor mercantil autoritativo entre o contexto e as linhas.
 *
 * @param array<string,mixed>            $context Contexto autoritativo da cotação.
 * @param array<int,array<string,mixed>> $items Itens do carrinho.
 * @return int Valor mercantil em centavos.
 */
function papelito_packaging_merchandise_value_cents(array $context, array $items): int
{
	if (isset($context['merchandise_value_cents'])) {
		return max(0, (int) $context['merchandise_value_cents']);
	}

	return papelito_packaging_declared_value_cents($items);
}

/**
 * Converte uma medida em centímetros para o milímetro inteiro canônico.
 *
 * @param mixed $value Medida em centímetros.
 * @return int Medida em milímetros.
 */
function papelito_packaging_cm_to_mm(mixed $value): int
{
	return (int) round((float) $value * 10);
}

/**
 * Converte o pacote sintético de volume único no `LogisticsSnapshot` canônico.
 *
 * O pacote sintético mede dimensão em centímetro e **peso em grama** — é o que
 * `papelito_shipping_add_product_to_package()` acumula com `wc_get_weight(…, 'g')`.
 * Só a dimensão é convertida; tratar o peso como quilo gravaria mil vezes mais.
 *
 * @param int                 $vendor_id Vendor dono da remessa.
 * @param array<string,mixed> $package Pacote sintético já validado pelos limites físicos.
 * @param array<string,mixed> $context Contexto de cotação opcional.
 * @return array<string,mixed>|null Snapshot canônico ou nulo quando o pacote não é convertível.
 */
function papelito_packaging_legacy_snapshot(int $vendor_id, array $package, array $context = array()): ?array
{
	$source = (string) ($package['measurement_source'] ?? PAPELITO_PACKAGING_MEASUREMENT_LEGACY);

	return papelito_packaging_build_snapshot(
		array_merge(
			papelito_packaging_snapshot_context($context),
			array(
				'vendor_id'               => $vendor_id,
				'merchandise_value_cents' => papelito_packaging_merchandise_value_cents($context, array()),
				'measurement_source'      => '' === $source ? PAPELITO_PACKAGING_MEASUREMENT_LEGACY : $source,
				'packages'                => array(
					array(
						'length_mm' => papelito_packaging_cm_to_mm($package['length'] ?? 0),
						'width_mm'  => papelito_packaging_cm_to_mm($package['width'] ?? 0),
						'height_mm' => papelito_packaging_cm_to_mm($package['height'] ?? 0),
						'weight_g'  => (int) round((float) ($package['weight'] ?? 0)),
						'count'     => 1,
					),
				),
			)
		)
	);
}

/**
 * Avisa que as caixas do vendor mudaram.
 *
 * Criar, editar, desativar, reativar e excluir passam por aqui: qualquer uma
 * delas pode cruzar o mínimo em qualquer direção, e quem escuta decide se
 * abre ou arquiva o aviso.
 *
 * @param int $vendor_id Vendor dono das caixas.
 * @return void
 */
function papelito_packaging_announce_profiles_changed(int $vendor_id): void
{
	if ($vendor_id > 0) {
		do_action('papelito_vendor_packaging_profiles_changed', $vendor_id);
	}
}

/**
 * Diz se o vendor tem embalagem suficiente para vender.
 *
 * O mínimo é gate de ativação comercial, não exigência do algoritmo: a escolha
 * da caixa funciona com qualquer quantidade. Cair abaixo dele devolve o vendor
 * à inelegibilidade no mesmo instante.
 *
 * @param int $vendor_id Vendor consultado.
 * @return bool Se o vendor atinge o mínimo de caixas ativas.
 */
function papelito_packaging_vendor_is_eligible(int $vendor_id): bool
{
	if ($vendor_id <= 0) {
		return false;
	}

	return papelito_packaging_active_profile_count($vendor_id) >= PAPELITO_PACKAGING_MIN_ACTIVE_PROFILES;
}

/**
 * Diz se o gate de embalagem já vale para a cobertura.
 *
 * Nasce desligado de propósito. Ligá-lo antes de avisar os vendors e dar prazo
 * apagaria a vitrine inteira, porque um vendor sem caixa cadastrada some da
 * cobertura. A ordem de ativação está na BRASPRESS-003.
 *
 * @return bool Se a cobertura deve exigir o mínimo de caixas ativas.
 */
function papelito_packaging_profile_gate_enabled(): bool
{
	$configured = function_exists('papelito_shipping_provider_config')
		? papelito_shipping_provider_config('PAPELITO_PACKAGING_PROFILE_GATE_ENABLED', 'false')
		: false;

	return (bool) apply_filters(
		'papelito_packaging_profile_gate_enabled',
		true === filter_var($configured, FILTER_VALIDATE_BOOLEAN)
	);
}

/**
 * Varre os vendors e avisa quem está abaixo do mínimo de caixas.
 *
 * O aviso automático nasce de quem mexe nas próprias caixas, e quem nunca
 * cadastrou nada nunca mexe em nada — é justamente quem mais precisa ser
 * alcançado. Esta varredura é o empurrão inicial do rollout, não rotina.
 *
 * @param bool $dry_run Quando verdadeiro apenas conta, sem avisar ninguém.
 * @return array{vendors:int,pendentes:int,elegiveis:int} Resumo da varredura.
 */
function papelito_packaging_sweep_vendor_eligibility(bool $dry_run = false): array
{
	$resumo = array('vendors' => 0, 'pendentes' => 0, 'elegiveis' => 0);

	foreach (papelito_packaging_sweep_vendor_ids() as $vendor_id) {
		++$resumo['vendors'];
		$elegivel = papelito_packaging_vendor_is_eligible($vendor_id);
		$chave    = $elegivel ? 'elegiveis' : 'pendentes';
		++$resumo[$chave];

		if (! $dry_run) {
			papelito_packaging_announce_profiles_changed($vendor_id);
		}
	}

	return $resumo;
}

/**
 * Lê os vendors que a varredura precisa avaliar.
 *
 * @return array<int,int> IDs de vendor.
 */
function papelito_packaging_sweep_vendor_ids(): array
{
	if (! function_exists('get_users')) {
		return array();
	}

	$vendors = get_users(array('role' => 'seller', 'fields' => 'ID'));

	return array_values(array_filter(array_map('absint', is_array($vendors) ? $vendors : array())));
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
function papelito_packaging_braspress_package_filter(mixed $ignored, int $vendor_id, array $items): ?array
{
	return papelito_packaging_braspress_package($vendor_id, $items);
}

if (function_exists('add_filter')) {
	add_filter('papelito_braspress_physical_package', 'papelito_packaging_braspress_package_filter', 10, 3);
}

if (defined('WP_CLI') && WP_CLI) {
	/**
	 * Comandos de embalagem do Papelito.
	 */
	class Papelito_Packaging_CLI
	{
		/**
		 * Avisa os vendors que ainda não têm o mínimo de caixas cadastradas.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Apenas conta quem está pendente, sem criar aviso nem enviar e-mail.
		 *
		 * ## EXAMPLES
		 *
		 *     wp papelito packaging notify-eligibility --dry-run
		 *     wp papelito packaging notify-eligibility
		 *
		 * @param array<int,string>    $args Argumentos posicionais.
		 * @param array<string,string> $assoc_args Opções nomeadas.
		 * @return void
		 */
		public function notify_eligibility(array $args, array $assoc_args): void
		{
			$dry_run = isset($assoc_args['dry-run']);
			$resumo  = papelito_packaging_sweep_vendor_eligibility($dry_run);

			WP_CLI::log(sprintf('Vendors avaliados: %d', $resumo['vendors']));
			WP_CLI::log(sprintf('Com o mínimo de caixas: %d', $resumo['elegiveis']));
			WP_CLI::log(sprintf('Abaixo do mínimo: %d', $resumo['pendentes']));

			WP_CLI::success($dry_run ? 'Simulação concluída; nada foi enviado.' : 'Varredura concluída.');
		}
	}

	WP_CLI::add_command('papelito packaging', 'Papelito_Packaging_CLI');
}
