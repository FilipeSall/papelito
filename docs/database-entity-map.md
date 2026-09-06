# Mapa de entidades do banco de dados

Levantamento completo do schema `papelito_local`, feito **contra o banco em execução**, não a partir de documentação. Acompanha o diagrama [`database-entity-map.excalidraw`](database-entity-map.excalidraw), que é a versão visual deste mesmo conteúdo.

Para o *porquê* de cada decisão de modelagem, veja [context/data-model.md](context/data-model.md). Este documento é o inventário: o que existe, com que colunas, ligado a quê.

---

## Resumo executivo

O banco tem **193 tabelas**. Dessas, **40 são criadas pelo plugin próprio**, 12 são do core do WordPress e 40 do WooCommerce (incluindo o Action Scheduler). As outras **101 são plugins de terceiros** herdados da fase pré-headless, quase todas vazias.

Cinco constatações mudam como você deve ler qualquer coisa neste schema:

**1. Não existe uma única chave estrangeira em todo o banco.** Zero. A consulta abaixo devolve conjunto vazio:

```sql
SELECT * FROM information_schema.key_column_usage
WHERE table_schema = 'papelito_local' AND referenced_table_name IS NOT NULL;
```

Todas as 40 tabelas do plugin são InnoDB — o motor suporta FK — e nenhuma declara uma. Isso é herança do `dbDelta()` do WordPress, que não emite `FOREIGN KEY`. Consequência prática: **toda relação deste mapa é lógica**, sustentada por código PHP. Um `DELETE` direto no banco não cascateia, não é bloqueado e não avisa. O plugin compensa com *checadores de integridade* em PHP (veja `papelito_product_taxonomy_integrity_report()` em `product_taxonomy.php`), que varrem órfãos periodicamente em vez de impedi-los.

**2. Os pedidos vivem em `wp_posts`, não em HPOS.** As tabelas `wp_wc_orders`, `wp_wc_orders_meta`, `wp_wc_order_addresses` e `wp_wc_order_operational_data` existem e estão **vazias**. O site opera no armazenamento legado: um pedido é um `wp_posts` com `post_type = 'shop_order'`, e todo o seu estado está em `wp_postmeta`. Qualquer query, relatório ou migração que assuma HPOS lê tabela vazia.

**3. Não existe tabela de vendor.** O vendor é um `wp_users` com a role `seller` (em `wp_capabilities`, dentro de `wp_usermeta`), e o resto do seu perfil — faixa de CEP, geocodificação, nome da loja, recebedor Pagar.me — está espalhado em `wp_usermeta`. Toda coluna chamada `vendor_id` no schema aponta para `wp_users.ID`.

**4. A taxonomia do WooCommerce foi substituída, não estendida.** `wp_papelito_categories` / `_subcategories` / `_collections` são a fonte de classificação do catálogo headless. `wp_terms` e `wp_term_relationships` continuam povoados por herança do `product_cat`, mas não decidem mais nada na vitrine.

**5. O pagamento não tem tabela.** As 7 tabelas `wp_pagarme_module_core_*` são do plugin oficial Pagar.me para WooCommerce, estão todas vazias e **não têm uma única referência no código do Papelito** (`grep -rl pagarme_module_core` no plugin devolve nada). A integração própria grava tudo em `wp_postmeta` do pedido, com o prefixo `_papelito_pagarme_*`.

---

## Metodologia

O que foi feito, na ordem, para que o resultado seja reproduzível:

1. **Descoberta do acesso.** `docker-compose.yml` define o serviço `db` (MariaDB 10.5), banco `papelito_local`, exposto em `localhost:3307`. Container `papelito-db`, ativo durante o levantamento.
2. **Inventário bruto.** `information_schema.tables` — nome, motor, contagem de linhas e tamanho de cada uma das 193 tabelas.
3. **DDL real.** `SHOW CREATE TABLE` de todas as 40 tabelas `wp_papelito_*`, para ler tipos, colações, chaves primárias e **todos os índices**, que aqui fazem o papel que as FKs não fazem.
4. **Busca por FKs físicas.** `information_schema.key_column_usage` filtrando `referenced_table_name IS NOT NULL` → conjunto vazio, confirmado.
5. **Ground truth dos metadados.** Em vez de confiar no código, as chaves de `wp_usermeta` e `wp_postmeta` foram enumeradas com `GROUP BY meta_key` **no banco**, e cruzadas com o `post_type` do post dono. É por isso que a lista de chaves de pedido neste documento é exata, e não uma amostra.
6. **Semântica no código.** Para cada coluna que parece uma FK, a origem foi confirmada no plugin: `active_vendor.php` (o que é `vendor_id`), `order_routing.php` (como o vendor entra no pedido), `product_taxonomy.php` (os JOINs reais da taxonomia), `product_benefits.php` (o polimorfismo dos alvos), `receipts.php`, `merchandise.php`, `company_schema.php`.
7. **Sítios de criação de schema.** `grep -n "CREATE TABLE" includes/*.php` localizou os 43 blocos `dbDelta` que criam as 40 tabelas, confirmando que o plugin é o dono de todas elas.
8. **Colações.** `information_schema.columns` para achar as colunas com colação divergente — que é onde moram as armadilhas de JOIN.

