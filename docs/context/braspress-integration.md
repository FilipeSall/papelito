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

## Operação segura

O runtime desta fase aponta somente para a API oficial de produção. Não há configuração ou credencial de homologação; uma futura habilitação exigirá mudança explícita da allowlist e documentação própria.

Antes de qualquer teste de produção, a operação precisa de autorização explícita, credenciais rotacionadas e um procedimento manual separado. A flag `PAPELITO_BRASPRESS_ENABLED=false` continua sendo o default e não deve ser ligada por este cliente.
