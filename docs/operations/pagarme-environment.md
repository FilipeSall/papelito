# Runbook — Ambiente e credenciais da Pagar.me

Mecânica de configuração por ambiente e simulação local. O modelo de negócio, o payload do recebedor e as regras do webhook estão em [`../../../docs/flows/payments.md`](../../../docs/flows/payments.md).

> Nenhum valor real de credencial vive neste documento nem em qualquer outro do repositório. Segredos ficam no `.env` (gitignorado), no cofre e no servidor.

## Mapa: qual variável vai em qual lugar

| Variável | Local | Vercel (front) | Servidor WP |
|---|:---:|:---:|:---:|
| `NEXT_PUBLIC_PAGARME_PUBLIC_KEY` | `.env.local` | ✅ **a única do front** | — |
| `PAGARME_SECRET_KEY` | `.env` + compose | ❌ **nunca** | ✅ |
| `PAGARME_WEBHOOK_USER` | `.env` + compose | ❌ | ✅ |
| `PAGARME_WEBHOOK_PASS` | `.env` + compose | ❌ | ✅ |
| `PAGARME_BASE_URL` | opcional | ❌ | opcional |
| `PAPELITO_PAGARME_SIMULATION_ENABLED` / `_TOKEN` | `.env` + compose | ❌ | ❌ |

**Regra de ouro: a Vercel recebe exatamente uma variável Pagar.me — a public key.** Todo o resto é segredo e vive apenas no WordPress e no ambiente local. O único contato do frontend com a Pagar.me é a tokenização no browser.

## Fatos que economizam tempo

- **A Core API v5 usa a mesma base URL para teste e produção.** O ambiente é definido **pela chave**, não pela URL. **Não existe host de sandbox** — não invente um. `PAGARME_BASE_URL` é escape hatch (default `https://api.pagar.me/core/v5`).
  - Exceção: algumas contas de teste exigem `https://sdx-api.pagar.me/core/v5`. É o único caso legítimo de mudar a variável.
- **A chave live não tem `_live`.** Teste é `sk_test_…` / `pk_test_…`; produção é `sk_<hash>` / `pk_<hash>`. Portanto **"é live?" = "não começa com `sk_test_`"**. Um check por `startsWith('sk_live')` nunca casaria.
- **Public e secret precisam ser do mesmo modo.** `pk_test_` no front exige `sk_test_` no WP. Tokenizar em teste e cobrar em live falha.
- **Não existem** na v5 nem neste projeto: `account_id`, `encryption_key`, `PAGARME_ACCOUNT_ID`, `PAGARME_MARKETPLACE_ID`, `PAGARME_WEBHOOK_SECRET`, `PAGARME_SECRET_KEY_TEST`/`_LIVE`. A v5 usa **Basic Auth** no webhook, não HMAC. O `recipient_id` é **por vendor**, em `wp_usermeta`, não em variável de ambiente.
- `hash_equals()` no webhook evita timing attack. Mantenha.

## Local (Docker)

No `.env`:

```env
PAGARME_SECRET_KEY=sk_test_...
PAGARME_WEBHOOK_USER=papelito
PAGARME_WEBHOOK_PASS=<aleatório, diferente do de produção>
PAGARME_BASE_URL=https://api.pagar.me/core/v5
WP_ENVIRONMENT_TYPE=local
PAPELITO_PAGARME_SIMULATION_ENABLED=true
PAPELITO_PAGARME_SIMULATION_TOKEN=<token local>
```

**E — o passo que já foi esquecido uma vez — repassar ao container** no `docker-compose.yml`, em `services.web.environment:`:

```yaml
    PAGARME_SECRET_KEY: ${PAGARME_SECRET_KEY:-}
    PAGARME_WEBHOOK_USER: ${PAGARME_WEBHOOK_USER:-}
    PAGARME_WEBHOOK_PASS: ${PAGARME_WEBHOOK_PASS:-}
    PAGARME_BASE_URL: ${PAGARME_BASE_URL:-https://api.pagar.me/core/v5}
```

