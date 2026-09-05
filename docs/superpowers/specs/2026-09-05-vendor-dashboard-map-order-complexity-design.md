# Refatoração de complexidade do mapeamento de pedidos

## Objetivo

Reduzir a complexidade cognitiva de `papelito_vendor_dashboard_map_order()` de 16 para o limite aceito pelo SonarLint, preservando integralmente o payload e o comportamento dos detalhes de pedidos do comprador e do vendor.

## Desenho

Manter a função pública existente responsável pela validação e pelo mapeamento dos campos comuns. Quando `detail` for verdadeiro, ela delegará a montagem dos campos de detalhe à função de arquivo `papelito_vendor_dashboard_map_order_detail`; PHP não oferece helper privado em escopo de arquivo. A função receberá o pedido, o vendor opcional, a flag de recibo, os itens já calculados e o resultado base, e devolverá o resultado enriquecido. Uma busca no módulo confirma que esse identificador ainda não existe.

O código extraído será apenas o bloco já existente de detalhe: endereço, logística, rastreamento, pagamento, recibo, status seguintes, faturamento e bloco fiscal. A matriz de recibo será preservada explicitamente: comprador em resumo nunca inclui `receipt`; comprador em detalhe só inclui `receipt` com `include_receipt=true`, `vendor_id=null` e a função de resumo existente; vendor em listagem nunca inclui `receipt` nem chama o helper de recibo; vendor em detalhe inclui `receipt` quando a função existir, ignorando `include_receipt`. `next_statuses`, `billing` e `fiscal` continuarão exclusivos de `detail=true` e `vendor_id !== null`; nunca aparecerão na listagem do vendor nem no payload do comprador. Não haverá mudança em nomes de campos, condições, chamadas condicionais, autorização ou superfície REST.

O patch deverá preservar todos os hunks não relacionados já presentes no arquivo. Não usar reset, checkout, stash nem reformatar o arquivo inteiro; a nova alteração ficará limitada à função e ao helper novo.

## Validação

- comparar o diff contra o estado de trabalho anterior para confirmar que todos os hunks existentes foram preservados e que a alteração nova é somente estrutural;
- executar `php -l` no arquivo alterado;
- executar PHPCS do backend;
- executar o teste standalone mais próximo disponível e verificar o status do repositório;
- revisar os quatro cenários de equivalência: comprador resumo, comprador detalhe com e sem recibo, vendor lista e vendor detalhe, confirmando campos e chamadas condicionais.