Fonte da verdade da modelagem: **o banco em execução**, com o código do plugin como fonte da *semântica*. Não há Prisma, ORM, migrations versionadas nem arquivo de schema — o schema é produzido em runtime por `dbDelta()` a partir de strings SQL nos módulos PHP.

---

## Como as camadas se dividem

| Camada | Tabelas | Papel |
|---|---:|---|
| **WordPress (core)** | 12 | Substrato. `wp_posts` e `wp_users` carregam quase todo o domínio; `wp_postmeta` e `wp_usermeta` carregam o resto |
| **WooCommerce** | 40 | Motor de pedido, carrinho, lookups de leitura e Action Scheduler. Boa parte é analytics não usado pelo painel próprio |
| **Papelito (plugin)** | **40** | Todo o domínio B2B, catálogo, estoque, recibo, logística e comunicação |
| **Auxiliar / terceiros** | 101 | Wordfence, Elementor, RevSlider, SellKit, Jet*, Yoast, LiteSpeed, loyalty, redirection. Ruído |

---

## Camada WordPress

### `wp_users` ⭐ núcleo
Toda conta do sistema, em uma tabela só. **O papel não é coluna**: vem de `wp_capabilities`, em `wp_usermeta`. `customer` compra, `seller` **é** o vendor regional, `administrator` opera o painel.

| Coluna | Nota |
|---|---|
| `ID` | PK. Referenciada por ~25 tabelas Papelito, nenhuma com FK |
| `user_login`, `user_email` | UNIQUE |
| `user_pass`, `user_registered`, `display_name` | |

### `wp_usermeta`
Metadados da conta — e onde mora metade do estado do vendor, porque o plugin nunca criou uma tabela de perfil de vendor. Chaves confirmadas no banco:

| Chave | Para que serve |
|---|---|
| `wp_capabilities` | O papel (`customer` / `seller` / `administrator`) |
| `papelito_active_vendor_id` | Vendor ativo do comprador → `wp_users.ID` |
| `min_cep`, `max_cep` | Faixa de CEP que o vendor cobre |
| `cep_lat`, `cep_lng` | Geocodificação usada pelo cálculo de cobertura |
| `store_name`, `shipping_lead_time_days`, `instagram` | Perfil público do vendor |
| `papelito_b2b_active_company_id` | Empresa ativa → `wp_papelito_companies.id` |
| `papelito_pagarme_recipient_id`, `_status`, `_last_error*`, `_last_sync_at` | Estado do recebedor Pagar.me do vendor |
| `papelito_profile_complete`, `papelito_account_state`, `papelito_b2b_required` | Estado da conta |
| `papelito_email_verification_status`, `_token_hash`, `_expires_at`, `_sent_at`, `papelito_email_verified_at` | Verificação de e-mail |
| `papelito_favorites_v1`, `papelito_favorite_promo_email_enabled` | Favoritos |
| `cep`, `city`, `state`, `cnpj`, `phone_number` | Endereço legado (fallback quando não há empresa ativa) |
| `seller_application_*`, `application_submitted_at` | Candidatura a vendor |
| `google_sub` | Login Google |

### `wp_posts` ⭐ núcleo
**Quatro entidades de negócio em uma tabela**, separadas por `post_type`:

| `post_type` | É | Volume local |
|---|---|---:|
| `product` | Produto do catálogo | 42 publicados, 8 rascunhos |
| `product_variation` | Variação | 16 |
| `shop_order` | **O PEDIDO** | 4 |
| `shop_coupon` | Cupom | 1 |
| `attachment` | Mídia (ícones de categoria, imagens de kit, etc.) | 468 |

