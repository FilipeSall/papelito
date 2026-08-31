# Invariantes do backend

As regras de negócio do produto estão em [`../../../docs/business-rules.md`](../../../docs/business-rules.md). Este documento é a versão **implementada**: nome de função, comportamento de borda e detalhe de SQL. Serve para quem vai mexer no código ou escrever teste.

## Mapa fluxo → função

Todos os arquivos em `public_html/wp-content/plugins/plugin_papelito/includes/`.

| Fluxo | Funções reais | Camada |
|---|---|---|
| Cadastro BR | `auth_endpoints.php` — `papelito_auth_validate_register_payload`, `_seller_register_payload`, `papelito_auth_create_registered_user`/`_seller` | WP |
| Verificação de e-mail | `auth_endpoints.php` — token sha256, expiração de 24 h, rate limit, bloqueio de login por `wp_authenticate_user` | WP |
| Google OAuth | `auth_endpoints.php` — `papelito_auth_verify_google_id_token`, `papelito_auth_find_or_create_google_user` | mock HTTP + WP |
| Validações puras | `papelito_auth_is_valid_cep`, `papelito_auth_normalize_phone`, `papelito_auth_format_phone`; `papelito_validate_cpf` / `papelito_calculate_cpf_digit` / `papelito_revendedor_validate_cnpj` (`revendedor_application.php`); `cnpj_validation.php` | puro |
| Cobertura por CEP | `rest_api.php` — `papelito_sellers_by_cep`, `papelito_coverage_vendors`, `papelito_coverage_products`; `products_filter.php` — `papelito_matching_vendor_ids` | puro (regra de faixa) + WP |
| Estoque | `vendor_stock.php` — `papelito_get/set/adjust_vendor_stock`, `papelito_vendors_with_stock`, `papelito_vendor_stock_query` | WP, SQL real |
| Vendor ativo | `active_vendor.php` — `papelito_validate_active_vendor`, `papelito_set_active_vendor`, `papelito_resolve_default_vendor_id`, `papelito_available_vendors_for_user` | WP |
| Geocodificação | `vendor_geo.php` — `papelito_geocode_cep`, `papelito_haversine_km`, `papelito_normalize_cep` | puro + mock HTTP |
| Notificações e mensagens | `notifications.php`, `vendor_messaging.php` | WP, SQL real |
| Cupons / flash sale / roteamento | `coupons.php`, `flash_sale.php`, `order_routing.php`, `pagarme_*` | puro (normalizadores) + WP |
| Empresa / B2B | `company_*`, `cnpj_*`, `customer_identity.php` — `papelito_company_purchase_capability()` é a única política de compra | WP |
| Frete e rastreamento | `shipping.php`, `correios_prepostage.php`, `correios_tracking.php` | WP + HTTP externo |

## Taxonomia Papelito

- Produto publicado aparece na vitrine somente com categoria principal ativa, via
  `papelito_taxonomy_classified_clause()`.
- Subcategoria é opcional; cada uma precisa pertencer à categoria principal. A operação composta é
  `papelito_product_replace_taxonomy()` e é atômica.
- Slug de categoria/subcategoria com produto vinculado é imutável; desativação exige reclassificação.
- `papelito_taxonomy_exists_clause()` faz OR dentro da faceta e AND entre facetas distintas; filtro que não
  resolve é sempre vazio.

## Validações brasileiras

1. `papelito_auth_is_valid_cep` aceita **duas** formas: `\d{5}-?\d{3}` **e** `\d{2}\.\d{3}-\d{3}`. Rejeita o resto.
2. `papelito_auth_normalize_phone` remove não-dígitos e **descarta o prefixo `55` quando o total tem 12 ou 13 dígitos**.
3. `papelito_auth_format_phone`: 11 dígitos → `(XX) XXXXX-XXXX`; 10 → `(XX) XXXX-XXXX`; caso contrário devolve sanitizado.
4. `papelito_validate_cpf` valida pelos dígitos verificadores (pesos 10 e 11); rejeita comprimento ≠ 11 e sequências repetidas.
5. `papelito_normalize_cep` extrai 8 dígitos ou devolve `''`.
6. `papelito_normalize_cnpj` faz uppercase e mantém `[A-Z0-9]` em 14 posições — **nunca usa `\D`**, o que quebraria CNPJ alfanumérico.
7. `papelito_validate_cnpj` calcula o DV oficial para numérico **e** alfanumérico, com peso sobre o valor `ASCII − 48`. **Independe de feature flag**: validade estrutural ≠ aceitação. **Todo gate de CNPJ do fluxo de revendedor/vendor passa por ele**, via `papelito_revendedor_validate_cnpj` (`revendedor_application.php`), que exige a máscara `00.000.000/0000-00` e delega o DV. Nenhum endpoint de vendor pode voltar a validar só o formato — `PAPELITO_VENDOR_CNPJ_PATTERN` só pode ser referenciado dentro desse helper, e `test-vendor-cnpj-validation.php` falha se outro ponto o usar.
8. `papelito_cnpj_is_alphanumeric` apenas classifica; não decide aceitação.
9. `papelito_validate_cep_format` verifica **só formato**. Existência remota é separada, com três estados distintos: `cep_invalid`, `cep_not_found`, `cep_provider_unavailable`.

