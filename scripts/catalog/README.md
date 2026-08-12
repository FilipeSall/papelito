# Catalog import scripts

Wipe + reimport do catálogo Papelito a partir da planilha `Catálogo de Produtos - E-commerce.xlsx`. Aplicado em **02/05/2026**, quando o catálogo passou a ser **centralizado** (49 produtos cadastrados só pela Papelito, saindo do modelo Dokan de produto por vendedor).

> Isso **não** significa que o sistema virou single-vendor. A arquitetura atual é multi-vendor: o catálogo é único e centralizado, e cada vendor tem estoque próprio e faixas de CEP. Ver [`../../../docs/system-overview.md`](../../../docs/system-overview.md).

## Quando usar
- Reimport completo após nova versão da planilha
- Reset do catálogo em ambiente novo (staging, dev local)
- Auditoria de quais transformações foram aplicadas (planilha → WP)

## Pré-requisitos remotos
- WP-CLI (`/usr/local/bin/wp`)
- PHP 8+ com extensão `imagick`
- Plugins ativos: WooCommerce, WPGraphQL, WPGraphQL-WooCommerce
- Backup do banco antes de rodar (todas as ações são destrutivas)

## Pré-requisitos locais
- Python 3.10+ com `openpyxl` (`pip install openpyxl`)
- Acesso SSH ao servidor remoto

## Pipeline

### 1. Gerar `catalog.json` localmente
Lê a XLSX e produz o JSON intermediário.

```bash
PAPELITO_XLSX=/caminho/para/catalogo.xlsx \
PAPELITO_OUTPUT=/tmp/catalog.json \
PAPELITO_IMG_LOCAL_DIR=/caminho/para/papelito-web/public/images/products \
PAPELITO_IMG_REMOTE_BASE=/var/www/html/wp-content/uploads/papelito-import \
python3 build_catalog.py
```

Output: `simple: 34 / variable groups: 6 / draft: 9 / images mapped: 35`.

### 2. Limpar catálogo + criar usuário (manual via wp-cli ou SQL)
Antes do import:

```bash
# Backup
mysqldump -u USER -pPASS DB | gzip > pre-catalog-wipe-$(date +%Y%m%d).sql.gz

# Wipe (no servidor remoto)
mysql DB <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
DELETE pm FROM wp_postmeta pm JOIN wp_posts p ON pm.post_id=p.ID WHERE p.post_type IN ('product','product_variation');
DELETE tr FROM wp_term_relationships tr JOIN wp_posts p ON tr.object_id=p.ID WHERE p.post_type IN ('product','product_variation');
DELETE FROM wp_posts WHERE post_type IN ('product','product_variation');
TRUNCATE TABLE wp_wc_product_meta_lookup;
TRUNCATE TABLE wp_wc_product_attributes_lookup;
TRUNCATE TABLE wp_wc_category_lookup;
DELETE tr FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tt.taxonomy IN ('product_cat','product_tag') OR tt.taxonomy LIKE 'pa_%';
DELETE t FROM wp_terms t JOIN wp_term_taxonomy tt ON t.term_id=tt.term_id WHERE tt.taxonomy IN ('product_cat','product_tag') OR tt.taxonomy LIKE 'pa_%';
DELETE FROM wp_term_taxonomy WHERE taxonomy IN ('product_cat','product_tag') OR taxonomy LIKE 'pa_%';
TRUNCATE TABLE wp_woocommerce_attribute_taxonomies;
SET FOREIGN_KEY_CHECKS=1;
SQL

# Criar usuário autor dos produtos
wp user create marketing marketing@papelito.com --role=administrator --user_pass='<senha forte>'
```

> A senha usada na execução original de 02/05/2026 era fraca e ficou documentada em texto claro neste arquivo. **Ela deve ser rotacionada** — a conta é administrador de produção. Use uma senha forte e guarde-a no cofre, nunca aqui.

### 3. Subir imagens + JSON
Copiar o conteúdo de `papelito-web/public/images/products/{sedas,piteiras,filtros}/` para o servidor remoto em `wp-content/uploads/papelito-import/` (mesma estrutura). E subir o `catalog.json` para um local acessível.

### 4. Rodar o import
No servidor remoto, no diretório do WP:

```bash
PAPELITO_AUTHOR_LOGIN=marketing \
PAPELITO_CATALOG_JSON=/caminho/catalog.json \
wp eval-file /caminho/import_catalog.php
```

Antes do import, execute o seed/classificação em dry-run e depois com `PAPELITO_TAXONOMY_APPLY=1`. O importador não escreve `product_cat`: cria cada produto como rascunho, atribui a categoria Papelito pelas funções do domínio e só então restaura o status alvo. Sem correspondência ou seed, o item fica em rascunho, recebe `_papelito_taxonomy_todo` e entra no relatório de erros.

### 5. Otimizar imagens originais
Reduz PNGs para máx 1500px de largura, recomprime, limpa thumbnails antigos. Backup em `~/papelito-img-backup-<timestamp>/`.

```bash
php optimize_images.php /caminho/wp-content/uploads/2026/05
wp media regenerate --yes
```

Resultado típico: 35 originais 5–13 MB → 1–2 MB cada (~250 MB economizados).

### 6. Aplicar promoções (opcional)
Define preços de oferta nos 4 produtos da seção "Oferta Relâmpago" da home. Edite os IDs em `set_sale.php` antes:

```bash
wp eval-file /caminho/set_sale.php
```

## Validação

