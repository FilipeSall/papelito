# Runbook — Diagnóstico dos Correios e estado da pré-postagem

Como verificar uma chave CWS sem vazá-la, o que já foi confirmado sobre a habilitação do serviço de Pré-Postagem, o que continua bloqueado e quais alternativas comerciais foram avaliadas.

A implementação está em [`../context/correios-integration.md`](../context/correios-integration.md); o fluxo funcional em [`../../../docs/flows/shipping-and-tracking.md`](../../../docs/flows/shipping-and-tracking.md).

> **Ação pendente e prioritária: revogar a chave CWS que foi exposta em canal de chat** e criar uma chave técnica nova, mínima e temporária. A chave exposta não foi usada em nenhuma chamada, mas continua válida até ser revogada.

## Verificação segura de uma chave

O script `scripts/check-correios-cws-key.sh` faz **exatamente uma chamada `GET`**, não tem retry e **não cria, cancela ou altera pré-postagem alguma**. Use apenas depois de revogar qualquer chave exposta.

Nunca coloque a chave no Git, em argumento de linha de comando, print, e-mail ou chat. Digite silenciosamente e exporte só para o processo:

```bash
read -rs PAPELITO_CWS_ACCESS_KEY
export PAPELITO_CWS_ACCESS_KEY
PAPELITO_CWS_CHECK=cep ./scripts/check-correios-cws-key.sh
unset PAPELITO_CWS_ACCESS_KEY
```

> **O teste `cep` confirma somente chave e permissão para CEP v3. Ele NÃO confirma a capacidade de gerar etiquetas.** Essa confusão é a armadilha central deste assunto.

Para consultar, de forma não destrutiva, o serviço `86720 — API PRE POSTAGEM` no cartão, a chave também precisa de acesso a **Meu Contrato (566)**:

```bash
read -rs PAPELITO_CWS_ACCESS_KEY
export PAPELITO_CWS_ACCESS_KEY
PAPELITO_CWS_CHECK=prepost-service \
PAPELITO_CORREIOS_CNPJ=<cnpj> \
PAPELITO_CORREIOS_CONTRACT=<contrato> \
PAPELITO_CORREIOS_POSTING_CARD=<cartao> \
./scripts/check-correios-cws-key.sh
unset PAPELITO_CWS_ACCESS_KEY
```

O script imprime **apenas o status HTTP**:

| Status | Interpretação |
|---|---|
| `200` | chave aceita; no teste de serviço, o `86720` **está** presente no cartão |
| `401` | chave inválida, expirada ou revogada |
| `403` | a chave não tem o escopo necessário |
| `404` | no teste de serviço, o `86720` **não** foi encontrado naquele cartão |
| `429` / `5xx` | **inconclusivo — não repita automaticamente** |

Endpoint do gate: `GET /meucontrato/v1/empresas/{cnpj}/contratos/{contrato}/cartoes/{cartao}/servicos/86720`. Um `404` acompanhado de `CON-011` indica ausência.

Fonte: Manual oficial da API Meu Contrato, versão 1.0, 06/10/2025.

## Duas conclusões que não se pode tirar

- **A ausência da API no catálogo CWS significa que aquele usuário/chave não a enxerga como autorizada.** Não prova ausência em outro contrato, cartão ou perfil.
- **"Gestão de acesso" concede ou restringe acesso da chave; não substitui contratação.** Marcar um toggle não contrata serviço.

## Causa-raiz do erro original

A geração de etiqueta respondia `503` porque `apply_filters('papelito_correios_generate_prepostage', ...)` **não tinha nenhum `add_filter` registrado**: o retorno `null` era convertido em `papelito_correios_prepostage_not_enabled` → HTTP 503. Não era credencial, não era rede.

E a conclusão conceitual que vale repetir a qualquer pessoa envolvida:

> **A cotação funcionar não demonstra capacidade de gerar etiqueta.** Preço v3, Prazo v3 e Pré-Postagem são autorizações e operações diferentes.

Caminho feliz completo: `Pedido pago → aguardando envio → separação → pré-postagem → etiqueta → postagem → rastreamento → entrega`.

## Fatos técnicos e regulatórios confirmados

- Bases: produção `https://api.correios.com.br/`, homologação `https://apihom.correios.com.br/`. Rótulo assíncrono em `POST /prepostagem/v1/prepostagens/rotulo/assincrono/pdf`. Autenticação em `token/v1/autentica/cartaopostagem`, com margem de cache antes de `expiraEm`.
- **A homologação não é sandbox público**: o serviço precisa existir em produção e a réplica deve ser solicitada ao representante comercial.
- Desde **15/09/2025**, `itensDeclaracaoConteudo` é obrigatório mesmo com NF-e; descrições precisam de pelo menos 5 caracteres; itens restritos a via aérea exigem o serviço adicional `095`.
- **O manual público não contém o schema completo de criação** — por isso nenhum campo foi presumido no plugin.
- **Sem limite numérico de rate limit publicado** e **sem garantia pública de idempotência** para o POST de criação.
- **As fontes divergem sobre a expiração da pré-postagem**: o manual de integração fala em 7 dias, o material atual do PPN Web fala em 15. **Não automatizar expiração sem confirmação.**

## O que ficou confirmado, parcialmente confirmado e bloqueado

**Confirmado (confiança alta)**

