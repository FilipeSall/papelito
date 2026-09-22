# Braspress no backend

O cliente Braspress é server-side e recebe a integração já resolvida para um vendor. Ele não consulta banco, não escolhe credencial e não aceita host vindo da requisição do customer.

## Base HTTP

`BRASPRESS_BASE_URL` é opcional. Quando ausente, o cliente usa:

```env
BRASPRESS_BASE_URL=https://api.braspress.com/
```

Os únicos valores aceitos são `https://api.braspress.com/` e a mesma URL sem a barra final. A homologação não está habilitada nesta fase por falta de credenciais.

A barra final é normalizada antes de acrescentar o path da cotação ou do tracking. HTTP, outro host, porta, userinfo, IP, subdomínio parecido e qualquer path/query adicional são recusados como `configuration_error`. A falha fecha somente a Braspress e nunca cai silenciosamente para produção.

Variável ausente significa produção; variável presente com valor vazio também é inválida e não é tratada como ausente.

A variável não é declarada no `.env.example`: a base é fixa em produção e não há homologação a configurar, inclusive no desenvolvimento local. `localhost`, loopback e qualquer URL local não são destinos Braspress aceitos. Os testes continuam usando executor falso e não fazem chamadas externas.

O transporte usa HTTPS com verificação TLS, timeout de 15 segundos, redirecionamento desativado e limite de resposta. Suítes standalone, CI e desenvolvimento normal usam executor falso; este runbook não autoriza chamadas reais.

## Embalagem física

`papelito_packaging_profiles_for_vendor()` lê somente perfis ativos e
`papelito_packaging_rules_for_vendor()` lê os overrides do vendor, sempre por consulta preparada.
`papelito_packaging_items_to_lines()` converte produtos WooCommerce para mm/g inteiros; para Kits,
as dimensões vêm da embalagem declarada e o peso é derivado dos componentes e brindes sem aplicar
limites legados dos Correios.

`papelito_packaging_braspress_package_filter()` resolve a menor caixa que comporta o conjunto pelos
três testes do modelo físico, respeita um override aplicável e monta uma única caixa. A saída leva
`weight_kg` com a tara, `volumes` igual à soma de `cubagem[].volumes`, dimensões em metros,
`approval_version`, `measurement_source=profile` e `physical_hash`. Ausência de perfil, dado físico
inválido ou carga que não cabe retorna `null`; não há fallback sintético, mínimo/máximo dos Correios
ou divisão em várias caixas.

`papelito_braspress_quote()` copia esse `physical_hash` para a opção bruta, permitindo que a
normalização altere o `fingerprint` quando a caixa mudar, sem expor o hash no envelope público.

## Snapshot da embalagem no pedido

`papelito_packaging_profile_snapshot()` expõe o `LogisticsSnapshot` da caixa cadastrada — a mesma
escolha que alimenta a cotação Braspress, agora com uma fonte só. `papelito_packaging_legacy_snapshot()`
faz o equivalente para o pacote sintético de volume único dos Correios.

**O pacote sintético mede dimensão em centímetro e peso em GRAMA.**
`papelito_shipping_add_product_to_package()` acumula com `wc_get_weight( …, 'g' )` e
`PAPELITO_SHIPPING_MAX_WEIGHT_G = 30000` confirma a unidade. Na conversão para o v1 só a dimensão
muda (`× 10`); tratar o peso como quilo gravaria um snapshot mil vezes mais pesado.

`papelito_order_routing_logistics_snapshot()` escolhe a fonte **pelo provider da opção aceita**, não
pela embalagem que estiver disponível: o snapshot descreve o que foi cotado e cobrado, e dar snapshot
de perfil a um pedido Correios registraria uma caixa que ninguém cotou. Na Braspress o
`physical_hash` reconstruído é conferido contra o `fingerprint` da opção por
`papelito_shipping_option_physical_hash_matches()`, que recalcula a assinatura pela mesma
`papelito_shipping_option_fingerprint()` que a emitiu — a fórmula não é repetida em dois lugares.

Divergência **grava assim mesmo**, com `verification: "mismatch"`, e dispara
`papelito_logistics_snapshot_mismatch`. Não gravar perderia a evidência exatamente no caso em que
algo estranho aconteceu, e deixaria a pré-postagem sem caixa nenhuma. O pedido nunca falha por isso:
o `409` continua sendo só de preço, prazo e fingerprint, que já roda antes.

`papelito_order_routing_store_logistics_snapshot()` grava `_papelito_logistics_snapshot` com
`wp_slash( wp_json_encode( … ) )` e `_papelito_logistics_physical_hash` como escalar. O `wp_slash` é
obrigatório: a API de meta aplica `wp_unslash()` no valor recebido, e sem ele toda barra do JSON some
e o `json_decode` volta `null`.

## Código estável da opção

`papelito_braspress_service_code()` traduz o modal contratado no `code` da opção bruta pelo mapa
`PAPELITO_BRASPRESS_SERVICE_CODE_BY_MODAL`, que hoje só mapeia `R` → `rodoviario`, e a `option_key`
resultante é `braspress:rodoviario`. **O código nomeia a modalidade, nunca a cotação.** Até
17/09/2026 ele era o `id` que a Braspress devolve em cada POST: a chave mudava a cada cotação e o
`place-order` só fechava enquanto o transient da cotação sobrevivesse — expirado o cache, a
recotação produzia outra chave e o checkout respondia `409 papelito_checkout_shipping_stale` para
sempre. O identificador de cada cotação continua viajando em `external_quote_id` e é persistido no
pedido em `_papelito_shipping_external_quote_id`; é ele que prova qual cotação gerou aquele preço.

