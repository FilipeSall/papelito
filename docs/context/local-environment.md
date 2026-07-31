# Ambiente local

Como subir, o que cada serviço faz e como sair de problemas conhecidos. O passo a passo dos **dois** lados (backend + frontend) está em [`../../../docs/development.md`](../../../docs/development.md); aqui ficam os detalhes só do backend.

## Subir

```bash
cp .env.example .env       # preencha GRAPHQL_JWT_AUTH_SECRET_KEY e GRAPHQL_WOOCOMMERCE_SECRET_KEY
docker compose up -d
bash scripts/local-wordpress-setup.sh
./scripts/validate-wordpress.sh
```

Defina os dois segredos do GraphQL **antes** de subir o container — sem eles a autenticação headless não funciona localmente.

## Serviços

| Serviço | Container | Imagem | URL / porta |
|---|---|---|---|
| `web` | `papelito-web` | build local (`docker/php-apache`, PHP 8.3 + Apache) | http://localhost:8080 |
| `db` | `papelito-db` | `mariadb:10.5` | `127.0.0.1:3307` (fora do Docker); host `db` dentro |
| `phpmyadmin` | `papelito-phpmyadmin` | `phpmyadmin:5-apache` | http://localhost:8081 |
| `mailpit` | `papelito-mailpit` | `axllent/mailpit` | http://localhost:8025 (web) / `1025` (SMTP) |

Admin do WordPress local: `admin` / `admin`. As portas e nomes de container são sobrescrevíveis por variáveis (`WEB_PORT`, `DB_PORT`, `PHPMYADMIN_PORT`, `MAILPIT_WEB_PORT`, `MAILPIT_SMTP_PORT`).

> **O nome do serviço é `web`, não `papelito-web`.** Comandos de compose usam o serviço: `docker compose exec web wp ...`, `docker compose up -d --force-recreate web`. `papelito-web` é o nome do container.

### Mailpit é o que torna o fluxo de e-mail testável

Sem ele, confirmação de cadastro, recuperação de senha e e-mails do WooCommerce simplesmente não têm para onde ir, e o fluxo de verificação de e-mail fica intestável. Todo e-mail disparado localmente aparece em http://localhost:8025.

Se um e-mail não chegar, verifique se algum plugin tem **SMTP próprio configurado** — nesse caso ele passa por fora do Mailpit.

### `local-wordpress-setup.sh`, na ordem

1. espera o banco subir;
2. checa se as tabelas existem;
3. importa o dump se o banco estiver vazio;
4. roda `wp search-replace` para trocar os domínios de produção pelos locais;
5. limpa cache;
6. faz flush de permalinks.

Se o site local ainda estiver mostrando links de produção, rode o script de novo — é o passo 4 que faltou.

## Testar o fluxo B2B

O rollout empresarial fica **desabilitado por padrão, inclusive em produção**. Para exercitar localmente, no `.env` (gitignorado):

```env
PAPELITO_B2B_COMPANY_MODEL_ENABLED=true
PAPELITO_B2B_COMPANY_WRITES_ENABLED=true
```

E recrie **apenas** o container do WordPress:

```bash
docker compose up -d --force-recreate web
```

As flags são repassadas explicitamente pelo `docker-compose.yml`, com default `:-false`. **Não altere `.env.example` para habilitá-las.** Desligue as duas ao terminar o teste.

Lembrete de comportamento: o cadastro cria a **conta pendente**; a empresa só é criada quando o e-mail é confirmado.

> **Uma variável de ambiente nova só chega ao PHP se estiver no bloco `environment:` do serviço `web`.** Preencher o `.env` não basta — `getenv()` volta vazio dentro do container. Esse foi um bug real, silencioso, com as variáveis da Pagar.me.

## Segurança do ambiente local

**O banco local é uma cópia da base do cliente.** Contém dados reais. Este ambiente não deve ser usado para disparar integrações reais: mantenha chaves de teste, `PAPELITO_CORREIOS_PREPOST_MODE` em `mock` ou `disabled`, e o simulador da Pagar.me ativo em vez de chamadas live.

O bootstrap é **fail-closed** nesse ponto: qualquer flag `mock` ou `DEV_*` fora de `WP_ENVIRONMENT_TYPE=local|development` mata a inicialização. **Staging é tratado como produção.**

## Comandos úteis

```bash
docker compose exec web wp <comando>          # WP-CLI
docker compose logs -f web                     # logs do Apache/PHP
composer phpcs                                 # padrões de código
php -l <arquivo>                               # syntax check
php public_html/wp-content/plugins/plugin_papelito/tests/test-<x>.php   # suíte standalone
bash scripts/pull-from-prod.sh                 # sincroniza servidor → repo
./scripts/validate-wordpress.sh                # smoke test do ambiente
```

Cliente de banco externo: host `127.0.0.1`, porta `3307`. Dentro do Docker, host `db` na porta padrão.

Alias opcional: adicionar `papelitobrasil.local` ao `/etc/hosts` apontando para `127.0.0.1`. É opcional — o padrão é `http://localhost:8080` e é o que o `docker-compose.yml` e o `search-replace` assumem.

## Configuração local do WordPress

O `wp-config.php` local lê variáveis de ambiente pelos helpers `papelito_env()` / `papelito_env_bool()`, usa `WP_ENVIRONMENT_TYPE = local`, `WP_CACHE = false` e só aplica `DISALLOW_FILE_EDIT` fora de `local`.

**Em produção o `wp-config.php` é diferente e mantido à mão** — ver [`../operations/pagarme-environment.md`](../operations/pagarme-environment.md#produção-a-incompatibilidade-que-custa-tempo).

## Problemas conhecidos

| Sintoma | Causa e solução |
|---|---|
| Todos os fetches do Next falhando com `status: 0` + `AccessDenied` no Google ao mesmo tempo | o container do WordPress perdeu a rede do Docker. `docker compose up -d` resolve. **Não** é campanha inativa nem CORS. |
| Plugin não consegue escrever log | `chmod -R a+rwX public_html/wp-content/uploads` e `chmod a+rw public_html/wp-content/debug.log` |
| Site mostrando links de produção | rodar `scripts/local-wordpress-setup.sh` de novo |
| E-mail não aparece no Mailpit | plugin com SMTP próprio |
| Variável de ambiente vazia no PHP | falta no bloco `environment:` do serviço `web` |
| PHPCS falha por extensão ausente | o PHP CLI do host não tem `SimpleXML`/`xmlwriter`; rodar via container — ver [context/testing.md](testing.md) |
| WP-CLI "não encontrado" | use `docker compose exec web wp`, ou `php wp-cli.phar` na raiz do repositório |
