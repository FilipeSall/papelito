# Modelo de dados

Tabelas customizadas, metadados e criptografia. As decisões de "quem é fonte de verdade do quê" estão em [`../../../docs/architecture.md`](../../../docs/architecture.md#fonte-de-verdade-dos-dados).

## Convenções

- Todas as tabelas usam o prefixo do `$wpdb` (ex.: `wp_`).
- **Sem chaves estrangeiras físicas**: índices + validação em código.
- Criação por `dbDelta`, seguindo o padrão de `vendor_stock.php`: constante → função `*_table_names()` com `$wpdb->prefix` → instalador.
- Os instaladores são registrados em `papelito_maybe_migrate_db()`, controlado por `PAPELITO_DB_VERSION`. **O valor corrente vive em `plugin_papelito.php` e é a única fonte** — não replique o número em documentação, porque ele sobe a cada migração e seis documentos já ficaram desatualizados por isso:

  ```bash
  grep PAPELITO_DB_VERSION public_html/wp-content/plugins/plugin_papelito/plugin_papelito.php
  ```
- **Adicionar migração exige bumpar `PAPELITO_DB_VERSION`.** Sem o bump, `papelito_maybe_migrate_db()` não roda o instalador novo e o schema fica atrás do código, silenciosamente.
- **Migração é idempotente** — rodar duas vezes não duplica nem falha, porque `dbDelta` é declarativo.
- **Desligar feature flag NÃO remove tabela.** O schema é aditivo; nenhuma tabela existente é alterada por uma flag.

Antes de aplicar migração em produção: backup completo do banco e validação em ambiente equivalente. Restaurar backup só em caso de falha comprovada de migração.

## Taxonomia de produtos Papelito

`wp_papelito_categories` guarda a categoria principal, e `wp_papelito_subcategories` guarda facetas
opcionais. Os vínculos usam `wp_papelito_product_category` (PK em `product_id`, portanto no máximo uma
categoria) e `wp_papelito_product_subcategory`. O catálogo headless lê somente essas tabelas; termos
`product_cat` do WooCommerce permanecem inertes por compatibilidade com a loja WP legada.

`papelito_product_replace_taxonomy()` é o writer atômico. Categoria inativa ou arquivada não recebe
produto; trocar categoria limpa as subcategorias antigas. Filtros aplicam OR dentro da mesma faceta e AND
entre facetas.

## Benefícios da página de produto

Três tabelas: `wp_papelito_benefit_groups` (configuração nomeada, com `is_global`, `global_key` e `is_active`),
`wp_papelito_benefit_items` (ícone, título, texto auxiliar, `sort_order`, `is_active`) e
`wp_papelito_benefit_group_targets` (a quem a configuração se aplica).

`global_key` é nulo para grupos específicos e vale `1` no grupo global. A chave única dessa coluna
é a garantia no banco de que nunca existem dois grupos globais; a instalação normaliza bases legadas
mantendo o grupo global de menor `id`.

A invariante central é a **PK `(target_type, target_key)`** da tabela de alvos: um produto, uma coleção
ou uma categoria pertence a no máximo um grupo — garantido pelo banco, como a PK em `product_id` garante
"no máximo uma categoria". Sem ela a precedência precisaria de um desempate arbitrário dentro do mesmo
nível, e por isso **não existe coluna de prioridade**.

`papelito_product_benefits_resolve()` responde `produto > coleção > categoria > global`, parando no
primeiro grupo ativo; duas coleções desempatam pela ordem de `papelito_curated_collections()`. Não há
cadeia de fallback: grupo vencedor sem item ativo resulta em faixa vazia, de propósito, mas mantém a
origem do grupo vencedor.

O ícone é `emoji` ou `svg` — nunca HTML. `description_content` guarda o mesmo documento de nós tipados
da faixa de avisos, e `description` é o texto plano de degradação quando um token não resolve.

O grupo global é criado por `papelito_product_benefits_seed_global()` no bootstrap de migration, só se
ainda não existir, com os três benefícios que a página de produto exibia fixos. O cache é um transient
único versionado por `papelito_product_benefits_version`.

## Tabelas do marketplace

### `wp_papelito_vendor_stock`

```sql
CREATE TABLE wp_papelito_vendor_stock (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  notified_zero_at DATETIME NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vendor_product (vendor_id, product_id),
  KEY idx_product (product_id)
);
```

`notified_zero_at` **não é log** — é o controle anti-spam da notificação de estoque zerado. Ver [context/business-rules.md](business-rules.md#estoque).

### `wp_papelito_vendor_stock_log`

```sql
CREATE TABLE wp_papelito_vendor_stock_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(120) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vendor (vendor_id, created_at)
);
```

`reason` é sempre prefixada: `vendor_update:`, `admin_adjustment:<motivo>`, `order_decrement:#<pedido>`.

### `wp_papelito_notifications`

```sql
CREATE TABLE wp_papelito_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  payload LONGTEXT NULL,
  read_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_unread (user_id, read_at),
  KEY idx_user_created (user_id, created_at)
);
```

### `wp_papelito_messages`

```sql
CREATE TABLE wp_papelito_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  recipient_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_thread (thread_id, created_at),
  KEY idx_order (order_id)
);
```

Uma thread por pedido.

### Logística

- **`wp_papelito_shipments`** — associação **imutável** entre pedido, vendor, pré-postagem e código S10. Colunas de tentativa: `generation_status`, `idempotency_key`, `active`, `last_error_code`, `creation_outcome` (`not_created|created|uncertain`), `manual_fallback_eligible` (`0|1`, default `0`), `manual_fallback_consumed_at`, `is_test` (`0|1`, default `0`, **marca imutável** de remessa sem validade postal).
- **`wp_papelito_tracking_events`** — evento bruto, origem e **fingerprint idempotente**; duplicatas recusadas por chave única.

Registros anteriores à migração recebem `manual_fallback_eligible=0` — **nenhuma falha histórica se torna elegível por inferência**.

### Recibo interno

Três tabelas, criadas junto com a fundação do recibo numerado. **Nenhuma delas guarda nota fiscal** — o recibo é documento interno do marketplace.

- **`wp_papelito_receipt_sequences`** — `sequence_year` (PK) + `next_sequence`. Uma linha por ano. A alocação é `SELECT ... FOR UPDATE` na linha do ano, **dentro da mesma transação** que grava o recibo. É o que garante numeração sem furo nem duplicata sob concorrência.
- **`wp_papelito_receipts`** — um recibo por pedido (`UNIQUE order_id`), numerado `PPL-AAAA-NNNNNN` (`UNIQUE receipt_number`). Guarda comprador, empresa/CNPJ do snapshot B2B, método e estado do pagamento, os quatro valores em centavos e o `snapshot_json` completo. `origin` distingue `payment` de `backfill`.
- **`wp_papelito_receipt_vendor_parts`** — parcela por vendor, `UNIQUE (receipt_id, vendor_id)`. Hoje sempre uma linha, porque o checkout recusa carrinho misto; o modelo já suporta N.

**O recibo é imutável.** Alterar o `WC_Order` depois da emissão não muda `snapshot_json` nem nenhum campo `*_cents`. A soma das parcelas bate **exatamente** com o total do recibo — o frete é repartido por `papelito_receipt_allocate_cents()`, que dá o resto à última parcela.

O CNPJ vem de `_papelito_company_cnpj` do pedido, **nunca de `wp_usermeta`**.

### Documentos fiscais

Três tabelas de fundação. **A Papelito não emite nota**: o vendor emite por fora e anexa. Nesta camada não há rota REST, UI nem leitura de `doc_status` por pagamento ou fulfillment.

- **`wp_papelito_fiscal_documents`** — um documento **corrente** por `(order_id, vendor_id)`. A corrente tem `is_current = 1`; versões substituídas viram `is_current = NULL`, **nunca `0`**, e é isso que permite N históricos sob `UNIQUE (order_id, vendor_id, is_current)` — MySQL não compara NULLs em índice único. Guarda tipo, status, nível de validação, chave normalizada, emitente, emissão, valor em centavos, `flags_json` e as relações de substituição/cancelamento.
- **`wp_papelito_fiscal_document_files`** — um arquivo **ativo** por papel (`danfe_pdf`, `xml`, `other`), pelo mesmo truque: `UNIQUE (fiscal_document_id, role, is_active)` com `is_active = NULL` no soft-delete. Guarda storage key, nome original, MIME, tamanho e SHA-256.
- **`wp_papelito_fiscal_document_events`** — trilha imutável. Só insert. O detalhe passa por `papelito_fiscal_event_safe_detail()`, que **descarta PII, conteúdo e nome original** e reduz a chave de acesso aos quatro últimos dígitos.

> **`access_key` tem índice não único de propósito.** Chave duplicada é sinalização administrativa, não erro de banco: um `UNIQUE` transformaria duplicidade esperada em 500. A busca é `papelito_fiscal_documents_by_access_key()`.

Os arquivos vivem em `PAPELITO_PRIVATE_FISCAL_DOCUMENTS_DIR`, fora do webroot, 0600 em diretório 0700, com nome de 64 hex. Não há purga automática nem endpoint que apague: descarte é operação manual documentada.

### Nunca criada

`wp_papelito_invoices` foi projetada para armazenar NF-e por pedido/vendor e **nunca foi criada** — a fundação acima a substituiu, com nome e modelo diferentes.

## Tabelas B2B

Relacionamento:

```
wp_users
  1 ─── 0..1  papelito_customer_profiles         (PK user_id)
  N ───  N    papelito_company_members   N ─── 1  papelito_companies
papelito_companies
  1 ─── N     papelito_company_invitations
  1 ─── N     papelito_company_audit_log
  1 ─── N     papelito_company_owner_applications
```

**Cardinalidade: muitos-para-muitos** usuário ↔ empresa (decisão do cliente). Um CPF pode estar em mais de uma empresa; a unicidade é no par `(company_id, user_id)`. A empresa ativa da sessão é resolvida explicitamente.

**Não existe uma segunda tabela de autenticação.** `wp_users` continua sendo a identidade; as tabelas abaixo só complementam.

### `papelito_customer_profiles`

| Coluna | Tipo | Notas |
|---|---|---|
| `user_id` | BIGINT UNSIGNED, PK | 1:1 com `wp_users` |
| `cpf_hmac` | CHAR(64), UNIQUE | HMAC-SHA256 determinístico para busca e unicidade |
| `cpf_ciphertext` | LONGTEXT | envelope versionado AES-256-GCM |
| `cpf_last4` | CHAR(4) | exibição mascarada |
| `identity_status` | VARCHAR(32), default `pending` | `incomplete`/`pending`/`verified`/`rejected`/`suspended` |
| `identity_method` | VARCHAR(32) NULL | `email_verified`/`manual_review`/`datavalid`/`e_cnpj`/`legacy_migrated` |
| `identity_checked_at` | DATETIME NULL | |
| `created_at`/`updated_at` | DATETIME | |

**Não há `birth_date_ciphertext` nesta tabela.** A data de nascimento é sinal de verificação de responsável, não dado cadastral do perfil. A única tabela que a persiste é `papelito_company_pre_account_applications`, e só enquanto a candidatura estiver viva — ver [Candidatura empresarial pré-conta](#candidatura-empresarial-pré-conta).

### `papelito_companies`

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED, PK AI | |
| `cnpj` | **CHAR(14) CHARACTER SET ascii COLLATE ascii_bin**, UNIQUE | canônico maiúsculo; 12 alfanuméricos + 2 dígitos. `ascii_bin` para ser **case-sensitive** |
| `legal_name` | VARCHAR(255) | |
| `trade_name` | VARCHAR(255) NULL | |
| `billing_email` | VARCHAR(191) | pode diferir do e-mail do owner; editável por owner/admin. **Sempre gravado na forma de `papelito_normalize_email()`** (trim + caixa baixa) — comparações downstream lowercaseiam, e gravar com a caixa original fazia o mesmo endereço parecer dois |
| `billing_email_verified_at` | DATETIME NULL | preenchido = confirmado. Nasce preenchido quando o endereço é igual ao e-mail da conta e a conta vale como verificada |
| `pending_billing_email` | VARCHAR(191) NULL | endereço aguardando confirmação. `billing_email` mantém o último valor confirmado até o token ser consumido |
| `pending_billing_email_token_hash` | CHAR(64) NULL | sha256 do token; o valor em claro só existe no e-mail. Sobrescrever invalida o link anterior |
| `pending_billing_email_expires_at` | DATETIME NULL | UTC, 24h. Expirado responde **410**, inexistente responde **404** |
| `billing_email_verification_sent_at` | DATETIME NULL | último envio; **não** é limpo na confirmação |
| `phone` | VARCHAR(24) NULL | |
| `registry_status` | VARCHAR(32), default `pending` | `pending`/`active`/`inactive`/`not_found`/`unavailable`/`provider_unsupported`/`conflict` |
| `ownership_status` | VARCHAR(32), default `pending` | `pending_evidence`/`pending_manual_review`/`verified`/`rejected` |
| `company_status` | VARCHAR(24), default `onboarding` | `onboarding`/`active`/`suspended`/`archived` |
| `verification_method` | VARCHAR(32) NULL | |
| `provider_source` | VARCHAR(32) NULL | |
| `provider_checked_at` | DATETIME NULL | |
| `provider_data_hash` | CHAR(64) NULL | hash da evidência, **não o QSA bruto** |
| `fiscal_*` | | `cep`, `state`, `city`, `neighborhood`, `street`, `number`, `complement` |
| `pagarme_customer_id` / `pagarme_customer_code` | VARCHAR NULL | |
| `owner_user_id` | BIGINT UNSIGNED NULL | owner ativo canônico |
| `created_by_user_id` | BIGINT UNSIGNED | **≠ owner** — quem criou o registro |
| `verified_by_user_id` / `verified_at` | | |
| `created_at`/`updated_at` | | |

Índices: `UNIQUE uniq_cnpj (cnpj)`, `idx_registry_status`, `idx_ownership_status`, `idx_company_status`.

### `papelito_company_members`

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED, PK AI | |
| `company_id` / `user_id` | BIGINT UNSIGNED | |
| `member_role` | VARCHAR(24), default `buyer` | `owner`/`admin`/`buyer`/`viewer` |
| `member_status` | VARCHAR(32), default `pending_company_approval` | `pending_identity`/`pending_company_approval`/`active`/`rejected`/`suspended`/`revoked`/`expired` |
| `requested_at`, `invited_by_user_id`, `approved_by_user_id`, `approved_at`, `rejected_at`, `rejected_reason`, `expires_at` | | |
| `membership_origin`, `request_count`, `last_request_at`, `rejected_by_user_id`, `suspended_at`/`suspended_by_user_id`, `revoked_at`/`revoked_by_user_id`, `role_changed_at`/`role_changed_by_user_id` | | colunas aditivas de auditoria — use-as em vez de inferir histórico |

Índices: `UNIQUE uniq_company_user (company_id, user_id)`, `idx_user_status`, `idx_company_status`, **`idx_company_role_status (company_id, member_role, member_status)`** — este último é o que apoia a consistência "exatamente um owner ativo", mantida por código transacional.

### `papelito_company_invitations`

| Coluna | Notas |
|---|---|
| `invited_email` | |
| `invited_cpf_hmac` | CHAR(64) NULL — trava o convite a um CPF |
| `invited_role` | default `buyer`; **nunca `owner`** |
| `token_hash` | CHAR(64), UNIQUE — **só o hash**; o token nunca é persistido em claro |
| `invitation_status` | `pending`/`accepted`/`revoked`/`expired` |
| `expires_at` | default +7 dias, configurável |
| `accepted_at`, `accepted_by_user_id`, `revoked_at`, `revoked_by_user_id`, `revoked_reason`, `resent_at`, `resend_count` | reenvio invalida o token anterior |

### `papelito_company_audit_log`

| Coluna | Notas |
|---|---|
| `company_id`, `actor_user_id`, `action`, `created_at` | `action` ex.: `company_created`, `member_invited`, `role_changed` |
| `payload_json` | LONGTEXT NULL — **sem PII**: nada de CPF, data de nascimento, QSA completo, token ou resposta de provedor |

Índice `idx_company_created (company_id, created_at)`. Append-only.

### `papelito_account_status_log`

Histórico de suspensão e reativação de **conta**. Existe separado de `papelito_company_audit_log`
porque aquele é escopado por empresa (`company_id NOT NULL`) e a suspensão é um estado da pessoa,
que pode não ter empresa nenhuma.

| Coluna | Notas |
|---|---|
| `user_id`, `actor_user_id`, `created_at` | quem foi suspenso, quem suspendeu, quando |
| `action` | `suspend` ou `reactivate` |
| `reason` | TEXT NULL — justificativa; obrigatória na suspensão, opcional na reativação |

Índice `idx_user_created (user_id, created_at)`. Append-only.

O estado corrente **não** mora nessa tabela: vive em usermeta, para a leitura ser barata no caminho
de compra e de venda.

| Usermeta | Conteúdo |
|---|---|
| `papelito_account_status` | `suspended` quando suspensa; ausente ou `active` caso contrário |
| `papelito_account_suspension_reason` | justificativa vigente |
| `papelito_account_suspension_at` | data/hora UTC da suspensão vigente |
| `papelito_account_suspension_actor` | id do administrador que suspendeu |

A reativação apaga as três últimas e devolve `papelito_account_status` para `active` — o histórico
permanece só no log.

### Candidatura empresarial pré-conta

`papelito_company_pre_account_applications` é a tabela do cadastro empresarial vigente. Ela existe porque **a candidatura não pode criar conta**: antes da aprovação administrativa não há `wp_user`, empresa, membership nem sessão para o candidato.

Guarda os dados pessoais cifrados necessários à análise (contato, nome, telefone, CPF, **data de nascimento**, endereço, razão social), os HMAC determinísticos de e-mail e CPF, o hash da senha escolhida no cadastro, a evidência mínima do provedor (**sem QSA bruto**), os metadados do arquivo privado e a decisão administrativa.

`created_user_id`, `created_company_id` e `created_membership_id` só são preenchidos na aprovação, que é quem cria esses recursos. Candidatura `document_required` ou `pending_manual_review` nunca referencia nenhum deles.

**Estados**: `document_required` → `pending_manual_review` → `approved` | `rejected`, mais `expired` pela varredura de retenção.

**Restrições que carregam a lógica:**

- `uniq_open_cnpj (canonical_cnpj, is_open)` garante **uma candidatura aberta por CNPJ**. O truque é `is_open` ser `1` enquanto aberta e **`NULL` quando fechada**: em MySQL, valores `NULL` não colidem em índice único, então candidaturas encerradas não impedem uma recandidatura para o mesmo CNPJ. Não troque `NULL` por `0`.
- `idx_resume_token (resume_token_hash)` — a retomada é por token opaco de 32 bytes guardado em cookie `__Host-`, nunca por sessão.
- `idx_status_expires (application_status, expires_at)` serve à varredura de retenção.

**Retenção.** `papelito_pre_account_applications_sweep()` roda de hora em hora: fecha as abertas vencidas (`expired`) e, passado o TTL de `PAPELITO_PRE_ACCOUNT_APPLICATION_TTL_DAYS`, apaga o documento e zera toda a PII reversível — colunas cifradas, `password_hash`, `resume_token_hash` e `evidence_json`. Sobra um registro auditável sem dado pessoal: CNPJ, decisão, administrador e IDs criados. O documento também é apagado **na decisão**, sem esperar o TTL. Colunas cifradas são `NOT NULL`: o purge grava string vazia, não `NULL`, e usa `password_hash IS NULL` como sentinela de "já purgada".

#### Relação com `papelito_company_owner_applications`

A tabela antiga **continua existindo e em uso** para o caminho em que o usuário **já tem conta** e envia documento de responsável por `POST /companies/current/owner-document`. As duas convivem de propósito, com filas administrativas separadas:

| | `pre_account_applications` | `owner_applications` |
|---|---|---|
| Pré-requisito | nenhum — candidato sem conta | `wp_user` existente |
| Entrada | `POST /company-applications` | `POST /companies/current/owner-document` |
| Fila admin | `GET /admin/pre-account-applications` | `GET /admin/owner-applications` |
| `applicationId` | `pre:<id>` | numérico |

Ao aprovar, confira em qual fila a candidatura está: os IDs não são intercambiáveis.

### Outras tabelas B2B

`papelito_company_idempotency` (chaves de idempotência duráveis), `papelito_b2b_onboarding` (linha de onboarding retomável), `papelito_b2b_legacy_email_log` (campanhas de migração, unicidade em `(user_id, campaign, campaign_version)`).

## Metadados em `wp_usermeta`

**Customer (legado / conta)**: `store_name`, `phone_number`, `cnpj`, `instagram`, `state`, `city`, `cep`, `papelito_profile_complete`, `google_sub`, `papelito_auth_session_version`, `papelito_email_verification_status`, `papelito_email_verification_token_hash`, `papelito_email_verification_token_expires_at`, `papelito_email_verification_sent_at`, `papelito_email_verified_at`, `papelito_email_verification_method`, `papelito_email_verified_by`, `papelito_favorites_v1`, `papelito_active_vendor_id`.

**Vendor**: `min_cep[]`, `max_cep[]` (arrays **serializados**), `cep`, `cep_lat`, `cep_lng`, `shipping_lead_time_days`, `application_status` / `seller_application_status`, `application_rejection_reason`, `application_reviewed_by`, `application_reviewed_at`, `papelito_pagarme_recipient_id`, `papelito_pagarme_recipient_status`.

**B2B**: `papelito_b2b_required`, `papelito_b2b_active_company_id`, `papelito_b2b_onboarding_address_{cep,state,city,street,number,complement,neighborhood}`, e as sete metas de coorte legada listadas em [`../../../docs/flows/legacy-migration.md`](../../../docs/flows/legacy-migration.md#dados).

**Endereço e contato não têm origem única.** Cada fluxo grava no seu lugar e nenhum retrofita os outros: o vendor usa `seller_application_*`, o onboarding B2B usa `papelito_b2b_onboarding_address_*` e o cadastro pela empresa deixa telefone, CNPJ e endereço fiscal só em `wp_papelito_companies`. Leitura administrativa que precisa do dado consolidado percorre as três origens nessa ordem — é o que `papelito_admin_users_base_detail()` faz. Ler só o usermeta legado devolve vazio para conta B2B.

> `min_cep[]` / `max_cep[]` são **serializados** em usermeta. Factory de teste precisa passar por `update_user_meta`, não escrever direto.
>
> **`papelito_profile_complete` tem dois escritores** e passa a `'1'` na verificação de e-mail mesmo quando o onboarding B2B falhou. Não é autoridade sobre cadastro completo.
>
> **CPF/CNPJ em usermeta não participam de pedidos novos.** Existem só para inspeção e rollback.

## Postmeta de pedido

O snapshot fiscal B2B (22 chaves canônicas) está em [`../../../docs/flows/cart-and-checkout.md`](../../../docs/flows/cart-and-checkout.md#snapshot-fiscal). Além dele:

| Meta | Conteúdo |
|---|---|
| `_vendor_id` / `_vendor_name` | por **linha** do pedido (itemmeta) |
| `_papelito_pagarme_order_id` / `_papelito_pagarme_charge_id` | ids do PSP — o `charge_id` é o fallback de busca no webhook |
| `_papelito_stock_decremented` | idempotência do decremento |
| `_papelito_logistics_status` | projeção logística, separada do estado comercial |
| `_papelito_shipping_neighborhood` | bairro de destino, **só em pedidos novos** — antes era validado na cotação e nunca gravado |
| `_papelito_import_todo` | pendências por produto deixadas pela importação de catálogo |
| `_correios_tracking_code` | **legado**, do plugin Correios for WooCommerce 4.2.5 |

Options relevantes: `papelito_catalog_pdf_id` (override do catálogo em PDF), `papelito_coverage_cache_version` (versão do cache de cobertura), `papelito_shipping_free_shipping_minimum_cents` (mínimo do pedido para o cupom manual de frete grátis, em centavos, default `9900`).

`papelito_home_promo_marquee` e `papelito_home_features` guardam, além do texto puro, o conteúdo estruturado das faixas (`content` / `subtitleContent`): lista plana de nós `text`/`token`, sem HTML. O texto puro é derivado dos nós de texto e serve de fallback de leitura.

## Criptografia de PII

Em `customer_identity.php`:

- **HMAC determinístico** — `papelito_pii_hmac(value)` = HMAC-SHA256(valor normalizado, `PAPELITO_PII_LOOKUP_KEY`). Usado em `cpf_hmac` para busca e unicidade.
- **Cifra reversível** — AES-256-GCM com `PAPELITO_PII_ENCRYPTION_KEY`, envelope versionado:

  ```
  v<key_version>:<iv_base64>:<tag_base64>:<ciphertext_base64>
  ```

  IV de 12 bytes **aleatório por operação**, auth tag de 16 bytes, `PAPELITO_PII_KEY_VERSION` identificando a chave.
- **Guardas**: chave ausente, curta, inválida ou com o valor literal `change-me` → devolve `WP_Error` e **não grava nada em claro**. O valor sensível nunca é logado.
- `cpf_last4` é derivado para exibição mascarada.
- **QSA bruto nunca é persistido.**

Geração:

```bash
openssl rand -hex 32   # PAPELITO_PII_LOOKUP_KEY
openssl rand -hex 32   # PAPELITO_PII_ENCRYPTION_KEY
```

### Rotação — as duas chaves têm regras opostas

- **`PAPELITO_PII_ENCRYPTION_KEY`**: suba `PAPELITO_PII_KEY_VERSION`, **mantenha a chave anterior disponível** para decriptar registros antigos e recriptografe em background.
- **`PAPELITO_PII_LOOKUP_KEY`**: **nunca rotacione sem reindexar todos os `cpf_hmac`.** Trocar a chave muda todos os hashes de uma vez, e a busca por CPF para de encontrar qualquer registro.

Valores reais vivem apenas no `.env` (gitignorado) e no cofre/ambientes. O `.env.example` só tem placeholders `change-me`.
