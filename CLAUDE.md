# CLAUDE.md — papelito-wordpress (backend headless)

WordPress + WooCommerce servindo como API para o frontend Next.js (`../papelito-web`). O `/wp-admin` é restrito a administradores; o site público é o Next.

## Onde está a documentação

**Não duplique aqui o que é compartilhado.** Contexto de negócio, inventário de rotas e fluxos ponta a ponta vivem em [`../docs/`](../docs/README.md).

| Preciso de… | Documento |
|---|---|
| Índice do backend | [docs/README.md](docs/README.md) |
| Mapa dos 52 módulos do plugin, mu-plugins, eventos, convenções PHP | [docs/context/architecture.md](docs/context/architecture.md) |
| Tabelas, usermeta, postmeta, criptografia de PII | [docs/context/data-model.md](docs/context/data-model.md) |
| Invariantes com nome de função e armadilhas de SQL | [docs/context/business-rules.md](docs/context/business-rules.md) |
| Docker, Mailpit, flags B2B, troubleshooting | [docs/context/local-environment.md](docs/context/local-environment.md) |
| Testes standalone e baseline de PHPCS | [docs/context/testing.md](docs/context/testing.md) |
| Correios: adapter, modos, polling | [docs/context/correios-integration.md](docs/context/correios-integration.md) |
| Deploy, rollback, incidente, sync, credenciais | [docs/README.md#operações](docs/README.md#operações) |
| Inventário das rotas REST e operações GraphQL | [`../docs/integration-contracts.md`](../docs/integration-contracts.md) |
| Regras de negócio do produto | [`../docs/business-rules.md`](../docs/business-rules.md) |
| Fluxos funcionais | [`../docs/README.md#fluxos`](../docs/README.md#fluxos) |

## Stack

WordPress 6.9 (PHP 8.3) · WooCommerce · WPGraphQL · `wp-graphql-jwt-authentication` · `wp-graphql-woocommerce` · plugin custom **`plugin_papelito`** (todas as regras de negócio) · mu-plugins `papelito-cors` e `papelito-hardening` · Docker Compose local · Composer + PHPCS · Hostinger Business (SSH).

A versão de schema corrente é `PAPELITO_DB_VERSION`, em `plugin_papelito.php` — **não replique o número em documentação**. Migração nova exige bumpar a constante, senão o instalador não roda.

## Invariantes do backend

- **O WordPress é a única autoridade de autorização.** `papelito_company_purchase_capability()` é a única função que decide compra; checkout e pagamento a recalculam.
- **`current_user_can()` dentro do endpoint é a defesa real.** O proxy Next e o `permission_callback` dependem do mesmo token — não são camadas independentes.
- **Nunca bloqueie login em `wp_authenticate_user`** para implementar gate de produto: isso quebra a emissão do JWT headless. A única exceção existente é a verificação de e-mail, no filtro `authenticate` com prioridade 30 — depois de o core validar a senha, para senha errada em conta pendente não diferir da verificada em mensagem nem em custo de hash. Barreira nova vai no proxy Next, depois do login.
- **Nunca confie no navegador** para `companyId`, `cnpj`, `canPurchase`, papel, status ou preço. Toda mutação empresarial recarrega empresa e membership do banco.
- **`coverage/products` calcula em lote.** Não volte para loop que recalcula geocodificação, vendors e estoque produto a produto. Com `vendor_id`, checa só aquele vendor **sem geocodificar**. Preserve o transient versionado por `papelito_coverage_cache_version` e a resposta com `has_coverage`, `best_vendor` e `alternatives`.
- **Alteração de estoque dispara `papelito_vendor_stock_changed`**, e mudança nos metadados de cobertura invalida a versão do cache: `min_cep`, `max_cep`, `cep`, `cep_lat`, `cep_lng`, `shipping_lead_time_days`, `store_name`, `city`, `state`, `application_status`.
- **O estoque nativo do WooCommerce é deliberadamente intocado.** Sem espelhamento em `_stock`/`_stock_status`.
- **A taxonomia Papelito é a única classificação do fluxo headless.** Produto publicado precisa de categoria principal para a vitrine; subcategoria é opcional e os filtros usam OR por faceta e AND entre facetas.
- **`papelito_stock_zeroed` só dispara na transição `qty > 0 → 0`** e apenas com `notified_zero_at IS NULL`.
- **Pagamento direto ao vendor, sem split de receita**: `split` do PSP com recebedor único a 100%, `liable: true`, taxas no vendor. Vender exige **dupla aprovação** — cobertura regional **e** recebedor Pagar.me `active`.
- **A conta bancária do recebedor é sempre do CNPJ do vendor** (`holder_type: company`, `holder_document` = documento do recebedor), exigência da Pagar.me. A regra única é `papelito_vendor_bank_holder_matches_recipient()`; não reabra conta pessoa física nem converta draft antigo em silêncio. Detalhe em [`../docs/pagarme-integration.md`](../docs/pagarme-integration.md).
- **Nenhum pedido B2B lê documento fiscal de `wp_usermeta`** nem chama `papelito_pagarme_resolve_customer_document()`. O snapshot do pedido é a única fonte.
- **Webhook `charge.*` pode chegar sem `order_id`** — a busca precisa cair para o postmeta `_papelito_pagarme_charge_id`. Sempre reconcilie com `GET /orders/{id}` antes de liberar.
- **`pagarme-payments-for-woocommerce` fica desativado** — a integração é pelo `plugin_papelito`.
- **Desligar feature flag não remove tabela.** O schema é aditivo; rollback é por build/flag.
- **Qualquer flag `mock`/`DEV_*` fora de `local`/`development` mata o bootstrap.** Staging é tratado como produção.
- **Listeners de notificação ficam centralizados em `notifications.php`**, não espalhados pelos módulos de domínio.
- **Em produção o `wp-config.php` é mantido à mão** e não vem no deploy. Variável nova exige edição manual no servidor.

## Convenções

- **PHPCS com WordPress coding standards** (`phpcs.xml.dist`). Baseline aceito em [docs/context/testing.md](docs/context/testing.md#baseline-de-phpcs) — não amplie.
- **O lint do editor é parte da entrega, e stub de teste não é exceção**: parâmetro tipado (`mixed` onde o core aceita qualquer coisa), corpo nunca vazio e literal de fixture repetido em constante `<ESCOPO>_TEST_*`. Nenhum hint de SonarLint se cala com comentário — nem o `php:S1186`, que pede exatamente isso. Regras e forma canônica em [docs/context/testing.md](docs/context/testing.md#stub-que-nasce-limpo-no-editor).
- **Sanitizar sempre** (`sanitize_text_field`, `sanitize_email`, `wp_kses_post`), escapar na saída (`esc_*`). Nunca confiar em `$_POST`/`$_GET` direto.
- `$wpdb->prepare` sempre.
- Rotas REST públicas que executem trabalho caro, chamem provedores externos, sejam abusáveis ou mutem estado exigem rate limit via transient. Leituras pequenas, somente leitura e cacheáveis — como configurações públicas da Home e o mínimo de frete grátis — podem ficar sem rate limit no plugin. Quando houver proxy Next, não use um balde por IP compartilhado pelo proxy: derive a identidade de usuário/cliente ou aplique a proteção na borda (CDN/WAF).
- Sem chaves estrangeiras físicas: índices + validação em código.
- Nomes de arquivo com underscore; funções com prefixo `papelito_`.
- **Não editar core nem plugins de terceiros.** Estender por hooks/filters no `plugin_papelito` ou em mu-plugin novo.
- Mudança em superfície REST exige atualizar [`../docs/integration-contracts.md`](../docs/integration-contracts.md).
- **Nunca logar** CPF completo, data de nascimento, QSA completo, resposta completa de provedor, token, documento em revisão, payload da Pagar.me com PII ou credencial dos Correios.
- **Comentário solto dentro da função não entra** — a documentação vive no docblock acima da declaração (abaixo). `//` no meio do corpo só para justificar workaround ou invariante não óbvia.

## Documentação no código (PHPDoc)

O plugin já é documentado assim e a forma não é negociável: **arquivo e função exportada nascem com docblock `/** */`**, em português, escrito para humano.

- **Docblock de arquivo**, no topo, depois do `<?php`: o que o módulo governa, qual é a regra de ouro e o que ele deliberadamente **não** faz. [`account_status.php`](public_html/wp-content/plugins/plugin_papelito/includes/account_status.php) é a referência — ele explica os três níveis de "suspensa" e diz que suspensão não bloqueia login.
- **Docblock de função**: o que devolve e a armadilha de quem chamar — se relê do banco, se é a autoridade da decisão, se dispara hook, se é fail-closed. Uma linha resolve a maioria (`Nome completo (com prefixo) da tabela de histórico de estado de conta.`).
- **Docblock de classe de exceção**: por que ela existe em vez de uma `RuntimeException` genérica.
- `@param`/`@return` só quando o tipo não conta a história — com PHP 8.3 tipado, repetir a assinatura em prosa é ruído. `@package Papelito` no docblock de arquivo.
- Não documente o trivial só para preencher: constante autoexplicativa e wrapper de uma linha dispensam bloco.

Isso não substitui as invariantes: regra que vale para várias funções vive em [docs/context/business-rules.md](docs/context/business-rules.md) com o nome da função, e o docblock aponta para ela em vez de reescrevê-la.

## Variáveis críticas

Tabela completa em [docs/context/architecture.md](docs/context/architecture.md#configuração-por-ambiente). Obrigatórias para o básico funcionar: `GRAPHQL_JWT_AUTH_SECRET_KEY`, `GRAPHQL_WOOCOMMERCE_SECRET_KEY`, `WP_ENVIRONMENT_TYPE`. `PAPELITO_GOOGLE_CLIENT_ID` precisa ser **exatamente igual** a `GOOGLE_CLIENT_ID` no frontend.

## Comandos

```bash
docker compose up -d
docker compose exec web wp <comando>     # o serviço é "web"
docker compose --profile quality run --rm phpcs
php -l <arquivo>
php public_html/wp-content/plugins/plugin_papelito/tests/test-<x>.php
bash scripts/pull-from-prod.sh
```