Modal fora do mapa não vira payload: `papelito_braspress_build_quote_payload()` recusa com
`papelito_braspress_payload_invalid` antes de qualquer chamada, em vez de deixar a opção ser
descartada silenciosamente na normalização.

A chave estável **não** afrouxa a validação do `place-order`. Preço, prazo, validade e
`physical_hash` continuam presos ao `fingerprint`, e o `physical_hash` cobre o `vendor_id` do
`LogisticsSnapshot` — por isso a seleção de um vendor segue sem casar com a opção de outro, mesmo
agora que os dois publicam a mesma `option_key`. Pedido histórico com `braspress:<id da cotação>`
continua legível: `_papelito_shipping_option_key` é gravado e nunca relido para autorizar nada.

## Cache de cotação

`papelito_braspress_quote_at()` consulta o transient antes do POST e grava somente a opção
normalizada devolvida pelo adapter após uma resposta válida. A chave versionada é produzida por
`papelito_braspress_quote_cache_key()` com `papelito_shipping_cache_fingerprint()` e cobre
`vendor_id`, `id` e `configuration_version` da integração, o payload oficial inteiro,
`physical_hash` do pacote quando presente e a data civil de `America/Sao_Paulo`. Dois vendors ou
duas versões da mesma integração não compartilham entrada; a chave não contém usuário, senha,
token ou qualquer documento em claro.

O TTL é o menor entre `PAPELITO_BRASPRESS_QUOTE_CACHE_MAX_TTL` e os segundos restantes até
`23:59:59.999` de São Paulo. O cálculo é feito a partir do instante injetável da cotação e resulta
em TTL zero somente como decisão de não gravar: zero ou negativo nunca é persistido.
Assim, a cotação de 23:59 recebe um TTL positivo e curto, e a data civil seguinte usa outra chave.

Falhas de timeout, rede, `429`, outros `4xx`, `5xx`, resposta inválida, conta bloqueada e rota não
atendida não gravam cache; a próxima chamada tenta o transporte novamente. A action
`papelito_braspress_quote_cache_result` publica somente `(vendor_id, 'hit'|'miss')`, sem payload,
corpo do provider ou credencial.

## Credenciais e vendor

Usuário e senha pertencem à integração Braspress do vendor e chegam ao transporte somente depois da resolução server-side daquele `vendor_id`. Não há `BRASPRESS_USER` ou `BRASPRESS_PASSWORD` como fallback de checkout e nenhum segredo deve entrar em `.env.example`, payload, resposta REST, log ou documentação.

O header Basic é montado apenas no transporte. Logs guardam vendor, operação, duração, status do provider, categoria, `traceId` e mensagens do provider em quantidade/tamanho limitados; sequências numéricas de oito ou mais dígitos são mascaradas. Header, usuário, senha, payload e corpo bruto nunca são registrados.

## Quem configura a integração

A integração é do vendor, mas dois painéis escrevem nela. O vendor configura em `/vendor/configuracoes` por `GET|PUT|DELETE /vendor/me/integrations/braspress`, com step-up de senha para tocar na credencial. O administrador configura pela aba **Integrações** de `/admin/contas/{id}`, por `GET /admin/vendors/{id}/integrations` e `PUT|DELETE /admin/vendors/{id}/integrations/braspress` — existe porque boa parte dos vendors não quer fazer esse cadastro, e a operação faz por eles.

Os dois caminhos convergem em `papelito_vendor_integration_write_braspress()` e `papelito_vendor_integration_erase_braspress()`, que são a única escrita da integração. O que muda antes delas é só a autorização: titularidade, limite por vendor e reautenticação de um lado (`papelito_vendor_integration_guard_braspress_save()`); `manage_options` e limite por administrador do outro (`includes/admin_vendor_integrations.php`). A rota administrativa **não pede nem aceita a senha do vendor** — a capability é a autorização.

Não existe estado "desligado pela Papelito". Admin e vendor escrevem a mesma coluna `enabled`, e é ela que `papelito_vendor_integration_resolve_braspress()` já consultava: com a integração desligada o adapter não resolve, e a Braspress some da cotação, do checkout, do registro de remessa nova e do polling de tracking — que reagenda com `braspress_integration_unavailable` em vez de perder a remessa em trânsito. A credencial cifrada continua guardada, então religar não exige cadastrá-la de novo.

Nos dois painéis o interruptor grava sozinho, sem confirmação de senha: `papelito_vendor_integration_intended_action()` classifica um corpo sem `username`/`password`/`removeCredentials` como `configuration_saved`, e só as outras duas intenções passam pelo step-up. Trocar ou remover credencial continua exigindo a senha da conta no painel do vendor.

A trilha em `papelito_vendor_integration_audit` distingue os dois pelo prefixo da ação: `credentials_saved` é do vendor, `admin_credentials_saved` é da operação. O prefixo é aplicado por `papelito_vendor_integration_audited_action()` quando o ator não é o dono da integração, e o aviso por e-mail ao titular continua saindo em toda troca ou remoção de credencial.

## Operação segura

O runtime desta fase aponta somente para a API oficial de produção. Não há configuração ou credencial de homologação; uma futura habilitação exigirá mudança explícita da allowlist e documentação própria.

Antes de qualquer teste de produção, a operação precisa de autorização explícita, credenciais rotacionadas e um procedimento manual separado. A flag `PAPELITO_BRASPRESS_ENABLED=false` continua sendo o default e não deve ser ligada por este cliente.