Colunas relevantes: `ID` (PK), `post_author` → `wp_users.ID`, `post_type`, `post_status` (`wc-processing`, `wc-failed`…), `post_title`, `post_name` (slug), `post_date`.

### `wp_postmeta`
Onde o pedido de fato vive. Todas as chaves `_papelito_*` presentes em `shop_order`, agrupadas por função:

| Grupo | Chaves |
|---|---|
| **Vendor** | `_papelito_vendor_id` → `wp_users.ID`, `_papelito_vendor_name`, `_papelito_vendor_status`, `_papelito_vendor_status_source`, `_papelito_vendor_purchase_notified` |
| **Empresa (snapshot B2B)** | `_papelito_company_id`, `_company_cnpj`, `_company_legal_name`, `_company_trade_name`, `_company_phone`, `_company_billing_email`, `_company_billing_email_verified_at`, `_company_status`, `_company_registry_status`, `_company_ownership_status`, `_company_verified_at`, `_company_verification_source`, `_company_provider_source`, `_company_provider_checked_at`, `_company_provider_data_hash`, `_company_pagarme_customer_code`, `_company_fiscal_{cep,state,city,neighborhood,street,number,complement}`, `_company_snapshot_version` |
| **Membership** | `_papelito_membership_id`, `_membership_role`, `_membership_status`, `_membership_approved_at`, `_membership_expires_at` |
| **Pagamento** | `_papelito_pagarme_order_id`, `_charge_id`, `_payment_method`, `_payment_state`, `_idempotency_key`, `_last_reconcile_at`, `_pix_qr_code`, `_pix_qr_code_url`, `_pix_copy_paste`, `_pix_expires_at` |
| **Valor** | `_papelito_authoritative_total_cents` |
| **Frete** | `_papelito_shipping_service_code`, `_service_name`, `_delivery_time`, `_price_cents`, `_discount_cents`, `_shipping_neighborhood` |
| **Estoque** | `_papelito_stock_reserved`, `_papelito_stock_decremented` |
| **Logística** | `_papelito_logistics_status`, `_logistics_updated_at`, `_papelito_tracking_notification_<vendor>_<evento>` |
| **Atribuição** | `_papelito_ga_client_id`, `_papelito_ga_session_id` |
| **Checkout** | `_papelito_checkout_attempt_id`, `_attempt_company_id`, `_attempt_request_hash`, `_papelito_b2b_snapshot_version` |
| **Fiscal do comprador** | `_papelito_fiscal_{cep,state,city,neighborhood,street,number,complement}`, `_billing_cnpj` |

Em outros `post_type`: `_papelito_kit_id` e `_papelito_peso_bruto_kg` (product), `_papelito_coupon_role` / `_coupon_vendor_ids` / `_coupon_product_ids` (shop_coupon), `_papelito_source` e `_papelito_temporary_admin_media` (attachment).

### Taxonomia nativa — `wp_terms`, `wp_termmeta`, `wp_term_taxonomy`, `wp_term_relationships`
Continuam povoadas (110 termos, 291 relações) por herança do `product_cat`. **Não são mais a fonte de classificação do catálogo headless.** `wp_term_relationships` é a junção N:N genérica entre qualquer post e qualquer termo.

### `wp_options`
Configuração global, feature flags do rollout B2B e transients — incluindo o transient versionado de cobertura por CEP usado por `coverage/products`.

---

## Camada WooCommerce

### `wp_woocommerce_order_items`
As linhas do pedido. Produto, frete e cupom convivem na mesma tabela, separados por `order_item_type` (`line_item` | `shipping` | `coupon`). `order_id` → `wp_posts.ID`.

### `wp_woocommerce_order_itemmeta`
Metadados da linha — e onde o **vendor é gravado por item**:

| Chave | Aponta para |
|---|---|
| `_product_id`, `_variation_id` | `wp_posts.ID` |
| `_vendor_id`, `_vendor_name` | `wp_users.ID` (seller) |
| `_qty` | |
| `_papelito_total_cents`, `_subtotal_cents`, `_discount_cents`, `_discount_source` | Valores autoritativos em centavos |
| `_papelito_kit_snapshot` | Composição do kit congelada no momento da venda |
| `_papelito_shipping_price_cents`, `_shipping_discount_cents`, `_shipping_service_code` | Nas linhas de frete |

