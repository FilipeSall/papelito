# Runbook — Deploy do backend

## Fluxo padrão
1. Branch `feature/<slug>` (ou `fix/<slug>`).
2. PR para `main`. CI roda PHPCS.
3. Merge: workflow `Deploy` dispara automaticamente. Detecta o que mudou (theme/plugin/mu-plugins/htaccess) e roda só o necessário.
4. Backup remoto criado em `$REMOTE_BACKUP_DIR_PRODUCTION/<artifact>-<timestamp>.tgz`.
5. Flush WP-CLI executado (`wp cache flush`, `wp rewrite flush`).

## Deploy manual (workflow_dispatch)
GitHub → Actions → Deploy → Run workflow → escolher target/artifact.

## Rollback
1. Localizar backup: `ssh ... 'ls -lt $REMOTE_BACKUP_DIR_PRODUCTION | head'`.
2. Restaurar: `ssh ... 'tar xzf $REMOTE_BACKUP_DIR_PRODUCTION/<arq>.tgz -C /tmp/restore && rsync -av /tmp/restore/<artifact>/ $TARGET_DIR/'`.
3. `wp cache flush`.

## Hotfix urgente em produção
1. Editar via SSH (último recurso).
2. Imediatamente depois rodar `bash scripts/pull-from-prod.sh` localmente.
3. Commit em `chore/sync-prod-<data>`. PR e merge para reconciliar.
