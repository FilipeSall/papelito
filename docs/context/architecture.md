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
| `auth_endpoints.php` | registro, verificação de e-mail, Google OAuth, recuperação de senha, `/auth/me` |
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
| `home_assets.php` | banners e assets da home |
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
| `shipping.php` | cotação nos Correios |
| `correios_prepostage.php` | pré-postagem e etiqueta |
| `correios_tracking.php` | polling do Rastro, projeção de estado, S10 manual |
| `receipts.php` | recibo persistido: numeração anual, snapshot imutável em centavos, parcelas por vendor |
| `receipts_backfill.php` | backfill em lotes dos pedidos pagos antes do recibo existir, com checkpoint e WP-CLI |
| `order_receipt.php` | recibo interno em PDF |

O recibo tem duas camadas com responsabilidades distintas. `receipts.php` **grava** o documento no momento em que o pagamento é confirmado, congelando valores e itens; `order_receipt.php` **renderiza** o PDF sob demanda, lendo `papelito_receipts` + `papelito_receipt_vendor_parts`. **Nada financeiro, identificador de compra ou data vem do `WC_Order` ao vivo** — dele só sai a situação operacional, que é informativa. Pedido pago sem linha de recibo emite de forma idempotente durante a geração; sem recibo possível, a rota devolve `papelito_receipt_unavailable` (409), nunca um fatal.

`papelito_receipt_issue_for_order()` é idempotente por `order_id` e recusa pedido que `papelito_pagarme_payment_state_is_paid()` não aprove. A numeração `PPL-AAAA-NNNNNN` sai de `papelito_receipt_sequences`, com `SELECT ... FOR UPDATE` na linha do ano dentro da mesma transação que grava o recibo — **nunca `MAX(id)+1`, `get_option` ou contador em memória**. A soma das parcelas por vendor bate exatamente com o total do recibo: o frete é repartido por `papelito_receipt_allocate_cents()`, que dá o resto à última parcela.

### Vendor e operação

| Arquivo | Responsabilidade |
|---|---|
| `revendedor_application.php` | candidatura de vendor, aprovação, criação direta pelo admin |
| `vendor_dashboard.php` | KPIs, pedidos, configurações, faixas de cobertura |
| `vendor_interests.php` | manifestações de interesse |
| `vendor_messaging.php` | threads comprador ↔ vendor |
| `vendor_processing_alerts.php` | alertas de separação |
| `support.php` | apoio a suporte |

### Administração e notificações

| Arquivo | Responsabilidade |
|---|---|
| `admin_users.php` | listagem, detalhe, papel, ativação de e-mail, cancelamento de pedido |
| `admin_reports.php` | relatórios e exportações |
| `notifications.php` | dispatcher **e todos os listeners** dos eventos de domínio |

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
| `papelito-hardening.php` | bloqueia enumeração de usuários, desativa XML-RPC, remove o generator, rate limit no login | sim |
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
| `PAPELITO_PAGARME_SIMULATION_ENABLED`, `PAPELITO_PAGARME_SIMULATION_TOKEN` | só local/teste | simulador de webhook |
| `PAPELITO_CORREIOS_*` | frete | ver [context/correios-integration.md](correios-integration.md) |
| `PAPELITO_PII_LOOKUP_KEY`, `PAPELITO_PII_ENCRYPTION_KEY`, `PAPELITO_PII_KEY_VERSION` | B2B | ver [context/data-model.md](data-model.md#criptografia-de-pii) |
| `PAPELITO_CNPJWS_TOKEN`, `PAPELITO_RECEITAWS_TOKEN` | opcional | provedores de CNPJ |
| `PAPELITO_PRIVATE_COMPANY_DOCUMENTS_DIR` | análise documental | default fora do webroot |
| `PAPELITO_B2B_*`, `PAPELITO_COMPANY_*`, `PAPELITO_QSA_*`, `PAPELITO_ALPHANUMERIC_CNPJ_*` | flags | ver [`../../../docs/architecture.md`](../../../docs/architecture.md#feature-flags) |

> **Em produção o `wp-config.php` é mantido à mão no servidor** — o deploy faz rsync de `themes/` e `plugins/` e **não** toca nele. Uma variável nova exige edição manual lá, e o `wp-config.example.php` do repositório precisa ser atualizado no mesmo movimento (ver [operations/sync-from-prod.md](../operations/sync-from-prod.md)).

## Convenções PHP

- **PHPCS com os WordPress coding standards** (`phpcs.xml.dist`). O baseline aceito está em [context/testing.md](testing.md#baseline-de-phpcs).
- **Sanitizar sempre**: `sanitize_text_field`, `sanitize_email`, `wp_kses_post`; escapar na saída com `esc_*`. Nunca confiar em `$_POST` / `$_GET` direto.
  - Exceção documentada: senha temporária de vendor **não** passa por `sanitize_text_field` — corromperia caracteres válidos. Ver [`../../../docs/flows/authentication.md`](../../../docs/flows/authentication.md#senha-temporária-de-vendor).
- `current_user_can()` dentro do endpoint é a autorização real, não o `permission_callback` isolado.
- **Endpoint REST público novo exige rate limit por IP** via transient — padrão em `auth_endpoints.php`.
- `$wpdb->prepare` sempre. Nada de interpolação em SQL.
- Sem chaves estrangeiras físicas: índices + validação em código (convenção do projeto).
- Convenção de nomes: arquivos com underscore, funções com prefixo `papelito_`.
- **Não editar core nem plugins de terceiros.** Estender via hooks/filters no `plugin_papelito` ou em um mu-plugin novo.
- Toda mudança de superfície REST exige atualizar [`../../../docs/integration-contracts.md`](../../../docs/integration-contracts.md).

## Logs

`my_plugin_log_json()` escreve JSON em `/wp-content/uploads/papelito/logs/plugin_papelito.log`. Auditoria de empresa vai para `papelito_company_audit_log`; ajustes de estoque para `papelito_vendor_stock_log`; transições de pedido para as notas do WooCommerce.

**Proibido logar**: CPF completo, data de nascimento, QSA completo, resposta completa de provedor, token de convite, token de API, documento enviado para revisão, payload da Pagar.me com PII, credencial dos Correios.