> `order_routing.php:681` grava `_vendor_id` **por item**, com `_papelito_vendor_id` no pedido como padrão (`:850`, `:934`). O schema, portanto, **representa** um pedido multivendor — o que impede é a regra de negócio, bloqueada em `pricing.php` e no split de recebedor único da Pagar.me.

### `wp_wc_orders` e companhia (HPOS) — **vazias**
`wp_wc_orders`, `wp_wc_orders_meta`, `wp_wc_order_addresses`, `wp_wc_order_operational_data`. Criadas pelo WooCommerce, 0 linhas. Não são a fonte da verdade e não devem ser escritas.

### Demais
`wp_wc_product_meta_lookup` (leitura desnormalizada de preço/SKU/estoque, PK = `product_id`), `wp_woocommerce_sessions` (carrinho antes do pedido existir), `wp_wc_reserved_stock` (reserva do Woo, distinta de `_papelito_stock_reserved`), `wp_wc_customer_lookup` / `wp_wc_order_stats` / `wp_wc_category_lookup` (analytics nativo — `order_stats` está vazia; o painel do Papelito não lê daqui), `wp_woocommerce_shipping_zone_methods`.

---

## Camada Papelito — as 40 tabelas

### Identidade, empresa e acesso (12)

| Tabela | Papel | Chaves e colunas que importam |
|---|---|---|
| `wp_papelito_customer_profiles` | A pessoa física por trás da conta, **1:1** com `wp_users` | PK `user_id`; UNIQUE `cpf_hmac`; `cpf_ciphertext`, `birth_date_ciphertext` (PII), `cpf_last4` (único fragmento exibível), `identity_status` |
| `wp_papelito_companies` ⭐ | **O comprador fiscal.** Quem compra é a empresa | PK `id`; UNIQUE `cnpj` (`utf8mb4_bin`); `owner_user_id`, `created_by_user_id`, `verified_by_user_id` → `wp_users.ID`; `registry_status`, `ownership_status`, `company_status`; `fiscal_*` (origem do CEP em contexto B2B); `provider_source`/`_checked_at`/`_data_hash`; `pagarme_customer_id` |
| `wp_papelito_company_members` ⭐ | **A autorização.** Pessoa ↔ empresa, com papel e status | PK `id`; UNIQUE `(company_id, user_id)`; `member_role` (owner/admin/buyer), `member_status`, `membership_origin`, `expires_at`, `identity_requirement`; 5 colunas `*_by_user_id` de trilha |
| `wp_papelito_b2b_onboarding` | Estado do funil de entrada, **1:1** com a conta | PK `user_id`; `company_id`, `membership_id`; `onboarding_type`, `status`, `target_cnpj`, `expires_at` |
| `wp_papelito_company_invitations` | Convite da empresa para uma pessoa | PK `id`; UNIQUE `token_hash`; `invited_email`, `invited_cpf_hmac`, `invited_role`, `invitation_status`, `expires_at`, `resend_count` |
| `wp_papelito_company_owner_applications` | Candidatura a **dono** de empresa existente, com análise documental | PK `id`; UNIQUE `(company_id, attempt_number)`; UNIQUE `(company_id, is_open)` → no máximo uma aberta; `evidence_json` (cruzamento de QSA), `document_storage_key` + `sha256`, `document_purge_status` |
| `wp_papelito_company_pre_account_applications` | Cadastro de empresa **antes da conta existir** | PK `id`; UNIQUE `(canonical_cnpj, is_open)`; tudo cifrado (`contact_email`, `full_name`, `phone`, `cpf`, `birth_date`, `address`, `legal_name`); `password_hash` (migra para `wp_users` na aprovação); `resume_token_hash`; `created_user_id` / `created_company_id` / `created_membership_id` |
| `wp_papelito_company_audit_log` | Trilha de auditoria **do domínio de empresa** | PK `id`; `company_id`, `actor_user_id`; `action`, `payload_json` |
| `wp_papelito_company_idempotency` | Deduplicação de escritas B2B | PK `id`; UNIQUE `(actor_user_id, operation, key_hash)`; `request_hash`, `resource_id`, `response_code`, `expires_at` |
| `wp_papelito_b2b_legacy_email_log` | Campanha de migração da coorte pré-B2B | PK `id`; UNIQUE `(user_id, campaign, campaign_version)`; `status`, `attempts`, `next_retry_at` |
| `wp_papelito_account_status_log` | Ativação, bloqueio e suspensão de conta pelo admin | PK `id`; `user_id`, `actor_user_id`, `action`, `reason` |
| `wp_papelito_integration_secret_audit` | Quem leu ou girou um segredo de integração | PK `id`; `slug`, `actor_user_id`, `action`, `ip`, `user_agent`. **O segredo não está aqui** |

