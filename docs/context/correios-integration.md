# Integração com os Correios — lado do backend

Contrato do adapter, modos de operação, polling e credenciais. O fluxo funcional completo (incluindo o comportamento vigente de postagem manual e o catálogo de erros espelhado em TypeScript) está em [`../../../docs/flows/shipping-and-tracking.md`](../../../docs/flows/shipping-and-tracking.md).

## Credenciais

**Sempre no servidor. Nunca envie ao Next.js ou ao navegador.**

| Variável | Uso |
|---|---|
| `PAPELITO_CORREIOS_USERNAME` | autenticação CWS |
| `PAPELITO_CORREIOS_ACCESS_CODE` | autenticação CWS |
| `PAPELITO_CORREIOS_POSTING_CARD` | cartão de postagem — **validado contra `^00\d{8}$` antes de qualquer chamada** |
| `PAPELITO_CORREIOS_CONTRACT` | contrato |
| `PAPELITO_CORREIOS_ENV` | `staging` ou `production` |

O token Bearer é obtido em `token/v1/autentica/cartaopostagem` e cacheado em transient até perto da expiração. A lista de serviços do contrato também é cacheada.

Erros dos Correios podem propagar `correios_status` e `correios_message` em `data`, mas **nunca** token, Basic Auth ou access code.

## Cotação — `POST /papelito/v1/shipping/quote`

Payload `{ vendor_id, destination_cep, items: [{ product_id, qty }] }`. O backend:

- valida que `vendor_id` pertence a um usuário com role `seller` e que o vendor tem CEP de origem válido;
- lê peso e dimensões do WooCommerce;
- filtra PAC/SEDEX disponíveis no contrato;
- devolve `[{ service, code, name, price, delivery_time }]`;
- em `429`, respeita `Retry-After` quando presente e faz **uma única** nova tentativa curta;
- se PAC e SEDEX falharem, **preserva o contexto da primeira falha útil** em `data.correios_status` / `data.correios_message` em vez de perdê-lo.

Produto sem peso ou dimensões cadastrados gera erro claro de cadastro incompleto (`422`).

## Modos de pré-postagem

`PAPELITO_CORREIOS_PREPOST_MODE = disabled | mock | real`

| Modo | Comportamento |
|---|---|
| `disabled` | nenhuma geração. O fluxo vigente é o registro manual de S10 pelo vendor. |
| `mock` | registrado **somente** em `WP_ENVIRONMENT_TYPE=local|development`. Gera S10 estruturalmente válido com `is_test=1` e PDF marcado `SEM VALIDADE POSTAL`. Nunca entra no polling real. IDs com prefixo `MOCK-*`. |
| `real` | **fail-closed** até existirem: provider registrado, contrato com o serviço `86720 — API PRE POSTAGEM` confirmado, e o schema vigente exportado do CWS autenticado. |

**O manual público não contém o schema completo de criação — por isso nenhum campo foi presumido no plugin.** Essa é a razão pela qual o modo `real` não está implementado, não falta de tempo.

Staging e produção **bloqueiam todas as flags `DEV_*`**: staging é tratado como produção.

`is_test=1` só volta a `0` nunca: é gravado na criação da remessa ou, localmente, pelo `simulate` (abaixo). O polling real ignora toda remessa com `is_test=1`; o PDF simulado exige `provider=mock` **e** `is_test=1`; fixtures de rastreamento aceitam `provider IN (mock, manual)` apenas com `is_test=1`. Nenhum campo isolado promove um registro ao fluxo mock.

### Cenários locais determinísticos

| Variável | Valores |
|---|---|
| `PAPELITO_CORREIOS_DEV_HEALTH_SOURCE` | `mock` \| `real` |
| `PAPELITO_CORREIOS_DEV_HEALTH_SCENARIO` | `healthy` \| `unhealthy` \| `unknown` |
| `PAPELITO_CORREIOS_DEV_TRACKING_SCENARIO` | `preposted` \| `posted` \| `in_transit` \| `delivered` \| `cancelled` \| `expired` |