## Cadastro e verificação de e-mail

10. `papelito_auth_validate_register_payload` exige e-mail válido, senha ≥ 8, nome e sobrenome, telefone de 10–11 dígitos, CEP e estado; **CNPJ opcional**. Seller exige adicionalmente `store_name`, CNPJ, cidade/estado, instagram, `min_cep`/`max_cep` e `has_sold`.
11. O e-mail nasce `pending`. O token é **sha256 do valor em claro**, de **uso único**, e expira em **24 h**.
12. Cadastro por convite e reenvio usam rate limit por identidade opaca (token ou e-mail), para não compartilhar o IP do proxy Next; reenvio tem cooldown de 1 min.
13. Login por senha é **bloqueado** até `papelito_email_verification_status = 'verified'`, pelo hook `wp_authenticate_user`. O gate recusa **qualquer valor que não seja exatamente `'verified'`**. Status vazio (`''`) é usuário legado e não exige verificação.

## Google OAuth

14. `papelito_auth_verify_google_id_token` exige **HTTP 200, `email_verified = true` e `aud == PAPELITO_GOOGLE_CLIENT_ID`**; senão `WP_Error` (`papelito_invalid_token` / `papelito_unverified_email`). Falha de rede → `papelito_google_unreachable`.
15. `find_or_create_google_user` faz account linking por `google_sub` **ou** e-mail; usuário novo nasce com `papelito_profile_complete = 0`.

## Cobertura regional

16. Um vendor cobre um CEP se existe índice `i` tal que `min_cep[i] <= user_cep <= max_cep[i]` — as faixas são **arrays serializados** em usermeta.
17. **Dupla aprovação para aparecer em cobertura**: role `seller` **e** `application_status = 'approved'`.
18. `coverage/products` devolve, por produto, `has_coverage`, `best_vendor` e `alternatives`.
19. A resposta é cacheada em transient versionado por `papelito_coverage_cache_version` (~5 min) e invalidada por `papelito_vendor_stock_changed` **ou** por mudança nas metas de cobertura: `min_cep`, `max_cep`, `cep`, `cep_lat`, `cep_lng`, `store_name`, `city`, `state`, `application_status`, `shipping_lead_time_days`.
20. **Com `vendor_id` informado, checa só aquele vendor por faixa + estoque, sem geocodificar** — invariante de performance, não otimização opcional.
21. Cadeia de geocodificação: **BrasilAPI → ViaCEP → Nominatim**. Cache em `papelito_cep_geo_{cep}`: 30 dias em acerto, 1 dia em erro.
22. Faixas de CEP não podem se sobrepor. O `id` de uma faixa deriva do `min_cep`, **não** de índice posicional.

## Estoque

23. `papelito_set_vendor_stock` faz UPSERT (`INSERT ... ON DUPLICATE KEY UPDATE`); `papelito_adjust_vendor_stock` incrementa/decrementa e grava log com `reason` prefixada.
24. A transição de `qty > 0` para `0` dispara `papelito_stock_zeroed` — **e só quando `notified_zero_at IS NULL`**. A volta a `> 0` limpa o marcador; `0 → 0` não dispara nada.
25. Ajuste concorrente usa `SELECT ... FOR UPDATE` em transação, rejeita saldo negativo e **reverte reserva parcial** de pedidos multi-linha.
26. Qualquer alteração dispara `papelito_vendor_stock_changed`, que invalida a cobertura.
27. `papelito_order_routing_resolve_items` rejeita linha com quantidade acima do estoque, devolvendo **409** com produto, disponível e solicitado.
28. Decremento por pedido é idempotente via `_papelito_stock_decremented`.

### Armadilhas de SQL em `papelito_vendor_stock_query`

Quatro erros já cometidos nessa query, todos com sintoma silencioso:

- `count_sql` é lido com `$wpdb->get_var()`. Com `GROUP BY p.ID` + `COUNT(*)` o retorno é ~1 e a paginação quebra. **Use `COUNT(DISTINCT p.ID)`.**
- `product_variation` não tem relação de termos própria. JOIN por `tr.object_id = p.ID` **derruba todas as variações**. Use o id efetivo: `COALESCE(NULLIF(p.post_parent,0), p.ID)`.
- Toda ordenação precisa de **desempate estável por `p.ID`**, senão a paginação duplica ou pula linhas.
- `updated_desc` precisa tratar NULL: `ORDER BY vs.updated_at IS NULL, vs.updated_at DESC, p.ID ASC`.