### Catálogo e taxonomia (9)

| Tabela | Papel | Chaves e colunas que importam |
|---|---|---|
| `wp_papelito_categories` ⭐ | Categoria de 1º nível, **fonte única de classificação** | PK `id`; UNIQUE `slug`; `icon_attachment_id` → `wp_posts.ID`; `seo_title`, `seo_description`; `is_active`, `archived_at` |
| `wp_papelito_subcategories` | Subcategoria opcional dentro de uma categoria | PK `id`; UNIQUE `(category_id, slug)`; `facet` → agrupa em facetas de filtro; `sort_order`, `is_active` |
| `wp_papelito_product_category` | Produto → categoria | **PK `product_id`** — um produto tem no máximo **uma** categoria. A regra está na PK, não no código; `category_id` |
| `wp_papelito_product_subcategory` | Produto → subcategorias | PK `(product_id, subcategory_id)` — N:N real |
| `wp_papelito_collections` | Agrupamento comercial transversal (ex.: `premium`) | PK `id`; UNIQUE `slug` |
| `wp_papelito_product_collection` ⚠️ | Produto → coleção, **por slug em texto** | PK `(product_id, collection_slug)`. Ver *Relações frágeis* |
| `wp_papelito_benefit_groups` | Grupo de benefícios da página de produto | PK `id`; UNIQUE `global_key` → força **exatamente um** grupo global |
| `wp_papelito_benefit_items` | Itens do grupo | PK `id`; `group_id`; `icon_type` ∈ `emoji`\|`svg` (nunca HTML); `icon_attachment_id` |
| `wp_papelito_benefit_group_targets` | Alvo do grupo — **polimórfico** | PK `(target_type, target_key)` → um alvo pertence a no máximo um grupo. `target_type` ∈ `product`\|`collection`\|`category`. Precedência de resolução: produto > coleção > categoria > global |

### Kits, brindes e estoque (7)

| Tabela | Papel | Chaves e colunas que importam |
|---|---|---|
| `wp_papelito_kits` | Marca um produto como kit | PK `id`; UNIQUE `product_id` → 1:1 com o produto; `image_attachment_id`; `package_length`/`width`/`height` |
| `wp_papelito_kit_items` | Produtos que compõem o kit | PK `(kit_id, product_id)`; `quantity` |
| `wp_papelito_merchandise` | Catálogo de **brindes** | PK `id`; `weight`, `length`, `width`, `height` (entram na cotação de frete); `image_attachment_id`. **Não são produtos do Woo**: não têm preço nem post |
| `wp_papelito_kit_merchandise_items` | Brindes que compõem o kit | PK `(kit_id, merchandise_id)`; `quantity` |
| `wp_papelito_vendor_stock` ⭐ | **A disponibilidade** | PK `id`; UNIQUE `(vendor_id, product_id)`; `qty`; `notified_zero_at` |
| `wp_papelito_vendor_stock_log` | Razão do estoque, com delta e motivo | PK `id`; `vendor_id`, `product_id`, `delta`, `reason` |
| `wp_papelito_vendor_interests` | Lead de quem quer virar vendor | PK `id`; UNIQUE `(cnpj, visibility)`, `(email, visibility)`, `customer_user_id`; **PII em claro**: `cnpj`, `phone`, `email` |

### Pedido, recibo e documento fiscal (5)

