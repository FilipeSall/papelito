# Versionamento e Deploy do Papelito

## O que entra no Git

- `docker-compose.yml`
- `docker/`
- `scripts/`
- `docs/`
- `manual.md`
- `public_html/wp-config.example.php`
- `public_html/wp-config-sample.php`
- `public_html/wp-content/themes/jupiterx-child/`
- `public_html/wp-content/plugins/plugin_papelito/`
- `.github/workflows/manual-deploy.yml`

## O que fica fora do Git

- `public_html/wp-config.php`
- `.env` e `.env.local`
- `public_html/wp-admin/`
- `public_html/wp-includes/`
- `public_html/wp-content/uploads/`
- `public_html/wp-content/litespeed/`
- `public_html/wp-content/ai1wm-backups/`
- `public_html/wp-content/updraft/`
- `public_html/wp-content/mu-plugins/` atuais de terceiros
- `db/`, `backup/`, `artifacts/`
- `wp-cli.phar`

## Estrutura de responsabilidade

- `jupiterx-child`: layout, CSS/JS, templates, overrides de WooCommerce/Dokan/Elementor e ajustes visuais.
- `plugin_papelito`: hooks, metadados de usuário, lógica de CEP/frete e comportamento que precisa sobreviver à troca de tema.
- `wp-config`: segredo e comportamento por ambiente.

## Ambiente local

1. Copie `.env.example` para `.env`.
2. Suba os containers com `docker compose up -d --build`.
3. Rode `./scripts/local-wordpress-setup.sh`.
4. Valide com `./scripts/validate-wordpress.sh`.

## Fluxo de deploy manual

1. Preencha as variáveis remotas em `.env.local`.
2. Gere o pacote com `./scripts/package-theme.sh` ou `./scripts/package-plugin.sh`.
3. Faça backup remoto com `./scripts/backup-before-deploy.sh theme jupiterx-child` ou `./scripts/backup-before-deploy.sh plugin plugin_papelito`.
4. Faça deploy com `./scripts/deploy-theme.sh` ou `./scripts/deploy-plugin.sh`.
5. Teste login, home, WooCommerce, Dokan e regras de CEP/frete.

## Segredos do GitHub Actions

- `REMOTE_HOST_STAGING`
- `REMOTE_PORT_STAGING`
- `REMOTE_USER_STAGING`
- `REMOTE_THEMES_DIR_STAGING`
- `REMOTE_PLUGINS_DIR_STAGING`
- `REMOTE_WP_PATH_STAGING`
- `REMOTE_BACKUP_DIR_STAGING`
- `SSH_PRIVATE_KEY_STAGING`
- `REMOTE_HOST_PRODUCTION`
- `REMOTE_PORT_PRODUCTION`
- `REMOTE_USER_PRODUCTION`
- `REMOTE_THEMES_DIR_PRODUCTION`
- `REMOTE_PLUGINS_DIR_PRODUCTION`
- `REMOTE_WP_PATH_PRODUCTION`
- `REMOTE_BACKUP_DIR_PRODUCTION`
- `SSH_PRIVATE_KEY_PRODUCTION`

## Observações operacionais

- O `wp-config.php` local agora aceita variáveis de ambiente e mantém `DISALLOW_FILE_EDIT` automático fora de `local`.
- O Git interno do `jupiterx-child` deve ser preservado apenas como backup local, não como parte do novo repositório principal.
- Se existir customização em plugin premium ou tema pai, a migração deve ser feita para o child theme ou plugin custom antes de atualizar terceiros.