A fonte de saúde real é **opt-in**, consulta apenas autenticação e o serviço `86720`, usa **cache de 15 minutos e não faz retry**, com credenciais redigidas. A chave de cache inclui ambiente, fonte, cenário, contrato, cartão e uma **impressão HMAC não reversível** da credencial. `healthy` exige autenticação válida **e** o serviço confirmado; falha confirmada é `unhealthy`; timeout, `429` ou `5xx` é `unknown`.

Localmente, como **nenhum POST de criação real é executado**, até `unknown` abre o fallback manual. É relaxação exclusiva de desenvolvimento.

### Simular rastreamento pelo WP-CLI

Avança o rastreamento de um pedido local sem chamar os Correios, pelo mesmo processador de eventos do polling. Só existe no WP-CLI e recusa qualquer ambiente diferente de `local` ou `development`.

```bash
bash scripts/simulate-delivery.sh 123                  # até entregue
bash scripts/simulate-delivery.sh 123 in_transit       # até em trânsito
bash scripts/simulate-delivery.sh 123 --dry-run        # só mostra, não grava
```

O script é atalho para `docker compose exec -T web wp --allow-root papelito tracking simulate <pedido> [estado]`. O estado final é `posted`, `in_transit`, `out_for_delivery` ou `delivered` (padrão), e o comando grava todos os eventos até ele, nesta ordem: `PO/01`, `RO/01`, `OEC/03`, `BDE/01`. Cada evento tem origem `local_simulation` em `wp_papelito_tracking_events`; não há chamada HTTP externa.

Serve para qualquer remessa de saída ativa do pedido, gerada em modo `mock` ou registrada com rastreio manual. Remessa com `is_test=0` é marcada como teste (`is_test=1`, `next_poll_at` nulo) antes de gravar, para o polling real nunca consultá-la. O comando não cria remessa: sem etiqueta gerada ou rastreio registrado no painel do vendor, ele recusa. Com mais de uma remessa, aplica a todas; `--shipment=<id>` limita a uma. `--at=<ISO-8601>` fixa a data-base dos eventos; sem ela, vale a criação da remessa. Repetir o comando é idempotente.

O status operacional só chega a `entregue` quando todas as remessas ativas do pedido foram entregues. Como o comando chama `papelito_tracking_ingest_event()`, mantém evento bruto, projeção, nota do pedido e notificações como no fluxo normal. O status WooCommerce do pedido não muda.

## Contrato do adapter

`POST /papelito/v1/vendor/me/orders/{id}/shipments` aplica o filtro `papelito_correios_generate_prepostage`, que deve devolver exatamente:

```php
array(
	'prepost_id'    => 'identificador retornado pelos Correios',
	'tracking_code' => 'AA123456789BR',
	'service_code'  => 'codigo contratado',
)
```

**O adapter não recebe payload do frontend.** Ele monta tudo a partir do pedido, dos itens e do cadastro validado do vendor. Sequência esperada:

1. criar a pré-postagem com o schema oficial vigente;
2. solicitar o rótulo com as mesmas credenciais;
3. consultar a pré-postagem e obter o código atribuído;
4. devolver apenas os três campos acima ao núcleo de rastreamento.

Internamente o provider precisa expor `health`, `create`, `get_or_regenerate_label`, `reconcile` e `cancel`.

O `prepost_id` fica deliberadamente **fora do read model público**. O PDF é exposto só por endpoint autorizado, armazenado **fora do webroot** com chave opaca e checksum, nunca na biblioteca de mídia.

### Idempotência

Hoje a chave de geração é `hash( 'sha256', 'shipment-v1|order|vendor|provider|service' )`, **reservada antes** de chamar o provider. Ela não tem componente de pacote/volume e, por isso, colide se o mesmo pedido/vendor/provider/serviço tiver vários volumes. Timeout vira `uncertain` e precisa de reconciliação — **timeout não provoca retry cego**.

O snapshot resolve a tentativa canônica por essa chave, **não por "último ID"**. Duplicatas e replays nunca substituem a tentativa canônica.