| Tabela | Papel | Chaves e colunas que importam |
|---|---|---|
| `wp_papelito_receipts` ⭐ | Recibo interno numerado e **persistido** | PK `id`; UNIQUE `receipt_number` (`PPL-AAAA-NNNNNN`, `utf8mb4_bin`); UNIQUE `order_id` → um recibo por pedido; `customer_user_id`, `company_id`; `company_cnpj`/`company_legal_name` são **cópia**, não join; `subtotal_cents`/`discount_cents`/`shipping_cents`/`total_cents`; `snapshot_json` + `snapshot_version`; `origin` ∈ `payment`\|`backfill` |
| `wp_papelito_receipt_vendor_parts` | A parte do recibo de cada vendor | PK `id`; UNIQUE `(receipt_id, vendor_id)`; `items_json`, `part_number`, `part_ordinal`; valores em centavos |
| `wp_papelito_receipt_sequences` | Contador do número do recibo, uma linha por ano | PK `sequence_year`; `next_sequence`. Existe para garantir numeração sequencial sem buraco sob concorrência |
| `wp_papelito_fiscal_documents` | Nota fiscal **anexada** pelo vendor | PK `id`; UNIQUE `(order_id, vendor_id)`; UNIQUE `storage_key`; `mime`, `size_bytes`, `sha256`, `uploaded_by`. É só um arquivo indexado — sem campo digitado nem validação fiscal |
| `wp_papelito_fiscal_document_events` | Trilha do anexo: enviado, substituído, baixado, removido | PK `id`; `order_id`, `vendor_id`, `actor_user_id`, `event`. Append-only — sobrevive à substituição do documento |

### Logística (2)

| Tabela | Papel | Chaves e colunas que importam |
|---|---|---|
| `wp_papelito_shipments` | A remessa: uma linha por (pedido, vendor, direção) | PK `id`; **três UNIQUE**: `tracking_code`, `prepost_id`, `idempotency_key` (esta impede gerar duas etiquetas no retry); `status` + `status_rank` (evita retrocesso de estado); `last_event_*` (cópia do último evento); `next_poll_at`, `poll_attempts`; `label_storage_key` + `label_sha256`; `manual_fallback_eligible`, `reconciliation_status` |
| `wp_papelito_tracking_events` | Cada evento de rastreio recebido | PK `id`; UNIQUE `event_key` → torna a ingestão idempotente; `shipment_id`; `raw_payload` (resposta original preservada) |

### Comunicação (5)

| Tabela | Papel | Chaves e colunas que importam |
|---|---|---|
| `wp_papelito_message_threads` | A conversa comprador ↔ vendor | PK `id`; UNIQUE `order_id` (nullable) e `support_key`; `customer_id`, `vendor_id` → `wp_users.ID`; `context` ∈ `order`\|`support`; `escalated_at` |
| `wp_papelito_messages` | Mensagens da thread. Append-only | PK `id`; `thread_id`, `sender_id`, `body` |
| `wp_papelito_message_reads` | Marcador de leitura por participante | PK `(thread_id, user_id)`; `last_read_message_id` — ponteiro, não registro por mensagem |
| `wp_papelito_notifications` | Notificação in-app | PK `id`; UNIQUE `(user_id, type, dedupe_key)` → o dedupe do barramento de eventos; `payload`, `read_at` |
| `wp_papelito_notification_email_log` | Espelho do dedupe no canal e-mail | PK `id`; UNIQUE `(user_id, type, dedupe_key)`. Tabela separada de propósito: apagar a notificação in-app não pode liberar o reenvio do e-mail |

---

## Relações identificadas

Nenhuma é uma FK. A coluna "Tipo" abaixo diz como a relação foi determinada.

### Verificadas no DDL (a cardinalidade está numa PK ou UNIQUE)

| Filho | → Pai | Card. | Garantido por |
|---|---|---|---|
| `customer_profiles.user_id` | `wp_users.ID` | 1:1 | PK `user_id` |
| `b2b_onboarding.user_id` | `wp_users.ID` | 1:1 | PK `user_id` |
| `company_members` | `companies` + `wp_users` | N:1 / N:1 | UNIQUE `(company_id, user_id)` |
| `company_owner_applications` | `companies` | N:1 | UNIQUE `(company_id, is_open)` limita a 1 aberta |
| `product_category.product_id` | `wp_posts` (product) | **1:1** | PK só em `product_id` |
| `product_category.category_id` | `categories.id` | N:1 | |
| `product_subcategory` | `wp_posts` + `subcategories` | N:N | PK composta |
| `subcategories.category_id` | `categories.id` | N:1 | UNIQUE `(category_id, slug)` |
| `kits.product_id` | `wp_posts` (product) | **1:1** | UNIQUE `product_id` |
| `kit_items` | `kits` + `wp_posts` | N:N | PK composta |
| `kit_merchandise_items` | `kits` + `merchandise` | N:N | PK composta |
| `vendor_stock` | `wp_users` (seller) + `wp_posts` | N:N | UNIQUE `(vendor_id, product_id)` |
| `receipts.order_id` | `wp_posts` (shop_order) | **1:1** | UNIQUE `order_id` |
| `receipt_vendor_parts` | `receipts` + `wp_users` | N:1 / N:1 | UNIQUE `(receipt_id, vendor_id)` |
| `fiscal_documents` | pedido + vendor | 1:1 por par | UNIQUE `(order_id, vendor_id)` |
| `tracking_events.shipment_id` | `shipments.id` | N:1 | UNIQUE `event_key` (idempotência) |
| `message_threads.order_id` | `wp_posts` (shop_order) | **1:1** | UNIQUE `order_id`, nullable |
| `message_reads` | `message_threads` + `wp_users` | N:N | PK composta |
| `benefit_group_targets` | `benefit_groups` | N:1 | PK `(target_type, target_key)` |

