# Runbook — Sincronizar mudanças do servidor para o repositório

Use quando alguém alterar arquivos diretamente em produção: hotfix, hardening, edição via SSH. Sem isso, o próximo deploy sobrescreve a alteração.

## Pré-condições

- SSH funcionando: `ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST "echo ok"`.
- `.env` preenchido com as variáveis `REMOTE_*`.

## Passos

1. Branch limpa: `git checkout -b chore/sync-prod-$(date +%Y%m%d)`.
2. `bash scripts/pull-from-prod.sh`.
3. Revisar: `git status`, `git diff`.
4. **Comparar `_pulled/wp-config.php` com `public_html/wp-config.example.php`.** Se apareceu constante nova no servidor, atualize o exemplo.
5. `git add -A && git commit -m "chore: sync prod $(date +%Y-%m-%d)"`.
6. PR para `main`.

O passo 4 é o valor não óbvio deste runbook: **é o único mecanismo que captura variáveis e constantes novas de produção para o repositório.** O `wp-config.php` de produção é mantido à mão e não vem no deploy; sem essa comparação, o `wp-config.example.php` envelhece silenciosamente e o próximo ambiente novo sobe incompleto.

`_pulled/` é área de auditoria e é gitignorada — o `wp-config.php` real **não** vai para o Git.

## Quando NÃO usar

- **Conteúdo** (posts, produtos, pedidos): isso vive no banco, não no Git.
- **Uploads**: precisa de rsync separado. `scripts/pull-prod-uploads.sh` está previsto e **ainda não existe**.

## Uso como tripwire

O mesmo script serve a um propósito diferente e útil: `bash scripts/pull-from-prod.sh` seguido de `git diff` **revela alterações não autorizadas** em tema, plugin ou mu-plugins. É o jeito mais rápido de detectar arquivo modificado por invasor. Ver [incident.md](incident.md).