- causa original do `503` e ausência de callback registrado;
- separação entre cotação, pré-postagem e Rastro;
- o legado (Correios for WooCommerce 4.2.5) não tinha geração automática;
- existência do serviço contratual `86720` e da consulta por cartão;
- mock fail-closed, reserva idempotente e fallback manual implementados e testados;
- o bairro de destino era descartado e agora tem snapshot **em pedidos novos** (`_papelito_shipping_neighborhood`).

**Parcialmente confirmado (confiança média)**

- a imagem do CWS mostra os escopos **daquela chave/configuração**, não do contrato inteiro;
- o SRO-Rastro aparecia desligado naquela imagem, sem prova de indisponibilidade contratual;
- há vestígios de Melhor Envio no legado, sem integração ativa comprovada;
- no fluxo de S10 manual, o acompanhamento depende de o objeto ser visível à credencial Rastro.

**Incerto / bloqueante**

- serviço `86720` no contrato e cartão reais;
- método oficial de uso da chave subdelegada para cada API;
- OpenAPI de criação e de respostas;
- rate limits, idempotência, reconciliação, cancelamento e expiração;
- homologação e credenciais próprias;
- regra fiscal, remetente, cartão e pagador **por vendor**;
- estado dos plugins e credenciais efetivamente implantados em produção (SSH não executado na investigação);
- suporte comercial multi-vendor e custos finais dos intermediadores.

## Alternativas avaliadas

| Opção | Custo divulgado | Complexidade / UX | Riscos | Recomendação |
|---|---|---|---|---|
| **PPN Web / manual** | frete do contrato ou postagem à vista | baixa técnica, mais trabalho do vendor | erro de digitação; o Rastro pode não ver objeto fora do contrato | **curto prazo** |
| **Frenet Whitelabel** | API do parceiro sem taxa/mensalidade; frete/plano/etiqueta são comerciais | melhor aderência aparente a onboarding, carteira e plataforma | contrato comercial, limites, conciliação | **primeira avaliação para marketplace** |
| **Melhor Envio** | API sem taxa; paga-se frete/etiqueta | API e sandbox bons; exige modelar conta e saldo por vendor | OAuth, carteira, fiscal e multi-vendor precisam de desenho | **piloto mais simples** |
| **Kangu** | API cobre cotação, pedido, tracking, XML e etiqueta | integração possível | preço e suporte multi-vendor não confirmados | avaliar comercialmente |
| **SuperFrete** | sem mensalidade nem volume mínimo | plugin/API simples para loja | aderência headless/marketplace e contas segregadas a confirmar | condicionada |
| Contrato da plataforma | centraliza negociação e API | boa UX | a plataforma paga, concilia e assume risco fiscal/financeiro | só com decisão jurídica/financeira |
| Contrato de cada vendor | segrega custo e remetente | onboarding complexo | credenciais, cartões e suporte por vendor | bom para vendors maduros |
| Híbrido (provider por vendor) | — | maior complexidade inicial, melhor cobertura | matriz de estados e conciliação | longo prazo |

## Recomendação registrada

Lançar com **PPN/manual + cadastro validado do S10**, manter o mock **somente local** e desenvolver o adapter direto apenas depois de um `200` ou confirmação formal do `86720` **e** do OpenAPI exportado. Em paralelo, avaliar Frenet Whitelabel como primeira opção de intermediador multi-vendor e Melhor Envio como piloto.

> O motivo é concreto: essa escolha **evita postagem duplicada**, não depende de um serviço contratual ainda incerto e preserva a arquitetura. O adapter direto continua preferível quando contrato, cartão, cobrança e remetente estiverem claramente definidos.

## O que pedir a quem

**Ao cliente**: responsável comercial dos Correios; contratos e cartões ativos; serviços contratados; confirmação formal do `86720` por cartão; usuário CWS; nova chave subdelegada (**nunca por chat**); acesso a homologação; CNPJ e endereço de remetente; regra de origem por vendor; agência de aceitação; quem paga o frete; se o processo por vendor é automático, manual ou híbrido; política de NF-e/DC-e; autorização para usar intermediador.

**Aos Correios**: quais APIs estão habilitadas; OpenAPI vigente; bases de produção e homologação; credenciais de homologação; serviços permitidos; rate limits; garantia de idempotência e método de reconciliação; cancelamento; recuperação do PDF; expiração e retenção; canal e prazo de habilitação.

**Já no sistema, mas exigindo snapshot antes do modo real**: peso, dimensões, valor, itens e documento do destinatário — todos hoje lidos de fonte **mutável** no momento do uso.

## Ideias descartadas — não repetir

- Clicar em "Alterar chave" no CWS **não** é solução garantida.
- Um toggle de acesso **não** equivale a contratação.
- O modo mock **não** avança o pedido para "Enviado".
- **Nunca** enviar identificador `MOCK-*` ao Rastro.
- **Não** reutilizar pacote e documento mutáveis no payload real.
- **Não** presumir que todo S10 manual será consultável pela nossa credencial.
- Código implementado em uma entrega não é o "estado original" do sistema.

## Bloqueios operacionais abertos

- **Revogar a chave CWS exposta** (P0).
- **Decidir e ativar o modo de postagem manual no ambiente de lançamento** (P0).
- Obter a confirmação formal do `86720` antes de qualquer trabalho no adapter direto.