### Inferidas do código (a coluna é um `bigint` solto; a semântica está em PHP)

| Relação | Onde foi confirmada |
|---|---|
| `vendor_id` → `wp_users.ID` **com role `seller`** | `active_vendor.php` → `papelito_validate_active_vendor()` checa `in_array('seller', $user->roles)` |
| `usermeta.papelito_active_vendor_id` → `wp_users.ID` | `active_vendor.php` → `PAPELITO_ACTIVE_VENDOR_META` |
| `postmeta._papelito_vendor_id` → `wp_users.ID` | `order_routing.php:794` |
| `order_itemmeta._vendor_id` → `wp_users.ID` | `order_routing.php:681` |
| `product_id` → `wp_posts.ID` com `post_type='product'` | `product_taxonomy.php:656, 1857` — os JOINs filtram por `post_type` explicitamente |
| `icon_attachment_id` / `image_attachment_id` → `wp_posts.ID` (attachment) | `product_taxonomy.php`, `kits.php`, `merchandise.php` |
| `benefit_group_targets.target_key` → produto / coleção / categoria | `product_benefits.php:180` → `papelito_benefit_target_types()` |
| `receipts.company_id` → `companies.id` | `receipts.php:620` |
| `b2b_onboarding.company_id` / `membership_id` | `company_onboarding.php:88-105` |
| `postmeta._papelito_company_id` / `_membership_id` | `receipts.php:291`, snapshot do checkout |
| `_papelito_coupon_vendor_ids` / `_coupon_product_ids` (CSV em meta) | `coupons.php:14-16` |

### Relações frágeis — merecem atenção

**`product_collection.collection_slug` → `collections.slug`.** A ligação é por **texto**, não por id, e **nenhuma query do plugin faz JOIN entre as duas tabelas** — verificado por grep em `product_taxonomy*.php`. A resolução acontece em PHP, depois de ler as duas tabelas separadamente. Consequências: renomear o slug de uma coleção órfã todas as linhas em silêncio; não há como detectar isso com uma constraint; o checador de integridade em `product_taxonomy.php:1939` faz `SELECT DISTINCT collection_slug` justamente para achar esses órfãos depois do fato.

**`benefit_group_targets.target_key`** é um `varchar(96)` que guarda um **ID numérico** quando `target_type='product'` e um **slug** quando é `collection` ou `category`. Nenhum índice pode validar isso.

---

## Colações — onde estão as armadilhas

O schema é majoritariamente `utf8mb4_unicode_520_ci` (163 tabelas). As 28 tabelas em `utf8_general_ci` (utf8**mb3**) são todas de terceiros — Wordfence, Simple History, Yoast, Jadlog. **Nenhuma tabela de domínio está em utf8mb3**, então não há risco de JOIN entre camadas por causa disso.

Dentro das tabelas Papelito, sete colunas são `utf8mb4_bin` de propósito — comparação byte a byte, sem equivalência de acento ou caixa:

| Coluna | Por quê |
|---|---|
| `companies.cnpj` | Identificador exato; suporta o CNPJ alfanumérico |
| `b2b_onboarding.target_cnpj` | Idem |
| `company_pre_account_applications.canonical_cnpj` | Idem |
| `receipts.receipt_number` | Número de documento não pode colidir por caixa |
| `receipt_vendor_parts.part_number` | Idem |
| `fiscal_documents.storage_key` e `sha256` | Chave de armazenamento e hash |

> **Atenção:** `receipts.company_cnpj` é `char(14)` com a colação **padrão** da tabela, enquanto `companies.cnpj` é `utf8mb4_bin`. Um `JOIN ... ON r.company_cnpj = c.cnpj` cruza colações diferentes. Junte por `company_id`, que é o caminho pretendido — o CNPJ ali é cópia de snapshot, não chave de junção.

