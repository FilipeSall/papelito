# API Headless do Papelito

Documentação dos endpoints disponíveis para o frontend Next.js (`papelito-web`).

## Endpoints

| Tipo | URL | Auth |
|---|---|---|
| GraphQL | `https://papelitobrasil.com.br/graphql` | JWT no header `Authorization: Bearer ...` (somente para resolvers que exigem) |
| REST custom | `https://papelitobrasil.com.br/wp-json/papelito/v1/*` | Varia |
| REST WP padrão | `https://papelitobrasil.com.br/wp-json/wp/v2/*` | Cookie/JWT (limitada por CORS) |
| WC Store API | `https://papelitobrasil.com.br/wp-json/wc/store/v1/*` | Nonce / cookie |

Enquanto `wp.papelitobrasil.com.br` não existir, a API headless de produção fica ancorada no domínio principal.

## Autenticação JWT

Pré-requisitos no `wp-config.php` / ambiente:

```text
GRAPHQL_JWT_AUTH_SECRET_KEY=<segredo para auth token>
GRAPHQL_WOOCOMMERCE_SECRET_KEY=<segredo para session token/cart token do WooGraphQL>
```

```graphql
mutation Login($u: String!, $p: String!) {
  login(input: { username: $u, password: $p }) {
    authToken
    refreshToken
    user { id email databaseId }
  }
}
```

Token JWT vai no header das requisições subsequentes:

```text
Authorization: Bearer <authToken>
```

Refresh:

```graphql
mutation Refresh($r: String!) {
  refreshJwtAuthToken(input: { jwtRefreshToken: $r }) { authToken }
}
```

## Catálogo (WooGraphQL)

```graphql
query Products($first: Int = 12, $after: String) {
  products(first: $first, after: $after, where: { status: PUBLISH }) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      databaseId
      name
      slug
      ... on SimpleProduct {
        price
        regularPrice
        salePrice
        stockStatus
      }
      image { sourceUrl altText }
      productCategories { nodes { id name slug } }
    }
  }
}
```

## CEP / Sellers (custom)

```graphql
query SellersByCep($cep: String!) {
  sellersByCep(cep: $cep) { id storeName }
}
```

REST equivalente:

```bash
curl -X POST https://papelitobrasil.com.br/wp-json/papelito/v1/cep \
  -H "Content-Type: application/json" -d '{"cep":"01310-100"}'
```

## Customer (área logada)

```graphql
query Me {
  customer {
    id
    email
    firstName
    lastName
    billing { city state postcode }
  }
}
```

## B2B / Migração de Legados (custom)

Todos os campos de autoridade vêm do WordPress em `/papelito/v1/auth/me`, dentro de `b2b`:

```jsonc
{
  "purchaseMode": "legacy | b2b | blocked",
  "isLegacyCohort": true,
  "legacyMigrationStatus": "eligible",
  "legacyGraceEndsAt": "2026-08-31 23:59:59",
  "legacyWarningLevel": "info | warning | urgent | none",
  "legacyCanPurchaseDuringGrace": true
}
```

Endpoints de usuário autenticado:

| Método | URL | Descrição |
|---|---|---|
| `GET` | `/wp-json/papelito/v1/legacy-migration/status` | Retorna status calculado da migração legado. |
| `POST` | `/wp-json/papelito/v1/legacy-migration/start` | Inicia criação de empresa ou solicitação de acesso assistida. |
| `POST` | `/wp-json/papelito/v1/legacy-migration/warning-viewed` | Registra visualização de aviso sem alterar bloqueios. |
| `POST` | `/wp-json/papelito/v1/legacy-migration/restart` | Reinicia onboarding expirado. |

Endpoints administrativos exigem `papelito_manage_companies`:

| Método | URL | Descrição |
|---|---|---|
| `GET` | `/wp-json/papelito/v1/admin/legacy-migration/summary` | Agregados do coorte sem PII. |
| `GET` | `/wp-json/papelito/v1/admin/legacy-migration/users` | Lista segura por status. |
| `POST` | `/wp-json/papelito/v1/admin/legacy-migration/{userId}/resend` | Reenvia campanha idempotente. |
| `POST` | `/wp-json/papelito/v1/admin/legacy-migration/{userId}/exempt` | Marca isenção com motivo. |

WP-CLI:

```bash
wp papelito b2b legacy audit --format=csv --output=/path/seguro/legacy-audit.csv
wp papelito b2b legacy mark-cohort --cutoff="AAAA-MM-DD HH:MM:SS" --dry-run
wp papelito b2b legacy mark-cohort --cutoff="AAAA-MM-DD HH:MM:SS" --apply
wp papelito b2b legacy status
wp papelito b2b legacy send-campaign --campaign=initial_notice --dry-run
```

Relatórios não devem expor CPF/CNPJ completo; documentos são mascarados ou hasheados.

## Estoque do Vendor (custom)

Endpoints REST do painel de estoque (`/vendor/estoque` no front). Auth: JWT de vendor aprovado.

### `GET /wp-json/papelito/v1/vendor/me/stock`

Lista o catálogo (global) com o estoque sobreposto do vendor autenticado. Aceita filtros aplicados no banco (não só na página atual):

| Param | Tipo | Default | Descrição |
|---|---|---|---|
| `search` | string | `''` | Nome do produto ou SKU |
| `filter` | string | `all` | `all` \| `with_stock` \| `zeroed_only` |
| `category` | int | `0` | `term_id` de uma categoria (`product_cat`); seleção única |
| `tags` | string (CSV) | `''` | `term_id`s de tags (`product_tag`) separados por vírgula. Semântica **OR** (produto com qualquer das tags) |
| `sort` | string | `name_asc` | `name_asc` \| `name_desc` \| `qty_desc` \| `qty_asc` \| `updated_desc`. Valor inválido cai para `name_asc` |
| `page` / `per_page` | int | `1` / `20` | Paginação sobre o recorte filtrado |

Cada item da resposta inclui `categories` e `tags` (termos do produto; para variações, herdados do produto pai):

```jsonc
{
  "items": [
    {
      "product_id": 123,
      "product_name": "Seda King Size",
      "sku": "SK-1",
      "qty": 5,
      "updated_at": "2026-06-21 12:00:00",
      "is_zeroed": false,
      "image_url": "https://.../seda.jpg",
      "categories": [{ "id": 7, "name": "Sedas", "slug": "sedas" }],
      "tags": [{ "id": 12, "name": "Combo", "slug": "combo" }]
    }
  ],
  "total": 22,
  "page": 1,
  "per_page": 20
}
```

```bash
curl -H "Authorization: Bearer <JWT>" \
  "https://papelitobrasil.com.br/wp-json/papelito/v1/vendor/me/stock?category=7&tags=12,45&sort=qty_desc"
```

### `GET /wp-json/papelito/v1/vendor/me/stock/taxonomies`

Opções para o drawer de filtros: categorias e tags com `count > 0` (lista global). Cacheado ~10 min (transient, invalidação só por TTL — independente do cache de cobertura).

```jsonc
{
  "categories": [{ "id": 7, "name": "Sedas", "slug": "sedas", "count": 3 }],
  "tags":       [{ "id": 12, "name": "Combo", "slug": "combo", "count": 4 }]
}
```

> `PUT /wp-json/papelito/v1/vendor/me/stock` (ajuste de quantidade) não mudou. Os mesmos filtros `category`/`tags`/`sort` existem no endpoint admin `GET /admin/vendors/{id}/stock` (paridade de backend; UI admin fora de escopo).

## CORS

Allowlist controlada por `PAPELITO_ALLOWED_ORIGINS` no `wp-config.php`. Veja `mu-plugins/papelito-cors.php`.