Sem esse bloco, `getenv()` volta vazio dentro do container **mesmo com o `.env` preenchido** — e a falha é silenciosa.

No frontend, `.env.local`: `NEXT_PUBLIC_PAGARME_PUBLIC_KEY=pk_test_...`. Reinicie o `bun run dev`: o Next só lê `NEXT_PUBLIC_*` no boot.

## Produção: a incompatibilidade que custa tempo

O código lê a secret por `papelito_env('PAGARME_SECRET_KEY')`, que internamente usa `getenv()`. Em produção:

| Achado | Consequência |
|---|---|
| O `wp-config.php` de produção usa `define()`, não `papelito_env()`/`getenv()` | `define()` **não** popula `getenv()`. |
| `variables_order = GPCS` (**sem o `E` de Environment**) | `SetEnv` no `.htaccess` **não** funciona. Caminho descartado. |
| Hospedagem compartilhada CloudLinux + CageFS | sem root, sem `systemctl`, sem editar pool do FPM. |
| O diretório home fora do `public_html` é gravável | dá para guardar segredos fora da webroot. |
| O deploy não toca o `wp-config.php` | ele é mantido à mão no servidor. |

**Procedimento**: um arquivo PHP de segredos **fora do `public_html`**, com `chmod 600`, usando **`putenv()`**:

```php
<?php
// Pagar.me — fora da webroot, nunca versionado
putenv('PAGARME_SECRET_KEY=<chave live, sem "_test">');
putenv('PAGARME_WEBHOOK_USER=<usuario>');
putenv('PAGARME_WEBHOOK_PASS=<aleatorio, diferente do de teste>');
// PAGARME_BASE_URL usa o default do código
```

E no `wp-config.php` de produção, **antes** de `require_once ABSPATH . 'wp-settings.php';`:

```php
if ( is_readable( '<caminho do arquivo de segredos>' ) ) {
    require_once '<caminho do arquivo de segredos>';
}
```

**Por que `putenv()` e não `define()`**: o código lê `getenv()`; `putenv()` popula `getenv()`, `define()` não.

Duas armadilhas em sequência:

1. Se `papelito_env()` **não existir** no `wp-config.php` de produção, o plugin dá **fatal error** ao chamar `papelito_pagarme_secret_key()`. Garanta que o helper exista antes de deployar o código que o usa.
2. **Recicle o PHP depois de editar** (hPanel → Reiniciar PHP, ou aguarde a reciclagem do FPM). `getenv()` só vê o que existia quando o worker subiu.

Alternativa: hPanel → Avançado → Variáveis de ambiente PHP — **mas valide antes**, porque `variables_order` sem `E` pode impedir que isso popule `getenv()`.

## Validar sem vazar segredo

```bash
# WordPress local
docker compose exec web php -r 'printf("configured=%s prefix=%s%s",
  getenv("PAGARME_SECRET_KEY") ? "yes":"no",
  substr((string)getenv("PAGARME_SECRET_KEY"),0,7), PHP_EOL);'
# imprime apenas: configured=yes prefix=sk_test

# Webhook
curl -i <url do webhook>                  # espera 401 sem Basic Auth
curl -i -u '<user>:<pass>' <url>          # espera 200
```

**Nunca** `echo $PAGARME_SECRET_KEY`, nunca logar o valor. No frontend, valide só o prefixo (`process.env.NEXT_PUBLIC_PAGARME_PUBLIC_KEY?.slice(0,7)`).

Checagem de sanidade recomendada no runtime (só aviso, sem o valor): `WP_ENVIRONMENT_TYPE=production` com chave `sk_test_` é erro de configuração; `WP_ENVIRONMENT_TYPE=local` com chave que **não** começa com `sk_test_` é perigo — chave live em ambiente local.

## Webhook por ambiente

**Não há WordPress de staging**, e o webhook chega no WordPress (não na Vercel). Portanto:

- o webhook **real** só existe em produção;
- em teste, a validação acontece no checkout/tokenização (front local + chaves de teste no WP local) e no simulador do Dashboard;
- o WordPress local é inacessível para a Pagar.me. Para exercitar ponta a ponta, exponha por túnel (`ngrok http 8080`) e cadastre a URL pública no Dashboard em modo Test.

Eventos a selecionar no Dashboard: `order.*`, `charge.*`, `recipient.updated`.

Com staging ausente, **a reconciliação ativa (`GET /orders/{id}`) é a rede de segurança principal**, não o webhook.

## Simulador local de webhook

`POST /wp-json/papelito/v1/dev/pagarme/simulate-webhook`, em `pagarme_simulator.php`. **Não é mock paralelo**: monta fixtures de evento e chama o **mesmo** processador do webhook real (`papelito_pagarme_process_webhook_payload`).

Guardas: só com `PAPELITO_PAGARME_SIMULATION_ENABLED=true`, **nunca** com `WP_ENVIRONMENT_TYPE=production`, e exige sessão de administrador **ou** o header `X-Papelito-Dev-Token`. Nunca use credenciais live em ambiente local.

```bash
curl -X POST 'http://localhost:8080/wp-json/papelito/v1/dev/pagarme/simulate-webhook' \
  -H 'Content-Type: application/json' \
  -H 'X-Papelito-Dev-Token: <token local>' \
  -d '{"order_id":123,"scenario":"paid"}'
```

| Cenário | Efeito |
|---|---|
| `pending` | mantém o pagamento pendente |
| `paid` | marca como pago/processando |
| `failed` | marca como falho **e libera a reserva de estoque** |
| `expired` | marca como vencido/falho **e libera a reserva** |
| `duplicate` | reprocessa um evento pago, para validar idempotência |

`repeat_count` repete o evento — use para provar que **pedido/cobrança recusados não baixam estoque duas vezes**:

```bash
curl -X POST 'http://localhost:8080/wp-json/papelito/v1/dev/pagarme/simulate-webhook' \
  -H 'Content-Type: application/json' \
  -H 'X-Papelito-Dev-Token: <token local>' \
  -d '{"order_id":123,"scenario":"paid","repeat_count":2}'
```

Roteiro manual de teste local: cartão aprovado → pedido vira `processing`; cartão recusado → **o carrinho permanece preenchido**; boleto com sucesso → carrinho limpo e `/checkout/pagamento/{orderId}` mostrando o boleto pendente.

## Checklist de cutover para produção

1. Desativar `pagarme-payments-for-woocommerce` (`wp plugin deactivate`) — conflito de gateway.
2. Garantir que `papelito_env()` existe no `wp-config.php` de produção.
3. Deployar o código novo do plugin.
4. Criar o arquivo de segredos com `putenv()` e a chave live; reciclar o PHP.
5. Validar com o `php -r` acima: `configured=yes` e prefixo esperado.
6. Recriar os recebedores em modo live (recebedor de teste não vale em produção).
7. Cadastrar o webhook live no Dashboard, com Basic Auth de produção.
8. Smoke test: um pedido real pequeno, depois estornar.
9. Confirmar no DevTools que **nenhuma `sk_`** aparece em Network ou Sources.
10. Confirmar que os logs (debug.log do WP, logs da Vercel) não imprimem chave.

## Pendências

- **Rotacionar as credenciais** (secret key e senha do webhook de produção) — elas passaram por canal de chat.
- Separar chaves test/live por ambiente na Vercel.
- Registrar o webhook live no Dashboard.
- Confirmar com o suporte se existe assinatura HMAC disponível para webhook na v5.
- Matriz sandbox pendente: PIX, boleto, cartão, retry, CNPJ alfanumérico e duas empresas com o mesmo `billing_email` (ver [`../../../docs/flows/payments.md`](../../../docs/flows/payments.md#limitações-e-pendências)).