---

## Ambiguidades e pontos que precisam de validação humana

1. **`tb_codigosdisplay`** — tabela sem prefixo `wp_`, sem chave primária, com **PII em claro** (CNPJ, CPF, e-mail, telefone) de uma campanha de sorteio legada. Nenhum código do projeto a referencia. Está vazia localmente, mas **é preciso confirmar se está vazia em produção** antes de descartar. Se tiver dados, é um passivo de LGPD fora de qualquer inventário.

2. **`wp_papelito_vendor_interests` guarda CNPJ, telefone e e-mail em claro**, enquanto o resto do domínio cifra PII (`customer_profiles`, `company_pre_account_applications`). Divergência deliberada ou lacuna? Vale decidir.

3. **`receipt_vendor_parts` suporta N vendors por recibo**, mas a regra de negócio proíbe pedido multivendor. O mesmo vale para `_vendor_id` por item em `order_itemmeta`. O schema está pronto para algo que o código bloqueia — deixar assim é decisão consciente, mas não está registrada em lugar nenhum.

4. **Nenhuma FK, em nenhum lugar.** Adicionar constraints a posteriori é possível (tudo é InnoDB) mas exigiria limpar órfãos antes e sobreviver ao `dbDelta()`, que não as preserva. Vale ao menos decidir se os checadores de integridade em PHP devem virar rotina agendada em vez de ferramenta de diagnóstico.

5. **HPOS** — as tabelas existem e estão vazias. Alguém precisa decidir se a migração acontece ou se elas são descartadas; enquanto estiverem ali, qualquer plugin ou consulta que assuma HPOS lê vazio sem erro.

6. **`wp_woocommerce_log` com 25.640 linhas (11,5 MB)** é a terceira maior tabela do banco, atrás só de `wp_postmeta` e `wp_options`. Não tem retenção configurada.

7. **Volume local não representa produção.** As contagens deste documento vêm do banco local (4 usuários, 4 pedidos). O banco de produção foi zerado; os dados históricos estão só nos dumps. Não use estes números para dimensionar índice ou consulta.

---

## Como reproduzir

```bash
cd papelito-wordpress && docker compose up -d db

# inventário
docker exec papelito-db mariadb -uroot -proot_local_123 -e "
  SELECT table_name, engine, table_rows
  FROM information_schema.tables
  WHERE table_schema='papelito_local' ORDER BY table_name;"

# confirmar que continua sem FK
docker exec papelito-db mariadb -uroot -proot_local_123 -e "
  SELECT COUNT(*) AS fks FROM information_schema.key_column_usage
  WHERE table_schema='papelito_local' AND referenced_table_name IS NOT NULL;"

# DDL de uma tabela do domínio
docker exec papelito-db mariadb -uroot -proot_local_123 papelito_local \
  -e "SHOW CREATE TABLE wp_papelito_companies\G"
```

O diagrama abre em [excalidraw.com](https://excalidraw.com) (*Open* → selecione o `.excalidraw`) ou na extensão Excalidraw do VS Code.

### Como o `.excalidraw` foi validado

O arquivo não é um JSON aproximado do formato: ele passou pelo pipeline oficial do pacote **`@excalidraw/excalidraw@0.18.1`**, e é a saída do serializador oficial que está em disco.

| Etapa | Função oficial | Resultado |
|---|---|---|
| Abrir como o site abre | `loadFromBlob()` — a mesma função que o excalidraw.com chama no *Open*, e a que emite “File not compatible” | aceito, 1.305 elementos |
| Normalizar | `restore(..., { repairBindings: true })` | 1.305 → 1.305, **0 descartados, 0 marcados `isDeleted`**; gerou os `index` (fractional indexing) |
| Gravar | `serializeAsJSON(elements, appState, files, "local")` | é este o conteúdo do arquivo |
| Round-trip | `serialize(restore(arquivo)) === arquivo` | **byte a byte idêntico** — a normalização é idempotente |
| Renderizar | `exportToSvg()` | SVG de 527 KB, `viewBox 0 0 5740 4054`, 1.081 `<text>` (= o total de elementos de texto) |

O `restore()` detectou dois elementos de texto **vazios** na primeira rodada e os descartou — eram linhas em branco do painel-hub. A origem foi corrigida e o gerador agora falha se alguém tentar emitir texto vazio.
