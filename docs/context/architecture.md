# Arquitetura do backend

## Stack

| Peça | Papel |
|---|---|
| WordPress 6.9 (PHP 8.3) | fonte de verdade de `wp_users`, produtos, pedidos |
| WooCommerce | carrinho, checkout, pedidos |
| WPGraphQL | schema GraphQL em `/graphql` |
| `wp-graphql-jwt-authentication` | auth headless (`login`, `refreshJwtAuthToken`) |
| `wp-graphql-woocommerce` | `customer`, `cart`, pedidos |
| **`plugin_papelito`** | **todas as regras de negócio do marketplace** |
| mu-plugins `papelito-*` | CORS e hardening |
| Docker Compose | ambiente local |
| Composer + PHPCS | padrões de código e CI |
| Hostinger Business (SSH) | hospedagem |

`/wp-admin` é restrito a administradores. O site público é o Next.js.

## Layout

```
public_html/
  wp-config.php                    config por ambiente (helpers papelito_env)
  wp-config.example.php            referência versionada; a real fica fora do Git
  wp-content/
    mu-plugins/
      papelito-cors.php            nossos
      papelito-hardening.php       nossos
      elementor-safe-mode.php      terceiros, gitignorados
      hostinger-auto-updates.php   terceiros, gitignorados
    plugins/plugin_papelito/       código de domínio
    themes/jupiterx-child/         legado, ver context/legacy-stack-removal.md
docker/                            Dockerfile + scripts de dev
db/                                dump local (gitignorado)
docs/                              esta documentação
scripts/                           deploy, sync, setup local, diagnóstico
stubs/  tests/                     apoio a testes
artifacts/  _pulled/               saídas locais (gitignorados)
composer.json  phpcs.xml.dist
```

**Regra de responsabilidade, com o motivo**: `plugin_papelito` é dono de hooks, metadados de usuário, lógica de CEP/frete e de todo comportamento que **precisa sobreviver à troca de tema**. Foi essa separação que tornou a migração headless possível. Nada de regra de negócio no tema.

## `plugin_papelito/includes/` por domínio

54 arquivos. Agrupados pelo que fazem — a lista alfabética não ajuda ninguém.

### Infraestrutura compartilhada

| Arquivo | Responsabilidade |
|---|---|
| `private_files.php` | validação e armazenamento de arquivo privado fora do webroot, parametrizado por spec |

O chamador passa um spec (`code_prefix`, `max_bytes`, `formats`, `fallback_basename`) e recebe de volta os códigos de erro no seu próprio prefixo. A validação confere **conteúdo**, não extensão: `finfo`, cruzamento extensão↔MIME, magic bytes e `wp_check_filetype_and_ext`. O armazenamento usa nome de 64 hex aleatórios, `0700` no diretório e `0600` no arquivo, e **recusa qualquer diretório dentro do webroot** — não existe fallback para `uploads/`.

**Retenção não é responsabilidade deste módulo.** Cada chamador decide: a candidatura de titularidade purga o documento após a decisão, em `company_owner_applications.php`.

### Autenticação e identidade

| Arquivo | Responsabilidade |
|---|---|
| `auth_endpoints.php` | registro, verificação de e-mail, Google OAuth, recuperação/troca de senha, invalidação de sessão e `/auth/me` |
| `user_registration.php` | hooks do WooCommerce: campos extras e validação BR no cadastro clássico |
| `customer_identity.php` | criptografia/HMAC de CPF + repositório de perfis de customer |

### Modelo de empresa (B2B)