As rotas de escrita do vendor recusam uma segunda remessa ativa com `409 papelito_tracking_shipment_exists`. Somente a rota administrativa de migração cria múltiplas linhas e não usa idempotência. A inclusão de identidade por volume e o relaxamento controlado desse bloqueio são pré-requisitos de trabalho futuro para multi-volume; multi-volume não está implementado.

## Polling do rastreamento

`GET /srorastro/v1/objetos/{codigoObjeto}?resultado=T`.

- Hook `papelito_correios_tracking_poll_due`, **a cada 5 minutos** pelo Action Scheduler, com WP-Cron como fallback.
- Até **100** remessas por execução (filtrável entre 1 e 500).
- Reconsulta em **10 min** se saiu para entrega, **30 min** nos demais estados.
- Falha usa backoff exponencial até **6 horas**.

> Em produção, configure um cron real que acione o WordPress. Não dependa de tráfego HTTP para disparar WP-Cron — remessa sem visita fica sem polling.

Mapa de eventos padrão, contendo **apenas** combinações demonstradas no manual oficial público:

| Evento | Estado |
|---|---|
| `PO/01` | postado |
| `RO/01` | em trânsito |
| `OEC/03` | saiu para entrega |
| `BDE/01` | entregue |

Evento desconhecido é **armazenado sem alterar estado**. Para acrescentar combinações depois de obter a tabela oficial do contrato, use o filtro `papelito_correios_tracking_event_map` com os estados internos já previstos: `pickup_available`, `delivery_failed`, `returning`, `returned`, `lost`. **Nunca classifique genericamente um evento terminal como entrega.**

**Somente `BDE/01` conclui entrega**, e o pedido só projeta `entregue` quando todas as remessas de saída ativas estão entregues.

## Webhook: por que não existe

Não está habilitado, e só deve ser adicionado depois de os Correios fornecerem, **para o contrato**, a especificação oficial de autenticação, payload, reenvio e validação de origem. **O polling continua sendo a reconciliação obrigatória mesmo em um futuro modelo híbrido** — o webhook seria aceleração, não substituição.

## Auditoria e endpoints administrativos

```
GET /papelito/v1/admin/tracking/health              contadores de pendências, erros e remessas estagnadas
GET /papelito/v1/admin/orders/{id}/tracking-events  histórico bruto do pedido
POST /papelito/v1/admin/orders/{id}/shipments/manual-release
```

O endpoint administrativo de remessa (`manage_woocommerce`) existe para migração e suporte auditado, não como caminho normal.

Invariantes de auditoria: evento duplicado é recusado por chave única; evento antigo permanece auditável mas **não regride a projeção**; **um S10 não pode estar em dois pedidos**; pedido cancelado ou reembolsado não é reclassificado comercialmente por evento posterior, ainda que o evento seja registrado.

## Dados mutáveis que o adapter real não pode usar cegamente

O pacote de envio relê o produto e o documento relê o perfil no momento do uso. Esses dados **mudam**. Antes de ligar o modo real, é pré-requisito ter snapshot de peso, dimensões, valor, itens e documento do destinatário — do contrário a etiqueta pode ser gerada com dados diferentes dos do pedido.

## Observabilidade exigida antes do modo real

Correlation ID, logs estruturados com redação, contadores por provider e categoria de erro, circuit breaker. **Nunca registrar token, documento, endereço integral ou PDF.** Rollback desabilita a criação nova por feature flag mas **mantém a reconciliação** do que já existe.

Em modo mock, um teste de CI deve **proibir** que o host `api.correios.com.br` seja alcançado.

## Legado

Existe em disco o plugin `woocommerce-correios` (Claudio Sanches 4.2.5), fora do pipeline headless, e postmeta `_correios_tracking_code` vindo dele. O dump de produção contém códigos legados **incluindo entradas de teste inválidas** — migração exige validação, não importação em massa como S10 válido.

## Estado e bloqueios

Ver [`../operations/correios-diagnostics.md`](../operations/correios-diagnostics.md) para o diagnóstico da chave, os bloqueios abertos e as alternativas comerciais avaliadas.
