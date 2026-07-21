# Investigação e solução — Pré-Postagem e etiquetas dos Correios

Data da revisão: 21/07/2026. Escopo: repositórios `papelito-wordpress` e
`papelito-web`, histórico e dump locais, documentação oficial e imagens do CWS
fornecidas pelo cliente. Não foi criada, cancelada ou alterada nenhuma postagem,
chave, credencial, contrato ou cobrança.

## 1. Resumo executivo

O botão **Gerar etiqueta dos Correios** deveria validar o pedido e o remetente,
reservar idempotentemente a operação, criar uma pré-postagem no contrato/cartão
correto, obter o S10, gerar ou recuperar o PDF e oferecer seu download privado.
Postagem e entrega só devem ser reconhecidas posteriormente por eventos do Rastro.

No código original, o Next encaminhava o clique ao WordPress, que validava vendor,
pagamento, separação e inexistência de envio e executava o filtro
`papelito_correios_generate_prepostage`. Não havia callback versionado. O valor
`null` era convertido em `papelito_correios_prepostage_not_enabled`, HTTP 503.
Portanto, a hipótese principal da análise anterior foi confirmada no código.

A cotação funcionar não demonstra capacidade de gerar etiqueta. Preço v3, Prazo
v3 e Pré-Postagem são autorizações e operações diferentes. A imagem do CWS mostra
Preço/Prazo/CEP habilitados para aquela chave/configuração, SRO-Rastro e Meu
Contrato desabilitados e não mostra API Pré-Postagem. Isso prova apenas o escopo
visível daquela chave; não prova a situação de todos os contratos e cartões.

A solução recomendada é híbrida:

1. curto prazo: mock estritamente local e fallback manual com cadastro auditável
   do S10;
2. produção: somente ativar o adapter direto depois de confirmar o serviço
   `86720 — API PRE POSTAGEM` no cartão e exportar o OpenAPI autorizado;
3. se o serviço não for disponibilizado: PPN Web/manual no lançamento e avaliação
   comercial de um intermediador com suporte formal a marketplace;
4. longo prazo: provider por vendor, com Correios direto, intermediador ou manual.

Implementação realizada nesta entrega: modos `disabled|mock|real`, bloqueio do mock
em produção, cenários de erro, PDF `SEM VALIDADE`, reserva idempotente antes do
provider, estados de geração, armazenamento privado, download autorizado, erros
estruturados, endpoint/UI manual, script de health check somente leitura e testes.
O adapter real permanece intencionalmente não implementado até o gate contratual e
o OpenAPI.

## 2. Evidências encontradas no código