| Arquivo | Responsabilidade |
|---|---|
| `company_schema.php` | DDL de todas as tabelas B2B via `dbDelta` |
| `company_repository.php` | acesso typed a companies, members, invitations, audit |
| `company_services.php` | criação e ciclo de vida da empresa |
| `company_onboarding.php` | linha de onboarding retomável |
| `company_owner_applications.php` | candidatura do responsável e análise documental |
| `company_authz.php` | matriz RBAC; recarrega empresa + membership a cada mutação |
| `company_active_context.php` | coorte B2B sticky e empresa ativa persistida |
| `company_membership_services.php` | papel, suspensão, revogação, transferência de titularidade |
| `company_invitation_services.php` | convites |
| `company_access_request_services.php` | solicitações de acesso e anti-enumeração |
| `company_idempotency.php` | idempotência durável compartilhada |
| `company_endpoints.php`, `company_management_endpoints.php`, `company_admin_endpoints.php` | rotas REST |
| `company_flags.php` | leitura das feature flags |
| `company_final_check.php` | comando WP-CLI de saneamento local |
| `cnpj_validation.php` | validadores de CPF/CNPJ/CEP — **fonte autoritativa** |
| `cnpj_providers.php` | adapters BrasilAPI / CNPJ.ws / ReceitaWS com contrato normalizado |
| `legacy_migration.php` | coorte pré-B2B, campanhas, WP-CLI |

### Catálogo, cobertura e estoque

| Arquivo | Responsabilidade |
|---|---|
| `rest_api.php` | `/cep`, `/sellers-by-cep`, `/coverage`, `/coverage/products` e a query GraphQL `sellersByCep` |
| `products_filter.php` | filtro de produtos por CEP |
| `vendor_geo.php` | geocodificação de CEP e haversine |
| `vendor_stock.php` | estoque por vendor, log e a query do painel |
| `active_vendor.php` | vendor ativo do comprador |
| `favorites.php` | favoritos |
| `flash_sale.php` | campanha de flash sale |
| `coupons.php` | motor de cupons sobre `shop_coupon` |
| `pricing.php` | `/cart/pricing` |
| `catalog-pdf.php` | catálogo em PDF administrável |
| `home_assets.php` | banners e assets da home, incluindo o corredor "Explore por coleção" (`papelito_home_collections_nav`) |
| `product_benefits.php` | benefícios da página de produto: schema, resolução por escopo e seed do grupo global |
| `product_benefits_rest.php` | rota pública `/products/{id}/benefits` e CRUD admin de `/admin/benefit-groups` |
| `product_sku.php` | geração imutável de SKU para produtos/variações, backfill admin e comando WP-CLI |
| `media_uploads.php` | apoio a upload |

### Checkout, pagamento e frete

| Arquivo | Responsabilidade |
|---|---|
| `order_routing.php` | `/checkout/place-order`: valida, resolve vendor, cria pedido, reserva estoque, chama pagamento |
| `pagarme_client.php` | wrapper HTTP com Basic Auth e `Idempotency-Key` |
| `pagarme_recipients.php` | recebedor do vendor (KYC) |
| `pagarme_payments.php` | montagem do `POST /orders` |
| `pagarme_webhook.php` | webhook + reconciliação ativa |
| `pagarme_simulator.php` | simulação de webhook fora de produção |
| `shipping.php`, `shipping_providers.php` | cotação autenticada e orquestração isolada de providers |
| `shipping_metrics.php` | contador diário de resíduo de embalagem: origem da medida por cotação e recusa por código de erro |
| `shipping_observability.php` | contador diário de desfecho e latência por provider, em cotação e rastreio, e o alerta de saúde da integração |
| `shipping_breaker.php` | disjuntor da Braspress por vendor: quatro falhas seguidas de indisponibilidade tiram o provider da cotação por 120 s, sem tocar nos Correios |
| `vendor_integrations.php`, `braspress.php` | contrato Braspress por vendor, cofre write-only e cliente HTTP de cotação |
| `vendor_secrets.php` | cofre das credenciais de transportadora do vendor: chave derivada da de PII por HMAC com rótulo de domínio, envelope `k<versão>`, sem variável de ambiente própria |
| `correios_prepostage.php` | pré-postagem e etiqueta |
| `correios_tracking.php`, `braspress_tracking.php` | polling por provider; Rastro/S10 e Braspress `byNumPedido` com status externo preservado |
| `receipts.php` | recibo persistido: numeração anual, snapshot imutável em centavos, parcelas por vendor |
| `receipts_backfill.php` | backfill em lotes dos pedidos pagos antes do recibo existir, com checkpoint e WP-CLI |
| `fiscal_documents.php` | nota fiscal como arquivo indexado: schema, armazenamento privado, trilha |
| `fiscal_documents_rest.php` | rotas da nota do vendor e do comprador, e a transação que substitui sem deixar órfão |
| `fiscal_documents_cleanup.php` | exclusão em cascata (pedido, vendor) e `wp papelito fiscal sweep` |
| `order_receipt.php` | recibo interno em PDF |

