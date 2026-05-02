# Onboarding — papelito-wordpress

## Stack
- WordPress 6.9 + WooCommerce + Dokan + tema filho `jupiterx-child` + plugin custom `plugin_papelito`.
- WPGraphQL + WooGraphQL + JWT Auth (headless).
- Hosting: Hostinger Business (SSH).

## Setup local
1. Instalar Docker e Docker Compose.
2. `cp .env.example .env`.
3. `docker-compose up -d`.
4. Importar dump: `bash scripts/local-wordpress-setup.sh`.
5. Acessar `http://localhost:8080`. Admin: `admin/admin`.
6. Para autenticação headless funcionar localmente, definir `GRAPHQL_JWT_AUTH_SECRET_KEY` e `GRAPHQL_WOOCOMMERCE_SECRET_KEY` no `.env` antes de subir o container.

## O que está versionado
- `public_html/wp-content/themes/jupiterx-child/` — tema filho.
- `public_html/wp-content/plugins/plugin_papelito/` — plugin custom.
- `public_html/wp-content/mu-plugins/papelito-*.php` — must-use de hardening/CORS.
- `public_html/.htaccess`, `public_html/wp-content/uploads/.htaccess`.
- `public_html/wp-config.example.php` — referência (real fica fora do Git).

## Workflow
1. `git checkout -b feature/<slug>`.
2. Editar arquivo versionado.
3. Push + PR. CI roda PHPCS.
4. Merge em `main` → deploy automático Hostinger.

## Comandos úteis
- `docker-compose exec papelito-web wp ...` — WP-CLI local.
- `bash scripts/pull-from-prod.sh` — sync servidor → repo.
- `composer phpcs` — lint local.