| Evidência | Arquivo/linha | Comportamento confirmado | Consequência |
|---|---|---|---|
| Clique do vendor | `papelito-web/src/components/layout/vendor-panel/vendor-order-actions.tsx:95-109` | Faz `POST /api/vendor/orders/{id}/shipments` | O browser não chama Correios diretamente. |
| Proxy autenticado | `papelito-web/app/api/vendor/orders/[id]/shipments/route.ts:19-39` | Repassa JWT ao endpoint WordPress e preserva código/categoria/retryable | Credenciais Correios ficam server-side. |
| Validações | `includes/correios_tracking.php:457-493` | Exige pedido válido, `em_separacao`, não encerrado e trata envio existente | Clique fora do estado correto é recusado. |
| Ponto de extensão | `includes/correios_tracking.php:513` | Executa `apply_filters('papelito_correios_generate_prepostage', ...)` | Provider pode ser trocado sem expor detalhes ao front. |
| Ausência original de provider | histórico anterior a esta entrega; única ocorrência operacional era o `apply_filters` | Nenhum `add_filter` versionado | O retorno era sempre `null` e virava 503. |
| Provider atual | `includes/correios_prepostage.php:98-106,213` | `real` exige callback; mock só é registrado fora de produção | Produção não pode gerar dados falsos por configuração acidental. |
| Concorrência | `includes/correios_tracking.php:47-70,313-370` | Chave única e reserva `generating` existem antes do provider | Dois cliques não possuem duas reservas externas. |
| Resultado incerto | `includes/correios_tracking.php:373-387,489-492` | Erro sem prova de “não criado” vira `uncertain` | Timeout não provoca retry cego. |
| Persistência | `includes/correios_tracking.php:43-74,415-451` | Guarda provider, estado, idempotência, pré-postagem, S10, serviço, checksum e chave do PDF | Auditoria e recuperação não dependem só do pedido Woo. |
| PDF privado | `includes/correios_tracking.php:391-411,910-965` | Arquivo fora do webroot, chave opaca, checksum e autorização order/vendor | PDF não entra na biblioteca pública de mídia. |
| Fallback manual | `includes/correios_tracking.php:556-590,993-1017` | Vendor pode cadastrar S10 somente com flag e pedido próprio em separação | Operação pode continuar sem geração automática. |
| Rastreamento real | `includes/correios_tracking.php:590-612` | Consulta `srorastro/v1/objetos/{S10}?resultado=T` | SRO-Rastro é separado de emissão. |
| Polling | `includes/correios_tracking.php:800-862` | Sucesso em 30 min, saída para entrega em 10 min, falha com backoff/jitter; lote padrão 100 | Evita polling contínuo, mas depende de acesso Rastro. |
| Estados protegidos | `includes/correios_tracking.php:647-691`; `includes/vendor_dashboard.php:534-557` | Vendor não declara entrega e cancelamento com envio exige revisão | Rastro é a evidência para avançar estados logísticos. |
| Autenticação/cotação | `includes/shipping.php:292,555,626,745,760` | Token por cartão, Meu Contrato, pacote e Preço/Prazo reais | Cotação bem-sucedida não comprova Pré-Postagem. |
| Bairro do destino | `includes/order_routing.php:168-194,325-347` | Era validado, mas não entrava no endereço Woo | Nesta entrega passou a ser snapshot no meta `_papelito_shipping_neighborhood`. |
| Dados mutáveis | `includes/shipping.php:626-681`; `includes/pagarme_payments.php:222-234` | Pacote relê produto; documento relê perfil | Não devem ser usados cegamente pelo adapter real. |
| Legado | `woocommerce-correios.php:3-8`; `wc-correios-functions.php:134-201` | Plugin Claudio Sanches v4.2.5 guardava `_correios_tracking_code` e notificava | Há evidência de S10 manual, não de geração automática. |
| Histórico no dump | `db/u374715300_rhozU.sql` (metas `_correios_tracking_code` e snippet de CEP de origem) | Existem códigos e notas legadas, inclusive entradas inválidas de teste | Migração exige validação; não importar tudo como S10 válido. |

Limitações confirmadas no estado original: nenhum PDF, download, cancelamento remoto,
read model de `prepost_id`, reserva pré-provider ou provider real. O `prepost_id`
continua deliberadamente fora do read model público; o PDF é exposto apenas por
endpoint autorizado.

## 3. Fluxo atual

Fluxo conceitual correto:

`Pedido pago → aguardando envio → separação → pré-postagem → etiqueta → postagem → rastreamento → entrega`

Fluxo original:

1. pagamento confirmado libera `aguardando_envio`;
2. vendor marca separação;
3. botão chama Next e WordPress;
4. WordPress valida o pedido;
5. **interrupção:** filtro de pré-postagem devolve `null`;
6. WordPress responde 503; não há `prepost_id`, S10 ou PDF;
7. sem S10, o poll do Rastro não começa.

Fluxos após esta entrega:

- `mock` local: cria IDs `MOCK-*`, PDF `SEM VALIDADE`, fica `preposted` e nunca é
  enviado ao Rastro. Ele não transforma o pedido em `enviado`.
- manual: vendor gera fora do Papelito, cadastra S10, shipment fica `preposted` e o
  poll tenta acompanhar. O avanço depende de a credencial Rastro poder consultar
  aquele objeto.
- real: permanece fail-closed até callback autenticado e validado. Configurar
  `real` sem provider não reserva nem cria postagem.

## 4. Resultado da pesquisa oficial

### Matriz de fontes

