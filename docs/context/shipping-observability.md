# Observabilidade de frete — como ler os sinais e o que fazer

Runbook para quem está de plantão no backend. Responde a uma pergunta só: **a
Braspress está quieta porque ninguém cotou, porque a rota não é atendida, porque
a conta parou de autenticar ou porque a transportadora caiu?** Antes disso tudo
chegava ao suporte como "não foi possível cotar o frete".

Os Correios não têm disjuntor e não são afetados por nenhum estado da Braspress.
Se o checkout parou de mostrar **qualquer** frete, o problema não está aqui —
vá para [correios-integration.md](correios-integration.md).

## Os quatro sinais

| Sinal | Onde | Responde |
|---|---|---|
| Contador diário por provider | `papelito_shipping_provider_metrics_report()` | quanto falhou, de quê, e quão lento |
| Log de falha do provider | `error_log`, prefixo `papelito_braspress` | qual vendor, qual categoria, qual `traceId` |
| Alerta de saúde | action `papelito_shipping_provider_alert` + `error_log` prefixo `papelito_shipping_alert` | qual loja entrou ou saiu de estado degradado |
| Auditoria de configuração | tabela `wp_papelito_vendor_integration_audit` | quem mexeu na credencial, quando, e se conseguiu |

**Divisão de trabalho deliberada:** o contador é agregado e **não tem vendor** —
ele responde "quanto" e "de quê". Quem responde "qual loja" é o log, o alerta e a
auditoria. Contador por vendor cresceria com o marketplace e nunca é lido loja a
loja.

## Ler o contador

```bash
docker compose exec -T -u www-data web wp eval \
  'print_r( papelito_shipping_provider_metrics_report() );'

# período fechado
docker compose exec -T -u www-data web wp eval \
  'print_r( papelito_shipping_provider_metrics_report( "2026-09-01", "2026-09-19" ) );'
```

A saída tem duas seções — `quote` e `tracking` — e dentro de cada uma um bloco
por provider:

- `outcomes` — contagem por categoria da taxonomia de
  [`../../../docs/braspress/08-error-handling-and-observability.md`](../../../docs/braspress/08-error-handling-and-observability.md);
- `success`, `failures`, `skipped`, `attempted`;
- `failure_percent` — sobre **tentativas reais**, sem contar `skipped`;
- `latency` — só em `quote`: `count`, `average_ms`, `max_ms` e `buckets`.

Três leituras que enganam se você não souber:

- **`skipped` alto não é incidente.** É a Braspress não participando: flag
  desligada, vendor fora da allowlist, sem integração, sem caixa cadastrada ou
  disjuntor aberto. Todo vendor sem Braspress soma aqui o dia inteiro.
- **`not_available` alto é normal.** É destino fora da malha, e de Bebedouro/SP
  isso inclui Vitória e Vila Velha. **Não alerte por isso.**
- **`tracking` não tem `latency`.** O poll roda em cron e o tempo dele não é
  risco de checkout; a chave simplesmente não existe, em vez de reportar `0 ms`
  como se fosse medição.

Os baldes de latência são um histograma de largura fixa (`250`, `500`, `1000`,
`2500`, `5000`, `10000`, `over`), não percentil exato — percentil exigiria
guardar uma linha por cotação. Para a pergunta operacional real, "quantas
cotações passaram de 5 s", o balde responde. O timeout do cliente é 15 s e cai
em `over`.

O armazenamento é uma option por dia civil, sem autoload, com 400 dias de
retenção — o mesmo padrão de [`shipping_metrics.php`](../../public_html/wp-content/plugins/plugin_papelito/includes/shipping_metrics.php).
A soma é read-modify-write e **pode perder incremento sob concorrência**: o
número serve para tendência, não para conciliação.

## O que fazer em cada categoria

| Categoria | Significa | Ação |
|---|---|---|
| `authentication_error` | `401/403` confirmado | A integração já está em `invalid_credentials` e um alerta já saiu. Peça ao vendor para reemitir usuário/senha em `/vendor/configuracoes`. **Não desabilite a integração** — ela continua `enabled` justamente para o vendor conseguir corrigir. |
| `provider_account_blocked` | `CNPJ PAGANTE BLOQUEADO`, inadimplência | Nenhuma cotação vai funcionar até o vendor resolver com a Braspress. Estado `provider_blocked`. Papelito não tem ação técnica. |
| `not_available` | rota não atendida a partir daquela origem | **Nada.** É resposta de negócio. Se subiu de repente, confira se o vendor mudou o CEP de origem. |
| `configuration_error` | origem, CNPJ do cadastro ou `BRASPRESS_BASE_URL` fora da allowlist | Confira `wp-config.php` e o cadastro do vendor. A Braspress fica inelegível; os Correios seguem. |
| `validation_error` | CEP, peso ou volume recusados antes de chamar | Defeito de dado local. Veja o pacote em [`../../../docs/braspress/`](../../../docs/braspress/README.md); não é a transportadora. |
| `timeout` / `network_error` | limite local de 15 s ou DNS/TLS | Conta para o disjuntor. Se persistir, confirme com a Braspress. Não há retry automático no `POST` de cotação. |
| `rate_limited` | `429` | Conta para o disjuntor. A política de limite da Braspress **segue não publicada** — não invente backoff. |
| `provider_5xx` | falha externa, ou regra de negócio sem causa reconhecida | Conta para o disjuntor. Lembre que a Braspress devolve **HTTP 500 para regra de negócio**; a causa útil está no `errorList` do log. |
| `provider_4xx` | payload recusado (ProblemDetails) | Defeito nosso de contrato. O log traz o campo recusado e o `traceId`, que é o único identificador para abrir chamado na Braspress. |
| `invalid_response` | JSON inválido ou campo crítico ausente | Descartada. Se repetir, é mudança de contrato do provider. |
| `not_found` | só em `tracking`: conhecimento ainda não emitido | Esperado na janela inicial da postagem. Só investigue se ficar dias assim. |

