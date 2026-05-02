# Runbook — Resposta a incidente de segurança

## 1. Contenção (primeiros 30 min)
- Trocar senhas: DB (hPanel), SSH/FTP (hPanel → SSH Access), admins WP (`wp user reset-password ID`), regenerar salts (`api.wordpress.org/secret-key/1.1/salt/`).
- Bloquear IPs suspeitos no `.htaccess`.
- Desabilitar plugins suspeitos: `wp plugin deactivate <slug>`.
- Tirar site do ar se houver evidência de defacement: `wp maintenance-mode activate`.

## 2. Diagnóstico
- Logs de acesso: hPanel → Files → `logs/`.
- Scan de malware: Wordfence Free / Sucuri SiteCheck (`https://sitecheck.sucuri.net/`).
- Diff: `bash scripts/pull-from-prod.sh` + `git diff` revelam alterações não autorizadas em tema/plugin/mu-plugins.
- Verificar usuários: `wp user list --role=administrator`.

## 3. Restauração
- Backup limpo (UpdraftPlus → S3): restaurar arquivos + DB de antes do incidente.
- Reaplicar Sprint 0 + Sprint 1 por cima da restauração.

## 4. Pós-mortem
- Documentar em `docs/incidents/YYYY-MM-DD-<slug>.md`.
- Atualizar `runbook-incidente.md` com aprendizados.
