# Runbook — Sincronizar mudanças manuais do servidor para o repo

Use quando alguém alterar arquivos diretamente em produção (hotfix, hardening, edição via SSH).

## Pré-condição
- SSH funcionando (`ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST "echo ok"`).
- `.env` preenchido com `REMOTE_*`.

## Passos

1. Branch limpa: `git checkout -b chore/sync-prod-$(date +%Y%m%d)`.
2. Rodar pull: `bash scripts/pull-from-prod.sh`.
3. Revisar: `git status`, `git diff`.
4. Comparar `_pulled/wp-config.php` (auditoria, não vai pro Git) com `public_html/wp-config.example.php`. Atualize o exemplo se houver constante nova.
5. Commit: `git add -A && git commit -m "chore: sync prod $(date +%Y-%m-%d)"`.
6. PR para `main`.

## Quando NÃO usar
- Para conteúdo (posts, produtos): isso vive no banco, não no Git.
- Para uploads: rsync separado (`scripts/pull-prod-uploads.sh`, futuro).