O recibo tem duas camadas com responsabilidades distintas. `receipts.php` **grava** o documento no momento em que o pagamento é confirmado, congelando valores e itens; `order_receipt.php` **renderiza** o PDF sob demanda, lendo `papelito_receipts` + `papelito_receipt_vendor_parts`. A renderização é separada em duas etapas: `papelito_receipt_document()` monta o conteúdo já rotulado e formatado, e `papelito_receipt_pdf()` o diagrama em A4 com primitivas próprias (retângulo, fio, losango, texto alinhado por métrica de Helvetica) — sem dependência externa de PDF. **Nada financeiro, identificador de compra ou data vem do `WC_Order` ao vivo** — dele só sai a situação operacional, que é informativa. Pedido pago sem linha de recibo emite de forma idempotente durante a geração; sem recibo possível, a rota devolve `papelito_receipt_unavailable` (409), nunca um fatal.

`papelito_receipt_issue_for_order()` é idempotente por `order_id` e recusa pedido que `papelito_pagarme_payment_state_is_paid()` não aprove. A numeração `PPL-AAAA-NNNNNN` sai de `papelito_receipt_sequences`, com `SELECT ... FOR UPDATE` na linha do ano dentro da mesma transação que grava o recibo — **nunca `MAX(id)+1`, `get_option` ou contador em memória**. A soma das parcelas por vendor bate exatamente com o total do recibo: o frete é repartido por `papelito_receipt_allocate_cents()`, que dá o resto à última parcela.

### Vendor e operação

| Arquivo | Responsabilidade |
|---|---|
| `revendedor_application.php` | candidatura de vendor, aprovação, criação direta pelo admin |
| `vendor_dashboard.php` | KPIs, pedidos, configurações, faixas de cobertura; e as regras canônicas de venda paga, desconto e reembolso |
| `vendor_reports.php` | exportações do vendor, escopadas pela sessão |
| `vendor_interests.php` | manifestações de interesse |
| `vendor_messaging.php` | threads comprador ↔ vendor |
| `vendor_processing_alerts.php` | alertas de separação |
| `support.php` | apoio a suporte |

### Administração e notificações

| Arquivo | Responsabilidade |
|---|---|
| `admin_users.php` | listagem, detalhe, papel, ativação de e-mail, cancelamento de pedido |
| `account_status.php` | estado comercial da conta (ativa/suspensa), guards, histórico e contexto de `/auth/me` |
| `account_admin_endpoints.php` | rotas de suspender/reativar conta e empresa, e histórico de estado |
| `admin_reports.php` | snapshot de vendas com segmento e janela anterior, relatórios e exportações |
| `notifications.php` | dispatcher **e todos os listeners** dos eventos de domínio |
| `notification_emails.php` | camada de apresentação dos e-mails transacionais: casca da marca, chapas, botão e os corpos HTML/texto. Não conhece hook nem evento |

## Barramento de eventos

Eventos entre domínios usam `do_action()`. **Os listeners ficam centralizados em `notifications.php`** — quem emite não conhece quem consome. Não espalhe `add_action` de notificação pelos módulos de domínio.

