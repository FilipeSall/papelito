# mu-plugins do Papelito

Plugins must-use carregados automaticamente pelo WordPress (não desabilitáveis pela UI).

| Arquivo | Propósito |
|---|---|
| `papelito-hardening.php` | Hardening: bloqueia enumeração de users, desativa XML-RPC, remove generator, rate limit em login |
| `papelito-cors.php` | CORS controlado para REST e WPGraphQL com allowlist via `PAPELITO_ALLOWED_ORIGINS` |
| `elementor-safe-mode.php` | 3rd-party, ignorado pelo Git |
| `hostinger-auto-updates.php` | 3rd-party, ignorado pelo Git |

Para adicionar novo mu-plugin: crie o arquivo e ajuste `.gitignore` se for de terceiros.