| Fonte oficial | Data/versão | Produto | Uso | Confiança |
|---|---|---|---|---|
| [Manual API Meu Contrato](https://www.correios.com.br/atendimento/developers/manuais/manual-api-meu-contrato) | 06/10/2025, v1.0 | Meu Contrato 566 | Contratos, cartões, serviços e teste do 86720 | Alta |
| [Manual Correios Web Services](https://www.correios.com.br/atendimento/developers/manuais/correioswebservice) | versão web vigente; acesso 21/07/2026 | CWS | Catálogo, chaves, subdelegação e OpenAPI | Alta |
| [Manual de uso da API Token](https://www.correios.com.br/atendimento/developers/manuais/manual-uso-da-api-token) | 03/11/2025, v1.0 | API Token | Token por login/contrato/cartão, validade e homologação | Alta |
| [Manual de integração das APIs](https://www.correios.com.br/atendimento/developers/arquivos/manual-para-integracao-correios-api.pdf) | revisão 04/2025, v2.4 | Pré-Postagem e outras APIs | Fluxos públicos, rótulo, consulta e cancelamento | Alta para funcionalidades; média para schema |
| [Portal de desenvolvedores](https://www.correios.com.br/atendimento/developers/) | comunicado vigente desde 15/09/2025 | Pré-Postagem | Declaração de conteúdo e adicional 095 | Alta |
| [Manual do usuário PPN](https://www.correios.com.br/atendimento/developers/manual-do-usuario-ppn) | página vigente; acesso 21/07/2026 | PPN Web | Alternativa operacional web | Alta |
| Imagens CWS do cliente | CWS v1.11.5, 21/07/2026 | chave/escopos | Estado visível daquela chave | Média; não cobre todos os cartões |

### Autenticação

- APIs usam Bearer Token. O token pode ser obtido pela API Token nos contextos de
  login, contrato ou cartão, conforme o endpoint e o escopo.
- O código atual usa `token/v1/autentica/cartaopostagem` e mantém cache com margem
  antes de `expiraEm`.
- O manual também prevê chave subdelegada CWS para terceiros. Ela reduz a exposição
  da senha principal, mas só dá acesso às APIs autorizadas.
- Chaves/tokens devem ser guardados server-side, rotacionados e nunca impressos.
  A chave colada durante esta investigação foi exposta e deve ser revogada; ela não
  foi usada.

### Contrato, cartão e serviço

O serviço contratual é `86720 — API PRE POSTAGEM`. O Manual Meu Contrato fornece
consulta específica por CNPJ, contrato, cartão e serviço:

`GET /meucontrato/v1/empresas/{cnpj}/contratos/{contrato}/cartoes/{cartao}/servicos/86720`

- `200` com código `86720`: presente naquele cartão;
- `404`/`CON-011`: ausente naquele cartão;
- `401/403`: credencial ou permissão, não conclusão contratual;
- `429/5xx`: inconclusivo.

A ausência da API no catálogo CWS significa que o usuário/chave não a enxerga como
autorizada. Não prova ausência em outro contrato/cartão/perfil. A confirmação deve
ser feita por cartão na API Meu Contrato ou formalmente pelo gestor comercial.
“Gestão de acesso” concede/restringe acesso da chave; não substitui contratação.

### Pré-Postagem e schema

O produto oficial atual continua sendo **API PRE POSTAGEM**; PPN Web é a interface
web. SIGEP Web é legado e não deve fundamentar uma integração nova.

O manual confirma operações de criação, consulta, geração/reemissão de rótulo,
declaração de conteúdo e cancelamento. O endpoint público explicitamente exibido
para rótulo assíncrono em homologação é:

`POST https://apihom.correios.com.br/prepostagem/v1/prepostagens/rotulo/assincrono/pdf`

com grupos como `idsPrePostagem`, `tipoRotulo` e `formatoRotulo`. O domínio base é
`prepostagem/v1`. Os Correios determinam que método, path e schemas completos e
vigentes sejam obtidos no Swagger/OpenAPI autenticado do CWS. Por isso este
relatório não inventa o payload de criação.

Campos funcionais que devem ser mapeados após o OpenAPI: contrato/cartão/serviço,
remetente, destinatário, documento, endereço, peso/dimensões, número do pedido,
serviços adicionais, valor declarado, NF-e ou declaração e itens. Desde 15/09/2025,
`itensDeclaracaoConteudo` é exigido inclusive com NF-e, descrição deve ter ao menos
cinco caracteres e itens restritos ao transporte aéreo exigem adicional `095`.

### Ambientes e limites

- Produção: `https://api.correios.com.br/`.
- Homologação: `https://apihom.correios.com.br/`.
- Segundo o Manual Token, homologação não é um sandbox público: o serviço deve
  existir em produção, a réplica deve ser solicitada ao representante comercial e
  há credenciais próprias de homologação.
- O OpenAPI protegido respondeu 403 sem autorização; isso confirma restrição do
  schema, não indisponibilidade da API.
- Não há, nas fontes públicas consultadas, rate limit numérico nem garantia pública
  de idempotência do POST. `Retry-After`, retenção/expiração, reconciliação por
  referência, semântica completa do cancelamento e duração do PDF continuam
  pendentes do OpenAPI/suporte.
- Há divergência pública sobre expiração: manual de integração menciona 7 dias e
  material atual do PPN Web menciona 15. Não automatizar expiração sem confirmação.

Health check nunca deve criar pré-postagem. O script
`scripts/check-correios-cws-key.sh` faz um único GET de CEP ou Meu Contrato, timeout
de 10 s, nenhum retry e imprime só o HTTP status.

## 5. Comparação com a integração antiga

| Aspecto | Legado | Atual/original | Após esta entrega |
|---|---|---|---|
| Plugin | Claudio Sanches — Correios for WooCommerce 4.2.5 | `plugin_papelito` headless | mesmo plugin custom, provider interno |
| Cotação | Plugin WooCommerce | APIs Preço/Prazo reais | mantida |
| Origem | Snippet alterava CEP por vendor | origem do vendor no módulo custom | precisa política por vendor/cartão |
| Etiqueta | Nenhuma evidência de geração | botão sem callback, 503 | mock local/manual; real gateado |
| S10 | Meta `_correios_tracking_code`, entrada manual/API REST | tabela `papelito_shipments` | manual validado ou retorno do provider |
| Tracking | Opcional no plugin e histórico consultado | Rastro oficial + polling | mantido, com mock excluído |
| PDF | Não encontrado | inexistente | privado para provider que o fornece |
| Melhor Envio | Vestígios de nomes em dados | sem plugin ativo comprovado | alternativa, não legado confirmado |

Não foi encontrado SIGEP Web, Frenet, Kangu ou geração automática no legado. O
plugin antigo ainda contém integração CWS/Rastro, mas não deve ser reativado para
Pré-Postagem: ele não implementa esse fluxo e versões/APIs antigas podem estar
descontinuadas. O estado atual dos plugins no servidor de produção não foi
confirmado via SSH; o histórico local foi suficiente para identificar o fluxo.

## 6. Solução para desenvolvimento

### Implementado

- `PAPELITO_CORREIOS_PREPOST_MODE=disabled|mock|real`, default `disabled`;
- `mock` bloqueado se `WP_ENVIRONMENT_TYPE=production`;
- IDs `MOCK-*`, PDF mínimo com `SEM VALIDADE`, sem chamadas externas e sem polling;
- cenários `400,401,403,404,409,422,429,500,503` via
  `PAPELITO_CORREIOS_PREPOST_MOCK_SCENARIO`;
- fallback manual explicitamente desligado por padrão;
- testes unitários do provider, idempotência e mapeamento do front;
- health check real manual e somente leitura.

### OpenAPI mock/contrato

Quando o cliente exportar o JSON OpenAPI do CWS, gerar um mock server compatível e
versionar apenas fixtures sanitizadas. Até lá, o mock interno testa o contrato do
Papelito, não afirma compatibilidade de payload com os Correios. O pipeline deve:

1. validar OpenAPI e fixtures;
2. testar serialização/desserialização do adapter;
3. simular os nove HTTPs;
4. impedir por teste que host `api.correios.com.br` seja acessado no modo mock;
5. executar teste real apenas por comando manual e flag efêmera.

Estados cobertos: aguardando etiqueta, gerada/preposted, autenticação inválida, API
ausente, indisponibilidade, geração em andamento/incerta, postado, trânsito,
entregue, cancelado e expirado. Os últimos estados devem ser fixtures de evento ou
reconciliação; não são simulados enviando `MOCK-*` ao Rastro.

## 7. Solução permanente para produção

Contrato interno: `health`, `create`, `get_or_regenerate_label`, `reconcile` e
`cancel`. O filtro existente permanece compatível, mas `real` só fica pronto com
provider explicitamente registrado.

Fluxo recomendado:

1. validar pedido e snapshot imutável;
2. resolver provider/contrato/cartão por vendor;
3. reservar tentativa com chave única `pedido|vendor|pacote|versão|provider`;
4. obter token com cache, margem e lock;
5. criar uma única vez;
6. em timeout, marcar `uncertain` e reconciliar antes de retry;
7. persistir `prepost_id`, S10, serviço, estado e auditoria;
8. gerar/recuperar PDF e guardar fora do webroot com checksum;
9. liberar download autenticado;
10. acompanhar Rastro, sem permitir entrega manual.

Ainda necessários antes do provider real:

- snapshot de peso/dimensões/valor/itens no pedido; não reler produto;
- snapshot validado do documento do destinatário com retenção/controle de acesso;
- declaração/NF-e e classificação de itens restritos;
- regra de remetente, contrato, cartão e pagador por vendor;
- OpenAPI, idempotência e reconciliação oficiais.

Falhas e UI:

| Categoria | HTTP interno sugerido | Mensagem ao vendor | Ação |
|---|---:|---|---|
| Não configurada | 503 | Geração automática ainda não configurada | Mostrar manual se habilitado |
| Serviço ausente | 404/422 | Contrato/cartão não possui Pré-Postagem | Suporte/comercial |
| Credencial inválida | 401 | Credenciais precisam ser atualizadas | Não pedir segredo ao vendor |
| Sem permissão | 403 | Chave sem acesso à API | Corrigir escopo |
| Dados incompletos | 422 | Informar exatamente o campo faltante | Corrigir cadastro/pedido |
| Em andamento | 409 | Etiqueta já está sendo gerada | Aguardar |
| Já gerada | 200/409 | Oferecer baixar/reimprimir | Não criar outra |
| Resultado incerto | 409 | Suporte precisa reconciliar | Bloquear novo POST |
| Indisponível | 429/503 | Tentar mais tarde | Circuit breaker/Retry-After |

Observabilidade: correlation ID, logs estruturados com redaction, contadores por
provider/categoria, latência, tentativas incertas, fila/poll atrasado, circuit
breaker e alertas. Nunca registrar token, documentos, endereço integral ou PDF.
Retry automático só em operação comprovadamente idempotente. Rollback desliga
novas criações por feature flag, mas mantém consulta/reconciliação do já criado.

## 8. Alternativas sem a API de Pré-Postagem

| Opção | Viabilidade/custo divulgado | Complexidade/UX | Riscos e dependências | Recomendação |
|---|---|---|---|---|
| PPN Web/manual | Alta; preço é o frete/contrato ou postagem à vista | Baixa técnica, mais trabalho do vendor | erro de digitação; Rastro pode não ver objeto fora do contrato | Curto prazo |
| Melhor Envio | API sem taxa/mensalidade; paga-se o frete/etiqueta. [Docs oficiais](https://docs.melhorenvio.com.br/reference/introducao-api-melhor-envio) | API e sandbox bons; modelar conta/saldo por vendor | OAuth, carteira, fiscal e multi-vendor precisam desenho | Piloto simples |
| Frenet Whitelabel | API do parceiro anunciada sem taxa/mensalidade; custos de frete/plano/etiqueta são comerciais. [Onboarding](https://docs.frenet.com.br/docs/cadastro-de-contas-na-frenet), [etiqueta](https://docs.frenet.com.br/reference/getshipmentlabelasync) | Melhor aderência aparente a onboarding, carteira e plataforma | contrato comercial, limites e conciliação | Primeira avaliação para marketplace |
| Kangu | API oficial cobre cotação, pedido, tracking, XML e etiqueta. [Manual](https://portal.kangu.com.br/ged/documento/download-file/hash/file_cVQ0blhHaG1PQUZFMXhqZXB2VEpBQTRsa3VXbFB5RkRYYm50bjdHTzFVZz0%3D/Documentac_a_o_da_API_Kangu_.pdf) | Integração possível | preço e suporte multi-vendor não confirmados | Avaliar comercialmente |
| SuperFrete | Sem mensalidade/volume mínimo; paga-se frete emitido. [Página oficial](https://superfrete.com/woocommerce) | Plugin/API simples para loja | aderência headless/marketplace e contas segregadas precisa confirmação | Alternativa condicionada |
| Contrato da plataforma | Centraliza negociação e API | Boa UX | plataforma paga, concilia, assume segurança/fiscal/financeiro | Só com decisão jurídica/financeira |
| Contrato de cada vendor | Segrega custo e remetente | Onboarding complexo | credenciais, cartões e suporte por vendor | Bom para vendors maduros |
| Híbrido | Provider por vendor | Maior complexidade inicial, melhor cobertura | matriz de estados e conciliação | Longo prazo recomendado |

No manual: entregar destinatário/endereço/contato, serviço, itens, peso, dimensões,
valor e documento operacional; vendor gera no PPN/portal/agência, cadastra S10 e o
Papelito valida formato. Poll automático é condicionado a acesso ao objeto.

## 9. Checklist de dados e dependências

### Grupo 1 — Dados já existentes no sistema

| Item | Por que | Onde/origem | Responsável | Obrigatório | Impacto da ausência/limite |
|---|---|---|---|---|---|
| Nomes das credenciais | Autenticar APIs | env `PAPELITO_CORREIOS_*` | Operação | Sim para real | valores não foram reexibidos; chave exposta deve ser revogada |
| Contrato/cartão | Autorizar serviço/cobrança | env | Cliente/Correios | Sim | impossível escolher autorização |
| Códigos PAC/SEDEX | Cotação e postagem | `shipping.php`/pedido | Sistema | Sim | serviço errado/rejeição |
| Remetente/vendor | Origem e identificação | usermeta/wizard | Vendor/cliente | Sim | legado incompleto bloqueia real |
| Destinatário/endereço | Entrega | pedido Woo | Comprador | Sim | bairro passou a ter snapshot só em novos pedidos |
| Itens | Declaração | order items | Checkout | Sim | falta snapshot postal completo |
| Peso/dimensões | Tarifação | produto atual | Catálogo | Sim | mutável; criar snapshot |
| Valor | declarado/seguro | pedido/produto | Checkout | Condicional | não usar preço atual |
| Documento | exigência postal/fiscal | perfil/Pagar.me | Comprador | Provável | mutável; falta snapshot protegido |
| Declaração/NF-e | conformidade | não persistida como snapshot | Cliente/vendor | Sim conforme caso | bloqueia payload real |
| S10/prepost | rastreamento/auditoria | tabela shipments | Provider/vendor | Após geração | sem eles não há acompanhamento |
| Status logísticos | operação/UI | tabela + order meta | Sistema | Sim | não confundir com pagamento |

### Grupo 2 — Solicitar ao cliente

| Item | Por que | Onde obter | Quem fornece | Obrigatório | Impacto se não vier |
|---|---|---|---|---|---|
| Responsável comercial | habilitação/escalonamento | contrato | Cliente | Sim | sem confirmação formal |
| Contratos e cartões ativos | mapear autorização | contrato/CWS | Cliente | Sim | não validar 86720 |
| Serviços contratados | confirmar PAC/SEDEX/86720 | Meu Contrato/minuta | Cliente/Correios | Sim | provider indefinido |
| Confirmação do 86720 por cartão | gate da API | resposta formal/GET | Correios | Sim para direto | manter manual/intermediador |
| Usuário CWS | gestão técnica | Meu Correios | Cliente | Sim | não exportar OpenAPI |
| Nova chave subdelegada | menor privilégio | CWS | Cliente | Sim para teste | nunca enviar por chat |
| Homologação | testar criação sem produção | gestor comercial | Correios/cliente | Sim para real | sem teste destrutivo seguro |
| CNPJ/endereço de remetente | payload/fiscal | cadastro oficial | Cliente/vendor | Sim | rejeição |
| Regra de origem por vendor | escolher remetente | decisão operacional | Cliente | Sim | etiqueta/agência errada |
| Agência/unidade | aceitação física | operação local | Cliente/vendor | Condicional | risco de recusa |
| Pagador do frete | cobrança/conciliação | decisão financeira | Cliente | Sim | débito indevido |
| Processo por vendor | automático/manual/híbrido | decisão de negócio | Cliente | Sim | UI e permissões ambíguas |
| Política NF-e/DC-e | conformidade | fiscal/contabilidade | Cliente | Sim | postagem irregular |
| Autorização intermediador | fallback | decisão comercial/LGPD | Cliente | Condicional | não iniciar onboarding |

### Grupo 3 — Obter com os Correios

| Item | Por que | Onde | Fornecedor | Obrigatório | Impacto |
|---|---|---|---|---|---|
| APIs habilitadas | confirmar escopo real | Meu Contrato/gestor | Correios | Sim | conclusão contratual incerta |
| OpenAPI vigente | payload/respostas | CWS autenticado | Correios | Sim | adapter real bloqueado |
| Base produção/homologação | roteamento seguro | manual/OpenAPI | Correios | Sim | risco de ambiente errado |
| Credenciais hom | teste autorizado | gestor comercial | Correios | Sim para hom | não criar nem testar real |
| Serviços permitidos | selecionar PAC/SEDEX | Meu Contrato | Correios | Sim | rejeição |
| Rate limits | proteção/backoff | suporte/OpenAPI | Correios | Sim operacional | definir conservadoramente |
| Idempotência/reconciliação | evitar duplicidade | suporte/OpenAPI | Correios | Sim | timeout fica `uncertain` |
| Cancelamento | compensação | OpenAPI | Correios | Sim | cancelamento local não basta |
| Recuperação do PDF | reimpressão | OpenAPI | Correios | Sim | suporte manual |
| Expiração/retenção | jobs e storage | suporte/manual | Correios | Sim | divergência 7/15 dias |
| Canal/prazo de habilitação | planejamento | gestor/Fale Conosco | Correios | Sim | sem data de go-live |

## 10. Plano de implementação

| Horizonte/etapa | Atividade | Arquivos/sistemas | Dependência | Responsável | Risco | Aceite | Prioridade |
|---|---|---|---|---|---|---|---|
| Curto — concluído | modos/mock/error fixtures | `correios_prepostage.php` | nenhuma externa | Dev | mock em prod | bloqueio e testes passam | P0 |
| Curto — concluído | reserva/idempotência/estado incerto | `correios_tracking.php`, DB 1.7.0 | migração dbDelta | Dev/Ops | lock/migração | duas reservas resolvem mesmo ID | P0 |
| Curto — concluído | fallback S10 + UI/proxy | WP + Next | flag manual | Dev/Ops | código fora do contrato não rastreável | ownership/S10 validados | P0 |
| Curto — concluído | PDF privado mock/download | WP + Next | storage gravável | Dev/Ops | permissão de disco | não acessível sem vendor | P0 |
| Curto | ativar manual no ambiente de lançamento | env/deploy | decisão cliente | Ops | operação não treinada | runbook e vendor treinado | P0 |
| Curto | rotacionar chave exposta | CWS | cliente | Cliente | uso indevido | chave revogada, nova não compartilhada | P0 |
| Médio | validar 86720/OpenAPI | CWS/Meu Contrato | chave nova + cartão | Cliente/Correios | 403/5xx inconclusivo | evidência 200 ou resposta formal | P0 |
| Médio | snapshots postal/fiscal | checkout/pedido | decisão LGPD/fiscal | Dev/Cliente | PII/retrocompatibilidade | dados imutáveis e protegidos | P1 |
| Médio | adapter real ou intermediador | novo provider | OpenAPI/contrato | Dev | cobrança/duplicidade | contrato, hom e testes de contrato | P1 |
| Médio | canário por vendor | flags/config | provider pronto | Ops | impacto financeiro | 1 vendor, métricas e rollback | P1 |
| Longo | provider por vendor e fila durável | WP/worker/storage | escala | Arquitetura/Ops | complexidade | reconciliação e SLO definidos | P2 |
| Longo | métricas/alertas/runbook/retenção | observabilidade | stack escolhida | Ops/Sec | PII/logs | alertas testados, redaction auditada | P2 |

## 11. Textos para o cliente

### 11.1 WhatsApp curto

> Para liberar a geração automática de etiquetas, precisamos confirmar com os
> Correios se o serviço **86720 — API PRE POSTAGEM** está ativo no contrato e no
> cartão de postagem. Preço e Prazo já funcionam, mas são serviços separados.
> Também precisamos de acesso a Meu Contrato e Rastro para validar e acompanhar os
> envios. Por favor, não envie senhas ou chaves por WhatsApp; compartilhe somente
> por canal seguro. A chave mostrada anteriormente deve ser revogada porque foi
> exposta.

### 11.2 E-mail formal

Assunto: Confirmação contratual e acessos para etiquetas dos Correios

> Olá,
>
> Para concluir a integração de etiquetas do Papelito, solicitamos a confirmação
> dos contratos e cartões de postagem ativos, dos serviços PAC/SEDEX disponíveis e
> da habilitação do serviço **86720 — API PRE POSTAGEM** em cada cartão que poderá
> ser utilizado. Precisamos ainda do OpenAPI vigente da API no CWS, da informação
> sobre homologação e do contato do gestor comercial responsável.
>
> Para validação técnica, recomendamos uma nova chave CWS subdelegada e temporária,
> com o menor escopo possível, incluindo Meu Contrato, Pré-Postagem e Rastro apenas
> se autorizados. A chave anteriormente exibida deve ser revogada. Não envie
> credenciais por e-mail; indicaremos um canal seguro para o compartilhamento.
>
> Favor informar também quem será o remetente e pagador do frete em cada vendor,
> qual cartão será usado e se a operação deve aceitar fallback manual.
>
> Atenciosamente,
> Equipe Papelito

### 11.3 Cotação x etiqueta

> A cotação e a etiqueta são etapas diferentes. Preço/Prazo calculam quanto custa
> e quando a encomenda pode chegar. A Pré-Postagem registra o objeto no contrato,
> gera o código de rastreamento e permite emitir o PDF. Por isso o frete pode
> aparecer corretamente no checkout mesmo quando o contrato ainda não permite
> gerar a etiqueta pela API.

### 11.4 Alternativas

> Se o contrato atual não puder usar a API de Pré-Postagem, podemos operar de três
> formas: (1) o vendor gera a etiqueta no PPN/portal dos Correios e cadastra o
> código no Papelito; (2) integramos um intermediador logístico, após validar custo
> e suporte a múltiplos vendors; ou (3) adotamos um fluxo híbrido, automático para
> vendors habilitados e manual para os demais. Para o curto prazo, recomendamos o
> fluxo manual auditável; em paralelo avaliamos a opção automática definitiva.

### 11.5 Texto encaminhável aos Correios

Assunto: Habilitação e documentação da API PRE POSTAGEM — serviço 86720

> Prezados,
>
> Solicitamos confirmar, para o CNPJ, contrato(s) e cartão(ões) informados em canal
> seguro, se o serviço **86720 — API PRE POSTAGEM** está ativo e autorizado. Caso
> não esteja, pedimos os requisitos, canal, prazo e procedimento comercial/técnico
> para habilitação.
>
> Solicitamos também: OpenAPI vigente no CWS; endpoints de produção e homologação;
> procedimento e credenciais próprias de homologação; serviços postais permitidos;
> rate limits; política de idempotência/reconciliação; cancelamento; expiração; e
> recuperação/reemissão do rótulo PDF.
>
> Precisamos confirmar ainda a relação entre o serviço, contrato e cartão de
> postagem, e o acesso às APIs Meu Contrato (566) e SRO-Rastro (87). Nenhuma senha
> deve ser enviada por e-mail; favor orientar o canal seguro recomendado.

## 12. Recomendação final

Principal: lançar com **PPN/manual + cadastro validado do S10**, manter mock somente
local e desenvolver o adapter direto apenas após `200`/confirmação formal do 86720
e OpenAPI exportado. Em paralelo, avaliar Frenet Whitelabel como primeira opção de
intermediador para multi-vendor e Melhor Envio como piloto mais simples.

Essa escolha evita postagens duplicadas, não depende de um serviço contratual ainda
incerto e preserva a arquitetura. O adapter Correios direto continua preferível
quando contrato, cartão, cobrança e remetente estiverem claramente definidos.

## 13. Pendências e nível de confiança

### Confirmado — confiança alta

- causa original do 503 e ausência de callback no código versionado original;
- separação entre cotação, pré-postagem e Rastro;
- legado Claudio Sanches v4.2.5 sem geração automática encontrada;
- serviço contratual 86720 e consulta por cartão;
- mock fail-closed, reserva idempotente e fallback implementados/testados;
- bairro era descartado e agora é snapshot para novos pedidos.

### Confirmado parcialmente — confiança média

- imagem CWS: escopos daquela chave/configuração, não de todo o contrato;
- SRO-Rastro desligado na imagem, sem prova de indisponibilidade contratual;
- vestígios de Melhor Envio, sem integração ativa comprovada;
- manual S10: acompanhamento depende de o objeto ser visível à credencial Rastro.

### Incerto/bloqueante

- serviço 86720 no contrato/cartão real;
- método oficial de uso da nova chave subdelegada para cada API;
- OpenAPI de criação e respostas;
- rate limits/idempotência/reconciliação/cancelamento/expiração;
- homologação e credenciais próprias;
- regra fiscal, remetente, cartão e pagador por vendor;
- estado de plugins/credenciais efetivamente implantados em produção (SSH não
  executado);
- suporte comercial multi-vendor e custos finais dos intermediadores.

### Auditoria do plano da outra IA

Incorporados: fallback manual, endereço do wizard, gate por OpenAPI, bairro e trilhas
independentes. Rejeitados/corrigidos: clicar “Alterar chave” como solução garantida;
tratar toggle como contratação; afirmar que mock avança para Enviado; enviar mock ao
Rastro; reutilizar pacote/documento mutáveis; afirmar que todo S10 manual será
consultável; tratar código implementado nesta entrega como estado original.

Validação final: mock não se registra em produção; chave única precede o provider;
PDF fica privado; nenhum segredo foi gravado no Git/relatório; nenhuma chamada de
criação/cancelamento foi realizada. A chave exposta pelo usuário não foi usada e
deve ser revogada.
