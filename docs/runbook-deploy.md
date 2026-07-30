# Runbook — Deploy do backend

## Fluxo padrão
1. Branch `feature/<slug>` (ou `fix/<slug>`).
2. PR para `main`. CI roda PHPCS.
3. Merge: workflow `Deploy` dispara automaticamente. Detecta o que mudou (theme/plugin/mu-plugins/htaccess) e roda só o necessário.
4. Backup remoto criado em `$REMOTE_BACKUP_DIR_PRODUCTION/<artifact>-<timestamp>.tgz`.
5. Flush WP-CLI executado (`wp cache flush`, `wp rewrite flush`).

## Domínio do frontend

Em produção, defina `PAPELITO_FRONTEND_URL=https://marketplace.papelito.com` e inclua
`https://marketplace.papelito.com` em `PAPELITO_ALLOWED_ORIGINS`. No Vercel, defina
`NEXTAUTH_URL=https://marketplace.papelito.com` para o mesmo ambiente de produção.

Durante a transição, `https://papelito-web.vercel.app` pode permanecer na allowlist de
CORS como fallback. Não use esse domínio em `PAPELITO_FRONTEND_URL` ou `NEXTAUTH_URL`;
ele deve redirecionar para o domínio canônico na Vercel.

## Deploy manual (workflow_dispatch)
GitHub → Actions → Deploy → Run workflow → escolher target/artifact.

## Rollback
1. Localizar backup: `ssh ... 'ls -lt $REMOTE_BACKUP_DIR_PRODUCTION | head'`.
2. Restaurar: `ssh ... 'tar xzf $REMOTE_BACKUP_DIR_PRODUCTION/<arq>.tgz -C /tmp/restore && rsync -av /tmp/restore/<artifact>/ $TARGET_DIR/'`.
3. `wp cache flush`.

## Rollback B2B Step 4

1. Desligar `PAPELITO_B2B_LEGACY_WARNING_ENABLED`, `PAPELITO_B2B_LEGACY_EMAIL_ENABLED` e
   `PAPELITO_B2B_LEGACY_MIGRATION_ENABLED`.
2. Manter `PAPELITO_B2B_PURCHASE_ENFORCED=false` enquanto o Step 5 não for aprovado.
3. Não remover `papelito_b2b_legacy_cohort`, logs de campanha, empresas, memberships ou
   onboardings.
4. Não reverter usuários já migrados (`papelito_b2b_required=1`) para checkout legado.

## Hotfix urgente em produção
1. Editar via SSH (último recurso).
2. Imediatamente depois rodar `bash scripts/pull-from-prod.sh` localmente.
3. Commit em `chore/sync-prod-<data>`. PR e merge para reconciliar.
