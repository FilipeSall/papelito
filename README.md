# Papelito — Backend WordPress (headless)

[![Lint](https://github.com/FilipeSall/papelito-wordpress/actions/workflows/lint.yml/badge.svg)](https://github.com/FilipeSall/papelito-wordpress/actions/workflows/lint.yml)
[![Deploy](https://github.com/FilipeSall/papelito-wordpress/actions/workflows/manual-deploy.yml/badge.svg)](https://github.com/FilipeSall/papelito-wordpress/actions/workflows/manual-deploy.yml)

WordPress headless servindo o frontend Next.js [`papelito-web`](https://github.com/FilipeSall/papelito-web). Todas as regras de negócio do marketplace vivem no plugin custom `plugin_papelito`.

## Arquitetura

```text
                 ┌──────────────┐
                 │  Vercel      │
   usuário ────▶ │  Next.js 16  │   https://marketplace.papelito.com
                 └──────┬───────┘
                        │ HTTPS  GraphQL /graphql
                        │        REST    /wp-json/papelito/v1/*
                        ▼
                 ┌──────────────────────┐
                 │  Hostinger           │
                 │  WordPress + Woo     │ ← /wp-admin só para administradores
                 │  + WPGraphQL         │
                 │  + plugin_papelito   │
                 └──────────────────────┘
```

## Começar

```bash
cp .env.example .env      # preencha GRAPHQL_JWT_AUTH_SECRET_KEY e GRAPHQL_WOOCOMMERCE_SECRET_KEY
docker compose up -d
bash scripts/local-wordpress-setup.sh
./scripts/validate-wordpress.sh
```

| Serviço | URL |
|---|---|
| WordPress | http://localhost:8080 (`admin` / `admin`) |
| phpMyAdmin | http://localhost:8081 |
| Mailpit | http://localhost:8025 |

## Documentação

- **[docs/README.md](docs/README.md)** — índice do backend: arquitetura do plugin, modelo de dados, invariantes, ambiente local, testes e os runbooks de operação (deploy, incidente, sync, credenciais).
- **[CLAUDE.md](CLAUDE.md)** — invariantes e convenções para quem (ou o que) vai editar o código.
- O contexto compartilhado com o frontend — negócio, contratos REST/GraphQL e fluxos ponta a ponta — fica no workspace, em `../docs/`.

## Variáveis críticas

`GRAPHQL_JWT_AUTH_SECRET_KEY` e `GRAPHQL_WOOCOMMERCE_SECRET_KEY` precisam estar definidas para o login JWT e a sessão/carrinho do WooGraphQL funcionarem. `WP_ENVIRONMENT_TYPE` controla o hardening. Tabela completa em [docs/context/architecture.md](docs/context/architecture.md#configuração-por-ambiente).

## Legado

`jupiterx-child`, `jupiterx-core`, `elementor`, `elementor-pro` e a stack associada ficaram fora do pipeline headless. Continuam no repositório até a troca para um tema mínimo e a remoção definitiva — ver [docs/context/legacy-stack-removal.md](docs/context/legacy-stack-removal.md), que explica por que o tema não pode ser simplesmente apagado.
