# Runbook — Deploy do backend

## Fluxo padrão

1. Branch `feature/<slug>` (ou `fix/<slug>`).
2. PR para `main`. O CI roda **PHPCS** (`.github/workflows/lint.yml`). **Não roda testes.**
3. Merge dispara o workflow `Deploy`, que **detecta o que mudou** (theme / plugin / mu-plugins / htaccess) e roda só o necessário.
4. Backup remoto é criado em `$REMOTE_BACKUP_DIR_PRODUCTION/<artifact>-<timestamp>.tgz`.
5. Flush via WP-CLI: `wp cache flush` e `wp rewrite flush`.

Deploy manual: GitHub → Actions → **Deploy** → *Run workflow* → escolher target/artifact.

> **O deploy faz rsync de `themes/` e `plugins/` e não toca o `wp-config.php`.** Variável de ambiente nova em produção exige edição manual no servidor. Ver [pagarme-environment.md](pagarme-environment.md#produção-a-incompatibilidade-que-custa-tempo).

## Domínio do frontend

Em produção:

```
PAPELITO_FRONTEND_URL=https://marketplace.papelito.com
PAPELITO_ALLOWED_ORIGINS=...,https://marketplace.papelito.com
# na Vercel, no ambiente de produção:
NEXTAUTH_URL=https://marketplace.papelito.com
```

Durante a transição, `https://papelito-web.vercel.app` pode continuar na allowlist de CORS e acessível como fallback. **Nunca** use esse domínio em `PAPELITO_FRONTEND_URL` nem em `NEXTAUTH_URL`: **sessões de login não são compartilhadas entre os domínios** — o usuário que logar em um não estará logado no outro.

## Rollback

```bash
# 1. localizar o backup
ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST 'ls -lt $REMOTE_BACKUP_DIR_PRODUCTION | head'

# 2. restaurar
ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST \
  'tar xzf $REMOTE_BACKUP_DIR_PRODUCTION/<arquivo>.tgz -C /tmp/restore \
   && rsync -av /tmp/restore/<artifact>/ $TARGET_DIR/'

# 3. limpar cache
ssh ... 'wp cache flush'
```

## Rollback do modo de aviso a legados

Isto **não** é só desligar flags — tem regras de irreversibilidade:

1. Desligar `PAPELITO_B2B_LEGACY_WARNING_ENABLED`, `PAPELITO_B2B_LEGACY_EMAIL_ENABLED` e `PAPELITO_B2B_LEGACY_MIGRATION_ENABLED`.
2. `PAPELITO_B2B_PURCHASE_ENFORCED` **não desliga o enforce de compra** — o checkout consulta `canPurchase` incondicionalmente. A flag só decide o aviso da coorte legada. Rollback do enforce é por build, não por flag. Ver [architecture.md](../../../docs/architecture.md#o-enforce-de-compra-é-incondicional).
3. **Não remover** `papelito_b2b_legacy_cohort`, logs de campanha, empresas, memberships nem onboardings.
4. **Não reverter usuários já migrados** (`papelito_b2b_required=1`) para o checkout legado — eles não têm caminho de volta e ficariam sem nenhum fluxo válido.

Rollback do modelo B2B em geral é **por build/flag**, preservando tabelas, snapshots, usermeta e pedidos históricos. Desligar flag **não** apaga tabela.

## Hotfix urgente em produção

1. Editar via SSH — **último recurso**.
2. Imediatamente depois, rodar `bash scripts/pull-from-prod.sh` localmente.
3. Commit em `chore/sync-prod-<data>`, PR e merge para reconciliar.

Se isso não for feito, o próximo deploy sobrescreve o hotfix. O procedimento completo está em [sync-from-prod.md](sync-from-prod.md).

## Scripts de deploy

| Script | Para quê |
|---|---|
| `scripts/package-theme.sh` / `package-plugin.sh` | empacotar o artefato |
| `scripts/backup-before-deploy.sh theme|plugin <nome>` | backup remoto antes de sobrescrever |
| `scripts/deploy-theme.sh` / `deploy-plugin.sh` / `deploy-mu-plugins.sh` / `deploy-htaccess.sh` | rsync do artefato |
| `scripts/validate-wordpress.sh` | smoke test |
| `scripts/pull-from-prod.sh` | trazer o servidor para o repositório |

> `deploy-mu-plugins.sh` roda com `--include='README.md'`. O `mu-plugins/README.md` é parte do deploy — **não mova esse arquivo**.

## Secrets do GitHub Actions

Dois conjuntos, staging e produção (o de staging existe na configuração mesmo sem um WordPress de staging ativo):

```
REMOTE_HOST_STAGING          REMOTE_HOST_PRODUCTION
REMOTE_PORT_STAGING          REMOTE_PORT_PRODUCTION
REMOTE_USER_STAGING          REMOTE_USER_PRODUCTION
REMOTE_THEMES_DIR_STAGING    REMOTE_THEMES_DIR_PRODUCTION
REMOTE_PLUGINS_DIR_STAGING   REMOTE_PLUGINS_DIR_PRODUCTION
REMOTE_WP_PATH_STAGING       REMOTE_WP_PATH_PRODUCTION
REMOTE_BACKUP_DIR_STAGING    REMOTE_BACKUP_DIR_PRODUCTION
SSH_PRIVATE_KEY_STAGING      SSH_PRIVATE_KEY_PRODUCTION
```

## Antes de subir uma mudança de schema

1. Backup completo do banco.
2. Validar a migração em ambiente equivalente (local com dump recente).
3. Conferir que `PAPELITO_DB_VERSION` foi bumpada — sem isso o `papelito_maybe_migrate_db()` não roda o instalador novo.
4. Confirmar que a migração é idempotente (aplicar duas vezes).

## Ambiente de hospedagem

Hostinger Business, hospedagem compartilhada **CloudLinux + CageFS**: sem root, sem `systemctl`, sem Docker, sem editar pool do FPM. PHP 8.3. O diretório home fora do `public_html` é gravável, o que permite guardar arquivo de segredos fora da webroot.

### Diretórios privados fora do `public_html`

`PAPELITO_PRIVATE_COMPANY_DOCUMENTS_DIR` e `PAPELITO_PRIVATE_FISCAL_DOCUMENTS_DIR` apontam para diretórios **fora do webroot**. Não há fallback público: diretório dentro de `public_html` falha com `*_storage_public` **antes de gravar** qualquer byte.

Como o deploy não toca o `wp-config.php`, variável nova exige edição manual no servidor:

```bash
mkdir -p ~/papelito-private/fiscal-documents && chmod 700 ~/papelito-private/fiscal-documents
# em wp-config.php, acima do "That's all":
# putenv( 'PAPELITO_PRIVATE_FISCAL_DOCUMENTS_DIR=/home/<user>/papelito-private/fiscal-documents' );
```

Confira que o caminho **não** está sob `public_html` e que o diretório não é servido por HTTP antes de subir qualquer arquivo.

**Não existe WordPress de staging.** Homologação de frontend é Vercel Preview; homologação de backend é local.