## O disjuntor

Só a Braspress é protegida, e só por vendor. Ele **não** gera erro de checkout:
abrir significa que a Braspress não participa daquela cotação, igual a um vendor
sem integração.

- Abre com **4 falhas seguidas** de `timeout`, `network_error`, `rate_limited`
  ou `provider_5xx`. Qualquer sucesso no meio zera a contagem.
- Fica aberto por **120 s**, depois meia-abre e libera **uma** sonda.
- Sonda boa fecha; sonda ruim reabre e o descanso recomeça.
- `not_available`, `authentication_error` e `validation_error` **não abrem**.
  Rota fora da malha abriria o disjuntor todo dia; credencial recusada já tem
  estado próprio e alerta, e desligar a transportadora esconderia o problema do
  vendor.

Estado e reset manual:

```bash
# ver o estado de um vendor
docker compose exec -T -u www-data web wp eval \
  'echo papelito_shipping_breaker_state( "braspress", 2163 );'

# fechar à força, depois de confirmar que a Braspress voltou
docker compose exec -T -u www-data web wp eval \
  'papelito_shipping_breaker_close( "braspress", 2163 );'
```

## Desligar sem deploy

Ordem de contenção, da menos para a mais agressiva:

1. tirar o vendor de `PAPELITO_BRASPRESS_VENDOR_ALLOWLIST`;
2. `PAPELITO_BRASPRESS_ENABLED=false` — desliga a Braspress inteira.

Em produção o `wp-config.php` é mantido à mão e não vem no deploy, então as duas
valem imediatamente. Nenhuma das duas apaga tabela, pedido ou histórico. Os
Correios não são tocados por nenhuma delas.

## Auditoria de configuração

```sql
SELECT created_at, vendor_id, actor_user_id, action, status
FROM wp_papelito_vendor_integration_audit
WHERE provider = 'braspress'
ORDER BY created_at DESC
LIMIT 50;
```

`action` é o que se **tentou** (`credentials_saved`, `credentials_removed`,
`configuration_saved`, `removed`) e `status` é o que **aconteceu**:

- `success` — gravou;
- `denied` — não podia: loja de outro dono, senha da conta errada, limite de
  escrita. **É a linha que importa em suspeita de invasão**;
- `rejected` — o próprio vendor errou o formulário (CEP inválido, credencial
  pela metade);
- `failed` — defeito nosso (cifragem ou gravação).

A tabela **nunca** guarda a credencial, um prefixo dela nem o seu tamanho. As
linhas anteriores a `PAPELITO_DB_VERSION 1.51.0` recebem `status = 'success'` no
backfill, e isso é correto: até então só o sucesso era auditado.

## O que o log pode conter, e o que nunca contém

O log de falha (`papelito_braspress`) carrega `vendor_id` interno, `operation`,
`duration_ms`, `provider_status`, `category`, `traceId` e até cinco mensagens do
`errorList` **já redigidas** — sequências de 8 ou mais dígitos viram
`[redacted]`, e usuário, senha e o Basic em base64 são substituídos antes de
sair.

Nunca aparecem, em log, métrica, alerta ou auditoria: senha, cabeçalho
`Authorization`, CNPJ completo, CPF, endereço, CEP ou corpo bruto do provider.
Isso é coberto por teste negativo, não por inspeção — veja
`test-shipping-provider-observability.php` (cenário 3),
`test-braspress-credential-alert.php` (cenário 2) e
`test-vendor-integration-audit.php` (cenário 5). **Se você precisar acrescentar
um campo a qualquer um desses canais, acrescente junto a asserção de que ele não
vaza.**

## Onde plugar alerta de verdade

Hoje o alerta é uma action e uma linha de log; não há integrador externo. Para
mandar para um canal:

```php
add_action(
	'papelito_shipping_provider_alert',
	function ( string $provider, string $state, array $context ): void {
		// $context traz apenas vendor_id, previous_state e error_category.
	},
	10,
	3
);
```

O alerta sai **na transição** de saúde, não a cada cotação: uma credencial
vencida alertaria uma vez por checkout e o canal viraria ruído em uma tarde. A
recuperação também é publicada, para fechar o alerta aberto.

## Pendências conhecidas

- **Não há coleta externa.** Os números vivem em `wp_options` e são lidos por
  WP-CLI. Não existe rota REST nem exportador.
- **Latência de tracking não é medida.**
- **O vocabulário de status da Braspress segue não confirmado**, então
  `provider_5xx` continua absorvendo causa de negócio que não casa com os
  padrões conhecidos de `errorList`.
