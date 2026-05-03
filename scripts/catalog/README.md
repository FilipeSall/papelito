# Catalog import scripts

Wipe + reimport do catálogo Papelito a partir da planilha `Catálogo de Produtos - E-commerce.xlsx`. Aplicado em **02/05/2026** para sair do modelo marketplace (Dokan) para single-vendor com 49 produtos.

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
wp user create marketing marketing@papelito.com --role=administrator --user_pass=papelito
```

### 3. Subir imagens + JSON
Copiar o conteúdo de `papelito-web/public/images/products/{sedas,piteiras,filtros}/` para o servidor remoto em `wp-content/uploads/papelito-import/` (mesma estrutura). E subir o `catalog.json` para um local acessível.

### 4. Rodar o import
No servidor remoto, no diretório do WP:

```bash
PAPELITO_AUTHOR_LOGIN=marketing \
PAPELITO_CATALOG_JSON=/caminho/catalog.json \
wp eval-file /caminho/import_catalog.php
```

Cria categorias (`Sedas`, `Piteiras`, `Filtros`, `Acessórios` + subcategorias), atributo `pa_cor`, produtos simples, variáveis e rascunhos. Anexa imagens via `media_sideload_image`.

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

## Limitações conhecidas
- 9 produtos importados como `draft` por dados incompletos na planilha (`Filtro Gomado`, `Dichavador Cristal`, `Bandeja Chaveiro Relax/Amarelo/Black`, `Cinzeiro`, `Bandeja P/M/G`). Procurar pela meta `_papelito_import_todo` no admin para ver o que falta.
- SKU `PP03020002` aparece duplicado na planilha (Seda Slim Longa + Filtro Slim Longo). O da seda é renomeado para `PP03020002-<slug>` durante o import.
- Acessórios variáveis (Dichavador, Tubelito) não têm SKU nas variações — coluna G da planilha tem números soltos (provável GTIN/EAN), não foi possível mapear de forma confiável.

## Estado atual
Ver `_papelito_import_todo` postmeta para ver pendências por produto.