```bash
# Total de produtos publicados
curl -s -X POST https://papelitobrasil.com.br/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"{ products(first:1000) { nodes { databaseId } } }"}' | jq '.data.products.nodes | length'

# Produtos em promoção
curl -s -X POST https://papelitobrasil.com.br/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"{ products(where:{onSale:true}) { nodes { name } } }"}'
```

## Migração da taxonomia (`product_cat` → entidade Papelito)

Três arquivos, independentes do pipeline de import acima. Plano completo em
[`docs/prompts/product-taxonomy/`](../../../docs/prompts/product-taxonomy/README.md).

| Arquivo | O que é |
|---|---|
| `taxonomy_map.php` | só dados e funções puras: seed da taxonomia proposta, mapa `raiz\|filho` → destino, tokens de título e o resolvedor. Nada consulta banco nem imprime |
| `migrate_taxonomy.php` | **fase 3, dry-run**: lê o estado atual, aplica o mapa e imprime o relatório. **Não escreve nada** |
| `test_taxonomy_map.php` | 34 checagens sobre as funções puras. Trava as armadilhas de derivação |

O container `web` só monta `public_html`, então os scripts entram por `docker cp`:

```bash
docker cp scripts/catalog/taxonomy_map.php      papelito-web:/tmp/
docker cp scripts/catalog/migrate_taxonomy.php  papelito-web:/tmp/
docker cp scripts/catalog/test_taxonomy_map.php papelito-web:/tmp/

docker compose exec -T web wp eval-file /tmp/test_taxonomy_map.php
docker compose exec -T web wp eval-file /tmp/migrate_taxonomy.php
```

Variáveis: `PAPELITO_TAXONOMY_REPORT` (caminho do CSV, default `/tmp/papelito-taxonomy-dryrun.csv`) e
`PAPELITO_TAXONOMY_VERBOSE=1` (lista produto por produto).

### Aplicar de verdade (fase 4)

```bash
# dump antes — o script é idempotente, mas dump é barato
docker exec papelito-db mariadb-dump -upapelito -ppapelito_local_123 papelito_local \
  wp_terms wp_term_taxonomy wp_term_relationships wp_postmeta wp_options > /tmp/pre-taxonomia.sql

docker compose exec -T -e PAPELITO_TAXONOMY_APPLY=1 web wp eval-file /tmp/migrate_taxonomy.php
```

**Escreve somente nas tabelas `wp_papelito_*`.** `product_cat` não é tocado: o dual-write fica suprimido
durante a migração, de propósito. Confira com o hash do conjunto de vínculos antes e depois:

```bash
docker exec papelito-db mariadb -upapelito -ppapelito_local_123 papelito_local -N -e "
SELECT MD5(GROUP_CONCAT(CONCAT(tr.object_id,':',tt.term_id) ORDER BY tr.object_id, tt.term_id))
  FROM wp_term_relationships tr JOIN wp_term_taxonomy tt USING(term_taxonomy_id)
 WHERE tt.taxonomy='product_cat';"
```

Rollback: `PAPELITO_TAXONOMY_APPLY=1 PAPELITO_TAXONOMY_RESET=1` apaga os vínculos de produto e reaplica. O
seed de categorias permanece.

Produtos com classificação parcial ficam marcados em `_papelito_taxonomy_todo` — mesma convenção do
`_papelito_import_todo`. Para listar:

```bash
docker compose exec -T web wp eval 'foreach ( get_posts( array( "post_type" => "product", "post_status" => "any", "numberposts" => -1, "meta_key" => "_papelito_taxonomy_todo" ) ) as $p ) { echo "#{$p->ID} {$p->post_title}\n"; }'
```

**Armadilhas que o `test_taxonomy_map.php` protege** — cada uma classificaria errado em silêncio:

- token de título é casado **do mais longo para o mais curto** e o trecho é consumido; sem isso `Longa`
  casaria dentro de `Mega Longa`;
- tamanho de bandeja só é derivado quando o tipo é `bandeja`: `Bandeja Chaveiro P Amarelo` tem um "P"
  que é **cor**, não tamanho;
- termo combinado é decomposto (`Brown Slim` → Brown + Slim), então toda subcategoria referenciada pelo
  mapa precisa existir no seed — há teste de integridade referencial para isso.

## Limitações conhecidas
- 9 produtos importados como `draft` por dados incompletos na planilha (`Filtro Gomado`, `Dichavador Cristal`, `Bandeja Chaveiro Relax/Amarelo/Black`, `Cinzeiro`, `Bandeja P/M/G`). Procurar pela meta `_papelito_import_todo` no admin para ver o que falta.
- SKU `PP03020002` aparece duplicado na planilha (Seda Slim Longa + Filtro Slim Longo). O da seda é renomeado para `PP03020002-<slug>` durante o import.
- Acessórios variáveis (Dichavador, Tubelito) não têm SKU nas variações — coluna G da planilha tem números soltos (provável GTIN/EAN), não foi possível mapear de forma confiável.

## Estado atual
Ver `_papelito_import_todo` postmeta para ver pendências por produto.

## Relação com o resto do sistema

Este pipeline tem um acoplamento cruzado que não é óbvio: as imagens de produto vêm de `papelito-web/public/images/products/` — **o repositório do frontend é entrada de build deste script do backend**. Mover ou renomear essas pastas quebra os passos 1 e 3.

Produto importado sem peso e dimensões faz a cotação de frete falhar no checkout (`422`). Ao concluir um reimport, confira os itens marcados com `_papelito_import_todo` antes de considerar o catálogo utilizável — ver [`../../docs/context/business-rules.md`](../../docs/context/business-rules.md).