| Action | Emitido em | Payload |
|---|---|---|
| `papelito_stock_zeroed` | `papelito_set_vendor_stock` e decremento por pedido | `$vendor_id, $product_id` |
| `papelito_vendor_stock_changed` | qualquer alteração de estoque | invalida o cache de cobertura |
| `papelito_vendor_application_submitted` | submissão de candidatura | `$user_id` |
| `papelito_vendor_approved` | aprovação | `$user_id` |
| `papelito_vendor_rejected` | rejeição | `$user_id, $reason` |
| `papelito_product_on_promo` | publicação de cupom restrito e ativação de flash sale | `$product_id, $context` |
| `papelito_active_vendor_changed` | troca de vendor ativo | `$user_id, $prev, $new` |
| `papelito_order_payment_confirmed` | `papelito_pagarme_apply_order_state`, **depois** de `$order->save()` persistir o estado pago | `$order, $state` |
| `papelito_shipping_package_built` | `papelito_shipping_notify_package_built()`, nos **dois** caminhos de embalagem | `$measurement_source` (`profile` \| `legacy_synthetic` \| `kit_declared`) |
| `papelito_shipping_package_rejected` | mesmo emissor, quando o pacote não é aprovado | `$error` (`WP_Error`) |
| `papelito_shipping_provider_quote_result` | `papelito_shipping_observe_provider_quote()`, uma vez por provider por cotação | `$provider, $result, $duration_ms, $vendor_id` |
| `papelito_tracking_poll_result` | `papelito_tracking_publish_poll_result()`, no agendamento do próximo poll | `$provider, $failed, $error_code` |
| `papelito_shipping_provider_alert` | `papelito_shipping_provider_alert()`, só na **transição** de saúde da integração | `$provider, $state, $context` (allowlist: `vendor_id`, `previous_state`, `error_category`) |

> As actions de embalagem e as de observabilidade são as únicas cujo listener **não** vive em
> `notifications.php`: quem escuta é `shipping_metrics.php`, `shipping_observability.php` e
> `shipping_breaker.php`, e contador não é notificação. A centralização vale para notificação e
> e-mail, não para observabilidade.

> `papelito_shipping_provider_quote_result` publica o resultado **bruto** do provider, inclusive
> `WP_Error`. Quem escuta é que reduz ao vocabulário fechado e descarta mensagem e `data` — é lá que
> o dado do comprador para. Listener novo herda essa obrigação.

> `papelito_order_payment_confirmed` é **reentrante por desenho**: webhook repetido reemite o evento. Todo consumidor precisa ser idempotente. É o gatilho da emissão do recibo (`receipts.php`), que é idempotente por `order_id`.

Filtros de extensão:

| Filtro | Para quê |
|---|---|
| `papelito_correios_generate_prepostage` | registrar o adapter de pré-postagem |
| `papelito_correios_tracking_event_map` | acrescentar combinações de evento dos Correios |
| `papelito_should_dispatch_notification` | suprimir uma notificação |

## mu-plugins

| Arquivo | O que faz | Versionado |
|---|---|---|
| `papelito-cors.php` | allowlist de `PAPELITO_ALLOWED_ORIGINS`; headers em `rest_pre_serve_request` e `graphql_init`; trata OPTIONS; permite `Authorization`, `Content-Type`, `X-WP-Nonce`; default `http://localhost:3000` | sim |
| `papelito-hardening.php` | bloqueia enumeração de usuários, desativa XML-RPC, remove o generator, rate limit no login, fecha o cadastro público (`users_can_register` forçado a `0`; registro no checkout do Woo desligado; `registerUser`, `registerCustomer` e `checkout` fora do schema GraphQL) | sim |
| `elementor-safe-mode.php` | terceiros | **não** |
| `hostinger-auto-updates.php` | terceiros | **não** |

mu-plugins carregam automaticamente e **não podem ser desativados pela interface**. Para adicionar um novo: crie o arquivo e ajuste o `.gitignore` se for de terceiros.

## Configuração por ambiente

`wp-config.php` lê variáveis pelos helpers `papelito_env()` / `papelito_env_bool()` e força `DISALLOW_FILE_EDIT` fora de `local`.

