# API Headless do Papelito

Documentação dos endpoints disponíveis para o frontend Next.js (`papelito-web`).

## Endpoints

| Tipo | URL | Auth |
|---|---|---|
| GraphQL | `https://wp.papelitobrasil.com.br/graphql` | JWT no header `Authorization: Bearer ...` (somente para resolvers que exigem) |
| REST custom | `https://wp.papelitobrasil.com.br/wp-json/papelito/v1/*` | Varia |
| REST WP padrão | `https://wp.papelitobrasil.com.br/wp-json/wp/v2/*` | Cookie/JWT (limitada por CORS) |
| WC Store API | `https://wp.papelitobrasil.com.br/wp-json/wc/store/v1/*` | Nonce / cookie |

## Autenticação JWT

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
curl -X POST https://wp.papelitobrasil.com.br/wp-json/papelito/v1/cep \
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

## CORS

Allowlist controlada por `PAPELITO_ALLOWED_ORIGINS` no `wp-config.php`. Veja `mu-plugins/papelito-cors.php`.
