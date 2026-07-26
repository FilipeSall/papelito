# Recibo interno em PDF

## Objetivo

Disponibilizar ao comprador um recibo interno em PDF para pedidos pagos. O arquivo deve poder ser baixado na confirmação do checkout e no detalhe de qualquer pedido pago. O envio por e-mail é opt-in: somente ocorre quando o comprador aciona a ação correspondente.

## Limites

- O recibo não é documento fiscal, mas a interface e o PDF não devem exibir um aviso ou texto comparando-o a uma nota fiscal.
- Esta entrega não emite, armazena ou simula NF-e, NFC-e ou NFS-e.
- O checkout continua aceitando um único vendor por pedido.
- Não há envio automático de e-mail nem armazenamento permanente do PDF.

## Comportamento

O backend gera o PDF de forma determinística e sob demanda a partir do `WC_Order`, somente quando a cobrança Pagar.me estiver em `paid` ou `captured`. O arquivo mostra identificação e data do pedido, data de pagamento, comprador, vendor, itens, quantidades, subtotal, frete, desconto, total e método de pagamento sem dados sensíveis.

O mesmo comprador que possui o pedido pode baixar o arquivo e solicitar o envio. Para compra B2B, o destinatário é o e-mail de faturamento do snapshot somente se ele ainda corresponder ao e-mail atualmente verificado da empresa; para compra individual, é o e-mail verificado da conta. Não existe campo para informar e-mail alternativo. Quando não houver destinatário elegível, o endpoint retorna `422` e a interface informa que não há e-mail verificado para o envio. Reenvios são limitados a três solicitações por pedido e usuário em uma janela de uma hora, sem bloqueá-los permanentemente.

## Interfaces

- `GET /papelito/v1/profile/me/orders/{id}/receipt`: PDF privado do pedido do usuário autenticado.
- `POST /papelito/v1/profile/me/orders/{id}/receipt/email`: envia o PDF ao destinatário determinado pelo pedido.
- Rotas Next.js autenticadas fazem proxy do download binário e da solicitação de e-mail, sem expor o JWT WordPress ao browser.

O PDF será servido com download forçado, `Cache-Control: private, no-store` e `X-Content-Type-Options: nosniff`.

## Interface do cliente

A tela `/checkout/sucesso/[orderId]`, exibida depois de uma cobrança confirmada, terá mensagem de pagamento confirmado e botão para baixar o recibo. O detalhe `/perfil/pedidos/[id]` exibirá os botões de baixar e enviar para o e-mail para pedidos pagos; pedidos pendentes, expirados ou não pagos não oferecem essas ações.

## Implementação e validação

Um módulo dedicado no plugin gera o PDF em memória, valida propriedade e estado do pagamento, envia o e-mail sob demanda e contém o TODO técnico para a futura integração de documentos fiscais. O gerador é autocontido para não introduzir uma dependência de produção nova no deploy atual.

Testes cobrem autorização, bloqueio de pedido não pago, resposta binária e seus headers, conteúdo do e-mail/destinatário, rate limit e exibição dos CTAs nas duas telas. A entrega roda `php -l`, testes relevantes do backend, `npm run lint` e `npm run build`.
