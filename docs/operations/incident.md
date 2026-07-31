# Runbook — Resposta a incidente de segurança

## 1. Contenção (primeiros 30 minutos)

- **Trocar senhas**: banco (hPanel), SSH/FTP (hPanel → SSH Access), administradores WP (`wp user reset-password <ID>`).
- **Regenerar os salts** do WordPress em `https://api.wordpress.org/secret-key/1.1/salt/` e aplicar no `wp-config.php`. Isso invalida todas as sessões.
- Bloquear IPs suspeitos no `.htaccess`.
- Desativar plugin suspeito: `wp plugin deactivate <slug>`.
- Com evidência de defacement, tirar o site do ar: `wp maintenance-mode activate`.

Rotacionar também os segredos de integração, porque eles vivem no servidor: `GRAPHQL_JWT_AUTH_SECRET_KEY`, `GRAPHQL_WOOCOMMERCE_SECRET_KEY`, `PAGARME_SECRET_KEY`, `PAGARME_WEBHOOK_PASS`, credenciais dos Correios, tokens de provedor de CNPJ e `PAPELITO_FRONT_PROXY_TOKEN`.

> **Atenção às chaves de PII**: `PAPELITO_PII_ENCRYPTION_KEY` só pode ser rotacionada com bump de `PAPELITO_PII_KEY_VERSION` e a chave antiga preservada para decriptar. **`PAPELITO_PII_LOOKUP_KEY` não pode ser rotacionada sem reindexar todos os `cpf_hmac`** — rotacionar às pressas torna todo CPF cifrado inlocalizável. Ver [../context/data-model.md](../context/data-model.md#rotação--as-duas-chaves-têm-regras-opostas).

## 2. Diagnóstico

- Logs de acesso: hPanel → Files → `logs/`.
- Scan de malware: Wordfence Free, Sucuri SiteCheck (`https://sitecheck.sucuri.net/`).
- **Diff contra o repositório**: `bash scripts/pull-from-prod.sh` + `git diff` revelam alterações não autorizadas em tema, plugin e mu-plugins. É o jeito mais rápido de achar arquivo modificado.
- Usuários administrativos: `wp user list --role=administrator`.
- Log do plugin: `public_html/wp-content/uploads/papelito/logs/plugin_papelito.log`.
- Auditoria empresarial: tabela `papelito_company_audit_log`.

## 3. Restauração

- Backup limpo: **UpdraftPlus → S3**. Restaurar arquivos e banco de antes do incidente.
- Reaplicar por cima da restauração o que estiver no repositório: `main` do `papelito-wordpress` mais as alterações manuais de `wp-config.php` registradas em `wp-config.example.php`.
- Rodar `./scripts/validate-wordpress.sh` e conferir login, catálogo e checkout.

> Uma versão antiga deste runbook mandava "reaplicar Sprint 0 + Sprint 1". Esses nomes **não correspondem a nada existente no repositório** — ignore. A referência correta é o estado de `main` mais as constantes de ambiente.

## 4. Pós-mortem

Documente em `docs/incidents/YYYY-MM-DD-<slug>.md` — **o diretório não existe ainda; crie no primeiro incidente**. Depois, atualize este runbook com o que foi aprendido.

O pós-mortem deve registrar, no mínimo: vetor de entrada, janela de exposição, dados potencialmente acessados (com atenção a PII: CPF cifrado, documentos empresariais em `PAPELITO_PRIVATE_COMPANY_DOCUMENTS_DIR`), credenciais rotacionadas e o que mudou para não repetir.

## Ações de segurança pendentes registradas

Itens abertos que já foram identificados e não são incidentes ativos:

- **Revogar a chave CWS dos Correios que foi exposta em canal de chat** e criar uma chave técnica nova, mínima e temporária. Ver [correios-diagnostics.md](correios-diagnostics.md).
- **Rotacionar as credenciais da Pagar.me** (secret key e senha do webhook de produção) que passaram por canal de chat. Ver [pagarme-environment.md](pagarme-environment.md).
- **Rotacionar a senha da conta `marketing@papelito.com`** de produção, que estava documentada em texto claro no runbook de importação de catálogo.