| Variável | Obrigatória | Descrição |
|---|---|---|
| `GRAPHQL_JWT_AUTH_SECRET_KEY` | sim | assina os JWTs |
| `GRAPHQL_WOOCOMMERCE_SECRET_KEY` | sim | sessão/carrinho do WooGraphQL |
| `WP_ENVIRONMENT_TYPE` | sim | `local` / `development` / `staging` / `production` — controla o hardening |
| `PAPELITO_GOOGLE_CLIENT_ID` | se Google OAuth | `aud` esperado; **mesmo valor** de `GOOGLE_CLIENT_ID` no front |
| `PAPELITO_ALLOWED_ORIGINS` | recomendado | CSV de origins do CORS |
| `PAPELITO_FRONTEND_URL` | produção | URL do front; ver [operations/deploy.md](../operations/deploy.md) |
| `COOKIE_DOMAIN` | produção | domínio do cookie |
| `PAPELITO_FRONT_PROXY_TOKEN` | recomendado em produção | segredo compartilhado com o Next; permite rate limit de frete por comprador sem confiar em header público |
| `PAGARME_SECRET_KEY`, `PAGARME_WEBHOOK_USER`, `PAGARME_WEBHOOK_PASS`, `PAGARME_BASE_URL` | quando pagamento ligado | ver [operations/pagarme-environment.md](../operations/pagarme-environment.md) |
| `GA4_MEASUREMENT_ID`, `GA4_API_SECRET` | quando a atribuição de campanha estiver ligada | envio do `purchase` ao GA4 na confirmação do pagamento. Sem elas o pedido segue normal e nenhum evento é enviado. Ver [analytics-and-attribution.md](../../../docs/analytics-and-attribution.md) |
| `PAPELITO_PAGARME_SIMULATION_ENABLED`, `PAPELITO_PAGARME_SIMULATION_TOKEN` | só local/teste | simulador de webhook |
| `PAPELITO_CORREIOS_*` | frete | ver [context/correios-integration.md](correios-integration.md) |
| `PAPELITO_PII_LOOKUP_KEY`, `PAPELITO_PII_ENCRYPTION_KEY`, `PAPELITO_PII_KEY_VERSION` | B2B | ver [context/data-model.md](data-model.md#criptografia-de-pii) |
| `PAPELITO_CNPJWS_TOKEN`, `PAPELITO_RECEITAWS_TOKEN` | opcional | provedores de CNPJ |
| `PAPELITO_PRIVATE_COMPANY_DOCUMENTS_DIR` | análise documental | default fora do webroot |
| `PAPELITO_PRIVATE_FISCAL_DOCUMENTS_DIR` | notas anexadas pelo vendor | default fora do webroot; **sem fallback público** |
| `PAPELITO_B2B_*`, `PAPELITO_COMPANY_*`, `PAPELITO_QSA_*`, `PAPELITO_ALPHANUMERIC_CNPJ_*` | flags | ver [`../../../docs/architecture.md`](../../../docs/architecture.md#feature-flags) |

### CORS — o que o mu-plugin realmente garante

Auditado em 16/09/2026. `mu-plugins/papelito-cors.php` é a única autoridade de CORS e vale para REST
e WPGraphQL.

- A allowlist é **comparação exata** (`in_array` estrito) contra `PAPELITO_ALLOWED_ORIGINS`. Não há
  curinga, prefixo nem regex, então `papelito.com.attacker.test` não passa.
- O plugin **remove** `rest_send_cors_headers()` do core (prioridade 11 em `rest_api_init`), que
  reflete qualquer `Origin`, e apaga o `Access-Control-Allow-Origin: *` do WPGraphQL.
- `Access-Control-Allow-Credentials: true` só acompanha uma origem específica, nunca `*`.
- `Vary: Origin` é enviado sem sobrescrever outros `Vary`, o que mantém o cache correto.
- Sem a constante `PAPELITO_ALLOWED_ORIGINS` definida no `wp-config.php`, a allowlist fica vazia e
  **nenhum** cabeçalho CORS é emitido — o sintoma é `fetch` do navegador falhando, não erro do REST.

Duas armadilhas conhecidas:

- **`PATCH` não está em `Access-Control-Allow-Methods`** (só `GET, POST, PUT, DELETE, OPTIONS`).
  Hoje isso não quebra nada porque as rotas `PATCH` são chamadas pelo proxy do Next, servidor a
  servidor, sem CORS. No dia em que o navegador chamar uma delas direto, o preflight falha.
- **`http://localhost:3000` fica na allowlist de produção de propósito**, para desenvolvimento
  contra o backend real. É uma decisão consciente, registrada em
  [`../../../docs/integration-contracts.md`](../../../docs/integration-contracts.md), mas significa
  que uma página servida em `localhost:3000` pode fazer requisição com credencial ao WP de produção.

> **Em produção o `wp-config.php` é mantido à mão no servidor** — o deploy faz rsync de `themes/` e `plugins/` e **não** toca nele. Uma variável nova exige edição manual lá, e o `wp-config.example.php` do repositório precisa ser atualizado no mesmo movimento (ver [operations/sync-from-prod.md](../operations/sync-from-prod.md)).

## Convenções PHP

- **PHPCS com os WordPress coding standards** (`phpcs.xml.dist`). O baseline aceito está em [context/testing.md](testing.md#baseline-de-phpcs).
- **Sanitizar sempre**: `sanitize_text_field`, `sanitize_email`, `wp_kses_post`; escapar na saída com `esc_*`. Nunca confiar em `$_POST` / `$_GET` direto.
  - Exceção documentada: senha temporária de vendor **não** passa por `sanitize_text_field` — corromperia caracteres válidos. Ver [`../../../docs/flows/authentication.md`](../../../docs/flows/authentication.md#senha-temporária-de-vendor).
- **Sem ternário aninhado** (`php:S3358`). Leitura de configuração em cascata (constante → `papelito_env()` → `getenv()`) é uma função com `return` antecipado, não um ternário dentro do outro — `papelito_shipping_provider_config()` é a que existe. Ternário no meio da lista de argumentos de outro ternário vira variável nomeada antes da chamada.
- **Complexidade cognitiva de até 15 por função** (`php:S3776`). O callback REST é gate, delegação e resposta; parsing do corpo, resolução de preço e transformação de linha viram funções nomeadas com o prefixo do módulo. Closure passada a `array_map`/`array_filter` soma aninhamento a cada condicional interna — extraia a closure e passe o nome da função como callable. `papelito_shipping_quote_endpoint()` decomposto em `papelito_shipping_quote_request_input()`, `papelito_shipping_quote_pricing_context()` e `papelito_shipping_quote_declared_line()` é o desenho de referência.
- `current_user_can()` dentro do endpoint é a autorização real, não o `permission_callback` isolado.
- Rotas REST públicas que executem trabalho caro, chamem provedores externos, sejam abusáveis ou mutem estado exigem rate limit via transient. Leituras pequenas, somente leitura e cacheáveis — como configurações públicas da Home e o mínimo de frete grátis — podem ficar sem rate limit no plugin. Quando houver proxy Next, não use um balde por IP compartilhado pelo proxy: derive a identidade de usuário/cliente ou aplique a proteção na borda (CDN/WAF).
- `$wpdb->prepare` sempre. Nada de interpolação em SQL.
- Sem chaves estrangeiras físicas: índices + validação em código (convenção do projeto).
- Convenção de nomes: arquivos com underscore, funções com prefixo `papelito_`.
- **Não editar core nem plugins de terceiros.** Estender via hooks/filters no `plugin_papelito` ou em um mu-plugin novo.
- Toda mudança de superfície REST exige atualizar [`../../../docs/integration-contracts.md`](../../../docs/integration-contracts.md).

## Logs

`my_plugin_log_json()` escreve JSON em `/wp-content/uploads/papelito/logs/plugin_papelito.log`. Auditoria de empresa vai para `papelito_company_audit_log`; ajustes de estoque para `papelito_vendor_stock_log`; transições de pedido para as notas do WooCommerce.

**Proibido logar**: CPF completo, data de nascimento, QSA completo, resposta completa de provedor, token de convite, token de API, documento enviado para revisão, payload da Pagar.me com PII, credencial dos Correios.