Além disso: a query parte de `FROM wp_posts p LEFT JOIN papelito_vendor_stock vs ... AND vs.vendor_id = %d`. **Nenhum JOIN restringe a "produtos do vendor"** — o painel mostra o catálogo global com o estoque sobreposto, e `duplicate_products_for_vendor` em `products_filter.php` virou no-op. O parâmetro `tags` **não pode** usar o `'type' => 'array'` padrão do WP (não faz parse de CSV): precisa de `sanitize_callback` com `explode`/`intval`/`array_filter`.

## Vendor ativo

29. O default é o vendor **mais próximo com estoque**, com resolução **lazy** (não persiste até o usuário interagir).
30. `papelito_validate_active_vendor` exige cobertura do CEP, `approved` e role `seller`.
31. `papelito_set_active_vendor` dispara `papelito_active_vendor_changed($user_id, $prev_id, $new_id)`.

## Empresa e compra

32. `papelito_company_purchase_capability()` é a **única** política de compra: centraliza `canPurchase`, `purchaseMode`, motivo estável, onboarding e `userContextType`.
33. A classificação usa **capabilities e roles, nunca a role primária**. Precedência: `hybrid` → `internal_admin` → `vendor` → `customer`.
34. Aprovação do primeiro owner exige CNPJ reconsultado e explicitamente `active` **nas últimas 24 h**.
35. Falha técnica de provedor é `unavailable`, **nunca** `active` nem `inactive`. Divergência entre provedores é `conflict`.
36. Provedor que não suporta CNPJ alfanumérico é marcado `provider_unsupported` — **nunca `invalid`**.
37. Orçamento síncrono global de **6 s**, ~3 s por provedor, **sem retry no caminho síncrono**.
38. Toda mutação empresarial recarrega empresa e membership do banco e exige `Idempotency-Key` durável.
39. Transferência de titularidade e decisões de candidatura usam `SELECT ... FOR UPDATE`.

## Pagamento

40. Pagamento **direto ao vendor, sem split de receita**: `split` do PSP com recebedor único a 100%, `liable: true`, taxas no vendor.
41. Vender exige **dupla aprovação**: regional (CEP) **e** recebedor Pagar.me `active`. `place-order` valida as duas.
42. `POST /checkout/place-order` cria o pedido Woo, reserva estoque e chama a Pagar.me. Testar sucesso, erro de pagamento e rollback de reserva.
43. O webhook trata `order.*` e `charge.*`. **`charge.*` pode chegar sem `order_id`** — a busca precisa cair para o postmeta `_papelito_pagarme_charge_id`.
44. A reconciliação por WP-Cron libera reserva de pagamentos terminais não pagos e expirados, **sem restocar pedidos pagos ou processados**.
45. Nenhum pedido B2B chama `papelito_pagarme_resolve_customer_document()` nem lê documento fiscal de usermeta.

## Rastreamento

46. Somente `BDE/01` conclui entrega. O pedido projeta `entregue` só quando **todas** as remessas ativas estão entregues.
47. Evento desconhecido é armazenado mas **não altera estado**.
48. Evento antigo permanece auditável mas **não regride a projeção**.
49. Um S10 não pode estar em dois pedidos.
50. `manual_fallback_eligible=1` só é gravado com `creation_outcome=not_created` **e** código na allowlist. **Status HTTP nunca libera fallback sozinho.**

## Notificações

51. `papelito_dispatch_notification($user_id, $type, $payload)` é o único ponto de escrita; o filtro `papelito_should_dispatch_notification` permite suprimir.
52. `favorite_on_promo` é pulado para quem já tem notificação não lida do mesmo produto.
53. `papelito_product_on_promo` é emitido apenas na transição `non-publish → publish` de um cupom.
54. Marcar como lida decrementa o contador **só se estava não lida**.

## Erros padronizados

55. Erros retornam `WP_Error` com código `papelito_*` e o status HTTP em `get_error_data()['status']`. Teste deve afirmar `get_error_code()` **e** o status. Catálogo em [`../../../docs/integration-contracts.md`](../../../docs/integration-contracts.md#catálogo-de-erros-papelito_).

## Ao refatorar

Duas regras que evitaram estrago antes:

- **Cubra com teste antes de mover a lógica.** Nunca altere endpoint, shape de resposta ou código de erro observável pelo frontend durante um refactor.
- **Mocke somente a fronteira**: HTTP externo, relógio, JWT e — em teste unitário — o WordPress. **A regra de negócio nunca é mockada.**

E um argumento que vale lembrar: **não mocke `$wpdb` onde o SQL É a regra de negócio** (UPSERT de estoque, `FOR UPDATE`, JOIN de `users`⋈`usermeta`, `min_cep[]` serializado). Na direção oposta, não suba o WordPress inteiro para testar `papelito_validate_cpf()`. Costuras úteis quando a testabilidade for o objetivo: `Clock`, verificador de token do Google, repositório de estoque, comparador de faixa de CEP — mantendo as funções `papelito_*` como adaptadores finos.
